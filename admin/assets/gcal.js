/* topper. admin — Google Calendar e Meet no formulário da tarefa (§31.4): «Criar Google Meet», as respostas dos
   convidados e, ao marcar, quem está ocupado e os 3 próximos horários livres em comum. Tudo pelo servidor
   (gcal_api.php): o browser nunca fala com a Google. */
(function () {
  'use strict';
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
})();
