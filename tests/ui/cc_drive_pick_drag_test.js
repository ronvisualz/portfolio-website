// «Juntar da Drive» — arrastar para o Topperboard (§33.9): do seletor (arrasto real do rato) o cartão fica onde se larga
// (na grelha), o seletor fecha e Ctrl+Z tira-o; com 2 escolhidos entram os 2; um não escolhido entra sozinho; largar fora
// do Topperboard devolve o diálogo como estava; um clique que mexe uns píxeis não junta nada nem fecha o diálogo; um
// clique durante um arrasto preso não fecha o diálogo. Da página da Drive
// (outro separador): o que a Drive põe no arrasto entra no Topperboard; pastas ditas à parte; mais de 30 ficheiros entram
// todos (pedidos de 30).   node tests/ui/cc_drive_pick_drag_test.js
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');
const BASE = 'http://127.0.0.1:8765';
let ok = 0, fail = 0;
const check = (c, msg) => { if (c) ok++; else { fail++; console.error('FALHA: ' + msg); } };
const S = JSON.parse(execFileSync('php', [path.join(__dirname, 'seed_drive_pick.php'), 'dpd' + Date.now().toString(36)]).toString());
const T = S.tag;
const php = code => execFileSync('php', ['-r', 'require "tests/boot.php"; ' + code]).toString().trim();
const lastCards = n => JSON.parse(php(`echo json_encode($pdo->query("SELECT id, x, y FROM cc_cards WHERE board_id = ${S.b} AND deleted_at IS NULL ORDER BY id DESC LIMIT ${n}")->fetchAll(PDO::FETCH_ASSOC));`));

