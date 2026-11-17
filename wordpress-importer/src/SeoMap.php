<?php
declare(strict_types=1);

namespace SlateModules\WpImport;

use Slate\Options;

/**
 * SEO plugins → Slate SEO: per-item meta (Yoast, Rank Math, All in One SEO, SEOPress), global settings
 * (separator, title templates, noindex per type / taxonomy, homepage, organisation, social profiles)
 * and the redirects kept by Rank Math, Redirection and Yoast Premium.
 */
final class SeoMap
{
    /** Which SEO plugin a site uses, from staged options or plugin list. */
    public static function plugin(array $activePlugins = []): ?string
    {
        $p = implode(' ', $activePlugins);
        return match (true) {
            str_contains($p, 'wordpress-seo') || Data::option('wpseo_titles') !== null => 'Yoast SEO',
            str_contains($p, 'seo-by-rank-math') || Data::option('rank-math-options-titles') !== null => 'Rank Math',
            str_contains($p, 'all-in-one-seo') || Data::option('aioseo_options') !== null => 'All in One SEO',
            str_contains($p, 'wp-seopress') || Data::option('seopress_titles_option_name') !== null => 'SEOPress',
            default => null,
        };
    }

    /** Placeholders of the four plugins → Slate's (%title% %site% %sep% %tagline% %page% %category% %year%). */
    public static function vars(string $s): string
    {
        $s = (string)preg_replace('/%%(\w+)%%/', '%$1%', $s); // Yoast, SEOPress
        $s = strtr($s, ['#post_title' => '%title%', '#site_title' => '%site%', '#separator_sa' => '%sep%', '#tagline' => '%tagline%', '#page_number' => '%page%',
            '#taxonomy_title' => '%title%', '#archive_title' => '%title%', '#current_year' => '%year%', '#post_excerpt' => '', '#categories' => '%category%',
            '#author_name' => '', '#post_date' => '']);
        $s = strtr($s, ['%sitename%' => '%site%', '%sitetitle%' => '%site%', '%sitedesc%' => '%tagline%', '%tagline%' => '%tagline%', '%primary_category%' => '%category%',
            '%currentyear%' => '%year%', '%current_year%' => '%year%', '%seo_title%' => '%title%', '%post_title%' => '%title%', '%term_title%' => '%title%',
            '%term%' => '%title%', '%pagenumber%' => '%page%', '%excerpt%' => '', '%post_excerpt%' => '', '%excerpt_only%' => '', '%name%' => '', '%date%' => '',
            '%cpt_plural%' => '%title%', '%archive_title%' => '%title%', '%pt_plural%' => '%title%']);
        // Anything else plugin-specific is dropped rather than shown literally.
        $s = (string)preg_replace('/%(?!title%|site%|sep%|tagline%|page%|category%|year%)[a-z_]+%/i', '', $s);
        return trim((string)preg_replace('/\s{2,}/', ' ', $s));
    }

    // ------------------------------------------------------------------ per item

