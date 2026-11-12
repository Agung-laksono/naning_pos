// Rozak Mart POS - Progressive Web App Service Worker
const CACHE_NAME = 'rozak-mart-pos-v82';
const AUTH_PATHS = ['/login', '/register', '/logout', '/profile', '/forgot-password', '/reset-password'];
const STATIC_ASSETS = [
  '/manifest.json',
  '/icons/icon-192x192.png',
  '/icons/icon-512x512.png',
  '/icons/icon-maskable-512x512.png'
];

// Install: Cache initial static app shell dengan toleransi kegagalan per-item
self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then(async (cache) => {
      for (const asset of STATIC_ASSETS) {
        try {
          await cache.add(asset);
        } catch (err) {
          console.warn('PWA: Dilewati caching:', asset, err);
        }
      }
    }).then(() => self.skipWaiting())
  );
});

// Activate: Bersihkan cache versi lama
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => {
      return Promise.all(
        keys.map((key) => {
          if (key !== CACHE_NAME) {
            console.log('PWA: Menghapus cache lama:', key);
            return caches.delete(key);
          }
        })
      );
    }).then(() => self.clients.claim())
  );
});

// Fetch: Strategi Caching Cerdas
self.addEventListener('fetch', (event) => {
  const request = event.request;
  const url = new URL(request.url);

  // Jangan tangani request selain GET (misal POST checkout, POST produk)
  if (request.method !== 'GET') {
    return;
  }

  // Jangan cache API atau request data dinamis
  if (url.pathname.startsWith('/api/') || url.pathname.includes('/checkout')) {
    return;
  }

  // 1. Static Assets & Banner Media (Vite build, icons, uploads/banners, fonts) -> Cache-First
  if (url.pathname.startsWith('/build/') || url.pathname.startsWith('/icons/') || url.pathname.startsWith('/uploads/') || url.hostname.includes('fonts.')) {
    // Range requests (misal video streaming/seeking) diteruskan ke network tanpa trigger cache API error
    if (request.headers.has('range')) {
      return;
    }
    event.respondWith(
      caches.match(request).then((cachedResponse) => {
        if (cachedResponse) {
          return cachedResponse;
        }
        return fetch(request).then((networkResponse) => {
          if (networkResponse && networkResponse.status === 200) {
            const responseToCache = networkResponse.clone();
            caches.open(CACHE_NAME).then((cache) => {
              cache.put(request, responseToCache);
            });
          }
          return networkResponse;
        });
      })
    );
    return;
  }

  // 2. Navigasi Halaman Utama (/pos, /) -> Network-First dengan Fallback ke Cache (lewati rute auth & redirect)
  if (request.mode === 'navigate' || request.headers.get('accept')?.includes('text/html')) {
    const reqUrl = new URL(request.url);
    const isAuthRoute = AUTH_PATHS.some((p) => reqUrl.pathname.startsWith(p));
    event.respondWith(
      fetch(request)
        .then((networkResponse) => {
          if (
            !isAuthRoute &&
            networkResponse &&
            networkResponse.status === 200 &&
            !networkResponse.redirected
          ) {
            const responseToCache = networkResponse.clone();
            caches.open(CACHE_NAME).then((cache) => {
              cache.put(request, responseToCache);
            });
          }
          return networkResponse;
        })
        .catch(() => {
          if (isAuthRoute) {
            return new Response('Offline: silakan periksa koneksi server untuk login.', {
              status: 503,
              headers: { 'Content-Type': 'text/plain; charset=utf-8' }
            });
          }
          return caches.match(request).then((cachedResponse) => {
            if (cachedResponse) {
              return cachedResponse;
            }
            return caches.match('/pos');
          });
        })
    );
    return;
  }

  // 3. Request Lainnya -> Stale-While-Revalidate
  event.respondWith(
    caches.match(request).then((cachedResponse) => {
      const fetchPromise = fetch(request).then((networkResponse) => {
        if (networkResponse && networkResponse.status === 200) {
          const responseToCache = networkResponse.clone();
          caches.open(CACHE_NAME).then((cache) => {
            cache.put(request, responseToCache);
          });
        }
        return networkResponse;
      }).catch(() => cachedResponse);

      return cachedResponse || fetchPromise;
    })
  );
});

