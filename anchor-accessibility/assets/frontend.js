(function () {
    'use strict';

    var STORAGE_KEY = 'anchor_a11y_prefs';
    var root = document.documentElement;
    var body = document.body;

    /* ------------------------------------------------------------------ */
    /*  State                                                             */
    /* ------------------------------------------------------------------ */

    var state = loadState();

    function defaultState() {
        return {
            fontStep: 0,        // -3 … +5
            contrast: false,
            grayscale: false,
            underline: false,
            readable: false,
            spacing: false,
            hideImages: false,
            bigCursor: false,
            pauseAnim: false,
            highlight: false
        };
    }

    function loadState() {
        try {
            var raw = localStorage.getItem(STORAGE_KEY);
            if (raw) return Object.assign(defaultState(), JSON.parse(raw));
        } catch (e) { /* private browsing */ }
        return defaultState();
    }

    function saveState() {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
        } catch (e) { /* quota / private browsing */ }
    }

    /* ------------------------------------------------------------------ */
    /*  Apply state to DOM                                                */
    /* ------------------------------------------------------------------ */

    function applyAll() {
        // Font size
        if (state.fontStep !== 0) {
            root.style.fontSize = (100 + state.fontStep * 10) + '%';
        } else {
            root.style.fontSize = '';
        }

        toggle('aa-contrast', state.contrast);
        toggle('aa-grayscale', state.grayscale);
        toggle('aa-underline', state.underline);
        toggle('aa-readable', state.readable);
        toggle('aa-spacing', state.spacing);
        toggle('aa-hide-images', state.hideImages);
        toggle('aa-big-cursor', state.bigCursor);
        toggle('aa-pause-anim', state.pauseAnim);
        toggle('aa-highlight', state.highlight);

        updateButtons();
        saveState();
    }

    function toggle(cls, on) {
        body.classList.toggle(cls, !!on);
    }

    /* ------------------------------------------------------------------ */
    /*  Button state sync                                                 */
    /* ------------------------------------------------------------------ */

    function updateButtons() {
        var panel = document.getElementById('anchor-a11y-panel');
        if (!panel) return;

        var map = {
            'contrast':     state.contrast,
            'grayscale':    state.grayscale,
            'underline':    state.underline,
            'readable':     state.readable,
            'spacing':      state.spacing,
            'hide-images':  state.hideImages,
            'big-cursor':   state.bigCursor,
            'pause-anim':   state.pauseAnim,
            'highlight':    state.highlight
        };

        var btns = panel.querySelectorAll('[data-action]');
        for (var i = 0; i < btns.length; i++) {
            var action = btns[i].getAttribute('data-action');
            if (action in map) {
                btns[i].setAttribute('aria-pressed', map[action] ? 'true' : 'false');
            }
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Actions                                                           */
    /* ------------------------------------------------------------------ */

    var actions = {
        'font-increase': function () { if (state.fontStep < 5) state.fontStep++; },
        'font-decrease': function () { if (state.fontStep > -3) state.fontStep--; },
        'font-reset':    function () { state.fontStep = 0; },
        'contrast':      function () { state.contrast    = !state.contrast; },
        'grayscale':     function () { state.grayscale   = !state.grayscale; },
        'underline':     function () { state.underline   = !state.underline; },
        'readable':      function () { state.readable    = !state.readable; },
        'spacing':       function () { state.spacing     = !state.spacing; },
        'hide-images':   function () { state.hideImages  = !state.hideImages; },
        'big-cursor':    function () { state.bigCursor   = !state.bigCursor; },
        'pause-anim':    function () { state.pauseAnim   = !state.pauseAnim; },
        'highlight':     function () { state.highlight   = !state.highlight; },
        'reset-all':     function () { state = defaultState(); }
    };

    /* ------------------------------------------------------------------ */
    /*  Panel open/close                                                  */
    /* ------------------------------------------------------------------ */

    function openPanel() {
        var panel  = document.getElementById('anchor-a11y-panel');
        var toggle = document.querySelector('.anchor-a11y-toggle');
        if (!panel || !toggle) return;

        panel.hidden = false;
        toggle.setAttribute('aria-expanded', 'true');
        panel.querySelector('.anchor-a11y-close').focus();
    }

    function closePanel() {
        var panel  = document.getElementById('anchor-a11y-panel');
        var toggle = document.querySelector('.anchor-a11y-toggle');
        if (!panel || !toggle) return;

        panel.hidden = true;
        toggle.setAttribute('aria-expanded', 'false');
        toggle.focus();
    }

    /* ------------------------------------------------------------------ */
    /*  Init                                                              */
    /* ------------------------------------------------------------------ */

    function init() {
        var widget = document.getElementById('anchor-a11y-widget');
        if (!widget) return;

        // Apply saved preferences immediately
        applyAll();

        // Toggle button
        widget.querySelector('.anchor-a11y-toggle').addEventListener('click', function () {
            var panel = document.getElementById('anchor-a11y-panel');
            if (panel.hidden) {
                openPanel();
            } else {
                closePanel();
            }
        });

        // Close button
        widget.querySelector('.anchor-a11y-close').addEventListener('click', closePanel);

        // Action buttons (delegation)
        widget.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-action]');
            if (!btn) return;

            var action = btn.getAttribute('data-action');
            if (actions[action]) {
                actions[action]();
                applyAll();
            }
        });

        // Escape to close
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                var panel = document.getElementById('anchor-a11y-panel');
                if (panel && !panel.hidden) {
                    closePanel();
                }
            }
        });

        // Click outside to close
        document.addEventListener('click', function (e) {
            var panel = document.getElementById('anchor-a11y-panel');
            if (panel && !panel.hidden && !widget.contains(e.target)) {
                closePanel();
            }
        });

        // Trap focus inside panel when open
        widget.addEventListener('keydown', function (e) {
            if (e.key !== 'Tab') return;

            var panel = document.getElementById('anchor-a11y-panel');
            if (!panel || panel.hidden) return;

            var focusable = panel.querySelectorAll('button, a[href], [tabindex]:not([tabindex="-1"])');
            if (!focusable.length) return;

            var first = focusable[0];
            var last  = focusable[focusable.length - 1];

            if (e.shiftKey && document.activeElement === first) {
                e.preventDefault();
                last.focus();
            } else if (!e.shiftKey && document.activeElement === last) {
                e.preventDefault();
                first.focus();
            }
        });
    }

    // Run on DOMContentLoaded or immediately if already loaded
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
