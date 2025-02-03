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

namespace BaksDev\Yandex\Market\Orders\Controller\Admin;

use BaksDev\Core\Controller\AbstractController;
use BaksDev\Core\Form\Search\SearchDTO;
use BaksDev\Core\Form\Search\SearchForm;
use BaksDev\Core\Listeners\Event\Security\RoleSecurity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
#[RoleSecurity('ROLE_YA_MARKET_ORDERS')]
final class IndexController extends AbstractController
{
    #[Route('/admin/ya/market/orders/{page<\d+>}', name: 'admin.index', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        //AllYaMarketInterface $allYaMarket,
        int $page = 0,
    ): Response
    {

        return new Response('Временно недоступно: '.self::class);

        // Поиск
        $search = new SearchDTO();

        $searchForm = $this
            ->createForm(
                type: SearchForm::class,
                data: $search,
                options: ['action' => $this->generateUrl('yandex-market-orders:admin.index')]
            )
            ->handleRequest($request);

        // Ожидается найти класс «BaksDev\Yandex\Market\Orders\Controller\Admin\IndexController» в файле «/home/bundles.baks.dev/vendor/baks-dev/yandex-market-orders/Controller/Admin/IndexController.php». " при импорте сервисов из ресурса "/home/bundles.baks.dev/vendor/baks
        // -dev/yandex-market-orders/", но он не найден! Проверьте префикс пространства имен, используемый с ресурсом, в /home/bundles.baks.dev/vendor/baks-dev/yandex-market-orders/Resources /config/services.php (который импортируется из «/home/bundles.baks.dev/vendor/baks-dev/yand»
        // ex-market-orders/BaksDevYandexMarketOrdersBundle.php").


        // Фильтр
        // $filter = new ProductsStocksFilterDTO($request, $ROLE_ADMIN ? null : $this->getProfileUid());
        // $filterForm = $this->createForm(ProductsStocksFilterForm::class, $filter);
        // $filterForm->handleRequest($request);

        // Получаем список
        /*$YaMarket = $allYaMarket
            ->search($search)
            ->findPaginator();*/

        return $this->render(
            [
                'query' => [],
                'search' => $searchForm->createView(),
            ]
        );
    }
}
