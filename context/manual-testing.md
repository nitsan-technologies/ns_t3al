# Manual testing plan — ns_t3al

Practical backend checklist for **AI-Powered XLIFF Translation** after Critical #1–3 and High #6–9 fixes. Run on a real TYPO3 instance (ddev or equivalent). No full PHPUnit suite is required; use the smoke checks below for a quick gate.

**Primary path:** Admin Tools → **AI Localization** (`tools_NsT3al`) → Extbase `ModuleController::listAction`.

---

## Prerequisites

| Item | Detail |
|---|---|
| TYPO3 | **12.4 / 13.4 / 14.x** (extension constraint `12.4.0-14.9.99`). Primary smoke on the version you develop against; see [version matrix](#version-matrix-v12--v13--v14). |
| Extension | `ns_t3al` active; `ns_license` dependency satisfied. |
| Sample XLF | A **local** extension with `Resources/Private/Language/*.xlf` (e.g. `ns_t3al` itself or a small test ext). Prefer a file with empty targets for AI missing-only checks. |
| Admin user | Backend admin — full Translate UI (Create file, Import CSV, key row actions). |
| Editor user | Non-admin BE user with **module access** to `tools_NsT3al` only (no admin flag). Used for admin-gate and permission tests. |
| No-module user | BE user **without** `tools_NsT3al` access — module access checks. |
| API keys (optional for AI/Crowdin) | Extension Configuration: OpenAI / Gemini / DeepL / Google as needed; Crowdin `accessToken` + project ID for Crowdin save. |
| Browsers | Two browsers or profiles for concurrent-edit (High #8). DevTools Network for request status codes. |
| Cache | Flush TYPO3 caches after deploying PHP/config changes. |

### Quick automated smoke (optional gate)

Run from the extension root (or via `ddev exec` from the site root):

```bash
# PHP syntax (not .Build/, which holds the dev tooling)
find Classes Configuration Tests ext_*.php -name '*.php' -print0 | xargs -0 -n1 php -l

# Unit tests (once: composer install → dev tooling in .Build/)
composer test

# SQL: last column must not end with a trailing comma
# Modules config must load as an array; no AJAX routes / legacy UI left
php -r 'var_export(is_array(include "Configuration/Backend/Modules.php"));'
test ! -e Configuration/Backend/AjaxRoutes.php && test ! -e Resources/Public/Css/Style.css && test ! -e ext_tables.php

# Every TYPO3 package used in Classes/ is required in composer.json (EXT:install is optional and guarded)
for p in backend extbase extensionmanager fluid; do grep -q "\"typo3/cms-$p\"" composer.json || echo "MISSING typo3/cms-$p"; done
grep -rl 'TYPO3\\CMS\\Install\\' Classes | grep -v Service/ActiveLanguagesProvider.php && echo "UNGUARDED EXT:install usage"

# No Extension Manager override (page TSconfig / TypoScript / forked template)
test ! -e Configuration/page.tsconfig && test ! -e ext_typoscript_setup.typoscript && test ! -e Resources/Private/Templates/List \
  && ! grep -rq 'cms-extensionmanager\|tx_extensionmanager' Configuration ext_*.php
```

**Last run (2026-09-23, this workspace):** all PASS — 34 PHP files lint OK; `ext_tables.sql` no trailing comma; `ext_tables.php` absent (no global assets); Modules + Services.yaml load. (Since §19 there is no `AjaxRoutes.php` any more.)

---

## 1. Translate flow — filters, comboboxes, grid

**User:** admin.

- [ ] Open **Admin Tools → AI Localization**. Docheader shows **Translate · Search · Logs** as `nav-pills`; **Guide** is a right-side button (not a tab).
  - **Expected:** No Import ZIP UI; no UI/XLF toggle; no Missing only/Overwrite dropdown.
- [ ] Extension type defaults to **Local**. Switch **Local → System → All** and back.
  - **Expected:** Extension list updates; choice persists after reload (module data `extType`).
- [ ] Open the **extension** combobox: search by title and by extension key; select an extension with XLF files.
  - **Expected:** Native select stays in sync; filter submits; file dropdown populates with `.xlf` files.
- [ ] With type **All**, confirm optional Local/System group headers in the combobox (if implemented).
- [ ] Select a **file**, then open the **languages** multi-select: search by name/ISO; pick 1–2 non-default langs; chips appear; wait for debounce auto-submit.
  - **Expected:** Grid columns: Key \| default \| selected langs. Server always includes `default`.
  - **Expected (loader):** Filter reloads (extension / file / language / Local|System|All) show neutral **“Updating view…”** — not the AI marketing line.
- [ ] Click **Translate** with a configured provider.
  - **Expected (loader):** Overlay shows **“AI translation in progress… Please wait.”** (`loader_text_translate`).
- [ ] Inspect empty / missing / same-as-default cells.
  - **Expected:** Empty target → `.nt`; missing default → `.nd`; same as default → `.same` (CSS classes present).
- [ ] Confirm **actions** column (`.ns-t3al-col-actions`) shows add / delete / move for admin.
  - **Expected:** Compact flex toolbar; class names `.add` / `.del` / `.move` still work for JS.
- [ ] Toolbar shows AI provider tabs + Crowdin project ID field only.
- [ ] Editor actions visible: **Translate · Save · Save to Crowdin · Export CSV · Reload**.

---

## 2. AI translate — missing-only

**User:** admin. **Requires:** at least one configured AI provider API key.

- [ ] Select an XLF with some **empty** and some **filled** target cells for a non-default language.
- [ ] Choose a provider → click **Translate**.
  - **Expected:** Empty cells fill with AI text; **non-empty targets are unchanged** (missing-only, hard-coded).
- [ ] Select **Gemini / DeepL / Google** (not OpenAI) when that provider’s key is missing → Translate.
  - **Expected:** Selection stays on that provider; flash shows **that** provider’s key warning — not OpenAI’s; UI does not snap back to OpenAI.
- [ ] Confirm results are in the grid only (flash/forward to list with override labels).
  - **Expected:** Disk XLF unchanged until **Save**.
- [ ] Misconfigure/remove API key → Translate.
  - **Expected:** Error flash; return to list; no silent success.

### 2a. Structured, count-checked AI requests (Major — no `#SP#` misalignment)

- [ ] Pick a file with **> 50** missing labels for one language (3 batches) → Translate with OpenAI, then DeepL.
  - **Expected:** Every filled cell is the translation of **its own row's** English text (spot-check first, middle around row 50/51, last). Placeholders (`%s`, `%1$s`, `{0}`) and HTML tags are preserved.
- [ ] Labels whose English text contains `#SP#`, `|`, line breaks or quotes translate into the right row (no shifting).
- [ ] Set an invalid API key (e.g. append `x` to `openaiApiKey`) → Translate.
  - **Expected:** Error flash, no cells change (all batches failed). Restore the key afterwards.
- [ ] Partial failure (one batch with a dropped/extra/empty key) cannot be forced reliably by hand; it is covered by a mocked-HTTP check. **Expected** when it happens: warning flash "X of Y labels could not be translated…", only validated rows filled, rejected rows left empty.
### 2b. Provider HTTP errors / timeouts (Major — no backend error page)

**User:** admin. Change settings in **Settings → Extension Configuration → ns_t3al** (or `typo3 configuration:set EXTENSIONS/ns_t3al/<key> <value>` + flush caches) and restore them afterwards.

- [ ] Set `deeplApiUrl` to `http://127.0.0.1:1/v2/translate` (nothing listens) → pick a language with empty cells → **Translate** with **DeepL**.
  - **Expected:** red flash "The translation service could not be reached (timeout or network error)…" within a few seconds; grid unchanged; no "Oops, an error occurred" page. `typo3temp/var/log/typo3_*.log` (composer: `var/log/`) has one `[ERROR] … DeepLService: Translation provider request failed` line with `ConnectException` (plus one `[WARNING] … DeepLLanguageProvider` line: the language list falls back to the built-in one).
- [ ] Use a file with **> 50** missing labels (≥ 2 batches) with the same broken URL.
  - **Expected:** still only **one** `[ERROR]` line — remaining batches are skipped after an outage.
- [ ] Set an invalid `deeplApiKey` (append `x`) with the real URL → **Translate** with DeepL.
  - **Expected:** flash "The translation service rejected the request (HTTP 403)…" (check key / quota / model).
- [ ] Restore `deeplApiUrl` (`https://api-free.deepl.com/v2/translate`) and the key; Translate works again.

### 2c. DeepL target languages (Major — header auth, current target codes)

**User:** admin. **Requires:** `deeplApiKey`. Nothing is written to disk unless you click **Save**.

- [ ] Pick a file, languages **Japanese (ja)** and **Korean (ko)** → **DeepL** → **Translate**.
  - **Expected:** cells fill with Japanese / Korean (before: "Unsupported Language").
- [ ] Languages **Brazilian Portuguese (pt_BR)**, **Chinese (Traditional) (zh)**, **Chinese (Simple) (ch)** → DeepL → Translate.
  - **Expected:** pt_BR in Brazilian Portuguese (e.g. "vagas"); zh in **Traditional** characters (e.g. 職位), ch in **Simplified** (职位).
- [ ] Languages **Portuguese (pt)** / **Norwegian (no)** → DeepL → Translate.
  - **Expected:** European Portuguese (PT-PT) / Norwegian Bokmål (NB).
- [ ] Language **Greenlandic (kl)** → DeepL → Translate.
  - **Expected:** red flash "Unsupported Language — DeepL cannot translate into "kl"…"; grid unchanged.
- [ ] After the first DeepL translation: `typo3temp/var/cache/data/ns_t3al_deepl_languages/targets_*` exists (live list from `GET /v3/languages`, refreshed daily; flush caches to refresh now).
- [ ] Optional (DevTools are not usable for server requests): with a proxy logging the request, `/v2/translate` carries `Authorization: DeepL-Auth-Key …` and a JSON body `{"text": […], "target_lang": "PT-BR"}`; no `auth_key` anywhere.

---

## 3. Save local + special characters (XML escape — High #7)

**User:** admin.

- [ ] Edit a target to include `&`, `<`, and `>` (e.g. `A & B <tag> C`).
- [ ] Click **Save** (`fileLocation=local`).
  - **Expected:** Success flash; files written under the extension `Resources/Private/Language/` (or language subfolder for non-default).
  - **Expected:** Exactly **one** “Translation Success” flash even when multiple languages are selected (including implicit `default`) — not one flash per language.
- [ ] Open the written `.xlf` in an editor / `cat`.
  - **Expected:** Correct single XML escaping (`&amp;`, `&lt;`, `&gt;`); **no** double-escaped entities like `&amp;amp;`. Reloading the module shows the original characters in the grid.
- [ ] Confirm a log entry appears under **Logs** for the save (when logging is enabled for that path).

### 3a. In-place save — no source rewrite, metadata kept, no Core writes (Major)

**User:** admin. Use a **Local** extension under git (e.g. `ns_personio`) so `git diff` shows exactly what was written.

- [ ] Note `stat` mtimes of `locallang_db.xlf` and `de.locallang_db.xlf`. Open the file + **de** in Translate, change **one** German value → **Save**.
  - **Expected:** `git diff` shows only that unit (its `<target>`, plus its `<source>` if the English text had drifted). Header, `date` attribute, notes, `approved` / `state` / `xml:space`, comments and indentation unchanged. The English `locallang_db.xlf` mtime is unchanged.
- [ ] Click **Save** again without editing.
  - **Expected:** INFO flash "No changes"; no file mtime changes.
- [ ] Change **one** English (default) text → **Save**.
  - **Expected:** exactly one changed line in `locallang_db.xlf`; translation files are not rewritten.
- [ ] Rename a key, delete a key, add a key with an English text (leave German empty) → **Save**.
  - **Expected:** rename keeps position (and `resname`); deleted unit removed from all files; new unit appended to English; **no** empty `<target>` added to `de` (German falls back to English).
- [ ] **Sort by key** → **Save**. **Expected:** units sorted; a plain Save without sorting never reorders.
- [ ] Switch to **System**, pick `backend` → `locallang.xlf`. Note the mtime of `typo3/sysext/backend/Resources/Private/Language/locallang.xlf` (composer: `vendor/typo3/cms-backend/…`).
  - **Expected:** key + English columns read-only; no **Sort by key**, no **Create file**, no key actions.
  - [ ] In DevTools remove `readonly` from an English textarea, change it → **Save**. **Expected:** warning "Source file is read-only" + "No changes"; the grid shows the original Core text again; Core file mtime unchanged.
- [ ] Restore the test extension (`git checkout -- .`).

---

## 4. Save to Crowdin (optional)

**User:** admin. **Requires:** Crowdin token + project ID.

- [ ] Set Crowdin project ID in toolbar → **Save to Crowdin**.
  - **Expected:** Local write succeeds first; remote update for non-default languages; errors surface clearly if token/project invalid.
- [ ] Crowdin errors (Major): with an invalid `accessToken`, a wrong project ID, or `crowdinApiUrl` set to `http://127.0.0.1:1/` → **Save to Crowdin** on a System extension translation.
  - **Expected:** "Translation Success" for the local file **plus** a red Crowdin flash ("Crowdin rejected the access token (HTTP 401)…" / "Crowdin project … was not found" / "No matching file found at Crowdin: <path>" / "Crowdin could not be reached…"); never a backend error page; the save still appears under **Logs**; an `[ERROR] … CrowdinService` line is in the TYPO3 log.
- [ ] **Known gap (High #5):** Crowdin + local/framework edge cases may still be incomplete — treat failures here as possible open issues; see [Known gaps](#known-untested--open-high-4--5).

---

## 5. Guide modal (Supademo embed)

**User:** admin or editor with module access.

- [ ] Click **Guide** in the docheader (on v12, v13 **and** v14).
  - **Expected:** TYPO3 modal "Localization Guide" opens over the whole backend; iframe loads a Supademo **`/embed/…`** URL (not `/demo/…`); footer has **Close**.
- [ ] Close the modal (**Close** and the header **×**), then open it again.
  - **Expected:** modal and iframe are removed (no leftover media); reopening works.
- On v12 with a window narrower than 1024px and the module menu expanded, the menu covers the modal — core behaviour for every TYPO3 modal; collapse the menu.
- [ ] Optional: deep-link `guideAction` URL.
  - **Expected:** Same modal auto-opens; Guide is still **not** a nav tab.

---

## 6. Search tab

**User:** admin or editor.

- [ ] Open **Search** → enter a word known to exist in an extension XLF (source or target) → submit.
  - **Expected:** Results list matching extensions/files; links open Translate `list` with the right extension / file / languages.
- [ ] Search for an English text from a source file (e.g. a label of a local extension).
  - **Expected:** The file is listed (English source files are searched).
- [ ] Search for a label that contains `&` (stored as `&amp;` in the XLF, e.g. `Save & close`) and for a value that spans several lines in the XLF.
  - **Expected:** Both files are found.
- [ ] Pick **German (de)** in the language select, search a German word that exists only in a `de.*.xlf` target → submit; then pick **English (source)** and submit again.
  - **Expected:** German finds the file and the link opens Translate with `de` preselected; the select keeps the picked language after submit; English (source) shows “No results”.
- [ ] Search a very common text (e.g. `e`) with **All languages**.
  - **Expected:** Exactly 100 result files plus the info box “Only the first 100 matching files are shown…”. The page answers quickly.
- [ ] Framework packages (admin): with no language pack installed, a German Core label (e.g. `Speichern`) is not found under Core; after installing the German language pack (Admin Tools → Maintenance → Manage Language Packs) it is.
- [ ] Search for nonsense string and for spaces only.
  - **Expected:** “No results” — no PHP error.

---

## 7. Logs tab

**User:** admin or editor.

- [ ] Open **Logs** → pick an extension (Local/System/All as needed) → filter by log file / target language.
  - **Expected:** Entries render; expand/collapse works; no SQL errors (Critical #3 trailing-comma fix).

---

## 8. Admin-only gates — Create file, CSV, key edits (Critical #2)

**User:** non-admin with module access.

- [ ] Open Translate for a local extension with XLF.
  - **Expected:** **No** Create file UI; **no** Import CSV; key fields / default textareas **readonly**; **no** add/delete/move actions (`modifyKeys` false).
- [ ] As admin, note a working Create-file POST URL (or craft Extbase `createFile` request). As non-admin, replay that request (or remove UI and POST anyway).
  - **Expected:** Denied — access flash / no new file created on disk.
- [ ] Same for **Import CSV** POST as non-admin.
  - **Expected:** Denied; grid/disk unchanged.
- [ ] As admin: Create file with a valid name (`my_labels.xlf`) → file appears; Import CSV → grid updates as override; Save still required for disk.

---

## 9. Path traversal rejection (Critical #1)

**User:** admin (UI) + crafted requests.

Valid names: `^[a-zA-Z0-9_-]+\.xlf$` only — no `..`, `/`, `\`.

- [ ] Create file with invalid names (via manipulated POST if UI blocks): `../foo.xlf`, `foo/bar.xlf`, `foo.xliff`, `evil.php`.
  - **Expected:** Rejected; **no** file created outside `Resources/Private/Language/`.
- [ ] If you can intercept Save / AJAX write with `fileName` / language containing `../` or `/`.
  - **Expected:** 403 or validation error; paths stay under the extension language directory (`XliffUtility::assertPathContainedInLanguageDirectory`).

---

## 10. Concurrent edit — shared lock (High #8)

**User:** two admins (or admin + editor), two browsers/profiles.

- [ ] Browser A: open Translate for extension `X` + file.
- [ ] Browser B: within ~10 minutes, open the **same** extension/file.
  - **Expected:** Concurrent-edit warning in B naming A's username and the file (shared cache `ns_t3al_editor_lock`, TTL ~600s) — not only same-session module data. Wording: §27.
- [ ] Close A / wait past TTL → B reload.
  - **Expected:** Warning clears when lock expires or is released.

---

## 11. No AJAX routes — module access only (High #9)

ns_t3al registers **no** AJAX routes (the legacy Localization modal UI and `Configuration/Backend/AjaxRoutes.php` were removed). Every feature is a module action behind `tools_NsT3al` access.

- [ ] Open the AI Localization module, run in the browser console: `Object.keys(TYPO3.settings.ajaxUrls).filter(k => /translation|localiz|manual|export_/.test(k))`.
  - **Expected:** `[]`.
- [ ] As the no-module user, open `/typo3/module/tools/ns-t3al`.
  - **Expected:** Core access denied; no XLF read/write side effects.

### 11a. Removed legacy write route `update_manual_translations` (Blocker)

The route `/get/write-file-translation` bypassed the non-admin "values only" rules of `saveAction` and wrote raw request XML to disk. It is removed; `saveAction` is the only XLF write path.

- [ ] As admin and as editor, open the AI Localization module and run in the browser console: `TYPO3.settings.ajaxUrls.update_manual_translations`.
  - **Expected:** `undefined`.
- [ ] As editor, POST to `/typo3/ajax/get/write-file-translation` (any token, e.g. replayed from an old session) with `extkey`, `targetLanguage`, `files[]`, `editingMode=codepen`, `input[<file>][content]=<broken/>`.
  - **Expected:** No JSON success — core answers an unknown backend route with a **302 redirect to the login route** (`BackendRouteInitialization`). Target `.xlf` unchanged on disk (check mtime / content).
- [ ] Regression: as editor, edit a target value in the Translate grid → **Save**.
  - **Expected:** Saved; key / default-source edits still ignored for non-admins.

### 11b. Per-extension permission + Save target validation (Major)

Non-admins may only edit extensions granted to one of their groups: **Backend Users → group → Module Permissions → "AI Localization: editable extensions"** (`customPermOptions` group `ns_t3al_extensions`, checked by `ExtensionAccessService`). Framework packages are never listed. Admins are unaffected. `saveAction` / `translateAction` / `exportCsvAction` also require `$file` to be an existing base XLF (`getFileList()`); new files only via admin-only **Create file**.

**Users:** admin; editor whose group has `tools_NsT3al` + exactly one granted extension (e.g. `ns_t3al`).

- [ ] As admin, edit the editor group → **Module Permissions**.
  - **Expected:** Group "AI Localization: editable extensions" lists non-framework extensions as "Title (ext_key)".
- [ ] As editor, open AI Localization (also Search / Logs).
  - **Expected:** Extension picker shows **only** the granted extension(s).
- [ ] As editor, replay the Save form with `extensionKey`/`extkey` = a **non-granted** extension.
  - **Expected:** "Access denied … not allowed to edit the language files of this extension"; target `.xlf` mtime unchanged.
- [ ] As editor, replay the Save form for the granted extension with `file=evil_new.xlf`.
  - **Expected:** "Language file not found"; no new file in `Resources/Private/Language/`.
- [ ] Same non-granted replay against **Export CSV** and **Translate**.
  - **Expected:** Same "Access denied" flash; no CSV download / no AI call.
- [ ] As editor, open Export ZIP (`…/ns-t3al/export?token=…&extensionKey=<non-granted>`; copy the token from the Export ZIP button).
  - **Expected:** Error flash "Access denied"; no ZIP. Granted extension → ZIP download as before.
- [ ] Remove all grants from the editor group, reload AI Localization.
  - **Expected:** Info box "No extensions available" with the Access Rights hint; empty picker.
- [ ] Regression: as editor, edit a target value of the granted extension → **Save**.
  - **Expected:** "Translation Success"; value on disk. As admin, all extensions still listed and saveable.

**Upgrade note:** existing non-admin editors see no extensions until an admin grants them.

---

## 12. Extension Manager list — core template, no ns_t3al override (High #6, Major "forked EM template")

**User:** admin.

- [ ] Open **System → Extensions** (EM list).
  - **Expected:** Core list only — columns Upd. · A/D · Extension · Key · Version · State · Type · Actions. **No** "AI Localization" column, no "AI Localization Guide" button. Page source loads **no** `EXT:ns_t3al/.../Style.css` and no `localizationButtonHandler.js`.
- [ ] A/D toggle, Local/System/All filter, search, update/download/remove actions.
  - **Expected:** Core behaviour (core web components, no ns_t3al ViewHelper).
- [ ] Open **Admin Tools → AI Localization**, pick a Local extension + file.
  - **Expected:** Editor actions show **Export ZIP**; click → browser downloads `<ext>_Localization_<date>.zip` with the extension's `Resources/Private/Language` files (sub folders included, relative paths); page stays on the editor (no stuck loader).
- [ ] Same with a System extension (e.g. `backend`), and with `enableExport = 0`.
  - **Expected:** No Export ZIP button. Opening the export URL directly anyway (`…/ns-t3al/export?token=…&extensionKey=backend`) shows the error flash "Export ZIP not available" on the Translate tab, no download. ("Access denied" is only for unknown / non-granted extensions.)
- [ ] Deep link `…/typo3/module/tools/ns-t3al?token=…&extensionKey=<key>&file=locallang.xlf` (take the token from the module menu link).
  - **Expected:** Translate opens with that extension/file preselected; Logs tab keeps `extensionKey`.

---

## 13. CSV export / import (sanity)

**User:** admin.

- [ ] **Export CSV** with a populated grid.
  - **Expected:** Download reflects current keys/languages.
- [ ] **Import CSV** (admin) → grid shows imported overrides → **Save**.
  - **Expected:** Disk matches after Save; Import alone does not persist permanently.

---

## 14. Composer dependencies (Major — missing extensionmanager/backend)

- [ ] In a Composer project that requires only `typo3/cms-core` (`^12.4`, `^13.4` or `^14.3`) run `composer require nitsan/ns-t3al --dry-run` (path or private repo).
  - **Expected:** resolves on 14.3.x (not only 14.4+); installs `typo3/cms-backend`, `cms-extbase`, `cms-extensionmanager`, `cms-fluid`; does **not** force `typo3/cms-install`.
- [ ] After install: `vendor/bin/typo3 cache:flush`, open AI Localization.
  - **Expected:** DI container builds (no "Class TYPO3\CMS\Extensionmanager\Utility\ListUtility not found"); System extension (e.g. `backend`) offers only the active language packs.

---

## 15. Code review minor fixes (m1–m12)

**User:** admin. Flush caches first (Maintenance → Flush TYPO3 and PHP Cache).

- [ ] **Save is not repeated on reload (m5):** Translate → Local extension → one language → change a cell → **Save**.
  - **Expected:** success flash; the iframe URL is a GET of `…/Module/list`. Press F5 (or "Reload frame"): no "resend form" prompt, no new flash, the file is not rewritten and the Logs tab shows only one new entry. Same for **Create file**.
- [ ] **Crowdin only for system extensions (m6):** open a Local extension.
  - **Expected:** no **Save to Crowdin** button and no Crowdin project ID field. Open a System extension (e.g. `core`): both are shown.
- [ ] **File list (m4):** System extension `core` → File select.
  - **Expected:** only top-level files (e.g. `locallang_core.xlf`), no `Form/…` or `Iso/…` entries; every listed file opens.
- [ ] **Logs (m11):** Logs tab → extension with more than 20 log rows.
  - **Expected:** 20 entries, "Page 1 of N (X logs)" + **Next**; page 2 has **Previous**; changing log file / language filter starts at page 1. A label containing `<img src=x onerror=alert(1)>` saved and then viewed in Logs shows as text (no image, no alert). Old log rows still show their diff.
- [ ] **Log retention (m11):** Extension Settings → ns_t3al → *Log retention (days)* shows `365`.
  - **Expected:** with `1`, a new Save removes log rows older than one day; `0` keeps everything.
- [ ] **Log record (m3):** List module → root page (or a search for `tx_nst3al_domain_model_logs`).
  - **Expected:** table is hidden from the List module; Logs tab **delete** still works; Maintenance → Analyze Database offers to drop / rename the old `sys_language_uid`, `t3ver_*`, `translation_mode` columns (optional).
- [ ] **CSV import (m9):** Import CSV with an exported file where one value was changed.
  - **Expected:** grid shows the changed value (Save still needed); no new file in `/tmp` or `typo3temp/var/transient`. Submitting without a file shows "CSV import failed — No file uploaded".
- [ ] **Remembered selection (m7):** pick extension + file + language, open another module, open AI Localization again.
  - **Expected:** same extension, file and languages are preselected.
- [ ] **Package metadata (m12):** `composer validate` in the extension folder.
  - **Expected:** valid; `license` is `GPL-2.0-or-later`, `nitsan/ns-license` is `^14.5`; README describes the extension (no GitLab template).

---

## 16. Code cleanup (N1–N5)

**User:** admin. Flush caches first.

- [ ] **N1:** `ext_localconf.php` starts with `defined('TYPO3') or die();` (no `TYPO3_MODE`).
- [ ] **N2 import map:** open AI Localization, then in the browser dev tools inspect `<script type="importmap">` of the module frame.
  - **Expected:** one `@nitsan/ns-t3al/…` entry per JS file, no `@nitsan/nst3al/` entries. Comboboxes, filters, dirty-save warning and Translate still work; FormEngine record editing (any page / content element) still opens.
- [ ] **N3 stub actions:** take the Logs tab link and replace `/Module/logs` with `/Module/manual` (also `aiLocalization`, `validate`).
  - **Expected:** TYPO3 shows its default backend page, no exception and no AI Localization page. Translate / Search / Logs / Guide still open.
- [ ] **N3 provider resolution:** with only the OpenAI key set, open Translate.
  - **Expected:** OpenAI preselected. Select **Gemini** (no key) → Translate → "Gemini API key unavailable" and Gemini stays selected.
- [ ] **N4 Gemini allowlist:** with a Gemini key, Translate with the toolbar model; then change the checked `models[gemini]` radio value to `../evil` in the dev tools and Translate again.
  - **Expected:** both translations work; the forged value is replaced by the configured *Gemini model* (or `gemini-2.5-flash`) and never becomes part of the request URL (no "model not found" / 404 provider error).
- [ ] **N5 CSV formulas:** set two targets to `+49 Hotline` and `=HYPERLINK("http://evil.test","x")`, Save, **Export CSV**.
  - **Expected:** the CSV cells are `'+49 Hotline` and `'=HYPERLINK(…)`; opened in Excel / LibreOffice / Numbers they show as text (no formula, no link). **Import CSV** with the same file → grid shows the original values without `'`; **Save** → "No changes".
- [ ] **Log write:** any successful Save.
  - **Expected:** only the success flash (no "Log could not be saved"); the Logs tab has the new entry and rows older than *Log retention (days)* are gone.

---

## 17. Extended review fixes (l10n cache, Export ZIP, empty key)

**User:** admin. Back up any file you edit (or use a git-clean extension).

- [ ] **Labels visible after Save:** Translate → Local → `ns_t3al` → `locallang_mod.xlf` → English → change `actionmenu.logs` to `Logs QA` → **Save**.
  - **Expected:** the module tab immediately reads **Logs QA** (no cache flush). Restore the value with Save again. (Editing the file on disk instead keeps the old tab text until caches are flushed — that is the core behaviour the fix works around.)
- [ ] **Export ZIP with sub folders:** Export ZIP for a Local extension with a sub folder in `Resources/Private/Language` (create e.g. `Resources/Private/Language/Sub/locallang.xlf` in a test extension).
  - **Expected:** `<ext>_Localization_<YmdHi>.zip`; contains the `Sub/…` file; no entry starts with `/` (check with `unzip -l`). Two downloads started at the same time are both complete; no `ns_t3al_export_*` file remains in `var/transient`.
- [ ] **Empty / invalid extension key:** Export ZIP URL (`…/ns-t3al/export?token=…`) with `extensionKey=does_not_exist`, `../../etc`, then with `extensionKey=` (empty) / missing.
  - **Expected:** unknown / malformed key → error flash "Access denied" on the Translate tab; empty / missing key → the Translate editor opens normally. Never a download, an error 500 or a log exception.

## 18. Functional testing bugs (v14)

**User:** admin. Use a git-clean Local extension (e.g. `ns_personio` → `locallang.xlf`, languages English + German) and restore it afterwards (`git checkout -- Resources/Private/Language`).

- [ ] **Partial CSV import keeps other keys:** create `partial.csv` with only `key,de` / `tx_nspersonio_domain_model_jobs.seeAll,Alle QA` / `qa_csv.added,QA aus CSV` → **Import CSV**.
  - **Expected:** INFO "CSV imported — 1 existing labels updated, 1 new keys added"; every other row is still in the grid with its English and German text. **Save** → both files keep all original keys, `seeAll` German is `Alle QA`, `qa_csv.added` is appended.
- [ ] **Duplicate key:** click **+** on the last row and in the new row enter key `tx_nspersonio_domain_model_jobs.seeAll`, English `QA duplicate` → **Save**; repeat with **Translate**.
  - **Expected:** error "Label keys need attention — These keys would exist more than once: …"; the typed row is still shown; the existing label still reads `See All`; the file is unchanged and no AI request is sent. Renaming an existing row onto another existing key is refused the same way; swapping two keys works.
- [ ] **Invalid key:** new row key `it's label` (or with a space / `<`) → **Save**.
  - **Expected:** error "Invalid keys: it's label …"; nothing written. A valid key like `qa_check.new_key` saves normally.
- [ ] **English is not a target language:** open the language picker and type `English` / `en`.
  - **Expected:** no English entry (English is the source column). A URL with `langKeys[]=en` shows only English (source) + the other requested languages.
- [ ] **Crowdin messages** (System → `viewpage` → `locallang.xlf`, German): change one German cell, leave Project ID empty → **Save to Crowdin**.
  - **Expected:** notification "Project ID is required" (not "An unexpected error occurred"). Enter any project ID with an empty `accessToken` → **Save to Crowdin** → error "Crowdin access token missing … Nothing was saved."; the edited cell is still in the grid and no file is written.
- [ ] **Core English file not rewritten / first save into a new folder:** note `shasum typo3/sysext/viewpage/Resources/Private/Language/locallang.xlf`; with no `labels/de/viewpage` folder yet, change one German cell → **Save**.
  - **Expected:** "Translation file saved successfully"; the German file is created under the labels path (`typo3conf/l10n/de/viewpage/…` classic, `var/labels/…` Composer) including its new folders; the core English checksum is unchanged. Delete the created German file afterwards.
- [ ] **Search finds English source:** Search → `Source file is read-only`, language **English (source)**.
  - **Expected:** `ns_t3al/Resources/Private/Language/locallang_mod.xlf` is listed.
- [ ] **Extension title (Breaking #108304):** `typo3 cache:flush`, then open Admin Tools → Extensions and the Translate extension dropdown (Local).
  - **Expected:** both show **AI-Powered XLIFF Translation**; the Extensions list tooltip is the one-sentence description; ns_t3al is not listed under *Composer Support of Extensions*. Same in a Composer-mode v14 install (there the title comes from `composer.json`). `typo3 extension:list` on v14 shows key / version / status only, no title.
- [ ] **Editors cannot write via AJAX:** as non-admin editor, call `TYPO3.settings.ajaxUrls` in the browser console.
  - **Expected:** no ns_t3al entry at all; writes only go through the module Save (non-admin: translations only, English/keys read-only).

## 19. Leftover code removal (legacy UI, unused labels, release archive)

**User:** admin. `typo3 cache:flush` first.

- [ ] **No blank row under the labels:** Translate → any Local extension / file (admin), then a System extension and a non-admin editor.
  - **Expected:** the grid ends with the last real label, no empty row. **+** on any row inserts an empty row below it; filling key + English and **Save** adds the label, an untouched added row is ignored ("No changes"). A file with no `trans-unit` at all (e.g. right after **Create file**) shows exactly one blank row to type the first label into.
- [ ] **Moved rows are kept:** Local extension, English + German. Drag a row (move handle) below the next one.
  - **Expected:** Save turns red. **Save** → "Translation file saved successfully"; F5 / other module and back → the row stays at its new place. `git diff` shows only the moved `trans-unit` block in the English and German file (indentation / blank lines of other units untouched). **Save** again without moving → "No changes". Changing only a text → only that line changes, nothing is reordered.
- [ ] **Unsaved changes are not lost silently:** move a row (or edit / delete one), do not save.
  - **Expected:** clicking another backend module → modal "Unsaved changes" (Keep editing / Discard changes). Keep editing → module and grid stay as they are, menu still on AI Localization. Discard changes → the other module opens. Browser reload or the Search / Logs tab / Reload link → browser "Leave page?" prompt. Save, Translate, filter changes, Export CSV / ZIP never ask.
- [ ] Open every tab: Translate (Local + System extension), Search (any term), Logs (extension with logs: Next / Previous, Toggle Collapse; extension without logs), Guide.
  - **Expected:** no "template could not be loaded", "class not found" or raw label keys (e.g. `button.save`) anywhere; no ns_t3al errors in `var/log/typo3_*.log`.
- [ ] **Export ZIP** for a Local extension.
  - **Expected:** ZIP download, page stays usable. With `enableExport = 0` (or a System extension) the button is hidden and the direct URL gives the error flash "Export ZIP not available".
- [ ] **Export CSV** / **Import CSV** round trip, then **Save**.
  - **Expected:** as in §13; "CSV imported" info flash.
- [ ] Settings → Extension Configuration → ns_t3al → General settings.
  - **Expected:** only *Enable Logs*, *Enable Export*, *Log retention (days)* (no *Enable Manual translation* / *Enable Localization*).
- [ ] Release archive: `git archive --worktree-attributes HEAD | tar -t | grep -E '^(context/|tasks/|\.agents/|\.claude/|\.cursor/|AGENTS.md|CLAUDE.md|Tests/|Build/)'`.
  - **Expected:** no output.

## 20. Unused-code removal + v12 / v13 compatibility (2026-10-07)

**User:** admin. Deploy to each instance (`tasks/qa-environment.md`).

- [ ] **Logs without entries:** Logs tab → an extension that has no log rows.
  - **Expected:** yellow warning "Logs not found" inside the module (same place as other flash messages), no PHP error.
- [ ] **Crowdin project ID missing:** System extension → leave Project ID empty → **Save to Crowdin**.
  - **Expected:** red notification "Project ID is required" (TYPO3 notification, no page reload).
- [ ] **Per major (v12, v13, v14):** module menu entry, Translate grid, edit → Save turns red → module switch shows "Unsaved changes" (Keep editing / Discard), real Save of a moved row + one translation, Logs (Toggle Collapse, language filter), Search with results, Guide modal.
  - **Expected:** identical behaviour on all three; saved files differ only in the moved unit / edited target.

## 21. Module QA fixes (2026-10-08, ticket 2m7)

**User:** admin.

- [ ] **Logs rows without a diff:** `INSERT` a log row with `content=''` for an extension / file that has real logs (or use an instance with legacy rows), e.g. `target_language='default'`.
  - **Expected:** Logs tab lists only entries with a diff; the language filter does not offer the empty row's language; an extension whose only rows are empty shows "Logs not found"; with 23 real rows the pager says "Page 1 of 2" and page 2 has 3 entries.
- [ ] **CSV import count:** Export CSV, change one existing value and add one new key, Import CSV.
  - **Expected:** "1 existing labels updated, 1 new keys added" (unchanged rows are not counted); re-importing the untouched export says "0 existing labels updated, 0 new keys added".
- [ ] **Export disabled message:** `enableExport = 0`, open a copied Export ZIP URL.
  - **Expected:** "Export ZIP not available" (not "Access denied"); after `enableExport = 1` the same URL downloads the ZIP.
- [ ] **Guide on v14:** see §5 — the modal must open on v14 (Bootstrap there has no Modal).

## 22. Logs provider DI (2026-10-08, ticket 2gn re-review m8)

**User:** admin.

- [ ] **Static:** `rg -n "makeInstance\(ListUtility" Classes` returns nothing; `LogsViewDataProvider` takes `ListUtility` as its 4th constructor argument.
- [ ] **Logs tab:** Flush all caches (rebuilds the DI container), open Logs for an extension with real logs (e.g. `ns_t3al` / `locallang.xlf`).
  - **Expected:** heading "Translation Logs - <extension title> (EXT: <key>)", entries render, file select / language filter / pager work; no exception.
- [ ] **Unit:** `composer test` — `LogsViewDataProviderTest` green.

## 23. Follow-up fixes after e4aa209 (2026-10-08, ticket uc2n)

**User:** admin; for the German checks set the user's backend language to German (User Settings, or `be_users.lang = 'de'`). Back up the XLF you edit.

- [ ] **Nothing to translate:** Translate tab, `ns_t3al` / `locallang.xlf`, language German (every cell filled) → **Translate**.
  - **Expected:** blue notice "Nothing to translate" + "Every label already has a translation…" (German: "Nichts zu übersetzen" + text); no provider request.
- [ ] **Success notice:** a file with empty cells → **Translate** with a configured provider.
  - **Expected:** green "Data Successfully Retrieved" + "The missing translations were filled in. Review them and click "Save"…".
- [ ] **Clear a translation:** German backend, `ns_t3al` / `locallang.xlf` + German, empty the `file.ToggleCollapse` cell → **Save**.
  - **Expected:** `de.locallang.xlf` loses only that `<trans-unit>` (4 lines, rest byte-identical); Logs tab button now reads "Toggle Collapse" (English) instead of "Ein-/Ausklappen"; the log entry lists only `file.ToggleCollapse`. Restore the file afterwards.
- [ ] **Untouched empty units:** Save without edits on a file with source-only units in the translation file.
  - **Expected:** "No changes"; file not written.
- [ ] **Gemini outage:** not forceable by hand (endpoint is fixed); covered by `GeminiServiceTest` (503 / 500 / 502 HTML / 429 → `retryLater`, one request for 160 labels, one error).
- [ ] **German labels:** Logs tab shows "Ein-/Ausklappen"; a successful Save shows "Übersetzung gespeichert".
- [ ] **Unit:** `composer test` — `LanguageFilesTest`, `XliffDocumentUpdaterClearedTranslationTest`, `GeminiServiceTest`, `XliffUtilityComputeDiffTest` green.

---

## 24. "Nothing to translate" no longer marks Save as changed (2026-10-12, ticket uj43)

**User:** admin. Use a file whose selected language is fully translated and that has at least one **multi-line** label (or create a temporary pair, e.g. `qa_multiline.xlf` + `de.qa_multiline.xlf`, and delete it afterwards). Note the md5 of the files and the newest log uid first.

- [ ] **Nothing to translate:** select the file + German → **Translate**.
  - **Expected:** blue "Nothing to translate" notice; **Save stays grey** (not red); no provider request.
- [ ] **Save without changes:** click **Save** right after that.
  - **Expected:** blue "No changes — Nothing was changed, so no language file was modified."; file md5 unchanged; no new log row. (Before the fix: "Translation file saved successfully", and multi-line labels were rewritten with `&#xD;`.)
- [ ] **Translation `0`:** a target such as `<target state="translated">0</target>` shows `0` in the grid (was empty, and saving deleted it).
- [ ] **Real edit still saves:** change one German cell (Save turns red) → **Save**.
  - **Expected:** "Translation file saved successfully"; only that unit changes, line breaks stay LF (no `&#xD;`); a log row is written.
- [ ] **Unsaved edits survive Translate:** edit a cell (or import a CSV that changes a value) → **Translate** with nothing to fill.
  - **Expected:** "Nothing to translate"; the edited value is still in the grid and **Save stays red**; leaving the module asks "Unsaved changes".
- [ ] **CSV import identical to the files:** export CSV → import the same file.
  - **Expected:** "0 existing labels updated"; Save stays grey. A CSV with a changed value turns Save red.
- [ ] **Translate that fills labels** (configured provider, a file with empty cells): Save turns red; Save shows success as before.
- [ ] **Unit:** `composer test` — `GridLabelsTest` green.

---

## 25. German module texts (2026-10-12, ticket uj5d)

**User:** admin with backend language German (User Settings → Language, or `be_users.lang = 'de'`). Flush caches after deploying (labels are cached). TYPO3 core texts (top bar, module menu groups, the Logs "Delete" tooltip) stay English unless the German core language pack is installed — that is not part of this extension.

- [ ] **Module menu:** "AI Localization".
- [ ] **Translate tab:** tabs "Übersetzen · Suche · Protokolle · Anleitung"; filter "Lokal · System · Alle"; "Datei erstellen"; buttons "Übersetzen · Speichern · CSV exportieren · ZIP exportieren · Neu laden"; grid header "Schlüssel · en (Standard)"; row actions "Hinzufügen / Entfernen / Verschieben"; "Nach Schlüssel sortieren", "CSV importieren".
- [ ] **Flash messages:** Save without edits → "Keine Änderungen"; CSV import → "CSV importiert …"; duplicate key → "Label-Schlüssel prüfen".
- [ ] **Unsaved changes:** edit a cell, then switch module → modal "Ungespeicherte Änderungen" with "Weiter bearbeiten" / "Änderungen verwerfen".
- [ ] **Search tab:** "Sprache", "Alle Sprachen", "Englisch (Quelle)", "Alles durchsuchen", "Suchergebnisse".
- [ ] **Logs tab:** "Übersetzungsprotokolle", "Datum: …", pagination "Zurück / Weiter / Seite x von y".
- [ ] **English backend:** switch the user back to English → every text above is English as before.
- [ ] **Unit:** `composer test` — `LanguageFilesTest` (same ids in `de.locallang_mod.xlf`, every unit has a target, placeholders kept).

---

## 26. Create file writes a clean empty file (2026-10-12, ticket uj5e)

**User:** admin. Local extension, e.g. `ns_t3al`. Delete the test files afterwards.

- [ ] **Create file:** type `qa_test.xlf` beside the file select → **Create file**.
  - **Expected:** the new file opens with exactly one blank row. On disk: `<xliff version="1.2" xmlns="urn:oasis:names:tc:xliff:document:1.2">`, `<file source-language="en" datatype="plaintext" original="EXT:<ext>/Resources/Private/Language/qa_test.xlf" date="<now, UTC>" product-name="<ext>">`, `<header/>`, an empty `<body>`, tab indentation, **no** `new` unit.
- [ ] **First key:** enter key `qa.first` and English text → **Save**.
  - **Expected:** "Translation file saved successfully"; the unit sits in `<body>` indented with tabs like the rest of the file.
- [ ] **First German save:** add German, enter a translation → **Save**.
  - **Expected:** `de.qa_test.xlf` is created with the same layout and attribute order (plus `target-language="de"`), tabs, `<source>` + `<target>`; the label resolves in German after a cache flush.
- [ ] **Unit:** `composer test` — `XliffFileGeneratorTest`.

---

## 27. Concurrent-edit warning names user and file (2026-10-12, ticket uj5f)

**User:** two backend users A and B, two browsers/profiles (or log out A and log in B — the lock stays).

- [ ] A opens Translate → extension + `locallang.xlf`. Within 30 seconds B opens the same file.
  - **Expected:** B sees "Warning — `<A's username>` started editing the file locallang.xlf less than a minute ago." (never "0 minutes", never "this extension"). A sees no warning.
- [ ] A reloads after ~1 minute (B's view is now the lock) — or B waits and A opens again.
  - **Expected:** "… 1 minute ago."; after 2+ minutes "… N minutes ago.".
- [ ] B reloads the same file in the same session.
  - **Expected:** no warning (own lock).
- [ ] B opens another file of the same extension.
  - **Expected:** no warning (lock is per file).
- [ ] B with backend language German.
  - **Expected:** "`<A>` hat vor weniger als einer Minute / vor 1 Minute / vor N Minuten begonnen, die Datei locallang.xlf zu bearbeiten."
- [ ] **Unit:** `composer test` — `ConcurrentEditNoticeTest` (0/29/59/60/119/120/599 s, no username → "Someone else", German).

---

## Version matrix (v12 / v13 / v14)

Re-run these on **each** supported major version before release. Cases marked **core** are highest priority per version.

| Case | v12 | v13 | v14 | Notes |
|---|:---:|:---:|:---:|---|
| Module opens; tabs + Guide modal | **core** | **core** | **core** | DocHeader / ModuleTemplate APIs differ by major |
| Local/System/All + comboboxes + grid | **core** | **core** | **core** | |
| Save local + `& < >` in XLF | **core** | **core** | **core** | High #7 |
| Admin vs non-admin gates | **core** | **core** | **core** | Critical #2 |
| Path traversal rejection | **core** | **core** | **core** | Critical #1 |
| Concurrent-edit warning (2 browsers) | yes | **core** | yes | High #8 — cache backend may differ |
| No ns_t3al AJAX routes; module access only | yes | **core** | yes | High #9 |
| EM list = core template (no override, no Style.css) | **core** | **core** | **core** | High #6 / forked EM template |
| Export ZIP from editor | yes | yes | yes | Replaces EM row export |
| Composer install pulls backend/extbase/extensionmanager/fluid | **core** | **core** | **core** | Missing composer dependencies |
| AI Translate missing-only | yes | yes | yes | Needs API keys |
| Search + Logs | yes | yes | yes | |
| Crowdin save | optional | optional | optional | High #5 open |
| System/framework package paths | optional | optional | optional | High #4 open |

**Suggestion:** Full pass on one version (usually current LTS in the workspace); on the other two, run the **core** rows only plus any version-specific ModuleTemplate/JS regressions you hit.

How to run unit tests per major, which DDEV projects to use and the v12/v13 traps (module URL token, port conflicts): `tasks/version-matrix.md`, `context/core.md` → Compatibility. Last full matrix: 2026-10-07 (§20).

---

## Known untested / open High #4–5

Do **not** treat these as release blockers for the Critical/High #6–9 work, but document outcomes if you poke them.

### High #4 — Framework package paths (open)

System/framework extensions may resolve labels via `Environment::getLabelsPath()` rather than the package `Resources/Private/Language/` tree.

- [ ] Optional: select a **System** / `typo3-cms-framework` package, pick a language pack path, Save.
  - **Expected (desired):** Writes land in the correct labels path; no write into core package tree incorrectly. (English source files of framework packages are never written — see §3a.)
  - **Known gap:** Path rules may still be wrong or incomplete — failures here are expected until #4 is fixed. Record actual vs expected paths.

### High #5 — Crowdin local / framework (open)

- [ ] Optional: Save to Crowdin for a **framework** ext (with project ID).
  - **Expected (desired):** Correct Crowdin directory/file IDs and uploads.
  - **Local extensions:** not offered by design (no button / project ID field; a forged request is refused with "Crowdin upload is only available for TYPO3 system extensions" and nothing is written).

---

## Sign-off

| Field | Value |
|---|---|
| Tester | |
| TYPO3 version(s) | |
| Date | |
| Smoke checks | ☐ PASS |
| Core manual cases | ☐ PASS / ☐ FAIL (list) |
| Open #4/#5 probed | ☐ skipped / ☐ notes attached |
| Blockers | |
