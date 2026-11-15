<?php
declare(strict_types=1);

use Slate\AdminUi;
use Slate\Ai;
use Slate\ContentModel;
use Slate\DB;
use Slate\ListTable;

$base = admin_url('m/auto-cpt');
$types = ContentModel::types();
$taxes = ContentModel::taxonomies();
$groups = ContentModel::fieldGroups();
$customTypes = array_filter($types, static fn($t) => empty($t['builtin']));
$runCount = (int)DB::val("SELECT COUNT(*) FROM {{activity_log}} WHERE action = 'auto_cpt_applied'");
$tab = ($_GET['tab'] ?? '') === 'history' ? 'history' : 'generate';

if ($tab === 'history') {
    $fmt = trim(option('date_format', 'M j, Y') . ' ' . option('time_format', 'H:i'));
    $table = new ListTable('acg.history', [
        'when' => ['label' => 'Date', 'primary' => true, 'sort' => 'created_at', 'render' => static fn($r) => '<strong>' . e(local_date($r['created_at'], $fmt)) . '</strong>',
            'export' => static fn($r) => (string)$r['created_at']],
        'what' => ['label' => 'What changed', 'render' => static fn($r) => e((string)$r['object'])],
        'by' => ['label' => 'By', 'render' => static fn($r) => e((string)($r['display_name'] ?: $r['username'] ?: '-'))],
    ], [
        'base' => $base, 'search' => 'Search…', 'sort' => 'created_at', 'dir' => 'desc', 'export' => true,
        'empty' => ['icon' => 'clock', 'title' => 'Nothing generated yet', 'text' => 'Each time you create a content model here it is listed with what changed.'],
    ]);
    $s = $table->state();
    $where = "a.action = 'auto_cpt_applied'";
    $params = [];
    if ($s['q'] !== '') {
        $where .= ' AND a.object LIKE :q';
        $params['q'] = '%' . addcslashes($s['q'], '%_\\') . '%';
    }
    $sql = "SELECT a.id, a.object, a.created_at, u.display_name, u.username FROM {{activity_log}} a LEFT JOIN {{users}} u ON u.id = a.user_id WHERE $where ORDER BY a.id " . ($s['dir'] === 'asc' ? 'ASC' : 'DESC');
    if ($table->exporting()) {
        $table->exportCsv(DB::all($sql, $params), 'content-model-history-' . gmdate('Y-m-d') . '.csv');
    }
    $total = (int)DB::val("SELECT COUNT(*) FROM {{activity_log}} a WHERE $where", $params);
    $rows = DB::all($sql . ' LIMIT ' . (int)$s['per'] . ' OFFSET ' . (int)$s['offset'], $params);
}

echo AdminUi::header('Auto CPT generator', 'Create post types, taxonomies and custom fields from an existing site or a short description, you review everything before it is created.', 'sparkles',
    ['Content model' => [admin_url('model/types'), 'ghost', 'layers']]);
echo AdminUi::kpis(['Custom post types' => count($customTypes), 'Taxonomies' => count($taxes), 'Field groups' => count($groups)]);
echo AdminUi::tabs(['generate' => ['Generate', $base], 'history' => ['History', $base . '?tab=history', $runCount ?: null]], $tab);

if ($tab === 'history') {
    echo $table->render($rows, $total);
    return;
}

