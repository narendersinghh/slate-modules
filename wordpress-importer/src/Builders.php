<?php
declare(strict_types=1);

namespace SlateModules\WpImport;

/**
 * Page builders: which builder made a page, and what to do when its rendered page cannot be fetched.
 * - detect(): Elementor, Divi, WPBakery, Beaver Builder, Bricks, Oxygen, Breakdance, Brizy, Thrive, Avada (Fusion), Gutenberg, classic.
 * - elementorHtml(): Elementor's stored JSON → plain, editable HTML (fallback when the site is offline).
 * - elementorTemplate(): an Elementor Theme Builder template → Slate template HTML with {{tags}}.
 */
final class Builders
{
    public const LABELS = ['elementor' => 'Elementor', 'divi' => 'Divi', 'wpbakery' => 'WPBakery', 'beaver' => 'Beaver Builder', 'bricks' => 'Bricks',
        'oxygen' => 'Oxygen', 'breakdance' => 'Breakdance', 'brizy' => 'Brizy', 'thrive' => 'Thrive Architect', 'avada' => 'Avada (Fusion)',
        'gutenberg' => 'Block editor', 'classic' => 'Classic editor'];

    /** Elementor forms found while converting (widget settings), for the forms step. */
    public array $forms = [];
    /** Widgets without an equivalent (type => count). */
    public array $skipped = [];
    /** fn(string plugin, string id, string title): ?string: form slug for embedded forms. */
    public $formSlug;
    /** fn(array ids): string: gallery markup. */
    public $gallery;
    private int $postId = 0;
    private string $mode = 'content';

    /** Which builder made this item: from meta first, then content and rendered markup. */
    public static function detect(string $content, array $meta = [], string $rendered = ''): string
    {
        if (($meta['_elementor_edit_mode'] ?? '') === 'builder' || str_contains($rendered, 'data-elementor-type="wp-page"') || str_contains($rendered, 'data-elementor-type="wp-post"')
            || str_contains($content, 'data-elementor-type')) {
            return 'elementor';
        }
        if (($meta['_et_pb_use_builder'] ?? '') === 'on' || str_contains($content, '[et_pb_section')) {
            return 'divi';
        }
        if (isset($meta['_wpb_vc_js_status']) || str_contains($content, '[vc_row')) {
            return 'wpbakery';
        }
        if (!empty($meta['_fl_builder_enabled']) || str_contains($rendered, 'fl-builder-content')) {
            return 'beaver';
        }
        if (isset($meta['_bricks_page_content_2']) || ($meta['_bricks_editor_mode'] ?? '') === 'bricks' || str_contains($rendered, 'brxe-')) {
            return 'bricks';
        }
        if (isset($meta['ct_builder_shortcodes']) || isset($meta['_ct_builder_json']) || str_contains($rendered, 'ct-section')) {
            return 'oxygen';
        }
        if (isset($meta['_breakdance_data']) || str_contains($rendered, 'breakdance')) {
            return 'breakdance';
        }
        if (isset($meta['brizy']) || isset($meta['brizy_post_uid']) || str_contains($rendered, 'brz-root')) {
            return 'brizy';
        }
        if (($meta['tcb_editor_enabled'] ?? '') === '1' || str_contains($rendered, 'thrv_wrapper')) {
            return 'thrive';
        }
        if (str_contains($content, '[fusion_builder_container') || ($meta['fusion_builder_status'] ?? '') === 'active') {
            return 'avada';
        }
        if (Blocks::has($content)) {
            return 'gutenberg';
        }
        return 'classic';
    }

    public static function isBuilder(string $b): bool
    {
        return !in_array($b, ['gutenberg', 'classic'], true);
    }

    // ------------------------------------------------------------------ Elementor JSON

    public static function elementorData(array $meta): array
    {
        $raw = $meta['_elementor_data'] ?? '';
        $d = is_string($raw) ? json_decode($raw, true) : $raw;
        return is_array($d) ? $d : [];
    }

    /** Elementor content as plain HTML (no design): headings, text, images, buttons, lists, videos, forms. */
    public function elementorHtml(array $elements, int $postId = 0): string
    {
        $this->postId = $postId;
        $this->mode = 'content';
        return $this->els($elements);
    }

    /** An Elementor Theme Builder template as a Slate template. */
    public function elementorTemplate(array $elements, int $postId = 0): string
    {
        $this->postId = $postId;
        $this->mode = 'template';
        return $this->els($elements);
    }

