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

## GitHub Actions (`.github/workflows/`)

Same layout as `ns_t3af`: one workflow per TYPO3 major, every step through the Docker test runner `Build/Scripts/runTests.sh` (`ghcr.io/typo3/core-testing-php*` images).

| Workflow | Runs |
|---|---|
| `core12.yml`, `core13.yml`, `core14.yml` | per PHP 8.2 / 8.3 / 8.4 / 8.5: `composerInstallHighest`, `lint`, `unit`; on PHP 8.3 also `cgl -n` (php-cs-fixer dry run) and `composer -- stan` (PHPStan per major — the code branches on classes that exist in only some majors) |
| `documentation.yml` | renders `Documentation/` with `render-guides --minimal-test` |
| `ter-release.yml` | on a git tag: publishes to TER with `tailor` (needs repo secrets `REPOSITORY_URL`, `TYPO3_EXTENSION_KEY`, `TYPO3_API_TOKEN`, `TYPO3_API_USERNAME`, `TYPO3_API_PASSWORD`) |

`runTests.sh` pins the `typo3/cms-*` packages from `composer.json` `require` to the `-t` major (`TYPO3_CONSTRAINT`) before `composer update`; keep that list in sync when a `typo3/cms-*` requirement is added.

Same checks locally (Docker or Podman):

```bash
Build/Scripts/runTests.sh -t 12 -p 8.2 -s composerInstallHighest
Build/Scripts/runTests.sh -t 12 -p 8.2 -s unit
Build/Scripts/runTests.sh -t 12 -p 8.2 -s lint
Build/Scripts/runTests.sh -t 12 -p 8.2 -s cgl -n
Build/Scripts/runTests.sh -t 12 -p 8.2 -s composer -- stan
```

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
