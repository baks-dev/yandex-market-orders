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

namespace BaksDev\Yandex\Market\Orders\UseCase\New;

use BaksDev\Contacts\Region\Repository\PickupByGeolocation\PickupByGeolocationInterface;
use BaksDev\Core\Entity\AbstractHandler;
use BaksDev\Core\Messenger\MessageDispatchInterface;
use BaksDev\Core\Type\Field\InputField;
use BaksDev\Core\Validator\ValidatorCollectionInterface;
use BaksDev\Delivery\Repository\CurrentDeliveryEvent\CurrentDeliveryEventInterface;
use BaksDev\Field\Pack\Phone\Type\PhoneField;
use BaksDev\Files\Resources\Upload\File\FileUploadInterface;
use BaksDev\Files\Resources\Upload\Image\ImageUploadInterface;
use BaksDev\Orders\Order\Entity\Event\OrderEvent;
use BaksDev\Orders\Order\Entity\Order;
use BaksDev\Orders\Order\Messenger\OrderMessage;
use BaksDev\Orders\Order\Repository\ExistsOrderNumber\ExistsOrderNumberInterface;
use BaksDev\Orders\Order\Repository\FieldByDeliveryChoice\FieldByDeliveryChoiceInterface;
use BaksDev\Orders\Order\Type\Status\OrderStatus\OrderStatusNew;
use BaksDev\Products\Product\Repository\CurrentProductByArticle\ProductConstByArticleInterface;
use BaksDev\Users\Address\Services\GeocodeAddressParser;
use BaksDev\Users\Profile\UserProfile\Entity\UserProfile;
use BaksDev\Users\Profile\UserProfile\Repository\FieldValueForm\FieldValueFormDTO;
use BaksDev\Users\Profile\UserProfile\Repository\FieldValueForm\FieldValueFormInterface;
use BaksDev\Users\Profile\UserProfile\Repository\UserByUserProfile\UserByUserProfileInterface;
use BaksDev\Users\Profile\UserProfile\UseCase\User\NewEdit\UserProfileHandler;
use BaksDev\Yandex\Market\Orders\UseCase\New\User\Delivery\Field\OrderDeliveryFieldDTO;
use BaksDev\Yandex\Market\Orders\UseCase\New\User\UserProfile\Value\ValueDTO;
use BaksDev\Yandex\Market\Orders\UseCase\Status\New\ToggleUnpaidToNewYaMarketOrderHandler;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;

final class YandexMarketOrderHandler extends AbstractHandler
{
    public function __construct(
        private readonly UserProfileHandler $profileHandler,
        private readonly ProductConstByArticleInterface $productConstByArticle,
        private readonly FieldByDeliveryChoiceInterface $deliveryFields,
        private readonly CurrentDeliveryEventInterface $currentDeliveryEvent,
        private readonly GeocodeAddressParser $geocodeAddressParser,
        private readonly FieldValueFormInterface $fieldValue,
        private readonly ExistsOrderNumberInterface $existsOrderNumber,
        private readonly ToggleUnpaidToNewYaMarketOrderHandler $newYaMarketOrderStatusHandler,
        private readonly UserByUserProfileInterface $userByUserProfile,
        private readonly PickupByGeolocationInterface $pickupByGeolocation,

        EntityManagerInterface $entityManager,
        MessageDispatchInterface $messageDispatch,
        ValidatorCollectionInterface $validatorCollection,
        ImageUploadInterface $imageUpload,
        FileUploadInterface $fileUpload,
    )
    {
        parent::__construct($entityManager, $messageDispatch, $validatorCollection, $imageUpload, $fileUpload);
    }

    public function handle(YandexMarketOrderDTO $command): string|Order
    {
        if(false === $command->getStatusEquals(OrderStatusNew::class))
        {
            return 'Заказ не является в статусе New «Новый»';
        }

        $isExists = $this->existsOrderNumber->isExists($command->getNumber());

        if($isExists)
        {
            /**
             * Если заказ в статусе Unpaid «В ожидании оплаты» - вернуть заказ в New «Новый»
             * в сервисе проверит, что заказ в статусе Unpaid
             */
            return $this->newYaMarketOrderStatusHandler->handle($command);
        }


        /**
         * Присваиваем заказу идентификатор пользователя
         */

        $NewOrderInvariable = $command->getInvariable();

        $User = $this->userByUserProfile
            ->forProfile($NewOrderInvariable->getProfile())
            ->findUser();

        if($User === false)
        {
            return 'Пользователь по профилю не найден';
        }

        $NewOrderInvariable->setUsr($User->getId());


        /**
         * Получаем события продукции
         * @var Products\NewOrderProductDTO $product
         */
        foreach($command->getProduct() as $product)
        {
            $ProductData = $this->productConstByArticle->find($product->getArticle());

            if(!$ProductData)
            {
                $error = sprintf('Артикул товара %s не найден', $product->getArticle());
                throw new InvalidArgumentException($error);
            }

            $product
                ->setProduct($ProductData->getEvent())
                ->setOffer($ProductData->getOffer())
                ->setVariation($ProductData->getVariation())
                ->setModification($ProductData->getModification());
        }


        /** Присваиваем информацию о покупателе */
        $this->fillProfile($command);


        /** Присваиваем информацию о доставке */
        $this->fillDelivery($command);

        /** Валидация DTO  */
        $this->validatorCollection->add($command);

        $OrderUserDTO = $command->getUsr();


        /**
         * Создаем профиль пользователя
         */
        if($OrderUserDTO->getProfile() === null)
        {
            $UserProfileDTO = $OrderUserDTO->getUserProfile();
            $this->validatorCollection->add($UserProfileDTO);

            if($UserProfileDTO === null)
            {
                return $this->validatorCollection->getErrorUniqid();
            }

            /* Присваиваем новому профилю идентификатор пользователя */
            $UserProfileDTO->getInfo()->setUsr($OrderUserDTO->getUsr());
            $UserProfile = $this->profileHandler->handle($UserProfileDTO);

            if(!$UserProfile instanceof UserProfile)
            {
                return $UserProfile;
            }

            $UserProfileEvent = $UserProfile->getEvent();
            $OrderUserDTO->setProfile($UserProfileEvent);
        }

        /** Сохраняем */

        $Order = new Order();
        $Order->setNumber($command->getNumber());

        $this
            ->setCommand($command)
            ->preEventPersistOrUpdate($Order, OrderEvent::class);

        /** Валидация всех объектов */
        if($this->validatorCollection->isInvalid())
        {
            return $this->validatorCollection->getErrorUniqid();
        }

        $this->flush();

        /* Отправляем сообщение в шину */
        $this->messageDispatch->dispatch(
            message: new OrderMessage($this->main->getId(), $this->main->getEvent(), $command->getEvent()),
            transport: 'orders-order'
        );

        return $this->main;
    }


