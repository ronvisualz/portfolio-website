/* topper. admin — Drive: a aplicação (árvore, lista/grelha, seleção, operações).
 *
 * Desenha tudo dentro de #drive-app a partir do catálogo (drive_api.php › catalog):
 * árvore de pastas, atalhos, medidor de espaço, lista/grelha por blocos, pesquisa,
 * seleção múltipla, arrastar, menus, Lixo, partilhas e detalhes.
 * Expõe TD.app (navegação e consulta) e TD.ops (operações com conflitos, progresso
 * e «Anular»), que o assistente (chat.js) também usa. Envios, miniaturas, ZIP e
 * pré-visualização são de outros módulos (TD.upload, TD.thumbs, TD.zip, TD.preview):
 * usam-se se existirem; sem eles a página continua a funcionar. */
(function () {
  'use strict';
  const TD = window.TD;
  const root = document.getElementById('drive');
  const host = document.getElementById('drive-app');
  if (!TD || !root || !host) return;

  const P = TD.path;
  const esc = TD.esc;
  const BLOCK = 150;                 // linhas por bloco de desenho (a janela tem 2 a 3 blocos)
  const BASE_ROOTS = ['Empresa/', 'Leads/', 'Projetos/', 'Marketing/', 'Biblioteca criativa/', 'Showreel e Portfolio/', 'Arquivo/'];
  const ROOT_ERR = 'As pastas base não podem ser movidas.';
  const MAC = /Mac|iPhone|iPad/.test(navigator.platform || '');
  const MODK = MAC ? '⌘' : 'Ctrl+';
  const coll = new Intl.Collator('pt', { numeric: true, sensitivity: 'base' });
  const fold = s => String(s).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
  const now = () => Math.floor(Date.now() / 1000);
  const hiddenKey = k => k.indexOf('.topper/') === 0;

  // ── ícones que faltam na base (mesmo desenho: 20×20, traço 1,5) ──
  const XI = {
    back: '<path d="M12.5 4.5L7 10l5.5 5.5"/>',
    fwd: '<path d="M7.5 4.5L13 10l-5.5 5.5"/>',
    up: '<path d="M10 16V4.5M5 9.5l5-5 5 5"/>',
    menu: '<path d="M3 5.5h14M3 10h14M3 14.5h14"/>',
    filter: '<path d="M3 4.5h14l-5.5 6.5v4.5l-3 1.5V11z"/>',
    sort: '<path d="M6.5 4v12M3.5 13l3 3 3-3M13.5 16V4M10.5 7l3-3 3 3"/>',
    check: '<path d="M4.5 10.5l3.5 3.5 7.5-8"/>',
    cut: '<circle cx="6" cy="14.5" r="2.2"/><circle cx="14" cy="14.5" r="2.2"/><path d="M7.4 12.8L14.5 3.5M12.6 12.8L5.5 3.5"/>',
    paste: '<rect x="4.5" y="3.5" width="11" height="14" rx="1.5"/><path d="M7.5 3.5V2.5h5v1M7.5 9h5M7.5 12h5"/>',
    eye: '<path d="M2 10s3-5.5 8-5.5S18 10 18 10s-3 5.5-8 5.5S2 10 2 10z"/><circle cx="10" cy="10" r="2.3"/>',
    open: '<path d="M11 3.5h5.5V9M16.5 3.5L9 11M14 12.5v4H3.5V6H8"/>',
    drive: '<rect x="2.5" y="11" width="15" height="5.5" rx="1.5"/><path d="M4.2 11l2-6.5h7.6l2 6.5M13.8 13.75h.1"/>',
    keys: '<rect x="2" y="5" width="16" height="10" rx="1.5"/><path d="M5 8h.1M8 8h.1M11 8h.1M14 8h.1M6 12h8"/>',
    newfolder: '<path d="M2.5 5.5a1 1 0 0 1 1-1h4l1.6 1.8h7.4a1 1 0 0 1 1 1v8.2a1 1 0 0 1-1 1h-13a1 1 0 0 1-1-1z"/><path d="M10 9v5M7.5 11.5h5"/>',
    upfolder: '<path d="M2.5 5.5a1 1 0 0 1 1-1h4l1.6 1.8h7.4a1 1 0 0 1 1 1v8.2a1 1 0 0 1-1 1h-13a1 1 0 0 1-1-1z"/><path d="M10 14.5V9M7.6 11.2L10 8.8l2.4 2.4"/>',
    refresh: '<path d="M16 10a6 6 0 1 1-1.8-4.3M16 3.5v3h-3"/>',
    warn: '<path d="M10 3l8 14H2z"/><path d="M10 8.5v4M10 14.8v.1"/>',
    caret: '<path d="M6 8l4 4 4-4"/>',
    play: '<path d="M7 5v10l8-5z"/>',
    sparkle: '<path d="M10 3v4M10 13v4M3 10h4M13 10h4M5.5 5.5l2 2M12.5 12.5l2 2M14.5 5.5l-2 2M7.5 12.5l-2 2"/>',
  };
  function ic(name, cls) {
    if (!XI[name]) return TD.icon(name, cls);
    return '<svg class="td-ico' + (cls ? ' ' + cls : '') + '" viewBox="0 0 20 20" width="20" height="20" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">' + XI[name] + '</svg>';
  }

  // ── estado ──────────────────────────────────────────────
  const sortPref = TD.store.get('drive.sort', null);
  const S = {
    loaded: false, error: '', lastLoad: 0,
    objects: [], byKey: new Map(), folders: new Map(), flat: [],
    roots: BASE_ROOTS.slice(), thumbs: new Set(), thumbUrl: new Map(),
    favs: new Set(), recents: [], trashInfo: { count: 0, size: 0 }, shares: [], index: null,
    view: 'folder', prefix: '', q: '', scope: 'all', before: null,
    sort: sortPref && ['name', 'date', 'size', 'kind'].indexOf(sortPref.by) >= 0 ? { by: sortPref.by, dir: sortPref.dir === -1 ? -1 : 1 } : { by: 'name', dir: 1 },
    layout: TD.store.get('drive.layout', 'list') === 'grid' ? 'grid' : 'list',
    filter: 'all',
    items: [], keyIndex: new Map(), nFolders: 0, els: new Map(), tabEl: null,
    sel: new Set(), anchor: -1, focus: -1,
    clip: null, drag: null, renaming: null, pendingRender: false, busy: 0, needLoad: false,
    // Entre 1200 e 1679 px os detalhes ocupam o lugar do assistente: aí não se reabrem sozinhos
    // (o assistente é a coluna da direita por omissão em cada visita).
    details: !!TD.store.get('drive.details', false) && !sharedCol(),
    expanded: new Set(TD.store.get('drive.tree', [])), treeRoot: TD.store.get('drive.treeRoot', true) !== false,
    trash: null, trashBusy: false, trashed: new Map(),
    hits: null, hitsBusy: false, hitsSeq: 0, hitsT: 0, hitsAll: false,
    hist: 0, histMax: 0, lastChild: new Map(),
    undo: null, zipping: 0, pointer: 'mouse',
  };
  let readyResolve;
  const ready = new Promise(r => { readyResolve = r; });

  // ── o esqueleto ─────────────────────────────────────────
  host.removeAttribute('aria-busy');
  host.innerHTML =
    '<aside class="dr-side" aria-label="Pastas e atalhos">' +
      '<div class="dr-side-top">' +
        '<button type="button" class="btn dr-new" aria-haspopup="menu">' + ic('plus') + '<span>Novo</span></button>' +
        '<button type="button" class="dr-iconbtn dr-side-x" aria-label="Fechar o painel">' + ic('close') + '</button>' +
      '</div>' +
      '<div class="dr-side-scroll">' +
        '<div class="dr-tree" role="tree" aria-label="Pastas"></div>' +
      '</div>' +
      '<div class="dr-sc"></div>' +
      '<div class="dr-quota" aria-label="Espaço usado"></div>' +
    '</aside>' +
    '<section class="dr-main" aria-label="Ficheiros">' +
      '<div class="dr-bar">' +
        '<button type="button" class="dr-iconbtn dr-menu-btn" aria-label="Pastas e atalhos" aria-expanded="false">' + ic('menu') + '</button>' +
        '<form class="dr-search" role="search" autocomplete="off">' + ic('search', 'dr-search-ico') +
          '<input type="search" class="dr-q" placeholder="Pesquisar no Drive" aria-label="Pesquisar no Drive" spellcheck="false" enterkeyhint="search" maxlength="200">' +
          '<span class="dr-q-spin" hidden aria-hidden="true"></span>' +
          '<button type="button" class="dr-scope" hidden></button>' +
          '<button type="button" class="dr-q-x" aria-label="Limpar a pesquisa" hidden>' + ic('close') + '</button>' +
          '<kbd class="dr-q-kbd" aria-hidden="true">/</kbd>' +
        '</form>' +
        '<div class="dr-tools">' +
          '<div class="dr-seg" role="group" aria-label="Vista">' +
            '<button type="button" data-layout="list" aria-label="Lista" title="Lista">' + ic('list') + '</button>' +
            '<button type="button" data-layout="grid" aria-label="Grelha" title="Grelha">' + ic('grid') + '</button>' +
          '</div>' +
          '<button type="button" class="dr-iconbtn dr-info-btn" aria-label="Detalhes" title="Detalhes (i)" aria-pressed="false">' + ic('info') + '</button>' +
          '<button type="button" class="btn ghost sm dr-chat-btn" aria-expanded="false" aria-controls="drive-chat">' + ic('chat') + '<span>Assistente</span></button>' +
        '</div>' +
      '</div>' +
      '<div class="dr-loc">' +
        '<div class="dr-hist">' +
          '<button type="button" class="dr-iconbtn" data-hist="back" aria-label="Para trás" title="Para trás">' + ic('back') + '</button>' +
          '<button type="button" class="dr-iconbtn" data-hist="fwd" aria-label="Para a frente" title="Para a frente">' + ic('fwd') + '</button>' +
          '<button type="button" class="dr-iconbtn dr-up" data-hist="up" aria-label="Pasta acima" title="Pasta acima (Backspace)">' + ic('up') + '</button>' +
        '</div>' +
        '<nav class="dr-crumbs" aria-label="Localização"></nav>' +
      '</div>' +
      '<div class="dr-strip"></div>' +
      '<div class="dr-scroll">' +
        '<section class="dr-quick" hidden aria-label="Abertos recentemente"></section>' +
        '<div class="dr-hits" hidden></div>' +
        '<div class="dr-head" hidden></div>' +
        '<div class="dr-items" role="listbox" aria-multiselectable="true" aria-label="Ficheiros e pastas"></div>' +
        '<div class="dr-band" hidden></div>' +
      '</div>' +
      '<div class="dr-dropnote" hidden role="status"></div>' +
    '</section>' +
    '<aside class="dr-details" aria-label="Detalhes" hidden></aside>' +
    '<div class="dr-scrim" hidden></div>';

  const $ = sel => host.querySelector(sel);
  const sideEl = $('.dr-side'), treeEl = $('.dr-tree'), scEl = $('.dr-sc'), quotaEl = $('.dr-quota');
  const mainEl = $('.dr-main'), qEl = $('.dr-q'), qX = $('.dr-q-x'), qSpin = $('.dr-q-spin'), scopeBtn = $('.dr-scope');
  const crumbsEl = $('.dr-crumbs'), stripEl = $('.dr-strip'), scrollEl = $('.dr-scroll'), hitsEl = $('.dr-hits'), quickEl = $('.dr-quick');
  const headEl = $('.dr-head'), itemsEl = $('.dr-items'), bandEl = $('.dr-band');
  const noteEl = $('.dr-dropnote'), detEl = $('.dr-details'), scrimEl = $('.dr-scrim');
  const infoBtn = $('.dr-info-btn'), chatBtn = $('.dr-chat-btn'), menuBtn = $('.dr-menu-btn'), newBtn = $('.dr-new');
  const histBack = host.querySelector('[data-hist="back"]'), histFwd = host.querySelector('[data-hist="fwd"]'), histUp = host.querySelector('[data-hist="up"]');
  let gridF = null, gridA = null;

  // ── utilitários ─────────────────────────────────────────
  const isRoot = k => S.roots.indexOf(k) >= 0;
  /** «Projetos/X/» → «Projetos › X»; '' → «Drive» */
  function pretty(prefix) { return prefix ? prefix.replace(/\/$/, '').split('/').join(' › ') : 'Drive'; }
  /** Limpa um nome como o r2_clean_name do servidor. */
  function cleanName(n) {
    n = String(n || '').replace(/[/\\]/g, '-').replace(/[\x00-\x1f\x7f]/g, '').replace(/\s+/g, ' ').trim();
    return n === '.' || n === '..' ? '' : n;
  }
  function byteLen(s) { return new TextEncoder().encode(s).length; }
  function kindLabel(it) {
    if (it.folder) return 'Pasta';
    const x = P.ext(it.k || it.name);
    const l = TD.KIND_LABELS[it.kind] || 'Ficheiro';
    return x && (it.kind === 'video' || it.kind === 'image' || it.kind === 'audio' || it.kind === 'other' || it.kind === 'design' || it.kind === 'zip') ? l + ' · ' + x.toUpperCase() : l;
  }
  function group(it) {
    if (it.folder) return 'folder';
    const k = it.kind;
    if (k === 'doc' || k === 'sheet' || k === 'slides' || k === 'text') return 'docs';
    if (k === 'video' || k === 'image' || k === 'pdf' || k === 'audio') return k;
    return 'other';
  }
  const FILTERS = [['all', 'Todos os tipos'], ['folder', 'Pastas'], ['video', 'Vídeo'], ['image', 'Imagem'], ['pdf', 'PDF'], ['docs', 'Documentos'], ['audio', 'Áudio'], ['other', 'Outros']];
  const SORTS = [['name', 'Nome'], ['date', 'Data de alteração'], ['size', 'Tamanho'], ['kind', 'Tipo']];
  const n = TD.fmt.n;
  function itemsWord(c) { return n(c, 'item', 'itens'); }
  /** Data futura em texto: «daqui a 5 h», «amanhã, 14:00», «até 20/10/2026». */
  function untilText(ts) {
    const s = ts - now();
    if (s <= 0) return 'expirado';
    if (s < 3600) return 'daqui a ' + Math.max(1, Math.round(s / 60)) + ' min';
    if (s < 86400) return 'daqui a ' + Math.round(s / 3600) + ' h';
    if (s < 7 * 86400) return 'daqui a ' + Math.round(s / 86400) + ' dias';
    return 'até ' + TD.fmt.day(ts);
  }
  function hoursText(h) { return h % 24 === 0 && h >= 48 ? (h / 24) + ' dias' : h + ' h'; }
  /** Prazo de um link: «Sem prazo» (pastas) ou «Expira daqui a 5 h». */
  function shareUntil(s) { return (s.protected ? 'Com password · ' : '') + (s.forever ? 'Sem prazo' : 'Expira ' + untilText(s.exp)); }
  function scrollNearest(box, el, topPad) {
    if (!box || !el) return;
    const b = box.getBoundingClientRect(), r = el.getBoundingClientRect();
    const top = b.top + (topPad || 0);
    if (r.top < top) box.scrollTop -= (top - r.top) + 6;
    else if (r.bottom > b.bottom) box.scrollTop += (r.bottom - b.bottom) + 6;
  }
  function headH() { return headEl.hidden ? 0 : headEl.offsetHeight; }
  function narrow() { return window.matchMedia('(max-width: 819px)').matches; }
  function chatSlide() { return window.matchMedia('(max-width: 1199px)').matches; }
  /** 1200–1679 px: o assistente e os detalhes partilham a coluna da direita. */
  function sharedCol() { return window.matchMedia('(min-width: 1200px) and (max-width: 1679px)').matches; }
  /** Mesma forma em minúsculas e sem acentos, com o mesmo comprimento (para destacar termos). */
  function foldSame(s) {
    let o = '';
    for (const ch of s) { const x = fold(ch); o += x.length === ch.length ? x : ch; }
    return o;
  }
  function highlight(text, terms) {
    text = String(text || '');
    if (!terms || !terms.length) return esc(text);
    const f = foldSame(text), rs = [];
    terms.forEach(t => { if (!t) return; let i = f.indexOf(t); while (i >= 0) { rs.push([i, i + t.length]); i = f.indexOf(t, i + t.length); } });
    if (!rs.length) return esc(text);
    rs.sort((a, b) => a[0] - b[0]);
    let out = '', pos = 0;
    for (const [a, b] of rs) {
      if (b <= pos) continue;
      const s = Math.max(a, pos);
      out += esc(text.slice(pos, s)) + '<mark>' + esc(text.slice(s, b)) + '</mark>';
      pos = b;
    }
    return out + esc(text.slice(pos));
  }
  function terms() { return fold(S.q).split(/\s+/).filter(Boolean); }

  // ── índice derivado do catálogo (só se refaz quando o catálogo muda) ──
  function buildIndex() {
    const folders = new Map(), byKey = new Map();
    const ensure = p => {
      let f = folders.get(p);
      if (f) return f;
      f = { k: p, name: p === '' ? 'Drive' : P.base(p), kids: [], files: [], size: 0, nfiles: 0, m: 0, marker: false, item: null };
      folders.set(p, f);
      if (p !== '') ensure(P.parent(p)).kids.push(p);
      return f;
    };
    ensure('');
    S.roots.forEach(ensure);
    for (const o of S.objects) {
      if (o.k.slice(-1) === '/') { const f = ensure(o.k); f.marker = true; if (o.m > f.m) f.m = o.m; continue; }
      const it = { k: o.k, s: o.s || 0, m: o.m || 0, e: o.e || '', name: P.base(o.k), folder: false, kind: TD.kind(o.k).id };
      byKey.set(o.k, it);
      const f = ensure(P.parent(o.k));
      f.files.push(it);
      for (let p = f.k; ; p = P.parent(p)) {
        const a = folders.get(p);
        a.size += it.s; a.nfiles++; if (it.m > a.m) a.m = it.m;
        if (p === '') break;
      }
    }
    const flat = [];
    folders.forEach(f => {
      f.item = { k: f.k, name: f.name, folder: true, s: f.size, m: f.m, e: '', kind: 'folder', n: f.kids.length + f.files.length, nfiles: f.nfiles };
      if (f.k !== '') flat.push(f.item);
    });
    byKey.forEach(it => flat.push(it));
    flat.forEach(it => { it.norm = fold(it.name); });
    S.folders = folders; S.byKey = byKey; S.flat = flat;
  }
  function itemOf(k) {
    if (P.isFolder(k)) { const f = S.folders.get(k); return f && k !== '' ? f.item : null; }
    return S.byKey.get(k) || null;
  }

  // ── a lista da vista atual ──────────────────────────────
  function cmp(a, b) {
    if (a.folder !== b.folder) return a.folder ? -1 : 1;
    const by = S.sort.by;
    let r = by === 'date' ? (a.m || 0) - (b.m || 0) : by === 'size' ? (a.s || 0) - (b.s || 0) : by === 'kind' ? coll.compare(kindLabel(a), kindLabel(b)) : 0;
    if (r === 0) r = coll.compare(a.name, b.name);
    if (r === 0) r = a.k < b.k ? -1 : a.k > b.k ? 1 : 0;
    return r * S.sort.dir;
  }
  function nameSearch() {
    const ts = terms();
    if (!ts.length) return [];
    const pre = S.scope === 'here' ? S.prefix : '';
    const out = [];
    for (const it of S.flat) {
      if (pre && (it.k.indexOf(pre) !== 0 || it.k === pre)) continue;
      let ok = true;
      for (let t = 0; t < ts.length; t++) if (it.norm.indexOf(ts[t]) < 0) { ok = false; break; }
      if (ok) out.push(it);
    }
    return out;
  }
  function computeItems() {
    let list = [];
    const v = S.view;
    if (S.loaded) {
      if (v === 'folder') {
        const f = S.folders.get(S.prefix);
        if (f) list = f.kids.map(k => S.folders.get(k).item).concat(f.files);
      } else if (v === 'search') list = nameSearch();
      else if (v === 'recent') {
        const seen = new Set();
        S.recents.forEach(r => { if (!r || seen.has(r.k)) return; const it = itemOf(r.k); if (it) { seen.add(r.k); list.push(Object.assign({}, it, { t: r.t || 0 })); } });
      } else if (v === 'fav') S.favs.forEach(k => { const it = itemOf(k); if (it) list.push(it); });
      else if (v === 'trash') list = S.trash ? S.trash.items.slice() : [];
      else if (v === 'shared') list = shareItems();
    }
    if (v !== 'trash' && v !== 'shared' && S.filter !== 'all') list = list.filter(it => group(it) === S.filter);
    if (v === 'recent') list.sort((a, b) => (a.folder !== b.folder ? (a.folder ? -1 : 1) : (b.t - a.t)));
    else if (v === 'trash') list.sort((a, b) => b.deleted - a.deleted || coll.compare(a.name, b.name));
    else if (v === 'shared') list.sort((a, b) => (a.share.forever ? 1e15 : a.exp) - (b.share.forever ? 1e15 : b.exp));
    else if (v === 'folder' && S.prefix === '') {
      list.sort((a, b) => {
        const ia = S.roots.indexOf(a.k), ib = S.roots.indexOf(b.k);
        if (ia >= 0 || ib >= 0) return (ia < 0 ? 99 : ia) - (ib < 0 ? 99 : ib);
        return cmp(a, b);
      });
    } else list.sort(cmp);
    S.items = list;
    S.keyIndex = new Map();
    let nf = 0;
    list.forEach((it, i) => { S.keyIndex.set(it.k, i); if (it.folder && nf === i) nf++; });
    S.nFolders = nf;
  }
  function shareItems() {
    return S.shares.map(sh => {
      const folder = !!sh.folder || P.isFolder(sh.k);
      const it = itemOf(sh.k);
      return { k: 'share:' + sh.id, share: sh, orig: sh.k, name: P.base(sh.k), folder, kind: folder ? 'folder' : TD.kind(sh.k).id, s: it ? it.s : -1, m: it ? it.m : 0, e: it ? it.e : '', exp: sh.exp || 0, loc: P.parent(sh.k) };
    });
  }
  function trashItem(t) {
    const folder = !!t.folder || P.isFolder(t.k);
    return { k: 'lixo:' + t.id, id: t.id, orig: t.k, name: P.base(t.k), folder, kind: folder ? 'folder' : TD.kind(t.k).id, s: t.s || 0, files: t.files || 0, deleted: t.deleted || 0, expires: t.expires || 0, left: t.days_left, loc: P.parent(t.k), e: '' };
  }
  /** Os itens selecionados, pela ordem da lista (ou o que tem o foco). */
  function selItems() {
    const out = [];
    if (S.sel.size) S.items.forEach(it => { if (S.sel.has(it.k)) out.push(it); });
    else if (S.items[S.focus]) out.push(S.items[S.focus]);
    return out;
  }
  function realKey(it) { return it.orig || it.k; }
  const flatView = () => S.view === 'search' || S.view === 'recent' || S.view === 'fav';
  const fileView = () => S.view === 'folder' || flatView();
  const useGrid = () => S.layout === 'grid' && fileView();
  function canDrag(it) { return fileView() && !isRoot(it.k); }

  // ── desenho: linhas e cartões ───────────────────────────
  function thumbUrl(e) {
    if (!e) return '';
    let u = S.thumbUrl.get(e);
    if (!u && TD.thumbs && typeof TD.thumbs.cached === 'function') { u = TD.thumbs.cached(e); if (u) S.thumbUrl.set(e, u); }
    return u || '';
  }
  function thumbImg(it) {
    const u = thumbUrl(it.e);
    return u ? '<img src="' + esc(u) + '" alt="" loading="lazy" decoding="async" draggable="false">' : '';
  }
  function starBtn(it) {
    const on = S.favs.has(it.k);
    return '<button type="button" class="dr-star' + (on ? ' on' : '') + '" data-act="fav" tabindex="-1" aria-pressed="' + on + '" aria-label="' + (on ? 'Remover dos favoritos' : 'Adicionar aos favoritos') + '" title="' + (on ? 'Remover dos favoritos' : 'Adicionar aos favoritos') + '">' + ic('star') + '</button>';
  }
  function sizeTxt(it) { return it.s >= 0 ? TD.fmt.size(it.s) : '—'; }
  function dateTs(it) { return S.view === 'recent' ? it.t : it.m; }
  function nameHtml(it) { return S.view === 'search' ? highlight(it.name, terms()) : esc(it.name); }
  function metaTxt(it) {
    if (S.view === 'trash') return leftTxt(it, true) + ' · apagado ' + TD.fmt.rel(it.deleted) + ' · ' + sizeTxt(it);
    if (S.view === 'shared') return shareUntil(it.share) + ' · ' + n(it.share.downloads || 0, 'descarga', 'descargas');
    const parts = [];
    if (flatView()) parts.push(pretty(P.parent(it.k)));
    if (it.folder) parts.push(itemsWord(it.n)); else parts.push(sizeTxt(it));
    const d = dateTs(it); if (d) parts.push(TD.fmt.rel(d));
    return parts.join(' · ');
  }
  /** «Apaga em 28 dias» / «Apaga hoje» (Lixo). */
  function leftTxt(it, cap) {
    const t = it.left == null ? '' : it.left <= 0 ? 'apaga hoje' : 'apaga em ' + n(it.left, 'dia', 'dias');
    return cap && t ? t[0].toUpperCase() + t.slice(1) : t;
  }
  function locHtml(it) {
    // (a parte «· apagado há…» só aparece quando a coluna «Apagado» não cabe — drive.css)
    if (S.view === 'trash') return '<span class="dr-where">Estava em ' + esc(pretty(it.loc)) + '<span class="dr-where-x"> · apagado ' + esc(TD.fmt.rel(it.deleted)) + '</span></span>';
    // nos links, «· n descargas» faz o mesmo quando a coluna «Descargas» não cabe
    if (S.view === 'shared') return '<span class="dr-where">' + esc(pretty(it.loc)) + '<span class="dr-where-x"> · ' + esc(n(it.share.downloads || 0, 'descarga', 'descargas')) + '</span></span>';
    if (flatView()) return '<span class="dr-where">' + esc(pretty(P.parent(it.k))) + '</span>';
    return '';
  }
  function rowCls(base, it, i) {
    let c = base;
    if (it.folder) c += ' is-folder';
    if (S.sel.has(it.k)) c += ' is-sel';
    if (S.clip && S.clip.mode === 'cut' && S.clip.set.has(it.k)) c += ' is-cut';
    if (isRoot(it.k)) c += ' is-base';
    return c;
  }
  function optAttrs(it, i) {
    return ' role="option" id="dri' + i + '" data-i="' + i + '" tabindex="-1" aria-selected="' + S.sel.has(it.k) + '"' + (canDrag(it) ? ' draggable="true"' : '');
  }
  function rowHtml(it, i) {
    const v = S.view;
    const kind = it.folder ? 'folder' : it.kind;
    const th = !it.folder && it.e && (kind === 'image' || kind === 'video') ? ' data-e="' + esc(it.e) + '"' : '';
    let h = '<div class="' + rowCls('dr-row', it, i) + '"' + optAttrs(it, i) + '>' +
      '<span class="dr-c dr-c-check"><span class="dr-check" aria-hidden="true">' + ic('check') + '</span></span>' +
      '<span class="dr-c dr-c-icon k-' + kind + '"' + th + '>' + (thumbImg(it) || ic(kind)) + '</span>' +
      '<span class="dr-c dr-c-name"><span class="dr-name" title="' + esc(it.name) + '">' + nameHtml(it) + '</span>' +
        (isRoot(it.k) && v === 'folder' ? '<span class="dr-tag">Pasta base</span>' : '') + locHtml(it) +
        '<span class="dr-meta">' + esc(metaTxt(it)) + '</span></span>';
    if (v === 'trash') {
      const left = it.left == null ? '' : it.left <= 0 ? 'hoje' : n(it.left, 'dia', 'dias');
      h += '<span class="dr-c dr-c-a" title="' + esc(TD.fmt.date(it.deleted)) + '">' + esc(TD.fmt.rel(it.deleted)) + '</span>' +
        '<span class="dr-c dr-c-b' + (it.left != null && it.left <= 3 ? ' is-soon' : '') + '">' + esc(left) + '</span>' +
        '<span class="dr-c dr-c-size">' + sizeTxt(it) + (it.folder && it.files ? '<small>' + esc(n(it.files, 'ficheiro', 'ficheiros')) + '</small>' : '') + '</span>' +
        '<span class="dr-c dr-c-act"><button type="button" class="dr-mini-btn" data-act="restore" tabindex="-1" aria-label="Restaurar" title="Restaurar">' + ic('restore') + '<span>Restaurar</span></button>' +
        '<button type="button" class="dr-iconbtn danger" data-act="purge" tabindex="-1" aria-label="Apagar de vez" title="Apagar de vez">' + ic('trash') + '</button></span>';
    } else if (v === 'shared') {
      const sh = it.share;
      h += '<span class="dr-c dr-c-a" title="' + esc(sh.forever ? 'Ativo até ser revogado' : TD.fmt.date(it.exp)) + '">' + esc(sh.forever ? 'Sem prazo' : untilText(it.exp)) + '</span>' +
        '<span class="dr-c dr-c-b">' + esc(n(sh.downloads || 0, 'descarga', 'descargas')) + '</span>' +
        '<span class="dr-c dr-c-act"><button type="button" class="dr-mini-btn" data-act="copylink" tabindex="-1">' + ic('link') + '<span>Copiar link</span></button>' +
        '<button type="button" class="dr-iconbtn danger" data-act="revoke" tabindex="-1" aria-label="Revogar o link" title="Revogar o link">' + ic('close') + '</button></span>';
    } else {
      const d = dateTs(it);
      h += '<span class="dr-c dr-c-kind">' + esc(it.folder ? 'Pasta · ' + itemsWord(it.n) : kindLabel(it)) + '</span>' +
        '<span class="dr-c dr-c-size">' + sizeTxt(it) + '</span>' +
        '<span class="dr-c dr-c-date" title="' + esc(d ? TD.fmt.date(d) : '') + '">' + esc(d ? TD.fmt.rel(d) : '—') + '</span>' +
        '<span class="dr-c dr-c-star">' + starBtn(it) + '</span>';
    }
    return h + '</div>';
  }
  function cardHtml(it, i) {
    const check = '<span class="dr-check" aria-hidden="true">' + ic('check') + '</span>';
    if (it.folder) {
      return '<div class="' + rowCls('dr-card', it, i) + '"' + optAttrs(it, i) + '>' + check +
        '<span class="dr-fico">' + ic('folder') + '</span>' +
        '<span class="dr-card-t"><span class="dr-name" title="' + esc(it.name) + '">' + nameHtml(it) + '</span>' +
        '<span class="dr-card-m">' + esc(itemsWord(it.n) + (it.s ? ' · ' + TD.fmt.size(it.s) : '')) + '</span></span>' + starBtn(it) + '</div>';
    }
    const kind = it.kind;
    const th = it.e && (kind === 'image' || kind === 'video') ? ' data-e="' + esc(it.e) + '"' : '';
    const img = thumbImg(it);
    const x = P.ext(it.k);
    return '<div class="' + rowCls('dr-card', it, i) + '"' + optAttrs(it, i) + '>' + check +
      '<span class="dr-thumb k-' + kind + (img ? ' has-img' : '') + '"' + th + '>' + (img || (ic(kind, 'dr-thumb-ico') + (x ? '<span class="dr-ext">' + esc(x.toUpperCase()) + '</span>' : ''))) +
        (kind === 'video' ? '<span class="dr-play">' + ic('play') + '</span>' : '') + '</span>' +
      '<span class="dr-card-b"><span class="dr-card-ico k-' + kind + '">' + ic(kind) + '</span>' +
        '<span class="dr-card-t"><span class="dr-name" title="' + esc(it.name) + '">' + nameHtml(it) + '</span>' +
        '<span class="dr-card-m">' + esc(metaTxt(it)) + '</span></span>' + starBtn(it) + '</span></div>';
  }

  function renderHead() {
    if (useGrid() || !S.loaded || !S.items.length) { headEl.hidden = true; headEl.innerHTML = ''; return; }
    headEl.hidden = false;
    const v = S.view;
    const all = S.items.length > 0 && S.sel.size === S.items.length;
    const some = S.sel.size > 0 && !all;
    const sortable = v === 'folder' || v === 'search' || v === 'fav';
    const hb = (key, label, cls) => {
      if (!sortable || !key) return '<span class="dr-c ' + cls + '">' + label + '</span>';
      const on = S.sort.by === key;
      return '<span class="dr-c ' + cls + '"><button type="button" class="dr-hsort' + (on ? ' on' : '') + '" data-sort="' + key + '" aria-label="Ordenar por ' + label.toLowerCase() + '"' + (on ? ' aria-sort="' + (S.sort.dir > 0 ? 'ascending' : 'descending') + '"' : '') + '>' + label +
        (on ? '<span class="dr-arrow' + (S.sort.dir < 0 ? ' down' : '') + '">' + ic('up') + '</span>' : '') + '</button></span>';
    };
    let h = '<div class="dr-hrow v-' + v + '">' +
      '<span class="dr-c dr-c-check">' + (v === 'shared' ? '' : '<button type="button" class="dr-check' + (all ? ' on' : some ? ' some' : '') + '" data-hcheck aria-label="' + (all ? 'Limpar a seleção' : 'Selecionar tudo') + '" title="' + (all ? 'Limpar a seleção' : 'Selecionar tudo') + '">' + ic('check') + '</button>') + '</span>' +
      '<span class="dr-c dr-c-icon"></span>' + hb('name', 'Nome', 'dr-c-name');
    if (v === 'trash') h += '<span class="dr-c dr-c-a">Apagado</span><span class="dr-c dr-c-b">Apaga em</span><span class="dr-c dr-c-size">Tamanho</span><span class="dr-c dr-c-act"></span>';
    else if (v === 'shared') h += '<span class="dr-c dr-c-a">Expira</span><span class="dr-c dr-c-b">Descargas</span><span class="dr-c dr-c-act"></span>';
    else h += hb('kind', 'Tipo', 'dr-c-kind') + hb('size', 'Tamanho', 'dr-c-size') + (v === 'recent' ? '<span class="dr-c dr-c-date">Aberto</span>' : hb('date', 'Alterado', 'dr-c-date')) + '<span class="dr-c dr-c-star"></span>';
    headEl.innerHTML = h + '</div>';
  }

  const thumbIO = 'IntersectionObserver' in window ? new IntersectionObserver(onThumbVisible, { root: scrollEl, rootMargin: '300px' }) : null;

  // ── janela de desenho ───────────────────────────────────
  // Só estão no DOM os itens à volta do que se vê (blocos de ~150 linhas, até ~450 de cada vez).
  // Linhas e cartões têm altura fixa, por isso a posição de qualquer item calcula-se: a barra de
  // rolar mostra o tamanho real (o resto é margem), o retângulo de seleção apanha itens fora do
  // DOM e uma pasta com 5 000 ficheiros custa o mesmo que uma com 300.
  // Uma «secção» é a lista inteira, ou (na grelha) as pastas e os ficheiros, cada uma na sua grelha.
  let secs = [], winRaf = 0;
  const rowMemo = { list: 46, f: 68, a: 222 };
  function newSec(el, s0, n, grid, key) {
    return { el, s0, n, grid, key, cols: 1, rowH: rowMemo[key], colW: 0, gapX: 0, gapY: 0, w0: 0, w1: 0, measured: false };
  }
  function secOf(i) { for (const s of secs) if (i >= s.s0 && i < s.s0 + s.n) return s; return null; }
  function secTop(s) { return itemsEl.offsetTop + (s.grid ? s.el.offsetTop : 0); }
  function secLeft(s) { return itemsEl.offsetLeft + (s.grid ? s.el.offsetLeft : 0); }
  function gridColsOf(el) {
    const t = getComputedStyle(el).gridTemplateColumns;
    return Math.max(1, t && t !== 'none' ? t.split(' ').filter(Boolean).length : 1);
  }
  /** Colunas, espaços e altura de uma linha (pela primeira desenhada). true se algo mudou. */
  function measureSec(s) {
    let changed = false;
    if (s.grid) {
      const cs = getComputedStyle(s.el);
      const cols = gridColsOf(s.el);
      if (cols !== s.cols) { s.cols = cols; changed = true; }
      s.gapX = parseFloat(cs.columnGap) || 0; s.gapY = parseFloat(cs.rowGap) || 0;
    }
    const el = S.els.get(s.s0 + s.w0);
    if (el && el.offsetHeight) {
      const h = el.offsetHeight + s.gapY;
      if (Math.abs(h - s.rowH) > 0.5) { s.rowH = h; changed = true; }
      s.colW = el.offsetWidth;
      s.measured = true;
      rowMemo[s.key] = h;
    }
    return changed;
  }
  function itemsHtml(s, a, b) {
    let h = '';
    for (let j = a; j < b; j++) { const i = s.s0 + j; h += s.grid ? cardHtml(S.items[i], i) : rowHtml(S.items[i], i); }
    return h;
  }
  function padSec(s) {
    const rows = Math.ceil(s.n / s.cols);
    s.el.style.paddingTop = Math.floor(s.w0 / s.cols) * s.rowH + 'px';
    s.el.style.paddingBottom = Math.max(0, rows - Math.ceil(s.w1 / s.cols)) * s.rowH + 'px';
  }
  /** Põe no DOM os itens [a, b) da secção (índices relativos), reaproveitando os que já lá estão. */
  function setWindow(s, a, b, added) {
    if (a === s.w0 && b === s.w1) return;
    const drop = j => { const e = S.els.get(s.s0 + j); if (e) e.remove(); S.els.delete(s.s0 + j); };
    if (b <= s.w0 || a >= s.w1 || s.w0 === s.w1) {
      for (let j = s.w0; j < s.w1; j++) S.els.delete(s.s0 + j);
      s.el.innerHTML = itemsHtml(s, a, b);
      const ch = s.el.children;
      for (let j = a; j < b; j++) S.els.set(s.s0 + j, ch[j - a]);
      if (b > a) added.push([s.s0 + a, s.s0 + b]);
    } else {
      for (let j = s.w0; j < Math.min(a, s.w1); j++) drop(j);
      for (let j = Math.max(b, s.w0); j < s.w1; j++) drop(j);
      if (a < s.w0) {
        s.el.insertAdjacentHTML('afterbegin', itemsHtml(s, a, s.w0));
        const ch = s.el.children;
        for (let j = a; j < s.w0; j++) S.els.set(s.s0 + j, ch[j - a]);
        added.push([s.s0 + a, s.s0 + s.w0]);
      }
      if (b > s.w1) {
        const from = Math.max(a, s.w1);
        s.el.insertAdjacentHTML('beforeend', itemsHtml(s, from, b));
        const ch = s.el.children, len = ch.length;
        for (let j = from; j < b; j++) S.els.set(s.s0 + j, ch[len - (b - j)]);
        added.push([s.s0 + from, s.s0 + b]);
      }
    }
    s.w0 = a; s.w1 = b;
    padSec(s);
  }
  /** A janela que cobre o que está à vista, mais um bloco antes e outro depois (alinhada às linhas). */
  function wantWindow(s) {
    const rows = Math.ceil(s.n / s.cols);
    const B = Math.max(2, Math.ceil(BLOCK / s.cols));
    const top = secTop(s), st = scrollEl.scrollTop, vh = scrollEl.clientHeight || 700;
    const v0 = Math.min(rows, Math.max(0, Math.floor((st - top) / s.rowH)));
    const v1 = Math.min(rows, Math.max(0, Math.ceil((st + vh - top) / s.rowH)));
    const r0 = Math.max(0, (Math.floor(v0 / B) - 1) * B);
    const r1 = Math.min(rows, (Math.ceil(v1 / B) + 1) * B);
    return [r0 * s.cols, Math.min(s.n, r1 * s.cols)];
  }
  function updateWindow() {
    if (!secs.length) return;
    const added = [];
    for (let pass = 0; pass < 3; pass++) {
      let again = false;
      for (const s of secs) {
        const w = wantWindow(s);
        setWindow(s, w[0], w[1], added);
        if (!s.measured && s.w1 > s.w0 && measureSec(s)) { padSec(s); again = true; }
      }
      if (!again) break;
    }
    if (S.renaming && !S.els.has(S.renaming.i)) S.renaming.cancel();
    if (!S.tabEl || !S.tabEl.isConnected) { const f = S.els.has(S.focus) ? S.focus : firstRendered(); if (f >= 0) setRoving(f); }
    added.forEach(r => thumbsFor(r[0], r[1]));
  }
  function firstRendered() { for (const s of secs) if (s.w1 > s.w0) return s.s0 + s.w0; return -1; }
  /** Rola o mínimo para o item ficar à vista (pelas contas, sem precisar do elemento). */
  function scrollToIndex(i) {
    const s = secOf(i);
    if (!s) return;
    const row = Math.floor((i - s.s0) / s.cols);
    const y = secTop(s) + row * s.rowH, h = s.rowH - s.gapY, hh = headH();
    const st = scrollEl.scrollTop, vh = scrollEl.clientHeight;
    if (y < st + hh) scrollEl.scrollTop = Math.max(0, y - hh - 6);
    else if (y + h > st + vh) scrollEl.scrollTop = y + h - vh + 6;
    if (scrollEl.scrollTop !== st || !S.els.has(i)) updateWindow();
  }
  scrollEl.addEventListener('scroll', () => {
    if (!winRaf) winRaf = requestAnimationFrame(() => { winRaf = 0; updateWindow(); });
  }, { passive: true });
  if ('ResizeObserver' in window) {
    new ResizeObserver(() => { if (!secs.length) return; secs.forEach(x => { x.measured = false; }); updateWindow(); }).observe(scrollEl);
  }

  function renderList(opt) {
    opt = opt || {};
    if (S.renaming) S.renaming.cancel();   // a linha vai ser refeita: o campo de renomear não pode ficar órfão
    S.els = new Map(); S.tabEl = null; secs = [];
    if (thumbIO) thumbIO.disconnect();
    itemsEl.textContent = '';
    itemsEl.style.paddingTop = itemsEl.style.paddingBottom = '';
    gridF = gridA = null;
    const grid = useGrid();
    host.classList.toggle('is-grid', grid);
    itemsEl.className = 'dr-items v-' + S.view + (grid ? ' is-grid' : ' is-list') + (S.sel.size ? ' has-sel' : '');
    renderHead();
    renderQuick();
    if (!S.loaded) {
      itemsEl.setAttribute('aria-busy', S.error ? 'false' : 'true');
      itemsEl.innerHTML = S.error ? errorHtml() : skeletonHtml();
      return;
    }
    itemsEl.removeAttribute('aria-busy');
    if (S.view === 'trash' && !S.trash) { itemsEl.innerHTML = skeletonHtml(); return; }
    if (!S.items.length) { itemsEl.innerHTML = emptyHtml(); return; }
    if (grid) {
      let h = '';
      if (S.nFolders) h += '<div class="dr-sec" role="presentation">Pastas</div><div class="dr-grid dr-grid-f" role="group" aria-label="Pastas"></div>';
      if (S.items.length > S.nFolders) h += '<div class="dr-sec" role="presentation">Ficheiros</div><div class="dr-grid dr-grid-a" role="group" aria-label="Ficheiros"></div>';
      itemsEl.innerHTML = h;
      gridF = itemsEl.querySelector('.dr-grid-f'); gridA = itemsEl.querySelector('.dr-grid-a');
      if (gridF) secs.push(newSec(gridF, 0, S.nFolders, true, 'f'));
      if (gridA) secs.push(newSec(gridA, S.nFolders, S.items.length - S.nFolders, true, 'a'));
      secs.forEach(measureSec);
    } else secs.push(newSec(itemsEl, 0, S.items.length, false, 'list'));
    secs.forEach(padSec);    // a altura toda logo à partida: a posição de rolar mantém-se
    if (opt.scrollTop != null) scrollEl.scrollTop = opt.scrollTop;
    updateWindow();
  }
  /** Na raiz: uma linha com os ficheiros abertos há pouco pela equipa (acesso rápido). */
  function renderQuick() {
    const show = S.loaded && S.view === 'folder' && S.prefix === '' && S.filter === 'all';
    const list = [];
    if (show) {
      const seen = new Set();
      for (const r of S.recents) {
        if (list.length >= 8) break;
        const it = r && !seen.has(r.k) && !P.isFolder(r.k) ? S.byKey.get(r.k) : null;
        if (it) { seen.add(r.k); list.push({ it, t: r.t }); }
      }
    }
    if (!list.length) { quickEl.hidden = true; quickEl.innerHTML = ''; return; }
    let h = '<div class="dr-quick-h"><span>Abertos recentemente</span><a href="?v=recent" class="lnk" data-quick="all">Ver todos</a></div><div class="dr-quick-l">';
    list.forEach(({ it, t }) => {
      const img = thumbImg(it);
      const th = it.e && (it.kind === 'image' || it.kind === 'video') ? ' data-e="' + esc(it.e) + '"' : '';
      h += '<button type="button" class="dr-qc" data-k="' + esc(it.k) + '" title="' + esc(it.k) + '">' +
        '<span class="dr-qc-th k-' + it.kind + (img ? ' has-img' : '') + '"' + th + '>' + (img || ic(it.kind)) + '</span>' +
        '<span class="dr-qc-b"><span class="dr-qc-n">' + esc(it.name) + '</span><span class="dr-qc-m">' + esc((t ? 'Aberto ' + TD.fmt.rel(t) : '') + ' · ' + pretty(P.parent(it.k))) + '</span></span></button>';
    });
    quickEl.innerHTML = h + '</div>';
    quickEl.hidden = false;
    if (TD.thumbs && TD.thumbs.urls) {
      const want = list.map(x => x.it).filter(it => it.e && S.thumbs.has(it.e) && !S.thumbUrl.has(it.e) && (it.kind === 'image' || it.kind === 'video')).map(it => it.e);
      if (want.length) Promise.resolve(TD.thumbs.urls(want)).then(map => { Object.keys(map || {}).forEach(e => { if (map[e]) { S.thumbUrl.set(e, map[e]); paintThumb(e); } }); }).catch(() => {});
    }
  }
  quickEl.addEventListener('click', e => {
    const all = e.target.closest('[data-quick="all"]');
    if (all) { if (e.ctrlKey || e.metaKey) return; e.preventDefault(); go({ view: 'recent', prefix: '' }); return; }
    const b = e.target.closest('.dr-qc');
    if (b) TD.app.openFile(b.dataset.k);
  });
  quickEl.addEventListener('contextmenu', e => {
    const b = e.target.closest('.dr-qc');
    if (!b) return;
    e.preventDefault(); e.stopPropagation();
    const k = b.dataset.k;
    openMenu([
      { label: 'Abrir', icon: 'open', fn: () => TD.app.openFile(k) },
      { label: 'Mostrar na pasta', icon: 'folder', fn: () => TD.app.reveal(k) },
      { label: 'Partilhar link…', icon: 'link', fn: () => shareDialog(k) },
      { label: 'Copiar caminho (não é um link)', icon: 'copy', fn: () => copyPath(k) },
    ], { x: e.clientX, y: e.clientY });
  });
  function skeletonHtml() {
    let h = '<div class="dr-skel" aria-hidden="true">';
    for (let i = 0; i < 9; i++) h += '<div class="dr-skel-row"><i></i><b style="width:' + (30 + (i * 37) % 45) + '%"></b><em></em></div>';
    return h + '</div><span class="sr">A carregar…</span>';
  }
  function errorHtml() {
    const act = S.errStatus === 401
      ? '<a class="btn sm" href="/admin/index.php">Entrar outra vez</a>'
      : '<button type="button" class="btn sm" data-empty="retry">' + ic('refresh') + 'Tentar outra vez</button>';
    return '<div class="dr-empty is-err">' + '<span class="dr-empty-ico">' + ic('warn') + '</span>' +
      '<b>Não foi possível abrir o Drive.</b><p>' + esc(S.error) + '</p>' +
      '<div class="dr-empty-acts">' + act + '</div></div>';
  }
  function emptyHtml() {
    const v = S.view;
    const e = (icon, title, text, acts) => '<div class="dr-empty"><span class="dr-empty-ico">' + ic(icon) + '</span><b>' + esc(title) + '</b>' +
      (text ? '<p>' + text + '</p>' : '') + (acts ? '<div class="dr-empty-acts">' + acts + '</div>' : '') + '</div>';
    const btn = (k, icon, label, ghost) => '<button type="button" class="btn sm' + (ghost ? ' ghost' : '') + '" data-empty="' + k + '">' + ic(icon) + esc(label) + '</button>';
    if (S.filter !== 'all' && fileView()) {
      const lab = (FILTERS.find(f => f[0] === S.filter) || [])[1] || '';
      return e('filter', 'Nada do tipo «' + lab + '» aqui.', 'O filtro de tipo está ligado.', btn('nofilter', 'close', 'Limpar o filtro'));
    }
    if (v === 'folder') {
      if (!S.folders.has(S.prefix)) return e('warn', 'Esta pasta já não existe.', 'Pode ter sido movida, renomeada ou enviada para o Lixo.', btn('root', 'drive', 'Ir para o início'));
      return e('folder', 'Esta pasta está vazia.', 'Arraste ficheiros ou pastas do computador para aqui, ou use os botões.',
        btn('upload', 'upload', 'Enviar ficheiros') + btn('mkdir', 'newfolder', 'Nova pasta', true));
    }
    if (v === 'search') {
      // nada nos nomes, mas o conteúdo dos ficheiros tem resultados (logo acima): só uma linha
      if (S.hits && S.hits.length) return '<p class="dr-empty-line">' + ic('search') + '<span>Nenhum nome corresponde a «' + esc(S.q) + '». Veja acima os resultados no conteúdo dos ficheiros.</span></p>';
      return e('search', 'Nada encontrado para «' + S.q + '».', S.scope === 'here' ? 'Procurou só em «' + esc(pretty(S.prefix)) + '».' : 'Procurou nos nomes de todo o Drive.',
        S.scope === 'here' ? btn('scopeall', 'search', 'Procurar em todo o Drive') : '');
    }
    if (v === 'recent') return e('clock', 'Ainda não há ficheiros recentes.', 'Os ficheiros que a equipa abrir ou enviar aparecem aqui.');
    if (v === 'fav') return e('star', 'Ainda não há favoritos.', 'Carregue na estrela de um ficheiro ou pasta (ou botão direito › Adicionar aos favoritos). Os favoritos são partilhados pela equipa.');
    if (v === 'shared') return e('link', 'Não há links ativos.', 'Para enviar um ficheiro a alguém de fora: botão direito › Partilhar link…');
    if (v === 'trash') return e('trash', 'O Lixo está vazio.', 'O que for para o Lixo fica cá 30 dias e depois é apagado de vez.');
    return '';
  }

  // ── seleção e foco ──────────────────────────────────────
  function setRoving(i) {
    const el = S.els.get(i);
    if (!el) return;
    if (S.tabEl && S.tabEl !== el) S.tabEl.tabIndex = -1;
    el.tabIndex = 0; S.tabEl = el;
  }
  /** Foco num item: rola até ele (opt.scroll), desenha-o e dá-lhe o foco do teclado (opt.dom). */
  function setFocus(i, opt) {
    opt = opt || {};
    if (i < 0 || i >= S.items.length) return;
    S.focus = i;
    if (opt.scroll !== false) scrollToIndex(i);
    const el = S.els.get(i);
    if (!el) return;
    setRoving(i);
    if (opt.dom !== false) el.focus({ preventScroll: true });
  }
  function applySel() {
    S.els.forEach((el, i) => {
      const on = S.sel.has(S.items[i].k);
      if (el.classList.contains('is-sel') !== on) { el.classList.toggle('is-sel', on); el.setAttribute('aria-selected', on); }
    });
    itemsEl.classList.toggle('has-sel', S.sel.size > 0);
    const hc = headEl.querySelector('[data-hcheck]');
    if (hc) {
      const all = S.items.length > 0 && S.sel.size === S.items.length;
      hc.classList.toggle('on', all); hc.classList.toggle('some', S.sel.size > 0 && !all);
      hc.setAttribute('aria-label', all ? 'Limpar a seleção' : 'Selecionar tudo');
    }
    renderStrip();
    scheduleDetails();
  }
  function selectOnly(i) { S.sel.clear(); if (S.items[i]) S.sel.add(S.items[i].k); S.anchor = i; }
  function selectRange(a, b, add) {
    if (!add) S.sel.clear();
    const lo = Math.min(a, b), hi = Math.max(a, b);
    for (let j = lo; j <= hi; j++) S.sel.add(S.items[j].k);
  }
  function clickSel(i, e) {
    const it = S.items[i];
    const mod = e.ctrlKey || e.metaKey;
    if (e.shiftKey && S.anchor >= 0 && S.anchor < S.items.length) selectRange(S.anchor, i, mod);
    else if (mod) { if (S.sel.has(it.k)) S.sel.delete(it.k); else S.sel.add(it.k); S.anchor = i; }
    else selectOnly(i);
    setFocus(i, { scroll: false });
    applySel();
  }
  function toggleSel(i) {
    const it = S.items[i];
    if (S.sel.has(it.k)) S.sel.delete(it.k); else S.sel.add(it.k);
    S.anchor = i;
    setFocus(i, { scroll: false });
    applySel();
  }
  function selectAll() { S.items.forEach(it => S.sel.add(it.k)); applySel(); }
  function clearSel() { if (!S.sel.size) return false; S.sel.clear(); applySel(); return true; }
  function selectKey(key, focus) {
    const i = S.keyIndex.get(key);
    if (i == null) return false;
    selectOnly(i);
    setFocus(i, { dom: focus !== false });
    applySel();
    return true;
  }
  /** Volta a desenhar a vista mantendo (e mapeando) seleção, foco e posição de rolar. opt.top: volta ao início. */
  function rerender(mapKey, opt) {
    opt = opt || {};
    if (S.renaming || S.drag) { S.pendingRender = true; return; }
    S.pendingRender = false;
    const f = S.items[S.focus];
    const fk = f ? (mapKey ? mapKey(f.k) : f.k) : null;
    const hadFocus = itemsEl.contains(document.activeElement);
    const top = opt.top ? 0 : scrollEl.scrollTop;
    const sel = [];
    S.sel.forEach(k => { const nk = mapKey ? mapKey(k) : k; if (nk) sel.push(nk); });
    const ak = S.items[S.anchor] ? (mapKey ? mapKey(S.items[S.anchor].k) : S.items[S.anchor].k) : null;
    computeItems();
    S.sel = new Set(sel.filter(k => S.keyIndex.has(k)));
    S.focus = fk != null && S.keyIndex.has(fk) ? S.keyIndex.get(fk) : -1;
    S.anchor = ak != null && S.keyIndex.has(ak) ? S.keyIndex.get(ak) : -1;
    renderList({ scrollTop: top });
    if (S.focus >= 0 && S.els.has(S.focus)) setFocus(S.focus, { dom: hadFocus, scroll: false });
    else if (hadFocus) { const f = firstRendered(); if (f >= 0) S.els.get(f).focus({ preventScroll: true }); }
    renderStrip(); renderCrumbs(); scheduleDetails();
  }

  // ── miniaturas ──────────────────────────────────────────
  const thumbWait = new Map();   // etag → item (à espera de ficar visível)
  let thumbQ = [], thumbT = 0;
  function thumbsFor(from, to) {
    if (!TD.thumbs) return;
    const want = new Set();
    for (let j = from; j < to; j++) {
      const it = S.items[j];
      if (it.folder || !it.e || (it.kind !== 'image' && it.kind !== 'video') || S.thumbUrl.has(it.e)) continue;
      if (S.thumbs.has(it.e)) want.add(it.e);
      else if (useGrid() && thumbIO && S.els.get(j)) { thumbWait.set(it.e, it); thumbIO.observe(S.els.get(j)); }
    }
    if (want.size && TD.thumbs.urls) {
      Promise.resolve(TD.thumbs.urls(Array.from(want))).then(map => {
        if (!map) return;
        Object.keys(map).forEach(e => { if (map[e]) { S.thumbUrl.set(e, map[e]); paintThumb(e); } });
      }).catch(() => {});
    }
  }
  function onThumbVisible(entries) {
    entries.forEach(en => {
      if (!en.isIntersecting) return;
      thumbIO.unobserve(en.target);
      const t = en.target.querySelector('[data-e]');
      const e = t && t.getAttribute('data-e');
      const it = e && thumbWait.get(e);
      if (it && !S.thumbUrl.has(e)) { thumbQ.push(it); thumbWait.delete(e); }
    });
    clearTimeout(thumbT);
    if (thumbQ.length && TD.thumbs && TD.thumbs.ensure) thumbT = setTimeout(() => {
      const list = thumbQ; thumbQ = [];
      try {
        TD.thumbs.ensure(list, (etag, url) => { if (!etag || !url) return; S.thumbs.add(etag); S.thumbUrl.set(etag, url); paintThumb(etag); });
      } catch (err) { console.error(err); }
    }, 250);
  }
  function paintThumb(e) {
    const u = S.thumbUrl.get(e);
    if (!u) return;
    const sel = '[data-e="' + (window.CSS && CSS.escape ? CSS.escape(e) : e) + '"]';
    host.querySelectorAll(sel).forEach(box => {
      let img = box.querySelector('img');
      if (!img) {
        img = document.createElement('img'); img.alt = ''; img.decoding = 'async'; img.draggable = false;
        const play = box.querySelector('.dr-play');
        box.textContent = '';
        box.appendChild(img);
        if (play) box.appendChild(play);
      }
      img.src = u;
      box.classList.add('has-img');
    });
  }

  // ── barra lateral: árvore, atalhos, espaço ──────────────
  function treeKids(p) {
    const f = S.folders.get(p);
    if (!f) return [];
    return f.kids.slice().sort((a, b) => {
      if (p === '') {
        const ia = S.roots.indexOf(a), ib = S.roots.indexOf(b);
        if (ia >= 0 || ib >= 0) return (ia < 0 ? 99 : ia) - (ib < 0 ? 99 : ib);
      }
      return coll.compare(P.base(a), P.base(b));
    });
  }
  function treeNode(p, lvl, has, open) {
    const f = S.folders.get(p);
    const cur = S.view === 'folder' && S.prefix === p;
    const name = p === '' ? 'Drive' : f.name;
    return '<div class="dr-tn' + (cur ? ' is-cur' : '') + (p === '' ? ' is-root' : '') + '" role="treeitem" aria-level="' + lvl + '"' +
      (has ? ' aria-expanded="' + open + '"' : '') + ' aria-selected="' + cur + '" tabindex="-1" data-k="' + esc(p) + '" style="--lvl:' + lvl + '" title="' + esc(name) + '">' +
      '<span class="dr-tw' + (open ? ' open' : '') + '" data-tw aria-hidden="true">' + (has ? ic('chevron') : '') + '</span>' +
      ic(p === '' ? 'drive' : 'folder', 'dr-tn-ico') +
      '<span class="dr-tn-n">' + esc(name) + '</span>' +
      '<span class="dr-tn-s">' + (f && f.size ? esc(TD.fmt.size(f.size)) : '') + '</span></div>';
  }
  function renderTree() {
    if (!S.loaded) { treeEl.innerHTML = '<div class="dr-tree-skel" aria-hidden="true"><i></i><i></i><i></i><i></i></div>'; return; }
    const focusK = treeEl.contains(document.activeElement) ? document.activeElement.getAttribute('data-k') : null;
    let h = treeNode('', 1, true, S.treeRoot);
    const walk = (p, lvl) => {
      for (const c of treeKids(p)) {
        const cf = S.folders.get(c);
        const has = cf.kids.length > 0, open = has && S.expanded.has(c);
        h += treeNode(c, lvl, has, open);
        if (open) walk(c, lvl + 1);
      }
    };
    if (S.treeRoot) walk('', 2);
    treeEl.innerHTML = h;
    const nodes = treeEl.querySelectorAll('.dr-tn');
    let tab = treeEl.querySelector('.is-cur') || nodes[0];
    if (focusK != null) { const f = treeEl.querySelector('[data-k="' + cssq(focusK) + '"]'); if (f) { tab = f; } }
    if (tab) tab.tabIndex = 0;
    if (focusK != null && tab) tab.focus({ preventScroll: true });
    const cur = treeEl.querySelector('.is-cur');
    if (cur) scrollNearest(treeEl.parentNode, cur, 0);
  }
  function cssq(s) { return String(s).replace(/["\\]/g, '\\$&'); }
  function expandTo(prefix) {
    let changed = false;
    if (prefix && !S.treeRoot) { S.treeRoot = true; changed = true; }
    for (let p = P.parent(prefix); p !== ''; p = P.parent(p)) if (!S.expanded.has(p)) { S.expanded.add(p); changed = true; }
    if (changed) saveTree();
  }
  function saveTree() {
    const arr = Array.from(S.expanded).slice(-200);
    TD.store.set('drive.tree', arr); TD.store.set('drive.treeRoot', S.treeRoot);
  }
  function toggleNode(p, open) {
    if (p === '') S.treeRoot = open != null ? open : !S.treeRoot;
    else if (open != null ? open : !S.expanded.has(p)) S.expanded.add(p); else S.expanded.delete(p);
    saveTree(); renderTree();
  }
  function renderShortcuts() {
    const list = [['recent', 'clock', 'Recentes', ''], ['fav', 'star', 'Favoritos', S.favs.size || ''],
      ['shared', 'link', 'Partilhados', S.shares.length || ''], ['trash', 'trash', 'Lixo', S.trashInfo.count || '']];
    scEl.innerHTML = list.map(([v, i, l, c]) => '<a href="?v=' + v + '" class="dr-sc-it' + (S.view === v ? ' is-cur' : '') + '" data-v="' + v + '"' +
      (S.view === v ? ' aria-current="page"' : '') + '>' + ic(i) + '<span class="dr-sc-l">' + l + '</span>' + (c !== '' ? '<span class="dr-count">' + c + '</span>' : '') + '</a>').join('');
  }
  function renderQuota() {
    const r = S.folders.get('');
    if (!S.loaded || !r) { quotaEl.innerHTML = ''; return; }
    const total = r.size;
    const tops = treeKids('').map(k => ({ k, name: P.base(k), s: S.folders.get(k).size }));
    const bases = tops.filter(t => isRoot(t.k));
    const others = tops.filter(t => !isRoot(t.k)).sort((a, b) => b.s - a.s);
    const rows = bases.slice();
    const rest = others.reduce((a, t) => a + t.s, 0) + r.files.reduce((a, it) => a + it.s, 0);
    if (rest > 0) rows.push({ k: '', name: 'Outros', s: rest });
    const colors = ['var(--ink)', 'var(--yellow)', 'var(--sky)', 'var(--coral)', '#B4B4AE'];
    let bar = '', list = '';
    rows.forEach((t, i) => {
      const pct = total ? t.s * 100 / total : 0;
      const c = colors[Math.min(i, colors.length - 1)];
      if (pct > 0) bar += '<i style="width:' + Math.max(pct, 1.2).toFixed(2) + '%;background:' + c + '" title="' + esc(t.name + ': ' + TD.fmt.size(t.s)) + '"></i>';
      list += '<li><i style="background:' + c + '"></i><span class="dr-q-n">' + esc(t.name) + '</span><span class="dr-q-s">' + esc(TD.fmt.size(t.s)) + '</span></li>';
    });
    quotaEl.innerHTML = '<div class="dr-q-h"><span>Espaço usado</span><b>' + esc(TD.fmt.size(total)) + '</b></div>' +
      '<div class="dr-q-bar" role="img" aria-label="' + esc(rows.map(t => t.name + ' ' + TD.fmt.size(t.s)).join(', ')) + '">' + bar + '</div>' +
      '<ul class="dr-q-l">' + list + '</ul>' +
      '<div class="dr-q-f">' + esc(n(r.nfiles, 'ficheiro', 'ficheiros')) + (S.trashInfo.size ? ' · Lixo ' + esc(TD.fmt.size(S.trashInfo.size)) : '') + '</div>';
  }
  function renderSide() { renderShortcuts(); renderQuota(); }

  // ── migalhas, faixa (filtros / seleção) e barra ─────────
  function renderCrumbs() {
    let h = '';
    const v = S.view;
    if (v === 'folder') {
      let cr = P.crumbs(S.prefix);
      let hiddenCr = [];
      if (cr.length > 4) { hiddenCr = cr.slice(1, cr.length - 2); cr = [cr[0], null].concat(cr.slice(cr.length - 2)); }
      cr.forEach((c, i) => {
        if (i) h += '<span class="dr-cr-sep" aria-hidden="true">' + ic('chevron') + '</span>';
        if (c === null) { h += '<button type="button" class="dr-cr dr-cr-more" data-crmore aria-label="Mais pastas" title="' + esc(hiddenCr.map(x => x[1]).join(' › ')) + '">…</button>'; return; }
        const last = i === cr.length - 1;
        if (last) h += '<button type="button" class="dr-cr is-cur" data-crumb="' + esc(c[0]) + '" data-crmenu aria-haspopup="menu" aria-current="page">' + (c[0] === '' ? ic('drive') : '') + '<span>' + esc(c[1]) + '</span>' + ic('caret', 'dr-cr-caret') + '</button>';
        else h += '<button type="button" class="dr-cr" data-crumb="' + esc(c[0]) + '">' + (c[0] === '' ? ic('drive') : '') + '<span>' + esc(c[1]) + '</span></button>';
      });
      crumbsEl.dataset.hidden = JSON.stringify(hiddenCr.map(x => x[0]));
    } else {
      const t = { recent: ['clock', 'Recentes'], fav: ['star', 'Favoritos'], shared: ['link', 'Partilhados'], trash: ['trash', 'Lixo'] }[v];
      if (v === 'search') h = '<span class="dr-cr is-cur is-static">' + ic('search') + '<span>Resultados para «' + esc(S.q) + '»</span></span>';
      else if (t) h = '<span class="dr-cr is-cur is-static">' + ic(t[0]) + '<span>' + t[1] + '</span></span>';
    }
    crumbsEl.innerHTML = h;
    histBack.disabled = S.hist <= 0;
    histFwd.disabled = S.hist >= S.histMax;
    histUp.disabled = !(S.view === 'folder' ? S.prefix !== '' : S.view === 'search');
  }
  function renderStrip() {
    const v = S.view;
    const sel = selItems().length && S.sel.size ? selItems() : [];
    if (sel.length) {
      const size = sel.reduce((a, it) => a + Math.max(0, it.s || 0), 0);
      const anyRoot = sel.some(it => isRoot(it.k));
      const b = (k, icon, label, extra) => '<button type="button" class="dr-sb-btn' + (extra || '') + '" data-sb="' + k + '" title="' + esc(label) + '">' + ic(icon) + '<span>' + esc(label) + '</span></button>';
      let acts = '';
      if (v === 'trash') acts = b('restore', 'restore', 'Restaurar') + b('purge', 'trash', 'Apagar de vez', ' danger');
      else if (v === 'shared') acts = (sel.length === 1 ? b('copylink', 'link', 'Copiar link') : '') + b('revoke', 'close', 'Revogar', ' danger');
      else {
        const one = sel.length === 1 ? sel[0] : null;
        acts = b('download', 'download', one && !one.folder ? 'Descarregar' : 'Descarregar ZIP') +
          (anyRoot ? '' : b('move', 'move', 'Mover para…')) + b('copyto', 'copy', 'Copiar para…') +
          (one && !anyRoot ? b('quicklink', 'link', 'Copiar link de partilha') + b('share', 'link', 'Partilhar…') : '') + (anyRoot ? '' : b('trash', 'trash', 'Lixo', ' danger'));
      }
      stripEl.className = 'dr-strip is-sel';
      stripEl.innerHTML = '<button type="button" class="dr-iconbtn" data-sb="clear" aria-label="Limpar a seleção" title="Limpar a seleção (Esc)">' + ic('close') + '</button>' +
        '<span class="dr-sb-n" role="status"><b>' + esc(n(sel.length, 'selecionado', 'selecionados')) + '</b>' + (size ? '<span class="dr-sb-size"> · ' + esc(TD.fmt.size(size)) + '</span>' : '') + '</span>' +
        '<span class="dr-sb-acts">' + acts + '</span>' +
        (fileView() ? '<button type="button" class="dr-iconbtn" data-sb="more" aria-label="Mais ações" aria-haspopup="menu" title="Mais ações">' + ic('more') + '</button>' : '');
      return;
    }
    stripEl.className = 'dr-strip';
    let h = '';
    if (fileView()) {
      const fl = FILTERS.find(f => f[0] === S.filter) || FILTERS[0];
      const so = SORTS.find(s => s[0] === S.sort.by) || SORTS[0];
      h += '<button type="button" class="dr-chip' + (S.filter !== 'all' ? ' on' : '') + '" data-chip="filter" aria-haspopup="menu">' + ic('filter') + '<span>' + esc(S.filter === 'all' ? 'Tipo' : fl[1]) + '</span>' + ic('caret', 'dr-chip-caret') + '</button>';
      if (S.filter !== 'all') h += '<button type="button" class="dr-chip-x" data-chip="nofilter" aria-label="Limpar o filtro" title="Limpar o filtro">' + ic('close') + '</button>';
      if (v !== 'recent') h += '<button type="button" class="dr-chip" data-chip="sort" aria-haspopup="menu">' + ic('sort') + '<span>' + esc(so[1]) + '</span><span class="dr-chip-dir">' + (S.sort.dir > 0 ? '↑' : '↓') + '</span>' + ic('caret', 'dr-chip-caret') + '</button>';
    } else if (v === 'trash') {
      h += '<span class="dr-strip-note">' + ic('clock') + '<span>Os itens no Lixo são apagados de vez ao fim de 30 dias.</span></span>';
    } else if (v === 'shared') {
      h += '<span class="dr-strip-note">' + ic('link') + '<span>Quem tiver um destes links pode descarregar sem entrar no admin.</span></span>';
    }
    h += '<span class="dr-strip-sp"></span>';
    if (S.loaded) {
      let sum = '';
      if (v === 'folder') { const f = S.folders.get(S.prefix); if (f) sum = itemsWord(S.items.length) + (f.size ? ' · ' + TD.fmt.size(f.size) : ''); }
      else if (v === 'search') sum = n(S.items.length, 'resultado', 'resultados') + ' nos nomes';
      else if (v === 'trash') sum = S.trash ? itemsWord(S.trash.items.length) + (S.trash.size ? ' · ' + TD.fmt.size(S.trash.size) : '') : '';
      else sum = itemsWord(S.items.length);
      h += '<span class="dr-strip-sum">' + esc(sum) + '</span>';
      if (v === 'trash' && S.trash && S.trash.items.length) h += '<button type="button" class="btn danger sm" data-chip="empty">' + ic('trash') + 'Esvaziar Lixo</button>';
    }
    stripEl.innerHTML = h;
  }
  function renderBar() {
    host.querySelectorAll('[data-layout]').forEach(b => { const on = b.dataset.layout === S.layout; b.classList.toggle('on', on); b.setAttribute('aria-pressed', on); b.disabled = !fileView(); });
    infoBtn.setAttribute('aria-pressed', S.details);
    infoBtn.classList.toggle('on', S.details);
    const inFolder = S.view === 'folder' ? S.prefix : (S.view === 'search' ? S.prefix : '');
    const canScope = (S.view === 'search' || document.activeElement === qEl) && inFolder !== '';
    scopeBtn.hidden = !canScope;
    if (canScope) {
      scopeBtn.textContent = S.scope === 'here' ? 'Só em «' + P.base(inFolder) + '»' : 'Em todo o Drive';
      scopeBtn.setAttribute('aria-label', 'Âmbito da pesquisa: ' + scopeBtn.textContent + '. Carregue para mudar.');
      scopeBtn.classList.toggle('on', S.scope === 'here');
    }
    qX.hidden = !qEl.value;
    newBtn.disabled = !S.loaded;
  }
  function renderAll() {
    renderList(); renderTree(); renderSide(); renderCrumbs(); renderStrip(); renderBar(); scheduleDetails();
  }

  // ── navegação (sem recarregar: ?p= ?v= ?q=) ─────────────
  function validPrefix(p) {
    return p === '' || (p.slice(-1) === '/' && !hiddenKey(p) && p.slice(0, -1).split('/').every(s => s !== '' && s !== '.' && s !== '..' && s.trim() !== ''));
  }
  function readUrl() {
    const u = new URLSearchParams(location.search);
    let p = u.get('p') || '';
    if (p && p.slice(-1) !== '/') p += '/';
    if (!validPrefix(p)) p = '';
    const v = u.get('v'), q = (u.get('q') || '').trim().slice(0, 200);
    if (q) return { view: 'search', prefix: p, q, scope: p ? 'here' : 'all' };
    if (['recent', 'fav', 'shared', 'trash'].indexOf(v) >= 0) return { view: v, prefix: '' };
    return { view: 'folder', prefix: p };
  }
  function urlOf() {
    const u = new URLSearchParams();
    if (S.view === 'folder' && S.prefix) u.set('p', S.prefix);
    else if (S.view === 'search') { if (S.scope === 'here' && S.prefix) u.set('p', S.prefix); u.set('q', S.q); }
    else if (S.view !== 'folder') u.set('v', S.view);
    const s = u.toString().replace(/%2F/g, '/');
    return location.pathname + (s ? '?' + s : '');
  }
  function pushUrl(replace) {
    const url = urlOf();
    if (replace || url === location.pathname + location.search) { history.replaceState({ td: S.hist }, '', url); return; }
    S.hist++; S.histMax = S.hist;
    history.pushState({ td: S.hist }, '', url);
  }
  /** Muda de vista/pasta. opt: push (omissão true), replace, focusKey, select */
  function go(st, opt) {
    opt = opt || {};
    if (S.renaming) S.renaming.cancel();
    const prev = { view: S.view, prefix: S.prefix };
    if (S.items[S.focus] && S.view === 'folder') S.lastChild.set(S.prefix, S.items[S.focus].k);
    S.view = st.view;
    if (st.prefix != null) S.prefix = st.prefix;
    if (st.view === 'search') { S.q = st.q || ''; if (st.scope) S.scope = st.scope; if (qEl.value.trim() !== S.q) qEl.value = S.q; }
    else { S.q = ''; qEl.value = ''; S.hits = null; S.before = null; clearTimeout(S.hitsT); S.hitsSeq++; renderHits(); }
    S.sel.clear(); S.anchor = -1; S.focus = -1;
    if (S.view === 'trash' && (!S.trash || prev.view !== 'trash')) loadTrash();
    if (S.view === 'shared' && prev.view !== 'shared') loadShares();
    if (S.view === 'folder') expandTo(S.prefix);
    if (opt.push !== false) pushUrl(opt.replace);
    computeItems();
    scrollEl.scrollTop = 0;
    renderList();
    let fk = opt.focusKey || null;
    if (!fk && S.view === 'folder' && prev.view === 'folder' && prev.prefix.indexOf(S.prefix) === 0 && prev.prefix !== S.prefix) {
      fk = prev.prefix.slice(0, S.prefix.length + prev.prefix.slice(S.prefix.length).indexOf('/') + 1);   // a subpasta de onde viemos
    }
    if (!fk && S.view === 'folder' && opt.push === false) fk = S.lastChild.get(S.prefix) || null;
    if (fk && S.keyIndex.has(fk)) {
      const i = S.keyIndex.get(fk);
      if (opt.select) selectOnly(i);
      S.anchor = i;
      setFocus(i, { dom: opt.select || itemsEl.contains(document.activeElement) || document.activeElement === document.body });
      if (opt.select) applySel();
    }
    renderTree(); renderShortcuts(); renderCrumbs(); renderStrip(); renderBar(); scheduleDetails();
    if (narrow()) setDrawer(false);
  }
  window.addEventListener('popstate', e => {
    S.hist = (e.state && typeof e.state.td === 'number') ? e.state.td : 0;
    if (S.hist > S.histMax) S.histMax = S.hist;
    go(readUrl(), { push: false });
  });
  function up() {
    if (S.view === 'folder') { if (S.prefix) go({ view: 'folder', prefix: P.parent(S.prefix) }); }
    else if (S.view === 'search') leaveSearch();
  }

  // ── catálogo ────────────────────────────────────────────
  let loadSeq = 0, loadP = null, refreshT = 0;
  function load(fresh) {
    if (loadP && !fresh) return loadP;
    const seq = ++loadSeq;
    const p = TD.api({ do: 'catalog', fresh: !!fresh }).then(j => {
      if (seq !== loadSeq) return j;
      S.lastLoad = Date.now();
      if (!j.ok) {
        if (!S.loaded) { S.error = j.error || 'Erro.'; S.errStatus = j.status || 0; renderAll(); }
        return j;
      }
      if (S.busy > 0) { S.needLoad = true; return j; }   // há uma operação a meio: a resposta pode já vir velha
      applyCatalog(j);
      return j;
    });
    loadP = p;
    p.then(() => { if (loadP === p) loadP = null; });
    return p;
  }
  function applyCatalog(j) {
    const first = !S.loaded;
    S.objects = (Array.isArray(j.objects) ? j.objects : []).filter(o => o && typeof o.k === 'string' && o.k !== '' && !hiddenKey(o.k))
      .map(o => ({ k: o.k, s: +o.s || 0, m: +o.m || 0, e: String(o.e || '').toLowerCase() }));
    if (Array.isArray(j.roots) && j.roots.length) S.roots = j.roots.filter(r => typeof r === 'string');
    S.thumbs = new Set(Array.isArray(j.thumbs) ? j.thumbs : []);
    // os URLs das miniaturas são pré-assinados (6 h): ao fim de 5 h pedem-se outra vez
    if (!S.thumbT || Date.now() - S.thumbT > 5 * 3600 * 1000) { S.thumbUrl.clear(); S.thumbT = Date.now(); }
    S.favs = new Set(Array.isArray(j.favs) ? j.favs : []);
    S.recents = Array.isArray(j.recents) ? j.recents.filter(r => r && typeof r.k === 'string') : [];
    if (j.trash && typeof j.trash === 'object') S.trashInfo = { count: +j.trash.count || 0, size: +j.trash.size || 0 };
    S.shares = Array.isArray(j.shares) ? j.shares.filter(s => s && s.k) : [];
    S.index = j.index || null;
    S.loaded = true; S.error = '';
    buildIndex();
    if (first) {
      computeItems();
      renderAll();
      if (S.view === 'folder') { expandTo(S.prefix); renderTree(); }
      readyResolve();
    } else {
      rerender(); renderTree(); renderSide(); renderBar();
    }
    TD.bus.emit('drive:catalog', { count: S.objects.length });
  }
  /** Atualização em segundo plano depois de uma operação (pede a listagem fresca). */
  function scheduleRefresh(ms) {
    clearTimeout(refreshT);
    refreshT = setTimeout(() => { if (S.busy > 0) { S.needLoad = true; return; } load(true); }, ms == null ? 700 : ms);
  }
  function userRefresh() {
    const t = TD.toast('A atualizar…', 'info', { ms: 0 });
    const tasks = [load(true)];
    if (S.view === 'trash') tasks.push(loadTrash());
    Promise.all(tasks).then(rs => { t.close(); const j = rs[0]; if (j && !j.ok) TD.toast(j.error || 'Não foi possível atualizar.', 'err'); });
  }
  function loadTrash() {
    S.trashBusy = true;
    return TD.api({ do: 'trash_list' }).then(j => {
      S.trashBusy = false;
      if (!j.ok) { S.trash = S.trash || { items: [], count: 0, size: 0 }; if (S.view === 'trash') TD.toast(j.error || 'Não foi possível abrir o Lixo.', 'err'); }
      else {
        S.trash = { items: (j.items || []).map(trashItem), count: +j.count || 0, size: +j.size || 0 };
        S.trashInfo = { count: S.trash.items.length, size: S.trash.size || S.trash.items.reduce((a, t) => a + t.s, 0) };
      }
      renderSide();
      if (S.view === 'trash') rerender();
      return j;
    });
  }
  /** Partilhados: os links ativos e as descargas vêm do servidor (o catálogo pode ter minutos). */
  function loadShares() {
    return TD.api({ do: 'share_list' }).then(j => {
      if (!j.ok || !Array.isArray(j.items)) return j;
      S.shares = j.items.filter(s => s && typeof s.k === 'string');
      renderSide();
      if (S.view === 'shared') rerender();
      return j;
    });
  }
  function opDone() {
    S.busy = Math.max(0, S.busy - 1);
    if (!S.busy && S.needLoad) { S.needLoad = false; scheduleRefresh(200); }
  }

  // ── alterações locais ao catálogo (logo, antes da resposta fresca) ──
  function mapper(from, to) {
    const isF = P.isFolder(from);
    return k => (isF ? (k.indexOf(from) === 0 ? to + k.slice(from.length) : k) : (k === from ? to : k));
  }
  function locTransfer(from, to, copy) {
    const isF = P.isFolder(from), add = [], keep = [];
    for (const o of S.objects) {
      const hit = isF ? o.k.indexOf(from) === 0 : o.k === from;
      if (hit) add.push({ k: to + o.k.slice(from.length), s: o.s, m: copy ? now() : o.m, e: o.e });
      if (!hit || copy) keep.push(o);
    }
    const addKeys = new Set(add.map(o => o.k));
    S.objects = keep.filter(o => !addKeys.has(o.k)).concat(add);
    if (isF && !addKeys.has(to)) S.objects.push({ k: to, s: 0, m: now(), e: '' });
    if (!copy) {
      const m = mapper(from, to);
      S.favs = new Set(Array.from(S.favs, m));
      S.recents = S.recents.map(r => ({ k: m(r.k), t: r.t }));
      S.shares = S.shares.map(s => Object.assign({}, s, { k: m(s.k) }));
      if (isF) {
        S.expanded = new Set(Array.from(S.expanded, m));
        if (S.prefix.indexOf(from) === 0) { S.prefix = m(S.prefix); if (S.view === 'folder') pushUrl(true); }
      }
      if (S.clip) { S.clip.keys = S.clip.keys.map(m); S.clip.set = new Set(S.clip.keys); }
    }
  }
  function locRemove(key) {
    const isF = P.isFolder(key), gone = [];
    S.objects = S.objects.filter(o => { const hit = isF ? o.k.indexOf(key) === 0 : o.k === key; if (hit) gone.push(o); return !hit; });
    const under = k => (isF ? k.indexOf(key) === 0 : k === key);
    S.favs = new Set(Array.from(S.favs).filter(k => !under(k)));
    S.recents = S.recents.filter(r => !under(r.k));
    S.shares = S.shares.filter(s => !under(s.k));
    return gone;
  }
  function afterLocal(mapKey) {
    buildIndex();
    if (S.view === 'folder' && !S.folders.has(S.prefix)) {
      // a pasta aberta deixou de existir (foi para o Lixo ou mudou de sítio): sobe até uma que exista
      let p = S.prefix; while (p && !S.folders.has(p)) p = P.parent(p);
      S.prefix = p; pushUrl(true);
    }
    rerender(mapKey); renderTree(); renderSide();
  }

  // ── TD.ops: as operações (interface e assistente) ───────
  function progress(label) {
    let t = null, text = label;
    const timer = setTimeout(() => { t = TD.toast(text, 'info', { ms: 0 }); }, 500);
    return {
      set(s) { text = s; if (t) t.set(s); },
      close() { clearTimeout(timer); if (t) t.close(); },
    };
  }
  /** opt.noOverwrite: sem «Substituir» (juntar uma pasta a uma pasta que a contém). */
  function conflictDialog(msg, remaining, op, opt) {
    return TD.dialog.open((box, done) => {
      box.classList.add('dr-dlg');
      box.setAttribute('aria-labelledby', 'dr-cf-t');
      box.innerHTML = '<h2 class="td-dialog-title" id="dr-cf-t">Já existe um item com este nome</h2><p class="td-dialog-text"></p>';
      box.querySelector('p').textContent = msg || 'Já existe um item com este nome no destino.';
      const l = TD.el('div', 'td-dialog-choices');
      const ch = [['overwrite', 'Substituir', 'O que está no destino é substituído (as pastas juntam-se).'],
        ['rename', 'Manter ambos', 'Fica com um nome novo, por exemplo «nome (2)».'],
        ['skip', 'Saltar', op === 'copy' ? 'Este item não é copiado.' : 'Este item fica onde está.']];
      let cb = null;
      ch.forEach(([v, lab, hint]) => {
        if (v === 'overwrite' && opt && opt.noOverwrite) return;
        const b = TD.el('button', { class: 'td-choice' + (v === 'overwrite' ? ' danger' : ''), type: 'button', 'data-choice': v, autofocus: v === 'rename' });
        b.appendChild(TD.el('b', null, lab)); b.appendChild(TD.el('span', 'muted', hint));
        b.onclick = () => done({ choice: v, all: !!(cb && cb.checked) });
        l.appendChild(b);
      });
      box.appendChild(l);
      if (remaining > 0) {
        const lab = TD.el('label', 'check dr-cf-all');
        cb = TD.el('input', { type: 'checkbox' });
        lab.appendChild(cb); lab.appendChild(document.createTextNode(' Aplicar aos restantes (' + remaining + ')'));
        box.appendChild(lab);
      }
      const a = TD.el('div', 'td-dialog-actions');
      const no = TD.el('button', { class: 'btn ghost', type: 'button' }, remaining > 0 ? 'Cancelar o resto' : 'Cancelar');
      no.onclick = () => done(null);
      a.appendChild(no); box.appendChild(a);
    });
  }
  function fail(message, opt) {
    if (!(opt && opt.quiet)) TD.toast(message, 'err');
    return { ok: false, message };
  }
  function checkKey(k, folderOk) {
    if (typeof k !== 'string' || k === '' || hiddenKey(k) || k.length > 1024 || k[0] === '/') return 'Caminho inválido.';
    if (!folderOk && P.isFolder(k)) return 'Caminho inválido.';
    if (P.isFolder(k) ? !validPrefix(k) : !validPrefix(P.parent(k)) || P.base(k).trim() === '') return 'Caminho inválido.';
    return '';
  }
  /** move | copy | rename — itens [{from, to}] com o `to` completo. */
  async function doTransfer(op, items, opt) {
    const verb = { move: ['A mover', 'movido', 'movidos', 'mover'], copy: ['A copiar', 'copiado', 'copiados', 'copiar'], rename: ['A renomear', 'renomeado', 'renomeados', 'renomear'] }[opt.rename ? 'rename' : op];
    // copiar para a mesma pasta = duplicar («nome (2)»); mover para o mesmo sítio não faz nada
    items = (Array.isArray(items) ? items : []).filter(x => x && x.from && x.to && (x.from !== x.to || op === 'copy'));
    if (!items.length) return fail('Nada para ' + verb[3] + '.', opt);
    for (const x of items) {
      const bad = checkKey(x.from, true) || checkKey(x.to, true);
      if (bad) return fail(bad, opt);
      if (P.isFolder(x.from) !== P.isFolder(x.to)) return fail('Caminho inválido.', opt);
      if (op === 'move' && isRoot(x.from)) return fail(ROOT_ERR, opt);
      if (P.isFolder(x.from) && x.from !== x.to && x.to.indexOf(x.from) === 0) return fail('Não é possível ' + verb[3] + ' uma pasta para dentro dela própria.', opt);
    }
    S.busy++;
    const pg = progress(items.length === 1 ? verb[0] + ' «' + P.base(items[0].from) + '»…' : verb[0] + ' ' + items.length + ' itens…');
    const done = [], failed = [];
    let skipped = 0, cancelled = false, policy = opt.onConflict || null;
    try {
      for (let i = 0; i < items.length; i++) {
        const { from, to } = items[i];
        // «Fotos/Fotos/» → «Fotos/»: a pasta de destino contém a origem; só «Manter ambos» ou «Saltar»
        const inside = P.isFolder(from) && from !== to && from.indexOf(to) === 0;
        let mode = from === to ? 'rename' : policy || 'error';
        if (inside && mode === 'overwrite') mode = 'error';
        for (;;) {
          try {
            const r = await TD.until({ do: op, from, to, on_conflict: mode }, j => {
              if (j.progress && j.progress.total) {
                const p = j.progress.done + ' de ' + j.progress.total + ' ficheiros';
                pg.set((items.length > 1 ? verb[0] + ' ' + (i + 1) + ' de ' + items.length + ' · ' : verb[0] + ' «' + P.base(from) + '» · ') + p);
              } else if (items.length > 1) pg.set(verb[0] + ' ' + (i + 1) + ' de ' + items.length + '…');
            });
            if (r.skipped) skipped++;
            else done.push({ from: r.from || from, to: r.to || to, moved: r.moved, overwrite: mode === 'overwrite' });
            break;
          } catch (e) {
            if (e.resp && e.resp.conflict && mode === 'error') {
              pg.close();
              const ans = await conflictDialog(inside ? e.message + ' Essa pasta contém «' + P.base(from) + '»: não se podem juntar.' : e.message, items.length - i - 1, op, { noOverwrite: inside });
              if (!ans) { cancelled = true; break; }
              if (ans.all) policy = ans.choice;
              mode = ans.choice;
              continue;
            }
            failed.push({ from, error: e.message || 'Erro.' });
            break;
          }
        }
        if (cancelled) break;
      }
    } finally {
      pg.close();
    }
    if (done.length) {
      let map = k => k;
      done.forEach(d => { locTransfer(d.from, d.to, op === 'copy'); if (op !== 'copy') { const m = mapper(d.from, d.to), prev = map; map = k => m(prev(k)); } });
      afterLocal(op === 'copy' ? null : map);
      scheduleRefresh();
      TD.bus.emit('drive:changed', { kind: opt.rename ? 'rename' : op, items: done });
    }
    opDone();
    // mensagem
    const dest = done.length ? P.parent(done[0].to) : (items[0] ? P.parent(items[0].to) : '');
    let msg;
    if (opt.rename && done.length === 1) msg = 'Renomeado para «' + P.base(done[0].to) + '».';
    else if (done.length === 1 && items.length === 1) msg = '«' + P.base(done[0].from) + '» ' + verb[1] + ' para «' + pretty(dest) + '».';
    else if (done.length) msg = done.length + ' itens ' + verb[2] + ' para «' + pretty(dest) + '».';
    else if (cancelled) msg = 'Operação cancelada.';
    else if (skipped && !failed.length) msg = 'Nada foi ' + verb[1] + ' (saltado).';
    else msg = '';
    if (skipped && done.length) msg += ' ' + n(skipped, 'saltado', 'saltados') + '.';
    if (cancelled && done.length) msg += ' O resto foi cancelado.';
    if (failed.length) msg = (msg ? msg + ' ' : '') + 'Não foi possível ' + verb[3] + ' «' + P.base(failed[0].from) + '»: ' + failed[0].error + (failed.length > 1 ? ' (+' + (failed.length - 1) + ')' : '');
    const ok = !failed.length && !cancelled && (done.length > 0 || skipped > 0);
    if (!opt.quiet) {
      if (failed.length || cancelled) TD.toast(msg, failed.length ? 'err' : 'info');
      else if (done.length) {
        const undoable = op !== 'copy' && opt.undo !== false && !done.some(d => d.overwrite);
        const undo = undoable ? () => run({ kind: 'move', payload: { items: done.map(d => ({ from: d.to, to: d.from })) } }, { undo: false, undoMsg: true }) : null;
        toastUndo(opt.undoMsg ? 'Anulado.' : msg, undo);
      } else TD.toast(msg, 'info');
    }
    return { ok, message: msg, result: { done, skipped, failed, cancelled } };
  }
  function toastUndo(msg, undo) {
    S.undo = undo;
    TD.toast(msg, 'ok', undo ? { action: { label: 'Anular', fn: () => { if (S.undo === undo) S.undo = null; undo(); } }, ms: 9000 } : {});
  }
  async function doMkdir(key, opt) {
    const bad = checkKey(key, true);
    if (bad || !P.isFolder(key)) return fail('Caminho inválido.', opt);
    if (byteLen(P.base(key)) > 200) return fail('O nome é demasiado comprido.', opt);
    if (S.folders.has(key)) return fail('Já existe uma pasta chamada «' + P.base(key) + '» aqui.', opt);
    S.busy++;
    const j = await TD.api({ do: 'mkdir', key });
    opDone();
    if (!j.ok) return fail(j.error || 'Não foi possível criar a pasta.', opt);
    const k = j.key || key;
    for (let p = k; p !== ''; p = P.parent(p)) if (!S.folders.has(p)) S.objects.push({ k: p, s: 0, m: now(), e: '' });
    afterLocal();
    scheduleRefresh();
    TD.bus.emit('drive:changed', { kind: 'mkdir', key: k });
    const msg = 'Pasta «' + P.base(k) + '» criada.';
    if (!opt.quiet) TD.toast(msg, 'ok');
    return { ok: true, message: msg, result: { key: k } };
  }
  const BATCH = 500;   // itens por cadeia de pedidos nas operações em lote (o servidor aceita até 500)
  async function doTrash(keys, opt) {
    keys = (Array.isArray(keys) ? keys : []).filter(k => typeof k === 'string');
    if (!keys.length) return fail('Nada para enviar para o Lixo.', opt);
    for (const k of keys) {
      const bad = checkKey(k, true);
      if (bad) return fail(bad, opt);
      if (isRoot(k)) return fail(ROOT_ERR, opt);
    }
    S.busy++;
    const pg = progress(keys.length === 1 ? 'A enviar «' + P.base(keys[0]) + '» para o Lixo…' : 'A enviar ' + keys.length + ' itens para o Lixo…');
    const ids = [], failed = [], doneKeys = [];
    const gone = (key, id) => {
      const objs = locRemove(key);
      S.trashInfo.count++; S.trashInfo.size += objs.reduce((a, o) => a + o.s, 0);
      if (id != null) { ids.push(id); S.trashed.set(id, { k: key, objs }); }
      doneKeys.push(key);
    };
    try {
      if (keys.length === 1) {
        const key = keys[0];
        try {
          const r = await TD.until({ do: 'trash', key }, j => {
            if (j.progress && j.progress.total) pg.set('A enviar para o Lixo · ' + j.progress.done + ' de ' + j.progress.total + ' ficheiros');
          });
          gone(key, r.id);
        } catch (e) { failed.push({ key, error: e.message || 'Erro.' }); }
      } else {
        // em lote: o servidor trata vários ficheiros de cada vez; cada resposta diz o que já foi
        const take = j => {
          (j.items || []).forEach(x => gone(x.k, x.id));
          (j.failed || []).forEach(x => failed.push({ key: x.k, error: x.error || 'Erro.' }));
        };
        for (let i = 0; i < keys.length; i += BATCH) {
          const chunk = keys.slice(i, i + BATCH);
          try {
            await TD.until({ do: 'trash', keys: chunk }, j => {
              take(j);
              pg.set('A enviar para o Lixo · ' + Math.min(keys.length, i + ((j.progress && j.progress.done) || 0)) + ' de ' + keys.length + ' itens');
            });
          } catch (e) {
            if (e.resp) take(e.resp);
            const left = chunk.filter(k => doneKeys.indexOf(k) < 0 && !failed.some(f => f.key === k));
            if (left.length) failed.push({ key: left[0], error: e.message || 'Erro.' });
            break;
          }
        }
      }
    } finally { pg.close(); }
    if (ids.length) {
      S.trash = null;
      afterLocal();
      scheduleRefresh();
      TD.bus.emit('drive:changed', { kind: 'trash', keys: doneKeys, ids });
    }
    opDone();
    let msg = ids.length === 1 && keys.length === 1 ? '«' + P.base(keys[0]) + '» enviado para o Lixo.' : ids.length ? ids.length + ' itens enviados para o Lixo.' : '';
    if (failed.length) msg = (msg ? msg + ' ' : '') + 'Não foi possível enviar «' + P.base(failed[0].key) + '» para o Lixo: ' + failed[0].error + (failed.length > 1 ? ' (+' + (failed.length - 1) + ')' : '');
    if (!opt.quiet) {
      if (failed.length) TD.toast(msg, 'err');
      else toastUndo(msg, opt.undo === false ? null : () => run({ kind: 'restore', payload: { ids } }, { undoMsg: true }));
    }
    return { ok: !failed.length, message: msg, result: { ids, failed } };
  }
  async function doRestore(ids, opt) {
    ids = (Array.isArray(ids) ? ids : []).filter(x => x != null);
    if (!ids.length) return fail('Nada para restaurar.', opt);
    S.busy++;
    const pg = progress(ids.length === 1 ? 'A restaurar…' : 'A restaurar ' + ids.length + ' itens…');
    const done = [], failed = [];
    let policy = opt.onConflict || null, cancelled = false, skipped = 0;
    /** Um item, com as perguntas de conflito (o caminho de sempre). */
    async function one(id, mode, remaining) {
      for (;;) {
        try {
          const body = { do: 'restore', id };
          if (mode !== 'error') body.on_conflict = mode;
          const r = await TD.until(body, j => { if (j.progress && j.progress.total) pg.set('A restaurar · ' + j.progress.done + ' de ' + j.progress.total + ' ficheiros'); });
          if (r.skipped) skipped++; else done.push({ id, k: r.k });
          return;
        } catch (e) {
          if (e.resp && e.resp.conflict && mode === 'error') {
            pg.close();
            const ans = await conflictDialog(e.message, remaining, 'restore');
            if (!ans) { cancelled = true; return; }
            if (ans.all) policy = ans.choice;
            mode = ans.choice;
            continue;
          }
          failed.push({ id, error: e.message || 'Erro.' });
          return;
        }
      }
    }
    try {
      if (ids.length === 1) await one(ids[0], policy || 'error', 0);
      else {
        // em lote; num conflito o servidor pára nesse item, pergunta-se e continua-se dali
        let rest = ids.slice();
        const take = j => {
          (j.items || []).forEach(x => done.push({ id: x.id, k: x.k }));
          (j.failed || []).forEach(x => failed.push({ id: x.id, error: x.error || 'Erro.' }));
        };
        while (rest.length && !cancelled) {
          const chunk = rest.slice(0, BATCH);
          const body = { do: 'restore', ids: chunk };
          if (policy) body.on_conflict = policy;
          let sk = 0;
          try {
            await TD.until(body, j => {
              take(j); sk = j.skipped || 0;
              pg.set('A restaurar · ' + Math.min(ids.length, ids.length - rest.length + ((j.progress && j.progress.done) || 0)) + ' de ' + ids.length + ' itens');
            });
            skipped += sk;
            rest = rest.slice(chunk.length);
          } catch (e) {
            const j = e.resp || {};
            take(j); skipped += j.skipped || 0;
            const at = j.conflict ? rest.indexOf(j.id) : -1;
            if (at < 0) { failed.push({ id: chunk[0], error: e.message || 'Erro.' }); break; }
            await one(j.id, 'error', rest.length - at - 1);
            rest = rest.slice(at + 1);
          }
        }
      }
    } finally { pg.close(); }
    if (done.length) {
      done.forEach(d => {
        const t = S.trashed.get(d.id);
        // volta já ao sítio na lista local (a listagem fresca confirma)
        if (t && t.k === d.k) t.objs.forEach(o => { if (!S.objects.some(x => x.k === o.k)) S.objects.push(o); });
        S.trashed.delete(d.id);
        if (S.trash) S.trash.items = S.trash.items.filter(x => x.id !== d.id);
      });
      S.trashInfo.count = Math.max(0, S.trashInfo.count - done.length);
      afterLocal();
      scheduleRefresh(300);
      TD.bus.emit('drive:changed', { kind: 'restore', items: done });
    }
    if (done.length || failed.length) { if (S.view === 'trash') loadTrash(); else S.trash = null; }
    opDone();
    let msg = done.length === 1 && done[0].k ? '«' + P.base(done[0].k) + '» restaurado em «' + pretty(P.parent(done[0].k)) + '».' : done.length ? done.length + ' itens restaurados.' : cancelled ? 'Operação cancelada.' : skipped ? 'Nada foi restaurado (saltado).' : '';
    if (opt.undoMsg && done.length && !failed.length) msg = 'Anulado. ' + msg;
    if (failed.length) msg = (msg ? msg + ' ' : '') + 'Não foi possível restaurar: ' + failed[0].error + (failed.length > 1 ? ' (+' + (failed.length - 1) + ')' : '');
    if (!opt.quiet) TD.toast(msg, failed.length ? 'err' : cancelled ? 'info' : 'ok', done.length === 1 && done[0].k && !opt.undoMsg ? { action: { label: 'Mostrar', fn: () => TD.app.reveal(done[0].k) } } : {});
    return { ok: !failed.length && !cancelled, message: msg, result: { done, failed, skipped } };
  }
  async function doPurge(pl, opt) {
    S.busy++;
    const all = !!pl.all;
    const ids = all ? [] : (pl.ids || (pl.id != null ? [pl.id] : [])).filter(x => x != null);
    if (!all && !ids.length) { opDone(); return fail('Nada para apagar.', opt); }
    const pg = progress(all ? 'A esvaziar o Lixo…' : 'A apagar de vez…');
    const failed = [], done = [];
    let left = 0;
    try {
      if (all) {
        try {
          const r = await TD.until({ do: 'purge_all' }, j => { if (j.progress && j.progress.total) pg.set('A esvaziar o Lixo · ' + j.progress.done + ' de ' + j.progress.total); });
          done.push('all'); left = r.left || 0;
        } catch (e) { failed.push(e.message || 'Erro.'); }
      } else if (ids.length === 1) {
        try { await TD.until({ do: 'purge', id: ids[0] }); done.push(ids[0]); S.trashed.delete(ids[0]); }
        catch (e) { failed.push(e.message || 'Erro.'); }
      } else {
        for (let i = 0; i < ids.length; i += BATCH) {
          try {
            await TD.until({ do: 'purge', ids: ids.slice(i, i + BATCH) }, j => {
              (j.items || []).forEach(id => { done.push(id); S.trashed.delete(id); });
              pg.set('A apagar de vez · ' + Math.min(ids.length, done.length) + ' de ' + ids.length + ' itens');
            });
          } catch (e) { failed.push(e.message || 'Erro.'); break; }
        }
      }
    } finally { pg.close(); }
    if (done.length) {
      if (all) { S.trash = left ? null : { items: [], count: 0, size: 0 }; S.trashInfo = { count: left, size: left ? S.trashInfo.size : 0 }; S.trashed.clear(); }
      else if (S.trash) {
        S.trash.items = S.trash.items.filter(x => done.indexOf(x.id) < 0);
        S.trash.size = S.trash.items.reduce((a, t) => a + t.s, 0);
        S.trashInfo = { count: S.trash.items.length, size: S.trash.size };
      } else S.trashInfo.count = Math.max(0, S.trashInfo.count - done.length);
      renderSide();
      // o que outro sócio enviou para o Lixo entretanto fica lá: a lista vem outra vez do servidor
      if (S.view === 'trash') { if (S.trash) rerender(); else loadTrash(); }
      if (all && left) scheduleRefresh(300);
      TD.bus.emit('drive:changed', { kind: 'purge', ids: done });
    }
    opDone();
    let msg = all ? (done.length ? (left ? 'O Lixo foi esvaziado. Ficaram ' + itemsWord(left) + ' que entraram entretanto.' : 'O Lixo foi esvaziado.') : '') : done.length === 1 ? 'Apagado de vez.' : done.length ? done.length + ' itens apagados de vez.' : '';
    if (failed.length) msg = (msg ? msg + ' ' : '') + 'Não foi possível apagar: ' + failed[0];
    if (!opt.quiet) TD.toast(msg, failed.length ? 'err' : 'ok');
    return { ok: !failed.length, message: msg, result: { done, failed } };
  }
  async function doShare(key, hours, opt) {
    const bad = checkKey(key, true);
    if (bad) return fail(bad, opt);
    const forever = opt.forever === true;
    if (forever && !P.isFolder(key)) return fail('Só uma pasta pode ficar partilhada sem prazo.', opt);
    hours = Number(hours);
    if (!forever && (!Number.isInteger(hours) || hours < 1 || hours > 168)) return fail('Duração inválida (de 1 a 168 horas).', opt);
    const body = forever ? { do: 'share_create', key, forever: true } : { do: 'share_create', key, hours };
    if (opt.password) body.password = String(opt.password);   // só a interface; as propostas do assistente nunca a levam
    if (opt.includePrivate) body.include_private = true;   // só a interface, depois de a pessoa o marcar
    if (opt.perm === 'edit') body.perm = 'edit';   // §32.2: só a interface (o servidor confirma que pode)
    const j = await TD.api(body);
    if (!j.ok || !j.share) return fail((j && j.error) || 'Não foi possível criar o link.', opt);
    const sh = Object.assign({ downloads: 0 }, j.share);
    S.shares = S.shares.filter(x => x.id !== sh.id).concat([sh]);
    renderSide();
    if (S.view === 'shared') rerender();
    scheduleDetails(true);
    TD.bus.emit('drive:changed', { kind: 'share', key, share: sh });
    const msg = 'Link para «' + P.base(key) + '» criado (' + (forever ? 'sem prazo, até o revogar' : 'válido ' + hoursText(hours)) + ').';
    if (!opt.quiet) TD.toast(msg, 'ok', { action: { label: 'Copiar link', fn: () => TD.copyLink(sh.url) }, ms: 9000 });
    return { ok: true, message: msg, url: sh.url, result: sh };
  }
  /** TD.ops.run(action, opt) → {ok, message, url?, result?}. Nunca rejeita. */
  async function run(action, opt) {
    opt = opt || {};
    try {
      const kind = action && action.kind;
      const pl = (action && action.payload) || {};
      switch (kind) {
        case 'move': return await doTransfer('move', pl.items || (pl.from ? [{ from: pl.from, to: pl.to }] : []), opt);
        case 'copy': return await doTransfer('copy', pl.items || (pl.from ? [{ from: pl.from, to: pl.to }] : []), opt);
        case 'rename': return await doTransfer('move', [{ from: pl.from, to: pl.to }], Object.assign({}, opt, { rename: true }));
        case 'mkdir': return await doMkdir(pl.key, opt);
        case 'trash': return await doTrash(pl.items || (pl.key ? [pl.key] : []), opt);
        case 'share': return await doShare(pl.key, pl.hours, Object.assign({}, opt, pl.forever === true ? { forever: true } : {}, typeof pl.password === 'string' && pl.password ? { password: pl.password } : {}));
        case 'restore': return await doRestore(pl.ids || (pl.id != null ? [pl.id] : []), opt);
        case 'purge': return await doPurge(pl, opt);
        default: return fail('Ação desconhecida.', opt);
      }
    } catch (e) {
      console.error(e);
      return fail((e && e.message) || 'Erro inesperado.', opt);
    }
  }
  TD.ops = { run };

  // ── ações da interface ──────────────────────────────────
  function canUpload() { return !!(TD.upload && TD.upload.addFiles); }
  function needUpload() { if (canUpload()) return true; TD.toast('O módulo de envios não está disponível nesta página.', 'err'); return false; }
  function pickFiles(prefix) { if (needUpload() && TD.upload.pickFiles) TD.upload.pickFiles(prefix); }
  function pickFolder(prefix) { if (needUpload() && TD.upload.pickFolder) TD.upload.pickFolder(prefix); }
  function openItem(it) {
    if (!it) return;
    if (S.view === 'trash') { restoreItems([it]); return; }
    if (S.view === 'shared') { TD.app.openFile(it.orig); return; }
    if (it.folder) go({ view: 'folder', prefix: it.k });
    else preview(it);
  }
  function previewActions() {
    return [
      { id: 'share', label: 'Partilhar', icon: 'link', fn: item => shareDialog(item.k) },
      { id: 'details', label: 'Detalhes', icon: 'info', fn: item => { if (TD.preview && TD.preview.close) TD.preview.close(); TD.app.reveal(item.k); openDetails(true); } },
      { id: 'reveal', label: 'Mostrar na pasta', icon: 'folder', fn: item => { if (TD.preview && TD.preview.close) TD.preview.close(); TD.app.reveal(item.k); } },
    ].map(a => Object.assign(a, { onClick: a.fn, run: a.fn }));
  }
  function touchRecent(k) {
    S.recents = [{ k, t: now() }].concat(S.recents.filter(r => r.k !== k)).slice(0, 200);
    if (S.view === 'recent') rerender();
    else if (S.view === 'folder' && S.prefix === '') renderQuick();
  }
  function preview(it) {
    if (!it || it.folder) return;
    const obj = S.byKey.get(it.k) || it;
    touchRecent(obj.k);
    if (TD.preview && TD.preview.open) {
      const list = (fileView() ? S.items : [obj]).filter(x => !x.folder).map(x => S.byKey.get(x.k) || x);
      const prevFocus = document.activeElement;
      TD.preview.open(obj, {
        list,
        onNav: item => { const i = S.keyIndex.get(item && item.k); if (i != null) { selectOnly(i); setFocus(i, { dom: false }); applySel(); } touchRecent(item.k); },
        onClose: () => { const el = S.els.get(S.focus); if (el) el.focus({ preventScroll: true }); else if (prevFocus && prevFocus.focus && prevFocus.isConnected) prevFocus.focus(); },
        actions: previewActions(),
      });
      return;
    }
    // sem o módulo de pré-visualização: abre num separador novo
    const w = window.open('', '_blank');
    TD.api({ do: 'url', key: obj.k, inline: true, recent: true }).then(j => {
      if (!j.ok) { if (w) w.close(); TD.toast(j.error || 'Não foi possível abrir.', 'err'); return; }
      if (w) { w.opener = null; w.location.href = j.url; } else location.href = j.url;
    });
  }
  function downloadFile(it) {
    TD.api({ do: 'url', key: it.k, inline: false }).then(j => {
      if (!j.ok) { TD.toast(j.error || 'Não foi possível descarregar.', 'err'); return; }
      // num iframe escondido: a página não navega (nem pergunta «sair?» durante envios)
      const f = document.createElement('iframe');
      f.hidden = true; f.className = 'dr-dl-frame'; f.src = j.url;
      document.body.appendChild(f);
      setTimeout(() => f.remove(), 120000);
      TD.toast('A descarregar «' + it.name + '»…', 'info', { ms: 3000 });
    });
  }
  function download(list) {
    if (list.length === 1 && !list[0].folder) downloadFile(list[0]);
    else downloadZip(list);
  }
  function zipEntries(list) {
    const base = S.view === 'folder' ? S.prefix : null;
    const out = [], used = new Set();
    const add = (o, path) => {
      let p = path, i = 2;
      while (used.has(p)) { const dot = path.lastIndexOf('.'); p = dot > 0 ? path.slice(0, dot) + ' (' + i + ')' + path.slice(dot) : path + ' (' + i + ')'; i++; }
      used.add(p);
      out.push({ key: o.k, path: p, size: o.s, modified: o.m });
    };
    list.forEach(it => {
      if (it.folder) {
        const par = P.parent(it.k);
        S.objects.forEach(o => { if (o.k.indexOf(it.k) === 0 && o.k.slice(-1) !== '/') add(o, o.k.slice(par.length)); });
      } else {
        const o = S.byKey.get(it.k) || it;
        add(o, base != null && o.k.indexOf(base) === 0 ? o.k.slice(base.length) : P.base(o.k));
      }
    });
    return out;
  }
  async function downloadZip(list) {
    if (!TD.zip || !TD.zip.download) { TD.toast('O módulo de ZIP não está disponível nesta página.', 'err'); return; }
    const entries = zipEntries(list);
    if (!entries.length) { TD.toast('Não há ficheiros para descarregar.', 'info'); return; }
    const total = entries.reduce((a, e) => a + (e.size || 0), 0);
    let name;
    if (list.length === 1 && list[0].folder) name = list[0].name + '.zip';
    else if (S.view === 'folder' && S.prefix) name = P.base(S.prefix) + '.zip';
    else name = 'topper-drive.zip';
    const ac = 'AbortController' in window ? new AbortController() : null;
    const t = TD.toast('A preparar «' + name + '» (' + n(entries.length, 'ficheiro', 'ficheiros') + ', ' + TD.fmt.size(total) + ')…', 'info',
      { ms: 0, action: ac ? { label: 'Cancelar', fn: () => ac.abort() } : null });
    S.zipping++;
    let last = 0;
    try {
      await TD.zip.download(entries, name, {
        signal: ac ? ac.signal : undefined,
        onProgress: function (a, b) {
          let done = 0, tot = total;
          if (typeof a === 'number') { done = a; if (typeof b === 'number' && b > 0) tot = b; }
          else if (a && typeof a === 'object') { done = a.bytes != null ? a.bytes : a.loaded != null ? a.loaded : a.done || 0; tot = a.total || a.totalBytes || total; }
          const nowMs = Date.now();
          if (nowMs - last < 250) return;
          last = nowMs;
          t.set('ZIP «' + name + '»: ' + (tot ? Math.min(100, Math.round(done * 100 / tot)) + '% · ' : '') + TD.fmt.size(done) + ' de ' + TD.fmt.size(tot));
        },
      });
      t.close();
      TD.toast('«' + name + '» descarregado.', 'ok');
    } catch (e) {
      t.close();
      if (e && (e.name === 'AbortError' || (ac && ac.signal.aborted))) TD.toast('Descarga cancelada.', 'info');
      else TD.toast((e && e.message) || 'Não foi possível criar o ZIP.', 'err');
    } finally { S.zipping--; }
  }
  function transfer(keys, dest, op, opt) {
    const items = keys.filter(k => P.parent(k) !== dest || op === 'copy').map(k => ({ from: k, to: dest + P.base(k) + (P.isFolder(k) ? '/' : '') }));
    if (!items.length) return Promise.resolve({ ok: true, message: '' });
    const same = items.every(x => P.parent(x.from) === dest);
    return run({ kind: op, payload: { items } }, Object.assign(same && op === 'copy' ? { onConflict: 'rename' } : {}, opt || {}));
  }
  async function moveTo(list, op) {
    op = op || 'move';
    const keys = list.map(realKey);
    if (op === 'move' && keys.some(isRoot)) { TD.toast(ROOT_ERR, 'err'); return; }
    const start = S.view === 'folder' ? S.prefix : P.parent(keys[0] || '');
    const dest = await pickFolder2(op === 'copy' ? 'Copiar para…' : 'Mover para…', op === 'copy' ? 'Copiar aqui' : 'Mover aqui', start, keys, op);
    if (dest == null) return;
    transfer(keys, dest, op);
  }
  function clipSet(mode, list) {
    const keys = list.map(realKey);
    if (mode === 'cut' && keys.some(isRoot)) { TD.toast(ROOT_ERR, 'err'); return; }
    S.clip = { mode, keys, set: new Set(keys) };
    S.els.forEach((el, i) => el.classList.toggle('is-cut', mode === 'cut' && S.clip.set.has(S.items[i].k)));
    TD.toast((keys.length === 1 ? '«' + P.base(keys[0]) + '»' : keys.length + ' itens') + (mode === 'cut' ? ' cortado' : ' copiado') + (keys.length === 1 ? '' : 's') + '. Abra a pasta de destino e cole (' + MODK + 'V).', 'info');
  }
  function paste(dest) {
    if (!S.clip) return;
    if (dest == null) { if (S.view !== 'folder') { TD.toast('Abra uma pasta para colar.', 'info'); return; } dest = S.prefix; }
    const c = S.clip;
    const keys = c.keys.filter(k => TD.app.exists(k));
    if (!keys.length) { S.clip = null; TD.toast('O que copiou já não existe.', 'err'); return; }
    if (c.mode === 'cut') S.clip = null;
    transfer(keys, dest, c.mode === 'cut' ? 'move' : 'copy');
  }
  function setFav(list, on) {
    const keys = list.map(realKey).filter(k => k);
    const before = new Set(S.favs);
    keys.forEach(k => { if (on) S.favs.add(k); else S.favs.delete(k); });
    refreshStars(); renderShortcuts();
    if (S.view === 'fav') rerender();
    scheduleDetails(true);
    (async () => {
      for (const key of keys) {
        const j = await TD.api({ do: 'fav', key, on });
        if (!j.ok) { S.favs = before; refreshStars(); renderShortcuts(); if (S.view === 'fav') rerender(); TD.toast(j.error || 'Não foi possível guardar o favorito.', 'err'); return; }
        if (Array.isArray(j.favs)) S.favs = new Set(j.favs);
      }
      refreshStars(); renderShortcuts();
      TD.bus.emit('drive:changed', { kind: 'fav', keys, on });
    })();
  }
  function refreshStars() {
    S.els.forEach((el, i) => {
      const b = el.querySelector('.dr-star'); if (!b) return;
      const on = S.favs.has(S.items[i].k);
      b.classList.toggle('on', on); b.setAttribute('aria-pressed', on);
      const l = on ? 'Remover dos favoritos' : 'Adicionar aos favoritos';
      b.setAttribute('aria-label', l); b.title = l;
    });
  }
  function copyPath(k) { TD.copyText(k).then(ok => TD.toast(ok ? 'Caminho copiado: ' + k : 'Não foi possível copiar.', ok ? 'ok' : 'err')); }
  /**
   * «Copiar link de partilha» (§32.6): cria (ou reaproveita) um link «Só ver» com a validade por defeito e copia-o no
   * mesmo clique. Uma pasta com documentos privados pede confirmação no diálogo «Partilhar link…».
   */
  function quickLink(key) {
    const made = TD.api({ do: 'share_quick', key }).then(j => {
      if (j && j.code === 'private') { shareDialog(key); throw new Error('Esta pasta tem documentos privados: confirme no diálogo.'); }
      if (!j || !j.ok) throw new Error((j && j.error) || 'Não foi possível criar o link.');
      if (!j.reused) { S.shares = S.shares.filter(x => x.id !== j.share.id).concat([j.share]); renderSide(); scheduleDetails(true); }
      return j.url;
    });
    TD.copyLink(made);
  }
  /** Copia o link de uma partilha (§32.5): nunca uma que já expirou; se a cópia falhar, mostra o link para copiar à mão. */
  function copyLink(url, sh) {
    if (sh && !sh.forever && sh.exp && sh.exp * 1000 < Date.now()) { TD.toast('Este link já expirou: crie um novo.', 'err'); return; }
    TD.copyLink(url);
  }
  async function revoke(ids) {
    for (const id of ids) {
      const j = await TD.api({ do: 'share_revoke', id });
      if (!j.ok) { TD.toast(j.error || 'Não foi possível revogar o link.', 'err'); return; }
      S.shares = S.shares.filter(s => s.id !== id);
    }
    renderSide();
    if (S.view === 'shared') rerender();
    scheduleDetails(true);
    TD.toast(ids.length === 1 ? 'Link revogado. Deixou de funcionar.' : ids.length + ' links revogados.', 'ok');
    TD.bus.emit('drive:changed', { kind: 'unshare', ids });
  }
  function trashItems(list) {
    const keys = list.map(realKey);
    if (keys.some(isRoot)) { TD.toast(ROOT_ERR, 'err'); return; }
    run({ kind: 'trash', payload: { items: keys } });
  }
  function restoreItems(list) { run({ kind: 'restore', payload: { ids: list.map(it => it.id) } }); }
  async function purgeItems(list) {
    const ok = await TD.dialog.confirm({ title: list.length === 1 ? 'Apagar «' + list[0].name + '» de vez?' : 'Apagar ' + list.length + ' itens de vez?', text: 'Isto não se pode desfazer.', ok: 'Apagar de vez', danger: true });
    if (ok) run({ kind: 'purge', payload: { ids: list.map(it => it.id) } });
  }
  async function emptyTrash() {
    const c = S.trash ? S.trash.items.length : S.trashInfo.count;
    const ok = await TD.dialog.confirm({ title: 'Esvaziar o Lixo?', text: (c ? itemsWord(c) + ' vão ser apagados de vez. ' : '') + 'Isto não se pode desfazer.', ok: 'Esvaziar Lixo', danger: true });
    if (ok) run({ kind: 'purge', payload: { all: true } });
  }
  async function newFolder(parent) {
    parent = parent != null ? parent : (S.view === 'folder' ? S.prefix : '');
    const name = await TD.dialog.prompt({ title: 'Nova pasta', text: 'Em «' + pretty(parent) + '».', label: 'Nome da pasta', value: '', ok: 'Criar' });
    if (!name) return;
    const nm = cleanName(name);
    if (!nm) { TD.toast('Nome inválido.', 'err'); return; }
    const r = await run({ kind: 'mkdir', payload: { key: parent + nm + '/' } });
    if (r.ok && S.view === 'folder' && S.prefix === parent) selectKey(r.result.key);
  }

  // ── renomear em linha ───────────────────────────────────
  function startRename(i) {
    const it = S.items[i];
    if (!it || !fileView()) return;
    if (isRoot(it.k)) { TD.toast('As pastas base não podem ser renomeadas.', 'err'); return; }
    if (S.renaming) S.renaming.cancel();
    setFocus(i, { dom: false });
    const el = S.els.get(i);
    if (!el) return;
    const nameEl = el.querySelector('.dr-name');
    const inp = TD.el('input', { class: 'dr-rename', type: 'text', maxlength: '200', spellcheck: 'false', autocomplete: 'off', 'aria-label': 'Novo nome para «' + it.name + '»' });
    inp.value = it.name;
    nameEl.hidden = true;
    nameEl.parentNode.insertBefore(inp, nameEl);
    el.draggable = false;
    el.classList.add('is-renaming');
    let finished = false;
    const finish = async commit => {
      if (finished) return;
      finished = true;
      S.renaming = null;
      const v = cleanName(inp.value);
      inp.remove(); nameEl.hidden = false; el.classList.remove('is-renaming');
      if (canDrag(it)) el.draggable = true;
      if (!commit || !v || v === it.name) {
        if (commit && !v) TD.toast('O nome não pode ficar vazio.', 'err');
        if (S.pendingRender) rerender();
        if (el.isConnected) el.focus({ preventScroll: true });
        return;
      }
      if (byteLen(v) > 200) { TD.toast('O nome é demasiado comprido.', 'err'); if (S.pendingRender) rerender(); return; }
      nameEl.textContent = v;
      el.classList.add('is-busy');
      const to = P.parent(it.k) + v + (it.folder ? '/' : '');
      const r = await run({ kind: 'rename', payload: { from: it.k, to } });
      el.classList.remove('is-busy');
      if (!r.ok) { nameEl.textContent = it.name; if (S.pendingRender) rerender(); }
      else { const d = r.result && r.result.done && r.result.done[0]; if (d) selectKey(d.to, itemsEl.contains(document.activeElement) || document.activeElement === document.body || document.activeElement === inp); }
    };
    S.renaming = { i, cancel: () => finish(false) };
    inp.addEventListener('keydown', e => {
      e.stopPropagation();
      if (e.key === 'Enter') { e.preventDefault(); finish(true); }
      else if (e.key === 'Escape') { e.preventDefault(); finish(false); }
    });
    inp.addEventListener('blur', () => finish(true));
    ['mousedown', 'click', 'dblclick', 'contextmenu'].forEach(t => inp.addEventListener(t, e => e.stopPropagation()));
    inp.focus();
    const dot = it.folder ? -1 : it.name.lastIndexOf('.');
    inp.setSelectionRange(0, dot > 0 ? dot : it.name.length);
  }

  // ── menus (botão direito, Novo, filtros) ────────────────
  let menuEl = null, menuPrev = null, menuOnClose = null;
  function closeMenu(restore) {
    if (!menuEl) return;
    menuEl.remove(); menuEl = null;
    document.removeEventListener('keydown', menuKey, true);
    document.removeEventListener('mousedown', menuOutside, true);
    window.removeEventListener('resize', closeMenuNow);
    scrollEl.removeEventListener('scroll', closeMenuNow);
    if (menuOnClose) { const f = menuOnClose; menuOnClose = null; f(); }
    if (restore !== false && menuPrev && menuPrev.isConnected) menuPrev.focus({ preventScroll: true });
    menuPrev = null;
  }
  function closeMenuNow() { closeMenu(false); }
  function menuOutside(e) { if (menuEl && !menuEl.contains(e.target)) closeMenu(false); }
  function menuItems() { return menuEl ? Array.from(menuEl.querySelectorAll('.dr-menu-it:not([aria-disabled="true"])')) : []; }
  function menuKey(e) {
    if (!menuEl) return;
    const its = menuItems();
    const i = its.indexOf(document.activeElement);
    const k = e.key;
    if (k === 'ArrowDown' || k === 'ArrowUp' || k === 'Home' || k === 'End') {
      e.preventDefault(); e.stopPropagation();
      if (!its.length) return;
      const j = k === 'Home' ? 0 : k === 'End' ? its.length - 1 : k === 'ArrowDown' ? (i + 1) % its.length : (i - 1 + its.length) % its.length;
      its[j].focus();
    } else if (k === 'Escape') { e.preventDefault(); e.stopPropagation(); closeMenu(); }
    else if (k === 'Tab') { e.preventDefault(); closeMenu(); }
    else if (k === 'Enter' || k === ' ') { e.stopPropagation(); }
    else if (k.length === 1 && /\S/.test(k) && !e.ctrlKey && !e.metaKey && !e.altKey) {
      e.preventDefault(); e.stopPropagation();
      const f = fold(k);
      const order = its.slice(i + 1).concat(its.slice(0, i + 1));
      const hit = order.find(b => fold(b.textContent.trim()).indexOf(f) === 0);
      if (hit) hit.focus();
    } else e.stopPropagation();
  }
  /** items: [{label, icon, fn, kbd, disabled, danger, checked, hidden} | '-' | null]; pos: {x,y} ou {anchor: el} */
  function openMenu(items, pos, opt) {
    closeMenu(false);
    opt = opt || {};
    menuPrev = opt.returnFocus || document.activeElement;
    menuOnClose = opt.onClose || null;
    const m = TD.el('div', { class: 'dr-menu', role: 'menu', 'aria-label': opt.label || 'Ações' });
    const list = items.filter(it => it && !it.hidden);
    const clean = [];
    list.forEach(it => { if (it === '-' && (!clean.length || clean[clean.length - 1] === '-')) return; clean.push(it); });
    while (clean[clean.length - 1] === '-') clean.pop();
    clean.forEach(it => {
      if (it === '-') { m.appendChild(TD.el('div', { class: 'dr-menu-sep', role: 'separator' })); return; }
      if (it.title) { m.appendChild(TD.el('div', { class: 'dr-menu-title', role: 'presentation' }, it.title)); return; }
      const radio = it.checked != null;
      const b = TD.el('button', { class: 'dr-menu-it' + (it.danger ? ' danger' : '') + (it.checked ? ' on' : ''), type: 'button', role: radio ? 'menuitemradio' : 'menuitem', tabindex: '-1',
        'aria-disabled': it.disabled ? 'true' : null, 'aria-checked': radio ? String(!!it.checked) : null });
      b.innerHTML = '<span class="dr-menu-ic">' + (radio ? (it.checked ? ic('check') : '') : (it.icon ? ic(it.icon) : '')) + '</span><span class="dr-menu-l">' + esc(it.label) + '</span>' + (it.kbd ? '<kbd>' + esc(it.kbd) + '</kbd>' : '');
      b.addEventListener('click', ev => { ev.stopPropagation(); if (it.disabled) return; closeMenu(it.keepFocus !== false); it.fn(); });
      m.appendChild(b);
    });
    document.body.appendChild(m);
    menuEl = m;
    const W = window.innerWidth, H = window.innerHeight, r = m.getBoundingClientRect();
    let x, y;
    if (pos && pos.anchor) {
      const a = pos.anchor.getBoundingClientRect();
      x = pos.align === 'right' ? a.right - r.width : a.left; y = a.bottom + 4;
      if (y + r.height > H - 8) y = Math.max(8, a.top - r.height - 4);
    } else { x = (pos && pos.x) || 0; y = (pos && pos.y) || 0; if (y + r.height > H - 8) y = Math.max(8, y - r.height); }
    x = Math.max(8, Math.min(x, W - r.width - 8));
    y = Math.max(8, Math.min(y, H - r.height - 8));
    m.style.left = x + 'px'; m.style.top = y + 'px';
    document.addEventListener('keydown', menuKey, true);
    document.addEventListener('mousedown', menuOutside, true);
    window.addEventListener('resize', closeMenuNow);
    scrollEl.addEventListener('scroll', closeMenuNow, { passive: true });
    const first = menuItems()[0];
    if (first) first.focus(); else m.focus();
  }
  function itemMenu(pos) {
    const list = selItems();
    if (!list.length) return;
    const one = list.length === 1 ? list[0] : null;
    const v = S.view;
    if (v === 'trash') {
      openMenu([
        { label: 'Restaurar', icon: 'restore', fn: () => restoreItems(list) },
        one && { label: 'Detalhes', icon: 'info', kbd: 'I', fn: () => openDetails(true) },
        '-',
        { label: 'Apagar de vez', icon: 'trash', danger: true, kbd: 'Delete', fn: () => purgeItems(list) },
      ], pos);
      return;
    }
    if (v === 'shared') {
      openMenu([
        one && { label: 'Abrir', icon: 'open', kbd: 'Enter', fn: () => openItem(one) },
        one && { label: 'Mostrar na pasta', icon: 'folder', fn: () => TD.app.reveal(one.orig) },
        one && { label: 'Copiar link', icon: 'link', fn: () => copyLink(one.share.url, one.share) },
        '-',
        { label: list.length === 1 ? 'Revogar o link' : 'Revogar ' + list.length + ' links', icon: 'close', danger: true, fn: () => revoke(list.map(x => x.share.id)) },
      ], pos);
      return;
    }
    const anyRoot = list.some(it => isRoot(it.k));
    const fav = list.every(it => S.favs.has(it.k));
    const pasteOk = !!S.clip && one && one.folder;
    openMenu([
      one && { label: 'Abrir', icon: one.folder ? 'folder' : 'open', kbd: 'Enter', fn: () => openItem(one) },
      one && !one.folder && { label: 'Pré-visualizar', icon: 'eye', kbd: 'Espaço', fn: () => preview(one) },
      one && flatView() && { label: 'Mostrar na pasta', icon: 'folder', fn: () => TD.app.reveal(one.k) },
      '-',
      one && !one.folder && { label: 'Descarregar', icon: 'download', fn: () => downloadFile(one) },
      { label: 'Descarregar como ZIP', icon: 'zip', fn: () => downloadZip(list) },
      '-',
      one && { label: 'Renomear', icon: 'edit', kbd: 'F2', disabled: anyRoot, fn: () => startRename(S.keyIndex.get(one.k)), keepFocus: false },
      { label: 'Mover para…', icon: 'move', disabled: anyRoot, fn: () => moveTo(list, 'move') },
      { label: 'Copiar', icon: 'copy', kbd: MODK + 'C', fn: () => clipSet('copy', list) },
      { label: 'Cortar', icon: 'cut', kbd: MODK + 'X', disabled: anyRoot, fn: () => clipSet('cut', list) },
      S.clip && { label: 'Colar' + (pasteOk ? ' em «' + one.name + '»' : ''), icon: 'paste', kbd: MODK + 'V', disabled: !pasteOk && S.view !== 'folder', fn: () => paste(pasteOk ? one.k : null) },
      '-',
      { label: fav ? 'Remover dos favoritos' : 'Adicionar aos favoritos', icon: 'star', fn: () => setFav(list, !fav) },
      one && { label: 'Copiar link de partilha', icon: 'link', disabled: anyRoot, fn: () => quickLink(one.k) },
      one && { label: 'Partilhar link…', icon: 'link', disabled: anyRoot, fn: () => shareDialog(one.k) },
      one && { label: 'Copiar caminho (não é um link)', icon: 'copy', fn: () => copyPath(one.k) },
      { label: 'Detalhes', icon: 'info', kbd: 'I', fn: () => openDetails(true) },
      '-',
      { label: 'Enviar para o Lixo', icon: 'trash', danger: true, kbd: 'Delete', disabled: anyRoot, fn: () => trashItems(list) },
    ], pos);
  }
  function bgMenu(pos) {
    if (S.view === 'trash') {
      openMenu([
        { label: 'Esvaziar Lixo', icon: 'trash', danger: true, disabled: !(S.trash && S.trash.items.length), fn: emptyTrash },
        { label: 'Atualizar', icon: 'refresh', fn: userRefresh },
      ], pos);
      return;
    }
    const w = S.view === 'folder' && S.loaded;
    openMenu([
      { label: 'Nova pasta', icon: 'newfolder', disabled: !w, fn: () => newFolder(S.prefix) },
      { label: 'Enviar ficheiros', icon: 'upload', disabled: !w || !canUpload(), fn: () => pickFiles(S.prefix) },
      { label: 'Enviar pasta', icon: 'upfolder', disabled: !w || !canUpload(), fn: () => pickFolder(S.prefix) },
      '-',
      { label: 'Colar', icon: 'paste', kbd: MODK + 'V', disabled: !S.clip || !w, fn: () => paste(S.prefix) },
      { label: 'Selecionar tudo', icon: 'check', kbd: MODK + 'A', disabled: !S.items.length, fn: selectAll },
      { label: 'Atualizar', icon: 'refresh', fn: userRefresh },
    ], pos);
  }
  function folderMenu(anchor) {
    const p = S.prefix;
    const it = itemOf(p);
    const base = p === '' || isRoot(p);
    openMenu([
      { label: 'Nova pasta', icon: 'newfolder', fn: () => newFolder(p) },
      { label: 'Enviar ficheiros', icon: 'upload', disabled: !canUpload(), fn: () => pickFiles(p) },
      { label: 'Enviar pasta', icon: 'upfolder', disabled: !canUpload(), fn: () => pickFolder(p) },
      '-',
      it && { label: 'Descarregar como ZIP', icon: 'zip', fn: () => downloadZip([it]) },
      it && !base && { label: 'Partilhar link…', icon: 'link', fn: () => shareDialog(p) },
      it && { label: S.favs.has(p) ? 'Remover dos favoritos' : 'Adicionar aos favoritos', icon: 'star', fn: () => setFav([it], !S.favs.has(p)) },
      it && !base && { label: 'Renomear…', icon: 'edit', fn: () => renameDialog(it) },
      it && !base && { label: 'Mover para…', icon: 'move', fn: () => moveTo([it], 'move') },
      it && { label: 'Copiar caminho (não é um link)', icon: 'copy', fn: () => copyPath(p) },
      { label: 'Detalhes', icon: 'info', fn: () => { clearSel(); openDetails(true); } },
      '-',
      { label: 'Atualizar', icon: 'refresh', fn: userRefresh },
      it && !base && '-',
      it && !base && { label: 'Enviar para o Lixo', icon: 'trash', danger: true, fn: () => trashItems([it]) },
    ], { anchor }, { label: 'Pasta «' + pretty(p) + '»' });
  }
  async function renameDialog(it) {
    const dot = it.folder ? -1 : it.name.lastIndexOf('.');
    const v = await TD.dialog.prompt({ title: 'Renomear', label: 'Novo nome', value: it.name, ok: 'Guardar', select: [0, dot > 0 ? dot : it.name.length] });
    const nm = cleanName(v || '');
    if (!nm || nm === it.name) return;
    run({ kind: 'rename', payload: { from: it.k, to: P.parent(it.k) + nm + (it.folder ? '/' : '') } });
  }
  function filterMenu(anchor) {
    openMenu(FILTERS.map(([v, l]) => ({ label: l, checked: S.filter === v, fn: () => setFilter(v) })), { anchor }, { label: 'Filtrar por tipo' });
  }
  function sortMenu(anchor) {
    openMenu(SORTS.map(([v, l]) => ({ label: l, checked: S.sort.by === v, fn: () => setSort(v, S.sort.by === v ? S.sort.dir : (v === 'name' || v === 'kind' ? 1 : -1)) }))
      .concat(['-', { label: 'Ascendente', checked: S.sort.dir > 0, fn: () => setSort(S.sort.by, 1) }, { label: 'Descendente', checked: S.sort.dir < 0, fn: () => setSort(S.sort.by, -1) }]), { anchor }, { label: 'Ordenar' });
  }
  function newMenu() {
    const p = S.view === 'folder' ? S.prefix : null;
    const target = p != null ? p : '';
    openMenu([
      { title: 'Em «' + pretty(target) + '»' },
      { label: 'Nova pasta', icon: 'newfolder', fn: () => newFolder(target) },
      '-',
      { label: 'Enviar ficheiros', icon: 'upload', disabled: !canUpload() || p == null, fn: () => pickFiles(target) },
      { label: 'Enviar pasta', icon: 'upfolder', disabled: !canUpload() || p == null, fn: () => pickFolder(target) },
    ], { anchor: newBtn }, { label: 'Novo' });
  }
  function setFilter(v) { S.filter = v; S.sel.clear(); computeItems(); scrollEl.scrollTop = 0; renderList(); renderStrip(); scheduleDetails(); }
  function setSort(by, dir) { S.sort = { by, dir }; TD.store.set('drive.sort', S.sort); rerender(null, { top: true }); }
  function setLayout(l) { if (S.layout === l) return; S.layout = l; TD.store.set('drive.layout', l); rerender(null, { top: true }); renderBar(); }

  // ── diálogos: escolher pasta, partilhar, atalhos ────────
  function pickFolder2(title, okLabel, start, keys, op) {
    const forbidden = p => keys.some(k => P.isFolder(k) && p.indexOf(k) === 0);
    let loc = S.folders.has(start) && !forbidden(start) ? start : '';
    return TD.dialog.open((box, done) => {
      box.classList.add('dr-dlg', 'dr-pick');
      box.setAttribute('aria-label', title);
      const h = TD.el('h2', 'td-dialog-title', title);
      const what = TD.el('p', 'td-dialog-text', keys.length === 1 ? '«' + P.base(keys[0]) + '»' : keys.length + ' itens');
      const crumbs = TD.el('div', 'dr-pick-cr');
      const list = TD.el('div', { class: 'dr-pick-l', role: 'list' });
      const acts = TD.el('div', 'td-dialog-actions');
      const mk = TD.el('button', { class: 'btn ghost sm dr-pick-new', type: 'button' }); mk.innerHTML = ic('newfolder') + 'Nova pasta';
      const form = TD.el('form', { class: 'dr-pick-form', hidden: true });
      form.innerHTML = '<label class="sr" for="dr-pick-nm">Nome da nova pasta</label><input id="dr-pick-nm" type="text" maxlength="200" placeholder="Nome da nova pasta" autocomplete="off" spellcheck="false"><button type="submit" class="btn sm">Criar</button>';
      const sp = TD.el('span', 'dr-strip-sp');
      const no = TD.el('button', { class: 'btn ghost', type: 'button' }, 'Cancelar');
      const ok = TD.el('button', { class: 'btn', type: 'button' }, okLabel);
      acts.append(mk, sp, no, ok);
      box.append(h, what, crumbs, list, form, acts);
      function paint(focusFirst) {
        crumbs.textContent = '';
        P.crumbs(loc).forEach((c, i, a) => {
          if (i) crumbs.appendChild(TD.el('span', 'dr-cr-sep', '›'));
          const b = TD.el('button', { class: 'dr-pick-c' + (i === a.length - 1 ? ' is-cur' : ''), type: 'button' }, c[1]);
          b.onclick = () => { loc = c[0]; paint(true); };
          crumbs.appendChild(b);
        });
        list.textContent = '';
        const kids = treeKids(loc);
        if (!kids.length) list.appendChild(TD.el('div', 'dr-pick-empty', 'Sem subpastas.'));
        let first = true;
        kids.forEach(k => {
          const f = S.folders.get(k);
          const bad = forbidden(k);
          const b = TD.el('button', { class: 'dr-pick-it', type: 'button', role: 'listitem', disabled: bad, autofocus: !bad && first && !focusFirst, title: bad ? 'Não pode ir para dentro dela própria' : 'Abrir «' + f.name + '»' });
          if (!bad) first = false;
          b.innerHTML = ic('folder') + '<span class="dr-pick-n">' + esc(f.name) + '</span><span class="dr-pick-s">' + esc(f.kids.length ? n(f.kids.length, 'pasta', 'pastas') : '') + '</span>' + (f.kids.length ? ic('chevron', 'dr-pick-go') : '');
          b.onclick = () => { loc = k; paint(true); };
          list.appendChild(b);
        });
        const same = op === 'move' && keys.every(k => P.parent(k) === loc);
        ok.disabled = same || forbidden(loc);
        ok.textContent = okLabel + (loc ? '' : ' (raiz)');
        ok.title = same ? 'Os itens já estão nesta pasta.' : (okLabel + ': ' + pretty(loc));
        if (focusFirst) { const f = list.querySelector('button:not([disabled])') || ok; f.focus(); }
      }
      paint(false);
      no.onclick = () => done(null);
      ok.onclick = () => done(loc);
      const nm = form.querySelector('input');
      mk.onclick = () => { form.hidden = !form.hidden; if (!form.hidden) { nm.value = ''; nm.focus(); } };
      form.addEventListener('submit', async e => {
        e.preventDefault();
        const name = cleanName(nm.value);
        if (!name) { nm.focus(); return; }
        const r = await run({ kind: 'mkdir', payload: { key: loc + name + '/' } }, { quiet: true });
        if (!r.ok) { TD.toast(r.message, 'err'); nm.focus(); return; }
        form.hidden = true;
        loc = r.result.key; paint(true);
      });
    });
  }
  /** Como o ai_private do servidor: debaixo de «Empresa/Documentos legais/» (sem maiúsculas nem acentos). */
  function privateKey(k) {
    const seg = k.split('/');
    const norm = x => x.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/\s+/g, ' ').trim();
    return seg.length >= 2 && norm(seg[0]) === 'empresa' && norm(seg[1]) === 'documentos legais';
  }
  function shareDialog(key) {
    const it = itemOf(key);
    if (!it) { TD.toast('Este item já não existe.', 'err'); return; }
    if (isRoot(key)) { TD.toast('As pastas base não podem ser partilhadas. Partilhe uma subpasta ou os ficheiros.', 'err'); return; }
    // uma pasta com documentos privados lá dentro: diz quais, e só partilha com a caixa marcada
    const priv = it.folder && !privateKey(key) ? S.objects.filter(o => o.k.indexOf(key) === 0 && !P.isFolder(o.k) && privateKey(o.k)).map(o => o.k.slice(key.length)) : [];
    // 0 = «Sem prazo» (só pastas); nunca fica guardado como predefinição
    const opts = [[1, '1 h'], [6, '6 h'], [24, '24 h'], [72, '3 dias'], [168, '7 dias']].concat(it.folder ? [[0, 'Sem prazo']] : []);
    let hours = TD.store.get('drive.shareHours', 24);
    if (!opts.some(o => o[0] === hours) || hours === 0) hours = 24;
    TD.dialog.open((box, done) => {
      box.classList.add('dr-dlg', 'dr-share');
      box.setAttribute('aria-label', 'Partilhar «' + it.name + '»');
      box.innerHTML = '<h2 class="td-dialog-title">Partilhar «<span></span>»</h2>' +
        '<p class="td-dialog-text">Cria um link' + (it.folder ? '' : ' temporário') + '. Quem o tiver pode descarregar ' + (it.folder ? 'os ficheiros desta pasta' : 'este ficheiro') + ' sem entrar no admin nem usar password.</p>' +
        (TD.cfg.canEdit ? '<div class="dr-share-l" id="dr-sh-pl">Quem tem o link</div><div class="dr-share-perm" role="radiogroup" aria-labelledby="dr-sh-pl">' +
          '<label class="dr-share-pr"><input type="radio" name="dr-sh-perm" value="view" checked><span><b>Só ver</b><span class="muted">Ver e descarregar</span></span></label>' +
          '<label class="dr-share-pr"><input type="radio" name="dr-sh-perm" value="edit"><span><b>Pode editar</b><span class="muted">' + (it.folder ? 'Carregar, criar pastas, renomear e substituir versões — nunca apagar' : 'Substituir por uma nova versão (a anterior fica no histórico)') + '</span></span></label></div>' : '') +
        '<div class="dr-share-l" id="dr-sh-l">Validade do link</div>' +
        '<div class="dr-share-h" role="radiogroup" aria-labelledby="dr-sh-l"></div>' +
        (it.folder ? '<div class="dr-share-note" role="note" hidden><b>Sem prazo:</b> o link fica ativo até o revogares. Quem o tiver vê esta pasta e tudo o que lá puseres no futuro. Revoga-o em «Partilhados» ou nos detalhes da pasta.</div>' : '') +
        (priv.length ? '<div class="dr-share-warn" role="note"><b>Esta pasta tem documentos privados.</b><ul></ul><label class="check"><input type="checkbox" data-priv> Partilhar também estes documentos</label></div>' : '') +
        '<label class="check dr-share-pwl"><input type="checkbox" data-pw> Proteger com password</label>' +
        '<div class="dr-share-pw" hidden><div class="dr-share-row"><input type="text" data-pwv maxlength="100" autocomplete="off" spellcheck="false" aria-label="Password do link" placeholder="Mínimo 4 caracteres"><button type="button" class="btn ghost sm" data-pwgen>Gerar</button></div>' +
        '<p class="muted dr-share-pwn">Envia a password a quem recebe o link, por outra via. Não fica guardada em texto: não a consegues ver depois.</p></div>' +
        '<div class="dr-share-out" hidden></div>' +
        '<div class="dr-share-list"></div>' +
        '<div class="td-dialog-actions"><button type="button" class="btn ghost" data-x>Fechar</button><button type="button" class="btn" data-create>' + ic('link') + 'Criar link</button></div>';
      box.querySelector('h2 span').textContent = it.name;
      const grp = box.querySelector('.dr-share-h'), out = box.querySelector('.dr-share-out'), lst = box.querySelector('.dr-share-list');
      const create = box.querySelector('[data-create]');
      const privBox = box.querySelector('[data-priv]');
      const note = box.querySelector('.dr-share-note');
      const pwOn = box.querySelector('[data-pw]'), pwBox = box.querySelector('.dr-share-pw'), pwIn = box.querySelector('[data-pwv]');
      const pwOk = () => !pwOn.checked || pwIn.value.length >= 4;
      const syncCreate = () => { create.disabled = !pwOk() || !!(privBox && !privBox.checked); };
      pwOn.addEventListener('change', () => { pwBox.hidden = !pwOn.checked; if (pwOn.checked) pwIn.focus(); syncCreate(); });
      pwIn.addEventListener('input', syncCreate);
      box.querySelector('[data-pwgen]').onclick = () => {
        const a = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789', r = crypto.getRandomValues(new Uint32Array(12));
        pwIn.value = Array.from(r, v => a[v % a.length]).join('');
        syncCreate();
      };
      if (priv.length) {
        const ul = box.querySelector('.dr-share-warn ul');
        priv.slice(0, 5).forEach(r => ul.appendChild(TD.el('li', null, r)));
        if (priv.length > 5) ul.appendChild(TD.el('li', 'muted', 'e mais ' + (priv.length - 5) + '…'));
        create.disabled = true;
        privBox.addEventListener('change', syncCreate);
      }
      opts.forEach(([h, l]) => {
        const b = TD.el('button', { type: 'button', role: 'radio', class: 'dr-share-o', 'aria-checked': String(h === hours), 'data-h': h }, l);
        b.onclick = () => {
          hours = h;
          grp.querySelectorAll('[role=radio]').forEach(x => x.setAttribute('aria-checked', String(+x.dataset.h === h)));
          if (note) note.hidden = h !== 0;
        };
        grp.appendChild(b);
      });
      function paintList() {
        const act = S.shares.filter(s => s.k === key);
        lst.textContent = '';
        if (!act.length) return;
        lst.appendChild(TD.el('div', 'dr-share-l', 'Links ativos (' + act.length + ')'));
        act.forEach(s => lst.appendChild(shareRow(s, () => paintList())));
      }
      paintList();
      create.onclick = async () => {
        create.disabled = true; create.textContent = 'A criar…';
        if (hours !== 0) TD.store.set('drive.shareHours', hours);
        const pw = pwOn.checked ? pwIn.value : '';
        const permEl = box.querySelector('input[name=dr-sh-perm]:checked');
        const made = run({ kind: 'share', payload: hours === 0 ? { key, forever: true } : { key, hours } }, { quiet: true, includePrivate: !!(privBox && privBox.checked), password: pw, perm: permEl ? permEl.value : 'view' });
        // a cópia começa já, no clique (no Safari, copiar depois de esperar pelo servidor falha)
        const copied = TD.copyLink(made.then(x => { if (!x.ok) throw new Error(x.message); return x.url; }), { box: false, quiet: true });
        const r = await made;
        syncCreate(); create.innerHTML = ic('link') + 'Criar outro link';
        if (!r.ok) { TD.toast(r.message, 'err'); return; }
        out.hidden = false;
        out.innerHTML = '<label class="dr-share-l" for="dr-sh-url">Link criado' + (pw ? ' com password' : '') + ' · ' + (r.result.forever ? 'sem prazo' : 'expira ' + esc(TD.fmt.date(r.result.exp))) + '</label><div class="dr-share-row"><input id="dr-sh-url" type="text" readonly><button type="button" class="btn sm" data-copy>' + ic('copy') + 'Copiar</button></div><div class="dr-share-ok" role="status"></div>';
        const inp = out.querySelector('input'); inp.value = r.url;
        if (pw) { const pe = TD.el('div', 'dr-share-ok'); pe.textContent = 'Password: ' + pw; out.appendChild(pe); }
        const st = out.querySelector('.dr-share-ok');
        const said = c => { st.textContent = c.ok ? 'Copiado para a área de transferência.' : 'Selecione e copie o link.'; if (!c.ok) { inp.focus(); inp.setSelectionRange(0, inp.value.length); } };
        out.querySelector('[data-copy]').onclick = () => TD.copyLink(r.url, { box: false, quiet: true }).then(said);
        inp.addEventListener('focus', () => inp.setSelectionRange(0, inp.value.length));
        inp.focus(); inp.setSelectionRange(0, inp.value.length);
        copied.then(said);
        paintList();
      };
      box.querySelector('[data-x]').onclick = () => done(null);
    });
  }
  function shareRow(s, after) {
    const row = TD.el('div', 'dr-sh-row');
    const u = String(s.url || '');
    row.innerHTML = '<span class="dr-sh-i">' + ic('link') + '</span><span class="dr-sh-t"><span class="dr-sh-u" title="' + esc(u) + '">…' + esc(u.slice(-12)) + '</span>' +
      '<span class="dr-sh-m">' + esc(shareUntil(s)) + ' · ' + esc(n(s.downloads || 0, 'descarga', 'descargas')) + '</span></span>' +
      (TD.cfg.canEdit ? '<select data-perm aria-label="Permissão de quem tem o link"><option value="view">Só ver</option><option value="edit">Pode editar</option></select>' : '<span class="dr-sh-p">' + (s.perm === 'edit' ? 'Pode editar' : 'Só ver') + '</span>') +
      '<button type="button" class="btn ghost sm" data-c>Copiar</button><button type="button" class="dr-iconbtn danger" data-r aria-label="Revogar este link" title="Revogar">' + ic('close') + '</button>';
    row.querySelector('[data-c]').onclick = () => copyLink(u, s);
    const ps = row.querySelector('select[data-perm]');
    if (ps) {
      ps.value = s.perm === 'edit' ? 'edit' : 'view';
      ps.onchange = async () => {   // §32.2: muda a permissão; o link fica o mesmo
        const j = await TD.api({ do: 'share_update', id: s.id, perm: ps.value });
        if (!j.ok) { ps.value = s.perm === 'edit' ? 'edit' : 'view'; TD.toast(j.error || 'Não foi possível mudar a permissão.', 'err'); return; }
        s.perm = j.share.perm;
        S.shares = S.shares.map(x => x.id === s.id ? Object.assign({}, x, { perm: j.share.perm }) : x);
        TD.toast(j.share.perm === 'edit' ? 'Quem tem o link passa a poder editar (nunca apagar).' : 'O link passa a ser só para ver.', 'ok');
      };
    }
    row.querySelector('[data-r]').onclick = async () => { await revoke([s.id]); if (after) after(); };
    return row;
  }
  function shortcutsDialog() {
    const K = [
      ['Navegar', [['↑ ↓ ← →', 'Mover entre os itens (na grelha, nas duas direções)'], ['Enter', 'Abrir'], ['Backspace · Alt+↑', 'Pasta acima'], ['/ · ' + MODK + 'F', 'Pesquisar']]],
      ['Selecionar', [['Shift+↑↓ · Shift+clique', 'Alargar a seleção'], [MODK.replace('+', '') + '+clique', 'Juntar ou tirar um item'], [MODK + 'A', 'Selecionar tudo'], ['Esc', 'Limpar a seleção'], ['Arrastar no vazio', 'Selecionar com um retângulo']]],
      ['Ações', [['Espaço', 'Pré-visualizar'], ['F2', 'Renomear'], ['Delete', 'Enviar para o Lixo'], [MODK + 'C · ' + MODK + 'X · ' + MODK + 'V', 'Copiar, cortar e colar'], [MODK + 'Z', 'Anular a última operação'], ['I', 'Detalhes'], ['Shift+F10', 'Menu do item'], ['?', 'Esta lista']]],
      ['Arrastar', [['Arrastar para uma pasta', 'Mover'], ['… com Alt ou Ctrl', 'Copiar em vez de mover']]],
    ];
    TD.dialog.open((box, done) => {
      box.classList.add('dr-dlg', 'dr-keys');
      box.setAttribute('aria-label', 'Atalhos de teclado');
      let h = '<h2 class="td-dialog-title">Atalhos de teclado</h2><div class="dr-keys-g">';
      K.forEach(([g, rows]) => {
        h += '<section><h3>' + esc(g) + '</h3><dl>';
        rows.forEach(([k, d]) => { h += '<dt>' + k.split(' · ').map(x => '<kbd>' + esc(x) + '</kbd>').join(' ') + '</dt><dd>' + esc(d) + '</dd>'; });
        h += '</dl></section>';
      });
      box.innerHTML = h + '</div><div class="td-dialog-actions"><button type="button" class="btn">Fechar</button></div>';
      box.querySelector('.td-dialog-actions .btn').onclick = () => done(null);
    });
  }

  // ── painel de detalhes ──────────────────────────────────
  const infoCache = new Map();
  let detT = 0, infoSeq = 0;
  function openDetails(on) {
    S.details = on == null ? !S.details : !!on;
    TD.store.set('drive.details', S.details);
    renderBar();
    renderDetails(true);
    syncScrim();
    if (S.details && narrow()) { const c = detEl.querySelector('[data-det="close"]'); if (c) c.focus(); }
  }
  function scheduleDetails(force) {
    if (!S.details) { if (!detEl.hidden) renderDetails(); return; }
    clearTimeout(detT);
    detT = setTimeout(() => renderDetails(force), 60);
  }
  function detTarget() {
    const list = S.sel.size ? selItems() : [];
    if (list.length === 1) return { it: list[0] };
    if (list.length > 1) return { many: list };
    if (S.view === 'folder' && S.folders.has(S.prefix)) return { it: S.folders.get(S.prefix).item, here: true };
    return {};
  }
  function renderDetails(force) {
    detEl.hidden = !S.details;
    host.classList.toggle('has-details', S.details);
    root.classList.toggle('det-open', S.details);   // 1200–1679 px: os detalhes ficam no lugar do assistente (drive.css)
    if (!S.details) { detEl.innerHTML = ''; return; }
    const t = detTarget();
    let body = '';
    if (t.many) {
      const size = t.many.reduce((a, it) => a + Math.max(0, it.s || 0), 0);
      body = '<div class="dr-det-pv is-many">' + ic('copy') + '</div><div class="dr-det-name">' + esc(n(t.many.length, 'item selecionado', 'itens selecionados')) + '</div>' +
        '<dl class="dr-det-dl"><dt>Tamanho</dt><dd>' + esc(TD.fmt.size(size)) + '</dd><dt>Pastas</dt><dd>' + t.many.filter(x => x.folder).length + '</dd><dt>Ficheiros</dt><dd>' + t.many.filter(x => !x.folder).length + '</dd></dl>' +
        '<ul class="dr-det-many">' + t.many.slice(0, 12).map(it => '<li>' + ic(it.folder ? 'folder' : it.kind) + '<span>' + esc(it.name) + '</span></li>').join('') + (t.many.length > 12 ? '<li class="muted">e mais ' + (t.many.length - 12) + '…</li>' : '') + '</ul>';
    } else if (t.it) body = detailsBody(t.it, t.here);
    else body = '<div class="dr-det-empty">' + ic('info') + '<p>Selecione um ficheiro ou pasta para ver os detalhes.</p></div>';
    detEl.innerHTML = '<div class="dr-det-h"><span class="dr-det-title">Detalhes</span><button type="button" class="dr-iconbtn" data-det="close" aria-label="Fechar os detalhes" title="Fechar (i)">' + ic('close') + '</button></div><div class="dr-det-b">' + body + '</div>';
    if (t.it && fileView() && !t.it.trash) fetchInfo(realKey(t.it), force);
  }
  function detailsBody(it, here) {
    const key = realKey(it);
    const v = S.view;
    const kind = it.folder ? 'folder' : it.kind;
    const img = !it.folder && thumbUrl(it.e);
    let h = '<div class="dr-det-pv k-' + kind + (img ? ' has-img' : '') + '"' + (it.e && !it.folder ? ' data-e="' + esc(it.e) + '"' : '') + '>' + (img ? '<img src="' + esc(img) + '" alt="">' : ic(kind)) + '</div>' +
      '<div class="dr-det-name">' + esc(it.name || 'Drive') + (isRoot(key) ? ' <span class="dr-tag">Pasta base</span>' : '') + '</div>';
    if (v === 'trash') {
      h += '<dl class="dr-det-dl"><dt>Estava em</dt><dd>' + esc(pretty(it.loc)) + '</dd><dt>Apagado</dt><dd>' + esc(TD.fmt.date(it.deleted)) + '</dd>' +
        '<dt>Apagado de vez</dt><dd>' + esc(it.expires ? TD.fmt.day(it.expires) : '—') + (it.left != null ? ' (' + esc(n(Math.max(0, it.left), 'dia', 'dias')) + ')' : '') + '</dd>' +
        '<dt>Tamanho</dt><dd>' + esc(TD.fmt.size(it.s)) + (it.folder ? ' · ' + esc(n(it.files, 'ficheiro', 'ficheiros')) : '') + '</dd></dl>' +
        '<div class="dr-det-acts"><button type="button" class="btn sm" data-det="restore">' + ic('restore') + 'Restaurar</button></div>';
      return h;
    }
    const fav = S.favs.has(key);
    const real = itemOf(key) || it;
    if (key !== '') {
      h += '<div class="dr-det-acts">' +
        '<button type="button" class="dr-mini-btn' + (fav ? ' on' : '') + '" data-det="fav" aria-pressed="' + fav + '">' + ic('star') + '<span>' + (fav ? 'Favorito' : 'Favorito') + '</span></button>' +
        (isRoot(key) ? '' : '<button type="button" class="dr-mini-btn" data-det="share">' + ic('link') + '<span>Partilhar</span></button>') +
        '<button type="button" class="dr-mini-btn" data-det="download">' + ic('download') + '<span>' + (it.folder ? 'ZIP' : 'Descarregar') + '</span></button>' +
        (here ? '' : '<button type="button" class="dr-mini-btn" data-det="open">' + ic(it.folder ? 'folder' : 'eye') + '<span>Abrir</span></button>') + '</div>';
    }
    h += '<dl class="dr-det-dl">';
    h += '<dt>Tipo</dt><dd>' + esc(it.folder ? (key === '' ? 'Drive' : 'Pasta') : kindLabel(real)) + '</dd>';
    h += '<dt>Tamanho</dt><dd>' + esc(TD.fmt.size(real.s)) + (!it.folder && real.s >= 1024 ? ' <span class="muted">(' + Number(real.s).toLocaleString('pt-PT') + ' bytes)</span>' : '') + '</dd>';
    if (it.folder) {
      const f = S.folders.get(key);
      if (f) h += '<dt>Conteúdo</dt><dd><span data-info="files">' + esc(n(f.nfiles, 'ficheiro', 'ficheiros')) + '</span>' + (f.kids.length ? ' · ' + esc(n(f.kids.length, 'subpasta', 'subpastas')) : '') + '</dd>';
    }
    if (real.m) h += '<dt>Alterado</dt><dd>' + esc(TD.fmt.date(real.m)) + '</dd>';
    if (key !== '') {
      h += '<dt>Localização</dt><dd><button type="button" class="lnk dr-det-loc" data-det="loc">' + esc(pretty(P.parent(key))) + '</button></dd>';
      h += '<dt>Caminho</dt><dd class="dr-det-path"><code>' + esc(key).replace(/\//g, '/<wbr>') + '</code><button type="button" class="dr-iconbtn" data-det="path" aria-label="Copiar o caminho" title="Copiar o caminho">' + ic('copy') + '</button></dd>';
    }
    h += '</dl>';
    if (key !== '' && !isRoot(key)) {
      const act = S.shares.filter(s => s.k === key);
      h += '<section class="dr-det-sec"><h3>Links de partilha</h3><div class="dr-det-shares" data-shares></div>' +
        (act.length ? '' : '<p class="muted">Nenhum link ativo.</p>') +
        '<button type="button" class="btn ghost sm" data-det="share">' + ic('link') + 'Criar link…</button></section>';
      if (!it.folder) h += '<section class="dr-det-sec" data-versions hidden><h3>Versões anteriores</h3><ul class="dr-det-vers"></ul></section>';
    }
    if (key !== '') {
      if (!it.folder) h += '<section class="dr-det-sec"><h3>Assistente</h3><div class="dr-det-idx" data-index><span class="muted">A ver…</span></div></section>';
    }
    return h;
  }
  function paintDetailShares() {
    const box = detEl.querySelector('[data-shares]');
    const t = detTarget();
    if (!box || !t.it) return;
    const key = realKey(t.it);
    box.textContent = '';
    S.shares.filter(s => s.k === key).forEach(s => box.appendChild(shareRow(s)));
  }
  /** As versões anteriores (§32.2: um link «Pode editar» substituiu o ficheiro); só aparece quando há. */
  function fetchVersions(key) {
    const sec = detEl.querySelector('[data-versions]');
    if (!sec) return;
    TD.api({ do: 'versions', key }).then(j => {
      const t = detTarget();
      if (!j.ok || !j.items || !j.items.length || !t.it || realKey(t.it) !== key) return;
      const ul = sec.querySelector('ul');
      ul.innerHTML = j.items.map(v => '<li><span>' + esc(TD.fmt.date(v.at)) + (v.by ? ' · ' + esc(v.by) : '') + ' · ' + esc(TD.fmt.size(v.size)) + '</span>' +
        '<a class="btn ghost sm" href="' + esc(v.url) + '" rel="noopener">' + ic('download') + 'Descarregar</a></li>').join('');
      sec.hidden = false;
    });
  }
  function fetchInfo(key, force) {
    paintDetailShares();
    fetchVersions(key);
    const box = detEl.querySelector('[data-index]');
    const c = infoCache.get(key);
    if (c && !force && Date.now() - c.t < 60000) { paintInfo(key, c.j); return; }
    const seq = ++infoSeq;
    TD.api({ do: 'info', key }).then(j => {
      if (seq !== infoSeq) return;
      if (j.ok) infoCache.set(key, { t: Date.now(), j });
      paintInfo(key, j);
    });
    if (box && !c) box.innerHTML = '<span class="muted">A ver…</span>';
  }
  const IDX = { done: 'Lido', pending: 'Na fila para ser lido', skip: 'Não é lido (vídeo, áudio ou outro formato)', error: 'Erro ao ler', toobig: 'Demasiado grande para ser lido', private: 'Privado: o assistente não lê esta pasta' };
  const METHOD = { text: 'texto', office: 'documento Office', ai: 'lido pela IA', none: '' };
  function paintInfo(key, j) {
    const t = detTarget();
    if (!t.it || realKey(t.it) !== key) return;
    const box = detEl.querySelector('[data-index]');
    if (j && j.ok && Array.isArray(j.shares)) {
      // as partilhas do servidor (com o nº de descargas) mandam
      const others = S.shares.filter(s => s.k !== key);
      S.shares = others.concat(j.shares.map(s => Object.assign({ k: key }, s)));
      paintDetailShares();
    }
    const fe = detEl.querySelector('[data-info="files"]');
    if (fe && j && j.ok && j.folder) fe.textContent = n(j.folder.files, 'ficheiro', 'ficheiros') + ' · ' + TD.fmt.size(j.folder.size);
    if (!box) return;
    if (!j || !j.ok) { box.innerHTML = '<span class="muted">' + esc((j && j.error) || 'Sem informação.') + '</span>'; return; }
    const ix = j.index;
    if (!ix) { box.innerHTML = '<span class="muted">' + (S.index ? 'Ainda não foi lido pelo assistente.' : 'O índice de conteúdo não está ativo.') + '</span>'; return; }
    let h = '<div class="dr-idx-st st-' + esc(ix.status) + '">' + esc(IDX[ix.status] || ix.status) + (ix.status === 'done' && METHOD[ix.method] ? ' · ' + esc(METHOD[ix.method]) : '') + (ix.indexed_at ? ' · ' + esc(TD.fmt.rel(ix.indexed_at)) : '') + '</div>';
    if (ix.summary) h += '<p class="dr-idx-sum">' + esc(ix.summary) + '</p>';
    box.innerHTML = h;
  }
  detEl.addEventListener('click', e => {
    const b = e.target.closest('[data-det]');
    if (!b) return;
    const a = b.dataset.det;
    if (a === 'close') { openDetails(false); return; }
    const t = detTarget();
    const it = t.it;
    if (!it) return;
    const key = realKey(it);
    if (a === 'fav') setFav([it], !S.favs.has(key));
    else if (a === 'share') shareDialog(key);
    else if (a === 'download') download([itemOf(key) || it]);
    else if (a === 'open') openItem(it);
    else if (a === 'loc') TD.app.reveal(key);
    else if (a === 'path') copyPath(key);
    else if (a === 'restore') restoreItems([it]);
  });

  // ── pesquisa ────────────────────────────────────────────
  let searchT = 0;
  function onSearchInput() {
    const q = qEl.value.trim().slice(0, 200);
    qX.hidden = !qEl.value;
    clearTimeout(searchT);
    if (!q) { if (S.view === 'search') leaveSearch(); return; }
    searchT = setTimeout(() => {
      const first = S.view !== 'search';
      if (first) { S.before = { view: S.view, prefix: S.prefix }; S.scope = 'all'; if (S.view !== 'folder') S.prefix = ''; }
      S.q = q; S.view = 'search'; S.hitsAll = false;
      S.sel.clear(); S.anchor = -1; S.focus = -1;
      pushUrl(!first);
      computeItems();
      scrollEl.scrollTop = 0;
      renderList(); renderCrumbs(); renderStrip(); renderTree(); renderShortcuts(); renderBar(); scheduleDetails();
      contentSearch();
    }, S.flat.length > 3000 ? 90 : 40);
  }
  function leaveSearch() {
    const b = S.before || { view: 'folder', prefix: S.prefix };
    S.before = null;
    go({ view: b.view, prefix: b.prefix }, { replace: true });
  }
  function contentSearch() {
    clearTimeout(S.hitsT);
    const seq = ++S.hitsSeq;
    const q = S.q;
    if (fold(q).replace(/\s+/g, '').length < 2) { S.hits = null; S.hitsBusy = false; renderHits(); return; }
    S.hitsT = setTimeout(async () => {
      S.hitsBusy = true; qSpin.hidden = false;
      const body = { do: 'search', q, limit: 30 };
      if (S.scope === 'here' && S.prefix) body.prefix = S.prefix;
      const j = await TD.api(body);
      if (seq !== S.hitsSeq || S.view !== 'search') return;
      S.hitsBusy = false; qSpin.hidden = true;
      S.hits = j.ok && Array.isArray(j.items) ? j.items.filter(h => h && typeof h.k === 'string' && (S.byKey.has(h.k) || S.folders.has(h.k))) : [];
      renderHits();
    }, 300);
  }
  function renderHits() {
    qSpin.hidden = !S.hitsBusy || S.view !== 'search';
    const hits = S.view === 'search' ? S.hits : null;
    // a lista sem nomes mostra um estado vazio que depende dos resultados no conteúdo
    if (S.view === 'search' && S.loaded && !S.items.length) itemsEl.innerHTML = emptyHtml();
    if (!hits || !hits.length) { hitsEl.hidden = true; hitsEl.innerHTML = ''; return; }
    const ts = terms();
    const show = S.hitsAll ? hits : hits.slice(0, 4);
    let h = '<div class="dr-hits-h">' + ic('sparkle') + '<span>No conteúdo dos ficheiros</span><span class="dr-count">' + hits.length + '</span></div><ul class="dr-hits-l">';
    show.forEach(x => {
      const it = itemOf(x.k);
      const kind = it ? (it.folder ? 'folder' : it.kind) : TD.kind(x.k).id;
      h += '<li><button type="button" class="dr-hit" data-k="' + esc(x.k) + '"><span class="dr-hit-ic k-' + kind + '">' + ic(kind) + '</span><span class="dr-hit-t">' +
        '<span class="dr-hit-n">' + highlight(x.name || P.base(x.k), ts) + '<span class="dr-where">' + esc(pretty(P.parent(x.k))) + '</span></span>' +
        (x.snippet ? '<span class="dr-hit-s">' + highlight(x.snippet, ts) + '</span>' : '') +
        (x.summary && !x.snippet ? '<span class="dr-hit-s">' + esc(x.summary) + '</span>' : '') + '</span></button></li>';
    });
    h += '</ul>';
    if (hits.length > 4) h += '<button type="button" class="lnk dr-hits-more" data-hits="more">' + (S.hitsAll ? 'Mostrar menos' : 'Mostrar todos (' + hits.length + ')') + '</button>';
    hitsEl.innerHTML = h;
    hitsEl.hidden = false;
  }
  hitsEl.addEventListener('click', e => {
    if (e.target.closest('[data-hits="more"]')) { S.hitsAll = !S.hitsAll; renderHits(); return; }
    const b = e.target.closest('.dr-hit');
    if (b) TD.app.openFile(b.dataset.k);
  });
  hitsEl.addEventListener('contextmenu', e => {
    const b = e.target.closest('.dr-hit');
    if (!b) return;
    e.preventDefault(); e.stopPropagation();
    const k = b.dataset.k;
    openMenu([
      { label: 'Abrir', icon: 'open', fn: () => TD.app.openFile(k) },
      { label: 'Mostrar na pasta', icon: 'folder', fn: () => TD.app.reveal(k) },
      { label: 'Copiar caminho (não é um link)', icon: 'copy', fn: () => copyPath(k) },
    ], { x: e.clientX, y: e.clientY });
  });
  qEl.addEventListener('input', onSearchInput);
  qEl.addEventListener('focus', () => { root.classList.add('q-focus'); renderBar(); });
  qEl.addEventListener('blur', () => { root.classList.remove('q-focus'); setTimeout(renderBar, 150); });
  qEl.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
      e.preventDefault(); e.stopPropagation();
      if (qEl.value) { qEl.value = ''; onSearchInput(); } else qEl.blur();
    } else if (e.key === 'ArrowDown' || e.key === 'Enter') {
      if (e.key === 'Enter') { e.preventDefault(); clearTimeout(searchT); if (qEl.value.trim() && (S.view !== 'search' || S.q !== qEl.value.trim())) { onSearchInput(); return; } }
      if (S.items.length) { e.preventDefault(); selectOnly(0); setFocus(0); applySel(); }
      else if (S.view === 'search') {
        // só há resultados no conteúdo: o teclado segue para o primeiro
        const h = hitsEl.querySelector('.dr-hit');
        if (h) { e.preventDefault(); h.focus(); }
      }
    }
  });
  // ↑/↓ entre os resultados no conteúdo; ↑ no primeiro volta à caixa de pesquisa
  hitsEl.addEventListener('keydown', e => {
    if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
    const all = Array.from(hitsEl.querySelectorAll('.dr-hit'));
    const i = all.indexOf(document.activeElement);
    if (i < 0) return;
    e.preventDefault(); e.stopPropagation();
    if (e.key === 'ArrowDown') { if (all[i + 1]) all[i + 1].focus(); else if (S.items.length) { selectOnly(0); setFocus(0); applySel(); } }
    else if (i > 0) all[i - 1].focus();
    else qEl.focus();
  });
  $('.dr-search').addEventListener('submit', e => e.preventDefault());
  qX.addEventListener('click', () => { qEl.value = ''; onSearchInput(); qEl.focus(); });
  scopeBtn.addEventListener('mousedown', e => e.preventDefault());
  scopeBtn.addEventListener('click', () => {
    S.scope = S.scope === 'here' ? 'all' : 'here';
    if (S.view === 'search') { pushUrl(true); computeItems(); renderList(); renderStrip(); contentSearch(); }
    renderBar();
    qEl.focus();
  });
  function focusSearch() { qEl.focus(); qEl.select(); }

  // ── eventos da lista ────────────────────────────────────
  document.addEventListener('pointerdown', e => { S.pointer = e.pointerType || 'mouse'; }, true);
  itemsEl.addEventListener('click', e => {
    const em = e.target.closest('[data-empty]');
    if (em) { emptyAction(em.dataset.empty); return; }
    const row = e.target.closest('[data-i]');
    if (!row) return;
    const i = +row.dataset.i, it = S.items[i];
    if (!it) return;
    const b = e.target.closest('[data-act]');
    if (b) { e.stopPropagation(); rowAction(b.dataset.act, it, i); return; }
    if (e.target.closest('.dr-check')) { toggleSel(i); return; }
    if (S.pointer === 'touch' && !e.shiftKey && !e.ctrlKey && !e.metaKey) {
      if (S.sel.size) toggleSel(i); else { S.focus = i; setRoving(i); openItem(it); }
      return;
    }
    clickSel(i, e);
  });
  itemsEl.addEventListener('dblclick', e => {
    if (e.target.closest('[data-act], .dr-check, input')) return;
    const row = e.target.closest('[data-i]');
    if (row && S.pointer !== 'touch') openItem(S.items[+row.dataset.i]);
  });
  itemsEl.addEventListener('focusin', e => {
    const r = e.target.closest('[data-i]');
    if (r && S.els.get(+r.dataset.i) === r) { S.focus = +r.dataset.i; setRoving(S.focus); }
  });
  function rowAction(a, it, i) {
    if (a === 'fav') setFav([it], !S.favs.has(it.k));
    else if (a === 'restore') restoreItems([it]);
    else if (a === 'purge') purgeItems([it]);
    else if (a === 'copylink') copyLink(it.share.url, it.share);
    else if (a === 'revoke') revoke([it.share.id]);
  }
  function emptyAction(a) {
    if (a === 'retry') { S.error = ''; renderList(); load(true); }
    else if (a === 'upload') pickFiles(S.prefix);
    else if (a === 'mkdir') newFolder(S.prefix);
    else if (a === 'nofilter') setFilter('all');
    else if (a === 'root') go({ view: 'folder', prefix: '' });
    else if (a === 'scopeall') { S.scope = 'all'; pushUrl(true); computeItems(); renderList(); renderStrip(); renderBar(); contentSearch(); }
  }
  headEl.addEventListener('click', e => {
    const s = e.target.closest('[data-sort]');
    if (s) { const by = s.dataset.sort; setSort(by, S.sort.by === by ? -S.sort.dir : (by === 'name' || by === 'kind' ? 1 : -1)); return; }
    if (e.target.closest('[data-hcheck]')) { if (S.sel.size === S.items.length) clearSel(); else selectAll(); }
  });
  // toque longo (500 ms, sem arrastar) num item → o mesmo menu do clique direito; o toque que se segue não abre o item
  let lp = null, lpFired = false;
  itemsEl.addEventListener('touchstart', e => {
    const row = e.target.closest('[data-i]');
    if (!row || e.touches.length !== 1 || e.target.closest('[data-act], .dr-check, input')) return;
    const t0 = e.touches[0], x = t0.clientX, y = t0.clientY;
    lpFired = false;
    clearTimeout(lp && lp.t);
    lp = { x, y, t: setTimeout(() => {
      const i = +row.dataset.i;
      if (!S.items[i]) return;
      lpFired = true;
      if (!S.sel.has(S.items[i].k)) { selectOnly(i); applySel(); }
      setFocus(i, { scroll: false });
      itemMenu({ x, y });
    }, 500) };
  }, { passive: true });
  itemsEl.addEventListener('touchmove', e => { if (lp && e.touches[0] && Math.hypot(e.touches[0].clientX - lp.x, e.touches[0].clientY - lp.y) > 10) { clearTimeout(lp.t); lp = null; } }, { passive: true });
  itemsEl.addEventListener('touchend', e => { if (lp) { clearTimeout(lp.t); lp = null; } if (lpFired) e.preventDefault(); });
  itemsEl.addEventListener('click', e => { if (lpFired) { lpFired = false; e.stopImmediatePropagation(); e.preventDefault(); } }, true);
  scrollEl.addEventListener('contextmenu', e => {
    if (e.target.closest('input, textarea, .dr-hits, .dr-quick')) return;
    e.preventDefault();
    const row = e.target.closest('.dr-items [data-i]');
    if (row) {
      const i = +row.dataset.i;
      if (!S.sel.has(S.items[i].k)) { selectOnly(i); applySel(); }
      setFocus(i, { scroll: false });
      itemMenu({ x: e.clientX, y: e.clientY });
    } else {
      clearSel();
      bgMenu({ x: e.clientX, y: e.clientY });
    }
  });
  stripEl.addEventListener('click', e => {
    const c = e.target.closest('[data-chip]');
    if (c) {
      const a = c.dataset.chip;
      if (a === 'filter') filterMenu(c);
      else if (a === 'sort') sortMenu(c);
      else if (a === 'nofilter') setFilter('all');
      else if (a === 'empty') emptyTrash();
      return;
    }
    const b = e.target.closest('[data-sb]');
    if (!b) return;
    const a = b.dataset.sb, list = selItems();
    if (a === 'clear') { clearSel(); return; }
    if (!list.length) return;
    if (a === 'download') download(list);
    else if (a === 'move') moveTo(list, 'move');
    else if (a === 'copyto') moveTo(list, 'copy');
    else if (a === 'quicklink') quickLink(realKey(list[0]));
    else if (a === 'share') shareDialog(realKey(list[0]));
    else if (a === 'trash') trashItems(list);
    else if (a === 'restore') restoreItems(list);
    else if (a === 'purge') purgeItems(list);
    else if (a === 'copylink') copyLink(list[0].share.url, list[0].share);
    else if (a === 'revoke') revoke(list.map(x => x.share.id));
    else if (a === 'more') itemMenu({ anchor: b, align: 'right' });
  });
  crumbsEl.addEventListener('click', e => {
    if (e.target.closest('[data-crmore]')) {
      const hidden = JSON.parse(crumbsEl.dataset.hidden || '[]');
      openMenu(hidden.map(p => ({ label: P.base(p), icon: 'folder', fn: () => go({ view: 'folder', prefix: p }) })), { anchor: e.target.closest('[data-crmore]') });
      return;
    }
    const c = e.target.closest('[data-crumb]');
    if (!c) return;
    if (c.hasAttribute('data-crmenu')) folderMenu(c);
    else go({ view: 'folder', prefix: c.dataset.crumb });
  });
  histBack.addEventListener('click', () => { if (S.hist > 0) history.back(); });
  histFwd.addEventListener('click', () => { if (S.hist < S.histMax) history.forward(); });
  histUp.addEventListener('click', up);
  host.querySelectorAll('[data-layout]').forEach(b => b.addEventListener('click', () => setLayout(b.dataset.layout)));
  infoBtn.addEventListener('click', () => openDetails());
  newBtn.addEventListener('click', newMenu);
  scEl.addEventListener('click', e => {
    const a = e.target.closest('[data-v]');
    if (!a || e.ctrlKey || e.metaKey || e.shiftKey || e.button === 1) return;
    e.preventDefault();
    go({ view: a.dataset.v, prefix: '' });
  });

  // ── árvore: rato e teclado ──────────────────────────────
  treeEl.addEventListener('click', e => {
    const nd = e.target.closest('.dr-tn');
    if (!nd) return;
    const p = nd.dataset.k;
    if (e.target.closest('[data-tw]') && nd.hasAttribute('aria-expanded')) { toggleNode(p); return; }
    go({ view: 'folder', prefix: p });
  });
  treeEl.addEventListener('keydown', e => {
    const nd = e.target.closest('.dr-tn');
    if (!nd || e.ctrlKey || e.metaKey || e.altKey) return;
    const nodes = Array.from(treeEl.querySelectorAll('.dr-tn'));
    const i = nodes.indexOf(nd), p = nd.dataset.k;
    const exp = nd.getAttribute('aria-expanded');
    const focusN = j => { const t = nodes[Math.max(0, Math.min(nodes.length - 1, j))]; if (!t) return; nodes.forEach(x => { x.tabIndex = -1; }); t.tabIndex = 0; t.focus(); scrollNearest(treeEl.parentNode, t, 0); };
    const k = e.key;
    if (k === 'ArrowDown') focusN(i + 1);
    else if (k === 'ArrowUp') focusN(i - 1);
    else if (k === 'Home') focusN(0);
    else if (k === 'End') focusN(nodes.length - 1);
    else if (k === 'ArrowRight') { if (exp === 'false') toggleNode(p, true); else if (exp === 'true') focusN(i + 1); else return; }
    else if (k === 'ArrowLeft') {
      if (exp === 'true') toggleNode(p, false);
      else { const lvl = +nd.getAttribute('aria-level'); for (let j = i - 1; j >= 0; j--) if (+nodes[j].getAttribute('aria-level') < lvl) { focusN(j); break; } }
    } else if (k === 'Enter' || k === ' ') go({ view: 'folder', prefix: p });
    else return;
    e.preventDefault(); e.stopPropagation();
  });
  treeEl.addEventListener('contextmenu', e => {
    const nd = e.target.closest('.dr-tn');
    if (!nd) return;
    e.preventDefault();
    const p = nd.dataset.k;
    const it = itemOf(p);
    const base = p === '' || isRoot(p);
    openMenu([
      { label: 'Abrir', icon: 'folder', fn: () => go({ view: 'folder', prefix: p }) },
      { label: 'Nova pasta aqui', icon: 'newfolder', fn: () => newFolder(p) },
      { label: 'Enviar ficheiros para aqui', icon: 'upload', disabled: !canUpload(), fn: () => pickFiles(p) },
      S.clip && { label: 'Colar aqui', icon: 'paste', fn: () => paste(p) },
      '-',
      it && { label: 'Descarregar como ZIP', icon: 'zip', fn: () => downloadZip([it]) },
      it && !base && { label: 'Partilhar link…', icon: 'link', fn: () => shareDialog(p) },
      it && !base && { label: 'Renomear…', icon: 'edit', fn: () => renameDialog(it) },
      it && !base && { label: 'Mover para…', icon: 'move', fn: () => moveTo([it], 'move') },
      it && { label: 'Copiar caminho (não é um link)', icon: 'copy', fn: () => copyPath(p) },
      it && !base && '-',
      it && !base && { label: 'Enviar para o Lixo', icon: 'trash', danger: true, fn: () => trashItems([it]) },
    ], { x: e.clientX, y: e.clientY }, { label: 'Pasta «' + pretty(p) + '»' });
  });

  // ── teclado (atalhos globais do Drive) ──────────────────
  function gridCols() {
    const g = gridA || gridF;
    if (!g) return 1;
    const t = getComputedStyle(g).gridTemplateColumns;
    return Math.max(1, t && t !== 'none' ? t.split(' ').filter(Boolean).length : 1);
  }
  function gridMove(i, d) {
    const cols = gridCols(), nf = S.nFolders, len = S.items.length;
    if (i < nf) {
      const col = i % cols, row = Math.floor(i / cols), lastRow = Math.floor((nf - 1) / cols);
      if (d > 0) { if (i + cols < nf) return i + cols; if (row < lastRow) return nf - 1; return nf < len ? Math.min(nf + col, len - 1) : i; }
      return i - cols >= 0 ? i - cols : i;
    }
    const k = i - nf, col = k % cols, row = Math.floor(k / cols), lastRow = Math.floor((len - nf - 1) / cols);
    if (d > 0) { if (i + cols < len) return i + cols; return row < lastRow ? len - 1 : i; }
    if (k - cols >= 0) return i - cols;
    if (!nf) return i;
    return Math.min(Math.floor((nf - 1) / cols) * cols + col, nf - 1);
  }
  function pageSize() {
    const s = secOf(Math.max(0, S.focus)) || secs[0];
    if (!s) return 10;
    return Math.max(1, Math.floor((scrollEl.clientHeight - headH()) / s.rowH)) * s.cols;
  }
  function moveFocus(key, e) {
    const len = S.items.length;
    if (!len) return;
    let i = S.focus;
    let j;
    if (i < 0) j = key === 'End' ? len - 1 : 0;
    else if (key === 'Home') j = 0;
    else if (key === 'End') j = len - 1;
    else if (key === 'PageDown') j = Math.min(len - 1, i + pageSize());
    else if (key === 'PageUp') j = Math.max(0, i - pageSize());
    else if (useGrid()) {
      if (key === 'ArrowLeft') j = Math.max(0, i - 1);
      else if (key === 'ArrowRight') j = Math.min(len - 1, i + 1);
      else j = gridMove(i, key === 'ArrowDown' ? 1 : -1);
    } else {
      if (key === 'ArrowLeft' || key === 'ArrowRight') return;
      j = key === 'ArrowDown' ? Math.min(len - 1, i + 1) : Math.max(0, i - 1);
    }
    const mod = e.ctrlKey || e.metaKey;
    if (e.shiftKey) { if (S.anchor < 0) S.anchor = i < 0 ? j : i; selectRange(S.anchor, j, mod); }
    else if (!mod) selectOnly(j);
    setFocus(j);
    applySel();
  }
  function isTyping(t) { return t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName)); }
  /** Há um diálogo do core aberto? (os diálogos ficam no fim do <body>; não percorre a lista toda) */
  function dialogOpen() {
    for (let c = document.body.lastElementChild; c; c = c.previousElementSibling) if (c.classList.contains('td-dialog-back')) return true;
    return false;
  }
  document.addEventListener('keydown', e => {
    if (e.defaultPrevented || e.isComposing || menuEl) return;
    if (dialogOpen()) return;
    if (TD.preview && TD.preview.isOpen && TD.preview.isOpen()) return;
    const t = e.target, k = e.key, mod = e.ctrlKey || e.metaKey;
    // painéis deslizantes: Esc fecha (mesmo com o foco lá dentro)
    if (k === 'Escape' && (!isTyping(t) || (t.closest && t.closest('#drive-chat'))) && closeOverlays()) { e.preventDefault(); return; }
    if (t && t.closest && t.closest('#drive-chat, #drive-upload-dock, #drive-preview, .td-toasts')) return;
    if (isTyping(t)) return;
    if ((k === '/' && !mod && !e.altKey) || (mod && !e.shiftKey && !e.altKey && k.toLowerCase() === 'f')) { e.preventDefault(); focusSearch(); return; }
    if (k === '?' && !mod) { e.preventDefault(); shortcutsDialog(); return; }
    if (mod && !e.shiftKey && k.toLowerCase() === 'z') { if (S.undo) { e.preventDefault(); const u = S.undo; S.undo = null; u(); } return; }
    if (!S.loaded) return;
    const onItems = t === document.body || t === scrollEl || t === host || itemsEl.contains(t);
    if (!onItems) return;
    const cur = S.items[S.focus];
    const fv = fileView();
    if (/^(ArrowUp|ArrowDown|ArrowLeft|ArrowRight|Home|End|PageUp|PageDown)$/.test(k) && !(e.altKey && k === 'ArrowUp')) { e.preventDefault(); moveFocus(k, e); return; }
    if ((k === 'Backspace' && !mod) || (e.altKey && k === 'ArrowUp')) { e.preventDefault(); up(); return; }
    if (k === 'Enter' && !mod) { if (cur) { e.preventDefault(); openItem(selItems().length === 1 ? selItems()[0] : cur); } return; }
    if (k === ' ' && !mod) {
      e.preventDefault();
      if (cur && !cur.folder && fv) preview(cur);
      else if (cur) toggleSel(S.focus);
      return;
    }
    if (k === ' ' && mod && cur) { e.preventDefault(); toggleSel(S.focus); return; }
    if (k === 'Escape') { if (clearSel()) e.preventDefault(); else if (S.view === 'search') { e.preventDefault(); qEl.value = ''; onSearchInput(); } return; }
    if (k === 'Delete' || (MAC && mod && k === 'Backspace')) {
      const list = selItems(); if (!list.length) return;
      e.preventDefault();
      if (S.view === 'trash') purgeItems(list);
      else if (S.view === 'shared') revoke(list.map(x => x.share.id));
      else trashItems(list);
      return;
    }
    if (k === 'F2') { if (cur && fv) { e.preventDefault(); startRename(S.focus); } return; }
    if (mod && !e.shiftKey && !e.altKey) {
      const lk = k.toLowerCase();
      if (lk === 'a') { e.preventDefault(); selectAll(); return; }
      if ((lk === 'c' || lk === 'x') && fv) {
        if (String(window.getSelection && window.getSelection()) !== '') return;   // texto selecionado: cópia normal
        const list = selItems(); if (!list.length) return;
        e.preventDefault(); clipSet(lk === 'c' ? 'copy' : 'cut', list); return;
      }
      if (lk === 'v' && S.clip) { e.preventDefault(); paste(S.view === 'folder' ? S.prefix : null); return; }
    }
    if ((k === 'i' || k === 'I') && !mod && !e.altKey) { e.preventDefault(); openDetails(); return; }
    if (k === 'ContextMenu' || (k === 'F10' && e.shiftKey)) {
      e.preventDefault();
      if (cur) setFocus(S.focus);
      const el = S.els.get(S.focus);
      if (cur && el) { if (!S.sel.has(cur.k)) { selectOnly(S.focus); applySel(); } const r = el.getBoundingClientRect(); itemMenu({ x: r.left + 40, y: r.top + r.height / 2 }); }
      else { const r = scrollEl.getBoundingClientRect(); bgMenu({ x: r.left + 20, y: r.top + 20 }); }
    }
  });

  // ── seleção por retângulo ───────────────────────────────
  let band = null;
  scrollEl.addEventListener('mousedown', e => {
    if (e.button !== 0 || !S.loaded || !S.items.length || S.view === 'shared') return;
    if (e.target.closest('.dr-items [data-i], .dr-head, .dr-hits, .dr-quick, button, a, input, .dr-empty')) return;
    if (e.offsetX > scrollEl.clientWidth && e.target === scrollEl) return;   // a barra de rolar
    e.preventDefault();
    if (document.activeElement && document.activeElement !== document.body && !itemsEl.contains(document.activeElement)) document.activeElement.blur();
    const r = scrollEl.getBoundingClientRect();
    band = {
      x0: e.clientX - r.left + scrollEl.scrollLeft, y0: e.clientY - r.top + scrollEl.scrollTop,
      cx: e.clientX, cy: e.clientY, add: e.ctrlKey || e.metaKey || e.shiftKey, base: new Set(S.sel), moved: false, raf: 0,
    };
    document.addEventListener('mousemove', bandMove);
    document.addEventListener('mouseup', bandEnd);
  });
  /** Itens dentro do retângulo [L,T]–[R,B] (coordenadas do conteúdo), pelas contas — incluindo os que não estão no DOM. */
  function bandHits(L, T, R, B, sel) {
    let last = -1;
    for (const s of secs) {
      const top = secTop(s), left = secLeft(s), rows = Math.ceil(s.n / s.cols), cardH = s.rowH - s.gapY;
      const r0 = Math.max(0, Math.floor((T - top) / s.rowH)), r1 = Math.min(rows - 1, Math.floor((B - top) / s.rowH));
      for (let r = r0; r <= r1; r++) {
        const y0 = top + r * s.rowH;
        if (y0 > B || y0 + cardH < T) continue;
        for (let c = 0; c < s.cols; c++) {
          const j = r * s.cols + c;
          if (j >= s.n) break;
          if (s.grid) { const x0 = left + c * (s.colW + s.gapX); if (x0 > R || x0 + s.colW < L) continue; }
          sel.add(S.items[s.s0 + j].k); last = s.s0 + j;
        }
      }
    }
    return last;
  }
  function bandMove(e) {
    if (!band) return;
    band.cx = e.clientX; band.cy = e.clientY;
    if (!band.raf) band.raf = requestAnimationFrame(bandTick);
  }
  function bandTick() {
    if (!band) return;
    band.raf = 0;
    const r = scrollEl.getBoundingClientRect();
    // rolar sozinho perto das margens
    let auto = 0;
    if (band.cy > r.bottom - 28) auto = Math.min(24, (band.cy - (r.bottom - 28)) / 2 + 4);
    else if (band.cy < r.top + headH() + 28) auto = -Math.min(24, ((r.top + headH() + 28) - band.cy) / 2 + 4);
    if (auto) scrollEl.scrollTop += auto;
    const x1 = Math.max(0, Math.min(band.cx - r.left + scrollEl.scrollLeft, scrollEl.scrollWidth));
    const y1 = Math.max(0, Math.min(band.cy - r.top + scrollEl.scrollTop, scrollEl.scrollHeight));
    const L = Math.min(band.x0, x1), T = Math.min(band.y0, y1), R = Math.max(band.x0, x1), B = Math.max(band.y0, y1);
    if (!band.moved && R - L < 4 && B - T < 4) { if (auto) band.raf = requestAnimationFrame(bandTick); return; }
    band.moved = true;
    bandEl.hidden = false;
    bandEl.style.left = L + 'px'; bandEl.style.top = T + 'px'; bandEl.style.width = (R - L) + 'px'; bandEl.style.height = (B - T) + 'px';
    const sel = band.add ? new Set(band.base) : new Set();
    const last = bandHits(L, T, R, B, sel);
    S.sel = sel;
    if (last >= 0) { S.focus = last; S.anchor = last; if (S.els.has(last)) setRoving(last); }
    applySel();
    if (auto) band.raf = requestAnimationFrame(bandTick);
  }
  function bandEnd() {
    document.removeEventListener('mousemove', bandMove);
    document.removeEventListener('mouseup', bandEnd);
    if (!band) return;
    if (band.raf) cancelAnimationFrame(band.raf);
    const moved = band.moved, add = band.add;
    band = null;
    bandEl.hidden = true;
    if (!moved && !add) clearSel();
    if (moved && S.els.has(S.focus)) S.els.get(S.focus).focus({ preventScroll: true });
  }

  // ── arrastar: mover/copiar dentro do Drive e enviar do computador ──
  const hasFiles = dt => !!dt && Array.prototype.indexOf.call(dt.types || [], 'Files') >= 0;
  function validTarget(prefix, keys) {
    if (prefix == null) return false;
    if (keys.some(k => P.isFolder(k) && prefix.indexOf(k) === 0)) return false;
    return !keys.every(k => P.parent(k) === prefix);
  }
  function dropTarget(el) {
    if (!el || !el.closest) return null;
    const row = el.closest('.dr-items [data-i]');
    if (row) {
      const it = S.items[+row.dataset.i];
      if (it && it.folder && fileView()) return { prefix: it.k, el: row };
      return S.view === 'folder' ? { prefix: S.prefix, el: null, bg: true } : null;
    }
    const tn = el.closest('.dr-tn'); if (tn) return { prefix: tn.dataset.k, el: tn };
    const cr = el.closest('[data-crumb]'); if (cr) return { prefix: cr.dataset.crumb, el: cr };
    const sc = el.closest('.dr-sc-it[data-v="trash"]'); if (sc) return { trash: true, el: sc };
    if (el.closest('.dr-main') && S.view === 'folder') return { prefix: S.prefix, el: null, bg: true };
    return null;
  }
  let overEl = null, dragT = 0, hoverT = 0, hoverEl = null;
  function setOver(el) {
    if (overEl === el) return;
    if (overEl) overEl.classList.remove('is-over');
    overEl = el;
    if (el) el.classList.add('is-over');
    clearTimeout(hoverT); hoverEl = el;
    if (el && el.classList.contains('dr-tn') && el.getAttribute('aria-expanded') === 'false') {
      hoverT = setTimeout(() => { if (hoverEl === el && el.isConnected) toggleNode(el.dataset.k, true); }, 750);
    }
  }
  function clearDragUi() { setOver(null); noteEl.hidden = true; mainEl.classList.remove('is-dropping'); }
  function showNote(text) { noteEl.hidden = false; noteEl.innerHTML = ic('upload') + '<span>' + text + '</span>'; }
  host.addEventListener('dragstart', e => {
    const row = e.target.closest && e.target.closest('.dr-items [data-i]');
    if (!row) return;
    const i = +row.dataset.i, it = S.items[i];
    if (!it || !canDrag(it) || S.renaming) { e.preventDefault(); return; }
    if (!S.sel.has(it.k)) { selectOnly(i); setFocus(i, { scroll: false }); applySel(); }
    const list = selItems();
    if (list.some(x => isRoot(x.k))) { e.preventDefault(); TD.toast(ROOT_ERR, 'err'); return; }
    S.drag = { keys: list.map(x => x.k) };
    const dt = e.dataTransfer;
    dt.effectAllowed = 'copyMove';
    try { dt.setData('application/x-td-keys', JSON.stringify(S.drag.keys)); dt.setData('text/plain', S.drag.keys.join('\n')); } catch (err) { /* sem dados */ }
    const g = TD.el('div', 'dr-ghost');
    g.innerHTML = ic(list.length > 1 ? 'copy' : (it.folder ? 'folder' : it.kind)) + '<span>' + esc(list.length > 1 ? itemsWord(list.length) : it.name) + '</span>';
    document.body.appendChild(g);
    try { dt.setDragImage(g, -12, -8); } catch (err) { /* sem imagem */ }
    setTimeout(() => g.remove(), 0);
    host.classList.add('is-dragging');
    const dk = new Set(S.drag.keys);
    S.els.forEach((el, j) => { if (dk.has(S.items[j].k)) el.classList.add('is-dragged'); });
  });
  function endDrag() {
    if (!S.drag) return;
    S.drag = null;
    host.classList.remove('is-dragging');
    host.querySelectorAll('.is-dragged').forEach(x => x.classList.remove('is-dragged'));
    clearDragUi();
    if (S.pendingRender) rerender();
  }
  document.addEventListener('dragend', endDrag);
  document.addEventListener('dragover', e => {
    const internal = !!S.drag, ext = !internal && hasFiles(e.dataTransfer);
    if (!internal && !ext) return;
    e.preventDefault();   // o browser não abre o ficheiro largado fora do sítio
    clearTimeout(dragT);
    dragT = setTimeout(clearDragUi, 160);
    const t = host.contains(e.target) ? dropTarget(e.target) : null;
    if (internal) {
      if (!t || t.bg || (!t.trash && !validTarget(t.prefix, S.drag.keys))) { e.dataTransfer.dropEffect = 'none'; setOver(null); noteEl.hidden = true; return; }
      const copy = e.altKey || e.ctrlKey;
      e.dataTransfer.dropEffect = t.trash ? 'move' : copy ? 'copy' : 'move';
      setOver(t.el);
      showNote(t.trash ? 'Largue para enviar para o Lixo' : (copy ? 'Copiar' : 'Mover') + ' para «' + esc(pretty(t.prefix)) + '»' + (copy ? '' : ' · Alt ou Ctrl para copiar'));
      return;
    }
    const tp = t && !t.trash ? t.prefix : null;
    if (tp == null || !canUpload()) { e.dataTransfer.dropEffect = 'none'; setOver(null); noteEl.hidden = true; mainEl.classList.remove('is-dropping'); return; }
    e.dataTransfer.dropEffect = 'copy';
    setOver(t.el);
    mainEl.classList.toggle('is-dropping', !t.el);
    showNote('Largue para enviar para «' + esc(pretty(tp)) + '»');
  });
  document.addEventListener('drop', e => {
    const internal = !!S.drag, ext = !internal && hasFiles(e.dataTransfer);
    if (!internal && !ext) return;
    e.preventDefault();
    const t = host.contains(e.target) ? dropTarget(e.target) : null;
    if (internal) {
      const keys = S.drag.keys, copy = e.altKey || e.ctrlKey;
      endDrag();
      if (!t || t.bg) return;
      if (t.trash) { run({ kind: 'trash', payload: { items: keys } }); return; }
      if (!validTarget(t.prefix, keys)) return;
      transfer(keys, t.prefix, copy ? 'copy' : 'move');
      return;
    }
    clearDragUi();
    const tp = t && !t.trash ? t.prefix : null;
    if (tp == null) return;
    if (!needUpload()) return;
    dropFiles(e.dataTransfer, tp);
  });
  function dropFiles(dt, prefix) {
    if (typeof TD.upload.drop === 'function') { TD.upload.drop(dt, prefix); return; }   // o módulo de envios lê as pastas
    const entries = [];
    const items = dt.items;
    if (items && items.length && items[0].webkitGetAsEntry) {
      for (let i = 0; i < items.length; i++) { const en = items[i].kind === 'file' ? items[i].webkitGetAsEntry() : null; if (en) entries.push(en); }
    }
    const plain = Array.from(dt.files || []);
    if (!entries.length || entries.every(en => en.isFile)) {
      if (plain.length) TD.upload.addFiles(plain, plain.map(() => ''), prefix);
      return;
    }
    const t = TD.toast('A ler as pastas…', 'info', { ms: 0 });
    walk(entries).then(r => {
      t.close();
      if (!r.files.length) { TD.toast('Não encontrei ficheiros nessa pasta.', 'err'); return; }
      TD.upload.addFiles(r.files, r.dirs, prefix);
    }, () => { t.close(); TD.toast('Não foi possível ler a pasta arrastada.', 'err'); });
  }
  /** Percorre as pastas largadas: cada ficheiro com o caminho relativo da sua pasta («Sub/Pasta/»). */
  function walk(entries) {
    const files = [], dirs = [];
    const readAll = reader => new Promise((res, rej) => {
      let out = [];
      (function next() {
        reader.readEntries(batch => { if (!batch.length) return res(out); out = out.concat(Array.from(batch)); next(); }, rej);
      })();
    });
    const visit = (en, path) => {
      if (en.isFile) return new Promise(res => en.file(f => { if (!/^(\.DS_Store|Thumbs\.db|desktop\.ini)$/i.test(f.name)) { files.push(f); dirs.push(path); } res(); }, () => res()));
      if (en.isDirectory) {
        const p = path + en.name + '/';
        return readAll(en.createReader()).then(kids => kids.reduce((pr, kd) => pr.then(() => visit(kd, p)), Promise.resolve()));
      }
      return Promise.resolve();
    };
    return entries.reduce((pr, en) => pr.then(() => visit(en, '')), Promise.resolve()).then(() => ({ files, dirs }));
  }

  // ── gaveta (telemóvel) e assistente deslizante (< 1200 px) ──
  function setDrawer(open) {
    host.classList.toggle('side-open', open);
    menuBtn.setAttribute('aria-expanded', open);
    syncScrim();
    if (open) { const c = treeEl.querySelector('.is-cur') || newBtn; setTimeout(() => c.focus({ preventScroll: true }), 30); }
  }
  function syncScrim() {
    const on = (host.classList.contains('side-open') && narrow()) || (root.classList.contains('chat-open') && chatSlide()) || (S.details && narrow());
    scrimEl.hidden = !on;
  }
  function syncChat(open) {
    root.classList.toggle('chat-open', open);
    chatBtn.setAttribute('aria-expanded', open);
    chatBtn.classList.toggle('on', open);
    syncScrim();
  }
  function setChat(open) {
    const c = TD.chat;
    // a coluna da direita mostra os detalhes: para ver o assistente fecham-se os detalhes
    if (open && S.details && sharedCol()) openDetails(false);
    if (c && typeof c[open ? 'open' : 'close'] === 'function') {
      try { c[open ? 'open' : 'close'](); } catch (err) { console.error(err); }
    }
    syncChat(open);
    if (open && c && typeof c.focus === 'function') setTimeout(() => { try { c.focus(); } catch (err) { console.error(err); } }, 60);
    if (!open && chatSlide()) chatBtn.focus({ preventScroll: true });
  }
  /** Mantém a classe do layout certa mesmo quando o chat se abre/fecha sozinho. */
  const chatScript = !!document.querySelector('script[src*="/assets/drive/chat.js"]');
  root.classList.toggle('no-chat', !chatScript && !TD.chat);   // sem o assistente, a coluna dele não ocupa espaço
  function hookChat() {
    const c = TD.chat;
    root.classList.toggle('no-chat', !chatScript && !c);
    if (!c || c.__drHooked) return;
    c.__drHooked = true;
    ['open', 'close'].forEach(m => {
      const f = c[m];
      if (typeof f !== 'function') return;
      c[m] = function () {
        if (m === 'open' && S.details && sharedCol()) openDetails(false);
        const r = f.apply(c, arguments); syncChat(m === 'open'); return r;
      };
    });
    if (typeof c.focus === 'function') {   // focar o assistente (ex.: uma pergunta vinda de outro módulo) também o mostra
      const f = c.focus;
      c.focus = function () { if (S.details && sharedCol()) openDetails(false); return f.apply(c, arguments); };
    }
  }
  function closeOverlays() {
    if (host.classList.contains('side-open') && narrow()) { setDrawer(false); menuBtn.focus(); return true; }
    if (root.classList.contains('chat-open') && chatSlide()) { setChat(false); return true; }
    if (S.details && narrow()) { openDetails(false); return true; }
    return false;
  }
  menuBtn.addEventListener('click', () => setDrawer(!host.classList.contains('side-open')));
  $('.dr-side-x').addEventListener('click', () => { setDrawer(false); menuBtn.focus(); });
  chatBtn.addEventListener('click', () => {
    if (!chatSlide()) {   // 1200–1679 px com os detalhes abertos: o botão devolve a coluna ao assistente
      openDetails(false);
      const c = TD.chat;
      if (c && typeof c.open === 'function') c.open();
      if (c && typeof c.focus === 'function') setTimeout(() => { try { c.focus(); } catch (err) { console.error(err); } }, 60);
      return;
    }
    setChat(!root.classList.contains('chat-open'));
  });
  scrimEl.addEventListener('click', () => { setDrawer(false); if (root.classList.contains('chat-open')) setChat(false); if (S.details && narrow()) openDetails(false); });
  window.addEventListener('resize', () => { syncScrim(); });

  // ── módulos vizinhos e eventos ──────────────────────────
  let upT = 0;
  TD.bus.on('upload:done', d => {
    if (!d || typeof d.key !== 'string' || hiddenKey(d.key)) return;
    const o = { k: d.key, s: +d.size || 0, m: now(), e: String(d.etag || '').toLowerCase() };
    S.objects = S.objects.filter(x => x.k !== d.key);
    S.objects.push(o);
    S.recents = [{ k: d.key, t: now() }].concat(S.recents.filter(r => r.k !== d.key)).slice(0, 200);
    clearTimeout(upT);
    upT = setTimeout(() => { if (!S.loaded) return; buildIndex(); rerender(); renderTree(); renderSide(); }, 250);
  });
  TD.bus.on('upload:batch', d => {
    scheduleRefresh(300);
    TD.bus.emit('drive:changed', { kind: 'upload', keys: (d && d.keys) || [] });
  });
  window.addEventListener('beforeunload', e => {
    const up = TD.upload && typeof TD.upload.busy === 'function' && TD.upload.busy();
    if (up || S.zipping > 0 || S.busy > 0) { e.preventDefault(); e.returnValue = ''; }
  });
  setInterval(() => {
    if (document.visibilityState === 'visible' && S.loaded && Date.now() - S.lastLoad > 58000 && !S.renaming && !S.drag) load(false);
  }, 10000);
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible' && S.loaded && Date.now() - S.lastLoad > 60000) load(false);
  });

  // ── TD.app ──────────────────────────────────────────────
  TD.app = {
    ready,
    navigate(prefix) {
      prefix = String(prefix || '');
      if (prefix && !P.isFolder(prefix)) { TD.app.reveal(prefix); return; }
      if (!validPrefix(prefix)) return;
      go({ view: 'folder', prefix });
    },
    openFile(key) {
      if (!key) return;
      if (!S.loaded) { ready.then(() => TD.app.openFile(key)); return; }
      key = String(key);
      if (P.isFolder(key)) { TD.app.navigate(key); return; }
      const it = S.byKey.get(key);
      if (!it) { TD.toast('O ficheiro «' + P.base(key) + '» já não existe.', 'err'); return; }
      preview(it);
    },
    reveal(key) {
      if (!key) return;
      if (!S.loaded) { ready.then(() => TD.app.reveal(key)); return; }
      key = String(key);
      const par = P.parent(key);
      if (!validPrefix(par)) return;
      if (S.filter !== 'all') S.filter = 'all';
      go({ view: 'folder', prefix: par }, { focusKey: key, select: true });
    },
    current() {
      const sel = S.sel.size && S.view !== 'trash' ? selItems().map(realKey).filter(Boolean).slice(0, 200) : [];
      return { prefix: S.prefix, view: S.view, selection: sel };
    },
    refresh(fresh) { return load(fresh !== false).then(() => undefined); },
    get(key) {
      if (typeof key !== 'string') return null;
      if (P.isFolder(key)) { const f = S.folders.get(key); return f ? { k: key, s: f.size, m: f.m, e: '', folder: true, files: f.nfiles } : null; }
      const it = S.byKey.get(key);
      return it ? { k: it.k, s: it.s, m: it.m, e: it.e } : null;
    },
    exists(key) { return typeof key === 'string' && (P.isFolder(key) ? S.folders.has(key) : S.byKey.has(key)); },
    isFolder(prefix) { return typeof prefix === 'string' && P.isFolder(prefix) && S.folders.has(prefix); },
    list(prefix) {
      const f = S.folders.get(String(prefix || ''));
      if (!f) return { folders: [], files: [] };
      return { folders: treeKids(f.k), files: f.files.slice().sort((a, b) => coll.compare(a.name, b.name)).map(it => ({ k: it.k, s: it.s, m: it.m, e: it.e })) };
    },
    openDetails: on => openDetails(on == null ? true : on),
    chat: open => setChat(open == null ? !root.classList.contains('chat-open') : !!open),
  };

  // ── arranque ────────────────────────────────────────────
  const st0 = readUrl();
  // ?f=<chave>: vem de «Mostrar no Drive» (fichas, Centro Criativo…): abre a pasta com o ficheiro selecionado
  const f0 = new URLSearchParams(location.search).get('f') || '';
  const hs = history.state;
  S.hist = hs && typeof hs.td === 'number' ? hs.td : 0;
  S.histMax = S.hist;
  S.view = st0.view; S.prefix = st0.prefix; S.q = st0.q || ''; if (st0.scope) S.scope = st0.scope;
  if (S.view === 'search') { qEl.value = S.q; S.before = { view: 'folder', prefix: S.prefix }; }
  history.replaceState({ td: S.hist }, '', urlOf());
  if (TD.upload && typeof TD.upload.init === 'function') {
    try { TD.upload.init({ dock: document.getElementById('drive-upload-dock') }); } catch (err) { console.error(err); }
  }
  renderAll();
  load(false).then(() => {
    if (S.view === 'search') contentSearch();
    else if (f0 && S.view === 'folder') go({ view: 'folder', prefix: S.prefix }, { push: false, replace: true, focusKey: f0, select: true });
  });
  if (S.view === 'trash') loadTrash();
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', hookChat); else setTimeout(hookChat, 0);
  window.addEventListener('load', hookChat);   // se o chat.js só criar o TD.chat mais tarde
})();
