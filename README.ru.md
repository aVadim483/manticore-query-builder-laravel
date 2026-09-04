[English](README.md) | **Русский**

[![GitHub Release](https://img.shields.io/github/v/release/aVadim483/manticore-query-builder-laravel)](https://packagist.org/packages/avadim/manticore-query-builder-laravel)
[![Packagist Downloads](https://img.shields.io/packagist/dt/avadim/manticore-query-builder-laravel?color=%23aa00aa)](https://packagist.org/packages/avadim/manticore-query-builder-laravel)
[![GitHub License](https://img.shields.io/github/license/aVadim483/manticore-query-builder-laravel)](https://packagist.org/packages/avadim/manticore-query-builder-laravel)
[![Static Badge](https://img.shields.io/badge/php-%3E%3D7.4-005fc7)](https://packagist.org/packages/avadim/manticore-query-builder-laravel)
[![Static Badge](https://img.shields.io/badge/laravel-6%20--%2013-ff2d20)](https://packagist.org/packages/avadim/manticore-query-builder-laravel)

# ManticoreSearch Query Builder для Laravel

Самый простой способ использовать [ManticoreSearch Query Builder](https://github.com/aVadim483/manticore-query-builder-php)
в приложениях на Laravel и Lumen: запросы к ManticoreSearch пишутся в привычном для Laravel синтаксисе.

```sh
composer require avadim/manticore-query-builder-laravel
```

Сам построитель запросов — отдельный пакет, и синтаксис запроса живёт именно там: `match()`,
`where()`, `insert()`, схема таблицы. Этот пакет добавляет то, что имеет смысл только внутри
фреймворка: сервис-провайдер и файл конфигурации, именованные соединения и ответы в том виде,
в каком их ждёт Laravel.

## Содержание

* [Смежные пакеты](#смежные-пакеты)
* [Требования](#требования)
* [Установка](#установка)
* [Конфигурация](#конфигурация)
* [Быстрый старт](#быстрый-старт)
* [Алиас, фасад и контейнер](#алиас-фасад-и-контейнер)
* [Что возвращает чтение](#что-возвращает-чтение)
* [Когда запрос не выполнен](#когда-запрос-не-выполнен)
* [Постраничная навигация](#постраничная-навигация)
* [Транзакции](#транзакции)
* [Миграции](#миграции)
* [Долгоживущие процессы](#долгоживущие-процессы)
* [Логирование](#логирование)
* [Документация](#документация)
* [Тесты](#тесты)

## Смежные пакеты

* [`avadim/manticore-query-builder-php`](https://github.com/aVadim483/manticore-query-builder-php) —
  сам построитель запросов, без зависимостей от Laravel: синтаксис запроса, DSL схемы и пул
  соединений живут там. Именно он нужен вне Laravel-приложения, и именно к нему относится правка
  `match()`, `where()` или `insert()`.
* [`avadim/manticore-laravel-scout`](https://github.com/aVadim483/manticore-laravel-scout) —
  драйвер ManticoreSearch для [Laravel Scout](https://laravel.com/docs/scout), построенный на этом
  пакете: полнотекстовый поиск по моделям Eloquent в виде `Post::search('manticore')->get()`, а за
  актуальностью индекса следит наблюдатель Scout.

## Требования

* PHP 7.4 или выше с расширениями PDO и JSON
* Laravel 6 — 13 либо Lumen
* [avadim/manticore-query-builder-php](https://github.com/aVadim483/manticore-query-builder-php) 2.1 или выше — ставится вместе с пакетом
* Доступный сервер Manticore Search по SQL-интерфейсу, по умолчанию порт 9306. HTTP/JSON API не используется.

Некоторым возможностям одного сервера мало: векторному поиску KNN нужна библиотека KNN,
колоночному хранению — columnar, `rename()` — Manticore Buddy. Что кому нужно, перечислено в
[требованиях](https://github.com/aVadim483/manticore-query-builder-php/blob/main/README.ru.md#требования)
построителя запросов.

## Установка

### Laravel

Сервис-провайдер пакета регистрируется сам.

Опубликуйте файл конфигурации:

```sh
php artisan vendor:publish --provider="avadim\Manticore\Laravel\ServiceProvider"
```

### Lumen

Lumen ничего не обнаруживает сам, поэтому сервис-провайдер, конфигурация и алиас регистрируются
вручную в `bootstrap/app.php`:

```php
// короткое имя фасада - тот самый \ManticoreDb, который Laravel регистрирует самостоятельно
$app->withFacades(true, [
    'avadim\Manticore\Laravel\Facade' => 'ManticoreDb',
]);

// Регистрация файла конфигурации
$app->configure('manticore');

// Регистрация сервис-провайдера
$app->register(avadim\Manticore\Laravel\ServiceProvider::class);
```

Файл конфигурации в Lumen тоже копируется в приложение вручную.

## Конфигурация

После публикации файла конфигурации соединение настраивается в `.env` вашего приложения
(с подходящими значениями):

```dotenv
MANTICORE_HOST=localhost
MANTICORE_PORT=9306
MANTICORE_USER=
MANTICORE_PASS=
MANTICORE_TIMEOUT=5
```

### Все доступные переменные окружения

| Название               | Значение по умолчанию | Описание                                                        |
|------------------------|-----------------------|-----------------------------------------------------------------|
| MANTICORE_CONNECTION   | default               | Имя соединения по умолчанию                                     |
| MANTICORE_HOST         | localhost             | Адрес хоста с сервером Manticore                                |
| MANTICORE_PORT         | 9306                  | Порт SQL-интерфейса сервера                                     |
| MANTICORE_USER         |                       | Имя пользователя                                                |
| MANTICORE_PASS         |                       | Пароль                                                          |
| MANTICORE_TIMEOUT      | 5                     | Таймаут запроса                                                 |
| MANTICORE_PREFIX       |                       | Префикс, заменяющий подстановку `?` перед именем таблицы        |
| MANTICORE_FORCE_PREFIX | false                 | Добавлять префикс ко всем именам, а не только к записанным с `?` |

Префикс отделяет таблицы приложения от чужих на общем сервере: при префиксе `myapp_` запрос
`table('?products')` читает таблицу `myapp_products`. С `MANTICORE_FORCE_PREFIX=true` знак `?`
можно не писать — префикс добавится к любому имени.

Дополнительные соединения добавляются в массив `connections` файла `config/manticore.php`,
у каждого тот же набор ключей; `defaultConnection` указывает то, которое используется, когда имя
не задано.

## Быстрый старт

```php
// Список таблиц через соединение по умолчанию
$list = \ManticoreDb::showTables();

// Список таблиц через указанное соединение
$list = \ManticoreDb::connection('test')->showTables();

\ManticoreDb::table('t')->insert($data);
\ManticoreDb::table('t')->match($match)->where($where)->get();
```

Всё, что находится между `table()` и чтением, — полнотекстовый `match()`, `where()`, агрегаты,
фасетный поиск, `JOIN`, векторный поиск KNN, схема таблицы — относится к построителю запросов и
описано в [его документации](https://github.com/aVadim483/manticore-query-builder-php/blob/main/README.ru.md#документация).
Ниже описано только то, что этот пакет делает иначе.

## Алиас, фасад и контейнер

Алиас `\ManticoreDb` из примеров регистрирует сам пакет. Это самый короткий путь, но не
единственный — то же соединение доступно через фасад и через контейнер:

```php
use avadim\Manticore\Laravel\Facade as ManticoreDb;
use avadim\Manticore\Laravel\Manager;

// фасад, если глобальный алиас не по душе
ManticoreDb::table('products')->find($id);

// внедрение зависимости, если класс должен тестироваться без фреймворка вокруг
class ProductSearch
{
    private $manticore;

    public function __construct(Manager $manticore)
    {
        $this->manticore = $manticore;
    }

    public function find(string $text)
    {
        return $this->manticore->table('products')->match($text)->get();
    }
}
```

Все три пути отвечают одинаково: соединения у них общие, и запрос, построенный любым из них,
возвращает описанные ниже коллекции. Методы соединения `Manager` принимает через `__call()`, а
описывают их аннотации `@method` этого класса — поэтому IDE их подсказывает.

## Что возвращает чтение

Отдельный [построитель запросов](https://github.com/aVadim483/manticore-query-builder-php)
отвечает обычными массивами: коллекция означала бы лишнюю зависимость в библиотеке, не привязанной
к фреймворку. Внутри Laravel фреймворк уже есть, поэтому пакет отвечает так, как принято в Laravel:

```php
// Illuminate\Support\Collection с ключами от нуля
$rows = \ManticoreDb::table('products')->match('galaxy')->get();

$titles = $rows->map(fn ($row) => $row->title)->all();

// Одна строка - это объект Row
$row = \ManticoreDb::table('products')->where('price', '<', 1000)->first();
$row = \ManticoreDb::table('products')->find($id);

// pluck() тоже возвращает Collection, со вторым аргументом - с ключами по указанной колонке
$titles = \ManticoreDb::table('products')->pluck('title');
$titles = \ManticoreDb::table('products')->pluck('title', 'id');
```

`Row` читается и так и так, поэтому код, написанный под массивы отдельного построителя, продолжает
работать:

```php
$row->title;     // как объект, привычно для Laravel
$row['title'];   // как массив, как в отдельном построителе

$row->toArray();
$row->toJson();
json_encode($rows);   // массив объектов, как и ожидается от JSON API
```

Запись не изменилась и отвечает так же, как построитель: `insert()` возвращает `bool`,
`update()` и `delete()` — количество затронутых строк, `insertGetId()` — идентификатор.

## Когда запрос не выполнен

Отклонённое чтение бросает `avadim\Manticore\QueryBuilder\QueryErrorException`: ошибка в запросе —
это дефект, а не пустая выборка, и исключение говорит об этом там, где ошибка возникла:

```php
use avadim\Manticore\QueryBuilder\QueryErrorException;

try {
    $rows = \ManticoreDb::table('products')->match($text)->get();
}
catch (QueryErrorException $e) {
    report($e);

    $rows = collect();
}
```

Запись по-прежнему отвечает значением: `false` из `insert()`, ноль затронутых строк из `update()`
и `delete()`. Причина последнего запроса, удачного или нет, хранится в его результате:

```php
\ManticoreDb::lastResultSet()->error();
```

Чаще всего отклонённое чтение вызывает полнотекстовый запрос, введённый посетителем, поэтому его
стоит пропустить через `\ManticoreDb::escapeMatch($text)` прежде, чем он попадёт в `match()`.

## Постраничная навигация

```php
// Illuminate\Pagination\LengthAwarePaginator, номер страницы берётся из запроса
$products = \ManticoreDb::table('products')->match('galaxy')->paginate(15);

// ... и то же самое без COUNT(*) для подсчёта общего числа, когда шаблону нужны только
// "следующая" и "предыдущая"
$products = \ManticoreDb::table('products')->simplePaginate(15);
```

```blade
@foreach ($products as $product)
    {{ $product->title }}
@endforeach

{{ $products->links() }}
```

Аргументы те же, что и в Laravel: `paginate($perPage, $columns, $pageName, $page)`.

## Транзакции

Manticore выполняет `BEGIN` / `COMMIT` / `ROLLBACK` на real-time таблицах, поэтому транзакция
пишется так же, как в остальном Laravel. Транзакции реализованы в самом построителе запросов,
поэтому работает и статический вызов:

```php
\ManticoreDb::transaction(function ($connection) {
    $connection->table('products')->insert($data);
    $connection->table('log')->insert($record);
});

// ... или вручную
$connection = \ManticoreDb::connection();
$connection->beginTransaction();
$connection->table('products')->insert($data);
$connection->commit();   // либо rollBack()
```

В функцию обратного вызова передаётся соединение, а то, что она вернёт, становится результатом
`transaction()`. Исключение откатывает транзакцию и пробрасывается дальше; второй аргумент задаёт
число попыток. Точек сохранения в Manticore нет, поэтому вложенный `transaction()` только считает
уровень вложенности — записывает данные самый внешний коммит.

Любой SQL-запрос можно выполнить напрямую:

```php
\ManticoreDb::connection()->statement('FLUSH RAMCHUNK products');   // true, если сервер его принял
```

## Миграции

Таблица Manticore создаётся в миграции Laravel так же, как любая другая:

```php
use avadim\Manticore\QueryBuilder\Schema\SchemaTable;

class CreateManticoreProductsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        \ManticoreDb::create('products', function (SchemaTable $table) {
            $table->timestamp('created_at');
            $table->string('name');
            $table->text('description');
            $table->float('price');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        \ManticoreDb::drop('products', true);   // true - удалить, только если существует
    }
}
```

Транзакционного DDL в Manticore нет, поэтому упавшая на середине миграция оставляет всё, что уже
успела создать: пишите `down()` так, чтобы он отработал и на недостроенной таблице.

Таблица, которая уже в работе, меняется так же:

```php
class AddRatingToManticoreProducts extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        \ManticoreDb::addColumn('products', 'rating', 'int');
        \ManticoreDb::addColumn('products', 'summary', 'text');
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        \ManticoreDb::dropColumn('products', 'rating');
        \ManticoreDb::dropColumn('products', 'summary');
    }
}
```

Три отличия от миграции обычной СУБД:

* **Одна операция на выражение.** Сервер не принимает `ADD COLUMN a, ADD COLUMN b`, поэтому две
  колонки выше — это два запроса, и падение второго оставит первую добавленной: откатывать
  нечем.
* **У ранее записанных строк новая колонка остаётся пустой** — добавление не переиндексирует их.
  Полнотекстовое поле, добавленное так, найдёт только строки, записанные после него, поэтому
  данные нужно записать заново, чтобы колонка что-то значила.
* **Кеш схемы сбрасывается автоматически**, но только в том соединении, которое выполнило
  запрос, — о прочих см. [Долгоживущие процессы](#долгоживущие-процессы).

## Долгоживущие процессы

Соединение запоминает схему каждой таблицы, которую описывало: это избавляет от `DESCRIBE` перед
каждым запросом и приводит значения строки к типам PHP. Кеш живёт ровно столько, сколько живёт
соединение, — под php-fpm это длительность запроса, а под Octane, в обработчике очереди или в
команде планировщика заметно дольше.

Поэтому таблице, изменённой на стороне, кеш нужно сбросить:

```php
\ManticoreDb::forgetSchema();
```

Сбрасываются схемы всех таблиц соединения — выборочно по одной нельзя.

Свой кеш построитель поддерживает в актуальном состоянии сам: `create()`, `alter()`,
`addColumn()`, `dropColumn()`, `truncate()`, `rename()` и `drop()` сбрасывают схему той таблицы,
которую изменили, — но только в том соединении, которое выполнило запрос.

Устаревший кеш ничего не бросает. Типы колонок нужны в трёх местах: при чтении они приводят
значения строки к типам PHP, при `insert()` и `update()` — форматируют значения для SQL.
Незнакомая колонка форматируется по типу самого значения и обычно проходит, а вот изменившийся
тип — колонка была `string`, стала `integer` — даёт либо ошибку сервера, либо молча неверные
данные.

Условие для ручного сброса всегда одно: схему изменили мимо вашего соединения, а оно это
пережило. На практике это четыре случая.

* **`ALTER` через `statement()`.** Сырой SQL идёт мимо построителя, и сбросить кеш ему нечем:

  ```php
  \ManticoreDb::connection()->statement('ALTER TABLE products ADD COLUMN rating int');
  \ManticoreDb::forgetSchema();
  ```

* **Миграция из другого процесса.** `php artisan migrate` отработал, а обработчик очереди или
  Octane, поднятый до неё, живёт со старым кешом. Самый частый случай: обычный деплой так и
  выглядит.
* **Таблицу изменил кто-то ещё** — индексатор, соседний сервис, другое приложение на том же
  сервере.
* **Другое именованное соединение того же приложения.** Изменили через `connection('admin')`,
  читаете через `connection('default')` — это два разных кеша.

Под php-fpm вызывать `forgetSchema()` в контроллере почти всегда бессмысленно: соединение живёт
один запрос, и кеш не переживёт ответ. Отдельный случай — тесты: тест, который меняет таблицы
через `statement()` и переиспользует соединение, должен сбрасывать кеш в `setUp()`, иначе
следующий тест увидит схему предыдущего.

Сброс стоит одного `DESCRIBE` на таблицу при следующем обращении. `DESCRIBE` уходит служебным
запросом и в логгер не попадает, поэтому по логу устаревший кеш не отследить.

## Логирование

Пакет умеет писать запросы в логгер.

```php
// Включить логирование всего
\ManticoreDb::setLogger(\Log::getLogger());

// Включить логирование для указанного соединения
\ManticoreDb::connection('test')->setLogger(\Log::getLogger());

// Включить логирование для следующего запроса
\ManticoreDb::table('test')->match($match)->where($where)->setLogger(\Log::getLogger())->get();
```

Подойдёт любой PSR-3 логгер, `\Log::getLogger()` — логгер Laravel. Служебные запросы, которые
построитель отправляет сам, в том числе `DESCRIBE`, не логируются.

## Документация

Синтаксис запросов, схема таблиц и всё остальное, что умеет построитель, описано в
[документации](https://github.com/aVadim483/manticore-query-builder-php/blob/main/README.ru.md#документация)
пакета [avadim/manticore-query-builder-php](https://packagist.org/packages/avadim/manticore-query-builder-php).
Документация самого Manticore Search — https://manual.manticoresearch.com/

## Тесты

```sh
composer install
vendor/bin/phpunit
```

Части тестов нужен запущенный сервер ManticoreSearch. По умолчанию используется `127.0.0.1:9306`,
адрес меняется переменными окружения `MANTICORE_TEST_HOST` и `MANTICORE_TEST_PORT` в
`phpunit.xml.dist`. Если сервер недоступен, такие тесты пропускаются, остальные выполняются.
Таблицы, созданные тестами, называются `phpunit_*` и удаляются после прогона.
