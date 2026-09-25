(function() {
  'use strict';

  // ============================================================================
  // Video URL Builders
  // ============================================================================

  function buildYouTubeSrc(id, autoplay, start) {
    var params = new URLSearchParams({
      autoplay: autoplay ? '1' : '0',
      playsinline: '1',
      rel: '0',
      modestbranding: '1'
    });
    if (start > 0) params.set('start', String(start));
    return 'https://www.youtube.com/embed/' + encodeURIComponent(id) + '?' + params.toString();
  }

  function buildVimeoSrc(id, autoplay, start) {
    var params = new URLSearchParams({
      autoplay: autoplay ? '1' : '0',
      byline: '0',
      title: '0',
      portrait: '0',
      dnt: '1'
    });
    // Vimeo's start time is a URL fragment (#t=<n>s), not a query param.
    var fragment = start > 0 ? '#t=' + start + 's' : '';
    return 'https://player.vimeo.com/video/' + encodeURIComponent(id) + '?' + params.toString() + fragment;
  }

  function getVideoSrc(provider, id, autoplay, start) {
    start = parseInt(start, 10) || 0;
    if (provider === 'youtube') {
      return buildYouTubeSrc(id, autoplay, start);
    }
    if (provider === 'vimeo') {
      return buildVimeoSrc(id, autoplay, start);
    }
    return '';
  }

  function getDirectUrl(provider, id) {
    if (provider === 'youtube') {
      return 'https://www.youtube.com/watch?v=' + encodeURIComponent(id);
    }
    if (provider === 'vimeo') {
      return 'https://vimeo.com/' + encodeURIComponent(id);
    }
    return '';
  }

  // ============================================================================
  // Popup: Lightbox Modal
  // ============================================================================

  var lightboxModal = null;
  var lbState = { items: [], index: 0, opts: {}, autoplay: false, origin: null };

  function getLightboxModal() {
    if (lightboxModal) return lightboxModal;

    var modal = document.createElement('div');
    modal.className = 'avg-modal';
    modal.setAttribute('role', 'dialog');
    modal.setAttribute('aria-modal', 'true');
    modal.hidden = true;

    modal.innerHTML = [
      '<div class="avg-modal-backdrop" data-close></div>',
      '<div class="avg-modal-dialog">',
      '  <button type="button" class="avg-modal-close" aria-label="Close" data-close>&times;</button>',
      '  <button type="button" class="avg-modal-nav avg-modal-prev" aria-label="Previous item" data-prev>&#8249;</button>',
      '  <div class="avg-modal-frame" data-frame></div>',
      '  <button type="button" class="avg-modal-nav avg-modal-next" aria-label="Next item" data-next>&#8250;</button>',
      '  <div class="avg-modal-counter" aria-live="polite"></div>',
      '</div>'
    ].join('');

    document.body.appendChild(modal);
    lightboxModal = modal;

    modal.addEventListener('click', function(e) {
      if (e.target.closest('[data-prev]')) { renderLightboxItem(lbState.index - 1); return; }
      if (e.target.closest('[data-next]')) { renderLightboxItem(lbState.index + 1); return; }
      if (e.target.hasAttribute('data-close')) closeLightbox();
    });

    // Mirrors the carousel track's proven gesture handling (40px threshold,
    // bail out when vertical movement dominates so page scroll still works).
    (function initModalSwipe() {
      var dialog = modal.querySelector('.avg-modal-dialog');
      if (!dialog) return;
      var startX = 0, startY = 0, deltaX = 0, swiping = false;
      var threshold = 40;

      dialog.addEventListener('touchstart', function(e) {
        if (e.touches.length !== 1) { swiping = false; return; }
        startX = e.touches[0].clientX;
        startY = e.touches[0].clientY;
        deltaX = 0;
        swiping = true;
      }, { passive: true });

      dialog.addEventListener('touchmove', function(e) {
        if (!swiping) return;
        deltaX = e.touches[0].clientX - startX;
        var deltaY = e.touches[0].clientY - startY;
        if (Math.abs(deltaY) > Math.abs(deltaX)) swiping = false;
      }, { passive: true });

      dialog.addEventListener('touchend', function() {
        if (!swiping) return;
        swiping = false;
        if (Math.abs(deltaX) > threshold) {
          renderLightboxItem(lbState.index + (deltaX < 0 ? 1 : -1));
        }
      }, { passive: true });
    })();

    return modal;
  }

  function applyPopupOptions(dialog, frame, opts) {
    if (!dialog) return;
    // Reset previous overrides.
    dialog.style.maxWidth = '';
    if (frame) frame.style.aspectRatio = '';
    var caption = dialog.querySelector('.avg-popup-caption');
    if (caption) caption.remove();
    if (!opts) return;
    if (opts.maxWidth) dialog.style.maxWidth = opts.maxWidth;
    if (opts.aspect && frame) {
      // 'auto' means leave default. Map "16:9" -> "16 / 9".
      frame.style.aspectRatio = opts.aspect.replace(':', ' / ');
    }
    if (opts.caption) {
      var cap = document.createElement('div');
      cap.className = 'avg-popup-caption';
      cap.textContent = opts.caption;
      dialog.appendChild(cap);
    }
  }

  function updateLightboxNav() {
    var modal = getLightboxModal();
    var total = lbState.items.length;
    var prev = modal.querySelector('[data-prev]');
    var next = modal.querySelector('[data-next]');
    var counter = modal.querySelector('.avg-modal-counter');
    // A single-item gallery has nothing to navigate.
    if (prev) prev.hidden = total < 2;
    if (next) next.hidden = total < 2;
    if (counter) {
      counter.hidden = total < 2;
      counter.textContent = (lbState.index + 1) + ' / ' + total;
    }
  }

  // The ONE render path (every entry point, click, arrow, key, swipe, goes
  // through here), and the first thing it does is destroy the current frame.
  // Because playback is a raw autoplay iframe, that teardown IS the video stop.
  function renderLightboxItem(index) {
    var total = lbState.items.length;
    if (!total) return;

    var modal = getLightboxModal();
    var dialog = modal.querySelector('.avg-modal-dialog');
    var frame = modal.querySelector('[data-frame]');

    // Wrap around at both ends.
    index = ((index % total) + total) % total;
    lbState.index = index;
    var item = lbState.items[index];

    frame.innerHTML = '';
    dialog.classList.remove('avg-modal-image', 'avg-modal-html');

    if (item.type === 'image') {
      dialog.classList.add('avg-modal-image');
      // Built via the DOM, not string concatenation (the URL and alt text are
      // never interpolated into markup).
      var img = document.createElement('img');
      img.src = item.fullUrl;
      img.alt = item.alt || '';
      frame.appendChild(img);
    } else if (item.type === 'html') {
      dialog.classList.add('avg-modal-html');
      // Server-side wp_kses_post output, cloned from the tile.
      frame.innerHTML = item.html;
    } else {
      var src = getVideoSrc(item.provider, item.videoId, lbState.autoplay, item.start);
      frame.innerHTML = '<iframe src="' + src + '" allow="autoplay; fullscreen; picture-in-picture; clipboard-write; encrypted-media" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe>';
    }

    applyPopupOptions(dialog, frame, {
      maxWidth: lbState.opts.maxWidth,
      aspect: item.type === 'video' ? lbState.opts.aspect : '',
      caption: (lbState.opts.showCaption && item.caption) ? item.caption : ''
    });

    updateLightboxNav();
  }

  function openLightbox(sequence, startIndex, opts) {
    // Close any other open popups first (cross-module coordination).
    document.dispatchEvent(new CustomEvent('anchor-close-popups', { detail: { except: getLightboxModal() } }));

    var modal = getLightboxModal();
    lbState.items = sequence || [];
    lbState.opts = opts || {};
    lbState.autoplay = !!(opts && opts.autoplay);
    // The caller knows which tile was activated; don't infer it from focus.
    lbState.origin = (opts && opts.origin) || document.activeElement;

    renderLightboxItem(startIndex || 0);
    modal.hidden = false;
    document.body.style.overflow = 'hidden';

    var closeBtn = modal.querySelector('.avg-modal-close');
    if (closeBtn) closeBtn.focus();
  }

  function closeLightbox() {
    if (!lightboxModal) return;
    var frame = lightboxModal.querySelector('[data-frame]');
    if (frame) frame.innerHTML = '';
    var dialog = lightboxModal.querySelector('.avg-modal-dialog');
    if (dialog) dialog.classList.remove('avg-modal-image', 'avg-modal-html');
    lightboxModal.hidden = true;
    document.body.style.overflow = '';

    // Return focus to the tile that opened us.
    if (lbState.origin && typeof lbState.origin.focus === 'function') {
      lbState.origin.focus();
    }
    lbState.origin = null;
    lbState.items = [];
  }

  // ============================================================================
  // Keyboard Handler (lightbox only)
  // ============================================================================

  window.addEventListener('keydown', function(e) {
    // Arrow navigation only while the lightbox is actually open.
    if (lightboxModal && !lightboxModal.hidden) {
      if (e.key === 'ArrowLeft') {
        e.preventDefault();
        renderLightboxItem(lbState.index - 1);
        return;
      }
      if (e.key === 'ArrowRight') {
        e.preventDefault();
        renderLightboxItem(lbState.index + 1);
        return;
      }
    }

    // Trap Tab focus inside the open lightbox so it can't wander to the page
    // behind the backdrop.
    if (e.key === 'Tab' && lightboxModal && !lightboxModal.hidden) {
      var focusables = [];
      var candidates = lightboxModal.querySelectorAll('button, [href], iframe, input, select, textarea, [tabindex]:not([tabindex="-1"])');
      for (var i = 0; i < candidates.length; i++) {
        if (!candidates[i].hidden && candidates[i].offsetParent !== null) {
          focusables.push(candidates[i]);
        }
      }
      if (!focusables.length) return;

      var first = focusables[0];
      var last = focusables[focusables.length - 1];

      if (e.shiftKey && document.activeElement === first) {
        e.preventDefault();
        last.focus();
      } else if (!e.shiftKey && document.activeElement === last) {
        e.preventDefault();
        first.focus();
      }
      return;
    }

    if (e.key === 'Escape') {
      closeLightbox();
    }
  });

  // ============================================================================
  // Cross-Module Close Event (coordination with Universal Popups and other
  // gallery popup styles)
  // ============================================================================

  document.addEventListener('anchor-close-popups', function(e) {
    var except = e.detail && e.detail.except;
    // Close lightbox if not the excepted element
    if (lightboxModal && lightboxModal !== except && !lightboxModal.hidden) {
      closeLightbox();
    }
  });

  window.AnchorLightbox = {
    open: openLightbox,
    close: closeLightbox,
    isOpen: function () { return !!(lightboxModal && !lightboxModal.hidden); },
    getVideoSrc: getVideoSrc,
    getDirectUrl: getDirectUrl,
    applyPopupOptions: applyPopupOptions
  };

})();
