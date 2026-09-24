const CACHE_PREFIX = 'eduportal-shell-';
const CACHE_NAME = `${CACHE_PREFIX}v4`;
const scopeUrl = new URL(self.registration.scope);
const staticPaths = [
  'offline.html',
  'manifest.webmanifest',
  'assets/pwa-icon-192.svg',
  'assets/style.min.css?v=20260924',
  'assets/js/pwa.js',
  'assets/js/trusted_types.js',
  'assets/js/system_loader.js?v=20260924-loader3',
  'assets/js/responsive_ui.js',
  'assets/pwa-icon-192.svg',
  'assets/pwa-icon-512.svg',
  'assets/pwa-icon-maskable.svg',
  'EDUPORTAL_TEACHER_STUDENT_GUIDE.html'
];
const staticUrls = staticPaths.map(path => new URL(path, scopeUrl).href);
const offlineUrl = new URL('offline.html', scopeUrl).href;
const assetsPath = new URL('assets/', scopeUrl).pathname;
const cacheablePaths = new Set([
  new URL('manifest.webmanifest', scopeUrl).pathname,
  new URL('assets/pwa-icon-192.svg', scopeUrl).pathname,
  new URL('EDUPORTAL_TEACHER_STUDENT_GUIDE.html', scopeUrl).pathname
]);

const isCacheableAsset = requestUrl => {
  return requestUrl.pathname.startsWith(assetsPath) || cacheablePaths.has(requestUrl.pathname);
};

const cacheFirst = async request => {
  const cache = await caches.open(CACHE_NAME);
  const cachedResponse = await cache.match(request);

  if (cachedResponse) {
    fetch(request)
      .then(response => {
        if (response.ok) {
          return cache.put(request, response.clone());
        }
        return null;
      })
      .catch(() => null);
    return cachedResponse;
  }

  try {
    const response = await fetch(request);
    if (response.ok) {
      await cache.put(request, response.clone());
    }
    return response;
  } catch (error) {
    return new Response('This resource is unavailable while offline.', {
      status: 503,
      statusText: 'Offline'
    });
  }
};

const networkFirstNavigation = async request => {
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), 5000);
  try {
    return await fetch(request, { signal: controller.signal });
  } catch (error) {
    const offlineResponse = await caches.match(offlineUrl);
    return offlineResponse || new Response('EduPortal is offline.', {
      status: 503,
      statusText: 'Offline'
    });
  } finally {
    clearTimeout(timeout);
  }
};

self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then(async cache => {
        await cache.addAll(staticUrls);
        const loaderUrl = new URL('assets/js/system_loader.js?v=20260924-loader3', scopeUrl).href;
        const loaderResponse = await cache.match(loaderUrl);
        if (loaderResponse) {
          await cache.put(new URL('assets/js/system_loader.js', scopeUrl).href, loaderResponse);
        }
      })
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys()
      .then(cacheNames => Promise.all(
        cacheNames
          .filter(cacheName => cacheName.startsWith(CACHE_PREFIX) && cacheName !== CACHE_NAME)
          .map(cacheName => caches.delete(cacheName))
      ))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', event => {
  const request = event.request;
  const requestUrl = new URL(request.url);

  if (request.method !== 'GET' || requestUrl.origin !== self.location.origin || request.headers.has('range')) {
    return;
  }

  if (request.mode === 'navigate') {
    event.respondWith(networkFirstNavigation(request));
    return;
  }

  if (isCacheableAsset(requestUrl)) {
    event.respondWith(cacheFirst(request));
  }
});

self.addEventListener('message', event => {
  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
});
