// «Juntar da Drive» com pré-visualização (§33.9): grelha como a da Drive — a miniatura da Drive quando existe, feita como
// na Drive quando falta (imagens e vídeos), ícone + extensão no resto; preguiçosa (só as que se veem); setas na grelha,
// Espaço escolhe sem refazer as imagens, Esc fecha, Tab fica dentro; quem não tem o Financeiro nunca pede miniaturas da
// Faturação; sem miniaturas (pedido recusado) ficam os ícones, sem erros.   node tests/ui/cc_drive_pick_thumbs_test.js
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');
const BASE = 'http://127.0.0.1:8765';
let ok = 0, fail = 0;
const check = (c, msg) => { if (c) ok++; else { fail++; console.error('FALHA: ' + msg); } };
const seed = (...a) => JSON.parse(execFileSync('php', [path.join(__dirname, 'seed_drive_pick.php'), ...a]).toString());
const php = code => execFileSync('php', ['-r', 'require "tests/boot.php"; require "tests/fixtures.php"; ' + code]).toString().trim();
const IGNORE = /favicon|\/fonts\/|fonts\.g|WebSocket|ws:\/\/|wss:\/\/|ERR_CONNECTION_REFUSED/i;   // a fonte da marca e o tempo real não existem na bancada

async function openPicker(p, term) {
  await p.locator('[data-upmenu]').click();
  await p.locator('.cc-upmenu button', { hasText: 'Da Drive' }).click();
  const dlg = p.locator('.cc-dpick');
  await dlg.waitFor({ timeout: 5000 });
  await p.waitForFunction(() => !document.querySelector('.cc-dpick .dp-list .spin'), null, { timeout: 10000 }).catch(() => {});
  if (term) { await dlg.locator('input[type=search]').fill(term); await p.waitForTimeout(400); }
  return dlg;
}
async function page(b, uid, errs, also) {
  const ctx = await b.newContext({ viewport: { width: 1400, height: 900 } });
  const p = await ctx.newPage();
  p.setDefaultTimeout(6000);
  p.on('pageerror', e => errs.push('pageerror: ' + e.message));
  p.on('console', m => { const t = m.text() + ' ' + ((m.location() || {}).url || ''); if (m.type() === 'error' && !IGNORE.test(t) && !(also && also.test(t))) errs.push('console: ' + m.text()); });
  await p.goto(BASE + '/admin/__as/' + uid);
  return p;
}
const spy = p => {
  const s = { etags: new Set(), keys: new Set() };
  p.on('request', rq => {
    if (!rq.url().includes('/admin/drive_api.php') || rq.method() !== 'POST') return;
    let j = {}; try { j = JSON.parse(rq.postData() || '{}'); } catch (e) { /* outro */ }
    if (j.do === 'thumb_urls') (j.etags || []).forEach(e => s.etags.add(e));
    if (j.do === 'urls') (j.keys || []).forEach(k => s.keys.add(k));
  });
  return s;
};

