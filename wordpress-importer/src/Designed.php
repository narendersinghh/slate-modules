<?php
declare(strict_types=1);

namespace SlateModules\WpImport;

use Slate\HtmlPages;
use Slate\Media;

/**
 * A page made with a page builder (Elementor, Divi, WPBakery, Beaver, Bricks…) → a Slate "designed page" (HtmlPages):
 * the rendered page from the live site, its own stylesheets / scripts / fonts copied to this server at their original
 * /wp-content/ paths (the same store as the theme mirror: the "WordPress mirror" theme rewrites exactly those paths, so
 * designed pages look right with or without it; files not copied yet are fetched once on first request),
 * media pointed at the imported library, links pointed at the new addresses, forms replaced by Slate forms,
 * and every text / image editable by clicking (HtmlPages::annotate). WordPress-only head tags are dropped
 * (Slate prints its own SEO tags). Fetched code is stored, never run on the server.
 */
final class Designed
{
    /** Tracking / WordPress-only scripts that are not copied. */
    private const SKIP_JS = '#(googletagmanager|gtag\(|fbq\(|clarity\.ms|hotjar|wp-emoji|_wpemojiSettings|admin-bar|wp-embed\.min|connect\.facebook|google-analytics|analytics\.js|cdn-cgi/challenge|comment-reply|wp-includes/js/dist/hooks|wpcf7|contact-form-7|wpforms|gravityforms|gform_|fluentform|recaptcha)#i';

    private Mirror $mirror;
    private string $origin;
    private string $host;
    /** fn(string old path without trailing slash): ?string new URL of an imported item. */
    private $link;
    public array $notes = [];

    public function __construct(string $origin, callable $link)
    {
        $this->origin = rtrim($origin, '/');
        $port = parse_url($this->origin, PHP_URL_PORT);
        $this->host = strtolower(preg_replace('/^www\./', '', (string)parse_url($this->origin, PHP_URL_HOST)) . ($port ? ':' . $port : ''));
        $this->mirror = new Mirror($this->origin);
        $this->link = $link;
    }

    public function mirror(): Mirror
    {
        return $this->mirror;
    }

    /**
     * Build page data from a rendered page. $formSlug: fn(plugin, id): ?string.
     * Returns ['title', 'description', 'data' => HtmlPages data, 'notes' => []].
     */
    public function build(string $html, callable $formSlug): array
    {
        $this->notes = [];
        $html = $this->cleanHead($html);
        $html = FormsMap::replaceRendered($html, $formSlug, static fn(string $slug) => '<div class="slate-form-widget" data-slate-form-widget="' . e($slug) . '"></div>');
        $html = $this->rewriteScripts($html);
        $html = Loops::dynamize($html, $this->notes);
        $parsed = HtmlPages::fromDocument($html, fn(string $u) => $this->url($u), 'import');
        $parsed['notes'] = $this->notes;
        return $parsed;
    }

    /** Drop head tags Slate prints itself (canonical, social, schema, feeds) and tracking or WordPress-only scripts. */
    private function cleanHead(string $html): string
    {
        $doc = HtmlPages::load($html);
        $x = new \DOMXPath($doc);
        $remove = [
            '//link[@rel="canonical" or @rel="shortlink" or @rel="EditURI" or @rel="wlwmanifest" or @rel="pingback" or @rel="alternate" or @rel="https://api.w.org/" or @rel="dns-prefetch" or @rel="profile"]',
            '//meta[starts-with(@property, "og:") or starts-with(@name, "twitter:") or starts-with(@property, "article:") or @name="generator" or @name="msapplication-TileImage"]',
            '//script[@type="application/ld+json"]', '//script[contains(@class, "yoast-schema-graph")]', '//*[@id="wpadminbar"]', '//style[@id="admin-bar-inline-css"]',
            '//style[@id="wp-emoji-styles-inline-css"]', '//link[contains(@href, "wp-emoji")]',
            '//noscript[contains(., "googletagmanager")]', '//link[contains(@href, "contact-form-7") or contains(@href, "wpforms") or contains(@href, "gravityforms")]',
        ];
        foreach ($remove as $q) {
            foreach (iterator_to_array($x->query($q) ?: []) as $n) {
                $n->parentNode?->removeChild($n);
            }
        }
        foreach (iterator_to_array($x->query('//script') ?: []) as $s) {
            /** @var \DOMElement $s */
            $src = $s->getAttribute('src');
            $code = $src === '' ? (string)$s->textContent : '';
            if (preg_match(self::SKIP_JS, $src !== '' ? $src : $code) || ($src !== '' && !$this->own($src) && !preg_match('#^(https?:)?//(cdn|ajax|unpkg|fonts)\.#i', $src) && preg_match('#(track|pixel|tag|analytics|ads)#i', $src))) {
                $s->parentNode?->removeChild($s);
            }
        }
        return (string)$doc->saveHTML();
    }

