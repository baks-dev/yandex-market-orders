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
use BaksDev\Orders\Order\Type\Status\OrderStatus\Collection\OrderStatusNew;
use BaksDev\Orders\Order\Type\Status\OrderStatus\Collection\OrderStatusUnpaid;
use BaksDev\Orders\Order\UseCase\Admin\Status\OrderStatusHandler;
use BaksDev\Users\Profile\UserProfile\Repository\UserByUserProfile\UserByUserProfileInterface;
use BaksDev\Yandex\Market\Orders\UseCase\New\YandexMarketOrderDTO;
use BaksDev\Yandex\Market\Orders\UseCase\New\YandexMarketOrderHandler;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;

final readonly class UnpaidYaMarketOrderStatusHandler
{
    public function __construct(
        #[Target('yandexMarketOrdersLogger')] private LoggerInterface $logger,
        private YandexMarketOrderHandler $yandexMarketOrderHandler,
        private CurrentOrderEventInterface $currentOrderEvent,
        private UserByUserProfileInterface $userByUserProfile,
        private OrderStatusHandler $orderStatusHandler,
        private ExistsOrderNumberInterface $existsOrderNumber,

    ) {}

    /** @see YandexMarket */
    public function handle(YandexMarketOrderDTO $command): string|Order
    {
        /** Не добавляем неоплаченный заказ, если он не «В ожидании оплаты» */
        if(false === $command->getStatusEquals(OrderStatusUnpaid::class))
        {
            return '';
        }

        /** Не добавляем заказ, если он уже создан */
        $isExists = $this->existsOrderNumber->isExists($command->getNumber());

        if($isExists)
        {
            return sprintf('%s: Ошибка при создании заказа неоплаченного (заказ уже добавлен)!', $command->getNumber());
        }

        /**
         * Присваиваем идентификатор пользователя @UserUid по идентификатору профиля @UserProfileUid
         */

        $NewOrderInvariable = $command->getInvariable();
        $UserProfileUid = $NewOrderInvariable->getProfile();

        $User = $this->userByUserProfile
            ->forProfile($UserProfileUid)
            ->find();

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


        /** Создаем заказ со статусом New «Новый» */
        $command->setStatus(OrderStatusNew::class);
        $handle = $this->yandexMarketOrderHandler->handle($command);

        /**
         * Если был создан заказ - сразу переводим в статус «не оплачено»
         */

        if($handle instanceof Order)
        {
            $OrderEvent = $this
                ->currentOrderEvent
                ->forOrder($handle->getId())
                ->find();

            if(false === $OrderEvent)
            {
                $this->logger->critical(
                    sprintf('Не смогли получить «Новый» заказ %s для перевода в статус «Не оплачено»', $command->getNumber())
                );

                return '';
            }

            if(false === $OrderEvent->isStatusEquals(OrderStatusNew::class))
            {
                $this->logger->critical(
                    sprintf('Не смогли получить «Новый» заказ %s для перевода в статус «Не оплачено»', $command->getNumber())
                );

                return '';
            }

            /**
             * Обновляем статус «Не оплачено»
             * В статус «Не оплачено» может перевестись только новый заказ
             */

            $UnpaidOrderStatusDTO = new UnpaidYaMarketOrderStatusDTO($User, $UserProfileUid);
            $OrderEvent->getDto($UnpaidOrderStatusDTO);

            $UnpaidOrderStatusDTO->setUnpaidStatus();
            $handle = $this->orderStatusHandler->handle($UnpaidOrderStatusDTO);

        }

        return $handle;
    }
}
