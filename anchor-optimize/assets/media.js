(function($) {
    'use strict';

    // Handle "Optimize Now" button in attachment detail modal.
    $(document).on('click', '.ao-optimize-btn', function(e) {
        e.preventDefault();

        var $btn    = $(this);
        var $status = $btn.siblings('.ao-optimize-status');
        var id      = $btn.data('id');

        $btn.prop('disabled', true).text('Optimizing…');
        $status.text('');

        $.post(AO_Media.ajaxUrl, {
            action:        'anchor_optimize_single',
            nonce:         AO_Media.nonce,
            attachment_id: id
        }, function(response) {
            if (response.success) {
                var d = response.data;
                var tags = [];
                if (d.has_webp) tags.push('WebP');
                if (d.has_avif) tags.push('AVIF');

                $btn.replaceWith(
                    '<span style="color:#46b450; font-weight:600;">Saved ' + d.savings_pct + '%</span>' +
                    ' <span style="color:#666;">(' + d.savings_size + ')</span>' +
                    (tags.length ? '<br><small>' + tags.join(' + ') + ' generated</small>' : '')
                );
            } else {
                $btn.prop('disabled', false).text('Optimize Now');
                $status.css('color', '#dc3232').text(response.data.message || 'Error');
            }
        }).fail(function() {
            $btn.prop('disabled', false).text('Optimize Now');
            $status.css('color', '#dc3232').text('Request failed');
        });
    });

})(jQuery);
