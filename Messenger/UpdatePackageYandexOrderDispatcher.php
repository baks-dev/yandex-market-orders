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
use BaksDev\Yandex\Market\Orders\Type\DeliveryType\TypeDeliveryFbsYaMarket;
use BaksDev\Yandex\Market\Orders\UseCase\New\YandexMarketOrderDTO;
use BaksDev\Yandex\Market\Repository\YaMarketTokenExtraCompany\YaMarketTokenExtraCompanyInterface;
use BaksDev\Yandex\Market\Repository\YaMarketTokensByProfile\YaMarketTokensByProfileInterface;
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
        private OrderEventInterface $OrderEventRepository,
        private CurrentOrderEventInterface $CurrentOrderEvent,
        private UpdateYaMarketOrderReadyStatusRequest $updateYaMarketOrderReadyStatusRequest,
        private YaMarketTokensByProfileInterface $YaMarketTokensByProfile,
    ) {}


    public function __invoke(OrderMessage $message): void
    {
        /** Дедубликатор по идентификатору заказа */
        $Deduplicator = $this->deduplicator
            ->namespace('orders-order')
            ->deduplication([
                (string) $message->getId(),
                OrderStatusNew::STATUS,
                self::class,
            ]);

        if($Deduplicator->isExecuted() === true)
        {
            return;
        }


        /**
         * Получаем активное событие, т.к. может быть Unpaid
         * если Unpaid - ожидаем возврат в статус NEW
         */
        $CurrentOrderEvent = $this->CurrentOrderEvent
            ->forOrder($message->getId())
            ->find();

        if(false === ($CurrentOrderEvent instanceof OrderEvent))
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
        if(false === $CurrentOrderEvent->isStatusEquals(OrderStatusNew::class))
        {
            return;
        }

        if(
            false === $CurrentOrderEvent->isDeliveryTypeEquals(TypeDeliveryFbsYaMarket::TYPE) &&
            false === $CurrentOrderEvent->isDeliveryTypeEquals(TypeDeliveryDbsYaMarket::TYPE)
        )
        {
            return;
        }


        $UserProfileUid = $CurrentOrderEvent->getOrderProfile();

        if(false === ($UserProfileUid instanceof UserProfileUid))
        {
            $this->logger->critical(
                'yandex-market-orders: Идентификатор профиля заказа не определен',
                [self::class.':'.__LINE__, var_export($message, true)],
            );
            return;
        }


        /** Получаем все токены профиля */

        $tokensByProfile = $this->YaMarketTokensByProfile->findAll($UserProfileUid);

        if(false === $tokensByProfile || false === $tokensByProfile->valid())
        {
            return;
        }

        foreach($tokensByProfile as $YaMarketTokenUid)
        {
            /**
             * Получаем информацию о заказе и проверяем что заказ Новый
             */

            $YandexMarketOrderDTO = $this->yaMarketOrdersInfoRequest
                ->forTokenIdentifier($YaMarketTokenUid)
                ->find($CurrentOrderEvent->getOrderNumber());

            /** Если заказ не найден - пробуем определить в другом магазине */
            if(false === $YandexMarketOrderDTO instanceof YandexMarketOrderDTO)
            {
                continue;
            }

            if(false === $YandexMarketOrderDTO->getStatusEquals(OrderStatusNew::class))
            {
                return;
            }

            /** Если заказ Яндекс PROCESSING - отправляем уведомление о принятом заказе в обработку */

            $UpdateYaMarketOrderReadyStatusRequest = $this->updateYaMarketOrderReadyStatusRequest;

            $UpdateYaMarketOrderReadyStatusRequest
                ->forTokenIdentifier($YaMarketTokenUid)
                ->update($CurrentOrderEvent->getOrderNumber());
        }

        $this->logger->info(
            sprintf('%s: Отправили информацию о принятом в обработку заказе', $CurrentOrderEvent->getOrderNumber()),
            [self::class.':'.__LINE__],
        );

        $Deduplicator->save();
    }

}