    /** Slate SEO fields from raw post meta (and the AIOSEO table row, if any). Only values set by hand are returned. */
    public static function fromMeta(array $m, ?array $aioseo = null): array
    {
        $seo = [];
        $first = static function (array $keys) use ($m): string {
            foreach ($keys as $k) {
                if (isset($m[$k]) && trim((string)$m[$k]) !== '') {
                    return trim((string)$m[$k]);
                }
            }
            return '';
        };
        $title = $first(['_yoast_wpseo_title', 'rank_math_title', '_seopress_titles_title', '_aioseo_title']);
        $desc = $first(['_yoast_wpseo_metadesc', 'rank_math_description', '_seopress_titles_desc', '_aioseo_description']);
        if ($aioseo) {
            $title = $title ?: trim((string)($aioseo['title'] ?? ''));
            $desc = $desc ?: trim((string)($aioseo['description'] ?? ''));
        }
        if ($title !== '') {
            $seo['title'] = self::vars($title);
        }
        if ($desc !== '') {
            $seo['description'] = self::vars($desc);
        }
        $kw = $first(['_yoast_wpseo_focuskw', 'rank_math_focus_keyword', '_seopress_analysis_target_kw']);
        if ($kw === '' && $aioseo && ($k = json_decode((string)($aioseo['keyphrases'] ?? ''), true))) {
            $kw = (string)($k['focus']['keyphrase'] ?? '');
        }
        if ($kw !== '') {
            $seo['focus_keyword'] = trim(explode(',', $kw)[0]);
        }
        $canonical = $first(['_yoast_wpseo_canonical', 'rank_math_canonical_url', '_seopress_robots_canonical']) ?: trim((string)($aioseo['canonical_url'] ?? ''));
        if ($canonical !== '') {
            $seo['canonical'] = $canonical;
        }
        $rm = Data::decode($m['rank_math_robots'] ?? null);
        $rm = is_array($rm) ? $rm : [];
        if (($m['_yoast_wpseo_meta-robots-noindex'] ?? '') === '1' || in_array('noindex', $rm, true) || ($m['_seopress_robots_index'] ?? '') === 'yes'
            || !empty($aioseo['robots_noindex'])) {
            $seo['robots_index'] = 'noindex';
        } elseif (($m['_yoast_wpseo_meta-robots-noindex'] ?? '') === '2' || in_array('index', $rm, true)) {
            $seo['robots_index'] = 'index';
        }
        if (($m['_yoast_wpseo_meta-robots-nofollow'] ?? '') === '1' || in_array('nofollow', $rm, true) || ($m['_seopress_robots_follow'] ?? '') === 'yes'
            || !empty($aioseo['robots_nofollow'])) {
            $seo['nofollow'] = true;
        }
        $ogT = $first(['_yoast_wpseo_opengraph-title', 'rank_math_facebook_title', '_seopress_social_fb_title']) ?: trim((string)($aioseo['og_title'] ?? ''));
        $ogD = $first(['_yoast_wpseo_opengraph-description', 'rank_math_facebook_description', '_seopress_social_fb_desc']) ?: trim((string)($aioseo['og_description'] ?? ''));
        if ($ogT !== '') {
            $seo['og_title'] = self::vars($ogT);
        }
        if ($ogD !== '') {
            $seo['og_description'] = self::vars($ogD);
        }
        $img = $first(['_yoast_wpseo_opengraph-image', 'rank_math_facebook_image', '_seopress_social_fb_img']) ?: trim((string)($aioseo['og_image_custom_url'] ?? ''));
        $imgId = (int)$first(['_yoast_wpseo_opengraph-image-id', 'rank_math_facebook_image_id']);
        if ($imgId) {
            $seo['og_image_id'] = $imgId;
        } elseif ($img !== '') {
            $seo['og_image_url'] = $img;
        }
        $schema = $first(['_yoast_wpseo_schema_article_type', '_yoast_wpseo_schema_page_type', 'rank_math_rich_snippet']);
        $schemaMap = ['Article' => 'Article', 'BlogPosting' => 'BlogPosting', 'NewsArticle' => 'NewsArticle', 'FAQPage' => 'FAQPage', 'AboutPage' => 'AboutPage',
            'ContactPage' => 'ContactPage', 'CollectionPage' => 'CollectionPage', 'article' => 'Article', 'product' => 'Product', 'event' => 'Event', 'recipe' => 'Recipe',
            'service' => 'Service', 'off' => 'None'];
        if (isset($schemaMap[$schema])) {
            $seo['schema_type'] = $schemaMap[$schema];
        }
        return $seo;
    }

    public static function termFromMeta(array $m): array
    {
        return self::fromMeta($m);
    }

    /** Yoast keeps taxonomy SEO in one option: wpseo_taxonomy_meta[taxonomy][term id]. */
    public static function yoastTerm(string $wpTax, int $termId): array
    {
        $all = Data::option('wpseo_taxonomy_meta');
        $t = is_array($all) ? (array)($all[$wpTax][$termId] ?? []) : [];
        if (!$t) {
            return [];
        }
        $seo = [];
        if (!empty($t['wpseo_title'])) {
            $seo['title'] = self::vars((string)$t['wpseo_title']);
        }
        if (!empty($t['wpseo_desc'])) {
            $seo['description'] = self::vars((string)$t['wpseo_desc']);
        }
        if (($t['wpseo_noindex'] ?? '') === 'noindex') {
            $seo['robots_index'] = 'noindex';
        }
        if (!empty($t['wpseo_canonical'])) {
            $seo['canonical'] = (string)$t['wpseo_canonical'];
        }
        if (!empty($t['wpseo_focuskw'])) {
            $seo['focus_keyword'] = (string)$t['wpseo_focuskw'];
        }
        return $seo;
    }

