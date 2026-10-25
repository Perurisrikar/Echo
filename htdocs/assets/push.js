/* Notification card: turns push on/off for this device and saves the two preferences. */
(function () {
  'use strict';
  var card = document.getElementById('pushCard');
  if (!card) return;
  var $ = function (id) { return document.getElementById(id); };
  var msg = $('pushMsg'), on = $('pushOn'), off = $('pushOff'), later = $('pushLater'), prefs = $('pushPrefs');
  var ann = $('pushAnn'), daily = $('pushDaily');
  var prompt = card.dataset.prompt === '1', haveServer = card.dataset.have === '1';
  var DISMISS = 'echo_push_dismissed';

  function b64uToBytes(s) {
    s = s.replace(/-/g, '+').replace(/_/g, '/'); while (s.length % 4) s += '=';
    var r = atob(s), a = new Uint8Array(r.length); for (var i = 0; i < r.length; i++) a[i] = r.charCodeAt(i); return a;
  }
  function bytesToB64u(buf) {
    var b = new Uint8Array(buf), s = ''; for (var i = 0; i < b.length; i++) s += String.fromCharCode(b[i]);
    return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  }
  function post(fields) {
    var fd = new FormData(); fd.append('csrf_token', card.dataset.token);
    Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
    return fetch('push_subscribe.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) {
      return r.text().then(function (t) {
        try { return JSON.parse(t); } catch (e) {
          var plain = t.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 120);
          return { ok: false, error: 'The server answered ' + r.status + (r.status === 403 || r.status === 503 || r.status >= 500 ? ' (the host blocked or failed it)' : '') + (plain ? ': ' + plain : '') + '.' };
        }
      });
    });
  }
  function show(el, yes) { if (el) el.hidden = !yes; }
  function state(text, buttons) { msg.textContent = text; show(on, buttons.on); show(off, buttons.off); show(prefs, buttons.off); show(later, buttons.on && prompt); show(card, true); }

  var isIos = /iphone|ipad|ipod/i.test(navigator.userAgent);
  var standalone = (window.matchMedia && matchMedia('(display-mode: standalone)').matches) || navigator.standalone === true;
  var supported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;

  if (!supported) {
    if (prompt) return;   // don't nag on the home page
    if (isIos && !standalone) state('On iPhone, first tap Share → “Add to Home Screen”, then open Echo from your home screen and turn notifications on here.', {});
    else state("This browser can't show notifications. Chrome on Android or an installed Echo works best.", {});
    return;
  }
  if (prompt) {
    var t = 0; try { t = parseInt(localStorage.getItem(DISMISS) || '0', 10); } catch (e) {}
    if (haveServer || Notification.permission === 'denied' || (t && Date.now() - t < 14 * 864e5)) return;
  }

  var reg = null, sub = null;
  function refresh() {
    if (Notification.permission === 'denied') { state('Notifications are blocked for Echo. Allow them in your browser or phone settings for this site, then reload.', {}); return; }
    if (sub) state('Notifications are on for this device.', { off: true });
    else state(prompt ? 'Get a heads-up for announcements and each morning\'s classes.' : 'Get a heads-up on this device for announcements and each morning\'s classes.', { on: true });
  }
  function fields(s) {
    return { endpoint: s.endpoint, p256dh: bytesToB64u(s.getKey('p256dh')), auth: bytesToB64u(s.getKey('auth')), ann: ann.checked ? '1' : '', daily: daily.checked ? '1' : '' };
  }

  navigator.serviceWorker.ready.then(function (r) { reg = r; return r.pushManager.getSubscription(); }).then(function (s) { sub = s; refresh(); }).catch(function () {});

  on.addEventListener('click', function () {
    on.disabled = true;
    Notification.requestPermission().then(function (perm) {
      if (perm !== 'granted') { refresh(); on.disabled = false; return; }
      return reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64uToBytes(card.dataset.key) }).then(function (s) {
        return post(Object.assign({ action: 'subscribe' }, fields(s))).then(function (res) {
          if (res.ok) { sub = s; refresh(); return; }
          return s.unsubscribe().catch(function () {}).then(function () { sub = null; state(res.error || 'Could not turn notifications on.', { on: true }); });
        });
      });
    }).catch(function () { state('Could not turn notifications on. Please try again.', { on: true }); }).then(function () { on.disabled = false; });
  });
  off.addEventListener('click', function () {
    if (!sub) return;
    var s = sub; post({ action: 'unsubscribe', endpoint: s.endpoint }).then(function () { return s.unsubscribe(); }).then(function () { sub = null; refresh(); }).catch(function () { refresh(); });
  });
  [ann, daily].forEach(function (cb) { cb.addEventListener('change', function () { if (sub) post(Object.assign({ action: 'prefs' }, { endpoint: sub.endpoint, ann: ann.checked ? '1' : '', daily: daily.checked ? '1' : '' })); }); });
  if (later) later.addEventListener('click', function () { try { localStorage.setItem(DISMISS, String(Date.now())); } catch (e) {} show(card, false); });
})();
