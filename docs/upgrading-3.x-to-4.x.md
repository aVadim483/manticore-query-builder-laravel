[English](upgrading-3.x-to-4.x.md) | [Русский](ru/upgrading-3.x-to-4.x.md)

# Upgrading from 3.x to the native Laravel driver

This guide targets the stable native driver **4.0.0**. The 3.x API and its Composer dependency on
`avadim/manticore-query-builder-php` have been removed. The native driver
uses Laravel Database and PDO directly. This guide describes explicit application changes,
not compatibility aliases that redirect old calls automatically.

The connection change alone does not rename, recreate or reindex existing Manticore tables.
Keep their physical names and verify the prefix before moving application traffic. For the full
supported API and server requirements, see the [native driver reference](laravel-driver.md).
The guide has been exercised on a [live Laravel 12 application](migration-app-verification.md);
that report records the application changes and checks without modifying the installed package.

## 1. Inventory and entry points

Find uses in application code, providers, jobs, tests and migrations:

```sh
rg -n 'ManticoreDb|avadim\\Manticore\\(Laravel|QueryBuilder)|SchemaTable|lastResultSet|insertResultSet|replaceResultSet|lazy_insert_ids|force_prefix' app config database tests
rg -n "app\(['\"]manticore|MANTICORE_|whereKnn|callAutocomplete|->highlight\(|->cursor\(" app config database tests
```

| 3.x component | Native replacement |
| --- | --- |
| `\ManticoreDb`, package `Facade` | `Illuminate\Support\Facades\DB::connection('manticore')` |
| `app('manticore')`, package `Manager` | Laravel `DatabaseManager`, with an explicit connection name |
| Package `Connection`, `Query` type hints | `Database\ManticoreConnection`, `Database\ManticoreQueryBuilder` under `avadim\Manticore\Laravel` |
| `Row` | `stdClass`, or an application DTO you construct explicitly |
| Upstream `SchemaTable` | `Database\ManticoreBlueprint` through Laravel `Schema` |
| Upstream `ResultSet` | Normal Laravel return values; `Database\SearchResult` for rows, metadata and facets |
| Upstream `QueryErrorException` | `Illuminate\Database\QueryException` for SQL failures |

Do not alias the old classes to the new ones: their constructors, method signatures, results and
failure handling differ. A service formerly injecting `Manager` can inject
`Illuminate\Database\DatabaseManager` and call `$database->connection('manticore')`.
Injecting Laravel's default connection alone would normally select the application's primary SQL database.

## 2. Move the connection configuration

Prepare the application changes in a deployment copy, then install the stable driver:

```sh
composer require "avadim/manticore-query-builder-laravel:^4.0"
```

Put the new connection in the existing `connections` array of `config/database.php`:

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
    'options' => [], // PDO options
],
```

This is an array entry, not a replacement for the whole database configuration. Keep the primary
connection and migration repository in MySQL/PostgreSQL/SQLite as appropriate for the application.
Do not change `DB_CONNECTION` to `manticore` just to enable this driver.

| Old configuration | Migration |
| --- | --- |
| `manticore.connections.default` | `database.connections.manticore`, including `driver => 'manticore'` |
| `manticore.connections.analytics` | A separate named connection, e.g. `database.connections.manticore_analytics` |
| `defaultConnection`, `MANTICORE_CONNECTION` | Use explicit `DB::connection(...)`; these keys are not read by the native driver |
| `host`, `port`, `username`, `password`, `timeout` | Move to each new connection; existing environment variable names can stay |
| `prefix`, `force_prefix`, `?table` | Laravel always prefixes builder table names; remove `force_prefix` and the `?` marker |
| `lazy_insert_ids` | No equivalent deferred result object; choose `insert()` or `insertGetId()` explicitly |
| `logChannel`, `MANTICORE_LOG_CHANNEL` | Not wired into the native driver; configure a Laravel query listener below |

With prefix `app_`, change `table('?products')` to `table('products')`, which addresses
`app_products`. A former unmarked `table('products')` may also start addressing `app_products`:
check every table name, not only those containing `?`. For an intentionally unprefixed table,
use a separate native connection with an empty prefix. Raw SQL is not rewritten with table prefixes.
There is no SQL database to select, and MySQL session settings are not sent on connect.
PDO emulated prepares must stay enabled; the connector rejects explicitly disabling them.

Laravel auto-discovers `avadim\Manticore\Laravel\ServiceProvider`, which registers only the native
driver. Remove any package `dont-discover` entry added during early 4.x development, or register
this provider manually in `bootstrap/providers.php` if discovery is intentionally disabled.
`DatabaseServiceProvider` remains an equivalent entry point; one provider is sufficient.
Remove the application's manual `ManticoreDb` alias, old manager/factory bindings and
`config/manticore.php` after moving its settings. The package no longer publishes that file.
The new driver has been tested on Laravel 11–13; this guide does not establish Lumen or Eloquent compatibility.

## 3. Queries, raw expressions and signatures

```php
// Before (3.x)
$rows = \ManticoreDb::table('?products')->select('id, title')->match($text)->get();

