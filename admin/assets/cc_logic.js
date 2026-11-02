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
  /** A cor em (x, y) de uma imagem RGBA (largura w): média 3×3, ou o píxel exato com Alt. */
  function avg3(data, w, x, y, alt) {
    var h = Math.floor(data.length / 4 / w), r = 0, g = 0, b = 0, n = 0, R = alt ? 0 : 1;
    for (var dy = -R; dy <= R; dy++) for (var dx = -R; dx <= R; dx++) {
      var xx = x + dx, yy = y + dy;
      if (xx < 0 || yy < 0 || xx >= w || yy >= h) continue;
      var i = (yy * w + xx) * 4; r += data[i]; g += data[i + 1]; b += data[i + 2]; n++;
    }
    var hx = function (v) { return ('0' + Math.round(v / (n || 1)).toString(16)).slice(-2); };
    return ('#' + hx(r) + hx(g) + hx(b)).toUpperCase();
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

  return { version: 1, rtPub: rtPub, clipMatch: clipMatch, clipPack: clipPack, clipPlace: clipPlace, clipText: clipText, marqueeHit: marqueeHit, avg3: avg3, hexTo: hexTo, thumbPick: thumbPick, undoPlan: undoPlan, skippedMsg: skippedMsg, same: same, strokeHit: strokeHit, fitStroke: fitStroke, drawBox: drawBox, toLocal: toLocal, listKey: listKey, listPaste: listPaste, listClean: listClean, BRAND: BRAND, hexParse: hexParse, recentColors: recentColors };
});
