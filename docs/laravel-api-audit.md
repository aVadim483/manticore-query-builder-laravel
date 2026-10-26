# Laravel Query Builder profile and 4.0 API contracts

[Русский](ru/laravel-api-audit.md)

The [machine-readable catalog](laravel-query-builder-api.json) covers 299 public Laravel
11–13 and driver methods, including infrastructure and explicitly unsupported operations.
A catalog entry does not imply availability in every Laravel patch. Static Macroable
registration APIs remain Laravel facilities.

## Support profile

| Family | Status and scope |
| --- | --- |
| select/addSelect/from, ordering, limit/offset | Supported with native Manticore syntax; SELECT table aliases are permitted |
| Literal where/IN/integer-IN/BETWEEN, nested AND/OR, any/all/none | Supported; numeric time attributes use Unix timestamps |
| whereNull/whereNotNull | JSON paths and special ID checks; other attributes depend on native null support |
| get/first/find/sole/value/pluck/implode and existence helpers | Adapted PDO results, casts and Manticore result-window limits |
| Aggregates, GROUP BY, HAVING | No DISTINCT; one Basic/Between/Raw HAVING. Nested HAVING and aggregate helpers with HAVING are rejected |
| Pagination, chunk/each/lazy, cursor | Supported subject to documented count, keyset, cursor and result-window constraints |
| Full text, JSON, search, KNN, geo, facets, hybrid | Adapted APIs with the contracts below |
| INSERT/REPLACE/UPDATE/DELETE/TRUNCATE | Explicit query-shape and payload validation; incompatible clauses cannot disappear silently |
| Conditional writes and upsert | Read-before-write operations with the guarantees below |
| Raw SQL helpers and expressions | Explicit native SQL escape hatches; server syntax errors remain QueryException |
| Callbacks, composition, macros, debugging | Laravel facilities; application callbacks/macros determine their own behavior |
| Bindings, introspection, cloning | Compiled getters include MATCH/JSON parameters. Manually merging deferred search bindings is rejected; use nested conditions |

The following families raise LogicException at configuration or before SQL execution:

* JOIN/lateral JOIN, UNION, DISTINCT, locks, group limits and Laravel index hints.
* LIKE helpers and LIKE/ILIKE/REGEXP in ordinary where, binary/null-safe comparisons.
* Column comparisons, column/value BETWEEN, row-value comparisons, scalar/IN/EXISTS
  subqueries, selectSub/fromSub. Native Manticore sub-selects use different syntax.
* Laravel calendar/relative-date filters. Use explicit Unix timestamp ranges or native
  SELECT expressions filtered through aliases.
* inOrderOf, inRandomOrder, explain and timeout(seconds). Native alternatives include
  `orderByRaw('RAND()')->option('rand_seed', 42)` and `option('max_query_time', $milliseconds)`.
  The latter is a server search limit, not a PDO network timeout. timeout(null) clears the setting.
* Increment/decrement/Each methods: native UPDATE does not evaluate these expressions.
* insertUsing, insertOrIgnore variants, updateFrom and Laravel vector-distance APIs.
  Use knn/hybridSearch; string arguments do not trigger automatic embedding requests.

Known unavailable where-methods on older Laravel patches are rejected instead of becoming
dynamic filters on unrelated column names. Ordinary dynamicWhere and explicit user macros
remain available.

## Write rules

UPDATE/DELETE reject projections, aggregates, DISTINCT, GROUP BY, HAVING, joins/unions,
ordering, windows, locks, hints, timeouts and computed search expressions. INSERT/REPLACE/
TRUNCATE additionally reject WHERE. Writes require one plain physical table without an alias.

Every INSERT/REPLACE batch row must have identical column names; key order may differ.
Malformed batches raise InvalidArgumentException before writes. JSON-path assignments in
value arrays are rejected: write the full JSON attribute. IDs cannot be updated. TEXT and
columnar attributes require REPLACE, including when casts are disabled.

Empty insert/replace inputs return true; empty upsert returns 0 without validating its other
arguments or invoking before-query callbacks. Empty update, or empty/batch input to ID-returning
writes, raises InvalidArgumentException.

## Frozen new contracts

The [signature snapshot](query-builder-contracts.json) fixes parameter names (including named
arguments), types, defaults and result contracts for 19 methods across supported Laravel majors.
Results below describe normal execution without an application callback replacing the result.

