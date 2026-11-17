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
use SlateModules\WpImport\Builders;
use SlateModules\WpImport\Data;
use SlateModules\WpImport\Importer;

if (!function_exists('wpi_stat_label')) {
    function wpi_stat_label(string $k, callable $typeLabel): string
    {
        return ['terms' => 'Categories & tags', 'media' => 'Media files', 'media_failed' => 'Media failed', 'menus' => 'Menus', 'archives' => 'Archive pages'][$k] ?? $typeLabel($k);
    }
}

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

$asset = static function (string $f): string {
    $path = __DIR__ . '/assets/' . $f;
    return url('modules/wordpress-importer/assets/' . $f) . '?v=' . substr(md5((string)@filemtime($path)), 0, 8);
};
$step = (string)($_GET['step'] ?? '');
$step = $step === 'review' ? 'analyse' : $step; // 1.x links
if (!in_array($step, ['source', 'analyse', 'choose', 'run', 'report'], true)) {
    $step = $job ? (!empty($job['finished_at']) ? 'report' : 'run') : ($scan ? 'analyse' : 'source');
}
if (in_array($step, ['analyse', 'choose'], true) && !$scan) {
    $step = 'source';
}
if (in_array($step, ['run', 'report'], true) && !$job) {
    $step = $scan ? 'analyse' : 'source';
}
if ($step === 'report' && empty($job['finished_at'])) {
    $step = 'run';
}
$running = $job && !$job['finished_at'];
$ai = \Slate\Ai::enabled() && \Slate\Auth::can('use_ai');
$analysis = $scan['analysis'] ?? null;
$wizard = ['source' => 'Connect', 'analyse' => 'Analyse', 'choose' => 'Choose', 'run' => 'Import', 'report' => 'Report'];
$reach = ['source' => true, 'analyse' => (bool)$scan, 'choose' => (bool)$scan, 'run' => (bool)$job, 'report' => !empty($job['finished_at'])];
?>
<link rel="stylesheet" href="<?= e($asset('importer.css')) ?>">
<div class="stack wpi" data-wpi data-base="<?= e($base) ?>">
  <nav class="wizard-steps" aria-label="Steps">
    <?php $n = 0; foreach ($wizard as $k => $label): $n++; ?>
      <?php if ($reach[$k]): ?>
        <a href="<?= e($base . '?step=' . $k) ?>"<?= $k === $step ? ' class="active" aria-current="step"' : '' ?>><span><?= $n ?></span> <?= e($label) ?></a>
      <?php else: ?>
        <button type="button" disabled><span><?= $n ?></span> <?= e($label) ?></button>
      <?php endif; ?>
    <?php endforeach; ?>
  </nav>

