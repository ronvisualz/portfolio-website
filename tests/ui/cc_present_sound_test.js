// Som só em Apresentar (§33.13): ao chegar a um vídeo/áudio toca com som; avançar pausa; Espaço pausa/retoma; volume e
// «Sem som» na barra; fora de Apresentar nunca há som sozinho; sair pára tudo.   node tests/ui/cc_present_sound_test.js
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path'), os = require('os'), fs = require('fs');
const BASE = 'http://127.0.0.1:8765';
let ok = 0, fail = 0;
const check = (c, msg) => { if (c) ok++; else { fail++; console.error('FALHA: ' + msg); } };
const BID = execFileSync('php', [path.join(__dirname, 'seed_board.php')]).toString().trim();
const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'ccpres-'));
const WEBM = path.join(dir, 'v.webm'), WAV = path.join(dir, 'a.wav');
execFileSync('ffmpeg', ['-hide_banner', '-loglevel', 'error', '-y', '-f', 'lavfi', '-i', 'testsrc=size=320x240:rate=15', '-f', 'lavfi', '-i', 'sine=frequency=440', '-t', '6', '-c:v', 'libvpx', '-b:v', '150k', '-c:a', 'libvorbis', WEBM]);
execFileSync('ffmpeg', ['-hide_banner', '-loglevel', 'error', '-y', '-f', 'lavfi', '-i', 'sine=frequency=330', '-t', '6', WAV]);
// um vídeo e um áudio à direita dos outros cartões (ficam no fim da ordem de leitura da 2.ª linha)
execFileSync('php', ['-r', `require "tests/boot.php"; require_once "admin/inc/cc.php"; require "tests/fixtures.php";
  [$v] = tests_cc_asset($pdo, file_get_contents("${WEBM}"), ['name' => 'v.webm', 'mime' => 'video/webm', 'kind' => 'video', 'w' => 320, 'h' => 240]);
  [$a] = tests_cc_asset($pdo, file_get_contents("${WAV}"), ['name' => 'a.wav', 'mime' => 'audio/wav', 'kind' => 'audio']);
  cc_card_add($pdo, ${BID}, ['type' => 'video', 'x' => 1100, 'y' => 420, 'w' => 280, 'asset_id' => $v['id']], 1);
  cc_card_add($pdo, ${BID}, ['type' => 'audio', 'x' => 1450, 'y' => 420, 'w' => 300, 'asset_id' => $a['id']], 1);`]);

(async () => {
  const b = await chromium.launch({ args: ['--autoplay-policy=no-user-gesture-required'] });
  const p = await b.newPage({ viewport: { width: 1400, height: 900 } });
  await p.goto(BASE + '/admin/__as/1');
  await p.goto(BASE + '/admin/toppernote.php?b=' + BID);
  await p.waitForSelector('.ccc.t-video'); await p.waitForTimeout(400);
  const playing = () => p.evaluate(() => [...document.querySelectorAll('video, audio')].filter(m => !m.paused).map(m => ({ tag: m.tagName, muted: m.muted, vol: m.volume })));
  // fora de Apresentar: passar o rato pré-visualiza sem som
  await p.locator('.ccc.t-video').hover(); await p.waitForTimeout(900);
  const hov = await playing();
  check(hov.every(m => m.muted), 'fora de Apresentar a pré-visualização é sem som (' + JSON.stringify(hov) + ')');
  await p.mouse.move(5, 880);
  // Apresentar: avança até ao vídeo
  await p.locator('[data-a="present"]').click(); await p.waitForTimeout(500);
  const bar = p.locator('.cc-pres');
  let guard = 0;
  while (!/vídeo|v\.webm/i.test(await p.evaluate(() => (document.querySelector('.cc-pres [data-pt]') || {}).textContent || '')) && guard++ < 12) { await p.keyboard.press('ArrowRight'); await p.waitForTimeout(350); }
  await p.waitForTimeout(900);
  let pl = await playing();
  check(pl.length === 1 && pl[0].tag === 'VIDEO' && !pl[0].muted, 'ao chegar ao vídeo toca com som (' + JSON.stringify(pl) + ')');
  check(await bar.locator('[data-p="mute"]').isVisible() && await bar.locator('input[type=range]').isVisible(), 'a barra mostra volume e «Sem som»');
  await p.keyboard.press('Space'); await p.waitForTimeout(300);
  check((await playing()).length === 0, 'Espaço pausa');
  await p.keyboard.press('Space'); await p.waitForTimeout(400);
  check((await playing()).length === 1, 'Espaço retoma');
  await bar.locator('[data-p="mute"]').click(); await p.waitForTimeout(200);
  pl = await playing();
  check(pl.length === 1 && pl[0].muted && await bar.locator('[data-p="mute"]').getAttribute('aria-pressed') === 'true', '«Sem som» põe o vídeo mudo');
  await bar.locator('[data-p="mute"]').click();
  await bar.locator('input[type=range]').fill('0.3'); await p.waitForTimeout(150);
  pl = await playing();
  check(pl.length === 1 && Math.abs(pl[0].vol - 0.3) < 0.01, 'o volume da barra aplica-se (' + JSON.stringify(pl) + ')');
  // avançar: o vídeo pausa e o áudio toca (com o foco no volume as setas são dele; o foco volta à barra)
  await bar.focus();
  await p.keyboard.press('ArrowRight'); await p.waitForTimeout(900);
  pl = await playing();
  check(pl.length === 1 && pl[0].tag === 'AUDIO' && !pl[0].muted, 'avançar pausa o vídeo e o áudio seguinte toca (' + JSON.stringify(pl) + ')');
  await p.keyboard.press('ArrowLeft'); await p.waitForTimeout(800);
  check((await playing()).every(m => m.tag === 'VIDEO'), 'recuar pausa o áudio');
  // sair: nada fica a tocar
  await p.keyboard.press('Escape'); await p.waitForTimeout(500);
  check((await playing()).length === 0, 'sair de Apresentar pára tudo');
  check(await p.locator('.ccc.t-video video').count() === 0, 'e o cartão volta ao normal');
  // o browser recusa tocar com som → «Tocar» no cartão, nada escondido a tocar
  const b2 = await chromium.launch({ args: ['--autoplay-policy=document-user-activation-required'] });
  const q = await b2.newPage({ viewport: { width: 1400, height: 900 } });
  await q.goto(BASE + '/admin/__as/1'); await q.goto(BASE + '/admin/toppernote.php?b=' + BID);
  await q.waitForSelector('.ccc.t-video'); await q.waitForTimeout(300);
  await q.evaluate(() => { HTMLMediaElement.prototype.play = function () { return Promise.reject(new DOMException('no', 'NotAllowedError')); }; });
  await q.locator('[data-a="present"]').click(); await q.waitForTimeout(400);
  guard = 0;
  while (!/vídeo/i.test(await q.evaluate(() => (document.querySelector('.cc-pres [data-pt]') || {}).textContent || '')) && guard++ < 12) { await q.keyboard.press('ArrowRight'); await q.waitForTimeout(350); }
  await q.waitForTimeout(500);
  check(await q.locator('.ccc.t-video .pres-play').isVisible().catch(() => false), 'se o browser recusar o som aparece «Tocar» no cartão');
  await b2.close();
  await b.close();
  fs.rmSync(dir, { recursive: true, force: true });
  console.log(fail ? `FALHOU ${fail} de ${ok + fail}` : `OK ${ok}`);
  process.exit(fail ? 1 : 0);
})();
