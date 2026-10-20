[English](README.md) | **Русский**

[![GitHub Release](https://img.shields.io/github/v/release/aVadim483/manticore-query-builder-laravel)](https://packagist.org/packages/avadim/manticore-query-builder-laravel)
[![Packagist Downloads](https://img.shields.io/packagist/dt/avadim/manticore-query-builder-laravel?color=%23aa00aa)](https://packagist.org/packages/avadim/manticore-query-builder-laravel)
[![GitHub License](https://img.shields.io/github/license/aVadim483/manticore-query-builder-laravel)](https://packagist.org/packages/avadim/manticore-query-builder-laravel)
[![Static Badge](https://img.shields.io/badge/php-%3E%3D8.2-005fc7)](https://packagist.org/packages/avadim/manticore-query-builder-laravel)
[![Static Badge](https://img.shields.io/badge/laravel-11%20--%2013-ff2d20)](https://packagist.org/packages/avadim/manticore-query-builder-laravel)
[![tests](https://github.com/aVadim483/manticore-query-builder-laravel/actions/workflows/tests.yml/badge.svg)](https://github.com/aVadim483/manticore-query-builder-laravel/actions/workflows/tests.yml)

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
* [Отличия от Laravel Query Builder](#отличия-от-laravel-query-builder)
* [Алиас, фасад и контейнер](#алиас-фасад-и-контейнер)
* [Что возвращает чтение](#что-возвращает-чтение)
* [Метаданные поиска](#метаданные-поиска)
* [Результаты записи и отложенные ID](#результаты-записи-и-отложенные-id)
* [Когда запрос не выполнен](#когда-запрос-не-выполнен)
* [Постраничная навигация](#постраничная-навигация)
* [Обход большого результата](#обход-большого-результата)
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

* PHP 8.2 или выше с расширениями PDO, PDO MySQL, mbstring и JSON
* Laravel 11, 12 или 13 либо Lumen 11
* [avadim/manticore-query-builder-php](https://github.com/aVadim483/manticore-query-builder-php) 2.6 или выше в пределах 2.x — ставится вместе с пакетом
* Доступный сервер Manticore Search по SQL-интерфейсу, по умолчанию порт 9306. HTTP/JSON API не используется.

Версия 2.x работает на Laravel с 6 по 13 и на PHP 7.4 и остаётся доступной приложению, которое
ещё не переехало: старый Laravel этим не ломается, ему просто продолжает ставиться 2.x.

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
| MANTICORE_TIMEOUT      | 5                     | Таймаут установки соединения в секундах                         |
| MANTICORE_PREFIX       |                       | Префикс, заменяющий подстановку `?` перед именем таблицы        |
| MANTICORE_FORCE_PREFIX | false                 | Добавлять префикс ко всем именам, а не только к записанным с `?` |
| MANTICORE_LOG_CHANNEL  |                       | Канал логирования, куда идут запросы; пока пусто — не логируется ничего |

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

Начиная с версии зависимости 2.6 также доступны `whereMvaAny()` / `whereMvaAll()` для
многозначных атрибутов, `withDistance()` / `whereGeoDistance()` / `orderByDistance()` для
геопоиска и `callAutocomplete()` для подсказок. Аргументы и требования к серверу описаны в
[руководстве по поиску](https://github.com/aVadim483/manticore-query-builder-php/blob/v2.6.0/docs/ru/searching.md).
Для автодополнения нужны Manticore Buddy и таблица с инфиксами. Строки и метаданные можно
получать отдельно, а ID записанных документов загружать по требованию, как описано ниже.

## Отличия от Laravel Query Builder

Пакет использует собственные классы построителя запросов и соединения Manticore. Он не
регистрирует драйвер для `DB::connection('manticore')` и не реализует весь
[API Laravel Query Builder](https://laravel.com/docs/queries). Используйте `ManticoreDb` или
внедрённый `Manager`; перед переносом цепочки `DB::table()` сверяйтесь с документацией
базового построителя.

| Область | Laravel Query Builder | Этот пакет |
|---|---|---|
| Строки результата | `get()` возвращает `Collection` объектов `stdClass` | `Collection` объектов `Row` с индексами от нуля, доступом к полям как к свойствам и элементам массива. `Row` хранит данные; изменение поля ничего не сохраняет, связей Eloquent и метода `save()` у него нет. |
| Чтение сырого SQL | `DB::select()` возвращает массив объектов `stdClass` | `ManticoreDb::select()` возвращает массив базового построителя без оборачивания строк в `Row`. CALL-методы, например `callAutocomplete()`, также возвращают массивы. |
| Поиск | Использует полнотекстовый API драйвера БД | `match()` принимает язык запросов Manticore; `whereKnn()`, MVA, геопоиск, фасеты и метаданные работают по правилам Manticore. `whereMvaAll()` проверяет каждый элемент атрибута на соответствие условию, а не наличие всех переданных значений. |
| Ошибки SQL | Ошибки БД обычно бросают `Illuminate\Database\QueryException` | Отклонённое чтение бросает `QueryErrorException` базового построителя, запись возвращает `false` или `0`. Проверяйте `lastResultSet()->error()`, чтобы отличить ошибку от записи, не изменившей ни одной строки. |
| Потоковое чтение | `lazy()` и `cursor()` возвращают `LazyCollection`; `cursor()` выполняет один запрос | Оба метода возвращают `Generator` и читают страницы через `LIMIT`/`OFFSET`. Для полного обхода без растущего смещения используйте `chunkById()`. |
| Пагинация | Следует правилам пагинации SQL-базы | `paginate()` / `simplePaginate()` возвращают пагинаторы Laravel и увеличивают `max_matches` под страницу, если он не задан явно. Остальным обходам со смещением по-прежнему нужен подходящий `maxMatches()`. |
| Upsert | Использует обработку конфликтов самой БД с уникальными индексами | `upsert()` сначала ищет каждую строку по `$uniqueBy`, объединяет указанные колонки `$update` с найденной строкой и записывает её через `REPLACE` по ID документа либо вставляет новую. Это поиск на каждую строку, а не одна атомарная команда обработки конфликтов. |
| Схема | Laravel `Schema` и `Blueprint` | `ManticoreDb::create()`, базовый `SchemaTable` и ALTER-методы Manticore. Совместимый протокол SQL не превращает Manticore в MySQL. |
| Транзакции | Точки сохранения и повторы зависят от драйвера БД | В Manticore нет savepoint: вложенные вызовы считают уровни, а откат отменяет всю транзакцию. Возвращённый записью `false` не вызывает исключения и автоматического отката; бросьте исключение явно, если ошибка должна прервать транзакцию. |

Сырые выражения и параметры тоже используют API базового построителя. Например, именованный
параметр задаётся явно: обычная строка `':price'` не считается плейсхолдером.

```php
$rows = \ManticoreDb::table('products')
    ->where('price', '>', \ManticoreDb::param('price'))
    ->bind(['price' => 100])
    ->get();
```

Для сырого выражения используйте `ManticoreDb::raw()`. Объекты Laravel `DB::raw()` и соглашения
о позиционных параметрах не взаимозаменяемы с этим API. Обычный текст посетителя передавайте
через `match(ManticoreDb::escapeMatch($text))`; синтаксис запросов Manticore передавайте напрямую,
только если его нужно интерпретировать как поисковое выражение.

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

`first()` и `find()` возвращают `null`, если ничего не нашлось. Там, где это не ветка кода, а 404,
есть пара методов, которые так и говорят:

```php
// Illuminate\Database\RecordsNotFoundException — Laravel сам отвечает на него 404
$row = \ManticoreDb::table('products')->where('sku', $sku)->firstOrFail();
$row = \ManticoreDb::table('products')->findOrFail($id);
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

## Метаданные поиска

`get()` получает строки и выполняет `SHOW META`. `getRows()` пропускает `SHOW META`, сохраняя
тот же результат: `Collection` объектов `Row` с индексами от нуля. Если метаданные нужны
отдельно, получите их сразу после поиска, до следующей команды на этом соединении:

```php
$query = \ManticoreDb::table('products')->match('galaxy')->limit(20);
$rows = $query->getRows();
$meta = $query->getMeta();

$meta->totalFound();       // int|null
$meta->total();            // int|null
$meta->time();             // float|null
$meta['total_found'];      // то же значение, что у totalFound()
$meta->toArray();
```

`Meta` неизменяем, поддерживает перебор и подсчёт полей; отсутствующие поля возвращают `null`.
`getMeta()` выполняет `SHOW META` на соединении запроса и заменяет его последний результат
результатом этой служебной команды. Чтобы прочитать уже полученные через `get()` метаданные
без нового SQL-запроса, сохраните результат поиска:

```php
$rows = $query->get();
$result = $query->lastResultSet();
$meta = $result->metaObject();   // без SQL
$loaded = $result->hasMeta();
$values = $result->meta();      // прежний API с массивом
```

После `getRows()` у результата поиска нет загруженных метаданных: `hasMeta()` вернёт `false`.
Последующий `getMeta()` читает текущие метаданные соединения, а не заполняет прежний результат.

## Результаты записи и отложенные ID

`insert()` и `replace()` возвращают `bool`. Для одного ID используйте `insertGetId()` /
`replaceGetId()`, для полного результата, включая несколько ID, — `insertResultSet()` /
`replaceResultSet()`. Эти методы получают ID сразу. Примеры записи нескольких строк и
обработки ошибок есть в [руководстве по записи](https://github.com/aVadim483/manticore-query-builder-php/blob/v2.6.0/docs/ru/writing.md).

По умолчанию даже булевые методы записи получают ID сразу. Чтобы избежать служебного запроса,
когда ID обычно не используются, добавьте `'lazy_insert_ids' => true` в нужное соединение
файла `config/manticore.php`. Настройка выключена по умолчанию; отдельной переменной окружения
для неё в этом пакете нет.

```php
$query = \ManticoreDb::table('products');
if (!$query->insert(['title' => 'Galaxy', 'price' => 100])) {
    throw new \RuntimeException((string)$query->lastResultSet()->error());
}
$result = $query->lastResultSet();
$id = $result->result();   // при lazy_insert_ids загрузка ID происходит здесь
```

Получите отложенные ID **до любой следующей команды на том же клиенте/соединении**, включая
чтение и служебные запросы. Устаревший результат бросает `LogicException`; загруженные ID
кешируются. Если загрузка ID после успешной записи завершается ошибкой, `result()` бросает
и запоминает её, а статус успешной записи сохраняется. Пользовательские клиенты без поддержки
отложенных ID продолжают получать их сразу.

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

Сервер просматривает `max_matches` строк результата и не больше — тысячу, если в конфигурации не
сказано иначе, — и окно за этой границей даёт не пустую страницу, а ошибку `offset out of bounds`.
Пагинатор по пятнадцать строк упирается в неё на 68-й странице, ссылку на которую сам же и
нарисовал страницей раньше. Поэтому оба метода просят у сервера ровно столько совпадений, сколько
нужно запрошенной странице, и только когда ей нужно больше стандартного; явный `maxMatches()`
остаётся вашим — и больший, и меньший. Глубокая страница всё равно стоит серверу сортировки всего,
что перед ней: это природа `LIMIT`/`OFFSET`, а не особенность пакета.

## Обход большого результата

Результат, который не читается целиком, обходят постранично. Любой обход отдаёт то же, что и
остальные чтения: страница — `Collection`, строка — `Row`.

```php
// страницами по 500, пока результат не кончится или колбэк не вернёт false
\ManticoreDb::table('products')->orderBy('id')->chunk(500, function ($rows, $page) {
    foreach ($rows as $row) {
        // ... $row->title
    }
});

// тот же обход, но по колонке id
\ManticoreDb::table('products')->chunkById(500, function ($rows, $page) { /* ... */ });

// построчно, со сквозным номером
\ManticoreDb::table('products')->each(function ($row, $number) { /* ... */ });

// генератор из Row: следующая страница запрашивается, только когда до неё дошли
foreach (\ManticoreDb::table('products')->orderBy('id')->lazy(500) as $row) {
    // ...
}

// единственная подходящая строка: RecordsNotFoundException, если её нет,
// MultipleRecordsFoundException, если их больше одной
$product = \ManticoreDb::table('products')->where('sku', $sku)->sole();
```

`cursor()` — синоним `lazy()`. Оба возвращают генератор, а не `LazyCollection`, потому что так
объявлено в построителе запросов; если нужны методы коллекции — `LazyCollection::make($query->lazy())`.

**Обходить результат целиком следует через `chunkById()`.** `chunk()`, `each()` и `lazy()` листают
через `LIMIT`/`OFFSET`, то есть упираются в тот же `max_matches`, что и постраничная навигация:
обход более чем тысячи строк не заканчивается раньше времени, а падает с `offset out of bounds`, и
чтобы его дописать, пришлось бы поднять `maxMatches()` до размера всего результата. Кроме того, им
нужен зафиксированный порядок: две страницы запроса без `orderBy()` — это два отдельных поиска, и
при неустойчивом порядке одна строка придёт дважды, а другая не придёт вовсе. У `chunkById()` нет
ни того, ни другого: он запрашивает строки после последнего id предыдущей страницы, поэтому ничего
не теряется и никакой offset не растёт.

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

Сбрасываются схемы всех таблиц соединения — выборочно по одной нельзя. У приложения, читающего через
несколько именованных соединений, и кешей несколько, а вызов выше добирается только до
соединения по умолчанию; менеджер сбрасывает все:

```php
app('manticore')->forgetSchemas();   // все соединения, которые он создал
```

(Менеджер знает те соединения, которые создал сам; открытое через `\ManticoreDb::connection()` в
их число не входит и свой кеш сохранит.)

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

В воркере соединение переживает не только свой кеш схемы: сам дескриптор может оказаться мёртвым —
сервер закрыл его за ночь простоя или он потерялся вместе с сетью, — и с этого момента через него
не проходит ни один запрос. Соединение сбрасывается по имени, а следующее обращение открывает новое:

```php
app('manticore')->purge();                 // соединение по умолчанию
app('manticore')->purge('analytics');      // именованное

$connection = app('manticore')->reconnect();   // ... и сразу открыть заново
```

Объект при этом не закрывается: тот, кто держит на него ссылку, продолжает им пользоваться, а
дескриптор освобождается вместе с последней ссылкой. Оба метода убирают соединение и из пула
построителя, поэтому `\ManticoreDb::connection()` тоже отдаст новое.

## Логирование

Достаточно назвать канал логирования приложения — и запросы окажутся в том же логе, что и всё
остальное:

```dotenv
MANTICORE_LOG_CHANNEL=stack
```

Канал — это PSR-3-логгер, а именно такой и нужен построителю запросов, поэтому он передаётся как
есть при загрузке пакета. Пока переменная пуста — а это значение по умолчанию — не логируется
ничего.

Логгер можно назначить и руками, на любой глубине:

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
