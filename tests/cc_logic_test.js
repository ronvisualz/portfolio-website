// Lógica pura do Toppernote (§33): node tests/cc_logic_test.js
const CCL = require('../admin/assets/cc_logic.js');
let ok = 0, fail = 0;
const eq = (a, b, msg) => { const A = JSON.stringify(a), Bv = JSON.stringify(b); if (A === Bv) ok++; else { fail++; console.error('FALHA: ' + msg + '\n  esperado ' + Bv + '\n  obtido   ' + A); } };
const T = items => items.map(i => i.t);

eq(CCL.version, 1, 'CCL.version === 1');

// ── listas (§33.1) ──
let r = CCL.listKey([{ t: 'a' }], 0, 'Enter', { atEnd: true });
eq([r.items.length, r.focus, r.handled, r.items[1]], [2, 1, true, { t: '', d: false, l: 0 }], 'Enter no fim cria o item seguinte vazio');
r = CCL.listKey([{ t: 'a' }, { t: '' }], 1, 'Enter', { atEnd: true, empty: true });
eq([T(r.items), r.focus], [['a', '', ''], 2], 'Enter num vazio a seguir a outro vazio cria outro (vários vazios seguidos)');
r = CCL.listKey([{ t: 'abcd' }], 0, 'Enter', { caret: 2 });
eq([T(r.items), r.focus], [['ab', 'cd'], 1], 'Enter a meio parte o item no cursor');
r = CCL.listKey([{ t: 'a', l: 1 }], 0, 'Enter', { atEnd: true });
eq(r.items[1].l, 1, 'o item novo fica no mesmo nível');
r = CCL.listKey([{ t: 'a' }, { t: 'b' }, { t: '' }], 2, 'Backspace', { empty: true, atStart: true });
eq([T(r.items), r.focus, r.handled], [['a', 'b'], 1, true], 'Backspace num vazio apaga-o e sobe');
r = CCL.listKey([{ t: '' }, { t: 'b' }], 0, 'Backspace', { empty: true, atStart: true });
eq([T(r.items), r.focus, r.handled], [['b'], 0, true], 'Backspace no primeiro vazio apaga-o e fica no seguinte');
r = CCL.listKey([{ t: '' }], 0, 'Backspace', { empty: true, atStart: true });
eq(r.handled, false, 'Backspace com um só item vazio não apaga (fica um item editável)');
r = CCL.listKey([{ t: 'a' }, { t: 'b' }], 1, 'Backspace', { atStart: false });
eq(r.handled, false, 'Backspace num item com texto é o normal do browser');
r = CCL.listKey([{ t: 'a' }, { t: 'b' }], 1, 'Tab', {});
eq([r.items[1].l, r.handled, r.focus], [1, true, 1], 'Tab passa a sub-item');
r = CCL.listKey([{ t: 'a' }, { t: 'b', l: 2 }], 1, 'Tab', {});
eq(r.items[1].l, 2, 'Tab não passa do nível 2');
r = CCL.listKey([{ t: 'a' }, { t: 'b', l: 1 }], 1, 'Tab', { shift: true });
eq(r.items[1].l, 0, 'Shift Tab sobe um nível');
r = CCL.listKey([{ t: 'a' }], 0, 'Tab', { shift: true });
eq([r.items[0].l, r.handled], [0, true], 'Shift Tab no nível 0 fica (e não sai do campo)');
r = CCL.listKey([{ t: 'a', d: false }], 0, 'Enter', { ctrl: true, atEnd: true });
eq([r.items.length, r.items[0].d, r.handled], [1, true, true], 'Ctrl Enter marca como feito, sem criar item');
r = CCL.listKey([{ t: 'a', d: true }], 0, 'Enter', { ctrl: true });
eq(r.items[0].d, false, 'Ctrl Enter outra vez desmarca');
r = CCL.listKey([{ t: 'a' }], 0, 'Enter', { shift: true });
eq(r.handled, false, 'Shift Enter não é tratado');
r = CCL.listKey([{ t: 'a' }, { t: 'b' }, { t: 'c' }], 2, 'ArrowUp', { alt: true });
eq([T(r.items), r.focus], [['a', 'c', 'b'], 1], 'Alt ↑ sobe o item (reordenar pelo teclado)');
r = CCL.listKey([{ t: 'a' }, { t: 'b' }], 1, 'ArrowDown', { alt: true });
eq([T(r.items), r.focus, r.handled], [['a', 'b'], 1, true], 'Alt ↓ no último fica');
const src = [{ t: 'a' }];
CCL.listKey(src, 0, 'Enter', { atEnd: true });
eq(src, [{ t: 'a' }], 'não muda a lista recebida');

