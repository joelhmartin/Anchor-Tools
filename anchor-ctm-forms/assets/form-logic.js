/* ══════════════════════════════════════════════════
   CTM Form Logic — Frontend Runtime
   Plugin: Anchor CTM Forms

   Handles conditional field visibility and scoring.
   Auto-initializes on all .ctm-form-wrap elements.
   ══════════════════════════════════════════════════ */
(function () {
  'use strict';

  function initFormLogic(wrap) {
    var form = wrap.querySelector('form');
    if (!form) return;

    var conditionalFields = wrap.querySelectorAll('[data-conditions]');
    var hasScoringAttr = wrap.querySelector('[data-scoring]');
    var hasConditionals = conditionalFields.length > 0;
    var scoring = null;

    if (hasScoringAttr) {
      try {
        scoring = JSON.parse(hasScoringAttr.getAttribute('data-scoring'));
      } catch (e) { /* ignore */ }
    }

    if (!hasConditionals && !scoring) return;

    /* ── Build field map: data-field-id → input element(s) ── */
    var fieldMap = {};
    var allFieldEls = wrap.querySelectorAll('[data-field-id]');
    for (var i = 0; i < allFieldEls.length; i++) {
      var el = allFieldEls[i];
      var fid = el.getAttribute('data-field-id');
      fieldMap[fid] = el;
    }

    /* ── Get value of a field by its data-field-id ── */
    function getFieldValue(fieldId) {
      var container = fieldMap[fieldId];
      if (!container) return '';

      // Radio: find checked
      var radios = container.querySelectorAll('input[type="radio"]');
      if (radios.length > 0) {
        for (var r = 0; r < radios.length; r++) {
          if (radios[r].checked) return radios[r].value;
        }
        return '';
      }

      // Checkbox: comma-joined checked values
      var checkboxes = container.querySelectorAll('input[type="checkbox"]');
      if (checkboxes.length > 0) {
        var vals = [];
        for (var c = 0; c < checkboxes.length; c++) {
          if (checkboxes[c].checked) vals.push(checkboxes[c].value);
        }
        return vals.join(',');
      }

      // Select
      var sel = container.querySelector('select');
      if (sel) return sel.value;

      // Textarea
      var ta = container.querySelector('textarea');
      if (ta) return ta.value;

      // Input (text, email, tel, number, url, hidden)
      var inp = container.querySelector('input');
      if (inp) return inp.value;

      return '';
    }

    /* ── Evaluate a single condition ── */
    function evalCondition(cond) {
      var val = getFieldValue(cond.field);
      var target = cond.value || '';

      switch (cond.operator) {
        case 'equals':
          return val === target;
        case 'not_equals':
          return val !== target;
        case 'contains':
          return val.indexOf(target) !== -1;
        case 'is_empty':
          return val === '';
        case 'is_not_empty':
          return val !== '';
        case 'greater_than':
          return parseFloat(val) > parseFloat(target);
        case 'less_than':
          return parseFloat(val) < parseFloat(target);
        default:
          return false;
      }
    }

    /* ── Evaluate all conditions for a field ── */
    function evalConditions(el) {
      var condJson = el.getAttribute('data-conditions');
      var logic = el.getAttribute('data-condition-logic') || 'all';
      var conditions;

      try {
        conditions = JSON.parse(condJson);
      } catch (e) {
        return true;
      }

      if (!conditions || conditions.length === 0) return true;

      if (logic === 'any') {
        for (var i = 0; i < conditions.length; i++) {
          if (evalCondition(conditions[i])) return true;
        }
        return false;
      }

      // 'all'
      for (var j = 0; j < conditions.length; j++) {
        if (!evalCondition(conditions[j])) return false;
      }
      return true;
    }

    /* ── Show/hide conditional fields ── */
    function updateConditionals() {
      for (var i = 0; i < conditionalFields.length; i++) {
        var el = conditionalFields[i];
        var show = evalConditions(el);

        el.style.display = show ? '' : 'none';

        // Disable hidden inputs so they don't submit
        var inputs = el.querySelectorAll('input, select, textarea');
        for (var j = 0; j < inputs.length; j++) {
          inputs[j].disabled = !show;
        }
      }
    }

    /* ── Scoring ── */
    var scoreDisplay = wrap.querySelector('.ctm-score-display');
    var scoreInput = wrap.querySelector('.ctm-score-input');

    function updateScoring() {
      if (!scoring) return;

      var total = 0;
      var scoredEls = wrap.querySelectorAll('[data-score]');

      for (var i = 0; i < scoredEls.length; i++) {
        var el = scoredEls[i];
        var score = parseFloat(el.getAttribute('data-score')) || 0;

        if (el.tagName === 'OPTION') {
          // Select option
          if (el.selected) total += score;
        } else if (el.type === 'checkbox' || el.type === 'radio') {
          if (el.checked) total += score;
        }
      }

      if (scoreDisplay) {
        scoreDisplay.textContent = total;
      }
      if (scoreInput) {
        scoreInput.value = total;
      }
    }

    /* ── Bind events ── */
    form.addEventListener('change', function () {
      if (hasConditionals) updateConditionals();
      if (scoring) updateScoring();
    });

    form.addEventListener('input', function () {
      if (hasConditionals) updateConditionals();
    });

    // Initial evaluation
    if (hasConditionals) updateConditionals();
    if (scoring) updateScoring();
  }

  /* ── Init on DOM ready ── */
  function onReady() {
    var wraps = document.querySelectorAll('.ctm-form-wrap');
    for (var i = 0; i < wraps.length; i++) {
      initFormLogic(wraps[i]);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', onReady);
  } else {
    onReady();
  }
})();
