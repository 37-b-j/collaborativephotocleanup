// Collaborative PhotoCleanup PWA Service Worker
const CACHE_NAME = "photocleanup-v26";
const APP_PREFIX = "/index.php/apps/collaborativephotocleanup";

// Assets to precache on install
const PRECACHE = [
  APP_PREFIX + "/",
];

// Install: precache app shell
self.addEventListener("install", (event) => {
  console.log("[SW] Install");
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => {
      return cache.addAll(PRECACHE).catch((err) => {
        console.log("[SW] Precache partial:", err);
      });
    })
  );
  self.skipWaiting();
});

// Activate: clean old caches
self.addEventListener("activate", (event) => {
  console.log("[SW] Activate");
  event.waitUntil(
    caches.keys().then((keys) => {
      return Promise.all(
        keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k))
      );
    })
  );
  self.clients.claim();
});

// Fetch: network-first for API, cache-first for static
self.addEventListener("fetch", (event) => {
  const url = new URL(event.request.url);
  const path = url.pathname;

  // API calls: network-first, no cache
  if (path.includes("/api/v1/") || path.includes("/core/preview")) {
    return; // Let browser handle normally - no caching
  }

  // App page: network-first with cache fallback
  if (path.includes("/collaborativephotocleanup")) {
    event.respondWith(
      fetch(event.request)
        .then((response) => {
          const clone = response.clone();
          caches.open(CACHE_NAME).then((cache) => {
            cache.put(event.request, clone);
          });
          return response;
        })
        .catch(() => {
          return caches.match(event.request).then((cached) => {
            return cached || new Response("Offline - bitte Verbindung prüfen", {
              status: 503,
              headers: { "Content-Type": "text/html; charset=utf-8" },
            });
          });
        })
    );
  }
});
