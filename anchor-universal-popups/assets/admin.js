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

  function applyPreview(){
    var html = $('#up_html').val() || '';
    var css  = $('#up_css').val() || '';
    var js   = $('#up_js').val() || '';
    var $wrap = $('#up-preview-content');
    if(!$wrap.length) return;
    var doc = '<style>'+css+'</style>'
            + '<div class="up-viewport" style="max-height:400px; overflow:auto; padding:12px;">'+html+'</div>'
            + '<script>(function(){try{'+js+'}catch(e){console.error(e)}})();<' + '/script>';
    $wrap.empty().append(doc);
  }

  $(document).ready(function(){
    toggleTriggerFields();
    $(document).on('change', 'select[name="up_trigger_type"]', toggleTriggerFields);

    if (window.wp && wp.codeEditor){
      ['up_html','up_css','up_js'].forEach(function(id){
        var $ta = $('#'+id);
        if($ta.length){
          var mode = 'text/html';
          if(id==='up_css') mode = 'text/css';
          if(id==='up_js') mode = 'application/javascript';
          var editor = wp.codeEditor.initialize($ta, { codemirror: { mode: mode, lineNumbers: true } });
          if(editor && editor.codemirror){
            editor.codemirror.on('change', function(){ $ta.val(editor.codemirror.getValue()); applyPreview(); });
          } else {
            $ta.on('input', applyPreview);
          }
        }
      });
    } else {
      $('#up_html,#up_css,#up_js').on('input', applyPreview);
    }
    applyPreview();
  });
})(jQuery);
