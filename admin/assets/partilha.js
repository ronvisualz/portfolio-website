/* topper. — página do link partilhado (§32.3).
 * Grelha: miniaturas (sem miniatura → ícone; PDF → 1.ª página num iframe pequeno e preguiçoso).
 * Visualizador em ecrã inteiro: setas (teclado e botões), deslizar no telemóvel, Esc fecha.
 * «Pode editar» (§32.2): carregar (direto para o armazenamento, com progresso), nova pasta, renomear, substituir.
 * Cada pedido volta a passar por partilha.php (pv/th/f): revogar o link corta tudo. */
(function () {
  'use strict';
  var dataEl = document.getElementById('sh-data');
  if (!dataEl) return;
  var D = JSON.parse(dataEl.textContent || '{}');
  var page = document.querySelector('.sh-page');
  var files = D.files || [];
  var enc = encodeURIComponent;
  var url = function (kind, rel) { return D.base + (kind ? '&' + kind + '=1' : '') + '&f=' + enc(rel); };
  var icon = function (k) { var c = document.querySelector('.sh-card[data-kind="' + k + '"] .sh-ico'); return c ? c.innerHTML : ''; };

  // ── miniaturas ───────────────────────────────────────────
  var pdfFrames = 0;
  document.querySelectorAll('.sh-thumb img').forEach(function (img) {
    var fail = function () {
      var th = img.parentNode, k = img.getAttribute('data-fallback'), card = th.closest('.sh-card');
      img.remove();
      th.classList.add('no-img');
      if (k === 'pdf' && card && pdfFrames < 12) {   // a 1.ª página, só para as primeiras (cada uma carrega o PDF)
        pdfFrames++;
        var f = document.createElement('iframe');
        f.loading = 'lazy'; f.tabIndex = -1; f.setAttribute('aria-hidden', 'true'); f.title = '';
        f.className = 'sh-pdf';
        f.src = url('pv', files[+card.getAttribute('data-i')].r) + '#page=1&toolbar=0&navpanes=0&scrollbar=0&view=FitH';
        th.classList.remove('no-img');
        th.appendChild(f);
      }
    };
    if (img.complete && img.naturalWidth === 0 && img.getAttribute('src')) fail();
    else img.addEventListener('error', fail, { once: true });
  });

  // ── visualizador ─────────────────────────────────────────
  var V = document.querySelector('.sh-viewer');
  var stage = V.querySelector('.sh-v-stage'), cur = -1, opener = null, sx = null, sy = 0;
  function show(i) {
    if (i < 0 || i >= files.length) return;
    cur = i;
    var f = files[i], h = '';
    var src = url('pv', f.r);
    if (f.pv === 'image') h = '<img alt="">';
    else if (f.pv === 'video') h = '<video controls playsinline autoplay preload="metadata"></video>';
    else if (f.pv === 'audio') h = '<audio controls autoplay preload="metadata"></audio>';
    else if (f.pv === 'pdf') h = '<iframe title=""></iframe>';
    else h = '<div class="sh-v-other">' + icon(f.k) + '<p></p><a class="btn" href="' + url('', f.r) + '" rel="nofollow">Descarregar</a></div>';
    stage.innerHTML = h;
    var el = stage.firstChild;
    if (f.pv === 'image') { el.src = src; el.alt = f.n; }
    else if (f.pv) { el.src = src; if (f.pv === 'pdf') el.title = f.n; }
    else el.querySelector('p').textContent = f.n + ' · ' + f.s + ' · ' + f.l;
    V.querySelector('.sh-v-name').textContent = f.n;
    V.querySelector('.sh-v-pos').textContent = (i + 1) + ' / ' + files.length;
    V.querySelector('[data-v-dl]').href = url('', f.r);
    V.querySelector('[data-v-prev]').disabled = i === 0;
    V.querySelector('[data-v-next]').disabled = i === files.length - 1;
    var nx = files[i + 1];
    if (nx && nx.pv === 'image') (new Image()).src = url('pv', nx.r);   // a seguinte já a caminho
  }
  function open(i, from) {
    opener = from || document.activeElement;
    V.hidden = false;
    document.documentElement.style.overflow = 'hidden';
    show(i);
    V.querySelector('[data-v-close]').focus();
  }
  function close() {
    V.hidden = true;
    stage.innerHTML = '';   // pára o vídeo/áudio
    cur = -1;
    document.documentElement.style.overflow = '';
    if (opener && opener.focus) opener.focus();
  }
  document.addEventListener('click', function (e) {
    var c = e.target.closest('.sh-card');
    if (c) { open(+c.getAttribute('data-i'), c); return; }
    if (e.target.closest('[data-v-close]')) close();
    else if (e.target.closest('[data-v-prev]')) show(cur - 1);
    else if (e.target.closest('[data-v-next]')) show(cur + 1);
    else if (e.target === stage && !V.hidden) close();
  });
  document.addEventListener('keydown', function (e) {
    if (V.hidden) return;
    if (e.key === 'Escape') { e.preventDefault(); close(); }
    else if (e.key === 'ArrowRight') { e.preventDefault(); show(cur + 1); }
    else if (e.key === 'ArrowLeft') { e.preventDefault(); show(cur - 1); }
    else if (e.key === 'Tab') {   // o foco fica dentro do visualizador
      var f = Array.prototype.filter.call(V.querySelectorAll('a[href], button:not([disabled]), video, audio, iframe'), function (x) { return x.offsetParent !== null; });
      if (!f.length) return;
      if (e.shiftKey && document.activeElement === f[0]) { e.preventDefault(); f[f.length - 1].focus(); }
      else if (!e.shiftKey && document.activeElement === f[f.length - 1]) { e.preventDefault(); f[0].focus(); }
    }
  });
  V.addEventListener('touchstart', function (e) { if (e.touches.length === 1) { sx = e.touches[0].clientX; sy = e.touches[0].clientY; } }, { passive: true });
  V.addEventListener('touchend', function (e) {
    if (sx === null) return;
    var dx = e.changedTouches[0].clientX - sx, dy = e.changedTouches[0].clientY - sy;
    sx = null;
    if (Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy) * 1.5) show(cur + (dx < 0 ? 1 : -1));
  }, { passive: true });

  // ── descarregar a seleção (§32.4): um POST com os caminhos escolhidos; o servidor faz o ZIP ──
  var selBtn = document.querySelector('[data-zip-sel]'), selForm = document.querySelector('[data-sel-form]'), selClear = document.querySelector('[data-sel-clear]');
  function picked() { return Array.prototype.slice.call(document.querySelectorAll('[data-sel]:checked')); }
  function syncSel() {
    if (!selBtn) return;
    var n = picked().length;
    selBtn.hidden = selClear.hidden = n === 0;
    selBtn.querySelector('span').textContent = 'Descarregar seleção (' + n + ')';
    if (page) page.classList.toggle('has-sel', n > 0);
  }
  document.addEventListener('change', function (e) { if (e.target.matches('[data-sel]')) syncSel(); });
  if (selBtn) {
    selBtn.addEventListener('click', function () {
      selForm.innerHTML = '';
      picked().forEach(function (c) {
        var i = document.createElement('input');
        i.type = 'hidden'; i.name = 'sel[]'; i.value = c.getAttribute('data-sel');
        selForm.appendChild(i);
      });
      if (selForm.children.length) selForm.submit();
    });
    selClear.addEventListener('click', function () { picked().forEach(function (c) { c.checked = false; }); syncSel(); });
    syncSel();   // o browser pode repor caixas marcadas ao voltar atrás
  }

  // ── «Pode editar» ────────────────────────────────────────
  if (!D.edit || !page) return;
  var who = document.getElementById('sh-who'), statusEl = document.querySelector('[data-status]');
  var upIn = document.querySelector('[data-up-input]');
  var KEY = 'td_sh_who';
  try { who.value = localStorage.getItem(KEY) || ''; } catch (e) {}
  function status(m, err) { statusEl.textContent = m || ''; statusEl.classList.toggle('err', !!err); }
  function name() {
    var v = who.value.trim();
    if (!v) { who.setAttribute('aria-invalid', 'true'); who.focus(); status('Escreva o seu nome primeiro, para quem partilhou saber quem enviou.', true); return ''; }
    who.removeAttribute('aria-invalid');
    try { localStorage.setItem(KEY, v); } catch (e) {}
    return v;
  }
  function post(body) {
    body.name = who.value.trim();
    return fetch(D.base, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Share-Form': page.getAttribute('data-ft') }, body: JSON.stringify(body) })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Resposta inválida (' + r.status + ').' }; }); },
            function () { throw new Error('Sem ligação. Tente outra vez.'); })
      .then(function (j) { if (!j.ok) throw new Error(j.error || 'Não foi possível concluir.'); return j; });
  }
  function put(u, file, onp) {
    return new Promise(function (res, rej) {
      var x = new XMLHttpRequest();
      x.open('PUT', u);
      x.setRequestHeader('Content-Type', file.type || 'application/octet-stream');
      x.upload.onprogress = function (e) { if (e.lengthComputable) onp(e.loaded / e.total); };
      x.onload = function () { x.status >= 200 && x.status < 300 ? res() : rej(new Error('O envio falhou (' + x.status + ').')); };
      x.onerror = function () { rej(new Error('O envio falhou. Verifique a ligação e tente outra vez.')); };
      x.send(file);
    });
  }
  /** Envia um ficheiro: pede o URL, envia direto, confirma. replaceRel = substituir esse ficheiro (a versão anterior fica guardada). */
  function send(file, n, total, replaceRel) {
    var rel = replaceRel || (D.sub + file.name);
    var label = total > 1 ? 'A enviar ' + n + ' de ' + total + ' — ' : 'A enviar ';
    status(label + file.name + '…');
    return post({ a: 'sign', rel: rel, size: file.size, replace: !!replaceRel }).then(function (j) {
      return put(j.url, file, function (p) { status(label + file.name + ' (' + Math.round(p * 100) + '%)'); })
        .then(function () { return post({ a: 'done', rel: j.rel, replaced: !!replaceRel }); });
    });
  }
  function busy(on) { document.querySelectorAll('[data-up], [data-mkdir], .sh-more').forEach(function (b) { b.disabled = on; }); }
  function run(list, replaceRel) {
    if (!list.length) return;
    busy(true);
    var i = 0;
    var next = function () {
      if (i >= list.length) { status(list.length > 1 ? list.length + ' ficheiros enviados.' : 'Enviado.'); location.reload(); return; }
      var f = list[i++];
      return send(f, i, list.length, replaceRel).then(next);
    };
    Promise.resolve().then(next).catch(function (er) { status(er.message, true); busy(false); });
  }
  var replacing = '';
  document.querySelector('[data-up]').addEventListener('click', function () { if (!name()) return; replacing = ''; upIn.multiple = true; upIn.click(); });
  upIn.addEventListener('change', function () {
    var list = Array.prototype.slice.call(upIn.files || []);
    upIn.value = '';
    run(replacing ? list.slice(0, 1) : list, replacing);
  });
  document.querySelector('[data-mkdir]').addEventListener('click', function () {
    if (!name()) return;
    var n = (window.prompt('Nome da nova pasta') || '').trim();
    if (!n) return;
    busy(true); status('A criar a pasta…');
    post({ a: 'mkdir', rel: D.sub + n }).then(function () { location.reload(); }, function (er) { status(er.message, true); busy(false); });
  });
  document.addEventListener('click', function (e) {
    var r = e.target.closest('[data-rename]'), s = e.target.closest('[data-replace]');
    if (r) {
      if (!name()) return;
      var old = r.getAttribute('data-name'), n = (window.prompt('Novo nome', old) || '').trim();
      if (!n || n === old) return;
      busy(true); status('A renomear…');
      post({ a: 'rename', rel: r.getAttribute('data-rename'), to: n }).then(function () { location.reload(); }, function (er) { status(er.message, true); busy(false); });
    } else if (s) {
      if (!name()) return;
      replacing = s.getAttribute('data-replace');
      upIn.multiple = false;
      upIn.click();
    }
  });
  // arrastar ficheiros para a página também carrega
  page.addEventListener('dragover', function (e) { if (e.dataTransfer && Array.prototype.indexOf.call(e.dataTransfer.types, 'Files') >= 0) e.preventDefault(); });
  page.addEventListener('drop', function (e) {
    if (!e.dataTransfer || !e.dataTransfer.files.length) return;
    e.preventDefault();
    if (!name()) return;
    run(Array.prototype.slice.call(e.dataTransfer.files), '');
  });
})();
