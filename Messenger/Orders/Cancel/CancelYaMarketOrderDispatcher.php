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

namespace BaksDev\Yandex\Market\Orders\Messenger\Orders\Cancel;


use BaksDev\Centrifugo\Server\Publish\CentrifugoPublishInterface;
use BaksDev\Core\Deduplicator\DeduplicatorInterface;
use BaksDev\Orders\Order\Repository\CurrentOrderNumber\CurrentOrderEventByNumberInterface;
use BaksDev\Orders\Order\Type\Status\OrderStatus\Collection\OrderStatusCanceled;
use BaksDev\Orders\Order\Type\Status\OrderStatus\Collection\OrderStatusMarketplace;
use BaksDev\Yandex\Market\Orders\UseCase\Status\Cancel\CancelYaMarketOrderStatusHandler;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[Autoconfigure(shared: false)]
#[AsMessageHandler(priority: 0)]
final readonly class CancelYaMarketOrderDispatcher
{
    public function __construct(
        #[Target('yandexMarketOrdersLogger')] private LoggerInterface $logger,
        private CurrentOrderEventByNumberInterface $CurrentOrderEventByNumberRepository,
        private CancelYaMarketOrderStatusHandler $cancelYaMarketOrderStatusHandler,
        private DeduplicatorInterface $deduplicator,
        private CentrifugoPublishInterface $publish,
    ) {}

    public function __invoke(CancelYaMarketOrderMessage $message): void
    {

        /** Индекс дедубдикации по номеру заказа */

        $Deduplicator = $this->deduplicator
            ->namespace('yandex-market-orders')
            ->deduplication([
                $message->getOrderNumber(),
                self::class,
            ]);

        if($Deduplicator->isExecuted())
        {
            return;
        }

        $orders = $this->CurrentOrderEventByNumberRepository->findAll($message->getOrderNumber());

        if(empty($orders))
        {
            return;
        }


        foreach($orders as $OrderEvent)
        {
            if(
                true === $OrderEvent->isStatusEquals(OrderStatusCanceled::class)
                || true === $OrderEvent->isStatusEquals(OrderStatusMarketplace::class)
                || true === $OrderEvent->isDanger()
            )
            {
                $Deduplicator->save();

                return;
            }
        }


        $arrOrdersCancel = $this->cancelYaMarketOrderStatusHandler->handle($message);

        /**
         * Если заказов для отмены не найдено
         */

        if(empty($arrOrdersCancel))
        {
            $this->logger->critical(
                sprintf('yandex-market-orders: Не найдено отправления для отмены заказа %s', $message->getOrderNumber()),
                [self::class.':'.__LINE__],
            );

            $Deduplicator->save();

            return;
        }


        /**
         * Если имеются заказы для отмены - скрываем их идентификатор
         */

        $this->logger->info(
            sprintf('Отменили заказ %s', $message->getOrderNumber()),
            [self::class.':'.__LINE__],
        );

        $Deduplicator->save();

        foreach($arrOrdersCancel as $Order)
        {
            /**
             * Скрываем идентификатор у всех пользователей
             */

            $this->publish
                ->addData(['profile' => false]) // Скрывает у всех
                ->addData(['identifier' => (string) $Order->getId()])
                ->send('remove');


            $this->publish
                ->addData(['profile' => false]) // Скрывает у всех
                ->addData(['order' => (string) $Order->getId()])
                ->send('orders');
        }

    }
}
