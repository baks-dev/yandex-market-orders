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

use BaksDev\Core\Deduplicator\DeduplicatorInterface;
use BaksDev\Orders\Order\Entity\Order;
use BaksDev\Orders\Order\Repository\CurrentOrderNumber\CurrentOrderNumberInterface;
use BaksDev\Orders\Order\Type\Status\OrderStatus\OrderStatusCanceled;
use BaksDev\Orders\Order\UseCase\Admin\Canceled\CanceledOrderDTO;
use BaksDev\Orders\Order\UseCase\Admin\Edit\EditOrderDTO;
use BaksDev\Orders\Order\UseCase\Admin\Status\OrderStatusHandler;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
use BaksDev\Yandex\Market\Orders\Api\Canceled\YaMarketCancelOrdersRequest;
use BaksDev\Yandex\Market\Orders\UseCase\New\YandexMarketOrderDTO;
use BaksDev\Yandex\Market\Orders\UseCase\Status\Cancel\CancelYaMarketOrderStatusHandler;
use BaksDev\Yandex\Market\Repository\YaMarketTokenExtraCompany\YaMarketTokenExtraCompanyInterface;
use DateInterval;
use Generator;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class CancelYaMarketOrderScheduleHandler
{
    private LoggerInterface $logger;

    public function __construct(
        private readonly YaMarketCancelOrdersRequest $yandexMarketCancelOrdersRequest,
        private readonly CancelYaMarketOrderStatusHandler $cancelYaMarketOrderStatusHandler,
        private readonly YaMarketTokenExtraCompanyInterface $tokenExtraCompany,
        private readonly DeduplicatorInterface $deduplicator,
        LoggerInterface $yandexMarketOrdersLogger,
    ) {
        $this->logger = $yandexMarketOrdersLogger;
    }


    public function __invoke(CancelYaMarketOrdersScheduleMessage $message): void
    {
        /**
         * Получаем список ОТМЕНЕННЫХ сборочных заданий по основному идентификатору компании
         */

        $orders = $this->yandexMarketCancelOrdersRequest
            ->profile($message->getProfile())
            ->findAll();

        if($orders->valid())
        {
            $this->ordersCancel($orders, $message->getProfile());
        }

        /**
         * Получаем заказы по дополнительным идентификаторам
         */

        $extra = $this->tokenExtraCompany->profile($message->getProfile())->execute();

        if($extra !== false)
        {
            foreach($extra as $company)
            {
                $orders = $this->yandexMarketCancelOrdersRequest
                    ->setExtraCompany($company['company'])
                    ->findAll();

                if($orders->valid())
                {
                    $this->ordersCancel($orders, $message->getProfile());
                }
            }
        }
    }


    private function ordersCancel(Generator $orders, UserProfileUid $profile): void
    {
        /** @var YandexMarketOrderDTO $YandexMarketOrderDTO */
        foreach($orders as $YandexMarketOrderDTO)
        {
            /** Индекс дедубдикации по номеру заказа */
            $Deduplicator = $this->deduplicator
                ->namespace('yandex-market-orders')
                ->expiresAfter(DateInterval::createFromDateString('1 day'))
                ->deduplication([$YandexMarketOrderDTO->getNumber(), md5(self::class)]);

            if($Deduplicator->isExecuted())
            {
                continue;
            }

            $handle = $this->cancelYaMarketOrderStatusHandler->handle($YandexMarketOrderDTO, $profile);

            if($handle instanceof Order)
            {
                $this->logger->info(
                    sprintf('Отменили заказ %s', $YandexMarketOrderDTO->getNumber()),
                    [
                        self::class.':'.__LINE__,
                        'attr' => (string) $profile->getAttr(),
                        'profile' => (string) $profile,
                    ]
                );

                continue;
            }

            if($handle !== false)
            {
                $this->logger->critical(
                    sprintf('Yandex: Ошибка при отмене заказа %s (%s)', $YandexMarketOrderDTO->getNumber(), $handle),
                    [
                        self::class.':'.__LINE__,
                        'attr' => (string) $profile->getAttr(),
                        'profile' => (string) $profile,
                    ]
                );
            }

            $Deduplicator->save();
        }
    }
}
