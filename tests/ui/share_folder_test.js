// §32.3: pasta partilhada com grelha de miniaturas, pré-visualização em ecrã inteiro com setas, subpastas; com
// «Pode editar», carregar e criar pastas. node tests/ui/share_folder_test.js
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');
const fs = require('fs');
const os = require('os');
const BASE = 'http://127.0.0.1:8765';
let ok = 0, fail = 0;
const check = (c, msg) => { if (c) ok++; else { fail++; console.error('FALHA: ' + msg); } };
const [VIEW, EDIT] = execFileSync('php', [path.join(__dirname, 'seed_gallery.php')]).toString().trim().split('\n');

(async () => {
  const b = await chromium.launch();
  const p = await b.newPage({ viewport: { width: 1280, height: 900 } });
  await p.goto(BASE + VIEW);
  // a grelha
  check(await p.locator('.sh-card').count() === 5, 'grelha com os 5 ficheiros (' + await p.locator('.sh-card').count() + ')');
  check(await p.locator('.sh-folder').count() === 1, 'a subpasta aparece à parte');
  check(await p.locator('.sh-card[data-kind=image] img').count() === 2, 'as imagens têm miniatura');
  check(await p.locator('[data-up]').count() === 0, '«Só ver» não mostra «Carregar»');
  // o visualizador
  await p.locator('.sh-card', { hasText: '01-vermelho.png' }).click();
  await p.waitForSelector('.sh-viewer:not([hidden])');
  const t1 = await p.locator('.sh-viewer .sh-v-name').innerText();
  await p.keyboard.press('ArrowRight'); await p.waitForTimeout(200);
  const t2 = await p.locator('.sh-viewer .sh-v-name').innerText();
  check(t1.includes('01-vermelho') && t2.includes('02-azul'), 'a seta passa ao seguinte (' + t1 + ' → ' + t2 + ')');
  check(await p.locator('.sh-viewer img').count() === 1, 'a imagem aparece em grande');
  await p.keyboard.press('ArrowRight'); await p.waitForTimeout(200);
  check(await p.locator('.sh-viewer video').count() === 1, 'o vídeo tem leitor');
  await p.keyboard.press('Escape'); await p.waitForTimeout(200);
  check(await p.locator('.sh-viewer').isHidden(), 'Esc fecha');
  // subpastas
  await p.locator('.sh-folder', { hasText: 'Sub' }).click();
  await p.waitForLoadState('load');
  check(p.url().includes('p=Sub'), 'entra na subpasta (' + p.url() + ')');
  check((await p.locator('.sh-crumbs').innerText()).includes('Sub'), 'as migalhas mostram a subpasta');
  check(await p.locator('.sh-card', { hasText: 'dentro.txt' }).count() === 1, 'mostra o que está na subpasta');
  // telemóvel
  const m = await b.newPage({ viewport: { width: 390, height: 844 }, isMobile: true });
  await m.goto(BASE + VIEW);
  check(await m.evaluate(() => document.documentElement.scrollWidth <= 390), 'sem deslocamento horizontal no telemóvel');
  // «Pode editar»: carregar e nova pasta
  const e = await b.newPage({ viewport: { width: 1280, height: 900 } });
  await e.goto(BASE + EDIT);
  check(await e.locator('[data-up]').count() === 1 && await e.locator('[data-mkdir]').count() === 1, '«Pode editar» mostra «Carregar» e «Nova pasta»');
  const f = path.join(os.tmpdir(), 'enviado-' + Date.now() + '.txt'); fs.writeFileSync(f, 'enviado pelo visitante');
  e.on('dialog', d => d.accept('Bruno Teste'));
  await e.fill('#sh-who', 'Bruno Teste').catch(() => {});
  await e.setInputFiles('input[type=file][data-up-input]', f);
  await e.waitForSelector('.sh-card:has-text("' + path.basename(f) + '")', { timeout: 15000 }).catch(() => {});
  check(await e.locator('.sh-card', { hasText: path.basename(f) }).count() === 1, 'o ficheiro carregado aparece na grelha');
  check(await e.locator('[data-del], [data-delete], .sh-delete').count() === 0, 'não há nenhum botão de apagar');
  await b.close();
  console.log(fail ? `FALHOU ${fail} de ${ok + fail}` : `OK ${ok}`);
  process.exit(fail ? 1 : 0);
})();
