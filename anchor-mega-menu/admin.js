(function($){
  function ensurePreviewNodes(){
    var $wrap = $('#mm-preview-content');
    if(!$wrap.length) return null;
    if(!$wrap.find('#mm-preview-global-css').length){
      $wrap.empty().append(
        '<style id="mm-preview-global-css"></style>' +
        '<style id="mm-preview-css"></style>' +
        '<div id="mm-preview-html" class="mm-viewport" style="max-height:none; overflow:auto; padding:12px;"></div>' +
        '<div id="mm-preview-js"></div>'
      );
    }
    return {
      globalStyle: $wrap.find('#mm-preview-global-css'),
      style: $wrap.find('#mm-preview-css'),
      viewport: $wrap.find('#mm-preview-html'),
      js: $wrap.find('#mm-preview-js')
    };
  }

  function applyPreview(){
    var html = $('#mm_html').val() || '';
    var globalCss = $('#mm_global_css').val() || '';
    var css  = $('#mm_css').val() || '';
    var js  =  $('#mm_js').val() || '';
    var nodes = ensurePreviewNodes();
    if(!nodes) return;
    nodes.globalStyle.text(globalCss);
    nodes.style.text(css);
    nodes.viewport.html(html);
    nodes.js.empty();
    if(js){
      var script = document.createElement('script');
      script.text = '(function(){try{'+js+'}catch(e){console.error(e)}})();';
      nodes.js.append(script);
    }
  }

  $(document).ready(function(){
    if (window.wp && wp.codeEditor){
      ['mm_html','mm_global_css','mm_css','mm_js'].forEach(function(id){
        var $ta = $('#'+id);
        if($ta.length){
          var mode = 'text/html';
          if(id==='mm_css' || id==='mm_global_css') mode = 'text/css';
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
      $('#mm_html,#mm_global_css,#mm_css,#mm_js').on('input', applyPreview);
    }

    applyPreview();

    function applyPreviewMaxHeight(){
      var raw = ($('input[name="mm_max_height"]').val() || '').trim().toLowerCase();
      var $viewport = $('#mm-preview-viewport');
      if (!raw || raw === 'none') {
        $viewport.css('max-height', 'none');
        return;
      }
      var v = parseInt(raw, 10);
      if (!isNaN(v) && v > 0) {
        $viewport.css('max-height', v + 'px');
      } else {
        $viewport.css('max-height', 'none');
      }
    }

    applyPreviewMaxHeight();
    $('input[name="mm_max_height"]').on('input', applyPreviewMaxHeight);
  });
})(jQuery);
