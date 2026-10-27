# Performance

[Русский](ru/performance.md)

These are measurements of particular datasets and one client on local TCP, not latency
or concurrent-throughput guarantees for other applications.

## INSERT/REPLACE compared with 3.x

Measured on October 27, 2026: published 3.0.0 with standalone PHP builder 2.6.0 versus
4.x source commit 52a1f89, after bulk-write and column-encoding optimizations.
PHP 8.3.6, Laravel 12.70.1, Manticore 29.9.0 and 77 shared dependencies were identical.
Query logging and Xdebug were disabled.

Each scenario has 60 measurements after five warm-ups, with alternating version order.
Primary totals use ordinary PDO/PDOStatement without tracing wrappers. Connections and
schema are warm; data creation, table reset, replacement seeding and result validation
are outside the timer. All written documents matched between versions.

Seven fields, including a float price, JSON with a 128-byte payload and a two-element MVA:

| Operation, documents | 3.0.0, ms | 4.x at 52a1f89, ms | Difference from 3.x |
| --- | ---: | ---: | ---: |
| INSERT, 1 | 1.290 | 1.068 | -17.22% |
| REPLACE, 1 | 1.185 | 0.969 | -18.23% |
| INSERT, 100 | 7.864 | 8.121 | +3.27% |
| REPLACE, 100 | 7.901 | 8.163 | +3.31% |
| INSERT, 500 | 33.379 | 36.091 | +8.13% |
| REPLACE, 500 | 33.201 | 36.004 | +8.44% |
| INSERT, 1000 | 64.044 | 69.299 | +8.21% |
| REPLACE, 1000 | 63.989 | 65.241 | +1.96% |

For five scalar fields without JSON/MVA, 500-document INSERT took 21.901 / 23.516 ms
in 3.x / 4.x (+7.37%); REPLACE took 21.743 / 23.259 ms (+6.97%).
Thus 4.x took about 7–8% longer for these 500-document batches.
Single-row overhead differs: 3.x also executes SELECT LAST_INSERT_ID(), while ordinary
4.x insert/replace executes only the write.

A separate traced series of real 500-document writes with JSON/MVA measured preparation
before PDO at about 6.3–6.5 ms in both versions. Binding and execution accounted for most
of the remaining difference. Traced timings are not substituted for the primary totals.
Observed variation, particularly on REPLACE, means percentages should not be generalized.

The [recorded measurements and source manifests](https://github.com/aVadim483/manticore-query-builder-laravel/blob/52a1f891db84045c187f5a55bbc5e4ba13be6976/benchmarks/sql-pipeline/current-v3.md) remain in Git history.
The separate [published 3.0.0/4.0.0 comparison](https://github.com/aVadim483/manticore-query-builder-laravel/tree/main/benchmarks/compare-versions) covers API, HTTP and queue
scenarios; its 4.0.0 numbers predate the optimizations above.

## Earlier 4.x workloads: upsert, reads and hybrid

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
The queue drained and the final write was verified. These finite runs found no continuing memory growth.

## Repeating the measurements

Clone the repository: tests/ and benchmarks/ are excluded from Composer dist archives.
See the [benchmark instructions](https://github.com/aVadim483/manticore-query-builder-laravel/blob/main/benchmarks/README.md).

Use a Laravel application's autoloader to supply the event dispatcher for SQL counters.
The native benchmark creates and drops only its random scratch table:

```sh
MANTICORE_TEST_HOST=127.0.0.1 MANTICORE_TEST_PORT=9306 \
MANTICORE_BENCHMARK_REPORT=/tmp/driver-benchmark.json \
php -d memory_limit=512M tests/benchmarks/native-driver.php /path/to/application/vendor/autoload.php
```

`MANTICORE_BENCHMARK_DIMS` accepts 3, 128 (default) or 768. For the worker test, place
`tests/benchmarks/fixtures/DriverSoakProbe.php` in the test application's `app/Jobs/` directory,
then run `php tests/benchmarks/laravel-worker.php /path/to/application`. It uses the app's
`manticore` connection and a private database queue, with random table/queue names. The
application needs its normal database queue tables, the `bin/php` CLI wrapper (or an equivalent
executable), and the migration verification application's `Catalog` service for preservation
checks. Adapt this application-specific fixture to your own test application before use.
