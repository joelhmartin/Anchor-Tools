/**
 * Anchor Compliance — settings screen.
 * Enqueued only on the Compliance tab (anchor_settings_enqueue_compliance).
 */
(function ($) {
	'use strict';

	$(function () {
		initColorPickers();
		initPagePickers();
		initLogoPicker();
		initCustomRulesRepeater();
	});

	/** Appearance section: wp-color-picker on the three color fields. */
	function initColorPickers() {
		if ($.fn.wpColorPicker) {
			$('.anchor-cmp-color-field').wpColorPicker();
		}
	}

	/**
	 * General section: selecting a published page fills the paired URL
	 * text input with that page's permalink (read from the option's
	 * data-url attribute, so no extra AJAX round trip is needed).
	 */
	function initPagePickers() {
		$(document).on('change', '.anchor-cmp-page-picker', function () {
			var $select = $(this);
			var url = $select.find('option:selected').attr('data-url') || '';
			var targetId = $select.data('target');
			if (url && targetId) {
				$('#' + targetId).val(url);
			}
		});
	}

	/** Appearance section: the WP media frame for the logo attachment. */
	function initLogoPicker() {
		var frame = null;

		$(document).on('click', '.anchor-cmp-logo-select', function (e) {
			e.preventDefault();

			if (!window.wp || !wp.media) {
				return;
			}

			if (!frame) {
				frame = wp.media({
					title: 'Select Logo',
					button: { text: 'Use this logo' },
					multiple: false,
					library: { type: 'image' }
				});

				frame.on('select', function () {
					var attachment = frame.state().get('selection').first().toJSON();
					var thumbUrl = (attachment.sizes && attachment.sizes.thumbnail)
						? attachment.sizes.thumbnail.url
						: attachment.url;

					$('#anchor_cmp_logo_id').val(attachment.id);
					$('#anchor_cmp_logo_preview').html(
						$('<img />').attr('src', thumbUrl).attr('alt', '')
					);
				});
			}

			frame.open();
		});

		$(document).on('click', '.anchor-cmp-logo-remove', function (e) {
			e.preventDefault();
			$('#anchor_cmp_logo_id').val(0);
			$('#anchor_cmp_logo_preview').empty();
		});
	}

	/**
	 * Custom Rules section: an add/remove repeater. Rows are indexed off
	 * the current count of rows already in the DOM, so every submitted
	 * row (existing or freshly added) has a distinct
	 * custom_rules[N][...] name the sanitizer's foreach can walk.
	 */
	function initCustomRulesRepeater() {
		var $container = $('#anchor-cmp-custom-rules');
		var $template = $('#anchor-cmp-rule-template');

		if (!$container.length || !$template.length) {
			return;
		}

		$(document).on('click', '#anchor-cmp-rule-add', function (e) {
			e.preventDefault();

			var nextIndex = $container.find('.anchor-cmp-rule-row').length;
			var html = $template.html().split('__INDEX__').join(String(nextIndex));

			$container.append(html);
		});

		$(document).on('click', '.anchor-cmp-rule-remove', function (e) {
			e.preventDefault();
			$(this).closest('.anchor-cmp-rule-row').remove();
		});
	}
})(jQuery);
