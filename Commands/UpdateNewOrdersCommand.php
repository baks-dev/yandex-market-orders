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

namespace BaksDev\Yandex\Market\Orders\Commands;

use BaksDev\Core\Messenger\MessageDispatchInterface;
use BaksDev\Orders\Order\Entity\Order;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
use BaksDev\Yandex\Market\Orders\Messenger\Schedules\NewOrders\NewYaMarketOrdersScheduleMessage;
use BaksDev\Yandex\Market\Repository\AllProfileToken\AllProfileYaMarketTokenInterface;
use BaksDev\Yandex\Market\Repository\YaMarketTokensByProfile\YaMarketTokensByProfileInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'baks:yandex-market-orders:new',
    description: 'Получает новые заказы Yandex Market'
)]
class UpdateNewOrdersCommand extends Command
{
    private SymfonyStyle $io;

    public function __construct(
        private readonly AllProfileYaMarketTokenInterface $allProfileYaMarketToken,
        private readonly MessageDispatchInterface $messageDispatch,
    )
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io = new SymfonyStyle($input, $output);

        /** Получаем активные токены авторизации профилей Yandex Market */
        $profiles = $this->allProfileYaMarketToken
            ->onlyActiveToken()
            ->findAll();

        $profiles = iterator_to_array($profiles);

        $helper = $this->getHelper('question');


        /**
         * Интерактивная форма списка профилей
         */

        $questions[] = 'Все';

        foreach($profiles as $quest)
        {
            $questions[] = $quest->getAttr();
        }

        $questions['-'] = 'Выйти';

        $question = new ChoiceQuestion(
            'Профиль пользователя (Ctrl+C чтобы выйти)',
            $questions,
            '0',
        );

        $key = $helper->ask($input, $output, $question);

        /**
         *  Выходим без выполненного запроса
         */

        if($key === '-' || $key === 'Выйти')
        {
            return Command::SUCCESS;
        }


        /**
         * Выполняем все
         */

        if($key === '0' || $key === 'Все')
        {
            /** @var UserProfileUid $profile */
            foreach($profiles as $profile)
            {
                $this->update($profile);
            }

            return Command::SUCCESS;
        }


        /**
         * Выполняем определенный профиль
         */

        $UserProfileUid = null;

        foreach($profiles as $profile)
        {
            if($profile->getAttr() === $questions[$key])
            {
                /* Присваиваем профиль пользователя */
                $UserProfileUid = $profile;
                break;
            }
        }

        if($UserProfileUid)
        {
            $this->update($UserProfileUid);

            $this->io->success('Заказы успешно обновлены');
            return Command::SUCCESS;
        }

        $this->io->success('Профиль пользователя не найден');
        return Command::SUCCESS;

    }

    public function update(UserProfileUid $UserProfileUid): void
    {
        $this->io->note(sprintf('Обновляем новые заказы профиля %s', $UserProfileUid->getAttr()));

        /** Получаем все токены профиля */

        $this->messageDispatch->dispatch(
            message: new NewYaMarketOrdersScheduleMessage($UserProfileUid),
        );


        //        $tokensByProfile = $this->YaMarketTokensByProfile->findAll($UserProfileUid);
        //
        //        if(false === $tokensByProfile || false === $tokensByProfile->valid())
        //        {
        //            $this->io->error(sprintf('Токенов авторизации профиля %s не найдено', $UserProfileUid->getAttr()));
        //            return;
        //        }

        //        foreach($tokensByProfile as $YaMarketTokenUid)
        //        {
        //            $orders = $this->yandexMarketNewOrdersRequest
        //                ->forTokenIdentifier($YaMarketTokenUid)
        //                ->findAll(DateInterval::createFromDateString('1 week'));
        //
        //            if(false === $orders || false === $orders->valid())
        //            {
        //                $this->io->writeln(sprintf('<fg=gray>%s: новых заказов не найдено</>', $YaMarketTokenUid));
        //                continue;
        //            }
        //
        //            foreach($orders as $YandexMarketOrderDTO)
        //            {
        //                $handle = $this->yandexMarketOrderHandler->handle($YandexMarketOrderDTO);
        //
        //                if($handle instanceof Order)
        //                {
        //                    $this->io->info(sprintf('Добавили новый заказ %s', $YandexMarketOrderDTO->getPostingNumber()));
        //                    continue;
        //                }
        //
        //                $this->io->error(sprintf('%s: Ошибка при добавлении заказа %s', $handle, $YandexMarketOrderDTO->getPostingNumber()));
        //            }
        //
        //        }
    }
}
