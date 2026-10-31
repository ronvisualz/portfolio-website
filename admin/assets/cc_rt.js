/* topper. admin — Toppernote: tempo real dos Topperboards (§26)
 *
 * O Topperboard continua a gravar cada alteração na base de dados (cc_api). Esta camada leva-as aos
 * outros logo (Yjs, num Durable Object da Cloudflare por Topperboard) e mostra quem está no Topperboard:
 * avatares, cursores com nome e cor, seleções, «está a escrever…», seguir uma pessoa.
 *
 * Documento Yjs do Topperboard:
 *   cards: Map<id, Map{x,y,w,h,col,ord,lane,c(JSON do conteúdo),del,pub(JSON do cartão novo),t,by}>
 *          só os cartões mexidos desde que o Topperboard abriu em tempo real; t = hora (do servidor) da mudança
 *   meta:  Map{reload: {n, by}} — «recarreguem da base» (depois de ações feitas no servidor)
 * Ao entrar, o que é mais recente ganha: a base (updated_at do cartão) ou o documento (t).
 *
 * Sem ligação: as alterações ficam numa fila (localStorage) e gravam-se ao voltar; o documento
 * Yjs junta-se sozinho quando a ligação volta. Se o limite diário do plano gratuito for atingido,
 * o Topperboard funciona como antes (grava e atualiza a cada 8 s) e o tempo real volta às 00:00 UTC.
 *
 * Usado pelo cc.js: window.ccRealtime(ganchos) devolve o objeto RT (ou um que não faz nada).
 */
