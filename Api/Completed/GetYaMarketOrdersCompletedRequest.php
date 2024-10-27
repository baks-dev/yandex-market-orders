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

namespace BaksDev\Yandex\Market\Orders\Api\Completed;

use BaksDev\Yandex\Market\Api\YandexMarket;
use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use Generator;

/**
 * Информация о заказах
 */
final class GetYaMarketOrdersCompletedRequest extends YandexMarket
{
    private int $page = 1;

    private ?DateTimeImmutable $fromDate = null;

    /**
     * Возвращает информацию о 50 последних заказах со статусом:
     *
     * DELIVERY — заказ передан в службу доставки.
     *
     * Лимит: 1 000 000 запросов в час (~16666 в минуту | ~277 в секунду)
     *
     * @see https://yandex.ru/dev/market/partner-api/doc/ru/reference/orders/getOrders
     *
     */
    public function findAll(?DateInterval $interval = null): Generator
    {
        if(!$this->fromDate)
        {
            $dateTime = new DateTimeImmutable();
            $this->fromDate = $dateTime->sub($interval ?? DateInterval::createFromDateString('30 minutes'));
        }

        $response = $this->TokenHttpClient()
            ->request(
                'GET',
                sprintf('/campaigns/%s/orders', $this->getCompany()),
                ['query' =>
                    [
                        'page' => $this->page,
                        'pageSize' => 50,
                        'status' => 'DELIVERY',
                        'updatedAtFrom' => $this->fromDate->format(DateTimeInterface::W3C)
                    ]
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
            /** Пропускаем, если доставка не FBS (Курьером YANDEX) */
            if($order['delivery']["deliveryPartnerType"] !== 'YANDEX_MARKET')
            {
                continue;
            }

            yield new YaMarketCompletedOrderDTO($order['id']);
        }
    }
}
