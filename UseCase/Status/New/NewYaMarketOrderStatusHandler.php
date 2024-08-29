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

namespace BaksDev\Yandex\Market\Orders\UseCase\Status\New;

use BaksDev\Orders\Order\Entity\Order;
use BaksDev\Orders\Order\Repository\CurrentOrderNumber\CurrentOrderNumberInterface;
use BaksDev\Orders\Order\Repository\ExistsOrderNumber\ExistsOrderNumberInterface;
use BaksDev\Orders\Order\UseCase\Admin\Status\OrderStatusHandler;
use BaksDev\Yandex\Market\Orders\UseCase\New\YandexMarketOrderDTO;

final class NewYaMarketOrderStatusHandler
{
    public function __construct(
        private readonly ExistsOrderNumberInterface $existsOrderNumber,
        private readonly CurrentOrderNumberInterface $currentOrderNumber,
        private readonly OrderStatusHandler $orderStatusHandler,
    ) {}

    /** Метод возвращает статус неоплаченного заказа в статус NEW */
    public function handle(YandexMarketOrderDTO $command): string|Order
    {
        $isExists = $this->existsOrderNumber->isExists($command->getNumber());

        if($isExists === false)
        {
            return 'Заказ не найден';
        }

        $OrderEvent = $this->currentOrderNumber->getCurrentOrderEvent($command->getNumber());

        if($OrderEvent === null)
        {
            return 'Заказ не найден';
        }


        /**
         * При изменении статуса в NEW «Новый» будет сброс ограничения по профилю
         * @see OrderInvariable
         */
        $NewYaMarketOrderStatusDTO = new NewYaMarketOrderStatusDTO();
        $OrderEvent->getDto($NewYaMarketOrderStatusDTO);


        if(false === $NewYaMarketOrderStatusDTO->isStatusUnpaid())
        {
            return 'Заказ уже добавлен, но его статус не является Unpaid «Не оплачен»';
        }

        /**
         * Если заказ существует и его статус Unpaid «В ожидании оплаты» - обновляем на статус NEW «Новый»
         */

        $NewYaMarketOrderStatusDTO->setOrderStatusNew();

        /**
         * Ожидается, что статус NEW «Новый» объявлен ранее для резерва продукции
         * применяем статус без проверки дублей (deduplicator: false)
         */
        return $this->orderStatusHandler->handle($NewYaMarketOrderStatusDTO, false);
    }
}
