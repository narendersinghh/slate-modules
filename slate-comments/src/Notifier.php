<?php
declare(strict_types=1);

namespace SlateModules\Comments;

use Slate\Hooks;
use Slate\Posts;

/** Email notification and webhook for new comments. */
final class Notifier
{
    /** Absolute URL of a dashboard page, on the dashboard host when one is configured. */
    public static function adminLink(string $path): string
    {
        if (class_exists(\Slate\AdminAccess::class)) {
            return rtrim(\Slate\AdminAccess::baseUrl(), '/') . '/' . admin_slug() . '/' . ltrim($path, '/');
        }
        return site_url(admin_slug() . '/' . ltrim($path, '/'));
    }

    public static function newComment(int $id, array $comment, array $post, bool $sendEmail = true): void
    {
        if ($sendEmail) {
            try {
                self::email($comment, $post);
            } catch (\Throwable $e) {
                error_log('Comments notification: ' . $e->getMessage());
            }
        }
        if (class_exists(\Slate\Webhooks::class)) {
            try {
                \Slate\Webhooks::dispatch('comment.created', [
                    'comment' => [
                        'id' => $id,
                        'parent_id' => !empty($comment['parent_id']) ? (int)$comment['parent_id'] : null,
                        'author_name' => (string)$comment['author_name'],
                        'author_url' => (string)($comment['author_url'] ?? ''),
                        'user_id' => !empty($comment['user_id']) ? (int)$comment['user_id'] : null,
                        'content' => (string)$comment['content'],
                        'status' => (string)$comment['status'],
                        'created_at' => gmdate('c'),
                    ],
                    'post' => ['id' => (int)$post['id'], 'type' => (string)$post['type'], 'title' => (string)$post['title'], 'url' => Posts::permalink($post, true)],
                    'moderate_url' => self::adminLink('m/comments?status=' . ($comment['status'] === 'pending' ? 'pending' : 'approved')),
                ]);
            } catch (\Throwable $e) {
                error_log('Comments webhook: ' . $e->getMessage());
            }
        }
        Hooks::action('comment_created', $id, $comment, $post);
    }

    private static function email(array $comment, array $post): void
    {
        $to = trim((string)Config::get('notify_email'));
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL) || !class_exists(\Slate\Mailer::class)) {
            return;
        }
        // No email about your own comment.
        if (strcasecmp($to, (string)($comment['author_email'] ?? '')) === 0 && !empty($comment['user_id'])) {
            return;
        }
        $pending = $comment['status'] === 'pending';
        $title = (string)$post['title'] !== '' ? (string)$post['title'] : 'Untitled';
        $subject = ($pending ? 'New comment awaiting approval on “' : 'New comment on “') . str_limit($title, 80) . '”';
        $itemUrl = Posts::permalink($post, true);
        $link = self::adminLink('m/comments?status=' . ($pending ? 'pending' : 'approved'));
        $excerpt = mb_strlen((string)$comment['content']) > 600 ? mb_substr((string)$comment['content'], 0, 599) . '…' : (string)$comment['content'];
        $html = '<p><strong>' . e($comment['author_name']) . '</strong>'
            . ($comment['author_email'] !== '' ? ' &lt;' . e($comment['author_email']) . '&gt;' : '')
            . ' commented on <a href="' . e($itemUrl) . '">' . e($title) . '</a>:</p>'
            . '<blockquote style="margin:0 0 16px;padding:8px 14px;border-left:3px solid #ccc;color:#333">' . nl2br(e($excerpt)) . '</blockquote>'
            . ($pending ? '<p>This comment is waiting for your approval.</p>' : '')
            . '<p><a href="' . e($link) . '">' . ($pending ? 'Review pending comments' : 'Manage comments') . '</a></p>';
        $text = $comment['author_name'] . ' commented on “' . $title . "” ($itemUrl):\n\n" . $excerpt . "\n\n"
            . ($pending ? "This comment is waiting for your approval.\n" : '') . ($pending ? 'Review pending comments: ' : 'Manage comments: ') . $link . "\n";
        $opts = ['text' => $text];
        if (filter_var((string)$comment['author_email'], FILTER_VALIDATE_EMAIL)) {
            $opts['reply_to'] = (string)$comment['author_email'];
        }
        \Slate\Mailer::send($to, $subject, $html, $opts);
    }
}
