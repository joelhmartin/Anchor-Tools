jQuery(function ($) {
    const $root = $('[data-apfm]');
    if (!$root.length || typeof AnchorAP === 'undefined') return;

    const $nav = $root.find('[data-apfm-tab]');
    const $panels = $root.find('[data-apfm-panel]');
    const $title = $root.find('[data-apfm-title], [data-aap-title]');
    const $searchWrap = $root.find('[data-apfm-search]');
    const $uploadWrap = $root.find('[data-apfm-upload]');
    const $tree = $root.find('[data-afm-tree]');
    const $filesOnly = $root.find('[data-apfm-files-only]');
    const $breadcrumbs = $root.find('[data-afm-breadcrumbs]');

    const $orders = $root.find('[data-aap-orders]');
    const $downloads = $root.find('[data-aap-downloads]');
    const $orderDrawer = $root.find('[data-aap-drawer]');
    const $orderDrawerTitle = $root.find('[data-aap-drawer-title]');
    const $orderMeta = $root.find('[data-aap-order-meta]');
    const $orderItems = $root.find('[data-aap-order-items]');

    const $profileForm = $root.find('[data-aap-profile-form]');
    const $profileNotice = $root.find('[data-aap-profile-notice]');
    const $passwordForm = $root.find('[data-aap-password-form]');
    const $passwordNotice = $root.find('[data-aap-password-notice]');
    const $resetNotice = $root.find('[data-aap-reset-notice]');

    const state = { tab: 'files', page: 1, currentFolderId: 0 };

    function apiAccount(action, data) {
        return $.post(AnchorAP.ajax, Object.assign({ action, nonce: AnchorAP.nonce }, data || {}));
    }

    function esc(s) {
        return String(s || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function setNotice($el, kind, text) {
        $el.removeClass('is-success is-error');
        $el.addClass(kind === 'success' ? 'is-success' : 'is-error');
        $el.text(text);
        $el.prop('hidden', false);
    }

    /**
     * .fail() handler that shows the server's message. wp_send_json_error with
     * a 4xx/5xx status rejects the jQuery deferred, so .done() alone never
     * sees those errors and the form would fail silently.
     */
    function failNotice($el, fallback) {
        return function (xhr) {
            const msg = xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message;
            setNotice($el, 'error', msg || fallback);
        };
    }

    function resetProfileForm() {
        if (!AnchorAP.user) return;
        $profileForm.find('[name="first_name"]').val(AnchorAP.user.firstName || '');
        $profileForm.find('[name="last_name"]').val(AnchorAP.user.lastName || '');
        $profileForm.find('[name="user_email"]').val(AnchorAP.user.email || '');
    }

    function updateTitle(tab) {
        const fm = (typeof AnchorFM !== 'undefined') ? AnchorFM : null;
        const titleMap = {
            files: (fm && fm.i18n && fm.i18n.title) ? fm.i18n.title : 'Documents',
            orders: AnchorAP.i18n && AnchorAP.i18n.orders ? AnchorAP.i18n.orders : 'Orders',
            downloads: AnchorAP.i18n && AnchorAP.i18n.downloads ? AnchorAP.i18n.downloads : 'Downloads',
            'product-docs': (fm && fm.i18n && fm.i18n.productDocs) ? fm.i18n.productDocs : 'Product Docs',
            users: (fm && fm.i18n && fm.i18n.users) ? fm.i18n.users : 'Users',
            account: AnchorAP.i18n && AnchorAP.i18n.account ? AnchorAP.i18n.account : 'Account',
            security: AnchorAP.i18n && AnchorAP.i18n.security ? AnchorAP.i18n.security : 'Security',
        };
        $title.text(titleMap[tab] || titleMap.files);
    }

    function updateToolbar() {
        const isFiles = state.tab === 'files';
        $searchWrap.prop('hidden', !isFiles);
        $uploadWrap.prop('hidden', !isFiles);
        $tree.prop('hidden', !isFiles);
        $filesOnly.prop('hidden', !isFiles);
        $breadcrumbs.toggle(isFiles);
        if (!isFiles) {
            $breadcrumbs.html('');
        }
        $root.toggleClass('apfm--tab-files', isFiles);
        $root.toggleClass('apfm--lock-files', !isFiles);
        $root.toggleClass('apfm--hide-files', !isFiles);
    }

    function showPanels(tab) {
        $panels.removeClass('is-active').prop('hidden', true);
        $panels.each(function () {
            const panelTab = String($(this).data('apfm-panel') || '');
            if (panelTab === tab) {
                $(this).prop('hidden', false).addClass('is-active');
            }
        });
    }

    function switchTab(tab) {
        state.tab = tab;
        $nav.removeClass('is-active');
        $nav.filter(`[data-apfm-tab="${tab}"]`).addClass('is-active');
        showPanels(tab);
        updateTitle(tab);
        updateToolbar();

        if (tab !== 'files') {
            $root.find('[data-afm-action="close-drawer"]').trigger('click');
            $root.find('[data-afm-action="close-modal"]').trigger('click');
        }
        if (tab !== 'orders') {
            closeOrderDrawer();
        }
        if (tab === 'orders') loadOrders();
        if (tab === 'downloads') loadDownloads();
        if (tab === 'product-docs') {
            $root.trigger('anchorfm:showProductDocs');
        }
        if (tab === 'users') {
            $root.trigger('anchorfm:showUsers');
        }
        if (tab === 'account') {
            $profileNotice.prop('hidden', true);
            resetProfileForm();
        }
        if (tab === 'security') {
            $passwordNotice.prop('hidden', true);
            $resetNotice.prop('hidden', true);
            $passwordForm.trigger('reset');
        }
    }

    function renderOrders(items) {
        if (!AnchorAP.hasWoo) {
            $orders.html('<div class="afm__empty">WooCommerce is not available.</div>');
            return;
        }
        if (!items || !items.length) {
            $orders.html('<div class="afm__empty">No orders found.</div>');
            return;
        }
        $orders.html(items.map(o => `
            <div class="aap__orderCard" data-aap-order="${o.id}">
                <div class="aap__orderTop">
                    <div class="aap__orderNo">#${esc(o.number)}</div>
                    <div class="aap__orderStatus">${esc(o.statusLabel || o.status)}</div>
                </div>
                <div class="aap__orderMeta">
                    <div>${esc(o.date)}</div>
                    <div>${o.totalHtml} • ${esc(o.items)} items</div>
                </div>
                <button type="button" class="afm__btn afm__btn--secondary aap__orderBtn">
                    <span class="dashicons dashicons-visibility" aria-hidden="true"></span>
                    View
                </button>
            </div>
        `).join(''));
    }

    function loadOrders() {
        if (!$orders.length) return;
        $orders.html('<div class="afm__skeleton afm__skeleton--share"></div>');
        apiAccount('anchor_ap_orders', { page: state.page }).done(res => {
            if (!res || !res.success) {
                $orders.html(`<div class="afm__empty">${esc(res && res.data && res.data.message ? res.data.message : 'Unable to load orders.')}</div>`);
                return;
            }
            renderOrders(res.data.orders);
        });
    }

    function openOrderDrawer() {
        $orderDrawer.addClass('is-open');
        $root.addClass('afm--drawerOpen afm--orderDrawer');
    }

    function closeOrderDrawer() {
        $orderDrawer.removeClass('is-open');
        if (!$root.find('[data-afm-drawer]').hasClass('is-open')) {
            $root.removeClass('afm--drawerOpen');
        }
        $root.removeClass('afm--orderDrawer');
        $orderDrawerTitle.text('Order');
        $orderMeta.html('');
        $orderItems.html('');
    }

    function loadOrder(orderId) {
        openOrderDrawer();
        $orderDrawerTitle.text('Loading…');
        $orderMeta.html('<div class="afm__skeleton afm__skeleton--meta"></div>');
        $orderItems.html('<div class="afm__skeleton afm__skeleton--share"></div>');

        apiAccount('anchor_ap_order', { order_id: Number(orderId) }).done(res => {
            if (!res || !res.success) {
                $orderDrawerTitle.text('Order');
                $orderMeta.html(`<div class="afm__empty">${esc(res && res.data && res.data.message ? res.data.message : 'Unable to load order.')}</div>`);
                $orderItems.html('');
                return;
            }
            const o = res.data.order;
            $orderDrawerTitle.text(`#${o.number}`);
            $orderMeta.html(`
                <div class="afm__metaRow"><div class="afm__metaKey">Status</div><div class="afm__metaVal">${esc(o.statusLabel)}</div></div>
                <div class="afm__metaRow"><div class="afm__metaKey">Date</div><div class="afm__metaVal">${esc(o.date)}</div></div>
                <div class="afm__metaRow"><div class="afm__metaKey">Total</div><div class="afm__metaVal">${o.totalHtml}</div></div>
                <div class="afm__metaRow"><div class="afm__metaKey">Payment</div><div class="afm__metaVal">${esc(o.paymentMethod || '')}</div></div>
            `);
            $orderItems.html(`
                <div class="aap__sectionTitle">Items</div>
                <div class="aap__itemList">
                    ${(res.data.items || []).map(it => `
                        <div class="aap__item">
                            <div class="aap__itemName">${esc(it.name)}</div>
                            <div class="aap__itemSub">${esc(it.sku ? ('SKU ' + it.sku + ' • ') : '')}Qty ${esc(it.quantity)}</div>
                            <div class="aap__itemPrice">${it.totalHtml}</div>
                        </div>
                    `).join('')}
                </div>
            `);
        });
    }

    function loadDownloads() {
        if (!$downloads.length) return;
        $downloads.html('<div class="afm__skeleton afm__skeleton--share"></div>');
        $.post(AnchorAP.ajax, {
            action: 'anchor_pd_my_docs',
            nonce: AnchorAP.nonce,
        }).done(res => {
            if (!res || !res.success) {
                $downloads.html(`<div class="afm__empty">${esc(res && res.data && res.data.message ? res.data.message : 'Unable to load downloads.')}</div>`);
                return;
            }
            const docs = res.data.docs || [];
            if (!docs.length) {
                $downloads.html('<div class="afm__empty">No downloads yet.</div>');
                return;
            }
            $downloads.html(docs.map(d => `
                <div class="afm__card">
                    <div class="afm__cardIcon dashicons dashicons-media-document" aria-hidden="true"></div>
                    <div class="afm__cardMain">
                        <div class="afm__cardTitle">${esc(d.title)}</div>
                        <div class="afm__cardSub">${esc(d.product)}${d.expires ? ' • Expires ' + esc(d.expires) : ''}</div>
                    </div>
                    ${d.available === false
                        ? `<span class="afm__btn afm__btn--disabled" aria-disabled="true" title="This file could not be read from the server.">
                        <span class="dashicons dashicons-warning" aria-hidden="true"></span>
                        Unavailable
                    </span>`
                        : `<a class="afm__btn afm__btn--primary" href="${esc(d.downloadUrl)}">
                        <span class="dashicons dashicons-download" aria-hidden="true"></span>
                        Download
                    </a>`}
                </div>
            `).join(''));
        });
    }

    // Events
    $root.on('click', '[data-apfm-tab]', function () {
        switchTab(String($(this).data('apfm-tab')));
    });

    $root.on('click', '[data-aap-order]', function () {
        loadOrder($(this).data('aap-order'));
    });

    $root.on('click', '[data-aap-action="close-drawer"]', closeOrderDrawer);

    $profileForm.on('submit', function (e) {
        e.preventDefault();
        $profileNotice.prop('hidden', true);
        const data = {
            first_name: $profileForm.find('[name="first_name"]').val(),
            last_name: $profileForm.find('[name="last_name"]').val(),
            user_email: $profileForm.find('[name="user_email"]').val(),
        };
        apiAccount('anchor_ap_update_profile', data).done(res => {
            if (!res || !res.success) {
                setNotice($profileNotice, 'error', res && res.data && res.data.message ? res.data.message : 'Unable to save.');
                return;
            }
            setNotice($profileNotice, 'success', 'Saved.');
        }).fail(failNotice($profileNotice, 'Unable to save.'));
    });

    $passwordForm.on('submit', function (e) {
        e.preventDefault();
        $passwordNotice.prop('hidden', true);
        const newPassword = String($passwordForm.find('[name="new_password"]').val() || '');
        apiAccount('anchor_ap_change_password', { new_password: newPassword }).done(res => {
            if (!res || !res.success) {
                setNotice($passwordNotice, 'error', res && res.data && res.data.message ? res.data.message : 'Unable to change password.');
                return;
            }
            setNotice($passwordNotice, 'success', 'Password changed. Please log in again.');
            if (res.data && res.data.loginUrl) {
                window.setTimeout(() => { window.location.href = res.data.loginUrl; }, 900);
            }
        }).fail(failNotice($passwordNotice, 'Unable to change password.'));
    });

    $root.on('click', '[data-aap-action="send-reset"]', function () {
        $resetNotice.prop('hidden', true);
        apiAccount('anchor_ap_send_reset', {}).done(res => {
            if (!res || !res.success) {
                setNotice($resetNotice, 'error', res && res.data && res.data.message ? res.data.message : 'Unable to send reset email.');
                return;
            }
            setNotice($resetNotice, 'success', 'Reset email sent.');
        }).fail(failNotice($resetNotice, 'Unable to send reset email.'));
    });

    $root.on('anchorfm:folderLoaded', function (_evt, payload) {
        state.currentFolderId = payload && typeof payload.folderId !== 'undefined' ? Number(payload.folderId) : 0;
        updateToolbar();
    });

    $root.on('anchorfm:bootstrapped', function (_evt, payload) {
        if (payload && typeof payload.defaultFolderId !== 'undefined') {
            state.currentFolderId = Number(payload.defaultFolderId) || 0;
            updateToolbar();
        }
    });

    showPanels(state.tab);
    updateTitle(state.tab);
    updateToolbar();
    switchTab('files');
});
