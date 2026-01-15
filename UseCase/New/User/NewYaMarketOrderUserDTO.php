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

namespace BaksDev\Yandex\Market\Orders\UseCase\New\User;

use BaksDev\Orders\Order\Entity\User\OrderUserInterface;
use BaksDev\Users\Profile\UserProfile\Type\Event\UserProfileEventUid;
use BaksDev\Users\User\Type\Id\UserUid;
use Symfony\Component\Validator\Constraints as Assert;

final class NewYaMarketOrderUserDTO implements OrderUserInterface
{
    /* Пользователь  */

    /** ID пользователя  */
    #[Assert\Uuid]
    private ?UserUid $usr = null;

    /** Новый Аккаунт */
    private UserAccount\NewYaMarketUserAccountDTO $userAccount;

    /* Профиль пользователя */

    /** Идентификатор События!! профиля пользователя */
    #[Assert\Uuid]
    private ?UserProfileEventUid $profile = null;

    /** Новый профиль пользователя */
    private UserProfile\NewYaMarketUserProfileDTO $userProfile;


    /** Способ оплаты */
    #[Assert\Valid]
    private Payment\NewYaMarketOrderPaymentDTO $payment;

    /** Способ доставки */
    #[Assert\Valid]
    private Delivery\NewYaMarketOrderDeliveryDTO $delivery;


    public function __construct()
    {
        $this->userAccount = new UserAccount\NewYaMarketUserAccountDTO();
        $this->userProfile = new UserProfile\NewYaMarketUserProfileDTO();
        $this->payment = new Payment\NewYaMarketOrderPaymentDTO();
        $this->delivery = new Delivery\NewYaMarketOrderDeliveryDTO();

        $this->usr = new UserUid();
    }


    /** ID пользователя */
    public function getUsr(): ?UserUid
    {
        return $this->usr;
    }


    public function setUsr(?UserUid $usr): void
    {

        $this->usr = $usr;
    }


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

    public function getUserAccount(): ?UserAccount\NewYaMarketUserAccountDTO
    {
        //		if(!$this->user)
        //		{
        //			$this->userAccount = new UserAccount\UserAccountDTO();
        //		}

        return $this->userAccount;
    }


    public function setUserAccount(?UserAccount\NewYaMarketUserAccountDTO $userAccount): void
    {
        $this->userAccount = $userAccount;
    }


    /** Новый профиль пользователя */

    public function getUserProfile(): ?UserProfile\NewYaMarketUserProfileDTO
    {
        //		if(!$this->profile)
        //		{
        //			$this->userProfile = new UserProfile\UserProfileDTO();
        //		}

        return $this->userProfile;
    }


    public function setUserProfile(?UserProfile\NewYaMarketUserProfileDTO $userProfile): void
    {

        $this->userProfile = $userProfile;
    }


    /** Способ оплаты */

    public function getPayment(): Payment\NewYaMarketOrderPaymentDTO
    {
        return $this->payment;
    }


    public function setPayment(Payment\NewYaMarketOrderPaymentDTO $payment): void
    {
        $this->payment = $payment;
    }


    /** Способ доставки */

    public function getDelivery(): Delivery\NewYaMarketOrderDeliveryDTO
    {
        return $this->delivery;
    }


    public function setDelivery(Delivery\NewYaMarketOrderDeliveryDTO $delivery): void
    {
        $this->delivery = $delivery;
    }


}
