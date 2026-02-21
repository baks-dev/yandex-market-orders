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

namespace BaksDev\Yandex\Market\Orders\UseCase\Status\New;

use BaksDev\Field\Pack\Phone\Type\PhoneField;
use BaksDev\Orders\Order\Entity\Order;
use BaksDev\Orders\Order\Repository\CurrentOrderNumber\CurrentOrderEventByNumberInterface;
use BaksDev\Orders\Order\Repository\ExistsOrderNumber\ExistsOrderNumberInterface;
use BaksDev\Orders\Order\Type\Status\OrderStatus\Collection\OrderStatusUnpaid;
use BaksDev\Orders\Order\UseCase\Admin\Status\OrderStatusHandler;
use BaksDev\Users\Profile\UserProfile\Entity\UserProfile;
use BaksDev\Users\Profile\UserProfile\Repository\CurrentUserProfileEvent\CurrentUserProfileEventInterface;
use BaksDev\Users\Profile\UserProfile\Repository\FieldValueForm\FieldValueFormDTO;
use BaksDev\Users\Profile\UserProfile\Repository\FieldValueForm\FieldValueFormInterface;
use BaksDev\Users\Profile\UserProfile\Type\Event\UserProfileEventUid;
use BaksDev\Users\Profile\UserProfile\UseCase\Admin\NewEdit\UserProfileDTO;
use BaksDev\Users\Profile\UserProfile\UseCase\Admin\NewEdit\UserProfileHandler;
use BaksDev\Users\Profile\UserProfile\UseCase\Admin\NewEdit\Value\ValueDTO;
use BaksDev\Yandex\Market\Orders\UseCase\New\NewYaMarketOrderDTO;

final readonly class ToggleUnpaidToNewYaMarketOrderHandler
{
    public function __construct(
        private ExistsOrderNumberInterface $existsOrderNumber,
        private CurrentOrderEventByNumberInterface $currentOrderNumber,
        private OrderStatusHandler $orderStatusHandler,
        private FieldValueFormInterface $fieldValue,
        private CurrentUserProfileEventInterface $currentUserProfileEvent,
        private UserProfileHandler $userProfileHandler,
    ) {}

    /**
     * Метод возвращает статус неоплаченного заказа UNPAID в статус NEW
     */
    public function handle(NewYaMarketOrderDTO $command): array|false
    {
        $isExists = $this->existsOrderNumber->isExists($command->getPostingNumber());

        if($isExists === false)
        {
            return false;
        }

        $arrOrderEvent = $this->currentOrderNumber->findAll($command->getPostingNumber());

        if(true === empty($arrOrderEvent))
        {
            return false;
        }

        $orders = [];

        foreach($arrOrderEvent as $OrderEvent)
        {
            if(false === $OrderEvent->isStatusEquals(OrderStatusUnpaid::class))
            {
                continue;
            }

            /**
             * Если заказ существует и его статус Unpaid «В ожидании оплаты» - обновляем на статус NEW «Новый»
             */

            $NewYaMarketOrderStatusDTO = new ToggleUnpaidToNewYaMarketOrderDTO();
            $OrderEvent->getDto($NewYaMarketOrderStatusDTO);
            $OrderUserDTO = $NewYaMarketOrderStatusDTO->getUsr();

            /** Обновляем информацию о клиенте */
            $UserProfileUid = $this->fillProfile($command, $OrderUserDTO->getProfile());

            if(false !== $UserProfileUid)
            {
                $OrderUserDTO->setProfile($UserProfileUid);
            }

            $NewYaMarketOrderStatusDTO->setOrderStatusNew();

            /**
             * Ожидается, что статус NEW «Новый» объявлен ранее для резерва продукции
             * применяем статус без проверки дублей (deduplicator: false)
             */
            $orders[] = $this->orderStatusHandler->handle($NewYaMarketOrderStatusDTO, false);

        }

        return $orders;
    }


    public function fillProfile(NewYaMarketOrderDTO $command, UserProfileEventUid $profile): UserProfileEventUid|false
    {

        if(empty($command->getBuyer()))
        {
            return false;
        }

        /** Получаем профиль пользователя */

        $UserProfileEvent = $this->currentUserProfileEvent->findByEvent($profile);

        if(false === $UserProfileEvent)
        {
            return false;
        }

        $Buyer = $command->getBuyer();


        /** @var UserProfileDTO $UserProfileDTO */
        $UserProfileDTO = $UserProfileEvent->getDto(UserProfileDTO::class);
        $PersonalDTO = $UserProfileDTO->getPersonal();
        $TypeProfileUid = $UserProfileDTO->getType();


        /** Определяем свойства клиента при доставке DBS */
        $profileFields = $this->fieldValue->get($TypeProfileUid);


        /** @var FieldValueFormDTO $profileField */
        foreach($profileFields as $profileField)
        {
            if(isset($Buyer['email']) && $profileField->getType()->getType() === 'account_email')
            {
                /** Не добавляем подменный mail YandexMarket */
                if($Buyer['email'] !== 'noreply-market@support.yandex.ru')
                {
                    $UserProfileValueDTO = new ValueDTO();
                    $UserProfileValueDTO->setField($profileField->getField());
                    $UserProfileValueDTO->setValue($Buyer['email']);
                    $UserProfileDTO->addValue($UserProfileValueDTO);
                }

                continue;
            }

            if($profileField->getType()->getType() === 'contact_field')
            {
                $keys = ['lastName', 'firstName', 'middleName'];

                $contactName = implode(' ', array_filter($Buyer, function($value, $key) use ($keys) {
                    return in_array($key, $keys, true);
                }, ARRAY_FILTER_USE_BOTH));

                $UserProfileValueDTO = new ValueDTO();
                $UserProfileValueDTO->setField($profileField->getField());
                $UserProfileValueDTO->setValue($contactName);
                $UserProfileDTO->addValue($UserProfileValueDTO);


                /** Сохраняем пользователя */
                $PersonalDTO->setUsername($contactName);

                continue;
            }

            if(isset($Buyer['phone']) && $profileField->getType()->getType() === 'phone_field')
            {
                $phone = PhoneField::formater($Buyer['phone']);

                $UserProfileValueDTO = new ValueDTO();
                $UserProfileValueDTO->setField($profileField->getField());
                $UserProfileValueDTO->setValue($phone);
                $UserProfileDTO->addValue($UserProfileValueDTO);

                continue;
            }
        }

        $OrderUserDTO = $command->getUsr();
        $OrderDeliveryDTO = $OrderUserDTO->getDelivery();

        /** Сохраняем локацию */
        $PersonalDTO->setLocation($OrderDeliveryDTO->getAddress());
        $PersonalDTO->setLatitude($OrderDeliveryDTO->getLatitude());
        $PersonalDTO->setLongitude($OrderDeliveryDTO->getLongitude());

        $UserProfile = $this->userProfileHandler->handle($UserProfileDTO);

        if($UserProfile instanceof UserProfile)
        {
            return $UserProfile->getEvent();
        }

        return false;
    }
}
