<?php
/** Languages: the website's languages, add, order, choose the default, switch on/off. */
use SlateModules\Multilingual\Admin;
use SlateModules\Multilingual\Languages;

echo Admin::nav('languages');

$settings = Languages::settings();
$default = $settings['default'];
$langs = $settings['languages'];
$codes = array_keys($langs);
?>
<form method="post" action="<?= e(admin_url('m/' . Admin::SLUG . '/languages')) ?>" class="stack">
  <?= csrf_field() ?>
  <button type="submit" tabindex="-1" aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden">Save</button>
  <section class="card">
    <div class="card-head"><h2>Your languages</h2><span class="muted small">The default language keeps today's addresses (no prefix)</span></div>
    <div class="table-wrap"><table class="table">
      <thead><tr>
        <th>Language</th><th>Address</th><th>Visible to visitors</th><th>Default</th><th class="num">Order</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($langs as $code => $l): $isDefault = $code === $default; $n = 'langs[' . $code . ']'; $pos = array_search($code, $codes, true); ?>
        <tr>
          <td>
            <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
              <input class="input input-sm" name="<?= e($n) ?>[name]" value="<?= e($l['name']) ?>" maxlength="60" aria-label="Name in English" title="Name in English" style="max-width:170px">
              <input class="input input-sm" name="<?= e($n) ?>[native]" value="<?= e($l['native']) ?>" maxlength="60" aria-label="Native name" title="Name in the language itself (shown in the switcher)" dir="auto" style="max-width:170px">
              <code class="muted"><?= e($code) ?></code>
            </div>
            <details style="margin-top:6px"><summary class="small muted" style="cursor:pointer">Advanced</summary>
              <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:6px">
                <label class="small">Text direction
                  <select class="input input-sm" name="<?= e($n) ?>[dir]">
                    <?php foreach (['auto' => 'Automatic (' . (in_array(explode('-', $code)[0], Languages::RTL, true) ? 'right to left' : 'left to right') . ')', 'ltr' => 'Left to right', 'rtl' => 'Right to left'] as $v => $label): ?>
                      <option value="<?= e($v) ?>"<?= $l['dir'] === $v ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                  </select></label>
                <label class="small">Social locale
                  <input class="input input-sm" name="<?= e($n) ?>[locale]" value="<?= e($l['locale']) ?>" placeholder="<?= e(Languages::locale($code)) ?>" maxlength="12" style="max-width:100px" spellcheck="false" title="Open Graph locale for shared links, e.g. fr_FR"></label>
              </div>
            </details>
          </td>
          <td class="nowrap">
            <?php if ($isDefault): ?>
              <code class="muted"><?= e(url('/')) ?></code>
              <input type="hidden" name="<?= e($n) ?>[prefix]" value="<?= e($l['prefix'] ?: $code) ?>">
            <?php else: ?>
              <span class="muted">/</span><input class="input input-sm" name="<?= e($n) ?>[prefix]" value="<?= e($l['prefix']) ?>" maxlength="32" style="max-width:90px" aria-label="URL prefix of <?= e($l['name']) ?>" spellcheck="false"><span class="muted">/</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($isDefault): ?>
              <?= \Slate\AdminUi::pill('Always', 'success') ?>
            <?php else: ?>
              <label class="switch-row"><input type="checkbox" class="switch" name="<?= e($n) ?>[enabled]" value="1"<?= !empty($l['enabled']) ? ' checked' : '' ?> aria-label="<?= e($l['name']) ?> visible to visitors"></label>
            <?php endif; ?>
          </td>
          <td><input type="radio" name="default" value="<?= e($code) ?>"<?= $isDefault ? ' checked' : '' ?> aria-label="Make <?= e($l['name']) ?> the default language"<?= empty($l['enabled']) ? ' disabled title="Switch it on first"' : '' ?>></td>
          <td class="num nowrap">
            <?php if (!$isDefault): ?>
              <button class="btn btn-sm btn-ghost" name="move" value="<?= e($code) ?>:up"<?= $pos <= 1 ? ' disabled' : '' ?> aria-label="Move <?= e($l['name']) ?> up" title="Move up"><?= icon('arrow-up', 14) ?></button>
              <button class="btn btn-sm btn-ghost" name="move" value="<?= e($code) ?>:down"<?= $pos >= count($codes) - 1 ? ' disabled' : '' ?> aria-label="Move <?= e($l['name']) ?> down" title="Move down"><?= icon('arrow-down', 14) ?></button>
            <?php endif; ?>
          </td>
          <td class="nowrap">
            <?php if (!$isDefault): ?>
              <button class="btn btn-sm btn-ghost btn-danger" name="remove" value="<?= e($code) ?>"
                data-confirm="Remove <?= e($l['native']) ?>? Its content is kept but will count as <?= e(Languages::native($default)) ?> until you assign another language. To hide it from visitors, switch it off instead.">Remove</button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <div class="card-foot row-between">
      <span class="help" style="margin:0">Switched-off languages are hidden from visitors while you prepare their content. The order is the order of the language switcher.</span>
      <button class="btn btn-primary"><?= icon('check', 16) ?> Save languages</button>
    </div>
  </section>
</form>

<form method="post" action="<?= e(admin_url('m/' . Admin::SLUG . '/languages/add')) ?>" class="card" style="margin-top:20px">
  <?= csrf_field() ?>
  <div class="card-head"><h2><?= icon('plus', 18) ?> Add a language</h2></div>
  <div class="card-pad field-grid">
    <div class="field" style="--w:50%">
      <label for="ml-preset">Language</label>
      <select class="input" id="ml-preset" name="preset">
        <option value="">Choose…</option>
        <?php foreach (Languages::PRESETS as $code => $p): if (isset($langs[$code])) continue; ?>
          <option value="<?= e($code) ?>"><?= e($p[0] . ($p[1] !== $p[0] ? ', ' . $p[1] : '')) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field" style="--w:50%">
      <label class="switch-row" style="margin-top:26px"><input type="hidden" name="enabled" value="0"><input type="checkbox" class="switch" name="enabled" value="1"><span>Visible to visitors now <small class="muted">leave off while you translate</small></span></label>
    </div>
    <details class="field" style="--w:100%">
      <summary class="small muted" style="cursor:pointer">Not in the list, or a custom address?</summary>
      <div class="field-grid" style="margin-top:10px">
        <div class="field" style="--w:25%"><label for="ml-code">Code</label><input class="input" id="ml-code" name="code" placeholder="e.g. es, pt-br" maxlength="20" spellcheck="false"></div>
        <div class="field" style="--w:25%"><label for="ml-prefix">URL prefix</label><input class="input" id="ml-prefix" name="prefix" placeholder="same as code" maxlength="32" spellcheck="false"></div>
        <div class="field" style="--w:25%"><label for="ml-name">Name</label><input class="input" id="ml-name" name="name" placeholder="from the list" maxlength="60"></div>
        <div class="field" style="--w:25%"><label for="ml-native">Native name</label><input class="input" id="ml-native" name="native" placeholder="from the list" maxlength="60" dir="auto"></div>
      </div>
    </details>
    <div class="field" style="--w:100%"><button class="btn btn-primary"><?= icon('plus', 16) ?> Add language</button></div>
  </div>
</form>
