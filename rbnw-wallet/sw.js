/*
 * RBNW Wallet service worker.
 * - Static assets (CSS/JS/images/fonts/icons): cache, refreshed in the background.
 * - Pages: always from the network (they contain balances and personal
 *   data, so they are never cached); offline.html when there's no network.
 * - API calls are never touched.
 * Bump VERSION when offline.html or the precache list changes.
 */
const VERSION = 'rbnw-v1';
const PRECACHE = [
  'offline.html',
  'assets/img/rbnw-logo.png',
  'assets/img/rbnw-r.png',
  'assets/img/icons/icon-192.png',
];

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(VERSION).then(c => c.addAll(PRECACHE)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys()
      .then(keys => Promise.all(keys.filter(k => k !== VERSION).map(k => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

const isStatic = (url) =>
  /\.(css|js|png|jpg|jpeg|webp|svg|gif|ico|woff2?|ttf|json|bin)$/i.test(url.pathname) ||
  /(^|\.)(fonts\.googleapis\.com|fonts\.gstatic\.com|cdnjs\.cloudflare\.com)$/.test(url.hostname);

self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.pathname.includes('/api/')) return;

  if (req.mode === 'navigate') {
    e.respondWith(fetch(req).catch(() => caches.match('offline.html')));
    return;
  }

  if (isStatic(url)) {
    // stale-while-revalidate
    e.respondWith(caches.open(VERSION).then(async (cache) => {
      const hit = await cache.match(req);
      const fresh = fetch(req).then(res => {
        if (res && (res.ok || res.type === 'opaque')) cache.put(req, res.clone());
        return res;
      }).catch(() => hit);
      return hit || fresh;
    }));
  }
});
