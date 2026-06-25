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

namespace BaksDev\Yandex\Market\Orders\UseCase\New;

use BaksDev\Contacts\Region\Repository\PickupByGeolocation\PickupByGeolocationDTO;
use BaksDev\Contacts\Region\Repository\PickupByGeolocation\PickupByGeolocationInterface;
use BaksDev\Core\Entity\AbstractHandler;
use BaksDev\Core\Messenger\MessageDispatchInterface;
use BaksDev\Core\Type\Field\InputField;
use BaksDev\Core\Validator\ValidatorCollectionInterface;
use BaksDev\Delivery\Repository\CurrentDeliveryEvent\CurrentDeliveryEventInterface;
use BaksDev\Delivery\Type\Event\DeliveryEventUid;
use BaksDev\Field\Pack\Phone\Type\PhoneField;
use BaksDev\Files\Resources\Upload\File\FileUploadInterface;
use BaksDev\Files\Resources\Upload\Image\ImageUploadInterface;
use BaksDev\Orders\Order\Entity\Event\OrderEvent;
use BaksDev\Orders\Order\Entity\Order;
use BaksDev\Orders\Order\Messenger\OrderMessage;
use BaksDev\Orders\Order\Repository\ExistsOrderNumber\ExistsOrderNumberInterface;
use BaksDev\Orders\Order\Repository\FieldByDeliveryChoice\FieldByDeliveryChoiceInterface;
use BaksDev\Orders\Order\Type\Status\OrderStatus\Collection\OrderStatusNew;
use BaksDev\Products\Product\Repository\CurrentProductByArticle\CurrentProductByBarcodeResult;
use BaksDev\Products\Product\Repository\CurrentProductByArticle\ProductConstByArticleInterface;
use BaksDev\Users\Address\Services\GeocodeAddressParser;
use BaksDev\Users\Address\UseCase\Geocode\GeocodeAddressDTO;
use BaksDev\Users\Profile\UserProfile\Entity\UserProfile;
use BaksDev\Users\Profile\UserProfile\Repository\FieldValueForm\FieldValueFormDTO;
use BaksDev\Users\Profile\UserProfile\Repository\FieldValueForm\FieldValueFormInterface;
use BaksDev\Users\Profile\UserProfile\Repository\UserByUserProfile\UserByUserProfileInterface;
use BaksDev\Users\Profile\UserProfile\UseCase\User\NewEdit\UserProfileHandler;
use BaksDev\Yandex\Market\Orders\UseCase\New\Products\Items\NewYaMarketOrderProductItemDTO;
use BaksDev\Yandex\Market\Orders\UseCase\New\Products\NewYaMarketOrderProductDTO;
use BaksDev\Yandex\Market\Orders\UseCase\New\User\Delivery\Field\NewYaMarketOrderDeliveryFieldDTO;
use BaksDev\Yandex\Market\Orders\UseCase\New\User\UserProfile\Value\NewYaMarketUserProfileValueDTO;
use BaksDev\Yandex\Market\Orders\UseCase\Status\New\ToggleUnpaidToNewYaMarketOrderHandler;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Создает заказ со статусом New «Новый» или переводит заказа в статусе Unpaid в статус New «Новый»
 */
final class NewYaMarketOrderHandler extends AbstractHandler
{
    public function __construct(
        private readonly GeocodeAddressParser $geocodeAddressParser,
        private readonly UserProfileHandler $profileHandler,
        private readonly ToggleUnpaidToNewYaMarketOrderHandler $toggleUnpaidToNewYaMarketOrderHandler,
        private readonly ProductConstByArticleInterface $productConstByArticleRepository,
        private readonly FieldByDeliveryChoiceInterface $deliveryFieldsRepository,
        private readonly CurrentDeliveryEventInterface $currentDeliveryEventRepository,
        private readonly FieldValueFormInterface $fieldValueRepository,
        private readonly ExistsOrderNumberInterface $existsOrderNumberRepository,
        private readonly UserByUserProfileInterface $userByUserProfileRepository,
        private readonly PickupByGeolocationInterface $pickupByGeolocationRepository,

        EntityManagerInterface $entityManager,
        MessageDispatchInterface $messageDispatch,
        ValidatorCollectionInterface $validatorCollection,
        ImageUploadInterface $imageUpload,
        FileUploadInterface $fileUpload,

        #[Autowire(env: 'PROJECT_PROFILE')] string|null $projectProfile = null,
    )
    {
        parent::__construct($entityManager, $messageDispatch, $validatorCollection, $imageUpload, $fileUpload);
    }

