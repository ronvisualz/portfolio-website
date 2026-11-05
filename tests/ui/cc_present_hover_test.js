// Apresentar: o vídeo/áudio toca até ao fim (correções de 2 nov). Com os cartões longe uns dos outros (o cartão seguinte
// ainda não está desenhado quando se chega), a política de autoplay por omissão (o clique em «Apresentar» autoriza o som):
// ao chegar toca com som; o cursor não o pára nem cria pré-visualização (num vídeo vizinho começa-o com som: 5 nov);
// sobrevive ao redesenho da IA, à forma de onda
// calculada, ao recarregar da sondagem e a deslocar a vista; Espaço pausa sem saltar o diapositivo.
//   node tests/ui/cc_present_hover_test.js
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path'), os = require('os'), fs = require('fs');
const BASE = 'http://127.0.0.1:8765';
const ROOT = path.join(__dirname, '..', '..');
let ok = 0, fail = 0;
const check = (c, msg) => { if (c) ok++; else { fail++; console.error('FALHA: ' + msg); } };
const php = code => execFileSync('php', ['-r', 'require "tests/boot.php"; require_once "admin/inc/cc.php"; require_once "tests/fixtures.php"; ' + code], { cwd: ROOT }).toString().trim();

const TITLE = 'Teste · Apresentar longe';
const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'ccpresl-'));
const WEBM = path.join(dir, 'longe.webm'), WAV = path.join(dir, 'longe.wav');
// 60 s chegam para o teste todo sem o vídeo/áudio acabar sozinho
execFileSync('ffmpeg', ['-hide_banner', '-loglevel', 'error', '-y', '-f', 'lavfi', '-i', 'testsrc=size=160x120:rate=10', '-f', 'lavfi', '-i', 'sine=frequency=520', '-t', '60', '-c:v', 'libvpx', '-b:v', '40k', '-c:a', 'libvorbis', WEBM]);
execFileSync('ffmpeg', ['-hide_banner', '-loglevel', 'error', '-y', '-f', 'lavfi', '-i', 'sine=frequency=260', '-t', '60', '-ar', '8000', '-ac', '1', WAV]);
// nota (0,0) → vídeo (1600,0) → nota (3200,0) → áudio (4800,0); 2.ª linha: nota (0,900) e um vídeo logo ao lado (300,900)
const S = JSON.parse(php(`
  foreach ($pdo->query("SELECT id FROM cc_boards WHERE title = " . $pdo->quote(${JSON.stringify(TITLE)}))->fetchAll(PDO::FETCH_COLUMN) as $old) {
    $pdo->exec('DELETE FROM cc_cards WHERE board_id = ' . (int)$old); $pdo->exec('DELETE FROM cc_boards WHERE id = ' . (int)$old);
  }
  [$v] = tests_cc_asset($pdo, file_get_contents(${JSON.stringify(WEBM)}), ['name' => 'longe.webm', 'mime' => 'video/webm', 'kind' => 'video', 'w' => 160, 'h' => 120]);
  [$a] = tests_cc_asset($pdo, file_get_contents(${JSON.stringify(WAV)}), ['name' => 'longe.wav', 'mime' => 'audio/wav', 'kind' => 'audio']);
  $prev = $pdo->query('SELECT id, ai_status FROM cc_assets WHERE id IN (' . (int)$v['id'] . ',' . (int)$a['id'] . ')')->fetchAll(PDO::FETCH_KEY_PAIR);
  // em produção a IA fica a trabalhar nas referências novas (o Topperboard redesenha-as a cada volta); o áudio sem forma de onda
  $pdo->exec("UPDATE cc_assets SET ai_status = 'queued' WHERE id IN (" . (int)$v['id'] . ',' . (int)$a['id'] . ')');
  $pdo->exec('UPDATE cc_assets SET peaks = NULL WHERE id = ' . (int)$a['id']);
  $b = cc_board_create($pdo, ['title' => ${JSON.stringify(TITLE)}, 'kind' => 'free'], 1);
  $add = fn(array $d) => cc_card_add($pdo, $b, $d, 1);
  $ids = [
    'n1' => $add(['type' => 'note', 'x' => 0, 'y' => 0, 'content' => ['text' => 'Primeira nota']]),
    'v' => $add(['type' => 'video', 'x' => 1600, 'y' => 0, 'w' => 280, 'asset_id' => $v['id']]),
    'n2' => $add(['type' => 'note', 'x' => 3200, 'y' => 0, 'content' => ['text' => 'Nota do meio']]),
    'au' => $add(['type' => 'audio', 'x' => 4800, 'y' => 0, 'w' => 300, 'asset_id' => $a['id']]),
    'n3' => $add(['type' => 'note', 'x' => 0, 'y' => 900, 'content' => ['text' => 'Nota da 2.ª linha']]),
    'v2' => $add(['type' => 'video', 'x' => 300, 'y' => 900, 'w' => 280, 'asset_id' => $v['id']]),
  ];
  cc_board_touch($pdo, $b);
  echo json_encode(['bid' => $b, 'ids' => $ids, 'v' => (int)$v['id'], 'a' => (int)$a['id'], 'prev' => $prev]);`));
