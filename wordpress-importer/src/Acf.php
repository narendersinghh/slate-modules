<?php
declare(strict_types=1);

namespace SlateModules\WpImport;

use Slate\ContentModel;
use SlateModules\AutoCpt\Generator;

/**
 * Advanced Custom Fields, CPT UI and ACF post types → Slate content model, and ACF values (raw post meta) → Slate fields.
 *
 * Mapping decisions (no Slate core changes needed):
 * - group            → its sub fields become top-level fields named "<group>_<sub>" (exactly ACF's meta keys);
 * - flexible content → a repeater whose first sub field "layout" holds the layout name, followed by the sub fields of all layouts;
 * - repeater         → repeater (nested repeaters too);
 * - link             → URL; oembed → URL; google map → text (address); user → text (display name);
 * - relationship / post object / page link → relationship; taxonomy → taxonomy terms; clone, message, accordion → skipped (reported).
 */
final class Acf
{
    public const TYPES = ['text' => 'text', 'textarea' => 'textarea', 'wysiwyg' => 'richtext', 'number' => 'number', 'range' => 'number', 'email' => 'email',
        'url' => 'url', 'link' => 'url', 'oembed' => 'url', 'password' => 'text', 'true_false' => 'toggle', 'date_picker' => 'date', 'date_time_picker' => 'datetime',
        'time_picker' => 'text', 'image' => 'image', 'file' => 'file', 'gallery' => 'gallery', 'relationship' => 'relation', 'post_object' => 'relation',
        'page_link' => 'relation', 'select' => 'select', 'radio' => 'radio', 'button_group' => 'radio', 'checkbox' => 'checkbox', 'color_picker' => 'color',
        'repeater' => 'repeater', 'flexible_content' => 'repeater', 'taxonomy' => 'taxonomy', 'user' => 'text', 'google_map' => 'text', 'tab' => 'tab',
        'icon_picker' => 'text'];
    public const SKIPPED = ['clone', 'message', 'accordion'];

    // ------------------------------------------------------------------ definitions

    /** Exact ACF field groups (companion export, or acf-field-group / acf-field rows from WXR / SQL). */
    public static function groups(): array
    {
        static $cache = null;
        $sig = (string)\Slate\DB::val("SELECT COUNT(*) FROM {{wpi_data}} WHERE kind IN ('acf','wpost')");
        if ($cache !== null && $cache[0] === $sig) {
            return $cache[1];
        }
        $exact = Data::get('acf', 'groups');
        $rows = self::groupsFromRows();
        if ($exact !== null && is_array($g = json_decode($exact, true)) && $g) {
            // ACF free does not load the sub fields of Pro field types (repeater, flexible content): take them from the field rows.
            $byKey = [];
            $index = static function (array $fields) use (&$index, &$byKey): void {
                foreach ($fields as $f) {
                    $byKey[(string)($f['key'] ?? '')] = $f;
                    $index((array)($f['sub_fields'] ?? []));
                    foreach ((array)($f['layouts'] ?? []) as $l) {
                        $index((array)($l['sub_fields'] ?? []));
                    }
                }
            };
            foreach ($rows as $rg) {
                $index((array)($rg['fields'] ?? []));
            }
            $fill = static function (array $fields) use (&$fill, $byKey): array {
                foreach ($fields as &$f) {
                    $row = $byKey[(string)($f['key'] ?? '')] ?? null;
                    if (in_array($f['type'] ?? '', ['repeater', 'group'], true) && empty($f['sub_fields']) && !empty($row['sub_fields'])) {
                        $f['sub_fields'] = $row['sub_fields'];
                    }
                    if (($f['type'] ?? '') === 'flexible_content' && $row && !array_filter((array)($f['layouts'] ?? []), static fn($l) => !empty($l['sub_fields']))) {
                        $f['layouts'] = $row['layouts'] ?? ($f['layouts'] ?? []);
                    }
                    if (!empty($f['sub_fields'])) {
                        $f['sub_fields'] = $fill((array)$f['sub_fields']);
                    }
                }
                unset($f);
                return $fields;
            };
            foreach ($g as &$grp) {
                $grp['fields'] = $fill((array)($grp['fields'] ?? []));
            }
            unset($grp);
            $cache = [$sig, $g];
            return $g;
        }
        $cache = [$sig, $rows];
        return $rows;
    }

