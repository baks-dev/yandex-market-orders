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

use BaksDev\Core\Cache\AppCacheInterface;
use BaksDev\Yandex\Market\Api\YandexMarket;
use BaksDev\Yandex\Market\Orders\Api\Boxes\BoxesYaMarketProductRequest;
use BaksDev\Yandex\Market\Orders\Schedule\NewOrders\NewOrdersSchedule;
use BaksDev\Yandex\Market\Orders\Schedule\UnpaidOrders\UnpaidOrdersSchedule;
use BaksDev\Yandex\Market\Orders\UseCase\New\NewYaMarketOrderByBusinessDTO;
use BaksDev\Yandex\Market\Orders\UseCase\New\NewYaMarketOrderDTO;
use BaksDev\Yandex\Market\Repository\YaMarketToken\YaMarketTokenInterface;
use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Generator;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;

/**
 * Информация о заказах
 *
 * @note использует тот же запрос:
 * @see GetYaMarketOrdersNewRequest
 * @see GetYaMarketOrdersCancelRequest
 * @see GetYaMarketOrdersCompletedRequest
 * @see GetYaMarketOrdersUnpaidRequest
 */
#[Autoconfigure(shared: false)]
final class GetYaMarketOrdersUnpaidRequest extends YandexMarket
{
    private int $page = 1;

    private ?DateTimeImmutable $fromDate = null;

    public function __construct(
        #[Autowire(env: 'APP_ENV')] string $environment,
        #[Target('yandexMarketLogger')] LoggerInterface $logger,
        YaMarketTokenInterface $YaMarketToken,
        AppCacheInterface $cache,

        private readonly BoxesYaMarketProductRequest $boxesYaMarketProductRequest,
    )
    {
        parent::__construct($environment, $logger, $YaMarketToken, $cache);
    }

