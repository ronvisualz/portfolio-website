/*
 * topper. admin — ficha central do negócio (docs/ESPECIFICACAO-PLATAFORMA.md §15)
 *
 * Pouco a fazer aqui: os cartões abrem o painel lateral da aba de origem pelo
 * próprio app.js (a[data-panel]); este ficheiro só abre os formulários por cima
 * (checklist de fecho, nova tarefa) a partir do botão que os chama — com a
 * animação da §13.8 — e fecha o menu «Mais» ao clicar fora ou com Esc.
 */
(function boot() {
  'use strict';
  if (!window.topper) { document.addEventListener('DOMContentLoaded', boot, { once: true }); return; }
  if (window.topperDeal) return;
  window.topperDeal = true;
  var T = window.topper;

  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('[data-dmdl]');
    if (b) {
      var m = document.querySelector(b.getAttribute('data-dmdl'));
      if (m) { e.preventDefault(); T.openMdl(m, b); }
      return;
    }
    document.querySelectorAll('details.dmore[open]').forEach(function (d) { if (!d.contains(e.target)) d.open = false; });
  });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    document.querySelectorAll('details.dmore[open]').forEach(function (d) { d.open = false; var s = d.querySelector('summary'); if (s) s.focus(); });
  });
  // Escolher uma opção do menu «Mais» que abre o painel fecha o menu.
  document.addEventListener('click', function (e) {
    var a = e.target.closest && e.target.closest('.dmore-m a');
    if (a) { var d = a.closest('details'); if (d) d.open = false; }
  }, true);
})();
