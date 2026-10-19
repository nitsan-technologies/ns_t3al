# ns_t3al — session state

Current product UX snapshot after the Translate-module redesign. Agents must treat these as intentional — do not “restore” removed controls unless the user explicitly asks.

## Present (keep)

| Area | State |
|---|---|
| Docheader tabs | AF/AA-style `nav-pills`: Translate · Search · Logs |
| Guide | Right-side button → TYPO3 modal (`@typo3/backend/modal.js`, not Bootstrap); Supademo **`/embed/`** URL |
| Extension type | Local / System / All (default Local; module-data persist) |
| Extension pick | searchable combobox (title + key) |
| Language pick | Searchable multi-select combobox + chips |
| Label editor | UI-only spreadsheet grid (no CodeMirror branch) |
| AI fill | Always **missing-only** (skip non-empty targets) |
| Toolbar | AI providers + Crowdin project ID (system extensions only); selected provider persists (`apiModel` module data + `selectedApiModel` hidden) until user changes it |
| Editor actions | Translate · Save · Save to Crowdin (framework only) · Export CSV · Export ZIP (non-framework, `enableExport`) · Reload; Save / Create file redirect (PRG) |
| Logs | 20 per page, Previous / Next, retention `logRetentionDays`; rows without a diff are not listed |
| Row actions | Single compact `.ns-t3al-col-actions` flex column (add/del/move) |
| Core DocHeader | Disabled; custom `DocHeaderTabs` owns chrome |
| Module chrome | Same as ns_t3af: flex shell (`.ns-t3al-module-shell`), core `module-docheader` classes on the tabs, `PageHeader` partial (title + intro + extension badge), core `card`s for filters / editor / results, `FooterLinks` |
| Theming | Colors via TYPO3 tokens → `light-dark()` → `[data-color-scheme=dark]`; works in v14 "auto" (no attribute), explicit light/dark and v12 (always light). No hardcoded light-only surfaces |

## Removed from main module UX (do not reintroduce)

| Removed | Notes |
|---|---|
| **Import ZIP** UI / PHP action | ZIP **export** stays (editor action → `exportAction` streams the ZIP) |
| **Legacy Localization modal UI** | AJAX controllers + routes, Localization partials, code editor (t3editor), `ALModalManager.js` / `actions.js` / `Style.css`, `enableManualtranslation` / `enableLocalization` settings — all deleted; no AJAX routes remain |
| **Extension Manager list override** | page TSconfig + TypoScript + forked `List/Index.html` + compat ViewHelper removed; use module deep links |
| **UI / XLF** editingMode toggle | Spreadsheet only; no `loadCodeMirror` on list |
| **Missing only / Overwrite all** dropdown | Hard-coded missing-only in `translateAction` |
| **Guide as nav tab** | Button + modal only; `guideAction` is deep-link/auto-open |

## Release archive

`.gitattributes` marks `context/`, `tasks/`, `AGENTS.md`, `CLAUDE.md`, `.agents/`, `.claude/`, `.cursor/`, `Build/`, `Tests/`, `.gitignore`, `.gitattributes`, `.gitlab-ci.yml`, `.php-cs-fixer.php`, `phpstan.neon` as `export-ignore` (not in `git archive` / Packagist dist).

## Known gaps (not bugs)

- `ModuleController` is ~1500 lines (one controller by design); split into action-specific services only with a spec in `context/specs/`.
- The Supademo Guide demo (content, not code) still walks through the removed Extension Manager "T3AI Localization" column; it needs re-recording for the Admin Tools module.
- TYPO3 v12 below 1024px: the expanded module menu (`z-index` 1051) covers every core modal (1010), including the Guide. Core behaviour, not fixed in the extension.
## Log

