<?php
declare(strict_types=1);

namespace SlateModules\Comments;

use Slate\Auth;
use Slate\ContentModel;
use Slate\Crypto;
use Slate\DB;
use Slate\Hooks;
use Slate\Options;
use Slate\Posts;
use Slate\Seo;

/**
 * Public side: the comments section (list + form), its CSS, template tags, and the endpoint the form posts to.
 *
 * Pages are served from a full-page cache to anonymous visitors, so the form carries no session CSRF token. It is
 * protected instead by a honeypot, a signed timestamp (HMAC of post id + time), an Origin/Referer check, a per-IP
 * rate limit, length limits and content checks.
 */
final class Front
{
    public const ENDPOINT = '/comments/post';

    /** Minimum seconds between rendering the form and submitting it. */
    private const MIN_SECONDS = 3;
    /** Max comments per IP address within RATE_WINDOW seconds. */
    private const RATE_MAX = 5;
    private const RATE_WINDOW = 600;

    /** @var array<int,bool> items whose comments section was printed in this request */
    private static array $rendered = [];
    private static bool $cssPrinted = false;

    // ------------------------------------------------------------------ hooks

    /** "the_content" filter: append the section on the real single view of an enabled item. */
    public static function filterContent(string $html, array $post = []): string
    {
        if (!$post || !Config::get('auto_append') || !self::isMainItem($post) || isset(self::$rendered[(int)$post['id']])) {
            return $html;
        }
        return $html . self::render($post);
    }

    /** Is this item the one the current request is a single view of? */
    private static function isMainItem(array $post): bool
    {
        $ctx = Seo::$ctx;
        return ($ctx['kind'] ?? '') === 'single' && is_array($ctx['post'] ?? null)
            && (int)($ctx['post']['id'] ?? 0) === (int)($post['id'] ?? -1);
    }

    /** "template_tag" filter: {{comments}} and {{comments_count}}. */
    public static function templateTag(mixed $value, mixed ...$args): mixed
    {
        $name = (string)($args[0] ?? '');
        $ctx = is_array($args[2] ?? null) ? $args[2] : [];
        if ($value !== null || ($name !== 'comments' && $name !== 'comments_count')) {
            return $value;
        }
        $post = is_array($ctx['post'] ?? null) ? $ctx['post'] : null;
        if (!$post || empty($post['id'])) {
            return '';
        }
        if ($name === 'comments_count') {
            return Config::enabledFor((string)$post['type']) ? (string)Comments::approvedCount((int)$post['id']) : '';
        }
        return self::render($post);
    }

    /** "template_lookup" filter: {{#if comments_count}} and {{#if comments_open}}. */
    public static function templateLookup(mixed $value, mixed ...$args): mixed
    {
        $expr = (string)($args[0] ?? '');
        $ctx = is_array($args[1] ?? null) ? $args[1] : [];
        $post = is_array($ctx['post'] ?? null) ? $ctx['post'] : null;
        if ($value !== null || !$post || empty($post['id'])) {
            return $value;
        }
        return match ($expr) {
            'comments_count' => Config::enabledFor((string)$post['type']) ? Comments::approvedCount((int)$post['id']) : 0,
            'comments_open' => Comments::isOpen($post),
            default => $value,
        };
    }

    /** "head" action: the stylesheet, only on pages that show comments. */
    public static function head(): void
    {
        if (self::$cssPrinted) {
            return;
        }
        $ctx = Seo::$ctx;
        $single = ($ctx['kind'] ?? '') === 'single' && is_array($ctx['post'] ?? null) && Config::enabledFor((string)($ctx['post']['type'] ?? ''));
        if ($single || self::$rendered) {
            echo self::styleTag();
            self::$cssPrinted = true;
        }
    }

    /** "html_output" filter: comments printed without the head stylesheet (e.g. a list page) still get it. */
    public static function htmlOutput(string $html): string
    {
        if (!self::$rendered || self::$cssPrinted) {
            return $html;
        }
        self::$cssPrinted = true;
        $pos = stripos($html, '</head>');
        if ($pos !== false) {
            return substr($html, 0, $pos) . self::styleTag() . substr($html, $pos);
        }
        $pos = strpos($html, '<section id="comments"');
        return $pos !== false ? substr($html, 0, $pos) . self::styleTag() . substr($html, $pos) : $html;
    }

