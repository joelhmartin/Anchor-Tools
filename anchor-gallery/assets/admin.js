(function($){
  'use strict';

  /* ──────────────────────────────────────────────
     Live preview (debounced AJAX)
     ──────────────────────────────────────────────*/
  var timer;
  function refreshPreview(){
    clearTimeout(timer);
    timer = setTimeout(function(){
      var settings = {};
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

      // Snapshot every item card with all per-item fields.
      var items = [];
      $('#avg-video-list .avg-video-row').each(function(){
        var $row = $(this);
        items.push({
          type:                $row.find('.avg-item-type').val() || 'video',
          url:                 $row.find('.avg-video-url').val() || '',
          title:               $row.find('.avg-video-title').val() || '',
          attachment_id:       parseInt($row.find('.avg-attachment-id').val(), 10) || 0,
          html:                $row.find('.avg-item-html').val() || '',
          alt:                 $row.find('.avg-item-alt').val() || '',
          caption:             $row.find('.avg-item-caption').val() || '',
          categories:          $row.find('.avg-item-cats').val() || '',
          custom_thumbnail_id: parseInt($row.find('.avg-custom-thumb-id').val(), 10) || 0,
          link_url:            $row.find('.avg-item-link-url').val() || '',
          link_target:         $row.find('.avg-item-link-target').val() || '_self'
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

  /* ──────────────────────────────────────────────
     Forced-by-layout / conditional visibility
     (unchanged from previous revision)
     ──────────────────────────────────────────────*/
  var FORCED_BY_LAYOUT = (window.AVG && AVG.forcedByLayout) ? AVG.forcedByLayout : {};

  function applyForcedSettings(){
    var layout = $('#avg_layout').val() || 'slider';
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

  function getSettingValue(key){
    var $el = $('#avg_' + key);
    if (!$el.length) $el = $('[name="avg_' + key + '"]').first();
    if (!$el.length) return undefined;
    if ($el.attr('type') === 'checkbox') return $el.is(':checked');
    return $el.val();
  }

  function dependsMet(depends){
    if (!depends || typeof depends !== 'object') return true;
    for (var key in depends) {
      if (!Object.prototype.hasOwnProperty.call(depends, key)) continue;
      var expected = depends[key];
      var actual = getSettingValue(key);
      if (expected === true) {
        if (!actual || actual === '0' || actual === 0) return false;
      } else if (expected === false) {
        if (actual && actual !== '0' && actual !== 0) return false;
      } else {
        if (String(actual) !== String(expected)) return false;
      }
    }
    return true;
  }

  function updateVisibility(){
    var layout = $('#avg_layout').val() || 'slider';
    $('.avg-setting-row, .anchor-builder__field').each(function(){
      var $row = $(this);
      var visible = true;

      var appliesAttr = $row.attr('data-applies-to');
      if (appliesAttr === undefined || appliesAttr === '') {
        appliesAttr = $row.attr('data-show-for') || '';
      }
      if (appliesAttr) {
        if (appliesAttr.split(',').indexOf(layout) === -1) visible = false;
      }

      if (visible) {
        var dependsRaw = $row.attr('data-depends-on');
        if (dependsRaw) {
          try {
            var depends = JSON.parse(dependsRaw);
            if (!dependsMet(depends)) visible = false;
          } catch (e) {}
        }
      }
      $row.toggle(visible);
    });
    applyForcedSettings();
  }

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

  /* ──────────────────────────────────────────────
     Item card rendering (mirror of PHP render_item_card)
     ──────────────────────────────────────────────*/
  function escapeHtml(s){
    return $('<div>').text(s == null ? '' : String(s)).html();
  }
  function escapeAttr(s){ return escapeHtml(s).replace(/"/g, '&quot;'); }

  function deriveThumbUrl(item){
    if (item.custom_thumb_url) return item.custom_thumb_url;
    if (item.type === 'image' && item.image_thumb_url) return item.image_thumb_url;
    if (item.type === 'video' && item.url) {
      var m = item.url.match(/(?:youtu\.be\/|youtube\.com\/(?:watch\?v=|embed\/|shorts\/|live\/))([A-Za-z0-9_-]{6,})/);
      if (m) return 'https://img.youtube.com/vi/' + m[1] + '/mqdefault.jpg';
    }
    return '';
  }

  function typeBadgeText(t){ return t === 'image' ? 'Image' : (t === 'html' ? 'HTML' : 'Video'); }
  function typeIconChar(t){ return t === 'html' ? '&lt;/&gt;' : (t === 'image' ? '&#128247;' : '&#9658;'); }

  function buildCard(idx, item){
    var t      = item.type || 'video';
    var thumb  = deriveThumbUrl(item);
    var title  = item.title || (t === 'html' ? '(HTML block)' : '(untitled)');
    var cats   = (item.categories || '').split(',').map(function(s){return s.trim();}).filter(Boolean);
    var chips  = cats.map(function(c){ return '<span class="avg-card-cat-chip">'+ escapeHtml(c) +'</span>'; }).join('');
    var prefix = 'avg_videos['+ idx +']';

    var html = ''
      + '<div class="avg-video-row anchor-builder__item-card" data-index="'+ idx +'" draggable="true">'
      +   '<span class="anchor-builder__item-handle" aria-hidden="true">&#x2630;</span>'
      +   '<div class="anchor-builder__item-thumb avg-card-thumb" data-type-icon="'+ escapeAttr(t) +'"'
      +     (thumb ? ' style="background-image:url(\''+ thumb.replace(/'/g, "\\'") +'\')"' : '') +'>'
      +     (thumb ? '' : '<span class="avg-card-thumb-icon">'+ typeIconChar(t) +'</span>')
      +   '</div>'
      +   '<div class="anchor-builder__item-body">'
      +     '<div class="anchor-builder__item-title avg-card-title">'+ escapeHtml(title) +'</div>'
      +     '<div class="anchor-builder__item-meta">'
      +       '<span class="avg-type-badge avg-type-'+ escapeAttr(t) +'">'+ escapeHtml(typeBadgeText(t)) +'</span> '
      +       '<span class="avg-card-cats">'+ chips +'</span>'
      +     '</div>'
      +   '</div>'
      +   '<div class="anchor-builder__item-actions">'
      +     '<button type="button" class="button avg-edit-item">Edit</button>'
      +     '<button type="button" class="button avg-duplicate-item" aria-label="Duplicate">&#x2398;</button>'
      +     '<button type="button" class="button button-link-delete avg-remove-video" aria-label="Remove">&times;</button>'
      +   '</div>'
      +   '<select name="'+ prefix +'[type]" class="avg-item-type" style="display:none">'
      +     '<option value="video"'+  (t === 'video'  ? ' selected' : '') +'>Video</option>'
      +     '<option value="image"'+  (t === 'image'  ? ' selected' : '') +'>Image</option>'
      +     '<option value="html"'+   (t === 'html'   ? ' selected' : '') +'>Custom HTML</option>'
      +   '</select>'
      +   '<input type="url"    name="'+ prefix +'[url]"                  value="'+ escapeAttr(item.url || '') +'"           class="avg-video-url"      style="display:none" />'
      +   '<input type="hidden" name="'+ prefix +'[attachment_id]"        value="'+ (parseInt(item.attachment_id,10) || 0) +'"  class="avg-attachment-id" />'
      +   '<input type="hidden" name="'+ prefix +'[custom_thumbnail_id]"  value="'+ (parseInt(item.custom_thumbnail_id,10) || 0) +'" class="avg-custom-thumb-id" />'
      +   '<textarea name="'+ prefix +'[html]" class="avg-item-html" style="display:none">'+ escapeHtml(item.html || '') +'</textarea>'
      +   '<input type="text" name="'+ prefix +'[title]"        value="'+ escapeAttr(item.title || '') +'"        class="avg-video-title"      style="display:none" />'
      +   '<input type="text" name="'+ prefix +'[alt]"          value="'+ escapeAttr(item.alt || '') +'"          class="avg-item-alt"         style="display:none" />'
      +   '<input type="text" name="'+ prefix +'[caption]"      value="'+ escapeAttr(item.caption || '') +'"      class="avg-item-caption"     style="display:none" />'
      +   '<input type="text" name="'+ prefix +'[categories]"   value="'+ escapeAttr(item.categories || '') +'"   class="avg-item-cats"        style="display:none" />'
      +   '<input type="url"  name="'+ prefix +'[link_url]"     value="'+ escapeAttr(item.link_url || '') +'"     class="avg-item-link-url"    style="display:none" />'
      +   '<input type="text" name="'+ prefix +'[link_target]"  value="'+ escapeAttr(item.link_target || '_self') +'" class="avg-item-link-target" style="display:none" />'
      + '</div>';
    return html;
  }

  function reindexCards(){
    $('#avg-video-list .avg-video-row').each(function(idx){
      var $row = $(this);
      $row.attr('data-index', idx);
      $row.find('[name^="avg_videos["]').each(function(){
        var $el = $(this);
        var name = $el.attr('name');
        $el.attr('name', name.replace(/^avg_videos\[\d+\]/, 'avg_videos[' + idx + ']'));
      });
    });
  }

  function readCard($row){
    return {
      type:                $row.find('.avg-item-type').val() || 'video',
      url:                 $row.find('.avg-video-url').val() || '',
      title:               $row.find('.avg-video-title').val() || '',
      attachment_id:       parseInt($row.find('.avg-attachment-id').val(), 10) || 0,
      custom_thumbnail_id: parseInt($row.find('.avg-custom-thumb-id').val(), 10) || 0,
      html:                $row.find('.avg-item-html').val() || '',
      alt:                 $row.find('.avg-item-alt').val() || '',
      caption:             $row.find('.avg-item-caption').val() || '',
      categories:          $row.find('.avg-item-cats').val() || '',
      link_url:            $row.find('.avg-item-link-url').val() || '',
      link_target:         $row.find('.avg-item-link-target').val() || '_self'
    };
  }

  function refreshCardChrome($row){
    var item = readCard($row);
    var t = item.type || 'video';
    var thumb = deriveThumbUrl({
      type: t,
      url:  item.url,
      custom_thumb_url:    $row.data('customThumbUrl') || '',
      image_thumb_url:     $row.data('imageThumbUrl') || ''
    });
    var $thumb = $row.find('.avg-card-thumb');
    $thumb.attr('data-type-icon', t);
    if (thumb) {
      $thumb.css('background-image', "url('" + thumb + "')").find('.avg-card-thumb-icon').remove();
    } else {
      $thumb.css('background-image', '');
      if (!$thumb.find('.avg-card-thumb-icon').length) {
        $thumb.append('<span class="avg-card-thumb-icon">'+ typeIconChar(t) +'</span>');
      } else {
        $thumb.find('.avg-card-thumb-icon').html(typeIconChar(t));
      }
    }
    $row.find('.avg-card-title').text(item.title || (t === 'html' ? '(HTML block)' : '(untitled)'));
    $row.find('.avg-type-badge').attr('class', 'avg-type-badge avg-type-' + t).text(typeBadgeText(t));
    var cats = item.categories.split(',').map(function(s){return s.trim();}).filter(Boolean);
    $row.find('.avg-card-cats').empty();
    cats.forEach(function(c){
      $row.find('.avg-card-cats').append('<span class="avg-card-cat-chip">'+ escapeHtml(c) +'</span>');
    });
  }

  function addItem(item){
    var idx = $('#avg-video-list .avg-video-row').length;
    item = item || { type: 'video' };
    var $card = $(buildCard(idx, item));
    $('#avg-video-list').append($card);
    if (item.image_thumb_url) $card.data('imageThumbUrl', item.image_thumb_url);
    if (item.custom_thumb_url) $card.data('customThumbUrl', item.custom_thumb_url);
    refreshCardChrome($card);
    return $card;
  }

  /* ──────────────────────────────────────────────
     Inspector (side panel)
     ──────────────────────────────────────────────*/
  var $activeRow = null;

  function openInspector($row){
    $activeRow = $row;
    var item = readCard($row);
    var $p = $('#avg-inspector');

    $p.find('.avg-insp-type').val(item.type);
    $p.find('.avg-insp-url').val(item.url);
    $p.find('.avg-insp-title').val(item.title);
    $p.find('.avg-insp-alt').val(item.alt);
    $p.find('.avg-insp-caption').val(item.caption);
    $p.find('.avg-insp-cats').val(item.categories);
    $p.find('.avg-insp-html').val(item.html);
    $p.find('.avg-insp-link-url').val(item.link_url);
    $p.find('.avg-insp-link-target').val(item.link_target);

    syncInspectorRows(item.type);
    syncInspectorImagePreview(item.attachment_id);
    syncInspectorThumbPreview(item.custom_thumbnail_id);

    $p.addClass('is-open').attr('aria-hidden', 'false');
  }

  function syncInspectorRows(t){
    var $p = $('#avg-inspector');
    $p.find('.avg-insp-row-video').toggle(t === 'video');
    $p.find('.avg-insp-row-image').toggle(t === 'image');
    $p.find('.avg-insp-row-html').toggle(t === 'html');
  }

  function syncInspectorImagePreview(id){
    var $prev = $('#avg-inspector .avg-insp-image-preview');
    $prev.empty();
    if (id && wp && wp.media) {
      var url = $activeRow ? ($activeRow.data('imageThumbUrl') || '') : '';
      if (url) $prev.html('<img src="'+ url +'" style="max-height:60px;border-radius:3px;border:1px solid #dcdcde;margin-left:8px" />');
    }
  }

  function syncInspectorThumbPreview(id){
    var $prev = $('#avg-inspector .avg-insp-thumb-preview');
    $prev.empty();
    if (id && $activeRow) {
      var url = $activeRow.data('customThumbUrl') || '';
      if (url) $prev.html('<img src="'+ url +'" style="max-height:80px;border-radius:3px;border:1px solid #dcdcde" />');
    }
  }

  function commitInspectorField(field, value){
    if (!$activeRow) return;
    var map = {
      type:        '.avg-item-type',
      url:         '.avg-video-url',
      title:       '.avg-video-title',
      alt:         '.avg-item-alt',
      caption:     '.avg-item-caption',
      categories:  '.avg-item-cats',
      html:        '.avg-item-html',
      link_url:    '.avg-item-link-url',
      link_target: '.avg-item-link-target'
    };
    if (!map[field]) return;
    $activeRow.find(map[field]).val(value);
    refreshCardChrome($activeRow);
    refreshPreview();
  }

  /* ──────────────────────────────────────────────
     Media pickers
     ──────────────────────────────────────────────*/
  function openImagePicker($row){
    var frame = wp.media({
      title: 'Choose Image',
      library: { type: 'image' },
      multiple: false,
      button: { text: 'Use Image' }
    });
    frame.on('select', function(){
      var a = frame.state().get('selection').first().toJSON();
      var thumbUrl = a.sizes && a.sizes.thumbnail ? a.sizes.thumbnail.url : a.url;
      $row.find('.avg-attachment-id').val(a.id);
      $row.data('imageThumbUrl', thumbUrl);
      refreshCardChrome($row);
      if ($activeRow && $activeRow[0] === $row[0]) syncInspectorImagePreview(a.id);
      refreshPreview();
    });
    frame.open();
  }

  function openCustomThumbPicker($row){
    var frame = wp.media({
      title: 'Choose Custom Thumbnail',
      library: { type: 'image' },
      multiple: false,
      button: { text: 'Use as Thumbnail' }
    });
    frame.on('select', function(){
      var a = frame.state().get('selection').first().toJSON();
      var thumbUrl = a.sizes && a.sizes.thumbnail ? a.sizes.thumbnail.url : a.url;
      $row.find('.avg-custom-thumb-id').val(a.id);
      $row.data('customThumbUrl', thumbUrl);
      refreshCardChrome($row);
      if ($activeRow && $activeRow[0] === $row[0]) syncInspectorThumbPreview(a.id);
      refreshPreview();
    });
    frame.open();
  }

  function openBulkMediaPicker(){
    var frame = wp.media({
      title: 'Add Images',
      library: { type: 'image' },
      multiple: true,
      button: { text: 'Add Selected Images' }
    });
    frame.on('select', function(){
      frame.state().get('selection').each(function(att){
        var d = att.toJSON();
        var thumbUrl = d.sizes && d.sizes.thumbnail ? d.sizes.thumbnail.url : d.url;
        addItem({ type: 'image', attachment_id: d.id, image_thumb_url: thumbUrl });
      });
      refreshPreview();
    });
    frame.open();
  }

  /* ──────────────────────────────────────────────
     Wiring
     ──────────────────────────────────────────────*/
  $(function(){
    // Settings change -> preview refresh + visibility
    $(document).on('change input', '.avg-setting', function(){
      refreshPreview();
      updateVisibility();
      updateAspectRatioState();
    });

    updateVisibility();
    updateAspectRatioState();

    // Re-enable forced (disabled) inputs on submit so values save.
    $(document).on('submit', 'form#post', function(){
      $('.avg-setting').filter(function(){ return $(this).data('avgForced'); }).prop('disabled', false);
    });

    // Add buttons
    $('#avg-add-video').on('click', function(){ addItem({type:'video'}); refreshPreview(); });
    $('#avg-add-html').on('click',  function(){ addItem({type:'html'});  refreshPreview(); });
    $('#avg-add-image').on('click', function(){ openBulkMediaPicker(); });

    // Bulk URL import
    $('#avg-bulk-import').on('click', function(){
      var text = $('#avg-bulk-urls').val();
      if (!text || !text.trim()) return;
      text.split(/[\r\n]+/).forEach(function(line){
        line = line.trim();
        if (!line) return;
        if (/youtu\.?be|vimeo\.com/.test(line)) {
          addItem({ type: 'video', url: line });
        }
      });
      $('#avg-bulk-urls').val('');
      refreshPreview();
    });

    // Card actions
    $(document).on('click', '.avg-edit-item', function(){
      openInspector($(this).closest('.avg-video-row'));
    });
    $(document).on('click', '.avg-duplicate-item', function(){
      var $src = $(this).closest('.avg-video-row');
      var copy = readCard($src);
      copy.image_thumb_url  = $src.data('imageThumbUrl') || '';
      copy.custom_thumb_url = $src.data('customThumbUrl') || '';
      var $new = addItem(copy);
      // Insert directly after source rather than at the end.
      $src.after($new);
      reindexCards();
      refreshPreview();
    });
    $(document).on('click', '.avg-remove-video', function(){
      $(this).closest('.avg-video-row').remove();
      reindexCards();
      refreshPreview();
    });

    // Drag-reorder (delegated by builder.js, but we still need to reindex
    // and refresh after the user drops).
    $(document).on('anchor-builder:items-reordered', function(){
      reindexCards();
      refreshPreview();
    });

    // Inspector field bindings
    $(document).on('change', '.avg-insp-type', function(){
      var v = $(this).val();
      syncInspectorRows(v);
      commitInspectorField('type', v);
    });
    $(document).on('input change', '.avg-insp-url',         function(){ commitInspectorField('url',         $(this).val()); });
    $(document).on('input change', '.avg-insp-title',       function(){ commitInspectorField('title',       $(this).val()); });
    $(document).on('input change', '.avg-insp-alt',         function(){ commitInspectorField('alt',         $(this).val()); });
    $(document).on('input change', '.avg-insp-caption',     function(){ commitInspectorField('caption',     $(this).val()); });
    $(document).on('input change', '.avg-insp-cats',        function(){ commitInspectorField('categories',  $(this).val()); });
    $(document).on('input change', '.avg-insp-html',        function(){ commitInspectorField('html',        $(this).val()); });
    $(document).on('input change', '.avg-insp-link-url',    function(){ commitInspectorField('link_url',    $(this).val()); });
    $(document).on('change',       '.avg-insp-link-target', function(){ commitInspectorField('link_target', $(this).val()); });

    $(document).on('click', '.avg-insp-choose-image', function(){
      if (!$activeRow) return; openImagePicker($activeRow);
    });
    $(document).on('click', '.avg-insp-choose-thumb', function(){
      if (!$activeRow) return; openCustomThumbPicker($activeRow);
    });
    $(document).on('click', '.avg-insp-reset-thumb', function(){
      if (!$activeRow) return;
      $activeRow.find('.avg-custom-thumb-id').val(0);
      $activeRow.data('customThumbUrl', '');
      refreshCardChrome($activeRow);
      syncInspectorThumbPreview(0);
      refreshPreview();
    });
    $(document).on('click', '.avg-insp-remove', function(){
      if (!$activeRow) return;
      $activeRow.remove();
      $activeRow = null;
      $('#avg-inspector').removeClass('is-open').attr('aria-hidden','true');
      reindexCards();
      refreshPreview();
    });
    $(document).on('click', '.avg-insp-duplicate', function(){
      if (!$activeRow) return;
      $activeRow.find('.avg-duplicate-item').trigger('click');
    });

    // Initial preview
    refreshPreview();

    $(document).on('anchor-builder:refresh-preview', function(){ refreshPreview(); });
    document.addEventListener('anchor-builder:refresh-preview', function(){ refreshPreview(); });
  });

})(jQuery);
