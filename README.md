**English** | [Русский](README.ru.md)

[![GitHub Release](https://img.shields.io/github/v/release/aVadim483/manticore-query-builder-laravel)](https://packagist.org/packages/avadim/manticore-query-builder-laravel)
[![Packagist Downloads](https://img.shields.io/packagist/dt/avadim/manticore-query-builder-laravel?color=%23aa00aa)](https://packagist.org/packages/avadim/manticore-query-builder-laravel)
[![GitHub License](https://img.shields.io/github/license/aVadim483/manticore-query-builder-laravel)](https://packagist.org/packages/avadim/manticore-query-builder-laravel)
[![Static Badge](https://img.shields.io/badge/php-%3E%3D8.2-005fc7)](https://packagist.org/packages/avadim/manticore-query-builder-laravel)
[![Static Badge](https://img.shields.io/badge/laravel-11%20--%2013-ff2d20)](https://packagist.org/packages/avadim/manticore-query-builder-laravel)
[![tests](https://github.com/aVadim483/manticore-query-builder-laravel/actions/workflows/tests.yml/badge.svg)](https://github.com/aVadim483/manticore-query-builder-laravel/actions/workflows/tests.yml)

# ManticoreSearch Query Builder for Laravel

The easiest way to use the [ManticoreSearch Query Builder](https://github.com/aVadim483/manticore-query-builder-php)
in your Laravel or Lumen applications. This package allows you to build ManticoreSearch queries using a Laravel-like syntax.

```sh
composer require avadim/manticore-query-builder-laravel
```

The query builder is a separate package and is where the syntax of a query lives — `match()`,
`where()`, `insert()`, the schema of a table. This one adds what only means something inside the
framework: the service provider and the config file, named connections, and answers of the shape
Laravel expects.

## Contents

* [Related packages](#related-packages)
* [Requirements](#requirements)
* [Installation](#installation)
* [Configuration](#configuration)
* [Quick start](#quick-start)
* [Differences from Laravel Query Builder](#differences-from-laravel-query-builder)
* [The alias, the facade and the container](#the-alias-the-facade-and-the-container)
* [What the reads return](#what-the-reads-return)
* [Search metadata](#search-metadata)
* [Write results and deferred IDs](#write-results-and-deferred-ids)
* [When a query fails](#when-a-query-fails)
* [Pagination](#pagination)
* [Walking a large result](#walking-a-large-result)
* [Transactions](#transactions)
* [Migrations](#migrations)
* [Long-running workers](#long-running-workers)
* [Logging](#logging)
* [Documentation](#documentation)
* [Testing](#testing)

## Related packages

* [`avadim/manticore-query-builder-php`](https://github.com/aVadim483/manticore-query-builder-php) —
  the query builder itself, with no dependency on Laravel: the syntax of a query, the schema DSL
  and the pool of connections live there. That is the one to use outside a Laravel application,
  and the one a change to `match()`, `where()` or `insert()` belongs to.
* [`avadim/manticore-laravel-scout`](https://github.com/aVadim483/manticore-laravel-scout) —
  the ManticoreSearch driver for [Laravel Scout](https://laravel.com/docs/scout), built on this
  package: full-text search of Eloquent models as `Post::search('manticore')->get()`, with the
  index kept in step by the observer of Scout.

## Requirements

* PHP 8.2 or above with the PDO, PDO MySQL, mbstring and JSON extensions
* Laravel 11, 12 or 13, or Lumen 11
* [avadim/manticore-query-builder-php](https://github.com/aVadim483/manticore-query-builder-php) 2.6 or above within 2.x, installed along with this package
* Manticore Search reachable over its SQL interface, port 9306 by default. The HTTP/JSON API is not used.

Version 2.x runs on Laravel 6 to 13 and PHP 7.4, and stays where it is for an application that
has not moved yet: an older Laravel is not broken by this, it simply keeps installing 2.x.

A few things need more than the bare server: KNN vector search needs the KNN library, columnar
storage the columnar one, `rename()` and `callAutocomplete()` need Manticore Buddy. Autocomplete
also needs a table configured with infixes. What needs what is listed in the
[requirements](https://github.com/aVadim483/manticore-query-builder-php#requirements) of the query builder.

## Installation

### Laravel

The service provider of the package registers itself.

Publish the configuration file:

```sh
php artisan vendor:publish --provider="avadim\Manticore\Laravel\ServiceProvider"
```

### Lumen

Lumen discovers nothing on its own, so the service provider, the configuration and the alias are
registered by hand in `bootstrap/app.php`:

```php
// the short name of the facade - the \ManticoreDb that Laravel registers by itself
$app->withFacades(true, [
    'avadim\Manticore\Laravel\Facade' => 'ManticoreDb',
]);

// Register Config Files
$app->configure('manticore');

// Register Service Providers
$app->register(avadim\Manticore\Laravel\ServiceProvider::class);
```

Copy the configuration file into your application by hand as well.

## Configuration

After the configuration file is published, a connection is set up in the `.env` file of your
application (with appropriate values):

```dotenv
MANTICORE_HOST=localhost
MANTICORE_PORT=9306
MANTICORE_USER=
MANTICORE_PASS=
MANTICORE_TIMEOUT=5
```

### All available environment variables

| Name                   | Default value | Description                                                        |
|------------------------|---------------|--------------------------------------------------------------------|
| MANTICORE_CONNECTION   | default       | Name of default connection                                         |
| MANTICORE_HOST         | localhost     | Address of host with Manticore server                              |
| MANTICORE_PORT         | 9306          | Port of the SQL interface of the server                            |
| MANTICORE_USER         |               | Username                                                           |
| MANTICORE_PASS         |               | Password                                                           |
| MANTICORE_TIMEOUT      | 5             | Connection establishment timeout in seconds                         |
| MANTICORE_PREFIX       |               | Prefix that replaces the placeholder `?` in front of a table name  |
| MANTICORE_FORCE_PREFIX | false         | Prefix every table name, not only the ones written with `?`        |
| MANTICORE_LOG_CHANNEL  |               | Logging channel the queries are sent to; nothing is logged while it is empty |

A prefix keeps the tables of an application apart from the rest of a shared server:
`table('?products')` reads the table `myapp_products` when the prefix is `myapp_`. With
`MANTICORE_FORCE_PREFIX=true` the `?` can be left out — every name is prefixed.

More connections are added to the `connections` array of `config/manticore.php`, each with the
same set of keys; `defaultConnection` names the one used when no name is given.

## Quick start

```php
// Get list of tables via the default connection
$list = \ManticoreDb::showTables();

// Get list of tables via the specified connection
$list = \ManticoreDb::connection('test')->showTables();

\ManticoreDb::table('t')->insert($data);
\ManticoreDb::table('t')->match($match)->where($where)->get();
```

Everything between `table()` and the read — full-text `match()`, `where()`, aggregates, faceted
search, `JOIN`, KNN vector search, the schema of a table — belongs to the query builder and is
described in [its documentation](https://github.com/aVadim483/manticore-query-builder-php/blob/main/docs/README.md).
The sections below cover what this package does differently.

Version 2.6 also includes `whereMvaAny()` / `whereMvaAll()` for multi-value attributes,
`withDistance()` / `whereGeoDistance()` / `orderByDistance()` for geographic search, and
`callAutocomplete()` for suggestions. See the upstream [searching guide](https://github.com/aVadim483/manticore-query-builder-php/blob/v2.6.0/docs/searching.md)
for their arguments and server requirements. Rows and metadata can be read separately, and
write IDs can be loaded on demand, as described below.

## Differences from Laravel Query Builder

This package uses its own Manticore query builder and connection classes. It does not register
a driver for `DB::connection('manticore')` or implement the complete
[Laravel Query Builder API](https://laravel.com/docs/queries). Use `ManticoreDb` or the injected
`Manager`; check the upstream documentation before moving an existing `DB::table()` chain here.

| Area | Laravel Query Builder | This package |
|---|---|---|
| Result rows | `get()` returns a `Collection` of `stdClass` objects | A zero-based `Collection` of `Row` objects, with property and array access. A `Row` is a data container; changing it does not persist anything and it has no Eloquent relationships or `save()` method. |
| Raw reads | `DB::select()` returns an array of `stdClass` objects | `ManticoreDb::select()` returns the upstream array result, without wrapping rows in `Row`. CALL helpers such as `callAutocomplete()` also return arrays. |
| Search | Uses the database driver's full-text API | `match()` uses Manticore's query language; `whereKnn()`, MVA and geographic helpers, facets and search metadata follow Manticore semantics. `whereMvaAll()` tests every attribute element against the condition; it does not mean that every supplied value must be present. |
| Failed SQL | Database errors normally throw `Illuminate\Database\QueryException` | Rejected reads throw upstream `QueryErrorException`; rejected writes return `false` or `0`. Inspect `lastResultSet()->error()` to distinguish an error from a write that changed no rows. |
| Streaming | `lazy()` and `cursor()` return `LazyCollection`; `cursor()` uses a single query | Both methods return `Generator` and fetch pages with `LIMIT`/`OFFSET`. Use `chunkById()` for a full walk without growing offsets. |
| Pagination | Uses the SQL database's pagination behavior | `paginate()` / `simplePaginate()` return Laravel paginators and raise `max_matches` for the requested window unless explicitly set. Other offset-based walks still need a suitable `maxMatches()` value. |
| Upserts | Uses the database's native conflict handling with unique indexes | `upsert()` first looks up each row by `$uniqueBy`, merges the requested `$update` columns with an existing row and writes it with `REPLACE` by document ID, or inserts a new row. It costs a lookup per row and is not a single atomic conflict-handling statement. |
| Schema | Uses Laravel `Schema` and its `Blueprint` | Use `ManticoreDb::create()` and upstream `SchemaTable`, plus Manticore ALTER helpers. The SQL wire protocol does not make Manticore a MySQL database. |
| Transactions | Savepoints and retries depend on the database driver | Manticore has no savepoints: nested calls count levels, and rollback cancels the whole transaction. Returning `false` from a write does not throw and therefore does not trigger automatic rollback. Throw explicitly when a failed write must abort the transaction. |

Raw expressions and bindings also use the upstream API. For example, bind a named parameter
explicitly rather than treating a string such as `':price'` as a placeholder:

```php
$rows = \ManticoreDb::table('products')
    ->where('price', '>', \ManticoreDb::param('price'))
    ->bind(['price' => 100])
    ->get();
```

Use `ManticoreDb::raw()` for an upstream raw expression. Laravel's `DB::raw()` objects and
positional binding conventions are not interchangeable with this API. For plain visitor text,
use `match(ManticoreDb::escapeMatch($text))`; pass Manticore query syntax directly only when it
is intended to be interpreted as a search expression.

## The alias, the facade and the container

The `\ManticoreDb` alias of the examples is registered by the package itself. It is the shortest
way in, but not the only one — the same connection is reachable through the facade and through
the container:

```php
use avadim\Manticore\Laravel\Facade as ManticoreDb;
use avadim\Manticore\Laravel\Manager;

// the facade, when a global alias is not to your taste
ManticoreDb::table('products')->find($id);

// injected, when a class is to be testable without the framework around it
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

All three answer alike: they share the connections, and a query built through any of them returns
the collections described below. `Manager` answers the methods of a connection through `__call()`,
which the `@method` annotations of the class describe, so an IDE completes them.

## What the reads return

The standalone [query builder](https://github.com/aVadim483/manticore-query-builder-php) answers
with plain arrays — a collection there would mean an extra dependency in a framework-agnostic
library. Inside Laravel the framework is a given, so this package answers the way Laravel does:

```php
// Illuminate\Support\Collection, keyed from zero
$rows = \ManticoreDb::table('products')->match('galaxy')->get();

$titles = $rows->map(fn ($row) => $row->title)->all();

// A single row is a Row object
$row = \ManticoreDb::table('products')->where('price', '<', 1000)->first();
$row = \ManticoreDb::table('products')->find($id);

// pluck() answers with a Collection too, keyed by the second column when it is given
$titles = \ManticoreDb::table('products')->pluck('title');
$titles = \ManticoreDb::table('products')->pluck('title', 'id');
```

`first()` and `find()` answer with `null` when nothing matched. Where that is a 404 rather than a
branch of the code, there are the two that say so:

```php
// Illuminate\Database\RecordsNotFoundException, which Laravel answers with a 404 of its own accord
$row = \ManticoreDb::table('products')->where('sku', $sku)->firstOrFail();
$row = \ManticoreDb::table('products')->findOrFail($id);
```

A `Row` reads both ways, so the code written against the array answer of the standalone builder
keeps working:

```php
$row->title;     // as an object, like in Laravel
$row['title'];   // as an array, like in the standalone builder

$row->toArray();
$row->toJson();
json_encode($rows);   // an array of objects, as expected of a JSON API
```

Writes are untouched and keep the answers of the builder: `insert()` returns `bool`,
`update()` and `delete()` the number of affected rows, `insertGetId()` the id.

## Search metadata

`get()` loads rows and `SHOW META`. `getRows()` skips `SHOW META` and still returns the same
zero-based `Collection` of `Row` objects. When metadata is needed separately, read it immediately
after the search, before another command runs on that connection:

```php
$query = \ManticoreDb::table('products')->match('galaxy')->limit(20);
$rows = $query->getRows();
$meta = $query->getMeta();

$meta->totalFound();       // int|null
$meta->total();            // int|null
$meta->time();             // float|null
$meta['total_found'];      // the same value as totalFound()
$meta->toArray();
```

`Meta` is immutable, iterable and countable; absent fields return `null`. `getMeta()` executes
`SHOW META` on the query's connection and replaces its last result set with that service result.
To inspect metadata already loaded by `get()` without another SQL request, keep its result set:

```php
$rows = $query->get();
$result = $query->lastResultSet();
$meta = $result->metaObject();   // no SQL
$loaded = $result->hasMeta();
$values = $result->meta();      // existing array API
```

After `getRows()`, the search result has no loaded metadata; `hasMeta()` is false. A later
`getMeta()` reads current connection metadata, rather than filling that earlier result set.

## Write results and deferred IDs

`insert()` and `replace()` return `bool`. Use `insertGetId()` / `replaceGetId()` for a single ID,
or `insertResultSet()` / `replaceResultSet()` for the full result, including multiple IDs.
These explicit ID/result methods fetch IDs immediately. See the upstream
[writing guide](https://github.com/aVadim483/manticore-query-builder-php/blob/v2.6.0/docs/writing.md)
for multi-row writes and error handling.

By default, boolean writes also fetch IDs immediately. To avoid that service query when the IDs
are usually unused, add `'lazy_insert_ids' => true` to the relevant connection in
`config/manticore.php`. This optional setting is disabled by default and has no dedicated
environment variable in this package.

```php
$query = \ManticoreDb::table('products');
if (!$query->insert(['title' => 'Galaxy', 'price' => 100])) {
    throw new \RuntimeException((string)$query->lastResultSet()->error());
}
$result = $query->lastResultSet();
$id = $result->result();   // loads IDs here when lazy_insert_ids is enabled
```

Fetch deferred IDs before **any next command on the same client/connection**, even a read or
service query. An expired result throws `LogicException`; loaded IDs are cached. If ID loading
fails after a successful write, `result()` throws and remembers the error while the write's
success status remains unchanged. Custom clients without deferred-ID support keep eager loading.

## When a query fails

A rejected read throws `avadim\Manticore\QueryBuilder\QueryErrorException` — a query with a
mistake in it is a bug, not an empty result set, and an exception says so where it happened:

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

Writes keep answering with a value instead: `false` from `insert()`, zero affected rows from
`update()` and `delete()`. The reason of the last statement, successful or not, is kept in its
result set:

```php
\ManticoreDb::lastResultSet()->error();
```

A full-text query typed by a visitor is the usual source of a rejected read, so it is worth
passing through `\ManticoreDb::escapeMatch($text)` before it reaches `match()`.

## Pagination

```php
// Illuminate\Pagination\LengthAwarePaginator, the page number taken from the request
$products = \ManticoreDb::table('products')->match('galaxy')->paginate(15);

// ... and without the COUNT(*) of the total, when the template only needs "next" and "previous"
$products = \ManticoreDb::table('products')->simplePaginate(15);
```

```blade
@foreach ($products as $product)
    {{ $product->title }}
@endforeach

{{ $products->links() }}
```

Both accept the same arguments as in Laravel: `paginate($perPage, $columns, $pageName, $page)`.

A server looks through `max_matches` rows of a result and no further - a thousand of them unless
the configuration says otherwise - and a window past that is not an empty page but an error,
`offset out of bounds`. A paginator of fifteen rows reaches it on page 68, having drawn a link to
it a moment earlier. So both methods ask the server for exactly as many matches as the page they
were given needs, and only when the page needs more than the default; an explicit `maxMatches()`
of yours is left alone, whether it is larger or smaller. A deep page still costs the server the
sorting of everything before it - that is the nature of `LIMIT`/`OFFSET`, not of this package.

## Walking a large result

A result too large to be read at once is walked page by page. Every walk hands over the shapes
the other reads answer with: a page is a `Collection`, a row is a `Row`.

```php
// pages of 500, until the result ends or the callback returns false
\ManticoreDb::table('products')->orderBy('id')->chunk(500, function ($rows, $page) {
    foreach ($rows as $row) {
        // ... $row->title
    }
});

// the same walk, but by the id column
\ManticoreDb::table('products')->chunkById(500, function ($rows, $page) { /* ... */ });

// row by row, with a running number
\ManticoreDb::table('products')->each(function ($row, $number) { /* ... */ });

// a generator of Row, asking the server for the next page only when it is reached
foreach (\ManticoreDb::table('products')->orderBy('id')->lazy(500) as $row) {
    // ...
}

// the only row that matches - RecordsNotFoundException when there is none,
// MultipleRecordsFoundException when there is more than one
$product = \ManticoreDb::table('products')->where('sku', $sku)->sole();
```

`cursor()` is an alias of `lazy()`. Both are generators rather than a `LazyCollection`, because
that is what the query builder declares them to be; `LazyCollection::make($query->lazy())` wraps
one where the methods of a collection are wanted.

**`chunkById()` is the one to walk a whole result with.** `chunk()`, `each()` and `lazy()` page
with `LIMIT`/`OFFSET`, so they run into the same `max_matches` the pagination does - a walk over
more than a thousand rows throws `offset out of bounds` rather than ending early, and raising
`maxMatches()` to the size of the whole result is what it would take to finish it. They also
depend on the order of the result being fixed: two pages of a query without an `orderBy()` are two
separate searches, and an unstable order between them shows the same row twice while another one
is never seen. `chunkById()` has neither problem - it asks for the rows after the last id of the
previous page, so nothing is skipped and no offset grows.

## Transactions

Manticore serves `BEGIN` / `COMMIT` / `ROLLBACK` on real-time tables, so a transaction is
written the way it is elsewhere in Laravel. Transactions live in the query builder itself, so
the static call works too:

```php
\ManticoreDb::transaction(function ($connection) {
    $connection->table('products')->insert($data);
    $connection->table('log')->insert($record);
});

// ... or by hand
$connection = \ManticoreDb::connection();
$connection->beginTransaction();
$connection->table('products')->insert($data);
$connection->commit();   // or rollBack()
```

The callback receives the connection, and whatever it returns becomes the result of
`transaction()`. An exception rolls the transaction back and is rethrown; a second argument
sets how many times to try. Manticore has no savepoints, so a nested `transaction()` only
counts a level deeper — the outermost commit is the one that writes.

Any SQL statement can be run directly:

```php
\ManticoreDb::connection()->statement('FLUSH RAMCHUNK products');   // true when the server accepted it
```

## Migrations

A table of Manticore is created in a Laravel migration like any other:

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
        \ManticoreDb::drop('products', true);   // true - drop it only if it exists
    }
}
```

Manticore has no transactional DDL, so a migration that fails halfway leaves behind whatever it
had already created — write `down()` so that it can be run over a half-built table.

A table already in use is changed the same way:

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

Three things this differs in from a migration of a SQL database:

* **One operation per statement.** The server takes no `ADD COLUMN a, ADD COLUMN b`, so the two
  columns above are two statements — and the second failing leaves the first one added, since
  there is nothing to roll back with.
* **The rows written earlier keep an empty value** in the new column: adding it does not reindex
  them. A full-text field added this way finds only the rows written after it, so the data has to
  be written again for the column to mean anything.
* **The cache of the schema is dropped for you**, but only in the connection that ran the
  statement — see [Long-running workers](#long-running-workers) for the ones that did not.

## Long-running workers

A connection remembers the schema of every table it has described, which saves a `DESCRIBE`
before each query and is what casts the values of a row into PHP types. The cache lives as long
as the connection does — the length of a request under php-fpm, but much longer under Octane, a
queue worker or a scheduled command.

So a table altered from somewhere else needs the cache dropped:

```php
\ManticoreDb::forgetSchema();
```

This drops the schemas of every table of the connection — there is no way to drop a single one.
An application that reads through more than one named connection has more than one cache, and the
call above reaches the default one; the facade drops the lot:

```php
app('manticore')->forgetSchemas();   // every connection the manager built
```

(The manager knows the connections it built itself; one opened through `\ManticoreDb::connection()`
is not among them and keeps its cache.)

The builder keeps its own cache in step: `create()`, `alter()`, `addColumn()`, `dropColumn()`,
`truncate()`, `rename()` and `drop()` drop the schema of the table they touched — but only in the
connection that ran the statement.

A stale cache throws nothing. The column types are needed in three places: reading casts the
values of a row into PHP types, `insert()` and `update()` format the values for SQL. An unknown
column is formatted after the type of the value itself and usually gets through, while a type that
has changed — a column that was `string` and is now `integer` — gives either an error from the
server or quietly wrong data.

The condition for dropping the cache by hand is always the same: the schema was altered past your
connection, and the connection outlived it. In practice that is four cases.

* **An `ALTER` through `statement()`.** Raw SQL goes past the builder, which has no way to drop
  the cache:

  ```php
  \ManticoreDb::connection()->statement('ALTER TABLE products ADD COLUMN rating int');
  \ManticoreDb::forgetSchema();
  ```

* **A migration run by another process.** `php artisan migrate` is done, while the queue worker or
  the Octane process started before it lives on with the old cache. The most common case: an
  ordinary deployment looks exactly like this.
* **A table altered by someone else** — an indexer, a neighbouring service, another application on
  the same server.
* **Another named connection of the same application.** Altered through `connection('admin')`,
  read through `connection('default')` — those are two different caches.

Under php-fpm, calling `forgetSchema()` in a controller is almost always pointless: the connection
lives for one request, and the cache does not outlive the response. Tests are the other case: a
test that alters tables through `statement()` and reuses the connection has to drop the cache in
`setUp()`, or the next test sees the schema of the previous one.

Dropping the cache costs one `DESCRIBE` per table on the next query. `DESCRIBE` goes out as a
service query and never reaches the logger, so a stale cache leaves no trace in the log.

A connection outlives more than its schema cache in a worker: the handle itself may be gone —
closed by the server after a night of idling, or lost with the network — and every query through
it fails from then on. The connection is dropped by name, and the next call for it opens a new one:

```php
app('manticore')->purge();                 // the default connection
app('manticore')->purge('analytics');      // a named one

$connection = app('manticore')->reconnect();   // ... and open it again in one call
```

Neither closes the object: whoever still holds one goes on using it, and the handle behind it is
released with the last reference. Both drop the connection from the pool of the builder as well,
so `\ManticoreDb::connection()` hands out the new one too.

## Logging

Naming a logging channel of the application is enough to see the queries in the log it writes:

```dotenv
MANTICORE_LOG_CHANNEL=stack
```

The channel is a PSR-3 logger, which is what the builder takes, so it is handed over as it is when
the package boots. Nothing is logged while the variable is empty, and that is the default.

A logger is attached by hand just as well, at whatever depth is needed:

```php
// Enable logging for all
\ManticoreDb::setLogger(\Log::getLogger());

// Enable logging for the specified connection
\ManticoreDb::connection('test')->setLogger(\Log::getLogger());

// Enable logging for the next query
\ManticoreDb::table('test')->match($match)->where($where)->setLogger(\Log::getLogger())->get();
```

Any PSR-3 logger will do, `\Log::getLogger()` is the one of Laravel. Service queries the builder
sends on its own, `DESCRIBE` among them, are not logged.

## Documentation

The syntax of a query, the schema of a table and everything else the builder does is described in
the [documentation](https://github.com/aVadim483/manticore-query-builder-php/blob/main/docs/README.md)
of [avadim/manticore-query-builder-php](https://packagist.org/packages/avadim/manticore-query-builder-php).
Manticore Search itself is documented at https://manual.manticoresearch.com/

## Testing

```sh
composer install
vendor/bin/phpunit
```

Some tests need a running ManticoreSearch server. By default they use `127.0.0.1:9306`, this can be
changed with the `MANTICORE_TEST_HOST` and `MANTICORE_TEST_PORT` environment variables in `phpunit.xml.dist`.
If the server is unreachable those tests are skipped, the rest of the suite still runs.
Tables created by the tests are named `phpunit_*` and are dropped afterwards.
