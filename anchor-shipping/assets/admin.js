(function ($) {
	'use strict';
	var cfg = window.anchorShipping || {};

	function panelData($panel) {
		var data = { nonce: cfg.nonce, order_id: $panel.data('order') };
		$panel.find('.anchor-shipping-create').find('input, select').each(function () {
			if (this.type === 'checkbox') { if (this.checked) { data[this.name] = 1; } return; }
			data[this.name] = $(this).val();
		});
		return data;
	}

	function send($panel, action, data) {
		$panel.addClass('is-busy').find('.anchor-shipping-error').text('');
		$.post(cfg.ajax, $.extend({ action: action }, data))
			.done(function (res) {
				if (res && res.success) { $panel.replaceWith(res.data.html); toggleCustom(); return; }
				$panel.find('.anchor-shipping-error').text((res && res.data && res.data.message) || 'Something went wrong.');
			})
			.fail(function (xhr) {
				var msg = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message;
				$panel.find('.anchor-shipping-error').text(msg || 'Request failed.');
			})
			.always(function () { $panel.removeClass('is-busy'); });
	}

	function toggleCustom() {
		$('.anchor-shipping-panel').each(function () {
			var custom = $(this).find('select[name="box"]').val() === 'custom';
			$(this).find('.anchor-shipping-custom').toggle(custom);
		});
	}

	$(document).on('click', '.anchor-shipping-submit', function () {
		var $panel = $(this).closest('.anchor-shipping-panel');
		if ($panel.hasClass('is-busy')) { return; }
		send($panel, 'anchor_shipping_create_label', panelData($panel));
	});

	$(document).on('click', '.anchor-shipping-void', function () {
		var $panel = $(this).closest('.anchor-shipping-panel');
		if ($panel.hasClass('is-busy') || !window.confirm(cfg.confirmVoid)) { return; }
		send($panel, 'anchor_shipping_void_label', { nonce: cfg.nonce, id: $(this).data('id') });
	});

	$(document).on('change', '.anchor-shipping-panel select[name="box"]', toggleCustom);
	$(toggleCustom);
})(jQuery);
