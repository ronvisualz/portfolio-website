// §32.6: clique direito / toque longo → «Copiar link de partilha» copia logo um link «Só ver». node tests/ui/quickshare_test.js
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');
const BASE = 'http://127.0.0.1:8765';
let ok = 0, fail = 0;
const check = (c, msg) => { if (c) ok++; else { fail++; console.error('FALHA: ' + msg); } };
execFileSync('php', [path.join(__dirname, 'seed_drive.php')]);
const LINK = /\/admin\/partilha\.php\?t=[\w-]{43}$/;

(async () => {
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: 1280, height: 900 } });
  await ctx.grantPermissions(['clipboard-read', 'clipboard-write'], { origin: BASE });
  const p = await ctx.newPage();
  await p.goto(BASE + '/admin/__as/1');
  await p.goto(BASE + '/admin/drive.php?p=' + encodeURIComponent('Empresa/Teste32/'));
  const quiet = pg => pg.evaluate(() => document.querySelectorAll('.toasts, .toast, .td-toasts').forEach(e => e.remove()));
  await p.waitForSelector('.dr-row'); await quiet(p);
  const row = p.locator('.dr-row', { hasText: 'relatorio.txt' }).first();
  await row.scrollIntoViewIfNeeded();
  // 1. clique direito
  await p.evaluate(() => navigator.clipboard.writeText('ANTIGO'));
  await row.click({ button: 'right' });
  await p.locator('.dr-menu-it', { hasText: 'Copiar link de partilha' }).click();
  await p.waitForTimeout(800);
  const c1 = await p.evaluate(() => navigator.clipboard.readText());
  check(LINK.test(c1), 'clique direito copia o link (' + c1 + ')');
  // 2. barra de ações com um ficheiro selecionado
  await p.evaluate(() => navigator.clipboard.writeText('ANTIGO'));
  await row.click();
  const sb = p.locator('[data-sb=quicklink]');
  check(await sb.count() === 1, 'a barra de ações tem «Copiar link de partilha»');
  if (await sb.count()) { await sb.click(); await p.waitForTimeout(800); }
  const c2 = await p.evaluate(() => navigator.clipboard.readText());
  check(c2 === c1, 'a barra reaproveita o mesmo link');
  await ctx.close();
  // 3. toque longo num ecrã tátil abre o menu
  const t = await b.newContext({ viewport: { width: 820, height: 1180 }, hasTouch: true, isMobile: true });
  const q = await t.newPage();
  await q.goto(BASE + '/admin/__as/1');
  await q.goto(BASE + '/admin/drive.php?p=' + encodeURIComponent('Empresa/Teste32/'));
  const el = q.locator('.dr-row, .dr-card', { hasText: 'relatorio.txt' }).first();
  await el.waitFor(); await quiet(q); await el.scrollIntoViewIfNeeded();
  const box = await el.boundingBox();
  const cdp = await t.newCDPSession(q);
  const pt = [{ x: box.x + box.width / 3, y: box.y + box.height / 2 }];
  await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: pt });
  await q.waitForTimeout(700);
  await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
  await q.waitForTimeout(300);
  check(await q.locator('.dr-menu-it', { hasText: 'Copiar link de partilha' }).isVisible().catch(() => false), 'toque longo abre o menu com «Copiar link de partilha»');
  await b.close();
  console.log(fail ? `FALHOU ${fail} de ${ok + fail}` : `OK ${ok}`);
  process.exit(fail ? 1 : 0);
})();