// After (native driver)
use Illuminate\Support\Facades\DB;

$db = DB::connection('manticore');
$rows = $db->table('products')->select(['id', 'title'])->match($text)->get();
```

Both builders' `select()` configures columns; it does not execute the query. The native
`$db->select($sql, $bindings)` is a different operation that immediately executes raw SQL.

| 3.x call | Native call / action |
| --- | --- |
| `index('products')` | `table('products')` |
| `selectColumns('id, title')`, `select('id, title')` | `select(['id', 'title'])` or `select('id', 'title')`; a comma string is one identifier in Laravel |
| `get('id', 'title')` | `get(['id', 'title'])` |
| `sql($sql)->bind($params)->get()` | `$db->select($sql, $params)`; returns raw PDO values |
| Upstream `raw()` / `param()` / query `bind()` | `DB::raw()` for trusted SQL; pass bindings directly to `whereRaw()` / `selectRaw()` or connection methods |
| `findOrFail($id)` | `where('id', $id)->firstOrFail()`; Laravel Query Builder has no `findOrFail()` |
| Catch `RecordsNotFoundException` after `firstOrFail()` / `findOrFail()` | Catch `Illuminate\Database\RecordNotFoundException` (singular) after native `firstOrFail()`; review exception mappings |
| `where('details.color', 'red')` for a JSON path | `whereRaw('details.color = ?', ['red'])`; Laravel can treat dotted names as qualified table/column identifiers and add the table prefix |
| `whereKnn($column, $k, $vector)` | `knn($column, $vector, $k)`; it also sets LIMIT to k |
| `highlight($options, $fields, $query)` | `withHighlight($fields, $options, $query)` |
| `facet($column, $callback)` | `facet($column, $alias, $limit, $sort, $direction)->search()` |
| `ranker("expr('...')")` | `ranker('expr', $formula)`; pass the formula without SQL quotes |
| `maxMatches($n)` / `options($map)` | `option('max_matches', $n)` / explicit `option()` calls; `fieldWeights()` accepts a weight map |

```php
$query = $db->table('products')->whereRaw('price > ?', [12.5])
    ->select('id')->selectRaw('price * ? as adjusted', [2]);
