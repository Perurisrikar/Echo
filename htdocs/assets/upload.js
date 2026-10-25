/* Chunked study-material upload. Sends the file in ~1 MB pieces so a big file does not hit the host's
   per-request limits and a flaky mobile connection only retries one piece. Forms without JS still post normally. */
(function () {
  'use strict';
  var PIECE = 600 * 1024;

  function rand24() {
    var a = new Uint8Array(12); (window.crypto || window.msCrypto).getRandomValues(a);
    return Array.prototype.map.call(a, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
  }
  function sleep(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }

  // Piece -> base64url text (a plain form field, not a binary file part).
  function b64url(blob) {
    return new Promise(function (ok, bad) {
      var r = new FileReader();
      r.onload = function () { ok(String(r.result).replace(/^data:[^,]*,/, '').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')); };
      r.onerror = function () { bad(new Error('Could not read the file.')); };
      r.readAsDataURL(blob);
    });
  }
  function textB64(str) { return btoa(unescape(encodeURIComponent(str))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, ''); }

  function send(url, fields, blob, tries) {
    var prep = blob ? b64url(blob).then(function (t) { return Object.assign({}, fields, { data: t }); }) : Promise.resolve(fields);
    return prep.then(function (f) { return post(url, f, blob, tries); });
  }
  function post(url, fields, blob, tries) {
    var body = new URLSearchParams();
    Object.keys(fields).forEach(function (k) { body.append(k, fields[k]); });
    return fetch(url, { method: 'POST', body: body, credentials: 'same-origin' }).then(function (res) {
      return res.text().then(function (txt) {
        var data = null; try { data = JSON.parse(txt); } catch (e) {}
        if (res.ok && data && data.ok) return data;
        var retry = res.status >= 500 || res.status === 0;
        var err = (data && data.error) || ('The server answered ' + res.status + (res.status === 403 ? ' (blocked)' : '') + '.');
        var e = new Error(err); e.retry = retry; e.status = res.status; throw e;
      });
    }, function () { var e = new Error('The connection dropped.'); e.retry = true; throw e; }).catch(function (e) {
      if (e.retry && tries > 1) return sleep(900 * (4 - tries + 1)).then(function () { return post(url, fields, blob, tries - 1); });
      throw e;
    });
  }

  document.addEventListener('submit', function (ev) {
    var form = ev.target;
    if (!form || !form.hasAttribute || !form.hasAttribute('data-chunk-upload') || !window.fetch || !window.FormData) return;
    var input = form.querySelector('input[type=file]');
    var file = input && input.files && input.files[0];
    if (!file) return;                       // let the browser show its "choose a file" message
    ev.preventDefault();

    var btn = form.querySelector('button[type=submit]'), label = btn ? btn.textContent : '';
    var note = form.querySelector('.up-note');
    if (!note) { note = document.createElement('div'); note.className = 'up-note'; form.appendChild(note); }
    note.className = 'up-note'; note.innerHTML = '<div class="up-bar"><div></div></div><div class="up-text"></div>';
    var bar = note.firstChild.firstChild, txt = note.lastChild;
    function busy(on) { if (btn) { btn.disabled = on; if (!on) btn.textContent = label; } }
    function fail(msg) { busy(false); note.className = 'up-note banner warn'; note.textContent = msg; }

    var action = form.getAttribute('action') || 'material_manage.php';
    var url = action.replace(/material_manage\.php.*$/, 'material_chunk.php');
    var get = function (n) { var el = form.querySelector('[name="' + n + '"]'); return el ? el.value : ''; };
    var uid = rand24(), total = Math.max(1, Math.ceil(file.size / PIECE));
    var base = { csrf_token: get('csrf_token'), uid: uid, section: get('section'), subject: get('subject') };
    busy(true);
    var i = 0;
    function next() {
      if (i >= total) return Promise.resolve();
      var pct = Math.round(i / total * 100);
      bar.style.transform = 'scaleX(' + (pct / 100) + ')'; txt.textContent = 'Uploading… ' + pct + '%  (keep this page open)'; if (btn) btn.textContent = 'Uploading ' + pct + '%';
      var piece = file.slice(i * PIECE, Math.min(file.size, (i + 1) * PIECE));
      return send(url, Object.assign({ action: 'chunk', index: String(i), total: String(total) }, base), piece, 4).then(function () { i++; return next(); }, function (e) { e.stage = 'piece ' + (i + 1) + ' of ' + total; throw e; });
    }
    next().then(function () {
      bar.style.transform = 'scaleX(1)'; txt.textContent = 'Finishing…';
      return send(url, Object.assign({ action: 'finish', total: String(total), size: String(file.size), name_b64: textB64(file.name), title: get('title'), description: get('description') }, base), null, 3).catch(function (e) { e.stage = 'the final step'; throw e; });
    }).then(function () {
      var back = get('back') || 'materials.php';
      location.href = new URL(back, new URL(action, location.href).href.replace(/material_manage\.php.*$/, '')).href;
    }).catch(function (e) {
      send(url, Object.assign({ action: 'cancel' }, base), null, 1).catch(function () {});
      fail((e && e.message ? e.message : 'The upload failed.') + (e && e.stage ? ' (stopped at ' + e.stage + ')' : '') + (e && e.status === 403 ? ' If this keeps happening, add the file as a link instead.' : ''));
    });
  });
})();
