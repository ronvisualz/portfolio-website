// Conta-gotas pelo teclado e no toque (auditoria de acessibilidade, WCAG 2.1.1, 2.4.3, 4.1.3):
// - o palco recebe o foco (Tab; preso no diálogo com os botões da barra) e tem uma mira: setas 1 píxel do original,
//   Shift+setas 10; Enter/Espaço escolhem (Shift+Enter continua); a lupa, o #HEX e a mensagem seguem a mira;
//   + − 0 aproximam à volta da mira;
// - os estados (a carregar, erro) são anunciados (dica numa região viva, ligada ao diálogo por aria-describedby);
// - aberto com I na tela, ao fechar o foco volta à tela; aberto pelo botão volta ao botão;
// - no iPad nada desliza nem aproxima por trás (touch-action: none no ecrã inteiro): nem a pinça nem o arrasto na barra;
// - iOS: o recurso play()/pause() do clone repõe o tempo pedido e espera pelo «seeked» (antes: 10 s e o erro).
//   node tests/ui/cc_eyedropper_keys_test.js
const { chromium, devices } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');
const BASE = 'http://127.0.0.1:8765';
let ok = 0, fail = 0;
const check = (c, msg) => { if (c) ok++; else { fail++; console.error('FALHA: ' + msg); } };
const php = code => execFileSync('php', ['-r', 'require "tests/boot.php"; ' + code]).toString().trim();
const SP = JSON.parse(execFileSync('php', [path.join(__dirname, 'seed_pick.php')]).toString().trim());
const manual = id => JSON.parse(php(`echo $pdo->query("SELECT palette FROM cc_assets WHERE id=${id}")->fetchColumn();`)).filter(c => c[5] && c[5].m).map(c => c[0]);
const near = (hex, want, tol = 4) => { const h = /^#([0-9A-F]{2})([0-9A-F]{2})([0-9A-F]{2})$/i.exec(hex || ''), w = /^#(..)(..)(..)$/.exec(want); return !!h && [1, 2, 3].every(i => Math.abs(parseInt(h[i], 16) - parseInt(w[i], 16)) <= tol); };
const soft = (pr, ms) => Promise.race([pr, new Promise(res => setTimeout(res, ms || 4000))]).catch(() => {});

(async () => {
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: 1400, height: 900 } });
  const p = await ctx.newPage();
  const errs = []; p.on('pageerror', e => errs.push(e.message));
  const ready = () => p.waitForFunction(() => /Clique para escolher|Toque e arraste/.test((document.querySelector('.cc-pickfs .pf-hint') || {}).textContent || ''), null, { timeout: 15000 }).catch(() => {});
  const img = () => p.evaluate(() => { const e = document.querySelector('.cc-pickfs .pf-img'); return e ? e.getBoundingClientRect().toJSON() : null; });
  const ae = () => p.evaluate(() => { const a = document.activeElement; return a ? (a.getAttribute('data-pf') ? 'pf:' + a.getAttribute('data-pf') : a.matches('.pf-stage') ? 'stage' : a.matches('.ccb-vp') ? 'vp' : a.matches('[data-pick]') ? 'pick' : a.tagName + '.' + a.className) : null; });
  // a mira: centro no ecrã (o .pf-cross tem 0 × 0), à vista; a barra e a lupa
  const state = () => p.evaluate(() => {
    const c = document.querySelector('.cc-pickfs .pf-cross'), l = document.querySelector('.cc-loupe');
    const cr = c && !c.hidden && getComputedStyle(c).display !== 'none' ? c.getBoundingClientRect() : null;
    const lr = l && !l.hidden ? l.getBoundingClientRect() : null;
    return { cross: cr && { x: cr.x, y: cr.y }, loupe: lr && { x: lr.x, y: lr.y, w: lr.width, h: lr.height },
      hex: (document.querySelector('[data-pf-hex]') || {}).textContent || '', msg: (document.querySelector('.cc-pickfs .pf-msg') || {}).textContent || '',
      zoom: (document.querySelector('.cc-pickfs output') || {}).textContent || '', open: !!document.querySelector('.cc-pickfs') };
  });
  const pxScreen = (r, x, y) => ({ x: r.x + (x + 0.5) * r.width / 2400, y: r.y + (y + 0.5) * r.height / 1600 });
  const press = async (k, n = 1) => { for (let i = 0; i < n; i++) await p.keyboard.press(k); await p.waitForTimeout(80); };

  await p.goto(BASE + '/admin/__as/1');
  await p.goto(BASE + '/admin/toppernote.php?b=' + SP.board);
  await p.waitForSelector('.ccc[data-id="' + SP.cards.image + '"]'); await p.waitForTimeout(300);
  await p.locator('.ccc[data-id="' + SP.cards.image + '"]').click(); await p.waitForTimeout(200);
  const before = await ae();

  // 1) abrir com I; a dica é região viva e descreve o diálogo; o palco é focável, com nome
  await p.keyboard.press('i');
  await p.waitForSelector('.cc-pickfs', { timeout: 8000 }).catch(() => {});
  await ready();
  const dlg = await p.evaluate(() => {
    const d = document.querySelector('.cc-pickfs'), h = d && d.querySelector('.pf-hint'), s = d && d.querySelector('.pf-stage');
    const db = d && d.getAttribute('aria-describedby');
    return d && { described: !!db && !!h && db.split(/\s+/).includes(h.id), hintLive: !!h && !!h.closest('[role=status],[aria-live=polite],[aria-live=assertive],[role=alert]'),
      tab: s && s.getAttribute('tabindex'), role: s && s.getAttribute('role'), label: s && s.getAttribute('aria-label') };
  });
  check(dlg && dlg.described, 'o diálogo é descrito pela dica (aria-describedby) (' + JSON.stringify(dlg) + ')');
  check(dlg && dlg.hintLive, 'a dica é uma região viva (estados e erros anunciados)');
  check(dlg && dlg.tab === '0' && dlg.role === 'application' && dlg.label === 'Imagem: use as setas para apontar e Enter para escolher', 'o palco é focável, com nome (' + JSON.stringify(dlg) + ')');

  // 2) Tab: o palco entra na volta presa ao diálogo (palco → Afastar → Aproximar → Concluir → palco)
  check(await ae() === 'pf:done', 'o foco começa em «Concluir»');
  await press('Tab');
  check(await ae() === 'stage', 'Tab de «Concluir» vai para o palco (' + await ae() + ')');
  await press('Tab');
  check(await ae() === 'pf:out', 'Tab do palco vai para «Afastar»');
  await press('Shift+Tab'); await press('Shift+Tab');
  check(await ae() === 'pf:done', 'Shift+Tab do palco volta a «Concluir» (preso no diálogo)');
  await press('Tab');

  // 3) a mira aparece no meio da imagem; a lupa e o #HEX seguem-na
  let r = await img(), s = await state();
  const c0 = r && pxScreen(r, 1200, 800);
  check(s.cross && c0 && Math.abs(s.cross.x - c0.x) < 1.5 && Math.abs(s.cross.y - c0.y) < 1.5, 'com o foco no palco a mira aparece no meio da imagem (' + JSON.stringify([s.cross, c0]) + ')');
  check(/^#[0-9A-F]{6}$/.test(s.hex) && !!s.loupe, 'e mostra a cor (#HEX e lupa) (' + JSON.stringify(s) + ')');
  await press('Shift+ArrowLeft');
  s = await state();
  const c1 = r && pxScreen(r, 1190, 800);
  check(s.cross && Math.abs(s.cross.x - c1.x) < 1.5, 'Shift+← anda 10 píxeis do original (' + JSON.stringify([s.cross, c1]) + ')');
  check(s.hex === '#DC2828', 'a cor da mira na metade vermelha (' + s.hex + ')');
  check(/#DC2828/.test(s.msg), 'a mensagem (região viva) diz a cor da mira (' + s.msg + ')');
  check(s.loupe && Math.abs(s.loupe.x - (s.cross.x + 22)) < 2 && Math.abs(s.loupe.y - (s.cross.y + 22)) < 2, 'a lupa segue a mira (' + JSON.stringify([s.loupe, s.cross]) + ')');
  await press('ArrowRight');
  const s2 = await state();
  check(s2.cross && Math.abs((s2.cross.x - s.cross.x) - r.width / 2400) < 0.2, '→ anda 1 píxel do original (' + (s2.cross && (s2.cross.x - s.cross.x).toFixed(3)) + ' ≈ ' + (r.width / 2400).toFixed(3) + ')');

  // 4) Shift+Enter acrescenta e fica
  const n0 = manual(SP.assets.image).length;
  await press('Shift+Enter'); await p.waitForTimeout(900);
  check(manual(SP.assets.image).includes('#DC2828') && manual(SP.assets.image).length === n0 + 1, 'Shift+Enter acrescenta a cor da mira à paleta (' + JSON.stringify(manual(SP.assets.image)) + ')');
  s = await state();
  check(s.open && /#DC2828 adicionada à paleta\./.test(s.msg) && await ae() === 'stage', 'com Shift fica aberto, com a confirmação, e o foco no palco (' + s.msg + ')');

  // 5) + − 0 com o foco no palco aproximam à volta da mira (a mira não sai do sítio)
  const sz = await state();
  await press('+'); await press('+');
  const sz2 = await state();
  check(parseInt(sz2.zoom, 10) > 100 && sz2.cross && Math.abs(sz2.cross.x - sz.cross.x) < 1.5 && Math.abs(sz2.cross.y - sz.cross.y) < 1.5, '+ aproxima à volta da mira (' + JSON.stringify([sz.cross, sz2.cross, sz2.zoom]) + ')');
  // com zoom a mira não sai do palco: a imagem desloca-se para a acompanhar
  await press('+', 4);
  await press('Shift+ArrowRight', 60);
  const st = await p.evaluate(() => document.querySelector('.cc-pickfs .pf-stage').getBoundingClientRect().toJSON());
  s = await state();
  check(s.cross && s.cross.x >= st.x && s.cross.x <= st.x + st.width && s.cross.y >= st.y && s.cross.y <= st.y + st.height, 'com zoom a mira fica sempre à vista no palco (' + JSON.stringify([s.cross, { x: st.x, w: st.width }]) + ')');
  check(s.hex === '#2F77B1', 'e lê a metade azul (' + s.hex + ')');
  await press('0');
  check((await state()).zoom === '100%', '0 volta ao ajustado');

  // 6) nas bordas a mira fica no primeiro píxel
  await press('Shift+ArrowLeft', 200);
  r = await img(); s = await state();
  const cL = pxScreen(r, 0, 800);
  check(s.cross && Math.abs(s.cross.x - cL.x) < 1.5 && s.hex === '#DC2828', 'na borda esquerda a mira fica no píxel 0 (' + JSON.stringify([s.cross, cL, s.hex]) + ')');
  await press('Shift+ArrowRight', 125);

  // 7) Espaço escolhe e sai; o foco volta à tela (abriu-se com I na tela)
  await press('Shift+ArrowRight', 2);
  const hb = (await state()).hex;
  await press(' '); await p.waitForTimeout(900);
  check(!(await state()).open && manual(SP.assets.image).includes('#2F77B1'), 'Espaço escolhe a cor da mira e sai (' + hb + ')');
  check(before === 'vp' && await ae() === 'vp', 'aberto com I na tela, o foco volta à tela (' + before + ' → ' + await ae() + ')');

  // 8) aberto pelo botão: Esc devolve o foco ao botão
  await soft(p.locator('.ccb-side [data-pick]').click({ timeout: 3000 })); await soft(p.waitForSelector('.cc-pickfs', { timeout: 4000 })); await ready();
  await press('Escape');
  check(await ae() === 'pick', 'aberto pelo botão, Esc devolve o foco ao botão (' + await ae() + ')');
  // I na tela, Esc: o foco volta à tela
  await p.locator('.ccb-vp').focus();
  await press('i'); await soft(p.waitForSelector('.cc-pickfs', { timeout: 4000 })); await ready();
  await press('Escape');
  check(await ae() === 'vp', 'I na tela → Esc: o foco volta à tela (' + await ae() + ')');

  // 9) erro: «Não foi possível ler a imagem.» na região viva
  await p.route(/cc_file\.php\?.*px=1/, rt => rt.abort());
  await soft(p.locator('.ccb-side [data-pick]').click({ timeout: 3000 })); await soft(p.waitForSelector('.cc-pickfs', { timeout: 4000 }));
  await p.waitForFunction(() => /Não foi possível/.test((document.querySelector('.cc-pickfs .pf-hint') || {}).textContent || ''), null, { timeout: 8000 }).catch(() => {});
  const er = await p.evaluate(() => { const h = document.querySelector('.cc-pickfs .pf-hint'); return h && { t: h.textContent, live: !!h.closest('[role=status],[aria-live=polite],[aria-live=assertive],[role=alert]') }; });
  check(er && er.t === 'Não foi possível ler a imagem.' && er.live, 'o erro fica numa região viva (' + JSON.stringify(er) + ')');
  await p.unroute(/cc_file\.php\?.*px=1/);
  await press('Escape');
  check(!errs.length, 'sem erros na página (' + errs.join(' | ') + ')');
  await ctx.close();

  // 10) iOS: o clone só tem fotograma depois de play(); a reprodução passa do tempo pedido antes do pause()
  const ictx = await b.newContext({ viewport: { width: 1400, height: 900 } });
  await ictx.addInitScript(() => {
    const RS = Object.getOwnPropertyDescriptor(HTMLMediaElement.prototype, 'readyState');
    Object.defineProperty(HTMLMediaElement.prototype, 'readyState', { configurable: true, get() { const v = RS.get.call(this); return this.crossOrigin === 'anonymous' && !this.__played ? Math.min(v, 1) : v; } });
    const PL = HTMLMediaElement.prototype.play;
    HTMLMediaElement.prototype.play = function () {
      if (this.crossOrigin !== 'anonymous') return PL.call(this);
      this.__played = true; window.__iosPlay = (window.__iosPlay || 0) + 1;
      return PL.call(this).then(() => new Promise(res => setTimeout(res, 400)));
    };
  });
  const v = await ictx.newPage();
  const verrs = []; v.on('pageerror', e => verrs.push(e.message));
  await v.goto(BASE + '/admin/__as/1');
  await v.goto(BASE + '/admin/toppernote.php?b=' + SP.board);
  await v.waitForSelector('.ccc[data-id="' + SP.cards.video + '"]'); await v.waitForTimeout(300);
  await v.locator('.ccc[data-id="' + SP.cards.video + '"]').click({ position: { x: 14, y: 14 } }); await v.waitForTimeout(200);   // no canto: o centro é o ▶
  // o detalhe do vídeo aberto, parado a meio (0,5 s); o conta-gotas lê o fotograma desse tempo
  await v.keyboard.press('i'); await soft(v.waitForSelector('.cc-pickfs', { timeout: 8000 })); await v.waitForTimeout(300);
  await v.keyboard.press('Escape'); await v.waitForTimeout(200);
  await v.waitForSelector('.ccb-side .ccd-media video', { timeout: 8000 }).catch(() => {});
  const at = await v.evaluate(() => new Promise(res => {
    const d = document.querySelector('.ccb-side .ccd-media video'); if (!d) return res(null);
    const go = () => { d.pause(); d.addEventListener('seeked', () => res(d.currentTime), { once: true }); d.currentTime = 0.5; };
    if (d.readyState >= 1) go(); else d.addEventListener('loadedmetadata', go, { once: true });
    setTimeout(() => res(d.currentTime), 5000);
  }));
  check(at != null && Math.abs(at - 0.5) < 0.05, 'iOS: o leitor do detalhe parado a 0,5 s (' + at + ')');
  await v.locator('.ccb-vp').focus();
  const t0 = Date.now();
  await v.keyboard.press('i'); await soft(v.waitForSelector('.cc-pickfs', { timeout: 8000 }));
  await v.waitForFunction(() => /Clique para escolher|Não foi possível/.test((document.querySelector('.cc-pickfs .pf-hint') || {}).textContent || ''), null, { timeout: 7000 }).catch(() => {});
  const vh = await v.evaluate(() => ({ t: (document.querySelector('.cc-pickfs .pf-hint') || {}).textContent || '', plays: window.__iosPlay || 0 }));
  check(vh.plays === 1, 'iOS: o recurso play() correu (' + vh.plays + ')');
  check(/Clique para escolher/.test(vh.t), 'iOS: depois do play()/pause() o fotograma lê-se em segundos (' + vh.t + ', ' + (Date.now() - t0) + ' ms)');
  const vr = await v.evaluate(() => { const e = document.querySelector('.cc-pickfs .pf-img'); return e ? e.getBoundingClientRect().toJSON() : null; });
  const hx = async (x, y) => { await v.mouse.move(x - 1, y); await v.mouse.move(x, y); await v.waitForTimeout(60); return v.evaluate(() => { const l = document.querySelector('.cc-loupe'); return l && !l.hidden ? (l.querySelector('.sw') || {}).textContent || '' : ''; }); };
  const vL = vr ? await hx(vr.x + vr.width * 0.25, vr.y + vr.height * 0.5) : '', vR = vr ? await hx(vr.x + vr.width * 0.75, vr.y + vr.height * 0.5) : '';
  check(near(vL, '#DC2828') && near(vR, '#2F77B1'), 'iOS: as cores do fotograma (' + vL + ', ' + vR + ')');
  check(!verrs.length, 'iOS: sem erros na página (' + verrs.join(' | ') + ')');
  await ictx.close();

  // 11) iPad (toque real por CDP), na Biblioteca: a pinça e o arrasto na barra não mexem na página por trás
  const { defaultBrowserType, ...dev } = devices['iPad Pro 11 landscape'];
  const tctx = await b.newContext(dev);
  const t = await tctx.newPage();
  const cdp = await tctx.newCDPSession(t);
  await cdp.send('Emulation.setTouchEmulationEnabled', { enabled: true, maxTouchPoints: 5 });
  const send = (type, pts) => cdp.send('Input.dispatchTouchEvent', { type, touchPoints: pts.map((q, i) => ({ x: q[0], y: q[1], id: i, radiusX: 4, radiusY: 4, force: 1 })) });
  const wait = ms => new Promise(res => setTimeout(res, ms));
  const tap = async (x, y) => { await send('touchStart', [[x, y]]); await wait(60); await send('touchEnd', []); };
  const drag = async (x0, y0, x1, y1) => { await send('touchStart', [[x0, y0]]); for (let i = 1; i <= 12; i++) { await wait(16); await send('touchMove', [[x0 + (x1 - x0) * i / 12, y0 + (y1 - y0) * i / 12]]); } await wait(30); await send('touchEnd', []); };
  const pinch = async (cx, cy, d0, d1) => { const pts = d => [[cx - d / 2, cy], [cx + d / 2, cy]]; await send('touchStart', pts(d0)); for (let i = 1; i <= 14; i++) { await wait(25); await send('touchMove', pts(d0 + (d1 - d0) * i / 14)); } await wait(40); await send('touchEnd', []); };
  await t.goto(BASE + '/admin/__as/1');
  await t.goto(BASE + '/admin/toppernote.php?t=biblioteca');
  await t.waitForSelector('[data-q]');
  // a página tem de poder deslocar-se (com a pesquisa a Biblioteca fica curta): o arrasto na barra é que não a pode mover
  await t.evaluate(() => document.body.insertAdjacentHTML('beforeend', '<div style="height:3000px" aria-hidden="true"></div>'));
  await t.locator('[data-q]').fill('pick33retrato');
  const tile = t.locator('.ccl-tile[data-id="' + SP.assets.portrait + '"]');
  await tile.waitFor({ timeout: 8000 }).catch(() => {});
  let tb = await tile.boundingBox().catch(() => null);
  if (tb) await tap(tb.x + tb.width / 2, tb.y + Math.min(tb.height / 2, 60));
  await t.waitForSelector('.ccd [data-pick]', { timeout: 8000 }).catch(() => {});
  await t.locator('.ccd [data-pick]').scrollIntoViewIfNeeded().catch(() => {});
  const pk = await t.locator('.ccd [data-pick]').boundingBox().catch(() => null);
  if (pk) await tap(pk.x + pk.width / 2, pk.y + pk.height / 2);
  await t.waitForSelector('.cc-pickfs', { timeout: 8000 }).catch(() => {});
  await t.waitForFunction(() => /Toque e arraste/.test((document.querySelector('.cc-pickfs .pf-hint') || {}).textContent || ''), null, { timeout: 15000 }).catch(() => {});
  await t.waitForTimeout(300);
  check(await t.evaluate(() => { const e = document.querySelector('.cc-pickfs'); return !!e && getComputedStyle(e).touchAction === 'none'; }), 'iPad: touch-action: none no ecrã inteiro do conta-gotas (barra incluída)');
  const scroll = () => t.evaluate(() => { let m = Math.round(scrollY); document.querySelectorAll('*').forEach(e => { if (e.scrollTop > m && !e.closest('.cc-pickfs')) m = Math.round(e.scrollTop); }); return m; });
  const tx = await t.evaluate(() => { const e = document.querySelector('.cc-pickfs .pf-txt'); return e ? e.getBoundingClientRect().toJSON() : null; });
  if (tx) {
    const s0 = await scroll();
    await drag(tx.x + tx.width / 2, tx.y + tx.height / 2, tx.x + tx.width / 2, tx.y + tx.height / 2 - 250); await t.waitForTimeout(600);
    const s1 = await scroll();
    check(s1 === s0, 'iPad: arrastar o dedo na barra não desloca a Biblioteca por trás (' + s0 + ' → ' + s1 + ')');
    await pinch(tx.x + tx.width / 2, tx.y + tx.height / 2, 40, Math.min(240, tx.width - 10)); await t.waitForTimeout(700);
    const vv = await t.evaluate(() => +visualViewport.scale.toFixed(2));
    check(vv === 1, 'iPad: uma pinça na barra não aproxima a página (' + vv + ')');
    check(await t.locator('.cc-pickfs').count() === 1, 'iPad: o conta-gotas continua aberto');
  } else check(false, 'iPad: o conta-gotas abriu na Biblioteca');
  await tctx.close();

  await b.close();
  console.log(fail ? `FALHOU ${fail} de ${ok + fail}` : `OK ${ok}`);
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
