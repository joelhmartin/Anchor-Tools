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

        // The lesson page's course outline (templates/lesson-layout.php) is a
        // <details> rendered open, so it is there without JavaScript. On a
        // narrow screen it sits above the lesson, so start it collapsed; on a
        // wide one it is the sidebar (its summary hidden by frontend.css), so
        // keep it open. The breakpoint matches frontend.css.
        var $outline = $('.anchor-course-outline-toggle');
        if ($outline.length && window.matchMedia) {
            var wide = window.matchMedia('(min-width: 60rem)');
            var sync = function () {
                $outline.prop('open', wide.matches);
            };
            var wasOpen = $outline.prop('open');
            sync();
            // Collapsing it moves the lesson up, so a page opened at a
            // fragment (Mark complete returns to #anchor-lesson-footer) would
            // otherwise be left scrolled past its target.
            if (wasOpen && !$outline.prop('open') && window.location.hash.length > 1) {
                var target = document.getElementById(window.location.hash.slice(1));
                if (target) {
                    target.scrollIntoView();
                }
            }
            if (wide.addEventListener) {
                wide.addEventListener('change', sync);
            } else if (wide.addListener) {
                wide.addListener(sync);
            }
        }
    });
})(jQuery);
