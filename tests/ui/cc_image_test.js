// Imagens nítidas (§33.5): a miniatura escolhe-se pela largura real × zoom × densidade, troca sozinha quando há uma maior
// e ao fazer zoom; sem miniaturas usa o original.   node tests/ui/cc_image_test.js
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path'), fs = require('fs'), os = require('os');
const BASE = 'http://127.0.0.1:8765';
let ok = 0, fail = 0;
const check = (c, msg) => { if (c) ok++; else { fail++; console.error('FALHA: ' + msg); } };
const BID = execFileSync('php', [path.join(__dirname, 'seed_board.php')]).toString().trim();
const PNG = path.join(os.tmpdir(), 'cc_up2400_' + process.pid + '.png');
execFileSync('php', ['-r', '$im=imagecreatetruecolor(2400,1600); for($i=0;$i<2400;$i+=20){imagefilledrectangle($im,$i,0,$i+9,1599,imagecolorallocate($im,($i*7+' + Date.now() % 97 + ')%255,($i*3)%255,120));} imagepng($im, $argv[1]);', PNG]);

(async () => {
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: 1280, height: 900 }, deviceScaleFactor: 2 });
  const p = await ctx.newPage();
  await p.goto(BASE + '/admin/__as/1');
  await p.goto(BASE + '/admin/toppernote.php?b=' + BID);
  await p.waitForSelector('.ccc[data-id]'); await p.waitForTimeout(300);
  await p.mouse.move(400, 500);
  await p.keyboard.press('1'); for (let i = 0; i < 3; i++) await p.keyboard.press('+');   // ~200%
  await p.waitForTimeout(300);
  const n0 = await p.locator('.ccc.t-image').count();
  await p.locator('input[data-up]').setInputFiles(PNG);
  await p.waitForFunction(n => document.querySelectorAll('.ccc.t-image[data-id]').length > n && [...document.querySelectorAll('.ccc.t-image[data-id]')].some(e => +e.dataset.id > 0 && e.querySelector('img[src*="cc_file"]')), n0, { timeout: 30000 }).catch(() => {});
  await p.waitForTimeout(1500);
  const sOf = () => p.evaluate(() => {
    const els = [...document.querySelectorAll('.ccc.t-image[data-id]')].sort((a, b) => +b.dataset.id - +a.dataset.id), img = els[0] && els[0].querySelector('img');
    if (!img) return null;
    const u = new URL(img.currentSrc || img.src, location.href), r = els[0].getBoundingClientRect();
    return { s: +u.searchParams.get('s'), css: Math.round(r.width), need: Math.round(r.width * devicePixelRatio), natural: img.naturalWidth };
  });
  let st = await sOf();
  check(st && st.natural >= Math.min(1600, st.need) * 0.95, 'logo após o envio a imagem é nítida: ' + JSON.stringify(st));
  await p.reload(); await p.waitForSelector('.ccc.t-image[data-id]');
  await p.keyboard.press('1'); for (let i = 0; i < 3; i++) await p.keyboard.press('+');
  await p.waitForTimeout(1500);
  st = await sOf();
  check(st && st.natural >= Math.min(1600, st.need) * 0.95, 'depois de recarregar continua nítida: ' + JSON.stringify(st));
  // a tela não fica com will-change fixo (rasterização presa na escala inicial); só durante o movimento
  const wc = () => p.evaluate(() => getComputedStyle(document.querySelector('.ccb-world')).willChange);
  check(await wc() === 'auto', 'em repouso a tela não tem will-change (' + await wc() + ')');
  const ib = await p.locator('.ccc.t-image').last().boundingBox();
  await p.mouse.move(ib.x + ib.width / 2, ib.y + ib.height / 2);
  await p.keyboard.down('Control'); await p.mouse.wheel(0, -100); const during = await wc();
  for (let i = 0; i < 5; i++) { await p.mouse.wheel(0, -100); await p.waitForTimeout(60); }
  await p.keyboard.up('Control');
  check(during === 'transform', 'durante o zoom tem will-change: transform (' + during + ')');
  await p.waitForTimeout(1200);
  check(await wc() === 'auto', 'depois do zoom volta a auto (a camada rasteriza de novo, nítida)');
  st = await sOf();
  check(st && st.s === 0, 'muito zoom numa imagem maior do que 1600 px → usa o original (' + JSON.stringify(st) + ')');
  // sem miniaturas → mostra o original
  execFileSync('php', ['-r', `require "tests/boot.php"; $pdo->exec("UPDATE cc_assets a JOIN cc_cards c ON c.asset_id = a.id SET a.thumbs = '' WHERE c.board_id = ${BID} AND c.type = 'image'");`]);
  await p.reload(); await p.waitForSelector('.ccc.t-image[data-id]'); await p.waitForTimeout(1200);
  const nt = await p.evaluate(() => [...document.querySelectorAll('.ccc.t-image img')].map(i => new URL(i.currentSrc || i.src, location.href).searchParams.get('s')));
  check(nt.length >= 2 && nt.every(x => x === '0'), 'sem miniaturas, o cartão mostra o original (' + JSON.stringify(nt) + ')');
  await b.close();
  fs.unlinkSync(PNG);
  console.log(fail ? `FALHOU ${fail} de ${ok + fail}` : `OK ${ok}`);
  process.exit(fail ? 1 : 0);
})();
