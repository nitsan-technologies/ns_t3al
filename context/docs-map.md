# Docs Map — ns_t3al

*Index for fetch-on-demand. Store WHAT each source answers + path — not full page content.*

---

## Agent context (read first for coding)

| Need | Path |
|---|---|
| Router / what to load | `AGENTS.md` |
| Principles + guardrails | `context/principles.md` |
| Identity, requires, ext_conf keys, v12–v14 compatibility | `context/core.md` |
| Actions, events, caches, persistence, file map, unit tests | `context/architecture.md` |
| Translate grid, filters, Search, Logs, Guide | `context/features/translate-module.md` |
| AI providers, Save, Crowdin, CSV | `context/features/ai-and-crowdin.md` |
| Permissions, editor lock | `context/features/access-control.md` |
| Intentional UX state / removals, dated log | `context/session-state.md` |
| Manual QA checklist + version matrix | `context/manual-testing.md` |
| Deep specs (on demand) | `context/specs/` |
| Workflows | `tasks/*.md` |
| Skills | `.agents/skills/*/SKILL.md` |

---

## Code entry points

| Question | Start here |
|---|---|
| Which actions exist? | `Configuration/Backend/Modules.php` |
| How is a request handled? | `Classes/Controller/ModuleController.php` (one controller, ~1500 lines) |
| What does the grid show? | `Classes/Backend/View/EditorViewDataProvider.php`, `Resources/Private/Partials/Module/LabelGrid.html` |
| How is an XLF written? | `Classes/Utility/XliffDocumentUpdater.php`, `XliffFormatRestorer.php`, `XliffUtility.php` |
| DI / event listeners | `Configuration/Services.yaml` |
| Caches, permission options | `ext_localconf.php` |
| Settings | `ext_conf_template.txt` |
| Log table | `ext_tables.sql`, `Configuration/TCA/tx_nst3al_domain_model_logs.php` |
| Labels | `Resources/Private/Language/locallang.xlf` (module), `locallang_mod.xlf` (module title, permissions), `locallang_db.xlf` (TCA); German `de.*` |
| Browser behaviour | `Resources/Public/JavaScript/module-functionality.js`, `module-ui.js`, `unsaved-changes.js` |

---

## User-facing documentation

| Topic | Path |
|---|---|
| Install, configuration, usage | `README.md` |
| Product page | https://t3planet.de/t3al-typo3-erweiterung |
| Support | https://t3planet.de/support |

---

## Common developer questions → where to look

| Question | Load |
|---|---|
| Why did Save rewrite / not rewrite a file? | `context/features/ai-and-crowdin.md` → Save action |
| Why is a moved row not kept / why did order change? | `context/features/translate-module.md` → Row actions |
| AI filled the wrong key / left labels empty? | `context/features/ai-and-crowdin.md` → Structured, count-checked requests |
| DeepL "unsupported language"? | `context/features/ai-and-crowdin.md` → DeepL target languages |
| Editor cannot see an extension? | `context/features/access-control.md` |
| Test fails only on v12 / v13? | `context/core.md` → Compatibility, `tasks/version-matrix.md` |
| Labels stay old after Save? | `context/architecture.md` → Persistence (`flushLabelCache()`) |
| How do I QA without side effects? | `tasks/qa-environment.md` |
