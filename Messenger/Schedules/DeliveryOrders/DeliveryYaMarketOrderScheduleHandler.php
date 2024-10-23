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

namespace BaksDev\Yandex\Market\Orders\Messenger\Schedules\DeliveryOrders;

use BaksDev\Core\Deduplicator\DeduplicatorInterface;
use BaksDev\Products\Stocks\Entity\ProductStock;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
use BaksDev\Yandex\Market\Orders\Api\Completed\GetYaMarketOrdersCompletedRequest;
use BaksDev\Yandex\Market\Orders\Api\Completed\YaMarketCompletedOrderDTO;
use BaksDev\Yandex\Market\Orders\UseCase\Status\Completed\CompletedYaMarketOrderStatusHandler;
use BaksDev\Yandex\Market\Repository\YaMarketTokenExtraCompany\YaMarketTokenExtraCompanyInterface;
use DateInterval;
use Generator;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class DeliveryYaMarketOrderScheduleHandler
{
    private LoggerInterface $logger;

    public function __construct(
        private readonly GetYaMarketOrdersCompletedRequest $GetYaMarketOrdersCompletedRequest,
        private readonly CompletedYaMarketOrderStatusHandler $CompletedYaMarketOrderStatusHandler,
        private readonly YaMarketTokenExtraCompanyInterface $tokenExtraCompany,
        private readonly DeduplicatorInterface $deduplicator,
        LoggerInterface $yandexMarketOrdersLogger,
    )
    {
        $this->logger = $yandexMarketOrdersLogger;
    }


    /**
     * Получаем заказы в доставке и применяем статус Completed
     * (заказ считается доставленным, если передан в службу доставки)
     */
    public function __invoke(DeliveryYaMarketOrdersScheduleMessage $message): void
    {
        /**
         * Получаем список ПЕРЕДАННЫХ службе доставки сборочных заданий по основному идентификатору компании
         */

        $orders = $this->GetYaMarketOrdersCompletedRequest
            ->profile($message->getProfile())
            ->findAll();

        if($orders->valid())
        {
            $this->ordersComplete($orders, $message->getProfile());
        }

        /**
         * Получаем заказы по дополнительным идентификаторам
         */

        $extra = $this->tokenExtraCompany->profile($message->getProfile())->execute();

        if(false === $extra)
        {
            return;
        }

        foreach($extra as $company)
        {
            $orders = $this->GetYaMarketOrdersCompletedRequest
                ->setExtraCompany($company['company'])
                ->findAll();

            if(false === $orders->valid())
            {
                continue;
            }

            $this->ordersComplete($orders, $message->getProfile());
        }
    }


    private function ordersComplete(Generator $orders, UserProfileUid $profile): void
    {
        /** @var YaMarketCompletedOrderDTO $YaMarketCompletedOrderDTO */
        foreach($orders as $YaMarketCompletedOrderDTO)
        {
            /** Индекс дедубдикации по номеру заказа */
            $Deduplicator = $this->deduplicator
                ->namespace('yandex-market-orders')
                ->expiresAfter(DateInterval::createFromDateString('1 day'))
                ->deduplication([
                    $YaMarketCompletedOrderDTO->getNumber(),
                    self::class
                ]);

            if($Deduplicator->isExecuted())
            {
                continue;
            }

            $handle = $this->CompletedYaMarketOrderStatusHandler->handle($YaMarketCompletedOrderDTO, $profile);

            if($handle instanceof ProductStock)
            {
                $this->logger->info(
                    sprintf('Выполнили складскую заявку %s (доставлен в ПВЗ)', $YaMarketCompletedOrderDTO->getNumber()),
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
                    sprintf('yandex-market-orders: Ошибка при отметке о выполнении складской заявки %s (%s)', $YaMarketCompletedOrderDTO->getNumber(), $handle),
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
