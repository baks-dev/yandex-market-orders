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
use BaksDev\Orders\Order\Repository\CurrentOrderNumber\CurrentOrderNumberInterface;
use BaksDev\Orders\Order\Type\Status\OrderStatus\OrderStatusCanceled;
use BaksDev\Orders\Order\Type\Status\OrderStatus\OrderStatusCompleted;
use BaksDev\Orders\Order\UseCase\Admin\Edit\EditOrderDTO;
use BaksDev\Orders\Order\UseCase\Admin\Status\OrderStatusHandler;
use BaksDev\Products\Stocks\UseCase\Admin\Warehouse\WarehouseProductStockDTO;
use BaksDev\Users\Profile\UserProfile\Repository\UserByUserProfile\UserByUserProfileInterface;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
use BaksDev\Yandex\Market\Orders\Api\Canceled\YaMarketCancelOrderDTO;
use BaksDev\Yandex\Market\Orders\Repository\ProductStocksByOrder\ProductStocksCompleteByOrderInterface;
use BaksDev\Yandex\Market\Orders\UseCase\New\YandexMarketOrderDTO;

final class CancelYaMarketOrderStatusHandler
{
    public function __construct(
        private readonly OrderStatusHandler $orderStatusHandler,
        private readonly CurrentOrderNumberInterface $currentOrderNumber,
        private readonly ProductStocksCompleteByOrderInterface $productStocksCompleteByOrder,
        private readonly UserByUserProfileInterface $userByUserProfile,
        private readonly Deduplicator $deduplicator,
    ) {}


    public function handle(
        YandexMarketOrderDTO|YaMarketCancelOrderDTO $command,
        UserProfileUid $profile
    ): Order|string|false {

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

        $Deduplicator = $this->deduplicator
            ->namespace('orders-order')
            ->deduplication([
                $command->getNumber(),
                OrderStatusCanceled::STATUS,
                md5(self::class)
            ]);

        if($Deduplicator->isExecuted())
        {
            return false;
        }


        if($EditOrderDTO->getStatus()->equals(OrderStatusCompleted::class))
        {
            /** Получаем заявку по идентификатору заказа  */

            $OrderUid = $EditOrderDTO->getOrder();

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

                    $Deduplicator->save();
                    return 'Добавили возврат продукции при отмене заказа';
                }
            }


            $Deduplicator->save();
            return 'Заказ уже выполнен в системе';
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
