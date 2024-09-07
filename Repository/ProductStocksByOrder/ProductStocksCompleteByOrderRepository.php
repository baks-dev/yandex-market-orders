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

namespace BaksDev\Yandex\Market\Orders\Repository\ProductStocksByOrder;

use BaksDev\Core\Doctrine\ORMQueryBuilder;
use BaksDev\Orders\Order\Entity\Order;
use BaksDev\Orders\Order\Type\Id\OrderUid;
use BaksDev\Products\Stocks\Entity\Event\ProductStockEvent;
use BaksDev\Products\Stocks\Entity\Orders\ProductStockOrder;
use BaksDev\Products\Stocks\Entity\ProductStock;
use BaksDev\Products\Stocks\Type\Status\ProductStockStatus;
use BaksDev\Products\Stocks\Type\Status\ProductStockStatus\ProductStockStatusCompleted;
use InvalidArgumentException;

final class ProductStocksCompleteByOrderRepository implements ProductStocksCompleteByOrderInterface
{
    private OrderUid|false $order = false;

    public function __construct(private readonly ORMQueryBuilder $ORMQueryBuilder) {}

    public function forOrder(Order|OrderUid|string $order): self
    {
        if($order instanceof Order)
        {
            $order = $order->getId();
        }

        if(is_string($order))
        {
            $order = new OrderUid($order);
        }

        $this->order = $order;

        return $this;
    }

    /**
     * Метод получает по идентификатору выполненного заказа складскую заявку (для возврата)
     */
    public function find(): ProductStockEvent|false
    {

        if($this->order === false)
        {
            throw new InvalidArgumentException('Не передан обязательный параметр order через вызов метода forOrder');
        }

        $orm = $this->ORMQueryBuilder->createQueryBuilder(self::class);

        $orm->select('event');

        $orm
            ->from(ProductStockOrder::class, 'ord')
            ->where('ord.ord = :ord')
            ->setParameter('ord', $this->order, OrderUid::TYPE);

        $orm->join(
            ProductStock::class,
            'stock',
            'WITH',
            'stock.event = ord.event',
        );

        $orm->join(
            ProductStockEvent::class,
            'event',
            'WITH',
            'event.id = stock.event
             AND event.status = :status
            '
        )->setParameter(
            'status',
            ProductStockStatusCompleted::class,
            ProductStockStatus::TYPE
        );

        return $orm->getOneOrNullResult() ?: false;
    }
}
