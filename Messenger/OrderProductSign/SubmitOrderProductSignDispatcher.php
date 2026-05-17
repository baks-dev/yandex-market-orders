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

namespace BaksDev\Yandex\Market\Orders\Messenger\OrderProductSign;


use BaksDev\Core\Deduplicator\DeduplicatorInterface;
use BaksDev\Orders\Order\Entity\Event\OrderEvent;
use BaksDev\Orders\Order\Repository\CurrentOrderEvent\CurrentOrderEventInterface;
use BaksDev\Orders\Order\Repository\CurrentOrderNumber\CurrentOrderEventByNumberInterface;
use BaksDev\Orders\Order\Type\Id\OrderUid;
use BaksDev\Ozon\Orders\Api\Exemplar\UpdateOzonOrdersExemplarRequest;
use BaksDev\Ozon\Orders\Api\GetOzonOrderInfoRequest;
use BaksDev\Ozon\Orders\Type\DeliveryType\TypeDeliveryFbsOzon;
use BaksDev\Products\Sign\Entity\Event\ProductSignEvent;
use BaksDev\Products\Sign\Messenger\ProductSignMessage;
use BaksDev\Products\Sign\Repository\CurrentEvent\ProductSignCurrentEventInterface;
use BaksDev\Products\Sign\Repository\ProductSignByOrder\ProductSignByOrderInterface;
use BaksDev\Products\Sign\Type\Status\ProductSignStatus\ProductSignStatusProcess;
use BaksDev\Yandex\Market\Orders\Api\Boxes\UpdateSignYaMarketProductRequest;
use BaksDev\Yandex\Market\Orders\Api\GetYaMarketOrderInfoRequest;
use BaksDev\Yandex\Market\Orders\Type\DeliveryType\TypeDeliveryFbsYaMarket;
use BaksDev\Yandex\Market\Orders\UseCase\New\NewYaMarketOrderByBusinessDTO;
use BaksDev\Yandex\Market\Type\Id\YaMarketTokenUid;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[Autoconfigure(shared: false)]
#[AsMessageHandler(priority: 0)]
final class SubmitOrderProductSignDispatcher
{
    public function __construct(
        #[Target('yandexMarketOrdersLogger')] private LoggerInterface $Logger,
        private DeduplicatorInterface $deduplicator,
        private readonly ProductSignCurrentEventInterface $ProductSignCurrentEventRepository,
        private readonly CurrentOrderEventInterface $CurrentOrderEventRepository,
        private readonly GetYaMarketOrderInfoRequest $GetYaMarketOrderInfoRequest,
        private readonly UpdateSignYaMarketProductRequest $UpdateSignYaMarketProductRequest,
        private readonly CurrentOrderEventByNumberInterface $CurrentOrderEventByNumberRepository,
        private readonly ProductSignByOrderInterface $ProductSignByOrderRepository,

    ) {}

