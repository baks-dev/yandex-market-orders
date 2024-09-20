<?php
/*
 *  Copyright 2023.  Baks.dev <admin@baks.dev>
 *
 *  Permission is hereby granted, free of charge, to any person obtaining a copy
 *  of this software and associated documentation files (the "Software"), to deal
 *  in the Software without restriction, including without limitation the rights
 *  to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 *  copies of the Software, and to permit persons to whom the Software is furnished
 *  to do so, subject to the following conditions:
 *
 *  The above copyright notice and this permission notice shall be included in all
 *  copies or substantial portions of the Software.
 *
 *  THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 *  IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 *  FITNESS FOR A PARTICULAR PURPOSE AND NON INFRINGEMENT. IN NO EVENT SHALL THE
 *  AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 *  LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 *  OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 *  THE SOFTWARE.
 */

declare(strict_types=1);

namespace BaksDev\Yandex\Market\Orders\Messenger\OrderByComplete;

use BaksDev\Core\Deduplicator\DeduplicatorInterface;
use BaksDev\Core\Lock\AppLockInterface;
use BaksDev\Core\Messenger\MessageDispatchInterface;
use BaksDev\Orders\Order\Entity\Event\OrderEvent;
use BaksDev\Orders\Order\Entity\Order;
use BaksDev\Orders\Order\Entity\Products\OrderProduct;
use BaksDev\Orders\Order\Messenger\OrderMessage;
use BaksDev\Orders\Order\Repository\ExistOrderEventByStatus\ExistOrderEventByStatusInterface;
use BaksDev\Orders\Order\Type\Status\OrderStatus\OrderStatusCompleted;
use BaksDev\Orders\Order\UseCase\Admin\Edit\EditOrderDTO;
use BaksDev\Orders\Order\UseCase\Admin\Edit\Products\OrderProductDTO;
use BaksDev\Products\Product\Entity\Offers\Variation\Modification\Quantity\ProductModificationQuantity;
use BaksDev\Products\Product\Entity\Offers\Variation\Quantity\ProductVariationQuantity;
use BaksDev\Products\Product\Repository\CurrentQuantity\CurrentQuantityByEventInterface;
use BaksDev\Products\Product\Repository\CurrentQuantity\Modification\CurrentQuantityByModificationInterface;
use BaksDev\Products\Product\Repository\CurrentQuantity\Offer\CurrentQuantityByOfferInterface;
use BaksDev\Products\Product\Repository\CurrentQuantity\Variation\CurrentQuantityByVariationInterface;
use BaksDev\Yandex\Market\Orders\Api\YaMarketOrdersInfoRequest;
use BaksDev\Yandex\Market\Orders\UseCase\Status\Cancel\CancelYaMarketOrderStatusHandler;
use BaksDev\Yandex\Market\Repository\YaMarketTokenExtraCompany\YaMarketTokenExtraCompanyInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(priority: 0)]
final class CancelByOrderCompleted
{
    private LoggerInterface $logger;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DeduplicatorInterface $deduplicator,
        private readonly YaMarketOrdersInfoRequest $yaMarketOrdersInfoRequest,
        private readonly YaMarketTokenExtraCompanyInterface $tokenExtraCompany,
        private readonly CancelYaMarketOrderStatusHandler $cancelYaMarketOrderStatusHandler,
        LoggerInterface $ordersOrderLogger,
    ) {
        $this->logger = $ordersOrderLogger;
    }


    /**
     * Делаем проверку выполненного заказа на отмену в YandexMarket
     * Если заказ выполнен и его статус
     */
    public function __invoke(OrderMessage $message): void
    {
        /** Дедубликатор по идентификатору заказа */
        $Deduplicator = $this->deduplicator
            ->namespace('orders-order')
            ->deduplication([
                $message->getId(),
                md5(self::class)
            ]);

        if($Deduplicator->isExecuted())
        {
            return;
        }

        $OrderEvent = $this->entityManager
            ->getRepository(OrderEvent::class)
            ->find($message->getEvent());


        if(!$OrderEvent)
        {
            return;
        }

        $EditOrderDTO = new EditOrderDTO();
        $OrderEvent->getDto($EditOrderDTO);
        $this->entityManager->clear();

        /**
         * Если статус заказа не Completed «Выполнен» - завершаем обработчик
         * создаем заявку на возврат только при выполненном заказе
         */
        if(false === $EditOrderDTO->getStatus()->equals(OrderStatusCompleted::class))
        {
            return;
        }

        $OrderUserDTO = $EditOrderDTO->getUsr();

        if(!$OrderUserDTO)
        {
            return;
        }

        $EditOrderInvariableDTO = $EditOrderDTO->getInvariable();

        /** Проверяем, что номер заказа начинается с Y- (YandexMarket) */
        if(false === str_starts_with($EditOrderInvariableDTO->getNumber(), 'Y-'))
        {
            return;
        }

        /**
         * Получаем информацию о заказе и проверяем что заказ не отменен
         */

        $UserProfileUid = $EditOrderDTO->getProfile();

        if(is_null($UserProfileUid))
        {
            return;
        }

        $YandexMarketOrderDTO = $this->yaMarketOrdersInfoRequest
            ->profile($EditOrderDTO->getProfile())
            ->find($EditOrderInvariableDTO->getNumber());

        /**
         * Если заказ не найден - пробуем найти по дополнительным идентификаторам
         */

        if($YandexMarketOrderDTO === false)
        {
            $extra = $this->tokenExtraCompany
                ->profile($UserProfileUid)
                ->execute();

            if($extra === false)
            {
                return;
            }

            foreach($extra as $company)
            {
                $YandexMarketOrderDTO = $this->yaMarketOrdersInfoRequest
                    ->setExtraCompany($company['company'])
                    ->find($EditOrderInvariableDTO->getNumber());

                if($YandexMarketOrderDTO !== false)
                {
                    break;
                }
            }
        }

        if($YandexMarketOrderDTO === false)
        {
            $this->logger->critical(
                sprintf('Yandex: Информация о заказе %s не найдено', $EditOrderInvariableDTO->getNumber()),
                [self::class.':'.__LINE__]
            );
            return;
        }


        /** Если заказ отменен - создаем заявку на возврат */
        if($YandexMarketOrderDTO->getStatus()->equals(OrderStatusCompleted::class))
        {

            $handle = $this->cancelYaMarketOrderStatusHandler->handle($YandexMarketOrderDTO, $UserProfileUid);

            if($handle instanceof Order)
            {
                $this->logger->info(
                    sprintf('Отменили заказ %s', $YandexMarketOrderDTO->getNumber()),
                    [
                        self::class.':'.__LINE__,
                        'profile' => (string) $UserProfileUid,
                    ]
                );
            }

            if($handle !== false)
            {
                $this->logger->critical(
                    sprintf('Yandex: Ошибка при отмене заказа %s (%s)', $YandexMarketOrderDTO->getNumber(), $handle),
                    [
                        self::class.':'.__LINE__,
                        'profile' => (string) $UserProfileUid,
                    ]
                );
            }
        }


        $Deduplicator->save();
    }
}