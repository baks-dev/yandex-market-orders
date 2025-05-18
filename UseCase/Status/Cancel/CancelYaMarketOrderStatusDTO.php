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

namespace BaksDev\Yandex\Market\Orders\UseCase\Status\Cancel;

use BaksDev\Orders\Order\Entity\Event\OrderEventInterface;
use BaksDev\Orders\Order\Type\Event\OrderEventUid;
use BaksDev\Orders\Order\Type\Status\OrderStatus;
use BaksDev\Orders\Order\Type\Status\OrderStatus\Collection\OrderStatusCanceled;
use BaksDev\Users\Profile\UserProfile\Entity\UserProfile;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
use Symfony\Component\Validator\Constraints as Assert;

/** @see OrderEvent */
final class CancelYaMarketOrderStatusDTO implements OrderEventInterface
{
    /** Идентификатор события */
    #[Assert\NotBlank]
    #[Assert\Uuid]
    private readonly OrderEventUid $id;

    /** Ответственный */
    #[Assert\NotBlank]
    private readonly UserProfileUid $profile;

    /** Выделить заказ */
    private readonly bool $danger;

    /** Статус заказа */
    #[Assert\NotBlank]
    private OrderStatus $status;

    /** Комментарий к заказу */
    private ?string $comment;

    public function __construct(UserProfile|UserProfileUid|string $profile)
    {
        if(is_string($profile))
        {
            $profile = new UserProfileUid($profile);
        }

        if($profile instanceof UserProfile)
        {
            $profile = $profile->getId();
        }

        $this->profile = $profile;

        $this->danger = true;
    }


    /** Идентификатор события */
    public function getEvent(): OrderEventUid
    {
        return $this->id;
    }

    /**
     * Danger
     */
    public function getDanger(): bool
    {
        return $this->danger;
    }

    /**
     * Status
     */
    public function getStatus(): OrderStatus
    {
        return $this->status;
    }

    /**
     * Статус «Отмена» меняется в случае, если заказ «Новый» либо «Не оплаченный»
     * В остальных случаях отмена только в ручную, для этого заказ выделяется и обновляется комментарий
     */
    public function cancelOrder(): void
    {
        $this->status = new OrderStatus(OrderStatusCanceled::class);
    }

    /** Профиль пользователя при неоплаченном статусе - NULL */
    public function getProfile(): UserProfileUid
    {
        return $this->profile;
    }

    /**
     * Comment
     */
    public function getComment(): string
    {
        return $this->comment;
    }

    public function setComment(?string $comment): self
    {
        $this->comment = $comment;
        return $this;
    }


}
