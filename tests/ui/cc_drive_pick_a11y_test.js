// «Juntar da Drive» pelo teclado, para o leitor de ecrã e no toque (auditoria de acessibilidade, WCAG 2.4.3, 4.1.3):
// - fechar (Esc, «Cancelar», «Adicionar N») devolve o foco ao botão «Enviar» (antes ia para o corpo da página);
// - entrar numa pasta ou numa migalha põe o foco na 1.ª pasta/célula (antes o botão desaparecia e o foco caía no corpo);
// - uma região viva dentro do diálogo diz «A carregar a Drive…», «<pasta> · N itens», «N resultados», «Nada encontrado…»;
// - o ▶ só aparece por cima de uma miniatura (nunca por cima do ícone do vídeo);
// - a pesquisa tem borda com ≥ 3:1 (claro e escuro) e, no toque, 44 px de altura; «Drive» do caminho com 44 px;
// - no toque e a ≤ 640 px o rodapé é só «O cartão aponta para o ficheiro: não se faz cópia.».
//   node tests/ui/cc_drive_pick_a11y_test.js
const { chromium, devices } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');
const BASE = 'http://127.0.0.1:8765';
let ok = 0, fail = 0;
const check = (c, msg) => { if (c) ok++; else { fail++; console.error('FALHA: ' + msg); } };
const S = JSON.parse(execFileSync('php', [path.join(__dirname, 'seed_drive_pick.php'), 'dpa' + Date.now().toString(36)]).toString());
const T = S.tag, FOLDER = S.dir.replace(/\/$/, '').split('/').pop(), N = Object.keys(S.k).length;
const FOOT = 'O cartão aponta para o ficheiro: não se faz cópia.';
const lum = c => { const v = c.match(/[\d.]+/g).slice(0, 3).map(x => +x / 255).map(x => x <= 0.04045 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4)); return 0.2126 * v[0] + 0.7152 * v[1] + 0.0722 * v[2]; };
const ratio = (a, b) => { const x = lum(a), y = lum(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); };

