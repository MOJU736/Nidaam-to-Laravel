/* Nidaam app service worker (Sept 29 2026) - makes the app installable
   ("Add to Home screen") and loads its styles/scripts/icons faster.
   Pages and financial data are NEVER cached: every page always comes
   fresh from the server. With no connection, a small "You are offline"
   page is shown instead of the browser's error. */
var CACHE = 'nidaam-static-v1';
var OFFLINE = 'offline.html';

self.addEventListener('install', function (e) {
  e.waitUntil(caches.open(CACHE).then(function (c) {
    return c.addAll([OFFLINE, 'assets/dist/img/nidaam-app-192.png', 'assets/dist/img/nidaam-mark.svg']);
  }).then(function () { return self.skipWaiting(); }));
});

self.addEventListener('activate', function (e) {
  e.waitUntil(caches.keys().then(function (keys) {
    return Promise.all(keys.filter(function (k) { return k.indexOf('nidaam-static-') === 0 && k !== CACHE; }).map(function (k) { return caches.delete(k); }));
  }).then(function () { return self.clients.claim(); }));
});

self.addEventListener('fetch', function (e) {
  var req = e.request;
  if (req.method !== 'GET') { return; }
  var url = new URL(req.url);
  if (url.origin !== self.location.origin) { return; }

  /* Pages: always the network; offline page only when there is none. */
  if (req.mode === 'navigate') {
    e.respondWith(fetch(req).catch(function () { return caches.match(OFFLINE); }));
    return;
  }

  /* Static files under /assets/: show the saved copy at once, refresh it
     in the background (stale-while-revalidate). */
  if (url.pathname.indexOf('/assets/') !== -1 && !/\/assets\/uploads\//.test(url.pathname)) {
    e.respondWith(caches.open(CACHE).then(function (c) {
      return c.match(req).then(function (hit) {
        var net = fetch(req).then(function (res) {
          if (res && res.status === 200) { c.put(req, res.clone()); }
          return res;
        }).catch(function () { return hit; });
        return hit || net;
      });
    }));
  }
});