$sql = $query->toSql();
$bindings = $query->getBindings();
$rendered = $query->toRawSql();
```

`DB::raw()` uses Laravel's expression class, not the upstream expression class. MATCH is bound
as a search expression; escape literal visitor text with
`ManticoreQueryBuilder::escapeMatch()` from `avadim\Manticore\Laravel\Database`.
Build a single MATCH expression per query: repeated native `match()` calls add conditions rather
than replacing the preceding expression as 3.x does. Standard Laravel method availability is not
a guarantee that its generated SQL is accepted by Manticore. For example, joins, unions, DISTINCT,
locks and some date/JSON helpers need separate review; consult the native reference.

## 4. Rows, collections and metadata

| Operation | 3.x Laravel wrapper | Native driver |
| --- | --- | --- |
| `get()` | `Collection<Row>` | `Collection<stdClass>` |
| `first()`, `find()` | `Row|null` | `stdClass|null` |
| `pluck()` | `Collection` | `Collection` |
| `$db->select(...)` | Array of row arrays from upstream connection | `array<stdClass>`, without builder casts |
| `lazy()`, `cursor()` | `Generator<Row>` | `LazyCollection<stdClass>` |
| Metadata after `get()` | Last upstream `ResultSet` / session methods | Use `search()` for an explicit snapshot |

Replace `$row['title']` with `$row->title`. Replace `$row->toArray()` with `(array)$row` and
`$row->toJson()` with `json_encode($row, JSON_THROW_ON_ERROR)`. Use `$row->missing ?? null` for
optional fields: `stdClass` does not implement the old Row's missing-property behavior.
`$rows->toArray()` now leaves `stdClass` items intact; for a list of row arrays use
`$rows->map(static fn ($row) => (array)$row)->all()`. Updating return types to an application DTO
is another explicit option; returned rows are not Eloquent models.

```php
$result = $db->table('products')->match($text)->limit(20)->search(['id', 'title']);
$rows = $result->rows();
$total = $result->meta()->totalFound();
$metadata = $result->meta()->toArray();
```

`SearchResult` is not an upstream `ResultSet`: it has no `result()`, `error()`, `hasMeta()` or
`metaObject()` contract. Its `Meta` is an immutable snapshot captured before query listeners,
and is already loaded. `get()` does not fetch SHOW META; `getMeta()` reads current session state
and can be affected by any intervening SQL. There is no native `lastResultSet()` or deferred metadata object.
Facet results live in `$result->facet($name)` / `$result->facets()`.

The old builder automatically added `_score` to MATCH reads and could add convenience aliases
such as `_count` beside `count(*)`. Native reads require explicit `withScore()` and do not invent
the old `_count` alias. Add application DTO fields if clients depend on that JSON shape. Column
order may change when computed fields are added. Numeric fields returned by native CALL helpers
(for example `qpos`, `docs`, `distance`) can remain PDO strings; cast them explicitly in a DTO when
the old application serialized integers.

Builder reads/writes cast JSON, MVA/MVA64, vectors, booleans and numbers using cached DESCRIBE.
Timestamp inputs accept `DateTimeInterface` and reads return epoch integers. Integers outside
PHP's range remain strings. `cast_attributes => false` disables those casts, not schema validation
needed for pagination. Explicit raw connection reads retain PDO values. Check DTO/JSON consumers
and nested attributes rather than assuming every old serialized shape is unchanged.

## 5. Writes, IDs and failures

```php
// Before: the second argument is a document ID.
$id = \ManticoreDb::table('products')->insertGetId(['title' => 'Galaxy'], 42);

// After: put the ID in the row; the optional second argument is Laravel's sequence name.
$id = $db->table('products')->insertGetId(['id' => 42, 'title' => 'Galaxy']);
$db->table('products')->where('id', 42)->update(['price' => 25.5]);
$db->table('products')->where('id', 42)->delete();
```

Move the second ID argument of old `insert()`, `replace()`, `update()` and `replaceGetId()` into
the row or an explicit WHERE condition as appropriate. Native `insert()` / `replace()` return
booleans, `update()` / `delete()` return affected counts, and ID methods return `int|string`.
SQL failures throw `QueryException` instead of returning `false` or a failure-shaped zero.
Zero affected rows can be a successful operation; it is not a substitute for exception handling.

```php
use Illuminate\Database\QueryException;

