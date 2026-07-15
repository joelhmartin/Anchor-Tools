(function($){
  function toggleAllDay(){
    if($('#anchor_event_all_day').is(':checked')){
      $('.anchor-event-time-fields').hide();
    } else {
      $('.anchor-event-time-fields').show();
    }
  }

  function toggleVirtual(){
    if($('#anchor_event_virtual').is(':checked')){
      $('#anchor-event-virtual-url').show();
    } else {
      $('#anchor-event-virtual-url').hide();
    }
  }

  function toggleRegistration(){
    if($('#anchor_event_registration_enabled').is(':checked')){
      $('.anchor-event-registration-fields').show();
    } else {
      $('.anchor-event-registration-fields').hide();
    }
  }

  function toggleRegistrationType(){
    var type = $('#anchor_event_registration_type').val();
    if(type === 'external'){
      $('#anchor-event-registration-url').show();
    } else {
      $('#anchor-event-registration-url').hide();
    }
  }

  // Task 1.3+1.4 metabox parity (Task 1.5): show/hide the type-dependent and
  // mode-dependent form sections. A container carries data-when-type="a b"
  // and/or data-when-mode="c d" (space-separated). No such attribute on a
  // container means "always shown" for that axis. A container with BOTH
  // attributes only shows when both match. Mirrors admin.js exactly so the
  // metabox and the front-end manager form behave identically.
  function applyConditionalVisibility(){
    var type = $('#anchor_event_type').val();
    var mode = $('#anchor_event_registration_mode').val();

    $('.anchor-event-conditional').each(function(){
      var $el = $(this);
      var whenType = $el.attr('data-when-type');
      var whenMode = $el.attr('data-when-mode');

      var typeMatches = !whenType || whenType.split(/\s+/).indexOf(type) !== -1;
      var modeMatches = !whenMode || whenMode.split(/\s+/).indexOf(mode) !== -1;

      $el.toggle(typeMatches && modeMatches);
    });
  }

  // Session repeater (Sessions section, data-when-type="multisession").
  // Dependency-free jQuery: clone the hidden template row, re-index every
  // row's field names on add/remove so anchor_event_sessions[n][...] stays
  // contiguous. Mirrors admin.js's initSessionsRepeater() exactly.
  function initSessionsRepeater(){
    var $section = $('.anchor-event-sessions-table').closest('.anchor-event-conditional');
    if(!$section.length){ return; }
    var $rows = $section.find('.anchor-event-sessions-rows');
    var $template = $('#anchor-event-session-template');

    function reindexRows(){
      $rows.find('.anchor-event-session-row').each(function(i){
        $(this).find('input').each(function(){
          var name = $(this).attr('name');
          if(!name){ return; }
          name = name.replace(/anchor_event_sessions\[\d+\]/, 'anchor_event_sessions[' + i + ']');
          $(this).attr('name', name);
        });
      });
    }

    $section.on('click', '.anchor-event-session-add', function(e){
      e.preventDefault();
      var index = $rows.find('.anchor-event-session-row').length;
      var html = $template.html().replace(/__INDEX__/g, index);
      $rows.append(html);
    });

    $section.on('click', '.anchor-event-session-remove', function(e){
      e.preventDefault();
      $(this).closest('.anchor-event-session-row').remove();
      reindexRows();
    });
  }

  // Offering-dates repeater (Offering Dates section, data-when-type="offering").
  // Task 2.3 front-end parity. The recurrence rule builder is admin-only (see
  // render_group_authoring_sections()'s $include_recurrence=false for this
  // form) — mirrors admin.js's initOfferingRepeater() exactly.
  function initOfferingRepeater(){
    var $section = $('.anchor-event-offering-table').closest('.anchor-event-conditional');
    if(!$section.length){ return; }
    var $rows = $section.find('.anchor-event-offering-rows');
    var $template = $('#anchor-event-offering-template');

    function reindexRows(){
      $rows.find('.anchor-event-offering-row').each(function(i){
        $(this).find('input').each(function(){
          var name = $(this).attr('name');
          if(!name){ return; }
          name = name.replace(/anchor_event_offering_dates\[\d+\]/, 'anchor_event_offering_dates[' + i + ']');
          $(this).attr('name', name);
        });
      });
    }

    $section.on('click', '.anchor-event-offering-add', function(e){
      e.preventDefault();
      var index = $rows.find('.anchor-event-offering-row').length;
      var html = $template.html().replace(/__INDEX__/g, index);
      $rows.append(html);
    });

    $section.on('click', '.anchor-event-offering-remove', function(e){
      e.preventDefault();
      $(this).closest('.anchor-event-offering-row').remove();
      reindexRows();
    });
  }

  function initThumbnailField(){
    var $field = $('.anchor-event-thumbnail-field');
    if(!$field.length || !window.wp || !wp.media){ return; }
    var $input = $field.find('#anchor_event_thumbnail_id');
    var $preview = $field.find('.anchor-event-thumbnail-preview');
    var $remove = $field.find('.anchor-event-thumbnail-remove');

    $field.on('click', '.anchor-event-thumbnail-select', function(e){
      e.preventDefault();
      var frame = wp.media({
        title: 'Select featured image',
        library: { type: 'image' },
        multiple: false,
        button: { text: 'Use this image' }
      });
      frame.on('select', function(){
        var att = frame.state().get('selection').first().toJSON();
        $input.val(att.id);
        var url = att.sizes && att.sizes.medium ? att.sizes.medium.url : att.url;
        $preview.html('<img src="'+url+'" alt="" />');
        $remove.prop('hidden', false);
      });
      frame.open();
    });

    $field.on('click', '.anchor-event-thumbnail-remove', function(e){
      e.preventDefault();
      $input.val('');
      $preview.empty();
      $remove.prop('hidden', true);
    });
  }

  function initGalleryField(){
    var $field = $('.anchor-event-manager-form .anchor-event-gallery-field');
    if(!$field.length || !window.wp || !wp.media){ return; }
    var $input = $field.find('#anchor_event_gallery');
    var $list = $field.find('.anchor-event-gallery-previews');

    function syncInput(){
      var ids = [];
      $list.children('li').each(function(){
        var id = parseInt($(this).data('id'), 10);
        if(id){ ids.push(id); }
      });
      $input.val(ids.join(','));
    }

    function addItem(id, thumb){
      var $li = $('<li/>').attr('data-id', id);
      $('<img/>').attr({ src: thumb, alt: '' }).appendTo($li);
      $('<button/>', {
        type: 'button',
        'class': 'anchor-event-gallery-remove',
        'aria-label': 'Remove image',
        html: '&times;'
      }).appendTo($li);
      $list.append($li);
    }

    if($.fn.sortable){
      $list.sortable({
        items: '> li',
        placeholder: 'anchor-event-gallery-placeholder',
        tolerance: 'pointer',
        forcePlaceholderSize: true,
        update: syncInput
      });
    }

    $field.on('click', '.anchor-event-gallery-add', function(e){
      e.preventDefault();
      var currentIds = ($input.val() || '').split(',').map(function(v){ return parseInt(v, 10); }).filter(Boolean);
      var frame = wp.media({
        title: 'Select or upload images',
        library: { type: 'image' },
        multiple: 'add',
        button: { text: 'Use these images' }
      });
      frame.on('open', function(){
        var selection = frame.state().get('selection');
        currentIds.forEach(function(id){
          var attachment = wp.media.attachment(id);
          attachment.fetch();
          selection.add([ attachment ]);
        });
      });
      frame.on('select', function(){
        var selection = frame.state().get('selection').toJSON();
        $list.empty();
        selection.forEach(function(att){
          var thumb = att.sizes && att.sizes.thumbnail ? att.sizes.thumbnail.url : att.url;
          addItem(att.id, thumb);
        });
        syncInput();
      });
      frame.open();
    });

    $field.on('click', '.anchor-event-gallery-remove', function(e){
      e.preventDefault();
      $(this).closest('li').remove();
      syncInput();
    });

    $field.on('click', '.anchor-event-gallery-clear', function(e){
      e.preventDefault();
      if(!confirm('Clear all gallery images?')){ return; }
      $list.empty();
      syncInput();
    });
  }

  function initDeleteConfirms(){
    $(document).on('click', '.anchor-event-admin-delete', function(e){
      var msg = $(this).data('confirm') || 'Are you sure?';
      if(!confirm(msg)){ e.preventDefault(); }
    });
  }

  $(document).ready(function(){
    toggleAllDay();
    toggleVirtual();
    toggleRegistration();
    toggleRegistrationType();
    initThumbnailField();
    initGalleryField();
    initSessionsRepeater();
    initOfferingRepeater();
    initDeleteConfirms();
    applyConditionalVisibility();

    $('#anchor_event_all_day').on('change', toggleAllDay);
    $('#anchor_event_virtual').on('change', toggleVirtual);
    $('#anchor_event_registration_enabled').on('change', toggleRegistration);
    $('#anchor_event_registration_type').on('change', toggleRegistrationType);
    $('#anchor_event_type, #anchor_event_registration_mode').on('change', applyConditionalVisibility);
  });
})(jQuery);
