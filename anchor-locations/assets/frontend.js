(function () {
  'use strict';

  // --- escaping helpers (never regress: a crafted title/slug must not inject markup) ---
  function esc(s) {
    var d = document.createElement('div');
    d.textContent = (s == null ? '' : String(s));
    return d.innerHTML;
  }
  function escUrl(s) {
    return encodeURI(s == null ? '' : String(s)).replace(/"/g, '%22');
  }
  // Only allow same-origin paths or absolute http(s) URLs to reach location.href.
  // Reject protocol-relative "//host" (open-redirect), javascript:, data:, etc.
  function safeHref(s) {
    s = (s == null ? '' : String(s));
    // same-origin path: a single leading slash, but not protocol-relative "//host"
    if (/^\/(?!\/)/.test(s)) { return s; }
    // absolute http(s) URL only
    if (/^https?:\/\//i.test(s)) { return s; }
    return '';
  }

  // Normalize any GeoJSON value into a FeatureCollection google.maps.Data accepts,
  // carrying the location url so the click handler can navigate.
  function toFeatureCollection(boundary, url) {
    var feature;
    if (boundary && boundary.type === 'FeatureCollection') {
      // clone-ish: stamp url onto each feature's properties
      (boundary.features || []).forEach(function (f) {
        f.properties = f.properties || {};
        if (f.properties.al_url == null) { f.properties.al_url = url; }
      });
      return boundary;
    }
    if (boundary && boundary.type === 'Feature') {
      feature = boundary;
      feature.properties = feature.properties || {};
      if (feature.properties.al_url == null) { feature.properties.al_url = url; }
    } else {
      // bare geometry (Polygon / MultiPolygon / etc.)
      feature = { type: 'Feature', properties: { al_url: url }, geometry: boundary };
    }
    return { type: 'FeatureCollection', features: [feature] };
  }

  function renderBoundaries(map, markers) {
    var data = null;
    (markers || []).forEach(function (m) {
      if (!m.boundary) { return; }
      try {
        if (!data) {
          data = new google.maps.Data({ map: map });
          data.setStyle({ fillColor: '#2c6ecb', fillOpacity: 0.10, strokeColor: '#2c6ecb', strokeOpacity: 0.6, strokeWeight: 1 });
          data.addListener('mouseover', function (e) { data.overrideStyle(e.feature, { fillOpacity: 0.28, strokeWeight: 2 }); });
          data.addListener('mouseout', function () { data.revertStyle(); });
          data.addListener('click', function (e) {
            var href = safeHref(e.feature.getProperty('al_url'));
            if (href) { window.location.href = href; }
          });
        }
        data.addGeoJson(toFeatureCollection(m.boundary, m.url));
      } catch (err) { /* bad geojson must never break the map */ }
    });
  }

  // Build the accessible filter panel; returns { el, apply } or null.
  function buildFilters(cfg, markers, onChange) {
    var wants = cfg.filters || [];
    if (!wants.length) { return null; }
    var panel = document.createElement('div');
    panel.className = 'al-map-filters';

    var groups = {};
    function collect(kind, valueOf) {
      var seen = {}, values = [];
      markers.forEach(function (rec) {
        valueOf(rec.data).forEach(function (v) {
          if (v && !seen[v]) { seen[v] = 1; values.push(v); }
        });
      });
      return values.sort();
    }

    if (wants.indexOf('service') > -1) {
      groups.service = collect('service', function (m) {
        var out = [];
        (m.services || []).forEach(function (s) {
          (s.service_slugs || []).forEach(function (slug) { if (slug) { out.push(slug); } });
        });
        return out;
      });
    }
    if (wants.indexOf('type') > -1) {
      groups.type = collect('type', function (m) { return m.type ? [m.type] : []; });
    }

    var checked = { service: {}, type: {} };
    var idSeq = 0;

    function humanize(v) { return v.replace(/[-_]+/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); }); }

    ['service', 'type'].forEach(function (kind) {
      var values = groups[kind];
      if (!values || !values.length) { return; }
      var fs = document.createElement('fieldset');
      fs.className = 'al-map-filter-group al-map-filter-' + kind;
      var legend = document.createElement('legend');
      legend.textContent = (kind === 'service' ? 'Service' : 'Type');
      fs.appendChild(legend);
      values.forEach(function (v) {
        var id = 'al-flt-' + kind + '-' + (idSeq++);
        var label = document.createElement('label');
        label.setAttribute('for', id);
        var cb = document.createElement('input');
        cb.type = 'checkbox';
        cb.id = id;
        cb.value = v;
        cb.checked = false;
        cb.addEventListener('change', function () {
          if (cb.checked) { checked[kind][v] = 1; } else { delete checked[kind][v]; }
          onChange(checked);
        });
        var span = document.createElement('span');
        span.textContent = humanize(v); // textContent = safe; no markup injection
        label.appendChild(cb);
        label.appendChild(span);
        fs.appendChild(label);
      });
      panel.appendChild(fs);
    });

    if (!panel.children.length) { return null; }
    return { el: panel, checked: checked };
  }

  function markerVisible(rec, checked) {
    var svcKeys = Object.keys(checked.service);
    var typeKeys = Object.keys(checked.type);
    var okSvc = true, okType = true;
    if (svcKeys.length) {
      var slugs = [];
      (rec.data.services || []).forEach(function (s) {
        (s.service_slugs || []).forEach(function (slug) { slugs.push(slug); });
      });
      okSvc = svcKeys.some(function (k) { return slugs.indexOf(k) > -1; });
    }
    if (typeKeys.length) {
      okType = typeKeys.indexOf(rec.data.type) > -1;
    }
    return okSvc && okType;
  }

  function initOne(el) {
    var cfg;
    try { cfg = JSON.parse(el.getAttribute('data-al-map')); } catch (e) { return; }
    if (!window.google || !google.maps) { return; }

    // A filter panel is inserted before the map element; give them a shared wrapper
    // so layout stays predictable regardless of theme.
    var mapCfg = cfg || {};
    var center = { lat: 39.5, lng: -98.35 };
    if (mapCfg.center && mapCfg.center.indexOf(',') > -1) {
      var c = mapCfg.center.split(','); center = { lat: parseFloat(c[0]), lng: parseFloat(c[1]) };
    } else if (mapCfg.markers && mapCfg.markers.length) {
      center = { lat: mapCfg.markers[0].lat, lng: mapCfg.markers[0].lng };
    }

    var map = new google.maps.Map(el, { center: center, zoom: mapCfg.zoom || 8 });
    var info = new google.maps.InfoWindow();
    var bounds = new google.maps.LatLngBounds();

    var records = []; // { marker, data }
    (mapCfg.markers || []).forEach(function (m) {
      var opts = { position: { lat: m.lat, lng: m.lng }, title: m.title };
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
      records.push({ marker: marker, data: m });
    });

    // Clustering (opt-in, graceful): use the library when present, else plain markers.
    var clusterer = null;
    var useCluster = mapCfg.cluster && window.markerClusterer && window.markerClusterer.MarkerClusterer;
    if (useCluster) {
      try {
        clusterer = new markerClusterer.MarkerClusterer({ map: map, markers: [] });
      } catch (e) { clusterer = null; }
    }

    function place(rec, visible) {
      if (clusterer) {
        if (visible) { clusterer.addMarker(rec.marker); } else { clusterer.removeMarker(rec.marker); }
      } else {
        rec.marker.setMap(visible ? map : null);
      }
    }

    // Initial placement: all visible.
    records.forEach(function (rec) { place(rec, true); });

    // Boundary polygons.
    renderBoundaries(map, mapCfg.markers || []);

    // Filter UI.
    var built = buildFilters(mapCfg, records, function (checked) {
      records.forEach(function (rec) { place(rec, markerVisible(rec, checked)); });
    });
    if (built) {
      el.parentNode.insertBefore(built.el, el);
    }

    if (records.length > 1) { map.fitBounds(bounds); }
  }

  function init() { document.querySelectorAll('.al-map[data-al-map]').forEach(initOne); }
  if (document.readyState !== 'loading') { init(); } else { document.addEventListener('DOMContentLoaded', init); }
})();
