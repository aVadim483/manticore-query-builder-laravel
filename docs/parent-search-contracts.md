# Parent driver contracts for Scout consumers

Introduced in 4.0.0-alpha.2 after alpha.1; implemented in the parent driver without Scout.

## B1: bounded weighted reciprocal rank fusion

`$query->hybrid($text, $column, $vector, $options)->search()` clones a common attribute
query and executes independent text and KNN searches. Existing `knn()->match()` remains
intersection. Default candidate limits are 100 per branch, with weights 1/1 and RRF
constant 60. Bounds, weights and vectors are validated before either search.
Rank starts at 1; an absent branch contributes zero. Ties use ascending unsigned document
ID, including IDs beyond PHP integer precision. The text branch supplies document data
when both branches contain an ID. Each hit retains original ranks/scores and distance.

`HybridResult` is separate from `SearchResult`. It contains branch metadata snapshots,
the exact deduplicated window size, and no global union total. Branch totals are server
metadata, not proof of an exact corpus-wide count. `paginateWindow()` pages the materialized
window, never reruns SQL. A fresh search may change pages if data changes. No unlimited
cursor/deep-page claim is made. Both enabled branches execute or an exception propagates;
no partial success is returned on failure. Attribute filters are cloned into both branches.

Initial scope excludes pre-existing search expressions, MATCH/KNN, raw predicates, custom
ordering, projections, facets, grouping, callbacks, offsets and limits on the common query.
These are rejected rather than silently changed. Text is escaped as literal MATCH input
unless explicitly opted into extended MATCH syntax. KNN candidates are bounded after
filters; approximate retrieval and insufficient neighbours can shorten the vector window.

## B2: RT replacement with an observable maintenance gap

Checks on Manticore 28.6.6/Buddy 4.2.0 and 29.9.0/Buddy 4.4.3 confirmed CREATE LIKE,
RT rename and distributed local-target ALTER. This does not establish an atomic alias/swap,
in-flight request isolation or safe concurrent writes. Distributed/cluster replacement is
outside the supported mechanism. No zero-downtime guarantee is made.

Supported replacement uses three distinct names on one connection: active, staged, backup.
The application must stop/drain reads and writes and exclusively lock this operation.
Forward steps: active -> backup, staged -> active. Rollback: active -> staged,
backup -> active. Existing tables are never dropped automatically. State is inspected from
the server so a new process with the same names can resume a gap. Unknown/conflicting
layouts fail explicitly. No lock or persistent state store is implied by a PHP object.
The caller supplies explicit maintenance authorization on every mutating invocation.

`createLike()` prepares an empty staging table on the same server without parsing and
reconstructing DDL. `getCreateStatement()`, existing column introspection and settings
inspection describe the source. CREATE LIKE does not transfer data or guarantee portability
of external resources (dictionaries, files, plugins, embedding credentials/models).
Cross-server restore, distributed definitions and cluster membership are not cloned.

## B3: evidence, not inferred operational support

Connection-local, lazy server information reports version and advertised modules. A loaded
module or Buddy is not a successful probe. Read-only probes of a caller-selected test table
verify individual operations. A recognized missing dependency yields `unsupported` with the
original QueryException retained; unrelated query failures propagate unchanged. Reconnect,
disconnect and purge invalidate cached evidence. Registration performs no network request.
KNN validates the declared HNSW dimensions and metric before executing its probe or fusion.

References: [schema/settings](https://manual.manticoresearch.com/Updating_table_schema_and_settings),
[distributed tables](https://manual.manticoresearch.com/Creating_a_table/Creating_a_distributed_table/Creating_a_local_distributed_table).
