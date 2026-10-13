(function ($) {
	'use strict';
	var cfg = window.anchorShipping || {};
	var txt = window.anchorShippingConsole || {};

	$(document).on('click', '.asm-status-btn', function () {
		var $btn = $(this), $box = $btn.closest('.asm-actions'), to = $btn.data('to');
		if ($box.hasClass('is-busy')) { return; }
		if (!window.confirm(to === 'cancelled' ? txt.confirmCancel : txt.confirmDone)) { return; }
		$box.addClass('is-busy').find('.asm-status-error').text('');
		$.post(cfg.ajax, { action: 'anchor_shipping_console_status', nonce: cfg.nonce, order_id: $box.data('order'), to: to })
			.done(function (res) {
				if (res && res.success) { window.location.reload(); return; }
				$box.removeClass('is-busy').find('.asm-status-error').text((res && res.data && res.data.message) || 'Something went wrong.');
			})
			.fail(function (xhr) {
				var msg = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message;
				$box.removeClass('is-busy').find('.asm-status-error').text(msg || 'Request failed.');
			});
	});
})(jQuery);
