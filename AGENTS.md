# AGENTS.md — ns_t3al

Agent router for the **AI-Powered XLIFF Translation** TYPO3 extension (edit extension XLIFF files in a backend grid, AI fill, Crowdin upload). Load only what the task needs.

> **Principles:** `context/principles.md`  
> **Session log:** `context/session-state.md`  
> **Doc index:** `context/docs-map.md`  
> **Deep specs:** `context/specs/` (load on demand)

---

## Always load

| File | Why |
|---|---|
| `context/principles.md` | Goals + non-negotiable guardrails (one write path, minimal XLF diffs, secrets, free, no license check) |
| `context/core.md` | Identity, requires, ext_conf keys, v12–v14 compatibility traps |
| `context/session-state.md` | Current UX state and intentional removals (do not reintroduce) |

---

## Task router

| Task | Load |
|---|---|
| Translate editor UI, filters, comboboxes, grid, row move, unsaved guard, Search, Logs, Guide | `context/features/translate-module.md` |
| AI providers / models, missing-only translate, Save (in-place), Crowdin, CSV | `context/features/ai-and-crowdin.md` |
| Permissions, non-admin editors, editor lock | `context/features/access-control.md` |
| Actions, events, caches, persistence, file map, unit test layout | `context/architecture.md` |
| Run tests / lint | `tasks/run-quality.md` |
| v12 / v13 / v14 compatibility check | `tasks/version-matrix.md`, `context/core.md` → Compatibility |
| Manual / smoke QA | `context/manual-testing.md`, `tasks/qa-environment.md` |
| Update agent context after a landing | `tasks/context-update.md` |

Prefer `context/features/*.md` over `context/specs/` unless implementing.

---

## Feature status

| Feature | Status | Agent entry |
|---|---|---|
| Translate editor (list grid) | Done | `context/features/translate-module.md` |
| Local / System / All filter | Done | `context/features/translate-module.md` |
| Searchable extension + language comboboxes | Done | `context/features/translate-module.md` |
| Row add / delete / move (order persisted on Save) | Done | `context/features/translate-module.md` |
| Unsaved-changes guard | Done | `context/features/translate-module.md` |
| Search tab | Done | `context/features/translate-module.md` |
| Logs tab (paginated, retention) | Done | `context/features/translate-module.md` |
| Guide modal (Supademo) | Done | `context/features/translate-module.md` |
| AI translate (missing-only; OpenAI, Gemini, DeepL, Google) | Done | `context/features/ai-and-crowdin.md` |
| In-place XLF save | Done | `context/features/ai-and-crowdin.md` |
| Crowdin save (system extensions only) | Done | `context/features/ai-and-crowdin.md` |
| CSV import / export | Done | `context/features/ai-and-crowdin.md` |
| Export ZIP (module action) | Done | `context/architecture.md` |
| Per-extension permissions for editors | Done | `context/features/access-control.md` |
| Free: ns_license dependency only, no license check | Done | `context/features/access-control.md` |
| TYPO3 12.4 / 13.4 / 14.3 compatibility | Done — verified 2026-10-07 | `context/core.md` |
| Crowdin for local extensions | Open (not offered by design) | `context/manual-testing.md` → High #5 |
| EM Localization modal / AJAX | Removed | `context/session-state.md` |

---

## Skills (`.agents/skills/`)

| Skill | Use |
|---|---|
| `nst3al-xliff-save` | Grid / Save / row move / key rename / Create file / CSV / Export ZIP |
| `nst3al-ai-provider` | Add or change AI providers / models, batching, Crowdin |
| `nst3al-test` | Unit tests, v12–v14 matrix, manual QA without side effects |
| `nst3al-context-update` | Maintain `context/` after features |

Symlinks: run `.agents/scripts/link-skills.sh` after adding skills.

---

## Quick commands

```bash
# Extension root
composer install && composer test
composer ci          # test + PHPStan + php-cs-fixer dry run (= GitLab pipeline, tasks/run-quality.md)

# TYPO3 root (DDEV, classic mode)
ddev exec php typo3/sysext/core/bin/typo3 cache:flush
ddev exec 'kill -USR2 $(pgrep -o php-fpm)'   # OPcache after PHP changes
```

Entry point: Admin Tools → **AI Localization** (`tools_NsT3al`, `/module/tools/ns-t3al`) → `ModuleController::listAction`.

**Do not restore:** Import ZIP UI, UI/XLF CodeMirror toggle, Missing only/Overwrite dropdown, Guide as a nav tab, the legacy Localization modals / AJAX routes, the Extension Manager list override — see `context/session-state.md`.
