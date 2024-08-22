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
use BaksDev\Orders\Order\Repository\CurrentOrderNumber\CurrentOrderNumberInterface;
use BaksDev\Orders\Order\Type\Status\OrderStatus\OrderStatusCanceled;
use BaksDev\Orders\Order\Type\Status\OrderStatus\OrderStatusCompleted;
use BaksDev\Orders\Order\UseCase\Admin\Canceled\OrderCanceledDTO;
use BaksDev\Orders\Order\UseCase\Admin\Edit\EditOrderDTO;
use BaksDev\Orders\Order\UseCase\Admin\Status\OrderStatusHandler;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
use BaksDev\Yandex\Market\Orders\Api\Canceled\YaMarketCancelOrderDTO;
use BaksDev\Yandex\Market\Orders\UseCase\New\YandexMarketOrderDTO;

final class CancelYaMarketOrderStatusHandler
{
    public function __construct(
        private readonly OrderStatusHandler $orderStatusHandler,
        private readonly CurrentOrderNumberInterface $currentOrderNumber,
    ) {}


    public function handle(
        YandexMarketOrderDTO|YaMarketCancelOrderDTO $command,
        UserProfileUid $profile
    ): string|false|Order {
        $OrderEvent = $this->currentOrderNumber->getCurrentOrderEvent($command->getNumber());

        /**
         * Если заказа не существует
         */

        if(!$OrderEvent)
        {
            return 'Заказа для отмены не найдено';
        }

        $EditOrderDTO = new EditOrderDTO();
        $OrderEvent->getDto($EditOrderDTO);

        /**
         * Пропускаем, если заказ существует и его статус уже является CANCELED «Статус отменен»
         */

        if($EditOrderDTO->getStatus()->equals(OrderStatusCanceled::class))
        {
            return false;
        }

        if($EditOrderDTO->getStatus()->equals(OrderStatusCompleted::class))
        {
            return 'Заказ уже выполнен в системе';
        }

        /**
         * Если заказ существует и его статус не CANCELED «Статус отменен» - обновляем
         */

        $CancelYaMarketOrderStatusDTO = new CancelYaMarketOrderStatusDTO($profile);
        $OrderEvent->getDto($CancelYaMarketOrderStatusDTO);
        $CancelYaMarketOrderStatusDTO->setComment('Отмена пользователем Yandex Market');

        return $this->orderStatusHandler->handle($CancelYaMarketOrderStatusDTO);

    }

}
