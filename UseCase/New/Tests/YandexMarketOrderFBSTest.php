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
use BaksDev\Products\Product\Repository\CurrentProductByArticle\ProductConstByArticleInterface;
use BaksDev\Users\Profile\UserProfile\Type\Event\UserProfileEventUid;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
use BaksDev\Yandex\Market\Orders\Api\YaMarketNewOrdersRequest;
use BaksDev\Yandex\Market\Orders\Type\DeliveryType\TypeDeliveryYandexMarket;
use BaksDev\Yandex\Market\Orders\Type\PaymentType\TypePaymentYandex;
use BaksDev\Yandex\Market\Orders\Type\ProfileType\TypeProfileYandexMarket;
use BaksDev\Yandex\Market\Orders\UseCase\New\Products\NewOrderProductDTO;
use BaksDev\Yandex\Market\Orders\UseCase\New\User\OrderUserDTO;
use BaksDev\Yandex\Market\Orders\UseCase\New\YandexMarketOrderDTO;
use BaksDev\Yandex\Market\Orders\UseCase\New\YandexMarketOrderHandler;
use BaksDev\Yandex\Market\Type\Authorization\YaMarketAuthorizationToken;
use DateInterval;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * @group yandex-market-orders
 * @group yandex-market-orders-new
 */
#[When(env: 'test')]
class YandexMarketOrderFBSTest extends KernelTestCase
{
    private static YaMarketAuthorizationToken $Authorization;

    public static function setUpBeforeClass(): void
    {
        /** Токен авторизации заказов FBS - доставка курьером Яндекс Маркет */
        self::$Authorization = new YaMarketAuthorizationToken(
            new UserProfileUid(),
            $_SERVER['TEST_YANDEX_MARKET_TOKEN'],
            $_SERVER['TEST_YANDEX_MARKET_COMPANY'],
            $_SERVER['TEST_YANDEX_MARKET_BUSINESS']
        );

        // Бросаем событие консольной комманды
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        $event = new ConsoleCommandEvent(new Command(), new StringInput(''), new NullOutput());
        $dispatcher->dispatch($event, 'console.command');


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

    public function testUseCase(): void
    {
        /** @var YaMarketNewOrdersRequest $YandexMarketNewOrdersRequest */
        $YandexMarketNewOrdersRequest = self::getContainer()->get(YaMarketNewOrdersRequest::class);
        $YandexMarketNewOrdersRequest->TokenHttpClient(self::$Authorization);

        $response = $YandexMarketNewOrdersRequest
            ->findAll(DateInterval::createFromDateString('10 day'));


        if($response->valid())
        {
            /** @var ProductConstByArticleInterface $ProductConstByArticleInterface */
            $ProductConstByArticleInterface = self::getContainer()->get(ProductConstByArticleInterface::class);

            /** @var YandexMarketOrderDTO $YandexMarketOrderDTO */
            foreach($response as $YandexMarketOrderDTO)
            {
                $products = $YandexMarketOrderDTO->getProduct();

                /** @var NewOrderProductDTO $NewOrderProductDTO */
                foreach($products as $NewOrderProductDTO)
                {
                    $CurrentProductDTO = $ProductConstByArticleInterface->find($NewOrderProductDTO->getArticle());

                    if($CurrentProductDTO === false)
                    {
                        dump('continue 2');
                        continue 2;
                    }
                }

                /** @var OrderUserDTO $OrderUserDTO */
                $OrderUserDTO = $YandexMarketOrderDTO->getUsr();
                $OrderUserDTO->setProfile(new UserProfileEventUid()); // присваиваем клиенту идентификатор тестового профиля

                self::assertTrue($OrderUserDTO->getUserProfile()->getType()->equals(TypeProfileYandexMarket::TYPE));
                self::assertTrue($OrderUserDTO->getDelivery()->getDelivery()->equals(TypeDeliveryYandexMarket::TYPE));
                self::assertTrue($OrderUserDTO->getPayment()->getPayment()->equals(TypePaymentYandex::TYPE));

                /** @var YandexMarketOrderHandler $YandexMarketOrderHandler */
                $YandexMarketOrderHandler = self::getContainer()->get(YandexMarketOrderHandler::class);

                $handle = $YandexMarketOrderHandler->handle($YandexMarketOrderDTO);
                self::assertTrue(($handle instanceof Order), $handle.': Ошибка YandexMarketOrder');

                return;

            }

            self::assertFalse(true, message: 'Не найдено ни одного товара для заказа FBS');

        }
        else
        {
            self::assertFalse($response->valid());
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
