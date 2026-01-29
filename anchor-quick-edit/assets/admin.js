(function($){
  let cropper = null;

  function openModal($modal) {
    $modal.attr('aria-hidden', 'false').addClass('is-open');
  }
  function closeModal($modal) {
    $modal.attr('aria-hidden', 'true').removeClass('is-open');
    if (cropper) {
      cropper.destroy();
      cropper = null;
    }
    $modal.find('.ac-yqep-image').attr('src', '');
    $modal.find('.ac-yqep-status').text('');
  }

  function setStatus($modal, msg) {
    $modal.find('.ac-yqep-status').text(msg || '');
  }

  function getMode($modal) {
    return $modal.find('input[name="ac_yqep_mode"]:checked').val() || 'copy';
  }

  // Extend inline edit to populate fields and wire post id
  const original = inlineEditPost.edit;
  inlineEditPost.edit = function(id){
    original.apply(this, arguments);

    let postId = 0;
    if (typeof id === 'object') postId = parseInt(this.getId(id), 10);
    if (!postId) return;

    const $row = $('#post-' + postId);
    const seoTitle = ($row.find('.ac-yqep-yoast-title-val').text() || '').trim();
    const metaDesc = ($row.find('.ac-yqep-yoast-desc-val').text() || '').trim();

    const $editRow = $('#edit-' + postId);
    $editRow.find('input[name="_yoast_wpseo_title"]').val(seoTitle);
    $editRow.find('textarea[name="_yoast_wpseo_metadesc"]').val(metaDesc);

    $editRow.find('.ac-yqep-post-id').val(postId);
  };

  $(document).on('click', '.ac-yqep-edit-featured', function(){
    const $editRow = $(this).closest('tr.inline-edit-row');
    const postId = parseInt($editRow.find('.ac-yqep-post-id').val(), 10);
    const $modal = $editRow.find('.ac-yqep-modal');

    if (!postId) return;

    setStatus($modal, 'Loading image...');
    openModal($modal);

    $.post(AC_YQEP.ajaxUrl, {
      action: 'ac_yqep_get_featured',
      nonce: AC_YQEP.nonce,
      postId: postId
    }).done(function(res){
      if (!res || !res.success) {
        setStatus($modal, (res && res.data && res.data.message) ? res.data.message : 'Failed to load.');
        return;
      }

      const data = res.data;
      $modal.find('.ac-yqep-attachment-id').val(data.attachmentId);

      const img = $modal.find('.ac-yqep-image').get(0);
      img.onload = function(){
        cropper = new Cropper(img, {
          viewMode: 1,
          autoCropArea: 1,
          responsive: true,
          background: false
        });
        setStatus($modal, '');
      };
      img.src = data.url;

      // Preselect format based on original, default to jpeg
      const mime = data.mime || 'image/jpeg';
      const preferred = (mime === 'image/png' || mime === 'image/webp' || mime === 'image/jpeg') ? mime : 'image/jpeg';
      $modal.find('.ac-yqep-format').val(preferred);

      // Default output size: keep square-ish, or use original max 1600
      const outW = Math.min(1600, data.width || 1200);
      const outH = Math.min(1600, data.height || 1200);
      $modal.find('.ac-yqep-out-w').val(outW);
      $modal.find('.ac-yqep-out-h').val(outH);
    }).fail(function(){
      setStatus($modal, 'Request failed.');
    });
  });

  $(document).on('click', '.ac-yqep-close, .ac-yqep-modal__backdrop', function(){
    const $modal = $(this).closest('.ac-yqep-modal');
    closeModal($modal);
  });

  $(document).on('click', '.ac-yqep-reset', function(){
    const $modal = $(this).closest('.ac-yqep-modal');
    if (cropper) cropper.reset();
    setStatus($modal, '');
  });

  $(document).on('click', '.ac-yqep-center', function(){
    const $modal = $(this).closest('.ac-yqep-modal');
    if (!cropper) return;
    cropper.setCropBoxData({ left: 0, top: 0 });
    setStatus($modal, '');
  });

  // Keep aspect ratio in sync when locked
  $(document).on('input', '.ac-yqep-out-w, .ac-yqep-out-h', function(){
    const $modal = $(this).closest('.ac-yqep-modal');
    if (!$modal.find('.ac-yqep-lock').is(':checked')) return;

    const w = parseInt($modal.find('.ac-yqep-out-w').val(), 10);
    const h = parseInt($modal.find('.ac-yqep-out-h').val(), 10);
    if (!w || !h) return;

    // Enforce cropper aspect ratio to output ratio
    if (cropper) cropper.setAspectRatio(w / h);
  });

  $(document).on('change', '.ac-yqep-lock', function(){
    const $modal = $(this).closest('.ac-yqep-modal');
    if (!cropper) return;

    if ($(this).is(':checked')) {
      const w = parseInt($modal.find('.ac-yqep-out-w').val(), 10);
      const h = parseInt($modal.find('.ac-yqep-out-h').val(), 10);
      if (w && h) cropper.setAspectRatio(w / h);
    } else {
      cropper.setAspectRatio(NaN);
    }
  });

  $(document).on('click', '.ac-yqep-save', function(){
    const $modal = $(this).closest('.ac-yqep-modal');
    const $editRow = $modal.closest('tr.inline-edit-row');
    const postId = parseInt($editRow.find('.ac-yqep-post-id').val(), 10);
    const attachmentId = parseInt($modal.find('.ac-yqep-attachment-id').val(), 10);

    if (!cropper || !postId || !attachmentId) {
      setStatus($modal, 'Missing cropper or IDs.');
      return;
    }

    const crop = cropper.getData(true); // natural image coordinates
    const outW = parseInt($modal.find('.ac-yqep-out-w').val(), 10) || 1200;
    const outH = parseInt($modal.find('.ac-yqep-out-h').val(), 10) || 1200;
    const mime = $modal.find('.ac-yqep-format').val() || 'image/jpeg';
    const mode = getMode($modal);

    setStatus($modal, 'Saving...');

    $.post(AC_YQEP.ajaxUrl, {
      action: 'ac_yqep_process_image',
      nonce: AC_YQEP.nonce,
      postId: postId,
      attachmentId: attachmentId,
      crop: JSON.stringify(crop),
      outW: outW,
      outH: outH,
      mime: mime,
      mode: mode
    }).done(function(res){
      if (!res || !res.success) {
        setStatus($modal, (res && res.data && res.data.message) ? res.data.message : 'Save failed.');
        return;
      }
      setStatus($modal, res.data.message || 'Saved.');

      // Force reload of the row so the thumbnail preview updates
      // Simple approach: refresh page after a moment
      setTimeout(function(){ window.location.reload(); }, 700);
    }).fail(function(){
      setStatus($modal, 'Save request failed.');
    });
  });

})(jQuery);
