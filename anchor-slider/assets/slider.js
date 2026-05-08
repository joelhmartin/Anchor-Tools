/**
 * Anchor Slider — frontend slider logic.
 *
 * Vanilla JS. Each slider on the page is independent. Supports slide and
 * fade transitions, arrows, dots, autoplay, loop, pause-on-hover.
 */
(function () {
    'use strict';

    function initSlider(root) {
        var track = root.querySelector('.anchor-slider__track');
        var slides = root.querySelectorAll('.anchor-slider__slide');
        var dots = root.querySelectorAll('.anchor-slider__dot');
        var prev = root.querySelector('.anchor-slider__arrow--prev');
        var next = root.querySelector('.anchor-slider__arrow--next');

        if (!track || slides.length === 0) return;

        var config = {};
        try {
            config = JSON.parse(root.getAttribute('data-config') || '{}');
        } catch (e) { config = {}; }

        var current = 0;
        var total = slides.length;
        var transition = config.transition || 'slide';
        var autoplayTimer = null;

        function go(idx) {
            if (config.loop) {
                if (idx < 0) idx = total - 1;
                if (idx >= total) idx = 0;
            } else {
                if (idx < 0) idx = 0;
                if (idx >= total) idx = total - 1;
            }
            current = idx;

            if (transition === 'fade') {
                slides.forEach(function (s, i) {
                    s.classList.toggle('is-active', i === current);
                });
            } else {
                track.style.transform = 'translateX(-' + (current * 100) + '%)';
            }
            dots.forEach(function (d, i) {
                d.classList.toggle('is-active', i === current);
            });
        }

        function startAutoplay() {
            if (!config.autoplay || total <= 1) return;
            stopAutoplay();
            autoplayTimer = setInterval(function () {
                go(current + 1);
            }, config.autoplaySpeed || 5000);
        }
        function stopAutoplay() {
            if (autoplayTimer) {
                clearInterval(autoplayTimer);
                autoplayTimer = null;
            }
        }

        if (prev) prev.addEventListener('click', function () { go(current - 1); stopAutoplay(); startAutoplay(); });
        if (next) next.addEventListener('click', function () { go(current + 1); stopAutoplay(); startAutoplay(); });
        dots.forEach(function (d, i) {
            d.addEventListener('click', function () { go(i); stopAutoplay(); startAutoplay(); });
        });

        if (config.pauseOnHover) {
            root.addEventListener('mouseenter', stopAutoplay);
            root.addEventListener('mouseleave', startAutoplay);
        }

        // Touch swipe
        var startX = 0, deltaX = 0, dragging = false;
        track.addEventListener('touchstart', function (e) {
            startX = e.touches[0].clientX;
            dragging = true;
            stopAutoplay();
        }, { passive: true });
        track.addEventListener('touchmove', function (e) {
            if (!dragging) return;
            deltaX = e.touches[0].clientX - startX;
        }, { passive: true });
        track.addEventListener('touchend', function () {
            if (!dragging) return;
            if (Math.abs(deltaX) > 50) {
                go(current + (deltaX < 0 ? 1 : -1));
            }
            dragging = false;
            deltaX = 0;
            startAutoplay();
        });

        go(0);
        startAutoplay();
    }

    function initAll() {
        document.querySelectorAll('.anchor-slider').forEach(initSlider);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }
})();
