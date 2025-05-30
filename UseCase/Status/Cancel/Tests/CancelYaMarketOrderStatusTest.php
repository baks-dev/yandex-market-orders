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

namespace BaksDev\Yandex\Market\Orders\UseCase\Status\Cancel\Tests;

use BaksDev\Core\Cache\AppCacheInterface;
use BaksDev\Orders\Order\Entity\Event\OrderEvent;
use BaksDev\Orders\Order\Entity\Order;
use BaksDev\Orders\Order\Repository\CurrentOrderEvent\CurrentOrderEventInterface;
use BaksDev\Orders\Order\Type\Id\OrderUid;
use BaksDev\Orders\Order\Type\Status\OrderStatus\Collection\OrderStatusCanceled;
use BaksDev\Orders\Order\UseCase\Admin\Edit\Tests\OrderNewTest;
use BaksDev\Orders\Order\UseCase\Admin\Status\OrderStatusHandler;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
use BaksDev\Users\Profile\UserProfile\UseCase\Admin\NewEdit\Tests\NewUserProfileHandlerTest;
use BaksDev\Yandex\Market\Orders\UseCase\Status\Cancel\CancelYaMarketOrderStatusDTO;
use BaksDev\Yandex\Market\Orders\UseCase\Status\New\Tests\NewYaMarketOrderStatusTest;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * @group yandex-market-orders
 * @group yandex-market-orders-status
 *
 * @depends BaksDev\Yandex\Market\Orders\UseCase\Status\New\Tests\NewYaMarketOrderStatusTest::class
 */
#[When(env: 'test')]
class CancelYaMarketOrderStatusTest extends KernelTestCase
{
    public function testUseCase(): void
    {
        self::assertTrue(true);

        /** Проверяем результат тестирования UnpaidYaMarketOrderHandlerTest */

        /** @var AppCacheInterface $AppCache */
        $AppCache = self::getContainer()->get(AppCacheInterface::class);
        $cache = $AppCache->init('yandex-market-orders-test');
        $item = $cache->getItem('UnpaidYaMarketOrderHandlerTest');

        if($item->isHit())
        {
            return;
        }


        /** @var CurrentOrderEventInterface $CurrentOrderEventInterface */

        $CurrentOrderEventInterface = self::getContainer()->get(CurrentOrderEventInterface::class);

        $OrderEvent = $CurrentOrderEventInterface
            ->forOrder(OrderUid::TEST)
            ->find();

        if($OrderEvent === false)
        {
            return;
        }

        $CancelYaMarketOrderStatusDTO = new CancelYaMarketOrderStatusDTO(
            new UserProfileUid(UserProfileUid::TEST)
        );
        $OrderEvent->getDto($CancelYaMarketOrderStatusDTO);

        self::assertNotNull($CancelYaMarketOrderStatusDTO->getEvent());
        self::assertTrue($CancelYaMarketOrderStatusDTO->getStatus()->equals(OrderStatusCanceled::class));
        self::assertNotNull($CancelYaMarketOrderStatusDTO->getProfile());
        self::assertTrue($CancelYaMarketOrderStatusDTO->getProfile()->equals(UserProfileUid::TEST));

        $CancelOrderInvariable = $CancelYaMarketOrderStatusDTO->getInvariable();
        self::assertNotNull($CancelOrderInvariable->getProfile());

        $CancelYaMarketOrderStatusDTO->setComment('Отмена');
        self::assertEquals('Отмена', $CancelYaMarketOrderStatusDTO->getComment());

        /** @var OrderStatusHandler $OrderStatusHandler */
        $OrderStatusHandler = self::getContainer()->get(OrderStatusHandler::class);
        $handle = $OrderStatusHandler->handle($CancelYaMarketOrderStatusDTO);

        self::assertTrue(($handle instanceof Order), $handle.': Ошибка YandexMarketOrder');

        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $events = $em->getRepository(OrderEvent::class)
            ->findBy(['orders' => OrderUid::TEST]);

        self::assertCount(4, $events);

    }

    public static function tearDownAfterClass(): void
    {
        OrderNewTest::setUpBeforeClass();
        NewUserProfileHandlerTest::setUpBeforeClass();
    }

}
