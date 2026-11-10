/* Admin helpers (no dependencies): unsaved-changes warning and confirm dialogs. */
(function () {
  'use strict';
  var form = document.querySelector('form[data-warn-unsaved]');
  if (form) {
    var dirty = false;
    form.addEventListener('input', function () { dirty = true; });
    form.addEventListener('submit', function () { dirty = false; });
    window.addEventListener('beforeunload', function (e) {
      if (dirty) { e.preventDefault(); e.returnValue = ''; }
    });
  }
  document.addEventListener('click', function (e) {
    var cp = e.target.closest ? e.target.closest('[data-copy]') : null;
    if (cp) {
      var text = cp.getAttribute('data-copy');
      var done = function () { var old = cp.textContent; cp.textContent = 'Copied'; setTimeout(function () { cp.textContent = old; }, 1500); };
      if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(text).then(done); }
      else { var ta = document.createElement('textarea'); ta.value = text; document.body.appendChild(ta); ta.select(); try { document.execCommand('copy'); done(); } catch (err) { /* ignore */ } document.body.removeChild(ta); }
      return;
    }
    var el = e.target.closest ? e.target.closest('[data-confirm]') : null;
    if (el && !window.confirm(el.getAttribute('data-confirm'))) { e.preventDefault(); }
  });
})();
