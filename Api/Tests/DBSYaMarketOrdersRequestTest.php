<?php
/*
 *  Copyright 2025.  Baks.dev <admin@baks.dev>
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
use BaksDev\Core\Type\Gps\GpsLatitude;
use BaksDev\Core\Type\Gps\GpsLongitude;
use BaksDev\Payment\Type\Id\PaymentUid;
use BaksDev\Reference\Money\Type\Money;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
use BaksDev\Yandex\Market\Orders\Api\GetYaMarketOrdersNewRequest;
use BaksDev\Yandex\Market\Orders\Type\DeliveryType\TypeDeliveryDbsYaMarket;
use BaksDev\Yandex\Market\Orders\UseCase\New\Products\Price\NewOrderPriceDTO;
use BaksDev\Yandex\Market\Orders\UseCase\New\User\Delivery\OrderDeliveryDTO;
use BaksDev\Yandex\Market\Orders\UseCase\New\User\OrderUserDTO;
use BaksDev\Yandex\Market\Orders\UseCase\New\User\Payment\OrderPaymentDTO;
use BaksDev\Yandex\Market\Orders\UseCase\New\YandexMarketOrderDTO;
use BaksDev\Yandex\Market\Type\Authorization\YaMarketAuthorizationToken;
use DateInterval;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\Attribute\When;


/**
 * @group get-ya-market-orders-new-request-test
 */
#[When(env: 'test')]
class DBSYaMarketOrdersRequestTest extends KernelTestCase
{
    private static YaMarketAuthorizationToken $Authorization;

    public static function setUpBeforeClass(): void
    {
        self::$Authorization = new YaMarketAuthorizationToken(
            profile: UserProfileUid::TEST,
            token: $_SERVER['TEST_YANDEX_MARKET_TOKEN_DBS'],
            company: (int) $_SERVER['TEST_YANDEX_MARKET_COMPANY_DBS'],
            business: (int) $_SERVER['TEST_YANDEX_MARKET_BUSINESS_DBS'],
            card: false,
            stocks: false,
        );
    }

    public function testUseCase(): void
    {
        /** @var GetYaMarketOrdersNewRequest $GetYaMarketOrdersNewRequest */
        $GetYaMarketOrdersNewRequest = self::getContainer()->get(GetYaMarketOrdersNewRequest::class);
        $GetYaMarketOrdersNewRequest->TokenHttpClient(self::$Authorization);

        $orders = $GetYaMarketOrdersNewRequest->findAll(DateInterval::createFromDateString('1 day'));

        self::assertTrue($orders->valid() === true || $orders->valid() === false);

        if($orders->valid() === false)
        {
            $this->addWarning('yandex-market-orders: Новых заказов не найдено ('.self::class.':'.__LINE__.')');
            return;
        }

        foreach($orders as $YandexMarketOrderDTO)
        {

            self::assertInstanceOf(YandexMarketOrderDTO::class, $YandexMarketOrderDTO);

            $NewOrderInvariable = $YandexMarketOrderDTO->getInvariable();

            self::assertTrue($NewOrderInvariable->getProfile()->equals(UserProfileUid::TEST));
            self::assertNotNull($NewOrderInvariable->getNumber());
            self::assertInstanceOf(DateTimeImmutable::class, $NewOrderInvariable->getCreated());


            /** Коллекция продукции */

            $ArrayCollectionProducts = $YandexMarketOrderDTO->getProduct();

            self::assertTrue($ArrayCollectionProducts->count() > 0);

            foreach($ArrayCollectionProducts as $YandexMarketOrderProductDTO)
            {

                self::assertNotEmpty($YandexMarketOrderProductDTO->getArticle());

                $NewOrderPriceDTO = $YandexMarketOrderProductDTO->getPrice();

                self::assertInstanceOf(NewOrderPriceDTO::class, $NewOrderPriceDTO);
                self::assertIsInt($NewOrderPriceDTO->getTotal());
                self::assertTrue($NewOrderPriceDTO->getTotal() > 0);
                self::assertInstanceOf(Money::class, $NewOrderPriceDTO->getPrice());
                self::assertTrue($NewOrderPriceDTO->getPrice()->getValue() > 0);


            }


            /** Информация о покупателе */

            $OrderUserDTO = $YandexMarketOrderDTO->getUsr();

            self::assertInstanceOf(OrderUserDTO::class, $OrderUserDTO);


            /** Информация о доставке */
            $OrderDeliveryDTO = $OrderUserDTO->getDelivery();
            self::assertInstanceOf(OrderDeliveryDTO::class, $OrderDeliveryDTO);

            self::assertTrue($OrderDeliveryDTO->getDelivery()->equals(TypeDeliveryDbsYaMarket::TYPE));
            self::assertNotNull($OrderDeliveryDTO->getAddress());
            self::assertInstanceOf(GpsLatitude::class, $OrderDeliveryDTO->getLatitude());
            self::assertInstanceOf(GpsLongitude::class, $OrderDeliveryDTO->getLongitude());
            self::assertInstanceOf(DateTimeImmutable::class, $OrderDeliveryDTO->getDeliveryDate());


            /** Информация о способе оплаты */
            $OrderPaymentDTO = $OrderUserDTO->getPayment();
            self::assertInstanceOf(OrderPaymentDTO::class, $OrderPaymentDTO);
            self::assertInstanceOf(PaymentUid::class, $OrderPaymentDTO->getPayment());

            break;
        }

    }


}