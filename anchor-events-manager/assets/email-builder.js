/**
 * Task 3.2 — "Emails" metabox: Monaco editor + token palette + live preview +
 * AJAX real-data preview, per lifecycle-email type (confirmation / reminder /
 * cancellation / roster). Cloned from the anchor-blocks admin.js house
 * pattern (srcdoc live preview, debounce) — see anchor-blocks/assets/admin.js.
 *
 * The shared anchor-monaco.js glue (assets/anchor-monaco.js) creates one
 * Monaco editor per `.anchor-monaco[data-anchor-monaco]` wrapper found on
 * the page (this metabox renders four, one per email-type tab) and keeps
 * each editor's hidden textarea in sync: on every edit it writes
 * `textarea.value` and dispatches a bubbling `input` event on it. This file
 * never touches Monaco editor creation — it only listens for that `input`
 * event (live preview) and, for token-insert / reset, resolves the specific
 * `monaco.editor` instance whose DOM node lives inside the same
 * `.anchor-monaco` wrapper as the target textarea via the Monaco 0.52
 * `monaco.editor.getEditors()` static API, falling back to plain textarea
 * cursor/`value` manipulation when Monaco failed to load (AnchorMonaco.active
 * unset — see anchor-monaco.js's revertToTextareas()).
 */
(function ($) {
  'use strict';

  var debounceTimers = {};

  function panelTextarea(type) {
    return document.getElementById('anchor_email_tpl_' + type);
  }

  function previewFrame(type) {
    return document.querySelector('.anchor-email-preview-frame[data-email-type="' + type + '"]');
  }

  /** Raw client-side preview: tokens shown literally, instant feedback. */
  function buildDoc(type) {
    var ta = panelTextarea(type);
    var html = ta ? ta.value : '';
    return '<!DOCTYPE html><html><head><meta charset="utf-8">' +
      '<meta name="viewport" content="width=device-width, initial-scale=1">' +
      (window.AnchorPreview ? window.AnchorPreview.headMarkup() : '') +
      '</head><body>' + html + '</body></html>';
  }

  function applyPreview(type) {
    var frame = previewFrame(type);
    if (frame) { frame.srcdoc = buildDoc(type); }
  }

  function applyPreviewDebounced(type) {
    clearTimeout(debounceTimers[type]);
    debounceTimers[type] = setTimeout(function () { applyPreview(type); }, 250);
  }

  /** Find the monaco.editor instance whose DOM node lives in the same .anchor-monaco wrapper as `ta`. */
  function findMonacoEditorFor(ta) {
    if (!ta || !window.AnchorMonaco || !window.AnchorMonaco.active) { return null; }
    if (!window.monaco || !window.monaco.editor || typeof window.monaco.editor.getEditors !== 'function') { return null; }
    var wrap = ta.closest('.anchor-monaco');
    if (!wrap) { return null; }
    var editors = window.monaco.editor.getEditors();
    for (var i = 0; i < editors.length; i++) {
      var node = editors[i].getDomNode && editors[i].getDomNode();
      if (node && node.closest && node.closest('.anchor-monaco') === wrap) {
        return editors[i];
      }
    }
    return null;
  }

  function insertToken(type, token) {
    var ta = panelTextarea(type);
    if (!ta) { return; }
    var ed = findMonacoEditorFor(ta);
    if (ed) {
      ed.focus();
      var sel = ed.getSelection() || new window.monaco.Selection(1, 1, 1, 1);
      ed.executeEdits('anchor-email-token', [{ range: sel, text: token, forceMoveMarkers: true }]);
      // onDidChangeContent (wired in anchor-monaco.js) mirrors ta.value and
      // dispatches 'input' for us — nothing further to do here.
      return;
    }
    // Fallback: Monaco unavailable, plain textarea in use.
    var start = ta.selectionStart != null ? ta.selectionStart : ta.value.length;
    var end = ta.selectionEnd != null ? ta.selectionEnd : ta.value.length;
    ta.value = ta.value.slice(0, start) + token + ta.value.slice(end);
    ta.selectionStart = ta.selectionEnd = start + token.length;
    ta.focus();
    ta.dispatchEvent(new Event('input', { bubbles: true }));
  }

  function resetToDefault(type) {
    var ta = panelTextarea(type);
    if (!ta) { return; }
    var def = (window.AnchorEmailBuilder && AnchorEmailBuilder.defaults && AnchorEmailBuilder.defaults[type]) || '';
    var ed = findMonacoEditorFor(ta);
    if (ed) {
      ed.setValue(def); // triggers onDidChangeContent -> ta.value sync + 'input' dispatch.
      return;
    }
    ta.value = def;
    ta.dispatchEvent(new Event('input', { bubbles: true }));
  }

  function previewWithRealData(type) {
    if (!window.AnchorEmailBuilder) { return; }
    var ta = panelTextarea(type);
    var template = ta ? ta.value : '';
    var $btn = $('.anchor-email-preview-real[data-email-type="' + type + '"]');
    var original = $btn.text();
    $btn.prop('disabled', true).text(AnchorEmailBuilder.loadingLabel || 'Loading…');

    $.post(AnchorEmailBuilder.ajaxUrl, {
      action: 'anchor_events_email_preview',
      nonce: AnchorEmailBuilder.nonce,
      event_id: AnchorEmailBuilder.postId,
      type: type,
      template: template
    }).done(function (response) {
      if (response && response.success && response.data && typeof response.data.html === 'string') {
        var frame = previewFrame(type);
        if (frame) { frame.srcdoc = response.data.html; }
      }
    }).always(function () {
      $btn.prop('disabled', false).text(original);
    });
  }

  function switchTab(type) {
    $('.anchor-email-tab').removeClass('is-active');
    $('.anchor-email-tab[data-email-type="' + type + '"]').addClass('is-active');
    $('.anchor-email-panel').hide();
    $('.anchor-email-panel[data-email-type="' + type + '"]').show();
    // Monaco's automaticLayout option (set for every editor in anchor-monaco.js)
    // self-corrects a display:none -> block size change; refresh the raw
    // preview too so a newly-shown tab isn't left showing a stale/blank frame.
    applyPreview(type);
  }

  $(document).ready(function () {
    if (!$('.anchor-email-builder').length) { return; }

    $('.anchor-email-tab').each(function () { applyPreview($(this).data('email-type')); });

    $(document).on('click', '.anchor-email-tab', function () {
      switchTab($(this).data('email-type'));
    });

    $(document).on('input', '.anchor-email-panel textarea[id^="anchor_email_tpl_"]', function () {
      applyPreviewDebounced($(this).closest('.anchor-email-panel').data('email-type'));
    });

    $(document).on('click', '.anchor-email-token', function () {
      var type = $(this).closest('.anchor-email-panel').data('email-type');
      insertToken(type, $(this).data('token'));
    });

    $(document).on('click', '.anchor-email-reset', function () {
      resetToDefault($(this).data('email-type'));
    });

    $(document).on('click', '.anchor-email-preview-real', function () {
      previewWithRealData($(this).data('email-type'));
    });
  });
})(jQuery);
