// Aviso com «Anular» (app.js/app.css, auditoria T7): no escuro (o aviso é claro) o «Anular» tem ≥ 4,5:1 e o anel de foco
// ≥ 3:1 contra o aviso; com o foco lá dentro o tempo pára (e volta a correr quando o foco sai); se o aviso fechar com o
// foco lá dentro, o foco volta à tela do Topperboard; com toque «Anular» e «×» têm 44 × 44 px. Noutra página o aviso
// continua a funcionar (Anular corre a função e fecha; um aviso simples fecha sozinho).
//   node tests/ui/toast_a11y_test.js
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

function parse(c) { const m = c.match(/[\d.]+/g).map(Number); return { r: m[0], g: m[1], b: m[2] }; }
function lum(c) { const f = v => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }; return 0.2126 * f(c.r) + 0.7152 * f(c.g) + 0.0722 * f(c.b); }
function ratio(a, b) { const x = lum(parse(a)), y = lum(parse(b)); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); }

(async () => {
  const b = await chromium.launch();

  // ── Topperboard, escuro e claro: contraste, anel, tempo parado com o foco, foco de volta à tela ──
  {
    const p = await b.newPage({ viewport: { width: 1400, height: 900 } });
    const errs = []; p.on('pageerror', e => errs.push(String(e)));
    await p.goto(BASE + '/admin/__as/1');
    await p.goto(BASE + '/admin/toppernote.php?b=' + BID);
    await p.waitForSelector('.ccc[data-id="' + ids.note + '"]'); await p.waitForTimeout(600);
    const T = p.locator('.tst', { hasText: 'apagado' });
    const del = async () => {
      await p.locator('.ccc[data-id="' + ids.note + '"]').click({ position: { x: 20, y: 10 } });
      await p.locator('.ccb-vp').focus(); await p.keyboard.press('Delete');
      await T.waitFor({ timeout: 5000 }); await p.waitForTimeout(250);
    };
    const look = () => p.evaluate(() => {
      const t = [...document.querySelectorAll('.tst')].find(x => /apagado/.test(x.textContent)), u = t.querySelector('.undo'), ae = document.activeElement, cs = getComputedStyle(ae);
      return { bg: getComputedStyle(t).backgroundColor, undo: getComputedStyle(u).color, fv: ae.matches(':focus-visible'), inToast: t.contains(ae), ring: cs.outlineColor, rs: cs.outlineStyle, rw: cs.outlineWidth };
    });
    for (const theme of ['dark', 'light']) {
      await p.evaluate(t => document.documentElement.setAttribute('data-theme', t), theme);
      await del();
      await p.locator('.tst', { hasText: 'apagado' }).locator('.undo').focus();
      const l = await look();
      const ru = ratio(l.undo, l.bg), rr = ratio(l.ring, l.bg);
      check(ru >= 4.5, theme + ': «Anular» com ≥ 4,5:1 contra o aviso (' + l.undo + ' / ' + l.bg + ' = ' + ru.toFixed(2) + ')');
      check(l.fv && l.inToast && l.rs === 'solid' && l.rw === '2px' && rr >= 3, theme + ': anel de foco do «Anular» com ≥ 3:1 contra o aviso (' + JSON.stringify(l) + ' = ' + rr.toFixed(2) + ')');
      if (theme === 'dark') {
        // com o foco no «Anular» o aviso não fecha sozinho
        await p.waitForTimeout(9000);
        check(await T.count() === 1, 'escuro: com o foco no «Anular», o aviso continua lá depois dos 8 s');
      }
      // Enter no «Anular»: repõe o cartão, fecha o aviso e o foco volta à tela
      await p.keyboard.press('Enter');
      let back = false; for (let i = 0; i < 20 && !back; i++) { await p.waitForTimeout(250); back = alive(ids.note) === 1; }
      const f = await p.evaluate(() => document.activeElement.className);
      check(back && await T.count() === 0, theme + ': Enter no «Anular» repõe o cartão e fecha o aviso');
      check(/ccb-vp/.test(f), theme + ': o foco volta à tela do Topperboard (' + f + ')');
      await p.waitForTimeout(800);
    }
    // «×» com o foco: fecha e devolve o foco à tela
    await del();
    await T.locator('.x').focus(); await p.keyboard.press('Enter'); await p.waitForTimeout(300);
    let f = await p.evaluate(() => document.activeElement.className);
    check(await T.count() === 0 && /ccb-vp/.test(f), 'Enter no «×» fecha o aviso e o foco volta à tela (' + f + ')');
    // o foco sai do aviso: o tempo volta a correr
    await p.keyboard.press('Control+z'); await p.waitForTimeout(1200);
    await del();
    await T.locator('.undo').focus(); await p.waitForTimeout(300);
    await p.locator('.ccb-vp').focus();
    await p.waitForTimeout(3500);
    check(await T.count() === 0, 'quando o foco sai do aviso, ele fecha pouco depois (como ao tirar o rato)');
    await p.keyboard.press('Control+z'); await p.waitForTimeout(1200);
    check(alive(ids.note) === 1, 'o cartão volta (limpeza do teste)');
    check(errs.length === 0, 'Topperboard: sem erros de JavaScript (' + errs.join(' | ') + ')');
    await p.close();
  }

  // ── toque: «Anular» e «×» com 44 px ──
  {
    const { defaultBrowserType, ...opt } = devices['iPhone 13'];
    const ctx = await b.newContext(opt);
    const p = await ctx.newPage();
    await p.goto(BASE + '/admin/__as/1');
    await p.goto(BASE + '/admin/toppernote.php?b=' + BID);
    await p.waitForSelector('.ccc[data-id]'); await p.waitForTimeout(400);
    await p.evaluate(() => topper.toast('Cartão apagado.', { undo: function () {} }));
    const T = p.locator('.tst', { hasText: 'Cartão apagado.' });
    const u = await T.locator('.undo').boundingBox(), x = await T.locator('.x').boundingBox();
    check(u && u.width >= 44 && u.height >= 44, 'toque: «Anular» tem 44 × 44 px (' + JSON.stringify(u) + ')');
    check(x && x.width >= 44 && x.height >= 44, 'toque: «×» tem 44 × 44 px (' + JSON.stringify(x) + ')');
    await ctx.close();
  }

  // ── outra página: o aviso continua a funcionar ──
  {
    const p = await b.newPage({ viewport: { width: 1280, height: 800 } });
    const errs = []; p.on('pageerror', e => errs.push(String(e)));
    await p.goto(BASE + '/admin/__as/1');
    await p.goto(BASE + '/admin/');
    await p.waitForFunction(() => window.topper && window.topper.toast);
    await p.evaluate(() => { window.__u = 0; topper.toast('Teste do aviso.', { undo: function () { window.__u++; } }); });
    const T = p.locator('.tst', { hasText: 'Teste do aviso.' });
    check(await T.count() === 1 && await T.locator('.undo').isVisible() && await T.locator('.x').isVisible(), 'outra página: o aviso aparece com «Anular» e «×»');
    await T.locator('.undo').click(); await p.waitForTimeout(200);
    check(await p.evaluate(() => window.__u) === 1 && await T.count() === 0, 'outra página: «Anular» corre a função e fecha o aviso');
    await p.evaluate(() => topper.toast('Aviso simples.'));
    const S = p.locator('.tst', { hasText: 'Aviso simples.' });
    check(await S.count() === 1, 'outra página: um aviso simples aparece');
    await p.mouse.move(5, 5); await p.waitForTimeout(4600);
    check(await S.count() === 0, 'outra página: e fecha sozinho');
    check(errs.length === 0, 'outra página: sem erros de JavaScript (' + errs.join(' | ') + ')');
    await p.close();
  }

  await b.close();
  console.log(fail ? `FALHOU ${fail} de ${ok + fail}` : `OK ${ok}`);
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