<?php if ($step === 'source'): $src = ($conn['source'] ?? 'rest') === 'wxr' ? 'wxr' : 'rest'; ?>
  <?php if ($running): ?>
    <div class="alert">An import is paused. <a href="<?= e($base . '?step=run') ?>">Resume it</a> or connect a new source below.</div>
  <?php endif; ?>
  <section class="card">
    <div class="card-head"><h2>Where is the site now?</h2></div>
    <div class="card-pad stack">
      <div class="choice-grid">
        <label class="choice-card"><input type="radio" name="wpi_src" value="rest"<?= $src === 'rest' ? ' checked' : '' ?>><span>Live WordPress site <small class="muted">(recommended)</small><br><small class="muted">Reads everything, including drafts, menus, settings and the exact design of builder pages.</small></span></label>
        <label class="choice-card"><input type="radio" name="wpi_src" value="wxr"<?= $src === 'wxr' ? ' checked' : '' ?>><span>WordPress export file (.xml)<br><small class="muted">When you can't give admin access. Add a database dump on the next step to get settings, SEO and forms too.</small></span></label>
      </div>
      <div data-src-panel="rest" class="stack-sm"<?= $src === 'rest' ? '' : ' hidden' ?>>
        <div class="grid-form">
          <label class="field span-2"><span>Site address</span><input class="input" data-in="url" value="<?= e(($conn['source'] ?? '') === 'rest' ? ($conn['url'] ?? '') : '') ?>" placeholder="https://example.com"></label>
          <label class="field"><span>Administrator username</span><input class="input" data-in="user" value="<?= e($conn['user'] ?? '') ?>" autocomplete="off"></label>
          <label class="field"><span>Application password</span><input class="input" type="password" data-in="password" autocomplete="new-password" placeholder="xxxx xxxx xxxx xxxx xxxx xxxx"></label>
        </div>
        <small class="help">In WordPress go to <strong>Users › Profile › Application Passwords</strong>, add one named “Slate” and paste it here. Never use your login password. Without it only public content is read. The password is stored encrypted.</small>
        <div class="wpi-note">
          <?= icon('plug', 16) ?>
          <div><strong>Get everything with the Slate Export plugin</strong> <span class="muted small">(optional)</span><br>
            <span class="muted small">WordPress does not share custom field definitions, form settings, Elementor templates, SEO settings and plugin redirects through its normal API. Install this small read-only plugin on the old site (Plugins › Add New › Upload) and Slate reads them too. Remove it when you are done.</span><br>
            <a class="btn btn-sm" href="<?= e($base . '/companion.zip') ?>"><?= icon('download', 14) ?> Download slate-export.zip</a></div>
        </div>
      </div>
      <div data-src-panel="wxr" class="stack-sm"<?= $src === 'wxr' ? '' : ' hidden' ?>>
        <label class="field"><span>Export file</span><input class="input" type="file" accept=".xml" data-file></label>
        <small class="help">In WordPress: <strong>Tools › Export › All content</strong>. Keep the old site online during the import so media and page designs can be copied (or add a .zip of wp-content/uploads on the next step).</small>
      </div>
    </div>
    <div class="card-foot row-between">
      <span class="muted small">Nothing is imported yet: next you'll see what was found.</span>
      <button type="button" class="btn btn-primary" data-connect><?= icon('search', 15) ?> Analyse site</button>
    </div>
  </section>

