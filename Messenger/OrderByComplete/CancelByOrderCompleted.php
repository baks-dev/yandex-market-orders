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
use BaksDev\Orders\Order\Entity\Order;
use BaksDev\Orders\Order\Messenger\OrderMessage;
use BaksDev\Orders\Order\Repository\OrderEvent\OrderEventInterface;
use BaksDev\Orders\Order\Type\Status\OrderStatus\OrderStatusCanceled;
use BaksDev\Orders\Order\Type\Status\OrderStatus\OrderStatusCompleted;
use BaksDev\Orders\Order\UseCase\Admin\Edit\EditOrderDTO;
use BaksDev\Yandex\Market\Orders\Api\GetYaMarketOrderInfoRequest;
use BaksDev\Yandex\Market\Orders\UseCase\Status\Cancel\CancelYaMarketOrderStatusHandler;
use BaksDev\Yandex\Market\Repository\YaMarketTokenExtraCompany\YaMarketTokenExtraCompanyInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(priority: 0)]
final class CancelByOrderCompleted
{
    private LoggerInterface $logger;

    public function __construct(
        private readonly DeduplicatorInterface $deduplicator,
        private readonly GetYaMarketOrderInfoRequest $yaMarketOrdersInfoRequest,
        private readonly YaMarketTokenExtraCompanyInterface $tokenExtraCompany,
        private readonly CancelYaMarketOrderStatusHandler $cancelYaMarketOrderStatusHandler,
        private readonly OrderEventInterface $orderEventRepository,
        LoggerInterface $ordersOrderLogger,
    )
    {
        $this->logger = $ordersOrderLogger;
    }


    /**
     * Делаем проверку выполненного заказа на отмену в YandexMarket
     * Если системный заказ Completed «Выполнен» а Яндекс заказ Canceled «Отменен» - создаем возврат
     */
    public function __invoke(OrderMessage $message): void
    {
        /** Дедубликатор по идентификатору заказа */
        $Deduplicator = $this->deduplicator
            ->namespace('orders-order')
            ->deduplication([
                $message->getId(),
                OrderStatusCompleted::STATUS,
                self::class
            ]);

        if($Deduplicator->isExecuted() === true)
        {
            return;
        }

        $OrderEvent = $this->orderEventRepository->find($message->getEvent());

        if(false === $OrderEvent)
        {
            return;
        }

        /**
         * Если статус заказа не Completed «Выполнен» - завершаем обработчик
         * создаем заявку на возврат только при выполненном заказе
         */
        if(false === $OrderEvent->isStatusEquals(OrderStatusCompleted::class))
        {
            return;
        }

        $EditOrderDTO = new EditOrderDTO();
        $OrderEvent->getDto($EditOrderDTO);
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

        $UserProfileUid = $EditOrderInvariableDTO->getProfile();

        if(is_null($UserProfileUid))
        {
            return;
        }

        $YandexMarketOrderDTO = $this->yaMarketOrdersInfoRequest
            ->profile($UserProfileUid)
            ->find($EditOrderInvariableDTO->getNumber());

        /**
         * Если заказ в Яндексе не найден - пробуем найти по дополнительным идентификаторам
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


        /** Если заказ Яндекс отменен, а системный заказ Выполнен - создаем заявку на возврат */
        if(true === $YandexMarketOrderDTO->getStatusEquals(OrderStatusCanceled::class))
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
