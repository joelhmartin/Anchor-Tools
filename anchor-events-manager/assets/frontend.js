(function(){
  function initCalendars(){
    document.querySelectorAll('.anchor-event-calendar').forEach(function(cal){
      if(cal.dataset.anchorEventsBound){ return; }
      cal.dataset.anchorEventsBound = '1';
      cal.addEventListener('click', function(e){
        var btn = e.target.closest('.anchor-event-calendar-btn');
        if(!btn || !btn.dataset.month){ return; }
        e.preventDefault();
        var month = btn.dataset.month;
        var showPast = cal.getAttribute('data-show-past') || 'yes';
        fetchCalendar(cal, month, showPast);
      });
      cal.addEventListener('mouseover', handleTooltipOver, true);
      cal.addEventListener('mouseout', handleTooltipOut, true);
      cal.addEventListener('mousemove', handleTooltipMove, true);
    });
  }

  function fetchCalendar(cal, month, showPast){
    if(!window.ANCHOR_EVENTS_AJAX || !ANCHOR_EVENTS_AJAX.ajaxUrl){ return; }
    var form = new FormData();
    form.append('action', 'anchor_events_calendar');
    form.append('nonce', ANCHOR_EVENTS_AJAX.nonce || '');
    form.append('month', month || '');
    form.append('show_past', showPast || 'yes');

    cal.classList.add('is-loading');

    fetch(ANCHOR_EVENTS_AJAX.ajaxUrl, { method:'POST', body:form })
      .then(function(r){ return r.json(); })
      .then(function(res){
        cal.classList.remove('is-loading');
        if(!res || !res.success || !res.data || !res.data.html){ return; }
        var tmp = document.createElement('div');
        tmp.innerHTML = res.data.html;
        var newCal = tmp.querySelector('.anchor-event-calendar');
        if(newCal){
          cal.replaceWith(newCal);
          initCalendars();
        }
      })
      .catch(function(){ cal.classList.remove('is-loading'); });
  }

  function initGalleries(){
    document.querySelectorAll('.anchor-event-gallery').forEach(function(gallery){
      if(gallery.dataset.anchorGalleryBound){ return; }
      gallery.dataset.anchorGalleryBound = '1';
      var track = gallery.querySelector('.anchor-event-gallery-track');
      var prev = gallery.querySelector('.anchor-event-gallery-prev');
      var next = gallery.querySelector('.anchor-event-gallery-next');
      if(!track){ return; }

      function updateNav(){
        var atStart = track.scrollLeft <= 4;
        var atEnd = track.scrollLeft + track.clientWidth >= track.scrollWidth - 4;
        var overflowing = track.scrollWidth > track.clientWidth + 4;
        if(prev){ prev.hidden = !overflowing || atStart; }
        if(next){ next.hidden = !overflowing || atEnd; }
      }

      function step(dir){
        var slide = track.querySelector('.anchor-event-gallery-slide');
        var amount = slide ? slide.getBoundingClientRect().width + 12 : track.clientWidth * 0.8;
        track.scrollBy({ left: dir * amount, behavior: 'smooth' });
      }

      if(prev){ prev.addEventListener('click', function(){ step(-1); }); }
      if(next){ next.addEventListener('click', function(){ step(1); }); }
      track.addEventListener('scroll', updateNav, { passive: true });
      window.addEventListener('resize', updateNav);
      updateNav();
    });
  }

  document.addEventListener('DOMContentLoaded', function(){
    initCalendars();
    initGalleries();
    initLightbox();
  });

  // Lightbox
  var lb = { el: null, img: null, caption: null, counter: null, items: [], index: 0, group: null };

  function buildLightbox(){
    if(lb.el){ return; }
    var el = document.createElement('div');
    el.className = 'anchor-event-lightbox';
    el.setAttribute('aria-hidden', 'true');
    el.innerHTML = ''
      + '<div class="anchor-event-lightbox-backdrop"></div>'
      + '<button type="button" class="anchor-event-lightbox-close" aria-label="Close">&times;</button>'
      + '<button type="button" class="anchor-event-lightbox-nav anchor-event-lightbox-prev" aria-label="Previous">&larr;</button>'
      + '<button type="button" class="anchor-event-lightbox-nav anchor-event-lightbox-next" aria-label="Next">&rarr;</button>'
      + '<figure class="anchor-event-lightbox-stage">'
      +   '<img class="anchor-event-lightbox-image" alt="" />'
      +   '<figcaption class="anchor-event-lightbox-caption"></figcaption>'
      + '</figure>'
      + '<div class="anchor-event-lightbox-counter"></div>';
    document.body.appendChild(el);
    lb.el = el;
    lb.img = el.querySelector('.anchor-event-lightbox-image');
    lb.caption = el.querySelector('.anchor-event-lightbox-caption');
    lb.counter = el.querySelector('.anchor-event-lightbox-counter');

    el.querySelector('.anchor-event-lightbox-backdrop').addEventListener('click', closeLightbox);
    el.querySelector('.anchor-event-lightbox-close').addEventListener('click', closeLightbox);
    el.querySelector('.anchor-event-lightbox-prev').addEventListener('click', function(){ navLightbox(-1); });
    el.querySelector('.anchor-event-lightbox-next').addEventListener('click', function(){ navLightbox(1); });
    document.addEventListener('keydown', function(e){
      if(!lb.el || !lb.el.classList.contains('is-open')){ return; }
      if(e.key === 'Escape'){ closeLightbox(); }
      else if(e.key === 'ArrowLeft'){ navLightbox(-1); }
      else if(e.key === 'ArrowRight'){ navLightbox(1); }
    });
  }

  function openLightbox(items, index){
    buildLightbox();
    lb.items = items;
    lb.index = index;
    showLightboxItem();
    lb.el.classList.add('is-open');
    lb.el.setAttribute('aria-hidden', 'false');
    document.body.classList.add('anchor-event-lightbox-open');
  }

  function closeLightbox(){
    if(!lb.el){ return; }
    lb.el.classList.remove('is-open');
    lb.el.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('anchor-event-lightbox-open');
    lb.img.removeAttribute('src');
  }

  function navLightbox(dir){
    if(!lb.items.length){ return; }
    lb.index = (lb.index + dir + lb.items.length) % lb.items.length;
    showLightboxItem();
  }

  function showLightboxItem(){
    var item = lb.items[lb.index];
    if(!item){ return; }
    lb.img.style.opacity = '0';
    var preload = new Image();
    preload.onload = function(){
      lb.img.src = item.src;
      lb.img.alt = item.caption || '';
      lb.img.style.opacity = '1';
    };
    preload.src = item.src;
    lb.caption.textContent = item.caption || '';
    lb.caption.style.display = item.caption ? '' : 'none';
    if(lb.items.length > 1){
      lb.counter.textContent = (lb.index + 1) + ' / ' + lb.items.length;
      lb.counter.style.display = '';
    } else {
      lb.counter.style.display = 'none';
    }
    var multi = lb.items.length > 1;
    lb.el.querySelector('.anchor-event-lightbox-prev').style.display = multi ? '' : 'none';
    lb.el.querySelector('.anchor-event-lightbox-next').style.display = multi ? '' : 'none';
  }

  function initLightbox(){
    document.querySelectorAll('.anchor-event-gallery').forEach(function(gallery){
      if(gallery.dataset.anchorLightboxBound){ return; }
      gallery.dataset.anchorLightboxBound = '1';
      gallery.addEventListener('click', function(e){
        var slide = e.target.closest('a[data-anchor-lightbox]');
        if(!slide || !gallery.contains(slide)){ return; }
        e.preventDefault();
        var slides = Array.prototype.slice.call(gallery.querySelectorAll('a[data-anchor-lightbox]'));
        var items = slides.map(function(s){
          return { src: s.getAttribute('href'), caption: s.getAttribute('data-caption') || '' };
        });
        var index = slides.indexOf(slide);
        if(index < 0){ index = 0; }
        openLightbox(items, index);
      });
    });
  }

  // Tooltip logic
  var tooltip, activeLink = null;
  function ensureTooltip(){
    if(tooltip) return tooltip;
    tooltip = document.createElement('div');
    tooltip.className = 'anchor-event-tooltip';
    tooltip.innerHTML = '<img alt=\"\" /><div class=\"anchor-event-tooltip-title\"></div>';
    document.body.appendChild(tooltip);
    return tooltip;
  }

  function handleTooltipOver(e){
    var link = e.target.closest('.anchor-event-calendar-link');
    if(!link) return;
    if(activeLink === link){ return; }
    activeLink = link;
    var tip = ensureTooltip();
    var title = link.dataset.title || link.textContent || '';
    var thumb = link.dataset.thumb || '';
    tip.querySelector('.anchor-event-tooltip-title').textContent = title;
    var img = tip.querySelector('img');
    if(thumb){
      img.src = thumb;
      img.style.display = '';
    } else {
      img.removeAttribute('src');
      img.style.display = 'none';
    }
    tip.classList.add('is-visible');
  }

  function handleTooltipMove(e){
    if(!tooltip || !tooltip.classList.contains('is-visible')) return;
    var x = e.clientX + 12;
    var y = e.clientY + 12;
    tooltip.style.left = x + 'px';
    tooltip.style.top = y + 'px';
  }

  function handleTooltipOut(e){
    var link = e.target.closest('.anchor-event-calendar-link');
    if(!link) return;
    // If moving within the same link, ignore
    if(e.relatedTarget && link.contains(e.relatedTarget)){ return; }
    if(tooltip){
      tooltip.classList.remove('is-visible');
    }
    activeLink = null;
  }
})();