const touch = () => php(`cc_board_touch($pdo, ${S.bid});`);

(async () => {
  const b = await chromium.launch();   // sem --autoplay-policy: a política por omissão do browser
  const p = await b.newPage({ viewport: { width: 1400, height: 900 } });
  // a IA continua a trabalhar durante a apresentação (cada volta redesenha os cartões das referências «IA…»)
  let work = 0, boards = 0, metaSeen = 0, releaseMeta;
  const metaGate = new Promise(r => { releaseMeta = r; });
  await p.route(/\/admin\/cc_api\.php\?a=work\b/, r => { work++; r.fulfill({ contentType: 'application/json', body: JSON.stringify({ ok: true, done: 0, pending: 1 }) }); });
  // a forma de onda do áudio só chega quando o teste deixar (a meio da apresentação)
  await p.route(/\/admin\/cc_api\.php\?a=audio_meta\b/, async r => { metaSeen++; await metaGate; r.continue().catch(() => {}); });
  p.on('request', q => { if (/cc_api\.php\?a=board\b/.test(q.url())) boards++; });
  // guarda cada elemento a que se pediu play() (um elemento desligado do documento não aparece no querySelectorAll)
  await p.addInitScript(() => {
    window.__played = [];
    const o = HTMLMediaElement.prototype.play;
    HTMLMediaElement.prototype.play = function () { if (window.__played.indexOf(this) < 0) window.__played.push(this); return o.apply(this, arguments); };
  });
  await p.goto(BASE + '/admin/__as/1');
  await p.goto(BASE + '/admin/toppernote.php?b=' + S.bid);
  await p.waitForSelector('.ccc.t-video'); await p.waitForTimeout(800);
  const card = id => p.locator('.ccc[data-id="' + id + '"]');
  const st = () => p.evaluate(() => ({
    pn: (document.querySelector('.cc-pres [data-pn]') || {}).textContent,
    btn: (document.querySelector('.cc-pres [data-p="play"]') || {}).textContent,
    media: [...document.querySelectorAll('video, audio')].map(m => ({ tag: m.tagName, cls: m.className, paused: m.paused, muted: m.muted, t: +m.currentTime.toFixed(2), card: m.closest('.ccc') ? +m.closest('.ccc').getAttribute('data-id') : null })),
  }));
  const playing = async () => (await st()).media.filter(m => !m.paused);
  // o elemento guardado (window[k]) continua ligado, a tocar e com som (e dentro do cartão, se for vídeo)
  const alive = (k, cardId) => p.evaluate(([k, id]) => {
    const m = window[k];
    if (!m) return { none: true };
    const c = m.closest('.ccc');
    return { paused: m.paused, muted: m.muted, inDoc: m.isConnected, card: c ? +c.getAttribute('data-id') : null, ok: !m.paused && !m.muted && m.isConnected && (id ? !!c && +c.getAttribute('data-id') === id : true) };
  }, [k, cardId || 0]);
  const mark = id => p.evaluate(id => { const el = document.querySelector('.ccc[data-id="' + id + '"]'); if (el) el.__mk = 1; return !!el; }, id);
  const replaced = id => p.evaluate(id => { const el = document.querySelector('.ccc[data-id="' + id + '"]'); return !!el && !el.__mk; }, id);
  const until = async (fn, ms) => { const t = Date.now(); while (Date.now() - t < ms) { if (await fn()) return true; await p.waitForTimeout(200); } return false; };
  const centre = async id => { const bb = await card(id).boundingBox(); return bb ? { x: bb.x + bb.width / 2, y: bb.y + bb.height / 2 } : { x: 700, y: 450 }; };

  await p.mouse.move(5, 880);
  await p.locator('[data-a="present"]').click(); await p.waitForTimeout(600);
  check((await st()).pn === '1 / 6', 'Apresentar começa na primeira nota (' + (await st()).pn + ')');

  // ── vídeo longe da nota anterior (não estava desenhado) ──
  await p.keyboard.press('ArrowRight'); await p.waitForTimeout(1200);
  let s = await st();
  const pv = s.media.filter(m => m.tag === 'VIDEO');
  check(s.pn === '2 / 6' && pv.length === 1 && /pres-media/.test(pv[0].cls) && !pv[0].paused && !pv[0].muted && pv[0].card === S.ids.v && s.btn === '❚❚',
    'ao chegar a um vídeo longe do cartão anterior toca com som (' + JSON.stringify(s) + ')');
  await p.evaluate(() => { window.__pv = document.querySelector('video.pres-media'); });
  for (let k = 1; k <= 2; k++) {
    const c = await centre(S.ids.v);
    await p.mouse.move(c.x, c.y, { steps: 6 }); await p.waitForTimeout(500);
    s = await st();
    check(s.media.length === 1 && !(await p.evaluate(() => !!document.querySelector('video.hov-prev'))) && (await alive('__pv', S.ids.v)).ok,
      `passar o cursor (${k}) não cria pré-visualização nem tira o som (${JSON.stringify(s.media)})`);
    await p.mouse.move(5, 5, { steps: 6 }); await p.waitForTimeout(500);
    check((await alive('__pv', S.ids.v)).ok && (await st()).media.length === 1, `tirar o cursor (${k}) não pára o vídeo (${JSON.stringify(await alive('__pv', S.ids.v))})`);
  }
  // a IA a trabalhar redesenha o cartão (refreshAssets → rerender)
  await mark(S.ids.v);
  const aiRedrew = await until(() => replaced(S.ids.v), 6000);
  check(aiRedrew && work > 0, 'a IA a trabalhar redesenha o cartão do vídeo durante a apresentação (prova do teste; voltas: ' + work + ')');
  check((await alive('__pv', S.ids.v)).ok, 'o vídeo continua (o mesmo, com som, no cartão) quando a IA redesenha o cartão (' + JSON.stringify(await alive('__pv', S.ids.v)) + ')');
  // outra pessoa muda o Topperboard: a sondagem recarrega tudo (reload → renderAll)
  let b0 = boards;
  touch();
  const reloaded = await until(async () => boards > b0, 14000);
  await p.waitForTimeout(1000);
  check(reloaded, 'a sondagem recarregou o Topperboard (prova do teste)');
  check((await alive('__pv', S.ids.v)).ok && (await st()).pn === '2 / 6', 'o vídeo continua depois de a sondagem recarregar o Topperboard (' + JSON.stringify(await alive('__pv', S.ids.v)) + ')');
  // deslocar a vista para longe do cartão não o tira do documento
  await p.mouse.move(700, 450); await p.mouse.wheel(4000, 0); await p.waitForTimeout(700);
  check((await alive('__pv', S.ids.v)).ok, 'deslocar a vista para longe não pára o vídeo (' + JSON.stringify(await alive('__pv', S.ids.v)) + ')');
  await p.mouse.move(5, 5);
  // Espaço pausa/retoma sem mudar de diapositivo
  await p.keyboard.press('Space'); await p.waitForTimeout(400);
  s = await st();
  check(s.pn === '2 / 6' && (await playing()).length === 0 && s.btn === '▶', 'Espaço pausa o vídeo e não salta o diapositivo (' + JSON.stringify(s) + ')');
  await p.keyboard.press('Space'); await p.waitForTimeout(500);
  check((await st()).pn === '2 / 6' && (await alive('__pv', S.ids.v)).ok, 'Espaço retoma o mesmo vídeo');

  // ── áudio longe da nota anterior, com o › da barra ──
  await p.locator('.cc-pres [data-p="1"]').click(); await p.waitForTimeout(700);
  check((await st()).pn === '3 / 6' && (await playing()).length === 0, 'avançar para uma nota pára o vídeo');
  await p.locator('.cc-pres [data-p="1"]').click(); await p.waitForTimeout(1200);
  s = await st();
  const pa = s.media.filter(m => m.tag === 'AUDIO');
  check(s.pn === '4 / 6' && pa.length === 1 && /pres-media/.test(pa[0].cls) && !pa[0].paused && !pa[0].muted && s.btn === '❚❚',
    'ao chegar a um áudio longe do anterior ouve-se o som (' + JSON.stringify(s) + ')');
  await p.evaluate(() => { window.__pa = document.querySelector('audio.pres-media'); });
  // chega a forma de onda calculada pelo browser (auNeedPeaks → audio_meta → rerender do cartão)
  await mark(S.ids.au);
  releaseMeta();
  const waveRedrew = await until(() => replaced(S.ids.au), 6000);
  check(metaSeen > 0 && waveRedrew, 'a forma de onda calculada redesenha o cartão do áudio (prova do teste; pedidos: ' + metaSeen + ')');
  await p.waitForTimeout(500);
  check((await alive('__pa')).ok, 'o áudio continua quando chega a forma de onda (' + JSON.stringify(await alive('__pa')) + ')');
  b0 = boards;
  touch();
  check(await until(async () => boards > b0, 14000), 'a sondagem recarregou o Topperboard outra vez (prova do teste)');
  await p.waitForTimeout(1000);
  check((await alive('__pa')).ok && (await st()).pn === '4 / 6', 'o áudio continua depois de a sondagem recarregar o Topperboard (' + JSON.stringify(await alive('__pa')) + ')');

  // ── numa nota, o vídeo vizinho (à vista): o cursor começa-o com som (decidido a 5 nov), sem pré-visualização sem som ──
  await p.keyboard.press('ArrowRight'); await p.waitForTimeout(1000);
  check((await st()).pn === '5 / 6' && (await playing()).length === 0, 'avançar pára o áudio');
  const nb = await card(S.ids.v2).boundingBox();
  check(!!nb && nb.x < 1360, 'o vídeo vizinho está à vista ao lado da nota (prova do teste: ' + JSON.stringify(nb) + ')');
  if (nb) { await p.mouse.move(Math.min(1390, Math.max(nb.x, 0) + 20), nb.y + nb.height / 2, { steps: 6 }); await p.waitForTimeout(800); }
  const nm = (await st()).media;
  check(nm.length === 1 && nm[0].cls === 'card-play' && !nm[0].paused && !nm[0].muted && nm[0].card === S.ids.v2,
    'em Apresentar, passar o cursor por um vídeo vizinho começa-o com som, sem pré-visualização (' + JSON.stringify(nm) + ')');
  await p.mouse.move(5, 5);

  // ── vídeo perto do anterior (já desenhado) ──
  await p.keyboard.press('ArrowRight'); await p.waitForTimeout(1200);
  s = await st();
  const pv2 = s.media.filter(m => m.tag === 'VIDEO');
  check(s.pn === '6 / 6' && pv2.length === 1 && /pres-media/.test(pv2[0].cls) && !pv2[0].paused && !pv2[0].muted && pv2[0].card === S.ids.v2, 'um vídeo perto do anterior também toca com som (' + JSON.stringify(s) + ')');
  await p.evaluate(() => { window.__pv2 = document.querySelector('video.pres-media'); });
  const c2 = await centre(S.ids.v2);
  await p.mouse.move(c2.x, c2.y, { steps: 6 }); await p.waitForTimeout(400);
  await p.mouse.move(5, 5, { steps: 6 }); await p.waitForTimeout(500);
  check((await alive('__pv2', S.ids.v2)).ok, 'e o cursor não o pára (' + JSON.stringify(await alive('__pv2', S.ids.v2)) + ')');

  // ── fora de Apresentar: pré-visualização sem som, pára ao sair ──
  await p.keyboard.press('Escape'); await p.waitForTimeout(1000);
  check((await playing()).length === 0 && !(await p.evaluate(() => window.__played.some(m => !m.paused))), 'sair de Apresentar pára tudo');
  const cv = await centre(S.ids.v);
  await p.mouse.move(cv.x, cv.y, { steps: 6 }); await p.waitForTimeout(900);
  s = await st();
  const hv = s.media.filter(m => m.card === S.ids.v);
  check(hv.length === 1 && hv[0].muted && !hv[0].paused && !/pres-media/.test(hv[0].cls), 'fora de Apresentar passar o cursor pré-visualiza sem som (' + JSON.stringify(s.media) + ')');
  await p.mouse.move(5, 880, { steps: 4 }); await p.waitForTimeout(400);
  check((await st()).media.length === 0, 'fora de Apresentar a pré-visualização pára ao sair');
  // entrar em Apresentar (pelo teclado) com a pré-visualização a correr debaixo do cursor: pára logo, sem esperar pelo cursor
  const cv2 = await centre(S.ids.v);
  await p.mouse.move(cv2.x, cv2.y, { steps: 6 }); await p.waitForTimeout(900);
  const before = (await st()).media.filter(m => m.card === S.ids.v && m.muted && !m.paused).length;
  const left = await p.evaluate(() => { document.querySelector('[data-a="present"]').click(); return document.querySelectorAll('.ccc video:not(.pres-media)').length; });
  check(before === 1 && left === 0, 'ao entrar em Apresentar a pré-visualização que estava a correr pára (' + before + ' → ' + left + ')');
  await p.waitForTimeout(500); await p.keyboard.press('Escape'); await p.waitForTimeout(400);

  releaseMeta();
  await b.close();
  php(`$st = $pdo->prepare('UPDATE cc_assets SET ai_status = ? WHERE id = ?'); foreach (json_decode(${JSON.stringify(JSON.stringify(S.prev))}, true) as $id => $ai) $st->execute([$ai, $id]);`);
  fs.rmSync(dir, { recursive: true, force: true });
  console.log(fail ? `FALHOU ${fail} de ${ok + fail}` : `OK ${ok}`);
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
