/* Guardar na topper. — o serviço da extensão: menu do botão direito, atalhos e o envio para a plataforma.
 * Guarda só o endereço da plataforma e o token pessoal (Perfil › Ligações, revogável); nunca a palavra-passe. */
importScripts('config.js');

const MENU = 'topper-save';

async function settings() {
  const s = await chrome.storage.local.get(['server', 'token', 'boards', 'dest']);
  return { server: (s.server || self.TOPPER_SERVER || '').replace(/\/+$/, ''), token: s.token || '', boards: s.boards || [], dest: s.dest ?? -1 };
}

async function api(a, opts = {}) {
  const s = await settings();
  if (!s.server || !s.token) throw Object.assign(new Error('Ligue a extensão à topper.: abra as opções e cole o seu token (Perfil › Ligações).'), { code: 'setup' });
  const r = await fetch(s.server + '/admin/capture_api.php?a=' + a + (opts.query || ''), {
    method: opts.body ? 'POST' : 'GET', body: opts.body || undefined, credentials: 'omit',
    headers: { 'Authorization': 'Bearer ' + s.token, 'X-Topper-Token': s.token, 'Accept': 'application/json' }
  }).catch(() => { throw new Error('Sem ligação à topper. Tente outra vez.'); });
  const j = await r.json().catch(() => ({ ok: false, error: 'A topper. não respondeu (' + r.status + ').' }));
  if (!j.ok) throw Object.assign(new Error(j.error || 'Não foi possível guardar.'), { code: j.code });
  return j;
}

/** Os quadros recentes (para o menu e o popup). */
async function refreshBoards() {
  try {
    const j = await api('me');
    await chrome.storage.local.set({ boards: j.boards || [], me: j.user, defaultBoard: j.default });
    await buildMenu();
    return j;
  } catch (e) { await buildMenu(); throw e; }
}

async function buildMenu() {
  await chrome.contextMenus.removeAll();
  const { boards } = await settings();
  const ctx = ['image', 'video', 'link', 'selection', 'page'];
  chrome.contextMenus.create({ id: MENU, title: 'Guardar na topper.', contexts: ctx });
  chrome.contextMenus.create({ id: MENU + ':0', parentId: MENU, title: 'Caixa de entrada', contexts: ctx });
  if (boards.length) chrome.contextMenus.create({ id: MENU + ':sep', parentId: MENU, type: 'separator', contexts: ctx });
  boards.slice(0, 8).forEach((b) => chrome.contextMenus.create({ id: MENU + ':' + b.id, parentId: MENU, title: (b.code ? b.code + ' · ' : '') + b.title, contexts: ctx }));
  chrome.contextMenus.create({ id: MENU + ':sep2', parentId: MENU, type: 'separator', contexts: ['page'] });
  chrome.contextMenus.create({ id: 'topper-area', parentId: MENU, title: 'Capturar uma área do ecrã…', contexts: ['page'] });
}

chrome.runtime.onInstalled.addListener(async (d) => {
  await buildMenu();
  refreshBoards().catch(() => {});
  if (d.reason === 'install') chrome.runtime.openOptionsPage();
});
chrome.runtime.onStartup.addListener(() => { refreshBoards().catch(() => {}); });

function notify(title, msg, ok = true) {
  chrome.notifications.create({ type: 'basic', iconUrl: 'icons/128.png', title, message: msg, priority: ok ? 0 : 1 });
}

/** Lê da página: título, autor/créditos, site, e o vídeo (tempo e posição). */
function pageInfo() {
  const meta = (sel) => { const el = document.querySelector(sel); return el ? (el.getAttribute('content') || el.textContent || '').trim() : ''; };
  let author = meta('meta[name="author"]') || meta('meta[property="article:author"]') || meta('[itemprop="author"] [itemprop="name"]') || meta('link[itemprop="name"]');
  const yt = document.querySelector('ytd-video-owner-renderer #channel-name a, #owner #channel-name a');
  if (yt) author = yt.textContent.trim();
  const vids = Array.from(document.querySelectorAll('video')).filter((v) => { const r = v.getBoundingClientRect(); return r.width > 80 && r.height > 60 && r.bottom > 0 && r.top < innerHeight; });
  vids.sort((a, b) => (b.getBoundingClientRect().width * b.getBoundingClientRect().height) - (a.getBoundingClientRect().width * a.getBoundingClientRect().height));
  const v = vids[0];
  const r = v ? v.getBoundingClientRect() : null;
  return {
    title: meta('meta[property="og:title"]') || document.title, site: meta('meta[property="og:site_name"]') || location.hostname.replace(/^www\./, ''), author,
    url: location.href, dpr: devicePixelRatio,
    video: v ? { t: v.currentTime, x: r.left, y: r.top, w: r.width, h: r.height, src: v.currentSrc && !v.currentSrc.startsWith('blob:') ? v.currentSrc : '' } : null
  };
}

