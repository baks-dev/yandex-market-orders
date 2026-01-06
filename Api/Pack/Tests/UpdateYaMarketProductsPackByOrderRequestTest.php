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
 */

declare(strict_types=1);

namespace BaksDev\Yandex\Market\Orders\Api\Pack\Tests;

use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
use BaksDev\Yandex\Market\Orders\Api\Pack\UpdateYaMarketProductsPackByOrderRequest;
use BaksDev\Yandex\Market\Orders\Api\Pack\YaMarketProductsPackDTO;
use BaksDev\Yandex\Market\Type\Authorization\YaMarketAuthorizationToken;
use PHPUnit\Framework\Attributes\Group;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\Attribute\When;

#[When(env: 'test')]
#[Group('yandex-market-orders')]
class UpdateYaMarketProductsPackByOrderRequestTest extends KernelTestCase
{
    private static YaMarketAuthorizationToken $Authorization;

    public static function setUpBeforeClass(): void
    {
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
        //return;

        /** @var UpdateYaMarketProductsPackByOrderRequest $UpdateYaMarketProductsPackByOrderRequest */
        $UpdateYaMarketProductsPackByOrderRequest = self::getContainer()->get(UpdateYaMarketProductsPackByOrderRequest::class);
        $UpdateYaMarketProductsPackByOrderRequest->TokenHttpClient(self::$Authorization);

        $results = $UpdateYaMarketProductsPackByOrderRequest
            ->products([
                [
                    'items' => [
                        [
                            'id' => 1025572165, // идентификатор продукта
                            'fullCount' => 1, // машиноместо
                            /*'instances' => [
                                ['cis' => '01030410947874432155Qbag!93Zjqw'], // честный знак
                            ],*/
                        ],
                    ],
                ],
                [
                    'items' => [
                        [
                            'id' => 1025572165, // идентификатор продукта
                            'fullCount' => 1, // машиноместо
                            /*'instances' => [
                                ['cis' => '01030410947874432155Qbag!93Zjqw'], // честный знак
                            ],*/
                        ],
                    ],
                ],
                [
                    'items' => [
                        [
                            'id' => 1025572165, // идентификатор продукта
                            'fullCount' => 1, // машиноместо
                            /*'instances' => [
                                ['cis' => '01030410947874432155Qbag!93Zjqw'], // честный знак
                            ],*/
                        ],
                    ],
                ],
                [
                    'items' => [
                        [
                            'id' => 1025572165, // идентификатор продукта
                            'fullCount' => 1, // машиноместо
                            /*'instances' => [
                                ['cis' => '01030410947874432155Qbag!93Zjqw'], // честный знак
                            ],*/
                        ],
                    ],
                ],
            ])
            ->package('Y-52858158913');

        foreach($results as $YaMarketProductsPackDTO)
        {
            // Вызываем все геттеры
            $reflectionClass = new ReflectionClass(YaMarketProductsPackDTO::class);
            $methods = $reflectionClass->getMethods(ReflectionMethod::IS_PUBLIC);

            foreach($methods as $method)
            {
                // Методы без аргументов
                if($method->getNumberOfParameters() === 0)
                {
                    // Вызываем метод
                    $data = $method->invoke($YaMarketProductsPackDTO);
                    // dump($data);
                }
            }
        }
    }
}

