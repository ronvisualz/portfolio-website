// Anular depois da borracha com o tempo real ligado (§33.10): o traço volta e fica — o marcador de apagado do
// documento do tempo real não o volta a esconder (nem a quem abre o Topperboard a seguir); anular um traço acabado de
// fazer não deixa um cartão fantasma; um Anular carregado enquanto a borracha ainda grava espera por ela — também o
// «Anular» da barra tocado no iPad quando a borracha é a 1.ª ação a anular (antes ficava desativado até à resposta).
// A bancada não tem o serviço de tempo real: o teste troca o fornecedor (YProvider) por um falso que diz «ligado» e
// «sincronizado» e guarda o documento entre páginas (como o servidor e o IndexedDB); o resto é o código real (Yjs).
//   node tests/ui/cc_erase_undo_rt_test.js        (CC_BASE=http://127.0.0.1:8766 para outra bancada)
const { chromium, devices } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');
const BASE = process.env.CC_BASE || 'http://127.0.0.1:8765', REPO = path.join(__dirname, '..', '..');
let ok = 0, fail = 0;
const check = (c, msg) => { if (c) ok++; else { fail++; console.error('FALHA: ' + msg); } };
const php = code => execFileSync('php', ['-r', 'require "tests/boot.php"; require_once "admin/inc/cc.php"; ' + code], { cwd: REPO }).toString();
const newBoard = t => +php(`echo cc_board_create($pdo, ['title' => 'Teste33 · anular borracha · ${t}', 'kind' => 'free'], 1);`).trim();
const alive = bid => JSON.parse(php(`echo json_encode($pdo->query("SELECT id FROM cc_cards WHERE board_id=${bid} AND type='drawing' AND deleted_at IS NULL ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));`)).map(Number);
const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);

// o fornecedor falso do tempo real (corre na página antes do cc_rt.js)
function fakeRealtime() {
  let real;
  const b64 = u => { let s = ''; for (let i = 0; i < u.length; i += 0x8000) s += String.fromCharCode.apply(null, u.subarray(i, i + 0x8000)); return btoa(s); };
  const unb64 = s => Uint8Array.from(atob(s), c => c.charCodeAt(0));
  Object.defineProperty(window, 'TopperRT', { configurable: true, get() { return real; }, set(v) {
    const Y = v.Y;
    function Fake(host, room, doc) {
      const ls = {}, key = 'fake-rt-' + room;
      this.on = (k, f) => { (ls[k] = ls[k] || []).push(f); };
      const emit = (k, a) => (ls[k] || []).forEach(f => f(a));
      this.sendMessage = () => {}; this.destroy = () => {};
      this.awareness = { clientID: 1, setLocalState() {}, setLocalStateField() {}, getStates: () => new Map(), on() {}, off() {} };
      try { const s = localStorage.getItem(key); if (s) Y.applyUpdate(doc, unb64(s)); } catch (e) {}
      doc.on('update', () => { try { localStorage.setItem(key, b64(Y.encodeStateAsUpdate(doc))); } catch (e) {} });
      window.__rtDoc = doc;
      setTimeout(() => { emit('status', { status: 'connected' }); emit('sync', true); }, 50);
    }
    real = Object.assign({}, v, { YProvider: Fake, IndexeddbPersistence: function () {} });
  } });
}

