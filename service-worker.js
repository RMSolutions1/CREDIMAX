/* Credimax — SW mínimo para PWA. No cachea HTML para no servir páginas PHP viejas. */
self.addEventListener('install', (event) => {
  event.waitUntil(self.skipWaiting());
});

self.addEventListener('activate', (event) => {
  event.waitUntil(self.clients.claim());
});