    public function fillProfile(YandexMarketOrderDTO $command): void
    {
        if(empty($command->getBuyer()))
        {
            return;
        }

        /** Профиль пользователя  */
        $UserProfileDTO = $command->getUsr()->getUserProfile();

        if(null === $UserProfileDTO)
        {
            return;
        }

        /** Идентификатор типа профиля  */
        $TypeProfileUid = $UserProfileDTO?->getType();

        if(null === $TypeProfileUid)
        {
            return;
        }


        $Buyer = $command->getBuyer();

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
                    return in_array($key, $keys);
                }, ARRAY_FILTER_USE_BOTH));

                $UserProfileValueDTO = new ValueDTO();
                $UserProfileValueDTO->setField($profileField->getField());
                $UserProfileValueDTO->setValue($contactName);
                $UserProfileDTO->addValue($UserProfileValueDTO);

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
    }

    public function fillDelivery(YandexMarketOrderDTO $command): void
    {
        /* Идентификатор свойства адреса доставки */
        $OrderDeliveryDTO = $command->getUsr()->getDelivery();

        /* Создаем адрес геолокации */
        $GeocodeAddress = $this->geocodeAddressParser
            ->getGeocode(
                $OrderDeliveryDTO->getLatitude().', '.$OrderDeliveryDTO->getLongitude()
            );

        /** Если адрес не найден по геолокации - пробуем определить по адресу */
        if(empty($GeocodeAddress))
        {
            $GeocodeAddress = $this->geocodeAddressParser
                ->getGeocode(
                    $OrderDeliveryDTO->getAddress()
                );
        }

        if(!empty($GeocodeAddress))
        {
            $OrderDeliveryDTO->setAddress($GeocodeAddress->getAddress());
            //$OrderDeliveryDTO->setGeocode($GeocodeAddress->getId());
            $OrderDeliveryDTO->setLatitude($GeocodeAddress->getLatitude());
            $OrderDeliveryDTO->setLongitude($GeocodeAddress->getLongitude());
        }


        /**
         * Определяем свойства доставки и присваиваем адрес
         */

        $fields = $this->deliveryFields->fetchDeliveryFields($OrderDeliveryDTO->getDelivery());


        /** Указываем адрес доставки */

        $address_field = array_filter($fields, function($v) {
            /** @var InputField $InputField */
            return $v->getType()->getType() === 'address_field';
        });

        $address_field = current($address_field);

        if($address_field)
        {
            $OrderDeliveryFieldDTO = new OrderDeliveryFieldDTO();
            $OrderDeliveryFieldDTO->setField($address_field);
            $OrderDeliveryFieldDTO->setValue($OrderDeliveryDTO->getAddress());
            $OrderDeliveryDTO->addField($OrderDeliveryFieldDTO);
        }

        /** При самовывозе указываем ПВЗ */

        $contacts_region = array_filter($fields, function($v) {
            /** @var InputField $InputField */
            return $v->getType()->getType() === 'contacts_region_type';
        });

        $contacts_field = current($contacts_region);

        if($contacts_field)
        {
            $OrderDeliveryFieldDTO = new OrderDeliveryFieldDTO();
            $OrderDeliveryFieldDTO->setField($contacts_field);

            /** Определяем по геолокации ПВЗ */
            $PickupByGeolocationDTO = $this->pickupByGeolocation
                ->latitude($OrderDeliveryDTO->getLatitude())
                ->longitude($OrderDeliveryDTO->getLongitude())
                ->execute();

            if($PickupByGeolocationDTO)
            {
                $OrderDeliveryFieldDTO->setValue((string) $PickupByGeolocationDTO->getId());
            }

            $OrderDeliveryDTO->addField($OrderDeliveryFieldDTO);
        }

        /**
         * Присваиваем активное событие доставки
         */

        $DeliveryEvent = $this->currentDeliveryEvent->get($OrderDeliveryDTO->getDelivery());
        $OrderDeliveryDTO->setEvent($DeliveryEvent?->getId());
    }
}
