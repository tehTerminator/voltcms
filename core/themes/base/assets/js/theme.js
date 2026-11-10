/* Base theme script (loaded on every page, deferred). Makes wide tables in content scroll sideways on small screens. */
(function () {
  'use strict';
  document.querySelectorAll('.cms-content table').forEach(function (table) {
    if (table.parentElement && table.parentElement.classList.contains('table-responsive')) { return; }
    var wrap = document.createElement('div');
    wrap.className = 'table-responsive';
    table.parentNode.insertBefore(wrap, table);
    wrap.appendChild(table);
  });
})();
