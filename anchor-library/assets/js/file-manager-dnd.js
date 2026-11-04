/**
 * File manager: drag and drop. One internal-drag state, one delegated handler
 * set on $root, no document-level drop handler.
 *
 * Sources: any list row (file, folder, link, video; the whole selection when
 * the grabbed row is selected) and admin tree buttons.
 * Targets: folder rows, tree nodes (including Home), breadcrumb crumbs.
 * OS files: upload into the folder under the pointer, else the open folder.
 * Runs on window.AnchorFMCore, so it must be enqueued after file-manager.js.
 */
(function ($) {
    $(function () {
        const C = window.AnchorFMCore;
        if (!C || !C.isAdmin) return;
        const { $root, $content, state } = C;
        const pdId = Number((window.AnchorFM && window.AnchorFM.productDocsFolderId) || 0);
        const TARGET = '[data-afm-row-kind="folder"], [data-afm-open-folder], [data-afm-crumb]';

        // {items:[{kind,id,from}], copy:boolean}; null when no internal drag is active.
        let drag = null;
        let osDepth = 0;
        let overEl = null;

        const dt = e => e.originalEvent.dataTransfer;
        const hasFiles = e => !!dt(e) && Array.prototype.indexOf.call(dt(e).types || [], 'Files') !== -1;
        const wantsCopy = e => !!(e.originalEvent.ctrlKey || e.originalEvent.altKey || e.originalEvent.metaKey);
        const searching = () => !!(state.search && state.search.length >= 2);

        function targetOf(el) {
            const $el = $(el).closest(TARGET);
            if (!$el.length) return null;
            const raw = $el.attr('data-afm-row-id');
            const id = $el.is('[data-afm-crumb]') ? $el.attr('data-afm-crumb')
                : ($el.is('[data-afm-open-folder]') ? $el.attr('data-afm-open-folder') : raw);
            return { $el, id: Number(id) };
        }

        function isDescendant(id, ancestorId) {
            let cur = id;
            for (let n = 0; cur && n < 200; n++) {
                if (cur === ancestorId) return true;
                cur = state.parentById[cur] || 0;
            }
            return false;
        }

        // Product Docs, a dragged folder itself, or inside one: not a place to drop.
        function validTarget(id) {
            if (pdId && id === pdId) return false;
            const items = drag.items;
            if (items.some(i => i.kind === 'folder' && isDescendant(id, i.id))) return false;
            // Only folders live at the top level; a batch with none can't go there.
            if (id === 0 && !items.some(i => i.kind === 'folder')) return false;
            return true;
        }

        // B4: nothing to do when every dragged item already sits in the target.
        const isNoop = id => drag.items.every(i => i.from === id);

        function clearOver() {
            $root.find('.is-drop').removeClass('is-drop');
            overEl = null;
        }
        function setOver($el) {
            if (overEl && overEl[0] === $el[0]) return;
            clearOver();
            $el.addClass('is-drop');
            overEl = $el;
        }
        function endDrag() {
            drag = null;
            osDepth = 0;
            clearOver();
            $root.removeClass('afm--drag afm--dragMode');
            $root.find('.is-dragging').removeClass('is-dragging');
        }

        // ---- internal drag: start -------------------------------------------

        function itemFrom(kind, id, $row) {
            if (kind === 'folder') return { kind, id, from: state.parentById[id] || 0 };
            const top = $row && !searching() && Number($row[0].style.getPropertyValue('--afm-depth') || 0) === 0;
            return { kind, id, from: top ? state.currentFolderId : null };
        }

        function dragBadge(n, e) {
            if (n < 2 || !dt(e).setDragImage) return;
            const $b = $('<div class="afm__dragBadge"></div>').text(n + ' items')
                .css({ position: 'absolute', top: '-200px', left: '-200px', padding: '6px 12px', borderRadius: '999px', background: '#1d2327', color: '#fff', font: '600 13px sans-serif' })
                .appendTo(document.body);
            dt(e).setDragImage($b[0], 10, 10);
            window.setTimeout(() => $b.remove(), 0);
        }

        $root.on('dragstart', '[data-afm-row], .afm__treeBtn[draggable]', function (e) {
            const $src = $(this);
            let items;
            if ($src.is('[data-afm-row]')) {
                const key = String($src.attr('data-afm-row'));
                // B2: dragging a selected row drags the selection; otherwise the row alone.
                if (!state.selectedRows.has(key)) {
                    state.selectedRows.clear();
                    state.selectedRows.add(key);
                    state.lastSelectedKey = key;
                    C.refreshSelectionUI();
                }
                items = C.selectionAsItems().map(i => {
                    const $r = $root.find('[data-afm-row="' + i.kind + ':' + i.id + '"]').first();
                    return itemFrom(i.kind, i.id, $r);
                });
            } else {
                const id = Number($src.attr('data-afm-open-folder'));
                if (!(id > 0)) { e.preventDefault(); return; }
                items = [itemFrom('folder', id)];
            }
            items = items.filter(i => !(i.kind === 'folder' && pdId && i.id === pdId));
            if (!items.length) { e.preventDefault(); return; }
            drag = { items, copy: false };
            const d = dt(e);
            d.effectAllowed = 'copyMove';
            d.setData('text/plain', items.length + ' item(s)');
            dragBadge(items.length, e);
            $root.addClass('afm--dragMode').removeClass('afm--drag');
            items.forEach(i => $root.find('[data-afm-row="' + i.kind + ':' + i.id + '"]').addClass('is-dragging'));
        });

        $root.on('dragend', endDrag);

        // ---- over / leave ---------------------------------------------------

        $root.on('dragover', TARGET, function (e) {
            const t = targetOf(this);
            if (!t) return;
            const d = dt(e);
            if (drag) {
                if (!validTarget(t.id)) { d.dropEffect = 'none'; clearOver(); return; }
                drag.copy = wantsCopy(e);
                e.preventDefault();
                d.dropEffect = drag.copy ? 'copy' : 'move';
                setOver(t.$el);
            } else if (hasFiles(e) && t.id > 0 && !(pdId && t.id === pdId)) {
                e.preventDefault();
                e.stopPropagation(); // keep the whole-pane overlay logic out of it
                d.dropEffect = 'copy';
                $root.removeClass('afm--drag');
                setOver(t.$el);
            }
        });

        $root.on('dragleave', TARGET, function (e) {
            const rel = e.originalEvent.relatedTarget;
            if (rel && $(this).has(rel).length) return;
            $(this).removeClass('is-drop');
            if (overEl && overEl[0] === this) overEl = null;
        });

        // ---- drop (the one path) -------------------------------------------

        function flash($el) {
            $el.addClass('is-drop-flash');
            window.setTimeout(() => $el.removeClass('is-drop-flash'), 500);
        }

        function dropInternal(t, copy) {
            const items = drag.items.map(i => ({ kind: i.kind, id: i.id }));
            if (!validTarget(t.id)) return;
            if (copy) { flash(t.$el); C.copyItems(items, t.id); return; }
            if (isNoop(t.id)) return; // B4: no request
            flash(t.$el);
            $root.addClass('afm--busy');
            C.api('anchor_fm_move_items', { items: JSON.stringify(items), target_folder_id: t.id }, { fallback: 'Move failed' })
                .done(function (res) {
                    const moved = (((res && res.data) || {}).items || [])
                        .filter(i => i.status === 'moved').map(i => ({ kind: i.kind, id: i.sourceId }));
                    C.forgetClipboard(moved);
                    state.selectedRows.clear();
                    window.AnchorFMMove.announce(res, t.id);
                    C.bootstrap({ stay: true });
                })
                .always(() => $root.removeClass('afm--busy'));
        }

        $root.on('drop', TARGET, function (e) {
            const t = targetOf(this);
            if (!t) return;
            if (drag) {
                e.preventDefault();
                e.stopPropagation();
                const copy = wantsCopy(e);
                dropInternal(t, copy);
                endDrag();
            } else if (hasFiles(e)) {
                e.preventDefault();
                e.stopPropagation();
                const files = dt(e).files;
                endDrag();
                if (t.id > 0 && C.filesTabActive()) C.uploadFiles(files, t.id);
            }
        });

        // ---- OS files onto the open folder (the pane overlay) --------------

        function osEvent(e) {
            return !drag && hasFiles(e) && C.filesTabActive();
        }
        // A folder row / tree node / crumb under the pointer takes the files itself
        // (the delegated $root handlers above), so the pane handlers step aside.
        function overUploadTarget(e) {
            const t = targetOf(e.target);
            return !!t && t.id > 0 && !(pdId && t.id === pdId);
        }
        $content.on('dragenter', function (e) {
            if (!osEvent(e)) return;
            e.preventDefault();
            osDepth++;
            if (C.canUploadHere()) $root.addClass('afm--drag');
        });
        $content.on('dragover', function (e) {
            if (!osEvent(e)) return;
            if (overUploadTarget(e)) { $root.removeClass('afm--drag'); return; }
            e.preventDefault(); // otherwise the browser would open the dropped file
            if (C.canUploadHere()) $root.addClass('afm--drag');
            dt(e).dropEffect = C.canUploadHere() ? 'copy' : 'none';
        });
        $content.on('dragleave', function (e) {
            if (!osEvent(e)) return;
            osDepth = Math.max(0, osDepth - 1);
            if (!osDepth || !e.originalEvent.relatedTarget) { osDepth = 0; $root.removeClass('afm--drag'); }
        });
        $content.on('drop', function (e) {
            if (!osEvent(e) || overUploadTarget(e)) return;
            e.preventDefault();
            e.stopPropagation();
            osDepth = 0;
            $root.removeClass('afm--drag');
            clearOver();
            if (C.canUploadHere()) C.uploadFiles(dt(e).files);
        });

        // Anywhere else in the portal, an OS file drop does nothing (rather than
        // the browser navigating away to the file). This is not an upload path.
        $root.on('dragover drop', function (e) {
            if (!drag && hasFiles(e)) e.preventDefault();
        });
    });
})(jQuery);
