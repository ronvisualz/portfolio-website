// Visualizador interno (§33.10): duplo clique ou Espaço abre em ecrã inteiro; zoom; ← → entre imagens do Topperboard; Esc fecha.
// node tests/ui/cc_viewer_test.js
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');
const BASE = 'http://127.0.0.1:8765';
let ok = 0, fail = 0;
const check = (c, msg) => { if (c) ok++; else { fail++; console.error('FALHA: ' + msg); } };
const BID = execFileSync('php', [path.join(__dirname, 'seed_board.php')]).toString().trim();
// uma segunda imagem, à direita e mais abaixo (ordem de leitura: a do seed primeiro)
const SECOND = execFileSync('php', ['-r', `require "tests/boot.php"; require_once "admin/inc/cc.php"; $a = $pdo->query("SELECT asset_id FROM cc_cards WHERE board_id=${BID} AND type='image'")->fetchColumn(); echo cc_card_add($pdo, ${BID}, ['type' => 'image', 'x' => 1100, 'y' => 460, 'w' => 200, 'asset_id' => $a, 'content' => ['caption' => 'Segunda']], 1);`]).toString().trim();

(async () => {
  const b = await chromium.launch();
  const p = await b.newPage({ viewport: { width: 1280, height: 900 } });
  await p.goto(BASE + '/admin/__as/1');
  await p.goto(BASE + '/admin/toppernote.php?b=' + BID);
  await p.waitForSelector('.ccc.t-image'); await p.waitForTimeout(400);
  const first = p.locator('.ccc.t-image').first();
  const firstId = await first.getAttribute('data-id');
  await first.dblclick();
  const v = p.locator('.cc-viewer');
  await v.waitFor({ timeout: 3000 }).catch(() => {});
  check(await v.isVisible().catch(() => false), 'duplo clique numa imagem abre o visualizador');
  check(await v.getAttribute('role') === 'dialog' && await v.getAttribute('aria-modal') === 'true', 'é um diálogo modal');
  const s = await v.locator('img').evaluate(i => new URL(i.src, location.href).searchParams.get('s'));
  check(s === '1600' || s === '0', 'mostra a imagem grande (s=' + s + ')');
  const bg = await v.evaluate(e => getComputedStyle(e).backgroundColor);
  check(/rgba?\((\d+), (\d+), (\d+)/.test(bg) && +RegExp.$1 < 40, 'fundo escuro (' + bg + ')');
  check(/1 \/ 2/.test(await v.innerText()), 'diz a posição (1 / 2)');
  await p.keyboard.press('ArrowRight'); await p.waitForTimeout(250);
  check(/2 \/ 2/.test(await v.innerText()) && /Segunda/.test(await v.innerText()), '→ passa à imagem seguinte');
  await p.keyboard.press('ArrowLeft'); await p.waitForTimeout(250);
  check(/1 \/ 2/.test(await v.innerText()), '← volta');
  const z0 = await v.locator('output').textContent();
  const vb = await v.boundingBox();
  await p.mouse.move(vb.x + vb.width / 2, vb.y + vb.height / 2);
  await p.keyboard.down('Control'); await p.mouse.wheel(0, -300); await p.keyboard.up('Control'); await p.waitForTimeout(250);
  const z1 = await v.locator('output').textContent();
  check(parseInt(z1) > parseInt(z0), 'Ctrl + roda faz zoom (' + z0 + ' → ' + z1 + ')');
  await p.keyboard.press('0'); await p.waitForTimeout(150);
  check(await v.locator('output').textContent() === '100%', '0 repõe o tamanho');
  await p.keyboard.press('Escape'); await p.waitForTimeout(250);
  check(await v.count() === 0, 'Esc fecha');
  check(await p.evaluate(id => document.activeElement && document.activeElement.closest('.ccc') && document.activeElement.closest('.ccc').dataset.id === id, firstId), 'o foco volta ao cartão');
  // Espaço com uma imagem selecionada também abre (Espaço + arrastar continua a mover a tela)
  await p.keyboard.press('Escape');
  await p.locator('.ccc.t-image[data-id="' + SECOND + '"]').click(); await p.waitForTimeout(200);
  await p.keyboard.press('Space'); await p.waitForTimeout(300);
  check(await v.isVisible().catch(() => false) && /2 \/ 2/.test(await v.innerText()), 'Espaço com a imagem selecionada abre-a');
  await p.keyboard.press('Escape');
  await b.close();
  console.log(fail ? `FALHOU ${fail} de ${ok + fail}` : `OK ${ok}`);
  process.exit(fail ? 1 : 0);
})();
