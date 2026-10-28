/* topper. website v5 — page behaviour (no 3D here; the stage lives in scene.js). */
(function () {
  'use strict';

  /* ================================================================
     CONTENT — edit here.
     ================================================================ */
  var PROJECTS = [
    // Delta Y (Production + post, media/c28) is off the page for now
    { title: 'Wuant — The One', cat: '3D animation', tab: '3d', video: 'assets/portfolio/clips/c64.mp4', poster: 'assets/portfolio/clips/c64.jpg' },
    { title: 'Casino Solverde', cat: 'VFX', tab: 'vfx', video: 'assets/portfolio/clips/c52.mp4', poster: 'assets/portfolio/clips/c52.jpg' },
    { title: 'LING', cat: 'Campaign', tab: 'campaign', video: 'assets/portfolio/clips/c36.mp4', poster: 'assets/portfolio/clips/c36.jpg' },
    { title: 'Richie Campbell', cat: 'Editing', tab: 'editing', video: 'assets/portfolio/clips/c44.mp4', poster: 'assets/portfolio/clips/c44.jpg' }
  ];
  var TABS = [['all', 'All'], ['3d', '3D'], ['vfx', 'VFX'], ['production', 'Production'], ['campaign', 'Campaign'], ['editing', 'Editing']];
  // clients: [name, logo file in brand/clients/ (or '' until we have it — the name is set as a wordmark)]
  // TODO(logos): Delta Y, Wuant and Richie Campbell still need their logo files.
  var CLIENTS = [
    ['Riot Games', 'riot-games.svg'], ['Galp', 'galp.svg'], ['NOS', 'nos.svg'], ['Sporting CP', 'sporting-cp.png'],
    ['Federação Portuguesa de Futebol', 'fpf.svg'], ['Solverde', 'solverde.png'], ['Delta Y', ''], ['Wuant', ''], ['Richie Campbell', '']
  ];

  // shared with the 3D stage (scene.js runs after this file)
  var shared = window.__topper = { projects: PROJECTS, tab: 'all' };

  var reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
  var fine = matchMedia('(hover: hover) and (pointer: fine)').matches;
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return [].slice.call((r || document).querySelectorAll(s)); };
  var esc = function (s) { return String(s).replace(/[&<>"']/g, function (c) { return '&#' + c.charCodeAt(0) + ';'; }); };
  var pad = function (n) { return String(n).padStart(2, '0'); };
  var root = document.documentElement;
  $('#yr').textContent = new Date().getFullYear();

  /* ── text kit: masked word reveals (headings the stage draws in 3D are left whole) ── */
  function split(el) {
    if (el.dataset.split) return; el.dataset.split = '1';
    var i = 0;
    (function walk(node) {
      [].slice.call(node.childNodes).forEach(function (n) {
        if (n.nodeType === 3) {
          var parts = n.textContent.split(/(\s+)/), frag = document.createDocumentFragment();
          parts.forEach(function (w) {
            if (!w) return;
            if (/^\s+$/.test(w)) { frag.appendChild(document.createTextNode(w)); return; }
            var o = document.createElement('span'), inner = document.createElement('span');
            o.className = 'w'; inner.className = 'wi'; inner.style.setProperty('--i', i++); inner.textContent = w;
            o.appendChild(inner); frag.appendChild(o);
          });
          n.parentNode.replaceChild(frag, n);
        } else if (n.nodeType === 1 && n.tagName !== 'BR') walk(n);
      });
    })(el);
  }
  // the deck captions: the new words rise in (a CSS animation restarted on each change)
  function swap(el, html) {
    if (el.innerHTML === html) return;
    el.innerHTML = html;
    if (reduce) return;
    el.classList.remove('swap'); void el.offsetWidth; el.classList.add('swap');
  }
  var heads = $$('.headline, .sec-title').filter(function (h) { return !h.hasAttribute('data-t3d'); });
  if (!reduce) heads.forEach(split);
  function reveal(el) { el.classList.add('in'); }
  if ('IntersectionObserver' in window && !reduce) {
    var hio = new IntersectionObserver(function (es) {
      es.forEach(function (en) { if (en.isIntersecting && (!en.target.closest('.hero') || root.classList.contains('shown'))) { hio.unobserve(en.target); reveal(en.target); } });
    }, { threshold: 0.25 });
    heads.forEach(function (h) { hio.observe(h); });
  } else heads.forEach(reveal);

  /* ── the door: counts GPU readiness from the stage, then asks how to come in (sound needs a click) ── */
  var loader = $('#loader'), ldNum = $('#ldNum'), ldBar = $('#ldBar'), door = $('#ldDoor'), target = 0, shown = 0, started = performance.now(), done = false, opened = false;
  window.addEventListener('topper:progress', function (e) { target = Math.max(target, e.detail.p); });
  function openDoor() {
    if (opened) return; opened = true;
    root.classList.add('ready'); door.hidden = false; $('#ldTag').textContent = 'Best with sound';
    $('.ld-in', door).focus({ preventScroll: true });
  }
  function finishLoader(sound) {
    if (done) return; done = true;
    root.classList.add('shown');
    setSound(!!sound);
    window.dispatchEvent(new Event('topper:intro'));
    setTimeout(function () { loader.hidden = true; }, 1400);
  }
  door.addEventListener('click', function (e) { var b = e.target.closest('button'); if (b) finishLoader(b.dataset.sound === '1'); });
  (function count() {
    if (opened) return;
    shown += (target - shown) * 0.12 + 0.002;
    shown = Math.min(shown, target, 1);
    var n = Math.round(shown * 100);
    ldNum.textContent = String(n); ldBar.style.transform = 'scaleX(' + shown + ')';
    if (shown >= 0.995 && performance.now() - started > 900) return openDoor();
    requestAnimationFrame(count);
  })();
  if (reduce) { target = 1; }
  setTimeout(openDoor, 12000);   // never hold the page hostage, whatever the GPU is doing

  /* ── sound: the header switch (the stage owns the audio) ── */
  var snd = $('#snd');
  function setSound(on) {
    snd.setAttribute('aria-pressed', String(on)); snd.setAttribute('aria-label', on ? 'Sound on' : 'Sound off');
    window.dispatchEvent(new CustomEvent('topper:sound', { detail: { on: on } }));
  }
  snd.addEventListener('click', function () { setSound(snd.getAttribute('aria-pressed') !== 'true'); });

  /* ── work: tabs, the fallback grid, and the deck ── */
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
      '<div class="meta"><span class="idx">' + pad(i + 1) + ' / ' + pad(PROJECTS.length) + '</span>' +
        '<h3>' + esc(p.title) + '</h3><span class="cat">' + esc(p.cat) + '</span></div></li>';
  }).join('');
  var deckEl = $('#deck'), deckList = $('#deckList'), deckOn = false, focusP = null, focusK = 0;
  function visible() { return PROJECTS.filter(function (p) { return shared.tab === 'all' || p.tab === shared.tab; }); }
  function buildList() {
    var vis = visible();
    deckEl.style.setProperty('--n', vis.length);
    deckList.innerHTML = vis.map(function (p, k) {
      return '<li><button type="button" data-k="' + k + '"><span class="n">' + pad(k + 1) + '</span>' + esc(p.title) + '</button></li>';
    }).join('');
  }
  buildList();
  $('#tabs').addEventListener('click', function (e) {
    var b = e.target.closest('button'); if (!b) return;
    $$('#tabs button').forEach(function (x) { x.setAttribute('aria-selected', String(x === b)); });
    shared.tab = b.dataset.tab;
    $$('.proj').forEach(function (li) { li.hidden = shared.tab !== 'all' && li.dataset.tab !== shared.tab; });
    buildList();
    if (deckOn) {
      var top = deckEl.getBoundingClientRect().top + scrollY;
      if (scrollY > top) window.scrollTo(0, top);
    }
    window.dispatchEvent(new Event('topper:filter'));
    window.dispatchEvent(new Event('topper:layout'));
    var v = visible(); if (deckOn && v.length) window.dispatchEvent(new CustomEvent('topper:focus', { detail: { p: v[0], k: 0, n: v.length } }));
  });

  // the deck's captions show only while it is pinned (the cards are screen-fixed; the captions scroll in with the section)
  addEventListener('scroll', function () {
    if (!deckOn) return;
    var r = deckEl.getBoundingClientRect();
    deckEl.classList.toggle('pinned', r.top <= innerHeight * 0.12 && r.bottom >= innerHeight * 0.88);
  }, { passive: true });

  // the deck scrolls: card k sits at an even step through the pinned stretch
  function deckY(k) {
    var vis = visible().length, r = deckEl.getBoundingClientRect(), top = r.top + scrollY;
    return top + (vis > 1 ? k / (vis - 1) : 0) * (r.height - innerHeight);
  }
  function goto(k) { window.scrollTo({ top: deckY(k), behavior: reduce ? 'auto' : 'smooth' }); }
  deckList.addEventListener('click', function (e) { var b = e.target.closest('button'); if (b) goto(+b.dataset.k); });
  // keyboard: arrows step through the deck while it is on screen
  addEventListener('keydown', function (e) {
    if (!deckOn || !lb.hidden || !deckEl.classList.contains('pinned') || /^(INPUT|TEXTAREA|SELECT)$/.test((e.target.tagName || ''))) return;
    var d = e.key === 'ArrowRight' || e.key === 'ArrowDown' ? 1 : e.key === 'ArrowLeft' || e.key === 'ArrowUp' ? -1 : 0;
    if (!d) return;
    e.preventDefault(); goto(Math.max(0, Math.min(visible().length - 1, focusK + d)));
  });
  window.addEventListener('topper:goto', function (e) { goto(e.detail.k); });
  window.addEventListener('topper:open', function () { if (focusP) openLightbox(focusP); });
  $('#deckPlay').addEventListener('click', function () { if (focusP) openLightbox(focusP); });
  window.addEventListener('topper:focus', function (e) {
    var d = e.detail; if (!d.p) return;
    focusP = d.p; focusK = d.k;
    swap($('#deckTitle'), esc(d.p.title));
    swap($('#deckCat'), esc(d.p.cat));
    $('#deckLive').textContent = d.p.title + ', ' + d.p.cat + ' (' + (d.k + 1) + ' of ' + d.n + ')';
    $('#deckCount').innerHTML = '<b>' + pad(d.k + 1) + '</b> / ' + pad(d.n);
    $$('button', deckList).forEach(function (b) { b.classList.toggle('on', +b.dataset.k === d.k); b.setAttribute('aria-current', String(+b.dataset.k === d.k)); });
  });

  // hover (desktop) or in view (touch) plays the clip in the fallback grid; the file loads only then
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
    var vio = new IntersectionObserver(function (es) { es.forEach(function (en) { if (!deckOn) play(en.target, en.isIntersecting); }); }, { threshold: .6 });
    $$('.proj .media').forEach(function (m) { vio.observe(m); });
  }

  /* ── lightbox (opens with an iris) ── */
  var lb = $('#lightbox'), lbv = $('#lbVideo'), lastFocus = null;
  function openLightbox(p) {
    lastFocus = document.activeElement;
    lbv.src = p.video; lbv.poster = p.poster; $('#lbTitle').textContent = p.title + ' — ' + p.cat;
    lb.hidden = false; document.body.style.overflow = 'hidden';
    requestAnimationFrame(function () { lb.classList.add('open'); });
    var pr = lbv.play(); if (pr && pr.catch) pr.catch(function () {});
    $('#lbClose').focus();
  }
  function closeLightbox() {
    lb.classList.remove('open');
    lbv.pause();
    setTimeout(function () { lbv.removeAttribute('src'); lbv.load(); lb.hidden = true; }, reduce ? 0 : 450);
    document.body.style.overflow = '';
    if (lastFocus) lastFocus.focus();
  }
  $('#lbClose').addEventListener('click', closeLightbox);
  lb.addEventListener('click', function (e) { if (e.target === lb) closeLightbox(); });
  addEventListener('keydown', function (e) { if (e.key === 'Escape' && !lb.hidden) closeLightbox(); });

  /* ── services: each panel gets a film from that kind of work (a still first; the clip loads and plays only while in view) ── */
  var saveData = navigator.connection && navigator.connection.saveData;
  $$('.svc').forEach(function (li) {
    var p = PROJECTS.filter(function (q) { return q.title === li.dataset.p; })[0]; if (!p) return;
    li.insertAdjacentHTML('afterbegin', '<figure class="svc-media"><img src="' + esc(p.poster) + '" alt="" decoding="async"><video muted loop playsinline preload="none"></video></figure>');
    li._p = p;
  });
  if ('IntersectionObserver' in window && !reduce) {
    var swio = new IntersectionObserver(function (es) { es.forEach(function (en) { if (en.isIntersecting) { en.target.classList.add('in'); swio.unobserve(en.target); } }); }, { threshold: 0.12 });
    swio.observe($('#svcs'));
    var svio = new IntersectionObserver(function (es) {
      es.forEach(function (en) {
        var fig = en.target, v = fig.querySelector('video'), p = fig.parentNode._p;
        if (en.isIntersecting && !saveData) {
          if (!v.getAttribute('src')) v.src = p.video;
          var pr = v.play(); if (pr && pr.then) pr.then(function () { fig.classList.add('playing'); }, function () {});
        } else if (!v.paused) v.pause();
      });
    }, { threshold: 0.35 });
    $$('.svc-media').forEach(function (f) { svio.observe(f); });
  } else $('#svcs').classList.add('in');

  /* ── clients: every brand at once, a wall of logos that settles in one by one ── */
  $('#logos').innerHTML = CLIENTS.map(function (c, i) {
    return '<li class="client" style="--i:' + i + '">' + (c[1] ? '<img src="assets/brand/clients/' + c[1] + '" alt="' + esc(c[0]) + '" decoding="async">' : '<b>' + esc(c[0]) + '</b>') + '</li>';
  }).join('');
  if ('IntersectionObserver' in window && !reduce) {
    var wio = new IntersectionObserver(function (es) { es.forEach(function (en) { if (en.isIntersecting) { en.target.classList.add('in'); wio.unobserve(en.target); } }); }, { threshold: 0.15 });
    wio.observe($('#logos'));
  } else $('#logos').classList.add('in');

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

  /* ── the opening film: the smaller file on small screens; it plays only while it is on screen ── */
  var reel = $('#reel');
  if (reel) {
    reel.src = innerWidth < 900 ? reel.dataset.srcS : reel.dataset.src;
    if (reduce) { reel.removeAttribute('autoplay'); reel.pause(); }
    else if ('IntersectionObserver' in window) {
      new IntersectionObserver(function (es) {
        es.forEach(function (en) { if (en.isIntersecting) { var pr = reel.play(); if (pr && pr.catch) pr.catch(function () {}); } else reel.pause(); });
      }).observe(reel);
    }
  }

  /* ── the cursor label (desktop): "Play film", "Click" … set by the stage ── */
  var cur = $('#cursor'), curT = $('span', cur), cx = -100, cy = -100, tx = -100, ty = -100;
  if (fine && !reduce) {
    addEventListener('pointermove', function (e) { tx = e.clientX; ty = e.clientY; }, { passive: true });
    (function loop() { cx += (tx - cx) * 0.22; cy += (ty - cy) * 0.22; cur.style.transform = 'translate3d(' + cx + 'px,' + cy + 'px,0)'; requestAnimationFrame(loop); })();
  }
  window.addEventListener('topper:cursor', function (e) {
    var l = e.detail.label; cur.classList.toggle('on', !!l); if (l) curT.textContent = l;
  });

  /* ── stage ready: switch the work section to the deck; no WebGL: the flat hat stands in ── */
  var staged = false;
  function noStage() {
    if (staged) return; staged = true;
    root.classList.add('no-stage'); $('#hatSlot').classList.add('flat');
    target = 1;
  }
  window.addEventListener('topper:stage', function (e) {
    if (e.detail && e.detail.ok) {
      staged = true; root.classList.add('t3d');
      root.classList.remove('no-stage'); $('#hatSlot').classList.remove('flat');   // a slow GPU can arrive after the fallback
      if (e.detail.deck) {
        deckOn = true; root.classList.add('has-deck');
        $('#tabsSlot').appendChild($('#tabs'));
        window.dispatchEvent(new Event('topper:layout'));
      }
    } else noStage();
  });
  setTimeout(noStage, 9000);   // no WebGL (or it never loaded): the flat hat and the grid stand in

  /* ── contact form: real POST on topperdigital.xyz, a dry run anywhere else ── */
  var form = $('#cform'), st = $('#cstatus');
  form.elements.ts.value = Date.now();
  var qs = new URLSearchParams(location.search);
  var src = qs.get('src'); if (src) form.elements.src.value = src;
  // UTM: a primeira visita com utm_* fica guardada 30 dias e vai com o formulário (origem da lead no Hub)
  var UTM = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'], utm = null;
  try {
    if (UTM.some(function (k) { return qs.get(k); })) {
      utm = { t: Date.now() }; UTM.forEach(function (k) { if (qs.get(k)) utm[k] = qs.get(k).slice(0, 120); });
      localStorage.setItem('tp_utm', JSON.stringify(utm));
    } else {
      utm = JSON.parse(localStorage.getItem('tp_utm') || 'null');
      if (utm && Date.now() - utm.t > 30 * 864e5) { utm = null; localStorage.removeItem('tp_utm'); }
    }
  } catch (e) { utm = null; }
  if (utm) UTM.forEach(function (k) {
    if (!utm[k]) return;
    var i = document.createElement('input'); i.type = 'hidden'; i.name = k; i.value = utm[k]; form.appendChild(i);
  });
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
