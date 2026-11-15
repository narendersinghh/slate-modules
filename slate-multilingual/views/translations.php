<?php
/** Translations tab: counts per language, every item with its translations, create / link / unlink. */
use Slate\Auth;
use Slate\ContentModel;
use Slate\Posts;
use SlateModules\Multilingual\Admin;
use SlateModules\Multilingual\Languages;
use SlateModules\Multilingual\Translations;

$langs = Languages::all();
$default = Languages::default();
$types = Languages::translatableTypes();
$self = Admin::url('translations');
$current = $self . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '');
$actionBase = admin_url('m/' . Admin::SLUG);

if (count($langs) < 2): ?>
  <div class="card empty-state"><?= icon('globe', 32) ?><h2>Add a second language first</h2>
    <p class="muted"><?= Auth::can('manage_settings') ? 'Go to <a href="' . e(Admin::url('languages')) . '">Languages</a> to add the languages of your website.' : 'Ask an administrator to add the languages of your website.' ?></p></div>
<?php return; endif;

if (!$types): ?>
  <div class="card empty-state"><?= icon('globe', 32) ?><h2>No translatable content types</h2>
    <p class="muted">Choose which content can be translated under <a href="<?= e(Admin::url('languages')) ?>">Languages</a>.</p></div>
<?php return; endif;

$counts = Translations::counts();
$type = (string)($_GET['type'] ?? '');
if (!in_array($type, $types, true)) {
    $type = $types[0];
}
$typeDef = ContentModel::type($type) ?? ['plural' => $type, 'singular' => $type];
$missing = (string)($_GET['missing'] ?? '');
if ($missing !== '' && $missing !== 'any' && !isset($langs[$missing])) {
    $missing = '';
}
$q = trim((string)($_GET['q'] ?? ''));
$pg = max(1, (int)($_GET['pg'] ?? 1));
$per = 50;

$data = Translations::overview($type);
$rows = $data['rows'];
$enabledCodes = array_keys(Languages::enabled());
$rows = array_values(array_filter($rows, static function (array $row) use ($missing, $q, $enabledCodes): bool {
    if ($missing === 'any' && !array_diff($enabledCodes, array_keys($row))) {
        return false;
    }
    if ($missing !== '' && $missing !== 'any' && isset($row[$missing])) {
        return false;
    }
    if ($q !== '') {
        foreach ($row as $item) {
            if (mb_stripos($item['title'], $q) !== false || (ctype_digit($q) && (int)$q === $item['id'])) {
                return true;
            }
        }
        return false;
    }
    return true;
}));
$total = count($rows);
$pages = max(1, (int)ceil($total / $per));
$pg = min($pg, $pages);
$rows = array_slice($rows, ($pg - 1) * $per, $per);
$canOthers = Auth::can('edit_others_posts');
$me = (int)Auth::id();
$qs = static fn(array $set): string => Admin::url('translations', array_merge(['type' => $_GET['type'] ?? null, 'missing' => $_GET['missing'] ?? null, 'q' => $_GET['q'] ?? null], $set));
?>
<section class="card" style="margin-bottom:20px">
  <div class="card-head"><h2><?= icon('activity', 18) ?> Content per language</h2><span class="muted small">Every status except trash</span></div>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Content</th><?php foreach ($langs as $code => $l): ?><th class="center"><?= e($l['native']) ?><?= empty($l['enabled']) ? ' <span class="muted">(off)</span>' : '' ?></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php foreach ($counts as $t => $byLang): $def = ContentModel::type($t); $max = max($byLang ?: [0]); ?>
      <tr>
        <td><a href="<?= e($qs(['type' => $t, 'pg' => null, 'missing' => null, 'q' => null])) ?>"><?= e($def['plural'] ?? $t) ?></a></td>
        <?php foreach ($langs as $code => $l): $n = (int)($byLang[$code] ?? 0); ?>
          <td class="center">
            <?= number_format($n) ?>
            <?php if ($code !== $default && $n < (int)($byLang[$default] ?? 0)): ?>
              <br><a class="small muted" href="<?= e($qs(['type' => $t, 'missing' => $code, 'pg' => null, 'q' => null])) ?>"><?= number_format((int)$byLang[$default] - $n) ?> to translate</a>
            <?php endif; ?>
          </td>
        <?php endforeach; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>

