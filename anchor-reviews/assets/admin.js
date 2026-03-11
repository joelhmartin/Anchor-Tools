(function($) {
    'use strict';

    // Show/hide setting rows based on layout selection.
    var $layout = $('select[name="ard_layout"]');
    if ($layout.length) {
        function toggleLayoutFields() {
            var val = $layout.val();
            $('.ard-setting-row[data-show-for-layout]').each(function() {
                var allowed = $(this).data('show-for-layout').split(',');
                $(this).toggle(allowed.indexOf(val) !== -1);
            });
        }
        $layout.on('change', toggleLayoutFields);
        toggleLayoutFields();
    }
})(jQuery);
