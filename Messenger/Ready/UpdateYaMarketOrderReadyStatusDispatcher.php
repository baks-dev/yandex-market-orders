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

namespace BaksDev\Yandex\Market\Orders\Messenger\Ready;


use BaksDev\Core\Deduplicator\DeduplicatorInterface;
use BaksDev\Core\Messenger\MessageDelay;
use BaksDev\Core\Messenger\MessageDispatchInterface;
use BaksDev\Yandex\Market\Orders\Api\UpdateYaMarketOrderReadyStatusRequest;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Отправляем информацию о принятом в обработку заказе
 */
#[AsMessageHandler(priority: 0)]
final readonly class UpdateYaMarketOrderReadyStatusDispatcher
{
    public function __construct(
        #[Target('yandexMarketOrdersLogger')] private LoggerInterface $logger,
        private UpdateYaMarketOrderReadyStatusRequest $updateYaMarketOrderReadyStatusRequest,
        private MessageDispatchInterface $messageDispatch,
        private DeduplicatorInterface $deduplicator,
    ) {}

    public function __invoke(UpdateYaMarketOrderReadyStatusMessage $message): void
    {
        /** Дедубликатор по номеру заказа */
        $Deduplicator = $this->deduplicator
            ->namespace('orders-order')
            ->deduplication([
                $message->getOrderNumber(),
                self::class,
            ]);

        if($Deduplicator->isExecuted() === true)
        {
            return;
        }

        $isUpdate = $this
            ->updateYaMarketOrderReadyStatusRequest
            ->forTokenIdentifier($message->getTokenIdentifier())
            ->update($message->getOrderNumber());

        if(false === $isUpdate)
        {
            /**
             * Пробуем повторить попытку позже
             */

            $this->messageDispatch->dispatch(
                message: $message,
                stamps: [new MessageDelay('1 minutes')],
                transport: $message->getUserProfile().'-low',
            );

            $this->logger->critical(
                sprintf('%s: Ошибка при обновлении информации о принятом в обработку заказе', $message->getOrderNumber()),
                [self::class.':'.__LINE__],
            );

            return;
        }

        $this->logger->info(
            sprintf('%s: Отправили информацию о принятом в обработку заказе', $message->getOrderNumber()),
            [self::class.':'.__LINE__],
        );

        $Deduplicator->save();

    }
}