    private function els(array $elements): string
    {
        $out = '';
        foreach ($elements as $el) {
            if (is_array($el)) {
                $out .= $this->el($el);
            }
        }
        return $out;
    }

    private function el(array $el): string
    {
        $type = (string)($el['elType'] ?? '');
        $s = (array)($el['settings'] ?? []);
        if (in_array($type, ['section', 'container', 'column', 'inner-section'], true) || ($type === 'widget' && ($el['widgetType'] ?? '') === 'inner-section')) {
            $kids = (array)($el['elements'] ?? []);
            $row = $type === 'section' || ($type === 'container' && ($s['flex_direction'] ?? '') === 'row');
            $inner = '';
            foreach ($kids as $k) {
                $html = $this->el((array)$k);
                if ($html === '') {
                    continue;
                }
                $inner .= $row && count($kids) > 1 ? '<div style="flex:1 1 260px">' . $html . '</div>' : $html;
            }
            if ($inner === '') {
                return '';
            }
            $styles = [];
            if ($row && count($kids) > 1) {
                $styles[] = 'display:flex;flex-wrap:wrap;gap:2rem';
            }
            if ($this->mode === 'template' && !empty($s['background_color']) && preg_match('/^#[0-9a-f]{3,8}$/i', (string)$s['background_color'])) {
                $styles[] = 'background:' . $s['background_color'] . ';padding:2rem';
            }
            return $styles ? '<div style="' . implode(';', $styles) . '">' . $inner . '</div>' : $inner;
        }
        if ($type !== 'widget') {
            return $this->els((array)($el['elements'] ?? []));
        }
        $w = (string)($el['widgetType'] ?? '');
        $dyn = $this->mode === 'template' ? $this->dynamic((array)($s['__dynamic__'] ?? [])) : [];
        $t = static fn(string $k) => trim((string)($s[$k] ?? ''));
        $tag = static fn(string $k, string $d) => preg_match('/^(h[1-6]|p|div|span)$/', (string)($s[$k] ?? '')) ? (string)$s[$k] : $d;
        $link = static fn($l) => is_array($l) ? (string)($l['url'] ?? '') : '';
        if ($this->mode === 'template' && ($tpl = $this->themeWidget($w, $s)) !== null) {
            return $tpl;
        }
        switch ($w) {
            case 'heading':
                $h = $tag('header_size', 'h2');
                $text = $dyn['title'] ?? e($t('title'));
                $url = $dyn['link'] ?? $link($s['link'] ?? null);
                return $text !== '' ? "<$h>" . ($url !== '' ? '<a href="' . e($url) . '">' . $text . '</a>' : $text) . "</$h>" : '';
            case 'text-editor':
                return $dyn['editor'] ?? (string)($s['editor'] ?? '');
            case 'image':
                $url = $dyn['image'] ?? (string)($s['image']['url'] ?? '');
                if ($url === '') {
                    return '';
                }
                $img = str_starts_with($url, '{{') ? $url : '<img src="' . e($url) . '" alt="' . e((string)($s['image']['alt'] ?? '')) . '">';
                $href = $link($s['link'] ?? null);
                $cap = $t('caption');
                return '<figure>' . ($href !== '' ? '<a href="' . e($href) . '">' . $img . '</a>' : $img) . ($cap !== '' ? '<figcaption>' . e($cap) . '</figcaption>' : '') . '</figure>';
            case 'button':
                $text = $dyn['text'] ?? e($t('text'));
                $url = $dyn['link'] ?? $link($s['link'] ?? null);
                return $text !== '' ? '<p class="buttons"><a class="button" href="' . e($url ?: '#') . '">' . $text . '</a></p>' : '';
            case 'video':
                return Blocks::embed((string)($s['youtube_url'] ?? $s['vimeo_url'] ?? $s['external_url']['url'] ?? ''));
            case 'icon-list':
                $items = '';
                foreach ((array)($s['icon_list'] ?? []) as $i) {
                    $txt = e(trim((string)($i['text'] ?? '')));
                    $u = $link($i['link'] ?? null);
                    $items .= $txt !== '' ? '<li>' . ($u !== '' ? '<a href="' . e($u) . '">' . $txt . '</a>' : $txt) . '</li>' : '';
                }
                return $items !== '' ? '<ul>' . $items . '</ul>' : '';
            case 'image-box':
            case 'icon-box':
            case 'call-to-action':
            case 'flip-box':
                $img = (string)($s['image']['url'] ?? $s['bg_image']['url'] ?? '');
                $title = $t('title_text') ?: $t('title');
                $desc = $t('description_text') ?: $t('description');
                $btn = $t('button');
                return ($img !== '' ? '<figure><img src="' . e($img) . '" alt="' . e($title) . '"></figure>' : '') . ($title !== '' ? '<h3>' . e($title) . '</h3>' : '')
                    . ($desc !== '' ? '<p>' . $desc . '</p>' : '') . ($btn !== '' ? '<p class="buttons"><a class="button" href="' . e($link($s['link'] ?? null) ?: '#') . '">' . e($btn) . '</a></p>' : '');
            case 'divider':
                return '<hr>';
            case 'spacer':
                $px = (int)($s['space']['size'] ?? 40);
                return '<div style="height:' . max(0, min(400, $px)) . 'px" aria-hidden="true"></div>';
            case 'html':
                return (string)($s['html'] ?? '');
            case 'shortcode':
                return '<p>' . (string)($s['shortcode'] ?? '') . '</p>';
            case 'testimonial':
                return '<blockquote><p>' . $t('testimonial_content') . '</p>' . ($t('testimonial_name') !== '' ? '<cite>' . e($t('testimonial_name')) . '</cite>' : '') . '</blockquote>';
            case 'tabs':
            case 'accordion':
            case 'toggle':
            case 'nested-tabs':
            case 'nested-accordion':
                $out = '';
                foreach ((array)($s['tabs'] ?? $s['items'] ?? []) as $i) {
                    $out .= '<details><summary>' . e((string)($i['tab_title'] ?? $i['item_title'] ?? '')) . '</summary>' . (string)($i['tab_content'] ?? '') . '</details>';
                }
                return $out . $this->els((array)($el['elements'] ?? []));
            case 'image-gallery':
            case 'gallery':
                $ids = array_filter(array_map(static fn($g) => (int)($g['id'] ?? 0), (array)($s['wp_gallery'] ?? $s['gallery'] ?? [])));
                return $ids && $this->gallery ? ($this->gallery)($ids) : '';
            case 'image-carousel':
                $imgs = '';
                foreach ((array)($s['carousel'] ?? []) as $g) {
                    if (!empty($g['url'])) {
                        $imgs .= '<figure style="margin:0"><img src="' . e((string)$g['url']) . '" alt="" style="width:100%;height:auto"></figure>';
                    }
                }
                return $imgs !== '' ? '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px">' . $imgs . '</div>' : '';
            case 'counter':
                return '<p><strong>' . e((string)($s['ending_number'] ?? '')) . e($t('suffix')) . '</strong> ' . e($t('title')) . '</p>';
            case 'google_maps':
                return $t('address') !== '' ? '<p>' . e($t('address')) . '</p>' : '';
            case 'form':
                $this->forms[] = ['post_id' => $this->postId, 'widget_id' => (string)($el['id'] ?? ''), 'settings' => $s];
                $slug = $this->formSlug ? ($this->formSlug)('elementor', $this->postId . ':' . ($el['id'] ?? ''), (string)($s['form_name'] ?? '')) : null;
                return $slug ? '<p>[form slug="' . $slug . '"]</p>' : '';
            case 'posts':
            case 'loop-grid':
            case 'archive-posts':
            case 'loop-carousel':
                if ($this->mode === 'template') {
                    return $this->loop($w, $s);
                }
                break;
            case 'template':
                $tid = (int)($s['template_id'] ?? 0);
                $row = $tid ? Data::row('wpost', $tid) : null;
                if ($row) {
                    return $this->els(self::elementorData(Data::meta($tid)));
                }
                break;
        }
        foreach (['editor', 'description', 'content', 'text', 'title'] as $k) {
            if (is_string($s[$k] ?? null) && trim($s[$k]) !== '') {
                return '<div>' . $s[$k] . '</div>';
            }
        }
        if ($w !== '') {
            $this->skipped[$w] = ($this->skipped[$w] ?? 0) + 1;
        }
        return $this->els((array)($el['elements'] ?? []));
    }

