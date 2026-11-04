// Apresentar só com o teclado (auditoria T7): ao entrar o foco vai para a barra (role=toolbar «Apresentar»); o contador e
// o título do diapositivo são anunciados (aria-live); as setas no deslizador do volume mexem no volume (não mudam de
// diapositivo); o Espaço num botão da barra carrega nesse botão («Sem som» alterna o som, «Sair» sai); mudar de
// diapositivo com o foco nos controlos de media escondidos leva o foco para «Seguinte»; ao sair (Esc ou «Sair») o foco
// volta ao botão «Apresentar»; o anel de foco do volume é o dos botões da barra (≥ 3:1 contra a barra escura).
//   node tests/ui/cc_present_keys_test.js
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path'), os = require('os'), fs = require('fs');
const BASE = 'http://127.0.0.1:8765';
const ROOT = path.join(__dirname, '..', '..');
let ok = 0, fail = 0;
const check = (c, msg) => { if (c) ok++; else { fail++; console.error('FALHA: ' + msg); } };
const BID = execFileSync('php', [path.join(__dirname, 'seed_board.php')]).toString().trim();
const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'ccpk-'));
const WEBM = path.join(dir, 'v.webm'), MP3 = path.join(dir, 'a.mp3');
execFileSync('ffmpeg', ['-hide_banner', '-loglevel', 'error', '-y', '-f', 'lavfi', '-i', 'testsrc=size=320x240:rate=15', '-f', 'lavfi', '-i', 'sine=frequency=440', '-t', '8', '-c:v', 'libvpx', '-b:v', '150k', '-c:a', 'libvorbis', WEBM]);
execFileSync('ffmpeg', ['-hide_banner', '-loglevel', 'error', '-y', '-f', 'lavfi', '-i', 'sine=frequency=330:duration=8', '-c:a', 'libmp3lame', '-b:a', '48k', MP3]);
// 7 diapositivos: nota, lista, amostra, imagem, sub-Topperboard, vídeo, áudio
execFileSync('php', ['-r', `require "tests/boot.php"; require_once "admin/inc/cc.php"; require "tests/fixtures.php";
  [$v] = tests_cc_asset($pdo, file_get_contents("${WEBM}"), ['name' => 'v.webm', 'mime' => 'video/webm', 'kind' => 'video', 'w' => 320, 'h' => 240]);
  [$a] = tests_cc_asset($pdo, file_get_contents("${MP3}"), ['name' => 'a.mp3', 'mime' => 'audio/mpeg', 'kind' => 'audio']);
  cc_card_add($pdo, ${BID}, ['type' => 'video', 'x' => 1100, 'y' => 420, 'w' => 280, 'asset_id' => $v['id']], 1);
  cc_card_add($pdo, ${BID}, ['type' => 'audio', 'x' => 1450, 'y' => 420, 'w' => 300, 'asset_id' => $a['id']], 1);`], { cwd: ROOT });

// contraste WCAG entre duas cores rgb()/rgba() (a de cima composta sobre a de baixo)
function parse(c) { const m = c.match(/[\d.]+/g).map(Number); return { r: m[0], g: m[1], b: m[2], a: m.length > 3 ? m[3] : 1 }; }
function over(top, bot) { return { r: top.r * top.a + bot.r * (1 - top.a), g: top.g * top.a + bot.g * (1 - top.a), b: top.b * top.a + bot.b * (1 - top.a), a: 1 }; }
function lum(c) { const f = v => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }; return 0.2126 * f(c.r) + 0.7152 * f(c.g) + 0.0722 * f(c.b); }
function ratio(a, b) { const x = lum(a), y = lum(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); }

