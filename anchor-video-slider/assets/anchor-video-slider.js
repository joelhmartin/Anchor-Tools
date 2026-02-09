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
  // Popup: Lightbox Modal
  // ============================================================================

  var lightboxModal = null;

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
      '  <div class="avg-modal-frame" data-frame></div>',
      '</div>'
    ].join('');

    document.body.appendChild(modal);
    lightboxModal = modal;

    modal.addEventListener('click', function(e) {
      if (e.target.hasAttribute('data-close')) {
        closeLightbox();
      }
    });

    return modal;
  }

  function openLightbox(provider, id, autoplay) {
    // Close any other open popups first (cross-module coordination)
    document.dispatchEvent(new CustomEvent('anchor-close-popups', { detail: { except: getLightboxModal() } }));

    var modal = getLightboxModal();
    var frame = modal.querySelector('[data-frame]');
    var src = getVideoSrc(provider, id, autoplay);

    frame.innerHTML = '<iframe src="' + src + '" allow="autoplay; fullscreen; picture-in-picture; clipboard-write; encrypted-media" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe>';
    modal.hidden = false;
    document.body.style.overflow = 'hidden';

    var closeBtn = modal.querySelector('.avg-modal-close');
    if (closeBtn) closeBtn.focus();
  }

  function closeLightbox() {
    if (!lightboxModal) return;
    var frame = lightboxModal.querySelector('[data-frame]');
    if (frame) frame.innerHTML = '';
    lightboxModal.hidden = true;
    document.body.style.overflow = '';
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

  function openTheater(provider, id, autoplay, title) {
    // Close any other open popups first (cross-module coordination)
    document.dispatchEvent(new CustomEvent('anchor-close-popups', { detail: { except: getTheaterEl() } }));

    var theater = getTheaterEl();
    var frame = theater.querySelector('[data-frame]');
    var titleEl = theater.querySelector('[data-title]');
    var src = getVideoSrc(provider, id, autoplay);

    titleEl.textContent = title || '';
    frame.innerHTML = '<iframe src="' + src + '" allow="autoplay; fullscreen; picture-in-picture; clipboard-write; encrypted-media" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe>';
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

  function openSidePanel(provider, id, autoplay, title) {
    // Close any other open popups first (cross-module coordination)
    document.dispatchEvent(new CustomEvent('anchor-close-popups', { detail: { except: getSidePanelEl() } }));

    var panel = getSidePanelEl();
    var frame = panel.querySelector('[data-frame]');
    var titleEl = panel.querySelector('[data-title]');
    var src = getVideoSrc(provider, id, autoplay);

    titleEl.textContent = title || '';
    frame.innerHTML = '<iframe src="' + src + '" allow="autoplay; fullscreen; picture-in-picture; clipboard-write; encrypted-media" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe>';

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

  function openInline(gallery, tile, provider, id, autoplay) {
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

    e.preventDefault();

    var provider = tile.getAttribute('data-provider');
    var videoId = tile.getAttribute('data-video-id');
    var url = tile.getAttribute('data-url');
    var popupStyle = gallery.getAttribute('data-popup') || 'lightbox';
    var autoplay = gallery.getAttribute('data-autoplay') === '1';
    var title = tile.querySelector('.avg-title');
    var titleText = title ? title.textContent : '';

    if (!provider || !videoId) return;

    switch (popupStyle) {
      case 'none':
        window.open(url || getDirectUrl(provider, videoId), '_blank');
        break;
      case 'inline':
        openInline(gallery, tile, provider, videoId, autoplay);
        break;
      case 'theater':
        openTheater(provider, videoId, autoplay, titleText);
        break;
      case 'side_panel':
        openSidePanel(provider, videoId, autoplay, titleText);
        break;
      case 'lightbox':
      default:
        openLightbox(provider, videoId, autoplay);
        break;
    }
  }

  document.addEventListener('click', handleTileClick);

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

    function getVisibleCount() {
      if (window.innerWidth < 768) return 1;
      if (window.innerWidth < 1024) return 2;
      return 3;
    }

    function updateCarousel() {
      if (layout !== 'carousel') return;

      var visibleCount = getVisibleCount();
      var tileWidth = tiles[0].offsetWidth;
      var gap = parseInt(getComputedStyle(gallery).getPropertyValue('--avg-gap')) || 16;
      var offset = currentIndex * (tileWidth + gap);

      track.style.transform = 'translateX(-' + offset + 'px)';

      // Update active states
      tiles.forEach(function(tile, i) {
        tile.classList.toggle('active', i >= currentIndex && i < currentIndex + visibleCount);
      });

      // Update button states
      if (prevBtn) prevBtn.disabled = currentIndex === 0;
      if (nextBtn) nextBtn.disabled = currentIndex >= tiles.length - visibleCount;
    }

    function scrollSlider(direction) {
      if (layout === 'slider') {
        var tileWidth = tiles[0].offsetWidth;
        var gap = parseInt(getComputedStyle(gallery).getPropertyValue('--avg-gap')) || 16;
        var scrollAmount = (tileWidth + gap) * direction;
        track.scrollBy({ left: scrollAmount, behavior: 'smooth' });
      } else {
        var visibleCount = getVisibleCount();
        currentIndex = Math.max(0, Math.min(tiles.length - visibleCount, currentIndex + direction));
        updateCarousel();
      }
    }

    if (prevBtn) {
      prevBtn.addEventListener('click', function() { scrollSlider(-1); });
    }

    if (nextBtn) {
      nextBtn.addEventListener('click', function() { scrollSlider(1); });
    }

    // Initialize carousel
    if (layout === 'carousel') {
      updateCarousel();
      window.addEventListener('resize', updateCarousel);
    }

    // Dots navigation
    var dotsContainer = gallery.querySelector('.avg-dots');
    if (dotsContainer && layout === 'carousel') {
      var dotCount = Math.ceil(tiles.length / getVisibleCount());
      for (var i = 0; i < dotCount; i++) {
        var dot = document.createElement('button');
        dot.className = 'avg-dot' + (i === 0 ? ' active' : '');
        dot.setAttribute('aria-label', 'Go to slide ' + (i + 1));
        dot.setAttribute('data-index', i);
        dotsContainer.appendChild(dot);
      }

      dotsContainer.addEventListener('click', function(e) {
        var dot = e.target.closest('.avg-dot');
        if (!dot) return;

        var index = parseInt(dot.getAttribute('data-index'));
        currentIndex = index * getVisibleCount();
        updateCarousel();

        dotsContainer.querySelectorAll('.avg-dot').forEach(function(d, i) {
          d.classList.toggle('active', i === index);
        });
      });
    }

    // Auto-advance
    var autoplayEnabled = gallery.getAttribute('data-slider-autoplay') === '1';
    var autoplaySpeed = parseInt(gallery.getAttribute('data-autoplay-speed')) || 5000;

    if (autoplayEnabled && layout === 'carousel') {
      var autoplayInterval = setInterval(function() {
        var visibleCount = getVisibleCount();
        if (currentIndex >= tiles.length - visibleCount) {
          currentIndex = 0;
        } else {
          currentIndex++;
        }
        updateCarousel();
      }, autoplaySpeed);

      // Pause on hover
      gallery.addEventListener('mouseenter', function() {
        clearInterval(autoplayInterval);
      });
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
      initSliderNavigation(gallery);
      initPagination(gallery);

      // Make tiles keyboard accessible
      gallery.querySelectorAll('.avg-tile').forEach(function(tile) {
        tile.setAttribute('tabindex', '0');
        tile.setAttribute('role', 'button');

        tile.addEventListener('keydown', function(e) {
          if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            tile.click();
          }
        });
      });
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
      initSliderNavigation(gallery);
      initPagination(gallery);

      gallery.querySelectorAll('.avg-tile').forEach(function(tile) {
        tile.setAttribute('tabindex', '0');
        tile.setAttribute('role', 'button');

        tile.addEventListener('keydown', function(e) {
          if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            tile.click();
          }
        });
      });
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
    }
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
