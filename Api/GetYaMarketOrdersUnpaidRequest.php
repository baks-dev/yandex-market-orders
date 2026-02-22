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
use BaksDev\Yandex\Market\Orders\Schedule\UnpaidOrders\UnpaidOrdersSchedule;
use BaksDev\Yandex\Market\Orders\UseCase\New\NewYaMarketOrderDTO;
use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Generator;

/**
 * Информация о заказах
 */
final class GetYaMarketOrdersUnpaidRequest extends YandexMarket
{
    private int $page = 1;

    private ?DateTimeImmutable $fromDate = null;

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
    public function findAll(?DateInterval $interval = null): Generator|false
    {
        /** Если не передано время интервала присваиваем  */
        if(false === ($this->fromDate instanceof DateTimeImmutable))
        {
            $this->fromDate = new DateTimeImmutable()
                ->setTimezone(new DateTimeZone('UTC'))
                ->sub($interval ?? DateInterval::createFromDateString(UnpaidOrdersSchedule::INTERVAL))
                ->sub(DateInterval::createFromDateString('1 hour'));
        }

        $response = $this->TokenHttpClient()
            ->request(
                'GET',
                sprintf('/campaigns/%s/orders', $this->getCompany()),
                ['query' =>
                    [
                        'page' => $this->page,
                        'pageSize' => 50,
                        'status' => 'UNPAID',
                        'updatedAtFrom' => $this->fromDate->format(DateTimeInterface::ATOM),
                    ],
                ],
            );

        $content = $response->toArray(false);


        if($response->getStatusCode() !== 200)
        {
            foreach($content['errors'] as $error)
            {
                $this->logger->critical($error['code'].': '.$error['message'], [self::class.':'.__LINE__]);
            }

            return false;
        }

        foreach($content['orders'] as $order)
        {
            if(false === $this->isExecuteEnvironment())
            {
                /** @see https://yandex.ru/dev/market/partner-api/doc/ru/reference/orders/getOrders#orderdto */
                yield new NewYaMarketOrderDTO(
                    order: $order,
                    profile: $this->getProfile(),
                    token: $this->getTokenIdentifier(),
                );
            }

            /**
             * Получаем информацию о клиенте
             */

            $client = null;

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

                if($response->getStatusCode() === 200)
                {
                    // Добавляем информацию о клиенте
                    $client = $clientResponse->toArray(false)['result'];
                }
            }


            /**
             * Если заказ FBS
             */

            if(
                isset($order['delivery']['deliveryPartnerType'])
                && $order['delivery']['deliveryPartnerType'] === 'YANDEX_MARKET'
            )
            {

                // получаем количество товаров в заказе
                $totalItems = array_sum(array_column($order['items'], 'count'));

                // получаем количество отправлений в заказе
                $totalBoxes = isset($order['delivery']['shipments'])
                    ? array_sum(array_map(static function($item) {
                        return isset($item['boxes']) ? count($item['boxes']) : 0;
                    }, $order['delivery']['shipments']))
                    : 0;


                /**
                 * Если заказ не разделен - отправляем уведомление на разделение
                 */

                if($totalItems !== $totalBoxes)
                {
                    $products = [];

                    foreach($order['items'] as $product)
                    {
                        for($i = 1; $i <= $product['count']; $i++)
                        {
                            $products[] = [
                                'items' => [
                                    [
                                        'id' => $product['id'], // идентификатор продукта
                                        'fullCount' => 1,
                                    ],
                                ],
                            ];
                        }
                    }

                    $responseBoxes = $this->TokenHttpClient()
                        ->request(
                            'PUT',
                            sprintf('/campaigns/%s/orders/%s/boxes', $this->getCompany(), $order['id']),
                            ['json' => ['boxes' => $products]],
                        );

                    if($responseBoxes->getStatusCode() !== 200)
                    {

                        $contentBoxes = $responseBoxes->toArray(false);

                        $this->logger->critical(
                            sprintf('yandex-market-orders: Ошибка %s при разделении упаковки заказа %s', $response->getStatusCode(), $order['id']),
                            [$contentBoxes, $products, self::class.':'.__LINE__]);

                        continue;
                    }

                    $this->logger->info(
                        sprintf('%s: Разделили заказ на машиноместа', $order['id']),
                        [$products, self::class.':'.__LINE__],
                    );

                    continue;
                }


                /**
                 * Если заказ разделен - создаем отправления
                 */

                $fbsOrder = $order;

                foreach($order['delivery']['shipments'] as $key => $shipment)
                {
                    foreach($shipment['boxes'] as $box)
                    {
                        /** Создаем заказ на единицу продукции */
                        $fbsOrder['posting'] = $box['fulfilmentId'];

                        $fbsOrder['items'] = null;
                        $fbsOrder['items'][0] = $order['items'][$key];
                        $fbsOrder['items'][0]['count'] = 1;

                        yield new NewYaMarketOrderDTO(
                            order: $fbsOrder,
                            profile: $this->getProfile(),
                            token: $this->getTokenIdentifier(),
                            buyer: $client,
                        );
                    }
                }

                continue;
            }

            /**
             * Все остальные заказы создаем как есть
             */

            /** @see https://yandex.ru/dev/market/partner-api/doc/ru/reference/orders/getOrders#orderdto */
            yield new NewYaMarketOrderDTO(
                order: $order,
                profile: $this->getProfile(),
                token: $this->getTokenIdentifier(),
                buyer: $client,
            );
        }
    }
}
