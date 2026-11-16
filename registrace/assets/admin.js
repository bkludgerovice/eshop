(function () {
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-confirm]');
    if (b && !confirm(b.getAttribute('data-confirm'))) { ev.preventDefault(); return; }
    var c = ev.target.closest('[data-copy]');
    if (c) {
      var t = document.getElementById(c.getAttribute('data-copy'));
      t.select();
      (navigator.clipboard ? navigator.clipboard.writeText(t.value) : Promise.resolve(document.execCommand('copy')))
        .then(function () { c.textContent = 'Zkopírováno ✓'; });
    }
    var p = ev.target.closest('[data-preset]');
    if (p) {
      var cols = p.getAttribute('data-preset').split(',');
      document.querySelectorAll('#exportform input[name="cols[]"]').forEach(function (i) { i.checked = cols.indexOf(i.value) >= 0; });
    }
  });
})();