    /** Field groups rebuilt from acf-field-group / acf-field rows (export file, database dump or companion). */
    private static function groupsFromRows(): array
    {
        $rows = array_filter(Data::rows('wpost'), static fn($r) => in_array($r['type'] ?? '', ['acf-field-group', 'acf-field'], true));
        if (!$rows) {
            return [];
        }
        $byParent = [];
        foreach ($rows as $r) {
            if ($r['type'] === 'acf-field' && ($r['status'] ?? 'publish') !== 'trash') {
                $byParent[(int)$r['parent']][] = $r;
            }
        }
        foreach ($byParent as &$list) {
            usort($list, static fn($a, $b) => ($a['menu_order'] ?? 0) <=> ($b['menu_order'] ?? 0));
        }
        unset($list);
        $build = static function (int $parent) use (&$build, $byParent): array {
            $out = [];
            foreach ($byParent[$parent] ?? [] as $r) {
                $f = Data::decode((string)$r['content']);
                $f = is_array($f) ? $f : [];
                $f += ['key' => $r['slug'], 'name' => $r['excerpt'], 'label' => $r['title'], 'ID' => (int)$r['id']];
                $kids = $build((int)$r['id']);
                if ($f['type'] === 'flexible_content') {
                    $layouts = [];
                    foreach ((array)($f['layouts'] ?? []) as $lk => $l) {
                        $l += ['key' => (string)$lk];
                        $l['sub_fields'] = array_values(array_filter($kids, static fn($k) => ($k['parent_layout'] ?? '') === $l['key']));
                        $layouts[] = $l;
                    }
                    $f['layouts'] = $layouts;
                } elseif ($kids) {
                    $f['sub_fields'] = $kids;
                }
                $out[] = $f;
            }
            return $out;
        };
        $groups = [];
        foreach ($rows as $r) {
            if ($r['type'] !== 'acf-field-group' || ($r['status'] ?? 'publish') === 'trash') {
                continue;
            }
            $g = Data::decode((string)$r['content']);
            $g = is_array($g) ? $g : [];
            $groups[] = $g + ['key' => $r['slug'], 'title' => $r['title'], 'fields' => $build((int)$r['id']), 'active' => ($r['status'] ?? '') !== 'acf-disabled'];
        }
        return $groups;
    }

    /** WP post types / taxonomies a group is shown on, from its location rules. */
    public static function location(array $g): array
    {
        $types = [];
        $taxes = [];
        $other = [];
        foreach ((array)($g['location'] ?? []) as $and) {
            foreach ((array)$and as $rule) {
                $p = (string)($rule['param'] ?? '');
                $v = (string)($rule['value'] ?? '');
                if (($rule['operator'] ?? '==') !== '==') {
                    continue;
                }
                match ($p) {
                    'post_type' => $types[] = $v,
                    'page_template', 'page_type', 'page', 'page_parent' => $types[] = 'page',
                    'post_category', 'post_format', 'post' => $types[] = 'post',
                    'taxonomy' => $taxes[] = $v === 'all' ? '*' : $v,
                    default => $other[] = $p,
                };
            }
        }
        return ['types' => array_values(array_unique($types)), 'taxes' => array_values(array_unique($taxes)), 'other' => array_values(array_unique($other))];
    }

