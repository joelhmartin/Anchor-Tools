(function($) {
    'use strict';

    var unoptimizedIds = [];
    var totalToProcess = 0;
    var processed      = 0;
    var totalSaved     = 0;
    var stopped        = false;
    var selectedIds    = (AO_Bulk.selectedIds || []).map(function(id) { return parseInt(id, 10); }).filter(Boolean);

    function toggleResizeControls() {
        $('.ao-bulk-resize-controls').toggle($('#ao-bulk-operation').val() === 'resize');
    }

    $('#ao-bulk-operation').on('change', toggleResizeControls);
    toggleResizeControls();

    // Scan button.
    $('#ao-bulk-scan').on('click', function() {
        var $btn = $(this);
        $btn.prop('disabled', true).text(AO_Bulk.i18n.scanning);
        $('#ao-bulk-status').show();
        $('#ao-bulk-summary').empty();
        $('#ao-bulk-progress-wrap').hide();
        $('#ao-bulk-log').empty();

        $.post(AO_Bulk.ajaxUrl, {
            action: 'anchor_optimize_bulk_scan',
            nonce:  AO_Bulk.nonce,
            ids:    selectedIds
        }, function(response) {
            $btn.prop('disabled', false).text(selectedIds.length ? 'Load Selected Images' : 'Scan for Unoptimized Images');

            if (!response.success) {
                $('#ao-bulk-summary').html('<p style="color:#dc3232;">' + (response.data.message || AO_Bulk.i18n.error) + '</p>');
                return;
            }

            var d = response.data;
            $('#ao-bulk-summary').html(
                '<div class="stat-box"><span class="stat-value">' + d.total + '</span><span class="stat-label">Total Images</span></div>' +
                '<div class="stat-box"><span class="stat-value">' + d.optimized + '</span><span class="stat-label">Optimized</span></div>' +
                '<div class="stat-box"><span class="stat-value">' + d.unoptimized + '</span><span class="stat-label">Unoptimized</span></div>'
            );

            if (d.unoptimized === 0) {
                if (d.selected_mode && d.ready > 0) {
                    unoptimizedIds = d.processable_ids || [];
                    totalToProcess = d.ready;
                    processed      = 0;
                    totalSaved     = 0;
                    stopped        = false;

                    $('#ao-bulk-progress-wrap').show();
                    $('#ao-bulk-start').show();
                    $('#ao-bulk-stop').hide();
                    $('.ao-progress-fill').css('width', '0%');
                    $('#ao-bulk-progress-text').text(d.ready + ' ' + AO_Bulk.i18n.selectedReady);
                    return;
                }

                $('#ao-bulk-progress-wrap').show();
                $('#ao-bulk-progress-text').text(AO_Bulk.i18n.noImages);
                return;
            }

            unoptimizedIds = d.processable_ids || d.unoptimized_ids;
            totalToProcess = d.ready || d.unoptimized;
            processed      = 0;
            totalSaved     = 0;
            stopped        = false;

            $('#ao-bulk-progress-wrap').show();
            $('#ao-bulk-start').show();
            $('#ao-bulk-stop').hide();
            $('.ao-progress-fill').css('width', '0%');
            if (selectedIds.length) {
                $('#ao-bulk-progress-text').text((d.ready || d.unoptimized) + ' ' + AO_Bulk.i18n.selectedReady);
            } else {
                $('#ao-bulk-progress-text').text(d.unoptimized + ' images ready to optimize.');
            }
        }).fail(function() {
            $btn.prop('disabled', false).text(selectedIds.length ? 'Load Selected Images' : 'Scan for Unoptimized Images');
            $('#ao-bulk-summary').html('<p style="color:#dc3232;">' + AO_Bulk.i18n.error + '</p>');
        });
    });

    // Start button.
    $('#ao-bulk-start').on('click', function() {
        $(this).hide();
        $('#ao-bulk-stop').show();
        stopped = false;
        processBatch();
    });

    // Stop button.
    $('#ao-bulk-stop').on('click', function() {
        stopped = true;
        $(this).hide();
        $('#ao-bulk-progress-text').text('Stopped. ' + processed + ' of ' + totalToProcess + ' processed.');
    });

    function processBatch() {
        if (stopped || unoptimizedIds.length === 0) {
            if (!stopped) {
                $('#ao-bulk-stop').hide();
                $('#ao-bulk-progress-text').text(AO_Bulk.i18n.complete);
                $('.ao-progress-fill').css('width', '100%');
            }
            return;
        }

        var batch = unoptimizedIds.splice(0, AO_Bulk.batchSize);

        $.post(AO_Bulk.ajaxUrl, {
            action: 'anchor_optimize_bulk_process',
            nonce:  AO_Bulk.nonce,
            ids:    batch,
            operation: $('#ao-bulk-operation').val(),
            resize_mode: $('#ao-bulk-resize-mode').val(),
            resize_value: $('#ao-bulk-resize-value').val()
        }, function(response) {
            if (!response.success) {
                $('#ao-bulk-progress-text').text(response.data.message || AO_Bulk.i18n.error);
                return;
            }

            var results = response.data.results || [];
            for (var i = 0; i < results.length; i++) {
                var r = results[i];
                processed++;

                var logClass = r.success ? 'ao-log-success' : 'ao-log-error';
                var logText;
                if (r.success) {
                    logText = r.title + ' — saved ' + r.savings_pct + '% (' + r.savings_size + ')';
                    if (r.operation_message) logText += ' · ' + r.operation_message;
                    var tags = [];
                    if (r.has_webp) tags.push('WebP');
                    if (r.has_avif) tags.push('AVIF');
                    if (tags.length) logText += ' · ' + tags.join('+');
                    if (r.errors && r.errors.length) {
                        logText += ' <span style="color:#dc3232;">(' + r.errors.join('; ') + ')</span>';
                    }
                } else {
                    logText = 'ID ' + r.id + ' — ' + (r.message || 'Error');
                }

                $('#ao-bulk-log').prepend('<div class="ao-log-entry ' + logClass + '">' + logText + '</div>');
            }

            var pct = Math.round((processed / totalToProcess) * 100);
            $('.ao-progress-fill').css('width', pct + '%');
            $('#ao-bulk-progress-text').text(AO_Bulk.i18n.processing + ' ' + processed + ' / ' + totalToProcess + ' (' + pct + '%)');

            // Process next batch.
            processBatch();
        }).fail(function() {
            $('#ao-bulk-progress-text').text(AO_Bulk.i18n.error);
            $('#ao-bulk-stop').hide();
        });
    }

})(jQuery);
