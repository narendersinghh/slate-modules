<?php
declare(strict_types=1);

namespace SlateModules\WpImport;

use Slate\DB;
use SlateModules\AutoCpt\WpClient;

/**
 * What a source contains, per category, before anything is imported: counts, what will happen,
 * what is skipped and why, and which extra source (companion plugin, database dump, uploads archive) would add more.
 */
final class Analyse
{
    public static function run(array $conn, array $scan, ?WpClient $wp): array
    {
        $sum = $scan['summary'];
        $spec = $scan['spec'];
        $wxr = ($conn['source'] ?? 'rest') === 'wxr';
        $ns = implode(' ', (array)($sum['namespaces'] ?? []));
        $companion = isset(Data::sources()['companion']);
        $sources = ['rest' => !$wxr, 'auth' => !empty($sum['auth']), 'companion' => $companion, 'wxr' => $wxr, 'sql' => isset(Data::sources()['sql']),
            'uploads' => isset(Data::sources()['uploads'])];
        $hasOptions = Data::has('option');
        $plugins = (array)($sum['companion']['plugins'] ?? []);
        $has = static fn(string $nsPart, string $plugin = '') => str_contains($ns, $nsPart) || ($plugin !== '' && in_array($plugin, $plugins, true));
        $cat = [];

        // ---- content
        $types = (array)($scan['counts']['types'] ?? []);
        $total = array_sum(array_map(static fn($c) => (int)$c['total'], $types));
        $missing = [];
        foreach ($wxr ? Data::typeCounts() : [] as $t => $n) {
            if (isset($types[$t]) && (int)$types[$t]['total'] < $n) {
                $missing[] = "$t: $n in the database but " . (int)$types[$t]['total'] . ' in the export file (the post type is not exportable). Make it exportable (e.g. CPT UI › Exportable) and export again, or connect the live site.';
            }
        }
        $cat['content'] = ['label' => 'Pages, posts and custom items', 'icon' => 'file-text', 'found' => $total,
            'detail' => array_map(static fn($k, $c) => $k . ': ' . (int)$c['total'], array_keys($types), $types),
            'will' => 'Imported with status, dates, parent and order, featured image, categories and tags, custom fields and SEO.',
            'skip' => array_values(array_filter(array_merge([!$wxr && empty($sum['auth']) ? 'Drafts and private items need an administrator application password.' : null], $missing))), 'option' => 'types'];

        // ---- builders
        $builders = self::builders($wp, $wxr, array_keys($types));
        if ($builders) {
            arsort($builders);
            $cat['builders'] = ['label' => 'Page builders', 'icon' => 'columns', 'found' => array_sum(array_filter($builders, static fn($n, $b) => Builders::isBuilder($b), ARRAY_FILTER_USE_BOTH)),
                'detail' => array_map(static fn($b, $n) => (Builders::LABELS[$b] ?? $b) . ': ' . $n, array_keys($builders), $builders),
                'will' => 'Builder pages are copied with their exact design (as designed pages you edit by clicking). Block editor pages become clean HTML.',
                'skip' => $wxr ? ['Designs are copied from the live site: keep it online during the import. Offline, builder pages are rebuilt from their stored content without design.'] : [],
                'option' => 'designed'];
        }

        // ---- content model
        $acfGroups = Acf::groups();
        $reg = Acf::registered();
        $acfFields = 0;
        foreach ($acfGroups as $g) {
            $acfFields += count((array)($g['fields'] ?? []));
        }
        $detail = [count($spec['post_types']) . ' custom post type(s): ' . implode(', ', array_column($spec['post_types'], 'plural')),
            count($spec['taxonomies']) . ' taxonomies: ' . implode(', ', array_column($spec['taxonomies'], 'plural'))];
        if ($acfGroups) {
            $detail[] = count($acfGroups) . ' ACF field group(s), ' . $acfFields . ' top-level field(s) (exact definitions)';
        } elseif ($spec['field_groups']) {
            $detail[] = count($spec['field_groups']) . ' field group(s) worked out from the values';
        }
        if ($reg['source']) {
            $detail[] = 'Definitions from ' . implode(' and ', array_map(static fn($s) => $s === 'cptui' ? 'CPT UI' : 'ACF', $reg['source']));
        }
        $skip = [];
        if (!$acfGroups && ($sum['acf'] ?? false)) {
            $skip[] = 'ACF field groups were worked out from values. For the exact definitions (repeaters, flexible content, choices, required) install the companion plugin, or upload the export file / a database dump.';
        }
        foreach (['jet-engine' => 'JetEngine', 'meta-box' => 'Meta Box', 'pods' => 'Pods'] as $p => $label) {
            if (in_array($p, $plugins, true) || $has($p)) {
                $skip[] = "$label definitions are not read directly; their post types and fields are worked out from the content.";
            }
        }
        if (Data::option('options_') !== null || array_filter(Data::optionNames(), static fn($n) => str_starts_with($n, 'options_'))) {
            $skip[] = 'ACF options pages (site-wide fields) have no Slate equivalent and are not imported.';
        }
        $cat['model'] = ['label' => 'Post types, taxonomies and custom fields', 'icon' => 'database', 'found' => count($spec['post_types']) + count($spec['taxonomies']) + max(count($acfGroups), count($spec['field_groups'])),
            'detail' => $detail, 'will' => 'Created with labels, URL bases, hierarchy, archives and supports; ACF repeaters, groups, flexible content, relationships, galleries and choices are kept.',
            'skip' => $skip, 'option' => 'model'];

        // ---- media
        $cat['media'] = ['label' => 'Media library', 'icon' => 'image', 'found' => isset($sum['media']) ? (int)$sum['media'] : null,
            'detail' => array_values(array_filter([$sources['uploads'] ? 'Files come from the uploaded archive (' . (int)(Data::sources()['uploads']['files'] ?? 0) . ' files).' : null])),
            'will' => 'Copied with alt text, title and caption, optimised, and every link to them updated.',
            'skip' => ['Files hosted on other servers (CDNs) are only linked, not copied.'], 'option' => 'media'];

        // ---- menus
        $menus = $wxr ? count(self::wxrMenus()) : ($sum['menus'] ?? null);
        $cat['menus'] = ['label' => 'Menus', 'icon' => 'menu', 'found' => $menus, 'detail' => [],
            'will' => 'Nested items linked to the imported pages, posts and categories, placed in the theme where the locations match.',
            'skip' => $menus === null ? ['Reading menus needs an administrator application password.'] : ($wxr && !$hasOptions ? ['The export file has no menu locations; add a database dump to place menus automatically.'] : []),
            'option' => 'menus'];

        // ---- SEO
        $seoPlugin = SeoMap::plugin($plugins) ?: (is_string($sum['seo'] ?? null) ? $sum['seo'] : ($has('rankmath') ? 'Rank Math' : ($has('aioseo') ? 'All in One SEO' : ($has('seopress') ? 'SEOPress' : null))));
        $seoItems = (int)DB::val("SELECT COUNT(DISTINCT ref) FROM {{wpi_data}} WHERE kind = 'postmeta' AND k IN ('_yoast_wpseo_title','_yoast_wpseo_metadesc','rank_math_title','rank_math_description','_seopress_titles_title','_seopress_titles_desc','_aioseo_title','_aioseo_description','_yoast_wpseo_focuskw','rank_math_focus_keyword')");
        $cat['seo'] = ['label' => 'SEO', 'icon' => 'search', 'found' => $seoItems ?: null,
            'detail' => array_values(array_filter([$seoPlugin ? 'Plugin: ' . $seoPlugin : 'No SEO plugin found; titles and descriptions are read from each page.', $seoItems ? "$seoItems item(s) with SEO set by hand" : null,
                $hasOptions && $seoPlugin ? 'Global settings: separator, title templates, noindex rules, organisation, social profiles' : null])),
            'will' => 'Titles, descriptions, focus keywords, canonical, robots and social images per item; rich-result schema kept.',
            'skip' => $seoPlugin && !$hasOptions ? ['Global SEO settings (templates, separator, social profiles) need the companion plugin or a database dump.'] : [], 'option' => 'seo'];

        // ---- redirects
        $redirects = SeoMap::pluginRedirects($sources['auth'] ? $wp : null);
        $bySource = array_count_values(array_column($redirects, 'source'));
        $rSkip = [];
        if (!$redirects && ($has('rankmath', 'seo-by-rank-math') || in_array('wordpress-seo-premium', $plugins, true)) && !$hasOptions && !Data::has('table:rank_math_redirections')) {
            $rSkip[] = 'Rank Math and Yoast Premium redirects need the companion plugin or a database dump.';
        }
        $cat['redirects'] = ['label' => 'Redirects', 'icon' => 'link', 'found' => count($redirects),
            'detail' => array_merge(array_map(static fn($s, $n) => "$s: $n rule(s)", array_keys($bySource), $bySource), ['Plus a 301 redirect for every address that changes in Slate.']),
            'will' => 'Kept as Slate redirects, pointed at the new addresses.', 'skip' => $rSkip, 'option' => 'redirects'];

        // ---- forms
        $forms = FormsMap::all($sources['auth'] ? $wp : null);
        $byPlugin = array_count_values(array_map(static fn($f) => FormsMap::pluginLabel($f['plugin']), $forms));
        $fSkip = [];
        if (!FormsMap::available()) {
            $fSkip[] = 'The Forms module is not active: activate it to import forms.';
        }
        if ($has('wpforms') && !array_filter($forms, static fn($f) => $f['plugin'] === 'wpforms') && !$companion) {
            $fSkip[] = 'WPForms forms are not in the REST API: use the companion plugin, the export file or a database dump.';
        }
        if (in_array('ninja-forms', $plugins, true) || in_array('formidable', $plugins, true) || in_array('forminator', $plugins, true)) {
            $fSkip[] = 'Ninja Forms, Formidable and Forminator forms are not converted; rebuild them in Slate Forms.';
        }
        $cat['forms'] = ['label' => 'Forms', 'icon' => 'message', 'found' => count($forms), 'detail' => array_map(static fn($p, $n) => "$p: $n", array_keys($byPlugin), $byPlugin),
            'will' => 'Rebuilt in Slate Forms with labels, required fields, choices and notification e-mail; embeds on pages are replaced.', 'skip' => $fSkip, 'option' => 'forms'];

        // ---- templates
        $templates = TemplatesMap::collect($sources['auth'] ? $wp : null);
        $cat['templates'] = ['label' => 'Templates and loops', 'icon' => 'layers', 'found' => count($templates),
            'detail' => array_map(static fn($t) => $t['source'] . ': ' . $t['name'] . ' (' . $t['kind'] . '/' . $t['target'] . ')', array_slice($templates, 0, 12)),
            'will' => 'Saved as Slate templates, switched off for review; titles, content, images, fields and post lists become template tags.',
            'skip' => array_values(array_filter([!$templates && !$companion && !$wxr && !Data::has('wpost') ? 'Elementor Theme Builder templates need the companion plugin, the export file or a database dump.' : null])),
            'option' => 'templates'];

        // ---- comments and users
        $comments = Data::count('comment');
        if (!$comments && !$wxr && $wp) {
            $c = $wp->get('/wp/v2/comments', ['per_page' => 1]);
            $comments = $c['ok'] ? (int)$c['total'] : 0;
        }
        $cat['comments'] = ['label' => 'Comments', 'icon' => 'message', 'found' => $comments, 'detail' => [],
            'will' => 'Imported with replies, authors and dates (approved and waiting).', 'option' => 'comments',
            'skip' => array_values(array_filter([!class_exists(\SlateModules\Comments\Comments::class) ? 'The Comments module is not active: activate it to import comments.' : null]))];
        $users = Data::count('user');
        if (!$users && !$wxr && $wp) {
            $u = $wp->get('/wp/v2/users', ['per_page' => 1]);
            $users = $u['ok'] ? (int)$u['total'] : 0;
        }
        $cat['users'] = ['label' => 'Authors', 'icon' => 'users', 'found' => $users, 'detail' => [],
            'will' => 'Matched to existing accounts by e-mail, or created as suspended accounts with their role (administrators become editors).',
            'skip' => ['Passwords are never copied: people reset theirs after an administrator activates the account.'], 'option' => 'authors'];

        // ---- design
        $elementor = !empty($sum['elementor']) || isset($builders['elementor']);
        $cat['design'] = ['label' => 'Design', 'icon' => 'palette', 'found' => null,
            'detail' => array_values(array_filter([$elementor ? 'Elementor global colours and fonts can be copied into the design system.' : null])),
            'will' => 'The theme can be mirrored (header, footer, styles) so the rest of the site looks the same.', 'skip' => [], 'option' => 'design'];

        // ---- never imported
        $never = ['Plugin and theme settings that have no Slate equivalent, widgets and sidebars, revisions and trashed items.'];
        if ($has('wc/') || in_array('woocommerce', $plugins, true)) {
            $never[] = 'WooCommerce orders, customers and coupons (products are imported as a post type).';
        }
        if ($has('elementor-pro') || in_array('elementor-pro', $plugins, true)) {
            $never[] = 'Elementor popups are kept only inside designed pages.';
        }
        return ['categories' => $cat, 'sources' => $sources, 'never' => $never, 'plugins' => $plugins, 'at' => gmdate('c')];
    }

