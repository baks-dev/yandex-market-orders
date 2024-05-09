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

namespace BaksDev\Yandex\Market\Orders\Api;

use BaksDev\Yandex\Market\Api\YandexMarket;
use BaksDev\Yandex\Market\Orders\UseCase\New\YandexMarketOrderDTO;
use DomainException;

/**
 * Информация о заказах
 */
final class YandexMarketAllOrdersRequest extends YandexMarket
{

    private int $page = 1;

    public function page(int $page): self
    {
        $this->page = $page;
        return $this;
    }

    /**
     * Возвращает информацию о заказах. Запрос можно использовать, чтобы узнать, нет ли новых заказов.
     *
     * Лимит: 1 000 000 запросов в час
     *
     * @see https://yandex.ru/dev/market/partner-api/doc/ru/reference/orders/getOrders
     *
     */
    public function findAll(string $status = 'PROCESSING')
    {
        while(true)
        {

            $response = $this->TokenHttpClient()
                ->request(
                    'GET',
                    sprintf('/campaigns/%s/orders', $this->getCompany()),
                    ['query' =>
                        [
                            'page' => $this->page,
                            'pageSize' => 50,
                            'status' => $status
                        ]
                    ],
                );

            $content = $response->toArray(false);

            if($response->getStatusCode() !== 200)
            {
                foreach($content['errors'] as $error)
                {
                    $this->logger->critical($error['code'].': '.$error['message'], [__FILE__.':'.__LINE__]);
                }

                throw new DomainException(
                    message: 'Ошибка '.self::class,
                    code: $response->getStatusCode()
                );
            }

            $this->page = $content['pager']['currentPage'] + 1;

            if($this->page === $content['pager']['pagesCount'] || $content['pager']['total'] < 50 )
            {
                break;
            }

            foreach($content['orders'] as $order)
            {
                /** Продукция в заказе */
                foreach($order['items'] as $item)
                {

                }


                /** @see https://yandex.ru/dev/market/partner-api/doc/ru/reference/orders/getOrders#orderdto */
                yield new YandexMarketOrderDTO($order);
            }
        }
    }
}