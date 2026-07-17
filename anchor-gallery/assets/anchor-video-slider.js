(function() {
  'use strict';

  // ============================================================================
  // Video URL Builders
  // ============================================================================

  function buildYouTubeSrc(id, autoplay) {
    var params = new URLSearchParams({
      autoplay: autoplay ? '1' : '0',
      playsinline: '1',
      rel: '0',
      modestbranding: '1'
    });
    return 'https://www.youtube.com/embed/' + encodeURIComponent(id) + '?' + params.toString();
  }

  function buildVimeoSrc(id, autoplay) {
    var params = new URLSearchParams({
      autoplay: autoplay ? '1' : '0',
      byline: '0',
      title: '0',
      portrait: '0',
      dnt: '1'
    });
    return 'https://player.vimeo.com/video/' + encodeURIComponent(id) + '?' + params.toString();
  }

  function getVideoSrc(provider, id, autoplay) {
    if (provider === 'youtube') {
      return buildYouTubeSrc(id, autoplay);
    }
    if (provider === 'vimeo') {
      return buildVimeoSrc(id, autoplay);
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
  // Lightbox Sequence Collection
  // ============================================================================

  // Filters hide via .is-hidden, pagination via .avg-hidden — two different
  // classes that both resolve to display:none. offsetParent catches both
  // without hardcoding either, and correctly does NOT treat carousel tiles as
  // hidden, since those keep layout boxes and merely scroll out of view.
  function isTileVisible(el) {
    return !!(el && el.offsetParent !== null);
  }

  function readTile(tile) {
    var type = tile.getAttribute('data-type') || 'video';
    var item = {
      type: type,
      tile: tile,
      caption: tile.getAttribute('data-caption') || ''
    };

    if (type === 'image') {
      item.fullUrl = tile.getAttribute('data-full-url') || '';
      var img = tile.querySelector('.avg-thumb-img');
      item.alt = img ? (img.getAttribute('alt') || '') : '';
    } else if (type === 'html') {
      var content = tile.querySelector('.avg-html-content');
      item.html = content ? content.innerHTML : '';
    } else {
      item.provider = tile.getAttribute('data-provider') || '';
      item.videoId = tile.getAttribute('data-video-id') || '';
      item.url = tile.getAttribute('data-url') || '';
    }

    return item;
  }

  function collectSequence(gallery, clicked) {
    // render_gallery_layout() emits a thumb strip plus one featured tile. The
    // strip is the real sequence; a click on the featured tile maps to the
    // currently-active thumb.
    var isGalleryLayout = !!gallery.querySelector('.avg-gallery-thumb');
    var nodes, target = clicked;

    if (isGalleryLayout) {
      nodes = gallery.querySelectorAll('.avg-gallery-thumb');
      if (clicked && clicked.classList.contains('avg-gallery-featured')) {
        target = gallery.querySelector('.avg-gallery-thumb.active') || nodes[0];
      }
    } else {
      // .avg-tile-linked is an <a> and only exists when popup_style is 'none',
      // which never coexists with a lightbox — excluded defensively.
      nodes = gallery.querySelectorAll('.avg-tile:not(.avg-tile-linked)');
    }

    var items = [];
    var startIndex = 0;
    for (var i = 0; i < nodes.length; i++) {
      if (!isTileVisible(nodes[i])) continue;
      if (nodes[i] === target) startIndex = items.length;
      items.push(readTile(nodes[i]));
    }
    return { items: items, startIndex: startIndex };
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

  // The ONE render path. Every entry point — click, arrow, key, swipe — goes
  // through here, and the first thing it does is destroy the current frame.
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
      // Built via the DOM, not string concatenation — the URL and alt text are
      // never interpolated into markup.
      var img = document.createElement('img');
      img.src = item.fullUrl;
      img.alt = item.alt || '';
      frame.appendChild(img);
    } else if (item.type === 'html') {
      dialog.classList.add('avg-modal-html');
      // Server-side wp_kses_post output, cloned from the tile.
      frame.innerHTML = item.html;
    } else {
      var src = getVideoSrc(item.provider, item.videoId, lbState.autoplay);
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
  // Popup: Theater Mode
  // ============================================================================

  var theaterEl = null;

  function getTheaterEl() {
    if (theaterEl) return theaterEl;

    var theater = document.createElement('div');
    theater.className = 'avg-theater';
    theater.setAttribute('role', 'dialog');
    theater.setAttribute('aria-modal', 'true');
    theater.hidden = true;

    theater.innerHTML = [
      '<div class="avg-theater-header">',
      '  <span class="avg-theater-title" data-title></span>',
      '  <button type="button" class="avg-theater-close" aria-label="Close" data-close>&times;</button>',
      '</div>',
      '<div class="avg-theater-frame" data-frame></div>'
    ].join('');

    document.body.appendChild(theater);
    theaterEl = theater;

    theater.addEventListener('click', function(e) {
      if (e.target.hasAttribute('data-close')) {
        closeTheater();
      }
    });

    return theater;
  }

  function openTheater(provider, id, autoplay, title, opts) {
    // Close any other open popups first (cross-module coordination)
    document.dispatchEvent(new CustomEvent('anchor-close-popups', { detail: { except: getTheaterEl() } }));

    var theater = getTheaterEl();
    var frame = theater.querySelector('[data-frame]');
    var titleEl = theater.querySelector('[data-title]');
    var src = getVideoSrc(provider, id, autoplay);

    titleEl.textContent = title || '';
    frame.innerHTML = '<iframe src="' + src + '" allow="autoplay; fullscreen; picture-in-picture; clipboard-write; encrypted-media" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe>';
    applyPopupOptions(theater, frame, opts);
    theater.hidden = false;
    document.body.style.overflow = 'hidden';

    var closeBtn = theater.querySelector('.avg-theater-close');
    if (closeBtn) closeBtn.focus();
  }

  function closeTheater() {
    if (!theaterEl) return;
    var frame = theaterEl.querySelector('[data-frame]');
    if (frame) frame.innerHTML = '';
    theaterEl.hidden = true;
    document.body.style.overflow = '';
  }

  // ============================================================================
  // Popup: Side Panel
  // ============================================================================

  var sidePanelEl = null;
  var sidePanelBackdrop = null;

  function getSidePanelEl() {
    if (sidePanelEl) return sidePanelEl;

    var backdrop = document.createElement('div');
    backdrop.className = 'avg-side-panel-backdrop';
    document.body.appendChild(backdrop);
    sidePanelBackdrop = backdrop;

    var panel = document.createElement('div');
    panel.className = 'avg-side-panel';
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-modal', 'true');
    panel.hidden = true;

    panel.innerHTML = [
      '<div class="avg-side-panel-header">',
      '  <span class="avg-side-panel-title" data-title></span>',
      '  <button type="button" class="avg-side-panel-close" aria-label="Close" data-close>&times;</button>',
      '</div>',
      '<div class="avg-side-panel-frame" data-frame></div>',
      '<div class="avg-side-panel-content" data-content></div>'
    ].join('');

    document.body.appendChild(panel);
    sidePanelEl = panel;

    backdrop.addEventListener('click', closeSidePanel);
    panel.addEventListener('click', function(e) {
      if (e.target.hasAttribute('data-close')) {
        closeSidePanel();
      }
    });

    return panel;
  }

  function openSidePanel(provider, id, autoplay, title, opts) {
    // Close any other open popups first (cross-module coordination)
    document.dispatchEvent(new CustomEvent('anchor-close-popups', { detail: { except: getSidePanelEl() } }));

    var panel = getSidePanelEl();
    var frame = panel.querySelector('[data-frame]');
    var titleEl = panel.querySelector('[data-title]');
    var src = getVideoSrc(provider, id, autoplay);

    titleEl.textContent = title || '';
    frame.innerHTML = '<iframe src="' + src + '" allow="autoplay; fullscreen; picture-in-picture; clipboard-write; encrypted-media" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe>';
    applyPopupOptions(panel, frame, opts);

    panel.hidden = false;
    sidePanelBackdrop.classList.add('visible');
    document.body.style.overflow = 'hidden';

    // Animate in
    requestAnimationFrame(function() {
      panel.classList.add('open');
    });

    var closeBtn = panel.querySelector('.avg-side-panel-close');
    if (closeBtn) closeBtn.focus();
  }

  function closeSidePanel() {
    if (!sidePanelEl) return;

    sidePanelEl.classList.remove('open');
    sidePanelBackdrop.classList.remove('visible');

    setTimeout(function() {
      var frame = sidePanelEl.querySelector('[data-frame]');
      if (frame) frame.innerHTML = '';
      sidePanelEl.hidden = true;
      document.body.style.overflow = '';
    }, 350);
  }

  // ============================================================================
  // Popup: Inline Expand
  // ============================================================================

  var currentInlineGallery = null;
  var currentInlineTile = null;

  function openInline(gallery, tile, provider, id, autoplay, opts) {
    // Close any other open popups first (cross-module coordination)
    document.dispatchEvent(new CustomEvent('anchor-close-popups', { detail: { except: null } }));

    // Close any existing inline player
    closeInline();

    var track = gallery.querySelector('.avg-track');
    var src = getVideoSrc(provider, id, autoplay);

    // Create inline player
    var player = document.createElement('div');
    player.className = 'avg-inline-player';
    player.innerHTML = [
      '<button type="button" class="avg-inline-close" aria-label="Close">&times;</button>',
      '<iframe src="' + src + '" allow="autoplay; fullscreen; picture-in-picture; clipboard-write; encrypted-media" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe>'
    ].join('');
    if (opts) {
      if (opts.maxWidth) player.style.maxWidth = opts.maxWidth;
      if (opts.aspect) {
        var iframeEl = player.querySelector('iframe');
        if (iframeEl) iframeEl.style.aspectRatio = opts.aspect.replace(':', ' / ');
      }
      if (opts.caption) {
        var cap = document.createElement('div');
        cap.className = 'avg-popup-caption';
        cap.textContent = opts.caption;
        player.appendChild(cap);
      }
    }

    // Insert before the tile
    track.insertBefore(player, tile);

    tile.classList.add('avg-inline-expanded');
    currentInlineGallery = gallery;
    currentInlineTile = tile;

    player.querySelector('.avg-inline-close').addEventListener('click', closeInline);

    // Scroll into view
    player.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }

  function closeInline() {
    if (!currentInlineGallery) return;

    var player = currentInlineGallery.querySelector('.avg-inline-player');
    if (player) {
      player.remove();
    }

    if (currentInlineTile) {
      currentInlineTile.classList.remove('avg-inline-expanded');
    }

    currentInlineGallery = null;
    currentInlineTile = null;
  }

  // ============================================================================
  // Global Keyboard Handler
  // ============================================================================

  window.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
      closeLightbox();
      closeTheater();
      closeSidePanel();
      closeInline();
      // Also handle legacy modal
      var legacyModal = document.querySelector('.anchor-video-modal:not([hidden])');
      if (legacyModal) {
        var frame = legacyModal.querySelector('[data-frame]');
        if (frame) frame.innerHTML = '';
        legacyModal.hidden = true;
      }
    }
  });

  // ============================================================================
  // Cross-Module Close Event (coordination with Universal Popups)
  // ============================================================================

  document.addEventListener('anchor-close-popups', function(e) {
    var except = e.detail && e.detail.except;
    // Close lightbox if not the excepted element
    if (lightboxModal && lightboxModal !== except && !lightboxModal.hidden) {
      closeLightbox();
    }
    // Close theater if not the excepted element
    if (theaterEl && theaterEl !== except && !theaterEl.hidden) {
      closeTheater();
    }
    // Close side panel if not the excepted element
    if (sidePanelEl && sidePanelEl !== except && !sidePanelEl.hidden) {
      closeSidePanel();
    }
    // Always close inline (it doesn't have a persistent element to compare)
    closeInline();
  });

  // ============================================================================
  // Tile Click Handler
  // ============================================================================

  function handleTileClick(e) {
    var tile = e.target.closest('.avg-tile');
    if (!tile) return;

    var gallery = tile.closest('.anchor-video-gallery');
    if (!gallery) return;

    var popupStyle = gallery.getAttribute('data-popup') || 'lightbox';

    // 3.7.x — popup_style = 'none' means NO popup. If the user wired up an
    // anchor (Link URL per item, rendered as <a class="avg-tile-linked">), the
    // browser handles navigation natively — we don't intercept. Otherwise we
    // do nothing on click — no auto-opening of the raw image/video URL.
    if (popupStyle === 'none') {
      return;
    }

    var itemType = tile.getAttribute('data-type') || 'video';

    // HTML tiles host arbitrary shortcode output — links, forms, embeds. Never
    // intercept their clicks. They stay reachable via lightbox navigation, but
    // clicking them does whatever their own markup says.
    if (itemType === 'html') return;

    e.preventDefault();

    if (popupStyle === 'lightbox') {
      var seq = collectSequence(gallery, tile);
      if (!seq.items.length) return;
      var showCaption = gallery.getAttribute('data-popup-caption');
      if (showCaption === null) showCaption = '1';
      openLightbox(seq.items, seq.startIndex, {
        maxWidth: gallery.getAttribute('data-popup-max-width') || '',
        aspect: gallery.getAttribute('data-popup-aspect') || '',
        showCaption: showCaption === '1',
        autoplay: gallery.getAttribute('data-autoplay') === '1',
        origin: tile
      });
      return;
    }

    // Theater / side panel / inline keep their existing single-item behavior.
    if (itemType === 'image') return;

    var provider = tile.getAttribute('data-provider');
    var videoId = tile.getAttribute('data-video-id');
    var autoplay = gallery.getAttribute('data-autoplay') === '1';
    var titleEl = tile.querySelector('.avg-title');
    var titleText = titleEl ? titleEl.textContent : '';

    if (!provider || !videoId) return;

    var captionAttr = tile.getAttribute('data-caption') || '';
    var showCap = gallery.getAttribute('data-popup-caption');
    if (showCap === null) showCap = '1';
    var popupOpts = {
      maxWidth: gallery.getAttribute('data-popup-max-width') || '',
      aspect: gallery.getAttribute('data-popup-aspect') || '',
      caption: (showCap === '1' && captionAttr) ? captionAttr : ''
    };

    switch (popupStyle) {
      case 'inline':
        openInline(gallery, tile, provider, videoId, autoplay, popupOpts);
        break;
      case 'theater':
        openTheater(provider, videoId, autoplay, titleText, popupOpts);
        break;
      case 'side_panel':
        openSidePanel(provider, videoId, autoplay, titleText, popupOpts);
        break;
    }
  }

  document.addEventListener('click', handleTileClick);

  // role="button" tiles are <div>s — Enter/Space don't fire click natively.
  document.addEventListener('keydown', function(e) {
    if (e.key !== 'Enter' && e.key !== ' ' && e.key !== 'Spacebar') return;
    if (!e.target || !e.target.closest) return;
    var tile = e.target.closest('.avg-tile[role="button"]');
    if (!tile) return;
    e.preventDefault();
    tile.click();
  });

  // ============================================================================
  // Slider/Carousel Navigation
  // ============================================================================

  function initSliderNavigation(gallery) {
    var layout = gallery.getAttribute('data-layout');
    if (layout !== 'slider' && layout !== 'carousel') return;

    var track = gallery.querySelector('.avg-track');
    var prevBtn = gallery.querySelector('.avg-nav-prev');
    var nextBtn = gallery.querySelector('.avg-nav-next');
    var tiles = gallery.querySelectorAll('.avg-tile');

    if (!track || tiles.length === 0) return;

    var currentIndex = 0;
    var loopEnabled = gallery.getAttribute('data-loop') === '1';
    var centerMode = gallery.getAttribute('data-center') === '1';

    /* -- Responsive visible count ------------------------------------ */

    function getVisibleCount() {
      var cols = parseInt(gallery.getAttribute('data-cols-desktop')) || 3;
      var colsTablet = parseInt(gallery.getAttribute('data-cols-tablet')) || Math.min(cols, 2);
      var colsMobile = parseInt(gallery.getAttribute('data-cols-mobile')) || 1;
      // Measure the gallery container, not the window — so the builder's
      // device-toolbar (which resizes the preview frame, not the window)
      // produces a faithful preview. Breakpoints match the frontend CSS:
      // mobile <=767, tablet <=1023, desktop above.
      var width = gallery.offsetWidth || gallery.getBoundingClientRect().width || window.innerWidth;
      if (width <= 767) return colsMobile;
      if (width <= 1023) return colsTablet;
      return cols;
    }

    /* -- Carousel transform update ----------------------------------- */

    function updateCarousel() {
      if (layout !== 'carousel') return;

      var visibleCount = getVisibleCount();
      var tileWidth = tiles[0].offsetWidth;
      var gap = parseInt(getComputedStyle(gallery).getPropertyValue('--avg-gap')) || 16;
      var step = tileWidth + gap;
      var offset = currentIndex * step;

      // Center mode: shift so the active slide(s) sit in the middle
      if (centerMode && tiles.length > visibleCount) {
        var containerWidth = gallery.offsetWidth;
        var groupWidth = visibleCount * tileWidth + (visibleCount - 1) * gap;
        var centerShift = (containerWidth - groupWidth) / 2;
        offset = currentIndex * step - centerShift;
        if (offset < 0) offset = 0;
      }

      track.style.transform = 'translateX(-' + offset + 'px)';

      // Update active / inactive states
      tiles.forEach(function(tile, i) {
        tile.classList.toggle('active', i >= currentIndex && i < currentIndex + visibleCount);
      });

      // Update arrow states (loop = never disabled)
      if (loopEnabled) {
        if (prevBtn) prevBtn.disabled = false;
        if (nextBtn) nextBtn.disabled = false;
      } else {
        if (prevBtn) prevBtn.disabled = currentIndex === 0;
        if (nextBtn) nextBtn.disabled = currentIndex >= tiles.length - visibleCount;
      }

      // Sync dots
      updateDots();
    }

    /* -- Slide navigation -------------------------------------------- */

    function goToSlide(index) {
      var visibleCount = getVisibleCount();
      var maxIndex = tiles.length - visibleCount;

      if (loopEnabled) {
        if (index > maxIndex) index = 0;
        else if (index < 0) index = maxIndex;
      } else {
        index = Math.max(0, Math.min(maxIndex, index));
      }

      currentIndex = index;
      updateCarousel();
    }

    var slidesToScroll = parseInt(gallery.getAttribute('data-slides-to-scroll'), 10) || 1;
    if (slidesToScroll < 1) slidesToScroll = 1;

    function scrollSlider(direction) {
      if (layout === 'slider') {
        var tileWidth = tiles[0].offsetWidth;
        var gap = parseInt(getComputedStyle(gallery).getPropertyValue('--avg-gap')) || 16;
        var scrollAmount = (tileWidth + gap) * direction * slidesToScroll;
        track.scrollBy({ left: scrollAmount, behavior: 'smooth' });
      } else {
        goToSlide(currentIndex + direction * slidesToScroll);
      }
    }

    if (prevBtn) {
      prevBtn.addEventListener('click', function() { scrollSlider(-1); });
    }

    if (nextBtn) {
      nextBtn.addEventListener('click', function() { scrollSlider(1); });
    }

    /* -- Dots navigation (one dot per slide) ------------------------- */

    var dotsContainer = gallery.querySelector('.avg-dots');
    var dots = [];

    function buildDots() {
      if (!dotsContainer || layout !== 'carousel') return;
      dotsContainer.innerHTML = '';
      dots = [];

      for (var i = 0; i < tiles.length; i++) {
        var dot = document.createElement('button');
        dot.className = 'avg-dot' + (i === currentIndex ? ' active' : '');
        dot.setAttribute('aria-label', 'Go to slide ' + (i + 1));
        dot.setAttribute('data-index', i);
        dots.push(dot);
        dotsContainer.appendChild(dot);
      }

      dotsContainer.addEventListener('click', function(e) {
        var d = e.target.closest('.avg-dot');
        if (!d) return;
        goToSlide(parseInt(d.getAttribute('data-index')));
      });
    }

    function updateDots() {
      for (var i = 0; i < dots.length; i++) {
        dots[i].classList.toggle('active', i === currentIndex);
      }
    }

    /* -- Touch / swipe support --------------------------------------- */

    (function initTouch() {
      var startX = 0, startY = 0, deltaX = 0, swiping = false;
      var threshold = 40;

      track.addEventListener('touchstart', function(e) {
        startX = e.touches[0].clientX;
        startY = e.touches[0].clientY;
        deltaX = 0;
        swiping = true;
      }, { passive: true });

      track.addEventListener('touchmove', function(e) {
        if (!swiping) return;
        deltaX = e.touches[0].clientX - startX;
        var deltaY = e.touches[0].clientY - startY;
        // If vertical scroll is dominant, release the swipe
        if (Math.abs(deltaY) > Math.abs(deltaX)) { swiping = false; return; }
        if (layout === 'carousel') e.preventDefault();
      }, { passive: false });

      track.addEventListener('touchend', function() {
        if (!swiping) return;
        swiping = false;
        if (Math.abs(deltaX) > threshold) {
          scrollSlider(deltaX < 0 ? 1 : -1);
        }
      }, { passive: true });
    })();

    /* -- Keyboard navigation ----------------------------------------- */

    gallery.setAttribute('tabindex', '0');
    gallery.addEventListener('keydown', function(e) {
      if (e.key === 'ArrowLeft') { e.preventDefault(); scrollSlider(-1); }
      if (e.key === 'ArrowRight') { e.preventDefault(); scrollSlider(1); }
    });

    /* -- Initialize carousel ----------------------------------------- */

    if (layout === 'carousel') {
      buildDots();
      updateCarousel();

      var resizeTimer;
      var scheduleUpdate = function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(updateCarousel, 100);
      };
      window.addEventListener('resize', scheduleUpdate);

      // Observe the gallery container so the builder's device-toolbar
      // (which only resizes the preview frame, not the window) triggers
      // a re-measure.
      if (typeof ResizeObserver !== 'undefined') {
        var ro = new ResizeObserver(scheduleUpdate);
        ro.observe(gallery);
      }
    }

    /* -- Autoplay with pause/resume ---------------------------------- */

    var autoplayEnabled = gallery.getAttribute('data-slider-autoplay') === '1';
    var autoplaySpeed = parseInt(gallery.getAttribute('data-autoplay-speed')) || 5000;

    if (autoplayEnabled && layout === 'carousel') {
      var autoplayInterval = null;
      // data-pause-on-hover defaults to '1' when missing for backwards compat.
      var pauseHoverAttr = gallery.getAttribute('data-pause-on-hover');
      var pauseOnHover = pauseHoverAttr === null ? true : pauseHoverAttr === '1';

      function startAutoplay() {
        stopAutoplay();
        autoplayInterval = setInterval(function() {
          goToSlide(currentIndex + slidesToScroll);
        }, autoplaySpeed);
      }

      function stopAutoplay() {
        if (autoplayInterval) { clearInterval(autoplayInterval); autoplayInterval = null; }
      }

      startAutoplay();

      if (pauseOnHover) {
        gallery.addEventListener('mouseenter', stopAutoplay);
        gallery.addEventListener('mouseleave', startAutoplay);
      }
      gallery.addEventListener('touchstart', stopAutoplay, { passive: true });
      gallery.addEventListener('touchend', function() {
        setTimeout(startAutoplay, 3000);
      }, { passive: true });
    }
  }

  // ============================================================================
  // Gallery Layout (Featured + Strip)
  // ============================================================================

  // Inject a <link> tag into <head> once, identified by href.
  function ensureLink(rel, href, as) {
    var sel = 'link[rel="' + rel + '"][href="' + href + '"]';
    if (document.querySelector(sel)) return;
    var link = document.createElement('link');
    link.rel = rel;
    link.href = href;
    if (as) link.setAttribute('as', as);
    document.head.appendChild(link);
  }

  function initGalleryLayout(gallery) {
    var featured = gallery.querySelector('.avg-gallery-featured');
    var strip = gallery.querySelector('.avg-gallery-strip');
    var thumbs = gallery.querySelectorAll('.avg-gallery-thumb');
    var prevBtn = gallery.querySelector('.avg-nav-prev');
    var nextBtn = gallery.querySelector('.avg-nav-next');

    if (!featured || !strip || thumbs.length === 0) return;

    function setFeatured(index) {
      var thumb = thumbs[index];
      if (!thumb) return;

      // Update active strip state
      thumbs.forEach(function(t) { t.classList.remove('active'); });
      thumb.classList.add('active');

      // Pull data from the clicked thumb
      var provider  = thumb.getAttribute('data-provider') || '';
      var videoId   = thumb.getAttribute('data-video-id') || '';
      var url       = thumb.getAttribute('data-url') || '';
      var fullUrl   = thumb.getAttribute('data-full-url') || '';
      var thumbUrl  = thumb.getAttribute('data-thumb') || '';
      var label     = thumb.getAttribute('data-label') || '';
      var duration  = thumb.getAttribute('data-duration') || '';
      var type      = thumb.getAttribute('data-type') || 'video';

      // Update featured data attrs (used by handleTileClick for popup)
      featured.setAttribute('data-index', index);
      featured.setAttribute('data-type', type);
      featured.setAttribute('data-provider', provider);
      featured.setAttribute('data-video-id', videoId);
      featured.setAttribute('data-url', url);
      if (fullUrl) {
        featured.setAttribute('data-full-url', fullUrl);
      } else {
        featured.removeAttribute('data-full-url');
      }

      // Update featured thumbnail. Markup renders the image as a real
      // <img class="avg-thumb-img"> child, so swap its src; fall back to a
      // CSS background-image for any legacy markup that lacks the <img>.
      var featImg = featured.querySelector('.avg-thumb-img');
      if (featImg && thumbUrl) {
        featImg.src = thumbUrl;
        featImg.alt = label;
      } else {
        var featThumb = featured.querySelector('.avg-thumb');
        if (featThumb && thumbUrl) {
          featThumb.style.backgroundImage = "url('" + thumbUrl + "')";
        }
      }

      // Toggle the featured play button for video/image switches.
      var playEl = featured.querySelector('.avg-play');
      if (playEl) {
        playEl.style.display = type === 'image' ? 'none' : '';
      }

      // Update duration badge
      var durationEl = featured.querySelector('.avg-duration');
      if (durationEl) {
        durationEl.textContent = duration;
        durationEl.style.display = duration ? '' : 'none';
      }

      // Update title
      var titleEl = featured.querySelector('.avg-gallery-featured-title');
      if (titleEl) {
        titleEl.textContent = label;
      }

      // Scroll active thumb into view within the strip
      thumb.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'nearest' });
    }

    // Thumbnail clicks
    thumbs.forEach(function(thumb, i) {
      thumb.setAttribute('tabindex', '0');
      thumb.setAttribute('role', 'button');
      thumb.addEventListener('click', function() { setFeatured(i); });
      thumb.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          setFeatured(i);
        }
      });
    });

    // Strip nav arrows scroll the strip
    if (prevBtn) {
      prevBtn.addEventListener('click', function() {
        strip.scrollBy({ left: -200, behavior: 'smooth' });
      });
    }
    if (nextBtn) {
      nextBtn.addEventListener('click', function() {
        strip.scrollBy({ left: 200, behavior: 'smooth' });
      });
    }

    // Lazy-preload video embeds: when gallery nears the viewport, issue
    // preconnect + prefetch hints so browsers start the handshake before
    // anyone clicks play. Strip thumbnails are already in the DOM and load
    // as normal CSS background-images, so no special handling needed.
    if ('IntersectionObserver' in window) {
      var preloadObserver = new IntersectionObserver(function(entries) {
        if (!entries[0].isIntersecting) return;
        preloadObserver.unobserve(gallery);

        // Preconnect to video CDN origins
        ensureLink('preconnect', 'https://www.youtube-nocookie.com');
        ensureLink('preconnect', 'https://player.vimeo.com');
        ensureLink('dns-prefetch', 'https://i.ytimg.com');

        // Prefetch each video's embed URL
        thumbs.forEach(function(thumb) {
          var provider = thumb.getAttribute('data-provider');
          var videoId  = thumb.getAttribute('data-video-id');
          if (!provider || !videoId) return;

          var embedUrl = provider === 'youtube'
            ? 'https://www.youtube-nocookie.com/embed/' + encodeURIComponent(videoId)
            : 'https://player.vimeo.com/video/' + encodeURIComponent(videoId);

          ensureLink('prefetch', embedUrl, 'document');
        });
      }, { rootMargin: '200px' });

      preloadObserver.observe(gallery);
    }
  }

  // ============================================================================
  // Pagination
  // ============================================================================

  function initPagination(gallery) {
    var pagination = gallery.querySelector('.avg-pagination');
    if (!pagination) return;

    var paginationStyle = gallery.getAttribute('data-pagination');
    var total = parseInt(pagination.getAttribute('data-total')) || 0;
    var perPage = parseInt(pagination.getAttribute('data-per-page')) || 12;
    var tiles = gallery.querySelectorAll('.avg-tile');
    var currentPage = 1;
    var totalPages = Math.ceil(total / perPage);

    function showPage(page) {
      currentPage = page;
      var start = (page - 1) * perPage;
      var end = start + perPage;

      tiles.forEach(function(tile, i) {
        tile.classList.toggle('avg-hidden', i < start || i >= end);
      });

      updatePaginationUI();
    }

    function updatePaginationUI() {
      var numbersContainer = pagination.querySelector('.avg-pagination-numbers');
      if (!numbersContainer) return;

      numbersContainer.innerHTML = '';

      // Previous button
      var prevBtn = document.createElement('button');
      prevBtn.className = 'avg-page-btn';
      prevBtn.textContent = '\u2190';
      prevBtn.disabled = currentPage === 1;
      prevBtn.addEventListener('click', function() {
        if (currentPage > 1) showPage(currentPage - 1);
      });
      numbersContainer.appendChild(prevBtn);

      // Page numbers
      for (var i = 1; i <= totalPages; i++) {
        var btn = document.createElement('button');
        btn.className = 'avg-page-btn' + (i === currentPage ? ' active' : '');
        btn.textContent = i;
        btn.setAttribute('data-page', i);
        numbersContainer.appendChild(btn);
      }

      // Next button
      var nextBtn = document.createElement('button');
      nextBtn.className = 'avg-page-btn';
      nextBtn.textContent = '\u2192';
      nextBtn.disabled = currentPage === totalPages;
      nextBtn.addEventListener('click', function() {
        if (currentPage < totalPages) showPage(currentPage + 1);
      });
      numbersContainer.appendChild(nextBtn);

      // Click handler for page numbers
      numbersContainer.addEventListener('click', function(e) {
        var btn = e.target.closest('.avg-page-btn[data-page]');
        if (!btn) return;
        showPage(parseInt(btn.getAttribute('data-page')));
      });
    }

    // Load More button
    var loadMoreBtn = pagination.querySelector('.avg-load-more');
    if (loadMoreBtn && paginationStyle === 'load_more') {
      var shown = perPage;

      loadMoreBtn.addEventListener('click', function() {
        shown += perPage;
        tiles.forEach(function(tile, i) {
          if (i < shown) {
            tile.classList.remove('avg-hidden');
          }
        });

        if (shown >= total) {
          loadMoreBtn.disabled = true;
          loadMoreBtn.textContent = 'All Loaded';
        }
      });
    }

    // Infinite scroll
    if (paginationStyle === 'infinite') {
      var shown = perPage;
      var loading = false;

      function checkScroll() {
        if (loading || shown >= total) return;

        var rect = gallery.getBoundingClientRect();
        var isNearBottom = rect.bottom < window.innerHeight + 200;

        if (isNearBottom) {
          loading = true;
          shown += perPage;

          tiles.forEach(function(tile, i) {
            if (i < shown) {
              tile.classList.remove('avg-hidden');
            }
          });

          setTimeout(function() {
            loading = false;
          }, 100);
        }
      }

      window.addEventListener('scroll', checkScroll, { passive: true });
      checkScroll();
    }

    // Initialize numbered pagination
    if (paginationStyle === 'numbered') {
      updatePaginationUI();
    }
  }

  // ============================================================================
  // Initialize Galleries
  // ============================================================================

  function initGalleries() {
    var galleries = document.querySelectorAll('.anchor-video-gallery');
    galleries.forEach(function(gallery) {
      var layout = gallery.getAttribute('data-layout');
      // Skip logo_carousel — pure CSS, no JS needed
      if (layout === 'logo_carousel') return;

      if (layout === 'gallery') {
        initGalleryLayout(gallery);
        return;
      }

      initSliderNavigation(gallery);
      initPagination(gallery);
      // Tile tabindex/role are rendered server-side (anchor-gallery.php) based
      // on popup_style + tile type; Enter/Space is handled by the delegated
      // keydown listener above. No per-tile JS wiring needed here.
    });
  }

  // Initialize on DOM ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initGalleries);
  } else {
    initGalleries();
  }

  // Expose init method for admin preview
  function initContainer(container) {
    var galleries = container.querySelectorAll('.anchor-video-gallery');
    galleries.forEach(function(gallery) {
      var layout = gallery.getAttribute('data-layout');
      if (layout === 'logo_carousel') return;

      if (layout === 'gallery') {
        initGalleryLayout(gallery);
        return;
      }

      initSliderNavigation(gallery);
      initPagination(gallery);
      // Tile tabindex/role are rendered server-side (anchor-gallery.php) based
      // on popup_style + tile type; Enter/Space is handled by the delegated
      // keydown listener above. No per-tile JS wiring needed here.
    });
  }

  window.AnchorVideoGallery = {
    init: function(container) {
      if (!container) {
        initGalleries();
      } else if (container.jquery) {
        // jQuery object
        initContainer(container[0]);
      } else {
        initContainer(container);
      }
    },
    collectSequence: collectSequence
  };

  // ============================================================================
  // Legacy Support (old slider markup)
  // ============================================================================

  function getLegacyModal() {
    var modal = document.querySelector('.anchor-video-modal');
    if (modal) return modal;

    modal = document.createElement('div');
    modal.className = 'up-modal anchor-video-modal';
    modal.setAttribute('role', 'dialog');
    modal.setAttribute('aria-modal', 'true');
    modal.hidden = true;

    modal.innerHTML = [
      '<div class="up-modal__backdrop" data-close></div>',
      '<div class="up-modal__dialog">',
      '  <button type="button" class="up-modal__close" aria-label="Close popup" data-close>x</button>',
      '  <div class="up-modal__frame" data-frame></div>',
      '</div>'
    ].join('');

    document.body.appendChild(modal);

    modal.addEventListener('click', function(e) {
      if (e.target.hasAttribute('data-close')) {
        var frame = modal.querySelector('[data-frame]');
        if (frame) frame.innerHTML = '';
        modal.hidden = true;
      }
    });

    return modal;
  }

  // Legacy tile click handler
  document.addEventListener('click', function(e) {
    var tile = e.target.closest('.anchor-video-tile');
    if (!tile) return;

    // Skip if it's a new-style gallery tile
    if (tile.closest('.anchor-video-gallery')) return;

    e.preventDefault();

    var provider = tile.getAttribute('data-provider');
    var id = tile.getAttribute('data-video-id');
    var slider = tile.closest('.anchor-video-slider');
    var autoplay = slider && slider.getAttribute('data-autoplay') === '1';

    if (!provider || !id) return;

    var modal = getLegacyModal();
    var frame = modal.querySelector('[data-frame]');
    var src = getVideoSrc(provider, id, autoplay);

    frame.innerHTML = '<iframe src="' + src + '" allow="autoplay; fullscreen; picture-in-picture; clipboard-write; encrypted-media" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe>';
    modal.hidden = false;

    var closeBtn = modal.querySelector('.up-modal__close');
    if (closeBtn) closeBtn.focus();
  });

})();

