/* Slate Forms: inline validation, conditional fields, multi-step forms and sending without leaving the page.
   Everything here is an enhancement: without JavaScript the server validates and shows the same messages. */
(function () {
  'use strict';

  var MSG = {
    required: 'This field is required.',
    choose: 'Please choose an option.',
    chooseMany: 'Please choose at least one option.',
    consent: 'Please tick this box to continue.',
    file: 'Please choose a file.',
    email: 'Enter a valid email address.',
    url: 'Enter a valid web address.',
    number: 'Enter a number.',
    date: 'Enter a valid date.',
    format: 'Use the requested format.',
    network: 'Sorry, that did not go through. Please check your connection and try again.'
  };

  function controls(form, key) {
    return Array.prototype.filter.call(form.elements, function (el) {
      return el.name === 'fields[' + key + ']' || el.name === 'fields[' + key + '][]';
    });
  }
  function valueOf(form, key) {
    var out = [];
    controls(form, key).forEach(function (el) {
      if (el.disabled) return;
      if (el.type === 'checkbox' || el.type === 'radio') { if (el.checked) out.push(el.value); }
      else if (el.type === 'file') { if (el.files && el.files.length) out.push(el.files[0].name); }
      else if (el.value.trim() !== '') out.push(el.value.trim());
    });
    return out.map(function (v) { return v.toLowerCase(); });
  }
  function parts(form, key) { return form.querySelectorAll('[data-sf-field="' + key + '"]'); }

  // ---------- conditions ----------
  function applyRules(form) {
    var rules = form._sfRules;
    if (!rules.length) return;
    var shown = {};
    form._sfOrder.forEach(function (key) {
      var rule = rules.filter(function (r) { return r.key === key; })[0];
      var on = true;
      if (rule) {
        var c = rule.cond;
        var vals = shown[c.field] === false ? [] : valueOf(form, c.field);
        var want = String(c.value || '').trim().toLowerCase();
        var met;
        if (c.op === 'not') met = vals.indexOf(want) === -1;
        else if (c.op === 'contains') met = want !== '' && vals.some(function (v) { return v.indexOf(want) !== -1; });
        else if (c.op === 'empty') met = vals.length === 0;
        else if (c.op === 'filled') met = vals.length > 0;
        else met = vals.indexOf(want) !== -1;
        on = c.action === 'hide' ? !met : met;
        Array.prototype.forEach.call(parts(form, key), function (el) {
          el.hidden = !on;
          el.classList.toggle('is-off', !on);
        });
        controls(form, key).forEach(function (el) { el.disabled = !on; });
      }
      shown[key] = on;
    });
  }

  // ---------- validation ----------
  function errorEl(form, key) {
    var list = parts(form, key);
    for (var i = 0; i < list.length; i++) if (list[i].hasAttribute('data-sf-error')) return list[i];
    var box = null;
    Array.prototype.forEach.call(list, function (el) { var e = el.querySelector && el.querySelector('[data-sf-error]'); if (e) box = e; });
    if (box) return box;
    // Theme look: no reserved line, add one after the control.
    var ctl = controls(form, key)[0];
    if (!ctl) return null;
    var anchor = ctl.closest('fieldset') || (ctl.closest('label') && ctl.type === 'checkbox' ? ctl.closest('label') : ctl);
    box = document.createElement('p');
    box.className = 'slate-form__error-msg';
    box.id = (ctl.id || ('sf-' + key)) + '-err';
    box.setAttribute('data-sf-error', '');
    box.setAttribute('data-sf-field', key);
    box.hidden = true;
    anchor.insertAdjacentElement('afterend', box);
    return box;
  }
  function setError(form, key, msg) {
    var box = errorEl(form, key);
    var wrap = form.querySelector('.slate-form__field[data-sf-field="' + key + '"]');
    if (wrap) wrap.classList.toggle('has-error', !!msg);
    controls(form, key).forEach(function (el) { if (msg) el.setAttribute('aria-invalid', 'true'); else el.removeAttribute('aria-invalid'); });
    var group = form.querySelector('fieldset[data-sf-field="' + key + '"], .slate-form__field[data-sf-field="' + key + '"] fieldset');
    if (group) { if (msg) group.setAttribute('aria-invalid', 'true'); else group.removeAttribute('aria-invalid'); }
    if (box) { box.textContent = msg || ''; box.hidden = !msg; }
  }
  function check(form, key) {
    var els = controls(form, key).filter(function (el) { return !el.disabled && el.type !== 'hidden'; });
    if (!els.length) return '';
    var first = els[0];
    if (first.type === 'checkbox' && els.length > 1 || (first.type === 'checkbox' && /\[\]$/.test(first.name))) {
      var any = els.some(function (el) { return el.checked; });
      var n = els.filter(function (el) { return el.checked; }).length;
      var box = first.closest('fieldset');
      if (!any) return form.querySelector('[data-sf-req="' + key + '"]') ? MSG.chooseMany : '';
      if (box && box.dataset.min && n < +box.dataset.min) return 'Choose at least ' + box.dataset.min + '.';
      if (box && box.dataset.max && n > +box.dataset.max) return 'Choose at most ' + box.dataset.max + '.';
      return '';
    }
    if (first.type === 'radio') {
      return first.required && !els.some(function (el) { return el.checked; }) ? MSG.choose : '';
    }
    var v = first.validity;
    if (first.type === 'file' && first.files && first.files[0] && first.dataset.max && first.files[0].size > +first.dataset.max) {
      return 'The file is too large (up to ' + (Math.round(first.dataset.max / 104857.6) / 10) + ' MB).';
    }
    if (v.valid) return '';
    if (v.valueMissing) return first.type === 'checkbox' ? MSG.consent : first.type === 'file' ? MSG.file : first.tagName === 'SELECT' ? MSG.choose : MSG.required;
    if (v.typeMismatch) return first.type === 'email' ? MSG.email : first.type === 'url' ? MSG.url : MSG.format;
    if (v.badInput) return first.type === 'number' ? MSG.number : first.type === 'date' ? MSG.date : MSG.format;
    if (v.patternMismatch) return first.dataset.patternMsg || MSG.format;
    if (v.tooShort) return 'Use at least ' + first.minLength + ' characters.';
    if (v.tooLong) return 'Use at most ' + first.maxLength + ' characters.';
    if (v.rangeUnderflow) return first.type === 'date' ? 'Pick a date on or after ' + first.min + '.' : 'Enter ' + first.min + ' or more.';
    if (v.rangeOverflow) return first.type === 'date' ? 'Pick a date on or before ' + first.max + '.' : 'Enter ' + first.max + ' or less.';
    return first.validationMessage || MSG.format;
  }
  function validate(form, keys) {
    var bad = null;
    keys.forEach(function (key) {
      var msg = check(form, key);
      setError(form, key, msg);
      if (msg && !bad) bad = key;
    });
    if (bad) {
      var el = controls(form, bad).filter(function (x) { return !x.disabled; })[0];
      if (el) el.focus();
    }
    return !bad;
  }
  function keysIn(form, root) {
    var keys = [];
    Array.prototype.forEach.call(root.querySelectorAll('[name^="fields["]'), function (el) {
      var m = el.name.match(/^fields\[([^\]]+)\]/);
      if (m && keys.indexOf(m[1]) === -1 && el.type !== 'hidden') keys.push(m[1]);
    });
    return keys;
  }

  // ---------- steps ----------
  function setupSteps(form) {
    var steps = Array.prototype.slice.call(form.querySelectorAll('[data-sf-step]'));
    if (steps.length < 2) return;
    var actions = form.querySelector('.slate-form__actions');
    var progress = form.querySelector('[data-sf-progress]');
    // Back / Next sit in the button row, next to Send.
    var nav = actions || form;
    var back = document.createElement('button');
    back.type = 'button'; back.className = 'slate-form__back'; back.setAttribute('data-sf-back', ''); back.textContent = form.dataset.back || 'Back';
    var next = document.createElement('button');
    next.type = 'button'; next.className = 'slate-form__next'; next.setAttribute('data-sf-next', ''); next.textContent = form.dataset.next || 'Next';
    var submit = nav.querySelector('button[type="submit"]');
    nav.insertBefore(back, nav.firstChild);
    if (submit) submit.insertAdjacentElement('afterend', next); else nav.appendChild(next);
    var i = 0;
    function show(n, focus) {
      i = Math.max(0, Math.min(steps.length - 1, n));
      steps.forEach(function (s, k) { s.hidden = k !== i; });
      back.hidden = i === 0;
      next.hidden = i === steps.length - 1;
      if (submit) submit.hidden = i !== steps.length - 1;
      if (progress) {
        progress.hidden = false;
        progress.innerHTML = '<span>Step ' + (i + 1) + ' of ' + steps.length + '</span><span class="slate-form__bar"><span style="width:' + Math.round((i + 1) / steps.length * 100) + '%"></span></span>';
      }
      if (focus) { var f = steps[i].querySelector('input:not([type=hidden]):not([disabled]),select,textarea'); if (f) f.focus(); }
    }
    form.addEventListener('click', function (e) {
      if (e.target.closest('[data-sf-back]')) show(i - 1, true);
      if (e.target.closest('[data-sf-next]') && validate(form, keysIn(form, steps[i]))) show(i + 1, true);
    });
    form._sfShowStep = function (key) { steps.forEach(function (s, k) { if (s.querySelector('[name^="fields[' + key + ']"]')) show(k); }); };
    // Came back from the server with errors: open the first step that has one.
    var err = form.querySelector('.has-error, [aria-invalid="true"]');
    var start = 0;
    if (err) steps.forEach(function (s, k) { if (s.contains(err) && !start) start = k; });
    show(start);
  }

  // ---------- submit ----------
  function init(form) {
    if (form.dataset.sfReady) return;
    form.dataset.sfReady = '1';
    form.noValidate = true;
    form.classList.add('sf-js');
    var ok = form.querySelector('.slate-form__success');
    var err = form.querySelector('.slate-form__error:not(.slate-form__summary)');
    var btn = form.querySelector('button[type="submit"]');
    var started = Date.now();
    form._sfRules = [];
    form._sfOrder = [];
    Array.prototype.forEach.call(form.querySelectorAll('[data-sf-field]'), function (el) {
      var key = el.getAttribute('data-sf-field');
      if (form._sfOrder.indexOf(key) === -1) form._sfOrder.push(key);
      if (el.hasAttribute('data-sf-cond') && !form._sfRules.some(function (r) { return r.key === key; })) {
        try { form._sfRules.push({ key: key, cond: JSON.parse(el.getAttribute('data-sf-cond')) }); } catch (e) {}
      }
    });
    applyRules(form);
    setupSteps(form);
    var touched = {};
    var onChange = function (e) {
      applyRules(form);
      var m = e.target.name && e.target.name.match(/^fields\[([^\]]+)\]/);
      // Fields already showing a message update while typing, so the message goes away as soon as the answer is fine.
      var showing = m && Array.prototype.some.call(parts(form, m[1]), function (el) { return (el.matches('[data-sf-error]') || el.querySelector && el.querySelector('[data-sf-error]:not([hidden])')) && !(el.matches('[data-sf-error]') && el.hidden); });
      if (m && (touched[m[1]] || e.type === 'change' || showing)) { touched[m[1]] = true; setError(form, m[1], check(form, m[1])); }
    };
    form.addEventListener('input', onChange);
    form.addEventListener('change', onChange);
    form.addEventListener('focusout', function (e) {
      var m = e.target.name && e.target.name.match(/^fields\[([^\]]+)\]/);
      if (m && e.target.type !== 'checkbox' && e.target.type !== 'radio' && (e.target.value || touched[m[1]])) { touched[m[1]] = true; setError(form, m[1], check(form, m[1])); }
    });
    var show = function (el, on) { if (!el) return; el.hidden = !on; el.style.display = on ? 'block' : ''; if (on) el.focus({ preventScroll: false }); };

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      applyRules(form);
      show(ok, false);
      show(err, false);
      if (!validate(form, keysIn(form, form))) {
        var bad = form.querySelector('.has-error [name^="fields["], [aria-invalid="true"]');
        if (bad && form._sfShowStep) { var m = bad.name && bad.name.match(/^fields\[([^\]]+)\]/); if (m) { form._sfShowStep(m[1]); bad.focus(); } }
        return;
      }
      form.elements._js.value = String(Math.round((Date.now() - started) / 1000) || 1);
      form.elements._page.value = location.href.split('#')[0];
      form.elements._title.value = document.title;
      form.classList.remove('form-state-success', 'form-state-error');
      form.classList.add('is-sending');
      if (btn) btn.disabled = true;
      fetch(form.action, { method: 'POST', body: new FormData(form), headers: { Accept: 'application/json' }, credentials: 'same-origin' })
        .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
        .then(function (res) {
          if (res.ok) {
            if (res.redirect) { location.href = res.redirect; return; }
            form.reset();
            applyRules(form);
            form.classList.remove('form-state-default');
            form.classList.add('form-state-success', 'is-sent');
            if (ok && res.message) ok.innerHTML = ok.innerHTML || res.message;
            show(ok, true);
            return;
          }
          form.classList.add('form-state-error');
          var fieldErrors = res.errors && typeof res.errors === 'object' ? Object.keys(res.errors) : [];
          fieldErrors.forEach(function (k) { setError(form, k, res.errors[k]); });
          if (fieldErrors.length) {
            var first = controls(form, fieldErrors[0])[0];
            if (form._sfShowStep) form._sfShowStep(fieldErrors[0]);
            if (first) first.focus();
          }
          if (err) {
            if (!fieldErrors.length && res.message && res.message !== 'error') {
              err.dataset.orig = err.dataset.orig || err.innerHTML;
              err.textContent = res.message;
            } else if (err.dataset.orig) err.innerHTML = err.dataset.orig;
            show(err, !fieldErrors.length || !form.querySelector('[data-sf-error]:not([hidden])'));
            if (fieldErrors.length) err.hidden = false;
          }
        })
        .catch(function () {
          form.classList.add('form-state-error');
          if (err) { if (err.dataset.orig) err.innerHTML = err.dataset.orig; else err.textContent = MSG.network; show(err, true); }
        })
        .then(function () { form.classList.remove('is-sending'); if (btn) btn.disabled = false; });
    });

    // Back from a submit without JavaScript: bring the message into view.
    var summary = form.querySelector('.slate-form__summary');
    if (summary) summary.focus();
  }
  function boot() { document.querySelectorAll('form[data-slate-form]').forEach(init); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
