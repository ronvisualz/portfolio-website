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
    if (String(p.action || '').indexOf('drive_') === 0) return json_(drive_(p));
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

// ── Google Drive ───────────────────────────────────────────
//
// Pasta «Topper Digital» e tudo o que está dentro dela. Nenhum pedido
// chega a ficheiros fora desta pasta: cada id é confirmado subindo pelos
// pais até à raiz (inside_). Precisa de autorização para o Drive: depois
// de colar esta versão, correr uma vez a função autorizarDrive no editor.

const ROOT_ID = '11mgqHYd1oqLvOMQdj6C0R-Mgtn9WNXNF';
const PROJECTS_ID = '1p7VKCw1iZSpb4xFTugX1wzyVLsfSho-a';   // 02 Projetos
const PROJECT_SUBFOLDERS = ['Vídeos', 'Faturas', 'Documentos'];
const DRIVE_MAX_BYTES = 25 * 1024 * 1024;
const FOLDER_MIME = 'application/vnd.google-apps.folder';

/** Correr à mão uma vez, no editor, para dar acesso ao Drive. */
function autorizarDrive() { return DriveApp.getFolderById(ROOT_ID).getName(); }

function drive_(p) {
  const a = p.action;
  if (a === 'drive_list') return driveList_(p);
  const write = ['drive_mkdir', 'drive_upload', 'drive_project', 'drive_trash'];
  if (write.indexOf(a) < 0) return { ok: false, error: 'action' };
  const lock = LockService.getScriptLock();
  lock.waitLock(20000);
  try {
    if (a === 'drive_mkdir')   return driveMkdir_(p);
    if (a === 'drive_upload')  return driveUpload_(p);
    if (a === 'drive_project') return driveProject_(p);
    return driveTrash_(p);
  } finally {
    lock.releaseLock();
  }
}

function validId_(id) { return typeof id === 'string' && /^[A-Za-z0-9_-]{10,100}$/.test(id); }

/** Verdadeiro se o item (pasta ou ficheiro) está dentro da raiz (ou é a raiz). */
function inside_(item) {
  if (item.getId() === ROOT_ID) return true;
  const seen = {};
  let queue = [item];
  for (let depth = 0; depth < 30 && queue.length; depth++) {
    const next = [];
    for (let i = 0; i < queue.length; i++) {
      const it = queue[i].getParents();
      while (it.hasNext()) {
        const f = it.next(), id = f.getId();
        if (id === ROOT_ID) return true;
        if (!seen[id]) { seen[id] = true; next.push(f); }
      }
    }
    queue = next;
  }
  return false;
}

function folder_(id) {
  if (!id) id = ROOT_ID;
  if (!validId_(id)) throw new Error('Pasta inválida.');
  let f;
  try { f = DriveApp.getFolderById(id); } catch (e) { throw new Error('Pasta não encontrada.'); }
  if (f.isTrashed() || !inside_(f)) throw new Error('Pasta fora da pasta Topper Digital.');
  return f;
}

function cleanName_(n) {
  n = String(n || '').replace(/[\u0000-\u001f\/\\]/g, ' ').replace(/\s+/g, ' ').trim();
  if (!n) throw new Error('Falta o nome.');
  if (n.length > 200) n = n.slice(0, 200);
  return n;
}

function folderInfo_(f) {
  return { id: f.getId(), name: f.getName(), url: f.getUrl(), root: f.getId() === ROOT_ID };
}

/** Do item até à raiz (raiz primeiro). */
function breadcrumb_(f) {
  const out = [];
  let cur = f;
  for (let i = 0; i < 30 && cur; i++) {
    out.unshift({ id: cur.getId(), name: cur.getName() });
    if (cur.getId() === ROOT_ID) break;
    const it = cur.getParents();
    let up = null;
    while (it.hasNext()) { const x = it.next(); if (inside_(x)) { up = x; break; } }
    cur = up;
  }
  return out;
}

