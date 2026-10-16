/**
 * topper. admin <-> Google Sheet
 *
 * Web app que deixa o admin do site (topperdigital.xyz/admin) ler e escrever
 * nesta folha. Cada separador é uma tabela: a linha 1 tem os títulos e cada
 * linha seguinte é um registo. As colunas cujo título é uma fórmula
 * (ARRAYFORMULA) são calculadas pela folha e nunca são escritas pelo site.
 *
 * Instalação: Extensões > Apps Script, colar este ficheiro, Guardar,
 * Implementar > Nova implementação > Aplicação Web
 *   Executar como: Eu   ·   Quem tem acesso: Qualquer pessoa
 * O TOKEN abaixo é a palavra-passe partilhada com o site: não a divulgue.
 */
const TOKEN = '__TOKEN__';

const TABS = ['Trabalhos', 'Equipa', 'Custos empresa', 'Leads'];

function doGet(e)  { return handle_(e.parameter || {}); }
function doPost(e) {
  let body = {};
  try { body = JSON.parse((e.postData && e.postData.contents) || '{}'); } catch (err) {}
  return handle_(body);
}

function handle_(p) {
  if (!p || p.token !== TOKEN) return json_({ ok: false, error: 'token' });
  try {
    if (p.action === 'read') return json_({ ok: true, tabs: readAll_(), at: new Date().toISOString() });
    const lock = LockService.getScriptLock();
    lock.waitLock(15000);
    try {
      if (p.action === 'update') return json_({ ok: true, row: update_(p) });
      if (p.action === 'append') return json_({ ok: true, row: append_(p) });
      if (p.action === 'delete') { delete_(p); return json_({ ok: true }); }
    } finally {
      SpreadsheetApp.flush();
      lock.releaseLock();
    }
    return json_({ ok: false, error: 'action' });
  } catch (err) {
    return json_({ ok: false, error: String(err && err.message || err) });
  }
}

function json_(o) {
  return ContentService.createTextOutput(JSON.stringify(o)).setMimeType(ContentService.MimeType.JSON);
}

// ── leitura ────────────────────────────────────────────────

function sheet_(name) {
  if (TABS.indexOf(name) < 0) throw new Error('Separador não permitido: ' + name);
  const sh = SpreadsheetApp.getActive().getSheetByName(name);
  if (!sh) throw new Error('Separador em falta: ' + name);
  return sh;
}

/** Títulos da linha 1 e quais são calculados (fórmula no título). */
function header_(sh) {
  const width = sh.getLastColumn();
  const r = sh.getRange(1, 1, 1, width);
  const formulas = r.getFormulas()[0];
  const shown = r.getDisplayValues()[0];
  const names = shown.map(function (v, i) {
    if (!formulas[i]) return String(v).trim();
    // ={"Título"; ARRAYFORMULA(...)}  ->  Título
    const m = formulas[i].match(/^=\s*\{\s*"([^"]+)"/);
    return m ? m[1] : String(v).trim();
  });
  return { names: names, computed: formulas.map(function (f) { return !!f; }) };
}

function cell_(v) {
  if (v instanceof Date) return Utilities.formatDate(v, Session.getScriptTimeZone(), 'yyyy-MM-dd');
  return v;
}

/** Célula vazia. Uma caixa de seleção por marcar (FALSE) também conta como vazia. */
function blank_(v) { return v === '' || v === null || v === false; }

/** Última linha com dados nas colunas que não são calculadas. */
function lastDataRow_(sh, h) {
  const last = sh.getLastRow();
  if (last < 2) return 1;
  const vals = sh.getRange(2, 1, last - 1, h.names.length).getValues();
  for (let i = vals.length - 1; i >= 0; i--) {
    for (let c = 0; c < h.names.length; c++) {
      if (!h.computed[c] && !blank_(vals[i][c])) return i + 2;
    }
  }
  return 1;
}

function readTab_(name) {
  const sh = sheet_(name);
  const h = header_(sh);
  const last = lastDataRow_(sh, h);
  const rows = [];
  if (last >= 2) {
    const vals = sh.getRange(2, 1, last - 1, h.names.length).getValues();
    vals.forEach(function (r, i) {
      const empty = r.every(function (v, c) { return h.computed[c] || blank_(v); });
      if (empty) return;
      const o = { _row: i + 2 };
      h.names.forEach(function (n, c) { if (n) o[n] = cell_(r[c]); });
      rows.push(o);
    });
  }
  return { header: h.names, computed: h.computed, rows: rows };
}

function readAll_() {
  const out = {};
  TABS.forEach(function (t) { out[t] = readTab_(t); });
  return out;
}

// ── escrita ────────────────────────────────────────────────

/** Garante que a linha ainda é a que o site leu (a folha pode ter mudado). */
function check_(sh, h, row, match) {
  if (!row || row < 2) throw new Error('Linha inválida.');
  Object.keys(match || {}).forEach(function (k) {
    const c = h.names.indexOf(k);
    if (c < 0) throw new Error('Coluna desconhecida: ' + k);
    const now = sh.getRange(row, c + 1).getValue();
    if (String(cell_(now)).trim() !== String(match[k]).trim()) {
      throw new Error('A folha mudou entretanto. Recarregue a página e tente outra vez.');
    }
  });
}

function write_(sh, h, row, values) {
  Object.keys(values || {}).forEach(function (k) {
    const c = h.names.indexOf(k);
    if (c < 0) throw new Error('Coluna desconhecida: ' + k);
    if (h.computed[c]) return;               // calculada pela folha
    sh.getRange(row, c + 1).setValue(values[k] === null ? '' : values[k]);
  });
}

function update_(p) {
  const sh = sheet_(p.tab), h = header_(sh);
  check_(sh, h, Number(p.row), p.match);
  const row = Number(p.row);
  // Mudar o nome de um trabalho leva a equipa com ele.
  if (p.tab === 'Trabalhos' && p.values && p.values['Nome'] !== undefined) {
    const old = String(sh.getRange(row, h.names.indexOf('Nome') + 1).getValue());
    if (old && old !== p.values['Nome']) renameCrew_(old, String(p.values['Nome']));
  }
  write_(sh, h, row, p.values);
  return row;
}

function append_(p) {
  const sh = sheet_(p.tab), h = header_(sh);
  const row = lastDataRow_(sh, h) + 1;
  if (row > sh.getMaxRows()) sh.insertRowsAfter(sh.getMaxRows(), 50);
  write_(sh, h, row, p.values);
  return row;
}

function delete_(p) {
  const sh = sheet_(p.tab), h = header_(sh);
  const row = Number(p.row);
  check_(sh, h, row, p.match);
  if (p.tab === 'Trabalhos') {
    const name = String(sh.getRange(row, h.names.indexOf('Nome') + 1).getValue());
    if (name) deleteCrew_(name);
  }
  sh.deleteRow(row);
}

function crewRows_() {
  const sh = sheet_('Equipa'), h = header_(sh);
  const c = h.names.indexOf('Trabalho');
  const last = lastDataRow_(sh, h);
  const vals = last >= 2 ? sh.getRange(2, c + 1, last - 1, 1).getValues() : [];
  return { sh: sh, col: c + 1, vals: vals };
}

function renameCrew_(from, to) {
  const x = crewRows_();
  x.vals.forEach(function (v, i) { if (String(v[0]) === from) x.sh.getRange(i + 2, x.col).setValue(to); });
}

function deleteCrew_(name) {
  const x = crewRows_();
  for (let i = x.vals.length - 1; i >= 0; i--) {
    if (String(x.vals[i][0]) === name) x.sh.deleteRow(i + 2);
  }
}
