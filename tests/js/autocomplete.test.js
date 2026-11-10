/*
 * Behaviour tests for core/admin/assets/autocomplete.js in a simulated browser (jsdom).
 * Test-only tooling, never shipped to the web server:  npm install jsdom  &&  node tests/js/autocomplete.test.js
 */
'use strict';
const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');

const SCRIPT = fs.readFileSync(path.join(__dirname, '../../core/admin/assets/autocomplete.js'), 'utf8');
let passed = 0, failed = 0;
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
function check(name, ok, extra) {
  if (ok) { passed++; console.log('  ok   ' + name); } else { failed++; console.log('  FAIL ' + name + (extra !== undefined ? ' -> ' + JSON.stringify(extra) : '')); }
}

/** A page with one field, a mocked fetch, and the script loaded. */
function page(opts) {
  opts = opts || {};
  const html = '<!doctype html><body><form id="f"><label for="parent_id">Parent</label>' +
    '<div class="ac-wrap" data-ac data-ac-url="/admin/lookup/content-parent" data-ac-label="' + (opts.label || '') + '"' +
    (opts.thumb ? ' data-ac-thumb="' + opts.thumb + '"' : '') + (opts.exclude ? ' data-ac-exclude="' + opts.exclude + '"' : '') +
    ' data-ac-placeholder="Type to search"><input type="number" class="form-control" id="parent_id" name="parent_id" value="' + (opts.value || '') + '" min="1" data-ac-value></div>' +
    '<input id="other"><button id="go" type="submit">Save</button></form></body>';
  const dom = new JSDOM(html, { runScripts: 'outside-only', pretendToBeVisual: true });
  const w = dom.window;
  const calls = [];
  w.fetch = function (url, o) {
    const call = { url: url, signal: o && o.signal, aborted: false };
    if (o && o.signal) { o.signal.addEventListener('abort', function () { call.aborted = true; }); }
    calls.push(call);
    return (opts.handler || function () { return Promise.resolve({ ok: true, json: () => Promise.resolve({ items: opts.items || [] }) }); })(url, o, call);
  };
  w.eval(SCRIPT);
  w.CmsAutocomplete.boot(); // jsdom fires DOMContentLoaded asynchronously; boot is idempotent
  const d = w.document;
  const ui = {
    w, d, calls,
    input: d.getElementById('parent_id'),
    hidden: d.querySelector('input[name=parent_id]'),
    list: d.querySelector('.ac-list'),
    clear: d.querySelector('.ac-wrap .btn'),
    status: d.querySelector('[role=status]'),
    form: d.getElementById('f'),
    opts: () => Array.from(d.querySelectorAll('[role=option]')),
  };
  ui.key = (k) => { const e = new w.KeyboardEvent('keydown', { key: k, bubbles: true, cancelable: true }); ui.input.dispatchEvent(e); return e; };
  ui.type = (text) => { ui.input.focus(); ui.input.value = text; ui.input.dispatchEvent(new w.Event('input', { bubbles: true })); };
  return ui;
}
const ITEMS = [
  { id: 5, label: 'Law Book', hint: '/law-book' },
  { id: 9, label: 'Other Book', hint: '/other-book', thumb: '/user/uploads/2026/10/thumbs/a.jpg' },
  { id: 12, label: '<img src=x onerror=alert(1)>', hint: '/x' },
];

