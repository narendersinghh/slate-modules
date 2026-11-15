<?php
/** Overview: how far the translation of each content type has got, per language. */
use Slate\AdminUi;
use Slate\Auth;
use Slate\ContentModel;
use SlateModules\Multilingual\Admin;
use SlateModules\Multilingual\Languages;
use SlateModules\Multilingual\Strings;

$langs = Languages::all();
$default = Languages::default();
$extra = Languages::extraCodes();
$admin = Auth::can('manage_settings');

echo Admin::nav('overview');

if (!$extra) {
    echo AdminUi::emptyState('Your website has one language',
        'Add a second language to start translating. Until then nothing changes on your website: no language prefixes, no switcher, same addresses.',
        'globe', $admin ? ['Add a language' => Admin::url('languages')] : []);
    return;
}

$coverage = Admin::coverage();
$items = array_sum(array_column($coverage, 'items'));
$needed = $items * count($extra);
$done = 0;
foreach ($coverage as $c) {
    foreach ($extra as $code) {
        $done += $c['langs'][$code] ?? 0;
    }
}
$strings = Strings::all();
$stringsDone = 0;
foreach ($strings as $tr) {
    $stringsDone += count(array_intersect_key($tr, array_flip($extra)));
}
$pct = static fn(int $n, int $of): int => $of > 0 ? (int)floor($n * 100 / $of) : 100;
?>
<?php if (!Languages::isActive()): ?>
  <div class="banner banner-accent"><span class="ic"><?= icon('info', 18) ?></span>
    <div>Visitors see one language only: <?= e(implode(', ', array_map([Languages::class, 'native'], $extra))) ?> <?= count($extra) === 1 ? 'is' : 'are' ?> switched off.
    Prepare the translations, then switch the language on under <?= $admin ? '<a href="' . e(Admin::url('languages')) . '">Languages</a>' : 'Languages' ?>.</div></div>
<?php endif; ?>

<?= AdminUi::kpis([
    'Languages' => count(Languages::enabled()) . ' live' . (count($langs) > count(Languages::enabled()) ? ' · ' . (count($langs) - count(Languages::enabled())) . ' off' : ''),
    'Translatable items' => number_format($items),
    'Translated' => $pct($done, $needed) . '%',
    'Still to translate' => number_format(max(0, $needed - $done)),
    'Strings translated' => $strings ? $pct($stringsDone, count($strings) * count($extra)) . '%' : '-',
]) ?>

<section class="card">
  <div class="card-head"><h2>Progress by content type</h2><span class="muted small">Every status except trash · <?= e(Languages::native($default)) ?> is the original language</span></div>
  <?php if (!$coverage): ?>
    <div class="card-pad muted">No content type is translatable. <?= $admin ? 'Choose them under <a href="' . e(Admin::url('settings')) . '">Settings</a>.' : '' ?></div>
  <?php else: ?>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Content</th><th class="num">Items</th>
      <?php foreach ($extra as $code): ?><th><?= e($langs[$code]['native']) ?><?= empty($langs[$code]['enabled']) ? ' <span class="muted small">(off)</span>' : '' ?></th><?php endforeach; ?>
    </tr></thead>
    <tbody>
    <?php foreach ($coverage as $type => $c): $def = ContentModel::type($type); ?>
      <tr>
        <td><a href="<?= e(Admin::url('translations', ['f' => ['type' => $type]])) ?>"><strong><?= e($def['plural'] ?? $type) ?></strong></a></td>
        <td class="num"><?= number_format($c['items']) ?></td>
        <?php foreach ($extra as $code): $n = (int)($c['langs'][$code] ?? 0); $p = $pct($n, $c['items']); ?>
          <td style="min-width:150px">
            <progress class="mlx-bar" max="100" value="<?= $p ?>" aria-label="<?= e($langs[$code]['native']) ?>: <?= $p ?>%"></progress>
            <div class="small"><?= $p ?>% <span class="muted">· <?= number_format($n) ?> of <?= number_format($c['items']) ?></span>
              <?php if ($n < $c['items']): ?> · <a href="<?= e(Admin::url('translations', ['f' => ['type' => $type, 'missing' => $code]])) ?>"><?= number_format($c['items'] - $n) ?> to do</a><?php endif; ?>
            </div>
          </td>
        <?php endforeach; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>

<section class="card" style="margin-top:20px">
  <div class="card-head"><h2>How translating works</h2></div>
  <div class="card-pad">
    <ol class="steps">
      <li>Open <a href="<?= e(Admin::url('translations')) ?>">Translations</a> and click <strong>Translate</strong> next to an item, under the language you want.</li>
      <li>A copy of the item opens as a draft in that language. Replace the text with the translation and publish it.</li>
      <li>Visitors reach it at its language address (for example <code><?= e(url('/' . Languages::prefix(array_values(array_intersect($extra, array_keys(Languages::enabled())))[0] ?? $extra[0]) . '/')) ?></code>) and can switch between languages.</li>
    </ol>
  </div>
</section>
<style>.mlx-bar{width:100%;height:8px;accent-color:var(--accent);vertical-align:middle}</style>
