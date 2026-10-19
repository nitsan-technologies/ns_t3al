# Feature — access control and editor lock

## Who may edit what

| Who | May edit |
|---|---|
| Admin | Every extension (framework packages included; their English source stays read-only — see `ai-and-crowdin.md` → Save) |
| Non-admin with `tools_NsT3al` | Only extensions granted via group **Access Rights → Custom module options → "AI Localization: editable extensions"**; translations only — keys and English source are read-only, no Create file / Import CSV / row actions |
| No `tools_NsT3al` access | Nothing (module is `access: user`) |

- `ExtensionAccessService::canEdit()` is the **single** check (`isAdmin()` or `check('custom_options', 'ns_t3al_extensions:<key>')`).
- The option list is built in `ext_localconf.php` by `ExtensionAccessService::buildPermissionOptions()` from active packages, **framework packages omitted** (`PERMISSION_GROUP = 'ns_t3al_extensions'`, labels in `locallang_mod.xlf` `permission.extensions.*`).
- Callers: `EditorViewDataProvider::getAvailableExtensions()` filters the Translate / Search / Logs pickers; `ModuleController::guardEditableTarget()` guards save / translate / exportCsv (also requires an existing base XLF from `getFileList()`); `exportAction` only accepts extensions from `getAvailableExtensions()`; `addNoEditableExtensionsNotice()` explains an empty picker.
- There are **no AJAX routes**: every write goes through a module action, so module access + `canEdit()` cover everything. Do not add AJAX side doors.
- Paths: `XliffUtility` rejects file names and language codes containing `..`, `/` or `\`, and `assertPathContainedInLanguageDirectory()` checks with `realpath()` that the target (or its parent for new files) stays inside the language directory (symlinks included); tests in `Tests/Unit/Utility/XliffUtilityPathTest.php`.

## Concurrent-edit lock

- `EditorViewDataProvider::checkAndAcquireEditorLock()` stores `{sessid, time, username, extensionKey, file}` in cache `ns_t3al_editor_lock` (`SimpleFileBackend`, group `system`, TTL 600 s, id `lock_<sha256(ext\0file)>`), registered in `ext_localconf.php`.
- Another session on the same extension + file within 10 minutes → warning in the editor (it is a warning, not a hard lock). Flushing system caches clears all locks.
- The text comes from `Backend/View/ConcurrentEditNotice`: lock username (or "Someone else" for a lock without one), file name and the time since the lock was taken — "less than a minute", "1 minute" or "N minutes" (`concurrent_edit.message.*` in `locallang_mod.xlf`).

## Free extension, no license check

ns_t3al is free. `nitsan/ns-license` stays a required dependency (`composer.json` `require`, `ext_emconf.php` `depends`), following the NITSAN "Free" pattern, but ns_t3al never calls ns_license: there is no license check, no login / activation listener and no license gating. The module works for every user with module access, without any license key.

## Agent guardrails

- Keep `canEdit()` the only permission decision; filter lists through it, never duplicate the admin / custom_options check.
- Never offer framework packages to non-admins, never write a framework English source file.
- Do not add a license check, license gating or premium / trial CTA: the extension is free.
