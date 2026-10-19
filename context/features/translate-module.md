# Feature — Translate module UI

Primary Admin Tools surface for ns_t3al. Entry: `ModuleController::listAction` → `Templates/Module/List.html`.

## Docheader navigation

- Custom AF-style **nav-pills** in `Partials/Module/DocHeaderTabs.html` (TYPO3 Module DocHeader disabled).
- Tabs: **Translate** · **Search** · **Logs** (`buildModuleTabs()` — Guide is **not** a tab).
- Right-side **Guide** button opens the Supademo demo in a TYPO3 modal (`module-ui.js`, see Guide flow).
- Embed URL must use Supademo `/embed/…` (not `/demo/…`).

## Translate flow (`list`)

1. **Extension type** — Local / System / All btn-group (default **Local**; persisted in module data as `extType`).
2. **Extension** — searchable combobox (title + key; optional Local/System group headers when All). Native `<select>` stays form source of truth; selecting submits the filter.
3. **File** — `.xlf` select; admin **Create file** beside it writes an empty file without units (`XliffFileGenerator`: `original="EXT:<ext>/Resources/Private/Language/<file>"`, `date`, `product-name`, tab indentation, `<header/>`). Lists only top-level base files of `Resources/Private/Language/` (`XliffUtility::getFileList()`); subfolder files and translation-only files are not editable, so they are not offered.
4. **Languages** — searchable multi-select combobox (name + ISO); chips for selected langs; debounce then auto-submit. Server always includes `default`. `en` is never offered or accepted as a target: English is the `default` source (`getLanguageOptions()` drops it, `normalizeLangKeys()` ignores it).
5. **Grid** — UI-only spreadsheet (`LabelGrid.html`): Key | default | lang…  
   - Empty target → `.nt`; missing default → `.nd`; same as default → `.same`.
6. **Row actions** (admin `modifyKeys`) — single `.ns-t3al-col-actions` cell with flex toolbar (add / delete / move). Keep `.add` / `.del` / `.move` class names for JS. New labels: **+** inserts an empty `_newkey…` row below that row; rows with an empty key are dropped on Save / Translate. Moving (drag the move handle) or deleting a row dispatches `change` on the form (`notifyGridChanged()`), so Save turns red like for a text edit. **Save keeps the grid order:** `ModuleController::saveAction` asks `EditorViewDataProvider::isGridOrderChanged()` (grid order vs English file order with renames in place, removed keys dropped, new keys appended) and only then passes `keepGridOrder` to `XliffDocumentUpdater`, which moves the units of every written file (English + translations) into grid order — each unit keeps its own leading whitespace, so only the moved units differ. A save without moved rows never reorders; "Sort by key" wins over grid order. `LabelGrid.html` renders no blank trailing row — only a file without any label (e.g. a file just made with Create file) gets one blank row to start from, and only when the source is editable (admin, non-framework).
   - **Key guard** (`ModuleController::guardKeyChanges()` → `EditorViewDataProvider::findKeyProblems()`), run by **Save** and **Translate** before keys are applied: a key that would exist twice (new row with an existing key, rename onto another key) or a key that is not in the file yet and does not match `EditorViewDataProvider::KEY_PATTERN` (`^[A-Za-z0-9_.-]+$`) stops the action with an error flash; the posted grid (typed keys included) is shown again. Keys already in the file are not re-validated, so legacy ids keep working; swapping two keys is allowed.
   - **Unsaved changes guard** (`unsaved-changes.js`): while Save is red (edit / move / delete, AI results, failed save), a browser reload / close or an in-module link (tabs, Reload) shows the browser's leave prompt, and switching the backend module (or a backend refresh) shows a TYPO3 modal "Unsaved changes" — Keep editing / Discard changes — via `top.TYPO3.Backend.consumerScope` (asked once per click). Module actions leave without asking as before: any form submit, anything that shows the module loader (Save, Translate, filter changes) and links marked `data-ns-t3al-download` (Export ZIP).
   - **Server-side red Save:** when Translate, CSV import or a failed key check hands the grid back to `listAction` (`overrideLabels`), Save is red only if that grid differs from the files (`GridLabels::differ()`: keys, row order, trimmed texts) or posted keys are renamed. So "Nothing to translate" or an identical CSV leaves Save normal, while unsaved edits made before Translate stay red. Posted grids are normalised to LF line breaks (`GridLabels::normalizeLineBreaks()` in Translate / Save / list): browsers submit textarea line breaks as CRLF, which made every multi-line label look edited and was written back as `&#xD;`.
