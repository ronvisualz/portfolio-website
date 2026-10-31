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

  return { version: 1, listKey: listKey, listPaste: listPaste, listClean: listClean };
});
