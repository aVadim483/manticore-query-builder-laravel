[English](../upgrading-3.x-to-4.x.md) | [Русский](upgrading-3.x-to-4.x.md)

# Переход с 3.x на нативный драйвер Laravel

Руководство относится к драйверу в ветке `dev-4.x`; стабильная версия 4.0 ещё не выпущена.
API 3.x и Composer-зависимость от `avadim/manticore-query-builder-php` удалены.
Нативный драйвер работает напрямую через Laravel Database
и PDO. Ниже описаны изменения приложения: автоматического перенаправления старых вызовов нет.

Смена соединения сама по себе не переименовывает, не пересоздаёт и не переиндексирует таблицы.
Сохраните физические имена и проверьте префиксы перед переключением приложения.
Полный API и требования к серверу описаны в [справочнике драйвера](laravel-driver.md).

## 1. Поиск старых вызовов и точки входа

Проверьте код приложения, провайдеры, задания, тесты и миграции:

```sh
rg -n 'ManticoreDb|avadim\\Manticore\\(Laravel|QueryBuilder)|SchemaTable|lastResultSet|insertResultSet|replaceResultSet|lazy_insert_ids|force_prefix' app config database tests
rg -n "app\(['\"]manticore|MANTICORE_|whereKnn|callAutocomplete|->highlight\(|->cursor\(" app config database tests
```

| Компонент 3.x | Замена в нативном драйвере |
| --- | --- |
| `\ManticoreDb`, пакетный `Facade` | `Illuminate\Support\Facades\DB::connection('manticore')` |
| `app('manticore')`, пакетный `Manager` | Laravel `DatabaseManager` с явным именем соединения |
| Типы пакетных `Connection`, `Query` | `Database\ManticoreConnection`, `Database\ManticoreQueryBuilder` в `avadim\Manticore\Laravel` |
| `Row` | `stdClass` либо явно создаваемый DTO приложения |
| `SchemaTable` PHP-билдера | `Database\ManticoreBlueprint` через Laravel `Schema` |
| `ResultSet` PHP-билдера | Обычные результаты Laravel; `Database\SearchResult` для строк, метаданных и фасетов |
| `QueryErrorException` PHP-билдера | `Illuminate\Database\QueryException` при ошибке SQL |

Не заменяйте классы простыми псевдонимами: различаются конструкторы, сигнатуры, результаты
и обработка ошибок. Вместо внедрения `Manager` можно внедрить
`Illuminate\Database\DatabaseManager` и вызвать `$database->connection('manticore')`.
Соединение Laravel по умолчанию обычно указывает на основную SQL-базу приложения.

## 2. Перенос конфигурации соединения

Добавьте запись в существующий массив `connections` файла `config/database.php`:

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
    'options' => [], // Параметры PDO
],
```

Это элемент массива, а не замена всего файла. Основное соединение и таблицу учёта миграций
оставьте в MySQL/PostgreSQL/SQLite согласно устройству приложения. Для подключения драйвера
не нужно менять `DB_CONNECTION` на `manticore`.

| Старая конфигурация | Переход |
| --- | --- |
| `manticore.connections.default` | `database.connections.manticore` с `driver => 'manticore'` |
| `manticore.connections.analytics` | Отдельное имя, например `database.connections.manticore_analytics` |
| `defaultConnection`, `MANTICORE_CONNECTION` | Явный `DB::connection(...)`; драйвер не читает эти настройки |
| `host`, `port`, `username`, `password`, `timeout` | Перенесите в каждое новое соединение; имена переменных окружения можно сохранить |
| `prefix`, `force_prefix`, `?table` | Laravel всегда добавляет префикс к именам таблиц билдера; удалите `force_prefix` и маркер `?` |
| `lazy_insert_ids` | Отложенного объекта результата нет; явно выбирайте `insert()` или `insertGetId()` |
| `logChannel`, `MANTICORE_LOG_CHANNEL` | Настройте обработчик событий Laravel, показанный ниже |

При префиксе `app_` замените `table('?products')` на `table('products')`: оба обращения должны
выбирать `app_products`. Старый вызов без маркера `table('products')` тоже может начать выбирать
`app_products`: проверьте все имена. Для таблиц без префикса используйте отдельное соединение
с пустым `prefix`. В сыром SQL префиксы автоматически не подставляются.
SQL-база при подключении не выбирается, настройки сессии MySQL не отправляются.
Эмуляция подготовленных запросов PDO должна оставаться включённой; явное отключение отклоняется.

Laravel автоматически обнаруживает `avadim\Manticore\Laravel\ServiceProvider`, который теперь
регистрирует только нативный драйвер. Удалите исключение пакета из `dont-discover`, если добавляли
его при раннем тестировании 4.x. При намеренно отключённом обнаружении зарегистрируйте провайдер
вручную в `bootstrap/providers.php`. `DatabaseServiceProvider` сохранён как равнозначная точка
регистрации; достаточно одного провайдера. Удалите ручной псевдоним `ManticoreDb`, регистрации
старых manager/factory и, после переноса настроек, `config/manticore.php`.
Пакет больше не публикует этот файл. Драйвер проверен на Laravel 11–13;
это руководство не подтверждает совместимость с Lumen или Eloquent.

## 3. Запросы, сырые выражения и сигнатуры

```php
// До перехода (3.x)
$rows = \ManticoreDb::table('?products')->select('id, title')->match($text)->get();

