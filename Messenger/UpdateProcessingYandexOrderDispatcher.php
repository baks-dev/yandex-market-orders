<?php
/*
 *  Copyright 2026.  Baks.dev <admin@baks.dev>
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
 *
 */

declare(strict_types=1);

namespace BaksDev\Yandex\Market\Orders\Messenger;

use BaksDev\Core\Deduplicator\DeduplicatorInterface;
use BaksDev\Core\Messenger\MessageDelay;
use BaksDev\Core\Messenger\MessageDispatchInterface;
use BaksDev\Orders\Order\Entity\Event\OrderEvent;
use BaksDev\Orders\Order\Messenger\OrderMessage;
use BaksDev\Orders\Order\Repository\CurrentOrderEvent\CurrentOrderEventInterface;
use BaksDev\Orders\Order\Type\Status\OrderStatus\Collection\OrderStatusNew;
use BaksDev\Orders\Order\Type\Status\OrderStatus\Collection\OrderStatusPackage;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
use BaksDev\Yandex\Market\Orders\Api\GetYaMarketOrderInfoRequest;
use BaksDev\Yandex\Market\Orders\Messenger\ProcessYandexPackageStickers\ProcessYandexPackageStickersMessage;
use BaksDev\Yandex\Market\Orders\Messenger\Ready\UpdateYaMarketOrderReadyStatusMessage;
use BaksDev\Yandex\Market\Orders\Type\DeliveryType\TypeDeliveryDbsYaMarket;
use BaksDev\Yandex\Market\Orders\Type\DeliveryType\TypeDeliveryFbsYaMarket;
use BaksDev\Yandex\Market\Orders\UseCase\New\Box\NewYaMarketOrderBoxDTO;
use BaksDev\Yandex\Market\Orders\UseCase\New\NewYaMarketOrderByBusinessDTO;
use BaksDev\Yandex\Market\Type\Id\YaMarketTokenUid;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Если поступает новый заказ YandexMarket - отправляем уведомление о статусе «Принят в обработку»
 */
#[Autoconfigure(shared: false)]
#[AsMessageHandler(priority: 9)]
final readonly class UpdateProcessingYandexOrderDispatcher
{
    public function __construct(
        #[Target('yandexMarketOrdersLogger')] private LoggerInterface $logger,
        private DeduplicatorInterface $deduplicator,
        private GetYaMarketOrderInfoRequest $yaMarketOrdersInfoRequest,
        private CurrentOrderEventInterface $CurrentOrderEventRepository,
        private MessageDispatchInterface $messageDispatch,
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
        $CurrentOrderEvent = $this->CurrentOrderEventRepository
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

        /** Если тип заказа не Yandex Market */
        if(
            false === $CurrentOrderEvent->isDeliveryTypeEquals(TypeDeliveryFbsYaMarket::TYPE) &&
            false === $CurrentOrderEvent->isDeliveryTypeEquals(TypeDeliveryDbsYaMarket::TYPE)
        )
        {
            $Deduplicator->save();
            return;
        }

        /**
         * Для FBS заказов:
         * - Если статус заказа не Статус Package «Упаковка заказов» - завершаем обработчик в ожидании автоматической упаковки
         */
        if(
            true === $CurrentOrderEvent->isDeliveryTypeEquals(TypeDeliveryFbsYaMarket::TYPE)
            && false === $CurrentOrderEvent->isStatusEquals(OrderStatusPackage::class)
        )
        {
            return;
        }

        /**
         * Для DBS заказов:
         * - Если статус заказа не Статус New «Новый» - завершаем обработчик
         */
        if(
            true === $CurrentOrderEvent->isDeliveryTypeEquals(TypeDeliveryDbsYaMarket::TYPE)
            && false === $CurrentOrderEvent->isStatusEquals(OrderStatusNew::class)
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

        if(true === ($CurrentOrderEvent->getOrderTokenIdentifier() instanceof Uuid))
        {
            return;
        }

        $YaMarketTokenUid = new YaMarketTokenUid($CurrentOrderEvent->getOrderTokenIdentifier());

        /**
         * Получаем информацию о заказе в маркетплейсе и проверяем что заказ Новый
         */

        $NewYaMarketOrderByBusinessDTO = $this->yaMarketOrdersInfoRequest
            ->forTokenIdentifier($YaMarketTokenUid)
            ->findNew($CurrentOrderEvent->getOrderNumber());

        if(false === $NewYaMarketOrderByBusinessDTO instanceof NewYaMarketOrderByBusinessDTO)
        {
            return;
        }

        if(false === $NewYaMarketOrderByBusinessDTO->getStatusEquals(OrderStatusNew::class))
        {
            return;
        }

        /**
         * Отправляем уведомление о принятом заказе в обработку
         */

        $UpdateYaMarketOrderReadyStatusMessage = new UpdateYaMarketOrderReadyStatusMessage(
            $YaMarketTokenUid,
            $UserProfileUid,
            $CurrentOrderEvent->getOrderNumber(),
        );

        $this->messageDispatch->dispatch(
            message: $UpdateYaMarketOrderReadyStatusMessage,
            stamps: [
                new MessageDelay(
                    true === $CurrentOrderEvent->isDeliveryTypeEquals(TypeDeliveryDbsYaMarket::TYPE)
                        ? '1 seconds' // DBS
                        : '1 minutes', // FBS
                ),
            ],
            transport: (string) $UserProfileUid,
        );

        /**
         * Создаем задание на получение стикеров
         */

        /** @var NewYaMarketOrderBoxDTO $box */
        foreach($NewYaMarketOrderByBusinessDTO->getPostingBox() as $box)
        {
            $ProcessYandexPackageStickersMessage = new ProcessYandexPackageStickersMessage(
                token: $YaMarketTokenUid,
                order: $CurrentOrderEvent->getOrderNumber(),
                posting: $box->getPostingNumber(),
                box: $box->getBoxId(),
            );

            $this->messageDispatch->dispatch(
                message: $ProcessYandexPackageStickersMessage,
                stamps: [
                    new MessageDelay(
                        true === $CurrentOrderEvent->isDeliveryTypeEquals(TypeDeliveryDbsYaMarket::TYPE)
                            ? '5 seconds' // DBS
                            : '2 minutes', // FBS
                    ),
                ],
                transport: $UserProfileUid.'-low',
            );

        }

        $Deduplicator->save();
    }
}
