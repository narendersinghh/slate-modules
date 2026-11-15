<?php
/** String translations tab: fixed texts used by templates and themes. */
use SlateModules\Multilingual\Admin;
use SlateModules\Multilingual\Languages;
use SlateModules\Multilingual\Strings;

$codes = Languages::extraCodes();
$strings = Strings::all();
$blank = max(3, 8 - count($strings));
$i = 0;
?>
<?php if (!$codes): ?>
  <div class="card empty-state"><?= icon('type', 32) ?><h2>Add a second language first</h2>
    <p class="muted">Strings are translated into the languages configured under <a href="<?= e(Admin::url('languages')) ?>">Languages</a>.</p></div>
<?php return; endif; ?>

<p class="muted" style="margin:-4px 0 16px">Texts written in your templates or theme, such as “Read more”, in every language.
  Use <code>{{t:Read more}}</code> in templates or <code>&lt;?= e(ml_t('Read more')) ?&gt;</code> in PHP themes. The text must match exactly; empty translations fall back to the original.</p>

<form method="post" action="<?= e(admin_url('m/' . Admin::SLUG . '/strings')) ?>">
  <?= csrf_field() ?>
  <div class="card"><div class="table-wrap"><table class="table">
    <thead><tr>
      <th><?= e(Languages::native(Languages::default())) ?> (original)</th>
      <?php foreach ($codes as $code): ?><th><?= e(Languages::native($code)) ?></th><?php endforeach; ?>
      <th class="center">Delete</th>
    </tr></thead>
    <tbody>
    <?php foreach ($strings as $source => $tr): ?>
      <tr>
        <td><input class="input input-sm" name="rows[<?= $i ?>][source]" value="<?= e($source) ?>" maxlength="1000" aria-label="Original text"></td>
        <?php foreach ($codes as $code): ?>
          <td><input class="input input-sm" name="rows[<?= $i ?>][tr][<?= e($code) ?>]" value="<?= e($tr[$code] ?? '') ?>" maxlength="1000"
            lang="<?= e(Languages::htmlLang($code)) ?>" dir="<?= e(Languages::dir($code)) ?>" aria-label="<?= e(Languages::name($code)) ?>"></td>
        <?php endforeach; ?>
        <td class="center"><input type="checkbox" name="rows[<?= $i ?>][delete]" value="1" aria-label="Delete this string"></td>
      </tr>
    <?php $i++; endforeach; ?>
    <?php for ($b = 0; $b < $blank; $b++, $i++): ?>
      <tr>
        <td><input class="input input-sm" name="rows[<?= $i ?>][source]" value="" maxlength="1000" placeholder="New text, e.g. Read more" aria-label="Original text"></td>
        <?php foreach ($codes as $code): ?>
          <td><input class="input input-sm" name="rows[<?= $i ?>][tr][<?= e($code) ?>]" value="" maxlength="1000"
            lang="<?= e(Languages::htmlLang($code)) ?>" dir="<?= e(Languages::dir($code)) ?>" aria-label="<?= e(Languages::name($code)) ?>"></td>
        <?php endforeach; ?>
        <td></td>
      </tr>
    <?php endfor; ?>
    </tbody>
  </table></div></div>
  <input type="hidden" name="complete" value="1">
  <div class="row-between" style="margin-top:12px">
    <span class="muted small"><?= number_format(count($strings)) ?> string<?= count($strings) === 1 ? '' : 's' ?> · save to get more empty rows</span>
    <button class="btn btn-primary"><?= icon('check', 16) ?> Save strings</button>
  </div>
</form>
