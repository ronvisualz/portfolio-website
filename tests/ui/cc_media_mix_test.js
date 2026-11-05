// Vídeo e MP3 no Topperboard (decidido com a equipa a 5 nov). Fora de Apresentar: o ▶ do cartão de vídeo toca com som
// no próprio cartão até ao fim (o cursor sair não o pára) e um MP3 toca ao mesmo tempo. Em Apresentar: um vídeo que não
// é o diapositivo (dentro de uma coluna, como no Topperboard real) começa com som ao passar o cursor e continua depois de
// o cursor sair; clicar pausa/retoma; o ▶ de um MP3 dentro da coluna toca; mudar de diapositivo ou sair pára tudo.
//   node tests/ui/cc_media_mix_test.js
const { chromium, devices } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path'), os = require('os'), fs = require('fs');
const BASE = 'http://127.0.0.1:8765';
const ROOT = path.join(__dirname, '..', '..');
let ok = 0, fail = 0;
const check = (c, msg) => { if (c) ok++; else { fail++; console.error('FALHA: ' + msg); } };
const php = code => execFileSync('php', ['-r', 'require "tests/boot.php"; require_once "admin/inc/cc.php"; require_once "tests/fixtures.php"; ' + code], { cwd: ROOT }).toString().trim();

const TITLE = 'Teste · Vídeo e MP3 juntos';
const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'ccmix-'));
const LONG = path.join(dir, 'longo.webm'), SHORT = path.join(dir, 'curto.webm'), MP3 = path.join(dir, 'som.mp3');
execFileSync('ffmpeg', ['-hide_banner', '-loglevel', 'error', '-y', '-f', 'lavfi', '-i', 'testsrc=size=160x120:rate=10', '-f', 'lavfi', '-i', 'sine=frequency=470', '-t', '40', '-c:v', 'libvpx', '-b:v', '40k', '-c:a', 'libvorbis', LONG]);
execFileSync('ffmpeg', ['-hide_banner', '-loglevel', 'error', '-y', '-f', 'lavfi', '-i', 'testsrc=size=160x120:rate=10', '-f', 'lavfi', '-i', 'sine=frequency=610', '-t', '3', '-c:v', 'libvpx', '-b:v', '40k', '-c:a', 'libvorbis', SHORT]);
execFileSync('ffmpeg', ['-hide_banner', '-loglevel', 'error', '-y', '-f', 'lavfi', '-i', 'sine=frequency=350', '-t', '40', '-codec:a', 'libmp3lame', '-b:a', '64k', MP3]);
// nota (0,0) → coluna com vídeo longo + MP3 (500,0) → vídeo curto solto (1000,0) → MP3 solto (1400,0)
const S = JSON.parse(php(`
  foreach ($pdo->query("SELECT id FROM cc_boards WHERE title = " . $pdo->quote(${JSON.stringify(TITLE)}))->fetchAll(PDO::FETCH_COLUMN) as $old) { $pdo->exec('DELETE FROM cc_cards WHERE board_id = ' . (int)$old); $pdo->exec('DELETE FROM cc_boards WHERE id = ' . (int)$old); }
  [$vl] = tests_cc_asset($pdo, file_get_contents(${JSON.stringify(LONG)}), ['name' => 'longo mix.webm', 'mime' => 'video/webm', 'kind' => 'video', 'w' => 160, 'h' => 120]);
  [$vs] = tests_cc_asset($pdo, file_get_contents(${JSON.stringify(SHORT)}), ['name' => 'curto mix.webm', 'mime' => 'video/webm', 'kind' => 'video', 'w' => 160, 'h' => 120]);
  [$a] = tests_cc_asset($pdo, file_get_contents(${JSON.stringify(MP3)}), ['name' => 'som mix.mp3', 'mime' => 'audio/mpeg', 'kind' => 'audio']);
  $pdo->exec("UPDATE cc_assets SET ai_status = 'done' WHERE id IN (" . (int)$vl['id'] . ',' . (int)$vs['id'] . ',' . (int)$a['id'] . ')');
  $b = cc_board_create($pdo, ['title' => ${JSON.stringify(TITLE)}, 'kind' => 'free'], 1);
  $add = fn(array $d) => cc_card_add($pdo, $b, $d, 1);
  $ids = ['n1' => $add(['type' => 'note', 'x' => 0, 'y' => 0, 'content' => ['text' => 'Início']])];
  $ids['col'] = $add(['type' => 'column', 'x' => 500, 'y' => 0, 'w' => 300, 'content' => ['title' => 'Referências']]);
  $ids['cv'] = $add(['type' => 'video', 'x' => 0, 'y' => 0, 'w' => 280, 'asset_id' => $vl['id'], 'column_id' => $ids['col'], 'ord' => 0]);
  $ids['ca'] = $add(['type' => 'audio', 'x' => 0, 'y' => 0, 'w' => 280, 'asset_id' => $a['id'], 'column_id' => $ids['col'], 'ord' => 1]);
  $ids['vs'] = $add(['type' => 'video', 'x' => 1000, 'y' => 0, 'w' => 280, 'asset_id' => $vs['id']]);
  $ids['as'] = $add(['type' => 'audio', 'x' => 1400, 'y' => 0, 'w' => 300, 'asset_id' => $a['id']]);
  cc_board_touch($pdo, $b);
  echo json_encode(['bid' => $b, 'ids' => $ids]);`));
