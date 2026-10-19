# ns_t3al — core

## Purpose

**AI-Powered XLIFF Translation** is a TYPO3 backend Admin Tools extension for editing extension XLIFF files (`Resources/Private/Language/*.xlf`) in a spreadsheet-style grid, filling empty targets with AI (OpenAI / Gemini / DeepL / Google Translate), saving locally and/or uploading to Crowdin, plus cross-extension search and translation logs.

## Identity

| Item | Value |
|---|---|
| Extension key | `ns_t3al` |
| Title / description | `composer.json` `description` = `AI-Powered XLIFF Translation - <short description>`: TYPO3 v14 takes the title from the text before the first ` - ` (Breaking #108304). `ext_emconf.php` `title` / `description` hold the same two parts. No URLs in either; product page in `homepage`, support in `support.issues` (both `t3planet.de`). Guarded by `Tests/Unit/ExtensionMetadataTest.php` |
| Extbase name | `NsT3AL` |
| Version | `1.0.0` (see `ext_emconf.php`) |
| Category | Backend (`be`) |
| TYPO3 | `12.4.0–14.9.99` (Composer: `^12.4 \|\| ^13.4 \|\| ^14.3`) |
| Depends | `typo3/cms-core`, `cms-backend`, `cms-extbase`, `cms-extensionmanager` (`ListUtility`), `cms-fluid` — all declared in `composer.json` + `ext_emconf.php`; `ns_license` `14.5.0–14.9.99` (Composer `nitsan/ns-license: ^14.5`) |
| License | `GPL-2.0-or-later` (`composer.json`) |
| Optional | `typo3/cms-install` (v12/v13 `LanguagePackService`) — not required; `Service/ActiveLanguagesProvider` falls back to `EXTCONF.lang.availableLanguages` when absent. No `suggest` / `suggests` |
| Licensing | Free. `ns_license` is a required dependency only; ns_t3al has no license check — see `features/access-control.md` |
| Module id | `tools_NsT3al` |
| Parent | Admin Tools (`tools`) |
| Path | `/module/tools/ns-t3al` |
| Controller | `NITSAN\NsT3AL\Controller\ModuleController` |

## Extension configuration (`ext_conf_template.txt`)

### AI providers

| Key | Role |
|---|---|
| `openaiApiKey` / `openaiApiModel` | OpenAI chat models |
| `geminiApiKey` / `geminiApiModel` | Gemini flash models |
| `deeplApiKey` / `deeplApiUrl` | DeepL translate API |
| `googleApiKey` / `googleApiUrl` | Google Translate v2 |

### Crowdin

| Key | Role |
|---|---|
| `accessToken` | Crowdin API token (password field, `Configuration/PasswordField`) |
| `crowdinApiUrl` | Default `https://api.crowdin.com/api/v2/` (trailing slash optional) |
| `crowdinSystemExtensionPath` | Default `/main/typo3/sysext` (Crowdin directory of the system extensions) |

Crowdin upload is offered for **system extensions only** (TYPO3 Core Crowdin project layout).

### Feature flags

| Key | Default | Role |
|---|---|---|
| `enableLogs` | `1` | Write a translation log row (with diff) on every Save |
| `enableExport` | `1` | "Export ZIP" editor action (module) |
| `logRetentionDays` | `365` | Translation log rows older than this are deleted when a new row is written; `0` keeps all |

## Compatibility (v12 / v13 / v14)

One code base for all three majors — no version branches in the code except where listed below.

| TYPO3 | PHP floor | Unit tests verified (2026-10-07) |
|---|---|---|
| 12.4 | **8.1** | 12.4.45 on PHP 8.1 + 8.4 |
| 13.4 | 8.2 | 13.4.35 on PHP 8.2 + 8.4 |
| 14.3 | 8.2 | 14.3.7 on PHP 8.4; live instance 14.3.6 on PHP 8.5 |

Code must lint on PHP 8.1–8.5. Traps found while testing:

| Trap | Rule |
|---|---|
| PHP 8.1 | No `never` return type on arrow functions (`fn(): never => throw …` is a fatal error before 8.2); no 8.2+ functions / readonly classes / DNF types |
| Cache backends | v12/v13 `AbstractBackend::__construct($context, array $options = [])`, v14 `__construct(array $options = [])` — tests avoid real backends and stub `FrontendInterface` get/set instead (`Tests/Unit/Support/DeepLTestDoublesTrait.php`); a `Typo3Version` branch fails PHPStan on the other majors |
| Optional core classes | Classes that exist in only some majors (e.g. `LanguagePackService`: EXT:install on v12/v13, EXT:core on v14) — reference them only inside `if (class_exists(X::class))`, never via constant arrays / loops, so PHPStan passes on every major (CI `stan` runs per major) |
| Locales | v12/v13 `Locales::getLanguages()` contains `'default' => 'English'`, v14 does not — production code drops `default`; tests must skip it |
| Fluid | Layout stays `Layouts/Module.html` (v12/v13 Fluid only resolves `.html`, not `.fluid.html`) |
| Module URLs | v12/v13 need the route token — in QA open the module via `top.TYPO3.ModuleMenu.App.showModule('tools_NsT3al')`, not a bare `/typo3/module/tools/ns-t3al` |
| Extension title | v14 reads the title from `composer.json` `description` before ` - ` (Breaking #108304) |
| Severity | Use `ContextualFeedbackSeverity` (exists since v12), never the removed `AbstractMessage` constants |
| Bootstrap JS | v14's bundled Bootstrap exports only Carousel / Collapse / Popover — `data-bs-toggle="modal"` / `"tab"` / `"dropdown"` do nothing. Use the TYPO3 APIs (`@typo3/backend/modal.js`, same on v12–v14) |
| Module labels | `locallang_mod.xlf` keys `mlang_tabs_tab` / `mlang_labels_tabdescr` / `mlang_labels_tablabel` work on v12–v14 |

Commands: `tasks/run-quality.md`; how to run the matrix: `tasks/version-matrix.md`.

## Primary product surface

One Admin Tools module owns the product UX:

- Tabs: **Translate** · **Search** · **Logs**
- Right-side **Guide** button → Supademo demo in a TYPO3 modal (not a tab)

Deep detail: `features/translate-module.md`, `features/ai-and-crowdin.md`.
