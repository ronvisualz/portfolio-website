/*
 * topper. admin — aba Contactos v2 (docs/ESPECIFICACAO-PLATAFORMA.md §13)
 *
 * Funciona na lista (contactos.php), no painel lateral e na ficha completa
 * (contact.php), sempre por delegação: o painel troca de conteúdo sem que
 * nada tenha de ser ligado outra vez.
 *
 *   · «+ Adicionar contacto»: pessoa ou empresa, tipos, «Serviços e preços»
 *     quando é fornecedor ou freelancer, aviso de duplicado enquanto se escreve;
 *   · na ficha: Editar / Gravar, Ativo / Inativo (admins), serviços e preços,
 *     Nova ação, concluir no Foco, relação (acompanhar, pausar, rever, «Como
 *     correu?» de um follow-up) e Juntar duplicados.
 *
 * Os formulários por cima saem do botão que os abre (app.js, §13.8). Tudo
 * grava por /admin/ui_api.php, que confirma as permissões em cada pedido.
 */
(function boot() {
  'use strict';
  if (!window.topper) { document.addEventListener('DOMContentLoaded', boot, { once: true }); return; }
  if (window.topperContacts) return;
  window.topperContacts = true;

  var T = window.topper;
  var reduced = window.matchMedia('(prefers-reduced-motion: reduce)');
  var ALERT = '<svg class="ico" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4M12 17h.01"/></svg>';

  function $(s, el) { return (el || document).querySelector(s); }
  function $$(s, el) { return Array.prototype.slice.call((el || document).querySelectorAll(s)); }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function typing(el) { return el && (el.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(el.tagName)); }
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function ymd(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
  function hm(d) { return pad(d.getHours()) + ':' + pad(d.getMinutes()); }
  function plusDays(n) { var d = new Date(); d.setDate(d.getDate() + n); return ymd(d); }
  function fail(e) { T.toast(e.message, { err: true }); }

  // Fora da aba Contactos, o painel traz os formulários num <template>: passam para a página.
  function adoptModals() {
    $$('template[data-contact-modals]').forEach(function (t) {
      if (!$('#mdl-rel')) document.body.appendChild(t.content.cloneNode(true));
      t.parentNode.removeChild(t);
    });
  }
  adoptModals();
  document.addEventListener('topper:panel', adoptModals);

  var openMdl = T.openMdl, closeMdl = T.closeMdl, topMdl = T.topMdl;

  // ── + Adicionar contacto ────────────────────────────────────
  var newMdl = $('#mdl-cnew');
  if (newMdl) {
    var form = $('form', newMdl), dupBox = $('.dup', newMdl), svcBox = $('[data-svc-box]', newMdl);
    var kindOf = function () { var r = $('input[name=kind]:checked', form); return r ? r.value : 'person'; };
    var syncKind = function () {
      var k = kindOf();
      $$('[data-only]', form).forEach(function (el) {
        var on = el.getAttribute('data-only') === k;
        el.hidden = !on;
        if (!on) $$('input[type=checkbox]', el).forEach(function (i) { i.checked = false; });
      });
      syncSvc();
    };
    var syncSvc = function () {
      var prov = $$('[data-type="is_supplier"], [data-type="is_freelancer"]', form).some(function (i) { return i.checked && !i.closest('[hidden]'); });
      var was = svcBox.hidden;
      svcBox.hidden = !prov;
      if (prov && was && !reduced.matches && svcBox.animate) svcBox.animate([{ opacity: 0, transform: 'translateY(-4px)' }, { opacity: 1, transform: 'none' }], { duration: 180, easing: 'ease-out' });
    };
    form.addEventListener('change', function (e) {
      if (e.target.name === 'kind') syncKind();
      if (e.target.hasAttribute('data-type')) {
        // Freelancer é uma pessoa sem empresa.
        if (e.target.getAttribute('data-type') === 'is_freelancer' && e.target.checked) {
          var co = $('input[name=company]', form);
          if (co && co.value.trim()) T.toast('Um freelancer é uma pessoa sem empresa: tire a empresa antes de gravar.', { err: true });
        }
        syncSvc();
      }
    });
    syncKind();

    // Linhas de serviço: acrescentar e tirar.
    var lineIdx = $$('[data-svc-row]', svcBox).length + 1;
    svcBox.addEventListener('click', function (e) {
      if (e.target.closest('[data-svc-row-add]')) {
        var tpl = $('template[data-svc-tpl]', svcBox);
        var html = tpl.innerHTML.replace(/svc\[999\]/g, 'svc[' + (lineIdx++) + ']');
        var tb = $('tbody', svcBox);
        tb.insertAdjacentHTML('beforeend', html);
        var sel = $('tr:last-child select', tb); if (sel) sel.focus();
        return;
      }
      var del = e.target.closest('[data-svc-row-del]');
      if (del) {
        var tr = del.closest('tr'), tb2 = tr.parentNode;
        if (tb2.children.length > 1) tb2.removeChild(tr);
        else $$('input[type=text], select', tr).forEach(function (i) { i.value = i.tagName === 'SELECT' && i.name.indexOf('[unit]') > 0 ? 'day' : ''; });
      }
    });

    // Duplicados enquanto se escreve.
    var dupTimer = null, dupSeq = 0;
    var dupCheck = function () {
      var name = $('input[name=name]', form).value.trim(), email = $('input[name=email]', form).value.trim(), phone = $('input[name=phone]', form).value.trim();
      if (name.length < 3 && !/.+@.+\..+/.test(email) && phone.replace(/\D/g, '').length < 6) { dupBox.hidden = true; dupBox.innerHTML = ''; return; }
      var my = ++dupSeq;
      T.api('contact_dups', { name: name, email: email, phone: phone }, 'GET').then(function (j) {
        if (my !== dupSeq) return;
        var h = '';
        j.contacts.forEach(function (c) {
          h += '<li><a href="/admin/contact.php?id=' + c.id + '" data-dup-open="contact:' + c.id + '">' + esc(c.name) + '</a> <span class="muted">· ' + esc(c.sub) + ' · mesmo ' + esc(c.why) + '</span></li>';
        });
        dupBox.innerHTML = h ? '<b>' + ALERT + ' Já existe em Contactos</b><ul>' + h + '</ul>' : '';
        dupBox.hidden = !h;
      }).catch(function () { /* é só um aviso */ });
    };
    $$('[data-cdup]', form).forEach(function (i) {
      i.addEventListener('input', function () { clearTimeout(dupTimer); dupTimer = setTimeout(dupCheck, 400); });
    });
    dupBox.addEventListener('click', function (e) {
      var open = e.target.closest('[data-dup-open]');
      if (open && T.openPanel && $('#sp')) { e.preventDefault(); closeMdl(newMdl); T.openPanel(open.getAttribute('data-dup-open')); }
    });
    form.addEventListener('submit', function (e) {
      var n = $('input[name=name]', form);
      if (!n.value.trim()) { e.preventDefault(); T.toast('Indique o nome.', { err: true }); n.focus(); return; }
      var fr = $('[data-type="is_freelancer"]', form), co = $('input[name=company]', form);
      if (kindOf() === 'person' && fr && fr.checked && co && co.value.trim()) {
        e.preventDefault(); T.toast('Um freelancer é uma pessoa sem empresa: tire a empresa ou desmarque Freelancer.', { err: true }); co.focus();
      }
    });
  }

  // ── a vista do contacto ─────────────────────────────────────
  function viewsOf(id) { return $$('.lv[data-contact="' + id + '"]'); }
  function contactOf(el) { var v = el.closest('[data-contact]'); return v ? v.getAttribute('data-contact') : null; }
  function applyParts(id, j) {
    viewsOf(id).forEach(function (v) {
      var f = $('.lv-focus', v); if (f && j.focus != null) f.innerHTML = j.focus;
      var n = $('[data-focus-n]', v); if (n && j.focus_n != null) n.textContent = j.focus_n;
      var h = $('.lv-hist .hist-list', v); if (h && j.history != null) h.innerHTML = j.history;
      if (j.relation != null) {
        var r = $('.cv-rel', v);
        if (r) r.outerHTML = j.relation;
        else if (j.relation) { var foot = $('.lv-read .lv-foot', v); (foot ? foot : $('.lv-read', v)).insertAdjacentHTML(foot ? 'beforebegin' : 'beforeend', j.relation); }
      }
    });
  }
  function refreshView(id) {
    if (T.panelKey && T.panelKey() === 'contact:' + id) T.reloadPanel(); else location.reload();
  }

  // ── Editar / Gravar ─────────────────────────────────────────
  function editing(v) { var f = v && $('form[data-contact-edit]', v); return f && !f.hidden ? f : null; }
  function syncEditKind(f) {
    var k = ($('input[name="f[kind]"]:checked', f) || {}).value || 'person';
    $$('[data-only]', f).forEach(function (el) { el.hidden = el.getAttribute('data-only') !== k; });
  }
  function startEdit(v) {
    var f = v && $('form[data-contact-edit]', v);
    if (!f || !f.hidden) return;
    $('.lv-read', v).hidden = true;
    f.hidden = false;
    syncEditKind(f);
    var b = $('[data-cedit-start]', v); if (b) b.hidden = true;
    var first = $('input[name="f[name]"]', f);
    if (first) first.focus();
    if (!reduced.matches && f.animate) f.animate([{ opacity: 0, transform: 'translateY(4px)' }, { opacity: 1, transform: 'none' }], { duration: 160, easing: 'ease-out' });
  }
  function stopEdit(v) {
    var f = editing(v); if (!f) return;
    f.reset();
    f.hidden = true;
    $('.lv-read', v).hidden = false;
    var b = $('[data-cedit-start]', v); if (b) { b.hidden = false; b.focus({ preventScroll: true }); }
  }
  document.addEventListener('click', function (e) {
    var s = e.target.closest && e.target.closest('[data-cedit-start]');
    if (s) { e.preventDefault(); startEdit(s.closest('.lv')); return; }
    var c = e.target.closest && e.target.closest('[data-cedit-cancel]');
    if (c) { e.preventDefault(); stopEdit(c.closest('.lv')); }
  });
  document.addEventListener('change', function (e) {
    if (e.target.matches && e.target.matches('form[data-contact-edit] input[name="f[kind]"]')) syncEditKind(e.target.form);
  });
  // A empresa: sugestões a partir de Contactos enquanto se escreve.
  var coTimer = null;
  document.addEventListener('input', function (e) {
    var i = e.target;
    if (!i.matches || !i.matches('form[data-contact-edit] input[name="f[company]"]')) return;
    clearTimeout(coTimer);
    coTimer = setTimeout(function () {
      var dl = document.getElementById(i.getAttribute('list'));
      if (!dl || i.value.trim().length < 2) return;
      T.api('contact_pick', { q: i.value.trim() }, 'GET').then(function (j) {
        dl.innerHTML = j.items.filter(function (x) { return x.kind === 'company'; }).map(function (x) {
          return '<option value="' + esc(x.label.replace(/ \(empresa\)$/, '')) + '">';
        }).join('');
      }).catch(function () {});
    }, 250);
  });
  document.addEventListener('keydown', function (e) {
    var f = e.target.closest && e.target.closest('form[data-contact-edit]');
    if (f) {
      if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); stopEdit(f.closest('.lv')); }
      else if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) { e.preventDefault(); f.requestSubmit ? f.requestSubmit() : f.submit(); }
      return;
    }
    // E: editar o contacto aberto (no painel ou na ficha), fora dos campos de escrita.
    if ((e.key === 'e' || e.key === 'E') && !e.metaKey && !e.ctrlKey && !e.altKey && !typing(document.activeElement) && !topMdl() && !$('.cmdk-back.open')) {
      var v = $('#sp.open .lv.cv') || $('.lv-page.cv');
      if (v && $('[data-cedit-start]', v) && !editing(v)) { e.preventDefault(); startEdit(v); }
    }
  }, true);
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (!f.matches || !f.matches('form[data-contact-edit]')) return;
    e.preventDefault();
    var v = f.closest('.lv'), id = v.getAttribute('data-contact'), page = v.classList.contains('lv-page');
    var btn = $('button[type=submit]', f), fd = new FormData(f);
    if (page) fd.append('flash', '1');
    btn.disabled = true;
    T.api('contact_save', fd).then(function (j) {
      if (page) { location.reload(); return; }   // o aviso com «Anular» vem com a página
      T.toast(j.msg, j.undo ? { undo: j.undo, afterUndo: function () { refreshView(id); } } : {});
      var tr = document.getElementById('c-' + id);
      if (tr) {
        var a = $('td.nm a.b', tr), sm = $('td.nm small', tr);
        if (a) a.lastChild.textContent = j.name;
        if (sm) sm.textContent = j.sub;
      }
      if (j.changed) refreshView(id); else stopEdit(v);
    }).catch(function (er) { btn.disabled = false; fail(er); });
  });

  // ── Ativo / Inativo (só admins; o servidor confirma) ────────
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('[data-active-toggle]');
    if (!b) return;
    var id = b.getAttribute('data-active-toggle');
    b.disabled = true;
    T.api('contact_active', { id: id }).then(function (j) {
      $$('[data-active-toggle="' + id + '"]').forEach(function (x) { x.outerHTML = j.html; });
      var tr = document.getElementById('c-' + id), dot = tr && $('.on-dot', tr);
      if (dot) { dot.classList.toggle('off', !j.active); dot.lastChild.textContent = j.active ? 'Ativo' : 'Inativo'; }
      T.toast(j.msg, { undo: j.undo, afterUndo: function () { refreshView(id); } });
    }).catch(function (er) { b.disabled = false; fail(er); });
  });

  // ── Serviços e preços ───────────────────────────────────────
  function svcSection(el) { return el.closest('[data-services]'); }
  function svcForm(sec) { return $('form[data-svc-form]', sec); }
  // A edição abre no lugar da linha (a linha esconde-se); acrescentar abre por baixo da tabela.
  function svcHome(f) {
    if (!f.__home) { f.__home = document.createComment('svc-form'); f.parentNode.insertBefore(f.__home, f); }
    return f.__home;
  }
  function svcPark(f) {
    var home = svcHome(f);
    if (f.__row) { f.__row.hidden = false; f.__row = null; }
    if (f.__cell) { var tr = f.__cell.parentNode; f.__cell = null; home.parentNode.insertBefore(f, home.nextSibling); if (tr.parentNode) tr.parentNode.removeChild(tr); }
  }
  function svcOpen(sec, row) {
    var f = svcForm(sec); if (!f) return;
    svcPark(f);
    if (row) {
      var tr = document.createElement('tr'), td = document.createElement('td');
      tr.className = 'svc-edit-tr'; td.colSpan = row.children.length;
      tr.appendChild(td); row.parentNode.insertBefore(tr, row.nextSibling);
      td.appendChild(f); row.hidden = true; f.__row = row; f.__cell = td;
    }
    f.reset();
    $('input[name=line]', f).value = row ? row.getAttribute('data-line') : '';
    if (row) {
      $('select[name=service_id]', f).value = row.getAttribute('data-service') !== '0' ? row.getAttribute('data-service') : '';
      $('select[name=unit]', f).value = row.getAttribute('data-unit') || 'day';
      $('input[name=price]', f).value = row.getAttribute('data-price');
      $('input[name=vat]', f).checked = row.getAttribute('data-vat') === '1';
      $('input[name=notes]', f).value = row.getAttribute('data-notes');
    }
    $('[data-svc-why]', f).hidden = !row;
    f.hidden = false;
    var first = $('select[name=service_id]', f);
    if (first) first.focus();
  }
  document.addEventListener('click', function (e) {
    var t = e.target;
    if (!t.closest) return;
    var add = t.closest('[data-svc-add]');
    if (add) { svcOpen(svcSection(add), null); return; }
    var ed = t.closest('[data-svc-edit]');
    if (ed) { svcOpen(svcSection(ed), ed.closest('tr')); return; }
    var cancel = t.closest('[data-svc-cancel]');
    if (cancel) { var f = cancel.closest('form'); svcPark(f); f.hidden = true; return; }
    var hist = t.closest('[data-svc-hist]');
    if (hist) {
      var list = $('.svc-hist', svcSection(hist)), open = hist.getAttribute('aria-expanded') === 'true';
      list.hidden = open; hist.setAttribute('aria-expanded', open ? 'false' : 'true');
      return;
    }
    var del = t.closest('[data-svc-del]');
    if (del) {
      var sec = svcSection(del), id = contactOf(del);
      del.disabled = true;
      T.api('service_remove', { id: id, line: del.getAttribute('data-svc-del') }).then(function (j) {
        sec.outerHTML = j.html;
        applyParts(id, { history: j.history });
        T.toast(j.msg, { undo: j.undo, afterUndo: function () { refreshView(id); } });
      }).catch(function (er) { del.disabled = false; fail(er); });
    }
  });
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (!f.matches || !f.matches('form[data-svc-form]')) return;
    e.preventDefault();
    var sec = svcSection(f), id = $('input[name=id]', f).value, btn = $('button[type=submit]', f);
    btn.disabled = true;
    T.api('service_save', new FormData(f)).then(function (j) {
      sec.outerHTML = j.html;
      applyParts(id, { history: j.history });
      T.toast(j.msg);
    }).catch(function (er) { btn.disabled = false; fail(er); });
  });

  // ── Nova ação ───────────────────────────────────────────────
  var TITLES = { call: 'Ligar a ', meeting: 'Reunião com ', email: 'Email para ', lunch: 'Almoço com ', dinner: 'Jantar com ', document: 'Documento para ', event: 'Evento · ', task: 'Tarefa · ', checklist: 'Tarefa · ' };
  function quickWhen(k) {
    var d = new Date();
    if (k === '1h' || k === '3h') {
      d.setMinutes(Math.ceil(d.getMinutes() / 5) * 5, 0, 0);
      d.setHours(d.getHours() + (k === '1h' ? 1 : 3));
    } else if (k === 'tomorrow') {
      d.setDate(d.getDate() + 1); d.setHours(10, 0, 0, 0);
    } else if (k === 'week') {
      d.setDate(d.getDate() + ((8 - d.getDay()) % 7 || 7)); d.setHours(10, 0, 0, 0);
    }
    return d;
  }
  document.addEventListener('change', function (e) {
    var r = e.target;
    if (!r.matches || !r.matches('form[data-contact-task] input[name=kind]')) return;
    var t = $('input[name=title]', r.form);
    t.placeholder = (TITLES[r.value] || TITLES.task) + t.getAttribute('data-who');
  });
  document.addEventListener('click', function (e) {
    var q = e.target.closest && e.target.closest('form[data-contact-task] [data-quick]');
    if (!q) return;
    var f = q.closest('form'), d = quickWhen(q.getAttribute('data-quick'));
    $('input[name=date]', f).value = ymd(d);
    $('input[name=time]', f).value = hm(d);
    $$('[data-quick]', f).forEach(function (b) { b.classList.toggle('on', b === q); b.setAttribute('aria-pressed', b === q ? 'true' : 'false'); });
  });
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (!f.matches || !f.matches('form[data-contact-task]')) return;
    e.preventDefault();
    var id = $('input[name=id]', f).value, btn = $('button[type=submit]', f);
    btn.disabled = true;
    T.api('contact_task', new FormData(f)).then(function (j) {
      applyParts(id, j);
      $('input[name=title]', f).value = '';
      $('textarea[name=notes]', f).value = '';
      $$('[data-quick]', f).forEach(function (b) { b.classList.remove('on'); b.removeAttribute('aria-pressed'); });
      T.toast(j.msg);
    }).catch(fail).then(function () { btn.disabled = false; });
  });

  // ── Foco: concluir ──────────────────────────────────────────
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('[data-cdone]');
    if (!b) return;
    var id = contactOf(b); if (!id) return;
    b.disabled = true;
    var li = b.closest('li'); if (li) li.classList.add('done');
    T.api('contact_task_done', { id: id, task: b.getAttribute('data-cdone') }).then(function (j) {
      setTimeout(function () { applyParts(id, j); }, reduced.matches ? 0 : 220);
      T.toast(j.msg, { undo: j.undo, afterUndo: function () { refreshView(id); } });
      if (j.relation_followup) openRout(id, b);
    }).catch(function (er) { b.disabled = false; if (li) li.classList.remove('done'); fail(er); });
  });

  // ── Relação: acompanhar / alterar ───────────────────────────
  var LEVEL_PTS = { formality: { proxima: 2, cordial: 1, formal: 0 }, business: { corporate: 3, medio: 2, pequeno: 1 } };
  function relLevel(f, p, b) {
    var pts = Math.max(1, Math.min(5, p)) * 2 + (LEVEL_PTS.business[b] || 1) + (LEVEL_PTS.formality[f] || 0);
    return pts >= 11 ? 'A' : (pts >= 7 ? 'B' : 'C');
  }
  function relCalc(f) {
    var out = $('[data-rel-calc]', f); if (!out) return;
    var val = function (n) { var r = $('input[name=' + n + ']:checked', f); return r ? r.value : ''; };
    var fo = val('formality'), po = +val('potential'), bu = val('business');
    if (!fo || !po || !bu) { out.textContent = 'O nível (A, B ou C) sai das três classificações.'; return; }
    var lvl = relLevel(fo, po, bu), cad = {};
    try { cad = JSON.parse(f.getAttribute('data-cad')) || {}; } catch (er) { /* sem cadências */ }
    var ex = $('input[name=cadence_days]', f).value;
    out.innerHTML = 'Nível <b>' + lvl + '</b> · follow-up a cada <b>' + esc(ex || cad[lvl] || '?') + ' dias</b>' + (ex ? ' (exceção)' : ' (Definições)');
  }
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('[data-rel-open]');
    if (!b) return;
    var m = $('#mdl-rel'); if (!m) return;
    var id = b.getAttribute('data-rel-open'), v = viewsOf(id)[0], f = $('form', m);
    var cur = {}, sec = v && $('[data-rel-json]', v);
    try { cur = sec ? JSON.parse(sec.getAttribute('data-rel-json')) : {}; } catch (er) { cur = {}; }
    f.reset();
    $('input[name=id]', f).value = id;
    $('[data-rel-name]', m).textContent = v ? v.getAttribute('data-name') : '';
    $('#mdl-rel-t').textContent = sec ? 'Alterar relação' : 'Acompanhar relação';
    ['formality', 'potential', 'business'].forEach(function (k) {
      var r = cur[k] != null ? $('input[name=' + k + '][value="' + cur[k] + '"]', f) : null;
      if (!r && !sec) r = $('input[name=' + k + '][value="' + ({ formality: 'cordial', potential: '3', business: 'medio' })[k] + '"]', f);
      if (r) r.checked = true;
    });
    var own = $('select[name=owner_id]', f);   // numa relação nova fica quem a cria (vem marcado)
    if (sec) own.value = cur.owner_id == null ? '' : String(cur.owner_id);
    $('input[name=cadence_days]', f).value = cur.cadence_days || '';
    relCalc(f);
    openMdl(m, b);
  });
  document.addEventListener('change', function (e) { var f = e.target.form; if (f && f.matches('[data-rel-form]')) relCalc(f); });
  document.addEventListener('input', function (e) { var f = e.target.form; if (f && f.matches('[data-rel-form]')) relCalc(f); });
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (!f.matches) return;
    if (f.matches('[data-rel-form]')) {
      e.preventDefault();
      var id = $('input[name=id]', f).value, btn = $('button[type=submit]', f);
      btn.disabled = true;
      T.api('relation_set', new FormData(f)).then(function (j) {
        closeMdl(f.closest('.mdl'));
        applyParts(id, j);
        T.toast(j.msg, { undo: j.undo, afterUndo: function () { refreshView(id); } });
      }).catch(fail).then(function () { btn.disabled = false; });
    } else if (f.matches('[data-relpause-form]')) {
      e.preventDefault();
      var pid = $('input[name=id]', f).value, pb = $('button[type=submit]', f);
      pb.disabled = true;
      T.api('relation_pause', new FormData(f)).then(function (j) {
        closeMdl(f.closest('.mdl'));
        applyParts(pid, j);
        T.toast(j.msg, { undo: j.undo, afterUndo: function () { refreshView(pid); } });
      }).catch(fail).then(function () { pb.disabled = false; });
    } else if (f.matches('[data-merge-form]')) {
      e.preventDefault();
      var mb = $('button[type=submit]', f);
      mb.disabled = true;
      T.api('contact_merge', new FormData(f)).then(function (j) {
        T.toast(j.msg);
        location.href = j.url;
      }).catch(function (er) { mb.disabled = false; fail(er); });
    }
  });
  // Pausar, retomar, deixar de acompanhar, revisão de nível.
  document.addEventListener('click', function (e) {
    var t = e.target;
    if (!t.closest) return;
    var p = t.closest('[data-rel-pause]');
    if (p) {
      var m = $('#mdl-relpause'); if (!m) return;
      $('input[name=id]', m).value = p.getAttribute('data-rel-pause');
      openMdl(m, p);
      return;
    }
    var act = t.closest('[data-rel-resume], [data-rel-stop], [data-rel-review]');
    if (!act) return;
    var id = contactOf(act); if (!id) return;
    var call = act.hasAttribute('data-rel-resume') ? ['relation_resume', { id: id }]
             : act.hasAttribute('data-rel-stop') ? ['relation_stop', { id: id }]
             : ['relation_review', { id: id, decision: act.getAttribute('data-rel-review'), potential: act.getAttribute('data-potential') || '' }];
    act.disabled = true;
    T.api(call[0], call[1]).then(function (j) {
      applyParts(id, j);
      T.toast(j.msg, j.undo ? { undo: j.undo, afterUndo: function () { refreshView(id); } } : {});
    }).catch(function (er) { act.disabled = false; fail(er); });
  });

  // ── «Como correu?» de um follow-up de relação (§13.5) ───────
  function routStep(m, step) { $$('.out-step', m).forEach(function (s) { s.hidden = s.getAttribute('data-step') !== step; }); }
  function openRout(id, from) {
    var m = $('#mdl-rout'); if (!m) return;
    var v = viewsOf(id)[0];
    m.setAttribute('data-contact-id', id);
    $('[data-rout-name]', m).textContent = v ? v.getAttribute('data-name') : '';
    $$('form', m).forEach(function (f) { f.reset(); });
    var sec = v && $('[data-rel-json]', v), cad = sec ? +sec.getAttribute('data-cadence') : 0;
    $('#rout-next').value = plusDays(cad || 15);
    $('#rout-next').min = plusDays(0);
    $('#rout-md').value = plusDays(1);
    routStep(m, 'choose');
    openMdl(m, from);
  }
  document.addEventListener('click', function (e) {
    var m = $('#mdl-rout'); if (!m || !m.contains(e.target)) return;
    var o = e.target.closest('[data-rout]'); if (!o) return;
    e.preventDefault();
    var id = m.getAttribute('data-contact-id'), what = o.getAttribute('data-rout');
    if (what === 'talked' || what === 'meeting') {
      routStep(m, what);
      var first = $('[data-step="' + what + '"] textarea, [data-step="' + what + '"] input', m);
      if (first) first.focus();
      return;
    }
    o.disabled = true;
    T.api('relation_outcome', { id: id, result: what }).then(function (j) {
      if (j.url) { location.href = j.url; return; }
      applyParts(id, j);
      closeMdl(m);
      T.toast(j.msg);
    }).catch(fail).then(function () { o.disabled = false; });
  });
  document.addEventListener('submit', function (e) {
    var m = $('#mdl-rout'), f = e.target;
    if (!m || !m.contains(f)) return;
    e.preventDefault();
    var id = m.getAttribute('data-contact-id'), btn = $('button[type=submit]', f), fd = new FormData(f);
    fd.append('id', id);
    fd.append('result', f.getAttribute('data-step'));
    btn.disabled = true;
    T.api('relation_outcome', fd).then(function (j) {
      applyParts(id, j);
      closeMdl(m);
      T.toast(j.msg);
    }).catch(fail).then(function () { btn.disabled = false; });
  });

  // ── Juntar duplicados ───────────────────────────────────────
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('[data-merge]');
    if (!b) return;
    var m = $('#mdl-merge'); if (!m) return;
    var id = contactOf(b), v = viewsOf(id)[0];
    $('input[name=id]', m).value = id;
    $('input[name=other]', m).value = b.getAttribute('data-merge');
    $('[data-merge-text]', m).innerHTML = 'Juntar <b>' + esc(b.getAttribute('data-merge-name')) + '</b> a <b>' + esc(v ? v.getAttribute('data-name') : '') + '</b>?';
    openMdl(m, b);
  });
})();
