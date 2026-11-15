<?php
declare(strict_types=1);

namespace SlateModules\Multilingual;

use Slate\App;
use Slate\Auth;
use Slate\Frontend;
use Slate\Options;
use Slate\Posts;
use Slate\Router;
use Slate\Seo;

/**
 * Language-prefixed URLs and everything visitors see: routing, permalinks, redirects, <html lang>, hreflang,
 * canonical URLs of translated listings, the language switcher and template tags.
 *
 * Default language: no prefix (/about/). Other languages: /es/acerca/. The prefix is stripped in "route_path" so core
 * resolves the rest normally; permalinks get the prefix back through the "permalink" filter.
 */
final class Urls
{
    /** True once a public (front-end) request was routed through route_path. */
    public static bool $frontend = false;

    /** Language of the current front-end request (null outside the front end). */
    public static ?string $lang = null;

    /** Language requested with ?lang= on /api/v1 requests. */
    public static ?string $apiLang = null;

    /** Request path without the language prefix, e.g. "/acerca" or "/page/2". */
    public static string $path = '/';

    /** Path handed to core after language mapping (front-page / posts-page translations). */
    public static string $routed = '/';

    /** While > 0 the permalink filter returns core's unprefixed path. */
    private static int $raw = 0;

    /** Render the language home as a post listing (static front page without a translation). */
    private static bool $homeListing = false;

    /** The request is a sitemap: core builds it while "resolving", but its URLs are for visitors (prefixed). */
    private static bool $sitemap = false;

    /** Re-entry guard for route_resolved (Frontend::notFound() renders, and so fires it again). */
    private static bool $guarding = false;

    /** Language of the current request: front end, then API ?lang=, then the default language. */
    public static function current(): string
    {
        return self::$lang ?? self::$apiLang ?? Languages::default();
    }

    /** Language Posts::query() listings are limited to, or null when they are not filtered (admin, CLI, plain API). */
    public static function queryLanguage(): ?string
    {
        return self::$frontend ? self::$lang : self::$apiLang;
    }

    // ------------------------------------------------------------------ routing

    /** route_path (runs first): detect and strip a language prefix, map translated front/posts pages. */
    public static function routePath(mixed $path): mixed
    {
        if (!is_string($path) || !Languages::isActive()) {
            return $path;
        }
        self::$frontend = true;
        $lang = Languages::default();
        $rest = $path === '' ? '/' : $path;
        if (preg_match('#^/([^/]+)(/.*)?$#', $rest, $m) && ($code = Languages::byPrefix($m[1])) !== null) {
            $candidate = isset($m[2]) && $m[2] !== '' ? $m[2] : '/';
            // Sitemaps and robots.txt exist once, at the site root.
            if (!preg_match('#^/(robots\.txt|sitemap[^/]*\.xml)$#', $candidate)) {
                $lang = $code;
                $rest = $candidate;
            }
        }
        self::$sitemap = (bool)preg_match('#^/sitemap[^/]*\.xml$#', $rest);
        self::$lang = $lang;
        self::$path = $rest;
        self::$routed = $lang === Languages::default() ? $rest : self::mapTranslatedPages($rest, $lang);
        return self::$routed;
    }

    /** /es/ → Spanish translation of the static front page; /es/noticias/ (posts page translation) → the posts page. */
    private static function mapTranslatedPages(string $rest, string $lang): string
    {
        if (Options::get('front_page') !== 'page') {
            return $rest;
        }
        [$base, $suffix] = self::splitPage($rest);
        if ($base === '/') {
            if (isset($_GET['s'])) {
                return $rest;
            }
            $front = Posts::find((int)Options::get('front_page_id'));
            if (!$front || $front['status'] !== 'publish') {
                return $rest; // core lists posts on the home page; it is filtered to this language
            }
            $t = self::viewable(Translations::translation($front, $lang));
            if ($t && (int)$t['id'] !== (int)$front['id']) {
                return self::norm(self::rawPath($t)) . $suffix;
            }
            if (!Options::get('maintenance_mode') || Auth::can('access_admin')) {
                self::$homeListing = true; // no translation: this language's home lists its posts
            }
            return $rest;
        }
        $ppId = (int)Options::get('posts_page_id');
        $postsPage = $ppId ? Posts::find($ppId) : null;
        if ($postsPage) {
            $t = self::viewable(Translations::translation($postsPage, $lang));
            if ($t && (int)$t['id'] !== (int)$postsPage['id']) {
                $pRaw = self::norm(self::rawPath($postsPage));
                $tRaw = self::norm(self::rawPath($t));
                if ($base === $tRaw && $tRaw !== $pRaw) {
                    return $pRaw . $suffix;
                }
                if ($base === $pRaw && $tRaw !== $pRaw && in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
                    // /es/blog/ while a Spanish posts page exists → its own address.
                    redirect(url(Posts::trail(self::norm(self::pathOf($t)) . $suffix)) . self::queryString(), 301);
                }
            }
        }
        return $rest;
    }

