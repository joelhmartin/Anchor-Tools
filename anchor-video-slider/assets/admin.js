(function($){
  'use strict';

  var timer;
  function refreshPreview(){
    clearTimeout(timer);
    timer = setTimeout(function(){
      var settings = {};
      // Collect all avg_ prefixed settings
      $('.avg-setting').each(function(){
        var $el = $(this);
        var name = $el.attr('name');
        if (!name || name.indexOf('avg_') !== 0) return;
        var key = name.replace('avg_','');
        if ($el.attr('type') === 'checkbox') {
          settings[key] = $el.is(':checked') ? '1' : '0';
        } else {
          settings[key] = $el.val();
        }
      });

      var $wrap = $('.avg-preview-content');
      if (!$wrap.length) return;

      $.post(AVG.ajaxUrl, {
        action: 'avg_preview',
        nonce: AVG.nonce,
        settings: settings
      }, function(res){
        if (res.success && res.data && res.data.html) {
          $wrap.html(res.data.html);
          if (window.AnchorVideoGallery && typeof window.AnchorVideoGallery.init === 'function') {
            window.AnchorVideoGallery.init($wrap);
          }
        }
      });
    }, 500);
  }

  // Conditional field visibility based on layout
  function updateVisibility(){
    var layout = $('#avg_layout').val() || 'slider';
    $('.avg-show-for').each(function(){
      var show = $(this).data('show-for') || '';
      $(this).toggle(show.split(',').indexOf(layout) !== -1);
    });
  }

  // Disable aspect ratio dropdown when cinematic tile style is selected
  function updateAspectRatioState(){
    var tileStyle = $('#avg_tile_style').val() || 'card';
    var $aspectRatio = $('#avg_thumb_aspect_ratio');
    var $row = $aspectRatio.closest('.avg-setting-row');

    if (tileStyle === 'cinematic') {
      $aspectRatio.prop('disabled', true);
      if (!$row.find('.avg-cinematic-note').length) {
        $row.append('<span class="avg-cinematic-note" style="display:block; font-size:11px; color:#666; margin-top:4px;">Cinematic tile style uses fixed 2.35:1 ratio</span>');
      }
    } else {
      $aspectRatio.prop('disabled', false);
      $row.find('.avg-cinematic-note').remove();
    }
  }

  // Add a video row
  function addVideoRow(url, title){
    var idx = $('#avg-video-list .avg-video-row').length;
    var html = '<div class="avg-video-row" data-index="'+ idx +'">'
      + '<input type="url" name="avg_videos['+ idx +'][url]" value="'+ (url||'') +'" placeholder="https://youtube.com/watch?v=..." class="avg-video-url" />'
      + '<input type="text" name="avg_videos['+ idx +'][title]" value="'+ (title||'') +'" placeholder="Optional title" class="avg-video-title" />'
      + '<button type="button" class="button avg-remove-video">&times;</button>'
      + '</div>';
    $('#avg-video-list').append(html);
  }

  $(function(){
    // Settings change -> refresh preview
    $(document).on('change input', '.avg-setting', function(){
      refreshPreview();
      updateVisibility();
      updateAspectRatioState();
    });

    // Layout visibility on load
    updateVisibility();
    updateAspectRatioState();

    // Add video
    $('#avg-add-video').on('click', function(){
      addVideoRow('','');
    });

    // Remove video
    $(document).on('click', '.avg-remove-video', function(){
      $(this).closest('.avg-video-row').remove();
      if ($('#avg-video-list .avg-video-row').length === 0) {
        addVideoRow('','');
      }
    });

    // Bulk import
    $('#avg-bulk-import').on('click', function(){
      var text = $('#avg-bulk-urls').val();
      if (!text.trim()) return;
      var lines = text.split(/[\r\n]+/);
      var added = 0;

      // Remove single empty row
      var $rows = $('#avg-video-list .avg-video-row');
      if ($rows.length === 1 && !$rows.first().find('.avg-video-url').val().trim()) {
        $rows.first().remove();
      }

      $.each(lines, function(_, line){
        line = line.trim();
        if (!line) return;
        // Basic YouTube/Vimeo check
        if (/youtu\.?be|vimeo\.com/.test(line)) {
          addVideoRow(line, '');
          added++;
        }
      });

      if (added > 0) {
        $('#avg-bulk-urls').val('');
      }
    });

    // Initial preview
    refreshPreview();
  });

})(jQuery);
