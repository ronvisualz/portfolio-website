/* topper. admin — Drive: envios (TD.upload) e miniaturas (TD.thumbs).
 *
 * Os bytes vão do browser diretamente para o R2: o drive_api.php só assina.
 * Até 16 MB um único PUT; acima, multipart em partes de 16 MB (4 em paralelo),
 * com novas tentativas (espera crescente) e retoma pelo sessionStorage se a
 * ligação cair ou a página for recarregada a meio.
 * A barra de envios (#drive-upload-dock) fica em baixo e continua ao navegar.
 * Miniaturas: JPEG com o lado maior de 320 px em .topper/thumbs/<etag>.jpg,
 * feitas no browser — do ficheiro local ao enviar, ou do URL do R2 para os
 * ficheiros que já lá estão. */
(function () {
  'use strict';
  const TD = window.TD;
  if (!TD) return;

  const ETAG_RE = /^[a-f0-9]{32}(-\d{1,5})?$/;
  const JUNK = /^(\.DS_Store|Thumbs\.db|desktop\.ini)$/i;
  const normEtag = e => String(e || '').replace(/"/g, '').trim().toLowerCase();
  const sleep = ms => new Promise(r => setTimeout(r, ms));

  // ════════════════════════════════════════════════════════
  //  Miniaturas
  // ════════════════════════════════════════════════════════
  const THUMB_MAX = 320, THUMB_Q = 0.75;
  const IMG_REMOTE_MAX = 25 * 1024 * 1024;   // acima disto não se descarrega a imagem só para a miniatura
  const IMG_LOCAL_MAX = 80 * 1024 * 1024;
  const FAIL_KEY = 'thumbs.fail', FAIL_DAYS = 30, FAIL_MAX = 2000;
  const T_OK = 5 * 3600e3, T_NONE = 60e3;    // validade da cache: URL (assinado por 6 h+) / «não existe»

  /** Falha definitiva (não é um formato que o browser consiga ler): não se volta a tentar. */
  class ThumbError extends Error {}

  /** 'image' | 'video' | '' — só o que o browser consegue desenhar. */
  function thumbKind(key) {
    const pv = TD.kind(String(key || '')).preview;
    return pv === 'image' || pv === 'video' ? pv : '';
  }

  const tcache = new Map();     // etag → {url|null, t}
  function cachedUrl(e) {
    const c = tcache.get(e);
    if (!c) return undefined;
    if (Date.now() - c.t > (c.url ? T_OK : T_NONE)) { tcache.delete(e); return undefined; }
    return c.url;
  }

  // ── thumb_urls em lote (vários pedidos seguidos juntam-se num só) ──
  let want = new Set(), waiters = [], flushT = 0;
  function urls(etags) {
    const out = {}, missing = [];
    (etags || []).forEach(x => {
      const e = normEtag(x);
      if (!ETAG_RE.test(e)) return;
      const c = cachedUrl(e);
      if (c) out[e] = c;
      else if (c === undefined) missing.push(e);
    });
    if (!missing.length) return Promise.resolve(out);
    return new Promise(resolve => {
      missing.forEach(e => want.add(e));
      waiters.push({ etags: missing, out, resolve });
      clearTimeout(flushT);
      flushT = setTimeout(flushUrls, 30);
    });
  }
  async function flushUrls() {
    const list = Array.from(want), ws = waiters;
    want = new Set(); waiters = [];
    for (let i = 0; i < list.length; i += 300) {
      const chunk = list.slice(i, i + 300);
      const j = await TD.api({ do: 'thumb_urls', etags: chunk });
      if (!j.ok) continue;           // sem resposta: fica por saber (pede-se outra vez mais tarde)
      const t = Date.now(), got = j.urls || {};
      chunk.forEach(e => tcache.set(e, { url: got[e] || null, t }));
    }
    ws.forEach(w => {
      w.etags.forEach(e => { const c = cachedUrl(e); if (c) w.out[e] = c; });
      w.resolve(w.out);
    });
  }

  // ── falhas lembradas (TD.store, por browser) ──
  function failed(e) {
    const m = TD.store.get(FAIL_KEY, null);
    return !!(m && m[e] && Date.now() / 1000 - m[e] < FAIL_DAYS * 86400);
  }
  function remember(e) {
    const m = TD.store.get(FAIL_KEY, null) || {};
    m[e] = Math.floor(Date.now() / 1000);
    const ks = Object.keys(m);
    if (ks.length > FAIL_MAX) ks.sort((a, b) => m[a] - m[b]).slice(0, ks.length - FAIL_MAX).forEach(k => { delete m[k]; });
    TD.store.set(FAIL_KEY, m);
  }

  // ── desenhar ──
  function toJpeg(src, w, h) {
    if (!w || !h) throw new ThumbError('Sem dimensões.');
    const s = Math.min(1, THUMB_MAX / Math.max(w, h));
    const cw = Math.max(1, Math.round(w * s)), ch = Math.max(1, Math.round(h * s));
    const c = document.createElement('canvas');
    c.width = cw; c.height = ch;
    const g = c.getContext('2d');
    g.fillStyle = '#fff';                 // PNG transparente → fundo branco (o JPEG não tem alfa)
    g.fillRect(0, 0, cw, ch);
    g.imageSmoothingEnabled = true;
    g.imageSmoothingQuality = 'high';
    g.drawImage(src, 0, 0, cw, ch);
    return new Promise((resolve, reject) => {
      try {
        c.toBlob(b => (b && b.size ? resolve(b) : reject(new ThumbError('Não foi possível criar o JPEG.'))), 'image/jpeg', THUMB_Q);
      } catch (e) { reject(new ThumbError('Imagem protegida (CORS).')); }
    });
  }
  /** Imagem a partir de um URL do próprio browser (blob:). Erro = formato que não se consegue ler. */
  function imageThumb(src) {
    return new Promise((resolve, reject) => {
      const img = new Image();
      img.decoding = 'async';
      const t = setTimeout(() => { img.src = ''; reject(new ThumbError('A imagem demorou demasiado.')); }, 30000);
      img.onload = () => {
        clearTimeout(t);
        // SVG sem tamanho próprio: quadrado
        const w = img.naturalWidth || THUMB_MAX, h = img.naturalHeight || THUMB_MAX;
        toJpeg(img, w, h).then(resolve, reject);
      };
      img.onerror = () => { clearTimeout(t); reject(new ThumbError('Formato de imagem que o browser não lê.')); };
      img.src = src;
    });
  }
  /** Frame de um vídeo: a 1 s, ou a ⅓ da duração se for mais curto. */
  function videoThumb(src, cors) {
    return new Promise((resolve, reject) => {
      const v = document.createElement('video');
      v.muted = true; v.playsInline = true; v.preload = 'auto';
      if (cors) v.crossOrigin = 'anonymous';
      let timer = 0, over = false;
      const done = (err, val) => {
        if (over) return;
        over = true;
        clearTimeout(timer);
        v.onloadedmetadata = v.onseeked = v.onerror = v.onloadeddata = null;
        try { v.removeAttribute('src'); v.load(); } catch (e) { /* já libertado */ }
        if (err) reject(err); else resolve(val);
      };
      const wait = (ms, err) => { clearTimeout(timer); timer = setTimeout(() => done(err), ms); };
      const grab = () => { toJpeg(v, v.videoWidth, v.videoHeight).then(b => done(null, b), e => done(e)); };
      v.onerror = () => {
        const c = v.error ? v.error.code : 0;
        // 2 = rede (tenta-se outra vez noutra altura); 3/4 = o browser não descodifica este vídeo
        done(c === 2 ? new Error('A ligação falhou.') : new ThumbError('Vídeo que o browser não reproduz.'));
      };
      v.onloadedmetadata = () => {
        if (!v.videoWidth) { done(new ThumbError('Sem imagem de vídeo.')); return; }
        const d = v.duration;
        const at = isFinite(d) && d > 0 ? Math.min(1, d / 3) : 0.1;
        wait(20000, new ThumbError('O vídeo não avançou até ao frame.'));
        v.onseeked = () => {
          if (v.readyState >= 2) grab();
          else v.onloadeddata = grab;
        };
        v.currentTime = at;
      };
      wait(25000, new Error('O vídeo demorou demasiado a abrir.'));
      v.src = src;
    });
  }
  async function remoteImage(src) {
    const ac = 'AbortController' in window ? new AbortController() : null;
    const t = setTimeout(() => { if (ac) ac.abort(); }, 60000);
    let blob;
    try {
      const r = await fetch(src, { signal: ac ? ac.signal : undefined, credentials: 'omit' });
      if (!r.ok) throw new Error('HTTP ' + r.status);
      blob = await r.blob();
    } finally { clearTimeout(t); }
    if (blob.size > IMG_REMOTE_MAX) throw new ThumbError('Imagem grande demais para miniatura.');
    const u = URL.createObjectURL(blob);
    try { return await imageThumb(u); } finally { URL.revokeObjectURL(u); }
  }
  /** Envia o JPEG para .topper/thumbs/<etag>.jpg. Devolve um URL para mostrar já. */
  async function putThumb(e, blob) {
    const j = TD.must(await TD.api({ do: 'thumb_sign', etag: e }));
    const r = await fetch(j.url, { method: 'PUT', headers: { 'Content-Type': 'image/jpeg' }, body: blob, credentials: 'omit' });
    if (!r.ok) throw new Error('O R2 recusou a miniatura (HTTP ' + r.status + ').');
    const url = j.get || URL.createObjectURL(blob);
    tcache.set(e, { url, t: Date.now() });
    return url;
  }

  // ── quem está à espera de cada miniatura ──
  const listeners = new Map();   // etag → [onReady]
  function listen(e, fn) {
    if (typeof fn !== 'function') return;
    const l = listeners.get(e) || [];
    if (l.indexOf(fn) < 0) l.push(fn);
    listeners.set(e, l);
  }
  function notify(e, url) {
    const l = listeners.get(e) || [];
    listeners.delete(e);
    if (!url) return;
    l.forEach(fn => { try { fn(e, url); } catch (err) { console.error(err); } });
  }

  // ── a partir do ficheiro local (ao enviar), um de cada vez ──
  const localPending = new Map();   // etag → Promise<url|null>
  let localChain = Promise.resolve();
  function fromFile(file, etag) {
    const e = normEtag(etag), kind = file && thumbKind(file.name);
    if (!kind || !ETAG_RE.test(e)) return Promise.resolve(null);
    if (kind === 'image' && file.size > IMG_LOCAL_MAX) return Promise.resolve(null);
    if (localPending.has(e)) return localPending.get(e);
    const p = localChain.then(async () => {
      const src = URL.createObjectURL(file);
      try {
        const blob = kind === 'image' ? await imageThumb(src) : await videoThumb(src, false);
        const url = await putThumb(e, blob);
        notify(e, url);
        return url;
      } catch (err) {
        if (err instanceof ThumbError) remember(e);
        notify(e, null);
        return null;
      } finally {
        URL.revokeObjectURL(src);
      }
    });
    localChain = p.catch(() => null);
    localPending.set(e, p);
    p.then(() => { localPending.delete(e); }, () => { localPending.delete(e); });
    return p;
  }

  // ── para o que já está no R2 (grelha), 2 de cada vez ──
  const rqueue = [], inflight = new Set();
  let rrunning = false;
  function ensure(items, onReady) {
    (items || []).forEach(it => {
      if (!it || typeof it.k !== 'string') return;
      const e = normEtag(it.e), kind = thumbKind(it.k);
      if (!kind || !ETAG_RE.test(e)) return;
      const c = cachedUrl(e);
      if (c) { if (onReady) { try { onReady(e, c); } catch (err) { console.error(err); } } return; }
      if (inflight.has(e) || localPending.has(e)) { listen(e, onReady); return; }
      if (failed(e) || (kind === 'image' && +it.s > IMG_REMOTE_MAX)) return;
      listen(e, onReady);
      inflight.add(e);
      rqueue.push({ k: it.k, e, kind });
    });
    if (!rrunning && rqueue.length) runRemote();
  }
  async function runRemote() {
    rrunning = true;
    try {
      while (rqueue.length) {
        const batch = rqueue.splice(0, 40);
        try { await remoteBatch(batch); } catch (err) { /* fica para a próxima vez */ }
        batch.forEach(x => { inflight.delete(x.e); if (!cachedUrl(x.e)) listeners.delete(x.e); });
      }
    } finally { rrunning = false; }
  }
  async function remoteBatch(batch) {
    // talvez alguém (outro browser) já as tenha feito
    const have = await urls(batch.map(x => x.e));
    const todo = batch.filter(x => { if (have[x.e]) { notify(x.e, have[x.e]); return false; } return true; });
    if (!todo.length) return;
    const j = await TD.api({ do: 'urls', keys: todo.map(x => x.k), inline: true });
    if (!j.ok) return;
    let i = 0;
    const worker = async () => {
      while (i < todo.length) {
        const x = todo[i++];
        const src = j.urls && j.urls[x.k];
        if (!src) continue;
        try {
          const blob = x.kind === 'image' ? await remoteImage(src) : await videoThumb(src, true);
          notify(x.e, await putThumb(x.e, blob));
        } catch (err) {
          if (err instanceof ThumbError) remember(x.e);
        }
      }
    };
    await Promise.all([worker(), worker()]);
  }

  TD.thumbs = {
    /** {etag: url} das miniaturas que existem (pedidos em lote, com cache). */
    urls,
    /** Gera as que faltam para os itens dados ({k, e, s}); onReady(etag, url) quando houver. */
    ensure,
    /** O URL já conhecido (síncrono), ou null. */
    cached: etag => cachedUrl(normEtag(etag)) || null,
    /** Miniatura a partir do ficheiro local que acabou de ser enviado. */
    fromFile,
  };

  // ════════════════════════════════════════════════════════
  //  Envios
  // ════════════════════════════════════════════════════════
  const FILES_AT_ONCE = 3, PARTS_AT_ONCE = 4, RETRIES = 4, SIGN_BATCH = 20;
  const MP_PREFIX = 'r2mp:';

  const jobs = [];           // os que estão na barra, por ordem
  const queue = [];          // à espera de vez
  const busyKeys = new Set();
  // As chaves finais dos envios em curso. Com «Manter ambos» um envio pode ficar com «nome (2)»:
  // outro envio para «nome (2)» espera que este acabe (e depois vê o conflito).
  const reserved = new Set();
  let active = 0, seq = 0, batch = null;
  let dock = null, ui = null;
  let conflictLock = Promise.resolve();

  // ── sessionStorage (retoma dos multipart) ──
  function save(k, v) { try { sessionStorage.setItem(k, JSON.stringify(v)); } catch (e) { /* sem armazenamento */ } }
  function load(k) { try { return JSON.parse(sessionStorage.getItem(k) || 'null'); } catch (e) { return null; } }
  function drop(k) { try { sessionStorage.removeItem(k); } catch (e) { /* sem armazenamento */ } }
  /** A mesma chave que o envio antigo (drive.js), para retomar envios começados lá. */
  function storeKey(job) { return MP_PREFIX + job.prefix + '\u0000' + job.name + '\u0000' + job.size + '\u0000' + job.file.lastModified; }
  function savedUploads() {
    const out = [];
    try {
      for (let i = 0; i < sessionStorage.length; i++) {
        const k = sessionStorage.key(i);
        if (!k || k.indexOf(MP_PREFIX) !== 0) continue;
        const parts = k.slice(MP_PREFIX.length).split('\u0000');
        const rec = load(k);
        if (parts.length !== 4 || !rec || !rec.key || !rec.uploadId) continue;
        out.push({ sk: k, prefix: parts[0], name: parts[1], size: +parts[2], rec });
      }
    } catch (e) { /* sem armazenamento */ }
    return out;
  }

  // ── nomes e pastas ──
  function cleanSeg(s) {
    return String(s).replace(/\\/g, '-').replace(/[\x00-\x1f\x7f]/g, '').replace(/\s+/g, ' ').trim();
  }
  /** «Sub/Pasta/» limpo (sem segmentos vazios, «.» ou «..»), ou ''. */
  function cleanRel(r) {
    if (!r) return '';
    const segs = String(r).split('/').map(cleanSeg).filter(s => s && s !== '.' && s !== '..');
    return segs.length ? segs.join('/') + '/' : '';
  }
  function cleanPrefix(p) {
    p = String(p || '');
    if (p && p.slice(-1) !== '/') p += '/';
    if (p.indexOf('.topper/') === 0 || p[0] === '/') return null;
    return p;
  }
  function folderLabel(p) { return p ? TD.path.base(p) : 'Drive'; }
  function shownName(job) { return job.rel + (job.key ? TD.path.base(job.key) : job.name); }

  function cancelErr() { const e = new Error('Cancelado.'); e.cancelled = true; return e; }

  /** Espera antes de tentar outra vez: 0,8 s, 1,6 s, 3,2 s… e, sem rede, até ela voltar. */
  async function backoff(attempt, job) {
    const until = Date.now() + 800 * Math.pow(2, attempt);
    while (Date.now() < until || navigator.onLine === false) {
      if (job.cancelled) throw cancelErr();
      await sleep(200);
    }
  }

  /** PUT com progresso (XHR, por causa do upload.onprogress). Resolve com o ETag (sem aspas). */
  function put(url, body, type, onProgress, job) {
    return new Promise((resolve, reject) => {
      const x = new XMLHttpRequest();
      let last = Date.now(), stalled = false;
      // ligação parada (sem progresso nem resposta) durante 90 s: aborta e tenta outra vez
      const wd = setInterval(() => { if (Date.now() - last > 90000) { stalled = true; x.abort(); } }, 5000);
      const end = () => { clearInterval(wd); job.xhrs.delete(x); };
      job.xhrs.add(x);
      x.open('PUT', url);
      if (type) x.setRequestHeader('Content-Type', type);
      x.upload.onprogress = ev => { last = Date.now(); onProgress(ev.loaded); };
      x.onload = () => {
        end();
        if (x.status >= 200 && x.status < 300) resolve(String(x.getResponseHeader('ETag') || '').replace(/"/g, '').trim());
        else { const e = new Error('O R2 recusou o envio (HTTP ' + x.status + ').'); e.http = x.status; reject(e); }
      };
      x.onerror = () => { end(); reject(new Error('A ligação ao R2 falhou (rede ou CORS).')); };
      x.onabort = () => { end(); reject(stalled ? new Error('O envio parou (o R2 deixou de responder).') : cancelErr()); };
      x.send(body);
    });
  }
  /** Erros do servidor que não vale a pena repetir (4xx, salvo 408/429). */
  function retryable(j) { return !j.status || j.status >= 500 || j.status === 408 || j.status === 429; }
  async function apiRetry(body, job) {
    let j = null;
    for (let attempt = 0; attempt < 3; attempt++) {
      j = await TD.api(body);
      if (j.ok || !retryable(j) || job.cancelled) return j;
      if (attempt < 2) await backoff(attempt, job);
    }
    return j;
  }

  // ── conflitos («Já existe») ──
  function conflictDialog(job, remaining) {
    return TD.dialog.open((box, done) => {
      box.classList.add('tdu-cf');
      box.setAttribute('aria-labelledby', 'tdu-cf-t');
      box.appendChild(TD.el('h2', { class: 'td-dialog-title', id: 'tdu-cf-t' }, 'Já existe um ficheiro com este nome'));
      box.appendChild(TD.el('p', 'td-dialog-text', 'Já existe «' + job.name + '» em «' + folderLabel(job.prefix) + '».'));
      const l = TD.el('div', 'td-dialog-choices');
      let cb = null;
      [['overwrite', 'Substituir', 'O ficheiro que já lá está é substituído por este.'],
        ['rename', 'Manter ambos', 'Este fica com um nome novo, por exemplo «nome (2)».'],
        ['skip', 'Saltar', 'Este ficheiro não é enviado.']].forEach(([v, label, hint]) => {
        const b = TD.el('button', { class: 'td-choice' + (v === 'overwrite' ? ' danger' : ''), type: 'button', 'data-choice': v, autofocus: v === 'rename' });
        b.appendChild(TD.el('b', null, label));
        b.appendChild(TD.el('span', 'muted', hint));
        b.onclick = () => done({ choice: v, all: !!(cb && cb.checked) });
        l.appendChild(b);
      });
      box.appendChild(l);
      if (remaining > 0) {
        const lab = TD.el('label', 'check tdu-cf-all');
        cb = TD.el('input', { type: 'checkbox' });
        lab.appendChild(cb);
        lab.appendChild(document.createTextNode(' Aplicar aos restantes envios (' + remaining + ')'));
        box.appendChild(lab);
      }
    });
  }
  /** → 'overwrite' | 'rename' | 'skip'. Um diálogo de cada vez; «aplicar aos restantes» vale para o lote. */
  function resolveConflict(job) {
    const b = job.batch;
    if (b.conflictAll) return Promise.resolve(b.conflictAll);
    const p = conflictLock.then(async () => {
      if (b.conflictAll) return b.conflictAll;
      if (job.cancelled) return 'skip';
      job.phase = 'conflict'; touch(job);
      const remaining = b.jobs.filter(j => j !== job && (j.state === 'queued' || j.phase === 'conflict' || j.phase === 'sign')).length;
      const r = await conflictDialog(job, remaining);
      const choice = r && r.choice ? r.choice : 'skip';   // Esc = Saltar (nada se perde)
      if (r && r.all) b.conflictAll = choice;
      return choice;
    });
    conflictLock = p.catch(() => 'skip');
    return p;
  }

  /** Os nomes que os outros envios desta pasta (na fila ou a enviar) vão ocupar: o servidor não os escolhe para «nome (2)». */
  function avoidFor(job) {
    const out = [];
    for (const j of jobs) {
      if (j === job || (j.state !== 'queued' && j.state !== 'active')) continue;
      const k = j.key || (j.prefix + j.name);
      if (TD.path.parent(k) === job.prefix && out.indexOf(k) < 0) out.push(k);
      if (out.length >= 1000) break;
    }
    return out;
  }
  async function sign(job, mode) {
    const body = { do: 'sign', prefix: job.prefix, name: job.name, type: job.file.type || '', size: job.size };
    if (mode === 'overwrite') { body.overwrite = true; body.on_conflict = 'overwrite'; }
    if (mode === 'rename') { body.on_conflict = 'rename'; body.avoid = avoidFor(job); }
    job.phase = 'sign'; touch(job);
    const j = await apiRetry(body, job);
    if (job.cancelled) throw cancelErr();
    if (!j.ok && j.status === 409 && (j.exists || j.conflict) && !mode) {
      const choice = await resolveConflict(job);
      if (job.cancelled) throw cancelErr();
      if (choice === 'skip') return null;
      return sign(job, choice);
    }
    return TD.must(j);
  }

  // ── um ficheiro ──
  async function run(job) {
    job.state = 'active'; job.phase = 'prep'; job.loaded = 0; job.error = '';
    touch(job);
    const sk = storeKey(job);
    try {
      let s = null;
      const saved = load(sk);
      if (saved && saved.key && saved.uploadId) {
        const j = await TD.api({ do: 'mp_parts', key: saved.key, uploadId: saved.uploadId });
        if (j.ok) {
          s = { mode: 'multipart', key: saved.key, type: saved.type, uploadId: saved.uploadId, partSize: saved.partSize, done: j.parts || {}, resumed: true };
        } else if (retryable(j)) {
          throw new Error(j.error || 'Sem ligação ao servidor.');
        } else drop(sk);   // o envio já não existe: começa do zero
      }
      if (!s) s = await sign(job);
      if (!s) { job.state = 'skipped'; job.phase = ''; touch(job); return; }
      if (s.mode === 'multipart') job.mp = { key: s.key, uploadId: s.uploadId, sk };
      if (job.cancelled) throw cancelErr();
      job.key = s.key;
      reserved.add(job.key);
      job.resumed = !!s.resumed;
      job.phase = 'up'; touch(job);
      const etag = s.mode === 'single' ? await single(job, s) : await multipart(job, s, sk);
      job.etag = normEtag(etag);
      job.loaded = job.size;
      job.state = 'done'; job.phase = '';
      touch(job);
      job.batch.keys.push(job.key);
      // a miniatura primeiro (o app pede-a logo a seguir ao upload:done)
      if (job.etag) job.batch.thumbs.push(fromFile(job.file, job.etag));
      TD.bus.emit('upload:done', { key: job.key, size: job.size, etag: job.etag });
      refreshSaved();
    } catch (e) {
      job.xhrs.forEach(x => x.abort());
      if (job.cancelled || e.cancelled) {
        job.state = 'cancelled';
        abortMp(job);   // um multipart criado pelo sign enquanto se cancelava
        drop(sk);
      } else {
        job.state = 'error';
        job.error = (e && e.message) || 'Erro inesperado.';
        if (load(sk)) job.error += ' O que já foi enviado fica guardado: «Tentar outra vez» continua daí.';
        say('Falhou o envio de «' + job.name + '».');
      }
      job.phase = '';
      touch(job);
      refreshSaved();
    }
  }

  async function single(job, s) {
    let url = s.url;
    for (let attempt = 0; ; attempt++) {
      if (job.cancelled) throw cancelErr();
      try {
        return await put(url, job.file, s.type, l => { job.loaded = l; touch(job); }, job);
      } catch (e) {
        job.loaded = 0; touch(job);
        if (job.cancelled || e.cancelled || attempt >= RETRIES) throw e;
        if (e.http && e.http < 500 && e.http !== 403 && e.http !== 408 && e.http !== 429) throw e;
        await backoff(attempt, job);
        if (e.http === 403) {
          // URL caducado: assina outra vez a mesma chave (já era nossa)
          const j = TD.must(await apiRetry({ do: 'sign', prefix: TD.path.parent(job.key), name: TD.path.base(job.key), type: job.file.type || '', size: job.size, overwrite: true }, job));
          url = j.url;
        }
      }
    }
  }

  async function multipart(job, s, sk) {
    const f = job.file, ps = s.partSize, total = Math.max(1, Math.ceil(f.size / ps));
    const partLen = n => Math.max(0, Math.min(ps, f.size - (n - 1) * ps));
    const doneParts = {};
    Object.keys(s.done || {}).forEach(n => { if (+n >= 1 && +n <= total) doneParts[n] = s.done[n]; });
    job.mp = { key: s.key, uploadId: s.uploadId, sk };   // (cancelar aborta o multipart no R2)
    const rec = { key: s.key, uploadId: s.uploadId, type: s.type, partSize: ps, parts: doneParts };
    save(sk, rec);
    let base = 0;
    Object.keys(doneParts).forEach(n => { base += partLen(+n); });
    const loaded = {};
    const show = () => { let sum = base; for (const k in loaded) sum += loaded[k]; job.loaded = sum; touch(job); };
    show();
    const todo = [];
    for (let n = 1; n <= total; n++) if (!doneParts[n]) todo.push(n);

    const urls = {};
    let signing = null;
    const urlFor = async n => {
      while (!urls[n]) {
        if (signing) { await signing; continue; }
        const batchParts = todo.filter(m => m >= n && !urls[m] && !doneParts[m]).slice(0, SIGN_BATCH);
        signing = apiRetry({ do: 'mp_sign', key: s.key, uploadId: s.uploadId, parts: batchParts }, job)
          .then(j => { TD.must(j); Object.keys(j.urls || {}).forEach(m => { urls[m] = j.urls[m]; }); })
          .finally(() => { signing = null; });
        await signing;
      }
      return urls[n];
    };
    let failure = null;
    const one = async n => {
      for (let attempt = 0; ; attempt++) {
        if (job.cancelled) throw cancelErr();
        if (failure) throw failure;
        const blob = f.slice((n - 1) * ps, (n - 1) * ps + partLen(n));
        try {
          const u = await urlFor(n);
          if (job.cancelled) throw cancelErr();
          const etag = await put(u, blob, null, l => { loaded[n] = l; show(); }, job);
          if (job.cancelled) throw cancelErr();   // cancelado entretanto: não volta a guardar a retoma
          if (!etag) {
            const e = new Error('O R2 não mostrou o ETag. Falta «ExposeHeaders: ETag» na política CORS do bucket.');
            e.fatal = true; throw e;
          }
          delete loaded[n];
          base += blob.size;
          doneParts[n] = etag;
          save(sk, rec);
          show();
          return;
        } catch (e) {
          delete loaded[n]; show();
          if (job.cancelled || e.cancelled || e.fatal || failure || attempt >= RETRIES) throw e;
          if (e.http === 403) delete urls[n];     // URL caducado: assinar outra vez
          await backoff(attempt, job);
        }
      }
    };
    let idx = 0;
    const worker = async () => {
      while (!failure && idx < todo.length) {
        const n = todo[idx++];
        try { await one(n); } catch (e) { failure = failure || e; }
      }
    };
    const ws = [];
    for (let w = 0; w < Math.min(PARTS_AT_ONCE, todo.length); w++) ws.push(worker());
    await Promise.all(ws);
    if (job.cancelled) throw cancelErr();
    if (failure) throw failure;

    job.phase = 'join'; touch(job);
    const parts = Object.keys(doneParts).map(k => ({ n: +k, etag: doneParts[k] })).sort((a, b) => a.n - b.n);
    const j = TD.must(await apiRetry({ do: 'mp_complete', key: s.key, uploadId: s.uploadId, parts }, job));
    drop(sk);
    job.mp = null;
    return j.etag || '';
  }

  // ── fila ──
  function newBatch() { return { conflictAll: null, keys: [], thumbs: [], jobs: [], t0: Date.now() }; }
  const targetOf = job => job.prefix + '\u0000' + job.name;

  function pump() {
    while (active < FILES_AT_ONCE) {
      // dois ficheiros para a mesma chave nunca ao mesmo tempo (o segundo vê o conflito), nem
      // para a chave final que outro envio já recebeu («nome (2)» de um «Manter ambos»)
      const i = queue.findIndex(j => !busyKeys.has(targetOf(j)) && !reserved.has(j.prefix + j.name));
      if (i < 0) break;
      const job = queue.splice(i, 1)[0];
      if (job.cancelled) continue;
      const t = targetOf(job);
      active++; busyKeys.add(t);
      const fin = () => {
        active--; busyKeys.delete(t);
        if (job.key) reserved.delete(job.key);
        pump();
        if (!active && !queue.length) drained();
      };
      run(job).then(fin, err => { console.error(err); fin(); });
    }
    render();
  }
  async function drained() {
    const b = batch;
    if (!b) return;
    batch = null;
    render();
    // as miniaturas que ainda estão a ser feitas (até 8 s), para o catálogo já as trazer
    if (b.thumbs.length) await Promise.race([Promise.all(b.thumbs), sleep(8000)]);
    for (let i = 0; i < b.keys.length; i += 500) {
      await TD.api({ do: 'upload_done', keys: b.keys.slice(i, i + 500) });
    }
    const ok = b.jobs.filter(j => j.state === 'done').length, bad = b.jobs.filter(j => j.state === 'error').length;
    if (ok || bad) say(bad ? ok + ' enviados, ' + bad + ' com erro.' : 'Envio concluído: ' + TD.fmt.n(ok, 'ficheiro', 'ficheiros') + '.');
    TD.bus.emit('upload:batch', { keys: b.keys.slice() });
  }

  function addFiles(files, relDirs, prefix) {
    const list = Array.from(files || []);
    const p = cleanPrefix(prefix);
    if (p == null) { TD.toast('Pasta de destino inválida.', 'err'); return 0; }
    ensureDock();
    let n = 0;
    list.forEach((f, i) => {
      if (!f || typeof f.size !== 'number' || JUNK.test(f.name)) return;
      if (!batch) batch = newBatch();
      const rel = cleanRel(relDirs && relDirs[i]);
      const job = {
        id: ++seq, file: f, name: f.name, size: f.size, base: p, rel, prefix: p + rel,
        key: '', state: 'queued', phase: '', loaded: 0, etag: '', error: '', resumed: false,
        xhrs: new Set(), cancelled: false, mp: null, batch, el: null, dirty: true, shown: '',
      };
      jobs.push(job); queue.push(job); batch.jobs.push(job);
      n++;
    });
    if (!n) { TD.toast('Não há ficheiros para enviar.', 'info'); return 0; }
    dock.hidden = false;
    place();
    pump();
    return n;
  }

  function abortMp(job) {
    if (!job.mp) return;
    TD.api({ do: 'mp_abort', key: job.mp.key, uploadId: job.mp.uploadId });
    drop(job.mp.sk);
    job.mp = null;
  }
  /** Cancela sem chamar a fila (cancel e cancelAll decidem a seguir). */
  function stop(job) {
    if (job.cancelled || (job.state !== 'queued' && job.state !== 'active')) return false;
    job.cancelled = true;
    const qi = queue.indexOf(job);
    if (qi >= 0) { queue.splice(qi, 1); job.state = 'cancelled'; }
    job.xhrs.forEach(x => x.abort());
    abortMp(job);
    touch(job);
    return true;
  }
  function cancel(job) {
    if (!stop(job)) return;
    if (!active && !queue.length) drained();
    else pump();
  }
  /** Todos de uma vez: primeiro os que esperam (para a fila não arrancar nenhum entretanto). */
  function cancelAll() {
    queue.slice().forEach(stop);
    jobs.slice().forEach(stop);
    if (!active && !queue.length) drained();
  }
  function retry(job) {
    if (job.state !== 'error' && job.state !== 'cancelled') return;
    if (!batch) batch = newBatch();
    Object.assign(job, { state: 'queued', phase: '', cancelled: false, error: '', loaded: 0, key: '', etag: '', xhrs: new Set(), mp: null, batch });
    batch.jobs.push(job);
    queue.push(job);
    touch(job);
    pump();
  }
  function busy() { return active > 0 || queue.length > 0; }

  // ── escolher ficheiros / pastas ──
  const inputs = {};
  function input(folder) {
    const id = folder ? 'tdu-pick-folder' : 'tdu-pick-files';
    if (inputs[id]) return inputs[id];
    const inp = TD.el('input', { type: 'file', multiple: true, id, class: 'tdu-input', tabindex: '-1', 'aria-hidden': 'true' });
    if (folder) { inp.setAttribute('webkitdirectory', ''); inp.setAttribute('directory', ''); }
    inp.addEventListener('change', () => {
      const list = Array.from(inp.files || []);
      const p = inp.getAttribute('data-prefix') || '';
      inp.value = '';
      if (!list.length) { if (folder) TD.toast('Essa pasta está vazia.', 'info'); return; }
      const dirs = list.map(f => {
        if (!folder) return '';
        const r = f.webkitRelativePath || '';
        const i = r.lastIndexOf('/');
        return i > 0 ? r.slice(0, i + 1) : '';
      });
      addFiles(list, dirs, p);
    });
    document.body.appendChild(inp);
    return (inputs[id] = inp);
  }
  function pick(folder, prefix) {
    const inp = input(folder);
    inp.setAttribute('data-prefix', String(prefix || ''));
    inp.click();
  }

  // ── pastas arrastadas: percorre a árvore e guarda o caminho relativo de cada ficheiro ──
  function walk(entries) {
    const files = [], dirs = [];
    const readAll = reader => new Promise((res, rej) => {
      let out = [];
      (function next() {
        reader.readEntries(b => { if (!b.length) return res(out); out = out.concat(Array.from(b)); next(); }, rej);
      })();
    });
    const visit = (en, path) => {
      if (en.isFile) {
        return new Promise(res => en.file(f => { if (!JUNK.test(f.name)) { files.push(f); dirs.push(path); } res(); }, () => res()));
      }
      if (en.isDirectory) {
        const p = path + en.name + '/';
        return readAll(en.createReader()).then(kids => kids.reduce((pr, k) => pr.then(() => visit(k, p)), Promise.resolve()));
      }
      return Promise.resolve();
    };
    return Array.from(entries || []).reduce((pr, en) => pr.then(() => visit(en, '')), Promise.resolve()).then(() => ({ files, dirs }));
  }
  /** DataTransfer (largar) → {files, dirs}; pastas incluídas. */
  function fromDataTransfer(dt) {
    const entries = [];
    const items = dt && dt.items;
    if (items && items.length && items[0].webkitGetAsEntry) {
      for (let i = 0; i < items.length; i++) {
        const en = items[i].kind === 'file' ? items[i].webkitGetAsEntry() : null;
        if (en) entries.push(en);
      }
    }
    const plain = Array.from((dt && dt.files) || []);
    if (!entries.length || entries.every(en => en.isFile)) {
      const files = plain.filter(f => !JUNK.test(f.name));
      return Promise.resolve({ files, dirs: files.map(() => '') });
    }
    return walk(entries);
  }
  async function dropFiles(dt, prefix) {
    const t = TD.toast('A ler os ficheiros…', 'info', { ms: 0 });
    try {
      const r = await fromDataTransfer(dt);
      t.close();
      if (!r.files.length) { TD.toast('Não encontrei ficheiros para enviar.', 'err'); return 0; }
      return addFiles(r.files, r.dirs, prefix);
    } catch (e) {
      t.close();
      TD.toast('Não foi possível ler a pasta arrastada.', 'err');
      return 0;
    }
  }

  // ════════════════════════════════════════════════════════
  //  Barra de envios
  // ════════════════════════════════════════════════════════
  const CHECK = '<svg class="td-ico" viewBox="0 0 20 20" width="20" height="20" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4.5 10.5l3.5 3.5 7.5-8"/></svg>';
  let renderT = 0, speed = 0, lastBytes = -1, lastTick = 0, speedT = 0, liveEl = null;
  function touch(job) { job.dirty = true; render(); }
  function render() { if (!renderT) renderT = setTimeout(paint, 120); }
  function say(text) { if (liveEl) { liveEl.textContent = ''; setTimeout(() => { liveEl.textContent = text; }, 30); } }

  function ensureDock() {
    if (ui) return ui;
    dock = dock || document.getElementById('drive-upload-dock');
    if (!dock) { dock = TD.el('div', { id: 'drive-upload-dock' }); document.body.appendChild(dock); }
    dock.classList.add('tdu-dock');
    dock.hidden = true;
    dock.innerHTML = '';
    const box = TD.el('section', { class: 'tdu', 'aria-label': 'Envios' });
    box.innerHTML =
      '<div class="tdu-head">' +
        '<button type="button" class="tdu-toggle" aria-controls="tdu-body">' +
          '<span class="tdu-state" aria-hidden="true"></span>' +
          '<span class="tdu-text"><span class="tdu-title"></span><span class="tdu-sub"></span></span>' +
          TD.icon('chevron', 'tdu-chev') +
        '</button>' +
        '<button type="button" class="tdu-x">' + TD.icon('close') + '</button>' +
      '</div>' +
      '<div class="tdu-bar" role="progressbar" aria-label="Progresso dos envios" aria-valuemin="0" aria-valuemax="100"><i></i></div>' +
      '<div class="tdu-body" id="tdu-body">' +
        '<div class="tdu-saved" hidden></div>' +
        '<ul class="tdu-list"></ul>' +
        '<div class="tdu-foot"><button type="button" class="lnk tdu-clear">Limpar concluídos</button></div>' +
      '</div>' +
      '<span class="sr" aria-live="polite"></span>';
    dock.appendChild(box);
    const $ = s => box.querySelector(s);
    ui = { box, toggle: $('.tdu-toggle'), state: $('.tdu-state'), title: $('.tdu-title'), sub: $('.tdu-sub'), x: $('.tdu-x'),
      bar: $('.tdu-bar'), fill: $('.tdu-bar i'), body: $('.tdu-body'), saved: $('.tdu-saved'), list: $('.tdu-list'), clear: $('.tdu-clear') };
    liveEl = $('.sr[aria-live]');
    setCollapsed(!!TD.store.get('upload.collapsed', false));
    ui.toggle.addEventListener('click', () => { setCollapsed(!box.classList.contains('is-min')); TD.store.set('upload.collapsed', box.classList.contains('is-min')); });
    ui.x.addEventListener('click', async () => {
      if (busy()) {
        const n = jobs.filter(j => j.state === 'queued' || j.state === 'active').length;
        const yes = await TD.dialog.confirm({ title: 'Cancelar os envios?', text: TD.fmt.n(n, 'ficheiro ainda não foi enviado.', 'ficheiros ainda não foram enviados.'), ok: 'Cancelar envios', cancel: 'Continuar', danger: true });
        if (yes) cancelAll();
        return;
      }
      clearDock(true);
    });
    ui.clear.addEventListener('click', () => clearDock(false));
    ui.list.addEventListener('click', e => {
      const b = e.target.closest('button[data-act]');
      const li = b && b.closest('li[data-id]');
      const job = li && jobs.find(j => j.id === +li.getAttribute('data-id'));
      if (!job) return;
      const act = b.getAttribute('data-act');
      if (act === 'cancel') cancel(job);
      else if (act === 'retry') retry(job);
      else if (act === 'open') openFolder(job);
    });
    ui.saved.addEventListener('click', e => {
      const b = e.target.closest('button[data-act]');
      if (!b) return;
      const s = savedUploads().find(x => x.sk === b.getAttribute('data-sk'));
      if (!s) { refreshSaved(); return; }
      if (b.getAttribute('data-act') === 'resume') pick(false, s.prefix);
      else {
        TD.api({ do: 'mp_abort', key: s.rec.key, uploadId: s.rec.uploadId });
        drop(s.sk);
        refreshSaved();
      }
    });
    window.addEventListener('resize', place);
    const app = document.getElementById('drive-app');
    if (app && 'ResizeObserver' in window) new ResizeObserver(place).observe(app);
    refreshSaved();
    paint();
    return ui;
  }
  function setCollapsed(on) {
    ui.box.classList.toggle('is-min', on);
    ui.toggle.setAttribute('aria-expanded', on ? 'false' : 'true');
    ui.toggle.setAttribute('aria-label', on ? 'Mostrar a lista de envios' : 'Esconder a lista de envios');
    place();
  }
  /** No canto inferior direito da área do Drive (sem tapar o assistente); os avisos ficam ao lado ou por cima. */
  function place() {
    const b = document.body;
    if (!dock || dock.hidden) { b.classList.remove('tdu-on', 'tdu-side', 'tdu-lift'); return; }
    const app = document.getElementById('drive-app');
    let right = 16;
    if (app && window.innerWidth >= 820) {
      const r = app.getBoundingClientRect();
      if (r.width > 380) right = Math.max(12, Math.round(window.innerWidth - r.right + 12));
    }
    dock.style.setProperty('--tdu-right', right + 'px');
    const d = dock.getBoundingClientRect();
    const side = d.left >= 360;
    b.classList.add('tdu-on');
    b.classList.toggle('tdu-side', side);
    b.classList.toggle('tdu-lift', !side);
    b.style.setProperty('--tdu-left', Math.round(d.left) + 'px');
    b.style.setProperty('--tdu-h', Math.round(d.height) + 'px');
  }
  function clearDock(all) {
    for (let i = jobs.length - 1; i >= 0; i--) {
      const j = jobs[i];
      if (j.state === 'queued' || j.state === 'active') continue;
      if (!all && j.state === 'error') continue;
      if (j.el) j.el.remove();
      jobs.splice(i, 1);
    }
    if (!jobs.length && !savedUploads().length) { dock.hidden = true; place(); }
    render();
  }
  function openFolder(job) {
    if (!job.key) return;
    if (TD.app && typeof TD.app.reveal === 'function') TD.app.reveal(job.key);
    else location.href = '/admin/drive.php?p=' + encodeURIComponent(TD.path.parent(job.key));
  }
  /** Envios multipart a meio (de antes de recarregar a página): retomar ou descartar. */
  function refreshSaved() {
    if (!ui) return;
    // os que estão na lista já têm o ficheiro (e «Tentar outra vez»): não se repetem aqui
    const running = new Set(jobs.map(storeKey));
    const list = savedUploads().filter(s => !running.has(s.sk));
    ui.saved.hidden = !list.length;
    ui.saved.textContent = '';
    if (!list.length) return;
    ui.saved.appendChild(TD.el('p', 'tdu-saved-t', list.length === 1 ? 'Há um envio por terminar. Escolha o mesmo ficheiro para continuar de onde parou.'
      : 'Há ' + list.length + ' envios por terminar. Escolha os mesmos ficheiros para continuar de onde pararam.'));
    list.forEach(s => {
      const row = TD.el('div', 'tdu-saved-r');
      row.appendChild(TD.el('span', { class: 'tdu-saved-n', title: s.prefix + s.name }, s.name + ' · ' + TD.fmt.size(s.size)));
      row.appendChild(TD.el('button', { type: 'button', class: 'lnk', 'data-act': 'resume', 'data-sk': s.sk }, 'Escolher ficheiro'));
      row.appendChild(TD.el('button', { type: 'button', class: 'lnk', 'data-act': 'discard', 'data-sk': s.sk }, 'Descartar'));
      ui.saved.appendChild(row);
    });
    if (dock.hidden) { dock.hidden = false; place(); }
    render();
  }

  function eta(sec) {
    if (!isFinite(sec) || sec <= 0) return '';
    sec = Math.ceil(sec);
    if (sec < 60) return (sec === 1 ? 'falta ' : 'faltam ') + sec + ' s';
    const m = Math.ceil(sec / 60);
    if (m < 60) return (m === 1 ? 'falta ' : 'faltam ') + m + ' min';
    const h = Math.floor(m / 60), mm = m % 60;
    return 'faltam ' + h + ' h' + (mm ? ' ' + mm + ' min' : '');
  }
  function batchBytes(b) {
    let tot = 0, got = 0;
    b.jobs.forEach(j => {
      if (j.state === 'cancelled' || j.state === 'skipped') return;
      tot += j.size;
      got += j.state === 'done' ? j.size : Math.min(j.size, j.loaded || 0);
    });
    return { tot, got };
  }
  /** Velocidade média (por segundo, suavizada), medida enquanto há envios. */
  function tickSpeed() {
    if (!batch) { speed = 0; lastBytes = -1; clearInterval(speedT); speedT = 0; return; }
    const now = Date.now(), { got } = batchBytes(batch);
    if (lastBytes >= 0 && now > lastTick) {
      const inst = Math.max(0, got - lastBytes) * 1000 / (now - lastTick);
      speed = speed ? speed * 0.7 + inst * 0.3 : inst;
    }
    lastBytes = got; lastTick = now;
  }

  function statusText(job) {
    switch (job.state) {
      case 'queued': return 'Em espera';
      case 'done': return 'Enviado';
      case 'cancelled': return 'Cancelado';
      case 'skipped': return 'Saltado (já existia)';
      case 'error': return job.error;
      default:
        if (job.phase === 'conflict') return 'Já existe — escolha o que fazer';
        if (job.phase === 'prep' || job.phase === 'sign') return 'A preparar…';
        if (job.phase === 'join') return 'A juntar as partes…';
        return TD.fmt.size(job.loaded) + ' de ' + TD.fmt.size(job.size) + (job.resumed ? ' · retomado' : '');
    }
  }
  function paintRow(job) {
    let li = job.el;
    if (!li) {
      li = job.el = TD.el('li', { class: 'tdu-item', 'data-id': job.id });
      li.innerHTML = '<span class="tdu-ico">' + TD.icon(TD.kind(job.name).id) + '</span>' +
        '<div class="tdu-main"><div class="tdu-name"></div><div class="tdu-st"><span class="tdu-msg"></span><span class="tdu-dest"></span></div>' +
        '<div class="tdu-pbar"><i></i></div></div><div class="tdu-acts"></div>';
      ui.list.appendChild(li);
    }
    const nm = li.querySelector('.tdu-name');
    nm.textContent = shownName(job);
    nm.title = (job.key || job.prefix + job.name);
    li.querySelector('.tdu-msg').textContent = statusText(job);
    li.querySelector('.tdu-dest').textContent = 'em «' + folderLabel(job.base) + '»';
    const pct = job.state === 'done' ? 100 : job.size ? Math.min(100, (job.loaded || 0) * 100 / job.size) : 0;
    li.querySelector('.tdu-pbar i').style.width = pct.toFixed(1) + '%';
    li.className = 'tdu-item is-' + job.state + (job.phase === 'conflict' ? ' is-wait' : '');
    // botões: só se mudou o estado
    const shown = job.state === 'active' || job.state === 'queued' ? 'run' : job.state;
    if (job.shown !== shown) {
      job.shown = shown;
      const acts = li.querySelector('.tdu-acts');
      acts.textContent = '';
      const nm2 = '«' + job.name + '»';
      const btn = (act, icon, label) => {
        const b = TD.el('button', { type: 'button', class: 'tdu-btn', 'data-act': act, 'aria-label': label, title: label });
        b.innerHTML = TD.icon(icon);
        acts.appendChild(b);
      };
      if (shown === 'run') btn('cancel', 'close', 'Cancelar ' + nm2);
      else if (shown === 'error' || shown === 'cancelled') btn('retry', 'restore', 'Tentar outra vez ' + nm2);
      else if (shown === 'done') btn('open', 'folder', 'Abrir a pasta de ' + nm2);
    }
    job.dirty = false;
  }
  function paint() {
    clearTimeout(renderT);
    renderT = 0;
    if (!ui) return;
    jobs.forEach(j => { if (j.dirty) paintRow(j); });
    const live = jobs.filter(j => j.state === 'queued' || j.state === 'active');
    const isBusy = busy();
    const nErr = jobs.filter(j => j.state === 'error').length;
    const nDone = jobs.filter(j => j.state === 'done').length;
    let title, sub = '', pct = 100;
    if (isBusy && batch) {
      if (!speedT) { lastBytes = -1; speed = 0; tickSpeed(); speedT = setInterval(() => { tickSpeed(); render(); }, 1000); }
      const { tot, got } = batchBytes(batch);
      const files = batch.jobs.filter(j => j.state !== 'cancelled' && j.state !== 'skipped');
      const ok = files.filter(j => j.state === 'done').length;
      pct = tot > 0 ? got * 100 / tot : (files.length ? ok * 100 / files.length : 0);
      title = 'A enviar ' + TD.fmt.n(Math.max(files.length, live.length), 'ficheiro', 'ficheiros');
      const waiting = live.some(j => j.phase === 'conflict');
      const parts = files.length > 1 ? [ok + ' de ' + files.length, Math.floor(pct) + '%'] : [Math.floor(pct) + '%'];
      if (waiting) parts.push('à espera de uma decisão');
      else if (speed > 1024 && Date.now() - batch.t0 > 1500) {
        parts.push(TD.fmt.size(Math.round(speed)) + '/s');
        const left = eta((tot - got) / speed);
        if (left) parts.push(left);
      }
      sub = parts.join(' · ');
    } else {
      if (speedT) { clearInterval(speedT); speedT = 0; }
      if (nErr) title = (nDone ? TD.fmt.n(nDone, 'enviado', 'enviados') + ' · ' : '') + nErr + ' com erro';
      else if (nDone) title = TD.fmt.n(nDone, 'ficheiro enviado', 'ficheiros enviados');
      else if (jobs.length) title = 'Nenhum ficheiro enviado';
      else title = 'Envios por terminar';
      const bytes = jobs.filter(j => j.state === 'done').reduce((a, j) => a + j.size, 0);
      sub = nErr ? 'Veja os erros na lista' : nDone ? 'Concluído · ' + TD.fmt.size(bytes) : '';
    }
    ui.title.textContent = title;
    ui.sub.textContent = sub;
    ui.sub.hidden = !sub;
    ui.box.classList.toggle('is-busy', isBusy);
    ui.box.classList.toggle('has-err', !isBusy && nErr > 0);
    ui.box.classList.toggle('is-ok', !isBusy && !nErr && nDone > 0);
    ui.fill.style.width = Math.max(0, Math.min(100, pct)).toFixed(1) + '%';
    ui.bar.setAttribute('aria-valuenow', String(Math.floor(pct)));
    ui.bar.hidden = !isBusy;
    const xl = isBusy ? 'Cancelar todos os envios' : 'Fechar a barra de envios';
    ui.x.setAttribute('aria-label', xl); ui.x.title = xl;
    ui.clear.hidden = isBusy || !jobs.some(j => j.state === 'done' || j.state === 'cancelled' || j.state === 'skipped');
    ui.clear.parentNode.hidden = ui.clear.hidden;
    const st = isBusy ? 'busy' : nErr ? 'err' : nDone ? 'ok' : 'idle';
    if (ui.stateNow !== st) {   // só quando muda (senão a animação recomeça a cada desenho)
      ui.stateNow = st;
      ui.state.innerHTML = st === 'busy' ? '<i class="tdu-spin"></i>' : st === 'err' ? TD.icon('info') : st === 'ok' ? CHECK : TD.icon('upload');
    }
    if (dock && !dock.hidden) place();
  }

  window.addEventListener('beforeunload', e => {
    if (busy()) { e.preventDefault(); e.returnValue = ''; }
  });

  TD.upload = {
    init(opt) {
      if (opt && opt.dock) dock = opt.dock;
      ensureDock();
    },
    /** files: File[]; relDirs: 'Sub/Pasta/' por ficheiro (ou ''); prefix: a pasta de destino. → nº em fila. */
    addFiles,
    pickFiles: prefix => pick(false, prefix),
    pickFolder: prefix => pick(true, prefix),
    /** Largar do computador (ficheiros e pastas) para a pasta prefix. */
    drop: dropFiles,
    fromDataTransfer,
    walk,
    busy,
    /** Para testes e diagnóstico: o estado de cada envio na barra. */
    list: () => jobs.map(j => ({ id: j.id, name: j.name, rel: j.rel, key: j.key, state: j.state, phase: j.phase, loaded: j.loaded, size: j.size, etag: j.etag, error: j.error, resumed: j.resumed })),
    cancelAll,
  };
})();
