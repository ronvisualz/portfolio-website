/*
 * topper. admin — quadro de tarefas
 *
 * Arrastar com Pointer Events (rato, caneta e dedo): no telemóvel
 * arrasta-se pela pega ⠿, com o rato pode ser pelo cartão todo.
 * A mudança aparece logo (otimista); se o servidor recusar, o cartão
 * volta ao sítio e aparece um aviso. Sem JavaScript, o «Mover para…»
 * de cada cartão continua a funcionar como formulário normal.
 *
 * A última coluna, «Feito», guarda as tarefas feitas (mais recentes em
 * cima). Lá o cartão mantém a cor de quem a fez; tirá-lo de lá para
 * uma pilha reabre-o («A fazer») com essa pessoa.
 */
(function () {
  'use strict';
  var board = document.getElementById('board');
  if (!board) return;
  var API = board.getAttribute('data-api');
  var CSRF = board.getAttribute('data-csrf');
  var toastEl = document.getElementById('toast');
  var toastTimer = null;

  board.classList.add('js');

  function toast(msg) {
    if (!toastEl) return;
    toastEl.textContent = msg;
    toastEl.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { toastEl.hidden = true; }, 5000);
  }

  function listOf(memberId) {
    return board.querySelector('.stack-list[data-member="' + memberId + '"]');
  }

  var feito = board.querySelector('.stack.feito');
  var feitoList = feito ? feito.querySelector('.stack-list') : null;
  // feitas que não estão à vista (para lá do «Ver mais»)
  var hiddenDone = feito ? (parseInt(feito.getAttribute('data-total'), 10) || 0) - feitoList.querySelectorAll('.card').length : 0;

  function isDone(list) { return list === feitoList; }

  function recount() {
    board.querySelectorAll('.stack').forEach(function (s) {
      var n = s.querySelectorAll('.card').length;
      if (s === feito) n += hiddenDone;
      var c = s.querySelector('.count');
      if (c) c.textContent = n;
    });
  }

  function ids(list) {
    return Array.prototype.map.call(list.querySelectorAll('.card'), function (c) {
      return c.getAttribute('data-id');
    });
  }

  var STATUS_LABEL = { todo: 'A fazer', done: 'Feito' };

  function setStatus(card, st) {
    var prev = card.getAttribute('data-status');
    if (prev === st) return;
    card.classList.remove('st-' + prev);
    card.classList.add('st-' + st);
    card.setAttribute('data-status', st);
    var lab = card.querySelector('.card-meta .st');
    if (lab) lab.textContent = STATUS_LABEL[st] || st;
  }

  /*
   * Põe o cartão de acordo com a lista onde está: numa pilha, a cor e o
   * dono passam a ser os da pilha e, se vinha de «Feito», reabre; em
   * «Feito», fica feito com a cor de quem o tinha.
   * Devolve o estado anterior, para poder desfazer.
   */
  function snapshot(card) {
    return {
      who: card.style.getPropertyValue('--who'),
      mid: card.getAttribute('data-mid'),
      status: card.getAttribute('data-status'),
      chip: (card.querySelector('.who-chip') || {}).textContent
    };
  }
  function restore(card, s) {
    card.style.setProperty('--who', s.who);
    card.setAttribute('data-mid', s.mid);
    setStatus(card, s.status);
    var chip = card.querySelector('.who-chip');
    if (chip) chip.textContent = s.chip;
    var sel = card.querySelector('.card-move select');
    if (sel) sel.value = s.status === 'done' ? 'done' : s.mid;
  }
  function setWho(card, list) {
    var sel = card.querySelector('.card-move select');
    if (isDone(list)) {
      setStatus(card, 'done');
      if (sel) sel.value = 'done';
      return;
    }
    var stack = list.closest('.stack');
    var mid = list.getAttribute('data-member');
    card.style.setProperty('--who', getComputedStyle(stack).getPropertyValue('--who'));
    card.setAttribute('data-mid', mid);
    if (card.getAttribute('data-status') === 'done') setStatus(card, 'todo');
    var chip = card.querySelector('.who-chip');
    var name = stack.querySelector('header a, header span');
    if (chip && name) chip.textContent = mid === '0' ? 'Sem dono' : name.textContent.trim().split(/\s+/)[0];
    if (sel) sel.value = mid;
  }

  /*
   * Grava a posição atual de um cartão. undo() repõe o DOM se falhar.
   */
  function persist(card, undo) {
    var list = card.parentNode;
    var body = new URLSearchParams();
    body.set('csrf', CSRF);
    body.set('do', 'move');
    body.set('id', card.getAttribute('data-id'));
    body.set('to', list.getAttribute('data-member'));
    if (!isDone(list)) body.set('order', ids(list).join(','));
    card.classList.add('saving');

    fetch(API, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'X-CSRF-Token': CSRF, 'Accept': 'application/json' },
      body: body
    }).then(function (r) {
      return r.json().catch(function () { return { ok: false }; }).then(function (j) {
        if (!r.ok || !j.ok) throw new Error(j.error || 'Não foi possível guardar a mudança.');
        return j;
      });
    }).then(function () {
      card.classList.remove('saving');
    }).catch(function (err) {
      card.classList.remove('saving');
      undo();
      recount();
      toast((err && err.message && !/fetch|network/i.test(err.message))
        ? err.message
        : 'Sem ligação ao servidor. A mudança não foi guardada.');
    });
  }

  function placeAt(card, list, before) {
    var from = { list: card.parentNode, next: card.nextElementSibling };
    var was = snapshot(card);
    if (isDone(list)) before = list.firstElementChild; // feitas: a mais recente em cima
    list.insertBefore(card, before || null);
    setWho(card, list);
    recount();
    return function undo() {
      from.list.insertBefore(card, from.next && from.next.parentNode === from.list ? from.next : null);
      restore(card, was);
    };
  }

  // ── «Mover para…» sem arrastar ────────────────────────────
  board.querySelectorAll('.card-move').forEach(function (form) {
    var sel = form.querySelector('select');
    sel.addEventListener('change', function () {
      var card = form.closest('.card');
      var list = listOf(sel.value);
      if (!list || list === card.parentNode) return;
      var undo = placeAt(card, list, null);
      persist(card, undo);
      card.querySelector('.card-t').focus();
    });
    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      sel.dispatchEvent(new Event('change'));
    });
  });

  // ── «✓ Feito» e «Reabrir» ─────────────────────────────────
  board.addEventListener('submit', function (ev) {
    var form = ev.target;
    var reopen = form.classList.contains('card-reopen');
    if (!reopen && !form.classList.contains('card-done')) return;
    var card = form.closest('.card');
    var list = reopen ? listOf(card.getAttribute('data-mid')) : feitoList;
    if (!list) return; // pilha que não está à vista: deixa ir o formulário
    ev.preventDefault();
    if (list === card.parentNode) return;
    var undo = placeAt(card, list, null);
    persist(card, undo);
    card.querySelector('.card-t').focus();
  });

  // ── arrastar ──────────────────────────────────────────────
  var drag = null;
  var lastDrop = 0;

  // o clique que vem logo a seguir a largar não deve abrir a tarefa
  board.addEventListener('click', function (e) {
    if (Date.now() - lastDrop < 350) { e.preventDefault(); e.stopPropagation(); }
  }, true);

  function cardAfter(list, y) {
    var cards = list.querySelectorAll('.card:not(.dragging)');
    for (var i = 0; i < cards.length; i++) {
      if (cards[i].offsetParent === null) continue; // não está à vista
      var r = cards[i].getBoundingClientRect();
      if (y < r.top + r.height / 2) return cards[i];
    }
    return null;
  }

  function listAt(x, y) {
    var el = document.elementFromPoint(x, y);
    if (!el) return null;
    var stack = el.closest('.stack');
    return stack ? stack.querySelector('.stack-list') : null;
  }

  function begin(e) {
    var card = drag.card;
    var r = card.getBoundingClientRect();
    drag.started = true;
    drag.dx = e.clientX - r.left;
    drag.dy = e.clientY - r.top;
    drag.from = { list: card.parentNode, next: card.nextElementSibling };

    var ghost = card.cloneNode(true);
    ghost.classList.add('ghost');
    ghost.style.width = r.width + 'px';
    ghost.style.setProperty('--who', getComputedStyle(card).getPropertyValue('--who'));
    document.body.appendChild(ghost);
    drag.ghost = ghost;

    var ph = document.createElement('div');
    ph.className = 'card-ph';
    ph.style.height = r.height + 'px';
    card.parentNode.insertBefore(ph, card);
    drag.ph = ph;
    card.classList.add('dragging');
    document.body.classList.add('is-dragging');
  }

  function move(e) {
    if (!drag || e.pointerId !== drag.id) return;
    if (!drag.started) {
      if (Math.abs(e.clientX - drag.x0) + Math.abs(e.clientY - drag.y0) < 5) return;
      begin(e);
    }
    e.preventDefault();
    drag.ghost.style.transform = 'translate(' + (e.clientX - drag.dx) + 'px,' + (e.clientY - drag.dy) + 'px) rotate(1.5deg)';

    var list = listAt(e.clientX, e.clientY);
    if (list) {
      var after = cardAfter(list, e.clientY);
      if (after !== drag.ph.nextElementSibling || drag.ph.parentNode !== list) {
        list.insertBefore(drag.ph, after);
      }
      board.querySelectorAll('.stack.over').forEach(function (s) { s.classList.remove('over'); });
      list.closest('.stack').classList.add('over');
    }
    // perto das margens, o quadro anda sozinho
    var br = board.getBoundingClientRect();
    if (e.clientX > br.right - 40) board.scrollLeft += 14;
    else if (e.clientX < br.left + 40) board.scrollLeft -= 14;
  }

  function end(e) {
    if (!drag || e.pointerId !== drag.id) return;
    var d = drag;
    drag = null;
    document.removeEventListener('pointermove', move);
    document.removeEventListener('pointerup', end);
    document.removeEventListener('pointercancel', end);
    if (!d.started) return;

    var card = d.card;
    var cancelled = e.type === 'pointercancel';
    var list = d.ph.parentNode;
    if (!cancelled) list.insertBefore(card, d.ph);
    d.ph.remove();
    d.ghost.remove();
    card.classList.remove('dragging');
    document.body.classList.remove('is-dragging');
    board.querySelectorAll('.stack.over').forEach(function (s) { s.classList.remove('over'); });
    lastDrop = Date.now();

    if (cancelled) return;
    if (card.parentNode === d.from.list && card.nextElementSibling === d.from.next) return;

    var was = snapshot(card);
    if (isDone(card.parentNode)) {
      if (d.from.list === card.parentNode) { // dentro de «Feito» a ordem é a data
        d.from.list.insertBefore(card, d.from.next && d.from.next.parentNode === d.from.list ? d.from.next : null);
        return;
      }
      card.parentNode.insertBefore(card, card.parentNode.firstElementChild);
    }
    setWho(card, card.parentNode);
    recount();
    persist(card, function () {
      d.from.list.insertBefore(card, d.from.next && d.from.next.parentNode === d.from.list ? d.from.next : null);
      restore(card, was);
    });
  }

  board.addEventListener('pointerdown', function (e) {
    if (drag || e.button > 0) return;
    var card = e.target.closest('.card');
    if (!card) return;
    var onGrip = !!e.target.closest('.grip');
    // Com o dedo só pela pega (para não roubar o deslizar da página);
    // com o rato, o cartão todo, menos os controlos.
    if (!onGrip && (e.pointerType !== 'mouse' || e.target.closest('select, button, input, label, .card-move, .card-acts'))) return;
    if (onGrip) e.preventDefault();
    drag = { card: card, id: e.pointerId, x0: e.clientX, y0: e.clientY, started: false };
    document.addEventListener('pointermove', move, { passive: false });
    document.addEventListener('pointerup', end);
    document.addEventListener('pointercancel', end);
  });

  // links dos cartões não se arrastam como imagem/endereço
  board.addEventListener('dragstart', function (e) { e.preventDefault(); });
})();
