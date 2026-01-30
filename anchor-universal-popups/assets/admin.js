(function($){
  function toggleTriggerFields(){
    var triggerType = $('select[name="up_trigger_type"]').val() || 'page_load';

    $('[data-up-show-when-trigger]').each(function(){
      var allowed = String($(this).attr('data-up-show-when-trigger') || '')
        .split(',')
        .map(function(s){ return $.trim(s); })
        .filter(Boolean);
      var show = allowed.indexOf(triggerType) !== -1;
      $(this).toggle(show);
    });

    $('[data-up-hide-when-trigger]').each(function(){
      var blocked = String($(this).attr('data-up-hide-when-trigger') || '')
        .split(',')
        .map(function(s){ return $.trim(s); })
        .filter(Boolean);
      var hide = blocked.indexOf(triggerType) !== -1;
      $(this).toggle(!hide);
    });
  }

  function toggleModeFields(){
    var mode = $('select[name="up_mode"]').val() || 'html';

    $('[data-up-show-when-mode]').each(function(){
      var allowed = String($(this).attr('data-up-show-when-mode') || '')
        .split(',')
        .map(function(s){ return $.trim(s); })
        .filter(Boolean);
      var show = allowed.indexOf(mode) !== -1;
      $(this).toggle(show);
    });
  }

  function applyPreview(){
    var mode = $('select[name="up_mode"]').val() || 'html';
    var content = '';

    if(mode === 'shortcode'){
      // For shortcode mode, show a note in the preview (shortcode can't be rendered in live preview)
      var shortcode = $('#up_shortcode').val() || '';
      content = '<div style="background:#f0f0f1; padding:16px; border-radius:4px; color:#666;">' +
                '<strong>Shortcode Preview:</strong><br/>' +
                '<code style="display:block; margin-top:8px; padding:8px; background:#fff; border:1px solid #ddd; border-radius:2px;">' +
                (shortcode ? shortcode.replace(/</g,'&lt;').replace(/>/g,'&gt;') : '(No shortcode entered)') +
                '</code>' +
                '<p style="margin-top:8px; font-size:12px;">Shortcodes will be rendered when the popup is displayed on the frontend.</p>' +
                '</div>';
    } else {
      content = $('#up_html').val() || '';
    }

    var css  = $('#up_css').val() || '';
    var js   = $('#up_js').val() || '';
    var $wrap = $('#up-preview-content');
    if(!$wrap.length) return;
    var doc = '<style>'+css+'</style>'
            + '<div class="up-viewport" style="max-height:400px; overflow:auto; padding:12px;">'+content+'</div>'
            + '<script>(function(){try{'+js+'}catch(e){console.error(e)}})();<' + '/script>';
    $wrap.empty().append(doc);
  }

  $(document).ready(function(){
    toggleTriggerFields();
    toggleModeFields();
    $(document).on('change', 'select[name="up_trigger_type"]', toggleTriggerFields);
    $(document).on('change', 'select[name="up_mode"]', function(){
      toggleModeFields();
      applyPreview();
    });

    if (window.wp && wp.codeEditor){
      ['up_html','up_shortcode','up_css','up_js'].forEach(function(id){
        var $ta = $('#'+id);
        if($ta.length){
          var mode = 'text/html';
          if(id==='up_css') mode = 'text/css';
          if(id==='up_js') mode = 'application/javascript';
          if(id==='up_shortcode') mode = 'text/html'; // shortcodes are essentially HTML-like
          var editor = wp.codeEditor.initialize($ta, { codemirror: { mode: mode, lineNumbers: true } });
          if(editor && editor.codemirror){
            editor.codemirror.on('change', function(){ $ta.val(editor.codemirror.getValue()); applyPreview(); });
          } else {
            $ta.on('input', applyPreview);
          }
        }
      });
    } else {
      $('#up_html,#up_shortcode,#up_css,#up_js').on('input', applyPreview);
    }
    applyPreview();
  });
})(jQuery);
