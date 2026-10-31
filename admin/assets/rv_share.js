/* topper. admin — cartão «Portal do cliente» na ficha do negócio: criar, copiar, renovar e revogar o link,
 * validade, separadores, idioma, Topperboards partilhados e os acessos do cliente (com ícones). */
(function () {
  'use strict';
  var box = document.querySelector('[data-rv-share]'); if (!box) return;
  var DEAL = box.getAttribute('data-rv-share'), API = '/admin/rv_api.php';
  var CSRF = (document.querySelector('meta[name="csrf"]') || {}).content || '';
  var ICO = {
    eye: '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>', user: '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
    bubble: '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>', send: '<path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/>',
    check: '<path d="M20 6 9 17l-5-5"/>', download: '<path d="M12 4v12M6 10l6 6 6-6M4 20h16"/>', alert: '<path d="M12 9v4M12 17h.01"/><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/>',
    copy: '<rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v1"/>', ext: '<path d="M15 3h6v6M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>'
  };
  function ico(n) { return '<svg class="ico" viewBox="0 0 24 24" aria-hidden="true">' + (ICO[n] || ICO.eye) + '</svg>'; }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function toast(m, o) { var t = window.topper && window.topper.toast; return t ? t(m, o) : window.alert(m); }
  function get(a) { return fetch(API + '?a=' + a + '&deal=' + DEAL, { credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(function (r) { return r.json(); }); }
  function post(a, p) {
    var b = new URLSearchParams(p || {}); b.append('deal', DEAL);
    return fetch(API + '?a=' + a, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-Token': CSRF }, body: b })
      .then(function (r) { return r.json(); }).then(function (j) { if (!j.ok) throw new Error(j.error || 'Não foi possível concluir.'); return j; });
  }
  var TABS = [['proposal', 'Proposta'], ['boards', 'Topperboards'], ['review', 'Review']];
  var D = null;

  function render() {
    var s = D.share, can = D.can, h = '';
    if (!s || !s.valid) {
      h += '<p class="dc-empty">' + (s ? 'O link do portal deixou de funcionar (revogado ou fora da validade).' : 'Ainda sem link.') + '</p>'
        + '<p class="dc-note">Um link privado, sem conta, onde o cliente vê a proposta, os Topperboards partilhados e as peças enviadas, comenta e aprova. Nunca vê custos, notas, tarefas nem comentários internos.</p>'
        + (can ? '<div class="dc-acts"><button type="button" class="btn sm" data-sh="create">' + ico('ext') + ' ' + (s ? 'Criar um link novo' : 'Criar link do portal') + '</button></div>' : '');
    } else {
      h += '<div class="rv-link"><code>…/portal.php?t=••••••••</code>'
        + '<button type="button" class="btn sm" data-sh="copy">' + ico('copy') + ' Copiar link</button>'
        + '<button type="button" class="btn ghost sm" data-sh="open" data-tip="Abre o portal como o cliente o vê">' + ico('ext') + ' Abrir</button>'
        + '<span class="muted small">criado a ' + esc(s.created) + (s.last ? ' · último acesso ' + esc(s.last) : ' · ainda sem acessos') + '</span></div>';
      if (can) {
        h += '<div class="rv-share-set"><div class="row"><label class="field" style="margin:0"><span>Válido até</span><input type="date" value="' + esc(s.until) + '" data-sh-set="until"></label>'
          + '<label class="field" style="margin:0"><span>Idioma</span><select data-sh-set="lang"><option value="pt"' + (s.lang === 'pt' ? ' selected' : '') + '>Português</option><option value="en"' + (s.lang === 'en' ? ' selected' : '') + '>English</option></select></label></div>'
          + '<div class="row"><span class="muted small">Partilhado:</span>' + TABS.map(function (t) {
              return '<label class="check"><input type="checkbox" data-sh-tab="' + t[0] + '"' + (s.tabs.indexOf(t[0]) > -1 ? ' checked' : '') + '> ' + t[1] + '</label>'; }).join('') + '</div>'
          + (D.boards.length ? '<div class="row"><span class="muted small">Quadros (só leitura):</span>' + D.boards.map(function (b) {
              return '<label class="check"><input type="checkbox" data-sh-board="' + b.id + '"' + (s.boards.indexOf(b.id) > -1 ? ' checked' : '') + '> ' + esc(b.title) + '</label>'; }).join('') + '</div>' : '')
          + '</div><div class="dc-acts"><button type="button" class="btn ghost sm" data-sh="renew" data-tip="Cria um link novo: o atual deixa de funcionar">Renovar link</button>'
          + '<button type="button" class="btn ghost sm" data-sh="revoke" style="color:var(--danger)">Revogar</button></div>';
      }
    }
    h += '<h4 class="rv-sh-h">Acessos e ações do cliente</h4>' + (D.log.length ? '<ol class="tline">' + D.log.map(function (l) {
      return '<li><span class="hi c-' + esc(l.c) + '" role="img" aria-label="' + esc(l.tip) + '" data-tip="' + esc(l.tip) + '" tabindex="0">' + ico(l.icon) + '</span><div><div class="meta">' + esc(l.when)
        + (l.who ? ' · ' + esc(l.who) : '') + (l.email ? ' · ' + esc(l.email) : '') + (l.ip ? ' · IP ' + esc(l.ip) : '') + '</div><div class="what">' + esc(l.txt) + '</div></div></li>';
    }).join('') + '</ol>' : '<p class="muted small">Ainda sem acessos.</p>');
    box.innerHTML = h;
  }
  function load() { return get('share').then(function (j) { if (!j.ok) throw new Error(j.error); D = j; render(); }).catch(function (e) { box.innerHTML = '<p class="dc-empty">' + esc(e.message) + '</p>'; }); }
  function copy(url) {
    if (navigator.clipboard && navigator.clipboard.writeText) return navigator.clipboard.writeText(url).then(function () { toast('Link copiado. Envie-o ao cliente.'); }, function () { window.prompt('Copie o link:', url); });
    window.prompt('Copie o link:', url);
  }
  box.addEventListener('click', function (e) {
    var b = e.target.closest('[data-sh]'); if (!b) return;
    var a = b.getAttribute('data-sh');
    if (a === 'create' || a === 'renew') {
      if (a === 'renew' && !window.confirm('Criar um link novo? O link atual deixa de funcionar.')) return;
      post('share_create').then(function (j) { copy(j.url); return load(); }).catch(function (er) { toast(er.message, { err: true }); });
    } else if (a === 'copy' || a === 'open') {
      post('share_link').then(function (j) { if (a === 'copy') copy(j.url); else window.open(j.url, '_blank', 'noopener'); }).catch(function (er) { toast(er.message, { err: true }); });
    } else if (a === 'revoke') {
      if (!window.confirm('Revogar o link? O cliente deixa de conseguir abrir o portal.')) return;
      post('share_revoke').then(function (j) { toast(j.msg); return load(); }).catch(function (er) { toast(er.message, { err: true }); });
    }
  });
  box.addEventListener('change', function (e) {
    var t = e.target, p = {};
    if (t.hasAttribute('data-sh-set')) p[t.getAttribute('data-sh-set')] = t.value;
    else if (t.hasAttribute('data-sh-tab')) { p = new URLSearchParams(); box.querySelectorAll('[data-sh-tab]:checked').forEach(function (c) { p.append('tabs[]', c.getAttribute('data-sh-tab')); }); if (!p.toString()) p.append('tabs[]', ''); }
    else if (t.hasAttribute('data-sh-board')) { p = new URLSearchParams(); box.querySelectorAll('[data-sh-board]:checked').forEach(function (c) { p.append('boards[]', c.getAttribute('data-sh-board')); }); if (!p.toString()) p.append('boards[]', ''); }
    else return;
    post('share_save', p).then(function (j) { toast(j.msg); return load(); }).catch(function (er) { toast(er.message, { err: true }); load(); });
  });
  load();
})();