- **2026-10-14** — UI aligned with ns_t3af: in v14 "auto" color mode (no `data-color-scheme` attribute) the docheader, AI toolbar and missing-translation cells stayed light on a dark backend and the inactive tabs were unreadable. Tokens now resolve through `light-dark()`; layout uses the AF flex shell, core docheader classes, page header with intro, cards (filter, editor with actions header + AI provider row + flush grid, search results, logs filter) and the AF footer. New labels `title.*.intro`, `field.*`, `toolbar.provider`, `footer.*` (EN + DE). Filter cards allow overflow so combobox menus are not clipped. Editor card: AI provider row (title + provider buttons, key warning / models below, Crowdin ID right), then one action row (CSV import + Sort by key left; Translate / Save / Reload and Export CSV / ZIP button groups right; Save and Reload icon-only); AF soft button colours; unsaved Save is green (`btn-success`, also in `unsaved-changes.js`); row actions are a `btn-group` under an "Actions" header (`label.actions`), delete asks for confirmation (`row.delete.*`, core Modal); new rows are tinted and do not copy cell states; grid textareas grow with their content (no inner scrollbar); striped rows; loader uses core `<typo3-backend-spinner>`. 334 unit tests green; live-verified on v14 in auto-light, auto-dark and explicit dark.
- **2026-10-12** — Ticket uj5d: German backend showed the module in English because `locallang_mod.xlf` (module name, tabs, buttons, grid headers, Search, Logs, all `translateMod()` flash messages, unsaved-changes modal) had no German file — `de.locallang_mod.xlf` added for all units. The hardcoded `Date:` in `Partials/Logs.html` now uses `locallang_mod.xlf:logs.date` (stray `</span>` removed). `LanguageFilesTest` also checks that translations keep the sprintf placeholders of their source (a lost `%s` throws on PHP 8). 319 unit tests on 12.4.45 / 13.4.36 / 14.3, PHPStan, cs clean; live-verified on v14 in German and English.
- **2026-10-12** — Ticket uj5e: Create file copied `Resources/Private/Templates/Empty.xlf` (empty `original` / `date` / `product-name`, a dummy `new` unit, space indentation). It now writes the file with the new `XliffFileGenerator` (also behind `XliffUtility::generateXml()`, so first-time translation files share the layout): `original` with the file name, UTC `date`, `product-name`, tabs, `<header/>`, no units — the grid's blank row for an empty file takes the first label. Template deleted. 324 unit tests on 12.4.45 / 13.4.36 / 14.3, PHPStan, cs clean; live-verified on v14 (create, first key, first German save, labels resolve in TYPO3).
- **2026-10-12** — Ticket uj5f: the two-user warning rounded `(t + 30) / 60` (so "0 minutes" for the first 30 s), always used the plural, said "this extension" although the lock is per file, and ignored the username stored in the lock. New `Backend/View/ConcurrentEditNotice` builds "<username> started editing the file <file> less than a minute / 1 minute / N minutes ago." ("Someone else" for a lock without username); labels `concurrent_edit.someone` + `concurrent_edit.message.{lessThanMinute,oneMinute,minutes}` (EN + DE) replace `concurrent_edit.message`. Live-verified on v14 with two backend users (EN + DE).
- **2026-10-12** — PHP 8.5 check of all uncommitted work (2gn … uj5f): 334 unit tests with `--display-deprecations` on PHP 8.5.11 for TYPO3 12.4.45 / 13.4.36 / 14.3.8, PHPStan clean on all three, lint clean — zero PHP deprecations (Docker recipe in `tasks/version-matrix.md`). Same 16-job `.gitlab-ci.yml` matrix green locally on PHP 8.1–8.4. The v14 DDEV instance runs PHP 8.5.3, so today's live QA was on 8.5.
- **2026-10-12** — Dead-code sweep: `shipmonk/dead-code-detector` (throw-away copy, TYPO3 entry points declared: Extbase actions, event listeners, DI constructors, domain model) plus checks of labels, templates/partials, JS, CSS and extension settings. Only real dead code: `CrowdinService::fetchFileContent()` (no caller since the initial commit) — removed; its URL-encoding test now uses `fetchFile()`. Not dead despite looking so: `LogsRepository::initializeObject()` (called by TYPO3 DI), `mlang_*` labels (module title via `Modules.php`), JS `consume()` (backend navigation guard), `.icon-actions-toggle-*` CSS (core icon markup).
- **2026-10-12** — Ticket uj43: "Nothing to translate" no longer turns Save red — `listAction` marks a handed-back grid as changed only when it differs from the files (`GridLabels::differ()`) or keys are renamed, so unsaved edits made before Translate stay red. Save with no edits already skipped untouched files, but browsers post textarea line breaks as CRLF: every multi-line label looked edited, Save reported success and wrote `&#xD;` into the file — posted grids are now normalised to LF (`GridLabels::normalizeLineBreaks()`). A translation `0` on a target with attributes was read as empty (and deleted on Save) — fixed in `XliffUtility::simpleXMLElementToArray()`. 318 unit tests on 12.4.45 / 13.4.36 / 14.3 (PHP 8.4), PHPStan, cs clean; live-verified on v14 (Translate, Save, real edit, CSV import).
- **2026-10-08** — Ticket uc2n (follow-up after e4aa209): the 3 missing Translate notice labels added (EN + DE); `de.locallang.xlf` complete (5 missing keys, `form.ext` target, empty duplicate of `translation.write.crowdin.success.title` removed) and guarded by `LanguageFilesTest`; clearing a translation removes its whole unit from the target file (TYPO3 v12–v14 render a unit without target text as an empty label); Gemini 5xx / 429 now set `retryLater` (shared `ProviderErrorTrait::isRetryLaterStatus()`, 429 also stops batches for the other providers); log diff compares the last duplicate like TYPO3. Restored the `$packagePath` line in `LogsViewDataProvider` that went missing after the 2gn fix. 301 unit tests on 14.3 (+ 299/299 on 12.4.45 PHP 8.1 and 13.4.35 PHP 8.2 before the last 2 tests), PHPStan, cs clean; live-verified on v14 with a German admin.
- **2026-10-08** — Ticket 2gn re-review (m8): `LogsViewDataProvider` gets `ListUtility` by constructor injection instead of `makeInstance()`; `LogsViewDataProviderTest` added (fails on the old code). 284 unit tests, PHPStan, php-cs-fixer clean; Logs tab live-verified on v14 and the compiled DI container passes `ListUtility`.
- **2026-10-08** — GitLab pipeline (`.gitlab-ci.yml`, modelled on ns_t3aa): unit tests TYPO3 12/13/14 × PHP 8.1–8.4, lint, php-cs-fixer, PHPStan level 3 per major; `.php-cs-fixer.php`, `phpstan.neon`, composer `stan` / `cs:*` / `ci`. PHPStan found a real bug: `LogsRepository::DEFAULT_RETENTION_DAYS` was missing, so log retention failed on installs without a saved `logRetentionDays`.
- **2026-10-08** — 2m7 QA fixes: Logs skip rows without a diff (pagination / language filter / "no logs" notice were off); Guide uses the TYPO3 Modal API (did nothing on v14, `GuideModal.html` + its CSS removed); CSV import "updated" counts only real text changes; Export ZIP refused because of `enableExport` / system extension now says "Export ZIP not available" instead of "Access denied". Live-verified on v14 (all tabs), v12 (Guide, Logs) and v13 (all tabs, Export ZIP, Guide); 281 unit tests green on 12.4.45 (PHP 8.1/8.4), 13.4.35 (8.2/8.4) and 14.3; lint 8.1–8.5 clean.
- **2026-10-07** — Unused code removed: `Service/FlashMessageService` (logs warning uses Extbase `addFlashMessage()`), `Logs::$status` / `getSourceFile()`, unused `t3version` view / JS setting, `t3al-loading` icon, JS `showLoader()` / `hideNsT3alLoader()` (Crowdin notice via `showNsT3alError()`). `Setup::executeOnSignal()` → `onAfterPackageActivation()`.
- **2026-10-07** — v12/v13/v14 compatibility verified: 280 unit tests on 12.4.45 (PHP 8.1/8.4), 13.4.35 (8.2/8.4), 14.3.7; lint 8.1–8.5; live QA on v12 + v13 DDEV. Test-only fixes for PHP 8.1 and v12/v13 core APIs (`context/core.md` → Compatibility).
- **2026-10-07** — Agent context completed to the NITSAN standard: principles, docs map, `tasks/`, `.agents/` skills, `CLAUDE.md`, Cursor rule.

## When updating this file

After landing UX or flow changes, update this snapshot and the matching feature doc under `context/features/`, and add a dated line to Log (keep it short — prune entries older than a few releases). Keep `AGENTS.md` feature status in sync.

## 2026-10-19 — extension is free

- Removed the license check (`Service/LicenseCheckService`, listeners `Hooks/BackendUserLogin`, `Setup`, their `Services.yaml` entries and unit tests). `nitsan/ns-license` stays a required dependency (Free pattern); no license activation is needed.
- `context/features/access-and-license.md` renamed to `context/features/access-control.md`.

## 2026-10-19 — Crowdin fixes

- Exact file / directory path match, sub folders, TYPO3 → Crowdin language ids, `limit=500` paging, distinct auth / project / file / language errors, configurable `crowdinSystemExtensionPath`, normalized `crowdinApiUrl`, password field for `accessToken`. v14: dummy token → "Crowdin rejected the access token (HTTP 401)". Not verified with a real token.
