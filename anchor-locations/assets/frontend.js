(function () {
  'use strict';
  function esc(s) {
    var d = document.createElement('div');
    d.textContent = (s == null ? '' : String(s));
    return d.innerHTML;
  }
  function escUrl(s) {
    return encodeURI(s == null ? '' : String(s)).replace(/"/g, '%22');
  }
  function initOne(el) {
    var cfg;
    try { cfg = JSON.parse(el.getAttribute('data-al-map')); } catch (e) { return; }
    if (!window.google || !google.maps) { return; }
    var center = { lat: 39.5, lng: -98.35 };
    if (cfg.center && cfg.center.indexOf(',') > -1) {
      var c = cfg.center.split(','); center = { lat: parseFloat(c[0]), lng: parseFloat(c[1]) };
    } else if (cfg.markers && cfg.markers.length) {
      center = { lat: cfg.markers[0].lat, lng: cfg.markers[0].lng };
    }
    var map = new google.maps.Map(el, { center: center, zoom: cfg.zoom || 8 });
    var info = new google.maps.InfoWindow();
    var bounds = new google.maps.LatLngBounds();
    (cfg.markers || []).forEach(function (m) {
      var opts = { position: { lat: m.lat, lng: m.lng }, map: map, title: m.title };
      if (m.icon) { opts.icon = m.icon; }
      var marker = new google.maps.Marker(opts);
      bounds.extend(opts.position);
      marker.addListener('click', function () {
        var html = '<div class="al-map-popup"><h3><a href="' + escUrl(m.url) + '">' + esc(m.title) + '</a></h3>';
        if (m.services && m.services.length) {
          html += '<ul>';
          m.services.forEach(function (s) { html += '<li><a href="' + escUrl(s.url) + '">' + esc(s.title) + '</a></li>'; });
          html += '</ul>';
        }
        html += '</div>';
        info.setContent(html); info.open(map, marker);
      });
    });
    if (cfg.markers && cfg.markers.length > 1) { map.fitBounds(bounds); }
  }
  function init() { document.querySelectorAll('.al-map[data-al-map]').forEach(initOne); }
  if (document.readyState !== 'loading') { init(); } else { document.addEventListener('DOMContentLoaded', init); }
})();
