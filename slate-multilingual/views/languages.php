<?php
/** Languages tab: configured languages, translatable content types, add a language, theme usage. */
use Slate\ContentModel;
use SlateModules\Multilingual\Admin;
use SlateModules\Multilingual\Languages;

$settings = Languages::settings();
$default = $settings['default'];
$langs = $settings['languages'];
$public = array_filter(ContentModel::types(), static fn($t) => !empty($t['public']));
$chosen = Languages::translatableTypes();
$base = Admin::url();
?>
<form method="post" action="<?= e(admin_url('m/' . Admin::SLUG . '/languages')) ?>" class="stack">
  <?= csrf_field() ?>
  <button type="submit" tabindex="-1" aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden">Save</button>
  <section class="card">
    <div class="card-head"><h2><?= icon('globe', 18) ?> Languages</h2><span class="muted small">The default language has no URL prefix</span></div>
    <div class="table-wrap"><table class="table">
      <thead><tr>
        <th>Default</th><th>Language</th><th>Native name</th><th>Code</th><th>URL prefix</th><th>Direction</th><th>Social locale</th><th>Enabled</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($langs as $code => $l): $isDefault = $code === $default; $n = 'langs[' . $code . ']'; ?>
        <tr>
          <td><input type="radio" name="default" value="<?= e($code) ?>"<?= $isDefault ? ' checked' : '' ?> aria-label="Make <?= e($l['name']) ?> the default language"<?= empty($l['enabled']) ? ' disabled' : '' ?>></td>
          <td><input class="input input-sm" name="<?= e($n) ?>[name]" value="<?= e($l['name']) ?>" maxlength="60" aria-label="Name"></td>
          <td><input class="input input-sm" name="<?= e($n) ?>[native]" value="<?= e($l['native']) ?>" maxlength="60" aria-label="Native name" dir="auto"></td>
          <td><code><?= e($code) ?></code></td>
          <td>
            <?php if ($isDefault): ?>
              <span class="muted small">none — <code><?= e(url('/')) ?></code></span>
              <input type="hidden" name="<?= e($n) ?>[prefix]" value="<?= e($l['prefix'] ?: $code) ?>">
            <?php else: ?>
              <input class="input input-sm" name="<?= e($n) ?>[prefix]" value="<?= e($l['prefix']) ?>" maxlength="32" style="max-width:120px" aria-label="URL prefix" spellcheck="false">
            <?php endif; ?>
          </td>
          <td>
            <select class="input input-sm" name="<?= e($n) ?>[dir]" aria-label="Text direction">
              <?php foreach (['auto' => 'Automatic (' . (in_array(explode('-', $code)[0], Languages::RTL, true) ? 'right to left' : 'left to right') . ')', 'ltr' => 'Left to right', 'rtl' => 'Right to left'] as $v => $label): ?>
                <option value="<?= e($v) ?>"<?= $l['dir'] === $v ? ' selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </td>
          <td><input class="input input-sm" name="<?= e($n) ?>[locale]" value="<?= e($l['locale']) ?>" placeholder="<?= e(Languages::locale($code)) ?>" maxlength="12" style="max-width:100px" aria-label="Open Graph locale" spellcheck="false"></td>
          <td>
            <?php if ($isDefault): ?>
              <span class="pill pill-success">Always</span>
            <?php else: ?>
              <label class="switch-row"><input type="checkbox" class="switch" name="<?= e($n) ?>[enabled]" value="1"<?= !empty($l['enabled']) ? ' checked' : '' ?> aria-label="<?= e($l['name']) ?> enabled"></label>
            <?php endif; ?>
          </td>
          <td class="nowrap">
            <?php if (!$isDefault): ?>
              <button class="btn btn-sm btn-ghost btn-danger" name="remove" value="<?= e($code) ?>"
                data-confirm="Remove <?= e($l['native']) ?>? Its content is kept but will count as <?= e(Languages::native($default)) ?> until you assign another language. To hide it from visitors, disable it instead.">Remove</button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <div class="card-pad">
      <p class="help" style="margin:0">Disabled languages are hidden from visitors (their pages answer “not found”, their items are left out of every list) while you prepare their content.
        Items without a language belong to the default language. Changing the default language moves those items to the new default.</p>
    </div>
  </section>

  <section class="card">
    <div class="card-head"><h2>Translatable content</h2><span class="muted small">Items of these types get a language and translations</span></div>
    <div class="card-pad">
      <input type="hidden" name="types_present" value="1">
      <div class="choice-grid">
        <?php foreach ($public as $slug => $t): ?>
          <label class="choice-card"><input type="checkbox" name="types[]" value="<?= e($slug) ?>"<?= in_array($slug, $chosen, true) ? ' checked' : '' ?>> <?= e($t['plural'] ?? $slug) ?></label>
        <?php endforeach; ?>
      </div>
      <p class="help">Other types are shared by every language: they are always listed and never get a prefix.</p>
    </div>
  </section>

  <div><button class="btn btn-primary"><?= icon('check', 16) ?> Save languages</button></div>
