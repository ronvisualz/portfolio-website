// Topperboard, auditoria T7: o Espaço carrega no botão com o foco (seta da barra de ferramentas, ferramenta «Nota»,
// «+ Escolher da imagem») em vez de ir para o mover da tela/visualizador; no telemóvel a barra da caneta cabe no ecrã e não
// tapa a barra de ferramentas (borracha → «Anular» por toque repõe o traço); com toque, «Fechar (Esc)» da gaveta da
// Biblioteca tem 44 × 44 px.
//   node tests/ui/cc_board_a11y_test.js
const { chromium, devices } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');
const BASE = 'http://127.0.0.1:8765';
const ROOT = path.join(__dirname, '..', '..');
let ok = 0, fail = 0;
const check = (c, msg) => { if (c) ok++; else { fail++; console.error('FALHA: ' + msg); } };
const php = code => execFileSync('php', ['-r', 'require "tests/boot.php"; ' + code], { cwd: ROOT }).toString().trim();
const BID = execFileSync('php', [path.join(__dirname, 'seed_board.php')]).toString().trim();
const ids = JSON.parse(php(`echo json_encode($pdo->query("SELECT type, id FROM cc_cards WHERE board_id = ${BID} AND deleted_at IS NULL")->fetchAll(PDO::FETCH_KEY_PAIR));`));
const alive = id => +php(`echo $pdo->query("SELECT COUNT(*) FROM cc_cards WHERE id = ${id} AND deleted_at IS NULL")->fetchColumn();`);
const pause = ms => new Promise(r => setTimeout(r, ms));

