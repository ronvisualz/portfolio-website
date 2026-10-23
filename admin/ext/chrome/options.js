const $ = (s) => document.querySelector(s);
function status(m, c) { const el = $('#st'); el.textContent = m; el.className = 'st ' + (c || ''); }
(async () => {
  const s = await chrome.storage.local.get(['server', 'token']);
  $('#server').value = s.server || self.TOPPER_SERVER || '';
  if (s.token) $('#token').placeholder = 'tpk_… (guardado: …' + s.token.slice(-4) + ')';
  $('#keys').onclick = (e) => { e.preventDefault(); chrome.tabs.create({ url: 'chrome://extensions/shortcuts' }); };
  $('#save').onclick = async () => {
    const server = $('#server').value.trim().replace(/\/+$/, ''), token = $('#token').value.trim();
    if (!/^https:\/\/[^/]+$/.test(server) && !/^http:\/\/(127\.0\.0\.1|localhost)(:\d+)?$/.test(server)) { status('Escreva o endereço completo (https://…).', 'err'); return; }
    if (token && !/^tpk_[A-Za-z0-9_-]{30,60}$/.test(token)) { status('O token começa por «tpk_». Copie-o outra vez de Perfil › Ligações.', 'err'); return; }
    await chrome.storage.local.set(token ? { server, token } : { server });
    status('A testar…');
    chrome.runtime.sendMessage({ k: 'refresh' }, (r) => {
      if (r && r.ok) { status('Ligado como ' + r.r.user.name + '. Já pode usar o botão direito ou o atalho.', 'ok'); $('#token').value = ''; }
      else status((r && r.error) || 'Não foi possível ligar.', 'err');
    });
  };
  $('#clear').onclick = async () => { await chrome.storage.local.remove(['token', 'boards', 'me', 'defaultBoard']); $('#token').placeholder = 'tpk_…'; status('Desligada. O token continua ativo na plataforma até o revogar em Perfil › Ligações.'); };
})();
