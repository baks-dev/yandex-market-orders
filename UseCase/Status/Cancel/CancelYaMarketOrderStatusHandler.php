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

namespace BaksDev\Yandex\Market\Orders\UseCase\Status\Cancel;

use BaksDev\Orders\Order\Entity\Order;
use BaksDev\Orders\Order\Repository\CurrentOrderNumber\CurrentOrderEventByNumberInterface;
use BaksDev\Orders\Order\Type\Status\OrderStatus\Collection\OrderStatusCanceled;
use BaksDev\Orders\Order\Type\Status\OrderStatus\Collection\OrderStatusCompleted;
use BaksDev\Orders\Order\Type\Status\OrderStatus\Collection\OrderStatusNew;
use BaksDev\Orders\Order\Type\Status\OrderStatus\Collection\OrderStatusUnpaid;
use BaksDev\Orders\Order\UseCase\Admin\Edit\EditOrderDTO;
use BaksDev\Orders\Order\UseCase\Admin\Status\OrderStatusHandler;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
use BaksDev\Yandex\Market\Orders\Api\Canceled\YaMarketCancelOrderDTO;
use BaksDev\Yandex\Market\Orders\UseCase\New\YandexMarketOrderDTO;

final readonly class CancelYaMarketOrderStatusHandler
{
    public function __construct(
        private OrderStatusHandler $orderStatusHandler,
        private CurrentOrderEventByNumberInterface $currentOrderEventByNumber,
    ) {}

    public function handle(
        YandexMarketOrderDTO|YaMarketCancelOrderDTO $command,
        UserProfileUid $profile
    ): Order|string|false
    {
        $OrderEvent = $this->currentOrderEventByNumber->find($command->getNumber());

        if(false === $OrderEvent)
        {
            return 'Заказа для отмены не найдено';
        }

        $EditOrderDTO = new EditOrderDTO();
        $OrderEvent->getDto($EditOrderDTO);

        if(
            true === $OrderEvent->isStatusEquals(OrderStatusCanceled::class) ||
            true === $OrderEvent->isStatusEquals(OrderStatusCompleted::class)
        )
        {
            return false;
        }


        /**
         * Делаем отмену заказа
         */

        $CancelYaMarketOrderStatusDTO = new CancelYaMarketOrderStatusDTO($profile);
        $OrderEvent->getDto($CancelYaMarketOrderStatusDTO);

        $CancelYaMarketOrderStatusDTO->setComment($command->getComment());

        /**
         * Автоматически отменяем «Новый» либо «Не оплаченный» заказ
         */

        if(
            true === $OrderEvent->isStatusEquals(OrderStatusNew::class) ||
            true === $OrderEvent->isStatusEquals(OrderStatusUnpaid::class)
        )
        {
            $CancelYaMarketOrderStatusDTO->cancelOrder();
        }

        return $this->orderStatusHandler->handle($CancelYaMarketOrderStatusDTO, false);
    }
}