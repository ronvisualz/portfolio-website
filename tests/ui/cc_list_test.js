// Listas no Topperboard (§33.1): o primeiro item edita-se; Enter cria o seguinte vazio; vários vazios seguidos;
// Backspace num vazio apaga-o e sobe; colar várias linhas cria vários itens.   node tests/ui/cc_list_test.js
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');
const BASE = 'http://127.0.0.1:8765';
let ok = 0, fail = 0;
const check = (c, msg) => { if (c) ok++; else { fail++; console.error('FALHA: ' + msg); } };
const BID = execFileSync('php', [path.join(__dirname, 'seed_board.php')]).toString().trim();
const items = () => JSON.parse(execFileSync('php', ['-r', `require "tests/boot.php"; echo $pdo->query("SELECT content FROM cc_cards WHERE board_id=${BID} AND type='list' AND deleted_at IS NULL")->fetchColumn();`]).toString()).items.map(i => i.t);

(async () => {
  const b = await chromium.launch();
  const p = await b.newPage({ viewport: { width: 1280, height: 900 } });
  await p.goto(BASE + '/admin/__as/1');
  await p.goto(BASE + '/admin/toppernote.php?b=' + BID);
  await p.waitForSelector('.ccc[data-id]');
  await p.waitForTimeout(400);
  const li = i => p.locator('.ccc-li').nth(i).locator('.ccc-lx');
  const dom = () => p.evaluate(() => [...document.querySelectorAll('.ccc-li .ccc-lx')].slice(0, 12).map(e => e.textContent));
  const active = () => p.evaluate(() => { const a = document.activeElement, li = a.closest && a.closest('.ccc-li'); return { i: li ? +li.dataset.i : null, us: getComputedStyle(a).userSelect, ce: a.isContentEditable }; });
  // 1. o primeiro item edita-se (bug: não deixava escrever no Safari — a tela tem user-select:none)
  await li(0).dblclick();
  await p.waitForTimeout(200);
  const a0 = await active();
  check(a0.i === 0 && a0.ce, 'duplo clique no 1.º item põe-no a editar');
  check(a0.us === 'text', 'o item em edição aceita seleção de texto (user-select: text; no Safari é o que deixa escrever) — ' + a0.us);
  await p.keyboard.press('End');
  await p.keyboard.type('abc');
  await p.keyboard.press('Escape');
  await p.waitForTimeout(900);
  check(items()[0] === 'Primeiroabc', 'o primeiro item edita-se (' + JSON.stringify(items()) + ')');
  // 2. Enter no fim cria o seguinte vazio; Enter Enter Enter → 3 vazios seguidos
  await li(2).dblclick(); await p.waitForTimeout(150);
  await p.keyboard.press('End');
  for (let k = 0; k < 3; k++) { await p.keyboard.press('Enter'); await p.waitForTimeout(150); }
  check(JSON.stringify((await dom()).slice(0, 6)) === JSON.stringify(['Primeiroabc', 'Segundo', 'Terceiro', '', '', '']), 'Enter Enter Enter → 3 itens vazios seguidos (' + JSON.stringify(await dom()) + ')');
  check((await active()).i === 5, 'o cursor fica no último item novo');
  // 3. Backspace num vazio apaga-o e sobe
  await p.keyboard.press('Backspace'); await p.waitForTimeout(150);
  check((await dom()).length === 5 && (await active()).i === 4, 'Backspace num vazio apaga-o e sobe (' + (await dom()).length + ')');
  // 4. escrever num vazio do meio e sair: os vazios do fim não se gravam
  await p.keyboard.type('Quinto'); await p.waitForTimeout(100);
  await p.keyboard.press('Escape'); await p.waitForTimeout(900);
  check(JSON.stringify(items()) === JSON.stringify(['Primeiroabc', 'Segundo', 'Terceiro', '', 'Quinto']), 'vazio a meio fica, vazios do fim não (' + JSON.stringify(items()) + ')');
  // 5. colar 3 linhas num item cria 3 itens
  await li(1).dblclick(); await p.waitForTimeout(150);
  await p.keyboard.press('End');
  await p.evaluate(() => { const dt = new DataTransfer(); dt.setData('text/plain', 'Um\n\nDois\nTrês'); document.activeElement.dispatchEvent(new ClipboardEvent('paste', { clipboardData: dt, bubbles: true, cancelable: true })); });
  await p.waitForTimeout(200);
  await p.keyboard.press('Escape'); await p.waitForTimeout(900);
  check(JSON.stringify(items().slice(0, 5)) === JSON.stringify(['Primeiroabc', 'Segundo', 'Um', 'Dois', 'Três']), 'colar 3 linhas → 3 itens (' + JSON.stringify(items()) + ')');
  // 6. Tab → sub-item; Ctrl Enter → feito
  await li(2).dblclick(); await p.waitForTimeout(150);
  await p.keyboard.press('Tab'); await p.waitForTimeout(150);
  await p.keyboard.press('Control+Enter'); await p.waitForTimeout(150);
  await p.keyboard.press('Escape'); await p.waitForTimeout(900);
  const raw = JSON.parse(execFileSync('php', ['-r', `require "tests/boot.php"; echo $pdo->query("SELECT content FROM cc_cards WHERE board_id=${BID} AND type='list' AND deleted_at IS NULL")->fetchColumn();`]).toString()).items[2];
  check(raw.l === 1 && raw.d === true, 'Tab faz sub-item e Ctrl Enter marca feito (' + JSON.stringify(raw) + ')');
  check(await p.locator('.ccc-li[data-i="2"]').evaluate(e => parseFloat(getComputedStyle(e).paddingLeft)) > 10, 'o sub-item aparece recuado');
  // 7. Ctrl Z desfaz a sessão de edição inteira
  await p.keyboard.press('Control+z'); await p.waitForTimeout(900);
  check(items()[2] === 'Um' && !JSON.parse(execFileSync('php', ['-r', `require "tests/boot.php"; echo $pdo->query("SELECT content FROM cc_cards WHERE board_id=${BID} AND type='list' AND deleted_at IS NULL")->fetchColumn();`]).toString()).items[2].d, 'Ctrl Z desfaz o Tab e o Ctrl Enter de uma vez');
  // 8. arrastar a pega ⋮⋮ reordena
  const h = p.locator('.ccc-li[data-i="0"] .ccc-lh');
  check(await h.count() === 1, 'cada item tem a pega ⋮⋮ para arrastar');
  await p.locator('.ccc-li[data-i="0"]').hover();
  await h.dragTo(p.locator('.ccc-li[data-i="1"]'), { targetPosition: { x: 40, y: 12 } });
  await p.waitForTimeout(900);
  check(items()[0] === 'Segundo' && items()[1] === 'Primeiroabc', 'arrastar o 1.º para baixo do 2.º troca-os (' + JSON.stringify(items().slice(0, 3)) + ')');
  await b.close();
  console.log(fail ? `FALHOU ${fail} de ${ok + fail}` : `OK ${ok}`);
  process.exit(fail ? 1 : 0);
})();
