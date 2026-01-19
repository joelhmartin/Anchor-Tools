(function(){
  if(!window.ANCHOR_WEBINAR){ return; }

  var data = window.ANCHOR_WEBINAR;
  var container = document.getElementById('anchor-webinar-player');
  if(!container){ return; }

  var sessionKey = 'sess_' + Math.random().toString(36).slice(2) + Date.now();
  var player = new Vimeo.Player(container, {
    id: data.vimeoId,
    responsive: true
  });

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
    payload.append('nonce', data.nonce);
    payload.append('webinar_id', data.webinarId);
    payload.append('seconds', Math.round(watched));
    payload.append('session', sessionKey);

    navigator.sendBeacon && final ?
      navigator.sendBeacon(data.ajaxUrl, payload) :
      fetch(data.ajaxUrl, { method:'POST', body: payload, credentials:'same-origin' });
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
})();
