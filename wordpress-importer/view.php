<?php
declare(strict_types=1);

use Slate\AdminUi;
use Slate\ContentModel;
use Slate\DB;
use Slate\ListTable;
use Slate\Media;
use Slate\Options;
use Slate\Posts;
use Slate\Terms;
use SlateModules\WpImport\Importer;

$base = admin_url('m/wp-import');
$job = Importer::job();
$scan = Options::get('wpi_scan');
$scan = is_array($scan) && isset($scan['summary']) ? $scan : null;
$conn = Options::get('wpi_conn') ?: [];
$counts = ['post' => 0, 'media' => 0, 'term' => 0];
foreach (DB::all("SELECT kind, COUNT(*) AS c FROM {{wpi_map}} WHERE kind IN ('post','media','term') GROUP BY kind") as $r) {
    $counts[$r['kind']] = (int)$r['c'];
}
$imported = array_sum($counts);
$events = DB::all("SELECT id, user_id, action, object, created_at FROM {{activity_log}} WHERE action IN ('wp_import_started','wp_import_finished') ORDER BY id DESC LIMIT 400");

// Runs = a "started" event closed by the next "finished" event for the same source (CLI runs only log "finished").
$runs = [];
$open = [];
foreach (array_reverse($events) as $ev) {
    if ($ev['action'] === 'wp_import_started') {
        $runs[] = ['source' => $ev['object'], 'started' => $ev['created_at'], 'finished' => null, 'user_id' => $ev['user_id']];
        $open[$ev['object']] = array_key_last($runs);
    } elseif (isset($open[$ev['object']])) {
        $runs[$open[$ev['object']]]['finished'] = $ev['created_at'];
        unset($open[$ev['object']]);
    } else {
        $runs[] = ['source' => $ev['object'], 'started' => null, 'finished' => $ev['created_at'], 'user_id' => $ev['user_id']];
    }
}
$runs = array_reverse($runs);
$lastFinished = $job['finished_at'] ?? null;
foreach ($runs as $r) {
    $lastFinished ??= $r['finished'];
}

$tab = in_array($_GET['tab'] ?? '', ['items', 'history'], true) ? (string)$_GET['tab'] : 'migrate';
$typeLabel = static fn(string $slug): string => (string)(ContentModel::type($slug)['plural'] ?? ucfirst(str_replace(['_', '-'], ' ', $slug)));
$taxLabel = static fn(string $slug): string => (string)(ContentModel::taxonomy($slug)['plural'] ?? ucfirst(str_replace(['_', '-'], ' ', $slug)));

// ------------------------------------------------------------------ lists (handled first so CSV export can stream)