r = CCL.listPaste([{ t: 'a' }], 0, 'x\n\ny\r\nz');
eq([T(r.items), r.focus], [['a', 'x', 'y', 'z'], 3], 'colar 3 linhas insere 3 itens depois do atual (vazias ignoradas)');
r = CCL.listPaste([{ t: '' }, { t: 'b' }], 0, 'x\ny');
eq([T(r.items), r.focus], [['x', 'y', 'b'], 1], 'colar num item vazio usa-o para a primeira linha');
r = CCL.listPaste([{ t: 'a' }], 0, Array.from({ length: 250 }, (_, i) => 'l' + i).join('\n'));
eq(r.items.length, 200, 'colar muitas linhas fica no máximo de 200 itens');
r = CCL.listPaste([{ t: 'a' }], 0, '  - um\n* dois\n[ ] três\n[x] quatro\n1. cinco');
eq([T(r.items).slice(1), r.items[4].d], [['um', 'dois', 'três', 'quatro', 'cinco'], true], 'tira marcadores de listas coladas; [x] fica feito');

eq(CCL.listClean([{ t: 'a' }, { t: '' }, { t: ' ' }]), [{ t: 'a' }], 'listClean tira os vazios do fim');
eq(CCL.listClean([{ t: '' }, { t: 'a' }, { t: '' }]), [{ t: '' }, { t: 'a' }], 'listClean só tira os do fim');
eq(CCL.listClean([{ t: '' }, { t: '' }]), [{ t: '' }], 'listClean deixa pelo menos 1');

// ── cores (§33.2) ──
eq(CCL.hexParse('  ffc718 '), '#FFC718', 'hexParse aceita sem # e com espaços');
eq(CCL.hexParse('#abc'), '#AABBCC', 'hexParse alarga 3 dígitos');
eq(CCL.hexParse('#2f77b1'), '#2F77B1', 'hexParse põe em maiúsculas');
eq(CCL.hexParse('azul'), null, 'hexParse recusa texto que não é cor');
eq(CCL.hexParse('#12345'), null, 'hexParse recusa 5 dígitos');
eq(CCL.hexParse('rgb(47, 119, 177)'), '#2F77B1', 'hexParse aceita rgb()');
eq(CCL.recentColors(['#111111'], '#ffc718'), ['#FFC718', '#111111'], 'recentColors põe a nova à frente');
eq(CCL.recentColors(['#FFC718', '#111111'], '#111111'), ['#111111', '#FFC718'], 'recentColors sem duplicados');
eq(CCL.recentColors(['#000001', '#000002', '#000003', '#000004', '#000005', '#000006', '#000007', '#000008'], '#000009').length, 8, 'recentColors corta a 8');
eq(CCL.recentColors(['#111111'], 'azul'), ['#111111'], 'recentColors ignora o que não é cor');
eq(CCL.BRAND, ['#111111', '#FFC718', '#E8846D', '#81CFE3', '#2F77B1'], 'cores da marca (1–5)');

// ── desenho (§33.3) ──
eq(CCL.strokeHit([0, 0, 100, 0], 50, 3, 4), true, 'strokeHit: perto do segmento → toca');
eq(CCL.strokeHit([0, 0, 100, 0], 50, 5, 4), false, 'strokeHit: a r+1 → não toca');
eq(CCL.strokeHit([0, 0, 100, 0], 104, 0, 4), true, 'strokeHit: perto da ponta → toca');
eq(CCL.strokeHit([0, 0, 0, 0], 2, 2, 4), true, 'strokeHit: traço de um ponto');
r = CCL.fitStroke([100, 50, 200, 150]);
eq(r, { pts: [6, 6, 106, 106], vw: 112, vh: 112, x: 94, y: 44 }, 'fitStroke: relativo ao canto, com 6 px de margem');
r = CCL.fitStroke([10, 10, 12, 10]);
eq([r.vw, r.vh], [20, 20], 'fitStroke: caixa mínima 20 × 20');
eq(CCL.drawBox([{ pts: [6, 6, 306, 186] }, { pts: [10, 20, 40, 60] }]), { vw: 312, vh: 192 }, 'drawBox: desenhos antigos sem caixa usam a dos traços');
eq(CCL.toLocal({ w: 640, h: 400 }, { vw: 320, vh: 200 }, 320, 200), [160, 100], 'toLocal: o meio do cartão a 200% é o meio do desenho');
eq(CCL.toLocal({ w: 400, h: 400 }, { vw: 200, vh: 100 }, 200, 200), [100, 50], 'toLocal: com proporção diferente fica centrado (meet)');

