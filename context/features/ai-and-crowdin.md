# Feature — AI translation and Crowdin

## Providers

Selected in the Translate toolbar (`Toolbar.html`). `AiProviderResolver` holds the only provider → API key setting map: `isConfigured()` (used by `TranslationService::validate()`) and `resolve()` (used by `ModuleController`: a known requested provider is kept, otherwise the first configured one, otherwise `''`):

| Provider | Ext conf | Service |
|---|---|---|
| OpenAI | `openaiApiKey`, `openaiApiModel` | `OpenAiService` |
| Gemini | `geminiApiKey`, `geminiApiModel` | `GeminiService` |
| DeepL | `deeplApiKey`, `deeplApiUrl` | `DeepLService` |
| Google Translate | `googleApiKey`, `googleApiUrl` | `GoogleService` |

Model names are allowlisted for OpenAI and Gemini: `GeminiService::ALLOWED_MODELS` (`gemini-2.5-flash`, `gemini-2.5-flash-lite`, `gemini-3.6-flash`) mirrors the toolbar options and `ext_conf_template.txt`. The first allowed value of requested model (`models[gemini]`) and configured `geminiApiModel` wins, otherwise `DEFAULT_MODEL`, so a forged value never reaches the request URL. Add a new model to all three places.

### Structured, count-checked requests

`TranslationService::request()` sends batches of 50 labels (empty sources are skipped) and **never maps results by position of free text** (the old `#SP#` separator protocol is gone):

