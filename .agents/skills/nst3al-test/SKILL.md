---
name: nst3al-test
description: Verify an ns_t3al change - unit tests, PHP lint 8.1-8.5, the TYPO3 v12/v13/v14 matrix and manual backend QA on DDEV without side effects. Use before marking any ns_t3al task done, for compatibility checks, or when the user asks to test or QA ns_t3al.
---

1. **Unit tests** — `composer test` in the extension (`tasks/run-quality.md`). New behaviour needs a new test.
2. **Matrix** (core APIs, caches, Locales, Fluid, PHP syntax touched) — `tasks/version-matrix.md`: v12 on PHP 8.1, v13 on PHP 8.2, v14; lint on 8.1–8.5. Same test count everywhere.
3. **Manual QA** — set up and clean up with `tasks/qa-environment.md` (baseline, license isolation, temp users), run the matching sections of `context/manual-testing.md`. On v12/v13 open the module via `top.TYPO3.ModuleMenu.App.showModule('tools_NsT3al')`.
4. **Check** — `git diff` of saved XLF shows only intended lines; no ns_t3al entries in `var/log/typo3_*.log`; no raw label keys in the UI.
5. **Clean up** — instance back to baseline (users, sessions, registry row, log rows, `apiBaseUrl`, files, caches, DDEV state).
6. **Report** — outcome first; per major what was verified; what was not tested and why (e.g. no API key).

Never let QA contact the real license server or a paid AI API without the user's consent; never print keys.
