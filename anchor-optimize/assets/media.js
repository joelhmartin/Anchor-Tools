(function($) {
    'use strict';

    function toggleOperationControls($panel) {
        var operation = $panel.find('.ao-operation').val();
        $panel.find('.ao-resize-controls').toggle(operation === 'resize');
        $panel.find('.ao-replace-controls').toggle(operation === 'replace');
        $panel.find('.ao-crop-controls').toggle(operation === 'crop');
        $panel.find('.ao-save-mode').closest('p').toggle(operation === 'resize' || operation === 'crop');
    }

    $(document).on('change', '.ao-operation', function() {
        toggleOperationControls($(this).closest('.ao-media-panel'));
    });

    $(document).on('change', '.ao-save-mode', function() {
        toggleOperationControls($(this).closest('.ao-media-panel'));
    });

    $(document).on('mouseenter focus', '.ao-media-panel', function() {
        toggleOperationControls($(this));
    });

    $('.ao-media-panel').each(function() {
        toggleOperationControls($(this));
    });

    $(document).on('click', '.ao-optimize-btn', function(e) {
        e.preventDefault();
        if (this.disabled) return;

        var $btn      = $(this);
        var $panel    = $btn.closest('.ao-media-panel');
        var $status   = $btn.siblings('.ao-optimize-status');
        var $summary  = $panel.find('.ao-current-status');
        var id        = $btn.data('id');
        var operation = $panel.find('.ao-operation').val();

        if (operation === 'replace' && !$panel.find('.ao-replacement-file')[0].files.length) {
            $status.css('color', '#dc3232').text('Choose a replacement image first.');
            return;
        }

        $btn.prop('disabled', true).text('Working…');
        $status.text('');

        var payload = new FormData();
        payload.append('action', 'anchor_optimize_single');
        payload.append('nonce', AO_Media.nonce);
        payload.append('attachment_id', id);
        payload.append('operation', operation);
        payload.append('save_mode', $panel.find('.ao-save-mode').val());
        payload.append('resize_mode', $panel.find('.ao-resize-mode').val());
        payload.append('resize_value', $panel.find('.ao-resize-value').val());
        payload.append('crop_width', $panel.find('.ao-crop-width').val());
        payload.append('crop_height', $panel.find('.ao-crop-height').val());
        payload.append('crop_position', $panel.find('.ao-crop-position').val());

        if (operation === 'replace') {
            payload.append('replacement_file', $panel.find('.ao-replacement-file')[0].files[0]);
        }

        $.ajax({
            url: AO_Media.ajaxUrl,
            type: 'POST',
            data: payload,
            processData: false,
            contentType: false
        }).done(function(response) {
            if (response.success) {
                var d = response.data || {};
                if (d.status_html) {
                    $summary.html(d.status_html);
                }
                if (d.created_duplicate) {
                    $status.css('color', '#2271b1').text('Duplicate created as attachment #' + d.attachment_id + '.');
                } else {
                    $status.css('color', '#46b450').text(d.operation_message || 'Completed.');
                }
                $btn.prop('disabled', false).text('Run Image Action');
            } else {
                $btn.prop('disabled', false).text('Run Image Action');
                $status.css('color', '#dc3232').text(response.data.message || 'Error');
            }
        }).fail(function() {
            $btn.prop('disabled', false).text('Run Image Action');
            $status.css('color', '#dc3232').text('Request failed');
        });
    });

})(jQuery);
