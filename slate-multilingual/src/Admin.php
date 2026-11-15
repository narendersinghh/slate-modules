<?php
declare(strict_types=1);

namespace SlateModules\Multilingual;

use Slate\Auth;
use Slate\ContentModel;
use Slate\Options;
use Slate\PageCache;
use Slate\Posts;
use Slate\Router;
use Slate\Session;

/** Dashboard screen (Multilingual): translations overview, languages, string translations. */
final class Admin
{
    public const SLUG = 'multilingual';

    public static function url(string $tab = '', array $query = []): string
    {
        if ($tab !== '') {
            $query = ['tab' => $tab] + $query;
        }
        $query = array_filter($query, static fn($v) => $v !== null && $v !== '');
        return admin_url('m/' . self::SLUG) . ($query ? '?' . http_build_query($query) : '');
    }

    public static function tabs(): array
    {
        $tabs = ['translations' => 'Translations'];
        if (Auth::can('manage_settings')) {
            $tabs['languages'] = 'Languages';
            $tabs['strings'] = 'String translations';
        }
        return $tabs;
    }

    /** Admin page renderer (Modules::adminPage). */
    public static function render(): void
    {
        $tabs = self::tabs();
        $tab = (string)($_GET['tab'] ?? '');
        if (!isset($tabs[$tab])) {
            $tab = !Languages::isActive() && isset($tabs['languages']) ? 'languages' : 'translations';
        }
        $view = static function (string $__name, array $__vars): void {
            extract($__vars, EXTR_SKIP);
            require dirname(__DIR__) . '/views/' . $__name . '.php';
        };
        $view('admin', ['tabs' => $tabs, 'tab' => $tab, 'view' => $view]);
    }

    public static function routes(mixed $r): void
    {
        if (!$r instanceof Router) {
            return;
        }
        $base = '/m/' . self::SLUG;
        $r->post($base . '/languages', [self::class, 'saveLanguages']);
        $r->post($base . '/languages/add', [self::class, 'addLanguage']);
        $r->post($base . '/strings', [self::class, 'saveStrings']);
        $r->post($base . '/translate', [self::class, 'translate']);
        $r->post($base . '/link', [self::class, 'link']);
        $r->post($base . '/unlink', [self::class, 'unlink']);
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
        $types = $current['types'];
        if (input('types_present') !== null) {
            $public = array_keys(array_filter(ContentModel::types(), static fn($t) => !empty($t['public'])));
            $types = array_values(array_intersect($public, array_map('strval', (array)input('types', []))));
        }
        if ($errors) {
            Session::flash('error', implode(' ', $errors));
            redirect(self::url('languages'));
        }
        $changedDefault = $default !== $current['default'];
        self::store(['default' => $default, 'languages' => $langs, 'types' => $types]);
        $msg = $remove !== '' && !isset($langs[$remove]) ? 'Language removed. Its content was kept and now counts as ' . Languages::native($default) . ' until you assign another language.' : 'Languages saved.';
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

    public static function saveStrings(): void
    {
        Auth::require('manage_settings');
        $rows = (array)input('rows', []);
        // PHP drops fields beyond max_input_vars: never save a cut-off form (it would delete strings).
        if (input('complete') !== '1') {
            Session::flash('error', 'The form was too large for this server (PHP max_input_vars = ' . (int)ini_get('max_input_vars')
                . '), so nothing was saved. Raise that limit or ask your host to.');
            redirect(self::url('strings'));
        }
        $n = Strings::save($rows);
        PageCache::clear();
        log_activity('settings_saved', 'multilingual strings');
        Session::flash('success', $n . ' string' . ($n === 1 ? '' : 's') . ' saved.');
        redirect(self::url('strings'));
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
            : Languages::native((string)$lang) . ' translation created as a draft — translate it and publish.');
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

    /** Return address posted by the forms (only this screen). */
    private static function back(): string
    {
        $to = (string)input('return', '');
        return $to !== '' && str_starts_with($to, admin_url('m/' . self::SLUG)) ? $to : self::url('translations');
    }
}
