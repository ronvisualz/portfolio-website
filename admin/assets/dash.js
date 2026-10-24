/* topper. admin — Dashboard Executivo (§30): o pódio anima uma vez quando um negócio entra nele ou bate o recorde
   (lembrado neste browser); trocar de período (mês, trimestre…) refaz só Resultados e Crescimento, sem recarregar
   a página nem mudar o sítio onde se está. O resto do Dashboard é desenhado no servidor. */
(function () {
  'use strict';
  var pod = document.querySelector('.podium[data-hof]');
  if (pod) {
    var id = pod.getAttribute('data-hof'), seen = '';
    try { seen = localStorage.getItem('hof-seen') || ''; } catch (x) { /* sem memória: anima sempre */ }
    if (seen !== id && !matchMedia('(prefers-reduced-motion: reduce)').matches) {
      pod.classList.add('celebrate');
      try { localStorage.setItem('hof-seen', id); } catch (x) { /* fica só nesta visita */ }
    }
  }

  // ── período: o servidor devolve só as duas secções (?frag=per), com as mesmas permissões da página ──
  var busy = null;
  document.addEventListener('click', function (e) {
    var a = e.target.closest ? e.target.closest('#resultados .views a[data-per]') : null;
    if (!a || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0 || !window.fetch) return;
    e.preventDefault();
    if (a.classList.contains('on')) return;
    var page = new URL(a.href, location.href); page.hash = '';
    var url = new URL(page.href); url.searchParams.set('frag', 'per');
    var secs = ['resultados', 'crescimento'].map(function (s) { return document.getElementById(s); }).filter(Boolean);
    // marca já o escolhido: a resposta chega a seguir
    a.parentNode.querySelectorAll('a').forEach(function (x) { x.classList.toggle('on', x === a); x.removeAttribute('aria-current'); });
    a.setAttribute('aria-current', 'true');
    secs.forEach(function (s) { s.classList.add('xloading'); s.setAttribute('aria-busy', 'true'); });
    if (busy) busy.abort();
    var ctl = window.AbortController ? new AbortController() : null;
    busy = ctl;
    fetch(url.href, { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' }, signal: ctl ? ctl.signal : undefined })
      .then(function (r) { if (!r.ok || r.status === 204 || r.redirected) throw new Error('http'); return r.text(); })
      .then(function (html) {
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var fresh = secs.map(function (s) { return doc.getElementById(s.id); });
        if (fresh.some(function (f) { return !f; })) throw new Error('frag');
        secs.forEach(function (s, i) { s.replaceWith(document.importNode(fresh[i], true)); });
        history.replaceState(history.state, '', page.pathname + page.search + '#resultados');
        busy = null;
      })
      .catch(function (err) {
        if (err && err.name === 'AbortError') return;
        location.href = a.href;   // sem resposta útil: vai pelo link, como sem JavaScript
      });
  });
})();
