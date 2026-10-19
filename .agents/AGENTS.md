# .agents/

Agent-agnostic configuration for `ns_t3al` following [AGENTS.md](https://agents.md) and [Agent Skills](https://agentskills.io) conventions.

## Layout

- `skills/` — Shared skills (committed). Each directory contains `SKILL.md`.
- `skills-local/` — Private skills (gitignored).
- `scripts/link-skills.sh` — Symlinks skills into `.claude/skills/` and `.cursor/skills/`.

## Adding a skill

1. Create `.agents/skills/nst3al-<name>/SKILL.md` with `name` + `description` frontmatter.
2. Run `.agents/scripts/link-skills.sh`.
3. Commit the skill directory and the new symlinks; add it to the Skills table in `AGENTS.md`.

## Rules

- Edit skills in `.agents/skills/`, never in `.claude/skills/` or `.cursor/skills/` (those are symlinks).
- Edit agent context in `context/` and routers in `AGENTS.md` / `CLAUDE.md`.
- Skills hold workflows and checklists; facts stay in `context/` (link, don't copy).
- Deep implementation specs live in `context/specs/FEATURE_*.md` — summarize in `context/features/*.md` only.
