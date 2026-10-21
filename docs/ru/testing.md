[English](../testing.md) | [Русский](testing.md)

# Проверка нативного драйвера

Основной набор загружает тот же `ServiceProvider`, который Laravel обнаруживает при установке
пакета. Проверяются SQL и параметры, контракты Laravel, запись/ID, транзакции, схема и миграции,
преобразования атрибутов, соединения, поиск, потоковое чтение и пагинация.
Bootstrap по умолчанию отклоняет загрузку классов удалённого PHP-билдера.

```sh
composer install
MANTICORE_TEST_HOST=127.0.0.1 MANTICORE_TEST_PORT=9306 MANTICORE_TEST_BUDDY=1 vendor/bin/phpunit --fail-on-skipped
```

CI независимо устанавливает Laravel 11, 12 и 13, включая PHP 8.2 на нижней границе и PHP 8.5
на верхней. Нужен сервер Manticore с Buddy и библиотекой KNN.
Затем каждый job проверяет изолированную production-установку:

```sh
php tests/prepare-production.php 13
composer update --working-dir=build/production --no-dev --prefer-dist --no-interaction
php tests/production-smoke.php build/production/vendor/autoload.php --live
```

Первый аргумент `11`, `12` или `13` выбирает версию Illuminate Database. Проверяемое приложение
устанавливает текущий пакет как path-зависимость без Testbench, PHPUnit и полного Laravel.
Smoke-тест регистрирует провайдер в минимальном контейнере Illuminate и проверяет ленивое
соединение, сборку запроса, а с `--live` — схему, запись и ID, JSON/MVA, метаданные поиска
и транзакцию. Уникальная тестовая таблица удаляется в `finally`.
Без `--live` соединение с сервером не открывается.

Для полного набора в отдельной установке задайте `MANTICORE_TEST_AUTOLOAD` — абсолютный путь
к её Composer-autoload. Запускайте её PHPUnit с `phpunit.xml.dist` репозитория и сопоставлением
PSR-4 `avadim\Manticore\Laravel\Tests\` с каталогом `tests/` текущего checkout.
Это исключает незаметную подмену версии Laravel корневым каталогом vendor.

2026-10-21 полный набор прошёл на PHP 8.4.19 с Laravel 11.57.0, 12.70.1 и 13.30.1:
по 130 тестов без ошибок и пропусков. Сервер: Manticore 29.9.0, Buddy 4.4.3, Columnar/KNN 14.2.1.
Другие версии PHP проверяет CI. Эти проверки не устанавливают минимальную версию Manticore
и не подтверждают полноценную поддержку Eloquent.

[Benchmarks](../../benchmarks/README.md) отдельно проверены на 100 документах для каждой
вставки и 120 документах / 5 запросах на поисковый профиль. Четыре клиента обязаны вернуть
одинаковое число строк. Такой малый прогон проверяет скрипты, а не производительность.