    /**
     * Slate field definitions for a list of ACF fields. $note collects what could not be mapped.
     * $typeMap: WP post type => Slate slug; $taxMap: WP taxonomy => Slate slug.
     */
    public static function fields(array $acf, array $typeMap, array $taxMap, array &$note, string $prefix = '', string $labelPrefix = ''): array
    {
        $out = [];
        foreach ($acf as $f) {
            $type = (string)($f['type'] ?? 'text');
            $name = $prefix . preg_replace('/[^a-z0-9_]/', '_', strtolower((string)($f['name'] ?? '')));
            $label = $labelPrefix . trim(strip_tags((string)($f['label'] ?? $name)));
            if ($type === 'tab') {
                $out[] = self::def('tab_' . substr(md5((string)($f['key'] ?? $label)), 0, 6), $label, 'tab');
                continue;
            }
            if (in_array($type, self::SKIPPED, true) || ($name === $prefix && $type !== 'group')) {
                if ($type !== 'message' && $type !== 'accordion') {
                    $note[] = "Field “{$label}” ({$type}) has no Slate equivalent and was skipped.";
                }
                continue;
            }
            if ($type === 'group') {
                array_push($out, ...self::fields((array)($f['sub_fields'] ?? []), $typeMap, $taxMap, $note, $name . '_', $label . ': '));
                continue;
            }
            $slate = self::TYPES[$type] ?? 'text';
            if ($type === 'select' && !empty($f['multiple'])) {
                $slate = 'checkbox';
            }
            $d = self::def($name, $label, $slate);
            $d['required'] = !empty($f['required']);
            $d['help'] = trim(strip_tags((string)($f['instructions'] ?? '')));
            $d['placeholder'] = is_string($f['placeholder'] ?? null) ? $f['placeholder'] : '';
            $d['default'] = is_scalar($f['default_value'] ?? null) ? (string)$f['default_value'] : '';
            $w = (int)($f['wrapper']['width'] ?? 0);
            $d['width'] = $w <= 0 ? '100' : ($w <= 34 ? '33' : ($w <= 50 ? '50' : ($w <= 67 ? '66' : '100')));
            if (in_array($slate, ['select', 'radio', 'checkbox'], true)) {
                foreach ((array)($f['choices'] ?? []) as $v => $l) {
                    $d['options'][] = $v . ' : ' . $l;
                }
            }
            if ($slate === 'relation') {
                $pts = array_values(array_filter(array_map(static fn($t) => $typeMap[$t] ?? null, (array)($f['post_type'] ?? []))));
                $d['post_type'] = implode(',', $pts);
            }
            if ($slate === 'taxonomy') {
                $d['taxonomy'] = $taxMap[(string)($f['taxonomy'] ?? '')] ?? '';
                if ($d['taxonomy'] === '') {
                    $d['type'] = 'text';
                }
            }
            if ($type === 'repeater') {
                $d['sub_fields'] = self::fields((array)($f['sub_fields'] ?? []), $typeMap, $taxMap, $note);
            }
            if ($type === 'flexible_content') {
                $layouts = (array)($f['layouts'] ?? []);
                $sub = [self::def('layout', 'Layout', 'select') + ['options' => array_map(static fn($l) => ($l['name'] ?? '') . ' : ' . ($l['label'] ?? $l['name'] ?? ''), $layouts)]];
                $seen = ['layout' => true];
                foreach ($layouts as $l) {
                    foreach (self::fields((array)($l['sub_fields'] ?? []), $typeMap, $taxMap, $note) as $sf) {
                        if (!isset($seen[$sf['name']])) {
                            $seen[$sf['name']] = true;
                            $sf['help'] = trim(($sf['help'] ?? '') . ' (layout: ' . ($l['label'] ?? $l['name']) . ')');
                            $sub[] = $sf;
                        }
                    }
                }
                $d['sub_fields'] = $sub;
                $note[] = "Flexible content “{$label}” became a repeater with a “Layout” column.";
            }
            $out[] = $d;
        }
        return $out;
    }

    private static function def(string $name, string $label, string $type): array
    {
        return ['name' => $name, 'label' => $label, 'type' => $type, 'required' => false, 'help' => '', 'placeholder' => '', 'default' => '', 'width' => '100',
            'options' => [], 'post_type' => ''];
    }

