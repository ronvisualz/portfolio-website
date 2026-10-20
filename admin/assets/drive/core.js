/* topper. admin — Drive: a base partilhada (window.TD).
 *
 * Carregado antes de todos os outros módulos do Drive (upload, zip,
 * preview, app, chat). Só utilitários: pedidos à API, formatos, tipos
 * de ficheiro, ícones, avisos, diálogos e um pequeno bus de eventos.
 * Nada aqui conhece o estado da página — isso é do app.js. */
(function () {
  'use strict';
  const TD = window.TD = window.TD || {};
  const root = document.getElementById('drive');
  TD.root = root;
  TD.cfg = {
    api: (root && root.getAttribute('data-api')) || '/admin/drive_api.php',
    aiApi: (root && root.getAttribute('data-ai-api')) || '/admin/ai_api.php',
    csrf: (root && root.getAttribute('data-csrf')) || '',
  };

  // ── pedidos ─────────────────────────────────────────────
  /** POST JSON com o token CSRF. Nunca rejeita: em falha devolve {ok:false, error}. */
  TD.api = function (body, url) {
    return fetch(url || TD.cfg.api, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': TD.cfg.csrf },
      body: JSON.stringify(body),
    }).then(r => r.json().catch(() => ({
      ok: false,
      error: r.status === 401 ? 'A sessão terminou. Entre outra vez.' : 'Resposta inesperada do servidor (HTTP ' + r.status + ').',
    })).then(j => { j.status = r.status; return j; }),
    () => ({ ok: false, error: 'Sem ligação ao servidor.' }));
  };
  TD.ai = body => TD.api(body, TD.cfg.aiApi);
  /** Lança se a resposta não for ok (para usar dentro de async/await). */
  TD.must = function (j) {
    if (!j || !j.ok) { const e = new Error((j && j.error) || 'Erro.'); e.resp = j; throw e; }
    return j;
  };
  /**
   * Repete um pedido «até estar feito» (move, copy, trash, restore, purge, purge_all).
   * Enquanto o servidor responder {done:false, next:{…}}, envia `next` tal como veio.
   * onStep(j) recebe cada resposta (para mostrar progresso). Lança no primeiro erro.
   */
  TD.until = async function (body, onStep, maxRounds) {
    let j = null;
    for (let i = 0; i < (maxRounds || 2000); i++) {
      j = TD.must(await TD.api(body));
      if (onStep) onStep(j);
      if (j.done !== false) return j;
      if (!j.next) throw new Error('Resposta do servidor sem continuação.');
      body = j.next;
    }
    throw new Error('A operação demorou demasiado. Atualize a página e confirme o resultado.');
  };
  TD.sleep = ms => new Promise(r => setTimeout(r, ms));

  // ── DOM ─────────────────────────────────────────────────
  /** el('button', 'btn sm', 'Texto') ou el('a', {class:'lnk', href:'#'}, 'x'). */
  TD.el = function (tag, cls, text) {
    const e = document.createElement(tag);
    if (cls && typeof cls === 'object') {
      for (const k in cls) {
        if (cls[k] == null || cls[k] === false) continue;
        if (k === 'class') e.className = cls[k];
        else if (k === 'text') e.textContent = cls[k];
        else if (k === 'html') e.innerHTML = cls[k];
        else if (k.slice(0, 2) === 'on' && typeof cls[k] === 'function') e.addEventListener(k.slice(2), cls[k]);
        else e.setAttribute(k, cls[k] === true ? '' : cls[k]);
      }
    } else if (cls) e.className = cls;
    if (text != null) e.textContent = text;
    return e;
  };
  const ESC = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
  TD.esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ESC[c]);

  // ── formatos (pt-PT) ────────────────────────────────────
  const dtf = new Intl.DateTimeFormat('pt-PT', { timeZone: 'Europe/Lisbon', day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
  const df = new Intl.DateTimeFormat('pt-PT', { timeZone: 'Europe/Lisbon', day: '2-digit', month: '2-digit', year: 'numeric' });
  TD.fmt = {
    /** 1536 → «1,5 KB» */
    size(b) {
      if (b == null || b < 0) return '—';
      const u = ['B', 'KB', 'MB', 'GB', 'TB']; let i = 0, n = b;
      while (n >= 1024 && i < 4) { n /= 1024; i++; }
      return (i ? n.toFixed(n < 10 ? 1 : 0).replace('.', ',') : String(b)) + ' ' + u[i];
    },
    /** unix → «19/10/2026, 18:32» */
    date(ts) { return ts ? dtf.format(new Date(ts * 1000)).replace(',', '') : '—'; },
    day(ts) { return ts ? df.format(new Date(ts * 1000)) : '—'; },
    /** unix → «há 5 min», «ontem», ou a data */
    rel(ts) {
      if (!ts) return '—';
      const s = Math.round(Date.now() / 1000 - ts);
      if (s < 60) return 'agora mesmo';
      if (s < 3600) return 'há ' + Math.floor(s / 60) + ' min';
      if (s < 86400) return 'há ' + Math.floor(s / 3600) + ' h';
      if (s < 172800) return 'ontem';
      if (s < 7 * 86400) return 'há ' + Math.floor(s / 86400) + ' dias';
      return TD.fmt.day(ts);
    },
    /** (3, 'ficheiro', 'ficheiros') → «3 ficheiros» */
    n(n, one, many) { return n + ' ' + (n === 1 ? one : many); },
    eur(v) { return new Intl.NumberFormat('pt-PT', { style: 'currency', currency: 'EUR' }).format(v || 0); },
  };

  // ── caminhos (chaves do R2) ─────────────────────────────
  TD.path = {
    isFolder: k => k === '' || k.slice(-1) === '/',
    /** «a/b/c.pdf» → «c.pdf»; «a/b/» → «b» */
    base(k) { const t = k.replace(/\/$/, ''); return t.slice(t.lastIndexOf('/') + 1); },
    /** «a/b/c.pdf» → «a/b/»; «a/b/» → «a/»; «a» → «» */
    parent(k) { const t = k.replace(/\/$/, ''); const i = t.lastIndexOf('/'); return i < 0 ? '' : t.slice(0, i + 1); },
    ext(k) { const b = TD.path.base(k); const i = b.lastIndexOf('.'); return i > 0 ? b.slice(i + 1).toLowerCase() : ''; },
    /** «a/b/» → [['','Drive'],['a/','a'],['a/b/','b']] */
    crumbs(p) {
      const out = [['', 'Drive']]; let acc = '';
      (p ? p.replace(/\/$/, '').split('/') : []).forEach(s => { acc += s + '/'; out.push([acc, s]); });
      return out;
    },
    /** dentro de (ou igual a) uma pasta */
    within: (k, prefix) => prefix === '' || k.indexOf(prefix) === 0,
  };

  // ── tipos de ficheiro ───────────────────────────────────
  const KINDS = {
    video: ['mp4', 'm4v', 'mov', 'webm', 'mkv', 'avi', 'mxf', 'prores', 'r3d', 'braw'],
    image: ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif', 'heic', 'tif', 'tiff', 'bmp', 'exr', 'dpx', 'tga'],
    audio: ['mp3', 'wav', 'm4a', 'aac', 'flac', 'ogg', 'aif', 'aiff'],
    pdf: ['pdf'],
    text: ['txt', 'md', 'csv', 'tsv', 'json', 'srt', 'vtt', 'log', 'xml', 'yml', 'yaml', 'ini', 'html', 'css', 'js', 'py', 'php', 'rtf'],
    doc: ['doc', 'docx', 'odt', 'pages'],
    sheet: ['xls', 'xlsx', 'ods', 'numbers'],
    slides: ['ppt', 'pptx', 'odp', 'key'],
    zip: ['zip', 'rar', '7z', 'tar', 'gz', 'tgz'],
    design: ['psd', 'ai', 'indd', 'aep', 'prproj', 'drp', 'blend', 'c4d', 'ma', 'mb', 'hip', 'nk', 'fbx', 'obj', 'abc', 'usd', 'usdz', 'glb', 'gltf', 'sketch', 'fig', 'xd', 'eps'],
  };
  const LABEL = { folder: 'Pasta', video: 'Vídeo', image: 'Imagem', audio: 'Áudio', pdf: 'PDF', text: 'Texto', doc: 'Documento',
    sheet: 'Folha de cálculo', slides: 'Apresentação', zip: 'Ficheiro comprimido', design: 'Projeto / design', other: 'Ficheiro' };
  // o que o browser consegue mostrar sozinho
  const PREVIEW = {
    video: ['mp4', 'm4v', 'mov', 'webm'], image: ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif', 'bmp'],
    audio: ['mp3', 'wav', 'm4a', 'aac', 'flac', 'ogg'], pdf: ['pdf'],
    text: ['txt', 'md', 'csv', 'tsv', 'json', 'srt', 'vtt', 'log', 'xml', 'yml', 'yaml', 'ini', 'html', 'css', 'js', 'py', 'php'],
  };
  /** → {id, label, preview} — id: folder|video|image|audio|pdf|text|doc|sheet|slides|zip|design|other */
  TD.kind = function (key) {
    if (TD.path.isFolder(key)) return { id: 'folder', label: LABEL.folder, preview: '' };
    const x = TD.path.ext(key);
    let id = 'other';
    for (const k in KINDS) if (KINDS[k].indexOf(x) >= 0) { id = k; break; }
    let pv = '';
    for (const k in PREVIEW) if (PREVIEW[k].indexOf(x) >= 0) { pv = k; break; }
    return { id, label: LABEL[id], preview: pv };
  };
  TD.KIND_LABELS = LABEL;
  /** O tipo de conteúdo (para envios e pré-visualização), pela extensão. */
  TD.mime = function (key) {
    const x = TD.path.ext(key);
    return ({ mp4: 'video/mp4', m4v: 'video/mp4', mov: 'video/quicktime', webm: 'video/webm', jpg: 'image/jpeg', jpeg: 'image/jpeg',
      png: 'image/png', gif: 'image/gif', webp: 'image/webp', svg: 'image/svg+xml', avif: 'image/avif', pdf: 'application/pdf',
      mp3: 'audio/mpeg', wav: 'audio/wav', m4a: 'audio/mp4', ogg: 'audio/ogg', flac: 'audio/flac', txt: 'text/plain', csv: 'text/csv',
      md: 'text/markdown', json: 'application/json', zip: 'application/zip' })[x] || 'application/octet-stream';
  };

  // ── ícones (SVG em linha, 20×20, herdam a cor) ──────────
  const P = {
    folder: '<path d="M2.5 5.5a1 1 0 0 1 1-1h4l1.6 1.8h7.4a1 1 0 0 1 1 1v8.2a1 1 0 0 1-1 1h-13a1 1 0 0 1-1-1z"/>',
    other: '<path d="M5 2.5h6.5L15.5 6.5v11H5z"/><path d="M11.5 2.5v4h4"/>',
    video: '<rect x="2.5" y="4.5" width="15" height="11" rx="1.5"/><path d="M8.5 7.5v5l4.2-2.5z"/>',
    image: '<rect x="2.5" y="3.5" width="15" height="13" rx="1.5"/><circle cx="7" cy="8" r="1.4"/><path d="M3 15l4.5-4.5 3 3 2.5-2.5 4.5 4.5"/>',
    audio: '<path d="M8 14.5V4.5l8-1.5v10"/><circle cx="6" cy="14.5" r="2"/><circle cx="14" cy="13" r="2"/>',
    pdf: '<path d="M5 2.5h6.5L15.5 6.5v11H5z"/><path d="M11.5 2.5v4h4"/><path d="M7.5 12.5h5M7.5 15h3"/>',
    text: '<path d="M5 2.5h6.5L15.5 6.5v11H5z"/><path d="M7.5 9.5h5M7.5 12h5M7.5 14.5h3"/>',
    doc: '<path d="M5 2.5h6.5L15.5 6.5v11H5z"/><path d="M7.5 9.5h5M7.5 12h5M7.5 14.5h5"/>',
    sheet: '<rect x="3" y="3" width="14" height="14" rx="1.5"/><path d="M3 8h14M3 12.5h14M8 3v14"/>',
    slides: '<rect x="2.5" y="4" width="15" height="10" rx="1.5"/><path d="M10 14v3M7 17h6"/>',
    zip: '<path d="M5 2.5h10v15H5z"/><path d="M10 2.5v2M10 6v2M10 9.5v2M8.8 12.5h2.4v2.5H8.8z"/>',
    design: '<path d="M10 2.5l7.5 7.5-7.5 7.5L2.5 10z"/><circle cx="10" cy="10" r="2.2"/>',
    star: '<path d="M10 2.8l2.2 4.6 5 .7-3.6 3.5.9 5-4.5-2.4-4.5 2.4.9-5L2.8 8.1l5-.7z"/>',
    trash: '<path d="M3.5 5.5h13M8 5.5V3.5h4v2M5.5 5.5l.8 11h7.4l.8-11"/>',
    clock: '<circle cx="10" cy="10" r="7.5"/><path d="M10 5.5V10l3 2"/>',
    link: '<path d="M8.5 11.5a3.2 3.2 0 0 0 4.5 0l2.5-2.5a3.2 3.2 0 0 0-4.5-4.5l-1 1"/><path d="M11.5 8.5a3.2 3.2 0 0 0-4.5 0L4.5 11a3.2 3.2 0 0 0 4.5 4.5l1-1"/>',
    upload: '<path d="M10 13.5V3.5M6 7.5l4-4 4 4M3.5 13v3.5h13V13"/>',
    download: '<path d="M10 3.5v10M6 9.5l4 4 4-4M3.5 13v3.5h13V13"/>',
    search: '<circle cx="8.5" cy="8.5" r="5.5"/><path d="M12.5 12.5l4.5 4.5"/>',
    grid: '<rect x="3" y="3" width="6" height="6" rx="1"/><rect x="11" y="3" width="6" height="6" rx="1"/><rect x="3" y="11" width="6" height="6" rx="1"/><rect x="11" y="11" width="6" height="6" rx="1"/>',
    list: '<path d="M3 5h14M3 10h14M3 15h14"/>',
    info: '<circle cx="10" cy="10" r="7.5"/><path d="M10 9v5M10 6.2v.1"/>',
    close: '<path d="M5 5l10 10M15 5L5 15"/>',
    chevron: '<path d="M7.5 4.5L13 10l-5.5 5.5"/>',
    plus: '<path d="M10 4v12M4 10h12"/>',
    chat: '<path d="M3 4.5h14v9H8l-4 3v-3H3z"/>',
    more: '<circle cx="5" cy="10" r="1.2"/><circle cx="10" cy="10" r="1.2"/><circle cx="15" cy="10" r="1.2"/>',
    restore: '<path d="M4 10a6 6 0 1 0 1.8-4.3M4 3.5v3h3"/>',
    copy: '<rect x="6.5" y="6.5" width="10" height="10" rx="1.5"/><path d="M13.5 6.5v-3h-10v10h3"/>',
    move: '<path d="M2.5 5.5a1 1 0 0 1 1-1h4l1.6 1.8h7.4a1 1 0 0 1 1 1v8.2a1 1 0 0 1-1 1h-13a1 1 0 0 1-1-1z"/><path d="M7.5 11h5M10.5 9l2 2-2 2"/>',
    edit: '<path d="M4 16l1-4 8-8 3 3-8 8z"/>',
  };
  /** TD.icon('folder') → '<svg …>' (aria-hidden). Tipos desconhecidos usam o ícone genérico. */
  TD.icon = function (name, cls) {
    return '<svg class="td-ico' + (cls ? ' ' + cls : '') + '" viewBox="0 0 20 20" width="20" height="20" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">'
      + (P[name] || P.other) + '</svg>';
  };

  // ── eventos ─────────────────────────────────────────────
  const subs = {};
  TD.bus = {
    on(ev, fn) { (subs[ev] = subs[ev] || []).push(fn); return () => TD.bus.off(ev, fn); },
    off(ev, fn) { subs[ev] = (subs[ev] || []).filter(f => f !== fn); },
    emit(ev, data) { (subs[ev] || []).slice().forEach(fn => { try { fn(data); } catch (e) { console.error(e); } }); },
  };

  // ── preferências locais (por browser) ───────────────────
  TD.store = {
    get(k, def) { try { const v = localStorage.getItem('td.' + k); return v == null ? def : JSON.parse(v); } catch (e) { return def; } },
    set(k, v) { try { localStorage.setItem('td.' + k, JSON.stringify(v)); } catch (e) { /* sem armazenamento */ } },
  };

  TD.copyText = async function (text) {
    try { await navigator.clipboard.writeText(text); return true; } catch (e) {
      const t = TD.el('textarea'); t.value = text; t.style.position = 'fixed'; t.style.opacity = '0';
      document.body.appendChild(t); t.select();
      let ok = false; try { ok = document.execCommand('copy'); } catch (e2) { ok = false; }
      t.remove(); return ok;
    }
  };

  // ── avisos (canto inferior) ─────────────────────────────
  let toastBox = null;
  /** TD.toast('Movido.', 'ok'|'err'|'info', {action:{label:'Anular', fn}, ms}) → {close(), set(text)} */
  TD.toast = function (msg, kind, opt) {
    opt = opt || {};
    if (!toastBox) { toastBox = TD.el('div', { class: 'td-toasts', role: 'status', 'aria-live': 'polite' }); document.body.appendChild(toastBox); }
    const t = TD.el('div', 'td-toast ' + (kind || 'ok'));
    const txt = TD.el('span', 'td-toast-text', msg);
    t.appendChild(txt);
    if (opt.action) {
      const b = TD.el('button', { class: 'td-toast-act', type: 'button' }, opt.action.label);
      b.addEventListener('click', () => { close(); opt.action.fn(); });
      t.appendChild(b);
    }
    const x = TD.el('button', { class: 'td-toast-x', type: 'button', 'aria-label': 'Fechar' });
    x.innerHTML = TD.icon('close');
    x.addEventListener('click', () => close());
    t.appendChild(x);
    toastBox.appendChild(t);
    let timer = null;
    function close() { clearTimeout(timer); t.remove(); }
    const ms = opt.ms === 0 ? 0 : (opt.ms || (kind === 'err' ? 9000 : 4500));
    if (ms) timer = setTimeout(close, ms);
    return { close, set(text) { txt.textContent = text; } };
  };

  // ── diálogos ────────────────────────────────────────────
  let openDlg = null;
  /** Base: monta a caixa, devolve uma Promise resolvida por done(valor). Esc/fora → null. */
  function dialog(build) {
    if (openDlg) openDlg(null);
    return new Promise(resolve => {
      const prev = document.activeElement;
      const back = TD.el('div', 'td-dialog-back');
      const box = TD.el('div', { class: 'td-dialog', role: 'dialog', 'aria-modal': 'true' });
      back.appendChild(box);
      function done(v) {
        if (!back.isConnected) return;
        back.remove(); openDlg = null;
        document.removeEventListener('keydown', onKey, true);
        if (prev && prev.focus) prev.focus();
        resolve(v);
      }
      function onKey(e) {
        if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); done(null); }
        else if (e.key === 'Tab') {   // o foco não sai da caixa
          const f = box.querySelectorAll('button, input, select, textarea, a[href]');
          if (!f.length) return;
          const first = f[0], last = f[f.length - 1];
          if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
          else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
        }
        e.stopPropagation();
      }
      back.addEventListener('mousedown', e => { if (e.target === back) done(null); });
      document.addEventListener('keydown', onKey, true);
      build(box, done);
      document.body.appendChild(back);
      openDlg = done;
      const f = box.querySelector('[autofocus], input, select, textarea, .td-dialog-actions .btn:not(.ghost)');
      if (f) f.focus();
    });
  }
  function head(box, title, text) {
    box.appendChild(TD.el('h2', 'td-dialog-title', title));
    if (text) box.appendChild(TD.el('p', 'td-dialog-text', text));
  }
  function actions(box, list) {
    const a = TD.el('div', 'td-dialog-actions');
    list.forEach(b => a.appendChild(b));
    box.appendChild(a);
    return a;
  }
  TD.dialog = {
    open: dialog,
    /** → true/false */
    confirm(o) {
      return dialog((box, done) => {
        head(box, o.title, o.text);
        const no = TD.el('button', { class: 'btn ghost', type: 'button' }, o.cancel || 'Cancelar');
        const yes = TD.el('button', { class: 'btn' + (o.danger ? ' danger' : ''), type: 'button' }, o.ok || 'Confirmar');
        no.onclick = () => done(false); yes.onclick = () => done(true);
        actions(box, [no, yes]);
      }).then(v => v === true);
    },
    /** → texto ou null. o.select = [início, fim] seleciona parte do valor (ex.: o nome sem a extensão). */
    prompt(o) {
      return dialog((box, done) => {
        head(box, o.title, o.text);
        const f = TD.el('form', 'td-dialog-form');
        const id = 'tdp' + Math.random().toString(36).slice(2, 8);
        if (o.label) f.appendChild(TD.el('label', { for: id }, o.label));
        const inp = TD.el('input', { id, type: 'text', maxlength: o.maxlength || 200, autocomplete: 'off', spellcheck: 'false' });
        inp.value = o.value || '';
        f.appendChild(inp);
        const no = TD.el('button', { class: 'btn ghost', type: 'button' }, o.cancel || 'Cancelar');
        const yes = TD.el('button', { class: 'btn', type: 'submit' }, o.ok || 'Guardar');
        no.onclick = () => done(null);
        f.addEventListener('submit', e => { e.preventDefault(); const v = inp.value.trim(); if (v) done(v); else inp.focus(); });
        box.appendChild(f);
        f.appendChild(actions(box, [no, yes]));
        setTimeout(() => { inp.focus(); if (o.select) inp.setSelectionRange(o.select[0], o.select[1]); else inp.select(); }, 0);
      });
    },
    /** o.options = [{value, label, hint?}] → valor escolhido ou null */
    choose(o) {
      return dialog((box, done) => {
        head(box, o.title, o.text);
        const l = TD.el('div', 'td-dialog-choices');
        o.options.forEach((op, i) => {
          const b = TD.el('button', { class: 'td-choice' + (op.danger ? ' danger' : ''), type: 'button', autofocus: i === 0 });
          b.appendChild(TD.el('b', null, op.label));
          if (op.hint) b.appendChild(TD.el('span', 'muted', op.hint));
          b.onclick = () => done(op.value);
          l.appendChild(b);
        });
        box.appendChild(l);
        const no = TD.el('button', { class: 'btn ghost', type: 'button' }, o.cancel || 'Cancelar');
        no.onclick = () => done(null);
        actions(box, [no]);
      });
    },
  };
})();
