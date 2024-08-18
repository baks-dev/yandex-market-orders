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

use BaksDev\Orders\Order\Entity\Event\OrderEvent;
use BaksDev\Orders\Order\Entity\Order;
use BaksDev\Orders\Order\Repository\CurrentOrderEvent\CurrentOrderEventInterface;
use BaksDev\Orders\Order\Type\Id\OrderUid;
use BaksDev\Orders\Order\Type\Status\OrderStatus\OrderStatusCanceled;
use BaksDev\Orders\Order\Type\Status\OrderStatus\OrderStatusUnpaid;
use BaksDev\Orders\Order\UseCase\Admin\Status\OrderStatusHandler;
use BaksDev\Products\Product\Repository\CurrentProductByArticle\ProductConstByArticleInterface;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
use BaksDev\Yandex\Market\Orders\Api\YaMarketNewOrdersRequest;
use BaksDev\Yandex\Market\Orders\Api\YaMarketUnpaidOrdersRequest;
use BaksDev\Yandex\Market\Orders\UseCase\New\Products\NewOrderProductDTO;
use BaksDev\Yandex\Market\Orders\UseCase\New\YandexMarketOrderDTO;
use BaksDev\Yandex\Market\Orders\UseCase\Status\Cancel\CancelYaMarketOrderStatusDTO;
use BaksDev\Yandex\Market\Orders\UseCase\Status\Cancel\CancelYaMarketOrderStatusHandler;
use BaksDev\Yandex\Market\Orders\UseCase\Status\New\NewYaMarketOrderStatusHandler;
use BaksDev\Yandex\Market\Orders\UseCase\Status\New\Tests\NewYaMarketOrderStatusTest;
use BaksDev\Yandex\Market\Orders\UseCase\Unpaid\Tests\UnpaidYaMarketOrderHandlerTest;
use BaksDev\Yandex\Market\Orders\UseCase\Unpaid\UnpaidYaMarketOrderHandler;
use BaksDev\Yandex\Market\Type\Authorization\YaMarketAuthorizationToken;
use DateInterval;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * @group yandex-market-orders
 * @group yandex-market-orders-status
 *
 * @depends BaksDev\Yandex\Market\Orders\UseCase\Status\Cancel\Tests\CancelYaMarketOrderStatusTest::class
 */
#[When(env: 'test')]
class AllUseCaseYaMarketOrderTest extends KernelTestCase
{
    private static YaMarketAuthorizationToken $Authorization;

    public static function setUpBeforeClass(): void
    {
        // Бросаем событие консольной комманды
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        $event = new ConsoleCommandEvent(new Command(), new StringInput(''), new NullOutput());
        $dispatcher->dispatch($event, 'console.command');

        UnpaidYaMarketOrderHandlerTest::clearData();

        self::$Authorization = new YaMarketAuthorizationToken(
            new UserProfileUid(),
            $_SERVER['TEST_YANDEX_MARKET_TOKEN'],
            $_SERVER['TEST_YANDEX_MARKET_COMPANY'],
            $_SERVER['TEST_YANDEX_MARKET_BUSINESS']
        );
    }

    public function testUseCase(): void
    {
        /**
         * Получаем список новых заказов с целью получить хоть один существующий заказ
         *
         * @var YaMarketNewOrdersRequest $YandexMarketNewOrdersRequest
         */
        $YandexMarketNewOrdersRequest = self::getContainer()->get(YaMarketNewOrdersRequest::class);
        $YandexMarketNewOrdersRequest->TokenHttpClient(self::$Authorization);

        $response = $YandexMarketNewOrdersRequest->findAll(DateInterval::createFromDateString('10 day'));

        if($response->valid())
        {
            /** @var ProductConstByArticleInterface $ProductConstByArticleInterface */
            $ProductConstByArticleInterface = self::getContainer()->get(ProductConstByArticleInterface::class);

            /** @var YandexMarketOrderDTO $YandexMarketOrderDTO */
            foreach($response as $YandexMarketOrderDTO)
            {
                $products = $YandexMarketOrderDTO->getProduct();

                /** @var NewOrderProductDTO $NewOrderProductDTO */
                $NewOrderProductDTO = $products->current();

                $CurrentProductDTO = $ProductConstByArticleInterface->find($NewOrderProductDTO->getArticle());

                if($CurrentProductDTO === false)
                {
                    continue;
                }

                /**
                 * Создаем новый заказ, который автоматически должен измениться на статус «Не оплачен»
                 * @var UnpaidYaMarketOrderHandler $handler
                 */
                $handler = self::getContainer()->get(UnpaidYaMarketOrderHandler::class);
                $handle = $handler->handle($YandexMarketOrderDTO);

                self::assertTrue(($handle instanceof Order), $handle.': Ошибка YandexMarketOrder');


                /**
                 * Обновляем заказ на Новый после оплаты
                 * @var NewYaMarketOrderStatusHandler $handler
                 */
                $handler = self::getContainer()->get(NewYaMarketOrderStatusHandler::class);
                $handle = $handler->handle($YandexMarketOrderDTO);
                self::assertTrue(($handle instanceof Order), $handle.': Ошибка YandexMarketOrder');

                /**
                 * Делаем отмену заказа
                 * @var CancelYaMarketOrderStatusHandler $handler
                 */
                $handler = self::getContainer()->get(CancelYaMarketOrderStatusHandler::class);
                $handle = $handler->handle($YandexMarketOrderDTO, new UserProfileUid(UserProfileUid::TEST));

                self::assertTrue(($handle instanceof Order), $handle.': Ошибка YandexMarketOrder');

                /** @var EntityManagerInterface $em */
                $em = self::getContainer()->get(EntityManagerInterface::class);
                $events = $em->getRepository(OrderEvent::class)
                    ->findBy(['orders' => OrderUid::TEST]);

                self::assertCount(4, $events);

                return;
            }

            echo PHP_EOL.'Не найдено продукции для теста '.self::class.':'.__LINE__.PHP_EOL;

        }
        else
        {
            self::assertFalse($response->valid());
        }
    }

    public static function tearDownAfterClass(): void
    {
        UnpaidYaMarketOrderHandlerTest::clearData();
    }

}