if ($tab === 'items') {
    $view = in_array($_GET['view'] ?? '', ['media', 'terms'], true) ? (string)$_GET['view'] : 'content';
    $filterOpts = match ($view) {
        'content' => array_map($typeLabel, array_column(DB::all("SELECT DISTINCT p.type FROM {{wpi_map}} m INNER JOIN {{posts}} p ON p.id = m.new_id WHERE m.kind = 'post'"), 'type', 'type')),
        'terms' => array_map($taxLabel, array_column(DB::all("SELECT DISTINCT t.taxonomy FROM {{wpi_map}} m INNER JOIN {{terms}} t ON t.id = m.new_id WHERE m.kind = 'term'"), 'taxonomy', 'taxonomy')),
        default => [],
    };
    $shortUrl = static fn(string $u): string => rawurldecode((string)(parse_url($u, PHP_URL_PATH) ?: '/')) . (($qs = parse_url($u, PHP_URL_QUERY)) ? '?' . $qs : '');
    $table = new ListTable('wpi.items', [
        'title' => ['label' => 'Title', 'primary' => true, 'sort' => 'title', 'render' => static fn($r) => $r['edit']
            ? '<a class="row-title" href="' . e($r['edit']) . '">' . e($r['title']) . '</a>' : '<strong>' . e($r['title']) . '</strong>',
            'export' => static fn($r) => $r['title']],
        'type' => ['label' => 'Type', 'sort' => 'type', 'render' => static fn($r) => e($r['type'])],
        'status' => ['label' => 'Status', 'render' => static fn($r) => $r['status'] !== '' ? '<span class="status status-' . e($r['status']) . '">' . e(['publish' => 'Published'][$r['status']] ?? ucfirst($r['status'])) . '</span>' : '<span class="muted">-</span>',
            'export' => static fn($r) => $r['status']],
        'old' => ['label' => 'Old URL', 'sort' => 'old', 'render' => static fn($r) => $r['old'] !== '' ? '<a class="cell-link small" href="' . e($r['old']) . '" target="_blank" rel="noopener" title="' . e($r['old']) . '">' . e($shortUrl($r['old'])) . '</a>' : '<span class="muted">-</span>',
            'export' => static fn($r) => $r['old']],
        'new' => ['label' => 'New URL', 'render' => static fn($r) => $r['url'] !== '' ? '<a class="cell-link small" href="' . e($r['url']) . '" target="_blank" rel="noopener" title="' . e($r['url']) . '">' . e($shortUrl($r['url'])) . '</a>' : '<span class="muted">-</span>',
            'export' => static fn($r) => $r['url']],
        'id' => ['label' => 'Slate ID', 'sort' => 'id', 'default' => false, 'align' => 'right', 'render' => static fn($r) => (string)(int)$r['id']],
    ], [
        'base' => $base,
        'search' => 'Search by title or old URL…',
        'views' => ['content' => ['Pages & posts', $counts['post']], 'media' => ['Media', $counts['media']], 'terms' => ['Categories & tags', $counts['term']]],
        'filters' => $filterOpts ? ['type' => ['label' => $view === 'terms' ? 'Taxonomy' : 'Type', 'all' => $view === 'terms' ? 'All taxonomies' : 'All types', 'options' => $filterOpts]] : [],
        'sort' => 'id', 'dir' => 'desc', 'export' => true,
        'row_actions' => static fn($r) => ($r['edit'] ? '<a href="' . e($r['edit']) . '">Edit</a>' : '') . ($r['url'] !== '' ? '<a href="' . e($r['url']) . '" target="_blank" rel="noopener">View</a>' : ''),
        'empty' => ['icon' => 'download', 'title' => 'Nothing imported yet', 'text' => 'Items appear here after a migration, with their old and new addresses.'],
    ]);
    $s = $table->state();
    [$from, $titleCol, $typeCol] = match ($view) {
        'media' => ['{{wpi_map}} m INNER JOIN {{media}} x ON x.id = m.new_id', 'x.original_name', 'x.mime'],
        'terms' => ['{{wpi_map}} m INNER JOIN {{terms}} x ON x.id = m.new_id', 'x.name', 'x.taxonomy'],
        default => ['{{wpi_map}} m INNER JOIN {{posts}} x ON x.id = m.new_id', 'x.title', 'x.type'],
    };
    $where = ['m.kind = :kind'];
    $params = ['kind' => ['content' => 'post', 'media' => 'media', 'terms' => 'term'][$view]];
    if ($s['q'] !== '') {
        $where[] = "($titleCol LIKE :q OR m.old_url LIKE :q2)";
        $params['q'] = $params['q2'] = '%' . addcslashes($s['q'], '%_\\') . '%';
    }
    if (isset($s['filters']['type'], $filterOpts[$s['filters']['type']])) {
        $where[] = "$typeCol = :type";
        $params['type'] = $s['filters']['type'];
    }
    $order = ['title' => $titleCol, 'type' => $typeCol, 'old' => 'm.old_url', 'id' => 'x.id'][$s['sort']] ?? 'x.id';
    $sql = "SELECT x.*, m.old_url AS wpi_old_url FROM $from WHERE " . implode(' AND ', $where) . " ORDER BY $order " . ($s['dir'] === 'asc' ? 'ASC' : 'DESC') . ', x.id DESC';
    $toRow = static function (array $x) use ($view, $typeLabel, $taxLabel): array {
        if ($view === 'media') {
            return ['id' => $x['id'], 'title' => $x['original_name'] ?: basename((string)$x['path']), 'type' => (string)$x['mime'], 'status' => '',
                'old' => (string)$x['wpi_old_url'], 'url' => Media::url($x), 'edit' => ''];
        }
        if ($view === 'terms') {
            return ['id' => $x['id'], 'title' => (string)$x['name'], 'type' => $taxLabel((string)$x['taxonomy']), 'status' => '',
                'old' => (string)$x['wpi_old_url'], 'url' => Terms::link($x, true), 'edit' => admin_url('terms/' . $x['taxonomy'] . '/' . $x['id'])];
        }
        $p = Posts::hydrate($x);
        return ['id' => $p['id'], 'title' => $p['title'] !== '' ? (string)$p['title'] : '(no title)', 'type' => $typeLabel((string)$p['type']), 'status' => (string)$p['status'],
            'old' => (string)$x['wpi_old_url'], 'url' => $p['status'] === 'publish' ? Posts::permalink($p, true) : '', 'edit' => admin_url('content/' . $p['type'] . '/' . $p['id'])];
    };
    if ($table->exporting()) {
        $table->exportCsv((static function () use ($sql, $params, $toRow) {
            foreach (DB::all($sql, $params) as $x) {
                yield $toRow($x);
            }
        })(), 'migrated-' . $view . '-' . gmdate('Y-m-d') . '.csv');
    }
    $total = (int)DB::val("SELECT COUNT(*) FROM $from WHERE " . implode(' AND ', $where), $params);
    $rows = array_map($toRow, DB::all($sql . ' LIMIT ' . (int)$s['per'] . ' OFFSET ' . (int)$s['offset'], $params));
}