    /**
     * Возвращает информацию о заказах:
     *
     * https://yandex.ru/dev/market/partner-api/doc/ru/reference/orders/getBusinessOrders
     *
     * @return Generator<int, NewYaMarketOrderByBusinessDTO>|false
     */
    public function findAllNew(?DateInterval $interval = null): Generator|false
    {
        /** Если не передано время интервала присваиваем  */
        if(false === ($this->fromDate instanceof DateTimeImmutable))
        {
            $this->fromDate = new DateTimeImmutable()
                ->setTimezone(new DateTimeZone('UTC'))
                ->sub($interval ?? DateInterval::createFromDateString(NewOrdersSchedule::INTERVAL))
                ->sub(DateInterval::createFromDateString('14 days'));
        }


        /** Заказы */
        $orders = [];

        /**
         * Идентификатор страницы
         * Передавая значение, полученное при последнем запросе - получаем следующую страницу
         */
        $pageToken = '';

        while(true)
        {
            $response = $this->TokenHttpClient()
                ->request(
                    'POST',
                    sprintf('/v1/businesses/%s/orders', $this->getBusiness()),
                    [
                        'query' =>
                            [
                                'limit' => 1,
                                'pageToken' => $pageToken,
                            ],
                        'json' => [
                            "campaignIds" => [
                                $this->getCompany()
                            ],
                            'statuses' => [
                                'UNPAID',
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

            $orders = array_merge($orders, $content['orders']);

            /**
             * Прерываем цикл по условиям:
             * - нет ключа для хранения информации о следующей страницы
             * - нет ключа для хранения токена следующей страницы
             * - нет токена следующей страницы
             */
            if(
                false === isset($content['paging'])
                || true === empty($content['paging'])
                || false === isset($content['paging']['nextPageToken'])
            )
            {
                break;
            }

            /** Сохраняем токен следующей страницы */
            $pageToken = $content['paging']['nextPageToken'];
        }

        if(true === empty($orders))
        {
            return false;
        }

        foreach($orders as $order)
        {
            /** @note выполняется в тестовой среде */
            if(false === $this->isExecuteEnvironment())
            {
                /** @see https://yandex.ru/dev/market/partner-api/doc/ru/reference/orders/getOrders#orderdto */
                yield new NewYaMarketOrderByBusinessDTO(
                    order: $order,
                    profile: $this->getProfile(),
                    token: $this->getTokenIdentifier(),
                );

                continue;
            }

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

                if($response->getStatusCode() === 200)
                {
                    /** Добавляем информацию о клиенте */
                    $clientResponse = $clientRequest->toArray(false);

                    if(isset($clientResponse['result']))
                    {
                        $client = $clientResponse['result'];
                    }
                }
            }

            $delivery = $order['delivery'];

            /**
             * Если заказ FBS
             */

            if(isset($delivery['deliveryPartnerType']) && $delivery['deliveryPartnerType'] === 'YANDEX_MARKET')
            {

                /** Получаем сумму всех товаров в заказе */
                $totalItems = array_sum(array_column($order['items'], 'count'));

                /**
                 * Получаем количество отправлений в заказе
                 *
                 * https://yandex.ru/dev/market/partner-api/doc/ru/reference/orders/getBusinessOrders#entity-BusinessOrderBoxLayoutDTO
                 */
                $totalBoxes = empty($delivery['boxesLayout']) ? 0 : count($delivery['boxesLayout']);

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

                    $responseBoxes = $this->boxesYaMarketProductRequest
                        ->products($products)
                        ->update((string) $order['orderId']);

                    if(false === $responseBoxes)
                    {
                        continue;
                    }

                    $this->logger->info(
                        message: sprintf('%s: Разделили заказ на машиноместа', $order['orderId']),
                        context: [$products, self::class.':'.__LINE__],
                    );

                    continue;
                }

                /** Создаем массив из отправлений */

                $boxes = null;

                foreach($delivery['boxesLayout'] as $box)
                {
                    $boxes[] = $box['barcode'];
                }

                /** Создаем на каждый продукт новый заказ с отправлением */

                $fbsOrder = $order;

                foreach($order['items'] as $item)
                {
                    for($i = 0; $i < $item['count']; $i++)
                    {
                        /** Получаем одно отправление и переводим указатель на следующий */
                        $fbsOrder['posting'] = current($boxes);
                        next($boxes);

                        $fbsOrder['items'] = null;
                        $fbsOrder['items'][0] = $item;
                        $fbsOrder['items'][0]['count'] = 1;

                        /** Сумма платежа покупателя */
                        $payment = ($item['prices']['payment']['value'] * 100) / $item['count'];
                        $fbsOrder['items'][0]['prices']['payment']['value'] = $payment / 100;

                        /** Общая сумма вознаграждений продавцу*/
                        if(true === isset($item['prices']['subsidy']))
                        {
                            $subsidy = ($item['prices']['subsidy']['value'] * 100) / $item['count'];
                            $fbsOrder['items'][0]['prices']['subsidy']['value'] = $subsidy / 100;
                        }

                        /** Сумма, которая оплачена баллами Плюса */
                        if(true === isset($item['prices']['cashback']))
                        {
                            $cashback = ($item['prices']['cashback']['value'] * 100) / $item['count'];
                            $fbsOrder['items'][0]['prices']['cashback']['value'] = $cashback / 100;
                        }

                        yield new NewYaMarketOrderByBusinessDTO(
                            order: $fbsOrder,
                            profile: $this->getProfile(),
                            token: $this->getTokenIdentifier(),
                            buyer: $client,
                        );
                    }
                }

                continue;
            }

            /** @see https://yandex.ru/dev/market/partner-api/doc/ru/reference/orders/getBusinessOrders#entity-BusinessOrderDTO */
            yield new NewYaMarketOrderByBusinessDTO(
                order: $order,
                profile: $this->getProfile(),
                token: $this->getTokenIdentifier(),
                buyer: $client,
            );
        }
    }

    /**
     * @deprecated
     *
     * Возвращает информацию о 50 последних заказах со статусом:
     *
     * UNPAID - заказ оформлен, но еще не оплачен (если выбрана оплата при оформлении).
     *
     * Лимит: 1 000 000 запросов в час (~16666 в минуту | ~277 в секунду)
     *
     * @see https://yandex.ru/dev/market/partner-api/doc/ru/reference/orders/getOrders
     *
     */
    public function findAllOld(?DateInterval $interval = null): Generator|false
    {
        /** Если не передано время интервала присваиваем  */
        if(false === ($this->fromDate instanceof DateTimeImmutable))
        {
            $this->fromDate = new DateTimeImmutable()
                ->setTimezone(new DateTimeZone('UTC'))
                ->sub($interval ?? DateInterval::createFromDateString(UnpaidOrdersSchedule::INTERVAL))
                ->sub(DateInterval::createFromDateString('14 days'));
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