(async () => {
  const b = await chromium.launch();

  // ── 1) grelha com miniaturas (quem tem o Financeiro) ──
  const S = seed('dpt' + Date.now().toString(36));
  const T = S.tag, cell = (dlg, name) => dlg.locator('.dp-f', { hasText: T + '-' + name });
  const errs = [];
  const p = await page(b, 1, errs);
  await p.goto(BASE + '/admin/toppernote.php?b=' + S.b);
  await p.waitForSelector('.ccc[data-id]');
  const dlg = await openPicker(p, T);
  const n = await dlg.locator('.dp-f').count();
  check(n === (S.k[T + '-v.webm'] ? 7 : 6), 'a pesquisa mostra os ficheiros da pasta (' + n + ')');
  check(await dlg.locator('.dp-grid .dp-f').count() === n, 'os ficheiros estão numa grelha (.dp-grid)');
  // a: a miniatura que a Drive já tem (não o original)
  const aImg = cell(dlg, 'a.png').locator('.dp-th img');
  await aImg.waitFor({ timeout: 6000 }).catch(() => {});
  const aSrc = await aImg.getAttribute('src').catch(() => '');
  check(new RegExp('\\.topper/thumbs/' + S.e[T + '-a.png'] + '\\.jpg').test(aSrc || ''), 'imagem com miniatura na Drive → essa miniatura (' + (aSrc || 'sem img').slice(0, 90) + ')');
  await p.waitForFunction(el => el && el.complete && el.naturalWidth > 0, await aImg.elementHandle().catch(() => null), { timeout: 6000 }).catch(() => {});
  check(await aImg.evaluate(el => el.naturalWidth).catch(() => 0) === 320, 'a miniatura carrega (320 px de largura)');
  // b: sem miniatura → feita como na Drive (e gravada em .topper/thumbs)
  const bImg = cell(dlg, 'b.png').locator('.dp-th img');
  await bImg.waitFor({ timeout: 10000 }).catch(() => {});
  check(await bImg.count() === 1, 'imagem sem miniatura → a miniatura aparece (feita no browser como na Drive)');
  const eb = S.e[T + '-b.png'];
  let made = false;
  for (let i = 0; i < 20 && !made; i++) { made = php(`echo r2_head(DRIVE_THUMBS . "${eb}.jpg") ? 1 : 0;`) === '1'; if (!made) await p.waitForTimeout(250); }
  check(made, 'e fica gravada em .topper/thumbs/<etag>.jpg (a Drive passa a tê-la)');
  // vídeo: ▶ por cima da miniatura (WebM — o Chromium do Playwright não lê H.264)
  if (S.k[T + '-v.webm']) {
    const v = cell(dlg, 'v.webm');
    await v.locator('.dp-th img').waitFor({ timeout: 15000 }).catch(() => {});
    check(await v.locator('.dp-th img').count() === 1 && await v.locator('.dp-th .dp-play').count() === 1, 'vídeo → miniatura do fotograma com ▶');
  } else console.log('(sem ffmpeg: o vídeo não foi testado)');
  // pdf, mp3, txt: ícone do tipo + extensão, como na Drive
  for (const [nm, ext] of [['x.pdf', 'PDF'], ['s.mp3', 'MP3'], ['n.txt', 'TXT']]) {
    const c = cell(dlg, nm);
    check(await c.locator('.dp-th svg').count() === 1 && (await c.locator('.dp-th .dp-ext').innerText().catch(() => '')) === ext && await c.locator('img').count() === 0,
      nm + ' → ícone e «' + ext + '», sem imagem');
  }
  const icoBox = await cell(dlg, 'x.pdf').locator('.dp-th svg').boundingBox().catch(() => null);
  check(icoBox && icoBox.width >= 28 && icoBox.width <= 64, 'o ícone tem tamanho próprio sem o CSS da Drive (' + (icoBox && Math.round(icoBox.width)) + ' px)');
  const th = await cell(dlg, 'a.png').locator('.dp-th').boundingBox().catch(() => null);
  check(th && th.width >= 120 && th.height >= 80, 'a pré-visualização é grande como na grelha da Drive (' + (th && Math.round(th.width) + '×' + Math.round(th.height)) + ')');
  // teclado: Espaço escolhe sem refazer a imagem; setas andam na grelha
  await aImg.evaluate(el => { el.dataset.mk = '1'; }).catch(() => {});
  const ai = +(await cell(dlg, 'a.png').getAttribute('data-i'));
  await cell(dlg, 'a.png').focus();
  await p.keyboard.press(' ');
  check(await cell(dlg, 'a.png').getAttribute('aria-pressed') === 'true' && /Adicionar 1/.test(await dlg.locator('[data-dp-add]').innerText()), 'Espaço escolhe (aria-pressed e «Adicionar 1»)');
  check(await cell(dlg, 'a.png').locator('img[data-mk="1"]').count() === 1, 'escolher não refaz as imagens (a mesma img continua lá)');
  const focusI = () => p.evaluate(() => { const a = document.activeElement; return a && a.classList.contains('dp-f') ? +a.getAttribute('data-i') : -9; });
  const cols = await dlg.locator('.dp-f').evaluateAll(els => els.filter(e => e.offsetTop === els[0].offsetTop).length);
  check(cols >= 3, 'a grelha tem várias colunas a 1400 px (' + cols + ')');
  await dlg.locator('.dp-f[data-i="0"]').focus();
  await p.keyboard.press('ArrowRight');
  check(await focusI() === 1, '→ passa ao ficheiro seguinte');
  await p.keyboard.press('ArrowDown');
  check(await focusI() === Math.min(1 + cols, n - 1), '↓ desce uma linha (' + await focusI() + ')');
  await p.keyboard.press('ArrowUp');
  check(await focusI() === 1, '↑ volta à linha de cima');
  await p.keyboard.press('ArrowLeft'); await p.keyboard.press('ArrowLeft');
  check(await focusI() === 0, '← no primeiro fica');
  // foco ≠ escolha (WCAG 2.4.7 e 1.4.11), no claro e no escuro: o foco tem anel próprio, fora da célula e com 3:1 sobre o
  // diálogo; a escolha fica com o anel amarelo por dentro. Um escolhido com foco vê-se diferente de um escolhido sem foco.
  const lum = c => { const v = (c.match(/[\d.]+/g) || []).slice(0, 3).map(x => { x /= 255; return x <= 0.03928 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4); }); return 0.2126 * v[0] + 0.7152 * v[1] + 0.0722 * v[2]; };
  const ratio = (a, c) => { const x = lum(a), y = lum(c); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); };
  const look = loc => loc.evaluate(el => { const s = getComputedStyle(el); return { fv: el.matches(':focus-visible'), os: s.outlineStyle, ow: parseFloat(s.outlineWidth) || 0, oc: s.outlineColor,
    oo: parseFloat(s.outlineOffset) || 0, bs: s.boxShadow, bg: getComputedStyle(el.closest('.cc-dpick')).backgroundColor }; });
  const ring = l => l.os === 'none' || !l.ow ? 'sem anel' : l.ow + 'px ' + l.oc + ' a ' + l.oo + 'px';
  const shot = async loc => { const r = await loc.boundingBox(); return (await p.screenshot({ clip: { x: r.x - 6, y: r.y - 6, width: r.width + 12, height: r.height + 12 } })).toString('base64'); };
  const cmp = await (await b.newContext()).newPage();   // comparar píxeis numa página em branco (sem a CSP nem o estado do Topperboard)
  const diff = (a, c) => cmp.evaluate(async ([a, c]) => {
    const img = s => new Promise((ok, ko) => { const i = new Image(); i.onload = () => ok(i); i.onerror = ko; i.src = 'data:image/png;base64,' + s; });
    const px = i => { const cv = document.createElement('canvas'); cv.width = i.width; cv.height = i.height; const x = cv.getContext('2d'); x.drawImage(i, 0, 0); return x.getImageData(0, 0, i.width, i.height).data; };
    const A = px(await img(a)), C = px(await img(c));
    let n = 0; for (let i = 0; i < A.length; i += 4) if (Math.max(Math.abs(A[i] - C[i]), Math.abs(A[i + 1] - C[i + 1]), Math.abs(A[i + 2] - C[i + 2])) > 48) n++;
    return n;
  }, [a, c]);
  const xs = cell(dlg, 'x.pdf'), nt = cell(dlg, 'n.txt');
  await p.mouse.move(2, 2);   // sem hover por cima das células
  await xs.focus(); await p.keyboard.press(' ');
  check(await xs.getAttribute('aria-pressed') === 'true' && await nt.getAttribute('aria-pressed') === 'false', 'x.pdf escolhido, n.txt não');
  const theme0 = await p.evaluate(() => document.documentElement.getAttribute('data-theme'));
  for (const theme of ['light', 'dark']) {
    await p.evaluate(t => document.documentElement.setAttribute('data-theme', t), theme);
    await p.waitForTimeout(150);
    await xs.focus(); await p.waitForTimeout(60);
    const fs = await look(xs), shFs = await shot(xs);            // escolhido, com foco
    await nt.focus(); await p.waitForTimeout(60);
    const s = await look(xs), shS = await shot(xs);              // escolhido, sem foco
    const fn = await look(nt);                                   // com foco, não escolhido
    const sel = (s.bs.match(/rgba?\([^)]*\)/) || [''])[0];
    check(fs.fv && fn.fv, theme + ': o foco vem do teclado (:focus-visible)');
    check(fs.os !== 'none' && fs.ow >= 2 && fn.os !== 'none' && fn.ow >= 2, theme + ': a célula com foco tem anel de foco (' + ring(fs) + ' / ' + ring(fn) + ')');
    check(ring(fs) !== ring(s), theme + ': o anel de um escolhido com foco é diferente do de um escolhido sem foco (' + ring(fs) + ' vs ' + ring(s) + ')');
    check(fs.bs === s.bs && fs.bs !== fn.bs, theme + ': com foco, o escolhido continua a mostrar a escolha e o não escolhido não (' + fs.bs + ' / ' + fn.bs + ')');
    check(!!sel && fn.oc !== sel, theme + ': o foco não usa a cor da escolha (' + fn.oc + ' vs ' + sel + ')');
    check(fn.oo >= 0, theme + ': o anel do foco fica fora da célula, onde a escolha não desenha (' + fn.oo + ' px)');
    const cr = ratio(fn.oc, fn.bg);
    check(cr >= 3, theme + ': o anel do foco tem pelo menos 3:1 sobre o diálogo (' + cr.toFixed(2) + ':1, ' + fn.oc + ' sobre ' + fn.bg + ')');
    const dpx = await diff(shFs, shS);
    check(dpx > 200, theme + ': um escolhido com foco vê-se diferente de um escolhido sem foco (' + dpx + ' píxeis diferentes)');
  }
  // as pastas (linhas por cima da grelha) também: foco com 3:1 nos dois modos
  await dlg.locator('input[type=search]').fill(''); await p.waitForTimeout(400);
  for (const theme of ['light', 'dark']) {
    await p.evaluate(t => document.documentElement.setAttribute('data-theme', t), theme);
    await p.waitForTimeout(150);
    await dlg.locator('input[type=search]').focus();
    await dlg.locator('.dp-d').first().focus(); await p.waitForTimeout(60);
    const fd = await look(dlg.locator('.dp-d').first()), cd = ratio(fd.oc, fd.bg);
    check(fd.fv && fd.os !== 'none' && fd.ow >= 2 && cd >= 3, theme + ': a pasta com foco tem anel com pelo menos 3:1 (' + ring(fd) + ', ' + cd.toFixed(2) + ':1)');
  }
  await p.evaluate(t => t === null ? document.documentElement.removeAttribute('data-theme') : document.documentElement.setAttribute('data-theme', t), theme0);
  await cmp.context().close();
  // Tab fica dentro do diálogo
  let inside = true;
  for (let i = 0; i < n + 8; i++) { await p.keyboard.press('Tab'); inside = inside && await p.evaluate(() => !!document.activeElement.closest('.cc-dpick')); }
  check(inside, 'Tab fica dentro do diálogo');
  await p.keyboard.press('Escape'); await p.waitForTimeout(200);
  check(await p.locator('.cc-dpick').count() === 0, 'Esc fecha');
  check(ai >= 0, 'a célula tem data-i');
  check(errs.length === 0, 'sem erros de consola nem de CSP (' + errs.slice(0, 3).join(' | ') + ')');
  await p.context().close();

  // ── 2) preguiçosa: com 80 imagens, só se pedem as que se veem ──
  const M = seed('dpm' + Date.now().toString(36), 'many');
  const errs2 = [];
  const p2 = await page(b, 1, errs2);
  const s2 = spy(p2);
  await p2.goto(BASE + '/admin/toppernote.php?b=' + M.b);
  await p2.waitForSelector('.ccc[data-id]');
  const d2 = await openPicker(p2, M.tag + 'm-');
  check(await d2.locator('.dp-f').count() === 80, 'a pesquisa mostra as 80 imagens');
  await p2.waitForTimeout(2500);
  const asked = () => M.many.filter((k, i) => s2.keys.has(k) || s2.etags.has(M.me[i])).length;
  const before = asked();
  check(before > 0 && before < 60, 'antes de deslocar só se pedem as que se veem (' + before + ' de 80)');
  await d2.locator('.dp-list').evaluate(el => { el.scrollTop = el.scrollHeight; });
  await p2.waitForTimeout(2500);
  check(asked() > before, 'ao deslocar até ao fim pedem-se as outras (' + asked() + ')');
  check(errs2.length === 0, 'sem erros com muitas imagens (' + errs2.slice(0, 3).join(' | ') + ')');
  await p2.context().close();

  // ── 3) sem o Financeiro: a Faturação nunca aparece nem se pede; geração recusada → ícones, sem erros ──
  const F = seed('dpf' + Date.now().toString(36), 'bill');
  const errs3 = [];
  const p3 = await page(b, 3, errs3, /403 \(Forbidden\) .*drive_api\.php/);   // o registo do próprio browser para a recusa simulada abaixo
  const s3 = spy(p3);
  await p3.route('**/admin/drive_api.php', r => /"thumb_sign"/.test(r.request().postData() || '')
    ? r.fulfill({ status: 403, contentType: 'application/json', body: JSON.stringify({ ok: false, error: 'Recusado.' }) }) : r.continue());
  await p3.goto(BASE + '/admin/toppernote.php?b=' + F.b);
  await p3.waitForSelector('.ccc[data-id]');
  const d3 = await openPicker(p3, F.tag);
  await p3.waitForTimeout(3000);
  check(await d3.locator('.dp-f', { hasText: F.tag + '-fat' }).count() === 0, 'uid 3: o PNG da Faturação não aparece');
  check(!s3.etags.has(F.billEtag) && !s3.keys.has(F.bill), 'uid 3: a miniatura da Faturação nunca é pedida');
  check(await d3.locator('.dp-f', { hasText: F.tag + '-a.png' }).locator('.dp-th img').count() === 1, 'uid 3: vê a miniatura que a Drive já tem');
  const fb = d3.locator('.dp-f', { hasText: F.tag + '-b.png' });
  check(await fb.locator('.dp-th img').count() === 0 && await fb.locator('.dp-th svg').count() === 1 && (await fb.locator('.dp-ext').innerText().catch(() => '')) === 'PNG', 'uid 3, geração recusada: fica o ícone com «PNG»');
  check(errs3.length === 0, 'uid 3: sem erros (' + errs3.slice(0, 3).join(' | ') + ')');
  await p3.context().close();

  // ── 4) iPad: tocar escolhe e «Adicionar» junta (o caminho principal no toque); alvos de 44 px ──
  const I = seed('dpi' + Date.now().toString(36));
  const ip = await (await b.newContext({ viewport: { width: 1180, height: 820 }, hasTouch: true })).newPage();
  ip.setDefaultTimeout(6000);
  const errs4 = []; ip.on('pageerror', e => errs4.push(e.message));
  await ip.goto(BASE + '/admin/__as/1');
  await ip.goto(BASE + '/admin/toppernote.php?b=' + I.b);
  await ip.waitForSelector('.ccc[data-id]');
  const n4 = await ip.locator('.ccc[data-id]').count();
  await ip.locator('[data-upmenu]').tap();
  await ip.locator('.cc-upmenu button', { hasText: 'Da Drive' }).tap();
  const d4 = ip.locator('.cc-dpick'); await d4.waitFor();
  await ip.waitForFunction(() => !document.querySelector('.cc-dpick .dp-list .spin'), null, { timeout: 10000 }).catch(() => {});
  await d4.locator('input[type=search]').fill(I.tag); await ip.waitForTimeout(400);
  const c4 = d4.locator('.dp-f', { hasText: I.tag + '-c.png' });
  await c4.tap(); await ip.waitForTimeout(150);
  check(await c4.getAttribute('aria-pressed') === 'true', 'iPad: tocar escolhe');
  const add4 = d4.locator('[data-dp-add]');
  const sz = { cell: await c4.boundingBox(), add: await add4.boundingBox() };
  check(sz.cell.height >= 44 && sz.add.height >= 44 && sz.add.width >= 44, 'iPad: alvos de 44 px (' + Math.round(sz.cell.height) + ', ' + Math.round(sz.add.height) + ')');
  await add4.tap();
  for (let i = 0; i < 30 && await ip.locator('.ccc[data-id]').count() !== n4 + 1; i++) await ip.waitForTimeout(150);
  check(await ip.locator('.ccc[data-id]').count() === n4 + 1 && await ip.locator('.cc-dpick').count() === 0, 'iPad: «Adicionar» junta o escolhido e fecha');
  check(errs4.length === 0, 'iPad: sem erros (' + errs4.join(' | ') + ')');

  await b.close();
  console.log(fail ? `FALHOU ${fail} de ${ok + fail}` : `OK ${ok}`);
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
