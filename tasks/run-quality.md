# Run Quality Checks

From the extension root (`typo3conf/ext/ns_t3al/`). Dev tooling lives in the git-ignored `.Build/` (`composer install` once).

```bash
composer install            # once
composer test               # = phpunit -c Build/phpunit/UnitTests.xml (no DB, no instance)
.Build/bin/phpunit -c Build/phpunit/UnitTests.xml --filter XliffDocumentUpdaterTest   # one test class
composer stan               # PHPStan level 3 (phpstan.neon)
composer cs:check           # php-cs-fixer dry run (.php-cs-fixer.php: PSR-12 + short arrays, ordered imports, single quotes)
composer cs:fix             # apply the fixes
composer ci                 # test + stan + cs:check — what the pipeline runs
```

Follow the TYPO3 CGL as well (`declare(strict_types=1)`, typed properties, constructor DI via `Services.yaml`).

## GitLab pipeline (`.gitlab-ci.yml`)

Same layout as `ns_t3aa` (image `himanshuramavat/php-ci:<php>-v1.5.0`, one `ci` stage, jobs run on web-triggered pipelines or when PHP / build files change):

| Job | Runs |
|---|---|
| `tests` | unit tests — TYPO3 ^12.4 on PHP 8.1–8.4, ^13.4 and ^14.3 on PHP 8.2–8.4 (10 jobs) |
| `lint` | `php -l` on PHP 8.1 and 8.4 |
| `cs` | `php-cs-fixer fix --dry-run --diff` (installed globally, no TYPO3 install) |
| `stan` | `composer stan` once per TYPO3 major (^12.4 / PHP 8.1, ^13.4, ^14.3 / PHP 8.2) — the code branches on classes that exist in only some majors |

Each job pins the TYPO3 packages from `composer.json` `require` to its `TYPO3_VERSION` before `composer update`; keep that list in sync when a `typo3/cms-*` requirement is added. No PHP 8.5 CI image exists yet; run 8.5 locally (lint, unit tests and PHPStan in Docker) as in `tasks/version-matrix.md`.

Static smoke (also in `context/manual-testing.md` → Quick automated smoke):

```bash
find Classes Configuration Tests ext_*.php -name '*.php' -print0 | xargs -0 -n1 php -l
php -r 'var_export(is_array(include "Configuration/Backend/Modules.php"));'
test ! -e Configuration/Backend/AjaxRoutes.php && test ! -e ext_tables.php && echo "no AJAX routes / ext_tables.php"
```

From the TYPO3 root (DDEV, classic mode):

```bash
ddev exec php typo3/sysext/core/bin/typo3 cache:flush
ddev exec 'kill -USR2 $(pgrep -o php-fpm)'   # reset OPcache after deploying PHP
```

| Change | Minimum |
|---|---|
| Utility / Service / ViewDataProvider PHP | `composer test` + add or adjust a unit test for the changed behaviour |
| XLIFF read / write, path guards, batching | unit test is mandatory (see `context/architecture.md` → Unit tests) |
| Anything touching core APIs, caches, Locales, Fluid layouts | `tasks/version-matrix.md` (v12 + v13 unit runs, lint 8.1–8.5) |
| Controller / Fluid / JS / CSS | flush cache (+ OPcache), exercise the flow in the backend — `context/manual-testing.md` |
| Labels (`*.xlf`) | add the key to `locallang.xlf` **and** `de.locallang.xlf`; flush cache |
| Context / docs only | no PHP required |
