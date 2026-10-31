// §32.1: mover é instantâneo — o item aparece logo no destino, com «Anular»; enquanto a cópia corre, «A arrumar…».
// node tests/ui/move_instant_test.js
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');
const BASE = 'http://127.0.0.1:8765';
let ok = 0, fail = 0;
const check = (c, msg) => { if (c) ok++; else { fail++; console.error('FALHA: ' + msg); } };
const B = execFileSync('php', [path.join(__dirname, 'seed_moves.php')]).toString().trim();

(async () => {
  const b = await chromium.launch();
  const p = await b.newPage({ viewport: { width: 1280, height: 900 } });
  await p.goto(BASE + '/admin/__as/1');
  await p.goto(BASE + '/admin/drive.php?p=' + encodeURIComponent(B));
  await p.waitForSelector('.dr-row');
  await p.evaluate(() => document.querySelectorAll('.td-toast').forEach(t => t.remove()));
  const t0 = Date.now();
  const r = await p.evaluate(([f, t]) => TD.ops.run({ kind: 'move', payload: { from: f, to: t } }), [B + 'Origem/Lote/', B + 'Destino/Lote/']);
  const dt = Date.now() - t0;
  check(r.ok, 'mover respondeu ok (' + r.message + ')');
  check(dt < 1500, 'a interface dá por feito logo (' + dt + ' ms)');
  await p.waitForTimeout(400);
  const body = await p.evaluate(() => document.body.innerText);
  check(/A arrumar 30 ficheiros/.test(body), 'mostra «A arrumar 30 ficheiros…»');
  check(await p.locator('.td-toast button', { hasText: 'Anular' }).count() >= 1, 'o aviso tem «Anular»');
  // abre o destino: o item já lá está (o servidor ainda não copiou nada)
  await p.goto(BASE + '/admin/drive.php?p=' + encodeURIComponent(B + 'Destino/Lote/'));
  await p.waitForSelector('.dr-row', { timeout: 10000 }).catch(() => {});
  check(await p.locator('.dr-row', { hasText: 'f07.txt' }).count() === 1, 'depois de recarregar, os ficheiros estão no destino');
  const u = await p.evaluate(k => TD.api({ do: 'url', key: k }), B + 'Destino/Lote/f07.txt');
  check(decodeURIComponent(u.url || '').includes('Origem/Lote/f07.txt'), 'abrir lê da origem enquanto a cópia não acaba');
  // anular (ainda na fila): volta à origem
  await p.goto(BASE + '/admin/drive.php?p=' + encodeURIComponent(B));
  await p.waitForSelector('.dr-row');
  const r2 = await p.evaluate(([f, t]) => TD.ops.run({ kind: 'move', payload: { from: f, to: t } }), [B + 'Destino/Lote/', B + 'Destino/Lote2/']);
  check(r2.ok, 'mover outra vez antes de a cópia acabar');
  await p.locator('.td-toast button', { hasText: 'Anular' }).last().click();
  await p.waitForTimeout(1200);
  const st = await p.evaluate(() => TD.api({ do: 'catalog', fresh: true }));
  const ks = (st.objects || []).map(o => o.k);
  check(ks.includes(B + 'Destino/Lote/f01.txt') && !ks.includes(B + 'Destino/Lote2/f01.txt'), 'anulado: fica onde estava antes do segundo mover');
  // a fila corre no servidor
  execFileSync('php', ['-r', 'require "tests/boot.php"; require_once "admin/inc/drive_moves.php"; echo drive_moves_run($pdo, 60);']);
  await p.evaluate(() => document.querySelectorAll('.td-toast').forEach(t => t.remove()));
  const s2 = await p.evaluate(() => TD.api({ do: 'moves_status' }));
  check(s2.files === 0, 'depois de correr, nada por arrumar');
  await b.close();
  console.log(fail ? `FALHOU ${fail} de ${ok + fail}` : `OK ${ok}`);
  process.exit(fail ? 1 : 0);
})();
