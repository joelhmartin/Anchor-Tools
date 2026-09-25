/**
 * Anchor Courses - front end.
 *
 * Progressive enhancement only. Every action in this module also works as a
 * plain form post to admin-post.php, so this file must never be the only way
 * to do anything. Task 35 adds the dataLayer pushes here.
 */
(function ($) {
    'use strict';

    $(function () {
        // Prevent a double submit producing two identical POSTs. The server is
        // idempotent regardless (brief section 26); this is a UX nicety.
        $('.anchor-lesson-complete').on('submit', function () {
            $(this).find('button[type="submit"]').prop('disabled', true);
        });
    });
})(jQuery);
