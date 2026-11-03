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
async function page(b, uid, errs) {
  const ctx = await b.newContext({ viewport: { width: 1400, height: 900 } });
  const p = await ctx.newPage();
  p.setDefaultTimeout(6000);
  p.on('pageerror', e => errs.push('pageerror: ' + e.message));
  p.on('console', m => { if (m.type() === 'error' && !IGNORE.test(m.text() + ' ' + ((m.location() || {}).url || ''))) errs.push('console: ' + m.text()); });
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
  const p3 = await page(b, 3, errs3);
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

  await b.close();
  console.log(fail ? `FALHOU ${fail} de ${ok + fail}` : `OK ${ok}`);
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
