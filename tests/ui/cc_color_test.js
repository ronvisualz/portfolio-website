// Amostra de cor (§33.2): seletor junto do cartão, campo #HEX, copiar/colar #HEX, Ctrl V sobre a amostra, últimas cores.
// node tests/ui/cc_color_test.js
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');
const BASE = 'http://127.0.0.1:8765';
let ok = 0, fail = 0;
const check = (c, msg) => { if (c) ok++; else { fail++; console.error('FALHA: ' + msg); } };
const BID = execFileSync('php', [path.join(__dirname, 'seed_board.php')]).toString().trim();
const hex = () => JSON.parse(execFileSync('php', ['-r', `require "tests/boot.php"; echo $pdo->query("SELECT content FROM cc_cards WHERE board_id=${BID} AND type='swatch' AND deleted_at IS NULL")->fetchColumn();`]).toString()).hex;

(async () => {
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: 1280, height: 900 }, permissions: ['clipboard-read', 'clipboard-write'] });
  const p = await ctx.newPage();
  await p.goto(BASE + '/admin/__as/1');
  await p.goto(BASE + '/admin/toppernote.php?b=' + BID);
  await p.waitForSelector('.ccc.t-swatch');
  await p.waitForTimeout(400);
  const card = p.locator('.ccc.t-swatch');
  await card.dblclick();
  const pop = p.locator('.cc-cpop');
  await pop.waitFor({ timeout: 3000 }).catch(() => {});
  check(await pop.count() === 1, 'duplo clique na amostra abre o seletor');
  if (await pop.count()) {
    const c = await card.boundingBox(), q = await pop.boundingBox();
    const gap = Math.max(q.y - (c.y + c.height), c.y - (q.y + q.height), 0);
    check(gap <= 16 && Math.abs(q.x - c.x) < c.width + 40, 'o seletor fica junto do cartão (distância ' + Math.round(gap) + ' px)');
    check(q.x >= 0 && q.y >= 0 && q.x + q.width <= 1280 && q.y + q.height <= 900, 'o seletor cabe na janela');
    check(await pop.locator('.cp-sw').count() >= 5, 'mostra as 5 cores da marca');
    const inp = pop.locator('.cp-in');
    check((await inp.inputValue()).toUpperCase() === '#FFC718', 'o campo #HEX mostra a cor atual');
    // texto que não é cor → aviso, a cor não muda
    await inp.fill('azul'); await inp.press('Enter'); await p.waitForTimeout(500);
    check(/não é uma cor/i.test(await pop.innerText()) && hex() === '#FFC718', 'texto que não é cor → aviso e a cor fica');
    await inp.fill('  2f77b1 '); await inp.press('Enter'); await p.waitForTimeout(900);
    check(hex() === '#2F77B1', 'escrever «2f77b1» + Enter muda a amostra (' + hex() + ')');
  }
  // Ctrl V com um #HEX copiado e a amostra selecionada → muda a cor
  await p.keyboard.press('Escape');
  await card.click(); await p.waitForTimeout(200);
  await p.evaluate(() => { const dt = new DataTransfer(); dt.setData('text/plain', '#E8846D'); document.dispatchEvent(new ClipboardEvent('paste', { clipboardData: dt, bubbles: true, cancelable: true })); });
  await p.waitForTimeout(900);
  check(hex() === '#E8846D', 'Ctrl V com «#E8846D» sobre a amostra muda a cor (' + hex() + ')');
  check(await p.locator('.ccc.t-swatch').count() === 1, 'colar o #HEX não cria um cartão novo');
  await p.keyboard.press('Control+z'); await p.waitForTimeout(900);
  check(hex() === '#2F77B1', 'Ctrl Z repõe a cor anterior (' + hex() + ')');
  // copiar #HEX e últimas cores
  await card.dblclick(); await pop.waitFor();
  await pop.locator('button', { hasText: 'Copiar #HEX' }).click(); await p.waitForTimeout(300);
  check((await p.evaluate(() => navigator.clipboard.readText())).toUpperCase() === '#2F77B1', '«Copiar #HEX» põe o código na área de transferência');
  check(await pop.locator('.cp-recent .cp-sw').count() >= 2, 'mostra as últimas cores usadas');
  const prefs = JSON.parse(execFileSync('php', ['-r', 'require "tests/boot.php"; echo $pdo->query("SELECT prefs FROM users WHERE id=1")->fetchColumn();']).toString());
  check(prefs.cc && prefs.cc.colors && prefs.cc.colors[0] === '#E8846D', 'as últimas cores ficam guardadas por pessoa (' + JSON.stringify(prefs.cc && prefs.cc.colors) + ')');
  await p.keyboard.press('Escape'); await p.waitForTimeout(200);
  check(await pop.count() === 0, 'Esc fecha o seletor');
  await b.close();
  console.log(fail ? `FALHOU ${fail} de ${ok + fail}` : `OK ${ok}`);
  process.exit(fail ? 1 : 0);
})();
