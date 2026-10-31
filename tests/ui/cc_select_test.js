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
  await b.close();
  console.log(fail ? `FALHOU ${fail} de ${ok + fail}` : `OK ${ok}`);
  process.exit(fail ? 1 : 0);
})();
