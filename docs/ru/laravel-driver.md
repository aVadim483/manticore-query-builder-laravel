# Драйвер Laravel Database

В ветке `dev-4.x` добавлен экспериментальный драйвер на стандартном Laravel Query Builder.
Его реализация работает с PDO напрямую и не вызывает самостоятельный PHP-билдер.
Зависимость от PHP-билдера пока остаётся в пакете для существующего API версии 3.x.

## Конфигурация

Провайдер пакета автоматически регистрирует драйвер `manticore`. Добавьте именованное
соединение в массив `connections` файла `config/database.php` приложения:

```php
'manticore' => [
    'driver' => 'manticore',
    'host' => env('MANTICORE_HOST', '127.0.0.1'),
    'port' => env('MANTICORE_PORT', 9306),
    'username' => env('MANTICORE_USER'),
    'password' => env('MANTICORE_PASS'),
    'timeout' => env('MANTICORE_TIMEOUT', 5),
    'prefix' => '',
    'options' => [], // настройки PDO
],
```

Новый драйвер читает `database.connections` независимо от прежнего `config/manticore.php`.
Префиксы работают по правилам Laravel: `prefix => 'app_'` автоматически применяется к
`table('products')`. Плейсхолдер `?products` прежнего билдера не используется. Имя базы не требуется.

Отдельный `avadim\Manticore\Laravel\DatabaseServiceProvider` регистрирует только новый драйвер
без инициализации прежнего статического билдера. Основной провайдер пакета тоже подключает его.

## Запросы и результаты

```php
use Illuminate\Support\Facades\DB;

$connection = DB::connection('manticore');
$query = $connection->table('products')
    ->select('id', 'title', 'price')
    ->match('galaxy')
    ->where('price', '>', 100)
    ->option('max_matches', 2000)
    ->orderBy('id');

$rows = $query->limit(50)->get(); // Collection<stdClass>
$row = $connection->table('products')->find(1, ['id', 'title']); // stdClass|null
$rows = $connection->select('SELECT id FROM products WHERE price > ?', [100]); // array<stdClass>
```

`select()` на билдере только задаёт колонки и возвращает этот билдер. Сырые методы принимают
параметры Laravel: например, `whereRaw('price > ?', [100])` и
`selectRaw('price * ? as adjusted', [2])`. Доступны `toSql()`, `getBindings()` и `toRawSql()`.
`match()` передаёт полнотекстовое выражение Manticore как параметр; поисковые операторы
не экранируются как обычный текст.
Для обычного текста посетителя предварительно вызовите `ManticoreQueryBuilder::escapeMatch()`.

Соединение требует эмуляции подготовленных выражений PDO. На проверенном Manticore 28.6.6
серверные prepared UPDATE округляют дробные значения, поэтому отключение эмуляции отклоняется.
Конечные PHP float при выполнении передаются как проверенные числовые литералы; остальные
параметры остаются привязанными через PDO. SQL, параметры, события и исключения Laravel сохраняют
исходные значения. Это работает для позиционных и именованных параметров. INF и NAN отклоняются.

`option($name, $value)` принимает конечные числа, экранированные строки и карты положительных
целых весов для `field_weights` / `index_weights`. Повторная установка заменяет значение,
`option($name, null)` удаляет настройку. Названия ranker проверяются.

```php
$query->fieldWeights(['title' => 10, 'body' => 2])->ranker('bm25')->withScore();
$query->ranker('expr', 'sum(lcs*user_weight)*1000+bm25');
$query->option('comment', 'catalog search');
```

