/**
 * Anchor Webinars — edit screen.
 * Shows/hides the role checklist in the Access Control metabox based on the
 * selected access mode.
 */
(function ($) {
    'use strict';

    function toggle($wrap) {
        var mode = $wrap.find('input[name="anchor_webinar_access"]:checked').val();
        $wrap.find('.anchor-webinar-access__roles').toggle(mode === 'roles');
    }

    $(function () {
        var $wrap = $('.anchor-webinar-access');
        if (!$wrap.length) { return; }

        toggle($wrap);
        $wrap.on('change', 'input[name="anchor_webinar_access"]', function () {
            toggle($wrap);
        });
    });
})(jQuery);
