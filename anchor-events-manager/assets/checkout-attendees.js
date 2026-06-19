/**
 * Anchor Events — checkout attendee capture (Phase 2).
 *
 * Minimal helper for the per-seat attendee fields rendered into the classic
 * WooCommerce checkout. The fields live in the customer-details column, which is
 * not replaced by the `updated_checkout` AJAX pass, but we re-bind defensively in
 * case a theme/extension re-renders them.
 */
(function ($) {
    'use strict';

    function bind() {
        var $wrap = $('#anchor-event-attendees');
        if (!$wrap.length) {
            return;
        }

        // Optional UX nicety: prefill the very first empty attendee from billing.
        var $firstName = $wrap.find('input[type="text"]').first();
        var $firstEmail = $wrap.find('input[type="email"]').first();

        if ($firstName.length && $firstName.val() === '') {
            var bFirst = $('#billing_first_name').val() || '';
            var bLast = $('#billing_last_name').val() || '';
            var full = $.trim(bFirst + ' ' + bLast);
            if (full !== '') {
                $firstName.val(full);
            }
        }
        if ($firstEmail.length && $firstEmail.val() === '') {
            var bEmail = $('#billing_email').val() || '';
            if (bEmail !== '') {
                $firstEmail.val(bEmail);
            }
        }
    }

    $(document.body).on('updated_checkout', bind);
    $(bind);
})(jQuery);