async function info(tabId) {
  try { const [r] = await chrome.scripting.executeScript({ target: { tabId }, func: pageInfo }); return r.result || {}; } catch (e) { return {}; }
}

/** Corta a captura do separador (dataURL) para o retângulo dado (px CSS) e devolve um Blob. */
async function crop(dataUrl, rect, dpr) {
  const bmp = await createImageBitmap(await (await fetch(dataUrl)).blob());
  const sx = Math.max(0, Math.round(rect.x * dpr)), sy = Math.max(0, Math.round(rect.y * dpr));
  const sw = Math.min(bmp.width - sx, Math.round(rect.w * dpr)), sh = Math.min(bmp.height - sy, Math.round(rect.h * dpr));
  const cv = new OffscreenCanvas(Math.max(1, sw), Math.max(1, sh));
  cv.getContext('2d').drawImage(bmp, sx, sy, sw, sh, 0, 0, sw, sh);
  return cv.convertToBlob({ type: 'image/png' });
}

/** Envia para a plataforma. p: kind, url, src, title, text, note, t, author, site, board; file (Blob). */
async function save(p, file) {
  const fd = new FormData();
  Object.keys(p).forEach((k) => { if (p[k] != null && p[k] !== '') fd.append(k, String(p[k])); });
  fd.append('via', 'extension');
  if (file) fd.append('file', file, p.kind === 'frame' ? 'frame.png' : p.kind === 'area' ? 'captura.png' : 'ficheiro');
  const j = await api('save', { body: fd });
  if (p.board > 0) await chrome.storage.local.set({ dest: +p.board });
  return j;
}

async function saveFrame(tab, board, note) {
  const i = await info(tab.id);
  if (!i.video) throw new Error('Não encontrei um vídeo à vista nesta página.');
  const shot = await chrome.tabs.captureVisibleTab(tab.windowId, { format: 'png' });
  const blob = await crop(shot, i.video, i.dpr || 1);
  return save({ kind: 'frame', url: i.url, title: i.title, author: i.author, site: i.site, t: i.video.t.toFixed(2), board, note }, blob);
}

/** Desenhar o retângulo na página (Esc cancela). Devolve {x,y,w,h,dpr} em px CSS ou null. */
function pickArea() {
  return new Promise((resolve) => {
    const ov = document.createElement('div');
    ov.style.cssText = 'position:fixed;inset:0;z-index:2147483647;cursor:crosshair;background:rgba(0,0,0,.18)';
    const box = document.createElement('div');
    box.style.cssText = 'position:fixed;border:2px solid #FFC718;background:rgba(255,199,24,.08);box-shadow:0 0 0 9999px rgba(0,0,0,.25);display:none';
    const tip = document.createElement('div');
    tip.textContent = 'Arraste para escolher a área · Esc para cancelar';
    tip.style.cssText = 'position:fixed;top:12px;left:50%;transform:translateX(-50%);background:#111;color:#fff;font:600 13px system-ui;padding:6px 12px;border-radius:99px';
    ov.append(box, tip); document.documentElement.appendChild(ov);
    let x0 = 0, y0 = 0, on = false;
    const done = (r) => { ov.remove(); removeEventListener('keydown', key, true); setTimeout(() => resolve(r), 60); };
    const key = (e) => { if (e.key === 'Escape') { e.preventDefault(); done(null); } };
    addEventListener('keydown', key, true);
    ov.addEventListener('pointerdown', (e) => { on = true; x0 = e.clientX; y0 = e.clientY; box.style.display = 'block'; tip.remove(); });
    ov.addEventListener('pointermove', (e) => { if (!on) return; const x = Math.min(x0, e.clientX), y = Math.min(y0, e.clientY); Object.assign(box.style, { left: x + 'px', top: y + 'px', width: Math.abs(e.clientX - x0) + 'px', height: Math.abs(e.clientY - y0) + 'px' }); });
    ov.addEventListener('pointerup', (e) => { const w = Math.abs(e.clientX - x0), h = Math.abs(e.clientY - y0); done(w > 8 && h > 8 ? { x: Math.min(x0, e.clientX), y: Math.min(y0, e.clientY), w, h, dpr: devicePixelRatio } : null); });
  });
}

