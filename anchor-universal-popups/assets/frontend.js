(function(){
  if(!window.UP_SNIPPETS || !Array.isArray(UP_SNIPPETS)) return;

  // Modal registry for global close coordination
  var allModals = [];

  // Video popups queued to preload silently in the background (click-triggered only)
  var preloadQueue = [];

  // Global close event - closes all popups except the specified one
  function closeAllPopups(exceptModal) {
    document.dispatchEvent(new CustomEvent('anchor-close-popups', { detail: { except: exceptModal } }));
  }

  document.addEventListener('anchor-close-popups', function(e) {
    var except = e.detail && e.detail.except;
    allModals.forEach(function(m) {
      if (m !== except && !m.hidden) {
        closeModal(m);
      }
    });
  });

  // Storage helpers
  function markShown(id, mode, minutes){
    try{
      if(mode === 'session'){
        sessionStorage.setItem('up_shown_'+id, String(Date.now()));
      } else {
        var key = 'up_cool_'+id;
        var until = Date.now() + (Math.max(1, minutes|0) * 60 * 1000);
        localStorage.setItem(key, String(until));
      }
    } catch(e){}
  }
  function shouldShow(id, mode, minutes){
    try{
      if(mode === 'session'){
        return !sessionStorage.getItem('up_shown_'+id);
      } else {
        var key = 'up_cool_'+id;
        var until = parseInt(localStorage.getItem(key) || '0', 10);
        return !(until && Date.now() < until);
      }
    } catch(e){
      // if storage is blocked, show anyway
      return true;
    }
  }

  function buildModalShell(isVideo, popupStyle){
    popupStyle = popupStyle || 'modal';
    var modal = document.createElement('div');
    modal.className = 'up-modal up-style-' + popupStyle;
    modal.setAttribute('role','dialog');
    modal.setAttribute('aria-modal','true');
    modal.hidden = true;

    var backdrop = document.createElement('div');
    backdrop.className = 'up-modal__backdrop';
    backdrop.setAttribute('data-close','');
    modal.appendChild(backdrop);

    if(isVideo){
      // Video dialog with space for content under the player
      var dialog = document.createElement('div');
      dialog.className = 'up-modal__dialog';

      var close = document.createElement('button');
      close.className = 'up-modal__close';
      close.type = 'button';
      close.setAttribute('aria-label','Close popup');
      close.setAttribute('data-close','');
      close.setAttribute('data-close-btn','');
      close.textContent = '×';

      var frame = document.createElement('div');
      frame.className = 'up-modal__frame';
      frame.setAttribute('data-frame','');

      var after = document.createElement('div');
      after.className = 'up-video-after';
      after.setAttribute('data-after','');

      dialog.appendChild(close);
      dialog.appendChild(frame);
      dialog.appendChild(after);
      modal.appendChild(dialog);
    } else {
      // Pure HTML content dialog
      var wrap = document.createElement('div');
      wrap.className = 'up-content-wrap';

      var close2 = document.createElement('button');
      close2.className = 'up-modal__close';
      close2.type = 'button';
      close2.setAttribute('aria-label','Close popup');
      close2.setAttribute('data-close','');
      close2.setAttribute('data-close-btn','');
      close2.textContent = '×';

      var inner = document.createElement('div');
      inner.className = 'up-content__inner';
      inner.setAttribute('data-content','');

      wrap.appendChild(close2);
      wrap.appendChild(inner);
      modal.appendChild(wrap);
    }

    document.body.appendChild(modal);
    allModals.push(modal);
    return modal;
  }

  function normalizePopupStyle(style){
    style = String(style || '').toLowerCase().trim();
    if(!style) return 'modal';
    if(style === 'flyin' || style === 'fly-in' || style === 'flyin-center' || style === 'flyin-bottom-center'){
      return 'flyin-bottom';
    }
    if(style === 'flyin-left'){
      return 'flyin-bottom-left';
    }
    if(style === 'flyin-right'){
      return 'flyin-bottom-right';
    }
    return style;
  }

  function buildYouTubeSrc(id, opts){
    opts = opts || {};
    var p = new URLSearchParams({
      autoplay: opts.autoplay ? '1' : '0',
      playsinline: '1',
      rel: '0',
      modestbranding: '1',
      enablejsapi: '1'
    });
    // Mute when asked. Needed for autoplay (browsers block unmuted autoplay) and
    // for a muted preload so a later programmatic play() is allowed without a gesture.
    if(opts.muted){
      p.set('mute', '1');
    }
    return 'https://www.youtube.com/embed/' + encodeURIComponent(id) + '?' + p.toString();
  }
  function buildVimeoSrc(id, opts){
    opts = opts || {};
    var p = new URLSearchParams({
      autoplay: opts.autoplay ? '1' : '0',
      byline: '0',
      title: '0',
      portrait: '0',
      dnt: '1'
    });
    if(opts.muted){
      p.set('muted', '1');
    }
    return 'https://player.vimeo.com/video/' + encodeURIComponent(id) + '?' + p.toString();
  }

  function buildFacebookSrc(url, opts){
    opts = opts || {};
    var p = new URLSearchParams({
      href: url,
      show_text: 'false',
      autoplay: opts.autoplay ? 'true' : 'false'
    });
    // Facebook only autoplays muted.
    if(opts.autoplay || opts.muted){
      p.set('mute', '1');
    }
    return 'https://www.facebook.com/plugins/video.php?' + p.toString();
  }

  // Build the embed src for any provider. For Facebook, `ref` is the full
  // video URL; for YouTube/Vimeo it is the video id.
  function getVideoSrc(provider, ref, opts){
    if(provider === 'facebook') return buildFacebookSrc(ref, opts);
    if(provider === 'vimeo')    return buildVimeoSrc(ref, opts);
    return buildYouTubeSrc(ref, opts);
  }

  /**
   * Activate <script> tags inside a container after innerHTML insertion.
   * innerHTML does not execute scripts, so we replace each <script> with
   * a fresh element the browser will load/run.
   */
  function activateScripts(container){
    var scripts = container.querySelectorAll('script');
    for(var i = 0; i < scripts.length; i++){
      var old = scripts[i];
      var s   = document.createElement('script');
      // Copy all attributes (src, async, type, etc.)
      for(var j = 0; j < old.attributes.length; j++){
        s.setAttribute(old.attributes[j].name, old.attributes[j].value);
      }
      // Copy inline content
      if(old.textContent) s.textContent = old.textContent;
      old.parentNode.replaceChild(s, old);
    }
  }

  function videoIframe(src){
    return '<iframe src="'+src+'" allow="autoplay; fullscreen; picture-in-picture; clipboard-write; encrypted-media" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe>';
  }

  /**
   * Silently load the player into a hidden modal so it's ready before the user
   * clicks. Built WITHOUT autoplay so it buffers the player chrome/poster only.
   * Runs at idle time, so it never competes with page render or load.
   */
  function preloadVideo(modal, provider, id, muted){
    var frameWrap = modal.querySelector('[data-frame]');
    if(!frameWrap || modal._preloaded) return;
    // Takeovers preload muted so their scroll-driven (gesture-less) play is
    // allowed — Vimeo in particular only autoplays programmatically when muted.
    var opts = { autoplay: false, muted: !!muted };
    var src = provider === 'youtube' ? buildYouTubeSrc(id, opts) : buildVimeoSrc(id, opts);
    modal._preloadSrc = src;
    frameWrap.innerHTML = videoIframe(src);
    modal._preloaded = true;
  }

  /**
   * Start playback on an already-preloaded iframe via the provider postMessage
   * API — no reload, so the warm player plays instantly. If the player isn't
   * ready yet (user clicked within the first moment), the command is a no-op
   * and the visitor simply presses play on the visible poster.
   */
  function playPreloaded(modal, provider, muted){
    var iframe = modal.querySelector('[data-frame] iframe');
    if(!iframe || !iframe.contentWindow) return;
    try{
      if(provider === 'youtube'){
        if(muted){ iframe.contentWindow.postMessage(JSON.stringify({event:'command',func:'mute',args:[]}), '*'); }
        iframe.contentWindow.postMessage(JSON.stringify({event:'command',func:'playVideo',args:[]}), '*');
      } else {
        if(muted){ iframe.contentWindow.postMessage(JSON.stringify({method:'setVolume',value:0}), '*'); }
        iframe.contentWindow.postMessage(JSON.stringify({method:'play'}), '*');
      }
    }catch(e){}
  }

  function isFullscreen(modal){
    return modal.classList.contains('up-style-fullscreen');
  }

  function nowTs(){ return (window.performance && performance.now) ? performance.now() : Date.now(); }

  function escapeCssIdentifier(value){
    value = String(value || '').trim();
    if(window.CSS && typeof window.CSS.escape === 'function'){
      return window.CSS.escape(value);
    }
    return value.replace(/[^a-zA-Z0-9_-]/g, '\\$&');
  }

  function classTriggerSelector(value){
    return String(value || '').trim().split(/\s+/).map(function(part){
      return part.replace(/^[.#]+/, '');
    }).filter(Boolean).map(function(part){
      return '.' + escapeCssIdentifier(part);
    }).join('');
  }

  function idTriggerSelector(value){
    value = String(value || '').trim().replace(/^#/, '');
    return value ? '#' + escapeCssIdentifier(value) : '';
  }

  function closestTrigger(el, selector){
    if(!selector || !el.closest) return null;
    try{
      return el.closest(selector);
    }catch(e){
      return null;
    }
  }

  function queryFirst(selector){
    if(!selector) return null;
    try{
      return document.querySelector(selector);
    }catch(e){
      return null;
    }
  }

  function runAfterFirstScroll(callback){
    var done = false;
    var onFirstScroll = function(){
      if(done) return;
      done = true;
      window.removeEventListener('scroll', onFirstScroll);
      callback();
    };
    window.addEventListener('scroll', onFirstScroll, { passive: true });
  }

  // Reveal a standard (non-fullscreen) modal: just unhide and focus the close.
  function revealModal(modal){
    modal._openedAt = nowTs();
    modal._closing = false;
    if(modal._closeTimer){ clearTimeout(modal._closeTimer); modal._closeTimer = null; }
    modal.hidden = false;
    var closeBtn = modal.querySelector('.up-modal__close');
    if(closeBtn){ closeBtn.focus(); }
  }

  // ── Fullscreen takeover (self-anchoring; driven by an inline shortcode card) ──
  // The transform that makes the full-viewport dialog visually occupy the
  // anchor's CURRENT on-screen rect (origin 0,0) — the collapsed start/end state.
  function collapsedTransform(anchor){
    var vw = window.innerWidth || document.documentElement.clientWidth;
    var vh = window.innerHeight || document.documentElement.clientHeight;
    var r = anchor ? anchor.getBoundingClientRect() : { left: 0, top: 0, width: vw, height: vh };
    var sx = Math.max(0.02, r.width / vw);
    var sy = Math.max(0.02, r.height / vh);
    return 'translate(' + r.left + 'px,' + r.top + 'px) scale(' + sx + ',' + sy + ')';
  }

  // Expand: grow the overlay from the anchor's rect to the full viewport (FLIP)
  // while the backdrop fades in, and play the (preloaded) video muted.
  function expandTakeover(modal, provider, id, anchor){
    if(modal._expanded || !anchor) return;
    modal._expanded = true;
    modal._anchor = anchor;
    modal._openedAt = nowTs();
    modal._closing = false;
    if(modal._closeTimer){ clearTimeout(modal._closeTimer); modal._closeTimer = null; }

    closeAllPopups(modal); // replace any other open popup

    // Scroll-driven open has no user gesture, so playback must be muted.
    var frameWrap = modal.querySelector('[data-frame]');
    if(modal._preloaded){
      playPreloaded(modal, provider, true);
    } else if(frameWrap){
      var src = getVideoSrc(provider, id, { autoplay: true, muted: true });
      frameWrap.innerHTML = videoIframe(src);
    }

    var dialog = modal.querySelector('.up-modal__dialog');
    var backdrop = modal.querySelector('.up-modal__backdrop');

    modal.hidden = false;
    if(dialog){
      dialog.style.transition = 'none';
      dialog.style.transformOrigin = '0 0';
      dialog.style.transform = collapsedTransform(anchor);
    }
    if(backdrop){ backdrop.style.transition = 'none'; backdrop.style.opacity = '0'; }
    if(dialog){ void dialog.offsetWidth; } // commit the collapsed start state
    requestAnimationFrame(function(){
      if(dialog){
        dialog.style.transition = 'transform 0.45s cubic-bezier(0.22,0.61,0.36,1)';
        dialog.style.transform = 'translate(0px,0px) scale(1,1)';
      }
      if(backdrop){ backdrop.style.transition = 'opacity 0.45s ease'; backdrop.style.opacity = '1'; }
    });
  }

  // Collapse: shrink back to the anchor's current rect, then hide, stop
  // playback, and re-arm the preload so the next expand is instant again.
  function collapseTakeover(modal){
    if(!modal._expanded || modal._closing) return;
    modal._closing = true;
    modal._expanded = false;

    var dialog = modal.querySelector('.up-modal__dialog');
    var backdrop = modal.querySelector('.up-modal__backdrop');

    if(dialog){
      dialog.style.transition = 'transform 0.4s ease';
      dialog.style.transform = collapsedTransform(modal._anchor);
    }
    if(backdrop){ backdrop.style.transition = 'opacity 0.4s ease'; backdrop.style.opacity = '0'; }

    modal._closeTimer = setTimeout(function(){
      modal.hidden = true;
      var f = modal.querySelector('[data-frame]');
      if(f){
        if(modal._preloaded && modal._preloadSrc){
          var iframe = f.querySelector('iframe');
          if(iframe){ iframe.src = modal._preloadSrc; }
          else { f.innerHTML = videoIframe(modal._preloadSrc); }
        } else {
          f.innerHTML = '';
        }
      }
      if(dialog){ dialog.style.transition = 'none'; dialog.style.transform = ''; }
      if(backdrop){ backdrop.style.transition = 'none'; backdrop.style.opacity = ''; }
      modal._closing = false;
      modal._closeTimer = null;
    }, 420);
  }

  function openVideo(modal, provider, id, autoplay, extra){
    var frameWrap = modal.querySelector('[data-frame]');
    if(!frameWrap) return;

    var opts = { autoplay: !!autoplay, muted: !!(extra && extra.muted) };
    if(provider === 'facebook'){
      var fbSrc = getVideoSrc(provider, id, { autoplay: !!autoplay, muted: !!autoplay });
      frameWrap.innerHTML = videoIframe(fbSrc);
    } else if(modal._preloaded){
      // Reuse the player that's already warmed up in the background.
      if(autoplay){ playPreloaded(modal, provider, opts.muted); }
    } else {
      var src = getVideoSrc(provider, id, opts);
      frameWrap.innerHTML = videoIframe(src);
    }

    // Optional content under the video
    var after = modal.querySelector('[data-after]');
    if(after){
      var css = extra && extra.css ? '<style>'+extra.css+'</style>' : '';
      var html = extra && extra.html ? extra.html : '';
      after.innerHTML = css + html;
      activateScripts(after);
      try{ new Function(extra && extra.js ? extra.js : '')(); }catch(e){}
    }

    revealModal(modal);
  }

  function openContent(modal, html, css, js){
    var content = modal.querySelector('[data-content]');
    if(!content) return;
    var style = '<style>'+ (css || '') +'</style>';
    content.innerHTML = style + (html || '');
    activateScripts(content);
    try{ new Function(js || '')(); }catch(e){}
    revealModal(modal);
  }

  function closeModal(modal){
    if(modal.hidden) return;
    // Fullscreen takeover animates back to its anchor instead of just hiding.
    if(isFullscreen(modal)){
      collapseTakeover(modal);
      return;
    }
    if(modal._closing) return;

    // Stop playback / clear under-content, re-arming the preload if present.
    var f = modal.querySelector('[data-frame]');
    if(f){
      if(modal._preloaded && modal._preloadSrc){
        // Reset to the no-autoplay URL: stops playback AND re-arms the preload
        // so reopening is instant again, without re-fetching on every open.
        var iframe = f.querySelector('iframe');
        if(iframe){ iframe.src = modal._preloadSrc; }
        else { f.innerHTML = videoIframe(modal._preloadSrc); }
      } else {
        f.innerHTML = '';
      }
    }
    var a = modal.querySelector('[data-after]');
    if(a) a.innerHTML = '';
    modal.hidden = true;
  }

  function wireClose(modal){
    modal.addEventListener('click', function(e){
      var t = e.target;
      if(t && t.hasAttribute('data-close')){
        closeModal(modal);
      }
    });
    document.addEventListener('click', function(e){
      if(modal.hidden) return;
      var now = (typeof e.timeStamp === 'number' && e.timeStamp > 0)
        ? e.timeStamp
        : ((window.performance && performance.now) ? performance.now() : Date.now());
      if(modal._openedAt && (now - modal._openedAt) < 50) return;

      var shell = modal.querySelector('.up-modal__dialog, .up-content-wrap');
      if(!shell) return;
      if(shell.contains(e.target)) return;
      closeModal(modal);
    }, true);
    window.addEventListener('keydown', function(e){
      if(e.key === 'Escape' && !modal.hidden) closeModal(modal);
    });
  }

  function resolveProvider(sn){
    var provider = sn.provider || sn.mode;
    if(provider === 'video'){ provider = sn.provider || 'youtube'; } // unified video mode fallback
    return provider;
  }

  function attach(sn){
    var isVideo = (sn.mode === 'youtube' || sn.mode === 'vimeo' || sn.mode === 'video');
    var popupStyle = normalizePopupStyle(sn.popup_style);

    // Fullscreen takeover is its own self-anchoring mode (driven by the inline
    // shortcode card, not the trigger system) — handled entirely separately.
    if(popupStyle === 'fullscreen'){
      setupFullscreenTakeover(sn, isVideo);
      return;
    }

    var modal = buildModalShell(isVideo, popupStyle);
    if (popupStyle === 'modal' && sn.modal_max_width) {
      modal.style.setProperty('--up-modal-max-width', sn.modal_max_width);
    }
    if (popupStyle === 'theater') {
      if (sn.theater_max_width) modal.style.setProperty('--up-theater-max-width', sn.theater_max_width);
      if (sn.theater_max_height) modal.style.setProperty('--up-theater-max-height', sn.theater_max_height);
    }
    if (popupStyle.indexOf('flyin') === 0 && sn.flyin_max_width) {
      modal.style.setProperty('--up-flyin-max-width', sn.flyin_max_width);
    }
    wireClose(modal);

    // Store modal reference on snippet for card click handler
    sn._modal = modal;

    // Resolve provider from snippet (for unified video mode)
    var provider = resolveProvider(sn);

    // Apply close icon color if set
    if(sn.close_color){
      var closeBtn = modal.querySelector('[data-close-btn]');
      if(closeBtn) closeBtn.style.color = sn.close_color;
    }

    // Queue for silent background preload: video popups that open *later*
    // (class/id click, or scroll). page_load popups open immediately, so
    // preloading is moot. Skip ones gated out this session so we never load
    // the unseen. (Fullscreen takeover handles its own preload separately.)
    if(isVideo && sn.video_id && (provider === 'youtube' || provider === 'vimeo')){
      var ptrig = sn.trigger || {};
      var deferredTrigger = (ptrig.type === 'class' || ptrig.type === 'id' || ptrig.type === 'scroll');
      // 'class' bypasses frequency gating on open, so always preload it.
      var willShow = (ptrig.type === 'class') ||
        shouldShow(sn.id, sn.frequency.mode, sn.frequency.cooldownMinutes);
      if(deferredTrigger && willShow){
        preloadQueue.push({ modal: modal, provider: provider, id: sn.video_id });
      }
    }

    function triggerOpen(){
      // Close any other open popups first
      closeAllPopups(modal);

      // For "Click on class", treat the popup like an explicit user action and
      // avoid frequency gating that can make clicks appear "broken".
      var bypassFrequency = (trig && trig.type === 'class');
      if(!bypassFrequency && !shouldShow(sn.id, sn.frequency.mode, sn.frequency.cooldownMinutes)) return;
      if(isVideo){
        // Autoplay must be muted unless this is a genuine user click (class/id) —
        // browsers block unmuted autoplay for page_load/scroll-triggered playback.
        var userClick = (trig && (trig.type === 'class' || trig.type === 'id'));
        var muteForAutoplay = !!sn.autoplay && !userClick;
        var vidRef = (provider === 'facebook') ? sn.video_url : sn.video_id;
        openVideo(modal, provider, vidRef, !!sn.autoplay, { html: sn.html, css: sn.css, js: sn.js, muted: muteForAutoplay });
      } else {
        // Use pre-rendered shortcode content if in shortcode mode, otherwise use HTML
        var content = (sn.mode === 'shortcode' && sn.shortcode_content) ? sn.shortcode_content : sn.html;
        openContent(modal, content, sn.css, sn.js);
      }
      if(!bypassFrequency){
        markShown(sn.id, sn.frequency.mode, sn.frequency.cooldownMinutes);
      }
    }

    // Expose triggerOpen for card click handler
    sn._triggerOpen = triggerOpen;

    var trig = sn.trigger || {type:'page_load', value:'', delay:0};
    if(trig.type === 'page_load'){
      var delay = Math.max(0, parseInt(trig.delay || 0, 10));
      if(delay > 0){
        setTimeout(triggerOpen, delay);
      } else {
        setTimeout(triggerOpen, 0);
      }
    } else if(trig.type === 'class'){
      if(!trig.value) return;
      var selector = classTriggerSelector(trig.value);
      document.addEventListener('click', function(e){
        var el = e.target;
        if(closestTrigger(el, selector)){
          e.preventDefault();
          triggerOpen();
        }
      });
    } else if(trig.type === 'id'){
      if(!trig.value) return;
      var idSel = idTriggerSelector(trig.value);
      document.addEventListener('click', function(e){
        var el = e.target;
        if(closestTrigger(el, idSel)){
          e.preventDefault();
          triggerOpen();
        }
      });
    } else if(trig.type === 'scroll'){
      var fired = false;
      function fireOnce(){
        if(fired) return;
        fired = true;
        triggerOpen();
      }
      var pct = Math.max(0, Math.min(100, parseInt(trig.scrollPercent != null ? trig.scrollPercent : 50, 10)));

      if(trig.scrollMode === 'element'){
        // Fire when a target element reaches the configured visible threshold.
        var target = trig.scrollTarget ? queryFirst(trig.scrollTarget) : null;
        if(!target) return;

        runAfterFirstScroll(function(){
          if('IntersectionObserver' in window){
            // Cap at 0.99: an element taller than the viewport can never report a
            // ratio of exactly 1, so a literal 100% threshold would never fire.
            var thr = Math.min(0.99, Math.max(0, pct/100));
            var io = new IntersectionObserver(function(entries){
              for(var k = 0; k < entries.length; k++){
                if(entries[k].isIntersecting && entries[k].intersectionRatio >= thr){
                  io.disconnect();
                  fireOnce();
                  break;
                }
              }
            }, { threshold: thr });
            io.observe(target);
          } else {
            // No IO support: fire once the target's top scrolls into the viewport.
            var checkEl = function(){
              var r = target.getBoundingClientRect();
              var vh = window.innerHeight || document.documentElement.clientHeight;
              if(r.top < vh && r.bottom > 0){
                window.removeEventListener('scroll', onScrollEl);
                fireOnce();
              }
            };
            var onScrollEl = function(){ requestAnimationFrame(checkEl); };
            window.addEventListener('scroll', onScrollEl, { passive: true });
            checkEl();
          }
        });
      } else {
        // Depth mode: fire after the page has been scrolled pct% of the way down.
        var ticking = false;
        var onScroll = function(){
          if(ticking) return;
          ticking = true;
          requestAnimationFrame(function(){
            ticking = false;
            var doc = document.documentElement;
            var scrollable = doc.scrollHeight - window.innerHeight;
            var y = window.scrollY || window.pageYOffset || 0;
            var scrolledPct = scrollable > 0 ? (y / scrollable) * 100 : 100;
            if(scrolledPct >= pct){
              window.removeEventListener('scroll', onScroll);
              fireOnce();
            }
          });
        };
        window.addEventListener('scroll', onScroll, { passive: true });
      }
    }
  }

  // Fullscreen takeover: the inline shortcode card IS the trigger. Render the
  // card where you want it; when it scrolls into view the overlay grows from it
  // and plays, and collapses + stops when it scrolls out. Video-only.
  function setupFullscreenTakeover(sn, isVideo){
    var anchors = document.querySelectorAll('[data-up-popup-id="' + sn.id + '"]');
    if(!anchors.length) return; // shortcode not placed on this page → do nothing

    var provider = resolveProvider(sn);
    if(!isVideo || !sn.video_id || (provider !== 'youtube' && provider !== 'vimeo')) return;

    var modal = buildModalShell(true, 'fullscreen');
    sn._modal = modal;
    wireClose(modal); // × / Esc collapse it
    if(sn.close_color){
      var cb = modal.querySelector('[data-close-btn]');
      if(cb) cb.style.color = sn.close_color;
    }

    // Warm it first (muted, so scroll-driven play is allowed) — instant on expand.
    preloadQueue.unshift({ modal: modal, provider: provider, id: sn.video_id, muted: true });

    // Clicking the thumbnail expands from that card too (handled in click listener).
    sn._fsExpand = function(anchor){ expandTakeover(modal, provider, sn.video_id, anchor || anchors[0]); };

    var EXPAND_AT = 0.5; // ≥50% of the card visible → expand; gone → collapse
    if(!('IntersectionObserver' in window)) return; // no IO → click-to-expand only

    Array.prototype.forEach.call(anchors, function(anchor){
      var io = new IntersectionObserver(function(entries){
        for(var k = 0; k < entries.length; k++){
          var en = entries[k];
          if(en.isIntersecting && en.intersectionRatio >= EXPAND_AT){
            if(!modal._expanded){ expandTakeover(modal, provider, sn.video_id, anchor); }
          } else if(!en.isIntersecting || en.intersectionRatio <= 0.01){
            // Collapse only when the card that opened it has scrolled away.
            if(modal._expanded && modal._anchor === anchor){ collapseTakeover(modal); }
          }
        }
      }, { threshold: [0, EXPAND_AT] });
      io.observe(anchor);
    });
  }

  // Build a lookup map for snippets by ID
  var snippetMap = {};
  UP_SNIPPETS.forEach(function(sn) {
    snippetMap[sn.id] = sn;
  });

  // Video card click handler (for shortcode-rendered cards)
  document.addEventListener('click', function(e) {
    var card = e.target.closest('[data-up-popup-id]');
    if (!card) return;

    var popupId = parseInt(card.getAttribute('data-up-popup-id'), 10);
    var sn = snippetMap[popupId];
    if (!sn) return;

    // Fullscreen takeover expands from the clicked card; others open normally.
    if (sn._fsExpand) {
      e.preventDefault();
      sn._fsExpand(card);
      return;
    }
    if (!sn._triggerOpen) return;
    e.preventDefault();
    sn._triggerOpen();
  });

  // Keyboard support for video cards (Enter/Space)
  document.addEventListener('keydown', function(e) {
    if (e.key !== 'Enter' && e.key !== ' ') return;

    var card = e.target.closest('[data-up-popup-id]');
    if (!card) return;

    e.preventDefault();
    card.click();
  });

  // Drain the preload queue during browser idle time, one video at a time with
  // a gap between each, so a burst of iframe loads never competes with the
  // page's own content or hurts load metrics.
  function processPreloadQueue(){
    if(!preloadQueue.length) return;
    var i = 0;
    function next(){
      if(i >= preloadQueue.length) return;
      var item = preloadQueue[i++];
      try{ preloadVideo(item.modal, item.provider, item.id, item.muted); }catch(e){}
      setTimeout(next, 300);
    }
    if('requestIdleCallback' in window){
      requestIdleCallback(next, { timeout: 3000 });
    } else {
      setTimeout(next, 1200);
    }
  }

  document.addEventListener('DOMContentLoaded', function(){
    UP_SNIPPETS.forEach(function(sn){
      try{ attach(sn); }catch(e){}
    });
    processPreloadQueue();
  });
})();
