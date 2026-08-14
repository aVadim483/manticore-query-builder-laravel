[![Latest Stable Version](http://poser.pugx.org/avadim/manticore-query-builder-laravel/v)](https://packagist.org/packages/avadim/manticore-query-builder-laravel) 
[![Total Downloads](http://poser.pugx.org/avadim/manticore-query-builder-laravel/downloads)](https://packagist.org/packages/avadim/manticore-query-builder-laravel) 
[![License](http://poser.pugx.org/avadim/manticore-query-builder-laravel/license)](https://packagist.org/packages/avadim/manticore-query-builder-laravel) 
[![PHP Version Require](http://poser.pugx.org/avadim/manticore-query-builder-laravel/require/php)](https://packagist.org/packages/avadim/manticore-query-builder-laravel)

# ManticoreSearch Query Builder for Laravel

An easiest way to use the [ManticoreSearch Query Builder](https://github.com/aVadim483/manticore-query-builder-php)
in your Laravel or Lumen applications. This package allows you to build ManticoreSearch queries using a Laravel-like syntax.

```sh
composer require avadim/manticore-query-builder-laravel
```

## Installation

### Laravel

The package's service provider will automatically register its service provider.

Publish the configuration file:

```sh
php artisan vendor:publish --provider="avadim\Manticore\Laravel\ServiceProvider"
```

#### Alternative configuration method via .env file

After you publish the configuration file as suggested above, you may configure ManticoreSearch by adding the following
to your application's `.env` file (with appropriate values):

```dotenv
MANTICORE_HOST=localhost
MANTICORE_PORT=9306
MANTICORE_USER=
MANTICORE_PASS=
MANTICORE_TIMEOUT=5
```

#### All available environments variables

| Name                      | Default value | Description                           |
|---------------------------|---------------|---------------------------------------|
| MANTICORE_CONNECTION      | default       | Name of default connection            |
| MANTICORE_HOST            | localhost     | Address of host with Manticore server |
| MANTICORE_PORT            | 9306          | Port number with REST server          |
| MANTICORE_USER            |               | Username                              |
| MANTICORE_PASS            |               | Password                              |
| MANTICORE_TIMEOUT         | 5             | Timeout between requests              |

### Lumen

If you work with Lumen, please register the service provider and configuration in `bootstrap/app.php`:

```php
// Enable shortname of facade
$app->withFacades(true, [
    'avadim\Manticore\Laravel\Facade' => 'Facade',
]);

// Register Config Files
$app->configure('manticore');

// Register Service Providers
$app->register(avadim\Manticore\Laravel\ServiceProvider::class);
```

Manually copy the configuration file to your application.

## How to use

```php
// Get list of tables via the default connection
$list = \ManticoreDb::showTables();

// Get list of tables via the specified connection
$list = \ManticoreDb::connection('test')->showTables();

\ManticoreDb::table('t')->insert($data);
\ManticoreDb::table('t')->match($match)->where($where)->get();

```

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

Both entry points answer alike — the `\ManticoreDb` alias and the container service behind the
facade share the same connection.

Create a table of ManticoreSearch in Laravel migration
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
        \ManticoreDb::table('products')->dropIfExists('users');
    }
}
```

## Logging

You can use logger instance for logging in this package.

```php
// Enable logging for all
ManticoreDb::setLogger(\Log::getLogger());

// Enable logging for the specified connection
ManticoreDb::connection('test')->setLogger(\Log::getLogger());

// Enable logging for the next query
ManticoreDb::table('test')->match($match)->where($where)->setLogger(\Log::getLogger())->get();
```


## Testing

```sh
composer install
vendor/bin/phpunit
```

Some tests need a running ManticoreSearch server. By default they use `127.0.0.1:9306`, this can be
changed with the `MANTICORE_TEST_HOST` and `MANTICORE_TEST_PORT` environment variables in `phpunit.xml.dist`.
If the server is unreachable those tests are skipped, the rest of the suite still runs.
Tables created by the tests are named `phpunit_*` and are dropped afterwards.

More info about ManticoreSearch Query Builder see in 
[documentation](https://github.com/aVadim483/manticore-query-builder-php/blob/main/docs/README.md)
of the package
[avadim/manticore-query-builder-php](https://packagist.org/packages/avadim/manticore-query-builder-php)
