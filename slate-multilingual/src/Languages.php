<?php
declare(strict_types=1);

namespace SlateModules\Multilingual;

use Slate\ContentModel;
use Slate\Options;

/**
 * Language configuration (option "ml_settings"):
 *   ['default' => 'en',
 *    'languages' => ['en' => ['code','name','native','prefix','enabled','dir','locale'], 'es' => [...]],
 *    'types' => ['post', 'page'] | null (null = every public content type)]
 *
 * The module is "active" only when at least one language besides the default is enabled; otherwise every hook is a no-op.
 */
final class Languages
{
    public const OPTION = 'ml_settings';

    /** Scripts written right to left (base language codes). */
    public const RTL = ['ar', 'he', 'fa', 'ur', 'ps', 'sd', 'ug', 'yi', 'dv', 'ckb'];

    /** URL segments a language prefix may never take (system paths and pagination). */
    public const RESERVED_PREFIXES = ['admin', 'api', 'content', 'core', 'modules', 'themes', 'install', 'sitemap', 'robots', 'feed',
        'search', 'page', 'login', 'logout', 'media', 'uploads', 'cron'];

    /** code => [English name, native name, Open Graph locale] */
    public const PRESETS = [
        'en' => ['English', 'English', 'en_US'],
        'en-gb' => ['English (UK)', 'English (UK)', 'en_GB'],
        'es' => ['Spanish', 'Español', 'es_ES'],
        'fr' => ['French', 'Français', 'fr_FR'],
        'de' => ['German', 'Deutsch', 'de_DE'],
        'it' => ['Italian', 'Italiano', 'it_IT'],
        'pt' => ['Portuguese', 'Português', 'pt_PT'],
        'pt-br' => ['Portuguese (Brazil)', 'Português (Brasil)', 'pt_BR'],
        'nl' => ['Dutch', 'Nederlands', 'nl_NL'],
        'sv' => ['Swedish', 'Svenska', 'sv_SE'],
        'da' => ['Danish', 'Dansk', 'da_DK'],
        'no' => ['Norwegian', 'Norsk', 'nb_NO'],
        'fi' => ['Finnish', 'Suomi', 'fi_FI'],
        'pl' => ['Polish', 'Polski', 'pl_PL'],
        'cs' => ['Czech', 'Čeština', 'cs_CZ'],
        'ro' => ['Romanian', 'Română', 'ro_RO'],
        'hu' => ['Hungarian', 'Magyar', 'hu_HU'],
        'el' => ['Greek', 'Ελληνικά', 'el_GR'],
        'tr' => ['Turkish', 'Türkçe', 'tr_TR'],
        'ru' => ['Russian', 'Русский', 'ru_RU'],
        'uk' => ['Ukrainian', 'Українська', 'uk_UA'],
        'ar' => ['Arabic', 'العربية', 'ar_AR'],
        'he' => ['Hebrew', 'עברית', 'he_IL'],
        'fa' => ['Persian', 'فارسی', 'fa_IR'],
        'ur' => ['Urdu', 'اردو', 'ur_PK'],
        'hi' => ['Hindi', 'हिन्दी', 'hi_IN'],
        'bn' => ['Bengali', 'বাংলা', 'bn_IN'],
        'pa' => ['Punjabi', 'ਪੰਜਾਬੀ', 'pa_IN'],
        'mr' => ['Marathi', 'मराठी', 'mr_IN'],
        'gu' => ['Gujarati', 'ગુજરાતી', 'gu_IN'],
        'ta' => ['Tamil', 'தமிழ்', 'ta_IN'],
        'te' => ['Telugu', 'తెలుగు', 'te_IN'],
        'zh' => ['Chinese (Simplified)', '简体中文', 'zh_CN'],
        'zh-tw' => ['Chinese (Traditional)', '繁體中文', 'zh_TW'],
        'ja' => ['Japanese', '日本語', 'ja_JP'],
        'ko' => ['Korean', '한국어', 'ko_KR'],
        'id' => ['Indonesian', 'Bahasa Indonesia', 'id_ID'],
        'ms' => ['Malay', 'Bahasa Melayu', 'ms_MY'],
        'th' => ['Thai', 'ไทย', 'th_TH'],
        'vi' => ['Vietnamese', 'Tiếng Việt', 'vi_VN'],
    ];

    private static ?array $settings = null;
    private static ?bool $active = null;
    private static ?array $types = null;

