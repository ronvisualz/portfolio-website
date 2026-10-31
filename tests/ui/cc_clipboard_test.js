// Copiar, cortar, colar e duplicar (§33.6): entre Topperboards e separadores, na posição do cursor; colar de fora como antes.
// node tests/ui/cc_clipboard_test.js
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');
const BASE = 'http://127.0.0.1:8765';
let ok = 0, fail = 0;
const check = (c, msg) => { if (c) ok++; else { fail++; console.error('FALHA: ' + msg); } };
const BID = execFileSync('php', [path.join(__dirname, 'seed_board.php')]).toString().trim();
const DST = execFileSync('php', ['-r', 'require "tests/boot.php"; require_once "admin/inc/cc.php"; echo cc_board_create($pdo, ["title" => "Teste33 · colar", "kind" => "free"], 1);']).toString().trim();
const count = (b, type) => +execFileSync('php', ['-r', `require "tests/boot.php"; echo $pdo->query("SELECT COUNT(*) FROM cc_cards WHERE board_id=${b} AND deleted_at IS NULL" . (${JSON.stringify(type || '')} ? " AND type='${type || ''}'" : ''))->fetchColumn();`]).toString();

(async () => {
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: 1400, height: 900 }, permissions: ['clipboard-read', 'clipboard-write'] });
  const A = await ctx.newPage(), D = await ctx.newPage();
  await A.goto(BASE + '/admin/__as/1'); await A.goto(BASE + '/admin/toppernote.php?b=' + BID);
  await D.goto(BASE + '/admin/toppernote.php?b=' + DST);
  await A.waitForSelector('.ccc.t-note'); await D.waitForSelector('.ccb-vp'); await A.waitForTimeout(400);
  // A: seleciona nota e lista, Ctrl C
  await A.bringToFront();
  await A.locator('.ccc.t-note').click(); await A.keyboard.down('Shift'); await A.locator('.ccc.t-list .ccc-lt').click(); await A.keyboard.up('Shift');
  await A.waitForTimeout(150);
  await A.keyboard.press('Control+c'); await A.waitForTimeout(300);
  check(/2 cartões copiados/.test(await A.locator('body').innerText()), 'aviso «2 cartões copiados»');
  const sys = await A.evaluate(() => navigator.clipboard.readText());
  check(/Um exemplo de nota/.test(sys), 'a área de transferência do sistema leva o texto legível (' + sys.slice(0, 40) + ')');
  // D (outro separador, outro Topperboard): Ctrl V com o cursor sobre a tela → cola aí
  await D.bringToFront();
  const vb = await D.locator('.ccb-vp').boundingBox();
  await D.mouse.move(vb.x + 300, vb.y + 260);
  await D.keyboard.press('Control+v'); await D.waitForTimeout(1200);
  check(count(DST) === 2, 'colar noutro separador e Topperboard cria os 2 cartões (' + count(DST) + ')');
  const pos = await D.evaluate(() => [...document.querySelectorAll('.ccc[data-id]')].map(e => e.getBoundingClientRect().left));
  check(pos.some(x => Math.abs(x - (vb.x + 300)) < 40), 'na posição do cursor (' + pos.map(Math.round) + ' vs ' + Math.round(vb.x + 300) + ')');
  // Ctrl D duplica no próprio Topperboard
  await A.bringToFront();
  const notes0 = count(BID, 'note');
  await A.locator('.ccc.t-note').first().click(); await A.waitForTimeout(150); await A.keyboard.press('Escape'); await A.locator('.ccc.t-note').first().click();
  await A.keyboard.press('Control+d'); await A.waitForTimeout(1000);
  check(count(BID, 'note') === notes0 + 1, 'Ctrl D duplica a nota');
  // Ctrl X + Ctrl V no mesmo Topperboard: o original sai, a cópia fica; Ctrl Z repõe
  const sw0 = count(BID, 'swatch');
  await A.locator('.ccc.t-swatch').click(); await A.waitForTimeout(150);
  await A.keyboard.press('Control+x'); await A.waitForTimeout(900);
  check(count(BID, 'swatch') === sw0 - 1, 'Ctrl X tira a amostra');
  await A.mouse.move(vb.x + 500, vb.y + 600);
  await A.keyboard.press('Control+v'); await A.waitForTimeout(1000);
  check(count(BID, 'swatch') === sw0, 'Ctrl V cola-a de volta');
  await A.keyboard.press('Control+z'); await A.waitForTimeout(1000);
  check(count(BID, 'swatch') === sw0 - 1, 'Ctrl Z tira a colada');
  await A.keyboard.press('Control+z'); await A.waitForTimeout(1200);
  check(count(BID, 'swatch') === sw0, 'Ctrl Z outra vez repõe a cortada');
  // colar texto de fora continua como hoje (cria uma nota)
  await A.evaluate(() => navigator.clipboard.writeText('Texto de fora'));
  const n1 = count(BID, 'note');
  await A.keyboard.press('Escape'); await A.mouse.move(vb.x + 200, vb.y + 650);
  await A.keyboard.press('Control+v'); await A.waitForTimeout(1000);
  check(count(BID, 'note') === n1 + 1, 'colar texto de fora cria uma nota, como antes');
  await b.close();
  console.log(fail ? `FALHOU ${fail} de ${ok + fail}` : `OK ${ok}`);
  process.exit(fail ? 1 : 0);
})();
