// "Which subjects drop if I skip?" — works out each subject's new percentage
// from the section timetable. Runs entirely in the browser.
(function () {
  'use strict';
  var el = document.getElementById('skip-data');
  if (!el) return;
  var data = JSON.parse(el.textContent);
  var dateIn = document.getElementById('skipDate');
  var addBtn = document.getElementById('skipAdd');
  var chips = document.getElementById('skipChips');
  var out = document.getElementById('skipOut');
  var days = [];

  function ymd(d) { return d; }
  function dow(dateStr) { var d = new Date(dateStr + 'T00:00:00'); var n = d.getDay(); return n === 0 ? 7 : n; }
  function label(dateStr) {
    return new Date(dateStr + 'T00:00:00').toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' });
  }
  function periodsOn(dateStr) {
    var n = dow(dateStr);
    if (data.holidays.indexOf(dateStr) !== -1) return [];
    if (n >= 6) {
      // a weekend only has classes if the admin made it a working day that follows a weekday's timetable
      var f = data.workdays && data.workdays[dateStr];
      if (!f) return [];
      n = f;
    }
    return data.timetable[n] || [];
  }

  function render() {
    chips.textContent = '';
    days.forEach(function (d) {
      var c = document.createElement('button');
      c.type = 'button'; c.className = 'chip'; c.setAttribute('data-day', d);
      var n = periodsOn(d).length;
      c.textContent = label(d) + (n ? '' : ' (no classes)') + '  ×';
      chips.appendChild(c);
    });
    out.textContent = '';
    if (!days.length) return;

    var missed = {};
    days.forEach(function (d) {
      periodsOn(d).forEach(function (code) { missed[code] = (missed[code] || 0) + 1; });
    });
    var affected = data.subjects.filter(function (s) { return missed[s.code]; });
    if (!affected.length) {
      var p = document.createElement('p'); p.className = 'hint'; p.textContent = 'No classes fall on those days, so nothing changes.';
      out.appendChild(p); return;
    }
    affected.forEach(function (s) {
      if (s.attended == null || s.conducted == null || s.conducted <= 0) return;
      var now = s.attended / s.conducted * 100;
      var later = s.attended / (s.conducted + missed[s.code]) * 100;
      var row = document.createElement('div'); row.className = 'subject-row';
      var left = document.createElement('div');
      var name = document.createElement('div'); name.className = 'subject-name'; name.textContent = s.label;
      var sub = document.createElement('div'); sub.className = 'since'; sub.textContent = 'miss ' + missed[s.code] + ' class' + (missed[s.code] === 1 ? '' : 'es');
      left.appendChild(name); left.appendChild(sub);
      var right = document.createElement('span');
      right.className = 'subject-pct ' + (later < data.threshold ? 'warn' : (later < data.threshold + 5 ? 'near' : ''));
      right.textContent = now.toFixed(1) + '% → ' + later.toFixed(1) + '%';
      row.appendChild(left); row.appendChild(right); out.appendChild(row);
    });
  }

  addBtn.addEventListener('click', function () {
    var v = ymd(dateIn.value);
    if (!v || days.indexOf(v) !== -1) return;
    days.push(v); days.sort(); dateIn.value = ''; render();
  });
  chips.addEventListener('click', function (e) {
    var d = e.target && e.target.getAttribute && e.target.getAttribute('data-day');
    if (!d) return;
    days = days.filter(function (x) { return x !== d; }); render();
  });
})();