    /**
     * Save Slate field groups for the ACF groups (id "acf_<key>", updated on every run). Returns log lines.
     * $spec: the scan spec (wp_map) to translate post type and taxonomy slugs.
     */
    public static function apply(array $spec): array
    {
        $typeMap = array_map(static fn($m) => $m['slug'], (array)($spec['wp_map']['types'] ?? []));
        $taxMap = array_map(static fn($m) => $m['slug'], (array)($spec['wp_map']['taxonomies'] ?? []));
        $log = [];
        foreach (self::groups() as $g) {
            if (isset($g['active']) && !$g['active']) {
                continue;
            }
            $loc = self::location($g);
            $types = array_values(array_filter(array_map(static fn($t) => $typeMap[$t] ?? null, $loc['types']), static fn($s) => $s && ContentModel::type($s)));
            $taxes = in_array('*', $loc['taxes'], true) ? array_keys(ContentModel::taxonomies())
                : array_values(array_filter(array_map(static fn($t) => $taxMap[$t] ?? null, $loc['taxes']), static fn($s) => $s && ContentModel::taxonomy($s)));
            $note = [];
            $fields = self::fields((array)($g['fields'] ?? []), $typeMap, $taxMap, $note);
            if (!$fields) {
                continue;
            }
            $id = 'acf_' . preg_replace('/[^a-z0-9_]/', '', strtolower((string)($g['key'] ?? md5((string)$g['title']))));
            ContentModel::saveFieldGroup(['id' => $id, 'title' => strip_tags((string)($g['title'] ?? 'Fields')),
                'position' => ($g['position'] ?? '') === 'side' ? 'side' : 'main', 'location' => ['post_types' => $types, 'taxonomies' => $taxes],
                'fields' => $fields, 'source' => 'acf']);
            $where = implode(', ', array_merge($types, $taxes)) ?: 'nowhere yet';
            $log[] = "ACF field group “{$g['title']}”: " . count($fields) . " field(s) on $where" . ($loc['other'] ? ' (rules not mapped: ' . implode(', ', $loc['other']) . ')' : '');
            foreach (array_unique($note) as $n) {
                $log[] = '  ' . $n;
            }
        }
        return $log;
    }

    /** Names of all ACF fields stored at the top level (for de-duplicating inferred fields). */
    public static function topNames(): array
    {
        $names = [];
        $walk = static function (array $fields, string $prefix) use (&$walk, &$names): void {
            foreach ($fields as $f) {
                $n = $prefix . strtolower((string)($f['name'] ?? ''));
                if (($f['type'] ?? '') === 'group') {
                    $walk((array)($f['sub_fields'] ?? []), $n . '_');
                }
                $names[$n] = true;
            }
        };
        foreach (self::groups() as $g) {
            $walk((array)($g['fields'] ?? []), '');
        }
        return array_keys($names);
    }

    /** ACF fields (raw definitions) that apply to a WP post type. */
    public static function fieldsForType(string $wpType): array
    {
        static $cache = [];
        if (isset($cache[$wpType])) {
            return $cache[$wpType];
        }
        $out = [];
        foreach (self::groups() as $g) {
            if (in_array($wpType, self::location($g)['types'], true)) {
                array_push($out, ...(array)($g['fields'] ?? []));
            }
        }
        return $cache[$wpType] = $out;
    }

