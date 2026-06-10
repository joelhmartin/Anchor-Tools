(function () {
  function esc(u) { return String(u).replace(/"/g, '&quot;'); }
  window.AnchorPreview = {
    // Returns <link> tags for harvested + extra URLs, then one <style> of inline head CSS.
    headMarkup: function (extraUrls) {
      var data = window.ANCHOR_PREVIEW || { urls: [], inline: '' };
      var urls = (data.urls || []).concat(extraUrls || []);
      var seen = {}, links = '';
      urls.forEach(function (u) {
        if (!u || seen[u]) { return; }
        seen[u] = true;
        links += '<link rel="stylesheet" href="' + esc(u) + '">';
      });
      var inline = data.inline ? '<style>' + data.inline + '</style>' : '';
      return links + inline;
    }
  };
})();
