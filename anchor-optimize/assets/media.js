(function($) {
    'use strict';

    // Enhanced handler for "Optimize Now" button.
    // The button also has an inline onclick fallback, so this is an upgrade path
    // that adds richer feedback (engine info, detailed errors).
    $(document).on('click', '.ao-optimize-btn', function(e) {
        e.preventDefault();
        // Skip if the inline handler already disabled the button (avoid double-fire).
        if (this.disabled) return;

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

                var html = '';

                // Show compression result.
                if (d.compressed && d.savings_pct > 0) {
                    html += '<span style="color:#46b450; font-weight:600;">Saved ' + d.savings_pct + '%</span>';
                    html += ' <span style="color:#666;">(' + d.savings_size + ')</span>';
                } else if (d.compressed) {
                    html += '<span style="color:#666;">Compressed (no size change)</span>';
                } else {
                    html += '<span style="color:#dc3232;">Compression failed</span>';
                }

                // Show next-gen format results.
                if (tags.length) {
                    html += '<br><small style="color:#46b450;">' + tags.join(' + ') + ' generated</small>';
                } else if (d.webp_enabled || d.avif_enabled) {
                    // Next-gen was enabled but no files were created.
                    var failed = [];
                    if (d.webp_enabled && !d.has_webp) failed.push('WebP');
                    if (d.avif_enabled && !d.has_avif) failed.push('AVIF');
                    if (failed.length) {
                        html += '<br><small style="color:#dc3232;">' + failed.join(' + ') + ' conversion failed — check server capabilities</small>';
                    }
                }

                // Show errors if any.
                if (d.errors && d.errors.length) {
                    html += '<br><small style="color:#999;">Errors: ' + d.errors.join('; ') + '</small>';
                }

                html += '<br><small style="color:#999;">Engine: ' + (d.engine || 'unknown').toUpperCase() + '</small>';

                $btn.replaceWith(html);
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
