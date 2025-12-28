(function(){
  function buildYouTubeSrc(id, autoplay){
    var p = new URLSearchParams({
      autoplay: autoplay ? '1' : '0',
      playsinline: '1',
      rel: '0',
      modestbranding: '1'
    });
    return 'https://www.youtube.com/embed/' + encodeURIComponent(id) + '?' + p.toString();
  }

  function buildVimeoSrc(id, autoplay){
    var p = new URLSearchParams({
      autoplay: autoplay ? '1' : '0',
      byline: '0',
      title: '0',
      portrait: '0',
      dnt: '1'
    });
    return 'https://player.vimeo.com/video/' + encodeURIComponent(id) + '?' + p.toString();
  }

  function getModal(){
    var modal = document.querySelector('.anchor-video-modal');
    if (modal) return modal;

    modal = document.createElement('div');
    modal.className = 'up-modal anchor-video-modal';
    modal.setAttribute('role','dialog');
    modal.setAttribute('aria-modal','true');
    modal.hidden = true;

    var backdrop = document.createElement('div');
    backdrop.className = 'up-modal__backdrop';
    backdrop.setAttribute('data-close','');
    modal.appendChild(backdrop);

    var dialog = document.createElement('div');
    dialog.className = 'up-modal__dialog';

    var close = document.createElement('button');
    close.className = 'up-modal__close';
    close.type = 'button';
    close.setAttribute('aria-label','Close popup');
    close.setAttribute('data-close','');
    close.textContent = 'x';

    var frame = document.createElement('div');
    frame.className = 'up-modal__frame';
    frame.setAttribute('data-frame','');

    dialog.appendChild(close);
    dialog.appendChild(frame);
    modal.appendChild(dialog);

    document.body.appendChild(modal);

    modal.addEventListener('click', function(e){
      if (e.target && e.target.hasAttribute('data-close')) {
        closeModal(modal);
      }
    });
    window.addEventListener('keydown', function(e){
      if (e.key === 'Escape' && !modal.hidden) {
        closeModal(modal);
      }
    });

    return modal;
  }

  function openModal(provider, id, autoplay){
    var modal = getModal();
    var frameWrap = modal.querySelector('[data-frame]');
    if (!frameWrap) return;

    var src = provider === 'youtube'
      ? buildYouTubeSrc(id, autoplay)
      : buildVimeoSrc(id, autoplay);

    frameWrap.innerHTML = '<iframe src="'+src+'" allow="autoplay; fullscreen; picture-in-picture; clipboard-write; encrypted-media" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe>';
    modal.hidden = false;
    var closeBtn = modal.querySelector('.up-modal__close');
    if (closeBtn) closeBtn.focus();
  }

  function closeModal(modal){
    var frameWrap = modal.querySelector('[data-frame]');
    if (frameWrap) frameWrap.innerHTML = '';
    modal.hidden = true;
  }

  document.addEventListener('click', function(e){
    var tile = e.target.closest('.anchor-video-tile');
    if (!tile) return;
    e.preventDefault();
    var provider = tile.getAttribute('data-provider');
    var id = tile.getAttribute('data-video-id');
    var slider = tile.closest('.anchor-video-slider');
    var autoplay = slider && slider.getAttribute('data-autoplay') === '1';
    if (!provider || !id) return;
    openModal(provider, id, autoplay);
  });
})();
