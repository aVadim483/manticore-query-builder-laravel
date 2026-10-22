# Hybrid search, server capabilities and RT index replacement

[Русский](ru/hybrid-and-index-replacement.md)

Added after alpha.1 for **4.0.0-alpha.2**. No Scout installation is required.
See [design contracts](parent-search-contracts.md) and [extended Schema](schema-alter.md).

## Independent text and vector search

```php
use Illuminate\Support\Facades\DB;

$db = DB::connection('manticore');
$result = $db->table('products')->where('stock', '>', 0)
    ->hybrid('phone', 'embedding', [1, 0, 0], [
        'text_weight' => 1, 'vector_weight' => 2, 'rank_constant' => 60,
        'text_candidates' => 100, 'vector_candidates' => 100,
        'knn_options' => ['ef' => 100],
    ])->search();

foreach ($result->hits() as $hit) {
    $id = $hit->id;
    $document = $hit->document;
    $score = $hit->score;
    // textRank, vectorRank, textScore and distance are nullable.
}
$documents = $result->rows();
$page = $result->paginateWindow(perPage: 20, page: 2);
$totals = $result->totals();
```

Both searches use the same write PDO and common attribute filters. The text branch uses
WEIGHT descending/ID ascending; the vector branch uses distance ascending/ID ascending.
Each ID scores `text_weight/(rank_constant+text_rank) + vector_weight/(rank_constant+vector_rank)`.
Ranks start at 1. A missing branch contributes zero. Duplicate IDs merge; text-branch
document data wins. Equal fused scores sort by unsigned numeric ID, retaining bigint precision.
Reserved internal aliases `__hybrid_text_score` and `__hybrid_distance` cannot be table columns.
Source scores/ranks are carried by `HybridHit`, not written into document attributes.

Defaults: weights 1/1, constant 60, 100 candidates per branch, escaped literal MATCH,
no extra KNN options. Weights are finite numbers in 0..1000000 with at least one positive;
zero disables a branch. Constant is an integer in 0..1000000; candidate bounds are 1..10000.
An empty text string skips the text branch. Set `escape => false` only for deliberate
extended MATCH expressions. KNN uses existing validated `knn_options`; HNSW dimensions and
metric come from SHOW CREATE TABLE. Cosine vectors must be nonzero.

The common query must have a simple logical table name and AND attribute predicates
(including nested AND/MVA). Projection, raw predicates/expressions, MATCH/KNN, facets,
custom order/limits/offsets, joins, grouping, callbacks and other query shapes are rejected.
Configure `fieldWeights()`/`ranker()` on the common query when needed. The source query is
not mutated. Existing `knn()->match()` continues to mean intersection, not this union.

`HybridResult` differs from `SearchResult`: `windowTotal()` is the exact deduplicated number
of returned candidates; `totalFound()` is always null. `totals()` labels window accuracy
`exact`, global `unavailable`, and branch totals `unknown` (or `unavailable` for an absent
branch). Branch `Meta` objects are raw server evidence: max_matches/cutoffs/approximation
can affect their counts, and RAM KNN may report more matches than the returned top-k.
Do not add branch totals or present the window count as the full result count.

Metadata is captured before the next query or query listener. A branch failure propagates;
there is no successful partial result or automatic fallback. Branches are sequential and
do not share a transactional read snapshot. Reusing a materialized result pages the same
ordered window without SQL; changing the data or rerunning search can change pages.
Candidate retrieval truncation, approximate KNN and attribute prefiltering can shorten or
change the window. Deep pages require a larger explicitly bounded window and more work;
there is no unlimited cursor. Large vectors/documents increase memory and network cost.

## Explicit capability evidence

```php
$info = $db->capabilities(); // lazy SHOW STATUS, connection-local snapshot
$version = $info->version;
$installed = $info->advertises('knn'); // installation evidence, not a successful probe
$evidence = $db->probeCapability('knn', 'products', [
    'column' => 'embedding', 'vector' => [1, 0, 0],
]);
$works = $evidence->supported;
$cause = $evidence->cause; // original QueryException or UnsupportedFeatureException, if any
$db->forgetCapabilities();
```

