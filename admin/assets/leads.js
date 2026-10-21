/*
 * topper. admin — aba Leads v2 (docs/ESPECIFICACAO-PLATAFORMA.md §12)
 *
 * Funciona na lista (leads.php), no painel lateral e na ficha completa
 * (lead.php), sempre por delegação: o painel troca de conteúdo sem que
 * nada tenha de ser ligado outra vez.
 *
 *   · formulários por cima (Nova lead, Arquivar, Como correu?) que crescem
 *     a partir do botão que os abriu, com Esc e o foco preso lá dentro;
 *   · atalho N para uma lead nova; aviso de duplicado enquanto se escreve;
 *   · Nova ação, concluir no Foco, «Como correu?» depois de uma chamada,
 *     arquivar com «Anular» e «Próxima urgente →».
 *
 * Tudo grava por /admin/ui_api.php, que confirma as permissões em cada
 * pedido. Sem JavaScript os formulários gravam por POST normal.
 */
(function boot() {
  'use strict';
  // Precisa do app.js (window.topper), que na página vem depois deste ficheiro.
  if (!window.topper) { document.addEventListener('DOMContentLoaded', boot, { once: true }); return; }
  if (window.topperLeads) return;
  window.topperLeads = true;

  var T = window.topper;
  var reduced = window.matchMedia('(prefers-reduced-motion: reduce)');
  var EASE = 'cubic-bezier(.2,.7,.2,1)';
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
  function fail(e) { T.toast(e.message, { err: true }); }

  // Fora da aba Leads, o painel traz os formulários num <template>: passam para a página.
  function adoptModals() {
    $$('template[data-lead-modals]').forEach(function (t) {
      if (!$('#mdl-out')) document.body.appendChild(t.content.cloneNode(true));
      t.parentNode.removeChild(t);
    });
  }
  adoptModals();
  document.addEventListener('topper:panel', adoptModals);

  // ── formulários por cima (§13.8, regra geral; substitui a da §12.1) ──
  // O botão transforma-se no formulário: estica da sua posição e forma até ao
  // centro, o conteúdo aparece a seguir e há um pequeno ressalto no fim
  // (~350 ms). Ao fechar, o inverso: o conteúdo desaparece e o formulário
  // encolhe de volta para dentro do botão. Sem movimento com reduced-motion.
  var MORPH_IN = 350, MORPH_OUT = 300, CARD_RADIUS = 14;
  function focusables(m) {
    return $$('a[href], button:not([disabled]), input:not([type=hidden]):not([disabled]), select, textarea, [tabindex]:not([tabindex="-1"])', m)
      .filter(function (n) { return n.offsetParent !== null && !n.closest('[hidden]'); });
  }
  function firstField(m) {
    var f = $('input:not([type=hidden]):not([type=radio]):not([type=checkbox]), textarea', $('.mdl-card', m));
    return f && f.offsetParent !== null ? f : focusables(m)[0];
  }
  function rectOf(el) {
    if (!el || !el.getBoundingClientRect || !document.contains(el)) return null;
    var r = el.getBoundingClientRect();
    return r.width > 0 && r.height > 0 ? r : null;
  }
  // O transform (e o raio, que a escala deforma) que põe o cartão em cima do botão.
  function overButton(card, a) {
    var b = card.getBoundingClientRect();
    var sx = Math.max(0.04, a.width / b.width), sy = Math.max(0.03, a.height / b.height);
    var cr = card.__btn ? getComputedStyle(card.__btn).borderRadius : '8px';
    var r = /%$/.test(cr) ? Math.min(a.width, a.height) * parseFloat(cr) / 100 : (parseFloat(cr) || 0);
    return {
      transform: 'translate(' + (a.left + a.width / 2 - (b.left + b.width / 2)).toFixed(1) + 'px,'
               + (a.top + a.height / 2 - (b.top + b.height / 2)).toFixed(1) + 'px) scale(' + sx.toFixed(4) + ',' + sy.toFixed(4) + ')',
      borderRadius: (r / sx).toFixed(1) + 'px / ' + (r / sy).toFixed(1) + 'px'
    };
  }
  function btnColor(el) {
    var c = el ? getComputedStyle(el).backgroundColor : '';
    return c && c !== 'transparent' ? c : 'rgba(0, 0, 0, 0)';
  }
  function hideOrigin(m, on) {
    var el = m.__from;
    if (el && el.style) el.style.visibility = on ? 'hidden' : '';
  }
  function morphIn(m) {
    var card = $('.mdl-card', m), back = $('.mdl-back', m), a = m.__rect;
    if (reduced.matches || !card.animate) return;
    card.classList.remove('pop-in');
    if (back) back.animate([{ opacity: 0 }, { opacity: 1 }], { duration: MORPH_IN, easing: 'ease-out' });
    var kids = Array.prototype.slice.call(card.children);
    kids.forEach(function (k) { k.animate([{ opacity: 0 }, { opacity: 0, offset: 0.55 }, { opacity: 1 }], { duration: MORPH_IN, easing: 'ease-out' }); });
    if (!a) {   // sem botão à vista: só surge, com o mesmo ressalto
      card.animate([{ transform: 'translateY(10px) scale(.96)', opacity: 0 }, { transform: 'scale(1.01)', opacity: 1, offset: 0.75 }, { transform: 'none', opacity: 1 }],
                   { duration: MORPH_IN, easing: EASE });
      return;
    }
    card.__btn = m.__from;
    var from = overButton(card, a), bg = getComputedStyle(card).backgroundColor;
    card.style.overflow = 'hidden';
    hideOrigin(m, true);
    card.animate([
      { transform: from.transform, borderRadius: from.borderRadius, backgroundColor: btnColor(m.__from), easing: 'cubic-bezier(.3,.7,.25,1)' },
      { transform: 'scale(1.015)', borderRadius: CARD_RADIUS + 'px', backgroundColor: bg, offset: 0.78, easing: 'ease-out' },
      { transform: 'none', borderRadius: CARD_RADIUS + 'px', backgroundColor: bg }
    ], { duration: MORPH_IN }).onfinish = function () { card.style.overflow = ''; };
  }
  function openMdl(m, from) {
    if (!m) return;
    m.__from = from || document.activeElement;
    m.__rect = rectOf(from);
    m.hidden = false;
    m.classList.add('open');
    document.documentElement.classList.add('mdl-lock');
    morphIn(m);
    var f = firstField(m);
    if (f) setTimeout(function () { f.focus({ preventScroll: true }); }, reduced.matches ? 0 : MORPH_IN - 50);
  }
  function closeMdl(m) {
    if (!m || !m.classList.contains('open') || m.__closing) return;
    // Os que só existem por endereço (converter, eliminar) fecham voltando à lista.
    var link = $('a.mdl-back[href]', m);
    if (link) { location.href = link.href; return; }
    var card = $('.mdl-card', m), back = $('.mdl-back', m), origin = m.__from;
    // Volta para onde o botão está agora; se já não estiver à vista, para onde estava.
    var a = rectOf(origin) || m.__rect;
    m.__closing = true;
    var fades = [];
    function done() {
      m.__closing = false;
      card.style.overflow = '';
      m.classList.remove('open');
      m.hidden = true;
      fades.forEach(function (f) { f.cancel(); });   // já escondido: o conteúdo volta ao normal sem piscar
      hideOrigin(m, false);
      if (!$('.mdl.open')) document.documentElement.classList.remove('mdl-lock');
      if (origin && origin.focus && document.contains(origin)) origin.focus({ preventScroll: true });
      m.__from = m.__rect = null;
    }
    if (!reduced.matches && card.animate) {
      Array.prototype.slice.call(card.children).forEach(function (k) {
        fades.push(k.animate([{ opacity: 1 }, { opacity: 0, offset: 0.35 }, { opacity: 0 }], { duration: MORPH_OUT, fill: 'forwards' }));
      });
      if (back) back.animate([{ opacity: 1 }, { opacity: 0 }], { duration: MORPH_OUT, easing: 'ease-in' });
      if (a) {
        card.__btn = origin;
        var to = overButton(card, a), bg = getComputedStyle(card).backgroundColor;
        card.style.overflow = 'hidden';
        card.animate([
          { transform: 'none', borderRadius: CARD_RADIUS + 'px', backgroundColor: bg },
          { transform: 'scale(1.01)', borderRadius: CARD_RADIUS + 'px', backgroundColor: bg, offset: 0.3, easing: 'cubic-bezier(.5,0,.75,0)' },
          { transform: to.transform, borderRadius: to.borderRadius, backgroundColor: btnColor(origin) }
        ], { duration: MORPH_OUT, fill: 'forwards' }).onfinish = function (ev) { done(); ev.target.cancel(); };
      } else {
        card.animate([{ opacity: 1, transform: 'none' }, { opacity: 0, transform: 'scale(.96)' }], { duration: 160, easing: 'ease-in', fill: 'forwards' })
          .onfinish = function (ev) { done(); ev.target.cancel(); };
      }
    } else done();
    // Tirar ?nova=1 / ?arch= do endereço, para não reabrir ao recarregar.
    if (/[?&](nova|arch)=/.test(location.search) && history.replaceState) {
      var u = new URL(location.href);
      u.searchParams.delete('nova'); u.searchParams.delete('arch');
      history.replaceState(null, '', u.pathname + u.search + u.hash);
    }
  }
  function topMdl() { var o = $$('.mdl.open'); return o[o.length - 1] || null; }

  document.addEventListener('click', function (e) {
    var c = e.target.closest && e.target.closest('[data-mdl-close]');
    if (c) { e.preventDefault(); closeMdl(c.closest('.mdl')); }
  });
  document.addEventListener('keydown', function (e) {
    var m = topMdl();
    if (m) {
      if (e.key === 'Escape') { e.preventDefault(); closeMdl(m); return; }
      if (e.key === 'Tab') {   // o foco fica dentro do formulário
        var f = focusables(m); if (!f.length) return;
        var i = f.indexOf(document.activeElement);
        if (e.shiftKey && i <= 0) { e.preventDefault(); f[f.length - 1].focus(); }
        else if (!e.shiftKey && i === f.length - 1) { e.preventDefault(); f[0].focus(); }
      }
      return;
    }
    // N: nova lead (fora dos campos de escrita).
    if ((e.key === 'n' || e.key === 'N') && !e.metaKey && !e.ctrlKey && !e.altKey && !typing(document.activeElement)
        && !$('.cmdk-back.open')) {
      var nm = $('#mdl-new');
      if (nm) { e.preventDefault(); openMdl(nm, $('[data-new-lead]')); }
      else if ($('#mdl-arch')) { e.preventDefault(); location.href = '/admin/leads.php?nova=1'; }
    }
  });

  // Os que já vêm abertos do servidor (?nova=1 depois de «Gravar e adicionar outra», ?arch=):
  // a nova lead sai outra vez do botão «Adicionar Lead».
  $$('.mdl.open').forEach(function (m) {
    document.documentElement.classList.add('mdl-lock');
    if (m.id === 'mdl-new') { m.__from = $('[data-new-lead]'); m.__rect = rectOf(m.__from); morphIn(m); }
    var f = firstField(m); if (f) f.focus({ preventScroll: true });
  });

  // ── nova lead ──────────────────────────────────────────────
  var newMdl = $('#mdl-new');
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('[data-new-lead]');
    if (!b || !newMdl || e.metaKey || e.ctrlKey) return;
    e.preventDefault();
    openMdl(newMdl, b);
  });

  if (newMdl) {
    var form = $('form', newMdl), dupBox = $('.dup', newMdl), useIn = $('input[name=use_contact]', newMdl);
    var dupTimer = null, dupSeq = 0;
    var dupFields = $$('[data-dup]', newMdl);

    function dupCheck() {
      var email = $('input[name=email]', form).value.trim(), phone = $('input[name=phone]', form).value.trim();
      var digits = phone.replace(/\D/g, '');
      if (!/.+@.+\..+/.test(email) && digits.length < 6) { dupBox.hidden = true; dupBox.innerHTML = ''; return; }
      var my = ++dupSeq;
      T.api('lead_dups', { email: email, phone: phone }, 'GET').then(function (j) {
        if (my !== dupSeq) return;
        var h = '';
        j.leads.forEach(function (l) {
          var isLead = l.phase === 'lead' || l.phase === 'archived';
          var what = l.phase === 'lead' ? 'lead ativa' : (l.phase === 'archived' ? 'lead arquivada' : 'negócio');
          h += '<li>' + esc(l.name) + ' <span class="muted">· ' + what + '</span> '
            + '<a class="lnk" href="' + (isLead ? '/admin/lead.php?id=' + l.id : '#') + '" data-dup-open="' + (isLead ? 'lead' : 'deal') + ':' + l.id + '">abrir</a></li>';
        });
        j.contacts.forEach(function (c) {
          var person = c.kind === 'person';
          var used = person && useIn.value === String(c.id);
          h += '<li>' + esc(c.name) + ' <span class="muted">· ' + (person ? 'pessoa' : 'empresa') + (c.company ? ' · ' + esc(c.company) : '') + ' em Contactos</span> '
            + '<button type="button" class="lnk" data-use="' + c.id + '" data-kind="' + esc(c.kind) + '" data-name="' + esc(c.name) + '" data-company="' + esc(c.company) + '">'
            + (used ? 'em uso ✓' : 'usar o existente') + '</button></li>';
        });
        if (j.hidden) h += '<li class="muted">' + j.hidden + (j.hidden === 1 ? ' registo' : ' registos') + ' com estes dados fora do seu alcance.</li>';
        dupBox.innerHTML = h ? '<b>' + ALERT + ' Já existe alguém com este email ou telefone</b><ul>' + h + '</ul>' : '';
        dupBox.hidden = !h;
      }).catch(function () { /* a verificação é só um aviso */ });
    }
    dupFields.forEach(function (i) {
      i.addEventListener('input', function () { clearTimeout(dupTimer); dupTimer = setTimeout(dupCheck, 400); });
    });
    if (dupFields.some(function (i) { return i.value.trim(); })) dupCheck();

    dupBox.addEventListener('click', function (e) {
      var use = e.target.closest('[data-use]');
      if (use) {
        var co = $('input[name=company]', form), pe = $('input[name=person]', form);
        if (use.getAttribute('data-kind') === 'person') {
          useIn.value = use.getAttribute('data-use');
          pe.value = use.getAttribute('data-name');
          if (!co.value.trim() && use.getAttribute('data-company')) co.value = use.getAttribute('data-company');
        } else {
          co.value = use.getAttribute('data-name');   // a empresa liga-se pelo nome
        }
        $$('[data-use]', dupBox).forEach(function (b) { b.textContent = 'usar o existente'; });
        use.textContent = 'em uso ✓';
        return;
      }
      var open = e.target.closest('[data-dup-open]');
      if (open && T.openPanel && $('#sp')) {
        e.preventDefault();
        closeMdl(newMdl);
        T.openPanel(open.getAttribute('data-dup-open'));
      }
    });
    // Mudar o nome da pessoa à mão deixa de usar o contacto escolhido.
    $('input[name=person]', form).addEventListener('input', function () {
      if (!useIn.value) return;
      useIn.value = '';
      $$('[data-use]', dupBox).forEach(function (b) { b.textContent = 'usar o existente'; });
    });
    form.addEventListener('submit', function (e) {
      var co = $('input[name=company]', form), pe = $('input[name=person]', form);
      if (!co.value.trim() && !pe.value.trim()) {
        e.preventDefault();
        T.toast('Indique a empresa ou a pessoa de contacto.', { err: true });
        co.focus();
      }
    });
  }

  // ── a vista da lead: atualizar depois de uma ação ──────────
  function viewsOf(id) { return $$('.lv[data-lead="' + id + '"]'); }
  function applyParts(id, j) {
    viewsOf(id).forEach(function (v) {
      var f = $('.lv-focus', v); if (f && j.focus != null) f.innerHTML = j.focus;
      var n = $('[data-focus-n]', v); if (n && j.focus_n != null) n.textContent = j.focus_n;
      var h = $('.lv-hist .hist-list', v); if (h && j.history != null) h.innerHTML = j.history;
      var chips = $('.lv-chips', v);
      if (chips && j.state) {
        // O estado aparece no cabeçalho e no campo «Estado» (edição no campo).
        $$('.chip[class*="st-"]', v).forEach(function (st) { st.outerHTML = j.state; });
        var ie = $('.ie[data-field="lead_state"]', v); if (ie && j.state_key) ie.setAttribute('data-value', j.state_key);
        var ring = $('.ring, .ring-none', chips);
        if (ring) ring.outerHTML = j.ring; else if (j.ring) chips.insertAdjacentHTML('beforeend', ' ' + j.ring);
      }
      var last = $('[data-last]', v); if (last && j.last) last.textContent = j.last;
    });
    // A linha da lista, se estiver à vista.
    var tr = document.getElementById('l-' + id);
    if (tr && j.state) {
      var td = tr.children;
      if (td[0] && j.ring) td[0].innerHTML = j.ring;
      if (td[2]) td[2].innerHTML = j.state;
      if (td[4] && j.next) td[4].innerHTML = j.next;
      if (td[5] && j.last) td[5].textContent = j.last;
      tr.className = tr.className.replace(/\blvl-\w+/g, '').trim();
      if (j.level) tr.classList.add('lvl-' + j.level);
    }
  }
  function refreshView(id) {
    if (T.panelKey && T.panelKey() === 'lead:' + id) T.reloadPanel(); else location.reload();
  }
  function leadOf(el) { var v = el.closest('[data-lead]'); return v ? v.getAttribute('data-lead') : null; }

  // ── Editar a ficha (§12.5): todos os campos de uma vez, «Gravar» ──
  function editing(v) { var f = v && $('form[data-lead-edit]', v); return f && !f.hidden ? f : null; }
  function startEdit(v) {
    var f = v && $('form[data-lead-edit]', v);
    if (!f || !f.hidden) return;
    $('.lv-read', v).hidden = true;
    f.hidden = false;
    var b = $('[data-edit-start]', v); if (b) b.hidden = true;
    var first = $('input:not([type=hidden]), textarea, select', f);
    if (first) first.focus();
    if (!reduced.matches && f.animate) f.animate([{ opacity: 0, transform: 'translateY(4px)' }, { opacity: 1, transform: 'none' }], { duration: 160, easing: 'ease-out' });
  }
  function stopEdit(v) {
    var f = editing(v); if (!f) return;
    f.reset();
    f.hidden = true;
    $('.lv-read', v).hidden = false;
    var b = $('[data-edit-start]', v); if (b) { b.hidden = false; b.focus({ preventScroll: true }); }
  }
  document.addEventListener('click', function (e) {
    var s = e.target.closest && e.target.closest('[data-edit-start]');
    if (s) { e.preventDefault(); startEdit(s.closest('.lv')); return; }
    var c = e.target.closest && e.target.closest('[data-edit-cancel]');
    if (c) { e.preventDefault(); stopEdit(c.closest('.lv')); }
  });
  document.addEventListener('keydown', function (e) {
    var f = e.target.closest && e.target.closest('form[data-lead-edit]');
    if (f) {
      if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); stopEdit(f.closest('.lv')); }
      else if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) { e.preventDefault(); f.requestSubmit ? f.requestSubmit() : f.submit(); }
      return;
    }
    // E: editar a lead aberta (no painel ou na ficha), fora dos campos de escrita.
    if ((e.key === 'e' || e.key === 'E') && !e.metaKey && !e.ctrlKey && !e.altKey && !typing(document.activeElement) && !topMdl() && !$('.cmdk-back.open')) {
      var v = $('#sp.open .lv') || $('.lv-page');
      if (v && $('[data-edit-start]', v) && !editing(v)) { e.preventDefault(); startEdit(v); }
    }
  }, true);
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (!f.matches || !f.matches('form[data-lead-edit]')) return;
    e.preventDefault();
    var v = f.closest('.lv'), id = v.getAttribute('data-lead'), page = v.classList.contains('lv-page');
    var btn = $('button[type=submit]', f), fd = new FormData(f);
    if (page) fd.append('flash', '1');
    btn.disabled = true;
    T.api('lead_save', fd).then(function (j) {
      if (page) { location.reload(); return; }   // o aviso com «Anular» vem com a página
      T.toast(j.msg, j.undo ? { undo: j.undo, afterUndo: function () { refreshView(id); } } : {});
      applyParts(id, j);
      var tr = document.getElementById('l-' + id);
      if (tr) {
        var a = $('td.nm a.b', tr), sm = $('td.nm small', tr);
        if (a) a.textContent = j.name;
        if (sm && j.company) sm.firstChild ? (sm.firstChild.textContent = j.company) : (sm.textContent = j.company);
        if (tr.children[3]) tr.children[3].textContent = j.owner;
      }
      if (j.changed) refreshView(id); else stopEdit(v);
    }).catch(function (er) { btn.disabled = false; fail(er); });
  });

  // ── Nova ação (§12.7) ───────────────────────────────────────
  var TITLES = { call: 'Ligar a ', meeting: 'Reunião com ', email: 'Email para ', lunch: 'Almoço com ', dinner: 'Jantar com ', document: 'Documento para ', event: 'Evento · ', task: 'Tarefa · ' };
  // Os atalhos de tempo, pela hora do browser.
  function quickWhen(k) {
    var d = new Date();
    if (k === '1h' || k === '3h') {
      d.setMinutes(Math.ceil(d.getMinutes() / 5) * 5, 0, 0);
      d.setHours(d.getHours() + (k === '1h' ? 1 : 3));
    } else if (k === 'tomorrow') {
      d.setDate(d.getDate() + 1); d.setHours(10, 0, 0, 0);
    } else if (k === 'week') {   // segunda-feira seguinte, às 10:00
      d.setDate(d.getDate() + ((8 - d.getDay()) % 7 || 7)); d.setHours(10, 0, 0, 0);
    }
    return d;
  }
  document.addEventListener('change', function (e) {
    var r = e.target;
    if (!r.matches || !r.matches('form[data-lead-task] input[name=kind]')) return;
    var t = $('input[name=title]', r.form);
    t.placeholder = (TITLES[r.value] || TITLES.task) + t.getAttribute('data-who');
  });
  document.addEventListener('click', function (e) {
    var q = e.target.closest && e.target.closest('form[data-lead-task] [data-quick]');
    if (!q) return;
    var f = q.form || q.closest('form'), d = quickWhen(q.getAttribute('data-quick'));
    $('input[name=date]', f).value = ymd(d);
    $('input[name=time]', f).value = hm(d);
    $$('[data-quick]', f).forEach(function (b) { b.classList.toggle('on', b === q); b.setAttribute('aria-pressed', b === q ? 'true' : 'false'); });
  });
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (!f.matches || !f.matches('form[data-lead-task]')) return;
    e.preventDefault();
    var id = $('input[name=id]', f).value, btn = $('button[type=submit]', f);
    btn.disabled = true;
    T.api('lead_task', new FormData(f)).then(function (j) {
      applyParts(id, j);
      $('input[name=title]', f).value = '';
      $('textarea[name=notes]', f).value = '';
      $$('[data-quick]', f).forEach(function (b) { b.classList.remove('on'); b.removeAttribute('aria-pressed'); });
      T.toast(j.msg);
    }).catch(fail).then(function () { btn.disabled = false; });
  });

  // ── Foco: concluir ──────────────────────────────────────────
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('[data-done]');
    if (!b) return;
    var id = leadOf(b); if (!id) return;
    b.disabled = true;
    var li = b.closest('li'); if (li) li.classList.add('done');
    T.api('lead_task_done', { id: id, task: b.getAttribute('data-done') }).then(function (j) {
      setTimeout(function () { applyParts(id, j); }, reduced.matches ? 0 : 220);
      T.toast(j.msg, { undo: j.undo, afterUndo: function () { refreshView(id); } });
      if (j.call) openOutcome(id, b);
    }).catch(function (er) { b.disabled = false; if (li) li.classList.remove('done'); fail(er); });
  });

  // ── «Como correu?» (§12.8) ──────────────────────────────────
  var outMdl = function () { return $('#mdl-out'); };
  function outStep(m, step) {
    $$('.out-step', m).forEach(function (s) { s.hidden = s.getAttribute('data-step') !== step; });
  }
  function setNext(m, next) {
    var b = $('[data-next-urgent]', m);
    b.hidden = !next;
    b.setAttribute('data-next', next || '');
  }
  function openOutcome(id, from) {
    var m = outMdl(); if (!m) return;
    var v = viewsOf(id)[0];
    m.setAttribute('data-lead', id);
    $('[data-out-name]', m).textContent = v ? v.getAttribute('data-name') : '';
    $('[data-out="convert"]', m).href = '/admin/leads.php?conv=' + id + '#converter';
    $('[data-suggest]', m).hidden = true;
    $$('[data-recall]', m).forEach(function (b) { b.disabled = false; b.classList.remove('on'); });
    var ta = $('textarea[name=note]', m); if (ta) ta.value = '';
    outStep(m, 'choose');
    setNext(m, null);
    openMdl(m, from);
    // A próxima urgente fica logo à mão.
    T.api('lead_next', { id: id }, 'GET').then(function (j) { if (m.getAttribute('data-lead') === id) setNext(m, j.next); }).catch(function () {});
  }
  document.addEventListener('click', function (e) {
    var m = outMdl(); if (!m || !m.contains(e.target)) return;
    var id = m.getAttribute('data-lead');
    var o = e.target.closest('[data-out]');
    if (o) {
      var what = o.getAttribute('data-out');
      if (what === 'convert') return;   // segue o link
      e.preventDefault();
      if (what === 'missed') {
        o.disabled = true;
        T.api('lead_outcome', { id: id, result: 'missed' }).then(function (j) {
          applyParts(id, j);
          $('[data-missed-msg]', m).textContent = j.msg + ' O prazo recomeçou.';
          $('[data-suggest]', m).hidden = !j.suggest_archive;
          setNext(m, j.next);
          outStep(m, 'missed');
          var first = $('[data-recall]', m); if (first) first.focus();
        }).catch(fail).then(function () { o.disabled = false; });
      } else if (what === 'followup') {
        outStep(m, 'followup');
        $('textarea[name=note]', m).focus();
      } else if (what === 'archive' || what === 'archive-noanswer') {
        closeMdl(m);
        var v = viewsOf(id)[0];
        // Sai do botão Arquivar da própria lead (painel ou ficha), como se tivesse sido carregado.
        openArchive(id, v ? v.getAttribute('data-name') : '', v ? $('[data-archive]', v) : o, what === 'archive-noanswer' ? 'sem_resposta' : '');
      }
      return;
    }
    var r = e.target.closest('[data-recall]');
    if (r) {
      var d = quickWhen(r.getAttribute('data-recall'));
      $$('[data-recall]', m).forEach(function (b) { b.disabled = true; });
      T.api('lead_task', { id: id, kind: 'call', title: '', notes: '', date: ymd(d), time: hm(d), duration: 15, assignee: '', repeat: '' }).then(function (j) {
        applyParts(id, j);
        r.classList.add('on');
        T.toast('Nova chamada marcada: ' + (r.textContent || '').toLowerCase() + '.');
      }).catch(function (er) { $$('[data-recall]', m).forEach(function (b) { b.disabled = false; }); fail(er); });
      return;
    }
    var nx = e.target.closest('[data-next-urgent]');
    if (nx) goNext(nx.getAttribute('data-next'), m);
  });
  document.addEventListener('submit', function (e) {
    var m = outMdl();
    if (!m || !e.target.matches('#mdl-out form[data-step="followup"]')) return;
    e.preventDefault();
    var id = m.getAttribute('data-lead'), btn = $('button[type=submit]', e.target);
    btn.disabled = true;
    T.api('lead_outcome', { id: id, result: 'followup', note: $('textarea[name=note]', e.target).value }).then(function (j) {
      applyParts(id, j);
      closeMdl(m);
      T.toast(j.msg);
      // O passo seguinte: a Nova ação da mesma lead.
      var v = viewsOf(id)[0], t = v && $('form[data-lead-task] input[name=title]', v);
      if (t) {
        t.scrollIntoView({ block: 'center', behavior: reduced.matches ? 'auto' : 'smooth' });
        t.focus({ preventScroll: true });
        var form = t.form; form.classList.remove('flash-in'); void form.offsetWidth; form.classList.add('flash-in');
      }
    }).catch(fail).then(function () { btn.disabled = false; });
  });

  function goNext(next, m) {
    if (!next) return;
    if (m) closeMdl(m);
    if (T.openPanel && $('#sp') && document.getElementById('l-' + next)) T.openPanel('lead:' + next);
    else if (T.openPanel && $('#sp') && !$('.lv-page')) T.openPanel('lead:' + next);
    else location.href = '/admin/lead.php?id=' + next;
  }

  // ── Arquivar (§12.9) ────────────────────────────────────────
  function openArchive(id, name, from, reason) {
    var m = $('#mdl-arch'); if (!m) return;
    var f = $('form', m);
    f.reset();
    $('input[name=id]', f).value = id;
    $('[data-arch-name]', m).textContent = name || '';
    var other = $('[data-other-text]', f); other.hidden = true; other.required = false;
    if (reason) { var r = $('input[name=reason][value="' + reason + '"]', f); if (r) r.checked = true; }
    openMdl(m, from);
    var chosen = $('input[name=reason]:checked', f) || $('input[name=reason]', f);
    if (chosen) setTimeout(function () { chosen.focus({ preventScroll: true }); }, reduced.matches ? 0 : 70);
  }
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('[data-archive]');
    if (!b) return;
    e.preventDefault();
    openArchive(b.getAttribute('data-archive'), b.getAttribute('data-name'), b);
  });
  document.addEventListener('change', function (e) {
    var r = e.target;
    if (!r.matches || !r.matches('form[data-arch-form] input[name=reason]')) return;
    var other = $('[data-other-text]', r.form), on = r.hasAttribute('data-other');
    other.hidden = !on; other.required = on;
    if (on) other.focus();
  });
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (!f.matches || !f.matches('form[data-arch-form]')) return;
    e.preventDefault();
    var m = f.closest('.mdl'), id = $('input[name=id]', f).value, btn = $('button[type=submit]', f);
    btn.disabled = true;
    T.api('lead_archive', new FormData(f)).then(function (j) {
      closeMdl(m);
      archived(id);
      T.toast(j.msg, { undo: j.undo, afterUndo: function () { location.reload(); } });
    }).catch(fail).then(function () { btn.disabled = false; });
  });
  // A lead arquivada sai da lista e do painel; na ficha fica marcada como arquivada.
  function archived(id) {
    var tr = document.getElementById('l-' + id);
    if (tr) {
      var gone = function () { if (tr.parentNode) tr.parentNode.removeChild(tr); };
      if (!reduced.matches && tr.animate) tr.animate([{ opacity: 1 }, { opacity: 0 }], { duration: 180 }).onfinish = gone; else gone();
    }
    if (T.panelKey && T.panelKey() === 'lead:' + id) T.closePanel();
    $$('.lv-page[data-lead="' + id + '"]').forEach(function (v) {
      var st = $('.lv-chips .chip[class*="st-"]', v); if (st) st.outerHTML = '<span class="chip">Arquivada</span>';
      $$('.lv-chips .ring, .lv-foot, .lv-blk:first-child form[data-lead-task]', v).forEach(function (n) { n.parentNode.removeChild(n); });
      $$('.ie-b, [data-done]', v).forEach(function (n) { n.hidden = true; });
    });
  }
})();
