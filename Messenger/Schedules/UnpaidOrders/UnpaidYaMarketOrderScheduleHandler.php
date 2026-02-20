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

use BaksDev\Core\Deduplicator\DeduplicatorInterface;
use BaksDev\Orders\Order\Entity\Order;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
use BaksDev\Yandex\Market\Orders\Api\GetYaMarketOrdersUnpaidRequest;
use BaksDev\Yandex\Market\Orders\Schedule\UnpaidOrders\UnpaidOrdersSchedule;
use BaksDev\Yandex\Market\Orders\UseCase\New\NewYaMarketOrderDTO;
use BaksDev\Yandex\Market\Orders\UseCase\Unpaid\UnpaidYaMarketOrderStatusHandler;
use BaksDev\Yandex\Market\Repository\YaMarketTokenExtraCompany\YaMarketTokenExtraCompanyInterface;
use BaksDev\Yandex\Market\Repository\YaMarketTokensByProfile\YaMarketTokensByProfileInterface;
use BaksDev\Yandex\Market\Type\Id\YaMarketTokenUid;
use Generator;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class UnpaidYaMarketOrderScheduleHandler
{
    public function __construct(
        #[Target('yandexMarketOrdersLogger')] private LoggerInterface $logger,
        private GetYaMarketOrdersUnpaidRequest $yandexMarketUnpaidOrdersRequest,
        private UnpaidYaMarketOrderStatusHandler $unpaidYandexMarketHandler,
        private YaMarketTokensByProfileInterface $YaMarketTokensByProfile,
        private DeduplicatorInterface $deduplicator,
    ) {}

    public function __invoke(UnpaidYaMarketOrdersScheduleMessage $message): void
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
                ->expiresAfter(UnpaidOrdersSchedule::INTERVAL)
                ->deduplication([
                    self::class,
                    (string) $YaMarketTokenUid,
                ]);

            if($Deduplicator->isExecuted())
            {
                return;
            }

            /*  @see строку :105 */
            $Deduplicator->save();

            /**
             * Получаем список НЕОПЛАЧЕННЫХ сборочных заданий по основному идентификатору компании
             */
            $orders = $this->yandexMarketUnpaidOrdersRequest
                ->forTokenIdentifier($YaMarketTokenUid)
                ->findAll();

            if($orders->valid())
            {
                $this->ordersUnpaid($orders, $message->getProfile());
            }

            $Deduplicator->delete();
        }
    }

    private function ordersUnpaid(Generator $orders, UserProfileUid $profile): void
    {
        /** @var NewYaMarketOrderDTO $YandexMarketOrderDTO */
        foreach($orders as $YandexMarketOrderDTO)
        {
            /** Индекс дедубдикации по номеру заказа */
            $Deduplicator = $this->deduplicator
                ->namespace('yandex-market-orders')
                ->expiresAfter('1 day')
                ->deduplication([
                    $YandexMarketOrderDTO->getPostingNumber(),
                    self::class,
                ]);

            if($Deduplicator->isExecuted())
            {
                continue;
            }

            $handle = $this->unpaidYandexMarketHandler->handle($YandexMarketOrderDTO);

            if($handle instanceof Order)
            {
                $this->logger->info(
                    sprintf('Создали неоплаченный заказ %s', $YandexMarketOrderDTO->getPostingNumber()),
                    [
                        self::class.':'.__LINE__,
                        'attr' => (string) $profile->getAttr(),
                        'profile' => (string) $profile,
                    ],
                );

                $Deduplicator->save();
            }
        }
    }
}
