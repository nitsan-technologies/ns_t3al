# ns_t3al — Operating Principles

*The common rule. Every change to context, code, or docs must serve the three goals below.*

---

## Goal 1 — Correct, maintainable code

Ship features that match `context/architecture.md` and `context/features/*.md`.

- **One UI, one write path:** the Admin Tools module `tools_NsT3al` (`ModuleController`) owns the whole UX; every XLF write goes through `saveAction` / `createFileAction`. No AJAX routes, no second UI, no Extension Manager override.
- **Save is the persistence model:** AI results and CSV imports live in the grid until **Save**; Save writes XLF **in place** with the smallest possible diff.
- **One code base for TYPO3 12.4 / 13.4 / 14.3** and PHP 8.1–8.5 (`context/core.md` → Compatibility).
- Reuse the existing single sources: `ExtensionAccessService::canEdit()` (permissions), `AiProviderResolver` (provider → key map), `XliffUtility` (parse / path guards), `XliffDocumentUpdater` + `XliffFormatRestorer` (writes), `ProviderErrorTrait` (provider errors).
- New behaviour in a Utility / Service / ViewDataProvider comes with a unit test.

## Goal 2 — Token-cost efficiency (agent context)

- **DRY context:** each fact lives in exactly ONE file. Reference, don't copy.
- **Load-on-demand:** read `context/features/<area>.md` for the task — not every context file. `context/manual-testing.md` is long; load it only for QA.
- **Prune as you go:** keep `context/session-state.md` short.

## Goal 3 — Accuracy and trust

- Answer from context + code. Conflicting facts: trust PHP / `Configuration/` over README and over context — then fix the context.
- Do not "restore" removed features listed in `context/session-state.md` unless the user asks.
- Report what was verified and what was not (version, browser, provider keys present or not).

---

## Guardrails (non-negotiable)

- **Never write TYPO3 Core sources:** English source files of framework packages (`typo3/sysext`, `vendor/typo3/cms-*`) are read-only; framework translations go to `Environment::getLabelsPath()`.
- **Minimal XLF diffs:** untouched units, header, notes, attributes, comments and indentation stay byte-identical; unchanged files are not written; grid order is applied only when rows were moved.
- **Never map AI results by position of free text:** batches are keyed / count-checked; a mismatching batch is rejected, never shifted.
- **Secrets:** API keys and the Crowdin token come only from Extension Configuration; never log, flash, render or print them (check only "set / empty"). Same for ns_license settings.
- **Errors never break the backend:** providers and Crowdin catch `\Throwable`, log it and return a status — no raw exception text to the user.
- **Permissions:** non-admins never get framework packages, keys or English source; every target passes `canEdit()` + path guards.
- **Free extension:** no license check, license gating or premium / trial CTA; ns_license stays a dependency only.
- **Leave instances clean:** QA users, log rows, registry rows, settings overrides and saved files are reverted after testing.

---

## Apply this whenever you

- Edit `context/`, `tasks/`, `.agents/` or `AGENTS.md`
- Touch `ModuleController` save / translate / import paths, `EditorViewDataProvider`, `XliffDocumentUpdater`, `XliffFormatRestorer`, `XliffUtility`
- Add or change an AI provider, model list, or Crowdin call
- Use a core API that differs between v12, v13 and v14
