<?php
declare(strict_types=1);

namespace SlateModules\Multilingual;

use Slate\AdminUi;
use Slate\Auth;
use Slate\ContentModel;
use Slate\Modules;
use Slate\Options;
use Slate\PageCache;
use Slate\Posts;
use Slate\Router;
use Slate\Session;

/**
 * Dashboard pages (sidebar group "Multilingual"): Overview, Translations, Strings, Languages and Settings.
 * Form posts go to /m/multilingual/<action> (see routes()).
 */
final class Admin
{
    public const SLUG = 'multilingual';

    /** page key => [label, capability, icon] ('' = overview, the group's first page). */
    private const PAGES = [
        '' => ['Overview', 'edit_posts', 'activity'],
        'translations' => ['Translations', 'edit_posts', 'translate'],
        'strings' => ['Strings', 'manage_settings', 'type'],
        'languages' => ['Languages', 'manage_settings', 'globe'],
    ];

    /** Register the dashboard pages (module.php). */
    public static function register(): void
    {
        foreach (self::PAGES as $key => [$label, $cap, $icon]) {
            Modules::adminPage($label, self::slug($key), [self::class, $key === '' ? 'overview' : $key], $cap, $icon);
        }
        Modules::adminPage('Settings', self::slug('settings'), [self::class, 'settings'], 'manage_settings', 'settings', 'settings');
    }

    private static function slug(string $page): string
    {
        return $page === '' ? self::SLUG : self::SLUG . '-' . $page;
    }

    /** Dashboard URL of a page ('' = overview, 'translations', 'strings', 'languages', 'settings'). */
    public static function url(string $page = '', array $query = []): string
    {
        $query = array_filter($query, static fn($v) => $v !== null && $v !== '' && $v !== []);
        return admin_url('m/' . self::slug($page)) . ($query ? '?' . http_build_query($query) : '');
    }

    /** Page tabs are drawn by the dashboard for every module (Slate 0.13), so nothing to print here. */
    public static function nav(string $current): string
    {
        return '';
        $tabs = [];
        foreach (self::PAGES + ['settings' => ['Settings', 'manage_settings', 'settings']] as $key => [$label, $cap]) {
            if (Auth::can($cap)) {
                $tabs[$key === '' ? 'overview' : $key] = [$label, self::url($key)];
            }
        }
        return AdminUi::tabs($tabs, $current);
    }

    private static function view(string $name, array $vars = []): void
    {
        (static function (string $__file, array $__vars): void {
            extract($__vars, EXTR_SKIP);
            require $__file;
        })(dirname(__DIR__) . '/views/' . $name . '.php', $vars);
    }

    /** Form handlers (admin_routes). */
    public static function routes(mixed $r): void
    {
        if (!$r instanceof Router) {
            return;
        }
        $base = '/m/' . self::SLUG;
        $r->post($base . '/languages', [self::class, 'saveLanguages']);
        $r->post($base . '/languages/add', [self::class, 'addLanguage']);
        $r->post($base . '/strings', [self::class, 'saveStrings']);
        $r->post($base . '/strings/add', [self::class, 'addString']);
        $r->post($base . '/types', [self::class, 'saveTypes']);
        $r->post($base . '/translate', [self::class, 'translate']);
        $r->post($base . '/link', [self::class, 'link']);
        $r->post($base . '/unlink', [self::class, 'unlink']);
    }

    // ------------------------------------------------------------------ pages

    public static function overview(): void
    {
        // Addresses from before 1.2 (one page with ?tab=).
        $old = (string)($_GET['tab'] ?? '');
        if (in_array($old, ['translations', 'strings', 'languages'], true)) {
            $q = $_GET;
            unset($q['tab']);
            redirect(self::url($old, $q));
        }
        self::view('overview');
    }

