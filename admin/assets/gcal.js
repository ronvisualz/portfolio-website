/* topper. admin — Google Calendar e Meet no formulário da tarefa (§31.4): «Criar Google Meet», as respostas dos
   convidados e, ao marcar, quem está ocupado e os 3 próximos horários livres em comum. Nas reuniões (§31.6):
   «Convidar» (etiquetas de email, sugestões da ficha, respostas ao lado de cada email), os campos de cada
   plataforma e a morada sugerida. Tudo pelo servidor (gcal_api.php): o browser nunca fala com a Google. */
(function () {
  'use strict';
  if (window.__topperGcal) return;   // o painel do negócio pode trazer o ficheiro outra vez
  window.__topperGcal = true;
  function csrf() { var i = document.querySelector('input[name=csrf]'); return i ? i.value : ''; }
  function get(q) { return fetch('/admin/gcal_api.php?' + q, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } }).then(function (r) { return r.json(); }); }
  function post(a, fd) {
    return fetch('/admin/gcal_api.php?a=' + a, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-CSRF-Token': csrf() } })
      .then(function (r) { return r.json().catch(function () { return { error: 'Resposta inválida do servidor.' }; }); });
  }
  function el(tag, cls, txt) { var e = document.createElement(tag); if (cls) e.className = cls; if (txt != null) e.textContent = txt; return e; }

  // ── as respostas dos convidados (lidas ao abrir) ──
  function responses(box) {
    var id = box.getAttribute('data-task'), out = box.querySelector('[data-gm-resp]');
    if (!out || !id || id === '0') return;
    out.textContent = 'A ler as respostas no Google…';
    get('a=event&task=' + id).then(function (j) {
      out.textContent = '';
      if (j.error || !j.linked) return;
      if (j.organizer) out.appendChild(el('span', 'muted', 'Organizado por ' + j.organizer + '. '));
      (j.responses || []).forEach(function (r) {
        var c = el('span', 'chip ' + ({ accepted: 'ok', declined: 'no', tentative: 'wait' }[r.k] || 'info'), r.email + ': ' + r.status);
        out.appendChild(c); out.appendChild(document.createTextNode(' '));
      });
    }).catch(function () { out.textContent = ''; });
  }
  document.querySelectorAll('[data-gmeet][data-linked]').forEach(responses);

  // ── «Criar Google Meet» numa tarefa já gravada ──
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('[data-gmeet-create]');
    if (!b) return;
    e.preventDefault();
    var box = b.closest('[data-gmeet]'), out = box.querySelector('[data-gm-out]');
    var form = b.closest('form');
    var day = form && form.querySelector('[name=due_date]'), time = form && form.querySelector('[name=due_time]');
    var say = function (t, bad) { out.hidden = false; out.textContent = t; out.className = 'small ' + (bad ? 'neg' : 'pos'); };
    if (day && time && (!day.value || !time.value)) { say('Escolha o dia e a hora (e grave) antes de criar o Meet.', true); return; }
    var fd = new FormData(); fd.append('task', box.getAttribute('data-task')); fd.append('guests', (box.querySelector('[name=gmeet_guests]') || {}).value || '');
    b.disabled = true; say('A criar o Meet no Google…');
    post('meet', fd).then(function (j) {
      b.disabled = false;
      if (j.error) { say(j.error, true); return; }
      say(j.msg + ' Organizador: ' + j.organizer + '.');
      var now = box.querySelector('.gm-now');
      if (!now) { now = el('div', 'gm-now'); box.insertBefore(now, box.children[1] || null); }
      now.textContent = '';
      var a = el('a', 'gm-link', j.url.replace(/^https:\/\//, '')); a.href = j.url; a.target = '_blank'; a.rel = 'noopener';
      now.appendChild(a); now.appendChild(document.createTextNode(' ')); now.appendChild(el('span', 'chip ok', 'Google Meet'));
      var mk = box.querySelector('.gm-make'); if (mk) mk.remove();
      var manual = box.querySelector('[name=call_link]'); if (manual) manual.value = j.url;
    }).catch(function () { b.disabled = false; say('Sem ligação.', true); });
  });

  // ── ao marcar: ocupado nessa hora e 3 sugestões ──
  var timer = null;
  function slots(form) {
    var box = form.querySelector('[data-gslots]');
    if (!box) return;
    var who = form.querySelector('[name=member_id]'), dur = form.querySelector('[name=duration_min]');
    var day = form.querySelector('[name=due_date]'), time = form.querySelector('[name=due_time]');
    var uids = [].map.call(form.querySelectorAll('[name="participants[]"]:checked'), function (c) { return c.value; });
    var mid = who ? who.value : '0';
    if ((!mid || mid === '0') && !uids.length) { box.hidden = true; return; }
    var q = 'a=slots&mid=' + encodeURIComponent(mid) + '&uids=' + encodeURIComponent(uids.join(',')) + '&dur=' + encodeURIComponent((dur && dur.value !== '0') ? dur.value : '60')
          + '&date=' + encodeURIComponent(day ? day.value : '') + '&time=' + encodeURIComponent(time ? time.value : '');
    get(q).then(function (j) {
      box.textContent = '';
      if (j.error || (!j.slots.length && !j.busy.length)) { box.hidden = true; return; }
      box.hidden = false;
      if (j.busy.length) box.appendChild(el('p', 'small neg', 'Ocupado nessa hora: ' + j.busy.join(', ') + '.'));
      if (j.slots.length) {
        var row = el('div', 'chips-wrap');
        row.appendChild(el('span', 'small muted', (j.people > 1 ? 'Livres para todos: ' : 'Próximos livres: ')));
        j.slots.forEach(function (s) {
          var c = el('button', 'chip-btn', s.label); c.type = 'button';
          c.addEventListener('click', function () {
            if (day) day.value = s.date; if (time) time.value = s.time;
            [day, time].forEach(function (i) { if (i) i.dispatchEvent(new Event('change', { bubbles: true })); });
          });
          row.appendChild(c);
        });
        box.appendChild(row);
      }
      if (j.google) box.appendChild(el('p', 'small muted', 'Conta o Google Calendar de ' + j.google + (j.google === 1 ? ' pessoa' : ' pessoas') + ' (só ocupado/livre).'));
    }).catch(function () { box.hidden = true; });
  }
  function later(form) { clearTimeout(timer); timer = setTimeout(function () { slots(form); }, 350); }
  document.addEventListener('change', function (e) {
    var f = e.target.closest && e.target.closest('form[data-task-form]');
    if (!f) return;
    if (/^(member_id|duration_min|due_date|due_time|participants\[\])$/.test(e.target.name || '')) later(f);
  });
  document.querySelectorAll('form[data-task-form]').forEach(function (f) { if (f.closest('.mdl.open')) later(f); });

  // ── reuniões (§31.6): a plataforma muda os campos ──
  function plat(form) {
    var k = (form.querySelector('input[name=kind]:checked') || {}).value || 'task';
    var p = (form.querySelector('input[name=platform]:checked') || {}).value || '';
    form.querySelectorAll('[data-not="meeting"]').forEach(function (n) { n.hidden = k === 'meeting'; });
    form.querySelectorAll('[data-plat]').forEach(function (n) { n.hidden = k !== 'meeting' || n.getAttribute('data-plat') !== (p || 'local'); });
    form.querySelectorAll('[data-call]').forEach(function (n) { n.hidden = k === 'meeting' && p !== 'meet'; });
  }
  document.addEventListener('change', function (e) {
    var f = e.target.closest && e.target.closest('form[data-task-form]');
    if (f && (e.target.name === 'kind' || e.target.name === 'platform')) plat(f);
  });

  // ── «Convidar» ──
  var RESP = { accepted: ['Sim', 'ok'], declined: ['Não', 'no'], tentative: ['Talvez', 'wait'], needsAction: ['Sem resposta', 'info'], failed: ['Não enviado', 'no'] };
  var reEmail = /^[^\s@,;<>"]+@[^\s@,;<>"]+\.[a-z]{2,}$/i;
  function tinv(box) {
    if (box.__tinv) return;
    box.__tinv = true;
    var d = {};
    try { d = JSON.parse(box.getAttribute('data-tinv')) || {}; } catch (er) { d = {}; }
    var form = box.closest('form');
    if (!form) return;
    var wrap = box.querySelector('[data-tinv-box]'), inp = box.querySelector('[data-tinv-in]'), hid = box.querySelector('input[name=invitees]');
    var sugBox = box.querySelector('[data-tinv-sug]'), err = box.querySelector('[data-tinv-err]'), team = box.querySelector('[data-tinv-team]');
    var idIn = form.querySelector('input[name=id]'), taskId = idIn ? idIn.value : '0';
    var list = (d.inv || []).slice(), sug = d.sug || [], resp = d.resp || {}, max = d.max || 30;
    function nameOf(e) { for (var i = 0; i < sug.length; i++) if (sug[i].email === e) return sug[i].name; return ''; }
    function save() { hid.value = list.join(','); }
    function render() {
      [].slice.call(wrap.querySelectorAll('.tinv-tag')).forEach(function (t) { t.remove(); });
      list.forEach(function (e) {
        var t = el('span', 'tinv-tag'), nm = nameOf(e);
        t.title = e;
        t.appendChild(el('span', 'e', nm && nm.toLowerCase() !== e ? nm + ' · ' + e : e));
        var r = resp[e];
        if (r && RESP[r]) t.appendChild(el('span', 'chip ' + RESP[r][1], RESP[r][0]));
        var x = el('button', 'x', '×');
        x.type = 'button'; x.setAttribute('aria-label', 'Tirar ' + e);
        x.addEventListener('click', function () { list = list.filter(function (y) { return y !== e; }); save(); render(); inp.focus(); });
        t.appendChild(x);
        wrap.insertBefore(t, inp);
      });
      sugBox.textContent = '';
      var free = sug.filter(function (s) { return list.indexOf(s.email) < 0; });
      if (free.length) {
        sugBox.appendChild(el('span', 'small muted', 'Sugestões: '));
        free.forEach(function (s) {
          var b = el('button', 'chip-btn tinv-s');
          b.type = 'button';
          b.appendChild(document.createTextNode('+ ' + (s.name && s.name.toLowerCase() !== s.email ? s.name + ' · ' : '') + s.email + ' '));
          b.appendChild(el('small', 'muted', s.src + (s.lang === 'en' ? ' · EN' : '')));
          b.addEventListener('click', function () { add(s.email); });
          sugBox.appendChild(b);
        });
      }
    }
    function add(raw) {
      var bad = [];
      String(raw).split(/[\s,;]+/).forEach(function (e) {
        e = e.trim().toLowerCase().replace(/^[<"']+|[>"']+$/g, '');
        if (!e) return;
        if (!reEmail.test(e)) { bad.push(e); return; }
        if (list.indexOf(e) >= 0) return;
        if (list.length >= max) { bad.push(e + ' (no máximo ' + max + ')'); return; }
        list.push(e);
      });
      err.hidden = !bad.length;
      err.textContent = bad.length ? 'Email inválido: ' + bad.join(', ') : '';
      save(); render();
      return !bad.length;
    }
    inp.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ',' || e.key === ';' || (e.key === ' ' && inp.value.indexOf('@') > 0)) {
        if (inp.value.trim()) { e.preventDefault(); if (add(inp.value)) inp.value = ''; }
        else if (e.key === 'Enter') e.preventDefault();
      } else if (e.key === 'Backspace' && !inp.value && list.length) { list.pop(); save(); render(); }
    });
    inp.addEventListener('blur', function () { if (inp.value.trim() && add(inp.value)) inp.value = ''; });
    inp.addEventListener('paste', function (e) {
      var t = (e.clipboardData || window.clipboardData).getData('text');
      if (t && /[,;\s]/.test(t.trim())) { e.preventDefault(); add(t); }
    });
    wrap.addEventListener('click', function (e) { if (e.target === wrap) inp.focus(); });
    // o que ficou escrito vai com o formulário (e um email errado não deixa gravar)
    form.addEventListener('submit', function (e) {
      if (e.submitter && e.submitter.value === 'delete_task') return;
      if (inp.value.trim()) { if (add(inp.value)) inp.value = ''; else { e.preventDefault(); e.stopImmediatePropagation(); inp.focus(); } }
    }, true);
    // os internos: o responsável e os participantes, com o email de trabalho
    function teamNote() {
      var who = form.querySelector('[name=member_id]'), n = [];
      if (who && who.value && who.value !== '0' && who.selectedOptions && who.selectedOptions[0]) n.push(who.selectedOptions[0].textContent.trim());
      [].forEach.call(form.querySelectorAll('[name="participants[]"]:checked'), function (c) { var s = c.parentNode.querySelector('span'); if (s) n.push(s.textContent.trim()); });
      team.textContent = n.length ? 'Da equipa, com o email de trabalho: ' + n.join(', ') + '. Junte mais pessoas em «Participantes».'
                                  : 'Pessoas da equipa: escolha-as em «Participantes» (são convidadas com o email de trabalho).';
    }
    // a morada sugerida para o «Local»
    var aBox = form.querySelector('[data-tinv-addr]'), loc = form.querySelector('[name=location]');
    function addr() {
      if (!aBox || !loc) return;
      var show = !!d.addr && loc.value.trim() !== d.addr;
      aBox.hidden = !show;
      if (show) aBox.querySelector('span').textContent = 'Usar a morada' + (d.addr_of ? ' de ' + d.addr_of : '') + ': ' + d.addr;
    }
    if (aBox) aBox.addEventListener('click', function (e) { if (e.target.closest('[data-tinv-addr-use]')) { loc.value = d.addr; addr(); } });
    if (loc) loc.addEventListener('input', addr);
    // mudar o negócio ou o contacto traz outras sugestões
    var dealSel = form.querySelector('[name=deal_id]'), ctHid = form.querySelector('input[type=hidden][name=contact_id]');
    var last = (dealSel ? dealSel.value : '') + '|' + (ctHid ? ctHid.value : ''), tm = null;
    function refresh() {
      var now = (dealSel ? dealSel.value : '') + '|' + (ctHid ? ctHid.value : '');
      if (now === last) return;
      last = now;
      get('a=guests&deal=' + encodeURIComponent(dealSel ? dealSel.value : '0') + '&contact=' + encodeURIComponent(ctHid ? ctHid.value : '0')).then(function (j) {
        if (j.error) return;
        sug = j.suggest || []; d.addr = j.address || ''; d.addr_of = j.address_of || '';
        if (taskId === '0' && !list.length && sug.length) list.push(sug[0].email);   // reunião nova: o contacto vem marcado
        save(); render(); addr();
      }).catch(function () {});
    }
    form.addEventListener('change', function (e) {
      if (/^(member_id|participants\[\])$/.test(e.target.name || '')) teamNote();
      if (e.target.name === 'deal_id') refresh();
    });
    form.addEventListener('input', function (e) { if (e.target.name === 'contact_label') { clearTimeout(tm); tm = setTimeout(refresh, 400); } });
    // as respostas de agora (Google e convites por email)
    if (taskId && taskId !== '0') {
      get('a=event&task=' + taskId).then(function (j) {
        if (j.error) return;
        (j.responses || []).forEach(function (r) { if (r.email) resp[r.email.toLowerCase()] = r.k; });
        Object.keys(j.invites || {}).forEach(function (e) { resp[e] = j.invites[e]; });
        render();
      }).catch(function () {});
    }
    save(); render(); teamNote(); addr(); plat(form);
  }
  function scan(root) { if (root.querySelectorAll) root.querySelectorAll('[data-tinv]').forEach(tinv); }
  scan(document);
  if (window.MutationObserver) {
    new MutationObserver(function (ms) {
      ms.forEach(function (m) { [].forEach.call(m.addedNodes, function (n) { if (n.nodeType === 1) { if (n.matches && n.matches('[data-tinv]')) tinv(n); scan(n); } }); });
    }).observe(document.body, { childList: true, subtree: true });
  }
})();
