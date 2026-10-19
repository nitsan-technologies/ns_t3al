# Run the v12 / v13 / v14 matrix

> Supported range and known traps: `context/core.md` → Compatibility.

## Unit tests per TYPO3 major (no instance needed)

The extension's own `composer.json` resolves the newest core (v14). For v12 / v13 build a throw-away copy with pinned cores and the PHP floor as Composer platform:

```bash
# from the extension root; MAJOR=12 PHP=8.1  or  MAJOR=13 PHP=8.2
MAJOR=12 PHP=8.1
DIR=/tmp/t3al-v$MAJOR
rm -rf "$DIR" && mkdir "$DIR"
rsync -a --exclude .Build --exclude composer.lock --exclude var ./ "$DIR/"
cd "$DIR"
composer config platform.php "$PHP.99"
composer require -W --no-interaction --no-progress \
  "typo3/cms-core:~$MAJOR.4.0" "typo3/cms-backend:~$MAJOR.4.0" "typo3/cms-extbase:~$MAJOR.4.0" \
  "typo3/cms-extensionmanager:~$MAJOR.4.0" "typo3/cms-fluid:~$MAJOR.4.0"
/opt/homebrew/opt/php@$PHP/bin/php .Build/bin/phpunit -c Build/phpunit/UnitTests.xml
```

- v14: `composer install && composer test` in the extension itself.
- Repeat with a newer PHP (e.g. `php@8.4`) on the same build to cover the top of the range.
- Expected: same test count on every major, `OK`. A failure only on v12/v13 is usually a core API difference — fix it version-aware, never by skipping the test.
- The `/tmp` copies are disposable; never commit their `composer.json` changes.

## Lint on every PHP version

```bash
for v in 8.1 8.2 8.3 8.4 8.5; do
  find Classes Configuration Tests ext_*.php -name '*.php' -print0 \
    | xargs -0 -n1 /opt/homebrew/opt/php@$v/bin/php -l | grep -v '^No syntax errors' || true
done
```

Expected: no output (every file "No syntax errors").

## Unit tests and PHPStan on PHP 8.5 (Docker)

The CI image `himanshuramavat/php-ci` has no 8.5 tag yet, so build a local one from the official image:

```bash
mkdir -p /tmp/php85-ci && cat > /tmp/php85-ci/Dockerfile <<'EOF'
FROM php:8.5-cli
RUN apt-get update && apt-get install -y --no-install-recommends git unzip libicu-dev libzip-dev \
 && docker-php-ext-install intl zip && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
EOF
docker build -t ns-t3al-php85-ci /tmp/php85-ci
```

Then, per `T3=^12.4 | ^13.4 | ^14.3`, on a throw-away copy (as above) mounted at `/app`:

```bash
docker run --rm -v "$DIR":/app -w /app ns-t3al-php85-ci bash -c '
  composer require --no-update --no-interaction "typo3/cms-core:'$T3'" "typo3/cms-backend:'$T3'" \
    "typo3/cms-extbase:'$T3'" "typo3/cms-extensionmanager:'$T3'" "typo3/cms-fluid:'$T3'" &&
  composer update --no-interaction --no-progress &&
  .Build/bin/phpunit -c Build/phpunit/UnitTests.xml --display-deprecations && composer stan'
```

Expected: same test count as on the other versions, no PHP deprecations, PHPStan `[OK]`. The v14 DDEV instance itself runs PHP 8.5, so live QA there covers 8.5 too.

## Live check on real instances

Local DDEV projects (classic mode, each with ns_license + ns_t3al):

| Major | Directory | DDEV name |
|---|---|---|
| 12.4 | `~/www/Projects/typo3_src-12.4.45` | `typo3-v12` |
| 13.4 | `~/www/Projects/typo3_src-13.4.35` | `typo3-src-13.4.35` |
| 14.3 | `~/www/Projects/typo3_src-14.3.6` | `typo3-src-14.3.6` |

- Run **one** extra project at a time: v12 and v13 share DB port 55005 and three projects at once can crash Docker Desktop.
- Before testing set up the instance as in `tasks/qa-environment.md` (deploy, license isolation, QA admin) and clean it up afterwards.
- Which cases to run per major: `context/manual-testing.md` → Version matrix (**core** rows at least).
