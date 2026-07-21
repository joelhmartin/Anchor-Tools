/**
 * Anchor Webinars — inline gate (sign in / register).
 * Submits the gated-webinar login or register form via AJAX; on success reloads
 * the same URL so the page re-renders with the player. Never navigates away.
 */
(function () {
    if (!window.ANCHOR_WEBINAR_LOGIN) { return; }

    var cfg = window.ANCHOR_WEBINAR_LOGIN;
    var gate = document.querySelector('.anchor-webinar-gate--login');
    if (!gate) { return; }

    // --- Cloudflare Turnstile (register form) ---
    // Rendered explicitly rather than via the auto-render class, because the
    // register panel starts hidden (display:none) and Turnstile's implicit
    // render is unreliable inside hidden containers.
    var captchaEl = gate.querySelector('.anchor-webinar-register__captcha');
    var captchaId = null;

    function renderCaptcha() {
        if (!captchaEl || captchaId !== null || !window.turnstile) { return; }
        try {
            captchaId = window.turnstile.render(captchaEl, {
                sitekey: captchaEl.getAttribute('data-sitekey')
            });
        } catch (e) { /* not ready yet */ }
    }

    function resetCaptcha() {
        // A Turnstile token is single-use; reset the widget after a failure.
        if (captchaId !== null && window.turnstile) {
            try { window.turnstile.reset(captchaId); } catch (e) {}
        }
    }

    if (captchaEl) {
        renderCaptcha();
        // api.js may still be loading; retry shortly and once more if needed.
        if (captchaId === null) {
            setTimeout(renderCaptcha, 400);
            setTimeout(renderCaptcha, 1200);
        }
    }

    // --- Tab switching (Sign In / Register) ---
    var tabs = gate.querySelectorAll('[data-awtab]');
    var panels = gate.querySelectorAll('[data-awpanel]');

    function activate(name) {
        Array.prototype.forEach.call(tabs, function (t) {
            var on = t.getAttribute('data-awtab') === name;
            t.classList.toggle('is-active', on);
            t.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        Array.prototype.forEach.call(panels, function (p) {
            p.classList.toggle('is-active', p.getAttribute('data-awpanel') === name);
        });
        if (name === 'register') { renderCaptcha(); }
    }

    Array.prototype.forEach.call(tabs, function (t) {
        t.addEventListener('click', function () { activate(t.getAttribute('data-awtab')); });
    });
    Array.prototype.forEach.call(gate.querySelectorAll('[data-awtab-link]'), function (a) {
        a.addEventListener('click', function (e) {
            e.preventDefault();
            activate(a.getAttribute('data-awtab-link'));
        });
    });

    // --- Shared AJAX form wiring ---
    function wire(form, opts) {
        if (!form) { return; }

        var errorBox = form.querySelector(opts.errorSel);
        var submit = form.querySelector(opts.submitSel);
        var submitLabel = submit ? submit.textContent : '';

        function showError(message) {
            if (!errorBox) { return; }
            errorBox.textContent = message;
            errorBox.hidden = false;
        }
        function clearError() {
            if (!errorBox) { return; }
            errorBox.textContent = '';
            errorBox.hidden = true;
        }
        function setBusy(busy) {
            if (!submit) { return; }
            submit.disabled = busy;
            submit.textContent = busy ? opts.busyLabel : submitLabel;
        }

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            clearError();
            setBusy(true);

            var payload = new FormData();
            payload.append('action', opts.action);
            payload.append('nonce', cfg.nonce);
            opts.fields.forEach(function (name) {
                var el = form.querySelector('[name="' + name + '"]');
                if (!el) { return; }
                if (el.type === 'checkbox') {
                    if (el.checked) { payload.append(name, '1'); }
                } else {
                    payload.append(name, el.value || '');
                }
            });

            fetch(cfg.ajaxUrl, {
                method: 'POST',
                body: payload,
                credentials: 'same-origin'
            })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res && res.success) {
                        window.location.reload();
                        return;
                    }
                    setBusy(false);
                    if (opts.resetCaptcha) { resetCaptcha(); }
                    showError((res && res.data && res.data.message) || opts.failMsg);
                })
                .catch(function () {
                    setBusy(false);
                    if (opts.resetCaptcha) { resetCaptcha(); }
                    showError('Something went wrong. Please try again.');
                });
        });
    }

    wire(gate.querySelector('.anchor-webinar-login__form'), {
        action: 'anchor_webinar_login',
        fields: ['log', 'pwd', 'rememberme'],
        errorSel: '.anchor-webinar-login__error',
        submitSel: '.anchor-webinar-login__submit',
        busyLabel: 'Signing in…',
        failMsg: 'Sign in failed. Please try again.'
    });

    wire(gate.querySelector('.anchor-webinar-register__form'), {
        action: 'anchor_webinar_register',
        fields: ['name', 'email', 'pwd', 'website', 'cf-turnstile-response'],
        errorSel: '.anchor-webinar-register__error',
        submitSel: '.anchor-webinar-register__submit',
        busyLabel: 'Creating account…',
        failMsg: 'Registration failed. Please try again.',
        resetCaptcha: true
    });
})();
