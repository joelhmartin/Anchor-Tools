/**
 * The registration-mode visibility rule, shared by admin.js (wp-admin
 * metabox) and manager.js (front-end console) so the two forms cannot
 * disagree about which sections a mode shows.
 *
 * "Use external signup form" (#anchor_event_external_signup) wins; otherwise
 * the Registration select (wc|free) decides. A container's data-when-mode
 * lists the modes it shows for (space-separated); none means always.
 *
 * Plain script, no dependencies: it also loads under Node for the test
 * harness (tests/js/registration-mode-harness.js).
 */
(function (root) {
  'use strict';
  var api = {
    effective: function (externalChecked, selectValue) {
      if (externalChecked) { return 'external'; }
      return selectValue ? String(selectValue) : 'free';
    },
    matches: function (whenMode, mode) {
      if (!whenMode) { return true; }
      return String(whenMode).split(/\s+/).indexOf(mode) !== -1;
    }
  };
  root.AnchorEventsRegistrationMode = api;
  if (typeof module !== 'undefined' && module.exports) { module.exports = api; }
})(typeof window !== 'undefined' ? window : this);
