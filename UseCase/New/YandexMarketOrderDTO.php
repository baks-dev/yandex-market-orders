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

namespace BaksDev\Yandex\Market\Orders\UseCase\New;

use BaksDev\Core\Type\Gps\GpsLatitude;
use BaksDev\Core\Type\Gps\GpsLongitude;
use BaksDev\Delivery\Type\Id\Choice\TypeDeliveryPickup;
use BaksDev\Delivery\Type\Id\DeliveryUid;
use BaksDev\DeliveryTransport\Type\OrderStatus\OrderStatusDelivery;
use BaksDev\Orders\Order\Entity\Event\OrderEventInterface;
use BaksDev\Orders\Order\Type\Event\OrderEventUid;
use BaksDev\Orders\Order\Type\Status\OrderStatus;
use BaksDev\Orders\Order\Type\Status\OrderStatus\Collection\OrderStatusCanceled;
use BaksDev\Orders\Order\Type\Status\OrderStatus\Collection\OrderStatusCompleted;
use BaksDev\Orders\Order\Type\Status\OrderStatus\Collection\OrderStatusNew;
use BaksDev\Orders\Order\Type\Status\OrderStatus\Collection\OrderStatusUnpaid;
use BaksDev\Orders\Order\Type\Status\OrderStatus\OrderStatusInterface;
use BaksDev\Payment\Type\Id\PaymentUid;
use BaksDev\Reference\Currency\Type\Currency;
use BaksDev\Reference\Money\Type\Money;
use BaksDev\Users\Profile\TypeProfile\Type\Id\TypeProfileUid;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
use BaksDev\Yandex\Market\Orders\Type\DeliveryType\TypeDeliveryDbsYaMarket;
use BaksDev\Yandex\Market\Orders\Type\DeliveryType\TypeDeliveryFbsYaMarket;
use BaksDev\Yandex\Market\Orders\Type\PaymentType\TypePaymentDbsYaMarket;
use BaksDev\Yandex\Market\Orders\Type\PaymentType\TypePaymentFbsYandex;
use BaksDev\Yandex\Market\Orders\Type\ProfileType\TypeProfileDbsYaMarket;
use BaksDev\Yandex\Market\Orders\Type\ProfileType\TypeProfileFbsYaMarket;
use BaksDev\Yandex\Market\Orders\UseCase\New\Products\NewOrderProductDTO;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Component\Validator\Constraints as Assert;

/** @see OrderEvent */
final class YandexMarketOrderDTO implements OrderEventInterface
{
    /** Идентификатор события */
    #[Assert\Uuid]
    private ?OrderEventUid $id = null;

    /** Идентификатор заказа YandexMarket */
    private string $number;

    /** Постоянная величина */
    #[Assert\Valid]
    private Invariable\NewOrderInvariable $invariable;

    /** Дата заказа */
    #[Assert\NotBlank]
    private DateTimeImmutable $created;

    /** Статус заказа */
    private OrderStatus $status;

    /** Коллекция продукции в заказе */
    #[Assert\Valid]
    private ArrayCollection $product;

    /** Пользователь */
    #[Assert\Valid]
    private User\OrderUserDTO $usr;

    /** Комментарий к заказу */
    private ?string $comment = null;

    /** Информация о покупателе */
    private ?array $buyer;


