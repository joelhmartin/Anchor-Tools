# Library (File Manager) runthrough — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the Anchor Tools `library` module (the client File Manager) behave like a file manager people expect: drag anything into any sensible place, a non-drag "Move to…" path, no silent failures, no state that bounces or goes stale, and fix every defect in the two audits.

**Architecture:** Server fixes land in the existing `anchor-library/src/Ajax/*.php` handlers plus one batch move endpoint, `anchor_fm_move_items`, which mirrors `anchor_fm_copy_items`. On the client, `file-manager.js` (3.2k lines, already over the 1,000-line rule) is not grown. It exposes a small `window.AnchorFMCore` context, and new or moved behaviour lives in sibling files `file-manager-dnd.js`, `file-manager-move.js` and `file-manager-upload.js`, enqueued after it and depending on it.

**Tech Stack:** WordPress, PHP 8.1+, jQuery IIFE (no ES modules, no build step), PHPUnit (`tests/test-library-*.php`, base `class-anchor-library-testcase.php`), Playwright on wp-env (`e2e/`).

**Spec:** the two audits are the requirements. Read them (with their file:line evidence) before your task:
- `.superpowers/reference/library-audit/bugs.md`: bug ids A (table), B1–B11, C1–C10, D1–D7, E1–E4, F1–F3, G1–G4.
- `.superpowers/reference/library-audit/features.md`: gap items 1–20.
- Already fixed in 3.44.14, so don't redo it: top-level folder duplicate, `copyItems` `.fail`, Ctrl/Cmd+D/V at top level, and Duplicate from search results.

## Global Constraints

- Text domain `anchor-schema`; `update_option(..., false)`; assets via `\Anchor_Asset_Loader::url('anchor-library/assets/...')`; jQuery IIFE `(function($){...})(jQuery);`.
- Never commit `*.min.*`. Run `composer install -q` to test and `git checkout -- vendor` before every commit (committed vendor/ is no-dev).
- No `alert()`, `confirm()` or `prompt()`. Use the module's existing `openConfirmModal` and `toast`.
- New source files stay under 1,000 lines and functions under 150. Don't add net lines to `file-manager.js`; move code out when you touch it.
- Don't log file names, user data or answer values. These are medical/dental clients.
- Server stays the authority: every client-side guard has a matching server check.
- Folder id 0 = top level. Only folders live there. Files, links and videos at 0 are refused with the message `Only folders can go at the top level` (the same string as 3.44.14).
- Existing tests must keep passing unedited, except where a test asserts a behaviour this plan deliberately changes (D2 file-move grant snapshot, D3 manager delete). Change those assertions and say so in the commit.
- Commit trailer: `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>` (implementers) on every commit.

## Rulings (decisions the audits left open)

1. **D2:** a file move no longer snapshots the destination folder's grants. A file with no explicit rows simply inherits its new folder; a file with explicit rows keeps them. If the permission index depends on the snapshot, update the index on move instead of snapshotting.
2. **D3:** `delete_file`, `get_permissions` and `set_permissions` require admin, matching every other writer and the UI.
3. **E3:** a move into a folder that already has that name gets a unique name through `Anchor_FM_Copy_Namer::resolve_unique($name, $existing, $is_file, false)`. Create and rename of folders refuse a same-name sibling with `A folder with that name already exists here`.
4. **E4:** renaming a file keeps the display name as typed (`sanitize_text_field`, strip `/\\`, keep the extension if the user dropped it). The stored disk name is unchanged.
5. **C1:** a non-admin's Home lists their top-most viewable folders (those whose parent they can't view), and the tree's roots are those same folders. Ancestor names they can't view are no longer sent. REST `GET /folders/0` returns the same set (parity).
6. **C2:** `bootstrap()` takes an option to stay in the current folder. Every mutation stays where the user is, and a folder move does not navigate into the destination.
7. **B6:** a double-click opens, for everyone. Rename is F2, the menu, or a slow second click on an already-selected row's name (Finder style, 500–1500 ms).
8. **Item 12, move safety:** every move (drag, picker or cut/paste) ends with a toast `Moved N item(s) to <Folder>` and an **Undo** button. Undo calls `anchor_fm_move_items` back to each item's original folder. Folders don't copy grants (they inherit, which is the hierarchy working as designed).
9. **F2, download errors:** folder and bulk downloads first call `anchor_fm_download_folder` with `check=1`, which returns JSON `{files:N}` or the error. The browser only navigates when the check succeeds. Zip entry paths are relative to the downloaded folder.
10. **D5:** the server refuses delete, rename, move, create-into and set_permissions on the Product Docs container, with the message `The Product Docs folder is managed automatically`. `ajax_list` gets the `> 0` guard.
11. **Out of scope:** a trash bin or undo for delete, an activity-log viewer, grid/thumbnail view, versions, public share links, and touch-drag shims. The "Copy share link" label becomes `Copy link`.
12. **E2:** a folder delete removes DB rows first in one pass (folders, files, links, videos, permissions, `permission_policies` for every deleted folder and file id, video_views, `_anchor_pd_docs` references), then unlinks bytes and removes each directory including its `.htaccess` and `index.php`. File delete also removes its `permission_policies` rows and `_anchor_pd_docs` references (D6).

