(function () {
    'use strict';

    var cfg = window.ANCHOR_POST_DISPLAY || {};

    /* ---- Helpers ---- */

    function debounce(fn, ms) {
        var timer;
        return function () {
            var ctx = this, args = arguments;
            clearTimeout(timer);
            timer = setTimeout(function () { fn.apply(ctx, args); }, ms);
        };
    }

    function ajax(action, data, cb) {
        var fd = new FormData();
        fd.append('action', action);
        fd.append('nonce', cfg.nonce);
        for (var k in data) {
            if (data.hasOwnProperty(k)) fd.append(k, data[k]);
        }
        fetch(cfg.ajaxUrl, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (r) { cb(r.success ? r.data : null); })
            .catch(function () { cb(null); });
    }

    function getGridShell(grid) {
        return grid.closest('.anchor-post-grid-wrap') || grid.parentNode;
    }

    function getSliderContainer(grid) {
        return grid.closest('.anchor-post-slider');
    }

    function collectGridParams(grid) {
        return {
            search:     grid.dataset.currentSearch || grid.dataset.search || '',
            post_type:  grid.dataset.postType  || '',
            posts:      grid.dataset.posts      || '12',
            max_posts:  grid.dataset.maxPosts   || '0',
            columns:    grid.dataset.columns    || '3',
            layout:     grid.dataset.layout     || 'grid',
            orderby:    grid.dataset.orderby    || 'date',
            order:      grid.dataset.order      || 'DESC',
            show_date:  grid.dataset.showDate   || 'no',
            show_type:  grid.dataset.showType   || 'no',
            no_results: grid.dataset.noResults  || 'No results found.',
            image_size: grid.dataset.imageSize  || 'medium',
            teaser_words: grid.dataset.teaserWords || '26',
            taxonomy:   grid.dataset.taxonomy   || '',
            terms:      grid.dataset.terms      || '',
            exclude_taxonomy: grid.dataset.excludeTaxonomy || '',
            exclude_terms:    grid.dataset.excludeTerms    || '',
            pagination: grid.dataset.pagination || 'none',
            pagination_window: grid.dataset.paginationWindow || '7',
            fields:     grid.dataset.fields    || '',
            slider_autoplay: grid.dataset.sliderAutoplay || 'no',
            slider_speed:    grid.dataset.sliderSpeed    || '5000',
            slider_per_view: grid.dataset.sliderPerView  || '3'
        };
    }

    /* ================================================================
       Live Search
       ================================================================ */

    function initSearch(form) {
        var input     = form.querySelector('.anchor-search-field');
        var dropdown  = form.querySelector('.anchor-search-results');
        var target    = form.dataset.target;
        var minChars  = parseInt(form.dataset.minChars, 10) || 3;
        var postTypes = form.dataset.postTypes || '';
        var activeIdx = -1;

        if (!input) return;

        /* -- Dropdown helpers -- */

        function openDropdown(html) {
            dropdown.innerHTML = html;
            dropdown.classList.add('is-open');
            activeIdx = -1;
        }

        function closeDropdown() {
            dropdown.classList.remove('is-open');
            activeIdx = -1;
        }

        function setActive(idx) {
            var items = dropdown.querySelectorAll('.anchor-search-result');
            items.forEach(function (el) { el.classList.remove('is-active'); });
            if (items[idx]) {
                items[idx].classList.add('is-active');
                items[idx].scrollIntoView({ block: 'nearest' });
            }
            activeIdx = idx;
        }

        /* -- If targeting a grid, reload it instead of showing dropdown -- */

        function filterGrid(term) {
            var grid = document.querySelector(target);
            if (!grid) return;
            grid.classList.add('is-loading');
            var params = collectGridParams(grid);
            params.search = term;
            params.page = '1';
            ajax('anchor_post_display_load', params, function (data) {
                grid.classList.remove('is-loading');
                if (!data) return;
                grid.innerHTML = data.html;
                grid.dataset.currentSearch = term;
                grid.dataset.currentPage = '1';
                initPostSlider(grid);
                updatePagination(grid, data);
            });
        }

        /* -- Main input handler -- */

        var onInput = debounce(function () {
            var term = input.value.trim();

            if (term.length < minChars) {
                closeDropdown();
                if (target) filterGrid('');
                return;
            }

            if (target) {
                filterGrid(term);
                return;
            }

            form.classList.add('is-loading');
            ajax('anchor_post_display_search', { term: term, post_types: postTypes }, function (data) {
                form.classList.remove('is-loading');
                if (!data || !data.length) {
                    openDropdown('<div class="anchor-search-no-results">No results found.</div>');
                    return;
                }
                var html = '';
                data.forEach(function (item) {
                    html += '<a class="anchor-search-result" href="' + item.url + '">';
                    if (item.thumb) {
                        html += '<img class="anchor-search-result-thumb" src="' + item.thumb + '" alt="">';
                    }
                    html += '<span class="anchor-search-result-info">';
                    html += '<span class="anchor-search-result-title">' + item.title + '</span>';
                    if (item.type) {
                        html += '<span class="anchor-search-result-type">' + item.type + '</span>';
                    }
                    html += '</span></a>';
                });
                openDropdown(html);
            });
        }, 300);

        input.addEventListener('input', onInput);

        /* -- Keyboard nav -- */

        input.addEventListener('keydown', function (e) {
            if (!dropdown.classList.contains('is-open')) return;
            var items = dropdown.querySelectorAll('.anchor-search-result');
            if (!items.length) return;

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                setActive(activeIdx < items.length - 1 ? activeIdx + 1 : 0);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                setActive(activeIdx > 0 ? activeIdx - 1 : items.length - 1);
            } else if (e.key === 'Enter' && activeIdx >= 0) {
                e.preventDefault();
                var href = items[activeIdx].getAttribute('href');
                if (href) window.location.href = href;
            } else if (e.key === 'Escape') {
                closeDropdown();
            }
        });

        /* -- Close on outside click -- */

        document.addEventListener('click', function (e) {
            if (!form.contains(e.target)) closeDropdown();
        });

        /* -- Prevent default submit when live + no target -- */

        form.addEventListener('submit', function (e) {
            if (target) {
                e.preventDefault();
                filterGrid(input.value.trim());
            }
        });
    }

    /* ================================================================
       Pagination (numbered + load-more)
       ================================================================ */

    function updatePagination(grid, data) {
        /* Remove existing pagination elements for this grid */
        var wrapper = getGridShell(grid);
        var oldPag = wrapper.querySelector('.anchor-post-grid-pagination');
        var oldBtn = wrapper.querySelector('.anchor-post-grid-load-more');
        if (oldPag) oldPag.remove();
        if (oldBtn) oldBtn.remove();

        if (data.pagination_html) {
            var anchor = getSliderContainer(grid) || grid;
            anchor.insertAdjacentHTML('afterend', data.pagination_html);
            bindPagination(grid);
        }
    }

    function loadPage(grid, page, append) {
        grid.classList.add('is-loading');
        var params = collectGridParams(grid);
        params.page = String(page);
        ajax('anchor_post_display_load', params, function (data) {
            grid.classList.remove('is-loading');
            if (!data) return;
            if (append) {
                grid.insertAdjacentHTML('beforeend', data.html);
            } else {
                grid.innerHTML = data.html;
            }
            grid.dataset.currentPage = String(page);
            initPostSlider(grid);
            updatePagination(grid, data);
        });
    }

    function bindPagination(grid) {
        var wrapper = getGridShell(grid);

        /* Numbered */
        var pag = wrapper.querySelector('.anchor-post-grid-pagination');
        if (pag) {
            pag.addEventListener('click', function (e) {
                var btn = e.target.closest('.page-num');
                if (!btn || btn.classList.contains('is-current')) return;
                e.preventDefault();
                loadPage(grid, parseInt(btn.dataset.page, 10), false);
            });
        }

        /* Load more */
        var lm = wrapper.querySelector('.anchor-post-grid-load-more');
        if (lm) {
            lm.addEventListener('click', function (e) {
                e.preventDefault();
                var next = parseInt(grid.dataset.currentPage || '1', 10) + 1;
                lm.disabled = true;
                loadPage(grid, next, true);
            });
        }
    }

    /* ================================================================
       Slider Layout
       ================================================================ */

    function getSliderPerView(grid) {
        var desktop = parseInt(grid.dataset.sliderPerView || grid.dataset.columns || '1', 10) || 1;
        var tablet  = parseInt(grid.dataset.sliderPerViewTablet || '', 10);
        var mobile  = parseInt(grid.dataset.sliderPerViewMobile || '', 10);
        // Breakpoints mirror the scoped CSS: mobile <= 767px, tablet <= 1024px.
        if (window.matchMedia('(max-width: 767px)').matches) return (mobile || 1);
        if (window.matchMedia('(max-width: 1024px)').matches) return (tablet || Math.min(2, desktop));
        return desktop;
    }

    function stopSlider(grid) {
        if (grid._apdSliderTimer) {
            clearInterval(grid._apdSliderTimer);
            grid._apdSliderTimer = null;
        }
    }

    function initPostSlider(grid) {
        if (!grid || (grid.dataset.layout !== 'slider' && grid.dataset.layout !== 'carousel')) return;

        var slider   = getSliderContainer(grid);
        var viewport = slider ? slider.querySelector('.anchor-post-slider-viewport') : null;
        var prev     = slider ? slider.querySelector('.anchor-post-slider-prev') : null;
        var next     = slider ? slider.querySelector('.anchor-post-slider-next') : null;
        var dotsWrap = slider ? slider.querySelector('.anchor-post-slider-dots') : null;
        if (!slider || !viewport) return;

        var cards = grid.querySelectorAll('.anchor-post-grid-card');
        var total = cards.length;
        var current = parseInt(grid.dataset.sliderIndex || '0', 10) || 0;
        var autoplay = grid.dataset.sliderAutoplay === 'yes';
        var speed = parseInt(grid.dataset.sliderSpeed || '5000', 10) || 5000;
        var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        var pauseHover = grid.dataset.carouselPauseOnHover === '1' || grid.dataset.carouselPauseOnHover === 'yes';

        function getGap() {
            var gap = parseFloat(getComputedStyle(grid).gap);
            return isNaN(gap) ? 20 : gap;
        }

        function setCardWidths() {
            var perView = Math.min(getSliderPerView(grid), Math.max(1, total));
            var gap = getGap();
            var cardWidth = Math.max(1, (viewport.offsetWidth - gap * (perView - 1)) / perView);
            cards.forEach(function (card) {
                card.style.flex = '0 0 ' + cardWidth + 'px';
            });
        }

        function goTo(index) {
            var perView = Math.min(getSliderPerView(grid), Math.max(1, total));
            var maxSlide = Math.max(0, total - perView);
            if (index < 0) index = maxSlide;
            if (index > maxSlide) index = 0;
            current = index;
            grid.dataset.sliderIndex = String(current);

            var gap = getGap();
            var cardWidth = cards[0] ? cards[0].getBoundingClientRect().width : viewport.offsetWidth;
            var offset = current * (cardWidth + gap);
            grid.style.transform = 'translateX(-' + offset + 'px)';

            if (prev) prev.disabled = total <= perView;
            if (next) next.disabled = total <= perView;
            updateDots(perView);
        }

        function buildDots() {
            if (!dotsWrap) return;
            var perView = Math.min(getSliderPerView(grid), Math.max(1, total));
            var pages = Math.max(1, Math.ceil(total / perView));
            dotsWrap.innerHTML = '';
            for (var d = 0; d < pages; d++) {
                var dot = document.createElement('button');
                dot.type = 'button';
                dot.className = 'anchor-post-slider-dot';
                dot.setAttribute('aria-label', 'Go to slide ' + (d + 1));
                (function (idx) {
                    dot.onclick = function () { grid._apdSliderGo(idx * Math.min(getSliderPerView(grid), Math.max(1, total)), true); };
                })(d);
                dotsWrap.appendChild(dot);
            }
        }

        function updateDots(perView) {
            if (!dotsWrap) return;
            var dots = dotsWrap.querySelectorAll('.anchor-post-slider-dot');
            var activePage = perView > 0 ? Math.floor(current / perView) : 0;
            dots.forEach(function (el, i) {
                el.classList.toggle('is-active', i === activePage);
            });
        }

        function startAutoplay() {
            stopSlider(grid);
            if (!autoplay || reduceMotion || total <= getSliderPerView(grid)) return;
            grid._apdSliderTimer = setInterval(function () {
                goTo(current + 1);
            }, Math.max(1000, speed));
        }

        grid._apdSliderGo = function (index, restart) {
            stopSlider(grid);
            goTo(index);
            if (restart) startAutoplay();
        };

        if (prev) {
            prev.onclick = function () { grid._apdSliderGo(current - 1, true); };
        }
        if (next) {
            next.onclick = function () { grid._apdSliderGo(current + 1, true); };
        }

        if (!viewport.dataset.apdSwipeBound) {
            var startX = 0;
            var startY = 0;
            viewport.addEventListener('touchstart', function (e) {
                var activeGrid = viewport.querySelector('.anchor-post-grid');
                if (activeGrid) stopSlider(activeGrid);
                startX = e.touches[0].clientX;
                startY = e.touches[0].clientY;
            }, { passive: true });
            viewport.addEventListener('touchend', function (e) {
                var activeGrid = viewport.querySelector('.anchor-post-grid');
                if (!activeGrid || !activeGrid._apdSliderGo) return;
                var dx = e.changedTouches[0].clientX - startX;
                var dy = e.changedTouches[0].clientY - startY;
                if (Math.abs(dx) > Math.abs(dy) && Math.abs(dx) > 40) {
                    var activeIndex = parseInt(activeGrid.dataset.sliderIndex || '0', 10) || 0;
                    activeGrid._apdSliderGo(dx < 0 ? activeIndex + 1 : activeIndex - 1, true);
                } else {
                    initPostSlider(activeGrid);
                }
            }, { passive: true });
            viewport.dataset.apdSwipeBound = '1';
        }

        if (pauseHover && autoplay && !slider.dataset.apdPauseBound) {
            slider.addEventListener('mouseenter', function () { stopSlider(grid); });
            slider.addEventListener('mouseleave', function () { if (grid._apdSliderGo) startAutoplay(); });
            slider.dataset.apdPauseBound = '1';
        }

        stopSlider(grid);
        setCardWidths();
        buildDots();
        goTo(current);
        startAutoplay();
    }

    function initPostSliders(root) {
        (root || document).querySelectorAll('.anchor-post-grid[data-layout="slider"], .anchor-post-grid[data-layout="carousel"]').forEach(initPostSlider);
    }

    /**
     * Re-initialize displays inside a container (used by the admin live preview
     * after injecting fresh markup). Binds pagination + sliders/carousels.
     */
    window.AnchorPostDisplayInit = function (root) {
        var scope = root || document;
        scope.querySelectorAll('.anchor-post-grid').forEach(function (grid) {
            grid.dataset.currentPage = '1';
            grid.dataset.currentSearch = grid.dataset.search || '';
            bindPagination(grid);
        });
        initPostSliders(scope);
    };

    /* ================================================================
       Init on DOM ready
       ================================================================ */

    function init() {
        document.querySelectorAll('.anchor-search').forEach(initSearch);

        document.querySelectorAll('.anchor-post-grid').forEach(function (grid) {
            grid.dataset.currentPage = '1';
            grid.dataset.currentSearch = grid.dataset.search || '';
            bindPagination(grid);
        });
        initPostSliders(document);
    }

    var resizeTimer;
    window.addEventListener('resize', function () {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function () {
            initPostSliders(document);
        }, 150);
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
