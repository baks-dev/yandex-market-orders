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

namespace BaksDev\Yandex\Market\Orders\UseCase\Status\Completed;

use BaksDev\DeliveryTransport\UseCase\Admin\Package\Completed\CompletedProductStockDTO;
use BaksDev\DeliveryTransport\UseCase\Admin\Package\Completed\CompletedProductStockHandler;
use BaksDev\Products\Stocks\Entity\ProductStock;
use BaksDev\Products\Stocks\Repository\ProductStockByNumber\ProductStockByNumberInterface;
use BaksDev\Products\Stocks\Type\Status\ProductStockStatus\ProductStockStatusCompleted;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
use BaksDev\Yandex\Market\Orders\Api\Completed\YaMarketCompletedOrderDTO;

final readonly class CompletedYaMarketOrderStatusHandler
{
    public function __construct(
        private ProductStockByNumberInterface $ProductStockByNumber,
        private CompletedProductStockHandler $CompletedProductStockHandler
    ) {}


    public function handle(
        YaMarketCompletedOrderDTO $command,
        UserProfileUid $profile
    ): ProductStock|string|false
    {
        /** Получаем складскую заявку по номеру */

        $ProductStockEvent = $this->ProductStockByNumber->find($command->getNumber());

        if(false === $ProductStockEvent)
        {
            return false;
        }

        /**
         * Пропускаем, если складская заявка существует и её статус уже является COMPLETED «Выполнен»
         */

        if(true === $ProductStockEvent->equalsProductStockStatus(ProductStockStatusCompleted::class))
        {
            return false;
        }

        $CompletedProductStockDTO = new CompletedProductStockDTO(
            $ProductStockEvent->getId(),
            $profile
        );

        return $this->CompletedProductStockHandler->handle($CompletedProductStockDTO);
    }
}
