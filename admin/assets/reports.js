/* topper. admin — Relatórios (§29): cada número abre os registos por trás dele numa janela (sem JavaScript, abre a
   página da lista); «PDF» imprime o separador (Guardar como PDF), com o estilo de impressão próprio. */
(function () {
  'use strict';
  var mdl = document.getElementById('mdl-mx');
  if (mdl) {
    var opener = document.createElement('a');
    opener.setAttribute('data-new', '#mdl-mx'); opener.hidden = true;
    document.body.appendChild(opener);
    document.addEventListener('click', function (e) {
      var a = e.target.closest && e.target.closest('a[data-mx-list]');
      if (!a || e.metaKey || e.ctrlKey || e.shiftKey || e.button) return;
      e.preventDefault();
      var body = mdl.querySelector('.mx-mdl-body');
      body.innerHTML = '<p class="muted">A carregar…</p>';
      opener.click();
      fetch(a.href + '&frag=1', { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
        .then(function (r) { if (!r.ok) throw new Error(r.status); return r.text(); })
        .then(function (h) { body.innerHTML = h; var t = body.querySelector('h2'); if (t) { t.tabIndex = -1; t.focus(); } })
        .catch(function () { location.href = a.href; });
    });
  }
  var pr = document.querySelector('[data-mx-print]');
  if (pr) pr.addEventListener('click', function () { window.print(); });
  if (/[?&]imprimir=1/.test(location.search)) setTimeout(function () { window.print(); }, 400);
})();
