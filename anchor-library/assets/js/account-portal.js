jQuery(function ($) {
    const $root = $('[data-aap]');
    if (!$root.length) return;

    const $panels = $root.find('[data-aap-panel]');
    const $nav = $root.find('[data-aap-tab]');
    const $title = $root.find('[data-aap-title]');

    const $orders = $root.find('[data-aap-orders]');
    const $downloads = $root.find('[data-aap-downloads]');
    const $drawer = $root.find('[data-aap-drawer]');
    const $drawerTitle = $root.find('[data-aap-drawer-title]');
    const $orderMeta = $root.find('[data-aap-order-meta]');
    const $orderItems = $root.find('[data-aap-order-items]');

    const $profileForm = $root.find('[data-aap-profile-form]');
    const $profileNotice = $root.find('[data-aap-profile-notice]');

    const $passwordForm = $root.find('[data-aap-password-form]');
    const $passwordNotice = $root.find('[data-aap-password-notice]');
    const $resetNotice = $root.find('[data-aap-reset-notice]');

    const state = { tab: 'account', page: 1 };

    function api(action, data) {
        return $.post(AnchorAP.ajax, Object.assign({ action, nonce: AnchorAP.nonce }, data || {}));
    }

    function esc(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function openDrawer() {
        $drawer.addClass('is-open');
        $root.addClass('afm--drawerOpen');
    }

    function closeDrawer() {
        $drawer.removeClass('is-open');
        $root.removeClass('afm--drawerOpen');
        $drawerTitle.text('Order');
        $orderMeta.html('');
        $orderItems.html('');
    }

    function setNotice($el, kind, text) {
        $el.removeClass('is-success is-error');
        $el.addClass(kind === 'success' ? 'is-success' : 'is-error');
        $el.text(text);
        $el.prop('hidden', false);
    }

    function switchTab(tab) {
        state.tab = tab;
        $nav.removeClass('is-active');
        $nav.filter(`[data-aap-tab="${tab}"]`).addClass('is-active');
        $panels.removeClass('is-active');
        $panels.filter(`[data-aap-panel="${tab}"]`).addClass('is-active');
        closeDrawer();

        const titleMap = {
            account: AnchorAP.i18n.account || 'Account',
            orders: AnchorAP.i18n.orders || 'Orders',
            downloads: AnchorAP.i18n.downloads || 'Downloads',
            security: AnchorAP.i18n.security || 'Security',
        };
        $title.text(titleMap[tab] || (AnchorAP.i18n.account || 'Account'));

        if (tab === 'orders') loadOrders();
        if (tab === 'downloads') loadDownloads();
    }

    function renderOrders(items) {
        if (!AnchorAP.hasWoo) {
            $orders.html(`<div class="afm__empty">WooCommerce is not available.</div>`);
            return;
        }
        if (!items || !items.length) {
            $orders.html(`<div class="afm__empty">No orders found.</div>`);
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
        $orders.html('<div class="afm__skeleton afm__skeleton--share"></div>');
        api('anchor_ap_orders', { page: state.page }).done(res => {
            if (!res || !res.success) {
                $orders.html(`<div class="afm__empty">${esc(res && res.data && res.data.message ? res.data.message : 'Unable to load orders.')}</div>`);
                return;
            }
            renderOrders(res.data.orders);
        });
    }

    function loadOrder(orderId) {
        openDrawer();
        $drawerTitle.text('Loading…');
        $orderMeta.html('<div class="afm__skeleton afm__skeleton--meta"></div>');
        $orderItems.html('<div class="afm__skeleton afm__skeleton--share"></div>');

        api('anchor_ap_order', { order_id: Number(orderId) }).done(res => {
            if (!res || !res.success) {
                $drawerTitle.text('Order');
                $orderMeta.html(`<div class="afm__empty">${esc(res && res.data && res.data.message ? res.data.message : 'Unable to load order.')}</div>`);
                $orderItems.html('');
                return;
            }
            const o = res.data.order;
            $drawerTitle.text(`#${o.number}`);
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

    // Events
    $root.on('click', '[data-aap-tab]', function () {
        switchTab(String($(this).data('aap-tab')));
    });
    $root.on('click', '[data-aap-action="refresh"]', function () {
        if (state.tab === 'orders') loadOrders();
        if (state.tab === 'downloads') loadDownloads();
    });
    $root.on('click', '[data-aap-order]', function () {
        loadOrder($(this).data('aap-order'));
    });
    $root.on('click', '[data-aap-action="close-drawer"]', closeDrawer);

    $profileForm.on('submit', function (e) {
        e.preventDefault();
        $profileNotice.prop('hidden', true);
        const data = {
            first_name: $profileForm.find('[name="first_name"]').val(),
            last_name: $profileForm.find('[name="last_name"]').val(),
            user_email: $profileForm.find('[name="user_email"]').val(),
        };
        api('anchor_ap_update_profile', data).done(res => {
            if (!res || !res.success) {
                setNotice($profileNotice, 'error', res && res.data && res.data.message ? res.data.message : 'Unable to save.');
                return;
            }
            setNotice($profileNotice, 'success', 'Saved.');
        });
    });

    $passwordForm.on('submit', function (e) {
        e.preventDefault();
        $passwordNotice.prop('hidden', true);
        const newPassword = String($passwordForm.find('[name="new_password"]').val() || '');
        api('anchor_ap_change_password', { new_password: newPassword }).done(res => {
            if (!res || !res.success) {
                setNotice($passwordNotice, 'error', res && res.data && res.data.message ? res.data.message : 'Unable to change password.');
                return;
            }
            setNotice($passwordNotice, 'success', 'Password changed. Please log in again.');
            if (res.data && res.data.loginUrl) {
                window.setTimeout(() => { window.location.href = res.data.loginUrl; }, 900);
            }
        });
    });

    $root.on('click', '[data-aap-action="send-reset"]', function () {
        $resetNotice.prop('hidden', true);
        api('anchor_ap_send_reset', {}).done(res => {
            if (!res || !res.success) {
                setNotice($resetNotice, 'error', res && res.data && res.data.message ? res.data.message : 'Unable to send reset email.');
                return;
            }
            setNotice($resetNotice, 'success', 'Reset email sent.');
        });
    });

    switchTab('account');

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
});
