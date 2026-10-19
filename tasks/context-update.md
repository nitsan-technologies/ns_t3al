# Context Update Guide

> Before any edit, follow `context/principles.md`.

| What changed | Update this file |
|---|---|
| Operating principles / guardrails | `context/principles.md` |
| Identity, requires, ext_conf keys, v12–v14 compatibility | `context/core.md` |
| Actions, request path, events, caches, persistence, file map, unit test layout | `context/architecture.md` |
| Doc index | `context/docs-map.md` |
| UX snapshot, intentional removals, dated log | `context/session-state.md` |
| Translate grid, filters, Search, Logs, Guide | `context/features/translate-module.md` |
| AI providers, Translate / Save, Crowdin, CSV | `context/features/ai-and-crowdin.md` |
| Permissions, editor lock | `context/features/access-control.md` |
| Manual QA cases | `context/manual-testing.md` |
| Quality commands | `tasks/run-quality.md` |
| Version matrix procedure | `tasks/version-matrix.md` |
| QA set-up / clean-up | `tasks/qa-environment.md` |
| Deep spec | `context/specs/` (new file) |
| Router / feature table / skills | `AGENTS.md` (minimal edits only) |
| New skill | `.agents/skills/<name>/SKILL.md` + `.agents/scripts/link-skills.sh` |

**Do not** paste full specs into `context/features/` — link to `context/specs/FEATURE_*.md` if depth is needed.

After landing a feature: append 3–5 lines to `context/session-state.md` → Log with date + summary, and add the manual check to `context/manual-testing.md`.
