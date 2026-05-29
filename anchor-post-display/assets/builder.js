/**
 * Anchor Post Display — builder behaviour.
 *
 * Owns two things the shared builder.js does not handle for this CPT:
 *   1. Live preview (debounced AJAX) into #apd-preview.
 *   2. Conditional field visibility driven by the `apd_layout` select and by
 *      each field's data-applies-to (layout list) / data-depends-on (JSON).
 *
 * Device width switching + tabs + copy are handled by the shared builder.js.
 */
(function () {
    'use strict';

    var cfg  = window.APD_BUILDER || {};
    var root = document.getElementById('anchor-post-display-builder');
    if (!root) return;

    var box = document.getElementById('apd-preview');

    /* ---- Live preview (reflects last saved settings) ---- */

    function refreshPreview() {
        if (!box || !cfg.ajaxUrl) return;
        var body = new URLSearchParams();
        body.set('action', 'anchor_post_display_preview');
        body.set('nonce', cfg.nonce);
        body.set('post_id', cfg.postId);
        fetch(cfg.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res && res.success) {
                    box.innerHTML = res.data.html;
                    if (typeof window.AnchorPostDisplayInit === 'function') {
                        window.AnchorPostDisplayInit(box);
                    }
                } else if (res && res.data && res.data.message) {
                    box.innerHTML = '<p class="apd-preview__hint">' + res.data.message + '</p>';
                }
            })
            .catch(function () {});
    }

    /* ---- Conditional field visibility ---- */

    function currentLayout() {
        var sel = root.querySelector('select[name="apd_layout"]');
        return sel ? sel.value : '';
    }

    function fieldValue(settingKey) {
        var el = root.querySelector('[name="apd_' + settingKey + '"]');
        if (!el) return null;
        if (el.type === 'checkbox') return el.checked;
        return el.value;
    }

    function dependsSatisfied(depends) {
        for (var key in depends) {
            if (!depends.hasOwnProperty(key)) continue;
            var want = depends[key];
            var have = fieldValue(key);
            if (Array.isArray(want)) {
                if (want.map(String).indexOf(String(have)) === -1) return false;
            } else if (want === true) {
                if (!(have === true || have === '1' || have === 1)) return false;
            } else {
                if (String(have) !== String(want)) return false;
            }
        }
        return true;
    }

    function syncConditional() {
        var layout = currentLayout();
        root.querySelectorAll('.anchor-builder__field').forEach(function (f) {
            var visible = true;

            var applies = f.getAttribute('data-applies-to');
            if (applies) {
                var list = applies.split(',').map(function (s) { return s.trim(); });
                if (layout && list.indexOf(layout) === -1) visible = false;
            }

            if (visible) {
                var depRaw = f.getAttribute('data-depends-on');
                if (depRaw) {
                    try {
                        if (!dependsSatisfied(JSON.parse(depRaw))) visible = false;
                    } catch (e) {}
                }
            }

            f.style.display = visible ? '' : 'none';
        });
    }

    /* ---- Wiring ---- */

    root.addEventListener('change', function (e) {
        if (e.target && e.target.closest('.anchor-builder__panel--left, .apd-source')) {
            syncConditional();
        }
    });

    // Shared builder.js fires this when the refresh (↻) button is clicked.
    document.addEventListener('anchor-builder:refresh-preview', refreshPreview);
    if (window.jQuery) {
        window.jQuery(document).on('anchor-builder:refresh-preview', refreshPreview);
    }

    syncConditional();
    refreshPreview();
})();
