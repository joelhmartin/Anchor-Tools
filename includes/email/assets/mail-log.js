/*
 * Anchor mail log: arm-then-confirm send buttons. No browser dialogs.
 *
 * A button inside `.anchor-mail-log` with `data-mail-action` (an AJAX action)
 * and `data-mail-args` (JSON) is armed by the first click and POSTs on a second
 * click within a few seconds. The response's `row_html` replaces the button's
 * row (`[data-mail-row]`), so the row updates in place.
 */
(function ($) {
	'use strict';

	var cfg = window.anchorMailLog || {};
	var i18n = cfg.i18n || {};
	var ARM_MS = 4000;

	function say($row, text, isError) {
		$row.find('.anchor-mail-log__msg').text(text || '').toggleClass('is-error', !!isError);
	}

	function disarm($btn) {
		clearTimeout($btn.data('armTimer'));
		$btn.removeData('armed').removeClass('is-armed').text($btn.data('label'));
	}

	function swap($row, html) {
		if (!html) { return $row; }
		var $new = $(html);
		$row.replaceWith($new);
		return $new;
	}

	$(document).on('click', '.anchor-mail-log [data-mail-action]', function (e) {
		e.preventDefault();
		var $btn = $(this);
		if ($btn.prop('disabled')) { return; }
		if (!$btn.data('label')) { $btn.data('label', $btn.text()); }

		if (!$btn.data('armed')) {
			$btn.data('armed', true).addClass('is-armed').text(i18n.confirm || 'Click again to send');
			$btn.data('armTimer', setTimeout(function () { disarm($btn); }, ARM_MS));
			return;
		}

		disarm($btn);
		$btn.prop('disabled', true);
		var $row = $btn.closest('[data-mail-row]');
		say($row, i18n.sending || 'Sending…');

		var args = $btn.data('mail-args');
		var payload = $.extend({}, $.isPlainObject(args) ? args : {}, {
			action: $btn.data('mail-action'),
			nonce: cfg.nonce
		});

		$.post(cfg.ajaxUrl, payload).done(function (res) {
			var data = (res && res.data) || {};
			$row = swap($row, data.row_html);
			say($row, data.message || i18n.sent || 'Sent');
		}).fail(function (xhr) {
			var data = (xhr.responseJSON && xhr.responseJSON.data) || {};
			$row = swap($row, data.row_html);
			say($row, data.message || i18n.error || 'Could not send.', true);
		}).always(function () {
			$btn.prop('disabled', false);
		});
	});
})(jQuery);
