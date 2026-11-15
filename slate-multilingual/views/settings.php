<?php
/** Settings: which content types are translatable, and how to use languages in templates and themes. */
use Slate\ContentModel;
use SlateModules\Multilingual\Admin;
use SlateModules\Multilingual\Languages;

echo Admin::nav('settings');

$public = array_filter(ContentModel::types(), static fn($t) => !empty($t['public']));
$chosen = Languages::translatableTypes();
?>
<form method="post" action="<?= e(admin_url('m/' . Admin::SLUG . '/types')) ?>" class="card">
  <?= csrf_field() ?>
  <div class="card-head"><h2>Translatable content</h2></div>
  <div class="card-pad">
    <p class="muted" style="margin-top:0">Items of these types get a language and can be translated. Other types are shared by every language: they are always listed and their addresses never get a prefix.</p>
    <div class="choice-grid">
      <?php foreach ($public as $slug => $t): ?>
        <label class="choice-card"><input type="checkbox" name="types[]" value="<?= e($slug) ?>"<?= in_array($slug, $chosen, true) ? ' checked' : '' ?>> <?= e($t['plural'] ?? $slug) ?></label>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="card-foot"><button class="btn btn-primary"><?= icon('check', 16) ?> Save settings</button></div>
</form>

<section class="card" style="margin-top:20px">
  <div class="card-head"><h2><?= icon('code', 18) ?> In templates and themes</h2></div>
  <div class="card-pad">
    <div class="kv small">
      <span><code>{{languages}}</code></span><span>Language switcher: links to the translations of the current page, or to each language's home page. Variants: <code>{{languages:code}}</code>, <code>{{languages:name}}</code>, <code>{{languages:both}}</code>.</span>
      <span><code>{{lang}}</code></span><span>Current language code. Also <code>{{lang:native}}</code>, <code>{{lang:name}}</code>, <code>{{lang:dir}}</code>.</span>
      <span><code>{{t:Read more}}</code></span><span>A fixed text in the visitor's language (see <a href="<?= e(Admin::url('strings')) ?>">Strings</a>).</span>
      <span>PHP themes</span><span><code>&lt;?= ml_switcher(['show' =&gt; 'native', 'hide_current' =&gt; false, 'hide_missing' =&gt; false]) ?&gt;</code>, <code>ml_lang()</code>, <code>e(ml_t('Read more'))</code>, <code>ml_url('/contact/')</code>, <code>ml_languages()</code>, <code>ml_translations()</code>.</span>
      <span>Styling</span><span>The switcher uses the classes <code>.ml-switcher</code>, <code>.ml-item</code>, <code>.ml-current</code> and <code>.ml-missing</code>.</span>
    </div>
  </div>
</section>
