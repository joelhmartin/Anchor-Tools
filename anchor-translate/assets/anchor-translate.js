(function($) {
    'use strict';

    var config = window.anchorTranslateConfig || {};
    var COOKIE_NAME = 'anchor_translate_lang';
    var COOKIE_DAYS = 30;
    var defaultLang = config.defaultLang || 'en';

    /* ---- Cookie helpers ---- */

    function getCookie(name) {
        var m = document.cookie.match('(?:^|;\\s*)' + name + '=([^;]*)');
        return m ? decodeURIComponent(m[1]) : null;
    }

    function setCookie(name, value, days) {
        var d = new Date();
        d.setTime(d.getTime() + days * 86400000);
        document.cookie = name + '=' + encodeURIComponent(value) +
            ';expires=' + d.toUTCString() +
            ';path=/;SameSite=Lax';
    }

    function deleteCookie(name) {
        document.cookie = name + '=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=/;SameSite=Lax';
    }

    /* ---- Apply skiptranslate to excluded elements ---- */

    function applyExclusions() {
        $('#wpadminbar').addClass('skiptranslate notranslate');

        var selectors = config.excludeSelectors || [];
        for (var i = 0; i < selectors.length; i++) {
            try { $(selectors[i]).addClass('skiptranslate notranslate'); } catch (e) {}
        }

        var phrases = config.preservePhrases || [];
        if (phrases.length) {
            wrapPhrases(document.body, phrases);
        }
    }

    /* ---- Wrap preserve-phrases in notranslate spans ---- */

    function wrapPhrases(root, phrases) {
        var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, null, false);
        var nodes = [];
        var node;
        while ((node = walker.nextNode())) {
            if (node.parentNode && /^(SCRIPT|STYLE|TEXTAREA|INPUT|SELECT)$/i.test(node.parentNode.tagName)) continue;
            if ($(node.parentNode).closest('.skiptranslate, .notranslate').length) continue;
            for (var i = 0; i < phrases.length; i++) {
                if (node.nodeValue && node.nodeValue.indexOf(phrases[i]) !== -1) {
                    nodes.push(node);
                    break;
                }
            }
        }

        for (var n = 0; n < nodes.length; n++) {
            var el = nodes[n];
            var html = el.nodeValue;
            for (var p = 0; p < phrases.length; p++) {
                var escaped = phrases[p].replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                html = html.replace(new RegExp(escaped, 'g'),
                    '<span class="notranslate skiptranslate">$&</span>');
            }
            if (html !== el.nodeValue) {
                var wrap = document.createElement('span');
                wrap.innerHTML = html;
                el.parentNode.replaceChild(wrap, el);
            }
        }
    }

    /* ---- Google Translate init callback ---- */

    window.anchorTranslateInit = function() {
        new google.translate.TranslateElement({
            pageLanguage: defaultLang,
            includedLanguages: (config.languageCodes || []).join(','),
            autoDisplay: false,
            layout: google.translate.TranslateElement.InlineLayout.SIMPLE
        }, 'anchor_translate_element');

        var saved = getCookie(COOKIE_NAME);
        if (saved && saved !== defaultLang) {
            triggerTranslation(saved);
        }
    };

    /* ---- Trigger translation programmatically ---- */

    function normalizeText(value) {
        return String(value || '').replace(/\s+/g, ' ').trim();
    }

    function uniquePush(list, value) {
        value = normalizeText(value);
        if (value && list.indexOf(value) === -1) {
            list.push(value);
        }
    }

    function getLanguageLabels(code) {
        var labels = [];
        var canonical = String(code || '').replace(/_/g, '-');
        var primary = canonical.split('-')[0];

        if (config.languages && config.languages[code]) {
            uniquePush(labels, config.languages[code]);
        }

        try {
            if (window.Intl && typeof Intl.DisplayNames === 'function') {
                var displayNames = new Intl.DisplayNames(['en'], { type: 'language' });
                uniquePush(labels, displayNames.of(canonical));
                if (primary !== canonical) {
                    uniquePush(labels, displayNames.of(primary));
                }
            }
        } catch (e) {}

        uniquePush(labels, primary.toUpperCase());
        uniquePush(labels, canonical.toUpperCase());

        return labels;
    }

    function getSimpleWidgetButton() {
        return document.querySelector('.VIpgJd-ZVi9od-xl07Ob-lTBxed') ||
            document.querySelector('.goog-te-gadget-simple a');
    }

    function findSimpleWidgetMenuItem(langCode) {
        var labels = getLanguageLabels(langCode);
        var frames = document.querySelectorAll('iframe');

        for (var i = 0; i < frames.length; i++) {
            var doc = null;

            try {
                doc = frames[i].contentDocument || (frames[i].contentWindow && frames[i].contentWindow.document);
            } catch (e) {
                doc = null;
            }

            if (!doc) continue;

            var items = doc.querySelectorAll('.VIpgJd-ZVi9od-vH1Gmf-ibnC6b, .VIpgJd-ZVi9od-vH1Gmf-ibnC6b-gk6SMd');
            for (var j = 0; j < items.length; j++) {
                var textNode = items[j].querySelector('.text');
                var text = normalizeText(textNode ? textNode.textContent : items[j].textContent);
                if (labels.indexOf(text) !== -1) {
                    return items[j];
                }
            }
        }

        return null;
    }

    function triggerTranslation(langCode) {
        var attempts = 0;
        var simpleWidgetOpened = false;

        var interval = setInterval(function() {
            var select = document.querySelector('.goog-te-combo');
            if (select) {
                clearInterval(interval);
                select.value = langCode;
                select.dispatchEvent(new Event('change'));
                updateSwitcherUI(langCode);
                return;
            }

            var menuItem = findSimpleWidgetMenuItem(langCode);
            if (menuItem) {
                clearInterval(interval);
                menuItem.click();
                updateSwitcherUI(langCode);
                return;
            }

            var simpleWidgetButton = getSimpleWidgetButton();
            if (simpleWidgetButton && (!simpleWidgetOpened || attempts % 10 === 0)) {
                simpleWidgetButton.click();
                simpleWidgetOpened = true;
            }

            if (++attempts > 50) clearInterval(interval);
        }, 100);
    }

    /* ---- Restore original language ---- */

    function restoreOriginal() {
        deleteCookie(COOKIE_NAME);

        // Try Google's restore mechanism via the iframe banner.
        var frame = document.querySelector('.goog-te-banner-frame');
        if (frame) {
            try {
                var btn = frame.contentDocument.querySelector('.goog-close-link');
                if (btn) { btn.click(); updateSwitcherUI(defaultLang); return; }
            } catch (e) {}
        }

        // Fallback: clear the googtrans cookie and reload.
        deleteCookie('googtrans');
        document.cookie = 'googtrans=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=/;domain=.' + location.hostname + ';SameSite=Lax';
        location.reload();
    }

    /* ---- Update switcher active state ---- */

    function updateSwitcherUI(code) {
        $('.anchor-translate-link').removeClass('active');
        $('.anchor-translate-link[data-lang="' + code + '"]').addClass('active');
    }

    /* ---- Public API for switcher clicks ---- */

    window.anchorTranslateSwitchLang = function(code) {
        if (code === defaultLang) {
            restoreOriginal();
            return;
        }
        setCookie(COOKIE_NAME, code, COOKIE_DAYS);
        triggerTranslation(code);
    };

    /* ---- DOM Ready ---- */

    $(function() {
        applyExclusions();
    });

})(jQuery);
