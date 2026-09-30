/**
 * Anchor email kit builder. One body textarea is the source of truth; the Design tab
 * edits it through WordPress's TinyMCE, the HTML tab through the shared Monaco glue
 * (anchor-monaco.js keeps its own textarea in sync and fires `input`). Switching tabs
 * copies the body across. The preview is the consuming module's own AJAX renderer,
 * so what it shows is what will be sent.
 */
(function ($) {
  'use strict';

  var cfg = window.ANCHOR_EMAIL_KIT || {};

  function init(root) {
    var body      = root.querySelector('.anchor-email-builder__body');
    var source    = root.querySelector('.anchor-email-builder__source');
    var subject   = root.querySelector('.anchor-email-builder__subject');
    var preheader = root.querySelector('.anchor-email-builder__preheader');
    var frame     = root.querySelector('.anchor-email-builder__frame');
    var status    = root.querySelector('.anchor-email-builder__status');
    var view      = 'design';
    var timer     = null;
    var request   = 0;

    wp.editor.initialize(body.id, {
      tinymce: {
        wpautop: false,
        plugins: 'lists,link,paste,textcolor,colorpicker,hr,wordpress,wplink',
        toolbar1: 'formatselect,bold,italic,underline,forecolor,bullist,numlist,alignleft,aligncenter,link,unlink,hr,undo,redo',
        setup: function (ed) {
          ed.on('change keyup SetContent', schedule);
          ed.on('init', refresh);
        }
      },
      quicktags: false,
      mediaButtons: true
    });

    // Only a fully initialised editor: before init, getContent() returns '' and would blank the body.
    function editor() {
      var ed = window.tinymce ? window.tinymce.get(body.id) : null;
      return ed && ed.initialized ? ed : null;
    }

    function currentBody() {
      if (view === 'html') { return monacoValue(); }
      var ed = editor();
      return ed ? ed.getContent() : body.value;
    }

    // Lets a host page (Announcements) flush the active view into the body textarea before it reads or submits.
    root.anchorEmailBuilder = { sync: function () { body.value = currentBody(); return body.value; } };

    function monacoValue() {
      var ed = monacoEditor();
      return ed ? ed.getValue() : source.value;
    }

    function monacoEditor() {
      if (!window.monaco || !window.monaco.editor || !window.monaco.editor.getEditors) { return null; }
      var wrap = source.closest('.anchor-monaco');
      var found = null;
      window.monaco.editor.getEditors().forEach(function (e) {
        if (wrap && wrap.contains(e.getDomNode())) { found = e; }
      });
      return found;
    }

    function setView(next) {
      if (next === view) { return; }
      var html = currentBody();
      view = next;
      root.querySelectorAll('.anchor-email-builder__tab').forEach(function (tab) {
        var on = tab.getAttribute('data-view') === view;
        tab.classList.toggle('is-active', on);
        tab.setAttribute('aria-selected', on ? 'true' : 'false');
      });
      root.querySelector('.anchor-email-builder__design').hidden = view !== 'design';
      root.querySelector('.anchor-email-builder__html').hidden = view !== 'html';
      if (view === 'html') {
        source.value = html;
        var m = monacoEditor();
        if (m) { m.setValue(html); m.layout(); }
      } else {
        var ed = editor();
        if (ed) { ed.setContent(html); } else { body.value = html; }
      }
      body.value = html;
    }

    function insertToken(token) {
      if (view === 'html') {
        var m = monacoEditor();
        if (m) {
          m.executeEdits('anchor-email-kit', [{ range: m.getSelection(), text: token, forceMoveMarkers: true }]);
          m.focus();
        } else {
          source.value += token;
        }
      } else {
        var ed = editor();
        if (ed) { ed.execCommand('mceInsertContent', false, token); } else { body.value += token; }
      }
      schedule();
    }

    function schedule() {
      clearTimeout(timer);
      timer = setTimeout(refresh, 500);
    }

    function refresh() {
      body.value = currentBody();
      var mine = ++request;
      status.textContent = '…';
      var data = new FormData();
      data.set('action', cfg.previewAction || '');
      data.set('nonce', cfg.nonce || '');
      data.set('post_id', (document.getElementById('post_ID') || {}).value || '0');
      data.set('subject', subject.value);
      data.set('preheader', preheader.value);
      data.set('body', body.value);
      fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (mine !== request) { return; }
          if (res && res.success) {
            frame.srcdoc = res.data.html;
            status.textContent = '';
          } else {
            status.textContent = (res && res.data && res.data.message) || 'Preview failed';
          }
        })
        .catch(function () { if (mine === request) { status.textContent = 'Preview failed'; } });
    }

    root.querySelectorAll('.anchor-email-builder__tab').forEach(function (tab) {
      tab.addEventListener('click', function () { setView(tab.getAttribute('data-view')); });
    });
    root.querySelectorAll('.anchor-email-builder__token').forEach(function (btn) {
      btn.addEventListener('click', function () { insertToken(btn.getAttribute('data-token')); });
    });
    [subject, preheader].forEach(function (el) { el.addEventListener('input', schedule); });
    source.addEventListener('input', schedule);

    // The form submits the body textarea: make sure it holds the active view's HTML.
    var form = root.closest('form');
    if (form) { form.addEventListener('submit', function () { body.value = currentBody(); }); }

    // With TinyMCE the first refresh runs from its init event; without it, run now.
    if (!window.tinymce) { refresh(); }
  }

  $(function () {
    document.querySelectorAll('[data-anchor-email-builder]').forEach(init);
  });
})(jQuery);