    public function __invoke(ProductSignMessage $message): void
    {
        /** Дедубликатор по идентификатору честного знака */
        $Deduplicator = $this->deduplicator
            ->namespace('yandex-market-orders')
            ->deduplication([
                (string) $message->getId(),
                self::class,
            ]);

        if($Deduplicator->isExecuted() === true)
        {
            return;
        }

        /** Получаем текущее состояние честного знака */

        $ProductSignEvent = $this->ProductSignCurrentEventRepository
            ->forProductSign($message->getId())
            ->find();

        if(false === ($ProductSignEvent instanceof ProductSignEvent))
        {
            $this->Logger->critical(
                message: 'yandex-market-orders: Не найдено событие ProductSignEvent',
                context: [self::class.':'.__LINE__, var_export($message, true)],
            );
            return;
        }

        if(false === $ProductSignEvent->isStatusEquals(ProductSignStatusProcess::class))
        {
            return;
        }

        if(false === ($ProductSignEvent->getOrderId() instanceof OrderUid))
        {
            return;
        }

        /** Получаем текущее состояние заказа */

        $SignOrderEvent = $this->CurrentOrderEventRepository
            ->forOrder($ProductSignEvent->getOrderId())
            ->find();

        if(false === ($SignOrderEvent instanceof OrderEvent))
        {
            $this->Logger->critical(
                message: 'yandex-market-orders: Не найдено событие OrderEvent',
                context: [self::class.':'.__LINE__, var_export($message, true)],
            );

            return;
        }

        /**
         * Если тип доставки заказа НЕ Ozon Fbs «Доставка службой Ozon» - Завершаем обработчик
         */
        if(false === $SignOrderEvent->isDeliveryTypeEquals(TypeDeliveryFbsYaMarket::TYPE))
        {
            $Deduplicator->save();
            return;
        }


        /** Получаем все отправления заказа */

        $posting = $this->CurrentOrderEventByNumberRepository
            ->findAll($SignOrderEvent->getOrderNumber());

        if(true === empty($posting))
        {
            return;
        }


        /** Получаем информацию о заказе в селлере  */
        $YaMarketTokenUid = new YaMarketTokenUid($SignOrderEvent->getOrderTokenIdentifier());

        $NewOzonOrderDTO = $this->GetYaMarketOrderInfoRequest
            ->forTokenIdentifier($YaMarketTokenUid)
            ->findNew($SignOrderEvent->getOrderNumber());

        if(false === ($NewOzonOrderDTO instanceof NewYaMarketOrderByBusinessDTO))
        {
            $this->Logger->critical(
                message: sprintf('yandex-market-orders: Не найдено информации о заказе %s', $SignOrderEvent->getOrderNumber()),
                context: [self::class.':'.__LINE__],
            );

            return;
        }

        if(empty($NewOzonOrderDTO->getPostingBox()))
        {
            $this->Logger->error(
                message: sprintf('yandex-market-orders: Не найдено информации о грузоместах %s', $SignOrderEvent->getOrderNumber()),
                context: [self::class.':'.__LINE__],
            );

            return;
        }


        $products = [];
        $signs = [];

        /** Итерируемся по отправлениям заказа */
        foreach($posting as $PostingOrderEvent)
        {
            /** Получаем честный знак отправления */
            $ProductSignByOrder = $this->ProductSignByOrderRepository
                ->forOrder($PostingOrderEvent->getOrderId())
                ->findAll();

            /** Завершаем обработчик если честного знака на отправление не найдено */
            if(false === $ProductSignByOrder || false === $ProductSignByOrder->valid())
            {
                return;
            }

            /** Ищем грузоместо согласно отправлению */
            foreach($NewOzonOrderDTO->getPostingBox() as $NewYaMarketOrderBoxDTO)
            {
                if($NewYaMarketOrderBoxDTO->getPostingNumber() === $PostingOrderEvent->getPostingNumber())
                {
                    $item = current($NewYaMarketOrderBoxDTO->getItems());

                    if(empty($item['id']))
                    {
                        return;
                    }

                    $products[$NewYaMarketOrderBoxDTO->getBoxId()] = $item['id'];

                    foreach($ProductSignByOrder as $ProductSignByOrderResult)
                    {
                        $signs[$NewYaMarketOrderBoxDTO->getBoxId()][] = ['cis' => $ProductSignByOrderResult->getSmallCode()];
                    }
                }
            }
        }

        /** Отправляем честные знаки */
        $this->Logger->critical(
            message: sprintf('DEBUG %s: лог отправки честных знаков по яндекс-заказу', $SignOrderEvent->getOrderNumber()),
            context: [
                self::class.':'.__LINE__,
                $products,
                $signs,
            ],
        );


        return;


        $isUpdate = $this->UpdateSignYaMarketProductRequest
            ->products($products)
            ->signs($signs)
            ->update($SignOrderEvent->getOrderNumber());

        if(false === $isUpdate)
        {
            $this->Logger->critical(
                message: sprintf('yandex-market-orders: Не найдено информации о заказе %s', $SignOrderEvent->getOrderNumber()),
                context: [self::class.':'.__LINE__],
            );
        }

    }
}
