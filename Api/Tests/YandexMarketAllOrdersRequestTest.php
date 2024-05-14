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

namespace BaksDev\Yandex\Market\Orders\Api\Tests;


use BaksDev\Core\Doctrine\DBALQueryBuilder;
use BaksDev\Orders\Order\Repository\ExistsOrderNumber\ExistsOrderNumberInterface;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
use BaksDev\Yandex\Market\Orders\Api\YandexMarketAllOrdersRequest;
use BaksDev\Yandex\Market\Orders\UseCase\New\YandexMarketOrderDTO;
use BaksDev\Yandex\Market\Orders\UseCase\New\YandexMarketOrderHandler;
use BaksDev\Yandex\Market\Type\Authorization\YaMarketAuthorizationToken;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * @group yandex-market-orders
 */
#[When(env: 'test')]
class YandexMarketAllOrdersRequestTest extends KernelTestCase
{

    private static YaMarketAuthorizationToken $Authorization;

    public static function setUpBeforeClass(): void
    {
        self::$Authorization = new YaMarketAuthorizationToken(
            new UserProfileUid(),
            $_SERVER['TEST_YANDEX_MARKET_TOKEN'],
            $_SERVER['TEST_YANDEX_MARKET_COMPANY'],
            $_SERVER['TEST_YANDEX_MARKET_BUSINESS']
        );
    }

    public function testComplete(): void
    {
        /** @var YandexMarketAllOrdersRequest $YandexMarketAllOrdersRequest */
        $YandexMarketAllOrdersRequest = self::getContainer()->get(YandexMarketAllOrdersRequest::class);
        $YandexMarketAllOrdersRequest->TokenHttpClient(self::$Authorization);


        /** @var YandexMarketOrderHandler $YandexMarketOrderHandler */
        $YandexMarketOrderHandler = self::getContainer()->get(YandexMarketOrderHandler::class);

        /** @var ExistsOrderNumberInterface $ExistsOrderNumberInterface */
        $ExistsOrderNumberInterface = self::getContainer()->get(ExistsOrderNumberInterface::class);

        $orders = $YandexMarketAllOrdersRequest->findAll();


        if($orders->valid())
        {
            /** @var YandexMarketOrderDTO $YandexMarketOrderDTO */
            $YandexMarketOrderDTO = $orders->current();

            $ExistsOrderNumberInterface->isExists($YandexMarketOrderDTO->getNumber());

            self::assertTrue($orders->valid());

        }
        else
        {
            self::assertFalse($orders->valid());
        }


        //        /** @var YandexMarketOrderRequest $YandexMarketOrderRequest */
        //        $YandexMarketOrderRequest = self::getContainer()->get(YandexMarketOrderRequest::class);
        //        $YandexMarketOrderRequest->TokenHttpClient(self::$Authorization);
        //
        //        $info = $YandexMarketOrderRequest->find('457047911');


    }
}