7. **Toolbar** — AI provider tabs + Crowdin project ID (`Toolbar.html`; project ID only for system extensions). No UI/XLF toggle; no Missing only/Overwrite dropdown.
8. **Editor actions** (`EditorActions.html`) — Translate · Save · Save to Crowdin (system extensions only) · Export CSV · Reload. Save and Create file end with a redirect (Post/Redirect/Get): reloading the page afterwards does not save, upload or log again.
9. **Grid actions** — Sort by key · Import CSV (admin) via `GridActions.html`.

### Supporting behaviours

- Concurrent-edit warning if another session opened the same file within ~10 minutes, e.g. "heli started editing the file locallang.xlf 3 minutes ago." (shared lock, see `access-control.md`).
- Large grids: `max_input_vars` warning; save may be disabled.
- Filter form: `#ns-t3al-filter`; editor form: `#translate_labels`.
- Loader copy: filter / file / language / type changes use neutral `loader_text` (“Updating view…”). AI **Translate** overrides with `loader_text_translate` via `data-loader-translate`.

## Search flow

1. Tab **Search** → `searchAction`.
2. Enter a word and optionally pick a language (**All languages**, **English (source)** or one language) → `LabelSearchService::search()`.
3. Only files that exist are read (one directory listing per `Language` directory); files without an English source file are skipped because the editor cannot open them. Framework packages are searched in English plus the **active language packs** only.
4. Each file is parsed with `XliffUtility` (same parser as the editor), so `&amp;`, multi-line values and `<source>` / `<target>` with attributes match; English is matched on `source`, other languages on `target`. Case-insensitive, whitespace-normalised; `<note>` and ids are not searched.
5. At most `LabelSearchService::MAX_RESULTS` (100) files are listed; when more match, an info box asks to refine the text or pick a language.
6. Results link into `list` with matching extension / file / languages.

## Logs flow

1. Tab **Logs** → `logsAction`.
2. Pick extension (Local/System/All when needed).
3. Filter by log file + target language; expand/collapse entries. Changing a filter starts at page 1.
   Rows with an empty `content` (no diff: failed writes, legacy rows) are excluded in `LogsRepository::findRecords()` **and** `findTargetLanguage()`, so pagination counts, the language filter and the "Logs not found" notice match what is rendered.
4. **Pagination** — 20 logs per page (`LogsViewDataProvider::ITEMS_PER_PAGE`, core `QueryResultPaginator`); Previous / Next keep the filters. Diffs are rendered from `Logs::getContentArray()` as escaped text inside `<del>` / `<ins>` (no `f:format.raw`).
5. **Retention** — rows older than `logRetentionDays` (Extension Settings, default 365, `0` keeps all) are removed whenever a new log row is written.
6. **Toggle Collapse** (`#expandAllButton`) is shown only when log panels exist. It expands/collapses all `.panel-collapse` entries via document-level handlers in `module-functionality.js` (works without legacy `.tab-content`).

## Guide flow

1. Click **Guide** in docheader → `module-ui.js` opens a **TYPO3 modal** (`@typo3/backend/modal.js`, `Modal.advanced()`) titled "Localization Guide" with the Supademo `/embed/` iframe and a **Close** button. The modal renders in the top backend document.
2. **Not Bootstrap:** TYPO3 v14 bundles Bootstrap without Modal, so `data-bs-toggle="modal"` does nothing there. The button carries `data-ns-t3al-guide-url` / `-title` / `-close` instead.
3. The iframe is passed as `content` and gets the core classes `modal-iframe` + `modal-type-iframe` (full-size layout). Modal `type: iframe` is not used: it reads `contentDocument.title` on load, which fails for the cross-origin demo.
4. The demo URL is allow-listed server-side (`ModuleController::resolveGuideDemoUrl()`); the iframe is created on open and removed with the modal.
5. Deep link `guideAction` renders `data-ns-t3al-auto-open-guide` and opens the same modal (Guide is not a nav tab).

## Key files

| Piece | Path |
|---|---|
| Filter + comboboxes | `Partials/Module/Filter.html`, `module-functionality.js` |
| Tabs + Guide button | `Partials/Module/DocHeaderTabs.html` |
| Guide modal | `module-ui.js` (TYPO3 Modal API, no Fluid partial) |
| Grid | `Partials/Module/LabelGrid.html`, `Icons.html` |
| Actions | `Partials/Module/EditorActions.html`, `GridActions.html` |
| Styles | `Resources/Public/Css/module.css` |

See also: `ai-and-crowdin.md` for Translate/Save behaviour; `session-state.md` for intentional UX removals.
