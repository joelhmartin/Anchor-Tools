(function ($) {
	'use strict';
	var cfg = window.anchorShipping || {};
	var txt = window.anchorShippingConsole || {};

	$(document).on('click', '.asm-print-btn', function () {
		var frame = $(this).closest('.asm-label').find('.asm-label-frame')[0];
		try { frame.contentWindow.focus(); frame.contentWindow.print(); }
		catch (e) { window.open($(this).siblings('.asm-print').attr('href'), '_blank'); }
	});

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

	// Products: stock quantity applies when tracking is on, stock status when it is off.
	function syncStock($cell) {
		var on = $cell.find('[data-field="manage_stock"]').prop('checked');
		$cell.toggleClass('is-managed', on);
		$cell.find('.asm-stock-qty').prop('disabled', !on);
		$cell.find('.asm-stock-status').prop('disabled', on);
	}
	$('.asm-stock').each(function () { syncStock($(this)); });
	$(document).on('change', '.asm-stock [data-field="manage_stock"]', function () { syncStock($(this).closest('.asm-stock')); });

	$(document).on('click', '.asm-product-save', function () {
		var $row = $(this).closest('.asm-product'), $msg = $row.find('.asm-product-msg');
		if ($row.hasClass('is-busy')) { return; }
		var data = { action: 'anchor_shipping_console_product_save', nonce: cfg.nonce, product_id: $row.data('product-id') };
		$row.find('[data-field]').each(function () {
			var $f = $(this), name = $f.data('field');
			data[name] = $f.is(':checkbox') ? ($f.prop('checked') ? 1 : 0) : $f.val();
		});
		$row.addClass('is-busy');
		$msg.removeClass('is-error').text('');
		$.post(cfg.ajax, data)
			.done(function (res) {
				if (res && res.success) {
					var $new = $(res.data.html);
					$row.replaceWith($new);
					$new.find('.asm-stock').each(function () { syncStock($(this)); });
					$new.find('.asm-product-msg').text(txt.saved || 'Saved.');
					return;
				}
				$row.removeClass('is-busy');
				$msg.addClass('is-error').text((res && res.data && res.data.message) || 'Something went wrong.');
			})
			.fail(function (xhr) {
				var m = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message;
				$row.removeClass('is-busy');
				$msg.addClass('is-error').text(m || 'Request failed.');
			});
	});
})(jQuery);
