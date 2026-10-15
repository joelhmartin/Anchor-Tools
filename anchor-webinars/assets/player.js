/**
 * Anchor Webinars — tracked player (Vimeo or YouTube).
 *
 * Every player container on the page is booted here, whatever rendered it:
 * the single-webinar template, the [anchor_webinar] / [anchor_webinar_registered]
 * shortcodes, or a builder layout. Containers carry data-webinar-id plus
 * data-vimeo-id or data-youtube-id (and data-registrant for a form registrant);
 * the bare legacy #anchor-webinar-player div falls back to the page-level config.
 *
 * Both providers feed the same counter: seconds actually played (seeking ahead
 * does not count), the video's duration, and — when present — the registrant key,
 * beaconed to admin-ajax on pause / end / page exit. Progress milestones are also
 * pushed to window.dataLayer (webinar_start, webinar_progress 25/50/75, webinar_complete).
 */
(function(){
  var cfg = window.ANCHOR_WEBINAR;
  if(!cfg || !cfg.ajaxUrl){ return; }

  var nodes = document.querySelectorAll('[data-anchor-webinar-player], #anchor-webinar-player');
  if(!nodes.length){ return; }

  var booted = {};

  // One tracker per player: provider adapters call tick(seconds) while playing.
  function makeTracker(container, webinarId){
    var sessionKey = 'sess_' + Math.random().toString(36).slice(2) + Date.now();
    var registrant = container.getAttribute('data-registrant') || '';
    var title = container.closest('[data-webinar-title]');
    title = title ? title.getAttribute('data-webinar-title') : document.title;
    var lastTime = null, watched = 0, duration = 0, playing = false, finalSent = false;
    var milestones = { 25:false, 50:false, 75:false };

    function push(event, extra){
      window.dataLayer = window.dataLayer || [];
      var o = { event: event, webinar_id: String(webinarId), webinar_title: title };
      for(var k in extra){ o[k] = extra[k]; }
      window.dataLayer.push(o);
    }

    function send(final){
      if(finalSent || watched <= 0){ return; }
      if(final){ finalSent = true; }
      var payload = new FormData();
      payload.append('action', 'anchor_webinar_log');
      payload.append('nonce', cfg.nonce || '');
      payload.append('webinar_id', webinarId);
      payload.append('seconds', Math.round(watched));
      payload.append('duration', Math.round(duration));
      payload.append('session', sessionKey);
      if(registrant){ payload.append('registrant', registrant); }
      if(final && navigator.sendBeacon){ navigator.sendBeacon(cfg.ajaxUrl, payload); }
      else { fetch(cfg.ajaxUrl, { method:'POST', body: payload, credentials:'same-origin', keepalive:true }); }
    }

    function checkMilestones(){
      if(!duration){ return; }
      var pct = 100 * watched / duration;
      [25, 50, 75].forEach(function(m){
        if(!milestones[m] && pct >= m){ milestones[m] = true; push('webinar_progress', { webinar_percent: m }); }
      });
    }

    window.addEventListener('pagehide', function(){ send(true); });

    return {
      setDuration: function(d){ if(d > 0){ duration = d; } },
      play: function(){ if(!playing && watched === 0){ push('webinar_start', {}); } playing = true; },
      pause: function(){ playing = false; lastTime = null; send(false); },
      ended: function(){ playing = false; lastTime = null; push('webinar_complete', { webinar_percent: 100 }); send(false); },
      tick: function(seconds){
        if(!playing){ lastTime = seconds; return; }
        if(lastTime !== null){
          var delta = seconds - lastTime;
          if(delta > 0 && delta < 10){ watched += delta; checkMilestones(); }
        }
        lastTime = seconds;
      }
    };
  }

  function bootVimeo(container, webinarId, vimeoId){
    if(typeof Vimeo === 'undefined' || !Vimeo.Player){ return; }
    var t = makeTracker(container, webinarId);
    var player = new Vimeo.Player(container, { id: vimeoId });
    player.getDuration().then(t.setDuration).catch(function(){});
    player.on('play', t.play);
    player.on('pause', t.pause);
    player.on('ended', t.ended);
    player.on('timeupdate', function(d){ if(d.duration){ t.setDuration(d.duration); } t.tick(d.seconds); });
  }

  var ytQueue = [];
  function bootYouTube(container, webinarId, videoId){
    if(window.YT && window.YT.Player){ startYouTube(container, webinarId, videoId); return; }
    ytQueue.push([container, webinarId, videoId]);
  }
  function startYouTube(container, webinarId, videoId){
    var t = makeTracker(container, webinarId);
    var target = container.querySelector('.anchor-webinar-player__yt') || container.appendChild(document.createElement('div'));
    var timer = null;
    var player = new YT.Player(target, {
      videoId: videoId,
      playerVars: { rel: 0, playsinline: 1, modestbranding: 1 },
      events: {
        onReady: function(){ t.setDuration(player.getDuration()); },
        onStateChange: function(e){
          if(e.data === YT.PlayerState.PLAYING){
            t.setDuration(player.getDuration());
            t.play();
            if(!timer){ timer = setInterval(function(){ t.tick(player.getCurrentTime()); }, 1000); }
          } else {
            if(timer){ clearInterval(timer); timer = null; }
            if(e.data === YT.PlayerState.ENDED){ t.tick(player.getCurrentTime()); t.ended(); }
            else if(e.data === YT.PlayerState.PAUSED){ t.tick(player.getCurrentTime()); t.pause(); }
          }
        }
      }
    });
  }
  // The IFrame API calls this global once loaded; keep any handler another script set.
  var prevReady = window.onYouTubeIframeAPIReady;
  window.onYouTubeIframeAPIReady = function(){
    if(typeof prevReady === 'function'){ prevReady(); }
    ytQueue.splice(0).forEach(function(a){ startYouTube(a[0], a[1], a[2]); });
  };

  Array.prototype.forEach.call(nodes, function(container){
    var webinarId = container.getAttribute('data-webinar-id') || cfg.webinarId;
    var youtubeId = container.getAttribute('data-youtube-id');
    var vimeoId   = container.getAttribute('data-vimeo-id') || (youtubeId ? '' : cfg.vimeoId);
    if(!webinarId || (!vimeoId && !youtubeId)){ return; }

    // A page can carry both a rendered container and the legacy in-content div
    // for the same webinar — boot the first, ignore the duplicate.
    if(booted[webinarId]){ return; }
    booted[webinarId] = true;

    if(youtubeId){ bootYouTube(container, webinarId, youtubeId); }
    else { bootVimeo(container, webinarId, vimeoId); }
  });
})();
