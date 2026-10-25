/* Study-material browser: instant search, subject and type filters, sorting, and saved items.
   Everything is plain DOM work on cards the server already rendered, so it also works (unfiltered) without JS. */
(function () {
  'use strict';
  var root = document.getElementById('matBrowser');
  if (!root) return;

  var list = document.getElementById('matList');
  var cards = Array.prototype.slice.call(list.querySelectorAll('.mat-card'));
  var search = document.getElementById('matSearch');
  var sortSel = document.getElementById('matSort');
  var countEl = document.getElementById('matCount');
  var emptyEl = document.getElementById('matEmpty');
  var savedN = document.getElementById('matSavedN');
  var KEY = 'echo_mat_saved';
  var state = { q: '', subject: '', type: '', sort: 'new' };
  var saved = {};

  function load() { try { saved = JSON.parse(localStorage.getItem(KEY) || '{}') || {}; } catch (e) { saved = {}; } }
  function persist() { try { localStorage.setItem(KEY, JSON.stringify(saved)); } catch (e) {} }

  function readHash() {
    var h = (location.hash || '').replace(/^#/, '');
    if (!h) return;
    h.split('&').forEach(function (kv) {
      var p = kv.split('=');
      var k = p[0], v = decodeURIComponent((p[1] || '').replace(/\+/g, ' '));
      if (k === 's') state.subject = v; else if (k === 't') state.type = v; else if (k === 'q') state.q = v; else if (k === 'o') state.sort = v;
    });
  }
  function writeHash() {
    var parts = [];
    if (state.subject) parts.push('s=' + encodeURIComponent(state.subject));
    if (state.type) parts.push('t=' + encodeURIComponent(state.type));
    if (state.q) parts.push('q=' + encodeURIComponent(state.q));
    if (state.sort !== 'new') parts.push('o=' + state.sort);
    try { history.replaceState(null, '', parts.length ? '#' + parts.join('&') : location.pathname + location.search); } catch (e) {}
  }

  function matches(card) {
    if (state.subject && card.dataset.subject !== state.subject) return false;
    if (state.type === 'saved') { if (!saved[card.dataset.id]) return false; }
    else if (state.type && card.dataset.type !== state.type) return false;
    if (state.q) {
      var hay = card.dataset.search || '';
      var words = state.q.toLowerCase().split(/\s+/).filter(Boolean);
      for (var i = 0; i < words.length; i++) if (hay.indexOf(words[i]) === -1) return false;
    }
    return true;
  }

  function sorted(arr) {
    var a = arr.slice();
    var ts = function (c) { return parseInt(c.dataset.ts, 10) || 0; };
    if (state.sort === 'old') a.sort(function (x, y) { return ts(x) - ts(y); });
    else if (state.sort === 'az') a.sort(function (x, y) { return x.dataset.title.localeCompare(y.dataset.title); });
    else if (state.sort === 'subject') a.sort(function (x, y) { return x.dataset.subjectLabel.localeCompare(y.dataset.subjectLabel) || ts(y) - ts(x); });
    else a.sort(function (x, y) { return ts(y) - ts(x); });
    return a;
  }

  function render() {
    Array.prototype.forEach.call(list.querySelectorAll('.mat-group'), function (n) { n.remove(); });
    var shown = sorted(cards.filter(matches));
    cards.forEach(function (c) { c.hidden = true; });
    var lastSubj = null;
    shown.forEach(function (c) {
      if (state.sort === 'subject' && c.dataset.subject !== lastSubj) {
        lastSubj = c.dataset.subject;
        var h = document.createElement('h3');
        h.className = 'mat-group';
        h.textContent = c.dataset.subjectLabel;
        list.appendChild(h);
      }
      c.hidden = false;
      list.appendChild(c);
    });
    emptyEl.hidden = shown.length > 0;
    var filtered = state.q || state.subject || state.type;
    countEl.textContent = filtered ? shown.length + ' of ' + cards.length + ' shown' : '';
    syncChips();
    writeHash();
  }

  function syncChips() {
    Array.prototype.forEach.call(document.querySelectorAll('#matSubjects .chip'), function (b) {
      var on = (b.dataset.subject || '') === state.subject; b.classList.toggle('on', on); b.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    Array.prototype.forEach.call(document.querySelectorAll('#matTypes .chip'), function (b) {
      var on = (b.dataset.type || '') === state.type; b.classList.toggle('on', on); b.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    sortSel.value = state.sort;
    if (search.value !== state.q) search.value = state.q;
  }

  function refreshStars() {
    var n = 0;
    cards.forEach(function (c) {
      var on = !!saved[c.dataset.id]; if (on) n++;
      var b = c.querySelector('[data-star]');
      b.setAttribute('aria-pressed', on ? 'true' : 'false');
      b.setAttribute('aria-label', on ? 'Remove from saved' : 'Save for later');
      c.classList.toggle('saved', on);
    });
    savedN.textContent = String(n);
  }

  load(); readHash();
  if (!state.subject && root.dataset.initialSubject) state.subject = root.dataset.initialSubject;
  // Forget saved items that were deleted since last visit.
  var live = {}; cards.forEach(function (c) { live[c.dataset.id] = 1; });
  Object.keys(saved).forEach(function (id) { if (!live[id]) delete saved[id]; }); persist();

  var t;
  search.addEventListener('input', function () { clearTimeout(t); t = setTimeout(function () { state.q = search.value.trim(); render(); }, 80); });
  sortSel.addEventListener('change', function () { state.sort = sortSel.value; render(); });
  root.addEventListener('click', function (e) {
    var chip = e.target.closest('.chip');
    if (chip && chip.dataset.subject !== undefined && chip.closest('#matSubjects')) { state.subject = (state.subject === chip.dataset.subject) ? '' : chip.dataset.subject; render(); return; }
    if (chip && chip.dataset.type !== undefined && chip.closest('#matTypes')) { state.type = (state.type === chip.dataset.type) ? '' : chip.dataset.type; render(); return; }
    var star = e.target.closest('[data-star]');
    if (star) {
      var id = star.closest('.mat-card').dataset.id;
      if (saved[id]) delete saved[id]; else saved[id] = 1;
      persist(); refreshStars(); if (state.type === 'saved') render();
    }
  });
  document.getElementById('matClear').addEventListener('click', function () { state.q = ''; state.subject = ''; state.type = ''; render(); search.focus(); });
  document.addEventListener('keydown', function (e) {
    var tag = (e.target.tagName || '').toLowerCase();
    if (e.key === '/' && tag !== 'input' && tag !== 'textarea' && tag !== 'select') { e.preventDefault(); search.focus(); }
    else if (e.key === 'Escape' && e.target === search) { search.value = ''; state.q = ''; render(); search.blur(); }
  });

  refreshStars();
  render();
})();
