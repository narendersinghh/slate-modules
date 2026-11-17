<?php
declare(strict_types=1);

namespace SlateModules\Multilingual;

use Slate\App;
use Slate\Auth;
use Slate\ContentModel;
use Slate\DB;
use Slate\Options;
use Slate\PageCache;
use Slate\Posts;
use Slate\Session;
use Slate\Terms;

/**
 * Content languages and translation groups.
 * Each item stores its language in the custom field "_lang" (missing/empty/unknown = default language) and the id of
 * its translation group in "_tgroup" (items sharing a group are translations of each other, one per language).
 */
final class Translations
{
    public const LANG = '_lang';
    public const GROUP = '_tgroup';
    public const FIELD_GROUP_ID = 'ml_language';

    /** "type|group" => [id => post] */
    private static array $groups = [];

    public static function flush(): void
    {
        self::$groups = [];
    }

    /** Portable SQL expression for a custom field that tolerates rows whose fields column is empty or not JSON. */
    public static function col(string $column, string $name): string
    {
        $valid = DB::$driver === 'sqlite' ? "json_valid($column)" : "JSON_VALID($column)";
        return "(CASE WHEN $valid THEN " . DB::jsonField($column, $name) . ' END)';
    }

    public static function fields(array $post): array
    {
        $f = $post['fields'] ?? [];
        if (is_string($f)) {
            $f = json_decode($f, true);
        }
        return is_array($f) ? $f : [];
    }

    /** Language stored in a fields array, normalised to a configured code (else the default). */
    public static function langOfFields(array $fields): string
    {
        $l = strtolower(trim((string)(is_scalar($fields[self::LANG] ?? null) ? $fields[self::LANG] : '')));
        return $l !== '' && Languages::get($l) !== null ? $l : Languages::default();
    }

    /** Language of an item (items of non-translatable types are always in the default language). */
    public static function lang(array $post): string
    {
        if (!Languages::isTranslatable((string)($post['type'] ?? ''))) {
            return Languages::default();
        }
        return self::langOfFields(self::fields($post));
    }