    // ------------------------------------------------------------------ global

    /**
     * Apply the SEO plugin's global settings. $typeMap / $taxMap: WP slug → Slate slug. Returns log lines.
     * Only fills Slate settings the plugin actually set; Slate defaults stay otherwise.
     */
    public static function applyGlobal(array $typeMap, array $taxMap): array
    {
        $log = [];
        $sepMap = ['sc-dash' => '-', 'sc-ndash' => '–', 'sc-mdash' => '–', 'sc-middot' => '·', 'sc-bull' => '•', 'sc-star' => '•', 'sc-smstar' => '•',
            'sc-pipe' => '|', 'sc-tilde' => '-', 'sc-laquo' => '»', 'sc-raquo' => '»', 'sc-lt' => '-', 'sc-gt' => '»'];
        $allowed = ['–', '-', '|', '•', '·', '»', '/'];
        $types = (array)(Options::get('seo_types') ?: []);
        $taxes = (array)(Options::get('seo_taxonomies') ?: []);
        $set = static function (string $k, mixed $v) use (&$log): void {
            if ($v === null || $v === '') {
                return;
            }
            Options::set($k, $v);
            $log[] = "SEO setting $k set from WordPress.";
        };
        $sep = null;
        $home = $homeDesc = null;
        $org = [];
        $social = [];
        $typeTpl = [];
        $taxTpl = [];
        if (is_array($y = Data::option('wpseo_titles'))) {
            $sep = $sepMap[(string)($y['separator'] ?? '')] ?? null;
            $home = $y['title-home-wpseo'] ?? null;
            $homeDesc = $y['metadesc-home-wpseo'] ?? null;
            foreach ($typeMap as $wp => $slug) {
                $typeTpl[$slug] = ['title' => $y['title-' . $wp] ?? '', 'noindex' => !empty($y['noindex-' . $wp])];
            }
            foreach ($taxMap as $wp => $slug) {
                $taxTpl[$slug] = ['title' => $y['title-tax-' . $wp] ?? '', 'noindex' => !empty($y['noindex-tax-' . $wp])];
            }
            $org = ['entity' => ($y['company_or_person'] ?? '') === 'person' ? 'Person' : 'Organization', 'name' => $y['company_name'] ?? '', 'logo_url' => $y['company_logo'] ?? ''];
        }
        if (is_array($ys = Data::option('wpseo_social'))) {
            $social = array_merge([$ys['facebook_site'] ?? '', !empty($ys['twitter_site']) ? 'https://x.com/' . ltrim((string)$ys['twitter_site'], '@') : ''],
                (array)($ys['other_social_urls'] ?? []));
            if (!empty($ys['twitter_site'])) {
                $set('seo_twitter_site', '@' . ltrim((string)$ys['twitter_site'], '@'));
            }
        }
        if (is_array($r = Data::option('rank-math-options-titles'))) {
            $sep ??= in_array(trim((string)($r['title_separator'] ?? '')), $allowed, true) ? trim((string)$r['title_separator']) : (trim((string)($r['title_separator'] ?? '')) === "\u{2014}" ? '–' : null);
            $home ??= $r['homepage_title'] ?? null;
            $homeDesc ??= $r['homepage_description'] ?? null;
            foreach ($typeMap as $wp => $slug) {
                $typeTpl[$slug] ??= ['title' => $r['pt_' . $wp . '_title'] ?? '', 'noindex' => in_array('noindex', (array)($r['pt_' . $wp . '_robots'] ?? []), true) && !empty($r['pt_' . $wp . '_custom_robots'])];
            }
            foreach ($taxMap as $wp => $slug) {
                $taxTpl[$slug] ??= ['title' => $r['tax_' . $wp . '_title'] ?? '', 'noindex' => in_array('noindex', (array)($r['tax_' . $wp . '_robots'] ?? []), true) && !empty($r['tax_' . $wp . '_custom_robots'])];
            }
            $org = $org ?: ['entity' => ($r['knowledgegraph_type'] ?? '') === 'person' ? 'Person' : 'Organization', 'name' => $r['knowledgegraph_name'] ?? '', 'logo_url' => $r['knowledgegraph_logo'] ?? ''];
            $social = $social ?: array_merge([$r['social_url_facebook'] ?? ''], preg_split('/\R/', (string)($r['social_additional_profiles'] ?? '')) ?: []);
        }
        if (is_array($a = Data::option('aioseo_options'))) {
            $sa = (array)($a['searchAppearance'] ?? []);
            $g = (array)($sa['global'] ?? []);
            $sep ??= in_array(html_entity_decode((string)($g['separator'] ?? '')), $allowed, true) ? html_entity_decode((string)$g['separator']) : null;
            $home ??= $g['siteTitle'] ?? null;
            $homeDesc ??= $g['metaDescription'] ?? null;
            foreach ($typeMap as $wp => $slug) {
                $pt = (array)($sa['postTypes'][$wp] ?? []);
                $typeTpl[$slug] ??= ['title' => $pt['title'] ?? '', 'noindex' => !empty($pt['advanced']['robotsMeta']['noindex'])];
            }
            $org = $org ?: ['entity' => ($g['schema']['siteRepresents'] ?? '') === 'person' ? 'Person' : 'Organization', 'name' => $g['schema']['organizationName'] ?? '', 'logo_url' => $g['schema']['organizationLogo'] ?? ''];
            $social = $social ?: array_values(array_filter((array)($a['social']['profiles']['urls'] ?? []), 'is_string'));
        }
        if (is_array($s = Data::option('seopress_titles_option_name'))) {
            $sep ??= in_array((string)($s['seopress_titles_sep'] ?? ''), $allowed, true) ? (string)$s['seopress_titles_sep'] : null;
            $home ??= $s['seopress_titles_home_site_title'] ?? null;
            $homeDesc ??= $s['seopress_titles_home_site_desc'] ?? null;
            foreach ($typeMap as $wp => $slug) {
                $typeTpl[$slug] ??= ['title' => $s['seopress_titles_single_titles'][$wp]['title'] ?? '', 'noindex' => !empty($s['seopress_titles_single_titles'][$wp]['noindex'])];
            }
            foreach ($taxMap as $wp => $slug) {
                $taxTpl[$slug] ??= ['title' => $s['seopress_titles_tax_titles'][$wp]['title'] ?? '', 'noindex' => !empty($s['seopress_titles_tax_titles'][$wp]['noindex'])];
            }
        }
        $set('seo_separator', $sep);
        if (is_string($home) && $home !== '') {
            $set('seo_home_title', self::vars($home));
        }
        if (is_string($homeDesc) && $homeDesc !== '') {
            $set('seo_home_description', self::vars($homeDesc));
        }
        foreach ($typeTpl as $slug => $t) {
            if (trim((string)$t['title']) === '' && empty($t['noindex'])) {
                continue;
            }
            $types[$slug] = array_merge(\Slate\Seo::typeDefaults($slug), (array)($types[$slug] ?? []), array_filter(['title_template' => self::vars((string)$t['title'])]),
                !empty($t['noindex']) ? ['robots' => 'noindex'] : []);
        }
        foreach ($taxTpl as $slug => $t) {
            if (trim((string)$t['title']) === '' && empty($t['noindex'])) {
                continue;
            }
            $taxes[$slug] = array_merge(\Slate\Seo::taxDefaults($slug), (array)($taxes[$slug] ?? []), array_filter(['title_template' => self::vars((string)$t['title'])]),
                ['robots' => !empty($t['noindex']) ? 'noindex' : 'index']);
        }
        if ($typeTpl) {
            Options::set('seo_types', $types);
            $log[] = 'Title templates and indexing per content type set (' . count(array_filter($typeTpl, static fn($t) => trim((string)$t['title']) !== '' || !empty($t['noindex']))) . ' type(s)).';
        }
        if ($taxTpl) {
            Options::set('seo_taxonomies', $taxes);
        }
        if (!empty($org['name'])) {
            $set('seo_schema_entity', $org['entity']);
            $set('seo_schema_name', (string)$org['name']);
            if (!empty($org['logo_url']) && ($mid = Importer::mediaByUrl((string)$org['logo_url']))) {
                $set('seo_schema_logo', $mid);
            }
        }
        $social = array_values(array_unique(array_filter(array_map('trim', array_map('strval', $social)), static fn($u) => (bool)preg_match('#^https?://#', $u))));
        if ($social) {
            $set('seo_schema_same_as', implode("\n", $social));
        }
        return $log;
    }

