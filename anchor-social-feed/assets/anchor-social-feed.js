(function(){
    function openModal(videoId){
        var modal = document.querySelector('#ssfs-modal');
        if(!modal){
            modal = document.createElement('div');
            modal.id = 'ssfs-modal';
            modal.className = 'ssfs-modal';
            modal.innerHTML = '<div class="ssfs-modal-inner"><button id="ssfs-close" class="ssfs-close" aria-label="Close">\u00d7</button><iframe width="100%" height="100%" src="" frameborder="0" allow="autoplay; encrypted-media" allowfullscreen></iframe></div>';
            document.body.appendChild(modal);
        }
        var iframe = modal.querySelector('iframe');
        iframe.src = 'https://www.youtube.com/embed/' + encodeURIComponent(videoId) + '?autoplay=1&rel=0';
        var iframeInner = modal.querySelector('.ssfs-modal-inner');
        if(iframeInner) iframeInner.style.display = '';
        var vidInner = modal.querySelector('.ssfs-video-modal-inner');
        if(vidInner) vidInner.style.display = 'none';
        modal.classList.add('is-open');
        document.documentElement.classList.add('ssfs-no-scroll');
        document.body.classList.add('ssfs-no-scroll');
    }
    function closeModal(){
        var modal = document.querySelector('#ssfs-modal');
        if(!modal) return;
        var iframe = modal.querySelector('iframe');
        if(iframe) iframe.src = '';
        var vid = modal.querySelector('video');
        if(vid){ vid.pause(); vid.removeAttribute('src'); vid.load(); }
        modal.classList.remove('is-open');
        document.documentElement.classList.remove('ssfs-no-scroll');
        document.body.classList.remove('ssfs-no-scroll');
    }
    function openVideoModal(videoUrl){
        var modal = document.querySelector('#ssfs-modal');
        if(!modal){
            modal = document.createElement('div');
            modal.id = 'ssfs-modal';
            modal.className = 'ssfs-modal';
            modal.innerHTML = '<div class="ssfs-video-modal-inner"><button id="ssfs-close" class="ssfs-close" aria-label="Close">\u00d7</button><video controls playsinline></video></div>';
            document.body.appendChild(modal);
        }
        var inner = modal.querySelector('.ssfs-video-modal-inner');
        if(!inner){
            inner = document.createElement('div');
            inner.className = 'ssfs-video-modal-inner';
            inner.innerHTML = '<button id="ssfs-close" class="ssfs-close" aria-label="Close">\u00d7</button><video controls playsinline></video>';
            var old = modal.querySelector('.ssfs-modal-inner');
            if(old) modal.removeChild(old);
            modal.appendChild(inner);
        }
        var vid = inner.querySelector('video');
        vid.src = videoUrl;
        var iframeInner = modal.querySelector('.ssfs-modal-inner');
        if(iframeInner) iframeInner.style.display = 'none';
        inner.style.display = '';
        modal.classList.add('is-open');
        document.documentElement.classList.add('ssfs-no-scroll');
        document.body.classList.add('ssfs-no-scroll');
        vid.play();
    }
    document.addEventListener('click', function(e){
        var t = e.target.closest('[data-ssfs-video]');
        if(t){ e.preventDefault(); openModal(t.getAttribute('data-ssfs-video')); }
        var igv = e.target.closest('[data-ssfs-ig-video]');
        if(igv){ e.preventDefault(); openVideoModal(igv.getAttribute('data-ssfs-ig-video')); }
        if(e.target.matches('#ssfs-close') || e.target.matches('#ssfs-modal')){ closeModal(); }
        var btn = e.target.closest('[data-ssfs-scroll]');
        if(btn){
            var dir = btn.getAttribute('data-ssfs-scroll');
            var track = btn.closest('.ssfs-card').querySelector('.ssfs-carousel');
            if(track){
                var delta = dir === 'next' ? track.clientWidth : -track.clientWidth;
                track.scrollBy({left: delta, behavior: 'smooth'});
            }
        }
    });
    document.addEventListener('keydown', function(e){
        if(e.key==='Escape'){
            var m = document.querySelector('#ssfs-modal');
            if(m) m.click();
        }
    });
})();
