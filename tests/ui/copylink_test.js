// §32.5: «Copiar link» copia o URL da partilha dessa linha; se a área de transferência falhar (iPad/Safari), nunca diz
// «Link copiado» — mostra o link num campo selecionável. Corre na bancada: node tests/ui/copylink_test.js
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');
const BASE = 'http://127.0.0.1:8765';
let ok = 0, fail = 0;
const check = (c, msg) => { if (c) ok++; else { fail++; console.error('FALHA: ' + msg); } };

execFileSync('php', [path.join(__dirname, 'seed_drive.php')]);

async function page(b, iosLike) {
  const ctx = await b.newContext({ viewport: { width: 1280, height: 900 } });
  if (!iosLike) await ctx.grantPermissions(['clipboard-read', 'clipboard-write'], { origin: BASE });
  else await ctx.addInitScript(() => {   // como o Safari fora do gesto: a API recusa e o execCommand diz que copiou sem copiar
    Object.defineProperty(navigator, 'clipboard', { value: { writeText: () => Promise.reject(new DOMException('no gesture', 'NotAllowedError')), write: () => Promise.reject(new DOMException('no gesture', 'NotAllowedError')) } });
    document.execCommand = () => true;
  });
  const p = await ctx.newPage();
  await p.goto(BASE + '/admin/__as/1');
  await p.goto(BASE + '/admin/drive.php');
  await p.waitForSelector('.dr-tn');
  p.clearToasts = () => p.evaluate(() => document.querySelectorAll('.td-toast').forEach(t => t.remove()));
  const csrf = await p.evaluate(() => document.querySelector('meta[name=csrf]').content);
  p.api = (body) => p.evaluate(async ([bd, c]) => (await fetch('/admin/drive_api.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': c }, body: JSON.stringify(bd) })).json(), [body, csrf]);
  return p;
}

(async () => {
  const b = await chromium.launch();
  // 1. com área de transferência: cada linha copia o URL da sua partilha
  let p = await page(b, false);
  const s = (await p.api({ do: 'share_create', key: 'Empresa/Teste32/relatorio.txt', hours: 24 })).share;
  await p.goto(BASE + '/admin/drive.php?v=shared');
  await p.clearToasts();
  const row = p.locator('.dr-row', { hasText: 'relatorio.txt' }).last();
  await row.hover(); await row.locator('[data-act=copylink]').click(); await p.waitForTimeout(300);
  const clip = await p.evaluate(() => navigator.clipboard.readText());
  check(/^https?:\/\/[^/]+\/admin\/partilha\.php\?t=[\w-]{43}$/.test(clip), 'copia um URL absoluto de partilha (' + clip + ')');
  check(clip.endsWith(s.url.split('?t=')[1]) || clip === s.url, 'copia a partilha desta linha');
  // 1b. diálogo «Partilhar»: criar o link copia logo esse link (a cópia começa no clique)
  await p.evaluate(() => navigator.clipboard.writeText('ANTIGO'));
  await p.goto(BASE + '/admin/drive.php?p=' + encodeURIComponent('Empresa/Teste32/'));
  await p.waitForSelector('.dr-row'); await p.clearToasts();
  const f = p.locator('.dr-row', { hasText: 'outro.txt' }).first();
  await f.click({ button: 'right' });
  await p.getByText('Partilhar link…').click();
  await p.locator('[data-create]').click();
  await p.waitForSelector('#dr-sh-url'); await p.waitForTimeout(400);
  const made = await p.inputValue('#dr-sh-url');
  check(/\/admin\/partilha\.php\?t=[\w-]{43}$/.test(made), 'o diálogo mostra o link criado');
  check(await p.evaluate(() => navigator.clipboard.readText()) === made, 'o diálogo copiou o link criado (e não o anterior)');
  await p.context().close();

  // 2. como no iPad: a cópia falha → campo com o link, sem «Link copiado»
  p = await page(b, true);
  await p.goto(BASE + '/admin/drive.php?v=shared');
  await p.clearToasts();
  const r2 = p.locator('.dr-row', { hasText: 'relatorio.txt' }).last();
  await r2.hover(); await r2.locator('[data-act=copylink]').click(); await p.waitForTimeout(400);
  const said = await p.evaluate(() => document.body.innerText.includes('Link copiado'));
  check(!said, 'não diz «Link copiado» quando a cópia falhou');
  const box = await p.$('.td-linkbox input');
  check(!!box, 'mostra o link num campo selecionável');
  if (box) {
    const v = await box.inputValue();
    check(/\/admin\/partilha\.php\?t=[\w-]{43}$/.test(v), 'o campo tem o URL da partilha (' + v + ')');
    check(await box.evaluate(e => e.selectionStart === 0 && e.selectionEnd === e.value.length), 'o link vem selecionado');
  }
  await b.close();
  console.log(fail ? `FALHOU ${fail} de ${ok + fail}` : `OK ${ok}`);
  process.exit(fail ? 1 : 0);
})();
