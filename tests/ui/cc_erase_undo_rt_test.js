// Anular depois da borracha com o tempo real ligado (§33.10): o traço volta e fica — o marcador de apagado do
// documento do tempo real não o volta a esconder (nem a quem abre o Topperboard a seguir); anular um traço acabado de
// fazer não deixa um cartão fantasma; um Anular carregado enquanto a borracha ainda grava espera por ela.
// A bancada não tem o serviço de tempo real: o teste troca o fornecedor (YProvider) por um falso que diz «ligado» e
// «sincronizado» e guarda o documento entre páginas (como o servidor e o IndexedDB); o resto é o código real (Yjs).
//   node tests/ui/cc_erase_undo_rt_test.js        (CC_BASE=http://127.0.0.1:8766 para outra bancada)
const { chromium } = require('playwright');
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

  check(!errs.length, 'sem erros na página (' + errs.join(' | ') + ')');
  await b.close();
  console.log(fail ? `FALHOU ${fail} de ${ok + fail}` : `OK ${ok}`);
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e); console.log('FALHOU (erro)'); process.exit(1); });
