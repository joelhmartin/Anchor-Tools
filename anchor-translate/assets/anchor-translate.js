(function($) {
    'use strict';

    var config = window.anchorTranslateConfig || {};
    var COOKIE_NAME = 'anchor_translate_lang';
    var COOKIE_DAYS = 30;
    var defaultLang = config.defaultLang || 'en';
    var preservePhrases = (config.preservePhrases || []).slice().sort(function(a, b) {
        return String(b).length - String(a).length;
    });
    var state = {
        items: null,
        currentLang: defaultLang,
        requestId: 0,
        cache: {}
    };

    function hasOwn(obj, key) {
        return Object.prototype.hasOwnProperty.call(obj, key);
    }

    function getCookie(name) {
        var match = document.cookie.match('(?:^|;\\s*)' + name + '=([^;]*)');
        return match ? decodeURIComponent(match[1]) : null;
    }

    function setCookie(name, value, days) {
        var expires = new Date();
        expires.setTime(expires.getTime() + days * 86400000);
        document.cookie = name + '=' + encodeURIComponent(value) +
            ';expires=' + expires.toUTCString() +
            ';path=/;SameSite=Lax';
    }

    function deleteCookie(name) {
        document.cookie = name + '=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=/;SameSite=Lax';
    }

    function clearLegacyGoogleCookie() {
        deleteCookie('googtrans');
        document.cookie = 'googtrans=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=/;domain=' + location.hostname + ';SameSite=Lax';
        document.cookie = 'googtrans=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=/;domain=.' + location.hostname + ';SameSite=Lax';
    }

    function escapeRegExp(value) {
        return String(value).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    function normalizeText(value) {
        return String(value || '').replace(/\s+/g, ' ').trim();
    }

    function setSwitcherLoading(isLoading) {
        $('.anchor-translate-switcher').toggleClass('is-loading', !!isLoading);
    }

    function updateSwitcherUI(code) {
        state.currentLang = code;
        $('.anchor-translate-link').removeClass('active');
        $('.anchor-translate-link[data-lang="' + code + '"]').addClass('active');
    }

    function showMessage(message, notifyUser) {
        if (!message) return;
        if (window.console && console.error) {
            console.error('[Anchor Translate] ' + message);
        }
        if (notifyUser) {
            window.alert(message);
        }
    }

    function applyExclusions() {
        $('#wpadminbar').addClass('skiptranslate notranslate');

        var selectors = config.excludeSelectors || [];
        for (var i = 0; i < selectors.length; i++) {
            try {
                $(selectors[i]).addClass('skiptranslate notranslate');
            } catch (e) {}
        }
    }

    function isExcludedNode(node) {
        if (!node) return true;

        var element = node.nodeType === Node.TEXT_NODE ? node.parentNode : node;
        if (!element || element.nodeType !== Node.ELEMENT_NODE) return true;
        if ($(element).closest('.skiptranslate, .notranslate, script, style, textarea, option').length) return true;

        return false;
    }

    function addTextNodeItems(items) {
        var walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT, null, false);
        var node;

        while ((node = walker.nextNode())) {
            if (!node.nodeValue || normalizeText(node.nodeValue) === '') continue;
            if (isExcludedNode(node)) continue;

            items.push({
                type: 'text',
                node: node,
                original: node.nodeValue
            });
        }
    }

    function addAttributeItems(items) {
        var attrs = ['placeholder', 'title', 'aria-label'];
        var elements = document.querySelectorAll('input[placeholder], textarea[placeholder], [title], [aria-label]');

        for (var i = 0; i < elements.length; i++) {
            if (isExcludedNode(elements[i])) continue;

            for (var j = 0; j < attrs.length; j++) {
                var value = elements[i].getAttribute(attrs[j]);
                if (!value || normalizeText(value) === '') continue;

                items.push({
                    type: 'attr',
                    element: elements[i],
                    attr: attrs[j],
                    original: value
                });
            }
        }
    }

    function getItems() {
        if (state.items) return state.items;

        state.items = [];
        addTextNodeItems(state.items);
        addAttributeItems(state.items);

        return state.items;
    }

    function getItemValue(item) {
        if (item.type === 'text') {
            return item.node ? item.node.nodeValue : '';
        }
        return item.element ? item.element.getAttribute(item.attr) : '';
    }

    function setItemValue(item, value) {
        if (item.type === 'text') {
            if (item.node && item.node.parentNode) {
                item.node.nodeValue = value;
            }
            return;
        }

        if (item.element && document.body && document.body.contains(item.element)) {
            item.element.setAttribute(item.attr, value);
        }
    }

    function tokenizePhrases(text) {
        var working = String(text || '');
        var replacements = [];

        for (var i = 0; i < preservePhrases.length; i++) {
            if (!preservePhrases[i]) continue;

            var regex = new RegExp(escapeRegExp(preservePhrases[i]), 'g');
            working = working.replace(regex, function(match) {
                var token = '__ANCHOR_TRANSLATE_TOKEN_' + replacements.length + '__';
                replacements.push({
                    token: token,
                    value: match
                });
                return token;
            });
        }

        return {
            text: working,
            replacements: replacements
        };
    }

    function restorePhrases(text, replacements) {
        var restored = String(text || '');

        for (var i = 0; i < replacements.length; i++) {
            restored = restored.split(replacements[i].token).join(replacements[i].value);
        }

        return restored;
    }

    function buildQueue(targetLang) {
        var items = getItems();
        var order = [];
        var payloads = [];
        var meta = {};

        for (var i = 0; i < items.length; i++) {
            var original = items[i].original;
            if (!original || normalizeText(original) === '') continue;

            var cacheKey = defaultLang + '|' + targetLang + '|' + original;
            if (hasOwn(state.cache, cacheKey)) continue;
            if (hasOwn(meta, original)) continue;

            var tokenized = tokenizePhrases(original);
            meta[original] = {
                cacheKey: cacheKey,
                replacements: tokenized.replacements
            };
            order.push(original);
            payloads.push(tokenized.text);
        }

        return {
            order: order,
            payloads: payloads,
            meta: meta
        };
    }

    function chunkTexts(texts) {
        var chunks = [];
        var current = [];
        var currentChars = 0;

        for (var i = 0; i < texts.length; i++) {
            var text = texts[i];
            var size = text.length;

            if (current.length && (current.length >= 40 || currentChars + size > 6000)) {
                chunks.push(current);
                current = [];
                currentChars = 0;
            }

            current.push(text);
            currentChars += size;
        }

        if (current.length) {
            chunks.push(current);
        }

        return chunks;
    }

    function requestChunk(texts, targetLang) {
        var params = new URLSearchParams();
        params.append('action', 'anchor_translate_translate');
        params.append('nonce', config.nonce || '');
        params.append('source', defaultLang);
        params.append('target', targetLang);

        for (var i = 0; i < texts.length; i++) {
            params.append('texts[]', texts[i]);
        }

        return $.ajax({
            url: config.ajaxUrl,
            method: 'POST',
            dataType: 'json',
            data: params.toString(),
            processData: false,
            contentType: 'application/x-www-form-urlencoded; charset=UTF-8'
        }).then(function(response) {
            if (!response || !response.success || !response.data || !$.isArray(response.data.translations)) {
                return $.Deferred().reject((response && response.data && response.data.message) || config.messages.translateFailed).promise();
            }
            return response.data.translations;
        }, function(xhr) {
            var message = config.messages.translateFailed;
            if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                message = xhr.responseJSON.data.message;
            }
            return $.Deferred().reject(message).promise();
        });
    }

    function fetchTranslations(queue, targetLang) {
        var deferred = $.Deferred();
        var chunks = chunkTexts(queue.payloads);
        var translations = [];
        var index = 0;

        function next() {
            if (index >= chunks.length) {
                deferred.resolve(translations);
                return;
            }

            requestChunk(chunks[index], targetLang).then(function(chunkTranslations) {
                translations = translations.concat(chunkTranslations);
                index++;
                next();
            }, function(message) {
                deferred.reject(message);
            });
        }

        if (!chunks.length) {
            deferred.resolve([]);
            return deferred.promise();
        }

        next();
        return deferred.promise();
    }

    function restoreOriginal() {
        var items = getItems();
        state.requestId++;
        deleteCookie(COOKIE_NAME);
        clearLegacyGoogleCookie();
        setSwitcherLoading(false);

        for (var i = 0; i < items.length; i++) {
            setItemValue(items[i], items[i].original);
        }

        updateSwitcherUI(defaultLang);
    }

    function applyTranslations(targetLang, notifyUser) {
        var requestId = ++state.requestId;
        var queue = buildQueue(targetLang);

        if (!config.hasApiKey) {
            showMessage(config.messages.missingApiKey, notifyUser);
            return $.Deferred().reject(config.messages.missingApiKey).promise();
        }

        setSwitcherLoading(true);

        return fetchTranslations(queue, targetLang).then(function(results) {
            if (requestId !== state.requestId) {
                return;
            }

            for (var i = 0; i < queue.order.length; i++) {
                var original = queue.order[i];
                var meta = queue.meta[original];
                if (!meta) continue;

                state.cache[meta.cacheKey] = restorePhrases(results[i] || '', meta.replacements);
            }

            var items = getItems();
            for (var j = 0; j < items.length; j++) {
                var cacheKey = defaultLang + '|' + targetLang + '|' + items[j].original;
                if (hasOwn(state.cache, cacheKey)) {
                    setItemValue(items[j], state.cache[cacheKey]);
                }
            }

            setCookie(COOKIE_NAME, targetLang, COOKIE_DAYS);
            updateSwitcherUI(targetLang);
        }, function(message) {
            if (requestId === state.requestId) {
                deleteCookie(COOKIE_NAME);
                showMessage(message, notifyUser);
            }
            return $.Deferred().reject(message).promise();
        }).always(function() {
            if (requestId === state.requestId) {
                setSwitcherLoading(false);
            }
        });
    }

    window.anchorTranslateSwitchLang = function(code) {
        if (!code) return false;

        if (code === defaultLang) {
            restoreOriginal();
            return false;
        }

        applyTranslations(code, true);
        return false;
    };

    $(function() {
        applyExclusions();
        clearLegacyGoogleCookie();
        updateSwitcherUI(getCookie(COOKIE_NAME) || defaultLang);

        if (!config.hasApiKey) {
            $('.anchor-translate-link').not('[data-lang="' + defaultLang + '"]').addClass('is-disabled');
            return;
        }

        var saved = getCookie(COOKIE_NAME);
        if (saved && saved !== defaultLang) {
            applyTranslations(saved, false);
        }
    });

})(jQuery);
