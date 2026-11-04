// Anéis de foco do Toppernote (auditoria de acessibilidade, WCAG 1.4.11): no claro, os focos que usavam o amarelo --hi
// (1,44–1,56:1 sobre o branco/a tela) passam a usar o azul de foco da plataforma (--focus); no escuro continuam amarelos
// (10+:1); a barra de Apresentar é sempre escura e fica amarela; no toque a seta das ferramentas com foco fica opaca;
// a célula escolhida do «Juntar da Drive» distingue-se com ≥ 3:1 no claro (anel escuro por dentro do amarelo).
// O foco é forçado pelo DevTools (CSS.forcePseudoState) e as cores lidas do estilo calculado.
//   node tests/ui/cc_focus_rings_test.js
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');
const BASE = 'http://127.0.0.1:8765';
let ok = 0, fail = 0;
const check = (c, msg) => { if (c) ok++; else { fail++; console.error('FALHA: ' + msg); } };
const S = JSON.parse(execFileSync('php', [path.join(__dirname, 'seed_drive_pick.php'), 'dfr' + Date.now().toString(36)]).toString());
const rgb = c => (c.match(/[\d.]+/g) || []).map(Number);
const lum = c => { const v = rgb(c).slice(0, 3).map(x => x / 255).map(x => x <= 0.04045 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4)); return 0.2126 * v[0] + 0.7152 * v[1] + 0.0722 * v[2]; };
const ratio = (a, b) => { const x = lum(a), y = lum(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); };
const YELLOW = 'rgb(255, 199, 24)';

// [nome, seletor do elemento com foco, pseudo forçado, pseudo-elemento do anel, seletores dos fundos ao lado, como criar se faltar]
const RINGS = [
  ['ferramentas (botão)', '.ccb-tools button', 'focus-visible', null, ['.ccb-tools']],
  ['seta das ferramentas', '.ccb-tools-edge', 'focus-visible', null, ['.ccb-vp']],
  ['pega do painel', '.ccb-side-grip', 'focus-visible', null, ['.ccb-side', '.ccb-vp']],
  ['pega do painel (linha)', '.ccb-side-grip', 'focus-visible', '::after', ['.ccb-side', '.ccb-vp']],
  ['paleta do detalhe', '.ccd-pal button', 'focus-visible', null, ['.ccd-pc', '.ccb-side'], 'side:<div class="ccd-pal"><span class="ccd-pc"><button type="button" class="ccd-pcb">#DC2828</button></span></div>'],
  ['comentários do cartão', '.ccc-nc', 'focus-visible', null, ['.ccc', '.ccb-vp'], 'card:<button type="button" class="ccc-nc">2</button>'],
  ['caneta', '.ccb-pen button', 'focus-visible', null, ['.ccb-pen'], 'vp:<div class="ccb-pen"><button type="button">x</button></div>'],
  ['cores do cartão', '.cc-cpop .cp-sw', 'focus-visible', null, ['.cc-cpop'], 'body:<div class="cc-cpop" style="left:0;top:0"><button type="button" class="cp-sw" style="background:#2F77B1"></button></div>'],
  ['aviso de apagados', '.ccb-gone .btn', 'focus-visible', null, ['.ccb-gone'], 'root:<div class="ccb-gone"><span>x</span><button type="button" class="btn sm">Retirar</button></div>'],
];