    public static function fieldsForTax(string $wpTax): array
    {
        $out = [];
        foreach (self::groups() as $g) {
            $t = self::location($g)['taxes'];
            if (in_array($wpTax, $t, true) || in_array('*', $t, true)) {
                array_push($out, ...(array)($g['fields'] ?? []));
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------ values

    /**
     * Slate field values from raw ACF meta. $cb: ['media' => fn(id|url): ?int, 'url' => fn(string): string, 'html' => fn(string): string,
     * 'term' => fn(string wpTax, int id): ?int, 'user' => fn(int id): string].
     */
    public static function values(array $acf, array $meta, array $cb, string $prefix = '', string $namePrefix = ''): array
    {
        $out = [];
        foreach ($acf as $f) {
            $type = (string)($f['type'] ?? 'text');
            $raw = strtolower((string)($f['name'] ?? ''));
            if ($raw === '' || in_array($type, array_merge(self::SKIPPED, ['tab']), true)) {
                continue;
            }
            $key = $prefix . ($f['name'] ?? '');
            $name = $namePrefix . preg_replace('/[^a-z0-9_]/', '_', $raw);
            if ($type === 'group') {
                $out += self::values((array)($f['sub_fields'] ?? []), $meta, $cb, $key . '_', $name . '_');
                continue;
            }
            if (!array_key_exists($key, $meta)) {
                continue;
            }
            $out[$name] = self::value($f, $key, $meta, $cb);
        }
        return $out;
    }

    private static function value(array $f, string $key, array $meta, array $cb): mixed
    {
        $v = Data::decode(isset($meta[$key]) ? (string)$meta[$key] : null);
        switch ((string)($f['type'] ?? 'text')) {
            case 'repeater':
                $rows = [];
                for ($i = 0, $n = min(500, (int)(is_numeric($v) ? $v : 0)); $i < $n; $i++) {
                    $rows[] = self::values((array)($f['sub_fields'] ?? []), $meta, $cb, "{$key}_{$i}_");
                }
                return $rows;
            case 'flexible_content':
                $rows = [];
                $layouts = [];
                foreach ((array)($f['layouts'] ?? []) as $l) {
                    $layouts[(string)($l['name'] ?? '')] = (array)($l['sub_fields'] ?? []);
                }
                foreach (array_values(is_array($v) ? $v : []) as $i => $layout) {
                    $rows[] = ['layout' => (string)$layout] + self::values($layouts[(string)$layout] ?? [], $meta, $cb, "{$key}_{$i}_");
                }
                return $rows;
            case 'image':
            case 'file':
                return $v !== '' && $v !== null ? ($cb['media'])($v) : null;
            case 'gallery':
                return array_values(array_filter(array_map($cb['media'], is_array($v) ? $v : array_filter(explode(',', (string)$v)))));
            case 'relationship':
            case 'post_object':
            case 'page_link':
                $ids = is_array($v) ? $v : ($v !== '' && $v !== null ? [$v] : []);
                return array_values(array_filter(array_map(static fn($x) => is_numeric($x) ? 'old:' . (int)$x : null, $ids)));
            case 'taxonomy':
                $ids = is_array($v) ? $v : ($v !== '' && $v !== null ? [$v] : []);
                return array_values(array_filter(array_map(static fn($x) => is_numeric($x) ? ($cb['term'])((string)($f['taxonomy'] ?? ''), (int)$x) : null, $ids)));
            case 'true_false':
                return !empty($v);
            case 'number':
            case 'range':
                return is_numeric($v) ? $v + 0 : null;
            case 'date_picker':
                $v = (string)$v;
                return preg_match('/^(\d{4})(\d{2})(\d{2})$/', $v, $d) ? "$d[1]-$d[2]-$d[3]" : (preg_match('/^\d{4}-\d{2}-\d{2}/', $v) ? substr($v, 0, 10) : '');
            case 'date_time_picker':
                return preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/', (string)$v) ? substr((string)$v, 0, 16) : (string)$v;
            case 'link':
                return ($cb['url'])(is_array($v) ? (string)($v['url'] ?? '') : (string)$v);
            case 'url':
            case 'oembed':
                return ($cb['url'])((string)$v);
            case 'wysiwyg':
                return ($cb['html'])((string)$v);
            case 'checkbox':
                return array_values(array_map('strval', is_array($v) ? $v : ($v !== '' && $v !== null ? [$v] : [])));
            case 'select':
                return !empty($f['multiple']) ? array_values(array_map('strval', (array)$v)) : (is_array($v) ? (string)reset($v) : (string)$v);
            case 'google_map':
                return is_array($v) ? (string)($v['address'] ?? json_encode($v)) : (string)$v;
            case 'user':
                $first = is_array($v) ? reset($v) : $v;
                return is_numeric($first) ? ($cb['user'])((int)$first) : (string)$first;
            default:
                return is_scalar($v) ? (string)$v : (is_array($v) ? implode(', ', array_filter($v, 'is_scalar')) : '');
        }
    }

    // ------------------------------------------------------------------ post types / taxonomies (CPT UI, ACF 6.1+)

    /**
     * Definitions registered by CPT UI or ACF: ['types' => [wpSlug => def], 'taxes' => [wpSlug => def], 'source' => [...]].
     * def: singular, plural, rewrite, hierarchical, has_archive, supports[], description, object_types[] (taxonomies).
     */
    public static function registered(): array
    {
        $types = [];
        $taxes = [];
        $src = [];
        $bool = static fn($v) => $v === true || $v === 'true' || $v === '1' || $v === 1;
        $sup = static function (array $s): array {
            $m = ['title' => 'title', 'editor' => 'editor', 'excerpt' => 'excerpt', 'thumbnail' => 'featured_image', 'author' => 'author', 'page-attributes' => 'page_attributes'];
            return array_values(array_unique(array_merge(array_values(array_filter(array_map(static fn($x) => $m[$x] ?? null, $s))), ['seo', 'frontend_edit'])));
        };
        foreach ((array)Data::option('cptui_post_types', []) as $slug => $t) {
            if (!is_array($t)) {
                continue;
            }
            $archive = $bool($t['has_archive'] ?? false);
            $types[(string)$slug] = ['singular' => (string)($t['singular_label'] ?? $slug), 'plural' => (string)($t['label'] ?? $slug),
                'rewrite' => trim((string)($t['rewrite_slug'] ?? ''), '/') ?: (string)$slug, 'hierarchical' => $bool($t['hierarchical'] ?? false),
                'has_archive' => $archive, 'archive_slug' => $archive ? trim((string)($t['has_archive_string'] ?? ''), '/') : '',
                'supports' => $sup((array)($t['supports'] ?? [])), 'description' => (string)($t['description'] ?? '')];
            $src['cptui'] = true;
        }
        foreach ((array)Data::option('cptui_taxonomies', []) as $slug => $t) {
            if (!is_array($t)) {
                continue;
            }
            $taxes[(string)$slug] = ['singular' => (string)($t['singular_label'] ?? $slug), 'plural' => (string)($t['label'] ?? $slug),
                'rewrite' => trim((string)($t['rewrite_slug'] ?? ''), '/') ?: (string)$slug, 'hierarchical' => $bool($t['hierarchical'] ?? false),
                'object_types' => array_values((array)($t['object_types'] ?? []))];
            $src['cptui'] = true;
        }
        $acfTypes = json_decode((string)Data::get('acf', 'post_types'), true);
        $acfTaxes = json_decode((string)Data::get('acf', 'taxonomies'), true);
        if (!is_array($acfTypes) || !is_array($acfTaxes)) {
            $acfTypes = $acfTaxes = [];
            foreach (Data::rows('wpost') as $r) {
                if (in_array($r['type'] ?? '', ['acf-post-type', 'acf-taxonomy'], true) && ($r['status'] ?? '') !== 'trash') {
                    $d = Data::decode((string)$r['content']);
                    if (is_array($d)) {
                        $d['title'] ??= $r['title'];
                        $r['type'] === 'acf-post-type' ? $acfTypes[] = $d : $acfTaxes[] = $d;
                    }
                }
            }
        }
        foreach ($acfTypes as $t) {
            $slug = (string)($t['post_type'] ?? '');
            if ($slug === '' || isset($types[$slug])) {
                continue;
            }
            $rw = (array)($t['rewrite'] ?? []);
            $types[$slug] = ['singular' => (string)($t['labels']['singular_name'] ?? $slug), 'plural' => (string)($t['labels']['name'] ?? $t['title'] ?? $slug),
                'rewrite' => trim((string)($rw['slug'] ?? ''), '/') ?: $slug, 'hierarchical' => !empty($t['hierarchical']), 'has_archive' => !empty($t['has_archive']),
                'archive_slug' => trim((string)($t['has_archive_slug'] ?? ''), '/'), 'supports' => $sup((array)($t['supports'] ?? [])), 'description' => (string)($t['description'] ?? '')];
            $src['acf'] = true;
        }
        foreach ($acfTaxes as $t) {
            $slug = (string)($t['taxonomy'] ?? '');
            if ($slug === '' || isset($taxes[$slug])) {
                continue;
            }
            $rw = (array)($t['rewrite'] ?? []);
            $taxes[$slug] = ['singular' => (string)($t['labels']['singular_name'] ?? $slug), 'plural' => (string)($t['labels']['name'] ?? $t['title'] ?? $slug),
                'rewrite' => trim((string)($rw['slug'] ?? ''), '/') ?: $slug, 'hierarchical' => !empty($t['hierarchical']), 'object_types' => array_values((array)($t['object_type'] ?? []))];
            $src['acf'] = true;
        }
        return ['types' => $types, 'taxes' => $taxes, 'source' => array_keys($src)];
    }

    /** Merge exact definitions into an inferred spec (labels, URL bases, archive, hierarchy, supports), and drop inferred fields ACF covers. */
    public static function enrichSpec(array $spec): array
    {
        $reg = self::registered();
        foreach ($spec['post_types'] as &$t) {
            $wp = array_search($t['slug'], array_map(static fn($m) => $m['slug'], (array)$spec['wp_map']['types']), true);
            $d = $wp !== false ? ($reg['types'][$wp] ?? null) : null;
            if (!$d) {
                continue;
            }
            // Keep a rewrite inferred from real links when it carries %taxonomy% tokens (more exact than the registered slug).
            $rewrite = str_contains((string)$t['rewrite'], '%') ? $t['rewrite'] : $d['rewrite'];
            $t = array_merge($t, ['singular' => $d['singular'], 'plural' => $d['plural'], 'rewrite' => $rewrite, 'hierarchical' => $d['hierarchical'],
                'has_archive' => $d['has_archive'], 'supports' => $d['supports'], 'description' => $d['description'] ?: ($t['description'] ?? '')]);
        }
        unset($t);
        foreach ($spec['taxonomies'] as &$x) {
            $wp = array_search($x['slug'], array_map(static fn($m) => $m['slug'], (array)$spec['wp_map']['taxonomies']), true);
            $d = $wp !== false ? ($reg['taxes'][$wp] ?? null) : null;
            if ($d) {
                $x = array_merge($x, ['singular' => $d['singular'], 'plural' => $d['plural'], 'hierarchical' => $d['hierarchical']]);
                if (empty($x['rewrite']) || $x['rewrite'] === $x['slug']) {
                    $x['rewrite'] = $d['rewrite'];
                }
            }
        }
        unset($x);
        $names = self::topNames();
        if ($names) {
            foreach ($spec['field_groups'] as $i => $g) {
                $spec['field_groups'][$i]['fields'] = array_values(array_filter((array)$g['fields'], static function ($f) use ($names) {
                    foreach ($names as $n) {
                        if ($f['name'] === $n || str_starts_with((string)$f['name'], $n . '_')) {
                            return false;
                        }
                    }
                    return true;
                }));
                if (!$spec['field_groups'][$i]['fields']) {
                    unset($spec['field_groups'][$i]);
                }
            }
            $spec['field_groups'] = array_values($spec['field_groups']);
        }
        $spec['registered'] = $reg['source'];
        return $spec;
    }

    /** Make sure CPT UI / ACF types that have no items yet still exist in the spec. */
    public static function addMissingTypes(array $spec): array
    {
        $reg = self::registered();
        foreach ($reg['types'] as $wp => $d) {
            if (isset($spec['wp_map']['types'][$wp])) {
                continue;
            }
            $slug = Generator::TYPE_ALIASES[$wp] ?? Generator::slug($wp);
            $spec['wp_map']['types'][$wp] = ['slug' => $slug, 'rest_base' => $wp, 'total' => 0];
            $spec['post_types'][] = ['slug' => $slug, 'singular' => $d['singular'], 'plural' => $d['plural'], 'rewrite' => $d['rewrite'], 'hierarchical' => $d['hierarchical'],
                'has_archive' => $d['has_archive'], 'public' => true, 'icon' => Generator::guessIcon($wp), 'supports' => $d['supports'], 'description' => $d['description']];
        }
        return $spec;
    }
}
