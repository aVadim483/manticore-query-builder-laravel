[English](migration-app-verification.md) | [Русский](ru/migration-app-verification.md)

# Live Laravel application migration: 3.x → 4.x

Subsequent installations of published packages through Composer are recorded in the
[release verification report](release-verification.md). The source-snapshot and alpha.1
checks below remain historical evidence of the 3.x migration.

## Current 4.x recheck — October 26, 2026

The application at [manticore.avadim.ru](https://manticore.avadim.ru/) now runs **dev-4.x**:
a snapshot of the current library working tree, including the uncommitted API audit changes.
This verifies source changes, not a new published release.
Runtime: Laravel 12.70.1, CLI PHP 8.3.6 and PHP-FPM 8.4.27.

A separate application copy was restored from `baseline-v3` with libraries 3.0.0 and 2.6.0.
Its checks were rerun before upgrading that same copy to current 4.x using the
[migration guide](upgrading-3.x-to-4.x.md). Laravel, other dependencies and the original
Manticore table were retained. The copy used separate SQLite, storage and caches. The catalogue
was neither recreated nor reseeded. Public form writes were temporarily paused during comparisons.

| Check | Repeated 3.0.0 run | Current 4.x |
| --- | --- | --- |
| Application scenarios | 32 / 32 | 32 / 32 |
| Browser scenarios in the private copy over SSH | 11 / 11 | 11 / 11 |
| Native checks | Not applicable | 6 / 6 |
| New methods and write guards | Not applicable | 8 / 8 |
| Matching comparable results | Original results confirmed | 31 / 31 |

The public application was then upgraded to **the same snapshot**. It passed another
32 application, 6 native, 8 new API checks and 11 browser scenarios through its actual PHP-FPM.
The Row/Generator → stdClass/LazyCollection contract is checked separately from result comparisons.

The eight new checks cover combined `whereFullText()`/JSON paths, nested MATCH/JSON bindings,
the `hybridSearch/Get/First` family, `updateOrInsert()`/`replaceOrInsert()`, JSON membership/length,
upsert field preservation and caller-transaction rollback, rejected write clauses preserving data,
and after-query callbacks running once. The application's JSON filter now uses
`where('details->color', 'red')` instead of raw SQL.

Composer installed the snapshot through a path repository with package mirroring; vendor files
were not patched manually. SHA-256 of the manifest containing `composer.json` and all `src` files:

```text
a124d80a8772719db21db3dee6c2d0f1d1df1317dc16a1859906fb264796f3f7
```

All **2105** original documents and their fingerprint were preserved:
`77b7e55f17dff88e5ecca2e674bcce3bce746a2a18a6ba1ee9eac79ace463f17`.
The original 3.x report is unchanged; the alpha.1 installation is retained for rollback.
The site and forms are live, and the temporary rehearsal web server has been stopped.
The [public JSON evidence](https://manticore.avadim.ru/?report=1) contains current results,
the public browser run, extra checks (`current`) and exact source provenance (`source`).

## First verification: 3.0.0 → 4.0.0-alpha.1

The application at [manticore.avadim.ru](https://manticore.avadim.ru/) was first deployed with
**3.0.0**, exercised in the browser and CLI, then upgraded in place to the published
**4.0.0-alpha.1** using the [migration guide](upgrading-3.x-to-4.x.md). It now runs 4.x.
The installed packages were not patched. This is a real Laravel application on the hosting
server, not a Testbench fixture. Its data is synthetic; Scout is not installed or verified.

The [checks page](https://manticore.avadim.ru/?view=checks) and
[JSON evidence](https://manticore.avadim.ru/?report=1) expose the before/after results.
Public forms let a session create, update and remove its own temporary demo documents.
The original catalogue is protected from those forms.

## Environment and preserved state

| Component | Before | After |
| --- | --- | --- |
| Laravel | 12.70.1 | 12.70.1 |
| PHP | 8.3.6 | 8.3.6 |
| Laravel package | 3.0.0 | 4.0.0-alpha.1 |
| Standalone PHP builder | 2.6.0 | Removed |
| Manticore | 29.9.0 | Same server and table |
| Buddy / Columnar/KNN | 4.4.3 / 14.2.1 | Unchanged |
| Primary migration repository / queue | Private SQLite | Same SQLite database |
| Search documents | 2105 | Same documents, without reseeding |

The application's CLI loads an isolated PDO SQLite module because the host's default PHP
does not enable it. Web requests use Manticore, file sessions and file cache. Application
code, vendor, configuration, SQLite and logs reside outside the web root. Composer versions
were resolved locally and the lock file transferred because server-side Packagist access
was intermittent; Composer installed the exact locked releases on the server.

The pre-upgrade application is preserved in its own Git repository as `baseline-v3`
(commit `fb40b3a`); the migrated source is tagged `migrated-v4-alpha1`.
The baseline report was retained unchanged. All 31 comparable application observations match
their original SHA-256 digests after explicit DTO conversion. The remaining scenario verifies
the expected Row/Generator → stdClass/LazyCollection contract change.

Before and after the tests, the original dataset fingerprint was:

```text
77b7e55f17dff88e5ecca2e674bcce3bce746a2a18a6ba1ee9eac79ace463f17
```

## Results

| Verification | 3.0.0 | 4.0.0-alpha.1 |
| --- | --- | --- |
| Application scenarios | 32 / 32 | 32 / 32 |
| Browser scenarios | 11 / 11 | 11 / 11 |
| Additional native checks | Not applicable | 6 / 6 |
| Base documents preserved | Yes | Yes |

The 32 application scenarios cover projection, MATCH/brand filters, facets/highlighting,
structured values, nested conditions, JSON paths, MVA ANY/ALL, MVA64 precision, geo, KNN,
weights/rankers, aggregates, keyed pluck, deep full/simple pagination, chunk/chunkById/each/
lazy/old cursor walks, bound raw SQL, KEYWORDS/SUGGEST/QSUGGEST/AUTOCOMPLETE/SNIPPETS,
the insert-ID-update-replace-delete lifecycle, commit/rollback, schema alterations/rename,
named connections/purge/reconnect, logging, not-found/result contracts and a real database
queue worker. Scratch tables and probe documents are separate from the base catalogue.

The 11 browser scenarios cover the real PHP-FPM application: homepage/results, MATCH with
brand/facets/highlights, a page beyond document 1000, KNN, create/update/delete forms, session
ownership, CSRF, inaccessible private files and mobile layout without horizontal overflow.
Screenshots were captured at desktop and mobile sizes before and after migration.

The six additional native checks verify removal of the old dependency, a single SELECT
cursor over all 2105 IDs, cursor pagination over 29 pages without lost/repeated IDs, exact
grouped totals, read/write QueryException failures and fresh migration/rollback. The last
check uses a separate SQLite file and Manticore prefix: four migrations apply and roll back,
including the actual application's new Manticore Blueprint schema. The live table is not dropped.

## Application changes that mattered

* Moved named connection configuration to `database.connections`, retained the physical prefix,
  removed `?` markers, the old configuration and facade/manager calls.
* Replaced ID arguments with document IDs or WHERE conditions, `findOrFail()` with
  `where()->firstOrFail()`, and caught singular `RecordNotFoundException`.
* Used arrays for selected columns, explicit `withScore()`, `search()` metadata/facets,
  `withHighlight()` and connection-level CALL helpers. KNN argument order changed.
* Preserved the application's JSON contract in its DTO: score field placement, the old `_count`
  facet alias and integer CALL fields. No compatibility classes were added to the package.
* Used bound `whereRaw('details.color = ?', ['red'])` for the JSON path: generic Laravel dot
  qualification otherwise prefixed `details` as a table name.
* Mapped the old `cursor(700)` to `lazy(700)` to retain its paged semantics; added ordering.
  Separately checked the new one-SELECT `cursor()` with an explicit 2105-row limit.
* Used Schema Builder for supported DDL. Old MODIFY/rename/settings calls used explicit,
  server-verified ALTER statements, as allowed by the guide.
* Moved logging to query events, used Laravel purge/reconnect, rebuilt configuration/view
  caches and issued `queue:restart`. Test jobs use isolated queues; the verification worker
  is forced when the application is temporarily in maintenance mode.

Manticore's in-place UPDATE does **not** participate in rollback. The baseline check exposed
this pre-existing server behavior; rollback probes use INSERT, and transaction examples now
use full-document REPLACE. See the [Manticore transaction documentation](https://manual.manticoresearch.com/Data_creation_and_modification/Transactions).

This verifies this application's migration on the listed versions. It does not establish
Scout adapter, Eloquent model persistence, Lumen or all other applications' compatibility.
