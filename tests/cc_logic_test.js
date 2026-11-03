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

// Faturação (§33.9): o tempo real não espalha o cartão de um ficheiro que nem todos podem ver (o servidor dá-o a cada um)
{ const rtPub = typeof CCL.rtPub === 'function' ? CCL.rtPub : () => 'sem rtPub';
  const parse = s => { try { return JSON.parse(s); } catch (e) { return s; } };
  const pub = rtPub({ id: 7, type: 'image', x: 1, asset: { id: 3, title: 'foto' }, _mh: 40, __new: true });
  eq(parse(pub), { id: 7, type: 'image', x: 1, asset: { id: 3, title: 'foto' } }, 'rtPub: um cartão normal vai inteiro (sem os campos locais)');
  eq(rtPub({ id: 8, type: 'image', asset: { id: 4, title: 'recibo', restricted: true } }), '', 'rtPub: referência «restrita» (Faturação) → não vai pelo tempo real');
  eq(rtPub({ id: 9, type: 'image', noaccess: true, asset: { id: 4, title: '', noaccess: true } }), '', 'rtPub: cartão «sem acesso» → também não (quem o vê teria o vazio)');
  eq(parse(rtPub({ id: 10, type: 'note', c: { text: 'olá' } })), { id: 10, type: 'note', c: { text: 'olá' } }, 'rtPub: nota sem referência vai inteira'); }