(async () => {
  const b = await chromium.launch({ args: ['--autoplay-policy=no-user-gesture-required'] });
  const p = await b.newPage({ viewport: { width: 1400, height: 900 } });
  const errs = []; p.on('pageerror', e => errs.push(String(e)));
  await p.goto(BASE + '/admin/__as/1');
  await p.goto(BASE + '/admin/toppernote.php?b=' + BID);
  await p.evaluate(() => document.documentElement.setAttribute('data-theme', 'light'));
  await p.waitForSelector('.ccc.t-audio'); await p.waitForTimeout(500);
  const st = () => p.evaluate(() => {
    const bar = document.querySelector('.cc-pres'), ae = document.activeElement;
    const live = bar && [...bar.querySelectorAll('[aria-live]')].map(n => n.getAttribute('aria-live') + ':' + n.textContent.trim());
    return {
      present: !!document.querySelector('.cc-boardview.present'),
      pn: bar ? bar.querySelector('[data-pn]').textContent : null,
      pt: bar ? bar.querySelector('[data-pt]').textContent : null,
      media: bar ? !bar.querySelector('.pr-media').hidden : null,
      role: bar && bar.getAttribute('role'), label: bar && bar.getAttribute('aria-label'), live,
      focus: ae === document.body ? 'BODY' : ae.matches('.cc-pres') ? 'BAR' : (ae.getAttribute('data-p') ? 'p=' + ae.getAttribute('data-p') : ae.hasAttribute('data-vol') ? 'VOL' : ae.matches('[data-a="present"]') ? 'APRESENTAR' : ae.tagName + '.' + ae.className),
      vol: bar ? bar.querySelector('[data-vol]').value : null,
      mute: bar ? bar.querySelector('[data-p="mute"]').getAttribute('aria-pressed') : null,
      playing: [...document.querySelectorAll('video, audio')].filter(m => !m.paused).length,
    };
  });
  const key = async (k, ms) => { await p.keyboard.press(k); await p.waitForTimeout(ms || 350); };

  // ── entrar com Enter no botão «Apresentar» ──
  await p.locator('[data-a="present"]').focus();
  await key('Enter', 900);
  let s = await st();
  check(s.present && s.role === 'toolbar' && s.label === 'Apresentar', 'a barra é uma toolbar «Apresentar» (' + JSON.stringify({ role: s.role, label: s.label }) + ')');
  check(s.focus === 'BAR', 'ao entrar o foco fica na barra de Apresentar, não no corpo (' + s.focus + ')');
  const said = x => (x.live || []).filter(l => /^polite:/.test(l)).join(' | ');
  check(/1 de 7/.test(said(s)), 'o diapositivo é anunciado ao entrar (aria-live: ' + JSON.stringify(s.live) + ')');
  await key('ArrowRight');
  s = await st();
  check(s.pn === '2 / 7' && /2 de 7/.test(said(s)), 'a seta direita com o foco na barra avança e anuncia «2 de 7» (' + JSON.stringify({ pn: s.pn, live: s.live }) + ')');
  for (let i = 0; i < 4; i++) await key('ArrowRight', 300);
  await p.waitForTimeout(900);
  s = await st();
  check(s.pn === '6 / 7' && s.media && s.playing === 1, 'chega ao vídeo e toca (prova do teste: ' + JSON.stringify(s) + ')');
  check(said(s).indexOf(s.pt) >= 0 && /6 de 7/.test(said(s)), 'num vídeo o anúncio diz também o título (' + JSON.stringify({ pt: s.pt, live: s.live }) + ')');

  // ── deslizador do volume: as setas são do volume ──
  await p.locator('.cc-pres [data-p="mute"]').focus();
  await key('Shift+Tab', 200);
  s = await st();
  check(s.focus === 'VOL', 'Shift+Tab desde «Sem som» chega ao volume (prova do teste: ' + s.focus + ')');
  const ring = await p.evaluate(() => {
    const r = document.querySelector('.cc-pres [data-vol]'), cs = getComputedStyle(r);
    const btn = document.querySelector('.cc-pres [data-p="mute"]');
    // o anel dos botões da barra (a mesma regra :focus-visible), lido sem mexer no foco
    const sheetRing = (() => { for (const sh of document.styleSheets) { let rules; try { rules = sh.cssRules; } catch (e) { continue; } for (const ru of rules) if (ru.selectorText && ru.selectorText.indexOf('.cc-pres button:focus-visible') >= 0) return ru.style.outlineColor + ' ' + ru.style.outlineWidth + ' ' + ru.style.outlineOffset; } return null; })();
    return { fv: r.matches(':focus-visible'), style: cs.outlineStyle, w: cs.outlineWidth, off: cs.outlineOffset, color: cs.outlineColor,
      bar: getComputedStyle(document.querySelector('.cc-pres')).backgroundColor, board: getComputedStyle(document.querySelector('.cc-boardview')).backgroundColor, sheetRing, btn: !!btn };
  });
  const barOnBoard = over(parse(ring.bar), parse(ring.board));
  const rr = ratio(parse(ring.color), barOnBoard);
  check(ring.fv && ring.style === 'solid' && ring.w === '2px' && rr >= 3, 'o anel de foco do volume tem ≥ 3:1 contra a barra escura no claro (' + JSON.stringify(ring) + ' → ' + rr.toFixed(2) + ':1)');
  check(ring.color === 'rgb(255, 199, 24)' && ring.off === '2px', 'o volume usa o mesmo anel dos botões da barra (' + ring.color + ', ' + ring.off + ')');
  await key('ArrowLeft', 500);
  s = await st();
  check(s.pn === '6 / 7' && s.vol === '0.95' && s.focus === 'VOL' && s.media && s.playing === 1, 'ArrowLeft no volume baixa o volume e fica no mesmo diapositivo, com o vídeo a tocar (' + JSON.stringify(s) + ')');
  await key('ArrowRight', 500);
  s = await st();
  check(s.pn === '6 / 7' && s.vol === '1' && s.focus === 'VOL', 'ArrowRight no volume sobe o volume, sem avançar (' + JSON.stringify(s) + ')');
  await key('PageDown', 500);
  s = await st();
  check(s.pn === '6 / 7' && s.focus === 'VOL', 'PageDown no volume não muda de diapositivo (' + JSON.stringify(s) + ')');

  // ── Espaço em «Sem som»: alterna o som (não pausa) ──
  await p.locator('.cc-pres [data-p="mute"]').focus();
  await key('Space', 400);
  s = await st();
  check(s.mute === 'true' && s.playing === 1 && s.pn === '6 / 7', 'Espaço em «Sem som» liga o «Sem som» e o vídeo continua (' + JSON.stringify(s) + ')');
  await key('Space', 400);
  s = await st();
  check(s.mute === 'false' && s.playing === 1, 'Espaço outra vez desliga o «Sem som» (' + JSON.stringify(s) + ')');

  // ── mudar de diapositivo com o foco nos controlos de media: o foco não cai no corpo ──
  await key('ArrowLeft', 900);
  s = await st();
  check(s.pn === '5 / 7' && !s.media, 'a seta esquerda em «Sem som» recua para o sub-Topperboard (sem media) (' + JSON.stringify(s) + ')');
  check(s.focus === 'p=1', 'com os controlos de media escondidos, o foco passa para «Seguinte» (' + s.focus + ')');

  // ── Espaço em «Sair» num diapositivo sem media: sai (não avança) ──
  await p.locator('.cc-pres [data-p="x"]').focus();
  await key('Space', 700);
  s = await st();
  check(!s.present && s.pn === null, 'Espaço em «Sair» sai de Apresentar (' + JSON.stringify(s) + ')');
  check(s.focus === 'APRESENTAR', 'ao sair pelo «Sair» o foco volta ao botão «Apresentar» (' + s.focus + ')');
  check(s.playing === 0, 'e nada fica a tocar');

  // ── Esc: sai e devolve o foco ──
  await key('Enter', 900);
  s = await st();
  check(s.present && s.focus === 'BAR', 'Enter outra vez entra, com o foco na barra (' + s.focus + ')');
  await key('Escape', 700);
  s = await st();
  check(!s.present && s.focus === 'APRESENTAR', 'Esc sai e o foco volta ao botão «Apresentar» (' + JSON.stringify(s) + ')');

  // ── revisão final: as apresentações anteriores (saídas por «Sair» e Esc) não deixam o fullscreenchange delas ligado ──
  // numa 3.ª apresentação, sair do ecrã inteiro (Esc do browser ou o controlo dele) fecha ESTA apresentação: a barra
  // sai, nada toca e as setas voltam à tela (antes corria o stop() antigo, que parava o som novo e deixava a barra)
  await key('Enter', 900);
  for (let i = 0; i < 5; i++) await key('ArrowRight', 300);
  await p.waitForTimeout(900);
  s = await st();
  const fs3 = await p.evaluate(() => !!document.fullscreenElement);
  check(s.present && s.pn === '6 / 7' && s.playing === 1 && fs3, 'prova do teste: 3.ª apresentação no vídeo a tocar, em ecrã inteiro (' + JSON.stringify({ s, fs3 }) + ')');
  await p.evaluate(() => document.exitFullscreen());
  await p.waitForTimeout(700);
  s = await st();
  const bars = await p.evaluate(() => document.querySelectorAll('.cc-pres').length);
  check(bars === 0 && !s.present, 'sair do ecrã inteiro tira a barra e o modo Apresentar (' + JSON.stringify({ bars, present: s.present }) + ')');
  check(s.playing === 0, 'e nada fica a tocar (' + s.playing + ')');
  // sem nada escolhido, as setas/Page Down não são da apresentação (antes avançavam diapositivos e a vista saltava)
  await p.waitForTimeout(600);
  const view = () => p.evaluate(() => document.querySelector('.ccb-world').style.transform);
  const v0 = await view();
  await key('ArrowRight', 300); await key('PageDown', 900);
  const v1 = await view();
  s = await st();
  check(v1 === v0 && s.pn === null, 'sem nada escolhido, ArrowRight/Page Down não mudam de diapositivo nem mexem na vista (' + JSON.stringify({ v0, v1, pn: s.pn }) + ')');
  const noteX = () => p.evaluate(() => document.querySelector('.ccc.t-note').style.transform);
  await p.locator('.ccc.t-note').click(); await p.waitForTimeout(200);
  const nx0 = await noteX();
  await key('ArrowRight', 300);
  const nx1 = await noteX();
  check(nx1 !== nx0, 'as setas voltam à tela: ArrowRight move a nota escolhida (' + JSON.stringify({ nx0, nx1 }) + ')');

  // ── revisão final: «▶ Tocar» (o browser recusou o som) sai sem deixar o foco cair no corpo ──
  await p.evaluate(() => {
    const play = HTMLMediaElement.prototype.play;
    window.__deny = true;
    HTMLMediaElement.prototype.play = function () { return window.__deny ? Promise.reject(new DOMException('no', 'NotAllowedError')) : play.call(this); };
  });
  await p.locator('[data-a="present"]').focus();
  await key('Enter', 900);
  for (let i = 0; i < 5; i++) await key('ArrowRight', 300);
  await p.waitForTimeout(700);
  s = await st();
  const tocar = p.locator('.ccc.t-video .pres-play');
  check(s.pn === '6 / 7' && await tocar.isVisible().catch(() => false), 'prova do teste: no vídeo com o som recusado aparece «▶ Tocar» (' + JSON.stringify({ pn: s.pn }) + ')');
  await p.evaluate(() => { window.__deny = false; });
  await tocar.focus();
  await key('Enter', 700);
  s = await st();
  check(await p.locator('.pres-play').count() === 0 && s.playing === 1, 'Enter em «▶ Tocar» toca e o botão sai (' + JSON.stringify({ playing: s.playing }) + ')');
  check(s.focus === 'p=play', 'o foco passa para o ❚❚ da barra (não cai no corpo) (' + s.focus + ')');
  // mudar de diapositivo com o foco em «▶ Tocar»: o foco vai para «Seguinte»
  await p.evaluate(() => { window.__deny = true; });
  await key('ArrowLeft', 500); await key('ArrowRight', 900);
  s = await st();
  check(s.pn === '6 / 7' && await tocar.isVisible().catch(() => false), 'prova do teste: de volta ao vídeo, «▶ Tocar» outra vez (' + JSON.stringify({ pn: s.pn, focus: s.focus }) + ')');
  await tocar.focus();
  await key('ArrowRight', 900);
  s = await st();
  check(s.pn === '7 / 7', 'a seta direita com o foco em «▶ Tocar» avança (' + s.pn + ')');
  check(s.focus === 'p=1', 'o «▶ Tocar» sai com o diapositivo e o foco passa para «Seguinte» (não cai no corpo) (' + s.focus + ')');
  await key('Escape', 700);
  s = await st();
  check(!s.present && s.focus === 'APRESENTAR' && s.playing === 0, 'Esc sai, o foco volta a «Apresentar» e nada toca (' + JSON.stringify({ present: s.present, focus: s.focus, playing: s.playing }) + ')');

  check(errs.length === 0, 'sem erros de JavaScript (' + errs.join(' | ') + ')');
  await b.close();
  fs.rmSync(dir, { recursive: true, force: true });
  console.log(fail ? `FALHOU ${fail} de ${ok + fail}` : `OK ${ok}`);
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