try {
    $db->table('products')->insert($document);
} catch (QueryException $error) {
    report($error);
    throw $error;
}
```

Do not inspect `lastResultSet()->error()` or catch upstream `QueryErrorException` for native SQL.
Invalid/unsupported builder operations can throw `InvalidArgumentException` / `LogicException`
before SQL. ID retrieval occurs before listeners can issue another write. An ID retrieval failure
can follow a successful INSERT; the driver never repeats a write to obtain its ID.
There is no native `insertResultSet()` / `replaceResultSet()` batch-ID API. Assign explicit IDs
to batch documents, or insert individual rows with `insertGetId()` when IDs must be returned;
do not infer a contiguous ID range from one returned ID.

REPLACE overwrites the entire document by ID and resets omitted attributes to server defaults.
Use it for full-text changes. `updateOrInsert()` searches and then UPDATEs/INSERTs;
`replaceOrInsert()` searches and fully REPLACEs/INSERTs. Emulated `upsert()` searches all
input keys, preserves non-updated fields, and writes INSERT/REPLACE in one transaction.
These lookups have no isolation or database-enforced uniqueness for arbitrary attributes.
See [write-helper semantics and transaction limits](laravel-driver.md#update-or-insert-replace-or-insert-and-upsert).

## 6. Lazy reads, pagination and transactions

Replace `cursor(500)` with `lazy(500)` to retain the old page-by-page behavior. New `cursor()`
takes no chunk size and performs one SELECT; it is bounded by the server result window, not an
unlimited table scan. Both methods return a LazyCollection, so remove wrappers that require a
Generator or `iterator_to_array()` and use collection methods such as `all()` / `take()` instead.
Add a deterministic `orderBy()` before Laravel `lazy()` / offset chunk walks: Laravel requires it.
For one SELECT over more than the default result limit, specify `limit()` explicitly as well as
the required result window; `max_matches` alone does not increase the number of returned rows.
For a large changing table prefer `lazyById()` / `chunkById()`, preserve IDs and group OR filters.
Laravel's `each()` callback index can restart per offset chunk; use `eachById()` or your own counter
if the old globally increasing index matters. Review callbacks type-hinting `Row` or Generator.

`cursorPaginate()` supports selected integer/bool/timestamp keys with a final ID tie-breaker;
float keys are rejected because SQL output can round boundaries. Native grouped `paginate()`
supports scalar group keys and a single HAVING, with an exact group window that can cost server
memory. Facets, KNN and cutoff cannot use the exact total-count helper. See the reference for
buffered/unbuffered cursor lifetimes and pagination limits.

Transactions run on the selected native connection only. Pass a Closure to `transaction()`;
automatic retry counts other than one, nested transactions and DDL inside a transaction are rejected.
Manual `beginTransaction()`, `commit()` and `rollBack()` use Laravel's exception/void semantics;
remove old boolean-success checks. Uncertain BEGIN/COMMIT/ROLLBACK failures disconnect the session,
and SQL is not replayed automatically. `TransactionRollbackException` exposes both `callbackError`
and `rollbackError` when rollback also fails. Primary-database and Manticore writes are not one transaction.

Manticore transactions cover **INSERT, REPLACE and DELETE**, not in-place **UPDATE**. UPDATE is
not rolled back even inside `transaction()`; the current driver does not reject it there.
Use a full-document REPLACE when the change must participate in rollback. This server limitation
also exists under 3.x; do not assume Laravel changes it. See the
[Manticore transaction reference](https://manual.manticoresearch.com/Data_creation_and_modification/Transactions).

## 7. SchemaTable and migrations

The parent also provides [hybrid search](hybrid-search.md) and [capability evidence / RT replacement](hybrid-and-index-replacement.md).
These are opt-in APIs; existing KNN/MATCH queries retain intersection semantics. Fusion consumers
must use HybridResult/window totals explicitly, and replacement requires application-managed maintenance.

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

Replace old `create()` / `drop($table, true)` / `hasTable()` with the named Schema builder's
`create()` / `dropIfExists()` / `hasTable()`. For `addColumn()` / `dropColumn()`, use
`Schema::connection('manticore')->table('products', $callback)` and Blueprint methods.
Use `multi()`, `multi64()` and `floatVector()` for native types; CREATE settings use `setting()`.
Old schema arrays and options are not automatically translated.
Use `bigInteger($column)->change()` for row-wise int-to-bigint,
`$schema->rename($from, $to)` with Buddy and `setting()` inside `table()` for supported ALTER settings.
Read reported settings with `$schema->getSettings($table)`; omitted defaults are not inferred.
See [extended Schema Builder](schema-alter.md). Arbitrary old modifiers and type changes
are not translated, and bigint widening has no automatic inverse in `down()`.
DDL throws on failure and has no old ResultSet return contract.

Keep the migration repository on the primary SQL database and invoke the normal migrator there;
the migration property and explicit Schema connection choose the DDL target. Do not run the whole
migration repository with `--database=manticore`. Existing tables need not be recreated: keep
applied migration records, review historical `down()` code and use new migrations for actual
schema changes. DDL is not transactional, and partial multi-statement ALTER can leave earlier
changes applied. Test both migration and rollback on a fresh test database. Do not place native
CREATE/DROP inside a test transaction.

## 8. Logging and long-running workers

Replace upstream global/connection/query `setLogger()` calls with Laravel query events.
For a channel selected by the existing environment variable, add an application-owned entry
to `config/logging.php` (alongside `channels`):

```php
'manticore_channel' => env('MANTICORE_LOG_CHANNEL'),
```

Register the listener once in an application provider's `boot()`, not on every job:

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

Unlike the old logger, native events include service queries such as DESCRIBE and SHOW META.
For unbuffered cursors, listeners must not query the same busy connection. See
[Laravel query events](https://laravel.com/docs/13.x/database#listening-for-query-events).

| Old worker operation | Native operation |
| --- | --- |
| `app('manticore')->purge($name)` | `DB::purge($newName)`; reacquire the connection/query afterwards |
| `app('manticore')->reconnect($name)` | `DB::reconnect($newName)` |
| `forgetSchema()` | `$db->forgetSchema()` or `$db->forgetSchema('products')` for one logical table |
| Manager `forgetSchemas()` | Clear each native connection you use explicitly |

Schema Builder, recognized raw DDL and reconnect clear that connection's schema cache, including
failed DDL. Another process or connection has its own cache: refresh it after external schema
changes or restart the worker. Do not retain query builders across purge/reconfiguration boundaries.
The old static pool has been removed. Replace all callers when updating the package;
old classes cannot share LAST_INSERT_ID / SHOW META or transaction state with this driver.

## 9. Search helpers and remaining gaps

Old query-level `callKeywords($text, $options)`, `callSuggest()`, `callQsuggest()` and
`callAutocomplete()` become connection-level `keywords($text, $table, $options)`, `suggest()`,
`qsuggest()` and `autocomplete()`. `callSnippets($data, $query, $options)` becomes
`$db->snippets($data, $table, $query, $options)`. Helpers return Collections of stdClass;
`$db->call($procedure, $arguments, $options)` returns all row-producing sets as an array of Collections.
Named helpers apply the table prefix; generic `call()` uses exact supplied arguments.
AUTOCOMPLETE still needs Buddy and table infixes; KNN needs its server library and an indexed vector.

For old CALL helpers without a typed replacement, generic `call()` supports scalar arguments and
flat lists only; more complex structures need explicitly constructed, bound SQL. Old date/JSON
convenience methods, plugins and schema settings must be checked individually. Joins, unions,
DISTINCT, locks, native isolated upsert, some ALTER operations and general Eloquent compatibility are
not completed by changing the connection name.

## 10. Application rollout

1. Add native connections alongside the old configuration and verify their physical table names.
2. Migrate one service at a time, including callbacks, type hints, serializers, errors and ID handling.
3. Test empty reads, failed writes, explicit/automatic IDs, structured values, transactions and workers.
   Validate metadata after listeners, pagination beyond 1000 results, and each server-dependent helper used.
4. Verify fresh migrations and rollback while retaining the primary SQL migration repository.
5. Rebuild the application's configuration cache, restart its queue/Octane workers, and reacquire native connections.
6. Remove application legacy bindings, aliases and old configuration. Verify that adapters no longer
   load removed classes. The package no longer includes the old implementation or dependency.

Use the existing test suite as examples: tests boot the main `ServiceProvider` and use
`tests/native-bootstrap.php` by default, which fails if an upstream builder class is loaded.
The standalone builder is absent from production and development package requirements.