if ($tab === 'history') {
    $fmt = trim(option('date_format', 'M j, Y') . ' ' . option('time_format', 'H:i'));
    $userIds = array_values(array_unique(array_filter(array_map('intval', array_column($runs, 'user_id')))));
    $users = $userIds ? array_column(DB::all('SELECT id, display_name, username FROM {{users}} WHERE id IN (' . implode(',', $userIds) . ')'), null, 'id') : [];
    $who = static fn($r) => ($u = $users[(int)$r['user_id']] ?? null) ? (string)($u['display_name'] ?: $u['username']) : 'Command line';
    $duration = static function (array $r): string {
        if (!$r['started'] || !$r['finished']) {
            return '';
        }
        $sec = max(0, strtotime($r['finished'] . ' UTC') - strtotime($r['started'] . ' UTC'));
        return $sec >= 3600 ? floor($sec / 3600) . ' h ' . floor($sec % 3600 / 60) . ' min' : ($sec >= 60 ? floor($sec / 60) . ' min ' . $sec % 60 . ' s' : $sec . ' s');
    };
    $table = new ListTable('wpi.history', [
        'when' => ['label' => 'Started', 'primary' => true, 'sort' => 'when', 'render' => static fn($r) => '<strong>' . e(local_date($r['started'] ?? $r['finished'], $fmt)) . '</strong>',
            'export' => static fn($r) => (string)($r['started'] ?? $r['finished'])],
        'source' => ['label' => 'Source', 'render' => static fn($r) => $r['source'] !== '' ? e($r['source']) : '<span class="muted">Export file</span>'],
        'status' => ['label' => 'Result', 'render' => static fn($r) => $r['finished'] ? AdminUi::pill('Finished', 'success') : AdminUi::pill('Not finished', 'warn'),
            'export' => static fn($r) => $r['finished'] ? 'Finished' : 'Not finished'],
        'duration' => ['label' => 'Duration', 'render' => static fn($r) => e($duration($r)) ?: '<span class="muted">-</span>', 'export' => $duration],
        'by' => ['label' => 'By', 'render' => static fn($r) => e($who($r)), 'export' => $who],
    ], [
        'base' => $base, 'search' => 'Search by source…', 'sort' => 'when', 'dir' => 'desc', 'export' => true,
        'empty' => ['icon' => 'clock', 'title' => 'No migrations yet', 'text' => 'Every import you run is listed here.'],
    ]);
    $s = $table->state();
    $list = array_values(array_filter($runs, static fn($r) => $s['q'] === '' || stripos($r['source'], $s['q']) !== false));
    if ($s['dir'] === 'asc') {
        $list = array_reverse($list);
    }
    if ($table->exporting()) {
        $table->exportCsv($list, 'migration-history-' . gmdate('Y-m-d') . '.csv');
    }
    $total = count($list);
    $rows = array_slice($list, (int)$s['offset'], (int)$s['per']);
}

