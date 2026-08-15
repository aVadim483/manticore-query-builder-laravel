**English** | [Русский](README.ru.md)

[![GitHub Release](https://img.shields.io/github/v/release/aVadim483/manticore-query-builder-laravel)](https://packagist.org/packages/avadim/manticore-query-builder-laravel)
[![Packagist Downloads](https://img.shields.io/packagist/dt/avadim/manticore-query-builder-laravel?color=%23aa00aa)](https://packagist.org/packages/avadim/manticore-query-builder-laravel)
[![GitHub License](https://img.shields.io/github/license/aVadim483/manticore-query-builder-laravel)](https://packagist.org/packages/avadim/manticore-query-builder-laravel)
[![Static Badge](https://img.shields.io/badge/php-%3E%3D7.4-005fc7)](https://packagist.org/packages/avadim/manticore-query-builder-laravel)
[![Static Badge](https://img.shields.io/badge/laravel-6%20--%2013-ff2d20)](https://packagist.org/packages/avadim/manticore-query-builder-laravel)

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
* [The alias, the facade and the container](#the-alias-the-facade-and-the-container)
* [What the reads return](#what-the-reads-return)
* [When a query fails](#when-a-query-fails)
* [Pagination](#pagination)
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

* PHP 7.4 or above with the PDO and JSON extensions
* Laravel 6 — 13, or Lumen
* [avadim/manticore-query-builder-php](https://github.com/aVadim483/manticore-query-builder-php) 2.1 or above, installed along with this package
* Manticore Search reachable over its SQL interface, port 9306 by default. The HTTP/JSON API is not used.

A few things need more than the bare server: KNN vector search needs the KNN library, columnar
storage the columnar one, `rename()` needs Manticore Buddy. What needs what is listed in the
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
| MANTICORE_TIMEOUT      | 5             | Timeout between requests                                           |
| MANTICORE_PREFIX       |               | Prefix that replaces the placeholder `?` in front of a table name  |
| MANTICORE_FORCE_PREFIX | false         | Prefix every table name, not only the ones written with `?`        |

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

## Long-running workers

A connection remembers the schema of every table it has described, which saves a `DESCRIBE`
before each query and is what casts the values of a row into PHP types. The cache lives as long
as the connection does — the length of a request under php-fpm, but much longer under Octane, a
queue worker or a scheduled command.

So a table altered from somewhere else needs the cache dropped:

```php
\ManticoreDb::forgetSchema();
```

The builder keeps its own cache in step: `create()`, `alter()`, `addColumn()`, `dropColumn()`,
`truncate()`, `rename()` and `drop()` drop the schema of the table they touched. What it cannot
know about is a statement of yours — a migration run by another process, an `ALTER` sent through
`statement()`, a table rebuilt by an indexer.

## Logging

You can use logger instance for logging in this package.

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