    /** Theme Builder widgets → Slate tags (template mode only). */
    private function themeWidget(string $w, array $s): ?string
    {
        $h = preg_match('/^h[1-6]$/', (string)($s['header_size'] ?? '')) ? (string)$s['header_size'] : 'h1';
        return match ($w) {
            'theme-post-title', 'woocommerce-product-title' => "<$h>{{title}}</$h>",
            'theme-post-content', 'woocommerce-product-content' => '<div class="entry-content">{{{content}}}</div>',
            'theme-post-featured-image' => '{{#if image}}<figure class="featured">{{image}}</figure>{{/if}}',
            'theme-post-excerpt' => '<p class="excerpt">{{excerpt}}</p>',
            'post-info' => '<p class="post-info"><time>{{date}}</time> {{author}}</p>',
            'theme-archive-title', 'archive-title' => "<$h>{{archive.title}}</$h>",
            'theme-site-logo', 'site-logo', 'theme-site-title' => '<a class="site-title" href="{{site.url}}">{{site.name}}</a>',
            'nav-menu' => '{{menu:primary}}',
            'breadcrumbs' => '{{{breadcrumbs}}}',
            'author-box' => '<p class="author-box">{{author}}</p>',
            'post-navigation', 'post-comments', 'search-form', 'sitemap' => '',
            default => null,
        };
    }

