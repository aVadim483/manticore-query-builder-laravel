# Performance and worker verification

[Русский](ru/performance.md)

A separate [3.0.0 vs 4.0.0 A/B report](../benchmarks/compare-versions/results-2026-10-26.md)
covers the same 20,000 documents and dependencies across API, HTTP and queue scenarios.
The measurements below concern 4.x alone.

Builder INSERT/REPLACE now render finite scalar PHP floats during SQL compilation,
after schema encoding. JSON numbers remain JSON-bound strings; strings and other
scalar bindings still use PDO parameters. This also applies to `insertGetId()` and
`replaceGetId()`, and writes delegated through these methods. Query events and query
logs expose the float literal in SQL, without a corresponding float binding.
UPDATE, WHERE and raw SQL continue to use `NumericBindings::prepare()`.
See the [bulk-write optimization comparison](../benchmarks/profile-writes/optimized-results-2026-10-26.md)
for released 3.0.0/4.0.0 and optimized source measurements.
The [SQL pipeline analysis](../benchmarks/sql-pipeline/results-2026-10-26.md) separately
measures preparation, actual PDO calls and replayed SQL, with six experimental optimization
hypotheses and compatibility checks. That report describes isolated experimental snapshots;
the implemented changes are described below.

Multi-row INSERT/REPLACE now have a specialized preparation path for the stock
`ManticoreQueryBuilder` and `ManticoreGrammar`. Schema encoding still runs through
`encodeWriteRows()`; SQL fragments and dense bindings are then collected together,
using one sorted column list and rendering finite scalar floats directly. Repeated
column order reuses validation of column names. Strings remain PDO parameters.
Builder/Grammar subclasses, custom or nested Expressions, and Grammar changes during
schema loading or callbacks registered by schema listeners retain the generic compilation path.
`cleanBindings()` uses one loop
and still delegates scalar conversion to Laravel's `castBinding()`. Single-row and
GetId methods retain their existing preparation path.

See the [implemented bulk-write comparison](../benchmarks/sql-pipeline/batch-writes.md).

The earlier 4.x measurements below predate the specialized bulk preparation. They were
recorded on October 26, 2026 with PHP CLI 8.3.6, Laravel 12.70.1,
Manticore 29.9.0 with Columnar/KNN 14.2.1. One client used local TCP on the test server;
these are measurements of this fixture, not throughput or latency guarantees.

The scratch dataset contained 5,000 initial documents and 5,500 after mixed upsert, with
128-dimensional L2 vectors, stored TEXT and JSON containing a 128-byte payload. HNSW was
built with `FLUSH RAMCHUNK` before hybrid measurements. Query logging was disabled.

| Operation | Time | Additional peak PHP memory | Observed SQL statements |
| --- | --- | --- | --- |
| INSERT 5,000, batches of 500 | 1.90 s | 8.77 MiB | 11 including schema lookup |
| Upsert 100 existing rows | 0.30 s | 0.40 MiB | 200 |
| Upsert 1,000 existing rows | 3.03 s | 2.76 MiB | 2,000 |
| Upsert 5,000 existing rows | 12.99 s | 13.90 MiB | 10,000 |
| Upsert 500 existing + 500 new | 3.18 s | 6.88 MiB | 2,000 |
| `get()`, all 5,500 documents | 0.99 s | 21.25 MiB | 1 |
| `cursor()`, all 5,500 documents | 1.01 s | 8.97 MiB | 1 |
| `lazy(250)`, all 5,500 documents | 1.36 s | 1.95 MiB | 23 |
| `chunkById(250)`, all 5,500 documents | 1.29 s | 0.99 MiB | 23 |

Upsert is emulated: each row requires a lookup and an INSERT/REPLACE, with all replacement
documents prepared before writes. This has linear query count and batch-dependent memory;
it is not a single bulk SQL upsert. Use explicit batches, for example 100–1,000 rows,
according to document size and acceptable transaction duration. Separate calls use separate
transactions unless the application supplies an outer transaction. Network latency adds to
every lookup/write. Existing fields outside the update list remained intact.

Cursor avoids retaining a complete collection, but a PDO driver may buffer its result.
For lower measured peak memory, use bounded `lazy()`/`chunkById()` batches. Chunk reads
verified the row count and ID sum, including the final partial page.

| Hybrid candidates per branch | Median / observed p95, 10 runs | Maximum additional peak memory |
| --- | --- | --- |
| 50 | 43.00 / 45.30 ms | 0.57 MiB |
| 200 | 129.52 / 133.94 ms | 1.93 MiB |
| 1,000 | 365.58 / 421.26 ms | 8.85 MiB |

Hybrid results retained the shared attribute filter. Larger windows increased both memory
and execution time; they do not establish a corpus-wide union total.

After 100 warm-up operations, 1,000 mixed JSON/full-text, facet and hybrid operations
completed in 16.63 s. Retained PHP memory grew by 4,544 bytes including measurement records;
allocated memory grew by 0. A separate real database queue worker processed 120 jobs,
15 operations per job, without restarting. Between jobs 20 and 120 both retained and allocated
memory growth were 0. Jobs included JSON/full-text reads, hybrid search and upsert.
The queue drained, the final write was verified, and the application's original 2,105 documents
and fingerprint were preserved. These finite runs found no continuing memory growth.

## Repeating the measurements

Use a clone of this repository and an autoloader from a Laravel application, which supplies
the event dispatcher used for SQL counters. The native benchmark creates and drops only its
random scratch table. An example on the test server is:

```sh
MANTICORE_TEST_HOST=127.0.0.1 MANTICORE_TEST_PORT=19306 \
MANTICORE_BENCHMARK_REPORT=/tmp/driver-benchmark.json \
php -d memory_limit=512M tests/benchmarks/native-driver.php /path/to/application/vendor/autoload.php
```

`MANTICORE_BENCHMARK_DIMS` accepts 3, 128 (default) or 768. For the worker test, place
`tests/benchmarks/fixtures/DriverSoakProbe.php` in the test application's `app/Jobs/` directory,
then run `php tests/benchmarks/laravel-worker.php /path/to/application`. It uses the app's
`manticore` connection and a private database queue, with random table/queue names. The
application needs its normal database queue tables, the `bin/php` CLI wrapper (or an equivalent
executable), and the migration verification application's `Catalog` service for preservation
checks. Full JSON measurements are retained under the application's `storage/app/`.
