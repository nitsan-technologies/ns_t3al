---
name: nst3al-xliff-save
description: Change or debug how ns_t3al reads and writes XLIFF files - the Translate grid, Save, row add/delete/move, key renames, Create file, CSV import and Export ZIP. Use when touching ModuleController save/createFile/importCsv, EditorViewDataProvider, XliffDocumentUpdater, XliffFormatRestorer or XliffUtility, or when a save produces an unexpected diff.
---

Load first: `context/features/ai-and-crowdin.md` → Save action, `context/features/translate-module.md` → Translate flow, `context/architecture.md` → Persistence.

## Invariants (must still hold after your change)

- Existing files are updated **in place** (`EditorViewDataProvider::buildLanguageFile()` → `XliffDocumentUpdater` → `XliffFormatRestorer`); `XliffUtility::generateXml()` only for new files.
- Untouched units keep their bytes (indentation, attributes, notes, comments, CDATA, `<group>`); unchanged files are not written; English `default` only when keys / English texts changed.
- Grid order is applied only when `isGridOrderChanged()` says rows were moved; "Sort by key" wins; each moved unit keeps its own leading whitespace.
- Keys pass `guardKeyChanges()` → `findKeyProblems()` (duplicates, `KEY_PATTERN` for new keys) before anything is written.
- Framework English sources are never written; translations go to `Environment::getLabelsPath()`.
- Save ends with a 303 redirect to `list` (PRG) except on write failure; one success flash per save; `flushLabelCache()` after a write.
- CSV import merges (`mergeImportedLabels()`), never drops keys; cells go through `CsvCellEscaper`.

## Workflow

1. Reproduce with a unit test in `Tests/Unit/Utility/XliffDocumentUpdaterTest.php` or `Tests/Unit/Backend/View/EditorViewDataProvider*Test.php` (fixtures in memory / temp dir via `TemporaryDirectoryTrait`).
2. Fix in the single owning class — do not add a second write path.
3. `composer test`; for core-API changes also `tasks/version-matrix.md`.
4. Manual: save in a git-clean extension and read `git diff` — only the intended lines may change (`context/manual-testing.md` §3, §3a, §19).
5. Update context with `nst3al-context-update`.