    /** Forget memoised settings (after saving). */
    public static function reset(): void
    {
        self::$settings = null;
        self::$active = null;
        self::$types = null;
    }

    public static function settings(): array
    {
        if (self::$settings === null) {
            $stored = Options::get(self::OPTION);
            self::$settings = self::normalize(is_array($stored) ? $stored : []);
        }
        return self::$settings;
    }

    /** Clean a stored/posted settings array into the canonical shape (always contains the default language). */
    public static function normalize(array $s): array
    {
        $langs = [];
        foreach ((array)($s['languages'] ?? []) as $key => $l) {
            if (!is_array($l)) {
                continue;
            }
            $code = self::cleanCode((string)($l['code'] ?? $key));
            if ($code === null || isset($langs[$code])) {
                continue;
            }
            $langs[$code] = self::normalizeLang($code, $l);
        }
        $default = self::cleanCode((string)($s['default'] ?? ''));
        if ($default === null) {
            $default = self::cleanCode((string)Options::get('language', 'en')) ?? 'en';
        }
        if (!isset($langs[$default])) {
            $langs = [$default => self::normalizeLang($default, [])] + $langs;
        }
        $langs[$default]['enabled'] = true;
        $langs[$default]['prefix'] = '';
        // Default language first, the rest in their saved order.
        $langs = [$default => $langs[$default]] + $langs;

        $types = null;
        if (array_key_exists('types', $s) && is_array($s['types'])) {
            $types = array_values(array_unique(array_filter(array_map('strval', $s['types']), static fn($t) => (bool)preg_match('/^[a-z0-9_-]+$/', $t))));
        }
        return ['default' => $default, 'languages' => $langs, 'types' => $types];
    }

    private static function normalizeLang(string $code, array $l): array
    {
        $preset = self::PRESETS[$code] ?? null;
        $name = trim(strip_tags((string)($l['name'] ?? '')));
        $native = trim(strip_tags((string)($l['native'] ?? '')));
        $prefix = array_key_exists('prefix', $l) ? self::cleanPrefix((string)$l['prefix']) : null;
        $dir = in_array($l['dir'] ?? 'auto', ['auto', 'ltr', 'rtl'], true) ? (string)($l['dir'] ?? 'auto') : 'auto';
        $locale = trim((string)($l['locale'] ?? ''));
        if (!preg_match('/^[a-z]{2,3}(_[A-Za-z0-9]{2,8})?$/', $locale)) {
            $locale = '';
        }
        return [
            'code' => $code,
            'name' => mb_substr($name !== '' ? $name : ($preset[0] ?? strtoupper($code)), 0, 60),
            'native' => mb_substr($native !== '' ? $native : ($preset[1] ?? ($name !== '' ? $name : strtoupper($code))), 0, 60),
            'prefix' => $prefix ?? $code,
            'enabled' => !array_key_exists('enabled', $l) || !empty($l['enabled']),
            'dir' => $dir,
            'locale' => $locale,
        ];
    }

    /** Language code: "es", "pt-br", "zh-hans", "ckb". Returns null when invalid. */
    public static function cleanCode(string $code): ?string
    {
        $code = strtolower(trim(str_replace('_', '-', $code)));
        return preg_match('/^[a-z]{2,3}(-[a-z0-9]{2,8}){0,2}$/', $code) ? $code : null;
    }

    /** URL prefix: lowercase letters, numbers and dashes. Returns null when invalid. */
    public static function cleanPrefix(string $prefix): ?string
    {
        $prefix = strtolower(trim($prefix, " \t\n\r\0\x0B/"));
        return preg_match('/^[a-z0-9](?:[a-z0-9-]{0,30}[a-z0-9])?$/', $prefix) ? $prefix : null;
    }

    public static function default(): string
    {
        return self::settings()['default'];
    }

    /** Every configured language (enabled or not), default first. */
    public static function all(): array
    {
        return self::settings()['languages'];
    }

    /** Languages visitors can use, default first. */
    public static function enabled(): array
    {
        return array_filter(self::all(), static fn($l) => !empty($l['enabled']));
    }

    /** True when at least one language besides the default is enabled. Everything is a no-op otherwise. */
    public static function isActive(): bool
    {
        return self::$active ??= count(self::enabled()) > 1;
    }

    public static function get(string $code): ?array
    {
        return self::all()[$code] ?? null;
    }

