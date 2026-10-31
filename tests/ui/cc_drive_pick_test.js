// Enviar «Do dispositivo» ou «Da Drive» (§33.9): seletor da Drive com pesquisa e seleção múltipla; o cartão aponta para
// o ficheiro (sem copiar); apagado da Drive → faixa no cartão e aviso com «Restaurar do Lixo» / «Retirar do Topperboard».
// node tests/ui/cc_drive_pick_test.js
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');
const BASE = 'http://127.0.0.1:8765';
let ok = 0, fail = 0;
const check = (c, msg) => { if (c) ok++; else { fail++; console.error('FALHA: ' + msg); } };
const BID = execFileSync('php', [path.join(__dirname, 'seed_board.php')]).toString().trim();
const TAG = 'pick' + Date.now().toString(36);
const DIR = execFileSync('php', ['-r', `require "tests/boot.php"; require "tests/fixtures.php"; $d = "Empresa/Teste33/${TAG}/"; tests_put($d . "${TAG}-um.txt", "um"); tests_put($d . "${TAG}-dois.txt", "dois"); tests_put($d . "${TAG}-tres.txt", "tres"); drive_catalog_invalidate(); echo $d;`]).toString().trim();
const php = code => execFileSync('php', ['-r', 'require "tests/boot.php"; ' + code]).toString().trim();
const ccObjs = () => +php(`echo $pdo->query("SELECT COUNT(*) FROM cc_assets WHERE file_key LIKE 'r2:.topper/cc/o/%'")->fetchColumn();`);

(async () => {
  const b = await chromium.launch();
  const p = await b.newPage({ viewport: { width: 1400, height: 900 } });
  await p.goto(BASE + '/admin/__as/1');
  await p.goto(BASE + '/admin/toppernote.php?b=' + BID);
  await p.waitForSelector('.ccc[data-id]'); await p.waitForTimeout(300);
  const objs0 = ccObjs(), cards0 = await p.locator('.ccc[data-id]').count();
  // botão Enviar → menu com as duas opções
  await p.locator('[data-upmenu]').click();
  const menu = p.locator('.cc-upmenu');
  check(await menu.locator('button', { hasText: 'Do dispositivo' }).count() === 1 && await menu.locator('button', { hasText: 'Da Drive' }).count() === 1, 'Enviar abre «Do dispositivo» e «Da Drive»');
  await menu.locator('button', { hasText: 'Da Drive' }).click();
  const dlg = p.locator('.cc-dpick');
  await dlg.waitFor({ timeout: 5000 }).catch(() => {});
  check(await dlg.getAttribute('role') === 'dialog', 'abre o seletor da Drive');
  // pesquisa
  await dlg.locator('input[type=search]').fill(TAG);
  await p.waitForTimeout(400);
  const rows = dlg.locator('.dp-f');
  check(await rows.count() === 3, 'a pesquisa encontra os 3 ficheiros (' + await rows.count() + ')');
  // seleção múltipla: clique + Shift-clique
  await rows.nth(0).click();
  await p.keyboard.down('Shift'); await rows.nth(1).click(); await p.keyboard.up('Shift');
  const add = dlg.locator('button[data-dp-add]');
  check(/Adicionar 2/.test(await add.innerText()), 'o botão diz «Adicionar 2»');
  await add.click(); await p.waitForTimeout(1200);
  check(await p.locator('.ccc[data-id]').count() === cards0 + 2, '2 cartões novos');
  check(ccObjs() === objs0, 'sem cópia: nada novo no armazenamento do Toppernote');
  const keys = php(`echo json_encode($pdo->query("SELECT a.file_key FROM cc_cards c JOIN cc_assets a ON a.id = c.asset_id WHERE c.board_id = ${BID} AND c.deleted_at IS NULL AND a.file_key LIKE 'r2:${DIR}%'")->fetchAll(PDO::FETCH_COLUMN));`);
  check(JSON.parse(keys).length === 2, 'os cartões apontam para os ficheiros da Drive');
  // o ficheiro vai para o Lixo → faixa no cartão e aviso no Topperboard
  const k1 = JSON.parse(keys)[0].slice(3);
  php(`$r = tests_http("POST", "/admin/drive_api.php", ["do" => "trash", "key" => ${JSON.stringify(k1)}], 1); echo $r["code"];`);
  await p.reload(); await p.waitForSelector('.ccc[data-id]'); await p.waitForTimeout(400);
  const gone = p.locator('.ccc.gone');
  check(await gone.count() === 1 && /Ficheiro apagado da Drive/.test(await gone.innerText()), 'o cartão fica com a faixa «Ficheiro apagado da Drive»');
  const note = p.locator('.ccb-gone');
  check(await note.isVisible().catch(() => false) && await note.locator('button', { hasText: 'Restaurar do Lixo' }).count() === 1 && await note.locator('button', { hasText: 'Retirar do Topperboard' }).count() === 1, 'aviso no Topperboard com «Restaurar do Lixo» e «Retirar do Topperboard»');
  await note.locator('button', { hasText: 'Restaurar do Lixo' }).click(); await p.waitForTimeout(1500);
  check(await p.locator('.ccc.gone').count() === 0 && await p.locator('.ccb-gone').count() === 0, '«Restaurar do Lixo» devolve o ficheiro e tira o aviso');
  // outra vez para o Lixo → «Retirar do Topperboard» tira o cartão (com Anular)
  php(`tests_http("POST", "/admin/drive_api.php", ["do" => "trash", "key" => ${JSON.stringify(k1)}], 1);`);
  await p.reload(); await p.waitForSelector('.ccb-gone'); await p.waitForTimeout(300);
  const n1 = await p.locator('.ccc[data-id]').count();
  await p.locator('.ccb-gone button', { hasText: 'Retirar do Topperboard' }).click(); await p.waitForTimeout(1200);
  check(await p.locator('.ccc[data-id]').count() === n1 - 1 && await p.locator('.ccb-gone').count() === 0, '«Retirar do Topperboard» tira o cartão e o aviso');
  // Lixo esvaziado → «Restaurar do Lixo» já não aparece, só «Retirar»
  await p.keyboard.press('Control+z'); await p.waitForTimeout(1200);
  const tid = php(`echo $pdo->query("SELECT drive_trash_id FROM cc_assets WHERE file_key = 'r2:${k1}'")->fetchColumn();`);
  php(`tests_http("POST", "/admin/drive_api.php", ["do" => "purge", "id" => ${+tid || 0}], 1);`);
  await p.reload(); await p.waitForSelector('.ccb-gone'); await p.waitForTimeout(300);
  check(await p.locator('.ccb-gone button', { hasText: 'Restaurar do Lixo' }).count() === 0 && /já não está no Lixo|apagado de vez/i.test(await p.locator('.ccb-gone').innerText()), 'depois de apagado de vez: aviso claro e só «Retirar do Topperboard»');
  await b.close();
  console.log(fail ? `FALHOU ${fail} de ${ok + fail}` : `OK ${ok}`);
  process.exit(fail ? 1 : 0);
})();
