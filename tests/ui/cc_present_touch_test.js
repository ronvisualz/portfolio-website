// Apresentar no toque (auditoria T7): no telemóvel a barra cabe no ecrã num diapositivo de áudio (‹, › e «Sair» à vista,
// alvos de 44 px) e um toque em «Sair» acaba Apresentar e cala o som; o ▶ do próprio cartão de áudio comanda o som da
// apresentação (nunca junta um 2.º som que fica a tocar depois de sair); «▶ Tocar» (som recusado pelo browser) e o botão
// «Apresentar» têm 44 px no ecrã com ponteiro grosso.
//   node tests/ui/cc_present_touch_test.js
const { chromium, devices } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path'), os = require('os'), fs = require('fs');
const BASE = 'http://127.0.0.1:8765';
const ROOT = path.join(__dirname, '..', '..');
let ok = 0, fail = 0;
const check = (c, msg) => { if (c) ok++; else { fail++; console.error('FALHA: ' + msg); } };
const BID = execFileSync('php', [path.join(__dirname, 'seed_board.php')]).toString().trim();
const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'ccpt-'));
const WEBM = path.join(dir, 'v.webm'), MP3 = path.join(dir, 'a.mp3');
execFileSync('ffmpeg', ['-hide_banner', '-loglevel', 'error', '-y', '-f', 'lavfi', '-i', 'testsrc=size=320x240:rate=15', '-f', 'lavfi', '-i', 'sine=frequency=440', '-t', '8', '-c:v', 'libvpx', '-b:v', '150k', '-c:a', 'libvorbis', WEBM]);
execFileSync('ffmpeg', ['-hide_banner', '-loglevel', 'error', '-y', '-f', 'lavfi', '-i', 'sine=frequency=330:duration=8', '-c:a', 'libmp3lame', '-b:a', '48k', MP3]);
// 7 diapositivos: nota, lista, amostra, imagem, sub-Topperboard, vídeo, áudio (o último)
execFileSync('php', ['-r', `require "tests/boot.php"; require_once "admin/inc/cc.php"; require "tests/fixtures.php";
  [$v] = tests_cc_asset($pdo, file_get_contents("${WEBM}"), ['name' => 'v.webm', 'mime' => 'video/webm', 'kind' => 'video', 'w' => 320, 'h' => 240]);
  [$a] = tests_cc_asset($pdo, file_get_contents("${MP3}"), ['name' => 'a.mp3', 'mime' => 'audio/mpeg', 'kind' => 'audio']);
  cc_card_add($pdo, ${BID}, ['type' => 'video', 'x' => 1100, 'y' => 420, 'w' => 280, 'asset_id' => $v['id']], 1);
  cc_card_add($pdo, ${BID}, ['type' => 'audio', 'x' => 1450, 'y' => 420, 'w' => 300, 'asset_id' => $a['id']], 1);`], { cwd: ROOT });
const pause = ms => new Promise(r => setTimeout(r, ms));