// Áudio num contentor de vídeo ou com extensão estranha (o «WhatsApp Audio … .mpeg», MP3 com ID3 dado como video/mpeg):
// o tipo decide-se pelos bytes (a mesma regra que cc_media_sniff no servidor), nunca por o browser não mostrar a imagem
{ const sniff = typeof CCL.sniffMedia === 'function' ? CCL.sniffMedia : () => 'sem sniffMedia';
  const B = (...a) => Buffer.concat(a.map(x => typeof x === 'string' ? Buffer.from(x, 'latin1') : Buffer.from(x)));
  const N = n => { const b = Buffer.alloc(4); b.writeUInt32BE(n); return b; };
  const Z = n => Buffer.alloc(n);
  const box = (t, ...body) => { const b = B(...body); return B(N(8 + b.length), t, b); };
  const hdlr = (h, pre = Z(4)) => box('hdlr', Z(4), pre, h, Z(13));
  const trak = (h, stbl = 64, pre = Z(4)) => box('trak', box('tkhd', Z(84)), box('mdia', box('mdhd', Z(24)), hdlr(h, pre), box('minf', hdlr('alis', 'dhlr'), box('stbl', Z(stbl)))));
  const ftyp = (brand = 'isom') => box('ftyp', brand, '\0\0\x02\0', brand, 'mp41');
  const moov = (...t) => box('moov', box('mvhd', Z(4), N(0), N(0), N(1000), N(5000), Z(80)), ...t);
  const S = (file, headLen = 65536, tailLen = 0) => sniff(file.subarray(0, headLen == null ? file.length : headLen), tailLen ? file.subarray(file.length - tailLen) : null, file.length);
  const K = r => r && typeof r === 'object' ? [r.kind, r.mime] : r;
  const frame = (h, len) => B(h, Z(len - 4));
  const mp3 = B(frame('\xFF\xFB\x90\x64', 417), frame('\xFF\xFB\x90\x64', 417), frame('\xFF\xFB\x90\x64', 417));
  const id3 = B('ID3\x04\0\0', '\0\0\0\x16', 'TSSE', '\0\0\0\x0C\0\0', '\x03Lavf60.3.10');
  eq(K(S(B(id3, mp3))), ['audio', 'audio/mpeg'], 'sniffMedia: ID3 + frames MPEG (o MP3 do WhatsApp) → áudio audio/mpeg');
  eq(K(S(B('ID3\x03\0\0\0\x01\0\0', Z(128)))), ['audio', 'audio/mpeg'], 'sniffMedia: ID3 maior do que o que se leu → áudio');
  eq(K(S(mp3)), ['audio', 'audio/mpeg'], 'sniffMedia: frames MPEG (FF FB) → áudio');
  eq(K(S(B(frame('\xFF\xF3\x80\x40', 208), frame('\xFF\xF3\x80\x40', 208)))), ['audio', 'audio/mpeg'], 'sniffMedia: MPEG-2 Layer III (FF F3) → áudio');
  eq(K(S(B(frame('\xFF\xFA\x90\x64', 417), frame('\xFF\xFA\x90\x64', 417)))), ['audio', 'audio/mpeg'], 'sniffMedia: frames com CRC (FF FA) → áudio');
  eq(S(B('\xFF\xFB\x90\x64', Buffer.alloc(413, 0x11), '\x12\x34\x56\x78', Z(400))), null, 'sniffMedia: FF FB sem o frame seguinte → desconhecido');
  eq(S(B('\xFF\xFB\xF0\x64', Z(1000))), null, 'sniffMedia: débito inválido → desconhecido');
  const info = B('\xFF\xFB\x90\x64', Z(32), 'Info', N(1), N(192), Z(417 - 4 - 32 - 12));
  const ri = S(B(id3, info, mp3));
  eq(!!ri && Math.abs(ri.dur - 192 * 1152 / 44100) < 0.01, true, 'sniffMedia: MP3 com Info → duração dos frames');
  const m4a = B(ftyp('M4A '), moov(trak('soun')), box('mdat', Z(2000)));
  eq(K(S(m4a)), ['audio', 'audio/mp4'], 'sniffMedia: MP4 só com som → áudio audio/mp4');
  eq(Math.abs(S(m4a).dur - 5) < 0.001, true, 'sniffMedia: MP4 de áudio → duração do mvhd');
  eq(K(S(B(ftyp(), moov(trak('vide'), trak('soun')), box('mdat', Z(2000))))), ['video', ''], 'sniffMedia: MP4 com vídeo → vídeo');
  eq(K(S(B(ftyp(), moov(trak('soun'), trak('vide')), box('mdat', Z(2000))))), ['video', ''], 'sniffMedia: vídeo depois do som → vídeo');
  const qt = B(ftyp('qt  '), box('wide'), box('mdat', Z(3000000)), moov(trak('vide', 64, 'mhlr'), trak('soun', 64, 'mhlr')));
  eq(K(S(qt, 65536, 2097152)), ['video', ''], 'sniffMedia: MOV com o moov no fim → vídeo');
  eq(S(qt, 65536, 0), null, 'sniffMedia: MOV com o moov no fim, sem o fim → desconhecido');
  eq(K(S(B(ftyp('M4A '), box('mdat', Z(3000000)), moov(trak('soun'))), 65536, 2097152)), ['audio', 'audio/mp4'], 'sniffMedia: só som com o moov no fim → áudio');
  const big = B(ftyp(), moov(trak('soun', 90000), trak('vide')), box('mdat', Z(1000)));
  eq(S(big, 65536, 0), null, 'sniffMedia: moov maior do que o lido, só o som à vista → desconhecido (um vídeo nunca vira áudio)');
  eq(K(S(big, null, 0)), ['video', ''], 'sniffMedia: o mesmo lido todo → vídeo');
  eq(K(S(B(ftyp(), moov(trak('soun', 90000)), box('mdat', Z(1000))), null, 0)), ['audio', 'audio/mp4'], 'sniffMedia: moov grande só de som, lido todo → áudio');
  const vint = n => n < 0x7F ? B([0x80 | n]) : n < 0x3FFF ? B([0x40 | (n >> 8), n & 0xFF]) : B([0x10, (n >> 16) & 0xFF, (n >> 8) & 0xFF, n & 0xFF]);
  const el = (id, ...body) => { const b = B(...body); return B(id, vint(b.length), b); };
  const ebml = doc => el('\x1A\x45\xDF\xA3', el('\x42\x82', doc));
  const entry = (t, codec) => el('\xAE', el('\xD7', '\x01'), el('\x83', B([t])), el('\x86', codec));
  const seg = tracks => B('\x18\x53\x80\x67\x01\xFF\xFF\xFF\xFF\xFF\xFF\xFF', el('\x15\x49\xA9\x66', el('\x2A\xD7\xB1', '\x0F\x42\x40')), el('\x16\x54\xAE\x6B', tracks), el('\x1F\x43\xB6\x75', Z(200)));
  eq(K(S(B(ebml('webm'), seg(entry(2, 'A_OPUS'))))), ['audio', 'audio/webm'], 'sniffMedia: WebM só com áudio → áudio audio/webm');
  eq(K(S(B(ebml('webm'), seg(B(entry(1, 'V_VP9'), entry(2, 'A_OPUS')))))), ['video', ''], 'sniffMedia: WebM com vídeo → vídeo');
  eq(K(S(B(ebml('matroska'), seg(entry(2, 'A_FLAC'))))), ['audio', 'audio/x-matroska'], 'sniffMedia: Matroska só com áudio → áudio');
  const ogg = p => B('OggS\0\x02', Z(8), '\x01\0\0\0', Z(8), B([1, p.length]), p);
  eq(K(S(ogg(B('OpusHead\x01\x02', Z(9))))), ['audio', 'audio/ogg'], 'sniffMedia: Ogg Opus → áudio');
  eq(K(S(ogg(B('\x80theora', Z(30))))), ['video', ''], 'sniffMedia: Ogg Theora → vídeo');
  const le = n => { const b = Buffer.alloc(4); b.writeUInt32LE(n); return b; };
  eq(K(S(B('RIFF', le(1000), 'WAVEfmt ', Z(100)))), ['audio', 'audio/wav'], 'sniffMedia: WAVE → áudio');
  eq(K(S(B('RIFF', le(1000), 'AVI LIST', Z(100)))), ['video', ''], 'sniffMedia: AVI → vídeo');
  eq(K(S(B('fLaC', Z(100)))), ['audio', 'audio/flac'], 'sniffMedia: FLAC → áudio');
  eq(K(S(B('\xFF\xF1\x50\x80\x02\x1F\xFC', Z(9), '\xFF\xF1\x50\x80\x02\x1F\xFC', Z(9)))), ['audio', 'audio/aac'], 'sniffMedia: AAC ADTS → áudio');
  eq(S(B('\0\0\x01\xBA', Z(2000))), null, 'sniffMedia: MPEG-PS → desconhecido');
  eq(S(B('%PDF-1.7', Z(200))), null, 'sniffMedia: PDF → desconhecido');
  eq(S(Z(0)), null, 'sniffMedia: vazio → desconhecido');
  eq(typeof CCL.sniffMore === 'function' && CCL.sniffMore(m4a) && CCL.sniffMore(qt) && !CCL.sniffMore(B(ebml('webm'))) && !CCL.sniffMore(mp3), true, 'sniffMore: só MP4/MOV (o moov pode estar mais à frente ou no fim) pedem mais bytes'); }

console.log(fail ? `FALHOU ${fail} de ${ok + fail}` : `OK ${ok}`);
process.exit(fail ? 1 : 0);
