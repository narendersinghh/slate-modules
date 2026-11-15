<?php
declare(strict_types=1);

namespace SlateModules\Comments;

use Slate\Auth;
use Slate\ContentModel;
use Slate\Csrf;
use Slate\DB;
use Slate\Posts;
use Slate\Session;

/** Dashboard: moderation actions and list queries. Settings are declared in Config::register(). */
final class Admin
{
    public static function url(array $query = []): string
    {
        $query = array_filter($query, static fn($v) => $v !== '' && $v !== null && $v !== 0 && $v !== []);
        return admin_url('m/comments') . ($query ? '?' . http_build_query($query) : '');
    }

    /** Back to the list the action came from (the form posts to the list URL, so its query string is the state). */
    private static function back(): never
    {
        $keep = array_filter($_GET, static fn($v, $k) => in_array($k, ['q', 'status', 'f', 'sort', 'dir', 'p'], true), ARRAY_FILTER_USE_BOTH);
        redirect(self::url($keep));
    }

    /**
     * Handle a POST to the comments screen. Always redirects.
     * List form: a row action button sends one=<action>:<id>; the bulk bar sends action=<action> + ids[].
     * Edit screen: do=edit + id.
     */
    public static function handlePost(): never
    {
        Csrf::check();
        if (!Auth::can('edit_posts')) {
            http_response_code(403);
            exit('You do not have permission to do that.');
        }
        if (preg_match('/^(\w+):(\d+)$/', (string)($_POST['one'] ?? ''), $m)) {
            [$do, $ids] = [$m[1], [(int)$m[2]]];
        } elseif (isset($_POST['action'])) {
            [$do, $ids] = [(string)$_POST['action'], (array)($_POST['ids'] ?? [])];
        } else {
            [$do, $ids] = [(string)($_POST['do'] ?? ''), [(int)($_POST['id'] ?? 0)]];
        }
        $comments = Comments::findMany($ids);
        if (!$comments) {
            Session::flash('error', 'Select at least one comment you are allowed to moderate.');
            self::back();
        }
        $n = count($comments);
        $label = $n === 1 ? 'Comment' : "$n comments";
        switch ($do) {
            case 'approve':
                Comments::setStatus($comments, 'approved');
                Session::flash('success', "$label approved.");
                break;
            case 'unapprove':
            case 'restore':
            case 'notspam':
                Comments::setStatus($comments, 'pending');
                Session::flash('success', $do === 'unapprove' ? "$label moved back to pending." : "$label restored to pending.");
                break;
            case 'spam':
                Comments::setStatus($comments, 'spam');
                Session::flash('success', "$label marked as spam.");
                break;
            case 'trash':
                Comments::setStatus($comments, 'trash');
                Session::flash('success', "$label moved to the trash.");
                break;
            case 'delete':
                Comments::deleteMany($comments);
                Session::flash('success', "$label deleted permanently.");
                break;
            case 'reply':
                self::reply($comments[0]);
                break;
            case 'edit':
                self::edit($comments[0]);
                redirect(self::url(['status' => (string)($_POST['status'] ?? '')]));
            default:
                Session::flash('error', 'Unknown action.');
                self::back();
        }
        log_activity('comments_' . preg_replace('/\W/', '', $do), (string)$n);
        self::back();
    }

