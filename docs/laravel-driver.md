# Native Laravel Database driver

The `dev-4.x` branch includes an experimental driver built on Laravel's own Query Builder.
It uses PDO directly; its implementation does not call the standalone PHP query builder.
The package still installs that dependency while the existing 3.x API is retained during development.

## Configuration

The package service provider registers the `manticore` driver automatically. Add a named
connection to the `connections` array in your application's `config/database.php`:

```php
'manticore' => [
    'driver' => 'manticore',
    'host' => env('MANTICORE_HOST', '127.0.0.1'),
    'port' => env('MANTICORE_PORT', 9306),
    'username' => env('MANTICORE_USER'),
    'password' => env('MANTICORE_PASS'),
    'timeout' => env('MANTICORE_TIMEOUT', 5),
    'prefix' => '',
    'options' => [], // PDO options
],
```

The new driver reads `database.connections`, independently of the legacy `config/manticore.php`.
Prefixes follow Laravel's rules: `prefix => 'app_'` prefixes `table('products')` automatically.
The legacy `?products` placeholder is not used. A database name is not needed.

The standalone `avadim\Manticore\Laravel\DatabaseServiceProvider` registers only the new driver,
without initializing the legacy static builder. The existing package provider also registers it.

## Queries and results

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

`select()` on a builder only selects columns and returns that builder. Raw methods accept
Laravel bindings, for example `whereRaw('price > ?', [100])` and
`selectRaw('price * ? as adjusted', [2])`. `toSql()`, `getBindings()` and `toRawSql()` are available.
`match()` binds a Manticore full-text expression; it does not escape search operators as literal text.
For literal visitor text, pass it through `ManticoreQueryBuilder::escapeMatch()` first.

The connector requires PDO emulated prepares. Native prepared UPDATE statements on the tested
Manticore 28.6.6 server round fractional values, so disabling emulation is rejected. Finite PHP
float parameters are rendered as checked numeric literals during execution; other parameters
remain PDO-bound. Laravel's SQL, bindings, events and exceptions retain the original parameters.
This applies to both positional and named parameters. Non-finite values are rejected.

`option($name, $value)` currently accepts finite numeric values. Setting an option again replaces
its value; `option($name, null)` removes it. Expression and map options are not implemented yet.

`insert()`, `insertGetId()`, attribute `update()` and `delete()` use Laravel execution and error handling.
SQL errors throw `Illuminate\Database\QueryException`, including rejected writes.
Connection events, `DB::listen()`, query logging and `pretend()` use Laravel's normal pipeline.
`DB::disconnect()`, `DB::reconnect()` and `DB::purge()` manage the new connection pool.
SQL statements are not automatically replayed after a connection error: a lost response does not
establish that the server failed to execute a write.

`lazy()`, `lazyById()` and `cursor()` return `LazyCollection`. `cursor()` performs one SELECT,
while `lazy()` fetches pages. PDO buffering is controlled by the connection's PDO options;
a lazy collection alone does not guarantee an unbuffered server result.

## Search metadata

```php
$query = $connection->table('products')->match('galaxy')->withScore()->limit(20);
$result = $query->search(['id', 'title']);
$rows = $result->rows(); // Collection<stdClass>, including the _score column
$meta = $result->meta();

$meta->totalFound();  // int|null
$meta->total();       // int|null
$meta->time();        // float|null
$meta['total_found'];
$meta->toArray();
```

`search()` captures SELECT rows and SHOW META on the same PDO connection before query-event
listeners run. The metadata snapshot remains valid after subsequent commands and after-query
callbacks. `Meta` is immutable, iterable, countable and JSON serializable; missing fields return
`null`. Reading the result or its metadata issues no further SQL.

Both SELECT and SHOW META appear in query events/logs after capture. The SELECT duration includes
metadata capture; SHOW META also has a separate timing entry. No metadata query runs in `pretend()`.

Ordinary `get()` returns a standard collection without SHOW META; its first use may load DESCRIBE.
For an explicit
follow-up, `$query->getMeta()` or `$connection->getMeta()` executes SHOW META for the current
session. Another command, including one from a query listener, can replace that session metadata;
use `search()` when rows and metadata must be captured together.

## MVA filters and geographic search

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
    ->get(); // each row includes _distance