async function open(b, dev, refuse) {
  const { defaultBrowserType, ...opt } = devices[dev];
  const ctx = await b.newContext(opt);
  // todos os sons, também os que o leitor de áudio (AU) cria fora do DOM; com refuse, o browser recusa tocar com som
  await ctx.addInitScript(r => {
    window.__all = new Set();
    const o = HTMLMediaElement.prototype.play;
    HTMLMediaElement.prototype.play = function () { window.__all.add(this); if (r && !this.muted) return Promise.reject(new DOMException('recusado', 'NotAllowedError')); return o.call(this); };
  }, refuse);
  const p = await ctx.newPage();
  p.errs = []; p.on('pageerror', e => p.errs.push(String(e)));
  const cdp = await ctx.newCDPSession(p);
  await cdp.send('Emulation.setTouchEmulationEnabled', { enabled: true, maxTouchPoints: 5 });
  const send = (type, pts) => cdp.send('Input.dispatchTouchEvent', { type, touchPoints: pts.map((q, i) => ({ x: q[0], y: q[1], id: i, radiusX: 4, radiusY: 4, force: 1 })) });
  p.tapXY = async (x, y) => { await send('touchStart', [[x, y]]); await pause(60); await send('touchEnd', []); };
  p.tapEl = async loc => { const r = await loc.boundingBox(); await p.tapXY(r.x + r.width / 2, r.y + r.height / 2); return r; };
  p.playing = () => p.evaluate(() => [...window.__all].concat([...document.querySelectorAll('audio, video')]).filter((m, i, a) => a.indexOf(m) === i && !m.paused)
    .map(m => (m.classList.contains('pres-media') ? 'pres' : m.isConnected ? 'dom' : 'fora') + ':' + m.tagName));
  p.pn = () => p.evaluate(() => (document.querySelector('.cc-pres [data-pn]') || {}).textContent || null);
  await p.goto(BASE + '/admin/__as/1');
  await p.goto(BASE + '/admin/toppernote.php?b=' + BID);
  await p.waitForSelector('.ccc.t-audio'); await p.waitForTimeout(700);
  return { ctx, p };
}
async function toSlide(p, n) {
  for (let g = 0; g < 10 && (await p.pn()) !== n + ' / 7'; g++) { await p.tapEl(p.locator('.cc-pres [data-p="1"]')); await p.waitForTimeout(450); }
  await p.waitForTimeout(1200);
}