    public function __construct(array $order, UserProfileUid $profile, ?array $buyer = null)
    {
        /** Постоянная величина */
        $NewOrderInvariable = new Invariable\NewOrderInvariable();
        $NewOrderInvariable->setCreated(new DateTimeImmutable($order['creationDate'] ?: 'now'));
        $NewOrderInvariable->setProfile($profile);
        $NewOrderInvariable->setNumber('Y-'.$order['id']); // помечаем заказ префиксом Y
        $this->invariable = $NewOrderInvariable;


        /** @deprecated переносится в Invariable */
        $this->number = 'Y-'.$order['id']; // помечаем заказ префиксом Y
        $this->created = new DateTimeImmutable($order['creationDate'] ?: 'now');


        /** Определяем статус заказа */
        $yandexStatus = match ($order['status'])
        {
            'CANCELLED' => OrderStatusCanceled::class, // заказ отменен
            'DELIVERY', 'PICKUP' => OrderStatusDelivery::class, // заказ передан в службу доставки
            'DELIVERED' => OrderStatusCompleted::class, // заказ получен покупателем
            'UNPAID' => OrderStatusUnpaid::class, // заказ оформлен, но еще не оплачен
            default => OrderStatusNew::class,
        };

        $this->status = new OrderStatus($yandexStatus);


        $this->product = new ArrayCollection();
        $this->usr = new User\OrderUserDTO();

        /** Дата доставки */
        $shipments = current($order['delivery']['shipments']);

        $deliveryDate = false;

        if(isset($shipments['shipmentDate']))
        {
            $deliveryDate = new DateTimeImmutable($shipments['shipmentDate']);
        }

        if(false === $deliveryDate && isset($order['expiryDate']))
        {
            $deliveryDate = new DateTimeImmutable($order['expiryDate']);
        }


        $OrderPaymentDTO = $this->usr->getPayment();
        $OrderProfileDTO = $this->usr->getUserProfile();

        /**
         * Адрес и геолокация клиента
         */

        $address = $order['delivery']['address'];

        $OrderDeliveryDTO = $this->usr->getDelivery()
            ->setDeliveryDate($deliveryDate)
            ->setLatitude(new GpsLatitude($address['gps']['latitude']))
            ->setLongitude(new GpsLongitude($address['gps']['longitude']));


        /** Адрес доставки клиента */

        $deliveryAddress = [];

        foreach($address as $key => $data)
        {
            /** @see https://yandex.ru/dev/market/partner-api/doc/ru/reference/orders/getOrders#orderdeliveryaddressdto */
            if(
                empty($data) ||
                in_array($key, [
                    'gps', // GPS-координаты.
                    'postcode', // Почтовый индекс.
                    'recipient', // Фамилия, имя и отчество получателя заказа.
                    'subway', // Станция метро.
                    'floor', // Этаж
                    'phone', // Телефон получателя заказа.
                ])
            )
            {
                continue;
            }

            $deliveryAddress[] = match ($key)
            {
                //'street' => 'улица '.trim(str_replace('улица', '', $data)),
                'house' => 'дом '.$data,
                //'block' => 'корпус '.$data,
                'entrance' => 'подъезд '.$data,
                default => $data,
            };
        }

        $OrderDeliveryDTO->setAddress(implode(', ', $deliveryAddress));
        //dump($OrderDeliveryDTO->getAddress());

        // Доставка YandexMarket (FBS)
        if($order['delivery']['deliveryPartnerType'] === 'YANDEX_MARKET')
        {

            /** Тип профиля FBS Yandex Market */
            $Profile = new TypeProfileUid(TypeProfileFbsYaMarket::class);

            $OrderProfileDTO?->setType($Profile);

            /** Способ доставки Yandex Market (FBS Yandex Market) */
            $Delivery = new DeliveryUid(TypeDeliveryFbsYaMarket::class);
            $OrderDeliveryDTO->setDelivery($Delivery);

            /** Способ оплаты FBS Yandex Market */
            $Payment = new PaymentUid(TypePaymentFbsYandex::class);
            $OrderPaymentDTO->setPayment($Payment);
        }

        // Доставка Магазином (DBS)
        if($order['delivery']['deliveryPartnerType'] === 'SHOP')
        {
            /** Тип профиля DBS Yandex Market */
            $Profile = new TypeProfileUid(TypeProfileDbsYaMarket::class);
            $OrderProfileDTO?->setType($Profile);

            /** Доставка - Самовывоз */
            //if($order['delivery']['type'] === 'PICKUP')
            //{
            //    /** Способ доставки Самовывоз */
            //    $Delivery = new DeliveryUid(TypeDeliveryPickup::class);
            //}
            //else
            //{
            /** Способ доставки Магазином (DBS Yandex Market) */
            $Delivery = new DeliveryUid(TypeDeliveryDbsYaMarket::class);
            //}

            $OrderDeliveryDTO->setDelivery($Delivery);

            /** Способ оплаты DBS Yandex Market  */
            $Payment = new PaymentUid(TypePaymentDbsYaMarket::class);
            $OrderPaymentDTO->setPayment($Payment);
        }

        /** Информация о покупателе */
        $this->buyer = empty($buyer) ? null : $buyer;

        $deliveryComment = [];


        if(isset($order['delivery']['dispatchType']))
        {
            /**
             * SHOP_OUTLET - доставка в пункт выдачи заказов магазина.
             * Способ доставки Самовывоз
             */
            if($order['delivery']['dispatchType'] === 'SHOP_OUTLET')
            {
                $Delivery = new DeliveryUid(TypeDeliveryPickup::class);
                $OrderDeliveryDTO->setDelivery($Delivery);

                $deliveryComment[] = 'Самовывоз';

            }

            if($order['delivery']['dispatchType'] === 'MARKET_BRANDED_OUTLET')
            {
                $deliveryComment[] = 'Самовывоз из ПВЗ Яндекс Маркет';
            }
        }


        foreach($address as $key => $data)
        {
            /** @see https://yandex.ru/dev/market/partner-api/doc/ru/reference/orders/getOrders#orderdeliveryaddressdto */
            if(
                empty($data) ||
                !in_array($key, [
                    //'postcode', // Почтовый индекс.
                    'recipient', // Фамилия, имя и отчество получателя заказа.
                    'subway', // Станция метро.
                    'apartment', // Квартира или офис.
                    'floor', // Этаж
                    'phone', // Телефон получателя заказа.
                ])
            )
            {
                continue;
            }

            $deliveryComment[] = match ($key)
            {
                //'postcode' => 'инд. '.$data,
                'recipient' => 'получатель '.$data,
                'phone' => 'тел. '.$data,
                'subway' => 'ст.метро '.$data,
                'apartment' => 'кв. '.$data,
                'floor' => 'этаж '.$data,
                default => $data,
            };
        }

        isset($order['notes']) ? $deliveryComment[] = $order['notes'] : false;

        /** Комментарий покупателя */
        $this->comment = implode(', ', $deliveryComment);

        /** Продукция */
        foreach($order['items'] as $item)
        {

            $NewOrderProductDTO = new NewOrderProductDTO()
                ->setId($item['id'])
                ->setArticle($item['offerId']);

            $NewOrderPriceDTO = $NewOrderProductDTO->getPrice();

            $NewOrderPriceDTO
                ->setPrice(new Money($item['priceBeforeDiscount'])) // Стоимость товара в валюте магазина до применения скидок.
                ->setCurrency(new Currency($order['currency']))
                ->setTotal($item['count']);

            $this->addProduct($NewOrderProductDTO);
        }
    }


