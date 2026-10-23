/* topper. — Review (Centro Criativo, fase 2): a sala de uma peça, para a equipa e para o portal do cliente.
 *
 * Leitor ao frame (← → um frame, Shift ±1 s, J/K/L, I/O intervalo e repetir, timecode SMPTE com drop
 * frame), tira de miniaturas com os marcadores dos comentários, desenho vetorial por cima do frame
 * (seta, retângulo, círculo, mão livre, texto), pinos em imagens e páginas de PDF, comentários em fio
 * («Internos» e «Para o cliente» — o portal só recebe os segundos), comparar versões (lado a lado,
 * cortina, sobreposição) e, na equipa, carregar versões, exportar, enviar, pedir alterações e aprovar.
 *
 *   TopperReview.mount(el, cfg)   cfg: mode ('team'|'client'), lang, load(), poll(rev), call(a, params|FormData),
 *                                 exportUrl(v, fmt, clientOnly), edit, identity(), identify() (cliente)
 *   TopperReview.previews(file)   poster + tira (vídeo), páginas (PDF) ou forma de onda (áudio), feitos no browser antes de enviar
 *
 * Áudio (§27): o mesmo leitor no tempo, com a forma de onda no lugar da imagem e da tira (cc_audio.js),
 * comentários marcados no tempo (m:ss,d), sem desenho nem pinos; comparar = ouvir A ou B no mesmo ponto.
 */