(async () => {
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: 1280, height: 900 } });
  await ctx.addInitScript(fakeRealtime);
  const p0 = await ctx.newPage();
  await p0.goto(BASE + '/admin/__as/1');
  await p0.close();
  const errs = [];

  const open = async bid => {
    const p = await ctx.newPage();
    p.on('pageerror', e => errs.push(e.message));
    await p.goto(BASE + '/admin/toppernote.php?b=' + bid);
    await p.waitForSelector('.ccb-vp');
    const live = await p.waitForFunction(() => /Ligado/.test((document.querySelector('.rt-st') || {}).textContent || ''), null, { timeout: 8000 }).then(() => true).catch(() => false);
    check(live, 'o tempo real (falso) está «Ligado» — sem isto o teste não provava nada');
    await p.waitForTimeout(300);
    return p;
  };
  const dom = p => p.$$eval('.ccc.t-drawing', es => es.map(e => +e.dataset.id).sort((a, b) => a - b));
  const resp = (p, a) => p.waitForResponse(r => r.url().includes('a=' + a + '&') || r.url().endsWith('a=' + a), { timeout: 10000 });
  // uma ação que grava e recarrega o Topperboard (Anular, Refazer, Delete): espera pelas duas respostas
  const act = async (p, a, fn) => {
    const r = resp(p, a).catch(() => null), bd = p.waitForResponse(r => /a=board\b/.test(r.url()), { timeout: 10000 }).catch(() => null);
    await fn(); await r; await bd; await p.waitForTimeout(400);
  };
  const origin = async p => { const vp = await p.locator('.ccb-vp').boundingBox(); return { X: vp.x + 320, Y: vp.y + 260 }; };
  const stroke = async (p, X, Y) => {
    const r = resp(p, 'card_add');
    await p.mouse.move(X, Y); await p.mouse.down();
    for (let i = 1; i <= 10; i++) await p.mouse.move(X + i * 15, Y);
    await p.mouse.up();
    await r; await p.waitForTimeout(150);
  };
  // dois traços (P), à espera de mais do que a tolerância do tempo real (2 s), e a borracha (E) a cortar o 1.º
  const twoThenErase = async (p, bid) => {
    const { X, Y } = await origin(p);
    await p.keyboard.press('p'); await p.waitForTimeout(150);
    await stroke(p, X, Y); await stroke(p, X, Y + 120);
    const ids = alive(bid);
    check(ids.length === 2, 'dois traços desenhados (' + ids + ')');
    await p.waitForTimeout(2600);
    await p.keyboard.press('e'); await p.waitForTimeout(150);
    await p.mouse.move(X + 75, Y - 30); await p.mouse.down(); await p.mouse.move(X + 75, Y + 30, { steps: 8 }); await p.mouse.up();
    return { ids, X, Y };
  };
  const marker = (p, id) => p.evaluate(id => { const m = window.__rtDoc && window.__rtDoc.getMap('cards').get(String(id)); return m ? m.toJSON().del : null; }, id);

  // ── 1. borracha → botão Anular → o traço volta e fica; Refazer logo a seguir não o deixa fantasma ──
  {
    const bid = newBoard('botão');
    const p = await open(bid);
    const { ids } = await twoThenErase(p, bid);
    await resp(p, 'cards_delete'); await p.waitForTimeout(500);
    check(same(alive(bid), [ids[1]]) && same(await dom(p), [ids[1]]), 'a borracha apaga o 1.º traço (base ' + alive(bid) + ', ecrã ' + await dom(p) + ')');
    check(await marker(p, ids[0]) === true, 'o tempo real marca o traço apagado');
    await act(p, 'cards_restore', () => p.locator('[data-undo="0"]').click());
    check(same(alive(bid), ids), 'Anular repõe o traço na base (' + alive(bid) + ')');
    check(same(await dom(p), ids), 'e no ecrã: o tempo real não o volta a esconder (ecrã ' + await dom(p) + ', base ' + alive(bid) + ')');
    check(await marker(p, ids[0]) === false, 'o documento do tempo real deixa de o marcar como apagado');
    await act(p, 'cards_delete', () => p.locator('[data-undo="1"]').click());
    check(same(alive(bid), [ids[1]]) && same(await dom(p), [ids[1]]), 'Refazer volta a apagar, sem fantasma no ecrã (base ' + alive(bid) + ', ecrã ' + await dom(p) + ')');
    await act(p, 'cards_restore', () => p.keyboard.press('Control+z'));
    check(same(alive(bid), ids) && same(await dom(p), ids), 'Anular outra vez repõe (ecrã ' + await dom(p) + ', base ' + alive(bid) + ')');
    await p.waitForTimeout(500);
    const f = await open(bid);
    check(same(await dom(f), alive(bid)) && alive(bid).length === 2, 'quem abre o Topperboard a seguir vê os dois traços (ecrã ' + await dom(f) + ', base ' + alive(bid) + ')');
    await f.close(); await p.close();
  }

  // ── 2. o mesmo com Ctrl Z ──
  {
    const bid = newBoard('ctrl z');
    const p = await open(bid);
    const { ids } = await twoThenErase(p, bid);
    await resp(p, 'cards_delete'); await p.waitForTimeout(500);
    await act(p, 'cards_restore', () => p.keyboard.press('Control+z'));
    check(same(alive(bid), ids) && same(await dom(p), ids), 'Ctrl Z depois da borracha: o traço volta e fica (ecrã ' + await dom(p) + ', base ' + alive(bid) + ')');
    const f = await open(bid);
    check(same(await dom(f), ids), 'e quem abre a seguir também o vê (ecrã ' + await dom(f) + ')');
    await f.close(); await p.close();
  }

  // ── 3. apagar com a tecla Delete → «Anular» do aviso ──
  {
    const bid = newBoard('aviso');
    const p = await open(bid);
    const { X, Y } = await origin(p);
    await p.keyboard.press('p'); await p.waitForTimeout(150);
    await stroke(p, X, Y); await stroke(p, X, Y + 120);
    const ids = alive(bid);
    await p.waitForTimeout(2600);
    await p.keyboard.press('v'); await p.waitForTimeout(150);
    await p.locator(`.ccc[data-id="${ids[0]}"]`).click(); await p.waitForTimeout(200);
    await act(p, 'cards_delete', () => p.keyboard.press('Delete'));
    check(same(await dom(p), [ids[1]]), 'Delete apaga o traço selecionado (ecrã ' + await dom(p) + ')');
    await act(p, 'cards_restore', () => p.locator('.tst button.undo').first().click());
    check(same(alive(bid), ids) && same(await dom(p), ids), '«Anular» do aviso: o traço volta e fica (ecrã ' + await dom(p) + ', base ' + alive(bid) + ')');
    check(!(await p.locator('[data-undo="1"]').isDisabled()), 'o «Anular» do aviso passa a apagar para o Refazer (como o Ctrl Z)');
    // a seguir, Ctrl Z anula a ação anterior (o 2.º traço) — sem o falso «outra pessoa mudou este cartão»
    await act(p, 'cards_delete', () => p.keyboard.press('Control+z'));
    check(!/não anulada/.test(await p.locator('body').innerText()), 'Ctrl Z depois do «Anular» do aviso não diz «alteração não anulada»');
    check(same(alive(bid), [ids[0]]) && same(await dom(p), [ids[0]]), 'e anula o 2.º traço (ecrã ' + await dom(p) + ', base ' + alive(bid) + ')');
    await p.close();
  }

  // ── 4. desenhar e anular logo: sem cartão fantasma ──
  {
    const bid = newBoard('fantasma');
    const p = await open(bid);
    const { X, Y } = await origin(p);
    await p.keyboard.press('p'); await p.waitForTimeout(150);
    await stroke(p, X, Y);
    const ids = alive(bid);
    await act(p, 'cards_delete', () => p.keyboard.press('Control+z'));
    check(alive(bid).length === 0, 'anular o traço apaga-o na base (' + alive(bid) + ')');
    check((await dom(p)).length === 0, 'e no ecrã não fica um fantasma (ecrã ' + await dom(p) + ', era ' + ids + ')');
    check(await marker(p, ids[0]) === true, 'o tempo real marca-o como apagado');
    const f = await open(bid);
    check((await dom(f)).length === 0, 'quem abre a seguir também não o vê (ecrã ' + await dom(f) + ')');
    await f.close(); await p.close();
  }

  // ── 5. Anular enquanto a borracha ainda grava (pedido lento): espera por ela e anula a borracha, não o traço anterior ──
  {
    const bid = newBoard('pedido lento');
    const p = await open(bid);
    await p.route(/cc_api\.php\?a=cards_delete/, async route => { await new Promise(r => setTimeout(r, 700)); await route.continue(); });
    const { ids } = await twoThenErase(p, bid);
    await p.waitForTimeout(150);
    await p.keyboard.press('Control+z');
    await p.waitForTimeout(3500);
    check(same(alive(bid), ids), 'os dois traços ficam na base (' + alive(bid) + ')');
    check(same(await dom(p), ids), 'e no ecrã (' + await dom(p) + ')');
    check(!(await p.locator('[data-undo="1"]').isDisabled()), 'Refazer fica ativo (a borracha foi para o Refazer)');
    await p.close();
  }

  // ── 5b. duas esperas seguidas: a rede de segurança de 10 s da 1.ª não solta a 2.ª antes de a borracha responder ──
  {
    const bid = newBoard('duas esperas');
    const p = await open(bid);
    let delay = 700;
    await p.route(/cc_api\.php\?a=cards_delete/, async route => { await new Promise(r => setTimeout(r, delay)); await route.continue(); });
    const { X, Y } = await origin(p);
    await p.keyboard.press('p'); await p.waitForTimeout(150);
    await stroke(p, X, Y); await stroke(p, X, Y + 120); await stroke(p, X, Y + 240);
    const ids = alive(bid);
    check(ids.length === 3, 'três traços desenhados (' + ids + ')');
    const erase = async y => {
      if (!(await p.evaluate(() => document.querySelector('.ccb-vp').classList.contains('tool-erase')))) { await p.keyboard.press('e'); await p.waitForTimeout(150); }
      await p.mouse.move(X + 75, y - 30); await p.mouse.down(); await p.mouse.move(X + 75, y + 30, { steps: 8 }); await p.mouse.up();
      await p.waitForTimeout(150);
      await p.keyboard.press('Control+z');
    };
    const del1 = resp(p, 'cards_delete');
    await erase(Y + 240);   // 1.ª espera (700 ms)
    check(!!(await del1.catch(() => null)), 'a 1.ª borracha apaga (pedido lento)');
    const t1 = Date.now();
    await p.waitForTimeout(2500);
    check(same(alive(bid), ids), '1.ª borracha anulada (' + alive(bid) + ')');
    delay = 3500;
    await p.waitForTimeout(Math.max(0, 8500 - (Date.now() - t1)));
    const del2 = resp(p, 'cards_delete');
    await erase(Y + 120);   // 2.ª espera: a borracha só responde depois dos 10 s da 1.ª
    check(!!(await del2.catch(() => null)), 'a 2.ª borracha apaga (pedido lento)');
    await p.waitForTimeout(2000);
    check(same(alive(bid), ids) && same(await dom(p), ids), 'a 2.ª borracha também é anulada, sem apagar outro traço (ecrã ' + await dom(p) + ', base ' + alive(bid) + ')');
    await p.close();
  }

  // ── 6. cura (migração 42): um traço reposto antes da correção (vivo na base, mais antigo do que o marcador de
  //       apagado) está escondido; depois da migração aparece e o marcador é limpo ──
  {
    const bid = newBoard('cura');
    const p = await open(bid);
    const { ids } = await twoThenErase(p, bid);
    await resp(p, 'cards_delete'); await p.waitForTimeout(500);
    await p.close();
    php(`$pdo->exec("UPDATE cc_cards SET deleted_at = NULL, updated_at = '2020-01-01 00:00:00' WHERE id = ${ids[0]}");`);   // a reposição de antes
    const h = await open(bid);
    check(same(alive(bid), ids) && same(await dom(h), [ids[1]]), 'antes da cura: vivo na base, escondido pelo tempo real (ecrã ' + await dom(h) + ', base ' + alive(bid) + ')');
    await h.close();
    php('require_once "admin/inc/migrate_creative.php"; $pdo->exec("DELETE FROM settings WHERE skey = \'cc_rt_heal_42\'"); migrate_cc_42_rt_heal($pdo);');   // a cura corre uma vez por base: aqui recomeça
    const c = await open(bid);
    check(same(await dom(c), ids), 'depois da migração aparece (ecrã ' + await dom(c) + ')');
    check(await marker(c, ids[0]) === false, 'e o marcador de apagado do documento é limpo');
    await c.close();
  }

  // ── 7. iPad (revisão final): a borracha é a 1.ª ação a anular (traços de outra sessão) e o «Anular» da barra é tocado
  //       enquanto o cards_delete ainda não respondeu — o toque não se perde: espera pela borracha e o traço volta e fica ──
  {
    const bid = newBoard('iPad');
    const ids = [120, 320].map(y => +php(`echo cc_card_add($pdo, ${bid}, ['type' => 'drawing', 'x' => 40, 'y' => ${y}, 'w' => 320, 'h' => 100, 'content' => ['paths' => [['c' => '#111111', 'w' => 6, 'pts' => [10, 50, 310, 50]]], 'vw' => 320, 'vh' => 100]], 1);`).trim());
    const ictx = await b.newContext({ ...devices['iPad Pro 11'] });
    await ictx.addInitScript(fakeRealtime);
    const q0 = await ictx.newPage(); await q0.goto(BASE + '/admin/__as/1'); await q0.close();
    const openI = async () => {
      const p = await ictx.newPage();
      p.on('pageerror', e => errs.push(e.message));
      await p.goto(BASE + '/admin/toppernote.php?b=' + bid);
      await p.waitForSelector('.ccc.t-drawing');
      const live = await p.waitForFunction(() => /Ligado/.test((document.querySelector('.rt-st') || {}).textContent || ''), null, { timeout: 8000 }).then(() => true).catch(() => false);
      check(live, 'iPad: o tempo real (falso) está «Ligado»');
      await p.waitForTimeout(400);
      return p;
    };
    const p = await openI();
    const undoBtn = p.locator('[data-undo="0"]');
    check(same(await dom(p), ids) && await undoBtn.isDisabled(), 'iPad, prova do teste: dois traços de outra sessão e «Anular» desativado (pilha vazia) (ecrã ' + await dom(p) + ')');
    const DELAY = 1500;
    await p.route(/cc_api\.php\?a=cards_delete/, async route => { await new Promise(r => setTimeout(r, DELAY)); await route.continue(); });
    const cdp = await ictx.newCDPSession(p);
    const touch = (type, pts) => cdp.send('Input.dispatchTouchEvent', { type, touchPoints: pts.map(([x, y]) => ({ x, y, id: 1 })) });
    const tapAt = async loc => { const r = await loc.boundingBox(); await p.touchscreen.tap(r.x + r.width / 2, r.y + r.height / 2); await p.waitForTimeout(200); };
    await tapAt(p.locator('[data-tool="draw"]'));
    await tapAt(p.locator('.ccb-pen [data-pt="erase"]'));
    check(await p.evaluate(() => document.querySelector('.ccb-vp').classList.contains('tool-erase')), 'iPad: a borracha está escolhida (por toques)');
    const bb = await p.locator(`.ccc[data-id="${ids[0]}"]`).boundingBox();
    const cx = Math.round(bb.x + bb.width / 2);
    const del = resp(p, 'cards_delete');
    await touch('touchStart', [[cx, Math.round(bb.y + bb.height * 0.1)]]);
    for (let i = 1; i <= 8; i++) { await touch('touchMove', [[cx, Math.round(bb.y + bb.height * (0.1 + i * 0.1))]]); await p.waitForTimeout(16); }
    await touch('touchEnd', []);
    await p.waitForTimeout(250);
    const t0 = Date.now();
    const enabled = !(await undoBtn.isDisabled());
    check(enabled, 'iPad: enquanto a borracha grava, o «Anular» da barra está ativo (aceita o toque)');
    const restore = resp(p, 'cards_restore').catch(() => null);
    await tapAt(undoBtn);
    const early = Date.now() - t0 < DELAY - 300;
    const d = await del.catch(() => null);
    check(!!d && early, 'iPad, prova do teste: o toque em «Anular» chegou antes de o cards_delete responder (' + (Date.now() - t0) + ' ms)');
    check(!!(await restore), 'iPad: o toque em «Anular» repõe o traço (cards_restore) depois da borracha');
    await p.waitForTimeout(1200);
    check(same(alive(bid), ids), 'iPad: o traço volta na base (' + alive(bid) + ')');
    check(same(await dom(p), ids), 'iPad: e no ecrã fica (ecrã ' + await dom(p) + ')');
    check(await marker(p, ids[0]) === false, 'iPad: o tempo real deixa de o marcar como apagado');
    const f = await openI();
    check(same(await dom(f), ids), 'iPad: quem abre a seguir (tempo real) vê os dois traços (ecrã ' + await dom(f) + ')');
    await f.close(); await p.close(); await ictx.close();
  }

  check(!errs.length, 'sem erros na página (' + errs.join(' | ') + ')');
  await b.close();
  console.log(fail ? `FALHOU ${fail} de ${ok + fail}` : `OK ${ok}`);
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e); console.log('FALHOU (erro)'); process.exit(1); });
