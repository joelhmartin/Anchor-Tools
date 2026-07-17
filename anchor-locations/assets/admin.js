(function ($) {
  'use strict';
  // Monaco auto-mounts via the shared anchor-monaco.js on .anchor-monaco[data-anchor-monaco].
  // Media picker for marker icon URL fields.
  $(document).on('click', '.al-media', function (e) {
    e.preventDefault();
    var $input = $(this);
    var frame = wp.media({ title: 'Select icon', multiple: false });
    frame.on('select', function () {
      var att = frame.state().get('selection').first().toJSON();
      $input.val(att.url).trigger('change');
    });
    frame.open();
  });
})(jQuery);
