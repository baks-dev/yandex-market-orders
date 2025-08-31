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

namespace BaksDev\Yandex\Market\Orders\Api;

use BaksDev\Yandex\Market\Api\YandexMarket;

final class UpdateYaMarketOrderPackageStatusRequest extends YandexMarket
{

    private array|false $products = false;

    public function products(array $products): self
    {
        $this->products = $products;
        return $this;
    }

    /**
     * Подготовка заказа
     *
     * Позволяет выполнить три операции:
     *
     * - передать Маркету информацию о распределении товаров по коробкам;
     * - передать Маркету коды маркировки для товаров;
     * - удалить товар из заказа, если его не оказалось на складе.
     *
     * @see https://yandex.ru/dev/market/partner-api/doc/ru/reference/orders/setOrderBoxLayout
     */
    public function package(int|string $order): bool
    {

        if(false === $this->isExecuteEnvironment())
        {
            return true;
        }

        $order = str_replace('Y-', '', (string) $order);

        $response = $this->TokenHttpClient()
            ->request(
                'PUT',
                sprintf('/campaigns/%s/orders/%s/boxes', $this->getCompany(), $order),
                ['json' => ['boxes' => $this->products]],
            );

        if($response->getStatusCode() !== 200)
        {
            $content = $response->toArray(false);

            $this->logger->critical(
                sprintf('yandex-market-orders: Ошибка %s при обновлении упаковки заказа %s', $response->getStatusCode(), $order),
                [$content, $this->products, self::class.':'.__LINE__]);

            return false;
        }

        return true;
    }
}