/**
 * Destek Örnek Yazılım - Service Worker
 * Kapsam: /admin/
 *
 * Strateji:
 *   - Sayfa gezinme (navigate)   -> NetworkFirst, çevrimdışında offline.html
 *   - /admin/api/ ve GET dışı    -> NetworkOnly (dinamik veri önbelleğe alınmaz)
 *   - Statik dosya (css/js/img)  -> StaleWhileRevalidate
 *
 * Sürüm değişince CACHE_VERSION artırılır; eski önbellek activate'te silinir.
 */

const CACHE_VERSION = 'v1.0.1';
const CACHE_NAME    = 'destek-pwa-' + CACHE_VERSION;
const CACHE_ONEK    = 'destek-pwa-';
const OFFLINE_URL   = '/admin/offline.html';

// Kurulumda önbelleğe alınacak dosyalar (tek tek eklenir; biri yoksa diğerleri yine de kaydedilir)
const PRECACHE = [
  OFFLINE_URL,
  '/admin/assets/css/adminlte.min.css',
  '/admin/assets/css/custom.css',
  '/admin/assets/js/pwa.js',
  '/admin/icon.php?size=192'
];

/* ─── Install ─────────────────────────────────────────────────────────── */
self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) =>
      // addAll tek bir dosya 404 verse tüm işlemi iptal eder; tek tek ekliyoruz
      Promise.all(PRECACHE.map((u) => cache.add(u).catch(() => {})))
    )
  );
});

/* ─── Activate: eski önbellek temizliği ───────────────────────────────── */
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(
        keys.filter((k) => k.startsWith(CACHE_ONEK) && k !== CACHE_NAME)
            .map((k) => caches.delete(k))
      ))
      .then(() => self.clients.claim())
  );
});

/* ─── Fetch ───────────────────────────────────────────────────────────── */
self.addEventListener('fetch', (event) => {
  const req = event.request;

  // Yalnızca GET önbelleğe alınır; POST/PUT vb. doğrudan ağa gider
  if (req.method !== 'GET') return;

  let url;
  try { url = new URL(req.url); } catch (e) { return; }

  // Farklı origin (CDN vb.) tarayıcıya bırakılır
  if (url.origin !== self.location.origin) return;

  // API istekleri: her zaman ağdan (dinamik veri önbelleğe alınmaz)
  if (url.pathname.startsWith('/admin/api/')) {
    event.respondWith(
      fetch(req).catch(() => new Response(
        JSON.stringify({ success: false, offline: true, message: 'Çevrimdışısınız.' }),
        { headers: { 'Content-Type': 'application/json' }, status: 503 }
      ))
    );
    return;
  }

  // Sayfa gezinmeleri: NetworkFirst -> çevrimdışında offline.html
  if (req.mode === 'navigate') {
    event.respondWith(
      fetch(req).catch(() =>
        caches.match(OFFLINE_URL).then((r) => r || cevrimdisiYanit())
      )
    );
    return;
  }

  // Statik dosyalar: StaleWhileRevalidate
  event.respondWith(
    caches.match(req).then((cached) => {
      const network = fetch(req).then((res) => {
        if (res && res.status === 200 && res.type !== 'opaque') {
          const clone = res.clone();
          caches.open(CACHE_NAME).then((cache) => cache.put(req, clone));
        }
        return res;
      }).catch(() => cached || bosYanit());

      // Önbellekte varsa hemen ver; yoksa ağı bekle (ağ da düşerse boş yanıt döner)
      return cached || network;
    }).catch(() => bosYanit())
  );
});

/* ─── Yedek yanıtlar: respondWith'e asla undefined gitmemeli ──────────── */
function cevrimdisiYanit() {
  return new Response(
    '<!DOCTYPE html><html lang="tr"><head><meta charset="utf-8">' +
    '<meta name="viewport" content="width=device-width,initial-scale=1">' +
    '<title>Çevrimdışısınız</title></head>' +
    '<body style="font-family:system-ui,sans-serif;text-align:center;padding:48px 24px;color:#212529;">' +
    '<h1 style="font-size:1.3rem;">İnternet bağlantısı yok</h1>' +
    '<p style="color:#6c757d;">Bağlantınız geldiğinde tekrar deneyin.</p>' +
    '<button onclick="location.reload()" style="background:#0d6efd;color:#fff;border:0;border-radius:8px;padding:11px 22px;cursor:pointer;">Tekrar Dene</button>' +
    '</body></html>',
    { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } }
  );
}

function bosYanit() {
  return new Response('', { status: 504, statusText: 'Cevrimdisi' });
}

/* ─── Push bildirimi ──────────────────────────────────────────────────── */
self.addEventListener('push', (event) => {
  let veri = { baslik: 'Destek Bildirimi', govde: '', url: '/admin/ticket-liste' };
  try {
    if (event.data) veri = Object.assign(veri, event.data.json());
  } catch (e) {
    if (event.data) veri.govde = event.data.text();
  }

  event.waitUntil(
    self.registration.showNotification(veri.baslik, {
      body:      veri.govde,
      icon:      '/admin/icon.php?size=192',
      badge:     '/admin/icon.php?size=192',
      data:      { url: veri.url || '/admin/ticket-liste' },
      tag:       veri.tag || undefined,
      renotify:  !!veri.tag,
      requireInteraction: !!veri.kalici
    })
  );
});

/* ─── Bildirime tıklanınca ilgili talebi aç ───────────────────────────── */
self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const hedef = (event.notification.data && event.notification.data.url) || '/admin/ticket-liste';

  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((liste) => {
      for (const c of liste) {
        if (c.url.indexOf(hedef) !== -1 && 'focus' in c) return c.focus();
      }
      // Aynı sayfa açık değilse mevcut bir pencereyi yönlendir, o da yoksa yeni pencere aç
      if (liste.length && 'navigate' in liste[0]) {
        return liste[0].navigate(hedef).then((c) => c && c.focus());
      }
      if (self.clients.openWindow) return self.clients.openWindow(hedef);
    })
  );
});

/* ─── pwa.js'ten gelen mesajlar (güncellemeyi hemen uygula) ───────────── */
self.addEventListener('message', (event) => {
  if (event.data === 'SKIP_WAITING') self.skipWaiting();
});
