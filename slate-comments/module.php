<?php
/**
 * Comments, comments on posts and any content type: moderation queue, spam protection that works with the
 * page cache, email notifications, webhooks, template tags and REST API output.
 */
declare(strict_types=1);

use Slate\Hooks;
use Slate\Modules;
use Slate\Router;
use SlateModules\Comments\Admin;
use SlateModules\Comments\Comments;
use SlateModules\Comments\Config;
use SlateModules\Comments\Front;
use SlateModules\Comments\Install;
use SlateModules\Comments\Rest;

// Table (also created by migrations/001-comments.php; this is idempotent).
Hooks::add('module_activated_slate-comments', [Install::class, 'run']);

// ---------------------------------------------------------------- front end
Hooks::add('the_content', [Front::class, 'filterContent'], 20);
Hooks::add('template_tag', [Front::class, 'templateTag']);
Hooks::add('template_lookup', [Front::class, 'templateLookup']);
Hooks::add('head', [Front::class, 'head']);
Hooks::add('html_output', [Front::class, 'htmlOutput'], 20);
Hooks::add('routes', static function (Router $r): void {
    $r->post(Front::ENDPOINT, [Front::class, 'handlePost']);
});

// Theme helper: echo slate_comments(); (current item) or slate_comments($post)
if (!function_exists('slate_comments')) {
    function slate_comments(?array $post = null): string
    {
        $post ??= function_exists('post') ? post() : null;
        return $post ? Front::render($post) : '';
    }
}

// ---------------------------------------------------------------- REST API
Hooks::add('api_post', [Rest::class, 'filterPost']);
Hooks::add('api_routes', [Rest::class, 'routes']);

// ---------------------------------------------------------------- admin
// Moderation list (Modules → Comments); the edit screen is the same page with ?edit=<id>.
Modules::adminPage('Comments', 'comments', static function (): void {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        Admin::handlePost();
    }
    require __DIR__ . '/views/admin.php';
}, 'edit_posts', 'message', 'main', [Comments::class, 'pendingCount']);

// Settings → Modules → Comments
Config::register();
