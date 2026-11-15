<?php
/** Comments moderation screen (Content → Comments). */
declare(strict_types=1);

use Slate\Auth;
use Slate\Posts;
use SlateModules\Comments\Admin;
use SlateModules\Comments\Comments;
use SlateModules\Comments\Front;

$self = admin_url('m/comments');
$current = $self . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '');
$pillClass = static fn(string $s): string => match ($s) {
    'approved' => 'pill pill-success', 'pending' => 'pill pill-warn', 'spam' => 'pill pill-danger', default => 'pill',
};
$itemLink = static function (?array $post): string {
    if (!$post) {
        return '<span class="muted">(deleted item)</span>';
    }
    $title = trim((string)$post['title']) !== '' ? (string)$post['title'] : '(no title)';
    return '<a href="' . e(Posts::permalink($post)) . '#comments" target="_blank" rel="noopener">' . e(str_limit($title, 60)) . '</a>';
};

// ---------------------------------------------------------------- edit / reply
$editId = (int)($_GET['edit'] ?? 0);
$replyId = (int)($_GET['reply'] ?? 0);
if ($editId > 0 || $replyId > 0):
    $c = Comments::find($editId ?: $replyId);
    if (!$c || !Comments::canModerate($c)): ?>
      <div class="card empty-state"><?= icon('message', 32) ?><h2>Comment not found</h2><p><a href="<?= e($self) ?>">Back to all comments</a></p></div>
    <?php return; endif;
    $post = Posts::find((int)$c['post_id']); ?>
<div class="row-between" style="margin-bottom:16px">
  <a class="btn btn-ghost btn-sm" href="<?= e($self) ?>"><?= icon('chevron-left', 15) ?> All comments</a>
