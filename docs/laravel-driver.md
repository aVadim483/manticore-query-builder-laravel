# Native Laravel Database driver

The `dev-4.x` branch includes an experimental driver built on Laravel's own Query Builder.
It uses PDO directly; its implementation does not call the standalone PHP query builder.
The 3.x wrapper API and its standalone builder dependency have been removed.

For configuration and API replacements in existing applications, see the
[3.x migration guide](upgrading-3.x-to-4.x.md).

## Configuration

The package service provider registers the `manticore` driver automatically. Add a named
connection to the `connections` array in your application's `config/database.php`:

```php
'manticore' => [
    'driver' => 'manticore',
    'host' => env('MANTICORE_HOST', '127.0.0.1'),
    'port' => env('MANTICORE_PORT', 9306),
    'username' => env('MANTICORE_USER'),
    'password' => env('MANTICORE_PASS'),
    'timeout' => env('MANTICORE_TIMEOUT', 5),
    'prefix' => '',
    'options' => [], // PDO options
],
```

The new driver reads `database.connections`, independently of the legacy `config/manticore.php`.
Prefixes follow Laravel's rules: `prefix => 'app_'` prefixes `table('products')` automatically.
The legacy `?products` placeholder is not used. A database name is not needed.

The standalone `avadim\Manticore\Laravel\DatabaseServiceProvider` registers only the new driver,
as an alternative to the auto-discovered `ServiceProvider`. Both register the same native driver;
one provider is sufficient.

## Queries and results

```php
use Illuminate\Support\Facades\DB;

$connection = DB::connection('manticore');
$query = $connection->table('products')
    ->select('id', 'title', 'price')
    ->match('galaxy')
    ->where('price', '>', 100)
    ->option('max_matches', 2000)
    ->orderBy('id');

$rows = $query->limit(50)->get(); // Collection<stdClass>
$row = $connection->table('products')->find(1, ['id', 'title']); // stdClass|null
$rows = $connection->select('SELECT id FROM products WHERE price > ?', [100]); // array<stdClass>
```

`select()` on a builder only selects columns and returns that builder. Raw methods accept
Laravel bindings, for example `whereRaw('price > ?', [100])` and
`selectRaw('price * ? as adjusted', [2])`. `toSql()`, `getBindings()` and `toRawSql()` are available.
`match()` binds a Manticore full-text expression; it does not escape search operators as literal text.
For literal visitor text, pass it through `ManticoreQueryBuilder::escapeMatch()` first.

The connector requires PDO emulated prepares. Native prepared UPDATE statements on the tested
Manticore 28.6.6 server round fractional values, so disabling emulation is rejected. Finite PHP
float parameters are rendered as checked numeric literals during execution; other parameters
remain PDO-bound. Laravel's SQL, bindings, events and exceptions retain the original parameters.
This applies to both positional and named parameters. Non-finite values are rejected.

`option($name, $value)` accepts finite numbers, escaped strings, and positive integer maps for
`field_weights` / `index_weights`. Setting an option again replaces its value;
`option($name, null)` removes it. Rankers use validated names, never unescaped SQL.

```php
$query->fieldWeights(['title' => 10, 'body' => 2])->ranker('bm25')->withScore();
$query->ranker('expr', 'sum(lcs*user_weight)*1000+bm25');
$query->option('comment', 'catalog search');
```