    private static function reply(array $parent): void
    {
        $raw = $_POST['reply'][(int)$parent['id']] ?? '';
        $content = trim((string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', is_string($raw) ? $raw : ''));
        if (mb_strlen($content) < 2 || mb_strlen($content) > Comments::MAX_CONTENT) {
            Session::flash('error', 'Write a reply of 2 to ' . number_format(Comments::MAX_CONTENT) . ' characters.');
            self::back();
        }
        $user = Auth::user() ?? [];
        // Replies stay one level deep: answering a reply answers its thread.
        $top = $parent;
        if (!empty($parent['parent_id']) && ($p = Comments::find((int)$parent['parent_id'])) && (int)$p['post_id'] === (int)$parent['post_id']) {
            $top = $p;
        }
        // Answering a comment that is not public yet approves it, so the reply is not orphaned.
        if ($top['status'] !== 'approved' && Comments::canModerate($top)) {
            Comments::setStatus([$top], 'approved');
        }
        $id = Comments::create([
            'post_id' => (int)$parent['post_id'],
            'parent_id' => (int)$top['id'],
            'author_name' => (string)(($user['display_name'] ?? '') !== '' ? $user['display_name'] : ($user['username'] ?? 'Admin')),
            'author_email' => (string)($user['email'] ?? ''),
            'author_url' => '',
            'user_id' => Auth::id() ?: null,
            'content' => $content,
            'status' => 'approved',
            'ip' => Auth::ip(),
            'user_agent' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);
        $post = Posts::find((int)$parent['post_id']);
        if ($post) {
            // Webhook + "comment_created" action; no notification email for your own reply.
            Notifier::newComment($id, ['parent_id' => (int)$top['id'], 'author_name' => (string)($user['display_name'] ?? ''), 'author_email' => (string)($user['email'] ?? ''),
                'author_url' => '', 'user_id' => Auth::id(), 'content' => $content, 'status' => 'approved'], $post, false);
        }
        Session::flash('success', 'Reply published.');
    }

    private static function edit(array $c): void
    {
        $clean = static fn(string $k): string => trim((string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string)($_POST[$k] ?? '')));
        $name = mb_substr($clean('author_name'), 0, Comments::MAX_NAME);
        $email = $clean('author_email');
        $url = $clean('author_url');
        $content = $clean('content');
        $status = (string)($_POST['status'] ?? $c['status']);
        $back = self::url(['edit' => (int)$c['id']]);
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Session::flash('error', 'That email address is not valid.');
            redirect($back);
        }
        if ($url !== '') {
            if (!preg_match('#^https?://#i', $url)) {
                $url = 'https://' . $url;
            }
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                Session::flash('error', 'That website address is not valid.');
                redirect($back);
            }
        }
        if (mb_strlen($content) < 1 || mb_strlen($content) > Comments::MAX_CONTENT) {
            Session::flash('error', 'The comment must be 1 to ' . number_format(Comments::MAX_CONTENT) . ' characters.');
            redirect($back);
        }
        if (!isset(Comments::STATUSES[$status])) {
            $status = (string)$c['status'];
        }
        Comments::edit($c, ['author_name' => $name, 'author_email' => mb_substr(strtolower($email), 0, Comments::MAX_EMAIL),
            'author_url' => mb_substr($url, 0, Comments::MAX_URL), 'content' => $content, 'status' => $status]);
        Session::flash('success', 'Comment updated.');
    }

    // ------------------------------------------------------------------ list

    /** Sortable columns → SQL. */
    private const SORTS = ['date' => 'c.created_at', 'author' => 'c.author_name'];

    /**
     * Comments matching the list state (ListTable::state()). $limit = false returns every match (CSV export).
     * @return array{items: array, total: int}
     */
    public static function query(array $s, bool $limit = true): array
    {
        [$scope, $params] = Comments::scopeSql();
        $where = [$scope];
        if (isset(Comments::STATUSES[$s['view']])) {
            $where[] = 'c.status = :status';
            $params['status'] = $s['view'];
        } else {
            $where[] = "c.status IN ('pending', 'approved')";
        }
        if (!empty($s['filters']['post'])) {
            $where[] = 'c.post_id = :post';
            $params['post'] = (int)$s['filters']['post'];
        }
        if (!empty($s['filters']['type'])) {
            $where[] = 'p.type = :type';
            $params['type'] = (string)$s['filters']['type'];
        }
        if ($s['q'] !== '') {
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $s['q']) . '%';
            $where[] = "(c.author_name LIKE :q1 ESCAPE '!' OR c.author_email LIKE :q2 ESCAPE '!' OR c.content LIKE :q3 ESCAPE '!' OR c.ip LIKE :q4 ESCAPE '!')";
            $params += ['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like];
        }
        $sql = 'FROM {{comments}} c LEFT JOIN {{posts}} p ON p.id = c.post_id WHERE ' . implode(' AND ', $where);
        $total = (int)DB::val("SELECT COUNT(*) $sql", $params);
        $order = (self::SORTS[$s['sort']] ?? 'c.created_at') . ($s['dir'] === 'asc' ? ' ASC' : ' DESC') . ', c.id' . ($s['dir'] === 'asc' ? ' ASC' : ' DESC');
        $page = $limit ? ' LIMIT ' . (int)$s['per'] . ' OFFSET ' . (int)$s['offset'] : '';
        $items = DB::all("SELECT c.* $sql ORDER BY $order$page", $params);
        Posts::prime(array_map(static fn(array $c) => (int)$c['post_id'], $items));
        return ['items' => $items, 'total' => $total];
    }

    /** Filter options: content types and items that have comments (in the user's scope). */
    public static function filterOptions(): array
    {
        [$scope, $sp] = Comments::scopeSql();
        $types = [];
        foreach (DB::col("SELECT DISTINCT p.type FROM {{comments}} c INNER JOIN {{posts}} p ON p.id = c.post_id WHERE $scope", $sp) as $t) {
            $types[(string)$t] = (string)(ContentModel::type((string)$t)['plural'] ?? $t);
        }
        asort($types);
        $items = [];
        $rows = DB::all("SELECT p.id, p.title FROM {{comments}} c INNER JOIN {{posts}} p ON p.id = c.post_id WHERE $scope
            GROUP BY p.id, p.title ORDER BY MAX(c.created_at) DESC LIMIT 200", $sp);
        foreach ($rows as $r) {
            $items[(int)$r['id']] = str_limit(trim((string)$r['title']) !== '' ? (string)$r['title'] : '(no title)', 60);
        }
        return ['types' => $types, 'items' => $items];
    }
}
