(function ($) {
    'use strict';
    var cfg = window.AnchorConnectors || { copied: 'Copied', confirm: 'Click again to disconnect' };

    $(document).on('click', '.anchor-connector-copy', function () {
        var $btn = $(this), $in = $btn.closest('td').find('.anchor-connector-callback');
        $in.trigger('focus').trigger('select');
        var done = function () {
            var old = $btn.text();
            $btn.text(cfg.copied);
            setTimeout(function () { $btn.text(old); }, 1500);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText($in.val()).then(done, function () { document.execCommand('copy'); done(); });
        } else {
            document.execCommand('copy');
            done();
        }
    });

    // Arm-then-confirm Disconnect: first click arms, second click within 4s submits.
    $(document).on('click', '.anchor-connector-disconnect', function (e) {
        var $btn = $(this);
        if ($btn.data('armed')) { return; }
        e.preventDefault();
        var old = $btn.text();
        $btn.data('armed', true).text(cfg.confirm).addClass('button-link-delete');
        setTimeout(function () {
            $btn.data('armed', false).text(old).removeClass('button-link-delete');
        }, 4000);
    });
})(jQuery);
