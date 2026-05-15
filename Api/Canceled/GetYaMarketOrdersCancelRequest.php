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

namespace BaksDev\Yandex\Market\Orders\Api\Canceled;

use BaksDev\Yandex\Market\Api\YandexMarket;
use BaksDev\Yandex\Market\Orders\Schedule\CancelOrders\CancelOrdersSchedule;
use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Generator;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

/**
 * Информация о заказах
 *
 * @note использует тот же запрос:
 * @see GetYaMarketOrdersNewRequest
 * @see GetYaMarketOrdersCancelRequest
 * @see GetYaMarketOrdersUnpaidRequest
 * @see GetYaMarketOrderInfoRequest
 * @see GetYaMarketOrdersCompletedRequest
 */
#[Autoconfigure(shared: false)]
final class GetYaMarketOrdersCancelRequest extends YandexMarket
{
    private int $page = 1;

    private ?DateTimeImmutable $fromDate = null;

    /**
     * Возвращает информацию о заказах:
     *
     * https://yandex.ru/dev/market/partner-api/doc/ru/reference/orders/getBusinessOrders
     *
     * @return Generator<int, YaMarketCancelOrderDTO>|false
     *
     */
    public function findAllNew(?DateInterval $interval = null): Generator|false
    {
        /** Если не передано время интервала присваиваем  */
        if(false === ($this->fromDate instanceof DateTimeImmutable))
        {
            $this->fromDate = new DateTimeImmutable()
                ->setTimezone(new DateTimeZone('UTC'))
                ->sub($interval ?? DateInterval::createFromDateString(CancelOrdersSchedule::INTERVAL))
                ->sub(DateInterval::createFromDateString('1 day'));
        }

        /** Лимит для прерывания бесконечного цикла */
        $limit = 0;

        /**
         * Идентификатор страницы
         * Передавая значение, полученное при последнем запросе - получаем следующую страницу
         */
        $pageToken = '';

        while(true)
        {
            ++$limit;

            $response = $this->TokenHttpClient()
                ->request(
                    method: 'POST',
                    url: sprintf('/v1/businesses/%s/orders', $this->getBusiness()),
                    options: [
                        'query' =>
                            [
                                'limit' => 50,
                                'pageToken' => $pageToken,
                            ],
                        'json' => [
                            "campaignIds" => [
                                $this->getCompany()
                            ],
                            'statuses' => [
                                'CANCELLED',
                            ],
                            'dates' => [
                                'creationDateFrom' => $this->fromDate->format('Y-m-d'),
                            ],
                            'fake' => false,
                        ],
                    ],
                );

            $content = $response->toArray(false);

            /** Если ошибка получения заказов - прерываем цикл */
            if($response->getStatusCode() !== 200)
            {
                foreach($content['errors'] as $error)
                {
                    $this->logger->critical(
                        message: $error['code'].': '.$error['message'],
                        context: [self::class.':'.__LINE__]
                    );
                }

                break;
            }

            /** Если нет заказов в ответе - прерываем цикл */
            if(true === empty($content['orders']))
            {
                break;
            }

            foreach($content['orders'] as $order)
            {
                yield new YaMarketCancelOrderDTO($order['orderId'], $order['substatus']);
            }

            /**
             * По условиям прерываем цикл:
             * - нет ключа для хранения информации о следующей страницы
             * - нет ключа для хранения токена следующей страницы
             * - нет токена следующей страницы
             * - превышен установленный нами лимит
             */
            if(
                false === isset($content['paging'])
                || true === empty($content['paging'])
                || false === isset($content['paging']['nextPageToken'])
                || $limit === 100
            )
            {
                break;
            }

            /** Сохраняем токен следующей страницы */
            $pageToken = $content['paging']['nextPageToken'];
        }
    }

    /**
     * @return Generator<YaMarketCancelOrderDTO>|false
     *
     * @see https://yandex.ru/dev/market/partner-api/doc/ru/reference/orders/getOrders
     *
     * @deprecated
     *
     * Возвращает информацию о 50 последних заказах со статусом:
     *
     * CANCELLED - заказ отменен.
     *
     * Лимит: 1 000 000 запросов в час (~16666 в минуту | ~277 в секунду)
     *
     */
    public function findAllOld(?DateInterval $interval = null): Generator|false
    {
        /** Если не передано время интервала присваиваем  */
        if(false === ($this->fromDate instanceof DateTimeImmutable))
        {
            $this->fromDate = new DateTimeImmutable()
                ->setTimezone(new DateTimeZone('UTC'))
                ->sub($interval ?? DateInterval::createFromDateString(CancelOrdersSchedule::INTERVAL))
                ->sub(DateInterval::createFromDateString('1 day'));
        }

        $response = $this->TokenHttpClient()
            ->request(
                'GET',
                sprintf('/campaigns/%s/orders', $this->getCompany()),
                ['query' =>
                    [
                        'page' => $this->page,
                        'pageSize' => 50,
                        'status' => 'CANCELLED',
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

        if(empty($content['orders']))
        {
            return false;
        }

        foreach($content['orders'] as $order)
        {
            yield new YaMarketCancelOrderDTO($order['id'], $order['substatus']);
        }
    }
}
