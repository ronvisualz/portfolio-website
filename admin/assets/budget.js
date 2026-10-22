/*
 * topper. admin — folha de orçamento (docs/ESPECIFICACAO-PLATAFORMA.md §14)
 *
 *   · cada campo grava-se sozinho (duração, datas, fases, ocupação, margem,
 *     custos fixos, textos da proposta) e o servidor devolve o cálculo já
 *     filtrado pelas permissões — sem Financeiro não chegam preços, margens
 *     nem lucro;
 *   · linhas de custo: quantidade e unidade no próprio campo, «+ Adicionar
 *     linha» / alterar (o formulário sai do botão, §13.8), tirar com «Anular»;
 *   · versões (nova, aceitar), modelos (guardar, aplicar) e a proposta em PDF.
 *
 * Tudo por /admin/ui_api.php, que confirma a permissão em cada pedido.
 */
(function boot() {
  'use strict';
  if (!window.topper) { document.addEventListener('DOMContentLoaded', boot, { once: true }); return; }
  var bs = document.querySelector('.bs[data-sheet]');
  if (!bs || window.topperBudget) return;
  window.topperBudget = true;

  var T = window.topper;
  var VERSION = bs.getAttribute('data-version');
  // Opção A (Pedido) ou B (Proposta de valor), §19.3: cada pedido diz qual a folha está a mostrar.
  var OPT = bs.getAttribute('data-opt') || 'A';
  function api(a, d, m) {
    if (typeof FormData !== 'undefined' && d instanceof FormData) { if (!d.has('opt')) d.append('opt', OPT); }
    else if (d && m !== 'GET') d.opt = OPT;
    return T.api(a, d, m);
  }
  var EDIT = bs.getAttribute('data-editable') === '1';

  function $(s, el) { return (el || document).querySelector(s); }
  function $$(s, el) { return Array.prototype.slice.call((el || document).querySelectorAll(s)); }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function typing(el) { return el && (el.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(el.tagName)); }
  function fail(e) { T.toast(e.message, { err: true }); }
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function saved() {
    var s = $('[data-saved]'), d = new Date();
    if (s) s.textContent = 'Gravado às ' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + '.';
  }

  // ── o cálculo que volta do servidor ──────────────────────
  function apply(j) {
    var c = j.calc;
    if (c) {
      Object.keys(c.k).forEach(function (k) {
        $$('[data-k="' + k + '"]', bs).forEach(function (el) {
          if (el.textContent === c.k[k]) return;
          el.textContent = c.k[k];
          el.classList.remove('flash-v'); void el.offsetWidth; el.classList.add('flash-v');
        });
      });
      var st = $('[data-k-status]', bs);
      if (st && c.status_html) st.innerHTML = c.status_html;
      var sug = $('[data-k="suggestion"]', bs);
      if (sug) { sug.textContent = c.suggestion || ''; sug.hidden = !c.suggestion; }
      var occ = $('[data-f="occupancy"]', bs);
      if (occ && document.activeElement !== occ && !c.occ_manual) occ.value = String(c.occupancy).replace('.', ',');
      var auto = $('[data-occ-auto]', bs);
      if (auto) auto.hidden = !c.occ_manual;
    }
    if (j.costs) Object.keys(j.costs).forEach(function (id) {
      var cell = $('[data-cost="' + id + '"]', bs); if (cell) cell.textContent = j.costs[id];
    });
    if (j.rows != null) {
      var tb = $('[data-lines]', bs); if (tb) tb.innerHTML = j.rows;
      if (j.lines) lines = j.lines;
    }
    if (j.phases != null) {
      var tmp = document.createElement('tbody'); tmp.innerHTML = j.phases;
      var now = $$('[data-phases] tr', bs);
      $$('tr', tmp).forEach(function (r, i) {
        var w = $('.ph-w', r), cur = now[i] && $('.ph-w', now[i]);
        if (w && cur) cur.textContent = w.textContent;
      });
    }
    saved();
  }

  var lines = {};
  try { lines = JSON.parse(($('[data-lines-json]') || {}).textContent || '{}'); } catch (e) { lines = {}; }

  if (!EDIT) { bindAlways(); return; }

  // ── campos que se gravam sozinhos ─────────────────────────
  var timers = {}, last = {};
  function send(field, value) {
    if (last[field] === value) return;
    last[field] = value;
    api('budget_set', { version: VERSION, field: field, value: value }).then(apply).catch(function (e) { delete last[field]; fail(e); });
  }
  $$('[data-f]', bs).forEach(function (el) {
    var f = el.getAttribute('data-f');
    if (el.type !== 'radio') last[f] = el.value;
  });
  bs.addEventListener('input', function (e) {
    var el = e.target, f = el.getAttribute && el.getAttribute('data-f');
    if (!f || el.type === 'radio' || el.type === 'date') return;
    clearTimeout(timers[f]);
    timers[f] = setTimeout(function () { send(f, el.value.trim()); }, /^(description|conditions|scope_b|payment_terms|revisions_text|rights_text|term_text)$/.test(f) ? 900 : 550);
  });
  bs.addEventListener('change', function (e) {
    var el = e.target, f = el.getAttribute && el.getAttribute('data-f');
    if (!f) return;
    clearTimeout(timers[f]);
    if (f === 'dur_mode') {
      $$('.bt-mode', bs).forEach(function (m) { m.hidden = m.getAttribute('data-mode') !== el.value; });
      // As datas do início aparecem em dois modos: o mesmo valor nos dois campos.
      last[f] = null;
    }
    if (f === 'start_date') $$('[data-f="start_date"]', bs).forEach(function (o) { if (o !== el) o.value = el.value; });
    send(f, el.value.trim());
  });

  // % de ocupação: voltar à sugestão automática.
  bs.addEventListener('click', function (e) {
    var fill = e.target.closest('[data-bfill]');
    if (fill) {
      var f = fill.getAttribute('data-bfill'), fe = $('[data-f="' + f + '"]', bs);
      if (fe) { fe.value = fill.getAttribute('data-value'); send(f, fe.value); }
      fill.closest('p').hidden = true;
      return;
    }
    var b = e.target.closest('[data-occ-auto]');
    if (b) { last.occupancy = null; send('occupancy', ''); var i = $('[data-f="occupancy"]', bs); if (i) i.blur(); return; }
    var a = e.target.closest('[data-fixed-avg]');
    if (a) {
      api('budget_fixed_avg', { version: VERSION }).then(function (j) {
        var i = $('[data-f="fixed_monthly"]', bs); if (i) { i.value = j.fixed; last.fixed_monthly = j.fixed; }
        apply(j); T.toast(j.msg);
      }).catch(fail);
    }
  });

  // ── fases ─────────────────────────────────────────────────
  var phases = $('[data-phases]', bs);
  function phasesValue() {
    return JSON.stringify($$('tr', phases).map(function (r) {
      return { name: ($('.ph-n', r) || {}).value || '', days: (($('.ph-d', r) || {}).value || '').replace(',', '.') };
    }));
  }
  if (phases) {
    phases.addEventListener('change', function (e) { if (e.target.closest('.ph-n, .ph-d')) send('phases', phasesValue()); });
    phases.addEventListener('click', function (e) {
      var d = e.target.closest('[data-ph-del]'); if (!d) return;
      d.closest('tr').remove(); send('phases', phasesValue());
    });
    var addPh = $('[data-ph-add]', bs);
    if (addPh) addPh.addEventListener('click', function () {
      var tr = document.createElement('tr');
      tr.innerHTML = '<td><input type="text" class="ph-n" maxlength="60" aria-label="Fase" placeholder="Nova fase"></td>'
        + '<td class="num"><input type="text" class="ph-d" inputmode="decimal" maxlength="6" aria-label="Dias úteis"></td><td class="muted mono small ph-w"></td>'
        + '<td class="act"><button type="button" class="ibtn" data-ph-del aria-label="Tirar a fase" data-tip><svg class="ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg></button></td>';
      phases.appendChild(tr); $('.ph-n', tr).focus();
    });
  }

  // ── linhas: quantidade e unidade no próprio campo ─────────
  var tbody = $('[data-lines]', bs);
  if (tbody) {
    tbody.addEventListener('change', function (e) {
      var q = e.target.closest('.bl-q'), u = e.target.closest('.bl-u');
      if (!q && !u) return;
      var el = q || u, data = { line: el.getAttribute('data-line') };
      if (q) data.qty = q.value.trim(); else data.qty_unit = u.value;
      el.disabled = true;
      api('budget_line_qty', data).then(apply).catch(function (er) { fail(er); if (q && lines[data.line]) q.value = lines[data.line].qty; })
        .then(function () { el.disabled = false; });
    });
    tbody.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && e.target.closest('.bl-q')) { e.preventDefault(); e.target.blur(); }
    });
    tbody.addEventListener('click', function (e) {
      var del = e.target.closest('[data-bl-del]');
      if (del) {
        del.disabled = true;
        api('budget_line_remove', { line: del.getAttribute('data-bl-del') }).then(function (j) {
          apply(j); T.toast(j.msg, { undo: j.undo });
        }).catch(function (er) { del.disabled = false; fail(er); });
        return;
      }
      var ed = e.target.closest('[data-bl-edit]');
      if (ed) { e.preventDefault(); lineForm(ed.getAttribute('data-bl-edit')); T.openMdl(mdl, ed); }
    });
  }

  // ── «+ Adicionar linha» / alterar ─────────────────────────
  var mdl = $('#mdl-bline'), form = mdl && $('form', mdl);
  var supBox = form && $('[data-sup-list]', form), supSeq = 0, supTimed = true;
  function kindOf() { var r = $('input[name=kind]:checked', form); return r ? r.value : 'supplier'; }
  function syncQty() {
    var k = kindOf(), timed = k === 'team' || (k === 'supplier' && supTimed);
    $('[data-qu-time]', form).hidden = !timed || k === 'other';
    $('[data-qu-fixed]', form).hidden = timed || k === 'other';
    var other = k === 'other', unit = $('[name=price_unit]', form).value;
    $('[data-qty-row]', form).hidden = other && unit === 'fixed';
  }
  function syncKind() {
    var k = kindOf();
    $$('.bl-sec', form).forEach(function (s) { s.hidden = s.getAttribute('data-sec') !== k; });
    syncQty();
  }
  function loadSuppliers(chosen) {
    var sid = $('[name=service_id]', form).value, my = ++supSeq;
    if (!sid) { supBox.innerHTML = '<p class="muted small" style="margin:0">Escolha o serviço para ver os fornecedores ativos que o prestam.</p>'; return Promise.resolve(); }
    supBox.innerHTML = '<p class="muted small" style="margin:0">A carregar…</p>';
    return api('budget_suppliers', { service: sid }, 'GET').then(function (j) {
      if (my !== supSeq) return;
      if (!j.items.length) { supBox.innerHTML = '<p class="muted small" style="margin:0">Nenhum fornecedor ativo presta este serviço. Em Contactos, junte o serviço e o preço a um fornecedor ativo.</p>'; return; }
      supBox.innerHTML = j.items.map(function (o) {
        return '<label class="sup' + (o.missing ? ' off' : '') + '"><input type="radio" name="contact_id" value="' + o.id + '" data-unit="' + esc(o.unit_label) + '" data-timed="' + (o.timed ? 1 : 0) + '"'
          + (o.missing ? ' disabled' : '') + (String(o.id) === String(chosen) ? ' checked' : '') + '>'
          + '<span><b>' + esc(o.name) + '</b><small>' + (o.missing ? 'preço por rever em Contactos' : (o.price ? esc(o.price) : 'por ' + esc(o.unit_label)))
          + (o.rating ? ' · ★' + esc(o.rating) : '') + '</small></span></label>';
      }).join('');
      var c = $('input[name=contact_id]:checked', supBox) || $('input[name=contact_id]:not([disabled])', supBox);
      if (c) { c.checked = true; pickSup(c); }
    }).catch(fail);
  }
  function pickSup(r) {
    supTimed = r.getAttribute('data-timed') === '1';
    $('[data-fixed-unit]', form).textContent = r.getAttribute('data-unit');
    syncQty();
  }
  function lineForm(id) {
    form.reset();
    var l = id ? lines[id] : null;
    form.elements.line.value = id || '';
    $('[data-bl-title]', form).textContent = l ? 'Alterar linha' : 'Adicionar linha';
    $('[data-bl-submit]', form).textContent = l ? 'Gravar linha' : 'Adicionar linha';
    supTimed = true;
    if (!l) { syncKind(); loadSuppliers(); return; }
    $('input[name=kind][value="' + l.kind + '"]', form).checked = true;
    if (l.kind === 'supplier') { form.elements.service_id.value = l.service_id || ''; loadSuppliers(l.contact_id); }
    if (l.kind === 'team') { form.elements.user_id.value = l.user_id || ''; form.elements.team_label.value = l.label; }
    if (l.kind === 'other') { form.elements.other_label.value = l.label; form.elements.price_unit.value = l.price_unit; form.elements.unit_price.value = l.unit_price; }
    form.elements.qty.value = l.qty;
    if (l.qty_unit === 'hour' || l.qty_unit === 'day') form.elements.qty_unit.value = l.qty_unit;
    form.elements.note.value = l.note || '';
    syncKind();
  }
  if (form) {
    form.addEventListener('change', function (e) {
      if (e.target.name === 'kind') syncKind();
      if (e.target.name === 'service_id') loadSuppliers();
      if (e.target.name === 'contact_id') pickSup(e.target);
      if (e.target.name === 'price_unit') syncQty();
      if (e.target.name === 'user_id') {
        var o = e.target.selectedOptions[0], t = form.elements.team_label;
        if (o && !t.value) t.placeholder = o.getAttribute('data-title') || 'Direção criativa, produção…';
      }
    });
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var btn = $('button[type=submit]', form); btn.disabled = true;
      api('budget_line_save', new FormData(form)).then(function (j) {
        apply(j); T.closeMdl(mdl); T.toast(j.msg);
        var row = $('tr[data-line="' + j.line + '"]', bs);
        if (row) { row.classList.add('bl-new'); setTimeout(function () { row.classList.remove('bl-new'); }, 1600); }
      }).catch(fail).then(function () { btn.disabled = false; });
    });
    // «+ Adicionar linha» (clique ou N): o formulário abre vazio.
    document.addEventListener('click', function (e) { if (e.target.closest && e.target.closest('[data-bl-add]')) lineForm(null); }, true);
    document.addEventListener('keydown', function (e) {
      if ((e.key === 'n' || e.key === 'N') && !e.metaKey && !e.ctrlKey && !e.altKey && !typing(document.activeElement) && !T.topMdl()) lineForm(null);
    }, true);
    syncKind();
  }

  bindAlways();

  // ── o que funciona também nas versões só de leitura ───────
  function bindAlways() {
    document.addEventListener('click', function (e) {
      var t = e.target.closest && e.target;
      if (!t) return;
      var m = t.closest('[data-bmdl]');
      if (m) { e.preventDefault(); T.openMdl($(m.getAttribute('data-bmdl')), m); return; }
      var nv = t.closest('[data-bver-new]');
      if (nv) {
        nv.disabled = true;
        api('budget_version_new', { version: VERSION }).then(function (j) { go(j); }).catch(function (er) { nv.disabled = false; fail(er); });
        return;
      }
      var ac = t.closest('[data-baccept]');
      if (ac) {
        ac.disabled = true;
        api('budget_accept', { version: VERSION }).then(function (j) { go(j, location.href); }).catch(function (er) { ac.disabled = false; fail(er); });
        return;
      }
      var cp = t.closest('[data-bcopy-a]');
      if (cp) {
        cp.disabled = true;
        api('budget_copy_a', { version: VERSION }).then(function (j) { T.toast(j.msg); setTimeout(function () { location.reload(); }, 500); })
          .catch(function (er) { cp.disabled = false; fail(er); });
        return;
      }
      var pdf = t.closest('[data-bpdf]');
      if (pdf) {
        pdf.disabled = true;
        api('budget_pdf', { version: VERSION }).then(function (j) {
          T.toast(j.msg);
          var a = document.createElement('a'); a.href = j.url; a.rel = 'noopener'; document.body.appendChild(a); a.click(); a.remove();
        }).catch(fail).then(function () { pdf.disabled = false; });
      }
    });
    var ts = $('form[data-btpl-save]');
    if (ts) ts.addEventListener('submit', function (e) {
      e.preventDefault();
      api('budget_tpl_save', new FormData(ts)).then(function (j) { T.closeMdl(ts.closest('.mdl')); T.toast(j.msg); ts.reset(); }).catch(fail);
    });
    var ta = $('form[data-btpl-apply]');
    if (ta) ta.addEventListener('submit', function (e) {
      e.preventDefault();
      api('budget_tpl_apply', new FormData(ta)).then(function (j) { go(j); }).catch(fail);
    });
  }

  // Depois de criar ou aceitar uma versão: a página nova mostra o aviso com «Anular».
  function go(j, url) {
    try { sessionStorage.setItem('topper-undo', JSON.stringify({ msg: j.msg, undo: j.undo || '' })); } catch (e) { /* sem armazenamento: só o aviso */ }
    var to = j.url || url || location.href, de = new URLSearchParams(location.search).get('de');
    if (de && to.indexOf('de=') < 0) to += (to.indexOf('?') < 0 ? '?' : '&') + 'de=' + encodeURIComponent(de);   // o «Voltar» continua a levar de onde se veio
    location.href = to;
  }
})();

// O aviso com «Anular» de uma ação que mudou de página (nova versão, aceitar, modelo).
(function () {
  'use strict';
  function show() {
    var raw = null;
    try { raw = sessionStorage.getItem('topper-undo'); sessionStorage.removeItem('topper-undo'); } catch (e) { return; }
    if (!raw || !window.topper) return;
    var j = JSON.parse(raw);
    window.topper.toast(j.msg, j.undo ? { undo: j.undo } : {});
  }
  if (window.topper) show(); else document.addEventListener('DOMContentLoaded', show, { once: true });
})();
