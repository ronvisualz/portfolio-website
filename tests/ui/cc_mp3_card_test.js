// MP3 com extensão .mpeg (o «WhatsApp Audio … .mpeg» de produção: ID3 + frames MPEG, que o browser dá como video/mpeg)
// vira cartão de áudio com forma de onda — enviado do dispositivo e «Da Drive»; um MP4 H.264+Opus que o browser não
// descodifica continua vídeo. O que já estava gravado como vídeo (trabalho media_sniff) passa a áudio no Topperboard
// aberto, mesmo a meio de Apresentar: o <video> da apresentação pára (não fica dentro do cartão de áudio) e ▶ toca o áudio.
//   node tests/ui/cc_mp3_card_test.js
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path'), os = require('os'), fs = require('fs');
const BASE = 'http://127.0.0.1:8765';
const ROOT = path.join(__dirname, '..', '..');
let ok = 0, fail = 0;
const check = (c, msg) => { if (c) ok++; else { fail++; console.error('FALHA: ' + msg); } };
const php = code => execFileSync('php', ['-r', 'require "tests/boot.php"; require_once "admin/inc/cc.php"; require_once "tests/fixtures.php"; ' + code], { cwd: ROOT }).toString().trim();

const TAG = 'mp3' + Date.now().toString(36);
const TITLE = 'Teste · MP3 .mpeg';
const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'ccmp3-'));
// MP3 como os do WhatsApp (o ffmpeg/Lavf escreve ID3v2.4: 49 44 33 04), únicos em cada corrida (a bancada reaproveita pela impressão digital)
const mp3 = (n, f, s) => { const o = path.join(dir, n); execFileSync('ffmpeg', ['-hide_banner', '-loglevel', 'error', '-y', '-f', 'lavfi', '-i', `sine=frequency=${f}:duration=${s}`, '-metadata', 'title=' + TAG + n, '-c:a', 'libmp3lame', '-b:a', '48k', '-id3v2_version', '4', '-f', 'mp3', o]); return o; };
const UP = mp3('up.mpeg', 330, 4), DRV = mp3('drive.mpeg', 550, 4), OLD = mp3('old.mpeg', 260, 40);
// vídeo H.264 com som Opus: o Chromium da bancada só descodifica o som (largura 0) — tem de continuar vídeo
const MP4 = path.join(dir, 'h264opus.mp4');
execFileSync('ffmpeg', ['-hide_banner', '-loglevel', 'error', '-y', '-f', 'lavfi', '-i', 'testsrc=size=320x240:rate=10', '-f', 'lavfi', '-i', 'sine=frequency=440', '-t', '3',
  '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-c:a', 'libopus', '-metadata', 'title=' + TAG, '-f', 'mp4', MP4]);
check(fs.readFileSync(UP).subarray(0, 4).toString('latin1') === 'ID3\x04', 'o MP3 de teste começa como o de produção (49 44 33 04)');

// o Topperboard (o de uma corrida anterior sai) e o caso de produção: um MP3 .mpeg gravado como vídeo, sem imagem, num cartão
// de vídeo, em cima (o 1.º diapositivo)
const OLDNAME = `WhatsApp Audio 2026-10-23 at 19.32.36 ${TAG}.mpeg`;
const S = JSON.parse(php(`
  foreach ($pdo->query("SELECT id FROM cc_boards WHERE title = " . $pdo->quote(${JSON.stringify(TITLE)}))->fetchAll(PDO::FETCH_COLUMN) as $old) {
    $pdo->exec('DELETE FROM cc_cards WHERE board_id = ' . (int)$old); $pdo->exec('DELETE FROM cc_boards WHERE id = ' . (int)$old);
  }
  $b = cc_board_create($pdo, ['title' => ${JSON.stringify(TITLE)}, 'kind' => 'free'], 1);
  $k = tests_put("Empresa/Teste33/${TAG}/" . ${JSON.stringify(OLDNAME)}, file_get_contents(${JSON.stringify(OLD)}), 'video/mpeg');
  [$a] = cc_asset_create($pdo, null, ['name' => ${JSON.stringify(OLDNAME)}, 'mime' => 'video/mpeg', 'kind' => 'video', 'file_key' => 'r2:' . $k, 'fp' => sha1('t|' . $k), 'by' => 1, 'no_ai' => 1, 'size' => filesize(${JSON.stringify(OLD)})]);
  $c = cc_card_add($pdo, $b, ['type' => 'video', 'asset_id' => (int)$a['id'], 'x' => 0, 'y' => -3000, 'w' => 280, 'h' => 174], 1);
  cc_card_add($pdo, $b, ['type' => 'note', 'x' => 0, 'y' => 0, 'content' => ['text' => 'MP3 .mpeg']], 1);
  cc_board_touch($pdo, $b);
  echo json_encode(['bid' => $b, 'old' => (int)$a['id'], 'oldCard' => $c]);`));
