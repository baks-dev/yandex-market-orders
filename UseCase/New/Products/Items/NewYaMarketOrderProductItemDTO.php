<?php
/*
 *  Copyright 2025.  Baks.dev <admin@baks.dev>
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

namespace BaksDev\Yandex\Market\Orders\UseCase\New\Products\Items;

use BaksDev\Orders\Order\Entity\Items\OrderProductItemInterface;
use BaksDev\Orders\Order\Type\Items\Const\OrderProductItemConst;
use BaksDev\Orders\Order\Type\Items\OrderProductItemUid;
use BaksDev\Yandex\Market\Orders\UseCase\New\Products\Items\Access\NewYaMarketOrderProductItemAccessDTO;
use BaksDev\Yandex\Market\Orders\UseCase\New\Products\Items\Price\NewYaMarketOrderProductItemPriceDTO;
use Symfony\Component\Validator\Constraints as Assert;

/** @see OrderProductItem */
final class NewYaMarketOrderProductItemDTO implements OrderProductItemInterface
{
    /**
     * ID единицы продукта в заказе
     */
    #[Assert\Uuid]
    private OrderProductItemUid $id;

    /**
     * Постоянный уникальный идентификатор
     */
    #[Assert\Uuid]
    private OrderProductItemConst $const;

    /**
     * Цена единицы продукта
     */
    #[Assert\Valid]
    private NewYaMarketOrderProductItemPriceDTO $price;

    /**
     * Флаг для производства
     */
    #[Assert\Valid]
    private NewYaMarketOrderProductItemAccessDTO $access;

    public function __construct()
    {
        $this->id = new OrderProductItemUid();
        $this->price = new NewYaMarketOrderProductItemPriceDTO();
        $this->const = new OrderProductItemConst();
        $this->access = new NewYaMarketOrderProductItemAccessDTO();
    }

    public function getId(): ?OrderProductItemUid
    {
        return $this->id;
    }

    public function getPrice(): NewYaMarketOrderProductItemPriceDTO
    {
        return $this->price;
    }

    public function setPrice(NewYaMarketOrderProductItemPriceDTO $price): void
    {
        $this->price = $price;
    }

    public function getAccess(): NewYaMarketOrderProductItemAccessDTO
    {
        return $this->access;
    }

    public function setAccess(NewYaMarketOrderProductItemAccessDTO $access): void
    {
        $this->access = $access;
    }

    public function getConst(): OrderProductItemConst
    {
        return $this->const;
    }

    public function setConst(OrderProductItemConst $const): void
    {
        $this->const = $const;
    }
}