/**
 * Per-event email builder for the front-end manager form.
 *
 * One modal per email type: the wording on the left, a rendered preview on the
 * right, and an HTML tab holding the whole template. The preview is the
 * plugin's own anchor_events_email_preview endpoint, which renders the real
 * template against real event data, so what the panel shows is what the email
 * will be — including the subject and opening lines currently typed but not yet
 * saved.
 *
 * The opening lines are edited in WordPress's own TinyMCE, attached to the
 * textarea on first open. That is a deliberate replacement for a third-party
 * visual page builder that used to sit behind a "Design" tab: it round-tripped
 * hand-built email HTML through its own component model and lost markup on
 * every visit, it rendered {tokens} as editable text with no way to resolve
 * them, and its asset manager was a second place to put files that WordPress
 * knew nothing about. TinyMCE edits only the prose region — the part that is
 * actually per-event — and the HTML tab remains the way to rebuild the whole
 * document.
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
        // Collapse runs of whitespace inside a text node. HTML collapses them
        // when it renders, so this changes nothing about the email — but it is
        // the difference between "{greeting}          {intro}" straggling across
        // the editor and a line you can read.
        var text = part.replace(/\s+/g, ' ').trim();
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
        out.push(pad(depth) + part + parts[i + 1].replace(/\s+/g, ' ').trim() + parts[i + 2]);
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

  /** WordPress's media library. One picker for every caller in this file. */
  function openMedia(onPick, onFail) {
    if (!window.wp || !wp.media) { if (onFail) { onFail(); } return; }
    var picker = wp.media({
      title: 'Choose an image',
      library: { type: 'image' },
      multiple: false,
      button: { text: 'Use this image' }
    });
    picker.on('select', function () {
      var img = picker.state().get('selection').first().toJSON();
      onPick(img.sizes && img.sizes.large ? img.sizes.large.url : img.url, img);
    });
    picker.open();
  }

  function init(wrap) {
    var eventId = wrap.getAttribute('data-event') || '0';
    var form    = wrap.closest('form');

    wrap.querySelectorAll('[data-email-modal]').forEach(function (modal) {
      var type    = modal.getAttribute('data-email-modal');
      var subject = modal.querySelector('[data-email-field="subject"]');
      var intro   = modal.querySelector('[data-email-field="intro"]');
      var source  = modal.querySelector('.anchor-event-email-source');
      var frame   = modal.querySelector('.anchor-event-email-frame');
      var status  = modal.querySelector('.anchor-event-email-status');
      var timer   = null;
      var view    = 'preview';
      var mce     = null;            // the TinyMCE instance, once attached
      var lastFocused = intro;

      function say(msg) { if (status) { status.textContent = msg || ''; } }

      /** The opening lines as they stand right now, editor or textarea. */
      function introValue() {
        if (mce && !mce.isHidden()) { return mce.getContent(); }
        return intro ? intro.value : '';
      }

      // ---------------------------------------------------------------- preview

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
        body.set('intro', introValue());

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
        if (!el) { return; }
        el.addEventListener('focus', function () { lastFocused = el; });
        el.addEventListener('input', renderSoon);
      });

      // ------------------------------------------------------- visual editor

      /**
       * Attach WordPress's editor to the opening-lines textarea.
       *
       * Deferred to the first open: TinyMCE measures its iframe at init, and a
       * <dialog> that has never been shown has no layout — an eager instance
       * comes up zero-height. Four of these (one per email type) on every event
       * edit would also be four editors nobody asked for.
       */
      function mountEditor() {
        if (mce || !intro || !window.wp || !wp.editor || !window.tinymce) { return; }
        var id = intro.id;

        try {
          wp.editor.initialize(id, {
            quicktags: false,
            mediaButtons: false,
            tinymce: {
              wpautop: true,
              menubar: false,
              statusbar: false,
              branding: false,
              height: 220,
              toolbar1: 'bold,italic,bullist,numlist,link,unlink,anchorimage,removeformat,undo,redo',
              toolbar2: '',
              setup: function (ed) {
                mce = ed;

                // Our own image button, wired to the WordPress media library.
                // The alternative — the editor's stock image dialog — asks for a
                // URL and knows nothing about the site's uploads.
                ed.addButton('anchorimage', {
                  icon: 'image',
                  tooltip: 'Insert image from library',
                  onclick: function () {
                    openMedia(function (url, img) {
                      ed.insertContent('<img src="' + url + '" alt="' + (img.alt || '') +
                        '" style="max-width:100%;height:auto;" />');
                    }, function () { say('Media library unavailable'); });
                  }
                });

                // Everything typed in the editor lands back in the textarea the
                // form posts, and drives the live preview. One source of truth:
                // the editor never holds state the save path cannot see.
                ed.on('keyup change SetContent ExecCommand NodeChange', function () {
                  ed.save();
                  renderSoon();
                });
                ed.on('focus', function () { lastFocused = intro; });
              }
            }
          });
        } catch (e) {
          mce = null;   // plain textarea still works; nothing is lost
        }
      }

      // TinyMCE keeps its content in an iframe until told otherwise, so the
      // textarea would post whatever it held at page load without this.
      if (form) {
        form.addEventListener('submit', function () {
          if (mce) { mce.save(); }
        });
      }

      // --------------------------------------------------------- open / close

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
          mountEditor();
          render();
        });
      });
      modal.querySelectorAll('.anchor-event-email-close').forEach(function (btn) {
        btn.addEventListener('click', function () {
          if (mce) { mce.save(); }
          if (typeof modal.close === 'function') { modal.close(); }
          else { modal.removeAttribute('open'); }
        });
      });

      // ------------------------------------------------------ Preview / HTML

      modal.querySelectorAll('[data-email-view]').forEach(function (tab) {
        tab.addEventListener('click', function () {
          view = tab.getAttribute('data-email-view');
          modal.querySelectorAll('[data-email-view]').forEach(function (t) {
            t.classList.toggle('is-active', t === tab);
          });
          if (source) { source.hidden = view !== 'html'; }
          if (frame)  { frame.hidden  = view !== 'preview'; }

          // A token only resolves in the field whose expansion pass knows the
          // key, so the palette shows the set that belongs to the open view.
          modal.querySelectorAll('[data-token-scope]').forEach(function (group) {
            var scope = group.getAttribute('data-token-scope');
            group.hidden = (view === 'html') ? scope !== 'template' : scope !== 'wording';
          });
          if (view === 'html') { lastFocused = source; }
          if (view === 'preview') {
            if (mce) { mce.save(); }
            lastFocused = intro;
            render();
          }
        });
      });

      // ------------------------------------------------------- token palette

      modal.querySelectorAll('.anchor-event-token').forEach(function (btn) {
        var key = (btn.getAttribute('data-token') || '').replace(/[{}]/g, '');
        var val = (cfg.tokens || {})[key];
        // Show what the token resolves to for this event, on hover.
        if (val) { btn.title = '{' + key + '} → ' + val; }

        btn.addEventListener('click', function () {
          insertToken(btn.getAttribute('data-token') || '');
        });
      });

      function insertToken(text) {
        // In the HTML view the target is the source; otherwise it is whichever
        // wording field was last touched, and the editor takes it as content.
        if (view === 'html') { insertAtCursor(source, text); return; }
        if (lastFocused === subject) { insertAtCursor(subject, text); return; }
        if (mce && !mce.isHidden()) {
          mce.insertContent(text);
          mce.save();
          renderSoon();
          return;
        }
        insertAtCursor(intro, text);
      }

      // media library -> insert an <img> at the cursor in the HTML view
      var media = modal.querySelector('.anchor-event-email-media');
      if (media) {
        media.addEventListener('click', function () {
          openMedia(function (url, img) {
            insertAtCursor(source, '<img src="' + url + '" alt="' + (img.alt || '') +
              '" style="max-width:100%;height:auto;" />');
            if (navigator.clipboard) { navigator.clipboard.writeText(url).catch(function () {}); }
            say('Image inserted — URL also copied');
          }, function () { say('Media library unavailable'); });
        });
      }

      function insertAtCursor(el, text) {
        if (!el) { return; }
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