    public static function group(array $post): string
    {
        $g = self::fields($post)[self::GROUP] ?? '';
        return is_string($g) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $g) ? $g : '';
    }

    public static function newGroup(): string
    {
        return bin2hex(random_bytes(8));
    }

    /** Items of a translation group (every status except trash), keyed by id. */
    public static function members(string $type, string $group): array
    {
        if ($group === '' || $type === '') {
            return [];
        }
        $key = $type . '|' . $group;
        if (!isset(self::$groups[$key])) {
            $col = self::col('p.fields', self::GROUP);
            $rows = DB::all("SELECT p.* FROM {{posts}} p WHERE p.type = :t AND p.status <> 'trash' AND $col = :g ORDER BY p.id ASC LIMIT 100",
                ['t' => $type, 'g' => $group]);
            $items = [];
            foreach ($rows as $row) {
                $p = Posts::hydrate($row);
                if ($p) {
                    $items[$p['id']] = $p;
                }
            }
            self::$groups[$key] = $items;
        }
        return self::$groups[$key];
    }

    /** Load many translation groups at once (sitemaps): fills the members() cache. */
    public static function primeGroups(string $type, array $groups): void
    {
        $todo = [];
        foreach (array_unique(array_filter(array_map('strval', $groups))) as $g) {
            if (!isset(self::$groups[$type . '|' . $g])) {
                $todo[] = $g;
                self::$groups[$type . '|' . $g] = [];
            }
        }
        $col = self::col('p.fields', self::GROUP);
        foreach (array_chunk($todo, 400) as $chunk) {
            [$in, $params] = self::inStrings($chunk, 'mg');
            $rows = DB::all("SELECT p.*, $col AS ml_g FROM {{posts}} p WHERE p.type = :t AND p.status <> 'trash' AND $col IN ($in) ORDER BY p.id ASC",
                $params + ['t' => $type]);
            foreach ($rows as $row) {
                $g = (string)$row['ml_g'];
                unset($row['ml_g']);
                if (($p = Posts::hydrate($row)) && isset(self::$groups[$type . '|' . $g])) {
                    self::$groups[$type . '|' . $g][$p['id']] = $p;
                }
            }
        }
    }

    /**
     * The item and its translations, lang => post (one per language; the item itself wins its language).
     * @param bool $publishedOnly only published items (for links shown to visitors)
     */
    public static function map(array $post, bool $publishedOnly = false): array
    {
        if (empty($post['id']) || !Languages::isTranslatable((string)($post['type'] ?? ''))) {
            return $publishedOnly && ($post['status'] ?? '') !== 'publish' ? [] : [Languages::default() => $post];
        }
        $list = [(int)$post['id'] => $post];
        $g = self::group($post);
        if ($g !== '') {
            $list += self::members((string)$post['type'], $g);
        }
        $out = [];
        foreach ($list as $p) {
            if ($publishedOnly && $p['status'] !== 'publish') {
                continue;
            }
            $out[self::lang($p)] ??= $p;
        }
        return $out;
    }

    public static function translation(array $post, string $lang, bool $publishedOnly = false): ?array
    {
        return self::map($post, $publishedOnly)[$lang] ?? null;
    }

    // ------------------------------------------------------------------ queries

    /**
     * posts_query_sql: on the front end (and in API requests with ?lang=) list only items in the current language.
     * Skipped for explicit id lookups, explicit "_lang" field filters and 'ml_all' => true.
     */
    public static function filterSql(mixed $triple, mixed $args): mixed
    {
        if (!Languages::isActive() || !is_array($triple) || count($triple) !== 3 || !is_array($args)) {
            return $triple;
        }
        $lang = Urls::queryLanguage();
        if ($lang === null || !empty($args['ids']) || !empty($args['ml_all'])
            || (isset($args['fields']) && is_array($args['fields']) && array_key_exists(self::LANG, $args['fields']))) {
            return $triple;
        }
        $types = array_values(array_unique(array_map('strval', (array)($args['type'] ?? 'post'))));
        $affected = array_values(array_intersect($types, Languages::translatableTypes()));
        if (!$affected) {
            return $triple;
        }
        [$where, $params, $joins] = $triple;
        $col = self::col('p.fields', self::LANG);
        if ($lang === Languages::default()) {
            $ph = [];
            foreach (Languages::extraCodes() as $i => $code) {
                $ph[] = ':ml_x' . $i;
                $params['ml_x' . $i] = $code;
            }
            $cond = $ph ? "($col IS NULL OR $col NOT IN (" . implode(',', $ph) . '))' : '1 = 1';
        } else {
            $params['ml_lang'] = $lang;
            $cond = "$col = :ml_lang";
        }
        if (count($affected) < count($types)) {
            $tp = [];
            foreach ($affected as $i => $t) {
                $tp[] = ':ml_t' . $i;
                $params['ml_t' . $i] = $t;
            }
            $cond = '(p.type NOT IN (' . implode(',', $tp) . ") OR $cond)";
        }
        $where[] = $cond;
        return [$where, $params, $joins];
    }

    // ------------------------------------------------------------------ editor

    /** field_groups: a "Language" box in the editor sidebar of every translatable type. */
    public static function fieldGroups(mixed $groups): mixed
    {
        if (!is_array($groups)) {
            return $groups;
        }
        unset($groups[self::FIELD_GROUP_ID]); // never keep a stale copy (core may persist filtered groups)
        if (!Languages::isActive() || !($types = Languages::translatableTypes())) {
            return $groups;
        }
        $options = [];
        foreach (Languages::all() as $code => $l) {
            $label = $l['native'] . ($l['native'] !== $l['name'] ? ', ' . $l['name'] : '');
            if ($code === Languages::default()) {
                $label .= ' (default)';
            } elseif (empty($l['enabled'])) {
                $label .= ' (disabled)';
            }
            $options[] = $code . ':' . $label;
        }
        $groups[self::FIELD_GROUP_ID] = [
            'id' => self::FIELD_GROUP_ID,
            'title' => 'Language',
            'position' => 'side',
            'location' => ['post_types' => $types, 'taxonomies' => []],
            'fields' => [[
                'name' => self::LANG,
                'label' => 'Language',
                'type' => 'select',
                'options' => $options,
                'default' => Languages::default(),
                'help' => self::editorHelp(),
            ]],
        ];
        return $groups;
    }

    /** Read-only summary of the edited item's translations (shown under the language select). */
    private static function editorHelp(): string
    {
        $base = 'Create and link translations under Multilingual › Translations.';
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET'
            || !preg_match('#^/' . preg_quote(admin_slug(), '#') . '/content/[a-z0-9_-]+/(\d+)$#', App::path(), $m)) {
            return $base;
        }
        $post = Posts::find((int)$m[1]);
        if (!$post || !Languages::isTranslatable((string)$post['type'])) {
            return $base;
        }
        $map = self::map($post);
        $have = [];
        $missing = [];
        foreach (Languages::all() as $code => $l) {
            if ((int)($map[$code]['id'] ?? 0) === (int)$post['id']) {
                continue;
            }
            if (isset($map[$code])) {
                $have[] = $l['native'] . ': “' . mb_strimwidth((string)$map[$code]['title'], 0, 40, '…') . '” (' . (Posts::STATUSES[$map[$code]['status']] ?? $map[$code]['status']) . ', #' . $map[$code]['id'] . ')';
            } else {
                $missing[] = $l['native'];
            }
        }
        $parts = [];
        $parts[] = $have ? 'Translations, ' . implode('; ', $have) . '.' : 'No translations yet.';
        if ($missing) {
            $parts[] = 'Missing: ' . implode(', ', $missing) . '.';
        }
        $parts[] = $base;
        return implode(' ', $parts);
    }

    /** post_before_save: an item cannot share its language with another item of its group, detach it instead. */
    public static function beforeSave(mixed $row, mixed $id = null): mixed
    {
        if (!is_array($row) || !Languages::isActive() || !Languages::isTranslatable((string)($row['type'] ?? ''))) {
            return $row;
        }
        $fields = json_decode((string)($row['fields'] ?? ''), true);
        if (!is_array($fields)) {
            return $row;
        }
        // Store codes exactly as configured ("ES " › "es") so SQL filters match them.
        if (isset($fields[self::LANG]) && is_string($fields[self::LANG])) {
            $clean = strtolower(trim($fields[self::LANG]));
            if ($clean !== $fields[self::LANG] && Languages::get($clean) !== null) {
                $fields[self::LANG] = $clean;
                $row['fields'] = json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }
        $g = self::group(['fields' => $fields]);
        if ($g === '') {
            return $row;
        }
        $lang = self::langOfFields($fields);
        foreach (self::members((string)$row['type'], $g) as $mid => $m) {
            if ((int)$mid !== (int)$id && self::lang($m) === $lang) {
                unset($fields[self::GROUP]);
                $row['fields'] = json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (Session::exists() || session_status() === PHP_SESSION_ACTIVE) {
                    Session::flash('warn', 'Another translation in this group already uses ' . Languages::native($lang)
                        . ', so this item was unlinked from its translations.');
                }
                break;
            }
        }
        return $row;
    }

    /** post_saved: refresh cached pages of the translations (their language switchers / hreflang changed). */
    public static function afterSave(mixed $id = null, mixed $row = null, mixed $existed = null): void
    {
        self::flush();
        if (!Languages::isActive() || !is_numeric($id) || Options::get('cache_purge', 'all') !== 'smart') {
            return;
        }
        $post = Posts::find((int)$id);
        if (!$post || ($g = self::group($post)) === '') {
            return;
        }
        $paths = [];
        foreach (self::members((string)$post['type'], $g) as $mid => $m) {
            if ((int)$mid !== (int)$post['id'] && $m['status'] === 'publish') {
                $paths[] = (string)(parse_url(Posts::permalink($m, true), PHP_URL_PATH) ?: '/');
            }
        }
        if ($paths) {
            PageCache::purgeContent($post, $paths);
        }
    }

    // ------------------------------------------------------------------ translation workflow

    /**
     * Duplicate an item as a draft in another language, linked into the same translation group.
     * @return array{ok:bool, id?:int, existed?:bool, error?:string}
     */
    public static function create(int $sourceId, string $lang): array
    {
        $src = Posts::find($sourceId);
        if (!$src || $src['status'] === 'trash') {
            return ['ok' => false, 'error' => 'That item no longer exists.'];
        }
        if (!Languages::isTranslatable((string)$src['type'])) {
            return ['ok' => false, 'error' => 'This content type is not translatable.'];
        }
        if (Languages::get($lang) === null) {
            return ['ok' => false, 'error' => 'Unknown language.'];
        }
        if (!Auth::can('edit_others_posts') && (int)$src['author_id'] !== (int)Auth::id()) {
            return ['ok' => false, 'error' => 'You can only translate your own items.'];
        }
        $map = self::map($src);
        if (isset($map[$lang])) {
            return ['ok' => true, 'id' => (int)$map[$lang]['id'], 'existed' => true];
        }

        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        $g = self::group($src);
        $srcFields = self::fields($src);
        if ($g === '' || ($srcFields[self::LANG] ?? '') === '') {
            // Link the source: give it a group and pin its language (robust if the default language changes later).
            $g = $g !== '' ? $g : self::newGroup();
            $srcFields[self::GROUP] = $g;
            $srcFields[self::LANG] = self::lang($src);
            DB::update('posts', ['fields' => json_encode($srcFields, $flags)], ['id' => (int)$src['id']]);
            Posts::flush((int)$src['id']);
            self::flush();
        }

        $terms = [];
        $current = Terms::forPosts([(int)$src['id']])[(int)$src['id']] ?? [];
        foreach (ContentModel::taxonomiesFor((string)$src['type']) as $tax => $def) {
            $terms[$tax] = array_values(array_map(static fn($t) => (int)$t['id'], (array)($current[$tax] ?? [])));
        }
        $parent = null;
        if (!empty($src['parent_id']) && ($pp = Posts::find((int)$src['parent_id']))) {
            $pt = self::translation($pp, $lang);
            $parent = $pt ? (int)$pt['id'] : (int)$pp['id'];
        }
        $fields = $srcFields;
        $fields[self::LANG] = $lang;
        $fields[self::GROUP] = $g;
        $seo = is_array($src['seo'] ?? null) ? $src['seo'] : [];
        unset($seo['canonical']);

        $newId = Posts::save([
            'type' => $src['type'],
            'title' => (string)$src['title'],
            'slug' => $src['slug'] . '-' . $lang,
            'content' => (string)$src['content'],
            'excerpt' => (string)$src['excerpt'],
            'status' => 'draft',
            'author_id' => Auth::id() ?: ($src['author_id'] ?? null),
            'parent_id' => $parent,
            'featured_media_id' => $src['featured_media_id'] ?: null,
            'template' => $src['template'] ?? null,
            'menu_order' => (int)($src['menu_order'] ?? 0),
            'fields' => $fields,
            'seo' => $seo,
            'terms' => $terms,
        ]);
        self::flush();
        log_activity('content_created', $src['type'] . ':' . $src['title'] . ' (' . $lang . ' translation)');
        return ['ok' => true, 'id' => $newId, 'existed' => false];
    }

    /**
     * Link an existing item as a translation of another one (both keep their content).
     * @return array{ok:bool, error?:string}
     */
    public static function link(int $id, int $toId): array
    {
        $item = Posts::find($id);
        $to = Posts::find($toId);
        if (!$item || !$to || $item['status'] === 'trash' || $to['status'] === 'trash' || $id === $toId) {
            return ['ok' => false, 'error' => 'Choose two different, existing items.'];
        }
        if ($item['type'] !== $to['type'] || !Languages::isTranslatable((string)$item['type'])) {
            return ['ok' => false, 'error' => 'Both items must be of the same translatable content type.'];
        }
        foreach ([$item, $to] as $p) {
            if (!Auth::can('edit_others_posts') && (int)$p['author_id'] !== (int)Auth::id()) {
                return ['ok' => false, 'error' => 'You can only link your own items.'];
            }
        }
        $lang = self::lang($item);
        if ($lang === self::lang($to)) {
            return ['ok' => false, 'error' => 'Both items are in ' . Languages::native($lang) . '. Change the language of one of them first.'];
        }
        $existing = self::translation($to, $lang);
        if ($existing && (int)$existing['id'] !== $id) {
            return ['ok' => false, 'error' => '#' . $to['id'] . ' already has a ' . Languages::native($lang) . ' translation (#' . $existing['id'] . ').'];
        }
        $g = self::group($to) ?: self::newGroup();
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        foreach ([$to, $item] as $p) {
            $fields = self::fields($p);
            $fields[self::GROUP] = $g;
            $fields[self::LANG] = self::lang($p);
            DB::update('posts', ['fields' => json_encode($fields, $flags)], ['id' => (int)$p['id']]);
            Posts::flush((int)$p['id']);
        }
        self::flush();
        PageCache::purgeContent(Posts::find($id), [(string)(parse_url(Posts::permalink(Posts::find($toId) ?? $to, true), PHP_URL_PATH) ?: '/')]);
        return ['ok' => true];
    }

    /** Remove an item from its translation group. */
    public static function unlink(int $id): bool
    {
        $post = Posts::find($id);
        if (!$post || self::group($post) === '') {
            return false;
        }
        $fields = self::fields($post);
        unset($fields[self::GROUP]);
        DB::update('posts', ['fields' => json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)], ['id' => $id]);
        Posts::flush($id);
        self::flush();
        PageCache::purgeContent(Posts::find($id));
        return true;
    }

    // ------------------------------------------------------------------ REST API

    /** api_post: add "lang" and "translations" (lang => {id, url}) to every item. */
    public static function apiPost(mixed $out, mixed $post = null, mixed $full = null): mixed
    {
        if (!is_array($out) || !is_array($post) || !Languages::isActive() || !Languages::isTranslatable((string)($post['type'] ?? ''))) {
            return $out;
        }
        $out['lang'] = self::lang($post);
        $out['translations'] = [];
        foreach (self::map($post, true) as $code => $p) {
            if (Languages::isEnabled($code)) {
                $out['translations'][$code] = ['id' => (int)$p['id'], 'url' => Posts::permalink($p, true)];
            }
        }
        if (!isset($out['translations'][$out['lang']])) {
            $out['translations'][$out['lang']] = ['id' => (int)$post['id'], 'url' => Posts::permalink($post, true)];
        }
        return $out;
    }

    // ------------------------------------------------------------------ admin statistics

    /** [type => [lang => count]] for non-trashed items of the translatable types. */
    public static function counts(): array
    {
        $types = Languages::translatableTypes();
        $out = [];
        foreach ($types as $t) {
            $out[$t] = array_fill_keys(array_keys(Languages::all()), 0);
        }
        if (!$types) {
            return $out;
        }
        [$in, $params] = self::inStrings($types, 'mt');
        $col = self::col('p.fields', self::LANG);
        $rows = DB::all("SELECT p.type AS t, $col AS l, COUNT(*) AS c FROM {{posts}} p WHERE p.type IN ($in) AND p.status <> 'trash' GROUP BY p.type, $col", $params);
        foreach ($rows as $r) {
            $lang = self::langOfFields([self::LANG => (string)($r['l'] ?? '')]);
            if (isset($out[$r['t']])) {
                $out[$r['t']][$lang] = ($out[$r['t']][$lang] ?? 0) + (int)$r['c'];
            }
        }
        return $out;
    }

    /**
     * Rows for the translations overview of one type: each row is a translation group (or a single unlinked item)
     * with lang => ['id','title','status','author_id'].
     * @return array{rows: array, truncated: bool}
     */
    public static function overview(string $type, int $max = 5000): array
    {
        $lc = self::col('p.fields', self::LANG);
        $gc = self::col('p.fields', self::GROUP);
        $rows = DB::all("SELECT p.id, p.title, p.status, p.author_id, $lc AS ml_l, $gc AS ml_g FROM {{posts}} p
            WHERE p.type = :t AND p.status <> 'trash' ORDER BY p.title ASC, p.id ASC LIMIT " . ($max + 1), ['t' => $type]);
        $truncated = count($rows) > $max;
        $rows = array_slice($rows, 0, $max);
        $groups = [];
        foreach ($rows as $r) {
            $g = (string)($r['ml_g'] ?? '');
            $key = $g !== '' && $g !== 'null' && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $g) ? 'g:' . $g : 'i:' . $r['id'];
            $lang = self::langOfFields([self::LANG => (string)($r['ml_l'] ?? '')]);
            $groups[$key] ??= [];
            $groups[$key][$lang] ??= ['id' => (int)$r['id'], 'title' => (string)$r['title'], 'status' => (string)$r['status'], 'author_id' => (int)$r['author_id']];
        }
        return ['rows' => array_values($groups), 'truncated' => $truncated];
    }

    /** IN() placeholders for strings. */
    public static function inStrings(array $values, string $prefix): array
    {
        $ph = [];
        $params = [];
        foreach (array_values($values) as $i => $v) {
            $ph[] = ':' . $prefix . $i;
            $params[$prefix . $i] = (string)$v;
        }
        return [$ph ? implode(',', $ph) : 'NULL', $params];
    }
}
