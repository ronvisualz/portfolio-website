// §32.7: o hover das linhas da Drive tem de ser legível nos dois modos (contraste ≥ 4.5), com transição de 120 ms,
// e o item selecionado mantém o amarelo. Corre na bancada: node tests/ui/hover_test.js
const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8765';
let ok = 0, fail = 0;
const check = (c, msg) => { if (c) ok++; else { fail++; console.error('FALHA: ' + msg); } };

// o contraste entre a cor do texto e o fundo efetivo (fundos translúcidos compostos sobre o primeiro opaco acima)
const measure = async (page, sel) => page.evaluate((sel) => {
  const el = document.querySelector(sel);
  if (!el) return null;
  const parse = s => { const m = s.match(/rgba?\(([^)]+)\)/); if (!m) return [0, 0, 0, 0]; const p = m[1].split(',').map(Number); return [p[0], p[1], p[2], p.length > 3 ? p[3] : 1]; };
  const layers = []; let n = el;
  while (n && n.nodeType === 1) { const c = parse(getComputedStyle(n).backgroundColor); if (c[3] > 0) layers.push(c); if (c[3] >= 1) break; n = n.parentElement; }
  let bg = [255, 255, 255];
  for (let i = layers.length - 1; i >= 0; i--) { const [r, g, b, a] = layers[i]; bg = [r * a + bg[0] * (1 - a), g * a + bg[1] * (1 - a), b * a + bg[2] * (1 - a)]; }
  const fgc = parse(getComputedStyle(el.querySelector('.dr-tn-n') || el).color);
  const lum = ([r, g, b]) => { const f = v => { v /= 255; return v <= .03928 ? v / 12.92 : Math.pow((v + .055) / 1.055, 2.4); }; return .2126 * f(r) + .7152 * f(g) + .0722 * f(b); };
  const L1 = lum(bg), L2 = lum(fgc.slice(0, 3));
  return { ratio: (Math.max(L1, L2) + .05) / (Math.min(L1, L2) + .05), bg: getComputedStyle(el).backgroundColor, transition: getComputedStyle(el).transitionDuration };
}, sel);

// estático: cada :hover com fundo claro fixo nos CSS da Drive tem de ter equivalente no modo escuro (app.css)
const fs = require('fs'), path = require('path');
const ADM = path.join(__dirname, '../../admin/assets');
const appCss = fs.readFileSync(path.join(ADM, 'app.css'), 'utf8');
for (const f of fs.readdirSync(path.join(ADM, 'drive')).filter(f => f.endsWith('.css'))) {
  const css = fs.readFileSync(path.join(ADM, 'drive', f), 'utf8');
  for (const m of css.matchAll(/([^{}]+):hover[^{]*\{[^}]*background(?:-color)?:\s*#[DEF][0-9A-Fa-f]{5}/g)) {
    const cls = (m[1].trim().split(',').pop().match(/\.[\w-]+/g) || []).pop();
    check(cls && appCss.includes(cls), `${f}: ${m[1].trim()}:hover tem cor clara fixa sem equivalente escuro`);
  }
}

(async () => {
  const b = await chromium.launch();
  for (const theme of ['dark', 'light']) {
    const p = await b.newPage({ viewport: { width: 1280, height: 900 } });
    await p.goto(BASE + '/admin/__as/1');
    await p.goto(BASE + '/admin/drive.php');
    await p.evaluate(t => document.documentElement.setAttribute('data-theme', t), theme);
    await p.waitForSelector('.dr-tn:not(.is-cur)', { timeout: 15000 });
    const sel = '.dr-tn:not(.is-cur):not(.is-root)';
    await p.hover(sel);
    await p.waitForTimeout(300);
    const r = await measure(p, sel);
    check(r && r.ratio >= 4.5, `árvore em ${theme}: contraste ${r && r.ratio.toFixed(2)} (fundo ${r && r.bg})`);
    check(r && r.transition.split(',').some(t => t.trim() === '0.12s'), `árvore em ${theme}: transição 120 ms (${r && r.transition})`);
    const cur = await p.evaluate(() => { const e = document.querySelector('.dr-tn.is-cur'); return e ? getComputedStyle(e).backgroundColor : ''; });
    check(cur === 'rgb(255, 199, 24)', `árvore em ${theme}: selecionado continua amarelo (${cur})`);
    // a lista de conversas do assistente (revisão final): um item com o estilo real, medido com o rato por cima
    await p.evaluate(() => { const b = document.createElement('button'); b.className = 'tdc-conv'; b.id = 'hv-conv'; b.textContent = 'Conversa de teste'; b.style.cssText = 'position:fixed;left:20px;top:300px;z-index:9999;width:240px'; document.body.appendChild(b); });
    await p.hover('#hv-conv'); await p.waitForTimeout(300);
    const cv = await measure(p, '#hv-conv');
    check(cv && cv.ratio >= 4.5, `conversas do assistente em ${theme}: contraste ${cv && cv.ratio.toFixed(2)} (fundo ${cv && cv.bg})`);
    await p.evaluate(() => document.getElementById('hv-conv').remove());
    for (const s of ['.dr-row', '.dr-search']) {
      if (!(await p.$(s))) continue;
      await p.hover(s); await p.waitForTimeout(300);
      const q = await measure(p, s);
      check(q && q.ratio >= 4.5, `${s} em ${theme}: contraste ${q && q.ratio.toFixed(2)}`);
    }
    await p.close();
  }
  await b.close();
  console.log(fail ? `FALHOU ${fail} de ${ok + fail}` : `OK ${ok}`);
  process.exit(fail ? 1 : 0);
})();
