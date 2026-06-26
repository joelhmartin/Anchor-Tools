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
            tiers: tiers
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
})(jQuery);
