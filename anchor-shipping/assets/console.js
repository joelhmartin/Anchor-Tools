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
	// Bulk labels: select-all on the Needs label tab, and the confirm form's AJAX submit.
	$(document).on('change', '.asm-bulk-all', function () {
		$(this).closest('table').find('.asm-bulk-pick').prop('checked', this.checked);
	});
	$(document).on('submit', '.asm-bulk-select', function (e) {
		if (!$(this).find('.asm-bulk-pick:checked').length) { e.preventDefault(); }
	});
	function syncCustom($row) { $row.find('.asm-bulk-custom').toggle($row.find('[data-field="box"]').val() === 'custom'); }
	$('.asm-bulk-row').each(function () { syncCustom($(this)); });
	$(document).on('change', '.asm-bulk-row [data-field="box"]', function () { syncCustom($(this).closest('.asm-bulk-row')); });
	// Labels are bought in chunks (server limit per request) so no single request outlives PHP/proxy timeouts;
	// the last request merges every chunk's labels into one file and returns the combined result screen.
	var BULK_CHUNK = 10;
	function bulkMessage(xhr, res, fallback) {
		var d = (xhr && xhr.responseJSON && xhr.responseJSON.data) || (res && res.data);
		return (d && d.message) || fallback;
	}
	function runBulk($form, $msg, rows) {
		var acc = { created: {}, skipped: {}, failed: {} }, done = 0, fatal = '';
		function finish() {
			$msg.text(txt.working || 'Creating labels...');
			$.post(cfg.ajax, { action: 'anchor_shipping_bulk_finish', nonce: cfg.nonce, created: acc.created, skipped: acc.skipped, failed: acc.failed })
				.done(function (res) {
					if (res && res.success) { $form.closest('.asm-bulk').replaceWith(res.data.html); return; }
					$form.removeClass('is-busy'); $msg.text(bulkMessage(null, res, 'Something went wrong.'));
				})
				.fail(function (xhr) {
					$form.removeClass('is-busy');
					$msg.text(bulkMessage(xhr, null, 'Could not combine the labels. They were created: print them from each order.'));
				});
		}
		function next() {
			if (fatal || done >= rows.length) { finish(); return; }
			var chunk = rows.slice(done, done + BULK_CHUNK);
			$msg.text((txt.working || 'Creating labels...') + ' ' + done + '/' + rows.length);
			$.post(cfg.ajax, { action: 'anchor_shipping_bulk_create', nonce: cfg.nonce, chunk: 1, rows: chunk })
				.done(function (res) {
					if (res && res.success) {
						$.each(['created', 'skipped', 'failed'], function (_, k) { $.extend(acc[k], res.data[k] || {}); });
						done += chunk.length; next(); return;
					}
					stop(chunk, bulkMessage(null, res, 'Something went wrong.'));
				})
				.fail(function (xhr) {
					stop(chunk, bulkMessage(xhr, null, 'Request failed. Check this order before retrying: a label may have been created.'));
				});
		}
		// A chunk we could not read back stops the run; its orders are reported, earlier chunks are still merged.
		function stop(chunk, why) {
			fatal = why;
			$.each(chunk, function (_, r) { acc.failed[r.order_id] = why; });
			$.each(rows.slice(done + chunk.length), function (_, r) { acc.failed[r.order_id] = 'Not attempted.'; });
			finish();
		}
		next();
	}
	$(document).on('submit', '.asm-bulk-form', function (e) {
		e.preventDefault();
		var $form = $(this), $msg = $form.find('.asm-bulk-msg'), rows = [];
		if ($form.hasClass('is-busy')) { return; }
		$form.find('.asm-bulk-row:not(.is-blocked)').each(function () {
			var $r = $(this), row = { order_id: $r.data('order-id') };
			$r.find('[data-field]').each(function () { row[$(this).data('field')] = $(this).val(); });
			rows.push(row);
		});
		if (!window.confirm((txt.confirmBulk || 'Create %d labels? Each one is billed by the carrier.').replace('%d', rows.length))) { return; }
		$form.addClass('is-busy');
		runBulk($form, $msg, rows);
	});

})(jQuery);
