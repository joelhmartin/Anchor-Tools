/**
 * File manager: move. The one place a move request is made and announced
 * (run), plus the non-drag ways to start one: the "Move to..." folder picker
 * (open) and cut/paste (cut, pasteCut). Drag and drop (file-manager-dnd.js)
 * calls run too. Runs on window.AnchorFMCore, so it must be enqueued after
 * file-manager.js.
 */
(function ($) {
    $(function () {
        const C = window.AnchorFMCore;
        if (!C) return;
        const { state, esc } = C;
        const pdId = Number((window.AnchorFM && window.AnchorFM.productDocsFolderId) || 0);
        // Names of folders the picker created or chose, for the toast, which can
        // be shown before the refreshed tree arrives.
        const names = {};
        const plural = n => n + ' item' + (n === 1 ? '' : 's');
        const folderLabel = id => Number(id) > 0 ? (C.folderName(id) || names[id] || 'folder') : 'Home';

        // ---- announce + undo -------------------------------------------------

        // Undo sends each moved item back to the folder it came from, grouped by
        // that folder so one request covers every item that shared an origin.
        // Groups run one after another so name de-duplication sees each result.
        function undo(moved) {
            const groups = {};
            moved.forEach(function (i) {
                (groups[i.fromFolderId] = groups[i.fromFolderId] || []).push({ kind: i.kind, id: i.sourceId });
            });
            let failed = 0, reason = '';
            const step = Object.keys(groups).reduce(function (prev, from) {
                return prev.then(function () {
                    return C.api('anchor_fm_move_items', {
                        items: JSON.stringify(groups[from]),
                        target_folder_id: Number(from)
                    }, { fallback: 'Undo failed' }).then(function (res) {
                        const d = (res && res.data) || {};
                        failed += d.errors || 0;
                        const bad = (d.items || []).find(function (i) { return i.status === 'error' && i.message; });
                        if (bad && !reason) reason = bad.message;
                    });
                });
            }, $.Deferred().resolve().promise());
            step.done(function () {
                C.toast(failed ? failed + " couldn't move back" + (reason ? ': ' + reason : '') : 'Move undone');
            }).always(function () { C.bootstrap({ stay: true }); });
        }

        /**
         * res is the anchor_fm_move_items response; targetId the destination.
         * Shows "Moved N item(s) to <Folder>" with an Undo button, and the
         * first per-item failure message when some items were refused.
         */
        function announce(res, targetId) {
            const d = (res && res.data) || {};
            const rows = d.items || [];
            const moved = rows.filter(function (i) { return i.status === 'moved'; });
            const bad = rows.find(function (i) { return i.status === 'error' && i.message; });
            const where = folderLabel(targetId);
            let msg;
            if (moved.length) msg = 'Moved ' + plural(moved.length) + ' to ' + where;
            else msg = !d.errors && rows.length ? 'Already in ' + where : 'Nothing was moved';
            if (d.errors) msg += ', ' + d.errors + " couldn't move" + (bad ? ': ' + bad.message : '');
            C.toast(msg, moved.length ? { label: 'Undo', onClick: function () { undo(moved); } } : null);
        }

        /**
         * The single move path (picker, cut/paste, drag): request, drop moved
         * items from the clipboard and selection, announce, refresh in place.
         * Returns the request so a caller can react to success or failure.
         */
        function run(items, targetId) {
            C.$root.addClass('afm--busy');
            return C.api('anchor_fm_move_items', {
                items: JSON.stringify(items.map(function (i) { return { kind: i.kind, id: i.id }; })),
                target_folder_id: Number(targetId)
            }, { fallback: 'Move failed' }).done(function (res) {
                const moved = (((res && res.data) || {}).items || [])
                    .filter(function (i) { return i.status === 'moved'; })
                    .map(function (i) { return { kind: i.kind, id: i.sourceId }; });
                C.forgetClipboard(moved);
                state.selectedRows.clear();
                announce(res, targetId);
                C.bootstrap({ stay: true });
            }).always(function () { C.$root.removeClass('afm--busy'); });
        }

        // ---- cut / paste -----------------------------------------------------

        // A cut is the clipboard with a flag, so "Paste here", C9 clean-up and the
        // dimming all follow the one clipboard. Copying afterwards replaces it.
        const hasCut = () => !!(state.clipboard && state.clipboard.cut && C.clipboardItems().length);

        function markCut() {
            C.$content.find('.is-cut').removeClass('is-cut');
            if (!hasCut()) return;
            C.clipboardItems().forEach(function (i) {
                C.$content.find('[data-afm-row="' + i.kind + ':' + i.id + '"]').addClass('is-cut');
            });
        }

        function cut(items) {
            if (!C.isAdmin || !items || !items.length) return;
            state.clipboard = { items: items.slice(), cut: true };
            markCut();
            C.toast(plural(items.length) + ' cut. Open a folder and paste to move.');
        }

        function cancelCut() {
            if (!hasCut()) return false;
            state.clipboard = null;
            markCut();
            C.toast('Cut cancelled');
            return true;
        }

        // Returns false when no cut is pending, so the caller falls back to copy.
        function pasteCut(targetId) {
            if (!hasCut()) return false;
            run(C.clipboardItems(), targetId);
            return true;
        }

        // ---- picker ----------------------------------------------------------

        // Where an item lives now: a search hit, an expanded row or the open folder.
        function homeOf(it) {
            const hit = state.searchFolderById && state.searchFolderById[it.kind + ':' + it.id];
            return typeof hit === 'number' ? hit : C.folderOfRow(it.kind, it.id);
        }

        function cloneTree(nodes) {
            return (nodes || []).map(function (n) {
                return { id: Number(n.id), name: n.name, pd: !!n.isProductDocs || Number(n.id) === pdId, children: cloneTree(n.children) };
            });
        }

        function open(items) {
            items = (items || []).map(function (i) { return { kind: i.kind, id: Number(i.id) }; });
            if (!C.isAdmin || !items.length) return;
            const home = { id: 0, name: 'Home', pd: false, children: cloneTree(state.tree) };
            const byId = {}, parentOf = {};
            (function index(n, p) { byId[n.id] = n; parentOf[n.id] = p; n.children.forEach(function (c) { index(c, n.id); }); })(home, -1);

            const hasFolder = items.some(function (i) { return i.kind === 'folder'; });
            const homes = items.map(homeOf);
            const blocked = new Set();   // folders being moved, and everything inside them
            (function mark(n, on) {
                const now = on || items.some(function (i) { return i.kind === 'folder' && i.id === n.id; });
                if (now) blocked.add(n.id);
                n.children.forEach(function (c) { mark(c, now); });
            })(home, false);
            // Already where they would land: every item lives in that folder.
            const isCurrent = id => homes.every(function (h) { return h === id; });
            const disabled = id => (id === 0 && !hasFolder) || (byId[id] && byId[id].pd) || blocked.has(id) || isCurrent(id);
            const canHold = id => !(byId[id] && byId[id].pd) && !blocked.has(id);

            const expanded = new Set([0]);
            let cur = null;
            const visible = function () {
                const out = [];
                (function walk(n, level) {
                    out.push({ n: n, level: level });
                    if (expanded.has(n.id)) n.children.forEach(function (c) { walk(c, level + 1); });
                })(home, 1);
                return out;
            };
            // Open on the first folder that can take the batch, with its ancestors showing.
            const first = visible().concat(Object.keys(byId).map(function (k) { return { n: byId[k] }; }))
                .find(function (v) { return !disabled(v.n.id); });
            cur = first ? first.n.id : 0;
            for (let p = parentOf[cur]; p !== undefined && p >= 0; p = parentOf[p]) expanded.add(p);

            const body = '<div class="afm__pick">'
                + '<div class="afm__pickBar"><button type="button" class="afm__btn afm__btn--secondary" data-afm-pick-new>New folder</button>'
                + '<span class="afm__pickStatus" data-afm-pick-status aria-live="polite"></span></div>'
                + '<div class="afm__pickNew" data-afm-pick-newform hidden>'
                + '<input type="text" class="afm__input" data-afm-pick-name maxlength="190" placeholder="Folder name" aria-label="New folder name">'
                + '<button type="button" class="afm__btn afm__btn--primary" data-afm-pick-create>Create</button>'
                + '<button type="button" class="afm__btn afm__btn--ghost" data-afm-pick-cancel>Cancel</button></div>'
                + '<div class="afm__pickTree" role="tree" aria-label="Destination folder" data-afm-pick-tree></div></div>';
            const title = 'Move ' + plural(items.length) + ' to…';
            window.AnchorFMUI.modal.open(title, body, 'Move here', go);
            const $b = window.AnchorFMUI.modal.body();
            const $tree = $b.find('[data-afm-pick-tree]'), $status = $b.find('[data-afm-pick-status]');
            const $form = $b.find('[data-afm-pick-newform]'), $name = $b.find('[data-afm-pick-name]');

            function render(focus) {
                $tree.html(visible().map(function (v) {
                    const n = v.n, kids = n.children.length, off = disabled(n.id);
                    return '<div class="afm__pickItem' + (n.id === cur ? ' is-selected' : '') + (off ? ' is-disabled' : '') + '" role="treeitem"'
                        + ' data-afm-pick-id="' + n.id + '" aria-level="' + v.level + '"'
                        + (kids ? ' aria-expanded="' + (expanded.has(n.id) ? 'true' : 'false') + '"' : '')
                        + ' aria-selected="' + (n.id === cur ? 'true' : 'false') + '"' + (off ? ' aria-disabled="true"' : '')
                        + ' tabindex="' + (n.id === cur ? '0' : '-1') + '" style="padding-left:' + (8 + (v.level - 1) * 18) + 'px">'
                        + (kids ? '<span class="afm__pickToggle dashicons dashicons-arrow-' + (expanded.has(n.id) ? 'down' : 'right') + '-alt2" data-afm-pick-toggle="' + n.id + '" aria-hidden="true"></span>'
                            : '<span class="afm__pickToggle" aria-hidden="true"></span>')
                        + '<span class="dashicons dashicons-' + (n.id === 0 ? 'admin-home' : 'category') + '" aria-hidden="true"></span>'
                        + '<span class="afm__pickName">' + esc(n.name) + '</span></div>';
                }).join(''));
                const ok = !disabled(cur);
                window.AnchorFMUI.modal.busy(!ok);
                $status.text(ok ? 'Move to: ' + byId[cur].name : (blocked.has(cur) ? 'A folder cannot go inside itself.' : (byId[cur].pd ? 'Product Docs is managed automatically.' : 'Pick another folder.')));
                if (focus) $tree.find('[data-afm-pick-id="' + cur + '"]')[0].focus({ preventScroll: false });
            }

            function go() {
                if (disabled(cur) || $b.data('busy')) return;
                $b.data('busy', true);
                window.AnchorFMUI.modal.busy(true, 'Moving…');
                names[cur] = byId[cur].name;
                run(items, cur).done(function () { window.AnchorFMUI.modal.close(); })
                    .fail(function () { window.AnchorFMUI.modal.busy(false, 'Move here'); })
                    .always(function () { $b.data('busy', false); });
            }

            function create() {
                const name = String($name.val() || '').trim();
                if (!name || $b.data('busy')) return;
                if (!canHold(cur)) { $status.text('A folder cannot be created there.'); return; }
                $b.data('busy', true);
                C.api('anchor_fm_create_folder', { name: name, parent_id: cur }, { quiet: true }).done(function (res) {
                    const id = Number(res && res.data && res.data.folderId);
                    if (!id) { $status.text('Could not create the folder.'); return; }
                    const node = { id: id, name: name, pd: false, children: [] };
                    byId[cur].children.push(node);
                    byId[id] = node; parentOf[id] = cur;
                    expanded.add(cur);
                    names[id] = name;
                    cur = id;
                    $name.val('');
                    $form.prop('hidden', true);
                    render(true);
                    C.bootstrap({ stay: true });
                }).fail(function (xhr) {
                    $status.text(C.errMessage(xhr, null, 'Could not create the folder.'));
                    $name.trigger('focus');
                }).always(function () { $b.data('busy', false); });
            }

            function select(id) { cur = id; render(true); }

            $tree.on('click', '[data-afm-pick-id]', function (e) {
                const id = Number($(this).data('afm-pick-id'));
                if ($(e.target).is('[data-afm-pick-toggle]')) {
                    if (expanded.has(id)) expanded.delete(id); else expanded.add(id);
                }
                select(id);
            });
            $tree.on('keydown', '[data-afm-pick-id]', function (e) {
                const list = visible(), idx = list.findIndex(function (v) { return v.n.id === cur; });
                const n = byId[cur];
                const go2 = i => { e.preventDefault(); select(list[Math.max(0, Math.min(list.length - 1, i))].n.id); };
                if (e.key === 'ArrowDown') go2(idx + 1);
                else if (e.key === 'ArrowUp') go2(idx - 1);
                else if (e.key === 'Home') go2(0);
                else if (e.key === 'End') go2(list.length - 1);
                else if (e.key === 'ArrowRight') {
                    e.preventDefault();
                    if (n.children.length && !expanded.has(cur)) { expanded.add(cur); render(true); } else if (n.children.length) select(n.children[0].id);
                } else if (e.key === 'ArrowLeft') {
                    e.preventDefault();
                    if (n.children.length && expanded.has(cur)) { expanded.delete(cur); render(true); } else if (parentOf[cur] >= 0) select(parentOf[cur]);
                } else if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); go(); }
            });

            $b.find('[data-afm-pick-new]').on('click', function () {
                $form.prop('hidden', false);
                $name.trigger('focus');
            });
            $b.find('[data-afm-pick-create]').on('click', create);
            $b.find('[data-afm-pick-cancel]').on('click', function () { $form.prop('hidden', true); render(true); });
            $name.on('keydown', function (e) {
                if (e.key === 'Enter') { e.preventDefault(); create(); }
                else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); $form.prop('hidden', true); render(true); }
            });

            render(false);
            window.setTimeout(function () {
                const el = $tree.find('[data-afm-pick-id="' + cur + '"]')[0];
                if (el) el.focus();
            }, 0);
        }

        window.AnchorFMMove = { open: open, announce: announce, run: run, cut: cut, pasteCut: pasteCut, cancelCut: cancelCut, hasCut: hasCut };
    });
})(jQuery);
