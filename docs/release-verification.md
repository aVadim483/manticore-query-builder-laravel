# Published-package verification

[Русский](ru/release-verification.md)

Following the [3.x application migration](migration-app-verification.md), published packages
were installed in the same application through Composer. No path/package repositories or
vendor patches were used. Only this dependency changed in the lock file; Laravel 12.70.1,
PHP CLI 8.3.6, PHP-FPM 8.4.27 and Manticore 29.9.0 were retained.

| Published package | Git source reference | Application / native / current API / browser checks |
| --- | --- | --- |
| 4.0.0-beta.1 | `cbbf0e0fa2545914c3dec09e292767c991cff286` | 32 / 6 / 8 / 11, all passed |

The beta also passed a second real 120-job worker run in one process, with zero retained or
allocated memory growth after job 20. The [performance report](performance.md) describes its scope.

All 31 comparable application results matched the original 3.0.0 baseline; the changed
Row/Generator contract was checked separately. All 2,105 original documents and their SHA-256
fingerprint remained intact. Published source bytes were checked against their exact Git tag:
the manifest of `composer.json` and 31 `src` files has SHA-256
`55a071ceb4cb71a67f893779027c94e8800cdb7abeb29a591f5f397bca7a8c79`.
The earlier working-directory snapshot has a different byte fingerprint because one file
had mixed line endings; its normalized content matches the published runtime code.

The [public report](https://manticore.avadim.ru/?report=1) exposes the installed package,
browser results and source reference. Per-version reports and pre-upgrade vendor/lock backups
are retained privately by the test application. These checks verify this application and
the documented driver profile; they do not claim full Eloquent or Lumen compatibility.
