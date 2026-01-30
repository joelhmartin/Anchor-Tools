(function(){
  if(!window.UP_SNIPPETS || !Array.isArray(UP_SNIPPETS)) return;

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

  function buildModalShell(isVideo){
    var modal = document.createElement('div');
    modal.className = 'up-modal';
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
      close2.textContent = '×';

      var inner = document.createElement('div');
      inner.className = 'up-content__inner';
      inner.setAttribute('data-content','');

      wrap.appendChild(close2);
      wrap.appendChild(inner);
      modal.appendChild(wrap);
    }

    document.body.appendChild(modal);
    return modal;
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
    // Autoplay on page load is commonly blocked unless muted.
    if(opts.autoplay && opts.muted){
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
    if(opts.autoplay && opts.muted){
      p.set('muted', '1');
    }
    return 'https://player.vimeo.com/video/' + encodeURIComponent(id) + '?' + p.toString();
  }

  function openVideo(modal, provider, id, autoplay, extra){
    var frameWrap = modal.querySelector('[data-frame]');
    if(!frameWrap) return;

    var opts = { autoplay: !!autoplay, muted: !!(extra && extra.muted) };
    var src = provider === 'youtube' ? buildYouTubeSrc(id, opts) : buildVimeoSrc(id, opts);
    frameWrap.innerHTML = '<iframe src="'+src+'" allow="autoplay; fullscreen; picture-in-picture; clipboard-write; encrypted-media" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe>';

    // Optional content under the video
    var after = modal.querySelector('[data-after]');
    if(after){
      var css = extra && extra.css ? '<style>'+extra.css+'</style>' : '';
      var html = extra && extra.html ? extra.html : '';
      after.innerHTML = css + html;
      try{ new Function(extra && extra.js ? extra.js : '')(); }catch(e){}
    }

    modal.hidden = false;
    var closeBtn = modal.querySelector('.up-modal__close');
    if(closeBtn){ closeBtn.focus(); }
  }

  function openContent(modal, html, css, js){
    var content = modal.querySelector('[data-content]');
    if(!content) return;
    var style = '<style>'+ (css || '') +'</style>';
    content.innerHTML = style + (html || '');
    try{ new Function(js || '')(); }catch(e){}
    modal.hidden = false;
    var closeBtn = modal.querySelector('.up-modal__close');
    if(closeBtn){ closeBtn.focus(); }
  }

  function closeModal(modal){
    // clear frames to stop playback and clear under-content
    var f = modal.querySelector('[data-frame]');
    if(f) f.innerHTML = '';
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
    window.addEventListener('keydown', function(e){
      if(e.key === 'Escape' && !modal.hidden) closeModal(modal);
    });
  }

  function attach(sn){
    var isVideo = (sn.mode === 'youtube' || sn.mode === 'vimeo');
    var modal = buildModalShell(isVideo);
    wireClose(modal);

    function triggerOpen(){
      // For "Click on class", treat the popup like an explicit user action and
      // avoid frequency gating that can make clicks appear "broken".
      var bypassFrequency = (trig && trig.type === 'class');
      if(!bypassFrequency && !shouldShow(sn.id, sn.frequency.mode, sn.frequency.cooldownMinutes)) return;
      if(isVideo){
        var muteForAutoplay = (trig && trig.type === 'page_load');
        openVideo(modal, sn.mode, sn.video_id, !!sn.autoplay, { html: sn.html, css: sn.css, js: sn.js, muted: muteForAutoplay });
      } else {
        // Use pre-rendered shortcode content if in shortcode mode, otherwise use HTML
        var content = (sn.mode === 'shortcode' && sn.shortcode_content) ? sn.shortcode_content : sn.html;
        openContent(modal, content, sn.css, sn.js);
      }
      if(!bypassFrequency){
        markShown(sn.id, sn.frequency.mode, sn.frequency.cooldownMinutes);
      }
    }

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
      var selector = '.' + trig.value.trim().split(/\s+/).join('.');
      document.addEventListener('click', function(e){
        var el = e.target;
        if(!el.closest) return;
        if(el.closest(selector)){
          e.preventDefault();
          triggerOpen();
        }
      });
    } else if(trig.type === 'id'){
      if(!trig.value) return;
      var idSel = '#' + trig.value.trim();
      document.addEventListener('click', function(e){
        var el = e.target;
        if(!el.closest) return;
        if(el.closest(idSel)){
          e.preventDefault();
          triggerOpen();
        }
      });
    }
  }

  document.addEventListener('DOMContentLoaded', function(){
    try{
      UP_SNIPPETS.forEach(attach);
    }catch(e){}
  });
})();
