# BaksDev Orders YandexMarket

[![Version](https://img.shields.io/badge/version-7.2.21-blue)](https://github.com/baks-dev/yandex-market-orders/releases)
![php 8.4+](https://img.shields.io/badge/php-min%208.4-red.svg)
[![packagist](https://img.shields.io/badge/packagist-green)](https://packagist.org/packages/baks-dev/yandex-market-orders)

Модуль заказов Yandex Market

## Установка

``` bash
composer require \
baks-dev/yandex-market \
baks-dev/yandex-market-orders
```

Для работы с заказами выполнить комманду для добавления типа профиля и доставку:

* #### FBS

``` bash
php bin/console baks:users-profile-type:yandex-market-fbs
php bin/console baks:payment:yandex-market-fbs
php bin/console baks:delivery:yandex-market-fbs
```

* #### DBS

``` bash
php bin/console baks:users-profile-type:yandex-market-dbs
php bin/console baks:payment:yandex-market-dbs
php bin/console baks:delivery:yandex-market-dbs
```

## Дополнительно

Установка конфигурации и файловых ресурсов:

``` bash
php bin/console baks:assets:install
```

Изменения в схеме базы данных с помощью миграции

``` bash
php bin/console doctrine:migrations:diff
php bin/console doctrine:migrations:migrate
```

## Тестирование

``` bash
php bin/phpunit --group=yandex-market-orders
```

## Лицензия ![License](https://img.shields.io/badge/MIT-green)

The MIT License (MIT). Обратитесь к [Файлу лицензии](LICENSE.md) за дополнительной информацией.
