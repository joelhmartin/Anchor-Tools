(function() {
  'use strict';

  // ============================================================================
  // Shared Carousel / Slider
  // ============================================================================
  //
  // Generic transform-carousel + scroll-slider engine, driven entirely by the
  // `cfg` object passed to init(root, cfg) (elements, mode, columns, autoplay,
  // etc). Consumers own their own markup and CSS classes; this module reads
  // nothing from the DOM beyond what `cfg` hands it.

  function prefersReducedMotion() {
    return typeof window.matchMedia === 'function' &&
      window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  }

  function init(root, cfg) {
    cfg = cfg || {};

    var track = cfg.track;
    var items = cfg.items ? Array.prototype.slice.call(cfg.items) : [];
    var prevBtn = cfg.prev || null;
    var nextBtn = cfg.next || null;
    var dotsContainer = cfg.dotsContainer || null;
    var dotClass = cfg.dotClass || 'avg-dot';
    var mode = cfg.mode === 'slider' ? 'slider' : 'carousel';
    var loopEnabled = !!cfg.loop;
    var centerMode = !!cfg.center;
    var cols = cfg.cols || {};
    var gapVar = cfg.gapVar || '--avg-gap';

    var noop = function() {};
    if (!track || items.length === 0) {
      return { go: noop, next: noop, prev: noop, destroy: noop };
    }

    var currentIndex = 0;
    var dots = [];
    var boundListeners = [];
    var pendingTimers = [];
    var resizeObserver = null;
    var resizeTimer = null;
    var autoplayInterval = null;

    function on(el, evt, handler, opts) {
      if (!el) return;
      el.addEventListener(evt, handler, opts);
      boundListeners.push({ el: el, evt: evt, handler: handler, opts: opts });
    }

    /* -- Responsive visible count ------------------------------------ */

    function getVisibleCount() {
      var desktop = parseInt(cols.desktop, 10) || 3;
      var tablet = parseInt(cols.tablet, 10) || Math.min(desktop, 2);
      var mobile = parseInt(cols.mobile, 10) || 1;
      // Measure the root element, not the window, so a device-toolbar preview
      // (which resizes the preview frame, not the window) stays accurate.
      // Breakpoints match the frontend CSS: mobile <=767, tablet <=1023.
      var width = root.offsetWidth || root.getBoundingClientRect().width || window.innerWidth;
      if (width <= 767) return mobile;
      if (width <= 1023) return tablet;
      return desktop;
    }

    /* -- Carousel transform update ----------------------------------- */

    function updateCarousel() {
      if (mode !== 'carousel') return;

      var visibleCount = getVisibleCount();
      var itemWidth = items[0].offsetWidth;
      var gap = parseInt(getComputedStyle(root).getPropertyValue(gapVar), 10) || 16;
      var step = itemWidth + gap;
      var offset = currentIndex * step;

      // Center mode: shift so the active slide(s) sit in the middle.
      if (centerMode && items.length > visibleCount) {
        var containerWidth = root.offsetWidth;
        var groupWidth = visibleCount * itemWidth + (visibleCount - 1) * gap;
        var centerShift = (containerWidth - groupWidth) / 2;
        offset = currentIndex * step - centerShift;
        if (offset < 0) offset = 0;
      }

      track.style.transform = 'translateX(-' + offset + 'px)';

      items.forEach(function(item, i) {
        item.classList.toggle('active', i >= currentIndex && i < currentIndex + visibleCount);
      });

      if (loopEnabled) {
        if (prevBtn) prevBtn.disabled = false;
        if (nextBtn) nextBtn.disabled = false;
      } else {
        if (prevBtn) prevBtn.disabled = currentIndex === 0;
        if (nextBtn) nextBtn.disabled = currentIndex >= items.length - visibleCount;
      }

      updateDots();
    }

    /* -- Slide navigation ---------------------------------------------- */

    function goToSlide(index) {
      var visibleCount = getVisibleCount();
      // When there are fewer items than the responsive visible count (e.g.
      // 1-2 items with 3 desktop columns), items.length - visibleCount is
      // negative; clamp to 0 so a negative currentIndex never happens (it
      // would otherwise produce an invalid transform string and break the
      // active item/dot state).
      var maxIndex = Math.max(0, items.length - visibleCount);

      if (loopEnabled) {
        if (index > maxIndex) index = 0;
        else if (index < 0) index = maxIndex;
      } else {
        index = Math.max(0, Math.min(maxIndex, index));
      }

      currentIndex = index;
      updateCarousel();
    }

    var slidesToScroll = parseInt(cfg.slidesToScroll, 10) || 1;
    if (slidesToScroll < 1) slidesToScroll = 1;

    function scrollSlider(direction) {
      if (mode === 'slider') {
        var itemWidth = items[0].offsetWidth;
        var gap = parseInt(getComputedStyle(root).getPropertyValue(gapVar), 10) || 16;
        var scrollAmount = (itemWidth + gap) * direction * slidesToScroll;
        track.scrollBy({ left: scrollAmount, behavior: 'smooth' });
      } else {
        goToSlide(currentIndex + direction * slidesToScroll);
      }
    }

    on(prevBtn, 'click', function() { scrollSlider(-1); });
    on(nextBtn, 'click', function() { scrollSlider(1); });

    /* -- Dots navigation (one dot per item) ---------------------------- */

    function buildDots() {
      if (!dotsContainer || mode !== 'carousel') return;
      dotsContainer.innerHTML = '';
      dots = [];

      for (var i = 0; i < items.length; i++) {
        var dot = document.createElement('button');
        dot.className = dotClass + (i === currentIndex ? ' active' : '');
        dot.setAttribute('aria-label', 'Go to slide ' + (i + 1));
        dot.setAttribute('data-index', i);
        dots.push(dot);
        dotsContainer.appendChild(dot);
      }

      on(dotsContainer, 'click', function(e) {
        var d = e.target.closest('.' + dotClass);
        if (!d) return;
        goToSlide(parseInt(d.getAttribute('data-index'), 10));
      });
    }

    function updateDots() {
      for (var i = 0; i < dots.length; i++) {
        dots[i].classList.toggle('active', i === currentIndex);
      }
    }

    /* -- Drag / swipe support (Pointer Events) --------------------------- */
    //
    // A single Pointer Events implementation drives both mouse drag
    // (desktop) and touch swipe, replacing the previous touch-only handler
    // so there is one code path instead of two. touch-action: pan-y on the
    // track lets the browser keep handling vertical scrolling natively (a
    // swipe that turns out to be a vertical scroll is abandoned rather than
    // fought), and a drag that crosses the threshold suppresses the click
    // event the browser fires on release, so a link or video button inside
    // the dragged slide doesn't also activate.
    //
    // A drag only STARTS on the track (pointerdown listener below), but
    // pointermove/pointerup/pointercancel listen on `document`, gated by the
    // `dragging` flag, rather than using track.setPointerCapture(): capture
    // would keep the drag tracking reliably once the pointer leaves the
    // track's bounds, but it also retargets the browser's compatibility
    // "click" event on release to the capturing element for EVERY click, not
    // only a drag past the threshold - breaking every consumer's click
    // delegation (e.g. `e.target.closest('.anchor-testimonial__media')` in
    // testimonials.js, `.avg-tile` in the gallery), since the click's target
    // becomes the track itself instead of the tapped/clicked descendant.

    track.style.touchAction = 'pan-y';

    (function initDrag() {
      var startX = 0, startY = 0, deltaX = 0, dragging = false, activePointerId = null;
      var threshold = 40;

      function suppressNextClick() {
        on(track, 'click', function(e) {
          e.preventDefault();
          e.stopPropagation();
        }, { capture: true, once: true });
      }

      on(track, 'pointerdown', function(e) {
        if (e.pointerType === 'mouse' && e.button !== 0) return;
        startX = e.clientX;
        startY = e.clientY;
        deltaX = 0;
        dragging = true;
        activePointerId = e.pointerId;
      });

      on(document, 'pointermove', function(e) {
        if (!dragging || e.pointerId !== activePointerId) return;
        deltaX = e.clientX - startX;
        var deltaY = e.clientY - startY;
        // If vertical scroll is dominant, release the drag back to native
        // scrolling instead of fighting it.
        if (Math.abs(deltaY) > Math.abs(deltaX)) { dragging = false; return; }
        if (mode === 'carousel') e.preventDefault();
      }, { passive: false });

      function endDrag(e) {
        if (!dragging || e.pointerId !== activePointerId) return;
        dragging = false;
        if (Math.abs(deltaX) > threshold) {
          suppressNextClick();
          scrollSlider(deltaX < 0 ? 1 : -1);
        }
      }

      on(document, 'pointerup', endDrag);
      on(document, 'pointercancel', function(e) {
        if (e.pointerId !== activePointerId) return;
        dragging = false;
      });

      // Prevent the browser's native "drag an image out of the page"
      // gesture from hijacking a mouse drag that starts over a slide image.
      on(track, 'dragstart', function(e) { e.preventDefault(); });
    })();

    /* -- Keyboard navigation --------------------------------------------- */

    root.setAttribute('tabindex', '0');
    on(root, 'keydown', function(e) {
      if (e.key === 'ArrowLeft') { e.preventDefault(); scrollSlider(-1); }
      if (e.key === 'ArrowRight') { e.preventDefault(); scrollSlider(1); }
    });

    /* -- Initialize carousel ---------------------------------------------- */

    if (mode === 'carousel') {
      buildDots();
      updateCarousel();

      var scheduleUpdate = function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(updateCarousel, 100);
      };
      on(window, 'resize', scheduleUpdate);

      // Observe the root so a device-toolbar preview (which only resizes the
      // preview frame, not the window) triggers a re-measure.
      if (typeof ResizeObserver !== 'undefined') {
        resizeObserver = new ResizeObserver(scheduleUpdate);
        resizeObserver.observe(root);
      }
    }

    /* -- Autoplay with pause/resume ---------------------------------------- */

    // Reduced-motion visitors never get autoplay, regardless of cfg.
    var autoplayEnabled = !!cfg.autoplay && mode === 'carousel' && !prefersReducedMotion();
    var autoplaySpeed = parseInt(cfg.autoplaySpeed, 10) || 5000;

    function startAutoplay() {
      stopAutoplay();
      autoplayInterval = setInterval(function() {
        goToSlide(currentIndex + slidesToScroll);
      }, autoplaySpeed);
    }

    function stopAutoplay() {
      if (autoplayInterval) { clearInterval(autoplayInterval); autoplayInterval = null; }
    }

    if (autoplayEnabled) {
      // pauseOnHover defaults to true when not explicitly set to false.
      var pauseOnHover = cfg.pauseOnHover !== false;

      startAutoplay();

      if (pauseOnHover) {
        on(root, 'mouseenter', stopAutoplay);
        on(root, 'mouseleave', startAutoplay);
      }
      on(root, 'pointerdown', stopAutoplay);
      on(root, 'pointerup', function() {
        pendingTimers.push(setTimeout(startAutoplay, 3000));
      });
    }

    /* -- Teardown ------------------------------------------------------------ */

    function destroy() {
      stopAutoplay();
      clearTimeout(resizeTimer);
      pendingTimers.forEach(clearTimeout);
      pendingTimers = [];
      if (resizeObserver) { resizeObserver.disconnect(); resizeObserver = null; }
      boundListeners.forEach(function(l) {
        l.el.removeEventListener(l.evt, l.handler, l.opts);
      });
      boundListeners = [];
    }

    return {
      go: goToSlide,
      next: function() { scrollSlider(1); },
      prev: function() { scrollSlider(-1); },
      destroy: destroy
    };
  }

  window.AnchorCarousel = { init: init };

})();
