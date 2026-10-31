// Cartão de link (§33.8): miniatura, título, autor; nos vídeos ▶ e duração; o Detalhe toca o leitor do serviço.
// node tests/ui/cc_link_card_test.js
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');
const BASE = 'http://127.0.0.1:8765';
let ok = 0, fail = 0;
const check = (c, msg) => { if (c) ok++; else { fail++; console.error('FALHA: ' + msg); } };
const BID = execFileSync('php', [path.join(__dirname, 'seed_board.php')]).toString().trim();
execFileSync('php', ['-r', `require "tests/boot.php"; require_once "admin/inc/cc.php";
  $im = imagecreatetruecolor(480, 360); imagefill($im, 0, 0, imagecolorallocate($im, 30, 30, 30)); ob_start(); imagejpeg($im); $jpg = ob_get_clean();
  $u = "https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=" . microtime(true);
  [$a] = cc_asset_create($pdo, null, ["kind" => "link", "source_url" => $u, "title" => "Never Gonna Give You Up", "preview" => $jpg, "name" => "", "mime" => "text/html", "no_ai" => true]);
  $pdo->prepare("UPDATE cc_assets SET analysis = ? WHERE id = ?")->execute([json_encode(["caption" => "Vídeo oficial", "site" => "YouTube", "author" => "Rick Astley", "provider" => "YouTube", "duration" => 213, "vembed" => "https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ"]), $a["id"]]);
  cc_card_add($pdo, ${BID}, ["type" => "link", "x" => 1100, "y" => 120, "w" => 280, "asset_id" => $a["id"]], 1);`]);

(async () => {
  const b = await chromium.launch();
  const p = await b.newPage({ viewport: { width: 1400, height: 900 } });
  const csp = [];
  p.on('console', m => { if (/Content Security Policy/i.test(m.text())) csp.push(m.text()); });
  await p.goto(BASE + '/admin/__as/1');
  await p.goto(BASE + '/admin/toppernote.php?b=' + BID);
  await p.waitForSelector('.ccc.t-link'); await p.waitForTimeout(400);
  const card = p.locator('.ccc.t-link');
  const txt = await card.innerText();
  check(/Never Gonna Give You Up/.test(txt) && /Rick Astley/.test(txt) && /YouTube/i.test(txt), 'título, autor e serviço no cartão (' + txt.replace(/\n/g, ' | ') + ')');
  check(await card.locator('.ccc-media img').count() === 1, 'com miniatura');
  check(await card.locator('.ccc-play').count() === 1 && /3:33/.test(txt), 'vídeo: ▶ e duração 3:33');
  await card.click(); await p.waitForTimeout(150);
  if (!(await p.locator('.ccb-side').isVisible())) await p.locator('[data-a="side"]').first().click();
  await p.locator('.ccb-side [data-tab="det"]').click(); await p.waitForTimeout(900);
  const fr = p.locator('.ccb-side .ccd-media iframe');
  check(await fr.count() === 1 && /youtube-nocookie\.com\/embed\/dQw4w9WgXcQ/.test(await fr.getAttribute('src')), 'o Detalhe tem o leitor do YouTube (sem cookies)');
  check(!/autoplay=1/.test(await fr.getAttribute('src')), 'sem tocar sozinho (o som só com ▶)');
  check(!csp.some(t => /youtube-nocookie/.test(t)), 'a CSP deixa o leitor (' + csp.join(' | ').slice(0, 200) + ')');
  await b.close();
  console.log(fail ? `FALHOU ${fail} de ${ok + fail}` : `OK ${ok}`);
  process.exit(fail ? 1 : 0);
})();
