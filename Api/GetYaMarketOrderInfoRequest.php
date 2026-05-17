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

namespace BaksDev\Yandex\Market\Orders\Api;

use BaksDev\Yandex\Market\Api\YandexMarket;
use BaksDev\Yandex\Market\Orders\UseCase\New\NewYaMarketOrderByBusinessDTO;
use BaksDev\Yandex\Market\Orders\UseCase\New\NewYaMarketOrderDTO;
use DateInterval;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Информация о заказе
 *
 * @note использует тот же запрос:
 * @see GetYaMarketOrdersNewRequest
 * @see GetYaMarketOrdersCancelRequest
 * @see GetYaMarketOrdersUnpaidRequest
 * @see GetYaMarketOrderInfoRequest
 * @see GetYaMarketOrdersCompletedRequest
 */
#[Autoconfigure(shared: false)]
final class GetYaMarketOrderInfoRequest extends YandexMarket
{

    /**
     * Возвращает информацию об одном заказе
     *
     * https://yandex.ru/dev/market/partner-api/doc/ru/reference/orders/getBusinessOrders
     */
    public function findNew(int|string $order): NewYaMarketOrderByBusinessDTO|false
    {
        $order = str_replace('Y-', '', (string) $order);


        $cache = $this->getCacheInit('yandex-market-orders');

        $key = $this->getBusiness().$this->getCompany().$order;

        $order = $cache->get($key, function(ItemInterface $item) use ($order): array|false {

            /** Временно кешируем этикетку на 1 секунду */
            $item->expiresAfter(DateInterval::createFromDateString('1 seconds'));

            $response = $this->TokenHttpClient()
                ->request(
                    method: 'POST',
                    url: sprintf('/v1/businesses/%s/orders', $this->getBusiness()),
                    options: [
                        'json' => [
                            "campaignIds" => [
                                $this->getCompany(),
                            ],
                            "orderIds" => [
                                (int) $order,
                            ],
                            'fake' => false,
                        ],
                    ],
                );

            $content = $response->toArray(false);

            if($response->getStatusCode() !== 200)
            {
                foreach($content['errors'] as $error)
                {
                    $this->logger->critical(
                        message: $error['code'].': '.$error['message'],
                        context: [self::class.':'.__LINE__],
                    );
                }

                return false;
            }

            if(true === empty($content['orders']))
            {
                return false;
            }

            $item->expiresAfter(DateInterval::createFromDateString('5 seconds'));

            return current($content['orders']);

        });

        /**
         * Получаем информацию о клиенте
         */

        $client = null;

        if($order['programType'] === 'DBS')
        {
            /**
             * Информация о покупателе — физическом лице
             * https://yandex.ru/dev/market/partner-api/doc/ru/reference/orders/getOrderBuyerInfo
             */
            $clientRequest = $this->TokenHttpClient()->request(
                method: 'GET',
                url: sprintf(
                    '/campaigns/%s/orders/%s/buyer',
                    $this->getCompany(),
                    $order['orderId'],
                ),
            );

            if($clientRequest->getStatusCode() === 200)
            {
                /** Добавляем информацию о клиенте */
                $clientResponse = $clientRequest->toArray(false);

                if(isset($clientResponse['result']))
                {
                    $client = $clientResponse['result'];
                }
            }
        }

        /** @see https://yandex.ru/dev/market/partner-api/doc/ru/reference/orders/getOrders#orderdto */
        return new NewYaMarketOrderByBusinessDTO(
            order: $order,
            profile: $this->getProfile(),
            token: $this->getTokenIdentifier(),
            buyer: $client,
        );


    }

    /**
     * @deprecated
     * Информация об одном заказе
     *
     * Возвращает информацию о заказе и его отправлениях.
     *
     * @see https://yandex.ru/dev/market/partner-api/doc/ru/reference/orders/getOrder
     *
     */
    public function findOld(int|string $order): NewYaMarketOrderDTO|false
    {
        $order = str_replace('Y-', '', (string) $order);

        $response = $this->TokenHttpClient()
            ->request('GET', sprintf('/campaigns/%s/orders/%s', $this->getCompany(), $order));

        $content = $response->toArray(false);


        if($response->getStatusCode() !== 200)
        {
            foreach($content['errors'] as $error)
            {
                if($error['code'] === 'NOT_FOUND')
                {
                    return false;
                }

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
                    $order['id'],
                ),
            );

            if($clientResponse->getStatusCode() === 200)
            {
                // Добавляем информацию о клиенте
                $client = $clientResponse->toArray(false)['result'];
            }
        }

        /** @see https://yandex.ru/dev/market/partner-api/doc/ru/reference/orders/getOrder#orderdto */
        return new NewYaMarketOrderDTO(
            order: $order,
            profile: $this->getProfile(),
            token: $this->getTokenIdentifier(),
            buyer: $client,
        );
    }
}
