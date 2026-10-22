/*
 * topper. admin — aba Marketing (§16): triagem e votos das ideias, kanban da
 * Produção (arrastar entre etapas; Agendado e Publicado pedem datas e links),
 * Calendário (arrastar uma peça aprovada para um dia = agendar), Estúdio
 * (grava sozinho; a IA propõe e a pessoa escolhe o que usar), métricas,
 * checklist e «Copiar legenda». Tudo passa por /admin/mkt_api.php, que
 * confirma as permissões em cada pedido; sem JavaScript, os formulários
 * continuam a funcionar.
 */
(function () {
  'use strict';

  // O app.js (avisos, formulários, painel) carrega depois desta página: lê-se quando for preciso.
  var T = window.topper || {};
  document.addEventListener('DOMContentLoaded', function () { T = window.topper || T; });
  var csrfMeta = document.querySelector('meta[name=csrf]');
  var csrf = csrfMeta ? csrfMeta.content : '';
  function $(s, el) { return (el || document).querySelector(s); }
  function $$(s, el) { return Array.prototype.slice.call((el || document).querySelectorAll(s)); }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function toast(msg, opt) { if (T.toast) return T.toast(msg, opt); }
  var NETS = { ig: 'Instagram', li: 'LinkedIn', x: 'X' };
  var SHORT = { ig: 'IG', li: 'in', x: 'X' };

  // ── pedidos ──────────────────────────────────────────────
  function mapi(action, data) {
    var fd = data instanceof FormData ? data : new FormData();
    if (!(data instanceof FormData)) {
      Object.keys(data || {}).forEach(function (k) {
        var v = data[k];
        if (v && typeof v === 'object' && !Array.isArray(v)) Object.keys(v).forEach(function (s) { fd.append(k + '[' + s + ']', v[s] == null ? '' : v[s]); });
        else if (Array.isArray(v)) v.forEach(function (x) { fd.append(k + '[]', x); });
        else fd.append(k, v == null ? '' : v);
      });
    }
    fd.set('a', action);
    return fetch('/admin/mkt_api.php', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-CSRF-Token': csrf } })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: r.status === 401 ? 'A sessão terminou. Recarregue a página.' : 'Não foi possível concluir. Tente outra vez.' }; }); },
            function () { return { ok: false, error: 'Sem ligação. Tente outra vez.' }; })
      .then(function (j) { if (!j || !j.ok) throw new Error((j && j.error) || 'Não foi possível concluir.'); return j; });
  }

  // Um aviso que sobrevive ao recarregar (para as ações que redesenham a página).
  function reloadWith(msg, undo) {
    try { sessionStorage.setItem('mkt-toast', JSON.stringify({ m: msg, u: undo || '' })); } catch (e) { /* sem sessionStorage: só recarrega */ }
    location.reload();
  }
  try {
    var pend = sessionStorage.getItem('mkt-toast');
    if (pend) {
      sessionStorage.removeItem('mkt-toast');
      pend = JSON.parse(pend);
      document.addEventListener('DOMContentLoaded', function () { toast(pend.m, pend.u ? { undo: pend.u } : {}); });
    }
  } catch (e) { /* nada a mostrar */ }

  function busy(btn, on, label) {
    if (!btn) return;
    if (on) { btn.__html = btn.innerHTML; btn.disabled = true; btn.classList.add('busy'); if (label) btn.textContent = label; }
    else { btn.disabled = false; btn.classList.remove('busy'); if (btn.__html != null) btn.innerHTML = btn.__html; }
  }

  // ── formulários genéricos: [data-mkt-form] ───────────────
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (!f.matches || !f.matches('form[data-mkt-form], form[data-idea-quick], form[data-vote], form[data-drive]')) return;
    e.preventDefault();
    if (f.hasAttribute('data-confirm') && !window.confirm(f.getAttribute('data-confirm'))) return;
    var fd = new FormData(f);
    var sub = e.submitter;
    if (sub && sub.name) fd.set(sub.name, sub.value);
    var action = fd.get('a');
    var btn = sub || $('button[type=submit], button:not([type])', f);
    busy(btn, true);
    mapi(action, fd).then(function (j) {
      busy(btn, false);
      var m = f.closest('.mdl');
      if (f.matches('[data-vote]')) {
        var b = $('.mvote', f);
        b.classList.toggle('on', j.on); b.setAttribute('aria-pressed', j.on ? 'true' : 'false');
        b.setAttribute('aria-label', j.on ? 'Tirar o meu voto' : 'Votar nesta ideia');
        $('span', b).textContent = j.n;
        return;
      }
      if (f.matches('[data-drive]')) { location.href = j.url; return; }
      if (f.matches('[data-idea-quick]')) { reloadWith(j.msg, j.undo); return; }
      // Triagem na lista de Ideias: a linha sai logo, com «Anular».
      var row = f.closest('tr[data-row]');
      if (action === 'triage' && row && !f.hasAttribute('data-reload')) {
        row.classList.add('gone');
        setTimeout(function () { if (row.parentNode) row.parentNode.removeChild(row); }, 260);
        toast(j.msg, j.undo ? { undo: j.undo } : {});
        return;
      }
      if (m && T.closeMdl) T.closeMdl(m);
      if (f.getAttribute('data-go')) {
        try { sessionStorage.setItem('mkt-toast', JSON.stringify({ m: j.msg, u: j.undo || '' })); } catch (x) { /* só navega */ }
        location.href = f.getAttribute('data-go');
        return;
      }
      reloadWith(j.msg, j.undo);
    }).catch(function (err) { busy(btn, false); toast(err.message, { err: true }); });
  });

  // ── copiar a legenda (com o link com rastreio) ───────────
  document.addEventListener('click', function (e) {
    var c = e.target.closest && e.target.closest('[data-copy]');
    if (!c) return;
    e.preventDefault();
    var text = c.getAttribute('data-copy');
    var done = function () { toast(c.getAttribute('data-copy-msg') || 'Legenda copiada (com o link com rastreio).'); };
    if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(text).then(done, function () { fallback(); });
    else fallback();
    function fallback() {
      var t = document.createElement('textarea');
      t.value = text; t.setAttribute('readonly', ''); t.style.position = 'fixed'; t.style.opacity = '0';
      document.body.appendChild(t); t.select();
      try { document.execCommand('copy'); done(); } catch (x) { toast('Não foi possível copiar: selecione o texto à mão.', { err: true }); }
      document.body.removeChild(t);
    }
  });

  // ── formulários de agendar / publicar / métricas ─────────
  function cardData(el) {
    var w = el && el.closest('[data-card]');
    try { return w ? JSON.parse(w.getAttribute('data-card')) : null; } catch (x) { return null; }
  }
  function fillSched(card, preset) {
    var m = $('#mdl-msched'); if (!m || !card) return null;
    $('input[name=id]', m).value = card.id;
    $('[data-title]', m).textContent = card.title;
    $$('.msched-row', m).forEach(function (r) {
      var n = r.getAttribute('data-net'), on = card.nets.indexOf(n) >= 0, p = card.posts[n] || {};
      r.hidden = !on;
      var d = $('input[type=date]', r), t = $('input[type=time]', r);
      if (!d) return;
      d.disabled = !on || !!p.pub;
      d.value = p.at ? p.at.slice(0, 10) : '';
      t.value = p.at ? p.at.slice(11, 16) : '10:00';
      if (preset && preset.net === n) { d.value = preset.day; if (preset.time) t.value = preset.time; }
    });
    return m;
  }
  function fillPub(card) {
    var m = $('#mdl-mpub'); if (!m || !card) return null;
    $('input[name=id]', m).value = card.id;
    $$('.msched-row', m).forEach(function (r) {
      var n = r.getAttribute('data-net'), on = card.nets.indexOf(n) >= 0, p = card.posts[n] || {};
      r.hidden = !on;
      var i = $('input', r); i.disabled = !on; i.value = p.url || '';
    });
    return m;
  }
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('[data-sched-open], [data-metrics], [data-shoot], [data-new="#mdl-shoot"]');
    if (!b) return;
    if (b.matches('[data-sched-open]')) {
      e.preventDefault();
      var m = fillSched(cardData(b));
      if (m && T.openMdl) T.openMdl(m, b);
      return;
    }
    if (b.matches('[data-metrics]')) {
      e.preventDefault();
      var d = JSON.parse(b.getAttribute('data-metrics')), mm = $('#mdl-mmet');
      if (!mm) return;
      $('input[name=id]', mm).value = d.id; $('input[name=network]', mm).value = d.net;
      $('[data-title]', mm).textContent = '· ' + d.title;
      ['reach', 'interactions', 'saves', 'shares', 'clicks'].forEach(function (k) { $('input[name=' + k + ']', mm).value = d.v[k] == null ? '' : d.v[k]; });
      if (T.openMdl) T.openMdl(mm, b);
      return;
    }
    // Dia de gravação: «Editar» preenche, «+ Dia de gravação» limpa (o app.js abre o formulário a seguir).
    var sm = $('#mdl-shoot'); if (!sm) return;
    var s = b.hasAttribute('data-shoot') ? JSON.parse(b.getAttribute('data-shoot')) : { id: '', day: '', title: '', place: '', notes: '', pieces: [] };
    $('input[name=id]', sm).value = s.id; $('input[name=day]', sm).value = s.day || '';
    $('input[name=title]', sm).value = s.title || ''; $('input[name=place]', sm).value = s.place || ''; $('textarea[name=notes]', sm).value = s.notes || '';
    $$('input[name="pieces[]"]', sm).forEach(function (c) { c.checked = s.pieces.indexOf(+c.value) >= 0; });
  }, true);

  // ── kanban da Produção (§16.5) ───────────────────────────
  var board = $('[data-mboard]');
  if (board) {
    var drag = null;
    var recount = function () { $$('.col', board).forEach(function (c) { $('[data-cnt]', c).textContent = $$('.mwrap', c).length; }); };
    var moveTo = function (wrap, stage, from) {
      var card = cardData(wrap), cur = wrap.closest('.col').getAttribute('data-stage');
      if (!card || stage === cur) return;
      // Abre um instante depois de largar: o clique que alguns browsers geram ao soltar o rato não o fecha logo.
      var open = function (m) { if (m && T.openMdl) setTimeout(function () { T.openMdl(m, from || wrap); }, 60); };
      if (stage === 'agendado') { open(fillSched(card)); return; }
      if (stage === 'publicado') { open(fillPub(card)); return; }
      var col = $('.col[data-stage="' + stage + '"] .cards', board), origin = wrap.parentNode, next = wrap.nextSibling;
      col.appendChild(wrap); recount();
      mapi('move', { id: card.id, stage: stage }).then(function (j) {
        var tmp = document.createElement('div'); tmp.innerHTML = j.card;
        var old = $('.mcard', wrap); if (old && tmp.firstChild) wrap.replaceChild(tmp.firstChild, old);
        var sel = $('select[name=stage]', wrap); if (sel) sel.value = stage;
        toast(j.msg, j.undo ? { undo: j.undo } : {});
      }).catch(function (err) {
        origin.insertBefore(wrap, next); recount();
        var sel = $('select[name=stage]', wrap); if (sel) sel.value = cur;
        toast(err.message, { err: true });
      });
    };
    board.addEventListener('dragstart', function (e) {
      var c = e.target.closest('.mcard[draggable="true"]'); if (!c) return;
      drag = c.closest('.mwrap'); drag.classList.add('dragging');
      e.dataTransfer.effectAllowed = 'move';
      try { e.dataTransfer.setData('text/plain', c.getAttribute('data-id')); } catch (x) { /* Firefox antigo */ }
    });
    board.addEventListener('dragend', function () {
      if (drag) drag.classList.remove('dragging');
      drag = null; $$('.col.over', board).forEach(function (c) { c.classList.remove('over'); });
    });
    board.addEventListener('dragover', function (e) {
      var col = e.target.closest('.col'); if (!col || !drag) return;
      e.preventDefault(); e.dataTransfer.dropEffect = 'move';
      $$('.col.over', board).forEach(function (c) { if (c !== col) c.classList.remove('over'); });
      col.classList.add('over');
    });
    board.addEventListener('drop', function (e) {
      var col = e.target.closest('.col'); if (!col || !drag) return;
      e.preventDefault(); col.classList.remove('over');
      moveTo(drag, col.getAttribute('data-stage'), col);
    });
    // Sem rato: o seletor do cartão.
    board.addEventListener('change', function (e) {
      var s = e.target.closest('select[name=stage]'); if (!s) return;
      moveTo(s.closest('.mwrap'), s.value, s);
    });
  }

  // ── Calendário (§16.6): arrastar = agendar ───────────────
  var cal = $('.mcal-wrap');
  if (cal) {
    var item = null;
    cal.addEventListener('dragstart', function (e) {
      var c = e.target.closest('[data-ready], [data-move]'); if (!c) return;
      item = { piece: c.getAttribute('data-ready') || c.getAttribute('data-move'), net: c.getAttribute('data-net'), time: c.getAttribute('data-time') || '10:00', el: c };
      e.dataTransfer.effectAllowed = 'move';
      try { e.dataTransfer.setData('text/plain', item.piece); } catch (x) { /* nada */ }
      cal.classList.add('dragging-' + item.net);
    });
    cal.addEventListener('dragend', function () {
      if (item) cal.classList.remove('dragging-' + item.net);
      item = null; $$('.cell.over', cal).forEach(function (c) { c.classList.remove('over'); });
    });
    cal.addEventListener('dragover', function (e) {
      var cell = e.target.closest('.cell'); if (!cell || !item) return;
      e.preventDefault();
      $$('.cell.over', cal).forEach(function (c) { if (c !== cell) c.classList.remove('over'); });
      cell.classList.add('over');
    });
    cal.addEventListener('drop', function (e) {
      var cell = e.target.closest('.cell'); if (!cell || !item) return;
      e.preventDefault(); cell.classList.remove('over');
      var net = cell.getAttribute('data-net'), day = cell.getAttribute('data-day');
      if (net !== item.net) { toast('Arraste para a linha do ' + NETS[item.net] + '.', { err: true }); return; }
      if (cell.hasAttribute('data-past')) { toast('Esse dia já passou. Escolha hoje ou um dia seguinte.', { err: true }); return; }
      var time = item.time, when = {};   // o «dragend» limpa o item antes da resposta
      when[net] = day + ' ' + time;
      mapi('schedule', { id: item.piece, when: when }).then(function (j) { reloadWith(j.msg + ' (' + NETS[net] + ' · ' + day.split('-').reverse().slice(0, 2).join('/') + ' ' + time + ')', j.undo); })
        .catch(function (err) { toast(err.message, { err: true }); });
    });
  }

  // ── Estúdio (§16.4) ──────────────────────────────────────
  var studio = $('[data-studio]');
  if (studio) {
    var pid = studio.getAttribute('data-studio');
    var canEdit = studio.getAttribute('data-edit') === '1';
    var timers = {};
    var saved = function () { /* gravação discreta: sem aviso a cada tecla */ };
    var count = function (box) {
      var max = +box.getAttribute('data-max'), net = box.getAttribute('data-net');
      var body = $('[data-field=body]', box).value, hook = $('[data-field=hook]', box).value;
      var out = $('[data-count]', box);
      if (net === 'x') {
        var parts = body.split(/^\s*-{3,}\s*$/m).map(function (s) { return s.trim(); }).filter(Boolean);
        if (parts.length > 1) {
          var worst = Math.max.apply(null, parts.map(function (p) { return p.length; }));
          out.textContent = parts.length + ' posts · maior ' + worst + '/' + max;
          out.classList.toggle('neg', worst > max);
          return;
        }
        var n = (hook ? hook.length + 1 : 0) + body.length;
        out.textContent = n + '/' + max; out.classList.toggle('neg', n > max);
        return;
      }
      var len = (hook ? hook.length + 2 : 0) + body.length;
      out.textContent = len + ' car.'; out.classList.toggle('neg', len > max);
    };
    $$('.mout', studio).forEach(function (box) {
      count(box);
      box.addEventListener('input', function (e) {
        var f = e.target.closest('[data-field]'); if (!f || !canEdit) return;
        count(box);
        var key = box.getAttribute('data-net') + ':' + f.getAttribute('data-field');
        clearTimeout(timers[key]);
        timers[key] = setTimeout(function () {
          mapi('version', { id: pid, network: box.getAttribute('data-net'), field: f.getAttribute('data-field'), value: f.value })
            .then(saved).catch(function (err) { toast(err.message, { err: true }); });
        }, 700);
      });
    });
    $$('[data-piece-field]', studio).forEach(function (f) {
      var go = function () {
        var o = {}; o[f.getAttribute('data-piece-field')] = f.value;
        mapi('piece_save', { id: pid, f: o }).then(saved).catch(function (err) { toast(err.message, { err: true }); });
      };
      if (f.tagName === 'SELECT') f.addEventListener('change', go);
      else { var t; f.addEventListener('input', function () { clearTimeout(t); t = setTimeout(go, 900); }); }
    });

    var props = $('[data-props]', studio), list = $('[data-props-list]', studio);
    var setBox = function (net, v) {
      var box = $('.mout[data-net="' + net + '"]', studio); if (!box || !v) return;
      ['hook', 'body', 'cta', 'hashtags'].forEach(function (k) { var i = $('[data-field=' + k + ']', box); if (i) i.value = v[k] || ''; });
      count(box);
      box.classList.add('flash-in'); setTimeout(function () { box.classList.remove('flash-in'); }, 900);
    };
    var render = function (items) {
      list.innerHTML = '';
      items.forEach(function (p) {
        var el = document.createElement('article');
        el.className = 'mprop';
        var target = p.network ? NETS[p.network] : '';
        var use = p.field === 'script' ? 'Usar como guião' : 'Usar no ' + (SHORT[p.network] || target);
        var val = p.field === 'all' ? [p.parts.hook, p.parts.body, p.parts.hashtags].filter(Boolean).join('\n\n') : p.value;
        if (p.field === 'thread') val = p.value.split(/\n---\n/).map(function (x, i) { return x + '   [' + x.length + '/280]'; }).join('\n\n');
        el.innerHTML = '<header>' + (p.network ? '<span class="mnet n-' + p.network + '">' + esc(SHORT[p.network]) + '</span>' : '') + '<b>' + esc(p.label) + '</b></header>'
          + '<p class="mprop-v"></p><footer><button type="button" class="btn sm" data-use>' + esc(use) + '</button> <button type="button" class="btn ghost sm" data-drop>Descartar</button></footer>';
        $('.mprop-v', el).textContent = val;
        $('[data-use]', el).addEventListener('click', function () {
          var b = this;
          busy(b, true);
          mapi('ai_use', { id: pid, network: p.network || '', field: p.field, value: p.value, parts: p.parts || {} }).then(function (j) {
            if (p.field === 'script') { reloadWith(j.msg, j.undo); return; }
            setBox(p.network, j.versions[p.network]);
            el.classList.add('used'); busy(b, false); b.textContent = 'Usado'; b.disabled = true;
            toast(j.msg, j.undo ? { undo: j.undo } : {});
          }).catch(function (err) { busy(b, false); toast(err.message, { err: true }); });
        });
        $('[data-drop]', el).addEventListener('click', function () { el.parentNode.removeChild(el); if (!list.children.length) props.hidden = true; });
        list.appendChild(el);
      });
      props.hidden = !items.length;
      props.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    };
    $$('[data-ai]', studio).forEach(function (b) {
      b.addEventListener('click', function () {
        var act = b.getAttribute('data-ai');
        var net = $('#ai-net') ? $('#ai-net').value : '';
        busy(b, true, 'A pensar…');
        mapi('ai', { id: pid, act: act, network: net, tone: $('#ai-tone') ? $('#ai-tone').value : '' }).then(function (j) {
          busy(b, false); render(j.proposals); toast(j.msg);
        }).catch(function (err) { busy(b, false); toast(err.message, { err: true }); });
      });
    });
  }

  // ── checklist da peça ────────────────────────────────────
  document.addEventListener('change', function (e) {
    var c = e.target.closest && e.target.closest('form[data-check] input[type=checkbox]');
    if (!c) return;
    var f = c.form, fd = new FormData(f);
    if (!c.checked) fd.delete('done');
    mapi('check', fd).then(function (j) {
      var box = f.closest('[data-checklist]'), n = box && $('[data-check-n]', box);
      if (n) n.textContent = j.done + '/' + j.total;
    }).catch(function (err) { c.checked = !c.checked; toast(err.message, { err: true }); });
  });

  // ── resumo do mês pela IA (§16.8) ────────────────────────
  var sg = $('[data-summary-go]');
  if (sg) sg.addEventListener('click', function () {
    busy(sg, true, 'A escrever…');
    mapi('summary', { month: sg.getAttribute('data-summary-go') }).then(function (j) {
      busy(sg, false);
      var b = $('[data-summary-body]'); b.innerHTML = '<p></p>'; $('p', b).textContent = j.summary;
      toast(j.msg);
    }).catch(function (err) { busy(sg, false); toast(err.message, { err: true }); });
  });
})();
