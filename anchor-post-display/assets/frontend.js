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
            var params = {
                search:    term,
                post_type: grid.dataset.postType  || '',
                posts:     grid.dataset.posts      || '12',
                columns:   grid.dataset.columns    || '3',
                layout:    grid.dataset.layout     || 'grid',
                orderby:   grid.dataset.orderby    || 'date',
                order:     grid.dataset.order      || 'DESC',
                show_date: grid.dataset.showDate   || 'no',
                show_type: grid.dataset.showType   || 'no',
                no_results: grid.dataset.noResults || 'No results found.',
                image_size: grid.dataset.imageSize || 'medium',
                teaser_words: grid.dataset.teaserWords || '26',
                taxonomy:  grid.dataset.taxonomy   || '',
                terms:     grid.dataset.terms      || '',
                exclude_taxonomy: grid.dataset.excludeTaxonomy || '',
                exclude_terms:    grid.dataset.excludeTerms    || '',
                pagination: grid.dataset.pagination || 'none',
                page:      '1'
            };
            ajax('anchor_post_display_load', params, function (data) {
                grid.classList.remove('is-loading');
                if (!data) return;
                grid.innerHTML = data.html;
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
        var wrapper = grid.parentNode;
        var oldPag = wrapper.querySelector('.anchor-post-grid-pagination');
        var oldBtn = wrapper.querySelector('.anchor-post-grid-load-more');
        if (oldPag) oldPag.remove();
        if (oldBtn) oldBtn.remove();

        if (data.pagination_html) {
            grid.insertAdjacentHTML('afterend', data.pagination_html);
            bindPagination(grid);
        }
    }

    function loadPage(grid, page, append) {
        grid.classList.add('is-loading');
        var params = {
            search:     grid.dataset.currentSearch || '',
            post_type:  grid.dataset.postType  || '',
            posts:      grid.dataset.posts      || '12',
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
            fields:     grid.dataset.fields    || '',
            page:       String(page)
        };
        ajax('anchor_post_display_load', params, function (data) {
            grid.classList.remove('is-loading');
            if (!data) return;
            if (append) {
                grid.insertAdjacentHTML('beforeend', data.html);
            } else {
                grid.innerHTML = data.html;
            }
            grid.dataset.currentPage = String(page);
            updatePagination(grid, data);
        });
    }

    function bindPagination(grid) {
        var wrapper = grid.parentNode;

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
       Init on DOM ready
       ================================================================ */

    function init() {
        document.querySelectorAll('.anchor-search').forEach(initSearch);

        document.querySelectorAll('.anchor-post-grid').forEach(function (grid) {
            grid.dataset.currentPage = '1';
            grid.dataset.currentSearch = '';
            bindPagination(grid);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
