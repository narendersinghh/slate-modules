<?php
/** Comments moderation screen (Modules → Comments): the list, or the edit screen with ?edit=<id>. */
declare(strict_types=1);

use Slate\AdminUi;
use Slate\Auth;
use Slate\ContentModel;
use Slate\ListTable;
use Slate\Posts;
use SlateModules\Comments\Admin;
use SlateModules\Comments\Comments;
use SlateModules\Comments\Front;

$self = admin_url('m/comments');
$tone = static fn(string $s): string => match ($s) { 'approved' => 'success', 'pending' => 'warn', 'spam' => 'danger', default => '' };
$pill = static fn(string $s): string => AdminUi::pill(Comments::STATUSES[$s] ?? $s, $tone($s));
$itemLink = static function (?array $post): string {
    if (!$post) {
        return '<span class="muted">(deleted item)</span>';
    }
    $title = trim((string)$post['title']) !== '' ? (string)$post['title'] : '(no title)';
    return '<a href="' . e(Posts::permalink($post)) . '#comments" target="_blank" rel="noopener">' . e(str_limit($title, 60)) . '</a>';
};

// ---------------------------------------------------------------- edit
$editId = (int)($_GET['edit'] ?? 0);
if ($editId > 0):
    $c = Comments::find($editId);
    if (!$c || !Comments::canModerate($c)) {
        echo AdminUi::emptyState('Comment not found', 'It may have been deleted.', 'message', ['Back to comments' => $self]);
        return;
    }
    $post = Posts::find((int)$c['post_id']); ?>
<div class="row-between" style="margin-bottom:16px">
  <a class="btn btn-ghost btn-sm" href="<?= e(Admin::url(['status' => $c['status']])) ?>"><?= icon('chevron-left', 15) ?> All comments</a>
</div>
<div class="split split-wide">
  <section class="card">
    <div class="card-head"><h2>Edit comment</h2><?= $pill((string)$c['status']) ?></div>
    <div class="card-pad">
      <form method="post" action="<?= e($self) ?>" class="stack">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="edit"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
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
    </div>
  </section>
  <aside class="stack">
    <section class="card"><div class="card-pad">
      <dl class="kv">
        <dt>On</dt><dd><?= $itemLink($post) ?></dd>
        <dt>Received</dt><dd><?= e(local_date((string)$c['created_at'], 'M j, Y H:i')) ?></dd>
        <dt>IP address</dt><dd><?= e($c['ip'] ?: '-') ?></dd>
        <?php if ($c['user_agent'] !== ''): ?><dt>Browser</dt><dd class="small muted"><?= e($c['user_agent']) ?></dd><?php endif; ?>
      </dl>
    </div></section>
  </aside>
</div>
<?php return; endif;

// ---------------------------------------------------------------- list
$counts = Comments::statusCounts();
$opts = Admin::filterOptions();
$filters = [];
if (count($opts['types']) > 1) {
    $filters['type'] = ['label' => 'Content types', 'options' => $opts['types']];
}
if ($opts['items']) {
    $filters['post'] = ['label' => 'Items', 'options' => $opts['items']];
}
$view = (string)($_GET['status'] ?? '');
$bulk = match ($view) {
    'pending' => ['approve' => 'Approve', 'spam' => 'Mark as spam', 'trash' => ['label' => 'Move to trash', 'danger' => true]],
    'approved' => ['unapprove' => 'Unapprove', 'spam' => 'Mark as spam', 'trash' => ['label' => 'Move to trash', 'danger' => true]],
    'spam' => ['notspam' => 'Not spam', 'delete' => ['label' => 'Delete permanently', 'danger' => true, 'confirm' => 'Delete the selected comments permanently? This cannot be undone.']],
    'trash' => ['restore' => 'Restore', 'delete' => ['label' => 'Delete permanently', 'danger' => true, 'confirm' => 'Delete the selected comments permanently? This cannot be undone.']],
    default => ['approve' => 'Approve', 'unapprove' => 'Unapprove', 'spam' => 'Mark as spam', 'trash' => ['label' => 'Move to trash', 'danger' => true]],
};
$excerpt = static fn(string $t): string => mb_strlen($t) > 400 ? mb_substr($t, 0, 399) . '…' : $t;

