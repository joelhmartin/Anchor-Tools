/**
 * Anchor Events — event-page ticket storefront (Phase 3).
 *
 * Collects the chosen per-tier quantities from the inline ticket block and POSTs
 * them to the nonce-verified add-to-cart endpoint, then renders the returned
 * messages plus a "View cart / Checkout" link into the live status region. No
 * build step — jQuery IIFE, accessible (text-only message rendering).
 */
(function ($) {
    'use strict';

    var cfg = window.AnchorEventsStore || {};

    function t(key, fallback) {
        return (cfg.i18n && cfg.i18n[key]) ? cfg.i18n[key] : fallback;
    }

    /**
     * Render a message list (and optional cart/checkout links) into $msg.
     *
     * @param {jQuery}  $msg
     * @param {Array}   messages
     * @param {boolean} success
     * @param {string}  [cartUrl]
     * @param {string}  [checkoutUrl]
     */
    function render($msg, messages, success, cartUrl, checkoutUrl) {
        if (!$msg || !$msg.length) {
            return;
        }
        $msg.empty()
            .toggleClass('anchor-event-cart-msg--error', !success)
            .toggleClass('anchor-event-cart-msg--success', !!success);

        var $list = $('<ul class="anchor-event-cart-msg-list"></ul>');
        $.each(messages || [], function (i, m) {
            $list.append($('<li></li>').text(m));
        });
        $msg.append($list);

        if (success && (cartUrl || checkoutUrl)) {
            var $links = $('<p class="anchor-event-cart-links"></p>');
            if (cartUrl) {
                $links.append(
                    $('<a class="anchor-event-button anchor-event-view-cart"></a>')
                        .attr('href', cartUrl)
                        .text(t('viewCart', 'View cart'))
                );
            }
            if (checkoutUrl) {
                if (cartUrl) {
                    $links.append(' ');
                }
                $links.append(
                    $('<a class="anchor-event-button anchor-event-checkout"></a>')
                        .attr('href', checkoutUrl)
                        .text(t('checkout', 'Checkout'))
                );
            }
            $msg.append($links);
        }
    }

    // Quantity stepper. The number input stays the single source of truth — the
    // buttons only nudge it — so the add-to-cart handler below is unaffected and
    // the field still works alone if this never binds. min/max come off the input
    // itself, which is where the seat layer already wrote the remaining-seat cap.
    $(document).on('click', '.anchor-event-qty-btn', function (e) {
        e.preventDefault();

        var $input = $(this).closest('.anchor-event-qty').find('.anchor-event-ticket-qty').first();
        if (!$input.length) {
            return;
        }

        var step = parseInt($(this).data('qty-step'), 10) || 0;
        var min = parseInt($input.attr('min'), 10);
        var max = parseInt($input.attr('max'), 10);
        var next = (parseInt($input.val(), 10) || 0) + step;

        if (!isNaN(min)) { next = Math.max(min, next); }
        if (!isNaN(max)) { next = Math.min(max, next); }

        if (next !== (parseInt($input.val(), 10) || 0)) {
            $input.val(next).trigger('change');
        }
    });

    $(document).on('click', '[data-add-to-cart]', function (e) {
        e.preventDefault();

        var $btn = $(this);
        var $block = $btn.closest('.anchor-event-tickets');
        if (!$block.length) {
            return;
        }

        var $msg = $block.find('.anchor-event-cart-msg').first();
        var eventId = $block.data('event');

        var tiers = {};
        $block.find('.anchor-event-ticket-qty').each(function () {
            var qty = parseInt($(this).val(), 10);
            var tierId = $(this).data('tier');
            if (tierId && qty > 0) {
                tiers[tierId] = (tiers[tierId] || 0) + qty;
            }
        });

        if ($.isEmptyObject(tiers)) {
            render($msg, [t('selectQty', 'Please choose at least one ticket.')], false);
            return;
        }

        $btn.prop('disabled', true).addClass('is-loading');

        $.post(cfg.ajaxUrl, {
            action: cfg.addAction || 'anchor_events_add_to_cart',
            nonce: cfg.nonce,
            event_id: eventId,
            tiers: tiers,
            plan: $block.find('[data-plan-choice] input:checked').val() || ''
        }).done(function (res) {
            if (res && res.success && res.data) {
                render($msg, res.data.messages || [], true, res.data.cart_url, res.data.checkout_url);
            } else {
                var msgs = (res && res.data && res.data.messages)
                    ? res.data.messages
                    : [t('error', 'Sorry, something went wrong. Please try again.')];
                render($msg, msgs, false);
            }
        }).fail(function () {
            render($msg, [t('error', 'Sorry, something went wrong. Please try again.')], false);
        }).always(function () {
            $btn.prop('disabled', false).removeClass('is-loading');
        });
    });

    // Payment-plan wording per ticket (Payment_Plans::render() data-plan-labels:
    // variation id => { option key => label }). With several ticket types the
    // picker starts generic ("save $750 on Dentist"); once ONE ticket is chosen
    // its own amounts replace it, and an ambiguous choice goes back to generic.
    function applyPlanLabels($fieldset, variationId) {
        var map = $fieldset.data('planLabels');
        var labels = (variationId && map && map[String(variationId)]) ? map[String(variationId)] : null;
        $fieldset.find('.anchor-event-plan-text').each(function () {
            var $text = $(this);
            if ($text.data('planDefault') === undefined) {
                $text.data('planDefault', $text.text());
            }
            var key = $text.attr('data-plan-key') || '';
            $text.text(labels && Object.prototype.hasOwnProperty.call(labels, key) ? labels[key] : $text.data('planDefault'));
        });
    }

    // WooCommerce variation forms (a theme's product form showing this picker).
    $(document).on('found_variation', 'form.variations_form', function (e, variation) {
        var id = variation && variation.variation_id;
        $(this).find('[data-plan-labels]').each(function () { applyPlanLabels($(this), id); });
    });
    $(document).on('reset_data', 'form.variations_form', function () {
        $(this).find('[data-plan-labels]').each(function () { applyPlanLabels($(this), null); });
    });

    // Ticket rows: exactly one ticket type with a quantity picks its wording.
    function syncTicketPlanLabels($block) {
        var ids = [];
        $block.find('.anchor-event-ticket-qty').each(function () {
            var id = $(this).attr('data-variation');
            if ((parseInt($(this).val(), 10) || 0) > 0 && id && ids.indexOf(id) === -1) {
                ids.push(id);
            }
        });
        var id = ids.length === 1 ? ids[0] : null;
        $block.find('[data-plan-labels]').each(function () { applyPlanLabels($(this), id); });
    }
    $(document).on('change input', '.anchor-event-tickets .anchor-event-ticket-qty', function () {
        syncTicketPlanLabels($(this).closest('.anchor-event-tickets'));
    });
    $(function () {
        $('.anchor-event-tickets').each(function () { syncTicketPlanLabels($(this)); });
    });
})(jQuery);
