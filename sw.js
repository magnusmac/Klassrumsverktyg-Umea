const CACHE_NAME = 'klassrum-v1';

// Static vendor assets to pre-cache (offline shell)
const PRECACHE = [
  '/assets/vendor/tailwind/tailwind.min.css',
  '/assets/vendor/lucide/lucide.min.js',
  '/assets/vendor/interactjs/interact.min.js',
  '/assets/vendor/fontawesome/all.min.css',
  '/assets/vendor/pdfjs/pdf.js',
  '/assets/vendor/pdfjs/pdf.worker.js',
  '/assets/img/icon-192.png',
  '/assets/img/icon-512.png',
  '/favicon.ico',
];

// Install: pre-cache vendor assets
self.addEventListener('install', e => {
  e.waitUntil(
    caches.open(CACHE_NAME)
      .then(cache => cache.addAll(PRECACHE))
      .then(() => self.skipWaiting())
  );
});

// Activate: delete old caches
self.addEventListener('activate', e => {
  e.waitUntil(
    caches.keys().then(keys =>
      Promise.all(keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k)))
    ).then(() => self.clients.claim())
  );
});

// Fetch strategy:
// - vendor assets → cache-first (they never change without a version bump)
// - everything else → network-first with cache fallback
self.addEventListener('fetch', e => {
  const url = new URL(e.request.url);

  // Only handle same-origin GET requests
  if (e.request.method !== 'GET' || url.origin !== self.location.origin) return;

  const isVendor = url.pathname.startsWith('/assets/vendor/') ||
                   url.pathname.startsWith('/assets/img/') ||
                   url.pathname === '/favicon.ico';

  if (isVendor) {
    // Cache-first
    e.respondWith(
      caches.match(e.request).then(cached => cached || fetch(e.request).then(resp => {
        if (resp.ok) {
          const clone = resp.clone();
          caches.open(CACHE_NAME).then(c => c.put(e.request, clone));
        }
        return resp;
      }))
    );
  } else {
    // Network-first: try network, fall back to cache for offline
    e.respondWith(
      fetch(e.request)
        .then(resp => {
          if (resp.ok) {
            const clone = resp.clone();
            caches.open(CACHE_NAME).then(c => c.put(e.request, clone));
          }
          return resp;
        })
        .catch(() => caches.match(e.request))
    );
  }
});
