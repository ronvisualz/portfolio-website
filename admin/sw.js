/* topper. admin — service worker (§26.3). Não guarda páginas em cache (a plataforma precisa de estar
 * ligada); serve para instalar a aplicação e receber o menu Partilhar do Android: o que chega
 * (ficheiros, link, texto) fica guardado aqui até a página partilhar.php o enviar com a sessão. */
const BOX = 'topper-share';

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (e) => e.waitUntil(self.clients.claim()));

self.addEventListener('fetch', (e) => {
  const u = new URL(e.request.url);
  if (e.request.method === 'POST' && u.origin === self.location.origin && u.pathname === '/admin/partilhar.php' && !u.searchParams.has('send')) {
    e.respondWith((async () => {
      try {
        const fd = await e.request.formData();
        const cache = await caches.open(BOX);
        for (const k of await cache.keys()) await cache.delete(k);   // uma partilha de cada vez
        const files = fd.getAll('files').filter((f) => f && typeof f === 'object' && f.size);
        const meta = { title: fd.get('title') || '', text: fd.get('text') || '', url: fd.get('url') || '', files: [], at: Date.now() };
        for (let i = 0; i < files.length && i < 10; i++) {
          const f = files[i];
          meta.files.push({ name: f.name || 'ficheiro', type: f.type || '', size: f.size });
          await cache.put('/__share/file/' + i, new Response(f, { headers: { 'Content-Type': f.type || 'application/octet-stream' } }));
        }
        await cache.put('/__share/meta', new Response(JSON.stringify(meta), { headers: { 'Content-Type': 'application/json' } }));
      } catch (err) { /* a página mostra que não chegou nada */ }
      return Response.redirect('/admin/partilhar.php?s=1', 303);
    })());
  }
});
