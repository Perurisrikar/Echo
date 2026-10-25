// Minimal service worker so Echo can be installed. It caches nothing, so
// attendance data is always live; if the network is down it just shows a
// friendly offline message for page loads.
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (e) => e.waitUntil(self.clients.claim()));
// ---- Notifications ----
self.addEventListener('push', (event) => {
  let d = {};
  try { d = event.data ? event.data.json() : {}; } catch (e) { d = { body: event.data ? event.data.text() : '' }; }
  event.waitUntil(self.registration.showNotification(d.title || 'Echo', {
    body: d.body || '',
    icon: 'assets/icon-192.png',
    badge: 'assets/icon-192.png',
    tag: d.tag || 'echo',
    data: { url: d.url || 'dashboard.php' },
  }));
});
self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const target = new URL((event.notification.data && event.notification.data.url) || 'dashboard.php', self.registration.scope).href;
  event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
    for (const c of list) { if ('focus' in c) { c.navigate(target).catch(() => {}); return c.focus(); } }
    return self.clients.openWindow(target);
  }));
});

self.addEventListener('fetch', (event) => {
  if (event.request.mode !== 'navigate') return;
  event.respondWith(
    fetch(event.request).catch(() => new Response(
      '<!DOCTYPE html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">' +
      '<title>Echo — Offline</title><body style="font-family:system-ui;padding:40px 24px;text-align:center;color:#2D332F">' +
      '<h2>You\'re offline</h2><p>Echo needs a connection to show your attendance. Try again once you\'re back online.</p></body>',
      { headers: { 'Content-Type': 'text/html; charset=utf-8' } }
    ))
  );
});
