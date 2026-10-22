/**
 * topper. admin — a página Equipa (§17): procurar pessoas e competências, filtrar por
 * departamento, aprovar ausências (com «Anular»), «Encontrar hora» e arrastar no
 * organograma (admins). O chat, a disponibilidade e as reuniões estão no chat.js.
 */
(function () {
  'use strict';
  function $(s, el) { return (el || document).querySelector(s); }
  function $$(s, el) { return Array.prototype.slice.call((el || document).querySelectorAll(s)); }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function T() { return window.topper; }
  function api() { return window.topperTeam.api.apply(null, arguments); }

  // ── procurar e filtrar os cartões ──────────────────────────
  var q = $('[data-team-q]'), cards = $('[data-pcards]'), dept = '';
  function filter() {
    if (!cards) return;
    var t = (q ? q.value : '').trim().toLowerCase(), shown = 0;
    $$('.pcard', cards).forEach(function (c) {
      var ok = (!t || c.getAttribute('data-search').indexOf(t) >= 0) && (!dept || c.getAttribute('data-dept') === dept);
      c.hidden = !ok; if (ok) shown++;
    });
    var none = $('[data-team-none]'); if (none) none.hidden = shown > 0;
  }
  if (q) {
    q.addEventListener('input', filter);
    // «/» procura (fora dos campos de escrita).
    document.addEventListener('keydown', function (e) {
      if (e.key === '/' && !/^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement.tagName) && !$('.mdl.open')) { e.preventDefault(); q.focus(); }
    });
  }
  $$('[data-dept-f]').forEach(function (b) {
    b.addEventListener('click', function () {
      dept = b.getAttribute('data-dept-f');
      $$('[data-dept-f]').forEach(function (x) { x.classList.toggle('on', x === b); x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
      filter();
    });
  });

  // ── aprovar / recusar ausências (admins) ───────────────────
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('[data-abs-decide]');
    if (!b) return;
    var row = b.closest('[data-abs]');
    $$('button', row).forEach(function (x) { x.disabled = true; });
    api('absence_decide', { id: row.getAttribute('data-abs'), decision: b.getAttribute('data-abs-decide') }).then(function (j) {
      row.classList.add('gone');
      setTimeout(function () { row.hidden = true; }, 220);
      T().toast(j.msg, { undo: j.undo });
      if (b.getAttribute('data-abs-decide') === 'approved') setTimeout(function () { location.reload(); }, 8600);
    }).catch(function (er) { $$('button', row).forEach(function (x) { x.disabled = false; }); T().toast(er.message, { err: true }); });
  });

  // ── «Encontrar hora» ───────────────────────────────────────
  var ff = $('[data-find-form]');
  if (ff) {
    var res = $('[data-find-res]', ff);
    ff.addEventListener('submit', function (e) {
      e.preventDefault();
      var ids = $$('input[name=ids]:checked', ff).map(function (i) { return i.value; });
      if (!ids.length) { T().toast('Escolha pelo menos uma pessoa.', { err: true }); return; }
      res.innerHTML = '<div class="sp-loading">A procurar…</div>';
      api('find_time', { ids: ids.join(','), d: ff.elements.d.value, w: ff.elements.w.value }, 'GET').then(function (j) {
        if (!j.slots.length) { res.innerHTML = '<p class="muted">Sem espaços livres comuns nesta semana. Experimente outra semana ou menos tempo.</p>'; return; }
        res.innerHTML = '<p class="muted small">Livres para ' + esc(j.names) + ' (clique para pedir a reunião):</p><div class="slots">'
          + j.slots.map(function (s) { return '<button type="button" class="chip-btn" data-find-slot="' + esc(s.date + ' ' + s.time) + '">' + esc(s.label) + '</button>'; }).join('') + '</div>';
        res.__ids = ids; res.__names = j.names; res.__d = ff.elements.d.value;
      }).catch(function (er) { res.innerHTML = '<p class="muted">' + esc(er.message) + '</p>'; });
    });
    res.addEventListener('click', function (e) {
      var s = e.target.closest('[data-find-slot]'); if (!s) return;
      var p = s.getAttribute('data-find-slot').split(' '), ids = res.__ids, names = res.__names, d = res.__d;
      T().closeMdl(ff.closest('.mdl'));
      setTimeout(function () {
        window.topperTeam.openMeet(ids, names, p[0], p[1], null);
        var dur = $('#mt-dur'); if (dur) dur.value = d;
      }, 320);
    });
  }

  // ── organograma: arrastar para mudar «responde a» (admins) ─
  var org = $('[data-org-edit]'), root = $('[data-org-root]');
  if (org) {
    var drag = null;
    var move = function (id, to) {
      api('org_move', { id: id, reports_to: to || '' }).then(function (j) {
        T().toast(j.msg, { undo: j.undo });
        setTimeout(function () { location.reload(); }, 700);
      }).catch(function (er) { T().toast(er.message, { err: true }); });
    };
    document.addEventListener('dragstart', function (e) {
      var n = e.target.closest && e.target.closest('.onode[draggable="true"]'); if (!n) return;
      drag = n; n.classList.add('dragging'); n.__dragged = true;
      e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', n.getAttribute('data-org'));
      if (root) root.classList.add('on');
    });
    document.addEventListener('dragend', function () {
      if (drag) { drag.classList.remove('dragging'); var d = drag; setTimeout(function () { d.__dragged = false; }, 50); }
      drag = null;
      $$('.onode.over, .org-top.over').forEach(function (n) { n.classList.remove('over'); });
      if (root) root.classList.remove('on');
    });
    document.addEventListener('dragover', function (e) {
      if (!drag) return;
      var t = e.target.closest && (e.target.closest('.onode[data-org]') || e.target.closest('[data-org-root]'));
      if (!t || t === drag) return;
      e.preventDefault();
      $$('.onode.over, .org-top.over').forEach(function (n) { if (n !== t) n.classList.remove('over'); });
      t.classList.add('over');
    });
    document.addEventListener('drop', function (e) {
      if (!drag) return;
      var t = e.target.closest && (e.target.closest('.onode[data-org]') || e.target.closest('[data-org-root]'));
      if (!t || t === drag) return;
      e.preventDefault();
      var id = drag.getAttribute('data-org');
      move(id, t.hasAttribute('data-org-root') ? '' : t.getAttribute('data-org'));
    });
  }

  // ── desativar: confirmar (não se anula, porque reatribui trabalho) ─
  var off = $('[data-confirm-off]');
  if (off) off.addEventListener('submit', function (e) {
    if (!off.__ok) {
      e.preventDefault();
      var b = $('button[type=submit]', off);
      b.textContent = 'Sim, desativar e reatribuir';
      off.__ok = true;
      b.focus();
    }
  });

})();
