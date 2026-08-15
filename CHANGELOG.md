# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the package follows
[semantic versioning](https://semver.org/).

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

[2.0.0]: https://github.com/aVadim483/manticore-query-builder-laravel/compare/v1.0.4...v2.0.0
[1.0.4]: https://github.com/aVadim483/manticore-query-builder-laravel/compare/v1.0.3...v1.0.4
[1.0.3]: https://github.com/aVadim483/manticore-query-builder-laravel/compare/v1.0.0...v1.0.3
[1.0.0]: https://github.com/aVadim483/manticore-query-builder-laravel/releases/tag/v1.0.0