// После перехода
use Illuminate\Support\Facades\DB;

$db = DB::connection('manticore');
$rows = $db->table('products')->select(['id', 'title'])->match($text)->get();
```

В обоих билдерах `select()` задаёт колонки без выполнения запроса.
Вызов соединения `$db->select($sql, $bindings)` сразу выполняет сырой SQL.

| Вызов 3.x | Новый вызов / действие |
| --- | --- |
| `index('products')` | `table('products')` |
| `selectColumns('id, title')`, `select('id, title')` | `select(['id', 'title'])` или `select('id', 'title')`; строка с запятой в Laravel означает один идентификатор |
| `get('id', 'title')` | `get(['id', 'title'])` |
| `sql($sql)->bind($params)->get()` | `$db->select($sql, $params)` с исходными значениями PDO |
| Старые `raw()`, `param()`, `bind()` | `DB::raw()` для доверенного SQL; параметры передаются в `whereRaw()`, `selectRaw()` или методы соединения |
| `findOrFail($id)` | `where('id', $id)->firstOrFail()`; в Laravel Query Builder нет `findOrFail()` |
| `whereKnn($column, $k, $vector)` | `knn($column, $vector, $k)`; также задаёт LIMIT k |
| `highlight($options, $fields, $query)` | `withHighlight($fields, $options, $query)` |
| `facet($column, $callback)` | `facet($column, $alias, $limit, $sort, $direction)->search()` |
| `ranker("expr('...')")` | `ranker('expr', $formula)`; формула без SQL-кавычек |
| `maxMatches($n)` / `options($map)` | `option('max_matches', $n)` / отдельные вызовы `option()`; `fieldWeights()` принимает карту весов |

```php
$query = $db->table('products')->whereRaw('price > ?', [12.5])
    ->select('id')->selectRaw('price * ? as adjusted', [2]);
