# Hybrid search

[Русский](ru/hybrid-search.md)

Hybrid search combines documents found by words with documents found by vector proximity.
A document can enter through either branch. In this driver, Manticore runs two separate
queries; PHP merges their results by ID and orders them using weighted RRF.

This guide describes the **4.0 API**.

## 1. The simplest search

Examples use a `products` table with full-text `title`/`body` fields and a three-dimensional
HNSW-indexed `embedding` column. The tutorial schema appears below. Configure the
`manticore` connection in `config/database.php` first.

<!-- example: minimal -->
```php
use Illuminate\Support\Facades\DB;

$vector = [1, 0, 0];
$products = DB::connection('manticore')->table('products')
    ->hybridGet('phone', 'embedding', $vector);
```

`$products` is a normal `Collection<stdClass>`, as with `get()`. Documents are already in
fused ranking order. Do not add another `get()` or `search()` call.

| Argument | Meaning |
| --- | --- |
| `'phone'` | Text for the full-text branch |
| `'embedding'` | Vector column name in Manticore, not a text field name |
| `$vector` | Query vector matching the column's dimensions |

The three-dimensional vector is illustrative, for the tutorial table. In an application,
generate document/query embeddings with the same compatible model. The driver does not
convert text into vectors. Defaults are equal branch weights, RRF constant 60 and up to
100 candidates per branch. Duplicate IDs merge.

## 2. Only the best document

<!-- example: first -->
```php
$product = DB::connection('manticore')->table('products')
    ->hybridFirst('phone', 'embedding', $vector);
```

Returns `stdClass` or `null`, as with `first()`. Both complete candidate windows are ranked
before selecting the best document. Neither candidate limit is reduced to 1: doing so could
lose a document ranked highly in both branches.

## 3. Shared filters

<!-- example: filters -->
```php
$products = DB::connection('manticore')->table('products')
    ->where('stock', '>', 0)
    ->where('brand', 'acme')
    ->whereIn('category_id', [1, 2])
    ->hybridGet('phone', 'embedding', $vector);
```

Filters apply to both branches before fusion. Set conditions before the terminal method.
`hybridGet()` has already returned a collection; adding SQL conditions afterward is too late.

## 4. Change one branch's weight

The fourth argument is optional. Start with one setting when adjusting the balance:

<!-- example: weights -->
```php
$products = DB::connection('manticore')->table('products')
    ->hybridGet('phone', 'embedding', $vector, [
        'vector_weight' => 2,
    ]);
```

Text weight remains 1; vector weight becomes 2. This increases the contribution of the
vector rank. Raw WEIGHT scores and distances are not added together. Zero disables a
branch; at least one weight must be positive.

## 5. Scores, ranks and metadata

Use `hybridSearch()` for a detailed result:

<!-- example: result -->
```php
$result = DB::connection('manticore')->table('products')
    ->hybridSearch('phone', 'embedding', $vector);

$documents = $result->rows();
$hits = $result->hits();
$bestHit = $hits->first();
$textMeta = $result->textMeta;
$vectorMeta = $result->vectorMeta;
$totals = $result->totals();
```

| Method | Return value |
| --- | --- |
| `hybridSearch(...)` | `HybridResult`: documents, scores, metadata and window pagination |
| `hybridGet(...)` | `Collection<stdClass>`: documents only |
| `hybridFirst(...)` | `stdClass|null`: best document only |

All three execute immediately. Each `HybridHit` exposes `id`, `document`, fused `score`,
`textRank`, `vectorRank`, `textScore` and `distance`. Absent-branch fields are null and its
score contribution is zero. Ranks start at 1.

Formula: `text_weight/(rank_constant+text_rank) + vector_weight/(rank_constant+vector_rank)`.
When both branches contain an ID, the text branch supplies its document fields. Equal
fused scores sort by ascending unsigned numeric ID, retaining bigint precision.

## 6. Page a bounded window

<!-- example: page -->
```php
$result = DB::connection('manticore')->table('products')
    ->hybridSearch('phone', 'embedding', $vector, [
        'text_candidates' => 200,
        'vector_candidates' => 200,
    ]);

$page = $result->paginateWindow(perPage: 20, page: 2);
$windowCount = $result->windowTotal();
$globalCount = $result->totalFound(); // null
```

This pages the fused candidate window, not the whole table. There can be up to 400 documents
before deduplication in this example. The paginator contains `HybridHit` objects; access
their documents through `document`. Paging an already materialized result issues no SQL.

