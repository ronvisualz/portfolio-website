/* Guardar na topper. — o popup: destino, nota, «Guardar esta página», área e frame do vídeo. */
const $ = (s) => document.querySelector(s);
const send = (m) => new Promise((res) => chrome.runtime.sendMessage(m, (r) => res(r || { ok: false, error: 'Sem resposta.' })));
const fmt = (t) => { t = Math.floor(t || 0); const h = Math.floor(t / 3600), m = Math.floor(t / 60) % 60, s = t % 60; return (h ? h + ':' + String(m).padStart(2, '0') : m) + ':' + String(s).padStart(2, '0'); };
function status(msg, cls) { const el = $('#st'); el.textContent = msg; el.className = 'st ' + (cls || ''); }

function fill(boards, dest) {
  const sel = $('#dest');
  sel.innerHTML = '<option value="0">Caixa de entrada</option>' + boards.map((b) => '<option value="' + b.id + '">' + (b.code ? b.code + ' · ' : '') + b.title.replace(/</g, '&lt;') + '</option>').join('');
  if (dest > 0 && !boards.some((b) => b.id === dest)) dest = 0;
  sel.value = String(dest > 0 ? dest : 0);
}

(async () => {
  $('#opt').onclick = () => chrome.runtime.openOptionsPage();
  $('#keys').onclick = (e) => { e.preventDefault(); chrome.tabs.create({ url: 'chrome://extensions/shortcuts' }); };
  const s = await chrome.storage.local.get(['token', 'boards', 'dest', 'me', 'defaultBoard']);
  if (!s.token) { $('#setup').hidden = false; return; }
  $('#main').hidden = false;
  const def = s.dest > 0 ? s.dest : (s.defaultBoard ? s.defaultBoard.id : 0);
  fill(s.boards || [], def);
  if (s.me) $('#me').textContent = s.me.name;
  send({ k: 'refresh' }).then((r) => {
    if (!r.ok) { status(r.error, 'err'); if (r.code === 'token') $('#setup').hidden = false; return; }
    $('#me').textContent = r.r.user.name;
    fill(r.r.boards, +$('#dest').value || def);
  });
  send({ k: 'probe' }).then((r) => { if (r.ok && r.r && r.r.video) { const b = $('#frame'); b.hidden = false; b.textContent = 'Frame do vídeo (' + fmt(r.r.video.t) + ')'; } });
  let qt = 0;
  $('#q').addEventListener('input', (e) => {
    clearTimeout(qt);
    qt = setTimeout(async () => {
      const r = await send({ k: 'search', q: e.target.value.trim() });
      if (r.ok) fill(r.r.boards, +$('#dest').value);
    }, 250);
  });
  $('#dest').addEventListener('change', () => chrome.storage.local.set({ dest: +$('#dest').value }));
  document.querySelectorAll('[data-k]').forEach((b) => b.addEventListener('click', async () => {
    const k = b.getAttribute('data-k'), board = +$('#dest').value, note = $('#note').value.trim();
    document.querySelectorAll('[data-k]').forEach((x) => { x.disabled = true; });
    status(k === 'area' ? 'Arraste na página para escolher a área…' : 'A guardar…');
    if (k === 'area') { send({ k, board, note }); setTimeout(() => window.close(), 150); return; }
    const r = await send({ k, board, note });
    document.querySelectorAll('[data-k]').forEach((x) => { x.disabled = false; });
    if (!r.ok) { status(r.error, 'err'); return; }
    const j = r.r;
    status(j.msg, 'ok');
    const s2 = await chrome.storage.local.get(['server']);
    const server = (s2.server || self.TOPPER_SERVER || '').replace(/\/+$/, '');
    const a = document.createElement('a');
    a.href = server + (j.board ? j.board.url : j.inbox); a.target = '_blank'; a.textContent = ' Abrir';
    $('#st').appendChild(a);
    $('#note').value = '';
  }));
})();
