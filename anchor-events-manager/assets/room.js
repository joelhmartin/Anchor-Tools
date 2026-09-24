/* Anchor Events — the /live/ room countdown and state refresh. */
(function ($) {
  'use strict';

  var cfg = window.ANCHOR_EVENTS_ROOM || {};
  var timer = null;
  var poll = null;
  var skew = 0; // serverNow - clientNow, in seconds.

  function $block() {
    return $('.anchor-room-state').first();
  }

  function now() {
    return Math.floor(Date.now() / 1000) + skew;
  }

  function readSkew($el) {
    var serverNow = parseInt($el.attr('data-server-now'), 10);
    if (serverNow) {
      skew = serverNow - Math.floor(Date.now() / 1000);
    }
  }

  function format(seconds) {
    if (seconds <= 0) { return cfg.i18n ? cfg.i18n.now : 'now'; }
    var d = Math.floor(seconds / 86400);
    var h = Math.floor((seconds % 86400) / 3600);
    var m = Math.floor((seconds % 3600) / 60);
    var s = seconds % 60;
    var pad = function (n) { return n < 10 ? '0' + n : String(n); };
    return (d > 0 ? d + 'd ' : '') + pad(h) + ':' + pad(m) + ':' + pad(s);
  }

  function showError() {
    var $el = $block();
    if (!$el.length || $el.find('.anchor-room-error').length) { return; }
    $el.append(
      $('<p class="anchor-room-error" role="status" aria-live="polite"></p>')
        .text(cfg.i18n ? cfg.i18n.refresh : 'Refresh the page.')
    );
  }

  function refresh() {
    if (!cfg.restUrl || !cfg.eventId) { return; }
    $.ajax({
      url: cfg.restUrl + cfg.eventId + '/room',
      method: 'GET',
      dataType: 'json',
      beforeSend: function (xhr) { xhr.setRequestHeader('X-WP-Nonce', cfg.nonce); }
    }).done(function (res) {
      if (!res || typeof res.html !== 'string') { showError(); return; }
      $block().replaceWith(res.html);
      start();
    }).fail(showError);
  }

  function tick() {
    var $el = $block();
    if (!$el.length) { return; }
    var target = parseInt($el.attr('data-target-ts'), 10) || 0;
    var remaining = target - now();
    var $out = $el.find('.anchor-room-countdown');
    if ($out.length) { $out.text(format(remaining)); }
    if (target && remaining <= 0) {
      window.clearInterval(timer);
      timer = null;
      refresh();
    }
  }

  function start() {
    var $el = $block();
    if (!$el.length) { return; }
    readSkew($el);

    if (timer) { window.clearInterval(timer); timer = null; }
    if (poll) { window.clearInterval(poll); poll = null; }

    var state = $el.attr('data-state');
    if (state === 'countdown' || state === 'between') {
      tick();
      timer = window.setInterval(tick, 1000);
    }
    if (state === 'live') {
      // Catch `ended` / `between` without a reload (spec §5.6).
      poll = window.setInterval(refresh, (cfg.pollSeconds || 300) * 1000);
    }
  }

  $(start);
})(jQuery);
