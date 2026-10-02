/*
 * Service worker of the agitator's application (ТЗ §34, ADR-013). Its scope is /field only — the panel is not
 * touched. It keeps the shell of the application (the page, its script and style) so the application opens
 * without a network. Data is not cached here: the houses live in IndexedDB, the API always goes to the network.
 */
const CACHE = 'bz-field-v1';
const SHELL = ['/field', '/field/app.js', '/field/app.css', '/field/manifest.json', '/field/icon.svg'];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE)
            // One by one: a file that failed must not stop the rest from being kept.
            .then((cache) => Promise.all(SHELL.map((url) => fetch(url, { credentials: 'same-origin' })
                .then((response) => (response.ok && !response.redirected ? cache.put(url, response) : null))
                .catch(() => null))))
            .then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((key) => key.startsWith('bz-field-') && key !== CACHE).map((key) => caches.delete(key))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    const url = new URL(request.url);
    if (request.method !== 'GET' || url.origin !== self.location.origin) {
        return;
    }
    const path = url.pathname;
    if (path !== '/field' && !path.startsWith('/field/')) {
        return;
    }

    // The page and its files: the network first (a fresh version, a fresh token), the kept copy when there is none.
    event.respondWith(
        fetch(request)
            .then((response) => {
                // A redirect to the sign-in page is not the application: never keep it.
                if (response.ok && !response.redirected) {
                    const copy = response.clone();
                    caches.open(CACHE).then((cache) => cache.put(path, copy));
                }
                return response;
            })
            .catch(() => caches.open(CACHE).then((cache) => cache.match(path)).then((kept) => kept ?? Response.error())),
    );
});
