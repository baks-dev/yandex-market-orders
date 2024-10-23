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

/**
 * Информация о заказе
 */
final class GetYaMarketOrderInfoRequest extends YandexMarket
{
    /**
     * Возвращает информацию о 50 последних заказах со статусом:
     *
     * UNPAID - заказ оформлен, но еще не оплачен (если выбрана оплата при оформлении).
     *
     * Лимит: 1 000 000 запросов в час (~16666 в минуту | ~277 в секунду)
     *
     * @see https://yandex.ru/dev/market/partner-api/doc/ru/reference/orders/getOrders
     *
     */
    public function find(int|string $order): YandexMarketOrderDTO|false
    {
        $order = str_replace('Y-', '', (string) $order);

        $response = $this->TokenHttpClient()
            ->request('GET', sprintf('/campaigns/%s/orders/%s', $this->getCompany(), $order));

        $content = $response->toArray(false);


        if($response->getStatusCode() !== 200)
        {
            foreach($content['errors'] as $error)
            {
                $this->logger->critical($error['code'].': '.$error['message'], [self::class.':'.__LINE__]);
            }

            return false;
        }

        if(false === isset($content['order']))
        {
            return false;
        }

        $order = $content['order'];

        $client = null;

        // Получаем информацию о клиенте

        if(isset($order['buyer']['id']))
        {
            $clientResponse = $this->TokenHttpClient()->request(
                'GET',
                sprintf(
                    '/campaigns/%s/orders/%s/buyer',
                    $this->getCompany(),
                    $order['id']
                )
            );

            if($response->getStatusCode() === 200)
            {
                // Добавляем информацию о клиенте
                $client = $clientResponse->toArray(false)['result'];
            }
        }

        /** @see https://yandex.ru/dev/market/partner-api/doc/ru/reference/orders/getOrder#orderdto */
        return new YandexMarketOrderDTO($order, $this->getProfile(), $client);

    }
}