    /** routes: the language home as a listing when the static front page has no translation. */
    public static function routes(mixed $r): void
    {
        if (!self::$homeListing || !$r instanceof Router) {
            return;
        }
        $handler = static function (string $n = '1'): void {
            self::renderHome(max(1, (int)$n));
        };
        $r->get('/', $handler);
        $r->get('/page/{n:\d+}', $handler);
    }

    /** The posts home (like core's "/" without a static front page) for a language without its own front page. */
    private static function renderHome(int $page): void
    {
        $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $rel = '/' . ltrim(substr($requestPath, strlen(base_path())), '/');
        if ($rel !== '/' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
            $want = (bool)Options::get('permalink_trailing_slash', true);
            if ($want !== str_ends_with($rel, '/')) {
                redirect(url($want ? rtrim($rel, '/') . '/' : rtrim($rel, '/')) . self::queryString(), 301);
            }
        }
        Posts::publishScheduled();
        $result = Posts::query(['type' => 'post', 'page' => $page, 'with_terms' => true]);
        Seo::$ctx = ['kind' => 'home', 'page' => $page];
        Frontend::page('home', ['result' => $result, 'title' => (string)Options::get('site_title')], ['home', 'index']);
    }

    // ------------------------------------------------------------------ links

    /** permalink: add the item's language prefix (unprefixed while core compares URLs during routing). */
    public static function permalink(mixed $path, mixed $post = null): mixed
    {
        if (self::$raw > 0 || !is_string($path) || !is_array($post) || !Languages::isActive()
            || !Languages::isTranslatable((string)($post['type'] ?? ''))) {
            return $path;
        }
        if (self::$frontend) {
            // Core resolves the (already unprefixed) request path by comparing it with permalinks.
            if (Frontend::resolving() && !self::$sitemap) {
                return $path;
            }
        }

        $lang = Translations::lang($post);
        if ($lang === Languages::default()) {
            return $path;
        }
        if (self::isFrontTranslation($post)) {
            $path = '/';
        }
        return self::prefixPath($path, $lang);
    }

    /** menu_item: menu links to content follow the visitor's language when a published translation exists. */
    public static function menuItem(mixed $item, mixed $post = null, mixed $term = null): mixed
    {
        if (!is_array($item) || !is_array($post) || empty($post['id']) || !self::$frontend || !Languages::isActive()
            || ($lang = self::current()) === Languages::default()) {
            return $item;
        }
        $t = Translations::translation($post, $lang, true);
        if (!$t || (int)$t['id'] === (int)$post['id']) {
            return $item;
        }
        $item['url'] = Posts::permalink($t);
        if (($item['label'] ?? '') === (string)$post['title']) {
            $item['label'] = (string)$t['title']; // automatic label (the item's title) → the translation's title
        }
        return $item;
    }

    /** term_link: term archives stay in the visitor's language. */
    public static function termLink(mixed $path, mixed $term = null): mixed
    {
        if (!is_string($path) || !self::$frontend || !Languages::isActive() || self::current() === Languages::default()
            || (Frontend::resolving() && !self::$sitemap)) {
            return $path;
        }
        return self::prefixPath($path, self::current());
    }

