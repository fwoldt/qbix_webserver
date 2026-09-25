(function () {
  // Filter phpinfo()'s rows as you type: rows that match stay, tables and
  // headings with nothing left hide, and the count says how many matched.
  var input = document.getElementById('pi-filter');
  var root = document.getElementById('pi');
  var count = document.getElementById('pi-count');
  var none = document.getElementById('pi-none');
  if (!input || !root) return;
  // The section list starts open where there is room for it, closed on a phone.
  var box = document.getElementById('pi-jumpbox');
  if (box && window.innerWidth > 768) box.open = true;
  if (box) box.addEventListener('click', function (e) { if (e.target.tagName === 'A' && window.innerWidth <= 768) box.open = false; });
  var rows = Array.prototype.slice.call(root.querySelectorAll('tr'));
  function apply() {
    var q = input.value.trim().toLowerCase();
    var shown = 0;
    rows.forEach(function (tr) {
      var hit = !q || tr.textContent.toLowerCase().indexOf(q) !== -1;
      tr.classList.toggle('hidden', !hit);
      if (hit && q) shown++;
    });
    // A table, and the heading before it, hide when none of its rows match.
    Array.prototype.forEach.call(root.querySelectorAll('table'), function (t) {
      var any = !q || t.querySelector('tr:not(.hidden)') !== null;
      var box = t.parentNode.classList && t.parentNode.classList.contains('tw') ? t.parentNode : t;
      box.classList.toggle('hidden', !any);
      var h = box.previousElementSibling;
      if (h && /^H[12]$/.test(h.tagName) && !h.classList.contains('p')) h.classList.toggle('hidden', !any);
    });
    Array.prototype.forEach.call(root.querySelectorAll('p,hr'), function (el) { el.classList.toggle('hidden', !!q); });
    count.textContent = q ? shown + ' matching setting' + (shown === 1 ? '' : 's') : '';
    none.hidden = !(q && shown === 0);
  }
  input.addEventListener('input', apply);
  // "/" jumps to the filter, as on many developer tools, unless typing elsewhere.
  document.addEventListener('keydown', function (e) {
    if (e.key !== '/' || e.ctrlKey || e.metaKey || e.altKey) return;
    var t = e.target;
    if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.isContentEditable)) return;
    e.preventDefault(); input.focus();
  });
})();
