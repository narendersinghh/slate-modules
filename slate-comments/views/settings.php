<?php
/** Comments → Settings. */
declare(strict_types=1);

use Slate\ContentModel;
use SlateModules\Comments\Config;

$s = Config::all();
$enabled = Config::types();
$types = array_filter(ContentModel::types(), static fn(array $t) => ($t['public'] ?? true) !== false);
$switch = static fn(string $name, bool $on, string $label, string $help = ''): string =>
    '<label class="switch-row"><input type="hidden" name="cs[' . e($name) . ']" value="0"><input type="checkbox" class="switch" name="cs[' . e($name) . ']" value="1"' . ($on ? ' checked' : '') . '>'
    . '<span>' . e($label) . ($help !== '' ? ' <small class="muted">— ' . e($help) . '</small>' : '') . '</span></label>';
?>
<div class="row-between" style="margin:-8px 0 16px;gap:12px;flex-wrap:wrap">
  <p class="muted" style="margin:0">Where comments appear, how they are moderated and who is told about them.</p>
  <a class="btn btn-sm" href="<?= e(admin_url('m/comments')) ?>"><?= icon('message', 15) ?> Moderate comments</a>
</div>
<form method="post" action="<?= e(admin_url('m/comments-settings')) ?>" class="stack">
  <?= csrf_field() ?>
  <section class="card">
    <div class="card-head"><h2>Where</h2></div>
    <div class="card-pad stack">
      <div class="field">
        <span>Content types with comments</span>
        <div style="display:flex;gap:16px;flex-wrap:wrap">
          <?php foreach ($types as $slug => $t): ?>
            <label class="check" style="display:inline-flex;gap:8px;align-items:center"><input type="checkbox" name="cs[types][]" value="<?= e($slug) ?>"<?= in_array($slug, $enabled, true) ? ' checked' : '' ?>> <?= e($t['plural'] ?? $slug) ?></label>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="field">
        <?= $switch('auto_append', (bool)$s['auto_append'], 'Show comments automatically below the content', 'turn off when your templates place {{comments}} or slate_comments() themselves') ?>
        <span class="help">Template tags: <code>{{comments}}</code> (the whole section), <code>{{comments_count}}</code>, and <code>{{#if comments_open}}</code>. Theme PHP: <code>&lt;?= slate_comments() ?&gt;</code>. The section is never printed twice on a page.</span>
      </div>
      <div class="field">
        <label for="cs-close">Close comments on items older than (days)</label>
        <input class="input" type="number" id="cs-close" name="cs[close_days]" min="0" max="36500" value="<?= (int)$s['close_days'] ?>" style="max-width:160px">
        <span class="help">0 = never close. Existing comments stay visible.</span>
      </div>
      <div class="field">
        <label for="cs-order">Order</label>
        <select class="input" id="cs-order" name="cs[order]" style="max-width:240px">
          <option value="oldest"<?= $s['order'] !== 'newest' ? ' selected' : '' ?>>Oldest first</option>
          <option value="newest"<?= $s['order'] === 'newest' ? ' selected' : '' ?>>Newest first</option>
        </select>
        <span class="help">Replies are always shown oldest first under their comment.</span>
      </div>
    </div>
  </section>

  <section class="card">
    <div class="card-head"><h2>Moderation &amp; spam</h2></div>
    <div class="card-pad stack">
      <div class="field"><?= $switch('moderate', (bool)$s['moderate'], 'Hold new comments for approval', 'recommended') ?></div>
      <div class="field"><?= $switch('approve_known', (bool)$s['approve_known'], 'Publish right away when the author already has an approved comment', 'only applies while comments are held') ?></div>
      <div class="field">
        <label for="cs-links">Links allowed before a comment is treated as spam</label>
        <input class="input" type="number" id="cs-links" name="cs[max_links]" min="0" max="100" value="<?= (int)$s['max_links'] ?>" style="max-width:160px">
        <span class="help">Comments with more links than this go to Spam.</span>
      </div>
      <div class="field">
        <label for="cs-words">Blocked words</label>
        <textarea class="input" id="cs-words" name="cs[blocked_words]" rows="5" placeholder="One word, phrase, email or IP address per line"><?= e((string)$s['blocked_words']) ?></textarea>
        <span class="help">A comment whose name, email, website, text, IP address or browser contains any of these goes to Spam.</span>
      </div>
      <p class="help" style="margin:0">Every comment form is also protected by a hidden honeypot field, a signed time token, a same-site check and a limit of 5 comments per 10 minutes per IP address. No cookies and no third-party services are used.</p>
    </div>
  </section>

  <section class="card">
    <div class="card-head"><h2>Notifications &amp; API</h2></div>
    <div class="card-pad stack">
      <div class="field">
        <label for="cs-notify">Email new comments to</label>
        <input class="input" type="email" id="cs-notify" name="cs[notify_email]" value="<?= e((string)$s['notify_email']) ?>" placeholder="Leave empty for no emails" style="max-width:420px">
        <span class="help">Spam is never emailed. The <code>comment.created</code> webhook event fires too (Settings → Integrations → Webhooks).</span>
      </div>
      <div class="field"><?= $switch('api_count', (bool)$s['api_count'], 'Add comments_count to REST API items') ?>
        <span class="help">Approved comments of an item are also available at <code>/api/v1/comments/{id}</code> (names, websites and text only — never emails or IP addresses).</span></div>
    </div>
  </section>
  <div><button class="btn btn-primary">Save settings</button></div>
</form>
