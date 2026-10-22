/**
 * topper. admin — aba Tarefas (§18)
 *   · lista: grupos recolhíveis (lembra-se do que fechou), concluir / reabrir com «Anular»
 *   · quadro: «Pegar», «Pedir ajuda», arrastar um cartão para outra coluna
 *   · «+ Nova tarefa»: tipo só com ícones, atalhos de tempo, reunião Meet/Discord/presencial
 *   · agenda: abre na hora atual, linha de «agora», arrastar na grelha para criar
 */
(function () {
  'use strict';
  var API = '/admin/tasks_api.php';
  var csrfMeta = document.querySelector('meta[name="csrf"]');
  var csrf = csrfMeta ? csrfMeta.content : '';
  function $(s, el) { return (el || document).querySelector(s); }
  function $$(s, el) { return Array.prototype.slice.call((el || document).querySelectorAll(s)); }
  function T() { return window.topper; }
  function store(k, v) { try { if (v === undefined) return localStorage.getItem(k); localStorage.setItem(k, v); } catch (x) { return null; } }

  function api(data) {
    var fd = new FormData();
    Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
    fd.append('csrf', csrf);
    return fetch(API, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-CSRF-Token': csrf } })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Não foi possível concluir. Tente outra vez.' }; }); },
            function () { return { ok: false, error: 'Sem ligação. Tente outra vez.' }; })
      .then(function (j) { if (!j.ok) throw new Error(j.error || 'Não foi possível concluir.'); return j; });
  }
  // Um aviso que sobrevive a recarregar a página (com «Anular»).
  function later(j) { try { sessionStorage.setItem('task-toast', JSON.stringify({ m: j.msg, u: j.undo || '' })); } catch (x) { /* só recarrega */ } location.reload(); }
  try {
    var pend = JSON.parse(sessionStorage.getItem('task-toast') || 'null');
    if (pend) { sessionStorage.removeItem('task-toast'); document.addEventListener('DOMContentLoaded', function () { T().toast(pend.m, pend.u ? { undo: pend.u } : {}); }); }
  } catch (x) { /* sem aviso */ }

  // ── lista: grupos recolhíveis ─────────────────────────────
  $$('details.tgrp[data-grp]').forEach(function (d) {
    var k = 'tgrp:' + d.getAttribute('data-grp'), v = store(k);
    if (v === '1') d.open = true; else if (v === '0') d.open = false;
    d.addEventListener('toggle', function () { store(k, d.open ? '1' : '0'); });
  });

  // ── concluir / reabrir / pegar / pedir ajuda ─────────────
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('[data-done], [data-reopen], [data-take], [data-help]');
    if (!b) return;
    e.preventDefault();
    var act = b.hasAttribute('data-done') ? 'done' : b.hasAttribute('data-reopen') ? 'reopen' : b.hasAttribute('data-take') ? 'take' : 'help';
    var id = b.getAttribute('data-' + act), row = b.closest('.trow, .bcard');
    b.disabled = true;
    api({ do: act, id: id }).then(function (j) {
      if (row && row.classList.contains('trow') && (act === 'done' || act === 'reopen')) {
        row.classList.add('gone');
        setTimeout(function () { row.hidden = true; }, 240);
        T().toast(act === 'done' ? 'Concluída · ' + (row.querySelector('.tt') || {}).textContent : j.msg, j.undo ? { undo: j.undo } : {});
        return;
      }
      later(j);
    }).catch(function (er) { b.disabled = false; T().toast(er.message, { err: true }); });
  });

  // ── quadro: arrastar um cartão para outra coluna ──────────
  var board = $('[data-board]'), drag = null;
  if (board) {
    board.addEventListener('dragstart', function (e) {
      var c = e.target.closest('.bcard[draggable="true"]'); if (!c) return;
      drag = c; c.classList.add('dragging');
      e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', c.getAttribute('data-task'));
    });
    board.addEventListener('dragend', function () {
      if (drag) drag.classList.remove('dragging'); drag = null;
      $$('.bcol.over', board).forEach(function (c) { c.classList.remove('over'); });
    });
    board.addEventListener('dragover', function (e) {
      var col = e.target.closest('.bcol'); if (!col || !drag || drag.closest('.bcol') === col) return;
      e.preventDefault();
      $$('.bcol.over', board).forEach(function (c) { if (c !== col) c.classList.remove('over'); });
      col.classList.add('over');
    });
    board.addEventListener('drop', function (e) {
      var col = e.target.closest('.bcol'); if (!col || !drag || drag.closest('.bcol') === col) return;
      e.preventDefault(); col.classList.remove('over');
      var card = drag;
      $('.bcards', col).insertBefore(card, $('.bcards', col).firstChild);
      api({ do: 'move', id: card.getAttribute('data-task'), to: col.getAttribute('data-col') }).then(later)
        .catch(function (er) { T().toast(er.message, { err: true }); setTimeout(function () { location.reload(); }, 1200); });
    });
  }

  // ── «+ Nova tarefa» ───────────────────────────────────────
  var form = $('[data-task-form]');
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function ymd(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
  function sync() {
    if (!form) return;
    var k = (form.querySelector('input[name=kind]:checked') || {}).value || 'task';
    var plat = (form.querySelector('input[name=platform]:checked') || {}).value || '';
    form.setAttribute('data-kind', k);
    $$('[data-only="meeting"]', form).forEach(function (n) { n.hidden = k !== 'meeting'; });
    $$('[data-only="local"]', form).forEach(function (n) { n.hidden = !(k === 'meeting' && plat === ''); });
    $$('[data-not="block"]', form).forEach(function (n) { n.hidden = k === 'block' || k === 'personal'; });
    var who = $('#tk-who', form); if (who) who.closest('.field').hidden = k === 'block' || k === 'personal';
    var busy = form.querySelector('input[name=busy][value="1"]');
    if (busy && (k === 'block' || k === 'personal')) busy.checked = true;
    var title = $('#tk-title', form);
    if (title && k === 'block' && title.value === '') title.value = 'Bloqueio';
  }
  // «Nova tarefa» de um tipo (painel do negócio): o formulário abre com esse tipo escolhido.
  document.addEventListener('click', function (e) {
    var k = e.target.closest && e.target.closest('[data-new][data-kind]');
    if (!k || !form) return;
    var r = form.querySelector('input[name=kind][value="' + k.getAttribute('data-kind') + '"]');
    if (r) { r.checked = true; sync(); }
  }, true);
  if (form) {
    form.addEventListener('change', function (e) { if (e.target.name === 'kind' || e.target.name === 'platform') sync(); });
    sync();
    form.addEventListener('click', function (e) {
      var q = e.target.closest('[data-qt]'); if (!q) return;
      var now = new Date(), d = new Date(now), t = '';
      var k = q.getAttribute('data-qt');
      if (k === '1h' || k === '3h') { d = new Date(now.getTime() + (k === '1h' ? 1 : 3) * 3600000); d.setMinutes(Math.ceil(d.getMinutes() / 15) * 15, 0, 0); t = pad(d.getHours()) + ':' + pad(d.getMinutes()); }
      else if (k === 'tomorrow') { d.setDate(d.getDate() + 1); t = '09:00'; }
      else if (k === 'nextweek') { d.setDate(d.getDate() + ((8 - d.getDay()) % 7 || 7)); t = '09:00'; }
      $('#tk-day', form).value = k === 'none' ? '' : ymd(d);
      $('#tk-time', form).value = k === 'none' ? '' : t;
      $$('[data-qt]', form).forEach(function (x) { x.classList.toggle('on', x === q); });
    });
    var del = $('[data-confirm-del]', form);
    if (del) del.addEventListener('click', function (e) {
      if (del.__ok) return;
      e.preventDefault(); del.__ok = true; del.textContent = 'Sim, eliminar'; del.classList.add('danger');
    });
  }

  // ── agenda ───────────────────────────────────────────────
  var sc = $('[data-agscroll]');
  if (sc) {
    var minPx = function () { var g = $('.agday', sc); return g ? g.getBoundingClientRect().height / 1440 : 0.8; };
    var now = $('[data-now]', sc), h = new Date().getHours();
    sc.scrollTop = Math.max(0, ((now ? h : 8) - 1) * 60 * minPx());
    if (now) setInterval(function () { var d = new Date(); now.style.top = 'calc(' + (d.getHours() * 60 + d.getMinutes()) + ' * var(--min))'; }, 60000);
    // Arrastar na grelha (só na minha agenda) → Tarefa · Tarefa pessoal · Bloqueio.
    var pick = $('[data-agpick]'), sel = null;
    var at = function (col, y) { var r = col.getBoundingClientRect(); return Math.max(0, Math.min(1440, Math.round((y - r.top) / minPx() / 15) * 15)); };
    sc.addEventListener('pointerdown', function (e) {
      var col = e.target.closest('.agday.can-make'); if (!col || e.target.closest('.agev') || e.button !== 0) return;
      e.preventDefault();
      if (sel && sel.ghost) sel.ghost.remove();
      var a = at(col, e.clientY);
      var g = document.createElement('div'); g.className = 'agghost'; col.appendChild(g);
      sel = { col: col, a: a, b: a + 30, ghost: g };
      draw();
      col.setPointerCapture(e.pointerId);
    });
    sc.addEventListener('pointermove', function (e) {
      if (!sel || sel.done) return;
      var m = at(sel.col, e.clientY);
      sel.b = Math.max(sel.a + 15, m); draw();
    });
    sc.addEventListener('pointerup', function (e) {
      if (!sel || sel.done) return;
      sel.done = true;
      pick.hidden = false;
      var r = sel.ghost.getBoundingClientRect();
      pick.style.left = Math.min(window.innerWidth - pick.offsetWidth - 12, r.right + 6) + 'px';
      pick.style.top = Math.max(12, Math.min(window.innerHeight - pick.offsetHeight - 12, r.top)) + 'px';
      $('button', pick).focus();
    });
    function draw() {
      sel.ghost.style.top = 'calc(' + sel.a + ' * var(--min))';
      sel.ghost.style.height = 'calc(' + (sel.b - sel.a) + ' * var(--min))';
      sel.ghost.textContent = fmt(sel.a) + '–' + fmt(sel.b);
    }
    function fmt(m) { return pad(Math.floor(m / 60) % 24) + ':' + pad(m % 60); }
    function close() { pick.hidden = true; if (sel && sel.ghost) sel.ghost.remove(); sel = null; }
    if (pick) {
      pick.addEventListener('click', function (e) {
        var b = e.target.closest('[data-make]'); if (!b || !sel || !form) return;
        var k = b.getAttribute('data-make'), dur = sel.b - sel.a, ghost = sel.ghost;
        var mdl = form.closest('.mdl');
        var r = form.querySelector('input[name=kind][value="' + (k === 'task' ? 'task' : k) + '"]'); if (r) r.checked = true;
        $('#tk-day', form).value = sel.col.getAttribute('data-day');
        $('#tk-time', form).value = fmt(sel.a);
        var ds = $('#tk-dur', form), best = '0', diff = 1e9;
        $$('option', ds).forEach(function (o) { var v = +o.value || 60, dd = Math.abs(v - dur); if (dd < diff) { diff = dd; best = o.value; } });
        ds.value = best;
        if (k !== 'block' && $('#tk-title', form).value === 'Bloqueio') $('#tk-title', form).value = '';
        sync();
        pick.hidden = true;
        T().openMdl(mdl, ghost);
        setTimeout(function () { ghost.remove(); }, 400);
        sel = null;
      });
      document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !pick.hidden) close(); });
      document.addEventListener('pointerdown', function (e) { if (!pick.hidden && !e.target.closest('[data-agpick]') && !e.target.closest('.agday')) close(); });
    }
  }
})();
