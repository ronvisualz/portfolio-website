/* topper. admin — Partilhar (§26.3): mostra o que o menu Partilhar do Android entregou ao service worker,
 * deixa escolher o destino e uma nota, e envia para capture_api com a sessão. Sem nada partilhado,
 * serve para guardar um link à mão. */
(function () {
  'use strict';
  var root = document.getElementById('share');
  if (!root) return;
  var CSRF = root.getAttribute('data-csrf'), API = '/admin/capture_api.php';
  var $ = function (s) { return root.querySelector(s); };
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var shared = null, files = [];

  function st(m, err) { var el = $('[data-st]'); el.textContent = m; el.style.color = err ? 'var(--danger)' : ''; }

  function loadShared() {
    if (!('caches' in window)) return Promise.resolve(null);
    return caches.open('topper-share').then(function (c) {
      return c.match('/__share/meta').then(function (r) {
        if (!r) return null;
        return r.json().then(function (meta) {
          return Promise.all(meta.files.map(function (f, i) { return c.match('/__share/file/' + i).then(function (x) { return x ? x.blob() : null; }); }))
            .then(function (blobs) { files = blobs.map(function (b, i) { return b ? new File([b], meta.files[i].name, { type: meta.files[i].type || b.type }) : null; }).filter(Boolean); return meta; });
        });
      });
    }).catch(function () { return null; });
  }
  function clearShared() { if ('caches' in window) caches.delete('topper-share').catch(function () {}); }

  function render() {
    var box = $('[data-items]'), h = '';
    if (!shared) {
      $('[data-manual]').hidden = false;
      box.innerHTML = '<p class="muted">Não chegou nada pelo menu Partilhar. Cole um link para o guardar, ou partilhe a partir de outra app.</p>';
      return;
    }
    files.forEach(function (f, i) {
      var isImg = /^image\//.test(f.type), isVid = /^video\//.test(f.type);
      h += '<figure class="share-it" data-i="' + i + '">' + (isImg ? '<img alt="" data-src="' + i + '">' : isVid ? '<video muted playsinline data-src="' + i + '"></video>' : '<span class="share-file">' + esc(f.name) + '</span>')
        + '<figcaption>' + esc(f.name) + ' · ' + Math.max(1, Math.round(f.size / 1024)) + ' KB</figcaption></figure>';
    });
    if (shared.url || shared.text) h += '<div class="share-link"><b>' + esc(shared.title || 'Link') + '</b>' + (shared.url ? '<a href="' + esc(shared.url) + '" target="_blank" rel="noopener">' + esc(shared.url) + '</a>' : '') + (shared.text && shared.text !== shared.url ? '<p>' + esc(shared.text) + '</p>' : '') + '</div>';
    box.innerHTML = h || '<p class="muted">A partilha chegou vazia.</p>';
    box.querySelectorAll('[data-src]').forEach(function (el) { el.src = URL.createObjectURL(files[+el.getAttribute('data-src')]); });
  }

  /** Um frame do vídeo (para as miniaturas e a análise). */
  function poster(file) {
    return new Promise(function (res) {
      var v = document.createElement('video'), url = URL.createObjectURL(file), done = false;
      var end = function (b) { if (done) return; done = true; URL.revokeObjectURL(url); res(b); };
      v.muted = true; v.playsInline = true; v.preload = 'auto'; v.src = url;
      v.onloadeddata = function () { v.currentTime = Math.min(1, (v.duration || 2) / 3); };
      v.onseeked = function () {
        var s = Math.min(1, 1280 / Math.max(v.videoWidth, 1)), cv = document.createElement('canvas');
        cv.width = Math.round(v.videoWidth * s); cv.height = Math.round(v.videoHeight * s);
        cv.getContext('2d').drawImage(v, 0, 0, cv.width, cv.height);
        cv.toBlob(end, 'image/jpeg', 0.86);
      };
      v.onerror = function () { end(null); };
      setTimeout(function () { end(null); }, 12000);
    });
  }

  function send(fields, file) {
    var fd = new FormData();
    Object.keys(fields).forEach(function (k) { if (fields[k] != null && fields[k] !== '') fd.append(k, fields[k]); });
    fd.append('csrf', CSRF);
    var go = function (prev) {
      if (file) fd.append('file', file, file.name);
      if (prev) fd.append('prev', prev, 'prev.jpg');
      return fetch(API + '?a=save', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-CSRF-Token': CSRF, 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); }, function () { throw new Error('Sem ligação. Tente outra vez.'); })
        .then(function (j) { if (!j.ok) throw new Error(j.error || 'Não foi possível guardar.'); return j; });
    };
    return file && /^video\//.test(file.type) ? poster(file).then(go) : go(null);
  }

  root.addEventListener('submit', function (e) {
    e.preventDefault();
    var board = $('#sh-dest').value, note = $('#sh-note').value.trim(), btn = $('[data-go]');
    var base = { board: board, note: note, via: 'android', title: shared ? shared.title : '', url: shared ? shared.url : $('#sh-url').value.trim(), text: shared ? shared.text : '' };
    var jobs = files.length ? files.map(function (f) { return function () { return send(Object.assign({}, base, { kind: 'file' }), f); }; })
      : (base.url || base.text) ? [function () { return send(Object.assign({}, base, { kind: base.url ? 'link' : 'text' }), null); }] : [];
    if (!jobs.length) { st('Cole um link ou partilhe alguma coisa.', true); return; }
    btn.disabled = true;
    st('A guardar…');
    var last = null, n = 0;
    jobs.reduce(function (p, fn) { return p.then(fn).then(function (j) { last = j; n++; }); }, Promise.resolve()).then(function () {
      clearShared();
      shared = null; files = [];
      $('[data-items]').innerHTML = '<p>' + esc(n > 1 ? n + ' itens guardados. ' : '') + esc(last.msg) + ' <a href="' + esc(last.board ? last.board.url : last.inbox) + '">Abrir</a></p>';
      $('#sh-note').value = ''; $('#sh-url').value = '';
      st('');
    }).catch(function (er) { st(er.message, true); }).then(function () { btn.disabled = false; });
  });

  fetch(API + '?a=me', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } }).then(function (r) { return r.json(); }).then(function (j) {
    if (!j.ok) { st(j.error, true); return; }
    var sel = $('#sh-dest');
    sel.innerHTML = '<option value="0">Caixa de entrada</option>' + j.boards.map(function (b) { return '<option value="' + b.id + '">' + esc((b.code ? b.code + ' · ' : '') + b.title) + '</option>'; }).join('');
    if (j.default) sel.value = String(j.default.id);
  }).catch(function () {});

  loadShared().then(function (m) { shared = m && (m.files.length || m.url || m.text) ? m : null; render(); });
})();
