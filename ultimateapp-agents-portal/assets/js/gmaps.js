/* Ultimate App Google Maps helper: map pickers ([data-map-picker]) and viewers ([data-map-view]).
   Config comes from data-gm-* attributes (Admin > Settings > Maps). Without a key, the fallback content stays. */
(() => {
  let loading = null;
  const cfgEl = document.querySelector('[data-gm-key]');
  if (!cfgEl) return;
  const cfg = { key: cfgEl.dataset.gmKey || '', lat: Number(cfgEl.dataset.gmLat) || 11.9674, lng: Number(cfgEl.dataset.gmLng) || 121.9248, zoom: Number(cfgEl.dataset.gmZoom) || 14 };
  const load = () => {
    if (window.google && google.maps && google.maps.Map) return Promise.resolve(google.maps);
    if (loading) return loading;
    loading = new Promise((resolve, reject) => {
      window.__uaMapsReady = () => resolve(google.maps);
      const s = document.createElement('script');
      s.src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent(cfg.key) + '&loading=async&v=weekly&callback=__uaMapsReady&language=en&region=PH';
      s.async = true;
      s.onerror = () => reject(new Error('Google Maps could not load.'));
      document.head.append(s);
    });
    return loading;
  };
  const status = (el, text) => { const s = el.parentElement.querySelector('[data-map-status]'); if (s) s.textContent = text; };
  const pickers = [...document.querySelectorAll('[data-map-picker]')];
  const views = [...document.querySelectorAll('[data-map-view]')];
  if (!pickers.length && !views.length) return;
  if (!cfg.key) { [...pickers, ...views].forEach(el => el.classList.add('map-off')); return; }

  load().then(maps => {
    pickers.forEach(el => {
      const latIn = document.getElementById(el.dataset.latInput);
      const lngIn = document.getElementById(el.dataset.lngInput);
      const has = latIn.value !== '' && lngIn.value !== '';
      const start = has ? {lat: Number(latIn.value), lng: Number(lngIn.value)} : {lat: cfg.lat, lng: cfg.lng};
      el.innerHTML = '';
      const map = new maps.Map(el, {center: start, zoom: has ? 18 : cfg.zoom, maxZoom: 21, mapTypeControl: true, mapTypeControlOptions: {mapTypeIds: ['roadmap', 'hybrid']}, streetViewControl: false, fullscreenControl: true, clickableIcons: false, gestureHandling: 'cooperative'});
      const marker = new maps.Marker({map, position: has ? start : null, draggable: true, title: 'Business location'});
      const set = (pos) => {
        const lat = typeof pos.lat === 'function' ? pos.lat() : pos.lat;
        const lng = typeof pos.lng === 'function' ? pos.lng() : pos.lng;
        marker.setPosition({lat, lng});
        latIn.value = lat.toFixed(7); lngIn.value = lng.toFixed(7);
        status(el, 'Pinned at ' + lat.toFixed(5) + ', ' + lng.toFixed(5) + '. Drag the pin to adjust.');
      };
      map.addListener('click', e => set(e.latLng));
      marker.addListener('dragend', e => set(e.latLng));
      if (has) status(el, 'Drag the pin to adjust your location.'); else status(el, 'Tap the map where your business is.');
      const locate = el.parentElement.querySelector('[data-map-locate]');
      if (locate) locate.addEventListener('click', () => {
        if (!navigator.geolocation) { status(el, 'Location is not available on this device.'); return; }
        status(el, 'Finding your location…');
        navigator.geolocation.getCurrentPosition(p => { const pos = {lat: p.coords.latitude, lng: p.coords.longitude}; map.setCenter(pos); map.setZoom(18); set(pos); },
          () => status(el, 'Could not get your location. Tap the map instead.'), {enableHighAccuracy: true, timeout: 10000});
      });
      const clear = el.parentElement.querySelector('[data-map-clear]');
      if (clear) clear.addEventListener('click', () => { marker.setPosition(null); latIn.value = ''; lngIn.value = ''; status(el, 'Pin removed.'); });
    });
    views.forEach(el => {
      let points = [];
      try { points = JSON.parse(el.dataset.markers || '[]'); } catch (e) { points = []; }
      el.innerHTML = '';
      const map = new maps.Map(el, {center: {lat: cfg.lat, lng: cfg.lng}, zoom: cfg.zoom, mapTypeControl: false, streetViewControl: false, fullscreenControl: true, clickableIcons: false});
      const info = new maps.InfoWindow();
      const bounds = new maps.LatLngBounds();
      points.forEach(pt => {
        const pos = {lat: Number(pt.lat), lng: Number(pt.lng)};
        const marker = new maps.Marker({map, position: pos, title: pt.title});
        bounds.extend(pos);
        marker.addListener('click', () => {
          const box = document.createElement('div');
          box.className = 'map-info';
          const b = document.createElement('b'); b.textContent = pt.title; box.append(b);
          if (pt.sub) { const s = document.createElement('div'); s.textContent = pt.sub; box.append(s); }
          const a = document.createElement('a'); a.href = 'https://www.google.com/maps/dir/?api=1&destination=' + pos.lat + ',' + pos.lng; a.target = '_blank'; a.rel = 'noopener'; a.textContent = 'Directions'; box.append(a);
          info.setContent(box); info.open({map, anchor: marker});
        });
      });
      if (points.length === 1) { map.setCenter({lat: Number(points[0].lat), lng: Number(points[0].lng)}); map.setZoom(17); }
      else if (points.length > 1) map.fitBounds(bounds, 48);
    });
  }).catch(err => [...pickers, ...views].forEach(el => { el.classList.add('map-off'); status(el, err.message + ' Check the key in Admin > Settings > Maps.'); }));
})();
