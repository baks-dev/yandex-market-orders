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

final readonly class YaMarketCancelOrderDTO
{
    /** Идентификатор заказа YandexMarket */
    private string $number;

    public string $comment;

    public function __construct(string|int $number, string $substatus)
    {
        $this->number = 'Y-'.$number; // помечаем заказ префиксом Y

        $this->comment = 'Yandex Seller: '.match ($substatus)
            {
                //** статусы, перечисленные в новом запросе */
                'RESERVATION_EXPIRED' => 'покупатель не завершил оформление зарезервированного заказа в течение 10 минут',
                'USER_NOT_PAID' => 'покупатель не оплатил заказ (для типа оплаты PREPAID) в течение 30 минут',
                'USER_UNREACHABLE' => 'не удалось связаться с покупателем',
                'USER_CHANGED_MIND' => 'покупатель отменил заказ по личным причинам',
                'USER_REFUSED_DELIVERY' => 'покупателя не устроили условия доставки',
                'USER_REFUSED_PRODUCT' => 'покупателю не подошел товар',
                'SHOP_FAILED' => 'магазин не может выполнить заказ',
                'USER_REFUSED_QUALITY' => 'покупателя не устроило качество товара',
                'REPLACING_ORDER' => 'покупатель решил заменить товар другим по собственной инициативе',
                'PROCESSING_EXPIRED' => 'значение более не используется',
                'PICKUP_EXPIRED' => 'закончился срок хранения заказа в пункт выдачи',
                'TOO_MANY_DELIVERY_DATE_CHANGES' => 'заказ переносили слишком много раз',
                'TOO_LONG_DELIVERY' => 'заказ доставляется слишком долго',
                'INCORRECT_PERSONAL_DATA' => 'для заказа из-за рубежа указаны неправильные данные получателя, заказ не пройдет проверку на таможне',

                //** статусы, НЕ перечисленные в новом запросе */
                'DELIVERY_SERVICE_UNDELIVERED' => 'Служба доставки не смогла доставить заказ',
                'RESERVATION_FAILED' => 'Маркет не может продолжить дальнейшую обработку заказа',
                'USER_WANTS_TO_CHANGE_DELIVERY_DATE' => 'Покупатель хочет получить заказ в другой день',
                'CANCELLED_COURIER_NOT_FOUND' => 'Не удалось найти курьера',
                'FULL_NOT_RANSOM' => 'Заказ не выкуплен клиентом',
                'USER_BOUGHT_CHEAPER' => 'Покупатель нашел дешевле',
                'USER_WANTED_ANOTHER_PAYMENT_METHOD' => 'Покупатель выбрал другой способ оплаты',
                'USER_WANTS_TO_CHANGE_ADDRESS' => 'Пользователь пожелал изменить адрес доставки',
                'USER_FORGOT_TO_USE_BONUS' => 'Пользователь забыл использовать бонус',
                'USER_HAS_NO_TIME_TO_PICKUP_ORDER' => 'Не удалось доставить заказ',

                default => sprintf('неизвестная причина (%s)', $substatus),
            };
    }

    /**
     * Number
     */
    public function getOrderNumber(): string
    {
        return $this->number;
    }

    /**
     * Comment
     */
    public function getComment(): string
    {
        return $this->comment;
    }
}
