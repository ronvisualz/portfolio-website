// Abrir outro Topperboard sem recarregar (§33.10), casos de corrida (revisão final):
// 1) uma resposta antiga do Topperboard anterior não se sobrepõe ao que se acabou de abrir;
// 2) um envio que acaba depois de mudar de Topperboard não aparece no novo, e os que estavam na fila vão para o de origem;
// 3) a barra de ferramentas acompanha a permissão de editar de cada Topperboard.   node tests/ui/cc_nav_race_test.js
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');
const fs = require('fs');
const os = require('os');
const BASE = 'http://127.0.0.1:8765';
let ok = 0, fail = 0;
const check = (c, msg) => { if (c) ok++; else { fail++; console.error('FALHA: ' + msg); } };
const php = code => execFileSync('php', ['-r', 'require "tests/boot.php"; require_once "admin/inc/cc.php"; ' + code]).toString().trim();
const BID = execFileSync('php', [path.join(__dirname, 'seed_board.php')]).toString().trim();
const SUB = php(`echo $pdo->query("SELECT ref_board_id FROM cc_cards WHERE board_id=${BID} AND type='board'")->fetchColumn();`);
php(`cc_card_add($pdo, ${SUB}, ['type' => 'note', 'x' => 100, 'y' => 100, 'content' => ['text' => 'Dentro da Luz']], 1);`);
const count = id => +php(`echo $pdo->query("SELECT COUNT(*) FROM cc_cards WHERE board_id=${id} AND deleted_at IS NULL")->fetchColumn();`);
const sleep = ms => new Promise(r => setTimeout(r, ms));
// dois PNG diferentes (impressões digitais diferentes: nada de «já estava na biblioteca»)
const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'ccnav-'));
const png = (name, r) => { php(`$im = imagecreatetruecolor(40, 30); imagefill($im, 0, 0, imagecolorallocate($im, ${r}, ${Date.now() % 200}, 90)); imagepng($im, ${JSON.stringify(path.join(dir, name))});`); return path.join(dir, name); };

(async () => {
  const b = await chromium.launch();
  const p = await b.newPage({ viewport: { width: 1400, height: 900 } });
  await p.goto(BASE + '/admin/__as/1');
  await p.goto(BASE + '/admin/toppernote.php?b=' + BID);
  await p.waitForSelector('.ccc.t-board'); await p.waitForTimeout(400);

  // 1) a sondagem vê uma mudança e recarrega o pai; a resposta atrasa-se; entretanto abre-se o filho
  let release, hit;
  const hold = new Promise(r => { release = r; }), seen = new Promise(r => { hit = r; });
  await p.route(new RegExp('cc_api\\.php\\?a=board&id=' + BID + '(&|$)'), async r => { hit(); await hold; r.continue(); });
  php(`cc_card_add($pdo, ${BID}, ['type' => 'note', 'x' => 900, 'y' => 900, 'content' => ['text' => 'Mudança de outra pessoa']], 3); cc_board_touch($pdo, ${BID});`);
  const got = await Promise.race([seen.then(() => true), sleep(12000).then(() => false)]);
  check(got, 'a sondagem pediu o Topperboard pai outra vez');
  await p.evaluate(id => window.ccOpenBoard(id, true), +SUB); await p.waitForTimeout(600);
  release(); await p.waitForTimeout(800);
  await p.unroute(new RegExp('cc_api\\.php\\?a=board&id=' + BID + '(&|$)'));
  const vpText = await p.locator('.ccb-vp').innerText();
  check(/Dentro da Luz/.test(vpText) && !/Mudança de outra pessoa/.test(vpText) && await p.locator('.ccc.t-board').count() === 0, 'a resposta atrasada do pai não substitui os cartões do filho');
  check(/Teste33 · Luz/.test(await p.locator('.ccb-crumb').innerText()), 'as migalhas continuam no filho');

  // 2) dois ficheiros enviados no pai; o primeiro acaba depois de abrir o filho
  await p.evaluate(id => window.ccOpenBoard(id, true), +BID); await p.waitForTimeout(800);
  const nB0 = count(BID), nS0 = count(SUB);
  let rel2, hit2;
  const hold2 = new Promise(r => { rel2 = r; }), seen2 = new Promise(r => { hit2 = r; });
  let first = true;
  await p.route('**/cc_api.php?a=upload_done', async r => { if (first) { first = false; hit2(); await hold2; } r.continue(); });
  await p.locator('[data-up]').setInputFiles([png('um.png', 30), png('dois.png', 200)]);
  await Promise.race([seen2, sleep(10000)]);
  await p.evaluate(id => window.ccOpenBoard(id, true), +SUB); await p.waitForTimeout(600);
  rel2(); await p.waitForTimeout(3000);
  await p.unroute('**/cc_api.php?a=upload_done');
  check(await p.locator('.ccc.t-image').count() === 0, 'o cartão enviado não aparece no filho (' + await p.locator('.ccc.t-image').count() + ')');
  check(count(SUB) === nS0, 'nada foi criado no filho (' + count(SUB) + ' vs ' + nS0 + ')');
  check(count(BID) === nB0 + 2, 'os 2 ficheiros ficaram no pai, onde foram largados (' + (count(BID) - nB0) + ')');

  // 3) filho só de leitura (arquivado): sem ferramentas de edição; de volta ao pai, voltam
  php(`$pdo->exec("UPDATE cc_boards SET status = 'archived' WHERE id = ${SUB}");`);
  await p.evaluate(id => window.ccOpenBoard(id, true), +BID); await p.waitForTimeout(800);
  await p.evaluate(id => window.ccOpenBoard(id, true), +SUB); await p.waitForTimeout(800);
  check(await p.locator('.ccb-tools [data-tool="note"]').count() === 0 && await p.locator('.ccb-pen').count() === 0, 'no Topperboard só de leitura não há ferramentas de edição');
  await p.evaluate(id => window.ccOpenBoard(id, true), +BID); await p.waitForTimeout(800);
  check(await p.locator('.ccb-tools [data-tool="note"]').count() === 1 && await p.locator('[data-upmenu]').count() === 1, 'no pai editável as ferramentas voltam');
  await p.keyboard.press('Escape'); await p.waitForTimeout(200);
  const before = count(BID);
  await p.locator('.ccb-tools [data-tool="note"]').click(); await p.waitForTimeout(1200);
  check(count(BID) === before + 1, 'e funcionam (a ferramenta Nota cria um cartão)');
  // de um Topperboard só de leitura aberto pelo endereço para um editável
  await p.goto(BASE + '/admin/toppernote.php?b=' + SUB); await p.waitForSelector('.ccb-vp'); await p.waitForTimeout(400);
  await p.evaluate(id => window.ccOpenBoard(id, true), +BID); await p.waitForTimeout(900);
  check(await p.locator('.ccb-tools [data-tool="note"]').count() === 1 && await p.locator('.ccb-pen').count() === 1, 'vindo de um só de leitura, o editável tem as ferramentas e a barra da caneta');
  await b.close();
  console.log(fail ? `FALHOU ${fail} de ${ok + fail}` : `OK ${ok}`);
  process.exit(fail ? 1 : 0);
})();
