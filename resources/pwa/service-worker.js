/* global CONFIG */
/* AlfaKit service worker (Panel::pwa()). `CONFIG` is prepended by the server:
 * { panel, version, scope, offline, precache[], internal, build[], name, icon } */
const PREFIX = 'alfakit-' + CONFIG.panel + '-'
const SHELL = PREFIX + 'shell-' + CONFIG.version
const STATIC = PREFIX + 'static-' + CONFIG.version
// Above what one build loads lazily: the limit only stops fonts and scripts under /vendor/
// from piling up. Old builds go with their cache when a new worker takes over.
const STATIC_LIMIT = 1000
const TRIM_DELAY = 30000

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches
      .open(SHELL)
      .then((cache) =>
        Promise.all(
          [CONFIG.offline, ...CONFIG.precache].map((url) =>
            cache
              .add(new Request(url, { credentials: 'same-origin', cache: 'reload' }))
              .catch(() => undefined),
          ),
        ),
      ),
  )
  // The first install takes over at once; updates wait for "Reload" (SKIP_WAITING).
  if (!self.registration.active) self.skipWaiting()
})

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches
      .keys()
      .then((keys) =>
        Promise.all(
          keys
            .filter((key) => key.startsWith(PREFIX) && key !== SHELL && key !== STATIC)
            .map((key) => caches.delete(key)),
        ),
      )
      .then(() => self.clients.claim()),
  )
})

self.addEventListener('message', (event) => {
  if (event.data && event.data.type === 'SKIP_WAITING') self.skipWaiting()
})

function inScope(url) {
  return (
    CONFIG.scope === '/' ||
    url.pathname === CONFIG.scope ||
    url.pathname.startsWith(CONFIG.scope + '/')
  )
}

function isStatic(request, url) {
  if (CONFIG.build.some((prefix) => url.pathname.startsWith(prefix))) return true
  return (
    ['font', 'style', 'script'].includes(request.destination) && url.pathname.startsWith('/vendor/')
  )
}

let trimming = null

// Never while a page loads: a page asks for a hundred chunks at once, and deleting cached
// ones in the middle of that left the page waiting for scripts that never arrived. One
// pass at a time, after things have been quiet for a while.
function scheduleTrim() {
  if (trimming) return
  trimming = new Promise((resolve) => setTimeout(resolve, TRIM_DELAY))
    .then(() => caches.open(STATIC))
    .then(async (cache) => {
      const keys = await cache.keys()
      for (let i = 0; i < keys.length - STATIC_LIMIT; i++) await cache.delete(keys[i])
    })
    .catch(() => undefined)
    .finally(() => {
      trimming = null
    })
}

async function cacheFirst(request) {
  const cached = await caches.match(request)
  if (cached) return cached
  const response = await fetch(request)
  if (response.ok && response.type === 'basic') {
    const copy = response.clone()
    caches
      .open(STATIC)
      .then((cache) => cache.put(request, copy))
      .then(scheduleTrim)
      .catch(() => undefined)
  }
  return response
}

async function offlinePage() {
  return (
    (await caches.match(CONFIG.offline, { ignoreSearch: true })) ||
    new Response('Offline', { status: 503, headers: { 'Content-Type': 'text/plain' } })
  )
}

self.addEventListener('fetch', (event) => {
  const request = event.request
  if (request.method !== 'GET') return

  const url = new URL(request.url)
  if (url.origin !== self.location.origin) return
  // Internal JSON endpoints and authenticated data are never cached.
  if (url.pathname.includes('/' + CONFIG.internal + '/')) return

  if (isStatic(request, url)) {
    event.respondWith(cacheFirst(request))
    return
  }

  if (!inScope(url)) return

  // Inertia visits: network only; offline, Inertia does a full visit to the offline page.
  if (request.headers.get('X-Inertia')) {
    event.respondWith(
      fetch(request).catch(
        () =>
          new Response('', {
            status: 409,
            headers: {
              'X-Inertia-Location':
                CONFIG.offline + '?url=' + encodeURIComponent(url.pathname + url.search),
            },
          }),
      ),
    )
    return
  }

  // Pages: network first (never stored, they hold the user's data), the offline page as a fallback.
  if (request.mode === 'navigate') {
    event.respondWith(fetch(request).catch(offlinePage))
  }
})

self.addEventListener('push', (event) => {
  let data
  try {
    data = event.data ? event.data.json() : {}
  } catch {
    data = { body: event.data ? event.data.text() : '' }
  }

  event.waitUntil(
    self.registration.showNotification(data.title || CONFIG.name, {
      body: data.body || '',
      icon: data.icon || CONFIG.icon || undefined,
      badge: CONFIG.icon || undefined,
      tag: data.tag || undefined,
      lang: data.lang || undefined,
      dir: data.dir || 'auto',
      data: { url: data.url || CONFIG.scope },
    }),
  )
})

self.addEventListener('notificationclick', (event) => {
  event.notification.close()
  const target = new URL(
    (event.notification.data && event.notification.data.url) || CONFIG.scope,
    self.location.origin,
  )
  if (target.origin !== self.location.origin) return

  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
      for (const client of windows) {
        if (inScope(new URL(client.url)) && 'focus' in client) {
          return client
            .focus()
            .then((focused) =>
              focused && 'navigate' in focused ? focused.navigate(target.href) : focused,
            )
        }
      }
      return self.clients.openWindow(target.href)
    }),
  )
})