    /** Pages per builder: from staged meta when present, otherwise a sample of the REST API. */
    private static function builders(?WpClient $wp, bool $wxr, array $wpTypes): array
    {
        $out = [];
        if (Data::has('postmeta')) {
            $rows = DB::all("SELECT ref, k, v FROM {{wpi_data}} WHERE kind = 'postmeta' AND k IN ('_elementor_edit_mode','_et_pb_use_builder','_wpb_vc_js_status','_fl_builder_enabled','_bricks_editor_mode','ct_builder_shortcodes','_breakdance_data','brizy_post_uid','tcb_editor_enabled','fusion_builder_status')");
            $byRef = [];
            foreach ($rows as $r) {
                $byRef[(string)$r['ref']][(string)$r['k']] = (string)$r['v'];
            }
            foreach ($byRef as $ref => $meta) {
                $t = Data::postType($ref);
                if (!Data::isLive($ref) || ($t && !in_array($t[0], $wpTypes, true)) || Data::row('wpost', $ref)) {
                    continue;
                }
                $b = Builders::detect('', $meta);
                if (Builders::isBuilder($b)) {
                    $out[$b] = ($out[$b] ?? 0) + 1;
                }
            }
        }
        if ($wxr) {
            foreach (Importer::loadWxr()['posts'] as $p) {
                if (in_array($p['type'], ['post', 'page'], true)) {
                    $b = Builders::detect((string)$p['content'], Data::meta((int)$p['old_id']));
                    if (!Builders::isBuilder($b)) {
                        $out[$b] = ($out[$b] ?? 0) + 1;
                    } elseif (!Data::has('postmeta')) {
                        $out[$b] = ($out[$b] ?? 0) + 1;
                    }
                }
            }
            return $out;
        }
        if (!$out && $wp) {
            foreach (['pages', 'posts'] as $base) {
                $r = $wp->get('/wp/v2/' . $base, ['per_page' => 50, '_fields' => 'id,content']);
                foreach ($r['ok'] && is_array($r['data']) ? $r['data'] : [] as $p) {
                    $b = Builders::detect((string)($p['content']['rendered'] ?? ''), [], (string)($p['content']['rendered'] ?? ''));
                    if ($b === 'classic' && preg_match('/wp-block-|<!-- wp:/', (string)($p['content']['rendered'] ?? ''))) {
                        $b = 'gutenberg';
                    }
                    $out[$b] = ($out[$b] ?? 0) + 1;
                }
            }
        }
        return $out;
    }

    private static function wxrMenus(): array
    {
        try {
            return Importer::loadWxr()['menus'] ?? [];
        } catch (\Throwable) {
            return [];
        }
    }
}
