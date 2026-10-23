/* topper. admin — áudio no Centro Criativo e no Review (§27).
 *
 *   TopperAudio.prep(fileOrBlob)   → Promise<{dur, peaks, feats, prev}|null>
 *       lê o áudio no browser (Web Audio): a forma de onda (600 picos 0–255, em base64, guardada para não
 *       recalcular), uma imagem (forma de onda + espectrograma em escala log — a «miniatura» que a IA vê)
 *       e medidas: BPM estimado (com confiança), energia (dBFS), pico, dinâmica, brilho (centróide
 *       espectral), passagens por zero, silêncio. Ficheiros acima de 150 MB não são lidos (ficam sem forma de onda).
 *   TopperAudio.peaks(b64)          → Uint8Array
 *   TopperAudio.waveSvg(peaks, bars) → SVG da forma de onda (duas camadas: a base e o progresso, por --p)
 *   TopperAudio.hub                  → o leitor único: só um áudio toca de cada vez na página.
 *       play(id, url, from) · toggle(id, url) · pause() · seek(t) · rate(r) · volume(v) · ab(a, b) · state()
 *       Atualiza sozinho tudo o que tiver data-au-id="<id>" (classe .playing e a variável --p de progresso).
 */
(function () {
  'use strict';
  var MAX_BYTES = 157286400;
  var N_PEAKS = 600;

  function b64(u8) { var s = ''; for (var i = 0; i < u8.length; i++) s += String.fromCharCode(u8[i]); return btoa(s); }
  function peaks(s) {
    if (!s) return null;
    try { var bin = atob(String(s).replace(/-/g, '+').replace(/_/g, '/')), u = new Uint8Array(bin.length); for (var i = 0; i < bin.length; i++) u[i] = bin.charCodeAt(i); return u; }
    catch (e) { return null; }
  }

  // ── FFT radix-2 (in place) ──
  function fft(re, im) {
    var n = re.length, i, j, k, len, ang, wr, wi, ur, ui, vr, vi, tr;
    for (i = 1, j = 0; i < n; i++) { var bit = n >> 1; for (; j & bit; bit >>= 1) j ^= bit; j ^= bit; if (i < j) { tr = re[i]; re[i] = re[j]; re[j] = tr; tr = im[i]; im[i] = im[j]; im[j] = tr; } }
    for (len = 2; len <= n; len <<= 1) {
      ang = -2 * Math.PI / len; wr = Math.cos(ang); wi = Math.sin(ang);
      for (i = 0; i < n; i += len) {
        var cr = 1, ci = 0;
        for (k = 0; k < len / 2; k++) {
          ur = re[i + k]; ui = im[i + k];
          vr = re[i + k + len / 2] * cr - im[i + k + len / 2] * ci; vi = re[i + k + len / 2] * ci + im[i + k + len / 2] * cr;
          re[i + k] = ur + vr; im[i + k] = ui + vi; re[i + k + len / 2] = ur - vr; im[i + k + len / 2] = ui - vi;
          tr = cr * wr - ci * wi; ci = cr * wi + ci * wr; cr = tr;
        }
      }
    }
  }
  // «magma» simplificado: preto → roxo → laranja → amarelo
  function color(v) {
    var st = [[0, 0, 4], [40, 11, 84], [101, 21, 110], [159, 42, 99], [212, 72, 66], [245, 125, 21], [250, 193, 39], [252, 255, 164]];
    v = Math.max(0, Math.min(1, v)) * (st.length - 1);
    var i = Math.floor(v), f = v - i, a = st[i], b = st[Math.min(st.length - 1, i + 1)];
    return [a[0] + (b[0] - a[0]) * f, a[1] + (b[1] - a[1]) * f, a[2] + (b[2] - a[2]) * f];
  }
  function db(x) { return 20 * Math.log10(Math.max(1e-9, x)); }

  function decode(buf) {
    var AC = window.AudioContext || window.webkitAudioContext;
    if (!AC) return Promise.reject(new Error('Web Audio'));
    var ctx = new AC();
    return new Promise(function (res, rej) { ctx.decodeAudioData(buf, res, rej); }).then(function (ab) { if (ctx.close) ctx.close(); return ab; }, function (e) { if (ctx.close) ctx.close(); throw e; });
  }
  /** Mistura para mono a 22 050 Hz (para as medidas e o espectrograma). */
  function mono22(ab) {
    var OAC = window.OfflineAudioContext || window.webkitOfflineAudioContext, rate = 22050;
    var len = Math.max(1, Math.ceil(ab.duration * rate));
    if (!OAC) { var d = ab.getChannelData(0); return Promise.resolve({ data: d, rate: ab.sampleRate }); }
    var oc = new OAC(1, len, rate), src = oc.createBufferSource();
    src.buffer = ab; src.connect(oc.destination); src.start(0);
    return oc.startRendering().then(function (r) { return { data: r.getChannelData(0), rate: rate }; });
  }

  function analyse(ab) {
    return mono22(ab).then(function (m) {
      var x = m.data, rate = m.rate, n = x.length, i, j;
      // forma de onda: o maior valor absoluto por troço (de todos os canais), normalizado
      var pk = new Float32Array(N_PEAKS), per = Math.max(1, Math.floor(ab.length / N_PEAKS)), chs = [];
      for (j = 0; j < ab.numberOfChannels; j++) chs.push(ab.getChannelData(j));
      for (i = 0; i < N_PEAKS; i++) {
        var a = i * per, b = Math.min(ab.length, a + per), mx = 0, step = Math.max(1, Math.floor((b - a) / 2000));
        for (var c = 0; c < chs.length; c++) for (j = a; j < b; j += step) { var v = Math.abs(chs[c][j]); if (v > mx) mx = v; }
        pk[i] = mx;
      }
      var top = 0; for (i = 0; i < N_PEAKS; i++) if (pk[i] > top) top = pk[i];
      var p8 = new Uint8Array(N_PEAKS); for (i = 0; i < N_PEAKS; i++) p8[i] = Math.round(Math.sqrt(pk[i] / (top || 1)) * 255);

      // energia por janela de 0,4 s, silêncio, passagens por zero
      var win = Math.round(rate * 0.4), rmsW = [], sum2 = 0, peak = 0, zc = 0;
      for (i = 0; i < n; i++) { var s = x[i]; sum2 += s * s; if (Math.abs(s) > peak) peak = Math.abs(s); if (i && (s >= 0) !== (x[i - 1] >= 0)) zc++; }
      for (i = 0; i + win <= n; i += win) { var e = 0; for (j = i; j < i + win; j++) e += x[j] * x[j]; rmsW.push(db(Math.sqrt(e / win))); }
      var sorted = rmsW.slice().sort(function (p, q) { return p - q; }), pct = function (p) { return sorted.length ? sorted[Math.min(sorted.length - 1, Math.floor(p * sorted.length))] : -120; };
      var silence = rmsW.filter(function (v) { return v < -50; }).length / Math.max(1, rmsW.length);

      // espectrograma: 800 colunas, FFT de 2048, 220 linhas em escala log (40 Hz – 11 kHz)
      var W = 800, Hs = 220, Hw = 70, N = 2048, cols = Math.min(W, Math.max(1, Math.floor((n - N) / 256) + 1)), hop = Math.max(1, Math.floor((n - N) / Math.max(1, cols - 1)));
      var spec = new Float32Array(W * Hs), re = new Float32Array(N), im = new Float32Array(N), han = new Float32Array(N), cen = 0, cenW = 0;
      for (i = 0; i < N; i++) han[i] = 0.5 - 0.5 * Math.cos(2 * Math.PI * i / (N - 1));
      var fLo = Math.log(40), fHi = Math.log(Math.min(11000, rate / 2)), rowBin = new Int32Array(Hs + 1);
      for (i = 0; i <= Hs; i++) rowBin[i] = Math.max(1, Math.min(N / 2 - 1, Math.round(Math.exp(fLo + (fHi - fLo) * i / Hs) / rate * N)));
      // fluxo espectral (para o BPM): janelas de 1024 com passo de 512 nos primeiros 3 minutos
      for (var col = 0; col < W; col++) {
        var off = Math.min(Math.max(0, n - N), Math.floor(col * cols / W) * hop);
        for (i = 0; i < N; i++) { re[i] = (x[off + i] || 0) * han[i]; im[i] = 0; }
        fft(re, im);
        var tot = 0, wsum = 0;
        for (i = 1; i < N / 2; i++) { var mag = Math.sqrt(re[i] * re[i] + im[i] * im[i]); tot += mag; wsum += mag * i * rate / N; re[i] = mag; }
        if (tot > 0) { cen += wsum; cenW += tot; }
        for (var r = 0; r < Hs; r++) {
          var b0 = rowBin[r], b1 = Math.max(b0 + 1, rowBin[r + 1]), mm = 0;
          for (j = b0; j < b1; j++) if (re[j] > mm) mm = re[j];
          spec[col * Hs + r] = db(mm / (N / 4));
        }
      }
      // BPM: envolvente de energia (passo de 512 amostras ≈ 23 ms), diferenças positivas, autocorrelação 60–200 bpm
      var hopB = 512, lim = Math.min(n, rate * 180), env = [];
      for (i = 0; i + hopB <= lim; i += hopB) { var en = 0; for (j = i; j < i + hopB; j++) en += x[j] * x[j]; env.push(Math.log(1e-6 + en)); }
      var on = new Float32Array(Math.max(0, env.length - 1)), om = 0;
      for (i = 1; i < env.length; i++) { on[i - 1] = Math.max(0, env[i] - env[i - 1]); om += on[i - 1]; }
      om /= Math.max(1, on.length);
      for (i = 0; i < on.length; i++) on[i] -= om;
      var fr = rate / hopB, best = 0, bestLag = 0, acSum = 0, acN = 0;
      for (var bpm = 60; bpm <= 200; bpm += 0.5) {
        var lag = fr * 60 / bpm, L = Math.floor(lag), fl = lag - L, ac = 0;
        for (i = 0; i + L + 1 < on.length; i++) ac += on[i] * (on[i + L] * (1 - fl) + on[i + L + 1] * fl);
        acSum += ac; acN++;
        if (ac > best) { best = ac; bestLag = bpm; }
      }
      var conf = acN && best > 0 ? Math.max(0, Math.min(1, (best - acSum / acN) / (best + 1e-9))) : 0;
      var bpmOut = bestLag;
      while (bpmOut < 75 && bpmOut > 0) bpmOut *= 2;
      while (bpmOut > 180) bpmOut /= 2;

      // a imagem: forma de onda em cima, espectrograma em baixo
      var cv = document.createElement('canvas'); cv.width = W; cv.height = Hw + Hs;
      var g = cv.getContext('2d'), img = g.createImageData(W, Hs), sv = Array.prototype.slice.call(spec).filter(isFinite).sort(function (p, q) { return p - q; });
      var lo = sv.length ? sv[Math.floor(sv.length * 0.05)] : -90, hi = sv.length ? sv[Math.floor(sv.length * 0.995)] : 0;
      for (col = 0; col < W; col++) for (r = 0; r < Hs; r++) {
        var cc = color((spec[col * Hs + r] - lo) / Math.max(1, hi - lo)), o = ((Hs - 1 - r) * W + col) * 4;
        img.data[o] = cc[0]; img.data[o + 1] = cc[1]; img.data[o + 2] = cc[2]; img.data[o + 3] = 255;
      }
      g.fillStyle = '#111'; g.fillRect(0, 0, W, Hw);
      g.putImageData(img, 0, Hw);
      g.fillStyle = '#FFC718';
      for (i = 0; i < W; i++) { var hv = p8[Math.floor(i * N_PEAKS / W)] / 255 * (Hw - 8); g.fillRect(i, (Hw - hv) / 2, 1, Math.max(1, hv)); }
      return new Promise(function (res) { cv.toBlob(function (bl) { res(bl); }, 'image/jpeg', 0.86); }).then(function (prev) {
        return {
          dur: ab.duration, peaks: b64(p8), prev: prev,
          feats: { bpm: Math.round(bpmOut * 10) / 10, bpm_conf: Math.round(conf * 100) / 100, rms_db: Math.round(db(Math.sqrt(sum2 / Math.max(1, n))) * 10) / 10,
                   peak_db: Math.round(db(peak) * 10) / 10, dyn_db: Math.round((pct(0.95) - pct(0.1)) * 10) / 10, centroid: cenW ? Math.round(cen / cenW) : 0,
                   zcr: Math.round(zc / Math.max(1, n) * 1000) / 1000, silence: Math.round(silence * 100) / 100, channels: ab.numberOfChannels, rate: ab.sampleRate }
        };
      });
    });
  }

  function prep(src) {
    if (!src || (src.size && src.size > MAX_BYTES)) return Promise.resolve(null);
    var get = src.arrayBuffer ? src.arrayBuffer() : new Response(src).arrayBuffer();
    return get.then(decode).then(analyse).catch(function () { return null; });
  }
  /** O mesmo, a partir de um endereço (um áudio já guardado). */
  function prepUrl(url) {
    return fetch(url, { credentials: 'same-origin' }).then(function (r) {
      if (!r.ok) throw new Error('http ' + r.status);
      var len = +r.headers.get('content-length') || 0;
      if (len > MAX_BYTES) throw new Error('grande');
      return r.blob();
    }).then(prep).catch(function () { return null; });
  }

  /** Duas cópias sobrepostas: a de baixo (.b) e a de progresso (.f), cortada por --p (a posição, em %). */
  function waveSvg(p8, bars) {
    bars = bars || 120;
    var svg;
    if (!p8 || !p8.length) svg = '<path d="M0 20H100" stroke-width="1"/>';
    else {
      var d = '';
      for (var i = 0; i < bars; i++) {
        var a = Math.floor(i * p8.length / bars), b = Math.max(a + 1, Math.floor((i + 1) * p8.length / bars)), m = 0;
        for (var j = a; j < b; j++) if (p8[j] > m) m = p8[j];
        var h = Math.max(1.5, m / 255 * 38), x = i * 100 / bars + 50 / bars;
        d += 'M' + x.toFixed(2) + ' ' + (20 - h / 2).toFixed(1) + 'v' + h.toFixed(1);
      }
      svg = '<path d="' + d + '" stroke-width="' + (100 / bars * 0.62).toFixed(2) + '"/>';
    }
    var one = function (c) { return '<svg class="' + c + '" viewBox="0 0 100 40" preserveAspectRatio="none" aria-hidden="true" focusable="false">' + svg + '</svg>'; };
    return '<span class="au-wave' + (p8 && p8.length ? '' : ' empty') + '">' + one('b') + one('f') + '<i class="au-ph"></i></span>';
  }

  // ── o leitor único ──
  var hub = (function () {
    var el = new Audio(), cur = null, A = null, B = null, raf = 0;
    el.preload = 'metadata';
    function sel(id) { return document.querySelectorAll('[data-au-id="' + id + '"]'); }
    function paint() {
      if (!cur) return;
      var d = el.duration && isFinite(el.duration) ? el.duration : 0, p = d ? el.currentTime / d : 0;
      Array.prototype.forEach.call(sel(cur), function (n) {
        n.style.setProperty('--p', (p * 100).toFixed(2) + '%');
        n.classList.toggle('playing', !el.paused);
        var t = n.querySelector('[data-au-t]'); if (t) t.textContent = fmt(el.currentTime) + (d ? ' / ' + fmt(d) : '');
      });
      document.dispatchEvent(new CustomEvent('topper-audio', { detail: { id: cur, t: el.currentTime, d: d, playing: !el.paused } }));
    }
    function loop() { cancelAnimationFrame(raf); if (!el.paused) { if (A != null && B != null && el.currentTime >= B) el.currentTime = A; paint(); raf = requestAnimationFrame(loop); } }
    function clear(id) { Array.prototype.forEach.call(sel(id), function (n) { n.classList.remove('playing'); n.style.setProperty('--p', '0%'); var t = n.querySelector('[data-au-t]'); if (t && t.getAttribute('data-au-dur')) t.textContent = t.getAttribute('data-au-dur'); }); }
    el.addEventListener('play', loop); el.addEventListener('pause', paint); el.addEventListener('ended', function () { paint(); });
    el.addEventListener('loadedmetadata', paint);
    // formato que o browser não toca (AIFF no Chrome, por exemplo): marca os elementos e avisa quem estiver a ouvir
    el.addEventListener('error', function () {
      if (!cur || !el.getAttribute('src')) return;
      Array.prototype.forEach.call(sel(cur), function (n) { n.classList.remove('playing'); n.classList.add('au-err'); });
      document.dispatchEvent(new CustomEvent('topper-audio', { detail: { id: cur, error: true } }));
    });
    // outro leitor da página começou (vídeo do Review, por exemplo): este pára
    document.addEventListener('play', function (e) { if (e.target !== el && (e.target.tagName === 'AUDIO' || e.target.tagName === 'VIDEO')) el.pause(); }, true);
    return {
      el: el,
      play: function (id, url, from) {
        if (cur !== id) { if (cur != null) { el.pause(); clear(cur); } cur = id; A = B = null; el.src = url; el.playbackRate = el.playbackRate || 1; }
        if (from != null) { try { el.currentTime = from; } catch (e) {} }
        var pr = el.play(); if (pr && pr.catch) pr.catch(function () {});
        paint();
      },
      toggle: function (id, url) { if (cur === id && !el.paused) { el.pause(); return false; } this.play(id, url); return true; },
      pause: function () { el.pause(); },
      seek: function (id, url, frac) {
        if (cur !== id) { this.play(id, url); var once = function () { el.removeEventListener('loadedmetadata', once); el.currentTime = frac * el.duration; }; el.addEventListener('loadedmetadata', once); return; }
        if (el.duration && isFinite(el.duration)) el.currentTime = frac * el.duration; paint();
      },
      seekT: function (id, url, t) { if (cur !== id) this.play(id, url, t); else { el.currentTime = t; if (el.paused) { var p = el.play(); if (p && p.catch) p.catch(function () {}); } paint(); } },
      rate: function (r) { el.playbackRate = r; },
      volume: function (v) { el.volume = Math.max(0, Math.min(1, v)); },
      ab: function (a, b) { A = a; B = b; },
      abState: function () { return { a: A, b: B }; },
      state: function () { return { id: cur, t: el.currentTime, d: el.duration, playing: !el.paused, rate: el.playbackRate, volume: el.volume }; },
      paint: paint
    };
  })();

  function fmt(s) {
    s = Math.max(0, Math.round(s || 0));
    var h = Math.floor(s / 3600), m = Math.floor(s / 60) % 60, x = s % 60;
    return (h ? h + ':' + (m < 10 ? '0' : '') : '') + m + ':' + (x < 10 ? '0' : '') + x;
  }

  window.TopperAudio = { prep: prep, prepUrl: prepUrl, peaks: peaks, waveSvg: waveSvg, hub: hub, fmt: fmt,
    isAudio: function (file) { var n = (file.name || '').toLowerCase(); return /^audio\//.test(file.type || '') || /\.(mp3|wav|m4a|aac|flac|ogg|aif|aiff)$/.test(n); } };
})();