// ── anular por pessoa (§33.10) ──
const cur = new Map([
  [1, { id: 1, x: 100, y: 50, w: 240, h: 0, col: null, ord: 0, lane: '', c: { text: 'olá', color: '', brief: false } }],
  [2, { id: 2, x: 300, y: 80, w: 240, h: 0, col: null, ord: 0, lane: '', c: { text: 'b' } }],
]);
const mv = { t: 'move', items: [
  { before: { id: 1, x: 0, y: 0, w: 240, h: 0, col: null, ord: 0, lane: '' }, after: { id: 1, x: 100, y: 50, w: 240, h: 0, col: null, ord: 0, lane: '' } },
  { before: { id: 2, x: 0, y: 0, w: 240, h: 0, col: null, ord: 0, lane: '' }, after: { id: 2, x: 200, y: 80, w: 240, h: 0, col: null, ord: 0, lane: '' } },
] };
r = CCL.undoPlan(mv, cur, false);
eq([r.apply.map(s => s.id), r.skipped], [[1], 1], 'move: o cartão mexido por outra pessoa fica; o meu volta');
eq(r.apply[0].x, 0, 'move: repõe o estado de antes');
r = CCL.undoPlan(mv, new Map([[1, Object.assign({}, cur.get(1), { x: 0, y: 0 })]]), true);
eq([r.apply.map(s => s.x), r.skipped], [[100], 1], 'refazer move: só se o cartão está como ficou depois de anular; apagado conta como saltado');
const ct = { t: 'content', id: 1, before: { text: 'oi' }, after: { text: 'olá ' } };
r = CCL.undoPlan(ct, cur, false);
eq([r.apply, r.skipped], [[{ id: 1, c: { text: 'oi' } }], 0], 'content igual (o servidor normaliza espaços e campos por defeito) → aplica');
r = CCL.undoPlan(ct, new Map([[1, Object.assign({}, cur.get(1), { c: { text: 'mudado por outra pessoa' } })]]), false);
eq([r.apply.length, r.skipped], [0, 1], 'content mudado por outra pessoa → saltado');
r = CCL.undoPlan({ t: 'add', ids: [1, 9] }, cur, false);
eq([r.apply, r.skipped], [[1], 1], 'add: anular só apaga os que ainda existem');
r = CCL.undoPlan({ t: 'del', ids: [1, 9] }, cur, false);
eq([r.apply, r.skipped], [[9], 1], 'del: anular só repõe os que não existem');
r = CCL.undoPlan({ t: 'multi', list: [ct, { t: 'add', ids: [9] }] }, cur, false);
eq([r.apply.length, r.skipped], [2, 1], 'multi: soma os saltados de cada parte');
eq(CCL.skippedMsg(1), '1 alteração não anulada: outra pessoa mudou este cartão.', 'aviso no singular');
eq(CCL.skippedMsg(3), '3 alterações não anuladas: outras pessoas mudaram estes cartões.', 'aviso no plural');

// ── miniaturas (§33.5) ──
eq(CCL.thumbPick([256, 768, 1600], 280, 1, 2), 768, 'thumbPick: 280 × 1 × 2 → 768');
eq(CCL.thumbPick([256, 768, 1600], 280, 3, 2), 1600, 'thumbPick: 280 × 3 × 2 → 1600');
eq(CCL.thumbPick([], 280, 1, 1), 0, 'thumbPick: sem miniaturas → original');
eq(CCL.thumbPick([1600, 256, 768], 100, 1, 1), 256, 'thumbPick: lista fora de ordem');
eq(CCL.thumbPick([256, 768, 1600], 280, 5, 2, 6481), 0, 'thumbPick: muito zoom numa imagem grande → original');
eq(CCL.thumbPick([256, 768, 1600], 280, 5, 2, 1600), 1600, 'thumbPick: a imagem não é maior do que a miniatura → fica a miniatura');

// ── conta-gotas (§33.11) ──
const img = (w, h, f) => { const d = new Uint8ClampedArray(w * h * 4); for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) { const c = f(x, y), i = (y * w + x) * 4; d[i] = c[0]; d[i + 1] = c[1]; d[i + 2] = c[2]; d[i + 3] = 255; } return d; };
const D = img(5, 5, (x, y) => x === 2 && y === 2 ? [220, 40, 40] : [200, 40, 40]);
eq(CCL.avg3(D, 5, 2, 2, false), '#CA2828', 'avg3: média 3×3 (8 × 200 + 220) / 9 = 202');
eq(CCL.avg3(D, 5, 2, 2, true), '#DC2828', 'avg3 com Alt: o píxel exato');
eq(CCL.avg3(D, 5, 0, 0, false), '#C82828', 'avg3 no canto: só os vizinhos que existem');
eq(CCL.hexTo('#7C7774', 'rgb'), 'rgb(124, 119, 116)', 'hexTo rgb');
eq(CCL.hexTo('#7C7774', 'hsl'), 'hsl(23, 3%, 47%)', 'hexTo hsl');
eq(CCL.hexTo('#FFFFFF', 'hsl'), 'hsl(0, 0%, 100%)', 'hexTo hsl branco');