    /** JSON-escaped and plain own URLs inside inline scripts and data-settings attributes (Elementor config). */
    private function rewriteScripts(string $html): string
    {
        $h = preg_quote($this->host, '#');
        // https:\/\/example.com\/wp-content\/…  (JSON inside scripts and attributes)
        $html = (string)preg_replace_callback('#(?:https?:)?\\\\/\\\\/(?:www\.)?' . $h . '((?:\\\\/[^"\'\s<>&\\\\]*)*\\\\/?)#i', function ($m) {
            $path = str_replace('\\/', '/', $m[1]) ?: '/';
            return str_replace('/', '\\/', $this->url($path, true));
        }, $html);
        // Plain own URLs inside inline <script> blocks.
        return (string)preg_replace_callback('#(<script\b(?![^>]*\bsrc=)[^>]*>)(.*?)(</script>)#is', function ($m) {
            $code = (string)preg_replace_callback('#(?:https?:)?//(?:www\.)?' . preg_quote($this->host, '#') . '(/[^"\'\s<>]*)?#i', fn($u) => $this->url($u[1] ?? '/', true), $m[2]);
            return $m[1] . $code . $m[3];
        }, $html);
    }

    private function own(string $u): bool
    {
        if (str_starts_with($u, '/') && !str_starts_with($u, '//')) {
            return true;
        }
        $p = parse_url(str_starts_with($u, '//') ? 'https:' . $u : $u);
        $host = strtolower(preg_replace('/^www\./', '', (string)($p['host'] ?? '')) . (isset($p['port']) ? ':' . $p['port'] : ''));
        return $host === $this->host;
    }

    /**
     * New URL for a URL found in the page: media → imported media, theme / plugin files → copied files,
     * links to imported pages → their new address, other own links → root-relative. $lazy: inside scripts,
     * files are not downloaded now (the compatibility route fetches them on first request).
     */
    public function url(string $u, bool $lazy = false): string
    {
        $u = trim(html_entity_decode($u, ENT_QUOTES));
        if ($u === '' || preg_match('#^(data:|mailto:|tel:|javascript:|\#|\{\{)#i', $u) || !$this->own($u)) {
            return $u;
        }
        $abs = str_starts_with($u, '/') && !str_starts_with($u, '//') ? $this->origin . $u : (str_starts_with($u, '//') ? 'https:' . $u : $u);
        $path = (string)parse_url($abs, PHP_URL_PATH);
        $query = parse_url($abs, PHP_URL_QUERY);
        $frag = parse_url($abs, PHP_URL_FRAGMENT);
        $suffix = ($frag ? '#' . $frag : '');
        if (str_starts_with($path, '/wp-content/uploads/') && !str_contains($path, '/uploads/elementor/')) {
            $id = Importer::mediaByUrl($path);
            if ($id && ($m = Media::find($id))) {
                return Media::url($m);
            }
        }
        if (preg_match('#^/(wp-content|wp-includes)/#', $path)) {
            if ($lazy) {
                return $this->mirror->localUrl($path) . ($query ? '?' . $query : '');
            }
            $local = $this->mirror->assetPath($abs);
            return $local !== null ? $this->mirror->asset($abs) : $this->mirror->localUrl($path);
        }
        if (str_starts_with($path, '/wp-admin/') || str_starts_with($path, '/wp-json/') || $path === '/xmlrpc.php') {
            return url(ltrim($path, '/')) . ($query ? '?' . $query : '');
        }
        $key = rtrim(rawurldecode($path), '/');
        if (($new = ($this->link)($key)) !== null) {
            return $new . $suffix;
        }
        return url(ltrim($path, '/')) . ($query ? '?' . $query : '') . $suffix;
    }
}
