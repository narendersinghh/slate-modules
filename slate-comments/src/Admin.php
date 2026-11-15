<?php
declare(strict_types=1);

namespace SlateModules\Comments;

use Slate\Auth;
use Slate\Csrf;
use Slate\DB;
use Slate\Posts;
use Slate\Session;

/** Dashboard: moderation actions, list queries and the settings form. */
final class Admin
{
    public const PER_PAGE = 30;

    public static function url(array $query = []): string
    {
        $query = array_filter($query, static fn($v) => $v !== '' && $v !== null && $v !== 0);
        return admin_url('m/comments') . ($query ? '?' . http_build_query($query) : '');
    }

    /** Safe redirect target inside the comments screen. */
    private static function back(): never
    {
        $to = (string)($_POST['return'] ?? '');
        redirect($to !== '' && str_starts_with($to, admin_url('m/comments')) && !preg_match('/[\r\n]/', $to) ? $to : self::url());
    }

    /** Handle a POST to the comments screen (moderation, reply, edit). Always redirects. */
    public static function handlePost(): never
    {
        Csrf::check();
        if (!Auth::can('edit_posts')) {
            http_response_code(403);
            exit('You do not have permission to do that.');
        }
        $do = (string)($_POST['do'] ?? '');
        if ($do === 'bulk') {
            $do = (string)($_POST['bulk_action'] ?? '');
            $comments = Comments::findMany((array)($_POST['ids'] ?? []));
        } else {
            $comments = Comments::findMany([(int)($_POST['id'] ?? 0)]);
        }
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
                break;
            default:
                Session::flash('error', 'Unknown action.');
                self::back();
        }
        log_activity('comments_' . preg_replace('/\W/', '', $do), (string)$n);
        self::back();
    }

    private static function reply(array $parent): void
    {
        $content = trim((string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string)($_POST['content'] ?? '')));
        if (mb_strlen($content) < 2 || mb_strlen($content) > Comments::MAX_CONTENT) {
            Session::flash('error', 'Write a reply of 2 to ' . number_format(Comments::MAX_CONTENT) . ' characters.');
            redirect(self::url(['reply' => (int)$parent['id']]));
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

    /** Current filters from the query string. */
    public static function filters(): array
    {
        $status = (string)($_GET['status'] ?? '');
        return [
            'status' => isset(Comments::STATUSES[$status]) ? $status : '',
            'q' => trim((string)($_GET['q'] ?? '')),
            'post' => max(0, (int)($_GET['post'] ?? 0)),
            'pg' => max(1, (int)($_GET['pg'] ?? 1)),
        ];
    }

    /** @return array{items: array, total: int} */
    public static function query(array $f): array
    {
        [$scope, $params] = Comments::scopeSql();
        $where = [$scope];
        if ($f['status'] !== '') {
            $where[] = 'c.status = :status';
            $params['status'] = $f['status'];
        } else {
            $where[] = "c.status IN ('pending', 'approved')";
        }
        if ($f['post'] > 0) {
            $where[] = 'c.post_id = :post';
            $params['post'] = $f['post'];
        }
        if ($f['q'] !== '') {
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $f['q']) . '%';
            $where[] = "(c.author_name LIKE :q1 ESCAPE '!' OR c.author_email LIKE :q2 ESCAPE '!' OR c.content LIKE :q3 ESCAPE '!' OR c.ip LIKE :q4 ESCAPE '!')";
            $params += ['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like];
        }
        $sql = 'FROM {{comments}} c WHERE ' . implode(' AND ', $where);
        $total = (int)DB::val("SELECT COUNT(*) $sql", $params);
        $offset = ($f['pg'] - 1) * self::PER_PAGE;
        $items = DB::all("SELECT c.* $sql ORDER BY c.created_at DESC, c.id DESC LIMIT " . self::PER_PAGE . ' OFFSET ' . (int)$offset, $params);
        Posts::prime(array_map(static fn(array $c) => (int)$c['post_id'], $items));
        return ['items' => $items, 'total' => $total];
    }

    // ------------------------------------------------------------------ settings

    public static function saveSettings(): never
    {
        Csrf::check();
        if (!Auth::can('manage_settings')) {
            http_response_code(403);
            exit('You do not have permission to do that.');
        }
        $before = Config::all();
        Config::save((array)($_POST['cs'] ?? []));
        // Display settings changed → cached pages must be rebuilt.
        if ($before !== Config::all() && class_exists(\Slate\PageCache::class)) {
            \Slate\PageCache::clear();
        }
        log_activity('comments_settings', 'saved');
        Session::flash('success', 'Comment settings saved.');
        redirect(admin_url('m/comments-settings'));
    }
}