function driveList_(p) {
  const f = folder_(p.folder);
  const kids = [];
  const fo = f.getFolders();
  while (fo.hasNext() && kids.length < 1000) {
    const x = fo.next();
    kids.push({ id: x.getId(), name: x.getName(), mimeType: FOLDER_MIME, size: 0,
                modified: x.getLastUpdated().toISOString(), url: x.getUrl(), folder: true });
  }
  const fi = f.getFiles();
  const files = [];
  while (fi.hasNext() && files.length < 1000) {
    const x = fi.next();
    files.push({ id: x.getId(), name: x.getName(), mimeType: x.getMimeType(), size: x.getSize(),
                 modified: x.getLastUpdated().toISOString(), url: x.getUrl(), folder: false,
                 thumbnail: 'https://drive.google.com/thumbnail?id=' + x.getId() + '&sz=w200' });
  }
  const byName = function (a, b) { return a.name.localeCompare(b.name, 'pt'); };
  kids.sort(byName); files.sort(byName);
  return { ok: true, folder: folderInfo_(f), breadcrumb: breadcrumb_(f), children: kids.concat(files),
           max_bytes: DRIVE_MAX_BYTES };
}

function child_(parent, name) {
  const it = parent.getFoldersByName(name);
  return it.hasNext() ? it.next() : null;
}

function ensure_(parent, name) { return child_(parent, name) || parent.createFolder(name); }

function driveMkdir_(p) {
  const parent = folder_(p.parent);
  const name = cleanName_(p.name);
  if (child_(parent, name)) throw new Error('Já existe uma pasta chamada «' + name + '» aqui.');
  return { ok: true, folder: folderInfo_(parent.createFolder(name)) };
}

function driveUpload_(p) {
  const parent = folder_(p.parent);
  const name = cleanName_(p.name);
  const b64 = String(p.base64 || '');
  if (!b64) throw new Error('Ficheiro vazio.');
  if (b64.length * 3 / 4 > DRIVE_MAX_BYTES + 4) {
    throw new Error('Ficheiro demasiado grande (máximo 25 MB). Para vídeos grandes use «Abrir no Drive» e carregue lá.');
  }
  const bytes = Utilities.base64Decode(b64);
  if (bytes.length > DRIVE_MAX_BYTES) {
    throw new Error('Ficheiro demasiado grande (máximo 25 MB). Para vídeos grandes use «Abrir no Drive» e carregue lá.');
  }
  const mime = /^[\w.+-]+\/[\w.+-]+$/.test(String(p.mimeType || '')) ? p.mimeType : 'application/octet-stream';
  const file = parent.createFile(Utilities.newBlob(bytes, mime, name));
  return { ok: true, file: { id: file.getId(), name: file.getName(), url: file.getUrl(), size: file.getSize() } };
}

function driveProject_(p) {
  const name = cleanName_(p.name);
  const year = String(p.year || '').trim();
  if (!/^(19|20|21)\d\d$/.test(year)) throw new Error('Ano inválido.');
  const projects = folder_(PROJECTS_ID);
  const y = ensure_(projects, year);
  const f = ensure_(y, name);
  PROJECT_SUBFOLDERS.forEach(function (s) { ensure_(f, s); });
  return { ok: true, folder: folderInfo_(f) };
}

function driveTrash_(p) {
  const id = String(p.id || '');
  if (!validId_(id) || id === ROOT_ID) throw new Error('Item inválido.');
  let item = null;
  try { item = DriveApp.getFileById(id); } catch (e) {
    try { item = DriveApp.getFolderById(id); } catch (e2) { throw new Error('Item não encontrado.'); }
  }
  if (!inside_(item)) throw new Error('Item fora da pasta Topper Digital.');
  if ([PROJECTS_ID, '1Wk82jckEFqgZEXoARzZ9f0dQPE5P9jse', '1oAdV2liA57M6lAXiPiEY8mpSSvCHADr2',
       '1LVqXNwxfYx6fE-G7On9GIuYcXYCWffen'].indexOf(id) >= 0) {
    throw new Error('As pastas principais não podem ir para o lixo.');
  }
  item.setTrashed(true);
  return { ok: true };
}
