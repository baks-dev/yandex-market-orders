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
use BaksDev\Yandex\Market\Orders\UseCase\New\Box\NewYaMarketOrderBoxDTO;
use BaksDev\Yandex\Market\Orders\UseCase\New\Invariable\NewYaMarketOrderInvariableDTO;
use BaksDev\Yandex\Market\Orders\UseCase\New\Posting\NewYaMarketOrderPostingDTO;
use BaksDev\Yandex\Market\Orders\UseCase\New\Products\NewYaMarketOrderProductDTO;
use BaksDev\Yandex\Market\Orders\UseCase\New\User\NewYaMarketOrderUserDTO;
use BaksDev\Yandex\Market\Type\Id\YaMarketTokenUid;
use DateInterval;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Component\Validator\Constraints as Assert;

/** @see OrderEvent */
final class NewYaMarketOrderByBusinessDTO implements OrderEventInterface
{
    /** Идентификатор события */
    #[Assert\Uuid]
    private ?OrderEventUid $id = null;

    /** Постоянная величина */
    #[Assert\Valid]
    private NewYaMarketOrderInvariableDTO $invariable;

    /** Идентификатор отправления */
    #[Assert\Valid]
    private NewYaMarketOrderPostingDTO $posting;

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
    private NewYaMarketOrderUserDTO $usr;

    /** Комментарий к заказу */
    private string $comment;

    /** Информация о покупателе */
    private ?array $buyer;

    /**
     * @var array<int, NewYaMarketOrderBoxDTO>|null $boxes
     */
    private ?array $boxes = null;