(async () => {
  const b = await chromium.launch({ args: ['--autoplay-policy=no-user-gesture-required'] });

  // ── telemóvel: a barra num diapositivo de áudio, «Sair» por toque ──
  let { ctx, p } = await open(b, 'iPhone 13', false);
  const pr = await p.locator('[data-a="present"]').boundingBox();
  check(pr && pr.width >= 44 && pr.height >= 44, 'telemóvel: o botão «Apresentar» tem 44 × 44 px (' + JSON.stringify(pr) + ')');
  await p.tapEl(p.locator('[data-a="present"]')); await p.waitForSelector('.cc-pres'); await p.waitForTimeout(700);
  await toSlide(p, 7);
  let pl = await p.playing();
  check((await p.pn()) === '7 / 7' && pl.length === 1 && pl[0] === 'pres:AUDIO', 'telemóvel: chega ao áudio (o último) e toca (prova do teste: ' + JSON.stringify(pl) + ')');
  const geo = await p.evaluate(() => {
    const bar = document.querySelector('.cc-pres'), r = bar.getBoundingClientRect();
    const ctl = [...bar.querySelectorAll('button, input')].filter(e => e.offsetParent).map(e => { const q = e.getBoundingClientRect(); return { t: e.getAttribute('data-p') || (e.hasAttribute('data-vol') ? 'vol' : e.tagName), x0: Math.round(q.left), x1: Math.round(q.right), w: Math.round(q.width), h: Math.round(q.height) }; });
    return { iw: innerWidth, ih: innerHeight, bar: [Math.round(r.left), Math.round(r.right), Math.round(r.top), Math.round(r.bottom)], ctl };
  });
  const out = geo.ctl.filter(c => c.x0 < 0 || c.x1 > geo.iw);
  check(out.length === 0 && geo.bar[0] >= 0 && geo.bar[1] <= geo.iw, 'telemóvel: a barra e todos os controlos ficam dentro do ecrã (' + JSON.stringify(geo) + ')');
  check(['-1', '1', 'x', 'play', 'mute', 'vol'].every(k => geo.ctl.some(c => c.t === k)), 'telemóvel: ‹, ›, «Sair», ▶, volume e «Sem som» à vista (' + geo.ctl.map(c => c.t).join(',') + ')');
  const small = geo.ctl.filter(c => c.h < 44 || (c.t !== 'vol' && c.w < 44));
  check(small.length === 0, 'telemóvel: os controlos da barra têm 44 px (' + JSON.stringify(small) + ')');
  // o ▶ do cartão de áudio comanda o som da apresentação
  const aup = p.locator('.ccc.t-audio .ccc-aup');
  await p.tapEl(aup); await p.waitForTimeout(800);
  pl = await p.playing();
  check(pl.length === 0, 'telemóvel: o ▶ do cartão em Apresentar pausa o som da apresentação, sem um 2.º som (' + JSON.stringify(pl) + ')');
  await p.tapEl(aup); await p.waitForTimeout(800);
  pl = await p.playing();
  check(pl.length === 1 && pl[0] === 'pres:AUDIO', 'telemóvel: outro toque no ▶ do cartão retoma o mesmo som (' + JSON.stringify(pl) + ')');
  const sair = await p.tapEl(p.locator('.cc-pres [data-p="x"]')); await p.waitForTimeout(700);
  pl = await p.playing();
  check(!(await p.locator('.cc-pres').count()) && !(await p.locator('.cc-boardview.present').count()), 'telemóvel: um toque em «Sair» acaba Apresentar (' + JSON.stringify(sair) + ')');
  check(pl.length === 0, 'telemóvel: e nada fica a tocar (' + JSON.stringify(pl) + ')');
  check(p.errs.length === 0, 'telemóvel: sem erros de JavaScript (' + p.errs.join(' | ') + ')');
  await ctx.close();

  // ── iPad: o ▶ e a forma de onda do cartão nunca juntam um 2.º som ──
  ({ ctx, p } = await open(b, 'iPad Pro 11 landscape', false));
  const pr2 = await p.locator('[data-a="present"]').boundingBox();
  check(pr2 && pr2.height >= 44, 'iPad: o botão «Apresentar» tem 44 px de altura (' + JSON.stringify(pr2) + ')');
  await p.tapEl(p.locator('[data-a="present"]')); await p.waitForSelector('.cc-pres'); await p.waitForTimeout(700);
  await toSlide(p, 7);
  let max = 0;
  const count = async () => { const n = (await p.playing()).length; max = Math.max(max, n); return n; };
  check((await count()) === 1, 'iPad: chega ao áudio e toca (prova do teste)');
  await p.tapEl(p.locator('.ccc.t-audio .ccc-aup')); await p.waitForTimeout(800); await count();
  await p.tapEl(p.locator('.ccc.t-audio .ccc-aup')); await p.waitForTimeout(800); await count();
  const wv = p.locator('.ccc.t-audio [data-cact="au-seek"]');
  if (await wv.count()) { await p.tapEl(wv); await p.waitForTimeout(800); await count(); }
  pl = await p.playing();
  check(max <= 1 && pl.length === 1 && pl[0] === 'pres:AUDIO', 'iPad: tocar no ▶ e na forma de onda do cartão em Apresentar nunca passa de 1 som (máximo ' + max + ', ' + JSON.stringify(pl) + ')');
  const btn = await p.evaluate(() => document.querySelector('.cc-pres [data-p="play"]').textContent);
  check(btn === '❚❚', 'iPad: a barra mostra ❚❚ com o som a tocar (' + btn + ')');
  await p.tapEl(p.locator('.cc-pres [data-p="x"]')); await p.waitForTimeout(700);
  pl = await p.playing();
  check(pl.length === 0, 'iPad: sair deixa 0 sons a tocar (' + JSON.stringify(pl) + ')');
  check(p.errs.length === 0, 'iPad: sem erros de JavaScript (' + p.errs.join(' | ') + ')');
  await ctx.close();

  // ── telemóvel com o som recusado: «▶ Tocar» com 44 px no ecrã ──
  ({ ctx, p } = await open(b, 'iPhone 13', true));
  await p.tapEl(p.locator('[data-a="present"]')); await p.waitForSelector('.cc-pres'); await p.waitForTimeout(700);
  for (const n of [6, 7]) {
    await toSlide(p, n);
    const t = await p.locator('.ccc .pres-play').boundingBox();
    check(t && Math.round(t.width) >= 44 && Math.round(t.height) >= 44, 'telemóvel, som recusado, diapositivo ' + n + ': «▶ Tocar» tem 44 × 44 px no ecrã (' + JSON.stringify(t) + ')');
  }
  await ctx.close();

  await b.close();
  fs.rmSync(dir, { recursive: true, force: true });
  console.log(fail ? `FALHOU ${fail} de ${ok + fail}` : `OK ${ok}`);
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
