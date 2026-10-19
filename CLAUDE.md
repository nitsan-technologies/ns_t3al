@AGENTS.md

Claude-only notes:

- Prefer `context/features/<area>.md` over `context/specs/` unless implementing; load `context/manual-testing.md` only for QA.
- Update `context/session-state.md` → Log at the end of a session (`nst3al-context-update`).
- Skills: `.agents/skills/` — run `.agents/scripts/link-skills.sh` after adding skills.
- Never write TYPO3 Core English XLF files; never print API keys, the Crowdin token or ns_license settings.
