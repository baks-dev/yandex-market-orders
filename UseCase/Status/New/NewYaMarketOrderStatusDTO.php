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

namespace BaksDev\Yandex\Market\Orders\UseCase\Status\New;

use BaksDev\Orders\Order\Entity\Event\OrderEventInterface;
use BaksDev\Orders\Order\Type\Event\OrderEventUid;
use BaksDev\Orders\Order\Type\Status\OrderStatus;
use BaksDev\Orders\Order\Type\Status\OrderStatus\OrderStatusNew;
use BaksDev\Orders\Order\Type\Status\OrderStatus\OrderStatusUnpaid;
use Symfony\Component\Validator\Constraints as Assert;

/** @see OrderEvent $var */
final class NewYaMarketOrderStatusDTO implements OrderEventInterface
{
    /** Идентификатор события */
    #[Assert\NotBlank]
    #[Assert\Uuid]
    private OrderEventUid $id;

    /** Постоянная величина */
    #[Assert\Valid]
    private Invariable\NewOrderInvariable $invariable;

    /** Статус заказа */
    #[Assert\NotBlank]
    private OrderStatus $status;

    /**
     * Ответственный
     * @deprecated Значение переносится в Invariable
     */
    #[Assert\IsNull]
    private readonly null $profile;

    public function __construct()
    {
        $this->profile = null;
        $this->invariable = new Invariable\NewOrderInvariable();
    }

    /** Идентификатор события */
    public function getEvent(): OrderEventUid
    {
        return $this->id;
    }

    /** Статус заказа */
    public function isStatusUnpaid(): bool
    {
        return $this->status->equals(OrderStatusUnpaid::class);
    }

    public function setOrderStatusNew(): self
    {
        $this->status = new OrderStatus(OrderStatusNew::class);
        return $this;
    }

    /**
     * Status
     */
    public function getStatus(): OrderStatus
    {
        return $this->status;
    }

    /**
     * @deprecated переносится в Invariable
     * Профиль пользователя при оплаченном статусе - NULL
     */
    public function getProfile(): null
    {
        /** @deprecated */
        return $this->profile;
    }

    /**
     * Invariable
     */
    public function getInvariable(): Invariable\NewOrderInvariable
    {
        return $this->invariable;
    }

}