(function () {
  'use strict';

  var SKEW = 2000;   // tolerância (ms) entre a hora do documento e a da base

  function noop() {}
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function throttle(fn, ms) {
    var last = 0, timer = 0, args = null;
    return function () {
      args = arguments;
      var now = Date.now(), wait = ms - (now - last);
      if (wait <= 0) { clearTimeout(timer); timer = 0; last = now; fn.apply(null, args); }
      else if (!timer) timer = setTimeout(function () { timer = 0; last = Date.now(); fn.apply(null, args); }, wait);
    };
  }
  function same(a, b) { return typeof a === 'number' && typeof b === 'number' ? Math.abs(a - b) < 0.06 : (a == null ? null : a) === (b == null ? null : b); }

  /** A fila de alterações por gravar (sem ligação). Funciona mesmo sem o serviço de tempo real. */
  function Outbox(boardId, api, onChange) {
    var key = 'cc-outbox-' + boardId, items = {};
    try { items = JSON.parse(localStorage.getItem(key) || '{}') || {}; } catch (e) { items = {}; }
    function store() { try { if (Object.keys(items).length) localStorage.setItem(key, JSON.stringify(items)); else localStorage.removeItem(key); } catch (e) {} onChange(); }
    var busy = false;
    return {
      size: function () { return Object.keys(items).length; },
      add: function (list) { list.forEach(function (it) { items[it.id] = Object.assign(items[it.id] || {}, it); }); store(); },
      flush: function () {
        var list = Object.keys(items).map(function (k) { return items[k]; });
        if (!list.length || busy) return Promise.resolve(null);
        busy = true;
        var sent = JSON.parse(JSON.stringify(items));
        return api('cards_update', { board: boardId, items: JSON.stringify(list) }).then(function () {
          Object.keys(sent).forEach(function (k) { if (JSON.stringify(items[k]) === JSON.stringify(sent[k])) delete items[k]; });
          store();
          return list;
        }, function () { return null; }).then(function (r) { busy = false; return r; });
      }
    };
  }

  window.ccRealtime = function (h) {
    var B = h.B, info = B.data.rt, me = info ? info.me : null;
    var RT = {
      connected: false, live: noop, publish: noop, added: noop, removed: noop, signal: noop, comments: noop, decorate: noop,
      view: noop, selection: noop, typing: noop, userMoved: noop, cursor: noop, escape: function () { return false; },
      queue: noop, mount: noop, reconcile: noop, flush: function () { return Promise.resolve(); }
    };
    var chip = null, people = null, state = 'off', outbox;

    // ── estado (o ponto ao lado dos avatares) ──
    function setState(s) { state = s; paint(); }
    function paint() {
      if (!chip) return;
      var n = outbox.size(), t, cls = state;
      if (state === 'on') t = n ? 'Ligado · a gravar ' + n + (n === 1 ? ' alteração' : ' alterações') : 'Ligado · tudo sincronizado';
      else if (state === 'connecting') t = 'A ligar…';
      else if (state === 'offline') t = 'Sem ligação' + (n ? ' · ' + n + (n === 1 ? ' alteração guardada' : ' alterações guardadas') : '') + ' · sincroniza ao voltar';
      else if (state === 'paused') t = 'Tempo real em pausa (limite do dia) · o Topperboard continua e sincroniza';
      else { cls = n ? 'offline' : 'off'; t = n ? n + (n === 1 ? ' alteração por gravar' : ' alterações por gravar') : ''; }
      chip.className = 'rt-st ' + cls;
      chip.hidden = !t;
      chip.innerHTML = '<i aria-hidden="true"></i><span>' + esc(t) + '</span>';
      chip.setAttribute('title', t);
    }

    outbox = Outbox(B.id, h.api, paint);
    function flush() {
      return outbox.flush().then(function (list) {
        if (list && list.length) { RT.publish(list); h.toast(list.length === 1 ? 'A alteração feita sem ligação foi gravada.' : 'As ' + list.length + ' alterações feitas sem ligação foram gravadas.'); }
        if (list && RT.connected && state === 'offline') state = 'on';
        paint();
      });
    }
    RT.queue = function (items) { outbox.add(items); if (state === 'on') setState('offline'); };
    RT.flush = flush;
    window.addEventListener('online', function () { flush(); });
    setInterval(function () { if (outbox.size() && navigator.onLine) flush(); }, 15000);

    RT.mount = function (acts) {
      var wrap = document.createElement('div');
      wrap.className = 'ccb-rt';
      wrap.innerHTML = '<div class="rt-people" data-rt-people></div><span class="rt-st" hidden></span>';
      acts.insertBefore(wrap, acts.firstChild);
      people = wrap.firstChild; chip = wrap.lastChild;
      people.addEventListener('click', function (e) { var b = e.target.closest('[data-follow]'); if (b) follow(+b.getAttribute('data-follow')); });
      paint();
      renderPeople();
    };

    if (!info || !window.TopperRT) { if (outbox.size()) flush(); return RT; }
    if (info.paused) { state = 'paused'; if (outbox.size()) flush(); return RT; }

    // ── a ligação ──
    var TR = window.TopperRT, Y = TR.Y;
    var doc = new Y.Doc(), cards = doc.getMap('cards'), meta = doc.getMap('meta');
    var offset = (B.data.now || Date.now()) - Date.now();   // hora do servidor - hora deste computador
    function now() { return Date.now() + offset; }
    var usage = { conns: 0, msgs: 0, wins: {} };
    function countMsg() { usage.msgs++; usage.wins[Math.floor(now() / 10000)] = 1; }
    var NativeWS = window.WebSocket;
    function CountWS(url, protocols) {
      var ws = protocols ? new NativeWS(url, protocols) : new NativeWS(url);
      var send = ws.send.bind(ws);
      ws.send = function (d) { countMsg(); return send(d); };
      return ws;
    }
    CountWS.CONNECTING = 0; CountWS.OPEN = 1; CountWS.CLOSING = 2; CountWS.CLOSED = 3;
    var tokenAt = Date.now(), firstToken = info.token;
    function token() {
      if (firstToken && Date.now() - tokenAt < (info.ttl - 60) * 1000) { var t = firstToken; firstToken = null; return Promise.resolve(t); }
      return h.api('rt_token', { board: B.id }, 'GET').then(function (j) { return j.token; });
    }
    try { new TR.IndexeddbPersistence('topper-cc-' + info.room, doc); } catch (e) { /* modo privado: só em memória */ }
    var provider = new TR.YProvider(info.host, info.room, doc, {
      party: 'board', protocol: info.secure ? 'wss' : 'ws', disableBc: true, WebSocketPolyfill: CountWS,
      params: function () { return token().then(function (t) { return { t: t }; }); }
    });
    var aw = provider.awareness;
    setState('connecting');
    var fails = 0;
    provider.on('status', function (e) {
      if (e.status === 'connected') { fails = 0; usage.conns++; RT.connected = true; setState('on'); flush(); }
      else if (e.status === 'disconnected') {
        RT.connected = false;
        fails++;
        setState(navigator.onLine ? (fails > 3 ? 'offline' : 'connecting') : 'offline');
        if (fails === 4 && navigator.onLine) checkHealth();
      }
    });
    // quando falha várias vezes com rede: o serviço respondeu? (se não, pode ser o limite do dia)
    function checkHealth() {
      fetch((info.secure ? 'https://' : 'http://') + info.host + '/health', { cache: 'no-store' }).then(function (r) {
        if (r.status === 429 || r.status === 503) setState('paused');
      }, function () {});
    }
    var synced = false;
    provider.on('sync', function (ok) { if (ok && !synced) { synced = true; reconcile(); } });

    // o uso conta-se aqui e soma-se no servidor (indicador e aviso aos 80% em Definições)
    function report(beacon) {
      var wins = Object.keys(usage.wins);
      if (!usage.conns && !usage.msgs) return;
      var data = { board: B.id, conns: usage.conns, msgs: usage.msgs, wins: wins.join(',') };
      usage = { conns: 0, msgs: 0, wins: {} };
      if (beacon && navigator.sendBeacon) {
        var fd = new FormData();
        Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
        fd.append('csrf', h.csrf);
        navigator.sendBeacon(h.apiUrl + '?a=rt_usage', fd);
      } else h.api('rt_usage', data).catch(noop);
    }
    setInterval(function () { report(false); }, 60000);
    window.addEventListener('pagehide', function () { report(true); });
    document.addEventListener('visibilitychange', function () { if (document.hidden) report(true); });

    // ── cartões: o que chega dos outros ──
    function entry(id) { var m = cards.get(String(id)); return m && m.toJSON ? m.toJSON() : null; }
    function toLocal(c, f) {
      var colChanged = 'col' in f && (f.col || null) !== (c.col || null);
      var reshape = colChanged || ('ord' in f && f.ord !== c.ord) || ('lane' in f && f.lane !== c.lane);
      var redraw = false;
      ['x', 'y'].forEach(function (k) { if (typeof f[k] === 'number') c[k] = f[k]; });
      ['w', 'h'].forEach(function (k) { if (typeof f[k] === 'number' && !same(c[k], f[k])) { c[k] = f[k]; redraw = true; } });
      if ('col' in f) c.col = f.col || null;
      if ('ord' in f) c.ord = f.ord;
      if ('lane' in f) c.lane = f.lane || '';
      if (typeof f.c === 'string' && f.c !== JSON.stringify(c.c || {})) { try { c.c = JSON.parse(f.c); redraw = true; } catch (e) {} }
      if (f.t) c.ut = Math.max(c.ut || 0, f.t);
      return reshape ? 2 : redraw ? 1 : 0;
    }
    function applyRemote(ids) {
      var full = false, redraw = [], moved = [];
      ids.forEach(function (id) {
        id = +id;
        var f = entry(id);
        if (!f || h.busy(id)) return;
        var c = B.cards.get(id);
        if (f.del) { if (c) { h.drop(id); full = true; } return; }
        if (!c) {
          if (!f.pub) return;
          try { c = JSON.parse(f.pub); } catch (e) { return; }
          B.cards.set(id, c);
          toLocal(c, f);
          full = true;
          return;
        }
        var k = toLocal(c, f);
        if (k === 2) full = true; else if (k === 1) redraw.push(c); else moved.push(c);
      });
      if (full || redraw.length > 30) { h.renderAll(); return; }
      redraw.forEach(function (c) { h.rerender(c); });
      moved.forEach(function (c) { h.glide(c); });
      if (moved.length || redraw.length) h.drawArrows();
    }
    cards.observeDeep(function (events, tr) {
      if (tr.local || !synced) return;
      var ids = {};
      events.forEach(function (ev) {
        if (ev.target === cards) ev.changes.keys.forEach(function (v, k) { ids[k] = 1; });
        else if (ev.path && ev.path.length) ids[ev.path[0]] = 1;
      });
      applyRemote(Object.keys(ids));
    });
    meta.observe(function (ev, tr) {
      if (tr.local || !synced) return;
      if (ev.keysChanged.has('reload')) h.reload();
    });

    /** O que é mais recente ganha: o documento (t) ou a base (ut do cartão). */
    function reconcile() {
      var write = [], take = [];
      cards.forEach(function (m, k) {
        var f = m.toJSON(), id = +k, c = B.cards.get(id);
        if (!c) { if (!f.del && f.pub && (f.t || 0) > (B.loadedAt || 0) - SKEW) take.push(k); return; }
        if (f.del) { if ((f.t || 0) > (c.ut || 0) + SKEW) take.push(k); else write.push(c); return; }
        var differs = ['x', 'y', 'w', 'h', 'ord'].some(function (p) { return p in f && !same(f[p], c[p]); })
          || ('col' in f && (f.col || null) !== (c.col || null)) || ('lane' in f && (f.lane || '') !== (c.lane || ''))
          || (typeof f.c === 'string' && f.c !== JSON.stringify(c.c || {}));
        if (!differs) return;
        if ((f.t || 0) > (c.ut || 0) + SKEW) take.push(k); else write.push(c);
      });
      if (take.length) applyRemote(take);
      if (write.length) doc.transact(function () { write.forEach(function (c) { setEntry(c.id, fields(c), c.ut || now(), false); }); });
    }
    RT.reconcile = function () { if (synced) reconcile(); };

    // ── o que eu mudo ──
    function fields(c) { return { x: c.x, y: c.y, w: c.w, h: c.h, col: c.col || null, ord: c.ord, lane: c.lane || '', c: JSON.stringify(c.c || {}) }; }
    function setEntry(id, f, t, del) {
      var k = String(id), m = cards.get(k);
      if (!m) { m = new Y.Map(); cards.set(k, m); }
      Object.keys(f).forEach(function (p) { if (f[p] !== undefined && !(typeof f[p] === 'number' && !isFinite(f[p]))) m.set(p, f[p]); });
      m.set('del', !!del);
      m.set('t', t);
      m.set('by', me.id);
    }
    function itemFields(it) {
      var f = {};
      ['x', 'y', 'w', 'h', 'ord'].forEach(function (p) { if (typeof it[p] === 'number') f[p] = it[p]; });
      if ('col' in it) f.col = it.col || null;
      if ('lane' in it) f.lane = it.lane || '';
      if (it.content) f.c = JSON.stringify(it.content);
      return f;
    }
    RT.publish = function (items) {
      if (!items || !items.length) return;
      doc.transact(function () { items.forEach(function (it) { if (it && it.id) setEntry(it.id, itemFields(it), now(), false); }); });
    };
    RT.live = throttle(function (ids) {
      doc.transact(function () { ids.forEach(function (id) { var c = B.cards.get(id); if (c) setEntry(id, { x: c.x, y: c.y, w: c.w, h: c.h }, now(), false); }); });
    }, 70);
    RT.added = function (list) {
      doc.transact(function () {
        (list || []).forEach(function (c) {
          if (!c || !c.id) return;
          var pub = Object.assign({}, c);
          delete pub._mh; delete pub.__new;
          var f = fields(c);
          f.pub = JSON.stringify(pub);
          setEntry(c.id, f, now(), false);
        });
      });
    };
    RT.removed = function (ids) { doc.transact(function () { (ids || []).forEach(function (id) { setEntry(id, {}, now(), true); }); }); };
    RT.signal = function () { meta.set('reload', { n: Math.random(), by: me.id, t: now() }); };

    // ── comentários e @menções ──
    RT.comments = function (card, mentioned) {
      try { provider.sendMessage(JSON.stringify({ k: 'cmt', card: card || 0, by: me.id, name: me.first, ment: mentioned || [] })); } catch (e) {}
    };
    provider.on('custom-message', function (msg) {
      var m;
      try { m = JSON.parse(msg); } catch (e) { return; }
      if (!m || m.k !== 'cmt' || m.by === me.id) return;
      h.onComments(m.card || 0);
      if ((m.ment || []).indexOf(me.id) >= 0) h.toast((m.name || 'Alguém') + ' mencionou-o num comentário deste Topperboard.', { action: { label: 'Ver', fn: function () { h.openComments(m.card || 0); } } });
    });

    // ── presença ──
    aw.setLocalState({ u: me, cur: null, sel: [], vw: null, ty: 0 });
    var sendCursor = throttle(function (p) { aw.setLocalStateField('cur', p); }, 50);
    RT.cursor = function (p) { sendCursor(p ? [Math.round(p.x), Math.round(p.y)] : null); };
    var sendView = throttle(function () { aw.setLocalStateField('vw', h.visibleRect()); }, 150);
    RT.selection = function () { aw.setLocalStateField('sel', Array.from(B.sel).slice(0, 200)); };
    RT.typing = function (id) { aw.setLocalStateField('ty', id || 0); };

    var curEl = null, others = new Map();   // clientID → estado
    function ensureCursors() {
      var vp = h.vp();
      if (!vp) return null;
      if (!curEl || !curEl.isConnected) { curEl = document.createElement('div'); curEl.className = 'ccb-cursors'; curEl.setAttribute('aria-hidden', 'true'); vp.appendChild(curEl); }
      return curEl;
    }
    function paintCursors() {
      var box = ensureCursors();
      if (!box) return;
      var v = B.view, seen = {};
      others.forEach(function (s, cid) {
        if (!s.cur || !s.u) return;
        seen[cid] = 1;
        var el = box.querySelector('[data-c="' + cid + '"]');
        if (!el) {
          el = document.createElement('div');
          el.className = 'rtc';
          el.setAttribute('data-c', cid);
          el.style.setProperty('--c', s.u.color);
          el.innerHTML = '<svg viewBox="0 0 16 16" width="16" height="16"><path d="M1 1l5.5 13.5 2-5.5 5.5-2z"/></svg><span>' + esc(s.u.first || s.u.name) + '</span>';
          box.appendChild(el);
        }
        el.style.transform = 'translate3d(' + (s.cur[0] * v.s + v.x) + 'px,' + (s.cur[1] * v.s + v.y) + 'px,0)';
      });
      Array.prototype.slice.call(box.children).forEach(function (el) { if (!seen[el.getAttribute('data-c')]) el.remove(); });
    }
    var decorated = [];
    RT.decorate = function () {
      decorated.forEach(function (el) { el.classList.remove('rsel'); el.style.removeProperty('--rsel'); var t = el.querySelector(':scope > .rt-tag'); if (t) t.remove(); });
      decorated = [];
      others.forEach(function (s) {
        if (!s.u) return;
        (s.sel || []).forEach(function (id) {
          var el = h.elOf(id);
          if (!el) return;
          el.classList.add('rsel');
          el.style.setProperty('--rsel', s.u.color);
          decorated.push(el);
        });
        if (s.ty) {
          var el = h.elOf(s.ty);
          if (el && !el.querySelector(':scope > .rt-tag')) {
            var t = document.createElement('span');
            t.className = 'rt-tag';
            t.style.setProperty('--c', s.u.color);
            t.textContent = (s.u.first || s.u.name) + ' está a escrever…';
            el.appendChild(t);
            if (decorated.indexOf(el) < 0) decorated.push(el);
          }
        }
      });
    };
    function renderPeople() {
      if (!people) return;
      var byUser = new Map();
      others.forEach(function (s, cid) { if (s.u && !byUser.has(s.u.id)) byUser.set(s.u.id, { s: s, cid: cid }); });
      var list = Array.from(byUser.values()), max = 5;
      people.innerHTML = list.slice(0, max).map(function (o) {
        var u = o.s.u, on = following === o.cid;
        return '<button type="button" class="rtav' + (on ? ' on' : '') + '" style="--c:' + esc(u.color) + '" data-follow="' + o.cid + '" aria-pressed="' + (on ? 'true' : 'false') + '" data-tip aria-label="' + esc(u.name + (on ? ' · a seguir (Esc para sair)' : ' · clique para seguir')) + '">' + esc(u.ini) + '</button>';
      }).join('') + (list.length > max ? '<span class="rtav more" title="' + esc(list.slice(max).map(function (o) { return o.s.u.name; }).join(', ')) + '">+' + (list.length - max) + '</span>' : '');
      people.hidden = !list.length;
    }
    aw.on('change', function () {
      var prev = others;
      others = new Map();
      aw.getStates().forEach(function (s, cid) { if (cid !== doc.clientID && s && s.u && s.u.id !== me.id) others.set(cid, s); });
      if (following && !others.has(following)) unfollow(true);
      var changedPeople = prev.size !== others.size || Array.from(others.keys()).some(function (k) { return !prev.has(k); });
      if (changedPeople) renderPeople();
      paintCursors();
      RT.decorate();
      if (following) { var fs = others.get(following); if (fs && fs.vw) h.showRect(fs.vw); }
    });

    // ── seguir uma pessoa ──
    var following = 0, banner = null;
    function follow(cid) {
      if (following === cid) { unfollow(); return; }
      var s = others.get(cid);
      if (!s) return;
      following = cid;
      var vp = h.vp();
      if (!banner) { banner = document.createElement('div'); banner.className = 'ccb-follow'; banner.setAttribute('role', 'status'); }
      banner.style.setProperty('--c', s.u.color);
      banner.innerHTML = '<span>A seguir <b>' + esc(s.u.name) + '</b></span><button type="button" data-unfollow>Parar (Esc)</button>';
      banner.querySelector('[data-unfollow]').onclick = function () { unfollow(); };
      vp.appendChild(banner);
      vp.classList.add('following');
      vp.style.setProperty('--follow', s.u.color);
      if (s.vw) h.showRect(s.vw);
      renderPeople();
    }
    function unfollow(gone) {
      if (!following) return;
      var s = others.get(following);
      following = 0;
      if (banner) banner.remove();
      var vp = h.vp();
      if (vp) vp.classList.remove('following');
      renderPeople();
      if (gone) h.toast('A pessoa que seguia saiu do Topperboard.');
      else if (s && s.u) h.toast('Deixou de seguir ' + s.u.first + '.');
    }
    RT.userMoved = function () { if (following) unfollow(); };
    RT.escape = function () { if (!following) return false; unfollow(); return true; };
    RT.view = function () { paintCursors(); sendView(); };

    window.addEventListener('beforeunload', function () { try { aw.setLocalState(null); } catch (e) {} });
    return RT;
  };
})();
