// Faturação num Topperboard (§33.9): um recibo que alguém com o Financeiro pôs no Topperboard aparece a quem não tem
// o Financeiro como «Sem acesso a este ficheiro» (sem miniatura, sem nome, sem pedidos ao ficheiro, não abre no
// visualizador); quem tem o Financeiro vê a imagem.   node tests/ui/cc_drive_noaccess_test.js
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const BASE = 'http://127.0.0.1:8765';
let ok = 0, fail = 0;
const check = (c, msg) => { if (c) ok++; else { fail++; console.error('FALHA: ' + msg); } };
const NAME = 'na' + Date.now().toString(36);
const setup = JSON.parse(execFileSync('php', ['-r', `require "tests/boot.php"; require "tests/fixtures.php"; require_once "admin/inc/cc.php";
  $b = cc_board_create($pdo, ["title" => "Teste33 · sem acesso", "kind" => "free"], 1);
  cc_card_add($pdo, $b, ["type" => "note", "x" => 0, "y" => 0, "content" => ["text" => "Uma nota"]], 1);
  $im = imagecreatetruecolor(600, 800); imagefilledrectangle($im, 0, 0, 599, 799, imagecolorallocate($im, 30, 140, 90));
  imagesetpixel($im, 3, 3, imagecolorallocate($im, random_int(0, 255), 7, 7)); ob_start(); imagepng($im); $png = ob_get_clean();
  $k = tests_put("Empresa/Financeiro/2026/11/${NAME}-recibo.png", $png, "image/png");
  $r = tests_http("POST", "/admin/cc_api.php?a=drive_add", ["board" => $b, "keys" => json_encode([$k]), "x" => 320, "y" => 0], 1, true);
  echo json_encode(["b" => $b, "card" => $r["json"]["cards"][0]["id"] ?? 0, "asset" => $r["json"]["cards"][0]["asset"]["id"] ?? 0]);`]).toString());

(async () => {
  check(setup.card > 0 && setup.asset > 0, 'o recibo entrou no Topperboard (' + JSON.stringify(setup) + ')');
  const b = await chromium.launch();
  // ── quem não tem o Financeiro ──
  const p = await b.newPage({ viewport: { width: 1400, height: 900 } });
  const fileReqs = [], errs = [];
  p.on('request', rq => { if (rq.url().includes('cc_file.php') && rq.url().includes('a=' + setup.asset + '&')) fileReqs.push(rq.url()); });
  p.on('pageerror', e => errs.push(String(e)));
  await p.goto(BASE + '/admin/__as/3');
  await p.goto(BASE + '/admin/toppernote.php?b=' + setup.b);
  const card = p.locator('.ccc[data-id="' + setup.card + '"]');
  await card.waitFor({ timeout: 10000 }).catch(() => {});
  await p.waitForTimeout(800);
  check(await card.count() === 1, 'o cartão aparece a quem não tem o Financeiro');
  check(/Sem acesso a este ficheiro/.test(await card.innerText().catch(() => '')), 'o cartão diz «Sem acesso a este ficheiro»');
  check(await card.locator('img').count() === 0, 'sem miniatura no cartão');
  check(await card.locator('.ccc-pal, .ccc-tags, .ccc-ai').count() === 0, 'sem paleta, etiquetas nem estado da análise (vêm do conteúdo do ficheiro)');
  check(!(await p.content()).includes(NAME), 'o nome do recibo não está na página');
  await card.dblclick().catch(() => {});
  await p.waitForTimeout(500);
  check(await p.locator('.cc-viewer').count() === 0, 'duplo clique não abre o visualizador');
  check(fileReqs.length === 0, 'nenhum pedido ao ficheiro do recibo (' + fileReqs.join(' ') + ')');
  // o painel «Detalhe» com o cartão escolhido: só o aviso (sem formulário, sem «Guardar», que apagava o título)
  await p.locator('[data-a="side"]').first().click();
  await p.waitForTimeout(300);
  if (!(await p.locator('[data-tab="det"]').isVisible())) { await p.locator('[data-a="side"]').first().click(); await p.waitForTimeout(300); }
  await p.click('[data-tab="det"]');
  await card.click({ position: { x: 20, y: 20 } });
  await p.waitForTimeout(1200);
  const side = p.locator('[data-side-b]');
  check(/Sem acesso a este ficheiro/.test(await side.innerText().catch(() => '')), 'o Detalhe diz «Sem acesso a este ficheiro» (' + (await side.innerText().catch(() => '')).slice(0, 120) + ')');
  check(await side.locator('[data-d], button:has-text("Guardar")').count() === 0, 'o Detalhe não tem «Guardar», «Analisar outra vez», «Mais como este» nem «Apagar»');
  check(await side.locator('input, textarea, select').count() === 0, 'o Detalhe não tem campos para editar');
  // a Biblioteca: o recibo nem aparece (nada de mosaico vazio «Sem acesso»; as pesquisas e os números também não o contam)
  await p.goto(BASE + '/admin/toppernote.php?t=biblioteca');
  await p.locator('.ccl-tile').first().waitFor({ timeout: 10000 }).catch(() => {});
  await p.waitForTimeout(800);
  check(await p.locator('.ccl-tile').count() > 0 && await p.locator('.ccl-tile[data-id="' + setup.asset + '"]').count() === 0,
        'a Biblioteca abre com as outras referências, sem o recibo');
  check(fileReqs.length === 0, 'nenhum pedido ao ficheiro do recibo depois do Detalhe e da Biblioteca (' + fileReqs.join(' ') + ')');
  check(errs.length === 0, 'sem erros de JavaScript (' + errs.join(' | ') + ')');
  // ── quem tem o Financeiro ──
  const q = await b.newPage({ viewport: { width: 1400, height: 900 } });
  await q.goto(BASE + '/admin/__as/1');
  await q.goto(BASE + '/admin/toppernote.php?b=' + setup.b);
  const c1 = q.locator('.ccc[data-id="' + setup.card + '"]');
  await c1.waitFor({ timeout: 10000 }).catch(() => {});
  await q.waitForFunction(id => { const i = document.querySelector('.ccc[data-id="' + id + '"] img'); return i && i.complete && i.naturalWidth > 0; }, setup.card, { timeout: 8000 }).catch(() => {});
  check(await c1.locator('img').evaluate(i => i.naturalWidth > 0).catch(() => false), 'quem tem o Financeiro vê a miniatura');
  check(!/Sem acesso/.test(await c1.innerText().catch(() => '')), 'e não vê o aviso «Sem acesso»');
  await q.goto(BASE + '/admin/toppernote.php?t=biblioteca');
  const t1 = q.locator('.ccl-tile[data-id="' + setup.asset + '"]');
  await t1.waitFor({ timeout: 10000 }).catch(() => {});
  check(await t1.count() === 1, 'quem tem o Financeiro encontra o recibo na Biblioteca');
  await b.close();
  console.log(fail ? `FALHOU ${fail} de ${ok + fail}` : `OK ${ok}`);
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