    /** @see OrderEvent */
    public function getEvent(): ?OrderEventUid
    {
        return $this->id;
    }

    public function setId(?OrderEventUid $id): self
    {
        $this->id = $id;
        return $this;
    }

    /**
     * Status
     */
    public function getStatus(): OrderStatus
    {
        return $this->status;
    }

    public function getStatusEquals(mixed $status): bool
    {
        return $this->status->equals($status);
    }

    public function setStatus(OrderStatus|OrderStatusInterface|string $status): self
    {
        $this->status = new OrderStatus($status);
        return $this;
    }


    /**
     * Number
     */
    public function getNumber(): string
    {
        return $this->number;
    }


    /**
     * Коллекция продукции в заказе
     *
     * @return ArrayCollection<NewOrderProductDTO>
     */
    public function getProduct(): ArrayCollection
    {
        return $this->product;
    }

    public function setProduct(ArrayCollection $product): void
    {
        $this->product = $product;
    }

    public function addProduct(NewOrderProductDTO $product): void
    {
        $filter = $this->product->filter(function(NewOrderProductDTO $element) use ($product) {
            return $element->getArticle() === $product->getArticle();
        });

        if($filter->isEmpty())
        {
            $this->product->add($product);
        }
    }

    public function removeProduct(NewOrderProductDTO $product): void
    {
        $this->product->removeElement($product);
    }

    /**
     * Usr
     */
    public function getUsr(): User\OrderUserDTO
    {
        return $this->usr;
    }

    /**
     * Comment
     */
    public function getComment(): ?string
    {
        return $this->comment;
    }

    /**
     * Buyer
     */
    public function getBuyer(): ?array
    {
        return $this->buyer;
    }

    /**
     * Invariable
     */
    public function getInvariable(): Invariable\NewOrderInvariable
    {
        return $this->invariable;
    }

}
