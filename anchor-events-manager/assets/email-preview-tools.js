/**
 * Shared helpers for the two email-template editors (the wp-admin metabox in
 * email-builder.js and the console modal in email-modal.js):
 *
 *  - guardSource(textarea): the template textarea is rendered pre-filled with
 *    the resolved template, so a plain submit would post every full HTML
 *    document on every save (and trip WAF XSS rules on fields the person never
 *    touched). It is disabled -- disabled fields are not submitted, and the
 *    server leaves the stored template alone for a field that is not posted --
 *    and enabled the first time its content is actually edited (an `input`
 *    event: typing, token insert, media insert, "Reset to default").
 *    Setting .value from script fires no event, so reformatting on open does
 *    not count as an edit.
 *  - bindDevices(group, frame): Desktop / Tablet / Mobile toggles that set the
 *    preview iframe's width so the email's own @media rules apply.
 */
(function () {
  'use strict';

  var WIDTHS = { desktop: '100%', tablet: '768px', mobile: '375px' };

  function guardSource(ta) {
    if (!ta || ta.dataset.editGuard) { return; }
    ta.dataset.editGuard = '1';
    ta.disabled = true;
    ta.addEventListener('input', function () { ta.disabled = false; });
  }

  function setDevice(group, frame, device) {
    var width = WIDTHS[device] || WIDTHS.desktop;
    frame.style.width = width;
    frame.style.maxWidth = '100%';
    frame.style.marginLeft = 'auto';
    frame.style.marginRight = 'auto';
    frame.style.alignSelf = 'center';
    frame.classList.toggle('is-device-frame', device !== 'desktop');
    frame.setAttribute('data-device', device);
    group.querySelectorAll('[data-email-device]').forEach(function (btn) {
      btn.setAttribute('aria-pressed', btn.getAttribute('data-email-device') === device ? 'true' : 'false');
      btn.classList.toggle('is-active', btn.getAttribute('data-email-device') === device);
    });
  }

  function bindDevices(group, frame) {
    if (!group || !frame || group.dataset.deviceBound) { return; }
    group.dataset.deviceBound = '1';
    group.querySelectorAll('[data-email-device]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        setDevice(group, frame, btn.getAttribute('data-email-device'));
      });
    });
    setDevice(group, frame, 'desktop');
  }

  window.AnchorEmailPreviewTools = { guardSource: guardSource, bindDevices: bindDevices, widths: WIDTHS };
})();
