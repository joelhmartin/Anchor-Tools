(function ($) {
	'use strict';
	var cfg = window.anchorShipping || {};

	/**
	 * Two-step confirm without a browser dialog: the first click arms the button (its label
	 * becomes `prompt`), a second click within 4 s returns true; otherwise it disarms itself.
	 * Shared with console.js as window.anchorShippingArm.
	 */
	function arm($btn, prompt) {
		var timer = $btn.data('asArmed');
		if (timer) {
			clearTimeout(timer);
			$btn.removeData('asArmed').removeClass('is-armed').text($btn.data('asLabel'));
			return true;
		}
		$btn.data('asLabel', $btn.text()).text(prompt).addClass('is-armed').attr('aria-live', 'polite');
		$btn.data('asArmed', setTimeout(function () {
			$btn.removeData('asArmed').removeClass('is-armed').text($btn.data('asLabel'));
		}, 4000));
		return false;
	}
	window.anchorShippingArm = arm;

	function failMessage(xhr) {
		var msg = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message;
		return msg || ('Request failed' + (xhr.status ? ' (HTTP ' + xhr.status + ')' : '') + '. Reload the page and try again.');
	}
	window.anchorShippingFailMessage = failMessage;

	function panelData($panel) {
		var data = { nonce: cfg.nonce, order_id: $panel.data('order') };
		$panel.find('.anchor-shipping-create').find('input, select').each(function () {
			if (this.type === 'checkbox') { if (this.checked) { data[this.name] = this.value || 1; } return; }
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
				$panel.find('.anchor-shipping-error').text(failMessage(xhr));
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
		if ($panel.hasClass('is-busy') || !arm($(this), cfg.confirmVoid || 'Click again to void')) { return; }
		send($panel, 'anchor_shipping_void_label', { nonce: cfg.nonce, id: $(this).data('id') });
	});

	$(document).on('change', '.anchor-shipping-panel select[name="box"]', toggleCustom);
	$(toggleCustom);
})(jQuery);