    public static function translations(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            self::bulkTranslate();
        }
        self::view('translations');
    }

    public static function strings(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && input('action') === 'delete') {
            $n = Strings::delete(array_map('strval', (array)input('ids', [])));
            PageCache::clear();
            Session::flash('success', $n . ' string' . ($n === 1 ? '' : 's') . ' deleted.');
            redirect(self::url('strings'));
        }
        self::view('strings');
    }

    public static function languages(): void
    {
        self::view('languages');
    }

    public static function settings(): void
    {
        self::view('settings');
    }

    /**
     * Translation progress per content type: type => ['items' => groups, 'langs' => [code => groups with that language]].
     * An "item" is a piece of content together with its translations.
     */
    public static function coverage(): array
    {
        $out = [];
        foreach (Languages::translatableTypes() as $type) {
            $rows = Translations::overview($type)['rows'];
            $langs = array_fill_keys(array_keys(Languages::all()), 0);
            foreach ($rows as $row) {
                foreach ($row as $code => $item) {
                    if (isset($langs[$code])) {
                        $langs[$code]++;
                    }
                }
            }
            $out[$type] = ['items' => count($rows), 'langs' => $langs];
        }
        return $out;
    }

    /** Bulk action of the Translations list: create_<lang> makes draft translations of the selected items. */
    private static function bulkTranslate(): void
    {
        $action = (string)input('action', '');
        $lang = str_starts_with($action, 'create_') ? substr($action, 7) : '';
        $back = self::back();
        if ($lang === '' || Languages::get($lang) === null) {
            redirect($back);
        }
        $made = $had = 0;
        $errors = [];
        foreach (array_slice(array_unique(array_map('intval', (array)input('ids', []))), 0, 200) as $id) {
            $res = Translations::create($id, $lang);
            if (!$res['ok']) {
                $errors[$res['error'] ?? 'Error'] = true;
            } elseif (!empty($res['existed'])) {
                $had++;
            } else {
                $made++;
            }
        }
        $native = Languages::native($lang);
        Session::flash('success', $made . ' ' . $native . ' draft' . ($made === 1 ? '' : 's') . ' created'
            . ($had ? ' (' . $had . ' already existed)' : '') . '.' . ($made ? ' Open each one to translate it, then publish.' : ''));
        if ($errors) {
            Session::flash('error', implode(' ', array_keys($errors)));
        }
        redirect($back);
    }

    // ------------------------------------------------------------------ languages

    public static function saveLanguages(): void
    {
        Auth::require('manage_settings');
        $current = Languages::settings();
        $posted = (array)input('langs', []);
        $remove = (string)input('remove', '');
        $default = Languages::cleanCode((string)input('default', $current['default'])) ?? $current['default'];
        if (!isset($current['languages'][$default]) || $default === $remove) {
            $default = $current['default'];
        }
        $langs = [];
        $errors = [];
        foreach ($current['languages'] as $code => $l) {
            if ($code === $remove && $code !== $default) {
                continue;
            }
            $p = is_array($posted[$code] ?? null) ? $posted[$code] : [];
            $prefixIn = trim((string)($p['prefix'] ?? $l['prefix']));
            $prefix = $prefixIn === '' ? $code : Languages::cleanPrefix($prefixIn);
            if ($prefix === null && $code !== $default) {
                $errors[] = '“' . $prefixIn . '” is not a valid URL prefix for ' . $l['native'] . ' (use lowercase letters, numbers and dashes).';
                $prefix = $l['prefix'] ?: $code;
            }
            $langs[$code] = [
                'code' => $code,
                'name' => (string)($p['name'] ?? $l['name']),
                'native' => (string)($p['native'] ?? $l['native']),
                'prefix' => (string)$prefix,
                // The old default row has no checkbox: it stays enabled when another language becomes the default.
                'enabled' => $code === $default || !empty($p['enabled']) || ($code === $current['default'] && !array_key_exists('enabled', $p)),
                'dir' => (string)($p['dir'] ?? $l['dir']),
                'locale' => (string)($p['locale'] ?? $l['locale']),
            ];
        }
        $errors = array_merge($errors, self::validatePrefixes($langs, $default));
        // Order: "move" = "<code>:up" / "<code>:down" (the default language always comes first).
        [$moveCode, $moveDir] = array_pad(explode(':', (string)input('move', ''), 2), 2, '');
        if (isset($langs[$moveCode])) {
            $keys = array_keys($langs);
            $i = array_search($moveCode, $keys, true);
            $j = $moveDir === 'up' ? $i - 1 : $i + 1;
            if (isset($keys[$j])) {
                [$keys[$i], $keys[$j]] = [$keys[$j], $keys[$i]];
                $langs = array_replace(array_flip($keys), $langs);
            }
        }
        $types = $current['types'];
        if ($errors) {
            Session::flash('error', implode(' ', $errors));
            redirect(self::url('languages'));
        }
        $changedDefault = $default !== $current['default'];
        self::store(['default' => $default, 'languages' => $langs, 'types' => $types]);
        $msg = $remove !== '' && !isset($langs[$remove]) ? 'Language removed. Its content was kept and now counts as ' . Languages::native($default) . ' until you assign another language.'
            : (isset($langs[$moveCode]) ? 'Order saved.' : 'Languages saved.');
        Session::flash('success', $msg);
        if ($changedDefault) {
            Session::flash('warn', 'The default language is now ' . Languages::native($default) . ': items without a language belong to it and its URLs have no prefix.');
        }
        self::clashWarnings($langs, $default);
        redirect(self::url('languages'));
    }

    public static function addLanguage(): void
    {
        Auth::require('manage_settings');
        $current = Languages::settings();
        $code = Languages::cleanCode((string)(input('code', '') ?: input('preset', '')));
        if ($code === null) {
            Session::flash('error', 'Choose a language or enter a valid code such as “es”, “pt-br” or “zh-hans”.');
            redirect(self::url('languages'));
        }
        if (isset($current['languages'][$code])) {
            Session::flash('error', Languages::native($code) . ' is already configured.');
            redirect(self::url('languages'));
        }
        $preset = Languages::PRESETS[$code] ?? null;
        $prefixIn = trim((string)input('prefix', ''));
        $prefix = $prefixIn === '' ? $code : Languages::cleanPrefix($prefixIn);
        if ($prefix === null) {
            Session::flash('error', '“' . $prefixIn . '” is not a valid URL prefix (use lowercase letters, numbers and dashes).');
            redirect(self::url('languages'));
        }
        $langs = $current['languages'];
        $langs[$code] = [
            'code' => $code,
            'name' => trim((string)input('name', '')) ?: ($preset[0] ?? strtoupper($code)),
            'native' => trim((string)input('native', '')) ?: ($preset[1] ?? strtoupper($code)),
            'prefix' => $prefix,
            'enabled' => (bool)input('enabled', false),
            'dir' => 'auto',
            'locale' => '',
        ];
        if ($errors = self::validatePrefixes($langs, $current['default'])) {
            Session::flash('error', implode(' ', $errors));
            redirect(self::url('languages'));
        }
        self::store(['default' => $current['default'], 'languages' => $langs, 'types' => $current['types']]);
        Session::flash('success', Languages::native($code) . ' added' . ($langs[$code]['enabled'] ? ' and enabled at ' . url(Languages::prefix($code) . '/') : ' (disabled: enable it when its content is ready)') . '.');
        self::clashWarnings([$code => $langs[$code]], $current['default']);
        redirect(self::url('languages'));
    }

    private static function validatePrefixes(array $langs, string $default): array
    {
        $errors = [];
        $seen = [];
        foreach ($langs as $code => $l) {
            if ($code === $default) {
                continue;
            }
            if ($problem = Languages::prefixProblem((string)$l['prefix'])) {
                $errors[] = $problem;
            }
            if (isset($seen[$l['prefix']])) {
                $errors[] = 'Two languages cannot share the URL prefix “' . $l['prefix'] . '”.';
            }
            $seen[$l['prefix']] = true;
        }
        return $errors;
    }

    private static function clashWarnings(array $langs, string $default): void
    {
        $warn = [];
        foreach ($langs as $code => $l) {
            if ($code !== $default && !empty($l['enabled']) && ($clash = Languages::prefixClash((string)$l['prefix']))) {
                $warn[] = 'The prefix /' . $l['prefix'] . '/ hides existing addresses: ' . $clash . '.';
            }
        }
        if ($warn) {
            Session::flash('warn', implode(' ', $warn));
        }
    }

    private static function store(array $settings): void
    {
        Options::set(Languages::OPTION, Languages::normalize($settings));
        Languages::reset();
        PageCache::clear();
        log_activity('settings_saved', 'multilingual');
    }

    // ------------------------------------------------------------------ strings

    /** Save the translations typed in the Strings list (only the rows shown on that page). */
    public static function saveStrings(): void
    {
        Auth::require('manage_settings');
        $back = self::back('strings');
        // PHP drops fields beyond max_input_vars: never save a cut-off form.
        if (input('complete') !== '1') {
            Session::flash('error', 'The form was too large for this server (PHP max_input_vars = ' . (int)ini_get('max_input_vars')
                . '), so nothing was saved. Show fewer rows per page and try again.');
            redirect($back);
        }
        $n = Strings::update((array)input('tr', []));
        PageCache::clear();
        log_activity('settings_saved', 'multilingual strings');
        Session::flash('success', $n . ' string' . ($n === 1 ? '' : 's') . ' saved.');
        redirect($back);
    }

    public static function addString(): void
    {
        Auth::require('manage_settings');
        $source = trim((string)input('source', ''));
        if (!Strings::add($source, (array)input('tr', []))) {
            Session::flash('error', $source === '' ? 'Type the original text first.' : 'You have reached the limit of ' . Strings::MAX . ' strings.');
            redirect(self::url('strings'));
        }
        PageCache::clear();
        log_activity('settings_saved', 'multilingual strings');
        Session::flash('success', '“' . mb_strimwidth($source, 0, 60, '…') . '” saved.');
        redirect(self::url('strings'));
    }

    // ------------------------------------------------------------------ settings

    /** Settings page: which content types are translatable. */
    public static function saveTypes(): void
    {
        Auth::require('manage_settings');
        $current = Languages::settings();
        $public = array_keys(array_filter(ContentModel::types(), static fn($t) => !empty($t['public'])));
        $types = array_values(array_intersect($public, array_map('strval', (array)input('types', []))));
        self::store(['default' => $current['default'], 'languages' => $current['languages'], 'types' => $types]);
        Session::flash('success', 'Settings saved.');
        redirect(self::url('settings'));
    }

    // ------------------------------------------------------------------ translations

    public static function translate(): void
    {
        Auth::require('edit_posts');
        [$id, $lang] = array_pad(explode(':', (string)input('pair', ''), 2), 2, '');
        $res = Translations::create((int)$id, (string)$lang);
        if (!$res['ok']) {
            Session::flash('error', $res['error'] ?? 'The translation could not be created.');
            redirect(self::back());
        }
        $post = Posts::find((int)$res['id']);
        if (!$post) {
            redirect(self::back());
        }
        Session::flash('success', !empty($res['existed'])
            ? 'This ' . Languages::native((string)$lang) . ' translation already exists.'
            : Languages::native((string)$lang) . ' translation created as a draft, translate it and publish.');
        redirect(admin_url('content/' . $post['type'] . '/' . $post['id']));
    }

    public static function link(): void
    {
        Auth::require('edit_posts');
        $res = Translations::link((int)input('id', 0), (int)input('to', 0));
        Session::flash($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Items linked as translations.' : ($res['error'] ?? 'Could not link the items.'));
        redirect(self::back());
    }

    public static function unlink(): void
    {
        Auth::require('edit_posts');
        $post = Posts::find((int)input('id', 0));
        if ($post && (Auth::can('edit_others_posts') || (int)$post['author_id'] === (int)Auth::id()) && Translations::unlink((int)$post['id'])) {
            Session::flash('success', '“' . $post['title'] . '” is no longer linked to its translations.');
        } else {
            Session::flash('error', 'Could not unlink that item.');
        }
        redirect(self::back());
    }

    /** Return address posted by the forms (only this module's pages). */
    private static function back(string $page = 'translations'): string
    {
        $to = (string)input('return', '');
        return $to !== '' && str_starts_with($to, admin_url('m/' . self::SLUG)) && !preg_match('/[\r\n]/', $to) ? $to : self::url($page);
    }
}
