// Announcement audience picker: "Everyone" and individual classes are exclusive.
(function () {
  'use strict';
  var list = document.getElementById('scopeList');
  if (!list) return;
  var all = list.querySelector('[data-all]');
  var classes = Array.prototype.slice.call(list.querySelectorAll('input[type="checkbox"]:not([data-all])'));
  list.addEventListener('change', function (e) {
    if (e.target === all) {
      if (all.checked) classes.forEach(function (c) { c.checked = false; });
    } else if (e.target.checked) {
      all.checked = false;
    }
    if (!all.checked && !classes.some(function (c) { return c.checked; })) all.checked = true;
  });
})();

// Selects marked data-autosubmit submit their form when changed (CSP-safe, no inline JS).
document.addEventListener('change', function (e) {
  var t = e.target;
  if (t && t.matches && t.matches('select[data-autosubmit]') && t.form) t.form.submit();
});
