/**
 * topper. admin — o que a Equipa (§17) põe em todas as páginas:
 *   · o sinal da sessão (~30 s, só com a página à vista): online/offline, não lidas, Foco.
 *     Só diz «estou aqui»; nunca o que a pessoa está a fazer.
 *   · o chat privado 1:1 (botão da barra de topo, tecla C, [data-chat-with="id"])
 *   · pedidos de reunião no chat: Aceitar · Recusar · Propor outra hora · Cancelar
 *   · a disponibilidade de uma pessoa ([data-avail="id"]) e o formulário de reunião
 *   · o modo Foco
 */
(function () {
  'use strict';
  var API = '/admin/team_api.php';
  var csrfMeta = document.querySelector('meta[name="csrf"]');
  var csrf = csrfMeta ? csrfMeta.content : '';
  var PING_MS = 30000, THREAD_MS = 4000;

  function $(s, el) { return (el || document).querySelector(s); }
  function $$(s, el) { return Array.prototype.slice.call((el || document).querySelectorAll(s)); }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function typing(el) { return el && (el.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(el.tagName)); }
  function T() { return window.topper || { toast: function () { return { close: function () {} }; }, openMdl: function () {}, closeMdl: function () {} }; }
  function toast(m, o) { return T().toast(m, o); }
  var ICO = {
    leads: '<path d="M4 6h16M7 12h10M10 18h4"/>',
    negocios: '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M9 7V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2"/>',
    contactos: '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
    tarefas: '<path d="m4 12 5 5L20 6"/>',
    marketing: '<path d="M3 11v2a1 1 0 0 0 1 1h3l5 4V6L7 10H4a1 1 0 0 0-1 1zM16 8a5 5 0 0 1 0 8"/>',
    video: '<rect x="3" y="6" width="13" height="12" rx="2"/><path d="m16 10 5-3v10l-5-3"/>'
  };
  function ico(k) { return '<svg class="ico" viewBox="0 0 24 24" aria-hidden="true">' + (ICO[k] || ICO.tarefas) + '</svg>'; }

  function api(action, data, method) {
    method = method || (data ? 'POST' : 'GET');
    var url = API + '?a=' + encodeURIComponent(action);
    var opt = { method: method, credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-CSRF-Token': csrf } };
    if (method === 'GET' && data) {
      Object.keys(data).forEach(function (k) { url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(data[k]); });
    } else if (data) {
      var fd = data instanceof FormData ? data : new FormData();
      if (!(data instanceof FormData)) {
        Object.keys(data).forEach(function (k) {
          var v = data[k];
          if (Array.isArray(v)) v.forEach(function (x) { fd.append(k + '[]', x); }); else fd.append(k, v == null ? '' : v);
        });
      }
      fd.set('a', action);
      fd.set('csrf', csrf);
      opt.body = fd;
    }
    return fetch(url, opt).then(function (r) {
      return r.json().catch(function () { return { ok: false, error: 'Não foi possível concluir. Tente outra vez.', status: r.status }; })
        .then(function (j) { if (r.status === 401) j.expired = true; return j; });
    }, function () { return { ok: false, error: 'Sem ligação. Tente outra vez.', offline: true }; }).then(function (j) {
      if (!j || !j.ok) { var e = new Error((j && j.error) || 'Não foi possível concluir.'); e.expired = j && j.expired; e.offline = j && j.offline; throw e; }
      return j;
    });
  }
  window.topperTeam = { api: api };

  // ── sinal da sessão ────────────────────────────────────────
  var badge = $('.chat-btn .badge'), pingTimer = null, stopped = false, focusUntil = '';
  var seen = 0;
  try { seen = parseInt(sessionStorage.getItem('chat-seen') || '0', 10) || 0; } catch (x) { /* sem memória: avisa outra vez */ }
  function setUnread(n) {
    if (!badge) return;
    badge.hidden = !n;
    badge.textContent = n > 99 ? '99+' : String(n);
  }
  function setFocus(f) {
    focusUntil = f || '';
    document.documentElement.classList.toggle('in-focus', !!focusUntil);
    var bar = $('[data-focus-bar]');
    if (bar) { bar.hidden = !focusUntil; bar.textContent = focusUntil ? 'Em foco até ' + focusUntil + ': chat e notificações em silêncio.' : ''; }
    var off = $('[data-focus-off]'); if (off) off.hidden = !focusUntil;
    var btn = $('.chat-btn'); if (btn) btn.classList.toggle('focus', !!focusUntil);
  }
  function presenceIds() {
    var ids = {};
    $$('[data-pres]').forEach(function (n) { ids[n.getAttribute('data-pres')] = 1; });
    return Object.keys(ids).slice(0, 200).join(',');
  }
  function applyPresence(map) {
    Object.keys(map || {}).forEach(function (id) {
      var p = map[id];
      $$('[data-pres="' + id + '"]').forEach(function (n) { n.classList.toggle('on', !!p.on); n.classList.toggle('off', !p.on); });
      $$('[data-plabel="' + id + '"]').forEach(function (n) { n.textContent = p.on ? 'Online' : p.label; n.classList.toggle('on', !!p.on); });
    });
  }
  function ping() {
    clearTimeout(pingTimer);
    if (stopped) return;
    if (document.hidden) { pingTimer = setTimeout(ping, PING_MS); return; }
    var ids = presenceIds();
    api('ping', ids ? { ids: ids } : {}, 'GET').then(function (j) {
      setUnread(j.unread);
      setFocus(j.focus);
      applyPresence(j.presence);
      if (j.last && j.last.id > seen && !j.focus) {
        var open = panelOpen() && threadWith === j.last.from;
        seen = j.last.id;
        try { sessionStorage.setItem('chat-seen', String(seen)); } catch (x) { /* fica só nesta página */ }
        if (!open) notify(j.last);
      }
      if (j.post && j.post.id > seenPost && !j.focus) {
        seenPost = j.post.id;
        try { sessionStorage.setItem('chat-seen-post', String(seenPost)); } catch (x) { /* fica só nesta página */ }
        if (!(panelOpen() && threadWith === -1)) notifyPost(j.post);
      }
      if (panelOpen() && !threadWith) loadList();
    }).catch(function (e) { if (e.expired) stopped = true; }).then(function () {
      if (!stopped) pingTimer = setTimeout(ping, PING_MS);
    });
  }
  var seenPost = 0;
  try { seenPost = parseInt(sessionStorage.getItem('chat-seen-post') || '0', 10) || 0; } catch (x) { /* avisa outra vez */ }
  function notifyPost(p) {
    toast(p.title);
    var t = $$('.toasts .tst'); t = t[t.length - 1];
    if (!t) return;
    var b = document.createElement('button');
    b.type = 'button'; b.className = 'undo'; b.textContent = 'Ver';
    b.addEventListener('click', function () { if (t.parentNode) t.parentNode.removeChild(t); openChat(0); openTeam(); });
    t.insertBefore(b, t.querySelector('.x'));
  }
  // Os avisos da equipa (pódio, recorde, comercial do mês): só leitura, no lugar de uma conversa.
  function openTeam() {
    clearTimeout(threadTimer);
    threadWith = -1; lastId = 0;
    list.hidden = true; thread.hidden = false; homeBtn.hidden = false;
    form.hidden = true; thread.classList.add('is-team');
    who.innerHTML = '<span class="tav sm chat-team-ico" aria-hidden="true">★</span><span><b>Equipa · avisos</b><small>Hall of fame e anúncios do Dashboard</small></span>';
    msgs.innerHTML = '<div class="sp-loading">A carregar…</div>';
    api('team_posts', null, 'GET').then(function (j) {
      if (threadWith !== -1) return;
      msgs.innerHTML = j.posts.length ? '' : '<p class="muted empty-s">Ainda não há avisos.</p>';
      j.posts.forEach(function (p) {
        var d = document.createElement('div');
        d.className = 'cmsg theirs team-post k-' + p.kind;
        d.innerHTML = '<div class="ctext"><b>' + esc(p.title) + '</b><br>' + p.html + '</div>'
          + (p.url ? '<a class="rcard" href="' + esc(p.url) + '"><span><small>Dashboard</small><b>Ver no Hall of fame</b></span></a>' : '') + '<time>' + esc(p.at) + '</time>';
        msgs.appendChild(d);
      });
      msgs.scrollTop = msgs.scrollHeight;
      ping();
    }).catch(function (e) { msgs.innerHTML = '<p class="muted empty-s">' + esc(e.message) + '</p>'; });
  }
  function notify(last) {
    toast('Nova mensagem de ' + last.name);
    var t = $$('.toasts .tst'); t = t[t.length - 1];
    if (!t) return;
    var b = document.createElement('button');
    b.type = 'button'; b.className = 'undo'; b.textContent = 'Abrir';
    b.addEventListener('click', function () { if (t.parentNode) t.parentNode.removeChild(t); openChat(last.from); });
    t.insertBefore(b, t.querySelector('.x'));
  }
  document.addEventListener('visibilitychange', function () { if (!document.hidden && !stopped) ping(); });
  setTimeout(ping, 600);

  // ── painel de chat ─────────────────────────────────────────
  var panel = $('#chatp'), back = $('.chat-back'), list = $('[data-chat-list]', panel), thread = $('[data-chat-thread]', panel);
  var msgs = $('[data-chat-msgs]', panel), form = $('[data-chat-form]', panel), who = $('[data-chat-who]', panel), homeBtn = $('[data-chat-home]', panel);
  var threadWith = 0, threadPerson = null, lastId = 0, threadTimer = null, opener = null;
  function panelOpen() { return panel && panel.classList.contains('open'); }
  function openChat(withId, from) {
    if (!panel) return;
    opener = from || document.activeElement;
    panel.hidden = false;
    void panel.offsetWidth;
    panel.classList.add('open');
    if (back) back.classList.add('open');
    document.documentElement.classList.add('chat-open');
    if (withId) openThread(withId); else showList();
  }
  function closeChat() {
    if (!panelOpen()) return;
    panel.classList.remove('open');
    if (back) back.classList.remove('open');
    document.documentElement.classList.remove('chat-open');
    clearTimeout(threadTimer); threadWith = 0;
    setTimeout(function () { if (!panelOpen()) panel.hidden = true; }, 260);
    if (opener && opener.focus && document.contains(opener)) opener.focus({ preventScroll: true });
  }
  function showList() {
    clearTimeout(threadTimer);
    threadWith = 0; threadPerson = null;
    thread.hidden = true; list.hidden = false; homeBtn.hidden = true; form.hidden = false; thread.classList.remove('is-team');
    who.innerHTML = '<b>Chat</b><small>Conversas privadas 1:1 e avisos da equipa</small>';
    loadList();
    setTimeout(function () { var f = $('.chat-search', list); (f || panel).focus({ preventScroll: true }); }, 30);
  }
  var listQ = '';
  function loadList() {
    api('chat_list', null, 'GET').then(function (j) {
      setFocus(j.focus);
      var html = '<label class="sr" for="chat-q">Procurar pessoa</label><input id="chat-q" class="chat-search" type="search" placeholder="Procurar pessoa…" value="' + esc(listQ) + '">';
      if (j.team) html += '<button type="button" class="chat-it chat-team' + (j.team.unread ? ' unread' : '') + '" data-chat-team data-q="equipa avisos">'
        + '<span class="tav sm chat-team-ico" aria-hidden="true">★</span><span class="ci-t"><b>Equipa · avisos</b><small>' + esc(j.team.last) + '</small></span>'
        + '<span class="ci-m">' + (j.team.unread ? '<span class="cnt">' + j.team.unread + '</span>' : '') + '</span></button>';
      if (!j.people.length) html += '<p class="muted empty-s">Ainda não há mais ninguém na equipa.</p>';
      html += j.people.map(function (p) {
        return '<button type="button" class="chat-it' + (p.unread ? ' unread' : '') + '" data-chat-person="' + p.id + '" data-q="' + esc((p.name + ' ' + p.role).toLowerCase()) + '">'
          + p.avatar + '<span class="ci-t"><b>' + esc(p.name) + '</b><small>' + esc(p.last || (p.role || '')) + '</small></span>'
          + '<span class="ci-m"><small data-plabel="' + p.id + '">' + esc(p.online ? 'Online' : p.presence) + '</small>' + (p.unread ? '<span class="cnt">' + p.unread + '</span>' : '') + '</span></button>';
      }).join('');
      var had = document.activeElement && document.activeElement.id === 'chat-q';
      list.innerHTML = html;
      filterList();
      if (had) { var q = $('#chat-q'); q.focus(); q.setSelectionRange(q.value.length, q.value.length); }
    }).catch(function (e) { list.innerHTML = '<p class="muted empty-s">' + esc(e.message) + '</p>'; });
  }
  function filterList() {
    var q = listQ.trim().toLowerCase();
    $$('.chat-it', list).forEach(function (b) { b.hidden = q !== '' && b.getAttribute('data-q').indexOf(q) < 0; });
  }
  if (list) list.addEventListener('input', function (e) { if (e.target.id === 'chat-q') { listQ = e.target.value; filterList(); } });
  if (list) list.addEventListener('keydown', function (e) {
    if (e.target.id === 'chat-q' && e.key === 'Enter') { var b = $$('.chat-it', list).filter(function (x) { return !x.hidden; })[0]; if (b) { e.preventDefault(); b.click(); } }
  });

  function openThread(id) {
    clearTimeout(threadTimer);
    threadWith = +id; lastId = 0;
    list.hidden = true; thread.hidden = false; homeBtn.hidden = false; form.hidden = false; thread.classList.remove('is-team');
    msgs.innerHTML = '<div class="sp-loading">A carregar…</div>';
    who.innerHTML = '<b>…</b>';
    pollThread(true);
  }
  function pollThread(first) {
    clearTimeout(threadTimer);
    var id = threadWith;
    if (!id) return;
    api('chat_thread', { 'with': id, after: first ? 0 : lastId }, 'GET').then(function (j) {
      if (id !== threadWith) return;
      var w = j['with'];
      threadPerson = w;
      who.innerHTML = w.avatar + '<span><b>' + esc(w.name) + '</b><small><span data-plabel="' + w.id + '">' + esc(w.online ? 'Online' : w.presence) + '</span> · ' + esc(w.state) + '</small></span>';
      var mail = $('[data-chat-mail]', panel), tel = $('[data-chat-tel]', panel);
      mail.href = 'mailto:' + w.email;
      tel.hidden = !w.phone; if (w.phone) tel.href = 'tel:' + w.phone.replace(/[^\d+]/g, '');
      if (first) msgs.innerHTML = j.messages.length ? '' : '<p class="muted empty-s">Ainda não há mensagens. Diga olá 👋</p>';
      appendMsgs(j.messages);
      if (first) setTimeout(function () { $('textarea', form).focus({ preventScroll: true }); }, 30);
    }).catch(function (e) { if (first) msgs.innerHTML = '<p class="muted empty-s">' + esc(e.message) + '</p>'; }).then(function () {
      if (id === threadWith && !document.hidden) threadTimer = setTimeout(function () { pollThread(false); }, THREAD_MS);
      else if (id === threadWith) threadTimer = setTimeout(function () { pollThread(false); }, PING_MS);
    });
  }
  function appendMsgs(arr) {
    if (!arr.length) return;
    var empty = $('.empty-s', msgs); if (empty) empty.remove();
    var near = msgs.scrollHeight - msgs.scrollTop - msgs.clientHeight < 80;
    arr.forEach(function (m) {
      if (m.id <= lastId && $('[data-mid="' + m.id + '"]', msgs)) return;
      lastId = Math.max(lastId, m.id);
      var d = document.createElement('div');
      d.className = 'cmsg ' + (m.mine ? 'mine' : 'theirs') + (m.meeting ? ' has-meet' : '');
      d.setAttribute('data-mid', m.id);
      d.innerHTML = msgHtml(m);
      msgs.appendChild(d);
    });
    if (near || arr.some(function (m) { return m.mine; })) msgs.scrollTop = msgs.scrollHeight;
  }
  var MEET_ST = { pending: 'Por responder', accepted: 'Aceite', declined: 'Recusado', proposed: 'Outra hora proposta', cancelled: 'Cancelado' };
  function msgHtml(m) {
    var h = '';
    if (m.meeting) {
      var mt = m.meeting;
      h += '<div class="meetc st-' + esc(mt.status) + '">' + ico('video')
        + '<div><b>' + esc(mt.subject) + '</b><small>' + esc(mt.when) + ' · ' + esc(mt.platform) + (mt.deal ? ' · ' + esc(mt.deal) : '') + '</small>'
        + '<span class="mst">' + esc(MEET_ST[mt.status] || mt.status) + '</span>'
        + (mt.link ? '<a href="' + esc(mt.link) + '" target="_blank" rel="noopener noreferrer" class="mlink">' + esc(mt.link) + '</a>' : '');
      if (mt.can_answer) {
        h += (mt.needs_link ? '<label class="sr" for="ml-' + mt.id + '">Link da chamada</label><input type="url" id="ml-' + mt.id + '" class="mlink-in" placeholder="Cole o link da chamada (https://…)">' : '')
          + '<div class="mbtns"><button type="button" class="btn sm" data-meet-accept="' + mt.id + '">Aceitar</button>'
          + '<button type="button" class="btn ghost sm" data-meet-decline="' + mt.id + '">Recusar</button>'
          + '<button type="button" class="btn ghost sm" data-meet-propose="' + mt.id + '" data-start="' + esc(mt.start) + '">Propor outra hora</button></div>';
      } else if (mt.can_cancel) {
        h += '<div class="mbtns"><button type="button" class="btn ghost sm" data-meet-decline="' + mt.id + '" data-cancel>Cancelar pedido</button></div>';
      }
      h += '</div></div>';
    } else {
      h += '<div class="ctext">' + m.html + '</div>';
    }
    (m.cards || []).forEach(function (c) {
      h += '<a class="rcard" href="' + esc(c.url) + '"' + (c.panel ? ' data-rc-panel="' + esc(c.panel) + '"' : '') + '>' + ico(c.icon)
        + '<span><small>' + esc(c.kind) + '</small><b>' + esc(c.title) + '</b></span></a>';
    });
    return h + '<time>' + esc(m.at) + '</time>';
  }
  function reloadThread() { if (threadWith) { lastId = 0; pollThread(true); } }

  if (form) {
    var ta = $('textarea', form);
    var grow = function () { ta.style.height = 'auto'; ta.style.height = Math.min(ta.scrollHeight, 140) + 'px'; };
    ta.addEventListener('input', grow);
    ta.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); form.requestSubmit ? form.requestSubmit() : form.dispatchEvent(new Event('submit', { cancelable: true })); }
    });
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var body = ta.value.trim();
      if (!body || !threadWith) return;
      var btn = $('button[type=submit]', form); btn.disabled = true;
      api('chat_send', { to: threadWith, body: body }).then(function (j) {
        ta.value = ''; grow();
        appendMsgs([j.message]);
        if (j.focus) toast(j.focus);
      }).catch(function (er) { toast(er.message, { err: true }); }).then(function () { btn.disabled = false; ta.focus(); });
    });
  }

  // Os cartões de registos abrem no painel lateral quando a página o tem.
  document.addEventListener('click', function (e) {
    var rc = e.target.closest && e.target.closest('.rcard[data-rc-panel]');
    if (rc && T().openPanel && !e.metaKey && !e.ctrlKey && document.querySelector('#sp')) { e.preventDefault(); closeChat(); T().openPanel(rc.getAttribute('data-rc-panel')); }
  });

  // ── reuniões no chat ───────────────────────────────────────
  document.addEventListener('click', function (e) {
    var t = e.target.closest ? e.target : null;
    if (!t) return;
    var b;
    if ((b = t.closest('[data-meet-accept]'))) {
      var id = b.getAttribute('data-meet-accept'), inp = $('#ml-' + id);
      if (inp && inp.value.trim() === '') { inp.focus(); toast('Cole o link da chamada para aceitar: a integração ainda não está configurada.', { err: true }); return; }
      b.disabled = true;
      api('meeting_accept', { id: id, link: inp ? inp.value.trim() : '' }).then(function (j) { toast(j.msg); reloadThread(); ping(); })
        .catch(function (er) { b.disabled = false; toast(er.message, { err: true }); });
    } else if ((b = t.closest('[data-meet-decline]'))) {
      b.disabled = true;
      api('meeting_decline', { id: b.getAttribute('data-meet-decline'), note: '' }).then(function (j) { toast(j.msg); reloadThread(); ping(); })
        .catch(function (er) { b.disabled = false; toast(er.message, { err: true }); });
    } else if ((b = t.closest('[data-meet-propose]'))) {
      var m = $('#mdl-propose'), f = $('[data-propose-form]', m), st = (b.getAttribute('data-start') || '').split(' ');
      f.elements.id.value = b.getAttribute('data-meet-propose');
      f.elements.day.value = st[0] || ''; f.elements.time.value = st[1] || '';
      T().openMdl(m, b);
    }
  });
  var pf = $('[data-propose-form]');
  if (pf) pf.addEventListener('submit', function (e) {
    e.preventDefault();
    api('meeting_propose', { id: pf.elements.id.value, start: pf.elements.day.value + ' ' + pf.elements.time.value }).then(function (j) {
      T().closeMdl(pf.closest('.mdl')); toast(j.msg); reloadThread();
    }).catch(function (er) { toast(er.message, { err: true }); });
  });

  // ── Foco ───────────────────────────────────────────────────
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('[data-focus], [data-focus-off]');
    if (!b) return;
    var off = b.hasAttribute('data-focus-off');
    api('focus', off ? { off: 1 } : { minutes: b.getAttribute('data-focus') }).then(function (j) {
      setFocus(j.focus); toast(j.msg);
      var d = b.closest('details'); if (d) d.open = false;
      document.dispatchEvent(new CustomEvent('topper:focus', { detail: j.focus }));
    }).catch(function (er) { toast(er.message, { err: true }); });
  });

  // ── disponibilidade ────────────────────────────────────────
  var av = $('#mdl-avail'), avId = 0, avWeek = '', avData = null;
  function openAvail(id, from) {
    avId = +id; avWeek = '';
    $('[data-avail-grid]', av).innerHTML = '<div class="sp-loading">A carregar…</div>';
    $('[data-avail-name]', av).textContent = '';
    if (!av.classList.contains('open')) T().openMdl(av, from);
    loadAvail();
  }
  function loadAvail() {
    api('availability', { id: avId, w: avWeek }, 'GET').then(function (j) {
      avData = j; avWeek = j.monday;
      $('[data-avail-name]', av).textContent = j.person.name;
      $('[data-avail-sub]', av).textContent = 'Horário: ' + j.person.hours + ' · agora: ' + j.person.state;
      $('[data-avail-label]', av).textContent = j.label;
      var dates = Object.keys(j.days);
      if (!dates.length) { $('[data-avail-grid]', av).innerHTML = '<p class="muted">Sem horário nesta semana.</p>'; return; }
      var DOW = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'];
      var h = '<table class="avt"><thead><tr><th></th>' + dates.map(function (d) {
        var dt = new Date(d + 'T12:00:00');
        return '<th>' + DOW[dt.getDay()] + '<small>' + d.slice(8, 10) + '/' + d.slice(5, 7) + '</small></th>';
      }).join('') + '</tr></thead><tbody>';
      j.rows.forEach(function (hm) {
        h += '<tr><th>' + (hm.slice(3) === '00' ? hm : '') + '</th>';
        dates.forEach(function (d) {
          var s = j.days[d][hm] || ['off', ''];
          if (s[0] === 'free' && !j.me) h += '<td class="free"><button type="button" data-slot="' + d + ' ' + hm + '" aria-label="Livre ' + d + ' ' + hm + ' — pedir reunião">' + '</button></td>';
          else h += '<td class="' + s[0] + '"' + (s[1] && s[0] !== 'free' ? ' title="' + esc(s[1]) + '"' : '') + '>' + (s[0] === 'busy' || s[0] === 'absent' ? '<span>' + esc(s[1]) + '</span>' : '') + '</td>';
        });
        h += '</tr>';
      });
      $('[data-avail-grid]', av).innerHTML = h + '</tbody></table><p class="avl"><i class="free"></i>Livre <i class="busy"></i>Ocupado <i class="absent"></i>Ausente</p>';
    }).catch(function (e) { $('[data-avail-grid]', av).innerHTML = '<p class="muted">' + esc(e.message) + '</p>'; });
  }
  if (av) {
    av.addEventListener('click', function (e) {
      var g = e.target.closest('[data-avail-go]');
      if (g && avData) { avWeek = g.getAttribute('data-avail-go') === 'prev' ? avData.prev : avData.next; loadAvail(); return; }
      var s = e.target.closest('[data-slot]');
      if (s && avData) {
        var p = s.getAttribute('data-slot').split(' ');
        T().closeMdl(av);
        setTimeout(function () { openMeet([avData.person.id], avData.person.name, p[0], p[1], null); }, 320);
      }
    });
  }
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('[data-avail]');
    if (b && !(e.target.closest('[data-org-edit]') && b.__dragged)) { e.preventDefault(); openAvail(b.getAttribute('data-avail'), b); return; }
    var c = e.target.closest && e.target.closest('[data-chat-avail]');
    if (c && threadWith) { e.preventDefault(); openAvail(threadWith, c); }
  });
  document.addEventListener('keydown', function (e) {
    var b = e.target.closest && e.target.closest('.onode[data-avail]');
    if (b && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); openAvail(b.getAttribute('data-avail'), b); }
  });

  // ── pedir reunião ──────────────────────────────────────────
  var mm = $('#mdl-meet'), mf = mm ? $('[data-meet-form]', mm) : null, dealsLoaded = false;
  function openMeet(ids, names, day, time, from) {
    if (!mf) return;
    mf.reset();
    $('[data-meet-ids]', mf).innerHTML = ids.map(function (i) { return '<input type="hidden" name="to[]" value="' + (+i) + '">'; }).join('');
    $('[data-meet-to]', mf).textContent = names ? '· ' + names : '';
    mf.elements.day.value = day || '';
    mf.elements.time.value = time || '';
    if (!dealsLoaded) {
      dealsLoaded = true;
      api('deals', null, 'GET').then(function (j) {
        var s = mf.elements.deal_id;
        j.deals.forEach(function (d) { var o = document.createElement('option'); o.value = d.id; o.textContent = d.label; s.appendChild(o); });
      }).catch(function () { dealsLoaded = false; });
    }
    T().openMdl(mm, from);
  }
  window.topperTeam.openMeet = openMeet;
  window.topperTeam.openChat = openChat;
  window.topperTeam.ping = ping;
  if (mf) mf.addEventListener('submit', function (e) {
    e.preventDefault();
    var fd = new FormData(mf), btn = $('button[type=submit]', mf);
    btn.disabled = true;
    api('meeting_request', fd).then(function (j) {
      T().closeMdl(mm);
      toast(j.msg);
      if (panelOpen() && threadWith === j['with']) reloadThread();
      document.dispatchEvent(new CustomEvent('topper:meeting'));
    }).catch(function (er) { toast(er.message, { err: true }); }).then(function () { btn.disabled = false; });
  });

  // ── abrir e fechar ─────────────────────────────────────────
  document.addEventListener('click', function (e) {
    if (!e.target.closest) return;
    var o = e.target.closest('[data-chat-open]');
    if (o) { e.preventDefault(); if (panelOpen()) closeChat(); else openChat(0, o); return; }
    var w = e.target.closest('[data-chat-with]');
    if (w) { e.preventDefault(); openChat(+w.getAttribute('data-chat-with'), w); return; }
    if (e.target.closest('[data-chat-close]')) { e.preventDefault(); closeChat(); return; }
    if (e.target.closest('[data-chat-home]')) { e.preventDefault(); showList(); return; }
    var p = e.target.closest('[data-chat-person]');
    if (p) { e.preventDefault(); openThread(+p.getAttribute('data-chat-person')); return; }
    if (e.target.closest('[data-chat-team]')) { e.preventDefault(); openTeam(); }
  });
  document.addEventListener('keydown', function (e) {
    if (e.metaKey || e.ctrlKey || e.altKey) return;
    if (document.querySelector('.mdl.open') || document.querySelector('.cmdk.open')) return;
    if (e.key === 'Escape' && panelOpen()) {
      if (threadWith && !typing(document.activeElement)) { e.preventDefault(); showList(); return; }
      e.preventDefault(); e.stopImmediatePropagation(); closeChat(); return;
    }
    if ((e.key === 'c' || e.key === 'C') && !typing(document.activeElement)) {
      e.preventDefault();
      if (panelOpen()) closeChat(); else openChat(0, $('.chat-btn'));
    }
  }, true);
  // ?chat=id abre a conversa (vem das notificações).
  var qm = /[?&]chat=(\d+)/.exec(location.search);
  if (qm) setTimeout(function () { openChat(+qm[1]); }, 200);
})();