// ------------------------------------------------------------------ header

echo AdminUi::header('Site migration', 'Move a WordPress site to Slate, content, media, SEO, menus and design, and keep every old link working.', 'download',
    $imported ? ['View site' => [url(), 'ghost', 'external']] : []);
if ($imported) {
    echo AdminUi::kpis(['Pages & posts' => number_format($counts['post']), 'Media files' => number_format($counts['media']),
        'Categories & tags' => number_format($counts['term']), 'Last import' => $lastFinished ? time_ago($lastFinished) : '-']);
}
echo AdminUi::tabs([
    'migrate' => ['Migrate', $base],
    'items' => ['Imported items', $base . '?tab=items', $imported ?: null],
    'history' => ['History', $base . '?tab=history', count($runs) ?: null],
], $tab);

if ($tab === 'items') {
    echo $table->render($rows, $total);
    if ($imported || $job): ?>
  <section class="card" style="margin-top:18px">
    <div class="card-head"><h2>Undo</h2></div>
    <div class="card-pad stack-sm">
      <p class="muted" style="margin:0"><strong>Forget import history</strong> keeps the imported content but stops treating it as imported: running the migration again will create copies, and old <code>?p=</code> and media links stop redirecting.
        <strong>Delete imported content</strong> removes every page, post, media file and term that came from the old site.</p>
      <div class="page-actions">
        <button type="button" class="btn" data-wpi-reset>Forget import history</button>
        <button type="button" class="btn btn-danger" data-wpi-reset-delete>Delete imported content</button>
      </div>
    </div>
  </section>
<?php endif;
}

if ($tab === 'history') {
    echo $table->render($rows, $total);
}

