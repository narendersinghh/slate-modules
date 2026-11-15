<?php
/** Strings: fixed texts of templates and themes ("Read more"…) in every language. */
use Slate\AdminUi;
use Slate\ListTable;
use SlateModules\Multilingual\Admin;
use SlateModules\Multilingual\Languages;
use SlateModules\Multilingual\Strings;

$codes = Languages::extraCodes();
if (!$codes) {
    echo Admin::nav('strings'), AdminUi::emptyState('Add a second language first', 'Strings are translated into the other languages of your website.', 'type', ['Add a language' => Admin::url('languages')]);
    return;
}

$all = [];
foreach (Strings::all() as $source => $tr) {
    $source = (string)$source;
    $all[] = ['id' => Strings::key($source), 'source' => $source, 'tr' => $tr, 'missing' => count(array_diff($codes, array_keys($tr)))];
}
$todo = count(array_filter($all, static fn($r) => $r['missing'] > 0));
$formId = 'ml-strings-form';

$columns = ['source' => ['label' => Languages::native(Languages::default()) . ' (original)', 'primary' => true, 'sort' => 'source',
    'render' => static fn(array $r): string => '<span dir="auto">' . nl2br(e($r['source'])) . '</span>', 'export' => static fn(array $r) => $r['source']]];
foreach ($codes as $code) {
    $columns['tr_' . $code] = ['label' => Languages::native($code),
        'render' => static fn(array $r): string => '<input class="input input-sm" form="' . $formId . '" name="tr[' . e($r['id']) . '][' . e($code) . ']" value="' . e($r['tr'][$code] ?? '') . '"'
            . ' maxlength="1000" lang="' . e(Languages::htmlLang($code)) . '" dir="' . e(Languages::dir($code)) . '" aria-label="' . e(Languages::name($code)) . '" placeholder="Same as the original">',
        'export' => static fn(array $r) => $r['tr'][$code] ?? ''];
}

$t = new ListTable('multilingual.strings', $columns, [
    'base' => Admin::url('strings'),
    'search' => 'Search strings…',
    'views' => ['all' => ['All', count($all)], 'todo' => ['Needs translation', $todo]],
    'bulk' => ['delete' => ['label' => 'Delete', 'danger' => true, 'confirm' => 'Delete the selected strings and their translations?']],
    'bulk_action' => Admin::url('strings'),
    'sort' => 'source', 'dir' => 'asc', 'export' => true, 'per_page' => 50,
    'empty' => ['icon' => 'type', 'title' => 'No strings yet', 'text' => 'Add the texts your templates use, such as “Read more”, with the form above.'],
]);
$s = $t->state();
$rows = array_values(array_filter($all, static function (array $r) use ($s): bool {
    if ($s['view'] === 'todo' && !$r['missing']) {
        return false;
    }
    if ($s['q'] === '') {
        return true;
    }
    foreach (array_merge([$r['source']], array_values($r['tr'])) as $text) {
        if (mb_stripos($text, $s['q']) !== false) {
            return true;
        }
    }
    return false;
}));
if ($s['dir'] === 'desc') {
    $rows = array_reverse($rows); // stored sorted A→Z
}
if ($t->exporting()) {
    $t->exportCsv($rows);
}
echo Admin::nav('strings');
$page = array_slice($rows, $s['offset'], $s['per']);
$return = Admin::url('strings') . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '');
?>
<p class="muted" style="margin:0 0 16px">Texts written in your templates or theme, such as “Read more”. Use <code>{{t:Read more}}</code> in templates
  or <code>&lt;?= e(ml_t('Read more')) ?&gt;</code> in PHP themes. The original must match exactly; an empty translation shows the original.</p>

<form method="post" action="<?= e(admin_url('m/' . Admin::SLUG . '/strings/add')) ?>" class="card" style="margin-bottom:20px">
  <?= csrf_field() ?>
  <div class="card-head"><h2><?= icon('plus', 18) ?> Add a string</h2></div>
  <div class="card-pad field-grid">
    <div class="field" style="--w:<?= count($codes) > 2 ? '100' : '34' ?>%">
      <label for="ml-src">Original text</label>
      <input class="input" id="ml-src" name="source" maxlength="1000" required placeholder="e.g. Read more">
    </div>
    <?php foreach ($codes as $code): ?>
      <div class="field" style="--w:33%">
        <label for="ml-new-<?= e($code) ?>"><?= e(Languages::native($code)) ?></label>
        <input class="input" id="ml-new-<?= e($code) ?>" name="tr[<?= e($code) ?>]" maxlength="1000" lang="<?= e(Languages::htmlLang($code)) ?>" dir="<?= e(Languages::dir($code)) ?>">
      </div>
    <?php endforeach; ?>
    <div class="field" style="--w:100%"><button class="btn"><?= icon('plus', 16) ?> Add string</button></div>
  </div>
</form>

<?= $t->render($page, count($rows)) ?>

<?php if ($page): ?>
<form id="<?= e($formId) ?>" method="post" action="<?= e(admin_url('m/' . Admin::SLUG . '/strings')) ?>" class="row-between" style="margin-top:12px">
  <?= csrf_field() ?>
  <input type="hidden" name="return" value="<?= e($return) ?>">
  <input type="hidden" name="complete" value="1">
  <span class="muted small">Saves the translations of the strings on this page.</span>
  <button class="btn btn-primary"><?= icon('check', 16) ?> Save translations</button>
</form>
<?php endif; ?>