$existing = [
    'types' => array_keys($types),
    'taxonomies' => array_keys($taxes),
    'groups' => array_values(array_map(static fn($g) => mb_strtolower((string)($g['title'] ?? '')), $groups)),
];
?>
<div class="stack" data-acg data-base="<?= e($base) ?>" data-existing="<?= e(json_encode($existing)) ?>">
  <nav class="wizard-steps" aria-label="Steps" data-steps>
    <button type="button" class="active" data-goto="source"><span>1</span> Choose source</button>
    <button type="button" data-goto="review" disabled><span>2</span> Review</button>
    <button type="button" data-goto="done" disabled><span>3</span> Create</button>
  </nav>

  <section class="card" data-step="source">
    <div class="card-head"><h2>Where should the content model come from?</h2></div>
    <div class="card-pad stack">
      <div class="choice-grid">
        <label class="choice-card"><input type="radio" name="acg_src" value="wp" checked><span>Live WordPress site<br><small class="muted">Reads its post types, taxonomies and ACF fields.</small></span></label>
        <label class="choice-card"><input type="radio" name="acg_src" value="wxr"><span>WordPress export file<br><small class="muted">A .xml file from Tools → Export.</small></span></label>
        <label class="choice-card"><input type="radio" name="acg_src" value="ai"><span>Describe it<br><small class="muted">Plain English; AI designs the model.</small></span></label>
        <label class="choice-card"><input type="radio" name="acg_src" value="json"><span>JSON spec<br><small class="muted">Paste a spec you already have.</small></span></label>
      </div>

      <div data-panel="wp" class="stack-sm">
        <div class="grid-form">
          <label class="field span-2"><span>Site address</span><input class="input" data-in="url" placeholder="https://example.com"></label>
          <label class="field"><span>Username <small class="muted">(optional)</small></span><input class="input" data-in="user" autocomplete="off"></label>
          <label class="field"><span>Application password <small class="muted">(optional)</small></span><input class="input" type="password" data-in="password" autocomplete="new-password"></label>
        </div>
        <small class="help">Without a login only public post types are read. Custom fields come from ACF (turn on “Show in REST API” for each field group) and registered meta.</small>
      </div>

      <div data-panel="wxr" class="stack-sm" hidden>
        <label class="field"><span>Export file</span><input class="input" type="file" accept=".xml" data-file></label>
        <small class="help">In WordPress: <strong>Tools → Export → All content</strong>. Every post type, taxonomy and ACF field is detected, including repeaters.</small>
      </div>

      <div data-panel="ai" class="stack-sm" hidden>
        <label class="field"><span>What is the site about?</span>
          <textarea class="input" rows="4" data-in="description" placeholder="A real-estate agency: property listings with price, bedrooms, bathrooms, area, gallery and location; agents with phone and email; neighbourhood guides."></textarea></label>
        <?php if (!Ai::enabled()): ?><div class="alert">Set up an AI provider under <a href="<?= e(admin_url('settings/ai/provider')) ?>">Settings → AI</a> to use this.</div><?php endif; ?>
      </div>

      <div data-panel="json" class="stack-sm" hidden>
        <label class="field"><span>Spec</span><textarea class="input code" rows="10" data-in="json" placeholder='{"post_types":[{"slug":"project","singular":"Project","plural":"Projects"}],"taxonomies":[],"field_groups":[]}'></textarea></label>
        <small class="help">Keys: <code>post_types</code>, <code>taxonomies</code>, <code>field_groups</code>. Missing details are filled in for you.</small>
      </div>
    </div>
    <div class="card-foot row-between">
      <span class="muted small">Nothing is created yet, next you'll review the result.</span>
      <button type="button" class="btn btn-primary" data-analyse data-ai-ready="<?= Ai::enabled() ? '1' : '0' ?>"><?= icon('search', 15) ?> <span>Analyse</span></button>
    </div>
  </section>

  <section class="stack" data-step="review" hidden>
    <section class="card">
      <div class="card-head row-between"><h2>Post types</h2><span class="muted small">Untick anything you don't want.</span></div>
      <div class="table-wrap"><table class="table table-compact">
        <thead><tr><th class="col-check"></th><th>Name</th><th>Slug</th><th>Address</th><th></th></tr></thead>
        <tbody data-rows="post_types"></tbody>
      </table></div>
    </section>
    <div class="grid-2">
      <section class="card">
        <div class="card-head"><h2>Taxonomies</h2></div>
        <div class="table-wrap"><table class="table table-compact">
          <thead><tr><th class="col-check"></th><th>Name</th><th>Used on</th><th></th></tr></thead>
          <tbody data-rows="taxonomies"></tbody>
        </table></div>
      </section>
      <section class="card">
        <div class="card-head"><h2>Custom fields</h2></div>
        <div class="table-wrap"><table class="table table-compact">
          <thead><tr><th class="col-check"></th><th>Field group</th><th>Used on</th><th></th></tr></thead>
          <tbody data-rows="field_groups"></tbody>
        </table></div>
      </section>
    </div>
    <section class="card">
      <details class="card-pad">
        <summary class="muted" style="cursor:pointer">Edit as JSON</summary>
        <textarea class="input code" rows="14" data-spec style="margin-top:10px"></textarea>
        <div style="margin-top:8px"><button type="button" class="btn btn-sm" data-refresh>Update preview</button></div>
      </details>
      <div class="card-foot row-between">
        <button type="button" class="btn btn-ghost" data-goto="source">Back</button>
        <button type="button" class="btn btn-primary" data-apply><?= icon('check', 15) ?> Create content model</button>
      </div>
    </section>
  </section>

  <section class="card" data-step="done" hidden>
    <div class="card-head row-between"><h2>Content model created</h2><?= AdminUi::pill('Done', 'success') ?></div>
    <div class="card-pad stack">
      <ul class="list list-compact" data-log style="margin:0"></ul>
      <div>
        <strong class="small-label">Open</strong>
        <div class="page-actions" style="margin-top:8px;justify-content:flex-start;flex-wrap:wrap" data-links></div>
      </div>
    </div>
    <div class="card-foot row-between">
      <span class="muted small">New post types appear in the sidebar after a reload.</span>
      <a class="btn" href="<?= e($base) ?>">Generate another</a>
    </div>
  </section>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  const root = document.querySelector('[data-acg]');
  if (!root) return;
  const { toast, esc } = window.Slate;
  const base = root.dataset.base;
  const existing = JSON.parse(root.dataset.existing || '{}');
  const $ = (s) => root.querySelector(s);
  const $$ = (s) => [...root.querySelectorAll(s)];
  let source = 'wp', spec = null;
  const post = async (path, body, isForm) => {
    try {
      const res = await fetch(base + path, { method: 'POST', credentials: 'same-origin', body: isForm ? body : JSON.stringify(body),
        headers: Object.assign({ 'X-CSRF-Token': SLATE.csrf, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, isForm ? {} : { 'Content-Type': 'application/json' }) });
      try { return await res.json(); } catch (e) { return { ok: false, error: 'Server error (' + res.status + ')' }; }
    } catch (e) { return { ok: false, error: 'Network error, ' + e.message }; }
  };
  const go = (step) => {
    $$('[data-step]').forEach((s) => { s.hidden = s.dataset.step !== step; });
    $$('[data-steps] [data-goto]').forEach((b) => b.classList.toggle('active', b.dataset.goto === step));
    if (step === 'done') $$('[data-steps] [data-goto]').forEach((b) => { b.disabled = b.dataset.goto !== 'done'; });
    window.scrollTo({ top: 0, behavior: 'smooth' });
  };
  $$('[data-goto]').forEach((b) => b.addEventListener('click', () => go(b.dataset.goto)));

  // Step 1, source
  const analyse = $('[data-analyse]');
  const setSource = (v) => {
    source = v;
    $$('[data-panel]').forEach((p) => { p.hidden = p.dataset.panel !== v; });
    analyse.querySelector('span').textContent = { wp: 'Analyse site', wxr: 'Analyse export', ai: 'Generate model', json: 'Review spec' }[v];
    analyse.classList.toggle('btn-ai', v === 'ai');
    analyse.classList.toggle('btn-primary', v !== 'ai');
    analyse.disabled = v === 'ai' && analyse.dataset.aiReady !== '1';
  };
  $$('input[name="acg_src"]').forEach((r) => r.addEventListener('change', () => setSource(r.value)));
  setSource('wp');
  const val = (k) => $('[data-in="' + k + '"]')?.value || '';
  analyse.addEventListener('click', async () => {
    let r;
    if (source === 'json') {
      try { r = { ok: true, spec: JSON.parse(val('json') || '{}') }; } catch (e) { return toast('The JSON is not valid', 'error'); }
    } else {
      analyse.classList.add('is-loading');
      if (source === 'wxr') {
        const f = $('[data-file]').files[0];
        if (!f) { analyse.classList.remove('is-loading'); return toast('Choose an export file first', 'error'); }
        const fd = new FormData(); fd.append('file', f); r = await post('/scan-wxr', fd, true);
      } else if (source === 'ai') r = await post('/describe', { description: val('description') });
      else r = await post('/scan-wp', { url: val('url'), user: val('user'), password: val('password') });
      analyse.classList.remove('is-loading');
    }
    if (!r.ok) return toast(r.error || 'Failed', 'error', 7000);
    $('[data-spec]').value = JSON.stringify(r.spec, null, 2);
    show(r.spec);
  });

  // Step 2, review
  const tag = (isNew) => isNew ? '<span class="pill pill-info">new</span>' : '<span class="pill">update</span>';
  const check = (kind, i) => '<td class="col-check"><input type="checkbox" checked data-pick="' + kind + '" data-i="' + i + '" aria-label="Include"></td>';
  const empty = (cols, text) => '<tr><td colspan="' + cols + '" class="muted">' + esc(text) + '</td></tr>';
  const show = (s) => {
    spec = Object.assign({ post_types: [], taxonomies: [], field_groups: [] }, s || {});
    const pts = (spec.post_types || []);
    $('[data-rows="post_types"]').innerHTML = pts.length ? pts.map((t, i) => '<tr>' + check('post_types', i) + '<td><strong>' + esc(t.plural || t.singular || t.slug) + '</strong></td><td><code>'
      + esc(t.slug || '') + '</code></td><td class="muted">/' + esc(t.rewrite || t.slug || '') + '/</td><td>' + tag(!existing.types.includes(t.slug)) + '</td></tr>').join('') : empty(5, 'No new post types.');
    $('[data-rows="taxonomies"]').innerHTML = (spec.taxonomies || []).length ? spec.taxonomies.map((t, i) => '<tr>' + check('taxonomies', i) + '<td><strong>' + esc(t.plural || t.slug) + '</strong><br><small class="muted">' + esc(t.slug || '') + '</small></td><td>'
      + esc((t.post_types || []).join(', ') || '-') + '</td><td>' + tag(!existing.taxonomies.includes(t.slug)) + '</td></tr>').join('') : empty(4, 'No taxonomies.');
    $('[data-rows="field_groups"]').innerHTML = (spec.field_groups || []).length ? spec.field_groups.map((g, i) => '<tr>' + check('field_groups', i) + '<td><strong>' + esc(g.title || 'Fields') + '</strong><br><small class="muted">'
      + esc((g.fields || []).map((f) => (f.label || f.name) + ' (' + (f.type || 'text') + ')').join(', ')) + '</small></td><td>' + esc([...(g.post_types || []), ...(g.taxonomies || [])].join(', ') || '-') + '</td><td>'
      + tag(!existing.groups.includes(String(g.title || '').toLowerCase())) + '</td></tr>').join('') : empty(4, 'No custom fields.');
    const b = $('[data-steps] [data-goto="review"]'); b.disabled = false;
    go('review');
  };
  $('[data-refresh]').addEventListener('click', () => {
    try { show(JSON.parse($('[data-spec]').value || '{}')); toast('Preview updated'); } catch (e) { toast('The JSON is not valid', 'error'); }
  });

  // Step 3, create
  $('[data-apply]').addEventListener('click', async (e) => {
    let full;
    try { full = JSON.parse($('[data-spec]').value || '{}'); } catch (err) { return toast('The JSON is not valid', 'error'); }
    const out = Object.assign({}, full);
    ['post_types', 'taxonomies', 'field_groups'].forEach((k) => {
      const off = $$('[data-pick="' + k + '"]').filter((c) => !c.checked).map((c) => +c.dataset.i);
      out[k] = (full[k] || []).filter((_, i) => !off.includes(i));
    });
    if (!out.post_types.length && !out.taxonomies.length && !out.field_groups.length) return toast('Nothing selected', 'error');
    const btn = e.currentTarget;
    btn.classList.add('is-loading');
    const r = await post('/apply', { spec: out, source });
    btn.classList.remove('is-loading');
    if (!r.ok) return toast(r.error, 'error', 7000);
    $('[data-log]').innerHTML = r.log.map((l) => '<li><span class="list-main"><span class="dot dot-info"></span><span>' + esc(l) + '</span></span></li>').join('');
    $('[data-links]').innerHTML = (r.links || []).map((l, i) => '<a class="btn' + (i === 0 ? ' btn-primary' : '') + '" href="' + esc(l.url) + '">' + esc(l.label) + '</a>').join('');
    go('done');
    toast('Content model created');
  });
});
</script>
