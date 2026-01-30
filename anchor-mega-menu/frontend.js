(function(){
  if(!window.MM_SNIPPETS || !Array.isArray(MM_SNIPPETS)) return;

  // Detect snippets that are rendered inline via shortcode so we don't also
  // spawn floating panels for them.
  var inlineIds = Array.prototype.slice.call(
    document.querySelectorAll('.mm-snippet-inline[data-mm-shortcode][data-mm-id]')
  ).map(function(node){
    var v = parseInt(node.getAttribute('data-mm-id'), 10);
    return isNaN(v) ? null : v;
  }).filter(function(v){ return v !== null; });

  function createPanel(sn){
    var panel = document.createElement('div');
    panel.className = 'mm-panel mm-anim-'+sn.settings.animation;
    panel.style.zIndex = sn.settings.zIndex || 9999;
    panel.style.position = (sn.settings.absolute ? 'absolute' : 'fixed'); // inline to avoid layout
    panel.style.top  = '-9999px';  // offscreen before first open
    panel.style.left = '-9999px';
    panel.style.opacity = '0';
    panel.style.pointerEvents = 'none';
    panel.setAttribute('data-mm-id', sn.id);

    // inner viewport
    var styleTag = document.createElement('style');
    styleTag.textContent = sn.css || '';
    var viewport = document.createElement('div');
    viewport.className = 'mm-viewport';
    var maxHeight = sn.settings.maxHeight;
    if (maxHeight === null || maxHeight === undefined || maxHeight === '' || maxHeight === 0 || maxHeight === 'none') {
      viewport.style.maxHeight = 'none';
    } else {
      var maxPx = parseInt(maxHeight, 10);
      viewport.style.maxHeight = (!isNaN(maxPx) && maxPx > 0) ? (maxPx + 'px') : 'none';
    }
    viewport.style.overflow = 'auto';
    viewport.innerHTML = sn.html || '';

    panel.appendChild(styleTag);
    panel.appendChild(viewport);

    // Optional arrow
    if (sn.settings.arrow){
      var arrow = document.createElement('div');
      arrow.className = 'mm-arrow';
      arrow.style.setProperty('--mm-arrow-color', sn.settings.arrowColor || '#ffffff');
      arrow.style.setProperty('--mm-arrow-size', (sn.settings.arrowSize||10)+'px');
      panel.appendChild(arrow);
    }

    document.body.appendChild(panel);

    // Run inline JS safely
    try { new Function(sn.js || '')(); } catch(e){}

    return panel;
  }

  function placePanel(panel, trigger, sn){
    var rect = trigger.getBoundingClientRect();
    var scrollY = window.scrollY || window.pageYOffset;
    var scrollX = window.scrollX || window.pageXOffset;
    var x = rect.left + scrollX;
    var y = rect.top + scrollY;

    var pw = panel.offsetWidth;
    var ph = panel.offsetHeight;

    var offsetX = sn.settings.offsetX || 0;
    var offsetY = sn.settings.offsetY || 8;

    var top = 0, left = 0;

    switch(sn.settings.position){
      case 'above':
        top = y - ph - offsetY;
        left = x + rect.width/2 - pw/2 + offsetX;
        break;
      case 'left':
        top = y + rect.height/2 - ph/2 + offsetY;
        left = x - pw - offsetX;
        break;
      case 'right':
        top = y + rect.height/2 - ph/2 + offsetY;
        left = x + rect.width + offsetX;
        break;
      case 'below':
      default:
        top = y + rect.height + offsetY;
        left = x + rect.width/2 - pw/2 + offsetX;
    }

    // Constrain horizontally so panel stays in viewport
    var maxLeft = scrollX + window.innerWidth - pw - 8;
    var minLeft = scrollX + 8;
    left = Math.max(minLeft, Math.min(maxLeft, left));

    // Apply and set orientation class
    panel.style.top = top + 'px';
    panel.style.left = left + 'px';
    panel.classList.remove('mm-pos-below','mm-pos-above','mm-pos-left','mm-pos-right');
    panel.classList.add('mm-pos-' + (sn.settings.position || 'below'));

    // Position arrow if present
    positionArrow(panel, trigger, sn);
  }

  function clamp(v, min, max){ return Math.max(min, Math.min(max, v)); }

  function positionArrow(panel, trigger, sn){
    var arrow = panel.querySelector('.mm-arrow');
    if(!arrow) return;
    var size = sn.settings.arrowSize || 10;
    var padding = size + 8; // keep arrow inside panel edges

    var rectTrig = trigger.getBoundingClientRect();
    var rectPanel = panel.getBoundingClientRect();
    var scrollY = window.scrollY || window.pageYOffset;
    var scrollX = window.scrollX || window.pageXOffset;

    var absLeft = rectPanel.left + scrollX;
    var absTop  = rectPanel.top  + scrollY;

    // Reset
    arrow.style.left = ''; arrow.style.right=''; arrow.style.top=''; arrow.style.bottom='';

    if(sn.settings.position === 'below' || sn.settings.position === 'above'){
      var anchor; // absolute X pos we want the arrow to sit under
      switch(sn.settings.arrowAlign){
        case 'center': anchor = absLeft + rectPanel.width/2; break;
        case 'start':  anchor = absLeft + padding; break;
        case 'end':    anchor = absLeft + rectPanel.width - padding; break;
        case 'auto':
        default:
          anchor = (rectTrig.left + scrollX) + rectTrig.width/2; // trigger center
      }
      anchor += (sn.settings.arrowOffset || 0);
      var leftPx = clamp(anchor - absLeft, padding, rectPanel.width - padding);
      arrow.style.left = Math.round(leftPx) + 'px';
    } else {
      var anchorY;
      switch(sn.settings.arrowAlign){
        case 'center': anchorY = absTop + rectPanel.height/2; break;
        case 'start':  anchorY = absTop + padding; break;
        case 'end':    anchorY = absTop + rectPanel.height - padding; break;
        case 'auto':
        default:
          anchorY = (rectTrig.top + scrollY) + rectTrig.height/2;
      }
      anchorY += (sn.settings.arrowOffset || 0);
      var topPx = clamp(anchorY - absTop, padding, rectPanel.height - padding);
      arrow.style.top = Math.round(topPx) + 'px';
    }
  }

  function attach(sn){
    if(!sn.trigger_class) return;
    var selector = '.' + sn.trigger_class.trim().split(/\s+/).join('.');
    var triggers = document.querySelectorAll(selector);
    if(!triggers.length) return;

    var panel = createPanel(sn);
    var hideTimer = null;
    var showing = false;
    var activeTrigger = null;

    function open(trigger){
      // render so we can measure
      panel.style.display = 'block';
      panel.style.visibility = 'hidden';
      placePanel(panel, trigger, sn);
      panel.style.visibility = '';
      panel.classList.add('mm-open');
      panel.style.opacity = '1';
      panel.style.pointerEvents = 'auto';
      showing = true;
      activeTrigger = trigger;
    }

    function scheduleClose(){
      clearTimeout(hideTimer);
      var delay = sn.settings.hoverDelay || 200;
      hideTimer = setTimeout(close, delay);
    }

    function close(){
      panel.classList.remove('mm-open');
      panel.style.opacity = '0';
      panel.style.pointerEvents = 'none';
      showing = false;
      activeTrigger = null;
      setTimeout(function(){ panel.style.display = 'none'; }, 200);
    }

    triggers.forEach(function(tr){
      tr.addEventListener('mouseenter', function(){ clearTimeout(hideTimer); open(tr); });
      tr.addEventListener('mouseleave', scheduleClose);
      tr.addEventListener('focus', function(){ open(tr); }, true);
      tr.addEventListener('blur', scheduleClose, true);
    });

    panel.addEventListener('mouseenter', function(){ clearTimeout(hideTimer); });
    panel.addEventListener('mouseleave', scheduleClose);

    ['scroll','resize'].forEach(function(evt){
      window.addEventListener(evt, function(){
        if(showing && activeTrigger){
          placePanel(panel, activeTrigger, sn);
        }
      }, { passive:true });
    });
  }

  MM_SNIPPETS.forEach(function(sn){
    if (inlineIds.indexOf(sn.id) !== -1) {
      // Already rendered via shortcode; skip floating panel behavior.
      return;
    }
    attach(sn);
  });
})();
