/*
 * Lightweight autocomplete for admin pickers (parent page, media image). No dependencies.
 *
 * Markup (rendered by Cms\AutocompleteField):
 *   <div class="ac-wrap" data-ac data-ac-url="/admin/lookup/..." data-ac-label="Selected title" data-ac-thumb="/img.jpg"
 *        data-ac-exclude="12" data-ac-placeholder="Type to search">
 *     <input type="number" name="parent_id" id="parent_id" value="5" data-ac-value>
 *   </div>
 * Without JavaScript the numeric input still works. With it, the input becomes a hidden carrier of the chosen id and a search box
 * with a suggestion list appears. Follows the ARIA 1.2 combobox pattern (listbox popup, aria-activedescendant, live status text).
 * Results come from a JSON endpoint: {"items":[{"id":1,"label":"...","hint":"...","thumb":"..."}]}. Text is always inserted with
 * textContent, never as HTML.
 */
(function () {
  'use strict';

  var DEBOUNCE_MS = 200;
  var counter = 0;

  function make(tag, className, text) {
    var node = document.createElement(tag);
    if (className) { node.className = className; }
    if (text !== undefined) { node.textContent = text; }
    return node;
  }

  function init(root) {
    var field = root.querySelector('[data-ac-value]');
    if (!field || root.getAttribute('data-ac-ready')) { return; }
    root.setAttribute('data-ac-ready', '1');

    var n = ++counter;
    var url = root.getAttribute('data-ac-url');
    var exclude = root.getAttribute('data-ac-exclude');
    var inputId = field.id || ('ac-input-' + n);
    var listId = 'ac-list-' + n;

    var selected = field.value ? {
      id: String(field.value),
      label: root.getAttribute('data-ac-label') || ('#' + field.value),
      thumb: root.getAttribute('data-ac-thumb') || ''
    } : null;

    // The original field now only carries the chosen id; its id moves to the visible search box so <label for> keeps working.
    field.type = 'hidden';
    ['id', 'class', 'min', 'inputmode'].forEach(function (a) { field.removeAttribute(a); });

    var group = make('div', 'input-group');
    var previewBox = make('span', 'input-group-text p-1 d-none');
    var previewImg = make('img', 'ac-thumb rounded');
    previewImg.alt = '';
    previewBox.appendChild(previewImg);

    var input = make('input', 'form-control');
    input.type = 'text';
    input.id = inputId;
    input.placeholder = root.getAttribute('data-ac-placeholder') || '';
    input.spellcheck = false;
    input.setAttribute('autocomplete', 'off');
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-expanded', 'false');
    input.setAttribute('aria-controls', listId);

    var clear = make('button', 'btn btn-outline-secondary d-none', '\u00d7');
    clear.type = 'button';
    clear.setAttribute('aria-label', 'Clear selection');

    var list = make('ul', 'list-group shadow ac-list d-none');
    list.id = listId;
    list.setAttribute('role', 'listbox');
    list.setAttribute('aria-label', 'Suggestions');

    var status = make('div', 'visually-hidden');
    status.setAttribute('role', 'status');
    status.setAttribute('aria-live', 'polite');

    group.appendChild(previewBox);
    group.appendChild(input);
    group.appendChild(clear);
    root.appendChild(group);
    root.appendChild(list);
    root.appendChild(status);

    var items = [];
    var active = -1;
    var isOpen = false;
    var timer = null;
    var controller = null;

    function showSelected() {
      input.value = selected ? selected.label : '';
      if (selected && selected.thumb) {
        previewImg.src = selected.thumb;
        previewBox.classList.remove('d-none');
      } else {
        previewImg.removeAttribute('src');
        previewBox.classList.add('d-none');
      }
      clear.classList.toggle('d-none', !selected);
    }

    function setOpen(open) {
      isOpen = open;
      list.classList.toggle('d-none', !open);
      input.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (!open) {
        active = -1;
        input.removeAttribute('aria-activedescendant');
      }
    }

    function setActive(index) {
      var options = list.querySelectorAll('[role=option]');
      if (active >= 0 && options[active]) { options[active].setAttribute('aria-selected', 'false'); }
      active = index;
      if (index >= 0 && options[index]) {
        options[index].setAttribute('aria-selected', 'true');
        input.setAttribute('aria-activedescendant', options[index].id);
        if (options[index].scrollIntoView) { options[index].scrollIntoView({ block: 'nearest' }); }
      } else {
        input.removeAttribute('aria-activedescendant');
      }
    }

    function choose(item) {
      selected = item ? { id: String(item.id), label: item.label, thumb: item.thumb || '' } : null;
      field.value = selected ? selected.id : '';
      showSelected();
      setOpen(false);
      // lets the form's unsaved-changes warning and any other listener know
      field.dispatchEvent(new Event('input', { bubbles: true }));
      field.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function message(text) {
      list.textContent = '';
      items = [];
      var li = make('li', 'list-group-item small text-secondary', text);
      li.setAttribute('role', 'option');
      li.setAttribute('aria-disabled', 'true');
      li.setAttribute('aria-selected', 'false');
      li.id = listId + '-msg';
      list.appendChild(li);
      status.textContent = text;
      setOpen(true);
    }

    function render(results) {
      if (!results.length) { message('No matches'); return; }
      list.textContent = '';
      items = results;
      results.forEach(function (item, i) {
        var li = make('li', 'list-group-item list-group-item-action ac-item d-flex align-items-center gap-2');
        li.id = listId + '-' + i;
        li.setAttribute('role', 'option');
        li.setAttribute('aria-selected', 'false');
        if (item.thumb) {
          var im = make('img', 'ac-thumb rounded');
          im.src = item.thumb;
          im.alt = '';
          im.loading = 'lazy';
          li.appendChild(im);
        }
        var text = make('div', 'ac-text');
        text.appendChild(make('div', 'fw-medium text-truncate', item.label));
        if (item.hint) { text.appendChild(make('div', 'small text-secondary text-truncate', item.hint)); }
        li.appendChild(text);
        li.addEventListener('mousedown', function (e) { e.preventDefault(); choose(item); }); // before blur
        li.addEventListener('mousemove', function () { if (active !== i) { setActive(i); } });
        list.appendChild(li);
      });
      status.textContent = results.length + (results.length === 1 ? ' result' : ' results') + ' available. Use the up and down arrow keys, then Enter.';
      setOpen(true);
      setActive(-1);
    }

    function load(query) {
      if (controller && controller.abort) { controller.abort(); }
      controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
      var options = { credentials: 'same-origin', headers: { Accept: 'application/json' } };
      if (controller) { options.signal = controller.signal; }
      var qs = 'q=' + encodeURIComponent(query) + (exclude ? '&exclude=' + encodeURIComponent(exclude) : '');
      status.textContent = 'Searching';
      fetch(url + '?' + qs, options)
        .then(function (response) {
          if (!response.ok) { throw new Error('http ' + response.status); }
          return response.json();
        })
        .then(function (data) {
          if (document.activeElement === input) { render(Array.isArray(data.items) ? data.items : []); }
        })
        .catch(function (error) {
          if (error && error.name === 'AbortError') { return; }
          if (document.activeElement === input) { message('Could not load results. Check your connection and try again.'); }
        });
    }

    function schedule(query) {
      clearTimeout(timer);
      timer = setTimeout(function () { load(query); }, DEBOUNCE_MS);
    }

    input.addEventListener('input', function () {
      if (input.value.trim() === '' && selected) { choose(null); }
      schedule(input.value);
    });
    input.addEventListener('focus', function () {
      if (selected) { input.select(); } else { schedule(input.value); }
    });
    input.addEventListener('click', function () {
      if (!isOpen) { load(selected && input.value === selected.label ? '' : input.value); }
    });
    input.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        if (!isOpen) { load(selected && input.value === selected.label ? '' : input.value); }
        else if (items.length) { setActive((active + 1) % items.length); }
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        if (isOpen && items.length) { setActive(active <= 0 ? items.length - 1 : active - 1); }
      } else if (e.key === 'Enter') {
        if (isOpen) {
          e.preventDefault(); // never submit the form while the list is open
          if (active >= 0 && items[active]) { choose(items[active]); }
        }
      } else if (e.key === 'Escape') {
        if (isOpen) { e.preventDefault(); e.stopPropagation(); setOpen(false); showSelected(); }
      } else if (e.key === 'Tab') {
        setOpen(false);
      }
    });
    input.addEventListener('blur', function () {
      clearTimeout(timer);
      if (controller && controller.abort) { controller.abort(); }
      setOpen(false);
      showSelected(); // free text that was not chosen is discarded
    });
    clear.addEventListener('click', function () {
      choose(null);
      input.focus();
    });

    showSelected();
  }

  function boot() {
    Array.prototype.forEach.call(document.querySelectorAll('[data-ac]'), init);
  }

  window.CmsAutocomplete = { init: init, boot: boot };
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
})();