// ── seleção por retângulo (§33.12) ──
const boxes = [{ id: 1, x: 0, y: 0, w: 100, h: 100 }, { id: 2, x: 200, y: 0, w: 100, h: 100 }, { id: 3, x: 0, y: 300, w: 100, h: 100 }];
eq(CCL.marqueeHit(boxes, { x: 90, y: 50, w: 150, h: 10 }), [1, 2], 'marqueeHit: toca a borda de dois cartões → os dois');
eq(CCL.marqueeHit(boxes, { x: 110, y: 110, w: 80, h: 80 }), [], 'marqueeHit: não toca nenhum');
eq(CCL.marqueeHit(boxes, { x: 250, y: 50, w: -200, h: 300 }), [1, 2, 3], 'marqueeHit: arrasto para trás (largura/altura negativas) normalizado');
eq(CCL.marqueeHit(boxes, { x: 100, y: 0, w: 0, h: 0 }), [1], 'marqueeHit: um ponto na borda conta');

// ── copiar e colar (§33.6) ──
const cm = new Map([
  [10, { id: 10, type: 'column', x: 100, y: 100, w: 300, h: 0, c: { title: 'Col' }, col: null, ord: 0 }],
  [11, { id: 11, type: 'note', x: 0, y: 0, w: 240, h: 0, c: { text: 'a' }, col: 10, ord: 0 }],
  [12, { id: 12, type: 'image', x: 0, y: 0, w: 240, h: 0, c: {}, col: 10, ord: 1, asset: { id: 7 } }],
  [20, { id: 20, type: 'note', x: 400, y: 150, w: 240, h: 0, c: { text: 'b' }, col: null, ord: 0 }],
  [25, { id: 25, type: 'board', x: 700, y: 150, w: 240, h: 0, c: { title: 'Sub' }, col: null, ord: 0, ref: 55 }],
  [30, { id: 30, type: 'arrow', x: 0, y: 0, w: 0, h: 0, c: { from: 20, to: 11, label: 'x' } }],
  [31, { id: 31, type: 'arrow', x: 0, y: 0, w: 0, h: 0, c: { from: 20, to: 99 } }],
]);
const pk = CCL.clipPack(cm, [10, 20, 30, 31], 5);
eq(pk.items.map(i => i.ref), [10, 11, 12, 20], 'clipPack: a coluna leva os cartões de dentro, pela ordem');
eq([pk.ox, pk.oy, pk.board], [100, 100, 5], 'clipPack: canto de cima à esquerda e Topperboard de origem');
eq([pk.items[3].x, pk.items[3].y], [300, 50], 'clipPack: coordenadas relativas ao canto');
eq([pk.items[1].col, pk.items[2].asset], [10, 7], 'clipPack: filhos apontam para a coluna; a referência vai pelo id');
eq(pk.arrows, [{ from: 20, to: 11, c: { from: 20, to: 11, label: 'x' } }], 'clipPack: só as setas com as duas pontas copiadas');
eq(CCL.clipPack(cm, [25], 5).items[0].sub, 55, 'clipPack: sub-Topperboard copia-se como cartão que aponta para o mesmo');
let pl = CCL.clipPlace(pk, null, true);
eq([pl[0].x, pl[0].y, pl[3].x, pl[3].y], [124, 124, 424, 174], 'clipPlace: no mesmo Topperboard sem cursor desloca +24');
pl = CCL.clipPlace(pk, null, false);
eq([pl[0].x, pl[3].x], [100, 400], 'clipPlace: noutro Topperboard sem cursor fica na posição original');
pl = CCL.clipPlace(pk, { x: 1000, y: 2000 }, true);
eq([pl[0].x, pl[0].y, pl[3].x, pl[3].y], [1000, 2000, 1300, 2050], 'clipPlace: com cursor, o canto vai para o cursor');
eq(CCL.clipText(pk), 'Col\na\nb', 'clipText: resumo legível (títulos e textos)');

// §33.6 (revisão final): o pacote guardado só se reconhece pelo texto igual ao que foi copiado; sem texto não cola nada antigo
{ const st = { ts: Date.now() - 3600e3, txt: 'Col\na\nb', pack: pk };
  eq(CCL.clipMatch(st, 'Col\na\nb'), pk, 'clipMatch: texto igual → o pacote');
  eq(CCL.clipMatch(st, ''), null, 'clipMatch: área de transferência sem texto → nada (não cola o pacote antigo)');
  eq(CCL.clipMatch(st, 'outra coisa'), null, 'clipMatch: texto diferente → nada');
  eq(CCL.clipMatch(null, 'x'), null, 'clipMatch: sem pacote guardado → nada'); }

console.log(fail ? `FALHOU ${fail} de ${ok + fail}` : `OK ${ok}`);
process.exit(fail ? 1 : 0);