</form>

<form method="post" action="<?= e(admin_url('m/' . Admin::SLUG . '/languages/add')) ?>" class="card" style="margin-top:20px">
  <?= csrf_field() ?>
  <div class="card-head"><h2><?= icon('plus', 18) ?> Add a language</h2></div>
  <div class="card-pad field-grid">
    <div class="field" style="--w:34%">
      <label for="ml-preset">Language</label>
      <select class="input" id="ml-preset" name="preset">
        <option value="">Other (enter a code) …</option>
        <?php foreach (Languages::PRESETS as $code => $p): if (isset($langs[$code])) continue; ?>
          <option value="<?= e($code) ?>"><?= e($p[0] . ' — ' . $p[1] . ' (' . $code . ')') ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field" style="--w:16%">
      <label for="ml-code">Code</label>
      <input class="input" id="ml-code" name="code" placeholder="e.g. es, pt-br" maxlength="20" spellcheck="false">
    </div>
    <div class="field" style="--w:16%">
      <label for="ml-prefix">URL prefix</label>
      <input class="input" id="ml-prefix" name="prefix" placeholder="same as code" maxlength="32" spellcheck="false">
    </div>
    <div class="field" style="--w:17%">
      <label for="ml-name">Name</label>
      <input class="input" id="ml-name" name="name" placeholder="from the list" maxlength="60">
    </div>
    <div class="field" style="--w:17%">
      <label for="ml-native">Native name</label>
      <input class="input" id="ml-native" name="native" placeholder="from the list" maxlength="60" dir="auto">
    </div>
    <div class="field" style="--w:100%">
      <label class="switch-row"><input type="hidden" name="enabled" value="0"><input type="checkbox" class="switch" name="enabled" value="1" checked><span>Enable now <small class="muted">— visitors can open it at once; leave off while you translate</small></span></label>
    </div>
    <div class="field" style="--w:100%"><button class="btn btn-primary"><?= icon('plus', 16) ?> Add language</button></div>
  </div>
</form>

<section class="card" style="margin-top:20px">
  <div class="card-head"><h2><?= icon('code', 18) ?> In templates and themes</h2></div>
  <div class="card-pad stack-sm small">
    <div><code>{{languages}}</code> language switcher (<code>{{languages:code}}</code>, <code>{{languages:name}}</code>, <code>{{languages:both}}</code>) — links to the translations of the current page, or to each language's home page.</div>
    <div><code>{{lang}}</code> current language code · <code>{{lang:native}}</code> · <code>{{lang:name}}</code> · <code>{{lang:dir}}</code></div>
    <div><code>{{t:Read more}}</code> a fixed text in the visitor's language (see <a href="<?= e(Admin::url('strings')) ?>">String translations</a>).</div>
    <div>PHP themes: <code>&lt;?= ml_switcher(['show' =&gt; 'native', 'hide_current' =&gt; false, 'hide_missing' =&gt; false]) ?&gt;</code>,
      <code>ml_lang()</code>, <code>e(ml_t('Read more'))</code>, <code>ml_url('/contact/')</code>, <code>ml_translations()</code>.</div>
    <div class="muted">Style the switcher with the classes <code>.ml-switcher</code>, <code>.ml-item</code>, <code>.ml-current</code> and <code>.ml-missing</code>.</div>
  </div>
</section>
