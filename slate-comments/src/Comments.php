<?php
declare(strict_types=1);

namespace SlateModules\Comments;

use Slate\Auth;
use Slate\DB;
use Slate\Hooks;
use Slate\PageCache;
use Slate\Posts;

/** Data layer: reading, writing and moderating comments. */
final class Comments
{
    public const STATUSES = ['pending' => 'Pending', 'approved' => 'Approved', 'spam' => 'Spam', 'trash' => 'Trash'];

    /** Limits for stored values (characters). */
    public const MAX_NAME = 100;
    public const MAX_EMAIL = 191;
    public const MAX_URL = 255;
    public const MAX_CONTENT = 5000;

    private static array $counts = [];

    public static function find(int $id): ?array
    {
        return $id > 0 ? DB::row('SELECT * FROM {{comments}} WHERE id = :id', ['id' => $id]) : null;
    }

    /**
     * Approved comments of an item, threaded one level deep: top-level comments each with a "replies" list.
     * Replies whose parent is not visible (trashed, spam…) are shown as top-level comments.
     */
    public static function thread(int $postId, string $order = 'oldest'): array
    {
        $rows = DB::all("SELECT id, post_id, parent_id, author_name, author_url, user_id, content, created_at FROM {{comments}}
            WHERE post_id = :p AND status = 'approved' ORDER BY created_at ASC, id ASC LIMIT 2000", ['p' => $postId]);
        $byId = [];
        foreach ($rows as $r) {
            $r['replies'] = [];
            $byId[(int)$r['id']] = $r;
        }
        $top = [];
        foreach ($byId as $id => $r) {
            $parent = (int)($r['parent_id'] ?? 0);
            if ($parent && isset($byId[$parent]) && !(int)($byId[$parent]['parent_id'] ?? 0)) {
                continue;
            }
            $top[] = $id;
        }
        foreach ($byId as $id => $r) {
            $parent = (int)($r['parent_id'] ?? 0);
            if ($parent && isset($byId[$parent]) && !(int)($byId[$parent]['parent_id'] ?? 0)) {
                $byId[$parent]['replies'][] = $r;
            }
        }
        $out = array_map(static fn(int $id) => $byId[$id], $top);
        return $order === 'newest' ? array_reverse($out) : $out;
    }

    /** Number of approved comments of an item (cached per request). */
    public static function approvedCount(int $postId): int
    {
        if (!array_key_exists($postId, self::$counts)) {
            try {
                self::$counts[$postId] = (int)DB::val("SELECT COUNT(*) FROM {{comments}} WHERE post_id = :p AND status = 'approved'", ['p' => $postId]);
            } catch (\PDOException) {
                self::$counts[$postId] = 0;
            }
        }
        return self::$counts[$postId];
    }

    /** Is the item open for new comments (type enabled, published, not older than the "close after" setting)? */
    public static function isOpen(array $post): bool
    {
        if (!Config::enabledFor((string)$post['type']) || ($post['status'] ?? '') !== 'publish') {
            return false;
        }
        $days = (int)Config::get('close_days');
        if ($days > 0) {
            $published = strtotime(((string)($post['published_at'] ?: $post['created_at'])) . ' UTC');
            if ($published && $published < time() - $days * 86400) {
                return false;
            }
        }
        return (bool)Hooks::filter('comments_open', true, $post);
    }

    /** Has this email address had a comment approved before? */
    public static function knownAuthor(string $email): bool
    {
        if ($email === '') {
            return false;
        }
        return (bool)DB::val("SELECT 1 FROM {{comments}} WHERE author_email = :e AND status = 'approved' LIMIT 1", ['e' => strtolower($email)]);
    }

    /** Store a new comment. Returns its id. */
    public static function create(array $c): int
    {
        $now = now();
        $id = DB::insert('comments', [
            'post_id' => (int)$c['post_id'],
            'parent_id' => !empty($c['parent_id']) ? (int)$c['parent_id'] : null,
            'author_name' => mb_substr((string)$c['author_name'], 0, self::MAX_NAME),
            'author_email' => mb_substr(strtolower((string)($c['author_email'] ?? '')), 0, self::MAX_EMAIL),
            'author_url' => mb_substr((string)($c['author_url'] ?? ''), 0, self::MAX_URL),
            'user_id' => !empty($c['user_id']) ? (int)$c['user_id'] : null,
            'content' => mb_substr((string)$c['content'], 0, self::MAX_CONTENT),
            'status' => isset(self::STATUSES[$c['status'] ?? '']) ? $c['status'] : 'pending',
            'ip' => mb_substr((string)($c['ip'] ?? ''), 0, 45),
            'user_agent' => mb_substr((string)($c['user_agent'] ?? ''), 0, 255),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        unset(self::$counts[(int)$c['post_id']]);
        if (($c['status'] ?? '') === 'approved') {
            self::purge([(int)$c['post_id']]);
        }
        return $id;
    }

    /** Edit the text/author fields of a comment. */
    public static function edit(array $comment, array $data): void
    {
        $allowed = array_intersect_key($data, array_flip(['author_name', 'author_email', 'author_url', 'content', 'status']));
        if (isset($allowed['status']) && !isset(self::STATUSES[$allowed['status']])) {
            unset($allowed['status']);
        }
        if (!$allowed) {
            return;
        }
        $allowed['updated_at'] = now();
        DB::update('comments', $allowed, ['id' => (int)$comment['id']]);
        $newStatus = $allowed['status'] ?? $comment['status'];
        if ($comment['status'] === 'approved' || $newStatus === 'approved') {
            self::purge([(int)$comment['post_id']]);
        }
    }

    /**
     * Change the status of several comments. Refreshes the cached pages of every item whose visible comments changed.
     * @param array[] $comments rows (already permission-checked)
     */
    public static function setStatus(array $comments, string $status): int
    {
        if (!isset(self::STATUSES[$status]) || !$comments) {
            return 0;
        }
        $ids = [];
        $purge = [];
        foreach ($comments as $c) {
            if ($c['status'] === $status) {
                continue;
            }
            $ids[] = (int)$c['id'];
            if ($c['status'] === 'approved' || $status === 'approved') {
                $purge[] = (int)$c['post_id'];
            }
        }
        if (!$ids) {
            return 0;
        }
        [$in, $params] = DB::in($ids, 'cid');
        DB::query("UPDATE {{comments}} SET status = :st, updated_at = :up WHERE id IN ($in)", $params + ['st' => $status, 'up' => now()]);
        self::purge($purge);
        if ($status === 'approved') {
            foreach ($comments as $c) {
                if ($c['status'] !== 'approved') {
                    Hooks::action('comment_approved', $c);
                }
            }
        }
        return count($ids);
    }

    /**
     * Delete comments permanently (their replies too).
     * @param array[] $comments rows (already permission-checked)
     */
    public static function deleteMany(array $comments): int
    {
        if (!$comments) {
            return 0;
        }
        $ids = array_map(static fn(array $c) => (int)$c['id'], $comments);
        [$in, $params] = DB::in($ids, 'cid');
        [$in2, $params2] = DB::in($ids, 'pid');
        $purge = array_map(static fn(array $c) => (int)$c['post_id'], $comments);
        $n = DB::query("DELETE FROM {{comments}} WHERE id IN ($in) OR parent_id IN ($in2)", $params + $params2)->rowCount();
        self::purge($purge);
        return $n;
    }

    /** Refresh the cached pages of these items. */
    public static function purge(array $postIds): void
    {
        $postIds = array_values(array_unique(array_filter(array_map('intval', $postIds))));
        foreach ($postIds as $id) {
            unset(self::$counts[$id]);
        }
        if (!$postIds || !class_exists(PageCache::class)) {
            return;
        }
        try {
            if (count($postIds) > 20) {
                PageCache::clear();
                return;
            }
            foreach ($postIds as $id) {
                PageCache::purgeContent(Posts::find($id));
            }
        } catch (\Throwable $e) {
            error_log('Comments cache purge: ' . $e->getMessage());
        }
    }

    // ------------------------------------------------------------------ permissions

    /** May the current user moderate comments on this item? */
    public static function canModerate(array $comment): bool
    {
        if (Auth::can('edit_others_posts')) {
            return true;
        }
        if (!Auth::can('edit_posts')) {
            return false;
        }
        $post = Posts::find((int)$comment['post_id']);
        return $post !== null && (int)$post['author_id'] === Auth::id();
    }

    /** SQL condition (alias "c") limiting comments to the items the current user may moderate. */
    public static function scopeSql(): array
    {
        if (Auth::can('edit_others_posts')) {
            return ['1 = 1', []];
        }
        return ['c.post_id IN (SELECT id FROM {{posts}} WHERE author_id = :scope_uid)', ['scope_uid' => Auth::id()]];
    }

    /** Comments by id that the current user may moderate. */
    public static function findMany(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }
        [$in, $params] = DB::in($ids, 'cid');
        [$scope, $sp] = self::scopeSql();
        return DB::all("SELECT c.* FROM {{comments}} c WHERE c.id IN ($in) AND $scope", $params + $sp);
    }

    /** Pending comments the current user can moderate (admin menu badge). */
    public static function pendingCount(): int
    {
        try {
            [$scope, $sp] = self::scopeSql();
            return (int)DB::val("SELECT COUNT(*) FROM {{comments}} c WHERE c.status = 'pending' AND $scope", $sp);
        } catch (\Throwable) {
            return 0;
        }
    }

    /** Count per status within the current user's scope. */
    public static function statusCounts(): array
    {
        [$scope, $sp] = self::scopeSql();
        $rows = DB::all("SELECT c.status, COUNT(*) AS n FROM {{comments}} c WHERE $scope GROUP BY c.status", $sp);
        $out = array_fill_keys(array_keys(self::STATUSES), 0);
        foreach ($rows as $r) {
            $out[(string)$r['status']] = (int)$r['n'];
        }
        return $out;
    }
}
