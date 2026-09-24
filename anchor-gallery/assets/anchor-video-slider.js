(function() {
  'use strict';

  // Shared lightbox (assets/shared/anchor-lightbox.js), enqueued as a
  // dependency of this script. Video URL building, the lightbox modal, and
  // the shared popup-option helper live there now; other popup styles below
  // (theater, side panel, inline, legacy) call the same shared helpers.
  var LB = window.AnchorLightbox;
  var getVideoSrc = LB.getVideoSrc;
  var getDirectUrl = LB.getDirectUrl;
  var applyPopupOptions = LB.applyPopupOptions;
  function openLightbox(seq, i, opts) { LB.open(seq, i, opts); }

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
    // Arrow navigation and Tab focus-trap for the lightbox now live in
    // assets/shared/anchor-lightbox.js, which owns its own keydown listener.
    if (e.key === 'Escape') {
      // The shared lightbox handles its own Escape via its own listener.
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
    // Lightbox closing on this event is handled by the shared lightbox file.
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

  // Thin adapter over the shared carousel engine
  // (assets/shared/anchor-carousel.js, enqueued as a dependency of this
  // script). Reads the gallery's avg-* markup and data-* attributes and
  // translates them into the generic cfg shape the shared engine expects.
  function initSliderNavigation(gallery) {
    var layout = gallery.getAttribute('data-layout');
    if (layout !== 'slider' && layout !== 'carousel') return;

    var cols = parseInt(gallery.getAttribute('data-cols-desktop'), 10) || 3;

    window.AnchorCarousel.init(gallery, {
      track: gallery.querySelector('.avg-track'),
      items: gallery.querySelectorAll('.avg-tile'),
      prev: gallery.querySelector('.avg-nav-prev'),
      next: gallery.querySelector('.avg-nav-next'),
      dotsContainer: gallery.querySelector('.avg-dots'),
      dotClass: 'avg-dot',
      mode: layout,
      loop: gallery.getAttribute('data-loop') === '1',
      center: gallery.getAttribute('data-center') === '1',
      cols: {
        desktop: cols,
        tablet: parseInt(gallery.getAttribute('data-cols-tablet'), 10) || Math.min(cols, 2),
        mobile: parseInt(gallery.getAttribute('data-cols-mobile'), 10) || 1
      },
      slidesToScroll: Math.max(1, parseInt(gallery.getAttribute('data-slides-to-scroll'), 10) || 1),
      gapVar: '--avg-gap',
      autoplay: gallery.getAttribute('data-slider-autoplay') === '1',
      autoplaySpeed: parseInt(gallery.getAttribute('data-autoplay-speed'), 10) || 5000,
      // data-pause-on-hover defaults to '1' when missing for backwards compat.
      pauseOnHover: gallery.getAttribute('data-pause-on-hover') !== '0'
    });
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
  // Logo Carousel (Marquee)
  // ============================================================================

  // PHP renders two copies of the logo group, and the CSS shifts the track by
  // one group width. That only looks continuous when a single group is already
  // wider than the row -- otherwise the track runs out of logos mid-scroll and
  // leaves dead space until the animation resets. CSS cannot compare content
  // width to container width, so measure here and clone until the track covers
  // the row twice over.
  function layoutMarqueeRow(row) {
    var track = row.querySelector('.avg-marquee');
    if (!track) return;

    var groups = track.querySelectorAll('.avg-marquee-group');
    if (!groups.length) return;

    // Item widths come from a fixed flex-basis, so the group measures correctly
    // even before the logo images have decoded.
    var groupWidth = groups[0].offsetWidth;
    var rowWidth = row.offsetWidth;
    if (!groupWidth || !rowWidth) return;

    var copies = Math.max(2, Math.ceil((rowWidth * 2) / groupWidth));
    if (copies === groups.length) return;

    while (track.querySelectorAll('.avg-marquee-group').length > copies) {
      track.removeChild(track.lastElementChild);
    }

    // Clone the second group: it is already aria-hidden, so screen readers
    // still see the logo list exactly once.
    var seam = groups[1] || groups[0];
    while (track.querySelectorAll('.avg-marquee-group').length < copies) {
      var clone = seam.cloneNode(true);
      clone.setAttribute('aria-hidden', 'true');
      clone.querySelectorAll('img').forEach(function(img) {
        img.setAttribute('loading', 'eager');
        img.setAttribute('alt', '');
      });
      track.appendChild(clone);
    }

    // Shift by exactly one group so the track lands on an identical arrangement.
    track.style.setProperty('--avg-marquee-shift', (100 / copies) + '%');

    // Custom properties inside @keyframes are resolved when the animation
    // starts, so restart it to pick up the new shift distance. Touch only
    // animation-name -- PHP sets animation-direction inline on this element,
    // and clearing the shorthand would drop the Scroll Right / reverse-row
    // setting along with it.
    track.style.animationName = 'none';
    void track.offsetWidth;
    track.style.animationName = '';
  }

  function initLogoMarquee(gallery) {
    var rows = gallery.querySelectorAll('.avg-marquee-row');
    if (!rows.length) return;

    var relayout = function() {
      rows.forEach(layoutMarqueeRow);
    };

    requestAnimationFrame(relayout);

    var resizeTimer = null;
    window.addEventListener('resize', function() {
      clearTimeout(resizeTimer);
      resizeTimer = setTimeout(relayout, 150);
    });
  }

  // ============================================================================
  // Initialize Galleries
  // ============================================================================

  function initGalleries() {
    var galleries = document.querySelectorAll('.anchor-video-gallery');
    galleries.forEach(function(gallery) {
      var layout = gallery.getAttribute('data-layout');
      if (layout === 'logo_carousel') { initLogoMarquee(gallery); return; }

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
      if (layout === 'logo_carousel') { initLogoMarquee(gallery); return; }

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
