/* In-site file viewer. PDFs use the browser's own viewer on desktop and pdf.js on phones (Android cannot embed PDFs),
   images and text show directly, Word files are converted to readable text, and "Open in app" hands the file to the
   phone's native app through the share sheet so nothing lands in the Downloads folder. */
(function () {
  'use strict';
  var root = document.getElementById('viewer');
  var statusEl = document.getElementById('viewerStatus');
  var shareBtn = document.getElementById('shareBtn');
  var back = document.getElementById('viewerBack');
  var d = root.dataset;
  var mode = d.mode, url = d.url;
  var PDFJS = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/';
  var MAMMOTH = 'https://cdnjs.cloudflare.com/ajax/libs/mammoth/1.4.2/mammoth.browser.min.js';

  // "Back" returns to the exact list the student came from (filters and all) when possible.
  back.addEventListener('click', function (e) {
    try { if (document.referrer && new URL(document.referrer).origin === location.origin && history.length > 1) { e.preventDefault(); history.back(); } } catch (x) {}
  });

  function say(msg) { statusEl.textContent = msg; statusEl.hidden = false; }
  function clearStatus() { statusEl.hidden = true; }
  function loadScript(src) {
    return new Promise(function (ok, fail) {
      var s = document.createElement('script'); s.src = src; s.onload = ok; s.onerror = function () { fail(new Error('load ' + src)); };
      document.head.appendChild(s);
    });
  }

  // Download the file once; the viewer, the zoom and "Open in app" all reuse it.
  var blobP = null;
  function getBlob() {
    if (!blobP) blobP = fetch(url + '&dl=1', { credentials: 'same-origin' }).then(function (r) {
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return r.blob();
    });
    return blobP;
  }

  // ---- Open in… (the phone's share sheet) ----
  var isAndroid = /android/i.test(navigator.userAgent);
  function setupShare() {
    var test;
    try { test = new File([''], 'a.txt', { type: 'text/plain' }); } catch (e) { return; }
    if (!navigator.canShare || !navigator.canShare({ files: [test] })) return;   // desktop browsers: Download is enough
    shareBtn.hidden = false;
    shareBtn.textContent = 'Preparing…';
    getBlob().then(function (blob) {
      var file = new File([blob], d.name, { type: d.mime });
      if (!navigator.canShare({ files: [file] })) { shareBtn.hidden = true; return; }
      shareBtn.disabled = false; shareBtn.textContent = 'Open in…';
      shareBtn.addEventListener('click', function () {
        navigator.share({ files: [file], title: d.name }).catch(function () { /* cancelled */ });
      });
    }).catch(function () { shareBtn.hidden = true; });
  }

  function fallbackCard(why) {
    root.innerHTML = '';
    var c = document.createElement('div'); c.className = 'viewer-card';
    var b = document.createElement('div'); b.className = 'viewer-bigtype'; b.textContent = d.ext || 'FILE';
    var h = document.createElement('h2'); h.textContent = d.name;
    var p = document.createElement('p'); p.className = 'hint';
    p.textContent = why || "This kind of file can't be previewed here.";
    var tip = document.createElement('p'); tip.className = 'hint';
    tip.textContent = !shareBtn.hidden ? 'Tap “Open in…” above and pick an app that can open it (for example WPS, Adobe or Docs).' : 'Use Download to save it, then open it in any app.';
    c.appendChild(b); c.appendChild(h); c.appendChild(p); c.appendChild(tip); root.appendChild(c);
  }

  // ---- PDF ----
  function nativePdfOk() {
    var coarse = window.matchMedia && window.matchMedia('(pointer: coarse)').matches;
    return !coarse && navigator.pdfViewerEnabled === true;
  }
  function showNativePdf() {
    root.innerHTML = '';
    var f = document.createElement('iframe');
    f.className = 'viewer-frame'; f.title = d.name; f.src = url;
    root.appendChild(f);
  }
  function showPdfJs() {
    say('Loading viewer…');
    return loadScript(PDFJS + 'pdf.min.js').then(function () {
      var lib = window.pdfjsLib;
      lib.GlobalWorkerOptions.workerSrc = PDFJS + 'pdf.worker.min.js';
      return getBlob().then(function (b) { return b.arrayBuffer(); }).then(function (buf) {
        return lib.getDocument({ data: buf, isEvalSupported: false }).promise;
      });
    }).then(function (pdf) {
      clearStatus();
      var tools = document.getElementById('pdfTools'), info = document.getElementById('pageInfo');
      tools.hidden = false;
      root.innerHTML = ''; root.classList.add('pdf-scroll');
      var zoom = 1, pages = [], rendered = {}, tasks = {};
      var firstW = 0, firstH = 0;

      function targetScale(vp1) { return Math.max(0.3, (root.clientWidth - 16) / vp1.width * zoom); }
      function sizePage(p, vp) { p.box.style.width = Math.floor(vp.width) + 'px'; p.box.style.height = Math.floor(vp.height) + 'px'; }

      function renderPage(i) {
        if (rendered[i] === zoom || tasks[i]) return;
        tasks[i] = true;
        pdf.getPage(i + 1).then(function (page) {
          var base = page.getViewport({ scale: 1 });
          var scale = targetScale(base), vp = page.getViewport({ scale: scale });
          var ratio = Math.min(window.devicePixelRatio || 1, 2);
          var cv = document.createElement('canvas');
          cv.width = Math.floor(vp.width * ratio); cv.height = Math.floor(vp.height * ratio);
          cv.style.width = Math.floor(vp.width) + 'px'; cv.style.height = Math.floor(vp.height) + 'px';
          var ctx = cv.getContext('2d');
          return page.render({ canvasContext: ctx, viewport: vp, transform: ratio !== 1 ? [ratio, 0, 0, ratio, 0, 0] : null }).promise.then(function () {
            sizePage(pages[i], vp);
            pages[i].box.innerHTML = ''; pages[i].box.appendChild(cv);
            rendered[i] = zoom;
          });
        }).catch(function () {}).then(function () { tasks[i] = false; });
      }

      var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (e) { if (e.isIntersecting) renderPage(parseInt(e.target.dataset.i, 10)); });
        var best = -1, bestR = 0;
        pages.forEach(function (p, i) { var r = p.box.getBoundingClientRect(); var vis = Math.min(r.bottom, innerHeight) - Math.max(r.top, 0); if (vis > bestR) { bestR = vis; best = i; } });
        if (best >= 0) info.textContent = 'Page ' + (best + 1) + ' of ' + pdf.numPages;
      }, { root: null, rootMargin: '600px 0px', threshold: [0, 0.25, 0.5, 0.75, 1] });

      function layout() {
        return pdf.getPage(1).then(function (p1) {
          var base = p1.getViewport({ scale: 1 }); firstW = base.width; firstH = base.height;
          var vp = p1.getViewport({ scale: targetScale(base) });
          pages.forEach(function (p) { sizePage(p, vp); });
        });
      }
      for (var i = 0; i < pdf.numPages; i++) {
        var box = document.createElement('div'); box.className = 'pdf-page'; box.dataset.i = String(i);
        root.appendChild(box); pages.push({ box: box }); io.observe(box);
      }
      info.textContent = 'Page 1 of ' + pdf.numPages;
      layout().then(function () { pages.forEach(function (p, i) { io.unobserve(p.box); io.observe(p.box); }); });

      function setZoom(z) {
        zoom = Math.min(3, Math.max(0.5, z)); rendered = {};
        layout().then(function () { pages.forEach(function (p, i) { p.box.innerHTML = ''; io.unobserve(p.box); io.observe(p.box); }); });
      }
      document.getElementById('zoomIn').addEventListener('click', function () { setZoom(zoom + 0.25); });
      document.getElementById('zoomOut').addEventListener('click', function () { setZoom(zoom - 0.25); });
      var rt; window.addEventListener('resize', function () { clearTimeout(rt); rt = setTimeout(function () { setZoom(zoom); }, 250); });
    });
  }

  // ---- Image / text / Word ----
  function showImage() {
    root.innerHTML = '';
    var img = document.createElement('img'); img.className = 'viewer-img'; img.alt = d.name; img.src = url;
    img.addEventListener('click', function () { img.classList.toggle('zoomed'); });
    img.addEventListener('error', function () { fallbackCard('The image could not be loaded.'); });
    root.appendChild(img);
    var hint = document.createElement('p'); hint.className = 'viewer-hint'; hint.textContent = 'Tap the image to zoom.'; root.appendChild(hint);
  }
  function showText() {
    return getBlob().then(function (b) { return b.text(); }).then(function (t) {
      root.innerHTML = ''; var pre = document.createElement('pre'); pre.className = 'viewer-text'; pre.textContent = t; root.appendChild(pre);
    });
  }
  function cleanHtml(html) {
    var doc = new DOMParser().parseFromString(html, 'text/html');
    doc.querySelectorAll('script,style,iframe,object,embed,link,meta,form').forEach(function (n) { n.remove(); });
    doc.querySelectorAll('*').forEach(function (n) {
      Array.prototype.slice.call(n.attributes).forEach(function (a) {
        var nm = a.name.toLowerCase(), v = a.value.trim().toLowerCase();
        if (nm.indexOf('on') === 0 || ((nm === 'href' || nm === 'src') && /^(javascript|data:text|vbscript)/.test(v) && !(nm === 'src' && /^data:image\//.test(v)))) n.removeAttribute(a.name);
      });
      if (n.tagName === 'A') { n.setAttribute('target', '_blank'); n.setAttribute('rel', 'noopener noreferrer'); }
    });
    return doc.body;
  }
  function showDocx() {
    say('Preparing document…');
    return loadScript(MAMMOTH).then(getBlob).then(function (b) { return b.arrayBuffer(); }).then(function (buf) {
      return window.mammoth.convertToHtml({ arrayBuffer: buf });
    }).then(function (res) {
      root.innerHTML = '';
      var wrap = document.createElement('article'); wrap.className = 'viewer-doc';
      var body = cleanHtml(res.value);
      while (body.firstChild) wrap.appendChild(body.firstChild);
      var note = document.createElement('p'); note.className = 'viewer-hint';
      note.textContent = 'Simplified view — fonts, columns and some layout are not shown. Use “Open in…” for the original.';
      root.appendChild(note); root.appendChild(wrap);
    });
  }

  setupShare();
  var job;
  if (mode === 'pdf') job = nativePdfOk() ? Promise.resolve(showNativePdf()) : showPdfJs();
  else if (mode === 'image') job = Promise.resolve(showImage());
  else if (mode === 'text') job = showText();
  else if (mode === 'docx') job = showDocx();
  else { fallbackCard(); job = Promise.resolve(); }
  job.then(function () { if (mode !== 'pdf') clearStatus(); }).catch(function () {
    fallbackCard(mode === 'pdf' || mode === 'docx' ? "The preview couldn't load (it needs a normal internet connection)." : 'The file could not be opened.');
  });
})();
