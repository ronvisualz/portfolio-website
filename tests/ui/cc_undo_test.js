// Anular/Refazer visíveis e por pessoa (§33.10): botões ↶ ↷; cada pessoa só anula as suas ações; o que outra pessoa
// mudou entretanto fica, com aviso.   node tests/ui/cc_undo_test.js
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');
const BASE = 'http://127.0.0.1:8765';
let ok = 0, fail = 0;
const check = (c, msg) => { if (c) ok++; else { fail++; console.error('FALHA: ' + msg); } };
const BID = execFileSync('php', [path.join(__dirname, 'seed_board.php')]).toString().trim();
const note = () => JSON.parse(execFileSync('php', ['-r', `require "tests/boot.php"; echo json_encode($pdo->query("SELECT id, x, y, content FROM cc_cards WHERE board_id=${BID} AND type='note' AND deleted_at IS NULL")->fetch());`]).toString());

(async () => {
  const b = await chromium.launch();
  const A = await b.newPage({ viewport: { width: 1280, height: 900 } });
  const Bp = await (await b.newContext()).newPage({ viewport: { width: 1280, height: 900 } });
  await A.goto(BASE + '/admin/__as/1'); await A.goto(BASE + '/admin/toppernote.php?b=' + BID);
  await Bp.goto(BASE + '/admin/__as/3'); await Bp.goto(BASE + '/admin/toppernote.php?b=' + BID);
  await A.waitForSelector('.ccc.t-note'); await Bp.waitForSelector('.ccc.t-note'); await A.waitForTimeout(300);
  const un = A.locator('[data-undo="0"]'), re = A.locator('[data-undo="1"]');
  check(await un.count() === 1 && await re.count() === 1, 'a barra tem os botões Anular e Refazer');
  check(/Anular \((Ctrl|⌘) Z\)/.test(await un.getAttribute('aria-label')), 'o botão diz o atalho (' + await un.getAttribute('aria-label') + ')');
  check(await un.isDisabled() && await re.isDisabled(), 'sem nada para anular, ficam desativados');
  const n0 = note(), x0 = +n0.x;
  // A move a nota (teclado: Shift + → = um passo da grelha)
  await A.locator('.ccc.t-note').click(); await A.waitForTimeout(150);
  for (let i = 0; i < 3; i++) await A.keyboard.press('Shift+ArrowRight');
  await A.waitForTimeout(1000);
  const x1 = +note().x;
  check(x1 > x0, 'A moveu a nota (' + x0 + ' → ' + x1 + ')');
  check(!(await un.isDisabled()), 'Anular fica ativo');
  // B (outra pessoa) muda o texto da mesma nota
  await Bp.evaluate(async ([bid, id]) => {
    const csrf = document.querySelector('meta[name="csrf"]').content;
    const fd = new FormData(); fd.append('board', bid); fd.append('items', JSON.stringify([{ id, content: { text: 'Texto da Colab' } }]));
    await fetch('/admin/cc_api.php?a=cards_update', { method: 'POST', body: fd, headers: { 'X-CSRF-Token': csrf }, credentials: 'same-origin' });
  }, [BID, +n0.id]);
  await A.waitForFunction(() => /Texto da Colab/.test(document.querySelector('.ccc.t-note').textContent), null, { timeout: 15000 }).catch(() => {});
  check(/Texto da Colab/.test(await A.locator('.ccc.t-note').textContent()), 'A vê o texto novo da Colab');
  // A anula: a posição volta, o texto da Colab fica
  await un.click(); await A.waitForTimeout(1000);
  const n2 = note();
  check(+n2.x === x0, 'Anular devolve a posição de A (' + n2.x + ')');
  check(JSON.parse(n2.content).text === 'Texto da Colab', 'o texto da Colab fica');
  check(!(await re.isDisabled()), 'Refazer fica ativo');
  await re.click(); await A.waitForTimeout(1000);
  check(+note().x === x1, 'Refazer volta a mover');
  // A move outra vez; B move a mesma nota depois → o Ctrl Z de A não desfaz o movimento de B
  await A.keyboard.press('Escape'); await A.waitForTimeout(100);   // sem seleção: o clique seleciona (um 2.º clique editaria)
  await A.locator('.ccc.t-note').click(); await A.waitForTimeout(150); await A.keyboard.press('Shift+ArrowDown'); await A.waitForTimeout(1000);
  await Bp.evaluate(async ([bid, id]) => {
    const csrf = document.querySelector('meta[name="csrf"]').content;
    const fd = new FormData(); fd.append('board', bid); fd.append('items', JSON.stringify([{ id, x: 900, y: 900 }]));
    await fetch('/admin/cc_api.php?a=cards_update', { method: 'POST', body: fd, headers: { 'X-CSRF-Token': csrf }, credentials: 'same-origin' });
  }, [BID, +n0.id]);
  const seen = await A.waitForFunction(id => { const e = document.querySelector('.ccc[data-id="' + id + '"]'); return e && /900/.test(e.style.transform + e.style.left + e.style.top); }, +n0.id, { timeout: 15000 }).then(() => true).catch(() => false);
  check(seen, 'A vê a nota no sítio para onde a Colab a moveu');
  await A.keyboard.press('Escape'); await A.keyboard.press('Control+z'); await A.waitForTimeout(800);
  const n3 = note();
  check(+n3.x === 900 && +n3.y === 900, 'o movimento da Colab fica (' + n3.x + ',' + n3.y + ')');
  check(/1 alteração não anulada: outra pessoa mudou este cartão/.test(await A.locator('body').innerText()), 'aviso «1 alteração não anulada…»');
  await b.close();
  console.log(fail ? `FALHOU ${fail} de ${ok + fail}` : `OK ${ok}`);
  process.exit(fail ? 1 : 0);
})();
