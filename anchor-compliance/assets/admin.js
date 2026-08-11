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
		initContentResets();
	});

	/**
	 * Strings localized from PHP (wp_localize_script in
	 * Anchor_Compliance_Settings::enqueue_assets); English fallbacks only
	 * for the degenerate case of the script loading without its data.
	 */
	var l10n = window.AnchorCmpAdminL10n || {};

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
					title: l10n.selectLogo || 'Select Logo',
					button: { text: l10n.useLogo || 'Use this logo' },
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
	 * Custom Rules section: an add/remove repeater.
	 *
	 * Indexes are a monotonic counter seeded from the max data-index already
	 * in the DOM — NOT the live row count. Counting rows reissued an index
	 * after remove-middle-then-add (remove index 1 of 0..2, add => index 2
	 * again), colliding with the surviving custom_rules[2][...] names; PHP's
	 * POST parsing keeps only the later duplicate, silently dropping a rule.
	 */
	function initCustomRulesRepeater() {
		var $container = $('#anchor-cmp-custom-rules');
		var $template = $('#anchor-cmp-rule-template');
		var nextIndex = 0;

		if (!$container.length || !$template.length) {
			return;
		}

		$container.find('.anchor-cmp-rule-row').each(function () {
			var idx = parseInt($(this).attr('data-index'), 10);
			if (!isNaN(idx) && idx >= nextIndex) {
				nextIndex = idx + 1;
			}
		});

		$(document).on('click', '#anchor-cmp-rule-add', function (e) {
			e.preventDefault();

			var html = $template.html().split('__INDEX__').join(String(nextIndex));
			nextIndex++;

			$container.append(html);
		});

		$(document).on('click', '.anchor-cmp-rule-remove', function (e) {
			e.preventDefault();
			$(this).closest('.anchor-cmp-rule-row').remove();
		});
	}

	/**
	 * Content section: "Reset to default" copies the shipped default (already
	 * rendered as the field's placeholder) back into the field. Pairs with
	 * blank-stays-blank sanitize: emptying a field now means empty, so this
	 * button is the way back to the default text.
	 */
	function initContentResets() {
		$(document).on('click', '.anchor-cmp-content-reset', function (e) {
			e.preventDefault();
			var $field = $('#' + $(this).data('target'));
			$field.val($field.attr('placeholder') || '').trigger('change');
		});
	}
})(jQuery);
