/* topper. — Portal do cliente: quem é (nome e e-mail, guardados neste browser), avisos com «Anular»,
 * o registo de acesso (por POST: o GET nunca altera nada) e a sala de review em modo cliente. */
(function () {
  'use strict';
  var P = window.PORTAL; if (!P) return;
  var EN = P.lang === 'en';
  var S = {
    idTitle: EN ? 'Before you continue' : 'Antes de continuar', idText: EN ? 'Tell us who you are: your name appears next to your comments and approvals.' : 'Diga-nos quem é: o nome aparece junto dos seus comentários e aprovações.',
    name: EN ? 'Name' : 'Nome', email: EN ? 'Email' : 'E-mail', go: EN ? 'Continue' : 'Continuar', cancel: EN ? 'Cancel' : 'Cancelar', undo: EN ? 'Undo' : 'Anular',
    close: EN ? 'Close' : 'Fechar', offline: EN ? 'No connection. Please try again.' : 'Sem ligação. Tente outra vez.', bad: EN ? 'Write your name and a valid email.' : 'Escreva o nome e um e-mail válido.'
  };
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

  // ── quem é ────────────────────────────────────────────────
  function identity() {
    try { var o = JSON.parse(localStorage.getItem(P.idKey) || 'null'); return o && o.name && o.email && /^[a-f0-9]{32}$/.test(o.key || '') ? o : null; } catch (e) { return null; }
  }
  function newKey() {
    var a = new Uint8Array(16); (window.crypto || window.msCrypto).getRandomValues(a);
    return Array.prototype.map.call(a, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
  }
  var ME = identity(), ANON = null;
  function anonKey() { if (!ANON) ANON = newKey(); return ANON; }
  function identify() {
    return new Promise(function (resolve) {
      var m = document.createElement('div'); m.className = 'mdl open'; m.setAttribute('role', 'dialog'); m.setAttribute('aria-modal', 'true');
      m.innerHTML = '<div class="mdl-back" data-x></div><form class="mdl-card" autocomplete="on"><h3>' + esc(S.idTitle) + '</h3><p class="muted">' + esc(S.idText) + '</p>'
        + '<label class="field"><span>' + esc(S.name) + '</span><input type="text" name="name" required minlength="2" maxlength="160" autocomplete="name"></label>'
        + '<label class="field"><span>' + esc(S.email) + '</span><input type="email" name="email" required maxlength="190" autocomplete="email"></label>'
        + '<div class="actions"><button class="btn" type="submit">' + esc(S.go) + '</button><button class="btn ghost" type="button" data-x>' + esc(S.cancel) + '</button></div></form>';
      document.body.appendChild(m);
      var f = m.querySelector('form'), done = false;
      function close(ok) { if (done) return; done = true; m.remove(); resolve(ok); }
      m.addEventListener('click', function (e) { if (e.target.closest('[data-x]')) close(false); });
      m.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(false); });
      f.addEventListener('submit', function (e) {
        e.preventDefault();
        var n = f.name.value.trim(), em = f.email.value.trim();
        if (n.length < 2 || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(em)) { toast(S.bad, { err: true }); return; }
        ME = { name: n, email: em, key: (ME && ME.key) || newKey() };
        try { localStorage.setItem(P.idKey, JSON.stringify(ME)); } catch (er) {}
        post('ident', {}).catch(function () {});
        close(true);
      });
      setTimeout(function () { f.name.focus(); }, 30);
    });
  }

  // ── pedidos ───────────────────────────────────────────────
  function post(a, params) {
    var fd = new URLSearchParams();
    fd.append('a', a); fd.append('ts', P.ts); fd.append('sig', P.sig);
    fd.append('name', ME ? ME.name : ''); fd.append('email', ME ? ME.email : ''); fd.append('ck', ME ? ME.key : anonKey());
    Object.keys(params || {}).forEach(function (k) { if (params[k] != null) fd.append(k, params[k]); });
    return fetch(P.url + (EN ? '&lang=en' : ''), { method: 'POST', credentials: 'omit', headers: { Accept: 'application/json' }, body: fd })
      .then(function (r) { return r.json().catch(function () { throw new Error(S.offline); }); }, function () { throw new Error(S.offline); })
      .then(function (j) {
        if (j.ident) return identify().then(function (ok) { if (!ok) throw new Error(j.error); return post(a, params); });
        if (!j.ok) throw new Error(j.error || S.offline);
        return j;
      });
  }
  function get(q) {
    return fetch(P.url + '&' + new URLSearchParams(q) + (EN ? '&lang=en' : ''), { credentials: 'omit', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json(); }, function () { throw new Error(S.offline); })
      .then(function (j) { if (!j.ok) throw new Error(j.error || S.offline); return j; });
  }

  // ── avisos (com «Anular») ─────────────────────────────────
  var box = document.querySelector('.toasts');
  function toast(msg, o) {
    o = o || {};
    if (!box) { window.alert(msg); return { close: function () {} }; }
    var t = document.createElement('div'); t.className = 'tst' + (o.err ? ' err' : ''); t.setAttribute('role', o.err ? 'alert' : 'status');
    t.innerHTML = '<span class="tx"></span>'; t.firstChild.textContent = msg;
    var timer;
    function close() { clearTimeout(timer); t.remove(); }
    if (typeof o.undo === 'function') {
      var b = document.createElement('button'); b.type = 'button'; b.className = 'undo'; b.textContent = S.undo;
      b.addEventListener('click', function () { close(); o.undo(); }); t.appendChild(b);
    }
    var x = document.createElement('button'); x.type = 'button'; x.className = 'x'; x.setAttribute('aria-label', S.close);
    x.innerHTML = '<svg class="ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>'; x.addEventListener('click', close); t.appendChild(x);
    box.appendChild(t);
    timer = setTimeout(close, o.undo ? 8000 : (o.err ? 7000 : 4500));
    return { close: close };
  }

  // ── o acesso fica registado (POST, uma vez por abertura) ──
  post('seen', P.item ? { item: P.item } : {}).catch(function () {});

  // ── a sala de review ──────────────────────────────────────
  var el = document.getElementById('rv-room');
  if (el && P.item && window.TopperReview) {
    var withItem = function (p) { var o = {}; Object.keys(p || {}).forEach(function (k) { o[k] = p[k]; }); o.item = P.item; return o; };
    TopperReview.mount(el, {
      mode: 'client', lang: P.lang, canComment: true, wmText: P.wm || '', toast: toast,
      load: function () { return get({ j: 'room', p: P.item, ck: ME ? ME.key : '' }); },
      poll: function (rev) { return get({ j: 'poll', p: P.item, rev: rev }); },
      call: function (a, p) { return post(a, withItem(p)); },
      identity: function () { return ME; },
      identify: identify,
      download: function (v) { post('download', { v: v }).then(function (j) { location.href = j.url; }).catch(function (e) { toast(e.message, { err: true }); }); }
    });
  }
})();