(function () {
  'use strict';

  var L = {
    pt: {
      play: 'Reproduzir (espaço)', pause: 'Pausar (espaço)', prev: 'Frame anterior (←)', next: 'Frame seguinte (→)', speed: 'Velocidade',
      loop: 'Repetir o intervalo', setIn: 'Marcar a entrada (I)', setOut: 'Marcar a saída (O)', clearIO: 'Limpar o intervalo (X)', full: 'Ecrã inteiro',
      tcTip: 'Timecode SMPTE · clique para ver em frames', frTip: 'Frames · clique para ver o timecode',
      pen: 'Mão livre', arrow: 'Seta', rect: 'Retângulo', ellipse: 'Círculo', text: 'Texto', color: 'Cor', undoShape: 'Anular o último traço (Ctrl+Z)',
      clearDraw: 'Limpar o desenho', drawHint: 'Desenhe por cima do frame: o desenho vai com o comentário.', textPrompt: 'Texto:',
      comments: 'Comentários', history: 'Histórico', all: 'Todos', fClient: 'Para o cliente', fInternal: 'Internos', fOpen: 'Por resolver', fMine: 'Os meus',
      write: 'Escreva um comentário…', writeReply: 'Responder…', send: 'Comentar', reply: 'Responder', resolve: 'Resolver', reopen: 'Reabrir', del: 'Apagar',
      general: 'Geral', pin: 'Pino', page: 'Página', frame: 'Frame', interval: 'Intervalo', noAnchor: 'Comentário geral (sem frame)', anchorOn: 'Preso a este frame',
      draft: 'Rascunho', you: 'Você', team: 'topper.', client: 'Cliente', internal: 'Interno', toClient: 'Para o cliente', resolved: 'Resolvido',
      drawn: 'com desenho', empty: 'Ainda sem comentários nesta versão.', emptyF: 'Nada com este filtro.', allV: 'Todas as versões',
      sendAll: 'Enviar comentários', sendHint: 'Os comentários ficam em rascunho até «Enviar comentários»: seguem todos de uma vez, numa só ronda.',
      approve: 'Aprovar', approved: 'Aprovada', download: 'Descarregar', processing: 'A preparar no servidor (proxy, fps e timecode)…', queued: 'Na fila para preparar…',
      failed: 'Não foi possível preparar esta versão.', retry: 'Tentar outra vez', noVersion: 'Ainda não há versões desta peça.',
      apTitle: 'Aprovar', apText: 'Escreva o seu nome completo para confirmar a aprovação. Fica registado com a data e a versão.', apName: 'Nome completo',
      apCheck: 'Confirmo que aprovo esta versão.', apGo: 'Aprovar', cancel: 'Cancelar', close: 'Fechar',
      idTitle: 'Antes de comentar', idText: 'Diga-nos quem é: o nome aparece junto dos seus comentários.', idName: 'Nome', idEmail: 'E-mail', idGo: 'Continuar',
      compare: 'Comparar', side: 'Lado a lado', wipe: 'Cortina', over: 'Sobreposição', diff: 'Diferença', opacity: 'Opacidade',
      undo: 'Anular', deleted: 'Comentário apagado.', sent: 'Enviado.', pages: 'páginas', of: 'de', loading: 'A abrir…', offline: 'Sem ligação. Tente outra vez.',
      approvedBy: 'Aprovada por', on: 'em', versionShort: 'Versão', wmNote: 'Pré-visualização com marca de água',
      processingAu: 'A converter o áudio no servidor…', listen: 'Ouvir', listenTip: 'Mesmo ponto, troca entre A e B (teclas A e B)', seekTip: 'Clique para ir a esse ponto'
    },
    en: {
      play: 'Play (space)', pause: 'Pause (space)', prev: 'Previous frame (←)', next: 'Next frame (→)', speed: 'Speed',
      loop: 'Loop the range', setIn: 'Mark in (I)', setOut: 'Mark out (O)', clearIO: 'Clear the range (X)', full: 'Full screen',
      tcTip: 'SMPTE timecode · click to show frames', frTip: 'Frames · click to show timecode',
      pen: 'Freehand', arrow: 'Arrow', rect: 'Rectangle', ellipse: 'Circle', text: 'Text', color: 'Colour', undoShape: 'Undo last stroke (Ctrl+Z)',
      clearDraw: 'Clear the drawing', drawHint: 'Draw on the frame: the drawing goes with your comment.', textPrompt: 'Text:',
      comments: 'Comments', history: 'History', all: 'All', fClient: 'For the client', fInternal: 'Internal', fOpen: 'Open', fMine: 'Mine',
      write: 'Write a comment…', writeReply: 'Reply…', send: 'Comment', reply: 'Reply', resolve: 'Resolve', reopen: 'Reopen', del: 'Delete',
      general: 'General', pin: 'Pin', page: 'Page', frame: 'Frame', interval: 'Range', noAnchor: 'General comment (no frame)', anchorOn: 'On this frame',
      draft: 'Draft', you: 'You', team: 'topper.', client: 'Client', internal: 'Internal', toClient: 'For the client', resolved: 'Resolved',
      drawn: 'with drawing', empty: 'No comments on this version yet.', emptyF: 'Nothing with this filter.', allV: 'All versions',
      sendAll: 'Send comments', sendHint: 'Your comments stay as drafts until you press «Send comments»: they all go together, as one round.',
      approve: 'Approve', approved: 'Approved', download: 'Download', processing: 'Being prepared…', queued: 'Queued…',
      failed: 'This version could not be prepared.', retry: 'Try again', noVersion: 'No versions yet.',
      apTitle: 'Approve', apText: 'Type your full name to confirm the approval. It is recorded with the date and the version.', apName: 'Full name',
      apCheck: 'I confirm I approve this version.', apGo: 'Approve', cancel: 'Cancel', close: 'Close',
      idTitle: 'Before you comment', idText: 'Tell us who you are: your name appears next to your comments.', idName: 'Name', idEmail: 'Email', idGo: 'Continue',
      compare: 'Compare', side: 'Side by side', wipe: 'Wipe', over: 'Overlay', diff: 'Difference', opacity: 'Opacity',
      undo: 'Undo', deleted: 'Comment deleted.', sent: 'Sent.', pages: 'pages', of: 'of', loading: 'Opening…', offline: 'No connection. Please try again.',
      approvedBy: 'Approved by', on: 'on', versionShort: 'Version', wmNote: 'Watermarked preview',
      processingAu: 'Converting the audio…', listen: 'Listen', listenTip: 'Same point, switch between A and B (keys A and B)', seekTip: 'Click to go to that point'
    }
  };
  var COLORS = ['#FFC718', '#81CFE3', '#FBAE9C', '#FFFFFF', '#111111', '#2A7928', '#D9443A'];
  var SPEEDS = [0.25, 0.5, 1, 1.5, 2];

  // ── utilitários ─────────────────────────────────────────────
  function $(s, r) { return (r || document).querySelector(s); }
  function $$(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function clamp(v, a, b) { return Math.max(a, Math.min(b, v)); }
  function typing(el) { return el && (el.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(el.tagName)); }
  function h(tag, cls, html) { var e = document.createElement(tag); if (cls) e.className = cls; if (html != null) e.innerHTML = html; return e; }
  var IC = {
    play: '<path d="M7 4v16l13-8Z" fill="currentColor" stroke="none"/>', pause: '<path d="M7 4h3v16H7zM14 4h3v16h-3z" fill="currentColor" stroke="none"/>',
    prev: '<path d="M6 5v14M19 5 9 12l10 7Z"/>', next: '<path d="M18 5v14M5 5l10 7-10 7Z"/>', loop: '<path d="M17 2l4 4-4 4"/><path d="M3 11V9a3 3 0 0 1 3-3h15M7 22l-4-4 4-4"/><path d="M21 13v2a3 3 0 0 1-3 3H3"/>',
    pen: '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/>', arrow: '<path d="M5 19 19 5M9 5h10v10"/>',
    rect: '<rect x="4" y="6" width="16" height="12" rx="1"/>', ellipse: '<ellipse cx="12" cy="12" rx="9" ry="7"/>', text: '<path d="M5 6V4h14v2M12 4v16M9 20h6"/>',
    undo: '<path d="M9 14 4 9l5-5"/><path d="M4 9h11a5 5 0 0 1 0 10h-3"/>', trash: '<path d="M3 6h18M8 6V4h8v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>',
    full: '<path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7"/>', x: '<path d="M18 6 6 18M6 6l12 12"/>', check: '<path d="M20 6 9 17l-5-5"/>',
    bubble: '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>', pin: '<path d="M12 22s7-6.2 7-12a7 7 0 0 0-14 0c0 5.8 7 12 7 12Z"/><circle cx="12" cy="10" r="2.5"/>',
    clip: '<path d="m21.4 11.1-9.2 9.2a6 6 0 0 1-8.5-8.5l9.2-9.2a4 4 0 0 1 5.7 5.7l-9.2 9.2a2 2 0 0 1-2.8-2.8l8.5-8.5"/>',
    send: '<path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/>', swap: '<path d="m17 3 4 4-4 4M3 7h18M7 21l-4-4 4-4M21 17H3"/>',
    down: '<path d="M12 4v12M6 10l6 6 6-6M4 20h16"/>', eye: '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
    lock: '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>', chevL: '<path d="m15 18-6-6 6-6"/>', chevR: '<path d="m9 18 6-6-6-6"/>',
    dots: '<circle cx="5" cy="12" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="19" cy="12" r="1.5"/>'
  };
  function ico(n) { return '<svg class="ico" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' + (IC[n] || '') + '</svg>'; }
  // vídeo ou áudio: o que tem tempo
  function av(el) { return !!el && (el.tagName === 'VIDEO' || el.tagName === 'AUDIO'); }
  function timed(v) { return !!v && (v.kind === 'video' || v.kind === 'audio'); }
  function mss(t) { var d = Math.floor(Math.max(0, t) * 10 + 1e-6), s = Math.floor(d / 10); return Math.floor(s / 60) + ':' + p2(s % 60) + ',' + (d % 10); }
  function wave(v, bars) { var A = window.TopperAudio; return A ? A.waveSvg(A.peaks(v.peaks), bars) : ''; }

  // ── timecode SMPTE (o mesmo cálculo do servidor) ────────────
  function tcBase(fn, fd) { var f = fn / Math.max(1, fd), nom = Math.round(f); return [nom, Math.abs(f - nom) > 0.001 && (nom === 30 || nom === 60)]; }
  function tcFrames(tc, fn, fd) {
    var m = /^(\d{2}):(\d{2}):(\d{2})[:;.](\d{2})$/.exec(tc || ''); if (!m) return 0;
    var b = tcBase(fn, fd), f = ((+m[1] * 3600 + +m[2] * 60 + +m[3]) * b[0]) + +m[4];
    if (b[1]) { var mins = +m[1] * 60 + +m[2]; f -= (b[0] === 30 ? 2 : 4) * (mins - Math.floor(mins / 10)); }
    return f;
  }
  function p2(n) { return (n < 10 ? '0' : '') + n; }
  function tc(frame, fn, fd, start) {
    var b = tcBase(fn, fd), nom = b[0], t = Math.max(0, tcFrames(start || '00:00:00:00', fn, fd) + frame);
    if (b[1]) {
      var drop = nom === 30 ? 2 : 4, per10 = nom * 600 - drop * 9, perMin = nom * 60 - drop;
      var d = Math.floor(t / per10), m = t % per10;
      t += drop * 9 * d + (m > drop ? drop * Math.floor((m - drop) / perMin) : 0);
    }
    var ff = t % nom, s = Math.floor(t / nom);
    return p2(Math.floor(s / 3600) % 24) + ':' + p2(Math.floor(s / 60) % 60) + ':' + p2(s % 60) + (b[1] ? ';' : ':') + p2(ff);
  }

  // ── pré-visualizações no browser (antes de enviar) ──────────
  function canvasBlob(c, q) { return new Promise(function (res) { c.toBlob(function (b) { res(b); }, 'image/jpeg', q || 0.82); }); }
  function videoPreviews(file) {
    return new Promise(function (resolve) {
      var url = URL.createObjectURL(file), v = document.createElement('video'), done = false;
      v.muted = true; v.preload = 'auto'; v.playsInline = true; v.src = url;
      function finish(r) { if (done) return; done = true; URL.revokeObjectURL(url); resolve(r); }
      setTimeout(function () { finish(null); }, 45000);
      v.onerror = function () { finish(null); };
      function seek(t) { return new Promise(function (ok) { v.onseeked = function () { ok(); }; v.currentTime = t; }); }
      v.onloadedmetadata = function () {
        var dur = v.duration, w = v.videoWidth, hh = v.videoHeight;
        if (!dur || !isFinite(dur) || !w) { finish(null); return; }
        var pc = document.createElement('canvas'), sc = Math.min(1, 1280 / w);
        pc.width = Math.round(w * sc); pc.height = Math.round(hh * sc);
        var n = clamp(Math.round(dur), 8, 60), tw = 160, th = Math.max(2, Math.round(tw * hh / w / 2) * 2);
        var st = document.createElement('canvas'); st.width = tw * n; st.height = th;
        var out = { w: w, h: hh, dur: dur, strip_n: n };
        seek(Math.min(dur * 0.2, Math.max(0, dur - 0.1))).then(function () {
          pc.getContext('2d').drawImage(v, 0, 0, pc.width, pc.height);
          return canvasBlob(pc, 0.82);
        }).then(function (b) {
          out.poster = b;
          var i = 0, g = st.getContext('2d');
          function step() {
            if (i >= n) return canvasBlob(st, 0.78).then(function (sb) { out.strip = sb; finish(out); });
            return seek(Math.min(dur - 0.05, (i + 0.5) * dur / n)).then(function () { g.drawImage(v, i * tw, 0, tw, th); i++; return step(); });
          }
          return step();
        }).catch(function () { finish(out.poster ? out : null); });
      };
    });
  }
  function pdfPages(file, onProgress) {
    var base = (document.querySelector('script[src*="review.js"]') || {}).src || '/admin/assets/review.js';
    var dir = base.replace(/review\.js.*$/, 'vendor/pdfjs/');
    return import(dir + 'pdf.min.js').then(function (lib) {
      lib.GlobalWorkerOptions.workerSrc = dir + 'pdf.worker.min.js';
      return file.arrayBuffer().then(function (buf) { return lib.getDocument({ data: buf, isEvalSupported: false }).promise; });
    }).then(function (pdf) {
      var n = Math.min(pdf.numPages, 120), out = [], i = 1;
      function next() {
        if (i > n) return out;
        return pdf.getPage(i).then(function (pg) {
          var vp0 = pg.getViewport({ scale: 1 }), vp = pg.getViewport({ scale: Math.min(3, 1600 / vp0.width) });
          var c = document.createElement('canvas'); c.width = Math.round(vp.width); c.height = Math.round(vp.height);
          var g = c.getContext('2d'); g.fillStyle = '#fff'; g.fillRect(0, 0, c.width, c.height);
          return pg.render({ canvasContext: g, viewport: vp }).promise.then(function () { return canvasBlob(c, 0.85); });
        }).then(function (b) { out.push(b); if (onProgress) onProgress(i, n); i++; return next(); });
      }
      return next();
    });
  }
  function previews(file, onProgress) {
    var k = kindOfFile(file);
    if (k === 'video') return videoPreviews(file);
    if (k === 'pdf') return pdfPages(file, onProgress).then(function (pages) { return { pages: pages }; });
    if (k === 'audio' && window.TopperAudio) return window.TopperAudio.prep(file).then(function (r) { return r ? { dur: r.dur, poster: r.prev, peaks: r.peaks } : {}; });
    return Promise.resolve({});
  }
  function kindOfFile(f) {
    var n = (f.name || '').toLowerCase();
    if (f.type === 'application/pdf' || /\.pdf$/.test(n)) return 'pdf';
    if (/^image\//.test(f.type) || /\.(jpe?g|png|webp|gif|tiff?)$/.test(n)) return 'image';
    if (/^audio\//.test(f.type) || /\.(mp3|wav|m4a|aac|flac|ogg|aif|aiff)$/.test(n)) return 'audio';
    return 'video';
  }

  // ── a sala ──────────────────────────────────────────────────
  function mount(root, cfg) {
    var T = L[cfg.lang] || L.pt, TEAM = cfg.mode === 'team', EDIT = !!cfg.edit;
    var S = { data: null, vid: null, frame: 0, inF: null, outF: null, loop: false, rate: 1, showFrames: false, tool: null, color: '#FFC718',
              draft: { drawing: [], x: null, y: null, general: false }, sel: null, filter: 'all', tab: 'c', page: 1, cmp: null, reply: null, allV: false,
              rev: 0, att: [], rev_timer: null, busy: false };
    var media = null, mediaB = null, rvfc = 0, raf = 0, revTimer = 0;
    function toast(m, o) { if (cfg.toast) return cfg.toast(m, o); var t = window.topper && window.topper.toast; return t ? t(m, o) : (window.alert(m), { close: function () {} }); }
    function fail(e) { toast((e && e.message) || String(e), { err: true }); }

    root.classList.add('rv-room');
    root.innerHTML = '<div class="rv-loading" role="status"><span class="spin" aria-hidden="true"></span> ' + esc(T.loading) + '</div>';

    // ── dados ─────────────────────────────────────────────────
    function V() { return S.data && S.data.versions.filter(function (v) { return v.id === S.vid; })[0] || null; }
    function fps(v) { v = v || V(); return v ? v.fn / v.fd : 25; }
    function maxF(v) {
      v = v || V(); if (!v) return 0;
      if (v.frames > 0) return v.frames - 1;
      var d = media && media.duration && isFinite(media.duration) ? media.duration : v.dur;
      return Math.max(0, Math.floor(d * fps(v)) - 1);
    }
    function tcOf(f, v) { v = v || V(); return !v ? '' : v.kind === 'audio' ? mss(f / fps(v)) : tc(f, v.fn, v.fd, v.tc); }
    function isVideo(v) { v = v || V(); return v && v.kind === 'video'; }

    function load(keep) {
      return cfg.load().then(function (d) {
        var first = !S.data;
        var prev = V();
        S.data = d; S.rev = d.item.rev;
        if (!S.vid || !d.versions.some(function (v) { return v.id === S.vid; })) S.vid = d.item.current || (d.versions[0] && d.versions[0].id) || null;
        if (first) { build(); return; }
        var now = V();
        var mediaChanged = !prev || !now || prev.id !== now.id || prev.src !== now.src || prev.proc !== now.proc || prev.pages.length !== now.pages.length;
        renderHead(); renderVersions(); renderSide();
        if (mediaChanged && !S.cmp) renderViewer(keep); else { renderTimeline(); renderOverlay(); }
      });
    }
    function pollLoop() {
      clearTimeout(revTimer);
      revTimer = setTimeout(function () {
        if (document.hidden || !cfg.poll) { pollLoop(); return; }
        cfg.poll(S.rev).then(function (j) { if (j && j.changed && !S.busy) return load(true); }).catch(function () {}).then(pollLoop);
      }, TEAM ? 6000 : 9000);
    }

    // ── estrutura ─────────────────────────────────────────────
    function build() {
      root.innerHTML = '';
      var head = h('header', 'rv-head'), body = h('div', 'rv-body');
      var col = h('section', 'rv-col'), side = h('aside', 'rv-side');
      col.innerHTML = '<div class="rv-view" data-rv-view></div>'
        + '<div class="rv-ctrl" data-rv-ctrl></div>'
        + '<div class="rv-tl" data-rv-tl><div class="rv-strip"></div><div class="rv-io"></div><div class="rv-mks"></div><div class="rv-head-line"></div></div>'
        + '<div class="rv-pages" data-rv-pages hidden></div>'
        + '<div class="rv-vbar" data-rv-vbar></div>';
      body.appendChild(col); body.appendChild(side);
      root.appendChild(head); root.appendChild(body);
      S.el = { head: head, col: col, side: side, view: $('[data-rv-view]', col), ctrl: $('[data-rv-ctrl]', col), tl: $('[data-rv-tl]', col), pages: $('[data-rv-pages]', col), vbar: $('[data-rv-vbar]', col) };
      renderHead(); renderVersions(); renderSide(); renderViewer(); bindKeys(); bindTimeline(); pollLoop();
      window.addEventListener('resize', fit);
    }

    // ── cabeçalho ─────────────────────────────────────────────
    function chip(color, txt) { return '<span class="rv-st c-' + esc(color) + '">' + esc(txt) + '</span>'; }
    function renderHead() {
      var d = S.data, it = d.item, v = V(), hd = S.el.head;
      var left = '<div class="rv-title"><h2>' + esc(it.title) + '</h2><div class="rv-meta">' + chip(it.color, it.statusLabel)
        + (it.stageLabel ? '<span class="rv-tag">' + esc(it.stageLabel) + '</span>' : '')
        + (TEAM && it.lineLabel ? '<span class="rv-tag">' + esc(it.lineLabel) + '</span>' : '')
        + (it.approved ? '<span class="rv-ap" data-tip="' + esc(T.approvedBy + ' ' + it.approved.name + ' ' + T.on + ' ' + it.approved.at) + '">' + ico('check') + ' ' + esc(T.approved) + ' · ' + esc(it.approved.name) + '</span>' : '')
        + '</div></div>';
      var acts = '';
      if (TEAM) {
        var ready = v && v.proc === 'ready', sameApproved = it.approved && it.approved.version === (v && v.id);
        var cur = v && v.id === it.current;
        if (EDIT) acts += '<label class="btn ghost sm" data-tip="Carregar uma versão nova (o original vai para o Drive)">' + ico('down').replace('M12 4v12M6 10l6 6 6-6', 'M12 20V8M6 14l6-6 6 6') + ' Versão nova<input type="file" hidden data-rv-upload accept="video/*,image/*,audio/*,.pdf,.mov,.mxf,.mp4,.m4v,.aif,.aiff"></label>';
        acts += '<div class="rv-popw"><button type="button" class="btn ghost sm" data-rv-pop="exp"' + (v ? '' : ' disabled') + ' aria-haspopup="true">' + ico('down') + ' Exportar</button>'
          + '<div class="rv-pop" data-pop="exp" hidden><b>Comentários de ' + esc(v ? v.label : '') + '</b>'
          + '<label class="check"><input type="checkbox" data-rv-exp-client> Só os do cliente</label>'
          + ['pdf|PDF (para ler)', 'csv|CSV (folha de cálculo)', 'edl|EDL · marcadores DaVinci Resolve', 'xml|XML · Premiere Pro / DaVinci', 'avid|Avid · marcadores (texto)'].map(function (x) {
              var p = x.split('|'); return '<a href="#" data-rv-exp="' + p[0] + '">' + esc(p[1]) + '</a>'; }).join('')
          + '</div></div>';
        if (EDIT) {
          acts += '<div class="rv-popw"><button type="button" class="btn ghost sm" data-rv-pop="set" aria-haspopup="true" data-tip="Nome, etapa, frente, marca de água e download">' + ico('dots') + ' Peça</button>'
            + '<div class="rv-pop wide" data-pop="set" hidden>' + settingsHtml() + '</div></div>';
        }
        if (d.drive) acts += '<a class="btn ghost sm" href="' + esc(d.drive) + '" data-tip="Os originais desta peça no Drive">Drive</a>';
        if (EDIT && v) {
          acts += '<span class="rv-sep"></span>';
          if (cur && !sameApproved) acts += '<button type="button" class="btn sm" data-rv-send' + (ready ? '' : ' disabled') + ' data-tip="' + esc(d.portal ? 'Aparece no portal do cliente e abre uma ronda' : 'Abre uma ronda. Falta criar o link do portal na ficha do negócio') + '">' + ico('send') + ' Enviar ao cliente</button>';
          if (cur && it.status !== 'changes' && !sameApproved) acts += '<button type="button" class="btn ghost sm" data-rv-changes>Pedir alterações</button>';
          if (cur && !sameApproved) acts += '<button type="button" class="btn dark sm" data-rv-approve' + (ready ? '' : ' disabled') + '>' + ico('check') + ' Aprovar versão</button>';
        }
      } else {
        if (it.download && v) acts += '<button type="button" class="btn ghost sm" data-rv-dl>' + ico('down') + ' ' + esc(T.download) + '</button>';
        var canAp = v && v.id === it.current && v.proc === 'ready' && !(it.approved && it.approved.version === v.id);
        if (canAp) acts += '<button type="button" class="btn sm" data-rv-approve>' + ico('check') + ' ' + esc(T.approve) + ' ' + esc(v.label) + '</button>';
      }
      hd.innerHTML = left + '<div class="rv-acts">' + acts + '</div>';
    }
    function settingsHtml() {
      var d = S.data, it = d.item, st = cfg.stages || {};
      var stages = Object.keys(st).map(function (k) { return '<option value="' + k + '"' + (k === it.stage ? ' selected' : '') + '>' + esc(st[k]) + '</option>'; }).join('');
      var lines = (d.deal.lines || []).length > 1 ? '<label class="field"><span>Frente</span><select data-rv-set="line"><option value="0">—</option>'
        + d.deal.lines.map(function (l) { return '<option value="' + l.id + '"' + (l.id === it.line ? ' selected' : '') + '>' + esc(l.name) + '</option>'; }).join('') + '</select></label>' : '';
      return '<label class="field"><span>Nome</span><input type="text" maxlength="200" value="' + esc(it.title) + '" data-rv-set="title"></label>'
        + '<label class="field"><span>Etapa de aprovação</span><select data-rv-set="stage">' + stages + '</select></label>' + lines
        + '<label class="check"><input type="checkbox" data-rv-set="watermark"' + (it.watermark ? ' checked' : '') + '> Marca de água no portal <span class="muted small">(logótipo topper. e nome do cliente)</span></label>'
        + '<label class="check"><input type="checkbox" data-rv-set="download"' + (it.download ? ' checked' : '') + '> O cliente pode descarregar o original</label>'
        + '<p class="muted small">Muda já, para as próximas visitas ao portal.</p>';
    }

    // ── barra das versões ─────────────────────────────────────
    function renderVersions() {
      var d = S.data, vb = S.el.vbar, it = d.item;
      if (!d.versions.length) { vb.innerHTML = '<span class="rv-up" data-rv-up hidden></span>'; return; }
      var counts = {};
      d.comments.forEach(function (c) { if (!c.parent) counts[c.version] = (counts[c.version] || 0) + 1; });
      var toks = d.versions.slice().reverse().map(function (v) {
        var cls = 'rv-tok' + (v.id === S.vid ? ' on' : '') + (v.id === it.current ? ' cur' : '');
        var flag = v.proc === 'ready' ? '' : (v.proc === 'error' ? ' <span class="rv-flag bad" aria-label="erro">!</span>' : ' <span class="spin sm" aria-hidden="true"></span>');
        var ap = it.approved && it.approved.version === v.id ? ' ' + ico('check') : '';
        var tip = v.label + ' · ' + v.at + (TEAM ? (v.sent ? ' · enviada ao cliente' : ' · ainda não enviada') : '') + (counts[v.id] ? ' · ' + counts[v.id] + ' ' + T.comments.toLowerCase() : '');
        return '<button type="button" class="' + cls + '" data-rv-v="' + v.id + '" data-tip="' + esc(tip) + '">' + esc(v.label) + (v.id === it.current ? ' · ' + (cfg.lang === 'en' ? 'current' : 'atual') : '') + ap + flag
          + (counts[v.id] ? '<em>' + counts[v.id] + '</em>' : '') + '</button>';
      }).join('');
      var ready = d.versions.filter(function (v) { return v.proc === 'ready'; }).length;
      vb.innerHTML = '<div class="rv-toks">' + toks + '</div>'
        + (ready > 1 ? '<button type="button" class="chip-btn" data-rv-cmp>' + ico('swap') + ' ' + esc(T.compare) + '</button>' : '')
        + '<span class="rv-up" data-rv-up hidden></span>';
    }

    // ── o leitor ──────────────────────────────────────────────
    function stopMedia() {
      [media, mediaB].forEach(function (m) { if (m && m.pause) { try { m.pause(); } catch (e) {} } });
      if (rvfc && media && media.cancelVideoFrameCallback) media.cancelVideoFrameCallback(rvfc);
      cancelAnimationFrame(raf); clearInterval(S.rev_timer); rvfc = 0; raf = 0; S.rev_timer = null;
      media = null; mediaB = null;
    }
    function renderViewer(keep) {
      var v = V(), view = S.el.view, keepFrame = keep ? S.frame : 0;
      stopMedia();
      S.el.auw = null;
      S.cmp = null;
      root.classList.toggle('rv-cmp-on', false);
      view.innerHTML = '';
      S.el.pages.hidden = true;
      if (!keep) { S.inF = null; S.outF = null; S.loop = false; S.page = 1; S.draft = { drawing: [], x: null, y: null, general: false }; S.sel = null; }
      if (!v) { view.innerHTML = '<div class="rv-empty">' + esc(T.noVersion) + (TEAM && EDIT ? '<br><span class="muted small">Carregue a primeira versão em «Versão nova».</span>' : '') + '</div>'; renderCtrl(); renderTimeline(); return; }
      var box = h('div', 'rv-box'), inner = h('div', 'rv-media');
      box.appendChild(inner); view.appendChild(box);
      if (v.proc !== 'ready') {
        inner.innerHTML = (v.poster ? '<img src="' + esc(v.poster) + '" alt="">' : '') + '<div class="rv-proc">'
          + (v.proc === 'error' ? '<b>' + esc(T.failed) + '</b>' + (TEAM && v.err ? '<span>' + esc(v.err) + '</span>' : '') + (TEAM && EDIT && timed(v) && S.data.ffmpeg ? '<button type="button" class="btn sm" data-rv-retry>' + esc(T.retry) + '</button>' : '')
            : '<span class="spin" aria-hidden="true"></span> ' + esc(v.proc === 'queued' ? T.queued : (v.kind === 'audio' ? T.processingAu : T.processing))) + '</div>';
        S.aspect = v.w && v.h ? v.h / v.w : 9 / 16;
        fit(); renderCtrl(); renderTimeline(); return;
      }
      if (timed(v)) {
        var isAu = v.kind === 'audio';
        media = document.createElement(isAu ? 'audio' : 'video');
        media.preload = 'auto'; media.src = v.src;
        if (isAu) {
          // áudio: a forma de onda grande (clique = ir a esse ponto), sem desenho nem pinos
          S.el.auw = h('div', 'rv-auw', wave(v, 240));
          S.el.auw.setAttribute('data-tip', T.seekTip);
          S.el.auw.addEventListener('pointerdown', function (e) { var r = S.el.auw.getBoundingClientRect(); if (playing()) togglePlay(false); seekFrame(clamp((e.clientX - r.left) / r.width, 0, 1) * (maxF() + 1)); });
          inner.classList.add('rv-au'); inner.appendChild(S.el.auw);
        } else {
          media.playsInline = true; if (v.poster) media.poster = v.poster;
          media.setAttribute('playsinline', ''); media.disablePictureInPicture = true;
        }
        media.controlsList = 'nodownload noremoteplayback';
        media.addEventListener('contextmenu', function (e) { if (!TEAM) e.preventDefault(); });
        inner.appendChild(media);
        S.aspect = isAu ? 0.3 : (v.w && v.h ? v.h / v.w : 9 / 16);
        media.addEventListener('loadedmetadata', function () {
          if (media.videoWidth) S.aspect = media.videoHeight / media.videoWidth;
          fit(); renderTimeline(); seekFrame(keepFrame);
        });
        media.addEventListener('seeked', function () { if (!playing()) { S.frame = curFrame(); syncB(); paint(); } });
        media.addEventListener('play', function () { trackFrames(); renderCtrlState(); });
        media.addEventListener('pause', function () { S.frame = curFrame(); paint(); renderCtrlState(); });
        media.addEventListener('ended', function () { renderCtrlState(); });
        media.addEventListener('error', function () {
          // o URL curto expirou (sessões longas): volta a pedir, no mesmo frame
          var f = S.frame; if (media && !media.__retry) { media.__retry = 1; media.src = v.src + (v.src.indexOf('?') > -1 ? '&' : '?') + 'r=' + Date.now(); media.load(); setTimeout(function () { seekFrame(f); }, 400); }
        });
      } else {
        media = document.createElement('img');
        media.alt = '';
        media.draggable = false;
        if (v.kind === 'pdf') {
          S.page = clamp(S.page, 1, Math.max(1, v.pages.length));
          var pg = v.pages[S.page - 1];
          media.src = pg ? pg.url : v.poster;
          S.aspect = pg && pg.w ? pg.h / pg.w : (v.h && v.w ? v.h / v.w : 1.414);
          renderPages();
        } else {
          media.src = v.src;
          S.aspect = v.w && v.h ? v.h / v.w : 0.75;
        }
        media.addEventListener('load', function () { if (media.naturalWidth) S.aspect = media.naturalHeight / media.naturalWidth; fit(); });
        inner.appendChild(media);
      }
      if (v.kind === 'audio') { S.el.svg = null; S.el.inner = inner; fit(); renderCtrl(); renderTimeline(); paint(); return; }
      S.el.auw = null;
      // a marca de água por cima (só enquanto a gravada no vídeo não está pronta): logótipo topper. e o cliente
      if (cfg.wmText) inner.appendChild(h('div', 'rv-wm', '<img src="/admin/assets/wm-logo.png" alt="">' + '<span>' + esc(cfg.wmText) + '</span>'));
      var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
      svg.setAttribute('class', 'rv-ov'); inner.appendChild(svg);
      S.el.svg = svg; S.el.inner = inner;
      bindOverlay(svg);
      fit(); renderCtrl(); renderTimeline(); paint();
    }
    function fit() {
      var view = S.el && S.el.view; if (!view) return;
      $$('.rv-box', view).forEach(function (box) {
        var inner = $('.rv-media', box); if (!inner) return;
        var W = box.clientWidth, H = box.clientHeight || W * 0.5625, a = S.aspect || 0.5625;
        var w = Math.min(W, H / a);
        inner.style.width = Math.floor(w) + 'px'; inner.style.height = Math.floor(w * a) + 'px';
      });
      paint();
    }
    function playing() { return av(media) && !media.paused && !media.ended; }
    function curFrame() { return av(media) ? clamp(Math.floor(media.currentTime * fps() + 1e-3), 0, maxF()) : 0; }
    function seekFrame(f) {
      if (!av(media)) return;
      f = clamp(Math.round(f), 0, maxF());
      S.frame = f;
      try { media.currentTime = (f + 0.5) / fps(); } catch (e) {}
      syncB(true);
      paint();
    }
    function syncB(force) {
      if (!av(mediaB) || !media) return;
      if (force || Math.abs(mediaB.currentTime - media.currentTime) > 1.5 / fps()) { try { mediaB.currentTime = media.currentTime; } catch (e) {} }
    }
    function trackFrames() {
      if (!av(media)) return;
      if (media.requestVideoFrameCallback) {
        var cb = function (now, meta) {
          if (!media) return;
          S.frame = clamp(Math.floor(meta.mediaTime * fps() + 1e-3), 0, maxF());
          loopCheck(); syncB(); paintLight();
          if (playing()) rvfc = media.requestVideoFrameCallback(cb);
        };
        rvfc = media.requestVideoFrameCallback(cb);
      } else {
        var tick = function () { if (!playing()) return; S.frame = curFrame(); loopCheck(); syncB(); paintLight(); raf = requestAnimationFrame(tick); };
        raf = requestAnimationFrame(tick);
      }
    }
    function loopCheck() {
      if (!S.loop || S.inF == null || S.outF == null) return;
      if (S.frame >= S.outF || S.frame < S.inF - 1) seekFrame(S.inF);
    }
    function togglePlay(force) {
      if (!av(media)) return;
      stopReverse();
      var go = force != null ? force : media.paused;
      if (go) {
        if (S.loop && S.inF != null && S.outF != null && (S.frame >= S.outF || S.frame < S.inF)) seekFrame(S.inF);
        else if (media.ended) seekFrame(0);
        media.playbackRate = S.rate; var p = media.play(); if (p && p.catch) p.catch(function () {});
        if (av(mediaB)) { mediaB.playbackRate = S.rate; mediaB.muted = !(S.cmp && S.cmp.listen === 'B'); media.muted = !!(S.cmp && S.cmp.listen === 'B'); var q = mediaB.play(); if (q && q.catch) q.catch(function () {}); }
      } else {
        media.pause(); if (mediaB && mediaB.pause) mediaB.pause();
        seekFrame(curFrame());
      }
    }
    function step(n) { if (!av(media)) return; stopReverse(); if (playing()) { media.pause(); if (mediaB) mediaB.pause(); } seekFrame(S.frame + n); }
    function stopReverse() { if (S.rev_timer) { clearInterval(S.rev_timer); S.rev_timer = null; renderCtrlState(); } }
    function reverse() {
      // J: o vídeo não toca para trás no browser; anda frame a frame
      if (!av(media)) return;
      if (playing()) { media.pause(); if (mediaB) mediaB.pause(); }
      var mult = S.rev_timer ? Math.min(4, (S.rev_mult || 1) * 2) : 1;
      stopReverse(); S.rev_mult = mult;
      S.rev_timer = setInterval(function () { if (S.frame <= 0) { stopReverse(); return; } seekFrame(S.frame - 1); }, Math.max(16, 1000 / (fps() * mult)));
      renderCtrlState();
    }
    function forwardL() {
      if (!av(media)) return;
      if (S.rev_timer) { stopReverse(); }
      if (playing()) { S.rate = Math.min(4, S.rate * 2); media.playbackRate = S.rate; if (mediaB) mediaB.playbackRate = S.rate; renderCtrl(); }
      else { S.rate = SPEEDS.indexOf(S.rate) > -1 ? S.rate : 1; togglePlay(true); }
    }

    // ── controlos ─────────────────────────────────────────────
    function renderCtrl() {
      var v = V(), c = S.el.ctrl, vid = timed(v) && v.proc === 'ready', au = v && v.kind === 'audio';
      var tools = (TEAM || cfg.canComment !== false) && !au ? ['pen', 'arrow', 'rect', 'ellipse', 'text'].map(function (t) {
        return '<button type="button" class="rv-b' + (S.tool === t ? ' on' : '') + '" data-rv-tool="' + t + '" aria-pressed="' + (S.tool === t) + '" data-tip="' + esc(T[t]) + '" aria-label="' + esc(T[t]) + '">' + ico(t) + '</button>';
      }).join('') + '<span class="rv-colors">' + COLORS.map(function (col) {
        return '<button type="button" class="rv-sw' + (S.color === col ? ' on' : '') + '" style="--c:' + col + '" data-rv-color="' + col + '" aria-label="' + esc(T.color) + ' ' + col + '"></button>';
      }).join('') + '</span><button type="button" class="rv-b" data-rv-undo-shape data-tip="' + esc(T.undoShape) + '" aria-label="' + esc(T.undoShape) + '"' + (S.draft.drawing.length ? '' : ' disabled') + '>' + ico('undo') + '</button>' : '';
      if (!v || v.proc !== 'ready') { c.innerHTML = ''; return; }
      var left = '';
      if (vid) {
        left = '<button type="button" class="rv-b" data-rv-prev data-tip="' + esc(T.prev) + '" aria-label="' + esc(T.prev) + '">' + ico('prev') + '</button>'
          + '<button type="button" class="rv-b rv-play" data-rv-play aria-label="' + esc(T.play) + '" data-tip="' + esc(T.play) + '">' + ico('play') + '</button>'
          + '<button type="button" class="rv-b" data-rv-next data-tip="' + esc(T.next) + '" aria-label="' + esc(T.next) + '">' + ico('next') + '</button>'
          + '<select class="rv-speed" data-rv-speed aria-label="' + esc(T.speed) + '" data-tip="' + esc(T.speed) + ' · J K L">' + SPEEDS.map(function (s) { return '<option value="' + s + '"' + (s === S.rate ? ' selected' : '') + '>' + String(s).replace('.', ',') + '×</option>'; }).join('')
          + (SPEEDS.indexOf(S.rate) < 0 ? '<option selected>' + S.rate + '×</option>' : '') + '</select>'
          + '<button type="button" class="rv-b rv-io-b" data-rv-in data-tip="' + esc(T.setIn) + '">I</button>'
          + '<button type="button" class="rv-b rv-io-b" data-rv-out data-tip="' + esc(T.setOut) + '">O</button>'
          + '<button type="button" class="rv-b' + (S.loop ? ' on' : '') + '" data-rv-loop aria-pressed="' + S.loop + '" data-tip="' + esc(T.loop) + '"' + (S.inF != null && S.outF != null ? '' : ' disabled') + '>' + ico('loop') + '</button>'
          + (S.inF != null || S.outF != null ? '<button type="button" class="rv-b" data-rv-clear data-tip="' + esc(T.clearIO) + '" aria-label="' + esc(T.clearIO) + '">' + ico('x') + '</button>' : '')
          + '<button type="button" class="rv-tc" data-rv-tc aria-live="off"></button>'
          + (au ? (TEAM && v.err ? '<span class="rv-fps mono" data-tip="' + esc(v.err) + '">⚠</span>' : '')
            : '<span class="rv-fps mono" data-tip="' + esc(v.err && TEAM ? v.err : 'fps') + '">' + (Math.round(fps(v) * 1000) / 1000).toString().replace('.', ',') + ' fps' + (TEAM && v.err ? ' ⚠' : '') + '</span>');
      } else if (v.kind === 'pdf') {
        left = '<button type="button" class="rv-b" data-rv-pg="-1" aria-label="' + esc(T.page) + ' −">' + ico('chevL') + '</button><span class="rv-tc static">' + esc(T.page) + ' ' + S.page + ' ' + esc(T.of) + ' ' + v.pages.length + '</span>'
          + '<button type="button" class="rv-b" data-rv-pg="1" aria-label="' + esc(T.page) + ' +">' + ico('chevR') + '</button>';
      }
      c.innerHTML = '<div class="rv-cl">' + left + '</div><div class="rv-cr">' + (S.cmp ? '' : tools)
        + '<button type="button" class="rv-b" data-rv-full data-tip="' + esc(T.full) + '" aria-label="' + esc(T.full) + '">' + ico('full') + '</button></div>';
      renderCtrlState();
    }
    function renderCtrlState() {
      var p = $('[data-rv-play]', S.el.ctrl);
      if (p) { var on = playing() || !!S.rev_timer; p.innerHTML = ico(on ? 'pause' : 'play'); p.setAttribute('aria-label', on ? T.pause : T.play); p.setAttribute('data-tip', on ? T.pause : T.play); }
      paintLight();
    }
    function paintLight() {
      var v = V(); if (!v || !S.el) return;
      var t = $('[data-rv-tc]', S.el.ctrl);
      if (t) {
        if (v.kind === 'audio') { t.textContent = tcOf(S.frame) + ' / ' + mss((maxF() + 1) / fps(v)); t.removeAttribute('data-tip'); }
        else {
          t.textContent = S.showFrames ? String(S.frame).padStart(String(maxF()).length, '0') + ' / ' + maxF() : tcOf(S.frame);
          t.setAttribute('data-tip', S.showFrames ? T.frTip : T.tcTip);
        }
      }
      var tl = S.el.tl, n = maxF() + 1;
      var pct = (n > 1 ? (S.frame + 0.5) / n * 100 : 0) + '%';
      var hl = $('.rv-head-line', tl); if (hl && timed(v)) hl.style.left = pct;
      if (v.kind === 'audio') { tl.style.setProperty('--p', pct); if (S.el.auw) S.el.auw.style.setProperty('--p', pct); }
      updateAnchor();
    }
    function paint() { paintLight(); renderOverlay(); }

    // ── barra do tempo ────────────────────────────────────────
    function renderTimeline() {
      var v = V(), tl = S.el.tl;
      var show = timed(v) && v.proc === 'ready';
      tl.hidden = !show;
      if (!show) return;
      var strip = $('.rv-strip', tl);
      tl.classList.toggle('au', v.kind === 'audio');
      if (v.kind === 'audio') {   // a forma de onda, com os comentários marcados no tempo por cima
        if (strip.getAttribute('data-v') !== String(v.id)) { strip.innerHTML = wave(v, 200); strip.setAttribute('data-v', v.id); }
        strip.style.backgroundImage = 'none'; strip.classList.remove('one');
      } else {
        strip.innerHTML = ''; strip.removeAttribute('data-v');
        strip.style.backgroundImage = v.strip ? 'url("' + v.strip.url.replace(/"/g, '%22') + '")' : (v.poster ? 'url("' + v.poster.replace(/"/g, '%22') + '")' : 'none');
        strip.classList.toggle('one', !v.strip);
      }
      var n = maxF() + 1, io = $('.rv-io', tl);
      if (S.inF != null || S.outF != null) {
        var a = S.inF != null ? S.inF : 0, b = S.outF != null ? S.outF : n - 1;
        io.hidden = false; io.style.left = (a / n * 100) + '%'; io.style.width = (Math.max(1, b - a + 1) / n * 100) + '%';
      } else io.hidden = true;
      var mk = '';
      visibleComments().forEach(function (c) {
        if (c.parent || c.fi == null || c.version !== v.id) return;
        var w = c.fo != null ? (c.fo - c.fi + 1) / n * 100 : 0;
        mk += '<button type="button" class="rv-mk ' + (c.resolved ? 'res' : (c.client ? 'cli' : (c.vis === 'client' ? 'tcl' : 'int'))) + (S.sel === c.id ? ' sel' : '') + (w ? ' rng' : '')
          + '" style="left:' + (c.fi / n * 100) + '%' + (w ? ';width:' + w + '%' : '') + '" data-rv-mk="' + c.id + '" data-tip="' + esc((c.tc || '') + ' · ' + c.who + ': ' + c.body.slice(0, 80)) + '" aria-label="' + esc(c.tc || '') + '"></button>';
      });
      $('.rv-mks', tl).innerHTML = mk;
      paintLight();
    }
    function bindTimeline() {
      var tl = S.el.tl, drag = false;
      function at(e) { var r = tl.getBoundingClientRect(); return clamp(Math.floor((e.clientX - r.left) / r.width * (maxF() + 1)), 0, maxF()); }
      tl.addEventListener('pointerdown', function (e) {
        if (e.target.closest('[data-rv-mk]')) return;
        if (!av(media)) return;
        drag = true; tl.setPointerCapture(e.pointerId);
        if (playing()) { media.pause(); if (mediaB) mediaB.pause(); }
        seekFrame(at(e));
      });
      tl.addEventListener('pointermove', function (e) { if (drag) seekFrame(at(e)); });
      tl.addEventListener('pointerup', function () { drag = false; });
      tl.addEventListener('pointercancel', function () { drag = false; });
    }

    // ── páginas (PDF) ─────────────────────────────────────────
    function renderPages() {
      var v = V(), p = S.el.pages;
      if (!v || v.kind !== 'pdf' || v.pages.length < 2) { p.hidden = true; return; }
      p.hidden = false;
      var pins = {};
      visibleComments().forEach(function (c) { if (!c.parent && c.version === v.id && c.page) pins[c.page] = (pins[c.page] || 0) + 1; });
      p.innerHTML = v.pages.map(function (pg, i) {
        return '<button type="button" class="rv-pg' + (i + 1 === S.page ? ' on' : '') + '" data-rv-page="' + (i + 1) + '" aria-label="' + esc(T.page) + ' ' + (i + 1) + '"><img src="' + esc(pg.url) + '" alt="" loading="lazy"><span>' + (i + 1) + '</span>'
          + (pins[i + 1] ? '<em>' + pins[i + 1] + '</em>' : '') + '</button>';
      }).join('');
    }
    function goPage(n) {
      var v = V(); if (!v || v.kind !== 'pdf') return;
      n = clamp(n, 1, v.pages.length); if (n === S.page) return;
      S.page = n; S.draft.x = null; S.draft.y = null; S.draft.drawing = [];
      if (S.cmp) { renderCompare(); return; }
      var pg = v.pages[n - 1]; media.src = pg.url; S.aspect = pg.w ? pg.h / pg.w : S.aspect;
      fit(); renderCtrl(); renderPages(); paint();
    }

    // ── desenho e pinos ───────────────────────────────────────
    function vbH() { return 1000 * (S.aspect || 0.5625); }
    function shapeSvg(sh, extra) {
      var H = vbH(), p = sh.p.map(function (n, i) { return i % 2 ? n * H : n * 1000; }), c = sh.c || '#FFC718', s = 'stroke="' + c + '" fill="none" stroke-width="5" stroke-linecap="round" stroke-linejoin="round"' + (extra || '');
      if (sh.t === 'rect') return '<rect x="' + Math.min(p[0], p[2]) + '" y="' + Math.min(p[1], p[3]) + '" width="' + Math.abs(p[2] - p[0]) + '" height="' + Math.abs(p[3] - p[1]) + '" rx="4" ' + s + '/>';
      if (sh.t === 'ellipse') return '<ellipse cx="' + (p[0] + p[2]) / 2 + '" cy="' + (p[1] + p[3]) / 2 + '" rx="' + Math.abs(p[2] - p[0]) / 2 + '" ry="' + Math.abs(p[3] - p[1]) / 2 + '" ' + s + '/>';
      if (sh.t === 'arrow') {
        var dx = p[2] - p[0], dy = p[3] - p[1], a = Math.atan2(dy, dx), l = 26;
        var h1 = [p[2] - l * Math.cos(a - 0.45), p[3] - l * Math.sin(a - 0.45)], h2 = [p[2] - l * Math.cos(a + 0.45), p[3] - l * Math.sin(a + 0.45)];
        return '<path d="M' + p[0] + ' ' + p[1] + 'L' + p[2] + ' ' + p[3] + 'M' + h1[0] + ' ' + h1[1] + 'L' + p[2] + ' ' + p[3] + 'L' + h2[0] + ' ' + h2[1] + '" ' + s + '/>';
      }
      if (sh.t === 'path') {
        var d = 'M' + p[0] + ' ' + p[1];
        for (var i = 2; i < p.length; i += 2) d += 'L' + p[i] + ' ' + p[i + 1];
        return '<path d="' + d + '" ' + s + '/>';
      }
      if (sh.t === 'text') {
        return '<text x="' + p[0] + '" y="' + p[1] + '" fill="' + c + '" font-size="34" font-weight="700" font-family="Inter, system-ui, sans-serif" paint-order="stroke" stroke="rgba(0,0,0,.55)" stroke-width="6" stroke-linejoin="round">' + esc(sh.s || '') + '</text>';
      }
      return '';
    }
    function commentsHere() {
      var v = V(); if (!v) return [];
      return visibleComments().filter(function (c) {
        if (c.parent || c.version !== v.id) return false;
        if (timed(v)) return c.fi != null && (c.fo != null ? S.frame >= c.fi && S.frame <= c.fo : S.frame === c.fi);
        if (v.kind === 'pdf') return (c.page || 1) === S.page;
        return true;
      });
    }
    function renderOverlay() {
      var svg = S.el && S.el.svg, v = V(); if (!svg || !v || S.cmp) return;
      var H = vbH();
      svg.setAttribute('viewBox', '0 0 1000 ' + H);
      var out = '', here = commentsHere(), paused = !playing() && !S.rev_timer;
      // os desenhos: o do comentário escolhido, ou os deste frame (em pausa)
      here.forEach(function (c) {
        if (!c.drawing || (!paused && v.kind === 'video')) return;
        if (S.sel && S.sel !== c.id) return;
        out += '<g class="rv-d' + (S.sel === c.id ? ' sel' : '') + '">' + c.drawing.map(function (sh) { return shapeSvg(sh); }).join('') + '</g>';
      });
      // os pinos (imagem / página)
      if (!timed(v)) {
        var idx = numbering();
        here.forEach(function (c) {
          if (c.x == null) return;
          out += '<g class="rv-pin ' + (c.resolved ? 'res' : (c.client ? 'cli' : (c.vis === 'client' ? 'tcl' : 'int'))) + (S.sel === c.id ? ' sel' : '') + '" data-rv-pin="' + c.id + '" transform="translate(' + c.x * 1000 + ' ' + c.y * H + ')">'
            + '<circle r="20"/><text text-anchor="middle" dy="7" font-size="20" font-weight="700" font-family="Inter, sans-serif">' + (idx[c.id] || '•') + '</text></g>';
        });
        if (S.draft.x != null) out += '<g class="rv-pin new" transform="translate(' + S.draft.x * 1000 + ' ' + S.draft.y * H + ')"><circle r="20"/><text text-anchor="middle" dy="7" font-size="22" font-weight="700">+</text></g>';
      }
      out += S.draft.drawing.map(function (sh) { return shapeSvg(sh); }).join('');
      if (S.drawing) out += shapeSvg(S.drawing);
      svg.innerHTML = out;
      svg.classList.toggle('tool', !!S.tool);
    }
    function numbering() {
      var v = V(), idx = {}, n = 0;
      sortedTop().forEach(function (c) { if (c.version === v.id) idx[c.id] = ++n; });
      return idx;
    }
    function bindOverlay(svg) {
      function pt(e) { var r = svg.getBoundingClientRect(); return [clamp((e.clientX - r.left) / r.width, -0.05, 1.05), clamp((e.clientY - r.top) / r.height, -0.05, 1.05)]; }
      svg.addEventListener('pointerdown', function (e) {
        var pin = e.target.closest && e.target.closest('[data-rv-pin]');
        if (pin && !S.tool) { select(+pin.getAttribute('data-rv-pin'), true); return; }
        var v = V(); if (!v) return;
        if (!S.tool) {
          if (v.kind === 'video') { togglePlay(); return; }
          if (!canComment()) return;
          var q = pt(e);
          S.draft.x = clamp(q[0], 0, 1); S.draft.y = clamp(q[1], 0, 1); S.draft.general = false; S.sel = null;
          paint(); focusComposer(); return;
        }
        if (!canComment()) return;
        if (playing()) togglePlay(false);
        stopReverse();
        var a = pt(e);
        if (S.tool === 'text') { askText(e, a); return; }
        svg.setPointerCapture(e.pointerId);
        S.drawing = { t: S.tool, c: S.color, p: S.tool === 'path' || S.tool === 'pen' ? [a[0], a[1]] : [a[0], a[1], a[0], a[1]] };
        if (S.tool === 'pen') S.drawing.t = 'path';
        renderOverlay();
      });
      svg.addEventListener('pointermove', function (e) {
        if (!S.drawing) return;
        var a = pt(e);
        if (S.drawing.t === 'path') { var p = S.drawing.p, lx = p[p.length - 2], ly = p[p.length - 1]; if (Math.abs(a[0] - lx) + Math.abs(a[1] - ly) > 0.004 && p.length < 1200) p.push(a[0], a[1]); }
        else { S.drawing.p[2] = a[0]; S.drawing.p[3] = a[1]; }
        renderOverlay();
      });
      function end() {
        if (!S.drawing) return;
        var d = S.drawing, p = d.p; S.drawing = null;
        var big = d.t === 'path' ? p.length >= 4 : Math.abs(p[2] - p[0]) + Math.abs(p[3] - p[1]) > 0.01;
        if (big) { S.draft.drawing.push(d); S.draft.general = false; }
        renderCtrl(); renderOverlay(); updateAnchor();
        if (big) focusComposer(true);
      }
      svg.addEventListener('pointerup', end); svg.addEventListener('pointercancel', end);
    }
    function askText(e, a) {
      var inner = S.el.inner, r = inner.getBoundingClientRect();
      var inp = h('input', 'rv-txt'); inp.type = 'text'; inp.maxLength = 120; inp.placeholder = T.textPrompt;
      inp.style.left = (e.clientX - r.left) + 'px'; inp.style.top = (e.clientY - r.top) + 'px';
      inner.appendChild(inp); inp.focus();
      var done = false;
      function commit(ok) {
        if (done) return; done = true;
        var s = inp.value.trim(); inp.remove();
        if (ok && s) { S.draft.drawing.push({ t: 'text', c: S.color, p: [a[0], a[1]], s: s }); S.draft.general = false; renderCtrl(); renderOverlay(); updateAnchor(); }
      }
      inp.addEventListener('keydown', function (k) { if (k.key === 'Enter') { k.preventDefault(); commit(true); } else if (k.key === 'Escape') { k.preventDefault(); commit(false); } k.stopPropagation(); });
      inp.addEventListener('blur', function () { commit(true); });
    }

    // ── comentários ───────────────────────────────────────────
    function canComment() { return TEAM ? EDIT : cfg.canComment !== false; }
    function visibleComments() {
      return S.data.comments.filter(function (c) {
        if (S.filter === 'client') return c.client || c.vis === 'client';
        if (S.filter === 'internal') return !c.client && c.vis !== 'client';
        if (S.filter === 'open') return !c.resolved;
        return true;
      });
    }
    function sortedTop() {
      var v = V();
      return S.data.comments.filter(function (c) { return !c.parent && (S.allV || !v || c.version === v.id); }).sort(function (a, b) {
        var pa = a.page || 0, pb = b.page || 0;
        if (pa !== pb) return pa - pb;
        var fa = a.fi == null ? -1 : a.fi, fb = b.fi == null ? -1 : b.fi;
        return fa !== fb ? fa - fb : a.id - b.id;
      });
    }
    function bodyHtml(s) {
      var t = esc(s);
      if (TEAM) t = t.replace(/@([\wÀ-ÿ][\wÀ-ÿ.\-]*)/g, '<b class="rv-at">@$1</b>');
      return t.replace(/\n/g, '<br>');
    }
    function anchorLabel(c) {
      if (c.tc) return c.tc;
      if (c.x != null) return (c.page ? T.page + ' ' + c.page + ' · ' : '') + T.pin;
      if (c.page) return T.page + ' ' + c.page;
      return T.general;
    }
    function renderSide() {
      var d = S.data, sd = S.el.side, v = V();
      var keepText = ($('[data-rv-text]', sd) || {}).value || '', keepVis = S.vis || 'internal';
      var html = '';
      if (TEAM && d.rounds) {
        var r = d.rounds, cur = r.done + (r.open ? 1 : 0), over = r.included != null && cur > r.included, at = r.included != null && cur === r.included;
        html += '<div class="rv-rounds' + (over ? ' over' : (at ? ' at' : '')) + '">' + ico('bubble') + '<div><b>'
          + (r.included != null ? 'Ronda ' + Math.max(cur, 0) + ' de ' + r.included + ' incluídas' : (cur ? cur + (cur === 1 ? ' ronda' : ' rondas') + ' nesta etapa' : 'Ainda sem rondas'))
          + '</b>' + (r.stage ? ' · ' + esc(r.stage) : '')
          + (r.open ? '<br><span class="small">Ronda aberta: à espera dos comentários do cliente.</span>' : '')
          + (over ? '<br><span class="small">Acima do incluído: o Comercial foi avisado e há uma tarefa «Pedido de alteração».</span>'
            : (at ? '<br><span class="small">A próxima ronda passa do incluído → aviso ao Comercial e pedido de alteração.</span>' : ''))
          + '</div></div>';
      }
      if (TEAM) {
        html += '<nav class="rv-tabs" role="tablist"><button type="button" role="tab" data-rv-tab="c" aria-selected="' + (S.tab === 'c') + '">' + esc(T.comments) + ' <em>' + d.comments.filter(function (c) { return !c.parent && (!v || c.version === v.id); }).length + '</em></button>'
          + '<button type="button" role="tab" data-rv-tab="h" aria-selected="' + (S.tab === 'h') + '">' + esc(T.history) + '</button></nav>';
      } else {
        html += '<h3 class="rv-side-h">' + esc(T.comments) + '</h3>';
      }
      if (TEAM && S.tab === 'h') {
        html += '<ol class="tline rv-log">' + (d.log || []).map(function (l) {
          return '<li><span class="hi c-' + esc(l.c) + '" role="img" aria-label="' + esc(l.tip) + '" data-tip="' + esc(l.tip) + '" tabindex="0">' + (window.TopperIcons && window.TopperIcons[l.icon] || ico(l.icon === 'upload' ? 'down' : (l.icon === 'send' ? 'send' : (l.icon === 'check' ? 'check' : (l.icon === 'bubble' ? 'bubble' : 'eye'))))) + '</span>'
            + '<div><div class="meta">' + esc(l.when) + '</div><div class="what">' + esc(l.txt) + '</div></div></li>';
        }).join('') + '</ol>'
          + (d.tasks && d.tasks.length ? '<p class="muted small">Aprovar conclui no Pipeline: ' + d.tasks.map(esc).join(', ') + '.</p>' : '');
        sd.innerHTML = html; return;
      }
      var filters = TEAM ? [['all', T.all], ['client', T.fClient], ['internal', T.fInternal], ['open', T.fOpen]] : [];
      if (filters.length) html += '<div class="rv-filt">' + filters.map(function (f) { return '<button type="button" class="chip-btn' + (S.filter === f[0] ? ' on' : '') + '" data-rv-filter="' + f[0] + '">' + esc(f[1]) + '</button>'; }).join('')
        + (d.versions.length > 1 ? '<label class="check small"><input type="checkbox" data-rv-allv' + (S.allV ? ' checked' : '') + '> ' + esc(T.allV) + '</label>' : '') + '</div>';
      var tops = sortedTop().filter(function (c) { return visibleComments().indexOf(c) > -1; });
      var idx = numbering();
      html += '<div class="rv-list" data-rv-list>';
      if (!tops.length) html += '<p class="rv-none muted">' + esc(sortedTop().length ? T.emptyF : T.empty) + '</p>';
      tops.forEach(function (c) { html += commentHtml(c, idx[c.id]); });
      html += '</div>';
      if (canComment() && v && v.proc === 'ready') html += composerHtml(keepText);
      if (!TEAM) {
        var drafts = d.comments.filter(function (c) { return c.draft; }).length;
        html += '<div class="rv-submit"><p class="muted small">' + esc(T.sendHint) + '</p><button type="button" class="btn" data-rv-submit' + (drafts ? '' : ' disabled') + '>' + ico('send') + ' ' + esc(T.sendAll) + (drafts ? ' (' + drafts + ')' : '') + '</button></div>';
      }
      sd.innerHTML = html;
      S.vis = keepVis; setVis(keepVis);
      updateAnchor();
      renderAtt();
    }
    function commentHtml(c, num) {
      var reps = S.data.comments.filter(function (r) { return r.parent === c.id; }).sort(function (a, b) { return a.id - b.id; });
      var tag = '';
      if (TEAM) tag = c.client ? '<span class="rv-tg cli">' + esc(T.client) + '</span>' : (c.vis === 'client' ? '<span class="rv-tg tcl" data-tip="O cliente vê este comentário no portal">' + esc(T.toClient) + '</span>' : '<span class="rv-tg int" data-tip="Só a equipa vê">' + ico('lock') + ' ' + esc(T.internal) + '</span>');
      else if (c.draft) tag = '<span class="rv-tg draft">' + esc(T.draft) + '</span>';
      var v = V(), other = v && c.version !== v.id ? (S.data.versions.filter(function (x) { return x.id === c.version; })[0] || {}).label : '';
      var acts = '';
      if (canComment() && !c.draft) acts += '<button type="button" class="lnk" data-rv-reply="' + c.id + '">' + esc(T.reply) + '</button>';
      if (TEAM && EDIT) acts += '<button type="button" class="lnk" data-rv-resolve="' + c.id + '" data-on="' + (c.resolved ? 0 : 1) + '">' + esc(c.resolved ? T.reopen : T.resolve) + '</button>';
      if (TEAM && EDIT && !c.client && (c.mine || S.data.admin)) acts += '<button type="button" class="lnk" data-rv-vis="' + c.id + '" data-vis="' + (c.vis === 'client' ? 'internal' : 'client') + '">' + (c.vis === 'client' ? 'Tornar interno' : 'Mostrar ao cliente') + '</button>';
      if ((TEAM && EDIT && !c.client && (c.mine || S.data.admin)) || (!TEAM && c.draft)) acts += '<button type="button" class="lnk danger" data-rv-del="' + c.id + '">' + esc(T.del) + '</button>';
      var att = TEAM && c.att && c.att.length ? '<div class="rv-att">' + c.att.map(function (a, i) { return '<a href="/admin/rv_api.php?a=att&amp;c=' + c.id + '&amp;i=' + i + '" target="_blank" rel="noopener">' + ico('clip') + ' ' + esc(a.n) + '</a>'; }).join('') + '</div>' : '';
      return '<article class="rv-c' + (c.client ? ' is-cli' : '') + (c.resolved ? ' res' : '') + (S.sel === c.id ? ' sel' : '') + (c.draft ? ' draft' : '') + '" data-rv-c="' + c.id + '">'
        + '<header><button type="button" class="rv-anc" data-rv-go="' + c.id + '">' + (num && c.x != null ? '<i>' + num + '</i>' : '') + esc(anchorLabel(c)) + (other ? ' · ' + esc(other) : '') + '</button>'
        + '<b class="rv-who">' + esc(c.who) + '</b>' + tag + (c.resolved ? '<span class="rv-ok" data-tip="' + esc(T.resolved) + '">' + ico('check') + '</span>' : '') + '<span class="rv-when">' + esc(c.at) + '</span></header>'
        + (c.body ? '<div class="rv-txtb">' + bodyHtml(c.body) + '</div>' : '') + (c.drawing ? '<span class="rv-drawn">' + ico('pen') + ' ' + esc(T.drawn) + '</span>' : '') + att
        + (acts ? '<footer>' + acts + '</footer>' : '')
        + (reps.length ? '<div class="rv-reps">' + reps.map(function (r) {
            var ra = (TEAM && EDIT && !r.client && (r.mine || S.data.admin)) || (!TEAM && r.draft) ? '<button type="button" class="lnk danger" data-rv-del="' + r.id + '">' + esc(T.del) + '</button>' : '';
            return '<div class="rv-rep' + (r.draft ? ' draft' : '') + '"><header><b class="rv-who">' + esc(r.who) + '</b>' + (TEAM && r.client ? '<span class="rv-tg cli">' + esc(T.client) + '</span>' : '') + (!TEAM && r.draft ? '<span class="rv-tg draft">' + esc(T.draft) + '</span>' : '')
              + '<span class="rv-when">' + esc(r.at) + '</span></header><div class="rv-txtb">' + bodyHtml(r.body) + '</div>' + (ra ? '<footer>' + ra + '</footer>' : '') + '</div>';
          }).join('') + '</div>' : '')
        + (S.reply === c.id ? '<form class="rv-replyf" data-rv-replyf="' + c.id + '"><textarea rows="2" maxlength="4000" placeholder="' + esc(T.writeReply) + '" data-rv-reply-text></textarea><div><button type="submit" class="btn sm">' + esc(T.reply) + '</button> <button type="button" class="btn ghost sm" data-rv-reply-x>' + esc(T.cancel) + '</button></div></form>' : '')
        + '</article>';
    }
    function composerHtml(text) {
      return '<form class="rv-comp" data-rv-comp>'
        + '<div class="rv-comp-top"><button type="button" class="rv-anchor" data-rv-anchor></button>'
        + (TEAM ? '<div class="rv-vis" role="group" aria-label="Quem vê"><button type="button" data-rv-setvis="internal">' + ico('lock') + ' Interno</button><button type="button" data-rv-setvis="client">' + ico('eye') + ' Para o cliente</button></div>' : '') + '</div>'
        + '<div class="rv-ta"><textarea rows="3" maxlength="4000" placeholder="' + esc(T.write) + '" data-rv-text>' + esc(text) + '</textarea><div class="rv-ment" data-rv-ment hidden></div></div>'
        + '<div class="rv-att-list" data-rv-att-list></div>'
        + '<div class="rv-comp-bot">' + (TEAM ? '<label class="rv-b" data-tip="Anexar ficheiro (vai para o Drive)">' + ico('clip') + '<input type="file" hidden multiple data-rv-attach></label>' : '')
        + '<span class="muted small rv-hint">' + (S.tool ? esc(T.drawHint) : '') + '</span><button type="submit" class="btn sm">' + esc(T.send) + '</button></div></form>';
    }
    function setVis(vis) {
      S.vis = vis;
      $$('[data-rv-setvis]', S.el.side).forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-rv-setvis') === vis); b.setAttribute('aria-pressed', String(b.getAttribute('data-rv-setvis') === vis)); });
    }
    function anchorNow() {
      var v = V(); if (!v) return {};
      if (S.draft.general && !S.draft.drawing.length) return { label: T.noAnchor };
      if (timed(v)) {
        if (S.inF != null && S.outF != null && S.outF > S.inF) return { fi: S.inF, fo: S.outF, label: T.interval + ' ' + tcOf(S.inF) + '–' + tcOf(S.outF) };
        return { fi: S.frame, label: tcOf(S.frame) };
      }
      var o = { label: '' };
      if (v.kind === 'pdf') { o.page = S.page; o.label = T.page + ' ' + S.page; }
      if (S.draft.x != null) { o.x = S.draft.x; o.y = S.draft.y; o.label = (o.label ? o.label + ' · ' : '') + T.pin; }
      if (!o.label) o.label = T.general;
      return o;
    }
    function updateAnchor() {
      var b = $('[data-rv-anchor]', S.el.side); if (!b) return;
      var a = anchorNow();
      b.innerHTML = ico(S.draft.general ? 'bubble' : (isVideo() ? 'pin' : 'pin')) + ' <span>' + esc(a.label || T.general) + '</span>' + (S.draft.drawing.length ? ' · ' + ico('pen') + S.draft.drawing.length : '')
        + (!S.draft.general ? ' <i aria-hidden="true">×</i>' : '');
      b.setAttribute('data-tip', S.draft.general ? T.anchorOn : T.noAnchor);
      var hint = $('.rv-hint', S.el.side); if (hint) hint.textContent = S.tool ? T.drawHint : '';
    }
    function focusComposer(keepTool) {
      var t = $('[data-rv-text]', S.el.side); if (t) t.focus({ preventScroll: false });
      if (!keepTool) {}
    }
    function renderAtt() {
      var l = $('[data-rv-att-list]', S.el.side); if (!l) return;
      l.innerHTML = S.att.map(function (a, i) { return '<span class="chip">' + ico('clip') + ' ' + esc(a.n) + (a.p != null && a.p < 1 ? ' · ' + Math.round(a.p * 100) + '%' : '') + ' <button type="button" class="lnk" data-rv-att-x="' + i + '" aria-label="Tirar">×</button></span>'; }).join('');
    }
    function select(id, seek) {
      var c = S.data.comments.filter(function (x) { return x.id === id; })[0]; if (!c) return;
      S.sel = S.sel === id && !seek ? null : id;
      if (c.version !== S.vid) { S.vid = c.version; renderVersions(); renderViewer(); }
      var v = V();
      if (timed(v) && c.fi != null) { if (playing()) togglePlay(false); seekFrame(c.fi); }
      if (v && v.kind === 'pdf' && c.page && c.page !== S.page) goPage(c.page);
      $$('.rv-c', S.el.side).forEach(function (a) { a.classList.toggle('sel', +a.getAttribute('data-rv-c') === S.sel); });
      var card = $('.rv-c[data-rv-c="' + id + '"]', S.el.side); if (card && seek) card.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
      renderTimeline(); paint();
    }
    function postComment(form) {
      var ta = $('[data-rv-text]', form), body = ta.value.trim(), v = V();
      if (!body && !S.draft.drawing.length) { ta.focus(); return; }
      if (S.att.some(function (a) { return !a.k; })) { toast('Espere que os anexos acabem de carregar.'); return; }
      var go = function () {
        var a = anchorNow(), p = { item: S.data.item.id, v: v.id, body: body, vis: TEAM ? S.vis || 'internal' : 'client' };
        if (!S.draft.general || S.draft.drawing.length) { ['fi', 'fo', 'x', 'y', 'page'].forEach(function (k) { if (a[k] != null) p[k] = a[k]; }); }
        else if (v.kind === 'pdf') p.page = S.page;
        if (S.draft.drawing.length) p.drawing = JSON.stringify(S.draft.drawing);
        if (S.att.length) p.att = JSON.stringify(S.att.map(function (x) { return { k: x.k }; }));
        S.busy = true;
        var btn = $('button[type=submit]', form); if (btn) btn.disabled = true;
        return cfg.call('comment_add', p).then(function (j) {
          S.data.comments = j.comments; ta.value = ''; S.draft = { drawing: [], x: null, y: null, general: false }; S.att = []; S.tool = null;
          renderSide(); renderVersions(); renderCtrl(); renderTimeline(); paint();
          if (!TEAM && j.msg) toast(j.msg);
        }).catch(fail).then(function () { S.busy = false; if (btn) btn.disabled = false; });
      };
      if (!TEAM && cfg.identity && !cfg.identity()) { cfg.identify().then(function (ok) { if (ok) go(); }); return; }
      go();
    }
    function postReply(form) {
      var id = +form.getAttribute('data-rv-replyf'), ta = $('[data-rv-reply-text]', form), body = ta.value.trim(), c = S.data.comments.filter(function (x) { return x.id === id; })[0];
      if (!body || !c) return;
      var go = function () {
        return cfg.call('comment_add', { item: S.data.item.id, v: c.version, body: body, parent: id, vis: TEAM ? c.vis : 'client' }).then(function (j) {
          S.data.comments = j.comments; S.reply = null; renderSide(); renderTimeline();
        }).catch(fail);
      };
      if (!TEAM && cfg.identity && !cfg.identity()) { cfg.identify().then(function (ok) { if (ok) go(); }); return; }
      go();
    }
    // @menções (equipa)
    function mentionUpdate(ta) {
      var box = $('[data-rv-ment]', S.el.side); if (!box || !TEAM) return;
      var before = ta.value.slice(0, ta.selectionStart), m = /@([^\s@]{0,30})$/.exec(before);
      if (!m) { box.hidden = true; return; }
      var q = m[1].toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
      var us = (S.data.users || []).filter(function (u) { return u.name.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').indexOf(q) === 0 || u.name.toLowerCase().indexOf(' ' + q) > -1; }).slice(0, 6);
      if (!us.length) { box.hidden = true; return; }
      box.hidden = false; S.mentIdx = 0;
      box.innerHTML = us.map(function (u, i) { return '<button type="button" class="' + (i === 0 ? 'on' : '') + '" data-rv-ment-u="' + esc(u.name) + '">' + esc(u.name) + '</button>'; }).join('');
    }
    function mentionPick(name) {
      var ta = $('[data-rv-text]', S.el.side), box = $('[data-rv-ment]', S.el.side); if (!ta) return;
      var pos = ta.selectionStart, before = ta.value.slice(0, pos).replace(/@([^\s@]{0,30})$/, '@' + name + ' ');
      ta.value = before + ta.value.slice(pos); ta.focus(); ta.selectionStart = ta.selectionEnd = before.length;
      if (box) box.hidden = true;
    }

    // ── comparar ──────────────────────────────────────────────
    function openCompare() {
      var ready = S.data.versions.filter(function (v) { return v.proc === 'ready' && v.kind === (V() || {}).kind; });
      if (ready.length < 2) { toast('Precisa de duas versões prontas do mesmo tipo.'); return; }
      // A: a mais antiga das duas; B: a mais recente (a vista agora e a vizinha mais próxima)
      var cur = V() && V().proc === 'ready' ? V() : ready[0];
      var other = ready.filter(function (v) { return v.id !== cur.id; }).sort(function (x, y) { return Math.abs(x.num - cur.num) - Math.abs(y.num - cur.num) || y.num - x.num; })[0];
      var a = cur.num < other.num ? cur : other, b = a === cur ? other : cur;
      S.cmp = { a: a.id, b: b.id, mode: 'side', pos: 50, op: 0.5, diff: false };
      renderCompare();
    }
    function closeCompare() { S.cmp = null; renderViewer(true); }
    function renderCompare() {
      var c = S.cmp, view = S.el.view, keep = S.frame;
      stopMedia();
      root.classList.add('rv-cmp-on');
      var va = S.data.versions.filter(function (v) { return v.id === c.a; })[0], vb = S.data.versions.filter(function (v) { return v.id === c.b; })[0];
      var opts = function (sel) { return S.data.versions.filter(function (v) { return v.proc === 'ready' && v.kind === va.kind; }).map(function (v) { return '<option value="' + v.id + '"' + (v.id === sel ? ' selected' : '') + '>' + esc(v.label) + '</option>'; }).join(''); };
      view.innerHTML = '<div class="rv-cmp-bar"><label>A <select data-rv-ca>' + opts(c.a) + '</select></label><label>B <select data-rv-cb>' + opts(c.b) + '</select></label>'
        + '<div class="rv-seg" role="group">' + [['side', T.side], ['wipe', T.wipe], ['over', T.over]].map(function (m) { return '<button type="button" data-rv-cmode="' + m[0] + '" class="' + (c.mode === m[0] ? 'on' : '') + '" aria-pressed="' + (c.mode === m[0]) + '">' + esc(m[1]) + '</button>'; }).join('') + '</div>'
        + (c.mode === 'over' ? '<label class="rv-op">' + esc(T.opacity) + ' <input type="range" min="0" max="1" step="0.01" value="' + c.op + '" data-rv-op></label><label class="check small"><input type="checkbox" data-rv-diff' + (c.diff ? ' checked' : '') + '> ' + esc(T.diff) + '</label>' : '')
        + '<button type="button" class="btn ghost sm" data-rv-cmp-x>' + ico('x') + ' ' + esc(T.close) + '</button></div>'
        + '<div class="rv-cmp-stage m-' + c.mode + '" data-rv-cstage></div>';
      var stage = $('[data-rv-cstage]', view);
      function pane(v, lab) {
        var box = h('div', 'rv-box'), inner = h('div', 'rv-media'), el;
        if (v.kind === 'audio') { el = document.createElement('audio'); el.preload = 'auto'; el.src = v.src; el.muted = lab === 'B'; inner.className = 'rv-media rv-au'; inner.appendChild(h('div', 'rv-auw', wave(v, 200))); }
        else if (v.kind === 'video') { el = document.createElement('video'); el.playsInline = true; el.preload = 'auto'; el.src = v.src; el.muted = lab === 'B'; }
        else { el = document.createElement('img'); el.src = v.kind === 'pdf' ? (v.pages[S.page - 1] || v.pages[0] || {}).url || v.poster : v.src; el.alt = ''; }
        inner.appendChild(el); inner.appendChild(h('span', 'rv-lbl', esc(lab + ' · ' + v.label)));
        box.appendChild(inner);
        return [box, el];
      }
      var A = pane(va, 'A'), B = pane(vb, 'B');
      if (va.kind === 'audio') {
        // áudio: as duas formas de onda; ouve-se uma de cada vez, sempre no mesmo ponto
        c.listen = c.listen || 'A';
        $('.rv-seg', view).outerHTML = '<div class="rv-seg" role="group" aria-label="' + esc(T.listen) + '" data-tip="' + esc(T.listenTip) + '">' + ['A', 'B'].map(function (k) { return '<button type="button" data-rv-listen="' + k + '" class="' + (c.listen === k ? 'on' : '') + '" aria-pressed="' + (c.listen === k) + '">' + esc(T.listen) + ' ' + k + '</button>'; }).join('') + '</div>';
        stage.className = 'rv-cmp-stage m-au';
        [A, B].forEach(function (x, i) { x[0].classList.toggle('on', c.listen === (i ? 'B' : 'A')); stage.appendChild(x[0]); });
        A[1].muted = c.listen !== 'A'; B[1].muted = c.listen !== 'B';
        [A, B].forEach(function (x) {
          var w = $('.rv-auw', x[0]);
          w.addEventListener('pointerdown', function (e) { var r = w.getBoundingClientRect(); seekFrame(clamp((e.clientX - r.left) / r.width, 0, 1) * (maxF() + 1)); });
        });
      } else if (c.mode === 'side') { stage.appendChild(A[0]); stage.appendChild(B[0]); }
      else {
        var wrap = h('div', 'rv-box rv-stack'), inner = h('div', 'rv-media');
        var ea = A[1], eb = B[1]; ea.className = 'a'; eb.className = 'b';
        inner.appendChild(ea); inner.appendChild(eb);
        inner.appendChild(h('span', 'rv-lbl', 'A · ' + esc(va.label))); inner.appendChild(h('span', 'rv-lbl r', 'B · ' + esc(vb.label)));
        if (c.mode === 'wipe') { eb.style.clipPath = 'inset(0 0 0 ' + c.pos + '%)'; inner.appendChild(h('div', 'rv-wipe', '<span></span>')); $('.rv-wipe', inner).style.left = c.pos + '%'; }
        else { eb.style.opacity = c.diff ? 1 : c.op; if (c.diff) eb.style.mixBlendMode = 'difference'; }
        wrap.appendChild(inner); stage.appendChild(wrap);
        if (c.mode === 'wipe') {
          var drag = false;
          inner.addEventListener('pointerdown', function (e) { drag = true; inner.setPointerCapture(e.pointerId); move(e); });
          inner.addEventListener('pointermove', function (e) { if (drag) move(e); });
          inner.addEventListener('pointerup', function () { drag = false; });
          var move = function (e) { var r = inner.getBoundingClientRect(); c.pos = clamp((e.clientX - r.left) / r.width * 100, 0, 100); eb.style.clipPath = 'inset(0 0 0 ' + c.pos + '%)'; $('.rv-wipe', inner).style.left = c.pos + '%'; };
        }
      }
      media = A[1]; mediaB = B[1];
      S.vid = va.id;
      S.aspect = va.kind === 'audio' ? 0.3 : (va.w && va.h ? va.h / va.w : 0.5625);
      if (av(media)) {
        media.addEventListener('loadedmetadata', function () { if (media.videoWidth) S.aspect = media.videoHeight / media.videoWidth; fit(); seekFrame(keep); });
        media.addEventListener('seeked', function () { if (!playing()) { S.frame = curFrame(); syncB(true); paintLight(); } });
        media.addEventListener('play', function () { trackFrames(); renderCtrlState(); });
        media.addEventListener('pause', function () { S.frame = curFrame(); syncB(true); renderCtrlState(); });
      } else { media.addEventListener('load', function () { if (media.naturalWidth) S.aspect = media.naturalHeight / media.naturalWidth; fit(); }); }
      renderVersions(); renderCtrl(); renderTimeline(); fit();
    }

    // ── ações da equipa ───────────────────────────────────────
    function upload(file) {
      var up = $('[data-rv-up]', S.el.vbar), kind = kindOfFile(file), it = S.data.item;
      function show(t) { if (up) { up.hidden = false; up.innerHTML = '<span class="spin sm" aria-hidden="true"></span> ' + esc(t); } }
      S.busy = true;
      show('A preparar ' + file.name + '…');
      var prev = previews(file, function (i, n) { show('A ler o PDF: página ' + i + ' de ' + n + '…'); }).catch(function (e) { if (kind === 'pdf') throw new Error('Não foi possível ler este PDF no browser: ' + (e.message || e)); return {}; });
      var init;
      return cfg.call('upload_init', { item: it.id, name: file.name, size: file.size, mime: file.type }).then(function (j) {
        init = j;
        return new Promise(function (res, rej) {
          var x = new XMLHttpRequest();
          x.open('PUT', j.put);
          if (file.type) x.setRequestHeader('Content-Type', file.type);
          x.upload.onprogress = function (e) { if (e.lengthComputable) show('A carregar para o Drive: ' + Math.round(e.loaded / e.total * 100) + '%'); };
          x.onload = function () { x.status >= 200 && x.status < 300 ? res() : rej(new Error('O Drive recusou o envio (' + x.status + ').')); };
          x.onerror = function () { rej(new Error(T.offline)); };
          x.send(file);
        });
      }).then(function () { show('A ler o ficheiro…'); return prev; }).then(function (p) {
        var fd = new FormData();
        fd.append('item', it.id); fd.append('key', init.key); fd.append('name', file.name); fd.append('mime', file.type || '');
        if (p && p.w) { fd.append('w', p.w); fd.append('h', p.h); fd.append('dur', p.dur); }
        else if (p && p.dur) fd.append('dur', p.dur);
        if (p && p.peaks) fd.append('peaks', p.peaks);
        if (p && p.poster) fd.append('poster', p.poster, 'poster.jpg');
        if (p && p.strip) { fd.append('strip', p.strip, 'strip.jpg'); fd.append('strip_n', p.strip_n); }
        if (p && p.pages) p.pages.forEach(function (b, i) { fd.append('pages[]', b, 'p' + (i + 1) + '.jpg'); });
        show('A guardar…');
        return cfg.call('upload_done', fd);
      }).then(function (j) {
        toast(j.msg); S.vid = j.version; return load();
      }).then(function () { S.vid = S.data.item.current; renderVersions(); renderViewer(); }).catch(fail).then(function () { S.busy = false; if (up) up.hidden = true; });
    }
    function attach(files) {
      Array.prototype.forEach.call(files, function (f) {
        var a = { n: f.name, p: 0 }; S.att.push(a); renderAtt();
        cfg.call('att_init', { item: S.data.item.id, name: f.name, size: f.size }).then(function (j) {
          return new Promise(function (res, rej) {
            var x = new XMLHttpRequest(); x.open('PUT', j.put); if (f.type) x.setRequestHeader('Content-Type', f.type);
            x.upload.onprogress = function (e) { if (e.lengthComputable) { a.p = e.loaded / e.total; renderAtt(); } };
            x.onload = function () { x.status < 300 ? (a.k = j.key, a.p = 1, renderAtt(), res()) : rej(new Error('O Drive recusou o anexo.')); };
            x.onerror = function () { rej(new Error(T.offline)); }; x.send(f);
          });
        }).catch(function (e) { S.att.splice(S.att.indexOf(a), 1); renderAtt(); fail(e); });
      });
    }
    function modal(html, onSubmit) {
      var m = h('div', 'mdl open rv-mdl'); m.setAttribute('role', 'dialog'); m.setAttribute('aria-modal', 'true');
      m.innerHTML = '<div class="mdl-back" data-x></div><form class="mdl-card" autocomplete="on">' + html + '</form>';
      document.body.appendChild(m);
      var f = $('form', m), prevFocus = document.activeElement;
      function close() { m.remove(); if (prevFocus && prevFocus.focus) prevFocus.focus(); }
      m.addEventListener('click', function (e) { if (e.target.closest('[data-x]')) close(); });
      m.addEventListener('keydown', function (e) { if (e.key === 'Escape') { e.stopPropagation(); close(); } });
      f.addEventListener('submit', function (e) { e.preventDefault(); var r = onSubmit(f); if (r && r.then) { var b = $('button[type=submit]', f); if (b) b.disabled = true; r.then(function (ok) { if (ok !== false) close(); else if (b) b.disabled = false; }, function (er) { if (b) b.disabled = false; fail(er); }); } else if (r !== false) close(); });
      setTimeout(function () { var i = $('input:not([type=checkbox]),textarea', f) || $('button', f); if (i) i.focus(); }, 30);
      return { close: close, el: m };
    }
    function approveTeam() {
      var v = V(), tasks = S.data.tasks || [];
      modal('<h3>Aprovar ' + esc(v.label) + '</h3><p class="muted">A peça fica <b>Aprovada</b>' + (tasks.length ? ', fica feita a tarefa <b>' + tasks.map(esc).join('</b>, <b>') + '</b> no Pipeline' : '') + ' e a versão entra na Biblioteca como referência da casa.</p>'
        + (tasks.length ? '' : '<p class="muted small">Não há uma tarefa de aprovação aberta para esta etapa (o mapa está em Definições › Centro Criativo).</p>')
        + '<div class="actions"><button class="btn" type="submit">' + ico('check') + ' Aprovar</button><button class="btn ghost" type="button" data-x>Cancelar</button></div>', function () {
        return cfg.call('approve', { item: S.data.item.id }).then(function (j) { toast(j.msg); return load(true); });
      });
    }
    function changesTeam() {
      modal('<h3>Pedir alterações</h3><p class="muted">A peça volta à equipa. A nota fica no histórico do negócio.</p><label class="field"><span>Nota (opcional)</span><textarea name="note" rows="3" maxlength="500"></textarea></label>'
        + '<div class="actions"><button class="btn" type="submit">Pedir alterações</button><button class="btn ghost" type="button" data-x>Cancelar</button></div>', function (f) {
        return cfg.call('changes', { item: S.data.item.id, note: f.note.value }).then(function (j) { toast(j.msg); return load(true); });
      });
    }
    function approveClient() {
      var v = V();
      var go = function () {
        var id = cfg.identity() || {};
        modal('<h3>' + esc(T.apTitle) + ' ' + esc(v.label) + '</h3><p class="muted">' + esc(T.apText) + '</p>'
          + '<label class="field"><span>' + esc(T.apName) + '</span><input type="text" name="name" required minlength="3" maxlength="160" value="' + esc(id.name || '') + '" autocomplete="name"></label>'
          + '<label class="check"><input type="checkbox" name="ok" required> ' + esc(T.apCheck) + '</label>'
          + '<div class="actions"><button class="btn" type="submit">' + ico('check') + ' ' + esc(T.apGo) + '</button><button class="btn ghost" type="button" data-x>' + esc(T.cancel) + '</button></div>', function (f) {
          if (!f.ok.checked) return false;
          return cfg.call('approve', { item: S.data.item.id, v: v.id, signed: f.name.value }).then(function (j) { toast(j.msg); return load(true); });
        });
      };
      if (cfg.identity && !cfg.identity()) { cfg.identify().then(function (ok) { if (ok) go(); }); return; }
      go();
    }

    // ── eventos ───────────────────────────────────────────────
    root.addEventListener('click', function (e) {
      var t = e.target.closest('button, a, [data-rv-go]'); if (!t || !root.contains(t)) { closePops(e); return; }
      closePops(e);
      var a;
      if (t.hasAttribute('data-rv-play')) togglePlay();
      else if (t.hasAttribute('data-rv-prev')) step(-1);
      else if (t.hasAttribute('data-rv-next')) step(1);
      else if (t.hasAttribute('data-rv-in')) setIn();
      else if (t.hasAttribute('data-rv-out')) setOut();
      else if (t.hasAttribute('data-rv-clear')) clearIO();
      else if (t.hasAttribute('data-rv-loop')) { S.loop = !S.loop; renderCtrl(); }
      else if (t.hasAttribute('data-rv-tc')) { S.showFrames = !S.showFrames; paintLight(); }
      else if (t.hasAttribute('data-rv-full')) { var el = S.el.view; if (document.fullscreenElement) document.exitFullscreen(); else if (el.requestFullscreen) el.requestFullscreen().then(function () { setTimeout(fit, 120); }); }
      else if ((a = t.getAttribute('data-rv-tool'))) { S.tool = S.tool === a ? null : a; if (S.tool && playing()) togglePlay(false); renderCtrl(); renderOverlay(); updateAnchor(); }
      else if ((a = t.getAttribute('data-rv-color'))) { S.color = a; renderCtrl(); }
      else if (t.hasAttribute('data-rv-undo-shape')) { S.draft.drawing.pop(); renderCtrl(); renderOverlay(); updateAnchor(); }
      else if ((a = t.getAttribute('data-rv-pg'))) goPage(S.page + (+a));
      else if ((a = t.getAttribute('data-rv-page'))) goPage(+a);
      else if ((a = t.getAttribute('data-rv-v'))) { if (+a !== S.vid || S.cmp) { S.vid = +a; S.cmp = null; renderVersions(); renderViewer(); renderSide(); } }
      else if (t.hasAttribute('data-rv-cmp')) openCompare();
      else if (t.hasAttribute('data-rv-cmp-x')) closeCompare();
      else if ((a = t.getAttribute('data-rv-cmode'))) { S.cmp.mode = a; renderCompare(); }
      else if ((a = t.getAttribute('data-rv-listen'))) listen(a);
      else if ((a = t.getAttribute('data-rv-mk'))) select(+a, true);
      else if ((a = t.getAttribute('data-rv-go'))) select(+a, true);
      else if ((a = t.getAttribute('data-rv-tab'))) { S.tab = a; renderSide(); }
      else if ((a = t.getAttribute('data-rv-filter'))) { S.filter = a; renderSide(); renderTimeline(); paint(); }
      else if ((a = t.getAttribute('data-rv-setvis'))) setVis(a);
      else if (t.hasAttribute('data-rv-anchor')) { S.draft.general = !S.draft.general; if (S.draft.general) { S.draft.x = null; S.draft.y = null; } updateAnchor(); paint(); }
      else if ((a = t.getAttribute('data-rv-reply'))) { var go = function () { S.reply = +a; renderSide(); var r = $('[data-rv-reply-text]', S.el.side); if (r) r.focus(); };
        if (!TEAM && cfg.identity && !cfg.identity()) cfg.identify().then(function (ok) { if (ok) go(); }); else go(); }
      else if (t.hasAttribute('data-rv-reply-x')) { S.reply = null; renderSide(); }
      else if ((a = t.getAttribute('data-rv-resolve'))) cfg.call('comment_resolve', { item: S.data.item.id, id: a, on: t.getAttribute('data-on') }).then(function (j) { S.data.comments = j.comments; renderSide(); renderTimeline(); paint(); }).catch(fail);
      else if ((a = t.getAttribute('data-rv-vis'))) cfg.call('comment_vis', { item: S.data.item.id, id: a, vis: t.getAttribute('data-vis') }).then(function (j) { S.data.comments = j.comments; renderSide(); renderTimeline(); paint(); }).catch(fail);
      else if ((a = t.getAttribute('data-rv-del'))) delComment(+a);
      else if ((a = t.getAttribute('data-rv-ment-u'))) mentionPick(a);
      else if ((a = t.getAttribute('data-rv-att-x'))) { S.att.splice(+a, 1); renderAtt(); }
      else if ((a = t.getAttribute('data-rv-pop'))) { e.stopPropagation(); var p = $('[data-pop="' + a + '"]', S.el.head); if (p) { var was = !p.hidden; closePops(); p.hidden = was; } }
      else if ((a = t.getAttribute('data-rv-exp'))) { e.preventDefault(); var only = $('[data-rv-exp-client]', S.el.head); location.href = cfg.exportUrl(S.vid, a, only && only.checked); }
      else if (t.hasAttribute('data-rv-send')) { t.disabled = true; cfg.call('send', { item: S.data.item.id }).then(function (j) { toast(j.msg, j.portal ? {} : { sticky: false }); return load(true); }).catch(fail).then(function () { t.disabled = false; }); }
      else if (t.hasAttribute('data-rv-changes')) changesTeam();
      else if (t.hasAttribute('data-rv-approve')) { TEAM ? approveTeam() : approveClient(); }
      else if (t.hasAttribute('data-rv-retry')) cfg.call('version_retry', { item: S.data.item.id, v: S.vid }).then(function (j) { toast(j.msg); return load(true); }).catch(fail);
      else if (t.hasAttribute('data-rv-submit')) submitClient(t);
      else if (t.hasAttribute('data-rv-dl')) { if (cfg.download) cfg.download(S.vid); }
    });
    function closePops(e) { $$('.rv-pop', root).forEach(function (p) { if (!e || !p.contains(e.target)) p.hidden = true; }); }
    root.addEventListener('change', function (e) {
      var t = e.target, a;
      if (t.hasAttribute('data-rv-upload')) { if (t.files[0]) upload(t.files[0]); t.value = ''; }
      else if (t.hasAttribute('data-rv-attach')) { attach(t.files); t.value = ''; }
      else if (t.hasAttribute('data-rv-speed')) { S.rate = +t.value; if (av(media)) media.playbackRate = S.rate; if (av(mediaB)) mediaB.playbackRate = S.rate; }
      else if (t.hasAttribute('data-rv-allv')) { S.allV = t.checked; renderSide(); }
      else if (t.hasAttribute('data-rv-ca') || t.hasAttribute('data-rv-cb')) { S.cmp[t.hasAttribute('data-rv-ca') ? 'a' : 'b'] = +t.value; renderCompare(); }
      else if (t.hasAttribute('data-rv-diff')) { S.cmp.diff = t.checked; renderCompare(); }
      else if ((a = t.getAttribute('data-rv-set'))) {
        var p = { item: S.data.item.id }; p[a] = t.type === 'checkbox' ? (t.checked ? 1 : 0) : t.value;
        cfg.call('item_save', p).then(function (j) { S.data.item = Object.assign(S.data.item, j.item); renderHead(); toast('Guardado.'); }).catch(fail);
      }
    });
    root.addEventListener('input', function (e) {
      var t = e.target;
      if (t.hasAttribute('data-rv-op')) { S.cmp.op = +t.value; var b = $('.rv-stack .b', root); if (b) b.style.opacity = S.cmp.op; }
      else if (t.hasAttribute('data-rv-text')) mentionUpdate(t);
    });
    root.addEventListener('submit', function (e) {
      var f = e.target;
      if (f.hasAttribute('data-rv-comp')) { e.preventDefault(); postComment(f); }
      else if (f.hasAttribute('data-rv-replyf')) { e.preventDefault(); postReply(f); }
    });
    root.addEventListener('keydown', function (e) {
      var t = e.target;
      if (t.hasAttribute && t.hasAttribute('data-rv-text')) {
        var box = $('[data-rv-ment]', S.el.side);
        if (box && !box.hidden && (e.key === 'Enter' || e.key === 'Tab')) { var on = $('button.on', box); if (on) { e.preventDefault(); mentionPick(on.getAttribute('data-rv-ment-u')); return; } }
        if (box && !box.hidden && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) {
          e.preventDefault(); var bs = $$('button', box), i = bs.indexOf($('button.on', box)); bs.forEach(function (b) { b.classList.remove('on'); });
          bs[(i + (e.key === 'ArrowDown' ? 1 : -1) + bs.length) % bs.length].classList.add('on'); return;
        }
        if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) { e.preventDefault(); postComment(t.form); }
        if (e.key === 'Escape') { t.blur(); }
      }
      if (t.hasAttribute && t.hasAttribute('data-rv-set') && t.type === 'text' && e.key === 'Enter') { e.preventDefault(); t.blur(); }
    });
    root.addEventListener('focusout', function (e) {
      var t = e.target;
      if (t.hasAttribute && t.hasAttribute('data-rv-set') && t.type === 'text' && t.value.trim() !== S.data.item.title) {
        cfg.call('item_save', { item: S.data.item.id, title: t.value }).then(function (j) { S.data.item = Object.assign(S.data.item, j.item); renderHead(); toast('Guardado.'); }).catch(fail);
      }
    });
    function delComment(id) {
      var a = TEAM ? { kind: 'team' } : { kind: 'client' };
      cfg.call('comment_del', { item: S.data.item.id, id: id }).then(function (j) {
        S.data.comments = j.comments; if (S.sel === id) S.sel = null; renderSide(); renderTimeline(); paint(); renderVersions();
        toast(T.deleted, { undo: function () { cfg.call('comment_restore', { item: S.data.item.id, id: id }).then(function (k) { S.data.comments = k.comments; renderSide(); renderTimeline(); paint(); renderVersions(); }).catch(fail); } });
      }).catch(fail);
      return a;
    }
    function submitClient(btn) {
      btn.disabled = true;
      cfg.call('submit', { item: S.data.item.id }).then(function (j) { toast(j.msg); return load(true); }).catch(function (e) { btn.disabled = false; fail(e); });
    }
    /** Comparar áudio: passa a ouvir A ou B, no mesmo ponto. */
    function listen(k) {
      if (!S.cmp || !av(media) || !av(mediaB)) return;
      S.cmp.listen = k; syncB(true);
      media.muted = k !== 'A'; mediaB.muted = k !== 'B';
      $$('[data-rv-listen]', S.el.view).forEach(function (b) { var on = b.getAttribute('data-rv-listen') === k; b.classList.toggle('on', on); b.setAttribute('aria-pressed', String(on)); });
      $$('.rv-cmp-stage.m-au > .rv-box', S.el.view).forEach(function (b, i) { b.classList.toggle('on', (i ? 'B' : 'A') === k); });
    }
    function setIn() { if (!timed(V())) return; S.inF = S.frame; if (S.outF != null && S.outF < S.inF) S.outF = null; S.draft.general = false; renderCtrl(); renderTimeline(); }
    function setOut() { if (!timed(V())) return; S.outF = S.frame; if (S.inF != null && S.inF > S.outF) S.inF = null; if (S.inF == null) S.inF = 0; S.draft.general = false; renderCtrl(); renderTimeline(); }
    function clearIO() { S.inF = null; S.outF = null; S.loop = false; renderCtrl(); renderTimeline(); }

    function bindKeys() {
      // na fase de captura: as teclas do leitor (I, O, J, K, L, C…) não chegam aos atalhos gerais da plataforma
      window.addEventListener('keydown', function (e) {
        if (!document.body.contains(root) || typing(e.target) || document.querySelector('.mdl.open') || e.altKey) return;
        var k = e.key, v = V(); if (!v) return;
        if (/^[ iIoOjJkKlLxXcC]$/.test(k) || /^(ArrowLeft|ArrowRight|Home|End|PageUp|PageDown)$/.test(k)) e.stopPropagation();
        if ((e.metaKey || e.ctrlKey) && (k === 'z' || k === 'Z')) { if (S.draft.drawing.length) { e.preventDefault(); S.draft.drawing.pop(); renderCtrl(); renderOverlay(); updateAnchor(); } return; }
        if (e.metaKey || e.ctrlKey) return;
        if (v.kind === 'pdf') {
          if (k === 'ArrowLeft' || k === 'PageUp') { e.preventDefault(); goPage(S.page - 1); }
          else if (k === 'ArrowRight' || k === 'PageDown') { e.preventDefault(); goPage(S.page + 1); }
        }
        if (k === 'Escape') { if (S.tool) { S.tool = null; renderCtrl(); renderOverlay(); updateAnchor(); } else if (S.sel) { S.sel = null; renderSide(); renderTimeline(); paint(); } return; }
        if (k === 'c' || k === 'C') { if (canComment()) { e.preventDefault(); focusComposer(); } return; }
        if (!timed(v) || v.proc !== 'ready') return;
        if ((k === 'a' || k === 'A' || k === 'b' || k === 'B') && S.cmp && S.cmp.listen) { e.preventDefault(); e.stopPropagation(); listen(k.toUpperCase()); return; }
        if (k === ' ' || k === 'k' || k === 'K') { e.preventDefault(); if (k === ' ') togglePlay(); else { stopReverse(); togglePlay(false); } }
        else if (k === 'ArrowLeft') { e.preventDefault(); step(e.shiftKey ? -Math.round(fps()) : -1); }
        else if (k === 'ArrowRight') { e.preventDefault(); step(e.shiftKey ? Math.round(fps()) : 1); }
        else if (k === 'l' || k === 'L') { e.preventDefault(); forwardL(); }
        else if (k === 'j' || k === 'J') { e.preventDefault(); reverse(); }
        else if (k === 'i' || k === 'I') { e.preventDefault(); setIn(); }
        else if (k === 'o' || k === 'O') { e.preventDefault(); setOut(); }
        else if (k === 'x' || k === 'X') { e.preventDefault(); clearIO(); }
        else if (k === 'Home') { e.preventDefault(); step(-S.frame); }
        else if (k === 'End') { e.preventDefault(); step(maxF() - S.frame); }
      }, true);
      document.addEventListener('fullscreenchange', function () { setTimeout(fit, 80); });
    }

    load().catch(function (e) { root.innerHTML = '<div class="rv-empty">' + esc((e && e.message) || T.offline) + '</div>'; });
    return { reload: function () { return load(true); } };
  }

  window.TopperReview = { mount: mount, previews: previews, tc: tc, tcFrames: tcFrames };
})();
