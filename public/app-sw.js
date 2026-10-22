// The Hijaz app's service worker, for the staff portal and the customer
// portal. It keeps one thing: a "no connection" page, shown when a page
// cannot be reached. Nothing else is cached, so no booking, balance or
// passport ever sits in the phone's cache. The ground pages have their own,
// offline-capable worker under /ground/, which takes precedence there.
const CACHE = 'hijaz-app-v2';
const OFFLINE = '/offline.html';

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(CACHE).then((cache) => cache.add(OFFLINE)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((k) => k.startsWith('hijaz-app-') && k !== CACHE).map((k) => caches.delete(k))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    if (event.request.mode !== 'navigate') {
        return;
    }

    event.respondWith(fetch(event.request).catch(() => caches.match(OFFLINE)));
});