(async () => {
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: 1400, height: 900 } });
  const p = await ctx.newPage();
  p.setDefaultTimeout(8000);
  const errs = []; p.on('pageerror', e => errs.push(e.message));
  await p.goto(BASE + '/admin/__as/1');
  await p.goto(BASE + '/admin/toppernote.php?b=' + S.b);
  await p.waitForSelector('.ccc[data-id]'); await p.waitForTimeout(300);
  const ae = () => p.evaluate(() => { const a = document.activeElement; if (!a || a === document.body) return 'BODY'; if (a.matches('[data-upmenu]')) return 'enviar'; if (a.matches('.dp-d')) return 'pasta:' + a.getAttribute('data-dp-go'); if (a.matches('.dp-f')) return 'celula:' + a.getAttribute('data-i'); if (a.matches('.dp-crumb button')) return 'migalha:' + a.getAttribute('data-dp-go'); return a.tagName + '.' + a.className; });
  const live = () => p.evaluate(() => { const d = document.querySelector('.cc-dpick'); return d ? [...d.querySelectorAll('[role=status],[aria-live]')].filter(e => !e.matches('.dp-drag-hint')).map(e => e.textContent).join(' | ') : null; });
  const loaded = () => p.waitForFunction(() => !document.querySelector('.cc-dpick .dp-list .spin'), null, { timeout: 10000 }).catch(() => {});
  // abrir só pelo teclado: Tab até «Enviar», Enter, ↓ «Da Drive», Enter
  async function openKb() {
    await p.locator('[data-upmenu]').focus();
    await p.keyboard.press('Enter'); await p.waitForTimeout(150);
    await p.keyboard.press('ArrowDown'); await p.keyboard.press('Enter');
    await p.locator('.cc-dpick').waitFor({ timeout: 5000 }).catch(() => {});
  }

  // 1) a carregar: anunciado dentro do diálogo; depois «Drive · N itens»
  await p.route('**/admin/drive_api.php', async rt => { if (/"catalog"/.test(rt.request().postData() || '')) await new Promise(r => setTimeout(r, 1200)); await rt.continue().catch(() => {}); });
  await openKb();
  await p.waitForTimeout(500);
  check(/A carregar a Drive…/.test(await live() || ''), 'a carregar: «A carregar a Drive…» numa região viva dentro do diálogo (' + await live() + ')');
  await loaded(); await p.unroute('**/admin/drive_api.php'); await p.waitForTimeout(150);
  check(/^Drive · \d+ ite(m|ns)$/.test(await live() || ''), 'na raiz: «Drive · N itens» (' + await live() + ')');
  // 2) Esc devolve o foco a «Enviar»
  await p.keyboard.press('Escape'); await p.waitForTimeout(200);
  check(await p.locator('.cc-dpick').count() === 0 && await ae() === 'enviar', 'Esc fecha e o foco volta a «Enviar» (' + await ae() + ')');

  // 3) entrar numa pasta pelo teclado: o foco vai para a 1.ª pasta ou célula e a pasta é anunciada
  await openKb(); await loaded();
  const top = S.dir.split('/')[0] + '/';
  await p.locator('.cc-dpick .dp-d[data-dp-go="' + top + '"]').focus().catch(() => {});
  await p.keyboard.press('Enter'); await p.waitForTimeout(250);
  let f = await ae();
  check(/^(pasta|celula):/.test(f), 'Enter numa pasta: o foco vai para a 1.ª pasta/célula (' + f + ')');
  check(new RegExp('^' + top.slice(0, -1) + ' · \\d+ ite(m|ns)$').test(await live() || ''), 'e diz «' + top.slice(0, -1) + ' · N itens» (' + await live() + ')');
  await p.locator('.cc-dpick .dp-d[data-dp-go="' + S.dir + '"]').focus().catch(() => {});
  await p.keyboard.press('Enter'); await p.waitForTimeout(250);
  f = await ae();
  check(f === 'celula:0', 'Enter na pasta dos ficheiros: o foco vai para a 1.ª célula (' + f + ')');
  check((await live() || '') === FOLDER + ' · ' + N + ' itens', 'e diz «' + FOLDER + ' · ' + N + ' itens» (' + await live() + ')');
  // uma migalha: o foco vai para a 1.ª pasta da pasta de cima
  await p.locator('.cc-dpick .dp-crumb button[data-dp-go="' + top + '"]').focus().catch(() => {});
  await p.keyboard.press('Enter'); await p.waitForTimeout(250);
  f = await ae();
  check(/^(pasta|celula):/.test(f), 'Enter numa migalha: o foco vai para a 1.ª pasta/célula (' + f + ')');
  // 4) pesquisa: «N resultados» e «Nada encontrado com «…».»
  await p.locator('.cc-dpick input[type=search]').fill(T); await p.waitForTimeout(400);
  check((await live() || '') === N + ' resultados', 'a pesquisa diz «' + N + ' resultados» (' + await live() + ')');
  await p.locator('.cc-dpick input[type=search]').fill(T + 'zzz'); await p.waitForTimeout(400);
  check((await live() || '') === 'Nada encontrado com «' + T + 'zzz».', 'sem resultados: «Nada encontrado com «…».» (' + await live() + ')');
  // 5) «Cancelar» (Enter) devolve o foco a «Enviar»
  await p.locator('.cc-dpick [data-dp-cancel]').focus(); await p.keyboard.press('Enter'); await p.waitForTimeout(200);
  check(await p.locator('.cc-dpick').count() === 0 && await ae() === 'enviar', '«Cancelar» fecha e o foco volta a «Enviar» (' + await ae() + ')');
  // 6) «Adicionar 1» (Enter) junta, fecha e devolve o foco a «Enviar»
  await openKb(); await loaded();
  await p.locator('.cc-dpick input[type=search]').fill(T); await p.waitForTimeout(400);
  await p.locator('.cc-dpick .dp-f', { hasText: T + '-n.txt' }).focus(); await p.keyboard.press(' ');
  await p.locator('.cc-dpick [data-dp-add]').focus(); await p.keyboard.press('Enter'); await p.waitForTimeout(800);
  check(await p.locator('.cc-dpick').count() === 0 && await ae() === 'enviar', '«Adicionar 1» fecha e o foco volta a «Enviar» (' + await ae() + ')');

  // 7) ▶ só por cima de uma miniatura: sem miniaturas (TD.thumbs desligado) o vídeo mostra só o ícone e a extensão
  if (S.k[T + '-v.webm']) {
    await p.evaluate(() => { window.__th = window.TD && TD.thumbs; if (window.TD) TD.thumbs = null; });
    await openKb(); await loaded();
    await p.locator('.cc-dpick input[type=search]').fill(T + '-v.webm'); await p.waitForTimeout(400);
    const pl = await p.evaluate(() => { const t = document.querySelector('.cc-dpick .dp-f .dp-th'), e = t && t.querySelector('.dp-play'); return t && { img: t.classList.contains('has-img'), play: !!e && getComputedStyle(e).display !== 'none' && e.getBoundingClientRect().width > 0 }; });
    check(pl && !pl.img && !pl.play, 'vídeo sem miniatura: sem ▶ por cima do ícone (' + JSON.stringify(pl) + ')');
    await p.keyboard.press('Escape');
    await p.evaluate(() => { if (window.TD) TD.thumbs = window.__th; });
    await openKb(); await loaded();
    await p.locator('.cc-dpick input[type=search]').fill(T + '-v.webm'); await p.waitForTimeout(400);
    await p.waitForSelector('.cc-dpick .dp-th.has-img', { timeout: 10000 }).catch(() => {});
    const pl2 = await p.evaluate(() => { const t = document.querySelector('.cc-dpick .dp-f .dp-th'), e = t && t.querySelector('.dp-play'); return t && { img: t.classList.contains('has-img'), play: !!e && getComputedStyle(e).display !== 'none' && e.getBoundingClientRect().width > 0 }; });
    check(pl2 && pl2.img && pl2.play, 'vídeo com miniatura: ▶ por cima da imagem (' + JSON.stringify(pl2) + ')');
    await p.keyboard.press('Escape');
  }

  // 8) a borda da pesquisa com ≥ 3:1 contra o campo e o diálogo, no claro e no escuro (sem foco)
  for (const th of ['light', 'dark']) {
    await p.evaluate(t => document.documentElement.setAttribute('data-theme', t), th);
    await openKb(); await loaded();
    await p.locator('.cc-dpick .dp-crumb button').first().focus(); await p.mouse.move(2, 2); await p.waitForTimeout(500);   // sem foco nem rato, depois da transição
    const c = await p.evaluate(() => { const i = document.querySelector('.cc-dpick input[type=search]'), d = document.querySelector('.cc-dpick'); return { b: getComputedStyle(i).borderTopColor, bg: getComputedStyle(i).backgroundColor, dlg: getComputedStyle(d).backgroundColor }; });
    const r1 = ratio(c.b, c.bg), r2 = ratio(c.b, c.dlg);
    check(r1 >= 3 && r2 >= 3, th + ': a borda da pesquisa tem ≥ 3:1 (' + r1.toFixed(2) + ' contra o campo, ' + r2.toFixed(2) + ' contra o diálogo)');
    await p.keyboard.press('Escape');
  }
  await p.evaluate(() => document.documentElement.removeAttribute('data-theme'));
  // no rato (desktop) o rodapé continua com as dicas de Shift/Ctrl e de arrastar
  await openKb(); await loaded();
  check(/Shift ou Ctrl para escolher vários/.test(await p.locator('.cc-dpick .dp-f-bar').innerText()), 'com rato o rodapé fala de Shift/Ctrl e de arrastar');
  await p.keyboard.press('Escape');
  check(!errs.length, 'sem erros na página (' + errs.join(' | ') + ')');
  await ctx.close();

  // 9) telemóvel (toque): pesquisa e «Drive» com 44 px; rodapé curto
  for (const [nm, dv] of [['iPhone 13', devices['iPhone 13']], ['iPad', devices['iPad Pro 11 landscape']]]) {
    const { defaultBrowserType, ...o } = dv;
    const tc = await b.newContext(o);
    const t = await tc.newPage();
    await t.goto(BASE + '/admin/__as/1');
    await t.goto(BASE + '/admin/toppernote.php?b=' + S.b);
    await t.waitForSelector('.ccc[data-id]'); await t.waitForTimeout(300);
    await t.locator('[data-upmenu]').tap(); await t.locator('.cc-upmenu button', { hasText: 'Da Drive' }).tap();
    await t.locator('.cc-dpick').waitFor({ timeout: 5000 }).catch(() => {});
    await t.waitForFunction(() => !document.querySelector('.cc-dpick .dp-list .spin'), null, { timeout: 10000 }).catch(() => {});
    const m = await t.evaluate(() => {
      const i = document.querySelector('.cc-dpick input[type=search]'), d = document.querySelector('.cc-dpick .dp-crumb button'), s = document.querySelector('.cc-dpick .dp-f-bar > span');
      return { h: i && i.getBoundingClientRect().height, dw: d && d.getBoundingClientRect().width, dh: d && d.getBoundingClientRect().height, foot: s && s.innerText.trim() };
    });
    check(m.h >= 44, nm + ': a pesquisa tem ≥ 44 px de altura (' + m.h + ')');
    check(m.dw >= 44 && m.dh >= 44, nm + ': «Drive» do caminho com 44 × 44 (' + m.dw + ' × ' + m.dh + ')');
    check(m.foot === FOOT, nm + ': o rodapé é só «' + FOOT + '» (' + JSON.stringify(m.foot) + ')');
    await tc.close();
  }
  // ≤ 640 px com rato: também o rodapé curto
  const nc = await b.newContext({ viewport: { width: 600, height: 800 } });
  const n = await nc.newPage();
  await n.goto(BASE + '/admin/__as/1');
  await n.goto(BASE + '/admin/toppernote.php?b=' + S.b);
  await n.waitForSelector('.ccc[data-id]'); await n.waitForTimeout(300);
  await n.locator('[data-upmenu]').click(); await n.locator('.cc-upmenu button', { hasText: 'Da Drive' }).click();
  await n.locator('.cc-dpick').waitFor({ timeout: 5000 }).catch(() => {});
  check((await n.locator('.cc-dpick .dp-f-bar > span').innerText().catch(() => '')).trim() === FOOT, '600 px: o rodapé é só a frase curta');
  await nc.close();

  await b.close();
  console.log(fail ? `FALHOU ${fail} de ${ok + fail}` : `OK ${ok}`);
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
