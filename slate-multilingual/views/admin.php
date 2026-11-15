<?php
/**
 * Multilingual admin screen: tab navigation, then the tab's view.
 * @var array $tabs
 * @var string $tab
 * @var callable $view
 */
use SlateModules\Multilingual\Admin;
use SlateModules\Multilingual\Languages;

$enabled = Languages::enabled();
?>
<div class="toolbar">
  <nav class="tabs-pill" aria-label="Multilingual sections">
    <?php foreach ($tabs as $k => $label): ?>
      <a href="<?= e(Admin::url($k)) ?>" class="<?= $tab === $k ? 'active' : '' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
  </nav>
  <span class="muted small">
    <?php foreach ($enabled as $code => $l): ?>
      <span class="pill<?= $code === Languages::default() ? ' pill-accent' : ' pill-soft' ?>" title="<?= e($l['name']) ?>"><?= e(strtoupper($code)) ?></span>
    <?php endforeach; ?>
  </span>
</div>

<?php if (!Languages::isActive()): ?>
  <section class="card card-pad" style="margin-bottom:16px;display:flex;gap:12px;align-items:flex-start">
    <?= icon('info', 18) ?>
    <div>
      <strong>Only one language is enabled.</strong>
      <p class="muted" style="margin:4px 0 0">Your website works exactly as before until a second language is added and enabled under
        <?php if (isset($tabs['languages'])): ?><a href="<?= e(Admin::url('languages')) ?>">Languages</a><?php else: ?>Languages<?php endif; ?>.
        Then content gets a language, translated pages live under a prefix such as <code>/es/</code>, and visitors can switch languages.</p>
    </div>
  </section>
<?php endif; ?>

<?php $view($tab, []); ?>
