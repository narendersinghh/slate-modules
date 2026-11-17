/* Slate Forms: the visual form builder (Modules › Forms › edit). Vanilla JS, uses the dashboard helpers in window.Slate. */
(function () {
  'use strict';
  const root = document.querySelector('[data-sfb]');
  if (!root) return;
  const SL = window.Slate || {};
  const esc = SL.esc || ((s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])));
  const toast = SL.toast || ((m) => alert(m));
  const cfg = JSON.parse(root.querySelector('[data-sfb-config]').textContent);

  // ---------- icons (same 24px stroke set as the dashboard) ----------
  const P = {
    grip: '<circle cx="9" cy="6" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="9" cy="18" r="1"/><circle cx="15" cy="6" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="15" cy="18" r="1"/>',
    trash: '<path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
    copy: '<rect x="8" y="8" width="14" height="14" rx="2"/><path d="M4 16a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2"/>',
    plus: '<path d="M12 5v14M5 12h14"/>',
    x: '<path d="M18 6 6 18M6 6l12 12"/>',
    eye: '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12"/><circle cx="12" cy="12" r="3"/>',
    'eye-off': '<path d="M10.7 5.1A10 10 0 0 1 12 5c6.4 0 10 7 10 7a17 17 0 0 1-2.2 3.2M6.6 6.6C3.9 8.4 2 12 2 12s3.6 7 10 7a9.7 9.7 0 0 0 5.4-1.6M9.9 9.9a3 3 0 0 0 4.2 4.2M2 2l20 20"/>',
    external: '<path d="M15 3h6v6M10 14 21 3M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
    back: '<path d="m15 18-6-6 6-6"/>',
    up: '<path d="m18 15-6-6-6 6"/>',
    down: '<path d="m6 9 6 6 6-6"/>',
    mail: '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m2 7 10 6 10-6"/>',
    type: '<path d="M4 7V4h16v3M9 20h6M12 4v16"/>',
    link: '<path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/>',
    calendar: '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
    clock: '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
    upload: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/>',
    shield: '<path d="M20 13c0 5-3.5 7.5-7.7 9a1 1 0 0 1-.7 0C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.2-2.7a1.2 1.2 0 0 1 1.5 0C14.5 3.8 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/>',
    para: '<path d="M4 6h16M4 12h16M4 18h10"/>',
    textarea: '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 9h10M7 13h10M7 17h5"/>',
    phone: '<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2z"/>',
    hash: '<path d="M4 9h16M4 15h16M10 3 8 21M16 3l-2 18"/>',
    select: '<rect x="3" y="6" width="18" height="12" rx="2"/><path d="m14 11 2 2 2-2"/>',
    radio: '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="3.5"/>',
    checkbox: '<rect x="3" y="3" width="18" height="18" rx="3"/><path d="m8 12 3 3 5-6"/>',
    heading: '<path d="M6 4v16M18 4v16M6 12h12"/>',
    step: '<path d="M3 12h4M10 12h4M17 12h4"/><path d="M7 5h10M7 19h10"/>',
    settings: '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
    code: '<path d="m16 18 6-6-6-6M8 6l-6 6 6 6"/>',
    branch: '<circle cx="6" cy="6" r="2"/><circle cx="6" cy="18" r="2"/><circle cx="18" cy="8" r="2"/><path d="M6 8v8M18 10c0 4-6 3-10 6"/>',
    check: '<path d="M20 6 9 17l-5-5"/>',
    info: '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>',
    inbox: '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.5 5.1 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.5-6.9A2 2 0 0 0 16.8 4H7.2a2 2 0 0 0-1.7 1.1z"/>',
  };
  const ic = (n, s = 16) => '<svg class="ic" width="' + s + '" height="' + s + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + (P[n] || '') + '</svg>';

  const TYPE_ICON = { text: 'type', email: 'mail', tel: 'phone', number: 'hash', textarea: 'textarea', url: 'link', select: 'select', radio: 'radio', checkbox: 'checkbox',
    consent: 'shield', date: 'calendar', time: 'clock', file: 'upload', hidden: 'eye-off', heading: 'heading', html: 'para', step: 'step' };
  const GROUPS = [['Basic', ['text', 'email', 'tel', 'textarea', 'number', 'url']], ['Choices', ['select', 'radio', 'checkbox', 'consent']],
    ['More', ['date', 'time', 'file', 'hidden']], ['Layout', ['heading', 'html', 'step']]];
  const DISPLAY = ['heading', 'html', 'step'];
  const CHOICE = ['select', 'radio', 'checkbox'];
  const NEW_LABEL = { text: 'Text', email: 'Email', tel: 'Phone', number: 'Number', textarea: 'Message', url: 'Website', select: 'Choose one', radio: 'Pick one',
    checkbox: 'Select all that apply', consent: 'Consent', date: 'Date', time: 'Time', file: 'Attachment', hidden: 'Hidden value', heading: 'Section title', html: 'Paragraph', step: 'Next page' };
  const OPS = { is: 'is', not: 'is not', contains: 'contains', filled: 'is filled in', empty: 'is empty' };

  // ---------- state ----------
  const state = { title: cfg.title, status: cfg.status, fields: cfg.fields.map((f) => Object.assign({}, f)), settings: Object.assign({}, cfg.settings), tab: 'fields', sel: null };
  let dirty = false;
  const uid = () => 'f' + Math.random().toString(36).slice(2, 10);
  const slug = (s) => (s || '').toLowerCase().normalize('NFKD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 40) || 'field';
  const keyFor = (label, except) => {
    const taken = state.fields.filter((f) => f !== except).map((f) => f.name);
    let k = slug(label), base = k, n = 2;
    while (taken.includes(k)) k = base + '_' + n++;
    return k;
  };
  const find = (id) => state.fields.find((f) => f.id === id);
  const valueFields = (except) => state.fields.filter((f) => !DISPLAY.includes(f.type) && f !== except);
  const parseChoices = (text) => String(text || '').split(/\r?\n/).map((l) => l.trim()).filter(Boolean).map((l) => {
    const i = l.indexOf(':');
    if (i === -1) return { v: l, l: l };
    const v = l.slice(0, i).trim(), lab = l.slice(i + 1).trim();
    return { v: v || lab, l: lab || v };
  });
  const joinChoices = (list) => list.filter((c) => (c.l || c.v).trim() !== '').map((c) => {
    const l = c.l.trim(), v = (c.v || '').trim() || l;
    return v === l && !l.includes(':') ? l : v + ' : ' + l;
  }).join('\n');
  const mark = () => { dirty = true; const s = root.querySelector('[data-save-state]'); if (s) s.textContent = 'Unsaved changes'; };

  // ---------- shell ----------
  root.innerHTML =
    '<div class="sfb-top">' +
      '<div class="sfb-name"><input class="sfb-title" data-title aria-label="Form name" maxlength="190" placeholder="Form name"><span class="sfb-meta">' +
        '<span class="pill" data-status-pill></span><a href="' + esc(cfg.urls.entries) + '" class="sfb-entries">' + ic('inbox', 14) + ' ' + cfg.entries + ' entr' + (cfg.entries === 1 ? 'y' : 'ies') + (cfg.unread ? ', ' + cfg.unread + ' new' : '') + '</a></span></div>' +
      '<div class="sfb-actions"><span class="save-state muted small" data-save-state></span>' +
        '<button type="button" class="btn btn-sm" data-embed>' + ic('code', 15) + ' Embed</button>' +
        '<a class="btn btn-sm" href="' + esc(cfg.urls.preview) + '" target="_blank" rel="noopener" data-preview>' + ic('eye', 15) + ' Preview</a>' +
        '<label class="sfb-pub" title="Only published forms show on the site and accept entries"><input type="checkbox" class="switch switch-sm" data-publish> <span>Published</span></label>' +
        '<button type="button" class="btn btn-primary btn-sm" data-save>Save</button></div>' +
    '</div>' +
    '<nav class="tabs-pill sfb-tabs" role="tablist">' +
      '<button type="button" role="tab" data-tab="fields">' + ic('para', 15) + ' Fields</button>' +
      '<button type="button" role="tab" data-tab="settings">' + ic('settings', 15) + ' After sending</button>' +
      '<button type="button" role="tab" data-tab="emails">' + ic('mail', 15) + ' Emails</button>' +
    '</nav>' +
    '<section class="sfb-pane sfb-build" data-pane="fields">' +
      '<aside class="sfb-palette" aria-label="Add a field"></aside>' +
      '<div class="sfb-canvas-wrap"><div class="sfb-canvas" data-canvas aria-label="Form fields"></div></div>' +
      '<aside class="sfb-inspector card" data-inspector aria-live="polite"></aside>' +
    '</section>' +
    '<section class="sfb-pane sfb-settings" data-pane="settings" hidden></section>' +
    '<section class="sfb-pane sfb-settings" data-pane="emails" hidden></section>';

  const $ = (s, r = root) => r.querySelector(s);
  const $$ = (s, r = root) => Array.from(r.querySelectorAll(s));
  const titleIn = $('[data-title]');
  titleIn.value = state.title;
  titleIn.addEventListener('input', () => { state.title = titleIn.value; mark(); });
  const pub = $('[data-publish]');
  const syncStatus = () => {
    pub.checked = state.status === 'publish';
    const p = $('[data-status-pill]');
    p.className = 'pill ' + (state.status === 'publish' ? 'pill-success' : 'pill-warn');
    p.textContent = state.status === 'publish' ? 'Published' : 'Draft';
  };
  pub.addEventListener('change', () => { state.status = pub.checked ? 'publish' : 'draft'; syncStatus(); mark(); });
  syncStatus();

  // ---------- palette ----------
  $('.sfb-palette').innerHTML = '<p class="sfb-hint">Click or drag a field into the form.</p>' + GROUPS.map(([g, types]) => '<div class="sfb-group"><h3>' + g + '</h3><div class="sfb-tiles">' +
    types.map((t) => '<button type="button" class="sfb-tile" draggable="true" data-add="' + t + '">' + ic(TYPE_ICON[t], 16) + '<span>' + esc(cfg.types[t]) + '</span></button>').join('') + '</div></div>').join('');

  function newField(type) {
    const f = { id: uid(), type, label: NEW_LABEL[type] || 'Field', name: '', placeholder: '', required: false, width: '100', options: '', help: '', default: '', min: '', max: '',
      pattern: '', pattern_msg: '', accept: '', max_size: '', cond: null, _new: true };
    if (CHOICE.includes(type)) f.options = 'Option 1\nOption 2\nOption 3';
    if (type === 'consent') { f.options = 'I agree to the privacy policy.'; f.required = true; }
    if (type === 'html') f.options = 'Add a short explanation here.';
    if (type === 'email') f.placeholder = 'you@example.com';
    if (type === 'file') f.accept = '';
    f.name = keyFor(f.label, f);
    return f;
  }
  function addField(type, index) {
    const f = newField(type);
    if (index == null) {
      const si = state.fields.findIndex((x) => x.id === state.sel);
      index = si === -1 ? state.fields.length : si + 1;
    }
    state.fields.splice(index, 0, f);
    state.sel = f.id;
    mark();
    renderCanvas();
    renderInspector();
    const el = $('[data-id="' + f.id + '"]');
    if (el) { el.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); }
    const lab = $('[data-k="label"]');
    if (lab && window.matchMedia('(min-width: 1101px)').matches) { lab.focus(); lab.select(); }
  }
  $('.sfb-palette').addEventListener('click', (e) => { const b = e.target.closest('[data-add]'); if (b) addField(b.dataset.add); });

  // ---------- canvas (live preview of the real form) ----------
  function controlPreview(f) {
    const ph = esc(f.placeholder || '');
    const choices = parseChoices(f.options);
    const def = String(f.default || '');
    switch (f.type) {
      case 'heading': return '<h3 class="sfp-heading">' + esc(f.label || 'Heading') + '</h3>' + (f.help ? '<p class="sfp-help">' + esc(f.help) + '</p>' : '');
      case 'html': return '<div class="sfp-text">' + esc((f.options || f.label).replace(/<[^>]+>/g, '')).replace(/\n/g, '<br>') + '</div>';
      case 'step': return '<div class="sfp-step"><span>' + ic('step', 14) + ' Page break</span><strong>' + esc(f.label || 'Next page') + '</strong></div>';
      case 'textarea': return '<div class="sfp-input sfp-area">' + (def ? esc(def) : '<span>' + ph + '</span>') + '</div>';
      case 'select': return '<div class="sfp-input sfp-select"><span>' + esc(def ? (choices.find((c) => c.v === def) || { l: def }).l : (f.placeholder || 'Choose…')) + '</span>' + ic('down', 14) + '</div>';
      case 'radio': case 'checkbox': {
        const picked = def.split(',').map((x) => x.trim());
        return '<div class="sfp-choices">' + (choices.length ? choices.map((c) => '<span class="sfp-choice"><i class="sfp-' + f.type + (picked.includes(c.v) ? ' on' : '') + '"></i>' + esc(c.l) + '</span>').join('') : '<span class="sfp-empty">Add choices in the panel</span>') + '</div>';
      }
      case 'consent': return '<span class="sfp-choice"><i class="sfp-checkbox"></i>' + esc((f.options || f.label).replace(/<[^>]+>/g, '')) + (f.required ? ' <b class="sfp-req">*</b>' : '') + '</span>';
      case 'file': return '<div class="sfp-input sfp-file"><span class="sfp-filebtn">Choose file</span><span>' + esc((f.accept || cfg.uploadTypes).split(',').slice(0, 4).join(',').toUpperCase()) + '</span></div>';
      case 'hidden': return '<div class="sfp-hidden">' + ic('eye-off', 14) + ' Hidden: <code>' + esc(f.default || f.placeholder || '(empty)') + '</code></div>';
      default: return '<div class="sfp-input">' + (def ? esc(def) : '<span>' + (ph || '&nbsp;') + '</span>') + (f.type === 'date' ? ic('calendar', 14) : f.type === 'time' ? ic('clock', 14) : '') + '</div>';
    }
  }
  function condText(f) {
    if (!f.cond || !f.cond.field) return '';
    const t = find(state.fields.find((x) => x.name === f.cond.field)?.id);
    const name = t ? (t.label || t.name) : f.cond.field;
    return (f.cond.action === 'hide' ? 'Hidden when ' : 'Shown when ') + '"' + name + '" ' + OPS[f.cond.op] + (['filled', 'empty'].includes(f.cond.op) ? '' : ' "' + f.cond.value + '"');
  }
  function renderCanvas() {
    const c = $('[data-canvas]');
    if (!state.fields.length) {
      c.innerHTML = '<div class="sfb-empty" data-drop-end>' + ic('plus', 22) + '<strong>Add your first field</strong><span>Pick a field type on the left, or drag it here.</span></div>' + submitPreview();
      return;
    }
    let page = 1;
    c.innerHTML = '<div class="sfb-list">' + state.fields.map((f, i) => {
      if (f.type === 'step') page++;
      const label = DISPLAY.includes(f.type) || f.type === 'consent' ? '' : '<div class="sfp-label">' + esc(f.label || '(no label)') + (f.required ? ' <b class="sfp-req">*</b>' : '') + '</div>';
      const cond = condText(f);
      return '<div class="sfb-item' + (state.sel === f.id ? ' is-sel' : '') + ' sfb-w' + (f.type === 'step' || f.type === 'hidden' ? '100' : f.width) + ' sfb-t-' + f.type + '" data-id="' + f.id + '" tabindex="0" role="button" aria-label="' + esc((cfg.types[f.type] || f.type) + ': ' + (f.label || '')) + '">' +
        '<div class="sfb-item-bar"><span class="sfb-grip" draggable="true" title="Drag to move" aria-hidden="true">' + ic('grip', 14) + '</span><span class="sfb-type">' + ic(TYPE_ICON[f.type], 13) + ' ' + esc(cfg.types[f.type]) + (f.type === 'step' ? ' ' + page : '') + '</span>' +
        '<span class="sfb-tools"><button type="button" data-act="dup" title="Duplicate" aria-label="Duplicate">' + ic('copy', 14) + '</button><button type="button" data-act="del" title="Delete" aria-label="Delete">' + ic('trash', 14) + '</button></span></div>' +
        label + controlPreview(f) + (f.help && f.type !== 'heading' ? '<div class="sfp-help">' + esc(f.help) + '</div>' : '') +
        (cond ? '<div class="sfb-cond">' + ic('branch', 13) + ' ' + esc(cond) + '</div>' : '') + '</div>';
    }).join('') + '</div>' + submitPreview();
  }
  function submitPreview() {
    return '<div class="sfb-submit-row"><span class="sfp-button">' + esc(state.settings.submit_label || cfg.defaults.submit_label) + '</span><button type="button" class="link small" data-goto="settings">Change button and message</button></div>';
  }

  const canvas = $('[data-canvas]');
  canvas.addEventListener('click', (e) => {
    const go = e.target.closest('[data-goto]');
    if (go) { setTab(go.dataset.goto); return; }
    const item = e.target.closest('.sfb-item');
    if (!item) return;
    const act = e.target.closest('[data-act]');
    const i = state.fields.findIndex((f) => f.id === item.dataset.id);
    if (act?.dataset.act === 'del') {
      const f = state.fields[i];
      const used = state.fields.filter((x) => x.cond && x.cond.field === f.name);
      if (!f._new && !confirm('Delete "' + (f.label || cfg.types[f.type]) + '"? Entries already received keep their answers.')) return;
      state.fields.splice(i, 1);
      used.forEach((x) => { x.cond = null; });
      state.sel = state.fields[Math.min(i, state.fields.length - 1)]?.id || null;
      mark(); renderCanvas(); renderInspector();
      return;
    }
    if (act?.dataset.act === 'dup') {
      const copy = Object.assign({}, state.fields[i], { id: uid(), _new: true, cond: state.fields[i].cond ? Object.assign({}, state.fields[i].cond) : null });
      copy.label = copy.label + ' (copy)';
      copy.name = keyFor(copy.label, copy);
      state.fields.splice(i + 1, 0, copy);
      state.sel = copy.id;
      mark(); renderCanvas(); renderInspector();
      return;
    }
    state.sel = item.dataset.id;
    $$('.sfb-item', canvas).forEach((x) => x.classList.toggle('is-sel', x === item));
    renderInspector();
    if (window.matchMedia('(max-width: 760px)').matches) $('[data-inspector]').scrollIntoView({ behavior: 'smooth', block: 'start' });
  });
  canvas.addEventListener('keydown', (e) => {
    const item = e.target.closest('.sfb-item');
    if (!item || e.target !== item) return;
    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); item.click(); }
    if (e.altKey && (e.key === 'ArrowUp' || e.key === 'ArrowDown')) { e.preventDefault(); move(item.dataset.id, e.key === 'ArrowUp' ? -1 : 1, true); }
  });
  function move(id, dir, focus) {
    const i = state.fields.findIndex((f) => f.id === id), j = i + dir;
    if (i < 0 || j < 0 || j >= state.fields.length) return;
    [state.fields[i], state.fields[j]] = [state.fields[j], state.fields[i]];
    mark(); renderCanvas(); renderInspector();
    if (focus) $('[data-id="' + id + '"]')?.focus();
  }

  // ---------- drag and drop (palette tiles and the grip of each field) ----------
  let drag = null;
  const marker = document.createElement('div');
  marker.className = 'sfb-marker';
  root.addEventListener('dragstart', (e) => {
    const tile = e.target.closest('[data-add]');
    const grip = e.target.closest('.sfb-grip');
    if (tile) drag = { add: tile.dataset.add };
    else if (grip) { const it = grip.closest('.sfb-item'); drag = { id: it.dataset.id }; it.classList.add('is-dragging'); e.dataTransfer.setDragImage(it, 20, 20); }
    else return;
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', drag.add || drag.id);
  });
  function dropIndex(e) {
    const items = $$('.sfb-item', canvas).filter((x) => !x.classList.contains('is-dragging'));
    for (const it of items) {
      const r = it.getBoundingClientRect();
      const narrow = r.width < canvas.getBoundingClientRect().width * 0.8;
      if (e.clientY < r.top) return { before: it };
      if (e.clientY <= r.bottom) {
        const after = narrow ? e.clientX > r.left + r.width / 2 : e.clientY > r.top + r.height / 2;
        return after ? { after: it } : { before: it };
      }
    }
    return { end: true };
  }
  canvas.addEventListener('dragover', (e) => {
    if (!drag) return;
    e.preventDefault();
    const d = dropIndex(e);
    const list = $('.sfb-list', canvas) || canvas;
    if (d.before) d.before.before(marker); else if (d.after) d.after.after(marker); else list.appendChild(marker);
  });
  canvas.addEventListener('dragleave', (e) => { if (!canvas.contains(e.relatedTarget)) marker.remove(); });
  canvas.addEventListener('drop', (e) => {
    if (!drag) return;
    e.preventDefault();
    const d = dropIndex(e);
    marker.remove();
    let idx = state.fields.length;
    const ref = d.before || d.after;
    if (ref) idx = state.fields.findIndex((f) => f.id === ref.dataset.id) + (d.after ? 1 : 0);
    if (drag.add) addField(drag.add, idx);
    else {
      const from = state.fields.findIndex((f) => f.id === drag.id);
      const [f] = state.fields.splice(from, 1);
      if (from < idx) idx--;
      state.fields.splice(idx, 0, f);
      state.sel = f.id;
      mark(); renderCanvas(); renderInspector();
    }
    drag = null;
  });
  root.addEventListener('dragend', () => { marker.remove(); drag = null; $$('.is-dragging').forEach((x) => x.classList.remove('is-dragging')); });

  // ---------- inspector ----------
  const fieldRow = (label, html, help) => '<label class="field sfb-field"><span>' + label + '</span>' + html + (help ? '<small class="muted">' + help + '</small>' : '') + '</label>';
  const input = (k, v, attrs = '') => '<input class="input" data-k="' + k + '" value="' + esc(v ?? '') + '" ' + attrs + '>';
  function renderInspector() {
    const box = $('[data-inspector]');
    const f = find(state.sel);
    if (!f) {
      box.innerHTML = '<div class="sfb-insp-empty">' + ic('info', 20) + '<p><strong>Select a field</strong> to change its label, options, validation and when it shows.</p><p class="muted small">Tip: drag fields by their handle to reorder them, or use Alt + arrow keys.</p></div>';
      return;
    }
    const t = f.type;
    let h = '<div class="sfb-insp-head"><span class="sfb-insp-type">' + ic(TYPE_ICON[t], 16) + ' ' + esc(cfg.types[t]) + '</span>' +
      '<span class="sfb-insp-move"><button type="button" class="icon-btn" data-move="-1" title="Move up" aria-label="Move up">' + ic('up', 16) + '</button><button type="button" class="icon-btn" data-move="1" title="Move down" aria-label="Move down">' + ic('down', 16) + '</button>' +
      '<button type="button" class="icon-btn" data-close-insp title="Close" aria-label="Close">' + ic('x', 16) + '</button></span></div><div class="sfb-insp-body">';
    if (t === 'heading') {
      h += fieldRow('Heading', input('label', f.label)) + fieldRow('Text under the heading', input('help', f.help, 'placeholder="Optional"'));
    } else if (t === 'html') {
      h += fieldRow('Text', '<textarea class="input" rows="6" data-k="options">' + esc(f.options) + '</textarea>', 'Simple HTML is allowed, for example links and bold text.');
    } else if (t === 'step') {
      h += fieldRow('Title of the next page', input('label', f.label, 'placeholder="Optional"'), 'Visitors fill in the form one page at a time, with Next and Back buttons.');
    } else {
      h += fieldRow(t === 'consent' ? 'Name in entries' : 'Label', input('label', f.label));
      if (t === 'consent') h += fieldRow('Checkbox text', '<textarea class="input" rows="3" data-k="options">' + esc(f.options) + '</textarea>', 'Links are allowed: &lt;a href="/privacy/"&gt;privacy policy&lt;/a&gt;');
      if (!['radio', 'checkbox', 'consent', 'hidden', 'file', 'date', 'time'].includes(t)) h += fieldRow(t === 'select' ? 'First option (empty)' : 'Placeholder', input('placeholder', f.placeholder, 'placeholder="' + (t === 'select' ? 'Choose…' : 'Optional') + '"'));
      if (t !== 'hidden') h += fieldRow('Help text', input('help', f.help, 'placeholder="Optional, shown under the field"'));
      if (CHOICE.includes(t)) h += choicesEditor(f);
      if (t !== 'hidden') {
        h += '<label class="switch-row sfb-req"><input type="checkbox" class="switch switch-sm" data-k="required"' + (f.required ? ' checked' : '') + '><span>Required</span></label>';
        h += '<div class="field sfb-field"><span>Width</span><div class="seg sfb-seg">' + [['100', 'Full'], ['50', 'Half'], ['33', 'Third'], ['66', 'Two thirds']].map(([v, l]) =>
          '<label><input type="radio" name="sfb-w" data-k="width" value="' + v + '"' + (f.width === v ? ' checked' : '') + '>' + l + '</label>').join('') + '</div><small class="muted">Fields side by side form columns. On phones they stack.</small></div>';
      }
      h += defaultEditor(f);
      h += validationEditor(f);
    }
    h += condEditor(f);
    if (!DISPLAY.includes(t)) {
      h += '<details class="sfb-adv"' + (f._showKey ? ' open' : '') + '><summary>Field key</summary>' + fieldRow('Key', input('name', f.name, 'pattern="[a-z0-9_\\-]+" spellcheck="false"'),
        'Used in email tags ({' + esc(f.name) + '}), the webhook and CSV export.' + (f._new ? '' : ' Changing it splits old and new entries.')) + '</details>';
    }
    box.innerHTML = h + '</div>';
  }
  function choicesEditor(f) {
    const list = parseChoices(f.options);
    const custom = f._customValues || list.some((c) => c.v !== c.l);
    f._customValues = custom;
    return '<div class="field sfb-field sfb-choices' + (custom ? ' has-values' : '') + '"><span>Choices</span><div class="sfb-choice-list">' + list.map((c, i) =>
      '<div class="sfb-choice" data-ci="' + i + '"><input class="input" data-c="l" value="' + esc(c.l) + '" aria-label="Choice ' + (i + 1) + '">' +
      (custom ? '<input class="input sfb-cv" data-c="v" value="' + esc(c.v) + '" aria-label="Stored value" placeholder="Value">' : '') +
      '<button type="button" class="icon-btn" data-c-del="' + i + '" title="Remove" aria-label="Remove choice">' + ic('x', 14) + '</button></div>').join('') + '</div>' +
      '<div class="sfb-choice-tools"><button type="button" class="btn btn-sm" data-c-add>' + ic('plus', 14) + ' Add choice</button>' +
      '<button type="button" class="link small" data-c-bulk>Paste a list</button>' +
      '<label class="small sfb-cv-toggle"><input type="checkbox" data-c-custom' + (custom ? ' checked' : '') + '> Different stored values</label></div></div>';
  }
  function defaultEditor(f) {
    const t = f.type;
    if (['file', 'consent'].includes(t)) return '';
    if (t === 'hidden') return fieldRow('Value', input('default', f.default || f.placeholder), 'Sent with every entry, for example a campaign name.');
    if (t === 'select' || t === 'radio') {
      return fieldRow('Selected by default', '<select class="input" data-k="default"><option value="">None</option>' + parseChoices(f.options).map((c) => '<option value="' + esc(c.v) + '"' + (c.v === f.default ? ' selected' : '') + '>' + esc(c.l) + '</option>').join('') + '</select>');
    }
    if (t === 'checkbox') return '';
    const type = t === 'date' ? 'text" placeholder="YYYY-MM-DD or today' : t === 'number' ? 'number' : t === 'time' ? 'time' : 'text';
    return '<details class="sfb-adv"' + (f.default ? ' open' : '') + '><summary>Default value</summary>' + fieldRow('Pre-filled value', '<input class="input" data-k="default" type="' + type + '" value="' + esc(f.default) + '">') + '</details>';
  }
  function validationEditor(f) {
    const t = f.type;
    let h = '';
    const pair = (a, b, type, hint) => '<div class="sfb-pair">' + fieldRow(a, '<input class="input" data-k="min" type="' + type + '" value="' + esc(f.min) + '" placeholder="None">') +
      fieldRow(b, '<input class="input" data-k="max" type="' + type + '" value="' + esc(f.max) + '" placeholder="None">') + '</div>' + (hint ? '<small class="muted">' + hint + '</small>' : '');
    if (['text', 'textarea', 'tel', 'url', 'email'].includes(t)) h += pair('Min. characters', 'Max. characters', 'number');
    if (t === 'number') h += pair('Minimum', 'Maximum', 'number');
    if (t === 'date') h += pair('Earliest date', 'Latest date', 'date');
    if (t === 'checkbox') h += pair('Min. choices', 'Max. choices', 'number');
    if (t === 'text' || t === 'tel') {
      const presets = { '': 'Anything', '[0-9]+': 'Digits only', "[A-Za-z\\s'\\-]+": 'Letters only', '[A-Za-z0-9]+': 'Letters and digits', custom: 'Custom pattern' };
      const known = Object.prototype.hasOwnProperty.call(presets, f.pattern);
      h += fieldRow('Allowed characters', '<select class="input" data-pattern-preset>' + Object.entries(presets).map(([v, l]) => '<option value="' + esc(v) + '"' + ((known ? f.pattern === v : v === 'custom') ? ' selected' : '') + '>' + l + '</option>').join('') + '</select>');
      if (f.pattern && !known) h += fieldRow('Pattern (regular expression)', input('pattern', f.pattern, 'spellcheck="false"'));
      if (f.pattern) h += fieldRow('Message when it does not match', input('pattern_msg', f.pattern_msg, 'placeholder="Use the requested format."'));
    }
    if (t === 'file') {
      h += fieldRow('Allowed file types', input('accept', f.accept, 'placeholder="' + esc(cfg.uploadTypes) + '"'), 'Comma-separated, for example: pdf, docx, jpg. Empty = the site default.');
      h += fieldRow('Largest file (MB)', '<input class="input" data-k="max_size" type="number" min="0.1" max="100" step="0.1" value="' + esc(f.max_size) + '" placeholder="' + cfg.uploadMax + '">', 'Files are stored privately and only your team can download them.');
    }
    return h ? '<details class="sfb-adv"' + (f.min || f.max || f.pattern || t === 'file' ? ' open' : '') + '><summary>Validation</summary>' + h + '</details>' : '';
  }
  function condEditor(f) {
    const others = valueFields(f).filter((x) => x.type !== 'hidden');
    if (!others.length) return '';
    const c = f.cond || { field: '', op: 'is', value: '', action: 'show' };
    const on = !!f.cond;
    const target = others.find((x) => x.name === c.field) || others[0];
    let valueCtl = '';
    if (!['filled', 'empty'].includes(c.op)) {
      const ch = CHOICE.includes(target.type) ? parseChoices(target.options) : target.type === 'consent' ? [{ v: 'yes', l: 'Ticked' }] : null;
      valueCtl = ch ? '<select class="input" data-cond="value">' + ch.map((o) => '<option value="' + esc(o.v) + '"' + (o.v === c.value ? ' selected' : '') + '>' + esc(o.l) + '</option>').join('') + '</select>'
        : '<input class="input" data-cond="value" value="' + esc(c.value) + '" placeholder="Value">';
    }
    return '<details class="sfb-adv sfb-logic"' + (on ? ' open' : '') + '><summary>' + ic('branch', 14) + ' Conditional logic' + (on ? ' <span class="pill pill-info">On</span>' : '') + '</summary>' +
      '<label class="switch-row"><input type="checkbox" class="switch switch-sm" data-cond-on' + (on ? ' checked' : '') + '><span>Show or hide this field based on an answer</span></label>' +
      (on ? '<div class="sfb-cond-rule"><select class="input" data-cond="action"><option value="show"' + (c.action !== 'hide' ? ' selected' : '') + '>Show</option><option value="hide"' + (c.action === 'hide' ? ' selected' : '') + '>Hide</option></select>' +
        '<span>this field when</span><select class="input" data-cond="field">' + others.map((x) => '<option value="' + esc(x.name) + '"' + (x === target ? ' selected' : '') + '>' + esc(x.label || x.name) + '</option>').join('') + '</select>' +
        '<select class="input" data-cond="op">' + Object.entries(OPS).map(([k, l]) => '<option value="' + k + '"' + (k === c.op ? ' selected' : '') + '>' + l + '</option>').join('') + '</select>' + valueCtl + '</div>' : '') + '</details>';
  }

  const insp = $('[data-inspector]');
  function onInspector(e) {
    const f = find(state.sel);
    if (!f) return;
    const el = e.target;
    if (el.dataset.k) {
      const k = el.dataset.k;
      const v = el.type === 'checkbox' ? el.checked : el.value;
      if (k === 'name') { f.name = slug(v).replace(/^_+/, ''); f._showKey = true; }
      else f[k] = v;
      if (k === 'label' && f._new && !f._keyTouched) f.name = keyFor(v, f);
      if (k === 'name') f._keyTouched = true;
      mark();
      renderCanvas();
      if (e.type === 'change' && ['required', 'width', 'default'].includes(k)) renderInspector();
      if (k === 'name' && e.type === 'change') renderInspector();
      return;
    }
    if (el.matches('[data-pattern-preset]')) {
      f.pattern = el.value === 'custom' ? (f.pattern || '.+') : el.value;
      mark(); renderInspector(); return;
    }
    if (el.matches('[data-c]')) {
      const list = parseChoices(f.options);
      const i = +el.closest('[data-ci]').dataset.ci;
      if (!list[i]) return;
      const wasSame = list[i].v === list[i].l;
      list[i][el.dataset.c] = el.value;
      if (el.dataset.c === 'l' && (wasSame || !f._customValues)) list[i].v = el.value;
      f.options = joinChoices(list);
      mark(); renderCanvas(); return;
    }
    if (el.matches('[data-c-custom]')) { f._customValues = el.checked; if (!el.checked) f.options = joinChoices(parseChoices(f.options).map((c) => ({ v: c.l, l: c.l }))); mark(); renderInspector(); return; }
    if (el.matches('[data-cond-on]')) {
      const others = valueFields(f).filter((x) => x.type !== 'hidden');
      f.cond = el.checked ? { field: others[0]?.name || '', op: 'is', value: CHOICE.includes(others[0]?.type) ? (parseChoices(others[0].options)[0]?.v || '') : '', action: 'show' } : null;
      mark(); renderInspector(); renderCanvas(); return;
    }
    if (el.matches('[data-cond]') && f.cond) {
      f.cond[el.dataset.cond] = el.value;
      if (el.dataset.cond === 'field') {
        const t = state.fields.find((x) => x.name === el.value);
        f.cond.value = t && CHOICE.includes(t.type) ? (parseChoices(t.options)[0]?.v || '') : t && t.type === 'consent' ? 'yes' : '';
      }
      mark(); renderCanvas();
      if (e.type === 'change' && ['field', 'op'].includes(el.dataset.cond)) renderInspector();
    }
  }
  insp.addEventListener('input', onInspector);
  insp.addEventListener('change', onInspector);
  insp.addEventListener('click', (e) => {
    const f = find(state.sel);
    if (!f) return;
    if (e.target.closest('[data-close-insp]')) { state.sel = null; renderCanvas(); renderInspector(); return; }
    const mv = e.target.closest('[data-move]');
    if (mv) { move(f.id, +mv.dataset.move); return; }
    if (e.target.closest('[data-c-add]')) {
      const list = parseChoices(f.options);
      list.push({ v: 'Option ' + (list.length + 1), l: 'Option ' + (list.length + 1) });
      f.options = joinChoices(list);
      mark(); renderCanvas(); renderInspector();
      const ins = $$('.sfb-choice [data-c="l"]', insp);
      ins[ins.length - 1]?.select();
      return;
    }
    const del = e.target.closest('[data-c-del]');
    if (del) { const list = parseChoices(f.options); list.splice(+del.dataset.cDel, 1); f.options = joinChoices(list); mark(); renderCanvas(); renderInspector(); return; }
    if (e.target.closest('[data-c-bulk]')) {
      const m = SL.modal({ title: 'Paste a list of choices', size: 'sm', body: '<label class="field"><span>One choice per line</span><textarea class="input" rows="10" data-bulk-text>' + esc(f.options) + '</textarea></label><p class="muted small">To store a different value, write it before a colon: <code>uk : United Kingdom</code></p>',
        foot: '<button type="button" class="btn" data-close>Cancel</button><button type="button" class="btn btn-primary" data-bulk-ok>Use these choices</button>' });
      m.querySelector('[data-bulk-ok]').addEventListener('click', () => { f.options = m.querySelector('[data-bulk-text]').value; f._customValues = null; m.close(); mark(); renderCanvas(); renderInspector(); });
    }
  });
  insp.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && e.target.matches('.sfb-choice [data-c="l"]')) { e.preventDefault(); insp.querySelector('[data-c-add]')?.click(); }
  });

  // ---------- settings and emails ----------
  const S = state.settings, D = cfg.defaults;
  const sIn = (k, attrs = '') => '<input class="input" data-s="' + k + '" value="' + esc(S[k] ?? '') + '" placeholder="' + esc(D[k] ?? '') + '" ' + attrs + '>';
  const sArea = (k, rows = 3) => '<textarea class="input" data-s="' + k + '" rows="' + rows + '" placeholder="' + esc(D[k] ?? '') + '">' + esc(S[k] ?? '') + '</textarea>';
  const tagChips = (target) => {
    const chip = (tag, label) => '<button type="button" class="sfb-tag" data-tag="' + esc(tag) + '" data-target="' + target + '" title="Inserts ' + esc(tag) + '">' + esc(label) + '</button>';
    return '<div class="sfb-tags"><span class="muted small">Insert:</span>' + chip('{form}', 'Form name') + chip('{site}', 'Site name') +
      (target.includes('message') ? chip('{all_fields}', 'All answers') : '') + valueFields().map((f) => chip('{' + f.name + '}', f.label || f.name)).join('') + '</div>';
  };
  function renderSettings() {
    const redirect = (S.redirect_url || '') !== '' || S._mode === 'redirect';
    const steps = state.fields.some((f) => f.type === 'step');
    $('[data-pane="settings"]').innerHTML =
      '<div class="card"><div class="card-head"><h2>After someone sends the form</h2></div><div class="card-pad stack">' +
        '<div class="seg"><label><input type="radio" name="sfb-after" value="message"' + (redirect ? '' : ' checked') + ' data-after> Show a message</label><label><input type="radio" name="sfb-after" value="redirect"' + (redirect ? ' checked' : '') + ' data-after> Go to another page</label></div>' +
        (redirect ? fieldRow('Page address', sIn('redirect_url', 'placeholder="/thank-you/"'), 'A page on your site (/thank-you/) or a full address.')
          : fieldRow('Thank-you message', sArea('success_message', 3), 'Shown in place of the form. Simple HTML links are allowed.')) +
        fieldRow('Button text', sIn('submit_label', 'maxlength="80"')) +
        fieldRow('Message when something is wrong', sArea('error_message', 2), 'Shown above the button. Each field also shows its own message.') +
        fieldRow('Small print under the button', sArea('note', 2), 'Optional, for example a privacy note. Simple HTML links are allowed.') +
      '</div></div>' +
      '<div class="card"><div class="card-head"><h2>Limits</h2></div><div class="card-pad stack">' +
        '<div class="sfb-pair">' + fieldRow('Stop after this many entries', '<input class="input" type="number" min="0" data-s="max_entries" value="' + esc(S.max_entries || '') + '" placeholder="No limit">', cfg.entries + ' received so far.') +
        fieldRow('Close on', '<input class="input" type="date" data-s="close_at" value="' + esc((S.close_at || '').slice(0, 10)) + '">', 'The form closes at the end of that day.') + '</div>' +
        fieldRow('Message when the form is closed', sArea('closed_message', 2)) +
      '</div></div>' +
      '<div class="card"><div class="card-head"><h2>Look</h2></div><div class="card-pad stack">' +
        '<div class="seg"><label><input type="radio" name="sfb-style" value="slate" data-s="style"' + (S.style !== 'theme' ? ' checked' : '') + '> Clean default</label><label><input type="radio" name="sfb-style" value="theme" data-s="style"' + (S.style === 'theme' ? ' checked' : '') + '> Website’s own styles</label></div>' +
        '<p class="muted small">The default look uses your design system colours, corners and fonts. "Website’s own styles" prints plain markup for pages with their own form design.</p>' +
        fieldRow('Extra CSS classes', sIn('css_class', 'placeholder="Optional"')) +
        (steps ? '<div class="sfb-pair">' + fieldRow('Next button', sIn('next_label')) + fieldRow('Back button', sIn('back_label')) + '</div>' : '') +
      '</div></div>' +
      '<div class="card sfb-spam"><div class="card-pad">' + ic('shield', 18) + '<div><strong>Spam protection is on</strong><p class="muted small">Every form has a hidden honeypot field, a minimum fill-in time and a limit per visitor. Change these for all forms in <a href="' + esc(cfg.urls.settings) + '">Forms settings</a>.</p></div></div></div>';
  }
  function renderEmails() {
    const on = (S.notify_to || '').toLowerCase() !== 'none';
    const hasEmail = state.fields.some((f) => f.type === 'email');
    $('[data-pane="emails"]').innerHTML =
      '<div class="card"><div class="card-head"><h2>Email me new entries</h2><input type="checkbox" class="switch" data-notify-on aria-label="Email new entries"' + (on ? ' checked' : '') + '></div>' +
        (on ? '<div class="card-pad stack">' + fieldRow('Send to', '<input class="input" data-s="notify_to" value="' + esc(S.notify_to || '') + '" placeholder="' + esc(cfg.adminEmail) + '">', 'Several addresses? Separate them with commas. Empty = ' + esc(cfg.adminEmail || 'the admin email') + '.') +
          fieldRow('Subject', sIn('email_subject', 'data-tagged="email_subject"')) + tagChips('email_subject') +
          '<p class="muted small sfb-note">' + ic('info', 14) + ' ' + (hasEmail ? 'Replies go straight to the visitor: the email\'s Reply-To is the address they typed.' : 'Add an Email field so you can reply to visitors directly.') + '</p></div>' : '<div class="card-pad"><p class="muted">No emails are sent. Entries are still saved under Entries.</p></div>') + '</div>' +
      '<div class="card"><div class="card-head"><h2>Confirmation to the visitor</h2><input type="checkbox" class="switch" data-s="confirm_enabled" aria-label="Send a confirmation email"' + (S.confirm_enabled ? ' checked' : '') + '></div>' +
        (S.confirm_enabled ? '<div class="card-pad stack">' + (hasEmail ? '' : '<div class="alert alert-error">' + ic('info', 15) + ' This form has no Email field, so there is nobody to send the confirmation to.</div>') +
          fieldRow('Subject', sIn('confirm_subject')) + tagChips('confirm_subject') +
          fieldRow('Message', sArea('confirm_message', 8)) + tagChips('confirm_message') +
          '<p class="muted small">"All answers" ({all_fields}) lists what the visitor filled in. Uploaded files are never attached.</p></div>'
          : '<div class="card-pad"><p class="muted">Send people a copy of their answers or a thank-you note.</p></div>') + '</div>';
  }
  function onSetting(e) {
    const el = e.target;
    if (el.matches('[data-after]')) {
      S._mode = el.value;
      if (el.value === 'message') S.redirect_url = '';
      mark(); renderSettings(); return;
    }
    if (el.matches('[data-notify-on]')) { S.notify_to = el.checked ? '' : 'none'; mark(); renderEmails(); return; }
    if (!el.dataset.s) return;
    if (el.type === 'radio' && !el.checked) return;
    S[el.dataset.s] = el.type === 'checkbox' ? el.checked : el.value;
    mark();
    if (el.dataset.s === 'confirm_enabled') renderEmails();
    if (el.dataset.s === 'submit_label') renderCanvas();
  }
  ['settings', 'emails'].forEach((p) => {
    const pane = $('[data-pane="' + p + '"]');
    pane.addEventListener('input', onSetting);
    pane.addEventListener('change', onSetting);
    pane.addEventListener('click', (e) => {
      const tag = e.target.closest('[data-tag]');
      if (!tag) return;
      const el = pane.querySelector('[data-s="' + tag.dataset.target + '"]');
      if (!el) return;
      const at = el.selectionStart ?? el.value.length;
      el.value = el.value.slice(0, at) + tag.dataset.tag + el.value.slice(el.selectionEnd ?? at);
      el.focus();
      el.selectionStart = el.selectionEnd = at + tag.dataset.tag.length;
      S[tag.dataset.target] = el.value;
      mark();
    });
  });

  // ---------- tabs ----------
  function setTab(t) {
    state.tab = t;
    $$('[data-tab]').forEach((b) => { b.classList.toggle('active', b.dataset.tab === t); b.setAttribute('aria-selected', b.dataset.tab === t ? 'true' : 'false'); });
    $$('[data-pane]').forEach((p) => { p.hidden = p.dataset.pane !== t; });
    if (t === 'settings') renderSettings();
    if (t === 'emails') renderEmails();
    try { sessionStorage.setItem('sfb-tab-' + cfg.id, t); } catch (e) {}
  }
  $('.sfb-tabs').addEventListener('click', (e) => { const b = e.target.closest('[data-tab]'); if (b) setTab(b.dataset.tab); });

  // ---------- embed ----------
  $('[data-embed]').addEventListener('click', () => {
    const sc = '[form slug="' + cfg.slug + '"]';
    const rows = [
      ['In the content editor', 'Click the Form button in the editor toolbar and pick "' + state.title + '". Or paste this shortcode:', sc],
      ['In Templates', 'Use the template tag:', '{{form:' + cfg.slug + '}}'],
      ['In a designed or imported page', 'Place this element where the form should appear:', '<div data-slate-form-widget="' + cfg.slug + '"></div>'],
      ['In theme PHP', 'Print it with:', "<?= slate_form('" + cfg.slug + "') ?>"],
    ];
    SL.modal({ title: 'Put this form on a page', size: 'sm', foot: '<button type="button" class="btn" data-close>Done</button>',
      body: (state.status !== 'publish' ? '<div class="alert alert-error">This form is a draft. Publish it so it shows on your site.</div>' : '') +
        '<div class="sfb-embed">' + rows.map(([t, d, code]) => '<div><strong>' + esc(t) + '</strong><p class="muted small">' + esc(d) + '</p><div class="sfb-copy"><code>' + esc(code) + '</code><button type="button" class="btn btn-sm" data-copy="' + esc(code) + '">' + ic('copy', 14) + ' Copy</button></div></div>').join('') + '</div>' });
  });

  // ---------- save ----------
  async function save() {
    if (!state.title.trim()) { toast('Give the form a name first.', 'error'); titleIn.focus(); return; }
    const missing = state.fields.find((f) => CHOICE.includes(f.type) && !parseChoices(f.options).length);
    if (missing) { state.sel = missing.id; setTab('fields'); renderCanvas(); renderInspector(); toast('"' + (missing.label || 'A choice field') + '" needs at least one choice.', 'error', 5000); return; }
    const btn = $('[data-save]');
    btn.classList.add('is-loading');
    const body = { id: cfg.id, title: state.title, status: state.status, settings: Object.assign({}, S),
      fields: state.fields.map((f) => { const o = Object.assign({}, f); Object.keys(o).forEach((k) => { if (k[0] === '_') delete o[k]; }); return o; }) };
    let res;
    try {
      const r = await fetch(cfg.urls.save, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': (window.SLATE || {}).csrf || '' }, body: JSON.stringify(body) });
      res = await r.json();
    } catch (e) { res = { ok: false, error: 'Network error, the form was not saved.' }; }
    btn.classList.remove('is-loading');
    if (!res.ok) { toast(res.error || 'Save failed.', 'error', 6000); return; }
    dirty = false;
    state.fields.forEach((f) => { delete f._new; });
    cfg.slug = res.slug || cfg.slug;
    state.status = res.status || state.status;
    syncStatus();
    $('[data-save-state]').textContent = 'Saved ' + new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    toast(res.message || 'Saved');
  }
  $('[data-save]').addEventListener('click', save);
  document.addEventListener('keydown', (e) => { if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 's') { e.preventDefault(); save(); } });
  window.addEventListener('beforeunload', (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
  $('[data-preview]').addEventListener('click', (e) => { if (dirty) { e.preventDefault(); save().then(() => window.open(cfg.urls.preview, '_blank', 'noopener')); } });

  renderCanvas();
  renderInspector();
  let start = 'fields';
  try { start = sessionStorage.getItem('sfb-tab-' + cfg.id) || 'fields'; } catch (e) {}
  setTab(['fields', 'settings', 'emails'].includes(start) ? start : 'fields');
})();