    /** Path with the language prefix: ("/acerca/", "es") → "/es/acerca/", ("/", "es") → "/es/". */
    public static function prefixPath(string $path, string $lang): string
    {
        $prefix = Languages::prefix($lang);
        if ($prefix === '') {
            return $path === '' ? '/' : $path;
        }
        if ($path === '' || $path === '/') {
            return Posts::trail('/' . $prefix);
        }
        return '/' . $prefix . '/' . ltrim($path, '/');
    }

    /** Site-relative path of an item's public URL (base directory removed), with its language prefix. */
    public static function pathOf(array $post): string
    {
        return self::relative(Posts::permalink($post));
    }

    /** Core's path of an item, without any language handling. */
    public static function rawPath(array $post): string
    {
        self::$raw++;
        try {
            return self::relative(Posts::permalink($post));
        } finally {
            self::$raw--;
        }
    }

    /** URL of a language's home page (relative to the site root, or absolute). */
    public static function homeUrl(string $lang, bool $absolute = false): string
    {
        $path = self::prefixPath('/', $lang);
        return $absolute ? site_url(ltrim($path, '/')) : url($path);
    }

    /** Is this page the translation of the static front page (then its address is the language home)? */
    public static function isFrontTranslation(array $post): bool
    {
        if (($post['type'] ?? '') !== 'page' || Options::get('front_page') !== 'page') {
            return false;
        }
        $fid = (int)Options::get('front_page_id');
        if (!$fid || (int)($post['id'] ?? 0) === $fid || ($g = Translations::group($post)) === '') {
            return false;
        }
        $front = Posts::find($fid);
        return $front !== null && Translations::group($front) === $g && Translations::lang($front) !== Translations::lang($post);
    }

    // ------------------------------------------------------------------ output

    /** html_lang: <html lang> of translated pages (default-language pages keep the site setting). */
    public static function htmlLang(mixed $code): mixed
    {
        if (!self::$frontend || !Languages::isActive() || ($lang = self::current()) === Languages::default()) {
            return $code;
        }
        return Languages::htmlLang($lang);
    }

    /** html_dir: text direction of translated pages. */
    public static function htmlDir(mixed $dir): mixed
    {
        if (!self::$frontend || !Languages::isActive() || ($lang = self::current()) === Languages::default()) {
            return $dir;
        }
        return Languages::dir($lang);
    }

    /**
     * route_resolved: a single item requested under another language's prefix → 301 to its own address;
     * items in a disabled language → 404.
     */
    public static function routeResolved(mixed $kind = '', mixed $vars = []): void
    {
        if (self::$guarding || !self::$frontend || !Languages::isActive() || $kind !== 'single' || !is_array($vars)
            || !is_array($vars['post'] ?? null) || isset($_GET['preview'])
            || !in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
            return;
        }
        $post = $vars['post'];
        if (!Languages::isTranslatable((string)($post['type'] ?? ''))) {
            return;
        }
        $lang = Translations::lang($post);
        if (!Languages::isEnabled($lang)) {
            self::$guarding = true;
            Frontend::notFound();
            exit;
        }
        $wrong = $lang !== self::$lang;
        if (!$wrong && $lang !== Languages::default() && self::isFrontTranslation($post)) {
            $wrong = self::splitPage(self::$path)[0] !== '/'; // reached through its slug instead of /es/
        }
        if ($wrong) {
            redirect(Posts::permalink($post) . self::queryString(), 301);
        }
    }

    /** frontend_vars: a translated posts page lists under its own title. */
    public static function frontendVars(mixed $vars, mixed $kind = ''): mixed
    {
        if (!is_array($vars) || $kind !== 'archive' || !self::$frontend || !Languages::isActive()
            || ($lang = self::current()) === Languages::default() || !($p = self::postsPageMatch())) {
            return $vars;
        }
        $t = Translations::translation($p, $lang, true);
        if ($t && (string)$t['title'] !== '') {
            $vars['title'] = (string)$t['title'];
            Seo::$ctx['title'] = (string)$t['title'];
        }
        return $vars;
    }

