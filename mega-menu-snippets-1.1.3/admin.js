(function($){
  function applyPreview(){
    var html = $('#mm_html').val() || '';
    var css  = $('#mm_css').val() || '';
    var js  =  $('#mm_js').val() || '';
    var $wrap = $('#mm-preview-content');
    if(!$wrap.length) return;
    var doc = '<style>'+css+'</style><div class="mm-viewport" style="max-height:400px; overflow:auto; padding:12px;">'+html+'</div><script>(function(){try{'+js+'}catch(e){console.error(e)}})();</script>';
    $wrap.empty().append(doc);
  }

  $(document).ready(function(){
    if (window.wp && wp.codeEditor){
      ['mm_html','mm_css','mm_js'].forEach(function(id){
        var $ta = $('#'+id);
        if($ta.length){
          var mode = 'text/html';
          if(id==='mm_css') mode = 'text/css';
          if(id==='mm_js') mode = 'application/javascript';
          var editor = wp.codeEditor.initialize($ta, { codemirror: { mode: mode, lineNumbers: true } });
          if(editor && editor.codemirror){
            editor.codemirror.on('change', function(){ $ta.val(editor.codemirror.getValue()); applyPreview(); });
          } else {
            $ta.on('input', applyPreview);
          }
        }
      });
    } else {
      $('#mm_html,#mm_css,#mm_js').on('input', applyPreview);
    }

    applyPreview();

    $('input[name="mm_max_height"]').on('input', function(){
      var v = parseInt($(this).val() || '400', 10);
      $('#mm-preview-viewport').css('max-height', v+'px');
    });
  });
})(jQuery);