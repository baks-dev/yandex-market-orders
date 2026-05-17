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
 *
 */

declare(strict_types=1);

namespace BaksDev\Yandex\Market\Orders\Api\Tests;

use BaksDev\Core\Doctrine\DBALQueryBuilder;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
use BaksDev\Users\Profile\UserProfile\UseCase\Admin\NewEdit\Tests\NewUserProfileHandlerTest;
use BaksDev\Yandex\Market\Orders\Api\GetYaMarketOrderInfoRequest;
use BaksDev\Yandex\Market\Orders\UseCase\New\NewYaMarketOrderByBusinessDTO;
use BaksDev\Yandex\Market\Orders\UseCase\New\NewYaMarketOrderHandler;
use BaksDev\Yandex\Market\Type\Authorization\YaMarketAuthorizationToken;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DependsOnClass;
use PHPUnit\Framework\Attributes\Group;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

#[When(env: 'test')]
#[Group('yandex-market-orders')]
class GetYaMarketOrderInfoRequestTest extends KernelTestCase
{
    private static YaMarketAuthorizationToken $Authorization;

    public static function setUpBeforeClass(): void
    {
        /** FBS */

        /*self::$Authorization = new YaMarketAuthorizationToken(
            profile: UserProfileUid::TEST,
            token: $_SERVER['TEST_YANDEX_MARKET_TOKEN'],
            company: (int) $_SERVER['TEST_YANDEX_MARKET_COMPANY'],
            business: (int) $_SERVER['TEST_YANDEX_MARKET_BUSINESS'],
            card: false,
            stocks: false,
        );*/

        /** DBS */

        self::$Authorization = new YaMarketAuthorizationToken(
            profile: UserProfileUid::TEST,
            token: $_SERVER['TEST_YANDEX_MARKET_TOKEN_DBS'],
            company: (int) 148730824,
            business: (int) $_SERVER['TEST_YANDEX_MARKET_BUSINESS_DBS'],
            card: false,
            stocks: false,
        );

        // Бросаем событие консольной комманды
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        $event = new ConsoleCommandEvent(new Command(), new StringInput(''), new NullOutput());
        $dispatcher->dispatch($event, 'console.command');

        NewUserProfileHandlerTest::setUpBeforeClass();
    }

    public function testUseCase(): void
    {

        self::assertTrue(true);

        /** @var GetYaMarketOrderInfoRequest $GetYaMarketOrderInfoRequest */
        $GetYaMarketOrderInfoRequest = self::getContainer()->get(GetYaMarketOrderInfoRequest::class);
        $GetYaMarketOrderInfoRequest->TokenHttpClient(self::$Authorization);

        $NewYaMarketOrderByBusinessDTO = $GetYaMarketOrderInfoRequest
            ->findNew('number');

        if(false === ($NewYaMarketOrderByBusinessDTO instanceof NewYaMarketOrderByBusinessDTO))
        {
            echo sprintf('%s результат запроса не протестирован %s %s', PHP_EOL, self::class, PHP_EOL);
            return;
        }


        /**
         * Пробуем сохранить заказ
         *
         * @var NewYaMarketOrderHandler $NewYaMarketOrderHandler
         */
        $NewYaMarketOrderHandler = self::getContainer()->get(NewYaMarketOrderHandler::class);
        $NewYaMarketOrderHandler->handle($NewYaMarketOrderByBusinessDTO);


        // Вызываем все геттеры
        $reflectionClass = new ReflectionClass(NewYaMarketOrderByBusinessDTO::class);
        $methods = $reflectionClass->getMethods(ReflectionMethod::IS_PUBLIC);

        foreach($methods as $method)
        {
            // Методы без аргументов
            if($method->getNumberOfParameters() === 0)
            {
                // Вызываем метод
                $data = $method->invoke($NewYaMarketOrderByBusinessDTO);
                // dump($data);
            }
        }
    }
}