`expr` and `export` require a non-empty formula; other built-in rankers take no formula.
Formula strings are SQL-escaped and interpreted by Manticore's ranking expression engine.
See [Manticore search options](https://manual.manticoresearch.com/Searching/Options).

`insert()`, `insertGetId()`, attribute `update()` and `delete()` use Laravel execution and error handling.
SQL errors throw `Illuminate\Database\QueryException`, including rejected writes.
Connection events, `DB::listen()`, query logging and `pretend()` use Laravel's normal pipeline.
`DB::disconnect()`, `DB::reconnect()` and `DB::purge()` manage the new connection pool.
SQL statements are not automatically replayed after a connection error: a lost response does not
establish that the server failed to execute a write.

`lazy()`, `lazyById()` and `cursor()` return `LazyCollection`. `cursor()` performs one SELECT,
while `lazy()` fetches pages. PDO buffering is controlled by the connection's PDO options;
a lazy collection alone does not guarantee an unbuffered server result.

## Search metadata

```php
$query = $connection->table('products')->match('galaxy')->withScore()->limit(20);
$result = $query->search(['id', 'title']);
$rows = $result->rows(); // Collection<stdClass>, including the _score column
$meta = $result->meta();

$meta->totalFound();  // int|null
$meta->total();       // int|null
$meta->time();        // float|null
$meta['total_found'];
$meta->toArray();
```

`search()` captures SELECT rows and SHOW META on the same PDO connection before query-event
listeners run. The metadata snapshot remains valid after subsequent commands and after-query
callbacks. `Meta` is immutable, iterable, countable and JSON serializable; missing fields return
`null`. Reading the result or its metadata issues no further SQL.

Both SELECT and SHOW META appear in query events/logs after capture. The SELECT duration includes
metadata capture; SHOW META also has a separate timing entry. No metadata query runs in `pretend()`.

Ordinary `get()` returns a standard collection without SHOW META; its first use may load DESCRIBE.
For an explicit
follow-up, `$query->getMeta()` or `$connection->getMeta()` executes SHOW META for the current
session. Another command, including one from a query listener, can replace that session metadata;
use `search()` when rows and metadata must be captured together.

## MVA filters and geographic search

```php
$rows = $connection->table('products')
    ->whereMvaAny('categories', [2, 3]) // ANY(categories) IN (2, 3)
    ->whereMvaAll('categories', '>=', 1)
    ->get();

$rows = $connection->table('places')
    ->withDistance('lat', 'lon', 55.75, 37.6, 'km')
    ->select('id', 'title')
    ->whereGeoDistance('lat', 'lon', 55.75, 37.6, 10, 'km')
    ->orderByDistance('lat', 'lon', 55.75, 37.6, 'km')
    ->get(); // each row includes _distance
```

MVA helpers accept scalar comparisons, IN / NOT IN sets and BETWEEN / NOT BETWEEN ranges.
Two arguments mean equality for a scalar or IN for an array. `orWhereMvaAny()` and
`orWhereMvaAll()` work in nested Laravel conditions too. Values must be integers or decimal
integer strings within PHP's integer range; strings are converted with a precision check.
Empty sets, invalid operators and ranges without exactly two bounds are rejected.
ALL tests every stored element against the condition; it does not mean "contains every supplied value".

Geographic coordinates are degrees, latitude first; units are `m`, `km` or `mi`. Numeric attributes
and JSON paths such as `location.lat` are accepted. Coordinates and radii must be finite and valid.
`withDistance()` keeps its selected expression even when `select()` is called afterwards, and accepts
an optional final alias argument. Conflicting or reserved aliases are rejected.

Filter/order helpers work without `withDistance()` as well: their internal computed columns are
removed from returned rows, including search results and cursors. Nested geo filters, aggregates
and pagination include the required expressions. Existing ordering is preserved by `orderByDistance()`.

## KNN vector search

Create an indexed vector with `$table->floatVector('embedding', 3, 'l2')`; dimensions must be
positive and similarity must be `l2`, `cosine` or `ip`. Omitting dimensions creates an ordinary,
unindexed float vector. HNSW queries require the server's KNN library.

```php
$result = $connection->table('products')
    ->knn('embedding', [1.0, 0.0, 0.0], 20, ['ef' => 100])
    ->withKnnDistance()->select('id', 'title')
    ->match('galaxy')->where('stock', '>', 0)
    ->orderByKnnDistance()->search();
```

`knn()` sets LIMIT to k; a subsequent `limit()` can override the returned row count. It compiles
the backwards-compatible `KNN(field, k, vector, options)` syntax. The server controls candidate
selection; k is not a promise of exactly k rows after filtering or across chunks. Only one
top-level KNN condition with AND filters is accepted. OR trees, nested KNN and KNN writes are rejected.
Vectors must be non-empty finite numeric lists; dimension mismatches are reported by Manticore.
Options are `ef`, `oversampling`, `rescore`, `early_termination` and `prefilter`; availability depends
on the server version. `withKnnDistance()` adds `_knn_distance` (or a custom alias), and
`orderByKnnDistance()` sorts via an internal column removed from returned rows.
See [Manticore KNN](https://manual.manticoresearch.com/Searching/KNN).

## Facets and highlighting

```php
$result = $connection->table('products')->match('galaxy')->limit(20)
    ->withHighlight('title', ['before_match' => '<em>', 'after_match' => '</em>'])
    ->facet('category', 'categories', 10, 'count', 'desc')
    ->facet('details.brand', 'brands', 20, 'value', 'asc')
    ->search(['id', 'title']);

$result->rows()[0]->_highlight; // highlighted string
$result->facet('categories'); // Collection<stdClass>, columns: categories, count(*)
$result->facets(); // array keyed by facet name
```

`facet(column, alias, limit, sort, direction)` accepts an attribute, MVA or JSON path, an optional
unique alias, a positive bucket limit, `count` / `value` sorting and `asc` / `desc`. Main-query
filters also apply to facets; the main row limit does not limit their counts. Buckets and metadata
are captured before query listeners run. MVA buckets contain individual values; counts are integers.
JSON path values retain PDO types. Missing facet names throw `OutOfBoundsException`.
Facets require `search()`; `get()`, cursors, pagination/aggregate counts, main GROUP BY/HAVING and
writes with facets are rejected. Raw facet expressions, distinct counts and per-facet filter
overrides are not yet exposed by this helper.

`withHighlight(fields, options, query, alias)` accepts a field name, a list of fields or an empty
list for all stored fields. It adds `_highlight` by default, keeps the expression after `select()`,
and works with `get()`, `search()` and cursors. Without an explicit highlight query, Manticore uses
the search MATCH. Options and an optional alternate query are escaped; the result is a string
using Manticore's field/snippet separators. Source fields must be stored. HTML sanitization is
an application concern; choose markers and stripping options appropriate for the output context.
See [facets](https://manual.manticoresearch.com/Searching/Faceted_search) and
[highlighting](https://manual.manticoresearch.com/Searching/Highlighting).

## CALL helpers and autocomplete

```php
$keywords = $connection->keywords('gal*', 'products', ['stats' => 1]);
$suggestions = $connection->suggest('galaxi', 'products');
$lastWord = $connection->qsuggest('a galaxi', 'products');
$snippets = $connection->snippets(['galaxy phone', 'galaxy tablet'], 'products', 'galaxy');
$completions = $connection->autocomplete('gal', 'products', ['fuzziness' => 0]);

// All row-producing sets, including empty sets, as array<Collection<stdClass>>:
$sets = $connection->call('KEYWORDS', ['galaxy', 'products'], ['stats' => 1]);
```

Named helpers return the first result set as a collection and apply the connection's table prefix.
Generic `call()` binds positional scalar arguments and non-empty flat lists; named options are
compiled as bound `value AS name` arguments. It uses exact supplied arguments, without applying
table prefixes. Every result set is consumed and the cursor closed before query events run.
Procedure output retains PDO types. Standard Laravel query logs, `pretend()` and `QueryException`
are supported; failures never cause automatic retries or silent empty-result fallback.

KEYWORDS, SUGGEST, QSUGGEST and SNIPPETS work without Buddy. AUTOCOMPLETE requires Buddy and
`min_infix_len` on the table, for example `$table->setting('min_infix_len', 2)`. The helper passes
server errors through when these requirements are missing. See
[Manticore autocomplete](https://manual.manticoresearch.com/Searching/Autocomplete).
Local native tests run without Buddy; set `MANTICORE_TEST_BUDDY=1` to require its live test.
CI sets this flag, so autocomplete errors fail the job instead of being skipped.

## Deep pages

`paginate()` and `simplePaginate()` use Laravel's paginators. A SELECT window beyond the standard
1000 matches automatically gets `max_matches = offset + limit`, unless the query explicitly sets
`max_matches`. This also applies to `lazy()` and other offset-based walks. The query's configured
options are not mutated by compilation. Explicitly setting a smaller window can still produce
a server error; use `chunkById()` or `lazyById()` for large ID-based walks without growing offsets.
Server result limits still apply to a SELECT without an explicit `limit()`.

## Writes and transactions

`insert()` accepts one document or a batch. `insertGetId()` and `replaceGetId()` capture the
server ID before query listeners can issue another write. `replace()` returns a boolean and
replaces whole documents by ID: omitted fields reset to server defaults, including empty MVA,
JSON null and numeric zero. Use REPLACE to change full-text fields. Non-empty `upsert()` calls
throw `LogicException`: arbitrary unique keys and partial atomic upserts are not implemented.

```php
$connection->table('products')->replace([
    'id' => 42, 'title' => 'Galaxy', 'price' => 199.5,
]);
$connection->transaction(function () use ($connection) {
    $connection->table('products')->replace(['id' => 42, 'title' => 'Galaxy', 'price' => 189.5]);
});
```

Transactions support one level and Laravel's after-commit callbacks. Nested transactions,
DDL inside transactions and retry counts other than one are rejected. Failed BEGIN, COMMIT
or ROLLBACK disconnect the uncertain session; SQL and transaction callbacks are never replayed.
If both the callback and rollback fail, `TransactionRollbackException` exposes `callbackError`
and `rollbackError`. A failure retrieving an ID can follow a successful write; it never causes
another INSERT. UPDATE remains subject to Manticore's supported attribute operations.

Only INSERT, REPLACE and DELETE participate in Manticore transactions. In-place UPDATE is
not rolled back; the current driver does not reject UPDATE inside a transaction. Use a
complete-document REPLACE for changes that need rollback, including all attributes to retain.
See [Manticore transactions](https://manual.manticoresearch.com/Data_creation_and_modification/Transactions).

## Schema and migrations

```php
use avadim\Manticore\Laravel\Database\ManticoreBlueprint;
use Illuminate\Support\Facades\Schema;

Schema::connection('manticore')->create('products', function (ManticoreBlueprint $table) {
    $table->id(); // Manticore's implicit 64-bit document ID
    $table->text('title'); // full-text field
    $table->string('sku'); // string attribute
    $table->float('price');
    $table->json('details');
    $table->multi('categories');
    $table->multi64('external_ids');
    $table->floatVector('embedding');
    $table->setting('min_word_len', 2);
});

$schema = Schema::connection('manticore');
$schema->hasTable('products');
$schema->getColumnListing('products');
$schema->table('products', fn (ManticoreBlueprint $table) => $table->integer('stock'));
$schema->dropIfExists('products');
```

Other supported column types are `integer`, `bigInteger`, `boolean` and `timestamp`.
Laravel string lengths and float precision declarations do not impose SQL constraints;
`float()` maps to Manticore's native float. TEXT supports `stored()` and `indexed()` flags.
CREATE settings are escaped; ALTER currently supports adding and dropping columns, in declaration
order. Changing types, renaming, nullable/default constraints, foreign keys, ordinary Laravel
indexes and unsupported modifiers fail before any statement from that blueprint executes.

Use ordinary migrations with `protected $connection = 'manticore'` and explicit
`Schema::connection('manticore')`. Keep Laravel's migration repository on the application's
primary SQL connection. DDL is not transactional: server failure halfway through a multi-statement
ALTER leaves earlier statements applied, so `down()` and recovery must account for that state.

## Attribute conversion and schema cache

The Query Builder loads DESCRIBE once per table and connection before its first read or write.
JSON arrays/objects are serialized on writes and decoded to associative arrays on reads;
JSON null remains null. MVA/MVA64 and float vectors accept list arrays and return arrays.
Integer values retain precision, using strings outside PHP's integer range. Boolean attributes
return booleans; numeric attributes return numbers. `DateTimeInterface` timestamp inputs become
epoch seconds. Expressions pass through without serialization.

Conversion applies to `get()`, `first()`, `pluck()`, `search()`, cursors and lazy collections,
including simple selected column aliases. Types of computed expressions are not inferred.
Raw `$connection->select()` retains PDO values. Set connection option `cast_attributes => false`
to disable automatic conversion and its schema discovery; then serialize structured writes yourself.
Schema introspection, cursor pagination and exact grouped pagination still inspect column types.

Schema Builder and raw CREATE/ALTER/DROP/TRUNCATE/RENAME invalidate the cache, including on failure.
Reconnect also clears it. After external DDL use `$connection->forgetSchema('products')` with the
logical, unprefixed table name, or `$connection->forgetSchema()` for all tables. Schema discovery
runs before the search SELECT so it does not overwrite its metadata snapshot.

## Walking large result sets

The native builder uses Laravel's `chunk()`, `each()`, `lazy()`, `chunkById()`, `eachById()`,
`lazyById()`, `chunkByIdDesc()` and `lazyByIdDesc()`. Offset walks require an explicit stable
`orderBy()`; ID walks order by the document ID automatically. Select the boundary column and,
if aliased, pass both its original name and selected alias:

```php
$connection->table('products')->select('id as document_id', 'title')
    ->lazyById(700, 'id', 'document_id')->each(function ($row) {
        // Process one document.
    });
```

Use ID walks when deleting processed documents or changing filtered attributes. Keep the ID
unchanged and group OR conditions inside a closure, so Laravel's next-ID condition applies to
the entire filter. These walks are not a snapshot against concurrent inserts or updates.
Returning `false` from chunk/each callbacks stops further pages; lazy collections fetch only
when iterated and `take()` stops fetching later pages. Cast JSON/MVA attributes remain available.
Like Laravel, some offset methods mutate their builder; clone reusable queries before walking.
Offset walks automatically grow `max_matches` as documented above; ID walks avoid growing offsets.

## Cursor lifetime and buffering

`cursor()` defers execution until iteration and runs one SELECT. It closes its PDO cursor on
exhaustion, fetch errors and destruction of an early-stopped iterator. `take(1)`, a normal `break`
and exceptions from collection callbacks release temporary iterators. If you retain the iterator
yourself, release all references in `finally` when stopping early:

```php
$iterator = $connection->table('products')->orderBy('id')->limit(5000)->cursor()->getIterator();
try {
    foreach ($iterator as $row) {
        // Process documents; breaking early is allowed.
    }
} finally {
    unset($iterator);
}
```

PDO buffers MySQL results by default, which allows another query during iteration. Configure
`options => [PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false]` for an unbuffered connection. While an
unbuffered iterator is active, use a separate connection for other queries, including queries
from listeners/callbacks. PDO rejects concurrent SQL on that connection with error 2014; the
driver reports `QueryException` and never discards the active result silently. After exhaustion
or releasing the iterator, the same connection is usable again. Fetch failures also retain the
original SQL and bindings. `pretend()` produces an empty cursor without connecting.

A cursor is one bounded server query: Manticore's result window still applies. Use an explicit
limit (which can raise `max_matches`) or `lazyById()` to walk an entire large table in pages.
Streaming PHP rows alone does not disable PDO buffering or create a database snapshot.

## Cursor pagination and totals

`cursorPaginate()` returns Laravel's `CursorPaginator`, using encoded next/previous cursors and
lexicographic WHERE boundaries instead of growing offsets. It performs no total-count query.
The default order is document ID ascending. Other ordering must use selected integer, boolean or timestamp
attributes or their simple aliases; a final ID tie-breaker is added automatically when needed.
Select every ordering column, including ID. Float/string, JSON/MVA and computed ordering, raw SELECT
projections, offsets, grouping, facets and KNN are rejected by this pagination helper.

```php
$query = $connection->table('products')->select('id', 'stock', 'title')->orderBy('stock');
$first = $query->cursorPaginate(50);
$next = $query->cursorPaginate(50, ['*'], 'cursor', $first->nextCursor()?->encode());
$previous = $query->cursorPaginate(50, ['*'], 'cursor', $next->previousCursor());
```

Manticore can round SQL float output (for example, 0.000000142857142857 becomes 0.00000014),
so float ordering cannot provide a lossless cursor boundary; use integer keys or offset pagination.
Cursor sizes must be positive integers. Malformed tokens, missing/null or fractional boundary values
and integer boundaries outside PHP's integer range are rejected. Integer IDs above 2^53
remain exact on 64-bit PHP. Reuse the same filters and ordering with a token; cursors do not create
a snapshot across concurrent changes. The source query is cloned before pagination.

`paginate()` uses an ordinary COUNT for document queries. For GROUP BY on one or more scalar
attributes it instead captures the grouped query's SHOW META, including HAVING. A service COUNT
first bounds the number of groups by matching documents; pagination clones use that `max_matches`
and `accurate_aggregation=1` for both the count and data queries. This avoids Laravel's unsupported
COUNT-over-subquery and prevents the default 1000-group window from truncating results. Source
options/bindings are unchanged. Group columns must be direct scalar attribute names; MVA/JSON,
raw group expressions and HAVING without GROUP BY are rejected. Manticore accepts a single
HAVING condition; composing multiple HAVING clauses is rejected explicitly. High-cardinality grouping can
require substantial server memory; use document cursor pagination for large document walks.

Exact pagination counts reject KNN candidate queries, facets and nonzero cutoff. Use `search()`
for their search metadata, or `simplePaginate()` when supported by the query and no total is needed.
Search `total_found` for ordinary documents can be an estimate; it is not substituted for COUNT.
See [Laravel cursor pagination](https://laravel.com/docs/13.x/pagination#cursor-pagination) and
[Manticore grouping](https://manual.manticoresearch.com/Searching/Grouping).

## Current boundaries

The minimum supported server is Manticore 28.6.6. CI tests its pinned official image and `latest`
with their bundled Buddy/KNN libraries. This is a support policy, not a claim that all older
servers are incompatible. See [server compatibility](testing.md#server-compatibility).

- Joins, unions, SELECT DISTINCT, locks and group limits are currently rejected by the driver.
  WHERE EXISTS subqueries and aggregates with HAVING are also rejected.
- UPDATE and DELETE reject ordering, limits, offsets, joins, search options and computed search expressions, rather than
  silently discarding them. Updating full-text fields requires separate Manticore handling.
- Native atomic `upsert()` remains unsupported. Advanced facet controls and hybrid fusion are
  not yet exposed by dedicated helpers.
- Read/write splitting and Eloquent compatibility have not been established.

The old `ManticoreDb`, `Manager`, `Connection`, `Query`, `Row` and `Factory` API has been removed.
Use the [migration guide](upgrading-3.x-to-4.x.md) to replace application calls and type hints.