const DKEY = php(`echo tests_put("Empresa/Teste33/${TAG}/WhatsApp Audio ${TAG} drive.mpeg", file_get_contents(${JSON.stringify(DRV)}), 'video/mpeg');`);
const row = name => JSON.parse(php(`echo json_encode($pdo->query("SELECT c.id, c.type, c.h, a.kind, a.mime, LENGTH(COALESCE(a.peaks, '')) AS pk FROM cc_cards c JOIN cc_assets a ON a.id = c.asset_id WHERE c.board_id = ${S.bid} AND c.deleted_at IS NULL AND a.file_name = " . $pdo->quote(${JSON.stringify(name)}) . " ORDER BY c.id DESC LIMIT 1")->fetch() ?: null);`));

(async () => {
  const b = await chromium.launch();   // a política de autoplay por omissão: o clique em «Apresentar» autoriza o som
  const p = await b.newPage({ viewport: { width: 1400, height: 900 } });
  await p.addInitScript(() => {
    // o que o browser diz ao servidor em cada envio (os campos de texto do FormData: kind, picos…)
    window.__sent = [];
    const send = XMLHttpRequest.prototype.send;
    XMLHttpRequest.prototype.send = function (body) {
      if (body instanceof FormData) { const o = {}; for (const [k, v] of body.entries()) if (typeof v === 'string') o[k] = v; window.__sent.push(o); }
      return send.apply(this, arguments);
    };
    window.__played = [];
    const o = HTMLMediaElement.prototype.play;
    HTMLMediaElement.prototype.play = function () { if (window.__played.indexOf(this) < 0) window.__played.push(this); return o.apply(this, arguments); };
  });
  await p.goto(BASE + '/admin/__as/1');
  await p.goto(BASE + '/admin/toppernote.php?b=' + S.bid);
  await p.waitForSelector('.ccc[data-id]'); await p.waitForTimeout(600);
  const until = async (fn, ms) => { const t = Date.now(); while (Date.now() - t < ms) { if (await fn()) return true; await p.waitForTimeout(250); } return false; };
  const sentFor = async name => (await p.evaluate(() => window.__sent)).find(o => o.name === name) || {};
  const waveOf = id => p.evaluate(id => { const el = document.querySelector('.ccc[data-id="' + id + '"]'); return !!el && el.classList.contains('t-audio') && !!el.querySelector('.au-wave:not(.empty)'); }, id);
  check(await p.locator(`.ccc[data-id="${S.oldCard}"].t-video`).count() === 1, 'o caso de produção começa como cartão de vídeo sem imagem (prova do teste)');

  // ── do dispositivo: o browser dá video/mpeg ──
  const upName = `WhatsApp Audio ${TAG}.mpeg`;
  await p.locator('input[data-up]').setInputFiles({ name: upName, mimeType: 'video/mpeg', buffer: fs.readFileSync(UP) });
  await until(async () => !!row(upName) && !(await p.locator('.ccc.uploading').count()), 20000);
  let r = row(upName);
  check(!!r && r.kind === 'audio' && r.mime === 'audio/mpeg' && r.type === 'audio' && +r.h === 0, 'do dispositivo: referência áudio audio/mpeg e cartão de áudio h=0 (' + JSON.stringify(r) + ')');
  const up = await sentFor(upName);
  check(up.kind === 'audio' && (up.peaks || '').length > 100, 'o browser leu os bytes: envia como áudio, com a forma de onda (kind=' + up.kind + ', picos=' + (up.peaks || '').length + ')');
  check(!!r && +r.pk > 100, 'a forma de onda ficou guardada no envio');
  check(!!r && await until(() => waveOf(r.id), 5000), 'o cartão mostra a forma de onda');

  // ── um vídeo verdadeiro que o browser não descodifica (H.264+Opus) continua vídeo ──
  const vName = `clip ${TAG}.mp4`;
  await p.locator('input[data-up]').setInputFiles({ name: vName, mimeType: 'video/mp4', buffer: fs.readFileSync(MP4) });
  await until(async () => !!row(vName) && !(await p.locator('.ccc.uploading').count()), 25000);
  r = row(vName);
  check(!!r && r.kind === 'video' && r.type === 'video', 'H.264+Opus (sem imagem no browser) continua vídeo (' + JSON.stringify(r) + ')');
  check((await sentFor(vName)).kind === 'video', 'o browser envia-o como vídeo (' + (await sentFor(vName)).kind + ')');
  check(!!r && await p.locator(`.ccc[data-id="${r.id}"].t-video`).count() === 1, 'e o cartão é de vídeo');

  // ── «Da Drive» ──
  await p.locator('[data-upmenu]').click();
  await p.locator('.cc-upmenu button', { hasText: 'Da Drive' }).click();
  const dlg = p.locator('.cc-dpick');
  await dlg.waitFor({ timeout: 5000 }).catch(() => {});
  await dlg.locator('input[type=search]').fill(TAG + ' drive');
  await until(async () => (await dlg.locator('.dp-f').count()) === 1, 5000);
  await dlg.locator('.dp-f').first().click();
  await dlg.locator('button[data-dp-add]').click();
  const dName = `WhatsApp Audio ${TAG} drive.mpeg`;
  await until(() => !!row(dName), 8000);
  r = row(dName);
  check(!!r && r.kind === 'audio' && r.mime === 'audio/mpeg' && r.type === 'audio' && +r.h === 0, 'Da Drive: referência áudio audio/mpeg e cartão de áudio h=0 (' + JSON.stringify(r) + ')');
  check(!!r && await until(() => waveOf(r.id), 12000), 'Da Drive: o cartão ganha a forma de onda (calculada no browser)');

  // ── o que já estava gravado como vídeo, a meio de Apresentar (1.º diapositivo) ──
  await p.mouse.move(5, 880);
  await p.locator('[data-a="present"]').click(); await p.waitForTimeout(1500);
  const st = () => p.evaluate(() => ({
    pn: (document.querySelector('.cc-pres [data-pn]') || {}).textContent, btn: (document.querySelector('.cc-pres [data-p="play"]') || {}).textContent,
    media: [...document.querySelectorAll('video, audio')].map(m => ({ tag: m.tagName, cls: m.className, paused: m.paused, card: m.closest('.ccc') ? +m.closest('.ccc').getAttribute('data-id') : null })),
  }));
  let s = await st();
  check(/^1 \//.test(s.pn || '') && s.media.some(m => m.tag === 'VIDEO' && !m.paused && m.card === S.oldCard), 'Apresentar começa no cartão de vídeo do MP3 e toca-o (prova do teste: ' + JSON.stringify(s) + ')');
  await p.evaluate(() => { window.__pv = document.querySelector('video.pres-media'); });
  php(`echo cc_media_sniff_run($pdo, ${S.old}) ? 1 : 0;`);   // o trabalho media_sniff (fila da migração 42)
  const became = await until(async () => (await p.locator(`.ccc[data-id="${S.oldCard}"].t-audio`).count()) === 1, 16000);
  await p.waitForTimeout(500);
  check(became, 'o trabalho converte-o e o Topperboard aberto mostra-o como áudio (sondagem)');
  r = row(OLDNAME);
  check(!!r && r.kind === 'audio' && r.mime === 'audio/mpeg' && r.type === 'audio' && +r.h === 0, 'na base: áudio audio/mpeg, cartão de áudio h=0 (' + JSON.stringify(r) + ')');
  s = await st();
  const pv = await p.evaluate(() => { const m = window.__pv; return m ? { paused: m.paused, inDoc: m.isConnected } : null; });
  check(!s.media.some(m => m.tag === 'VIDEO') && !!pv && pv.paused && !pv.inDoc, 'o <video> da apresentação pára e sai (não fica dentro do cartão de áudio): ' + JSON.stringify({ media: s.media, pv }));
  check(/^1 \//.test(s.pn || '') && s.btn === '▶', 'continua no mesmo diapositivo, com ▶ na barra (' + JSON.stringify(s) + ')');
  await p.locator('.cc-pres [data-p="play"]').click(); await p.waitForTimeout(1200);
  s = await st();
  check(s.media.some(m => m.tag === 'AUDIO' && !m.paused) && !s.media.some(m => m.tag === 'VIDEO') && s.btn === '❚❚', '▶ toca-o agora como áudio (' + JSON.stringify(s) + ')');
  await p.keyboard.press('Escape'); await p.waitForTimeout(400);

  await b.close();
  fs.rmSync(dir, { recursive: true, force: true });
  console.log(fail ? `FALHOU ${fail} de ${ok + fail}` : `OK ${ok}`);
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
