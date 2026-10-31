// Editar uma nota no ponto do clique (§33.10): duplo clique a meio de «exemplo» põe o cursor aí; o mesmo com um
// segundo clique numa nota já selecionada.   node tests/ui/cc_note_caret_test.js
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');
const BASE = 'http://127.0.0.1:8765';
let ok = 0, fail = 0;
const check = (c, msg) => { if (c) ok++; else { fail++; console.error('FALHA: ' + msg); } };
const BID = execFileSync('php', [path.join(__dirname, 'seed_board.php')]).toString().trim();
const note = () => JSON.parse(execFileSync('php', ['-r', `require "tests/boot.php"; echo $pdo->query("SELECT content FROM cc_cards WHERE board_id=${BID} AND type='note' AND deleted_at IS NULL")->fetchColumn();`]).toString()).text;

(async () => {
  const b = await chromium.launch();
  const p = await b.newPage({ viewport: { width: 1280, height: 900 } });
  await p.goto(BASE + '/admin/__as/1');
  await p.goto(BASE + '/admin/toppernote.php?b=' + BID);
  await p.waitForSelector('.ccc.t-note .ccc-text');
  await p.waitForTimeout(300);
  // o ponto entre «exem» e «plo» no ecrã
  const pt = async () => p.evaluate(() => {
    const el = document.querySelector('.ccc.t-note .ccc-text'), tn = el.firstChild, i = tn.textContent.indexOf('exem') + 4;   // entre «exem» e o resto
    const r = document.createRange(); r.setStart(tn, i); r.setEnd(tn, i + 1); const b = r.getBoundingClientRect();
    return { x: b.left + 0.5, y: b.top + b.height / 2 };
  });
  let q = await pt();
  await p.mouse.dblclick(q.x, q.y); await p.waitForTimeout(250);
  await p.keyboard.type('X'); await p.keyboard.press('Escape'); await p.waitForTimeout(900);
  check(note() === 'Um exemXplo de nota', 'duplo clique a meio da palavra escreve nesse sítio (' + note() + ')');
  // segundo clique numa nota já selecionada (sem arrastar) também edita no sítio
  await p.keyboard.press('Escape');
  q = await pt();
  await p.mouse.click(q.x, q.y); await p.waitForTimeout(600);
  q = await pt();
  await p.mouse.click(q.x, q.y); await p.waitForTimeout(250);
  const editing = await p.evaluate(() => document.activeElement && document.activeElement.classList.contains('ccc-text') && document.activeElement.isContentEditable);
  check(editing, 'segundo clique numa nota selecionada põe-na a editar');
  await p.keyboard.type('Y'); await p.keyboard.press('Escape'); await p.waitForTimeout(900);
  check(note() === 'Um exemYXplo de nota', 'e o cursor fica onde se clicou (' + note() + ')');
  // arrastar uma nota selecionada continua a movê-la (não entra em edição)
  await p.keyboard.press('Escape'); await p.waitForTimeout(150);   // tira a seleção: o próximo clique só seleciona
  const box = await p.locator('.ccc.t-note').boundingBox();
  await p.mouse.click(box.x + 20, box.y + box.height - 6); await p.waitForTimeout(600);
  await p.mouse.move(box.x + 20, box.y + box.height - 6); await p.mouse.down(); await p.mouse.move(box.x + 80, box.y + box.height + 30, { steps: 5 }); await p.mouse.up();
  await p.waitForTimeout(400);
  check(!(await p.evaluate(() => document.activeElement && document.activeElement.isContentEditable)), 'arrastar a nota selecionada não a põe a editar');
  await b.close();
  console.log(fail ? `FALHOU ${fail} de ${ok + fail}` : `OK ${ok}`);
  process.exit(fail ? 1 : 0);
})();
