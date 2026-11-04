/**
 * Product Docs panel (admin-only management + everyone's "my documents" list).
 * Split out of file-manager.js; runs on the shared window.AnchorFMCore
 * context, so it must be enqueued after file-manager.js.
 */
(function ($) {
    $(function () {
        const C = window.AnchorFMCore;
        if (!C) return;
        const { $root, state, api, esc, toast, errMessage } = C;
        const $productDocs = $root.find('[data-afm-product-docs]');
        const $productSelect = $root.find('[data-afm-product-select]');
        const $productDocsManage = $root.find('[data-afm-product-docs-manage]');
        const $productDocsNotice = $root.find('[data-afm-product-docs-notice]');
        const $productDocUpload = $('<input type="file" accept="*/*" data-afm-doc-upload style="display:none;">');
        $root.append($productDocUpload);

        // Product docs helpers
        function loadMyProductDocs() {
            if (!$productDocs.length) return;
            $productDocs.html('<div class="afm__skeleton afm__skeleton--share"></div>');
            api('anchor_pd_my_docs', {}, { quiet: true }).fail(xhr => {
                $productDocs.html(`<div class="afm__empty">${esc(errMessage(xhr, null, 'Unable to load documents.'))}</div>`);
            }).done(res => {
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
            api('anchor_pd_products', {}, { quiet: true }).fail(xhr => {
                $productDocsManage.html(`<div class="afm__empty">${esc(errMessage(xhr, null, 'Unable to load products.'))}</div>`);
            }).done(res => {
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
            api('anchor_pd_save_docs', { product_id: pid, docs }, { quiet: true }).done(res => {
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
            api('anchor_pd_save_docs', { product_id: pid, docs: newDocs }, { quiet: true }).done(() => {
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
    });
})(jQuery);
