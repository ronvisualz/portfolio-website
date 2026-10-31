// Rede de segurança do Topperboard (§33): abre o semeado, conta os cartões, cria uma nota com N, escreve, Esc,
// Ctrl Z tira-a, Ctrl Shift Z repõe-a; sem erros na consola.   node tests/ui/cc_board_smoke_test.js
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');
const BASE = 'http://127.0.0.1:8765';
let ok = 0, fail = 0;
const check = (c, msg) => { if (c) ok++; else { fail++; console.error('FALHA: ' + msg); } };
const BID = execFileSync('php', [path.join(__dirname, 'seed_board.php')]).toString().trim();

(async () => {
  const b = await chromium.launch();
  const p = await b.newPage({ viewport: { width: 1280, height: 900 } });
  const errs = [];
  p.on('pageerror', e => errs.push(e.message));
  // os tipos de letra da marca e o servidor de tempo real não estão na bancada: não contam
  p.on('console', m => { if (m.type() === 'error' && !/Failed to load resource|WebSocket connection/.test(m.text())) errs.push(m.text()); });
  p.on('response', r => { if (r.status() >= 400 && !/\/fonts\//.test(r.url())) errs.push(r.status() + ' ' + r.url()); });
  await p.goto(BASE + '/admin/__as/1');
  await p.goto(BASE + '/admin/toppernote.php?b=' + BID);
  await p.waitForSelector('.ccc[data-id]');
  await p.waitForTimeout(400);
  check(await p.evaluate(() => typeof window.CCL === 'object' && CCL.version === 1), 'cc_logic.js carregado (CCL.version 1)');
  const n0 = await p.locator('.ccc[data-id]').count();
  check(n0 === 6, 'o Topperboard semeado tem 6 cartões (' + n0 + ')');
  await p.mouse.click(640, 60);   // foco fora de campos
  await p.locator('.ccb-vp, .ccb').first().click({ position: { x: 900, y: 750 } }).catch(() => {});
  await p.keyboard.press('n');
  await p.waitForTimeout(500);
  await p.keyboard.type('Nota de fumo');
  await p.keyboard.press('Escape');
  await p.waitForTimeout(900);
  const has = () => p.evaluate(() => [...document.querySelectorAll('.ccc[data-id]')].some(e => /Nota de fumo/.test(e.textContent)));
  check(await has(), 'N cria uma nota e o texto fica');
  await p.keyboard.press('Escape');
  await p.keyboard.press('Control+z');
  await p.waitForTimeout(800);
  // a primeira anulação desfaz o texto; a seguinte tira a nota (no máximo 3)
  const cnt = () => p.locator('.ccc[data-id]').count();
  for (let i = 0; i < 2 && await cnt() !== n0; i++) { await p.keyboard.press('Control+z'); await p.waitForTimeout(800); }
  check(await p.locator('.ccc[data-id]').count() === n0, 'Ctrl Z tira a nota nova');
  await p.keyboard.press('Control+Shift+z');
  await p.waitForTimeout(800);
  check(await p.locator('.ccc[data-id]').count() === n0 + 1, 'Ctrl Shift Z repõe a nota');
  check(!errs.length, 'sem erros na consola: ' + errs.join(' | '));
  await b.close();
  console.log(fail ? `FALHOU ${fail} de ${ok + fail}` : `OK ${ok}`);
  process.exit(fail ? 1 : 0);
})();
