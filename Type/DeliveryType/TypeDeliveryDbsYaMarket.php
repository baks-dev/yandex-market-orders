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

namespace BaksDev\Yandex\Market\Orders\Type\DeliveryType;

use BaksDev\Delivery\Type\Id\Choice\Collection\TypeDeliveryInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('baks.delivery.type')]
final class TypeDeliveryDbsYaMarket implements TypeDeliveryInterface
{
    /** Доставка клиенту */
    public const TYPE = '5f97cf1c-7278-7c2a-b55f-e87ec660f3a6';

    /** Сортировка */
    public static function priority(): int
    {
        return 801;
    }

    public static function equals(mixed $uid): bool
    {
        return self::TYPE === (string) $uid;
    }

    public function __toString(): string
    {
        return self::TYPE;
    }

    /** Возвращает значение (value) */
    public function getValue(): string
    {
        return self::TYPE;
    }
}
