<?php
/*
 *  Copyright 2024.  Baks.dev <admin@baks.dev>
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

namespace BaksDev\Yandex\Market\Orders\UseCase\Unpaid;

use BaksDev\Orders\Order\Entity\Order;
use BaksDev\Orders\Order\Repository\CurrentOrderEvent\CurrentOrderEventInterface;
use BaksDev\Orders\Order\Repository\ExistsOrderNumber\ExistsOrderNumberInterface;
use BaksDev\Orders\Order\UseCase\Admin\Status\OrderStatusHandler;
use BaksDev\Users\Profile\UserProfile\Repository\UserByUserProfile\UserByUserProfileInterface;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
use BaksDev\Yandex\Market\Orders\UseCase\New\YandexMarketOrderDTO;
use BaksDev\Yandex\Market\Orders\UseCase\New\YandexMarketOrderHandler;
use BaksDev\Yandex\Market\Orders\UseCase\Unpaid\UnpaidYaMarketOrderStatusDTO;
use Psr\Log\LoggerInterface;

final class UnpaidYaMarketOrderHandler
{
    private LoggerInterface $logger;

    public function __construct(
        private readonly YandexMarketOrderHandler $yandexMarketOrderHandler,
        private readonly CurrentOrderEventInterface $currentOrderEvent,
        private readonly UserByUserProfileInterface $userByUserProfile,
        private readonly OrderStatusHandler $orderStatusHandler,
        private readonly ExistsOrderNumberInterface $existsOrderNumber,
        LoggerInterface $yandexMarketOrdersLogger
    ) {
        $this->logger = $yandexMarketOrdersLogger;
    }

    /** @see YandexMarket */
    public function handle(YandexMarketOrderDTO $command): string|Order
    {
        $isExists = $this->existsOrderNumber->isExists($command->getNumber());

        if($isExists)
        {
            return 'Невозможно создать заказ';
        }

        /**
         * Присваиваем ограничение по идентификатору пользователя
         */

        $NewOrderInvariable = $command->getInvariable();

        $User = $this->userByUserProfile
            ->withProfile($NewOrderInvariable->getProfile())
            ->findUser();

        if($User === false)
        {
            $this->logger->critical(sprintf(
                'Пользователь профиля %s для заказа %s не найден',
                $NewOrderInvariable->getProfile(),
                $NewOrderInvariable->getNumber()
            ));

            return 'Пользователь по профилю не найден';
        }


        $NewOrderInvariable->setUsr($User->getId());

        $handle = $this->yandexMarketOrderHandler->handle($command);

        /**
         * Если был создан заказ - переводим в статус «не оплачено»
         */

        if($handle instanceof Order)
        {
            $OrderEvent = $this
                ->currentOrderEvent
                ->order($handle->getId())
                ->getCurrentOrderEvent();

            if($OrderEvent === null)
            {
                $this->logger->critical(
                    sprintf('Не смогли получить «Новый» заказ %s для перевода в статус «Не оплачено»', $handle->getNumber())
                );

                return '';
            }

            /** Обновляем статус «Не оплачено» */
            $UnpaidOrderStatusDTO = new UnpaidYaMarketOrderStatusDTO();
            $OrderEvent->getDto($UnpaidOrderStatusDTO);

            if($UnpaidOrderStatusDTO->isStatusNew())
            {
                $UnpaidOrderStatusDTO->setUnpaidStatus();
                $handle = $this->orderStatusHandler->handle($UnpaidOrderStatusDTO);
            }
        }

        return $handle;
    }
}