    private static function styleTag(): string
    {
        $css = (string)@file_get_contents(dirname(__DIR__) . '/assets/comments.css');
        $css = trim((string)preg_replace(['#/\*.*?\*/#s', '/\s+/'], ['', ' '], $css));
        return $css === '' ? '' : '<style id="cmts-css">' . $css . '</style>' . "\n";
    }

    // ------------------------------------------------------------------ rendering

    /** The full comments section of an item ('' when comments are off for its type, or already printed). */
    public static function render(array $post): string
    {
        $id = (int)($post['id'] ?? 0);
        if (!$id || isset(self::$rendered[$id]) || !Config::enabledFor((string)($post['type'] ?? ''))) {
            return '';
        }
        self::$rendered[$id] = true;
        try {
            $thread = Comments::thread($id, (string)Config::get('order'));
        } catch (\PDOException $e) {
            error_log('Comments: ' . $e->getMessage());
            return '';
        }
        $count = 0;
        foreach ($thread as $c) {
            $count += 1 + count($c['replies']);
        }
        $open = Comments::isOpen($post);
        $permalink = Posts::permalink($post);

        $h = '<section id="comments" class="cmts" aria-labelledby="cmts-title">';
        $h .= '<h2 id="cmts-title" class="cmts-title">' . ($count === 0 ? 'Comments' : ($count === 1 ? '1 comment' : number_format($count) . ' comments')) . '</h2>';
        $h .= self::notice();
        if ($thread) {
            $h .= '<ol class="cmts-list">';
            foreach ($thread as $c) {
                $h .= self::item($c, $post, $permalink, $open);
            }
            $h .= '</ol>';
        } elseif ($open) {
            $h .= '<p class="cmts-empty">No comments yet. Be the first to share your thoughts.</p>';
        }
        if ($open) {
            $h .= self::form($post, self::replyTarget($id));
        } elseif (($post['status'] ?? '') === 'publish') {
            $h .= '<p class="cmts-closed">Comments are closed.</p>';
        }
        $h .= '</section>';
        return (string)Hooks::filter('comments_html', $h, $post);
    }

    /** Message after posting (?comment=pending|ok). The query string bypasses the page cache. */
    private static function notice(): string
    {
        return match ((string)($_GET['comment'] ?? '')) {
            'pending' => '<p class="cmts-notice" role="status">Thank you! Your comment is awaiting moderation and will appear once it has been approved.</p>',
            'ok' => '<p class="cmts-notice" role="status">Thank you! Your comment has been published.</p>',
            default => '',
        };
    }

    /** ?reply=ID → the approved top-level comment being answered. */
    private static function replyTarget(int $postId): ?array
    {
        $rid = (int)($_GET['reply'] ?? 0);
        if ($rid <= 0) {
            return null;
        }
        $c = Comments::find($rid);
        if (!$c || (int)$c['post_id'] !== $postId || $c['status'] !== 'approved') {
            return null;
        }
        if (!empty($c['parent_id'])) {
            $p = Comments::find((int)$c['parent_id']);
            $c = $p && $p['status'] === 'approved' && (int)$p['post_id'] === $postId ? $p : $c;
        }
        return $c;
    }

