// Barra de ferramentas do Topperboard (§33.14): estática (nunca desliza para o lado, nem com barras de deslocamento
// clássicas, pouca altura ou botões de 44 px no iPad) e expansível pela seta na borda, como o menu da aplicação (§12.0):
// «Expandir ferramentas» / «Recolher ferramentas», atalho ] (também com AltGr), nome de cada ferramenta, sem dicas
// enquanto expandida, guardada por pessoa e mantida ao recarregar e ao abrir um sub-Topperboard.
// node tests/ui/cc_tools_bar_test.js
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');
const BASE = 'http://127.0.0.1:8765';
let ok = 0, fail = 0;
const check = (c, msg) => { if (c) ok++; else { fail++; console.error('FALHA: ' + msg); } };
const php = code => execFileSync('php', ['-r', 'require "tests/boot.php"; require_once "admin/inc/cc.php"; ' + code]).toString().trim();
const resetPref = () => php(`$p = json_decode((string)$pdo->query("SELECT prefs FROM users WHERE id=1")->fetchColumn(), true) ?: []; unset($p["cc"]["tools"]); $pdo->prepare("UPDATE users SET prefs=? WHERE id=1")->execute([json_encode($p, JSON_UNESCAPED_UNICODE)]);`);
const prefTools = () => php(`$p = json_decode((string)$pdo->query("SELECT prefs FROM users WHERE id=1")->fetchColumn(), true) ?: []; echo json_encode($p["cc"]["tools"] ?? null);`);
const BID = execFileSync('php', [path.join(__dirname, 'seed_board.php')]).toString().trim();
const SUB = php(`echo $pdo->query("SELECT ref_board_id FROM cc_cards WHERE board_id=${BID} AND type='board'")->fetchColumn();`);
const count = id => +php(`echo $pdo->query("SELECT COUNT(*) FROM cc_cards WHERE board_id=${id} AND deleted_at IS NULL")->fetchColumn();`);
const NAMES = ['Selecionar', 'Anular', 'Refazer', 'Nota', 'Coluna', 'Lista', 'Amostra de cor', 'Desenhar', 'Seta', 'Enviar', 'Link', 'Sub-Topperboard', 'IA'];
const sleep = ms => new Promise(r => setTimeout(r, ms));

// o headless esconde as barras de deslocamento (--hide-scrollbars) e esconderia o problema: barras clássicas, como no Windows
const LAUNCH = { ignoreDefaultArgs: ['--hide-scrollbars'], args: ['--disable-features=OverlayScrollbar,OverlayScrollbars'] };

// medidas da barra: deslocamento lateral, barra horizontal, botões cortados, nomes visíveis
const measure = p => p.evaluate(() => {
  const t = document.querySelector('.ccb-tools'), r = t.getBoundingClientRect();
  const right = r.left + t.clientLeft + t.clientWidth;
  const btns = Array.from(t.querySelectorAll('button')).filter(b => b.offsetParent !== null);
  const before = t.scrollLeft; t.scrollLeft = 60; const forced = t.scrollLeft; t.scrollLeft = before;
  return {
    clientW: t.clientWidth, scrollW: t.scrollWidth, offsetW: t.offsetWidth, hBar: t.offsetHeight - t.clientHeight,
    scrollH: t.scrollHeight, clientH: t.clientHeight, forced,
    clipped: btns.filter(b => b.getBoundingClientRect().right > right + 0.5).length,
    minH: Math.min.apply(null, btns.map(b => b.getBoundingClientRect().height)),
    labels: Array.from(t.querySelectorAll('.tl')).filter(e => e.offsetParent !== null && getComputedStyle(e).display !== 'none' && e.getBoundingClientRect().width > 0).map(e => e.textContent.trim()),
    wide: t.classList.contains('wide'), tips: t.querySelectorAll('[data-tip]').length,
  };
});
const openBoard = async (p, id) => {
  await p.goto(BASE + '/admin/toppernote.php?b=' + (id || BID));
  await p.waitForSelector('.ccb-tools'); await p.waitForTimeout(400);
};
const edge = p => p.locator('.ccb-tools-edge');
// cada secção falha sozinha (um elemento que ainda não existe não pára o resto do teste)
const sec = async (name, fn) => { try { await fn(); } catch (e) { fail++; console.error('FALHA (' + name + '): ' + String(e && e.message || e).split('\n')[0]); } };
const ctx = async (b, o) => { const c = await b.newContext(o); c.setDefaultTimeout(4000); const p = await c.newPage(); await p.goto(BASE + '/admin/__as/1'); return [c, p]; };

