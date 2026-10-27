# Проверка нативного драйвера

[English](../testing.md)

Для выполнения команд нужен клон репозитория. Composer dist не содержит tests/,
benchmarks/ и CI-конфигурацию; используйте исходники и dev-зависимости из клона.
Обычные тесты загружают ServiceProvider пакета и запрещают обращение к классам
удалённого самостоятельного PHP-билдера.

## Запуск тестов

```sh
composer install
MANTICORE_TEST_HOST=127.0.0.1 MANTICORE_TEST_PORT=9306 MANTICORE_TEST_BUDDY=1 php tests/check-server.php
php tests/check-api-contracts.php
MANTICORE_TEST_HOST=127.0.0.1 MANTICORE_TEST_PORT=9306 MANTICORE_TEST_BUDDY=1 vendor/bin/phpunit --fail-on-skipped
```

Тестовому серверу нужны Buddy и библиотека KNN. check-server.php проверяет версию
и модули; suite должна проходить без пропусков. Тесты создают изолированные таблицы
со случайными именами и затем удаляют их. Полная совместимость Eloquent не входит
в этот профиль проверок.

## Совместимость с сервером

Поддерживаемый минимум — Manticore 28.6.6, самая ранняя проверенная версия драйвера.
Более старые версии не входят в политику поддержки; обычное соединение не добавляет
SQL-проверку версии. Отдельным функциям всё равно нужны соответствующие модули
и настройки таблиц.

[CI workflow](https://github.com/aVadim483/manticore-query-builder-laravel/blob/main/.github/workflows/tests.yml)
пересекает четыре комбинации runtime с официальным минимальным образом, закреплённым
по digest, и изменяемым образом latest:

| PHP | Laravel |
| --- | --- |
| 8.2 | 11 |
| 8.3 | 12 |
| 8.4 | 13 |
| 8.5 | 13 |

MANTICORE_TEST_EXPECTED_VERSION=28.6.6 проверяет точную версию минимального сервера.
MANTICORE_TEST_IMAGE обозначает образ в отчёте; MANTICORE_TEST_SERVER_REPORT позволяет
сохранить отчёт в существующем каталоге. Следование latest выявляет изменения сервера,
но не гарантирует поддержку ещё не проверенных будущих версий.

## Production-установки

```sh
php tests/prepare-production.php 13
composer update --working-dir=build/production --no-dev --prefer-dist --no-interaction
php tests/production-smoke.php build/production/vendor/autoload.php --live
```

Для live-команд задайте MANTICORE_TEST_HOST и MANTICORE_TEST_PORT.
В prepare-production.php выбирается 11, 12 или 13. Изолированный потребитель использует
компоненты Illuminate без Testbench, PHPUnit и полного Laravel framework. Проверяются
регистрация, компиляция и с --live: схема, запись/ID, преобразования типов, метаданные,
транзакции, hybrid, capability probes и замена индекса.

CI также устанавливает потребителей минимальных Illuminate 11.0.0, 12.0.0 и 13.0.0.
Для локальной подготовки передайте точную версию вторым аргументом:
php tests/prepare-production.php 13 13.0.0; каталог будет build/production-floor-13.
В tests/check-api-contracts.php передайте autoload этого потребителя.

Изолированная установка полной suite может задать абсолютный MANTICORE_TEST_AUTOLOAD
и запустить собственный PHPUnit с phpunit.xml.dist этого клона. Её dev-autoload
должен направлять namespace Tests пакета в tests/ текущего клона.

## Отдельные проверки

- Тесты схемы проверяют расширение integer, настройки, частичные ошибки DDL, кеш,
  миграции и rename. См. [ограничения схемы](schema-alter.md).
- HybridGuideIntegrationTest выполняет помеченные примеры из
  [русского](hybrid-search.md) и [английского](../hybrid-search.md) руководств:
  типы, семантические документы, общие фильтры, метаданные и страницы окна.
- Тесты замены индекса проверяют чтение в промежутке rename, прерывания и откат;
  см. [условия замены](hybrid-and-index-replacement.md).
- DocumentationTest проверяет конфигурацию README, содержание и структуру перевода.
  API-проверки читают docs/laravel-query-builder-api.json и docs/query-builder-contracts.json.
- На отдельном сервере без Buddy запускается php tests/schema-no-buddy.php:
  проверяются отказ rename без потери данных и недоступность fuzzy/autocomplete.
  Скрипт отклоняет сервер с Buddy и не входит в обычную suite.

[Инструкция benchmarks](https://github.com/aVadim483/manticore-query-builder-laravel/blob/main/benchmarks/README.md)
описывает подготовку скриптов и короткие smoke-прогоны. Результаты и границы нагрузок
приведены в [документе о производительности](performance.md).
Короткие smoke-прогоны проверяют работоспособность скриптов, а не скорость.