| Method | Result | Contract |
| --- | --- | --- |
| whereFullText(columns, value, options = [], boolean = and) | Same builder | A field or non-empty field list and non-empty text. Only boolean escape is accepted; default true. Unknown option keys appear in the exception |
| orWhereFullText(columns, value, options = []) | Same builder | Text OR groups; text/attribute OR and negated text groups are rejected |
| whereJsonContains(column, value, boolean = and, not = false) | Same builder | All candidates occur in an array; negation means at least one is absent |
| whereJsonOverlaps(column, value, boolean = and, not = false) | Same builder | At least one candidate occurs; negation means none occur |
| whereJsonContainsKey(column, boolean = and, not = false) | Same builder | The path resolves to a non-null value; JSON null and missing are indistinguishable |
| whereJsonLength(column, operator, value = null, boolean = and) | Same builder | Non-negative integer length; two arguments imply equality. Missing/JSON null never match, including length zero |
| Negative JSON methods and all orWhereJson variants | Same builder | Delegate to the corresponding predicate with negation/OR, including aliases missing in early Laravel 11 |
| updateOrInsert(array attributes, array\|callable values = []) | bool | INSERT merged attributes/values or UPDATE supplied attributes by the matched ID; an empty existing update returns true |
| replaceOrInsert(array attributes, array\|callable values = []) | bool | INSERT or full replacement under the matched ID; omitted fields reset even with empty values |
| upsert(array values, uniqueBy, update = null) | int | Processed input count. Existing documents preserve non-updated fields through REPLACE; new rows use INSERT. null updates all supplied columns; [] is INSERT only |

JSON membership accepts a scalar or a list of strings, booleans and finite numbers. Null,
objects, nested candidates and empty contains lists are rejected. Empty overlaps never
matches; its negation always matches. Comparison/coercion and LENGTH follow Manticore rather
than strict MySQL/PostgreSQL JSON typing. See [JSON](laravel-driver.md#json-filters) and
[full-text examples](laravel-driver.md#full-text-conditions).

Repeated whereFullText calls form one MATCH. Native match requires one top-level AND
expression and cannot mix with whereFullText; use the latter for nested text groups.
Generated JSON aliases are hidden from results. fetchUsing applies to get/cursor where
available; search, hybrid and conditional-write helpers require default object mode and
reject overrides, including those added by beforeQuery.

Write callbacks run once with exists and must return an associative row. Lookups must be
unambiguous non-null scalar physical attributes; ID 0 is an auto-ID marker, not a lookup key.
Existing IDs cannot change. Upsert requires identical row columns, all lookup/update columns,
and no duplicate lookup tuples/resolved IDs. Unstored TEXT fields must be supplied for update
because their previous values cannot be recovered.

Upsert prepares all documents before writing and wraps INSERT/REPLACE in a transaction.
It leaves successful outer transactions uncommitted; execution errors abort the entire
transaction without savepoints. Pending writes are invisible even to the current transaction.
Lookups have no isolation: concurrent inserts can conflict and replacements can overwrite
intervening changes. COMMIT failures never trigger replay. UPDATE in updateOrInsert is not
rolled back. See [write semantics](laravel-driver.md#update-or-insert-replace-or-insert-and-upsert).

## Errors and verification

| Exception | Meaning |
| --- | --- |
| InvalidArgumentException | Invalid values, paths, options, payloads, keys or callback results |
| LogicException | Unsupported API/combination, ambiguous lookup or an unrecoverable stored field |
| TypeError | Violation of declared PHP argument types |
| Illuminate Database QueryException | Server/connection failure, INSERT conflict, invalid explicit SQL or schema mismatch |
| TransactionRollbackException | Both operation and rollback failed; both causes are retained |

Schema-dependent validation can require DESCRIBE. Explicit incompatible constructs fail
before execution; raw SQL remains caller-supplied. User callback exceptions propagate.

DatabaseDriverApiAuditTest checks catalog completeness, signatures and dangerous combinations.
Its integration counterpart verifies actual data, columnar writes, filtered TRUNCATE rejection
and batch alignment. `php tests/check-api-contracts.php [autoload]` also runs without Testbench.
CI installs minimum Illuminate 11.0.0/12.0.0/13.0.0 components without dev dependencies and runs
live smoke checks. Full Eloquent and separate physical read/write-server compatibility remain
independent areas of verification.
