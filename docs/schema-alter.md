# Extended Schema Builder

[Русский](ru/schema-alter.md)

Available in **4.0.0** for real-time tables in RT mode.
The supported minimum is Manticore 28.6.6.

## Change table settings

```php
use Illuminate\Support\Facades\Schema;

$schema = Schema::connection('manticore');
$schema->table('products', function ($table) {
    $table->setting('min_infix_len', 3);
    $table->setting('morphology', 'stem_en');
});
$settings = $schema->getSettings('products'); // array<string, string>
```

Adjacent settings become a single ALTER statement with space-separated assignments.
ADD/DROP/change commands separate settings into groups; declaration order is preserved.
Repeated names in the same group use their last value. CREATE still uses `setting()`.

| ALTER setting | Accepted input |
| --- | --- |
| `min_prefix_len`, `min_infix_len`, `min_word_len` | Nonnegative integer or digit string |
| `expand_keywords`, `index_exact_words`, `html_strip` | `0`, `1`, `'0'`, `'1'` |
| `dict` | `'keywords'` or `'crc'` |
| `rt_mem_limit` | Positive integer bytes or digit string, optionally suffixed with K/M/G |
| `morphology`, `charset_table`, `stopwords`, `prefix_fields` | Single-line string |

Names and value formats are validated before DDL. The server validates ranges and
combinations, available dictionaries and files. Unsupported ALTER settings raise
`LogicException`; malformed values raise `InvalidArgumentException`. Other supported
CREATE settings remain usable on CREATE. Multiline values are rejected.

`getSettings()` executes SHOW TABLE SETTINGS on the write connection every time.
It returns only reported settings, without guessing omitted defaults. Values are
strings in server format: for example, `32M` becomes `'33554432'`. It is not a complete
configuration or a lossless copy of CREATE input; normalize desired settings before
comparing them in a synchronization tool. Unexpected or duplicate entries raise
`UnexpectedValueException` rather than producing an ambiguous map.

Changing full-text settings does not rebuild existing documents. Explicitly reimport
or reindex documents to apply the new processing rules to the existing index.

## Widen an integer attribute

```php
Schema::connection('manticore')->table('products', function ($table) {
    $table->bigInteger('stock')->change();
});
```

This supports only an existing row-wise `int` attribute (reported as `uint` by DESCRIBE)
to signed `bigint`. Existing values survive; the widened column accepts 64-bit integers.
An already-bigint source, columnar attribute, ID, missing column, narrowing or any other
conversion is rejected. No implicit table rebuild or data copying is performed.

Before executing any statement, `change()` refreshes DESCRIBE and validates the source
against preceding ADD/DROP commands in the same Blueprint. `toSql()` and `pretend()`
remain offline: they validate the target definition but do not inspect the source table.
Concurrent external DDL can still cause a server error after validation.

There is no automatic inverse conversion. Do not put `integer()->change()` in `down()`.
Keep the widened type, declare the migration irreversible, or implement an explicitly
reviewed rebuild with suitable data checks. The driver does not choose a rebuild for you.

## Rename a table

```php
Schema::connection('manticore')->rename('products', 'catalog');
```

Requires Buddy. Both names are logical names without the connection prefix; the driver
adds the prefix to each. A rename must occupy its own Blueprint. Empty, qualified,
aliased or identical target names are rejected. Existing targets are not overwritten.
Missing Buddy, a missing source or a target conflict raises `QueryException` from the
server. Column renaming is not supported.

## Failures and migration recovery

Definitions are compiled and validated before the first DDL statement. ALTER and RENAME
clear the connection's schema cache before execution and in `finally`, including failures.
`change()` also refreshes its source schema before execution. Other connections and workers
retain their own caches; call `forgetSchema()` or restart them after external changes.

DDL cannot run inside a driver transaction and is not atomic. If a later statement fails,
earlier column changes and settings remain applied. Inspect the actual schema and settings
before retrying or writing recovery code. Restoring a full-text setting does not reindex
documents that were written while the changed setting was active.

Keep Laravel's migration repository in the primary SQL database and select Manticore
explicitly inside each migration. Repository fixtures in `tests/fixtures/driver-alter-migrations`
demonstrate an upgrade and rollback preserving documents and the widened bigint type.
