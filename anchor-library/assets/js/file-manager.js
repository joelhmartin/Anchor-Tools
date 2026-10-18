jQuery(function ($) {
    const $root = $('[data-afm]');
    if (!$root.length) return;

    const portalMode = $root.is('[data-apfm]');

    const $tree = $root.find('[data-afm-tree]');
    const $grid = $root.find('[data-afm-grid]');
    const $breadcrumbs = $root.find('[data-afm-breadcrumbs]');
    const $drawer = $root.find('[data-afm-drawer]');
    const $drawerTitle = $root.find('[data-afm-drawer-title]');
    const $preview = $root.find('[data-afm-preview]');
    const $meta = $root.find('[data-afm-meta]');
    const $drawerActions = $root.find('[data-afm-drawer-actions]');
    const $modal = $root.find('[data-afm-modal]');
    const $modalBody = $root.find('[data-afm-modal-body]');
    const $modalTitle = $root.find('.afm__modalTitle');
    const $modalPrimary = $root.find('[data-afm-action="modal-primary"]');
    const $productDocs = $root.find('[data-afm-product-docs]');
    const $productSelect = $root.find('[data-afm-product-select]');
    const $productDocsManage = $root.find('[data-afm-product-docs-manage]');
    const $productDocsNotice = $root.find('[data-afm-product-docs-notice]');
    const $productDocUpload = $('<input type="file" accept="*/*" data-afm-doc-upload style="display:none;">');
    $root.append($productDocUpload);
    const $search = $root.find('[data-afm-search]');
    const $dropzone = $root.find('[data-afm-dropzone]');
    const $content = $root.find('.afm__content');
    const $fileInput = $root.find('[data-afm-file-input]');
    const $uploadBtn = $root.find('[data-afm-action="upload"]');
    const $linkBtn = $root.find('[data-afm-action="new-link"]');
    const $frame = $root.find('.afm__frame');
    const $resizer = $root.find('[data-afm-resizer]');

    const productDocsFolderId = Number(AnchorFM.productDocsFolderId || 0);
    let externalModalHandler = null;
    const state = {
        tab: 'files',
        tree: [],
        currentFolderId: 0,
        currentCapability: 'view',
        currentList: { folders: [], files: [] },
        selectedFileId: 0,
        selectedEntity: null,
        rolesDraft: [],
        usersDraft: [],
        policyDraft: { operator: 'any', rules: [] },
        modalMode: '',
        modalPayload: null,
        menuContext: null,
        search: '',
        products: [],
        productDocsDraft: [],
        expandedNodes: new Set(),
        parentById: {},
        childrenByParent: {},
        sortKey: 'name',
        sortDir: 'asc',
        expandedRows: {},
        // Children keyed by folder id, kept across collapse so re-expanding is
        // free. Dropped wholesale by loadFolder()/reloadCurrentFolder(), which
        // is where anything that could have changed the contents lands.
        childCache: {},
        childPending: {},
        // Bumped by loadFolder(). A child fetch started before a navigation
        // must not write its result into the state that navigation just reset.
        childGeneration: 0,
        selectedRows: new Set(),
        clipboard: null,
    };

    const SIDEBAR_MIN = 220;
    const SIDEBAR_MAX = 420;
    let isResizing = false;

    function api(action, data) {
        return $.post(AnchorFM.ajax, Object.assign({ action, nonce: AnchorFM.nonce }, data || {}));
    }

    function esc(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function fmtSize(bytes) {
        const n = Number(bytes || 0);
        if (!n) return '0 B';
        const units = ['B', 'KB', 'MB', 'GB', 'TB'];
        let i = 0;
        let v = n;
        while (v >= 1024 && i < units.length - 1) {
            v /= 1024;
            i++;
        }
        return (Math.round(v * 10) / 10) + ' ' + units[i];
    }

    function setSidebarWidth(width) {
        if (!$root.length) return;
        $root.get(0).style.setProperty('--afm-sidebar-width', `${width}px`);
    }

    function clamp(num, min, max) {
        return Math.min(max, Math.max(min, num));
    }

    /**
     * Uploading is administrator-only on the server — can_user_upload_to_folder()
     * is a plain administrator check and ignores the folder entirely. Folder
     * capability alone is not enough (a non-admin folder owner resolves to
     * 'manage'), so every upload affordance goes through here to stay honest
     * about what the server will actually accept.
     */
    function canUploadHere() {
        return !!AnchorFM.isAdmin && capRank(state.currentCapability) >= 2;
    }

    function capRank(cap) {
        switch (cap) {
            case 'manage': return 3;
            case 'upload': return 2;
            case 'view': return 1;
            default: return 0;
        }
    }

    function renderTreeNode(node) {
        const isOwner = node.ownerUserId && AnchorFM.user && node.ownerUserId === AnchorFM.user.id;
        const labelText = esc(node.name);
        const label = labelText + (node.isPrivate ? ' <span class="afm__tag">Private</span>' : '');
        const hasChildren = node.children && node.children.length;
        const isExpanded = hasChildren && state.expandedNodes.has(node.id);
        const childrenHtml = hasChildren
            ? `<div class="afm__treeChildren" data-afm-tree-children="${node.id}">${node.children.map(renderTreeNode).join('')}</div>`
            : '';

        return `
            <div class="afm__treeNode ${hasChildren ? (isExpanded ? 'is-expanded' : 'is-collapsed') : 'is-leaf'}" data-afm-folder-node="${node.id}">
                <div class="afm__treeRow">
                    ${hasChildren ? `<button type="button" class="afm__treeToggle" data-afm-tree-toggle="${node.id}" aria-label="Toggle folder" aria-expanded="${isExpanded ? 'true' : 'false'}">
                        <span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
                    </button>` : `<span class="afm__treeToggle is-placeholder" aria-hidden="true"></span>`}
                    <button type="button" class="afm__treeBtn" data-afm-open-folder="${node.id}" title="${labelText}">
                        <span class="dashicons dashicons-category afm__treeIcon" aria-hidden="true"></span>
                        <span class="afm__treeLabel">${label}</span>
                    </button>
                    ${(AnchorFM.isAdmin && !node.isProductDocs) ? `<button type="button" class="afm__kebab afm__kebab--tree" data-afm-folder-menu="${node.id}" aria-label="Folder actions">
                        <span class="dashicons dashicons-ellipsis" aria-hidden="true"></span>
                    </button>` : ''}
                </div>
                ${childrenHtml}
            </div>
        `;
    }

    function renderTree(tree) {
        const html = tree && tree.length ? tree.map(renderTreeNode).join('') : `<div class="afm__empty">${esc(AnchorFM.i18n.noFolders)}</div>`;
        $tree.html(html);
        highlightTreeSelection();
    }

    function rebuildTreeIndex() {
        state.parentById = {};
        state.childrenByParent = {};
        const walk = (nodes, parentId) => {
            (nodes || []).forEach(node => {
                state.parentById[node.id] = parentId;
                if (!state.childrenByParent[parentId]) state.childrenByParent[parentId] = [];
                state.childrenByParent[parentId].push(node.id);
                if (node.children && node.children.length) {
                    walk(node.children, node.id);
                }
            });
        };
        walk(state.tree || [], 0);
        const nextExpanded = new Set();
        state.expandedNodes.forEach(id => {
            if (Object.prototype.hasOwnProperty.call(state.parentById, id)) {
                nextExpanded.add(id);
            }
        });
        state.expandedNodes = nextExpanded;
    }

    function collapseBranch(nodeId) {
        state.expandedNodes.delete(nodeId);
        const kids = state.childrenByParent[nodeId] || [];
        kids.forEach(child => collapseBranch(child));
    }

    function openNode(nodeId) {
        const parentId = state.parentById[nodeId] || 0;
        const siblings = state.childrenByParent[parentId] || [];
        siblings.forEach(sibling => {
            if (sibling !== nodeId) collapseBranch(sibling);
        });
        state.expandedNodes.add(nodeId);
    }

    function openBranch(folderId) {
        const path = [];
        let current = Number(folderId);
        while (current && Object.prototype.hasOwnProperty.call(state.parentById, current)) {
            path.unshift(current);
            current = state.parentById[current];
        }
        let parentId = 0;
        path.forEach(id => {
            const siblings = state.childrenByParent[parentId] || [];
            siblings.forEach(sibling => {
                if (sibling !== id) collapseBranch(sibling);
            });
            state.expandedNodes.add(id);
            parentId = id;
        });
    }

    function toggleNode(nodeId) {
        const id = Number(nodeId);
        const kids = state.childrenByParent[id] || [];
        if (!kids.length) return;
        if (state.expandedNodes.has(id)) {
            collapseBranch(id);
        } else {
            openNode(id);
        }
        renderTree(state.tree);
    }

    function highlightTreeSelection() {
        $tree.find('[data-afm-open-folder]').removeClass('is-active');
        $tree.find(`[data-afm-open-folder="${state.currentFolderId}"]`).addClass('is-active');
    }

    function renderBreadcrumbs(crumbs) {
        let html = `<button type="button" class="afm__crumb" data-afm-crumb="0">Home</button>`;
        (crumbs || []).forEach(c => {
            html += `<span class="afm__crumbSep">/</span>`;
            html += `<button type="button" class="afm__crumb" data-afm-crumb="${c.id}">${esc(c.name)}</button>`;
        });
        $breadcrumbs.html(html);
    }

    // Small progress ring. r=8 so the circumference is 2*pi*8 = 50.27; the
    // dash offset is the unfilled remainder.
    function watchRing(pct) {
        const p = Math.max(0, Math.min(100, Number(pct)));
        if (!isFinite(p)) return '';
        const c = 50.27;
        const offset = (c * (100 - p) / 100).toFixed(2);
        const label = p + '% watched';
        return `<span class="afm__watchRing" title="${esc(label)}" aria-label="${esc(label)}">
            <svg viewBox="0 0 20 20" width="15" height="15" aria-hidden="true" focusable="false">
                <circle class="afm__watchRingTrack" cx="10" cy="10" r="8" fill="none" stroke-width="3"></circle>
                <circle class="afm__watchRingFill" cx="10" cy="10" r="8" fill="none" stroke-width="3"
                        stroke-dasharray="${c}" stroke-dashoffset="${offset}"
                        transform="rotate(-90 10 10)"></circle>
            </svg><span class="afm__watchPct">${esc(p)}%</span></span>`;
    }

    function iconForMime(mime) {
        if (!mime) return 'media-default';
        if (mime.startsWith('image/')) return 'format-image';
        if (mime === 'application/pdf') return 'pdf';
        if (mime.startsWith('audio/')) return 'format-audio';
        if (mime.startsWith('video/')) return 'format-video';
        if (mime.startsWith('text/')) return 'media-text';
        return 'media-document';
    }

    function matchesSearch(name) {
        const q = (state.search || '').trim().toLowerCase();
        if (!q) return true;
        return String(name || '').toLowerCase().includes(q);
    }

    function kindLabel(kind, mime) {
        if (kind === 'folder') return 'Folder';
        if (kind === 'link') return 'Link';
        if (kind === 'video') return 'Video';
        return (mime || 'File');
    }

    function rowKey(kind, id) { return kind + ':' + id; }

    function clipboardItems() {
        return (state.clipboard && Array.isArray(state.clipboard.items)) ? state.clipboard.items : [];
    }
    function setClipboard(items) {
        state.clipboard = { items: items.slice() };
        if (typeof toast === 'function') {
            toast(items.length + ' item' + (items.length === 1 ? '' : 's') + ' copied');
        }
    }
    function selectionAsItems() {
        return Array.from(state.selectedRows).map(function (k) {
            const p = String(k).split(':');
            return { kind: p[0], id: Number(p[1]) };
        });
    }
    function copyItems(items, targetFolderId) {
        if (!items || !items.length) return;
        $root.addClass('afm--busy');
        return api('anchor_fm_copy_items', {
            items: JSON.stringify(items),
            target_folder_id: targetFolderId
        }).done(function (res) {
            if (res && res.success) {
                const d = res.data || {};
                let msg = (d.copied || 0) + ' copied';
                if (d.errors) { msg += ', ' + d.errors + ' failed'; }
                if (typeof toast === 'function') toast(msg);
                bootstrap();
                loadFolder(state.currentFolderId);
            } else {
                const m = res && res.data && res.data.message;
                if (typeof toast === 'function') toast(m || 'Copy failed');
            }
        }).always(function () { $root.removeClass('afm--busy'); });
    }

    $(document).on('keydown', function (e) {
        if (!AnchorFM.isAdmin) return;
        if (!(e.ctrlKey || e.metaKey)) return;
        // Don't hijack typing or shortcuts while a field/modal is focused.
        const tag = (e.target && e.target.tagName) ? e.target.tagName.toLowerCase() : '';
        if (tag === 'input' || tag === 'textarea' || tag === 'select' || (e.target && e.target.isContentEditable)) return;
        if ($root.find('.afm__modal').is(':visible')) return;
        if (!$root.is(':visible')) return;

        const key = String(e.key || '').toLowerCase();
        if (key === 'c') {
            const items = selectionAsItems();
            if (items.length) { e.preventDefault(); setClipboard(items); }
        } else if (key === 'v') {
            const items = clipboardItems();
            if (items.length && state.currentFolderId > 0) { e.preventDefault(); copyItems(items, state.currentFolderId); }
        } else if (key === 'd') {
            const items = selectionAsItems();
            if (items.length && state.currentFolderId > 0) { e.preventDefault(); copyItems(items, state.currentFolderId); }
        }
    });

    function rowIcon(item) {
        if (item.kind === 'folder') return 'category';
        if (item.kind === 'link') return 'admin-links';
        if (item.kind === 'video') return 'video-alt3';
        return iconForMime(item.mime);
    }

    /**
     * Row glyph. Videos show their Vimeo thumbnail when we have one; anything
     * else (and any thumbnail that fails to load — these URLs are Vimeo's CDN
     * and do expire) falls back to the dashicon via the inline onerror.
     */
    function rowIconHtml(item) {
        if (item.kind === 'video' && item.thumbnailUrl) {
            return `<span class="afm__rowIcon afm__rowIcon--thumb">
                <img src="${esc(item.thumbnailUrl)}" alt="" loading="lazy"
                     onerror="this.parentNode.classList.remove('afm__rowIcon--thumb');this.parentNode.classList.add('dashicons','dashicons-video-alt3');this.remove();">
            </span>`;
        }
        return `<span class="afm__rowIcon dashicons dashicons-${rowIcon(item)}"></span>`;
    }

    function currentRows(list) {
        const rows = [];
        (list.folders || []).forEach(f => rows.push({ kind: 'folder', id: f.id, name: f.name, isPrivate: f.isPrivate }));
        (list.videos || []).forEach(v => rows.push({ kind: 'video', id: v.id, name: v.title, vimeoId: v.vimeoId, vimeoHash: v.vimeoHash, thumbnailUrl: v.thumbnailUrl, createdAt: v.createdAt, watchPercent: v.watchPercent }));
        (list.links || []).forEach(l => rows.push({ kind: 'link', id: l.id, name: l.title, url: l.url, createdAt: l.createdAt }));
        (list.files || []).forEach(f => rows.push({ kind: 'file', id: f.id, name: f.name, mime: f.mime, size: f.size, createdAt: f.createdAt, watchPercent: f.watchPercent }));
        return rows;
    }

    const naturalNameCollator = window.Intl && window.Intl.Collator
        ? new window.Intl.Collator(undefined, { numeric: true, sensitivity: 'base' })
        : null;

    function compareNames(a, b) {
        let av = String(a || '');
        let bv = String(b || '');
        if (naturalNameCollator) return naturalNameCollator.compare(av, bv);
        av = av.toLowerCase();
        bv = bv.toLowerCase();
        if (av < bv) return -1;
        if (av > bv) return 1;
        return 0;
    }

    function sortRows(rows) {
        const dir = state.sortDir === 'desc' ? -1 : 1;
        const folderRank = r => (r.kind === 'folder' ? 0 : 1);
        return rows.slice().sort((a, b) => {
            if (folderRank(a) !== folderRank(b)) return folderRank(a) - folderRank(b);
            let av, bv;
            switch (state.sortKey) {
                case 'size': av = a.size || 0; bv = b.size || 0; break;
                case 'kind': av = kindLabel(a.kind, a.mime); bv = kindLabel(b.kind, b.mime); break;
                case 'modified': av = a.createdAt || ''; bv = b.createdAt || ''; break;
                default: return compareNames(a.name, b.name) * dir;
            }
            if (av < bv) return -1 * dir;
            if (av > bv) return 1 * dir;
            return compareNames(a.name, b.name);
        });
    }

    function rowHtml(item, depth) {
        const pad = 12 + (depth || 0) * 20;
        const selected = state.selectedRows.has(rowKey(item.kind, item.id)) ? ' is-selected' : '';
        const disclosure = item.kind === 'folder'
            ? `<button type="button" class="afm__rowDisclosure" data-afm-row-expand="${item.id}" aria-label="Expand"><span class="dashicons dashicons-arrow-right-alt2"></span></button>`
            : `<span class="afm__rowDisclosure afm__rowDisclosure--empty"></span>`;
        const sizeText = item.kind === 'file' ? esc(fmtSize(item.size)) : '—';
        const modified = item.createdAt ? esc(String(item.createdAt).slice(0, 10)) : '—';
        // Files and folders can be moved (drag onto a folder row or the sidebar tree).
        const draggable = (AnchorFM.isAdmin && (item.kind === 'file' || item.kind === 'folder')) ? ' draggable="true"' : '';
        return `
            <div class="afm__row afm__row--${item.kind}${selected}"${draggable}
                 data-afm-row="${item.kind}:${item.id}"
                 data-afm-row-kind="${item.kind}" data-afm-row-id="${item.id}"
                 style="--afm-row-pad:${pad}px" tabindex="-1">
                <div class="afm__rowCell afm__rowName">
                    ${disclosure}
                    ${rowIconHtml(item)}
                    <span class="afm__rowLabel" data-afm-row-label>${esc(item.name)}</span>
                    ${(typeof item.watchPercent === 'number') ? watchRing(item.watchPercent) : ''}
                </div>
                <div class="afm__rowCell afm__rowKind">${esc(kindLabel(item.kind, item.mime))}</div>
                <div class="afm__rowCell afm__rowSize">${sizeText}</div>
                <div class="afm__rowCell afm__rowModified">${modified}</div>
                <div class="afm__rowCell afm__rowActions">
                    <button type="button" class="afm__kebab" data-afm-row-menu="${item.kind}:${item.id}"><span class="dashicons dashicons-ellipsis"></span></button>
                </div>
            </div>`;
    }

    function headerHtml() {
        const arrow = k => state.sortKey === k ? (state.sortDir === 'asc' ? ' ▲' : ' ▼') : '';
        return `
            <div class="afm__listHead">
                <button type="button" class="afm__rowCell afm__rowName afm__sortBtn" data-afm-sort="name">Name${arrow('name')}</button>
                <button type="button" class="afm__rowCell afm__rowKind afm__sortBtn" data-afm-sort="kind">Kind${arrow('kind')}</button>
                <button type="button" class="afm__rowCell afm__rowSize afm__sortBtn" data-afm-sort="size">Size${arrow('size')}</button>
                <button type="button" class="afm__rowCell afm__rowModified afm__sortBtn" data-afm-sort="modified">Modified${arrow('modified')}</button>
                <div class="afm__rowCell afm__rowActions"></div>
            </div>`;
    }

    function renderRowTree(item, depth) {
        let html = rowHtml(item, depth);
        if (item.kind === 'folder' && state.expandedRows[item.id]) {
            sortRows(state.expandedRows[item.id]).forEach(child => { html += renderRowTree(child, depth + 1); });
        }
        return html;
    }

    function renderList(list, capability) {
        state.currentList = list || { folders: [], files: [], links: [], videos: [] };
        state.currentCapability = capability || state.currentCapability;

        // Capability gating for the toolbar controls (restored from the old card grid).
        // Every write endpoint behind these controls is administrator-only on
        // the server, so the role check has to lead — a non-admin folder owner
        // resolves to 'manage' here and would otherwise be shown controls that
        // 403 on every click.
        const canManage = AnchorFM.isAdmin && capRank(state.currentCapability) >= 3;
        const canUpload = canManage && state.currentFolderId > 0;
        const canAddLink = canManage && state.currentFolderId > 0;
        $root.toggleClass('afm--canCreateFolder', canManage);
        $root.toggleClass('afm--canUpload', canUpload);
        if ($uploadBtn.length) { $uploadBtn.prop('disabled', !canUpload); }
        if ($linkBtn.length) { $linkBtn.prop('disabled', !canAddLink); }

        const rows = sortRows(currentRows(state.currentList));
        if (!rows.length) {
            $grid.html(headerHtml() + `<div class="afm__empty">${esc(AnchorFM.i18n.noFiles)}</div>`);
            return;
        }
        let html = headerHtml() + '<div class="afm__list" tabindex="0">';
        rows.forEach(item => { html += renderRowTree(item, 0); });
        html += '</div>';
        $grid.html(html);
        Object.keys(state.expandedRows).forEach(fid => {
            $grid.find(`[data-afm-row-expand="${fid}"]`).addClass('is-open');
        });
    }

    function openDrawer() {
        $drawer.addClass('is-open');
        $root.addClass('afm--drawerOpen');
    }

    function closeDrawer() {
        state.selectedFileId = 0;
        $drawer.removeClass('is-open');
        $root.removeClass('afm--drawerOpen');
        $drawerTitle.text('Select a file');
        $preview.html('');
        $meta.html('');
        $drawerActions.html('');
    }

    function openModal() {
        $modal.prop('hidden', false);
        $root.addClass('afm--modalOpen');
    }

    function closeModal() {
        $modal.prop('hidden', true);
        $root.removeClass('afm--modalOpen');
        state.selectedEntity = null;
        state.rolesDraft = [];
        state.usersDraft = [];
        state.policyDraft = { operator: 'any', rules: [] };
        state.modalMode = '';
        state.modalPayload = null;
        externalModalHandler = null;
        $modalBody.html('');
        $modal.find('.afm__modalPanel').removeClass('afm__modalPanel--viewer');
        $modal.find('.afm__modalFooter [data-afm-action="modal-primary"]').show();
        $modal.find('.afm__modalFooter [data-afm-action="close-modal"]').text('Cancel');
        $modal.find('.afm__viewerFooter').empty();
        if (typeof stopVideoTracking === 'function') stopVideoTracking();
    }

    function setModalPrimary(label, mode, payload) {
        $modalPrimary.text(label || 'Save');
        state.modalMode = mode || '';
        state.modalPayload = payload || null;
    }

    function setModalBusy(busy, label) {
        $modalPrimary.prop('disabled', !!busy);
        if (label) $modalPrimary.text(label);
    }

    function videoNotice(msg) {
        $modalBody.find('[data-afm-video-notice]').prop('hidden', false).text(msg);
    }

    /**
     * Pull the server's message out of a wp_send_json_error response.
     *
     * wp_send_json_error(..., 400) makes jQuery *reject* the deferred, so the
     * body arrives on jqXHR.responseJSON in a .fail() handler — never as the
     * argument to .done(). Reading only the success path is why a real
     * diagnosis used to surface as a bare console 400.
     */
    function errMessage(jqXHR, res, fallback) {
        const fromXHR = jqXHR && jqXHR.responseJSON && jqXHR.responseJSON.data && jqXHR.responseJSON.data.message;
        const fromRes = res && res.data && res.data.message;
        return fromXHR || fromRes || fallback;
    }

    function openTextModal(opts) {
        const title = opts && opts.title ? String(opts.title) : 'Edit';
        const label = opts && opts.primaryLabel ? String(opts.primaryLabel) : 'Save';
        const placeholder = opts && opts.placeholder ? String(opts.placeholder) : '';
        const value = opts && typeof opts.value !== 'undefined' ? String(opts.value) : '';
        const mode = opts && opts.mode ? String(opts.mode) : 'text';
        const payload = opts && opts.payload ? opts.payload : null;

        $modalTitle.text(title);
        $modalBody.html(`
            <div class="afm__fieldRow">
                <label class="afm__label">${esc(title)}</label>
                <input type="text" class="afm__input" data-afm-modal-input placeholder="${esc(placeholder)}" value="${esc(value)}">
                <div class="afm__help">${esc(opts && opts.help ? opts.help : '')}</div>
            </div>
        `);
        setModalPrimary(label, mode, payload);
        openModal();
        window.setTimeout(() => $modalBody.find('[data-afm-modal-input]').trigger('focus'), 0);
    }

    function openConfirmModal(opts) {
        const title = opts && opts.title ? String(opts.title) : 'Confirm';
        const label = opts && opts.primaryLabel ? String(opts.primaryLabel) : 'Confirm';
        const mode = opts && opts.mode ? String(opts.mode) : 'confirm';
        const payload = opts && opts.payload ? opts.payload : null;
        const message = opts && opts.message ? String(opts.message) : '';

        $modalTitle.text(title);
        $modalBody.html(`<div class="afm__help">${esc(message)}</div>`);
        setModalPrimary(label, mode, payload);
        openModal();
    }

    function openLinkModal(opts) {
        const title = opts && opts.title ? String(opts.title) : 'Link';
        const label = opts && opts.primaryLabel ? String(opts.primaryLabel) : 'Save';
        const mode = opts && opts.mode ? String(opts.mode) : 'link';
        const payload = opts && opts.payload ? opts.payload : null;
        const linkTitle = opts && opts.linkTitle ? String(opts.linkTitle) : '';
        const linkUrl = opts && opts.linkUrl ? String(opts.linkUrl) : '';

        $modalTitle.text(title);
        $modalBody.html(`
            <div class="afm__fieldRow">
                <label class="afm__label">Title</label>
                <input type="text" class="afm__input" data-afm-link-title placeholder="Link title" value="${esc(linkTitle)}">
            </div>
            <div class="afm__fieldRow">
                <label class="afm__label">URL</label>
                <input type="url" class="afm__input" data-afm-link-url placeholder="https://example.com" value="${esc(linkUrl)}">
                <div class="afm__help">Use a full URL starting with https://</div>
            </div>
        `);
        setModalPrimary(label, mode, payload);
        openModal();
        window.setTimeout(() => $modalBody.find('[data-afm-link-title]').trigger('focus'), 0);
    }

    function loadFolder(folderId) {
        // Switching folders is a full browse-view transition: cancel any in-flight
        // search and drop dataset-scoped caches so queued search callbacks or stale
        // expanded rows can't repaint over the new folder.
        clearTimeout(searchTimer);
        state.searchSeq = (state.searchSeq || 0) + 1;
        state.search = '';
        $search.val('');
        state.searchFolderById = {};
        state.searchRowsByKey = {};
        state.expandedRows = {};
        state.childCache = {};
        state.childPending = {};
        state.childGeneration++;
        state.selectedRows.clear();
        if (typeof renderBulkBar === 'function') renderBulkBar();
        state.currentFolderId = Number(folderId);
        openBranch(state.currentFolderId);
        renderTree(state.tree);
        closeDrawer();
        $root.addClass('afm--busy');
        $grid.html('<div class="afm__skeleton afm__skeleton--share"></div>');
        $root.find('[data-afm-panel]').removeClass('is-active');
        $root.find('[data-afm-panel="files"]').addClass('is-active');
        api('anchor_fm_list', { folder_id: state.currentFolderId }).done(res => {
            if (!res || !res.success) return;
            renderBreadcrumbs(res.data.breadcrumbs);
            state.lastBreadcrumbs = res.data.breadcrumbs;
            seedChildCache(res.data.contents);
            renderList({ folders: res.data.folders, links: res.data.links, files: res.data.files, videos: res.data.videos }, res.data.capability);
            $root.trigger('anchorfm:folderLoaded', {
                folderId: state.currentFolderId,
                capability: res.data.capability,
                isProductDocs: res.data.isProductDocs
            });
            if (!state.deepLinkChecked) {
                state.deepLinkChecked = true;
                handleDeepLink();
            }
        }).always(() => {
            $root.removeClass('afm--busy');
        });
    }

    function reloadCurrentFolder() {
        loadFolder(state.currentFolderId);
    }

    function bootstrap() {
        $root.addClass('afm--busy');
        $tree.html('<div class="afm__skeleton afm__skeleton--share"></div>');
        api('anchor_fm_bootstrap', {}).done(res => {
            if (!res || !res.success) return;
            state.tree = res.data.tree || [];
            rebuildTreeIndex();
            renderTree(state.tree);
            loadFolder(res.data.defaultFolderId);
            $root.trigger('anchorfm:bootstrapped', {
                tree: state.tree,
                defaultFolderId: res.data.defaultFolderId,
                productDocsFolderId: AnchorFM.productDocsFolderId || 0
            });
        }).always(() => {
            $root.removeClass('afm--busy');
        });
    }

    function ensureUploadProgress() {
        let $p = $root.find('[data-afm-upload-progress]');
        if (!$p.length) {
            $p = $(`<div class="afm__uploadProgress" data-afm-upload-progress>
                <div class="afm__uploadBar"><div class="afm__uploadBarFill"></div></div>
                <span class="afm__uploadPct">0%</span></div>`).appendTo($root);
        }
        $p.find('.afm__uploadBarFill').css('width', '0%');
        $p.find('.afm__uploadPct').text('0%');
        return $p;
    }

    function uploadFiles(files) {
        if (!files || !files.length) return;
        if (!canUploadHere()) return;

        const data = new FormData();
        data.append('action', 'anchor_fm_upload');
        data.append('nonce', AnchorFM.nonce);
        data.append('folder_id', String(state.currentFolderId));
        Array.from(files).forEach(f => data.append('files[]', f, f.name));

        $root.addClass('afm--busy');
        ensureUploadProgress();
        $.ajax({
            url: AnchorFM.ajax,
            method: 'POST',
            data,
            processData: false,
            contentType: false,
            xhr: function () {
                const xhr = new window.XMLHttpRequest();
                xhr.upload.addEventListener('progress', function (evt) {
                    if (evt.lengthComputable) {
                        const pct = Math.round((evt.loaded / evt.total) * 100);
                        const $p = $root.find('[data-afm-upload-progress]');
                        $p.find('.afm__uploadBarFill').css('width', pct + '%');
                        $p.find('.afm__uploadPct').text(pct + '%');
                    }
                }, false);
                return xhr;
            },
        }).always(() => $root.removeClass('afm--busy'))
            .done((res) => {
                const rejected = res && res.data && res.data.rejected;
                if (rejected && rejected.length) {
                    toast('Some files were blocked (type not allowed): ' + rejected.join(', '));
                }
                loadFolder(state.currentFolderId);
                $root.find('[data-afm-upload-progress]').remove();
            })
            .fail(() => {
                $root.find('[data-afm-upload-progress]').remove();
            });
    }

    // Every preview surface renders by pointing the browser at the stream
    // endpoint, which answers a failure with a bare status code and no body.
    // An <img> or <iframe> fed that paints nothing at all, so without this the
    // UI reports a server-side outage as an empty white rectangle.
    function unavailableHtml() {
        return `<div class="afm__noPreview afm__noPreview--error">
            <span class="dashicons dashicons-warning" aria-hidden="true"></span>
            <div>This file could not be read from the server.</div>
            <div class="afm__noPreviewHint">The record exists but its contents are unavailable. Please contact the site administrator.</div>
        </div>`;
    }

    // Second line of defence, for the case where the server believed the bytes
    // were readable and the transfer failed anyway.
    function bindPreviewErrorFallback($container) {
        $container.find('[data-afm-onerror]').on('error', function () {
            $(this).replaceWith(unavailableHtml());
        });
    }

    function loadFilePreview(fileId) {
        state.selectedFileId = Number(fileId);
        openDrawer();

        $drawerTitle.text('Loading…');
            $preview.html('<div class="afm__skeleton afm__skeleton--preview"></div>');
            $meta.html('<div class="afm__skeleton afm__skeleton--meta"></div>');
            $drawerActions.html('');

        api('anchor_fm_preview', { file_id: state.selectedFileId }).done(res => {
            if (!res || !res.success) return;
            const file = res.data.file;
            const prev = res.data.preview;
            const cap = res.data.capability;

            $drawerTitle.text(file.name);

            let previewHtml = '';
            if (prev.type === 'unavailable') {
                previewHtml = unavailableHtml();
            } else if (prev.type === 'image') {
                previewHtml = `<img class="afm__imgPreview" src="${esc(prev.inlineUrl)}" alt="${esc(file.name)}" data-afm-onerror>`;
            } else if (prev.type === 'pdf') {
                previewHtml = `<iframe class="afm__pdfPreview" src="${esc(prev.inlineUrl)}" title="${esc(file.name)}"></iframe>`;
            } else if (prev.type === 'text') {
                previewHtml = `<pre class="afm__textPreview">${esc(prev.textExcerpt || '')}</pre>`;
            } else {
                previewHtml = `<div class="afm__noPreview">
                    <span class="dashicons dashicons-${iconForMime(file.mime)}" aria-hidden="true"></span>
                    <div>No preview available</div>
                </div>`;
            }
            $preview.html(previewHtml);
            bindPreviewErrorFallback($preview);

            $meta.html(`
                <div class="afm__metaRow"><div class="afm__metaKey">Type</div><div class="afm__metaVal">${esc(file.mime)}</div></div>
                <div class="afm__metaRow"><div class="afm__metaKey">Size</div><div class="afm__metaVal">${fmtSize(file.size)}</div></div>
                <div class="afm__metaRow"><div class="afm__metaKey">Uploaded</div><div class="afm__metaVal">${esc(file.createdAt)}</div></div>
            `);

            const canManage = capRank(cap) >= 3;
            // See openFileViewer(): a Download for unreadable bytes is a 404.
            const canDownload = prev.available !== false;
            $drawerActions.html(`
                ${canDownload ? `<a class="afm__btn afm__btn--primary" href="${esc(prev.downloadUrl)}">
                    <span class="dashicons dashicons-download" aria-hidden="true"></span>
                    ${esc(AnchorFM.i18n.download)}
                </a>` : ''}
                ${canManage ? `<button type="button" class="afm__btn afm__btn--secondary" data-afm-action="permissions-file" data-afm-file="${file.id}">
                    <span class="dashicons dashicons-lock" aria-hidden="true"></span>
                    ${esc(AnchorFM.i18n.permissions)}
                </button>` : ''}
                ${canManage ? `<button type="button" class="afm__btn afm__btn--danger" data-afm-action="delete-file" data-afm-file="${file.id}">
                    <span class="dashicons dashicons-trash" aria-hidden="true"></span>
                    ${esc(AnchorFM.i18n.delete)}
                </button>` : ''}
            `);
        });
    }

    function openViewer(kind, id) {
        if (kind === 'file') return openFileViewer(id);
        if (kind === 'video') { if (typeof openVideoViewer === 'function') return openVideoViewer(id); }
    }

    function metaRow(k, v) {
        return `<div class="afm__metaRow"><div class="afm__metaKey">${esc(k)}</div><div class="afm__metaVal">${esc(v)}</div></div>`;
    }

    function openFileViewer(fileId) {
        api('anchor_fm_preview', { file_id: fileId }).then(res => {
            if (!res || !res.success) {
                if (typeof showAccessDenied === 'function') showAccessDenied('file', fileId, '');
                return;
            }
            const d = res.data, file = d.file, prev = d.preview;
            let body = '<div class="afm__viewer">';
            if (prev.type === 'unavailable') {
                body += `<div class="afm__viewerStage">${unavailableHtml()}</div>`;
            } else if (prev.type === 'image') {
                body += `<div class="afm__viewerStage"><img class="afm__viewerImg" src="${esc(prev.inlineUrl)}" alt="${esc(file.name)}" data-afm-onerror></div>`;
            } else if (prev.type === 'pdf') {
                body += `<div class="afm__viewerStage"><iframe class="afm__viewerPdf" src="${esc(prev.inlineUrl)}"></iframe></div>`;
            } else if (prev.type === 'text') {
                body += `<pre class="afm__viewerText">${esc(prev.textExcerpt || '')}</pre>`;
            } else if (prev.type === 'video') {
                body += `<div class="afm__viewerStage afm__viewerStage--video">
                    <video id="afmFPlayer_${esc(file.id)}" class="afm__viewerVideo"
                           controls preload="metadata" playsinline
                           src="${esc(prev.inlineUrl)}"></video>
                </div>`;
                if (AnchorFM.isAdmin) {
                    body += `<div class="afm__vhistory" data-afm-video-history><div class="afm__sectionTitle">Watch history</div><div class="afm__vhistoryBody">Loading…</div></div>`;
                }
            } else {
                body += `<div class="afm__viewerNone"><span class="dashicons dashicons-${iconForMime(file.mime)}"></span><div>No preview available</div></div>`;
            }
            body += '<div class="afm__viewerMeta">' +
                metaRow('Type', file.mime) +
                metaRow('Size', fmtSize(file.size)) +
                metaRow('Added', String(file.createdAt || '').slice(0, 10)) +
                '</div></div>';
            // Offering Download for bytes the server just said it cannot read
            // sends the user to a bare 404 page. Withhold it instead.
            const footer = (prev.downloadUrl && prev.available !== false)
                ? `<a class="afm__btn afm__btn--primary" href="${esc(prev.downloadUrl)}"><span class="dashicons dashicons-download"></span> Download</a>`
                : '';
            openViewerModal(esc(file.name), body, footer);
            bindPreviewErrorFallback($(document));
            if (prev.type === 'video') {
                mountFilePlayer(file.id, prev.downloadUrl);
                if (AnchorFM.isAdmin) loadVideoHistory('file', file.id);
            }
        });
    }

    function openViewerModal(titleHtml, bodyHtml, footerHtml) {
        $modal.find('.afm__modalTitle').html(titleHtml);
        $modalBody.html(bodyHtml);
        $modal.find('.afm__modalPanel').addClass('afm__modalPanel--viewer');
        const $footer = $modal.find('.afm__modalFooter');
        $footer.find('[data-afm-action="modal-primary"]').hide();
        $footer.find('[data-afm-action="close-modal"]').text('Close');
        let $vf = $footer.find('.afm__viewerFooter');
        if (!$vf.length) { $vf = $('<div class="afm__viewerFooter"></div>').prependTo($footer); }
        $vf.html(footerHtml || '');
        $modal.prop('hidden', false);
        $root.addClass('afm--modalOpen');
        state.modalMode = 'viewer';
    }

    let activePlayer = null;

    function openVideoViewer(videoId) {
        const v = findRow('video', videoId);
        if (v && v.vimeoId) { renderVideoViewer(videoId, v.name, v.vimeoId, v.vimeoHash); return; }
        // Not in the current/expanded/search cache (e.g. opened from a deep link) —
        // resolve it by id. The endpoint enforces view permission and answers a
        // forbidden/missing video with a 4xx, which rejects the deferred — so
        // access-denied has to be handled in .fail(), not .done().
        const denied = () => { if (typeof showAccessDenied === 'function') showAccessDenied('video', videoId, ''); };
        api('anchor_fm_vimeo_get', { video_id: videoId })
            .done(res => {
                if (res && res.success && res.data.video && res.data.video.vimeoId) {
                    const vid = res.data.video;
                    renderVideoViewer(videoId, vid.title, vid.vimeoId, vid.vimeoHash);
                } else {
                    denied();
                }
            })
            .fail(denied);
    }

    function renderVideoViewer(videoId, name, vimeoId, vimeoHash) {
        const playerId = 'afmVPlayer_' + videoId;
        let body = `<div class="afm__vplayer"><div id="${playerId}" class="afm__vplayerFrame" data-afm-video-frame></div></div>`;
        if (AnchorFM.isAdmin) {
            body += `<div class="afm__vhistory" data-afm-video-history><div class="afm__sectionTitle">Watch history</div><div class="afm__vhistoryBody">Loading…</div></div>`;
        }
        openViewerModal(esc(name), body, '');
        mountVimeoPlayer(playerId, vimeoId, videoId, vimeoHash);
        if (AnchorFM.isAdmin) loadVideoHistory('vimeo', videoId);
    }

    function mountVimeoPlayer(elId, vimeoId, videoId, vimeoHash) {
        if (!window.Vimeo || !window.Vimeo.Player) return;
        const opts = { id: Number(vimeoId), responsive: true };
        // Unlisted videos need their privacy hash to embed; without it the
        // player only works where the domain is whitelisted on Vimeo.
        if (vimeoHash) opts.h = String(vimeoHash);
        activePlayer = new window.Vimeo.Player(elId, opts);

        const adapter = vimeoAdapter(activePlayer);
        startMediaTracking(adapter, 'vimeo', videoId);
        applyResume(adapter, 'vimeo', videoId);
    }

    function mountFilePlayer(fileId, downloadUrl) {
        const el = document.getElementById('afmFPlayer_' + fileId);
        if (!el) return;

        const adapter = nativeAdapter(el);
        startMediaTracking(adapter, 'file', fileId);

        // Captured after startMediaTracking stamps this mount's token, same as
        // applyResume does — lets the error handler below detect that its
        // mount has since been superseded (or torn down) and skip touching
        // whatever session is live now.
        const token = trackState ? trackState.token : null;

        // .mov is on the upload allow-list but Firefox generally will not play
        // it. Rather than leaving a dead black box, fall back to exactly the
        // pre-player behaviour: the no-preview block plus Download.
        //
        // This also fires for a mid-playback network failure (dropped wifi,
        // worker timeout, expired stream nonce) 40 minutes in, so it must not
        // assume "unsupported format" and must not throw away the accumulated
        // position on the way out.
        el.addEventListener('error', function () {
            // Only act on shared tracking state if it still belongs to this
            // mount — a stale error from a closed/replaced modal must not
            // flush into, or null out, a different currently-live video.
            if (trackState && trackState.token === token) {
                // Persist before tearing down, or the watched time and
                // playhead accumulated so far are simply lost: closeModal
                // finds null state and flushes nothing.
                flushProgress(true);
                trackState = null;
                activeAdapter = null;
            }

            const code = (el.error && el.error.code) || 0;
            // 4 = MEDIA_ERR_SRC_NOT_SUPPORTED, 3 = MEDIA_ERR_DECODE: the file
            // genuinely will not play here. 2 = MEDIA_ERR_NETWORK: it would
            // have played; the connection died.
            const isNetwork = code === 2;
            if (!isNetwork && code !== 4 && code !== 3) return;

            const dl = downloadUrl
                ? `<a class="afm__btn afm__btn--primary" href="${esc(downloadUrl)}"><span class="dashicons dashicons-download"></span> Download</a>`
                : '';
            const msg = isNetwork
                ? 'Playback stopped — the connection to the server was lost.'
                : "This video can't be played in your browser.";
            $(el).closest('.afm__viewerStage').replaceWith(
                `<div class="afm__viewerNone">
                    <span class="dashicons dashicons-format-video"></span>
                    <div>${esc(msg)}</div>
                    <div class="afm__viewerNoneAction">${dl}</div>
                </div>`
            );
        }, { once: true });

        // duration is NaN until metadata arrives; resume only once we can
        // evaluate the near-end rule and the seek will actually stick.
        el.addEventListener('loadedmetadata', function () {
            if (!trackState || trackState.token !== token) return;
            trackState.duration = isFinite(el.duration) ? Math.floor(el.duration) : 0;
            applyResume(adapter, 'file', fileId);
        }, { once: true });
    }

    let trackState = null;
    let activeAdapter = null;
    // Bumped on every mount; lets async callbacks (getDuration, the resume
    // fetch) detect that the mount they belong to has since been torn down
    // and a different one is now live, so they don't write into it.
    let mountSeq = 0;

    // Vimeo's SDK and HTMLMediaElement expose the same handful of things under
    // different names. One adapter each keeps the heartbeat logic single-copy.
    // The two implementations must stay symmetric — that symmetry is the point.
    function vimeoAdapter(player) {
        // Cached because the tick needs the rate synchronously and Vimeo's
        // getPlaybackRate() is a promise. Defaults to 1 so a player that
        // never fires the event still reports an honest normal speed.
        let rate = 1;
        player.on('playbackratechange', d => {
            const r = Number(d && d.playbackRate);
            if (isFinite(r) && r > 0) rate = r;
        });
        return {
            onTimeUpdate: cb => player.on('timeupdate', d => cb(Math.floor((d && d.seconds) || 0))),
            onPlay: cb => player.on('play', cb),
            onPause: cb => player.on('pause', cb),
            onEnded: cb => player.on('ended', cb),
            getDuration: () => player.getDuration().then(d => Math.floor(d || 0)).catch(() => 0),
            getRate: () => rate,
            // These return promises: try/catch cannot see a rejection from a
            // player that is not ready yet, so swallow it explicitly.
            seek: t => { try { const p = player.setCurrentTime(t); if (p && p.catch) p.catch(() => {}); } catch (e) {} },
            unload: () => { try { if (player.unload) { const p = player.unload(); if (p && p.catch) p.catch(() => {}); } } catch (e) {} },
        };
    }

    function nativeAdapter(el) {
        return {
            onTimeUpdate: cb => el.addEventListener('timeupdate', () => cb(Math.floor(el.currentTime || 0))),
            onPlay: cb => el.addEventListener('play', cb),
            onPause: cb => el.addEventListener('pause', cb),
            onEnded: cb => el.addEventListener('ended', cb),
            // duration is NaN until metadata loads and Infinity for streams.
            getDuration: () => Promise.resolve(isFinite(el.duration) ? Math.floor(el.duration) : 0),
            getRate: () => el.playbackRate || 1,
            seek: t => { try { el.currentTime = t; } catch (e) {} },
            unload: () => { try { el.pause(); el.removeAttribute('src'); el.load(); } catch (e) {} },
        };
    }

    function startMediaTracking(adapter, source, itemId) {
        const token = ++mountSeq;
        trackState = {
            source: source,
            itemId: itemId,
            lastTime: 0,
            accum: 0,
            duration: 0,
            newSession: true,
            ended: false,
            token: token,
            // Whether the player is actually playing, and the wall-clock time
            // of the previous tick. Together they separate real playback from
            // a scrub: timeupdate also fires on every seek completion, paused
            // included, and only playback is bounded by the wall clock.
            playing: false,
            lastTickMs: 0,
            segments: [],
            segStart: null,
            segEnd: null,
        };
        activeAdapter = adapter;

        adapter.getDuration().then(d => {
            // The mount that started this fetch may already be torn down
            // (or replaced by a newer mount) by the time it resolves.
            if (!trackState || trackState.token !== token) return;
            trackState.duration = d || 0;
        });

        adapter.onTimeUpdate(function (t) {
            if (!trackState || trackState.token !== token) return;

            const nowMs = Date.now();

            if (trackState.segStart === null) {
                // First tick of a new stretch of playback.
                trackState.segStart = t;
                trackState.segEnd = t;
            } else {
                const gap = t - trackState.segEnd;
                // Media time cannot outrun wall-clock time during playback at
                // any rate; dragging the scrubber always does. A fixed gap
                // ceiling cannot tell the two apart — it credits a slow drag
                // (paused or not) as fully watched, and misreads honest 8x
                // playback as a seek. So the ceiling is the wall clock scaled
                // by the playback rate, and only while actually playing. The
                // +1 absorbs integer-second rounding on the media clock.
                const wallSeconds = Math.max(0, (nowMs - trackState.lastTickMs) / 1000);
                const rate = (typeof adapter.getRate === 'function' ? Number(adapter.getRate()) : 1) || 1;
                const allowed = (wallSeconds * rate) + 1;

                if (trackState.playing && gap >= 0 && gap <= allowed) {
                    // Playback advanced plausibly — extend the stretch.
                    if (gap > 0) trackState.accum += gap;
                    trackState.segEnd = t;
                } else {
                    // The playhead jumped, or moved while not playing — a
                    // seek. Close the stretch we were in and start a new one
                    // where we landed. This is the line that stops scrubbing
                    // from counting as watching.
                    closeSegment();
                    trackState.segStart = t;
                    trackState.segEnd = t;
                }
            }

            trackState.lastTickMs = nowMs;
            trackState.lastTime = t;
            if (trackState.accum >= 10) flushProgress(false);
        });

        // Re-watching a finished video must not keep reporting ended=1 — the
        // server returns resume 0 for an ended beat, so without this the
        // whole second viewing saves no position at all.
        adapter.onPlay(function () {
            if (!trackState || trackState.token !== token) return;
            trackState.ended = false;
            trackState.playing = true;
        });

        // The token guards below matter as much as the one above: a straggler
        // tick from a torn-down player would otherwise write into whichever
        // mount is live now — two of them inject a real segment into the next
        // video, and a stale `ended` zeroes the live mount's resume point.
        adapter.onPause(function () {
            if (!trackState || trackState.token !== token) return;
            trackState.playing = false;
            flushProgress(false);
        });

        adapter.onEnded(function () {
            if (!trackState || trackState.token !== token) return;
            trackState.playing = false;
            trackState.ended = true;
            // Carry coverage to the true end before flushing. `ended` fires
            // only after genuine end-of-media, so this grants at most the
            // final second — and without it 100% is unreachable on the Vimeo
            // path, whose last timeupdate lands a fraction short and floors
            // to D-1, capping a 20-minute video at 99%.
            if (trackState.duration > 0) {
                if (trackState.segStart === null) trackState.segStart = Math.max(0, trackState.duration - 1);
                trackState.segEnd  = Math.max(trackState.segEnd, trackState.duration);
                trackState.lastTime = trackState.duration;
            }
            flushProgress(true);
        });
    }

    // Push the stretch currently being played into the pending list. Segments
    // are half-open [start, end): playing from t=0 to t=5 means seconds 0-4
    // were seen, which is five seconds, not six.
    function closeSegment() {
        if (!trackState) return;
        if (trackState.segStart === null) return;
        if (trackState.segEnd > trackState.segStart) {
            trackState.segments.push([trackState.segStart, trackState.segEnd]);
        }
        trackState.segStart = null;
        trackState.segEnd = null;
    }

    // `reset` marks a deliberate "Start over": the server treats a zero
    // position as intentional rather than as the empty heartbeat a
    // closed-before-metadata modal produces, which it otherwise discards.
    function flushProgress(force, reset) {
        if (!trackState) return;

        // Capture the stretch in progress, so closing mid-play doesn't lose it.
        closeSegment();

        if (!force && trackState.segments.length === 0) return;

        const payload = {
            source: trackState.source,
            item_id: trackState.itemId,
            segments: JSON.stringify(trackState.segments),
            point: trackState.lastTime,
            duration: trackState.duration,
            ended: trackState.ended ? 1 : 0,
            new_session: trackState.newSession ? 1 : 0,
            reset: reset ? 1 : 0,
        };
        trackState.segments = [];
        trackState.accum = 0;
        trackState.newSession = false;

        // Playback may still be running; resume the open stretch where we are
        // so the next tick continues rather than starting a spurious segment.
        trackState.segStart = trackState.lastTime;
        trackState.segEnd = trackState.lastTime;

        api('anchor_fm_media_progress', payload);
    }

    // Seek to the user's saved position and tell them we did. A silent jump
    // into the middle of a video reads as a bug.
    function applyResume(adapter, source, itemId) {
        // Captured at call time (this runs synchronously right after
        // startMediaTracking, so trackState is this mount's state) so the
        // async response below can tell whether its mount is still live.
        const token = trackState ? trackState.token : null;
        api('anchor_fm_media_resume', { source: source, item_id: itemId })
            .done(res => {
                // The modal may have closed (or a different video opened)
                // while this request was in flight — don't let a stale
                // response touch whatever session is live now.
                if (!trackState || trackState.token !== token) return;
                if (!res || !res.success) return;
                const sec = Number(res.data.resumeSeconds) || 0;
                if (sec <= 0) return;

                adapter.seek(sec);
                trackState.lastTime = sec;

                renderResumeBar(sec, function () {
                    if (!trackState || trackState.token !== token) return;
                    adapter.seek(0);
                    trackState.lastTime = 0;
                    trackState.accum = 0;
                    trackState.ended = false;
                    trackState.segments = [];
                    trackState.segStart = null;
                    trackState.segEnd = null;
                    // Reuses the shared payload builder so the reset also
                    // clears newSession/ended consistently, and persists
                    // immediately so closing without watching does not
                    // restore the old position. reset=1 is what tells the
                    // server this zero is intentional.
                    flushProgress(true, true);
                });
            });
    }

    function renderResumeBar(seconds, onStartOver) {
        const $bar = $(`<div class="afm__resumeBar">
                <span class="afm__resumeText">Resuming from ${esc(fmtMMSS(seconds))}</span>
                <button type="button" class="afm__resumeReset">Start over</button>
                <button type="button" class="afm__resumeClose" aria-label="Dismiss">&times;</button>
            </div>`);

        $bar.find('.afm__resumeReset').on('click', function () {
            if (typeof onStartOver === 'function') onStartOver();
            $bar.remove();
        });
        $bar.find('.afm__resumeClose').on('click', function () { $bar.remove(); });

        // Insert above the player, not inside it — the file viewer's stage is a
        // black overflow:hidden box and the bar would be buried in it.
        $modalBody.find('.afm__vplayer, .afm__viewerStage').first().before($bar);
    }

    function stopVideoTracking() {
        flushProgress(true);
        if (activeAdapter) activeAdapter.unload();
        activeAdapter = null;
        activePlayer = null;
        trackState = null;
    }

    function fmtMMSS(total) {
        total = Math.max(0, Number(total) || 0);
        const m = Math.floor(total / 60), s = total % 60;
        return m + ':' + (s < 10 ? '0' : '') + s;
    }

    function loadVideoHistory(source, itemId) {
        api('anchor_fm_vimeo_history', { source: source, item_id: itemId }).then(res => {
            const $body = $modalBody.find('.afm__vhistoryBody');
            if (!res || !res.success) { $body.text('Unable to load history.'); return; }
            const rows = res.data.history || [];
            if (!rows.length) { $body.html('<div class="afm__empty">No views yet.</div>'); return; }
            let html = '<div class="afm__vhistoryTable">';
            rows.forEach(r => {
                html += `<div class="afm__vhistoryRow">
                    <span class="afm__vhName">${esc(r.name)}</span>
                    <span class="afm__vhPct">${esc(r.percent)}%</span>
                    <span class="afm__vhTime">${esc(fmtMMSS(r.totalSeconds))}</span>
                    <span class="afm__vhDate">${esc(String(r.lastViewedAt || '').slice(0,10))}</span>
                </div>`;
            });
            html += '</div>';
            $body.html(html);
        });
    }

    function openPermissions(entityType, entityId) {
        state.selectedEntity = { entityType, entityId: Number(entityId) };
        $modalBody.html('<div class="afm__skeleton afm__skeleton--share"></div>');
        $modalTitle.text(AnchorFM.i18n.permissions || 'Permissions');
        setModalPrimary('Save', 'save-permissions', null);
        openModal();

        api('anchor_fm_get_permissions', { entity_type: entityType, entity_id: Number(entityId) }).done(res => {
            if (!res || !res.success) {
                const msg = res && res.data && res.data.message ? String(res.data.message) : 'Unable to load permissions.';
                $modalBody.html(`<div class="afm__empty">${esc(msg)}</div>`);
                setModalPrimary('Close', 'noop-close', null);
                return;
            }
            state.rolesDraft = Array.isArray(res.data.roles) ? res.data.roles.slice() : [];
            state.usersDraft = Array.isArray(res.data.users) ? res.data.users.slice() : [];
            state.policyDraft = normalizePolicyDraft(res.data.policy || { operator: 'any', rules: [] });
            renderPermissionsEditor();
        });
    }

    function normalizePermOperator(value) {
        return String(value || '').toLowerCase() === 'all' ? 'all' : 'any';
    }

    function permissionRoles() {
        return Array.isArray(AnchorFM.roles) ? AnchorFM.roles : [];
    }

    function defaultPermissionCondition(type) {
        const roles = permissionRoles();
        type = type || 'role';
        if (type === 'date') return { type: 'date', start: '', end: '' };
        if (type === 'user') return { type: 'user', userId: '', name: '' };
        return { type: 'role', role: roles.length ? String(roles[0].key) : '' };
    }

    function normalizePolicyDraft(policy) {
        policy = policy && typeof policy === 'object' ? policy : {};
        const rules = Array.isArray(policy.rules) ? policy.rules : [];
        return {
            operator: normalizePermOperator(policy.operator),
            rules: rules.map(rule => {
                rule = rule && typeof rule === 'object' ? rule : {};
                const conditions = Array.isArray(rule.conditions) ? rule.conditions : [];
                return {
                    operator: normalizePermOperator(rule.operator || 'all'),
                    conditions: conditions.map(condition => {
                        condition = condition && typeof condition === 'object' ? condition : {};
                        const type = String(condition.type || 'role');
                        // Kept as-is so a re-save can't turn it into a real grant; the server rejects it.
                        if (type === 'never') return { type: 'never' };
                        if (type === 'date') {
                            return { type: 'date', start: String(condition.start || ''), end: String(condition.end || '') };
                        }
                        if (type === 'user') {
                            return { type: 'user', userId: String(condition.userId || condition.id || ''), name: String(condition.name || '') };
                        }
                        return { type: 'role', role: String(condition.role || '') };
                    }),
                };
            }),
        };
    }

    function readPolicyFromEditor() {
        const policy = {
            operator: normalizePermOperator($modalBody.find('[data-afm-perm-policy-op]').val()),
            rules: [],
        };
        $modalBody.find('[data-afm-perm-rule]').each(function () {
            const $rule = $(this);
            const rule = {
                operator: normalizePermOperator($rule.find('[data-afm-rule-op]').val()),
                conditions: [],
            };
            $rule.find('[data-afm-perm-condition]').each(function () {
                const $condition = $(this);
                const type = String($condition.find('[data-afm-cond-type]').val() || 'role');
                if (type === 'never') {
                    rule.conditions.push({ type: 'never' });
                } else if (type === 'date') {
                    rule.conditions.push({
                        type: 'date',
                        start: String($condition.find('[data-afm-cond-start]').val() || ''),
                        end: String($condition.find('[data-afm-cond-end]').val() || ''),
                    });
                } else if (type === 'user') {
                    rule.conditions.push({
                        type: 'user',
                        userId: String($condition.find('[data-afm-cond-user-id]').val() || ''),
                    });
                } else {
                    rule.conditions.push({
                        type: 'role',
                        role: String($condition.find('[data-afm-cond-role]').val() || ''),
                    });
                }
            });
            policy.rules.push(rule);
        });
        return normalizePolicyDraft(policy);
    }

    function roleOptions(selectedRole) {
        const roles = permissionRoles();
        const selected = String(selectedRole || '');
        // A role that no longer exists stays visibly selected: defaulting to the
        // first option would silently re-point the rule at a different group.
        const missing = selected && !roles.some(r => String(r.key) === selected)
            ? `<option value="${esc(selected)}" selected>Missing role: ${esc(selected)}</option>`
            : '';
        if (!roles.length) return missing || '<option value="">No roles</option>';
        return missing + roles.map(r => `<option value="${esc(r.key)}" ${String(selectedRole || '') === String(r.key) ? 'selected' : ''}>${esc(r.label)}</option>`).join('');
    }

    function renderPermissionCondition(condition, ruleIdx, conditionIdx) {
        const type = String(condition.type || 'role');
        const typedControl = type === 'date'
            ? `<div class="afm__dateRange">
                    <input type="date" class="afm__input" value="${esc(condition.start || '')}" data-afm-cond-start>
                    <input type="date" class="afm__input" value="${esc(condition.end || '')}" data-afm-cond-end>
               </div>`
            : type === 'user'
                ? `<input type="number" min="1" class="afm__input" placeholder="User ID" value="${esc(condition.userId || '')}" data-afm-cond-user-id>`
                : type === 'never'
                    ? `<span class="afm__muted">Not recognized — never matches. Remove it to save.</span>`
                    : `<select class="afm__select" data-afm-cond-role>${roleOptions(condition.role)}</select>`;

        return `
            <div class="afm__permCondition" data-afm-perm-condition data-afm-condition-idx="${conditionIdx}">
                <select class="afm__select" data-afm-cond-type>
                    <option value="role" ${type === 'role' ? 'selected' : ''}>Role</option>
                    <option value="date" ${type === 'date' ? 'selected' : ''}>Date range</option>
                    <option value="user" ${type === 'user' ? 'selected' : ''}>User</option>
                    ${type === 'never' ? '<option value="never" selected>Unrecognized</option>' : ''}
                </select>
                ${typedControl}
                <button type="button" class="afm__iconBtn" data-afm-action="remove-perm-condition" data-afm-rule-idx="${ruleIdx}" data-afm-condition-idx="${conditionIdx}" aria-label="Remove condition">
                    <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
                </button>
            </div>
        `;
    }

    function renderPermissionRule(rule, idx) {
        const conditions = Array.isArray(rule.conditions) ? rule.conditions : [];
        return `
            <div class="afm__permRule" data-afm-perm-rule data-afm-rule-idx="${idx}">
                <div class="afm__permRuleHead">
                    <div class="afm__permRuleTitle">Rule ${idx + 1}</div>
                    <select class="afm__select" data-afm-rule-op>
                        <option value="all" ${normalizePermOperator(rule.operator) === 'all' ? 'selected' : ''}>All conditions</option>
                        <option value="any" ${normalizePermOperator(rule.operator) === 'any' ? 'selected' : ''}>Any condition</option>
                    </select>
                    <button type="button" class="afm__iconBtn" data-afm-action="remove-perm-rule" data-afm-rule-idx="${idx}" aria-label="Remove rule">
                        <span class="dashicons dashicons-trash" aria-hidden="true"></span>
                    </button>
                </div>
                <div class="afm__permConditions">
                    ${conditions.length ? conditions.map((condition, conditionIdx) => renderPermissionCondition(condition, idx, conditionIdx)).join('') : `<div class="afm__empty afm__empty--tight">No conditions in this rule.</div>`}
                </div>
                <div class="afm__permActions">
                    <button type="button" class="afm__btn afm__btn--secondary" data-afm-action="add-perm-condition" data-afm-rule-idx="${idx}" data-afm-condition-type="role">Add role</button>
                    <button type="button" class="afm__btn afm__btn--secondary" data-afm-action="add-perm-condition" data-afm-rule-idx="${idx}" data-afm-condition-type="date">Add date</button>
                    <button type="button" class="afm__btn afm__btn--secondary" data-afm-action="add-perm-condition" data-afm-rule-idx="${idx}" data-afm-condition-type="user">Add user</button>
                </div>
            </div>
        `;
    }

    function renderPermissionsEditor() {
        const roles = Array.isArray(AnchorFM.roles) ? AnchorFM.roles : [];
        const selected = new Set((state.rolesDraft || []).map(r => String(r)));
        const users = Array.isArray(state.usersDraft) ? state.usersDraft : [];
        const policy = normalizePolicyDraft(state.policyDraft);

        const html = `
            <div class="afm__permWrap">
                <div class="afm__help">Admins always have access.</div>

                <div class="afm__permSection">
                    <div class="afm__sectionTitle">Always allowed</div>
                    <div class="afm__permList">
                        ${roles.length ? roles.map(r => `
                            <label class="afm__permRow">
                                <input type="checkbox" class="afm__checkbox" data-afm-role="${esc(r.key)}" ${selected.has(r.key) ? 'checked' : ''}>
                                <span class="afm__permLabel">${esc(r.label)}</span>
                                <span class="afm__mono">${esc(r.key)}</span>
                            </label>
                        `).join('') : `<div class="afm__empty afm__empty--tight">No roles available.</div>`}
                    </div>

                    <div class="afm__fieldRow">
                        <label class="afm__label">Add user access</label>
                        <div class="afm__fieldInline">
                            <input type="text" class="afm__input" placeholder="Search users" data-afm-user-search>
                            <button type="button" class="afm__btn afm__btn--secondary" data-afm-action="add-user-perm">Add</button>
                        </div>
                        <div class="afm__suggest" data-afm-suggest hidden></div>
                    </div>

                    <div class="afm__permList">
                        ${users.length ? users.map((u, idx) => `
                            <div class="afm__permRow">
                                <span class="afm__permLabel">${esc(u.name || u.id)}</span>
                                <span class="afm__mono">${esc(u.id)}</span>
                                <button type="button" class="afm__iconBtn" data-afm-action="remove-user-perm" data-afm-user-idx="${idx}" aria-label="Remove">
                                    <span class="dashicons dashicons-trash" aria-hidden="true"></span>
                                </button>
                            </div>
                        `).join('') : `<div class="afm__empty afm__empty--tight">No individual users added.</div>`}
                    </div>
                </div>

                <div class="afm__permSection">
                    <div class="afm__permSectionHead">
                        <div class="afm__sectionTitle">Rules</div>
                        <select class="afm__select" data-afm-perm-policy-op>
                            <option value="any" ${policy.operator === 'any' ? 'selected' : ''}>Any rule</option>
                            <option value="all" ${policy.operator === 'all' ? 'selected' : ''}>All rules</option>
                        </select>
                    </div>
                    <div class="afm__permRules">
                        ${policy.rules.length ? policy.rules.map((rule, idx) => renderPermissionRule(rule, idx)).join('') : `<div class="afm__empty afm__empty--tight">No rules added.</div>`}
                    </div>
                    <div class="afm__permActions">
                        <button type="button" class="afm__btn afm__btn--secondary" data-afm-action="add-perm-rule">Add rule</button>
                    </div>
                </div>
            </div>
        `;
        $modalBody.html(html);
    }

    function savePermissions() {
        if (!state.selectedEntity) return;
        const roles = [];
        $modalBody.find('[data-afm-role]').each(function () {
            const $cb = $(this);
            if ($cb.is(':checked')) roles.push(String($cb.data('afm-role')));
        });
        state.policyDraft = readPolicyFromEditor();
        api('anchor_fm_set_permissions', {
            entity_type: state.selectedEntity.entityType,
            entity_id: state.selectedEntity.entityId,
            roles,
            users: state.usersDraft,
            policy: JSON.stringify(state.policyDraft),
        }).done(res => {
            if (!res || !res.success) { toast(errMessage(null, res, 'Could not save permissions.')); return; }
            closeModal();
            bootstrap();
        }).fail(xhr => toast(errMessage(xhr, null, 'Could not save permissions.')));
    }

    function handleModalPrimary() {
        if (state.modalMode === 'external') {
            if (typeof externalModalHandler === 'function') externalModalHandler($modalBody);
            return;
        }
        if (state.modalMode === 'save-permissions') {
            savePermissions();
            return;
        }
        if (state.modalMode === 'create-folder') {
            const name = String($modalBody.find('[data-afm-modal-input]').val() || '').trim();
            if (!name) return;
            api('anchor_fm_create_folder', { parent_id: state.currentFolderId, name }).done(res => {
                if (!res || !res.success) return;
                closeModal();
                bootstrap();
                loadFolder(state.currentFolderId);
            });
            return;
        }
        if (state.modalMode === 'create-link') {
            const title = String($modalBody.find('[data-afm-link-title]').val() || '').trim();
            const url = String($modalBody.find('[data-afm-link-url]').val() || '').trim();
            if (!title || !url) return;
            api('anchor_fm_create_link', { folder_id: state.currentFolderId, title, url }).done(res => {
                if (!res || !res.success) return;
                closeModal();
                loadFolder(state.currentFolderId);
            });
            return;
        }
        if (state.modalMode === 'rename-folder') {
            const name = String($modalBody.find('[data-afm-modal-input]').val() || '').trim();
            const folderId = state.modalPayload && state.modalPayload.folderId ? Number(state.modalPayload.folderId) : 0;
            if (!folderId || !name) return;
            api('anchor_fm_rename_folder', { folder_id: folderId, name }).done(res => {
                if (!res || !res.success) return;
                closeModal();
                bootstrap();
            });
            return;
        }
        if (state.modalMode === 'edit-link') {
            const title = String($modalBody.find('[data-afm-link-title]').val() || '').trim();
            const url = String($modalBody.find('[data-afm-link-url]').val() || '').trim();
            const linkId = state.modalPayload && state.modalPayload.linkId ? Number(state.modalPayload.linkId) : 0;
            if (!linkId || !title || !url) return;
            api('anchor_fm_update_link', { link_id: linkId, title, url }).done(res => {
                if (!res || !res.success) return;
                closeModal();
                loadFolder(state.currentFolderId);
            });
            return;
        }
        if (state.modalMode === 'delete-folder') {
            const folderId = state.modalPayload && state.modalPayload.folderId ? Number(state.modalPayload.folderId) : 0;
            if (!folderId) return;
            api('anchor_fm_delete_folder', { folder_id: folderId }).done(res => {
                if (!res || !res.success) return;
                closeModal();
                bootstrap();
                loadFolder(state.currentFolderId);
            });
            return;
        }
        if (state.modalMode === 'delete-link') {
            const linkId = state.modalPayload && state.modalPayload.linkId ? Number(state.modalPayload.linkId) : 0;
            if (!linkId) return;
            api('anchor_fm_delete_link', { link_id: linkId }).done(res => {
                if (!res || !res.success) return;
                closeModal();
                loadFolder(state.currentFolderId);
            });
            return;
        }
        if (state.modalMode === 'delete-file') {
            const fileId = state.modalPayload && state.modalPayload.fileId ? Number(state.modalPayload.fileId) : 0;
            if (!fileId) return;
            api('anchor_fm_delete_file', { file_id: fileId }).done(res => {
                if (!res || !res.success) return;
                closeModal();
                closeDrawer();
                loadFolder(state.currentFolderId);
            });
            return;
        }
        if (state.modalMode === 'delete-video') {
            const videoId = state.modalPayload && state.modalPayload.videoId ? Number(state.modalPayload.videoId) : 0;
            if (!videoId) return;
            api('anchor_fm_vimeo_delete', { video_id: videoId }).done(res => {
                if (!res || !res.success) return;
                closeModal();
                loadFolder(state.currentFolderId);
            });
            return;
        }
        // Step 1 -> 2: ask the server to read the pasted refs and fetch titles.
        if (state.modalMode === 'resolve-video') {
            const src = String($modalBody.find('[data-afm-video-src]').val() || '').trim();
            if (!src) {
                videoNotice('Paste at least one Vimeo link or ID');
                return;
            }
            setModalBusy(true, 'Fetching…');
            api('anchor_fm_vimeo_resolve', { folder_id: state.currentFolderId, refs: src })
                .done(res => {
                    setModalBusy(false, 'Fetch');
                    if (!res || !res.success) { videoNotice(errMessage(null, res, 'Could not read those videos')); return; }
                    renderVideoReview((res.data && res.data.entries) || []);
                })
                .fail(jqXHR => {
                    setModalBusy(false, 'Fetch');
                    videoNotice(errMessage(jqXHR, null, 'Could not read those videos'));
                });
            return;
        }
        if (state.modalMode === 'new-video') {
            // Titles are read live from the review rows so edits are honored.
            const entries = (state.modalPayload && state.modalPayload.entries) || [];
            const videos = [];
            $modalBody.find('[data-afm-review-row]').each(function () {
                const i = Number($(this).data('afm-review-index'));
                const entry = entries[i];
                if (!entry) return;
                videos.push({
                    vimeo: entry.vimeoId,
                    hash: entry.hash || '',
                    title: String($(this).find('[data-afm-review-title]').val() || '').trim(),
                    thumbnailUrl: entry.thumbnailUrl || '',
                });
            });
            if (!videos.length) return;

            setModalBusy(true, 'Adding…');
            api('anchor_fm_vimeo_add', { folder_id: state.currentFolderId, videos: videos })
                .done(res => {
                    setModalBusy(false, 'Add');
                    if (!res || !res.success) { videoNotice(errMessage(null, res, 'Could not add video')); return; }
                    const added = (res.data && res.data.added) || [];
                    const failed = (res.data && res.data.failed) || [];
                    closeModal();
                    reloadCurrentFolder();
                    if (failed.length) {
                        // Partial import: say what landed and what didn't
                        // rather than implying everything worked.
                        toast(`Added ${added.length}; ${failed.length} skipped — ${failed[0].message}`);
                    } else if (added.length > 1) {
                        toast(`Added ${added.length} videos`);
                    }
                })
                .fail(jqXHR => {
                    setModalBusy(false, 'Add');
                    videoNotice(errMessage(jqXHR, null, 'Could not add video'));
                });
            return;
        }
        if (state.modalMode === 'noop-close') {
            closeModal();
            return;
        }
    }

    // Small popup menu for folder/file actions
    const $menu = $('<div class="afm__menu" data-afm-menu hidden></div>');
    // Mounted on <body>, not inside the plugin's own container, and positioned
    // fixed. An absolutely-positioned menu inside .afm is layered *within*
    // whatever wrapper the host theme puts around the plugin; if that wrapper
    // creates a stacking context, no z-index on the menu can lift it above a
    // later sibling section of the page. Escaping to <body> sidesteps every
    // ancestor stacking context and overflow clip at once.
    $('body').append($menu);

    function closeMenu() {
        $menu.prop('hidden', true).html('');
        state.menuContext = null;
    }

    function openMenu(anchorEl, items, context) {
        state.menuContext = context || null;

        const html = (items || []).map(it => `
            <button type="button" class="afm__menuItem ${it.danger ? 'is-danger' : ''} ${it.disabled ? 'is-disabled' : ''}" data-afm-menu-action="${esc(it.action)}" ${it.disabled ? 'disabled' : ''}>
                <span class="dashicons dashicons-${esc(it.icon || 'admin-generic')}" aria-hidden="true"></span>
                <span>${esc(it.label)}</span>
            </button>
        `).join('');

        $menu.html(html).prop('hidden', false);

        // The menu is position:fixed on <body>, so getBoundingClientRect()'s
        // viewport coordinates are already the right coordinate space — no
        // offset arithmetic against a container, and nothing to go wrong if the
        // page scrolls between measuring and painting.
        const rect  = anchorEl.getBoundingClientRect();
        const menuW = $menu.outerWidth() || 220;
        const menuH = $menu.outerHeight() || 0;
        const vw = window.innerWidth || document.documentElement.clientWidth;
        const vh = window.innerHeight || document.documentElement.clientHeight;
        const GAP = 6, EDGE = 8;

        // Right-align to the trigger, then keep it inside the viewport.
        let left = rect.right - menuW;
        if (left + menuW > vw - EDGE) left = vw - EDGE - menuW;
        if (left < EDGE) left = EDGE;

        // Below the trigger by default; flip above when there is no room under
        // it, which is what made the menu run off the bottom of the panel.
        let top = rect.bottom + GAP;
        if (top + menuH > vh - EDGE) {
            const above = rect.top - GAP - menuH;
            top = above >= EDGE ? above : Math.max(EDGE, vh - EDGE - menuH);
        }

        $menu.css({ top: Math.round(top) + 'px', left: Math.round(left) + 'px' });
    }

    // A fixed menu does not travel with the page, so anything that moves the
    // trigger must dismiss it rather than leave it stranded mid-screen. Capture
    // phase, because scrolls inside the sidebar and the file pane do not bubble
    // to window.
    window.addEventListener('scroll', function () {
        if (!$menu.prop('hidden')) closeMenu();
    }, true);
    window.addEventListener('resize', function () {
        if (!$menu.prop('hidden')) closeMenu();
    });

    function findFolderName(folderId) {
        const stack = (state.tree || []).slice();
        while (stack.length) {
            const n = stack.shift();
            if (Number(n.id) === Number(folderId)) return n.name;
            if (n.children && n.children.length) stack.push.apply(stack, n.children);
        }
        return '';
    }

    function findLinkById(linkId) {
        const links = (state.currentList && state.currentList.links) ? state.currentList.links : [];
        return links.find(l => Number(l.id) === Number(linkId)) || null;
    }

    $menu.on('click', '[data-afm-menu-action]', function () {
        const action = String($(this).data('afm-menu-action'));
        const ctx = state.menuContext || {};
        if ($(this).hasClass('is-disabled')) {
            closeMenu();
            return;
        }
        closeMenu();

        // Row (Finder list) context menu actions. Only the row menu sets ctx.kind.
        if (ctx.kind) {
            const k = ctx.kind;
            const vid = Number(ctx.id);
            if (action === 'open-folder') { loadFolder(vid); return; }
            if (action === 'open-file') { if (typeof openViewer === 'function') openViewer('file', vid); return; }
            if (action === 'open-video') { if (typeof openViewer === 'function') openViewer('video', vid); return; }
            if (action === 'open-link') { const l = findRow('link', vid); if (l && l.url) window.open(l.url, '_blank', 'noopener'); return; }
            if (action === 'show-in-folder') {
                const target = (state.searchFolderById && state.searchFolderById[k + ':' + vid]);
                const dest = (typeof target === 'number') ? target : state.currentFolderId;
                $search.val(''); state.search = '';
                loadFolder(dest);
                flashRow(k, vid);
                return;
            }
            if (action === 'copy-share-link') { if (typeof copyShareLink === 'function') copyShareLink(k, vid); return; }
            if (action === 'copy') { setClipboard([{ kind: k, id: vid }]); return; }
            if (action === 'duplicate') { copyItems([{ kind: k, id: vid }], state.currentFolderId); return; }
            if (action === 'paste-into') { copyItems(clipboardItems(), vid); return; }
            if (action === 'rename') { if (typeof startInlineRename === 'function') startInlineRename(k, vid); return; }
            if (action === 'permissions') { openPermissions(k, vid); return; }
            if (action === 'edit-link') {
                const link = findRow('link', vid);
                openLinkModal({
                    title: 'Edit link',
                    primaryLabel: 'Save',
                    mode: 'edit-link',
                    payload: { linkId: vid },
                    linkTitle: link ? (link.name || '') : '',
                    linkUrl: link ? (link.url || '') : '',
                });
                return;
            }
            if (action === 'delete') {
                if (k === 'folder') {
                    openConfirmModal({ title: 'Delete folder', primaryLabel: 'Delete', mode: 'delete-folder', payload: { folderId: vid }, message: 'This will permanently delete the folder and all its contents.' });
                } else if (k === 'file') {
                    openConfirmModal({ title: 'Delete file', primaryLabel: 'Delete', mode: 'delete-file', payload: { fileId: vid }, message: 'This will permanently delete the file.' });
                } else if (k === 'link') {
                    openConfirmModal({ title: 'Delete link', primaryLabel: 'Delete', mode: 'delete-link', payload: { linkId: vid }, message: 'This will remove the link from this folder.' });
                } else if (k === 'video') {
                    openConfirmModal({ title: 'Delete video', primaryLabel: 'Delete', mode: 'delete-video', payload: { videoId: vid }, message: 'This will permanently delete the video.' });
                }
                return;
            }
            return;
        }

        if (action === 'rename-folder') {
            const folderId = Number(ctx.folderId || 0);
            if (!folderId) return;
            openTextModal({
                title: 'Rename folder',
                primaryLabel: 'Save',
                placeholder: 'Folder name',
                value: findFolderName(folderId),
                mode: 'rename-folder',
                payload: { folderId },
            });
            return;
        }

        if (action === 'permissions-folder') {
            const folderId = Number(ctx.folderId || 0);
            if (!folderId) return;
            openPermissions('folder', folderId);
            return;
        }

        if (action === 'download-folder') {
            const folderId = Number(ctx.folderId || 0);
            if (!folderId) return;
            window.location = `${AnchorFM.ajax}?action=anchor_fm_download_folder&folder_id=${folderId}&nonce=${AnchorFM.nonce}`;
            return;
        }

        if (action === 'delete-folder') {
            const folderId = Number(ctx.folderId || 0);
            if (!folderId) return;
            openConfirmModal({
                title: 'Delete folder',
                primaryLabel: 'Delete',
                mode: 'delete-folder',
                payload: { folderId },
                message: 'This will permanently delete the folder and all its contents.',
            });
            return;
        }

        if (action === 'ungroup-folder') {
            const folderId = Number(ctx.folderId || 0);
            if (!folderId) return;
            api('anchor_fm_move_folder', { folder_id: folderId, target_folder_id: 0 }).done(res => {
                if (res && res.success) {
                    bootstrap();
                    loadFolder(folderId);
                }
            });
            return;
        }

        if (action === 'edit-link') {
            const linkId = Number(ctx.linkId || 0);
            if (!linkId) return;
            const link = findLinkById(linkId);
            if (!link) return;
            openLinkModal({
                title: 'Edit link',
                primaryLabel: 'Save',
                mode: 'edit-link',
                payload: { linkId },
                linkTitle: link.title || '',
                linkUrl: link.url || '',
            });
            return;
        }

        if (action === 'delete-link') {
            const linkId = Number(ctx.linkId || 0);
            if (!linkId) return;
            openConfirmModal({
                title: 'Delete link',
                primaryLabel: 'Delete',
                mode: 'delete-link',
                payload: { linkId },
                message: 'This will remove the link from this folder.',
            });
            return;
        }

        if (action === 'open-file') {
            const fileId = Number(ctx.fileId || 0);
            if (!fileId) return;
            openViewer('file', fileId);
            return;
        }

        if (action === 'permissions-file') {
            const fileId = Number(ctx.fileId || 0);
            if (!fileId) return;
            openPermissions('file', fileId);
            return;
        }

        if (action === 'delete-file') {
            const fileId = Number(ctx.fileId || 0);
            if (!fileId) return;
            openConfirmModal({
                title: 'Delete file',
                primaryLabel: 'Delete',
                mode: 'delete-file',
                payload: { fileId },
                message: 'This will permanently delete the file.',
            });
            return;
        }
    });

    function buildRowMenu(kind, id) {
        const items = [];
        if (kind === 'folder') items.push({ action: 'open-folder', icon: 'category', label: 'Open' });
        if (kind === 'file') items.push({ action: 'open-file', icon: 'visibility', label: 'Open' });
        if (kind === 'video') items.push({ action: 'open-video', icon: 'video-alt3', label: 'Play' });
        if (kind === 'link') items.push({ action: 'open-link', icon: 'admin-links', label: 'Open' });
        items.push({ action: 'show-in-folder', icon: 'category', label: 'Show in enclosing folder' });
        if (kind === 'file' || kind === 'video') items.push({ action: 'copy-share-link', icon: 'admin-links', label: 'Copy share link' });
        if (AnchorFM.isAdmin) {
            if (kind === 'folder' && clipboardItems().length) {
                items.push({ action: 'paste-into', icon: 'clipboard', label: 'Paste here' });
            }
            items.push({ action: 'duplicate', icon: 'admin-page', label: 'Duplicate' });
            items.push({ action: 'copy', icon: 'admin-page', label: 'Copy' });
            if (kind !== 'link') items.push({ action: 'rename', icon: 'edit', label: 'Rename' });
            if (kind === 'link') items.push({ action: 'edit-link', icon: 'edit', label: 'Edit' });
            if (kind === 'folder' || kind === 'file') items.push({ action: 'permissions', icon: 'shield', label: 'Permissions' });
            items.push({ action: 'delete', icon: 'trash', label: 'Delete', danger: true });
        }
        return items;
    }

    function openRowMenu(anchorEl, kind, id) {
        openMenu(anchorEl, buildRowMenu(kind, id), { kind: kind, id: Number(id) });
    }

    $root.on('click', '[data-afm-row-menu]', function (e) {
        e.stopPropagation();
        const parts = String($(this).data('afm-row-menu')).split(':');
        openRowMenu(this, parts[0], parts[1]);
    });
    $root.on('contextmenu', '[data-afm-row]', function (e) {
        e.preventDefault();
        openRowMenu(this, $(this).data('afm-row-kind'), $(this).data('afm-row-id'));
    });

    function flashRow(kind, id) {
        setTimeout(() => {
            const $r = $grid.find(`[data-afm-row="${kind}:${id}"]`);
            if (!$r.length) return;
            $r[0].scrollIntoView({ block: 'center' });
            $r.addClass('is-flash');
            setTimeout(() => $r.removeClass('is-flash'), 1400);
        }, 400);
    }

    // Events
    const filesTabActive = () => (!portalMode || $root.hasClass('apfm--tab-files'));
    const ignoreIfNotFilesTab = (evt) => {
        if (!filesTabActive()) {
            if (evt) evt.preventDefault();
            return true;
        }
        return false;
    };

    const getPageX = (evt) => {
        const oe = evt && evt.originalEvent;
        if (oe && oe.touches && oe.touches.length) return oe.touches[0].pageX;
        return evt.pageX;
    };

    const onResize = (evt) => {
        if (!isResizing) return;
        const pageX = getPageX(evt);
        const frameOffset = $frame.offset();
        if (!frameOffset || pageX === undefined) return;
        const nextWidth = clamp(pageX - frameOffset.left, SIDEBAR_MIN, SIDEBAR_MAX);
        setSidebarWidth(nextWidth);
        if (evt && evt.preventDefault) evt.preventDefault();
    };

    const stopResize = () => {
        if (!isResizing) return;
        isResizing = false;
        $root.removeClass('afm--resizing');
        $(document).off('.afmResize');
    };

    if ($resizer.length && $frame.length) {
        $resizer.on('mousedown', function (e) {
            if (e.which && e.which !== 1) return;
            e.preventDefault();
            isResizing = true;
            $root.addClass('afm--resizing');
            $(document).on('mousemove.afmResize', onResize);
            $(document).on('mouseup.afmResize', stopResize);
            onResize(e);
        });

        $resizer.on('touchstart', function (e) {
            e.preventDefault();
            isResizing = true;
            $root.addClass('afm--resizing');
            $(document).on('touchmove.afmResize', onResize);
            $(document).on('touchend.afmResize touchcancel.afmResize', stopResize);
            onResize(e);
        });
    }

    $root.on('click', '[data-afm-tree-toggle]', function (e) {
        e.preventDefault();
        e.stopPropagation();
        if (ignoreIfNotFilesTab(e)) return;
        toggleNode($(this).data('afm-tree-toggle'));
    });

    $root.on('click', '[data-afm-open-folder]', function (e) {
        if (ignoreIfNotFilesTab(e)) return;
        loadFolder($(this).data('afm-open-folder'));
    });

    $root.on('click', '[data-afm-folder-card]', function (e) {
        const id = $(this).data('afm-folder-card');
        if ($(e.target).closest('[data-afm-folder-menu]').length) return;
        if (ignoreIfNotFilesTab(e)) return;
        loadFolder(id);
    });

    $root.on('click', '[data-afm-file-card]', function (e) {
        const id = $(this).data('afm-file-card');
        if ($(e.target).closest('[data-afm-file-menu]').length) return;
        openViewer('file', id);
    });

    $root.on('click', '[data-afm-link-card]', function (e) {
        const id = $(this).data('afm-link-card');
        if ($(e.target).closest('[data-afm-link-menu]').length) return;
        const link = findLinkById(id);
        if (link && link.url) {
            window.open(link.url, '_blank', 'noopener');
        }
    });

    $root.on('click', '[data-afm-action="close-drawer"]', closeDrawer);
    $root.on('click', '[data-afm-action="close-modal"]', closeModal);
    $root.on('click', '[data-afm-action="modal-primary"]', handleModalPrimary);

    $(document).on('click', function (e) {
        if ($menu.prop('hidden')) return;
        if ($(e.target).closest('[data-afm-menu]').length) return;
        if ($(e.target).closest('[data-afm-folder-menu],[data-afm-file-menu],[data-afm-link-menu]').length) return;
        closeMenu();
    });

    $root.on('click', '[data-afm-action="upload"]', function (e) {
        if (ignoreIfNotFilesTab(e)) return;
        $fileInput.trigger('click');
    });
    $fileInput.on('change', function (e) {
        if (ignoreIfNotFilesTab(e)) {
            $(this).val('');
            return;
        }
        uploadFiles(this.files);
        $(this).val('');
    });

    let dragDepth = 0;
    $content.on('dragenter', function (e) {
        e.preventDefault();
        e.stopPropagation();
        if (ignoreIfNotFilesTab(e)) return;
        if (!canUploadHere()) return;
        dragDepth++;
        $root.addClass('afm--drag');
    });
    $content.on('dragover', function (e) {
        e.preventDefault();
        e.stopPropagation();
        if (ignoreIfNotFilesTab(e)) return;
        if (!canUploadHere()) return;
        $root.addClass('afm--drag');
    });
    $content.on('dragleave', function (e) {
        e.preventDefault();
        e.stopPropagation();
        if (ignoreIfNotFilesTab(e)) return;
        if (!canUploadHere()) return;
        dragDepth = Math.max(0, dragDepth - 1);
        if (!dragDepth) $root.removeClass('afm--drag');
    });
    $content.on('drop', function (e) {
        e.preventDefault();
        e.stopPropagation();
        if (ignoreIfNotFilesTab(e)) return;
        dragDepth = 0;
        $root.removeClass('afm--drag');
        if (!canUploadHere()) return;
        if (e.originalEvent && e.originalEvent.dataTransfer) {
            uploadFiles(e.originalEvent.dataTransfer.files);
        }
    });

    let searchTimer = null;
    $search.on('input', function () {
        const term = String($(this).val() || '').trim();
        state.search = term;
        clearTimeout(searchTimer);
        if (term.length < 2) {
            // Invalidate any in-flight search so a late response can't repaint over the browse view.
            state.searchSeq = (state.searchSeq || 0) + 1;
            renderList(state.currentList, state.currentCapability);
            renderBreadcrumbs(state.lastBreadcrumbs || []);
            return;
        }
        searchTimer = setTimeout(() => runGlobalSearch(term), 250);
    });

    function runGlobalSearch(term) {
        const seq = (state.searchSeq = (state.searchSeq || 0) + 1);
        api('anchor_fm_search', { term: term }).then(res => {
            if (seq !== state.searchSeq) return; // a newer query (or a clear) superseded this response
            if (!res || !res.success) return;
            renderSearchResults(res.data.results || [], res.data.truncated, term);
        });
    }

    function renderSearchResults(results, truncated, term) {
        // Switching to a search view changes the visible dataset; drop any
        // selection so the bulk bar can't act on rows that are no longer shown.
        state.selectedRows.clear();
        renderBulkBar();
        $breadcrumbs.html(`<span class="afm__crumb is-static">Search: “${esc(term)}”</span>`);
        state.searchFolderById = {};
        state.searchRowsByKey = {};
        if (!results.length) {
            $grid.html(`<div class="afm__empty">No matches for “${esc(term)}”.</div>`);
            return;
        }
        let html = headerHtml() + '<div class="afm__list afm__list--search" tabindex="0">';
        results.forEach(r => {
            const item = { kind: r.kind, id: r.id, name: r.name, mime: r.mime, size: r.size, url: r.url, vimeoId: r.vimeoId, vimeoHash: r.vimeoHash, thumbnailUrl: r.thumbnailUrl, createdAt: '' };
            // append the enclosing-folder path under the row
            const base = rowHtml(item, 0);
            html += base.replace('</div>\n            </div>', `</div><div class="afm__rowPath">${esc(r.path || 'Home')}</div>\n            </div>`);
            state.searchFolderById[r.kind + ':' + r.id] = r.folderId;
            // Cache the row so findRow() can resolve items opened from search
            // (e.g. play a video, open a link) without a browse listing.
            state.searchRowsByKey[r.kind + ':' + r.id] = item;
        });
        if (truncated) html += `<div class="afm__empty">Showing the first results — refine your search to narrow further.</div>`;
        html += '</div>';
        $grid.html(html);
    }

    $root.on('click', '[data-afm-action="new-folder"]', function (e) {
        if (ignoreIfNotFilesTab(e)) return;
        closeMenu();
        openTextModal({
            title: 'New folder',
            primaryLabel: 'Create',
            placeholder: 'Folder name',
            value: '',
            mode: 'create-folder',
            help: 'Folders inherit view permissions from parent folders.',
        });
    });

    $root.on('click', '[data-afm-action="new-link"]', function (e) {
        if (ignoreIfNotFilesTab(e)) return;
        if (state.currentFolderId <= 0) return;
        closeMenu();
        openLinkModal({
            title: 'New link',
            primaryLabel: 'Add link',
            mode: 'create-link',
        });
    });

    $root.on('click', '[data-afm-action="new-video"]', function (e) {
        if (ignoreIfNotFilesTab(e)) return;
        if (state.currentFolderId <= 0) return;
        closeMenu();
        openVideoModal();
    });

    // Step 1: paste one or many Vimeo refs. Titles come from Vimeo, so no
    // title field here — that's what the review step is for.
    function openVideoModal() {
        const body = `
            <div class="afm__formRow">
                <label class="afm__label">Vimeo links or IDs</label>
                <textarea class="afm__input afm__textarea" rows="4" data-afm-video-src
                    placeholder="https://vimeo.com/123456789, https://vimeo.com/987654321"></textarea>
                <div class="afm__hint">Separate multiple videos with commas or new lines. Titles are pulled from Vimeo — you can edit them next.</div>
            </div>
            <div class="afm__notice" data-afm-video-notice hidden></div>`;
        $modalTitle.text('New video');
        $modalBody.html(body);
        $modalPrimary.show();
        setModalPrimary('Fetch', 'resolve-video', null);
        openModal();
        window.setTimeout(() => $modalBody.find('[data-afm-video-src]').trigger('focus'), 0);
    }

    // Step 2: one row per resolved video — thumbnail, editable title (defaulted
    // to Vimeo's), and an inline reason for anything that can't be imported.
    function renderVideoReview(entries) {
        const importable = entries.filter(e => e.vimeoId && !e.error);
        const broken = entries.filter(e => !e.vimeoId || e.error);

        const rows = importable.map((e, i) => `
            <div class="afm__videoReviewRow" data-afm-review-row data-afm-review-index="${i}">
                <div class="afm__videoReviewThumb">
                    ${e.thumbnailUrl
                        ? `<img src="${esc(e.thumbnailUrl)}" alt="" loading="lazy">`
                        : `<span class="dashicons dashicons-video-alt3" aria-hidden="true"></span>`}
                </div>
                <div class="afm__videoReviewMain">
                    <input type="text" class="afm__input" data-afm-review-title value="${esc(e.title)}" aria-label="Video title">
                    <div class="afm__videoReviewMeta">
                        Vimeo ID ${esc(e.vimeoId)}${e.hash ? ' · unlisted' : ''}
                        ${e.warning ? `<span class="afm__videoReviewWarn">${esc(e.warning)} — title needs a hand</span>` : ''}
                    </div>
                </div>
            </div>`).join('');

        const brokenRows = broken.length ? `
            <div class="afm__videoReviewBroken">
                <div class="afm__label">Skipped (${broken.length})</div>
                ${broken.map(e => `<div class="afm__videoReviewMeta">${esc(e.input)} — ${esc(e.error || 'unreadable')}</div>`).join('')}
            </div>` : '';

        const body = `
            ${importable.length ? `<div class="afm__videoReview">${rows}</div>` : ''}
            ${brokenRows}
            <div class="afm__notice" data-afm-video-notice hidden></div>`;

        $modalTitle.text(importable.length === 1 ? 'Add video' : `Add ${importable.length} videos`);
        $modalBody.html(body);

        if (!importable.length) {
            // Nothing to add — a working Close beats a dead Add button.
            $modalPrimary.show();
            setModalPrimary('Close', 'noop-close', null);
            return;
        }
        $modalPrimary.show();
        setModalPrimary(importable.length === 1 ? 'Add' : `Add ${importable.length}`, 'new-video', { entries: importable });
        window.setTimeout(() => $modalBody.find('[data-afm-review-title]').first().trigger('focus').trigger('select'), 0);
    }

    $root.on('click', '[data-afm-folder-menu]', function () {
        if (!AnchorFM.isAdmin) return;
        const folderId = Number($(this).data('afm-folder-menu'));
        if (productDocsFolderId && folderId === productDocsFolderId) return;
        closeMenu();
        const parentId = state.parentById ? (state.parentById[folderId] || 0) : 0;
        const items = [
            { action: 'rename-folder', label: 'Rename', icon: 'edit' },
            { action: 'permissions-folder', label: 'Permissions', icon: 'lock' },
            { action: 'download-folder', label: 'Download', icon: 'download' },
        ];
        items.push({ action: 'ungroup-folder', label: 'Move to top', icon: 'admin-site', disabled: parentId === 0 });
        items.push({ action: 'delete-folder', label: 'Delete', icon: 'trash', danger: true });
        openMenu(this, items, { folderId, parentId });
    });

    $root.on('click', '[data-afm-action="delete-file"]', function () {
        const fileId = Number($(this).data('afm-file'));
        openConfirmModal({
            title: 'Delete file',
            primaryLabel: 'Delete',
            mode: 'delete-file',
            payload: { fileId },
            message: 'This will permanently delete the file.',
        });
    });

    $root.on('click', '[data-afm-file-menu]', function () {
        if (!AnchorFM.isAdmin) return;
        const fileId = Number($(this).data('afm-file-menu'));
        closeMenu();
        openMenu(this, [
            { action: 'open-file', label: 'Open', icon: 'visibility' },
            { action: 'permissions-file', label: 'Permissions', icon: 'lock' },
            { action: 'delete-file', label: 'Delete', icon: 'trash', danger: true },
        ], { fileId });
    });

    $root.on('click', '[data-afm-link-menu]', function (e) {
        if (!AnchorFM.isAdmin) return;
        e.stopPropagation();
        const linkId = Number($(this).data('afm-link-menu'));
        closeMenu();
        openMenu(this, [
            { action: 'edit-link', label: 'Edit', icon: 'edit' },
            { action: 'delete-link', label: 'Delete', icon: 'trash', danger: true },
        ], { linkId });
    });

    $root.on('click', '[data-afm-action="permissions-file"]', function () {
        const fileId = Number($(this).data('afm-file'));
        openPermissions('file', fileId);
    });

    $root.on('click', '[data-afm-sort]', function () {
        const key = $(this).data('afm-sort');
        if (state.sortKey === key) {
            state.sortDir = state.sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            state.sortKey = key; state.sortDir = 'asc';
        }
        renderList(state.currentList, state.currentCapability);
    });

    $root.on('click', '[data-afm-crumb]', function () {
        loadFolder(Number($(this).data('afm-crumb')));
    });

    // Expanding a folder costs a full admin-ajax round trip, and almost all of
    // that is WordPress booting -- roughly a second on a site with a large
    // plugin set, against single-digit milliseconds of actual listing work.
    // Nothing here can make that request fast, so instead: never issue it twice
    // for the same folder, and start it on hover so it overlaps the time
    // between the pointer reaching the arrow and the click landing.
    // The listing endpoints now return every folder this user may open, so the
    // usual case is that expanding one needs no request. Seeded on every
    // listing response, which is also what keeps it fresh -- the copy is never
    // older than the navigation that fetched it.
    function seedChildCache(contents) {
        if (!contents || typeof contents !== 'object') return;
        Object.keys(contents).forEach(fid => {
            state.childCache[fid] = currentRows(contents[fid]);
        });
    }

    function fetchChildren(fid) {
        if (state.childCache[fid]) return $.Deferred().resolve(state.childCache[fid]).promise();
        if (state.childPending[fid]) return state.childPending[fid];

        // A hover can start a fetch that is still in flight when the user
        // navigates away. Without this token its callback would delete a newer
        // pending entry for the same id -- orphaning a live request -- and
        // repopulate a cache the navigation had just cleared.
        const gen = state.childGeneration;
        const settle = () => { if (gen === state.childGeneration) delete state.childPending[fid]; };

        const req = api('anchor_fm_list', { folder_id: fid }).then(res => {
            settle();
            if (!res || !res.success) return null;
            const rows = currentRows(res.data);
            if (gen !== state.childGeneration) return null;
            state.childCache[fid] = rows;
            return rows;
        }, () => {
            settle();
            return null;
        });
        state.childPending[fid] = req;
        return req;
    }

    function prefetchChildren(fid) {
        if (state.search && state.search.length >= 2) return;
        if (state.childCache[fid] || state.childPending[fid]) return;
        fetchChildren(fid);
    }

    $root.on('mouseenter focus', '[data-afm-row-expand]', function () {
        prefetchChildren(Number($(this).data('afm-row-expand')));
    });

    $root.on('click', '[data-afm-row-expand]', function (e) {
        e.stopPropagation();
        // Expand-in-place operates on the browse listing only; in global-search
        // view there is no current-folder context to inject children into, and
        // re-rendering would discard the search results.
        if (state.search && state.search.length >= 2) return;
        const fid = Number($(this).data('afm-row-expand'));
        if (state.expandedRows[fid]) {
            delete state.expandedRows[fid];
            renderList(state.currentList, state.currentCapability);
            return;
        }
        // Warm cache: expand in the same tick, with no request and no flicker.
        if (state.childCache[fid]) {
            state.expandedRows[fid] = state.childCache[fid];
            renderList(state.currentList, state.currentCapability);
            return;
        }
        // Cold: the wait is a second of someone else's bootstrap, so say so
        // rather than leaving a dead arrow that looks like a missed click.
        const $btn = $(this).addClass('is-loading');
        const gen = state.childGeneration;
        fetchChildren(fid).then(rows => {
            $btn.removeClass('is-loading');
            if (!rows || gen !== state.childGeneration) return;
            state.expandedRows[fid] = rows;
            renderList(state.currentList, state.currentCapability);
        });
    });

    $root.on('click', '[data-afm-row]', function (e) {
        if ($(e.target).closest('[data-afm-row-expand],[data-afm-row-menu]').length) return;
        selectRow($(this), e);
    });

    $root.on('dblclick', '[data-afm-row]', function (e) {
        if ($(e.target).closest('[data-afm-row-expand],[data-afm-row-menu]').length) return;
        const kind = $(this).data('afm-row-kind');
        const id = Number($(this).data('afm-row-id'));
        if (kind === 'folder') { loadFolder(id); }
        else if (kind === 'file') { if (typeof openViewer === 'function') openViewer('file', id); }
        else if (kind === 'video') { if (typeof openViewer === 'function') openViewer('video', id); }
        else if (kind === 'link') { const l = findRow('link', id); if (l && l.url) window.open(l.url, '_blank', 'noopener'); }
    });

    function selectRow($row, e) {
        const key = $row.data('afm-row');
        if (e && (e.metaKey || e.ctrlKey)) {
            if (state.selectedRows.has(key)) state.selectedRows.delete(key); else state.selectedRows.add(key);
        } else if (e && e.shiftKey && state.lastSelectedKey) {
            selectRange(state.lastSelectedKey, key);
        } else {
            state.selectedRows.clear(); state.selectedRows.add(key);
        }
        state.lastSelectedKey = key;
        refreshSelectionUI();
    }

    function selectRange(fromKey, toKey) {
        const keys = $grid.find('.afm__row').map(function () { return $(this).data('afm-row'); }).get();
        const a = keys.indexOf(fromKey), b = keys.indexOf(toKey);
        if (a < 0 || b < 0) { state.selectedRows.add(toKey); return; }
        const lo = Math.min(a, b), hi = Math.max(a, b);
        for (let i = lo; i <= hi; i++) state.selectedRows.add(keys[i]);
    }

    function refreshSelectionUI() {
        $grid.find('.afm__row').each(function () {
            $(this).toggleClass('is-selected', state.selectedRows.has($(this).data('afm-row')));
        });
        renderBulkBar();
    }

    function renderBulkBar() {
        const n = state.selectedRows.size;
        let $bar = $root.find('[data-afm-bulkbar]');
        if (n < 2) { $bar.remove(); return; }
        if (!$bar.length) { $bar = $(`<div class="afm__bulkBar" data-afm-bulkbar></div>`).appendTo($root); }
        // Only files and folders can be downloaded in bulk; hide the button for
        // selections containing videos/links so we never silently skip items.
        const canBulkDownload = Array.from(state.selectedRows).every(k => {
            const kind = String(k).split(':')[0];
            return kind === 'file' || kind === 'folder';
        });
        const downloadBtn = canBulkDownload
            ? `<button type="button" class="afm__btn afm__btn--secondary" data-afm-bulk="download">Download</button>`
            : '';
        const adminBtns = AnchorFM.isAdmin
            ? `<button type="button" class="afm__btn afm__btn--secondary" data-afm-bulk="copy">Copy</button>
               <button type="button" class="afm__btn afm__btn--secondary" data-afm-bulk="duplicate">Duplicate</button>
               <button type="button" class="afm__btn afm__btn--danger" data-afm-bulk="delete">Delete</button>`
            : '';
        $bar.html(`<span class="afm__bulkCount">${n} selected</span>
            ${downloadBtn}
            ${adminBtns}
            <button type="button" class="afm__btn afm__btn--ghost" data-afm-bulk="clear">Clear</button>`);
    }

    $root.on('click', '[data-afm-bulk]', function () {
        const op = $(this).data('afm-bulk');
        const keys = Array.from(state.selectedRows);
        if (op === 'clear') { state.selectedRows.clear(); refreshSelectionUI(); return; }
        if (op === 'copy') { setClipboard(selectionAsItems()); state.selectedRows.clear(); refreshSelectionUI(); return; }
        if (op === 'duplicate') { copyItems(selectionAsItems(), state.currentFolderId); state.selectedRows.clear(); refreshSelectionUI(); return; }
        if (op === 'download') {
            // Each download must be its own request; assigning window.location in
            // a loop only keeps the last. Trigger a separate anchor click per item
            // (the stream endpoints send Content-Disposition: attachment), and
            // stagger them so the browser treats them as distinct downloads.
            keys.forEach((k, i) => {
                const parts = k.split(':'), kind = parts[0], id = Number(parts[1]);
                if (kind === 'folder') {
                    setTimeout(() => triggerDownload(folderDownloadUrl(id)), i * 400);
                } else if (kind === 'file') {
                    api('anchor_fm_preview', { file_id: id }).then(res => {
                        if (res && res.success && res.data.preview && res.data.preview.downloadUrl
                            && res.data.preview.available !== false) {
                            triggerDownload(res.data.preview.downloadUrl);
                        }
                    });
                }
            });
            return;
        }
        if (op === 'delete' && AnchorFM.isAdmin) {
            if (!window.confirm(`Delete ${keys.length} item(s)? This cannot be undone.`)) return;
            Promise.all(keys.map(k => {
                const parts = k.split(':'), kind = parts[0], id = Number(parts[1]);
                if (kind === 'file') return api('anchor_fm_delete_file', { file_id: id });
                if (kind === 'folder') return api('anchor_fm_delete_folder', { folder_id: id });
                if (kind === 'video') return api('anchor_fm_vimeo_delete', { video_id: id });
                if (kind === 'link') return api('anchor_fm_delete_link', { link_id: id });
                return Promise.resolve();
            })).then(() => { state.selectedRows.clear(); reloadCurrentFolder(); });
        }
    });

    function triggerDownload(url) {
        const a = document.createElement('a');
        a.href = url;
        a.rel = 'noopener';
        a.style.display = 'none';
        document.body.appendChild(a);
        a.click();
        setTimeout(() => { if (a.parentNode) a.parentNode.removeChild(a); }, 1000);
    }

    function openFileDownload(fileId) {
        api('anchor_fm_preview', { file_id: fileId }).then(res => {
            if (!res || !res.success || !res.data.preview || !res.data.preview.downloadUrl) return;
            // Navigating to a stream URL the server cannot satisfy drops the
            // user on a bare browser 404 page with no way back to the file
            // manager. Say what happened and keep them where they are.
            if (res.data.preview.available === false) {
                toast('That file could not be read from the server.');
                return;
            }
            triggerDownload(res.data.preview.downloadUrl);
        });
    }

    function folderDownloadUrl(folderId) {
        return `${AnchorFM.ajax}?action=anchor_fm_download_folder&folder_id=${folderId}&nonce=${AnchorFM.nonce}`;
    }

    function downloadFolder(folderId) {
        window.location = folderDownloadUrl(folderId);
    }

    function findRow(kind, id) {
        const local = currentRows(state.currentList).concat(
            Object.values(state.expandedRows).flat()
        ).find(r => r.kind === kind && r.id === Number(id));
        if (local) return local;
        // Fall back to a row surfaced via global search.
        return (state.searchRowsByKey && state.searchRowsByKey[kind + ':' + id]) || null;
    }

    $root.on('keydown', function (e) {
        if ($(e.target).is('input, textarea, [contenteditable]')) return;
        // state.tab is initialized to 'files' but never updated when another
        // panel (Users, Account, etc.) or the modal takes focus, so it can't
        // gate this handler. Guard on the actual DOM location instead: Enter
        // on a button inside the modal or the Users panel must not fall
        // through to "open the active/first files-grid row".
        if ($(e.target).closest('[data-afm-modal], [data-apfm-panel="users"]').length) return;
        const $rows = $grid.find('.afm__row');
        if (!$rows.length) return;
        let idx = $rows.index($grid.find('.afm__row.is-active'));
        if (e.key === 'ArrowDown') { e.preventDefault(); idx = Math.min($rows.length - 1, idx + 1); focusRowAt($rows, idx); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); idx = Math.max(0, idx - 1); focusRowAt($rows, idx); }
        else if (e.key === 'ArrowRight') { const $r = $rows.eq(Math.max(0, idx)); if ($r.data('afm-row-kind') === 'folder') $r.find('[data-afm-row-expand]').trigger('click'); }
        else if (e.key === 'ArrowLeft') { const $r = $rows.eq(Math.max(0, idx)); const fid = Number($r.data('afm-row-id')); if (state.expandedRows[fid]) { delete state.expandedRows[fid]; renderList(state.currentList, state.currentCapability); } }
        else if (e.key === 'Enter') { const $r = $rows.eq(Math.max(0, idx)); openRowDefault($r); }
        else if (e.key === ' ') { e.preventDefault(); const $r = $rows.eq(Math.max(0, idx)); previewRow($r); }
        else if (e.key === 'Escape') { if (typeof closeMenu === 'function') closeMenu(); if (!$modal.prop('hidden')) closeModal(); }
    });

    function focusRowAt($rows, idx) {
        $rows.removeClass('is-active');
        const $r = $rows.eq(idx).addClass('is-active');
        if ($r[0]) $r[0].scrollIntoView({ block: 'nearest' });
    }
    function openRowDefault($r) {
        const kind = $r.data('afm-row-kind'), id = Number($r.data('afm-row-id'));
        if (kind === 'folder') loadFolder(id);
        else if (kind === 'file' || kind === 'video') openViewer(kind, id);
        else if (kind === 'link') { const l = findRow('link', id); if (l && l.url) window.open(l.url, '_blank', 'noopener'); }
    }
    function previewRow($r) {
        const kind = $r.data('afm-row-kind'), id = Number($r.data('afm-row-id'));
        if (kind === 'file' || kind === 'video') openViewer(kind, id);
    }

    function startInlineRename(kind, id) {
        if (!AnchorFM.isAdmin) return;
        const $row = $grid.find(`[data-afm-row="${kind}:${id}"]`);
        const $label = $row.find('[data-afm-row-label]');
        if (!$label.length || $row.find('input.afm__renameInput').length) return;
        const current = $label.text();
        const $input = $(`<input type="text" class="afm__renameInput">`).val(current);
        $label.hide().after($input);
        $input.trigger('focus').trigger('select');

        function commit() {
            const name = String($input.val() || '').trim();
            $input.prop('disabled', true);
            if (!name || name === current) { cancel(); return; }
            const action = kind === 'folder' ? 'anchor_fm_rename_folder'
                : kind === 'video' ? 'anchor_fm_vimeo_update'
                : kind === 'file' ? 'anchor_fm_rename_file' : null;
            if (!action) { cancel(); return; }
            const data = {};
            if (kind === 'folder') { data.folder_id = id; data.name = name; }
            if (kind === 'video') { data.video_id = id; data.title = name; }
            if (kind === 'file') { data.file_id = id; data.name = name; }
            api(action, data).then(res => {
                if (res && res.success) reloadCurrentFolder(); else cancel();
            });
        }
        function cancel() { $input.remove(); $label.show(); }
        $input.on('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); commit(); }
            else if (e.key === 'Escape') { e.preventDefault(); cancel(); }
        });
        $input.on('blur', commit);
    }

    $root.on('keydown', function (e) {
        if (e.key === 'F2' && AnchorFM.isAdmin) {
            const $r = $grid.find('.afm__row.is-active');
            if ($r.length) startInlineRename($r.data('afm-row-kind'), Number($r.data('afm-row-id')));
        }
    });
    $root.on('dblclick', '[data-afm-row-label]', function (e) {
        if (!AnchorFM.isAdmin) return;
        e.stopPropagation();
        const $r = $(this).closest('[data-afm-row]');
        startInlineRename($r.data('afm-row-kind'), Number($r.data('afm-row-id')));
    });

    function shareUrlFor(kind, id) {
        const base = window.location.origin + window.location.pathname;
        return base + '#afm-' + kind + '-' + id;
    }
    function copyShareLink(kind, id) {
        const url = shareUrlFor(kind, id);
        const done = () => toast('Link copied');
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(url).then(done, () => fallbackCopy(url, done));
        } else { fallbackCopy(url, done); }
    }
    function fallbackCopy(text, cb) {
        const $t = $('<textarea>').val(text).css({ position: 'fixed', opacity: 0 }).appendTo('body');
        $t[0].select(); try { document.execCommand('copy'); } catch (e) {}
        $t.remove(); if (cb) cb();
    }
    function toast(msg) {
        const $t = $(`<div class="afm__toast">${esc(msg)}</div>`).appendTo($root);
        setTimeout(() => $t.addClass('is-show'), 10);
        setTimeout(() => { $t.removeClass('is-show'); setTimeout(() => $t.remove(), 300); }, 1800);
    }
    function handleDeepLink() {
        const m = (window.location.hash || '').match(/^#afm-(file|video|folder|link)-(\d+)$/);
        if (!m) return;
        const kind = m[1], id = Number(m[2]);
        if (kind === 'folder') { loadFolder(id); return; }
        if (kind === 'file' || kind === 'video') openViewer(kind, id);
    }

    function showAccessDenied(entityType, entityId, label) {
        const body = `<div class="afm__denied">
            <span class="dashicons dashicons-lock"></span>
            <div class="afm__deniedTitle">You don't have access to this item</div>
            <p class="afm__deniedText">If you think you should, you can request access.</p>
            <button type="button" class="afm__btn afm__btn--primary" data-afm-request-access
                    data-entity-type="${esc(entityType)}" data-entity-id="${esc(entityId)}" data-label="${esc(label || '')}">
                Request access</button>
            <div class="afm__notice" data-afm-request-notice hidden></div>
        </div>`;
        openViewerModal('Access required', body, '');
    }

    $root.on('click', '[data-afm-request-access]', function () {
        const $b = $(this);
        $b.prop('disabled', true);
        api('anchor_fm_request_access', {
            entity_type: $b.data('entity-type'),
            entity_id: $b.data('entity-id'),
            label: $b.data('label') || ''
        }).then(res => {
            const $n = $modalBody.find('[data-afm-request-notice]').prop('hidden', false);
            const d = (res && res.data) || {};
            let msg;
            if (!res || !res.success) {
                msg = (d.message) || 'Could not send request. Please try again later.';
                $b.prop('disabled', false); // allow a retry on failure
            } else if (d.alreadyHasAccess) {
                msg = 'You already have access to this item.';
            } else if (d.throttled) {
                msg = 'You already requested access for this item recently — no new message was sent.';
            } else {
                msg = 'Request sent. The site team has been notified.';
            }
            $n.text(msg);
        });
    });

    // Drag to move files into folders (admin/manage only)
    let dragFileId = null;
    let dragFolderId = null;

    $root.on('dragstart', '[data-afm-file-card]', function (e) {
        const id = Number($(this).data('afm-file-card'));
        if (!AnchorFM.isAdmin) return;
        dragFileId = id;
        dragFolderId = null;
        if (e.originalEvent && e.originalEvent.dataTransfer) {
            e.originalEvent.dataTransfer.effectAllowed = 'copyMove';
            e.originalEvent.dataTransfer.setData('text/plain', String(id));
        }
        $root.addClass('afm--dragMode');
    });

    $root.on('dragstart', '[data-afm-folder-card], [data-afm-open-folder]', function (e) {
        const id = Number($(this).data('afm-folder-card') || $(this).data('afm-open-folder'));
        if (!AnchorFM.isAdmin) return;
        dragFolderId = id;
        dragFileId = null;
        if (e.originalEvent && e.originalEvent.dataTransfer) {
            e.originalEvent.dataTransfer.effectAllowed = 'copyMove';
            e.originalEvent.dataTransfer.setData('text/plain', String(id));
        }
        $root.addClass('afm--dragMode');
    });

    // Finder rows: files and folders drag onto folder rows (or the sidebar tree) to move.
    $root.on('dragstart', '[data-afm-row]', function (e) {
        if (!AnchorFM.isAdmin) return;
        const kind = $(this).data('afm-row-kind');
        const id = Number($(this).data('afm-row-id'));
        if (kind === 'file') { dragFileId = id; dragFolderId = null; }
        else if (kind === 'folder') { dragFolderId = id; dragFileId = null; }
        else return;
        if (e.originalEvent && e.originalEvent.dataTransfer) {
            e.originalEvent.dataTransfer.effectAllowed = 'copyMove';
            e.originalEvent.dataTransfer.setData('text/plain', String(id));
        }
        $root.addClass('afm--dragMode');
    });

    $root.on('dragend', '[data-afm-file-card],[data-afm-folder-card],[data-afm-open-folder],[data-afm-row]', function () {
        dragFileId = null;
        dragFolderId = null;
        $root.removeClass('afm--drag');
        $root.removeClass('afm--dragMode');
        $root.find('.afm__card--folder, .afm__treeBtn, .afm__row--folder').removeClass('is-drop');
    });

    // Use capture on document to better catch drops over folder cards
    $(document).on('dragover', function (e) {
        if (!AnchorFM.isAdmin) return;
        if (!dragFileId && !dragFolderId) return;
        const el = document.elementFromPoint(e.clientX, e.clientY);
        const $target = $(el).closest('[data-afm-folder-card], [data-afm-open-folder], [data-afm-row-kind="folder"]');
        $root.find('.afm__card--folder, .afm__treeBtn, .afm__row--folder').removeClass('is-drop');
        if ($target.length) {
            e.preventDefault();
            if (e.originalEvent && e.originalEvent.dataTransfer) {
                e.originalEvent.dataTransfer.dropEffect = 'move';
            }
            $target.addClass('is-drop');
        }
    });

    $root.on('dragleave', '[data-afm-folder-card], [data-afm-open-folder], [data-afm-row-kind="folder"]', function () {
        $(this).removeClass('is-drop');
    });
    $root.on('drop', '[data-afm-folder-card], [data-afm-open-folder], [data-afm-row-kind="folder"]', function (e) {
        if (!AnchorFM.isAdmin) return;
        if (!dragFileId && !dragFolderId) return;
        e.preventDefault();
        const folderId = Number($(this).data('afm-folder-card') || $(this).data('afm-open-folder') || $(this).data('afm-row-id'));
        if (productDocsFolderId && folderId === productDocsFolderId) return;
        const copyMode = !!(e.originalEvent && (e.originalEvent.ctrlKey || e.originalEvent.altKey));
        handleDropOnFolder($(this), folderId, copyMode);
    });

    $(document).on('drop', function (e) {
        if (!AnchorFM.isAdmin) return;
        if (!dragFileId && !dragFolderId) return;
        const el = document.elementFromPoint(e.clientX, e.clientY);
        const $target = $(el).closest('[data-afm-folder-card], [data-afm-open-folder], [data-afm-row-kind="folder"]');
        if ($target.length) {
            e.preventDefault();
            const folderId = Number($target.data('afm-folder-card') || $target.data('afm-open-folder') || $target.data('afm-row-id'));
            if (productDocsFolderId && folderId === productDocsFolderId) return;
            const copyMode = !!(e.originalEvent && (e.originalEvent.ctrlKey || e.originalEvent.altKey));
            handleDropOnFolder($target, folderId, copyMode);
        }
    });

    function handleDropOnFolder($el, folderId, copyMode) {
        $el.removeClass('is-drop');
        if (copyMode) {
            const items = [];
            if (dragFileId) { items.push({ kind: 'file', id: dragFileId }); }
            else if (dragFolderId) {
                if (folderId === dragFolderId) return;
                items.push({ kind: 'folder', id: dragFolderId });
            }
            if (items.length) { flashDrop($el); copyItems(items, folderId); }
            return;
        }
        if (dragFileId) {
            api('anchor_fm_move_file', { file_id: dragFileId, folder_id: folderId }).done(res => {
                if (res && res.success) {
                    flashDrop($el);
                    loadFolder(state.currentFolderId);
                }
            });
        } else if (dragFolderId) {
            if (folderId === dragFolderId) return;
            api('anchor_fm_move_folder', { folder_id: dragFolderId, target_folder_id: folderId }).done(res => {
                if (res && res.success) {
                    flashDrop($el);
                    bootstrap();
                    loadFolder(folderId);
                }
            });
        }
    }

    function flashDrop($el) {
        $el.addClass('is-drop-flash');
        window.setTimeout(() => $el.removeClass('is-drop-flash'), 500);
    }

    // Panels follow the tree selection; Product Docs panel is admin-only and triggered by the admin tab.

    // Permissions: user search/add/remove
    let lastUserResults = [];
    $root.on('input', '[data-afm-user-search]', function () {
        const term = String($(this).val() || '').trim();
        const $suggest = $modalBody.find('[data-afm-suggest]');
        if (!term || term.length < 2) {
            $suggest.prop('hidden', true).html('');
            lastUserResults = [];
            return;
        }
        api('anchor_fm_user_search', { term }).done(res => {
            if (!res || !res.success) return;
            lastUserResults = res.data.users || [];
            if (!lastUserResults.length) {
                $suggest.prop('hidden', false).html('<div class="afm__suggestItem is-empty">No matches</div>');
                return;
            }
            $suggest.prop('hidden', false).html(lastUserResults.map(u => `
                <button type="button" class="afm__suggestItem" data-afm-pick-user="${u.id}">
                    <div class="afm__suggestName">${esc(u.displayName)}</div>
                    <div class="afm__suggestEmail">${esc(u.email)}</div>
                </button>
            `).join(''));
        });
    });
    $root.on('click', '[data-afm-pick-user]', function () {
        const id = Number($(this).data('afm-pick-user'));
        const u = lastUserResults.find(x => Number(x.id) === id);
        if (!u) return;
        state.usersDraft.push({ id: String(u.id), name: u.displayName });
        renderPermissionsEditor();
    });
    $root.on('click', '[data-afm-action="remove-user-perm"]', function () {
        const idx = Number($(this).data('afm-user-idx'));
        if (idx >= 0) {
            state.usersDraft.splice(idx, 1);
            renderPermissionsEditor();
        }
    });
    $root.on('click', '[data-afm-action="add-user-perm"]', function () {
        const term = String($modalBody.find('[data-afm-user-search]').val() || '').trim();
        if (!term) return;
        if (/^\d+$/.test(term)) {
            state.usersDraft.push({ id: term, name: term });
            renderPermissionsEditor();
        }
    });

    $root.on('change input', '[data-afm-perm-policy-op], [data-afm-rule-op], [data-afm-cond-role], [data-afm-cond-start], [data-afm-cond-end], [data-afm-cond-user-id]', function () {
        if (state.modalMode !== 'save-permissions') return;
        state.policyDraft = readPolicyFromEditor();
    });

    $root.on('change', '[data-afm-cond-type]', function () {
        if (state.modalMode !== 'save-permissions') return;
        const $condition = $(this).closest('[data-afm-perm-condition]');
        const $rule = $(this).closest('[data-afm-perm-rule]');
        const ruleIdx = Number($rule.data('afm-rule-idx'));
        const conditionIdx = Number($condition.data('afm-condition-idx'));
        const type = String($(this).val() || 'role');
        state.policyDraft = readPolicyFromEditor();
        if (state.policyDraft.rules[ruleIdx] && state.policyDraft.rules[ruleIdx].conditions[conditionIdx]) {
            state.policyDraft.rules[ruleIdx].conditions[conditionIdx] = defaultPermissionCondition(type);
        }
        renderPermissionsEditor();
    });

    $root.on('click', '[data-afm-action="add-perm-rule"]', function () {
        state.policyDraft = readPolicyFromEditor();
        state.policyDraft.rules.push({
            operator: 'all',
            conditions: [defaultPermissionCondition('role')],
        });
        renderPermissionsEditor();
    });

    $root.on('click', '[data-afm-action="remove-perm-rule"]', function () {
        state.policyDraft = readPolicyFromEditor();
        const idx = Number($(this).data('afm-rule-idx'));
        if (idx >= 0 && state.policyDraft.rules[idx]) {
            state.policyDraft.rules.splice(idx, 1);
            renderPermissionsEditor();
        }
    });

    $root.on('click', '[data-afm-action="add-perm-condition"]', function () {
        state.policyDraft = readPolicyFromEditor();
        const idx = Number($(this).data('afm-rule-idx'));
        const type = String($(this).data('afm-condition-type') || 'role');
        if (idx >= 0 && state.policyDraft.rules[idx]) {
            state.policyDraft.rules[idx].conditions.push(defaultPermissionCondition(type));
            renderPermissionsEditor();
        }
    });

    $root.on('click', '[data-afm-action="remove-perm-condition"]', function () {
        state.policyDraft = readPolicyFromEditor();
        const ruleIdx = Number($(this).data('afm-rule-idx'));
        const conditionIdx = Number($(this).data('afm-condition-idx'));
        if (ruleIdx >= 0 && conditionIdx >= 0 && state.policyDraft.rules[ruleIdx]) {
            state.policyDraft.rules[ruleIdx].conditions.splice(conditionIdx, 1);
            renderPermissionsEditor();
        }
    });

    // Product docs helpers
    function loadMyProductDocs() {
        if (!$productDocs.length) return;
        $productDocs.html('<div class="afm__skeleton afm__skeleton--share"></div>');
        api('anchor_pd_my_docs', {}).done(res => {
            if (!res || !res.success) {
                $productDocs.html(`<div class="afm__empty">${esc(res && res.data && res.data.message ? res.data.message : 'Unable to load documents.')}</div>`);
                return;
            }
            const docs = res.data.docs || [];
            if (!docs.length) {
                $productDocs.html('<div class="afm__empty">No documents available.</div>');
                return;
            }
            // Group by product
            const byProduct = {};
            docs.forEach(d => {
                const key = `${d.productId || d.product || 'Product'}`;
                if (!byProduct[key]) byProduct[key] = { name: d.product || key, id: d.productId || 0, docs: [] };
                byProduct[key].docs.push(d);
            });
            const cards = Object.values(byProduct).map(group => {
                const items = group.docs.map(d => `
                    <span class="afm__pill">
                        ${esc(d.title)}
                        ${AnchorFM.isAdmin ? `<button type="button" class="afm__pillClose" data-afm-doc-remove="${d.fileId}" data-afm-doc-product="${group.id}" aria-label="Remove">×</button>` : ''}
                    </span>
                `).join('');
                return `
                    <div class="afm__card afm__card--stack">
                        <div class="afm__cardMain">
                            <div class="afm__cardTitle">${esc(group.name)}</div>
                            <div class="afm__cardSub">${group.docs.length} document(s)</div>
                            <div class="afm__pillRow">${items}</div>
                        </div>
                    </div>
                `;
            });
            $productDocs.html(cards.join(''));
        });
    }

    function loadProducts() {
        if (!AnchorFM.isAdmin || !$productSelect.length) return;
        $productDocsManage.html('<div class="afm__skeleton afm__skeleton--share"></div>');
        api('anchor_pd_products', {}).done(res => {
            if (!res || !res.success) {
                $productDocsManage.html(`<div class="afm__empty">${esc(res && res.data && res.data.message ? res.data.message : 'Unable to load products.')}</div>`);
                return;
            }
            state.products = res.data.products || [];
            const options = state.products.map(p => `<option value="${p.id}">${esc(p.name)}</option>`).join('');
            $productSelect.html(options);
            if (state.products.length) {
                const firstId = state.products[0].id;
                $productSelect.val(firstId);
                setProductDocsDraft(firstId);
            } else {
                $productDocsManage.html('<div class="afm__empty">No products.</div>');
            }
        });
    }

    function setProductDocsDraft(productId) {
        const product = (state.products || []).find(p => Number(p.id) === Number(productId));
        state.productDocsDraft = product && product.docs ? product.docs.slice() : [];
        renderProductDocsManage();
    }

    function renderProductDocsManage() {
        if (!AnchorFM.isAdmin || !$productDocsManage.length) return;
        if (!state.productDocsDraft || !state.productDocsDraft.length) {
            state.productDocsDraft = [{ title: '', fileId: '', fileName: '', expires: '' }];
        }
        const d = state.productDocsDraft[0];
        const html = `
            <div class="afm__help">Upload or replace the document for this product. Set an optional expiry date (YYYY-MM-DD).</div>
            <div class="aap__item afm__formCard">
                <div class="aap__itemName">
                    <label>Title</label>
                    <input type="text" class="afm__input" data-afm-doc-title="0" value="${esc(d.title || '')}">
                </div>
                <div class="aap__itemSub">
                    <label>File</label>
                    <div class="afm__fieldInline">
                        <input type="text" class="afm__input" data-afm-doc-file-display="0" value="${esc(d.fileName || (d.fileId ? 'File #' + d.fileId : ''))}" readonly placeholder="Choose a file">
                        <button type="button" class="afm__btn afm__btn--secondary" data-afm-action="replace-doc" data-afm-doc-idx="0">
                            <span class="dashicons dashicons-upload" aria-hidden="true"></span>
                            Upload
                        </button>
                    </div>
                </div>
                <div class="aap__itemSub">
                    <label>Expires</label>
                    <input type="text" class="afm__input" placeholder="YYYY-MM-DD" data-afm-doc-expires="0" value="${esc(d.expires || '')}">
                </div>
            </div>
        `;
        $productDocsManage.html(html);
    }

    $productSelect.on('change', function () {
        const pid = Number($(this).val());
        setProductDocsDraft(pid);
    });

    $root.on('click', '[data-afm-action="add-doc"]', function () {
        state.productDocsDraft.push({ title: '', fileId: '', fileName: '', expires: '' });
        renderProductDocsManage();
    });
    $root.on('click', '[data-afm-action="remove-doc"]', function () {
        const idx = Number($(this).data('afm-doc-idx'));
        if (idx >= 0) {
            state.productDocsDraft.splice(idx, 1);
            renderProductDocsManage();
        }
    });
    $root.on('input', '[data-afm-doc-title]', function () {
        const idx = Number($(this).data('afm-doc-title'));
        if (state.productDocsDraft[idx]) state.productDocsDraft[idx].title = String($(this).val());
    });
    $root.on('input', '[data-afm-doc-expires]', function () {
        const idx = Number($(this).data('afm-doc-expires'));
        if (state.productDocsDraft[idx]) state.productDocsDraft[idx].expires = String($(this).val());
    });

    // Upload/replace doc file
    let replaceIdx = null;
    $root.on('click', '[data-afm-action="replace-doc"]', function () {
        replaceIdx = Number($(this).data('afm-doc-idx'));
        $productDocUpload.val('');
        $productDocUpload.trigger('click');
    });
    $productDocUpload.on('change', function () {
        const file = this.files && this.files[0];
        if (!file || replaceIdx === null) return;
        const pid = Number($productSelect.val());
        const fd = new FormData();
        fd.append('action', 'anchor_pd_upload');
        fd.append('nonce', AnchorFM.nonce);
        fd.append('product_id', pid);
        fd.append('file', file, file.name);
        $productDocsNotice.prop('hidden', true);
        $.ajax({
            url: AnchorFM.ajax,
            method: 'POST',
            data: fd,
            processData: false,
            contentType: false,
        }).done(res => {
            if (!res || !res.success) {
                $productDocsNotice.text(res && res.data && res.data.message ? res.data.message : 'Upload failed.').prop('hidden', false);
                return;
            }
            const info = res.data.file || {};
            if (!state.productDocsDraft[replaceIdx]) state.productDocsDraft[replaceIdx] = {};
            state.productDocsDraft[replaceIdx].fileId = info.id;
            state.productDocsDraft[replaceIdx].fileName = info.name;
            if (!state.productDocsDraft[replaceIdx].title) {
                state.productDocsDraft[replaceIdx].title = info.name;
            }
            renderProductDocsManage();
            replaceIdx = null;
            $productDocUpload.val('');
        }).fail(() => {
            $productDocsNotice.text('Upload failed.').prop('hidden', false);
        });
    });

    $root.on('click', '[data-afm-action="save-product-docs"]', function () {
        if (!AnchorFM.isAdmin) return;
        if (!$productDocsNotice.length) return;
        $productDocsNotice.prop('hidden', true);
        const pid = Number($productSelect.val());
        const docs = state.productDocsDraft.map(d => ({
            title: d.title || '',
            fileId: d.fileId || '',
            fileName: d.fileName || '',
            expires: d.expires || '',
        }));
        api('anchor_pd_save_docs', { product_id: pid, docs }).done(res => {
            if (!res || !res.success) {
                $productDocsNotice.text(res && res.data && res.data.message ? res.data.message : 'Unable to save.').prop('hidden', false);
                return;
            }
            $productDocsNotice.text('Saved.').prop('hidden', false);
            state.productDocsDraft = [{ title: '', fileId: '', fileName: '', expires: '' }];
            renderProductDocsManage();
            loadProducts();
            loadMyProductDocs();
        }).fail(xhr => {
            $productDocsNotice.text(errMessage(xhr, null, 'Unable to save.')).prop('hidden', false);
        });
    });

    // Remove doc via badge (admin)
    $root.on('click', '[data-afm-doc-remove]', function () {
        if (!AnchorFM.isAdmin) return;
        const fid = Number($(this).data('afm-doc-remove'));
        const pid = Number($(this).data('afm-doc-product') || 0);
        if (!pid || !fid) return;
        const product = (state.products || []).find(p => Number(p.id) === pid);
        if (!product) return;
        const newDocs = (product.docs || []).filter(d => Number(d.fileId) !== fid);
        api('anchor_pd_save_docs', { product_id: pid, docs: newDocs }).done(() => {
            loadProducts();
            loadMyProductDocs();
        }).fail(xhr => toast(errMessage(xhr, null, 'Unable to remove the document.')));
    });

    $root.on('anchorfm:showProductDocs', function () {
        if (!AnchorFM.isAdmin || !$productDocs.length) return;
        $root.find('[data-afm-panel]').removeClass('is-active');
        $root.find('[data-afm-panel="product-docs"]').addClass('is-active');
        loadMyProductDocs();
        loadProducts();
    });

    bootstrap();

    // Shared UI for sibling scripts (user-manager.js) so they reuse this
    // modal, request helper and toast instead of carrying their own.
    window.AnchorFMUI = {
        api, esc, toast, errMessage,
        modal: {
            open(title, bodyHtml, primaryLabel, onPrimary) {
                closeModal();
                $modalTitle.text(title);
                $modalBody.html(bodyHtml);
                setModalPrimary(primaryLabel || 'Save', 'external', null);
                externalModalHandler = onPrimary;
                openModal();
            },
            close: closeModal,
            busy: setModalBusy,
            body: () => $modalBody,
        },
    };
});
