/**
 * topper. admin — Definições (§20)
 *   · grava sozinho: ao mudar um campo (os de texto ao sair dele), com aviso «… · Anular»
 *   · aviso de impacto antes de mexer em dados existentes: Confirmar / Cancelar
 *   · «Repor (valor de origem)» em cada campo e «Repor» em cada linha do registo
 *   · tabelas (serviços, pilares): cada célula grava sozinha; Enter na linha nova acrescenta
 *   · pesquisa: leva direto ao campo, noutro separador se for preciso
 */
(function () {
  'use strict';
  var API = '/admin/ui_api.php';
  var csrfMeta = document.querySelector('meta[name="csrf"]');
  var csrf = csrfMeta ? csrfMeta.content : '';
  function $(s, el) { return (el || document).querySelector(s); }
  function $$(s, el) { return Array.prototype.slice.call((el || document).querySelectorAll(s)); }
  function T() { return window.topper; }

  // O pedido devolve sempre o JSON (o aviso de impacto chega com 409).
  function post(action, data) {
    var fd = new FormData();
    Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
    fd.append('a', action); fd.append('csrf', csrf);
    return fetch(API + '?a=' + action, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-CSRF-Token': csrf } })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: r.status === 401 ? 'A sessão terminou. Recarregue a página.' : 'Não foi possível gravar.' }; }); },
            function () { return { ok: false, error: 'Sem ligação. Tente outra vez.' }; });
  }

  function valueOf(el) { return el.type === 'checkbox' ? (el.checked ? (el.value || '1') : (el.value === 'employer' ? '' : '0')) : el.value; }
  function rowOf(el) { return el.closest('.set-row'); }
  function flash(el, cls) { el.classList.remove('saved', 'bad'); void el.offsetWidth; el.classList.add(cls); }

  function applied(row, j) {
    var input = $('[data-set]', row);
    if (input) {
      var v = j.value;
      input.setAttribute('data-was', v);
      if (input.tagName !== 'SELECT' && input.type !== 'time' && document.activeElement !== input) input.value = input.inputMode === 'decimal' ? String(v).replace('.', ',') : v;
      flash(input, 'saved');
    }
    var r = $('[data-reset]', row);
    if (r) r.hidden = String(j.value) === r.getAttribute('data-def');
  }

  function save(input, extra) {
    var key = input.getAttribute('data-set'), row = rowOf(input);
    var data = Object.assign({ key: key, value: input.value }, extra || {});
    var action = extra && extra.__action ? extra.__action : 'setting_save';
    if (data.__action) delete data.__action;
    var warn = $('.set-warn', row);
    return post(action, data).then(function (j) {
      if (j.impact) {
        // Antes de gravar: o que muda em dados que já existem.
        $('p', warn).textContent = j.impact;
        warn.hidden = false; warn.classList.add('in');
        warn.__retry = function () { return save(input, Object.assign({}, extra || {}, { confirm: 1, __action: action })); };
        warn.__cancel = function () { input.value = input.getAttribute('data-was'); };
        $('[data-impact-ok]', warn).focus();
        return;
      }
      warn.hidden = true;
      if (!j.ok) { flash(input, 'bad'); T().toast(j.error || 'Não foi possível gravar.', { err: true }); input.value = input.getAttribute('data-was'); return; }
      if (j.msg === 'Sem alterações.') return;
      applied(row, j);
      T().toast(j.msg, j.undo ? { undo: j.undo } : {});
    });
  }

  var main = $('[data-set-main]');
  if (main) {
    // Campos simples: selects, horas e números gravam ao mudar; texto ao sair do campo.
    main.addEventListener('change', function (e) {
      var el = e.target;
      if (el.matches('[data-set]')) { if (el.value !== el.getAttribute('data-was')) save(el); return; }
      var cell = el.closest('[data-cell]') && el;
      if (cell && cell.matches('[data-cell]')) saveCell(cell);
    });
    main.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && e.target.matches('input[data-set]')) { e.preventDefault(); e.target.blur(); }
      if (e.key === 'Enter' && e.target.matches('tr[data-id="0"] input[data-cell]')) { e.preventDefault(); saveCell(e.target); }
      if (e.key === 'Escape' && e.target.matches('[data-set]')) { e.target.value = e.target.getAttribute('data-was'); e.target.blur(); }
    });
    main.addEventListener('click', function (e) {
      var r = e.target.closest('[data-reset]');
      if (r) {
        var input = $('[data-set]', rowOf(r));
        save(input, { __action: 'setting_reset' });
        return;
      }
      var rs = e.target.closest('[data-restore]');
      if (rs) {
        rs.disabled = true;
        post('setting_restore', { log: rs.getAttribute('data-restore') }).then(function (j) {
          if (j.impact) {
            if (window.confirm(j.impact + '\n\nRepor mesmo assim?')) {
              post('setting_restore', { log: rs.getAttribute('data-restore'), confirm: 1 }).then(done);
            } else rs.disabled = false;
            return;
          }
          done(j);
        });
        function done(j) {
          if (!j.ok) { rs.disabled = false; T().toast(j.error, { err: true }); return; }
          try { sessionStorage.setItem('set-toast', JSON.stringify({ m: j.msg, u: j.undo || '' })); } catch (x) { /* só recarrega */ }
          location.reload();
        }
        return;
      }
      var ok = e.target.closest('[data-impact-ok]');
      if (ok) { var w = ok.closest('.set-warn'); if (w.__retry) w.__retry(); return; }
      var no = e.target.closest('[data-impact-no]');
      if (no) { var w2 = no.closest('.set-warn'); w2.hidden = true; if (w2.__cancel) w2.__cancel(); }
    });

    // Tabelas: cada célula grava sozinha.
    var saveCell = function (el) {
      var tr = el.closest('tr[data-id]'), table = el.closest('[data-table]');
      if (!tr || !table) return;
      var id = tr.getAttribute('data-id');
      if (id === '0' && el.value.trim() === '') return;
      post('setting_row', { table: table.getAttribute('data-table'), id: id, field: el.getAttribute('data-cell'), value: valueOf(el) }).then(function (j) {
        if (!j.ok) { flash(el, 'bad'); T().toast(j.error, { err: true }); return; }
        if (j.reload) { try { sessionStorage.setItem('set-toast', JSON.stringify({ m: j.msg, u: '' })); } catch (x) { /* só recarrega */ } location.reload(); return; }
        if (j.msg === 'Sem alterações.') return;
        flash(el, 'saved');
        T().toast(j.msg, j.undo ? { undo: j.undo } : {});
      });
    };
  }

  // Aviso que sobrevive a recarregar a página.
  try {
    var pend = JSON.parse(sessionStorage.getItem('set-toast') || 'null');
    if (pend) { sessionStorage.removeItem('set-toast'); document.addEventListener('DOMContentLoaded', function () { T().toast(pend.m, pend.u ? { undo: pend.u } : {}); }); }
  } catch (x) { /* sem aviso */ }

  // Ir a um campo (#set-chave): rola, destaca e põe o cursor lá.
  function focusRow(key) {
    var row = document.getElementById('set-' + key);
    if (!row) return false;
    row.scrollIntoView({ block: 'center', behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
    row.classList.remove('hit'); void row.offsetWidth; row.classList.add('hit');
    var f = $('[data-set], input, select, textarea, a.btn', row);
    if (f) setTimeout(function () { f.focus({ preventScroll: true }); }, 250);
    return true;
  }
  if (location.hash.indexOf('#set-') === 0) document.addEventListener('DOMContentLoaded', function () { focusRow(location.hash.slice(5)); });

  // Pesquisa: nome, grupo, separador e a frase de ajuda.
  var box = $('[data-set-search]'), res = $('[data-set-res]'), idx = [];
  try { idx = JSON.parse(($('#set-index') || {}).textContent || '[]'); } catch (x) { idx = []; }
  function norm(s) { return String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); }
  var cur = -1, hits = [];
  function render() {
    var q = norm(box.value.trim());
    res.innerHTML = ''; cur = -1;
    if (q.length < 2) { res.hidden = true; return; }
    hits = idx.filter(function (d) { return norm(d.l + ' ' + d.g + ' ' + d.tab + ' ' + d.h).indexOf(q) >= 0; })
              .sort(function (a, b) { return (norm(a.l).indexOf(q) >= 0 ? 0 : 1) - (norm(b.l).indexOf(q) >= 0 ? 0 : 1); }).slice(0, 8);
    if (!hits.length) { res.innerHTML = '<p class="muted small">Nada encontrado.</p>'; res.hidden = false; return; }
    hits.forEach(function (d, i) {
      var a = document.createElement('a');
      a.href = '/admin/definicoes.php?t=' + d.t + '#set-' + d.k; a.setAttribute('role', 'option'); a.setAttribute('data-i', i);
      a.innerHTML = '<b></b><small></small>';
      a.querySelector('b').textContent = d.l; a.querySelector('small').textContent = d.tab + ' · ' + d.g;
      res.appendChild(a);
    });
    res.hidden = false;
  }
  function go(d) {
    var here = new URLSearchParams(location.search).get('t') || 'geral';
    if (here === d.t && focusRow(d.k)) { res.hidden = true; box.value = ''; history.replaceState(null, '', '#set-' + d.k); return; }
    location.href = '/admin/definicoes.php?t=' + d.t + '#set-' + d.k;
  }
  if (box) {
    box.addEventListener('input', render);
    box.addEventListener('keydown', function (e) {
      var opts = $$('a', res);
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault(); if (!opts.length) return;
        cur = (cur + (e.key === 'ArrowDown' ? 1 : -1) + opts.length) % opts.length;
        opts.forEach(function (o, i) { o.classList.toggle('on', i === cur); });
      } else if (e.key === 'Enter') {
        e.preventDefault(); if (hits.length) go(hits[Math.max(0, cur)]);
      } else if (e.key === 'Escape') { box.value = ''; res.hidden = true; }
    });
    res.addEventListener('click', function (e) {
      var a = e.target.closest('a[data-i]'); if (!a) return;
      e.preventDefault(); go(hits[+a.getAttribute('data-i')]);
    });
    document.addEventListener('click', function (e) { if (!e.target.closest('.set-search')) res.hidden = true; });
  }
})();
