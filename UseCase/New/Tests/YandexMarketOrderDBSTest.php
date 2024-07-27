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

namespace BaksDev\Yandex\Market\Orders\UseCase\New\Tests;

use BaksDev\Core\Doctrine\DBALQueryBuilder;
use BaksDev\Orders\Order\Entity\Event\OrderEvent;
use BaksDev\Orders\Order\Entity\Order;
use BaksDev\Orders\Order\Type\Event\OrderEventUid;
use BaksDev\Orders\Order\Type\Id\OrderUid;
use BaksDev\Orders\Order\Type\Status\OrderStatus\Collection\OrderStatusCollection;
use BaksDev\Users\Profile\UserProfile\Type\Event\UserProfileEventUid;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
use BaksDev\Yandex\Market\Orders\Api\YandexMarketNewOrdersRequest;
use BaksDev\Yandex\Market\Orders\Type\DeliveryType\TypeDeliveryDbsYaMarket;
use BaksDev\Yandex\Market\Orders\Type\DeliveryType\TypeDeliveryYandexMarket;
use BaksDev\Yandex\Market\Orders\Type\PaymentType\TypePaymentDbsYaMarket;
use BaksDev\Yandex\Market\Orders\Type\PaymentType\TypePaymentYandex;
use BaksDev\Yandex\Market\Orders\Type\ProfileType\TypeProfileDbsYaMarket;
use BaksDev\Yandex\Market\Orders\Type\ProfileType\TypeProfileYandexMarket;
use BaksDev\Yandex\Market\Orders\UseCase\New\User\OrderUserDTO;
use BaksDev\Yandex\Market\Orders\UseCase\New\YandexMarketOrderDTO;
use BaksDev\Yandex\Market\Orders\UseCase\New\YandexMarketOrderHandler;
use BaksDev\Yandex\Market\Type\Authorization\YaMarketAuthorizationToken;
use DateInterval;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * @group yandex-market-orders
 * @group yandex-market-orders-dbs
 */
#[When(env: 'test')]
class YandexMarketOrderDBSTest extends KernelTestCase
{
    private static YaMarketAuthorizationToken $Authorization;

    public static function setUpBeforeClass(): void
    {
        /** Токен авторизации заказов FBS - доставка курьером Яндекс Маркет */
        self::$Authorization = new YaMarketAuthorizationToken(
            new UserProfileUid(),
            $_SERVER['TEST_YANDEX_MARKET_TOKEN_DBS'],
            $_SERVER['TEST_YANDEX_MARKET_COMPANY_DBS'],
            $_SERVER['TEST_YANDEX_MARKET_BUSINESS_DBS']
        );

        /** @var OrderStatusCollection $OrderStatusCollection */
        $OrderStatusCollection = self::getContainer()->get(OrderStatusCollection::class);
        $OrderStatusCollection->cases();


        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $main = $em->getRepository(Order::class)
            ->findOneBy(['id' => OrderUid::TEST]);

        if($main)
        {
            $em->remove($main);
        }

        $event = $em->getRepository(OrderEvent::class)
            ->findBy(['orders' => OrderUid::TEST]);

        foreach($event as $remove)
        {
            $em->remove($remove);
        }

        $em->flush();
        //$em->clear();
    }

    public function testUseCase(): void
    {


        /** @var YandexMarketNewOrdersRequest $YandexMarketNewOrdersRequest */
        $YandexMarketNewOrdersRequest = self::getContainer()->get(YandexMarketNewOrdersRequest::class);
        $YandexMarketNewOrdersRequest->TokenHttpClient(self::$Authorization);

        $orders = $YandexMarketNewOrdersRequest
            ->findAll(DateInterval::createFromDateString('10 day'));

        if($orders->valid())
        {
            /** @var YandexMarketOrderDTO $YandexMarketOrderDTO */
            $YandexMarketOrderDTO = $orders->current();

            /** @var OrderUserDTO $OrderUserDTO */
            $OrderUserDTO = $YandexMarketOrderDTO->getUsr();
            $OrderUserDTO->setProfile(new UserProfileEventUid()); // присваиваем идентификатор тестового профиля

            self::assertTrue($OrderUserDTO->getUserProfile()->getType()->equals(TypeProfileDbsYaMarket::TYPE));
            self::assertTrue($OrderUserDTO->getDelivery()->getDelivery()->equals(TypeDeliveryDbsYaMarket::TYPE));
            self::assertTrue($OrderUserDTO->getPayment()->getPayment()->equals(TypePaymentDbsYaMarket::TYPE));

            /** @var YandexMarketOrderHandler $YandexMarketOrderHandler */
            $YandexMarketOrderHandler = self::getContainer()->get(YandexMarketOrderHandler::class);
            $handle = $YandexMarketOrderHandler->handle($YandexMarketOrderDTO);

            self::assertTrue(($handle instanceof Order), $handle.': Ошибка YandexMarketOrder');

        }
        else
        {
            self::assertFalse($orders->valid());
        }
    }


    public static function tearDownAfterClass(): void
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $main = $em->getRepository(Order::class)
            ->findOneBy(['id' => OrderUid::TEST]);

        if($main)
        {
            $em->remove($main);
        }

        $event = $em->getRepository(OrderEvent::class)
            ->findBy(['orders' => OrderUid::TEST]);

        foreach($event as $remove)
        {
            $em->remove($remove);
        }

        $em->flush();
    }

}
