(function($){
  function getNextIndex(){
    var max = -1;
    $('#avs-sliders .avs-slider').each(function(){
      var idx = parseInt($(this).attr('data-index'), 10);
      if (!isNaN(idx)) {
        max = Math.max(max, idx);
      }
    });
    return max + 1;
  }

  function bindMediaPicker($context){
    $context.find('.avs-thumb-pick').off('click').on('click', function(e){
      e.preventDefault();
      var $field = $(this).closest('.avs-thumb-wrap').find('.avs-thumb-field');
      var frame = wp.media({
        title: (ANCHOR_VIDEO_SLIDER && ANCHOR_VIDEO_SLIDER.mediaTitle) || 'Select or upload image',
        button: { text: (ANCHOR_VIDEO_SLIDER && ANCHOR_VIDEO_SLIDER.mediaButton) || 'Use this image' },
        multiple: false
      });
      frame.on('select', function(){
        var attachment = frame.state().get('selection').first();
        if (!attachment) return;
        $field.val(attachment.id || '');
      });
      frame.open();
    });
  }

  function bindVideoRowActions($context){
    $context.find('.avs-remove-video').off('click').on('click', function(){
      var $row = $(this).closest('tr');
      var $tbody = $row.closest('tbody');
      $row.remove();
      if ($tbody.find('tr').length === 0) {
        $tbody.append($('#avs-video-template').html().replace(/__INDEX__/g, $tbody.closest('.avs-slider').attr('data-index')).replace(/__VIDX__/g, 0));
      }
      bindMediaPicker($tbody);
      bindVideoRowActions($tbody);
    });
  }

  function bindSliderActions($context){
    $context.find('.avs-add-video').off('click').on('click', function(){
      var $slider = $(this).closest('.avs-slider');
      var idx = $slider.attr('data-index');
      var $tbody = $slider.find('tbody');
      var vIdx = $tbody.find('tr').length;
      var tpl = $('#avs-video-template').html().replace(/__INDEX__/g, idx).replace(/__VIDX__/g, vIdx);
      $tbody.append(tpl);
      bindMediaPicker($slider);
      bindVideoRowActions($slider);
    });

    $context.find('.avs-remove-slider').off('click').on('click', function(){
      $(this).closest('.avs-slider').remove();
    });
  }

  $(function(){
    var $root = $('#avs-sliders');
    if (!$root.length) return;

    bindMediaPicker($root);
    bindVideoRowActions($root);
    bindSliderActions($root);

    $('#avs-add-slider').on('click', function(){
      var idx = getNextIndex();
      var tpl = $('#avs-slider-template').html().replace(/__INDEX__/g, idx);
      $root.append(tpl);
      var $newSlider = $root.find('.avs-slider').last();
      bindMediaPicker($newSlider);
      bindVideoRowActions($newSlider);
      bindSliderActions($newSlider);
    });
  });
})(jQuery);