    public function handle(NewYaMarketOrderDTO|NewYaMarketOrderByBusinessDTO $command): string|array|bool|Order
    {
        if(false === $command->getStatusEquals(OrderStatusNew::class))
        {
            return false;
        }

        $isExists = $this->existsOrderNumberRepository->isExists($command->getPostingNumber());

        if($isExists)
        {
            /**
             * Если заказ был создан в статусе Unpaid «В ожидании оплаты» - вернуть заказ в New «Новый»
             * в сервисе проверит, что заказ в статусе Unpaid
             *
             * @see UnpaidYaMarketOrderStatusHandler
             */
            return $this->toggleUnpaidToNewYaMarketOrderHandler->handle($command);
        }

        /**
         * Присваиваем заказу идентификатор пользователя
         */

        $this->setCommand($command);

        $NewOrderInvariable = $command->getInvariable();

        $User = $this->userByUserProfileRepository
            ->forProfile($NewOrderInvariable->getProfile())
            ->find();

        if($User === false)
        {
            return false;
            // return 'Пользователь по профилю не найден';
        }

        $NewOrderInvariable->setUsr($User->getId());


        /**
         * Получаем события продукции
         *
         * @var NewYaMarketOrderProductDTO $product
         */
        foreach($command->getProduct() as $product)
        {
            $ProductData = $this->productConstByArticleRepository->find($product->getArticle());

            if(false === ($ProductData instanceof CurrentProductByBarcodeResult))
            {
                $error = sprintf('Артикул товара %s не найден', $product->getArticle());
                throw new InvalidArgumentException($error);
            }

            $product
                ->setProduct($ProductData->getEvent())
                ->setOffer($ProductData->getOffer())
                ->setVariation($ProductData->getVariation())
                ->setModification($ProductData->getModification());


            /**
             * Items
             * Создаем единицу продукта по количеству продукта в заказе
             */
            for($i = 0; $i < $product->getPrice()->getTotal(); $i++)
            {
                $item = new NewYaMarketOrderProductItemDTO();

                /**
                 * Присваиваем цену из продукта в заказе
                 */
                $item->getPrice()
                    ->setPrice($product->getPrice()->getPrice())
                    ->setCurrency($product->getPrice()->getCurrency());

                $product->addItem($item);
            }

        }


        /** Присваиваем информацию о покупателе */
        $this->fillProfile($command);

        /** Присваиваем информацию о доставке */
        $this->fillDelivery($command);

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

            if(false === ($UserProfile instanceof UserProfile))
            {
                return $UserProfile;
            }

            $UserProfileEvent = $UserProfile->getEvent();
            $OrderUserDTO->setProfile($UserProfileEvent);
        }

        /** Сохраняем */

        $this
            ->setCommand($command)
            ->preEventPersistOrUpdate(Order::class, OrderEvent::class);


        /** Валидация всех объектов */
        if($this->validatorCollection->isInvalid())
        {
            return $this->validatorCollection->getErrorUniqid();
        }


        $this->flush();

        /* Отправляем сообщение в шину */
        $this->messageDispatch
            ->addClearCacheOther('orders-order-new')
            ->dispatch(
                message: new OrderMessage($this->main->getId(), $this->main->getEvent(), $command->getEvent()),
                transport: 'orders-order',
            );

