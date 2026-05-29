(function ($) {
  var debounceTimer = null;

  function cssLinks() {
    var urls = (window.MM_PREVIEW && MM_PREVIEW.cssUrls) || [];
    return urls.map(function (u) { return '<link rel="stylesheet" href="' + u + '">'; }).join('');
  }

  function buildDoc() {
    var globalCss = $('#mm_global_css').val() || '';
    var css  = $('#mm_css').val() || '';
    var html = $('#mm_html').val() || '';
    var js   = $('#mm_js').val() || '';
    return '<!DOCTYPE html><html><head><meta charset="utf-8">' +
      '<meta name="viewport" content="width=device-width, initial-scale=1">' +
      cssLinks() +
      '<style>' + globalCss + '</style><style>' + css + '</style></head><body>' +
      html +
      '<script>(function(){try{' + js + '}catch(e){console.error(e);}})();<\/script>' +
      '</body></html>';
  }

  function applyPreview() {
    var frame = document.getElementById('mm-preview-frame');
    if (frame) { frame.srcdoc = buildDoc(); }
  }

  function applyPreviewDebounced() {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(applyPreview, 250);
  }

  $(document).ready(function () {
    if (window.wp && wp.codeEditor) {
      ['mm_html', 'mm_global_css', 'mm_css', 'mm_js'].forEach(function (id) {
        var $ta = $('#' + id);
        if (!$ta.length) { return; }
        var mode = 'text/html';
        if (id === 'mm_css' || id === 'mm_global_css') { mode = 'text/css'; }
        if (id === 'mm_js') { mode = 'application/javascript'; }
        var editor = wp.codeEditor.initialize($ta, { codemirror: { mode: mode, lineNumbers: true } });
        if (editor && editor.codemirror) {
          editor.codemirror.on('change', function () {
            $ta.val(editor.codemirror.getValue());
            applyPreviewDebounced();
          });
        } else {
          $ta.on('input', applyPreviewDebounced);
        }
      });
    } else {
      $('#mm_html, #mm_global_css, #mm_css, #mm_js').on('input', applyPreviewDebounced);
    }
    applyPreview();
  });
})(jQuery);
