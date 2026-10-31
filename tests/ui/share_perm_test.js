// §32.2: no diálogo «Partilhar link…» escolhe-se «Só ver» / «Pode editar»; na lista de links muda-se depois, sem mudar o link.
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');
const BASE = 'http://127.0.0.1:8765';
let ok = 0, fail = 0;
const check = (c, msg) => { if (c) ok++; else { fail++; console.error('FALHA: ' + msg); } };
execFileSync('php', [path.join(__dirname, 'seed_drive.php')]);

(async () => {
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: 1280, height: 900 } });
  await ctx.grantPermissions(['clipboard-read', 'clipboard-write'], { origin: BASE });
  const p = await ctx.newPage();
  await p.goto(BASE + '/admin/__as/1');
  await p.goto(BASE + '/admin/drive.php?p=' + encodeURIComponent('Empresa/'));
  await p.waitForSelector('.dr-row');
  await p.evaluate(() => document.querySelectorAll('.toasts, .toast, .td-toasts').forEach(e => e.remove()));
  const row = p.locator('.dr-row', { hasText: 'Teste32' }).first();
  await row.click({ button: 'right' });
  await p.locator('.dr-menu-it', { hasText: 'Partilhar link…' }).click();
  const edit = p.locator('input[name=dr-sh-perm][value=edit]');
  check(await edit.count() === 1, 'o diálogo tem a opção «Pode editar»');
  check(await p.locator('input[name=dr-sh-perm][value=view]').isChecked().catch(() => false), '«Só ver» vem escolhido');
  if (await edit.count()) await edit.check();
  await p.locator('[data-create]').click();
  await p.waitForSelector('#dr-sh-url');
  const url = await p.inputValue('#dr-sh-url');
  const csrf = await p.evaluate(() => document.querySelector('meta[name=csrf]').content);
  const list = async () => p.evaluate(async c => (await (await fetch('/admin/drive_api.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': c }, body: JSON.stringify({ do: 'share_list', key: 'Empresa/Teste32/' }) })).json()).items, csrf);
  let mine = (await list()).find(s => s.url === url);
  check(mine && mine.perm === 'edit', 'a partilha foi criada com «Pode editar»');
  // mudar na lista do diálogo
  const sel = p.locator('.dr-sh-row select[data-perm]').last();
  check(await sel.count() === 1, 'cada link tem a permissão para mudar');
  if (await sel.count()) { await sel.selectOption('view'); await p.waitForTimeout(600); }
  mine = (await list()).find(s => s.url === url);
  check(mine && mine.perm === 'view', 'mudou para «Só ver» e o link é o mesmo');
  await b.close();
  console.log(fail ? `FALHOU ${fail} de ${ok + fail}` : `OK ${ok}`);
  process.exit(fail ? 1 : 0);
})();
