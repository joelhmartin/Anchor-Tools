/**
 * Per-event email builder for the front-end manager form.
 *
 * One modal per email type: the wording on the left, a rendered preview on the
 * right, and a switch to the raw HTML. The preview is the plugin's own
 * anchor_events_email_preview endpoint, which renders the real template against
 * real event data, so what the panel shows is what the email will be — including
 * the subject and opening lines currently typed but not yet saved.
 *
 * The fields are ordinary inputs inside the manager form, so nothing here needs
 * to save anything: closing the modal leaves the values in the form and the
 * event's normal Save writes them.
 */
(function () {
  'use strict';

  var cfg = window.ANCHOR_EVENT_EMAILS || {};

  /** UTF-8 safe base64 — btoa() alone throws on anything outside Latin-1. */
  function b64(str) {
    var bytes = new TextEncoder().encode(str);
    var bin = '';
    bytes.forEach(function (b) { bin += String.fromCharCode(b); });
    return btoa(bin);
  }

  /**
   * Indent an HTML document so the source view is readable.
   *
   * The stored templates carry whatever whitespace they were authored with —
   * usually a heredoc's leading indentation plus tokens strung across lines —
   * which renders as a wall. This re-indents by nesting depth: one tag per line,
   * two spaces per level.
   *
   * Whitespace between tags is not significant in these templates (they are
   * table-based emails), and the value is run through wp_kses on save either
   * way, so re-indenting cannot change what the email renders. <pre>, <style>
   * and <script> keep their contents verbatim.
   */
  var VOID_TAGS = ['area','base','br','col','embed','hr','img','input','link','meta','source','track','wbr'];
  var VERBATIM = ['pre','style','script','textarea'];

  function formatHtml(html) {
    if (!html || !html.trim()) { return html; }
    var parts = String(html).replace(/>\s+</g, '><').split(/(<[^>]+>)/g).filter(function (t) { return t !== ''; });
    var out = [], depth = 0, verbatim = null;

    for (var i = 0; i < parts.length; i++) {
      var part = parts[i];
      var isTag = part.charAt(0) === '<';

      if (verbatim) {
        out.push(part);
        if (isTag && part.toLowerCase().indexOf('</' + verbatim) === 0) { verbatim = null; depth = Math.max(0, depth - 1); }
        continue;
      }
      if (!isTag) {
        var text = part.trim();
        if (text !== '') { out.push(pad(depth) + text); }
        continue;
      }

      var name = (part.match(/^<\s*\/?\s*([a-zA-Z0-9-]+)/) || [])[1];
      name = name ? name.toLowerCase() : '';

      if (part.indexOf('</') === 0) {
        depth = Math.max(0, depth - 1);
        out.push(pad(depth) + part);
        continue;
      }

      var selfClosing = part.slice(-2) === '/>' || VOID_TAGS.indexOf(name) !== -1 || part.charAt(1) === '!' || part.charAt(1) === '?';

      // An element whose entire content is one text node stays on one line.
      // Breaking it would put whitespace inside a <td>, which is exactly how the
      // gap under an image appears in Outlook — and these are the cells holding
      // {header_image} and {cta_button}.
      if (!selfClosing && parts[i + 1] && parts[i + 1].charAt(0) !== '<'
          && parts[i + 2] && parts[i + 2].toLowerCase().indexOf('</' + name) === 0) {
        out.push(pad(depth) + part + parts[i + 1].trim() + parts[i + 2]);
        i += 2;
        continue;
      }

      out.push(pad(depth) + part);
      if (!selfClosing) {
        depth++;
        if (VERBATIM.indexOf(name) !== -1) { verbatim = name; }
      }
    }

    return out.join('\n');
  }

  function pad(n) { return new Array(n + 1).join('  '); }

  function ready(fn) {
    if (document.readyState !== 'loading') { fn(); }
    else { document.addEventListener('DOMContentLoaded', fn); }
  }

  function init(wrap) {
    var eventId = wrap.getAttribute('data-event') || '0';

    wrap.querySelectorAll('[data-email-modal]').forEach(function (modal) {
      var type    = modal.getAttribute('data-email-modal');
      var subject = modal.querySelector('[data-email-field="subject"]');
      var intro   = modal.querySelector('[data-email-field="intro"]');
      var source  = modal.querySelector('.anchor-event-email-source');
      var frame   = modal.querySelector('.anchor-event-email-frame');
      var status  = modal.querySelector('.anchor-event-email-status');
      var timer   = null;
      var lastFocused = intro;

      // Remember where a token or image should land.
      [subject, intro, source].forEach(function (el) {
        if (el) { el.addEventListener('focus', function () { lastFocused = el; }); }
      });

      function say(msg) { if (status) { status.textContent = msg || ''; } }

      function render() {
        if (!frame) { return; }
        say('Updating…');
        var body = new URLSearchParams();
        body.set('action', 'anchor_events_email_preview');
        body.set('nonce', cfg.nonce || '');
        body.set('event_id', eventId);
        body.set('type', type);
        // Base64 in transit: the raw value is a full HTML document, and a WAF
        // (Wordfence here) reads HTML in a POST field as an attack and returns
        // its own 403 before WordPress ever sees the request. The server decodes
        // and then sanitises exactly as before, so nothing is trusted extra.
        body.set('template_b64', b64(source ? source.value : ''));
        body.set('subject', subject ? subject.value : '');
        body.set('intro', intro ? intro.value : '');

        fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
          .then(function (r) { return r.json(); })
          .then(function (res) {
            if (!res || !res.success) { say('Preview unavailable'); return; }
            // srcdoc keeps the email in its own document, so its styles cannot
            // leak into the page and the page's cannot leak into it.
            frame.srcdoc = res.data.html;
            say('');
          })
          .catch(function () { say('Preview unavailable'); });
      }

      function renderSoon() {
        window.clearTimeout(timer);
        timer = window.setTimeout(render, 400);
      }

      [subject, intro, source].forEach(function (el) {
        if (el) { el.addEventListener('input', renderSoon); }
      });

      // open / close
      wrap.querySelectorAll('.anchor-event-email-open').forEach(function (btn) {
        if (btn.getAttribute('data-email-type') !== type) { return; }
        btn.addEventListener('click', function () {
          // Indent once, the first time it is opened — re-indenting on every
          // visit would fight an author who laid their own markup out.
          if (source && !source.dataset.formatted) {
            source.value = formatHtml(source.value);
            source.dataset.formatted = '1';
          }
          if (typeof modal.showModal === 'function') { modal.showModal(); }
          else { modal.setAttribute('open', ''); }
          render();
        });
      });
      modal.querySelectorAll('.anchor-event-email-close').forEach(function (btn) {
        btn.addEventListener('click', function () {
          if (typeof modal.close === 'function') { modal.close(); }
          else { modal.removeAttribute('open'); }
        });
      });

      // Preview / HTML switch
      modal.querySelectorAll('[data-email-view]').forEach(function (tab) {
        tab.addEventListener('click', function () {
          var view = tab.getAttribute('data-email-view');
          modal.querySelectorAll('[data-email-view]').forEach(function (t) {
            t.classList.toggle('is-active', t === tab);
          });
          var html = view === 'html';
          if (source) { source.hidden = !html; }
          if (frame) { frame.hidden = html; }
          if (!html) { render(); }
        });
      });

      // token buttons write into whichever field was last focused
      modal.querySelectorAll('.anchor-event-token').forEach(function (btn) {
        btn.addEventListener('click', function () {
          insertAtCursor(lastFocused || intro, btn.getAttribute('data-token') || '');
        });
      });

      // media library -> insert the URL, never re-upload
      var media = modal.querySelector('.anchor-event-email-media');
      if (media) {
        media.addEventListener('click', function () {
          if (!window.wp || !wp.media) { say('Media library unavailable'); return; }
          var picker = wp.media({ title: 'Choose an image', library: { type: 'image' }, multiple: false,
                                  button: { text: 'Use this image' } });
          picker.on('select', function () {
            var img = picker.state().get('selection').first().toJSON();
            var url = (img.sizes && img.sizes.large ? img.sizes.large.url : img.url);
            var target = lastFocused || source;
            // In the HTML view an <img> is what you want; in a text field the URL is.
            var snippet = (target === source)
              ? '<img src="' + url + '" alt="' + (img.alt || '') + '" style="max-width:100%;height:auto;" />'
              : url;
            insertAtCursor(target, snippet);
            if (navigator.clipboard) { navigator.clipboard.writeText(url).catch(function () {}); }
            say('Image inserted — URL also copied');
          });
          picker.open();
        });
      }

      function insertAtCursor(el, text) {
        if (!el) { return; }
        if (el.hidden) { el = intro; }
        var start = el.selectionStart, end = el.selectionEnd;
        if (typeof start === 'number') {
          el.value = el.value.slice(0, start) + text + el.value.slice(end);
          el.selectionStart = el.selectionEnd = start + text.length;
        } else {
          el.value += text;
        }
        el.focus();
        el.dispatchEvent(new Event('input', { bubbles: true }));
      }
    });
  }

  ready(function () {
    document.querySelectorAll('.anchor-event-emails').forEach(init);
  });
})();
