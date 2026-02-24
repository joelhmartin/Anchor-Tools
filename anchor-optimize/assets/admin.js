(function($) {
    'use strict';

    // Sync range sliders with number inputs.
    $('.anchor-optimize-quality-slider').each(function() {
        var $wrap  = $(this);
        var $range = $wrap.find('input[type="range"]');
        var $num   = $wrap.find('input[type="number"]');

        $range.on('input', function() {
            $num.val(this.value);
        });
        $num.on('input', function() {
            $range.val(this.value);
        });
    });

    // Show/hide custom quality fields based on compression mode.
    var $mode = $('#ao-mode');
    function toggleCustomFields() {
        var isCustom = $mode.val() === 'custom';
        // Quality and PNG quality rows.
        $('#ao-quality, #ao-png-quality').closest('tr').toggle(isCustom);
    }
    $mode.on('change', toggleCustomFields);
    toggleCustomFields();

    // Show/hide WebP quality when WebP is toggled off.
    var $webp = $('#ao-webp-enabled');
    function toggleWebpQuality() {
        $('#ao-webp-quality').closest('tr').toggle($webp.is(':checked'));
    }
    $webp.on('change', toggleWebpQuality);
    toggleWebpQuality();

    // Show/hide AVIF quality when AVIF is toggled off.
    var $avif = $('#ao-avif-enabled');
    function toggleAvifQuality() {
        $('#ao-avif-quality').closest('tr').toggle($avif.is(':checked'));
    }
    $avif.on('change', toggleAvifQuality);
    toggleAvifQuality();

})(jQuery);
