(function() {
    'use strict';

    function initSliders() {
        var sliders = document.querySelectorAll('.ard-layout-slider');
        for (var i = 0; i < sliders.length; i++) {
            initSlider(sliders[i]);
        }
    }

    function initSlider(wrap) {
        var viewport = wrap.querySelector('.ard-slider-viewport');
        var track    = wrap.querySelector('.ard-slider-track');
        var prev     = wrap.querySelector('.ard-slider-prev');
        var next     = wrap.querySelector('.ard-slider-next');
        if (!viewport || !track) return;

        var cards     = track.querySelectorAll('.ard-card');
        var total     = cards.length;
        var perView   = parseInt(wrap.dataset.perView, 10) || 1;
        var autoplay  = parseInt(wrap.dataset.autoplay, 10);
        var speed     = parseInt(wrap.dataset.speed, 10) || 5000;
        var current   = 0;
        var maxSlide  = Math.max(0, total - perView);
        var timer     = null;

        // Set card widths for multi-slide.
        function setCardWidths() {
            var gap = parseFloat(getComputedStyle(wrap).getPropertyValue('--ard-gap')) || 16;
            var vpWidth = viewport.offsetWidth;
            var cardWidth = (vpWidth - gap * (perView - 1)) / perView;
            for (var j = 0; j < cards.length; j++) {
                cards[j].style.flex = '0 0 ' + cardWidth + 'px';
            }
        }

        function goTo(index) {
            if (index < 0) index = maxSlide;
            if (index > maxSlide) index = 0;
            current = index;

            var gap = parseFloat(getComputedStyle(wrap).getPropertyValue('--ard-gap')) || 16;
            var vpWidth = viewport.offsetWidth;
            var cardWidth = (vpWidth - gap * (perView - 1)) / perView;
            var offset = current * (cardWidth + gap);
            track.style.transform = 'translateX(-' + offset + 'px)';
        }

        function startAutoplay() {
            if (!autoplay) return;
            stopAutoplay();
            timer = setInterval(function() { goTo(current + 1); }, speed);
        }

        function stopAutoplay() {
            if (timer) { clearInterval(timer); timer = null; }
        }

        if (prev) prev.addEventListener('click', function() { stopAutoplay(); goTo(current - 1); startAutoplay(); });
        if (next) next.addEventListener('click', function() { stopAutoplay(); goTo(current + 1); startAutoplay(); });

        // Touch / swipe.
        var startX = 0, startY = 0, isDragging = false;
        viewport.addEventListener('touchstart', function(e) {
            startX = e.touches[0].clientX;
            startY = e.touches[0].clientY;
            isDragging = true;
            stopAutoplay();
        }, { passive: true });
        viewport.addEventListener('touchend', function(e) {
            if (!isDragging) return;
            isDragging = false;
            var dx = e.changedTouches[0].clientX - startX;
            var dy = e.changedTouches[0].clientY - startY;
            if (Math.abs(dx) > Math.abs(dy) && Math.abs(dx) > 40) {
                goTo(dx < 0 ? current + 1 : current - 1);
            }
            startAutoplay();
        }, { passive: true });

        setCardWidths();
        goTo(0);
        startAutoplay();

        var resizeTimer;
        window.addEventListener('resize', function() {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function() {
                setCardWidths();
                goTo(current);
            }, 150);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initSliders);
    } else {
        initSliders();
    }
})();