    /**
     * Address of the current page in every enabled language that has it, lang => absolute URL.
     * Single items: their published translations. Listings: the same listing under each language prefix.
     */
    public static function alternates(): array
    {
        if (!self::$frontend || !Languages::isActive()) {
            return [];
        }
        $c = Seo::$ctx;
        $kind = (string)($c['kind'] ?? '');
        $out = [];
        if ($kind === 'single') {
            $post = $c['post'] ?? null;
            if (!is_array($post) || !Languages::isTranslatable((string)($post['type'] ?? ''))) {
                return [];
            }
            $map = Translations::map($post, true);
            foreach (Languages::enabled() as $code => $l) {
                if (isset($map[$code])) {
                    $out[$code] = Posts::permalink($map[$code], true);
                }
            }
            return $out;
        }
        if (!in_array($kind, ['home', 'archive', 'term'], true)) {
            return [];
        }
        [$base, $suffix] = self::splitPage(self::$path);
        $postsPage = $kind === 'archive' ? self::postsPageMatch() : null;
        foreach (Languages::enabled() as $code => $l) {
            if ($postsPage) {
                $t = Translations::translation($postsPage, $code, true);
                $p = $t ? self::pathOf($t) : self::prefixPath(self::rawPath($postsPage), $code);
            } else {
                $p = self::prefixPath($base, $code);
            }
            $out[$code] = site_url(ltrim(Posts::trail(rtrim($p, '/') . $suffix), '/'));
        }
        return $out;
    }

    /** The posts page (static front page setting) when the current listing is it. */
    private static function postsPageMatch(): ?array
    {
        if (Options::get('front_page') !== 'page' || !($pp = (int)Options::get('posts_page_id')) || !($p = Posts::find($pp))) {
            return null;
        }
        return self::norm(self::splitPage(self::$routed)[0]) === self::norm(self::rawPath($p)) ? $p : null;
    }

    /** seo_head: hreflang alternates, og:locale, canonical of translated listings. */
    public static function seoHead(mixed $head): mixed
    {
        if (!is_string($head) || !self::$frontend || !Languages::isActive()) {
            return $head;
        }
        $lang = self::current();
        $default = Languages::default();
        $kind = (string)(Seo::$ctx['kind'] ?? '');
        $alts = self::alternates();
        if ($lang !== $default) {
            if (in_array($kind, ['home', 'archive'], true) && isset($alts[$lang])) {
                $core = Seo::canonical();
                if ($core !== '' && $core !== $alts[$lang]) {
                    $head = str_replace(['href="' . e($core) . '"', 'content="' . e($core) . '"'],
                        ['href="' . e($alts[$lang]) . '"', 'content="' . e($alts[$lang]) . '"'], $head);
                }
            }
            $head = (string)preg_replace('#<meta property="og:locale" content="[^"]*">#',
                '<meta property="og:locale" content="' . e(Languages::locale($lang)) . '">', $head, 1);
        }
        if (count($alts) < 2) {
            return $head;
        }
        $extra = [];
        foreach ($alts as $code => $u) {
            $extra[] = '<link rel="alternate" hreflang="' . e(Languages::htmlLang($code)) . '" href="' . e($u) . '">';
        }
        if (isset($alts[$default])) {
            $extra[] = '<link rel="alternate" hreflang="x-default" href="' . e($alts[$default]) . '">';
        }
        if (str_contains($head, 'property="og:locale"')) {
            foreach ($alts as $code => $u) {
                if ($code !== $lang) {
                    $extra[] = '<meta property="og:locale:alternate" content="' . e(Languages::locale($code)) . '">';
                }
            }
        }
        return $head . "\n" . implode("\n", $extra);
    }

    /** seo_schema: inLanguage and the page URL of translated pages. */
    public static function seoSchema(mixed $graph, mixed $c = null): mixed
    {
        if (!is_array($graph) || !self::$frontend || !Languages::isActive() || ($lang = self::current()) === Languages::default()) {
            return $graph;
        }
        $core = '';
        $mine = '';
        if (in_array(Seo::$ctx['kind'] ?? '', ['home', 'archive'], true)) {
            $mine = self::alternates()[$lang] ?? '';
            $core = $mine !== '' ? Seo::canonical() : '';
        }
        foreach ($graph as &$node) {
            if (!is_array($node)) {
                continue;
            }
            if (array_key_exists('inLanguage', $node)) {
                $node['inLanguage'] = Languages::htmlLang($lang);
            }
            if ($core !== '' && $core !== $mine) {
                if (($node['url'] ?? null) === $core) {
                    $node['url'] = $mine;
                }
                if (($node['@id'] ?? null) === $core . '#webpage') {
                    $node['@id'] = $mine . '#webpage';
                }
            }
        }
        unset($node);
        return $graph;
    }

