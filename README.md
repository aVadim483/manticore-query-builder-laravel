[English](README.md) | [Русский](README.ru.md)

[![GitHub Release](https://img.shields.io/github/v/release/aVadim483/manticore-query-builder-laravel)](https://packagist.org/packages/avadim/manticore-query-builder-laravel)
[![tests](https://github.com/aVadim483/manticore-query-builder-laravel/actions/workflows/tests.yml/badge.svg)](https://github.com/aVadim483/manticore-query-builder-laravel/actions/workflows/tests.yml)

# ManticoreSearch Database Driver for Laravel

A native Laravel Database driver for ManticoreSearch. Use Laravel's database manager, Query Builder,
collections, pagination, query events and Schema Builder over PDO's MySQL protocol.

Version `4.0.0-alpha.3` is an early-testing prerelease; it is not a stable release.
See the [alpha release notes](docs/releases/4.0.0-alpha.3.md) for scope and unverified integrations.
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
* ManticoreSearch 28.6.6+ with the MySQL protocol enabled, normally on port 9306.
* Buddy for AUTOCOMPLETE; the KNN library and a vector index for KNN queries.

The supported server floor is 28.6.6. CI tests its official image pinned by digest and the moving
`latest` image; see the [server compatibility policy](docs/testing.md#server-compatibility).
Support for older servers, Lumen and general Eloquent compatibility is not established.

## Installation

To install the published alpha:

```sh
composer require avadim/manticore-query-builder-laravel:4.0.0-alpha.3
```

To test subsequent development changes:

```sh
composer require avadim/manticore-query-builder-laravel:dev-dev-4.x
```

An explicit alpha constraint avoids changing the whole application's `minimum-stability`.
A live Laravel 12 demonstration application's migration has been [verified](docs/migration-app-verification.md).
Scout adapter migration and other applications remain unverified.

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

The [hybrid search guide](docs/hybrid-search.md) progresses from a minimal call to advanced
options. `hybridSearch()` returns `HybridResult`, `hybridGet()` returns `Collection<stdClass>`,
and `hybridFirst()` returns `stdClass|null`. These execute immediately; the alpha.2
`hybrid(...)->search()` call is removed. `knn()->match()` retains intersection semantics.
[Capability probes and RT replacement](docs/hybrid-and-index-replacement.md) are documented separately;
replacement requires maintenance and does not promise zero downtime. Scout is not required.

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
On `dev-4.x` after alpha.1, [extended ALTER](docs/schema-alter.md) adds settings changes/inspection,
int-to-bigint `change()` and table `rename()` with Buddy; these additions are not in alpha.1.
Keep the migration repository in the primary SQL database; select Manticore inside individual migrations.
DDL is not transactional. See the [migration example](docs/upgrading-3.x-to-4.x.md).

## Differences from Laravel Query Builder

The `select()`, `get()`, `first()` and `lazy()` contracts are preserved: a builder, collection,
object/null and lazy collection respectively. The differences below matter when porting queries
from Laravel's standard SQL drivers. Standard Query Builder capabilities also depend on the
selected database; see [Laravel's reference](https://laravel.com/docs/13.x/queries).

| Operation | Standard Laravel Query Builder | This Manticore driver |
| --- | --- | --- |
| Substring matching | `whereLike()` or `where('name', 'like', '%text%')` use SQL LIKE | LIKE in WHERE is unsupported. Use `match()` with full-text index rules for TEXT, or `REGEX()` through a SELECT alias for STRING. Their search semantics differ |
| Full-text search | `whereFullText()` on supported databases | Use `match()`. `whereFullText()` is not automatically translated into MATCH |
| JSON predicates | `details->color` syntax and `whereJson*()` helpers on supported databases | Laravel's JSON syntax is not automatically translated. For example: `whereRaw('details.color = ?', ['red'])` |
| Reading JSON | Query Builder returns PDO values without automatically decoding JSON into PHP arrays | By default, the builder reads DESCRIBE and converts JSON to arrays; `cast_attributes => false` disables conversion |
| `get()` / `cursor()` without `limit()` | No row-count limit is added to the SQL | Manticore defaults to 20 returned rows. Set `limit()` or use `lazy()` / `chunk()` for batched traversal |
| JOIN, UNION, DISTINCT, WHERE EXISTS, locks | Methods expose SQL constructs supported by the selected database | The current driver rejects these constructs |
| Updating text with `update()` | Updates the specified text columns | Full-text fields require `replace()`, which replaces the whole document and resets omitted fields |
| `upsert()` | Insert/update by unique keys under the SQL driver's rules | Arbitrary atomic upsert is unsupported. `replace()` uses document IDs and has different replacement rules |
| Writes inside a transaction | INSERT, UPDATE and DELETE can be rolled back on transactional SQL tables | Manticore transactions cover INSERT/REPLACE/DELETE; UPDATE is not rolled back. Driver nesting, savepoints and retries are rejected |
| `cursorPaginate()` | Builds cursor boundaries from selected ordering columns under Laravel's constraints | Only selected integer/bool/timestamp keys ending in ID are supported; KNN, groups, facets and float/string/JSON/MVA/computed keys are rejected |

LIKE alternatives follow [Manticore field types](https://manual.manticoresearch.com/Creating_a_table/Data_types)
and [REGEX](https://manual.manticoresearch.com/Functions/String_functions). See also
[result limits](https://manual.manticoresearch.com/Searching/Pagination),
[transactions](https://manual.manticoresearch.com/Data_creation_and_modification/Transactions)
and [driver restrictions](docs/laravel-driver.md).

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
See [verification instructions](docs/testing.md) for isolated production consumers and test matrices.

[Benchmarks](benchmarks/README.md) compare the native driver with PDO and the official HTTP client.
