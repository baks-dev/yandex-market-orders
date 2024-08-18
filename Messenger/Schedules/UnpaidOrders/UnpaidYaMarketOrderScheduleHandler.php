<?php
/*
 *  Copyright 2023.  Baks.dev <admin@baks.dev>
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

namespace BaksDev\Yandex\Market\Orders\Messenger\Schedules\UnpaidOrders;

use BaksDev\Orders\Order\Entity\Order;
use BaksDev\Orders\Order\Repository\ExistsOrderNumber\ExistsOrderNumberInterface;
use BaksDev\Yandex\Market\Orders\Api\YaMarketUnpaidOrdersRequest;
use BaksDev\Yandex\Market\Orders\UseCase\New\YandexMarketOrderDTO;
use BaksDev\Yandex\Market\Orders\UseCase\Unpaid\UnpaidYaMarketOrderHandler;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class UnpaidYaMarketOrderScheduleHandler
{
    private LoggerInterface $logger;

    public function __construct(
        private readonly YaMarketUnpaidOrdersRequest $yandexMarketUnpaidOrdersRequest,
        private readonly ExistsOrderNumberInterface $existsOrderNumber,
        private readonly UnpaidYaMarketOrderHandler $unpaidYandexMarketHandler,
        LoggerInterface $yandexMarketOrdersLogger,
    ) {
        $this->logger = $yandexMarketOrdersLogger;
    }

    public function __invoke(UnpaidYaMarketOrdersScheduleMessage $message): void
    {
        /* Получить список неоплаченных заказов */
        $orders = $this->yandexMarketUnpaidOrdersRequest
            ->profile($message->getProfile())
            ->findAll();

        if(!$orders->valid())
        {
            $this->logger->info(
                'Неоплаченных заказов не найдено',
                [
                    self::class.':'.__LINE__,
                    'profile' => (string) $message->getProfile(),
                ]
            );

            return;
        }

        $this->logger->notice(
            'Получаем ожидающие оплату заказы и добавляем новые',
            [
                self::class.':'.__LINE__,
                'profile' => (string) $message->getProfile(),
            ]
        );

        /** @var YandexMarketOrderDTO $order */
        foreach($orders as $order)
        {
            $handle = $this->unpaidYandexMarketHandler->handle($order);

            if($handle instanceof Order)
            {
                $this->logger->info(
                    sprintf('Создали неоплаченный заказ %s', $order->getNumber()),
                    [
                        self::class.':'.__LINE__,
                        'attr' => (string) $message->getProfile()->getAttr(),
                        'profile' => (string) $message->getProfile(),
                    ]
                );
            }
        }
    }
}
