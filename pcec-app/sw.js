/* PCEC service worker — makes the app installable, caches static files and shows an
   offline page when there is no connection. Pages with personal data are never cached. */
const VERSION = 'pcec-v1';
const STATIC_CACHE = VERSION + '-static';
const scoped = (p) => new URL(p, self.registration.scope).href;
const PRECACHE = [
  'offline.html', 'manifest.json',
  'assets/css/style.css', 'assets/js/app.js',
  'assets/img/hero.svg', 'assets/img/favicon.svg',
  'assets/icons/icon-192.png', 'assets/icons/icon-512.png', 'assets/icons/apple-touch-icon.png',
];

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(STATIC_CACHE).then((c) => c.addAll(PRECACHE.map(scoped))).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => !k.startsWith(VERSION)).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  const scope = new URL(self.registration.scope);

  // Pages: always go to the network; show the offline page if it fails.
  if (req.mode === 'navigate') {
    event.respondWith(fetch(req).catch(() => caches.match(scoped('offline.html'))));
    return;
  }

  // Static files from this app and Google Fonts: serve from cache, refresh in the background.
  const isAppStatic = url.origin === scope.origin && url.pathname.startsWith(scope.pathname)
    && /\/(assets|uploads)\//.test(url.pathname) || url.pathname.endsWith('/manifest.json');
  const isFont = url.hostname === 'fonts.googleapis.com' || url.hostname === 'fonts.gstatic.com';
  if (!isAppStatic && !isFont) return; // api/, webhook/, admin data… go straight to the network

  event.respondWith(caches.open(STATIC_CACHE).then(async (cache) => {
    const cached = await cache.match(req);
    const network = fetch(req).then((res) => {
      if (res.ok || res.type === 'opaque') cache.put(req, res.clone());
      return res;
    }).catch(() => cached);
    return cached || network;
  }));
});
