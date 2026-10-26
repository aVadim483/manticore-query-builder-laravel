# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the package follows
[semantic versioning](https://semver.org/).

## [Unreleased]

### Changed

* Compile finite scalar floats directly as numeric SQL literals for builder INSERT/REPLACE,
  including the GetId variants and writes delegated through these methods. This avoids
  scanning the generated SQL in NumericBindings while preserving JSON/MVA/vector encoding.
  Query events and logs now contain those float literals in SQL rather than bindings;
  UPDATE, WHERE and raw SQL retain the general numeric renderer.

## [4.0.0] - 2026-10-26

### Changed

* Publish the stable native Laravel driver with the runtime code and API verified in beta.1
  and rc.1. The published RC passed Composer installation, all 32 application, six native,
  eight current-API and 11 browser checks while preserving the original 2,105 documents.
* Update installation, reference, schema and 3.x migration documentation for stable 4.0.

## [4.0.0-rc.1] - 2026-10-26

### Changed

* Promote the frozen beta.1 API to release candidate without runtime changes. The published
  beta package passed Composer installation in the live test application, 32 application,
  six native, eight current-API and 11 browser checks. A real 120-job worker again retained
  no additional memory after warm-up. Preserve release verification and source references.

## [4.0.0-beta.1] - 2026-10-26

### Added

* Repeatable upsert, bulk-read, HNSW hybrid and long-running Laravel worker measurements;
  120 jobs completed in one worker with no retained-memory growth after warm-up.
* Repeated 3.0.0-to-current application migration: all 32 cases, 31 comparable results,
  11 browser scenarios and additional native/current-API checks preserve 2,105 original documents.
* Reviewed API catalog for 299 Laravel/driver methods and frozen parameter/result contracts
  for 19 full-text, JSON and conditional-write methods. CI checks catalog drift and installs
  minimum Illuminate 11.0.0/12.0.0/13.0.0 consumers without dev dependencies.
* `updateOrInsert()` and `replaceOrInsert()` with unambiguous lookup and existence callbacks.
  Emulated `upsert()` preserves non-updated fields through REPLACE and stages its complete
  INSERT/REPLACE batch in one transaction. It joins caller transactions without savepoints;
  execution failures abort the whole transaction. Lookups remain non-isolated.
* Laravel JSON arrow paths and all `whereJson*()` filters, including negative and OR
  variants, with bound membership values and hidden expression aliases. Document native
  Manticore null/key semantics, array candidate restrictions and write limitations.
* Laravel-compatible `whereFullText()` / `orWhereFullText()` with field scoping, literal
  escaping by default, nested text groups and one combined MATCH expression. Unsupported
  options, mixed text/attribute OR conditions and mixing with `match()` fail explicitly.

### Fixed

* Reject DML clauses that the base grammar could silently omit, including DISTINCT, grouping,
  HAVING, hints and filtered TRUNCATE/INSERT/REPLACE. Validate matching bulk column sets.
* Explicitly reject unsupported inherited filter/subquery/date/arithmetic/vector APIs;
  unavailable known where-methods cannot fall through to unrelated dynamic column filters.
* Include deferred MATCH/JSON parameters in getRawBindings() without duplicating nested
  bindings. Reject manual merges of deferred search state and ignored terminal fetch modes.
* Keep TEXT/columnar UPDATE validation enabled when casts are disabled. Backport JSON overlap
  aliases and after-query callback behavior missing from the earliest Laravel 11 releases.
* Hybrid option validation lists all unknown keys in InvalidArgumentException messages.

## [4.0.0-alpha.3] - 2026-10-25

### Added

* Dedicated English and Russian hybrid search guides with examples progressing from a minimal
  call through first/document results, shared filters, branch weights, metadata, window pagination,
  advanced text/KNN options and extended MATCH syntax. Guide examples run against live servers in CI.

### Fixed

* Hybrid common-query validation accepts Laravel's lowercase `between` predicate, including
  nested attribute ranges used by the advanced guide example.
* Serialize KNN oversampling as a SQL float token (for example `2.0`), including whole-number
  values accepted by the PHP API, so Manticore can parse advanced KNN options correctly.

### Changed

* Replace the separate `hybrid()` builder method with terminal `hybridSearch()` (HybridResult),
  `hybridGet()` (Collection of document objects) and `hybridFirst()` (document object or null).
  First ranks the same candidate windows before selecting the best document. Scores and branch
  metadata remain available through hybridSearch().
  Migrate alpha.2 calls from `hybrid(...)->search()` to `hybridSearch(...)`.

## [4.0.0-alpha.2] - 2026-10-22

### Added

* Independent bounded text/vector search with weighted RRF, deterministic bigint ID tie-breaking,
  preserved branch metadata and a separate HybridResult with explicitly scoped window pagination/totals.
* Maintenance-only local RT replacement with server-observed state, resumable forward/rollback steps,
  CREATE LIKE schema preparation and no automatic deletion; distributed/cluster swaps are not promised.
* Lazy per-connection server/module evidence and explicit read-only KNN/fuzzy/CALL probes with bounded
  caches, reconnect/DDL invalidation and original exception preservation.
* Parallel-reader, injected interruption and actual second-rename failure tests, with standalone
  production consumer coverage and bilingual contracts for downstream Scout integration.

* Extended Schema Builder on dev-4.x: ordered ALTER settings with validated names/value formats,
  uncached normalized settings inspection, preflight-checked row-wise int-to-bigint `change()`,
  and prefixed table renames through Buddy. Arbitrary conversions and implicit rebuilds are rejected.
* Schema protocol, partial-failure/cache and real migration upgrade/rollback coverage, a separate
  no-Buddy rename failure check, and production-only smoke coverage for the extended schema API.
* English and Russian extended schema documentation distinguishing these unreleased APIs from alpha.1.

* A live Laravel 12 migration verification report: 32 application scenarios on 3.0.0 and
  4.0.0-alpha.1, 11 browser checks per version and six additional native checks, preserving
  the same 2105-document dataset without editing vendor code.

### Fixed

* Migration guidance now covers JSON-path filters, singular `RecordNotFoundException`,
  required ordering for lazy reads, old score/count aliases and PDO numeric CALL fields.
* Transaction examples and production smoke use REPLACE: Manticore's in-place UPDATE is
  not rolled back by a transaction. This existing server limitation is documented explicitly.

## [4.0.0-alpha.1] - 2026-10-21

First alpha of the native Laravel Database driver. This is a breaking upgrade from 3.x,
intended for early testing. A real application migration and Scout adapter compatibility
have not yet been validated; see the [release notes](docs/releases/4.0.0-alpha.1.md).

### Removed

* The 3.x wrapper API: `ManticoreDb`, package Facade/Manager/Factory/Connection/Query/Row,
  `config/manticore.php`, static connection pools and the standalone PHP-builder dependency.
  Applications must migrate to `DB::connection('manticore')`; no compatibility aliases are installed.

### Fixed

* Native cursors explicitly release PDO results on completion, fetch failures and early iterator
  destruction, preserve fetch error context, and return empty results during `pretend()`.

* Document ID null predicates compile to constants, allowing Laravel 13's initial ById
  boundary check without unsupported numeric NULL filters on Manticore.

* Native CALL option names use validated unquoted identifiers because Buddy's AUTOCOMPLETE
  parser rejects backtick-quoted aliases. Reproduced against Buddy 4.4.3 on Manticore 29.9.0.

### Added

* English and Russian 3.x migration guides covering native connection configuration, API and
  result replacements, IDs/errors, schema migrations, logging and worker lifecycle.
* A production-only consumer smoke test verifies provider registration and live schema/CRUD,
  casts, metadata and transactions without Testbench, the Laravel framework or the old builder.

* Native cursor pagination for stable integer/ID ordering, selected aliases and bidirectional
  encoded cursors, with validated boundary values and no growing offsets or total-count query.
  Float keys are rejected because rounded SQL output can repeat or skip cursor boundaries.
* Exact grouped pagination totals via captured metadata and document-bounded group windows,
  including HAVING and multiple scalar grouping keys, without mutating source queries.

* Integration coverage for large offset and ID walks beyond 1000 matches, reverse traversal,
  aliased/bigint IDs, early termination, lazy execution and deletion of processed documents.

* Native faceted search with named result collections, attribute/MVA/JSON facets, explicit
  bucket ordering/limits and metadata captured before query listeners run.
* Stored-field highlighting, generic bound CALL statements and KEYWORDS/SUGGEST/QSUGGEST/
  SNIPPETS/AUTOCOMPLETE helpers; all result sets are drained before events. Buddy autocomplete
  is required in CI and can be enabled locally with `MANTICORE_TEST_BUDDY=1`.

* Native KNN vector queries with validated vectors/options, distance selection/ordering,
  AND attribute/full-text filters and HNSW vector declarations in the Schema Builder.

* Native search options now support escaped strings, field/index weight maps and built-in or
  expression rankers through `fieldWeights()` and `ranker()`, with live score verification.

* Experimental native Laravel Database driver on `dev-4.x`: `DB::connection('manticore')`,
  a Laravel Query Builder with bound `match()` and numeric `option()`, standard result objects,
  raw bindings, CRUD, insert IDs, lazy collections, cursors and connection lifecycle events.
  The auto-discovered `ServiceProvider` registers this driver; `DatabaseServiceProvider` remains
  an alternative explicit entry point. General Eloquent compatibility remains unverified.
* Native REPLACE, event-safe insert IDs and single-level transactions with explicit failure
  handling, no automatic write retries and preservation of callback/rollback errors.
* Native Schema Builder and migrations: CREATE/DROP, ADD/DROP columns, introspection,
  Manticore Blueprint types and CREATE settings, with unsupported DDL rejected explicitly.
* Schema-cached conversion of JSON, MVA/MVA64, vectors, booleans and numbers across normal
  queries, search results and lazy reads. DDL and reconnect invalidate the connection cache.
* Native driver search results with immutable metadata snapshots, score, MVA ANY/ALL filters,
  geographic filtering/ordering and JSON coordinate paths. Deep SELECT windows automatically
  raise `max_matches` unless explicitly configured. Numeric bindings preserve fractional writes;
  the connector requires emulated prepares to avoid rounding in native prepared UPDATE on 28.6.6.

### Changed

* The supported Manticore floor is 28.6.6. CI crosses every PHP/Laravel combination with a
  digest-pinned minimum image and `latest`, checking actual versions and Buddy/KNN readiness.

* Native CREATE table settings are consistently quoted, including numeric setting values.

* README examples and benchmarks now use the native driver. Historical 3.x benchmark results
  are preserved separately and are not presented as native-driver measurements.
* The full test suite uses the upstream-class guard bootstrap and boots the production provider;
  CI fails if a required server-dependent test is skipped.

## [3.0.0] - 2026-09-04

The release that says what the package supports instead of asserting it, and finishes the shape it
was built for. The floor moves to Laravel 11 and PHP 8.2 - every version below that is either past
its security support or unreachable to composer, so a claim about it could not be tested. The reads
that were still handing out plain arrays now answer the way the rest of them do. And what a Laravel
application expects of a query is there: the two `OrFail` reads, a pagination that survives a deep
page, a logging channel out of the configuration, and a connection a worker can drop and open again.

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

* **`Row` declares the types of its `ArrayAccess` methods.** `offsetGet(mixed $offset): mixed`
  and the three beside it were untyped, with `#[\ReturnTypeWillChange]` to keep PHP 7.4 quiet -
  which the floor of PHP 8.2 no longer needs. A class extending `Row` and overriding one of them
  has to declare the same types.

### Added

* Continuous integration: the whole suite runs against a Manticore service container on every
  Laravel the package declares - 11 on PHP 8.2, 12 on 8.3, 13 on 8.4 and on 8.5, which is the
  newest PHP the framework allows. Both ends of the range are checked rather than assumed. The
  job of the floor turns `audit.block-insecure` off, because installing an EOL framework from
  scratch is the one case composer refuses.
* `firstOrFail()` and `findOrFail()`, answering with a `Row` or raising
  `Illuminate\Database\RecordsNotFoundException` - which Laravel turns into a 404 of its own
  accord, so reading a document by something out of the route needs no check of its own. `sole()`
  tells its two failures apart the way Laravel does as well: `RecordsNotFoundException` when
  nothing matched, `MultipleRecordsFoundException` when more than one row did. Both are a
  `RuntimeException`, which is what the query builder raised, so code catching that still works.
  `illuminate/database`, where those two live, is required explicitly - Laravel and Lumen both
  install it anyway.
* `MANTICORE_LOG_CHANNEL` - the name of a logging channel of the application is enough to get the
  queries into its log; the channel is a PSR-3 logger and the builder takes one. Nothing is logged
  while the variable is empty, which is the default.
* `Manager::purge()` and `Manager::reconnect()` - a connection is dropped by name and the next
  call for it opens a new one. A process that lives for one request never needs either; a queue
  worker whose handle the server closed overnight does, and so does an Octane process handing the
  application over to the next request. Both reach the pool of the query builder as well, which is
  what `\ManticoreDb::connection()` reads from - and what needed `forgetConnection()` of the
  builder 2.3 to be reachable at all. Neither closes the object: whoever holds one goes on using
  it.
* `Manager::forgetSchemas()` - drops the cached `DESCRIBE` of every connection the manager built,
  where `forgetSchema()` reaches the default one alone. A worker that outlived a migration needs
  the whole lot.
* The benchmarks of the protocols are part of the repository now (`benchmarks/`, kept out of the
  package by `.gitattributes`): five clients over the two protocols of the server, the question
  being what the MySQL protocol of this package really saves against the HTTP of the official one.
* `.gitattributes` - the tests, the benchmarks and the CI config are no longer part of the
  package: they are read on GitHub, not from `vendor/`. Line endings are pinned to LF as well,
  so that a file does not depend on the machine it was committed from.

### Changed

* Requires `avadim/manticore-query-builder-php` 2.3. Two releases of it are behind that: 2.2, where
  non-ASCII text in a raw statement is no longer cut off, a statement the server refused is raised
  as an error instead of coming back as a successful empty result, and `timeout` limits the opening
  of the connection rather than being applied to a handle already connected; and 2.3, released
  alongside this one for `Builder::forgetConnection()` - the pool of the builder had no way to drop
  a single connection, only `init()` to throw away every one of them together with the config and
  the logger. The breaking changes of 2.2 - a value that looks like `:word` is escaped instead of
  being read as a named parameter, and the rows of a join come back as a list - touch nothing this
  package has: neither its classes, nor its tests, nor the examples of the readme use either.
* The range of `phpunit/phpunit` is `^10.5|^11.0|^12.0|^13.0`, and that of `orchestra/testbench`
  `^9.0|^10.0|^11.0` - what the supported Laravel versions bring with them.
* The configuration is merged in `register()` rather than in `boot()`, so that it is there for
  whoever reads it while the providers are still registering. Lumen still `configure()`s first,
  because its call replaces the key instead of merging into it.
* `psr/log` is required as `^1.1|^2.0|^3.0` instead of the unbound `>=1.1`, which would have let a
  future major of the interface in unchecked.

### Fixed

* **A page past `max_matches` is served instead of failing.** A server looks through a thousand
  matches of a result unless told otherwise, and a `LIMIT` past that is an error - so
  `paginate(15)` drew a link to page 68 and answered it with `offset out of bounds`, a 500 in the
  middle of an ordinary pagination. Both `paginate()` and `simplePaginate()` now ask for exactly
  as many matches as the page they were given needs, and only when it needs more than the default;
  an explicit `maxMatches()` of the caller is left alone.
* **A second connection no longer resets the first.** `Factory::make()` called `Builder::init()`
  every time, which is the reconfiguration of the whole static builder: it emptied the pool of
  connections and detached the logger. Since `Manager::connection()` goes through the factory, a
  named connection threw away the connection built before it - leaving whoever held it with an
  object the builder no longer knew - and a logger set through `ManticoreDb::setLogger()` was lost
  on the next call. The builder is now reconfigured only when the config really differs, and a
  logger given to `make()` is attached without touching the rest.

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
* The section on pagination says what a deep page costs and what it asks the server for.
* The section on long-running workers says what to do about the connection itself, not only about
  its cache of schemas.
* The readme covers what was added: the two `OrFail` reads and the exceptions of `sole()`,
  `MANTICORE_LOG_CHANNEL` in the table of the environment variables and in the section on logging,
  and `forgetSchemas()` where the cache of a long-running worker is described.

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

[Unreleased]: https://github.com/aVadim483/manticore-query-builder-laravel/compare/v4.0.0-alpha.1...dev-4.x
[4.0.0-alpha.1]: https://github.com/aVadim483/manticore-query-builder-laravel/compare/v3.0.0...v4.0.0-alpha.1
[3.0.0]: https://github.com/aVadim483/manticore-query-builder-laravel/compare/v2.1.1...v3.0.0
[2.1.1]: https://github.com/aVadim483/manticore-query-builder-laravel/compare/v2.1.0...v2.1.1
[2.1.0]: https://github.com/aVadim483/manticore-query-builder-laravel/compare/v2.0.0...v2.1.0
[2.0.0]: https://github.com/aVadim483/manticore-query-builder-laravel/compare/v1.0.4...v2.0.0
[1.0.4]: https://github.com/aVadim483/manticore-query-builder-laravel/compare/v1.0.3...v1.0.4
[1.0.3]: https://github.com/aVadim483/manticore-query-builder-laravel/compare/v1.0.0...v1.0.3
[1.0.0]: https://github.com/aVadim483/manticore-query-builder-laravel/releases/tag/v1.0.0
