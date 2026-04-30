(function () {
    'use strict';

    var STORAGE_KEY = 'anchor_a11y_prefs_v2';
    var root = document.documentElement;
    var body = document.body;
    var widget;
    var panel;
    var toggleButton;
    var restoreButton;
    var backdrop;
    var tooltipEl;
    var guideEl;
    var maskTopEl;
    var maskBottomEl;
    var liveEl;
    var dictionaryInput;
    var dictionaryResult;
    var currentPopover = null;
    var currentModal = null;
    var trackedTooltipTarget = null;
    var smartContrastNodes = [];
    var scaledFontNodes = [];
    var pointerY = Math.round(window.innerHeight / 2);
    var structureIndex = {};
    var state = loadState();

    var profilePresets = {
        motor: {
            activeProfile: 'motor',
            highlightLinks: true,
            readingGuide: true,
            bigCursor: true,
            focusHighlight: true,
            lineHeight: 1
        },
        blind: {
            activeProfile: 'blind',
            screenReader: true,
            highlightLinks: true,
            focusHighlight: true
        },
        color: {
            activeProfile: 'color',
            smartContrast: true,
            saturation: 'low',
            highlightLinks: true
        },
        dyslexia: {
            activeProfile: 'dyslexia',
            readable: true,
            textSpacing: 2,
            lineHeight: 2
        },
        vision: {
            activeProfile: 'vision',
            fontScale: 3,
            contrastMode: 'dark',
            highlightLinks: true,
            lineHeight: 2,
            tooltips: true
        },
        cognitive: {
            activeProfile: 'cognitive',
            readable: true,
            textSpacing: 1,
            lineHeight: 2,
            tooltips: true
        },
        seizure: {
            activeProfile: 'seizure',
            pauseAnim: true,
            saturation: 'low'
        },
        adhd: {
            activeProfile: 'adhd',
            readingMask: true,
            lineHeight: 2,
            pauseAnim: true,
            textAlign: 'left'
        }
    };

    function defaultState() {
        return {
            activeProfile: '',
            fontScale: 0,
            contrastMode: 'off',
            smartContrast: false,
            highlightLinks: false,
            textSpacing: 0,
            pauseAnim: false,
            hideImages: false,
            readable: false,
            readingGuide: false,
            readingMask: false,
            tooltips: false,
            pageStructure: false,
            lineHeight: 0,
            textAlign: 'off',
            dictionary: false,
            saturation: 'off',
            bigCursor: false,
            focusHighlight: false,
            screenReader: false,
            widgetPosition: ''
        };
    }

    function loadState() {
        try {
            var raw = localStorage.getItem(STORAGE_KEY);
            if (raw) {
                return normalizeState(JSON.parse(raw));
            }
        } catch (e) {
            // Ignore private browsing / invalid data.
        }
        return normalizeState(defaultState());
    }

    function normalizeState(input) {
        var next = Object.assign(defaultState(), input || {});
        next.fontScale = clamp(parseInt(next.fontScale, 10) || 0, 0, 4);
        next.textSpacing = clamp(parseInt(next.textSpacing, 10) || 0, 0, 3);
        next.lineHeight = clamp(parseInt(next.lineHeight, 10) || 0, 0, 3);
        if ([ 'off', 'more', 'dark', 'light', 'invert' ].indexOf(next.contrastMode) === -1) {
            next.contrastMode = 'off';
        }
        if ([ 'off', 'low', 'high', 'mono' ].indexOf(next.saturation) === -1) {
            next.saturation = 'off';
        }
        if ([ 'off', 'left', 'center', 'right', 'justify' ].indexOf(next.textAlign) === -1) {
            next.textAlign = 'off';
        }
        if ([ '', 'left', 'right', 'hide' ].indexOf(next.widgetPosition) === -1) {
            next.widgetPosition = '';
        }
        return next;
    }

    function saveState() {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
        } catch (e) {
            // Ignore storage errors.
        }
    }

    function clamp(value, min, max) {
        return Math.max(min, Math.min(max, value));
    }

    function announce(message) {
        if (liveEl && message) {
            liveEl.textContent = '';
            window.setTimeout(function () {
                liveEl.textContent = message;
            }, 10);
        }
    }

    function clearActiveProfile() {
        state.activeProfile = '';
    }

    function resetState() {
        var widgetPosition = state.widgetPosition;
        state = defaultState();
        state.widgetPosition = widgetPosition;
    }

    function applyProfile(key) {
        if (!profilePresets[key]) {
            return;
        }
        var widgetPosition = state.widgetPosition;
        if (state.activeProfile === key) {
            resetState();
        } else {
            state = Object.assign(defaultState(), profilePresets[key]);
            state.widgetPosition = widgetPosition;
        }
        applyAll();
    }

    function buildFilterValue() {
        var filters = [];
        if (state.contrastMode === 'more') {
            filters.push('contrast(1.35)');
        } else if (state.contrastMode === 'invert') {
            filters.push('invert(1)', 'hue-rotate(180deg)');
        }

        if (state.saturation === 'low') {
            filters.push('saturate(0.65)');
        } else if (state.saturation === 'high') {
            filters.push('saturate(1.45)');
        } else if (state.saturation === 'mono') {
            filters.push('grayscale(1)');
        }

        return filters.join(' ');
    }

    function setRootClass(name, on) {
        body.classList.toggle(name, !!on);
    }

    function clearSequenceClasses(prefix, values) {
        values.forEach(function (value) {
            body.classList.remove(prefix + value);
        });
    }

    function applyVisualState() {
        body.style.filter = buildFilterValue();

        setRootClass('aa-contrast-dark', state.contrastMode === 'dark');
        setRootClass('aa-contrast-light', state.contrastMode === 'light');
        setRootClass('aa-smart-contrast', state.smartContrast);
        setRootClass('aa-highlight-links', state.highlightLinks);
        setRootClass('aa-readable', state.readable);
        setRootClass('aa-hide-images', state.hideImages);
        setRootClass('aa-big-cursor', state.bigCursor);
        setRootClass('aa-pause-anim', state.pauseAnim);
        setRootClass('aa-highlight', state.focusHighlight);
        setRootClass('aa-screen-reader-active', state.screenReader);

        clearSequenceClasses('aa-spacing-', [ '1', '2', '3' ]);
        if (state.textSpacing > 0) {
            body.classList.add('aa-spacing-' + state.textSpacing);
        }

        clearSequenceClasses('aa-lineheight-', [ '1', '2', '3' ]);
        if (state.lineHeight > 0) {
            body.classList.add('aa-lineheight-' + state.lineHeight);
        }

        clearSequenceClasses('aa-align-', [ 'left', 'center', 'right', 'justify' ]);
        if (state.textAlign !== 'off') {
            body.classList.add('aa-align-' + state.textAlign);
        }
    }

    function clearFontScale() {
        scaledFontNodes.forEach(function (node) {
            if (node.dataset.aaBaseFontSize !== undefined) {
                node.style.fontSize = node.dataset.aaBaseFontSize;
                delete node.dataset.aaBaseFontSize;
            } else {
                node.style.removeProperty('font-size');
            }
        });
        scaledFontNodes = [];
    }

    function applyFontScale() {
        clearFontScale();
        if (!state.fontScale) {
            return;
        }

        var scale = 1 + (state.fontScale * 0.1);
        var selector = 'p, li, a, button, input, textarea, select, label, legend, td, th, figcaption, blockquote, h1, h2, h3, h4, h5, h6, small, strong, em, span';
        var nodes = body.querySelectorAll(selector);

        Array.prototype.forEach.call(nodes, function (node) {
            if (isInsideWidget(node)) {
                return;
            }
            if (node.children.length && node.tagName === 'SPAN') {
                return;
            }
            var computed = window.getComputedStyle(node).fontSize;
            var size = parseFloat(computed);
            if (!size || computed.indexOf('px') === -1) {
                return;
            }
            node.dataset.aaBaseFontSize = node.style.fontSize || '';
            node.style.fontSize = (size * scale).toFixed(2) + 'px';
            scaledFontNodes.push(node);
        });
    }

    function parseColor(raw) {
        if (!raw || raw === 'transparent') {
            return null;
        }
        var match = raw.match(/rgba?\(([^)]+)\)/i);
        if (!match) {
            return null;
        }
        var parts = match[1].split(',').map(function (item) {
            return parseFloat(item.trim());
        });
        if (parts.length < 3) {
            return null;
        }
        return {
            r: parts[0],
            g: parts[1],
            b: parts[2],
            a: parts.length > 3 ? parts[3] : 1
        };
    }

    function relativeLuminance(color) {
        var channels = [ color.r, color.g, color.b ].map(function (channel) {
            var normalized = channel / 255;
            return normalized <= 0.03928 ? (normalized / 12.92) : Math.pow((normalized + 0.055) / 1.055, 2.4);
        });
        return (0.2126 * channels[0]) + (0.7152 * channels[1]) + (0.0722 * channels[2]);
    }

    function contrastRatio(foreground, background) {
        var l1 = relativeLuminance(foreground);
        var l2 = relativeLuminance(background);
        var lighter = Math.max(l1, l2);
        var darker = Math.min(l1, l2);
        return (lighter + 0.05) / (darker + 0.05);
    }

    function resolveBackgroundColor(node) {
        var current = node;
        while (current && current !== document) {
            var color = parseColor(window.getComputedStyle(current).backgroundColor);
            if (color && color.a > 0) {
                return color;
            }
            current = current.parentElement;
        }
        return parseColor(window.getComputedStyle(body).backgroundColor) || { r: 255, g: 255, b: 255, a: 1 };
    }

    function clearSmartContrast() {
        smartContrastNodes.forEach(function (node) {
            if (node.dataset.aaSmartColor !== undefined) {
                node.style.color = node.dataset.aaSmartColor;
            } else {
                node.style.removeProperty('color');
            }
            node.removeAttribute('data-aa-smart-color');
        });
        smartContrastNodes = [];
    }

    function applySmartContrast() {
        clearSmartContrast();
        if (!state.smartContrast) {
            return;
        }

        var selector = 'p, li, span, a, button, label, legend, input, textarea, select, h1, h2, h3, h4, h5, h6, td, th, figcaption, blockquote';
        var nodes = body.querySelectorAll(selector);

        Array.prototype.forEach.call(nodes, function (node) {
            if (!node.textContent || !node.textContent.trim() || isInsideWidget(node)) {
                return;
            }
            var styles = window.getComputedStyle(node);
            var fg = parseColor(styles.color);
            var bg = resolveBackgroundColor(node);
            if (!fg || !bg) {
                return;
            }

            if (contrastRatio(fg, bg) >= 4.5) {
                return;
            }

            node.dataset.aaSmartColor = node.style.color || '';
            node.style.color = relativeLuminance(bg) > 0.55 ? '#111111' : '#ffffff';
            smartContrastNodes.push(node);
        });
    }

    function updateReadingOverlays() {
        if (guideEl) {
            guideEl.hidden = !state.readingGuide;
            guideEl.style.top = Math.max(16, pointerY - 12) + 'px';
        }

        if (maskTopEl && maskBottomEl) {
            var maskGap = 72;
            maskTopEl.hidden = !state.readingMask;
            maskBottomEl.hidden = !state.readingMask;
            if (state.readingMask) {
                maskTopEl.style.height = Math.max(0, pointerY - (maskGap / 2)) + 'px';
                maskBottomEl.style.top = '';
                maskBottomEl.style.height = Math.max(0, window.innerHeight - (pointerY + (maskGap / 2))) + 'px';
            }
        }
    }

    function updateWidgetPosition() {
        if (!widget || !toggleButton || !restoreButton) {
            return;
        }

        var defaultSide = window.AnchorA11y && AnchorA11y.position === 'bottom-right' ? 'right' : 'left';
        var side = state.widgetPosition === 'left' || state.widgetPosition === 'right' ? state.widgetPosition : defaultSide;
        var hidden = state.widgetPosition === 'hide';

        widget.classList.toggle('anchor-a11y-bottom-left', side === 'left');
        widget.classList.toggle('anchor-a11y-bottom-right', side === 'right');
        widget.classList.toggle('is-widget-hidden', hidden);
        toggleButton.hidden = hidden;
        restoreButton.hidden = !hidden;

        if (hidden) {
            closePanel();
        }
    }

    function updateProfileCards() {
        if (!widget) {
            return;
        }
        var cards = widget.querySelectorAll('[data-profile]');
        Array.prototype.forEach.call(cards, function (card) {
            card.classList.toggle('is-active', card.getAttribute('data-profile') === state.activeProfile);
        });
    }

    function formatMeta(key) {
        switch (key) {
            case 'screen-reader':
                return state.screenReader ? 'On' : 'Off';
            case 'contrast':
                return {
                    off: 'Off',
                    more: 'Contrast +',
                    dark: 'Dark',
                    light: 'Light',
                    invert: 'Invert'
                }[state.contrastMode];
            case 'smart-contrast':
                return state.smartContrast ? 'On' : 'Off';
            case 'highlight-links':
                return state.highlightLinks ? 'On' : 'Off';
            case 'font-size':
                return (100 + (state.fontScale * 10)) + '%';
            case 'text-spacing':
                return [ 'Off', 'Light', 'Moderate', 'Heavy' ][state.textSpacing];
            case 'pause-anim':
                return state.pauseAnim ? 'Paused' : 'Playing';
            case 'hide-images':
                return state.hideImages ? 'Enabled' : 'Off';
            case 'readable':
                return state.readable ? 'On' : 'Off';
            case 'reading-guide':
                return state.readingGuide ? 'On' : 'Off';
            case 'reading-mask':
                return state.readingMask ? 'On' : 'Off';
            case 'tooltips':
                return state.tooltips ? 'On' : 'Off';
            case 'page-structure':
                return 'Inspect';
            case 'line-height':
                return [ 'Default', 'Relaxed', 'Open', 'Loose' ][state.lineHeight];
            case 'text-align':
                return {
                    off: 'Default',
                    left: 'Left',
                    center: 'Center',
                    right: 'Right',
                    justify: 'Justify'
                }[state.textAlign];
            case 'dictionary':
                return 'Lookup';
            case 'saturation':
                return {
                    off: 'Off',
                    low: 'Low',
                    high: 'High',
                    mono: 'Mono'
                }[state.saturation];
            case 'big-cursor':
                return state.bigCursor ? 'On' : 'Off';
            case 'focus-highlight':
                return state.focusHighlight ? 'On' : 'Off';
            default:
                return '';
        }
    }

    function isActiveCard(key) {
        switch (key) {
            case 'screen-reader':
                return state.screenReader;
            case 'contrast':
                return state.contrastMode !== 'off';
            case 'smart-contrast':
                return state.smartContrast;
            case 'highlight-links':
                return state.highlightLinks;
            case 'font-size':
                return state.fontScale > 0;
            case 'text-spacing':
                return state.textSpacing > 0;
            case 'pause-anim':
                return state.pauseAnim;
            case 'hide-images':
                return state.hideImages;
            case 'readable':
                return state.readable;
            case 'reading-guide':
                return state.readingGuide;
            case 'reading-mask':
                return state.readingMask;
            case 'tooltips':
                return state.tooltips;
            case 'line-height':
                return state.lineHeight > 0;
            case 'text-align':
                return state.textAlign !== 'off';
            case 'dictionary':
                return false;
            case 'saturation':
                return state.saturation !== 'off';
            case 'big-cursor':
                return state.bigCursor;
            case 'focus-highlight':
                return state.focusHighlight;
            default:
                return false;
        }
    }

    function updateCards() {
        if (!widget) {
            return;
        }

        var metas = widget.querySelectorAll('[data-card-meta]');
        Array.prototype.forEach.call(metas, function (meta) {
            var key = meta.getAttribute('data-card-meta');
            meta.textContent = formatMeta(key);
        });

        var cards = widget.querySelectorAll('[data-card-key]');
        Array.prototype.forEach.call(cards, function (card) {
            var key = card.getAttribute('data-card-key');
            card.classList.toggle('is-active', isActiveCard(key));
        });
    }

    function applyAll(options) {
        options = options || {};
        applyVisualState();
        applyFontScale();
        applySmartContrast();
        updateReadingOverlays();
        updateWidgetPosition();
        updateCards();
        updateProfileCards();
        saveState();

        if (!state.screenReader && window.speechSynthesis) {
            window.speechSynthesis.cancel();
        }

        if (options.announce) {
            announce(options.announce);
        }
    }

    function openPanel() {
        if (!panel || !toggleButton) {
            return;
        }
        panel.hidden = false;
        toggleButton.setAttribute('aria-expanded', 'true');
        toggleButton.classList.add('is-active');
        closePopover();
        closeModal();
    }

    function closePanel() {
        if (!panel || !toggleButton) {
            return;
        }
        panel.hidden = true;
        toggleButton.setAttribute('aria-expanded', 'false');
        toggleButton.classList.remove('is-active');
        closePopover();
        closeModal();
    }

    function togglePanel() {
        if (!panel) {
            return;
        }
        if (panel.hidden) {
            openPanel();
        } else {
            closePanel();
        }
    }

    function syncOverlayVisibility() {
        if (!backdrop) {
            return;
        }
        backdrop.hidden = !currentPopover && !currentModal;
    }

    function closePopover() {
        if (currentPopover) {
            currentPopover.hidden = true;
            currentPopover = null;
        }
        syncOverlayVisibility();
    }

    function openPopover(key) {
        closePopover();
        var popover = document.getElementById('anchor-a11y-popover-' + key);
        if (!popover) {
            return;
        }
        popover.hidden = false;
        currentPopover = popover;
        syncOverlayVisibility();
    }

    function closeModal() {
        if (currentModal) {
            currentModal.hidden = true;
            currentModal = null;
        }
        syncOverlayVisibility();
    }

    function openModal(key) {
        closeModal();
        var modal = document.getElementById('anchor-a11y-modal-' + key);
        if (!modal) {
            return;
        }
        modal.hidden = false;
        currentModal = modal;
        syncOverlayVisibility();
        if (key === 'page-structure') {
            populateStructureLists();
        } else if (key === 'dictionary' && dictionaryInput) {
            dictionaryInput.focus();
        }
    }

    function cycleFeature(key) {
        clearActiveProfile();
        if (key === 'font-size') {
            state.fontScale = (state.fontScale + 1) % 5;
        }
        applyAll();
    }

    function toggleFeature(key) {
        clearActiveProfile();
        var announceText = '';

        switch (key) {
            case 'screen-reader':
                state.screenReader = !state.screenReader;
                announceText = state.screenReader
                    ? (AnchorA11y.strings && AnchorA11y.strings.screenReaderEnabled) || 'Screen reader enabled.'
                    : (AnchorA11y.strings && AnchorA11y.strings.screenReaderOff) || 'Screen reader disabled.';
                break;
            case 'smart-contrast':
                state.smartContrast = !state.smartContrast;
                break;
            case 'highlight-links':
                state.highlightLinks = !state.highlightLinks;
                break;
            case 'pause-anim':
                state.pauseAnim = !state.pauseAnim;
                break;
            case 'hide-images':
                state.hideImages = !state.hideImages;
                break;
            case 'readable':
                state.readable = !state.readable;
                break;
            case 'reading-guide':
                state.readingGuide = !state.readingGuide;
                if (state.readingGuide) {
                    state.readingMask = false;
                }
                break;
            case 'reading-mask':
                state.readingMask = !state.readingMask;
                if (state.readingMask) {
                    state.readingGuide = false;
                }
                break;
            case 'tooltips':
                state.tooltips = !state.tooltips;
                hideTooltip();
                break;
            case 'dictionary':
                openModal('dictionary');
                break;
            case 'big-cursor':
                state.bigCursor = !state.bigCursor;
                break;
            case 'focus-highlight':
                state.focusHighlight = !state.focusHighlight;
                break;
            default:
                return;
        }

        applyAll({ announce: announceText });
    }

    function setChoice(choice, value) {
        clearActiveProfile();

        switch (choice) {
            case 'contrast':
                state.contrastMode = value;
                break;
            case 'spacing':
                state.textSpacing = clamp(parseInt(value, 10) || 0, 0, 3);
                break;
            case 'line-height':
                state.lineHeight = clamp(parseInt(value, 10) || 0, 0, 3);
                break;
            case 'text-align':
                state.textAlign = value;
                break;
            case 'saturation':
                state.saturation = value;
                break;
            case 'widget-position':
                state.widgetPosition = value === 'hide' ? 'hide' : value;
                closeModal();
                break;
            default:
                return;
        }

        applyAll();
        closePopover();
    }

    function getFocusable(container) {
        return container.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
    }

    function trapFocus(e, container) {
        var focusable = getFocusable(container);
        if (!focusable.length) {
            return;
        }
        var first = focusable[0];
        var last = focusable[focusable.length - 1];
        if (e.shiftKey && document.activeElement === first) {
            e.preventDefault();
            last.focus();
        } else if (!e.shiftKey && document.activeElement === last) {
            e.preventDefault();
            first.focus();
        }
    }

    function getReadableText(node) {
        if (!node) {
            return '';
        }
        if (node.tagName === 'IMG') {
            return node.getAttribute('alt') || node.getAttribute('title') || '';
        }
        if (node.getAttribute && node.getAttribute('aria-label')) {
            return node.getAttribute('aria-label');
        }
        if (node.getAttribute && node.getAttribute('title')) {
            return node.getAttribute('title');
        }
        return (node.innerText || node.textContent || '').trim();
    }

    function speakText(text) {
        if (!text || !window.speechSynthesis) {
            return;
        }
        window.speechSynthesis.cancel();
        var utterance = new window.SpeechSynthesisUtterance(text.replace(/\s+/g, ' ').trim().slice(0, 1200));
        utterance.rate = 1;
        utterance.pitch = 1;
        window.speechSynthesis.speak(utterance);
    }

    function maybeSpeakTarget(target) {
        if (!state.screenReader || isInsideWidget(target)) {
            return;
        }
        var text = getReadableText(target);
        if (text) {
            speakText(text);
        }
    }

    function getTooltipText(target) {
        if (!target || isInsideWidget(target)) {
            return '';
        }
        if (target.tagName === 'IMG') {
            return target.getAttribute('alt') || target.getAttribute('title') || '';
        }
        if (target.getAttribute && target.getAttribute('aria-label')) {
            return target.getAttribute('aria-label');
        }
        if (target.getAttribute && target.getAttribute('title')) {
            return target.getAttribute('title');
        }
        return '';
    }

    function showTooltip(text, x, y) {
        if (!tooltipEl || !text) {
            return;
        }
        tooltipEl.textContent = text;
        tooltipEl.hidden = false;
        tooltipEl.style.left = Math.min(window.innerWidth - 280, Math.max(12, x + 14)) + 'px';
        tooltipEl.style.top = Math.min(window.innerHeight - 120, Math.max(12, y + 14)) + 'px';
    }

    function hideTooltip() {
        if (tooltipEl) {
            tooltipEl.hidden = true;
            tooltipEl.textContent = '';
        }
        trackedTooltipTarget = null;
    }

    function updateTooltipTarget(target, clientX, clientY) {
        if (!state.tooltips) {
            hideTooltip();
            return;
        }
        var text = getTooltipText(target);
        if (!text) {
            hideTooltip();
            return;
        }
        trackedTooltipTarget = target;
        showTooltip(text, clientX, clientY);
    }

    function ensureStructureId(target, prefix, index) {
        if (target.id) {
            return target.id;
        }
        var id = 'aa-structure-' + prefix + '-' + index;
        target.id = id;
        return id;
    }

    function structureItem(label, id, badge) {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'anchor-a11y-structure-item';
        button.setAttribute('data-structure-target', id);
        button.innerHTML = badge
            ? '<span class="anchor-a11y-structure-badge">' + badge + '</span><span>' + label + '</span>'
            : '<span>' + label + '</span>';
        return button;
    }

    function populateStructureLists() {
        structureIndex = {};

        var headingWrap = widget.querySelector('[data-structure-list="headings"]');
        var landmarkWrap = widget.querySelector('[data-structure-list="landmarks"]');
        var linksWrap = widget.querySelector('[data-structure-list="links"]');
        headingWrap.innerHTML = '';
        landmarkWrap.innerHTML = '';
        linksWrap.innerHTML = '';

        var headings = document.querySelectorAll('h1, h2, h3, h4, h5, h6');
        Array.prototype.forEach.call(headings, function (heading, index) {
            if (isInsideWidget(heading)) {
                return;
            }
            var label = (heading.innerText || '').trim();
            if (!label) {
                return;
            }
            var id = ensureStructureId(heading, 'heading', index);
            structureIndex[id] = heading;
            headingWrap.appendChild(structureItem(label, id, heading.tagName.toUpperCase()));
        });

        var landmarkSelector = 'header, nav, main, footer, aside, form[aria-label], section[aria-label], [role="banner"], [role="navigation"], [role="main"], [role="contentinfo"], [role="complementary"], [role="search"], [role="form"], [role="region"]';
        var landmarks = document.querySelectorAll(landmarkSelector);
        Array.prototype.forEach.call(landmarks, function (landmark, index) {
            if (isInsideWidget(landmark)) {
                return;
            }
            var tag = landmark.getAttribute('role') || landmark.tagName.toLowerCase();
            var label = landmark.getAttribute('aria-label') || landmark.getAttribute('aria-labelledby') || landmark.innerText || tag;
            label = String(label).replace(/\s+/g, ' ').trim();
            if (!label) {
                label = tag;
            }
            var id = ensureStructureId(landmark, 'landmark', index);
            structureIndex[id] = landmark;
            landmarkWrap.appendChild(structureItem(label, id, tag.toUpperCase()));
        });

        var links = document.querySelectorAll('a[href]');
        Array.prototype.forEach.call(links, function (link, index) {
            if (isInsideWidget(link)) {
                return;
            }
            var label = (link.innerText || link.getAttribute('aria-label') || link.getAttribute('title') || link.getAttribute('href') || '').replace(/\s+/g, ' ').trim();
            if (!label) {
                return;
            }
            var id = ensureStructureId(link, 'link', index);
            structureIndex[id] = link;
            linksWrap.appendChild(structureItem(label, id, 'LINK'));
        });
    }

    function switchStructureTab(tab) {
        var tabButtons = widget.querySelectorAll('[data-structure-tab]');
        Array.prototype.forEach.call(tabButtons, function (button) {
            button.classList.toggle('is-active', button.getAttribute('data-structure-tab') === tab);
        });
        var lists = widget.querySelectorAll('[data-structure-list]');
        Array.prototype.forEach.call(lists, function (list) {
            list.hidden = list.getAttribute('data-structure-list') !== tab;
        });
    }

    function scrollToStructure(id) {
        var target = structureIndex[id] || document.getElementById(id);
        if (!target) {
            return;
        }
        target.scrollIntoView({ behavior: 'smooth', block: 'center' });
        closeModal();
    }

    function formatDefinitions(data) {
        if (!Array.isArray(data) || !data.length) {
            return '<p>' + ((AnchorA11y.strings && AnchorA11y.strings.dictionaryMissing) || 'No definition found.') + '</p>';
        }

        var html = [];
        data.slice(0, 1).forEach(function (entry) {
            if (entry.phonetic) {
                html.push('<p><strong>' + escapeHtml(entry.phonetic) + '</strong></p>');
            }
            (entry.meanings || []).slice(0, 3).forEach(function (meaning) {
                html.push('<div class="anchor-a11y-definition-group">');
                html.push('<h4>' + escapeHtml(meaning.partOfSpeech || 'Meaning') + '</h4>');
                var defs = meaning.definitions || [];
                if (defs.length) {
                    html.push('<ol>');
                    defs.slice(0, 3).forEach(function (definition) {
                        html.push('<li>' + escapeHtml(definition.definition || '') + '</li>');
                    });
                    html.push('</ol>');
                }
                html.push('</div>');
            });
        });
        return html.join('');
    }

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function lookupWord(word) {
        if (!dictionaryResult) {
            return;
        }
        var cleaned = String(word || '').trim().toLowerCase();
        if (!cleaned) {
            dictionaryResult.innerHTML = '<p>' + ((AnchorA11y.strings && AnchorA11y.strings.dictionaryEmpty) || 'Enter a word to look it up.') + '</p>';
            return;
        }

        dictionaryResult.innerHTML = '<p>Loading...</p>';
        fetch('https://api.dictionaryapi.dev/api/v2/entries/en/' + encodeURIComponent(cleaned))
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('Dictionary response failed');
                }
                return response.json();
            })
            .then(function (data) {
                dictionaryResult.innerHTML = formatDefinitions(data);
            })
            .catch(function () {
                dictionaryResult.innerHTML = '<p>' + ((AnchorA11y.strings && AnchorA11y.strings.dictionaryError) || 'Dictionary lookup failed.') + '</p>';
            });
    }

    function maybeLookupSelection() {
        var selection = window.getSelection ? window.getSelection().toString().trim() : '';
        if (!currentModal || currentModal.id !== 'anchor-a11y-modal-dictionary') {
            return;
        }
        if (!selection || selection.split(/\s+/).length !== 1 || selection.length > 40) {
            return;
        }
        openModal('dictionary');
        if (dictionaryInput) {
            dictionaryInput.value = selection;
        }
        lookupWord(selection);
    }

    function isInsideWidget(node) {
        return !!(widget && node && widget.contains(node));
    }

    function onDocumentKeyDown(e) {
        if ((e.ctrlKey || e.metaKey) && !e.altKey && !e.shiftKey && String(e.key).toLowerCase() === 'u') {
            e.preventDefault();
            togglePanel();
            return;
        }

        if (e.key === 'Escape') {
            if (currentPopover) {
                closePopover();
            } else if (currentModal) {
                closeModal();
            } else if (panel && !panel.hidden) {
                closePanel();
            }
            return;
        }

        if (e.key === 'Tab') {
            if (currentModal) {
                trapFocus(e, currentModal);
            } else if (currentPopover) {
                trapFocus(e, currentPopover);
            } else if (panel && !panel.hidden) {
                trapFocus(e, panel);
            }
        }
    }

    function onDocumentClick(e) {
        if (!widget) {
            return;
        }

        if (currentPopover && !currentPopover.contains(e.target) && !e.target.closest('[data-open-panel]')) {
            closePopover();
        }

        if (panel && !panel.hidden && !widget.contains(e.target) && !currentModal && !currentPopover) {
            closePanel();
        }

        if (!isInsideWidget(e.target)) {
            maybeSpeakTarget(e.target);
        }
    }

    function onDocumentMouseMove(e) {
        pointerY = e.clientY;
        updateReadingOverlays();
        if (trackedTooltipTarget && state.tooltips) {
            showTooltip(getTooltipText(trackedTooltipTarget), e.clientX, e.clientY);
        }
    }

    function onWidgetClick(e) {
        var target = e.target.closest('[data-toggle-feature],[data-cycle-feature],[data-open-panel],[data-open-modal],[data-choice],[data-profile],[data-close-modal],[data-structure-target],[data-section-toggle],[data-action],[data-structure-tab]');
        if (!target) {
            return;
        }

        if (target.hasAttribute('data-toggle-feature')) {
            toggleFeature(target.getAttribute('data-toggle-feature'));
            return;
        }

        if (target.hasAttribute('data-cycle-feature')) {
            cycleFeature(target.getAttribute('data-cycle-feature'));
            return;
        }

        if (target.hasAttribute('data-open-panel')) {
            openPopover(target.getAttribute('data-open-panel'));
            return;
        }

        if (target.hasAttribute('data-open-modal')) {
            openModal(target.getAttribute('data-open-modal'));
            return;
        }

        if (target.hasAttribute('data-choice')) {
            setChoice(target.getAttribute('data-choice'), target.getAttribute('data-value'));
            return;
        }

        if (target.hasAttribute('data-profile')) {
            applyProfile(target.getAttribute('data-profile'));
            return;
        }

        if (target.hasAttribute('data-close-modal')) {
            closeModal();
            return;
        }

        if (target.hasAttribute('data-structure-target')) {
            scrollToStructure(target.getAttribute('data-structure-target'));
            return;
        }

        if (target.hasAttribute('data-structure-tab')) {
            switchStructureTab(target.getAttribute('data-structure-tab'));
            return;
        }

        if (target.hasAttribute('data-section-toggle')) {
            var sectionName = target.getAttribute('data-section-toggle');
            var region = document.getElementById('anchor-a11y-' + sectionName);
            if (region) {
                var expanded = target.getAttribute('aria-expanded') === 'true';
                target.setAttribute('aria-expanded', expanded ? 'false' : 'true');
                region.hidden = expanded;
            }
            return;
        }

        if (target.hasAttribute('data-action')) {
            var action = target.getAttribute('data-action');
            if (action === 'reset-all') {
                resetState();
                applyAll();
            } else if (action === 'restore-widget') {
                state.widgetPosition = '';
                applyAll();
                openPanel();
            }
        }
    }

    function onWidgetSubmit(e) {
        var form = e.target.closest('[data-dictionary-form]');
        if (!form) {
            return;
        }
        e.preventDefault();
        lookupWord(dictionaryInput ? dictionaryInput.value : '');
    }

    function init() {
        widget = document.getElementById('anchor-a11y-widget');
        if (!widget) {
            return;
        }

        panel = document.getElementById('anchor-a11y-panel');
        toggleButton = widget.querySelector('.anchor-a11y-toggle');
        restoreButton = widget.querySelector('.anchor-a11y-restore');
        backdrop = widget.querySelector('.anchor-a11y-modal-backdrop');
        tooltipEl = widget.querySelector('.anchor-a11y-tooltip');
        guideEl = widget.querySelector('.anchor-a11y-reading-guide');
        maskTopEl = widget.querySelector('.anchor-a11y-reading-mask-top');
        maskBottomEl = widget.querySelector('.anchor-a11y-reading-mask-bottom');
        liveEl = widget.querySelector('.anchor-a11y-live');
        dictionaryInput = widget.querySelector('[data-dictionary-input]');
        dictionaryResult = widget.querySelector('[data-dictionary-result]');

        root.appendChild(widget);

        toggleButton.addEventListener('click', togglePanel);
        widget.querySelector('.anchor-a11y-close').addEventListener('click', closePanel);
        widget.addEventListener('click', onWidgetClick);
        widget.addEventListener('submit', onWidgetSubmit);
        if (backdrop) {
            backdrop.addEventListener('click', function () {
                closePopover();
                closeModal();
            });
        }

        document.addEventListener('keydown', onDocumentKeyDown);
        document.addEventListener('click', onDocumentClick);
        document.addEventListener('mousemove', onDocumentMouseMove);
        document.addEventListener('mouseup', maybeLookupSelection);

        document.addEventListener('mouseover', function (e) {
            updateTooltipTarget(e.target, e.clientX, e.clientY);
        });

        document.addEventListener('mouseout', function (e) {
            if (trackedTooltipTarget && (!e.relatedTarget || !trackedTooltipTarget.contains(e.relatedTarget))) {
                hideTooltip();
            }
        });

        document.addEventListener('focusin', function (e) {
            maybeSpeakTarget(e.target);
            if (state.tooltips) {
                var rect = e.target.getBoundingClientRect();
                updateTooltipTarget(e.target, rect.left, rect.bottom);
            }
        });

        document.addEventListener('focusout', function (e) {
            if (trackedTooltipTarget === e.target) {
                hideTooltip();
            }
        });

        window.addEventListener('resize', function () {
            updateReadingOverlays();
        });

        applyAll();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
