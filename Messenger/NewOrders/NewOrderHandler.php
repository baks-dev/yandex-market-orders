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

namespace BaksDev\Yandex\Market\Orders\Messenger\NewOrders;

use BaksDev\Core\Messenger\MessageDispatchInterface;
use BaksDev\Orders\Order\Entity\Order;
use BaksDev\Orders\Order\Repository\ExistsOrderNumber\ExistsOrderNumberInterface;
use BaksDev\Orders\Order\Type\Status\OrderStatus\OrderStatusCanceled;
use BaksDev\Orders\Order\UseCase\Admin\Edit\EditOrderDTO;
use BaksDev\Orders\Order\UseCase\Admin\Edit\EditOrderHandler;
use BaksDev\Orders\Order\UseCase\Admin\Edit\Products\OrderProductDTO;
use BaksDev\Orders\Order\UseCase\Admin\Edit\Products\Price\OrderPriceDTO;
use BaksDev\Orders\Order\UseCase\Admin\Status\OrderStatusDTO;
use BaksDev\Orders\Order\UseCase\Admin\Status\OrderStatusHandler;
use BaksDev\Products\Product\Repository\ProductByVariation\ProductByVariationInterface;
use BaksDev\Reference\Money\Type\Money;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
use BaksDev\Wildberries\Api\Token\Orders\WildberriesOrdersNew;
use BaksDev\Wildberries\Orders\Entity\WbOrders;
use BaksDev\Wildberries\Orders\Repository\WbOrdersById\WbOrdersByIdInterface;
use BaksDev\Wildberries\Orders\Type\Email\ClientEmail;
use BaksDev\Wildberries\Orders\Type\OrderStatus\Status\WbOrderStatusNew;
use BaksDev\Wildberries\Orders\Type\WildberriesStatus\Status\WildberriesStatusWaiting;
use BaksDev\Wildberries\Orders\UseCase\Command\New\CreateWbOrderDTO;
use BaksDev\Wildberries\Orders\UseCase\Command\New\CreateWbOrderHandler;
use BaksDev\Wildberries\Products\Entity\Cards\WbProductCard;
use BaksDev\Wildberries\Products\Entity\Cards\WbProductCardOffer;
use BaksDev\Wildberries\Products\Entity\Cards\WbProductCardVariation;
use BaksDev\Wildberries\Products\Messenger\WbCardNew\WbCardNewMessage;
use BaksDev\Wildberries\Products\UseCase\Cards\NewEdit\Variation\WbProductCardVariationDTO;
use BaksDev\Yandex\Market\Orders\Api\YandexMarketNewOrdersRequest;
use BaksDev\Yandex\Market\Orders\UseCase\New\YandexMarketOrderDTO;
use BaksDev\Yandex\Market\Orders\UseCase\New\YandexMarketOrderHandler;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class NewOrderHandler
{
    private LoggerInterface $logger;
    private YandexMarketNewOrdersRequest $yandexMarketNewOrdersRequest;
    private ExistsOrderNumberInterface $existsOrderNumber;
    private YandexMarketOrderHandler $yandexMarketOrderHandler;

    public function __construct(
        LoggerInterface $yandexMarketOrdersLogger,
        YandexMarketNewOrdersRequest $yandexMarketNewOrdersRequest,
        ExistsOrderNumberInterface $existsOrderNumber,
        YandexMarketOrderHandler $yandexMarketOrderHandler
    )
    {
        $this->yandexMarketNewOrdersRequest = $yandexMarketNewOrdersRequest;
        $this->existsOrderNumber = $existsOrderNumber;
        $this->logger = $yandexMarketOrdersLogger;
        $this->yandexMarketOrderHandler = $yandexMarketOrderHandler;
    }

    public function __invoke(NewOrdersMessage $message): void
    {
        /* Получить список новых сборочных заданий */
        $orders = $this->yandexMarketNewOrdersRequest
            ->profile($message->getProfile())
            ->findAll();

        if(!$orders->valid())
        {
            return;
        }

        /** @var YandexMarketOrderDTO $order */
        foreach($orders as $order)
        {
            if($this->existsOrderNumber->isExists($order->getNumber()))
            {
                continue;
            }

            /**
             * Создаем системный заказ
             */
            $handle = $this->yandexMarketOrderHandler->handle($order);

            if($handle instanceof Order)
            {
                $this->logger->info(
                    sprintf('Добавили новый заказ %s', $order->getNumber()),
                    [
                        __FILE__.':'.__LINE__,
                        'attr' => (string) $message->getProfile()->getAttr(),
                        'profile' => (string) $message->getProfile(),
                    ]
                );

                continue;
            }


            $this->logger->critical(
                sprintf('%s: Ошибка при добавлении заказа %s', $handle, $order->getNumber()),
                [
                    __FILE__.':'.__LINE__,
                    'attr' => (string) $message->getProfile()->getAttr(),
                    'profile' => (string) $message->getProfile(),
                ]
            );
        }
    }
}