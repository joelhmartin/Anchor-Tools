console.log('[AVG] admin.js loaded', jQuery ? 'jQuery OK' : 'jQuery MISSING');
(function($){
  'use strict';

  console.log('[AVG] IIFE running, $ =', typeof $);

  // Debounce utility for preview updates
  var previewTimers = {};
  function debounce(key, fn, delay) {
    if (previewTimers[key]) {
      clearTimeout(previewTimers[key]);
    }
    previewTimers[key] = setTimeout(fn, delay);
  }

  // Collect all settings from a gallery for preview
  function collectGallerySettings($gallery) {
    var gIdx = $gallery.attr('data-index');
    var prefix = 'galleries[' + gIdx + ']';
    var settings = {};

    // Get all form values
    $gallery.find('input, select, textarea').each(function() {
      var $el = $(this);
      var name = $el.attr('name');
      if (!name || name.indexOf(prefix) !== 0) return;

      // Extract the key from the name (e.g., "galleries[0][layout]" -> "layout")
      var match = name.match(/\]\[([^\]]+)\]$/);
      if (!match) return;
      var key = match[1];

      // Handle radio buttons
      if ($el.attr('type') === 'radio') {
        if ($el.is(':checked')) {
          settings[key] = $el.val();
        }
      }
      // Handle checkboxes
      else if ($el.attr('type') === 'checkbox') {
        settings[key] = $el.is(':checked') ? '1' : '0';
      }
      // Handle other inputs
      else {
        settings[key] = $el.val();
      }
    });

    return settings;
  }

  // Refresh the preview panel
  function refreshPreview($gallery) {
    var $previewContent = $gallery.find('.avs-preview-content');
    var $previewStatus = $gallery.find('.avs-preview-status');

    if (!$previewContent.length) return;

    // Show loading state
    $previewStatus.addClass('loading').text('Updating');

    var settings = collectGallerySettings($gallery);

    $.ajax({
      url: window.ANCHOR_VIDEO_GALLERY ? ANCHOR_VIDEO_GALLERY.ajaxUrl : ajaxurl,
      type: 'POST',
      data: {
        action: 'avg_preview',
        nonce: window.ANCHOR_VIDEO_GALLERY ? ANCHOR_VIDEO_GALLERY.nonce : '',
        settings: settings
      },
      success: function(response) {
        if (response.success && response.data && response.data.html) {
          $previewContent.html(response.data.html);

          // Re-initialize the frontend JS for the preview
          if (window.AnchorVideoGallery && typeof window.AnchorVideoGallery.init === 'function') {
            window.AnchorVideoGallery.init($previewContent);
          }

          $previewStatus.removeClass('loading').text('Live Preview');
        } else {
          $previewStatus.removeClass('loading').text('Preview Error');
        }
      },
      error: function() {
        $previewStatus.removeClass('loading').text('Preview Error');
      }
    });
  }

  // Bind preview triggers for a gallery
  function bindPreviewTriggers($gallery) {
    var gIdx = $gallery.attr('data-index');

    // Listen for changes on all form elements
    $gallery.find('input, select, textarea').off('change.avgpreview input.avgpreview').on('change.avgpreview input.avgpreview', function() {
      // Debounce to prevent too many calls
      debounce('preview-' + gIdx, function() {
        refreshPreview($gallery);
      }, 500);
    });

    // Initial preview load
    setTimeout(function() {
      refreshPreview($gallery);
    }, 100);
  }

  function getNextGalleryIndex(){
    var max = -1;
    $('#avs-galleries .avs-gallery').each(function(){
      var idx = parseInt($(this).attr('data-index'), 10);
      if (!isNaN(idx)) {
        max = Math.max(max, idx);
      }
    });
    return max + 1;
  }

  function getNextVideoIndex($gallery){
    var max = -1;
    $gallery.find('.avs-video-card').each(function(){
      var idx = parseInt($(this).attr('data-video-index'), 10);
      if (!isNaN(idx)) {
        max = Math.max(max, idx);
      }
    });
    return max + 1;
  }

  function updateLayoutVisibility($gallery){
    var layout = $gallery.find('input[name*="[layout]"]:checked').val() || 'slider';
    $gallery.attr('data-layout', layout);
  }

  function updateGalleryTitle($gallery){
    var title = $gallery.find('.avs-gallery-title-input').val();
    var id = $gallery.find('.avs-gallery-id').val();
    var $titleEl = $gallery.find('.avs-gallery-header .avs-gallery-title');

    var displayTitle = title || 'New Gallery';
    var shortcode = id ? ' <code class="avs-shortcode-preview">[anchor_video_gallery id="' + id + '"]</code>' : '';

    $titleEl.html(displayTitle + shortcode);
  }

  function renumberVideos($gallery){
    $gallery.find('.avs-video-card').each(function(i){
      $(this).find('.avs-video-number').text('#' + (i + 1));
    });
  }

  // Parse video URL to check if it's a valid YouTube or Vimeo URL
  function parseVideoUrl(url) {
    url = url.trim();
    if (!url) return null;

    // YouTube patterns
    var ytMatch = url.match(/(?:youtu\.be\/|youtube\.com\/(?:watch\?v=|embed\/|shorts\/|live\/))([A-Za-z0-9_-]{11})/);
    if (ytMatch) {
      return { provider: 'youtube', id: ytMatch[1], url: url };
    }

    // Vimeo patterns
    var vmMatch = url.match(/(?:vimeo\.com\/(?:video\/|channels\/[^\/]+\/|groups\/[^\/]+\/videos\/)?)(\d+)/);
    if (vmMatch) {
      return { provider: 'vimeo', id: vmMatch[1], url: url };
    }

    return null;
  }

  // Bulk add videos from pasted URLs
  function bulkAddVideos($gallery, urlText) {
    var gIdx = $gallery.attr('data-index');
    var lines = urlText.split(/[\r\n]+/);
    var added = 0;
    var failed = 0;

    // Remove empty first video card if it exists and is empty
    var $existingCards = $gallery.find('.avs-video-card');
    if ($existingCards.length === 1) {
      var $firstCard = $existingCards.first();
      var firstUrl = $firstCard.find('.avs-video-url').val();
      if (!firstUrl || !firstUrl.trim()) {
        $firstCard.remove();
      }
    }

    lines.forEach(function(line) {
      line = line.trim();
      if (!line) return;

      var parsed = parseVideoUrl(line);
      if (parsed) {
        var vIdx = getNextVideoIndex($gallery);
        var tplHtml = $('#avs-video-template').html();
        if (!tplHtml) {
          console.error('Anchor Video Gallery: video template not found');
          return;
        }
        var tpl = tplHtml.replace(/__GIDX__/g, gIdx).replace(/__VIDX__/g, vIdx);
        var $card = $(tpl);

        // Set the URL
        $card.find('.avs-video-url').val(parsed.url);

        $gallery.find('.avs-videos-grid').append($card);
        bindVideoEvents($card, $gallery);
        added++;
      } else {
        failed++;
      }
    });

    if (added > 0) {
      renumberVideos($gallery);
      // Refresh preview
      debounce('preview-' + gIdx, function() {
        refreshPreview($gallery);
      }, 500);
    }

    return { added: added, failed: failed };
  }

  function bindMediaPicker($context){
    $context.find('.avs-thumb-pick').off('click.avg').on('click.avg', function(e){
      e.preventDefault();
      var $field = $(this).closest('.avs-thumb-wrap').find('.avs-thumb-field');
      var frame = wp.media({
        title: (window.ANCHOR_VIDEO_GALLERY && ANCHOR_VIDEO_GALLERY.mediaTitle) || 'Select or upload image',
        button: { text: (window.ANCHOR_VIDEO_GALLERY && ANCHOR_VIDEO_GALLERY.mediaButton) || 'Use this image' },
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

  function bindGalleryEvents($gallery){
    var gIdx = $gallery.attr('data-index');

    // Toggle collapse
    $gallery.find('.avs-gallery-header').off('click.avg').on('click.avg', function(e){
      if ($(e.target).closest('.avs-remove-gallery').length) return;
      $gallery.toggleClass('collapsed');
    });

    // Remove gallery
    $gallery.find('.avs-remove-gallery').off('click.avg').on('click.avg', function(e){
      e.stopPropagation();
      if (confirm('Are you sure you want to delete this gallery?')) {
        $gallery.remove();
      }
    });

    // Layout change
    $gallery.find('input[name*="[layout]"]').off('change.avg').on('change.avg', function(){
      updateLayoutVisibility($gallery);
    });
    updateLayoutVisibility($gallery);

    // Title/ID update
    $gallery.find('.avs-gallery-title-input, .avs-gallery-id').off('input.avg').on('input.avg', function(){
      updateGalleryTitle($gallery);
    });

    // Add video
    $gallery.find('.avs-add-video').off('click.avg').on('click.avg', function(){
      var vIdx = getNextVideoIndex($gallery);
      var tplHtml = $('#avs-video-template').html();
      if (!tplHtml) {
        console.error('Anchor Video Gallery: video template not found');
        return;
      }
      var tpl = tplHtml.replace(/__GIDX__/g, gIdx).replace(/__VIDX__/g, vIdx);
      var $card = $(tpl);
      $gallery.find('.avs-videos-grid').append($card);
      bindVideoEvents($card, $gallery);
      renumberVideos($gallery);
      // Refresh preview after adding video
      debounce('preview-' + gIdx, function() {
        refreshPreview($gallery);
      }, 500);
    });

    // Bind existing videos
    $gallery.find('.avs-video-card').each(function(){
      bindVideoEvents($(this), $gallery);
    });

    bindMediaPicker($gallery);

    // Bind preview triggers
    bindPreviewTriggers($gallery);
  }

  function bindVideoEvents($card, $gallery){
    var gIdx = $gallery.attr('data-index');

    // Remove video
    $card.find('.avs-remove-video').off('click.avg').on('click.avg', function(){
      $card.remove();
      renumberVideos($gallery);
      // Ensure at least one empty video card
      if ($gallery.find('.avs-video-card').length === 0) {
        $gallery.find('.avs-add-video').trigger('click');
      }
      // Refresh preview after removing video
      debounce('preview-' + gIdx, function() {
        refreshPreview($gallery);
      }, 500);
    });

    bindMediaPicker($card);
  }

  function initSortable(){
    if (!$.fn.sortable) return;

    $('.avs-videos-grid').sortable({
      handle: '.avs-video-drag-handle',
      placeholder: 'avs-video-card avs-sortable-placeholder',
      tolerance: 'pointer',
      update: function(event, ui){
        var $gallery = ui.item.closest('.avs-gallery');
        var gIdx = $gallery.attr('data-index');
        renumberVideos($gallery);
        reindexVideoFields($gallery);
        // Refresh preview after reordering
        debounce('preview-' + gIdx, function() {
          refreshPreview($gallery);
        }, 500);
      }
    });
  }

  function reindexVideoFields($gallery){
    var gIdx = $gallery.attr('data-index');
    $gallery.find('.avs-video-card').each(function(i){
      $(this).attr('data-video-index', i);
      $(this).find('input, textarea').each(function(){
        var name = $(this).attr('name');
        if (name) {
          name = name.replace(/\[videos\]\[\d+\]/, '[videos][' + i + ']');
          $(this).attr('name', name);
        }
      });
    });
  }

  $(function(){
    var $root = $('#avs-galleries');
    console.log('[AVG] DOM ready. #avs-galleries found:', $root.length, 'Templates: gallery=', $('#avs-gallery-template').length, 'video=', $('#avs-video-template').length);
    if (!$root.length) return;

    // Bind existing galleries
    $root.find('.avs-gallery').each(function(){
      bindGalleryEvents($(this));
    });
    console.log('[AVG] Bound', $root.find('.avs-gallery').length, 'galleries');

    // Add new gallery
    $('#avs-add-gallery').on('click', function(){
      var idx = getNextGalleryIndex();
      var tplHtml = $('#avs-gallery-template').html();
      if (!tplHtml) {
        console.error('Anchor Video Gallery: gallery template not found');
        return;
      }
      var tpl = tplHtml.replace(/__INDEX__/g, idx);
      var $gallery = $(tpl);
      $root.append($gallery);
      bindGalleryEvents($gallery);
      initSortable();
      // Scroll to new gallery
      $('html, body').animate({
        scrollTop: $gallery.offset().top - 50
      }, 300);
    });

    initSortable();

    // Bulk add - import
    $(document).on('click', '.avs-bulk-import', function(e) {
      e.preventDefault();
      var $gallery = $(this).closest('.avs-gallery');
      var $inline = $(this).closest('.avs-bulk-inline');
      var $textarea = $inline.find('.avs-bulk-urls');
      var urls = $textarea.val();

      if (!urls.trim()) return;

      var result = bulkAddVideos($gallery, urls);

      // Show result
      $gallery.find('.avs-bulk-result').remove();
      var resultClass = result.failed === 0 ? 'success' : (result.added > 0 ? 'warning' : 'error');
      var resultMsg = '';

      if (result.added > 0) {
        resultMsg = result.added + ' video' + (result.added > 1 ? 's' : '') + ' added.';
      }
      if (result.failed > 0) {
        if (resultMsg) resultMsg += ' ';
        resultMsg += result.failed + ' URL' + (result.failed > 1 ? 's' : '') + ' not recognized.';
      }
      if (result.added === 0 && result.failed === 0) {
        resultMsg = 'No valid URLs found.';
        resultClass = 'error';
      }

      $('<div class="avs-bulk-result ' + resultClass + '">' + resultMsg + '</div>').insertAfter($inline);

      if (result.added > 0) {
        $textarea.val('');
        setTimeout(function() {
          $gallery.find('.avs-bulk-result').fadeOut(300, function() { $(this).remove(); });
        }, 3000);
      }
    });
  });

})(jQuery);
