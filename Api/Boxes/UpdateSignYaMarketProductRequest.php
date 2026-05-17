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

namespace BaksDev\Yandex\Market\Orders\Api\Boxes;

use BaksDev\Yandex\Market\Api\YandexMarket;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

#[Autoconfigure(shared: false)]
final class UpdateSignYaMarketProductRequest extends YandexMarket
{
    private array $products = [];

    private array $signs = [];

    public function signs(array $signs): self
    {
        $this->signs = $signs;

        return $this;
    }

    public function products(array $products): self
    {
        $this->products = $products;
        return $this;
    }

    /**
     * Позволяет передать Код маркировки товара в системе «Честный ЗНАК»
     *
     * @see https://yandex.ru/dev/market/partner-api/doc/ru/reference/orders/setOrderBoxLayout
     *
     */
    public function update(int|string $order): bool
    {
        if($this->isExecuteEnvironment() === false)
        {
            $this->logger->critical(
                message: 'Запрос может быть выполнен только в PROD окружении',
                context: [self::class.':'.__LINE__],
            );

            // return true;
        }

        if(empty($this->product))
        {
            $this->logger->critical(
                message: sprintf('Не передан продукт для заказа %s', $order),
                context: [self::class.':'.__LINE__],
            );

            $this->clearObject();
            return false;
        }

        if(empty($this->signs))
        {
            $this->logger->critical(
                message: sprintf('Не переданы код маркировки продукта в заказе %s', $order),
                context: [self::class.':'.__LINE__],
            );

            $this->clearObject();
            return false;
        }

        $order = str_replace('Y-', '', (string) $order);

        $products = [];

        foreach($this->products as $box => $product)
        {
            $products[] = [
                'items' => [
                    [
                        /**
                         * Идентификатор товара в заказе.
                         *
                         * @see https://yandex.ru/dev/market/partner-api/doc/ru/reference/orders/setOrderBoxLayout#entity-OrderBoxLayoutItemDTO
                         */
                        'id' => $product,
                        'fullCount' => 1,

                        /**
                         * Код маркировки товара в системе «Честный ЗНАК»
                         * https://yandex.ru/dev/market/partner-api/doc/ru/reference/orders/setOrderBoxLayout#entity-BriefOrderItemInstanceDTO
                         */
                        "instances" => [
                            $this->signs[$box] ?: null,
                        ],
                    ],
                ],
            ];
        }


        $response = $this->TokenHttpClient()
            ->request(
                method: 'PUT',
                url: sprintf('/campaigns/%s/orders/%s/boxes', $this->getCompany(), $order),
                options: [
                    'json' => [
                        'boxes' => $products,
                    ],
                ],
            );

        $content = $response->toArray(false);

        if($response->getStatusCode() !== 200)
        {
            foreach($content['errors'] as $error)
            {
                $this->logger->critical(
                    message: $error['code'].':'.$error['message'],
                    context: [self::class.':'.__LINE__]);
            }

            $this->clearObject();
            return false;
        }

        $this->clearObject();
        return true;
    }

    private function clearObject(): void
    {
        $this->products = [];
        $this->signs = [];
    }
}