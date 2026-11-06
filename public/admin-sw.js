/*
 * Service worker of the installable panel (PWA). Its scope is /admin only — /field has its own (field-sw.js).
 * It caches nothing of the panel's data: every request goes to the network. The only kept thing is a small
 * "no connection" page shown instead of the browser's error when a page cannot be opened without a network.
 * Chrome needs a service worker with a fetch handler to offer "Install".
 */
const CACHE = 'bz-admin-v1';
const OFFLINE = '/admin-app/offline.html';

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE)
            .then((cache) => cache.addAll([OFFLINE, '/brand/icon-192.png']))
            .catch(() => null)
            .then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((key) => key.startsWith('bz-admin-') && key !== CACHE).map((key) => caches.delete(key))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.mode !== 'navigate') {
        return;
    }
    event.respondWith(
        fetch(request).catch(() => caches.match(OFFLINE).then((kept) => kept ?? Response.error())),
    );
});
