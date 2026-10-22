[English](README.md) | [Русский](README.ru.md)

[![GitHub Release](https://img.shields.io/github/v/release/aVadim483/manticore-query-builder-laravel)](https://packagist.org/packages/avadim/manticore-query-builder-laravel)
[![tests](https://github.com/aVadim483/manticore-query-builder-laravel/actions/workflows/tests.yml/badge.svg)](https://github.com/aVadim483/manticore-query-builder-laravel/actions/workflows/tests.yml)

# Драйвер ManticoreSearch Database для Laravel

Нативный драйвер Laravel Database для ManticoreSearch. Используйте менеджер соединений Laravel,
Query Builder, коллекции, пагинацию, события запросов и Schema Builder через MySQL-протокол PDO.

Версия `4.0.0-alpha.2` предназначена для раннего тестирования; это не стабильный релиз.
Возможности и непроверенные интеграции перечислены в [заметках к alpha](docs/ru/releases/4.0.0-alpha.2.md).
Старый API обёртки 3.x и зависимость от `avadim/manticore-query-builder-php` удалены.
Для существующих приложений подготовлено [руководство перехода с 3.x](docs/ru/upgrading-3.x-to-4.x.md).

## Содержание

* [Требования](#требования)
* [Установка](#установка)
* [Конфигурация](#конфигурация)
* [Запросы и результаты](#запросы-и-результаты)
* [Запись и транзакции](#запись-и-транзакции)
* [Поисковые расширения](#поисковые-расширения)
* [Схема и миграции](#схема-и-миграции)
* [Отличия от Laravel Query Builder](#отличия-от-laravel-query-builder)
* [Тесты и benchmarks](#тесты-и-benchmarks)

## Требования

* PHP 8.2+ и Laravel 11–13 с учётом требований соответствующей версии Laravel к PHP.
* Расширения PHP `pdo`, `pdo_mysql` и `json`.
* ManticoreSearch 28.6.6+ с включённым MySQL-протоколом, обычно на порту 9306.
* Buddy для AUTOCOMPLETE; библиотека KNN и векторный индекс для KNN-запросов.

Минимальная поддерживаемая версия сервера — 28.6.6. CI проверяет закреплённый по digest
официальный образ этой версии и изменяемый `latest`; см. [политику совместимости](docs/ru/testing.md#совместимость-с-сервером).
Поддержка более старых серверов, Lumen и полноценная совместимость с Eloquent не подтверждены.

## Установка

Для установки опубликованной alpha:

```sh
composer require avadim/manticore-query-builder-laravel:4.0.0-alpha.2
```

Для проверки последующих изменений в разработке:

```sh
composer require avadim/manticore-query-builder-laravel:dev-dev-4.x
```

Явное ограничение alpha не требует менять `minimum-stability` всего приложения.
Миграция работающего демонстрационного Laravel-приложения [проверена](docs/ru/migration-app-verification.md).
Переход Scout-адаптера и других приложений пока не проверен.

Laravel автоматически обнаруживает `avadim\Manticore\Laravel\ServiceProvider`.
Если обнаружение пакетов отключено, зарегистрируйте его в `bootstrap/providers.php`.
`DatabaseServiceProvider` сохранён для приложений, подключавших нативный драйвер на ранних этапах 4.x.
Достаточно одного из этих провайдеров. Псевдонима `ManticoreDb` и публикуемой конфигурации пакета нет.

## Конфигурация

Добавьте запись в массив `connections` файла `config/database.php`:

```php
'manticore' => [
    'driver' => 'manticore',
    'host' => env('MANTICORE_HOST', '127.0.0.1'),
    'port' => env('MANTICORE_PORT', 9306),
    'username' => env('MANTICORE_USER'),
    'password' => env('MANTICORE_PASS'),
    'timeout' => env('MANTICORE_TIMEOUT', 5),
    'prefix' => env('MANTICORE_PREFIX', ''),
    'cast_attributes' => true,
    'options' => [],
],
```

Оставьте основную базу приложению по умолчанию и явно выбирайте `DB::connection('manticore')`.
Другие именованные соединения могут использовать драйвер `manticore` со своими параметрами.
Драйвер не читает `config/manticore.php`, `MANTICORE_CONNECTION`, `force_prefix`, `lazy_insert_ids`.
Laravel всегда добавляет префикс к именам таблиц билдера: используйте `products` без `?`.
Сырой SQL не переписывается. Эмуляция подготовленных запросов PDO должна оставаться включённой.

Логирование подключается через `DB::listen()`, управление соединениями воркеров —
через `DB::purge()` / `DB::reconnect()`. Примеры логирования и работы с кэшем схемы есть
в [руководстве перехода](docs/ru/upgrading-3.x-to-4.x.md).

## Запросы и результаты

```php
use Illuminate\Support\Facades\DB;

$db = DB::connection('manticore');
$query = $db->table('products')->select('id', 'title')->match('galaxy')
    ->where('price', '>', 100)->orderBy('id');

$rows = $query->get();                 // Collection<stdClass>
$first = (clone $query)->first();       // stdClass|null
$title = $first?->title;
$page = (clone $query)->paginate(20);
$lazy = (clone $query)->lazyById(500);   // LazyCollection<stdClass>
```

Метод билдера `select()` задаёт колонки и возвращает билдер. Метод соединения
`$db->select($sql, $bindings)` выполняет SQL и возвращает `array<stdClass>` без преобразований билдера.
`get(['id', 'title'])` принимает массив; строка с запятыми не является списком колонок.
Поля строки доступны через свойства. Для массива строк-массивов используйте
`$rows->map(static fn ($row) => (array)$row)->all()`.

`cursor()` возвращает LazyCollection из одного SELECT. `lazy()` читает страницы с offset,
`lazyById()` обходит таблицу по границе ID. `cursorPaginate()` поддерживает выбранные
integer/bool/timestamp ключи с ID в конце сортировки. Float, вычисляемые ключи, KNN,
группы и фасеты для курсорной пагинации отклоняются.
Подсчёт total и ограничения потокового чтения описаны в [справочнике](docs/ru/laravel-driver.md).

## Запись и транзакции

```php
$id = $db->table('products')->insertGetId(['title' => 'Galaxy', 'price' => 120.5]);
$db->table('products')->where('id', $id)->update(['price' => 99.5]);
$db->table('products')->replace(['id' => $id, 'title' => 'Galaxy phone', 'price' => 99.5]);
$db->table('products')->where('id', $id)->delete();

$db->transaction(function () use ($db) {
    $db->table('products')->insert(['id' => 42, 'title' => 'Phone', 'price' => 50]);
});
```

`insert()` / `replace()` возвращают bool, `update()` / `delete()` — число затронутых строк,
`insertGetId()` / `replaceGetId()` — `int|string`. Явный ID включается в документ.
Ошибки SQL выбрасывают `Illuminate\Database\QueryException`; нулевое число строк может означать успех.
REPLACE заменяет документ целиком со сбросом пропущенных атрибутов и позволяет менять полнотекстовые поля.
Вложенные транзакции, DDL внутри транзакции и число попыток кроме одной отклоняются.
После потери ответа запись автоматически не повторяется.

## Поисковые расширения

```php
$result = $db->table('products')->match('galaxy')->select('id', 'title')
    ->withScore()->fieldWeights(['title' => 10])->ranker('bm25')
    ->withHighlight(['title'])
    ->facet('brand', 'brands', 10)->limit(20)->search();

$rows = $result->rows();
$total = $result->meta()->totalFound();
$brands = $result->facet('brands');
```

`search()` сохраняет строки, метаданные и фасеты до обработчиков событий. `get()` читает только строки.
Также доступны `option()`, `knn()`, MVA ANY/ALL, геопоиск и методы соединения
`keywords()`, `suggest()`, `qsuggest()`, `snippets()`, `autocomplete()`, общий `call()`.
Сигнатуры и требования к серверу приведены в [справочнике](docs/ru/laravel-driver.md).

## Схема и миграции

```php
use avadim\Manticore\Laravel\Database\ManticoreBlueprint;
use Illuminate\Support\Facades\Schema;

Schema::connection('manticore')->create('products', function (ManticoreBlueprint $table) {
    $table->id();
    $table->text('title');
    $table->string('brand');
    $table->float('price');
    $table->json('details');
    $table->multi('tags');
});
```

CREATE, DROP, ALTER ADD/DROP выполняются через Laravel Schema Builder. Доступны
`multi64()`, `floatVector()`, настройки CREATE через `setting()`.
В `dev-4.x` после alpha.1 [расширенный ALTER](docs/ru/schema-alter.md) добавляет изменение и чтение
настроек, `change()` для int → bigint и `rename()` таблицы с Buddy; в alpha.1 этих дополнений нет.
Неподдерживаемые операции схемы явно отклоняются.
Таблицу учёта миграций оставьте в основной SQL-базе, выбирая Manticore в отдельных миграциях.
DDL не транзакционен. См. [пример миграции](docs/ru/upgrading-3.x-to-4.x.md).

## Отличия от Laravel Query Builder

Alpha.2 добавляет [независимый поиск weighted RRF, явные проверки возможностей и
замену RT-индекса в режиме обслуживания](docs/ru/hybrid-and-index-replacement.md) без Scout.
`knn()->match()` сохраняет пересечение; замена индекса не обещает zero downtime.

| Область | Поведение |
| --- | --- |
| Билдер и результаты | Контракты Laravel Builder, Collection, stdClass и LazyCollection |
| Диалект SQL | Manticore SQL через собственную Grammar; joins, unions, DISTINCT, блокировки и WHERE EXISTS отклоняются |
| Поиск | MATCH, ранжирование, фасеты, подсветка, векторы и метаданные расширяют API Laravel |
| Типы | Билдер преобразует JSON, MVA, векторы и числовые атрибуты по кэшированному DESCRIBE; сырые чтения сохраняют значения PDO |
| Запись | Полнотекстовые изменения требуют REPLACE; произвольный атомарный upsert не поддержан |
| Транзакции | Одно соединение, без вложенности, savepoints, повторных попыток и транзакционного DDL |
| Пагинация | Есть ограничения окна результатов и типов ключей; см. справочник |
| Eloquent | Связи, сохранение моделей и общая совместимость ORM не обещаются |

Используйте параметры в `whereRaw()` / `selectRaw()`; `DB::raw()` предназначен для доверенного SQL.
Буквальный текст MATCH экранируется через
`avadim\Manticore\Laravel\Database\ManticoreQueryBuilder::escapeMatch()`.
Собирайте одно выражение MATCH на запрос.

## Тесты и benchmarks

```sh
composer install
composer test
```

Интеграционные тесты используют `MANTICORE_TEST_HOST` / `MANTICORE_TEST_PORT` (127.0.0.1:9306).
Задайте `MANTICORE_TEST_BUDDY=1` для обязательного AUTOCOMPLETE. Локально недоступные возможности
сервера пропускаются; CI использует `--fail-on-skipped`.
Bootstrap по умолчанию отклоняет загрузку классов самостоятельного PHP-билдера.
Для основной таблицы учёта миграций в тестах нужен `pdo_sqlite`.
Запуск изолированной production-установки и матриц описан в [инструкции проверок](docs/ru/testing.md).

[Benchmarks](benchmarks/README.md) сравнивают нативный драйвер с PDO и официальным HTTP-клиентом.
