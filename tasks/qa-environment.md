# QA environment — set up, isolate, clean up

> Follow `context/principles.md` → Guardrails. Test cases: `context/manual-testing.md`. Which instances: `tasks/version-matrix.md`.

Every QA session must leave the instance as it was: same files, same DB rows, same settings.

## 1. Note the baseline

```bash
ddev mysql -e "SELECT COALESCE(MAX(uid),0) FROM tx_nst3al_domain_model_logs; \
  SELECT entry_key FROM sys_registry WHERE entry_namespace='ns_t3al';"
git -C typo3conf/ext/ns_t3al status --short   # also for every extension whose XLF you will save
```

## 2. Deploy the working copy (other instances only)

```bash
rsync -a --delete --exclude .git --exclude .Build --exclude var --exclude composer.lock \
  ~/www/Projects/typo3_src-14.3.6/typo3conf/ext/ns_t3al/ <instance>/typo3conf/ext/ns_t3al/
ddev exec php typo3/sysext/core/bin/typo3 cache:flush
```

(`--exclude .git` keeps the target's own git checkout so it can be reset in step 6.)

## 3. Keep QA away from the license server

ns_t3al itself never contacts the license server (it is free, no license check). ns_license and other NITSAN extensions on the instance may still do so on login, so keep this isolation. ns_license reads `EXTENSIONS.ns_license.apiBaseUrl`. Point it at a closed port before the first login:

```bash
ddev exec "php -r '\$f=\"typo3conf/system/settings.php\"; \$c=require \$f; \
  \$c[\"EXTENSIONS\"][\"ns_license\"][\"apiBaseUrl\"]=\"http://127.0.0.1:9/\"; \
  file_put_contents(\$f, \"<?php\nreturn \".var_export(\$c,true).\";\n\");'"
```

Never print or copy other ns_license settings (e.g. secrets) — only check whether keys are set.

## 4. Temporary users

```bash
ddev exec php typo3/sysext/core/bin/typo3 backend:user:create -n -a -u t3al_qa_admin -p '<random>' -e qa@example.com
ddev exec php typo3/sysext/core/bin/typo3 backend:user:create -n -u t3al_qa_editor -p '<random>' -e qa@example.com -g <groupUid>
```

The editor's group needs module access to `tools_NsT3al` and selected extensions under Custom module options (see `context/features/access-control.md`).

On v12/v13 open the module with `top.TYPO3.ModuleMenu.App.showModule('tools_NsT3al')` (module URLs need a token).

## 5. Test

Run the cases from `context/manual-testing.md`. Use git-clean extensions for saves (`ns_t3al` itself, `ns_personio`, …) so every change shows in `git diff`.

## 6. Clean up (in this order)

```bash
# DB: QA users, sessions, registry row written by the login, new log rows
ddev mysql -e "DELETE FROM sys_registry WHERE entry_namespace='ns_t3al'; \
  DELETE FROM be_sessions WHERE ses_userid IN (SELECT uid FROM be_users WHERE username LIKE 't3al_qa_%'); \
  DELETE FROM be_users WHERE username LIKE 't3al_qa_%'; \
  DELETE FROM tx_nst3al_domain_model_logs WHERE uid > <baseline max uid>;"
# Only delete the registry row if it did not exist in the baseline.

# Settings: remove the license override again
ddev exec "php -r '\$f=\"typo3conf/system/settings.php\"; \$c=require \$f; \
  unset(\$c[\"EXTENSIONS\"][\"ns_license\"][\"apiBaseUrl\"]); \
  file_put_contents(\$f, \"<?php\nreturn \".var_export(\$c,true).\";\n\");'"

# Files: reset saved XLF / deployed copies, remove created l10n files
git -C typo3conf/ext/<ext> checkout -- . && git -C typo3conf/ext/<ext> clean -fdq
ddev exec php typo3/sysext/core/bin/typo3 cache:flush
```

- `var_export` rewrites `settings.php` formatting (content unchanged) — mention it in the report.
- Saving a System extension translation creates files under `typo3conf/l10n/<lang>/…` (classic) or `var/labels/…` (Composer): delete them.
- Stop DDEV projects that were stopped before the session.

## 7. Report

Lead with the outcome, list what was verified per major, name anything not tested and any instance state that differs from the baseline.