    // ------------------------------------------------------------------ redirects

    /** Redirect rules kept by SEO / redirect plugins: [['from','to','code','regex','source']]. */
    public static function pluginRedirects(?\SlateModules\AutoCpt\WpClient $wp = null): array
    {
        $out = [];
        foreach (Data::rows('table:rank_math_redirections') as $r) {
            if (($r['status'] ?? 'active') !== 'active') {
                continue;
            }
            foreach ((array)Data::decode((string)($r['sources'] ?? '')) as $src) {
                $pattern = (string)($src['pattern'] ?? '');
                $cmp = (string)($src['comparison'] ?? 'exact');
                if ($pattern === '' || !in_array($cmp, ['exact', 'regex', 'start'], true)) {
                    continue;
                }
                $regex = $cmp !== 'exact';
                $from = $cmp === 'start' ? '^/' . preg_quote(ltrim($pattern, '/'), '#') . '(.*)' : ($regex ? $pattern : '/' . ltrim($pattern, '/'));
                $out[] = ['from' => $from, 'to' => (string)($r['url_to'] ?? ''), 'code' => (int)($r['header_code'] ?? 301), 'regex' => $regex, 'source' => 'Rank Math'];
            }
        }
        foreach (Data::rows('table:redirection_items') as $r) {
            if (($r['status'] ?? 'enabled') !== 'enabled' || ($r['action_type'] ?? 'url') !== 'url' || ($r['match_type'] ?? 'url') !== 'url') {
                continue;
            }
            $to = Data::decode((string)($r['action_data'] ?? ''));
            $to = is_array($to) ? (string)($to['url'] ?? '') : (string)$to;
            $out[] = ['from' => (string)$r['url'], 'to' => $to, 'code' => (int)($r['action_code'] ?? 301), 'regex' => !empty($r['regex']), 'source' => 'Redirection'];
        }
        // Redirection plugin through its own REST API (administrator application password) when nothing was staged.
        if (!Data::has('table:redirection_items') && $wp && $wp->authenticated()) {
            for ($page = 0; $page < 50; $page++) {
                $r = $wp->get('/redirection/v1/redirect', ['per_page' => 200, 'page' => $page]);
                if (!$r['ok'] || empty($r['data']['items'])) {
                    break;
                }
                foreach ($r['data']['items'] as $it) {
                    if (empty($it['enabled']) || ($it['action_type'] ?? 'url') !== 'url' || ($it['match_type'] ?? 'url') !== 'url') {
                        continue;
                    }
                    $to = $it['action_data'] ?? '';
                    $out[] = ['from' => (string)$it['url'], 'to' => is_array($to) ? (string)($to['url'] ?? '') : (string)$to, 'code' => (int)($it['action_code'] ?? 301),
                        'regex' => !empty($it['regex']), 'source' => 'Redirection'];
                }
                if (count($r['data']['items']) < 200) {
                    break;
                }
            }
        }
        foreach (['wpseo-premium-redirects-base' => false, 'wpseo-premium-redirects-export-regex' => true] as $opt => $isRe) {
            $y = Data::option($opt);
            foreach (is_array($y) ? $y : [] as $k => $r) {
                $from = (string)($r['origin'] ?? (is_string($k) ? $k : ''));
                if ($from === '') {
                    continue;
                }
                $out[] = ['from' => $isRe || ($r['format'] ?? '') === 'regex' ? $from : '/' . ltrim($from, '/'), 'to' => (string)($r['url'] ?? ''),
                    'code' => (int)($r['type'] ?? 301), 'regex' => $isRe || ($r['format'] ?? '') === 'regex', 'source' => 'Yoast Premium'];
            }
        }
        foreach ($out as &$r) {
            if (!in_array($r['code'], [301, 302, 307, 308, 410], true)) {
                $r['code'] = 301;
            }
        }
        unset($r);
        return $out;
    }
}
