/**
 * Anchor Courses - shared admin editor helpers.
 *
 * A dependency of both admin-curriculum.js (course builder) and
 * admin-quiz.js (question builder): the two editors independently need the
 * same "escape a value before it goes into a markup string" primitive, so it
 * lives here once instead of being copied into each bundle.
 */
(function ($, window) {
    'use strict';

    window.AnchorCoursesAdmin = window.AnchorCoursesAdmin || {};

    /** HTML-escape a value for interpolation into a markup string. */
    window.AnchorCoursesAdmin.esc = function (text) {
        return $('<div/>').text(text == null ? '' : String(text)).html();
    };
})(jQuery, window);
