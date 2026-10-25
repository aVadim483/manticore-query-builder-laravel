# Server capabilities and RT index replacement

[Русский](ru/hybrid-and-index-replacement.md)

Diagnostics and replacement APIs were added in alpha.2 and work without Scout.
See also [Schema Builder](schema-alter.md) and [parent driver contracts](parent-search-contracts.md).

## Independent text and vector search

See the [dedicated hybrid search guide](hybrid-search.md): a minimal call, the first document,
filters, weights, metadata, pagination and advanced options. Alpha.3 uses
hybridSearch()/hybridGet()/hybridFirst(); migration instructions from alpha.2 are included there.

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
