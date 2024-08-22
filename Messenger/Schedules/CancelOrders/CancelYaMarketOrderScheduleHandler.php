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

namespace BaksDev\Yandex\Market\Orders\Messenger\Schedules\CancelOrders;

use BaksDev\Orders\Order\Entity\Order;
use BaksDev\Orders\Order\Repository\CurrentOrderNumber\CurrentOrderNumberInterface;
use BaksDev\Orders\Order\Type\Status\OrderStatus\OrderStatusCanceled;
use BaksDev\Orders\Order\UseCase\Admin\Canceled\OrderCanceledDTO;
use BaksDev\Orders\Order\UseCase\Admin\Edit\EditOrderDTO;
use BaksDev\Orders\Order\UseCase\Admin\Status\OrderStatusHandler;
use BaksDev\Yandex\Market\Orders\Api\Canceled\YaMarketCancelOrdersRequest;
use BaksDev\Yandex\Market\Orders\UseCase\New\YandexMarketOrderDTO;
use BaksDev\Yandex\Market\Orders\UseCase\Status\Cancel\CancelYaMarketOrderStatusHandler;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class CancelYaMarketOrderScheduleHandler
{
    private LoggerInterface $logger;

    public function __construct(
        private readonly YaMarketCancelOrdersRequest $yandexMarketCancelOrdersRequest,
        private readonly CancelYaMarketOrderStatusHandler $cancelYaMarketOrderStatusHandler,
        LoggerInterface $yandexMarketOrdersLogger,
    ) {
        $this->logger = $yandexMarketOrdersLogger;
    }

    public function __invoke(CancelYaMarketOrdersScheduleMessage $message): void
    {
        /* Получить список неоплаченных заказов */
        $orders = $this->yandexMarketCancelOrdersRequest
            ->profile($message->getProfile())
            ->findAll();

        if(!$orders->valid())
        {
            $this->logger->info(
                'Отмененных заказов не найдено',
                [
                    self::class.':'.__LINE__,
                    'profile' => (string) $message->getProfile(),
                ]
            );

            return;
        }

        $this->logger->notice(
            'Получаем отмененные заказы',
            [
                self::class.':'.__LINE__,
                'profile' => (string) $message->getProfile(),
            ]
        );

        /** @var YandexMarketOrderDTO $order */
        foreach($orders as $order)
        {
            $handle = $this->cancelYaMarketOrderStatusHandler->handle($order, $message->getProfile());

            if($handle instanceof Order)
            {
                $this->logger->info(
                    sprintf('Отменили заказ %s', $order->getNumber()),
                    [
                        self::class.':'.__LINE__,
                        'attr' => (string) $message->getProfile()->getAttr(),
                        'profile' => (string) $message->getProfile(),
                    ]
                );
            }

            if($handle !== false)
            {
                $this->logger->critical(
                    sprintf('Yandex: Ошибка при отмене заказа %s (%s)', $order->getNumber(), $handle),
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
