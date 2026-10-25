// Small, dependency-free polish: install support, busy buttons, nav progress bar.
(function () {
  'use strict';

  // Installable app (home-screen icon). Skipped inside /admin/.
  if ('serviceWorker' in navigator && location.pathname.indexOf('/admin/') === -1) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register('sw.js').catch(function () {});
    });
  }

  // Forms marked data-confirm ask first (CSP-safe replacement for inline onsubmit).
  document.addEventListener('submit', function (e) {
    var msg = e.target && e.target.getAttribute && e.target.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) e.preventDefault();
  });

  // Disable a form's submit button once it's sent, so a double-tap can't
  // submit twice, and show that something is happening.
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || form.tagName !== 'FORM') return;
    var btn = form.querySelector('button[type="submit"], button:not([type])');
    if (!btn) return;
    setTimeout(function () {
      if (e.defaultPrevented) return;
      btn.dataset.label = btn.textContent;
      btn.classList.add('busy');
      btn.disabled = true;
    }, 0);
  });
  // Coming back via the browser's back button shouldn't leave buttons stuck.
  window.addEventListener('pageshow', function (e) {
    if (!e.persisted) return;
    document.querySelectorAll('button.busy').forEach(function (b) {
      b.classList.remove('busy');
      b.disabled = false;
    });
    var bar = document.getElementById('navProgress');
    if (bar) bar.classList.remove('on');
  });

  // Messages to faculty start with "Good morning/afternoon/evening". The page may have been open
  // for hours, so the greeting is refreshed to the moment of the tap (India time, as the college is).
  document.addEventListener('click', function (e) {
    var a = e.target.closest && e.target.closest('a[data-wa-live]');
    if (!a) return;
    try {
      var hour = parseInt(new Intl.DateTimeFormat('en-GB', { hour: 'numeric', hour12: false, timeZone: 'Asia/Kolkata' }).format(new Date()), 10) % 24;
      var greet = hour < 12 ? 'Good morning' : (hour < 17 ? 'Good afternoon' : 'Good evening');
      var u = new URL(a.href);
      var text = u.searchParams.get('text');
      if (!text) return;
      var fresh = text.replace(/^Good (morning|afternoon|evening)/, greet);
      if (fresh !== text) a.href = u.origin + u.pathname + '?text=' + encodeURIComponent(fresh);
    } catch (err) { /* keep the server-built link */ }
  }, true);

  // File inputs marked data-max-bytes refuse an oversized file straight away, instead of
  // uploading for minutes and then failing on the server.
  document.addEventListener('change', function (e) {
    var input = e.target;
    if (!input || input.type !== 'file' || !input.getAttribute('data-max-bytes')) return;
    var max = parseInt(input.getAttribute('data-max-bytes'), 10);
    var note = input.nextElementSibling && input.nextElementSibling.classList.contains('file-note') ? input.nextElementSibling : null;
    var file = input.files && input.files[0];
    if (file && max > 0 && file.size > max) {
      var mb = (max / 1048576);
      var msg = 'That file is ' + (file.size / 1048576).toFixed(1) + ' MB — the limit here is ' + (mb >= 10 ? mb.toFixed(0) : mb.toFixed(1)) + ' MB. ' + (input.getAttribute('data-too-big') || 'Please choose a smaller one.');
      if (!note) { note = document.createElement('div'); note.className = 'file-note banner warn'; input.parentNode.insertBefore(note, input.nextSibling); }
      note.textContent = msg;
      input.value = '';
    } else if (note) {
      note.remove();
    }
  });

  // Thin progress bar when moving between pages.
  var bar = document.createElement('div');
  bar.id = 'navProgress';
  bar.setAttribute('aria-hidden', 'true');
  document.body.appendChild(bar);
  document.addEventListener('click', function (e) {
    var a = e.target.closest && e.target.closest('a[href]');
    if (!a || e.defaultPrevented || e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return;
    if (a.target && a.target !== '_self') return;
    if (a.origin !== location.origin || a.hasAttribute('download')) return;
    if (a.pathname === location.pathname && a.search === location.search) return;
    bar.classList.add('on');
  });
})();

// Read-only boxes marked data-select-all select their whole text when clicked (easy copying).
document.addEventListener('click', (e) => {
  const el = e.target.closest && e.target.closest('[data-select-all]');
  if (el && el.select) el.select();
});
