/* topper. website v2 — page behaviour (no 3D here; the stage lives in scene.js). */
(function () {
  'use strict';

  /* ================================================================
     CONTENT — edit here.
     ================================================================ */
  var PROJECTS = [
    { title: 'Delta Y', cat: 'Production + post', tab: 'production', video: 'assets/portfolio/clips/c28.mp4', poster: 'assets/portfolio/clips/c28.jpg' },
    { title: 'Wuant — The One', cat: '3D animation', tab: '3d', video: 'assets/portfolio/clips/c64.mp4', poster: 'assets/portfolio/clips/c64.jpg' },
    { title: 'Casino Solverde', cat: 'VFX', tab: 'vfx', video: 'assets/portfolio/clips/c52.mp4', poster: 'assets/portfolio/clips/c52.jpg' },
    { title: 'LING', cat: 'Campaign', tab: 'campaign', video: 'assets/portfolio/clips/c36.mp4', poster: 'assets/portfolio/clips/c36.jpg' },
    { title: 'Richie Campbell', cat: 'Editing', tab: 'editing', video: 'assets/portfolio/clips/c44.mp4', poster: 'assets/portfolio/clips/c44.jpg' }
  ];
  var TABS = [['all', 'All'], ['3d', '3D'], ['vfx', 'VFX'], ['production', 'Production'], ['campaign', 'Campaign'], ['editing', 'Editing']];
  // TODO(logos): swap each name for the official SVG logo once we have the files.
  var CLIENTS = [
    ['Riot Games', 'Games'], ['Galp', 'Energy'], ['Sporting CP', 'Football'], ['NOS', 'Telecom'],
    ['FPF', 'Federação Portuguesa de Futebol'], ['Casino Solverde', 'Entertainment'], ['Delta Y', ''], ['Wuant', 'Music'], ['Richie Campbell', 'Music']
  ];

  var reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
  var fine = matchMedia('(hover: hover) and (pointer: fine)').matches;
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return [].slice.call((r || document).querySelectorAll(s)); };
  var esc = function (s) { return String(s).replace(/[&<>"']/g, function (c) { return '&#' + c.charCodeAt(0) + ';'; }); };
  $('#yr').textContent = new Date().getFullYear();

  /* ── work: tabs + projects ── */
  var used = {}; PROJECTS.forEach(function (p) { used[p.tab] = 1; });
  $('#tabs').innerHTML = TABS.filter(function (t) { return t[0] === 'all' || used[t[0]]; }).map(function (t, i) {
    return '<button type="button" role="tab" data-tab="' + t[0] + '" aria-selected="' + (i === 0) + '">' + t[1] + '</button>';
  }).join('');
  $('#projects').innerHTML = PROJECTS.map(function (p, i) {
    return '<li class="proj" data-tab="' + p.tab + '">' +
      '<div class="media" role="button" tabindex="0" aria-label="Play ' + esc(p.title) + '" data-i="' + i + '">' +
        '<img src="' + esc(p.poster) + '" alt="" loading="lazy">' +
        '<video muted loop playsinline preload="none" data-src="' + esc(p.video) + '"></video>' +
        '<span class="play">Play</span></div>' +
      '<div class="meta"><span class="idx">' + String(i + 1).padStart(2, '0') + ' / ' + String(PROJECTS.length).padStart(2, '0') + '</span>' +
        '<h3>' + esc(p.title) + '</h3><span class="cat">' + esc(p.cat) + '</span></div></li>';
  }).join('');
  $('#tabs').addEventListener('click', function (e) {
    var b = e.target.closest('button'); if (!b) return;
    $$('#tabs button').forEach(function (x) { x.setAttribute('aria-selected', String(x === b)); });
    var t = b.dataset.tab;
    $$('.proj').forEach(function (li) { li.hidden = t !== 'all' && li.dataset.tab !== t; });
    window.dispatchEvent(new Event('topper:layout'));
  });

  // hover (desktop) or in view (touch) plays the clip; the file loads only then
  function play(m, on) {
    var v = $('video', m); if (!v) return;
    if (on) {
      if (!v.src) v.src = v.dataset.src;
      var pr = v.play(); if (pr && pr.catch) pr.catch(function () {});
      m.classList.add('playing');
    } else { v.pause(); m.classList.remove('playing'); }
  }
  $$('.proj .media').forEach(function (m) {
    if (fine && !reduce) {
      m.addEventListener('mouseenter', function () { play(m, true); });
      m.addEventListener('mouseleave', function () { play(m, false); });
    }
    m.addEventListener('click', function () { openLightbox(PROJECTS[+m.dataset.i]); });
    m.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openLightbox(PROJECTS[+m.dataset.i]); } });
  });
  if (!fine && !reduce && 'IntersectionObserver' in window) {
    var vio = new IntersectionObserver(function (es) { es.forEach(function (en) { play(en.target, en.isIntersecting); }); }, { threshold: .6 });
    $$('.proj .media').forEach(function (m) { vio.observe(m); });
  }

  /* ── lightbox ── */
  var lb = $('#lightbox'), lbv = $('#lbVideo'), lastFocus = null;
  function openLightbox(p) {
    lastFocus = document.activeElement;
    lbv.src = p.video; lbv.poster = p.poster; $('#lbTitle').textContent = p.title + ' — ' + p.cat;
    lb.hidden = false; document.body.style.overflow = 'hidden';
    var pr = lbv.play(); if (pr && pr.catch) pr.catch(function () {});
    $('#lbClose').focus();
  }
  function closeLightbox() {
    lbv.pause(); lbv.removeAttribute('src'); lbv.load();
    lb.hidden = true; document.body.style.overflow = '';
    if (lastFocus) lastFocus.focus();
  }
  $('#lbClose').addEventListener('click', closeLightbox);
  lb.addEventListener('click', function (e) { if (e.target === lb) closeLightbox(); });
  addEventListener('keydown', function (e) { if (e.key === 'Escape' && !lb.hidden) closeLightbox(); });

  /* ── clients marquee (two copies for a seamless loop) ── */
  var row = CLIENTS.map(function (c, i) {
    return '<span class="logo-ph' + (i % 3 === 1 ? ' k' : '') + '"><b>' + esc(c[0]) + '</b>' + (c[1] ? '<small>' + esc(c[1]) + '</small>' : '') + '</span>';
  }).join('');
  $('#logos').innerHTML = row + row.replace(/class="logo-ph/g, 'aria-hidden="true" class="logo-ph');

  /* ── counters ── */
  if ('IntersectionObserver' in window && !reduce) {
    var cio = new IntersectionObserver(function (es) {
      es.forEach(function (en) {
        if (!en.isIntersecting) return;
        cio.unobserve(en.target);
        var el = en.target, to = +el.dataset.to, suf = el.dataset.suf || '', t0 = performance.now();
        (function tick(t) {
          var k = Math.min(1, (t - t0) / 1400), e = 1 - Math.pow(1 - k, 3);
          el.textContent = Math.round(to * e) + suf;
          if (k < 1) requestAnimationFrame(tick);
        })(t0);
      });
    }, { threshold: .6 });
    $$('.nums b').forEach(function (b) { cio.observe(b); });
  }

  /* ── header: hide on the way down, show on the way up ── */
  var hdr = $('#hdr'), lastY = scrollY;
  addEventListener('scroll', function () {
    var y = scrollY;
    if (!document.body.classList.contains('menu-open')) hdr.classList.toggle('hide', y > lastY && y > 120);
    lastY = y;
  }, { passive: true });

  /* ── mobile menu ── */
  var mb = $('#menuBtn');
  function menu(open) { document.body.classList.toggle('menu-open', open); mb.setAttribute('aria-expanded', String(open)); mb.textContent = open ? 'Close' : 'Menu'; }
  mb.addEventListener('click', function () { menu(!document.body.classList.contains('menu-open')); });
  $$('#mnav a').forEach(function (a) { a.addEventListener('click', function () { menu(false); }); });

  /* ── the loop: past the footer the page starts again (off with reduced motion) ── */
  var loopEl = $('.loop');
  if (reduce) { loopEl.hidden = true; }
  else {
    var jumping = false;
    addEventListener('scroll', function () {
      if (jumping || lb.hidden === false) return;
      var top = loopEl.offsetTop;
      if (scrollY >= top - 1) {
        jumping = true;
        var before = scrollY, to = Math.max(0, before - top);
        window.scrollTo(0, to);
        window.dispatchEvent(new CustomEvent('topper:jump', { detail: { delta: to - before } }));
        lastY = to;
        requestAnimationFrame(function () { jumping = false; });
      }
    }, { passive: true });
  }

  /* ── "click the hat" hint, shown once the stage is live (desktop) ── */
  var staged = false;
  function noStage() { if (staged) return; staged = true; document.documentElement.classList.add('no-stage'); $('#hatSlot').classList.add('flat'); }
  window.addEventListener('topper:stage', function (e) {
    if (e.detail && e.detail.ok) { staged = true; if (fine) setTimeout(function () { $('#hatHint').classList.add('on'); }, 1600); }
    else noStage();
  });
  setTimeout(noStage, 8000);   // no WebGL (or it never loaded): the flat hat stands in
  window.addEventListener('topper:trick', function () { $('#hatHint').classList.remove('on'); });

  /* ── contact form: real POST on topperdigital.xyz, a dry run anywhere else ── */
  var form = $('#cform'), st = $('#cstatus');
  form.elements.ts.value = Date.now();
  var src = new URLSearchParams(location.search).get('src'); if (src) form.elements.src.value = src;
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    if (!form.elements.name.value.trim() || !/^\S+@\S+\.\S+$/.test(form.elements.email.value) || !form.elements.message.value.trim()) {
      st.textContent = 'Please fill in your name, a valid email and a message.'; return;
    }
    if (!/(^|\.)topperdigital\.xyz$/.test(location.hostname)) {
      st.textContent = 'Prototype: nothing was sent. On topperdigital.xyz this creates a lead in the hub.'; return;
    }
    st.textContent = 'Sending…';
    fetch(form.action, { method: 'POST', body: new FormData(form), headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json().catch(function () { return { ok: r.ok, message: r.ok ? 'Sent.' : 'Something went wrong.' }; }); })
      .then(function (j) { st.textContent = j.message || (j.ok ? 'Sent.' : 'Something went wrong.'); if (j.ok) form.reset(); })
      .catch(function () { st.textContent = 'Something went wrong. Please email info@topperdigital.xyz.'; });
  });
})();