        return $this->main;
    }


    public function fillProfile(NewYaMarketOrderDTO|NewYaMarketOrderByBusinessDTO $command): void
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
        $profileFields = $this->fieldValueRepository->get($TypeProfileUid);

        /** @var FieldValueFormDTO $profileField */
        foreach($profileFields as $profileField)
        {

            if(isset($Buyer['email']) && $profileField->getType()->getType() === 'account_email')
            {
                /** Не добавляем подменный mail YandexMarket */
                if($Buyer['email'] !== 'noreply-market@support.yandex.ru')
                {
                    $UserProfileValueDTO = new NewYaMarketUserProfileValueDTO();
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

                $UserProfileValueDTO = new NewYaMarketUserProfileValueDTO();
                $UserProfileValueDTO->setField($profileField->getField());
                $UserProfileValueDTO->setValue($contactName);
                $UserProfileDTO->addValue($UserProfileValueDTO);

                continue;
            }

            if(isset($Buyer['phone']) && $profileField->getType()->getType() === 'phone_field')
            {
                $phone = PhoneField::formater($Buyer['phone']);

                $UserProfileValueDTO = new NewYaMarketUserProfileValueDTO();
                $UserProfileValueDTO->setField($profileField->getField());
                $UserProfileValueDTO->setValue($phone);
                $UserProfileDTO->addValue($UserProfileValueDTO);

                continue;
            }

        }
    }

    public function fillDelivery(NewYaMarketOrderDTO|NewYaMarketOrderByBusinessDTO $command): void
    {
        /** Идентификатор свойства адреса доставки */
        $OrderDeliveryDTO = $command->getUsr()->getDelivery();

        /**
         * Определяем адрес
         */

        $GeocodeAddress = null;

        if($OrderDeliveryDTO->getLatitude() && $OrderDeliveryDTO->getLongitude())
        {
            /** Пробуем определить по геолокации */
            $GeocodeAddress = $this->geocodeAddressParser
                ->getGeocode(
                    $OrderDeliveryDTO->getLatitude().', '.$OrderDeliveryDTO->getLongitude(),
                );
        }

        /** Если адрес не найден по геолокации - пробуем определить по адресу */
        if(true === empty($GeocodeAddress) && false === empty($OrderDeliveryDTO->getAddress()))
        {
            $GeocodeAddress = $this->geocodeAddressParser
                ->getGeocode(
                    $OrderDeliveryDTO->getAddress(),
                );
        }

        /** Если определили адрес - присваиваем */
        if(false === empty($GeocodeAddress))
        {
            // Не переприсваиваем адрес
            //$OrderDeliveryDTO->setAddress($GeocodeAddress->getAddress());
            $OrderDeliveryDTO->setLatitude($GeocodeAddress->getLatitude());
            $OrderDeliveryDTO->setLongitude($GeocodeAddress->getLongitude());
        }


        /**
         * Определяем свойства доставки и присваиваем адрес
         */

        $fields = $this->deliveryFieldsRepository->fetchDeliveryFields($OrderDeliveryDTO->getDelivery());


        /** Указываем адрес доставки */

        if($fields)
        {


            $address_field = array_filter($fields, function($v) {
                /** @var InputField $InputField */
                return $v->getType()->getType() === 'address_field';
            });

            $address_field = current($address_field);

            if($address_field)
            {
                $OrderDeliveryFieldDTO = new NewYaMarketOrderDeliveryFieldDTO();
                $OrderDeliveryFieldDTO->setField($address_field);
                $OrderDeliveryFieldDTO->setValue($OrderDeliveryDTO->getAddress());
                $OrderDeliveryDTO->addField($OrderDeliveryFieldDTO);
            }

            /** При самовывозе указываем ПВЗ */

            $contacts_region = array_filter($fields, static function($v) {
                /** @var InputField $InputField */
                return $v->getType()->getType() === 'contacts_region_type';
            });

            $contacts_field = current($contacts_region);

            if($contacts_field)
            {
                $OrderDeliveryFieldDTO = new NewYaMarketOrderDeliveryFieldDTO();
                $OrderDeliveryFieldDTO->setField($contacts_field);
                $PickupByGeolocationDTO = false;

                if($OrderDeliveryDTO->getLatitude() && $OrderDeliveryDTO->getLongitude())
                {
                    /** Определяем по геолокации ПВЗ */
                    $PickupByGeolocationDTO = $this->pickupByGeolocationRepository
                        ->latitude($OrderDeliveryDTO->getLatitude())
                        ->longitude($OrderDeliveryDTO->getLongitude())
                        ->execute();
                }

                /** Если по геолокации не определили - продуем определить по адресу ПВЗ  */
                if(
                    false === ($PickupByGeolocationDTO instanceof PickupByGeolocationDTO)
                    && $OrderDeliveryDTO->getAddress()
                )
                {
                    $GeocodeAddress = $this->geocodeAddressParser
                        ->getGeocode($OrderDeliveryDTO->getAddress());

                    if($GeocodeAddress instanceof GeocodeAddressDTO)
                    {
                        $PickupByGeolocationDTO = $this->pickupByGeolocationRepository
                            ->latitude($GeocodeAddress->getLatitude())
                            ->longitude($GeocodeAddress->getLongitude())
                            ->execute();
                    }
                }

                if(true === ($PickupByGeolocationDTO instanceof PickupByGeolocationDTO))
                {
                    $OrderDeliveryFieldDTO->setValue((string) $PickupByGeolocationDTO->getId());
                }

                /** Если склад не определен - присваиваем текущий из .env PROJECT_PROFILE */
                if(
                    false === ($PickupByGeolocationDTO instanceof PickupByGeolocationDTO)
                    && false === empty($this->projectProfile)
                )
                {
                    $OrderDeliveryFieldDTO->setValue((string) $PickupByGeolocationDTO->getId());
                }

                $OrderDeliveryDTO->addField($OrderDeliveryFieldDTO);
            }
        }

        /**
         * Присваиваем активное событие доставки
         */

        $DeliveryEventUid = $this->currentDeliveryEventRepository
            ->forDelivery($OrderDeliveryDTO->getDelivery())
            ->getId();

        if(false === $DeliveryEventUid instanceof DeliveryEventUid)
        {

            throw new InvalidArgumentException(
                sprintf('Способ доставки не найден! Выполните команду Upgrade типа %s : ', $OrderDeliveryDTO->getDelivery()),
            );
        }

        $OrderDeliveryDTO->setEvent($DeliveryEventUid);

    }
}
