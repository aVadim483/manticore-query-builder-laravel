# Testing the native driver

[Русский](ru/testing.md)

Clone the repository before running these commands. Composer dist archives exclude tests/,
benchmarks/ and CI configuration; use the checkout's source and development dependencies.
The standard suite boots the package's ServiceProvider and rejects loading classes from
the removed standalone PHP builder.

## Run the suite

```sh
composer install
MANTICORE_TEST_HOST=127.0.0.1 MANTICORE_TEST_PORT=9306 MANTICORE_TEST_BUDDY=1 php tests/check-server.php
php tests/check-api-contracts.php
MANTICORE_TEST_HOST=127.0.0.1 MANTICORE_TEST_PORT=9306 MANTICORE_TEST_BUDDY=1 vendor/bin/phpunit --fail-on-skipped
```

The test server needs Buddy and the KNN library. check-server.php validates the version
and required modules; the suite must pass without skips. Tests create isolated random
tables and remove them afterward. Eloquent compatibility is outside this test profile.

## Server compatibility

The supported minimum is Manticore 28.6.6, the earliest server verified for this driver.
Older versions are outside the support policy; normal connections do not add a
version-check query. Individual features still require their modules and table settings.

The [CI workflow](https://github.com/aVadim483/manticore-query-builder-laravel/blob/main/.github/workflows/tests.yml)
crosses these four runtime combinations with both the official minimum image, pinned
by digest, and the moving latest image:

| PHP | Laravel |
| --- | --- |
| 8.2 | 11 |
| 8.3 | 12 |
| 8.4 | 13 |
| 8.5 | 13 |

Set MANTICORE_TEST_EXPECTED_VERSION=28.6.6 to check the exact minimum.
MANTICORE_TEST_IMAGE labels the server report; MANTICORE_TEST_SERVER_REPORT optionally
saves it to an existing directory. Following latest detects upstream changes;
it is not a promise that an untested future server version will work.

## Production-only consumers

```sh
php tests/prepare-production.php 13
composer update --working-dir=build/production --no-dev --prefer-dist --no-interaction
php tests/production-smoke.php build/production/vendor/autoload.php --live
```

Set MANTICORE_TEST_HOST and MANTICORE_TEST_PORT for the live commands. Select 11, 12
or 13 in prepare-production.php. The isolated consumer uses bare Illuminate components
without Testbench, PHPUnit or the full Laravel framework. It checks registration,
compilation and, with --live, schema, writes/IDs, casts, metadata, transactions,
hybrid search, capability probes and index replacement.

CI additionally installs the minimum Illuminate 11.0.0, 12.0.0 and 13.0.0 consumers.
To prepare one locally, pass the exact component version as the second argument:
php tests/prepare-production.php 13 13.0.0; the generated directory is build/production-floor-13.
Run tests/check-api-contracts.php with that consumer's autoloader as its argument.

An isolated full-suite installation can set MANTICORE_TEST_AUTOLOAD to its absolute
Composer autoloader path and use its own PHPUnit with this checkout's phpunit.xml.dist.
Its development autoload mapping must point the package's Tests namespace at this
checkout's tests/ directory.

## Targeted checks

- Schema tests cover integer widening, settings, partial DDL failure, cache invalidation,
  migrations and rename. See [schema limits](schema-alter.md).
- HybridGuideIntegrationTest executes the labelled examples in both
  [English](hybrid-search.md) and [Russian](ru/hybrid-search.md) guides, checking types,
  semantic-only hits, shared filters, metadata and window pagination.
- Replacement tests cover readers during rename gaps, interruptions and rollback;
  see [replacement requirements](hybrid-and-index-replacement.md).
- DocumentationTest checks README configuration, contents and translation structure.
  API checks read docs/laravel-query-builder-api.json and docs/query-builder-contracts.json.
- On a separate server with Buddy disabled, run php tests/schema-no-buddy.php.
  It checks rename failure without data loss and unavailable fuzzy/autocomplete evidence.
  The script rejects Buddy-enabled servers and is separate from the normal suite.

The [benchmark instructions](https://github.com/aVadim483/manticore-query-builder-laravel/blob/main/benchmarks/README.md)
cover script setup and small smoke runs. Recorded results and workload limitations are in
[performance](performance.md). Small smoke runs validate scripts, not performance claims.
