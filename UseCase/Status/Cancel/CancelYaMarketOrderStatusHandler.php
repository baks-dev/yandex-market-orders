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

use BaksDev\Core\Deduplicator\Deduplicator;
use BaksDev\Orders\Order\Entity\Order;
use BaksDev\Orders\Order\Repository\CurrentOrderNumber\CurrentOrderEventByNumberInterface;
use BaksDev\Orders\Order\Type\Status\OrderStatus\OrderStatusCanceled;
use BaksDev\Orders\Order\Type\Status\OrderStatus\OrderStatusCompleted;
use BaksDev\Orders\Order\Type\Status\OrderStatus\OrderStatusNew;
use BaksDev\Orders\Order\Type\Status\OrderStatus\OrderStatusUnpaid;
use BaksDev\Orders\Order\UseCase\Admin\Edit\EditOrderDTO;
use BaksDev\Orders\Order\UseCase\Admin\Status\OrderStatusHandler;
use BaksDev\Products\Stocks\Entity\ProductStock;
use BaksDev\Products\Stocks\UseCase\Admin\Warehouse\WarehouseProductStockDTO;
use BaksDev\Products\Stocks\UseCase\Admin\Warehouse\WarehouseProductStockHandler;
use BaksDev\Users\Profile\UserProfile\Repository\UserByUserProfile\UserByUserProfileInterface;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
use BaksDev\Yandex\Market\Orders\Api\Canceled\YaMarketCancelOrderDTO;
use BaksDev\Yandex\Market\Orders\Repository\ProductStocksByOrder\ProductStocksCompleteByOrderInterface;
use BaksDev\Yandex\Market\Orders\UseCase\New\YandexMarketOrderDTO;
use DateInterval;
use DateTimeImmutable;

final readonly class CancelYaMarketOrderStatusHandler
{
    public function __construct(
        private OrderStatusHandler $orderStatusHandler,
        private CurrentOrderEventByNumberInterface $currentOrderEventByNumber,
        private ProductStocksCompleteByOrderInterface $productStocksCompleteByOrder,
        private UserByUserProfileInterface $userByUserProfile,
        private Deduplicator $deduplicator,
        private WarehouseProductStockHandler $warehouseProductStockHandler,
    ) {}


    public function handle(
        YandexMarketOrderDTO|YaMarketCancelOrderDTO $command,
        UserProfileUid $profile
    ): Order|string|false
    {

        $Deduplicator = $this->deduplicator
            ->namespace('orders-order')
            ->deduplication([
                $command->getNumber(),
                OrderStatusCanceled::STATUS,
                self::class
            ]);

        if($Deduplicator->isExecuted())
        {
            return false;
        }

        $OrderEvent = $this->currentOrderEventByNumber->find($command->getNumber());

        if(false === $OrderEvent)
        {
            return 'Заказа для отмены не найдено';
        }

        /**
         * Пропускаем, если заказ существует и его статус уже является CANCELED «Статус отменен»
         */

        if(true === $OrderEvent->isStatusEquals(OrderStatusCanceled::class))
        {
            $Deduplicator->save();
            return false;
        }

        $EditOrderDTO = new EditOrderDTO();
        $OrderEvent->getDto($EditOrderDTO);

        if($EditOrderDTO->getStatus()->equals(OrderStatusCompleted::class))
        {
            /** Получаем заявку по идентификатору заказа  */

            $OrderUid = $EditOrderDTO->getOrder();

            if(!$OrderUid)
            {
                return sprintf('Заказ #%s уже выполнен в системе, но идентификатор системного заказа не найден', $command->getNumber());
            }

            $ProductStocks = $this->productStocksCompleteByOrder
                ->forOrder($OrderUid)
                ->find();

            if($ProductStocks)
            {
                $User = $this->userByUserProfile
                    ->forProfile($profile)
                    ->findUser();

                if($User)
                {
                    $WarehouseProductStockDTO = new WarehouseProductStockDTO($User);
                    $ProductStocks->getDto($WarehouseProductStockDTO);
                    $WarehouseProductStockDTO->setComment(
                        sprintf('Возврат продукции при отмене заказа YaMarket #%s', $command->getNumber())
                    );

                    $handle = $this->warehouseProductStockHandler->handle($WarehouseProductStockDTO);

                    $Deduplicator->save();

                    if($handle instanceof ProductStock)
                    {
                        return 'Добавили возврат продукции при отмене выполненного заказа';
                    }

                    return sprintf('%s: Ошибка возврата заказа %s на склад', $handle, $command->getNumber());
                }
            }


            $Deduplicator->save();
            return 'Заказ уже выполнен в системе';
        }


        /**
         * Если заказ не является «Новый» либо «Не оплачен» - проверяем дату доставки
         * и отменяем только в случае если дата доставки не старше завтрашнего дня
         */
        if(
            $EditOrderDTO->getStatus()->equals(OrderStatusNew::class) === false &&
            $EditOrderDTO->getStatus()->equals(OrderStatusUnpaid::class) === false
        )
        {
            $OrderUserDTO = $EditOrderDTO->getUsr();
            $OrderDeliveryDTO = $OrderUserDTO?->getDelivery();
            $deliveryDate = $OrderDeliveryDTO->getDeliveryDate(); // дата доставки

            $now = (new DateTimeImmutable())->setTime(0, 0, 0);
            $packageDate = $now->add(new DateInterval('P1D')); // дата сборки на завтра

            /** Если дата доставки на завтра, либо уже в доставке сегодня - не отменяем заказ, ожидаем возврата */
            if($deliveryDate <= $packageDate)
            {
                return 'Ожидается возврат заказа находящийся на сборке либо в доставке';
            }
        }

        /**
         * Если заказ существует и его статус не CANCELED «Статус отменен» - обновляем
         */

        $CancelYaMarketOrderStatusDTO = new CancelYaMarketOrderStatusDTO($profile);
        $OrderEvent->getDto($CancelYaMarketOrderStatusDTO);
        $CancelYaMarketOrderStatusDTO->setComment('Отмена пользователем Yandex Market');

        $handle = $this->orderStatusHandler->handle($CancelYaMarketOrderStatusDTO);

        if($handle instanceof Order)
        {
            $Deduplicator->save();
        }

        return $handle;
    }
}