if ($tab !== 'migrate'): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  const post = (body) => fetch(<?= json_encode($base . '/reset') ?>, { method: 'POST', credentials: 'same-origin', body: JSON.stringify(body),
    headers: { 'X-CSRF-Token': SLATE.csrf, 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
  document.querySelector('[data-wpi-reset]')?.addEventListener('click', async () => {
    if (confirm('Forget the import history? Imported content stays, but a new import will create copies.')) { await post({}); location.reload(); }
  });
  document.querySelector('[data-wpi-reset-delete]')?.addEventListener('click', async () => {
    if (confirm('Permanently delete ALL pages, posts, media and terms that were imported? This cannot be undone.')) { await post({ delete: true }); location.href = <?= json_encode($base) ?>; }
  });
});
</script>
<?php
    return;
endif;

// ------------------------------------------------------------------ wizard

$step = (string)($_GET['step'] ?? '');
if (!in_array($step, ['source', 'review', 'run'], true)) {
    $step = $job ? 'run' : ($scan ? 'review' : 'source');
}
if ($step === 'review' && !$scan) {
    $step = 'source';
}
if ($step === 'run' && !$job) {
    $step = $scan ? 'review' : 'source';
}
$running = $job && !$job['finished_at'];
$steps = ['source' => ['1', 'Choose source', true], 'review' => ['2', 'Review', (bool)$scan], 'run' => ['3', 'Import', (bool)$job]];
?>
<div class="stack" data-wpi data-base="<?= e($base) ?>">
  <nav class="wizard-steps" aria-label="Steps">
    <?php foreach ($steps as $k => [$n, $label, $reachable]): ?>
      <?php if ($reachable): ?>
        <a href="<?= e($base . '?step=' . $k) ?>"<?= $k === $step ? ' class="active" aria-current="step"' : '' ?>><span><?= $n ?></span> <?= e($label) ?></a>
      <?php else: ?>
        <button type="button" disabled><span><?= $n ?></span> <?= e($label) ?></button>
      <?php endif; ?>
    <?php endforeach; ?>
  </nav>

<?php if ($step === 'source'): $src = ($conn['source'] ?? 'rest') === 'wxr' ? 'wxr' : 'rest'; ?>
  <?php if ($running): ?>
    <div class="alert">An import is paused. <a href="<?= e($base . '?step=run') ?>">Resume it</a> or analyse a new source below.</div>
  <?php endif; ?>
  <section class="card">
    <div class="card-head"><h2>Where is the site now?</h2></div>
    <div class="card-pad stack">
      <div class="choice-grid">
        <label class="choice-card"><input type="radio" name="wpi_src" value="rest"<?= $src === 'rest' ? ' checked' : '' ?>><span>Live WordPress site <small class="muted">(recommended)</small><br><small class="muted">Reads everything, including drafts, menus and settings.</small></span></label>
        <label class="choice-card"><input type="radio" name="wpi_src" value="wxr"<?= $src === 'wxr' ? ' checked' : '' ?>><span>WordPress export file (.xml)<br><small class="muted">When you can't give admin access. Media still downloads from the live site.</small></span></label>
      </div>
      <div data-src-panel="rest" class="stack-sm"<?= $src === 'rest' ? '' : ' hidden' ?>>
        <div class="grid-form">
          <label class="field span-2"><span>Site address</span><input class="input" data-in="url" value="<?= e(($conn['source'] ?? '') === 'rest' ? ($conn['url'] ?? '') : '') ?>" placeholder="https://example.com"></label>
          <label class="field"><span>Administrator username</span><input class="input" data-in="user" value="<?= e($conn['user'] ?? '') ?>" autocomplete="off"></label>
          <label class="field"><span>Application password</span><input class="input" type="password" data-in="password" autocomplete="new-password" placeholder="xxxx xxxx xxxx xxxx xxxx xxxx"></label>
        </div>
        <small class="help">In WordPress go to <strong>Users › Profile › Application Passwords</strong>, add one named “Slate” and paste it here. Never use your login password. Without it only public content is read.</small>
      </div>
      <div data-src-panel="wxr" class="stack-sm"<?= $src === 'wxr' ? '' : ' hidden' ?>>
        <label class="field"><span>Export file</span><input class="input" type="file" accept=".xml" data-file></label>
        <small class="help">In WordPress: <strong>Tools › Export › All content</strong>. Keep the old site online during the import so media can be downloaded.</small>
      </div>
    </div>
    <div class="card-foot row-between">
      <span class="muted small">Nothing is imported yet, next you'll see what was found.</span>
      <button type="button" class="btn btn-primary" data-connect><?= icon('search', 15) ?> Analyse site</button>
    </div>
  </section>

<?php elseif ($step === 'review'):
    $sum = $scan['summary'];
    $spec = $scan['spec'] ?? [];
    // Pre-fill with the choices of the last full import (not a partial "only these steps" refresh).
    $opts = (empty($job['opts']['only']) ? ($job['opts'] ?? []) : []) + ['types' => []];
    $specTypes = array_column($spec['post_types'] ?? [], 'plural', 'slug');
    // Pre-tick the types chosen last time (when they belong to this source), otherwise everything.
    $picked = array_intersect((array)$opts['types'], array_map('strval', array_keys($scan['counts']['types'] ?? [])));
    $detected = implode(' · ', array_filter([!empty($sum['elementor']) ? 'Page builder (Elementor)' : null, is_string($sum['seo'] ?? null) ? $sum['seo'] : (!empty($sum['seo']) ? 'SEO meta' : null), !empty($sum['acf']) ? 'ACF custom fields' : null]));
    $total = 0;
    ?>
  <section class="card">
    <div class="card-head row-between"><h2>What we found</h2><a class="btn btn-ghost btn-sm" href="<?= e($base . '?step=source') ?>">Change source</a></div>
    <div class="card-pad">
      <div class="kv">
        <span>Site</span><strong><?= e((string)($sum['site'] ?: '-')) ?></strong>
        <span>Address</span><span><?= $sum['url'] ? '<a href="' . e((string)$sum['url']) . '" target="_blank" rel="noopener">' . e((string)$sum['url']) . '</a>' : '-' ?></span>
        <span>Access</span><span><?= ($conn['source'] ?? '') === 'wxr' ? 'Export file' : (!empty($sum['auth']) ? 'Signed in as ' . e((string)($sum['user'] ?? 'admin')) : AdminUi::pill('Public content only', 'warn') . ' <small class="muted">drafts, menus and settings need an application password</small>') ?></span>
        <span>Detected</span><span><?= e($detected ?: 'Nothing special') ?></span>
        <span>Media files</span><span><?= e(isset($sum['media']) ? number_format((int)$sum['media']) : '-') ?></span>
        <span>Menus</span><span><?= e(isset($sum['menus']) ? number_format((int)$sum['menus']) : 'Not readable') ?></span>
      </div>
    </div>
  </section>

  <section class="card">
    <div class="card-head"><h2>Content to import</h2><span class="muted small">Untick anything you don't want.</span></div>
    <div class="table-wrap">
      <table class="table table-compact">
        <thead><tr><th class="col-check"></th><th>WordPress type</th><th>Becomes in Slate</th><th class="num">Items</th></tr></thead>
        <tbody>
        <?php foreach ($scan['counts']['types'] ?? [] as $wp => $c): $exists = ContentModel::type((string)$c['slug']) !== null; $total += (int)$c['total']; ?>
          <tr>
            <td class="col-check"><input type="checkbox" data-type="<?= e((string)$wp) ?>"<?= !$picked || in_array((string)$wp, $picked, true) ? ' checked' : '' ?> aria-label="Import <?= e((string)$wp) ?>"></td>
            <td><code><?= e((string)$wp) ?></code></td>
            <td><?= e($exists ? $typeLabel((string)$c['slug']) : (string)($specTypes[$c['slug']] ?? $c['slug'])) ?>
              <?= $exists ? AdminUi::pill('exists', '') : AdminUi::pill('new type', 'info') ?></td>
            <td class="num"><?= number_format((int)$c['total']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($scan['counts']['types'])): ?><tr><td colspan="4" class="muted">No content types found.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>

  <?php if (!empty($scan['counts']['taxonomies']) || !empty($spec['field_groups'])): ?>
  <div class="grid-2">
    <?php if (!empty($scan['counts']['taxonomies'])): ?>
    <section class="card">
      <div class="card-head"><h2>Taxonomies</h2></div>
      <div class="table-wrap"><table class="table table-compact">
        <thead><tr><th>WordPress</th><th>Becomes</th><th class="num">Terms</th></tr></thead>
        <tbody>
        <?php foreach ($scan['counts']['taxonomies'] as $wp => $c): $exists = ContentModel::taxonomy((string)$c['slug']) !== null; ?>
          <tr><td><code><?= e((string)$wp) ?></code></td><td><?= e($exists ? $taxLabel((string)$c['slug']) : (string)$c['slug']) ?> <?= $exists ? AdminUi::pill('exists') : AdminUi::pill('new', 'info') ?></td>
            <td class="num"><?= $c['total'] === null ? '-' : number_format((int)$c['total']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </section>
    <?php endif; ?>
    <?php if (!empty($spec['field_groups'])): ?>
    <section class="card">
      <div class="card-head"><h2>Custom fields</h2></div>
      <div class="table-wrap"><table class="table table-compact">
        <thead><tr><th>Field group</th><th>Used on</th><th class="num">Fields</th></tr></thead>
        <tbody>
        <?php foreach ($spec['field_groups'] as $g): ?>
          <tr><td><strong><?= e((string)($g['title'] ?? 'Fields')) ?></strong><br><small class="muted"><?= e(implode(', ', array_slice(array_column((array)($g['fields'] ?? []), 'name'), 0, 8)) . (count((array)($g['fields'] ?? [])) > 8 ? '…' : '')) ?></small></td>
            <td><?= e(implode(', ', array_merge((array)($g['post_types'] ?? []), (array)($g['taxonomies'] ?? [])))) ?></td>
            <td class="num"><?= count((array)($g['fields'] ?? [])) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </section>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <section class="card">
    <div class="card-head"><h2>Options</h2></div>
    <div class="card-pad">
      <div class="choice-grid">
        <?php foreach ([
            'model' => 'Create post types, taxonomies and custom fields', 'media' => 'Import media (optimized to WebP)',
            'seo' => 'SEO titles, descriptions, robots and social tags', 'menus' => 'Menus', 'settings' => 'Site title, tagline, timezone and homepage',
            'redirects' => '301 redirects for any URL that changes', 'design' => 'Mirror the design (theme, page-builder styles, header, footer)',
            'activate_theme' => 'Switch to the mirrored theme when done', 'drafts' => 'Include drafts and private content',
        ] as $k => $label): ?>
          <label class="choice-card"><input type="checkbox" data-opt="<?= e($k) ?>"<?= ($opts[$k] ?? true) ? ' checked' : '' ?>><span><?= e($label) ?></span></label>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="card-foot row-between">
      <span class="muted small">About <?= number_format($total + (int)($sum['media'] ?? 0)) ?> items. Running it again later updates what was imported, it never duplicates.</span>
      <button type="button" class="btn btn-primary" data-start><?= icon('download', 16) ?> Start import</button>
    </div>
  </section>

<?php else: // run
    $stats = (array)($job['stats'] ?? []);
    $statLabel = static function (string $k) use ($typeLabel): string {
        return ['terms' => 'Categories & tags', 'media' => 'Media files', 'media_failed' => 'Media failed', 'menus' => 'Menus', 'archives' => 'Archive pages'][$k] ?? $typeLabel($k);
    };
    $origin = (string)($job['origin'] ?? '');
    ?>
  <section class="card">
    <div class="card-head row-between">
      <h2><?= $running ? 'Importing' : 'Import finished' ?></h2>
      <span data-label><?= $running ? AdminUi::pill(isset($_GET['go']) ? 'Starting…' : 'Paused', isset($_GET['go']) ? 'info' : 'warn') : AdminUi::pill('Finished ' . time_ago($job['finished_at']), 'success') ?></span>
    </div>
    <div class="card-pad stack">
      <?php if ($running): ?>
        <div class="stack-sm">
          <div class="row-between small"><span data-step-label>Ready to continue<?= $origin !== '' ? ', ' . e($origin) : '' ?></span><span class="num" data-pct></span></div>
          <div class="wpi-bar"><i data-bar style="width:0"></i></div>
          <span class="muted small">Large sites take a while. Keep this tab open, if you close it, come back and press Resume.</span>
        </div>
        <div class="type-tags" data-stats><?php foreach ($stats as $k => $v): ?><span class="pill"><?= e($statLabel((string)$k)) ?>: <?= (int)$v ?></span><?php endforeach; ?></div>
      <?php else: ?>
        <p style="margin:0">Imported from <strong><?= e($origin !== '' ? $origin : 'the export file') ?></strong>.</p>
        <?= $stats ? AdminUi::kpis(array_combine(array_map(static fn($k) => $statLabel((string)$k), array_keys($stats)), array_map(static fn($v) => number_format((int)$v), $stats))) : '<p class="muted">Nothing new was imported.</p>' ?>
        <div>
          <strong class="small-label">Check the result</strong>
          <div class="page-actions" style="margin-top:8px;justify-content:flex-start;flex-wrap:wrap">
            <a class="btn btn-primary" href="<?= e(url()) ?>" target="_blank" rel="noopener"><?= icon('external', 15) ?> View site</a>
            <?php foreach (array_unique(array_column($job['spec']['wp_map']['types'] ?? [], 'slug')) as $slug): if (!ContentModel::type((string)$slug) || empty($stats[$slug])) continue; ?>
              <a class="btn" href="<?= e(admin_url('content/' . $slug)) ?>"><?= e($typeLabel((string)$slug)) ?></a>
            <?php endforeach; ?>
            <?php if (!empty($stats['media'])): ?><a class="btn" href="<?= e(admin_url('media')) ?>">Media</a><?php endif; ?>
            <?php if (!empty($stats['menus'])): ?><a class="btn" href="<?= e(admin_url('settings/appearance/menus')) ?>">Menus</a><?php endif; ?>
            <?php if (!empty($job['opts']['redirects'])): ?><a class="btn" href="<?= e(admin_url('settings/seo/redirects')) ?>">Redirects</a><?php endif; ?>
            <a class="btn" href="<?= e($base . '?tab=items') ?>">Old and new addresses</a>
          </div>
        </div>
      <?php endif; ?>
      <details<?= $running ? ' open' : '' ?>>
        <summary class="muted" style="cursor:pointer">Activity log</summary>
        <pre class="wpi-log" data-log style="margin-top:10px"><?= e(implode("\n", array_slice($job['log'] ?? [], -40))) ?: 'Nothing logged yet.' ?></pre>
      </details>
    </div>
    <div class="card-foot row-between">
      <span class="muted small"><?= $running ? 'Started ' . e(time_ago($job['started_at'])) : 'Run it again any time to pick up changes on the old site.' ?></span>
      <div class="page-actions">
        <?php if ($running): ?>
          <button type="button" class="btn btn-primary" data-resume><?= icon('refresh', 15) ?> Resume</button>
        <?php else: ?>
          <?php if ($scan): ?><a class="btn" href="<?= e($base . '?step=review') ?>"><?= icon('refresh', 15) ?> Sync again</a><?php endif; ?>
          <a class="btn" href="<?= e($base . '?step=source') ?>">New migration</a>
        <?php endif; ?>
      </div>
    </div>
  </section>
<?php endif; ?>
</div>
<style>
.wpi-bar{height:8px;border-radius:99px;background:var(--surface-3);overflow:hidden}
.wpi-bar i{display:block;height:100%;background:var(--accent);border-radius:99px;transition:width .4s}
.wpi-log{max-height:300px;font-size:12px;line-height:1.6;margin:0;white-space:pre-wrap}
</style>
<script>
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

  // Step 1, source
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
    location.href = base + '?step=review';
  });

  // Step 2, review
  $('[data-start]')?.addEventListener('click', async (e) => {
    const options = { types: [...root.querySelectorAll('[data-type]:checked')].map((c) => c.dataset.type) };
    root.querySelectorAll('[data-opt]').forEach((c) => { options[c.dataset.opt] = c.checked; });
    if (!options.types.length) return toast('Select at least one content type', 'error');
    const btn = e.currentTarget;
    btn.classList.add('is-loading');
    const r = await post('/start', { options });
    if (!r.ok) { btn.classList.remove('is-loading'); return toast(r.error, 'error', 8000); }
    location.href = base + '?step=run&go=1';
  });

  // Step 3, run
  const labels = <?= json_encode(['terms' => 'Categories & tags', 'media' => 'Media files', 'media_failed' => 'Media failed', 'menus' => 'Menus', 'archives' => 'Archive pages']) ?>;
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
    if (running || !$('[data-bar]')) return;
    running = true;
    const btn = $('[data-resume]');
    if (btn) btn.hidden = true;
    let fails = 0;
    while (running) {
      const r = await post('/step');
      if (!r.ok) {
        if (++fails > 4) { toast(r.error || 'Import paused', 'error', 9000); running = false; if (btn) btn.hidden = false; $('[data-label]').innerHTML = '<span class="pill pill-warn">Paused</span>'; break; }
        await new Promise((res) => setTimeout(res, 2000 * fails));
        continue;
      }
      fails = 0;
      render(r);
      if (r.done) { running = false; toast('Import finished!'); location.href = base + '?step=run'; break; }
    }
  };
  $('[data-resume]')?.addEventListener('click', loop);
  if (new URLSearchParams(location.search).get('go') === '1') loop();
});
</script>
