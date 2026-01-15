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

namespace BaksDev\Yandex\Market\Orders\UseCase\Unpaid\Tests;

use BaksDev\Core\Cache\AppCacheInterface;
use BaksDev\Core\Messenger\MessageDispatchInterface;
use BaksDev\Orders\Order\Entity\Event\OrderEvent;
use BaksDev\Orders\Order\Type\Status\OrderStatus\Collection\OrderStatusNew;
use BaksDev\Orders\Order\Type\Status\OrderStatus\Collection\OrderStatusUnpaid;
use BaksDev\Orders\Order\UseCase\Admin\Edit\Tests\OrderNewTest;
use BaksDev\Products\Product\Repository\CurrentProductByArticle\ProductConstByArticleInterface;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
use BaksDev\Users\Profile\UserProfile\UseCase\Admin\NewEdit\Tests\NewUserProfileHandlerTest;
use BaksDev\Yandex\Market\Orders\Api\GetYaMarketOrdersUnpaidRequest;
use BaksDev\Yandex\Market\Orders\UseCase\New\NewYaMarketOrderDTO;
use BaksDev\Yandex\Market\Orders\UseCase\New\Products\NewYaMarketOrderProductDTO;
use BaksDev\Yandex\Market\Orders\UseCase\Status\New\ToggleUnpaidToNewYaMarketOrderHandler;
use BaksDev\Yandex\Market\Orders\UseCase\Unpaid\UnpaidYaMarketOrderStatusHandler;
use BaksDev\Yandex\Market\Type\Authorization\YaMarketAuthorizationToken;
use DateInterval;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\Attribute\When;

#[When(env: 'test')]
#[Group('yandex-market-orders')]
class UnpaidYaMarketOrderHandlerTest extends KernelTestCase
{
    private static YaMarketAuthorizationToken $Authorization;

    public static function setUpBeforeClass(): void
    {
        /** Удаляем тестовый заказ */
        OrderNewTest::setUpBeforeClass();

        NewUserProfileHandlerTest::setUpBeforeClass();

        self::$Authorization = new YaMarketAuthorizationToken(
            profile: UserProfileUid::TEST,
            token: $_SERVER['TEST_YANDEX_MARKET_TOKEN'],
            company: (int) $_SERVER['TEST_YANDEX_MARKET_COMPANY'],
            business: (int) $_SERVER['TEST_YANDEX_MARKET_BUSINESS'],
            card: false,
            stocks: false,
        );
    }


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
            return;
        }

        /** @var MessageDispatchInterface $MessageDispatch */
        $MessageDispatch = self::getContainer()->get(MessageDispatchInterface::class);
        $MessageDispatch->falseDispatch();

        /**
         * Получаем список новых заказов с целью получить хоть один существующий заказ
         *
         * @var GetYaMarketOrdersUnpaidRequest $GetYaMarketOrdersUnpaidRequest
         */
        $GetYaMarketOrdersUnpaidRequest = self::getContainer()->get(GetYaMarketOrdersUnpaidRequest::class);
        $GetYaMarketOrdersUnpaidRequest->TokenHttpClient(self::$Authorization);

        $response = $GetYaMarketOrdersUnpaidRequest
            ->findAll(DateInterval::createFromDateString('30 day'));

        if(false === $response || false === $response->valid())
        {
            return;
        }


        /** @var ProductConstByArticleInterface $ProductConstByArticleInterface */
        $ProductConstByArticleInterface = self::getContainer()->get(ProductConstByArticleInterface::class);


        /** @var NewYaMarketOrderDTO $YandexMarketOrderDTO */
        foreach($response as $YandexMarketOrderDTO)
        {
            $products = $YandexMarketOrderDTO->getProduct();

            if($products->count() > 1)
            {
                continue;
            }

            /** @var NewYaMarketOrderProductDTO $NewOrderProductDTO */
            $NewOrderProductDTO = $products->current();

            $CurrentProductDTO = $ProductConstByArticleInterface->find($NewOrderProductDTO->getArticle());


            if($CurrentProductDTO === false)
            {
                continue;
            }


            /** Создаем новый заказ, который автоматически должен измениться на статус «Не оплачен» */

            /* Принудительно указываем статус UNPAID */
            $YandexMarketOrderDTO->setStatus(OrderStatusUnpaid::class);

            /** @var UnpaidYaMarketOrderStatusHandler $handler */
            $handler = self::getContainer()->get(UnpaidYaMarketOrderStatusHandler::class);
            $handle = $handler->handle($YandexMarketOrderDTO);

            $em = self::getContainer()->get(EntityManagerInterface::class);
            $OrderEvent = $em->getRepository(OrderEvent::class)->find($handle->getEvent());

            self::assertNotNull($OrderEvent);
            self::assertTrue($OrderEvent->getStatus()->equals(OrderStatusUnpaid::class));


            /** Возвращаем неоплаченный заказ в статус НОВЫЙ после оплаты */
            $YandexMarketOrderDTO->setStatus(OrderStatusNew::class);

            /** @var ToggleUnpaidToNewYaMarketOrderHandler $toggle */
            $toggle = self::getContainer()->get(ToggleUnpaidToNewYaMarketOrderHandler::class);
            $handle = $toggle->handle($YandexMarketOrderDTO);

            /** Запоминаем результат тестирования */
            $item->expiresAfter(DateInterval::createFromDateString('1 day'));
            $item->set(1);
            $cache->save($item);

            return;
        }

        foreach($YandexMarketOrderDTO->getProduct() as $product)
        {
            echo PHP_EOL.sprintf('Не найдено продукции %s для теста ', $product->getArticle()).self::class.':'.__LINE__.PHP_EOL;
        }
    }
}
