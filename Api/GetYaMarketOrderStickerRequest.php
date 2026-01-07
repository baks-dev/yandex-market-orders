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

namespace BaksDev\Yandex\Market\Orders\Api;

use BaksDev\Yandex\Market\Api\YandexMarket;
use DateInterval;
use Imagick;
use Symfony\Contracts\Cache\ItemInterface;

final class GetYaMarketOrderStickerRequest extends YandexMarket
{
    private string $number;

    private int $box;

    private int $key;

    /**Передаем идентификатор отправления для кеширования */
    public function number(string $number): self
    {
        $this->number = str_replace('Y-', '', $number);
        return $this;
    }

    public function box(int $box): self
    {
        $this->box = $box;
        return $this;
    }

    public function key(int $key): self
    {
        $this->key = $key;
        return $this;
    }

    /**
     * Готовый ярлык‑наклейка для коробки в заказе
     *
     * @see https://yandex.ru/dev/market/partner-api/doc/ru/reference/orders/generateOrderLabel
     */
    public function get(): bool
    {
        /**
         * Обрабатываем и сохраняем в кеш этикетку под номер отправления
         * Указываем отличающийся namespace для кеша стикера (не сбрасываем по какому-либо модулю)
         */
        $cache = $this->getCacheInit('order-sticker');
        $key = $this->number.'-'.$this->key;

        $sticker = $cache->get($key, function(ItemInterface $item): string|false {

            /** Временно кешируем этикетку на 1 секунду */
            $item->expiresAfter(DateInterval::createFromDateString('1 seconds'));

            $response = $this->TokenHttpClient()
                ->request(
                    'GET',
                    sprintf(
                        '/v2/campaigns/%s/orders/%s/delivery/shipments/%s/boxes/%s/label',
                        $this->getCompany(),
                        $this->number,
                        $this->key,
                        $this->box,
                    ),
                    ['query' => ['format' => 'A7']],
                );

            if($response->getStatusCode() !== 200)
            {
                return false;
            }

            $sticker = $response->getContent(false);

            /** Кешируем этикетку на 1 неделю */
            $item->expiresAfter(DateInterval::createFromDateString('1 week'));

            Imagick::setResourceLimit(Imagick::RESOURCETYPE_TIME, 3600);
            Imagick::setResourceLimit(Imagick::RESOURCETYPE_MEMORY, (1024 * 1024 * 256));

            $imagick = new Imagick();
            $imagick->setResolution(400, 400); // DPI

            /** Одна страница, если передан один номер отправления */
            $imagick->readImageBlob($sticker.'[0]'); // [0] — первая страница

            $imagick->setImageFormat('png');
            $imageBlob = $imagick->getImageBlob();

            $imagick->clear();

            return base64_encode($imageBlob);

        });

        return $sticker !== false;
    }
}