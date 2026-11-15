<?php
declare(strict_types=1);

namespace SlateModules\Comments;

use Slate\ContentModel;
use Slate\Options;

/** Module settings, stored in the single autoloaded option "comments_settings". */
final class Config
{
    public const OPTION = 'comments_settings';

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
            $stored = Options::get(self::OPTION, []);
            self::$cache = (is_array($stored) ? $stored : []) + self::defaults();
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

    /** Validate and store settings posted from the settings page. */
    public static function save(array $in): void
    {
        $types = array_values(array_filter(array_map('strval', (array)($in['types'] ?? [])), static fn(string $t) => ContentModel::type($t) !== null));
        $notify = trim((string)($in['notify_email'] ?? ''));
        $words = array_filter(array_map(static fn(string $w) => trim($w), preg_split('/\R/', (string)($in['blocked_words'] ?? '')) ?: []), static fn(string $w) => $w !== '');
        $value = [
            'types' => $types,
            'auto_append' => !empty($in['auto_append']),
            'moderate' => !empty($in['moderate']),
            'approve_known' => !empty($in['approve_known']),
            'close_days' => max(0, min(36500, (int)($in['close_days'] ?? 0))),
            'max_links' => max(0, min(100, (int)($in['max_links'] ?? 2))),
            'blocked_words' => mb_substr(implode("\n", array_unique($words)), 0, 20000),
            'notify_email' => filter_var($notify, FILTER_VALIDATE_EMAIL) ? $notify : '',
            'order' => ($in['order'] ?? '') === 'newest' ? 'newest' : 'oldest',
            'api_count' => !empty($in['api_count']),
        ];
        Options::set(self::OPTION, $value);
        self::$cache = null;
    }
}