(async () => {
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: 1600, height: 1000 } });
  // o fantasma do arrasto: guarda o texto do elemento dado a setDragImage
  await ctx.addInitScript(() => {
    const f = DataTransfer.prototype.setDragImage;
    DataTransfer.prototype.setDragImage = function (el, x, y) { window.__ghost = el && el.textContent; return f.call(this, el, x, y); };
  });
  const p = await ctx.newPage();
  p.setDefaultTimeout(8000);
  const errs = [];
  p.on('pageerror', e => errs.push(e.message));
  await p.goto(BASE + '/admin/__as/1');
  await p.goto(BASE + '/admin/toppernote.php?b=' + S.b);
  await p.waitForSelector('.ccc[data-id]'); await p.waitForTimeout(400);
  const cards = () => p.locator('.ccc[data-id]').count();
  const waitCards = async n => { for (let i = 0; i < 40 && await cards() !== n; i++) await p.waitForTimeout(150); return cards(); };
  const noToasts = () => p.evaluate(() => document.querySelectorAll('.tst').forEach(t => t.remove()));
  const vp = await p.locator('.ccb-vp').boundingBox();
  const world = () => p.evaluate(() => { const m = new DOMMatrix(getComputedStyle(document.querySelector('.ccb-world')).transform); return { s: m.a, x: m.e, y: m.f }; });
  const snapped = async (cx, cy) => { const v = await world(), wx = (cx - vp.x - v.x) / v.s, wy = (cy - vp.y - v.y) / v.s; return { x: Math.round((wx - 140) / 20) * 20, y: Math.round((wy - 40) / 20) * 20 }; };
  async function open() {
    if (await p.locator('.cc-dpick').count()) { await p.keyboard.press('Escape'); await p.waitForTimeout(150); }   // um passo anterior falhou e deixou-o aberto
    await noToasts();
    await p.locator('[data-upmenu]').click();
    await p.locator('.cc-upmenu button', { hasText: 'Da Drive' }).click();
    const dlg = p.locator('.cc-dpick'); await dlg.waitFor();
    await p.waitForFunction(() => !document.querySelector('.cc-dpick .dp-list .spin'), null, { timeout: 10000 }).catch(() => {});
    await dlg.locator('input[type=search]').fill(T); await p.waitForTimeout(400);
    return dlg;
  }
  const cell = (dlg, nm) => dlg.locator('.dp-f', { hasText: T + '-' + nm });
  async function drag(src, to, during) {
    const a = await src.boundingBox();
    await p.mouse.move(a.x + a.width / 2, a.y + a.height / 3);
    await p.mouse.down();
    await p.mouse.move(a.x + a.width / 2 + 12, a.y + a.height / 3 + 12, { steps: 3 });
    await p.mouse.move(to.x, to.y, { steps: 10 });
    await p.waitForTimeout(150);
    const mid = during ? await during() : null;
    await p.mouse.up();
    return mid;
  }
  const midState = () => p.evaluate(() => {
    // op: a opacidade do conteúdo do diálogo (a lista); a dica do arrasto fica dentro do diálogo aria-modal, à vista
    const bk = document.querySelector('.cc-dpick-back'), d = document.querySelector('.cc-dpick .dp-list'), h = document.querySelector('.cc-dpick-back .dp-drag-hint');
    return { pe: bk && getComputedStyle(bk).pointerEvents, op: d && getComputedStyle(d).opacity, hint: h ? h.textContent : '', hintShown: !!h && getComputedStyle(h).display !== 'none' && h.getBoundingClientRect().width > 1,
      live: !!h && (h.getAttribute('role') === 'status' || h.getAttribute('aria-live') === 'polite'), inModal: !!h && !!h.closest('[aria-modal="true"]') };
  });

  // C1: nada escolhido; arrastar a imagem para um sítio do Topperboard que fica por baixo do diálogo (passa através dele)
  let dlg = await open();
  const dr = await dlg.boundingBox();
  const to1 = { x: Math.round(Math.max(vp.x, dr.x) + 80), y: Math.round(dr.y + 120) };
  check(await p.evaluate(([x, y]) => { const e = document.querySelector('.cc-dpick-back'); e.style.pointerEvents = 'none'; const t = document.elementFromPoint(x, y); e.style.pointerEvents = ''; return !!(t && t.closest('.ccb-vp')); }, [to1.x, to1.y]), 'o ponto de largar (debaixo do diálogo) é a tela');
  const n0 = await cards();
  const exp1 = await snapped(to1.x, to1.y);
  const mid = await drag(cell(dlg, 'a.png'), to1, midState);
  check(mid.pe === 'none', 'durante o arrasto o fundo do diálogo deixa passar (pointer-events: ' + mid.pe + ')');
  check(mid.op === '0', 'durante o arrasto o diálogo fica transparente (' + mid.op + ')');
  check(mid.hint === 'Largue no Topperboard para juntar.' && mid.hintShown && mid.live, 'dica viva «Largue no Topperboard para juntar.» (' + JSON.stringify(mid.hint) + ')');
  check(mid.inModal, 'a dica viva fica dentro do diálogo aria-modal (os leitores de ecrã anunciam-na)');
  check(await p.evaluate(() => window.__ghost) === '1 ficheiro', 'o fantasma diz «1 ficheiro» (' + await p.evaluate(() => window.__ghost) + ')');
  check(await waitCards(n0 + 1) === n0 + 1, '1 cartão novo');
  const c1 = lastCards(1)[0];
  check(c1 && Math.abs(c1.x - exp1.x) <= 20 && Math.abs(c1.y - exp1.y) <= 20, 'o cartão fica onde se largou, na grelha (' + JSON.stringify(c1) + ' ≈ ' + JSON.stringify(exp1) + ')');
  check(await p.locator('.cc-dpick, .cc-dpick-back').count() === 0, 'o seletor fecha depois de largar');
  check(await p.evaluate(() => { const a = document.activeElement; return !a || a === document.body || !!a.closest('#cc'); }), 'o foco volta ao Topperboard');
  await p.keyboard.press('Control+z');
  check(await waitCards(n0) === n0, 'Ctrl+Z tira o cartão');

  // C2: dois escolhidos, arrastar um deles para fora do diálogo → entram os dois
  dlg = await open();
  await cell(dlg, 'a.png').click(); await cell(dlg, 'c.png').click();
  check(/Adicionar 2/.test(await dlg.locator('[data-dp-add]').innerText()), '2 escolhidos');
  const d2 = await dlg.boundingBox();
  const to2 = { x: Math.round(vp.x + 40), y: Math.round(Math.min(vp.y + vp.height - 60, d2.y + d2.height + 60)) };
  check(await p.evaluate(([x, y]) => { const e = document.querySelector('.cc-dpick-back'); e.style.pointerEvents = 'none'; const t = document.elementFromPoint(x, y); e.style.pointerEvents = ''; return !!(t && t.closest('.ccb-vp')); }, [to2.x, to2.y]), 'o segundo ponto também é a tela');
  await drag(cell(dlg, 'c.png'), to2);
  check(await waitCards(n0 + 2) === n0 + 2, 'arrastar um dos 2 escolhidos → 2 cartões');
  check(await p.evaluate(() => window.__ghost) === '2 ficheiros', 'o fantasma diz «2 ficheiros»');
  check(await p.locator('.cc-dpick').count() === 0, 'e o seletor fecha');

  // C3: dois escolhidos, arrastar outro (não escolhido) → só esse
  dlg = await open();
  await cell(dlg, 'a.png').click(); await cell(dlg, 'c.png').click();
  await drag(cell(dlg, 'b.png'), to2);
  check(await waitCards(n0 + 3) === n0 + 3, 'arrastar um não escolhido → só 1 cartão');

  // C4: largar fora do Topperboard (na barra de ferramentas) → nada entra; o diálogo volta com a escolha
  dlg = await open();
  await cell(dlg, 'a.png').click(); await cell(dlg, 'c.png').click();
  const tb = await p.locator('.ccb-tools').boundingBox();
  await drag(cell(dlg, 'a.png'), { x: Math.round(tb.x + tb.width / 2), y: Math.round(tb.y + tb.height - 30) });
  await p.waitForTimeout(800);
  check(await cards() === n0 + 3, 'largar fora do Topperboard não junta nada');
  const st = await p.evaluate(() => { const bk = document.querySelector('.cc-dpick-back'), d = document.querySelector('.cc-dpick .dp-list'); return bk && d ? { drag: bk.classList.contains('is-dragging'), pe: getComputedStyle(bk).pointerEvents, op: getComputedStyle(d).opacity } : null; });
  check(st && !st.drag && st.pe === 'auto' && st.op === '1', 'o diálogo volta como estava (' + JSON.stringify(st) + ')');
  check(/Adicionar 2/.test(await dlg.locator('[data-dp-add]').innerText().catch(() => '')) && await dlg.locator('.dp-f[aria-pressed="true"]').count() === 2, 'com a mesma escolha («Adicionar 2»)');

  // C7: um clique que mexe uns píxeis (o Chromium começa o arrasto aos 4 px; no trackpad e com Shift/Ctrl é comum) não
  // é um largar no Topperboard: o diálogo não desaparece, nada entra e a escolha fica
  for (const [nm, dx, dy] of [['b.png', 6, 0], ['a.png', 4, 4], ['b.png', 0, 16]]) {
    const tag = nm + ' +' + dx + ',' + dy + ' px';
    if (!await p.locator('.cc-dpick').count()) { dlg = await open(); await cell(dlg, 'a.png').click(); await cell(dlg, 'c.png').click(); }   // o caso anterior fechou-o
    const nB = await cards();
    const a = await cell(dlg, nm).boundingBox();
    const x0 = Math.round(a.x + a.width / 2), y0 = Math.round(a.y + a.height / 3);
    await p.mouse.move(x0, y0); await p.mouse.down();
    await p.mouse.move(x0 + dx, y0 + dy, { steps: 3 });
    await p.waitForTimeout(250);
    const m = await midState();
    await p.mouse.up(); await p.waitForTimeout(700);
    check(m.op === '1' && m.pe !== 'none', tag + ': com o botão em baixo o diálogo continua à vista (' + JSON.stringify(m) + ')');
    check(await cards() === nB, tag + ': nada entra no Topperboard (' + (await cards() - nB) + ' cartões novos)');
    const s7 = await p.evaluate(() => { const bk = document.querySelector('.cc-dpick-back'), d = document.querySelector('.cc-dpick .dp-list'); return bk && d ? { drag: bk.classList.contains('is-dragging'), pe: getComputedStyle(bk).pointerEvents, op: getComputedStyle(d).opacity } : null; });
    check(s7 && !s7.drag && s7.pe === 'auto' && s7.op === '1', tag + ': o diálogo fica aberto como estava (' + JSON.stringify(s7) + ')');
    check(s7 && /Adicionar 2/.test(await dlg.locator('[data-dp-add]').innerText().catch(() => '')) && await dlg.locator('.dp-f[aria-pressed="true"]').count() === 2, tag + ': com a mesma escolha («Adicionar 2»)');
  }
  if (!await p.locator('.cc-dpick').count()) dlg = await open();

  // C6: arrasto preso antes de sair do sítio (sem dragover longe nem dragend): o diálogo continua à vista e o clique
  // seguinte numa célula escolhe-a (não é engolido)
  const pk = await dlg.locator('.dp-f[aria-pressed="true"]').count();
  await cell(dlg, 'b.png').evaluate(el => { const r = el.getBoundingClientRect(); el.dispatchEvent(new DragEvent('dragstart', { bubbles: true, cancelable: true, clientX: r.x + r.width / 2, clientY: r.y + r.height / 3, dataTransfer: new DataTransfer() })); });
  await p.waitForTimeout(150);
  check(await p.evaluate(() => !document.querySelector('.cc-dpick-back').classList.contains('is-dragging')), 'arrasto preso no sítio: o diálogo continua à vista (sem is-dragging)');
  const bb = await cell(dlg, 'b.png').boundingBox(), clickB = () => p.mouse.click(Math.round(bb.x + bb.width / 2), Math.round(bb.y + bb.height / 3));   // o rato real (sem as esperas do Playwright)
  await clickB(); await p.waitForTimeout(150);
  check(await dlg.locator('.dp-f[aria-pressed="true"]').count() === pk + 1 && await cell(dlg, 'b.png').getAttribute('aria-pressed') === 'true', 'e o clique seguinte escolhe a célula');
  if (await cell(dlg, 'b.png').getAttribute('aria-pressed') === 'true') { await clickB(); await p.waitForTimeout(150); }
  // C6: arrasto preso (sem dragend) depois de sair do sítio: um clique no fundo não fecha o diálogo e devolve-o
  await cell(dlg, 'b.png').evaluate(el => {
    const r = el.getBoundingClientRect(), x = r.x + r.width / 2, y = r.y + r.height / 3, dt = new DataTransfer();
    el.dispatchEvent(new DragEvent('dragstart', { bubbles: true, cancelable: true, clientX: x, clientY: y, dataTransfer: dt }));
    el.dispatchEvent(new DragEvent('dragover', { bubbles: true, cancelable: true, clientX: x + 120, clientY: y + 60, dataTransfer: dt }));
  });
  await p.waitForTimeout(150);
  check(await p.evaluate(() => document.querySelector('.cc-dpick-back').classList.contains('is-dragging')), 'arrasto começado (is-dragging)');
  const d6 = await dlg.boundingBox();
  await p.mouse.click(Math.round(vp.x + 30), Math.round(d6.y + d6.height + 30));
  await p.waitForTimeout(300);
  check(await p.locator('.cc-dpick').count() === 1, 'um clique durante o arrasto não fecha o diálogo');
  check(await p.evaluate(() => { const bk = document.querySelector('.cc-dpick-back'); return !!bk && !bk.classList.contains('is-dragging') && getComputedStyle(bk).pointerEvents === 'auto'; }), 'e o diálogo volta (sem is-dragging)');
  check(await cards() === n0 + 3, 'e nada entrou');
  await p.keyboard.press('Escape');

  // C5: da página da Drive (outro separador): o que a Drive põe no arrasto
  const dp = await ctx.newPage();
  await dp.goto(BASE + '/admin/drive.php?p=' + encodeURIComponent(S.dir));
  await dp.waitForSelector('.dr-row', { timeout: 10000 }).catch(() => {});
  const payload = await dp.locator('.dr-row', { hasText: T + '-n.txt' }).first().evaluate(el => {
    const dt = new DataTransfer();
    el.dispatchEvent(new DragEvent('dragstart', { bubbles: true, cancelable: true, dataTransfer: dt }));
    const out = { types: Array.from(dt.types), keys: dt.getData('application/x-td-keys') };
    el.dispatchEvent(new DragEvent('dragend', { bubbles: true, dataTransfer: dt }));
    return out;
  }).catch(e => ({ err: String(e) }));
  check(payload.types && payload.types.includes('application/x-td-keys') && JSON.parse(payload.keys || '[]')[0] === S.k[T + '-n.txt'], 'a Drive põe application/x-td-keys no arrasto (' + JSON.stringify(payload) + ')');
  await dp.close();
  const dropKeys = (keys, x, y) => p.evaluate(([k, x, y]) => {
    const dt = new DataTransfer(); dt.setData('application/x-td-keys', JSON.stringify(k));
    const t = document.querySelector('.ccb-vp');
    const ov = new DragEvent('dragover', { bubbles: true, cancelable: true, clientX: x, clientY: y, dataTransfer: dt });
    t.dispatchEvent(ov);
    t.dispatchEvent(new DragEvent('drop', { bubbles: true, cancelable: true, clientX: x, clientY: y, dataTransfer: dt }));
    return ov.defaultPrevented;
  }, [keys, x, y]);
  await noToasts();
  check(await dropKeys([S.k[T + '-n.txt'], S.dir], to2.x, to2.y), 'o Topperboard aceita o arrasto da Drive');
  check(await waitCards(n0 + 4) === n0 + 4, 'o ficheiro da Drive entra (+1 cartão)');
  await p.waitForTimeout(300);
  let tst = (await p.locator('.tst').allInnerTexts()).join(' | ');
  check(/As pastas não entram: abra a pasta e escolha os ficheiros\./.test(tst) && !/sem acesso/.test(tst), 'a pasta é dita à parte (' + tst + ')');
  // mais de 30 (ex.: 31 escolhidos na Drive): entram todos, em pedidos de 30
  await noToasts();
  const many = Array.from({ length: 30 }, (_, i) => S.dir + 'falta-' + i + '.txt').concat([S.k[T + '-x.pdf']]);
  await dropKeys(many, to2.x, to2.y);
  await p.waitForFunction(() => document.querySelectorAll('.tst').length > 0, null, { timeout: 8000 }).catch(() => {});
  tst = (await p.locator('.tst').allInnerTexts()).join(' | ');
  // o 31.º fica 8 linhas abaixo (fora da vista, por isso conta-se na base de dados)
  const pdfCards = php(`echo $pdo->query("SELECT COUNT(*) FROM cc_cards c JOIN cc_assets a ON a.id = c.asset_id WHERE c.board_id = ${S.b} AND c.deleted_at IS NULL AND a.file_key = 'r2:${S.k[T + '-x.pdf']}'")->fetchColumn();`);
  check(pdfCards === '1', 'com 31 chaves a 31.ª também entra (pedidos de 30) (' + pdfCards + ')');
  check(/1 ficheiro da Drive no Topperboard\. 30 sem acesso ou já não existem\./.test(tst) && !/ficaram de fora/.test(tst), 'uma só mensagem com as contas somadas (' + tst + ')');
  // teto de 120 chaves num arrasto (centenas de chaves de outra página eram centenas de pedidos em série): 125 → só as
  // primeiras 120, em 4 pedidos de 30, e a mensagem diz quantas ficaram de fora
  await noToasts();
  const addReqs = [], onReq = rq => { if (/[?&]a=drive_add\b/.test(rq.url())) addReqs.push(rq.url()); };
  p.on('request', onReq);
  await dropKeys([S.k[T + '-s.mp3']].concat(Array.from({ length: 124 }, (_, i) => S.dir + 'nada-' + i + '.txt')), to2.x, to2.y);
  await p.waitForFunction(() => document.querySelectorAll('.tst').length > 0, null, { timeout: 20000 }).catch(() => {});
  p.off('request', onReq);
  tst = (await p.locator('.tst').allInnerTexts()).join(' | ');
  check(addReqs.length === 4, '125 chaves: só as primeiras 120, em 4 pedidos (' + addReqs.length + ' pedidos)');
  check(/1 ficheiro da Drive no Topperboard\. 119 sem acesso ou já não existem\. Entram até 120 ficheiros de cada vez: 5 ficaram de fora\./.test(tst), 'a mensagem diz quantas ficaram de fora (' + tst + ')');

  // um arrasto com a lista vazia (outra página pode pôr qualquer coisa): o servidor responde, nada de aviso em branco
  await noToasts();
  await dropKeys([], to2.x, to2.y);
  await p.waitForFunction(() => /Nada para juntar/.test((document.querySelector('.cc-up') || {}).textContent || ''), null, { timeout: 5000 }).catch(() => {});
  const up = await p.evaluate(() => ((document.querySelector('.cc-up .err') || {}).textContent || ''));
  const blank = await p.evaluate(() => Array.from(document.querySelectorAll('.tst')).filter(t => !t.textContent.trim()).length);
  check(/Nada para juntar\./.test(up) && blank === 0, 'lista vazia: «Nada para juntar.» na barra de envios, sem aviso em branco (' + JSON.stringify(up) + ', ' + blank + ')');

  check(errs.length === 0, 'sem erros na página (' + errs.join(' | ') + ')');
  await b.close();
  console.log(fail ? `FALHOU ${fail} de ${ok + fail}` : `OK ${ok}`);
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
