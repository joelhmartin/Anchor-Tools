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

      var postId = parseInt($('#post_ID').val(), 10) || 0;

      // Snapshot items currently in the form so the preview reflects
      // unsaved additions/removals.
      var items = [];
      $('#avg-video-list .avg-video-row').each(function(){
        var $row = $(this);
        items.push({
          type: $row.find('.avg-item-type').val() || 'video',
          url: $row.find('.avg-video-url').val() || '',
          title: $row.find('.avg-video-title').val() || '',
          attachment_id: parseInt($row.find('.avg-attachment-id').val(), 10) || 0,
          html: $row.find('.avg-item-html').val() || ''
        });
      });

      $.post(AVG.ajaxUrl, {
        action: 'avg_preview',
        nonce: AVG.nonce,
        post_id: postId,
        settings: settings,
        items: items
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

  // Backward-compat: legacy demoted layouts force certain settings.
  // Map: legacy layout key → { setting_key: { value: <forced>, note: <user-facing note> } }
  // The renderer in PHP still honors these layouts; the editor disables the
  // forced controls so the UI cannot lie about what's active.
  var FORCED_BY_LAYOUT = {
    lightbox_grid: {
      popup_style: { value: 'lightbox', note: 'Locked by Lightbox Grid layout. Switch layout (or pick a different preset) to change.' }
    },
    paginated: {
      pagination_enabled: { value: '1', note: 'Locked by Paginated Grid layout. Switch layout to change.' }
    }
  };

  function applyForcedSettings(){
    var layout = $('#avg_layout').val() || 'slider';
    // Clear any prior forced state first
    $('.avg-setting-row').each(function(){
      var $row = $(this);
      $row.find('.avg-setting').not('#avg_layout').each(function(){
        var $el = $(this);
        if ($el.data('avgForced')) {
          $el.prop('disabled', false).removeData('avgForced');
        }
      });
      $row.find('.avg-forced-note').remove();
    });

    var forced = FORCED_BY_LAYOUT[layout];
    if (!forced) return;
    Object.keys(forced).forEach(function(key){
      var $el = $('#avg_' + key);
      if (!$el.length) return;
      var spec = forced[key];
      if ($el.attr('type') === 'checkbox') {
        $el.prop('checked', spec.value === '1' || spec.value === 1 || spec.value === true);
      } else {
        $el.val(spec.value);
      }
      $el.prop('disabled', true).data('avgForced', true);
      var $row = $el.closest('.avg-setting-row');
      if (!$row.find('.avg-forced-note').length) {
        $row.append('<span class="avg-forced-note" style="display:block; font-size:11px; color:#a26b00; margin-top:4px;">' + spec.note + '</span>');
      }
    });
  }

  // Conditional field visibility based on layout
  function updateVisibility(){
    var layout = $('#avg_layout').val() || 'slider';
    $('.avg-show-for').each(function(){
      var show = $(this).data('show-for') || '';
      $(this).toggle(show.split(',').indexOf(layout) !== -1);
    });
    applyForcedSettings();
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

  // Add an item row (video, image, or custom HTML)
  function addItemRow(type, url, title, attachmentId, thumbUrl, htmlContent){
    var idx = $('#avg-video-list .avg-video-row').length;
    type = type || 'video';

    var html = '<div class="avg-video-row" data-index="'+ idx +'">'
      + '<select name="avg_videos['+ idx +'][type]" class="avg-item-type">'
      + '<option value="video"'+ (type === 'video' ? ' selected' : '') +'>Video</option>'
      + '<option value="image"'+ (type === 'image' ? ' selected' : '') +'>Image</option>'
      + '<option value="html"'+ (type === 'html' ? ' selected' : '') +'>Custom HTML</option>'
      + '</select>'
      + '<div class="avg-video-fields"'+ (type !== 'video' ? ' style="display:none"' : '') +'>'
      + '<input type="url" name="avg_videos['+ idx +'][url]" value="'+ (url||'') +'" placeholder="https://youtube.com/watch?v=..." class="avg-video-url" />'
      + '</div>'
      + '<div class="avg-image-fields"'+ (type !== 'image' ? ' style="display:none"' : '') +'>'
      + '<input type="hidden" name="avg_videos['+ idx +'][attachment_id]" value="'+ (attachmentId||0) +'" class="avg-attachment-id" />'
      + '<button type="button" class="button avg-choose-image">Choose Image</button>'
      + (thumbUrl ? '<img src="'+ thumbUrl +'" class="avg-image-preview" />' : '')
      + '</div>'
      + '<div class="avg-html-fields"'+ (type !== 'html' ? ' style="display:none"' : '') +'>'
      + '<textarea name="avg_videos['+ idx +'][html]" class="avg-item-html" rows="3" placeholder="HTML or shortcodes (e.g. <h2>Title</h2>[anchor_reviews])">'+ escapeHtml(htmlContent||'') +'</textarea>'
      + '</div>'
      + '<input type="text" name="avg_videos['+ idx +'][title]" value="'+ (title||'') +'" placeholder="Optional title" class="avg-video-title" />'
      + '<button type="button" class="button avg-remove-video" aria-label="Remove">&times;</button>'
      + '</div>';
    $('#avg-video-list').append(html);
  }

  function escapeHtml(s){
    return $('<div>').text(s == null ? '' : String(s)).html();
  }

  // Open WP Media Library picker (single image — for per-row "Choose Image" button)
  function openMediaPicker($row) {
    var frame = wp.media({
      title: 'Choose Image',
      library: { type: 'image' },
      multiple: false,
      button: { text: 'Use Image' }
    });

    frame.on('select', function(){
      var attachment = frame.state().get('selection').first().toJSON();
      var thumbUrl = attachment.sizes && attachment.sizes.thumbnail ? attachment.sizes.thumbnail.url : attachment.url;
      $row.find('.avg-attachment-id').val(attachment.id);
      $row.find('.avg-image-preview').remove();
      $row.find('.avg-image-fields').append('<img src="'+ thumbUrl +'" class="avg-image-preview" />');
      refreshPreview();
    });

    frame.open();
  }

  // Open WP Media Library picker (multi-select — for "+ Add Image" button)
  function openBulkMediaPicker() {
    var frame = wp.media({
      title: 'Add Images',
      library: { type: 'image' },
      multiple: true,
      button: { text: 'Add Selected Images' }
    });

    frame.on('select', function(){
      var selection = frame.state().get('selection');

      // Remove single empty row before adding
      var $rows = $('#avg-video-list .avg-video-row');
      if ($rows.length === 1) {
        var $first = $rows.first();
        var type = $first.find('.avg-item-type').val();
        var hasUrl = $first.find('.avg-video-url').val().trim();
        var hasAtt = parseInt($first.find('.avg-attachment-id').val()) > 0;
        if ((type === 'video' && !hasUrl) || (type === 'image' && !hasAtt)) {
          $first.remove();
        }
      }

      selection.each(function(attachment){
        var data = attachment.toJSON();
        var thumbUrl = data.sizes && data.sizes.thumbnail ? data.sizes.thumbnail.url : data.url;
        addItemRow('image', '', '', data.id, thumbUrl);
      });
      refreshPreview();
    });

    frame.open();
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

    // Re-enable any forced (disabled) inputs on submit so their values save.
    // The PHP-side render_dispatch() still forces the rendering value, but
    // we want the stored meta to match what the user sees.
    $(document).on('submit', 'form#post', function(){
      $('.avg-setting').filter(function(){ return $(this).data('avgForced'); })
        .prop('disabled', false);
    });

    // Type toggle: show/hide video-fields vs image-fields vs html-fields
    $(document).on('change', '.avg-item-type', function(){
      var $row = $(this).closest('.avg-video-row');
      var type = $(this).val();
      $row.find('.avg-video-fields').toggle(type === 'video');
      $row.find('.avg-image-fields').toggle(type === 'image');
      $row.find('.avg-html-fields').toggle(type === 'html');
      refreshPreview();
    });

    // Refresh preview when item content (url/html/title) changes
    $(document).on('input change', '.avg-video-url, .avg-item-html, .avg-video-title', function(){
      refreshPreview();
    });

    // Add video
    $('#avg-add-video').on('click', function(){
      addItemRow('video', '', '');
      refreshPreview();
    });

    // Add image(s) via media library (multi-select)
    $('#avg-add-image').on('click', function(){
      openBulkMediaPicker();
    });

    // Add custom HTML slide
    $('#avg-add-html').on('click', function(){
      addItemRow('html', '', '');
      refreshPreview();
    });

    // Choose image via media library
    $(document).on('click', '.avg-choose-image', function(){
      var $row = $(this).closest('.avg-video-row');
      openMediaPicker($row);
    });

    // Remove item
    $(document).on('click', '.avg-remove-video', function(){
      $(this).closest('.avg-video-row').remove();
      if ($('#avg-video-list .avg-video-row').length === 0) {
        addItemRow('video', '', '');
      }
      refreshPreview();
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
          addItemRow('video', line, '');
          added++;
        }
      });

      if (added > 0) {
        $('#avg-bulk-urls').val('');
      }
    });

    // Initial preview
    refreshPreview();

    // Builder refresh button -> refresh preview.
    // builder.js dispatches this as a jQuery .trigger() event which bubbles
    // up to document, so we listen via jQuery (native addEventListener does
    // not catch jQuery custom events). We also add a native listener so a
    // future native CustomEvent dispatch would still work.
    $(document).on('anchor-builder:refresh-preview', function(){
      refreshPreview();
    });
    document.addEventListener('anchor-builder:refresh-preview', function(){
      refreshPreview();
    });
  });

})(jQuery);
