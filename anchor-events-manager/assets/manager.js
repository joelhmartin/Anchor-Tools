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
    initDeleteConfirms();

    $('#anchor_event_all_day').on('change', toggleAllDay);
    $('#anchor_event_virtual').on('change', toggleVirtual);
    $('#anchor_event_registration_enabled').on('change', toggleRegistration);
    $('#anchor_event_registration_type').on('change', toggleRegistrationType);
  });
})(jQuery);