| Provider | Request | Response mapping |
|---|---|---|
| OpenAI | JSON object `{labelKey: source}` + `response_format: json_schema` (`strict`, every key `required`, `additionalProperties: false`) | decoded JSON object, by key |
| Gemini | same JSON object + `responseMimeType: application/json` (no `responseSchema` — per-key schemas exceed Gemini's complexity limit) | decoded JSON object, by key (thought parts ignored, ```json fences stripped) |
| DeepL | JSON body `text[]` + `target_lang`, `Authorization: DeepL-Auth-Key` header (never `auth_key` in the body); no `source_lang` (DeepL auto-detects) | `translations[]`, count must equal request |
| Google | JSON body `q[]` | `data.translations[]`, count must equal request |

#### DeepL target languages

`DeepLLanguageMapper` turns the TYPO3 language key into a current DeepL target code, most specific first (`pt_BR` → `PT-BR`, `de_AT` → `DE`). DeepL's deprecated bare codes are never sent: `en` → `EN-GB`, `pt` → `PT-PT` (the variants DeepL used for `EN` / `PT`), TYPO3 `zh` (Chinese Traditional) → `ZH-HANT`, `ch` / `zh_CN` → `ZH-HANS`, `no` → `NB`. The code must be in the target list from `DeepLLanguageProvider`: `GET /v3/languages?resource=translate_text` (same host as `deeplApiUrl`, header auth, `usable_as_target`), cached 24 h in cache `ns_t3al_deepl_languages`; on any failure, or for a `deeplApiUrl` not ending in `/v2/translate` (proxy), it uses `DeepLLanguageMapper::FALLBACK_TARGET_LANGUAGES` and logs a warning. Languages DeepL cannot translate into (e.g. `kl`, `fo`) return `translation.deepL.unsupported_language.message` without a translate request.

Shared helpers live in `Classes/Utility/LabelBatchUtility.php` (prompt, JSON encode/decode, schema, positional → key mapping).

Each batch is accepted **only** if the response has exactly the requested keys, each a non-empty string. Otherwise the whole batch is rejected — no translation can land on a wrong key:

- All batches rejected → `status: false` + provider error or `translation.responseMismatch.message` → error flash, nothing changes.
- Some batches rejected → `status: true`, `content` holds only validated labels, plus `title`/`message` (`translation.partialFailure.*`, "X of Y labels…") → module shows a **warning** flash per language. Rejected labels stay empty / unchanged.

### Error handling (never an exception to the caller)

- Providers (`OpenAiService`, `DeepLService`, `GoogleService`, `GeminiService`) catch every `\Throwable` and return their status array via `ProviderErrorTrait::providerFailure()`, which logs the error (`provider`, HTTP `status`, exception) and picks the message: timeout / network (`ConnectException`) → `translation.provider.unreachable`, 5xx → `translation.provider.server_error`, other 4xx (bad key, quota, model) → `translation.provider.rejected`, anything else → `translation.generation.error`. Gemini sends `http_errors: false` and reports its own messages for other 4xx; its 429 / 5xx responses go through `providerHttpFailure()` (same messages and `retryLater` as `providerFailure()`).
- Network errors, 429 and 5xx carry `retryLater: true` (`ProviderErrorTrait::isRetryLaterStatus()`): `TranslationService` then stops the remaining batches (already validated labels are kept as a partial result) instead of letting the user wait for every batch to fail the same way. Other 4xx only fail their own batch.
- `TranslationService::requestBatch()` also catches `\Throwable` (failed batch, logged). Unknown language codes use the code itself as language title (`getLanguages()[$iso] ?? $iso`).
- The caller (`ModuleController::translateAction`) catches `\Throwable`, log it and show the generic error — never the raw exception text.

## Translate action (AI fill)

1. User clicks **Translate** → POST `translateAction` with current grid `labels` / `keys`, langs, provider, models.
2. Resolves provider from the request: keeps the selected known provider even if its key is missing (so the correct key warning can be shown). Does **not** silently fall back to OpenAI.
3. Validates API key / provider config; flashes that provider’s error and returns to `list` on failure (empty selection → neutral “no key configured” message). The UI must keep the same provider selected after the flash (`selectedApiModel` hidden + module data + PHP `apiModelIs.*` flags + JS `data-ns-t3al-api-model-default`).
4. For each non-`default` language, builds a batch of cells that need translation.
5. **Missing-only (hard-coded):** if a target cell is already non-empty, it is **skipped**. There is no module UI for “Overwrite all”.
6. Merges AI results into labels and **forwards to `list`** with `overrideLabels` (in-memory / form only — **not** written to disk yet).

Provider selection is persisted in BE module data (`apiModel`) until the user picks another provider. Toolbar must mark the active provider via PHP boolean flags (`apiModelIs.deepl`, etc.) — do not compare string literals in Fluid (`{apiModelDefault} == deepl` treats `deepl` as a variable and always fails).

Do not reintroduce a `translationMode` dropdown on the Translate module toolbar.

## Save action (disk + optional Crowdin)

1. **Save** → `fileLocation=local` → for each selected language `EditorViewDataProvider::buildLanguageFile()` applies the grid to the existing XLF **in place** (`XliffDocumentUpdater`): renames keep position, removed keys are deleted, new keys are appended (no empty `<target>` in translation files), a cleared translation removes its unit (and duplicates) from the translation file so TYPO3 falls back to English, only changed `<source>` / `<target>` texts are replaced. Header, notes, `date`, `approved`, `state`, `xml:space`, comments, CDATA, `<group>` plural units and formatting survive (`XliffFormatRestorer` keeps untouched lines byte-identical). A target file's `<source>` is refreshed only for units whose translation changed. New files are generated with `XliffUtility::generateXml()`.
2. Unchanged files are **not written** — saving one translation never rewrites the English `default` file. When nothing changed at all an INFO flash "No changes" is shown. When at least one file was written the core `l10n` cache is flushed once (`flushLabelCache()`), so the new labels are used immediately everywhere in the backend.
3. **Sort by key** is only applied on Save when the sort was requested (hidden `sortByKey` field), never implicitly.
4. Flash messaging: **one** local success (or write-failure) flash for the whole save — not once per `langKey` (server always includes `default`).
5. System extensions (`typo3-cms-framework`): the English source and keys are read-only for everyone (Core files in `typo3/sysext` / `vendor/typo3/cms-*`); translations go to `Environment::getLabelsPath()`. A forced source / key edit is ignored with a "Source file is read-only" warning; **Create file** is refused.
6. After a save without write failures the action **redirects** (303, Post/Redirect/Get) to `list`, which renders from disk — ignored edits never look saved and a browser reload cannot re-post the save (no second write, upload or log row). Flash messages survive the redirect. With a write failure it forwards instead, keeping the posted grid.
7. **Save to Crowdin** → **system extensions only** (`typo3-cms-framework`): files are resolved in the TYPO3 Core Crowdin project layout (`<crowdinSystemExtensionPath>/<ext>/…`, default `/main/typo3/sysext`), so local extensions do not get the button / project ID field, and a forged request is refused with `translation.write.crowdin.error.system_extensions_only` before anything is written. Requires a Crowdin project ID (checked in the browser with a "Project ID is required" notification and again on the server) and a configured `accessToken` (`CrowdinService::hasAccessToken()`; otherwise `translation.write.crowdin.error.token_missing` and nothing is written); after successful local write, `CrowdinService` updates remote files for non-default languages (`accessToken`, `crowdinApiUrl`). Unchanged files are still uploaded when Crowdin is requested. Crowdin result flashes remain per upload.
   - Upload flow (`CrowdinService::uploadTranslation()`): `GET projects/{id}` (target languages; on 404 a `GET user` tells an invalid token apart) → language id via `resolveLanguageId()` (`toCrowdinLanguageId()`: `_`→`-`, plain keys with Crowdin region ids `es`→`es-ES`, `pt`→`pt-PT`, `sv`→`sv-SE`, `zh`/`ch`→`zh-CN`, …; then matched against the project's `targetLanguageIds`) → extension directory by **exact path** → file by **exact path** `…/<ext>/Resources/Private/Language/<file>` (recursive list, sub folders allowed; only an unambiguous suffix match as fallback) → storage → translation import.
   - Lists use `limit=500` + `offset` paging (`fetchAll()`, cap 100 pages). 401/403 anywhere → `translation.write.crowdin.error.auth`; distinct `project_not_found`, `file_not_found` (with path), `language_not_in_project`.
   - `crowdinApiUrl` is normalized (trim, exactly one trailing slash, empty → default). `accessToken` uses `Configuration/PasswordField` (`type=user[…]`, masked input that reads the stored value itself because core passes only the template default).
   - Request paths are built by `CrowdinService::buildPath()`: every path segment is `rawurlencode()`d and the query string comes from one `http_build_query()` call (no second `?`, file names with spaces / `&` / `=` are safe).
   - `CrowdinService` public methods never throw: `request()` returns the response also for 4xx/5xx (caller reports "Error Code : N") and `null` when Crowdin is unreachable (`translation.write.crowdin.error.unreachable`); every failure is logged. `ModuleController::uploadToCrowdin()` additionally catches `\Throwable` — an upload problem is an error flash, never a backend error page, and the log row below is still written.
8. Save diffs / outcomes are recorded in `tx_nst3al_domain_model_logs` (`Logs` model / repository) as plain-text JSON (`{unitId: {source: {del, ins}, target: {del, ins}}}`, no HTML); unit ids are compared as array keys, never interpolated into XPath. Diff computation and the file write are guarded: a failing write (e.g. directory cannot be created) counts as a write failure (error flash + log row with status 0) instead of an exception.

UI-only grid: there is no CodeMirror / raw XLF editing path on the module editor. Save always applies the label grid.

## CSV

- **Export CSV** — downloads current grid. Every cell goes through `CsvCellEscaper::escape()`: a value starting with `=`, `+`, `-`, `@`, tab or CR (after any leading `'`) gets a `'` prefix so spreadsheet apps show it as text instead of running a formula.
- **Import CSV** (admin, editable target only) — loads into grid as `overrideLabels`; disk write still requires **Save**. The CSV is **merged** with the file (`EditorViewDataProvider::mergeImportedLabels()`): imported cells replace existing ones, new keys are appended, keys and languages missing from the CSV are kept, and the CSV's languages are added to the grid. An INFO flash reports updated / added counts; "updated" counts only existing keys where at least one grid language gets a **different** text (re-importing an unchanged export reports 0 updated). Header `en`, `default` or `… (default)` means the English source. Imported keys go through the key guard on Save. The upload is copied into `php://temp` (no temporary file left on disk); a missing / failed upload shows "CSV import failed". Keys and values go through `CsvCellEscaper::unescape()`, which removes exactly the prefix `escape()` added, so export → import is lossless (values like `'quoted` stay untouched).

## Agent guardrails

- Prefer missing-only AI behaviour when changing `translateAction`.
- Keep Crowdin upload behind explicit Save-to-Crowdin + project ID, and limited to system extensions (UI and server guard) until a Crowdin layout for local extensions exists.
