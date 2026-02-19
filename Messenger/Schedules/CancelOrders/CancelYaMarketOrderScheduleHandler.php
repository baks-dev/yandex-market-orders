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

use BaksDev\Centrifugo\Server\Publish\CentrifugoPublishInterface;
use BaksDev\Core\Deduplicator\DeduplicatorInterface;
use BaksDev\Orders\Order\Entity\Order;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
use BaksDev\Yandex\Market\Orders\Api\Canceled\GetYaMarketOrdersCancelRequest;
use BaksDev\Yandex\Market\Orders\Api\Canceled\YaMarketCancelOrderDTO;
use BaksDev\Yandex\Market\Orders\Schedule\CancelOrders\CancelOrdersSchedule;
use BaksDev\Yandex\Market\Orders\UseCase\Status\Cancel\CancelYaMarketOrderStatusHandler;
use BaksDev\Yandex\Market\Repository\YaMarketTokenExtraCompany\YaMarketTokenExtraCompanyInterface;
use BaksDev\Yandex\Market\Repository\YaMarketTokensByProfile\YaMarketTokensByProfileInterface;
use BaksDev\Yandex\Market\Type\Id\YaMarketTokenUid;
use Generator;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CancelYaMarketOrderScheduleHandler
{
    public function __construct(
        #[Target('yandexMarketOrdersLogger')] private LoggerInterface $logger,
        private GetYaMarketOrdersCancelRequest $yandexMarketCancelOrdersRequest,
        private CancelYaMarketOrderStatusHandler $cancelYaMarketOrderStatusHandler,
        private YaMarketTokensByProfileInterface $YaMarketTokensByProfile,
        private DeduplicatorInterface $deduplicator,
        private CentrifugoPublishInterface $publish,
    ) {}

    public function __invoke(CancelYaMarketOrdersScheduleMessage $message): void
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
                ->expiresAfter(CancelOrdersSchedule::INTERVAL)
                ->deduplication([self::class, (string) $YaMarketTokenUid]);

            if($Deduplicator->isExecuted())
            {
                return;
            }

            /* @see строку :106 */
            $Deduplicator->save();

            /**
             * Получаем список ОТМЕНЕННЫХ сборочных заданий по основному идентификатору компании
             */

            $orders = $this->yandexMarketCancelOrdersRequest
                ->forTokenIdentifier($YaMarketTokenUid)
                ->findAll();

            if(false === $orders || false === $orders->valid())
            {
                $Deduplicator->delete();
                continue;
            }

            $this->ordersCancel($orders, $message->getProfile());
            $Deduplicator->delete();
        }
    }

    private function ordersCancel(Generator $orders, UserProfileUid $profile): void
    {
        /** @var YaMarketCancelOrderDTO $YandexMarketOrderDTO */
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

            $Order = $this->cancelYaMarketOrderStatusHandler->handle($YandexMarketOrderDTO);

            if($Order instanceof Order)
            {
                $this->logger->info(
                    sprintf('Отменили заказ %s', $YandexMarketOrderDTO->getPostingNumber()),
                    [
                        self::class.':'.__LINE__,
                        'attr' => (string) $profile->getAttr(),
                        'profile' => (string) $profile,
                    ],
                );

                /** Скрываем идентификатор у всех пользователей */
                $this->publish
                    ->addData(['profile' => false]) // Скрывает у всех
                    ->addData(['identifier' => (string) $Order->getId()])
                    ->send('remove');

                $this->publish
                    ->addData(['profile' => false]) // Скрывает у всех
                    ->addData(['order' => (string) $Order->getId()])
                    ->send('orders');

                $Deduplicator->save();

                continue;
            }

            if($Order !== false)
            {
                $this->logger->critical(
                    sprintf('Yandex: Ошибка при отмене заказа %s (%s)', $YandexMarketOrderDTO->getPostingNumber(), $Order),
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