```

MVA helpers accept scalar comparisons, IN / NOT IN sets and BETWEEN / NOT BETWEEN ranges.
Two arguments mean equality for a scalar or IN for an array. `orWhereMvaAny()` and
`orWhereMvaAll()` work in nested Laravel conditions too. Values must be integers or decimal
integer strings within PHP's integer range; strings are converted with a precision check.
Empty sets, invalid operators and ranges without exactly two bounds are rejected.
ALL tests every stored element against the condition; it does not mean "contains every supplied value".

Geographic coordinates are degrees, latitude first; units are `m`, `km` or `mi`. Numeric attributes
and JSON paths such as `location.lat` are accepted. Coordinates and radii must be finite and valid.
`withDistance()` keeps its selected expression even when `select()` is called afterwards, and accepts
an optional final alias argument. Conflicting or reserved aliases are rejected.

Filter/order helpers work without `withDistance()` as well: their internal computed columns are
removed from returned rows, including search results and cursors. Nested geo filters, aggregates
and pagination include the required expressions. Existing ordering is preserved by `orderByDistance()`.

## Deep pages

`paginate()` and `simplePaginate()` use Laravel's paginators. A SELECT window beyond the standard
1000 matches automatically gets `max_matches = offset + limit`, unless the query explicitly sets
`max_matches`. This also applies to `lazy()` and other offset-based walks. The query's configured
options are not mutated by compilation. Explicitly setting a smaller window can still produce
a server error; use `chunkById()` or `lazyById()` for large ID-based walks without growing offsets.
Server result limits still apply to a SELECT without an explicit `limit()`.

## Writes and transactions

`insert()` accepts one document or a batch. `insertGetId()` and `replaceGetId()` capture the
server ID before query listeners can issue another write. `replace()` returns a boolean and
replaces whole documents by ID: omitted fields reset to server defaults, including empty MVA,
JSON null and numeric zero. Use REPLACE to change full-text fields. Non-empty `upsert()` calls
throw `LogicException`: arbitrary unique keys and partial atomic upserts are not implemented.

```php
$connection->table('products')->replace([
    'id' => 42, 'title' => 'Galaxy', 'price' => 199.5,
]);
$connection->transaction(function () use ($connection) {
    $connection->table('products')->where('id', 42)->update(['price' => 189.5]);
});
```

Transactions support one level and Laravel's after-commit callbacks. Nested transactions,
DDL inside transactions and retry counts other than one are rejected. Failed BEGIN, COMMIT
or ROLLBACK disconnect the uncertain session; SQL and transaction callbacks are never replayed.
If both the callback and rollback fail, `TransactionRollbackException` exposes `callbackError`
and `rollbackError`. A failure retrieving an ID can follow a successful write; it never causes
another INSERT. UPDATE remains subject to Manticore's supported attribute operations.

## Schema and migrations

```php
use avadim\Manticore\Laravel\Database\ManticoreBlueprint;
use Illuminate\Support\Facades\Schema;

Schema::connection('manticore')->create('products', function (ManticoreBlueprint $table) {
    $table->id(); // Manticore's implicit 64-bit document ID
    $table->text('title'); // full-text field
    $table->string('sku'); // string attribute
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

Other supported column types are `integer`, `bigInteger`, `boolean` and `timestamp`.
Laravel string lengths and float precision declarations do not impose SQL constraints;
`float()` maps to Manticore's native float. TEXT supports `stored()` and `indexed()` flags.
CREATE settings are escaped; ALTER currently supports adding and dropping columns, in declaration
order. Changing types, renaming, nullable/default constraints, foreign keys, ordinary Laravel
indexes and unsupported modifiers fail before any statement from that blueprint executes.

Use ordinary migrations with `protected $connection = 'manticore'` and explicit
`Schema::connection('manticore')`. Keep Laravel's migration repository on the application's
primary SQL connection. DDL is not transactional: server failure halfway through a multi-statement
ALTER leaves earlier statements applied, so `down()` and recovery must account for that state.

## Attribute conversion and schema cache

The Query Builder loads DESCRIBE once per table and connection before its first read or write.
JSON arrays/objects are serialized on writes and decoded to associative arrays on reads;
JSON null remains null. MVA/MVA64 and float vectors accept list arrays and return arrays.
Integer values retain precision, using strings outside PHP's integer range. Boolean attributes
return booleans; numeric attributes return numbers. `DateTimeInterface` timestamp inputs become
epoch seconds. Expressions pass through without serialization.

Conversion applies to `get()`, `first()`, `pluck()`, `search()`, cursors and lazy collections,
including simple selected column aliases. Types of computed expressions are not inferred.
Raw `$connection->select()` retains PDO values. Set connection option `cast_attributes => false`
to disable automatic conversion and schema discovery; then serialize structured writes yourself.

Schema Builder and raw CREATE/ALTER/DROP/TRUNCATE/RENAME invalidate the cache, including on failure.
Reconnect also clears it. After external DDL use `$connection->forgetSchema('products')` with the
logical, unprefixed table name, or `$connection->forgetSchema()` for all tables. Schema discovery
runs before the search SELECT so it does not overwrite its metadata snapshot.

## Current boundaries

The initial implementation has been exercised on Manticore 28.6.6. The minimum supported server
version and compatibility with `latest` will be fixed before a release.

- Joins, unions, SELECT DISTINCT, locks and group limits are currently rejected by the driver.
  WHERE EXISTS subqueries and aggregates with HAVING are also rejected.
- UPDATE and DELETE reject ordering, limits, offsets, joins, search options and computed search expressions, rather than
  silently discarding them. Updating full-text fields requires separate Manticore handling.
- Native atomic `upsert()`, KNN, facets, highlighting and CALL helpers remain under development.
- Read/write splitting and Eloquent compatibility have not been established.

Existing `ManticoreDb`, `Manager` and `Row` keep the legacy behavior described in the main README.
They do not share a PDO connection with the new driver. Do not mix both APIs inside one transaction
or rely on one API to read the other's connection metadata.
