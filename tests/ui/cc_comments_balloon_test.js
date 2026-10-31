// Balão de comentários (§33.4): passar o cursor (ou tocar) no número mostra os últimos 3, só de leitura, e «Ver todos».
// node tests/ui/cc_comments_balloon_test.js
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');
const BASE = 'http://127.0.0.1:8765';
let ok = 0, fail = 0;
const check = (c, msg) => { if (c) ok++; else { fail++; console.error('FALHA: ' + msg); } };
const BID = execFileSync('php', [path.join(__dirname, 'seed_board.php')]).toString().trim();
execFileSync('php', ['-r', `require "tests/boot.php"; require_once "admin/inc/cc.php"; $b = cc_board_get($pdo, ${BID}); $c = $pdo->query("SELECT id FROM cc_cards WHERE board_id=${BID} AND type='note'")->fetchColumn(); $u = $pdo->query("SELECT * FROM users WHERE id=1")->fetch(); for ($i = 1; $i <= 5; $i++) cc_comment_add($pdo, $b, ['id' => $c], "Comentário número $i " . str_repeat("texto longo ", $i === 5 ? 20 : 1), $u);`]);

(async () => {
  const b = await chromium.launch();
  const p = await b.newPage({ viewport: { width: 1280, height: 900 } });
  await p.goto(BASE + '/admin/__as/1');
  await p.goto(BASE + '/admin/toppernote.php?b=' + BID);
  await p.waitForSelector('.ccc-nc');
  await p.waitForTimeout(300);
  const nc = p.locator('.ccc-nc').first(), bal = p.locator('.cc-nballoon');
  check(await nc.evaluate(e => e.tagName) === 'BUTTON' && /5 comentários/.test(await nc.getAttribute('aria-label')), 'o número é um botão com nome legível');
  await nc.hover();
  await p.waitForTimeout(150);
  check(await bal.count() === 0, 'não abre logo (espera ~250 ms para não piscar)');
  await bal.waitFor({ timeout: 2000 }).catch(() => {});
  check(await bal.isVisible().catch(() => false), 'passar o cursor no número abre o balão');
  check(await bal.locator('.nb-i').count() === 3, 'mostra os 3 últimos (' + await bal.locator('.nb-i').count() + ')');
  const txt = await bal.innerText();
  check(/número 5/.test(txt) && /número 3/.test(txt) && !/número 2/.test(txt), 'são os mais recentes');
  check(/…/.test(txt) && !/(texto longo ){15}/.test(txt), 'textos longos cortados a ~120 caracteres');
  check(await bal.locator('textarea, input, [contenteditable]').count() === 0, 'só de leitura (sem caixa de resposta)');
  check(/Ver todos \(5\)/.test(txt), 'tem «Ver todos (5)»');
  const nb = await nc.boundingBox(), bb = await bal.boundingBox();
  check(Math.min(Math.abs(bb.y - (nb.y + nb.height)), Math.abs(nb.y - (bb.y + bb.height))) <= 12, 'o balão fica junto do número');
  await p.mouse.move(5, 880); await p.waitForTimeout(500);
  check(await bal.count() === 0, 'sair com o cursor fecha o balão');
  // teclado: foco no número + Enter abre; Esc fecha
  await nc.focus(); await p.keyboard.press('Enter'); await p.waitForTimeout(400);
  check(await bal.isVisible().catch(() => false), 'Enter no número abre o balão');
  await p.keyboard.press('Escape'); await p.waitForTimeout(150);
  check(await bal.count() === 0, 'Esc fecha o balão');
  // «Ver todos» abre o painel Comentários desse cartão
  await nc.hover(); await bal.waitFor();
  await bal.locator('button', { hasText: 'Ver todos' }).click(); await p.waitForTimeout(600);
  check(await p.locator('.ccb-side [data-tab="com"].on, .ccb-side [data-tab="com"][aria-selected="true"]').count() === 1, '«Ver todos» abre o separador Comentários');
  check(/número 1/.test(await p.locator('.ccb-side').innerText()), 'o painel mostra todos (incluindo o 1.º)');
  // toque (iPad)
  const t = await b.newPage({ viewport: { width: 1180, height: 820 }, hasTouch: true, isMobile: false });
  await t.goto(BASE + '/admin/__as/1');
  await t.goto(BASE + '/admin/toppernote.php?b=' + BID);
  await t.waitForSelector('.ccc-nc'); await t.waitForTimeout(300);
  await t.locator('.ccc-nc').first().tap(); await t.waitForTimeout(500);
  check(await t.locator('.cc-nballoon').isVisible().catch(() => false), 'tocar no número abre o balão (iPad)');
  await b.close();
  console.log(fail ? `FALHOU ${fail} de ${ok + fail}` : `OK ${ok}`);
  process.exit(fail ? 1 : 0);
})();
