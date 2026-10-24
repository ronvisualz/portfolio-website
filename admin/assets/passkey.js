/* topper. admin — chaves de acesso (passkeys) no browser: entrar (o browser propõe a chave no campo do email, ou
   «Entrar com chave de acesso») e criar uma chave nova no Perfil. A chave privada nunca sai do aparelho. */
(function () {
  'use strict';
  if (!window.PublicKeyCredential || !navigator.credentials) {
    document.querySelectorAll('[data-passkey-login], [data-passkey-new]').forEach(function (b) { b.hidden = true; });
    document.querySelectorAll('[data-passkey-nosupport]').forEach(function (n) { n.hidden = false; });
    return;
  }
  function toB64u(buf) {
    var s = '', b = new Uint8Array(buf);
    for (var i = 0; i < b.length; i++) s += String.fromCharCode(b[i]);
    return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  }
  function fromB64u(s) {
    s = s.replace(/-/g, '+').replace(/_/g, '/');
    while (s.length % 4) s += '=';
    var bin = atob(s), out = new Uint8Array(bin.length);
    for (var i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i);
    return out.buffer;
  }
  function csrf() { var i = document.querySelector('input[name=csrf]'); return i ? i.value : ''; }
  function api(a, body) {
    var o = { credentials: 'same-origin', headers: { 'Accept': 'application/json' } };
    if (body) { o.method = 'POST'; o.headers['Content-Type'] = 'application/json'; o.headers['X-CSRF-Token'] = csrf(); o.body = JSON.stringify(body); }
    return fetch('/admin/passkey.php?a=' + a, o).then(function (r) { return r.json().catch(function () { return { error: 'Resposta inválida do servidor.' }; }); });
  }
  function why(err) {
    if (err && err.name === 'NotAllowedError') return 'Cancelado (ou o tempo acabou).';
    if (err && err.name === 'InvalidStateError') return 'Este aparelho já tem uma chave de acesso para a sua conta.';
    if (err && err.name === 'SecurityError') return 'Este endereço não permite chaves de acesso.';
    return (err && err.message) || 'Não foi possível usar a chave.';
  }

  // ── entrar ──
  var loginBtn = document.querySelector('[data-passkey-login]');
  var out = document.querySelector('[data-passkey-out]');
  var say = function (t, bad) { if (!out) return; out.hidden = !t; out.textContent = t || ''; out.className = 'flash ' + (bad ? 'err' : 'ok'); };
  var pending = null, entered = false;
  function signIn(mediation) {
    return api('login_options').then(function (o) {
      if (o.error) throw new Error(o.error);
      if (pending) pending.abort();
      pending = window.AbortController ? new AbortController() : null;
      var pk = { challenge: fromB64u(o.challenge), rpId: o.rpId, timeout: o.timeout, userVerification: o.userVerification, allowCredentials: [] };
      var req = { publicKey: pk };
      if (mediation) req.mediation = mediation;
      if (pending) req.signal = pending.signal;
      return navigator.credentials.get(req);
    }).then(function (c) {
      if (!c) return null;
      var r = c.response;
      say('A entrar…');
      return api('login', { id: c.id, clientDataJSON: toB64u(r.clientDataJSON), authenticatorData: toB64u(r.authenticatorData),
                            signature: toB64u(r.signature), userHandle: r.userHandle ? toB64u(r.userHandle) : null });
    }).then(function (j) {
      if (!j || entered) return;
      if (j.error) { say(j.error, true); return; }
      entered = true;
      location.href = j.go || '/admin/';
    });
  }
  if (loginBtn) {
    loginBtn.hidden = false;
    loginBtn.addEventListener('click', function () {
      say('');
      signIn(null).catch(function (e) { if (e && e.name === 'AbortError') return; say(why(e), true); });
    });
    // o browser propõe a chave logo no campo do email (preenchimento automático)
    if (PublicKeyCredential.isConditionalMediationAvailable) {
      PublicKeyCredential.isConditionalMediationAvailable().then(function (ok) {
        if (ok) signIn('conditional').catch(function () { /* sem chave escolhida: segue a palavra-passe */ });
      });
    }
  }

  // ── criar uma chave (Perfil › Segurança) ──
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('[data-passkey-new]');
    if (!b) return;
    var form = b.closest('form') || document, name = form.querySelector('input[name=pk_name]');
    var note = form.querySelector('[data-passkey-note]');
    var tell = function (t, bad) { if (note) { note.hidden = false; note.textContent = t; note.className = 'small ' + (bad ? 'neg' : 'pos'); } };
    e.preventDefault();
    b.disabled = true; tell('Confirme no aparelho (Face ID, impressão digital ou PIN)…');
    api('reg_options').then(function (o) {
      if (o.error) throw new Error(o.error);
      o.challenge = fromB64u(o.challenge);
      o.user.id = fromB64u(o.user.id);
      o.excludeCredentials = (o.excludeCredentials || []).map(function (x) { return { type: 'public-key', id: fromB64u(x.id) }; });
      return navigator.credentials.create({ publicKey: o });
    }).then(function (c) {
      var r = c.response;
      return api('reg', { id: c.id, clientDataJSON: toB64u(r.clientDataJSON), attestationObject: toB64u(r.attestationObject),
                          transports: r.getTransports ? r.getTransports() : [], name: name ? name.value : '' });
    }).then(function (j) {
      b.disabled = false;
      if (j.error) { tell(j.error, true); return; }
      tell(j.msg, false);
      setTimeout(function () { location.reload(); }, 900);
    }).catch(function (err) { b.disabled = false; tell(why(err), true); });
  });
})();