## Review Focus

1. **Selection plus drag:** dragging a row that is part of a 5-row selection moves all 5, and a row outside the selection moves alone. Expected: no item is moved twice, and no item ends up inside itself.
2. **Mixed batch to top level:** a batch with folders and files dragged to Home moves the folders and reports each file with `Only folders can go at the top level`, in one toast.
3. **Out-of-order responses:** clicking A then B fast always ends showing B (sequence token). The same holds when a mutation's reload races a navigation.
4. **Permissions modal failure:** if loading fails, Save stays disabled and no grants change (D1).
5. **Upload over the limit:** a desktop drop of 30 files totalling more than `post_max_size` uploads in batches, and any file over `upload_max_filesize` is named in the result toast rather than silently skipped.

---

### Task 1: Server — batch move, link/video move, guards, data-path fixes

**Files:**
- Modify: `anchor-library/src/Ajax/Folders.php`, `Files.php`, `Media.php`, `Sharing.php`, `Registry.php`, `src/Copier.php`, `src/Repository.php`, `src/PermissionWrites.php` (only if D2 needs it), `src/Rest/Routes.php` (C1 parity only)
- Create: `anchor-library/src/Mover.php` (move logic shared by the single-item handlers and the batch)
- Test: `tests/test-library-moves.php` (new), plus existing `test-library-parity.php` / `test-library-permissions.php` assertion updates per the Rulings

**Interfaces:**
- Produces `anchor_fm_move_items` with POST `items` (a JSON `[{kind:'file'|'folder'|'link'|'video', id}]`) and `target_folder_id`. Response `success` with `{moved:int, errors:int, items:[{kind, sourceId, fromFolderId, status:'moved'|'error', message, name}], targetFolderId}`, where `fromFolderId` is the original folder for Undo and `name` is the final (possibly de-duplicated) name. Batch-level refusals (target missing, target is Product Docs, not admin) return `Http::error` with 400/403/404.
- `anchor_fm_move_file` and `anchor_fm_move_folder` keep their request shape and call `Mover`.
- `anchor_fm_download_folder` with `check=1` returns `Http::success(['files'=>N])` or the error, and never streams.
- Upload response gains `rejected[]` entries `{name, reason}` for each skipped file: `too_large`, `type`, `server`.
- The `ajax_bootstrap` response is unchanged. `ajax_list` and `bootstrap` tree nodes for non-admins follow Ruling 5.

