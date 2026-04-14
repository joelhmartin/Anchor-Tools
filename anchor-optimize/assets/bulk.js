(function($) {
    'use strict';

    var unoptimizedIds = [];
    var totalToProcess = 0;
    var processed      = 0;
    var totalSaved     = 0;
    var stopped        = false;

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
            nonce:  AO_Bulk.nonce
        }, function(response) {
            $btn.prop('disabled', false).text('Scan for Unoptimized Images');

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
                $('#ao-bulk-progress-wrap').show();
                $('#ao-bulk-progress-text').text(AO_Bulk.i18n.noImages);
                return;
            }

            unoptimizedIds = d.unoptimized_ids;
            totalToProcess = d.unoptimized;
            processed      = 0;
            totalSaved     = 0;
            stopped        = false;

            $('#ao-bulk-progress-wrap').show();
            $('#ao-bulk-start').show();
            $('#ao-bulk-stop').hide();
            $('.ao-progress-fill').css('width', '0%');
            $('#ao-bulk-progress-text').text(d.unoptimized + ' images ready to optimize.');
        }).fail(function() {
            $btn.prop('disabled', false).text('Scan for Unoptimized Images');
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
            ids:    batch
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
