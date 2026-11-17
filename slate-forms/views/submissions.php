<?php
use Slate\AdminUi;
use SlateModules\Forms\Admin;
use Slate\DB;
use SlateModules\Forms\Forms;
use SlateModules\Forms\Inbox;

$self = Inbox::url();

// Old inbox addresses (?status=, ?form=, ?pg=) to the list table's parameters.
if (!isset($_GET['id']) && ($legacy = Inbox::legacyQuery($_GET)) !== null) {
    redirect(Inbox::url($legacy));
}

// CSV export of the list (before any output): every form field as its own column.
if (!isset($_GET['id'])) {
    $table = Inbox::table();
    $st = $table->state();
    [$where, $params] = Inbox::where($st);
    if ($table->exporting()) {
        Inbox::exportCsv($where, $params, Inbox::orderBy($st));
    }
}

// Page tabs (Forms, Entries, Settings) are drawn by the dashboard for every module.
echo Admin::assets();

// ---------------------------------------------------------------- one submission (?id=, also linked from notification emails)
if (($sid = (int)($_GET['id'] ?? 0)) > 0):
    $s = DB::row('SELECT * FROM {{form_submissions}} WHERE id = :id', ['id' => $sid]);
    if (!$s) {
        echo AdminUi::emptyState('Entry not found', 'It may have been deleted.', 'inbox', ['Back to entries' => $self]);
        return;
    }
    if ($s['status'] === 'new') {
        DB::update('form_submissions', ['status' => 'read'], ['id' => $sid]);
        $s['status'] = 'read';
    }
    $rows = Forms::rows($s);
    [$name, $email] = Inbox::sender($s);
    $back = (string)($_SERVER['HTTP_REFERER'] ?? '');
    $back = str_starts_with($back, $self) && !str_contains($back, 'id=') ? $back : $self;
    $newer = DB::val('SELECT id FROM {{form_submissions}} WHERE id > :id ORDER BY id ASC LIMIT 1', ['id' => $sid]);
    $older = DB::val('SELECT id FROM {{form_submissions}} WHERE id < :id ORDER BY id DESC LIMIT 1', ['id' => $sid]);
?>
<div class="row-between" style="margin-bottom:16px">
  <a class="btn btn-ghost btn-sm" href="<?= e($back) ?>"><?= icon('chevron-left', 15) ?> All entries</a>
  <span>
    <?php if ($newer): ?><a class="btn btn-sm btn-ghost" href="<?= e(Inbox::url(['id' => $newer])) ?>" title="Newer entry"><?= icon('chevron-left', 15) ?> Newer</a><?php endif; ?>
    <?php if ($older): ?><a class="btn btn-sm btn-ghost" href="<?= e(Inbox::url(['id' => $older])) ?>" title="Older entry">Older <?= icon('chevron-right', 15) ?></a><?php endif; ?>
  </span>
</div>
<div class="split split-wide">
  <section class="card">
    <div class="card-head">
      <h2 class="sf-entry-title"><?= Inbox::starButton($s) ?> <?= e($name !== '' ? $name : ($email !== '' ? $email : 'Entry #' . (int)$s['id'])) ?></h2>
      <?= $s['status'] === 'spam' ? AdminUi::pill('Spam', 'warn') : AdminUi::pill($s['form_title']) ?>
    </div>
    <div class="card-pad">
      <?php if (!$rows): ?><p class="muted">This entry has no fields.</p><?php endif; ?>
      <dl class="sub-detail">
        <?php foreach ($rows as $r): ?>
          <dt><?= e($r['label']) ?></dt>
          <dd><?php if ($r['file']): ?><a class="sf-file" href="<?= e(\SlateModules\Forms\Forms::fileUrl((int)$s['id'], $r['key'])) ?>"><?= icon('download', 15) ?> <?= e($r['value']) ?></a><?php if (!empty($r['file']['size'])): ?> <span class="muted small"><?= e(format_bytes((int)$r['file']['size'])) ?></span><?php endif; ?><?php elseif ($r['type'] === 'file' && $r['value'] !== ''): ?><?= e($r['value']) ?> <span class="muted small">(not kept: spam)</span><?php elseif ($r['value'] === ''): ?><span class="muted">-</span><?php elseif (filter_var($r['value'], FILTER_VALIDATE_EMAIL)): ?><a href="mailto:<?= e($r['value']) ?>"><?= e($r['value']) ?></a><?php elseif (filter_var($r['value'], FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $r['value'])): ?><a href="<?= e($r['value']) ?>" target="_blank" rel="noopener noreferrer"><?= e($r['value']) ?></a><?php else: ?><span class="sf-pre"><?= e($r['value']) ?></span><?php endif; ?></dd>
        <?php endforeach; ?>
      </dl>
    </div>
  </section>
  <aside class="stack">
    <form method="post" action="<?= e(admin_url('m/submissions/bulk')) ?>" class="card"><div class="card-pad stack-sm">
      <?= csrf_field() ?><input type="hidden" name="ids[]" value="<?= (int)$sid ?>"><input type="hidden" name="return" value="<?= e(Inbox::url(['id' => $sid])) ?>">
      <?php if ($email !== ''): ?><a class="btn btn-primary" href="mailto:<?= e($email) ?>?subject=<?= rawurlencode('Re: ' . $s['form_title']) ?>"><?= icon('message', 15) ?> Reply by email</a><?php endif; ?>
      <?php if ($s['status'] === 'spam'): ?>
        <button class="btn" name="action" value="read">Not spam</button>
      <?php else: ?>
        <button class="btn" name="action" value="new">Mark as unread</button>
        <button class="btn" name="action" value="spam">Mark as spam</button>
      <?php endif; ?>
      <button class="btn btn-ghost btn-danger" name="action" value="delete" data-confirm="Delete this entry permanently? This cannot be undone." onclick="this.form.return.value='<?= e($self) ?>'"><?= icon('trash', 15) ?> Delete</button>
    </div></form>
    <section class="card"><div class="card-pad">
      <div class="kv">
        <span>Form</span><span><?= $s['form_id'] ? '<a href="' . e(\SlateModules\Forms\Forms::editUrl((int)$s['form_id'])) . '">' . e($s['form_title']) . '</a>' : e($s['form_title']) ?></span>
        <span>Received</span><span><?= e(local_date($s['created_at'], 'M j, Y H:i')) ?></span>
        <?php if ($s['page_url']): ?><span>Page</span><span><a href="<?= e($s['page_url']) ?>" target="_blank" rel="noopener"><?= e($s['page_title'] ?: $s['page_url']) ?></a></span><?php endif; ?>
        <span>Status</span><span><?= e(Forms::STATUSES[$s['status']] ?? $s['status']) ?></span>
        <span>IP address</span><span><?= e($s['ip'] ?: '-') ?></span>
        <?php if ($s['user_agent']): ?><span>Browser</span><span class="small muted"><?= e($s['user_agent']) ?></span><?php endif; ?>
        <?php if ($s['source'] !== 'slate'): ?><span>Source</span><span>Imported from the previous website</span><?php endif; ?>
      </div>
    </div></section>
  </aside>
</div>
<?php return; endif;

// ---------------------------------------------------------------- list
$total = (int)DB::val("SELECT COUNT(*) FROM {{form_submissions}} $where", $params);
$items = DB::all("SELECT * FROM {{form_submissions}} $where ORDER BY " . Inbox::orderBy($st) . ' LIMIT ' . (int)$st['per'] . ' OFFSET ' . (int)$st['offset'], $params);
echo $table->render($items, $total);
