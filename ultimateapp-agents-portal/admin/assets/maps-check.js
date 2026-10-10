(() => {
  // Admin › Settings › Maps: checks which Google APIs this browser key can use for URide place search.
  const box = document.querySelector('[data-maps-check]');
  if (!box) return;
  const out = box.querySelector('[data-maps-check-out]');
  const cfg = document.querySelector('[data-gm-key]');
  const key = cfg ? cfg.dataset.gmKey : '';
  const row = (ok, name, detail) => {
    const li = document.createElement('li');
    li.className = ok ? 'ok' : 'bad';
    const b = document.createElement('b'); b.textContent = (ok ? '✓ ' : '✗ ') + name;
    const s = document.createElement('span'); s.textContent = ' — ' + detail;
    li.append(b, s); out.append(li);
  };
  const load = () => new Promise((resolve, reject) => {
    if (window.google && google.maps && google.maps.importLibrary) { resolve(); return; }
    window.__uaMapsCheck = resolve;
    const s = document.createElement('script');
    s.src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent(key) + '&loading=async&v=weekly&callback=__uaMapsCheck&language=en&region=PH';
    s.async = true; s.onerror = () => reject(new Error('script blocked'));
    document.head.append(s);
  });
  box.querySelector('button').addEventListener('click', async () => {
    out.replaceChildren();
    if (!key) { row(false, 'Browser key', 'No key saved. Paste your key and save first.'); return; }
    try { await load(); row(true, 'Maps JavaScript API', 'map script loaded.'); }
    catch (e) { row(false, 'Maps JavaScript API', 'could not load. Check the key, billing, and HTTP referrer restriction.'); return; }
    const where = {lat: 11.9674, lng: 121.9248};
    try {
      const {AutocompleteSuggestion, AutocompleteSessionToken} = await google.maps.importLibrary('places');
      const {suggestions} = await AutocompleteSuggestion.fetchAutocompleteSuggestions({input: 'D\'Mall Boracay', includedRegionCodes: ['ph'], locationBias: where, origin: where, sessionToken: new AutocompleteSessionToken()});
      row(true, 'Places API (New)', suggestions.length + ' suggestion(s) for "D\'Mall Boracay". URide shows Google places.');
    } catch (e) {
      const msg = String(e.message || e.code || 'refused');
      const fix = /referer|referrer/i.test(msg) ? 'Add this website (e.g. https://yourdomain.com/*) to the key\'s Website restrictions in Google Cloud › Credentials.'
        : /not.?(been )?used|disabled|not.?enabled|not.?activated|SERVICE_DISABLED/i.test(msg) ? 'Enable "Places API (New)" in Google Cloud › APIs & Services › Library.'
        : /api.?key|restricted|PERMISSION_DENIED|forbidden|403/i.test(msg) ? 'Add "Places API (New)" to this key\'s API restrictions in Google Cloud › Credentials.'
        : 'Enable "Places API (New)" and allow it in this key\'s API restrictions (Google Cloud › Credentials).';
      row(false, 'Places API (New)', msg.replace(/\.+$/, '') + '. ' + fix);
    }
    try {
      const {Geocoder} = await google.maps.importLibrary('geocoding');
      const res = await new Geocoder().geocode({location: where});
      if (!res || !res.results) throw new Error('request refused');
      const results = res.results;
      row(true, 'Geocoding API', 'map pins get a street address (e.g. "' + (results[0] ? results[0].formatted_address : '—') + '").');
    } catch (e) {
      row(false, 'Geocoding API', (e.message || e.code || 'refused') + '. Optional: enable "Geocoding API" so tapped and GPS pins show a street address. Without it, pins are named after the nearest URide place.');
    }
    row(true, 'Ultimate App places', 'always on: landmarks in Admin › URide places, merchants with a pin, and each customer\'s recent places.');
  });
})();