    /** page_cache_kind: a language home lists content, like "/". */
    public static function pageCacheKind(mixed $kind, mixed $ctx = null): mixed
    {
        if (self::$frontend && Languages::isActive() && self::current() !== Languages::default() && self::splitPage(self::$path)[0] === '/') {
            return 'listing';
        }
        return $kind;
    }

    // ------------------------------------------------------------------ sitemaps

    /** sitemap_posts: leave out items in disabled languages; load their translation groups in a few queries. */
    public static function sitemapPosts(mixed $rows, mixed $type = '', mixed $page = 1): mixed
    {
        if (!is_array($rows) || !Languages::isActive() || !Languages::isTranslatable((string)$type)) {
            return $rows;
        }
        $rows = array_values(array_filter($rows, static fn($r) => !is_array($r) || Languages::isEnabled(Translations::lang($r))));
        Translations::primeGroups((string)$type, array_map(static fn($r) => Translations::group($r), array_filter($rows, 'is_array')));
        return $rows;
    }

    /** sitemap_urlset: declare the xhtml namespace used by hreflang links. */
    public static function sitemapUrlset(mixed $tag): mixed
    {
        if (!is_string($tag) || !Languages::isActive() || str_contains($tag, 'xmlns:xhtml=')) {
            return $tag;
        }
        return (string)preg_replace('/>\s*$/', ' xmlns:xhtml="http://www.w3.org/1999/xhtml">', $tag, 1);
    }

    /** sitemap_entry: <xhtml:link rel="alternate" hreflang> for every published translation (enabled languages). */
    public static function sitemapEntry(mixed $lines, mixed $post = null): mixed
    {
        if (!is_array($lines) || !$lines || !is_array($post) || !Languages::isActive()
            || !Languages::isTranslatable((string)($post['type'] ?? '')) || Translations::group($post) === '') {
            return $lines;
        }
        $map = Translations::map($post, true);
        $links = [];
        foreach (Languages::enabled() as $code => $l) {
            if (isset($map[$code])) {
                $links[$code] = Posts::permalink($map[$code], true);
            }
        }
        if (count($links) < 2) {
            return $lines;
        }
        $extra = [];
        foreach ($links as $code => $u) {
            $extra[] = '<xhtml:link rel="alternate" hreflang="' . e(Languages::htmlLang($code)) . '" href="' . e($u) . '"/>';
        }
        if (isset($links[Languages::default()])) {
            $extra[] = '<xhtml:link rel="alternate" hreflang="x-default" href="' . e($links[Languages::default()]) . '"/>';
        }
        $last = array_pop($lines);
        if (!is_string($last) || trim($last) !== '</url>') {
            // Unexpected shape (another module changed it): add the links before the closing tag wherever it is.
            $lines[] = $last;
            $html = implode("\n", array_map('strval', $lines));
            $pos = strrpos($html, '</url>');
            return $pos === false ? $lines : [substr($html, 0, $pos) . implode("\n", $extra) . "\n" . substr($html, $pos)];
        }
        return array_merge($lines, $extra, [$last]);
    }

    // ------------------------------------------------------------------ switcher & template tags