(async function main() {
  console.log('Setup');
  let p = page({ value: '5', label: 'Law Book', thumb: '/t.jpg', exclude: '3' });
  check('the numeric field becomes a hidden carrier of the id and keeps its name and value', p.hidden.type === 'hidden' && p.hidden.value === '5' && p.hidden.name === 'parent_id' && !p.hidden.id);
  check('the visible search box takes over the id, so the <label for> still points at it', p.input.type === 'text' && p.d.querySelector('label[for=parent_id]').control === p.input);
  check('the selected label and thumbnail are shown, and the clear button is visible', p.input.value === 'Law Book' && !p.d.querySelector('.input-group-text').classList.contains('d-none') && p.d.querySelector('.ac-thumb').getAttribute('src') === '/t.jpg' && !p.clear.classList.contains('d-none'));
  check('ARIA combobox wiring', p.input.getAttribute('role') === 'combobox' && p.input.getAttribute('aria-expanded') === 'false' && p.input.getAttribute('aria-controls') === p.list.id && p.list.getAttribute('role') === 'listbox' && p.status.getAttribute('aria-live') === 'polite');
  p = page({});
  check('without a selection: empty box, no preview, no clear button, placeholder set', p.input.value === '' && p.hidden.value === '' && p.clear.classList.contains('d-none') && p.input.placeholder === 'Type to search');
  p.w.CmsAutocomplete.boot();
  check('initialising twice does not duplicate the widget', p.d.querySelectorAll('.input-group').length === 1);

  console.log('Searching');
  p = page({ items: ITEMS, exclude: '3' });
  p.type('l'); p.type('la'); p.type('law');
  await sleep(80);
  check('nothing is requested until typing pauses', p.calls.length === 0);
  await sleep(260);
  check('rapid typing causes exactly one request, with the final text and the exclude id, URL-encoded', p.calls.length === 1 && p.calls[0].url === '/admin/lookup/content-parent?q=law&exclude=3', p.calls.map((c) => c.url));
  check('results are shown as options with labels and hints', p.opts().length === 3 && p.opts()[0].textContent.indexOf('Law Book') !== -1 && p.opts()[0].textContent.indexOf('/law-book') !== -1 && p.input.getAttribute('aria-expanded') === 'true' && !p.list.classList.contains('d-none'));
  check('only items with a thumbnail get an image, with empty alt text', p.d.querySelectorAll('.ac-item img').length === 1 && p.d.querySelector('.ac-item img').alt === '');
  check('hostile text in a label is shown as text, never parsed as HTML', p.d.querySelectorAll('.ac-item img[src="x"]').length === 0 && p.opts()[2].textContent.indexOf('<img src=x onerror=alert(1)>') !== -1);
  check('the live status announces the number of results', /3 results/.test(p.status.textContent));
  p = page({ items: ITEMS });
  p.type('a'); await sleep(260);
  check('no exclude parameter when none is configured', p.calls[0].url === '/admin/lookup/content-parent?q=a');
  p.type('ab'); await sleep(260); p.type('abc'); await sleep(260);
  check('a newer search aborts the older request', p.calls.length === 3 && p.calls[0].aborted && p.calls[1].aborted && !p.calls[2].aborted);

  console.log('Keyboard');
  p = page({ items: ITEMS, value: '', exclude: '' });
  p.type('b'); await sleep(260);
  p.key('ArrowDown');
  check('ArrowDown highlights the first option and points aria-activedescendant at it', p.opts()[0].getAttribute('aria-selected') === 'true' && p.input.getAttribute('aria-activedescendant') === p.opts()[0].id);
  p.key('ArrowDown'); p.key('ArrowDown');
  check('ArrowDown moves on and wraps around', p.opts()[2].getAttribute('aria-selected') === 'true' && (p.key('ArrowDown'), p.opts()[0].getAttribute('aria-selected') === 'true' && p.opts()[2].getAttribute('aria-selected') === 'false'));
  p.key('ArrowUp');
  check('ArrowUp wraps backwards', p.opts()[2].getAttribute('aria-selected') === 'true');
  let seen = [];
  p.form.addEventListener('input', (e) => seen.push(e.target.name || e.target.id));
  p.key('ArrowUp');
  const enter = p.key('Enter');
  check('Enter chooses the highlighted option and does not submit the form', enter.defaultPrevented && p.hidden.value === '9' && p.input.value === 'Other Book');
  check('choosing closes the list, shows the thumbnail and the clear button', p.list.classList.contains('d-none') && p.input.getAttribute('aria-expanded') === 'false' && !p.input.hasAttribute('aria-activedescendant') && !p.clear.classList.contains('d-none') && p.d.querySelector('.ac-thumb').getAttribute('src') === '/user/uploads/2026/10/thumbs/a.jpg');
  check('the hidden field fires an input event that reaches the form (unsaved-changes warning)', seen.indexOf('parent_id') !== -1, seen);
  const enter2 = p.key('Enter');
  check('Enter with the list closed is left alone (normal form submit)', !enter2.defaultPrevented);

  p = page({ items: ITEMS });
  p.type('b'); await sleep(260);
  const enter3 = p.key('Enter');
  check('Enter with the list open but nothing highlighted does not submit and does not choose', enter3.defaultPrevented && p.hidden.value === '');
  p.key('ArrowDown');
  const esc = p.key('Escape');
  check('Escape closes the list and restores the chosen text', esc.defaultPrevented && p.list.classList.contains('d-none') && p.input.value === '' && p.hidden.value === '');
  p = page({ items: ITEMS, value: '5', label: 'Law Book' });
  p.input.focus(); p.input.value = 'something typed'; p.key('ArrowDown'); await sleep(260);
  p.key('Escape');
  check('Escape after typing brings back the selected label', p.input.value === 'Law Book' && p.hidden.value === '5');
  p = page({ items: ITEMS });
  p.type('b'); await sleep(260);
  p.key('Tab');
  check('Tab closes the list', p.list.classList.contains('d-none'));
  p = page({ items: ITEMS });
  p.input.focus();
  p.key('ArrowDown'); await sleep(30);
  check('ArrowDown on a closed list loads suggestions immediately', p.calls.length === 1 && p.calls[0].url === '/admin/lookup/content-parent?q=');

  console.log('Mouse and focus');
  p = page({ items: ITEMS });
  p.type('b'); await sleep(260);
  const md = new p.w.MouseEvent('mousedown', { bubbles: true, cancelable: true });
  p.opts()[1].dispatchEvent(md);
  check('mousedown on an option chooses it (and prevents the input losing focus first)', md.defaultPrevented && p.hidden.value === '9' && p.input.value === 'Other Book');
  p = page({ items: ITEMS });
  p.type('b'); await sleep(260);
  p.opts()[2].dispatchEvent(new p.w.MouseEvent('mousemove', { bubbles: true }));
  check('hovering highlights an option', p.opts()[2].getAttribute('aria-selected') === 'true');
  p.input.dispatchEvent(new p.w.Event('blur'));
  check('blur closes the list and discards text that was not chosen', p.list.classList.contains('d-none') && p.input.value === '' && p.hidden.value === '');
  p = page({ items: ITEMS, value: '5', label: 'Law Book' });
  p.input.focus(); p.input.value = 'half typed'; p.input.dispatchEvent(new p.w.Event('blur'));
  check('blur restores the selected label when the typed text was not chosen', p.input.value === 'Law Book' && p.hidden.value === '5');

  console.log('Clearing');
  p = page({ items: ITEMS, value: '5', label: 'Law Book', thumb: '/t.jpg' });
  seen = [];
  p.form.addEventListener('change', (e) => seen.push('change'));
  p.clear.click();
  check('the clear button empties the value, the box and the preview, focuses the box, and signals a change', p.hidden.value === '' && p.input.value === '' && p.clear.classList.contains('d-none') && p.d.querySelector('.input-group-text').classList.contains('d-none') && p.d.activeElement === p.input && seen.length === 1);
  p = page({ items: ITEMS, value: '5', label: 'Law Book' });
  p.input.focus(); p.input.value = ''; p.input.dispatchEvent(new p.w.Event('input', { bubbles: true }));
  check('emptying the text box clears the selection', p.hidden.value === '' && p.clear.classList.contains('d-none'));
  await sleep(260);

  console.log('Problems');
  p = page({ items: [] });
  p.type('zzz'); await sleep(260);
  check('no results: a "No matches" row that cannot be chosen', p.opts().length === 1 && p.opts()[0].textContent === 'No matches' && p.opts()[0].getAttribute('aria-disabled') === 'true');
  p.key('ArrowDown'); p.key('Enter');
  check('the "No matches" row is never selected', p.hidden.value === '');
  p = page({ handler: () => Promise.reject(new Error('offline')) });
  p.type('abc'); await sleep(260);
  check('a network failure shows a message instead of breaking', p.opts().length === 1 && /Could not load results/.test(p.opts()[0].textContent) && p.hidden.value === '');
  p = page({ handler: () => Promise.resolve({ ok: false, status: 500, json: () => Promise.resolve({}) }) });
  p.type('abc'); await sleep(260);
  check('an HTTP error shows the same message', /Could not load results/.test(p.opts()[0].textContent));
  p = page({ handler: () => Promise.resolve({ ok: true, json: () => Promise.resolve({ nonsense: true }) }) });
  p.type('abc'); await sleep(260);
  check('a malformed response is treated as no results', p.opts()[0].textContent === 'No matches');
  let release;
  p = page({ handler: () => new Promise((resolve) => { release = () => resolve({ ok: true, json: () => Promise.resolve({ items: ITEMS }) }); }) });
  p.type('abc'); await sleep(260);
  p.d.getElementById('other').focus(); // a real focus change, as when the user clicks elsewhere
  release(); await sleep(30);
  check('a response that arrives after the user left the field does not reopen the list', p.list.classList.contains('d-none'));

  console.log('\n' + passed + ' passed, ' + failed + ' failed');
  process.exit(failed === 0 ? 0 : 1);
})();
