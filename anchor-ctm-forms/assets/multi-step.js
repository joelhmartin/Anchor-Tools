/* ══════════════════════════════════════════════════
   CTM Multi-Step Form — Auto-Detection Engine
   Plugin: Anchor CTM Forms

   No globals, no config objects, no inline init calls.
   Finds all form.ctm-multi-step on DOMContentLoaded
   and wires up step navigation automatically.
   ══════════════════════════════════════════════════ */
(function () {
  'use strict';

  var SVG_ARROW = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>';

  function initForm(form) {
    var titlePage = form.querySelector('.ctm-multi-step-title');
    var steps = Array.from(form.querySelectorAll('.ctm-multi-step-item'));

    if (steps.length === 0) return; // nothing to do

    var totalSteps = steps.length;
    var currentStep = 0;

    /* ── Build progress bar ── */
    var progressWrap = document.createElement('div');
    progressWrap.className = 'ctm-ms-progress';
    var progressFill = document.createElement('div');
    progressFill.className = 'ctm-ms-progress-fill';
    progressWrap.appendChild(progressFill);

    /* ── Build step counter ── */
    var stepCounter = document.createElement('div');
    stepCounter.className = 'ctm-ms-step-counter';

    /* ── Insert progress bar + counter before the first step (or title page) ── */
    var firstElement = titlePage || steps[0];
    form.insertBefore(stepCounter, firstElement);
    form.insertBefore(progressWrap, stepCounter);

    /* ── Inject Prev/Next buttons into each step ── */
    steps.forEach(function (step, idx) {
      var nav = document.createElement('div');
      nav.className = 'ctm-ms-nav';

      if (idx > 0) {
        var prevBtn = document.createElement('button');
        prevBtn.type = 'button';
        prevBtn.className = 'ctm-ms-prev';
        prevBtn.innerHTML = '&larr; Back';
        prevBtn.addEventListener('click', function () { goTo(idx - 1); });
        nav.appendChild(prevBtn);
      } else {
        // Spacer so Next aligns right on first step
        var spacer = document.createElement('div');
        spacer.className = 'ctm-ms-nav-spacer';
        nav.appendChild(spacer);
      }

      if (idx < totalSteps - 1) {
        var nextBtn = document.createElement('button');
        nextBtn.type = 'button';
        nextBtn.className = 'ctm-ms-next';
        nextBtn.innerHTML = 'Continue ' + SVG_ARROW;
        nextBtn.addEventListener('click', function () { goTo(idx + 1); });
        nav.appendChild(nextBtn);
      }
      // Last step: no Next — form's submit button handles it

      step.appendChild(nav);
    });

    /* ── Step navigation ── */
    function goTo(idx) {
      if (idx < 0 || idx >= totalSteps) return;

      // Hide current step
      steps[currentStep].classList.remove('active');

      // Show target step
      steps[idx].classList.add('active');

      currentStep = idx;
      updateProgress();
    }

    function updateProgress() {
      var pct = totalSteps > 1 ? ((currentStep + 1) / totalSteps) * 100 : 100;
      progressFill.style.width = pct + '%';
      stepCounter.textContent = 'Step ' + (currentStep + 1) + ' of ' + totalSteps;
    }

    /* ── Title page logic ── */
    if (titlePage) {
      // Show title page first, hide progress
      titlePage.classList.add('active');
      progressWrap.style.display = 'none';
      stepCounter.style.display = 'none';

      var startBtn = titlePage.querySelector('.ctm-ms-start');
      if (startBtn) {
        startBtn.addEventListener('click', function () {
          titlePage.classList.remove('active');
          progressWrap.style.display = '';
          stepCounter.style.display = '';
          goTo(0);
        });
      }
    } else {
      // No title page — show first step immediately
      goTo(0);
    }
  }

  /* ── Init on DOMContentLoaded ── */
  function onReady() {
    var forms = document.querySelectorAll('form.ctm-multi-step');
    for (var i = 0; i < forms.length; i++) {
      initForm(forms[i]);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', onReady);
  } else {
    onReady();
  }
})();