const I = S.ids;

(async () => {
  const b = await chromium.launch();   // política de autoplay por omissão: o som só depois de um gesto na página
  const p = await b.newPage({ viewport: { width: 1600, height: 900 } });
  const errs = []; p.on('pageerror', e => errs.push(e.message));
  await p.addInitScript(() => { window.__played = []; const o = HTMLMediaElement.prototype.play; HTMLMediaElement.prototype.play = function () { if (window.__played.indexOf(this) < 0) window.__played.push(this); return o.apply(this, arguments); }; });
  // o que está a tocar: [{tag, card, muted, cls}]
  const playing = () => p.evaluate(() => window.__played.filter(m => !m.paused && !m.ended && m.isConnected !== undefined).filter(m => m.isConnected || m.tagName === 'AUDIO').map(m => { const c = m.closest && m.closest('.ccc'); return { tag: m.tagName, card: c ? +c.getAttribute('data-id') : null, muted: m.muted, cls: m.className }; }));
  const vidOf = id => p.evaluate(i => { const v = document.querySelector('.ccc[data-id="' + i + '"] video:not(.hov-prev)'); return v ? { paused: v.paused, ended: v.ended, muted: v.muted, t: v.currentTime, mix: v.hasAttribute('data-mix'), n: document.querySelectorAll('.ccc[data-id="' + i + '"] video').length } : null; }, id);
  const center = async (sel) => { const r = await p.evaluate(s => { const e = document.querySelector(s); return e ? e.getBoundingClientRect().toJSON() : null; }, sel); return r && { x: r.x + r.width / 2, y: r.y + r.height / 2 }; };
  await p.goto(BASE + '/admin/__as/1');
  await p.goto(BASE + '/admin/toppernote.php?b=' + S.bid);
  await p.waitForSelector('.ccc[data-id="' + I.vs + '"]', { timeout: 15000 });
  await p.waitForTimeout(600);

  // ── fora de Apresentar ──
  const vbtn = '.ccc[data-id="' + I.vs + '"] [data-cact="v-play"]';
  check(await p.locator(vbtn).count() === 1, 'o cartão de vídeo tem um ▶ verdadeiro (botão)');
  check(/Tocar ou pausar o vídeo/.test(await p.locator(vbtn).getAttribute('aria-label').catch(() => '') || ''), 'com o nome «Tocar ou pausar o vídeo»');
  let c = await center(vbtn);
  if (c) await p.mouse.click(c.x, c.y);
  await p.waitForTimeout(700);
  let v = await vidOf(I.vs);
  check(v && !v.paused && !v.muted, '▶ toca o vídeo com som no cartão (' + JSON.stringify(v) + ')');
  check(v && v.mix, 'o vídeo do cartão mistura com o leitor de áudio (data-mix)');
  await p.mouse.move(5, 880, { steps: 6 }); await p.waitForTimeout(700);
  v = await vidOf(I.vs);
  check(v && !v.paused, 'o cursor sair do cartão não pára o vídeo');
  c = await center('.ccc[data-id="' + I.as + '"] [data-cact="au-play"]');
  if (c) await p.mouse.click(c.x, c.y);
  await p.waitForTimeout(800);
  const both = await playing();
  check(both.some(m => m.tag === 'AUDIO') && both.some(m => m.tag === 'VIDEO' && m.card === I.vs), 'o MP3 toca junto com o vídeo (' + JSON.stringify(both) + ')');
  // a pré-visualização sem som noutro vídeo continua (ao passar o cursor), com o vídeo e o MP3 a tocar
  c = await center('.ccc[data-id="' + I.cv + '"] .ccc-media');
  if (c) { await p.mouse.move(c.x - 40, c.y - 30, { steps: 4 }); await p.waitForTimeout(900); }
  const prev = await p.evaluate(i => { const v = document.querySelector('.ccc[data-id="' + i + '"] video.hov-prev'); return v ? { muted: v.muted, paused: v.paused } : null; }, I.cv);
  check(prev && prev.muted && !prev.paused, 'a pré-visualização sem som ao passar o cursor continua (' + JSON.stringify(prev) + ')');
  await p.mouse.move(5, 880, { steps: 4 }); await p.waitForTimeout(300);
  // o vídeo curto (3 s) chega ao fim e fica parado no cartão
  await p.waitForFunction(i => { const v = document.querySelector('.ccc[data-id="' + i + '"] video.card-play'); return !v || v.ended || v.paused; }, I.vs, { timeout: 6000 }).catch(() => {});
  v = await vidOf(I.vs);
  check(v && (v.ended || v.paused) && v.n === 1, 'ao acabar fica parado no cartão (' + JSON.stringify(v) + ')');
  check(!(await p.evaluate(i => document.querySelector('.ccc[data-id="' + i + '"]').classList.contains('playing'), I.vs)), 'e o ▶ volta a aparecer (sem «playing»)');
  c = await center(vbtn);
  if (c) await p.mouse.click(c.x, c.y);
  await p.waitForTimeout(500);
  v = await vidOf(I.vs);
  check(v && !v.paused, '▶ depois do fim volta a tocar');
  c = await center(vbtn);
  if (c) await p.mouse.click(c.x, c.y);
  await p.waitForTimeout(400);
  v = await vidOf(I.vs);
  check(v && v.paused, '▶ outra vez pausa');
  // um redesenho do Topperboard (sondagem / tempo real) a meio não pára o vídeo do cartão nem cria outro
  // (o vídeo longo, dentro da coluna: o cartão é mesmo substituído — marca no elemento antigo)
  c = await center('.ccc[data-id="' + I.cv + '"] [data-cact="v-play"]');
  if (c) await p.mouse.click(c.x, c.y);
  await p.waitForTimeout(600);
  await p.evaluate(i => { const e = document.querySelector('.ccc[data-id="' + i + '"]'); e.__mark = 1; const v = e.querySelector('video.card-play'); if (v) v.__mark = 1; }, I.cv);
  php(`$pdo->prepare('UPDATE cc_cards SET updated_at = NOW() WHERE id = ?')->execute([${I.cv}]); cc_board_touch($pdo, ${S.bid});`);
  await p.waitForFunction(i => { const e = document.querySelector('.ccc[data-id="' + i + '"]'); return e && !e.__mark; }, I.cv, { timeout: 15000 }).catch(() => {});
  await p.waitForTimeout(500);
  const same = await p.evaluate(i => { const e = document.querySelector('.ccc[data-id="' + i + '"]'), vs = e ? e.querySelectorAll('video:not(.hov-prev)') : []; return { redrawn: !!e && !e.__mark, n: vs.length, mark: !!vs[0] && vs[0].__mark === 1, paused: vs[0] ? vs[0].paused : null, playingCls: !!e && e.classList.contains('playing') }; }, I.cv);
  check(same.redrawn && same.n === 1 && same.mark && same.paused === false && same.playingCls, 'o Topperboard redesenhado a meio: o mesmo vídeo continua a tocar no cartão novo (' + JSON.stringify(same) + ')');

  // ── Apresentar (com o vídeo da coluna e o MP3 ainda a tocar) ──
  await p.click('[data-a="present"]'); await p.waitForTimeout(1500);
  let pl = await playing();
  check(pl.length === 0, 'ao entrar em Apresentar o que tocava fora pára (' + JSON.stringify(pl) + ')');
  await p.keyboard.press('ArrowRight'); await p.waitForTimeout(1600);   // diapositivo 2: a coluna
  check((await p.locator('.cc-pres [data-pn]').textContent()) === '2 / 4', 'o diapositivo 2 é a coluna');
  c = await center('.ccc[data-id="' + I.cv + '"] .ccc-media');
  if (c) { await p.mouse.move(c.x - 50, c.y - 30, { steps: 5 }); await p.waitForTimeout(1000); }
  v = await vidOf(I.cv);
  check(v && !v.paused && !v.muted, 'em Apresentar, o cursor num vídeo dentro da coluna começa-o com som (' + JSON.stringify(v) + ')');
  const t0 = v ? v.t : 0;
  await p.mouse.move(5, 450, { steps: 5 }); await p.waitForTimeout(1300);
  v = await vidOf(I.cv);
  check(v && !v.paused && v.t > t0 + 0.8, 'o cursor sair não o pára: continua (' + JSON.stringify(v) + ')');
  c = await center('.ccc[data-id="' + I.cv + '"] .ccc-media');
  if (c) await p.mouse.click(c.x - 50, c.y - 30);
  await p.waitForTimeout(400);
  v = await vidOf(I.cv);
  check(v && v.paused, 'clicar no vídeo pausa');
  if (c) await p.mouse.click(c.x - 50, c.y - 30);
  await p.waitForTimeout(500);
  v = await vidOf(I.cv);
  check(v && !v.paused, 'clicar outra vez retoma');
  c = await center('.ccc[data-id="' + I.ca + '"] [data-cact="au-play"]');
  if (c) await p.mouse.click(c.x, c.y);
  await p.waitForTimeout(900);
  pl = await playing();
  check(pl.some(m => m.tag === 'AUDIO') && pl.some(m => m.tag === 'VIDEO' && m.card === I.cv), 'o ▶ do MP3 dentro da coluna toca, junto com o vídeo (' + JSON.stringify(pl) + ')');
  // o cursor fica parado em cima do vídeo da coluna: ao mudar de diapositivo o quadro desliza por baixo dele e passa
  // pelo MP3 e pelo vídeo — isso não é «passar o cursor» e nada pode começar
  await p.keyboard.press('ArrowRight'); await p.waitForTimeout(1800);   // diapositivo 3: o vídeo curto solto
  pl = await playing();
  check(!pl.some(m => m.card === I.cv) && !pl.some(m => m.tag === 'AUDIO' && !/pres-media/.test(m.cls)), 'mudar de diapositivo pára o vídeo e o MP3 da coluna (' + JSON.stringify(pl) + ')');
  check(pl.filter(m => m.tag === 'VIDEO').length === 1 && pl.some(m => m.card === I.vs), 'o vídeo do diapositivo toca sozinho ao chegar (' + JSON.stringify(pl) + ')');
  c = await center('.ccc[data-id="' + I.vs + '"] .ccc-media');
  if (c) { await p.mouse.move(c.x - 40, c.y - 30, { steps: 4 }); await p.waitForTimeout(700); }
  pl = await playing();
  check(pl.filter(m => m.tag === 'VIDEO').length <= 1, 'o cursor no vídeo do diapositivo não cria um 2.º som (' + JSON.stringify(pl) + ')');
  await p.keyboard.press('Escape'); await p.waitForTimeout(700);
  pl = await playing();
  check(pl.length === 0, 'sair de Apresentar pára tudo (' + JSON.stringify(pl) + ')');
  check(errs.length === 0, 'sem erros na página (' + errs.join(' | ') + ')');

  // ── iPad (toque, sem cursor): o ▶ do cartão é o caminho — 44 px no ecrã mesmo com o Topperboard inteiro à vista
  // (zoom pequeno); em Apresentar, tocar no vídeo da coluna começa-o e tocar outra vez pausa ──
  const { defaultBrowserType, ...ipad } = devices['iPad (gen 7)'];
  const tctx = await b.newContext(ipad);
  await tctx.addInitScript(() => { window.__played = []; const o = HTMLMediaElement.prototype.play; HTMLMediaElement.prototype.play = function () { if (window.__played.indexOf(this) < 0) window.__played.push(this); return o.apply(this, arguments); }; });
  const tp = await tctx.newPage();
  const terrs = []; tp.on('pageerror', e => terrs.push(e.message));
  const cdp = await tctx.newCDPSession(tp);
  await cdp.send('Emulation.setTouchEmulationEnabled', { enabled: true, maxTouchPoints: 5 });
  await tp.goto(BASE + '/admin/__as/1');
  await tp.goto(BASE + '/admin/toppernote.php?b=' + S.bid);
  await tp.waitForSelector(vbtn, { timeout: 15000 });
  await tp.waitForTimeout(900);
  const tvid = id => tp.evaluate(i => { const v = document.querySelector('.ccc[data-id="' + i + '"] video:not(.hov-prev)'); return v ? { paused: v.paused, muted: v.muted } : null; }, id);
  const tb = await tp.evaluate(s => { const e = document.querySelector(s), card = e && e.closest('.ccc'); return e ? { w: e.getBoundingClientRect().width, h: e.getBoundingClientRect().height, scale: card.getBoundingClientRect().width / card.offsetWidth } : null; }, vbtn);
  check(tb && tb.scale < 0.8, 'iPad: o Topperboard abre com zoom pequeno (prova do teste: ' + JSON.stringify(tb) + ')');
  check(tb && Math.round(tb.w) >= 44 && Math.round(tb.h) >= 44, 'iPad: o ▶ do cartão de vídeo tem 44 × 44 px no ecrã (' + JSON.stringify(tb) + ')');
  let tc = await tp.locator(vbtn).boundingBox();
  if (tc) await tp.touchscreen.tap(tc.x + tc.width / 2, tc.y + tc.height / 2);
  await tp.waitForTimeout(700);
  v = await tvid(I.vs);
  check(v && !v.paused && !v.muted, 'iPad: tocar no ▶ toca o vídeo com som (' + JSON.stringify(v) + ')');
  tc = await tp.locator(vbtn).boundingBox();
  if (tc) await tp.touchscreen.tap(tc.x + tc.width / 2, tc.y + tc.height / 2);
  await tp.waitForTimeout(400);
  v = await tvid(I.vs);
  check(v && v.paused, 'iPad: tocar outra vez no ▶ pausa (' + JSON.stringify(v) + ')');
  tc = await tp.locator('[data-a="present"]').boundingBox();
  if (tc) await tp.touchscreen.tap(tc.x + tc.width / 2, tc.y + tc.height / 2);
  await tp.waitForTimeout(1500);
  tc = await tp.locator('.cc-pres [data-p="1"]').boundingBox();
  if (tc) await tp.touchscreen.tap(tc.x + tc.width / 2, tc.y + tc.height / 2);
  await tp.waitForTimeout(1600);
  check((await tp.locator('.cc-pres [data-pn]').textContent()) === '2 / 4', 'iPad: o diapositivo 2 é a coluna');
  const tm = await tp.evaluate(i => { const r = document.querySelector('.ccc[data-id="' + i + '"] .ccc-media').getBoundingClientRect(); return { x: r.x + r.width * 0.2, y: r.y + r.height * 0.25 }; }, I.cv);
  await tp.touchscreen.tap(tm.x, tm.y);
  await tp.waitForTimeout(700);
  v = await tvid(I.cv);
  check(v && !v.paused && !v.muted, 'iPad, Apresentar: tocar no vídeo da coluna começa-o com som (' + JSON.stringify(v) + ')');
  await tp.touchscreen.tap(tm.x, tm.y);
  await tp.waitForTimeout(400);
  v = await tvid(I.cv);
  check(v && v.paused, 'iPad, Apresentar: tocar outra vez pausa (' + JSON.stringify(v) + ')');
  check(terrs.length === 0, 'iPad: sem erros na página (' + terrs.join(' | ') + ')');
  await tctx.close();

  await b.close();
  php(`$pdo->exec('DELETE FROM cc_cards WHERE board_id = ${S.bid}'); $pdo->exec('DELETE FROM cc_boards WHERE id = ${S.bid}');`);
  console.log(fail ? 'FALHOU ' + fail + ' de ' + (ok + fail) : 'OK ' + ok);
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