Для `expr` и `export` обязательна непустая формула; остальные встроенные ranker её не принимают.
Формулы экранируются как SQL-строки и выполняются движком выражений ранжирования Manticore.
Справочник: [поисковые настройки Manticore](https://manual.manticoresearch.com/Searching/Options).

`insert()`, `insertGetId()`, `update()` атрибутов и `delete()` используют выполнение запросов
и обработку ошибок Laravel. Ошибки SQL, включая отказ записи, бросают
`Illuminate\Database\QueryException`. События соединения, `DB::listen()`, журнал запросов
и `pretend()` проходят через стандартные механизмы Laravel.
`DB::disconnect()`, `DB::reconnect()` и `DB::purge()` управляют новым пулом соединений.
SQL автоматически не повторяется после ошибки соединения: потерянный ответ не доказывает,
что сервер не выполнил запись.

`lazy()`, `lazyById()` и `cursor()` возвращают `LazyCollection`. `cursor()` выполняет один SELECT,
а `lazy()` получает страницы. Буферизация PDO управляется настройками соединения;
ленивая коллекция сама по себе не гарантирует отсутствие буферизации результата.

## Метаданные поиска

```php
$query = $connection->table('products')->match('galaxy')->withScore()->limit(20);
$result = $query->search(['id', 'title']);
$rows = $result->rows(); // Collection<stdClass>, включая колонку _score
$meta = $result->meta();

$meta->totalFound();  // int|null
$meta->total();       // int|null
$meta->time();        // float|null
$meta['total_found'];
$meta->toArray();
```

`search()` получает строки SELECT и SHOW META на одном PDO-соединении до вызова обработчиков
событий запросов. Снимок метаданных сохраняется после следующих команд и after-query callbacks.
`Meta` неизменяем, поддерживает перебор, подсчёт полей и JSON; отсутствующие поля возвращают
`null`. Чтение результата и его метаданных не выполняет дополнительных SQL-запросов.

SELECT и SHOW META попадают в события и журнал после получения снимка. Длительность SELECT
включает получение метаданных; SHOW META также имеет отдельную запись времени.
В `pretend()` служебный запрос не выполняется.

Обычный `get()` возвращает стандартную коллекцию без SHOW META; первый вызов может загрузить DESCRIBE.
Для отдельного чтения `$query->getMeta()` или `$connection->getMeta()` выполняет SHOW META
текущей сессии. Следующая команда, в том числе из обработчика события, может заменить эти
метаданные; используйте `search()`, когда строки и метаданные нужно получить вместе.

## MVA и геопоиск

```php
$rows = $connection->table('products')
    ->whereMvaAny('categories', [2, 3]) // ANY(categories) IN (2, 3)
    ->whereMvaAll('categories', '>=', 1)
    ->get();

$rows = $connection->table('places')
    ->withDistance('lat', 'lon', 55.75, 37.6, 'km')
    ->select('id', 'title')
    ->whereGeoDistance('lat', 'lon', 55.75, 37.6, 10, 'km')
    ->orderByDistance('lat', 'lon', 55.75, 37.6, 'km')
    ->get(); // в строках есть _distance
```

MVA-методы принимают скалярные сравнения, наборы IN / NOT IN и диапазоны BETWEEN / NOT BETWEEN.
Два аргумента означают равенство для скаляра и IN для массива. `orWhereMvaAny()` и
`orWhereMvaAll()` доступны и во вложенных условиях Laravel. Значения должны быть целыми числами
или строками с десятичными целыми в диапазоне PHP int; строки преобразуются с проверкой точности.
Пустые наборы, недопустимые операторы и диапазоны без двух границ отклоняются.
ALL проверяет каждый элемент атрибута на соответствие условию, а не наличие всех переданных значений.

Координаты задаются в градусах, широта первой; единицы расстояния — `m`, `km` или `mi`.
Поддерживаются числовые атрибуты и JSON-пути вроде `location.lat`. Координаты и радиус проверяются
на допустимый диапазон и конечность. `withDistance()` сохраняет вычисляемую колонку после
последующего `select()` и принимает имя колонки последним аргументом. Конфликтующие и
зарезервированные имена отклоняются.

Фильтр и сортировка работают и без `withDistance()`: служебные вычисляемые колонки удаляются
из результатов, включая `search()` и курсоры. Вложенные геофильтры, агрегаты и пагинация получают
нужные выражения. `orderByDistance()` сохраняет ранее заданную сортировку.

## Векторный поиск KNN

Индексируемый вектор объявляется как `$table->floatVector('embedding', 3, 'l2')`. Размерность
должна быть положительной, метрика — `l2`, `cosine` или `ip`. Без размерности создаётся обычный
вектор без индекса. Для HNSW на сервере нужна библиотека KNN.

```php
$result = $connection->table('products')
    ->knn('embedding', [1.0, 0.0, 0.0], 20, ['ef' => 100])
    ->withKnnDistance()->select('id', 'title')
    ->match('galaxy')->where('stock', '>', 0)
    ->orderByKnnDistance()->search();
```

`knn()` устанавливает LIMIT равным k; последующий `limit()` может изменить число выдаваемых строк.
Используется совместимый синтаксис `KNN(field, k, vector, options)`. Выбор кандидатов выполняет
сервер; k не гарантирует ровно k строк после фильтрации или при наличии нескольких чанков.
Разрешён один KNN верхнего уровня с AND-фильтрами. OR, вложенный KNN и запись с KNN отклоняются.
Вектор — непустой список конечных чисел; несовпадение размерности диагностирует сервер.
Настройки: `ef`, `oversampling`, `rescore`, `early_termination`, `prefilter`; их доступность зависит
от версии сервера. `withKnnDistance()` добавляет `_knn_distance` или заданный псевдоним,
`orderByKnnDistance()` сортирует по служебной колонке, которая удаляется из результата.
Справочник: [KNN в Manticore](https://manual.manticoresearch.com/Searching/KNN).

## Фасеты и подсветка

```php
$result = $connection->table('products')->match('galaxy')->limit(20)
    ->withHighlight('title', ['before_match' => '<em>', 'after_match' => '</em>'])
    ->facet('category', 'categories', 10, 'count', 'desc')
    ->facet('details.brand', 'brands', 20, 'value', 'asc')
    ->search(['id', 'title']);

$result->rows()[0]->_highlight; // строка с подсветкой
$result->facet('categories'); // Collection<stdClass>, колонки categories и count(*)
$result->facets(); // массив по именам фасетов
```

`facet(column, alias, limit, sort, direction)` принимает атрибут, MVA или JSON-путь, необязательный
уникальный псевдоним, положительный лимит бакетов, сортировку `count` / `value` и `asc` / `desc`.
Фильтры основного запроса действуют и на фасеты; лимит строк не ограничивает их счётчики.
Бакеты и метаданные сохраняются до вызова обработчиков событий. MVA-бакеты содержат отдельные
значения, счётчики — целые числа. Значения JSON-путей сохраняют типы PDO. Неизвестное имя фасета
вызывает `OutOfBoundsException`. Фасеты требуют `search()`: `get()`, курсоры, подсчёт для пагинации,
агрегаты, основной GROUP BY/HAVING и запись с фасетами отклоняются. Сырые выражения фасетов,
distinct-счётчики и отдельные фильтры каждого фасета пока не представлены в этом API.

`withHighlight(fields, options, query, alias)` принимает имя поля, список полей или пустой список
для всех сохранённых полей. По умолчанию добавляет `_highlight`, сохраняется после `select()`
и работает в `get()`, `search()` и курсорах. Без отдельного поискового выражения используется MATCH
основного запроса. Настройки и альтернативный запрос экранируются. Результат — строка с разделителями
полей и фрагментов Manticore; исходные поля должны храниться на сервере. Для вывода HTML приложение
само выбирает подходящие маркеры, настройки удаления HTML и обработку исходного содержимого.
Справочники: [фасеты](https://manual.manticoresearch.com/Searching/Faceted_search),
[подсветка](https://manual.manticoresearch.com/Searching/Highlighting).

## CALL-методы и автодополнение

```php
$keywords = $connection->keywords('gal*', 'products', ['stats' => 1]);
$suggestions = $connection->suggest('galaxi', 'products');
$lastWord = $connection->qsuggest('a galaxi', 'products');
$snippets = $connection->snippets(['galaxy phone', 'galaxy tablet'], 'products', 'galaxy');
$completions = $connection->autocomplete('gal', 'products', ['fuzziness' => 0]);

// Все наборы строк, включая пустые, как array<Collection<stdClass>>:
$sets = $connection->call('KEYWORDS', ['galaxy', 'products'], ['stats' => 1]);
```

Именованные методы возвращают первый набор строк как коллекцию и добавляют префикс соединения
к имени таблицы. Универсальный `call()` принимает позиционные скалярные аргументы и непустые
плоские списки; именованные настройки передаются как параметры `value AS name`. Он использует
аргументы в точности как переданы, без добавления префикса. Все наборы читаются, а курсор
закрывается до событий запросов. Типы результата соответствуют PDO. Работают журнал запросов,
`pretend()` и `QueryException` Laravel. Ошибки не вызывают автоматический повтор или подмену
ответа пустой коллекцией.

KEYWORDS, SUGGEST, QSUGGEST и SNIPPETS работают без Buddy. Для AUTOCOMPLETE нужны Buddy и
`min_infix_len` у таблицы, например `$table->setting('min_infix_len', 2)`. Если условия не выполнены,
метод передаёт ошибку сервера. Справочник:
[автодополнение Manticore](https://manual.manticoresearch.com/Searching/Autocomplete).
Локальные нативные тесты работают без Buddy; `MANTICORE_TEST_BUDDY=1` включает обязательную
проверку автодополнения. В CI этот флаг установлен, ошибка Buddy завершит проверку с ошибкой.

## Глубокие страницы

`paginate()` и `simplePaginate()` возвращают пагинаторы Laravel. Если окно SELECT превышает
стандартные 1000 совпадений, драйвер автоматически добавляет `max_matches = offset + limit`,
пока `max_matches` не задан явно. Это работает и для `lazy()` и других обходов со смещением.
Компиляция не меняет настройки исходного запроса. Явно заданное меньшее окно по-прежнему может
вызвать ошибку сервера; для больших обходов по ID используйте `chunkById()` или `lazyById()`.
Ограничения сервера действуют и для SELECT без явного `limit()`.

## Запись и транзакции

`insert()` принимает документ или пакет документов. `insertGetId()` и `replaceGetId()` получают
ID до вызова обработчиков событий, которые могут выполнить другую запись. `replace()` возвращает
boolean и заменяет документ целиком по ID: пропущенные поля сбрасываются к значениям сервера,
включая пустой MVA, JSON null и числовой ноль. Для изменения полнотекстовых полей используйте
REPLACE. Непустой `upsert()` бросает `LogicException`: атомарное частичное обновление по произвольным
уникальным ключам не реализовано.

```php
$connection->table('products')->replace([
    'id' => 42, 'title' => 'Galaxy', 'price' => 199.5,
]);
$connection->transaction(function () use ($connection) {
    $connection->table('products')->where('id', 42)->update(['price' => 189.5]);
});
```

Поддерживается один уровень транзакций и after-commit callbacks Laravel. Вложенные транзакции,
DDL внутри транзакции и число попыток, отличное от одного, отклоняются. При сбое BEGIN, COMMIT
или ROLLBACK соединение с неопределённым состоянием отключается; SQL и callback транзакции
автоматически не повторяются. Если ошиблись и callback, и откат, `TransactionRollbackException`
сохраняет обе ошибки в `callbackError` и `rollbackError`. Ошибка получения ID возможна после
успешной записи и не приводит к повторному INSERT. UPDATE ограничен операциями над атрибутами,
которые поддерживает Manticore.

## Схема и миграции

```php
use avadim\Manticore\Laravel\Database\ManticoreBlueprint;
use Illuminate\Support\Facades\Schema;

Schema::connection('manticore')->create('products', function (ManticoreBlueprint $table) {
    $table->id(); // встроенный 64-битный ID документа Manticore
    $table->text('title'); // полнотекстовое поле
    $table->string('sku'); // строковый атрибут
    $table->float('price');
    $table->json('details');
    $table->multi('categories');
    $table->multi64('external_ids');
    $table->floatVector('embedding');
    $table->setting('min_word_len', 2);
});

$schema = Schema::connection('manticore');
$schema->hasTable('products');
$schema->getColumnListing('products');
$schema->table('products', fn (ManticoreBlueprint $table) => $table->integer('stock'));
$schema->dropIfExists('products');
```

Также доступны типы `integer`, `bigInteger`, `boolean` и `timestamp`. Длина строки и точность
float из объявления Laravel не создают SQL-ограничений; `float()` соответствует нативному float
Manticore. Для TEXT доступны флаги `stored()` и `indexed()`. Настройки CREATE экранируются.
ALTER пока поддерживает добавление и удаление колонок в порядке объявления. Изменение типа,
переименование, nullable/default, внешние ключи, обычные индексы Laravel и неподдерживаемые
модификаторы отклоняются до выполнения первого SQL из этого Blueprint.

Используйте обычные миграции с `protected $connection = 'manticore'` и явным
`Schema::connection('manticore')`. Таблицу учёта миграций храните в основной SQL-базе приложения.
DDL не поддерживает транзакции: ошибка сервера посередине ALTER из нескольких команд оставляет
предыдущие изменения применёнными. Учитывайте это состояние в `down()` и при восстановлении.

## Преобразование атрибутов и кеш схемы

Перед первым чтением или записью Query Builder выполняет DESCRIBE и кеширует схему отдельно
для каждой таблицы и соединения. JSON-массивы и объекты сериализуются при записи и читаются
как ассоциативные массивы; JSON null сохраняется как null. MVA/MVA64 и векторы принимают
массивы-списки и возвращают массивы. Целые числа сохраняют точность: значения за пределами PHP int
возвращаются строками. Boolean читается как boolean, числовые атрибуты — как числа.
`DateTimeInterface` в timestamp преобразуется в секунды Unix. SQL Expressions передаются напрямую.

Преобразование работает в `get()`, `first()`, `pluck()`, `search()`, курсорах и ленивых коллекциях,
включая простые псевдонимы колонок. Типы вычисляемых выражений не определяются автоматически.
Сырой `$connection->select()` сохраняет типы PDO. Настройка соединения `cast_attributes => false`
отключает преобразование и загрузку схемы; структурированные значения тогда нужно сериализовать вручную.

Schema Builder и сырые CREATE/ALTER/DROP/TRUNCATE/RENAME сбрасывают кеш, в том числе при ошибке.
Переподключение также очищает его. После внешнего DDL вызовите `$connection->forgetSchema('products')`
с логическим именем без префикса либо `$connection->forgetSchema()` для всех таблиц.
Загрузка схемы выполняется перед поисковым SELECT и не затирает снимок его метаданных.

## Текущие ограничения

Первая реализация проверена на Manticore 28.6.6. Минимальная поддерживаемая версия сервера
и совместимость с `latest` будут закреплены перед выпуском.

- JOIN, UNION, SELECT DISTINCT, блокировки и group limits пока отклоняются драйвером.
  Также отклоняются подзапросы WHERE EXISTS и агрегаты с HAVING.
- UPDATE и DELETE отклоняют сортировку, лимит, смещение, JOIN, поисковые настройки и вычисляемые выражения поиска,
  чтобы не игнорировать их молча. Обновлению полнотекстовых полей нужна отдельная обработка.
- Атомарный `upsert()` не поддерживается. Расширенные настройки фасетов и гибридное объединение
  результатов пока не представлены отдельными методами.
- Разделение чтения и записи и совместимость с Eloquent пока не подтверждены.

Существующие `ManticoreDb`, `Manager` и `Row` сохраняют поведение, описанное в основном README.
Они не делят PDO-соединение с новым драйвером. Не смешивайте два API внутри одной транзакции
и не рассчитывайте прочитать через один API метаданные соединения другого.
