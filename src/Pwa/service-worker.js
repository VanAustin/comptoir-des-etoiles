// Service worker du Comptoir des étoiles. Servi par /sw.js, précédé de VERSION et PRECACHE
// (voir App\Pwa\AppShell) : il garde sur la tablette les écrans de service et leurs fichiers,
// pour qu'ils s'ouvrent même sans réseau. L'API et l'administration ne sont jamais mises en cache.

const CACHE = `comptoir-${VERSION}`;
const SCOPE = new URL(self.registration.scope).pathname.replace(/\/$/, "");
const PAGE_TIMEOUT_MS = 4000;
const NEVER_CACHED = ["/api/", "/admin", "/login", "/logout", "/sw.js"];

self.addEventListener("install", (event) => {
  event.waitUntil((async () => {
    const cache = await caches.open(CACHE);
    // reload : jamais une copie du cache HTTP du navigateur, toujours la version du serveur
    await cache.addAll(PRECACHE.map((url) => new Request(SCOPE + url, { cache: "reload", credentials: "omit" })));
    // La nouvelle version prend la main tout de suite ; les écrans proposent de recharger
    await self.skipWaiting();
  })());
});

self.addEventListener("activate", (event) => {
  event.waitUntil((async () => {
    for (const name of await caches.keys()) {
      if (name !== CACHE && name.startsWith("comptoir-")) {
        await caches.delete(name);
      }
    }
    await self.clients.claim();
  })());
});

self.addEventListener("fetch", (event) => {
  const request = event.request;
  const url = new URL(request.url);
  if (request.method !== "GET" || url.origin !== self.location.origin) {
    return;
  }
  const path = url.pathname.slice(SCOPE.length) || "/";
  if (NEVER_CACHED.some((prefix) => path.startsWith(prefix))) {
    return;
  }

  if (request.mode === "navigate") {
    if (PRECACHE.includes(path)) {
      event.respondWith(networkFirst(request, path));
    }
    return;
  }
  if (PRECACHE.includes(path)) {
    event.respondWith(cacheFirst(request, path));
  }
});

/** Pages : la version du serveur si elle arrive à temps, sinon celle gardée sur la tablette. */
async function networkFirst(request, path) {
  const cache = await caches.open(CACHE);
  try {
    const response = await withTimeout(fetch(request), PAGE_TIMEOUT_MS);
    if (response.ok) {
      await cache.put(SCOPE + path, response.clone());
    }
    return response;
  } catch {
    const cached = await cache.match(SCOPE + path);
    return cached ?? Response.error();
  }
}

/** Fichiers statiques : ceux de la version en cours, déjà sur la tablette. */
async function cacheFirst(request, path) {
  const cached = await caches.match(SCOPE + path, { cacheName: CACHE });
  return cached ?? fetch(request);
}

function withTimeout(promise, ms) {
  return new Promise((resolve, reject) => {
    const timer = setTimeout(() => reject(new Error("timeout")), ms);
    promise.then((value) => { clearTimeout(timer); resolve(value); }, (error) => { clearTimeout(timer); reject(error); });
  });
}
