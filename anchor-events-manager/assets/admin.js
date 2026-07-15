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

  // Task 1.3+1.4: show/hide the type-dependent and mode-dependent metabox
  // sections. A container carries data-when-type="a b" and/or
  // data-when-mode="c d" (space-separated). No such attribute on a container
  // means "always shown" for that axis. A container with BOTH attributes
  // only shows when both match.
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
  // contiguous, matching the ticket-tier repeater's index scheme.
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

  function initGalleryField(){
    var $field = $('.anchor-event-gallery-field');
    if(!$field.length){ return; }
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
      if(!window.wp || !wp.media){ return; }

      var currentIds = ($input.val() || '').split(',').map(function(v){
        return parseInt(v, 10);
      }).filter(Boolean);

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

  $(document).ready(function(){
    toggleAllDay();
    toggleVirtual();
    toggleRegistration();
    toggleRegistrationType();
    initGalleryField();
    initSessionsRepeater();
    applyConditionalVisibility();

    $('#anchor_event_all_day').on('change', toggleAllDay);
    $('#anchor_event_virtual').on('change', toggleVirtual);
    $('#anchor_event_registration_enabled').on('change', toggleRegistration);
    $('#anchor_event_registration_type').on('change', toggleRegistrationType);
    $('#anchor_event_type, #anchor_event_registration_mode').on('change', applyConditionalVisibility);
  });
})(jQuery);
