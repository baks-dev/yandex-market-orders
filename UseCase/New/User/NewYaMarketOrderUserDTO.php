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

namespace BaksDev\Yandex\Market\Orders\UseCase\New\User;

use BaksDev\Orders\Order\Entity\User\OrderUserInterface;
use BaksDev\Users\Profile\UserProfile\Type\Event\UserProfileEventUid;
use BaksDev\Users\User\Type\Id\UserUid;
use BaksDev\Yandex\Market\Orders\UseCase\New\User\Delivery\NewYaMarketOrderDeliveryDTO;
use BaksDev\Yandex\Market\Orders\UseCase\New\User\Payment\NewYaMarketOrderPaymentDTO;
use BaksDev\Yandex\Market\Orders\UseCase\New\User\UserAccount\NewYaMarketUserAccountDTO;
use BaksDev\Yandex\Market\Orders\UseCase\New\User\UserProfile\NewYaMarketUserProfileDTO;
use Symfony\Component\Validator\Constraints as Assert;

final class NewYaMarketOrderUserDTO implements OrderUserInterface
{
    /**
     * Пользователь
     */

    /** ID пользователя  */
    #[Assert\Uuid]
    private readonly UserUid $usr;

    /** Новый Аккаунт */
    private readonly NewYaMarketUserAccountDTO $userAccount;

    /**
     * Профиль пользователя
     */

    /** Идентификатор События!! профиля пользователя */
    #[Assert\Uuid]
    private ?UserProfileEventUid $profile = null;

    /** Новый профиль пользователя */
    private readonly NewYaMarketUserProfileDTO $userProfile;

    /** Способ оплаты */
    #[Assert\Valid]
    private readonly NewYaMarketOrderPaymentDTO $payment;

    /** Способ доставки */
    #[Assert\Valid]
    private readonly NewYaMarketOrderDeliveryDTO $delivery;

    public function __construct()
    {
        $this->usr = new UserUid();

        $this->userAccount = new NewYaMarketUserAccountDTO();
        $this->userProfile = new NewYaMarketUserProfileDTO();
        $this->payment = new NewYaMarketOrderPaymentDTO();
        $this->delivery = new NewYaMarketOrderDeliveryDTO();
    }

    /** ID пользователя */
    public function getUsr(): ?UserUid
    {
        return $this->usr;
    }

    //    public function setUsr(?UserUid $usr): void
    //    {
    //        $this->usr = $usr;
    //    }

    /** Идентификатор События!! профиля пользователя */

    public function getProfile(): ?UserProfileEventUid
    {

        return $this->profile;
    }


    public function setProfile(?UserProfileEventUid $profile): void
    {
        $this->profile = $profile;
    }


    /** Новый Аккаунт */

    public function getUserAccount(): ?NewYaMarketUserAccountDTO
    {
        return $this->userAccount;
    }

    //    public function setUserAccount(?UserAccount\NewYaMarketUserAccountDTO $userAccount): void
    //    {
    //        $this->userAccount = $userAccount;
    //    }


    /** Новый профиль пользователя */

    public function getUserProfile(): ?NewYaMarketUserProfileDTO
    {
        return $this->userProfile;
    }

    //    public function setUserProfile(?UserProfile\NewYaMarketUserProfileDTO $userProfile): void
    //    {
    //
    //        $this->userProfile = $userProfile;
    //    }


    /** Способ оплаты */

    public function getPayment(): NewYaMarketOrderPaymentDTO
    {
        return $this->payment;
    }


    //    public function setPayment(Payment\NewYaMarketOrderPaymentDTO $payment): void
    //    {
    //        $this->payment = $payment;
    //    }


    /** Способ доставки */

    public function getDelivery(): NewYaMarketOrderDeliveryDTO
    {
        return $this->delivery;
    }

    //
    //    public function setDelivery(Delivery\NewYaMarketOrderDeliveryDTO $delivery): void
    //    {
    //        $this->delivery = $delivery;
    //    }


}
