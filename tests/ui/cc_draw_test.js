// Desenhar (§33.3): escala ao redimensionar (sem deformar nem cortar), paleta da caneta, espessura, borracha.
// node tests/ui/cc_draw_test.js
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');
const BASE = 'http://127.0.0.1:8765';
let ok = 0, fail = 0;
const check = (c, msg) => { if (c) ok++; else { fail++; console.error('FALHA: ' + msg); } };
const BID = execFileSync('php', [path.join(__dirname, 'seed_board.php')]).toString().trim();
const sql = q => execFileSync('php', ['-r', `require "tests/boot.php"; echo json_encode($pdo->query(${JSON.stringify(q)})->fetchAll());`]).toString();
const drawings = () => JSON.parse(sql(`SELECT id, w, h, content FROM cc_cards WHERE board_id=${BID} AND type='drawing' AND deleted_at IS NULL ORDER BY id`)).map(r => Object.assign(r, { c: JSON.parse(r.content) }));

(async () => {
  const b = await chromium.launch();
  const p = await b.newPage({ viewport: { width: 1280, height: 900 } });
  await p.goto(BASE + '/admin/__as/1');
  await p.goto(BASE + '/admin/toppernote.php?b=' + BID);
  await p.waitForSelector('.ccc.t-drawing');
  await p.waitForTimeout(400);
  const card = p.locator('.ccc.t-drawing').first();
  // a proporção do traço dentro do cartão: tem de ser igual antes e depois de redimensionar, e o traço nunca sai do cartão
  const fit = () => card.evaluate(el => {
    const c = el.getBoundingClientRect(), r = el.querySelector('path').getBoundingClientRect();
    return { rw: r.width / c.width, rh: r.height / c.height, inside: r.left >= c.left - 1 && r.top >= c.top - 1 && r.right <= c.right + 1 && r.bottom <= c.bottom + 1, aspect: r.width / r.height };
  });
  const f0 = await fit();
  const resize = async k => {
    await card.click(); await p.waitForTimeout(150);
    const h = card.locator('.ccc-res'), bb = await h.boundingBox(), cb = await card.boundingBox();
    const dx = cb.width * (k - 1);
    await p.mouse.move(bb.x + bb.width / 2, bb.y + bb.height / 2); await p.mouse.down();
    await p.mouse.move(bb.x + bb.width / 2 + dx / 2, bb.y + bb.height / 2, { steps: 4 });
    await p.mouse.move(bb.x + bb.width / 2 + dx, bb.y + bb.height / 2, { steps: 4 }); await p.mouse.up();
    await p.waitForTimeout(900);
  };
  for (const k of [2, 0.25]) {
    await resize(k);
    const f = await fit();
    check(f.inside, `a ${k * 100}%: o traço não sai do cartão (${JSON.stringify(f)})`);
    check(Math.abs(f.rw - f0.rw) < 0.03 && Math.abs(f.rh - f0.rh) < 0.03, `a ${k * 100}%: o traço escala na proporção do cartão (${f.rw.toFixed(3)}/${f0.rw.toFixed(3)}, ${f.rh.toFixed(3)}/${f0.rh.toFixed(3)})`);
    check(Math.abs(f.aspect - f0.aspect) / f0.aspect < 0.03, `a ${k * 100}%: sem deformar (proporção ${f.aspect.toFixed(3)} vs ${f0.aspect.toFixed(3)})`);
  }
  // depois de recarregar continua igual (o servidor guarda a caixa original do desenho)
  await p.reload(); await p.waitForSelector('.ccc.t-drawing'); await p.waitForTimeout(400);
  const f2 = await fit();
  check(f2.inside && Math.abs(f2.rw - f0.rw) < 0.03, 'depois de recarregar o desenho continua à escala (' + JSON.stringify(f2) + ')');
  // ── barra da caneta (§33.3): D/P abre; 1–5 cores da marca; espessura; borracha E ──
  await p.keyboard.press('Escape');
  await p.keyboard.press('p'); await p.waitForTimeout(200);
  const bar = p.locator('.ccb-pen');
  check(await bar.isVisible().catch(() => false), 'P abre a barra da caneta');
  check(await bar.locator('.pen-c').count() >= 6, 'a barra tem as 5 cores da marca e a cor livre');
  await p.keyboard.press('3'); await p.waitForTimeout(100);
  check(await bar.locator('.pen-c[aria-pressed="true"]').getAttribute('data-hex') === '#E8846D', 'a tecla 3 escolhe #E8846D');
  await bar.locator('[data-pw="8"]').click();
  check(await bar.locator('[data-pw="8"]').getAttribute('aria-pressed') === 'true', 'espessura grossa escolhida');
  const n0 = drawings().length;
  // uma zona livre da tela (sem cartões, sem a barra da caneta, sem o painel lateral)
  const Z = await p.evaluate(() => {
    const vp = document.querySelector('.ccb-vp').getBoundingClientRect(), side = document.querySelector('.ccb-side');
    const right = side && side.offsetParent ? Math.min(vp.right, side.getBoundingClientRect().left) : vp.right;
    const busy = [...document.querySelectorAll('.ccc, .ccb-pen, .ccb-zoom, .ccb-hint')].map(e => e.getBoundingClientRect());
    for (let y = vp.top + 70; y < vp.bottom - 130; y += 20) for (let x = vp.left + 20; x < right - 180; x += 20) {
      const r = { left: x - 20, top: y - 40, right: x + 160, bottom: y + 120 };
      if (!busy.some(b => b.left < r.right && b.right > r.left && b.top < r.bottom && b.bottom > r.top)) return { x, y };
    }
    return null;
  });
  check(!!Z, 'há uma zona livre para desenhar');
  const X = Z.x, Y = Z.y;
  await p.mouse.move(X, Y); await p.mouse.down();
  for (let i = 1; i <= 10; i++) await p.mouse.move(X + i * 12, Y + i * 8);
  await p.mouse.up(); await p.waitForTimeout(900);
  const ds = drawings(), nd = ds[ds.length - 1];
  check(ds.length === n0 + 1 && nd.c.paths[0].c === '#E8846D' && nd.c.paths[0].w === 8, 'o traço novo tem a cor e a espessura escolhidas (' + JSON.stringify(nd && nd.c.paths[0] && { c: nd.c.paths[0].c, w: nd.c.paths[0].w }) + ')');
  check(nd.c.vw > 0 && nd.c.vh > 0, 'o traço novo guarda a sua caixa (vw/vh)');
  // borracha
  await p.keyboard.press('e'); await p.waitForTimeout(100);
  check(await bar.locator('[data-pt="erase"]').getAttribute('aria-pressed') === 'true', 'E liga a borracha');
  await p.mouse.move(X + 40, Y + 5); await p.mouse.down(); await p.mouse.move(X + 80, Y + 60, { steps: 8 }); await p.mouse.up();
  await p.waitForTimeout(900);
  check(drawings().length === n0, 'a borracha apaga o traço tocado (' + drawings().length + ')');
  await p.keyboard.press('Control+z'); await p.waitForTimeout(1200);
  check(drawings().length === n0 + 1, 'Ctrl Z repõe o traço apagado');
  // «Espessura acompanha» desligado → o traço novo mantém a espessura ao redimensionar (non-scaling-stroke)
  await p.keyboard.press('p'); await bar.locator('[data-pfx]').uncheck();
  await p.mouse.move(X + 10, Y + 90); await p.mouse.down(); await p.mouse.move(X + 120, Y + 100, { steps: 6 }); await p.mouse.up();
  await p.waitForTimeout(900);
  const fx = drawings().pop();
  check(fx.c.fx === true, 'com «Espessura acompanha» desligado o desenho guarda fx');
  check(await p.locator('.ccc.t-drawing svg.fx').count() >= 1, 'e desenha com espessura fixa');
  await p.keyboard.press('Escape'); await p.waitForTimeout(150);
  check(!(await bar.isVisible().catch(() => false)), 'Esc fecha a caneta');
  await b.close();
  console.log(fail ? `FALHOU ${fail} de ${ok + fail}` : `OK ${ok}`);
  process.exit(fail ? 1 : 0);
})();