    private static function item(array $c, array $post, string $permalink, bool $open, bool $isReply = false): string
    {
        $cid = (int)$c['id'];
        $byAuthor = !empty($c['user_id']) && (int)$c['user_id'] === (int)($post['author_id'] ?? 0);
        $h = '<li id="comment-' . $cid . '" class="cmts-item' . ($byAuthor ? ' cmts-by-author' : '') . '">';
        $h .= '<article class="cmts-body">';
        $h .= '<header class="cmts-meta">' . self::avatar((string)$c['author_name']);
        $h .= '<span class="cmts-author">' . self::authorName($c) . ($byAuthor ? ' <span class="cmts-badge">Author</span>' : '') . '</span>';
        $iso = gmdate('c', (int)strtotime($c['created_at'] . ' UTC'));
        $h .= '<a class="cmts-date" href="#comment-' . $cid . '"><time datetime="' . e($iso) . '">' . e(local_date((string)$c['created_at'])) . '</time></a>';
        $h .= '</header>';
        $h .= '<div class="cmts-text">' . self::formatText((string)$c['content']) . '</div>';
        if ($open && !$isReply) {
            $h .= '<a class="cmts-reply-link" rel="nofollow" href="' . e($permalink . '?reply=' . $cid . '#respond') . '" aria-label="Reply to ' . e($c['author_name']) . '">Reply</a>';
        }
        $h .= '</article>';
        if (!empty($c['replies'])) {
            $h .= '<ol class="cmts-replies">';
            foreach ($c['replies'] as $r) {
                $h .= self::item($r, $post, $permalink, $open, true);
            }
            $h .= '</ol>';
        }
        return $h . '</li>';
    }

    private static function authorName(array $c): string
    {
        $name = trim((string)$c['author_name']) !== '' ? (string)$c['author_name'] : 'Anonymous';
        $url = (string)($c['author_url'] ?? '');
        if ($url !== '' && preg_match('#^https?://#i', $url) && filter_var($url, FILTER_VALIDATE_URL)) {
            return '<a href="' . e($url) . '" rel="nofollow ugc noopener" class="cmts-author-link">' . e($name) . '</a>';
        }
        return e($name);
    }

    /** Initials on a colour derived from the name: no external avatar service, nothing leaves the site. */
    public static function avatar(string $name): string
    {
        $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $initials = '';
        if ($words) {
            $initials = mb_substr((string)$words[0], 0, 1);
            if (count($words) > 1) {
                $initials .= mb_substr((string)end($words), 0, 1);
            }
        }
        $initials = mb_strtoupper($initials !== '' ? $initials : '?');
        $hue = hexdec(substr(md5(mb_strtolower(trim($name))), 0, 4)) % 360;
        return '<span class="cmts-avatar" style="--cmts-h:' . (int)$hue . '" aria-hidden="true">' . e($initials) . '</span>';
    }

