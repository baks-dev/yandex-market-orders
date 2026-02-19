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
 */

declare(strict_types=1);

namespace BaksDev\Yandex\Market\Orders\Messenger\Orders;


use BaksDev\Core\Cache\AppCacheInterface;
use BaksDev\Core\Deduplicator\DeduplicatorInterface;
use BaksDev\Orders\Order\Entity\Event\OrderEvent;
use BaksDev\Orders\Order\Messenger\Sticker\OrderStickerMessage;
use BaksDev\Orders\Order\Repository\CurrentOrderEvent\CurrentOrderEventInterface;
use BaksDev\Yandex\Market\Orders\Type\DeliveryType\TypeDeliveryFbsYaMarket;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(priority: 0)]
final class YandexOrderStickerDispatcher
{
    public function __construct(
        #[Target('yandexMarketOrdersLogger')] private LoggerInterface $Logger,
        private CurrentOrderEventInterface $CurrentOrderEventRepository,
        private AppCacheInterface $Cache,
        private DeduplicatorInterface $Deduplicator,
    ) {}

    public function __invoke(OrderStickerMessage $message): void
    {
        /** Дедубликатор по идентификатору заказа */
        $Deduplicator = $this->Deduplicator
            ->namespace('orders-order')
            ->deduplication([
                (string) $message->getId(),
                self::class,
            ]);

        if($Deduplicator->isExecuted() === true)
        {
            return;
        }


        /**
         * Получаем информацию о заказе
         */

        $OrderEvent = $this->CurrentOrderEventRepository
            ->forOrder($message->getId())
            ->find();

        if(false === ($OrderEvent instanceof OrderEvent))
        {
            $this->Logger->critical(
                message: 'ozon-orders: Не найдено событие OrderEvent',
                context: [self::class.':'.__LINE__, var_export($message, true)],
            );

            return;
        }

        if(false === $OrderEvent->isDeliveryTypeEquals(TypeDeliveryFbsYaMarket::TYPE))
        {
            $Deduplicator->save();
            return;
        }

        /**
         * Получаем стикеры Yandex
         */

        $cache = $this->Cache->init('order-sticker');

        $counter = 1;

        foreach($OrderEvent->getProduct() as $OrderProduct)
        {
            $total = $OrderProduct->getTotal();

            for($i = 1; $i <= $total; $i++)
            {
                $number = str_replace('Y-', '', $OrderEvent->getPostingNumber());
                $yandexSticker = $cache->getItem($number)->get();

                $counter++;

                /** Если стикер не найден - пробуем запросить заново */
                if(empty($yandexSticker))
                {
                    continue;
                }

                $message->addResult(number: $number, code: $yandexSticker);
            }
        }
    }
}