    /** Posts / Loop Grid / Archive Posts widgets → a Slate loop. */
    private function loop(string $w, array $s): string
    {
        $item = '<article class="loop-item">{{#if image}}<a href="{{url}}">{{image:thumb}}</a>{{/if}}<h3><a href="{{url}}">{{title}}</a></h3><p>{{excerpt}}</p></article>';
        $tid = (int)($s['template_id'] ?? 0);
        if ($tid && ($meta = Data::meta($tid)) && ($data = self::elementorData($meta))) {
            $mode = $this->mode;
            $item = '<article class="loop-item">' . $this->els($data) . '</article>';
            $this->mode = $mode;
        }
        $grid = '<div class="loop" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:2rem">';
        if ($w === 'archive-posts' || ($s['posts_query_type'] ?? $s['query_type'] ?? '') === 'current_query' || ($s['posts_post_type'] ?? '') === 'current_query') {
            return $grid . '{{#posts}}' . $item . '{{else}}<p>Nothing here yet.</p>{{/posts}}</div>{{{pagination}}}';
        }
        $type = (string)($s['posts_post_type'] ?? $s['post_query_post_type'] ?? 'post');
        $type = Importer::slateTypeFor($type) ?? 'post';
        $limit = max(1, (int)($s['posts_per_page'] ?? $s['posts_posts_per_page'] ?? 6));
        return $grid . '{{#query type=' . $type . ' limit=' . $limit . '}}' . $item . '{{/query}}</div>';
    }

    /** Elementor dynamic tags of a widget → Slate tags: [setting => replacement]. */
    private function dynamic(array $d): array
    {
        $out = [];
        foreach ($d as $key => $tag) {
            if (!is_string($tag) || !preg_match('/name="([\w-]+)"(?:\s+settings="([^"]*)")?/', $tag, $m)) {
                continue;
            }
            $set = json_decode(rawurldecode($m[2] ?? ''), true) ?: [];
            $field = '';
            if (!empty($set['key'])) {
                $field = preg_replace('/[^a-z0-9_]/', '_', strtolower((string)(explode(':', (string)$set['key'])[1] ?? $set['key'])));
            }
            $out[$key] = match (true) {
                $m[1] === 'post-title' => '{{title}}',
                $m[1] === 'post-excerpt' => '{{excerpt}}',
                $m[1] === 'post-date', $m[1] === 'post-time' => '{{date}}',
                $m[1] === 'post-url' => '{{url}}',
                $m[1] === 'post-featured-image' => '{{image}}',
                $m[1] === 'author-name', $m[1] === 'author-info' => '{{author}}',
                $m[1] === 'archive-title' => '{{archive.title}}',
                $m[1] === 'archive-description' => '{{{archive.description}}}',
                $m[1] === 'site-title' => '{{site.name}}',
                $m[1] === 'site-url' => '{{site.url}}',
                $m[1] === 'site-tagline' => '{{site.tagline}}',
                in_array($m[1], ['acf-image', 'acf-gallery'], true) && $field !== '' => '{{field:' . $field . '|image}}',
                $m[1] === 'acf-url' && $field !== '' => '{{field:' . $field . '|url}}',
                str_starts_with($m[1], 'acf') && $field !== '' => '{{field:' . $field . '}}',
                default => '',
            };
            if ($out[$key] === '') {
                unset($out[$key]);
            }
        }
        return $out;
    }
}
