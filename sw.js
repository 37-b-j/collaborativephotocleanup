// Collaborative PhotoCleanup PWA Service Worker
// Scope: /apps/collaborativephotocleanup/
var CACHE_NAME = "photocleanup-v30";

self.addEventListener("install", function(event) {
  console.log("[PhotoCleanup SW] Install");
  self.skipWaiting();
});

self.addEventListener("activate", function(event) {
  console.log("[PhotoCleanup SW] Activate");
  event.waitUntil(
    caches.keys().then(function(keys) {
      return Promise.all(
        keys.filter(function(k) { return k !== CACHE_NAME; }).map(function(k) { return caches.delete(k); })
      );
    })
  );
  self.clients.claim();
});

self.addEventListener("fetch", function(event) {
  var url = new URL(event.request.url);
  var path = url.pathname;

  // Pass through API calls, previews
  if (path.indexOf("/api/v1/") !== -1 || path.indexOf("/core/preview") !== -1 || path.indexOf("/ocs/") !== -1 || path.indexOf("/remote.php") !== -1 || event.request.method !== "GET") {
    return;
  }

  // App shell: stale-while-revalidate
  event.respondWith(
    caches.match(event.request).then(function(cached) {
      var fetchPromise = fetch(event.request).then(function(response) {
        if (response && response.status === 200) {
          var clone = response.clone();
          caches.open(CACHE_NAME).then(function(cache) {
            cache.put(event.request, clone);
          });
        }
        return response;
      }).catch(function() {
        return cached || new Response("Offline", { status: 503, statusText: "Service Unavailable" });
      });
      return cached || fetchPromise;
    })
  );
});