async function saveArea(tab, board, note) {
  const [r] = await chrome.scripting.executeScript({ target: { tabId: tab.id }, func: pickArea });
  const rect = r && r.result;
  if (!rect) return null;
  const i = await info(tab.id);
  const shot = await chrome.tabs.captureVisibleTab(tab.windowId, { format: 'png' });
  const blob = await crop(shot, rect, rect.dpr || 1);
  return save({ kind: 'area', url: i.url, title: i.title, author: i.author, site: i.site, board, note }, blob);
}

async function savePage(tab, board, note) {
  const i = await info(tab.id);
  return save({ kind: 'page', url: i.url || tab.url, title: i.title || tab.title, author: i.author, site: i.site, board, note });
}

chrome.contextMenus.onClicked.addListener(async (c, tab) => {
  try {
    if (c.menuItemId === 'topper-area') { const { dest } = await settings(); const j = await saveArea(tab, dest, ''); if (j) notify('Guardado', j.msg); return; }
    const id = String(c.menuItemId);
    if (!id.startsWith(MENU + ':')) return;
    const board = +id.split(':')[1];
    const i = await info(tab.id);
    let p;
    if (c.mediaType === 'image' && c.srcUrl) {
      if (c.srcUrl.startsWith('data:') || c.srcUrl.startsWith('blob:')) {
        const blob = await (await fetch(c.srcUrl)).blob().catch(() => null);
        const j = await save({ kind: 'image', url: c.pageUrl, title: i.title, author: i.author, site: i.site, board }, blob);
        notify('Guardado', j.msg); return;
      }
      p = { kind: 'image', src: c.srcUrl, url: c.pageUrl };
    } else if (c.mediaType === 'video') {
      // um vídeo: o link (com o tempo) e, se der, o frame que está à vista
      try { const j = await saveFrame(tab, board, ''); notify('Guardado', j.msg); return; } catch (e) { p = { kind: 'video', src: c.srcUrl || '', url: c.pageUrl, t: i.video ? i.video.t.toFixed(2) : '' }; }
    } else if (c.linkUrl) p = { kind: 'link', url: c.linkUrl, title: c.selectionText || '' };
    else if (c.selectionText) p = { kind: 'text', url: c.pageUrl, text: c.selectionText };
    else p = { kind: 'page', url: c.pageUrl };
    p = Object.assign({ title: i.title, author: i.author, site: i.site, board }, p);
    if (p.kind === 'link') { p.author = ''; p.site = ''; if (!p.title) p.title = ''; }
    const j = await save(p);
    notify('Guardado', j.msg);
  } catch (e) {
    notify('Não foi guardado', e.message, false);
    if (e.code === 'setup' || e.code === 'token') chrome.runtime.openOptionsPage();
  }
});

chrome.commands.onCommand.addListener(async (cmd, tab) => {
  if (cmd !== 'capture-area' || !tab) return;
  try { const { dest } = await settings(); const j = await saveArea(tab, dest, ''); if (j) notify('Guardado', j.msg); }
  catch (e) { notify('Não foi guardado', e.message, false); }
});

// o popup pede: page | area | frame | refresh
chrome.runtime.onMessage.addListener((m, sender, reply) => {
  (async () => {
    if (m.k === 'refresh') return refreshBoards();
    if (m.k === 'search') return api('boards', { query: '&q=' + encodeURIComponent(m.q || '') });
    const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
    if (!tab) throw new Error('Sem separador.');
    if (m.k === 'page') return savePage(tab, m.board, m.note);
    if (m.k === 'frame') return saveFrame(tab, m.board, m.note);
    if (m.k === 'area') { const j = await saveArea(tab, m.board, m.note); if (j) notify('Guardado', j.msg); return j; }
    if (m.k === 'probe') return info(tab.id);
    throw new Error('Pedido desconhecido.');
  })().then((r) => reply({ ok: true, r }), (e) => reply({ ok: false, error: e.message, code: e.code }));
  return true;
});