- [ ] **Step 1: Write failing tests** in `tests/test-library-moves.php`:
  - `test_move_items_moves_every_kind_and_reports_from_folder`: a file, link, video and folder from A go to B; each `status==='moved'`, `fromFolderId===A`, and the DB shows `folder_id`/`parent_id === B`. The file's bytes exist at the new path.
  - `test_move_items_to_top_level_moves_folders_only`: target 0 with a folder and a file gives the folder `parent_id 0`, and the file errors with `Only folders can go at the top level`.
  - `test_move_items_refuses_into_self_or_descendant`, `test_move_items_dedupes_names` (moving `Guide.pdf` into a folder that has `Guide.pdf` gives `Guide (copy).pdf`, or whatever `resolve_unique(...,false)` returns; assert it equals that call).
  - `test_move_items_refuses_missing_target` (404) and `test_writers_refuse_deleted_folder`: upload, create_folder(parent), move_file, set_permissions and rename_folder on a deleted id all return `success:false`.
  - `test_move_file_does_not_snapshot_destination_grants` (D2): after the move, `PermissionWrites`/the permissions table has no file-level rows for the file.
  - `test_delete_folder_removes_policies_dirs_and_pd_refs` (E2/D6), `test_non_admin_manager_cannot_delete_file_or_set_permissions` (D3), `test_set_permissions_rejects_unknown_user_id` (D4), `test_product_docs_folder_is_protected` (D5).
  - `test_upload_reports_oversize_as_rejected`: simulate `$_FILES` with `UPLOAD_ERR_INI_SIZE`; `rejected[0].reason==='too_large'`.
  - `test_download_folder_check_mode` (`files` count, error when empty) and `test_zip_paths_are_relative_to_folder` (the zip entry for `/A/B/x.txt`, downloaded from B, is `x.txt`).
  - `test_home_lists_topmost_viewable_folders_for_nested_grant` (C1): grant only /A/B to a subscriber; `anchor_fm_list` folder 0 lists B; the tree has no A; REST `/folders/0` lists B.
  - `test_search_applies_permissions_before_limit` (D7c), `test_copy_folder_tree_counts_child_failures` (G1: a missing-bytes child file makes the item report an error, or the response `errors > 0`).
  - `test_rename_file_keeps_display_name` (E4: `Intake Form v2.pdf` stays as typed) and `test_folder_rename_refuses_same_name_sibling`.
- [ ] **Step 2: Run them.** `export WP_TESTS_DIR="$TMPDIR/wordpress-tests-lib" WP_CORE_DIR="$TMPDIR/wordpress"; ./vendor/bin/phpunit --filter Library`. Expected: the new tests fail. (Test DB: wp-env tests-mysql. Find the port with `docker ps | grep tests-mysql`. If the WP test lib is missing, `rm -rf "$TMPDIR/wordpress" "$TMPDIR/wordpress-tests-lib"` and run `bash bin/install-wp-tests.sh wordpress_test root password 127.0.0.1:<port> latest true`.)
- [ ] **Step 3: Implement.**
  - `Mover::move(string $kind, int $id, int $target, array &$existing, int $user_id): array|WP_Error` returns `['fromFolderId'=>, 'name'=>]`, and `Mover::move_items(array $items, int $target, int $user_id): array` builds the response above.
  - The single-item handlers delegate to Mover. Byte relocation and rollback stay as in today's `ajax_move_file`.
  - The guards, data-path fixes and Rulings 1–5, 9, 10 and 12 apply in the handlers named in the audit ids.
  - Search: for non-admins, fetch up to 5× `SEARCH_LIMIT` per kind, filter, then cap. Compute `truncated` as "a kind hit its cap".
- [ ] **Step 4: Run the full Library suite** and expect it to pass. Run `php -l` on every touched file.
- [ ] **Step 5: Commit** `feat(library): batch move, link/video move, server guards and data-path fixes`.

### Task 2: Client core — `AnchorFMCore`, error surfacing, state correctness

**Files:**
- Modify: `anchor-library/assets/js/file-manager.js`, `anchor-library/src/Portal.php` (enqueue the three new sibling files, deps `['jquery','anchor-file-manager']`, versioned with `asset_ver`)
- Create: empty-but-valid `file-manager-dnd.js`, `file-manager-move.js` and `file-manager-upload.js` IIFEs, which later tasks fill

