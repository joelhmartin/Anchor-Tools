/**
 * Anchor Webinars — tracked Vimeo player.
 *
 * Every player container on the page is booted here, whatever rendered it:
 * the single-webinar template, the [anchor_webinar] shortcode, or a builder
 * layout. Containers carry their own data-webinar-id / data-vimeo-id; the
 * bare legacy #anchor-webinar-player div falls back to the page-level config.
 */
(function(){
  var cfg = window.ANCHOR_WEBINAR;
  if(!cfg || !cfg.ajaxUrl){ return; }
  if(typeof Vimeo === 'undefined' || !Vimeo.Player){ return; }

  var nodes = document.querySelectorAll('[data-anchor-webinar-player], #anchor-webinar-player');
  if(!nodes.length){ return; }

  var booted = {};

  function track(container, webinarId, vimeoId){
    var sessionKey = 'sess_' + Math.random().toString(36).slice(2) + Date.now();
    var player = new Vimeo.Player(container, { id: vimeoId });

    var lastTime = 0;
    var watched = 0;
    var playing = false;
    var sent = false;

    function sendLog(final){
      if(sent){ return; }
      if(watched <= 0){ return; }

      sent = final || false;

      var payload = new FormData();
      payload.append('action', 'anchor_webinar_log');
      payload.append('nonce', cfg.nonce);
      payload.append('webinar_id', webinarId);
      payload.append('seconds', Math.round(watched));
      payload.append('session', sessionKey);

      navigator.sendBeacon && final ?
        navigator.sendBeacon(cfg.ajaxUrl, payload) :
        fetch(cfg.ajaxUrl, { method:'POST', body: payload, credentials:'same-origin' });
    }

    function updateWatched(seconds){
      if(!playing){
        lastTime = seconds;
        return;
      }
      if(lastTime === 0){
        lastTime = seconds;
        return;
      }
      var delta = seconds - lastTime;
      if(delta > 0 && delta < 10){
        watched += delta;
      }
      lastTime = seconds;
    }

    player.on('play', function(){ playing = true; });
    player.on('pause', function(){ playing = false; sendLog(false); });
    player.on('ended', function(){ playing = false; sendLog(true); });
    player.on('timeupdate', function(data){ updateWatched(data.seconds); });

    window.addEventListener('beforeunload', function(){ sendLog(true); });
  }

  Array.prototype.forEach.call(nodes, function(container){
    var webinarId = container.getAttribute('data-webinar-id') || cfg.webinarId;
    var vimeoId   = container.getAttribute('data-vimeo-id') || cfg.vimeoId;
    if(!webinarId || !vimeoId){ return; }

    // A page can carry both a rendered container and the legacy in-content div
    // for the same webinar — boot the first, ignore the duplicate.
    if(booted[webinarId]){ return; }
    booted[webinarId] = true;

    track(container, webinarId, vimeoId);
  });
})();
