/*
 * topper. admin — o que é comum a todas as páginas (docs/ESPECIFICACAO-PLATAFORMA.md §11)
 *
 * Modo claro/escuro com a transição em círculo, menu recolhível, dicas nos
 * ícones, avisos com «Anular», pesquisa ⌘K, notificações, painel lateral,
 * edição no próprio campo, registo de interações, filtros que aplicam logo,
 * o Pipeline sem recarregar e a contagem dos números.
 *
 * Tudo o que grava passa por /admin/ui_api.php, que confirma as permissões
 * em cada pedido. Sem JavaScript, as páginas continuam a funcionar com os
 * formulários de sempre.
 */
(function () {
  'use strict';

  var root = document.documentElement;
  var body = document.body;
  var API = '/admin/ui_api.php';
  var csrfMeta = document.querySelector('meta[name=csrf]');
  var csrf = csrfMeta ? csrfMeta.content : '';
  var reduced = window.matchMedia('(prefers-reduced-motion: reduce)');
  var darkMq = window.matchMedia('(prefers-color-scheme: dark)');
  var isMac = /Mac|iPhone|iPad|iPod/.test(navigator.platform || navigator.userAgent);
  // Ecrãs só de toque: os lápis da edição no campo ficam sempre à vista (não há «passar o cursor»).
  if (window.matchMedia('(hover: none)').matches) root.classList.add('touch');

  function $(s, el) { return (el || document).querySelector(s); }
  function $$(s, el) { return Array.prototype.slice.call((el || document).querySelectorAll(s)); }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function typing(el) {
    return el && (el.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(el.tagName));
  }

  // ── pedidos ao servidor ──────────────────────────────────
  function api(action, data, method) {
    method = method || (data ? 'POST' : 'GET');
    var url = API + '?a=' + encodeURIComponent(action);
    var opt = { method: method, credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-CSRF-Token': csrf } };
    if (method === 'GET' && data) {
      Object.keys(data).forEach(function (k) { url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(data[k]); });
    } else if (data) {
      var fd = data instanceof FormData ? data : new FormData();
      if (!(data instanceof FormData)) {
        Object.keys(data).forEach(function (k) {
          var v = data[k];
          if (Array.isArray(v)) v.forEach(function (x) { fd.append(k + '[]', x); }); else fd.append(k, v == null ? '' : v);
        });
      }
      fd.set('a', action);
      opt.body = fd;
      if (data.keepalive) opt.keepalive = true;
    }
    return fetch(url, opt).then(function (r) {
      return r.json().catch(function () {
        return { ok: false, error: r.redirected || r.status === 401 ? 'A sessão terminou. Recarregue a página.' : 'Não foi possível concluir. Tente outra vez.' };
      });
    }).then(function (j) {
      if (!j || !j.ok) throw new Error((j && j.error) || 'Não foi possível concluir.');
      return j;
    }, function () { throw new Error('Sem ligação. Tente outra vez.'); });
  }

  // ── avisos em baixo, com «Anular» (§11.10) ───────────────
  var toastBox = $('.toasts');
  function toast(msg, opt) {
    opt = opt || {};
    if (!toastBox) return { close: function () {} };
    var t = document.createElement('div');
    t.className = 'tst' + (opt.err ? ' err' : '');
    t.setAttribute('role', opt.err ? 'alert' : 'status');
    t.innerHTML = '<span class="tx"></span>';
    t.firstChild.textContent = msg;
    var ms = opt.undo ? 8000 : (opt.err ? 7000 : 4000);
    var timer, tick;
    function close() { clearTimeout(timer); clearInterval(tick); if (t.parentNode) t.parentNode.removeChild(t); }
    if (opt.undo) {
      var b = document.createElement('button');
      b.type = 'button'; b.className = 'undo'; b.textContent = 'Anular';
      var left = document.createElement('span');
      left.className = 't'; left.setAttribute('aria-hidden', 'true');
      var s = 8; left.textContent = s + 's';
      tick = setInterval(function () { s--; left.textContent = Math.max(s, 0) + 's'; }, 1000);
      b.addEventListener('click', function () {
        close();
        if (typeof opt.undo === 'function') opt.undo(); else undo(opt.undo, opt.afterUndo);
      });
      t.appendChild(b); t.appendChild(left);
    }
    var x = document.createElement('button');
    x.type = 'button'; x.className = 'x'; x.setAttribute('aria-label', 'Fechar aviso'); x.setAttribute('data-tip', '');
    x.innerHTML = '<svg class="ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>';
    x.addEventListener('click', close);
    t.appendChild(x);
    toastBox.appendChild(t);
    timer = setTimeout(close, ms);
    t.addEventListener('pointerenter', function () { clearTimeout(timer); });
    t.addEventListener('pointerleave', function () { timer = setTimeout(close, 2500); });
    return { close: close };
  }

  function undo(token, after) {
    api('undo', { token: token }).then(function (j) {
      toast(j.msg || 'Anulado.');
      if (typeof after === 'function') after(); else setTimeout(function () { location.reload(); }, 450);
    }).catch(function (e) { toast(e.message, { err: true }); });
  }

  window.topper = { api: api, toast: toast, undo: undo };

  // As mensagens de sucesso passam a avisos em baixo; os erros ficam na página.
  $$('main .flash.ok').forEach(function (f) {
    toast(f.textContent.trim(), { undo: f.getAttribute('data-undo') || null });
    f.parentNode.removeChild(f);
  });

  // ── atalhos certos para o sistema ────────────────────────
  if (isMac) {
    $$('[data-kbd]').forEach(function (k) { k.textContent = '⌘ K'; });
    $$('[data-kbd-mod]').forEach(function (k) { k.textContent = '⌘'; });
  }

  // ── modo claro / escuro, com a transição em círculo (§11.1–2) ──
  function theme() { return root.getAttribute('data-theme') || (darkMq.matches ? 'dark' : 'light'); }
  function setTheme(next, x, y) {
    function apply() { root.setAttribute('data-theme', next); }
    if (!document.startViewTransition || reduced.matches) { apply(); return; }
    var r = Math.hypot(Math.max(x, innerWidth - x), Math.max(y, innerHeight - y));
    root.classList.add('theme-switching');
    var vt = document.startViewTransition(apply);
    vt.ready.then(function () {
      root.animate({ clipPath: ['circle(0px at ' + x + 'px ' + y + 'px)', 'circle(' + r + 'px at ' + x + 'px ' + y + 'px)'] },
                   { duration: 560, easing: 'cubic-bezier(.2,.7,.2,1)', pseudoElement: '::view-transition-new(root)' });
    }).catch(function () {});
    vt.finished.then(function () { root.classList.remove('theme-switching'); }, function () { root.classList.remove('theme-switching'); });
  }
  function toggleTheme(x, y) {
    var next = theme() === 'dark' ? 'light' : 'dark';
    setTheme(next, x, y);
    api('prefs', { theme: next }).catch(function (e) { toast(e.message, { err: true }); });
  }
  $$('.theme-btn').forEach(function (b) {
    b.addEventListener('click', function (e) {
      var r = b.getBoundingClientRect();
      var x = e.clientX || r.left + r.width / 2, y = e.clientY || r.top + r.height / 2;
      toggleTheme(x, y);
    });
  });

  // ── menu: recolher (guardado na conta) e gaveta no telemóvel (§11.18) ──
  var rail = $('#rail');
  var narrow = window.matchMedia('(max-width: 900px)');
  // A seta na borda do menu (§12.0) e o atalho «[».
  function toggleNav() {
    var closed = root.getAttribute('data-nav') !== 'closed';
    if (closed) root.setAttribute('data-nav', 'closed'); else root.removeAttribute('data-nav');
    $$('.rail-toggle').forEach(function (b) {
      var label = closed ? 'Expandir menu' : 'Recolher menu';
      b.setAttribute('aria-expanded', closed ? 'false' : 'true');
      b.setAttribute('aria-label', label + ' ( [ )');
      b.setAttribute('data-tip', label + '  ·  [ ');
    });
    hideTip();
    api('prefs', { nav: closed ? 'closed' : '' }).catch(function () {});
  }
  $$('.rail-toggle').forEach(function (b) { b.addEventListener('click', toggleNav); });
  function navOpen(open) {
    body.classList.toggle('nav-open', open);
    $$('.menu-btn').forEach(function (b) { b.setAttribute('aria-expanded', open ? 'true' : 'false'); });
    if (open && rail) { var a = $('.ni.on', rail) || $('.ni', rail); if (a) a.focus(); }
  }
  $$('.menu-btn').forEach(function (b) { b.addEventListener('click', function () { navOpen(!body.classList.contains('nav-open')); }); });
  $$('[data-nav-close]').forEach(function (s) { s.addEventListener('click', function () { navOpen(false); }); });

  // ── dicas em todos os ícones (§11.19) ────────────────────
  var tip = $('#tip'), tipFor = null, tipTimer = null, touchTimer = null;
  function tipText(el) { var t = el.getAttribute('data-tip'); return t || el.getAttribute('aria-label') || ''; }
  function tipAllowed(el) {
    if (!el.hasAttribute('data-tip-nav')) return true;
    return root.getAttribute('data-nav') === 'closed' && !narrow.matches;
  }
  function showTip(el) {
    if (!tip || !document.contains(el)) return;
    var text = tipText(el);
    if (!text) return;
    tip.textContent = text;
    tip.style.left = '0px'; tip.style.top = '0px';
    tip.classList.add('on');
    var r = el.getBoundingClientRect(), w = tip.offsetWidth, h = tip.offsetHeight, x, y;
    if (el.hasAttribute('data-tip-nav')) { x = r.right + 10; y = r.top + r.height / 2 - h / 2; }
    else if (el.classList && el.classList.contains('hit')) { x = r.left + r.width / 2 - w / 2; y = r.top - h - 6; }
    else {
      x = r.left + r.width / 2 - w / 2; y = r.bottom + 8;
      if (y + h > innerHeight - 6) y = r.top - h - 8;
    }
    x = Math.max(6, Math.min(x, innerWidth - w - 6));
    tip.style.left = Math.round(x) + 'px'; tip.style.top = Math.round(Math.max(6, y)) + 'px';
    tip.id = 'tip';
    tipFor = el;
  }
  function hideTip() { clearTimeout(tipTimer); clearTimeout(touchTimer); if (tip) tip.classList.remove('on'); tipFor = null; }
  // Botões só com ícone que ainda não tenham a marca ganham-na: o nome acessível é a dica.
  $$('.ibtn[aria-label]:not([data-tip])').forEach(function (b) { b.setAttribute('data-tip', ''); });
  document.addEventListener('pointerover', function (e) {
    if (e.pointerType === 'touch') return;
    var el = e.target.closest && e.target.closest('[data-tip]');
    if (el === tipFor) return;
    hideTip();
    if (!el || !tipAllowed(el)) return;
    tipTimer = setTimeout(function () { showTip(el); }, 300);
  });
  document.addEventListener('pointerout', function (e) {
    if (!tipFor && !tipTimer) return;
    var to = e.relatedTarget && e.relatedTarget.closest ? e.relatedTarget.closest('[data-tip]') : null;
    if (to !== tipFor) hideTip();
  });
  document.addEventListener('focusin', function (e) {
    var el = e.target.closest && e.target.closest('[data-tip]');
    if (!el || !tipAllowed(el)) return;
    var kb = true; try { kb = e.target.matches(':focus-visible'); } catch (_) {}
    if (kb) { hideTip(); showTip(el); }
  });
  document.addEventListener('focusout', hideTip);
  document.addEventListener('pointerdown', function (e) {
    hideTip();
    if (e.pointerType !== 'touch') return;
    var el = e.target.closest && e.target.closest('[data-tip]');
    if (!el || !tipAllowed(el)) return;
    touchTimer = setTimeout(function () { showTip(el); setTimeout(hideTip, 1600); }, 500);
  }, true);
  ['pointerup', 'pointercancel'].forEach(function (t) { document.addEventListener(t, function () { clearTimeout(touchTimer); }, true); });
  window.addEventListener('scroll', hideTip, true);

  // ── contagem dos números (motion que confirma) ───────────
  function fmt0(n) { return '€' + String(Math.round(n)).replace(/\B(?=(\d{3})+(?!\d))/g, ' '); }
  function countTo(el, to, money) {
    var from = parseFloat(el.getAttribute('data-n') || '0') || 0;
    el.setAttribute('data-n', to);
    var put = function (v) { el.textContent = money ? fmt0(v) : String(Math.round(v)); };
    if (reduced.matches || from === to) { put(to); return; }
    var t0 = performance.now(), d = 200;
    (function step(t) {
      var p = Math.min(1, (t - t0) / d), e = 1 - Math.pow(1 - p, 3);
      put(from + (to - from) * e);
      if (p < 1) requestAnimationFrame(step);
    })(t0);
  }
  window.topper.countTo = countTo;

  // ── saudação pela hora do browser (§11.3) ────────────────
  $$('[data-greet]').forEach(function (el) {
    var h = new Date().getHours();
    var g = h >= 6 && h < 13 ? 'Bom dia' : (h >= 13 && h < 20 ? 'Boa tarde' : 'Boa noite');
    el.textContent = g + ', ' + el.getAttribute('data-greet');
  });

  // ── filtros que aplicam logo (§11.12) ────────────────────
  $$('form[data-auto]').forEach(function (f) {
    var t = null;
    $$('select', f).forEach(function (s) {
      if (s.hasAttribute('onchange')) return;
      s.addEventListener('change', function () { f.requestSubmit ? f.requestSubmit() : f.submit(); });
    });
    $$('input[type=search], input[type=text]', f).forEach(function (i) {
      i.addEventListener('input', function () {
        clearTimeout(t);
        t = setTimeout(function () { f.requestSubmit ? f.requestSubmit() : f.submit(); }, 500);
      });
      if (i.value && i.hasAttribute('data-refocus')) { i.focus(); var n = i.value.length; try { i.setSelectionRange(n, n); } catch (_) {} }
    });
  });

  // ── copiar link ──────────────────────────────────────────
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('[data-copy]');
    if (!b) return;
    e.preventDefault();
    var url = new URL(b.getAttribute('data-copy'), location.href).href;
    (navigator.clipboard ? navigator.clipboard.writeText(url) : Promise.reject()).then(function () {
      toast('Link copiado.');
    }, function () { window.prompt('Copie o link:', url); });
  });

  // ── centro de notificações (§11.6) ───────────────────────
  var bell = $('.bell'), pop = $('#notif');
  function setBadge(n) {
    if (!bell) return;
    var b = $('.badge', bell);
    if (b) { b.textContent = n; b.hidden = !n; }
    bell.setAttribute('aria-label', 'Notificações' + (n ? ' · ' + n + ' por ler' : ''));
  }
  function popOpen(open) {
    if (!pop) return;
    pop.classList.toggle('open', open);
    bell.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (!open) return;
    var list = $('.pop-list', pop);
    api('notifications', null, 'GET').then(function (j) {
      setBadge(j.unread);
      if (!j.items.length) { list.innerHTML = '<div class="empty">Nada a pedir atenção agora.</div>'; return; }
      list.innerHTML = j.items.map(function (n) {
        return '<a class="nt' + (n.unread ? ' unread' : '') + '" href="' + esc(n.url) + '" data-key="' + esc(n.key) + '">' + n.icon
          + '<div><div class="tt">' + esc(n.title) + '</div><small>' + esc(n.sub) + '</small></div></a>';
      }).join('');
    }).catch(function (e) { list.innerHTML = '<div class="empty">' + esc(e.message) + '</div>'; });
  }
  if (bell && pop) {
    bell.addEventListener('click', function (e) { e.stopPropagation(); popOpen(!pop.classList.contains('open')); });
    pop.addEventListener('click', function (e) {
      e.stopPropagation();
      var a = e.target.closest('.nt');
      if (a && a.classList.contains('unread')) api('notifications_read', { keys: [a.getAttribute('data-key')], keepalive: true }).catch(function () {});
      if (e.target.closest('[data-read-all]')) {
        api('notifications_read', {}).then(function (j) {
          setBadge(j.unread);
          $$('.nt.unread', pop).forEach(function (n) { n.classList.remove('unread'); });
        }).catch(function (er) { toast(er.message, { err: true }); });
      }
    });
    document.addEventListener('click', function () { if (pop.classList.contains('open')) popOpen(false); });
  }

  // ── painel lateral de detalhe (§11.7) ────────────────────
  var sp = $('#sp'), spBack = $('.sp-back'), spKey = null, spReturn = null;
  // As linhas da lista que abrem no painel, pela ordem e filtros atuais (setas ↑ ↓, §12.5).
  function panelRows() { return $$('tr[data-panel]').filter(function (r) { return r.offsetParent !== null; }); }
  function panelStep(dir) {
    var rows = panelRows(), keys = rows.map(function (r) { return r.getAttribute('data-panel'); });
    var i = keys.indexOf(spKey);
    if (i < 0 || !keys[i + dir]) return;
    openPanel(keys[i + dir]);
    rows[i + dir].scrollIntoView({ block: 'nearest' });
  }
  function panelNav() {
    var nav = sp && $('.sp-nav', sp);
    if (!nav) return;
    var keys = panelRows().map(function (r) { return r.getAttribute('data-panel'); }), i = keys.indexOf(spKey);
    nav.hidden = i < 0;
    $('[data-step="-1"]', nav).disabled = i <= 0;
    $('[data-step="1"]', nav).disabled = i < 0 || i >= keys.length - 1;
  }
  if (sp) $$('.sp-nav [data-step]', sp).forEach(function (b) {
    b.addEventListener('click', function () { panelStep(+b.getAttribute('data-step')); });
  });
  function openPanel(key) {
    if (!sp) return;
    var parts = String(key).split(':');
    spKey = key;
    if (!sp.classList.contains('open')) spReturn = document.activeElement;
    $$('tr.sel').forEach(function (r) { r.classList.remove('sel'); });
    $$('[data-panel="' + key + '"]').forEach(function (r) { if (r.tagName === 'TR') r.classList.add('sel'); });
    sp.classList.add('open'); spBack.classList.add('open');
    var bodyEl = $('.sp-body', sp);
    bodyEl.innerHTML = '<div class="sp-loading">A carregar…</div>';
    panelNav();
    api('panel', { type: parts[0], id: parts[1] }, 'GET').then(function (j) {
      if (spKey !== key) return;
      sp.classList.toggle('wide', !!j.wide);
      $('#sp-title').textContent = j.title;
      $('.sp-full', sp).setAttribute('href', j.url);
      bodyEl.innerHTML = j.html;
      bodyEl.scrollTop = 0;
      sp.focus({ preventScroll: true });
      // O comportamento próprio do registo (ex.: leads.js), se a página ainda não o tiver.
      if (j.script && !window.topperLeads && !$('script[data-panel-js="' + j.script.split('?')[0] + '"]')) {
        var s = document.createElement('script');
        s.src = j.script; s.defer = true; s.setAttribute('data-panel-js', j.script.split('?')[0]);
        document.body.appendChild(s);
      }
      document.dispatchEvent(new CustomEvent('topper:panel', { detail: { key: key, el: bodyEl } }));
    }).catch(function (e) { bodyEl.innerHTML = '<div class="sp-loading">' + esc(e.message) + '</div>'; });
  }
  function closePanel() {
    if (!sp || !sp.classList.contains('open')) return;
    sp.classList.remove('open'); spBack.classList.remove('open');
    $$('tr.sel').forEach(function (r) { r.classList.remove('sel'); });
    spKey = null;
    if (spReturn && spReturn.focus) spReturn.focus({ preventScroll: true });
  }
  window.topper.openPanel = openPanel;
  window.topper.closePanel = closePanel;
  window.topper.reloadPanel = function () { if (spKey) openPanel(spKey); };
  window.topper.panelKey = function () { return spKey; };
  $$('[data-sp-close]').forEach(function (b) { b.addEventListener('click', closePanel); });
  // Clicar numa linha (fora dos controlos) ou no nome abre o painel; Ctrl/⌘-clique abre a página completa.
  document.addEventListener('click', function (e) {
    var t = e.target;
    if (!t.closest || t.closest('.sp, .cmdk')) return;
    var link = t.closest('a[data-panel]');
    if (link) {
      if (e.metaKey || e.ctrlKey || e.shiftKey || e.button === 1) return;
      e.preventDefault(); openPanel(link.getAttribute('data-panel')); return;
    }
    var row = t.closest('tr[data-panel]');
    if (!row || t.closest('a, button, input, select, textarea, label, form, summary')) return;
    openPanel(row.getAttribute('data-panel'));
  });
  var auto = $('[data-open-panel]');
  if (auto) openPanel(auto.getAttribute('data-open-panel'));

  // ── edição no próprio campo (§11.8) ──────────────────────
  function ieStart(ie) {
    if (ie.classList.contains('editing')) return;
    var type = ie.getAttribute('data-type') || 'text';
    var value = ie.getAttribute('data-value') || '';
    var input;
    if (type === 'select') {
      input = document.createElement('select');
      var opts = JSON.parse(ie.getAttribute('data-options') || '{}');
      Object.keys(opts).forEach(function (k) {
        var o = document.createElement('option'); o.value = k; o.textContent = opts[k]; if (k === value) o.selected = true; input.appendChild(o);
      });
    } else if (type === 'multi') {
      // Várias opções (ex.: tipos da lead): caixas de escolha; o valor fica «a, b».
      input = document.createElement('div');
      input.className = 'ie-multi';
      var chosen = value.split(',').map(function (x) { return x.trim(); });
      var mopts = JSON.parse(ie.getAttribute('data-options') || '{}');
      Object.keys(mopts).forEach(function (k) {
        var l = document.createElement('label'); l.className = 'check';
        var c = document.createElement('input'); c.type = 'checkbox'; c.value = k; c.checked = chosen.indexOf(k) >= 0;
        l.appendChild(c); l.appendChild(document.createTextNode(' ' + mopts[k])); input.appendChild(l);
      });
      Object.defineProperty(input, 'value', { get: function () {
        return $$('input:checked', input).map(function (c) { return c.value; }).join(', ');
      } });
      input.focus = function () { var c = $('input', input); if (c) c.focus(); };
    } else if (type === 'textarea') {
      input = document.createElement('textarea'); input.value = value; input.rows = 4;
    } else {
      input = document.createElement('input');
      input.type = type === 'date' ? 'date' : 'text';
      if (type === 'money') input.inputMode = 'decimal';
      input.value = value;
    }
    input.setAttribute('aria-label', (ie.querySelector('.ie-b') || {}).getAttribute ? ie.querySelector('.ie-b').getAttribute('aria-label') : 'Editar');
    var ok = document.createElement('button');
    ok.type = 'button'; ok.className = 'ibtn'; ok.setAttribute('aria-label', 'Guardar'); ok.setAttribute('data-tip', '');
    ok.innerHTML = '<svg class="ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>';
    var no = document.createElement('button');
    no.type = 'button'; no.className = 'ibtn'; no.setAttribute('aria-label', 'Cancelar (Esc)'); no.setAttribute('data-tip', '');
    no.innerHTML = '<svg class="ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>';
    ie.classList.add('editing');
    var view = $('.ie-v', ie), pen = $('.ie-b', ie);
    view.hidden = true; pen.hidden = true;
    ie.appendChild(input); ie.appendChild(ok); ie.appendChild(no);
    input.focus();
    function end() {
      [input, ok, no].forEach(function (n) { if (n.parentNode) n.parentNode.removeChild(n); });
      view.hidden = false; pen.hidden = false; ie.classList.remove('editing'); hideTip();
    }
    function save() {
      var v = input.value;
      if (v === value) { end(); pen.focus(); return; }
      input.disabled = ok.disabled = true;
      api('inline', { entity: ie.getAttribute('data-ie'), id: ie.getAttribute('data-id'), field: ie.getAttribute('data-field'), value: v })
        .then(function (j) {
          view.textContent = j.display;
          ie.setAttribute('data-value', type === 'money' && j.value !== null ? String(j.value).replace('.', ',') : (j.value == null ? '' : j.value));
          end(); pen.focus();
          ie.classList.remove('saved'); void ie.offsetWidth; ie.classList.add('saved');
          var inPanel = ie.closest('.sp');
          var refresh = inPanel && spKey ? function () { openPanel(spKey); } : null;
          toast('Guardado.', j.undo ? { undo: j.undo, afterUndo: refresh } : {});
          // Campos que mudam o resto (prazo, nome, contactos): volta a desenhar o painel ou a página.
          if (j.reload) setTimeout(function () { if (refresh) refresh(); else location.reload(); }, inPanel ? 250 : 700);
        })
        .catch(function (e) { input.disabled = ok.disabled = false; input.focus(); toast(e.message, { err: true }); });
    }
    ok.addEventListener('click', save);
    no.addEventListener('click', function () { end(); pen.focus(); });
    if (type === 'select') input.addEventListener('change', save);
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); end(); pen.focus(); }
      if (e.key === 'Enter' && (type !== 'textarea' || e.metaKey || e.ctrlKey)) { e.preventDefault(); save(); }
    });
  }
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('.ie-b');
    if (b) { e.preventDefault(); ieStart(b.closest('.ie')); }
  });

  // ── registar interação (§11.9) ───────────────────────────
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (!f.matches || !f.matches('form[data-log]')) return;
    e.preventDefault();
    var btn = $('button[type=submit]', f); if (btn) btn.disabled = true;
    api('interaction', new FormData(f)).then(function (j) {
      var list = f.closest('.hist') && $('.hist-list', f.closest('.hist'));
      if (list) list.innerHTML = j.html;
      $('textarea', f).value = '';
      toast(j.msg);
    }).catch(function (er) { toast(er.message, { err: true }); })
      .then(function () { if (btn) btn.disabled = false; });
  });

  // ── pesquisa global ⌘K / Ctrl+K (§11.11) ─────────────────
  var ck = $('.cmdk-back'), ckIn = ck && $('input', ck), ckRes = ck && $('.cmdk-res', ck), ckSeq = 0, ckTimer = null, ckReturn = null;
  function ckItems() { return $$('.cmdk-it', ckRes); }
  function ckSel(i) {
    var it = ckItems(); if (!it.length) return;
    i = (i + it.length) % it.length;
    it.forEach(function (n, k) { n.classList.toggle('on', k === i); n.setAttribute('aria-selected', k === i ? 'true' : 'false'); });
    it[i].scrollIntoView({ block: 'nearest' });
    ckIn.setAttribute('aria-activedescendant', it[i].id);
  }
  function ckRender(groups) {
    var n = 0, h = '';
    groups.forEach(function (g) {
      h += '<div class="cmdk-g" role="presentation">' + esc(g.name) + '</div>';
      g.items.forEach(function (it) {
        h += '<a class="cmdk-it" role="option" id="ck-' + (n++) + '"'
          + (it.url ? ' href="' + esc(it.url) + '"' : ' href="#"')
          + (it.panel ? ' data-panel-key="' + esc(it.panel) + '"' : '')
          + (it.action ? ' data-action="' + esc(it.action) + '"' : '') + '>'
          + it.icon + '<span class="tt">' + esc(it.title) + (it.sub ? '<small>' + esc(it.sub) + '</small>' : '') + '</span>'
          + (it.id ? '<span class="id">' + esc(it.id) + '</span>' : '') + '</a>';
      });
    });
    ckRes.innerHTML = h || '<div class="cmdk-empty">Nada encontrado' + (ckIn.value.trim().length < 2 ? '.' : ' para «' + esc(ckIn.value.trim()) + '».') + '</div>';
    ckSel(0);
  }
  function ckSearch() {
    var my = ++ckSeq;
    api('search', { q: ckIn.value }, 'GET').then(function (j) { if (my === ckSeq) ckRender(j.groups); })
      .catch(function (e) { if (my === ckSeq) ckRes.innerHTML = '<div class="cmdk-empty">' + esc(e.message) + '</div>'; });
  }
  function ckOpen() {
    if (!ck) return;
    ckReturn = document.activeElement;
    ck.classList.add('open'); ckIn.value = ''; ckIn.focus(); ckSearch();
  }
  function ckClose() {
    if (!ck || !ck.classList.contains('open')) return;
    ck.classList.remove('open');
    if (ckReturn && ckReturn.focus) ckReturn.focus({ preventScroll: true });
  }
  function ckGo(it, side) {
    if (!it) return;
    var act = it.getAttribute('data-action');
    if (act === 'theme') { ckClose(); toggleTheme(innerWidth - 60, 30); return; }
    var key = it.getAttribute('data-panel-key');
    if (side && key) { ckClose(); openPanel(key); return; }
    location.href = it.getAttribute('href');
  }
  if (ck) {
    $$('[data-cmdk]').forEach(function (b) { b.addEventListener('click', ckOpen); });
    ckIn.addEventListener('input', function () { clearTimeout(ckTimer); ckTimer = setTimeout(ckSearch, 140); });
    ckIn.addEventListener('keydown', function (e) {
      var it = ckItems(), cur = it.indexOf($('.cmdk-it.on', ckRes));
      if (e.key === 'ArrowDown') { e.preventDefault(); ckSel(cur + 1); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); ckSel(cur - 1); }
      else if (e.key === 'Enter') { e.preventDefault(); ckGo(it[cur], e.metaKey || e.ctrlKey); }
      else if (e.key === 'Escape') { e.preventDefault(); ckClose(); }
    });
    ckRes.addEventListener('click', function (e) {
      var it = e.target.closest('.cmdk-it'); if (!it) return;
      e.preventDefault(); ckGo(it, e.metaKey || e.ctrlKey);
    });
    ckRes.addEventListener('mousemove', function (e) {
      var it = e.target.closest('.cmdk-it'); if (!it || it.classList.contains('on')) return;
      ckSel(ckItems().indexOf(it));
    });
    ck.addEventListener('click', function (e) { if (e.target === ck) ckClose(); });
  }

  // ── teclado: ⌘K, Esc ─────────────────────────────────────
  document.addEventListener('keydown', function (e) {
    if ((e.metaKey || e.ctrlKey) && !e.altKey && (e.key === 'k' || e.key === 'K')) {
      e.preventDefault();
      if (ck && ck.classList.contains('open')) ckClose(); else ckOpen();
      return;
    }
    if (!typing(document.activeElement) && !e.metaKey && !e.ctrlKey && !e.altKey) {
      if (e.key === '[' && !(ck && ck.classList.contains('open'))) { e.preventDefault(); toggleNav(); return; }
      if (sp && sp.classList.contains('open') && !$('.mdl.open') && !$('.lv-form:not([hidden])', sp)) {
        if (e.key === 'ArrowDown' || e.key === 'j' || e.key === 'J') { e.preventDefault(); panelStep(1); return; }
        if (e.key === 'ArrowUp' || e.key === 'k' || e.key === 'K') { e.preventDefault(); panelStep(-1); return; }
      }
    }
    if (e.key === 'Escape') {
      if (ck && ck.classList.contains('open')) return;
      if ($('.mdl.open')) return;   // os formulários (leads.js) fecham-se a si próprios
      if (pop && pop.classList.contains('open')) { popOpen(false); bell.focus(); return; }
      if (sp && sp.classList.contains('open') && !typing(document.activeElement)) { closePanel(); return; }
      if (body.classList.contains('nav-open')) navOpen(false);
      hideTip();
    }
  });

  // ── Pipeline: arrastar sem recarregar, totais em tempo real (§11.13) ──
  var board = $('.pboard[data-live]');
  if (board) {
    var dragged = null, origin = null;
    var recount = function () {
      $$('.col', board).forEach(function (col) {
        var cards = $$('.dcard:not([hidden])', col), sum = 0;
        cards.forEach(function (c) { sum += parseFloat(c.getAttribute('data-value') || '0') || 0; });
        var cnt = $('[data-cnt]', col), tot = $('[data-sum]', col);
        if (cnt) countTo(cnt, cards.length, false);
        if (tot) countTo(tot, sum, true);
      });
      var all = $$('.dcard:not([hidden])', board), v = 0;
      all.forEach(function (c) { v += parseFloat(c.getAttribute('data-value') || '0') || 0; });
      $$('[data-kpi="open-value"]').forEach(function (k) { countTo(k, v, true); });
      $$('[data-kpi="open-count"]').forEach(function (k) { countTo(k, all.length, false); });
      $$('[data-kpi="stale"]').forEach(function (k) { countTo(k, $$('.dcard.risk:not([hidden])', board).length, false); });
    };
    var move = function (card, col) {
      if (!col || col.contains(card)) return;
      origin = { col: card.parentNode, next: card.nextSibling };
      var list = $('.cards', col);
      list.insertBefore(card, list.firstChild);
      card.classList.remove('moved'); void card.offsetWidth; card.classList.add('moved');
      recount();
      var from = origin;
      var sel = $('.dmove select', card); if (sel) sel.value = col.getAttribute('data-stage');
      api('deal_move', { id: card.getAttribute('data-id'), stage_id: col.getAttribute('data-stage') }).then(function (j) {
        if (j.won) {
          var val = parseFloat(card.getAttribute('data-value') || '0') || 0;
          $$('[data-kpi="won-value"]').forEach(function (k) { countTo(k, (parseFloat(k.getAttribute('data-n') || '0') || 0) + val, true); });
          $$('[data-kpi="won-count"]').forEach(function (k) { countTo(k, (parseFloat(k.getAttribute('data-n') || '0') || 0) + 1, false); });
          setTimeout(function () { card.hidden = true; recount(); }, reduced.matches ? 0 : 220);
        }
        toast(j.msg, j.undo ? { undo: j.undo } : {});
      }).catch(function (e) {
        from.col.insertBefore(card, from.next);
        if (sel) sel.value = from.col.closest('.col').getAttribute('data-stage');
        recount();
        toast(e.message, { err: true });
      });
    };
    board.addEventListener('dragstart', function (e) {
      var c = e.target.closest('.dcard[draggable="true"]'); if (!c) return;
      dragged = c; c.classList.add('dragging'); e.dataTransfer.effectAllowed = 'move';
      e.dataTransfer.setData('text/plain', c.getAttribute('data-id'));
    });
    board.addEventListener('dragend', function () {
      if (dragged) dragged.classList.remove('dragging'); dragged = null;
      $$('.col.over', board).forEach(function (c) { c.classList.remove('over'); });
    });
    board.addEventListener('dragover', function (e) {
      var col = e.target.closest('.col'); if (!col || !dragged) return;
      e.preventDefault();
      $$('.col.over', board).forEach(function (c) { if (c !== col) c.classList.remove('over'); });
      col.classList.add('over');
    });
    board.addEventListener('drop', function (e) {
      var col = e.target.closest('.col'); if (!col || !dragged) return;
      e.preventDefault(); col.classList.remove('over');
      move(dragged, col);
    });
    // Sem arrastar (toque, teclado): o «Mover para» de cada cartão faz o mesmo, também sem recarregar.
    board.addEventListener('change', function (e) {
      var s = e.target.closest('.dmove select'); if (!s) return;
      var card = s.closest('.dcard'), col = $('.col[data-stage="' + s.value + '"]', board);
      move(card, col);
    });
    board.addEventListener('submit', function (e) { if (e.target.closest('.dmove')) e.preventDefault(); });
  }
})();
