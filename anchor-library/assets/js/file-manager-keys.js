/**
 * Keyboard and rename gestures for the file manager list: row navigation,
 * select-all, copy/paste/duplicate shortcuts, F2, Escape, and the
 * "slow second click renames" gesture. Runs on window.AnchorFMCore, so it must
 * be enqueued after file-manager.js.
 */
(function ($) {
    $(function () {
        const C = window.AnchorFMCore;
        if (!C) return;
        const { $root, state, isAdmin } = C;
        const $grid = $root.find('[data-afm-grid]');
        const $modal = $root.find('[data-afm-modal]');

        const rowOf = (kind, id) => $grid.find(`[data-afm-row="${kind}:${id}"]`);

        function openRowDefault($r) {
            const kind = $r.data('afm-row-kind'), id = Number($r.data('afm-row-id'));
            if (kind === 'folder') C.loadFolder(id);
            else if (kind === 'file' || kind === 'video') C.openViewer(kind, id);
            else if (kind === 'link') { const l = C.findRow('link', id); if (l && l.url) window.open(l.url, '_blank', 'noopener'); }
        }

        function setActive($rows, idx) {
            $rows.removeClass('is-active');
            const $r = $rows.eq(idx).addClass('is-active');
            if ($r[0]) { $r[0].focus({ preventScroll: true }); $r[0].scrollIntoView({ block: 'nearest' }); }
        }

        // B9: Ctrl/Cmd+C, V, D belong to the list only while focus is inside it
        // and nothing is selected as text. Anywhere else they are the browser's.
        $(document).on('keydown', function (e) {
            if (!isAdmin || !(e.ctrlKey || e.metaKey) || e.altKey || e.shiftKey) return;
            const key = String(e.key || '').toLowerCase();
            if (key !== 'c' && key !== 'v' && key !== 'd') return;
            if (!$root[0].contains(document.activeElement)) return;
            if ($(e.target).is('input, textarea, select') || (e.target && e.target.isContentEditable)) return;
            if (window.getSelection && window.getSelection().toString() !== '') return;
            if (!$root.is(':visible') || !C.filesTabActive() || !$modal.prop('hidden')) return;

            if (key === 'c') {
                const items = C.selectionAsItems();
                if (items.length) { e.preventDefault(); C.setClipboard(items); }
            } else if (key === 'v') {
                const items = C.clipboardItems();
                if (items.length) { e.preventDefault(); C.copyItems(items, state.currentFolderId); }
            } else {
                const items = C.selectionAsItems();
                if (items.length) { e.preventDefault(); C.copyItems(items, state.currentFolderId); }
            }
        });

        // B8: Escape closes the topmost menu/modal/viewer from anywhere on the page.
        $(document).on('keydown', function (e) {
            if (e.key === 'Escape' && C.closeTopmost()) e.preventDefault();
        });

        // B7: list keys act only when focus is on a row, the list or the manager
        // itself - never on a toolbar button, select or link.
        $root.on('keydown', function (e) {
            const t = e.target;
            if (!(t === $root[0] || $(t).is('.afm__list, .afm__row, [data-afm-grid]'))) return;
            if (!C.filesTabActive()) return;
            const $rows = $grid.find('.afm__row');
            const mod = e.ctrlKey || e.metaKey;
            if (mod && String(e.key).toLowerCase() === 'a') {
                if (!$rows.length) return;
                e.preventDefault();
                $rows.each(function () { state.selectedRows.add($(this).data('afm-row')); });
                C.refreshSelectionUI();
                return;
            }
            if (mod || e.altKey || !$rows.length) return;

            let $cur = $(t).closest('.afm__row');
            if (!$cur.length) $cur = $grid.find('.afm__row.is-active');
            if (!$cur.length && state.selectedRows.size === 1) {
                $cur = $rows.filter(function () { return state.selectedRows.has($(this).data('afm-row')); }).first();
            }
            const idx = $rows.index($cur);
            const kind = $cur.data('afm-row-kind'), id = Number($cur.data('afm-row-id'));

            if (e.key === 'ArrowDown') { e.preventDefault(); setActive($rows, Math.min($rows.length - 1, idx + 1)); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); setActive($rows, Math.max(0, idx - 1)); }
            else if (e.key === 'ArrowRight' && kind === 'folder' && !state.expandedRows[id]) {
                $cur.find('[data-afm-row-expand]').trigger('click');
                const r = rowOf(kind, id)[0]; if (r) r.focus({ preventScroll: true });
            }
            else if (e.key === 'ArrowLeft' && kind === 'folder' && state.expandedRows[id]) {
                $cur.find('[data-afm-row-expand]').trigger('click');
                const r = rowOf(kind, id)[0]; if (r) r.focus({ preventScroll: true });
            }
            else if (e.key === 'Enter' && $cur.length) { openRowDefault($cur); }
            else if (e.key === ' ' && $cur.length) {
                e.preventDefault();
                if (kind === 'file' || kind === 'video') C.openViewer(kind, id);
            }
            else if (e.key === 'F2' && isAdmin && $cur.length) { C.startInlineRename(kind, id); }
        });

        // B6: a double-click opens (the row handler). Rename is F2, the menu, or a
        // slow second click (0.5-1.5 s) on the name of the row that is already the
        // only selection. This runs before the row's own click handler, so
        // selectedRows still describes the state before this click.
        let lastLabelClick = { key: '', t: 0 };
        $root.on('click', '[data-afm-row-label]', function (e) {
            if (!isAdmin || e.shiftKey || e.metaKey || e.ctrlKey) return;
            const $r = $(this).closest('[data-afm-row]');
            const key = String($r.data('afm-row'));
            const dt = Date.now() - lastLabelClick.t;
            const armed = lastLabelClick.key === key && dt >= 500 && dt <= 1500
                && state.selectedRows.size === 1 && state.selectedRows.has(key);
            lastLabelClick = armed ? { key: '', t: 0 } : { key, t: Date.now() };
            if (armed) C.startInlineRename($r.data('afm-row-kind'), Number($r.data('afm-row-id')));
        });
    });
})(jQuery);
