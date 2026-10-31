// §32.2: o painel de detalhes mostra as versões anteriores de um ficheiro substituído. node tests/ui/versions_ui_test.js
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');
const BASE = 'http://127.0.0.1:8765';
let ok = 0, fail = 0;
const check = (c, msg) => { if (c) ok++; else { fail++; console.error('FALHA: ' + msg); } };
execFileSync('php', [path.join(__dirname, 'seed_versions.php')]);
(async () => {
  const b = await chromium.launch();
  const p = await b.newPage({ viewport: { width: 1400, height: 900 } });
  await p.goto(BASE + '/admin/__as/1');
  await p.goto(BASE + '/admin/drive.php?p=' + encodeURIComponent('Empresa/Teste32/'));
  await p.waitForSelector('.dr-row');
  await p.evaluate(() => document.querySelectorAll('.toasts, .toast, .td-toasts').forEach(e => e.remove()));
  await p.locator('.dr-row', { hasText: 'com-versoes.txt' }).first().click();
  await p.keyboard.press('i');
  await p.waitForSelector('[data-versions]:not([hidden])', { timeout: 8000 }).catch(() => {});
  const sec = p.locator('[data-versions]');
  check(await sec.isVisible().catch(() => false), 'o painel mostra «Versões anteriores»');
  check((await sec.innerText().catch(() => '')).includes('Ana Visitante'), 'com quem substituiu');
  check(await sec.locator('a[href*="Signature"]').count() >= 1, 'e um link para descarregar');
  await b.close();
  console.log(fail ? `FALHOU ${fail} de ${ok + fail}` : `OK ${ok}`);
  process.exit(fail ? 1 : 0);
})();
