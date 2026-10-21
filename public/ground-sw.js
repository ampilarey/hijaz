/*
 * Keeps the ground pages reachable with no signal: network first, and when
 * that fails, the last copy this phone saw. Only the ground pages and the
 * built assets are kept; nothing else of the portal is cached. Unversioned
 * on purpose: a new deploy's pages replace the cached ones the next time
 * they load with a signal.
 */
const CACHE = 'hijaz-ground-v1';

self.addEventListener('install', (event) => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    const url = new URL(request.url);

    if (request.method !== 'GET' || url.origin !== self.location.origin) {
        return;
    }

    const cacheable = url.pathname.startsWith('/ground') || url.pathname.startsWith('/build/') || url.pathname.startsWith('/images/brand/');

    if (!cacheable || url.pathname.endsWith('/snapshot')) {
        return;
    }

    event.respondWith(
        fetch(request)
            .then((response) => {
                if (response.ok) {
                    const copy = response.clone();
                    caches.open(CACHE).then((cache) => cache.put(request, copy));
                }

                return response;
            })
            .catch(() => caches.match(request).then((cached) => cached || new Response('<p style="font-family:sans-serif;padding:2rem">No signal, and this phone has no copy of this page yet. Open it once with a signal.</p>', { headers: { 'Content-Type': 'text/html' } }))),
    );
});
