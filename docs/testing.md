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
General Eloquent compatibility remains unverified.

The [benchmarks](../benchmarks/README.md) have a separate smoke check using 100 documents for
each insertion client and 120 documents / 5 queries per search profile. All four search clients
must return the same number of rows. Small smoke runs validate scripts, not performance claims.

## Server compatibility

The supported minimum is **Manticore 28.6.6**, the earliest server verified for this driver.
Older versions are outside the support policy; they are not automatically rejected on every
connection. Server features still need their corresponding modules and table settings.
See the [official 28.6.6 release notes](https://manticoresearch.com/blog/manticore-search-28-6-6/).

CI crosses all four PHP/Laravel runtime combinations with these two server targets:

| Target | Image | Purpose |
| --- | --- | --- |
| Minimum | `manticoresearch/manticore:28.6.6@sha256:341f333906fb2183fe585ad66de3486f372b2bbdd39e0e001d280a54f06d893a` | Reproducible floor, including its bundled modules |
| Latest | `manticoresearch/manticore:latest` | Detect changes in the current official stable image |

Run `php tests/check-server.php` before the suite. It checks SHOW STATUS, rejects a server below
28.6.6, waits for Buddy and KNN, and prints the full server/module versions. Set
`MANTICORE_TEST_EXPECTED_VERSION=28.6.6` to ensure the minimum job has the exact expected version.
`MANTICORE_TEST_IMAGE` labels the report; `MANTICORE_TEST_SERVER_REPORT` optionally saves it
to an existing directory. The full suite and production consumer must pass without skips.
The driver does not add a version-check SQL round trip to application connections.

The minimum image bundles Buddy 4.2.0 and Columnar/KNN 13.8.3. On 2026-10-21 the `latest`
digest was `sha256:461b419739a2bbbffb4be0b32db26c41d7b435bf7fd1fce95b414ca5ccbb3775`, containing
Manticore 29.9.0, Buddy 4.4.3 and Columnar/KNN 14.2.1. The `latest` CI job deliberately follows
the tag; it is not frozen to this observed digest. A future tag change must be verified by CI.

Compatibility runs on 2026-10-21 passed with PHP 8.4.19: the minimum on Laravel 11/12/13,
and the observed `latest` on Laravel 13, each with 130 tests / 628 assertions and no skips.
The production-only Illuminate 13.38.0 consumer passed on both servers. For these local runs,
the executables and bundled modules were extracted from the official images and run as
isolated user services; Docker container startup and other PHP versions are covered by CI.

A subsequent [live application migration](migration-app-verification.md) verifies a deployed
Laravel 12 application on 3.0.0 and the published 4.0.0-alpha.1, including browser forms and
preserved search data. This is separate from the package-level matrix above.

## Extended Schema Builder verification

On 2026-10-22, the unreleased [extended schema API](schema-alter.md) passed the full suite
on PHP 8.4.19 in four combinations: Manticore 28.6.6 on Laravel 11/12/13, and Manticore
29.9.0 on Laravel 13. Each run completed **147 tests / 749 assertions**, without skips.
The production-only Illuminate 13.38.0 consumer passed on both servers, including
settings inspection, integer widening and a round-trip table rename.

Coverage includes preserved 32/64-bit values, fresh source-schema validation, columnar/type
rejection, ordered settings, defaults omitted by the server, partial ALTER failures,
cache invalidation after rename conflicts and real migration upgrade/rollback with the
migration repository on SQLite. Rollback deliberately retains bigint instead of narrowing it.
The protocol contract is recorded in [schema compatibility](schema-alter-compatibility.md).

A separate Manticore 29.9.0 process with `buddy_path` empty passed
`php tests/schema-no-buddy.php`: rename raises `QueryException` and preserves the original
table and documents. Run this script separately with `MANTICORE_TEST_HOST` and
`MANTICORE_TEST_PORT` pointing at a no-Buddy test instance; it rejects instances with Buddy.
It is not part of the ordinary Buddy-enabled PHPUnit run. All temporary compatibility
services were stopped after verification; the persistent application test server was retained.