/* ════════════════════════════════════════════════════════════
   Phase 4/5 — Filterable grid: button handler + default filter.
   Each filter button has data-filter, tiles have data-category.
   Shell may carry data-filter-default to force initial state.
   ════════════════════════════════════════════════════════════ */
(function () {
  function applyFilter(shell, filter) {
    shell.querySelectorAll('.avg-filter').forEach(function (b) {
      b.classList.toggle('is-active', b.getAttribute('data-filter') === filter);
    });
    var tiles = shell.querySelectorAll('.avg-tile');
    tiles.forEach(function (tile) {
      var cat = tile.getAttribute('data-category') || '';
      var slugs = cat.split(/\s+/).filter(Boolean);
      var match = slugs.indexOf(filter) !== -1;
      tile.classList.toggle('is-hidden', filter !== '*' && !match);
    });
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.avg-filter');
    if (!btn) return;
    var shell = btn.closest('.avg-filterable-shell');
    if (!shell) return;
    applyFilter(shell, btn.getAttribute('data-filter'));
  });

  // Apply default filter from data-filter-default once the DOM is ready.
  function initDefaults() {
    document.querySelectorAll('.avg-filterable-shell').forEach(function (shell) {
      var def = shell.getAttribute('data-filter-default') || '*';
      if (def && def !== '*') {
        // Make sure that filter button exists; otherwise leave All.
        var hasBtn = shell.querySelector('.avg-filter[data-filter="' + def + '"]');
        applyFilter(shell, hasBtn ? def : '*');
      }
    });
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initDefaults);
  } else {
    initDefaults();
  }
})();
