<?php
/*
 * Copyright 2026.  Baks.dev <admin@baks.dev>
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

namespace BaksDev\Yandex\Market\Orders\Messenger\OrderYandexAutoPackage;

use BaksDev\Core\Deduplicator\DeduplicatorInterface;
use BaksDev\Core\Messenger\MessageDispatchInterface;
use BaksDev\Orders\Order\Entity\Event\OrderEvent;
use BaksDev\Orders\Order\Messenger\MultiplyOrdersPackage\MultiplyOrdersPackageMessage;
use BaksDev\Orders\Order\Messenger\OrderMessage;
use BaksDev\Orders\Order\Repository\OrderEvent\OrderEventInterface;
use BaksDev\Orders\Order\Type\Status\OrderStatus\Collection\OrderStatusNew;
use BaksDev\Users\User\Type\Id\UserUid;
use BaksDev\Yandex\Market\Orders\Type\DeliveryType\TypeDeliveryFbsYaMarket;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[Autoconfigure(shared: false)]
#[AsMessageHandler(priority: 0)]
final readonly class OrderYandexAutoPackageDispatcher
{
    public function __construct(
        #[Target('yandexMarketOrdersLogger')] private LoggerInterface $Logger,
        private OrderEventInterface $OrderEventRepository,
        private DeduplicatorInterface $Deduplicator,
        private MessageDispatchInterface $MessageDispatch,
    ) {}


    /** Отправляем сообщение на перенос нового заказа в статус "Упаковка", если это FBS-заказ */
    public function __invoke(OrderMessage $message): void
    {
        $Deduplicator = $this->Deduplicator
            ->namespace('orders-order')
            ->deduplication([(string) $message->getId(), self::class]);

        if($Deduplicator->isExecuted())
        {
            return;
        }

        $OrderEvent = $this->OrderEventRepository
            ->find($message->getEvent());

        if(false === ($OrderEvent instanceof OrderEvent))
        {
            $this->Logger->critical(
                message: 'yandex-market-orders: Не найдено событие OrderEvent',
                context: [self::class.':'.__LINE__, var_export($message, true)],
            );
            return;
        }


        /** Если заказ не является новым или если он не FBS - завершаем обработчик */
        if(
            false === $OrderEvent->isStatusEquals(OrderStatusNew::class) ||
            false === $OrderEvent->isDeliveryTypeEquals(TypeDeliveryFbsYaMarket::TYPE)
        )
        {
            $Deduplicator->save();
            return;
        }

        $MultiplyOrdersPackageMessage = new MultiplyOrdersPackageMessage(
            $OrderEvent->getMain(),
            $OrderEvent->getOrderProfile(),
            true === ($OrderEvent->getModifyUser() instanceof UserUid) ? $OrderEvent->getModifyUser() : $OrderEvent->getOrderUser()
        );

        $this->MessageDispatch->dispatch($MultiplyOrdersPackageMessage);

        $Deduplicator->save();
    }
}