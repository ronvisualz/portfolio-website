/* topper. admin — Financeiro (§28): o que melhora com JavaScript (sem ele, tudo funciona por formulários).
 *  · lançamento: largar o PDF → a IA lê e preenche (fica por confirmar); IVA, total e retenção calculados;
 *    âmbito Empresa / Negócio; «pagar ao freelancer» e «entregar ao Estado».
 *  · pedir fatura: escolher o negócio recarrega com os dados dele.
 *  · simulador: o resultado atualiza enquanto se escreve.
 *  · Definições › Financeiro: cada parâmetro grava ao sair do campo (com «Anular»).
 */
(function () {
  'use strict';
  var csrfMeta = document.querySelector('meta[name=csrf]');
  var csrf = csrfMeta ? csrfMeta.content : '';
  var T = window.topper || {};
  function $(s, r) { return (r || document).querySelector(s); }
  function $$(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
  function num(v) {
    v = String(v || '').replace(/\s|€/g, '');
    if (v.indexOf(',') >= 0) v = v.replace(/\./g, '').replace(',', '.');
    var n = parseFloat(v);
    return isNaN(n) ? 0 : n;
  }
  function fmt(n) { return (Math.round(n * 100) / 100).toFixed(2).replace('.', ','); }
  function eur(n) { return fmt(n).replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + ' €'; }
  function post(url, fd) {
    return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-CSRF-Token': csrf } })
      .then(function (r) { return r.json().catch(function () { return { error: 'Resposta inválida do servidor.' }; }).then(function (j) { if (!r.ok && !j.error) j.error = 'Erro ' + r.status; return j; }); });
  }
  function say(msg, undo) { if (T.toast) T.toast(msg, { undo: undo || null }); }

  // ── formulário de lançamento ─────────────────────────────
  $$('form[data-fin-doc]').forEach(function (f) {
    var base = $('[name=base]', f), rate = $('[name=vat_rate]', f), vat = $('[name=vat]', f), tot = $('[name=total]', f);
    var whr = $('[name=wh_rate]', f), wh = $('[name=withholding]', f), note = $('.fin-wh-note', f), pk = $('[name=party_kind]', f);
    var vatTouched = !!(vat && vat.value), totTouched = !!(tot && tot.value), whTouched = !!(wh && wh.value);
    function recalc() {
      var b = num(base.value), r = num(rate.value);
      if (vat && !vatTouched) vat.value = b ? fmt(b * r / 100) : '';
      if (tot && !totTouched) tot.value = b ? fmt(b + num(vat.value)) : '';
      if (wh && whr && !whTouched) wh.value = num(whr.value) > 0 && b ? fmt(b * num(whr.value) / 100) : '';
      if (note) {
        var w = wh ? num(wh.value) : 0, t = num(tot.value);
        note.textContent = w > 0 ? 'Pagar a quem faturou: ' + eur(t - w) + ' · entregar ao Estado: ' + eur(w) + ' (retenção na fonte)' : '';
      }
    }
    if (base) base.addEventListener('input', function () { recalc(); });
    if (rate) rate.addEventListener('change', function () { vatTouched = false; totTouched = false; recalc(); });
    if (vat) vat.addEventListener('input', function () { vatTouched = true; totTouched = false; recalc(); });
    if (tot) tot.addEventListener('input', function () { totTouched = true; recalc(); });
    if (whr) whr.addEventListener('change', function () { whTouched = false; recalc(); });
    if (wh) wh.addEventListener('input', function () { whTouched = true; recalc(); });
    if (pk && whr) pk.addEventListener('change', function () {
      if (pk.value === 'freelancer' && !num(whr.value)) whr.value = f.getAttribute('data-wh-b');
      if (pk.value === 'landlord' && !num(whr.value)) whr.value = f.getAttribute('data-wh-r');
      if (pk.value === 'freelancer') { var c = $('[name=category]', f); if (c && !c.value) c.value = 'Freelancers'; }
      whTouched = false; recalc();
    });
    // âmbito
    function scope() {
      var deal = ($('[name=scope]:checked', f) || {}).value === 'deal';
      $$('.fin-deal', f).forEach(function (el) { el.hidden = !deal; });
      var sel = $('[name=deal_id]', f); if (sel) sel.required = deal;
    }
    $$('[name=scope]', f).forEach(function (r) { r.addEventListener('change', scope); });
    var dsel = $('[name=deal_id]', f), lsel = $('[name=line_id]', f);
    if (dsel && lsel) dsel.addEventListener('change', function () { lsel.innerHTML = '<option value="">Negócio inteiro</option>'; });
    scope(); recalc();
    // largar o PDF → IA
    var drop = $('.fin-drop', f), file = $('input[type=file][name=file]', f), info = $('.fin-ai-note', f), dup = $('.fin-dup', f), tmp = $('[name=tmp]', f);
    if (!drop || !file) return;
    function set(name, v) {
      var el = $('[name=' + name + ']', f);
      if (!el || v === undefined || v === null || v === '') return;
      if (el.tagName === 'SELECT') {
        var ok = $$('option', el).some(function (o) { return o.value === String(v); });
        if (!ok && name === 'vat_rate') { var o = document.createElement('option'); o.value = String(v); o.textContent = String(v).replace('.', ',') + '%'; el.appendChild(o); ok = true; }
        if (!ok) return;
      }
      el.value = typeof v === 'number' && name !== 'vat_rate' && name !== 'wh_rate' ? fmt(v) : String(v);
      el.classList.add('ai-filled');
    }
    function read(fl) {
      if (!fl) return;
      var fd = new FormData(); fd.append('file', fl);
      drop.classList.add('busy'); info.textContent = 'A ler o documento…';
      post('/admin/fin_api.php?a=read', fd).then(function (j) {
        drop.classList.remove('busy');
        if (j.error) { info.textContent = j.error; return; }
        drop.classList.add('done');
        $('label', drop).innerHTML = '<b>' + (j.name || 'Documento') + '</b> pronto a juntar';
        tmp.value = j.token; file.value = '';
        if (!j.fields) { info.textContent = j.note || 'Preencha os campos.'; return; }
        var d = j.fields, dir = f.getAttribute('data-dir');
        vatTouched = true; totTouched = true; whTouched = true;
        set('number', d.number); set('issue_date', d.issue_date); set('due_date', d.due_date); set('base', d.base); set('vat_rate', d.vat_rate); set('vat', d.vat); set('total', d.total);
        set('party_name', d.party_name); set('party_nif', d.party_nif); set('description', d.description); set('currency', d.currency);
        if (dir === 'out') { set('withholding', d.withholding); if (d.wh_rate) set('wh_rate', d.wh_rate); set('category', d.category); set('party_kind', d.party_kind); }
        if (d.scope) { var r = $('[name=scope][value=' + d.scope + ']', f); if (r) { r.checked = true; scope(); } }
        if (d.kind === 'credit_note') set('doc_type', 'credit');
        recalc();
        var conf = Math.round((d.confidence || 0) * 100);
        info.textContent = 'A IA preencheu os campos marcados (confiança ' + conf + '%). Confirme tudo antes de guardar.'
          + (d.direction && d.direction !== dir ? (dir === 'in' ? ' Atenção: parece uma fatura de fornecedor.' : ' Atenção: parece uma fatura emitida por nós.') : '');
        if (dup) { dup.hidden = !j.dup; dup.textContent = j.dup ? 'Duplicado? Já existe ' + j.dup.label + ' com o mesmo NIF e número.' : ''; }
      }).catch(function () { drop.classList.remove('busy'); info.textContent = 'Sem ligação. O ficheiro segue com o formulário.'; });
    }
    file.addEventListener('change', function () { read(file.files[0]); });
    ['dragenter', 'dragover'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('over'); }); });
    ['dragleave', 'drop'].forEach(function (ev) { drop.addEventListener(ev, function () { drop.classList.remove('over'); }); });
    drop.addEventListener('drop', function (e) {
      e.preventDefault();
      var fl = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
      if (!fl) return;
      try { var dt = new DataTransfer(); dt.items.add(fl); file.files = dt.files; } catch (x) { /* browsers antigos */ }
      read(fl);
    });
  });

  // ── pedir fatura ─────────────────────────────────────────
  $$('form[data-fin-req]').forEach(function (f) {
    function scope() { var deal = ($('[name=scope]:checked', f) || {}).value === 'deal'; $$('.fin-deal', f).forEach(function (el) { el.hidden = !deal; }); }
    $$('[name=scope]', f).forEach(function (r) { r.addEventListener('change', scope); });
    var d = $('[name=deal_id]', f);
    if (d) d.addEventListener('change', function () { if (d.value) location.href = '/admin/financeiro.php?tab=receber&request=1&deal=' + encodeURIComponent(d.value); });
    scope();
  });

  // ── simulador ────────────────────────────────────────────
  var sim = $('form[data-fin-sim]'), res = $('.fin-sim-res'), timer = null, seq = 0;
  if (sim && res) {
    var run = function () {
      var fd = new FormData(sim), my = ++seq;
      fd.delete('do');
      res.classList.add('busy');
      post('/admin/fin_api.php?a=sim', fd).then(function (j) {
        if (my !== seq) return;
        res.classList.remove('busy');
        if (j.html) res.innerHTML = j.html;
      }).catch(function () { res.classList.remove('busy'); });
    };
    sim.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(run, 350); });
    sim.addEventListener('change', function () { clearTimeout(timer); timer = setTimeout(run, 50); });
  }

  // ── Definições › Financeiro: parâmetros ──────────────────
  $$('[data-fparam]').forEach(function (el) {
    el.setAttribute('data-was', el.value);
    function save() {
      if (el.value === el.getAttribute('data-was')) return;
      var fd = new FormData(); fd.append('key', el.getAttribute('data-fparam')); fd.append('field', el.getAttribute('data-ffield') || 'value'); fd.append('v', el.value);
      var row = el.closest('tr'); if (row) row.classList.add('saving');
      post('/admin/fin_api.php?a=param', fd).then(function (j) {
        if (row) row.classList.remove('saving');
        if (j.error) { say(j.error); el.value = el.getAttribute('data-was'); el.classList.add('bad'); return; }
        el.classList.remove('bad');
        if (el.getAttribute('data-ffield') !== 'verified_on' && j.value !== undefined) el.value = j.value;
        el.setAttribute('data-was', el.value);
        if (row) { row.classList.add('saved'); setTimeout(function () { row.classList.remove('saved'); }, 900); }
        say(j.msg || 'Guardado.', j.undo);
      });
    }
    el.addEventListener('change', save);
    el.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && el.tagName === 'INPUT') { e.preventDefault(); el.blur(); }
      if (e.key === 'Escape') { el.value = el.getAttribute('data-was'); el.blur(); }
    });
  });
  // Definições › Integrações › Email: a palavra-passe de cada caixa (testada e gravada só no servidor)
  function boxOut(el) { var r = el.closest('tr'); while (r && !r.classList.contains('fin-box-out')) r = r.nextElementSibling; return r; }
  function passOut(el, txt, bad) {
    var outRow = boxOut(el), out = outRow && outRow.querySelector('td');
    if (outRow) outRow.hidden = false;
    if (out) { out.textContent = txt; out.className = 'small ' + (bad ? 'neg' : 'pos'); }
  }
  document.addEventListener('submit', function (e) {
    var f = e.target.closest && e.target.closest('form[data-fin-mail-pass]');
    if (!f) return;
    e.preventDefault();
    var btn = f.querySelector('button[type=submit]'), inp = f.querySelector('input[name=pass]');
    var fd = new FormData(); fd.append('box', f.getAttribute('data-fin-mail-pass')); fd.append('pass', inp.value);
    btn.disabled = true; passOut(f, 'A testar a palavra-passe…', false);
    post('/admin/fin_api.php?a=mail_pass', fd).then(function (j) {
      btn.disabled = false; inp.value = '';
      passOut(f, j.error || j.msg, !!j.error);
      if (!j.error) setTimeout(function () { location.reload(); }, 900);
    }).catch(function () { btn.disabled = false; passOut(f, 'Sem ligação.', true); });
  });
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('[data-fin-mail-pass-del]');
    if (!b || !confirm('Tirar a palavra-passe de ' + b.getAttribute('data-fin-mail-pass-del') + ' do servidor? Deixa de ler e de enviar por esta caixa.')) return;
    var fd = new FormData(); fd.append('box', b.getAttribute('data-fin-mail-pass-del'));
    post('/admin/fin_api.php?a=mail_pass_del', fd).then(function (j) { passOut(b, j.error || j.msg, !!j.error); if (!j.error) setTimeout(function () { location.reload(); }, 700); });
  });
  // Definições › Integrações › Email: «Testar» cada caixa (só leitura)
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('[data-fin-mail-test]');
    if (!b) return;
    var outRow = boxOut(b), out = outRow && outRow.querySelector('td');
    if (outRow) outRow.hidden = false;
    b.disabled = true; if (out) { out.textContent = 'A testar…'; out.className = 'small'; }
    var fd = new FormData(); fd.append('box', b.getAttribute('data-fin-mail-test'));
    post('/admin/fin_api.php?a=mail_test', fd).then(function (j) {
      b.disabled = false;
      if (out) { out.textContent = j.error || j.msg; out.className = 'small ' + (j.error ? 'neg' : 'pos'); }
    }).catch(function () { b.disabled = false; if (out) out.textContent = 'Sem ligação.'; });
  });
})();
