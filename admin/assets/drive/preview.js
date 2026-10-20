/* topper. admin — Drive: pré-visualização (TD.preview).
 *
 * Sobreposição de ecrã inteiro em #drive-preview: imagem (com zoom e
 * arrastar), vídeo, áudio, PDF, texto (os primeiros 512 KB, pedidos com
 * Range; CSV/TSV como tabela até 200 linhas) e, para o resto, um cartão com
 * «Descarregar». ←/→ percorrem a lista, Esc ou Espaço fecham. Os URLs vêm
 * do drive_api › url (em linha, 4 h) e as imagens vizinhas ficam pré-carregadas. */
(function () {
  'use strict';
  const TD = window.TD;
  if (!TD) return;

  const TEXT_MAX = 512 * 1024, CSV_ROWS = 200, URL_TTL = 3.5 * 3600e3;
  const ZMIN = 0.05, ZMAX = 16;
  const INTERACTIVE = 'a[href], button, input, select, textarea, video, audio, iframe, [contenteditable], [tabindex]:not([tabindex="-1"])';

  let host = null, ui = null, S = null;
  const urlCache = new Map();     // chave → {url, t}
  const preloaded = new Map();    // chave → Image (vizinhas)

  async function inlineUrl(key, recent) {
    const c = urlCache.get(key);
    if (c && Date.now() - c.t < URL_TTL) {
      if (recent) TD.api({ do: 'recent', key });
      return c.url;
    }
    const j = TD.must(await TD.api({ do: 'url', key, inline: true, recent: !!recent }));
    urlCache.set(key, { url: j.url, t: Date.now() });
    return j.url;
  }
  async function downloadItem(it) {
    const j = await TD.api({ do: 'url', key: it.k, inline: false });
    if (!j.ok) { TD.toast(j.error || 'Não foi possível descarregar.', 'err'); return; }
    // num iframe escondido: a página não sai do sítio (nem pergunta «sair?» durante envios)
    const f = document.createElement('iframe');
    f.hidden = true; f.className = 'tdp-dl-frame'; f.src = j.url;
    document.body.appendChild(f);
    setTimeout(() => f.remove(), 120000);
    TD.toast('A descarregar «' + TD.path.base(it.k) + '»…', 'info', { ms: 3000 });
  }

  // ── a moldura ───────────────────────────────────────────
  function build() {
    if (ui) return ui;
    host = document.getElementById('drive-preview');
    if (!host) { host = TD.el('div', { id: 'drive-preview' }); document.body.appendChild(host); }
    host.className = 'tdp';
    host.hidden = true;
    host.setAttribute('role', 'dialog');
    host.setAttribute('aria-modal', 'true');
    host.setAttribute('aria-labelledby', 'tdp-name');
    host.setAttribute('tabindex', '-1');
    host.innerHTML =
      '<div class="tdp-top">' +
        '<div class="tdp-id"><span class="tdp-ico"></span>' +
          '<div class="tdp-meta"><h2 class="tdp-name" id="tdp-name"></h2><div class="tdp-sub"></div></div></div>' +
        '<div class="tdp-acts">' +
          '<span class="tdp-extra"></span>' +
          '<a class="tdp-btn tdp-tab" target="_blank" rel="noopener noreferrer" hidden>' + TD.icon('link') + '<span>Abrir noutro separador</span></a>' +
          '<button type="button" class="tdp-btn tdp-dl">' + TD.icon('download') + '<span>Descarregar</span></button>' +
          '<button type="button" class="tdp-btn tdp-x" aria-label="Fechar (Esc)" title="Fechar (Esc)">' + TD.icon('close') + '</button>' +
        '</div>' +
      '</div>' +
      '<div class="tdp-stage">' +
        '<button type="button" class="tdp-nav tdp-prev" aria-label="Anterior (←)" title="Anterior (←)">' + TD.icon('chevron') + '</button>' +
        '<div class="tdp-view"></div>' +
        '<button type="button" class="tdp-nav tdp-next" aria-label="Seguinte (→)" title="Seguinte (→)">' + TD.icon('chevron') + '</button>' +
        '<div class="tdp-zoom" hidden>' +
          '<button type="button" class="tdp-zbtn" data-z="out" aria-label="Afastar (−)" title="Afastar (−)">−</button>' +
          '<button type="button" class="tdp-zbtn tdp-zval" data-z="toggle" title="Ajustar ao ecrã / tamanho real (0)">100%</button>' +
          '<button type="button" class="tdp-zbtn" data-z="in" aria-label="Aproximar (+)" title="Aproximar (+)">+</button>' +
        '</div>' +
      '</div>';
    const $ = s => host.querySelector(s);
    ui = { ico: $('.tdp-ico'), name: $('.tdp-name'), sub: $('.tdp-sub'), extra: $('.tdp-extra'), tab: $('.tdp-tab'), dl: $('.tdp-dl'), x: $('.tdp-x'),
      stage: $('.tdp-stage'), view: $('.tdp-view'), prev: $('.tdp-prev'), next: $('.tdp-next'), zoom: $('.tdp-zoom'), zval: $('.tdp-zval') };
    ui.x.addEventListener('click', () => close());
    ui.dl.addEventListener('click', () => { if (S) downloadItem(S.item); });
    ui.prev.addEventListener('click', () => nav(-1));
    ui.next.addEventListener('click', () => nav(1));
    ui.zoom.addEventListener('click', e => {
      const b = e.target.closest('[data-z]');
      if (!b || !S || !S.img) return;
      const z = b.getAttribute('data-z');
      if (z === 'in') zoomBy(1.25); else if (z === 'out') zoomBy(0.8); else toggleZoom();
    });
    ui.extra.addEventListener('click', e => {
      const b = e.target.closest('button[data-i]');
      if (!b || !S) return;
      const a = (S.ctx.actions || [])[+b.getAttribute('data-i')];
      const fn = a && (a.fn || a.onClick || a.run);
      if (typeof fn === 'function') { try { fn(S.item); } catch (err) { console.error(err); } }
    });
    // clicar no fundo (sem arrastar) fecha
    let downAt = null;
    ui.view.addEventListener('pointerdown', e => { downAt = (e.target === ui.view || e.target.classList.contains('tdp-fill')) ? [e.clientX, e.clientY] : null; });
    ui.view.addEventListener('click', e => {
      if (!downAt || !(e.target === ui.view || e.target.classList.contains('tdp-fill'))) return;
      if (Math.abs(e.clientX - downAt[0]) + Math.abs(e.clientY - downAt[1]) < 6) close();
    });
    swipe();
    window.addEventListener('resize', () => { if (S && S.img) layoutImg(true); });
    return ui;
  }

  // ── abrir / fechar / navegar ────────────────────────────
  function open(item, ctx) {
    // só a chave: o app sabe o resto (tamanho, data, etag), se o tiver
    if (typeof item === 'string') item = (TD.app && typeof TD.app.get === 'function' && TD.app.get(item)) || { k: item };
    if (!item || typeof item.k !== 'string' || TD.path.isFolder(item.k)) return;
    ctx = ctx || {};
    build();
    let list = (Array.isArray(ctx.list) ? ctx.list : []).filter(x => x && typeof x.k === 'string' && !TD.path.isFolder(x.k));
    let idx = list.findIndex(x => x.k === item.k);
    if (idx < 0) { list = [item]; idx = 0; }
    const prevFocus = S ? S.prevFocus : document.activeElement;
    if (S) cleanup();
    S = { item, list, idx, ctx, prevFocus, token: 0, marked: new Set(), img: null, z: null, abort: null };
    // botões do app (Partilhar, Detalhes, Mostrar na pasta…)
    ui.extra.textContent = '';
    (ctx.actions || []).forEach((a, i) => {
      if (!a || !a.label) return;
      const b = TD.el('button', { type: 'button', class: 'tdp-btn', 'data-i': i, title: a.label, 'data-id': a.id || null });
      b.innerHTML = (a.icon ? TD.icon(a.icon) : '') + '<span>' + TD.esc(a.label) + '</span>';
      ui.extra.appendChild(b);
    });
    host.hidden = false;
    document.documentElement.classList.add('tdp-lock');
    document.addEventListener('keydown', onKey, true);
    host.focus({ preventScroll: true });
    show(idx);
  }
  function cleanup() {
    if (!S) return;
    if (S.abort) { try { S.abort.abort(); } catch (e) { /* já terminou */ } S.abort = null; }
    ui.view.querySelectorAll('video, audio').forEach(m => { try { m.pause(); m.removeAttribute('src'); m.load(); } catch (e) { /* já parado */ } });
    ui.view.querySelectorAll('iframe').forEach(f => { f.src = 'about:blank'; });
    ui.view.textContent = '';
    ui.view.className = 'tdp-view';
    ui.zoom.hidden = true;
    ui.tab.hidden = true;
    S.img = null; S.z = null;
  }
  function close() {
    if (!S) return;
    const s = S;
    cleanup();
    S = null;
    host.hidden = true;
    document.documentElement.classList.remove('tdp-lock');
    document.removeEventListener('keydown', onKey, true);
    preloaded.clear();
    if (s.prevFocus && s.prevFocus.isConnected && s.prevFocus.focus) s.prevFocus.focus({ preventScroll: true });
    if (typeof s.ctx.onClose === 'function') { try { s.ctx.onClose(s.item); } catch (err) { console.error(err); } }
  }
  function nav(d) {
    if (!S) return;
    const i = S.idx + d;
    if (i < 0 || i >= S.list.length) return;
    cleanup();
    show(i);
    if (typeof S.ctx.onNav === 'function') { try { S.ctx.onNav(S.item); } catch (err) { console.error(err); } }
  }

  function header(it) {
    const kind = TD.kind(it.k), n = S.list.length;
    ui.ico.innerHTML = TD.icon(kind.id);
    ui.name.textContent = TD.path.base(it.k);
    ui.name.title = it.k;
    const bits = [];
    if (typeof it.s === 'number') bits.push(TD.fmt.size(it.s));
    if (it.m) bits.push(TD.fmt.date(it.m));
    if (n > 1) bits.push((S.idx + 1) + ' de ' + n);
    ui.sub.textContent = bits.join(' · ');
    ui.prev.hidden = ui.next.hidden = n <= 1;
    ui.prev.disabled = S.idx <= 0;
    ui.next.disabled = S.idx >= n - 1;
  }

  async function show(i) {
    const it = S.list[i];
    S.idx = i; S.item = it;
    const token = ++S.token;
    header(it);
    const pv = TD.kind(it.k).preview;
    ui.view.className = 'tdp-view is-loading';
    ui.view.appendChild(TD.el('span', 'tdp-spin'));
    const mark = !S.marked.has(it.k);
    S.marked.add(it.k);
    if (!pv) {
      if (mark) TD.api({ do: 'recent', key: it.k });
      card(it, 'Sem pré-visualização para este tipo de ficheiro.');
      preload();
      return;
    }
    let url;
    try { url = await inlineUrl(it.k, mark); } catch (e) {
      if (S && token === S.token) card(it, e.message || 'Não foi possível abrir o ficheiro.');
      return;
    }
    if (!S || token !== S.token) return;
    if (pv === 'image') showImage(it, url, token);
    else if (pv === 'video') showMedia(it, url, token, 'video');
    else if (pv === 'audio') showMedia(it, url, token, 'audio');
    else if (pv === 'pdf') showPdf(it, url);
    else if (pv === 'text') showText(it, url, token);
    else card(it, 'Sem pré-visualização para este tipo de ficheiro.');
    preload();
  }
  function ready(cls) {
    ui.view.className = 'tdp-view ' + cls;
    const sp = ui.view.querySelector('.tdp-spin');
    if (sp) sp.remove();
  }

  // ── tipos ───────────────────────────────────────────────
  function card(it, msg) {
    if (!S) return;
    ui.view.textContent = '';
    ready('is-card');
    const kind = TD.kind(it.k);
    const c = TD.el('div', 'tdp-card');
    const ic = TD.el('span', 'tdp-card-ico');
    ic.innerHTML = TD.icon(kind.id);
    c.appendChild(ic);
    c.appendChild(TD.el('b', 'tdp-card-n', TD.path.base(it.k)));
    const bits = [kind.label];
    if (typeof it.s === 'number') bits.push(TD.fmt.size(it.s));
    if (it.m) bits.push(TD.fmt.date(it.m));
    c.appendChild(TD.el('span', 'tdp-card-s', bits.join(' · ')));
    c.appendChild(TD.el('p', 'tdp-card-m', msg));
    const b = TD.el('button', { type: 'button', class: 'btn' });
    b.innerHTML = TD.icon('download') + '<span>Descarregar</span>';
    b.addEventListener('click', () => downloadItem(it));
    c.appendChild(b);
    ui.view.appendChild(c);
  }

  function showImage(it, url, token) {
    const fill = TD.el('div', 'tdp-fill');
    const img = new Image();
    img.className = 'tdp-img';
    img.alt = TD.path.base(it.k);
    img.decoding = 'async';
    img.draggable = false;
    img.onload = () => {
      if (!S || token !== S.token) return;
      ready('is-image');
      S.img = img;
      S.z = { s: 1, x: 0, y: 0, fit: true };
      layoutImg(false);
      ui.zoom.hidden = false;
    };
    img.onerror = () => { if (S && token === S.token) card(it, 'Não foi possível mostrar esta imagem.'); };
    img.src = url;
    fill.appendChild(img);
    ui.view.appendChild(fill);
    panZoom(fill, img);
  }
  /** Escala que cabe no ecrã (nunca aumenta além do tamanho real). */
  function fitScale() {
    const r = ui.view.getBoundingClientRect(), w = S.img.naturalWidth || 1, h = S.img.naturalHeight || 1;
    return Math.min(1, (r.width - 24) / w, (r.height - 24) / h) || 1;
  }
  function layoutImg(keep) {
    if (!S || !S.img) return;
    const z = S.z, img = S.img;
    if (!keep || z.fit) { z.s = fitScale(); z.fit = true; }
    clampPan();
    img.style.width = (img.naturalWidth || 1) + 'px';
    img.style.height = (img.naturalHeight || 1) + 'px';
    img.style.transform = 'translate(' + z.x + 'px,' + z.y + 'px) scale(' + z.s + ')';
    ui.view.classList.toggle('is-zoomed', z.s > fitScale() + 1e-3);
    ui.zval.textContent = Math.round(z.s * 100) + '%';
  }
  /** Imagem mais pequena que o ecrã: ao centro; maior: sem deixar margens. */
  function clampPan() {
    const z = S.z, r = ui.view.getBoundingClientRect();
    const w = (S.img.naturalWidth || 1) * z.s, h = (S.img.naturalHeight || 1) * z.s;
    z.x = w <= r.width ? (r.width - w) / 2 : Math.min(0, Math.max(r.width - w, z.x));
    z.y = h <= r.height ? (r.height - h) / 2 : Math.min(0, Math.max(r.height - h, z.y));
  }
  /** Zoom à volta de um ponto (coordenadas da área de visualização). */
  function zoomTo(s, px, py) {
    if (!S || !S.img) return;
    const z = S.z, r = ui.view.getBoundingClientRect();
    if (px == null) { px = r.width / 2; py = r.height / 2; }
    const fs = fitScale();
    s = Math.max(Math.min(ZMIN, fs), Math.min(ZMAX, s));
    const ix = (px - z.x) / z.s, iy = (py - z.y) / z.s;
    z.s = s; z.fit = Math.abs(s - fs) < 1e-3;
    z.x = px - ix * s; z.y = py - iy * s;
    layoutImg(true);
  }
  function zoomBy(f, px, py) { if (S && S.z) zoomTo(S.z.s * f, px, py); }
  function toggleZoom(px, py) {
    if (!S || !S.z) return;
    const fs = fitScale();
    if (S.z.fit || S.z.s <= fs + 1e-3) zoomTo(fs < 1 ? 1 : Math.min(ZMAX, fs * 2), px, py);
    else { S.z.fit = true; layoutImg(false); }
  }
  /** Roda = zoom; arrastar = mover; dois dedos = zoom; duplo clique = tamanho real / ajustar. */
  function panZoom(fill, img) {
    const pts = new Map();
    let pinch = null, drag = null;
    const local = e => { const r = ui.view.getBoundingClientRect(); return [e.clientX - r.left, e.clientY - r.top]; };
    fill.addEventListener('wheel', e => {
      if (!S || !S.img) return;
      e.preventDefault();
      const [x, y] = local(e);
      zoomBy(Math.pow(1.0018, -e.deltaY * (e.deltaMode === 1 ? 33 : 1)), x, y);
    }, { passive: false });
    fill.addEventListener('dblclick', e => { if (!S || !S.img) return; const [x, y] = local(e); toggleZoom(x, y); });
    img.addEventListener('pointerdown', e => {
      if (!S || !S.img) return;
      img.setPointerCapture(e.pointerId);
      pts.set(e.pointerId, local(e));
      if (pts.size === 2) {
        const [a, b] = Array.from(pts.values());
        pinch = { d: Math.hypot(a[0] - b[0], a[1] - b[1]) || 1, s: S.z.s };
        drag = null;
      } else if (pts.size === 1) {
        drag = { x: e.clientX, y: e.clientY, zx: S.z.x, zy: S.z.y };
      }
    });
    img.addEventListener('pointermove', e => {
      if (!S || !S.img || !pts.has(e.pointerId)) return;
      pts.set(e.pointerId, local(e));
      if (pinch && pts.size >= 2) {
        const [a, b] = Array.from(pts.values());
        const d = Math.hypot(a[0] - b[0], a[1] - b[1]) || 1;
        zoomTo(pinch.s * d / pinch.d, (a[0] + b[0]) / 2, (a[1] + b[1]) / 2);
      } else if (drag && ui.view.classList.contains('is-zoomed')) {
        S.z.x = drag.zx + e.clientX - drag.x;
        S.z.y = drag.zy + e.clientY - drag.y;
        S.z.fit = false;
        layoutImg(true);
      }
    });
    const up = e => { pts.delete(e.pointerId); if (pts.size < 2) pinch = null; if (!pts.size) drag = null; };
    img.addEventListener('pointerup', up);
    img.addEventListener('pointercancel', up);
  }
  /** Deslizar o dedo para o lado (sem zoom) muda de ficheiro. */
  function swipe() {
    let st = null;
    ui.stage.addEventListener('pointerdown', e => {
      if (e.pointerType !== 'touch' || !S) return;
      if (st) { st = null; return; }   // segundo dedo: é um zoom, não um deslizar
      const v = ui.view.className;
      if (!/is-image|is-card/.test(v) || /is-zoomed/.test(v)) return;
      st = { x: e.clientX, y: e.clientY, t: Date.now(), id: e.pointerId };
    });
    ui.stage.addEventListener('pointerup', e => {
      if (!st || e.pointerId !== st.id) return;
      const dx = e.clientX - st.x, dy = e.clientY - st.y, dt = Date.now() - st.t;
      st = null;
      if (dt < 700 && Math.abs(dx) > 60 && Math.abs(dx) > Math.abs(dy) * 1.5) nav(dx < 0 ? 1 : -1);
    });
    ui.stage.addEventListener('pointercancel', () => { st = null; });
  }

  function showMedia(it, url, token, tag) {
    const m = document.createElement(tag);
    m.className = 'tdp-' + tag;
    m.controls = true;
    m.autoplay = true;
    m.preload = 'metadata';
    if (tag === 'video') {
      m.playsInline = true;
      m.setAttribute('playsinline', '');
      const poster = TD.thumbs && TD.thumbs.cached ? TD.thumbs.cached(it.e) : null;
      if (poster) m.poster = poster;
    }
    m.addEventListener('error', () => {
      if (!S || token !== S.token) return;
      card(it, tag === 'video' ? 'O browser não consegue reproduzir este vídeo (formato ou codec).' : 'O browser não consegue reproduzir este áudio.');
    });
    const shown = () => { if (S && token === S.token) ready(tag === 'video' ? 'is-video' : 'is-audio'); };
    m.addEventListener('loadedmetadata', shown, { once: true });
    m.src = url;
    if (tag === 'audio') {
      const box = TD.el('div', 'tdp-audio-box');
      const ic = TD.el('span', 'tdp-card-ico');
      ic.innerHTML = TD.icon('audio');
      box.appendChild(ic);
      box.appendChild(TD.el('b', 'tdp-card-n', TD.path.base(it.k)));
      box.appendChild(m);
      ui.view.appendChild(box);
    } else ui.view.appendChild(m);
    const p = m.play && m.play();
    if (p && p.catch) p.catch(() => { /* sem autoplay: fica com os controlos */ });
    setTimeout(shown, 1500);   // sem metadados ainda: pelo menos mostra os controlos
  }

  function showPdf(it, url) {
    const f = TD.el('iframe', { class: 'tdp-pdf', title: TD.path.base(it.k) });
    f.src = url;
    ui.view.appendChild(f);
    ready('is-pdf');
    // telemóveis mostram mal PDFs embebidos: também se abre num separador
    ui.tab.href = url;
    ui.tab.hidden = false;
  }

  // ── texto e CSV ─────────────────────────────────────────
  async function showText(it, url, token) {
    const ac = 'AbortController' in window ? new AbortController() : null;
    S.abort = ac;
    let bytes, cut;
    try {
      if (it.s === 0) throw Object.assign(new Error(''), { empty: true });
      const r = await fetch(url, { headers: { Range: 'bytes=0-' + (TEXT_MAX - 1) }, signal: ac ? ac.signal : undefined, credentials: 'omit' });
      if (r.status === 416) throw Object.assign(new Error(''), { empty: true });
      if (!r.ok) throw new Error(r.status === 404 ? 'O ficheiro já não existe.' : 'Não foi possível ler o ficheiro (HTTP ' + r.status + ').');
      // sem Range (200), lê só o início e corta a ligação
      const chunks = [];
      let n = 0;
      const reader = r.body.getReader();
      for (;;) {
        const { done, value } = await reader.read();
        if (done) break;
        chunks.push(value); n += value.length;
        if (n >= TEXT_MAX) { reader.cancel().catch(() => {}); break; }
      }
      bytes = new Uint8Array(Math.min(n, TEXT_MAX));
      let p = 0;
      for (const c of chunks) { if (p >= bytes.length) break; const part = c.subarray(0, bytes.length - p); bytes.set(part, p); p += part.length; }
      const total = typeof it.s === 'number' ? it.s : n;
      const range = /\/(\d+)$/.exec(r.headers.get('Content-Range') || '');
      cut = (range ? +range[1] : total) > bytes.length;
    } catch (e) {
      if (!S || token !== S.token || (e && e.name === 'AbortError')) return;
      if (e && e.empty) { bytes = new Uint8Array(0); cut = false; }
      else { card(it, e.message || 'Não foi possível ler o ficheiro.'); return; }
    }
    if (!S || token !== S.token) return;
    S.abort = null;
    const text = decode(bytes, cut);
    const ext = TD.path.ext(it.k);
    ui.view.textContent = '';
    const wrap = TD.el('div', 'tdp-doc');
    const note = cut ? 'Mostra os primeiros 512 KB' + (typeof it.s === 'number' ? ' de ' + TD.fmt.size(it.s) : '') + '. Descarregue o ficheiro para ver tudo.' : '';
    if (ext === 'csv' || ext === 'tsv') {
      const t = csvTable(text, ext === 'tsv' ? '\t' : sniff(text));
      if (t.more || cut) wrap.appendChild(TD.el('p', 'tdp-note', 'Mostra as primeiras ' + Math.min(CSV_ROWS, t.rows) + ' linhas. Descarregue o ficheiro para ver tudo.'));
      wrap.appendChild(t.el);
      ready('is-table');
    } else {
      if (note) wrap.appendChild(TD.el('p', 'tdp-note', note));
      let body = text;
      if (ext === 'json' && !cut) { try { body = JSON.stringify(JSON.parse(text), null, 2); } catch (e) { body = text; } }
      const pre = TD.el('pre', { class: 'tdp-text', tabindex: '0' });
      pre.textContent = body;
      if (!body) pre.appendChild(TD.el('span', 'tdp-empty', 'Este ficheiro está vazio.'));
      wrap.appendChild(pre);
      ready('is-text');
    }
    ui.view.appendChild(wrap);
  }
  /** UTF-8 (com ou sem BOM), UTF-16 com BOM; se não for UTF-8 válido, Windows-1252 (Excel). */
  function decode(b, cut) {
    try {
      if (b[0] === 0xFF && b[1] === 0xFE) return new TextDecoder('utf-16le').decode(b.subarray(2), { stream: cut });
      if (b[0] === 0xFE && b[1] === 0xFF) return new TextDecoder('utf-16be').decode(b.subarray(2), { stream: cut });
    } catch (e) { /* segue para UTF-8 */ }
    const s = b[0] === 0xEF && b[1] === 0xBB && b[2] === 0xBF ? b.subarray(3) : b;
    try { return new TextDecoder('utf-8', { fatal: true }).decode(s, { stream: cut }); } catch (e) {
      try { return new TextDecoder('windows-1252').decode(s); } catch (e2) { return new TextDecoder('utf-8').decode(s); }
    }
  }
  /** O separador mais provável nas primeiras linhas: «;» (Excel em português), «,» ou tab. */
  function sniff(text) {
    const lines = text.split(/\r?\n/, 6).filter(l => l.trim());
    let best = ',', bestScore = -1;
    [';', ',', '\t', '|'].forEach(d => {
      const counts = lines.map(l => l.replace(/"[^"]*"/g, '').split(d).length - 1);
      const min = counts.length ? Math.min.apply(null, counts) : 0;
      const score = min > 0 ? min * 10 + counts.reduce((a, c) => a + c, 0) / counts.length : 0;
      if (score > bestScore) { best = d; bestScore = score; }
    });
    return best;
  }
  /** RFC 4180: aspas, aspas duplicadas e quebras de linha dentro de campos. */
  function parseCsv(text, d, max) {
    const rows = [];
    let row = [], f = '', q = false, i = 0;
    const n = text.length;
    while (i < n) {
      const c = text[i];
      if (q) {
        if (c === '"') { if (text[i + 1] === '"') { f += '"'; i += 2; continue; } q = false; i++; continue; }
        f += c; i++; continue;
      }
      if (c === '"' && f === '') { q = true; i++; continue; }
      if (c === d) { row.push(f); f = ''; i++; continue; }
      if (c === '\r' || c === '\n') {
        row.push(f); f = '';
        rows.push(row); row = [];
        if (c === '\r' && text[i + 1] === '\n') i++;
        i++;
        if (rows.length > max) return { rows, more: true };
        continue;
      }
      f += c; i++;
    }
    if (f !== '' || row.length) { row.push(f); rows.push(row); }
    return { rows, more: rows.length > max };
  }
  function csvTable(text, d) {
    const p = parseCsv(text, d, CSV_ROWS);
    const rows = p.rows.slice(0, CSV_ROWS);
    const cols = rows.reduce((a, r) => Math.max(a, r.length), 0);
    const box = TD.el('div', { class: 'tdp-tablebox', tabindex: '0' });
    const t = TD.el('table', 'tdp-csv');
    const head = TD.el('thead'), body = TD.el('tbody');
    rows.forEach((r, ri) => {
      const tr = TD.el('tr');
      tr.appendChild(TD.el(ri === 0 ? 'th' : 'td', 'tdp-rn', ri === 0 ? '' : String(ri)));
      for (let c = 0; c < cols; c++) {
        const v = r[c] == null ? '' : r[c];
        const cell = TD.el(ri === 0 ? 'th' : 'td');
        const d = TD.el('div', null, v);   // a div limita a largura (nas células da tabela o max-width não conta)
        if (v.length > 40 || v.indexOf('\n') >= 0) d.title = v.slice(0, 2000);
        cell.appendChild(d);
        tr.appendChild(cell);
      }
      (ri === 0 ? head : body).appendChild(tr);
    });
    t.appendChild(head); t.appendChild(body);
    box.appendChild(t);
    return { el: box, rows: rows.length, more: p.more };
  }

  // ── vizinhas ────────────────────────────────────────────
  function preload() {
    if (!S) return;
    [S.idx + 1, S.idx - 1].forEach(i => {
      const it = S.list[i];
      if (!it || TD.kind(it.k).preview !== 'image' || preloaded.has(it.k)) return;
      preloaded.set(it.k, null);
      inlineUrl(it.k, false).then(u => { const im = new Image(); im.decoding = 'async'; im.src = u; preloaded.set(it.k, im); }, () => preloaded.delete(it.k));
    });
  }

  // ── teclado e foco ──────────────────────────────────────
  function onKey(e) {
    if (!S) return;
    if (document.querySelector('.td-dialog-back')) return;   // um diálogo por cima (ex.: Partilhar) trata das teclas
    const t = e.target;
    const inField = t && t.closest && t.closest('input, textarea, select, [contenteditable]');
    const onMedia = t && t.closest && t.closest('video, audio');
    const k = e.key;
    // só se travam as teclas que a pré-visualização usa (o resto segue: botões, vídeo…)
    const eat = fn => { e.preventDefault(); e.stopPropagation(); fn(); };
    if (k === 'Escape') { eat(close); return; }
    if (k === 'Tab') { trap(e); return; }
    if (inField || e.ctrlKey || e.metaKey || e.altKey) return;
    if (k === ' ' || k === 'Spacebar') {
      if (t && t !== host && t.closest && t.closest(INTERACTIVE)) return;   // Espaço num botão/vídeo faz o normal
      eat(close); return;
    }
    if ((k === 'ArrowLeft' || k === 'ArrowRight') && !onMedia) { eat(() => nav(k === 'ArrowLeft' ? -1 : 1)); return; }
    if (S.img) {
      if (k === '+' || k === '=') eat(() => zoomBy(1.25));
      else if (k === '-' || k === '_') eat(() => zoomBy(0.8));
      else if (k === '0') eat(() => { S.z.fit = true; layoutImg(false); });
      else if (k === '1') eat(() => zoomTo(1));
    }
  }
  function trap(e) {
    const f = Array.from(host.querySelectorAll('button, a[href], video, audio, iframe, [tabindex]:not([tabindex="-1"])'))
      .filter(x => !x.hidden && !x.disabled && x.offsetParent !== null && !x.closest('[hidden]'));
    if (!f.length) { e.preventDefault(); host.focus(); return; }
    const first = f[0], last = f[f.length - 1], a = document.activeElement;
    if (e.shiftKey && (a === first || a === host || !host.contains(a))) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && (a === last || !host.contains(a))) { e.preventDefault(); first.focus(); }
  }

  TD.preview = {
    /** item = objeto do catálogo ({k, s, m, e}); ctx = {list, onNav(item), onClose(item), actions:[{id, label, icon, fn(item)}]} */
    open,
    close,
    isOpen: () => !!S,
    /** o item mostrado agora (ou null) */
    current: () => (S ? S.item : null),
    next: () => nav(1),
    prev: () => nav(-1),
  };
})();
