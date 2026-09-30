/**
 * Announcements edit screen: the audience rule builder (groups OR'd, conditions AND'd,
 * "is / is not"), audience preview, test send, and the send confirmation. The rules
 * live as JSON in #aa-audience-input, which the post form saves.
 */
(function ($) {
  'use strict';

  var cfg = window.ANCHOR_AA || {};
  var t = cfg.i18n || {};
  var conditions = cfg.conditions || {};
  var labels = cfg.labels || {};
  var $input, $root, state;

  function post(action, data) {
    return $.post(cfg.ajaxUrl, $.extend({ action: 'anchor_announcements_' + action, nonce: cfg.nonce }, data));
  }

  function fmt(str) {
    var args = Array.prototype.slice.call(arguments, 1);
    return str.replace(/%(\d)\$d|%d/g, function (m, n) { return n ? args[n - 1] : args.shift(); });
  }

  function load() {
    try { state = JSON.parse($input.val() || '{}'); } catch (e) { state = {}; }
    if (!Array.isArray(state.groups)) { state.groups = []; }
    // PHP encodes empty params as [], which would drop named properties on stringify.
    state.groups.forEach(function (g) {
      g.conditions = g.conditions || [];
      g.conditions.forEach(function (c) {
        if (!c.params || Array.isArray(c.params)) { c.params = {}; }
        c.negate = !!c.negate;
      });
    });
  }

  function save() { $input.val(JSON.stringify(state)); }

  function defaults(type) {
    var p = {};
    ((conditions[type] || {}).fields || []).forEach(function (f) {
      if (f.default !== undefined) { p[f.key] = f.default; }
    });
    return p;
  }

  function firstType() { return Object.keys(conditions)[0]; }

  function typeSelect(current) {
    var $s = $('<select class="aa-cond-type"/>');
    var groups = {};
    Object.keys(conditions).forEach(function (k) {
      var g = conditions[k].group;
      if (!groups[g]) { groups[g] = $('<optgroup/>').attr('label', g).appendTo($s); }
      groups[g].append($('<option/>').val(k).text(conditions[k].label).prop('selected', k === current));
    });
    return $s;
  }

  function field(f, c) {
    var $wrap = $('<label class="aa-field"/>').append($('<span class="aa-field__label"/>').text(f.label));
    var val = c.params[f.key];
    var set = function (v) { c.params[f.key] = v; save(); };
    var $el;
    switch (f.type) {
      case 'multiselect':
        $el = $('<select multiple/>');
        $.each(f.options || {}, function (k, lbl) {
          $el.append($('<option/>').val(k).text(lbl).prop('selected', (val || []).indexOf(k) !== -1));
        });
        $el.on('change', function () { set($(this).val() || []); });
        break;
      case 'select':
        $el = $('<select/>');
        $.each(f.options || {}, function (k, lbl) { $el.append($('<option/>').val(k).text(lbl).prop('selected', val === k)); });
        $el.on('change', function () { set($(this).val()); });
        break;
      case 'textarea':
        $el = $('<textarea rows="3"/>').val(val || '').on('input', function () { set($(this).val()); });
        break;
      case 'search':
        return searchField(f, c, $wrap);
      default:
        $el = $('<input/>').attr('type', f.type === 'number' ? 'number' : (f.type === 'date' ? 'date' : 'text')).val(val === undefined ? '' : val)
          .on('input change', function () { set(f.type === 'number' ? Number($(this).val()) : $(this).val()); });
    }
    return $wrap.append($el);
  }

  function searchField(f, c, $wrap) {
    var kind = f.search;
    labels[kind] = labels[kind] || {};
    c.params[f.key] = (c.params[f.key] || []).map(Number);
    var $chips = $('<span class="aa-chips"/>');
    var $q = $('<input type="search" class="aa-search"/>').attr('placeholder', t.search || 'Search');
    var $results = $('<ul class="aa-results"/>');
    var timer;

    function chips() {
      $chips.empty();
      c.params[f.key].forEach(function (id) {
        $('<span class="aa-chip"/>').text(labels[kind][id] || ('#' + id))
          .append($('<button type="button" class="aa-chip__x" aria-label="Remove">&times;</button>').on('click', function () {
            c.params[f.key] = c.params[f.key].filter(function (x) { return x !== id; });
            save(); chips();
          }))
          .appendTo($chips);
      });
    }

    $q.on('input', function () {
      clearTimeout(timer);
      var q = $q.val();
      if (q.length < 2) { $results.empty(); return; }
      timer = setTimeout(function () {
        post('search', { kind: kind, q: q }).done(function (res) {
          $results.empty();
          ((res && res.data) || []).forEach(function (item) {
            $('<li/>').append($('<button type="button"/>').text(item.text).on('click', function () {
              labels[kind][item.id] = item.text;
              if (c.params[f.key].indexOf(item.id) === -1) { c.params[f.key].push(item.id); }
              save(); chips(); $results.empty(); $q.val('');
            })).appendTo($results);
          });
        });
      }, 300);
    });

    chips();
    return $wrap.append($chips, $q, $results);
  }

  function conditionRow(g, c, ci) {
    var $row = $('<div class="aa-cond"/>');
    var $type = typeSelect(c.type).on('change', function () {
      c.type = $(this).val(); c.params = defaults(c.type); render();
    });
    var $neg = $('<select class="aa-cond-neg"/>')
      .append($('<option value="0"/>').text(t.is || 'is').prop('selected', !c.negate))
      .append($('<option value="1"/>').text(t.isNot || 'is not').prop('selected', !!c.negate))
      .on('change', function () { c.negate = $(this).val() === '1'; save(); render(); });
    var $fields = $('<div class="aa-cond-fields"/>');
    ((conditions[c.type] || {}).fields || []).forEach(function (f) { $fields.append(field(f, c)); });
    var $rm = $('<button type="button" class="button-link aa-remove"/>').text(t.remove || 'Remove').on('click', function () {
      g.conditions.splice(ci, 1); render();
    });
    return $row.append($('<div class="aa-cond-head"/>').append($neg, $type, $rm), $fields);
  }

  function render() {
    $root.empty();
    state.groups = state.groups.filter(function (g) { return g.conditions.length; });
    state.groups.forEach(function (g, gi) {
      if (gi > 0) { $root.append($('<div class="aa-or"/>').text(t.or || 'OR')); }
      var $g = $('<div class="aa-group"/>').append($('<p class="aa-group__head"/>').text(t.matchAll || 'Match ALL of these'));
      g.conditions.forEach(function (c, ci) { $g.append(conditionRow(g, c, ci)); });
      if (g.conditions.every(function (c) { return c.negate; })) {
        $g.append($('<p class="description"/>').text(t.onlyNegated));
      }
      $g.append($('<button type="button" class="button"/>').text(t.addCondition).on('click', function () {
        var type = firstType();
        g.conditions.push({ type: type, negate: false, params: defaults(type) }); render();
      }));
      $root.append($g);
    });
    $root.append($('<button type="button" class="button aa-add-group"/>').text(t.addGroup).on('click', function () {
      var type = firstType();
      state.groups.push({ conditions: [{ type: type, negate: false, params: defaults(type) }] }); render();
    }));
    save();
  }

  function previewAudience() {
    var $count = $('#aa-audience-count');
    var failed = function (xhr) {
      var body = xhr && (xhr.responseJSON || xhr);
      var m = body && body.data && body.data.message;
      $count.text(m || t.auditFailed || 'Could not check the audience. Please reload and try again.');
      return $.Deferred().reject().promise();
    };
    return post('audience', { rules: $input.val() }).then(function (res) {
      if (!res || !res.success) { return failed(res); }
      var d = res.data || { count: 0, suppressed: 0, sample: [] };
      $('#aa-audience-count').text(d.count ? fmt(t.recipients, d.count, d.suppressed) : t.nobody);
      var $list = $('#aa-audience-sample').empty();
      d.sample.forEach(function (r) { $('<li/>').text(r.name ? r.name + ' <' + r.email + '>' : r.email).appendTo($list); });
      return d.count;
    }, failed);
  }

  function builderFields() {
    if (window.tinymce) { window.tinymce.triggerSave(); }
    return {
      post_id: cfg.postId,
      subject: $('.anchor-email-builder__subject').val() || '',
      preheader: $('.anchor-email-builder__preheader').val() || '',
      body: $('.anchor-email-builder__body').val() || ''
    };
  }

  $(function () {
    cfg.postId = Number(cfg.postId) || Number($('#post_ID').val()) || 0;
    $input = $('#aa-audience-input');
    $root = $('#aa-audience-builder');
    if ($input.length && cfg.editable) { load(); render(); }

    $('#aa-audience-preview').on('click', previewAudience);

    // Report search (Task 12): the report box is inside the post form, so search navigates instead of submitting.
    $('#aa-report-search-go').on('click', function () {
      var base = $(this).data('base');
      window.location = base + (base.indexOf('?') === -1 ? '?' : '&') + 'aa_s=' + encodeURIComponent($('#aa-report-search').val()) + '#aa-report';
    });
    $('#aa-report-search').on('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); $('#aa-report-search-go').trigger('click'); }
    });

    $('#aa-test-send').on('click', function () {
      var $out = $('#aa-test-result').text('…');
      post('test_send', $.extend(builderFields(), { email: $('#aa-test-email').val() })).done(function (res) {
        $out.text((res && res.data && res.data.message) || '');
      }).fail(function (xhr) {
        $out.text((xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || 'Error');
      });
    });

    $('#aa-send-now').on('click', function (e) {
      var $btn = $(this);
      if ($btn.data('confirmed')) { return; }
      e.preventDefault();
      previewAudience().then(function (count) {
        if (count && window.confirm(fmt(t.confirmSend, count))) {
          $btn.data('confirmed', true);
          $('<input type="hidden" name="aa_action" value="send_now"/>').appendTo($btn.closest('form'));
          $btn.closest('form').trigger('submit');
        }
      });
    });
  });
})(jQuery);
