/* topper. admin — Dashboard Executivo (§30): o pódio anima uma vez quando um negócio entra nele ou bate o recorde
   (lembrado neste browser); o resto do Dashboard é desenhado no servidor. */
(function () {
  'use strict';
  var pod = document.querySelector('.podium[data-hof]');
  if (!pod) return;
  var id = pod.getAttribute('data-hof'), seen = '';
  try { seen = localStorage.getItem('hof-seen') || ''; } catch (x) { /* sem memória: anima sempre */ }
  if (seen === id || matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  pod.classList.add('celebrate');
  try { localStorage.setItem('hof-seen', id); } catch (x) { /* fica só nesta visita */ }
})();