</div>
<div class="split split-wide">
  <section class="card">
    <div class="card-head"><h2><?= $editId ? 'Edit comment' : 'Reply to ' . e($c['author_name']) ?></h2><span class="<?= e($pillClass((string)$c['status'])) ?>"><?= e(Comments::STATUSES[$c['status']] ?? $c['status']) ?></span></div>
    <div class="card-pad">
      <?php if ($editId): ?>
        <form method="post" action="<?= e($self) ?>" class="stack">
          <?= csrf_field() ?>
          <input type="hidden" name="do" value="edit"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
          <input type="hidden" name="return" value="<?= e(Admin::url(['status' => $c['status']])) ?>">
          <div class="field"><label for="ce-name">Name</label><input class="input" id="ce-name" name="author_name" maxlength="<?= Comments::MAX_NAME ?>" value="<?= e($c['author_name']) ?>"></div>
          <div class="field"><label for="ce-email">Email</label><input class="input" type="email" id="ce-email" name="author_email" maxlength="<?= Comments::MAX_EMAIL ?>" value="<?= e($c['author_email']) ?>"></div>
          <div class="field"><label for="ce-url">Website</label><input class="input" type="url" id="ce-url" name="author_url" maxlength="<?= Comments::MAX_URL ?>" value="<?= e($c['author_url']) ?>"></div>
          <div class="field"><label for="ce-content">Comment</label><textarea class="input" id="ce-content" name="content" rows="8" maxlength="<?= Comments::MAX_CONTENT ?>" required><?= e($c['content']) ?></textarea></div>
          <div class="field"><label for="ce-status">Status</label>
            <select class="input" id="ce-status" name="status">
              <?php foreach (Comments::STATUSES as $k => $l): ?><option value="<?= e($k) ?>"<?= $c['status'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div><button class="btn btn-primary">Save comment</button></div>
        </form>
      <?php else: ?>
        <blockquote style="margin:0 0 16px;padding:10px 14px;border-left:3px solid var(--border);color:var(--text-2)"><?= Front::formatText((string)$c['content']) ?></blockquote>
        <form method="post" action="<?= e($self) ?>" class="stack">
          <?= csrf_field() ?>
          <input type="hidden" name="do" value="reply"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
          <input type="hidden" name="return" value="<?= e(Admin::url(['post' => (int)$c['post_id']])) ?>">
          <div class="field"><label for="cr-content">Your reply</label><textarea class="input" id="cr-content" name="content" rows="6" maxlength="<?= Comments::MAX_CONTENT ?>" required autofocus></textarea>
            <span class="help">Published right away as <?= e((string)((Auth::user()['display_name'] ?? '') ?: (Auth::user()['username'] ?? ''))) ?>.<?= $c['status'] !== 'approved' ? ' The comment you answer will be approved too.' : '' ?></span></div>
          <div><button class="btn btn-primary">Publish reply</button></div>
        </form>
      <?php endif; ?>
    </div>
  </section>
  <aside class="stack">
    <section class="card"><div class="card-pad stack-sm">
      <div><strong class="small-label">On</strong><div><?= $itemLink($post) ?></div></div>
      <div><strong class="small-label">Author</strong><div><?= e($c['author_name']) ?><?= $c['author_email'] !== '' ? '<br><a href="mailto:' . e($c['author_email']) . '">' . e($c['author_email']) . '</a>' : '' ?></div></div>
      <?php if (preg_match('#^https?://#i', (string)$c['author_url'])): ?><div><strong class="small-label">Website</strong><div><a href="<?= e($c['author_url']) ?>" target="_blank" rel="nofollow noopener noreferrer"><?= e($c['author_url']) ?></a></div></div><?php endif; ?>
      <div><strong class="small-label">Received</strong><div><?= e(local_date((string)$c['created_at'], 'M j, Y H:i')) ?></div></div>
      <div><strong class="small-label">IP address</strong><div><?= e($c['ip'] ?: '—') ?></div></div>
      <?php if ($c['user_agent'] !== ''): ?><div><strong class="small-label">Browser</strong><div class="small muted"><?= e($c['user_agent']) ?></div></div><?php endif; ?>
    </div></section>
  </aside>
</div>
<?php return; endif;

// ---------------------------------------------------------------- list
$f = Admin::filters();
$result = Admin::query($f);
$items = $result['items'];
$total = $result['total'];
$counts = Comments::statusCounts();
$filterPost = $f['post'] > 0 ? Posts::find($f['post']) : null;
$tabs = ['' => ['All', $counts['pending'] + $counts['approved']], 'pending' => ['Pending', $counts['pending']], 'approved' => ['Approved', $counts['approved']],
    'spam' => ['Spam', $counts['spam']], 'trash' => ['Trash', $counts['trash']]];
$bulk = match ($f['status']) {
    'pending' => ['approve' => 'Approve', 'spam' => 'Mark as spam', 'trash' => 'Move to trash'],
    'approved' => ['unapprove' => 'Unapprove', 'spam' => 'Mark as spam', 'trash' => 'Move to trash'],
    'spam' => ['notspam' => 'Not spam', 'delete' => 'Delete permanently'],
    'trash' => ['restore' => 'Restore', 'delete' => 'Delete permanently'],
    default => ['approve' => 'Approve', 'unapprove' => 'Unapprove', 'spam' => 'Mark as spam', 'trash' => 'Move to trash'],
};
?>
<div class="row-between" style="margin:-8px 0 16px;gap:12px;flex-wrap:wrap">
  <p class="muted" style="margin:0">Moderate what visitors write under your content.<?= $filterPost ? ' Showing comments on ' . $itemLink($filterPost) . ' — <a href="' . e(Admin::url(['status' => $f['status']])) . '">show all</a>.' : '' ?></p>
  <?php if (Auth::can('manage_settings')): ?><a class="btn btn-sm" href="<?= e(admin_url('m/comments-settings')) ?>"><?= icon('settings', 15) ?> Settings</a><?php endif; ?>
</div>
<div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;justify-content:space-between;margin-bottom:14px">
  <nav class="tabs-pill" aria-label="Filter by status">
    <?php foreach ($tabs as $k => [$label, $n]): ?>
      <a class="<?= $f['status'] === $k ? 'active' : '' ?>" href="<?= e(Admin::url(['status' => $k, 'q' => $f['q'], 'post' => $f['post']])) ?>"><?= e($label) ?> <span><?= number_format((int)$n) ?></span></a>
    <?php endforeach; ?>
  </nav>
  <form class="filter-bar" method="get" action="<?= e($self) ?>" style="margin:0">
    <?php if ($f['status'] !== ''): ?><input type="hidden" name="status" value="<?= e($f['status']) ?>"><?php endif; ?>
    <?php if ($f['post'] > 0): ?><input type="hidden" name="post" value="<?= (int)$f['post'] ?>"><?php endif; ?>
    <input class="input input-sm" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Search comments…" aria-label="Search comments">
    <button class="btn btn-sm"><?= icon('search', 15) ?> Search</button>
  </form>
</div>
<?php if (!$items): ?>
  <div class="card empty-state"><?= icon('message', 32) ?><h2><?= $f['q'] !== '' ? 'No comments match your search' : 'No comments here' ?></h2>
    <p class="muted"><?= $f['status'] === 'pending' ? 'Nothing is waiting for approval.' : 'New comments appear here as soon as visitors post them.' ?></p></div>
<?php else: ?>
<form method="post" action="<?= e($self) ?>" id="cmts-bulk">
  <?= csrf_field() ?><input type="hidden" name="do" value="bulk"><input type="hidden" name="return" value="<?= e($current) ?>">
  <div class="filter-bar">
    <select class="input input-sm" name="bulk_action" aria-label="Bulk action">
      <?php foreach ($bulk as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?>
    </select>
    <button class="btn btn-sm" data-confirm="Apply to the selected comments?">Apply to selected</button>
    <label class="small muted" style="display:inline-flex;gap:6px;align-items:center"><input type="checkbox" onclick="document.querySelectorAll('[data-cmt-cb]').forEach(c=>c.checked=this.checked)"> Select all</label>
    <span class="muted small"><?= number_format($total) ?> comment<?= $total === 1 ? '' : 's' ?></span>
  </div>
</form>
<div class="card"><div class="table-wrap"><table class="table">
  <thead><tr><th style="width:28px"><span class="sr-only">Select</span></th><th>Author</th><th>Comment</th><th>On</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($items as $c):
      $cid = (int)$c['id'];
      $post = Posts::find((int)$c['post_id']);
      $act = static fn(string $do, string $label, string $cls = 'btn btn-ghost btn-sm', string $confirm = ''): string =>
          '<button class="' . $cls . '" form="cmts-row-' . $cid . '" name="do" value="' . e($do) . '"' . ($confirm !== '' ? ' data-confirm="' . e($confirm) . '"' : '') . '>' . e($label) . '</button>';
  ?>
    <tr>
      <td><input type="checkbox" form="cmts-bulk" name="ids[]" value="<?= $cid ?>" data-cmt-cb aria-label="Select comment by <?= e($c['author_name']) ?>"></td>
      <td style="min-width:160px">
        <strong><?= e($c['author_name']) ?></strong><?= !empty($c['user_id']) ? ' <span class="pill pill-soft">Team</span>' : '' ?>
        <?php if ($c['author_email'] !== ''): ?><br><a class="small" href="mailto:<?= e($c['author_email']) ?>"><?= e($c['author_email']) ?></a><?php endif; ?>
        <?php if (preg_match('#^https?://#i', (string)$c['author_url'])): ?><br><a class="small muted" href="<?= e($c['author_url']) ?>" target="_blank" rel="nofollow noopener noreferrer"><?= e(str_limit((string)preg_replace('#^https?://(www\.)?#i', '', (string)$c['author_url']), 40)) ?></a><?php endif; ?>
        <br><span class="small muted"><?= e($c['ip']) ?></span>
      </td>
      <td>
        <div class="small muted" style="margin-bottom:4px"><span class="<?= e($pillClass((string)$c['status'])) ?>"><?= e(Comments::STATUSES[$c['status']] ?? $c['status']) ?></span>
          <?= e(local_date((string)$c['created_at'], 'M j, Y H:i')) ?><?= !empty($c['parent_id']) ? ' · reply' : '' ?></div>
        <div style="max-width:560px;overflow-wrap:anywhere"><?= Front::formatText(mb_strlen((string)$c['content']) > 700 ? mb_substr((string)$c['content'], 0, 699) . '…' : (string)$c['content']) ?></div>
        <form method="post" action="<?= e($self) ?>" id="cmts-row-<?= $cid ?>" style="display:flex;gap:4px;flex-wrap:wrap;margin-top:6px">
          <?= csrf_field() ?><input type="hidden" name="id" value="<?= $cid ?>"><input type="hidden" name="return" value="<?= e($current) ?>">
          <?php
          echo match ((string)$c['status']) {
              'pending' => $act('approve', 'Approve', 'btn btn-sm') . $act('spam', 'Spam') . $act('trash', 'Trash', 'btn btn-ghost btn-sm btn-danger'),
              'approved' => $act('unapprove', 'Unapprove') . $act('spam', 'Spam') . $act('trash', 'Trash', 'btn btn-ghost btn-sm btn-danger'),
              'spam' => $act('notspam', 'Not spam') . $act('delete', 'Delete permanently', 'btn btn-ghost btn-sm btn-danger', 'Delete this comment permanently? This cannot be undone.'),
              default => $act('restore', 'Restore') . $act('delete', 'Delete permanently', 'btn btn-ghost btn-sm btn-danger', 'Delete this comment permanently? This cannot be undone.'),
          };
          ?>
          <?php if ($c['status'] !== 'spam' && $c['status'] !== 'trash'): ?><a class="btn btn-ghost btn-sm" href="<?= e(Admin::url(['reply' => $cid])) ?>">Reply</a><?php endif; ?>
          <a class="btn btn-ghost btn-sm" href="<?= e(Admin::url(['edit' => $cid])) ?>">Edit</a>
        </form>
      </td>
      <td style="min-width:140px"><?= $itemLink($post) ?>
        <?php if ($post): ?><br><a class="small muted" href="<?= e(Admin::url(['post' => (int)$post['id'], 'status' => $f['status']])) ?>"><?= number_format(Comments::approvedCount((int)$post['id'])) ?> approved</a><?php endif; ?></td>
      <td><?php if ($post && $c['status'] === 'approved'): ?><a class="icon-btn" href="<?= e(Posts::permalink($post) . '#comment-' . $cid) ?>" target="_blank" rel="noopener" title="View on site"><?= icon('external', 15) ?></a><?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div></div>
<?php $pages = (int)ceil($total / Admin::PER_PAGE); if ($pages > 1): ?>
  <div class="row-between" style="margin-top:12px">
    <span class="muted small">Page <?= (int)$f['pg'] ?> of <?= $pages ?></span>
    <span>
      <?php if ($f['pg'] > 1): ?><a class="btn btn-sm" href="<?= e(Admin::url(['status' => $f['status'], 'q' => $f['q'], 'post' => $f['post'], 'pg' => $f['pg'] - 1])) ?>">Previous</a><?php endif; ?>
      <?php if ($f['pg'] < $pages): ?><a class="btn btn-sm" href="<?= e(Admin::url(['status' => $f['status'], 'q' => $f['q'], 'post' => $f['post'], 'pg' => $f['pg'] + 1])) ?>">Next</a><?php endif; ?>
    </span>
  </div>
<?php endif; ?>
<?php endif; ?>