    /**
     * Language switcher HTML.
     * @param array{show?:string, hide_current?:bool, hide_missing?:bool, class?:string} $opts
     *   show: native (default) | name | code | both
     *   hide_missing: skip languages without a translation of the current item (instead of linking to their home page)
     */
    public static function switcher(array $opts = []): string
    {
        if (!Languages::isActive()) {
            return '';
        }
        $show = in_array($opts['show'] ?? 'native', ['native', 'name', 'code', 'both'], true) ? (string)($opts['show'] ?? 'native') : 'native';
        $current = self::current();
        $alts = self::alternates();
        $class = trim('ml-switcher ' . preg_replace('/[^A-Za-z0-9_ -]/', '', (string)($opts['class'] ?? '')));
        $items = '';
        foreach (Languages::enabled() as $code => $l) {
            $isCurrent = $code === $current;
            if ($isCurrent && !empty($opts['hide_current'])) {
                continue;
            }
            $href = $alts[$code] ?? null;
            $missing = $href === null;
            if ($missing && !empty($opts['hide_missing']) && !$isCurrent) {
                continue;
            }
            if ($href === null) {
                $href = $isCurrent ? (string)($_SERVER['REQUEST_URI'] ?? self::homeUrl($code)) : self::homeUrl($code);
            }
            $label = match ($show) {
                'name' => $l['name'],
                'code' => strtoupper($code),
                'both' => $l['native'] . ' (' . strtoupper($code) . ')',
                default => $l['native'],
            };
            $hl = Languages::htmlLang($code);
            $items .= '<li class="ml-item ml-item-' . e($code) . ($isCurrent ? ' ml-current' : '') . ($missing ? ' ml-missing' : '') . '">'
                . '<a href="' . e($href) . '" hreflang="' . e($hl) . '" lang="' . e($hl) . '" dir="' . e(Languages::dir($code)) . '"'
                . ($isCurrent ? ' aria-current="true"' : '') . '>' . e($label) . '</a></li>';
        }
        if ($items === '') {
            return '';
        }
        return '<nav class="' . e($class) . '" aria-label="' . e(Strings::translate('Language')) . '"><ul class="ml-list">' . $items . '</ul></nav>';
    }

    /** template_tag: {{languages}} {{languages:code}} {{lang}} {{lang:name}} {{lang:native}} {{lang:dir}} {{t:Read more}} */
    public static function templateTag(mixed $value, mixed $name = '', mixed $arg = '', mixed $ctx = [], mixed $raw = false, mixed $filter = ''): mixed
    {
        if ($value !== null) {
            return $value;
        }
        $arg = (string)$arg;
        $lang = self::current();
        return match ((string)$name) {
            'languages' => self::switcher(['show' => $arg !== '' ? $arg : 'native']),
            'lang' => e(match ($arg) {
                'name' => Languages::name($lang),
                'native' => Languages::native($lang),
                'dir' => Languages::dir($lang),
                'html' => Languages::htmlLang($lang),
                default => $lang,
            }),
            't' => $raw ? Strings::translate($arg) : e(Strings::translate($arg)),
            default => $value,
        };
    }

    // ------------------------------------------------------------------ API

    /** init: ?lang=xx on /api/v1 requests limits listings to that language. */
    public static function detectApi(): void
    {
        if (!Languages::isActive() || !isset($_GET['lang']) || !is_string($_GET['lang'])) {
            return;
        }
        $path = App::path();
        if ($path !== '/api/v1' && !str_starts_with($path, '/api/v1/')) {
            return;
        }
        $code = Languages::cleanCode($_GET['lang']);
        if ($code !== null && Languages::get($code) !== null) {
            self::$apiLang = $code;
        }
    }

    // ------------------------------------------------------------------ helpers

    /** ['/blog', '/page/2'] from '/blog/page/2'; ['/', ''] from '/'. */
    public static function splitPage(string $path): array
    {
        if (preg_match('#^(.*?)/page/(\d+)/?$#', $path, $m)) {
            return [self::norm($m[1] === '' ? '/' : $m[1]), '/page/' . $m[2]];
        }
        return [self::norm($path), ''];
    }

    private static function norm(string $path): string
    {
        $path = '/' . trim($path, '/');
        return $path;
    }

    private static function relative(string $url): string
    {
        $path = (string)(parse_url($url, PHP_URL_PATH) ?: '/');
        $base = base_path();
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }
        return '/' . ltrim($path, '/');
    }

    private static function viewable(?array $post): ?array
    {
        if (!$post) {
            return null;
        }
        return $post['status'] === 'publish' || ($post['status'] === 'private' && Auth::can('edit_others_posts')) ? $post : null;
    }

    private static function queryString(): string
    {
        $qs = (string)($_SERVER['QUERY_STRING'] ?? '');
        return $qs !== '' ? '?' . $qs : '';
    }
}
