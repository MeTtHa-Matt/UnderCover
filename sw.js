// Service worker "Undercover" — met en cache tout le nécessaire au premier
// chargement, puis sert l'app depuis le cache : plus aucune connexion
// n'est requise ensuite, même en avion ou en zone blanche.

const CACHE_NAME = "undercover-cache-v2";
const ASSETS = [
  "./index.html",
  "./script.js",
  "./style.css",
  "./words_list.js",
  "./manifest.json",
  "./icon-192.png",
  "./icon-512.png",
];

self.addEventListener("install", (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) =>
      // On met en cache chaque fichier séparément : si l'un d'eux
      // échoue (404, hébergeur particulier, etc.), les autres sont
      // quand même mis en cache au lieu de tout faire échouer d'un
      // coup (ce que fait cache.addAll par défaut).
      Promise.all(
        ASSETS.map((url) =>
          cache.add(url).catch((err) => {
            console.warn("[sw] Échec de mise en cache pour", url, err);
          }),
        ),
      ),
    ),
  );
  self.skipWaiting();
});

self.addEventListener("activate", (event) => {
  event.waitUntil(
    caches
      .keys()
      .then((keys) =>
        Promise.all(
          keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k)),
        ),
      ),
  );
  self.clients.claim();
});

// Stratégie "cache d'abord, réseau en secours" : l'app se charge instantanément
// depuis le cache, et se met à jour discrètement en arrière-plan si le réseau
// est disponible.
self.addEventListener("fetch", (event) => {
  if (event.request.method !== "GET") return;

  event.respondWith(
    caches.match(event.request).then((cached) => {
      const networkFetch = fetch(event.request)
        .then((response) => {
          if (response && response.status === 200) {
            const clone = response.clone();
            caches
              .open(CACHE_NAME)
              .then((cache) => cache.put(event.request, clone));
          }
          return response;
        })
        .catch(() => cached);

      return cached || networkFetch;
    }),
  );
});