Probes only read a caller-selected table. Supported names: `knn`, `fuzzy`, `autocomplete`,
`keywords`, `suggest`, `qsuggest`. KNN accepts column/vector; other probes accept `text`.
A successful probe verifies only those parameters/table on that connection, even if the
table is empty; it does not measure recall or prove other options/plugins work. Missing
advertised Buddy marks fuzzy/autocomplete unavailable without issuing the operation.
Explicit dependency errors retain their original exception in negative evidence; unrelated
SQL, missing-table and network errors propagate unchanged. Loaded modules never automatically
become positive operational evidence. Probe results have a 128-entry FIFO connection cache,
cleared by schema invalidation, disconnect/reconnect/purge or `forgetCapabilities()`.
Refresh explicitly after external component/configuration changes. No registration-time SQL
or mandatory capability checks are added to existing normal queries.

## Maintenance-only RT replacement

```php
use Illuminate\Support\Facades\Schema;

$schema = Schema::connection('manticore');
$definition = $schema->getCreateStatement('products');
$schema->createLike('products_next', 'products'); // schema only, no documents

// Application: import, replay changes, validate staging, lock, stop and drain traffic/workers.
$replacement = $schema->replacement('products', 'products_next', 'products_previous');
$before = $replacement->inspect();
$after = $replacement->switch(maintenanceConfirmed: true);

// Under the same maintenance/locking policy, rollback retains the newer table at products_next.
$restored = $replacement->rollback(maintenanceConfirmed: true);
```

Three distinct logical names share one connection prefix. Only local RT tables in RT mode
on one non-clustered connection are supported; distributed tables, clusters and read/write
splitting are rejected. Buddy is required for rename; advertised Buddy does not guarantee
the actual rename succeeds. Database errors preserve `QueryException`.

| Layout (active / staged / backup exists) | Status | Forward | Rollback |
| --- | --- | --- | --- |
| yes / yes / no | `prepared` | active → backup, staged → active | already restored |
| no / yes / yes | `gap` | staged → active | backup → active |
| yes / no / yes | `switched` | already switched | active → staged, backup → active |
| any other | `conflict` | refuses | refuses |

This is **not atomic and not zero downtime**. Separate-process readers on minimum/latest
observed missing-table errors during the gap, and old/new results before/after it. In-flight
requests have no promised snapshot isolation across rename; drain them before maintenance.
Concurrent writes, imports, queues, double-run exclusion and changes after activation are
the application's responsibility. The boolean acknowledges those preconditions; it does
not acquire a lock. Before rollback, reconcile writes made to the newer generation.

No old table is automatically deleted on success or error. After a failure, inspect server
state and reconstruct the replacement with the same names; explicitly choose switch or
rollback for `gap`. Layout alone is not proof of table ownership or import completeness:
persist job/generation identity, verify contents, and resolve conflicts in the application.
Both injected interruptions and a real second-rename SQL conflict were tested; originals
remain available for explicit recovery. Other connections retain independent schema caches.

CREATE LIKE preserves the server-side schema, HNSW declarations and supported table settings
on the same server. It does not transfer documents, external files, dictionaries, plugins,
embedding credentials/models to another server, cluster membership or distributed topology.
`getCreateStatement()` is server DDL, not a portable or sanitized export; handle any embedded
configuration privately. Validate the staging schema and dependencies before loading data.
Distributed local-target ALTER was accepted during research but has no atomicity/reader/write
guarantee in this release, so it is not exposed as a safe switch primitive.

## Notes for alpha.1 and Scout consumers

Existing methods retain their contracts. New fusion returns `HybridResult`, not `SearchResult`;
consumers must map hits/documents and window pagination explicitly. No Eloquent model import,
embedding generation, UUID mapping, queue coordination or Scout engine is included. Scout must
depend on the exact tested parent alpha and separately test its integration after publication.
