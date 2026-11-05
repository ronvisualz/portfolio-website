// Drive: depois de um envio, a miniatura feita no browser grava-se também para quem não tem o Financeiro — o thumb_sign
// leva a chave do ficheiro (o servidor confirma que a pessoa o vê e que o etag é o dele). Também pela grelha
// (TD.thumbs.ensure) para um ficheiro que ainda não tem miniatura.
//   node tests/ui/drive_upload_thumb_test.js      (OLD_UPLOAD_JS=<ficheiro> serve outro upload.js, para provar o vermelho)
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const fs = require('fs');
const BASE = 'http://127.0.0.1:8765';
let ok = 0, fail = 0;
const check = (c, msg) => { if (c) ok++; else { fail++; console.error('FALHA: ' + msg); } };
const php = code => execFileSync('php', ['-r', 'require "tests/boot.php"; require_once "tests/fixtures.php"; ' + code]).toString().trim();
const tag = Math.random().toString(16).slice(2, 8);
const PREFIX = 'Empresa/Teste33/upth-' + tag + '/';
php(`tests_put(${JSON.stringify(PREFIX + 'leia.txt')}, 'ok');`);
// uma imagem já no R2, sem miniatura (para a grelha a fazer)
const OLDKEY = PREFIX + 'antiga.png';
php(`$im = imagecreatetruecolor(60, 40); imagefill($im, 0, 0, imagecolorallocate($im, 30, 120, 200)); ob_start(); imagepng($im); tests_put(${JSON.stringify(OLDKEY)}, (string)ob_get_clean(), 'image/png');`);
const thumbOf = etag => php(`echo r2_head(DRIVE_THUMBS . ${JSON.stringify(etag)} . '.jpg') ? 'sim' : 'nao';`) === 'sim';

(async () => {
  const b = await chromium.launch();
  for (const uid of [3, 1]) {
    const ctx = await b.newContext({ viewport: { width: 1366, height: 800 } });
    const p = await ctx.newPage();
    const errs = []; p.on('pageerror', e => errs.push(e.message));
    const signs = [];
    p.on('response', async r => {
      if (!/drive_api\.php/.test(r.url())) return;
      let body = {}; try { body = JSON.parse(r.request().postData() || '{}'); } catch (e) {}
      if (body.do === 'thumb_sign') signs.push({ code: r.status(), key: body.key || '' });
    });
    if (process.env.OLD_UPLOAD_JS) {
      const old = fs.readFileSync(process.env.OLD_UPLOAD_JS, 'utf8');
      await p.route(/\/admin\/assets\/drive\/upload\.js/, route => route.fulfill({ status: 200, contentType: 'application/javascript', body: old }));
    }
    await p.goto(BASE + '/admin/__as/' + uid);
    await p.goto(BASE + '/admin/drive.php?p=' + encodeURIComponent(PREFIX));
    await p.waitForFunction(() => window.TD && TD.upload && TD.thumbs, null, { timeout: 15000 });
    // 1) envio: a miniatura do ficheiro novo grava-se
    const name = 'foto-' + uid + '.png';
    await p.evaluate(async ([name, prefix, uid]) => {
      const c = document.createElement('canvas'); c.width = 80; c.height = 50;
      const x = c.getContext('2d'); x.fillStyle = uid === 3 ? '#c83228' : '#28a050'; x.fillRect(0, 0, 80, 50);
      const blob = await new Promise(res => c.toBlob(res, 'image/png'));
      TD.upload.addFiles([new File([blob], name, { type: 'image/png' })], [''], prefix);
    }, [name, PREFIX, uid]);
    await p.waitForFunction(n => TD.upload.list().some(j => j.name === n && j.state === 'done' && j.etag), name, { timeout: 20000 }).catch(() => {});
    const job = await p.evaluate(n => TD.upload.list().find(j => j.name === n), name);
    check(job && job.state === 'done' && job.etag, 'uid ' + uid + ': o envio acaba (' + JSON.stringify(job && { state: job.state, error: job.error }) + ')');
    let made = false;
    for (let i = 0; i < 40 && job && job.etag && !(made = thumbOf(job.etag)); i++) await p.waitForTimeout(250);
    check(made, 'uid ' + uid + ': a miniatura do envio fica gravada em .topper/thumbs/');
    await p.waitForTimeout(600);   // o execFileSync bloqueia o ciclo de eventos: os eventos de resposta chegam depois
    check(signs.length > 0 && signs.every(s => s.code === 200), 'uid ' + uid + ': thumb_sign 200 (' + JSON.stringify(signs) + ')');
    check(signs.some(s => s.key === PREFIX + name), 'uid ' + uid + ': o thumb_sign do envio leva a chave do ficheiro');
    // 2) grelha: a imagem antiga sem miniatura ganha uma (TD.thumbs.ensure)
    if (uid === 3) {
      const eOld = php(`echo r2_head(${JSON.stringify(OLDKEY)})['etag'];`);
      const got = await p.evaluate(([k, e]) => new Promise(res => { const t = setTimeout(() => res(''), 15000); TD.thumbs.ensure([{ k, e, s: 100 }], (etag, url) => { clearTimeout(t); res(url || ''); }); }), [OLDKEY, eOld]);
      check(!!got && thumbOf(eOld), 'uid 3: a grelha faz a miniatura de uma imagem que ainda não a tinha');
      await p.waitForTimeout(600);
      check(signs.some(s => s.key === OLDKEY && s.code === 200), 'uid 3: com a chave do ficheiro da grelha');
    }
    check(errs.length === 0, 'uid ' + uid + ': sem erros na página (' + errs.join(' | ') + ')');
    await ctx.close();
  }
  await b.close();
  php(`foreach (r2_list_all(${JSON.stringify(PREFIX)}) as $o) { $h = r2_head($o['key']); if ($h) r2_delete(DRIVE_THUMBS . $h['etag'] . '.jpg'); r2_delete($o['key']); } drive_catalog_invalidate();`);
  console.log(fail ? 'FALHOU ' + fail + ' de ' + (ok + fail) : 'OK ' + ok);
  process.exit(fail ? 1 : 0);
})();
