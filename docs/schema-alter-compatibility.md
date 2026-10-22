# Extended schema compatibility

Protocol checks performed on 2026-10-22 against isolated RT tables in Manticore
28.6.6 / Buddy 4.2.0 and Manticore 29.9.0 / Buddy 4.4.3. These checks do not change
the supported server floor. No application tables were altered.

| Operation | Both tested servers | Driver contract |
| --- | --- | --- |
| `ALTER TABLE t MODIFY COLUMN qty bigint`, starting with `qty int` | Preserves values including 4294967295 | Support `bigInteger('qty')->change()` for an existing int attribute |
| Convert bool or timestamp to bigint | Server rejects | Reject unsupported source types before DDL |
| ALTER `min_prefix_len`, `min_infix_len`, `min_word_len`, `morphology`, `charset_table`, `dict`, `expand_keywords`, `index_exact_words`, `html_strip`, `stopwords`, `prefix_fields`, `rt_mem_limit` | Accepted | Expose a validated subset of settings through the existing `setting()` API |
| Multiple settings separated by spaces | Accepted | Emit one statement for adjacent setting operations |
| Multiple settings separated by commas | Syntax error | Do not use comma separators |
| `SHOW TABLE t SETTINGS` | A `settings` row containing newline-separated `name = value` entries | Return reported settings as strings; absent defaults are not inferred |
| `ALTER TABLE t RENAME new_t` | Preserves documents with Buddy available | Support Laravel `Schema::rename()`; require Buddy |

DESCRIBE reports an SQL `int` attribute as `uint`. The source-type preflight handles
that protocol spelling. Columnar attributes are deliberately excluded from widening.
A separate 29.9.0 process with `buddy_path` empty verified that rename raises
`QueryException` without changing the original table or its documents.

Settings output is normalized by the server. For example, `rt_mem_limit='32M'`
is reported as `33554432`. It is not an exact round trip of CREATE input or a
complete list of defaults. Changing full-text settings does not reindex existing
documents. Reimport/reindex them explicitly when the new rules must apply.

DDL is not transactional. Validate a whole Blueprint before running its first
statement, preserve declaration order, and invalidate schema caches on success
and failure. A server error can still leave earlier statements applied.

Arbitrary type changes, narrowing, implicit table reconstruction, column rename,
distributed table reconfiguration and remote embedding credentials are outside
this increment. CREATE-only settings remain available on CREATE, without being
automatically accepted on ALTER.

Reference: [Manticore schema and settings manual](https://manual.manticoresearch.com/Updating_table_schema_and_settings).
