(function ($) {
  var debounceTimer = null;

  function previewHead() {
    var extra = ($('#ab_preview_css_urls').val() || '')
      .split(/\r\n|\r|\n/)
      .map(function (s) { return s.trim(); })
      .filter(Boolean);
    return (window.AnchorPreview ? window.AnchorPreview.headMarkup(extra) : '');
  }

  function buildDoc() {
    var css  = $('#ab_css').val() || '';
    var html = $('#ab_html').val() || '';
    var js   = $('#ab_js').val() || '';
    return '<!DOCTYPE html><html><head><meta charset="utf-8">' +
      '<meta name="viewport" content="width=device-width, initial-scale=1">' +
      previewHead() +
      '<style>' + css + '</style></head><body>' +
      html +
      '<script>(function(){try{' + js + '}catch(e){console.error(e);}})();<\/script>' +
      '</body></html>';
  }

  function applyPreview() {
    var frame = document.getElementById('ab-preview-frame');
    if (frame) { frame.srcdoc = buildDoc(); }
  }

  function applyPreviewDebounced() {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(applyPreview, 250);
  }

  $(document).ready(function () {
    if (!(window.AnchorMonaco && window.AnchorMonaco.active) && window.wp && wp.codeEditor) {
      ['ab_html', 'ab_css', 'ab_js'].forEach(function (id) {
        var $ta = $('#' + id);
        if (!$ta.length) { return; }
        var mode = 'text/html';
        if (id === 'ab_css') { mode = 'text/css'; }
        if (id === 'ab_js')  { mode = 'application/javascript'; }
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
      $('#ab_html, #ab_css, #ab_js').on('input', applyPreviewDebounced);
    }

    $('#ab_preview_css_urls').on('input', applyPreviewDebounced);
    applyPreview();

    $('#ab-shortcode-copy-btn').on('click', function () {
      var $val = $('#ab-shortcode-value');
      var text = $val.val();
      function done() {
        var $b = $('#ab-shortcode-copy-btn'); var t = $b.text();
        $b.text('Copied!'); setTimeout(function () { $b.text(t); }, 1200);
      }
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(done, function () { $val.select(); document.execCommand('copy'); done(); });
      } else { $val.select(); document.execCommand('copy'); done(); }
    });
  });
})(jQuery);
