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

  $(document).ready(function(){
    toggleAllDay();
    toggleVirtual();
    toggleRegistration();
    toggleRegistrationType();

    $('#anchor_event_all_day').on('change', toggleAllDay);
    $('#anchor_event_virtual').on('change', toggleVirtual);
    $('#anchor_event_registration_enabled').on('change', toggleRegistration);
    $('#anchor_event_registration_type').on('change', toggleRegistrationType);
  });
})(jQuery);