**Interfaces:**
- Produces `window.AnchorFMCore`, assigned once at the end of the main IIFE. It contains `{ $root, $content, state, api, apiErr, toast, errMessage, loadFolder, reloadCurrentFolder, bootstrap, findRow, selectionAsItems, refreshSelectionUI, openConfirmModal, openModal, closeModal, renderTreeNodeHtml, folderName(id), uploadFiles, flashRow, isAdmin }`. Later tasks use these names exactly. Add any missing helper they need as a named function here, not a second copy.
- `api(action, data, opts)`: when `opts.quiet` is not set, attach a default `.fail` that toasts `errMessage(xhr, null, opts.fallback || 'Something went wrong')`. Expired nonce (`-1`/403 with no JSON) toasts `Your session expired. Reload the page.`
- `bootstrap({ stay: true })` rebuilds the tree and reloads `state.currentFolderId` (falling back to 0 if it's gone). `bootstrap()` with no args keeps today's first-load behaviour.
- `loadFolder(id)` uses a `loadSeq` token: stale responses are dropped. On failure it shows an inline error state with a "Back to Home" button and restores `state.currentFolderId` to the last good id.

- [ ] **Step 1:** Route every row of audit table A through `api` default-fail, or an explicit `.fail` where the UI must also reset (inline rename re-enables the input, request-access re-enables the button, viewer shows access-denied for 403/404 in `openFileViewer` like `openVideoViewer`, search/history show a message).
- [ ] **Step 2:** D1: disable the permissions modal's Save until `get_permissions` succeeds. On failure show the error in the modal body and keep Save disabled.
- [ ] **Step 3:** E1: bulk delete uses `openConfirmModal` (remove `window.confirm`). Run deletes sequentially, skipping ids already deleted as part of a selected ancestor. Report `N deleted, M failed: <first reason>`, then `bootstrap({stay:true})`.
- [ ] **Step 4:** C2/C3/C4: replace every `bootstrap(); loadFolder(...)` pair with `bootstrap({stay:true})`. Inline folder rename and bulk delete refresh the tree. The clipboard drops ids that a delete or move removed (C9). Copy/move toasts include the first per-item `message`.
- [ ] **Step 5:** Keyboard and modal fixes:
  - B6: dblclick opens; slow second click renames.
  - B7: keys apply only when focus is on a row, the list or `$root` itself, never a button/select/link.
  - B8: Escape closes the topmost modal or viewer from a document-level handler, and focus moves into the modal on open.
  - B9: document Ctrl/Cmd+C/D/V act only when focus is inside `$root` and no text is selected (`window.getSelection().toString()===''`).
  - Ctrl/Cmd+A selects all rows. C10: handle `hashchange`; `#afm-link-ID` opens the link's folder and flashes it.
- [ ] **Step 6:** Rename the menu label `Copy share link` to `Copy link`. Add client row-menu items: `Download` (file) and `Download folder` (folder, when they can view it). Folder downloads use the `check=1` preflight (Ruling 9). Bulk download names skipped files in one toast. The tree kebab Download is shown to anyone who can view the folder.
- [ ] **Step 7: Verify.** Run `node --check` on every JS file. Grep for zero `window.confirm|alert\(|prompt\(` in `anchor-library/assets/js`. Check that the `file-manager.js` line count did not grow (compare to BASE). Run the Library PHPUnit suite.
- [ ] **Step 8: Commit** `fix(library): surface every server error, keep the user in place, fix stale state and key conflicts`.

### Task 3: Drag and drop — `file-manager-dnd.js`

**Files:**
- Create/fill: `anchor-library/assets/js/file-manager-dnd.js`
- Modify: `file-manager.js` (move ALL existing drag code out, ~2770–2890 plus the `$content` upload drag handlers ~2136–2170; delete the dead `[data-afm-folder-card]`/`[data-afm-file-card]`/`[data-afm-link-card]` handlers), `file-manager.css` (delete dead `.afm__card--folder` rules; `.afm__dropzone{pointer-events:none}`; a drop-target highlight; a "dragging N items" state), and row/crumb/tree markup attributes in `file-manager.js` render functions only

**Interfaces:**
- Consumes `AnchorFMCore` (Task 2) and `anchor_fm_move_items`/`anchor_fm_copy_items` (Task 1). It calls `AnchorFMCore.uploadFiles(files, folderId)`, which Task 5 owns; until then use the existing upload function with a `folderId` param added in Task 2's core if it isn't there.
- Produces `AnchorFMMoves.afterMove(response, targetId)`, the shared Undo toast. Define it in `file-manager-move.js`'s namespace as `window.AnchorFMMove.announce(res, targetId)` so Task 4 reuses it; create the stub here if Task 4 isn't done yet.

- [ ] **Step 1:** Make one internal-drag state `{items:[{kind,id}], copy:boolean}`. Set it on `dragstart` from any row kind (file, folder, link, video) and from tree nodes (add `draggable="true"` to tree buttons for admins). When the grabbed row is selected, drag the whole selection; otherwise select it alone (B2). Set a drag image count badge with `setDragImage`.
- [ ] **Step 2:** OS-vs-internal: the upload dropzone acts only when `dataTransfer.types` includes `Files` AND no internal drag is active. Reset `dragDepth` on dragend and drop (B10). Show the upload overlay only when `canUploadHere()`, which now requires `currentFolderId > 0` (F1).
- [ ] **Step 3:** Use one delegated handler set on `$root` for these drop targets: folder rows, tree nodes, breadcrumb crumbs (`data-afm-crumb`, 0 = Home), and a Home tree root. There is no document-level drop handler (B1).
  - A target is invalid, with no highlight and `dropEffect='none'`, when it's the Product Docs folder, one of the dragged folders, or a descendant of one (check with `state.tree`).
  - Copy when Ctrl, Alt/Option or Cmd is held, with `dropEffect='copy'`; otherwise move (note A).
- [ ] **Step 4:** Drop behaviour:
  - Internal move calls `anchor_fm_move_items`, then `AnchorFMMove.announce` (an Undo toast), then `bootstrap({stay:true})`. Internal copy calls `copyItems`.
  - An OS-file drop onto a folder row or tree node uploads into that folder (item 8).
  - A drop onto the folder the items are already in is a no-op with no request (B4).
- [ ] **Step 5: Verify.** Run `node --check`, grep that `file-manager.js` has no `dragstart|dragover|'drop'` left, and check the line count went down. Run a manual reasoning pass against matrix rows 1–6 of features.md section 1 and write the result into your report.
- [ ] **Step 6: Commit** `feat(library): drag anything into any folder, crumb or Home; drag the whole selection; single drop path`.

### Task 4: Move to… picker, Cut/Paste, Undo — `file-manager-move.js`

**Files:**
- Fill: `anchor-library/assets/js/file-manager-move.js`; `file-manager.css` (picker styles); `file-manager.js` (menu items and bulk-bar buttons only)

**Interfaces:**
- Consumes `AnchorFMCore`, `anchor_fm_move_items`.
- Produces `window.AnchorFMMove = { open(items), announce(res, targetId), cut(items), pasteCut(targetId) }`. If Task 3 left a stub `announce`, replace it.

- [ ] **Step 1:** Picker modal (via core `openModal`) titled `Move N item(s) to…`, built as a keyboard-navigable tree from `state.tree` with a `Home` root.
  - Disabled nodes: the items' current folder, any dragged folder and its descendants, Product Docs, and Home when the batch has no folders.
  - Primary button `Move here`, plus a `New folder` button that creates a folder under the highlighted node and selects it.
- [ ] **Step 2:** Entry points:
  - Row menu `Move to…` for admins, all four kinds.
  - A bulk-bar `Move to…` button.
  - Ctrl/Cmd+X marks the selection as cut (rows dimmed). Ctrl/Cmd+V in a folder then moves instead of copying while a cut is pending; Escape cancels the cut.
- [ ] **Step 3:** `announce`:
  - It shows the toast `Moved N item(s) to <Folder>` with an `Undo` button (8 s), adding `, M couldn't move: <first message>` when there were errors.
  - Undo groups moved items by `fromFolderId` and calls `anchor_fm_move_items` per group, then `bootstrap({stay:true})`.
  - If the core `toast` can't take a button, add an `actions` option to core `toast` (one implementation).
- [ ] **Step 4: Verify** with `node --check`, then **Commit** `feat(library): Move to… picker, cut and paste, undo for moves`.

### Task 5: Uploads — `file-manager-upload.js`

**Files:**
- Fill: `anchor-library/assets/js/file-manager-upload.js`; modify `file-manager.js` (move the existing `uploadFiles` and its progress UI out); `Portal.php` (localize `uploadMaxBytes` = `wp_max_upload_size()` and `postMaxBytes` from `ini_get('post_max_size')` parsed with `wp_convert_hr_to_bytes`); `file-manager.css`

**Interfaces:**
- Produces `AnchorFMCore.uploadFiles(fileList|File[], folderId?)`, which defaults to `state.currentFolderId`. It returns a promise that resolves `{uploaded:[], rejected:[{name,reason}]}`. It also produces `AnchorFMUpload.dropEntries(dataTransferItems, folderId)`.

- [ ] **Step 1:** Client-side, a file over `uploadMaxBytes` is rejected as `too_large` before sending. The rest are sent in sequential batches, each under 80% of `postMaxBytes` and at most 20 files. Progress covers the total, and the bar can be cancelled.
- [ ] **Step 2:** Desktop folder drop: walk `items[i].webkitGetAsEntry()` recursively. Create each subfolder with `anchor_fm_create_folder(parent_id)`, reusing an existing same-name child (look it up in `state.tree`; the server refuses duplicates per Ruling 3), then upload its files into it. Add a `Upload folder` menu option using `<input type="file" webkitdirectory>` with the same path logic (`webkitRelativePath`).
- [ ] **Step 3:** Result toast: `N uploaded` plus each rejected file's name and reason (`too large`, `type not allowed`, `server error`), capped to 5 names plus "and K more". A drop at Home, or in search view, toasts `Open a folder to upload into it`. After upload, reload the folder that received the files only if it is still the current one.
- [ ] **Step 4: Verify** with `node --check` and the Library PHPUnit suite, then **Commit** `feat(library): batched uploads, folder upload, every skipped file reported`.

### Task 6: Playwright E2E + docs + version

**Files:**
- Create: `e2e/library-file-manager.spec.js`; extend `bin/e2e-seed.sh` idempotently with a portal page holding the Library shortcode (read `Portal.php` / LIBRARY.md for the shortcode) and folders `E2E Lib A` (containing `E2E Lib A1` and file `e2e-a.txt`) and `E2E Lib B`, written to `e2e/.seed.json`
- Modify: `anchor-library/LIBRARY.md` (moves, picker, undo, drag targets, the Ruling 5 Home behaviour, the error contract), `readme.txt` changelog `= 3.45.0 =`, `anchor-tools.php` `Version: 3.45.0`, readme `Stable tag: 3.45.0`

- [ ] **Step 1: Spec cases** (admin):
  - (a) Drag the `E2E Lib A1` row onto the `E2E Lib B` row. A1 is now under B, an Undo toast shows, and clicking Undo puts A1 back under A.
  - (b) Drag `E2E Lib A1` onto the Home crumb. It becomes top level.
  - (c) Right-click `e2e-a.txt`, Move to…, pick `E2E Lib B`, Move here. The file is in B.
  - (d) Dragging an OS file over the list doesn't break internal drag: a `dragenter` with `types` `['Files']` shows the overlay, and an internal drag never does.
  - (e) Duplicate on a top-level folder creates `<name> (copy)` at Home.
  - (f) With get_permissions blocked via `page.route` to 500, the Save button is disabled.
  - For HTML5 drag use `locator.dragTo()`. If Chromium's synthetic DnD doesn't fire `dataTransfer`, dispatch `dragstart`/`dragover`/`drop` with a shared `DataTransfer` via `page.evaluate`.
- [ ] **Step 2: Run** `npm run wp-env start`. Seed with `npx wp-env run cli --env-cwd=wp-content/plugins/<worktree-dir-name> bash bin/e2e-seed.sh` (the worktree basename, not Anchor-Tools), then `npx playwright test e2e/library-file-manager.spec.js`. Expect it to pass, and re-run once from a fresh seed.
- [ ] **Step 3: Commit** `test(library): e2e for drag, Move to…, undo and top-level duplicate; docs; 3.45.0`.
