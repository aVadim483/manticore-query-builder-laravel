[English](testing.md) | [Русский](ru/testing.md)

# Native driver verification

The standard suite boots the package's auto-discovered `ServiceProvider`. It covers query
compilation, bindings, Laravel contracts, writes/IDs, transactions, schema/migrations, casts,
connection lifecycle, search helpers, streaming and pagination. The default bootstrap rejects
loading any class from the removed standalone PHP builder.

```sh
composer install
MANTICORE_TEST_HOST=127.0.0.1 MANTICORE_TEST_PORT=9306 MANTICORE_TEST_BUDDY=1 vendor/bin/phpunit --fail-on-skipped
```

CI resolves dependencies independently for Laravel 11, 12 and 13, including PHP 8.2 at the floor
and PHP 8.5 at the upper end. It requires a live Manticore with Buddy and the KNN library.
Each job then verifies an isolated production consumer:

```sh
php tests/prepare-production.php 13
composer update --working-dir=build/production --no-dev --prefer-dist --no-interaction
php tests/production-smoke.php build/production/vendor/autoload.php --live
```

Use `11`, `12` or `13` in the first command to select the Illuminate Database major. The consumer
installs the current checkout as a path dependency, with no Testbench, PHPUnit or full Laravel
framework. Its smoke test registers the provider in a bare Illuminate container and checks lazy
resolution, query compilation and, with `--live`, Schema, insert IDs, structured casts, search
metadata and transactions. The live check drops its uniquely named test table in `finally`.
Without `--live`, it checks registration/compilation without connecting to a server.

An isolated full-suite installation can set `MANTICORE_TEST_AUTOLOAD` to its own absolute
Composer autoloader path and invoke its own PHPUnit executable with the repository's
`phpunit.xml.dist`. Its test autoload mapping must point `avadim\Manticore\Laravel\Tests\`
at this checkout's `tests/` directory. This prevents the root vendor directory from silently
selecting another framework version.

On 2026-10-21, the full suite passed on PHP 8.4.19 with Laravel 11.57.0, 12.70.1 and 13.30.1
against Manticore 29.9.0, Buddy 4.4.3 and Columnar/KNN 14.2.1: 130 tests per framework,
without failures or skips. PHP-version coverage remains the responsibility of CI.
These checks do not establish a minimum supported Manticore version or Eloquent compatibility.

The [benchmarks](../benchmarks/README.md) have a separate smoke check using 100 documents for
each insertion client and 120 documents / 5 queries per search profile. All four search clients
must return the same number of rows. Small smoke runs validate scripts, not performance claims.
