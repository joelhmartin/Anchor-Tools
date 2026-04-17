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
  });

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
