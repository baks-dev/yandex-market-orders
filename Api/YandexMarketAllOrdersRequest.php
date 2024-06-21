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

use BaksDev\Delivery\Type\Field\DeliveryFieldUid;
use BaksDev\Yandex\Market\Api\YandexMarket;
use BaksDev\Yandex\Market\Orders\UseCase\New\YandexMarketOrderDTO;

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

            /** TODO */

            /*$response = $this->TokenHttpClient()
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
            }*/


            $content = $this->debug();

            $this->page = $content['pager']['currentPage'] + 1;

            foreach($content['orders'] as $order)
            {
                /** Доставка YANDEX MARKET */
                if($order['delivery']['deliveryPartnerType'] === 'YANDEX_MARKET')
                {
                    /** @see https://yandex.ru/dev/market/partner-api/doc/ru/reference/orders/getOrders#orderdto */
                    yield new YandexMarketOrderDTO($order);
                }
                else
                {
                    dd('Доставка '.$order['delivery']['deliveryPartnerType']);
                }

            }

            if($this->page === $content['pager']['pagesCount'] || $content['pager']['total'] < 50)
            {
                break;
            }
        }
    }


    public function debug(): array
    {
        $json = '{"pager":{"total":1,"from":1,"to":1,"currentPage":1,"pagesCount":1,"pageSize":50},"orders":[{"id":457047911,"status":"PROCESSING","substatus":"STARTED","creationDate":"12-05-2024 23:07:08","currency":"RUR","itemsTotal":20320.0,"deliveryTotal":0.0,"buyerItemsTotal":20320.0,"buyerTotal":20320.0,"buyerItemsTotalBeforeDiscount":20320.0,"buyerTotalBeforeDiscount":20320.0,"paymentType":"PREPAID","paymentMethod":"YANDEX","fake":false,"items":[{"id":598132841,"offerId":"TH202-20-245-45-103Y","offerName":"Triangle EffeXSport TH202 245/45 R20 103Y","price":10160.0,"buyerPrice":10160.0,"buyerPriceBeforeDiscount":10160.0,"priceBeforeDiscount":10160.0,"count":2,"vat":"VAT_20_120","shopSku":"TH202-20-245-45-103Y","subsidy":0.0,"partnerWarehouseId":"a5f35434-7eab-4806-b5cb-0e66d6ca88f6","requiredInstanceTypes":["CIS"]}],"delivery":{"type":"DELIVERY","serviceName":"Доставка","price":0.0,"deliveryPartnerType":"YANDEX_MARKET","dates":{"fromDate":"18-05-2024","toDate":"18-05-2024","fromTime":"10:00:00","toTime":"23:00:00"},"region":{"id":239,"name":"Сочи","type":"CITY","parent":{"id":116900,"name":"Городской округ Сочи","type":"REPUBLIC_AREA","parent":{"id":10995,"name":"Краснодарский край","type":"REPUBLIC","parent":{"id":26,"name":"Южный федеральный округ","type":"COUNTRY_DISTRICT","parent":{"id":225,"name":"Россия","type":"COUNTRY"}}}}},"address":{"country":"Россия","city":"село Раздольное","street":"Тепличная улица","house":"79","entrance":"1","entryphone":"42","floor":"7","apartment":"42","gps":{"latitude":43.609622,"longitude":39.764142}},"deliveryServiceId":1006305,"liftPrice":0.0,"outletCode":"30437","shipments":[{"id":451737186,"shipmentDate":"14-05-2024","shipmentTime":"15:30","boxes":[{"id":570226951,"fulfilmentId":"457047911-1"}]}]},"buyer":{"type":"PERSON"},"taxSystem":"OSN","cancelRequested":false}]}';

        return json_decode($json, true);

    }
}
