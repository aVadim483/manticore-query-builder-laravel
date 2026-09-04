# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the package follows
[semantic versioning](https://semver.org/).

## [Unreleased]

### Breaking

* **The package requires Laravel 11 and PHP 8.2.** The ranges of `illuminate/*` are narrowed to
  `^11.0|^12.0|^13.0` and `php` to `^8.2`; Lumen 11 asks for the same two, so it is still served.

  What the code can run on is not what changed - the suite passes on every Laravel from 6 to 13,
  which is exactly why the range said so. What changed is what that range is worth. Laravel 10
  and 11 are past their security support, and every release of 6 to 11 is flagged by an advisory,
  so composer will not resolve one from scratch any more: a claim about them cannot be tested,
  only asserted. PHP 8.2 is the oldest version still getting security fixes of its own, and
  Laravel 11 is the major that sits on it.

  An application on an older Laravel is not broken by this: composer never re-resolves a framework
  that is already installed, so it keeps installing 2.x, which runs there. Version 2.x is where
  Laravel 6 to 13 and PHP 7.4 stay.

  For the sources this lifts the floor of the syntax: everything up to PHP 8.2 is now allowed in
  `src/`, and the tests are free to use the attributes of PHPUnit 10.

* **The walks of a result answer the way the other reads do.** `chunk()`, `chunkById()`,
  `each()`, `lazy()`, `cursor()` and `sole()` were inherited from the query builder untouched, so
  they handed out plain arrays while `get()` and `first()` of the same query answered with a
  `Collection` of `Row`. They now hand over the same shapes: a page is a `Collection`, a row is a
  `Row`. `foreach` over a page and `$row['title']` are unaffected; code that treated a page as an
  array - `array_map()`, `array_column()`, `array_slice()` - works with the collection instead.

### Added

* Continuous integration: the whole suite runs against a Manticore service container on every
  Laravel the package declares - 11 on PHP 8.2, 12 on 8.3, 13 on 8.4 and on 8.5, which is the
  newest PHP the framework allows. Both ends of the range are checked rather than assumed. The
  job of the floor turns `audit.block-insecure` off, because installing an EOL framework from
  scratch is the one case composer refuses.
* `.gitattributes` - the tests, the benchmarks and the CI config are no longer part of the
  package: they are read on GitHub, not from `vendor/`. Line endings are pinned to LF as well,
  so that a file does not depend on the machine it was committed from.

### Changed

* Requires `avadim/manticore-query-builder-php` 2.2, where non-ASCII text in a raw statement is no
  longer cut off, a statement the server refused is raised as an error instead of coming back as a
  successful empty result, and `timeout` limits the opening of the connection rather than being
  applied to a handle already connected. The breaking changes of that release - a value that looks
  like `:word` is escaped instead of being read as a named parameter, and the rows of a join come
  back as a list - touch nothing this package has: neither its classes, nor its tests, nor the
  examples of the readme use either.
* The range of `phpunit/phpunit` is `^10.5|^11.0|^12.0|^13.0`, and that of `orchestra/testbench`
  `^9.0|^10.0|^11.0` - what the supported Laravel versions bring with them.
* `psr/log` is required as `^1.1|^2.0|^3.0` instead of the unbound `>=1.1`, which would have let a
  future major of the interface in unchecked.

### Documentation

* The section on long-running workers says when `forgetSchema()` is actually needed: what a stale
  cache breaks - reads cast and writes format after the old column types, quietly rather than by
  throwing - and the four cases that call for dropping it by hand, an `ALTER` through
  `statement()`, a migration run by another process, a table altered by someone else, and another
  named connection of the same application. Plus the cases it is pointless in, and what dropping
  it costs.
* A section on walking a large result: what `chunk()`, `chunkById()`, `each()`, `lazy()`,
  `cursor()` and `sole()` hand over, and why a walk over the whole of a result belongs to
  `chunkById()` - the others page with `LIMIT`/`OFFSET`, which runs into `max_matches` and needs
  the order of the result to be fixed, or a row comes twice while another never does.

## [2.1.1] - 2026-08-15

### Documentation

* The section on migrations shows how to change a table that is already in use — `addColumn()`
  and `dropColumn()` in `up()` and `down()` — and what that differs in from a migration of a SQL
  database: one operation per statement with nothing to roll back with, an empty value in the new
  column for the rows written before it, and the cache of the schema dropped only in the
  connection that ran the statement.

## [2.1.0] - 2026-08-15

### Changed

* Requires `avadim/manticore-query-builder-php` 2.1, where the `CALL *` statements of the server
  became methods of the builder — `callSuggest()`, `callQsuggest()`, `callKeywords()`,
  `callSnippets()` and `callPq()`. They are reached through this package as they are, e.g.
  `\ManticoreDb::table('?posts')->callSuggest('mantikore')`.

## [2.0.0] - 2026-08-15

The release the reads change shape in, so the major number goes up with the one of the query
builder it is built on.

### Changed

* **Breaking.** Reads answer the way Laravel does: `get()` and `pluck()` return an
  `Illuminate\Support\Collection`, `first()` and `find()` a `Row` object. The standalone query
  builder keeps answering with arrays. A `Row` reads both as an object and as an array, so
  `$row->title` and `$row['title']` both work and existing code keeps running.
* **Breaking.** Requires `avadim/manticore-query-builder-php` 2.0, where a rejected read throws
  `QueryErrorException` instead of answering with `null`.
* Both entry points answer alike: the `\ManticoreDb` alias, which points at the query builder
  itself, and the container service behind the facade. The service provider tells the builder to
  build its connections of `Laravel\Connection`, whose queries are `Laravel\Query`.
* Supports Laravel 10 through 13, and PHPUnit 10 through 12 in the tests. Laravel 6 stays the
  declared minimum.

### Added

* `paginate()` and `simplePaginate()`, with the arguments of Laravel and answering with
  `LengthAwarePaginator` and `Paginator`.
* `MANTICORE_PREFIX` and `MANTICORE_FORCE_PREFIX` in the configuration file. Both keys existed
  and could only be edited in the published file.
* `@method` annotations on `Manager` and `Facade`, so an IDE completes the methods the two proxy
  to the connection. `AnnotationsTest` checks them against the query builder both ways.
* A test suite on Orchestra Testbench. The tests that need a running server are skipped when
  there is none.
* Documentation of what the reads return, of pagination, transactions, migrations, failed
  queries and the schema cache of a long-running worker, and a Russian translation of the readme.

### Removed

* Transactions and `statement()` moved into the query builder, where the static `\ManticoreDb`
  call reaches them as well. They keep working through this package unchanged.

## [1.0.4] - 2023-03-20

### Added

* Logging of queries through a `Psr\Log\LoggerInterface`.

## [1.0.3] - 2023-03-19

### Fixed

* The service provider initialises the query builder in `boot()`.

## [1.0.0] - 2023-03-19

The first release: service provider, facade, connection manager and the configuration file.

[2.1.1]: https://github.com/aVadim483/manticore-query-builder-laravel/compare/v2.1.0...v2.1.1
[2.1.0]: https://github.com/aVadim483/manticore-query-builder-laravel/compare/v2.0.0...v2.1.0
[2.0.0]: https://github.com/aVadim483/manticore-query-builder-laravel/compare/v1.0.4...v2.0.0
[1.0.4]: https://github.com/aVadim483/manticore-query-builder-laravel/compare/v1.0.3...v1.0.4
[1.0.3]: https://github.com/aVadim483/manticore-query-builder-laravel/compare/v1.0.0...v1.0.3
[1.0.0]: https://github.com/aVadim483/manticore-query-builder-laravel/releases/tag/v1.0.0
