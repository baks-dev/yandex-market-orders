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
use BaksDev\Yandex\Market\Orders\UseCase\New\YandexMarketOrderDTO;
use BaksDev\Yandex\Market\Orders\UseCase\Unpaid\UnpaidYaMarketOrderStatusHandler;
use BaksDev\Yandex\Market\Repository\YaMarketTokenExtraCompany\YaMarketTokenExtraCompanyInterface;
use Generator;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class UnpaidYaMarketOrderScheduleHandler
{
    private LoggerInterface $logger;

    public function __construct(
        private readonly GetYaMarketOrdersUnpaidRequest $yandexMarketUnpaidOrdersRequest,
        private readonly UnpaidYaMarketOrderStatusHandler $unpaidYandexMarketHandler,
        private readonly YaMarketTokenExtraCompanyInterface $tokenExtraCompany,
        private readonly DeduplicatorInterface $deduplicator,
        LoggerInterface $yandexMarketOrdersLogger,
    )
    {
        $this->logger = $yandexMarketOrdersLogger;
    }

    public function __invoke(UnpaidYaMarketOrdersScheduleMessage $message): void
    {
        $Deduplicator = $this->deduplicator
            ->namespace('yandex-market-orders')
            ->deduplication([
                self::class,
                $message->getProfile(),
            ]);

        if($Deduplicator->isExecuted())
        {
            $this->logger->debug(sprintf('Пропускаем неоплаченные заказы профиля %s', $message->getProfile()));
            return;
        }

        $this->logger->debug(sprintf('Получаем НЕОПЛАЧЕННЫЕ заказы профиля %s', $message->getProfile()));
        $Deduplicator->save();


        /**
         * Получаем список НЕОПЛАЧЕННЫХ сборочных заданий по основному идентификатору компании
         */
        $orders = $this->yandexMarketUnpaidOrdersRequest
            ->profile($message->getProfile())
            ->findAll();

        if($orders->valid())
        {
            $this->ordersUnpaid($orders, $message->getProfile());
        }

        /**
         * Получаем заказы по дополнительным идентификаторам
         */

        $extra = $this->tokenExtraCompany->profile($message->getProfile())->execute();

        if(false === $extra)
        {
            $Deduplicator->delete();
            return;
        }

        foreach($extra as $company)
        {
            $orders = $this->yandexMarketUnpaidOrdersRequest
                ->setExtraCompany($company['company'])
                ->findAll();

            if(false === $orders->valid())
            {
                continue;
            }

            $this->ordersUnpaid($orders, $message->getProfile());
        }

        $Deduplicator->delete();
    }


    private function ordersUnpaid(Generator $orders, UserProfileUid $profile): void
    {
        /** @var YandexMarketOrderDTO $order */
        foreach($orders as $order)
        {
            $order->resetProfile($profile);

            $handle = $this->unpaidYandexMarketHandler->handle($order);

            if($handle instanceof Order)
            {
                $this->logger->info(
                    sprintf('Создали неоплаченный заказ %s', $order->getNumber()),
                    [
                        self::class.':'.__LINE__,
                        'attr' => (string) $profile->getAttr(),
                        'profile' => (string) $profile,
                    ]
                );
            }
        }
    }
}
