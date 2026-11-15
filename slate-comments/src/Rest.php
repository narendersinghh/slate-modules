<?php
declare(strict_types=1);

namespace SlateModules\Comments;

use Slate\Api;
use Slate\ContentModel;
use Slate\Hooks;
use Slate\Posts;
use Slate\Router;

/** REST API: "comments_count" on items, and GET /api/v1/comments/{postId}. Never exposes emails or IP addresses. */
final class Rest
{
    /** "api_post" filter. */
    public static function filterPost(array $out, array $post = [], bool $full = false): array
    {
        if (Config::get('api_count') && $post && Config::enabledFor((string)($post['type'] ?? ''))) {
            $out['comments_count'] = Comments::approvedCount((int)$post['id']);
        }
        return $out;
    }

    /** "api_routes" action. */
    public static function routes(Router $r): void
    {
        $r->get('/comments/{id:\d+}', [self::class, 'list']);
    }

    public static function list(string $id): void
    {
        $post = Posts::find((int)$id);
        $type = $post ? ContentModel::type((string)$post['type']) : null;
        $apiTypes = (array)Hooks::filter('api_types', array_filter(ContentModel::types(), static fn($t) => ($t['public'] ?? true) !== false));
        if (!$post || $post['status'] !== 'publish' || !$type || empty($type['public']) || !isset($apiTypes[$post['type']])
            || !Config::enabledFor((string)$post['type'])) {
            Api::error(404, 'not_found', 'No comments for this item.');
        }
        $iso = static fn(string $utc): string => gmdate('c', (int)strtotime($utc . ' UTC'));
        $shape = static function (array $c) use ($iso): array {
            $url = (string)($c['author_url'] ?? '');
            return [
                'id' => (int)$c['id'],
                'parent' => !empty($c['parent_id']) ? (int)$c['parent_id'] : null,
                'author' => ['name' => (string)$c['author_name'], 'url' => preg_match('#^https?://#i', $url) ? $url : null],
                'content' => (string)$c['content'],
                'content_html' => Front::formatText((string)$c['content']),
                'date' => $iso((string)$c['created_at']),
            ];
        };
        $items = [];
        $total = 0;
        foreach (Comments::thread((int)$post['id'], (string)Config::get('order')) as $c) {
            $row = $shape($c);
            $row['replies'] = array_map($shape, $c['replies']);
            $total += 1 + count($c['replies']);
            $items[] = $row;
        }
        Api::json(['post' => (int)$post['id'], 'open' => Comments::isOpen($post), 'total' => $total, 'items' => $items]);
    }
}