(async () => {
  resetPref();
  php(`$pdo->exec("UPDATE cc_boards SET status = 'active' WHERE id = ${SUB}");`);
  const b = await chromium.launch(LAUNCH);
  try {
    // 0) as barras de deslocamento clássicas estão mesmo ligadas (senão o teste passava sem provar nada)
    await sec('barras clássicas', async () => {
      const [c, p] = await ctx(b, { viewport: { width: 1366, height: 640 } });
      const sb = await p.evaluate(() => { const d = document.createElement('div'); d.style.cssText = 'overflow:scroll;width:100px;height:100px;position:absolute'; document.body.appendChild(d); const w = d.offsetWidth - d.clientWidth; d.remove(); return w; });
      check(sb >= 10, 'barras de deslocamento clássicas ligadas no browser de teste (' + sb + ' px)');
      await c.close();
    });

    // 1) nunca desliza para o lado: computador grande, portátil baixo, iPad (botões de 44 px) — recolhida e expandida
    const cases = [
      { name: '1918×900', ctx: { viewport: { width: 1918, height: 900 } } },
      { name: '1366×640', ctx: { viewport: { width: 1366, height: 640 } }, vscroll: true },
      { name: 'iPad (toque)', ctx: { viewport: { width: 1180, height: 820 }, hasTouch: true }, touch: true },
    ];
    for (const cs of cases) await sec(cs.name, async () => {
      const [c, p] = await ctx(b, cs.ctx);
      try {
      await openBoard(p);
      if (cs.touch) check(await p.evaluate(() => matchMedia('(pointer: coarse)').matches), cs.name + ': o contexto tem ponteiro grosso (toque)');
      for (const st of ['recolhida', 'expandida']) {
        if (st === 'expandida') {
          if (cs.touch) await edge(p).tap(); else await edge(p).click();
          await p.waitForTimeout(350);
        }
        const m = await measure(p);
        check(m.wide === (st === 'expandida'), `${cs.name} ${st}: estado da barra (${m.wide})`);
        check(m.scrollW <= m.clientW, `${cs.name} ${st}: nada para deslizar para o lado (scrollWidth ${m.scrollW} > clientWidth ${m.clientW})`);
        check(m.hBar === 0, `${cs.name} ${st}: sem barra de deslocamento horizontal (${m.hBar} px)`);
        check(m.forced === 0, `${cs.name} ${st}: forçar scrollLeft não a mexe (${m.forced})`);
        check(m.clipped === 0, `${cs.name} ${st}: nenhum botão cortado (${m.clipped})`);
        // gesto horizontal (roda/trackpad) sobre a barra
        const r = await p.locator('.ccb-tools').boundingBox();
        if (!cs.touch) {
          await p.mouse.move(r.x + r.width / 2, r.y + 60); await p.mouse.wheel(60, 0); await p.waitForTimeout(150);
          check(await p.evaluate(() => document.querySelector('.ccb-tools').scrollLeft) === 0, `${cs.name} ${st}: a roda na horizontal não a desloca`);
        }
        if (cs.touch) check(m.minH >= 44, `${cs.name} ${st}: botões com 44 px no toque (${m.minH})`);
        if (cs.vscroll) {
          check(m.scrollH > m.clientH, `${cs.name} ${st}: com pouca altura a barra tem mais do que cabe (${m.scrollH} > ${m.clientH})`);
          const top = await p.evaluate(() => { const t = document.querySelector('.ccb-tools'); t.scrollTop = 5000; const v = t.scrollTop; const ia = t.querySelector('[data-tool="ai"]').getBoundingClientRect(), tr = t.getBoundingClientRect(); t.scrollTop = 0; return { v, vis: ia.bottom <= tr.bottom + 1 && ia.top >= tr.top - 1 }; });
          check(top.v > 0 && top.vis, `${cs.name} ${st}: desloca-se na vertical até à IA (${JSON.stringify(top)})`);
        }
        if (st === 'expandida') {
          check(JSON.stringify(m.labels) === JSON.stringify(NAMES), `${cs.name}: expandida mostra o nome de cada ferramenta (${JSON.stringify(m.labels)})`);
          check(m.offsetW >= 120, `${cs.name}: expandida fica larga (${m.offsetW})`);
        } else check(m.labels.length === 0, `${cs.name}: recolhida só tem ícones (${JSON.stringify(m.labels)})`);
        // a seta está na borda direita da barra, a meia altura
        const e = await edge(p).boundingBox(), tr = await p.locator('.ccb-tools').boundingBox();
        check(e && Math.abs(e.x + e.width / 2 - (tr.x + tr.width)) <= 2 && Math.abs(e.y + e.height / 2 - (tr.y + tr.height / 2)) <= 2, `${cs.name} ${st}: seta na borda direita, a meia altura (${JSON.stringify(e)} vs ${JSON.stringify(tr)})`);
        if (cs.touch) {
          // no toque a seta fica sempre à vista (discreta) e o alvo tem 44 px
          check(+(await edge(p).evaluate(el => getComputedStyle(el).opacity)) >= 0.6, `${cs.name} ${st}: no toque a seta fica à vista`);
          // 44 px de alvo, a crescer para fora da barra (sobre a tela), nunca por cima dos botões
          const hit = await p.evaluate(({ x, y }) => [[x + 29, y], [x - 11, y], [x, y + 19], [x, y - 19]].every(([a, b]) => { const h = document.elementFromPoint(a, b); return !!(h && h.closest('.ccb-tools-edge')); }), { x: e.x + e.width / 2, y: e.y + e.height / 2 });
          check(hit, `${cs.name} ${st}: o alvo de toque da seta tem 44 px`);
        }
        // nenhum botão da barra fica debaixo da seta (nem do seu alvo): tocar ou clicar no lado direito de um botão
        // escolhe a ferramenta, não expande a barra — também com o rato por cima da barra (a seta aparece)
        await p.locator('.ccb-tools').hover().catch(() => {});
        const under = await p.evaluate(() => [...document.querySelectorAll('.ccb-tools button, .ccb-tools label')].filter(b => b.offsetParent).filter(b => {
          const r = b.getBoundingClientRect(), y = r.top + r.height / 2, t = document.querySelector('.ccb-tools').getBoundingClientRect();
          if (y < t.top || y > Math.min(t.bottom, innerHeight)) return false;   // só os botões à vista (com pouca altura a barra desloca-se na vertical)
          return [r.right - 2, r.right - 6].some(x => { const h = document.elementFromPoint(x, y); return !(h && b.contains(h)); });
        }).map(b => b.getAttribute('aria-label') || b.textContent.trim()));
        check(under.length === 0, `${cs.name} ${st}: nenhum botão fica debaixo da seta (${JSON.stringify(under)})`);
      }
      // volta a recolher (fica guardado na conta)
      if (cs.touch) await edge(p).tap(); else await edge(p).click();
      await p.waitForTimeout(500);
      } finally { await c.close(); resetPref(); }
    });
    resetPref();

    // 2) a seta e o estado, no computador
    await sec('seta e teclado', async () => {
    const [c, p] = await ctx(b, { viewport: { width: 1600, height: 900 } });
    try {
    await openBoard(p);
    const E = edge(p);
    check(await E.count() === 1 && await E.evaluate(el => el.tagName) === 'BUTTON', 'há uma seta (botão) na barra de ferramentas');
    check(await E.getAttribute('aria-expanded') === 'false', 'começa recolhida (aria-expanded=false)');
    check(/^Expandir ferramentas/.test(await E.getAttribute('aria-label') || ''), 'nome «Expandir ferramentas» (' + await E.getAttribute('aria-label') + ')');
    check(await E.getAttribute('aria-keyshortcuts') === ']', 'atalho ] anunciado (aria-keyshortcuts)');
    check(await p.evaluate(() => { const id = document.querySelector('.ccb-tools-edge').getAttribute('aria-controls'); return !!id && document.getElementById(id) === document.querySelector('.ccb-tools'); }), 'aria-controls aponta para a barra');
    check(+(await E.evaluate(el => getComputedStyle(el).opacity)) === 0, 'no computador a seta só aparece ao passar na barra (como a do menu)');
    const tr0 = await p.locator('.ccb-tools').boundingBox();
    await p.mouse.move(tr0.x + tr0.width / 2, tr0.y + 100); await p.waitForTimeout(400);
    check(+(await E.evaluate(el => getComputedStyle(el).opacity)) === 1, 'ao passar na barra a seta aparece');
    // dica nos ícones com a barra recolhida
    await p.locator('.ccb-tools [data-tool="note"]').hover(); await p.waitForTimeout(700);
    check(/Nota/.test(await p.evaluate(() => { const t = document.querySelector('#tip.on'); return t ? t.textContent : ''; })), 'recolhida: o ícone mostra a dica com o nome');
    // o conteúdo da tela não salta quando a barra se alarga
    const noteBefore = await p.locator('.ccc.t-note').first().boundingBox();
    const prefReq = p.waitForRequest(r => /a=pref\b/.test(r.url()) && /name="k"\s*\r?\n\r?\ntools/.test(r.postData() || ''), { timeout: 4000 }).catch(() => null);
    await E.click(); await p.waitForTimeout(400);
    const req = await prefReq;
    check(!!req && /name="v"\s*\r?\n\r?\n1/.test(req.postData() || ''), 'expandir grava a preferência (pref k=tools v=1)');
    await sleep(300);
    check(prefTools() === 'true', 'fica guardado na conta (' + prefTools() + ')');
    check(await p.locator('.ccb-tools.wide').count() === 1 && await E.getAttribute('aria-expanded') === 'true', 'clicar expande (aria-expanded=true)');
    check(/^Recolher ferramentas/.test(await E.getAttribute('aria-label') || ''), 'nome passa a «Recolher ferramentas» (' + await E.getAttribute('aria-label') + ')');
    check(/Recolher ferramentas/.test(await E.getAttribute('data-tip') || ''), 'a dica da seta passa a «Recolher ferramentas»');
    const noteAfter = await p.locator('.ccc.t-note').first().boundingBox();
    check(Math.abs(noteAfter.x - noteBefore.x) <= 1 && Math.abs(noteAfter.y - noteBefore.y) <= 1, `os cartões ficam no mesmo sítio do ecrã (${noteBefore.x} → ${noteAfter.x})`);
    // sem dicas com a barra expandida (o nome já está à vista)
    const mw = await measure(p);
    check(mw.tips === 0, 'expandida: os botões não têm dicas (' + mw.tips + ')');
    await p.mouse.move(5, 5); await p.waitForTimeout(200);
    await p.locator('.ccb-tools [data-tool="column"]').hover(); await p.waitForTimeout(700);
    check(await p.locator('#tip.on').count() === 0, 'expandida: passar num botão não mostra dica');
    // o nome segue o contraste (claro)
    const contrast = async () => p.evaluate(() => {
      const lum = c => { const m = c.match(/[\d.]+/g).map(Number); const f = v => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }; return 0.2126 * f(m[0]) + 0.7152 * f(m[1]) + 0.0722 * f(m[2]); };
      const ratio = (a, b) => { const x = lum(a), y = lum(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); };
      const t = document.querySelector('.ccb-tools'), l = t.querySelector('[data-tool="note"] .tl'), on = t.querySelector('button.on .tl');
      const bg = getComputedStyle(t).backgroundColor;
      const e = document.querySelector('.ccb-tools-edge');
      return { label: ratio(getComputedStyle(l).color, bg), on: on ? ratio(getComputedStyle(on).color, getComputedStyle(on.parentNode).backgroundColor) : 99, edge: ratio(getComputedStyle(e).color, getComputedStyle(e).backgroundColor) };
    });
    const cl = await contrast();
    check(cl.label >= 4.5 && cl.on >= 4.5 && cl.edge >= 3, 'contraste no modo claro (' + JSON.stringify(cl) + ')');
    // a ferramenta Nota continua a funcionar expandida
    const n0 = count(BID);
    await p.locator('.ccb-tools [data-tool="note"]').click(); await p.waitForTimeout(1200);
    check(count(BID) === n0 + 1, 'expandida, a ferramenta Nota cria um cartão (' + (count(BID) - n0) + ')');
    await p.keyboard.press('Escape'); await p.keyboard.press('Escape'); await p.waitForTimeout(200);
    // recarregar mantém
    await openBoard(p);
    check(await p.locator('.ccb-tools.wide').count() === 1 && await E.getAttribute('aria-expanded') === 'true', 'depois de recarregar continua expandida');
    check(/^Recolher ferramentas/.test(await E.getAttribute('aria-label') || ''), 'depois de recarregar o nome é «Recolher ferramentas»');
    check((await measure(p)).tips === 0, 'depois de recarregar continua sem dicas');

    // 3) teclado: ] alterna (também AltGr e Option); [ continua a ser o menu; Ctrl/Cmd+] e a escrever não
    const wide = () => p.evaluate(() => document.querySelector('.ccb-tools').classList.contains('wide'));
    await p.locator('[data-vp]').focus();
    await p.keyboard.press(']'); await p.waitForTimeout(250);
    check(!(await wide()) && await E.getAttribute('aria-expanded') === 'false', '] recolhe');
    check(/^Expandir ferramentas/.test(await E.getAttribute('aria-label') || ''), 'e o nome volta a «Expandir ferramentas»');
    check((await measure(p)).tips > 0, 'recolhida, os botões voltam a ter dicas');
    await p.keyboard.press(']'); await p.waitForTimeout(250);
    check(await wide(), '] expande');
    await p.keyboard.press('Control+Alt+BracketRight'); await p.waitForTimeout(250);
    check(!(await wide()), 'AltGr + ] (teclado português no Windows) também alterna');
    await p.keyboard.press('Alt+BracketRight'); await p.waitForTimeout(250);
    check(await wide(), 'Option + ] (Mac) também alterna');
    await p.keyboard.press('Control+BracketRight'); await p.waitForTimeout(250);
    check(await wide(), 'Ctrl + ] não alterna');
    await p.keyboard.press('Meta+BracketRight'); await p.waitForTimeout(250);
    check(await wide(), 'Cmd + ] não alterna');
    const nav0 = await p.evaluate(() => document.documentElement.getAttribute('data-nav'));
    await p.keyboard.press('['); await p.waitForTimeout(300);
    const nav1 = await p.evaluate(() => document.documentElement.getAttribute('data-nav'));
    check(nav0 !== nav1 && await wide(), '[ continua a recolher o menu da aplicação e não mexe na barra (' + nav0 + ' → ' + nav1 + ')');
    await p.keyboard.press('['); await p.waitForTimeout(300);
    // a escrever numa nota, ] é texto
    await p.locator('.ccc.t-note').first().dblclick(); await p.waitForTimeout(300);
    await p.keyboard.press(']'); await p.waitForTimeout(250);
    check(await wide(), 'a escrever numa nota, ] não alterna a barra');
    await p.keyboard.press('Escape'); await p.waitForTimeout(200);
    await p.mouse.click(1200, 120); await p.waitForTimeout(200);
    // foco visível na seta
    await p.locator('.ccb-tools [data-tool="ai"]').focus(); await p.keyboard.press('Tab'); await p.waitForTimeout(200);
    const fv = await p.evaluate(() => { const e = document.activeElement; const s = getComputedStyle(e); return { edge: e.classList.contains('ccb-tools-edge'), outline: s.outlineStyle, w: parseFloat(s.outlineWidth), op: +s.opacity }; });
    check(fv.edge && fv.outline !== 'none' && fv.w >= 2 && fv.op === 1, 'a seta recebe o foco do teclado com contorno visível (' + JSON.stringify(fv) + ')');
    await p.keyboard.press('Enter'); await p.waitForTimeout(250);
    check(!(await wide()), 'Enter na seta recolhe');
    await p.keyboard.press('Enter'); await p.waitForTimeout(400);
    check(await wide(), 'Enter na seta expande');

    // 4) Apresentar: sem seta
    await p.locator('[data-a="present"]').click(); await p.waitForTimeout(700);
    check(!(await E.isVisible()), 'em Apresentar a seta não aparece');
    await p.keyboard.press('Escape'); await p.waitForTimeout(500);

    } finally { await c.close(); }
    });

    // 5) sub-Topperboard só de leitura, aberto da resposta pré-carregada (antiga): a barra continua expandida
    await sec('sub-Topperboard', async () => {
    await sleep(400);
    php(`$pdo->exec("UPDATE cc_boards SET status = 'archived' WHERE id = ${SUB}");`);
    // recolhida ao abrir; a resposta do filho é pré-carregada com a preferência antiga (recolhida)
    php(`$p = json_decode((string)$pdo->query("SELECT prefs FROM users WHERE id=1")->fetchColumn(), true) ?: []; $p["cc"]["tools"] = false; $pdo->prepare("UPDATE users SET prefs=? WHERE id=1")->execute([json_encode($p, JSON_UNESCAPED_UNICODE)]);`);
    const [c2, q] = await ctx(b, { viewport: { width: 1600, height: 900 } });
    let subReqs = 0;
    q.on('request', r => { if (new RegExp('a=board&id=' + SUB + '(&|$)').test(r.url())) subReqs++; });
    try {
    await openBoard(q);
    await q.waitForTimeout(2600);
    check(subReqs === 1, 'o filho foi pré-carregado antes de expandir (' + subReqs + ' pedidos)');
    await q.locator('.ccb-tools-edge').click(); await q.waitForTimeout(500);
    await q.evaluate(id => window.ccOpenBoard(id, true), +SUB); await q.waitForTimeout(900);
    check(subReqs === 1, 'o filho abriu da resposta pré-carregada (' + subReqs + ' pedidos)');
    check(/Teste33 · Luz/.test(await q.locator('.ccb-crumb').innerText()), 'abriu o sub-Topperboard');
    check(await q.locator('.ccb-tools [data-tool="note"]').count() === 0, 'o sub-Topperboard é só de leitura (sem ferramentas de edição)');
    const ms = await measure(q);
    check(ms.wide && await q.locator('.ccb-tools-edge').getAttribute('aria-expanded') === 'true', 'no sub-Topperboard a barra continua expandida');
    check(JSON.stringify(ms.labels) === JSON.stringify(['Selecionar', 'IA']), 'só de leitura, expandida mostra os nomes das ferramentas que há (' + JSON.stringify(ms.labels) + ')');
    check(ms.tips === 0 && ms.scrollW <= ms.clientW && ms.hBar === 0, 'só de leitura: sem dicas e sem deslizar (' + JSON.stringify(ms) + ')');
    await q.locator('[data-vp]').focus(); await q.keyboard.press(']'); await q.waitForTimeout(300);
    check(!(await q.evaluate(() => document.querySelector('.ccb-tools').classList.contains('wide'))), 'só de leitura, ] recolhe');
    await q.keyboard.press(']'); await q.waitForTimeout(300);
    await q.evaluate(id => window.ccOpenBoard(id, true), +BID); await q.waitForTimeout(900);
    const mp = await measure(q);
    check(mp.wide && JSON.stringify(mp.labels) === JSON.stringify(NAMES), 'de volta ao pai editável continua expandida, com todas as ferramentas (' + JSON.stringify(mp.labels) + ')');
    } finally { await c2.close(); php(`$pdo->exec("UPDATE cc_boards SET status = 'active' WHERE id = ${SUB}");`); }
    });

    // 6) telemóvel (barra flutuante): sem seta e sem nomes, mesmo com a preferência expandida
    await sec('telemóvel', async () => {
    php(`$p = json_decode((string)$pdo->query("SELECT prefs FROM users WHERE id=1")->fetchColumn(), true) ?: []; $p["cc"]["tools"] = true; $pdo->prepare("UPDATE users SET prefs=? WHERE id=1")->execute([json_encode($p, JSON_UNESCAPED_UNICODE)]);`);
    const [c3, m] = await ctx(b, { viewport: { width: 600, height: 800 } });
    try {
    await openBoard(m);
    check(!(await m.locator('.ccb-tools-edge').isVisible()), 'no telemóvel não há seta');
    const mm = await measure(m);
    check(mm.labels.length === 0 && mm.scrollW <= mm.clientW, 'no telemóvel a barra flutuante fica só com ícones (' + JSON.stringify(mm.labels) + ')');
    } finally { await c3.close(); }
    });

    // 7) modo escuro (com a preferência expandida): contraste; movimento reduzido: sem transições
    await sec('escuro e movimento reduzido', async () => {
    const [c4, d] = await ctx(b, { viewport: { width: 1600, height: 900 }, colorScheme: 'dark' });
    try {
    await openBoard(d);
    // o tema escolhido na conta prevalece sobre o do sistema: força-se o escuro como o botão do tema faz
    await d.evaluate(() => document.documentElement.setAttribute('data-theme', 'dark')); await d.waitForTimeout(300);
    const dk = await d.evaluate(() => {
      const lum = c => { const m = c.match(/[\d.]+/g).map(Number); const f = v => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }; return 0.2126 * f(m[0]) + 0.7152 * f(m[1]) + 0.0722 * f(m[2]); };
      const ratio = (a, b) => { const x = lum(a), y = lum(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); };
      const t = document.querySelector('.ccb-tools'), l = t.querySelector('[data-tool="note"] .tl'), on = t.querySelector('button.on .tl'), e = document.querySelector('.ccb-tools-edge');
      const bgL = lum(getComputedStyle(t).backgroundColor);
      return { dark: bgL < 0.05, label: ratio(getComputedStyle(l).color, getComputedStyle(t).backgroundColor), on: ratio(getComputedStyle(on).color, getComputedStyle(on.parentNode).backgroundColor), edge: ratio(getComputedStyle(e).color, getComputedStyle(e).backgroundColor) };
    });
    check(dk.dark && dk.label >= 4.5 && dk.on >= 4.5 && dk.edge >= 3, 'contraste no modo escuro (' + JSON.stringify(dk) + ')');
    const td = await d.evaluate(() => getComputedStyle(document.querySelector('.ccb-tools-edge')).transitionDuration);
    check(td.split(',').some(x => parseFloat(x) > 0), 'sem preferência, a seta tem transição (' + td + ')');
    } finally { await c4.close(); }
    const [c5, rm] = await ctx(b, { viewport: { width: 1600, height: 900 }, reducedMotion: 'reduce' });
    try {
    await openBoard(rm);
    const tr = await rm.evaluate(() => [getComputedStyle(document.querySelector('.ccb-tools-edge')).transitionDuration, getComputedStyle(document.querySelector('.ccb-tools-edge .ico')).transitionDuration]);
    check(tr.every(x => x.split(',').every(y => parseFloat(y) === 0)), 'com movimento reduzido, sem transições (' + JSON.stringify(tr) + ')');
    } finally { await c5.close(); }
    });
  } finally {
    await b.close();
    resetPref();
    php(`$pdo->exec("UPDATE cc_boards SET status = 'active' WHERE id = ${SUB}");`);
  }
  console.log(fail ? `FALHOU ${fail} de ${ok + fail}` : `OK ${ok}`);
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e); try { resetPref(); } catch (_) {} process.exit(1); });
