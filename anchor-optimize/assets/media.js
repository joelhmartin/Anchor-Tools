(function($) {
    'use strict';

    function toggleOperationControls($panel) {
        var operation = $panel.find('.ao-operation').val();
        $panel.find('.ao-resize-controls').toggle(operation === 'resize');
        $panel.find('.ao-crop-controls').toggle(operation === 'crop');
        $panel.find('.ao-save-mode').closest('p').toggle(operation !== 'optimize');
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

        $btn.prop('disabled', true).text('Working…');
        $status.text('');

        var payload = {
            action:        'anchor_optimize_single',
            nonce:         AO_Media.nonce,
            attachment_id: id,
            operation:     operation,
            save_mode:     $panel.find('.ao-save-mode').val(),
            resize_mode:   $panel.find('.ao-resize-mode').val(),
            resize_value:  $panel.find('.ao-resize-value').val(),
            crop_width:    $panel.find('.ao-crop-width').val(),
            crop_height:   $panel.find('.ao-crop-height').val(),
            crop_position: $panel.find('.ao-crop-position').val()
        };

        $.post(AO_Media.ajaxUrl, payload, function(response) {
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
