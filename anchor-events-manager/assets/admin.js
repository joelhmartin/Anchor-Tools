(function($){
  function toggleAllDay(){
    if($('#anchor_event_all_day').is(':checked')){
      $('.anchor-event-time-fields').hide();
    } else {
      $('.anchor-event-time-fields').show();
    }
  }

  function toggleVirtual(){
    $('#anchor-event-virtual-url').toggle($('#anchor_event_virtual').is(':checked'));
  }

  /**
   * A virtual event needs somewhere to send people.
   *
   * Deliberately NOT the `required` attribute. This field sits on one step of a
   * multi-step form, so it is usually off screen when Save is pressed, and the
   * browser refuses to submit a form with an invalid hidden control while
   * showing nothing — the button just stops working. Constraint validation also
   * runs before the submit event, so a handler cannot recover from it.
   *
   * Checking it here instead means the form can jump to the step holding the
   * field first, and then let the browser report against a control that is
   * actually visible.
   */
  function guardVirtualUrl(){
    var $url = $('[data-required-when-virtual]');
    if(!$url.length){ return; }
    $url.closest('form').on('submit', function(e){
      if(!$('#anchor_event_virtual').is(':checked')){ return; }
      if($.trim($url.val()) !== ''){ return; }
      e.preventDefault();
      var step = $url.closest('[data-step]').attr('data-step');
      if(step){ $('[data-step-nav="' + step + '"]').trigger('click'); }
      $('#anchor-event-virtual-url').show();
      $url[0].setCustomValidity('Add the link attendees will join at, or untick "Virtual event".');
      $url[0].reportValidity();
      $url.one('input', function(){ $url[0].setCustomValidity(''); });
    });
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

  // Labels repeater (Labels section). Same dependency-free clone-template-row
  // idiom as initSessionsRepeater() above, with one addition: the Caption input
  // is only meaningful for key="custom" (known keys resolve their caption from
  // the server-side vocabulary), so it is enabled/disabled to match.
  //
  // Unlike Sessions, this section is NOT wrapped in .anchor-event-conditional —
  // labels apply to every event type — so the section is found by walking up to
  // .anchor-event-section instead.
  function initLabelsRepeater(){
    var $section = $('.anchor-event-labels-table').closest('.anchor-event-section');
    if(!$section.length){ return; }
    var $rows = $section.find('.anchor-event-labels-rows');
    var $template = $('#anchor-event-label-template');

    function reindexRows(){
      $rows.find('.anchor-event-label-row').each(function(i){
        $(this).find('input, select').each(function(){
          var name = $(this).attr('name');
          if(!name){ return; }
          name = name.replace(/anchor_event_labels\[\d+\]/, 'anchor_event_labels[' + i + ']');
          $(this).attr('name', name);
        });
      });
    }

    function syncCaption($row){
      var isCustom = $row.find('.anchor-label-key').val() === 'custom';
      $row.find('.anchor-label-caption').prop('disabled', !isCustom);
      if(!isCustom){ $row.find('.anchor-label-caption').val(''); }
    }

    $section.on('click', '.anchor-event-label-add', function(e){
      e.preventDefault();
      var index = $rows.find('.anchor-event-label-row').length;
      var html = $template.html().replace(/__INDEX__/g, index);
      $rows.append(html);
      syncCaption($rows.find('.anchor-event-label-row').last());
    });

    $section.on('click', '.anchor-event-label-remove', function(e){
      e.preventDefault();
      $(this).closest('.anchor-event-label-row').remove();
      reindexRows();
    });

    $section.on('change', '.anchor-label-key', function(){
      syncCaption($(this).closest('.anchor-event-label-row'));
    });
  }

  // Offering-dates repeater (Offering Dates section, data-when-type="offering").
  // Task 2.3. Same dependency-free clone-template-row idiom as
  // initSessionsRepeater() above, one column wider (adds Capacity).
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

  // Recurrence rule builder (Recurring Schedule section, data-when-type="recurring").
  // Task 2.3. Not a repeater — just: (a) show the weekday checkboxes only for
  // freq=weekly, and (b) a client-side hint (no hard block — the server-side
  // guard in persist_group_authoring() is the real enforcement) nudging the
  // user to set a count or an until date before saving, plus (c) live
  // show/hide of the same inline error render_group_authoring_sections()
  // renders server-side from the STORED rule (Task 2.3 notice fix) — this
  // gives instant pre-save feedback; PHP still has the final say on load/reload.
  function initRecurrenceBuilder(){
    var $freq = $('#anchor_event_recurrence_freq');
    if(!$freq.length){ return; }
    var $weekdays = $('.anchor-event-recurrence-weekdays');
    var $count = $('#anchor_event_recurrence_count');
    var $until = $('#anchor_event_recurrence_until');
    var $hint = $('.anchor-event-recurrence-terminator-hint');
    var $error = $('.anchor-event-recurrence-error');

    function toggleWeekdays(){
      $weekdays.toggle($freq.val() === 'weekly');
    }

    function toggleTerminatorHint(){
      var hasTerminator = !!($count.val() || $until.val());
      $hint.toggleClass('anchor-event-recurrence-terminator-missing', !hasTerminator);
      $error.toggle(!hasTerminator);
    }

    $freq.on('change', toggleWeekdays);
    $count.on('input', toggleTerminatorHint);
    $until.on('input', toggleTerminatorHint);

    toggleWeekdays();
    toggleTerminatorHint();
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
    initLabelsRepeater();
    initOfferingRepeater();
    initRecurrenceBuilder();
    applyConditionalVisibility();

    $('#anchor_event_all_day').on('change', toggleAllDay);
    $('#anchor_event_virtual').on('change', toggleVirtual);
    guardVirtualUrl();
    $('#anchor_event_registration_enabled').on('change', toggleRegistration);
    $('#anchor_event_registration_type').on('change', toggleRegistrationType);
    $('#anchor_event_type, #anchor_event_registration_mode').on('change', applyConditionalVisibility);
  });
})(jQuery);
