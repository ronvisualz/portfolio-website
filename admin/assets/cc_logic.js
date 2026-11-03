/* topper. admin — Toppernote: lógica pura dos Topperboards (§33), sem DOM. No browser fica em window.CCL; no Node em
   module.exports (tests/cc_logic_test.js). cc.js liga estas funções aos eventos. */
(function (root, factory) {
  var api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  else root.CCL = api;
})(typeof self !== 'undefined' ? self : this, function () {
  'use strict';
  var LIST_MAX = 200, LEVEL_MAX = 2;

  function item(it) { it = it || {}; return { t: String(it.t || ''), d: !!it.d, l: Math.max(0, Math.min(LEVEL_MAX, +it.l || 0)) }; }
  function copy(items) { return (items || []).map(item); }

  /** Uma tecla num item de lista (§33.1). key: Enter | Backspace | Tab | ArrowUp | ArrowDown.
   *  mods: {shift, ctrl, alt, atStart, atEnd, empty, caret}. Devolve {items, focus, handled} (items é sempre uma cópia). */
  function listKey(items, i, key, mods) {
    var m = mods || {}, l = copy(items), cur = l[i];
    var out = function (focus, handled) { return { items: l, focus: focus, handled: handled !== false }; };
    if (!cur) return out(i, false);
    if (key === 'Enter') {
      if (m.ctrl) { cur.d = !cur.d; return out(i); }
      if (m.shift || l.length >= LIST_MAX) return out(i, false);
      var at = m.caret != null && !m.atEnd ? Math.max(0, Math.min(cur.t.length, +m.caret)) : cur.t.length;
      var rest = cur.t.slice(at);
      cur.t = cur.t.slice(0, at);
      l.splice(i + 1, 0, { t: rest, d: false, l: cur.l });
      return out(i + 1);
    }
    if (key === 'Backspace') {
      if (!m.empty || cur.t !== '' || l.length < 2) return out(i, false);
      l.splice(i, 1);
      return out(Math.max(0, i - 1));
    }
    if (key === 'Tab') { cur.l = Math.max(0, Math.min(LEVEL_MAX, cur.l + (m.shift ? -1 : 1))); return out(i); }
    if ((key === 'ArrowUp' || key === 'ArrowDown') && m.alt) {
      var j = key === 'ArrowUp' ? i - 1 : i + 1;
      if (j < 0 || j >= l.length) return out(i);
      l.splice(j, 0, l.splice(i, 1)[0]);
      return out(j);
    }
    return out(i, false);
  }

  /** Colar texto num item: uma linha por item (vazias ignoradas; marcadores «-», «*», «1.», «[ ]», «[x]» saem; «[x]» = feito).
   *  A 1.ª linha ocupa o item atual se ele estiver vazio. Máximo de 200 itens. */
  function listPaste(items, i, text) {
    var l = copy(items), cur = l[i] || { t: '', d: false, l: 0 };
    var lines = String(text || '').split(/\r?\n/).map(function (s) {
      var d = /^\s*(?:[-*•]\s*)?\[[xX]\]/.test(s);
      var t = s.replace(/^\s*(?:[-*•]|\d+[.)])?\s*(?:\[[ xX]?\]\s*)?/, '').trim();
      return { t: t, d: d, l: cur.l };
    }).filter(function (x) { return x.t !== ''; });
    if (!lines.length) return { items: l, focus: i };
    var at = i + 1;
    if (l[i] && l[i].t.trim() === '') { l[i] = lines.shift(); }
    else at = i + 1;
    var room = Math.max(0, LIST_MAX - l.length);
    lines = lines.slice(0, room);
    Array.prototype.splice.apply(l, [at, 0].concat(lines));
    return { items: l, focus: lines.length ? at + lines.length - 1 : i };
  }

  /** Ao gravar: tira os itens vazios do fim (deixa sempre pelo menos 1). */
  function listClean(items) {
    var l = (items || []).slice();
    while (l.length > 1 && String(l[l.length - 1].t || '').trim() === '') l.pop();
    return l;
  }

  // ── cores (§33.2, §33.3) ──
  var BRAND = ['#111111', '#FFC718', '#E8846D', '#81CFE3', '#2F77B1'];   // teclas 1–5 na caneta

  /** Texto → '#RRGGBB' (com ou sem #, 3 ou 6 dígitos, espaços, rgb(r, g, b)); null se não for cor. */
  function hexParse(s) {
    s = String(s == null ? '' : s).trim();
    var m = /^rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})/i.exec(s);
    if (m) {
      if (+m[1] > 255 || +m[2] > 255 || +m[3] > 255) return null;
      return '#' + [m[1], m[2], m[3]].map(function (v) { return ('0' + (+v).toString(16)).slice(-2); }).join('').toUpperCase();
    }
    s = s.replace(/^#/, '');
    if (/^[0-9a-f]{3}$/i.test(s)) s = s.replace(/(.)/g, '$1$1');
    return /^[0-9a-f]{6}$/i.test(s) ? '#' + s.toUpperCase() : null;
  }
  /** As últimas cores usadas: a nova à frente, sem duplicados, no máximo `max` (8). */
  function recentColors(list, hex, max) {
    var h = hexParse(hex), out = h ? [h] : [];
    (list || []).forEach(function (x) { var y = hexParse(x); if (y && out.indexOf(y) < 0) out.push(y); });
    return out.slice(0, max || 8);
  }

  // ── desenho (§33.3) ──
  /** O ponto (x, y) está a ≤ r de algum segmento do traço [x0,y0,x1,y1,…]? (borracha) */
  function strokeHit(pts, x, y, r) {
    for (var i = 0; i + 1 < pts.length; i += 2) {
      var ax = pts[i], ay = pts[i + 1], bx = i + 3 < pts.length ? pts[i + 2] : ax, by = i + 3 < pts.length ? pts[i + 3] : ay;
      var dx = bx - ax, dy = by - ay, L = dx * dx + dy * dy, t = L ? Math.max(0, Math.min(1, ((x - ax) * dx + (y - ay) * dy) / L)) : 0;
      if (Math.hypot(x - (ax + t * dx), y - (ay + t * dy)) <= r) return true;
    }
    return false;
  }
  /** Traço em coordenadas do mundo → relativo ao canto da sua caixa (margem 6, mínimo 20 × 20). */
  function fitStroke(pts, pad) {
    pad = pad == null ? 6 : pad;
    var xs = [], ys = [];
    for (var i = 0; i + 1 < pts.length; i += 2) { xs.push(pts[i]); ys.push(pts[i + 1]); }
    var x = Math.min.apply(null, xs) - pad, y = Math.min.apply(null, ys) - pad;
    var vw = Math.max(20, Math.max.apply(null, xs) + pad - x), vh = Math.max(20, Math.max.apply(null, ys) + pad - y);
    var r = function (v) { return Math.round(v * 10) / 10; };
    return { pts: pts.map(function (v, k) { return r(v - (k % 2 ? y : x)); }), vw: r(vw), vh: r(vh), x: r(x), y: r(y) };
  }
  /** Desenhos antigos (sem vw/vh): a caixa dos traços, com a mesma margem. */
  function drawBox(paths) {
    var mx = 0, my = 0;
    (paths || []).forEach(function (p) { for (var i = 0; i + 1 < (p.pts || []).length; i += 2) { mx = Math.max(mx, p.pts[i]); my = Math.max(my, p.pts[i + 1]); } });
    return { vw: Math.max(20, mx + 6), vh: Math.max(20, my + 6) };
  }
  /** Um ponto do cartão (px, py, relativo ao canto) → coordenadas do desenho (viewBox com xMidYMid meet). */
  function toLocal(box, vb, px, py) {
    var s = Math.min(box.w / vb.vw, box.h / vb.vh) || 1, ox = (box.w - vb.vw * s) / 2, oy = (box.h - vb.vh * s) / 2;
    return [(px - ox) / s, (py - oy) / s];
  }

  // ── anular por pessoa (§33.10) ──
  /** Forma canónica para comparar estados (o servidor apara texto, arredonda e acrescenta campos por defeito). */
  function canon(v) {
    if (Array.isArray(v)) return v.map(canon);
    if (v && typeof v === 'object') {
      var o = {};
      Object.keys(v).sort().forEach(function (k) { var x = canon(v[k]); if (x !== undefined) o[k] = x; });
      return o;
    }
    if (typeof v === 'string') { v = v.trim(); return v === '' ? undefined : v; }
    if (typeof v === 'number') { v = Math.round(v * 10) / 10; return v === 0 ? undefined : v; }
    return v === false || v == null ? undefined : v;
  }
  function same(a, b) { return JSON.stringify(canon(a)) === JSON.stringify(canon(b)); }
  var GEO = ['x', 'y', 'w', 'h', 'col', 'ord', 'lane'];
  function geo(c) { var o = {}; GEO.forEach(function (k) { o[k] = c[k]; }); return o; }
  /** O que um Ctrl Z (ou Ctrl Shift Z) pode repor sem desfazer o trabalho de outra pessoa: só os cartões que ainda estão
   *  como esta pessoa os deixou. entry: move | content | add | del | multi; current: Map id → cartão.
   *  Devolve {apply, skipped}: move → estados; content → [{id, c}]; add/del → ids; multi → planos de cada parte. */
  function undoPlan(entry, current, redo) {
    var apply = [], skipped = 0;
    if (entry.t === 'multi') {
      (redo ? entry.list : entry.list.slice().reverse()).forEach(function (x) { var p = undoPlan(x, current, redo); p.entry = x; apply.push(p); skipped += p.skipped; });
    } else if (entry.t === 'move') {
      entry.items.forEach(function (it) {
        var c = current.get(it.before.id), want = redo ? it.before : it.after;
        if (c && same(geo(c), geo(want))) apply.push(redo ? it.after : it.before); else skipped++;
      });
    } else if (entry.t === 'content') {
      var c = current.get(entry.id);
      if (c && same(c.c, redo ? entry.before : entry.after)) apply.push({ id: entry.id, c: redo ? entry.after : entry.before }); else skipped++;
    } else if (entry.t === 'add' || entry.t === 'del') {
      var shouldExist = (entry.t === 'add') !== !!redo;   // anular «add» apaga (têm de existir); anular «del» repõe (não podem existir)
      entry.ids.forEach(function (id) { if (current.has(id) === shouldExist) apply.push(id); else skipped++; });
    }
    return { apply: apply, skipped: skipped };
  }
  function skippedMsg(n) {
    return n === 1 ? '1 alteração não anulada: outra pessoa mudou este cartão.' : n + ' alterações não anuladas: outras pessoas mudaram estes cartões.';
  }

  // ── miniaturas (§33.5) ──
  /** A largura de miniatura a pedir: a menor da lista ≥ cssW × zoom × densidade; 0 = o original (sem miniaturas, ou o pedido
   *  passa 1,25× a maior e a imagem é maior do que ela). origW: largura do original (opcional). */
  function thumbPick(tw, cssW, zoom, dpr, origW) {
    var l = (tw || []).map(Number).filter(function (x) { return x > 0; }).sort(function (a, b) { return a - b; });
    if (!l.length) return 0;
    var need = cssW * (zoom || 1) * (dpr || 1), max = l[l.length - 1];
    for (var i = 0; i < l.length; i++) if (l[i] >= need) return l[i];
    return need > max * 1.25 && (!origW || origW > max) ? 0 : max;
  }

  // ── conta-gotas (§33.11) ──
  /** A cor em (x, y) de uma imagem RGBA (largura w): média 3×3, ou o píxel exato com Alt.
   *  box [x0, y0, x1, y1] (opcional, x1/y1 exclusivos): só os píxeis dentro dela existem (nas bordas da imagem, a
   *  recolha só traz parte da caixa; o resto é transparente e não conta). */
  function avg3(data, w, x, y, alt, box) {
    var h = Math.floor(data.length / 4 / w), r = 0, g = 0, b = 0, n = 0, R = alt ? 0 : 1;
    var x0 = box ? Math.max(0, box[0]) : 0, y0 = box ? Math.max(0, box[1]) : 0, x1 = box ? Math.min(w, box[2]) : w, y1 = box ? Math.min(h, box[3]) : h;
    for (var dy = -R; dy <= R; dy++) for (var dx = -R; dx <= R; dx++) {
      var xx = x + dx, yy = y + dy;
      if (xx < x0 || yy < y0 || xx >= x1 || yy >= y1) continue;
      var i = (yy * w + xx) * 4; r += data[i]; g += data[i + 1]; b += data[i + 2]; n++;
    }
    var hx = function (v) { return ('0' + Math.round(v / (n || 1)).toString(16)).slice(-2); };
    return ('#' + hx(r) + hx(g) + hx(b)).toUpperCase();
  }
  /** O tamanho (inteiro, em px CSS) da imagem w×h ajustada a availW×availH sem cortar; amplia no máximo maxUp vezes. */
  function fitRect(w, h, availW, availH, maxUp) {
    if (!(w > 0 && h > 0)) return { w: 0, h: 0 };
    var s = Math.min(Math.max(1, availW) / w, Math.max(1, availH) / h, maxUp > 0 ? maxUp : 8);
    return { w: Math.max(1, Math.round(w * s)), h: Math.max(1, Math.round(h * s)) };
  }
  /** O píxel (x, y) do original w×h debaixo de (cx, cy); rect é o getBoundingClientRect da imagem desenhada (já com
   *  zoom e deslocação). null fora da imagem. */
  function pickPx(rect, w, h, cx, cy) {
    if (!rect || !(rect.width > 0 && rect.height > 0) || !(w > 0 && h > 0)) return null;
    var fx = (cx - rect.left) / rect.width, fy = (cy - rect.top) / rect.height;
    if (fx < 0 || fy < 0 || fx > 1 || fy > 1) return null;
    return { x: Math.min(w - 1, Math.floor(fx * w)), y: Math.min(h - 1, Math.floor(fy * h)) };
  }
  /** A cor do texto que se lê sobre a amostra hex: '#111111' ou '#FFFFFF' (o de maior contraste, WCAG). */
  function ink(hex) {
    var v = hexParse(hex); if (!v) return '#111111';
    var lin = function (i) { var c = parseInt(v.substr(i, 2), 16) / 255; return c <= 0.04045 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4); };
    var L = 0.2126 * lin(1) + 0.7152 * lin(3) + 0.0722 * lin(5), Ld = 0.0056;   // #111111
    return (1.05 / (L + 0.05)) >= ((L + 0.05) / (Ld + 0.05)) ? '#FFFFFF' : '#111111';
  }
  /** Zoom f à volta de um ponto (ox, oy: distância ao centro do palco); v = { z, px, py }; z entre 1 e max; em 1 fica centrado. */
  function zoomAbout(v, f, ox, oy, max) {
    var z0 = v.z || 1, z = Math.min(max || 16, Math.max(1, z0 * f));
    if (z === 1) return { z: 1, px: 0, py: 0 };
    return { z: z, px: ox - (ox - v.px) * z / z0, py: oy - (oy - v.py) * z / z0 };
  }
  /** '#RRGGBB' → 'rgb(r, g, b)' ou 'hsl(h, s%, l%)'. */
  function hexTo(hex, mode) {
    var v = hexParse(hex); if (!v) return '';
    var r = parseInt(v.substr(1, 2), 16), g = parseInt(v.substr(3, 2), 16), b = parseInt(v.substr(5, 2), 16);
    if (mode !== 'hsl') return 'rgb(' + r + ', ' + g + ', ' + b + ')';
    var R = r / 255, G = g / 255, Bv = b / 255, mx = Math.max(R, G, Bv), mn = Math.min(R, G, Bv), l = (mx + mn) / 2, d = mx - mn, hh = 0, ss = 0;
    if (d) {
      ss = d / (1 - Math.abs(2 * l - 1));
      hh = mx === R ? ((G - Bv) / d) % 6 : mx === G ? (Bv - R) / d + 2 : (R - G) / d + 4;
      hh = Math.round(hh * 60); if (hh < 0) hh += 360;
    }
    return 'hsl(' + hh + ', ' + Math.round(ss * 100) + '%, ' + Math.round(l * 100) + '%)';
  }

  // ── seleção por retângulo (§33.12) ──
  /** Os ids das caixas [{id,x,y,w,h}] que o retângulo toca (interseção, bordas incluídas; largura/altura negativas aceites). */
  function marqueeHit(boxes, rect) {
    var x1 = Math.min(rect.x, rect.x + rect.w), x2 = Math.max(rect.x, rect.x + rect.w), y1 = Math.min(rect.y, rect.y + rect.h), y2 = Math.max(rect.y, rect.y + rect.h);
    return (boxes || []).filter(function (b) { return b.x <= x2 && b.x + b.w >= x1 && b.y <= y2 && b.y + b.h >= y1; }).map(function (b) { return b.id; });
  }

  // ── copiar e colar (§33.6) ──
  /** O que se copia: cartões (com os de dentro das colunas), coordenadas relativas ao canto da seleção, e as setas cujas
   *  duas pontas vão. cards: Map id → cartão. */
  function clipPack(cards, ids, boardId) {
    var get = function (id) { return cards.get ? cards.get(id) : cards[id]; };
    var want = [], seen = {};
    var add = function (c) { if (c && !seen[c.id] && c.type !== 'arrow' && !(c.id < 0)) { seen[c.id] = 1; want.push(c); } };
    (ids || []).forEach(function (id) {
      var c = get(id);
      if (!c || c.type === 'arrow') return;
      add(c);
      if (c.type === 'column') {
        var kids = [];
        (cards.forEach ? cards : new Map()).forEach(function (k) { if (k.col === c.id) kids.push(k); });
        kids.sort(function (a, b) { return (a.ord || 0) - (b.ord || 0); }).forEach(add);
      }
    });
    var top = want.filter(function (c) { return !(c.col && seen[c.col]); });
    var pos = function (c) { var p = c.col && !seen[c.col] && get(c.col) ? get(c.col) : c; return { x: p.x, y: p.y }; };
    var ox = Math.min.apply(null, top.map(function (c) { return pos(c).x; })), oy = Math.min.apply(null, top.map(function (c) { return pos(c).y; }));
    var items = want.map(function (c) {
      var inCol = !!(c.col && seen[c.col]), p = pos(c);
      return { ref: c.id, type: c.type, x: inCol ? 0 : p.x - ox, y: inCol ? 0 : p.y - oy, w: c.w, h: c.h || 0, c: JSON.parse(JSON.stringify(c.c || {})),
               asset: c.asset ? c.asset.id : null, sub: c.ref || null, col: inCol ? c.col : null, ord: c.ord || 0, lane: c.lane || '' };
    });
    var arrows = [];
    (cards.forEach ? cards : new Map()).forEach(function (a) {
      if (a.type === 'arrow' && a.c && seen[a.c.from] && seen[a.c.to]) arrows.push({ from: a.c.from, to: a.c.to, c: JSON.parse(JSON.stringify(a.c)) });
    });
    return { v: 1, board: boardId, ox: isFinite(ox) ? ox : 0, oy: isFinite(oy) ? oy : 0, items: items, arrows: arrows };
  }
  /** Onde colar: com o cursor (at, ponto do mundo) o canto vai para lá; sem cursor fica onde estava (+24 no mesmo Topperboard). */
  function clipPlace(pack, at, sameBoard) {
    var d = sameBoard ? 24 : 0, bx = at ? at.x : pack.ox + d, by = at ? at.y : pack.oy + d;
    return pack.items.map(function (it) { var o = JSON.parse(JSON.stringify(it)); if (!it.col) { o.x = Math.round(bx + it.x); o.y = Math.round(by + it.y); } return o; });
  }
  /** Resumo em texto do que se copiou (vai também para a área de transferência do sistema). */
  function clipText(pack) {
    return pack.items.map(function (it) {
      var c = it.c || {};
      return c.title || c.text || c.caption || c.hex || (c.items ? c.items.map(function (x) { return '- ' + x.t; }).join('\n') : '');
    }).filter(Boolean).join('\n');
  }

  /** O cartão a publicar no tempo real (JSON sem os campos locais), ou '' quando nem todos o podem ver (§33.9: ficheiro
   *  da Faturação marcado «restrito», ou «sem acesso» para quem o publica): esse chega a cada um pelo servidor. */
  function rtPub(c) {
    var a = c && c.asset;
    if (!c || c.noaccess || (a && (a.restricted || a.noaccess))) return '';
    var pub = {};
    Object.keys(c).forEach(function (k) { if (k !== '_mh' && k !== '__new') pub[k] = c[k]; });
    return JSON.stringify(pub);
  }

  /** O pacote guardado ao copiar, se o texto da área de transferência for o que se copiou (senão null). */
  function clipMatch(stored, text) {
    return stored && stored.pack && text && text === stored.txt ? stored.pack : null;
  }

  // ── o tipo pelo conteúdo: áudio num contentor de vídeo ou com extensão estranha (o MP3 «WhatsApp Audio … .mpeg», que o
  //    browser dá como video/mpeg). A mesma regra que cc_media_sniff (admin/inc/cc.php). Nunca se decide por o browser
  //    não conseguir mostrar a imagem: um H.264+Opus, HEVC ou ProRes que o Chromium não descodifica continua vídeo. ──
  var ISO_TOP = ['ftyp', 'moov', 'mdat', 'free', 'skip', 'wide', 'pnot', 'uuid'];
  var MPA_BR = [[0, 32, 64, 96, 128, 160, 192, 224, 256, 288, 320, 352, 384, 416, 448], [0, 32, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320, 384],
                [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320], [0, 32, 48, 56, 64, 80, 96, 112, 128, 144, 160, 176, 192, 224, 256],
                [0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160]];
  var MPA_SR = { 3: [44100, 48000, 32000], 2: [22050, 24000, 16000], 0: [11025, 12000, 8000] };
  function bstr(b, p, n) { var s = ''; for (var i = p; i < p + n && i < b.length; i++) s += String.fromCharCode(b[i]); return s; }
  function u32(b, p) { return b[p] * 16777216 + (b[p + 1] << 16) + (b[p + 2] << 8) + b[p + 3]; }
  function asVideo() { return { kind: 'video', mime: '', dur: null }; }
  function asAudio(mime, dur) { return { kind: 'audio', mime: mime, dur: dur == null ? null : dur }; }
  /** Um cabeçalho de frame MPEG áudio (camadas I–III) em p, ou null. */
  function mpa(b, p) {
    if (p + 4 > b.length || b[p] !== 0xFF || (b[p + 1] & 0xE0) !== 0xE0) return null;
    var ver = (b[p + 1] >> 3) & 3, layer = (b[p + 1] >> 1) & 3, bri = b[p + 2] >> 4, sri = (b[p + 2] >> 2) & 3;
    if (ver === 1 || layer === 0 || bri === 0 || bri === 15 || sri === 3) return null;
    var L = 4 - layer, br = MPA_BR[ver === 3 ? L - 1 : (L === 1 ? 3 : 4)][bri] * 1000, sr = MPA_SR[ver][sri], pad = (b[p + 2] >> 1) & 1;
    var len = L === 1 ? (Math.floor(12 * br / sr) + pad) * 4 : Math.floor((L === 3 && ver !== 3 ? 72 : 144) * br / sr) + pad;
    return { v1: ver === 3, L: L, sr: sr, len: len, mono: (b[p + 3] >> 6) === 3 };
  }
  /** A duração pelo cabeçalho Xing/Info do primeiro frame (o que o ffmpeg/Lavf escreve), ou null. */
  function xingDur(b, p, f) {
    var o = p + 4 + (f.v1 ? (f.mono ? 17 : 32) : (f.mono ? 9 : 17)), t = bstr(b, o, 4);
    if ((t !== 'Xing' && t !== 'Info') || o + 12 > b.length || !(u32(b, o + 4) & 1)) return null;
    return Math.round(u32(b, o + 8) * (f.L === 1 ? 384 : f.L === 2 || f.v1 ? 1152 : 576) / f.sr * 1000) / 1000;
  }
  /** Um frame AAC em ADTS em p (com o seguinte, se couber): o comprimento, ou 0. */
  function adts(b, p) {
    if (p + 7 > b.length || b[p] !== 0xFF || (b[p + 1] & 0xF6) !== 0xF0 || ((b[p + 2] >> 2) & 15) > 12) return 0;
    var len = ((b[p + 3] & 3) << 11) | (b[p + 4] << 3) | (b[p + 5] >> 5);
    if (len < 7 || (p + len + 2 <= b.length && (b[p + len] !== 0xFF || (b[p + len + 1] & 0xF6) !== 0xF0))) return 0;
    return len;
  }
  /** Uma caixa MP4/MOV em p: [comprimento, tipo, cabeçalho], ou null (max: até onde pode ir). */
  function isoBox(b, p, max) {
    if (p + 8 > b.length) return null;
    var len = u32(b, p), type = bstr(b, p + 4, 4), hl = 8;
    if (len === 1) { if (p + 16 > b.length) return null; len = u32(b, p + 8) * 4294967296 + u32(b, p + 12); hl = 16; }
    else if (len === 0) len = max;
    return len < hl || len > max ? null : [len, type, hl];
  }
  /** A primeira caixa `want` entre s e e (e: o fim lógico da caixa mãe): [início do corpo, fim], ou null. */
  function isoChild(b, s, e, want) {
    while (s + 8 <= Math.min(e, b.length)) {
      var x = isoBox(b, s, e - s);
      if (!x) return null;
      if (x[1] === want) return [s + x[2], s + x[0]];
      s += x[0];
    }
    return null;
  }
  /** O moov (m: a partir do cabeçalho; todo, ou só o princípio): uma pista vide → vídeo; só soun, todas vistas → áudio. */
  function moovKind(m, end, hl) {
    var p = hl, video = false, sound = false, unknown = false, dur = null;
    while (p + 8 <= m.length && p < end) {
      var x = isoBox(m, p, end - p);
      if (!x) break;
      if (x[1] === 'trak') {
        var md = isoChild(m, p + x[2], p + x[0], 'mdia'), h = md && isoChild(m, md[0], md[1], 'hdlr');
        var t = h && h[0] + 12 <= m.length ? bstr(m, h[0] + 8, 4) : null;
        if (t === 'vide' || t === 'pict' || t === 'auxv') video = true; else if (t === 'soun') sound = true; else if (t === null) unknown = true;
      } else if (x[1] === 'mvhd' && p + x[2] + 32 <= m.length) {
        var q = p + x[2], v1 = m[q] === 1, ts = u32(m, q + (v1 ? 20 : 12)), d = v1 ? u32(m, q + 24) * 4294967296 + u32(m, q + 28) : u32(m, q + 16);
        if (ts > 0) dur = Math.round(d / ts * 1000) / 1000;
      }
      p += x[0];
    }
    if (video) return asVideo();
    return sound && !unknown && p >= end ? asAudio('audio/mp4', dur) : null;   // uma parte por ver: não se sabe
  }
  function isoKind(head, tail, size) {
    var t0 = size - (tail ? tail.length : 0);
    function at(p, n) {
      if (p >= 0 && p + n <= head.length) return head.subarray(p, p + n);
      if (tail && p >= t0 && p + n <= size) return tail.subarray(p - t0, p - t0 + n);
      return null;
    }
    for (var p = 0, i = 0; i < 64 && p + 8 <= size; i++) {
      var h = at(p, 16) || at(p, 8), x = h && isoBox(h, 0, size - p);
      if (!x) return null;
      if (x[1] === 'moov') {
        var m = at(p, x[0]);
        if (m) return moovKind(m, x[0], x[2]);
        return p < head.length ? moovKind(head.subarray(p), x[0], x[2]) : null;   // só o princípio do moov: diz «vídeo», nunca «áudio»
      }
      p += x[0];
    }
    return null;
  }
  /** Um número EBML (WebM/Matroska) em p: [valor, bytes, tamanho desconhecido]; id: os IDs guardam o marcador. */
  function vint(b, p, id) {
    if (p >= b.length) return null;
    var c = b[p], len = 1, mask = 0x80;
    while (len <= 8 && !(c & mask)) { len++; mask >>= 1; }
    if (len > (id ? 4 : 8) || p + len > b.length) return null;
    var v = id ? c : c & (mask - 1), all = (c & (mask - 1)) === mask - 1;
    for (var i = 1; i < len; i++) { v = v * 256 + b[p + i]; if (b[p + i] !== 0xFF) all = false; }
    return [v, len, !id && all];
  }
  function ebmlEl(b, p) { var i = vint(b, p, true), s = i && vint(b, p + i[1], false); return s ? { id: i[0], at: p + i[1] + s[1], size: s[2] ? -1 : s[0] } : null; }
  function ebmlKind(b) {
    var e = ebmlEl(b, 0), doc = '';
    if (!e || e.id !== 0x1A45DFA3 || e.size < 0) return null;
    for (var p = e.at; p < Math.min(b.length, e.at + e.size);) { var c = ebmlEl(b, p); if (!c || c.size < 0) break; if (c.id === 0x4282) doc = bstr(b, c.at, c.size); p = c.at + c.size; }
    var s = ebmlEl(b, e.at + e.size);
    if (!s || s.id !== 0x18538067) return null;
    var end = s.size < 0 ? Infinity : s.at + s.size;
    for (var q = s.at, k = 0; q < end && k < 256; k++) {
      var c2 = ebmlEl(b, q);
      if (!c2 || c2.size < 0 || c2.id === 0x1F43B675) return null;   // os dados (Cluster) antes das pistas: não se sabe
      if (c2.id === 0x1654AE6B) return tracksKind(b, c2.at, c2.at + c2.size, doc);
      q = c2.at + c2.size;
    }
    return null;
  }
  /** As pistas (Tracks): TrackType 1 ou CodecID V_… → vídeo; só 2/A_…, todas vistas → áudio. */
  function tracksKind(b, s, e, doc) {
    var video = false, audio = false;
    for (var p = s; p < Math.min(e, b.length);) {
      var t = ebmlEl(b, p);
      if (!t || t.size < 0) break;
      if (t.id === 0xAE) {
        for (var r = t.at; r < Math.min(t.at + t.size, b.length);) {
          var f = ebmlEl(b, r);
          if (!f || f.size < 0) break;
          if (f.id === 0x83) { var tt = 0; for (var i = 0; i < f.size && f.at + i < b.length; i++) tt = tt * 256 + b[f.at + i]; if (tt === 1) video = true; else if (tt === 2) audio = true; }
          if (f.id === 0x86) { var cid = bstr(b, f.at, Math.min(f.size, 64)).slice(0, 2); if (cid === 'V_') video = true; else if (cid === 'A_') audio = true; }
          r = f.at + f.size;
        }
      }
      p = t.at + t.size;
    }
    if (video) return asVideo();
    return audio && e <= b.length && p >= e ? asAudio(doc === 'webm' ? 'audio/webm' : 'audio/x-matroska') : null;
  }
  /** Ogg: as primeiras páginas (BOS) dizem o codec de cada fluxo. */
  function oggKind(b) {
    var p = 0, audio = false;
    while (p + 27 <= b.length && bstr(b, p, 4) === 'OggS' && (b[p + 5] & 2)) {
      var n = b[p + 26], len = 0;
      if (p + 27 + n > b.length) break;
      for (var i = 0; i < n; i++) len += b[p + 27 + i];
      var pk = bstr(b, p + 27 + n, Math.min(len, 16));
      if (/^(\x80theora|\x01video|BBCD)/.test(pk)) return asVideo();
      if (/^(OpusHead|\x01vorbis|\x7fFLAC|Speex)/.test(pk)) audio = true;
      p += 27 + n + len;
    }
    return audio ? asAudio('audio/ogg') : null;
  }
  /** MP4/MOV: o moov pode estar mais à frente (grande) ou no fim — vale a pena ler mais (até 2 MB do início e do fim). */
  function sniffMore(head) { return !!head && head.length >= 8 && ISO_TOP.indexOf(bstr(head, 4, 4)) >= 0; }
  /**
   * O que um ficheiro de media é, pelos bytes. head: o princípio (Uint8Array, 64 KB chegam quase sempre); tail: o fim
   * (para o moov no fim dos MP4/MOV; ou null); size: o tamanho do ficheiro. Devolve { kind: 'audio', mime, dur }
   * (dur em segundos, ou null), { kind: 'video', mime: '' } ou null quando não se sabe (fica como estava).
   */
  function sniffMedia(head, tail, size) {
    head = head || new Uint8Array(0);
    var n = head.length;
    size = Math.max(+size || 0, n);
    if (n < 4) return null;
    var s4 = bstr(head, 0, 4);
    if (bstr(head, 0, 3) === 'ID3' && n >= 10) {   // ID3v2: o que vem a seguir à etiqueta diz o formato (por omissão MP3)
      var p = 10 + (head[6] & 0x7F) * 2097152 + (head[7] & 0x7F) * 16384 + (head[8] & 0x7F) * 128 + (head[9] & 0x7F) + (head[5] & 0x10 ? 10 : 0);
      if (bstr(head, p, 4) === 'fLaC') return asAudio('audio/flac');
      if (adts(head, p)) return asAudio('audio/aac');
      var f = mpa(head, p);
      return asAudio('audio/mpeg', f ? xingDur(head, p, f) : null);
    }
    if (s4 === 'fLaC') return asAudio('audio/flac');
    if (s4 === 'OggS') return oggKind(head);
    if (s4 === 'RIFF' && n >= 12) { var r = bstr(head, 8, 4); return r === 'WAVE' ? asAudio('audio/wav') : r === 'AVI ' ? asVideo() : null; }
    if (s4 === 'FORM' && n >= 12 && /^AIF[FC]$/.test(bstr(head, 8, 4))) return asAudio('audio/aiff');
    if (sniffMore(head)) return isoKind(head, tail || null, size);
    if (head[0] === 0x1A && head[1] === 0x45 && head[2] === 0xDF && head[3] === 0xA3) return ebmlKind(head);
    var f0 = mpa(head, 0);
    if (f0) {   // frames MPEG sem etiqueta: o seguinte tem de estar onde o primeiro diz (se couber no que se leu)
      var g = f0.len + 4 <= n ? mpa(head, f0.len) : f0;
      return g && g.v1 === f0.v1 && g.L === f0.L && g.sr === f0.sr ? asAudio('audio/mpeg', xingDur(head, 0, f0)) : null;
    }
    return adts(head, 0) ? asAudio('audio/aac') : null;
  }

  return { version: 1, sniffMedia: sniffMedia, sniffMore: sniffMore, rtPub: rtPub, clipMatch: clipMatch, clipPack: clipPack, clipPlace: clipPlace, clipText: clipText, marqueeHit: marqueeHit, avg3: avg3, fitRect: fitRect, pickPx: pickPx, ink: ink, zoomAbout: zoomAbout, hexTo: hexTo, thumbPick: thumbPick, undoPlan: undoPlan, skippedMsg: skippedMsg, same: same, strokeHit: strokeHit, fitStroke: fitStroke, drawBox: drawBox, toLocal: toLocal, listKey: listKey, listPaste: listPaste, listClean: listClean, BRAND: BRAND, hexParse: hexParse, recentColors: recentColors };
});
