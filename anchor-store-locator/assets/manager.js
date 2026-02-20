(function($){
  if ( ! window.ANCHOR_STORE_MGR ) { return; }

  var cfg   = window.ANCHOR_STORE_MGR;
  var $wrap = $('#anchor-store-manager');
  var $list = $('#asm-list');
  var $form = $('#asm-form');
  var $formEl   = $('#asm-form-el');
  var $formTitle = $('#asm-form-title');
  var $toast = $('#asm-toast');
  var mediaFrame = null;

  /* ---- View toggling ---- */
  function showList() {
    $form.hide();
    $list.show();
  }

  function showForm( title ) {
    $list.hide();
    $formTitle.text( title || 'Add Store' );
    $form.show();
    $form.find('input,textarea,select').first().focus();
  }

  function resetForm() {
    $formEl[0].reset();
    $('#asm-store-id').val('');
    $('#asm-thumbnail-id').val('');
    $('#asm-image-preview').empty();
    $('[data-action="remove-image"]').hide();
  }

  /* ---- Toast ---- */
  function toast( msg, type ) {
    $toast.attr('class', 'asm-toast asm-toast--' + ( type || 'success' ));
    $toast.text( msg ).stop(true).fadeIn(200);
    setTimeout(function(){ $toast.fadeOut(400); }, 3000);
  }

  /* ---- AJAX helpers ---- */
  function post( action, data, cb ) {
    data.action = action;
    data.nonce  = cfg.nonce;
    $.post( cfg.ajaxUrl, data, function( resp ) {
      if ( resp.success ) {
        cb( null, resp.data );
      } else {
        cb( resp.data && resp.data.message ? resp.data.message : 'An error occurred.' );
      }
    }).fail(function(){
      cb('Request failed.');
    });
  }

  /* ---- Add button ---- */
  $wrap.on('click', '[data-action="add"]', function(){
    resetForm();
    showForm('Add Store');
  });

  /* ---- Cancel / Back ---- */
  $wrap.on('click', '[data-action="cancel"]', function(){
    showList();
  });

  /* ---- Edit button ---- */
  $wrap.on('click', '[data-action="edit"]', function(){
    var id = $(this).data('id');
    resetForm();

    post('anchor_store_manager_get', { store_id: id }, function( err, data ){
      if ( err ) { toast( err, 'error' ); return; }
      $('#asm-store-id').val( data.id );
      $('#asm-title').val( data.title );
      $('#asm-address').val( data.address );
      $('#asm-lat').val( data.lat );
      $('#asm-lng').val( data.lng );
      $('#asm-website').val( data.website );
      $('#asm-email').val( data.email );
      $('#asm-phone').val( data.phone );
      $('#asm-maps-url').val( data.maps_url );
      $('#asm-status').val( data.status );

      if ( data.thumbnail_id && data.thumbnail_url ) {
        $('#asm-thumbnail-id').val( data.thumbnail_id );
        $('#asm-image-preview').html('<img src="' + data.thumbnail_url + '" alt="" />');
        $('[data-action="remove-image"]').show();
      }

      showForm('Edit Store');
    });
  });

  /* ---- Delete button ---- */
  $wrap.on('click', '[data-action="delete"]', function(){
    if ( ! confirm('Are you sure you want to delete this store?') ) { return; }
    var id  = $(this).data('id');
    var $tr = $list.find('tr[data-id="' + id + '"]');

    post('anchor_store_manager_delete', { store_id: id }, function( err ){
      if ( err ) { toast( err, 'error' ); return; }
      $tr.fadeOut(300, function(){ $(this).remove(); updateEmptyState(); });
      toast('Store deleted.');
    });
  });

  /* ---- Form submit ---- */
  $formEl.on('submit', function(e){
    e.preventDefault();
    var $btn = $formEl.find('[type="submit"]');
    $btn.prop('disabled', true).text('Saving…');

    var payload = {
      store_id:     $('#asm-store-id').val(),
      title:        $('#asm-title').val(),
      address:      $('#asm-address').val(),
      lat:          $('#asm-lat').val(),
      lng:          $('#asm-lng').val(),
      website:      $('#asm-website').val(),
      email:        $('#asm-email').val(),
      phone:        $('#asm-phone').val(),
      maps_url:     $('#asm-maps-url').val(),
      status:       $('#asm-status').val(),
      thumbnail_id: $('#asm-thumbnail-id').val()
    };

    post('anchor_store_manager_save', payload, function( err, data ){
      $btn.prop('disabled', false).text('Save Store');
      if ( err ) { toast( err, 'error' ); return; }

      if ( data.is_new ) {
        appendRow( data );
      } else {
        updateRow( data );
      }
      toast( data.is_new ? 'Store created.' : 'Store updated.' );
      showList();
    });
  });

  /* ---- Featured image: upload ---- */
  $wrap.on('click', '[data-action="upload-image"]', function(e){
    e.preventDefault();
    if ( mediaFrame ) { mediaFrame.open(); return; }

    mediaFrame = wp.media({
      title: 'Select Featured Image',
      button: { text: 'Use Image' },
      multiple: false
    });

    mediaFrame.on('select', function(){
      var attachment = mediaFrame.state().get('selection').first().toJSON();
      var url = attachment.sizes && attachment.sizes.medium ? attachment.sizes.medium.url : attachment.url;
      $('#asm-thumbnail-id').val( attachment.id );
      $('#asm-image-preview').html('<img src="' + url + '" alt="" />');
      $('[data-action="remove-image"]').show();
    });

    mediaFrame.open();
  });

  /* ---- Featured image: remove ---- */
  $wrap.on('click', '[data-action="remove-image"]', function(){
    $('#asm-thumbnail-id').val('');
    $('#asm-image-preview').empty();
    $(this).hide();
  });

  /* ---- Table helpers ---- */
  function buildRow( d ) {
    var img = d.thumbnail_sm
      ? '<img src="' + esc( d.thumbnail_sm ) + '" alt="" />'
      : '<span class="asm-no-img"></span>';
    var badge = '<span class="asm-badge asm-badge--' + esc( d.status ) + '">' + esc( ucfirst( d.status ) ) + '</span>';
    return '<tr data-id="' + d.id + '">' +
      '<td class="asm-col-img">' + img + '</td>' +
      '<td>' + esc( d.title ) + '</td>' +
      '<td>' + esc( d.address ) + '</td>' +
      '<td>' + esc( d.phone ) + '</td>' +
      '<td class="asm-col-status">' + badge + '</td>' +
      '<td class="asm-col-actions">' +
        '<button type="button" class="asm-btn asm-btn--sm" data-action="edit" data-id="' + d.id + '">Edit</button> ' +
        '<button type="button" class="asm-btn asm-btn--sm asm-btn--danger" data-action="delete" data-id="' + d.id + '">Delete</button>' +
      '</td></tr>';
  }

  function appendRow( d ) {
    $list.find('.asm-empty').remove();
    $list.find('tbody').append( buildRow( d ) );
  }

  function updateRow( d ) {
    var $old = $list.find('tr[data-id="' + d.id + '"]');
    if ( $old.length ) {
      $old.replaceWith( buildRow( d ) );
    }
  }

  function updateEmptyState() {
    if ( $list.find('tbody tr').length === 0 ) {
      $list.find('tbody').html('<tr class="asm-empty"><td colspan="6">No stores found.</td></tr>');
    }
  }

  function esc( s ) {
    if ( ! s ) return '';
    var d = document.createElement('div');
    d.appendChild( document.createTextNode( s ) );
    return d.innerHTML;
  }

  function ucfirst( s ) {
    return s ? s.charAt(0).toUpperCase() + s.slice(1) : '';
  }

})(jQuery);
