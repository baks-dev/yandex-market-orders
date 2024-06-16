# BaksDev Orders YandexMarket

[![Version](https://img.shields.io/badge/version-7.1.6-blue)](https://github.com/baks-dev/yandex-market-orders/releases)
![php 8.3+](https://img.shields.io/badge/php-min%208.3-red.svg)

Модуль заказов Yandex Market

## Установка

``` bash
composer require \
baks-dev/yandex-market \
baks-dev/yandex-market-orders
```

При первоначальной установке пакета согласится с применением flex-рецепта, либо самостоятельно добавить тип профиля,
доставку и способ оплаты «Yandex Market»

``` bash
php bin/console baks:users-profile-type:yandex-market
php bin/console baks:payment:yandex-market
php bin/console baks:delivery:yandex-market
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

