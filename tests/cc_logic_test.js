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

console.log(fail ? `FALHOU ${fail} de ${ok + fail}` : `OK ${ok}`);
process.exit(fail ? 1 : 0);