$sql = $query->toSql();
$bindings = $query->getBindings();
$rendered = $query->toRawSql();
```

`DB::raw()` создаёт выражение Laravel другого класса. MATCH передаётся как поисковое выражение;
буквальный пользовательский текст экранируйте через `ManticoreQueryBuilder::escapeMatch()`
из `avadim\Manticore\Laravel\Database`. Собирайте одно выражение MATCH: повторные новые
`match()` добавляют условия, а в 3.x заменяют предыдущее выражение.
Наличие стандартного метода Laravel не гарантирует поддержку его SQL сервером Manticore:
joins, unions, DISTINCT, блокировки и некоторые операции с датами/JSON требуют отдельной проверки.

## 4. Строки, коллекции и метаданные

| Операция | Обёртка Laravel 3.x | Нативный драйвер |
| --- | --- | --- |
| `get()` | `Collection<Row>` | `Collection<stdClass>` |
| `first()`, `find()` | `Row|null` | `stdClass|null` |
| `pluck()` | `Collection` | `Collection` |
| `$db->select(...)` | Массив строк-массивов старого соединения | `array<stdClass>` без преобразований билдера |
| `lazy()`, `cursor()` | `Generator<Row>` | `LazyCollection<stdClass>` |
| Метаданные после `get()` | Последний `ResultSet` / методы сессии | Явный снимок через `search()` |

Замените `$row['title']` на `$row->title`, `$row->toArray()` на `(array)$row`,
а `$row->toJson()` на `json_encode($row, JSON_THROW_ON_ERROR)`. Для необязательных полей
используйте `$row->missing ?? null`: у `stdClass` нет старого поведения отсутствующих свойств Row.
`$rows->toArray()` сохраняет элементы `stdClass` объектами. Для массива строк-массивов используйте
`$rows->map(static fn ($row) => (array)$row)->all()` либо явно создавайте DTO приложения.
Строки результата не являются моделями Eloquent.

```php
$result = $db->table('products')->match($text)->limit(20)->search(['id', 'title']);
$rows = $result->rows();
$total = $result->meta()->totalFound();
$metadata = $result->meta()->toArray();
```

У `SearchResult` нет старого контракта `result()`, `error()`, `hasMeta()`, `metaObject()`.
Его `Meta` — уже загруженный неизменяемый снимок, полученный до обработчиков событий запросов.
`get()` не запрашивает SHOW META; `getMeta()` читает текущее состояние сессии, которое может
измениться после любого промежуточного SQL. Нативных `lastResultSet()` и отложенных метаданных нет.
Фасеты доступны через `$result->facet($name)` / `$result->facets()`.

Билдер преобразует JSON, MVA/MVA64, векторы, bool и числа по кэшированному DESCRIBE.
На запись timestamp принимает `DateTimeInterface`, на чтение возвращает целое Unix-время.
Числа вне диапазона PHP остаются строками. `cast_attributes => false` отключает преобразования,
но не проверки схемы для пагинации. Сырые чтения соединения сохраняют значения PDO.
Проверьте сериализацию, DTO и вложенные атрибуты приложения.

## 5. Запись, ID и ошибки

```php
// До перехода: второй аргумент — ID документа.
$id = \ManticoreDb::table('products')->insertGetId(['title' => 'Galaxy'], 42);

// После перехода: ID входит в строку, второй аргумент предназначен для sequence Laravel.
$id = $db->table('products')->insertGetId(['id' => 42, 'title' => 'Galaxy']);
$db->table('products')->where('id', 42)->update(['price' => 25.5]);
$db->table('products')->where('id', 42)->delete();
```

Второй аргумент ID старых `insert()`, `replace()`, `update()` и `replaceGetId()` перенесите
в строку либо явное WHERE. Новые `insert()` / `replace()` возвращают bool,
`update()` / `delete()` — число затронутых строк, методы получения ID — `int|string`.
Ошибки SQL выбрасывают `QueryException`. Нулевое число затронутых строк может означать успех
и не заменяет обработку исключений.

```php
use Illuminate\Database\QueryException;

