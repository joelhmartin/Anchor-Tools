/* Anchor mail log panel: arm-then-confirm resend. No browser dialogs. */
(function ($) {
	'use strict';

	var cfg = window.anchorMailLog || {};
	var i18n = cfg.i18n || {};
	var ARM_MS = 4000;

	function say($btn, text, isError) {
		var $msg = $btn.siblings('.anchor-mail-log__msg');
		$msg.text(text || '').toggleClass('is-error', !!isError);
	}

	function disarm($btn) {
		clearTimeout($btn.data('armTimer'));
		$btn.removeData('armed').text(i18n.resend || 'Resend');
	}

	function prepend($btn, html) {
		var $body = $btn.closest('tbody');
		$body.find('.anchor-mail-log__empty').attr('hidden', true);
		if (html) {
			$body.find('.anchor-mail-log__empty').after(html);
		}
	}

	$(document).on('click', '.anchor-mail-log [data-resend]', function (e) {
		e.preventDefault();
		var $btn = $(this);
		if ($btn.prop('disabled')) { return; }

		if (!$btn.data('armed')) {
			$btn.data('armed', true).text(i18n.confirm || 'Click again to resend');
			$btn.data('armTimer', setTimeout(function () { disarm($btn); }, ARM_MS));
			return;
		}

		disarm($btn);
		$btn.prop('disabled', true);
		say($btn, i18n.sending || 'Sending…');

		$.post(cfg.ajaxUrl, {
			action: 'anchor_mail_log_resend',
			id: $btn.data('id'),
			nonce: cfg.nonce
		}).done(function (res) {
			var html = res && res.data && res.data.row_html;
			prepend($btn, html);
			say($btn, i18n.sent || 'Sent');
		}).fail(function (xhr) {
			var data = xhr.responseJSON && xhr.responseJSON.data;
			if (data && data.row_html) { prepend($btn, data.row_html); }
			say($btn, (data && data.message) || i18n.error || 'Could not resend.', true);
		}).always(function () {
			$btn.prop('disabled', false);
		});
	});
})(jQuery);
