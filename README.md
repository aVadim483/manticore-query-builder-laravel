[English](README.md) | [Русский](README.ru.md)

[![GitHub Release](https://img.shields.io/github/v/release/aVadim483/manticore-query-builder-laravel)](https://packagist.org/packages/avadim/manticore-query-builder-laravel)
[![tests](https://github.com/aVadim483/manticore-query-builder-laravel/actions/workflows/tests.yml/badge.svg)](https://github.com/aVadim483/manticore-query-builder-laravel/actions/workflows/tests.yml)

# ManticoreSearch Database Driver for Laravel

A native Laravel Database driver for ManticoreSearch. Use Laravel's database manager, Query Builder,
collections, pagination, query events and Schema Builder over PDO's MySQL protocol.

Version 4.x is under development on `dev-4.x`; it is not a stable 4.0 release.
The 3.x wrapper API and its dependency on `avadim/manticore-query-builder-php` have been removed.
Existing applications should follow the [3.x migration guide](docs/upgrading-3.x-to-4.x.md).

## Contents

* [Requirements](#requirements)
* [Installation](#installation)
* [Configuration](#configuration)
* [Queries and results](#queries-and-results)
* [Writes and transactions](#writes-and-transactions)
* [Search extensions](#search-extensions)
* [Schema and migrations](#schema-and-migrations)
* [Differences from Laravel Query Builder](#differences-from-laravel-query-builder)
* [Testing and benchmarks](#testing-and-benchmarks)

## Requirements

* PHP 8.2+ and Laravel 11–13, subject to each framework version's PHP requirements.
* PHP extensions `pdo`, `pdo_mysql` and `json`.
* ManticoreSearch with the MySQL protocol enabled, normally on port 9306.
* Buddy for AUTOCOMPLETE; the KNN library and a vector index for KNN queries.

The driver has been tested on Manticore 28.6.6 and 29.9.0, including Buddy 4.4.3 on 29.9.0.
A minimum supported server version will be fixed before release. Lumen and general Eloquent
compatibility are not established.

## Installation

To test the development branch:

```sh
composer require avadim/manticore-query-builder-laravel:dev-dev-4.x
```

Laravel discovers `avadim\Manticore\Laravel\ServiceProvider` automatically.
If package discovery is disabled, register that provider in `bootstrap/providers.php`.
`DatabaseServiceProvider` remains an alternative registration entry point for applications
that used the native driver earlier in 4.x development. Registering either provider is sufficient.
There is no `ManticoreDb` alias or package configuration to publish.

## Configuration

Add this entry to `connections` in `config/database.php`:

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

Keep the application's primary database as its default and use `DB::connection('manticore')`
explicitly. Additional connection names can use the same `manticore` driver with their own settings.
The driver reads no `config/manticore.php`, `MANTICORE_CONNECTION`, `force_prefix` or
`lazy_insert_ids`. Laravel always prefixes builder table names; use `products`, without `?`.
Raw SQL is not rewritten. PDO emulated prepares must stay enabled.

Use `DB::listen()` for logging and `DB::purge()` / `DB::reconnect()` for worker connections.
See the [migration guide](docs/upgrading-3.x-to-4.x.md) for logging and schema-cache lifecycle examples.

## Queries and results

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

Query Builder `select()` returns the builder and configures columns. Connection
`$db->select($sql, $bindings)` executes SQL and returns `array<stdClass>` without builder casts.
`get(['id', 'title'])` accepts an array; a comma-separated string is not a column list.
Rows use object properties. To get arrays, use
`$rows->map(static fn ($row) => (array)$row)->all()`.

`cursor()` returns a LazyCollection from one SELECT. `lazy()` reads offset pages; `lazyById()`
walks an ID boundary. `cursorPaginate()` supports selected integer/bool/timestamp keys with ID
as the final tie-breaker. Floats, computed keys, KNN and grouped/faceted cursor pagination are rejected.
See the [full driver reference](docs/laravel-driver.md) for exact totals and streaming limits.

## Writes and transactions

```php
$id = $db->table('products')->insertGetId(['title' => 'Galaxy', 'price' => 120.5]);
$db->table('products')->where('id', $id)->update(['price' => 99.5]);
$db->table('products')->replace(['id' => $id, 'title' => 'Galaxy phone', 'price' => 99.5]);
$db->table('products')->where('id', $id)->delete();

$db->transaction(function () use ($db) {
    $db->table('products')->insert(['id' => 42, 'title' => 'Phone', 'price' => 50]);
});
```

`insert()` / `replace()` return bool, `update()` / `delete()` return affected counts,
and `insertGetId()` / `replaceGetId()` return `int|string`. Put explicit IDs in the document.
SQL failures throw `Illuminate\Database\QueryException`; zero affected rows can be successful.
REPLACE replaces the entire document, resetting omitted attributes, and supports full-text changes.
Nested transactions, DDL inside transactions and retry counts other than one are rejected.
Writes are never automatically replayed after a lost response.

## Search extensions

```php
$result = $db->table('products')->match('galaxy')->select('id', 'title')
    ->withScore()->fieldWeights(['title' => 10])->ranker('bm25')
    ->withHighlight(['title'])
    ->facet('brand', 'brands', 10)->limit(20)->search();

$rows = $result->rows();
$total = $result->meta()->totalFound();
$brands = $result->facet('brands');
```

`search()` captures rows, metadata and facets before query listeners. `get()` fetches rows only.
Other extensions include `option()`, `knn()`, MVA ANY/ALL, geo distance, and connection-level
`keywords()`, `suggest()`, `qsuggest()`, `snippets()`, `autocomplete()` and generic `call()`.
See the [reference](docs/laravel-driver.md) for signatures and server requirements.

## Schema and migrations

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

CREATE, DROP and ALTER ADD/DROP use Laravel Schema Builder, including `multi64()`,
`floatVector()` and CREATE `setting()`. Unsupported schema operations fail explicitly.
Keep the migration repository in the primary SQL database; select Manticore inside individual migrations.
DDL is not transactional. See the [migration example](docs/upgrading-3.x-to-4.x.md).

## Differences from Laravel Query Builder

| Area | Behavior |
| --- | --- |
| Builder/results | Laravel Builder, Collection, stdClass and LazyCollection contracts |
| SQL dialect | Manticore SQL through custom Grammar; joins, unions, DISTINCT, locks and WHERE EXISTS are rejected |
| Search | MATCH, ranking, facets, highlighting, vectors and metadata extend Laravel's API |
| Types | Builder reads/writes cast JSON, MVA, vectors and numeric attributes using cached DESCRIBE; raw connection reads retain PDO values |
| Writes | Full-text changes require REPLACE; arbitrary atomic upsert is unsupported |
| Transactions | One connection, no nesting, savepoints, automatic retries or transactional DDL |
| Pagination | Server result windows and supported key types impose limits; consult the reference |
| Eloquent | Relationships, model persistence and general ORM compatibility are not promised |

Use bound parameters with `whereRaw()` / `selectRaw()`; `DB::raw()` is for trusted SQL.
Escape literal MATCH text with
`avadim\Manticore\Laravel\Database\ManticoreQueryBuilder::escapeMatch()`.
Build a single MATCH expression per query.

## Testing and benchmarks

```sh
composer install
composer test
```

Integration tests use `MANTICORE_TEST_HOST` / `MANTICORE_TEST_PORT` (127.0.0.1:9306).
Set `MANTICORE_TEST_BUDDY=1` to require AUTOCOMPLETE. Tests skip unavailable server features locally;
CI uses `--fail-on-skipped`. The default bootstrap rejects loading standalone PHP-builder classes.
Tests also require `pdo_sqlite` for the primary migration repository.

[Benchmarks](benchmarks/README.md) compare the native driver with PDO and the official HTTP client.