try {
    $db->table('products')->insert($document);
} catch (QueryException $error) {
    report($error);
    throw $error;
}
```

Для нативного SQL уберите проверки `lastResultSet()->error()` и перехват старого
`QueryErrorException`. Недопустимые операции билдера могут выбрасывать `InvalidArgumentException`
или `LogicException` до SQL. ID читается до обработчиков событий. Ошибка получения ID возможна
после успешного INSERT; драйвер не повторяет запись ради получения ID.
Аналогов `insertResultSet()` / `replaceResultSet()` с ID пакета строк нет: задавайте ID заранее
либо вставляйте строки по одной через `insertGetId()`. Не вычисляйте диапазон ID из одного результата.

REPLACE полностью заменяет документ по ID, сбрасывая пропущенные атрибуты в значения по умолчанию.
Используйте его для изменений полнотекстовых полей. Произвольный атомарный `upsert()` не поддержан;
Laravel `updateOrInsert()` выполняет несколько запросов и не является атомарным REPLACE/upsert.

## 6. Ленивое чтение, пагинация и транзакции

Для сохранения постраничного чтения замените `cursor(500)` на `lazy(500)`.
Новый `cursor()` не принимает размер порции и выполняет один SELECT; он ограничен окном
результатов сервера. Оба метода возвращают LazyCollection: замените требования Generator
и `iterator_to_array()` методами коллекции `all()` / `take()`.
Для большой изменяемой таблицы предпочтительны `lazyById()` / `chunkById()`; сохраняйте ID
и группируйте OR-фильтры. Индекс callback у Laravel `each()` может начинаться заново в каждой
порции; если нужен сквозной индекс, используйте `eachById()` либо собственный счётчик.
Проверьте типы `Row` и Generator в обработчиках.

`cursorPaginate()` поддерживает выбранные integer/bool/timestamp ключи с ID последним ключом
сортировки. Float отклоняется из-за округления границ в SQL. Групповой `paginate()` поддерживает
скалярные ключи и один HAVING; точный подсчёт групп может требовать много памяти сервера.
Фасеты, KNN и cutoff несовместимы с помощником точного подсчёта total.
Ограничения пагинации и время жизни buffered/unbuffered курсоров приведены в справочнике.

Транзакция действует только на выбранном нативном соединении. Передавайте Closure в `transaction()`;
число попыток кроме одной, вложенные транзакции и DDL внутри транзакции отклоняются.
`beginTransaction()`, `commit()`, `rollBack()` используют исключения и возврат void:
удалите старые проверки bool. Неопределённые ошибки BEGIN/COMMIT/ROLLBACK закрывают соединение;
SQL автоматически не повторяется. При ошибке callback и отката `TransactionRollbackException`
содержит `callbackError` и `rollbackError`. Записи в основной базе и Manticore не образуют общую транзакцию.

## 7. SchemaTable и миграции

```php
use avadim\Manticore\Laravel\Database\ManticoreBlueprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    protected $connection = 'manticore';

    public function up(): void
    {
        Schema::connection('manticore')->create('products', function (ManticoreBlueprint $table) {
            $table->id();
            $table->text('title');
            $table->float('price');
            $table->json('details');
            $table->multi('tags');
        });
    }

    public function down(): void
    {
        Schema::connection('manticore')->dropIfExists('products');
    }
};
```

Замените старые `create()` / `drop($table, true)` / `hasTable()` на
`create()` / `dropIfExists()` / `hasTable()` именованного Schema Builder.
Вместо `addColumn()` / `dropColumn()` используйте
`Schema::connection('manticore')->table('products', $callback)` и методы Blueprint.
Типы Manticore задаются через `multi()`, `multi64()`, `floatVector()`, настройки CREATE — `setting()`.
Старые массивы схемы и модификаторы автоматически не переводятся.
Типизированных аналогов `modifyColumn()`, `rename()`, `alterSettings()` пока нет;
поддерживаемый сервером проверенный SQL можно выполнить через `$db->statement()`.
DDL выбрасывает исключения при ошибках и не возвращает старый ResultSet.

Таблицу учёта миграций оставьте в основной SQL-базе и запускайте обычный migrator через неё.
Свойство миграции и явное соединение Schema выбирают цель DDL.
Не запускайте все миграции с `--database=manticore`. Существующие таблицы пересоздавать не нужно:
сохраните записи выполненных миграций, проверьте исторические `down()` и создавайте новые миграции
для реальных изменений схемы. DDL не транзакционен; при частичном ALTER предыдущие команды
могут уже выполниться. Проверьте создание и откат на чистой тестовой базе.
Не помещайте CREATE/DROP Manticore внутрь тестовой транзакции.

## 8. Логирование и долгоживущие воркеры

Замените глобальные, соединения и запросов `setLogger()` обработчиком событий Laravel.
Для сохранения переменной окружения добавьте свою настройку рядом с `channels` в `config/logging.php`:

```php
'manticore_channel' => env('MANTICORE_LOG_CHANNEL'),
```

Зарегистрируйте обработчик один раз в `boot()` провайдера приложения:

```php
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

