---
name: nst3al-context-update
description: Maintain ns_t3al context/, tasks/ and AGENTS.md after landing a change. Use after any ns_t3al feature, bugfix, removal or compatibility change, or when the user asks to update the ns_t3al agent context.
---

Follow `tasks/context-update.md` and `context/principles.md`.

After landing work:

1. Update the matching `context/features/<area>.md` (or `core.md` / `architecture.md`) — the one file that owns the fact
2. Append 3–5 lines to `context/session-state.md` → Log (date + summary); add removed UI to "Removed … (do not reintroduce)"
3. Add or adjust the manual check in `context/manual-testing.md`
4. If a deep spec is needed, add it under `context/specs/` and link it from the feature summary
5. Minimal edits to `AGENTS.md` (feature table / router / skills) only when status or routing changes
6. Verify every class, method, constant, file and config key you mention exists (`rg` it) — stale context is worse than none

DRY rules:

- Each fact lives in ONE file; other files link to it
- Do not paste full specs into feature summaries
- Never write secrets, API keys or license data into context
