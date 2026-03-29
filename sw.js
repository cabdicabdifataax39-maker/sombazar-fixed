const CACHE_NAME = 'sombazar-v4';
const STATIC_ASSETS = [
  '/',
  '/index.html',
  '/assets/style.min.css',
  '/assets/app.min.js',
  '/assets/icon-192.png',
  '/assets/icon-512.png',
  '/manifest.json',
  '/offline.html'
];

// Install
self.addEventListener('install', e => {
  e.waitUntil(
    caches.open(CACHE_NAME)
      .then(cache => cache.addAll(STATIC_ASSETS).catch(() => {}))
  );
  self.skipWaiting();
});

// Activate - eski cache'leri temizle
self.addEventListener('activate', e => {
  e.waitUntil(
    caches.keys().then(keys =>
      Promise.all(keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k)))
    )
  );
  self.clients.claim();
});

// Fetch
self.addEventListener('fetch', e => {
  const url = e.request.url;

  // API isteklerini hic cache'leme
  if (url.includes('/api/')) return;
  if (e.request.method !== 'GET') return;

  // HTML sayfalarini her zaman network'ten al (stale olmamasi icin)
  if (e.request.headers.get('accept')?.includes('text/html') || url.endsWith('.html')) {
    e.respondWith(
      fetch(e.request)
        .then(resp => {
          if (resp && resp.status === 200) {
            const clone = resp.clone();
            caches.open(CACHE_NAME).then(c => c.put(e.request, clone));
          }
          return resp;
        })
        .catch(() => caches.match(e.request)
          .then(cached => cached || caches.match('/offline.html'))
        )
    );
    return;
  }

  // Statik dosyalar: cache first, network fallback
  e.respondWith(
    caches.match(e.request).then(cached => {
      if (cached) return cached;
      return fetch(e.request).then(resp => {
        if (resp && resp.status === 200) {
          const clone = resp.clone();
          caches.open(CACHE_NAME).then(c => c.put(e.request, clone));
        }
        return resp;
      }).catch(() => caches.match('/offline.html'));
    })
  );
});

// Push bildirimi
self.addEventListener('push', e => {
  let data = { title: 'SomBazar', body: 'You have a new notification', icon: '/assets/icon-192.png', badge: '/assets/icon-72.png', url: '/' };
  try { Object.assign(data, e.data.json()); } catch(err) {}
  e.waitUntil(
    self.registration.showNotification(data.title, {
      body:    data.body,
      icon:    data.icon  || '/assets/icon-192.png',
      badge:   data.badge || '/assets/icon-72.png',
      data:    { url: data.url || '/' },
      vibrate: [200, 100, 200]
    })
  );
});

// Bildirime tiklaninca
self.addEventListener('notificationclick', e => {
  e.notification.close();
  const url = e.notification.data?.url || '/';
  e.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then(list => {
      for (const client of list) {
        if (client.url.includes(self.location.origin) && 'focus' in client) {
          client.navigate(url);
          return client.focus();
        }
      }
      return clients.openWindow(url);
    })
  );
});
