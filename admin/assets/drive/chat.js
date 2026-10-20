/* topper. admin — Drive: o assistente (painel à direita, #drive-chat).
 *
 * Conversa com o ai_api.php (TD.ai): mensagens com markdown simples e seguro,
 * «chips» para [[ficheiros]] e [[pastas/]], cartões de ação que só correm no
 * browser depois de «Confirmar» (TD.ops.run, do app.js), gasto do mês,
 * conversas anteriores e o ciclo do índice de conteúdo (drive_api.php › index_tick).
 *
 * Segurança: o texto do modelo e os nomes de ficheiros nunca passam por
 * innerHTML — tudo é construído com nós de texto; o innerHTML só recebe os
 * ícones e o esqueleto fixo deste ficheiro.
 *
 * Layout: em ecrãs largos é uma coluna que se recolhe numa faixa (classe
 * «chat-collapsed» no #drive, preferência guardada); abaixo de 1200 px é um
 * painel deslizante (classe «chat-open» no #drive, que o app.js também gere). */
(function () {
  'use strict';
  const TD = window.TD;
  const root = document.getElementById('drive');
  const host = document.getElementById('drive-chat');
  if (!TD || !root || !host || TD.chat) return;

  const P = TD.path;
  const MAX_TEXT = 4000;
  const TICK_MS = 4000;
  const NARROW = window.matchMedia('(max-width: 1199px)');
  const REDUCED = window.matchMedia('(prefers-reduced-motion: reduce)');
  const SUGGEST = ['Que faturas recebemos em setembro?', 'Onde está o logótipo em SVG?', 'Resume o contrato da Delta Y'];
  const KINDS = {
    move: { icon: 'move', tag: 'Mover', done: 'Movido.' },
    rename: { icon: 'edit', tag: 'Renomear', done: 'Renomeado.' },
    mkdir: { icon: 'newfolder', tag: 'Nova pasta', done: 'Pasta criada.' },
    trash: { icon: 'trash', tag: 'Lixo', done: 'Enviado para o Lixo.' },
    share: { icon: 'link', tag: 'Partilhar', done: 'Link criado.' },
  };
  const now = () => Math.floor(Date.now() / 1000);
  const num = v => (typeof v === 'number' && isFinite(v) ? v : (parseFloat(v) || 0));
  const nfmt = new Intl.NumberFormat('pt-PT');

  // ── ícones que faltam na base (mesmo desenho: 20×20, traço 1,5) ──
  const XI = {
    sparkle: '<path d="M9 2.8l1.5 4.2 4.2 1.5-4.2 1.5L9 14.2 7.5 10 3.3 8.5 7.5 7z"/><path d="M15 12.2l.7 1.8 1.8.7-1.8.7-.7 1.8-.7-1.8-1.8-.7 1.8-.7z"/>',
    send: '<path d="M10 16V4.5M5 9.5l5-5 5 5"/>',
    check: '<path d="M4.5 10.5l3.5 3.5 7.5-8"/>',
    warn: '<path d="M10 3l8 14H2z"/><path d="M10 8.5v4M10 14.8v.1"/>',
    key: '<circle cx="6.8" cy="13" r="3.4"/><path d="M9.2 10.6L16.5 3.3M13.6 6.2l2.1 2.1M11.6 8.2l1.6 1.6"/>',
    back: '<path d="M12.5 4.5L7 10l5.5 5.5"/>',
    collapse: '<rect x="2.5" y="3.5" width="15" height="13" rx="1.5"/><path d="M12.5 3.5v13M6.2 7.8L8.4 10l-2.2 2.2"/>',
    newfolder: '<path d="M2.5 5.5a1 1 0 0 1 1-1h4l1.6 1.8h7.4a1 1 0 0 1 1 1v8.2a1 1 0 0 1-1 1h-13a1 1 0 0 1-1-1z"/><path d="M10 9v5M7.5 11.5h5"/>',
    retry: '<path d="M16 10a6 6 0 1 1-1.8-4.3M16 3.5v3h-3"/>',
    ext: '<path d="M11 3.5h5.5V9M16.5 3.5L9 11M14 12.5v4H3.5V6H8"/>',
    arrow: '<path d="M3.5 10h12M11.5 6l4 4-4 4"/>',
  };
  function ic(name, cls) {
    if (!XI[name]) return TD.icon(name, cls);
    return '<svg class="td-ico' + (cls ? ' ' + cls : '') + '" viewBox="0 0 20 20" width="20" height="20" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">' + XI[name] + '</svg>';
  }
  /** Elemento com um ícone fixo (o único innerHTML com conteúdo dinâmico é o nome do ícone, de uma lista fixa). */
  function icoEl(name, cls) { const s = TD.el('span', cls || 'tdc-i'); s.innerHTML = ic(name); return s; }
  const el = TD.el;

  // ── estado ──────────────────────────────────────────────
  const S = {
    state: 'loading',      // loading | ready | setup | down
    downMsg: '',
    status: null,          // a resposta de «status»
    conv: null,            // {id, title}
    msgs: [],              // Msg[] da conversa aberta
    view: 'chat',          // chat | convs
    loadingConv: false,
    sending: false,
    seq: 0,                // muda a cada troca de conversa: respostas antigas são ignoradas
    budget: '',            // texto do aviso de limite mensal ('' = sem aviso)
    tooLong: false,        // a conversa aberta não aceita mais mensagens (longa demais ou apagada noutro sítio)
    index: null,
    collapsed: !!TD.store.get('chat.collapsed', false),
    actions: new Map(),    // token → Action (estado local)
    convSeq: 0,            // idem para a lista de conversas
    chatScroll: null,      // posição da conversa enquanto a lista está à vista
    loadFailed: false,
    statusAt: 0,
  };

  // ── o esqueleto (só texto fixo) ─────────────────────────
  host.innerHTML =
    '<div class="tdc">' +
      '<header class="tdc-head">' +
        '<div class="tdc-head-row">' +
          '<span class="tdc-logo">' + ic('sparkle') + '</span>' +
          '<h2 class="tdc-title" id="tdc-title">Assistente</h2>' +
          '<div class="tdc-head-acts">' +
            '<button type="button" class="tdc-ib" data-act="new" title="Nova conversa" aria-label="Nova conversa">' + ic('plus') + '</button>' +
            '<button type="button" class="tdc-ib" data-act="menu" aria-haspopup="menu" aria-expanded="false" title="Mais opções" aria-label="Mais opções do assistente">' + ic('more') + '</button>' +
            '<button type="button" class="tdc-ib tdc-collapse" data-act="collapse" title="Recolher o assistente" aria-label="Recolher o assistente">' + ic('collapse') + '</button>' +
            '<button type="button" class="tdc-ib tdc-close" data-act="close" title="Fechar (Esc)" aria-label="Fechar o assistente">' + ic('close') + '</button>' +
          '</div>' +
        '</div>' +
        '<div class="tdc-spend" hidden><span class="tdc-spend-t"></span><span class="tdc-meter" aria-hidden="true"><i></i></span></div>' +
      '</header>' +
      '<div class="tdc-menu" role="menu" aria-label="Opções do assistente" hidden></div>' +
      '<div class="tdc-banner" role="alert" hidden></div>' +
      '<div class="tdc-body"></div>' +
      '<form class="tdc-compose" novalidate hidden>' +
        '<label class="sr" for="tdc-q">Mensagem para o assistente</label>' +
        '<div class="tdc-field">' +
          '<textarea id="tdc-q" class="tdc-q" rows="1" maxlength="' + MAX_TEXT + '" placeholder="Pergunte sobre os ficheiros…" aria-describedby="tdc-hint" enterkeyhint="send"></textarea>' +
          '<button type="submit" class="tdc-send" aria-label="Enviar" title="Enviar (Enter)" disabled>' + ic('send') + '</button>' +
        '</div>' +
        '<span class="sr" id="tdc-hint">Enter envia; Shift+Enter muda de linha.</span>' +
      '</form>' +
      '<div class="tdc-foot"><span class="tdc-idx" hidden></span><span class="tdc-count"></span></div>' +
    '</div>' +
    '<button type="button" class="tdc-rail" title="Abrir o assistente" aria-label="Abrir o assistente">' + ic('sparkle') + '<span>Assistente</span></button>';

  const $ = s => host.querySelector(s);
  const boxEl = $('.tdc'), spendEl = $('.tdc-spend'), spendT = $('.tdc-spend-t'), meterEl = $('.tdc-meter i');
  const menuEl = $('.tdc-menu'), menuBtn = $('[data-act="menu"]'), bannerEl = $('.tdc-banner'), bodyEl = $('.tdc-body');
  const formEl = $('.tdc-compose'), qEl = $('.tdc-q'), sendBtn = $('.tdc-send'), countEl = $('.tdc-count');
  const idxEl = $('.tdc-idx'), railEl = $('.tdc-rail');
  // O corpo tem duas vistas: a conversa e a lista de conversas. A lista só esconde a conversa
  // (uma resposta que chegue entretanto continua a entrar no sítio certo).
  const chatEl = el('div', 'tdc-chat'), convsEl = el('div', 'tdc-convs');
  convsEl.hidden = true;
  bodyEl.appendChild(chatEl);
  bodyEl.appendChild(convsEl);
  let logEl = null;   // a lista de mensagens (role=log), quando a conversa tem mensagens

  // ── markdown simples e seguro ───────────────────────────
  // Blocos: parágrafos, quebras de linha, listas (com um nível de recuo), blocos de código,
  // títulos (como parágrafo em negrito), citações, tabelas e linhas horizontais.
  // Em linha: **negrito**, *itálico*, `código`, [texto](http…), endereços http(s) soltos e [[chave]].
  const LIST_RE = /^(\s*)([-*+•]|\d{1,3}[.)])\s+(.*)$/;
  const FENCE_RE = /^\s{0,3}(```+|~~~+)\s*([\w+#.-]*)\s*$/;
  const HR_RE = /^\s{0,3}([-*_])(?:\s*\1){2,}\s*$/;
  const HEAD_RE = /^\s{0,3}#{1,6}\s+(.*?)\s*#*\s*$/;
  const QUOTE_RE = /^\s{0,3}>\s?(.*)$/;
  const TROW_RE = /^\s*\|.*\|\s*$/;
  const TSEP_RE = /^\s*\|?\s*:?-{2,}:?\s*(\|\s*:?-{2,}:?\s*)*\|?\s*$/;

  function markdown(text, ctx) {
    const frag = document.createDocumentFragment();
    const lines = String(text == null ? '' : text).replace(/\r\n?/g, '\n').split('\n');
    let i = 0;
    const isBlockStart = l => FENCE_RE.test(l) || HR_RE.test(l) || HEAD_RE.test(l) || LIST_RE.test(l) || QUOTE_RE.test(l);
    while (i < lines.length) {
      const line = lines[i];
      if (/^\s*$/.test(line)) { i++; continue; }
      let m = FENCE_RE.exec(line);
      if (m) {
        const fence = m[1], body = [];
        i++;
        while (i < lines.length && !(lines[i].trim().indexOf(fence[0].repeat(3)) === 0 && lines[i].trim().replace(/[`~]/g, '') === '')) body.push(lines[i++]);
        i++;   // a vedação de fecho (ou o fim do texto)
        const pre = el('pre', 'tdc-pre'), code = el('code');
        code.textContent = body.join('\n');
        pre.appendChild(code);
        frag.appendChild(pre);
        continue;
      }
      if (HR_RE.test(line)) { frag.appendChild(el('hr', 'tdc-hr')); i++; continue; }
      m = HEAD_RE.exec(line);
      if (m) {
        const p = el('p', 'tdc-h'), b = el('strong');
        inline(m[1], b, ctx, 0);
        p.appendChild(b); frag.appendChild(p); i++;
        continue;
      }
      if (TROW_RE.test(line) && i + 1 < lines.length && TSEP_RE.test(lines[i + 1]) && lines[i + 1].indexOf('-') >= 0) {
        const rows = [line];
        i += 2;
        while (i < lines.length && TROW_RE.test(lines[i])) rows.push(lines[i++]);
        frag.appendChild(table(rows, ctx));
        continue;
      }
      if (LIST_RE.test(line)) {
        const r = parseList(lines, i);
        i = r.i;
        frag.appendChild(buildList(r.items, ctx));
        continue;
      }
      if (QUOTE_RE.test(line)) {
        const q = [];
        while (i < lines.length && QUOTE_RE.test(lines[i])) q.push(QUOTE_RE.exec(lines[i++])[1]);
        const bq = el('blockquote', 'tdc-quote');
        inline(q.join('\n'), bq, ctx, 0);
        frag.appendChild(bq);
        continue;
      }
      const para = [line];
      i++;
      while (i < lines.length && !/^\s*$/.test(lines[i]) && !isBlockStart(lines[i]) && !(TROW_RE.test(lines[i]) && i + 1 < lines.length && TSEP_RE.test(lines[i + 1]))) para.push(lines[i++]);
      const p = el('p');
      inline(para.map(s => s.trim()).join('\n'), p, ctx, 0);
      frag.appendChild(p);
    }
    return frag;
  }
  /** Itens de lista consecutivos: [{indent, ordered, n, text}] (linhas de continuação juntam-se ao item anterior). */
  function parseList(lines, i) {
    const items = [];
    while (i < lines.length) {
      const l = lines[i];
      const m = LIST_RE.exec(l);
      if (m && !HR_RE.test(l)) {
        // no primeiro nível, uma lista numerada a seguir a uma de pontos (ou o contrário) é outra lista
        if (items.length && m[1].replace(/\t/g, '    ').length <= items[0].indent && /\d/.test(m[2]) !== items[0].ordered) break;
        items.push({ indent: m[1].replace(/\t/g, '    ').length, ordered: /\d/.test(m[2]), n: parseInt(m[2], 10) || 1, text: m[3] });
        i++; continue;
      }
      if (/^\s*$/.test(l)) {
        if (i + 1 < lines.length && LIST_RE.test(lines[i + 1])) { i++; continue; }
        break;
      }
      if (items.length && /^\s+\S/.test(l) && !FENCE_RE.test(l)) { items[items.length - 1].text += '\n' + l.trim(); i++; continue; }
      break;
    }
    return { items, i };
  }
  function buildList(items, ctx) {
    const mk = it => { const l = el(it.ordered ? 'ol' : 'ul', 'tdc-list'); if (it.ordered && it.n !== 1) l.setAttribute('start', String(it.n)); return l; };
    const top = mk(items[0]);
    const stack = [{ indent: items[0].indent, list: top }];
    items.forEach(it => {
      while (stack.length > 1 && it.indent < stack[stack.length - 1].indent) stack.pop();
      let cur = stack[stack.length - 1];
      const lastLi = cur.list.lastElementChild;
      if (it.indent >= cur.indent + 2 && lastLi && stack.length < 4) {
        const sub = mk(it);
        lastLi.appendChild(sub);
        cur = { indent: it.indent, list: sub };
        stack.push(cur);
      }
      const li = el('li');
      inline(it.text, li, ctx, 0);
      cur.list.appendChild(li);
    });
    return top;
  }
  function table(rows, ctx) {
    const cells = r => {
      let t = r.trim();
      if (t[0] === '|') t = t.slice(1);
      if (t.slice(-1) === '|') t = t.slice(0, -1);
      return t.split('|').map(c => c.trim());
    };
    const wrap = el('div', 'tdc-tablewrap'), tb = el('table', 'tdc-table');
    const thead = el('thead'), tr0 = el('tr');
    cells(rows[0]).forEach(c => { const th = el('th'); inline(c, th, ctx, 0); tr0.appendChild(th); });
    thead.appendChild(tr0); tb.appendChild(thead);
    const tbody = el('tbody');
    rows.slice(1, 200).forEach(r => {
      const tr = el('tr');
      cells(r).forEach(c => { const td = el('td'); inline(c, td, ctx, 0); tr.appendChild(td); });
      tbody.appendChild(tr);
    });
    tb.appendChild(tbody); wrap.appendChild(tb);
    return wrap;
  }
  /** O texto em linha → nós (nunca HTML). depth limita a recursão (negrito dentro de itálico…). */
  function inline(src, out, ctx, depth) {
    let buf = '';
    const flush = () => { if (buf) { out.appendChild(document.createTextNode(buf)); buf = ''; } };
    let i = 0;
    const n = src.length;
    while (i < n) {
      const c = src[i];
      if (c === '\n') { flush(); out.appendChild(el('br')); i++; continue; }
      if (c === '`') {
        let k = 1; while (src[i + k] === '`') k++;
        const fence = src.substr(i, k);
        const end = src.indexOf(fence, i + k);
        if (end > i + k) {
          flush();
          const code = el('code', 'tdc-code');
          code.textContent = src.slice(i + k, end).replace(/^ ([\s\S]*\S[\s\S]*) $/, '$1');
          out.appendChild(code);
          i = end + k; continue;
        }
        buf += fence; i += k; continue;
      }
      if (c === '[' && src[i + 1] === '[') {
        const end = src.indexOf(']]', i + 2);
        const ref = end > i + 2 ? src.slice(i + 2, end) : '';
        if (ref && ref.length <= 1100 && ref.indexOf('\n') < 0 && ref.indexOf('[[') < 0) {
          flush(); out.appendChild(chip(ref, ctx)); i = end + 2; continue;
        }
      }
      if (c === '[' && depth < 4) {
        const m = /^\[([^\[\]\n]{1,300})\]\((https?:\/\/[^\s()<>]+(?:\([^\s()<>]*\)[^\s()<>]*)*)\)/i.exec(src.slice(i, i + 2500));
        if (m) { flush(); out.appendChild(link(m[2], m[1])); i += m[0].length; continue; }
      }
      if (c === '*' && src[i + 1] === '*' && depth < 4) {
        const end = src.indexOf('**', i + 2);
        if (end > i + 2 && !/\s/.test(src[i + 2]) && !/\s/.test(src[end - 1])) {
          flush();
          const b = el('strong');
          inline(src.slice(i + 2, end), b, ctx, depth + 1);
          out.appendChild(b);
          i = end + 2; continue;
        }
        buf += '**'; i += 2; continue;
      }
      if (c === '*' && depth < 4 && !/[\w*]/.test(src[i - 1] || '') && src[i + 1] && !/[\s*]/.test(src[i + 1])) {
        // fecha no próximo «*» da mesma linha que não tenha espaço antes nem letra depois
        let j = i + 1, end = -1;
        while (j < n && src[j] !== '\n') {
          if (src[j] === '*' && !/\s/.test(src[j - 1]) && !/[\w*]/.test(src[j + 1] || '')) { end = j; break; }
          j++;
        }
        if (end > i + 1) {
          flush();
          const em = el('em');
          inline(src.slice(i + 1, end), em, ctx, depth + 1);
          out.appendChild(em);
          i = end + 1; continue;
        }
      }
      if ((c === 'h' || c === 'H') && /^https?:\/\//i.test(src.substr(i, 8)) && !/[\w\/.@-]/.test(src[i - 1] || '')) {
        const m = /^https?:\/\/[^\s<>"'`«»]+/i.exec(src.slice(i, i + 2100));
        let url = m[0];
        // pontuação no fim não faz parte do endereço (e um «)» só conta se houver «(» lá dentro)
        for (;;) {
          const last = url.slice(-1);
          if (/[.,;:!?'"*\]]/.test(last)) { url = url.slice(0, -1); continue; }
          if (last === ')' && (url.match(/\(/g) || []).length < (url.match(/\)/g) || []).length) { url = url.slice(0, -1); continue; }
          break;
        }
        if (url.length > 8) { flush(); out.appendChild(link(url, url)); i += url.length; continue; }
      }
      buf += c; i++;
    }
    flush();
  }
  /** Link só para http(s); o resto fica texto. Abre num separador novo, sem acesso à página. */
  function link(href, text) {
    let u = null;
    try { u = new URL(href); } catch (e) { u = null; }
    if (!u || (u.protocol !== 'http:' && u.protocol !== 'https:')) return document.createTextNode(text);
    const a = el('a', { class: 'tdc-link', href: u.href, target: '_blank', rel: 'noopener noreferrer' });
    a.textContent = text;
    return a;
  }
  /** [[chave]] → botão com ícone e nome; referências que o servidor não validou ficam texto (só o nome). */
  function chip(ref, ctx) {
    const f = ctx && ctx.files ? (ctx.files.get(ref) || ctx.files.get(ref + '/')) : null;
    if (!f) return document.createTextNode(P.base(ref) || ref);
    const key = f.folder && !P.isFolder(f.k) ? f.k + '/' : f.k;
    const folder = P.isFolder(key);
    const kind = TD.kind(key).id;
    const b = el('button', { type: 'button', class: 'tdc-chip', 'data-kind': kind, 'data-key': key });
    b.innerHTML = TD.icon(kind);
    const name = typeof f.name === 'string' && f.name ? f.name : (P.base(key) || key);
    b.appendChild(el('span', 'tdc-chip-n', name));
    b.title = chipTitle(key, folder ? null : f.s);
    b.setAttribute('aria-label', (folder ? 'Abrir a pasta ' : 'Abrir ') + name);
    return b;
  }
  function chipTitle(key, size) {
    return key.replace(/\/$/, '').split('/').join(' › ') + (size > 0 ? ' · ' + TD.fmt.size(size) : '');
  }

  // ── mensagens ───────────────────────────────────────────
  function msgNode(m) {
    const user = m.role === 'user';
    const wrap = el('div', { class: 'tdc-msg ' + (user ? 'tdc-u' : 'tdc-a'), 'data-id': m.id != null ? String(m.id) : '' });
    if (m.ts) wrap.title = TD.fmt.date(m.ts);
    if (user) {
      wrap.appendChild(el('div', 'tdc-bubble', String(m.text == null ? '' : m.text)));
      return wrap;
    }
    const files = new Map();
    (Array.isArray(m.files) ? m.files : []).forEach(f => {
      if (f && typeof f.k === 'string' && f.k) { files.set(f.k, f); if (f.folder && !P.isFolder(f.k)) files.set(f.k + '/', f); }
    });
    const md = el('div', 'tdc-md');
    md.appendChild(markdown(m.text, { files }));
    wrap.appendChild(md);
    const acts = Array.isArray(m.actions) ? m.actions.filter(a => a && typeof a === 'object') : [];
    if (acts.length) {
      const box = el('div', 'tdc-cards');
      acts.forEach(a => box.appendChild(cardNode(a)));
      wrap.appendChild(box);
    }
    if (m.text) {
      const tools = el('div', 'tdc-tools');
      const cp = el('button', { type: 'button', class: 'tdc-mini', 'data-act': 'copy-msg', title: 'Copiar a resposta', 'aria-label': 'Copiar a resposta' });
      cp.innerHTML = ic('copy');
      tools.appendChild(cp);
      wrap.appendChild(tools);
    }
    wrap._msg = m;
    return wrap;
  }
  function thinkingNode() {
    const w = el('div', { class: 'tdc-msg tdc-a tdc-think', role: 'status' });
    w.appendChild(el('span', 'tdc-dots')).innerHTML = '<i></i><i></i><i></i>';
    const t = el('span', 'tdc-think-t', 'A pensar…');
    w.appendChild(t);
    const t1 = setTimeout(() => { t.textContent = 'A pensar… (a procurar nos ficheiros)'; }, 12000);
    const t2 = setTimeout(() => { t.textContent = 'Ainda a trabalhar — ficheiros longos demoram mais.'; }, 40000);
    w._stop = () => { clearTimeout(t1); clearTimeout(t2); w.remove(); };
    return w;
  }
  /** Texto da resposta para copiar: [[chave]] → o caminho. */
  function plainText(m) {
    return String(m.text || '').replace(/\[\[([^\]\n]+)\]\]/g, (a, k) => k);
  }

  // ── cartões de ação ─────────────────────────────────────
  function cardNode(a) {
    const meta = KINDS[a.kind] || { icon: 'other', tag: 'Ação', done: 'Feito.' };
    const token = typeof a.token === 'string' ? a.token : '';
    const prev = token && S.actions.get(token);
    // o estado local ganha ao do servidor enquanto este não souber o resultado (ex.: pedido a repetir)
    const act = prev && prev.status !== 'pending' && (a.status === 'pending' || !a.status) ? prev : Object.assign({}, a);
    if (!act.status) act.status = 'pending';
    if (token) S.actions.set(token, act);
    const card = el('div', { class: 'tdc-card', 'data-token': token, 'data-kind': a.kind || '' });
    const hd = el('div', 'tdc-card-h');
    hd.appendChild(icoEl(meta.icon, 'tdc-card-ico'));
    const tt = el('div', 'tdc-card-tt');
    tt.appendChild(el('span', 'tdc-card-tag', meta.tag));
    tt.appendChild(el('b', 'tdc-card-title', typeof a.title === 'string' && a.title ? a.title : meta.tag));
    hd.appendChild(tt);
    card.appendChild(hd);
    const lines = Array.isArray(a.lines) ? a.lines.filter(x => typeof x === 'string' && x !== '') : [];
    if (lines.length) {
      const ul = el('ul', 'tdc-card-lines');
      lines.slice(0, 40).forEach(l => ul.appendChild(lineNode(l)));
      if (lines.length > 40) ul.appendChild(el('li', 'tdc-card-more', '+ ' + (lines.length - 40) + ' itens'));
      card.appendChild(ul);
    }
    if (a.kind === 'trash') card.appendChild(el('p', 'tdc-card-note', 'Fica 30 dias no Lixo e pode ser restaurado.'));
    card.appendChild(el('div', 'tdc-card-f'));
    paint(card, act);
    return card;
  }
  /** «origem → destino» com a seta destacada; o resto é texto simples. */
  function lineNode(l) {
    const li = el('li');
    const k = l.indexOf(' → ');
    if (k > 0) {
      li.appendChild(el('span', 'tdc-from', l.slice(0, k)));
      li.appendChild(icoEl('arrow', 'tdc-arrow'));
      li.appendChild(el('span', 'tdc-to', l.slice(k + 3)));
    } else li.textContent = l;
    return li;
  }
  function hoursText(h) {
    h = Math.round(num(h));
    if (h >= 24 && h % 24 === 0) return h === 24 ? '24 horas' : (h / 24) + ' dias';
    return h === 1 ? '1 hora' : h + ' horas';
  }
  /** O link de uma partilha feita: do resultado local ou do que o servidor guardou (texto ou objeto). */
  function shareUrl(a) {
    const cands = [a.url, a.result && typeof a.result === 'object' ? a.result.url : null, typeof a.result === 'string' ? a.result : null];
    for (const c of cands) {
      if (typeof c !== 'string') continue;
      const m = /https?:\/\/[^\s"'<>]+/i.exec(c);
      if (!m) continue;
      try { const u = new URL(m[0]); if (u.protocol === 'http:' || u.protocol === 'https:') return u.href; } catch (e) { /* segue */ }
    }
    return '';
  }
  function resultText(a) {
    const r = a.result;
    if (typeof r === 'string') return r;
    if (r && typeof r === 'object' && typeof r.message === 'string') return r.message;
    return '';
  }
  function paint(card, a) {
    const meta = KINDS[a.kind] || { done: 'Feito.' };
    const f = card.querySelector('.tdc-card-f');
    f.textContent = '';
    card.setAttribute('data-status', a.status);
    card.classList.toggle('is-decided', a.status !== 'pending' && a.status !== 'running');
    if (a.status === 'pending') {
      const opsOk = !!(TD.ops && typeof TD.ops.run === 'function');
      const no = el('button', { type: 'button', class: 'btn ghost sm', 'data-act': 'cancel' }, 'Cancelar');
      const yes = el('button', { type: 'button', class: 'btn sm', 'data-act': 'confirm', disabled: !opsOk, title: opsOk ? null : 'As operações do Drive não estão disponíveis nesta página.' }, 'Confirmar');
      f.appendChild(no); f.appendChild(yes);
      return;
    }
    if (a.status === 'running') {
      const s = el('div', { class: 'tdc-card-st is-run', role: 'status' });
      s.appendChild(el('span', 'tdc-spin'));
      s.appendChild(el('span', null, 'A executar…'));
      f.appendChild(s);
      return;
    }
    const s = el('div', { class: 'tdc-card-st', role: 'status' });
    if (a.status === 'done') {
      s.classList.add('is-done');
      s.appendChild(icoEl('check'));
      const url = a.kind === 'share' ? shareUrl(a) : '';
      if (a.kind === 'share') {
        const h = a.payload && a.payload.hours;
        s.appendChild(el('span', null, 'Link criado' + (h ? ' · válido ' + hoursText(h) : '') + '.'));
        f.appendChild(s);
        if (url) {
          const box = el('div', 'tdc-share');
          const inp = el('input', { type: 'text', class: 'tdc-share-u', readonly: true, 'aria-label': 'Link de partilha', spellcheck: 'false' });
          inp.value = url;
          inp.addEventListener('focus', () => inp.select());
          box.appendChild(inp);
          box.appendChild(el('button', { type: 'button', class: 'btn sm', 'data-act': 'copy-link' }, 'Copiar'));
          f.appendChild(box);
        }
        return;
      }
      const t = resultText(a);
      s.appendChild(el('span', null, t && t.length < 400 ? t : meta.done));
    } else if (a.status === 'failed') {
      s.classList.add('is-fail');
      s.appendChild(icoEl('warn'));
      const t = resultText(a);
      s.appendChild(el('span', null, 'Não foi feito' + (t ? ': ' + t.replace(/\.$/, '') : '') + '.'));
    } else {
      s.classList.add('is-off');
      s.appendChild(icoEl('close'));
      s.appendChild(el('span', null, 'Cancelado.'));
    }
    f.appendChild(s);
  }
  /** Repinta todos os cartões desta ação (a conversa pode ter sido redesenhada entretanto). */
  function repaint(token) {
    const a = S.actions.get(token);
    if (!a) return;
    host.querySelectorAll('.tdc-card').forEach(c => { if (c.getAttribute('data-token') === token) paint(c, a); });
  }
  async function confirmAction(card) {
    const token = card.getAttribute('data-token');
    const a = S.actions.get(token);
    if (!a || a.status !== 'pending') return;
    if (!TD.ops || typeof TD.ops.run !== 'function') { TD.toast('As operações do Drive não estão disponíveis nesta página.', 'err'); return; }
    a.status = 'running';
    repaint(token);
    let r;
    try { r = await TD.ops.run({ kind: a.kind, payload: a.payload || {}, title: a.title || '' }); } catch (e) { r = { ok: false, message: (e && e.message) || 'Erro inesperado.' }; }
    r = r && typeof r === 'object' ? r : { ok: false, message: 'Erro inesperado.' };
    const res = r.result && typeof r.result === 'object' ? r.result : {};
    const some = Array.isArray(res.done) && res.done.length > 0;   // parte foi feita (ex.: o resto cancelado)
    let status;
    if (r.ok || some) status = 'done';
    else if (res.cancelled) status = 'cancelled';
    else status = 'failed';
    let result = a.kind === 'share' && r.url ? String(r.url) : String(r.message || '');
    if (!result && status === 'failed') result = 'Erro.';
    a.status = status;
    a.result = result.slice(0, 2000);
    if (r.url) a.url = String(r.url);
    repaint(token);
    await report(token, status, a.result);
  }
  async function cancelAction(card) {
    const token = card.getAttribute('data-token');
    const a = S.actions.get(token);
    if (!a || a.status !== 'pending') return;
    a.status = 'cancelled';
    repaint(token);
    await report(token, 'cancelled', '');
  }
  // Resultados que o servidor ainda não recebeu (sem ligação): guardados e reenviados mais tarde.
  function queue() { const q = TD.store.get('chat.results', []); return Array.isArray(q) ? q : []; }
  async function report(token, status, result) {
    const body = { do: 'action_result', token, status };
    if (result) body.result = result;
    const j = await TD.ai(body);
    if (j.action && typeof j.action === 'object') mergeAction(j.action);   // também no 409 «já decidida»: vale o que o servidor tem
    if (j.ok) return true;
    if (!j.status || j.status >= 500) {
      TD.store.set('chat.results', queue().filter(x => x.token !== token).concat([body]).slice(-50));
      TD.toast('O resultado não chegou ao assistente (' + (j.error || 'sem ligação') + '). Volta a ser enviado mais tarde.', 'info');
    } else if (j.error) TD.toast(j.error, 'err');
    return false;
  }
  async function flushResults() {
    const q = queue();
    if (!q.length) return;
    const left = [];
    for (const body of q) {
      const j = await TD.ai(body);
      if (!j.ok && (!j.status || j.status >= 500)) left.push(body);
    }
    TD.store.set('chat.results', left);
  }
  function mergeAction(srv) {
    if (!srv || typeof srv.token !== 'string') return;
    const a = S.actions.get(srv.token);
    if (!a) return;
    if (a.status === 'running') return;
    const keep = { result: a.result, url: a.url, status: a.status };
    Object.assign(a, srv);
    if (srv.result == null || srv.result === '') a.result = keep.result;
    if (keep.url && !a.url) a.url = keep.url;
    if (!a.status) a.status = keep.status;
    repaint(srv.token);
  }

  // ── vistas do corpo ─────────────────────────────────────
  function clearChat() { chatEl.textContent = ''; logEl = null; }
  /** Mostra a conversa (esconde a lista de conversas), guardando/repondo a posição. */
  function showChatView() {
    if (S.view === 'chat' && convsEl.hidden) return;
    S.view = 'chat';
    S.convSeq = (S.convSeq || 0) + 1;
    convsEl.hidden = true;
    convsEl.textContent = '';
    chatEl.hidden = false;
    bodyEl.scrollTop = S.chatScroll != null ? S.chatScroll : bodyEl.scrollHeight;
  }
  function renderAll() {
    boxEl.classList.toggle('is-setup', S.state === 'setup' || S.state === 'down' || S.state === 'loading');
    if (S.state !== 'ready') {
      showChatView();
      clearChat();
      if (S.state === 'loading') chatEl.appendChild(skeleton());
      else chatEl.appendChild(S.state === 'setup' ? setupPanel() : downPanel());
      bannerEl.hidden = true;
      syncCompose();
      return;
    }
    bannerEl.hidden = !S.budget;
    renderChat();
  }
  function skeleton() {
    const w = el('div', { class: 'tdc-skel', 'aria-hidden': 'true' });
    for (let i = 0; i < 3; i++) w.appendChild(el('i'));
    return w;
  }
  function newLog() {
    logEl = el('div', { class: 'tdc-log', role: 'log', 'aria-live': 'polite', 'aria-label': 'Conversa' });
    chatEl.appendChild(logEl);
    return logEl;
  }
  function renderChat() {
    clearChat();
    if (S.loadingConv) chatEl.appendChild(skeleton());
    else if (!S.msgs.length) chatEl.appendChild(emptyPanel());
    else {
      const log = newLog();
      S.msgs.forEach(m => log.appendChild(msgNode(m)));
      if (S.tooLong) log.appendChild(restartNode('Esta conversa ficou muito longa. Comece uma nova.', ''));
    }
    syncCompose();
  }
  function ensureLog() {
    if (logEl && logEl.isConnected) return logEl;
    clearChat();
    return newLog();
  }
  function suggestions() {
    const out = [];
    const app = TD.app;
    if (app && typeof app.current === 'function') {
      try {
        const c = app.current() || {};
        const sel = Array.isArray(c.selection) ? c.selection.filter(k => typeof k === 'string') : [];
        if (sel.length === 1 && !P.isFolder(sel[0])) out.push('Resume «' + P.base(sel[0]) + '»');
        else if ((!c.view || c.view === 'folder') && typeof c.prefix === 'string' && c.prefix) out.push('O que há na pasta «' + P.base(c.prefix) + '»?');
      } catch (e) { /* sem contexto */ }
    }
    return out.concat(SUGGEST).slice(0, 4);
  }
  function emptyPanel() {
    const w = el('div', 'tdc-empty');
    w.appendChild(icoEl('sparkle', 'tdc-empty-ico'));
    w.appendChild(el('b', 'tdc-empty-t', 'Pergunte ao assistente'));
    w.appendChild(el('p', null, 'Encontra ficheiros pelo nome e pelo conteúdo, resume documentos e propõe arrumações — nada muda sem confirmar.'));
    if (!S.budget) {
      const l = el('div', { class: 'tdc-sugs', role: 'group', 'aria-label': 'Sugestões' });
      suggestions().forEach(s => l.appendChild(el('button', { type: 'button', class: 'tdc-sug', 'data-act': 'suggest' }, s)));
      w.appendChild(l);
    }
    return w;
  }
  function refreshSuggestions() {
    const l = bodyEl.querySelector('.tdc-sugs');
    if (!l) return;
    const want = suggestions();
    const have = Array.from(l.children).map(b => b.textContent);
    if (want.join('\n') === have.join('\n')) return;
    l.textContent = '';
    want.forEach(s => l.appendChild(el('button', { type: 'button', class: 'tdc-sug', 'data-act': 'suggest' }, s)));
  }
  function setupPanel() {
    const cap = S.status && num(S.status.cap_eur) > 0 ? num(S.status.cap_eur) : 10;
    const w = el('div', 'tdc-setup');
    w.appendChild(icoEl('key', 'tdc-empty-ico'));
    w.appendChild(el('b', 'tdc-empty-t', 'Ligar o assistente'));
    w.appendChild(el('p', null, 'O assistente responde a perguntas sobre os ficheiros do Drive e propõe arrumações. Usa a API da Anthropic (Claude) e precisa de uma chave, que fica só no servidor — nunca no browser nem no Git.'));
    const ol = el('ol', 'tdc-steps');
    const step = parts => {
      const li = el('li');
      parts.forEach(p => {
        if (typeof p === 'string') li.appendChild(document.createTextNode(p));
        else if (p.code) li.appendChild(el('code', 'tdc-code', p.code));
        else if (p.b) li.appendChild(el('b', null, p.b));
        else if (p.a) { const a = el('a', { class: 'tdc-link', href: p.a, target: '_blank', rel: 'noopener noreferrer' }, p.t); li.appendChild(a); }
      });
      ol.appendChild(li);
    };
    step(['Entre em ', { a: 'https://console.anthropic.com/', t: 'console.anthropic.com' }, ' com a conta da empresa (ou crie uma).']);
    step(['Em ', { b: 'Settings › API Keys' }, ' carregue em ', { b: 'Create Key' }, ', dê-lhe um nome (ex.: «topper admin») e copie a chave. Começa por ', { code: 'sk-ant-' }, ' e só aparece uma vez.']);
    step(['Em ', { b: 'Settings › Billing' }, ' carregue crédito. O assistente nunca gasta mais de ' + TD.fmt.eur(cap).replace(/,00(?=\s|$)/, '') + ' por mês.']);
    step(['No servidor (Hostinger › Gestor de ficheiros), copie ', { code: 'admin/inc/ai_config.example.php' }, ' para ', { code: 'admin/inc/ai_config.php' }, ' e cole a chave em ', { code: 'api_key' }, '. Este ficheiro não vai para o Git.']);
    step(['Recarregue esta página.']);
    w.appendChild(ol);
    const acts = el('div', 'tdc-setup-acts');
    const open = el('a', { class: 'btn sm', href: 'https://console.anthropic.com/settings/keys', target: '_blank', rel: 'noopener noreferrer' });
    open.appendChild(document.createTextNode('Abrir a consola'));
    open.insertAdjacentHTML('beforeend', ic('ext'));
    acts.appendChild(open);
    acts.appendChild(el('button', { type: 'button', class: 'btn ghost sm', 'data-act': 'recheck' }, 'Verificar outra vez'));
    w.appendChild(acts);
    return w;
  }
  function downPanel() {
    const w = el('div', 'tdc-empty is-err');
    w.appendChild(icoEl('warn', 'tdc-empty-ico'));
    w.appendChild(el('b', 'tdc-empty-t', 'O assistente não respondeu'));
    w.appendChild(el('p', null, S.downMsg || 'Erro desconhecido.'));
    const b = el('button', { type: 'button', class: 'btn sm', 'data-act': 'recheck' });
    b.innerHTML = ic('retry');
    b.appendChild(document.createTextNode('Tentar outra vez'));
    w.appendChild(b);
    return w;
  }
  /** Aviso com «Nova conversa» (conversa demasiado longa ou apagada noutro sítio); com texto, leva a pergunta. */
  function restartNode(msg, text) {
    const w = el('div', { class: 'tdc-note', role: 'status' });
    w.appendChild(icoEl('info'));
    const t = el('div');
    t.appendChild(el('p', null, msg));
    const b = el('button', { type: 'button', class: 'btn sm', 'data-act': 'new-with' }, text ? 'Nova conversa com esta pergunta' : 'Nova conversa');
    if (text) b._text = text;
    t.appendChild(b);
    w.appendChild(t);
    return w;
  }

  // ── conversas anteriores ────────────────────────────────
  async function showConvs() {
    closeMenu();
    if (S.state !== 'ready') return;
    if (S.view === 'chat') S.chatScroll = bodyEl.scrollTop;
    S.view = 'convs';
    const seq = S.convSeq = (S.convSeq || 0) + 1;
    chatEl.hidden = true;
    convsEl.textContent = '';
    convsEl.hidden = false;
    const top = el('div', 'tdc-convs-h');
    const back = el('button', { type: 'button', class: 'tdc-ib', 'data-act': 'back', title: 'Voltar', 'aria-label': 'Voltar à conversa' });
    back.innerHTML = ic('back');
    top.appendChild(back);
    top.appendChild(el('b', null, 'Conversas anteriores'));
    convsEl.appendChild(top);
    const list = el('div', { class: 'tdc-convs-l', role: 'group', 'aria-label': 'Conversas anteriores', 'aria-busy': 'true' });
    list.appendChild(skeleton());
    convsEl.appendChild(list);
    bodyEl.scrollTop = 0;
    syncCompose();
    back.focus({ preventScroll: true });
    const j = await TD.ai({ do: 'convs' });
    if (seq !== S.convSeq) return;
    list.textContent = '';
    list.removeAttribute('aria-busy');
    if (!j.ok) {
      const e = el('div', 'tdc-convs-empty');
      e.appendChild(el('p', null, j.error || 'Não foi possível carregar as conversas.'));
      e.appendChild(el('button', { type: 'button', class: 'btn ghost sm', 'data-act': 'convs' }, 'Tentar outra vez'));
      list.appendChild(e);
      return;
    }
    const items = Array.isArray(j.items) ? j.items : [];
    if (!items.length) { list.appendChild(el('div', 'tdc-convs-empty', 'Ainda não há conversas.')); return; }
    items.forEach(c => {
      if (!c || c.id == null) return;
      const cur = !!(S.conv && String(S.conv.id) === String(c.id));
      const b = el('button', { type: 'button', class: 'tdc-conv' + (cur ? ' is-cur' : ''), 'data-act': 'open-conv', 'data-id': String(c.id), 'aria-current': cur ? 'true' : null });
      b.appendChild(el('span', 'tdc-conv-t', typeof c.title === 'string' && c.title.trim() ? c.title : 'Conversa sem título'));
      const meta = [];
      if (c.updated) meta.push(TD.fmt.rel(num(c.updated)));
      if (c.count != null) meta.push(TD.fmt.n(num(c.count), 'mensagem', 'mensagens'));
      if (cur) meta.push('aberta');
      b.appendChild(el('span', 'tdc-conv-m', meta.join(' · ')));
      list.appendChild(b);
    });
  }
  function backToChat() {
    showChatView();
    syncCompose();
    focusInput();
  }

  // ── conversa: abrir, nova, apagar ───────────────────────
  function setConv(c) {
    if (!c || c.id == null) return;
    S.conv = { id: c.id, title: typeof c.title === 'string' ? c.title : '' };
    TD.store.set('chat.conv', c.id);
  }
  function newConv(opt) {
    closeMenu();
    S.seq++;
    S.chatScroll = null;
    showChatView();
    S.conv = null; S.msgs = []; S.sending = false; S.tooLong = false; S.loadingConv = false; S.loadFailed = false;
    TD.store.set('chat.conv', null);
    if (S.state === 'ready') renderChat();
    if (!(opt && opt.quiet)) focusInput();
  }
  async function loadConv(id, opt) {
    opt = opt || {};
    closeMenu();
    const seq = ++S.seq;
    S.chatScroll = null;
    showChatView();
    S.sending = false; S.tooLong = false; S.loadingConv = true;
    renderChat();
    const j = await TD.ai({ do: 'load', conv: id });
    if (seq !== S.seq) return;
    S.loadingConv = false;
    if (!j.ok) {
      if (j.code === 'not_configured') { S.state = 'setup'; renderAll(); return; }
      // a conversa já não existe (apagada noutro browser): começa uma nova sem alarido
      if (j.status === 404 || j.status === 400) {
        S.conv = null; S.msgs = [];
        TD.store.set('chat.conv', null);
        renderChat();
        if (!opt.quiet) TD.toast(j.error || 'A conversa já não existe.', 'err');
        return;
      }
      S.conv = { id, title: '' };
      S.msgs = [];
      clearChat();
      const w = el('div', 'tdc-empty is-err');
      w.appendChild(icoEl('warn', 'tdc-empty-ico'));
      w.appendChild(el('b', 'tdc-empty-t', 'Não foi possível abrir a conversa'));
      w.appendChild(el('p', null, j.error || 'Erro desconhecido.'));
      const b = el('button', { type: 'button', class: 'btn sm', 'data-act': 'reload-conv' });
      b.innerHTML = ic('retry');
      b.appendChild(document.createTextNode('Tentar outra vez'));
      w.appendChild(b);
      chatEl.appendChild(w);
      S.loadFailed = true;
      syncCompose();
      return;
    }
    S.loadFailed = false;
    setConv(j.conv || { id });
    S.msgs = Array.isArray(j.messages) ? j.messages.filter(m => m && typeof m === 'object') : [];
    renderChat();
    scrollEnd(false);
    if (!opt.quiet) focusInput();
  }
  async function deleteConv() {
    closeMenu();
    if (!S.conv) return;
    const c = S.conv;
    const ok = await TD.dialog.confirm({
      title: 'Apagar esta conversa?',
      text: (c.title ? '«' + c.title + '» e as suas mensagens deixam de existir. ' : 'As mensagens desta conversa deixam de existir. ') + 'Os ficheiros do Drive não são afetados.',
      ok: 'Apagar', danger: true,
    });
    if (!ok) return;
    const j = await TD.ai({ do: 'delete_conv', conv: c.id });
    if (!j.ok) { TD.toast(j.error || 'Não foi possível apagar a conversa.', 'err'); return; }
    if (S.conv && String(S.conv.id) === String(c.id)) newConv();
    TD.toast('Conversa apagada.', 'ok');
  }

  // ── enviar ──────────────────────────────────────────────
  function context() {
    const app = TD.app;
    if (!app || typeof app.current !== 'function') return null;
    try {
      const c = app.current() || {};
      const folder = typeof c.prefix === 'string' && (!c.view || c.view === 'folder') ? c.prefix : '';
      const selection = Array.isArray(c.selection) ? c.selection.filter(k => typeof k === 'string').slice(0, 20) : [];
      return { folder, selection };
    } catch (e) { return null; }
  }
  function canSend() { return S.state === 'ready' && S.view === 'chat' && !S.sending && !S.budget && !S.tooLong && !S.loadingConv && !S.loadFailed; }
  async function send(text, retryOf) {
    text = String(text || '').trim().slice(0, MAX_TEXT);
    if (!text || !canSend()) return;
    if (retryOf) retryOf.remove();
    const seq = S.seq;
    const um = { id: null, role: 'user', text, files: [], actions: [], ts: now() };
    const log = ensureLog();
    const uel = msgNode(um);
    uel.classList.add('is-pending');
    log.appendChild(uel);
    const think = thinkingNode();
    log.appendChild(think);
    S.sending = true;
    syncCompose();
    scrollEnd(true);
    await flushResults();
    const body = { do: 'send', conv: S.conv ? S.conv.id : null, text };
    const ctx = context();
    if (ctx) body.context = ctx;
    const j = await TD.ai(body);
    think._stop();
    if (seq !== S.seq) return;   // entretanto mudou de conversa: a resposta fica guardada no servidor
    S.sending = false;
    uel.classList.remove('is-pending');
    if (j.ok) {
      if (j.conv) setConv(j.conv);
      const ms = (Array.isArray(j.messages) ? j.messages : []).filter(m => m && typeof m === 'object');
      const u = ms.find(m => m.role === 'user');
      if (u) { uel.replaceWith(msgNode(u)); S.msgs.push(u); } else S.msgs.push(um);
      let first = null;
      ms.filter(m => m.role !== 'user').forEach(m => {
        const n = msgNode(m);
        log.appendChild(n);
        if (!first) first = n;
        S.msgs.push(m);
      });
      if (j.spend_eur != null) setSpend({ spend_eur: j.spend_eur });
      syncCompose();
      if (first) revealReply(first); else scrollEnd(true);
      if (!ms.length && S.conv) loadConv(S.conv.id, { quiet: true });   // resposta sem mensagens: recarrega
      return;
    }
    const code = j.code || '';
    if (code === 'not_configured') { S.state = 'setup'; renderAll(); return; }
    if (code === 'budget') {
      setBudget(j.error);
      failNote(uel, 'Não enviada: limite mensal atingido.', null);
      syncCompose();
      return;
    }
    if (code === 'too_long' || (j.status === 404 && body.conv != null)) {
      // conversa longa demais, ou apagada noutro browser: continua numa conversa nova
      S.tooLong = true;
      failNote(uel, 'Não enviada.', null);
      log.appendChild(restartNode(code === 'too_long' ? 'Esta conversa ficou muito longa. Comece uma nova.' : (j.error || 'Esta conversa já não existe.') + ' Comece uma nova.', text));
      syncCompose();
      scrollEnd(true);
      return;
    }
    failNote(uel, j.error || 'Não foi possível obter resposta.', text);
    syncCompose();
    scrollEnd(true);
  }
  /** Erro por baixo da mensagem do utilizador; com texto, botão «Tentar outra vez». */
  function failNote(uel, msg, retryText) {
    uel.classList.add('is-failed');
    const n = el('div', { class: 'tdc-err', role: 'alert' });
    n.appendChild(icoEl('warn'));
    n.appendChild(el('span', null, msg));
    if (retryText != null) {
      const b = el('button', { type: 'button', class: 'tdc-retry', 'data-act': 'retry' });
      b.innerHTML = ic('retry');
      b.appendChild(document.createTextNode('Tentar outra vez'));
      b._text = retryText;
      n.appendChild(b);
    }
    uel.appendChild(n);
  }

  // ── rolar ───────────────────────────────────────────────
  function behavior() { return REDUCED.matches ? 'auto' : 'smooth'; }
  function scrollEnd(smooth) {
    if (S.view !== 'chat') { S.chatScroll = null; return; }   // ao voltar à conversa vai para o fim
    const top = bodyEl.scrollHeight;
    if (smooth) bodyEl.scrollTo({ top, behavior: behavior() }); else bodyEl.scrollTop = top;
  }
  /** Resposta comprida: mostra o início dela; curta: vai até ao fim. */
  function revealReply(n) {
    if (S.view !== 'chat') { S.chatScroll = null; return; }
    const room = bodyEl.clientHeight;
    const top = Math.max(0, n.offsetTop - 12);
    if (n.offsetHeight > room - 60) bodyEl.scrollTo({ top, behavior: behavior() });
    else scrollEnd(true);
  }

  // ── caixa de texto ──────────────────────────────────────
  function autosize() {
    qEl.style.height = 'auto';
    qEl.style.height = Math.min(qEl.scrollHeight + 2, 176) + 'px';
  }
  function syncCompose() {
    const show = S.state === 'ready' && S.view === 'chat';
    formEl.hidden = !show;
    const blocked = !!S.budget || S.tooLong;
    qEl.disabled = blocked;
    qEl.placeholder = S.budget ? 'Limite mensal atingido.' : S.tooLong ? 'Comece uma nova conversa para continuar.' : 'Pergunte sobre os ficheiros…';
    sendBtn.disabled = !canSend() || !qEl.value.trim();
    formEl.classList.toggle('is-busy', S.sending);
    const len = qEl.value.length;
    countEl.textContent = len > MAX_TEXT - 600 ? nfmt.format(len) + ' / ' + nfmt.format(MAX_TEXT) : '';
    countEl.classList.toggle('is-max', len >= MAX_TEXT);
    const del = menuEl.querySelector('[data-act="delete"]');
    if (del) del.setAttribute('aria-disabled', S.conv ? 'false' : 'true');
  }
  function focusInput() {
    if (S.state === 'ready' && S.view === 'chat' && !qEl.disabled && !formEl.hidden) qEl.focus({ preventScroll: true });
    else { const t = host.querySelector('.tdc-body button, .tdc-body a[href]'); if (t) t.focus({ preventScroll: true }); }
  }
  qEl.addEventListener('input', () => { autosize(); syncCompose(); });
  qEl.addEventListener('keydown', e => {
    if (e.key === 'Enter' && !e.shiftKey && !e.altKey && !e.isComposing && e.keyCode !== 229) {
      e.preventDefault();
      submit();
    }
  });
  formEl.addEventListener('submit', e => { e.preventDefault(); submit(); });
  function submit() {
    const text = qEl.value.trim();
    if (!text || !canSend()) return;
    qEl.value = '';
    autosize();
    send(text);
  }

  // ── cabeçalho: gasto do mês, aviso de limite ────────────
  function eur(v, whole) {
    return new Intl.NumberFormat('pt-PT', { style: 'currency', currency: 'EUR', minimumFractionDigits: whole ? 0 : 2, maximumFractionDigits: whole ? 0 : 2 }).format(v);
  }
  function setSpend(st) {
    S.status = Object.assign({}, S.status || {}, st);
    const s = S.status;
    if (s.spend_eur == null && s.cap_eur == null) { spendEl.hidden = true; return; }
    const spend = Math.max(0, num(s.spend_eur)), cap = num(s.cap_eur) > 0 ? num(s.cap_eur) : 10;
    const capTxt = eur(cap, Math.round(cap) === cap);
    spendT.textContent = (spend > 0 && spend < 0.005 ? '< 0,01 €' : eur(spend)) + ' de ' + capTxt + ' este mês · estimativa';
    const pct = Math.min(100, spend / cap * 100);
    meterEl.style.width = (spend > 0 ? Math.max(2, pct) : 0) + '%';
    spendEl.classList.toggle('is-warn', pct >= 80 && pct < 100);
    spendEl.classList.toggle('is-over', pct >= 100);
    spendEl.title = 'Gasto estimado do assistente' + (s.month_label ? ' em ' + s.month_label : ' este mês') +
      ' (conversas e leitura de ficheiros). Ao chegar a ' + capTxt + ' o assistente pára até ao dia 1.';
    spendEl.hidden = false;
    if ((pct >= 100 || s.limit_reached === true) && !S.budget && S.state === 'ready') setBudget('');
  }
  function setBudget(msg) {
    const cap = S.status && num(S.status.cap_eur) > 0 ? num(S.status.cap_eur) : 10;
    S.budget = msg || 'Chegou ao limite mensal de ' + eur(cap, Math.round(cap) === cap) + ' do assistente. Volta a funcionar no dia 1.';
    bannerEl.textContent = '';
    bannerEl.appendChild(icoEl('warn'));
    bannerEl.appendChild(el('span', null, S.budget));
    bannerEl.hidden = false;
    const sugs = bodyEl.querySelector('.tdc-sugs');
    if (sugs) sugs.remove();
    syncCompose();
  }

  // ── menu ────────────────────────────────────────────────
  function openMenu() {
    if (!menuEl.hidden) { closeMenu(); return; }
    menuEl.textContent = '';
    if (S.conv && S.conv.title) menuEl.appendChild(el('div', 'tdc-menu-title', S.conv.title));
    const item = (act, icon, label, o) => {
      const b = el('button', { type: 'button', class: 'tdc-menu-it' + (o && o.danger ? ' danger' : ''), role: 'menuitem', 'data-act': act, tabindex: '-1', 'aria-disabled': o && o.disabled ? 'true' : 'false' });
      b.appendChild(icoEl(icon, 'tdc-menu-ic'));
      b.appendChild(el('span', null, label));
      menuEl.appendChild(b);
    };
    item('new', 'plus', 'Nova conversa');
    item('convs', 'clock', 'Conversas anteriores');
    menuEl.appendChild(el('div', { class: 'tdc-menu-sep', role: 'separator' }));
    item('delete', 'trash', 'Apagar esta conversa', { danger: true, disabled: !S.conv });
    menuEl.hidden = false;
    menuBtn.setAttribute('aria-expanded', 'true');
    const first = menuEl.querySelector('.tdc-menu-it[aria-disabled="false"]');
    if (first) first.focus();
    setTimeout(() => document.addEventListener('mousedown', outside, true), 0);
  }
  function closeMenu(refocus) {
    if (menuEl.hidden) return;
    menuEl.hidden = true;
    menuBtn.setAttribute('aria-expanded', 'false');
    document.removeEventListener('mousedown', outside, true);
    if (refocus) menuBtn.focus();
  }
  function outside(e) { if (!menuEl.contains(e.target) && e.target !== menuBtn && !menuBtn.contains(e.target)) closeMenu(); }
  menuEl.addEventListener('keydown', e => {
    const its = Array.from(menuEl.querySelectorAll('.tdc-menu-it'));
    const i = its.indexOf(document.activeElement);
    if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); closeMenu(true); }
    else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      const d = e.key === 'ArrowDown' ? 1 : -1;
      for (let k = 1; k <= its.length; k++) {
        const t = its[(i + d * k + its.length * 2) % its.length];
        if (t.getAttribute('aria-disabled') !== 'true') { t.focus(); break; }
      }
    } else if (e.key === 'Home' || e.key === 'End') { e.preventDefault(); const l = its.filter(t => t.getAttribute('aria-disabled') !== 'true'); (e.key === 'Home' ? l[0] : l[l.length - 1]).focus(); }
    else if (e.key === 'Tab') closeMenu();
  });

  // ── cliques (delegados) ─────────────────────────────────
  host.addEventListener('click', e => {
    const chipEl = e.target.closest('.tdc-chip');
    if (chipEl && host.contains(chipEl)) { openRef(chipEl.getAttribute('data-key')); return; }
    const t = e.target.closest('[data-act]');
    if (!t || !host.contains(t)) return;
    if (t.getAttribute('aria-disabled') === 'true') return;
    const act = t.getAttribute('data-act');
    switch (act) {
      case 'new': closeMenu(); if (S.state === 'ready') newConv(); break;
      case 'menu': openMenu(); break;
      case 'convs': showConvs(); break;
      case 'delete': deleteConv(); break;
      case 'back': backToChat(); break;
      case 'open-conv': { const id = t.getAttribute('data-id'); loadConv(/^\d+$/.test(id) ? parseInt(id, 10) : id); break; }
      case 'reload-conv': if (S.conv) loadConv(S.conv.id); break;
      case 'collapse': setCollapsed(true); break;
      case 'close': if (TD.app && typeof TD.app.chat === 'function') TD.app.chat(false); else api.close(); break;
      case 'recheck': boot(); break;
      case 'suggest': send(t.textContent); break;
      case 'retry': send(t._text || '', t.closest('.tdc-msg')); break;
      case 'new-with': { const txt = t._text || ''; newConv(); if (txt) send(txt); break; }
      case 'confirm': { const c = t.closest('.tdc-card'); if (c) confirmAction(c); break; }
      case 'cancel': { const c = t.closest('.tdc-card'); if (c) cancelAction(c); break; }
      case 'copy-link': {
        const inp = t.parentNode.querySelector('.tdc-share-u');
        TD.copyText(inp ? inp.value : '').then(ok => {
          TD.toast(ok ? 'Link copiado.' : 'Não foi possível copiar.', ok ? 'ok' : 'err');
          if (ok) { t.textContent = 'Copiado'; setTimeout(() => { t.textContent = 'Copiar'; }, 1800); }
        });
        break;
      }
      case 'copy-msg': {
        const m = t.closest('.tdc-msg');
        TD.copyText(m && m._msg ? plainText(m._msg) : '').then(ok => TD.toast(ok ? 'Resposta copiada.' : 'Não foi possível copiar.', ok ? 'ok' : 'err'));
        break;
      }
    }
  });
  railEl.addEventListener('click', () => { setCollapsed(false); setTimeout(focusInput, 30); });
  boxEl.addEventListener('pointerenter', refreshSuggestions);
  boxEl.addEventListener('focusin', e => { if (!e.target.closest('.tdc-sugs')) refreshSuggestions(); });   // sem tirar o foco a uma sugestão

  /** Clique num chip: pasta → navega; ficheiro → pré-visualização (o app.js decide). */
  function openRef(key) {
    if (!key) return;
    const app = TD.app;
    if (!app) { TD.toast('O Drive ainda não está pronto.', 'err'); return; }
    if (P.isFolder(key)) {
      if (typeof app.navigate === 'function') app.navigate(key);
      if (NARROW.matches) api.close();   // em ecrãs estreitos mostra a pasta
    } else if (typeof app.openFile === 'function') app.openFile(key);
  }

  // ── recolher (ecrã largo) e painel deslizante (ecrã estreito) ──
  function setCollapsed(on) {
    S.collapsed = !!on;
    root.classList.toggle('chat-collapsed', S.collapsed);
    TD.store.set('chat.collapsed', S.collapsed);
    if (on) closeMenu();
    if (on && !NARROW.matches) railEl.focus({ preventScroll: true });
    // a coluna muda de largura: quem se posiciona pela área do Drive (barra de envios, listas) volta a medir
    clearTimeout(setCollapsed.t);
    setCollapsed.t = setTimeout(() => window.dispatchEvent(new Event('resize')), 260);
  }
  root.classList.toggle('chat-collapsed', S.collapsed);
  // a largura só se anima depois do arranque (sem animação ao abrir a página já recolhida)
  requestAnimationFrame(() => requestAnimationFrame(() => root.classList.add('chat-ready')));
  // ao passar para ecrã estreito o painel deslizante começa fechado (não tapa o Drive por surpresa)
  const onNarrow = e => { if (e.matches && root.classList.contains('chat-open')) api.close(); };
  if (NARROW.addEventListener) NARROW.addEventListener('change', onNarrow); else if (NARROW.addListener) NARROW.addListener(onNarrow);

  // ── índice de conteúdo: estado e ciclo do index_tick ────
  const IX = { timer: 0, busy: false, fails: 0, again: false, poke: 0 };
  function ixWants() { return !!(S.index && num(S.index.pending) > 0 && !S.index.paused); }
  function setIndex(st) {
    S.index = st && typeof st === 'object' ? st : null;
    renderIndex();
  }
  function renderIndex() {
    const st = S.index;
    if (!st || !(num(st.total) > 0)) { idxEl.hidden = true; return; }
    const done = num(st.done), pend = num(st.pending), err = num(st.errors);
    const of = done + pend + err;
    idxEl.textContent = '';
    idxEl.className = 'tdc-idx' + (st.paused ? ' is-paused' : pend > 0 ? ' is-busy' : ' is-done');
    idxEl.appendChild(el('span', 'tdc-idx-dot'));
    const txt = of > 0 ? 'Índice: ' + nfmt.format(done) + ' de ' + nfmt.format(of) + ' ficheiros lidos' + (err > 0 ? ' · ' + nfmt.format(err) + ' com erro' : '') : 'Índice: nenhum ficheiro para ler';
    idxEl.appendChild(el('span', 'tdc-idx-t', txt));
    if (st.paused) idxEl.appendChild(el('span', 'tdc-idx-p', 'Em pausa: ' + String(st.paused)));
    const tip = ['Lidos: ' + nfmt.format(done), 'Na fila: ' + nfmt.format(pend), 'Com erro: ' + nfmt.format(err)];
    if (num(st.skipped)) tip.push('Não lidos (vídeo, áudio e outros formatos): ' + nfmt.format(num(st.skipped)));
    if (num(st.private)) tip.push('Privados (Documentos legais): ' + nfmt.format(num(st.private)));
    if (st.ai === false) tip.push('Sem a chave da API só se leem textos e documentos Office (PDF e imagens ficam de fora).');
    idxEl.title = tip.join('\n');
    idxEl.hidden = false;
  }
  function scheduleTick(ms) {
    clearTimeout(IX.timer);
    IX.timer = 0;
    if (document.visibilityState === 'hidden') return;   // retoma quando o separador voltar a estar visível
    IX.timer = setTimeout(tick, ms);
  }
  async function tick() {
    IX.timer = 0;
    if (document.visibilityState === 'hidden') return;
    if (IX.busy) { IX.again = true; return; }
    IX.busy = true;
    const wasBusy = ixWants();
    const j = await TD.api({ do: 'index_tick' });
    IX.busy = false;
    if (!j.ok) {
      IX.fails++;
      if (IX.fails <= 3 && (ixWants() || IX.again)) scheduleTick(15000 * IX.fails);
      IX.again = false;
      return;
    }
    IX.fails = 0;
    setIndex('index' in j ? j.index : j);
    if (ixWants() || (IX.again && S.index && !S.index.paused)) scheduleTick(IX.again ? 300 : TICK_MS);
    else if (wasBusy && S.state === 'ready') refreshStatus();   // acabou de ler: o gasto pode ter mudado
    IX.again = false;
  }
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'hidden') { clearTimeout(IX.timer); IX.timer = 0; return; }
    if (ixWants() && !IX.timer && !IX.busy) scheduleTick(800);
    if (S.state === 'ready' && Date.now() - (S.statusAt || 0) > 5 * 60000) refreshStatus();
  });
  // Ficheiros novos, movidos ou apagados: o índice reconcilia-se num tick (com uma pequena espera).
  TD.bus.on('drive:changed', d => {
    if (!d) return;
    if ((d.kind === 'move' || d.kind === 'rename') && Array.isArray(d.items)) d.items.forEach(x => x && remapChips(x.from, x.to));
    if (d.kind === 'trash' && Array.isArray(d.keys)) d.keys.forEach(k => goneChips(k));
    if (['upload', 'move', 'copy', 'rename', 'trash', 'restore', 'purge'].indexOf(d.kind) < 0) return;
    if (!S.index || S.index.paused) return;
    clearTimeout(IX.poke);
    IX.poke = setTimeout(() => { if (IX.busy) IX.again = true; else scheduleTick(0); }, 2500);
  });
  /** Os chips seguem os ficheiros movidos ou renomeados nesta página. */
  function remapChips(from, to) {
    if (typeof from !== 'string' || typeof to !== 'string' || !from || !to) return;
    host.querySelectorAll('.tdc-chip').forEach(c => {
      const k = c.getAttribute('data-key');
      let nk = null;
      if (k === from) nk = to;
      else if (P.isFolder(from) && k.indexOf(from) === 0) nk = to + k.slice(from.length);
      if (!nk) return;
      c.setAttribute('data-key', nk);
      c.classList.remove('is-gone');
      if (k === from) { const n = c.querySelector('.tdc-chip-n'); if (n) n.textContent = P.base(nk); }
      c.title = chipTitle(nk, null);
    });
  }
  function goneChips(key) {
    host.querySelectorAll('.tdc-chip').forEach(c => {
      const k = c.getAttribute('data-key');
      if (k === key || (P.isFolder(key) && k.indexOf(key) === 0)) { c.classList.add('is-gone'); c.title = 'No Lixo · ' + chipTitle(k, null); }
    });
  }

  // ── arranque ────────────────────────────────────────────
  async function refreshStatus() {
    const j = await TD.ai({ do: 'status' });
    if (!j.ok) return;
    S.statusAt = Date.now();
    setSpend(j);
    if (j.index !== undefined) setIndex(j.index);
    if (ixWants() && !IX.timer && !IX.busy) scheduleTick(TICK_MS);
  }
  let booting = false;
  async function boot() {
    if (booting) return;
    booting = true;
    S.state = 'loading';
    S.budget = '';
    renderAll();
    const j = await TD.ai({ do: 'status' });
    booting = false;
    S.statusAt = Date.now();
    let index;
    if (!j.ok) {
      S.state = j.code === 'not_configured' ? 'setup' : 'down';
      S.downMsg = j.error || '';
      renderAll();
      // o índice também funciona sem o assistente (textos e Office): pergunta ao drive_api
      const k = await TD.api({ do: 'index_status' });
      index = k.ok ? ('index' in k ? k.index : k) : null;
    } else {
      S.state = j.configured === false ? 'setup' : 'ready';
      setSpend(j);
      index = j.index;
      renderAll();
      if (S.state === 'ready') {
        flushResults();
        const id = TD.store.get('chat.conv', null);
        if (id != null && id !== '') loadConv(id, { quiet: true });
      }
    }
    setIndex(index);
    // um tick logo no início reconcilia o catálogo (ficheiros enviados noutro browser); depois só enquanto houver pendentes
    if (S.index && !S.index.paused) scheduleTick(ixWants() ? 1500 : 5000);
  }

  // ── API pública ─────────────────────────────────────────
  const api = {
    init() { /* arranca sozinho; fica por compatibilidade */ return api; },
    /** Foca a caixa de texto (abre o painel se estiver recolhido). */
    focus() {
      if (!NARROW.matches && S.collapsed) setCollapsed(false);
      focusInput();
    },
    /** Ecrã estreito: mostra o painel deslizante. Ecrã largo: expande se estiver recolhido. */
    open() {
      if (NARROW.matches) root.classList.add('chat-open');
      else if (S.collapsed) setCollapsed(false);
    },
    close() {
      closeMenu();
      if (NARROW.matches) root.classList.remove('chat-open');
      else setCollapsed(true);
    },
    isOpen() { return NARROW.matches ? root.classList.contains('chat-open') : !S.collapsed; },
    /** Faz uma pergunta (ex.: a partir de outro módulo). */
    ask(text) { api.open(); if (S.view !== 'chat') backToChat(); send(text); },
  };
  TD.chat = api;
  boot();
})();
