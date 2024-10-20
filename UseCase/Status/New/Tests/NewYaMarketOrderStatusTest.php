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

namespace BaksDev\Yandex\Market\Orders\UseCase\Status\New\Tests;

use BaksDev\Core\Cache\AppCacheInterface;
use BaksDev\Orders\Order\Entity\Order;
use BaksDev\Orders\Order\Repository\CurrentOrderEvent\CurrentOrderEventInterface;
use BaksDev\Orders\Order\Type\Id\OrderUid;
use BaksDev\Orders\Order\Type\Status\OrderStatus\OrderStatusNew;
use BaksDev\Orders\Order\Type\Status\OrderStatus\OrderStatusUnpaid;
use BaksDev\Orders\Order\UseCase\Admin\Status\OrderStatusHandler;
use BaksDev\Yandex\Market\Orders\UseCase\Status\New\ToggleUnpaidToNewYaMarketOrderDTO;
use BaksDev\Yandex\Market\Orders\UseCase\Unpaid\Tests\UnpaidYaMarketOrderHandlerTest;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * @group yandex-market-orders
 * @group yandex-market-orders-status
 *
 * @depends BaksDev\Yandex\Market\Orders\UseCase\Unpaid\Tests\UnpaidYaMarketOrderHandlerTest::class
 *
 */
#[When(env: 'test')]
class NewYaMarketOrderStatusTest extends KernelTestCase
{

    public function testUseCase(): void
    {

        self::assertTrue(true);

        /** Кешируем на сутки результат теста */

        /** @var AppCacheInterface $AppCache */
        $AppCache = self::getContainer()->get(AppCacheInterface::class);
        $cache = $AppCache->init('yandex-market-orders-test');
        $item = $cache->getItem('UnpaidYaMarketOrderHandlerTest');

        if($item->isHit())
        {
            //return;
        }

        /** @var CurrentOrderEventInterface $CurrentOrderEventInterface */

        $CurrentOrderEventInterface = self::getContainer()->get(CurrentOrderEventInterface::class);

        $OrderEvent = $CurrentOrderEventInterface
            ->forOrder(OrderUid::TEST)
            ->execute();

        if($OrderEvent === false)
        {
            return;
        }

        /** Тест UnpaidYaMarketOrderHandlerTest переводит заказа в статус NEW */
        self::assertTrue($OrderEvent->isStatusEquals(OrderStatusNew::class));

        self::assertNotNull($OrderEvent);
        self::assertNotFalse($OrderEvent);

        $NewYaMarketOrderStatusDTO = new ToggleUnpaidToNewYaMarketOrderDTO();
        $OrderEvent->getDto($NewYaMarketOrderStatusDTO);

        self::assertNotNull($NewYaMarketOrderStatusDTO->getEvent());

        $NewYaMarketOrderStatusDTO->setOrderStatusNew();
        self::assertTrue($NewYaMarketOrderStatusDTO->getStatus()->equals(OrderStatusNew::class));

        /** @var OrderStatusHandler $OrderStatusHandler */
        $OrderStatusHandler = self::getContainer()->get(OrderStatusHandler::class);
        $handle = $OrderStatusHandler->handle($NewYaMarketOrderStatusDTO, false);

        self::assertTrue(($handle instanceof Order), $handle.': Ошибка YandexMarketOrder');

    }

}
