<?php
/*
 *  Copyright 2024.  Baks.dev <admin@baks.dev>
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

namespace BaksDev\Yandex\Market\Orders\Messenger;

use BaksDev\Core\Deduplicator\DeduplicatorInterface;
use BaksDev\Orders\Order\Entity\Event\OrderEvent;
use BaksDev\Orders\Order\Messenger\OrderMessage;
use BaksDev\Orders\Order\Repository\CurrentOrderEvent\CurrentOrderEventInterface;
use BaksDev\Orders\Order\Repository\OrderEvent\OrderEventInterface;
use BaksDev\Orders\Order\Type\Status\OrderStatus\Collection\OrderStatusNew;
use BaksDev\Orders\Order\UseCase\Admin\Edit\EditOrderDTO;
use BaksDev\Orders\Order\UseCase\Admin\Edit\User\OrderUserDTO;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
use BaksDev\Yandex\Market\Orders\Api\GetYaMarketOrderInfoRequest;
use BaksDev\Yandex\Market\Orders\Api\UpdateYaMarketOrderReadyStatusRequest;
use BaksDev\Yandex\Market\Orders\Type\DeliveryType\TypeDeliveryDbsYaMarket;
use BaksDev\Yandex\Market\Orders\Type\DeliveryType\TypeDeliveryFbsYandexMarket;
use BaksDev\Yandex\Market\Repository\YaMarketTokenExtraCompany\YaMarketTokenExtraCompanyInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Если поступает новый заказ YandexMarket - отправляем уведомление о статусе «Принят в обработку»
 */
#[AsMessageHandler(priority: 9)]
final readonly class UpdatePackageYandexOrderDispatcher
{
    public function __construct(
        #[Autowire(env: 'APP_ENV')] private string $environment,
        #[Target('yandexMarketOrdersLogger')] private LoggerInterface $logger,
        private DeduplicatorInterface $deduplicator,
        private GetYaMarketOrderInfoRequest $yaMarketOrdersInfoRequest,
        private YaMarketTokenExtraCompanyInterface $tokenExtraCompany,
        private OrderEventInterface $OrderEventRepository,
        private CurrentOrderEventInterface $CurrentOrderEvent,
        private UpdateYaMarketOrderReadyStatusRequest $updateYaMarketOrderReadyStatusRequest,
    ) {}


    public function __invoke(OrderMessage $message): void
    {
        /** Дедубликатор по идентификатору заказа */
        $Deduplicator = $this->deduplicator
            ->namespace('orders-order')
            ->deduplication([
                (string) $message->getId(),
                OrderStatusNew::STATUS,
                self::class
            ]);

        if($Deduplicator->isExecuted() === true)
        {
            return;
        }

        $OrderEvent = $this->OrderEventRepository
            ->find($message->getEvent());

        if(false === ($OrderEvent instanceof OrderEvent))
        {
            $this->logger->critical(
                'products-sign: Не найдено событие OrderEvent',
                [self::class.':'.__LINE__, var_export($message, true)],
            );

            return;
        }

        /**
         * Если статус заказа не Статус New «Новый» - завершаем обработчик
         */
        if(false === $OrderEvent->isStatusEquals(OrderStatusNew::class))
        {
            return;
        }

        if(
            false === $OrderEvent->isDeliveryTypeEquals(TypeDeliveryFbsYandexMarket::TYPE) &&
            false === $OrderEvent->isDeliveryTypeEquals(TypeDeliveryDbsYaMarket::TYPE)
        )
        {
            return;
        }


        /** Получаем активное событие заказа в случае если статус заказа изменился */
        if(false === ($OrderEvent->getOrderProfile() instanceof UserProfileUid))
        {
            $OrderEvent = $this->CurrentOrderEvent
                ->forOrder($message->getId())
                ->find();

            if(false === ($OrderEvent instanceof OrderEvent))
            {
                $this->logger->critical(
                    'yandex-market-orders-sign: Не найдено событие OrderEvent',
                    [self::class.':'.__LINE__, var_export($message, true)],
                );

                return;
            }
        }

        $EditOrderDTO = new EditOrderDTO();
        $OrderEvent->getDto($EditOrderDTO);
        $OrderUserDTO = $EditOrderDTO->getUsr();

        if(false === ($OrderUserDTO instanceof OrderUserDTO))
        {
            return;
        }

        $EditOrderInvariableDTO = $EditOrderDTO->getInvariable();
        $UserProfileUid = $OrderEvent->getOrderProfile();


        /**
         * Получаем информацию о заказе и проверяем что заказ Новый
         */

        $YandexMarketOrderDTO = $this->yaMarketOrdersInfoRequest
            ->profile($UserProfileUid)
            ->find($EditOrderInvariableDTO->getNumber());

        /**
         * Если заказ в Яндексе не найден - пробуем найти по дополнительным идентификаторам
         */

        $extraCompany = null;

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
                    $extraCompany = $company['company'];
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

        if(false === $YandexMarketOrderDTO->getStatusEquals(OrderStatusNew::class))
        {
            return;
        }

        /** Если заказ Яндекс PROCESSING - отправляем уведомление о принятом заказе в обработку */

        $UpdateYaMarketOrderReadyStatusRequest = $this->updateYaMarketOrderReadyStatusRequest;

        $UpdateYaMarketOrderReadyStatusRequest
            ->profile($UserProfileUid);

        if(false === is_null($extraCompany))
        {
            /** Присваиваем компанию, если заказ найден в другом магазине */
            $UpdateYaMarketOrderReadyStatusRequest
                ->setExtraCompany($extraCompany);
        }

        $UpdateYaMarketOrderReadyStatusRequest
            ->update($EditOrderInvariableDTO->getNumber());

        $this->logger->info(
            sprintf('%s: Отправили информацию о принятом в обработку заказе', $EditOrderInvariableDTO->getNumber()),
            [self::class.':'.__LINE__]
        );

        $Deduplicator->save();
    }

}