$t = new ListTable('comments.moderation', [
    'comment' => ['label' => 'Comment', 'primary' => true, 'render' => static function (array $c) use ($excerpt): string {
        $cid = (int)$c['id'];
        $html = '<div class="cmt-text">' . Front::formatText($excerpt((string)$c['content'])) . '</div>';
        if (!empty($c['parent_id']) && ($parent = Comments::find((int)$c['parent_id']))) {
            $html = '<div class="small muted">In reply to ' . e($parent['author_name']) . '</div>' . $html;
        }
        if (in_array($c['status'], ['pending', 'approved'], true)) {
            $html .= '<div class="cmt-reply stack-sm" id="cmt-reply-' . $cid . '" hidden>'
                . '<textarea class="input" name="reply[' . $cid . ']" rows="3" maxlength="' . Comments::MAX_CONTENT . '" placeholder="Write a reply…" aria-label="Reply to ' . e($c['author_name']) . '"></textarea>'
                . '<div><button class="btn btn-primary btn-sm" name="one" value="reply:' . $cid . '">Publish reply</button> '
                . '<span class="small muted">Published right away' . ($c['status'] !== 'approved' ? '; this comment will be approved too' : '') . '.</span></div></div>';
        }
        return $html;
    }, 'export' => static fn(array $c): string => (string)$c['content']],
    'author' => ['label' => 'Author', 'sort' => 'author', 'render' => static function (array $c): string {
        $html = '<strong>' . e($c['author_name']) . '</strong>' . (!empty($c['user_id']) ? ' ' . AdminUi::pill('Team', 'info') : '');
        if ($c['author_email'] !== '') {
            $html .= '<br><a class="small" href="mailto:' . e($c['author_email']) . '">' . e($c['author_email']) . '</a>';
        }
        if (preg_match('#^https?://#i', (string)$c['author_url'])) {
            $html .= '<br><a class="small muted" href="' . e($c['author_url']) . '" target="_blank" rel="nofollow noopener noreferrer">'
                . e(str_limit((string)preg_replace('#^https?://(www\.)?#i', '', (string)$c['author_url']), 40)) . '</a>';
        }
        return $html;
    }, 'export' => static fn(array $c): string => (string)$c['author_name']],
    'email' => ['label' => 'Email', 'default' => false, 'render' => static fn(array $c): string => e($c['author_email'])],
    'status' => ['label' => 'Status', 'render' => static fn(array $c): string => $pill((string)$c['status']), 'export' => static fn(array $c): string => Comments::STATUSES[$c['status']] ?? (string)$c['status']],
    'on' => ['label' => 'On', 'render' => static function (array $c) use ($itemLink): string {
        $post = Posts::find((int)$c['post_id']);
        if (!$post) {
            return $itemLink(null);
        }
        $type = ContentModel::type((string)$post['type']);
        return $itemLink($post) . '<br><span class="small muted">' . e((string)($type['singular'] ?? $post['type'])) . ' · ' . number_format(Comments::approvedCount((int)$post['id'])) . ' approved</span>';
    }, 'export' => static fn(array $c): string => (string)(Posts::find((int)$c['post_id'])['title'] ?? '')],
    'date' => ['label' => 'Date', 'sort' => 'date', 'class' => 'nowrap', 'render' => static fn(array $c): string => e(local_date((string)$c['created_at'], 'M j, Y H:i')),
        'export' => static fn(array $c): string => (string)$c['created_at']],
    'ip' => ['label' => 'IP address', 'default' => false, 'render' => static fn(array $c): string => e($c['ip'])],
], [
    'base' => $self,
    'search' => 'Search name, email, text or IP…',
    'view_param' => 'status',
    'views' => ['all' => ['All', $counts['pending'] + $counts['approved']], 'pending' => ['Pending', $counts['pending']], 'approved' => ['Approved', $counts['approved']],
        'spam' => ['Spam', $counts['spam']], 'trash' => ['Trash', $counts['trash']]],
    'filters' => $filters,
    'bulk' => $bulk,
    // Bulk and row actions post back to this exact list, so the view, filters and page are kept.
    'bulk_action' => Admin::url(array_intersect_key($_GET, array_flip(['q', 'status', 'f', 'sort', 'dir', 'p']))),
    'sort' => 'date', 'dir' => 'desc', 'export' => true,
    'row_actions' => static function (array $c): string {
        $cid = (int)$c['id'];
        $btn = static fn(string $do, string $label, string $confirm = '', string $cls = 'link'): string =>
            '<button type="submit" class="' . $cls . '" name="one" value="' . e($do) . ':' . $cid . '"' . ($confirm !== '' ? ' data-confirm="' . e($confirm) . '"' : '') . '>' . e($label) . '</button>';
        $del = 'Delete this comment permanently? This cannot be undone.';
        $html = match ((string)$c['status']) {
            'pending' => $btn('approve', 'Approve') . $btn('spam', 'Spam') . $btn('trash', 'Trash', '', 'link link-danger'),
            'approved' => $btn('unapprove', 'Unapprove') . $btn('spam', 'Spam') . $btn('trash', 'Trash', '', 'link link-danger'),
            'spam' => $btn('notspam', 'Not spam') . $btn('delete', 'Delete permanently', $del, 'link link-danger'),
            default => $btn('restore', 'Restore') . $btn('delete', 'Delete permanently', $del, 'link link-danger'),
        };
        if (in_array($c['status'], ['pending', 'approved'], true)) {
            $html .= '<button type="button" class="link" data-cmt-reply="' . $cid . '">Reply</button>';
        }
        $html .= '<a href="' . e(Admin::url(['edit' => $cid])) . '">Edit</a>';
        if ($c['status'] === 'approved' && ($post = Posts::find((int)$c['post_id']))) {
            $html .= '<a href="' . e(Posts::permalink($post) . '#comment-' . $cid) . '" target="_blank" rel="noopener">View</a>';
        }
        return $html;
    },
    'empty' => ['icon' => 'message', 'title' => $view === 'pending' ? 'Nothing is waiting for approval' : 'No comments here',
        'text' => 'New comments appear here as soon as visitors post them.'],
]);
$s = $t->state();
if ($t->exporting()) {
    $t->exportCsv(Admin::query($s, false)['items']);
}
$result = Admin::query($s);
?>
<div class="row-between" style="margin:-8px 0 16px;gap:12px;flex-wrap:wrap">
  <p class="muted" style="margin:0">Approve, answer or remove what visitors write under your content.</p>
  <?php if (Auth::can('manage_settings')): ?><a class="btn btn-sm" href="<?= e(AdminUi::settingsUrl('slate-comments')) ?>"><?= icon('settings', 15) ?> Settings</a><?php endif; ?>
</div>
<?= $t->render($result['items'], $result['total']) ?>
<style>.cmt-text{max-width:620px;overflow-wrap:anywhere}.cmt-text p{margin:0 0 .4em}.cmt-reply{margin-top:8px;max-width:620px}</style>
<script>
document.addEventListener('click', (e) => {
  const b = e.target.closest('[data-cmt-reply]');
  if (!b) return;
  const box = document.getElementById('cmt-reply-' + b.dataset.cmtReply);
  if (box) { box.hidden = !box.hidden; if (!box.hidden) box.querySelector('textarea').focus(); }
});
</script>