<?php elseif ($step === 'analyse'):
    $sum = $scan['summary'];
    $srcs = (array)($analysis['sources'] ?? []);
    $dataSources = Data::sources();
    ?>
  <section class="card">
    <div class="card-head row-between"><h2>What we found</h2><a class="btn btn-ghost btn-sm" href="<?= e($base . '?step=source') ?>">Change source</a></div>
    <div class="card-pad">
      <div class="kv">
        <span>Site</span><strong><?= e((string)($sum['site'] ?: '-')) ?></strong>
        <span>Address</span><span><?= $sum['url'] ? '<a href="' . e((string)$sum['url']) . '" target="_blank" rel="noopener">' . e((string)$sum['url']) . '</a>' : '-' ?></span>
        <span>Access</span><span><?= ($conn['source'] ?? '') === 'wxr' ? 'Export file' : (!empty($sum['auth']) ? 'Signed in as ' . e((string)($sum['user'] ?? 'admin')) : AdminUi::pill('Public content only', 'warn') . ' <small class="muted">drafts, menus and settings need an application password</small>') ?></span>
        <span>Data sources</span><span class="wpi-tags">
          <?php foreach (['rest' => 'REST API', 'companion' => 'Slate Export plugin', 'wxr' => 'Export file', 'sql' => 'Database dump', 'uploads' => 'Uploads archive'] as $k => $label): if (!empty($srcs[$k])): ?>
            <?= AdminUi::pill($label, 'success') ?>
          <?php endif; endforeach; ?></span>
      </div>
    </div>
  </section>

  <?php if ($analysis): ?>
  <div class="wpi-cats">
    <?php foreach ($analysis['categories'] as $key => $c): ?>
      <section class="card wpi-cat">
        <div class="card-pad stack-sm">
          <div class="row-between"><span class="wpi-cat-title"><?= icon((string)$c['icon'], 16) ?> <?= e((string)$c['label']) ?></span>
            <strong class="wpi-count"><?= $c['found'] === null ? '' : number_format((int)$c['found']) ?></strong></div>
          <?php if (!empty($c['detail'])): ?><ul class="wpi-detail"><?php foreach (array_slice((array)$c['detail'], 0, 8) as $d): ?><li><?= e((string)$d) ?></li><?php endforeach; ?></ul><?php endif; ?>
          <p class="small" style="margin:0"><?= e((string)$c['will']) ?></p>
          <?php foreach ((array)($c['skip'] ?? []) as $sk): ?><p class="small muted wpi-skip"><?= icon('info', 13) ?> <?= e((string)$sk) ?></p><?php endforeach; ?>
        </div>
      </section>
    <?php endforeach; ?>
  </div>
  <?php if (!empty($analysis['never'])): ?>
    <p class="muted small"><?= icon('info', 13) ?> Not imported: <?= e(implode(' ', $analysis['never'])) ?></p>
  <?php endif; ?>
  <?php endif; ?>

  <section class="card">
    <div class="card-head"><h2>Add more data <span class="muted small">(optional)</span></h2></div>
    <div class="card-pad stack-sm">
      <p class="small muted" style="margin:0">A <strong>database dump</strong> (.sql or .sql.gz, e.g. from phpMyAdmin or your host's backups) adds custom field definitions, SEO settings, forms, templates, plugin redirects and menu locations. It is read, never run. A <strong>.zip of wp-content/uploads</strong> lets media come from the archive instead of the old site.</p>
      <div class="row wpi-extra">
        <input class="input" type="file" accept=".sql,.gz,.zip" data-extra-file>
        <button type="button" class="btn" data-extra><?= icon('upload', 15) ?> Add file</button>
      </div>
      <?php if (!empty($dataSources['sql'])): ?><small class="muted">Database dump read: <?= e(number_format(array_sum((array)($dataSources['sql']['rows'] ?? [])))) ?> rows of interest.</small><?php endif; ?>
      <?php if (!empty($dataSources['uploads'])): ?><small class="muted">Uploads archive: <?= e(number_format((int)($dataSources['uploads']['files'] ?? 0))) ?> files.</small><?php endif; ?>
      <?php if (($conn['source'] ?? '') === 'rest' && empty($srcs['companion'])): ?>
        <small class="muted">Or install the <a href="<?= e($base . '/companion.zip') ?>">Slate Export plugin</a> on the old site and analyse again.</small>
      <?php endif; ?>
    </div>
    <div class="card-foot row-between">
      <span class="muted small">Next, choose what to import.</span>
      <a class="btn btn-primary" href="<?= e($base . '?step=choose') ?>">Choose what to import <?= icon('chevron-right', 15) ?></a>
    </div>
  </section>

<?php elseif ($step === 'choose'):
    $sum = $scan['summary'];
    $spec = $scan['spec'] ?? [];
    // Pre-fill with the choices of the last full import (not a partial refresh); a first migration gets the 2.0 defaults.
    $last = empty($job['opts']['only']) ? ($job['opts'] ?? []) : [];
    $fresh = !$last;
    $defaults = ['model' => true, 'media' => true, 'seo' => true, 'menus' => true, 'settings' => true, 'redirects' => true, 'design' => true, 'activate_theme' => true,
        'drafts' => true, 'designed' => $fresh, 'clean' => $fresh, 'forms' => $fresh, 'templates' => $fresh, 'comments' => $fresh, 'design_system' => false,
        'seo_global' => $fresh, 'plugin_redirects' => $fresh, 'authors' => $fresh ? 'create' : 'keep'];
    $opts = $last + $defaults + ['types' => []];
    $specTypes = array_column($spec['post_types'] ?? [], 'plural', 'slug');
    $picked = array_intersect((array)$opts['types'], array_map('strval', array_keys($scan['counts']['types'] ?? [])));
    $total = 0;
    $cats = (array)($analysis['categories'] ?? []);
    $found = static fn(string $k) => isset($cats[$k]['found']) && $cats[$k]['found'] !== null ? ' (' . number_format((int)$cats[$k]['found']) . ')' : '';
    $groups = [
        'Content' => ['drafts' => 'Include drafts and private content', 'clean' => 'Turn block editor content into clean HTML (columns, galleries, buttons, embeds)',
            'designed' => 'Keep builder pages exactly as designed' . $found('builders') . ', editable by clicking'],
        'Structure' => ['model' => 'Create post types, taxonomies and custom fields' . $found('model'), 'menus' => 'Menus' . $found('menus'),
            'templates' => 'Templates and post lists, saved switched off for review' . $found('templates'), 'settings' => 'Site title, tagline, timezone and homepage'],
        'SEO and addresses' => ['seo' => 'SEO per page: titles, descriptions, focus keywords, robots, social', 'seo_global' => 'SEO settings: separator, title templates, noindex rules, social profiles',
            'redirects' => '301 redirects for every address that changes', 'plugin_redirects' => 'Redirects kept by SEO and redirect plugins' . $found('redirects')],
        'Media, forms and people' => ['media' => 'Media library (optimised to WebP)' . $found('media'), 'forms' => 'Forms, rebuilt in Slate Forms' . $found('forms'),
            'comments' => 'Comments' . $found('comments')],
        'Look of the rest of the site' => ['design' => 'Mirror the theme (header, footer, styles) for all other pages', 'activate_theme' => 'Switch to the mirrored theme when done',
            'design_system' => 'Copy the Elementor global colours and fonts into the design system'],
    ];
    ?>
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

  <section class="card">
    <div class="card-head"><h2>Options</h2></div>
    <div class="card-pad stack">
      <?php foreach ($groups as $title => $items): ?>
        <div class="stack-sm">
          <strong class="small-label"><?= e($title) ?></strong>
          <div class="choice-grid">
            <?php foreach ($items as $k => $label): ?>
              <label class="choice-card"><input type="checkbox" data-opt="<?= e($k) ?>"<?= !empty($opts[$k]) ? ' checked' : '' ?>><span><?= e($label) ?></span></label>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
      <label class="field wpi-authors"><span>Authors</span>
        <select class="input" data-opt-select="authors">
          <?php foreach (['create' => 'Match or create their accounts (suspended until you activate them)', 'me' => 'Make me the author of everything', 'keep' => 'Leave authors as they are'] as $v => $l): ?>
            <option value="<?= e($v) ?>"<?= ($opts['authors'] ?? 'keep') === $v ? ' selected' : '' ?>><?= e($l) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>
    <div class="card-foot row-between">
      <span class="muted small">About <?= number_format($total + (int)($sum['media'] ?? 0)) ?> items. Running it again later updates what was imported, it never duplicates, and keeps pages you edited in Slate.</span>
      <button type="button" class="btn btn-primary" data-start><?= icon('download', 16) ?> Start import</button>
    </div>
  </section>

<?php elseif ($step === 'run'):
    $stats = (array)($job['stats'] ?? []);
    $origin = (string)($job['origin'] ?? '');
    ?>
  <section class="card">
    <div class="card-head row-between">
      <h2><?= $running ? 'Importing' : 'Import finished' ?></h2>
      <span data-label><?= $running ? AdminUi::pill(isset($_GET['go']) ? 'Starting' : 'Paused', isset($_GET['go']) ? 'info' : 'warn') : AdminUi::pill('Finished ' . time_ago($job['finished_at']), 'success') ?></span>
    </div>
    <div class="card-pad stack">
      <div class="stack-sm">
        <div class="row-between small"><span data-step-label>Ready to continue<?= $origin !== '' ? ', ' . e($origin) : '' ?></span><span class="num" data-pct></span></div>
        <div class="wpi-bar"><i data-bar style="width:<?= $running ? 0 : 100 ?>%"></i></div>
        <span class="muted small">Large sites take a while. Keep this tab open; if you close it, come back and press Resume. Each step can be resumed.</span>
      </div>
      <div class="wpi-tags" data-stats><?php foreach ($stats as $k => $v): ?><span class="pill"><?= e(wpi_stat_label((string)$k, $typeLabel)) ?>: <?= (int)$v ?></span><?php endforeach; ?></div>
      <details open>
        <summary class="muted" style="cursor:pointer">Activity log</summary>
        <pre class="wpi-log" data-log style="margin-top:10px"><?= e(implode("\n", array_slice($job['log'] ?? [], -40))) ?: 'Nothing logged yet.' ?></pre>
      </details>
    </div>
    <div class="card-foot row-between">
      <span class="muted small"><?= $running ? 'Started ' . e(time_ago($job['started_at'])) : '' ?></span>
      <div class="page-actions">
        <?php if ($running): ?><button type="button" class="btn btn-primary" data-resume><?= icon('refresh', 15) ?> Resume</button>
        <?php else: ?><a class="btn btn-primary" href="<?= e($base . '?step=report') ?>">See the report</a><?php endif; ?>
      </div>
    </div>
  </section>

<?php else: // report
    $stats = (array)($job['stats'] ?? []);
    $origin = (string)($job['origin'] ?? '');
    $report = (array)($job['report'] ?? []);
    $catLabels = ['content' => ['Pages, posts and items', 'file-text'], 'designed' => ['Designed pages (builder pages)', 'columns'], 'builders' => ['Made with', 'columns'],
        'model' => ['Post types and custom fields', 'database'], 'fields' => ['Custom field values', 'database'], 'terms' => ['Categories, tags and terms', 'tag'],
        'media' => ['Media', 'image'], 'menus' => ['Menus', 'menu'], 'seo' => ['SEO', 'search'], 'redirects' => ['Redirects', 'link'], 'forms' => ['Forms', 'message'],
        'templates' => ['Templates', 'layers'], 'comments' => ['Comments', 'message'], 'users' => ['Authors', 'users'], 'design' => ['Design', 'palette']];
    $countLabel = static function (string $k): string {
        return ['imported' => 'imported', 'created' => 'created', 'updated' => 'updated', 'kept' => 'kept your edits', 'kept_edits' => 'kept your edits', 'skipped' => 'skipped',
            'failed' => 'failed', 'designed' => 'kept with their design', 'cleaned' => 'converted to clean HTML', 'rebuilt_offline' => 'rebuilt without design (site offline)',
            'skipped_drafts' => 'drafts skipped', 'sticky' => 'sticky', 'relinked' => 'links updated', 'from_archive' => 'from the uploads archive', 'no_alt' => 'images without alt text',
            'items' => 'items', 'focus_keywords' => 'focus keywords', 'settings' => 'settings', 'changed_urls' => 'for changed addresses', 'from_rank_math' => 'from Rank Math',
            'from_redirection' => 'from Redirection', 'from_yoast_premium' => 'from Yoast Premium', 'plugin_skipped' => 'duplicates skipped', 'embeds_replaced' => 'embeds replaced',
            'definitions' => 'definitions', 'acf_groups' => 'ACF field groups', 'items_with_acf' => 'items with ACF values', 'mapped' => 'already linked', 'matched' => 'matched to existing accounts',
            'already' => 'already imported', 'design_system' => 'design system updated', 'skipped_header' => 'headers (kept in designed pages)', 'skipped_footer' => 'footers (kept in designed pages)',
            'skipped_popup' => 'popups (kept in designed pages)'][$k] ?? (Builders::LABELS[$k] ?? str_replace('_', ' ', $k));
    };
    $structure = (array)($report['structure'] ?? []);
    unset($report['structure']);
    ?>
  <section class="card">
    <div class="card-head row-between">
      <h2>Migration report</h2>
      <span><?= AdminUi::pill('Finished ' . time_ago($job['finished_at']), 'success') ?></span>
    </div>
    <div class="card-pad stack">
      <p style="margin:0">Imported from <strong><?= e($origin !== '' ? $origin : 'the export file') ?></strong>.</p>
      <?= $stats ? AdminUi::kpis(array_combine(array_map(static fn($k) => wpi_stat_label((string)$k, $typeLabel), array_keys($stats)), array_map(static fn($v) => number_format((int)$v), $stats))) : '<p class="muted">Nothing new was imported.</p>' ?>
      <div class="page-actions" style="justify-content:flex-start;flex-wrap:wrap">
        <a class="btn btn-primary" href="<?= e(url()) ?>" target="_blank" rel="noopener"><?= icon('external', 15) ?> View site</a>
        <?php foreach (array_unique(array_column($job['spec']['wp_map']['types'] ?? [], 'slug')) as $slug): if (!ContentModel::type((string)$slug) || empty($stats[$slug])) continue; ?>
          <a class="btn" href="<?= e(admin_url('content/' . $slug)) ?>"><?= e($typeLabel((string)$slug)) ?></a>
        <?php endforeach; ?>
        <?php if (!empty($stats['media'])): ?><a class="btn" href="<?= e(admin_url('media')) ?>">Media</a><?php endif; ?>
        <?php if (!empty($stats['menus'])): ?><a class="btn" href="<?= e(admin_url('design/menus')) ?>">Menus</a><?php endif; ?>
        <?php if (!empty($job['opts']['redirects'])): ?><a class="btn" href="<?= e(admin_url('seo/redirects')) ?>">Redirects</a><?php endif; ?>
        <?php if (!empty($report['templates'])): ?><a class="btn" href="<?= e(admin_url('templates')) ?>">Templates</a><?php endif; ?>
        <a class="btn" href="<?= e($base . '?tab=items') ?>">Old and new addresses</a>
      </div>
      <?php if ($structure): ?>
        <p class="small muted" style="margin:0"><?= icon('link', 13) ?> Post addresses: WordPress <code><?= e((string)($structure['wordpress'] ?? '?')) ?></code>, Slate <code><?= e((string)($structure['permalink'] ?? '?')) ?></code>. Every address that changed redirects to its new one.</p>
      <?php endif; ?>
    </div>
  </section>

  <?php foreach ($catLabels as $cat => [$label, $ic]): if (empty($report[$cat])) continue; $r = $report[$cat]; ?>
    <section class="card wpi-report">
      <div class="card-head row-between"><h2><?= icon($ic, 16) ?> <?= e($label) ?></h2>
        <span class="wpi-tags"><?php foreach ((array)($r['counts'] ?? []) as $k => $v): ?><span class="pill<?= in_array($k, ['failed', 'skipped', 'rebuilt_offline', 'no_alt'], true) ? ' pill-warn' : '' ?>"><?= number_format((int)$v) ?> <?= e($countLabel((string)$k)) ?></span><?php endforeach; ?></span>
      </div>
      <?php if (!empty($r['warnings'])): ?>
        <div class="card-pad stack-sm"><?php foreach ($r['warnings'] as $w): ?><p class="small wpi-skip" style="margin:0"><?= icon('alert', 13) ?> <?= e((string)$w) ?></p><?php endforeach; ?></div>
      <?php endif; ?>
      <?php if (!empty($r['items'])): ?>
        <div class="table-wrap"><table class="table table-compact">
          <tbody>
          <?php foreach (array_slice($r['items'], 0, 60) as $it): ?>
            <tr>
              <td><strong><?= e((string)$it['title']) ?></strong><br><small class="muted"><?= e((string)$it['note']) ?></small></td>
              <td class="wpi-actions">
                <?php if ($it['url'] !== ''): ?><a class="btn btn-sm btn-ghost" href="<?= e((string)$it['url']) ?>" target="_blank" rel="noopener"><?= icon('eye', 14) ?> View</a><?php endif; ?>
                <?php if ($it['edit'] !== ''): ?><a class="btn btn-sm btn-ghost" href="<?= e((string)$it['edit']) ?>"><?= icon('edit', 14) ?> Edit</a><?php endif; ?>
                <?php if ($ai && ($it['ai'] ?? '') !== ''): ?><button class="btn btn-sm" type="button" data-goraiya-ask="<?= e((string)$it['ai']) ?>"><?= icon('sparrow', 14) ?> Improve with Goraiya</button><?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
        <?php if (count($r['items']) > 60): ?><p class="card-pad muted small" style="margin:0">And <?= count($r['items']) - 60 ?> more.</p><?php endif; ?>
      <?php endif; ?>
    </section>
  <?php endforeach; ?>

  <section class="card">
    <div class="card-pad row-between">
      <span class="muted small"><?= $ai ? 'The import never needs AI. Goraiya can review a page and improve it; every change is kept as a revision.' : 'The import never needs AI. Connect one (Goraiya, in the top bar) if you want imported pages reviewed and improved automatically.' ?></span>
      <div class="page-actions">
        <?php if ($scan): ?><a class="btn" href="<?= e($base . '?step=choose') ?>"><?= icon('refresh', 15) ?> Sync again</a><?php endif; ?>
        <a class="btn" href="<?= e($base . '?step=source') ?>">New migration</a>
        <details class="wpi-log-box"><summary class="btn btn-ghost btn-sm">Activity log</summary><pre class="wpi-log"><?= e(implode("\n", array_slice($job['log'] ?? [], -120))) ?></pre></details>
      </div>
    </div>
  </section>
<?php endif; ?>
</div>
<script>window.WPI_LABELS = <?= json_encode(['terms' => 'Categories & tags', 'media' => 'Media files', 'media_failed' => 'Media failed', 'menus' => 'Menus', 'archives' => 'Archive pages'], JSON_HEX_TAG) ?>;</script>
<script src="<?= e($asset('importer.js')) ?>" defer></script>
