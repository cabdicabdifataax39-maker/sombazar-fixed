const CACHE_NAME = 'sombazar-v3';
const STATIC_ASSETS = [
  '/',
  '/index.html',
  '/assets/style.css',
  '/assets/app.js',
  '/assets/icon-192.png',
  '/manifest.json'
];

// Install - statik dosyaları cache'le
self.addEventListener('install', e => {
  e.waitUntil(
    caches.open(CACHE_NAME).then(cache => cache.addAll(STATIC_ASSETS).catch(() => {}))
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

// Fetch - network first, cache fallback
self.addEventListener('fetch', e => {
  // API isteklerini cache'leme
  if (e.request.url.includes('/api/')) return;
  if (e.request.method !== 'GET') return;
  // Store sayfalarini cache'leme - her zaman network'ten al
  if (e.request.url.includes('store')) return;

  e.respondWith(
    fetch(e.request)
      .then(resp => {
        if (resp && resp.status === 200) {
          const clone = resp.clone();
          caches.open(CACHE_NAME).then(cache => cache.put(e.request, clone));
        }
        return resp;
      })
      .catch(() => caches.match(e.request))
  );
});

// Push bildirimi al
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

// Bildirime tıklanınca
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
