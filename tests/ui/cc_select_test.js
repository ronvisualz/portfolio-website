// Selecionar várias coisas (§33.12): Alt + arrastar desenha o retângulo; Shift soma; arrastar sem Alt move a tela;
// iPad: tocar e manter 0,4 s e arrastar; barra «N selecionados».   node tests/ui/cc_select_test.js
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');
const BASE = 'http://127.0.0.1:8765';
let ok = 0, fail = 0;
const check = (c, msg) => { if (c) ok++; else { fail++; console.error('FALHA: ' + msg); } };
const BID = execFileSync('php', [path.join(__dirname, 'seed_board.php')]).toString().trim();
const box = (p, sel) => p.locator(sel).first().boundingBox();
const selCount = p => p.locator('.ccc.sel').count();

(async () => {
  const b = await chromium.launch();
  const p = await b.newPage({ viewport: { width: 1400, height: 900 } });
  await p.goto(BASE + '/admin/__as/1');
  await p.goto(BASE + '/admin/toppernote.php?b=' + BID);
  await p.waitForSelector('.ccc.t-note'); await p.waitForTimeout(400);
  const n = await box(p, '.ccc.t-note'), l = await box(p, '.ccc.t-list'), s = await box(p, '.ccc.t-swatch');
  // Alt sobre o fundo: cursor em mira
  await p.mouse.move(n.x - 15, n.y - 15);
  await p.keyboard.down('Alt'); await p.waitForTimeout(80);
  check(await p.evaluate(() => getComputedStyle(document.querySelector('.ccb-vp')).cursor) === 'crosshair', 'com Alt, o cursor sobre o fundo fica em mira');
  // Alt + arrastar sobre a nota e a lista (não chega à amostra)
  await p.mouse.down();
  await p.mouse.move((n.x + l.x + l.width) / 2, n.y + 20, { steps: 4 });
  const marq = await p.evaluate(() => { const m = document.querySelector('.ccb-marq'); if (!m) return null; const cs = getComputedStyle(m); return { border: cs.borderStyle, bg: cs.backgroundColor }; });
  check(marq && marq.border === 'dashed' && /rgba\(.+, 0\.\d+\)/.test(marq.bg), 'retângulo tracejado com fundo translúcido (' + JSON.stringify(marq) + ')');
  await p.mouse.move(l.x + l.width / 2, n.y + 30, { steps: 4 });
  await p.mouse.up(); await p.keyboard.up('Alt'); await p.waitForTimeout(250);
  check(await selCount(p) === 2, 'Alt + arrastar seleciona os 2 cartões tocados (' + await selCount(p) + ')');
  const bar = p.locator('.ccb-selbar');
  check(await bar.isVisible().catch(() => false) && /2 selecionados/.test(await bar.innerText()), 'barra «2 selecionados»');
  check(await bar.locator('button', { hasText: 'Alinhar' }).count() === 1 && await bar.locator('button', { hasText: 'Apagar' }).count() === 1, 'a barra tem Alinhar e Apagar');
  check(await bar.locator('button', { hasText: 'Escolher mais' }).count() === 1, 'no computador a barra também tem «Escolher mais»');
  // Shift + Alt soma a amostra
  await p.mouse.move(s.x + s.width + 15, s.y - 15);
  await p.keyboard.down('Shift'); await p.keyboard.down('Alt');
  await p.mouse.down(); await p.mouse.move(s.x + s.width / 2, s.y + 10, { steps: 4 }); await p.mouse.up();
  await p.keyboard.up('Alt'); await p.keyboard.up('Shift'); await p.waitForTimeout(250);
  check(await selCount(p) === 3, 'Shift soma ao que estava (' + await selCount(p) + ')');
  // arrastar sem Alt no fundo move a tela e não mexe na seleção
  const t0 = await p.evaluate(() => document.querySelector('.ccb-world').style.transform);
  await p.mouse.move(n.x - 30, n.y + 260); await p.mouse.down(); await p.mouse.move(n.x + 60, n.y + 300, { steps: 5 }); await p.mouse.up();
  await p.waitForTimeout(200);
  check(await p.evaluate(() => document.querySelector('.ccb-world').style.transform) !== t0, 'arrastar sem Alt move a tela');
  check(await selCount(p) === 3, 'e a seleção fica');
  // Alinhar à esquerda
  await bar.locator('button', { hasText: 'Alinhar' }).click();
  await p.locator('.ccb-selbar [data-align="left"]').click(); await p.waitForTimeout(900);
  const xs = JSON.parse(execFileSync('php', ['-r', `require "tests/boot.php"; echo json_encode($pdo->query("SELECT x FROM cc_cards WHERE board_id=${BID} AND type IN ('note','list','swatch') AND deleted_at IS NULL")->fetchAll(PDO::FETCH_COLUMN));`]).toString()).map(Number);
  check(new Set(xs).size === 1, 'Alinhar à esquerda põe os 3 no mesmo x (' + xs + ')');
  await p.keyboard.press('Control+z'); await p.waitForTimeout(900);
  const xs2 = JSON.parse(execFileSync('php', ['-r', `require "tests/boot.php"; echo json_encode($pdo->query("SELECT x FROM cc_cards WHERE board_id=${BID} AND type IN ('note','list','swatch') AND deleted_at IS NULL")->fetchAll(PDO::FETCH_COLUMN));`]).toString()).map(Number);
  check(new Set(xs2).size === 3, 'Ctrl Z desfaz o alinhamento');
  // a barra esconde-se com o foco lá dentro → o foco vai para a tela (não se perde no corpo da página)
  await p.locator('.ccb-selbar [data-sb="more"]').focus(); await p.keyboard.press('Escape'); await p.waitForTimeout(200);
  check(await p.evaluate(() => document.activeElement && document.activeElement.matches('.ccb-vp')), 'Esc com o foco na barra: o foco passa para a tela');
  await p.locator('.ccc.t-note').click(); await p.locator('.ccc.t-list').click({ modifiers: ['Shift'] }); await p.waitForTimeout(200);
  await p.locator('.ccb-selbar [data-sb="more"]').click(); await p.locator('.ccc.t-list').click(); await p.waitForTimeout(200);
  check(await selCount(p) === 1, 'computador: com o modo ligado, clicar tira a lista (fica 1)');
  await p.locator('.ccb-selbar [data-sb="more"]').click(); await p.waitForTimeout(200);
  check(await p.evaluate(() => document.activeElement && document.activeElement.matches('.ccb-vp')), '«Concluído» com 1 selecionado esconde a barra e o foco passa para a tela');
  // iPad: tocar e manter e arrastar
  const ctx = await b.newContext({ viewport: { width: 1180, height: 820 }, hasTouch: true });
  const t = await ctx.newPage();
  await t.goto(BASE + '/admin/__as/1'); await t.goto(BASE + '/admin/toppernote.php?b=' + BID);
  await t.waitForSelector('.ccc.t-note'); await t.waitForTimeout(500);
  const tn = await box(t, '.ccc.t-note');
  const cdp = await ctx.newCDPSession(t);
  const x0 = tn.x - 12, y0 = tn.y - 12;
  await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x: x0, y: y0 }] });
  await t.waitForTimeout(500);
  // ao chegar aos 0,4 s, antes de mexer, mostra-se que o modo mudou (no iPad não há vibração)
  const hold = await t.evaluate(() => ({ cls: document.querySelector('.ccb-vp').classList.contains('marq-ready'), dot: !!document.querySelector('.ccb-holddot') }));
  check(hold.cls && hold.dot, 'iPad: ao fim de 0,4 s aparece o sinal de seleção no ponto tocado (' + JSON.stringify(hold) + ')');
  for (let i = 1; i <= 6; i++) { await cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x: x0 + i * 10, y: y0 + i * 8 }] }); await t.waitForTimeout(30); }
  await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
  await t.waitForTimeout(300);
  check(await t.locator('.ccc.sel').count() >= 1, 'iPad: tocar e manter 0,4 s e arrastar seleciona');
  check(await t.locator('.ccb-holddot').count() === 0 && !(await t.locator('.ccb-vp.marq-ready').count()), 'iPad: o sinal desaparece ao largar');
  // iPad sem teclado: «Escolher mais» junta e tira cartões só com toques (alternativa ao toque longo + arrastar)
  const ctx2 = await b.newContext({ viewport: { width: 1180, height: 820 }, hasTouch: true, isMobile: true });
  const m = await ctx2.newPage();
  await m.goto(BASE + '/admin/__as/1'); await m.goto(BASE + '/admin/toppernote.php?b=' + BID);
  await m.waitForSelector('.ccc.t-note'); await m.waitForTimeout(500);
  const tapCard = async sel => { const r = await box(m, sel); await m.touchscreen.tap(r.x + r.width / 2, r.y + 8); await m.waitForTimeout(250); };
  const isSel = sel => m.locator(sel + '.sel').count();
  await tapCard('.ccc.t-note');
  const mb = m.locator('.ccb-selbar'), more = mb.locator('[data-sb="more"]');
  check(await mb.isVisible().catch(() => false) && await more.count() === 1 && /Escolher mais/.test(await more.innerText()), 'iPad: com 1 cartão a barra mostra «Escolher mais»');
  await more.tap(); await m.waitForTimeout(200);
  check(/Concluído/.test(await more.innerText()) && await more.getAttribute('aria-pressed') === null && /Toque nos cartões para juntar ou tirar/.test(await mb.locator('[role="status"]').innerText()), 'ligado: o botão diz «Concluído» (sem aria-pressed, rótulo e estado não se contradizem) e a dica é anunciada');
  await tapCard('.ccc.t-list');
  check(await selCount(m) === 2 && await isSel('.ccc.t-note') === 1 && await isSel('.ccc.t-list') === 1, 'tocar na lista junta-a (2 selecionados)');
  await tapCard('.ccc.t-note');
  check(await selCount(m) === 1 && await isSel('.ccc.t-list') === 1, 'tocar outra vez na nota tira-a, sem entrar em edição (' + await selCount(m) + ')');
  check(await m.locator('.ccc.t-note [contenteditable="true"]').count() === 0, 'a nota não entrou em edição');
  const nb = await box(m, '.ccc.t-note');
  await m.touchscreen.tap(nb.x + 10, nb.y + 230); await m.waitForTimeout(250);
  check(await selCount(m) === 1, 'tocar no fundo não limpa a seleção enquanto está ligado');
  await more.tap(); await m.waitForTimeout(200);
  check(/Escolher mais/.test(await more.innerText()) && !(await m.locator('.ccb-vp.pick-more').count()), '«Concluído» desliga');
  await tapCard('.ccc.t-note');
  check(await selCount(m) === 1 && await isSel('.ccc.t-note') === 1, 'desligado: tocar num cartão volta a substituir a seleção');
  // Espaço com o foco no botão (teclado no iPad) não abre o visualizador da imagem selecionada
  await tapCard('.ccc.t-image');
  await m.locator('.ccb-selbar [data-sb="more"]').focus(); await m.keyboard.press('Space'); await m.waitForTimeout(400);
  check(await m.locator('.cc-viewer').count() === 0 && await m.locator('.ccb-vp.pick-more').count() === 1, 'Espaço no «Escolher mais» liga o modo e não abre o visualizador');
  await m.locator('.ccb-selbar [data-sb="more"]').tap(); await m.waitForTimeout(150);
  await m.locator('.ccb-selbar [data-sb="more"]').tap(); await m.waitForTimeout(150);
  await m.keyboard.press('Escape'); await m.waitForTimeout(200);
  check(!(await m.locator('.ccb-vp.pick-more').count()) && await selCount(m) === 0, 'Esc desliga o modo (e limpa a seleção)');
  // escuro automático (sem tema guardado): o texto do botão ligado tem contraste
  const ctx3 = await b.newContext({ viewport: { width: 1180, height: 820 }, hasTouch: true, isMobile: true, colorScheme: 'dark' });
  const d = await ctx3.newPage();
  await d.goto(BASE + '/admin/__as/1'); await d.goto(BASE + '/admin/toppernote.php?b=' + BID);
  await d.waitForSelector('.ccc.t-note'); await d.evaluate(() => document.documentElement.removeAttribute('data-theme')); await d.waitForTimeout(400);
  const dn = await box(d, '.ccc.t-note'); await d.touchscreen.tap(dn.x + dn.width / 2, dn.y + 8); await d.waitForTimeout(250);
  await d.locator('.ccb-selbar [data-sb="more"]').tap(); await d.waitForTimeout(200);
  const ratio = await d.evaluate(() => {
    const e = document.querySelector('.ccb-selbar [data-sb="more"]'), cs = getComputedStyle(e);
    const rgb = s => s.match(/[\d.]+/g).slice(0, 3).map(Number), lum = c => { const v = c.map(x => { x /= 255; return x <= 0.03928 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4); }); return 0.2126 * v[0] + 0.7152 * v[1] + 0.0722 * v[2]; };
    const a = lum(rgb(cs.color)), b2 = lum(rgb(cs.backgroundColor));
    return (Math.max(a, b2) + 0.05) / (Math.min(a, b2) + 0.05);
  });
  check(ratio >= 4.5, 'escuro automático: contraste do botão ligado ≥ 4,5 (' + ratio.toFixed(2) + ')');
  await b.close();
  console.log(fail ? `FALHOU ${fail} de ${ok + fail}` : `OK ${ok}`);
  process.exit(fail ? 1 : 0);
})();
