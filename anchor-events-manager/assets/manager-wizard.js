/**
 * Multi-step wizard for the front-end manager form ([event_manager steps="yes"]).
 *
 * Progressive enhancement: the server renders every section, and this script
 * groups them by the data-step attribute PHP stamped on each one. If the script
 * never runs, the form stays a single long page that submits exactly as before.
 *
 * Validation is done here rather than by the browser because a `required` field
 * inside a hidden step cannot be focused — the browser refuses to submit and
 * reports nothing the user can see. So the form is marked novalidate and each
 * step is checked with checkValidity() before advancing, with the first invalid
 * field focused and its message shown inline.
 *
 * No jQuery: this file is enqueued with no dependencies.
 */
(function () {
  'use strict';

  function init(form) {
    var sections = Array.prototype.slice.call(form.querySelectorAll('[data-step]'));
    if (!sections.length) { return; }

    var rail = form.querySelector('.anchor-event-steps');
    var nav = form.querySelector('.anchor-event-wizard-nav');
    var submit = form.querySelector('.anchor-event-manager-submit');
    var back = form.querySelector('[data-wizard-back]');
    var next = form.querySelector('[data-wizard-next]');
    var where = form.querySelector('.anchor-event-wizard-where');
    if (!nav || !back || !next) { return; }

    // Ordered unique step numbers actually present in the DOM, so a step whose
    // sections were all filtered out server-side simply does not appear.
    var steps = sections
      .map(function (el) { return parseInt(el.getAttribute('data-step'), 10); })
      .filter(function (n, i, all) { return n && all.indexOf(n) === i; })
      .sort(function (a, b) { return a - b; });

    var current = steps[0];

    form.classList.add('is-wizard');
    form.setAttribute('novalidate', 'novalidate');
    nav.hidden = false;

    function fieldsIn(step) {
      var out = [];
      sections.forEach(function (el) {
        if (parseInt(el.getAttribute('data-step'), 10) !== step) { return; }
        // A conditional section switched off by the type/mode logic is not part of
        // the form the user is filling in, so its fields must not block them.
        // Test that logic's own inline display, not offsetParent — every section
        // of a step that is not currently on screen has a null offsetParent, so
        // offsetParent would also skip conditionals that ARE switched on whenever
        // we validate a step from the submit handler.
        if (el.classList.contains('anchor-event-conditional') && el.style.display === 'none') { return; }
        out = out.concat(Array.prototype.slice.call(
          el.querySelectorAll('input, select, textarea')
        ));
      });
      return out.filter(function (f) { return !f.disabled && f.type !== 'hidden'; });
    }

    function clearErrors(scope) {
      Array.prototype.forEach.call(scope.querySelectorAll('.anchor-event-error'), function (e) {
        e.parentNode.removeChild(e);
      });
      Array.prototype.forEach.call(scope.querySelectorAll('.has-error'), function (e) {
        e.classList.remove('has-error');
      });
    }

    function showError(field) {
      var holder = field.closest('.anchor-event-field') || field.parentNode;
      holder.classList.add('has-error');
      if (holder.querySelector('.anchor-event-error')) { return; }
      var msg = document.createElement('p');
      msg.className = 'anchor-event-error';
      msg.textContent = field.validationMessage || 'This field is required.';
      holder.appendChild(msg);
    }

    /** @return {boolean} true when every visible field in the step is valid. */
    function validate(step) {
      var bad = null;
      fieldsIn(step).forEach(function (field) {
        var holder = field.closest('.anchor-event-field') || field.parentNode;
        clearErrors(holder);
        if (!field.checkValidity()) {
          showError(field);
          if (!bad) { bad = field; }
        }
      });
      if (bad) {
        bad.focus();
        if (bad.scrollIntoView) { bad.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
        return false;
      }
      return true;
    }

    // `moved` is false only for the first paint, so the page does not jump on load.
    function render(moved) {
      sections.forEach(function (el) {
        el.hidden = parseInt(el.getAttribute('data-step'), 10) !== current;
      });

      if (rail) {
        Array.prototype.forEach.call(rail.querySelectorAll('[data-step-nav]'), function (li) {
          var n = parseInt(li.getAttribute('data-step-nav'), 10);
          li.classList.toggle('is-current', n === current);
          li.classList.toggle('is-done', n < current);
        });
      }

      var idx = steps.indexOf(current);
      var last = idx === steps.length - 1;
      back.hidden = idx === 0;
      next.hidden = last;
      if (submit) { submit.hidden = !last; }
      if (where) {
        where.textContent = 'Step ' + (idx + 1) + ' of ' + steps.length;
      }
      if (moved) { form.scrollIntoView({ block: 'start', behavior: 'smooth' }); }
    }

    function go(delta) {
      var idx = steps.indexOf(current);
      // Forward moves must earn their way past validation; going back never does.
      if (delta > 0 && !validate(current)) { return; }
      var target = steps[idx + delta];
      if (typeof target === 'undefined') { return; }
      current = target;
      render(true);
    }

    next.addEventListener('click', function () { go(1); });
    back.addEventListener('click', function () { go(-1); });

    if (rail) {
      rail.addEventListener('click', function (e) {
        var li = e.target.closest('[data-step-nav]');
        if (!li) { return; }
        var target = parseInt(li.getAttribute('data-step-nav'), 10);
        if (target === current) { return; }
        // Jumping ahead still has to pass everything in between, so the rail can
        // never be used to skip a required field.
        if (target > current) {
          for (var i = steps.indexOf(current); i < steps.indexOf(target); i++) {
            if (!validate(steps[i])) { current = steps[i]; render(true); return; }
          }
        }
        current = target;
        render(true);
      });
    }

    // Final guard. The submit button only exists on the last step, but a stray
    // Enter keypress can still submit from anywhere — re-check every step and
    // land the user on the first one that fails.
    form.addEventListener('submit', function (e) {
      for (var i = 0; i < steps.length; i++) {
        if (!validate(steps[i])) {
          e.preventDefault();
          current = steps[i];
          render(true);
          return;
        }
      }
    });

    render(false);
  }

  document.addEventListener('DOMContentLoaded', function () {
    Array.prototype.forEach.call(
      document.querySelectorAll('.anchor-event-manager-form'),
      init
    );
  });
})();