<form class="toolbar" method="get" action="<?= e(admin_url('m/' . Admin::SLUG)) ?>">
  <input type="hidden" name="tab" value="translations">
  <span style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
    <select class="input input-sm" name="type" onchange="this.form.submit()" aria-label="Content type">
      <?php foreach ($types as $t): ?><option value="<?= e($t) ?>"<?= $t === $type ? ' selected' : '' ?>><?= e(ContentModel::type($t)['plural'] ?? $t) ?></option><?php endforeach; ?>
    </select>
    <select class="input input-sm" name="missing" onchange="this.form.submit()" aria-label="Missing translations">
      <option value="">All items</option>
      <option value="any"<?= $missing === 'any' ? ' selected' : '' ?>>Missing any language</option>
      <?php foreach ($langs as $code => $l): ?><option value="<?= e($code) ?>"<?= $missing === $code ? ' selected' : '' ?>>Missing <?= e($l['native']) ?></option><?php endforeach; ?>
    </select>
    <input class="input input-sm" type="search" name="q" value="<?= e($q) ?>" placeholder="Search titles or ID…" aria-label="Search">
    <button class="btn btn-sm">Filter</button>
  </span>
  <span class="muted small"><?= number_format($total) ?> <?= e(strtolower($total === 1 ? ($typeDef['singular'] ?? $type) : ($typeDef['plural'] ?? $type))) ?><?= $data['truncated'] ? ' (first 5,000 items)' : '' ?></span>
</form>

<?php if (!$rows): ?>
  <div class="card empty-state"><?= icon('check', 32) ?><h2>Nothing here</h2><p class="muted"><?= $missing !== '' ? 'Everything is translated.' : 'No items match.' ?></p></div>
<?php else: ?>
<form method="post" action="<?= e($actionBase . '/translate') ?>">
  <?= csrf_field() ?><input type="hidden" name="return" value="<?= e($current) ?>">
  <div class="card"><div class="table-wrap"><table class="table">
    <thead><tr><th><?= e($typeDef['singular'] ?? $type) ?></th><?php foreach ($langs as $code => $l): ?><th><?= e($l['native']) ?></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php foreach ($rows as $row):
        $source = $row[$default] ?? reset($row);
        $canSource = $canOthers || $source['author_id'] === $me; ?>
      <tr>
        <td><strong><?= e($source['title'] !== '' ? $source['title'] : '(no title)') ?></strong></td>
        <?php foreach ($langs as $code => $l): $item = $row[$code] ?? null; ?>
          <td class="nowrap">
            <?php if ($item): ?>
              <a href="<?= e(admin_url('content/' . $type . '/' . $item['id'])) ?>" title="<?= e($item['title']) ?>">#<?= (int)$item['id'] ?></a>
              <span class="status status-<?= e($item['status']) ?>"><?= e(Posts::STATUSES[$item['status']] ?? $item['status']) ?></span>
              <?php if (count($row) > 1 && ($canOthers || $item['author_id'] === $me)): ?>
                <button class="btn btn-sm btn-ghost" type="submit" formaction="<?= e($actionBase . '/unlink') ?>" name="id" value="<?= (int)$item['id'] ?>"
                  title="Unlink from its translations" aria-label="Unlink #<?= (int)$item['id'] ?>" data-confirm="Unlink #<?= (int)$item['id'] ?> from its translations? The item itself is kept."><?= icon('x', 13) ?></button>
              <?php endif; ?>
            <?php elseif ($canSource): ?>
              <button class="btn btn-sm" type="submit" name="pair" value="<?= (int)$source['id'] . ':' . e($code) ?>"><?= icon('plus', 13) ?> Create</button>
            <?php else: ?>
              <span class="muted">—</span>
            <?php endif; ?>
          </td>
        <?php endforeach; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div></div>
</form>
<?php if ($pages > 1): ?>
  <div class="row-between" style="margin-top:12px">
    <span class="muted small">Page <?= $pg ?> of <?= $pages ?></span>
    <span>
      <?php if ($pg > 1): ?><a class="btn btn-sm" href="<?= e($qs(['pg' => $pg - 1])) ?>">Previous</a><?php endif; ?>
      <?php if ($pg < $pages): ?><a class="btn btn-sm" href="<?= e($qs(['pg' => $pg + 1])) ?>">Next</a><?php endif; ?>
    </span>
  </div>
<?php endif; ?>
<?php endif; ?>

<form method="post" action="<?= e($actionBase . '/link') ?>" class="card" style="margin-top:20px">
  <?= csrf_field() ?><input type="hidden" name="return" value="<?= e($current) ?>">
  <div class="card-head"><h2><?= icon('link', 18) ?> Link existing items</h2><span class="muted small">For translations written before this module, or created by hand</span></div>
  <div class="card-pad" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
    <span>Item #</span><input class="input input-sm" type="number" name="id" min="1" required style="width:110px" aria-label="Item ID">
    <span>is a translation of #</span><input class="input input-sm" type="number" name="to" min="1" required style="width:110px" aria-label="Translated item ID">
    <button class="btn btn-sm btn-primary">Link</button>
    <p class="help" style="flex-basis:100%;margin:0">Set each item's language in its editor first (Language box in the sidebar). “Create” copies the item — content, excerpt, fields, terms and featured image — as a draft in the other language.</p>
  </div>
</form>