`windowTotal()` is exact for that window; the full union total is unknown. `totals()` marks
window accuracy `exact`, global `unavailable`, and branch totals `unknown` or `unavailable`
for absent branches. Do not sum branch totals: they overlap, server settings bound them,
and KNN can be approximate. RAM KNN can report more matches than the returned top-k.

Deeper pages require larger candidate windows, increasing work, memory and traffic.
A new search after data changes can change ordering. There is no unlimited cursor traversal.

## 7. Advanced: filters, text weights and KNN options

Use this after understanding the settings above. Values illustrate the API; they are not
universal quality/performance recommendations for every index.

<!-- example: advanced -->
```php
$result = DB::connection('manticore')->table('products')
    ->where('stock', '>', 0)
    ->where('brand', 'acme')
    ->where(function ($query) {
        $query->whereIn('category_id', [1, 2])
            ->whereBetween('price', [100, 1000]);
    })
    ->whereMvaAny('tags', [1, 2])
    ->fieldWeights(['title' => 10, 'body' => 2])
    ->ranker('bm25')
    ->option('max_matches', 1000)
    ->hybridSearch('phone', 'embedding', $vector, [
        'text_weight' => 1.5,
        'vector_weight' => 0.75,
        'rank_constant' => 60,
        'text_candidates' => 300,
        'vector_candidates' => 150,
        'escape' => true,
        'knn_options' => [
            'ef' => 300,
            'oversampling' => 2,
            'prefilter' => true,
            'rescore' => true,
            'early_termination' => false,
        ],
    ]);
```

`fieldWeights()` affects ranking of text candidates. `text_weight`/`vector_weight` control
fusion afterward: these are different settings. `knn_options` are server KNN parameters,
not PHP fusion controls.

## 8. Extended MATCH syntax

Text is escaped as literal input by default. To deliberately use operators and select
text fields, disable escaping:

<!-- example: syntax -->
```php
$result = DB::connection('manticore')->table('products')
    ->hybridSearch('@title phone | @body travel', 'embedding', $vector, [
        'escape' => false,
    ]);
```

This affects only the text branch. The vector is still supplied separately.

## Option reference

| Option | Default | Values / purpose |
| --- | --- | --- |
| `text_weight` | 1 | Finite number 0..1000000, text-rank contribution |
| `vector_weight` | 1 | Finite number 0..1000000, vector-rank contribution |
| `rank_constant` | 60 | Integer 0..1000000; larger constants flatten differences between neighbouring rank contributions |
| `text_candidates` | 100 | Integer 1..10000, maximum text candidates |
| `vector_candidates` | 100 | Integer 1..10000, requested KNN candidates |
| `escape` | true | Escape MATCH input; empty text skips the text branch |
| `knn_options` | [] | Positive integer `ef`; finite `oversampling` ≥1; boolean/0/1 `prefilter`, `rescore`, `early_termination` |

Truncation, HNSW and filters can shorten a candidate list. Vector dimensions and metric
are checked against HNSW declarations. Cosine vectors must be nonzero.

## Tutorial schema

Requires Manticore 28.6.6+ and its KNN library. Create tables through migrations; the
application generates embeddings and loads documents. The examples use this schema:

<!-- example: schema -->
```php
use Illuminate\Support\Facades\Schema;

Schema::connection('manticore')->create('products', function ($table) {
    $table->text('title');
    $table->text('body');
    $table->string('brand');
    $table->integer('stock');
    $table->integer('category_id');
    $table->float('price');
    $table->multi('tags');
    $table->floatVector('embedding', 3, 'l2');
});
```

## Execution, errors and limits

Branches run sequentially through one write PDO without a shared transactional snapshot.
Metadata is captured before the next query or query listener. An empty branch still permits
documents from the other. Branch failures propagate; there is no successful empty fallback
or implicit search-mode change. See [capability diagnostics](hybrid-and-index-replacement.md).

The common query accepts a simple logical table name and AND attribute predicates, including
nested AND/MVA and [JSON filters](laravel-driver.md#json-filters). Generated JSON filter
expressions are copied to both branches. Projection, raw predicates/expressions, MATCH/KNN, facets, custom ordering,
limit/offset, joins, grouping, callbacks and native fusion controls are rejected. The source
builder is not mutated. `__hybrid_text_score` and `__hybrid_distance` are reserved column names;
application fields are not overwritten with scores.

`knn()->match()` remains intersection: documents must satisfy both conditions. `hybrid*()`
merges independent lists in the library without SQL UNION.