    public function __construct(
        array $order,
        UserProfileUid $profile,
        YaMarketTokenUid|false $token,
        ?array $buyer = null
    )
    {

        $this->product = new ArrayCollection();
        $this->usr = new NewYaMarketOrderUserDTO();

        /**
         * Информация о покупателе
         */
        $this->buyer = empty($buyer) ? null : $buyer;

        /**
         * Постоянная величина
         */
        $this->invariable = new NewYaMarketOrderInvariableDTO()
            ->setCreated(new DateTimeImmutable($order['creationDate'] ?: 'now'))
            ->setProfile($profile)
            ->setToken($token)
            ->setNumber('Y-'.$order['orderId']); // помечаем заказ префиксом Y

        /**
         * Если имеется отправление - присваиваем, в ином случае присваиваем номер заказа
         */
        $this->posting = new NewYaMarketOrderPostingDTO()
            ->setValue(
                isset($order['posting'])
                    ? 'Y-'.$order['posting']
                    : 'Y-'.$order['orderId'],
            );

        $this->created = new DateTimeImmutable($order['creationDate'] ?: 'now');

        /**
         * Определяем статус заказа
         */
        $yandexStatus = match ($order['status'])
        {
            'CANCELLED' => OrderStatusCanceled::class, // заказ отменен
            'DELIVERY', 'PICKUP' => OrderStatusDelivery::class, // заказ передан в службу доставки
            'DELIVERED' => OrderStatusCompleted::class, // заказ получен покупателем
            'UNPAID' => OrderStatusUnpaid::class, // заказ оформлен, но еще не оплачен
            default => OrderStatusNew::class,
        };

        $this->status = new OrderStatus($yandexStatus);

        /**
         * Информация о доставке заказа
         * https://yandex.ru/dev/market/partner-api/doc/ru/reference/orders/getBusinessOrders#entity-BusinessOrderDeliveryDTO
         */
        $delivery = $order['delivery'];

        /**
         * Тип сотрудничества со службой доставки в рамках конкретного заказа
         * https://yandex.ru/dev/market/partner-api/doc/ru/reference/orders/getBusinessOrders#entity-OrderDeliveryPartnerType
         */
        $deliveryPartnerType = $delivery['deliveryPartnerType'];

        /**
         * Диапазон дат доставки
         * https://yandex.ru/dev/market/partner-api/doc/ru/reference/orders/getBusinessOrders#entity-BusinessOrderDeliveryDatesDTO
         */
        $dates = $delivery['dates'];


        /**
         * Определяем дату доставки
         */

        $fromDate = $dates['fromDate'];

        if($order['programType'] === 'FBS' && true === isset($delivery['shipment']))
        {
            $fromDate = $delivery['shipment']['shipmentDate'];
        }

        if($order['programType'] === 'DBS')
        {
            $fromDate = $dates['realDeliveryDate'] ?? $dates['toDate'];
        }

        $deliveryDate = new DateTimeImmutable($fromDate);

        /**
         * Предполагаем, что если Yandex Market дает возможность постоплаты заказа. Тогда:
         * - в поле даты в ответе будет метка времени Unix (1 января 1970 0:00:00)
         * - время для оплаты заказа +7 дней с момента создания заказа
         *
         * Изменяем дату исходя из этих условий
         */
        if(
            isset($order['buyerType']) && $order['buyerType'] === 'BUSINESS'
            && $order['status'] === 'UNPAID'
            && $deliveryDate < (new DateTimeImmutable('now'))->setTime(0, 0)
        )
        {
            $deliveryDate = new DateTimeImmutable($order['creationDate'])
                ->add(DateInterval::createFromDateString('7 days'));
        }

        $this->usr->getDelivery()->setDeliveryDate($deliveryDate);

        /**
         * Информация о коробке (для заказов в кабинете).
         * https://yandex.ru/dev/market/partner-api/doc/ru/reference/orders/getBusinessOrders#entity-BusinessOrderBoxLayoutDTO
         */
        if(isset($delivery['boxesLayout']))
        {
            foreach($delivery['boxesLayout'] as $box)
            {
                $this->boxes[] = new NewYaMarketOrderBoxDTO(...$box);
            }
        }

        $NewYaMarketOrderDeliveryDTO = $this->usr->getDelivery();

        $deliveryComment = [];

        /** Комментарий к заказу */
        isset($order['notes']) ? $deliveryComment[] = $order['notes'] : false;


        /**
         * Информация о курьерской доставке
         * https://yandex.ru/dev/market/partner-api/doc/ru/reference/orders/getBusinessOrders#entity-BusinessOrderCourierDeliveryDTO
         */
        if(isset($delivery['courier']['address']))
        {
            $address = $delivery['courier']['address'];

            /**
             * GPS-координаты широты и долготы
             * https://yandex.ru/dev/market/partner-api/doc/ru/reference/orders/getBusinessOrders#entity-GpsDTO
             */
            if(isset($address['gps']))
            {
                $gps = $address['gps'];

                $NewYaMarketOrderDeliveryDTO
                    ->setLatitude(new GpsLatitude($gps['latitude']))
                    ->setLongitude(new GpsLongitude($gps['longitude']));
            }

            $deliveryAddress = [];

            /**
             * Информация о курьерской доставке
             * https://yandex.ru/dev/market/partner-api/doc/ru/reference/orders/getBusinessOrders#entity-BusinessOrderDeliveryAddressDTO
             */
            $addressInfoForDeliveryAddress = [
                'gps', // GPS-координаты.
                'postcode', // Почтовый индекс.
                'recipient', // Фамилия, имя и отчество получателя заказа.
                'subway', // Станция метро.
                'phone', // Телефон получателя заказа.
                'entryphone', // Код домофона.
                'floor', // Этаж
                'apartment', // Номер квартиры или офиса.
            ];

            foreach($address as $key => $data)
            {
                /** Пропускаем информацию о доставке, добавляя ее в адрес */
                if(empty($data) || true === in_array($key, $addressInfoForDeliveryAddress))
                {
                    continue;
                }

                $deliveryAddress[] = match ($key)
                {
                    'house' => 'дом '.$data,
                    'block' => 'корпус '.$data,
                    'entrance' => 'подъезд '.$data,

                    default => $data,
                };
            }

            $NewYaMarketOrderDeliveryDTO->setAddress(implode(', ', $deliveryAddress));

            /**
             * Информация о курьерской доставке
             * https://yandex.ru/dev/market/partner-api/doc/ru/reference/orders/getBusinessOrders#entity-BusinessOrderDeliveryAddressDTO
             */
            $addressInfoForDeliveryComment = [
                'recipient', // Фамилия, имя и отчество получателя заказа.
                'subway', // Станция метро.
                'apartment', // Квартира или офис.
                'floor', // Этаж
                'phone', // Телефон получателя заказа.
            ];

            foreach($address as $key => $data)
            {
                /** Пропускаем информацию о доставке, добавляя ее в комментарий */
                if(empty($data) || false === in_array($key, $addressInfoForDeliveryComment))
                {
                    continue;
                }

                $deliveryComment[] = match ($key)
                {
                    'recipient' => 'получатель '.$data,
                    'phone' => 'тел. '.$data,
                    'subway' => 'ст.метро '.$data,
                    'entryphone' => 'код домофона '.$data,
                    'floor' => 'этаж '.$data,
                    'apartment' => 'кв. '.$data,

                    default => $data,
                };
            }

            /** Доставка Магазином (DBS) - добавляем комментарий о времени */
            if(
                $deliveryPartnerType === 'SHOP' &&
                ($dates['fromTime'] !== '00:00:00' || $dates['toTime'] !== '00:00:00')
            )
            {
                $deliveryComment[] = 'время c '.$dates['fromTime'].' до '.$dates['toTime'];
            }
        }


        /** Доставка YandexMarket (FBS) */
        if($deliveryPartnerType === 'YANDEX_MARKET')
        {
            /** Тип профиля FBS Yandex Market */
            $Profile = new TypeProfileUid(TypeProfileFbsYaMarket::class);
            $this->usr->getUserProfile()?->setType($Profile);

            /** Способ доставки Yandex Market (FBS Yandex Market) */
            $Delivery = new DeliveryUid(TypeDeliveryFbsYaMarket::class);
            $NewYaMarketOrderDeliveryDTO->setDelivery($Delivery);

            /** Способ оплаты FBS Yandex Market */
            $Payment = new PaymentUid(TypePaymentFbsYandex::class);
            $this->usr->getPayment()->setPayment($Payment);
        }

        /** Доставка Магазином (DBS) */
        if($deliveryPartnerType === 'SHOP')
        {
            /** Тип профиля DBS Yandex Market */
            $Profile = new TypeProfileUid(TypeProfileDbsYaMarket::class);
            $this->usr->getUserProfile()?->setType($Profile);

            /** Способ доставки Магазином (DBS Yandex Market) */
            $Delivery = new DeliveryUid(TypeDeliveryDbsYaMarket::class);
            $NewYaMarketOrderDeliveryDTO->setDelivery($Delivery);

            /** Способ оплаты DBS Yandex Market  */
            $Payment = new PaymentUid(TypePaymentDbsYaMarket::class);
            $this->usr->getPayment()->setPayment($Payment);
        }


        /**
         * Способ доставки Самовывоз
         * https://yandex.ru/dev/market/partner-api/doc/ru/reference/orders/getBusinessOrders#entity-OrderDeliveryDispatchType
         */
        if(isset($delivery['dispatchType']))
        {
            /**
             * Доставка в пункт выдачи заказов магазина
             */
            if($delivery['dispatchType'] === 'SHOP_OUTLET')
            {
                $Delivery = new DeliveryUid(TypeDeliveryPickup::class);
                $NewYaMarketOrderDeliveryDTO->setDelivery($Delivery);

                $deliveryComment[] = 'Самовывоз';

                /** Информация о пунте выдачи заказов магазина */

                $NewYaMarketOrderDeliveryDTO
                    ->setLatitude(new GpsLatitude($delivery['pickup']['address']['gps']['latitude']))
                    ->setLongitude(new GpsLongitude($delivery['pickup']['address']['gps']['longitude']))
                    ->setAddress(
                        implode(', ',
                            [
                                $delivery['pickup']['address']['country'],
                                $delivery['pickup']['address']['city'],
                                $delivery['pickup']['address']['street'],
                                $delivery['pickup']['address']['house'],
                            ]),
                    );
            }

            /** Доставка в пункт выдачи заказов Маркета */
            if($deliveryPartnerType === 'SHOP' && $delivery['dispatchType'] === 'MARKET_BRANDED_OUTLET')
            {
                $deliveryComment[] = 'Самовывоз из ПВЗ Яндекс Маркет';
            }
        }

        /** Комментарий покупателя */
        $this->comment = implode(', ', $deliveryComment);

        /**
         * Продукция
         */

        foreach($order['items'] as $item)
        {
            $NewYaMarketOrderProductDTO = new NewYaMarketOrderProductDTO()
                ->setArticle($item['offerId']);

            /**
             * Расчет для получения стоимости без применения скидки
             */

            /** Сумма платежа покупателя */
            $payment = ($item['prices']['payment']['value'] * 100) / $item['count'];

            /** Общая сумма вознаграждений продавцу*/
            $subsidy = true === isset($item['prices']['subsidy'])
                ? ($item['prices']['subsidy']['value'] * 100) / $item['count']
                : 0;

            /** Сумма, которая оплачена баллами Плюса */
            $cashback = true === isset($item['prices']['cashback'])
                ? ($item['prices']['cashback']['value'] * 100) / $item['count']
                : 0;

            /** Стоимость товара в валюте магазина до применения скидок */
            $priceBeforeDiscount = ($payment + $subsidy + $cashback) / 100;

            $NewYaMarketOrderProductDTO->getPrice()
                ->setTotal($item['count'])
                ->setPrice(new Money($priceBeforeDiscount))
                ->setCurrency(new Currency($item['prices']['payment']['currencyId']));

            $this->addProduct($NewYaMarketOrderProductDTO);
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
    public function getPostingNumber(): string
    {
        return $this->posting->getValue();
    }

    /** @return array<int, NewYaMarketOrderBoxDTO>|null */
    public function getPostingBox(): ?array
    {
        return $this->boxes;
    }


    /**
     * Коллекция продукции в заказе
     *
     * @return ArrayCollection<NewYaMarketOrderProductDTO>
     */
    public function getProduct(): ArrayCollection
    {
        return $this->product;
    }

    public function setProduct(ArrayCollection $product): void
    {
        $this->product = $product;
    }

    public function addProduct(NewYaMarketOrderProductDTO $product): void
    {
        $filter = $this->product->filter(function(NewYaMarketOrderProductDTO $element) use ($product) {
            return $element->getArticle() === $product->getArticle();
        });

        if($filter->isEmpty())
        {
            $this->product->add($product);
        }
    }

    public function removeProduct(NewYaMarketOrderProductDTO $product): void
    {
        $this->product->removeElement($product);
    }

    /**
     * Usr
     */
    public function getUsr(): NewYaMarketOrderUserDTO
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
    public function getInvariable(): NewYaMarketOrderInvariableDTO
    {
        return $this->invariable;
    }

    public function getPosting(): NewYaMarketOrderPostingDTO
    {
        return $this->posting;
    }

    public function getOrderNumber(): ?string
    {
        return $this->invariable->getNumber();
    }
}