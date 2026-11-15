<?php
/**
 * Multilingual — content languages, translations, language URLs (/es/…), hreflang, language switcher,
 * template string translations and language-aware API output.
 *
 * With a single enabled language every front-end hook stays unregistered: the website behaves exactly as without
 * the module.
 */
declare(strict_types=1);

use Slate\DB;
use Slate\Frontend;
use Slate\Hooks;
use Slate\Modules;
use Slate\Options;
use Slate\PageCache;
use SlateModules\Multilingual\Admin;
use SlateModules\Multilingual\Languages;
use SlateModules\Multilingual\Strings;
use SlateModules\Multilingual\Translations;
use SlateModules\Multilingual\Urls;

require_once __DIR__ . '/src/Languages.php';
require_once __DIR__ . '/src/Translations.php';
require_once __DIR__ . '/src/Urls.php';
require_once __DIR__ . '/src/Strings.php';
require_once __DIR__ . '/src/Admin.php';

Options::lazy(Strings::OPTION);

// Editor "Language" box (also removes a stale stored copy of it when the module is idle).
Hooks::add('field_groups', [Translations::class, 'fieldGroups'], 20);

// {{languages}} {{lang}} {{t:Text}} — harmless with one language ({{t:…}} prints the text itself).
Hooks::add('template_tag', [Urls::class, 'templateTag']);

if (DB::connected() && Languages::isActive()) {
    // Routing: strip the language prefix before core (and other modules) resolve the path.
    Hooks::add('route_path', [Urls::class, 'routePath'], 1);
    Hooks::add('routes', [Urls::class, 'routes']);

    // Links.
    Hooks::add('permalink', [Urls::class, 'permalink'], 20);
    Hooks::add('term_link', [Urls::class, 'termLink'], 20);

    // Listings, archives, search and {{#query}} blocks show the visitor's language only.
    Hooks::add('posts_query_sql', [Translations::class, 'filterSql']);

    // Saving.
    Hooks::add('post_before_save', [Translations::class, 'beforeSave']);
    Hooks::add('post_saved', [Translations::class, 'afterSave']);
    Hooks::add('post_deleted', static function (): void {
        Translations::flush();
    });

    // Output: redirects to the right language, <html lang dir>, hreflang, og:locale, schema inLanguage.
    Hooks::add('html_output', [Urls::class, 'htmlOutput'], 5);
    Hooks::add('seo_head', [Urls::class, 'seoHead']);
    Hooks::add('seo_schema', [Urls::class, 'seoSchema']);
    Hooks::add('seo_title', [Urls::class, 'seoTitle']);
    Hooks::add('page_cache_kind', [Urls::class, 'pageCacheKind']);

    // REST API: ?lang=xx filters listings; every item gets "lang" and "translations".
    Hooks::add('init', [Urls::class, 'detectApi'], 1);
    Hooks::add('api_post', [Translations::class, 'apiPost']);
}

// ---------------------------------------------------------------- admin
Modules::adminPage('Multilingual', Admin::SLUG, [Admin::class, 'render'], 'edit_posts', 'globe', 'content');
Hooks::add('admin_routes', [Admin::class, 'routes']);

Hooks::add('module_activated_slate-multilingual', static function (): void {
    PageCache::clear();
});
Hooks::add('module_deactivated_slate-multilingual', static function (): void {
    $groups = Options::get('field_groups');
    if (is_array($groups) && isset($groups[Translations::FIELD_GROUP_ID])) {
        unset($groups[Translations::FIELD_GROUP_ID]);
        Options::set('field_groups', $groups);
    }
    PageCache::clear();
});

// ---------------------------------------------------------------- theme helpers

if (!function_exists('ml_lang')) {
    /** Code of the visitor's language, e.g. "en" or "es". */
    function ml_lang(): string
    {
        return Urls::current();
    }
}

if (!function_exists('ml_t')) {
    /** A fixed text in the visitor's language (Multilingual → String translations). Not escaped: use e(ml_t('…')). */
    function ml_t(string $text): string
    {
        return Strings::translate($text);
    }
}

if (!function_exists('ml_switcher')) {
    /**
     * Language switcher HTML.
     * @param array{show?:string, hide_current?:bool, hide_missing?:bool, class?:string} $opts
     */
    function ml_switcher(array $opts = []): string
    {
        return Urls::switcher($opts);
    }
}

if (!function_exists('ml_url')) {
    /** Site URL of a path in a language (default: the visitor's), e.g. ml_url('/contact/') → "/es/contact/". */
    function ml_url(string $path = '/', ?string $lang = null): string
    {
        $path = '/' . ltrim($path, '/');
        if (!Languages::isActive()) {
            return url($path);
        }
        $lang ??= Urls::current();
        return Languages::isEnabled($lang) ? url(Urls::prefixPath($path, $lang)) : url($path);
    }
}

if (!function_exists('ml_languages')) {
    /** Enabled languages: code => [code, name, native, dir, html_lang, url (home page), current]. */
    function ml_languages(): array
    {
        $out = [];
        foreach (Languages::enabled() as $code => $l) {
            $out[$code] = ['code' => $code, 'name' => $l['name'], 'native' => $l['native'], 'dir' => Languages::dir($code),
                'html_lang' => Languages::htmlLang($code), 'url' => Urls::homeUrl($code), 'current' => $code === Urls::current()];
        }
        return $out;
    }
}

if (!function_exists('ml_translations')) {
    /** Published translations of an item (default: the current one), lang => post (the item itself included). */
    function ml_translations(?array $post = null): array
    {
        $post ??= Frontend::$data['post'] ?? null;
        return is_array($post) && Languages::isActive() ? Translations::map($post, true) : [];
    }
}
