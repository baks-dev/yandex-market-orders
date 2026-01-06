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

namespace BaksDev\Yandex\Market\Orders\Messenger\Schedules\NewOrders;

use BaksDev\Core\Deduplicator\DeduplicatorInterface;
use BaksDev\Orders\Order\Entity\Order;
use BaksDev\Yandex\Market\Orders\Api\GetYaMarketOrdersNewRequest;
use BaksDev\Yandex\Market\Orders\Schedule\NewOrders\NewOrdersSchedule;
use BaksDev\Yandex\Market\Orders\UseCase\New\YandexMarketOrderDTO;
use BaksDev\Yandex\Market\Orders\UseCase\New\YandexMarketOrderHandler;
use BaksDev\Yandex\Market\Repository\YaMarketTokenExtraCompany\YaMarketTokenExtraCompanyInterface;
use BaksDev\Yandex\Market\Repository\YaMarketTokensByProfile\YaMarketTokensByProfileInterface;
use BaksDev\Yandex\Market\Type\Id\YaMarketTokenUid;
use Generator;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class NewYaMarketOrderScheduleHandler
{
    public function __construct(
        #[Target('yandexMarketOrdersLogger')] private LoggerInterface $logger,
        private GetYaMarketOrdersNewRequest $yandexMarketNewOrdersRequest,
        private YandexMarketOrderHandler $yandexMarketOrderHandler,
        private YaMarketTokensByProfileInterface $YaMarketTokensByProfile,
        private DeduplicatorInterface $deduplicator,
    ) {}

    public function __invoke(NewYaMarketOrdersScheduleMessage $message): void
    {
        /** Получаем все токены профиля */

        $tokensByProfile = $this->YaMarketTokensByProfile->findAll($message->getProfile());

        if(false === $tokensByProfile || false === $tokensByProfile->valid())
        {
            return;
        }

        /** @var YaMarketTokenUid $YaMarketTokenUid */
        foreach($tokensByProfile as $YaMarketTokenUid)
        {
            /**
             * Ограничиваем периодичность запросов
             */

            $Deduplicator = $this->deduplicator
                ->namespace('yandex-market-orders')
                ->expiresAfter(NewOrdersSchedule::INTERVAL)
                ->deduplication([self::class, (string) $YaMarketTokenUid]);

            if($Deduplicator->isExecuted())
            {
                continue;
            }

            /** Добавляем дедубликатор обновления (удалям в конце данного процесса) */
            $Deduplicator->save();

            /**
             * Получаем список НОВЫХ сборочных заданий по основному идентификатору компании
             */

            $orders = $this->yandexMarketNewOrdersRequest
                ->forTokenIdentifier($YaMarketTokenUid)
                ->findAll();

            if(false === $orders || false === $orders->valid())
            {
                $Deduplicator->delete();
                continue;
            }

            $this->ordersCreate($orders);

            /** Удаляем дедубликатор обновления */
            $Deduplicator->delete();
        }
    }

    /** @param Generator<YandexMarketOrderDTO> $orders */
    private function ordersCreate(Generator $orders): void
    {
        foreach($orders as $YandexMarketOrderDTO)
        {
            /** Индекс дедубдикации по номеру заказа */
            $Deduplicator = $this->deduplicator
                ->namespace('yandex-market-orders')
                ->deduplication([
                    $YandexMarketOrderDTO->getNumber(),
                    self::class,
                ]);

            if($Deduplicator->isExecuted())
            {
                continue;
            }

            $handle = $this->yandexMarketOrderHandler->handle($YandexMarketOrderDTO);

            if($handle instanceof Order)
            {
                $this->logger->info(
                    sprintf('Добавили новый заказ %s', $YandexMarketOrderDTO->getNumber()),
                    [self::class.':'.__LINE__],
                );

                $Deduplicator->save();
            }
        }
    }
}
