(function(){
  if(!window.ANCHOR_STORE_LOCATOR){ return; }
  if(!window.google || !google.maps){ return; }

  var config = window.ANCHOR_STORE_LOCATOR;
  var locations = Array.isArray(config.locations) ? config.locations : [];
  var radiusMiles = Number(config.radiusMiles || 50);
  var defaultCenter = {
    lat: Number(config.defaultLat || 0),
    lng: Number(config.defaultLng || 0)
  };
  var defaultZoom = Number(config.defaultZoom || 10);

  function toRad(val){ return val * (Math.PI / 180); }

  function distanceMiles(a, b){
    var R = 3958.8;
    var dLat = toRad(b.lat - a.lat);
    var dLng = toRad(b.lng - a.lng);
    var lat1 = toRad(a.lat);
    var lat2 = toRad(b.lat);
    var sinLat = Math.sin(dLat / 2);
    var sinLng = Math.sin(dLng / 2);
    var h = sinLat * sinLat + Math.cos(lat1) * Math.cos(lat2) * sinLng * sinLng;
    var c = 2 * Math.atan2(Math.sqrt(h), Math.sqrt(1 - h));
    return R * c;
  }

  function debounce(fn, delay){
    var timer;
    return function(){
      var args = arguments;
      window.clearTimeout(timer);
      timer = window.setTimeout(function(){ fn.apply(null, args); }, delay);
    };
  }

  function createCard(loc, distance){
    var article = document.createElement('article');
    article.className = 'anchor-store-card';
    article.setAttribute('tabindex', '0');
    article.dataset.storeId = loc.id;

    var header = document.createElement('div');
    header.className = 'anchor-store-card-header';

    if(loc.image){
      var img = document.createElement('img');
      img.src = loc.image;
      img.alt = loc.title || 'Store image';
      header.appendChild(img);
    }

    var title = document.createElement('h3');
    title.textContent = loc.title || '';
    header.appendChild(title);
    article.appendChild(header);

    var distanceEl = document.createElement('div');
    distanceEl.className = 'anchor-store-distance';
    if(typeof distance === 'number'){
      distanceEl.textContent = distance.toFixed(1) + ' mi';
      article.appendChild(distanceEl);
    }

    if(loc.address){
      var address = document.createElement('div');
      address.className = 'anchor-store-address';
      address.textContent = loc.address;
      article.appendChild(address);
    }

    var meta = document.createElement('div');
    meta.className = 'anchor-store-meta';

    if(loc.phone){
      var phone = document.createElement('div');
      phone.innerHTML = '<strong>Phone:</strong> ' + loc.phone;
      meta.appendChild(phone);
    }
    if(loc.email){
      var email = document.createElement('div');
      email.innerHTML = '<strong>Email:</strong> <a href="mailto:' + loc.email + '">' + loc.email + '</a>';
      meta.appendChild(email);
    }
    if(loc.website){
      var website = document.createElement('div');
      website.innerHTML = '<strong>Website:</strong> <a href="' + loc.website + '" target="_blank" rel="noopener">Visit site</a>';
      meta.appendChild(website);
    }
    if(loc.mapsUrl){
      var maps = document.createElement('div');
      maps.innerHTML = '<a href="' + loc.mapsUrl + '" target="_blank" rel="noopener">View on Google Maps</a>';
      meta.appendChild(maps);
    }

    article.appendChild(meta);

    if(loc.excerpt){
      var excerpt = document.createElement('p');
      excerpt.className = 'anchor-store-excerpt';
      excerpt.textContent = loc.excerpt;
      article.appendChild(excerpt);
    }

    var cta = document.createElement('a');
    cta.className = 'anchor-store-link';
    cta.href = loc.permalink;
    cta.textContent = 'View location';
    article.appendChild(cta);

    return article;
  }

  function initLocator(root){
    var mapEl = root.querySelector('[data-anchor-store-map]');
    var listEl = root.querySelector('[data-anchor-store-results]');
    var searchInput = root.querySelector('[data-anchor-store-search]');
    var geoButton = root.querySelector('[data-anchor-store-geolocate]');
    var statusEl = root.querySelector('[data-anchor-store-status]');

    if(!mapEl || !listEl){ return; }

    var map = new google.maps.Map(mapEl, {
      center: defaultCenter,
      zoom: defaultZoom,
      streetViewControl: false,
      mapTypeControl: false
    });

    var infoWindow = new google.maps.InfoWindow();
    var geocoder = new google.maps.Geocoder();
    var markers = {};
    var activeLocation = defaultCenter;
    var lastSearch = '';

    function setStatus(message){
      if(!statusEl){ return; }
      statusEl.textContent = message || '';
    }

    function highlightResult(id){
      var cards = listEl.querySelectorAll('.anchor-store-card');
      cards.forEach(function(card){
        if(card.dataset.storeId === String(id)){
          card.classList.add('is-active');
          card.scrollIntoView({ behavior: 'smooth', block: 'start' });
        } else {
          card.classList.remove('is-active');
        }
      });
    }

    function renderResults(){
      listEl.innerHTML = '';
      var results = locations.map(function(loc){
        var distance = null;
        if(loc.lat && loc.lng){
          distance = distanceMiles(activeLocation, { lat: Number(loc.lat), lng: Number(loc.lng) });
        }
        return {
          data: loc,
          distance: distance
        };
      }).filter(function(item){
        if(item.distance === null || isNaN(item.distance)){
          return false;
        }
        return item.distance <= radiusMiles;
      }).sort(function(a, b){
        return a.distance - b.distance;
      });

      if(results.length === 0){
        listEl.innerHTML = '<div class="anchor-store-empty">No locations within range.</div>';
      }

      results.forEach(function(item){
        var card = createCard(item.data, item.distance);
        card.addEventListener('click', function(){
          var marker = markers[item.data.id];
          if(marker){
            map.panTo(marker.getPosition());
            map.setZoom(Math.max(map.getZoom(), 12));
            infoWindow.setContent('<strong>' + (item.data.title || '') + '</strong>');
            infoWindow.open(map, marker);
          }
          highlightResult(item.data.id);
        });
        card.addEventListener('keydown', function(evt){
          if(evt.key === 'Enter' || evt.key === ' '){
            evt.preventDefault();
            card.click();
          }
        });
        listEl.appendChild(card);
      });

      Object.keys(markers).forEach(function(id){
        var marker = markers[id];
        var visible = results.some(function(item){ return String(item.data.id) === String(id); });
        marker.setVisible(visible);
      });

      if(results.length){
        setStatus('Showing ' + results.length + ' locations within ' + radiusMiles + ' miles.');
      } else {
        setStatus('No locations found within ' + radiusMiles + ' miles.');
      }
    }

    function setActiveLocation(latLng, label){
      activeLocation = { lat: Number(latLng.lat), lng: Number(latLng.lng) };
      map.setCenter(activeLocation);
      if(map.getZoom() < defaultZoom){
        map.setZoom(defaultZoom);
      }
      if(label && searchInput){
        searchInput.value = label;
      }
      renderResults();
    }

    function geocodeAddress(address){
      if(!address){ return; }
      setStatus('Searching...');
      geocoder.geocode({ address: address }, function(results, status){
        if(status === 'OK' && results[0]){
          var loc = results[0].geometry.location;
          setActiveLocation({ lat: loc.lat(), lng: loc.lng() }, results[0].formatted_address);
          setStatus('');
        } else {
          setStatus('Location not found.');
        }
      });
    }

    var debouncedSearch = debounce(function(value){
      if(value && value.length >= 3 && value !== lastSearch){
        lastSearch = value;
        geocodeAddress(value);
      }
    }, 500);

    if(searchInput){
      var autocomplete = new google.maps.places.Autocomplete(searchInput, {
        types: ['geocode']
      });
      autocomplete.addListener('place_changed', function(){
        var place = autocomplete.getPlace();
        if(place && place.geometry && place.geometry.location){
          setActiveLocation({ lat: place.geometry.location.lat(), lng: place.geometry.location.lng() }, place.formatted_address || place.name);
          setStatus('');
        }
      });

      searchInput.addEventListener('input', function(e){
        debouncedSearch(e.target.value || '');
      });

      searchInput.addEventListener('keydown', function(e){
        if(e.key === 'Enter'){
          e.preventDefault();
          geocodeAddress(e.target.value || '');
        }
      });
    }

    function useCurrentLocation(){
      if(!navigator.geolocation){
        setStatus('Geolocation is not supported.');
        setActiveLocation(defaultCenter);
        return;
      }
      setStatus('Finding your location...');
      navigator.geolocation.getCurrentPosition(function(position){
        setStatus('');
        setActiveLocation({
          lat: position.coords.latitude,
          lng: position.coords.longitude
        });
      }, function(){
        setStatus('Using default location.');
        setActiveLocation(defaultCenter);
      });
    }

    if(geoButton){
      geoButton.addEventListener('click', function(){
        useCurrentLocation();
      });
    }

    locations.forEach(function(loc){
      if(!loc.lat || !loc.lng){ return; }
      var marker = new google.maps.Marker({
        position: { lat: Number(loc.lat), lng: Number(loc.lng) },
        map: map,
        title: loc.title || ''
      });
      markers[loc.id] = marker;
      marker.addListener('click', function(){
        map.panTo(marker.getPosition());
        infoWindow.setContent('<strong>' + (loc.title || '') + '</strong>');
        infoWindow.open(map, marker);
        highlightResult(loc.id);
      });
    });

    renderResults();
    useCurrentLocation();
  }

  var roots = document.querySelectorAll('[data-anchor-store-locator]');
  roots.forEach(function(root){
    initLocator(root);
  });
})();
