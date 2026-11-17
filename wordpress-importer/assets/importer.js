/* Site Migration wizard (wordpress-importer). */
document.addEventListener('DOMContentLoaded', function () {
  const root = document.querySelector('[data-wpi]');
  if (!root) return;
  const { toast, esc } = window.Slate;
  const base = root.dataset.base;
  const $ = (s) => root.querySelector(s);
  const post = async (path, body, isForm) => {
    try {
      const res = await fetch(base + path, { method: 'POST', credentials: 'same-origin', body: isForm ? body : JSON.stringify(body || {}),
        headers: Object.assign({ 'X-CSRF-Token': SLATE.csrf, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, isForm ? {} : { 'Content-Type': 'application/json' }) });
      const t = await res.text();
      try { return JSON.parse(t); } catch (e) { return { ok: false, error: 'Server error (' + res.status + '): ' + t.replace(/<[^>]+>/g, ' ').slice(0, 300) }; }
    } catch (e) { return { ok: false, error: 'Network error, ' + e.message }; }
  };

  // Connect
  root.querySelectorAll('input[name="wpi_src"]').forEach((r) => r.addEventListener('change', () => {
    root.querySelectorAll('[data-src-panel]').forEach((p) => { p.hidden = p.dataset.srcPanel !== r.value; });
  }));
  $('[data-connect]')?.addEventListener('click', async (e) => {
    const b = e.currentTarget;
    const src = (root.querySelector('input[name="wpi_src"]:checked') || {}).value || 'rest';
    let r;
    b.classList.add('is-loading');
    if (src === 'wxr') {
      const f = $('[data-file]').files[0];
      if (!f) { b.classList.remove('is-loading'); return toast('Choose the export file', 'error'); }
      const fd = new FormData(); fd.append('file', f); r = await post('/upload', fd, true);
    } else {
      r = await post('/connect', { url: $('[data-in="url"]').value, user: $('[data-in="user"]').value, password: $('[data-in="password"]').value });
    }
    if (!r.ok) { b.classList.remove('is-loading'); return toast(r.error || 'Failed', 'error', 9000); }
    location.href = base + '?step=analyse';
  });

  // Analyse: add a database dump or an uploads archive
  $('[data-extra]')?.addEventListener('click', async (e) => {
    const b = e.currentTarget;
    const f = $('[data-extra-file]').files[0];
    if (!f) return toast('Choose a .sql, .sql.gz or .zip file', 'error');
    b.classList.add('is-loading');
    const fd = new FormData(); fd.append('file', f);
    const r = await post('/extra', fd, true);
    b.classList.remove('is-loading');
    if (!r.ok) return toast(r.error || 'Failed', 'error', 9000);
    toast('Added. The analysis was updated.');
    location.reload();
  });

  // Choose
  $('[data-start]')?.addEventListener('click', async (e) => {
    const options = { types: [...root.querySelectorAll('[data-type]:checked')].map((c) => c.dataset.type) };
    root.querySelectorAll('[data-opt]').forEach((c) => { options[c.dataset.opt] = c.checked; });
    root.querySelectorAll('[data-opt-select]').forEach((s) => { options[s.dataset.optSelect] = s.value; });
    if (!options.types.length) return toast('Select at least one content type', 'error');
    const btn = e.currentTarget;
    btn.classList.add('is-loading');
    const r = await post('/start', { options });
    if (!r.ok) { btn.classList.remove('is-loading'); return toast(r.error, 'error', 8000); }
    location.href = base + '?step=run&go=1';
  });

  // Import
  const labels = window.WPI_LABELS || {};
  let running = false;
  const render = (r) => {
    $('[data-bar]').style.width = r.progress + '%';
    $('[data-pct]').textContent = r.progress + '%';
    $('[data-step-label]').textContent = r.step;
    $('[data-label]').innerHTML = '<span class="pill pill-info">Running</span>';
    $('[data-stats]').innerHTML = Object.entries(r.stats || {}).map(([k, v]) => '<span class="pill">' + esc(labels[k] || k) + ': ' + v + '</span>').join('');
    const log = $('[data-log]'); log.textContent = (r.log || []).join('\n'); log.scrollTop = log.scrollHeight;
  };
  const loop = async () => {
    if (running || !$('[data-bar]') || !$('[data-resume]')) return;
    running = true;
    const btn = $('[data-resume]');
    btn.hidden = true;
    let fails = 0;
    while (running) {
      const r = await post('/step');
      if (!r.ok) {
        if (++fails > 4) { toast(r.error || 'Import paused', 'error', 9000); running = false; btn.hidden = false; $('[data-label]').innerHTML = '<span class="pill pill-warn">Paused</span>'; break; }
        await new Promise((res) => setTimeout(res, 2000 * fails));
        continue;
      }
      fails = 0;
      render(r);
      if (r.done) { running = false; toast('Import finished'); location.href = base + '?step=report'; break; }
    }
  };
  $('[data-resume]')?.addEventListener('click', loop);
  if (new URLSearchParams(location.search).get('go') === '1') loop();
});
