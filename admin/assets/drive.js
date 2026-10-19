/* topper. admin — Drive (Cloudflare R2): envios diretos do browser para o R2.
 * O servidor (drive_api.php) só assina; os bytes nunca passam pelo PHP. */
(function () {
  'use strict';
  var root = document.getElementById('drive');
  if (!root) return;
  var PREFIX = root.getAttribute('data-prefix') || '';
  var CSRF = root.getAttribute('data-csrf') || '';
  var API = root.getAttribute('data-api') || '/admin/drive_api.php';
  var PARALLEL = 4, RETRIES = 4, SIGN_BATCH = 20;

  // ── utilitários ─────────────────────────────────────────
  function api(body) {
    return fetch(API, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
      body: JSON.stringify(body)
    }).then(function (r) {
      return r.json().catch(function () {
        return { ok: false, error: r.status === 401 ? 'A sessão terminou. Entre outra vez.' : 'Resposta inesperada do servidor (HTTP ' + r.status + ').' };
      }).then(function (j) { j.status = r.status; return j; });
    }, function () { return { ok: false, error: 'Sem ligação ao servidor.' }; });
  }
  function must(j) { if (!j.ok) { var e = new Error(j.error || 'Erro.'); e.resp = j; throw e; } return j; }
  function size(b) {
    var u = ['B', 'KB', 'MB', 'GB', 'TB'], i = 0, n = b;
    while (n >= 1024 && i < 4) { n /= 1024; i++; }
    return (i ? n.toFixed(n < 10 ? 1 : 0).replace('.', ',') : String(b)) + ' ' + u[i];
  }
  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text != null) e.textContent = text;
    return e;
  }
  var msgBox = el('div', 'flash', '');
  msgBox.hidden = true;
  root.insertBefore(msgBox, root.firstChild);
  function say(text, kind) {
    msgBox.className = 'flash ' + (kind || 'ok');
    msgBox.textContent = text;
    msgBox.hidden = false;
    msgBox.scrollIntoView({ block: 'nearest' });
  }
  function pageNoDel() {
    var u = new URL(location.href);
    u.searchParams.delete('del');
    return u.pathname + u.search;
  }
  function sleep(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }
  function store(k, v) {
    try { if (v === undefined) sessionStorage.removeItem(k); else sessionStorage.setItem(k, JSON.stringify(v)); } catch (e) { /* sem armazenamento */ }
  }
  function load(k) { try { return JSON.parse(sessionStorage.getItem(k) || 'null'); } catch (e) { return null; } }

  /** PUT com progresso. Resolve com o ETag. */
  function put(url, blob, type, onProgress, job) {
    return new Promise(function (resolve, reject) {
      var x = new XMLHttpRequest();
      job.xhrs.add(x);
      x.open('PUT', url);
      if (type) x.setRequestHeader('Content-Type', type);
      x.upload.onprogress = function (ev) { onProgress(ev.loaded); };
      x.onload = function () {
        job.xhrs.delete(x);
        if (x.status >= 200 && x.status < 300) resolve((x.getResponseHeader('ETag') || '').replace(/"/g, ''));
        else { var e = new Error('O R2 recusou o envio (HTTP ' + x.status + ').'); e.http = x.status; reject(e); }
      };
      x.onerror = function () { job.xhrs.delete(x); reject(new Error('A ligação ao R2 falhou (rede ou CORS).')); };
      x.onabort = function () { job.xhrs.delete(x); reject(new Error('cancelado')); };
      x.send(blob);
    });
  }

  // ── fila de envios ──────────────────────────────────────
  var queue = document.getElementById('drive-queue');
  var pending = [], running = 0, uploadedAny = false;

  function addFiles(list) {
    for (var i = 0; i < list.length; i++) {
      var f = list[i];
      var li = el('li', 'dq-item');
      var top = el('div', 'dq-top');
      top.appendChild(el('span', 'dq-name', f.name));
      var st = el('span', 'dq-status muted', size(f.size) + ' · em espera');
      top.appendChild(st);
      var cancel = el('button', 'btn ghost sm', 'Cancelar');
      cancel.type = 'button';
      top.appendChild(cancel);
      li.appendChild(top);
      var bar = el('div', 'dq-bar'); var fill = el('div', 'dq-fill'); bar.appendChild(fill); li.appendChild(bar);
      queue.appendChild(li);
      var job = { file: f, li: li, st: st, fill: fill, btn: cancel, xhrs: new Set(), cancelled: false, mp: null };
      cancel.addEventListener('click', cancelJob.bind(null, job));
      pending.push(job);
    }
    pump();
  }
  function pump() {
    while (running < 2 && pending.length) {
      var job = pending.shift();
      if (job.cancelled) continue;
      running++;
      run(job).then(function () { running--; done(); }, function () { running--; done(); });
    }
  }
  function done() {
    pump();
    if (!running && !pending.length && uploadedAny) {
      say('Envio concluído. A atualizar a lista…', 'ok');
      setTimeout(function () { location.href = pageNoDel(); }, 900);
    }
  }
  function status(job, text, pct) {
    job.st.textContent = text;
    if (pct != null) job.fill.style.width = Math.max(0, Math.min(100, pct)).toFixed(1) + '%';
  }
  function cancelJob(job) {
    if (job.cancelled) return;
    job.cancelled = true;
    job.xhrs.forEach(function (x) { x.abort(); });
    status(job, 'Cancelado', 0);
    job.li.classList.add('dq-off');
    job.btn.disabled = true;
    if (job.mp) {
      api({ do: 'mp_abort', key: job.mp.key, uploadId: job.mp.uploadId });
      store(job.mp.store);
    }
  }

  function run(job) {
    var f = job.file;
    status(job, size(f.size) + ' · a preparar…', 0);
    var sk = 'r2mp:' + PREFIX + '\u0000' + f.name + '\u0000' + f.size + '\u0000' + f.lastModified;
    var saved = load(sk);
    var start = saved
      ? api({ do: 'mp_parts', key: saved.key, uploadId: saved.uploadId }).then(function (j) {
          if (!j.ok) { store(sk); return sign(false); }
          var parts = {};
          Object.keys(j.parts || {}).forEach(function (n) { parts[n] = j.parts[n]; });
          return { ok: true, mode: 'multipart', key: saved.key, type: saved.type, uploadId: saved.uploadId, partSize: saved.partSize, done: parts, resumed: true };
        })
      : sign(false);

    function sign(overwrite) {
      return api({ do: 'sign', prefix: PREFIX, name: f.name, type: f.type || '', size: f.size, overwrite: overwrite }).then(function (j) {
        if (!j.ok && j.exists && !job.cancelled) {
          if (window.confirm('Já existe «' + f.name + '» nesta pasta. Substituir?')) return sign(true);
          var e = new Error('Não enviado (já existia).'); e.quiet = true; throw e;
        }
        return must(j);
      });
    }

    return start.then(function (s) {
      if (job.cancelled) throw new Error('cancelado');
      if (s.mode === 'single') {
        status(job, size(f.size) + ' · a enviar…', 0);
        return put(s.url, f, s.type, function (l) {
          status(job, size(l) + ' de ' + size(f.size), f.size ? l * 100 / f.size : 100);
        }, job);
      }
      return multipart(job, s, sk);
    }).then(function () {
      uploadedAny = true;
      status(job, size(f.size) + ' · enviado ✓', 100);
      job.li.classList.add('dq-ok');
      job.btn.disabled = true;
    }, function (e) {
      if (job.cancelled) return;
      status(job, e.message, null);
      job.li.classList.add(e.quiet ? 'dq-off' : 'dq-err');
      job.btn.disabled = true;
      throw e;
    });
  }

  function multipart(job, s, sk) {
    var f = job.file, ps = s.partSize, total = Math.max(1, Math.ceil(f.size / ps));
    var doneParts = s.done || {};
    job.mp = { key: s.key, uploadId: s.uploadId, store: sk };
    var rec = { key: s.key, uploadId: s.uploadId, type: s.type, partSize: ps, parts: doneParts };
    store(sk, rec);
    var loaded = {}, base = 0;
    Object.keys(doneParts).forEach(function (n) { base += Math.min(ps, f.size - (n - 1) * ps); });
    function show() {
      var sum = base; Object.keys(loaded).forEach(function (k) { sum += loaded[k]; });
      status(job, size(sum) + ' de ' + size(f.size) + (s.resumed ? ' · retomado' : ''), sum * 100 / f.size);
    }
    show();
    var todo = [];
    for (var n = 1; n <= total; n++) if (!doneParts[n]) todo.push(n);
    var urls = {};
    function urlFor(n) {
      if (urls[n]) return Promise.resolve(urls[n]);
      var batch = todo.filter(function (m) { return m >= n && !urls[m]; }).slice(0, SIGN_BATCH);
      return api({ do: 'mp_sign', key: s.key, uploadId: s.uploadId, parts: batch }).then(must).then(function (j) {
        Object.keys(j.urls).forEach(function (m) { urls[m] = j.urls[m]; });
        return urls[n];
      });
    }
    function one(n, attempt) {
      if (job.cancelled) return Promise.reject(new Error('cancelado'));
      var blob = f.slice((n - 1) * ps, Math.min(f.size, n * ps));
      return urlFor(n).then(function (u) {
        return put(u, blob, null, function (l) { loaded[n] = l; show(); }, job);
      }).then(function (etag) {
        if (!etag) throw new Error('O R2 não mostrou o ETag. Falta «ExposeHeaders: ETag» na política CORS do bucket.');
        delete loaded[n];
        base += blob.size;
        doneParts[n] = etag;
        store(sk, rec);
        show();
      }, function (e) {
        delete loaded[n];
        if (job.cancelled || /ETag/.test(e.message) || attempt >= RETRIES) throw e;
        if (e.http === 403) delete urls[n];      // URL expirado: assinar outra vez
        return sleep(800 * Math.pow(2, attempt)).then(function () { return one(n, attempt + 1); });
      });
    }
    var idx = 0;
    function worker() {
      if (idx >= todo.length) return Promise.resolve();
      var n = todo[idx++];
      return one(n, 0).then(worker);
    }
    var ws = [];
    for (var w = 0; w < Math.min(PARALLEL, todo.length); w++) ws.push(worker());
    return Promise.all(ws).then(function () {
      if (job.cancelled) throw new Error('cancelado');
      status(job, 'A juntar as partes…', 100);
      var parts = Object.keys(doneParts).map(function (k) { return { n: +k, etag: doneParts[k] }; });
      return api({ do: 'mp_complete', key: s.key, uploadId: s.uploadId, parts: parts }).then(must);
    }).then(function () {
      store(sk);
      job.mp = null;
    }, function (e) {
      // Fica guardado (sessionStorage) para retomar escolhendo o mesmo ficheiro outra vez.
      if (!job.cancelled) e.message += ' Escolha o mesmo ficheiro outra vez para retomar.';
      throw e;
    });
  }

  // ── zona de arrastar e seletor ──────────────────────────
  var drop = document.getElementById('drive-drop');
  var input = document.getElementById('dfile');
  if (input) input.addEventListener('change', function () { addFiles(input.files); input.value = ''; });
  if (drop) {
    ['dragenter', 'dragover'].forEach(function (t) {
      drop.addEventListener(t, function (e) { e.preventDefault(); drop.classList.add('over'); });
    });
    ['dragleave', 'drop'].forEach(function (t) {
      drop.addEventListener(t, function (e) { e.preventDefault(); drop.classList.remove('over'); });
    });
    drop.addEventListener('drop', function (e) { if (e.dataTransfer && e.dataTransfer.files.length) addFiles(e.dataTransfer.files); });
  }
  // Largar fora da zona não abre o ficheiro no separador.
  window.addEventListener('dragover', function (e) { e.preventDefault(); });
  window.addEventListener('drop', function (e) { e.preventDefault(); });
  window.addEventListener('beforeunload', function (e) {
    if (running || pending.length) { e.preventDefault(); e.returnValue = ''; }
  });

  // ── nova pasta ──────────────────────────────────────────
  var mk = document.getElementById('drive-mkdir');
  if (mk) mk.addEventListener('submit', function (e) {
    e.preventDefault();
    var name = mk.elements.name.value.trim();
    if (!name) { say('Escreva o nome da pasta.', 'err'); return; }
    api({ do: 'mkdir', prefix: PREFIX, name: name }).then(function (j) {
      if (!j.ok) { say(j.error, 'err'); return; }
      location.href = pageNoDel();
    });
  });

  // ── abrir / descarregar / renomear / apagar ─────────────
  var modal = document.getElementById('drive-modal');
  var mBody = document.getElementById('drive-modal-body');
  function closeModal() { modal.hidden = true; mBody.textContent = ''; }
  document.getElementById('drive-modal-close').addEventListener('click', closeModal);
  modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) closeModal(); });

  root.addEventListener('click', function (e) {
    var a = e.target.closest('.js-open, .js-dl, .js-ren, .js-del');
    if (!a) return;
    e.preventDefault();
    var key = a.getAttribute('data-key');
    if (a.classList.contains('js-open')) {
      var kind = a.getAttribute('data-preview');
      if (!kind) return download(key);
      var w = kind === 'pdf' ? window.open('', '_blank') : null;
      api({ do: 'url', key: key, inline: true }).then(function (j) {
        if (!j.ok) { if (w) w.close(); say(j.error, 'err'); return; }
        if (w) { w.opener = null; w.location.href = j.url; return; }
        mBody.textContent = '';
        var m = kind === 'video' ? el('video') : kind === 'audio' ? el('audio') : el('img');
        if (kind !== 'image') { m.controls = true; m.autoplay = true; m.preload = 'metadata'; } else m.alt = a.textContent;
        m.src = j.url;
        mBody.appendChild(m);
        document.getElementById('drive-modal-title').textContent = a.textContent;
        modal.hidden = false;
      });
    } else if (a.classList.contains('js-dl')) {
      download(key);
    } else if (a.classList.contains('js-ren')) {
      var old = a.getAttribute('data-name');
      var name = window.prompt('Novo nome:', old);
      if (!name || name.trim() === old) return;
      say('A renomear…', 'ok');
      api({ do: 'rename', key: key, name: name.trim() }).then(function (j) {
        if (!j.ok) { say(j.error, 'err'); return; }
        location.href = pageNoDel();
      });
    } else if (a.classList.contains('js-del')) {
      a.disabled = true;
      api({ do: 'delete', key: key }).then(function (j) {
        if (!j.ok) { a.disabled = false; say(j.error, 'err'); return; }
        location.href = pageNoDel();
      });
    }
  });
  function download(key) {
    api({ do: 'url', key: key, inline: false }).then(function (j) {
      if (!j.ok) { say(j.error, 'err'); return; }
      location.href = j.url;   // Content-Disposition: attachment — a página fica
    });
  }
})();
