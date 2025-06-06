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

/**
 * Информация о заказах
 */
final class UpdateYaMarketOrderReadyStatusRequest extends YandexMarket
{
    /**
     * Изменение статуса одного заказа «Принят в обработку»
     *
     * Лимит: 1 000 000 запросов в час (~16666 в минуту | ~277 в секунду)
     *
     * @see https://yandex.ru/dev/market/partner-api/doc/ru/reference/orders/updateOrderStatus
     *
     */
    public function update(int|string $order): bool
    {
        if(false === $this->isExecuteEnvironment())
        {
            return true;
        }

        $order = str_replace('Y-', '', (string) $order);

        $response = $this->TokenHttpClient()
            ->request(
                'PUT',
                sprintf('/campaigns/%s/orders/%s/status', $this->getCompany(), $order),
                ['json' =>
                    [
                        'order' => [
                            "status" => "PROCESSING",
                            "substatus" => "READY_TO_SHIP",
                        ]
                    ]
                ],
            );

        $content = $response->toArray(false);

        if($response->getStatusCode() !== 200)
        {
            foreach($content['errors'] as $error)
            {
                $this->logger->critical($error['code'].': '.$error['message'], ['order' => $order, self::class.':'.__LINE__]);
            }

            return false;
        }

        return true;
    }
}
