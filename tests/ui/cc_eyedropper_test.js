// Conta-gotas e paleta (§33.11, §33.14): «+ Escolher da imagem» (I) abre a imagem — ou o fotograma em pausa do vídeo —
// em ecrã inteiro, com zoom (roda, + − 0) e uma amostra grande com o #HEX e o RGB; média 3×3 / Alt 1 px, Shift várias,
// Esc / «Concluir» / clique fora saem e devolvem o foco; as teclas do Topperboard ficam paradas; a paleta do painel vivo
// recebe as cores (mesmo que o painel tenha sido redesenhado por baixo); no iPad um toque acrescenta uma vez só.
// Depois: clicar numa cor copia; menu ⋯ (RGB, HSL, Amostra de cor, remover); «Copiar paleta».
//   node tests/ui/cc_eyedropper_test.js
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');
const BASE = 'http://127.0.0.1:8765';
let ok = 0, fail = 0;
const check = (c, msg) => { if (c) ok++; else { fail++; console.error('FALHA: ' + msg); } };
const php = code => execFileSync('php', ['-r', 'require "tests/boot.php"; ' + code]).toString().trim();
const BID = execFileSync('php', [path.join(__dirname, 'seed_board.php')]).toString().trim();
const SP = JSON.parse(execFileSync('php', [path.join(__dirname, 'seed_pick.php')]).toString().trim());
// imagem semeada: metade esquerda #DC2828 (220,40,40), direita #2F77B1; repõe a paleta (sem manuais)
const AID = php(`$a = (int)$pdo->query("SELECT asset_id FROM cc_cards WHERE board_id=${BID} AND type='image'")->fetchColumn(); $p = json_decode((string)$pdo->query("SELECT palette FROM cc_assets WHERE id=$a")->fetchColumn(), true) ?: []; $p = array_values(array_map(fn($c) => array_slice($c, 0, 5), array_filter($p, fn($c) => empty($c[5]['m'])))); $pdo->prepare("UPDATE cc_assets SET palette=? WHERE id=?")->execute([json_encode($p), $a]); echo $a;`);
const manual = (id = AID) => JSON.parse(php(`echo $pdo->query("SELECT palette FROM cc_assets WHERE id=${id}")->fetchColumn();`)).filter(c => c[5] && c[5].m).map(c => c[0]);
const near = (hex, want, tol = 4) => { const h = /^#([0-9A-F]{2})([0-9A-F]{2})([0-9A-F]{2})$/i.exec(hex || ''), w = /^#(..)(..)(..)$/.exec(want); return !!h && [1, 2, 3].every(i => Math.abs(parseInt(h[i], 16) - parseInt(w[i], 16)) <= tol); };

(async () => {
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: 1400, height: 900 }, permissions: ['clipboard-read', 'clipboard-write'] });
  const p = await ctx.newPage();
  const errs = []; p.on('pageerror', e => errs.push(e.message));
  const reqs = []; p.on('request', r => reqs.push(r.url()));
  // a imagem do conta-gotas (sem o ecrã inteiro, a superfície antiga do painel: o teste mede-a em vez de rebentar)
  const img = () => p.evaluate(() => { const e = document.querySelector('.cc-pickfs .pf-img') || document.querySelector('.ccd-media.picking img, .ccd-media.picking video') || document.querySelector('.ccd-media img, .ccd-media video'); return e ? e.getBoundingClientRect().toJSON() : null; });
  const soft = (pr, ms) => Promise.race([pr, new Promise(res => setTimeout(res, ms || 4000))]).catch(() => {});
  const hexAt = async (x, y) => {
    await p.mouse.move(x - 1, y); await p.mouse.move(x, y); await p.waitForTimeout(60);
    return p.evaluate(() => { const l = document.querySelector('.cc-loupe'); return l && !l.hidden ? (l.querySelector('.sw') || l.querySelector('b') || {}).textContent || '' : ''; });
  };
  const ready = () => p.waitForFunction(() => /Clique para escolher|Toque e arraste/.test((document.querySelector('.cc-pickfs .pf-hint, .ccd-pickhint') || {}).textContent || ''), null, { timeout: 15000 }).catch(() => {});
  const openPick = async () => { await soft(p.locator('.ccb-side [data-pick]').click({ timeout: 3000 })); await soft(p.waitForSelector('.cc-pickfs', { timeout: 4000 })); await ready(); };
  const focusPick = () => p.evaluate(() => !!(document.activeElement && document.activeElement.matches('[data-pick]') && document.activeElement.closest('.ccb-side, .ccd')));

  await p.goto(BASE + '/admin/__as/1');
  await p.goto(BASE + '/admin/toppernote.php?b=' + BID);
  await p.waitForSelector('.ccc.t-image'); await p.waitForTimeout(300);
  await p.locator('.ccc.t-image').click(); await p.waitForTimeout(200);

  // 1) I abre a imagem em ecrã inteiro (antes: a pré-visualização de 476×317 no painel)
  await p.keyboard.press('i');
  await p.waitForSelector('.cc-pickfs', { timeout: 8000 }).catch(() => {});
  const dlg = await p.evaluate(() => { const d = document.querySelector('.cc-pickfs'); return d && { role: d.getAttribute('role'), modal: d.getAttribute('aria-modal'), label: d.getAttribute('aria-label') }; });
  check(dlg && dlg.role === 'dialog' && dlg.modal === 'true' && dlg.label === 'Escolher uma cor da imagem', 'I abre o conta-gotas em ecrã inteiro (diálogo «Escolher uma cor da imagem») (' + JSON.stringify(dlg) + ')');
  // a entrada não encolhe o ecrã inteiro: no primeiro instante da animação já cobre as bordas (antes: escala .97 deixava
  // ~21 px à volta, e um clique rápido perto da borda caía no menu lateral ou no Topperboard por trás)
  const cover = await p.evaluate(() => {
    const d = document.querySelector('.cc-pickfs'); if (!d) return null;
    const an = d.getAnimations(); an.forEach(a => { a.pause(); a.currentTime = 0; });
    const r = d.getBoundingClientRect(), at = document.elementFromPoint(4, 450);
    const res = { x: Math.round(r.x), y: Math.round(r.y), w: Math.round(r.width), h: Math.round(r.height), at: at ? at.className || at.tagName : null, inside: !!(at && at.closest('.cc-pickfs')) };
    an.forEach(a => a.finish());
    return res;
  });
  check(cover && cover.inside && cover.x <= 0 && cover.y <= 0 && cover.w >= 1400 && cover.h >= 900, 'o ecrã inteiro cobre as bordas desde o primeiro instante da entrada (' + JSON.stringify(cover) + ')');
  await ready();
  let r = await img();
  check(r && (r.width >= 0.7 * 1400 || r.height >= 0.7 * 900), 'a imagem ocupa quase todo o ecrã (' + JSON.stringify(r && { w: Math.round(r.width), h: Math.round(r.height) }) + ')');
  check(await p.evaluate(() => document.activeElement && document.activeElement.getAttribute('data-pf') === 'done'), 'o foco vai para «Concluir»');
  // a dica (também a descrição do diálogo, aria-describedby) fala do rato e do teclado, numa linha onde cabe
  const hint1 = await p.locator('.cc-pickfs .pf-hint').textContent({ timeout: 3000 }).catch(() => '');
  check(hint1 === 'Clique para escolher · Alt: 1 píxel · Shift: várias · Roda: aproximar · Tab e setas: apontar com o teclado · Esc: sair', 'a dica com o rato e o teclado (' + hint1 + ')');
  const hintBox = await p.evaluate(() => { const h = document.querySelector('.cc-pickfs .pf-hint'), d = document.querySelector('.cc-pickfs'); return { h: h.getBoundingClientRect().height, fs: parseFloat(getComputedStyle(h).fontSize), by: d.getAttribute('aria-describedby') === h.id }; });
  check(hintBox.h < 2 * hintBox.fs && hintBox.by, 'a dica cabe numa linha a 1400 px e descreve o diálogo (' + JSON.stringify(hintBox) + ')');
  check(!/§/.test(await p.locator('.cc-pickfs').innerText({ timeout: 3000 }).catch(() => '')), 'sem «§» no conta-gotas');

  // 2) a cor em grande: retângulo na lupa e quadrado na barra, #HEX legível e RGB
  r = await img();
  const h25 = await hexAt(r.x + r.width * 0.25, r.y + r.height * 0.5);
  check(h25 === '#DC2828', 'a lupa mostra o #HEX do original (' + h25 + ')');
  const big = await p.evaluate(() => {
    const sw = document.querySelector('.cc-loupe .sw'), cv = document.querySelector('.cc-loupe canvas'), bs = document.querySelector('[data-pf-sw]'), hx = document.querySelector('[data-pf-hex]'), rgb = document.querySelector('[data-pf-rgb]');
    const R = e => e ? e.getBoundingClientRect() : { width: 0, height: 0 };
    return { sw: [R(sw).width, R(sw).height, sw ? parseFloat(getComputedStyle(sw).fontSize) : 0], cv: [R(cv).width, R(cv).height], bs: [R(bs).width, R(bs).height, bs ? getComputedStyle(bs).backgroundColor : ''],
             hx: [hx ? hx.textContent : '', hx ? parseFloat(getComputedStyle(hx).fontSize) : 0], rgb: rgb ? rgb.textContent : '' };
  });
  check(big.sw[0] >= 120 && big.sw[1] >= 48 && big.sw[2] >= 18, 'a lupa tem um retângulo grande da cor com o #HEX ≥ 18 px (' + JSON.stringify(big.sw) + ')');
  check(big.cv[0] >= 160 && big.cv[1] >= 160, 'a lupa é maior (≈168 px) (' + JSON.stringify(big.cv) + ')');
  check(big.bs[0] >= 48 && big.bs[1] >= 48 && big.bs[2] === 'rgb(220, 40, 40)', 'a barra mostra um quadrado grande com a cor (' + JSON.stringify(big.bs) + ')');
  check(big.hx[0] === '#DC2828' && big.hx[1] >= 18 && /rgb\(220, 40, 40\)/.test(big.rgb), 'e o #HEX ≥ 18 px com o RGB (' + JSON.stringify([big.hx, big.rgb]) + ')');

  // 3) Shift + clique acrescenta e fica
  await p.keyboard.down('Shift'); await p.mouse.click(r.x + r.width * 0.25, r.y + r.height * 0.5); await p.keyboard.up('Shift');
  await p.waitForTimeout(900);
  check(manual().includes('#DC2828'), 'Shift + clique acrescenta a cor à paleta (' + JSON.stringify(manual()) + ')');
  check(await p.locator('.cc-pickfs').count() === 1, 'com Shift fica no conta-gotas');
  check(/#DC2828 adicionada à paleta\./.test(await p.locator('.cc-pickfs .pf-msg').textContent({ timeout: 3000 }).catch(() => '')), 'a confirmação aparece no próprio conta-gotas');

  // 4) bordas: a primeira e a última coluna, a primeira linha (antes #000000 / #1F4F76)
  r = await img();
  const eL = await hexAt(r.x + 0.2, r.y + r.height * 0.5), eR = await hexAt(r.x + r.width - 0.2, r.y + r.height * 0.5), eT = await hexAt(r.x + r.width * 0.25, r.y + 0.2);
  check(eL === '#DC2828' && eT === '#DC2828', 'na borda esquerda e na de cima a cor é a da imagem (' + eL + ', ' + eT + ')');
  check(eR === '#2F77B1', 'na borda direita também (' + eR + ')');
  check(await hexAt(r.x - 8, r.y + r.height * 0.5) === '', 'fora da imagem não há lupa');

  // 5) zoom com a roda à volta do cursor; 0 volta ao ajustado; + e − pelo teclado
  const w0 = r.width, cx = r.x + r.width * 0.3, cy = r.y + r.height * 0.5;
  await p.mouse.move(cx, cy); await p.mouse.wheel(0, -500); await p.waitForTimeout(250);
  let rz = await img();
  check(rz && rz.width >= 2 * w0, 'a roda aproxima (' + Math.round(w0) + ' → ' + Math.round(rz && rz.width) + ')');
  const hz = await hexAt(cx, cy);
  check(hz === '#DC2828', 'com zoom a cor debaixo do cursor continua certa (' + hz + ')');
  // mais perto: um píxel do original com 2 ou mais píxeis no ecrã fica nítido (sem suavizar)
  await p.mouse.wheel(0, -300); await p.waitForTimeout(200);
  check(await p.evaluate(() => { const e = document.querySelector('.cc-pickfs .pf-img'); return !!e && e.classList.contains('px'); }), 'com muito zoom os píxeis ficam nítidos (sem suavizar)');
  const hz2 = await hexAt(cx, cy);
  check(hz2 === '#DC2828', 'e a cor debaixo do cursor continua certa (' + hz2 + ')');
  await p.keyboard.press('0'); await p.waitForTimeout(150);
  rz = await img();
  check(rz && Math.abs(rz.width - w0) < 2, '0 volta ao ajustado (' + Math.round(rz && rz.width) + ')');
  await p.keyboard.press('+'); await p.waitForTimeout(150);
  const zIn = await img(), zOut = await soft(p.locator('.cc-pickfs output').textContent({ timeout: 2000 })) || '';
  check(zIn && zIn.width > w0 * 1.1 && /\d+%/.test(zOut) && zOut !== '100%', '+ aproxima (' + zOut + ')');
  // arrastar com zoom desloca a imagem (e não escolhe)
  const nBefore = manual().length;
  if (zIn) { await p.mouse.move(zIn.x + zIn.width / 2, zIn.y + zIn.height / 2); await p.mouse.down(); await p.mouse.move(zIn.x + zIn.width / 2 + 60, zIn.y + zIn.height / 2 + 10, { steps: 6 }); await p.mouse.up(); }
  await p.waitForTimeout(500);
  const zPan = await img();
  check(zIn && zPan && Math.abs(zPan.x - zIn.x - 60) < 3, 'arrastar com zoom desloca a imagem (' + Math.round(zPan && zIn ? zPan.x - zIn.x : NaN) + ' px)');
  check(manual().length === nBefore && await p.locator('.cc-pickfs').count() === 1, 'arrastar não escolhe nem sai');
  await p.keyboard.press('-'); await p.keyboard.press('0'); await p.waitForTimeout(150);

  // 6) as teclas do Topperboard ficam paradas enquanto se escolhe
  const notes0 = await p.locator('.ccc.t-note').count();
  await p.keyboard.press('n'); await p.keyboard.press('Delete'); await p.keyboard.press('Control+z'); await p.waitForTimeout(600);
  check(await p.locator('.ccc.t-note').count() === notes0 && await p.locator('.ccc.t-image').count() === 1, 'N, Delete e Ctrl Z não mexem no Topperboard por trás');
  check(await p.locator('.cc-pickfs').count() === 1, 'e o conta-gotas continua aberto');

  // 7) o painel é redesenhado por baixo (outra pessoa mudou o Topperboard → sondagem → reload): as cores chegam ao painel vivo
  await p.evaluate(() => { window.__oldPal = document.querySelector('.ccb-side .ccd-palw'); });
  php(`require_once "admin/inc/cc.php"; cc_board_touch($pdo, ${BID});`);
  await p.waitForFunction(() => window.__oldPal && !window.__oldPal.isConnected && document.querySelector('.ccb-side .ccd-palw'), null, { timeout: 25000 }).catch(() => {});
  check(await p.evaluate(() => window.__oldPal && !window.__oldPal.isConnected), 'o painel foi redesenhado por baixo do conta-gotas (sondagem)');
  check(await p.locator('.cc-pickfs').count() === 1, 'o conta-gotas continua aberto depois de o painel ser redesenhado');
  // Alt + clique escolhe o píxel exato; sem Shift sai
  r = await img();
  if (r) { await p.mouse.move(r.x + r.width * 0.75, r.y + r.height * 0.5); await p.keyboard.down('Alt'); await p.mouse.click(r.x + r.width * 0.75, r.y + r.height * 0.5); await p.keyboard.up('Alt'); }
  await p.waitForTimeout(900);
  check(manual().includes('#2F77B1'), 'Alt + clique escolhe o píxel exato (#2F77B1)');
  check(await p.locator('.cc-pickfs').count() === 0 && await p.locator('.cc-loupe').count() === 0, 'sem Shift sai do conta-gotas');
  check(await p.locator('.ccb-side [data-pal-hex="#2F77B1"]').count() === 1 && await p.locator('.ccb-side [data-pal-hex="#DC2828"]').count() === 1, 'as duas cores aparecem na paleta do painel vivo');
  check(/#2F77B1 adicionada à paleta\./.test(await p.locator('.ccb-side .ccd-palmsg').textContent({ timeout: 3000 }).catch(() => '')), 'com a confirmação no painel');
  check(await p.locator('.ccb-side [data-tab="det"].on').count() === 1, 'o separador Detalhe continua aberto');
  // abriu-se com I na tela (passo 1): o foco volta à tela, não ao botão do painel (auditoria de acessibilidade)
  check(await p.evaluate(() => !!(document.activeElement && document.activeElement.matches('.ccb-vp'))), 'aberto com I na tela, o foco volta à tela');

  // 8) «Concluir», Esc e clique fora saem e devolvem o foco
  await openPick();
  await soft(p.locator('.cc-pickfs [data-pf="done"]').click({ timeout: 3000 })); await p.waitForTimeout(200);
  check(await p.locator('.cc-pickfs').count() === 0 && await focusPick(), '«Concluir» sai e devolve o foco');
  await openPick();
  await p.keyboard.press('Escape'); await p.waitForTimeout(200);
  check(await p.locator('.cc-pickfs').count() === 0 && await p.locator('.cc-loupe').count() === 0 && await focusPick(), 'Esc sai e devolve o foco');
  check(await p.locator('.ccc.t-image.sel').count() === 1, 'Esc no conta-gotas não tira a seleção do Topperboard');
  await openPick();
  // revisão final: carregar dentro da imagem e largar 1 px fora não escolhe nem sai (só sai o clique que começa E acaba fora)
  {
    const r8 = await img(), n8 = manual().length, adds8 = reqs.filter(u => /a=palette_add/.test(u)).length;
    if (r8) {
      const y8 = r8.y + r8.height * 0.5;
      await p.mouse.move(r8.x + 3, y8); await p.mouse.down(); await p.mouse.move(r8.x + 1, y8); await p.mouse.move(r8.x - 1, y8); await p.mouse.up();
    }
    await p.waitForTimeout(800);
    check(r8 && await p.locator('.cc-pickfs').count() === 1, 'rato: carregar dentro da imagem e largar 1 px fora não fecha o conta-gotas');
    check(manual().length === n8 && reqs.filter(u => /a=palette_add/.test(u)).length === adds8, 'rato: e não acrescenta nenhuma cor');
  }
  const fsOpen = await p.locator('.cc-pickfs').count() === 1;
  if (fsOpen) { await p.mouse.click(8, 300); await p.waitForTimeout(200); }
  check(fsOpen && await p.locator('.cc-pickfs').count() === 0, 'clicar fora da imagem sai');
  if (await p.locator('.cc-pickfs').count()) { await p.keyboard.press('Escape'); await p.waitForTimeout(150); }
  check(await p.locator('.ccc.t-image.sel').count() === 1, 'clicar fora também não mexe na seleção do Topperboard');
  await soft(p.waitForSelector('.ccb-side [data-pal-hex="#DC2828"]', { timeout: 4000 }));

  // clicar numa cor copia o #HEX
  await soft(p.locator('.ccb-side [data-pal-hex="#DC2828"]').click({ timeout: 4000 })); await p.waitForTimeout(300);
  check((await p.evaluate(() => navigator.clipboard.readText())) === '#DC2828', 'clicar numa cor copia o #HEX');
  // menu ⋯
  await soft(p.locator('.ccb-side [data-pal-menu="#DC2828"]').click({ timeout: 4000 }));
  const menu = p.locator('.cc-pmenu');
  const mt = await soft(menu.innerText({ timeout: 3000 })) || '';
  check(/rgb\(220, 40, 40\)/.test(mt) && /hsl\(/.test(mt), 'o menu ⋯ mostra RGB e HSL');
  check(await menu.locator('button', { hasText: 'Criar Amostra de cor' }).count() === 1, 'e «Criar Amostra de cor»');
  const n0 = await p.locator('.ccc.t-swatch').count();
  await soft(menu.locator('button', { hasText: 'Criar Amostra de cor' }).click({ timeout: 3000 })); await p.waitForTimeout(800);
  check(await p.locator('.ccc.t-swatch').count() === n0 + 1, 'cria um cartão Amostra de cor');
  await soft(p.locator('.ccb-side [data-pal-menu="#DC2828"]').click({ timeout: 3000 }));
  await soft(p.locator('.cc-pmenu button', { hasText: 'Remover' }).click({ timeout: 3000 })); await p.waitForTimeout(800);
  check(!manual().includes('#DC2828'), '«Remover» tira a cor manual');
  // «Copiar paleta»
  await soft(p.locator('.ccb-side [data-pal-copyall]').click({ timeout: 3000 })); await p.waitForTimeout(300);
  check(/#2F77B1/.test(await p.evaluate(() => navigator.clipboard.readText())), '«Copiar paleta» copia os #HEX');

  // 9) imagem ao alto (4 riscas): a posição segue a imagem desenhada (antes: object-fit e barras pretas enganavam)
  await p.goto(BASE + '/admin/toppernote.php?b=' + SP.board);
  await p.waitForSelector('.ccc[data-id="' + SP.cards.portrait + '"]'); await p.waitForTimeout(300);
  await p.locator('.ccc[data-id="' + SP.cards.portrait + '"]').click(); await p.waitForTimeout(200);
  await p.keyboard.press('i'); await p.waitForSelector('.cc-pickfs', { timeout: 8000 }).catch(() => {}); await ready();
  // a imagem que se vê (com object-fit: contain, o conteúdo desenhado é menor do que a caixa do elemento)
  r = await p.evaluate(() => {
    const e = document.querySelector('.cc-pickfs .pf-img') || document.querySelector('.ccd-media.picking img, .ccd-media img');
    if (!e) return { x: 0, y: 0, width: 0, height: 0 };
    const b = e.getBoundingClientRect(), nw = e.naturalWidth || e.width, nh = e.naturalHeight || e.height;
    if (getComputedStyle(e).objectFit !== 'contain' || !nw || !nh) return b.toJSON();
    const s = Math.min(b.width / nw, b.height / nh), w = nw * s, h = nh * s;
    return { x: b.x + (b.width - w) / 2, y: b.y + (b.height - h) / 2, width: w, height: h };
  });
  const stripes = [];
  for (const f of [0.125, 0.375, 0.625, 0.875]) stripes.push(await hexAt(r.x + r.width * f, r.y + r.height * 0.5));
  check(JSON.stringify(stripes) === JSON.stringify(['#DC2828', '#2F77B1', '#2CA02C', '#FFC718']), 'imagem ao alto: cada risca dá a sua cor (' + stripes.join(' ') + ')');
  check(r && r.height >= 0.7 * 900, 'a imagem ao alto ocupa a altura útil (' + Math.round(r && r.height) + ')');
  await hexAt(r.x + r.width * 0.875, r.y + r.height * 0.5);
  check(await p.evaluate(() => { const e = document.querySelector('.cc-loupe .sw'); return e ? getComputedStyle(e).color : ''; }) === 'rgb(17, 17, 17)', 'sobre o amarelo o #HEX fica escuro (legível)');
  await p.keyboard.press('Escape'); await p.waitForTimeout(200);

  // 10) vídeo: I logo a seguir a escolher o cartão — o fotograma é lido por um clone com crossorigin e endereço próprio
  errs.length = 0; reqs.length = 0;
  // no canto: o centro do cartão de vídeo é o ▶ (toca no cartão, não o escolhe — 5 nov)
  await soft(p.locator('.ccc[data-id="' + SP.cards.video + '"]').click({ timeout: 4000, position: { x: 14, y: 14 } }));
  await p.keyboard.press('i'); await p.waitForSelector('.cc-pickfs', { timeout: 8000 }).catch(() => {});
  check(await p.locator('.cc-pickfs').count() === 1, 'vídeo: o conta-gotas abre em ecrã inteiro');
  await ready();
  const vh = await p.locator('.cc-pickfs .pf-hint').textContent({ timeout: 3000 }).catch(() => '');
  check(!/Não foi possível/.test(vh), 'vídeo: o fotograma lê-se (' + vh + ')');
  r = await img();
  const vL = r ? await hexAt(r.x + r.width * 0.25, r.y + r.height * 0.5) : '', vR = r ? await hexAt(r.x + r.width * 0.75, r.y + r.height * 0.5) : '';
  check(near(vL, '#DC2828') && near(vR, '#2F77B1'), 'vídeo: metade vermelha e metade azul (' + vL + ', ' + vR + ')');
  check(reqs.some(u => /cc_file\.php\?a=\d+&s=0&cors=1/.test(u)), 'vídeo: o clone pede o endereço CORS próprio (&cors=1)');
  check(await p.evaluate(() => { const v = document.querySelector('.ccb-side .ccd-media video'); return !v || !v.hasAttribute('crossorigin'); }), 'o leitor do detalhe não depende de CORS (sem crossorigin)');
  check(!errs.length, 'sem erros na página (' + errs.join(' | ') + ')');
  await p.keyboard.press('Escape'); await p.waitForTimeout(200);

  // 11) Biblioteca: Esc fecha primeiro o conta-gotas (a gaveta fica); antes a lupa ficava a flutuar sobre a grelha
  await p.goto(BASE + '/admin/toppernote.php?t=biblioteca');
  await p.waitForSelector('[data-q]');
  await p.locator('[data-q]').fill('pick33retrato');
  const tile = p.locator('.ccl-tile[data-id="' + SP.assets.portrait + '"]');
  await tile.waitFor({ timeout: 8000 }).catch(() => {});
  await soft(tile.click({ timeout: 4000 }));
  await p.waitForSelector('.ccd [data-pick]', { timeout: 8000 }).catch(() => {});
  await soft(p.locator('.ccd [data-pick]').click({ timeout: 4000 })); await p.waitForSelector('.cc-pickfs', { timeout: 8000 }).catch(() => {}); await ready();
  r = await img();
  check(r && r.height >= 0.7 * 900, 'Biblioteca: a imagem abre em ecrã inteiro');
  if (r) await hexAt(r.x + r.width * 0.375, r.y + r.height * 0.5);
  await p.keyboard.press('Escape'); await p.waitForTimeout(250);
  check(await p.locator('.cc-pickfs').count() === 0 && await p.locator('.cc-loupe').count() === 0, 'Biblioteca: Esc fecha o conta-gotas e a lupa');
  check(await p.locator('.ccd').count() === 1, 'Biblioteca: a gaveta continua aberta');
  check(await focusPick(), 'Biblioteca: o foco volta a «+ Escolher da imagem»');
  await p.keyboard.press('Escape'); await p.waitForTimeout(200);
  check(await p.locator('.ccd').count() === 0, 'Biblioteca: o Esc seguinte fecha a gaveta');
  await ctx.close();

  // 12) iPad: um toque acrescenta uma vez só (pointerup + click davam duas vezes)
  const tctx = await b.newContext({ viewport: { width: 1180, height: 820 }, hasTouch: true });
  const t = await tctx.newPage();
  const adds = []; t.on('request', q => { if (/a=palette_add/.test(q.url())) adds.push(q.url()); });
  await t.goto(BASE + '/admin/__as/1');
  await t.goto(BASE + '/admin/toppernote.php?b=' + SP.board);
  await t.waitForSelector('.ccc[data-id="' + SP.cards.image + '"]'); await t.waitForTimeout(300);
  const ib = await t.locator('.ccc[data-id="' + SP.cards.image + '"]').boundingBox();
  await t.touchscreen.tap(ib.x + ib.width / 2, ib.y + ib.height / 2); await t.waitForTimeout(250);
  await t.keyboard.press('i'); await t.waitForSelector('.cc-pickfs', { timeout: 8000 }).catch(() => {});
  await t.waitForFunction(() => /Toque e arraste/.test((document.querySelector('.cc-pickfs .pf-hint') || {}).textContent || ''), null, { timeout: 15000 }).catch(() => {});
  check(/Toque e arraste para apontar; levante o dedo para escolher/.test(await t.locator('.cc-pickfs .pf-hint').textContent({ timeout: 3000 }).catch(() => '')), 'iPad: a dica de toque');
  await t.waitForTimeout(300);   // o fim da animação de abertura (opacidade)
  const tr = await t.evaluate(() => { const e = document.querySelector('.cc-pickfs .pf-img'); return e ? e.getBoundingClientRect().toJSON() : null; });
  check(tr && (tr.width >= 0.7 * 1180 || tr.height >= 0.7 * 820), 'iPad: a imagem em ecrã inteiro');
  const bb = await t.evaluate(() => [...document.querySelectorAll('.cc-pickfs .pf-bar button')].map(x => Math.min(x.getBoundingClientRect().width, x.getBoundingClientRect().height)));
  check(bb.length >= 3 && bb.every(v => v >= 44), 'iPad: botões da barra com 44 px (' + bb.join(',') + ')');
  check(/Dois dedos: aproximar/.test(await t.locator('.cc-pickfs .pf-hint').textContent({ timeout: 3000 }).catch(() => '')), 'iPad: a dica fala da pinça');
  const cdp = await tctx.newCDPSession(t);
  const touch = (type, pts) => cdp.send('Input.dispatchTouchEvent', { type, touchPoints: pts.map(([x, y, id]) => ({ x, y, id: id || 1 })) });
  const loupe = () => t.evaluate(() => { const e = document.querySelector('.cc-loupe'); return e && !e.hidden ? e.getBoundingClientRect().toJSON() : null; });
  const zoomTxt = () => t.locator('.cc-pickfs output').textContent({ timeout: 2000 }).catch(() => '(fechado)');
  // a lupa fica acima do dedo (ou ao lado, quando não cabe acima) — nunca debaixo do dedo e da mão
  // (antes: com o dedo na metade de cima da imagem saltava 60 px para baixo do dedo)
  if (tr) {
    const fx = Math.round(tr.x + tr.width / 2), spots = [];
    await touch('touchStart', [[fx, 600]]);
    for (const fy of [600, 360, 200]) {
      await touch('touchMove', [[fx, fy]]); await t.waitForTimeout(80);
      const l = await loupe();
      spots.push(l && { fy, top: Math.round(l.top), bottom: Math.round(l.bottom), left: Math.round(l.left), right: Math.round(l.right),
        ok: l.top < fy && (l.bottom <= fy - 24 || l.right <= fx - 24 || l.left >= fx + 24) });
    }
    await touch('touchCancel', []); await t.waitForTimeout(200);
    check(spots.length === 3 && spots.every(s => s && s.ok), 'iPad: a lupa fica acima do dedo ou ao lado, nunca por baixo (' + JSON.stringify(spots) + ')');
  }
  // pinça de dois dedos: aproxima à volta do meio e não escolhe nada (antes: o 2.º dedo acrescentava uma cor e fechava)
  if (tr) {
    const cx = Math.round(tr.x + tr.width / 2), cy = Math.round(tr.y + tr.height / 2);
    await touch('touchStart', [[cx - 40, cy, 1]]); await t.waitForTimeout(60);
    await touch('touchStart', [[cx - 40, cy, 1], [cx + 40, cy, 2]]); await t.waitForTimeout(60);
    const lp2 = await loupe();
    for (let i = 1; i <= 5; i++) await touch('touchMove', [[cx - 40 - i * 30, cy, 1], [cx + 40 + i * 30, cy, 2]]);
    await t.waitForTimeout(100);
    const zp = await zoomTxt();
    await touch('touchEnd', [[cx + 190, cy, 2]]);
    await touch('touchMove', [[cx + 150, cy + 40, 2]]); await t.waitForTimeout(60);
    const lp1 = await loupe();
    await touch('touchEnd', []);
    await t.waitForTimeout(1000);
    check(adds.length === 0, 'iPad: a pinça não acrescenta nenhuma cor (' + adds.length + ' pedidos)');
    check(await t.locator('.cc-pickfs').count() === 1, 'iPad: depois da pinça o conta-gotas continua aberto');
    check(parseInt(zp, 10) > 100, 'iPad: a pinça aproxima (' + zp + ')');
    check(!lp2 && !lp1, 'iPad: a lupa desaparece com o 2.º dedo e não volta com o dedo que fica (' + JSON.stringify([lp2, lp1]) + ')');
    await t.keyboard.press('0'); await t.waitForTimeout(150);
    check(await zoomTxt() === '100%', 'iPad: 0 volta ao ajustado depois da pinça');
  }
  // revisão final: o dedo a apontar a borda escorrega 1 px para fora e levanta — não escolhe nem fecha
  if (tr) {
    const tz = await t.evaluate(() => document.querySelector('.cc-pickfs .pf-img').getBoundingClientRect().toJSON());
    const y = Math.round(tz.y + tz.height / 2), xin = Math.ceil(tz.x) + 3, xout = Math.floor(tz.x) - 1;
    await touch('touchStart', [[xin, y]]);
    await touch('touchMove', [[xin - 2, y]]); await touch('touchMove', [[xout, y]]);
    await touch('touchEnd', []);
    await t.waitForTimeout(1000);
    check(xout > 0 && adds.length === 0, 'iPad: começar dentro da imagem e levantar o dedo 1 px fora não acrescenta nenhuma cor (' + adds.length + ' pedidos)');
    check(await t.locator('.cc-pickfs').count() === 1, 'iPad: e o conta-gotas continua aberto');
  }
  if (tr) await t.touchscreen.tap(tr.x + tr.width * 0.25, tr.y + tr.height * 0.5);
  await t.waitForTimeout(1000);
  check(adds.length === 1, 'iPad: um toque acrescenta uma vez só (' + adds.length + ' pedidos)');
  check(await t.locator('.cc-pickfs').count() === 0, 'iPad: sem Shift, o toque escolhe e sai');
  check(manual(SP.assets.image).includes('#DC2828'), 'iPad: a cor tocada é a certa');

  // 13) revisão final: um logótipo escuro num PNG transparente vê-se — atrás da imagem há um xadrez de dois cinzentos
  //     (antes: preto, e o logótipo desaparecia); a cor de um píxel opaco continua a ler-se do original
  {
    const TP = JSON.parse(php(`require_once "admin/inc/cc.php"; require_once "tests/fixtures.php";
      foreach ($pdo->query("SELECT id FROM cc_boards WHERE title LIKE 'Pick33t ·%'")->fetchAll(PDO::FETCH_COLUMN) as $o) { $pdo->exec("DELETE FROM cc_cards WHERE board_id = " . (int)$o); $pdo->exec("DELETE FROM cc_boards WHERE id = " . (int)$o); }
      $im = imagecreatetruecolor(2400, 1600); imagealphablending($im, false); imagesavealpha($im, true);
      imagefilledrectangle($im, 0, 0, 2399, 1599, imagecolorallocatealpha($im, 0, 0, 0, 127));
      imagefilledrectangle($im, 900, 500, 1499, 1099, imagecolorallocate($im, 26, 26, 26));
      ob_start(); imagepng($im); $png = (string)ob_get_clean();
      [$a, $restored] = tests_cc_asset($pdo, $png, ['name' => 'pick33-logo.png', 'mime' => 'image/png']);
      if (trim((string)$a['thumbs']) === '' || $restored) $pdo->prepare("UPDATE cc_assets SET thumbs = ? WHERE id = ?")->execute([json_encode(cc_thumbs_make(cc_gd_open($png), 't/' . substr((string)$a['sha1'], 0, 2) . '/' . $a['sha1'])), $a['id']]);
      $pdo->prepare("UPDATE cc_assets SET deleted_at = NULL, inbox = 0 WHERE id = ?")->execute([$a['id']]);
      $b = cc_board_create($pdo, ['title' => 'Pick33t · transparente', 'kind' => 'free'], 1);
      echo json_encode(['board' => $b, 'card' => cc_card_add($pdo, $b, ['type' => 'image', 'x' => 40, 'y' => 40, 'w' => 300, 'asset_id' => $a['id']], 1)]);`));
    // a cor de um píxel do ecrã (captura de 1 × 1: o único píxel do PNG fica igual com qualquer filtro)
    const zlib = require('zlib');
    const px = async (pg, x, y) => {
      const buf = await pg.screenshot({ clip: { x: Math.round(x), y: Math.round(y), width: 1, height: 1 } });
      const idat = []; let o = 8;
      while (o < buf.length) { const len = buf.readUInt32BE(o), type = buf.toString('ascii', o + 4, o + 8); if (type === 'IDAT') idat.push(buf.slice(o + 8, o + 8 + len)); o += 12 + len; }
      const raw = zlib.inflateSync(Buffer.concat(idat));
      return [raw[1], raw[2], raw[3]];
    };
    const c3 = await b.newContext({ viewport: { width: 1400, height: 900 } });
    const q = await c3.newPage();
    q.on('pageerror', e => errs.push(e.message));
    await q.goto(BASE + '/admin/__as/1');
    await q.goto(BASE + '/admin/toppernote.php?b=' + TP.board);
    await q.waitForSelector('.ccc[data-id="' + TP.card + '"]'); await q.waitForTimeout(300);
    await q.locator('.ccc[data-id="' + TP.card + '"]').click(); await q.waitForTimeout(200);
    await q.keyboard.press('i'); await q.waitForSelector('.cc-pickfs', { timeout: 8000 }).catch(() => {});
    await q.waitForFunction(() => /Clique para escolher/.test((document.querySelector('.cc-pickfs .pf-hint') || {}).textContent || '') && document.querySelector('.cc-pickfs .pf-img').complete, null, { timeout: 15000 }).catch(() => {});
    await q.mouse.move(4, 4); await q.waitForTimeout(400);
    const tr3 = await q.evaluate(() => { const e = document.querySelector('.cc-pickfs .pf-img'); return e ? e.getBoundingClientRect().toJSON() : null; });
    const grey = c => Math.min(...c) >= 90 && Math.max(...c) - Math.min(...c) <= 8;
    for (const theme of ['light', 'dark']) {
      await q.evaluate(t => document.documentElement.setAttribute('data-theme', t), theme); await q.waitForTimeout(150);
      const s = tr3 ? [await px(q, tr3.x + tr3.width * 0.1, tr3.y + tr3.height * 0.1), await px(q, tr3.x + tr3.width * 0.1 + 16, tr3.y + tr3.height * 0.1)] : [];
      check(s.length === 2 && s.every(grey), 'transparente (' + theme + '): atrás da imagem há cinzento neutro, não preto (' + JSON.stringify(s) + ')');
      check(s.length === 2 && s[0].join() !== s[1].join(), 'transparente (' + theme + '): em xadrez de dois cinzentos (' + JSON.stringify(s) + ')');
    }
    let lh = '';
    if (tr3) {
      await q.mouse.move(tr3.x + tr3.width / 2 - 1, tr3.y + tr3.height / 2); await q.mouse.move(tr3.x + tr3.width / 2, tr3.y + tr3.height / 2); await q.waitForTimeout(80);
      lh = await q.evaluate(() => { const l = document.querySelector('.cc-loupe'); return l && !l.hidden ? (l.querySelector('.sw') || {}).textContent || '' : ''; });
    }
    check(lh === '#1A1A1A', 'transparente: a cor de um píxel opaco do logótipo continua a ler-se do original (' + lh + ')');
    await c3.close();
  }
  check(!errs.length, 'sem erros na página (passos 12–13) (' + errs.join(' | ') + ')');

  await b.close();
  console.log(fail ? `FALHOU ${fail} de ${ok + fail}` : `OK ${ok}`);
  process.exit(fail ? 1 : 0);
})();
