# Floating-point parameters bound as strings

[Русский](ru/float-string-bindings.md)

Verified on October 26, 2026 with Manticore **28.6.6 and 29.9.0**, PHP 8.3.6 and PDO MySQL.
Each server ran 57 scenarios: 48 with emulated prepares, as required by the driver, and nine
diagnostic native-prepare cases. Modes compare current NumericBindings literals, explicitly
formatted numeric strings, PHP float-to-string conversion and selected DOUBLE(?) expressions.
Checks include errors, resulting data/types, affected rows and warnings. Classifications agree
between the two servers.

| Context with emulated prepares | String-bound float result |
| --- | --- |
| INSERT/REPLACE into an ordinary float attribute | Same stored values for all eight tested numbers |
| UPDATE float attribute | Successful execution with 0 affected rows; old value remains |
| Float WHERE comparisons / BETWEEN | Type or syntax error |
| Numeric JSON filters | Accepted, but different results; tested string variants return no matches |
| Scalar SELECT, arithmetic, ABS, POW, GEODIST, DOUBLE | Type or syntax error |
| Aggregate expressions / numeric HAVING | Error |
| Raw numeric JSON-path UPDATE | Syntax error |
| Vector tuples in INSERT/REPLACE or KNN | Syntax error on quoted components |
| Tested IF comparison | Same result; a specific accepting context |

For UPDATE, an initial price of 1.0 becomes approximately 55.3 with a numeric literal;
the string `'55.3'` leaves 1.0 unchanged. PDO reports success, affected rows are 0 and SHOW
WARNINGS is empty. Checking errors alone is insufficient. Numeric JSON equality matched
four records, while its string-bound version matched none.

Tested write values: 55.3, -55.3, 0.0, -0.0, 1e-7, 1e-20, 1.25e6 and 1e20. The ordinary
float32 representation differences are identical between scalar INSERT/REPLACE variants.
IN on a float attribute was also unsupported with numeric literals and is excluded from the
string-regression conclusion. DOUBLE(?) does not provide a general workaround.

Native prepares remain prohibited by the Connector. On both servers, native UPDATE of a
55.3 literal stored 55.0; its string variant retained the old value.

NumericBindings cannot be removed globally or skipped merely by detecting an INSERT/REPLACE
prefix: float bindings inside vector tuples still require numeric literals. A separate fast
path may be possible for verified scalar float attribute values in ordinary builder writes,
while preserving expression/vector handling, finite-number checks and event/exception contracts.
No library or vendor changes were made by this experiment.

A separate [string-float vs Expression timing comparison](../benchmarks/sql-pipeline/float-string-writes.md)
on Manticore 29.9.0 found no consistent end-to-end benefit from string conversion alone.
For 500-document batches, strings took about 2% longer: fewer Expressions meant more PDO bindings.

See the [detailed report](ru/float-string-bindings.md),
[summary JSON](experiments/float-string-bindings-2026-10-26.json) and
[full JSON gzip](experiments/float-string-bindings-2026-10-26.raw.json.gz).
To repeat from a repository clone:

```sh
php tests/experiments/float-string-bindings.php /path/to/application/vendor/autoload.php 9306 /tmp/float-strings.json
```

Only a random scratch table is created and dropped. The raw report's top-level `ok` indicates
experiment completion; expected SQL errors and semantic differences are recorded per mode.
