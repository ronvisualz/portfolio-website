// Conta-gotas e paleta (§33.11): «+ Escolher da imagem» (I), lupa com #HEX, média 3×3 / Alt 1 px, Shift várias, Esc sai;
// clicar numa cor copia; menu ⋯ (RGB, HSL, renomear, remover, Amostra de cor); «Copiar paleta».   node tests/ui/cc_eyedropper_test.js
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');
const BASE = 'http://127.0.0.1:8765';
let ok = 0, fail = 0;
const check = (c, msg) => { if (c) ok++; else { fail++; console.error('FALHA: ' + msg); } };
const BID = execFileSync('php', [path.join(__dirname, 'seed_board.php')]).toString().trim();
// imagem semeada: metade esquerda #DC2828 (220,40,40), direita #2F77B1; repõe a paleta (sem manuais)
const AID = execFileSync('php', ['-r', `require "tests/boot.php"; $a = (int)$pdo->query("SELECT asset_id FROM cc_cards WHERE board_id=${BID} AND type='image'")->fetchColumn(); $p = json_decode((string)$pdo->query("SELECT palette FROM cc_assets WHERE id=$a")->fetchColumn(), true) ?: []; $p = array_values(array_map(fn($c) => array_slice($c, 0, 5), array_filter($p, fn($c) => empty($c[5]['m'])))); $pdo->prepare("UPDATE cc_assets SET palette=? WHERE id=?")->execute([json_encode($p), $a]); echo $a;`]).toString().trim();
const manual = () => JSON.parse(execFileSync('php', ['-r', `require "tests/boot.php"; echo $pdo->query("SELECT palette FROM cc_assets WHERE id=${AID}")->fetchColumn();`]).toString()).filter(c => c[5] && c[5].m).map(c => c[0]);

(async () => {
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: 1400, height: 900 }, permissions: ['clipboard-read', 'clipboard-write'] });
  const p = await ctx.newPage();
  await p.goto(BASE + '/admin/__as/1');
  await p.goto(BASE + '/admin/toppernote.php?b=' + BID);
  await p.waitForSelector('.ccc.t-image'); await p.waitForTimeout(300);
  await p.locator('.ccc.t-image').click(); await p.waitForTimeout(200);
  await p.keyboard.press('i');   // I: abre o detalhe e liga o conta-gotas
  const media = p.locator('.ccb-side .ccd-media img');
  await media.waitFor({ timeout: 5000 }).catch(() => {});
  await p.waitForFunction(() => document.querySelector('.ccb-side .ccd-media.picking'), null, { timeout: 8000 }).catch(() => {});
  check(await p.locator('.ccb-side .ccd-media.picking').count() === 1, 'I liga o modo conta-gotas na imagem do detalhe');
  const mb = await media.boundingBox();
  await p.mouse.move(mb.x + mb.width * 0.25, mb.y + mb.height * 0.5);
  await p.waitForFunction(() => /#[0-9A-F]{6}/.test((document.querySelector('.cc-loupe') || {}).textContent || ''), null, { timeout: 10000 }).catch(() => {});
  const lt = await p.locator('.cc-loupe').textContent().catch(() => '');
  check(/#DC2828/.test(lt), 'a lupa mostra o #HEX do tom do original (' + lt + ')');
  check(await p.locator('.cc-loupe canvas').count() === 1, 'a lupa tem os píxeis ampliados');
  // Shift + clique adiciona e fica no modo
  await p.keyboard.down('Shift'); await p.mouse.click(mb.x + mb.width * 0.25, mb.y + mb.height * 0.5); await p.keyboard.up('Shift');
  await p.waitForTimeout(800);
  check(manual().includes('#DC2828'), 'clique adiciona a cor à paleta (' + JSON.stringify(manual()) + ')');
  check(await p.locator('.ccb-side .ccd-media.picking').count() === 1, 'com Shift fica no modo');
  // Alt = 1 píxel; clique sem Shift sai do modo
  await p.mouse.move(mb.x + mb.width * 0.75, mb.y + mb.height * 0.5);
  await p.keyboard.down('Alt'); await p.mouse.click(mb.x + mb.width * 0.75, mb.y + mb.height * 0.5); await p.keyboard.up('Alt');
  await p.waitForTimeout(800);
  check(manual().includes('#2F77B1'), 'Alt + clique escolhe o píxel exato (#2F77B1)');
  check(await p.locator('.ccb-side .ccd-media.picking').count() === 0 && await p.locator('.cc-loupe').count() === 0, 'sem Shift sai do modo');
  // clicar numa cor copia o #HEX
  await p.waitForSelector('.ccb-side [data-pal-hex="#DC2828"]');
  await p.locator('.ccb-side [data-pal-hex="#DC2828"]').click(); await p.waitForTimeout(300);
  check((await p.evaluate(() => navigator.clipboard.readText())) === '#DC2828', 'clicar numa cor copia o #HEX');
  // menu ⋯
  await p.locator('.ccb-side [data-pal-menu="#DC2828"]').click();
  const menu = p.locator('.cc-pmenu');
  check(/rgb\(220, 40, 40\)/.test(await menu.innerText()) && /hsl\(/.test(await menu.innerText()), 'o menu ⋯ mostra RGB e HSL');
  check(await menu.locator('button', { hasText: 'Criar Amostra de cor' }).count() === 1, 'e «Criar Amostra de cor»');
  const n0 = await p.locator('.ccc.t-swatch').count();
  await menu.locator('button', { hasText: 'Criar Amostra de cor' }).click(); await p.waitForTimeout(800);
  check(await p.locator('.ccc.t-swatch').count() === n0 + 1, 'cria um cartão Amostra de cor');
  await p.locator('.ccb-side [data-pal-menu="#DC2828"]').click();
  await p.locator('.cc-pmenu button', { hasText: 'Remover' }).click(); await p.waitForTimeout(800);
  check(!manual().includes('#DC2828'), '«Remover» tira a cor manual');
  // «Copiar paleta»
  await p.locator('.ccb-side [data-pal-copyall]').click(); await p.waitForTimeout(300);
  check(/#2F77B1/.test(await p.evaluate(() => navigator.clipboard.readText())), '«Copiar paleta» copia os #HEX');
  // Esc sai do modo
  await p.locator('.ccb-side [data-pick]').click(); await p.waitForTimeout(300);
  await p.keyboard.press('Escape'); await p.waitForTimeout(200);
  check(await p.locator('.ccb-side .ccd-media.picking').count() === 0, 'Esc sai do modo');
  await b.close();
  console.log(fail ? `FALHOU ${fail} de ${ok + fail}` : `OK ${ok}`);
  process.exit(fail ? 1 : 0);
})();
