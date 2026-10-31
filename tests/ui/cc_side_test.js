// Painel de detalhes redimensionável (§33.7): pela borda, 280 px a 60% da janela, duplo clique repõe 340, largura por pessoa.
// node tests/ui/cc_side_test.js
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');
const BASE = 'http://127.0.0.1:8765';
let ok = 0, fail = 0;
const check = (c, msg) => { if (c) ok++; else { fail++; console.error('FALHA: ' + msg); } };
const BID = execFileSync('php', [path.join(__dirname, 'seed_board.php')]).toString().trim();
execFileSync('php', ['-r', 'require "tests/boot.php"; $p = json_decode((string)$pdo->query("SELECT prefs FROM users WHERE id=1")->fetchColumn(), true) ?: []; unset($p["cc"]["side_w"]); $pdo->prepare("UPDATE users SET prefs=? WHERE id=1")->execute([json_encode($p)]);']);
const W = 1400;

(async () => {
  const b = await chromium.launch();
  const p = await b.newPage({ viewport: { width: W, height: 900 } });
  await p.goto(BASE + '/admin/__as/1');
  const open = async () => {
    await p.goto(BASE + '/admin/toppernote.php?b=' + BID);
    await p.waitForSelector('.ccc[data-id]'); await p.waitForTimeout(300);
    if (!(await p.locator('.ccb-side').isVisible())) { await p.locator('[data-a="side"]').first().click(); await p.waitForTimeout(200); }
  };
  await open();
  const side = p.locator('.ccb-side'), grip = p.locator('.ccb-side-grip');
  const width = async () => Math.round((await side.boundingBox()).width);
  check(await grip.count() === 1, 'o painel tem uma pega na borda');
  check(await grip.getAttribute('role') === 'separator' && await grip.getAttribute('aria-orientation') === 'vertical', 'a pega é um separador acessível');
  check(Math.abs(await width() - 340) <= 2, 'começa com 340 px (' + await width() + ')');
  const dragTo = async x => {
    const g = await grip.boundingBox();
    await p.mouse.move(g.x + g.width / 2, g.y + 200); await p.mouse.down();
    await p.mouse.move((g.x + x) / 2, g.y + 200, { steps: 4 }); await p.mouse.move(x, g.y + 200, { steps: 4 }); await p.mouse.up();
    await p.waitForTimeout(400);
  };
  await dragTo(W - 200);
  check(Math.abs(await width() - 280) <= 2, 'arrastar para 200 px fica nos 280 mínimos (' + await width() + ')');
  await dragTo(W - W * 0.9);
  check(Math.abs(await width() - W * 0.6) <= 2, 'arrastar para 90% fica nos 60% (' + await width() + ')');
  await grip.dblclick(); await p.waitForTimeout(400);
  check(Math.abs(await width() - 340) <= 2, 'duplo clique repõe 340 px (' + await width() + ')');
  // teclado: ← alarga 16 px, → estreita
  await grip.focus(); await p.keyboard.press('ArrowLeft'); await p.waitForTimeout(400);
  check(Math.abs(await width() - 356) <= 2, '← na pega alarga 16 px (' + await width() + ')');
  check(+(await grip.getAttribute('aria-valuenow')) === 356, 'aria-valuenow acompanha');
  await dragTo(W - 500);
  await p.waitForTimeout(500);
  await open();
  check(Math.abs(await width() - 500) <= 2, 'depois de recarregar mantém a largura da pessoa (' + await width() + ')');
  // outra pessoa não herda a largura
  const q = await b.newPage({ viewport: { width: W, height: 900 } });
  await q.goto(BASE + '/admin/__as/3'); await q.goto(BASE + '/admin/toppernote.php?b=' + BID);
  await q.waitForSelector('.ccc[data-id]'); await q.waitForTimeout(300);
  if (!(await q.locator('.ccb-side').isVisible())) { await q.locator('[data-a="side"]').first().click(); await q.waitForTimeout(200); }
  const w3 = Math.round((await q.locator('.ccb-side').boundingBox()).width);
  check(w3 !== 500, 'a largura é por pessoa (outra pessoa: ' + w3 + ')');
  await b.close();
  console.log(fail ? `FALHOU ${fail} de ${ok + fail}` : `OK ${ok}`);
  process.exit(fail ? 1 : 0);
})();
