/* topper. admin — Centro Criativo (§23): Projetos, Biblioteca, Caixa de entrada e o quadro (tela infinita).
 * Tudo o que grava passa por cc_api.php (permissões no servidor). As miniaturas pedem-se pela largura
 * no ecrã (256 · 768 · 1600), só para o que está à vista; mover e fazer zoom mexe só num transform. */
(function () {
  'use strict';
  var root = document.getElementById('cc');
  if (!root) return;
  var T = window.topper || {};   // o app.js carrega depois deste: volta a ler-se no arranque (DOMContentLoaded)
  var CSRF = (document.querySelector('meta[name="csrf"]') || {}).content || '';
  var VIEW = root.getAttribute('data-view');
  var EDIT = root.getAttribute('data-edit') === '1';
  var MANAGE = root.getAttribute('data-manage') === '1';
  var API = '/admin/cc_api.php';

  function $(s, r) { return (r || document).querySelector(s); }
  function $$(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function toast(m, o) { if (T.toast) return T.toast(m, o); window.alert(m); return { close: function () {} }; }
  function fail(e) { toast((e && e.message) || String(e), { err: true }); }
  function typing(el) { return el && (el.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(el.tagName)); }
  function clamp(v, a, b) { return Math.max(a, Math.min(b, v)); }
  function fmtDur(s) { s = Math.round(s || 0); return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0'); }
  function fmtBytes(n) { return n > 1048576 ? (n / 1048576).toFixed(1).replace('.', ',') + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB'; }
  var reduced = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : { matches: false };

  // ── ícones (traço, 24×24) ──────────────────────────────────
  var IC = {
    drive: '<path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z"/>',
    cursor: '<path d="m4 4 7 17 2.5-7.5L21 11Z"/>',
    note: '<path d="M15 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V9z"/><path d="M15 3v6h6"/>',
    column: '<rect x="4" y="3" width="7" height="18" rx="1.5"/><rect x="13" y="3" width="7" height="12" rx="1.5"/>',
    list: '<path d="M9 6h11M9 12h11M9 18h11"/><path d="m3 6 1.5 1.5L7 5M3 12l1.5 1.5L7 11M3 18l1.5 1.5L7 17"/>',
    swatch: '<circle cx="13.5" cy="6.5" r="1.5"/><circle cx="17.5" cy="10.5" r="1.5"/><circle cx="8.5" cy="7.5" r="1.5"/><circle cx="6.5" cy="12.5" r="1.5"/><path d="M12 2a10 10 0 0 0 0 20c.9 0 1.6-.7 1.6-1.6 0-.4-.2-.8-.4-1.1-.3-.3-.4-.7-.4-1.1 0-.9.7-1.6 1.6-1.6H16a6 6 0 0 0 6-6c0-4.9-4.5-8.6-10-8.6Z"/>',
    pen: '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
    arrow: '<path d="M5 19 19 5M9 5h10v10"/>',
    upload: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m17 8-5-5-5 5M12 3v12"/>',
    link: '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
    board: '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/>',
    play: '<path d="M7 4v16l13-8Z" fill="currentColor" stroke="none"/>',
    spark: '<path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9Z"/>',
    bubble: '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
    present: '<rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/>',
    plus: '<path d="M5 12h14M12 5v14"/>', minus: '<path d="M5 12h14"/>',
    fit: '<path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7"/>',
    trash: '<path d="M3 6h18M8 6V4h8v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>',
    check: '<path d="M20 6 9 17l-5-5"/>', swap: '<path d="m17 3 4 4-4 4M3 7h18M7 21l-4-4 4-4M21 17H3"/>',
    x: '<path d="M18 6 6 18M6 6l12 12"/>', file: '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v6h6"/>',
    send: '<path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/>',
    side: '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M15 3v18"/>', ext: '<path d="M15 3h6v6M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
    grid: '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>'
  };
  function ico(n, cls) { return '<svg class="ico' + (cls ? ' ' + cls : '') + '" viewBox="0 0 24 24" aria-hidden="true">' + (IC[n] || IC.file) + '</svg>'; }

  // ── pedidos ────────────────────────────────────────────────
  function api(a, data, method) {
    method = method || (data ? 'POST' : 'GET');
    var url = API + '?a=' + encodeURIComponent(a);
    var opt = { method: method, credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-CSRF-Token': CSRF } };
    if (method === 'GET') {
      if (data) Object.keys(data).forEach(function (k) { var v = data[k]; if (v != null && v !== '' && v !== false) url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(v); });
    } else {
      var fd = data instanceof FormData ? data : new FormData();
      if (!(data instanceof FormData) && data) {
        Object.keys(data).forEach(function (k) {
          var v = data[k];
          if (v == null) return;
          if (Array.isArray(v)) v.forEach(function (x) { fd.append(k + '[]', x); });
          else if (v instanceof Blob) fd.append(k, v, v.name || 'blob');
          else if (typeof v === 'object') fd.append(k, JSON.stringify(v));
          else fd.append(k, v === true ? '1' : v === false ? '' : v);
        });
      }
      fd.set('a', a);
      opt.body = fd;
    }
    return fetch(url, opt).then(function (r) {
      return r.json().catch(function () { return { ok: false, error: r.status === 401 ? 'A sessão terminou. Recarregue a página.' : 'Não foi possível concluir (' + r.status + '). Tente outra vez.' }; });
    }, function () { var er = new Error('Sem ligação. Tente outra vez.'); er.offline = true; throw er; }).then(function (j) {
      if (!j || !j.ok) throw new Error((j && j.error) || 'Não foi possível concluir.');
      return j;
    });
  }
  function xhr(method, url, body, headers, onp) {
    return new Promise(function (res, rej) {
      var x = new XMLHttpRequest();
      x.open(method, url);
      Object.keys(headers || {}).forEach(function (k) { x.setRequestHeader(k, headers[k]); });
      if (x.upload && onp) x.upload.onprogress = function (e) { if (e.lengthComputable) onp(e.loaded / e.total); };
      x.onload = function () { if (x.status >= 200 && x.status < 300) res(x.responseText); else rej(new Error('O envio falhou (' + x.status + ').')); };
      x.onerror = function () { rej(new Error('O envio falhou (ligação).')); };
      x.send(body);
    });
  }

  // ── uma janela simples (pergunta), com a animação das outras ─
  function ask(title, label, opt) {
    opt = opt || {};
    return new Promise(function (resolve) {
      var m = document.createElement('div');
      m.className = 'mdl'; m.hidden = true; m.setAttribute('role', 'dialog'); m.setAttribute('aria-modal', 'true');
      m.innerHTML = '<div class="mdl-back" data-mdl-close></div><form class="mdl-card" autocomplete="off"><h3>' + esc(title) + '</h3>'
        + (opt.text ? '<p class="muted">' + esc(opt.text) + '</p>' : '')
        + '<label class="field"><span>' + esc(label) + '</span>' + (opt.area ? '<textarea name="v" rows="6"></textarea>' : '<input name="v" type="' + (opt.type || 'text') + '">') + '</label>'
        + '<div class="actions"><button class="btn" type="submit">' + esc(opt.ok || 'Gravar') + '</button><button class="btn ghost" type="button" data-mdl-close>Cancelar</button></div></form>';
      document.body.appendChild(m);
      var inp = $('[name=v]', m);
      inp.value = opt.value || '';
      if (opt.placeholder) inp.placeholder = opt.placeholder;
      var done = false;
      function finish(v) { if (done) return; done = true; resolve(v); setTimeout(function () { m.remove(); }, 400); }
      $('form', m).addEventListener('submit', function (e) { e.preventDefault(); var v = inp.value.trim(); if (T.closeMdl) T.closeMdl(m); finish(v); });
      new MutationObserver(function () { if (m.hidden) finish(null); }).observe(m, { attributes: true, attributeFilter: ['hidden'] });
      if (T.openMdl) T.openMdl(m, opt.from || null); else { m.hidden = false; m.classList.add('open'); }
    });
  }
  function modal(html, cls) {
    var m = document.createElement('div');
    m.className = 'mdl'; m.hidden = true; m.setAttribute('role', 'dialog'); m.setAttribute('aria-modal', 'true');
    m.innerHTML = '<div class="mdl-back" data-mdl-close></div><div class="mdl-card ' + (cls || 'wide') + '">' + html + '</div>';
    document.body.appendChild(m);
    new MutationObserver(function () { if (m.hidden && !m.__keep) setTimeout(function () { m.remove(); }, 400); }).observe(m, { attributes: true, attributeFilter: ['hidden'] });
    if (T.openMdl) T.openMdl(m); else { m.hidden = false; m.classList.add('open'); }
    // o foco entra na janela (Esc fecha mesmo que o botão que a abriu já não exista)
    setTimeout(function () { var f = $('button, [href], input, select, textarea', m.lastChild); if (f) f.focus({ preventScroll: true }); }, reduced.matches ? 0 : 260);
    return m;
  }

  // ── miniaturas ─────────────────────────────────────────────
  function thumbW(tw, px) {
    var l = (tw || []).slice().sort(function (a, b) { return a - b; });
    if (!l.length) return 768;
    for (var i = 0; i < l.length; i++) if (l[i] >= px) return l[i];
    return l[l.length - 1];
  }
  function thumbUrl(a, px) { return a && a.thumb ? a.thumb + '&s=' + thumbW(a.tw, px) : ''; }
  function palHtml(pal, n) { return (pal || []).slice(0, n || 5).map(function (h) { return '<i style="background:' + esc(h) + '"></i>'; }).join(''); }

  // ── envios (no browser: impressão digital, pré-visualização e frames-chave dos vídeos) ──
  var Up = {
    box: null,
    add: function (name) {
      if (!this.box) { this.box = document.createElement('div'); this.box.className = 'cc-up'; this.box.setAttribute('role', 'status'); document.body.appendChild(this.box); }
      var row = document.createElement('div');
      row.innerHTML = '<span></span><i><b></b></i>';
      row.firstChild.textContent = name;
      this.box.appendChild(row);
      var self = this, bar = $('b', row);
      function gone(ms) { setTimeout(function () { row.remove(); if (self.box && !self.box.children.length) { self.box.remove(); self.box = null; } }, ms); }
      return {
        set: function (p) { bar.style.width = Math.round(clamp(p, 0, 1) * 100) + '%'; },
        done: function (msg) { bar.style.width = '100%'; if (msg) row.firstChild.textContent = name + ' · ' + msg; gone(900); },
        fail: function (msg) { row.className = 'err'; row.firstChild.textContent = name + ' · ' + msg; gone(6000); }
      };
    }
  };
  function rnd40() { var s = ''; for (var i = 0; i < 40; i++) s += '0123456789abcdef'[Math.floor(Math.random() * 16)]; return s; }
  function fingerprint(file) {
    if (!window.crypto || !crypto.subtle) return Promise.resolve(rnd40());
    var MB = 4194304, n = file.size;
    var parts = [file.slice(0, MB)];
    if (n > MB) parts.push(file.slice(Math.max(MB, n - MB)));
    parts.push(new Blob([String(n)]));
    return new Blob(parts).arrayBuffer().then(function (buf) { return crypto.subtle.digest('SHA-1', buf); }).then(function (h) {
      return Array.prototype.map.call(new Uint8Array(h), function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
    });
  }
  function canvasBlob(cv, q) {
    return new Promise(function (res) {
      cv.toBlob(function (b) { if (b && b.type === 'image/webp') return res(b); cv.toBlob(res, 'image/jpeg', q); }, 'image/webp', q);
    });
  }
  function imagePreview(file) {
    if (!window.createImageBitmap) return Promise.resolve(null);
    return createImageBitmap(file).then(function (bmp) {
      var w = bmp.width, h = bmp.height, s = Math.min(1, 1600 / w);
      var cv = document.createElement('canvas');
      cv.width = Math.max(1, Math.round(w * s)); cv.height = Math.max(1, Math.round(h * s));
      cv.getContext('2d').drawImage(bmp, 0, 0, cv.width, cv.height);
      if (bmp.close) bmp.close();
      return canvasBlob(cv, 0.86).then(function (b) { return { prev: b, w: w, h: h }; });
    }).catch(function () { return null; });
  }
  /** Frames-chave por deteção de corte: amostra o vídeo, compara histogramas e guarda 6–12 frames (1280 px). */
  function videoFrames(file, onp) {
    var url = URL.createObjectURL(file);
    var v = document.createElement('video');
    v.muted = true; v.playsInline = true; v.preload = 'auto'; v.src = url;
    function seek(t) { return new Promise(function (res) { var on = function () { v.removeEventListener('seeked', on); res(); }; v.addEventListener('seeked', on); v.currentTime = t; }); }
    function cleanup() { URL.revokeObjectURL(url); v.removeAttribute('src'); try { v.load(); } catch (e) {} }
    return new Promise(function (res, rej) {
      v.onloadeddata = res; v.onerror = function () { rej(new Error('formato')); };
      setTimeout(function () { rej(new Error('tempo')); }, 20000);
    }).then(async function () {
      var dur = v.duration, W = v.videoWidth, H = v.videoHeight;
      if (!isFinite(dur) || !dur || !W) throw new Error('sem imagem');
      var sm = document.createElement('canvas'); sm.width = 32; sm.height = 18;
      var sc = sm.getContext('2d', { willReadFrequently: true });
      var N = Math.min(120, Math.max(10, Math.floor(dur * 2))), step = dur / N, prev = null, diffs = [];
      for (var i = 0; i < N; i++) {
        var t = Math.min(dur - 0.05, i * step + step / 2);
        await seek(t);
        sc.drawImage(v, 0, 0, 32, 18);
        var d = sc.getImageData(0, 0, 32, 18).data, hist = new Float32Array(48);
        for (var p = 0; p < d.length; p += 4) { hist[d[p] >> 4]++; hist[16 + (d[p + 1] >> 4)]++; hist[32 + (d[p + 2] >> 4)]++; }
        var diff = 0;
        if (prev) for (var k = 0; k < 48; k++) diff += Math.abs(hist[k] - prev[k]);
        diffs.push({ t: t, d: diff });
        prev = hist;
        if (onp) onp(i / N * 0.6);
      }
      var vals = diffs.slice(1).map(function (x) { return x.d; });
      var mean = vals.reduce(function (a, b) { return a + b; }, 0) / Math.max(1, vals.length);
      var sd = Math.sqrt(vals.reduce(function (a, b) { return a + (b - mean) * (b - mean); }, 0) / Math.max(1, vals.length));
      var cuts = diffs.slice(1).filter(function (x) { return x.d > mean + 1.5 * sd; }).sort(function (a, b) { return b.d - a.d; }).slice(0, 11).map(function (x) { return x.t; });
      var times = [diffs[0].t].concat(cuts);
      for (var j = 1; times.length < 6 && j < 8; j++) {
        var tt = Math.min(dur - 0.1, dur * j / 8);
        if (times.every(function (x) { return Math.abs(x - tt) > step * 1.5; })) times.push(tt);
      }
      times = times.sort(function (a, b) { return a - b; }).slice(0, 12);
      var s = Math.min(1, 1280 / W), cv = document.createElement('canvas');
      cv.width = Math.round(W * s); cv.height = Math.round(H * s);
      var cx = cv.getContext('2d'), frames = [];
      for (var f = 0; f < times.length; f++) {
        await seek(times[f]);
        cx.drawImage(v, 0, 0, cv.width, cv.height);
        frames.push({ t: times[f], blob: await canvasBlob(cv, 0.84) });
        if (onp) onp(0.6 + (f + 1) / times.length * 0.4);
      }
      cleanup();
      return { prev: frames[0].blob, w: W, h: H, dur: dur, frames: frames };
    }).catch(function () { cleanup(); return null; });
  }
  function kindOf(file) {
    if (/^video\//.test(file.type) || /\.(mp4|mov|m4v|webm)$/i.test(file.name)) return 'video';
    if (/^image\//.test(file.type) || /\.(jpe?g|png|gif|webp|avif|heic|heif|bmp)$/i.test(file.name)) return 'image';
    return 'file';
  }
  /** Envia um ficheiro. ctx: board, x, y, lane, column, inbox, cw. Devolve { asset, card }. */
  async function uploadFile(file, ctx) {
    ctx = ctx || {};
    var row = Up.add(file.name);
    try {
      var kind = kindOf(file);
      var fp = await fingerprint(file);
      var init = await api('upload_init', { name: file.name, size: file.size, mime: file.type, fp: fp });
      if (init.dup) {
        row.done('já estava na biblioteca');
        var out = { asset: init.dup, card: null };
        if (ctx.board) {
          var a = init.dup, ratio = a.w && a.h ? a.h / a.w : 0.62, w = ctx.cw || 280;
          var r = await api('card_add', { board: ctx.board, type: a.kind === 'file' ? 'file' : a.kind, asset: a.id, x: ctx.x, y: ctx.y, w: w,
                                          h: a.kind === 'file' ? 0 : Math.round(w * Math.min(2.5, ratio)), lane: ctx.lane || '', column: ctx.column || '' });
          out.card = r.card;
        } else if (ctx.inbox) {
          await api('inbox_done', { ids: [init.dup.id], back: 1 });
        }
        return out;
      }
      row.set(0.04);
      var prep = kind === 'image' ? await imagePreview(file) : kind === 'video' ? await videoFrames(file, function (p) { row.set(0.04 + p * 0.36); }) : null;
      var fd = new FormData();
      var f = { name: file.name, size: file.size, mime: file.type, fp: fp, kind: kind, w: prep ? prep.w : '', h: prep ? prep.h : '', dur: prep && prep.dur ? prep.dur.toFixed(3) : '',
                board: ctx.board || '', x: ctx.x == null ? '' : Math.round(ctx.x), y: ctx.y == null ? '' : Math.round(ctx.y), lane: ctx.lane || '',
                column: ctx.column || '', inbox: ctx.inbox ? 1 : '', cw: ctx.cw || '', a: 'upload_done' };
      Object.keys(f).forEach(function (k) { fd.append(k, f[k]); });
      if (prep && prep.prev) fd.append('prev', prep.prev, 'prev');
      if (prep && prep.frames) prep.frames.forEach(function (fr) { fd.append('frames[]', fr.blob, 'f.jpg'); fd.append('t[]', fr.t.toFixed(3)); });
      if (init.mode === 'r2') {
        await xhr('PUT', init.put, file, file.type ? { 'Content-Type': file.type } : {}, function (p) { row.set(0.4 + p * 0.5); });
        fd.append('key', init.key);
      } else {
        fd.append('orig', file, file.name);
      }
      var txt = await xhr('POST', API + '?a=upload_done', fd, { 'Accept': 'application/json', 'X-CSRF-Token': CSRF }, init.mode === 'r2' ? null : function (p) { row.set(0.4 + p * 0.55); })
        .catch(function (e) { throw e; });
      var j = JSON.parse(txt || '{}');
      if (!j.ok) throw new Error(j.error || 'Não foi possível guardar.');
      row.done();
      kickWorker();
      return j;
    } catch (e) {
      row.fail(e.message || 'falhou');
      throw e;
    }
  }

  // ── a fila da IA: o browser vai pedindo enquanto houver trabalho ──
  var working = false, jobFns = [];
  function onJobs(fn) { jobFns.push(fn); }
  function jobsChanged() { jobFns.forEach(function (fn) { try { fn(); } catch (e) {} }); }
  function kickWorker() {
    if (working || !EDIT) return;
    working = true;
    (async function () {
      try {
        for (var i = 0; i < 400; i++) {
          var r = await api('work', {});
          jobsChanged();
          if (!r.pending) break;
          if (!r.done) await new Promise(function (res) { setTimeout(res, 4000); });
        }
      } catch (e) { /* a fila continua na próxima visita */ }
      working = false;
      jobsChanged();
    })();
  }
  /** Atualiza o estado da IA das referências que estão à espera (por id). */
  function refreshAssets(ids, apply) {
    ids = ids.filter(Boolean);
    if (!ids.length) return Promise.resolve();
    return api('jobs', { ids: ids.slice(0, 300).join(',') }, 'GET').then(function (j) { j.items.forEach(apply); }).catch(function () {});
  }
  function aiBadge(a) {
    if (!a) return '';
    if (a.ai === 'queued' || a.ai === 'running') return '<span class="cc-badge ai">IA…</span>';
    if (a.ai === 'error') return '<span class="cc-badge err" title="A análise falhou">IA ✕</span>';
    return '';
  }

  // ── detalhe de uma referência (gaveta na Biblioteca, painel no quadro) ──
  function hostOfUrl(u) { try { return new URL(u).hostname.replace(/^www\./, ''); } catch (e) { return u; } }
  function assetDetailHtml(a) {
    var media;
    if (a.kind === 'video' && a.orig) media = '<video controls playsinline preload="metadata" src="' + esc(a.orig) + '" poster="' + esc(thumbUrl(a, 768)) + '"></video>';
    else if (a.thumb) media = '<img src="' + esc(thumbUrl(a, 1600)) + '" alt="' + esc(a.title) + '">';
    else media = '<div class="empty">' + ico('file') + ' ' + esc(a.file || a.title) + '</div>';
    var h = '<div class="ccd-media">' + media + '</div>';
    if (a.frames && a.frames.length) {
      h += '<div><h4>Frames-chave</h4><div class="ccd-frames">' + a.frames.map(function (f) {
        return '<button type="button" data-seek="' + f.t + '" title="' + esc(f.caption || '') + '"><img src="' + esc(f.thumb) + '" alt="" loading="lazy"><span>' + fmtDur(f.t) + '</span></button>';
      }).join('') + '</div></div>';
    }
    var links = [];
    if (a.url) links.push('<a class="lnk" href="' + esc(a.url) + '" target="_blank" rel="noopener noreferrer">' + ico('ext') + ' Abrir o link</a>');
    if (a.orig && a.kind !== 'video') links.push('<a class="lnk" href="' + esc(a.orig) + '" target="_blank" rel="noopener">' + ico('ext') + ' Original</a>');
    if (a.drive) links.push('<a class="lnk" href="' + esc(a.drive) + '" data-tip="' + esc(a.drive_path) + '">' + ico('drive') + ' Mostrar no Drive</a>');
    if (links.length) h += '<div>' + links.join(' ') + '</div>';
    if (a.palette && a.palette.length) {
      h += '<div><h4>Paleta</h4><div class="ccd-pal">' + a.palette.map(function (c) { return '<button type="button" data-hex="' + esc(c.hex) + '" title="Procurar por esta cor"><i style="background:' + esc(c.hex) + '"></i>' + esc(c.hex) + '</button>'; }).join('') + '</div></div>';
    }
    var an = a.analysis || {};
    var st = a.ai === 'queued' || a.ai === 'running' ? '<p class="muted small"><span class="spin"></span> A IA está a analisar…</p>'
           : a.ai === 'error' ? '<p class="small" style="color:var(--bad-fg)">A análise falhou: ' + esc(a.ai_error) + '</p>'
           : a.ai === 'off' ? '<p class="muted small">' + esc(a.ai_error || 'A IA não está ligada.') + '</p>' : '';
    h += '<div class="ccd-an"><h4>Análise' + (a.ai_model ? ' · ' + esc(a.ai_model) : '') + (a.edited ? ' · editada ' + esc(a.edited) : '') + '</h4>' + st;
    if (an.why) h += '<div class="ccd-why"><b>Porque funciona.</b> ' + esc(an.why) + '</div>';
    if (an.confidence != null && an.confidence !== '') h += '<div class="ccd-conf">Confiança<i><b style="width:' + Math.round(an.confidence * 100) + '%"></b></i>' + Math.round(an.confidence * 100) + '%</div>';
    var ro = EDIT ? '' : ' readonly';
    h += '<label><span>Título</span><input name="title" value="' + esc(a.title) + '"' + ro + '></label>'
      + '<label><span>Legenda</span><textarea name="caption"' + ro + '>' + esc(an.caption || '') + '</textarea></label>'
      + '<div class="row">' + (a.vocab || []).map(function (v) {
          var cur = (a.facets || {})[v.key] || '';
          return '<label><span>' + esc(v.label) + '</span><select name="' + v.key + '"' + (EDIT ? '' : ' disabled') + '><option value="">—</option>'
            + v.values.map(function (x) { return '<option' + (x === cur ? ' selected' : '') + '>' + esc(x) + '</option>'; }).join('') + '</select></label>';
        }).join('') + '<label><span>Origem</span><select name="origin"' + (EDIT ? '' : ' disabled') + '><option value="external"' + (a.origin !== 'topper' ? ' selected' : '') + '>Externa</option><option value="topper"' + (a.origin === 'topper' ? ' selected' : '') + '>topper.</option></select></label></div>'
      + '<label><span>Etiquetas (separadas por vírgulas)</span><input name="tags" value="' + esc((a.tags || []).join(', ')) + '"' + ro + '></label>'
      + '<label><span>Porque funciona</span><textarea name="why"' + ro + '>' + esc(an.why || '') + '</textarea></label>'
      + '<div class="row"><label><span>Composição</span><textarea name="composition"' + ro + '>' + esc(an.composition || '') + '</textarea></label>'
      + '<label><span>Cor</span><textarea name="color"' + ro + '>' + esc(an.color || '') + '</textarea></label></div>'
      + (a.kind === 'video' ? '<label><span>Movimento</span><textarea name="movement"' + ro + '>' + esc(an.movement || '') + '</textarea></label>' : '')
      + '</div>';
    if (a.deal) h += '<p class="small muted">Projeto topper.: ' + esc(a.deal.code + ' · ' + a.deal.name) + (a.deal.disclose ? ' · Pode ser divulgado? ' + esc(a.deal.disclose) : '') + '</p>';
    if (a.cap) {   // guardado de fora (extensão, Partilhar no Android, Atalho do iOS)
      var cp = a.cap, via = { extension: 'com a extensão', android: 'pelo Partilhar (Android)', ios: 'pelo Atalho (iOS)', web: 'na plataforma' }[cp.via] || '';
      h += '<div class="ccd-cap"><h4>Guardado de fora</h4><p class="small">' + esc(via ? 'Guardado ' + via : 'Guardado') + (cp.at ? ' · ' + esc(String(cp.at).slice(8, 10) + '/' + String(cp.at).slice(5, 7)) : '') + '</p>'
        + (cp.page ? '<p class="small"><a href="' + esc(cp.page) + '" target="_blank" rel="noopener">' + esc(hostOfUrl(cp.page)) + (cp.t ? ' · ' + fmtDur(cp.t) : '') + '</a>' + (cp.author ? ' · ' + esc(cp.author) : '') + '</p>' : (cp.author ? '<p class="small">' + esc(cp.author) + '</p>' : ''))
        + (cp.quote ? '<blockquote class="small">' + esc(cp.quote) + '</blockquote>' : '') + (cp.note ? '<p class="small"><b>Nota:</b> ' + esc(cp.note) + '</p>' : '') + '</div>';
    }
    if (a.boards && a.boards.length) h += '<div><h4>Nos quadros</h4><div class="ccd-boards">' + a.boards.map(function (b) { return '<a class="chip" href="/admin/criativo.php?b=' + b.id + '">' + esc(b.title) + '</a>'; }).join('') + '</div></div>';
    h += '<div class="actions">' + (EDIT ? '<button type="button" class="btn sm" data-d="save">Guardar</button><button type="button" class="btn ghost sm" data-d="analyze">' + ico('spark') + ' Analisar outra vez</button>' : '')
      + '<button type="button" class="btn ghost sm" data-d="similar">Mais como este</button>'
      + (EDIT ? '<button type="button" class="lnk del" data-d="delete">' + ico('trash') + ' Apagar da biblioteca</button>' : '') + '</div><div data-d-sim></div>';
    return h;
  }
  /** Liga os botões do detalhe. o: onColor(hex), onAdd(asset), onChange(asset), onDelete(id), onOpen(id). */
  function bindAssetDetail(box, a, o) {
    o = o || {};
    box.addEventListener('click', function (e) {
      var s = e.target.closest('[data-seek]');
      if (s) { var v = $('video', box); if (v) { v.currentTime = +s.getAttribute('data-seek'); v.play().catch(function () {}); } return; }
      var hx = e.target.closest('[data-hex]');
      if (hx && o.onColor) { o.onColor(hx.getAttribute('data-hex')); return; }
      var b = e.target.closest('[data-d]');
      if (!b) return;
      var act = b.getAttribute('data-d');
      if (act === 'save') {
        var data = { id: a.id };
        $$('[name]', box).forEach(function (el) { if (el.closest('.ccd-an')) data[el.name] = el.value; });
        b.disabled = true;
        api('asset_save', data).then(function (j) { toast('Guardado.'); if (o.onChange) o.onChange(j.asset); }).catch(fail).then(function () { b.disabled = false; });
      } else if (act === 'analyze') {
        api('analyze', { id: a.id }).then(function (j) { toast(j.msg); kickWorker(); if (o.onChange) o.onChange(Object.assign({}, a, { ai: 'queued' })); }).catch(fail);
      } else if (act === 'similar') {
        var sim = $('[data-d-sim]', box);
        sim.innerHTML = '<p class="muted small"><span class="spin"></span> A procurar…</p>';
        api('similar', { id: a.id }, 'GET').then(function (j) {
          sim.innerHTML = '<h4>Mais como este <small class="muted">(por ' + esc(j.by) + ')</small></h4>' + (j.items.length ? refsHtml(j.items) : '<p class="muted small">Nada parecido ainda.</p>');
        }).catch(function (e2) { sim.innerHTML = ''; fail(e2); });
      } else if (act === 'delete') {
        api('asset_delete', { id: a.id }).then(function (j) {
          toast(j.msg, { undo: function () { api('asset_restore', { id: a.id }).then(function () { toast('Reposta.'); if (o.onRestore) o.onRestore(a.id); }).catch(fail); } });
          if (o.onDelete) o.onDelete(a.id);
        }).catch(fail);
      }
    });
    box.addEventListener('click', function (e) {
      var r = e.target.closest('.cc-refs [data-aid]');
      if (!r) return;
      if (o.onAdd) o.onAdd(+r.getAttribute('data-aid'), r); else if (o.onOpen) o.onOpen(+r.getAttribute('data-aid'));
    });
  }
  function refsHtml(items) {
    return '<div class="cc-refs">' + items.map(function (a) {
      return '<button type="button" draggable="true" data-aid="' + a.id + '" title="' + esc(a.caption || a.title) + '">'
        + (a.thumb ? '<img src="' + esc(thumbUrl(a, 256)) + '" alt="" loading="lazy">' : ico('file')) + '</button>';
    }).join('') + '</div>';
  }
  document.addEventListener('dragstart', function (e) {
    var r = e.target.closest && e.target.closest('.cc-refs [data-aid], .ccl-tile[data-id]');
    if (!r || !e.dataTransfer) return;
    e.dataTransfer.setData('text/x-cc-asset', r.getAttribute('data-aid') || r.getAttribute('data-id'));
    e.dataTransfer.effectAllowed = 'copy';
  });

  // ══ Projetos ═══════════════════════════════════════════════
  function viewProjects() {
    var show = 'active', q = '', timer;
    root.innerHTML = '<div class="ccp-bar"><input type="search" placeholder="Procurar quadro, ID ou negócio" aria-label="Procurar quadros" data-q>'
      + '<div class="views" role="group" aria-label="Estado"><button type="button" class="on" data-show="active">Ativos</button><button type="button" data-show="archived">Arquivados</button></div></div><div data-list><div class="ccb-loading"><span class="spin"></span> A carregar…</div></div>';
    var list = $('[data-list]', root);
    function tile(b) {
      var kind = { precreative: 'Pré-criativo', project: 'Projeto', free: 'Quadro livre', front: 'Frente', stage: 'Etapa', route: 'Rota' }[b.kind] || 'Quadro';
      var cov = b.covers.length ? '<div class="ccp-cover' + (b.covers.length === 1 ? ' n1' : '') + '">' + b.covers.slice(0, 3).map(function (u) { return '<img src="' + esc(u) + '" alt="" loading="lazy">'; }).join('') + '</div>'
              : '<div class="ccp-cover"><span class="ph">' + ico(b.kind === 'precreative' ? 'spark' : 'board') + '</span></div>';
      var chips = [];
      if (b.kind === 'precreative') {
        ['A', 'B'].forEach(function (l) { if (b.pages[l]) chips.push('<span class="chip ' + (b.pages[l] === 'sent' ? 'ok' : 'info') + '">' + l + ': ' + (b.pages[l] === 'sent' ? 'na proposta' : 'página feita') + '</span>'); });
        if (!chips.length && b.ai === 'prepared') chips.push('<span class="chip wait">Preparado com IA</span>');
      }
      if (b.why) chips.push('<span class="chip">' + ({ lost: 'Negócio perdido', kickoff: 'Passou ao projeto', manual: 'Arquivado' }[b.why] || 'Arquivado') + '</span>');
      return '<a class="ccp-tile" href="/admin/criativo.php?b=' + b.id + '">' + cov + '<div class="ccp-body"><span class="ccp-kind">' + kind + '</span><b>' + esc(b.title) + '</b>'
        + '<small>' + (b.code ? '<span class="mono">' + esc(b.code) + '</span>' : '') + (b.exec ? '<span>' + esc(b.exec) + '</span>' : '') + '<span>' + b.n + ' cartões</span><span>' + esc(b.updated) + '</span></small>'
        + (chips.length ? '<div class="ccp-chips">' + chips.join('') + '</div>' : '') + '</div></a>';
    }
    function sec(title, items, empty) {
      return '<section class="ccp-sec"><h2>' + esc(title) + ' <small>' + items.length + '</small></h2>'
        + (items.length ? '<div class="ccp-grid">' + items.map(tile).join('') + '</div>' : '<p class="muted small">' + esc(empty) + '</p>') + '</section>';
    }
    function load() {
      api('boards', { show: show, q: q }, 'GET').then(function (j) {
        var by = function (k) { return j.boards.filter(function (b) { return k.indexOf(b.kind) >= 0; }); };
        var h = '';
        if (j.missing.length && EDIT) {
          h += '<section class="ccp-sec"><h2>Negócios sem quadro <small>' + j.missing.length + '</small></h2><ul class="ccp-missing">' + j.missing.map(function (d) {
            return '<li><span><span class="mono">' + esc(d.code) + '</span> · ' + esc(d.name) + '</span><button type="button" class="btn sm ghost" data-mk="' + d.id + '" data-kind="' + d.kind + '">'
              + (d.kind === 'project' ? 'Criar quadro do projeto' : 'Criar pré-criativo') + '</button></li>';
          }).join('') + '</ul></section>';
        }
        h += sec('Pré-criativo', by(['precreative']), show === 'active' ? 'O pré-criativo nasce sozinho quando um X-Ray é validado.' : 'Nada arquivado.');
        h += sec('Projetos', by(['project']), show === 'active' ? 'O quadro do projeto nasce no Kickoff.' : 'Nada arquivado.');
        h += sec('Quadros livres', by(['free', 'front', 'stage', 'route']), 'Moodboards e ideias soltas: «+ Quadro».');
        list.innerHTML = h;
      }).catch(function (e) { list.innerHTML = '<p class="muted">' + esc(e.message) + '</p>'; });
    }
    root.addEventListener('input', function (e) { if (e.target.matches('[data-q]')) { clearTimeout(timer); timer = setTimeout(function () { q = e.target.value.trim(); load(); }, 250); } });
    root.addEventListener('click', function (e) {
      var s = e.target.closest('[data-show]');
      if (s) { show = s.getAttribute('data-show'); $$('[data-show]', root).forEach(function (x) { x.classList.toggle('on', x === s); }); load(); return; }
      var mk = e.target.closest('[data-mk]');
      if (mk) {
        mk.disabled = true;
        api('project_create', { deal: mk.getAttribute('data-mk'), kind: mk.getAttribute('data-kind') }).then(function (j) { location.href = '/admin/criativo.php?b=' + j.id; }).catch(function (er) { mk.disabled = false; fail(er); });
      }
    });
    load();
  }

  // novo quadro (com ligação opcional a um negócio)
  function bindNewBoard() {
    var btn = $('[data-cc-new-board]'), m = $('#mdl-cc-board');
    if (!btn || !m) return;
    var form = $('form', m), dq = $('[data-cc-deal-q]', m), res = $('[data-cc-deal-res]', m), t;
    btn.addEventListener('click', function () { if (T.openMdl) T.openMdl(m, btn); });
    dq.addEventListener('input', function () {
      clearTimeout(t);
      form.deal.value = '';
      var q = dq.value.trim();
      if (q.length < 2) { res.innerHTML = ''; return; }
      t = setTimeout(function () {
        api('deals', { q: q }, 'GET').then(function (j) {
          res.innerHTML = j.deals.map(function (d) { return '<button type="button" class="chip" data-deal="' + d.id + '">' + esc(d.label) + '</button>'; }).join(' ') || '<small class="muted">Nenhum negócio.</small>';
        }).catch(function () {});
      }, 220);
    });
    res.addEventListener('click', function (e) {
      var b = e.target.closest('[data-deal]');
      if (!b) return;
      form.deal.value = b.getAttribute('data-deal');
      dq.value = b.textContent;
      res.innerHTML = '';
    });
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      api('board_new', { title: form.title.value, deal: form.deal.value }).then(function (j) { location.href = '/admin/criativo.php?b=' + j.id; }).catch(fail);
    });
  }

  // importação (mecanismo pronto)
  function bindImport() {
    var btn = $('[data-cc-import]'), m = $('#mdl-cc-import');
    if (!btn || !m) return;
    var form = $('form', m);
    btn.addEventListener('click', function () { if (T.openMdl) T.openMdl(m, btn); });
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var fd = new FormData(form), sb = $('[type=submit]', form);
      sb.disabled = true; sb.textContent = 'A importar…';
      api('import', fd).then(function (j) {
        toast(j.msg);
        kickWorker();
        setTimeout(function () { location.href = '/admin/criativo.php?b=' + j.board; }, 600);
      }).catch(function (er) { fail(er); sb.disabled = false; sb.textContent = 'Importar'; });
    });
  }

  // ══ Biblioteca e Caixa de entrada ══════════════════════════
  var COLORS = ['#111111', '#F4F1EA', '#9E9E9E', '#C62828', '#F4511E', '#FFC718', '#7CB342', '#00897B', '#81CFE3', '#1E63B5', '#5E35B1', '#D81B60', '#795548'];
  function viewLibrary(inbox) {
    var f = { q: '', color: '', origin: '', kind: '' }, offset = 0, total = 0, loading = false, items = new Map(), sel = new Set(), boards = null, facetsBuilt = false, timer;
    root.innerHTML = '<div class="ccl"><aside class="ccl-f" aria-label="Filtros"><div><input type="search" placeholder="Procurar (texto, etiqueta, técnica…)" aria-label="Procurar referências" data-q></div>'
      + '<div><h4>Cor</h4><div class="ccl-sw" data-colors>' + COLORS.map(function (c) { return '<button type="button" style="background:' + c + '" data-color="' + c + '" aria-label="Cor ' + c + '" title="' + c + '"></button>'; }).join('')
      + '<input type="color" value="#FFC718" aria-label="Outra cor" data-color-in title="Outra cor"></div></div>'
      + '<div data-facets></div>'
      + '<div><h4>Origem</h4><div class="ccl-chips" data-g="origin"><button type="button" data-v="external">Externas</button><button type="button" data-v="topper">topper.</button></div></div>'
      + '<div><h4>Tipo</h4><div class="ccl-chips" data-g="kind"><button type="button" data-v="image">Imagens</button><button type="button" data-v="video">Vídeos</button><button type="button" data-v="link">Links</button><button type="button" data-v="file">Ficheiros</button></div></div>'
      + (MANAGE ? '<div><button type="button" class="lnk" data-analyze-all>' + ico('spark') + ' Analisar o que falta</button></div>' : '')
      + '</aside><section><div class="ccl-head"><span data-count>—</span>'
      + (inbox ? '<span>Tudo o que se captura entra aqui, já analisado pela IA. Arraste para um quadro ou selecione.</span>' : '<span>Cole (Ctrl V) ou arraste imagens, vídeos e links para aqui.</span>')
      + (EDIT ? '<label class="btn sm ccl-capture">' + ico('upload') + ' Capturar<input type="file" multiple accept="image/*,video/*" capture="environment" data-cc-upload hidden></label>' : '')
      + '</div><div class="ccl-grid" data-grid></div><div class="ccl-more" data-more></div><div data-act></div></section></div>';
    var grid = $('[data-grid]', root), more = $('[data-more]', root), count = $('[data-count]', root), act = $('[data-act]', root);

    function tile(a) {
      var ratio = a.w && a.h ? a.w + '/' + a.h : '4/3';
      var b = '<span class="badges"><span>' + (a.kind === 'video' ? '<span class="cc-badge">▶ ' + fmtDur(a.dur) + '</span>' : a.kind === 'link' ? '<span class="cc-badge">Link</span>' : '')
        + (a.origin === 'topper' ? ' <span class="cc-badge y">topper.</span>' : '') + (a.de != null ? ' <span class="cc-badge">ΔE ' + String(a.de).replace('.', ',') + '</span>' : '') + '</span>' + aiBadge(a) + '</span>';
      var img = a.thumb ? '<img src="' + esc(thumbUrl(a, 256)) + '" srcset="' + esc(a.thumb + '&s=' + thumbW(a.tw, 256)) + ' 256w, ' + esc(a.thumb + '&s=' + thumbW(a.tw, 768)) + ' 768w" sizes="(max-width: 860px) 50vw, 240px" alt="' + esc(a.caption || a.title) + '" loading="lazy" decoding="async" style="aspect-ratio:' + ratio + '">'
                        : '<span class="nf">' + ico('file') + '</span>';
      return '<div class="ccl-tile' + (sel.has(a.id) ? ' sel' : '') + '" role="button" tabindex="0" draggable="true" data-id="' + a.id + '">' + img
        + (a.pal && a.pal.length ? '<span class="pal">' + palHtml(a.pal, 6) + '</span>' : '')
        + '<span class="cap"><b>' + esc(a.title || a.file || 'Sem título') + '</b>' + esc((a.caption || '').slice(0, 110)) + '</span>' + b
        + (inbox && EDIT ? '<span class="ck" data-ck role="checkbox" aria-checked="' + (sel.has(a.id) ? 'true' : 'false') + '" aria-label="Selecionar" tabindex="-1">' + (sel.has(a.id) ? ico('check') : '') + '</span>' : '') + '</div>';
    }
    function load(reset) {
      if (loading) return;
      if (reset) { offset = 0; items.clear(); grid.innerHTML = ''; }
      loading = true;
      more.innerHTML = '<span class="spin"></span>';
      var q = Object.assign({}, f, { inbox: inbox ? 1 : '', offset: offset });
      api('library', q, 'GET').then(function (j) {
        total = j.total;
        if (j.facets && !facetsBuilt) buildFacets(j.vocab, j.facets);
        var h = '';
        j.items.forEach(function (a) { items.set(a.id, a); h += tile(a); });
        grid.insertAdjacentHTML('beforeend', h);
        offset += j.items.length;
        count.textContent = total === 1 ? '1 referência' : total + ' referências';
        more.textContent = items.size < total ? '' : (total ? '' : (inbox ? 'A caixa de entrada está vazia.' : 'Nada encontrado.'));
        if (j.items.some(function (a) { return a.ai === 'queued' || a.ai === 'running'; })) kickWorker();
      }).catch(function (e) { more.textContent = e.message; }).then(function () { loading = false; });
    }
    function buildFacets(vocab, facets) {
      facetsBuilt = true;
      $('[data-facets]', root).innerHTML = vocab.map(function (v) {
        var counts = {};
        (facets[v.key] || []).forEach(function (x) { counts[x.v] = x.n; });
        var vals = v.values.filter(function (x) { return counts[x]; });
        if (!vals.length) return '';
        return '<div><h4>' + esc(v.label) + '</h4><div class="ccl-chips" data-g="' + v.key + '">' + vals.map(function (x) { return '<button type="button" data-v="' + esc(x) + '">' + esc(x) + '<em>' + counts[x] + '</em></button>'; }).join('') + '</div></div>';
      }).join('');
    }
    function refreshTile(a) {
      var old = items.get(a.id);
      if (!old) return;
      items.set(a.id, Object.assign(old, a));
      var el = $('.ccl-tile[data-id="' + a.id + '"]', grid);
      if (el) el.outerHTML = tile(items.get(a.id));
    }
    onJobs(function () {
      var wait = [];
      items.forEach(function (a) { if (a.ai === 'queued' || a.ai === 'running') wait.push(a.id); });
      refreshAssets(wait, refreshTile);
    });
    if ('IntersectionObserver' in window) new IntersectionObserver(function (en) { if (en[0].isIntersecting && items.size < total) load(false); }, { rootMargin: '800px' }).observe(more);

    function renderAct() {
      if (!inbox || !sel.size) { act.innerHTML = ''; return; }
      var opts = (boards || []).map(function (b) { return '<option value="' + b.id + '">' + esc(b.title) + '</option>'; }).join('');
      act.innerHTML = '<div class="ccl-act"><b>' + sel.size + ' selecionada' + (sel.size > 1 ? 's' : '') + '</b>'
        + '<select class="mini" data-board-pick aria-label="Quadro"><option value="">Enviar para o quadro…</option>' + opts + '</select>'
        + '<button type="button" class="btn sm" data-send>Enviar</button><button type="button" class="btn ghost sm" data-arch>Arrumar na biblioteca</button><button type="button" class="lnk" data-clear>Limpar</button></div>';
    }
    function ensureBoards() {
      if (boards) return Promise.resolve(boards);
      return api('boards', { show: 'active' }, 'GET').then(function (j) { boards = j.boards; return boards; });
    }
    function toggleSel(id) {
      if (sel.has(id)) sel.delete(id); else sel.add(id);
      var el = $('.ccl-tile[data-id="' + id + '"]', grid);
      if (el) el.outerHTML = tile(items.get(id));
      ensureBoards().then(renderAct);
      renderAct();
    }
    function triage(ids, board) {
      api('inbox_done', { ids: ids, board: board || '' }).then(function (j) {
        toast(j.msg, { undo: function () { api('inbox_done', { ids: ids, back: 1 }).then(function () { load(true); }).catch(fail); } });
        ids.forEach(function (id) { sel.delete(id); var el = $('.ccl-tile[data-id="' + id + '"]', grid); if (el) el.remove(); items.delete(id); });
        renderAct();
      }).catch(fail);
    }

    root.addEventListener('input', function (e) {
      if (e.target.matches('[data-q]')) { clearTimeout(timer); timer = setTimeout(function () { f.q = e.target.value.trim(); load(true); }, 260); }
      if (e.target.matches('[data-color-in]')) { clearTimeout(timer); timer = setTimeout(function () { setColor(e.target.value); }, 200); }
    });
    function setColor(c) {
      f.color = f.color === c ? '' : c;
      $$('[data-color]', root).forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-color') === f.color); });
      load(true);
    }
    root.addEventListener('click', function (e) {
      var c = e.target.closest('[data-color]');
      if (c) { setColor(c.getAttribute('data-color')); return; }
      var ch = e.target.closest('.ccl-chips button');
      if (ch) {
        var g = ch.parentNode.getAttribute('data-g'), v = ch.getAttribute('data-v');
        f[g] = f[g] === v ? '' : v;
        $$('button', ch.parentNode).forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-v') === f[g]); });
        load(true);
        return;
      }
      if (e.target.closest('[data-analyze-all]')) { api('analyze_all', {}).then(function (j) { toast(j.msg); kickWorker(); load(true); }).catch(fail); return; }
      if (e.target.closest('[data-clear]')) { sel.clear(); $$('.ccl-tile.sel', grid).forEach(function (el) { el.outerHTML = tile(items.get(+el.getAttribute('data-id'))); }); renderAct(); return; }
      if (e.target.closest('[data-send]')) { var bid = $('[data-board-pick]', root).value; if (!bid) { toast('Escolha o quadro.', { err: true }); return; } triage(Array.from(sel), bid); return; }
      if (e.target.closest('[data-arch]')) { triage(Array.from(sel), ''); return; }
      var t = e.target.closest('.ccl-tile');
      if (!t) return;
      var id = +t.getAttribute('data-id');
      if (inbox && EDIT && (e.target.closest('[data-ck]') || e.shiftKey || e.metaKey || e.ctrlKey || sel.size)) { toggleSel(id); return; }
      openDetail(id);
    });
    root.addEventListener('keydown', function (e) {
      var t = e.target.closest && e.target.closest('.ccl-tile');
      if (t && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); t.click(); }
    });

    // detalhe (gaveta)
    var drawer = null;
    function closeDetail() { if (drawer) { drawer.remove(); drawer = null; } }
    function openDetail(id) {
      closeDetail();
      drawer = document.createElement('aside');
      drawer.className = 'ccd';
      drawer.setAttribute('aria-label', 'Referência');
      drawer.innerHTML = '<div class="ccd-h"><b>A carregar…</b><button type="button" class="ibtn" data-x aria-label="Fechar (Esc)">' + ico('x') + '</button></div><div class="ccd-b"><span class="spin"></span></div>';
      document.body.appendChild(drawer);
      $('[data-x]', drawer).addEventListener('click', closeDetail);
      api('asset', { id: id }, 'GET').then(function (j) {
        if (!drawer) return;
        var a = j.asset;
        $('.ccd-h b', drawer).textContent = a.title || a.file || 'Referência';
        var body = $('.ccd-b', drawer);
        body.innerHTML = assetDetailHtml(a);
        bindAssetDetail(body, a, {
          onColor: function (hex) { closeDetail(); setColor(hex); },
          onChange: function (na) { refreshTile(na); },
          onDelete: function (aid) { closeDetail(); var el = $('.ccl-tile[data-id="' + aid + '"]', grid); if (el) el.remove(); items.delete(aid); },
          onRestore: function () { load(true); },
          onOpen: function (aid) { openDetail(aid); }
        });
        $('[data-x]', drawer).focus();
      }).catch(function (e) { fail(e); closeDetail(); });
    }
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && drawer && !(T.topMdl && T.topMdl())) { e.preventDefault(); closeDetail(); } });

    // capturar: carregar, colar, arrastar
    function addFiles(files) {
      Array.from(files).reduce(function (p, file) {
        return p.then(function () { return uploadFile(file, { inbox: inbox }).catch(function () {}); });
      }, Promise.resolve()).then(function () { load(true); });
    }
    function addLink(url) {
      var row = Up.add(url);
      api('link_add', { url: url, inbox: inbox ? 1 : '' }).then(function () { row.done(); kickWorker(); load(true); }).catch(function (e) { row.fail(e.message); });
    }
    $$('[data-cc-upload]').forEach(function (inp) { inp.addEventListener('change', function () { addFiles(inp.files); inp.value = ''; }); });
    var lb = $('[data-cc-link]');
    if (lb) lb.addEventListener('click', function () { ask('Adicionar um link', 'Endereço', { placeholder: 'https://…', type: 'url', ok: 'Adicionar', from: lb }).then(function (v) { if (v) addLink(v); }); });
    if (EDIT) {
      document.addEventListener('paste', function (e) {
        if (typing(document.activeElement) || (T.topMdl && T.topMdl())) return;
        var dt = e.clipboardData;
        if (!dt) return;
        if (dt.files && dt.files.length) { e.preventDefault(); addFiles(dt.files); return; }
        var t = (dt.getData('text/plain') || '').trim();
        if (/^https?:\/\/\S+$/i.test(t)) { e.preventDefault(); addLink(t); }
      });
      var ov = null;
      document.addEventListener('dragover', function (e) {
        if (!e.dataTransfer || e.dataTransfer.types.indexOf('Files') < 0) return;
        e.preventDefault();
        if (!ov) { ov = document.createElement('div'); ov.className = 'ccl-drop'; ov.textContent = inbox ? 'Largar na caixa de entrada' : 'Largar na biblioteca'; document.body.appendChild(ov); }
      });
      document.addEventListener('dragleave', function (e) { if (ov && (e.clientX <= 0 || e.clientY <= 0 || e.clientX >= innerWidth || e.clientY >= innerHeight)) { ov.remove(); ov = null; } });
      document.addEventListener('drop', function (e) {
        if (ov) { ov.remove(); ov = null; }
        if (!e.dataTransfer) return;
        if (e.dataTransfer.files && e.dataTransfer.files.length) { e.preventDefault(); addFiles(e.dataTransfer.files); return; }
        var u = (e.dataTransfer.getData('text/uri-list') || e.dataTransfer.getData('text/plain') || '').trim();
        if (/^https?:\/\/\S+$/i.test(u)) { e.preventDefault(); addLink(u); }
      });
    }
    load(true);
  }

  // ══ O quadro ═══════════════════════════════════════════════
  var GRID = 20, LANE_SPLIT = 1165, LANE_TOP = 100;
  function viewBoard() {
    var B = {
      id: +root.getAttribute('data-board'), data: null, cards: new Map(), els: new Map(), sel: new Set(), view: { x: 60, y: 60, s: 1 },
      tool: 'select', color: '#111111', rev: 0, lanes: false, undo: [], redo: [], editing: null, side: 'ia', arrowFrom: null, loadedAt: 0
    };
    var vp, world, layer, svg, lanesEl, zoomOut, sideEl, sideBody, emptyEl, ptr = { x: 0, y: 0 }, spaceDown = false;
    var RT = null;   // tempo real (cc_rt.js): presença, cursores, alterações dos outros logo

    function load() {
      return api('board', { id: B.id }, 'GET').then(function (j) {
        B.data = j;
        B.rev = j.board.rev;
        B.loadedAt = j.now || Date.now();
        EDIT = !!j.edit;
        B.lanes = j.board.kind === 'precreative' || j.board.kind === 'front';
        B.cards.clear();
        j.cards.forEach(function (c) { B.cards.set(c.id, c); });
        return j;
      });
    }

    // ── moldura ──
    function frame() {
      var j = B.data, b = j.board;
      var crumbs = '<span class="ey">Centro Criativo</span><span>/</span>' + j.path.slice(0, -1).map(function (p) { return '<a href="/admin/criativo.php?b=' + p.id + '">' + esc(p.title) + '</a><span>/</span>'; }).join('')
        + '<b data-title title="' + (EDIT ? 'Duplo clique para mudar o nome' : '') + '">' + esc(b.title) + '</b>'
        + (b.deal ? ' <a class="chip" href="/admin/negocio.php?id=' + b.deal.id + '" title="Abrir o negócio">' + esc(b.deal.code) + (b.deal.client ? ' · ' + esc(b.deal.client) : '') + '</a>' : '')
        + (b.status === 'archived' ? ' <span class="chip">Arquivado</span>' : '');
      var stages = j.stages.length ? '<nav class="ccb-stages" aria-label="Etapas">' + (j.stage_host ? '<a href="/admin/criativo.php?b=' + j.stage_host + '"' + (j.stage_host === b.id ? ' class="on"' : '') + '>Visão geral</a>' : '')
        + j.stages.map(function (s) { return '<a href="/admin/criativo.php?b=' + s.id + '"' + (s.id === b.id ? ' class="on" aria-current="page"' : '') + '>' + esc(s.title) + '</a>'; }).join('') + '</nav>' : '';
      var acts = '';
      if (B.lanes && EDIT) acts += '<button type="button" class="btn sm" data-a="prepare" title="3 ângulos por opção e 6–9 referências por faixa">' + ico('spark') + '<span class="lbl"> Preparar com IA</span></button>'
                                + '<button type="button" class="btn sm dark" data-a="send" title="As páginas entram no PDF e na página do cliente, ao lado de cada opção" aria-label="Enviar para a Orçamentação">' + ico('send') + '<span class="lbl"> Enviar para a Orçamentação</span></button>';
      acts += '<button type="button" class="btn ghost sm" data-a="present" title="Modo apresentação (setas para navegar, Esc para sair)">' + ico('present') + '<span class="lbl"> Apresentar</span></button>'
            + '<button type="button" class="ibtn" data-a="side" aria-label="Painel (IA, detalhe, comentários)" data-tip>' + ico('side') + '</button>'
            + (MANAGE ? '<button type="button" class="ibtn" data-a="more" aria-label="Mais" data-tip>⋯</button>' : '');
      root.innerHTML = '<header class="ccb-top"><nav class="ccb-crumb" aria-label="Caminho">' + crumbs + '</nav>' + stages + '<div class="ccb-acts">' + acts + '</div></header>'
        + (B.lanes ? '<div class="ccb-lanebar" data-lanebar></div>' : '')
        + '<div class="ccb-main">'
        + '<div class="ccb-tools" role="toolbar" aria-label="Ferramentas">'
        + tb('select', 'cursor', 'Selecionar e mover ( V )') + (EDIT ? tb('note', 'note', 'Nota ( N )') + tb('column', 'column', 'Coluna ( C )') + tb('list', 'list', 'Lista ( L )')
          + tb('swatch', 'swatch', 'Amostra de cor') + tb('draw', 'pen', 'Desenhar ( D )', '<i class="sw" style="background:' + B.color + '"></i>') + tb('arrow', 'arrow', 'Seta entre cartões ( A )')
          + '<hr>' + '<label data-tip aria-label="Carregar imagens, vídeos ou ficheiros">' + ico('upload') + '<input type="file" multiple hidden data-up></label>'
          + tb('link', 'link', 'Link (ou cole com Ctrl V)') + tb('board', 'board', 'Sub-quadro') : '')
        + '<hr>' + tb('ai', 'spark', 'IA ( / )') + '</div>'
        + '<div class="ccb-vp" tabindex="0" aria-label="Tela do quadro. Espaço + arrastar para mover; Ctrl + roda para zoom." data-vp>'
        + '<div class="ccb-world"><div data-lanes></div><svg class="ccb-arrows" width="1" height="1"><defs><marker id="cc-ah" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z" fill="currentColor"/></marker></defs><g data-ar></g></svg><div data-layer></div></div>'
        + '<div class="ccb-empty" hidden><div><b>Quadro vazio</b><span>Cole (Ctrl V) ou arraste imagens, vídeos e links. <span class="kbd">N</span> nota · <span class="kbd">C</span> coluna · <span class="kbd">/</span> IA</span></div></div>'
        + '<div class="ccb-hint" aria-hidden="true"><span class="kbd">Espaço</span> mover · <span class="kbd">Ctrl</span>+roda zoom · <span class="kbd">/</span> IA</div>'
        + '<div class="ccb-zoom"><button type="button" data-z="-1" aria-label="Afastar ( - )">' + ico('minus') + '</button><output data-zo>100%</output><button type="button" data-z="1" aria-label="Aproximar ( + )">' + ico('plus') + '</button><button type="button" data-z="0" aria-label="Ver tudo ( 0 )">' + ico('fit') + '</button></div>'
        + '</div>'
        + '<aside class="ccb-side" data-side aria-label="Painel lateral"><div class="ccb-side-h" role="tablist"><button type="button" class="tab" data-tab="ia" role="tab">IA</button><button type="button" class="tab" data-tab="det" role="tab">Detalhe</button><button type="button" class="tab" data-tab="com" role="tab">Comentários</button><button type="button" class="tab" data-tab="hist" role="tab">Histórico</button>'
        + '<button type="button" class="ibtn" data-a="side" aria-label="Fechar o painel">' + ico('x') + '</button></div><div class="ccb-side-b" data-side-b></div></aside>'
        + '</div>';
      vp = $('[data-vp]', root); world = $('.ccb-world', root); layer = $('[data-layer]', root); svg = $('.ccb-arrows', root); lanesEl = $('[data-lanes]', root);
      zoomOut = $('[data-zo]', root); sideEl = $('[data-side]', root); sideBody = $('[data-side-b]', root); emptyEl = $('.ccb-empty', root);
      if (window.innerWidth < 1100) sideEl.hidden = true;
      setTool('select');
      bindBoard();
    }
    function tb(tool, icon, label, extra) { return '<button type="button" data-tool="' + tool + '" aria-label="' + esc(label) + '" data-tip>' + ico(icon) + (extra || '') + '</button>'; }

    function lanebar() {
      var el = $('[data-lanebar]', root);
      if (!el) return;
      var p = B.data.pages || {};
      el.innerHTML = ['A', 'B'].map(function (l) {
        var pg = p[l], st = pg ? (pg.sent ? 'na proposta (' + pg.sent + ')' : 'página de ' + pg.at) : 'sem página';
        return '<span class="cclb"><i style="background:var(--cc-' + l.toLowerCase() + ')"></i><b>' + (l === 'A' ? 'Opção A · Pedido' : 'Opção B · Proposta de valor') + '</b><span class="st">' + esc(st) + '</span>'
          + (EDIT ? '<button type="button" data-page-gen="' + l + '">' + (pg ? 'Gerar outra vez' : 'Gerar página') + '</button>' : '')
          + (pg ? '<button type="button" data-page-view="' + l + '">Ver</button>' : '')
          + (pg && EDIT ? '<button type="button" data-page-both="' + l + '" class="' + (pg.both ? 'on' : '') + '" aria-pressed="' + (pg.both ? 'true' : 'false') + '" title="Esta página liga às duas opções da proposta">Serve as duas</button>' : '') + '</span>';
      }).join('') + '<span class="muted small">Mantenha · tire · troque os cartões; depois «Gerar página» e «Enviar para a Orçamentação».</span>';
    }

    // ── vista (pan/zoom) ──
    var rafView = 0, thumbTimer = 0;
    function applyView() {
      if (rafView) return;
      rafView = requestAnimationFrame(function () {
        rafView = 0;
        var v = B.view;
        world.style.transform = 'translate3d(' + v.x + 'px,' + v.y + 'px,0) scale(' + v.s + ')';
        var g = 24 * v.s;
        while (g < 12) g *= 2;
        vp.style.backgroundSize = g + 'px ' + g + 'px';
        vp.style.backgroundPosition = v.x + 'px ' + v.y + 'px';
        zoomOut.textContent = Math.round(v.s * 100) + '%';
        cull();
        if (RT) RT.view();
        clearTimeout(thumbTimer);
        thumbTimer = setTimeout(updateThumbs, 160);
      });
    }
    function toWorld(sx, sy) { return { x: (sx - B.view.x) / B.view.s, y: (sy - B.view.y) / B.view.s }; }
    function vpPoint(e) { var r = vp.getBoundingClientRect(); return { x: e.clientX - r.left, y: e.clientY - r.top }; }
    function zoomAt(p, factor) {
      var s1 = clamp(B.view.s * factor, 0.08, 4);
      var w = toWorld(p.x, p.y);
      B.view.x = p.x - w.x * s1; B.view.y = p.y - w.y * s1; B.view.s = s1;
      applyView();
    }
    function center() { var r = vp.getBoundingClientRect(); return { x: r.width / 2, y: r.height / 2 }; }
    function animateView(fn) {
      if (reduced.matches) { fn(); applyView(); return; }
      world.classList.add('anim');
      fn(); applyView();
      setTimeout(function () { world.classList.remove('anim'); }, 480);
    }
    function fitTo(box, pad, maxS) {
      var r = vp.getBoundingClientRect();
      pad = pad == null ? 60 : pad;
      var s = Math.min((r.width - pad * 2) / Math.max(1, box.w), (r.height - pad * 2) / Math.max(1, box.h), maxS || 1.2);
      s = clamp(s, 0.08, 4);
      B.view.s = s; B.view.x = (r.width - box.w * s) / 2 - box.x * s; B.view.y = (r.height - box.h * s) / 2 - box.y * s;
    }
    function contentBox() {
      var x1 = Infinity, y1 = Infinity, x2 = -Infinity, y2 = -Infinity;
      B.cards.forEach(function (c) { if (c.col || c.type === 'arrow') return; var b = bbox(c); x1 = Math.min(x1, b.x); y1 = Math.min(y1, b.y); x2 = Math.max(x2, b.x + b.w); y2 = Math.max(y2, b.y + b.h); });
      if (B.lanes) { x1 = Math.min(x1, -40); y1 = Math.min(y1, LANE_TOP - 60); x2 = Math.max(x2, 1000); y2 = Math.max(y2, 2190); }
      if (!isFinite(x1)) return null;
      return { x: x1, y: y1, w: x2 - x1, h: y2 - y1 };
    }
    /** Enquadra a seleção (ou tudo, sem seleção). */
    function fitSel() {
      var ids = Array.from(B.sel), x1 = Infinity, y1 = Infinity, x2 = -Infinity, y2 = -Infinity;
      ids.forEach(function (id) { var c = B.cards.get(id); if (!c) return; if (c.col) c = B.cards.get(c.col) || c; var b = bbox(c); x1 = Math.min(x1, b.x); y1 = Math.min(y1, b.y); x2 = Math.max(x2, b.x + b.w); y2 = Math.max(y2, b.y + b.h); });
      if (!isFinite(x1)) { fitAll(true); return; }
      animateView(function () { fitTo({ x: x1, y: y1, w: x2 - x1, h: y2 - y1 }, 80, 1.5); });
    }
    function fitAll(anim) { var b = contentBox(); if (!b) return; var go = function () { fitTo(b, 50, 1); }; if (anim) animateView(go); else { go(); applyView(); } }

    // ── geometria dos cartões ──
    function cardH(c) {
      if (c.asset && c.asset.w && c.asset.h && (c.type === 'image' || c.type === 'video')) return c.w * c.asset.h / c.asset.w;
      if (c.type === 'drawing') return c.h || 120;
      if (c._mh) return c._mh;
      return c.h || 160;
    }
    function bbox(c) { return { x: c.x, y: c.y, w: c.w, h: cardH(c) }; }

    // ── desenhar os cartões ──
    function cardHtml(c) {
      var a = c.asset, x = c.c || {}, h = '';
      var badges = (c.nc ? '<span class="ccc-nc" title="Comentários">' + c.nc + '</span>' : '')
        + (x.state === 'keep' ? '<span class="ccc-state">Manter</span>' : x.state === 'swap' ? '<span class="ccc-state swap">Trocar</span>' : '')
        + (a ? '<span class="ccc-ai">' + aiBadge(a) + '</span>' : '');
      var hov = '';
      if (EDIT && a && B.lanes && c.lane) hov = '<div class="ccc-hov"><button type="button" data-cact="keep" class="' + (x.state === 'keep' ? 'on' : '') + '" title="Manter">' + ico('check') + '</button><button type="button" data-cact="swap" title="Trocar por outra parecida">' + ico('swap') + '</button><button type="button" data-cact="del" title="Tirar">' + ico('x') + '</button></div>';
      else if (a || c.type === 'note') hov = '<div class="ccc-hov"><button type="button" data-cact="com" title="Comentários">' + ico('bubble') + '</button></div>';
      switch (c.type) {
        case 'image': case 'video': {
          var ratio = a && a.w && a.h ? a.w + '/' + a.h : (c.h ? c.w + '/' + c.h : '4/3');
          h = '<div class="ccc-media" style="aspect-ratio:' + ratio + '">' + (a && a.thumb ? '<img alt="' + esc(a.caption || a.title) + '" decoding="async" draggable="false">' : '<span class="nf">' + ico('file') + '</span>')
            + (c.type === 'video' ? '<span class="ccc-play">' + ico('play') + '</span>' + (a && a.dur ? '<span class="ccc-dur">' + fmtDur(a.dur) + '</span>' : '') : '')
            + (a && a.pal && a.pal.length ? '<span class="ccc-pal">' + palHtml(a.pal, 5) + '</span>' : '')
            + (a && a.tags && a.tags.length ? '<span class="ccc-tags">' + a.tags.slice(0, 3).map(function (t) { return '<span>' + esc(t) + '</span>'; }).join('') + '</span>' : '') + '</div>'
            + (x.caption ? '<div class="ccc-cap">' + esc(x.caption) + '</div>' : '');
          break;
        }
        case 'link':
          h = (a && a.thumb ? '<div class="ccc-media" style="aspect-ratio:' + (a.w && a.h ? a.w + '/' + a.h : '1.91/1') + '"><img alt="" decoding="async" draggable="false"></div>' : '')
            + '<div class="ccc-cap ccc-link"><span class="site">' + esc(a ? (hostOf(a.url)) : '') + '</span><b>' + esc(a ? a.title : 'Link') + '</b>' + esc(a && a.caption ? a.caption.slice(0, 140) : '') + '</div>';
          break;
        case 'note':
          h = '<div class="ccc-text" data-ph="Escreva…">' + esc(x.text || '') + '</div>';
          break;
        case 'list':
          h = '<div class="ccc-lt" data-ph="Lista">' + esc(x.title || 'Lista') + '</div>' + (x.items || []).map(function (it, i) {
            return '<div class="ccc-li' + (it.d ? ' d' : '') + '" data-i="' + i + '"><input type="checkbox"' + (it.d ? ' checked' : '') + (EDIT ? '' : ' disabled') + ' aria-label="Feito"><span>' + esc(it.t) + '</span></div>';
          }).join('') + (EDIT ? '<button type="button" class="ccc-add" data-cact="li-add">+ item</button>' : '');
          break;
        case 'swatch':
          h = '<div class="ccc-sw" style="background:' + esc(x.hex || '#FFC718') + '"></div><div class="ccc-swl"><span>' + esc(x.hex || '') + '</span><span>' + esc(x.name || '') + '</span></div>';
          break;
        case 'column': {
          var kids = childrenOf(c.id);
          h = '<div class="ccc-ch" style="--col:' + esc(x.color || '') + '"><i></i><b>' + esc(x.title || 'Coluna') + '</b><small>' + kids.length + '</small></div><div class="ccc-cb" data-cb></div>';
          break;
        }
        case 'board': {
          var kid = (B.data.children || []).filter(function (k) { return k.id === c.ref; })[0];
          h = '<div class="ccc-bd">' + ico('board') + '</div><div class="ccc-bt"><b>' + esc(x.title || (kid && kid.title) || 'Sub-quadro') + '</b><small>' + (kid ? kid.n + ' cartões' : '') + '</small></div>';
          break;
        }
        case 'drawing': {
          var vw = x.vw || c.w, vh = x.vh || c.h || 100;
          h = '<svg viewBox="0 0 ' + vw + ' ' + vh + '" preserveAspectRatio="none">' + (x.paths || []).map(function (p) { return '<path d="' + pathD(p.pts) + '" stroke="' + esc(p.c) + '" stroke-width="' + p.w + '"/>'; }).join('') + '</svg>';
          break;
        }
        default:
          h = ico('file') + '<div><b>' + esc(a ? (a.file || a.title) : 'Ficheiro') + '</b><small>' + esc(a && a.mime ? a.mime : '') + '</small></div>';
      }
      return h + badges + hov;
    }
    function hostOf(u) { try { return new URL(u).hostname.replace(/^www\./, ''); } catch (e) { return ''; } }
    function pathD(pts) { var d = ''; for (var i = 0; i + 1 < pts.length; i += 2) d += (i ? 'L' : 'M') + pts[i] + ' ' + pts[i + 1]; return d; }
    function childrenOf(colId) {
      var l = [];
      B.cards.forEach(function (c) { if (c.col === colId) l.push(c); });
      return l.sort(function (a, b) { return a.ord - b.ord || a.id - b.id; });
    }
    function makeEl(c) {
      var el = document.createElement('div');
      el.className = 'ccc t-' + c.type + (c.type === 'note' && c.c && c.c.brief ? ' brief' : '') + (B.sel.has(c.id) ? ' sel' : '');
      el.setAttribute('data-id', c.id);
      if (c.type === 'note' && c.c && c.c.color) { el.style.setProperty('--note', c.c.color); el.classList.add('dark-ink'); }
      el.innerHTML = cardHtml(c);
      B.els.set(c.id, el);
      if (c.type === 'column') {
        var cb = $('[data-cb]', el);
        childrenOf(c.id).forEach(function (k) { var ke = makeEl(k); cb.appendChild(ke); setThumb(k, ke); });
      }
      if (c.type === 'drawing') el.style.height = (c.h || 100) + 'px';
      if (c.type === 'video' && c.asset && c.asset.orig) bindVideoHover(el, c);
      return el;
    }
    function place(c, el) {
      el = el || B.els.get(c.id);
      if (!el || c.col) return;
      el.style.transform = 'translate3d(' + c.x + 'px,' + c.y + 'px,0)';
      el.style.width = c.w + 'px';
      if (c.type === 'drawing') el.style.height = (c.h || 100) + 'px';
    }
    function setThumb(c, el) {
      var a = c.asset;
      if (!a || !a.thumb) return;
      var img = $('img', el);
      if (!img) return;
      var px = (c.col ? 280 : c.w) * B.view.s * (window.devicePixelRatio || 1);
      var url = thumbUrl(a, px);
      if (img.getAttribute('src') !== url) {
        // a miniatura maior entra quando já está carregada (sem piscar)
        if (img.getAttribute('src')) { var pre = new Image(); pre.onload = function () { img.src = url; }; pre.src = url; } else img.src = url;
      }
    }
    function updateThumbs() { B.els.forEach(function (el, id) { var c = B.cards.get(id); if (c) setThumb(c, el); }); }
    function bindVideoHover(el, c) {
      el.addEventListener('pointerenter', function (e) {
        if (e.pointerType !== 'mouse' || $('video', el)) return;
        var v = document.createElement('video');
        v.muted = true; v.loop = true; v.playsInline = true; v.autoplay = true; v.preload = 'auto'; v.src = c.asset.orig;
        $('.ccc-media', el).appendChild(v);
        v.play().catch(function () {});
      });
      el.addEventListener('pointerleave', function () { var v = $('video', el); if (v) { v.pause(); v.removeAttribute('src'); v.load(); v.remove(); } });
    }
    function measure(c, el) {
      if (c.type === 'image' || c.type === 'video' || c.type === 'drawing') return;
      var h = el.offsetHeight;
      if (h) c._mh = h;
    }

    // ── só o que está à vista ──
    var rafCull = 0;
    function cull() {
      var r = vp.getBoundingClientRect(), m = 300 / B.view.s;
      var a = toWorld(0, 0), b = toWorld(r.width, r.height);
      var x1 = a.x - m, y1 = a.y - m, x2 = b.x + m, y2 = b.y + m;
      var frag = null;
      B.cards.forEach(function (c) {
        if (c.col || c.type === 'arrow') return;
        var bb = bbox(c), vis = bb.x < x2 && bb.x + bb.w > x1 && bb.y < y2 && bb.y + bb.h > y1;
        var el = B.els.get(c.id);
        if (vis && !el) {
          el = makeEl(c);
          place(c, el);
          (frag || (frag = document.createDocumentFragment())).appendChild(el);
          c.__new = true;
        } else if (!vis && el && !B.sel.has(c.id) && B.editing !== c.id && !(drag && drag.ids && drag.ids.indexOf(c.id) >= 0)) {
          el.remove();
          B.els.delete(c.id);
          if (c.type === 'column') childrenOf(c.id).forEach(function (k) { B.els.delete(k.id); });
        }
      });
      if (frag) {
        layer.appendChild(frag);
        B.cards.forEach(function (c) {
          if (!c.__new) return;
          delete c.__new;
          var el = B.els.get(c.id);
          if (el) { measure(c, el); setThumb(c, el); }
        });
      }
      emptyEl.hidden = B.cards.size > 0;
      drawArrows();
      if (RT) RT.decorate();
    }
    function rerender(c) {
      var el = B.els.get(c.id);
      if (c.col) {   // dentro de uma coluna: redesenha a coluna
        var col = B.cards.get(c.col);
        if (col) rerender(col);
        return;
      }
      if (!el) { cull(); return; }
      var ne = makeEl(c);
      place(c, ne);
      el.replaceWith(ne);
      measure(c, ne);
      setThumb(c, ne);
      if (B.sel.has(c.id)) decorateSel();
      drawArrows();
    }
    function renderAll() {
      layer.innerHTML = '';
      B.els.clear();
      if (B.lanes) {
        var maxX = 2600;
        B.cards.forEach(function (c) { if (!c.col) maxX = Math.max(maxX, c.x + c.w + 300); });
        lanesEl.innerHTML = '<div class="ccb-lane" style="--lane:var(--cc-a);left:-40px;top:' + (LANE_TOP + 40) + 'px;width:' + (maxX + 40) + 'px;height:' + (LANE_SPLIT - LANE_TOP - 55) + 'px"><b>Opção A<small>Pedido do cliente</small></b></div>'
          + '<div class="ccb-lane" style="--lane:var(--cc-b);left:-40px;top:' + (LANE_SPLIT + 15) + 'px;width:' + (maxX + 40) + 'px;height:1010px"><b>Opção B<small>Proposta de valor</small></b></div>';
        lanebar();
      }
      cull();
      decorateSel();
    }

    // ── setas ──
    function centerOf(c) { var b = bbox(c); return { x: b.x + b.w / 2, y: b.y + b.h / 2, b: b }; }
    function edgePoint(from, to) {
      var b = from.b, dx = to.x - from.x, dy = to.y - from.y;
      if (!dx && !dy) return { x: from.x, y: from.y };
      var sx = dx ? (b.w / 2) / Math.abs(dx) : Infinity, sy = dy ? (b.h / 2) / Math.abs(dy) : Infinity, k = Math.min(sx, sy);
      return { x: from.x + dx * k, y: from.y + dy * k };
    }
    function drawArrows() {
      var g = $('[data-ar]', svg), h = '';
      B.cards.forEach(function (c) {
        if (c.type !== 'arrow') return;
        var f = B.cards.get(c.c.from), t = B.cards.get(c.c.to);
        if (!f || !t || f.col || t.col) return;
        var a = centerOf(f), b = centerOf(t), p1 = edgePoint(a, b), p2 = edgePoint(b, a);
        var d = 'M' + p1.x.toFixed(1) + ' ' + p1.y.toFixed(1) + 'L' + p2.x.toFixed(1) + ' ' + p2.y.toFixed(1);
        h += '<path class="hit" data-arrow="' + c.id + '" d="' + d + '"/><path' + (B.sel.has(c.id) ? ' class="sel"' : '') + ' d="' + d + '" marker-end="url(#cc-ah)" style="color:var(--mute)"/>';
        if (c.c.label) h += '<text x="' + ((p1.x + p2.x) / 2 + 6) + '" y="' + ((p1.y + p2.y) / 2 - 6) + '">' + esc(c.c.label) + '</text>';
      });
      g.innerHTML = h;
    }

    // ── seleção ──
    function decorateSel() {
      $$('.ccc-res', layer).forEach(function (r) { r.remove(); });
      B.els.forEach(function (el, id) { el.classList.toggle('sel', B.sel.has(id)); });
      if (EDIT && B.sel.size === 1) {
        var id = B.sel.values().next().value, c = B.cards.get(id), el = B.els.get(id);
        if (c && el && !c.col && c.type !== 'arrow') { var rh = document.createElement('span'); rh.className = 'ccc-res'; rh.setAttribute('aria-hidden', 'true'); el.appendChild(rh); }
      }
      drawArrows();
      if (RT) RT.decorate();
    }
    function select(ids, add) {
      if (!add) B.sel.clear();
      ids.forEach(function (id) { if (add && B.sel.has(id)) B.sel.delete(id); else B.sel.add(id); });
      decorateSel();
      sideSync();
      if (RT) RT.selection();
    }

    // ── gravar (com «Anular») ──
    function snapshot(c) { return { id: c.id, x: c.x, y: c.y, w: c.w, h: c.h, col: c.col, ord: c.ord, lane: c.lane }; }
    function push(entry) { B.undo.push(entry); if (B.undo.length > 80) B.undo.shift(); B.redo.length = 0; }
    function save(items) {
      if (!items.length) return Promise.resolve();
      if (RT) RT.publish(items);
      return api('cards_update', { board: B.id, items: JSON.stringify(items) }).then(function (j) { B.rev = j.rev; }).catch(function (e) {
        if (e.offline && RT) { RT.queue(items); return; }   // sem ligação: grava quando voltar
        fail(e); reload();
      });
    }
    function applyStates(states) {
      var touched = new Set();
      states.forEach(function (s) {
        var c = B.cards.get(s.id);
        if (!c) return;
        if (c.col) touched.add(c.col);
        Object.assign(c, s);
        if (c.col) touched.add(c.col);
      });
      renderAll();
      return save(states.map(function (s) { return Object.assign({}, s); }));
    }
    function undo(redo) {
      var st = redo ? B.redo : B.undo, other = redo ? B.undo : B.redo;
      var e = st.pop();
      if (!e) { toast(redo ? 'Nada para refazer.' : 'Nada para anular.'); return; }
      other.push(e);
      if (e.t === 'move') applyStates(e.items.map(function (i) { return redo ? i.after : i.before; }));
      else if (e.t === 'add' || e.t === 'del') {
        var restore = (e.t === 'add') === !!redo;
        api(restore ? 'cards_restore' : 'cards_delete', { board: B.id, ids: e.ids }).then(function () { if (RT) RT.signal(); reload(); }).catch(fail);
      } else if (e.t === 'content') {
        var c = B.cards.get(e.id);
        if (c) { c.c = redo ? e.after : e.before; rerender(c); save([{ id: c.id, content: c.c }]); }
      }
    }

    // ── criar ──
    function spot(w) {
      var p = ptr.inside ? ptr : center();
      var wpt = toWorld(p.x, p.y);
      return { x: Math.round((wpt.x - (w || 240) / 2) / GRID) * GRID, y: Math.round((wpt.y - 40) / GRID) * GRID };
    }
    function laneAt(y) { return !B.lanes || y < LANE_TOP ? '' : (y >= LANE_SPLIT ? 'B' : 'A'); }
    function addCard(type, content, at, extra) {
      if (!EDIT) return Promise.resolve();
      var w = { column: 300, note: 240, list: 260, swatch: 140, board: 240 }[type] || 260;
      var p = at || spot(w);
      var d = Object.assign({ board: B.id, type: type, x: p.x, y: p.y, w: w, lane: laneAt(p.y), content: JSON.stringify(content || {}) }, extra || {});
      return api('card_add', d).then(function (j) {
        B.rev = j.rev;
        var c = j.card;
        B.cards.set(c.id, c);
        if (RT) { if (type === 'board') RT.signal(); else RT.added([c]); }
        if (type === 'board') return reload().then(function () { return c; });
        if (c.col) rerender(B.cards.get(c.col)); else cull();
        var el = B.els.get(c.id);
        if (el) el.classList.add('new');
        push({ t: 'add', ids: [c.id] });
        select([c.id]);
        return c;
      }).catch(fail);
    }
    function addNote(text, at) { return addCard('note', { text: text || '' }, at).then(function (c) { if (c && !text) startEdit(c); return c; }); }
    function addColumn() { return addCard('column', { title: 'Coluna' }).then(function (c) { if (c) startEdit(c); }); }
    function addList() { return addCard('list', { title: 'Lista', items: [{ t: '', d: false }] }).then(function (c) { if (c) startEdit(c, 0); }); }
    function addSwatch(hex) { return addCard('swatch', { hex: hex || B.color }); }
    function addBoard() { return ask('Novo sub-quadro', 'Nome', { ok: 'Criar' }).then(function (v) { if (v) return addCard('board', { title: v }); }); }
    function addAsset(aid, at) {
      return api('asset', { id: aid }, 'GET').then(function (j) {
        var a = j.asset, w = 280, ratio = a.w && a.h ? a.h / a.w : 0.62, p = at || spot(w);
        return addCard(a.kind === 'video' ? 'video' : a.kind === 'link' ? 'link' : a.kind === 'file' ? 'file' : 'image', {}, p, { asset: a.id, h: a.kind === 'file' ? 0 : Math.round(w * Math.min(2.5, ratio)) });
      }).catch(fail);
    }
    function addLink(url, at) {
      var p = at || spot(300), row = Up.add(url);
      return api('link_add', { board: B.id, url: url, x: p.x, y: p.y, lane: laneAt(p.y) }).then(function (j) {
        row.done(); B.cards.set(j.card.id, j.card); cull(); push({ t: 'add', ids: [j.card.id] }); kickWorker();
        if (RT) RT.added([j.card]);
      }).catch(function (e) { row.fail(e.message); });
    }
    function addFiles(files, at) {
      var p = at || spot(280), i = 0;
      return Array.from(files).reduce(function (prom, file) {
        var q = { x: p.x + (i % 4) * 300, y: p.y + Math.floor(i / 4) * 320 }; i++;
        return prom.then(function () {
          return uploadFile(file, { board: B.id, x: q.x, y: q.y, lane: laneAt(q.y), cw: 280 }).then(function (j) {
            if (j && j.card) { B.cards.set(j.card.id, j.card); cull(); push({ t: 'add', ids: [j.card.id] }); if (RT) RT.added([j.card]); }
          }).catch(function () {});
        });
      }, Promise.resolve()).then(function () { reload(); });
    }
    function pasteText(t, at) {
      t = t.trim();
      if (!t) return;
      if (/^https?:\/\/\S+$/i.test(t)) return addLink(t, at);
      var m = /^#?([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(t);
      if (m) { var h = m[1].length === 3 ? m[1].replace(/./g, '$&$&') : m[1]; return addCard('swatch', { hex: '#' + h.toUpperCase() }, at); }
      return addNote(t, at);
    }

    // ── editar no próprio cartão ──
    function startEdit(c, itemIndex) {
      if (!EDIT) return;
      var el = B.els.get(c.id);
      if (!el) return;
      var target = c.type === 'note' ? $('.ccc-text', el) : c.type === 'column' ? $('.ccc-ch b', el) : c.type === 'list' ? (itemIndex != null ? $$('.ccc-li span', el)[itemIndex] : $('.ccc-lt', el)) : c.type === 'board' ? $('.ccc-bt b', el) : null;
      if (c.type === 'swatch') { pickColor(c); return; }
      if (!target) return;
      B.editing = c.id;
      if (RT) RT.typing(c.id);
      var before = JSON.parse(JSON.stringify(c.c || {}));
      target.contentEditable = 'true';
      target.focus();
      // um título por defeito («Coluna», «Lista») fica selecionado: escrever substitui-o
      var rng = document.createRange(); rng.selectNodeContents(target);
      if (!(c.type === 'column' && (c.c.title || '') === 'Coluna') && !(c.type === 'list' && itemIndex == null && (c.c.title || '') === 'Lista')) rng.collapse(false);
      var s = window.getSelection(); s.removeAllRanges(); s.addRange(rng);
      function end() {
        target.removeEventListener('blur', end);
        target.removeEventListener('keydown', key);
        target.contentEditable = 'false';
        B.editing = null;
        if (RT) RT.typing(null);
        var txt = target.innerText.replace(/ /g, ' ').replace(/\n$/, '');
        var nc = JSON.parse(JSON.stringify(c.c || {}));
        if (c.type === 'note') nc.text = txt;
        else if (c.type === 'column' || c.type === 'board') nc.title = txt.trim() || (c.type === 'column' ? 'Coluna' : 'Sub-quadro');
        else if (c.type === 'list') {
          if (itemIndex != null) { if (!txt.trim() && nc.items.length > 1) nc.items.splice(itemIndex, 1); else nc.items[itemIndex].t = txt; }
          else nc.title = txt.trim() || 'Lista';
        }
        if (JSON.stringify(nc) === JSON.stringify(before)) { rerender(c); return; }
        c.c = nc;
        push({ t: 'content', id: c.id, before: before, after: nc });
        rerender(c);
        save([{ id: c.id, content: nc }]);
      }
      function key(e) {
        if (e.key === 'Escape') { e.preventDefault(); target.blur(); }
        else if (e.key === 'Enter' && (c.type === 'column' || c.type === 'board' || (c.type === 'list' && itemIndex == null))) { e.preventDefault(); target.blur(); }
        else if (e.key === 'Enter' && c.type === 'list' && !e.shiftKey) {
          e.preventDefault();
          var at = itemIndex + 1;
          target.blur();
          var cc = B.cards.get(c.id);
          cc.c.items.splice(at, 0, { t: '', d: false });
          rerender(cc);
          save([{ id: cc.id, content: cc.c }]);
          setTimeout(function () { startEdit(cc, at); }, 0);
        }
        e.stopPropagation();
      }
      target.addEventListener('blur', end);
      target.addEventListener('keydown', key);
    }
    function pickColor(c) {
      var inp = document.createElement('input');
      inp.type = 'color'; inp.value = (c.c.hex || '#FFC718').toLowerCase();
      inp.style.position = 'fixed'; inp.style.left = '-50px';
      document.body.appendChild(inp);
      inp.addEventListener('change', function () {
        var before = Object.assign({}, c.c);
        c.c = Object.assign({}, c.c, { hex: inp.value.toUpperCase() });
        push({ t: 'content', id: c.id, before: before, after: c.c });
        rerender(c); save([{ id: c.id, content: c.c }]); inp.remove();
      });
      inp.click();
    }
    function delSel() {
      if (!EDIT || !B.sel.size) return;
      var ids = Array.from(B.sel);
      api('cards_delete', { board: B.id, ids: ids }).then(function (j) {
        B.rev = j.rev;
        j.ids.forEach(function (id) { B.cards.delete(id); var el = B.els.get(id); if (el) el.remove(); B.els.delete(id); });
        if (RT) RT.removed(j.ids);
        B.cards.forEach(function (c) { if (c.col && j.ids.indexOf(c.col) >= 0) { /* a coluna foi apagada: os cartões ficam onde a coluna estava */ } });
        B.sel.clear();
        push({ t: 'del', ids: j.ids });
        reload();
        toast(j.msg, { undo: function () { api('cards_restore', { board: B.id, ids: j.ids }).then(function () { if (RT) RT.signal(); reload(); toast('Reposto.'); }).catch(fail); } });
      }).catch(fail);
    }
    function dupSel() {
      if (!EDIT || !B.sel.size) return;
      api('cards_dup', { board: B.id, ids: Array.from(B.sel) }).then(function (j) {
        B.rev = j.rev;
        j.cards.forEach(function (c) { B.cards.set(c.id, c); });
        if (RT) RT.added(j.cards);
        push({ t: 'add', ids: j.cards.map(function (c) { return c.id; }) });
        renderAll();
        select(j.cards.map(function (c) { return c.id; }));
      }).catch(fail);
    }

    // ── ferramentas ──
    function setTool(t) {
      B.tool = t;
      B.arrowFrom = null;
      $$('[data-tool]', root).forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-tool') === t); b.setAttribute('aria-pressed', b.getAttribute('data-tool') === t ? 'true' : 'false'); });
      vp.classList.toggle('tool-draw', t === 'draw');
      vp.classList.toggle('tool-arrow', t === 'arrow');
    }
    function runTool(t) {
      if (t === 'note') return addNote();
      if (t === 'column') return addColumn();
      if (t === 'list') return addList();
      if (t === 'swatch') return addSwatch();
      if (t === 'board') return addBoard();
      if (t === 'link') return ask('Adicionar um link', 'Endereço', { placeholder: 'https://…', type: 'url', ok: 'Adicionar' }).then(function (v) { if (v) addLink(v); });
      if (t === 'ai') return openSlash();
      if (t === 'draw') {
        if (B.tool === 'draw') { // segundo clique: escolher a cor
          var cs = ['#111111', '#FFC718', '#E8846D', '#81CFE3', '#2F77B1', '#FFFFFF'];
          B.color = cs[(cs.indexOf(B.color) + 1) % cs.length];
          var sw = $('[data-tool="draw"] .sw', root); if (sw) sw.style.background = B.color;
          return;
        }
      }
      setTool(t);
      if (t === 'arrow') toast('Toque num cartão e depois noutro para ligar com uma seta.');
      if (t === 'draw') toast('Desenhe na tela. Clique outra vez no lápis para mudar a cor; Esc para sair.');
    }

    // ── gestos: arrastar cartões, mover a tela, pinça, seleção em caixa, desenhar, redimensionar ──
    var drag = null, pointers = new Map(), pinch = null;
    function bindBoard() {
      root.addEventListener('click', function (e) {
        var tool = e.target.closest('[data-tool]');
        if (tool) { runTool(tool.getAttribute('data-tool')); return; }
        var z = e.target.closest('[data-z]');
        if (z) { var k = +z.getAttribute('data-z'); if (k === 0) fitAll(true); else zoomAt(center(), k > 0 ? 1.25 : 0.8); return; }
        var a = e.target.closest('[data-a]');
        if (a) { topAction(a.getAttribute('data-a'), a); return; }
        var tab = e.target.closest('[data-tab]');
        if (tab) { B.side = tab.getAttribute('data-tab'); sideSync(); return; }
        var pg = e.target.closest('[data-page-gen]');
        if (pg) { pageGen(pg.getAttribute('data-page-gen'), pg); return; }
        var pv = e.target.closest('[data-page-view]');
        if (pv) { pageView(pv.getAttribute('data-page-view')); return; }
        var pb = e.target.closest('[data-page-both]');
        if (pb) { var on = !pb.classList.contains('on'); api('page_both', { board: B.id, lane: pb.getAttribute('data-page-both'), on: on ? 1 : '' }).then(function (j) { toast(j.msg); if (RT) RT.signal(); reload(); }).catch(fail); return; }
      });
      var up = $('[data-up]', root);
      if (up) up.addEventListener('change', function () { addFiles(up.files); up.value = ''; });
      var title = $('[data-title]', root);
      if (title && EDIT) title.addEventListener('dblclick', function () {
        title.contentEditable = 'true'; title.focus();
        var before = title.textContent;
        var end = function () {
          title.removeEventListener('blur', end);
          title.contentEditable = 'false';
          var t = title.textContent.trim();
          if (!t || t === before) { title.textContent = before; return; }
          api('board_save', { id: B.id, title: t }).then(function (j) { B.rev = j.rev; toast('Nome mudado.', { undo: function () { api('board_save', { id: B.id, title: before }).then(function () { title.textContent = before; }).catch(fail); } }); }).catch(function (er) { title.textContent = before; fail(er); });
        };
        title.addEventListener('blur', end);
        title.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === 'Escape') { e.preventDefault(); if (e.key === 'Escape') title.textContent = before; title.blur(); } e.stopPropagation(); });
      });

      vp.addEventListener('pointermove', function (e) { var p = vpPoint(e); ptr.x = p.x; ptr.y = p.y; ptr.inside = true; if (RT && e.pointerType === 'mouse') RT.cursor(toWorld(p.x, p.y)); }, { passive: true });
      vp.addEventListener('pointerleave', function () { ptr.inside = false; if (RT) RT.cursor(null); });
      vp.addEventListener('pointerdown', down);
      vp.addEventListener('pointermove', move);
      vp.addEventListener('pointerup', upEv);
      vp.addEventListener('pointercancel', upEv);
      vp.addEventListener('dblclick', dbl);
      vp.addEventListener('wheel', function (e) {
        e.preventDefault();
        if (RT) RT.userMoved();
        var k = e.deltaMode === 1 ? 16 : e.deltaMode === 2 ? 400 : 1;
        if (e.ctrlKey || e.metaKey) zoomAt(vpPoint(e), Math.exp(-clamp(e.deltaY * k, -60, 60) * 0.006));   // roda: ~1,4× por clique; pinça: suave
        else { B.view.x -= (e.shiftKey && !e.deltaX ? e.deltaY : e.deltaX) * k; if (!(e.shiftKey && !e.deltaX)) B.view.y -= e.deltaY * k; applyView(); }
      }, { passive: false });
      // Safari (iPad com teclado/trackpad): gestos de pinça
      vp.addEventListener('gesturestart', function (e) { e.preventDefault(); vp.__gs = B.view.s; });
      vp.addEventListener('gesturechange', function (e) { e.preventDefault(); if (vp.__gs) { var f = (vp.__gs * e.scale) / B.view.s; zoomAt(ptr.inside ? ptr : center(), f); } });
      vp.addEventListener('change', function (e) {   // caixas das listas
        var cb = e.target.closest('.ccc-li input');
        if (!cb || !EDIT) return;
        var el = cb.closest('.ccc'), c = B.cards.get(+el.getAttribute('data-id')), i = +cb.closest('.ccc-li').getAttribute('data-i');
        var before = JSON.parse(JSON.stringify(c.c));
        c.c.items[i].d = cb.checked;
        push({ t: 'content', id: c.id, before: before, after: JSON.parse(JSON.stringify(c.c)) });
        rerender(c); save([{ id: c.id, content: c.c }]);
      });
      vp.addEventListener('click', function (e) {
        var b = e.target.closest('[data-cact]');
        if (!b) return;
        e.stopPropagation();
        var el = b.closest('.ccc'), c = B.cards.get(+el.getAttribute('data-id')), act = b.getAttribute('data-cact');
        if (act === 'com') { select([c.id]); B.side = 'com'; sideEl.hidden = false; sideSync(); return; }
        if (act === 'del') { select([c.id]); delSel(); return; }
        if (act === 'keep') {
          var before = Object.assign({}, c.c);
          c.c = Object.assign({}, c.c, { state: c.c.state === 'keep' ? '' : 'keep' });
          push({ t: 'content', id: c.id, before: before, after: c.c });
          rerender(c); save([{ id: c.id, content: c.c }]);
          return;
        }
        if (act === 'swap') {
          b.disabled = true;
          api('card_swap', { board: B.id, card: c.id }).then(function (j) { B.rev = j.rev; B.cards.set(j.card.id, j.card); rerender(j.card); if (RT) RT.added([j.card]); toast('Trocada por outra parecida.'); }).catch(fail).then(function () { b.disabled = false; });
          return;
        }
        if (act === 'li-add') {
          c.c.items.push({ t: '', d: false });
          rerender(c);
          startEdit(c, c.c.items.length - 1);
        }
      });
      vp.addEventListener('dragover', function (e) {
        if (!EDIT || !e.dataTransfer) return;
        var t = e.dataTransfer.types;
        if (t.indexOf('Files') >= 0 || t.indexOf('text/x-cc-asset') >= 0 || t.indexOf('text/uri-list') >= 0 || t.indexOf('text/plain') >= 0) { e.preventDefault(); e.dataTransfer.dropEffect = 'copy'; }
      });
      vp.addEventListener('drop', function (e) {
        if (!EDIT || !e.dataTransfer) return;
        e.preventDefault();
        var p = vpPoint(e), w = toWorld(p.x, p.y), at = { x: Math.round((w.x - 140) / GRID) * GRID, y: Math.round((w.y - 40) / GRID) * GRID };
        var aid = e.dataTransfer.getData('text/x-cc-asset');
        if (aid) { addAsset(+aid, at); return; }
        if (e.dataTransfer.files && e.dataTransfer.files.length) { addFiles(e.dataTransfer.files, at); return; }
        var u = (e.dataTransfer.getData('text/uri-list') || '').split('\n')[0].trim();
        var t = u || e.dataTransfer.getData('text/plain');
        if (t) pasteText(t, at);
      });
      document.addEventListener('paste', function (e) {
        if (!EDIT || typing(document.activeElement) || (T.topMdl && T.topMdl())) return;
        var dt = e.clipboardData;
        if (!dt) return;
        if (dt.files && dt.files.length) { e.preventDefault(); addFiles(dt.files); return; }
        var t = dt.getData('text/plain');
        if (t) { e.preventDefault(); pasteText(t); }
      });
      window.addEventListener('keydown', keys, true);
      window.addEventListener('keyup', function (e) { if (e.key === ' ' && spaceDown) { spaceDown = false; vp.classList.remove('space'); } }, true);
      window.addEventListener('resize', function () { applyView(); });
      sideEl.addEventListener('click', sideClick);
    }
    function down(e) {
      if (e.button === 2) return;
      var t = e.target;
      if (t.closest('.ccb-zoom') || t.closest('[data-cact]') || t.closest('input,textarea,select,button,a') || (t.isContentEditable && t.contentEditable === 'true')) return;
      pointers.set(e.pointerId, vpPoint(e));
      vp.setPointerCapture(e.pointerId);
      if (RT) RT.userMoved();
      if (pointers.size === 2) {
        if (drag && drag.kind === 'drag' && drag.moved) finishDrag(e);
        drag = null;
        var ps = Array.from(pointers.values());
        pinch = { d0: Math.hypot(ps[0].x - ps[1].x, ps[0].y - ps[1].y) || 1, m0: { x: (ps[0].x + ps[1].x) / 2, y: (ps[0].y + ps[1].y) / 2 }, v0: Object.assign({}, B.view) };
        return;
      }
      if (pointers.size > 2) return;
      var p = vpPoint(e), w = toWorld(p.x, p.y);
      var cardEl = t.closest('.ccc'), c = cardEl ? B.cards.get(+cardEl.getAttribute('data-id')) : null;
      var arrowHit = t.closest('[data-arrow]');
      if (spaceDown || e.button === 1) { drag = { kind: 'pan', p0: p, v0: Object.assign({}, B.view) }; vp.classList.add('panning'); return; }
      if (B.tool === 'draw' && EDIT) { drag = { kind: 'draw', pts: [w.x, w.y], path: null }; startStroke(); return; }
      if (B.tool === 'arrow' && EDIT) {
        if (!c) return;
        if (!B.arrowFrom) { B.arrowFrom = c.id; select([c.id]); }
        else if (B.arrowFrom !== c.id) { var from = B.arrowFrom; B.arrowFrom = null; addCard('arrow', { from: from, to: c.id }, { x: 0, y: 0 }).then(function () { drawArrows(); }); }
        return;
      }
      if (arrowHit) { select([+arrowHit.getAttribute('data-arrow')], e.shiftKey); return; }
      if (t.closest('.ccc-res') && c && EDIT) { drag = { kind: 'resize', c: c, p0: w, w0: c.w, h0: c.h || cardH(c), before: snapshot(c) }; return; }
      if (c) {
        if (!B.sel.has(c.id) && !(e.shiftKey || e.metaKey || e.ctrlKey)) select([c.id]);
        if (!EDIT) { drag = { kind: 'click', c: c, p0: p, shift: e.shiftKey || e.metaKey || e.ctrlKey }; return; }
        // arrastar pela coluna: só pelo cabeçalho (o corpo deixa arrastar os cartões de dentro)
        var ids = Array.from(B.sel).filter(function (id) { var k = B.cards.get(id); return k && k.type !== 'arrow'; });
        if (ids.indexOf(c.id) < 0) ids = [c.id];
        if (c.col) ids = [c.id];
        else ids = ids.filter(function (id) { return !B.cards.get(id).col; });
        drag = { kind: 'drag', c: c, ids: ids, p0: p, w0: w, moved: false, shift: e.shiftKey || e.metaKey || e.ctrlKey, before: ids.map(function (id) { return snapshot(B.cards.get(id)); }) };
        return;
      }
      if (e.shiftKey) { drag = { kind: 'marq', p0: p, el: null }; return; }
      drag = { kind: 'pan', p0: p, v0: Object.assign({}, B.view), bg: true };
      vp.classList.add('panning');
    }
    function move(e) {
      if (!pointers.has(e.pointerId)) return;
      var p = vpPoint(e);
      pointers.set(e.pointerId, p);
      if (pinch && pointers.size >= 2) {
        var ps = Array.from(pointers.values()), d = Math.hypot(ps[0].x - ps[1].x, ps[0].y - ps[1].y), m = { x: (ps[0].x + ps[1].x) / 2, y: (ps[0].y + ps[1].y) / 2 };
        var s = clamp(pinch.v0.s * d / pinch.d0, 0.08, 4), wx = (pinch.m0.x - pinch.v0.x) / pinch.v0.s, wy = (pinch.m0.y - pinch.v0.y) / pinch.v0.s;
        B.view.s = s; B.view.x = m.x - wx * s; B.view.y = m.y - wy * s;
        applyView();
        return;
      }
      if (!drag) return;
      var w = toWorld(p.x, p.y);
      if (drag.kind === 'pan') {
        B.view.x = drag.v0.x + (p.x - drag.p0.x); B.view.y = drag.v0.y + (p.y - drag.p0.y);
        if (Math.hypot(p.x - drag.p0.x, p.y - drag.p0.y) > 3) drag.moved = true;
        applyView();
      } else if (drag.kind === 'drag') {
        if (!drag.moved && Math.hypot(p.x - drag.p0.x, p.y - drag.p0.y) < 4) return;
        if (!drag.moved) startDrag();
        drag.moved = true;
        var dx = w.x - drag.w0.x, dy = w.y - drag.w0.y;
        drag.before.forEach(function (b) { var c = B.cards.get(b.id); c.x = b.x + dx; c.y = b.y + dy; place(c); });
        if (drag.from) { var cc = drag.c; cc.x = drag.fx + dx; cc.y = drag.fy + dy; place(cc); }
        if (RT && !drag.from) RT.live(drag.ids);
        hoverColumn(w);
        if (B.lanes) $$('.ccb-lane', lanesEl).forEach(function (l, i) { l.classList.toggle('drop', laneAt(w.y) === (i ? 'B' : 'A')); });
        drawArrows();
      } else if (drag.kind === 'resize') {
        var c = drag.c, nw = clamp(drag.w0 + (w.x - drag.p0.x), 80, 3000);
        if (c.type === 'drawing') c.h = Math.round(drag.h0 * nw / drag.w0);
        c.w = Math.round(nw);
        place(c); drawArrows();
        if (RT) RT.live([c.id]);
      } else if (drag.kind === 'marq') {
        if (!drag.el) { drag.el = document.createElement('div'); drag.el.className = 'ccb-marq'; vp.appendChild(drag.el); }
        var x = Math.min(p.x, drag.p0.x), y = Math.min(p.y, drag.p0.y);
        Object.assign(drag.el.style, { left: x + 'px', top: y + 'px', width: Math.abs(p.x - drag.p0.x) + 'px', height: Math.abs(p.y - drag.p0.y) + 'px' });
      } else if (drag.kind === 'draw') {
        var last = drag.pts.length - 2;
        if (Math.hypot(w.x - drag.pts[last], w.y - drag.pts[last + 1]) * B.view.s < 2) return;
        drag.pts.push(w.x, w.y);
        drag.path.setAttribute('d', pathD(drag.pts.map(function (v) { return Math.round(v * 10) / 10; })));
      } else if (drag.kind === 'click') {
        if (Math.hypot(p.x - drag.p0.x, p.y - drag.p0.y) > 4) { drag = { kind: 'pan', p0: p, v0: Object.assign({}, B.view) }; }
      }
    }
    function startDrag() {
      var c = drag.c;
      if (c.col) {   // sai da coluna: passa a flutuar onde está
        var el = B.els.get(c.id), r = el.getBoundingClientRect(), vr = vp.getBoundingClientRect();
        var pos = toWorld(r.left - vr.left, r.top - vr.top);
        drag.from = { col: c.col, ord: c.ord };
        drag.fx = pos.x; drag.fy = pos.y;
        drag.before = [];
        drag.single = snapshot(c);
        c.col = null; c.x = pos.x; c.y = pos.y; c.w = Math.max(c.w, 240);
        el.style.width = c.w + 'px';
        layer.appendChild(el);
        place(c, el);
        var colC = B.cards.get(drag.from.col);
        if (colC) { var cel = B.els.get(colC.id); if (cel) { var n = $('.ccc-ch small', cel); if (n) n.textContent = childrenOf(colC.id).length; } }
      }
      drag.ids.forEach(function (id) { var el = B.els.get(id); if (el) el.classList.add('drag'); });
    }
    function hoverColumn(w) {
      $$('.ccc.t-column.over', layer).forEach(function (el) { el.classList.remove('over'); });
      $$('.ccc-cb .ins', layer).forEach(function (el) { el.remove(); });
      drag.over = null;
      if (drag.ids.length !== 1 || drag.c.type === 'column' || drag.c.type === 'board' && false) return;
      var hit = null;
      B.cards.forEach(function (c) {
        if (c.type !== 'column' || c.id === drag.c.id) return;
        var b = bbox(c), el = B.els.get(c.id);
        if (el) b.h = el.offsetHeight;
        if (w.x >= b.x && w.x <= b.x + b.w && w.y >= b.y && w.y <= b.y + b.h + 40) hit = c;
      });
      if (!hit) return;
      var cel = B.els.get(hit.id);
      if (!cel) return;
      cel.classList.add('over');
      var kids = childrenOf(hit.id).filter(function (k) { return k.id !== drag.c.id; }), idx = kids.length;
      for (var i = 0; i < kids.length; i++) {
        var kel = B.els.get(kids[i].id);
        if (!kel) continue;
        var r = kel.getBoundingClientRect(), vr = vp.getBoundingClientRect();
        if ((w.y - B.view.y / B.view.s) < 0) break;
        var mid = toWorld(0, r.top - vr.top + r.height / 2).y;
        if (w.y < mid) { idx = i; break; }
      }
      var ins = document.createElement('div'); ins.className = 'ins';
      var cb = $('[data-cb]', cel), ref = kids[idx] ? B.els.get(kids[idx].id) : null;
      if (ref && ref.parentNode === cb) cb.insertBefore(ins, ref); else cb.appendChild(ins);
      drag.over = { col: hit.id, idx: idx, kids: kids };
    }
    function finishDrag(e) {
      var changes = [], before = [];
      $$('.ccc.drag', layer).forEach(function (el) { el.classList.remove('drag'); });
      $$('.ccc.t-column.over', layer).forEach(function (el) { el.classList.remove('over'); });
      $$('.ccc-cb .ins', layer).forEach(function (el) { el.remove(); });
      $$('.ccb-lane.drop', lanesEl).forEach(function (el) { el.classList.remove('drop'); });
      if (drag.over) {
        var c = drag.c, kids = drag.over.kids.slice();
        before.push(drag.single || snapshot(c));
        kids.splice(drag.over.idx, 0, c);
        c.col = drag.over.col; c.lane = B.cards.get(drag.over.col).lane;
        kids.forEach(function (k, i) { if (k !== c) before.push(snapshot(k)); k.ord = i; changes.push({ id: k.id, col: k.col, ord: k.ord, lane: k.lane }); });
        var el = B.els.get(c.id);
        if (el) el.remove();
        B.els.delete(c.id);
        rerender(B.cards.get(drag.over.col));
      } else {
        var snapOff = e && e.altKey;
        var lead = drag.single || drag.before[0], lc = B.cards.get(lead.id);
        var sdx = snapOff ? 0 : Math.round(lc.x / GRID) * GRID - lc.x, sdy = snapOff ? 0 : Math.round(lc.y / GRID) * GRID - lc.y;
        var all = drag.single ? [drag.single] : drag.before;
        all.forEach(function (b) {
          var k = B.cards.get(b.id);
          k.x = Math.round((k.x + sdx) * 10) / 10; k.y = Math.round((k.y + sdy) * 10) / 10;
          var nl = laneAt(k.y + Math.min(cardH(k), 200) / 2);
          if (B.lanes) k.lane = nl;
          place(k);
          before.push(b);
          changes.push({ id: k.id, x: k.x, y: k.y, col: k.col, lane: k.lane });
          childrenOf(k.id).forEach(function (kid) { if (B.lanes && kid.lane !== k.lane) { before.push(snapshot(kid)); kid.lane = k.lane; changes.push({ id: kid.id, lane: kid.lane }); } });
        });
        if (drag.from) rerender(B.cards.get(drag.from.col) || {});
      }
      var after = changes.map(function (ch) { return Object.assign(snapshot(B.cards.get(ch.id))); });
      push({ t: 'move', items: before.map(function (b) { return { before: b, after: after.filter(function (a) { return a.id === b.id; })[0] || b }; }) });
      save(changes);
      drawArrows();
      cull();
    }
    function upEv(e) {
      pointers.delete(e.pointerId);
      if (pinch) { if (pointers.size < 2) pinch = null; return; }
      vp.classList.remove('panning');
      if (!drag) return;
      var d = drag;
      if (d.kind === 'drag') {
        if (d.moved) finishDrag(e);
        else if (d.shift) select([d.c.id], true);
        else select([d.c.id]);
      } else if (d.kind === 'click') {
        select([d.c.id], d.shift);
      } else if (d.kind === 'pan' && d.bg && !d.moved) {
        if (B.sel.size) select([]);
      } else if (d.kind === 'resize') {
        var c = d.c;
        push({ t: 'move', items: [{ before: d.before, after: snapshot(c) }] });
        save([{ id: c.id, w: c.w, h: c.h }]);
        rerender(c);
      } else if (d.kind === 'marq') {
        if (d.el) {
          var r = d.el.getBoundingClientRect(), vr = vp.getBoundingClientRect();
          var a = toWorld(r.left - vr.left, r.top - vr.top), b = toWorld(r.right - vr.left, r.bottom - vr.top), ids = [];
          B.cards.forEach(function (c) { if (c.col || c.type === 'arrow') return; var bb = bbox(c); if (bb.x < b.x && bb.x + bb.w > a.x && bb.y < b.y && bb.y + bb.h > a.y) ids.push(c.id); });
          d.el.remove();
          select(ids, true);
        }
      } else if (d.kind === 'draw') {
        endStroke(d.pts);
      }
      drag = null;
    }
    function dbl(e) {
      var el = e.target.closest('.ccc');
      if (!el) { if (EDIT && B.tool === 'select' && !e.target.closest('.ccb-zoom')) { var p = vpPoint(e), w = toWorld(p.x, p.y); addNote('', { x: Math.round((w.x - 120) / GRID) * GRID, y: Math.round((w.y - 30) / GRID) * GRID }); } return; }
      var c = B.cards.get(+el.getAttribute('data-id'));
      if (!c) return;
      if (c.type === 'board' && c.ref) { if (e.target.closest('.ccc-bt b') && EDIT) startEdit(c); else location.href = '/admin/criativo.php?b=' + c.ref; return; }
      if (c.type === 'list') { var li = e.target.closest('.ccc-li'); startEdit(c, li ? +li.getAttribute('data-i') : null); return; }
      if (c.type === 'note' || c.type === 'column' || c.type === 'swatch') { startEdit(c); return; }
      if (c.type === 'link' && c.asset && c.asset.url) { window.open(c.asset.url, '_blank', 'noopener'); return; }
      if (c.type === 'file' && c.asset && c.asset.orig) { window.open(c.asset.orig, '_blank', 'noopener'); return; }
      if (c.asset) { select([c.id]); B.side = 'det'; sideEl.hidden = false; sideSync(); }
    }

    // desenhar
    function startStroke() {
      var ns = 'http://www.w3.org/2000/svg', g = $('[data-ar]', svg), p = document.createElementNS(ns, 'path');
      p.setAttribute('stroke', B.color); p.setAttribute('stroke-width', '3'); p.setAttribute('stroke-linecap', 'round'); p.setAttribute('fill', 'none');
      p.style.stroke = B.color;
      g.appendChild(p);
      drag.path = p;
    }
    function endStroke(pts) {
      if (drag && drag.path) drag.path.remove();
      if (pts.length < 6) return;
      var xs = pts.filter(function (v, i) { return i % 2 === 0; }), ys = pts.filter(function (v, i) { return i % 2 === 1; });
      var x1 = Math.min.apply(null, xs) - 6, y1 = Math.min.apply(null, ys) - 6, x2 = Math.max.apply(null, xs) + 6, y2 = Math.max.apply(null, ys) + 6;
      var w = Math.max(20, x2 - x1), h = Math.max(20, y2 - y1);
      var rel = pts.map(function (v, i) { return Math.round((v - (i % 2 ? y1 : x1)) * 10) / 10; });
      addCard('drawing', { paths: [{ c: B.color, w: 3, pts: rel }], vw: Math.round(w), vh: Math.round(h) }, { x: Math.round(x1), y: Math.round(y1) }, { w: Math.round(w), h: Math.round(h) })
        .then(function () { select([]); });
    }

    // ── teclado ──
    function keys(e) {
      if (T.topMdl && T.topMdl()) return;
      if (document.querySelector('.cmdk-back.open') || document.querySelector('.cmdk.open')) return;
      var ae = document.activeElement;
      if (typing(ae)) return;
      if (slash && e.key !== 'Escape') return;
      var mod = e.metaKey || e.ctrlKey, k = e.key;
      var handled = true;
      if (k === ' ' && !mod) { if (!spaceDown) { spaceDown = true; vp.classList.add('space'); } }
      else if (!mod && !e.altKey && (k === 'n' || k === 'N')) addNote();
      else if (!mod && !e.altKey && (k === 'c' || k === 'C')) addColumn();
      else if (!mod && !e.altKey && (k === 'l' || k === 'L')) addList();
      else if (!mod && (k === 'v' || k === 'V')) setTool('select');
      else if (!mod && (k === 'd' || k === 'D') && EDIT) runTool('draw');
      else if (!mod && (k === 'a' || k === 'A') && EDIT) runTool('arrow');
      else if (k === '/' && !mod) openSlash();
      else if ((k === 'Delete' || k === 'Backspace') && B.sel.size) delSel();
      else if (k === 'Escape') { if (slash) closeSlash(); else if (RT && RT.escape()) { /* deixou de seguir */ } else if (B.tool !== 'select') setTool('select'); else if (B.sel.size) select([]); else handled = false; }
      else if (mod && (k === 'z' || k === 'Z')) undo(e.shiftKey);
      else if (mod && (k === 'y' || k === 'Y')) undo(true);
      else if (mod && (k === 'd' || k === 'D')) dupSel();
      else if (mod && (k === 'a' || k === 'A')) { var all = []; B.cards.forEach(function (c) { if (!c.col && c.type !== 'arrow') all.push(c.id); }); select(all); }
      else if (!mod && (k === '+' || k === '=')) zoomAt(center(), 1.25);
      else if (!mod && k === '-') zoomAt(center(), 0.8);
      else if (!mod && k === '0') fitAll(true);
      else if (!mod && (k === 'f' || k === 'F')) fitSel();
      else if (!mod && k === '1') zoomAt(center(), 1 / B.view.s);
      else if (k.indexOf('Arrow') === 0 && B.sel.size && EDIT && !mod) {
        var st = e.shiftKey ? GRID : 1, dx = k === 'ArrowLeft' ? -st : k === 'ArrowRight' ? st : 0, dy = k === 'ArrowUp' ? -st : k === 'ArrowDown' ? st : 0, ch = [];
        B.sel.forEach(function (id) { var c = B.cards.get(id); if (!c || c.col || c.type === 'arrow') return; c.x += dx; c.y += dy; place(c); ch.push({ id: c.id, x: c.x, y: c.y }); });
        drawArrows();
        if (RT) RT.live(ch.map(function (x) { return x.id; }));
        clearTimeout(keys.t); keys.t = setTimeout(function () { save(ch); }, 400);
      }
      else handled = false;
      if (handled) { e.preventDefault(); e.stopImmediatePropagation(); }
    }

    // ── menu «/» (IA) ──
    var slash = null;
    function aiActs() {
      var sel = Array.from(B.sel).map(function (id) { return B.cards.get(id); }).filter(Boolean);
      var asset = sel.length === 1 && sel[0].asset ? sel[0] : null;
      var l = [{ k: 'angles', i: '✦', t: 'Ângulos para este projeto' }];
      if (B.lanes) l.unshift({ k: 'prepare', i: '✦', t: 'Preparar com IA (as duas faixas)', edit: true });
      l.push({ k: 'analyze', i: '◎', t: 'Analisar referência', off: !asset, edit: true },
             { k: 'similar', i: '⌕', t: 'Mais como este', off: !asset },
             { k: 'refs', i: '☰', t: 'Referências para o brief' },
             { k: 'tidy', i: '▦', t: 'Arrumar o quadro', edit: true },
             { k: 'treatment', i: '▤', t: 'Quadro → tratamento' },
             { k: 'shots', i: '☷', t: 'Storyboard → lista de planos', edit: true });
      return l.filter(function (x) { return !x.edit || EDIT; });
    }
    function openSlash() {
      closeSlash();
      var acts = aiActs(), p = ptr.inside ? ptr : center(), r = vp.getBoundingClientRect();
      slash = document.createElement('div');
      slash.className = 'cc-slash';
      slash.setAttribute('role', 'dialog');
      slash.setAttribute('aria-label', 'Ações da IA');
      slash.innerHTML = '<input type="text" placeholder="Pedir à IA…" aria-label="Filtrar ações"><div class="h">IA · Centro Criativo</div><ul role="listbox"></ul>';
      document.body.appendChild(slash);
      slash.style.left = clamp(r.left + p.x, 8, innerWidth - 310) + 'px';
      slash.style.top = clamp(r.top + p.y, 8, innerHeight - 340) + 'px';
      var inp = $('input', slash), ul = $('ul', slash), cur = 0, shown = acts;
      function draw() {
        var q = inp.value.trim().toLowerCase();
        shown = acts.filter(function (a) { return !q || a.t.toLowerCase().indexOf(q) >= 0; });
        cur = Math.min(cur, Math.max(0, shown.length - 1));
        ul.innerHTML = shown.map(function (a, i) { return '<li><button type="button" role="option" data-k="' + a.k + '"' + (a.off ? ' disabled' : '') + (i === cur ? ' class="on"' : '') + '><i>' + a.i + '</i>' + esc(a.t) + '</button></li>'; }).join('') || '<li class="muted small" style="padding:8px 10px">Nada.</li>';
      }
      draw();
      inp.focus();
      inp.addEventListener('input', draw);
      inp.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowDown') { e.preventDefault(); cur = Math.min(shown.length - 1, cur + 1); draw(); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); cur = Math.max(0, cur - 1); draw(); }
        else if (e.key === 'Enter') { e.preventDefault(); var a = shown[cur]; if (a && !a.off) { closeSlash(); runAI(a.k); } }
        else if (e.key === 'Escape') { e.preventDefault(); closeSlash(); vp.focus(); }
        e.stopPropagation();
      });
      ul.addEventListener('click', function (e) { var b = e.target.closest('[data-k]'); if (b && !b.disabled) { closeSlash(); runAI(b.getAttribute('data-k')); } });
      setTimeout(function () { document.addEventListener('pointerdown', outside, true); }, 0);
    }
    function outside(e) { if (slash && !slash.contains(e.target)) closeSlash(); }
    function closeSlash() { if (slash) { slash.remove(); slash = null; document.removeEventListener('pointerdown', outside, true); } }

    // ── a IA (painel) ──
    var aiState = { busy: '', html: '', angles: [] };
    function aiBusy(label) {
      aiState.busy = label;
      B.side = 'ia'; sideEl.hidden = false; sideSync();
    }
    function aiDone(html) { aiState.busy = ''; if (html != null) aiState.html = html; sideSync(); }
    function selAsset() { var c = B.cards.get(Array.from(B.sel)[0]); return c && c.asset ? c : null; }
    function runAI(k) {
      var c = selAsset();
      if (k === 'prepare') return topAction('prepare');
      if (k === 'analyze') { if (!c) return; api('analyze', { id: c.asset.id }).then(function (j) { toast(j.msg); c.asset.ai = 'queued'; rerender(c); kickWorker(); }).catch(fail); return; }
      if (k === 'similar') {
        if (!c) return;
        aiBusy('A procurar referências parecidas…');
        return api('similar', { id: c.asset.id }, 'GET').then(function (j) { aiDone('<h4>Mais como este <small class="muted">(' + esc(j.by) + ')</small></h4>' + (j.items.length ? refsHtml(j.items) + '<p class="muted small">Clique para pôr no quadro ou arraste.</p>' : '<p class="muted">Nada parecido na biblioteca.</p>')); }).catch(function (e) { aiDone(); fail(e); });
      }
      if (k === 'refs') {
        aiBusy('A procurar referências para o brief…');
        return api('ai', { board: B.id, act: 'refs' }).then(function (j) { aiDone('<h4>Referências para o brief <small class="muted">(' + esc(j.by) + ')</small></h4>' + (j.items.length ? refsHtml(j.items) + '<p class="muted small">Clique para pôr no quadro ou arraste.</p>' : '<p class="muted">A biblioteca ainda não tem referências que sirvam.</p>')); }).catch(function (e) { aiDone(); fail(e); });
      }
      if (k === 'angles') return angles(false);
      if (k === 'tidy') {
        aiBusy('A arrumar o quadro em colunas…');
        return api('ai', { board: B.id, act: 'tidy' }).then(function (j) { aiDone(); toast(j.msg); if (RT) RT.signal(); reload().then(function () { fitAll(true); }); }).catch(function (e) { aiDone(); fail(e); });
      }
      if (k === 'treatment') {
        aiBusy('A escrever o tratamento… (pode demorar um minuto)');
        return api('ai', { board: B.id, act: 'treatment' }).then(function (j) {
          aiDone('<h4>Tratamento</h4><div class="cc-angle"><b>' + esc(j.doc.title) + '</b><p>' + esc(j.doc.logline) + '</p><span class="meta">' + (j.doc.sections || []).length + ' secções</span></div>'
            + '<a class="btn sm" href="' + esc(j.url) + '" target="_blank" rel="noopener">' + ico('ext') + ' Abrir (imprimir / PDF)</a>');
        }).catch(function (e) { aiDone(); fail(e); });
      }
      if (k === 'shots') {
        aiBusy('A transformar o storyboard em lista de planos…');
        return api('ai', { board: B.id, act: 'shots' }).then(function (j) {
          B.cards.set(j.card.id, j.card); cull(); select([j.card.id]);
          if (RT) RT.added([j.card]);
          aiDone('<h4>Lista de planos</h4><p>' + j.shots.length + ' planos numa lista no quadro.</p>' + (B.data.board.deal ? '<button type="button" class="btn sm" data-shots-tasks="' + j.card.id + '">Criar as tarefas no negócio</button>' : ''));
        }).catch(function (e) { aiDone(); fail(e); });
      }
    }
    function angles(more) {
      aiBusy(more ? 'Mais ângulos…' : 'A pensar em ângulos… (pode demorar um minuto)');
      return api('ai', { board: B.id, act: 'angles', avoid: more ? aiState.angles.map(function (a) { return a.title; }).join('\n') : '' }).then(function (j) {
        aiState.angles = more ? aiState.angles.concat(j.angles) : j.angles;
        aiDone(anglesHtml());
      }).catch(function (e) { aiDone(); fail(e); });
    }
    function anglesHtml() {
      return '<h4>Ângulos para este projeto</h4>' + aiState.angles.map(function (a, i) {
        return '<div class="cc-angle">' + (a.lane ? '<span class="lane">OPÇÃO ' + esc(a.lane) + '</span>' : '<span class="n">' + (i + 1) + '</span>') + '<b>' + esc(a.title) + '</b><p>' + esc(a.idea) + '</p>'
          + '<span class="meta">' + esc(a.technique) + ' · complexidade ' + esc(a.complexity) + '</span><span class="why">' + esc(a.why) + '</span>'
          + (EDIT ? '<div><button type="button" class="btn sm ghost" data-route="' + i + '">Criar rota a partir deste</button></div>' : '') + '</div>';
      }).join('') + '<button type="button" class="btn sm ghost" data-more-angles>Mais ângulos</button>';
    }
    function routeFrom(i) {
      var a = aiState.angles[i];
      if (!a) return;
      var box = contentBox(), at = { x: Math.round(((box ? box.x + box.w : 0) + 60) / GRID) * GRID, y: box ? Math.round(box.y / GRID) * GRID : 80 };
      if (B.lanes && a.lane) at.y = a.lane === 'B' ? LANE_SPLIT + 80 : LANE_TOP + 100;
      addCard('column', { title: 'Rota · ' + a.title }, at).then(function (col) {
        if (!col) return;
        return api('card_add', { board: B.id, type: 'note', column: col.id, x: 0, y: 0, lane: col.lane, content: JSON.stringify({ text: a.title + '\n' + a.idea + '\n\n' + a.technique + ' · complexidade ' + a.complexity + '\n\nPorque funciona: ' + a.why }) })
          .then(function (j) { B.rev = j.rev; B.cards.set(j.card.id, j.card); rerender(col); if (RT) RT.added([j.card]); toast('Rota criada.'); });
      }).catch(fail);
    }

    // ── painel lateral ──
    function sideSync() {
      if (!sideEl || sideEl.hidden) return;
      $$('[data-tab]', sideEl).forEach(function (t) { t.classList.toggle('on', t.getAttribute('data-tab') === B.side); t.setAttribute('aria-selected', t.getAttribute('data-tab') === B.side ? 'true' : 'false'); });
      var sel = Array.from(B.sel).map(function (id) { return B.cards.get(id); }).filter(Boolean);
      if (B.side === 'ia') {
        var acts = aiActs();
        sideBody.innerHTML = (B.data.ai ? '' : '<p class="small muted">A IA do Centro Criativo ainda não está ligada (Definições › Integrações). As ações sem IA continuam a funcionar.</p>')
          + '<div class="cc-aiacts">' + acts.map(function (a) { return '<button type="button" data-ai="' + a.k + '"' + (a.off ? ' disabled' : '') + '><i>' + a.i + '</i>' + esc(a.t) + '</button>'; }).join('') + '</div>'
          + (aiState.busy ? '<p class="muted"><span class="spin"></span> ' + esc(aiState.busy) + '</p>' : aiState.html)
          + '<div><h4>Brief</h4>' + (EDIT ? '<textarea rows="6" data-brief placeholder="O que é, para quem, o objetivo, o tom…">' + esc(B.data.board.brief) + '</textarea><div class="actions" style="margin-top:6px"><button type="button" class="btn sm ghost" data-brief-save>Guardar o brief</button></div>' : '<p>' + esc(B.data.board.brief || '—') + '</p>')
          + (B.data.board.deal && B.data.brief && B.data.brief !== B.data.board.brief ? '<details><summary class="small muted">Do X-Ray do negócio</summary><p class="small" style="white-space:pre-line">' + esc(B.data.brief) + '</p></details>' : '') + '</div>';
        return;
      }
      if (B.side === 'det') {
        var c = sel.length === 1 ? sel[0] : null;
        if (!c || !c.asset) { sideBody.innerHTML = '<p class="muted">Selecione uma imagem, vídeo ou link para ver a análise, a paleta e editar.</p>'; return; }
        sideBody.innerHTML = '<span class="spin"></span>';
        api('asset', { id: c.asset.id }, 'GET').then(function (j) {
          if (B.side !== 'det') return;
          sideBody.innerHTML = '<div>' + assetDetailHtml(j.asset) + '</div>';
          bindAssetDetail(sideBody.firstChild, j.asset, {
            onColor: function (hex) { if (EDIT) addSwatch(hex); },
            onChange: function (na) { c.asset = Object.assign(c.asset, na); rerender(c); },
            onDelete: function () { reload(); },
            onRestore: function () { reload(); },
            onAdd: function (aid) { addAsset(aid); }
          });
        }).catch(fail);
        return;
      }
      if (B.side === 'hist') { historyPanel(); return; }
      // comentários: do cartão selecionado ou do quadro
      var card = sel.length === 1 ? sel[0] : null;
      sideBody.innerHTML = '<h4>' + (card ? 'Comentários do cartão' : 'Comentários do quadro') + '</h4><div class="cc-cmts" data-cmts><span class="spin"></span></div>'
        + '<form class="cc-cmt-f" data-cmt-f><textarea placeholder="Escreva… @ para mencionar alguém" aria-label="Comentário"></textarea><div class="cc-ment" hidden></div><button type="submit" class="btn sm">Comentar</button></form>';
      loadComments(card);
    }
    function curCard() { var sel = Array.from(B.sel); return sel.length === 1 ? sel[0] : 0; }
    function reactHtml(m) {
      var r = m.react || {}, me = B.data.me, keys = Object.keys(r);
      var chips = keys.map(function (k) {
        var who = r[k] || [], mine = who.indexOf(me) >= 0;
        var names = who.map(function (id) { var u = (B.data.users || []).filter(function (x) { return x.id === id; })[0]; return u ? u.name.split(' ')[0] : ''; }).filter(Boolean).join(', ');
        return '<button type="button" class="cc-rx' + (mine ? ' on' : '') + '" data-react="' + m.id + '" data-emoji="' + esc(k) + '" aria-pressed="' + (mine ? 'true' : 'false') + '" title="' + esc(names) + '">' + esc(k) + ' <b>' + who.length + '</b></button>';
      }).join('');
      if (!EDIT) return chips ? '<div class="cc-rxs">' + chips + '</div>' : '';
      return '<div class="cc-rxs">' + chips + '<button type="button" class="cc-rx add" data-react-pick aria-label="Reagir">☺+</button><span class="cc-rxp" hidden>'
        + (B.data.reactions || []).map(function (k) { return '<button type="button" data-react="' + m.id + '" data-emoji="' + esc(k) + '" aria-label="Reagir com ' + esc(k) + '">' + esc(k) + '</button>'; }).join('') + '</span></div>';
    }
    function cmtHtml(list) {
      if (!list.length) return '<p class="muted small">Sem comentários.</p>';
      return list.map(function (m) {
        var body = esc(m.body).replace(/@([\p{L}][\p{L}.\-]*(?:\s[\p{L}][\p{L}.\-]*)?)/gu, '<span class="m">@$1</span>');
        return '<div class="cc-cmt' + (m.resolved ? ' res' : '') + '"><span class="w"><b>' + esc(m.who) + '</b>' + (m.client ? '<span class="cl">CLIENTE</span>' : '') + '<span>' + esc(m.when) + '</span>'
          + (EDIT ? '<button type="button" class="lnk small" data-cres="' + m.id + '">' + (m.resolved ? 'Reabrir' : 'Resolver') + '</button>' : '')
          + (m.uid === B.data.me || MANAGE ? '<button type="button" class="lnk small del" data-cdel="' + m.id + '" aria-label="Apagar comentário">' + ico('trash') + '</button>' : '') + '</span><p>' + body + '</p>' + reactHtml(m) + '</div>';
      }).join('');
    }
    function loadComments(card) {
      api('comments', { board: B.id, card: card ? card.id : '' }, 'GET').then(function (j) { var el = $('[data-cmts]', sideBody); if (el) el.innerHTML = cmtHtml(j.comments); }).catch(fail);
    }
    function sideClick(e) {
      var ai = e.target.closest('[data-ai]');
      if (ai) { runAI(ai.getAttribute('data-ai')); return; }
      if (e.target.closest('[data-more-angles]')) { angles(true); return; }
      var rt = e.target.closest('[data-route]');
      if (rt) { routeFrom(+rt.getAttribute('data-route')); return; }
      var st = e.target.closest('[data-shots-tasks]');
      if (st) { st.disabled = true; api('ai', { board: B.id, act: 'shots_tasks', card: st.getAttribute('data-shots-tasks') }).then(function (j) { toast(j.msg); }).catch(fail).then(function () { st.disabled = false; }); return; }
      var ref = e.target.closest('.cc-refs [data-aid]');
      if (ref && EDIT && B.side === 'ia') { addAsset(+ref.getAttribute('data-aid')); ref.style.opacity = '.35'; return; }
      if (e.target.closest('[data-brief-save]')) {
        var v = $('[data-brief]', sideBody).value, before = B.data.board.brief;
        api('board_save', { id: B.id, brief: v }).then(function (j) { B.rev = j.rev; B.data.board.brief = v; toast('Brief guardado.', { undo: function () { api('board_save', { id: B.id, brief: before }).then(function () { B.data.board.brief = before; sideSync(); }).catch(fail); } }); }).catch(fail);
        return;
      }
      var cres = e.target.closest('[data-cres]'), cdel = e.target.closest('[data-cdel]');
      if (cres || cdel) {
        api(cres ? 'comment_resolve' : 'comment_del', { id: (cres || cdel).getAttribute(cres ? 'data-cres' : 'data-cdel') }).then(function (j) { $('[data-cmts]', sideBody).innerHTML = cmtHtml(j.comments); if (RT) RT.comments(curCard()); }).catch(fail);
        return;
      }
      var rx = e.target.closest('[data-react]');
      if (rx) {
        api('react', { id: rx.getAttribute('data-react'), emoji: rx.getAttribute('data-emoji') }).then(function (j) { $('[data-cmts]', sideBody).innerHTML = cmtHtml(j.comments); if (RT) RT.comments(curCard()); }).catch(fail);
        return;
      }
      var rp = e.target.closest('[data-react-pick]');
      if (rp) { var pk = rp.nextElementSibling; if (pk) pk.hidden = !pk.hidden; return; }
      var hv = e.target.closest('[data-ver]');
      if (hv) { versionAct(hv.getAttribute('data-ver'), +hv.getAttribute('data-id'), hv); return; }
    }
    // comentários: enviar e @menções
    root.addEventListener('submit', function (e) {
      var f = e.target.closest('[data-cmt-f]');
      if (!f) return;
      e.preventDefault();
      var ta = $('textarea', f), sel = Array.from(B.sel), card = sel.length === 1 ? sel[0] : '';
      if (!ta.value.trim()) return;
      api('comment_add', { board: B.id, card: card, body: ta.value }).then(function (j) {
        ta.value = '';
        $('[data-cmts]', sideBody).innerHTML = cmtHtml(j.comments);
        var c = B.cards.get(card);
        if (c) { c.nc = j.comments.length; rerender(c); }
        if (RT) RT.comments(card || 0, j.mentioned);
      }).catch(fail);
    });
    root.addEventListener('keydown', function (e) {
      var ta = e.target.closest && e.target.closest('[data-cmt-f] textarea');
      if (!ta) return;
      var box = $('.cc-ment', ta.parentNode);
      if (!box.hidden) {
        var bs = $$('button', box), i = bs.findIndex(function (b) { return b.classList.contains('on'); });
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); if (bs[i]) bs[i].classList.remove('on'); i = (i + (e.key === 'ArrowDown' ? 1 : -1) + bs.length) % bs.length; bs[i].classList.add('on'); return; }
        if (e.key === 'Enter' || e.key === 'Tab') { e.preventDefault(); if (bs[i >= 0 ? i : 0]) bs[i >= 0 ? i : 0].click(); return; }
        if (e.key === 'Escape') { e.preventDefault(); box.hidden = true; return; }
      }
      if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) { e.preventDefault(); ta.form.requestSubmit ? ta.form.requestSubmit() : ta.form.dispatchEvent(new Event('submit', { cancelable: true })); }
    });
    root.addEventListener('input', function (e) {
      var ta = e.target.closest && e.target.closest('[data-cmt-f] textarea');
      if (!ta) return;
      var box = $('.cc-ment', ta.parentNode), before = ta.value.slice(0, ta.selectionStart), m = /@([\p{L}.\-]*)$/u.exec(before);
      if (!m) { box.hidden = true; return; }
      var q = m[1].toLowerCase(), users = (B.data.users || []).filter(function (u) { return u.name.toLowerCase().indexOf(q) === 0 || u.name.toLowerCase().split(' ').some(function (p) { return p.indexOf(q) === 0; }); }).slice(0, 8);
      if (!users.length) { box.hidden = true; return; }
      box.innerHTML = users.map(function (u, i) { return '<button type="button" data-ment="' + esc(u.name) + '"' + (i ? '' : ' class="on"') + '>' + esc(u.name) + '</button>'; }).join('');
      box.hidden = false;
      box.onclick = function (ev) {
        var b = ev.target.closest('[data-ment]');
        if (!b) return;
        var name = b.getAttribute('data-ment'), first = name.split(' ')[0];
        var dup = (B.data.users || []).filter(function (u) { return u.name.split(' ')[0] === first; }).length > 1;
        var ins = '@' + (dup ? name : first) + ' ';
        var pos = ta.selectionStart, start = pos - m[0].length;
        ta.value = ta.value.slice(0, start) + ins + ta.value.slice(pos);
        ta.selectionStart = ta.selectionEnd = start + ins.length;
        box.hidden = true;
        ta.focus();
      };
    });

    // ── histórico do quadro: versões com «Ver» e «Restaurar» ──
    function historyPanel() {
      sideBody.innerHTML = '<h4>Histórico do quadro</h4><p class="small muted">Uma versão por sessão de trabalho e antes das ações grandes (Arrumar o quadro, Preparar com IA, apagar muitos cartões). Restaurar guarda primeiro o estado de agora.</p>'
        + (EDIT ? '<div class="actions" style="margin:6px 0 10px"><button type="button" class="btn sm ghost" data-ver="mark" data-id="0">Guardar esta versão</button></div>' : '')
        + '<div class="cc-vers" data-vers><span class="spin"></span></div>';
      api('versions', { board: B.id }, 'GET').then(function (j) {
        var el = $('[data-vers]', sideBody);
        if (!el || B.side !== 'hist') return;
        el.innerHTML = j.versions.length ? '<ol>' + j.versions.map(function (v) {
          return '<li><div><b>' + esc(v.label || j.kinds[v.kind] || 'Versão') + '</b><small>' + esc(v.when) + (v.who ? ' · ' + esc(v.who) : '') + ' · ' + v.n + (v.n === 1 ? ' cartão' : ' cartões') + '</small></div>'
            + '<span><button type="button" class="lnk small" data-ver="view" data-id="' + v.id + '">Ver</button>'
            + (EDIT ? '<button type="button" class="lnk small" data-ver="restore" data-id="' + v.id + '" data-when="' + esc(v.when) + '">Restaurar</button>' : '') + '</span></li>';
        }).join('') + '</ol>' : '<p class="muted small">Ainda não há versões. A primeira guarda-se na próxima alteração.</p>';
      }).catch(fail);
    }
    function versionAct(k, id, btn) {
      if (k === 'mark') {
        ask('Guardar esta versão', 'Nome (opcional)', { ok: 'Guardar', from: btn }).then(function (v) {
          if (v === null) return;
          api('version_mark', { board: B.id, label: v || '' }).then(function (j) { toast(j.msg); historyPanel(); }).catch(fail);
        });
        return;
      }
      if (k === 'view') {
        api('version_view', { board: B.id, id: id }, 'GET').then(function (j) {
          var m = modal('<h3>Versão de ' + esc(j.when) + '</h3>' + versionPreview(j.cards)
            + '<div class="actions">' + (EDIT ? '<button type="button" class="btn" data-vr="' + id + '">Restaurar esta versão</button>' : '') + '<button type="button" class="btn ghost" data-mdl-close>Fechar</button></div>', 'cc-vmodal');
          m.addEventListener('click', function (e) { var r = e.target.closest('[data-vr]'); if (r) { if (T.closeMdl) T.closeMdl(m); versionAct('restore', id, null); } });
        }).catch(fail);
        return;
      }
      if (k === 'restore') {
        var when = btn ? btn.getAttribute('data-when') : '';
        var m = modal('<h3>Restaurar a versão' + (when ? ' de ' + esc(when) : '') + '?</h3><p>Os cartões voltam a como estavam. O estado de agora fica guardado no histórico, para poder voltar atrás.</p>'
          + '<div class="actions"><button type="button" class="btn" data-ok>Restaurar</button><button type="button" class="btn ghost" data-mdl-close>Cancelar</button></div>');
        m.addEventListener('click', function (e) {
          if (!e.target.closest('[data-ok]')) return;
          if (T.closeMdl) T.closeMdl(m);
          api('version_restore', { board: B.id, id: id }).then(function (j) {
            toast(j.msg);
            if (RT) RT.signal();
            reload().then(function () { if (B.side === 'hist') historyPanel(); });
          }).catch(fail);
        });
      }
    }
    /** Uma miniatura estática da versão (posições, imagens e textos). */
    function versionPreview(list) {
      var top = list.filter(function (c) { return !c.col && c.type !== 'arrow'; });
      if (!top.length) return '<p class="muted">Quadro vazio nesta versão.</p>';
      var x1 = Infinity, y1 = Infinity, x2 = -Infinity, y2 = -Infinity;
      top.forEach(function (c) { var h = c.asset && c.asset.w && c.asset.h ? c.w * c.asset.h / c.asset.w : (c.h || 160); x1 = Math.min(x1, c.x); y1 = Math.min(y1, c.y); x2 = Math.max(x2, c.x + c.w); y2 = Math.max(y2, c.y + h); c.__h = h; });
      var W = Math.min(860, window.innerWidth - 80), s = Math.min(W / Math.max(1, x2 - x1), 520 / Math.max(1, y2 - y1), 1), H = Math.round((y2 - y1) * s);
      return '<div class="cc-vprev" style="height:' + H + 'px;width:' + Math.round((x2 - x1) * s) + 'px">' + top.map(function (c) {
        var st = 'left:' + Math.round((c.x - x1) * s) + 'px;top:' + Math.round((c.y - y1) * s) + 'px;width:' + Math.round(c.w * s) + 'px;height:' + Math.max(8, Math.round(c.__h * s)) + 'px';
        var x = c.c || {}, inner = '';
        if (c.asset && c.asset.thumb) inner = '<img src="' + esc(thumbUrl(c.asset, c.w * s * 2)) + '" alt="">';
        else if (c.type === 'note') inner = '<span>' + esc((x.text || '').slice(0, 120)) + '</span>';
        else if (c.type === 'column' || c.type === 'list' || c.type === 'board') inner = '<span><b>' + esc(x.title || '') + '</b></span>';
        else if (c.type === 'swatch') inner = '<i style="background:' + esc(x.hex || '#ccc') + '"></i>';
        return '<div class="vp-c t-' + c.type + '" style="' + st + '">' + inner + '</div>';
      }).join('') + '</div>';
    }

    // ── ações do topo ──
    function topAction(k, btn) {
      if (k === 'side') { sideEl.hidden = !sideEl.hidden; sideSync(); return; }
      if (k === 'present') { present(); return; }
      if (k === 'prepare') {
        if (btn) btn.disabled = true;
        aiBusy('A preparar as duas faixas: ângulos e referências da biblioteca… (pode demorar um minuto)');
        api('prepare', { board: B.id }).then(function (j) { aiDone(); toast(j.msg); if (RT) RT.signal(); reload().then(function () { fitAll(true); }); }).catch(function (e) { aiDone(); fail(e); }).then(function () { if (btn) btn.disabled = false; });
        return;
      }
      if (k === 'send') {
        if (btn) btn.disabled = true;
        api('send_budget', { board: B.id }).then(function (j) { toast(j.msg); if (RT) RT.signal(); reload(); }).catch(fail).then(function () { if (btn) btn.disabled = false; });
        return;
      }
      if (k === 'more') {
        var b = B.data.board, arch = b.status === 'archived';
        var m = modal('<h3>' + esc(b.title) + '</h3><div class="actions" style="flex-direction:column;align-items:stretch">'
          + '<button type="button" class="btn ghost" data-m="arch">' + (arch ? 'Reabrir o quadro' : 'Arquivar o quadro') + '</button>'
          + '<button type="button" class="btn danger" data-m="del">Apagar o quadro</button><button type="button" class="btn ghost" data-mdl-close>Fechar</button></div>', '');
        m.addEventListener('click', function (e) {
          var x = e.target.closest('[data-m]');
          if (!x) return;
          if (x.getAttribute('data-m') === 'arch') api('board_archive', { id: B.id, on: arch ? '' : 1 }).then(function (j) { toast(j.msg); location.reload(); }).catch(fail);
          else api('board_delete', { id: B.id }).then(function (j) {
            toast(j.msg, { undo: function () { api('board_delete', { id: B.id, restore: 1 }).then(function () { location.reload(); }).catch(fail); } });
            setTimeout(function () { location.href = '/admin/criativo.php'; }, 8200);
          }).catch(fail);
        });
      }
    }

    // ── páginas do pré-criativo ──
    function pageGen(lane, btn) {
      btn.disabled = true;
      btn.textContent = 'A gerar…';
      api('page_gen', { board: B.id, lane: lane }).then(function (j) {
        toast(j.msg);
        if (RT) RT.signal();
        return reload().then(function () { pageView(lane); });
      }).catch(fail).then(function () { lanebar(); });
    }
    function pageHtml(lane, pg) {
      var d = pg.data || {};
      return '<div class="cc-page"><span class="lbl">' + (lane === 'A' ? 'Opção A · Pedido' : 'Opção B · Proposta de valor') + (pg.both ? ' · serve as duas' : '') + ' · pré-criativo</span>'
        + '<h3>' + esc(d.title || '') + '</h3><p class="con">' + esc(d.concept || '') + '</p><p class="app">' + esc(d.approach || '') + '</p>'
        + '<div class="g">' + (d.refs_view || []).map(function (a) { return '<img src="' + esc(thumbUrl(a, 768)) + '" alt="' + esc(a.title) + '">'; }).join('') + '</div>'
        + '<div class="f"><span class="pal">' + palHtml(d.palette, 6) + '</span><span>' + esc([d.technique, d.complexity ? 'Complexidade ' + String(d.complexity).toLowerCase() : ''].filter(Boolean).join(' · ')) + '</span></div>'
        + (d.ai === false ? '<p class="muted small">Gerada sem IA, a partir das notas da faixa.</p>' : '') + '</div>';
    }
    function pageView(lane) {
      var pg = (B.data.pages || {})[lane];
      if (!pg) return;
      modal(pageHtml(lane, pg) + '<div class="actions"><button type="button" class="btn ghost" data-mdl-close>Fechar</button></div>');
    }

    // ── apresentação ──
    function present() {
      var items = [];
      B.cards.forEach(function (c) { if (!c.col && c.type !== 'arrow' && c.type !== 'drawing') items.push(c); });
      if (!items.length) { toast('O quadro está vazio.'); return; }
      items.sort(function (a, b) { return Math.round(a.y / 300) - Math.round(b.y / 300) || a.x - b.x; });
      var i = 0, bar = document.createElement('div');
      root.classList.add('present');
      bar.className = 'cc-pres';
      bar.innerHTML = '<button type="button" data-p="-1" aria-label="Anterior">‹</button><span data-pn></span><button type="button" data-p="1" aria-label="Seguinte">›</button><button type="button" data-p="x">Sair (Esc)</button>';
      document.body.appendChild(bar);
      if (root.requestFullscreen) root.requestFullscreen().catch(function () {});
      function go() {
        var c = items[i], b = bbox(c);
        animateView(function () { fitTo(b, 70, 3); });
        $('[data-pn]', bar).textContent = (i + 1) + ' / ' + items.length;
        select([]);
      }
      function stop() {
        root.classList.remove('present');
        bar.remove();
        window.removeEventListener('keydown', pk, true);
        if (document.fullscreenElement && document.exitFullscreen) document.exitFullscreen().catch(function () {});
        setTimeout(function () { fitAll(true); }, 50);
      }
      function pk(e) {
        if (e.key === 'ArrowRight' || e.key === ' ' || e.key === 'PageDown') { i = Math.min(items.length - 1, i + 1); go(); }
        else if (e.key === 'ArrowLeft' || e.key === 'PageUp') { i = Math.max(0, i - 1); go(); }
        else if (e.key === 'Escape') stop();
        else return;
        e.preventDefault(); e.stopImmediatePropagation();
      }
      bar.addEventListener('click', function (e) { var b = e.target.closest('[data-p]'); if (!b) return; var v = b.getAttribute('data-p'); if (v === 'x') stop(); else { i = clamp(i + +v, 0, items.length - 1); go(); } });
      document.addEventListener('fullscreenchange', function f() { if (!document.fullscreenElement && root.classList.contains('present')) { document.removeEventListener('fullscreenchange', f); stop(); } });
      window.addEventListener('keydown', pk, true);
      setTimeout(go, 80);
    }

    // ── tempo real (cc_rt.js) ──
    function startRT() {
      if (!window.ccRealtime) return;
      RT = window.ccRealtime({
        B: B, api: api, toast: toast, csrf: CSRF, apiUrl: API,
        vp: function () { return vp; },
        elOf: function (id) { return B.els.get(+id); },
        busy: function (id) { return B.editing === id || !!(drag && ((drag.ids && drag.ids.indexOf(id) >= 0) || (drag.c && drag.c.id === id))); },
        drop: function (id) { B.cards.delete(id); B.sel.delete(id); var el = B.els.get(id); if (el) el.remove(); B.els.delete(id); },
        renderAll: function () { renderAll(); sideSync(); },
        rerender: rerender, drawArrows: drawArrows, reload: reload,
        glide: function (c) {
          var el = B.els.get(c.id);
          if (!el) { if (!c.col) cull(); return; }
          if (!reduced.matches) { el.classList.add('glide'); clearTimeout(el.__g); el.__g = setTimeout(function () { el.classList.remove('glide'); }, 160); }
          place(c, el);
        },
        visibleRect: function () {
          var r = vp.getBoundingClientRect(), a = toWorld(0, 0), b = toWorld(r.width, r.height);
          return { x: Math.round(a.x), y: Math.round(a.y), w: Math.round(b.x - a.x), h: Math.round(b.y - a.y) };
        },
        showRect: function (r) { animateView(function () { fitTo(r, 0, 4); }); },
        onComments: function (card) {
          var sel = Array.from(B.sel), cur = sel.length === 1 ? sel[0] : 0;
          if (sideEl && !sideEl.hidden && B.side === 'com' && cur === card) loadComments(card ? B.cards.get(card) : null);
          var c = card ? B.cards.get(card) : null;
          if (c) api('comments', { board: B.id, card: card }, 'GET').then(function (j) { c.nc = j.comments.length; rerender(c); }).catch(function () {});
        },
        openComments: function (card) { select(card && B.cards.has(card) ? [card] : []); B.side = 'com'; sideEl.hidden = false; sideSync(); }
      });
      var acts = $('.ccb-acts', root);
      if (acts) RT.mount(acts);
    }

    // ── recarregar (outro separador mudou) ──
    function reload() {
      var keepSel = Array.from(B.sel);
      return load().then(function () {
        B.sel = new Set(keepSel.filter(function (id) { return B.cards.has(id); }));
        if (RT) RT.reconcile();
        renderAll();
        sideSync();
      }).catch(function (e) { if (!e.offline) fail(e); });
    }
    var lastPoll = 0;
    setInterval(function () {
      if (document.hidden || drag || B.editing || !B.data) return;
      if (RT && RT.connected && Date.now() - lastPoll < 30000) return;   // em tempo real, a sondagem é só a rede de segurança
      lastPoll = Date.now();
      api('poll', { id: B.id, rev: B.rev }, 'GET').then(function (j) {
        if (j.changed && !drag && !B.editing) { B.rev = j.rev; reload(); }
        if (j.jobs && EDIT) kickWorker();
      }).catch(function () {});
    }, 8000);
    onJobs(function () {
      var wait = [];
      B.cards.forEach(function (c) { if (c.asset && (c.asset.ai === 'queued' || c.asset.ai === 'running')) wait.push(c.asset.id); });
      refreshAssets(wait, function (a) { B.cards.forEach(function (c) { if (c.asset && c.asset.id === a.id) { c.asset = a; if (B.els.has(c.id) || c.col) rerender(c); } }); });
    });

    // ── arranque ──
    load().then(function () {
      frame();
      startRT();
      renderAll();
      var deep = +root.getAttribute('data-card');
      if (deep && B.cards.has(deep)) {
        var c = B.cards.get(deep);
        if (c.col) c = B.cards.get(c.col) || c;
        fitTo(bbox(c), 120, 1); applyView();
        select([deep]); B.side = 'com'; sideEl.hidden = false; sideSync();
      } else {
        fitAll(false);
        if (B.view.s > 1) { B.view.s = 1; }
        applyView();
        sideSync();
      }
      var waiting = false;
      B.cards.forEach(function (c) { if (c.asset && (c.asset.ai === 'queued' || c.asset.ai === 'running')) waiting = true; });
      if (waiting) kickWorker();
      vp.focus({ preventScroll: true });
    }).catch(function (e) {
      root.innerHTML = '<div class="panel" style="margin:16px">' + esc(e.message) + ' <a href="/admin/criativo.php">Voltar ao Centro Criativo</a></div>';
    });
  }

  // ── arranque da página (depois do app.js: avisos, janelas, «Anular») ──
  function start() {
    T = window.topper || {};
    if (VIEW === 'projetos') { viewProjects(); bindNewBoard(); }
    else if (VIEW === 'biblioteca') { viewLibrary(false); bindImport(); }
    else if (VIEW === 'entrada') viewLibrary(true);
    else if (VIEW === 'board') viewBoard();
  }
  // os scripts «defer» correm por ordem e este vem antes do app.js: espera por ele
  if (window.topper && window.topper.toast) start(); else document.addEventListener('DOMContentLoaded', start);
})();
