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

        // Optional UX nicety: prefill the first EMPTY attendee from billing, so a
        // pre-filled attendee 1 doesn't block prefilling a later empty attendee.
        // Only the seat's own Name / Email inputs (anchor_attendees[cart][seat][name|email]):
        // an attendee question is a text input too ([fields][key]), and once
        // attendee 1's name is filled, "the first empty text input" was the
        // first question — the buyer's name landed in "How did you hear about us?".
        var $firstName = $wrap.find('input[name$="][name]"]').not('[name*="[fields]"]').filter(function () {
            return $(this).val() === '';
        }).first();
        var $firstEmail = $wrap.find('input[name$="][email]"]').not('[name*="[fields]"]').filter(function () {
            return $(this).val() === '';
        }).first();

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
