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
use BaksDev\Orders\Order\Entity\Event\OrderEventInterface;
use BaksDev\Orders\Order\Type\Event\OrderEventUid;
use BaksDev\Reference\Currency\Type\Currency;
use BaksDev\Reference\Money\Type\Money;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
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

    /** Дата заказа */
    #[Assert\NotBlank]
    private DateTimeImmutable $created;


    /** Коллекция продукции в заказе */
    #[Assert\Valid]
    private ArrayCollection $product;

    /** Пользователь */
    #[Assert\Valid]
    private User\OrderUserDTO $usr;

    /** Ответственный */
    private ?UserProfileUid $profile = null;

    public function __construct(array $order, )
    {

        $this->number = (string) $order['id'];
        $this->created = new DateTimeImmutable($order['creationDate']);

        $this->product = new ArrayCollection();
        $this->usr = new User\OrderUserDTO();


        /** Дата доставки */
        $shipments = current($order['delivery']['shipments']);
        $deliveryDate = new DateTimeImmutable($shipments['shipmentDate'].' '.$shipments['shipmentTime']);

        $OrderDeliveryDTO = $this->usr->getDelivery();
        $OrderDeliveryDTO->setDeliveryDate($deliveryDate);


        $address = $order['delivery']['address'];

        /** Геолокация клиента */
        $OrderDeliveryDTO->setLatitude(new GpsLatitude($address['gps']['latitude']));
        $OrderDeliveryDTO->setLongitude(new GpsLongitude($address['gps']['longitude']));


        /** Адрес доставки клиента */
        $addressClient = $address['country'];
        $addressClient .= isset($address['city']) ? ', '.$address['city'] : null;

        if(isset($address['street']))
        {
            if(mb_strpos($address['street'], 'улица'))
            {
                $addressClient .= ', '.$address['street'];
            }
            else
            {
                $addressClient .= ', улица '.$address['street'];
            }
        }

        $addressClient .= isset($address['house']) ? ' '.$address['house'] : null;

        $addressClient .= isset($address['block']) ? 'к'.$address['block'] : null;

        $addressClient .= isset($address['entrance']) ? ', под.'.$address['entrance'] : null;

        $OrderDeliveryDTO->setAddress($addressClient);


        /** Продукция */

        foreach($order['items'] as $item)
        {
            $NewOrderProductDTO = new Products\NewOrderProductDTO($item['offerId']);

            $NewOrderPriceDTO = $NewOrderProductDTO->getPrice();

            $Money = new Money($item['priceBeforeDiscount']); // Стоимость товара в валюте магазина до применения скидок.
            $Currency = new Currency($order['currency']);

            $NewOrderPriceDTO->setPrice($Money);
            $NewOrderPriceDTO->setCurrency($Currency);
            $NewOrderPriceDTO->setTotal($item['count']);

            $this->addProduct($NewOrderProductDTO);

        }

    }


    /** @see OrderEvent */
    public function getEvent(): ?OrderEventUid
    {
        return $this->id;
    }

    /**
     * Number
     */
    public function getNumber(): string
    {
        return $this->number;
    }


    /** Коллекция продукции в заказе */

    public function getProduct(): ArrayCollection
    {
        return $this->product;
    }

    public function setProduct(ArrayCollection $product): void
    {
        $this->product = $product;
    }

    public function addProduct(Products\NewOrderProductDTO $product): void
    {
        $filter = $this->product->filter(function(Products\NewOrderProductDTO $element) use ($product)
        {
            return $element->getArticle() === $product->getArticle();
        });

        if($filter->isEmpty())
        {
            $this->product->add($product);
        }
    }

    public function removeProduct(Products\NewOrderProductDTO $product): void
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



}