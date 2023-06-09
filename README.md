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


More info about ManticoreSearch Query Builder see in 
[documentation](https://github.com/aVadim483/manticore-query-builder-php/blob/main/docs/README.md)
of the package
[avadim/manticore-query-builder-php](https://packagist.org/packages/avadim/manticore-query-builder-php)