    public static function isEnabled(string $code): bool
    {
        return !empty(self::all()[$code]['enabled']);
    }

    /** Configured codes other than the default (enabled or disabled). */
    public static function extraCodes(): array
    {
        return array_values(array_diff(array_keys(self::all()), [self::default()]));
    }

    /** URL prefix of a language ('' for the default language). */
    public static function prefix(string $code): string
    {
        return $code === self::default() ? '' : (string)(self::all()[$code]['prefix'] ?? $code);
    }

    /** Enabled non-default language that owns this first URL segment. */
    public static function byPrefix(string $segment): ?string
    {
        foreach (self::enabled() as $code => $l) {
            if ($code !== self::default() && $l['prefix'] !== '' && $l['prefix'] === $segment) {
                return $code;
            }
        }
        return null;
    }

    public static function dir(string $code): string
    {
        $dir = (string)(self::all()[$code]['dir'] ?? 'auto');
        if ($dir === 'ltr' || $dir === 'rtl') {
            return $dir;
        }
        return in_array(explode('-', $code)[0], self::RTL, true) ? 'rtl' : 'ltr';
    }

    /** BCP 47 form for lang/hreflang attributes: "pt-br" → "pt-BR", "zh-hans" → "zh-Hans". */
    public static function htmlLang(string $code): string
    {
        $parts = explode('-', $code);
        foreach ($parts as $i => $p) {
            if ($i === 0) {
                continue;
            }
            $parts[$i] = strlen($p) === 2 ? strtoupper($p) : (strlen($p) === 4 ? ucfirst($p) : $p);
        }
        return implode('-', $parts);
    }

    /** Open Graph locale (ll_TT). */
    public static function locale(string $code): string
    {
        $l = self::all()[$code] ?? [];
        if (!empty($l['locale'])) {
            return (string)$l['locale'];
        }
        if (isset(self::PRESETS[$code])) {
            return self::PRESETS[$code][2];
        }
        $parts = explode('-', $code);
        return isset($parts[1]) && strlen($parts[1]) === 2 ? $parts[0] . '_' . strtoupper($parts[1]) : $parts[0];
    }

    public static function name(string $code): string
    {
        return (string)(self::all()[$code]['name'] ?? strtoupper($code));
    }

    public static function native(string $code): string
    {
        return (string)(self::all()[$code]['native'] ?? strtoupper($code));
    }

    /** Public content types whose items carry a language (settings "types"; default: all public types). */
    public static function translatableTypes(): array
    {
        if (self::$types === null) {
            $public = array_keys(array_filter(ContentModel::types(), static fn($t) => !empty($t['public'])));
            $chosen = self::settings()['types'];
            self::$types = $chosen === null ? $public : array_values(array_intersect($public, $chosen));
        }
        return self::$types;
    }

    public static function isTranslatable(string $type): bool
    {
        return $type !== '' && in_array($type, self::translatableTypes(), true);
    }

    /** Problems with a prefix (reserved words, clashes with content URLs). Empty = fine. */
    public static function prefixProblem(string $prefix): ?string
    {
        if (in_array($prefix, self::RESERVED_PREFIXES, true) || $prefix === admin_slug()) {
            return "“{$prefix}” is reserved by the system and cannot be a language prefix.";
        }
        return null;
    }

    /** Content that would be hidden behind a prefix (a top-level page or a type/taxonomy base with the same name). */
    public static function prefixClash(string $prefix): ?string
    {
        foreach (ContentModel::types() as $slug => $t) {
            $base = explode('/', trim((string)($t['rewrite'] ?? $slug), '/'))[0];
            if (!empty($t['public']) && $base === $prefix && $slug !== 'page') {
                return "the “{$t['plural']}” URLs start with /{$prefix}/";
            }
        }
        foreach (ContentModel::taxonomies() as $slug => $t) {
            $base = explode('/', trim((string)($t['rewrite'] ?? $slug), '/'))[0];
            if (!empty($t['public']) && $base === $prefix) {
                return "the “{$t['plural']}” archive URLs start with /{$prefix}/";
            }
        }
        $page = \Slate\DB::val("SELECT id FROM {{posts}} WHERE type = 'page' AND slug = :s AND parent_id IS NULL AND status <> 'trash' LIMIT 1", ['s' => $prefix]);
        return $page ? "a page already uses the address /{$prefix}/" : null;
    }
}
