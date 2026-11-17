<?php
declare(strict_types=1);

namespace SlateModules\Comments;

use Slate\ContentModel;
use Slate\Hooks;
use Slate\Modules;
use Slate\Options;

/**
 * Module settings. Each setting is its own option "comments_<key>" (edited at Settings › Modules › Comments).
 * Before 1.1.0 they were stored together in the array option "comments_settings": migrations/002-settings.php copies
 * those values over, and until it has run they are still read as a fallback.
 */
final class Config
{
    /** Option prefix of every setting. */
    public const PREFIX = 'comments_';

    /** Settings array used before 1.1.0. */
    public const LEGACY = 'comments_settings';

    private const UNSET = "\0unset";

    private static ?array $cache = null;

    public static function defaults(): array
    {
        return [
            'types' => ['post'],
            'auto_append' => true,
            'moderate' => true,
            'approve_known' => true,
            'close_days' => 0,
            'max_links' => 2,
            'blocked_words' => '',
            'notify_email' => (string)Options::get('admin_email', ''),
            'order' => 'oldest',
            'api_count' => true,
        ];
    }

    public static function all(): array
    {
        if (self::$cache === null) {
            $legacy = Options::get(self::LEGACY, []);
            $legacy = is_array($legacy) ? $legacy : [];
            $out = [];
            foreach (self::defaults() as $k => $default) {
                $v = Options::get(self::PREFIX . $k, self::UNSET);
                $out[$k] = $v !== self::UNSET ? $v : ($legacy[$k] ?? $default);
            }
            self::$cache = $out;
        }
        return self::$cache;
    }

    public static function get(string $key): mixed
    {
        return self::all()[$key] ?? null;
    }

    /** Content types with comments switched on (only types that still exist). */
    public static function types(): array
    {
        return array_values(array_filter(array_map('strval', (array)self::get('types')), static fn(string $t) => ContentModel::type($t) !== null));
    }

    public static function enabledFor(string $type): bool
    {
        return in_array($type, self::types(), true);
    }

    // ------------------------------------------------------------------ settings screen

    /** Declare the settings screen (Settings › Modules › Comments). */
    public static function register(): void
    {
        $p = self::PREFIX;
        $d = self::defaults();
        Modules::settings([
            ['type' => 'heading', 'label' => 'Display', 'help' => 'Where comments appear is chosen under "Content types" below.'],
            ['key' => $p . 'auto_append', 'type' => 'toggle', 'label' => 'Show comments below the content', 'default' => $d['auto_append'],
                'help' => 'Turn off when your templates place {{comments}} or slate_comments() themselves. Other template tags: {{comments_count}}, {{#if comments_open}}. The section is never printed twice.'],
            ['key' => $p . 'order', 'type' => 'select', 'label' => 'Order', 'default' => $d['order'], 'options' => ['oldest' => 'Oldest first', 'newest' => 'Newest first'],
                'help' => 'Replies are always shown oldest first under their comment.'],
            ['key' => $p . 'close_days', 'type' => 'number', 'label' => 'Close comments after', 'default' => $d['close_days'], 'min' => 0, 'max' => 36500, 'suffix' => 'days',
                'help' => '0 = never close. Existing comments stay visible.'],

            ['type' => 'heading', 'label' => 'Moderation & spam', 'help' => 'Every form also has a honeypot, a signed time token, a same-site check and a limit of 5 comments per 10 minutes per IP. No cookies, no third parties.'],
            ['key' => $p . 'moderate', 'type' => 'toggle', 'label' => 'Hold new comments for approval', 'default' => $d['moderate'], 'help' => 'Recommended.'],
            ['key' => $p . 'approve_known', 'type' => 'toggle', 'label' => 'Publish returning authors right away', 'default' => $d['approve_known'],
                'help' => 'When the author already has an approved comment.', 'show_if' => $p . 'moderate=1'],
            ['key' => $p . 'max_links', 'type' => 'number', 'label' => 'Links allowed', 'default' => $d['max_links'], 'min' => 0, 'max' => 100,
                'help' => 'Comments with more links than this go to Spam.'],
            ['key' => $p . 'blocked_words', 'type' => 'textarea', 'label' => 'Blocked words', 'default' => $d['blocked_words'], 'rows' => 5,
                'placeholder' => 'One word, phrase, email or IP address per line',
                'help' => 'A comment whose name, email, website, text, IP address or browser contains any of these goes to Spam.'],

            ['type' => 'heading', 'label' => 'Notifications & API'],
            ['key' => $p . 'notify_email', 'type' => 'email', 'label' => 'Email new comments to', 'default' => $d['notify_email'], 'placeholder' => 'Leave empty for no emails',
                'help' => 'Spam is never emailed. The comment.created webhook event fires too (Settings › Integrations › Webhooks).'],
            ['key' => $p . 'api_count', 'type' => 'toggle', 'label' => 'Add comments_count to REST API items', 'default' => $d['api_count'],
                'help' => 'Approved comments are also listed at /api/v1/comments/{id} (names, websites and text only, never emails or IP addresses).'],
        ], ['help' => 'Where comments appear, how they are moderated and who is told about them.', 'cap' => 'manage_settings', 'render' => [self::class, 'renderTypes']]);
        Hooks::add('settings_saved', [self::class, 'saved']);
    }

    /** Content-type picker (a list of checkboxes, which the generic fields don't offer). Printed inside the settings form. */
    public static function renderTypes(): void
    {
        $enabled = self::types();
        $types = array_filter(ContentModel::types(), static fn(array $t) => ($t['public'] ?? true) !== false);
        echo '<div class="card settings-card"><div class="setting-heading"><h3>Content types</h3><p class="muted small">Comments are open on the checked types.</p></div>'
            . '<div class="setting-row"><div class="setting-label"><label>Allow comments on</label></div><div class="setting-control">'
            . '<input type="hidden" name="s[' . self::PREFIX . 'types][]" value=""><div class="cmts-types">';
        foreach ($types as $slug => $t) {
            echo '<label class="check"><input type="checkbox" name="s[' . self::PREFIX . 'types][]" value="' . e((string)$slug) . '"'
                . (in_array((string)$slug, $enabled, true) ? ' checked' : '') . '> ' . e((string)($t['plural'] ?? $slug)) . '</label>';
        }
        echo '</div></div></div></div><style>.cmts-types{display:flex;gap:8px 18px;flex-wrap:wrap}.cmts-types .check{display:inline-flex;gap:8px;align-items:center}</style>';
    }

    /** After the generic save: store the content types and tidy the blocked-words list. */
    public static function saved(string $tab = '', string $section = ''): void
    {
        if ($tab !== 'modules' || $section !== 'slate-comments') {
            return;
        }
        $in = (array)($_POST['s'] ?? []);
        if (isset($in[self::PREFIX . 'types'])) {
            $types = array_values(array_unique(array_filter(array_map('strval', (array)$in[self::PREFIX . 'types']),
                static fn(string $t) => $t !== '' && ContentModel::type($t) !== null)));
            Options::set(self::PREFIX . 'types', $types);
        }
        $words = array_filter(array_map('trim', preg_split('/\R/', (string)Options::get(self::PREFIX . 'blocked_words', '')) ?: []), static fn(string $w) => $w !== '');
        Options::set(self::PREFIX . 'blocked_words', mb_substr(implode("\n", array_unique($words)), 0, 20000));
        self::$cache = null;
    }
}