(async () => {
  const b = await chromium.launch();

  // ── teclado: o Espaço carrega no botão com o foco ──
  {
    const p = await b.newPage({ viewport: { width: 1400, height: 900 } });
    const errs = []; p.on('pageerror', e => errs.push(String(e)));
    await p.goto(BASE + '/admin/__as/1');
    await p.goto(BASE + '/admin/toppernote.php?b=' + BID);
    await p.waitForSelector('.ccc[data-id="' + ids.image + '"]'); await p.waitForTimeout(600);
    const space = async sel => { await p.locator(sel).focus(); await p.keyboard.down(' '); await p.waitForTimeout(80); await p.keyboard.up(' '); await p.waitForTimeout(600); };
    const w0 = await p.locator('.ccb-tools.wide').count();
    await space('.ccb-tools-edge');
    const w1 = await p.locator('.ccb-tools.wide').count();
    check(w1 !== w0, 'Espaço na seta da barra de ferramentas expande/recolhe (' + w0 + ' → ' + w1 + ')');
    await space('.ccb-tools-edge');
    check((await p.locator('.ccb-tools.wide').count()) === w0, 'Espaço outra vez repõe a barra');
    const n0 = await p.locator('.ccc[data-id]').count();
    await space('.ccb-tools [data-tool="note"]');
    const n1 = await p.locator('.ccc[data-id]').count();
    check(n1 === n0 + 1, 'Espaço na ferramenta «Nota» cria uma nota (' + n0 + ' → ' + n1 + ')');
    await p.keyboard.press('Escape'); await p.waitForTimeout(300); await p.keyboard.press('Escape'); await p.waitForTimeout(300);
    // a imagem escolhida e o Detalhe aberto: Espaço no «+ Escolher da imagem» abre o conta-gotas, não o visualizador
    await p.locator('.ccc[data-id="' + ids.image + '"]').click({ position: { x: 30, y: 30 } });
    if (!(await p.locator('.ccb-side').isVisible())) await p.locator('[data-a="side"]').first().click();
    await p.locator('.ccb-side [data-tab="det"]').click(); await p.waitForSelector('.ccb-side [data-pick]'); await p.waitForTimeout(500);
    await space('.ccb-side [data-pick]');
    const pk = { pickfs: await p.locator('.cc-pickfs').count(), viewer: await p.locator('.cc-viewer').count() };
    check(pk.pickfs === 1 && pk.viewer === 0, 'Espaço no «+ Escolher da imagem» abre o conta-gotas e não o visualizador (' + JSON.stringify(pk) + ')');
    await p.keyboard.press('Escape'); await p.waitForTimeout(300);
    // a tela continua a mover com Espaço (o Espaço com o foco na tela não mudou)
    await p.locator('.ccb-vp').focus(); await p.keyboard.down(' '); await p.waitForTimeout(100);
    check(await p.evaluate(() => document.querySelector('.ccb-vp').classList.contains('space')), 'com o foco na tela o Espaço continua a ser o «mover»');
    await p.keyboard.up(' ');
    check(errs.length === 0, 'teclado: sem erros de JavaScript (' + errs.join(' | ') + ')');
    await p.close();
  }

  // ── telemóvel: barra da caneta e borracha → «Anular» ──
  {
    const { defaultBrowserType, ...opt } = devices['iPhone 13'];
    const ctx = await b.newContext(opt);
    const p = await ctx.newPage();
    const errs = []; p.on('pageerror', e => errs.push(String(e)));
    const cdp = await ctx.newCDPSession(p);
    await cdp.send('Emulation.setTouchEmulationEnabled', { enabled: true, maxTouchPoints: 5 });
    const send = (type, pts) => cdp.send('Input.dispatchTouchEvent', { type, touchPoints: pts.map((q, i) => ({ x: q[0], y: q[1], id: i, radiusX: 4, radiusY: 4, force: 1 })) });
    const tap = async (x, y) => { await send('touchStart', [[x, y]]); await pause(60); await send('touchEnd', []); };
    const tapEl = async loc => { const r = await loc.boundingBox(); await tap(r.x + r.width / 2, r.y + r.height / 2); return r; };
    const drag = async (x0, y0, x1, y1) => { await send('touchStart', [[x0, y0]]); for (let i = 1; i <= 20; i++) { await pause(16); await send('touchMove', [[x0 + (x1 - x0) * i / 20, y0 + (y1 - y0) * i / 20]]); } await pause(30); await send('touchEnd', []); };
    await p.goto(BASE + '/admin/__as/1');
    await p.goto(BASE + '/admin/toppernote.php?b=' + BID);
    const dc = p.locator('.ccc[data-id="' + ids.drawing + '"]');
    await dc.waitFor(); await p.waitForTimeout(700);
    // o desenho no meio da vista (escolher e F, como «Ajustar à seleção»)
    await tapEl(dc); await p.waitForTimeout(300); await p.keyboard.press('f'); await p.waitForTimeout(900);
    await tapEl(p.locator('.ccb-tools [data-tool="draw"]')); await p.waitForTimeout(400);
    const geo = await p.evaluate(() => {
      const r = e => { const q = e.getBoundingClientRect(); return { l: Math.round(q.left), r: Math.round(q.right), t: Math.round(q.top), b: Math.round(q.bottom) }; };
      const pen = document.querySelector('.ccb-pen'), tools = document.querySelector('.ccb-tools');
      const hit = sel => { const e = document.querySelector(sel), q = e.getBoundingClientRect(), h = document.elementFromPoint(q.left + q.width / 2, q.top + q.height / 2); return !!h && !!h.closest(sel); };
      const all = [...pen.querySelectorAll('button, label')].map(r);
      return { iw: innerWidth, pen: r(pen), tools: r(tools), hidden: pen.hidden, out: all.filter(q => q.l < 0 || q.r > innerWidth).length,
        undo: hit('[data-undo="0"]'), redo: hit('[data-undo="1"]'), toolsCovered: [...tools.querySelectorAll('button')].filter(e => { const q = e.getBoundingClientRect(), h = document.elementFromPoint(q.left + q.width / 2, q.top + q.height / 2); return h && !e.contains(h) && pen.contains(h); }).length };
    });
    const overlap = !(geo.pen.r <= geo.tools.l || geo.pen.l >= geo.tools.r || geo.pen.b <= geo.tools.t || geo.pen.t >= geo.tools.b);
    check(!geo.hidden && geo.pen.l >= 0 && geo.pen.r <= geo.iw && geo.out === 0, 'telemóvel: a barra da caneta e os seus botões cabem no ecrã (' + JSON.stringify(geo) + ')');
    check(!overlap && geo.toolsCovered === 0, 'telemóvel: a barra da caneta não tapa a barra de ferramentas (' + JSON.stringify({ pen: geo.pen, tools: geo.tools, covered: geo.toolsCovered }) + ')');
    check(geo.undo && geo.redo, 'telemóvel: «Anular» e «Refazer» recebem o toque (' + JSON.stringify({ undo: geo.undo, redo: geo.redo }) + ')');
    // borracha: arrastar o dedo pelo traço apaga-o; «Anular» por toque repõe-no
    await tapEl(p.locator('.ccb-pen [data-pt="erase"]')); await p.waitForTimeout(300);
    // o traço é uma diagonal de (10,10) a (310,190): qualquer linha horizontal do desenho o cruza; fica abaixo da caneta
    const r = await dc.boundingBox(), s = r.width / 320, y = Math.max(r.y + 100 * s, geo.pen.b + 16);
    check(y < r.y + 180 * s, 'telemóvel: o desenho está à vista abaixo da barra da caneta (prova do teste: ' + JSON.stringify({ r, pen: geo.pen }) + ')');
    await drag(r.x + 2 * s, y, r.x + 318 * s, y); await p.waitForTimeout(2500);
    check(alive(ids.drawing) === 0, 'telemóvel: a borracha apaga o traço (prova do teste)');
    await tapEl(p.locator('[data-undo="0"]'));
    let back = false; for (let i = 0; i < 20 && !back; i++) { await p.waitForTimeout(250); back = alive(ids.drawing) === 1; }
    check(back, 'telemóvel: um toque em «Anular» repõe o traço apagado pela borracha');
    check(errs.length === 0, 'telemóvel: sem erros de JavaScript (' + errs.join(' | ') + ')');
    await ctx.close();
  }

  // ── toque: «Fechar (Esc)» da gaveta da Biblioteca ──
  for (const dev of ['iPhone 13', 'iPad Pro 11 landscape']) {
    const { defaultBrowserType, ...opt } = devices[dev];
    const ctx = await b.newContext(opt);
    const p = await ctx.newPage();
    await p.goto(BASE + '/admin/__as/1');
    await p.goto(BASE + '/admin/toppernote.php?t=biblioteca');
    const tile = p.locator('.ccl-tile:has(img)').first();
    await tile.waitFor({ timeout: 15000 }); await p.waitForTimeout(500);
    await tile.tap(); await p.waitForSelector('.ccd [data-x]'); await p.waitForTimeout(400);
    const x = await p.locator('.ccd [data-x]').boundingBox();
    check(x && x.width >= 44 && x.height >= 44, dev + ': «Fechar (Esc)» da gaveta da Biblioteca tem 44 × 44 px (' + JSON.stringify(x) + ')');
    await p.locator('.ccd [data-x]').tap(); await p.waitForTimeout(300);
    check((await p.locator('.ccd').count()) === 0, dev + ': e fecha a gaveta');
    await ctx.close();
  }

  await b.close();
  console.log(fail ? `FALHOU ${fail} de ${ok + fail}` : `OK ${ok}`);
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