    /** Plain text → escaped paragraphs with line breaks. No HTML, no auto-linking. */
    public static function formatText(string $text): string
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
        $out = '';
        foreach (preg_split('/\n{2,}/', $text) ?: [] as $para) {
            $para = trim($para);
            if ($para !== '') {
                $out .= '<p>' . nl2br(e($para), false) . '</p>';
            }
        }
        return $out;
    }

    /**
     * The comment form.
     * @param array $values previously typed values (after a failed submission): name, email, url, content
     */
    public static function form(array $post, ?array $replyTo = null, array $values = [], string $error = ''): string
    {
        $pid = (int)$post['id'];
        $ts = time();
        $user = Auth::user();
        $permalink = Posts::permalink($post);
        $h = '<div id="respond" class="cmts-respond">';
        $h .= '<h3 class="cmts-form-title">' . ($replyTo
            ? 'Reply to ' . e($replyTo['author_name']) . ' <a class="cmts-cancel" rel="nofollow" href="' . e($permalink . '#respond') . '">Cancel reply</a>'
            : 'Leave a comment') . '</h3>';
        if ($error !== '') {
            $h .= '<p class="cmts-error" role="alert">' . e($error) . '</p>';
        }
        $h .= '<form class="cmts-form" method="post" action="' . e(url(ltrim(self::ENDPOINT, '/'))) . '">';
        $h .= '<input type="hidden" name="cmts_post" value="' . $pid . '">';
        $h .= '<input type="hidden" name="cmts_parent" value="' . (int)($replyTo['id'] ?? 0) . '">';
        $h .= '<input type="hidden" name="cmts_ts" value="' . $ts . '">';
        $h .= '<input type="hidden" name="cmts_sig" value="' . e(self::sign($pid, $ts)) . '">';
        // Honeypot: invisible to people, irresistible to bots.
        $h .= '<div class="cmts-hp" aria-hidden="true" style="position:absolute;left:-10000px;top:auto;width:1px;height:1px;overflow:hidden">'
            . '<label for="cmts-extra-' . $pid . '">Leave this field empty</label>'
            . '<input type="text" id="cmts-extra-' . $pid . '" name="cmts_extra" value="" tabindex="-1" autocomplete="off"></div>';
        if ($user) {
            $display = (string)($user['display_name'] ?? '') !== '' ? (string)$user['display_name'] : (string)($user['username'] ?? '');
            $h .= '<p class="cmts-signed-in">Commenting as <strong>' . e($display) . '</strong>.</p>';
        } else {
            $h .= '<div class="cmts-fields">';
            $h .= '<p class="cmts-field"><label for="cmts-name-' . $pid . '">Name <span class="cmts-req" aria-hidden="true">*</span></label>'
                . '<input type="text" id="cmts-name-' . $pid . '" name="cmts_name" required maxlength="' . Comments::MAX_NAME . '" autocomplete="name" value="' . e($values['name'] ?? '') . '"></p>';
            $h .= '<p class="cmts-field"><label for="cmts-email-' . $pid . '">Email <span class="cmts-req" aria-hidden="true">*</span> <small>(never published)</small></label>'
                . '<input type="email" id="cmts-email-' . $pid . '" name="cmts_email" required maxlength="' . Comments::MAX_EMAIL . '" autocomplete="email" value="' . e($values['email'] ?? '') . '"></p>';
            $h .= '<p class="cmts-field"><label for="cmts-url-' . $pid . '">Website <small>(optional)</small></label>'
                . '<input type="url" id="cmts-url-' . $pid . '" name="cmts_url" maxlength="' . Comments::MAX_URL . '" autocomplete="url" value="' . e($values['url'] ?? '') . '"></p>';
            $h .= '</div>';
        }
        $h .= '<p class="cmts-field"><label for="cmts-content-' . $pid . '">Comment <span class="cmts-req" aria-hidden="true">*</span></label>'
            . '<textarea id="cmts-content-' . $pid . '" name="cmts_content" rows="6" required minlength="2" maxlength="' . Comments::MAX_CONTENT . '">' . e($values['content'] ?? '') . '</textarea></p>';
        $note = Config::get('moderate') ? 'Comments are reviewed before they appear. ' : '';
        $h .= '<p class="cmts-submit"><button type="submit" class="cmts-button">Post comment</button>'
            . '<small class="cmts-note">' . e($note) . 'Your email address will not be published.</small></p>';
        $h .= '</form></div>';
        return $h;
    }

    // ------------------------------------------------------------------ posting

    private static function sign(int $postId, int $ts): string
    {
        return Crypto::hmac('comments|' . $postId . '|' . $ts);
    }

    /** Oldest acceptable form: one day, or longer when cached pages live longer than that. */
    private static function maxAge(): int
    {
        return max(86400, (int)Options::get('cache_ttl', 720) * 60 + 3600);
    }

    /** Origin (or Referer) must point at this site when the browser sends one. */
    private static function sameOrigin(): bool
    {
        $src = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
        if ($src === '') {
            $src = trim((string)($_SERVER['HTTP_REFERER'] ?? ''));
            if ($src === '') {
                return true;
            }
        }
        $u = parse_url($src);
        if (!is_array($u) || empty($u['host'])) {
            return false; // includes "Origin: null"
        }
        $hostOf = static fn(array $p): string => strtolower((string)($p['host'] ?? '')) . (isset($p['port']) ? ':' . $p['port'] : '');
        $from = $hostOf($u);
        $allowed = [$hostOf(parse_url(site_url()) ?: []), strtolower((string)($_SERVER['HTTP_HOST'] ?? ''))];
        if (class_exists(\Slate\AdminAccess::class)) {
            $allowed[] = strtolower(\Slate\AdminAccess::adminHost());
        }
        return in_array($from, array_filter($allowed), true);
    }

    /** POST /comments/post */
    public static function handlePost(): never
    {
        header('Cache-Control: no-store, private');
        header('X-Robots-Tag: noindex');
        $post = Posts::find((int)($_POST['cmts_post'] ?? 0));
        $type = $post ? ContentModel::type((string)$post['type']) : null;
        if (!$post || $post['status'] !== 'publish' || !$type || empty($type['public']) || !Config::enabledFor((string)$post['type'])) {
            self::page('Not available', '<p>This page does not accept comments.</p><p><a href="' . e(url()) . '">Go to the home page</a></p>', 404);
        }
        $permalink = Posts::permalink($post);
        if (!Comments::isOpen($post)) {
            self::page('Comments are closed', '<p>Comments are closed for this page.</p><p><a href="' . e($permalink) . '">Back</a></p>', 403);
        }
        if (!self::sameOrigin()) {
            self::page('Not allowed', '<p>This comment could not be accepted. Please go back to the page and try again.</p><p><a href="' . e($permalink . '#respond') . '">Back</a></p>', 403);
        }
        // Bots fill every field: pretend everything went fine.
        if (trim((string)($_POST['cmts_extra'] ?? '')) !== '') {
            redirect($permalink . '?comment=pending#comments');
        }

        $user = Auth::user();
        $clean = static fn(mixed $v): string => trim((string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', is_string($v) ? $v : ''));
        $values = [
            'name' => $clean($_POST['cmts_name'] ?? ''),
            'email' => $clean($_POST['cmts_email'] ?? ''),
            'url' => $clean($_POST['cmts_url'] ?? ''),
            'content' => $clean($_POST['cmts_content'] ?? ''),
        ];
        $parentId = (int)($_POST['cmts_parent'] ?? 0);
        $parent = null;
        if ($parentId > 0) {
            $parent = Comments::find($parentId);
            if ($parent && !empty($parent['parent_id'])) {
                $parent = Comments::find((int)$parent['parent_id']); // one level of replies
            }
            if (!$parent || (int)$parent['post_id'] !== (int)$post['id'] || $parent['status'] !== 'approved') {
                $parent = null;
            }
        }
        $fail = static function (string $message, int $code = 422) use ($post, $parent, $values): never {
            self::page('Your comment was not posted', self::form($post, $parent, $values, $message), $code);
        };

        // Signed timestamp: proves the form came from this site, recently, and was not filled in by a script instantly.
        $ts = (int)($_POST['cmts_ts'] ?? 0);
        $sig = (string)($_POST['cmts_sig'] ?? '');
        if ($ts <= 0 || !hash_equals(self::sign((int)$post['id'], $ts), $sig)) {
            $fail('The form could not be verified. Please submit it again.', 400);
        }
        $age = time() - $ts;
        if ($age < -60 || $age > self::maxAge()) {
            $fail('The form expired. Please submit it again.', 400);
        }
        if ($age < self::MIN_SECONDS) {
            $fail('That was quick! Please wait a few seconds and submit your comment again.', 429);
        }

        $ip = Auth::ip();
        $recent = (int)DB::val('SELECT COUNT(*) FROM {{comments}} WHERE ip = :ip AND created_at > :since',
            ['ip' => $ip, 'since' => gmdate('Y-m-d H:i:s', time() - self::RATE_WINDOW)]);
        if ($recent >= self::RATE_MAX && !Auth::can('edit_posts')) {
            $fail('You are commenting too quickly. Please wait a few minutes and try again.', 429);
        }

        // Author.
        if ($user) {
            $name = (string)($user['display_name'] ?? '') !== '' ? (string)$user['display_name'] : (string)($user['username'] ?? '');
            $email = (string)($user['email'] ?? '');
            $authorUrl = '';
        } else {
            $name = $values['name'];
            $email = $values['email'];
            $authorUrl = $values['url'];
            if ($name === '') {
                $fail('Please enter your name.');
            }
            if (mb_strlen($name) > Comments::MAX_NAME) {
                $fail('Your name is too long (' . Comments::MAX_NAME . ' characters at most).');
            }
            if ($email === '' || mb_strlen($email) > Comments::MAX_EMAIL || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $fail('Please enter a valid email address.');
            }
            if ($authorUrl !== '') {
                if (!preg_match('#^https?://#i', $authorUrl)) {
                    $authorUrl = 'https://' . $authorUrl;
                }
                if (mb_strlen($authorUrl) > Comments::MAX_URL || !filter_var($authorUrl, FILTER_VALIDATE_URL)) {
                    $fail('Please enter a valid website address, or leave it empty.');
                }
            }
        }
        $content = $values['content'];
        if (mb_strlen($content) < 2) {
            $fail('Please write a comment.');
        }
        if (mb_strlen($content) > Comments::MAX_CONTENT) {
            $fail('Your comment is too long (' . number_format(Comments::MAX_CONTENT) . ' characters at most).');
        }

        $comment = [
            'post_id' => (int)$post['id'],
            'parent_id' => $parent ? (int)$parent['id'] : null,
            'author_name' => $name,
            'author_email' => $email,
            'author_url' => $authorUrl,
            'user_id' => $user ? (int)$user['id'] : null,
            'content' => $content,
            'ip' => $ip,
            'user_agent' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ];
        // Duplicate (double click, resubmitted form): go to the existing comment.
        $dup = DB::row('SELECT id, status FROM {{comments}} WHERE post_id = :p AND author_email = :e AND content = :c AND created_at > :since ORDER BY id DESC LIMIT 1',
            ['p' => (int)$post['id'], 'e' => strtolower($email), 'c' => $content, 'since' => gmdate('Y-m-d H:i:s', time() - 3600)]);
        if ($dup) {
            redirect($permalink . ($dup['status'] === 'approved' ? '?comment=ok#comment-' . (int)$dup['id'] : '?comment=pending#comments'));
        }
        $comment['status'] = self::decideStatus($comment, $post, $user);
        $id = Comments::create($comment);
        if ($comment['status'] !== 'spam') {
            Notifier::newComment($id, $comment, $post);
        }
        if ($comment['status'] === 'approved') {
            redirect($permalink . '?comment=ok#comment-' . $id);
        }
        redirect($permalink . '?comment=pending#comments');
    }

    /** approved | pending | spam */
    private static function decideStatus(array $c, array $post, ?array $user): string
    {
        if ($user && (Auth::can('edit_others_posts') || (Auth::can('edit_posts') && (int)$post['author_id'] === (int)$user['id']))) {
            $status = 'approved';
        } elseif (self::looksLikeSpam($c)) {
            $status = 'spam';
        } elseif (!Config::get('moderate')) {
            $status = 'approved';
        } elseif (Config::get('approve_known') && Comments::knownAuthor((string)$c['author_email'])) {
            $status = 'approved';
        } else {
            $status = 'pending';
        }
        $status = (string)Hooks::filter('comment_status', $status, $c, $post);
        return isset(Comments::STATUSES[$status]) && $status !== 'trash' ? $status : 'pending';
    }

    private static function looksLikeSpam(array $c): bool
    {
        $links = preg_match_all('~(?:https?://|www\.)~i', (string)$c['content']);
        if ($links > (int)Config::get('max_links')) {
            return true;
        }
        $words = array_filter(array_map('trim', preg_split('/\R/', (string)Config::get('blocked_words')) ?: []), static fn(string $w) => $w !== '');
        if ($words) {
            $haystack = implode("\n", [$c['author_name'], $c['author_email'], $c['author_url'], $c['content'], $c['ip'], $c['user_agent']]);
            foreach ($words as $w) {
                if (mb_stripos($haystack, $w) !== false) {
                    return true;
                }
            }
        }
        return false;
    }

    /** Minimal standalone page (errors after posting; never cached). */
    private static function page(string $title, string $body, int $code): never
    {
        http_response_code($code);
        header('Content-Type: text/html; charset=utf-8');
        $site = (string)Options::get('site_title', '');
        echo '<!doctype html><html lang="' . e((string)Options::get('language', 'en')) . '"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">'
            . '<title>' . e($title . ($site !== '' ? ' – ' . $site : '')) . '</title>' . self::styleTag()
            . '<style>body{font:16px/1.6 system-ui,-apple-system,"Segoe UI",sans-serif;max-width:640px;margin:8vh auto;padding:0 20px;color:#222;background:#fff}'
            . '@media (prefers-color-scheme:dark){body{color:#eee;background:#161616}}</style></head><body>'
            . '<main class="cmts"><h1 class="cmts-title">' . e($title) . '</h1>' . $body . '</main></body></html>';
        exit;
    }
}