(async () => {
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: 1400, height: 900 } });
  const p = await ctx.newPage();
  const errs = []; p.on('pageerror', e => errs.push(e.message));
  const cdp = await ctx.newCDPSession(p);
  await p.goto(BASE + '/admin/__as/1');
  await p.goto(BASE + '/admin/toppernote.php?b=' + S.b);
  await p.waitForSelector('.ccc[data-id]'); await p.waitForTimeout(300);
  // o que falta na página cria-se (só para ler o estilo calculado da regra)
  await p.evaluate(rings => {
    rings.forEach(([, sel, , , , mk], i) => {
      let el = document.querySelector(sel);
      if (!el && mk) {
        const [where, html] = [mk.slice(0, mk.indexOf(':')), mk.slice(mk.indexOf(':') + 1)];
        const host = where === 'side' ? (document.querySelector('.ccb-side') || document.body) : where === 'card' ? document.querySelector('.ccc') : where === 'vp' ? document.querySelector('.ccb-vp') : where === 'root' ? document.getElementById('cc') : document.body;
        host.insertAdjacentHTML('beforeend', html);
        el = document.querySelector(sel);
      }
      if (el) el.setAttribute('data-ring', ((el.getAttribute('data-ring') || '') + ' ' + i).trim());   // o mesmo elemento pode ter dois anéis (a pega e a linha)
    });
    const pr = document.createElement('div'); pr.className = 'cc-pres'; pr.innerHTML = '<button type="button" data-ring="pres">›</button><input type="range" data-ring="presr">';
    pr.style.cssText = 'position:fixed;left:-500px;top:0'; document.body.appendChild(pr);
  }, RINGS);
  await cdp.send('DOM.enable'); await cdp.send('CSS.enable');
  const doc = await cdp.send('DOM.getDocument', { depth: -1 });
  const force = async (sel, on) => {
    const { nodeId } = await cdp.send('DOM.querySelector', { nodeId: doc.root.nodeId, selector: sel });
    if (nodeId) await cdp.send('CSS.forcePseudoState', { nodeId, forcedPseudoClasses: on ? ['focus', 'focus-visible'] : [] });
    return !!nodeId;
  };
  const read = (sel, pseudo, bgs) => p.evaluate(([sel, pseudo, bgs]) => {
    const el = document.querySelector(sel); if (!el) return null;
    const cs = getComputedStyle(el, pseudo || null);
    const bgOf = s => { let e = el.closest(s) || document.querySelector(s); while (e && e.parentElement) { const c = getComputedStyle(e).backgroundColor, a = (c.match(/[\d.]+/g) || [])[3]; if (c !== 'rgba(0, 0, 0, 0)' && (a == null || +a > 0.99)) return c; e = e.parentElement; } return getComputedStyle(document.body).backgroundColor; };
    return { style: cs.outlineStyle, color: cs.outlineColor, width: cs.outlineWidth, op: getComputedStyle(el).opacity, bgs: bgs.map(bgOf) };
  }, [sel, pseudo, bgs]);

  for (const th of ['light', 'dark']) {
    await p.evaluate(t => document.documentElement.setAttribute('data-theme', t), th);
    for (let i = 0; i < RINGS.length; i++) {
      const [nm, , , pseudo, bgs] = RINGS[i], sel = '[data-ring~="' + i + '"]';
      if (!await force(sel, true)) { check(false, th + ' · ' + nm + ': elemento em falta'); continue; }
      await p.waitForTimeout(60);
      const r = await read(sel, pseudo, bgs);
      await force(sel, false);
      const worst = r && r.style !== 'none' ? Math.min(...r.bgs.map(bg => ratio(r.color, bg))) : 0;
      check(worst >= 3, th + ' · ' + nm + ': anel de foco com ≥ 3:1 contra o fundo ao lado (' + worst.toFixed(2) + ' · ' + JSON.stringify(r) + ')');
      if (th === 'dark') check(r && r.color === YELLOW, 'escuro · ' + nm + ': o anel continua amarelo (' + (r && r.color) + ')');
    }
    // Apresentar: a barra é sempre escura e o anel continua amarelo nos dois modos
    for (const k of ['pres', 'presr']) {
      await force('[data-ring="' + k + '"]', true);
      const r = await read('[data-ring="' + k + '"]', null, []);
      await force('[data-ring="' + k + '"]', false);
      check(r && r.color === YELLOW && ratio(YELLOW, 'rgb(66, 65, 65)') >= 3, th + ' · Apresentar (' + k + '): anel amarelo sobre a barra escura (' + (r && r.color) + ')');
    }
  }
  await p.evaluate(() => document.documentElement.removeAttribute('data-theme'));

  // toque (html.touch): a seta das ferramentas com foco fica opaca (antes .touch .ccb-tools-edge { opacity: .6 } ganhava)
  await p.evaluate(() => document.documentElement.classList.add('touch'));
  const ei = RINGS.findIndex(r => r[1] === '.ccb-tools-edge'), es = '[data-ring~="' + ei + '"]';
  await force(es, true); await p.waitForTimeout(500);   // a opacidade tem transição
  const eop = await p.evaluate(s => getComputedStyle(document.querySelector(s)).opacity, es);
  await force(es, false); await p.waitForTimeout(500);
  const eidle = await p.evaluate(s => getComputedStyle(document.querySelector(s)).opacity, es);
  check(eop === '1', 'toque: a seta das ferramentas com foco fica opaca (' + eop + ')');
  check(eidle === '0.6', 'toque: sem foco continua a .6 (' + eidle + ')');
  await p.evaluate(() => document.documentElement.classList.remove('touch'));

  // «Juntar da Drive»: a célula escolhida distingue-se com ≥ 3:1 (claro e escuro) e o amarelo da escolha fica
  for (const th of ['light', 'dark']) {
    await p.evaluate(t => document.documentElement.setAttribute('data-theme', t), th);
    await p.locator('[data-upmenu]').click(); await p.locator('.cc-upmenu button', { hasText: 'Da Drive' }).click();
    await p.locator('.cc-dpick').waitFor({ timeout: 5000 }).catch(() => {});
    await p.waitForFunction(() => !document.querySelector('.cc-dpick .dp-list .spin'), null, { timeout: 10000 }).catch(() => {});
    await p.locator('.cc-dpick input[type=search]').fill(S.tag); await p.waitForTimeout(400);
    await p.locator('.cc-dpick .dp-f', { hasText: S.tag + '-n.txt' }).click(); await p.mouse.move(2, 2); await p.waitForTimeout(300);
    const on = await p.evaluate(() => {
      const f = document.querySelector('.cc-dpick .dp-f.on'), d = document.querySelector('.cc-dpick');
      if (!f) return null;
      const cols = getComputedStyle(f).boxShadow.match(/rgba?\([^)]*\)/g) || [];
      // o fundo tingido da célula, por cima do fundo do diálogo
      const num = s => { const v = (s.match(/[\d.]+/g) || []).map(Number); return /^color\(srgb/.test(s) ? v.slice(0, 3).map(x => x * 255).concat(v.slice(3)) : v; };   // rgb(…) ou color(srgb r g b / a)
      const c = num(getComputedStyle(f).backgroundColor), dbg = num(getComputedStyle(d).backgroundColor), a = c.length > 3 ? c[3] : 1;
      const tint = 'rgb(' + [0, 1, 2].map(k => Math.round(c[k] * a + dbg[k] * (1 - a))).join(', ') + ')';
      return { cols, dlg: getComputedStyle(d).backgroundColor, tint };
    });
    const opaque = on ? on.cols.filter(c => { const v = rgb(c); return v.length < 4 || v[3] > 0.99; }) : [];
    const best = on ? Math.max(0, ...opaque.map(c => Math.min(ratio(c, on.dlg), ratio(c, on.tint)))) : 0;
    check(best >= 3, th + ' · célula escolhida: um anel com ≥ 3:1 contra o diálogo e contra o fundo da célula (' + best.toFixed(2) + ' · ' + JSON.stringify(on) + ')');
    check(on && on.cols.includes(YELLOW), th + ' · célula escolhida: o amarelo da escolha continua (' + JSON.stringify(on && on.cols) + ')');
    await p.keyboard.press('Escape'); await p.waitForTimeout(150);
  }
  check(!errs.length, 'sem erros na página (' + errs.join(' | ') + ')');
  await b.close();
  console.log(fail ? `FALHOU ${fail} de ${ok + fail}` : `OK ${ok}`);
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