$channel = config('logging.manticore_channel');
if ($channel) {
    DB::listen(static function (QueryExecuted $event) use ($channel) {
        if ($event->connection->getConfig('driver') === 'manticore') {
            Log::channel($channel)->debug($event->sql, [
                'connection' => $event->connectionName,
                'bindings' => $event->bindings,
                'time_ms' => $event->time,
            ]);
        }
    });
}
```

События включают служебные DESCRIBE и SHOW META, которые старый logger пропускал.
При unbuffered курсоре обработчик не должен запрашивать то же занятое соединение.
См. [события запросов Laravel](https://laravel.com/docs/13.x/database#listening-for-query-events).

| Старая операция воркера | Новая операция |
| --- | --- |
| `app('manticore')->purge($name)` | `DB::purge($newName)`; затем заново получите соединение/билдер |
| `app('manticore')->reconnect($name)` | `DB::reconnect($newName)` |
| `forgetSchema()` | `$db->forgetSchema()` либо `$db->forgetSchema('products')` для одной логической таблицы |
| `forgetSchemas()` менеджера | Явно очистите каждое используемое нативное соединение |

Schema Builder, распознанный сырой DDL и переподключение очищают кэш схемы соединения,
включая неуспешный DDL. У другого процесса/соединения свой кэш: после внешних изменений
очистите его либо перезапустите воркер. Не сохраняйте билдеры между purge/переконфигурациями.
Старый статический пул удалён. При обновлении пакета перенесите все его вызовы:
у старых классов нет общей с драйвером сессии LAST_INSERT_ID / SHOW META или транзакции.

## 9. Поисковые помощники и оставшиеся ограничения

Старые методы запроса `callKeywords($text, $options)`, `callSuggest()`, `callQsuggest()`,
`callAutocomplete()` заменяются методами соединения `keywords($text, $table, $options)`,
`suggest()`, `qsuggest()`, `autocomplete()`. Вместо `callSnippets($data, $query, $options)`
используйте `$db->snippets($data, $table, $query, $options)`.
Помощники возвращают коллекции stdClass; `$db->call($procedure, $arguments, $options)` —
массив коллекций всех наборов строк. Именованные помощники добавляют префикс таблицы,
общий `call()` использует аргументы без изменения. AUTOCOMPLETE требует Buddy и infixes
в таблице, KNN — серверную библиотеку и индексированный вектор.

Для старых CALL без типизированной замены общий `call()` принимает скаляры и плоские списки.
Сложные структуры требуют явно составленного SQL с параметрами. Старые методы дат/JSON,
плагины и настройки схемы проверяйте отдельно. Смена соединения не добавляет поддержку
joins, unions, DISTINCT, блокировок, произвольного upsert, всех ALTER или полноценного Eloquent.
Установленный Scout-адаптер может использовать старые классы: перенесите и проверьте его
до удаления старого API либо изменения ограничений версий.

## 10. Порядок переключения приложения

1. Добавьте нативные соединения рядом со старой конфигурацией и проверьте физические имена таблиц.
2. Переносите сервисы по одному, включая callback, типы, сериализацию, ошибки и обработку ID.
3. Проверьте пустые результаты, ошибки записи, явные/автоматические ID, составные значения,
   транзакции и воркеры. Проверьте метаданные после обработчиков событий, пагинацию за пределами
   1000 строк и используемые функции сервера.
4. Проверьте новые миграции и откат с таблицей учёта в основной SQL-базе.
5. Пересоберите кэш конфигурации, перезапустите queue/Octane воркеры и получите соединения заново.
6. Удалите старые регистрации, псевдонимы и конфигурацию приложения. Убедитесь, что адаптеры
   не загружают удалённые классы. В самом пакете старой реализации и зависимости уже нет.

Примеры проверок есть в тестах: они загружают основной `ServiceProvider` и по умолчанию
используют `tests/native-bootstrap.php`, отклоняющий загрузку класса PHP-билдера.
Самостоятельного билдера нет ни в production, ни в dev-зависимостях пакета.
