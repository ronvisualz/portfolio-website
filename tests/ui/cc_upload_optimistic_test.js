// Envio otimista (§33.10): o cartão aparece logo, com a pré-visualização local e o progresso; em erro, «Tentar outra vez».
// node tests/ui/cc_upload_optimistic_test.js
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path'), fs = require('fs'), os = require('os');
const BASE = 'http://127.0.0.1:8765';
let ok = 0, fail = 0;
const check = (c, msg) => { if (c) ok++; else { fail++; console.error('FALHA: ' + msg); } };
const BID = execFileSync('php', [path.join(__dirname, 'seed_board.php')]).toString().trim();
const mk = (n, w, h) => { const f = path.join(os.tmpdir(), 'cc_opt_' + process.pid + '_' + n + '.png'); execFileSync('php', ['-r', `$im=imagecreatetruecolor(${w},${h}); imagefill($im,0,0,imagecolorallocate($im,${n * 40 % 255},90,${(Date.now() + n) % 255})); imagepng($im, $argv[1]);`, f]); return f; };
const A1 = mk(1, 1200, 800), A2 = mk(2, 900, 900), HEIC = path.join(os.tmpdir(), 'cc_opt_' + process.pid + '.heic');
fs.writeFileSync(HEIC, Buffer.from('não é uma imagem que o browser leia ' + Date.now()));

(async () => {
  const b = await chromium.launch();
  const p = await b.newPage({ viewport: { width: 1280, height: 900 } });
  await p.goto(BASE + '/admin/__as/1');
  await p.goto(BASE + '/admin/toppernote.php?b=' + BID);
  await p.waitForSelector('.ccc[data-id]'); await p.waitForTimeout(300);
  // atrasa o upload_done para se ver o estado intermédio
  await p.route('**/cc_api.php?a=upload_done', async r => { await new Promise(x => setTimeout(x, 1200)); r.continue(); });
  const t0 = Date.now();
  await p.locator('input[data-up]').setInputFiles(A1);
  await p.waitForSelector('.ccc.uploading', { timeout: 2000 }).catch(() => {});
  const dt = Date.now() - t0;
  check(dt < 900, 'o cartão aparece logo (' + dt + ' ms)');
  check(await p.locator('.ccc.uploading img[src^="blob:"]').count() === 1, 'com a pré-visualização local (blob:)');
  check(await p.locator('.ccc.uploading .ccc-prog').count() === 1, 'e uma barra de progresso');
  await p.waitForFunction(() => !document.querySelector('.ccc.uploading'), null, { timeout: 15000 }).catch(() => {});
  const last = await p.evaluate(() => { const l = [...document.querySelectorAll('.ccc.t-image[data-id]')].map(e => +e.dataset.id); return { max: Math.max(...l), neg: l.filter(x => x < 0).length, blob: document.querySelectorAll('img[src^="blob:"]').length }; });
  check(last.max > 0 && last.neg === 0, 'quando acaba fica o cartão do servidor (id positivo)');
  check(last.blob === 0, 'e o blob: foi largado');
  const nAfter = await p.locator('.ccc.t-image').count();
  // erro do servidor → «Tentar outra vez»; ao tentar e resultar, troca para o do servidor
  await p.unroute('**/cc_api.php?a=upload_done');
  let failOnce = true;
  await p.route('**/cc_api.php?a=upload_done', r => { if (failOnce) { failOnce = false; r.fulfill({ status: 500, contentType: 'application/json', body: '{"ok":false,"error":"Falhou no servidor."}' }); } else r.continue(); });
  await p.locator('input[data-up]').setInputFiles(A2);
  await p.waitForSelector('.ccc.failed', { timeout: 8000 }).catch(() => {});
  const fb = p.locator('.ccc.failed button', { hasText: 'Tentar outra vez' });
  check(await fb.count() === 1, 'em erro o cartão fica com «Tentar outra vez»');
  await fb.click();
  await p.waitForFunction(() => !document.querySelector('.ccc.failed, .ccc.uploading'), null, { timeout: 15000 }).catch(() => {});
  check(await p.locator('.ccc.t-image').count() === nAfter + 1 && await p.locator('.ccc.failed').count() === 0, 'tentar outra vez resulta e troca para o do servidor');
  // um ficheiro que o browser não lê → ícone e nome, nunca em branco
  await p.unroute('**/cc_api.php?a=upload_done');
  await p.route('**/cc_api.php?a=upload_done', async r => { await new Promise(x => setTimeout(x, 1500)); r.continue(); });
  await p.locator('input[data-up]').setInputFiles(HEIC);
  await p.waitForSelector('.ccc.uploading', { timeout: 2000 }).catch(() => {});
  const up = p.locator('.ccc.uploading');
  check(await up.count() === 1 && /\.heic/.test(await up.innerText()) && await up.locator('img[src^="blob:"]').count() === 0, 'HEIC: cartão otimista com ícone e nome');
  await p.waitForTimeout(3000);
  await b.close();
  [A1, A2, HEIC].forEach(f => fs.unlinkSync(f));
  console.log(fail ? `FALHOU ${fail} de ${ok + fail}` : `OK ${ok}`);
  process.exit(fail ? 1 : 0);
})();
