(() => {
  const form = document.getElementById('uride-form');
  if (!form) return;
  const mapElement = document.getElementById('uride-map');
  const status = document.getElementById('uride-map-status');
  const distanceLabel = document.getElementById('uride-distance');
  const inputs = {pickup: document.getElementById('uride-pickup'), dropoff: document.getElementById('uride-dropoff')};
  const lists = {pickup: document.getElementById('uride-pickup-results'), dropoff: document.getElementById('uride-dropoff-results')};
  const points = {pickup: null, dropoff: null};
  const markers = {pickup: null, dropoff: null};
  const tokens = {pickup: null, dropoff: null};
  const requestIds = {pickup: 0, dropoff: 0};
  const timers = {pickup: null, dropoff: null};
  const center0 = {lat: Number(mapElement.dataset.lat) || 11.9674, lng: Number(mapElement.dataset.lng) || 121.9248};
  let mode = 'pickup';
  let map = null;
  let geocoder = null;
  let accuracyCircle = null;
  let AdvancedMarkerElement, PinElement, AutocompleteSuggestion, AutocompleteSessionToken, LegacyAutocomplete;
  let googleSearch = 'off'; // off | new | legacy | failed
  const say = text => { status.textContent = text; };
  const mapNote = text => { const p = document.createElement('p'); p.textContent = text; mapElement.replaceChildren(p); };
  /* Google calls this when the key is refused (wrong referrer, API off, billing). Keep booking working without the map. */
  function mapOff(message) {
    map = null; geocoder = null; googleSearch = 'failed';
    markers.pickup = markers.dropoff = null; accuracyCircle = null;
    mapNote('Map unavailable — search a place above to set your pins.');
    const sat = document.querySelector('[data-map-type]'); if (sat) sat.hidden = true;
    if (message) say(message);
  }
  window.gm_authFailure = () => mapOff('Google Maps refused this key (check its allowed websites and APIs in Admin › Settings › Maps). You can still choose places from the suggestions.');
  const safe = fn => { try { fn(); } catch (e) { console.warn('URide map:', e && e.message); } };
  const mapCenter = () => { try { const c = map && map.getCenter(); return c ? c.toJSON() : center0; } catch (e) { return center0; } };

  function setMode(next) {
    mode = next;
    document.querySelectorAll('[data-map-mode]').forEach(b => b.classList.toggle('active', b.dataset.mapMode === next));
    say(form.dataset.mode === 'rental' ? 'Search or tap the map to set the pick-up. Drag the pin to fine-tune it.' : `Search or tap the map to set ${next === 'pickup' ? 'pick-up' : 'drop-off'}. Drag a pin to fine-tune it.`);
  }

  function closeList(kind) {
    lists[kind].replaceChildren();
    lists[kind].hidden = true;
    inputs[kind].setAttribute('aria-expanded', 'false');
  }

  function clearPoint(kind) {
    points[kind] = null;
    document.getElementById(`uride-${kind}-lat`).value = '';
    document.getElementById(`uride-${kind}-lng`).value = '';
    if (markers[kind]) { const m = markers[kind]; markers[kind] = null; safe(() => { m.map = null; }); }
    if (kind === 'pickup' && accuracyCircle) { const c = accuracyCircle; accuracyCircle = null; safe(() => c.setMap(null)); }
    distanceLabel.textContent = 'Set both pins';
    showQuote(null);
  }

  /* ---------- Server fare quote (Admin fare matrix). The booking recomputes the same fare on the server. */
  const fareLabel = document.getElementById('uride-fare');
  let quote = null;
  let quoteSeq = 0;
  const selectedVehicle = () => { const r = form.querySelector('input[name="vehicle_type"]:checked'); return r ? r.value : ''; };
  function showQuote(q) {
    quote = q;
    form.querySelectorAll('[data-vehicle-fare]').forEach(el => {
      const f = q && q.fares[el.dataset.vehicleFare];
      if (el.dataset.base === undefined) el.dataset.base = el.textContent;
      el.textContent = f ? (f.enabled ? '₱' + f.fare : 'Unavailable') : el.dataset.base;
    });
    const f = q && q.fares[selectedVehicle()];
    if (fareLabel) fareLabel.textContent = f ? (f.enabled ? '₱' + f.fare : 'Not available') : '—';
    const road = document.getElementById('uride-road');
    if (road) road.textContent = q ? q.road_km.toFixed(1) + ' km est. road distance' : '';
  }
  async function updateQuote() {
    if (!points.pickup || !points.dropoff) return;
    const seq = ++quoteSeq;
    if (fareLabel) fareLabel.textContent = 'Calculating…';
    const qs = new URLSearchParams({pickup_lat: points.pickup.lat.toFixed(7), pickup_lng: points.pickup.lng.toFixed(7), dropoff_lat: points.dropoff.lat.toFixed(7), dropoff_lng: points.dropoff.lng.toFixed(7)});
    try {
      const r = await fetch('uride-quote.php?' + qs, {credentials: 'same-origin', cache: 'no-store'});
      const j = await r.json();
      if (seq !== quoteSeq) return;
      if (!r.ok) { showQuote(null); if (fareLabel) fareLabel.textContent = j.error || 'Fare unavailable'; return; }
      showQuote(j);
    } catch (e) { if (seq === quoteSeq && fareLabel) fareLabel.textContent = 'Fare unavailable offline'; }
  }
  form.querySelectorAll('input[name="vehicle_type"]').forEach(r => r.addEventListener('change', () => showQuote(quote)));

  function distance(a, b) {
    const rad = Math.PI / 180;
    const dLat = (b.lat - a.lat) * rad, dLng = (b.lng - a.lng) * rad;
    const h = Math.sin(dLat / 2) ** 2 + Math.cos(a.lat * rad) * Math.cos(b.lat * rad) * Math.sin(dLng / 2) ** 2;
    return 6371 * 2 * Math.asin(Math.min(1, Math.sqrt(h)));
  }
  const inPH = p => p.lat >= 4 && p.lat <= 22 && p.lng >= 116 && p.lng <= 127;
  const pinnedLabel = p => `Pinned location (${p.lat.toFixed(6)}, ${p.lng.toFixed(6)})`;

  /* ---------- Name a point: Google reverse geocoding when the key allows it, else the nearest landmark. */
  async function nameFor(point) {
    if (geocoder) {
      try {
        const {results} = await geocoder.geocode({location: point});
        const best = results.find(r => !r.types.includes('plus_code')) || results[0];
        if (best) return best.formatted_address.replace(/, Philippines$/, '');
      } catch (e) { geocoder = null; /* Geocoding API not enabled for this key */ }
    }
    try {
      const r = await fetch(`uride-places.php?lat=${point.lat.toFixed(7)}&lng=${point.lng.toFixed(7)}`, {credentials: 'same-origin', cache: 'no-store'});
      const j = await r.json();
      if (j.near) return 'Near ' + j.near;
    } catch (e) { /* offline */ }
    return null;
  }

  function writePoint(kind, point, address) {
    points[kind] = point;
    inputs[kind].value = (address || pinnedLabel(point)).slice(0, 255);
    document.getElementById(`uride-${kind}-lat`).value = point.lat.toFixed(7);
    document.getElementById(`uride-${kind}-lng`).value = point.lng.toFixed(7);
  }

  function afterPlaced(kind) {
    if (isRental()) {
      if (map) safe(() => { map.panTo(points.pickup || points[kind]); map.setZoom(Math.max(map.getZoom() || 0, 18)); });
      say('Pick-up is set. Drag the pin to fine-tune it.');
      return;
    }
    if (points.pickup && points.dropoff) {
      if (map) safe(() => {
        const bounds = new google.maps.LatLngBounds();
        bounds.extend(points.pickup); bounds.extend(points.dropoff);
        map.fitBounds(bounds, 56);
      });
      distanceLabel.textContent = `${distance(points.pickup, points.dropoff).toFixed(2)} km straight line`;
      say('Both pins are set. Drag a pin to fine-tune it.');
      updateQuote();
    } else {
      if (map) safe(() => { map.panTo(points[kind]); map.setZoom(Math.max(map.getZoom() || 0, 18)); });
      setMode(kind === 'pickup' ? 'dropoff' : 'pickup');
    }
  }

  /** Sets a pin. address = null means "name it from the map" (reverse geocode in the background). */
  function place(kind, point, address, opts = {}) {
    if (!inPH(point)) { say('Choose a location in the Philippines.'); return; }
    ++requestIds[kind];
    clearTimeout(timers[kind]);
    tokens[kind] = null;
    clearPoint(kind);
    writePoint(kind, point, address);
    closeList(kind);
    if (map && AdvancedMarkerElement) safe(() => {
      const color = kind === 'pickup' ? '#0f8a4f' : '#d0342c';
      const pin = new PinElement({background: color, borderColor: '#ffffff', glyphColor: '#fff', glyph: kind === 'pickup' ? 'P' : 'D', scale: 1.15});
      const marker = new AdvancedMarkerElement({map, position: point, title: (kind === 'pickup' ? 'Pick-up' : 'Drop-off') + ' (drag to adjust)', content: pin.element, gmpDraggable: true});
      marker.addListener('dragend', async () => {
        const pos = marker.position;
        const p = {lat: typeof pos.lat === 'function' ? pos.lat() : pos.lat, lng: typeof pos.lng === 'function' ? pos.lng() : pos.lng};
        if (!inPH(p)) { say('Choose a location in the Philippines.'); return; }
        if (kind === 'pickup' && accuracyCircle) { accuracyCircle.setMap(null); accuracyCircle = null; }
        writePoint(kind, p, null);
        say(`${kind === 'pickup' ? 'Pick-up' : 'Drop-off'} moved to ${p.lat.toFixed(6)}, ${p.lng.toFixed(6)}.`);
        if (points.pickup && points.dropoff) { distanceLabel.textContent = `${distance(points.pickup, points.dropoff).toFixed(2)} km straight line`; updateQuote(); }
        const name = await nameFor(p);
        if (name && points[kind] === p) inputs[kind].value = name.slice(0, 255);
      });
      markers[kind] = marker;
    });
    afterPlaced(kind);
    if (address === null && !opts.keepLabel) {
      nameFor(point).then(name => { if (name && points[kind] === point) inputs[kind].value = name.slice(0, 255); });
    }
  }

  /* ---------- Suggestions: Ultimate App places (always) + Google Places (when the key allows) */
  const KIND_LABEL = {recent: 'Recent', landmark: 'Boracay', merchant: 'Merchant', google: 'Google'};
  function renderList(kind, items) {
    const list = lists[kind];
    list.replaceChildren();
    for (const item of items) {
      const button = document.createElement('button');
      button.type = 'button';
      button.setAttribute('role', 'option');
      const title = document.createElement('strong'); title.textContent = item.name;
      const sub = document.createElement('small'); sub.textContent = item.sub || '';
      const tag = document.createElement('em'); tag.textContent = KIND_LABEL[item.kind] || ''; tag.className = 'k-' + item.kind;
      button.append(tag, title, sub);
      button.addEventListener('pointerdown', e => e.preventDefault());
      button.addEventListener('click', () => choose(kind, item));
      button.addEventListener('keydown', e => {
        if (e.key === 'ArrowDown') { e.preventDefault(); (button.nextElementSibling || button).focus(); }
        if (e.key === 'ArrowUp') { e.preventDefault(); (button.previousElementSibling || inputs[kind]).focus(); }
        if (e.key === 'Escape') { closeList(kind); inputs[kind].focus(); }
      });
      list.append(button);
    }
    list.hidden = !items.length;
    inputs[kind].setAttribute('aria-expanded', String(!list.hidden));
  }

  async function choose(kind, item) {
    ++requestIds[kind];
    closeList(kind);
    if (item.kind !== 'google') { place(kind, {lat: item.lat, lng: item.lng}, item.name + (item.sub && item.kind !== 'recent' ? ', ' + item.sub : '')); return; }
    say('Finding the selected place…');
    try {
      let loc, label;
      if (item.prediction) {
        const selected = item.prediction.toPlace();
        await selected.fetchFields({fields: ['displayName', 'formattedAddress', 'location']});
        loc = selected.location; label = selected.displayName ? selected.displayName + (selected.formattedAddress ? ', ' + selected.formattedAddress : '') : selected.formattedAddress;
      } else if (item.placeId && geocoder) {
        const {results} = await geocoder.geocode({placeId: item.placeId});
        loc = results[0] && results[0].geometry.location; label = item.name + (item.sub ? ', ' + item.sub : '');
      }
      if (!loc) throw new Error('No coordinates');
      tokens[kind] = null;
      place(kind, {lat: loc.lat(), lng: loc.lng()}, (label || item.name).replace(/, Philippines$/, ''));
    } catch (e) {
      say('Could not locate that place. Choose another suggestion, or tap the map.');
    }
  }

  async function localPlaces(query) {
    try {
      const r = await fetch('uride-places.php?q=' + encodeURIComponent(query), {credentials: 'same-origin', cache: 'no-store'});
      if (!r.ok) return [];
      return (await r.json()).places || [];
    } catch (e) { return []; }
  }

  async function googlePlaces(kind, query) {
    if (googleSearch === 'off' || googleSearch === 'failed') return [];
    // Prefer places near the map centre (Boracay); results elsewhere in the Philippines still show.
    // A point bias is the form every Places version accepts (circle and bounds literals are refused by some).
    const c = mapCenter();
    const bias = {lat: c.lat, lng: c.lng};
    if (googleSearch === 'new') {
      try {
        if (!tokens[kind]) tokens[kind] = new AutocompleteSessionToken();
        const {suggestions} = await AutocompleteSuggestion.fetchAutocompleteSuggestions({
          input: query, includedRegionCodes: ['ph'], region: 'ph', locationBias: bias, origin: c, sessionToken: tokens[kind], language: 'en'
        });
        return suggestions.filter(s => s.placePrediction).slice(0, 5).map(s => {
          const p = s.placePrediction;
          const main = p.mainText ? p.mainText.toString() : p.text.toString();
          const sub = p.secondaryText ? p.secondaryText.toString().replace(/, Philippines$/, '') : '';
          const km = typeof p.distanceMeters === 'number' ? ` · ${(p.distanceMeters / 1000).toFixed(1)} km` : '';
          return {name: main, sub: sub + km, kind: 'google', prediction: p};
        });
      } catch (e) {
        console.warn('Places API (New) failed, trying the classic Places service:', e && (e.message || e.code));
        googleSearch = LegacyAutocomplete ? 'legacy' : 'failed';
        if (googleSearch === 'failed') return [];
      }
    }
    try {
      const service = new LegacyAutocomplete();
      const {predictions} = await service.getPlacePredictions({input: query, componentRestrictions: {country: 'ph'}, locationBias: bias, origin: c});
      return (predictions || []).slice(0, 5).map(p => ({name: p.structured_formatting.main_text, sub: (p.structured_formatting.secondary_text || '').replace(/, Philippines$/, ''), kind: 'google', placeId: p.place_id}));
    } catch (e) {
      console.warn('Classic Places service failed:', e && (e.message || e.code));
      googleSearch = 'failed';
      return [];
    }
  }

  function attachSearch(kind) {
    const input = inputs[kind];
    const run = async (query) => {
      const requestId = ++requestIds[kind];
      const local = await localPlaces(query);
      if (requestId !== requestIds[kind] || document.activeElement !== input) return;
      renderList(kind, local.slice(0, query ? 6 : 8));
      if (query.length < 2) return;
      const google = await googlePlaces(kind, query);
      if (requestId !== requestIds[kind] || document.activeElement !== input) return;
      const seen = new Set(local.map(p => p.name.toLowerCase()));
      const merged = [...local.slice(0, 5), ...google.filter(g => !seen.has(g.name.toLowerCase()))].slice(0, 9);
      renderList(kind, merged);
      if (!merged.length) say('No matching places. Try a nearby landmark, or tap the map to drop a pin.');
      else if (googleSearch === 'failed' && !google.length) say('Showing Ultimate App places. Google place search is off for this key (see Admin › Settings › Maps).');
    };
    input.addEventListener('input', () => {
      clearPoint(kind);
      clearTimeout(timers[kind]);
      const query = input.value.trim();
      timers[kind] = setTimeout(() => run(query), query.length ? 220 : 0);
    });
    input.addEventListener('focus', () => { if (!points[kind]) run(input.value.trim()); });
    input.addEventListener('keydown', event => {
      if (event.key === 'Escape') closeList(kind);
      if (event.key === 'ArrowDown' && !lists[kind].hidden) { event.preventDefault(); lists[kind].querySelector('button')?.focus(); }
      if (event.key === 'Enter' && !lists[kind].hidden) { event.preventDefault(); lists[kind].querySelector('button')?.click(); }
    });
    input.addEventListener('blur', () => setTimeout(() => { if (!lists[kind].contains(document.activeElement)) closeList(kind); }, 180));
  }
  attachSearch('pickup');
  attachSearch('dropoff');

  /* ---------- Precise "use my location": watch GPS for up to 20 s and keep the most accurate fix. */
  const locateButton = document.getElementById('uride-locate');
  let watchId = null;
  function stopWatch() { if (watchId !== null) { navigator.geolocation.clearWatch(watchId); watchId = null; } locateButton.classList.remove('busy'); }
  function showAccuracy(point, meters) {
    if (!map) return;
    safe(() => {
      if (accuracyCircle) accuracyCircle.setMap(null);
      accuracyCircle = new google.maps.Circle({map, center: point, radius: meters, strokeColor: '#1a73e8', strokeOpacity: .6, strokeWeight: 1, fillColor: '#1a73e8', fillOpacity: .12, clickable: false});
    });
  }
  locateButton.addEventListener('click', () => {
    if (!navigator.geolocation) { say('Location is not available on this device. Search or tap the map instead.'); return; }
    if (watchId !== null) { stopWatch(); return; }
    let best = null;
    const started = Date.now();
    locateButton.classList.add('busy');
    say('Finding your precise location… keep the app open for a few seconds.');
    const finish = (final) => {
      if (!best) return;
      const p = {lat: best.coords.latitude, lng: best.coords.longitude};
      const acc = Math.round(best.coords.accuracy);
      place('pickup', p, null);
      showAccuracy(p, acc);
      say(acc <= 25 ? `Pick-up set from GPS, accurate to about ±${acc} m. Drag the green pin if needed.`
        : `Your location is approximate (±${acc} m). Drag the green pin to your exact spot${final ? '' : ', or wait for a better GPS fix'}.`);
    };
    watchId = navigator.geolocation.watchPosition(pos => {
      if (!best || pos.coords.accuracy < best.coords.accuracy) { best = pos; say(`GPS accuracy ±${Math.round(pos.coords.accuracy)} m… improving.`); }
      if (best.coords.accuracy <= 15 || Date.now() - started > 20000) { stopWatch(); finish(true); }
    }, err => {
      stopWatch();
      if (best) { finish(true); return; }
      say(err.code === 1 ? 'Location permission is off. Allow location for this site, or search / tap the map.' : 'Could not get a GPS fix. Go near a window or outdoors, or search / tap the map.');
    }, {enableHighAccuracy: true, maximumAge: 0, timeout: 20000});
    setTimeout(() => { if (watchId !== null) { stopWatch(); if (best) finish(true); else say('Could not get a GPS fix. Search or tap the map instead.'); } }, 21000);
  });

  /* ---------- Book a Ride / Rent a Ride */
  const rentPrices = JSON.parse(form.dataset.rental || '{}');
  const dayHours = Number(form.dataset.dayHours) || 8;
  const peso = c => '₱' + (Math.ceil(c / 100)).toLocaleString('en-PH');
  function isRental() { return form.dataset.mode === 'rental'; }
  function rentQuote() {
    const v = selectedVehicle();
    const pkg = form.querySelector('input[name="rental_hours"]:checked')?.value;
    const r = rentPrices[v];
    if (!v || !pkg || !r) return null;
    if (!r.enabled) return {ok: false, why: 'This vehicle cannot be rented right now. Choose another.'};
    let hours, billed, fare, capped = false;
    if (pkg === 'day') { hours = dayHours; billed = hours; fare = r.day; }
    else { hours = Number(pkg); billed = Math.max(hours, r.min); fare = billed * r.hour; if (r.day > 0 && hours <= dayHours && fare > r.day) { fare = r.day; capped = true; } }
    if (!(fare > 0)) return {ok: false, why: 'This duration is not available for this vehicle.'};
    return {ok: true, hours, billed, fare: Math.ceil(fare / 100) * 100, capped, pkg};
  }
  function showRent() {
    const out = document.getElementById('uride-rent-fare'), detail = document.getElementById('uride-rent-detail');
    if (!out) return;
    const r = rentQuote();
    if (!r) { out.textContent = '—'; detail.textContent = 'Choose a vehicle and duration'; return; }
    if (!r.ok) { out.textContent = 'Not available'; detail.textContent = r.why; return; }
    out.textContent = peso(r.fare);
    const v = rentPrices[selectedVehicle()];
    detail.textContent = r.pkg === 'day' ? `Whole day · ${r.hours} hours with driver`
      : `${r.hours} hour${r.hours === 1 ? '' : 's'} with driver` + (r.capped ? ' · capped at the whole-day price' : r.billed > r.hours ? ` · minimum ${r.billed} hours` : ` · ${peso(v.hour)}/hr`);
    const later = form.querySelector('input[name="start_mode"]:checked')?.value === 'later';
    const when = form.querySelector('[data-rent-when]');
    if (when) when.textContent = later ? ' 1 hour before the start time' : ' right away';
  }
  const texts = {
    ride: ['Book a ride', 'Choose your pick-up, destination, and vehicle.', 'Request ride', 'e.g. near the blue gate, 2 bags'],
    rental: ['Rent a ride', 'Hire a vehicle with its driver by the hour or for the whole day.', 'Rent this ride', 'Your plan, e.g. Puka Beach, Mt. Luho, then D\'Mall']
  };
  function setTrip(next) {
    form.dataset.mode = next;
    const t = texts[next];
    const title = document.querySelector('[data-mode-title]'), sub = document.querySelector('[data-mode-sub]');
    if (title) title.textContent = t[0];
    if (sub) sub.textContent = t[1];
    const label = form.querySelector('[data-submit-label]');
    if (label && !form.querySelector('[type="submit"]').disabled) label.textContent = t[2];
    const note = form.querySelector('[data-note-input]'); if (note) note.placeholder = t[3];
    inputs.dropoff.required = next === 'ride';
    closeList('dropoff');
    if (next === 'rental') {
      setMode('pickup');
      if (markers.dropoff) safe(() => { markers.dropoff.map = null; });
      showRent();
    } else {
      if (points.dropoff && map && AdvancedMarkerElement) { const p = points.dropoff, label2 = inputs.dropoff.value; place('dropoff', p, label2); }
      showQuote(quote);
    }
    const url = new URL(location.href);
    if (next === 'rental') url.searchParams.set('mode', 'rent'); else url.searchParams.delete('mode');
    history.replaceState(null, '', url);
  }
  form.querySelectorAll('input[name="trip_type"]').forEach(r => r.addEventListener('change', () => setTrip(r.value)));
  form.querySelectorAll('input[name="rental_hours"], input[name="start_mode"], input[name="vehicle_type"]').forEach(r => r.addEventListener('change', showRent));
  const sched = document.getElementById('uride-scheduled');
  if (sched) sched.addEventListener('focus', () => { const later = form.querySelector('input[name="start_mode"][value="later"]'); if (later) { later.checked = true; showRent(); } });
  if (isRental()) setTrip('rental');

  /* ---------- Map */
  document.querySelectorAll('[data-map-mode]').forEach(b => b.addEventListener('click', () => setMode(b.dataset.mapMode)));
  const satButton = document.querySelector('[data-map-type]');
  if (satButton) satButton.addEventListener('click', () => {
    if (!map) return;
    const sat = map.getMapTypeId() !== 'hybrid';
    map.setMapTypeId(sat ? 'hybrid' : 'roadmap');
    satButton.textContent = sat ? 'Map' : 'Satellite';
    satButton.setAttribute('aria-pressed', String(sat));
  });

  async function initialize() {
    try {
      const {Map} = await google.maps.importLibrary('maps');
      ({AdvancedMarkerElement, PinElement} = await google.maps.importLibrary('marker'));
      try {
        const placesLib = await google.maps.importLibrary('places');
        ({AutocompleteSuggestion, AutocompleteSessionToken} = placesLib);
        LegacyAutocomplete = placesLib.AutocompleteService || null;
        googleSearch = AutocompleteSuggestion ? 'new' : (LegacyAutocomplete ? 'legacy' : 'failed');
      } catch (e) { googleSearch = 'failed'; }
      try { const {Geocoder} = await google.maps.importLibrary('geocoding'); geocoder = new Geocoder(); } catch (e) { geocoder = null; }
      mapElement.replaceChildren();
      map = new Map(mapElement, {
        center: center0, zoom: Number(mapElement.dataset.zoom) || 14, maxZoom: 21,
        mapId: mapElement.dataset.mapId || 'DEMO_MAP_ID',
        mapTypeControl: false, streetViewControl: false, fullscreenControl: true, clickableIcons: false, gestureHandling: 'greedy'
      });
      map.addListener('click', event => { if (event.latLng) place(isRental() ? 'pickup' : mode, {lat: event.latLng.lat(), lng: event.latLng.lng()}, null); });
      // Pins set before the map loaded (from a suggestion) now get their markers.
      for (const kind of ['pickup', 'dropoff']) if (points[kind]) { const p = points[kind]; const label = inputs[kind].value; place(kind, p, label); }
      if (satButton) satButton.hidden = false;
      say('Search a place above, use your location, or tap the map. Drag a pin to fine-tune it.');
    } catch (error) {
      mapNote('Map unavailable — search a place above to set your pins.');
      say('Google Maps could not load. You can still pick places from the suggestions.');
    }
  }

  form.addEventListener('submit', event => {
    if (isRental()) {
      if (!points.pickup) { event.preventDefault(); say('Choose a suggestion or tap the map to set the pick-up.'); mapElement.scrollIntoView({behavior: 'smooth', block: 'center'}); return; }
      const r = rentQuote();
      if (!r || !r.ok) { event.preventDefault(); say(r ? r.why : 'Choose a vehicle and duration.'); return; }
      const later = form.querySelector('input[name="start_mode"]:checked')?.value === 'later';
      if (later && !document.getElementById('uride-scheduled').value) { event.preventDefault(); say('Choose the date and time the rental starts.'); return; }
      form.querySelector('[type="submit"]').disabled = true;
      return;
    }
    if (!points.pickup || !points.dropoff) {
      event.preventDefault();
      say('Choose a suggestion or place both pins on the map.');
      mapElement.scrollIntoView({behavior: 'smooth', block: 'center'});
      return;
    }
    const f = quote && quote.fares[selectedVehicle()];
    if (f && !f.enabled) { event.preventDefault(); say('That vehicle type is not available right now.'); return; }
    form.querySelector('[type="submit"]').disabled = true;
  });

  const key = mapElement.dataset.googleKey;
  if (!key || key === 'YOUR_GOOGLE_MAPS_BROWSER_KEY') {
    mapNote('Map is off — search a place above to set your pins.');
    say('Choose places from the suggestions. (Add a Google Maps key in Admin › Settings › Maps for the map.)');
    return;
  }
  window.urideGoogleReady = initialize;
  const script = document.createElement('script');
  script.src = `https://maps.googleapis.com/maps/api/js?key=${encodeURIComponent(key)}&loading=async&v=weekly&callback=urideGoogleReady&language=en&region=PH`;
  script.async = true;
  script.onerror = () => { mapNote('Map unavailable — search a place above to set your pins.'); say('Google Maps could not load. You can still pick places from the suggestions.'); };
  document.head.append(script);
})();
