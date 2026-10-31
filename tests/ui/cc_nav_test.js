// Abrir sub-Topperboards sem recarregar (§33.10): URL ?b=filho, migalhas, Voltar/Avançar com a vista de cada um,
// ligação das migalhas, edição aberta gravada antes de sair.   node tests/ui/cc_nav_test.js
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');
const BASE = 'http://127.0.0.1:8765';
let ok = 0, fail = 0;
const check = (c, msg) => { if (c) ok++; else { fail++; console.error('FALHA: ' + msg); } };
const BID = execFileSync('php', [path.join(__dirname, 'seed_board.php')]).toString().trim();
const SUB = execFileSync('php', ['-r', `require "tests/boot.php"; echo $pdo->query("SELECT ref_board_id FROM cc_cards WHERE board_id=${BID} AND type='board'")->fetchColumn();`]).toString().trim();
execFileSync('php', ['-r', `require "tests/boot.php"; require_once "admin/inc/cc.php"; cc_card_add($pdo, ${SUB}, ['type' => 'note', 'x' => 100, 'y' => 100, 'content' => ['text' => 'Dentro da Luz']], 1);`]);
const noteText = () => JSON.parse(execFileSync('php', ['-r', `require "tests/boot.php"; echo $pdo->query("SELECT content FROM cc_cards WHERE board_id=${BID} AND type='note' AND deleted_at IS NULL")->fetchColumn();`]).toString()).text;

(async () => {
  const b = await chromium.launch();
  const p = await b.newPage({ viewport: { width: 1400, height: 900 } });
  await p.goto(BASE + '/admin/__as/1');
  await p.goto(BASE + '/admin/toppernote.php?b=' + BID);
  await p.waitForSelector('.ccc.t-board'); await p.waitForTimeout(400);
  await p.evaluate(() => { window.__mark = 42; });
  const zo0 = await p.locator('[data-zo]').textContent();
  // edição aberta numa nota: abrir o sub-Topperboard grava-a antes de sair
  await p.locator('.ccc.t-note .ccc-text').dblclick(); await p.keyboard.press('End'); await p.keyboard.type(' gravada');
  await p.locator('.ccc.t-board').dblclick({ position: { x: 20, y: 60 } });
  await p.waitForFunction(id => new URL(location.href).searchParams.get('b') === id, SUB, { timeout: 5000 }).catch(() => {});
  check(new URL(p.url()).searchParams.get('b') === SUB, 'o URL passa a ?b=' + SUB + ' (' + p.url() + ')');
  check(await p.evaluate(() => window.__mark) === 42, 'sem recarregar a página');
  await p.waitForSelector('.ccc.t-note', { timeout: 5000 }).catch(() => {});
  check(/Dentro da Luz/.test(await p.locator('.ccb-vp').innerText()), 'mostra os cartões do sub-Topperboard');
  check(/Teste33 · Luz/.test(await p.locator('.ccb-crumb').innerText()) && /Teste33 · semeado/.test(await p.locator('.ccb-crumb').innerText()), 'as migalhas mostram o pai e o filho');
  check(/gravada$/.test(noteText()), 'a edição aberta foi gravada antes de sair (' + noteText() + ')');
  // Voltar → pai com a mesma vista; Avançar → filho
  await p.goBack(); await p.waitForTimeout(900);
  check(new URL(p.url()).searchParams.get('b') === BID && await p.evaluate(() => window.__mark) === 42, 'Voltar abre o pai, sem recarregar');
  check(await p.locator('.ccc.t-board').count() === 1, 'com os cartões do pai');
  check(await p.locator('[data-zo]').textContent() === zo0, 'e a mesma vista (zoom ' + zo0 + ')');
  await p.goForward(); await p.waitForTimeout(900);
  check(new URL(p.url()).searchParams.get('b') === SUB && /Dentro da Luz/.test(await p.locator('.ccb-vp').innerText()), 'Avançar volta ao filho');
  // ligação das migalhas abre sem recarregar
  await p.locator('.ccb-crumb a', { hasText: 'Teste33 · semeado' }).click(); await p.waitForTimeout(900);
  check(new URL(p.url()).searchParams.get('b') === BID && await p.evaluate(() => window.__mark) === 42, 'a ligação das migalhas abre sem recarregar');
  // sem permissão: aviso e fica onde estava
  const other = execFileSync('php', ['-r', 'require "tests/boot.php"; require_once "admin/inc/cc.php"; $pdo->exec("DELETE FROM cc_boards WHERE title = \'Teste33 · apagado\'"); $id = cc_board_create($pdo, ["title" => "Teste33 · apagado", "kind" => "free"], 1); $pdo->exec("UPDATE cc_boards SET status = \'deleted\' WHERE id = $id"); echo $id;']).toString().trim();
  await p.evaluate(id => window.ccOpenBoard && window.ccOpenBoard(+id, true), other); await p.waitForTimeout(900);
  check(new URL(p.url()).searchParams.get('b') === BID, 'um Topperboard que não abre deixa-nos onde estávamos');
  check(/não encontrado/i.test(await p.locator('body').innerText()), 'com aviso');
  await b.close();
  console.log(fail ? `FALHOU ${fail} de ${ok + fail}` : `OK ${ok}`);
  process.exit(fail ? 1 : 0);
})();
