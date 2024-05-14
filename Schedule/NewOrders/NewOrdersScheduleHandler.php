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

namespace BaksDev\Yandex\Market\Orders\Schedule\NewOrders;

use BaksDev\Core\Messenger\MessageDispatchInterface;
use BaksDev\Yandex\Market\Orders\Messenger\NewOrders\NewOrdersMessage;
use BaksDev\Yandex\Market\Repository\AllProfileToken\AllProfileTokenInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class NewOrdersScheduleHandler
{
    private AllProfileTokenInterface $allProfileToken;

    private MessageDispatchInterface $messageDispatch;

    public function __construct(
        AllProfileTokenInterface $allProfileToken,
        MessageDispatchInterface $messageDispatch,
    )
    {
        $this->allProfileToken = $allProfileToken;
        $this->messageDispatch = $messageDispatch;
    }

    public function __invoke(NewOrdersScheduleMessage $message): void
    {
        $profiles = $this->allProfileToken
            ->onlyActiveToken()
            ->findAll();

        if($profiles->valid())
        {
            foreach($profiles as $profile)
            {
                $this->messageDispatch->dispatch(
                    message: new NewOrdersMessage($profile),
                    transport: (string) $profile,
                );
            }
        }


    }
}