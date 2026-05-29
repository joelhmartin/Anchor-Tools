/**
 * Anchor Webinars — inline login.
 * Submits the gated-webinar login form via AJAX; on success reloads the same
 * URL so the page re-renders with the player. Never navigates away.
 */
(function () {
    if (!window.ANCHOR_WEBINAR_LOGIN) { return; }

    var cfg = window.ANCHOR_WEBINAR_LOGIN;
    var form = document.querySelector('.anchor-webinar-login__form');
    if (!form) { return; }

    var errorBox = form.querySelector('.anchor-webinar-login__error');
    var submit = form.querySelector('.anchor-webinar-login__submit');
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
        submit.textContent = busy ? 'Signing in…' : submitLabel;
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        clearError();
        setBusy(true);

        var payload = new FormData();
        payload.append('action', 'anchor_webinar_login');
        payload.append('nonce', cfg.nonce);
        payload.append('log', (form.querySelector('[name="log"]') || {}).value || '');
        payload.append('pwd', (form.querySelector('[name="pwd"]') || {}).value || '');
        if (form.querySelector('[name="rememberme"]') && form.querySelector('[name="rememberme"]').checked) {
            payload.append('rememberme', '1');
        }

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
                showError((res && res.data && res.data.message) || 'Sign in failed. Please try again.');
            })
            .catch(function () {
                setBusy(false);
                showError('Something went wrong. Please try again.');
            });
    });
})();
