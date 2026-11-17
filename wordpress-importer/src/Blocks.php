<?php
declare(strict_types=1);

namespace SlateModules\WpImport;

/**
 * Gutenberg (block editor) content → clean HTML for Slate's editor, and block-theme templates → Slate template tags.
 *
 * - Static blocks keep their saved HTML; block comments are removed.
 * - Core blocks are mapped to simple markup with inline layout styles (columns, gallery, buttons, cover, media & text),
 *   embeds become responsive <figure class="embed"> iframes, reusable blocks are inlined.
 * - Unknown static blocks keep their HTML; dynamic blocks that need WordPress to render are listed in $this->dropped
 *   (with the REST API the already rendered HTML is used instead, so nothing is lost there).
 * - In template mode, post / query blocks become {{title}}, {{{content}}}, {{#posts}}, {{#query}} and friends.
 */
final class Blocks
{
    /** Dynamic blocks dropped from content (name => count), for the report. */
    public array $dropped = [];
    private string $mode;
    private int $depth = 0;

    public function __construct(string $mode = 'content')
    {
        $this->mode = $mode;
    }

    public static function has(string $html): bool
    {
        return str_contains($html, '<!-- wp:');
    }

    // ------------------------------------------------------------------ parsing

    /** Parse block markup into a tree: list of string|['name','attrs','inner' => list]. */
    public static function parse(string $html): array
    {
        $re = '/<!--\s+(\/)?wp:([a-z][a-z0-9_-]*\/)?([a-z][a-z0-9_-]*)\s+(\{(?:[^}]|\}(?!\s*\/?-->))*\})?\s*(\/)?-->/s';
        preg_match_all($re, $html, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $root = ['name' => '#root', 'attrs' => [], 'inner' => []];
        $stack = [&$root];
        $pos = 0;
        foreach ($mm as $m) {
            $start = $m[0][1];
            $top = &$stack[count($stack) - 1];
            if ($start > $pos) {
                $top['inner'][] = substr($html, $pos, $start - $pos);
            }
            $pos = $start + strlen($m[0][0]);
            $ns = rtrim($m[2][0] ?? '', '/');
            $name = ($ns !== '' ? $ns : 'core') . '/' . $m[3][0];
            $attrs = isset($m[4]) && $m[4][0] !== '' ? (json_decode($m[4][0], true) ?: []) : [];
            if (($m[1][0] ?? '') === '/') {
                if (count($stack) > 1) {
                    array_pop($stack);
                }
                unset($top);
                continue;
            }
            $node = ['name' => $name, 'attrs' => $attrs, 'inner' => []];
            if (($m[5][0] ?? '') === '/') {
                $top['inner'][] = $node;
            } else {
                $top['inner'][] = $node;
                $stack[] = &$top['inner'][count($top['inner']) - 1];
            }
            unset($top);
        }
        if ($pos < strlen($html)) {
            $stack[count($stack) - 1]['inner'][] = substr($html, $pos);
        }
        return $root['inner'];
    }

    // ------------------------------------------------------------------ rendering

    /** Block markup → HTML (content mode) or Slate template (template mode). */
    public function render(string $html): string
    {
        if (!self::has($html)) {
            return $this->mode === 'content' ? self::tidy(self::autop($html)) : $html;
        }
        $out = $this->renderList(self::parse($html));
        return $this->mode === 'content' ? self::tidy($out) : $out;
    }

    private function renderList(array $nodes): string
    {
        $out = '';
        foreach ($nodes as $n) {
            $out .= is_string($n) ? $n : $this->block($n);
        }
        return $out;
    }

    private function block(array $b): string
    {
        $name = $b['name'];
        $a = $b['attrs'];
        $inner = $this->renderList($b['inner']);
        if ($this->mode === 'template' && ($t = $this->templateBlock($name, $a, $b, $inner)) !== null) {
            return $t;
        }
        switch ($name) {
            case 'core/embed':
            case 'core-embed/youtube':
            case 'core-embed/vimeo':
                return self::embed((string)($a['url'] ?? self::firstUrl($inner)), $inner);
            case 'core/block':
                $ref = (int)($a['ref'] ?? 0);
                $row = $ref ? Data::row('wpost', $ref) : null;
                if ($row && $this->depth < 5) {
                    $this->depth++;
                    $html = $this->renderList(self::parse((string)$row['content']));
                    $this->depth--;
                    return $html;
                }
                $this->dropped['reusable block'] = ($this->dropped['reusable block'] ?? 0) + 1;
                return '';
            case 'core/spacer':
                $h = preg_replace('/[^0-9a-z.%]/i', '', (string)($a['height'] ?? '40px'));
                return '<div style="height:' . (is_numeric($h) ? $h . 'px' : $h) . '" aria-hidden="true"></div>';
            case 'core/more':
            case 'core/nextpage':
                return '';
            case 'core/shortcode':
            case 'core/html':
            case 'core/freeform':
                return $inner;
        }
        if (trim(strip_tags($inner, '<img><iframe><video><audio><hr><svg><input><br>')) === '' && !str_contains($inner, '<img') && !str_contains($inner, '<hr')
            && !in_array($name, ['core/separator', 'core/spacer', 'core/column', 'core/group'], true)) {
            // A dynamic block: nothing saved, WordPress renders it on the fly.
            $label = str_starts_with($name, 'core/') ? substr($name, 5) : $name;
            $this->dropped[$label] = ($this->dropped[$label] ?? 0) + 1;
            return '';
        }
        return $inner;
    }

    /** Post and query blocks of block themes → Slate template tags. */
    private function templateBlock(string $name, array $a, array $b, string $inner): ?string
    {
        $lvl = max(1, min(6, (int)($a['level'] ?? 2)));
        return match ($name) {
            'core/post-title', 'core/query-title' => $name === 'core/query-title' ? '<h1>{{archive.title}}</h1>'
                : '<h' . $lvl . '>' . (!empty($a['isLink']) ? '<a href="{{url}}">{{title}}</a>' : '{{title}}') . '</h' . $lvl . '>',
            'core/post-content' => '<div class="entry-content">{{{content}}}</div>',
            'core/post-excerpt' => '<p class="excerpt">{{excerpt}}</p>',
            'core/post-featured-image' => '{{#if image}}<figure class="featured">' . (!empty($a['isLink']) ? '<a href="{{url}}">{{image}}</a>' : '{{image}}') . '</figure>{{/if}}',
            'core/post-date' => '<time>{{date}}</time>',
            'core/post-author', 'core/post-author-name' => '<span class="author">{{author}}</span>',
            'core/post-terms' => '<div class="terms">{{terms:' . (($a['term'] ?? 'category') === 'post_tag' ? 'tag' : (string)($a['term'] ?? 'category')) . '}}</div>',
            'core/term-description' => '<div class="term-description">{{{archive.description}}}</div>',
            'core/query' => $this->query($a, $b),
            'core/post-template' => $inner,
            'core/query-pagination', 'core/query-pagination-next', 'core/query-pagination-previous', 'core/query-pagination-numbers' => $name === 'core/query-pagination' ? '{{{pagination}}}' : '',
            'core/query-no-results' => '',
            'core/template-part' => '',
            'core/site-title' => '<a href="{{site.url}}">{{site.name}}</a>',
            'core/site-tagline' => '<p>{{site.tagline}}</p>',
            'core/navigation' => '{{menu:primary}}',
            'core/comments', 'core/comments-query-loop', 'core/post-comments-form', 'core/comment-template' => '',
            default => null,
        };
    }

    private function query(array $a, array $b): string
    {
        $q = (array)($a['query'] ?? []);
        $tpl = '';
        $empty = '';
        foreach ($b['inner'] as $n) {
            if (is_array($n) && $n['name'] === 'core/post-template') {
                $tpl = $this->renderList($n['inner']);
            } elseif (is_array($n) && $n['name'] === 'core/query-no-results') {
                $empty = trim(strip_tags($this->renderList($n['inner'])));
            }
        }
        $rest = '';
        foreach ($b['inner'] as $n) {
            if (is_array($n) && in_array($n['name'], ['core/query-pagination'], true)) {
                $rest .= '{{{pagination}}}';
            }
        }
        $item = '<article class="loop-item">' . $tpl . '</article>';
        if (!empty($q['inherit'])) {
            return '<div class="loop">{{#posts}}' . $item . ($empty !== '' ? '{{else}}<p>' . e($empty) . '</p>' : '') . '{{/posts}}</div>' . $rest;
        }
        $type = (string)($q['postType'] ?? 'post');
        $args = 'type=' . preg_replace('/[^a-z0-9_-]/', '', $type) . ' limit=' . max(1, (int)($q['perPage'] ?? 6));
        if (!empty($q['orderBy'])) {
            $args .= ' orderby=' . (['date' => 'published_at', 'title' => 'title'][$q['orderBy']] ?? 'published_at') . ' order=' . (($q['order'] ?? 'desc') === 'asc' ? 'asc' : 'desc');
        }
        return '<div class="loop">{{#query ' . $args . '}}' . $item . '{{/query}}</div>';
    }

    // ------------------------------------------------------------------ helpers

    private static function firstUrl(string $html): string
    {
        return preg_match('#https?://[^\s<"\']+#', strip_tags($html, '<a>'), $m) ? $m[0] : '';
    }

    /** Responsive iframe for YouTube / Vimeo, a link for anything else. */
    public static function embed(string $url, string $inner = ''): string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES));
        $src = '';
        if (preg_match('#(?:youtube\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/)|youtu\.be/)([\w-]{6,})#', $url, $m)) {
            $src = 'https://www.youtube-nocookie.com/embed/' . $m[1];
        } elseif (preg_match('#vimeo\.com/(?:video/)?(\d+)#', $url, $m)) {
            $src = 'https://player.vimeo.com/video/' . $m[1];
        }
        $cap = preg_match('#<figcaption[^>]*>(.*?)</figcaption>#s', $inner, $c) ? '<figcaption>' . trim(strip_tags($c[1], '<a><em><strong>')) . '</figcaption>' : '';
        if ($src !== '') {
            return '<figure class="embed"><iframe src="' . e($src) . '" width="560" height="315" title="Video" allow="accelerometer; encrypted-media; picture-in-picture" allowfullscreen loading="lazy"></iframe>' . $cap . '</figure>';
        }
        return $url !== '' ? '<p><a href="' . e($url) . '">' . e($url) . '</a></p>' : $inner;
    }

    /** Classic editor content: wrap loose text blocks in paragraphs (like wpautop, simplified). */
    public static function autop(string $html): string
    {
        if (trim($html) === '' || preg_match('#<(p|div|h[1-6]|ul|ol|table|figure|section)\b#i', $html)) {
            return $html;
        }
        $parts = preg_split("/\n\s*\n/", str_replace("\r\n", "\n", trim($html))) ?: [];
        return implode("\n", array_map(static fn($p) => preg_match('#^\s*(<(blockquote|pre|iframe|figure|hr|img|\[)|\[)#i', $p) ? $p : '<p>' . nl2br(trim($p), false) . '</p>', $parts));
    }

    /** Map rendered core-block markup to Slate-friendly HTML (works on REST "rendered" content and on converted raw blocks). */
    public static function tidy(string $html): string
    {
        if (trim($html) === '' || !preg_match('/wp-block|wp-image|has-|is-style|wp-element/', $html)) {
            return trim($html);
        }
        [$doc, $root] = \Slate\HtmlPages::fragment($html);
        $x = new \DOMXPath($doc);
        $has = static fn(string $cls) => "contains(concat(' ', normalize-space(@class), ' '), ' $cls ')";
        $style = static function (\DOMElement $el, string $add): void {
            $cur = trim($el->getAttribute('style'));
            $el->setAttribute('style', $add . ($cur !== '' ? ';' . $cur : ''));
        };
        foreach (iterator_to_array($x->query('.//*[' . $has('wp-block-embed__wrapper') . ']', $root)) as $w) {
            $url = trim($w->textContent);
            if ($url !== '' && !$x->query('.//iframe', $w)->length && preg_match('#^https?://\S+$#', $url)) {
                $fig = $w->parentNode;
                $frag = \Slate\HtmlPages::fragment(self::embed($url));
                $new = $doc->importNode($frag[1]->firstChild, true);
                $fig instanceof \DOMElement && strtolower($fig->tagName) === 'figure' ? $fig->parentNode->replaceChild($new, $fig) : $w->parentNode->replaceChild($new, $w);
            } elseif ($w->parentNode instanceof \DOMElement) {
                $w->parentNode->setAttribute('class', 'embed');
                while ($w->firstChild) {
                    $w->parentNode->insertBefore($w->firstChild, $w);
                }
                $w->parentNode->removeChild($w);
            }
        }
        foreach (iterator_to_array($x->query('.//*[' . $has('wp-block-columns') . ']', $root)) as $el) {
            $style($el, 'display:flex;flex-wrap:wrap;gap:2rem');
            $el->setAttribute('class', 'columns');
        }
        foreach (iterator_to_array($x->query('.//*[' . $has('wp-block-column') . ']', $root)) as $el) {
            $basis = preg_match('/flex-basis:\s*([\d.]+%|[\d.]+px)/', $el->getAttribute('style'), $m) ? $m[1] : '0';
            $el->setAttribute('style', 'flex:1 1 ' . $basis . ';min-width:220px');
            $el->setAttribute('class', 'column');
        }
        foreach (iterator_to_array($x->query('.//*[' . $has('wp-block-gallery') . ']', $root)) as $el) {
            $cols = preg_match('/columns-(\d)/', $el->getAttribute('class'), $m) ? (int)$m[1] : 0;
            $el->setAttribute('style', 'display:grid;grid-template-columns:' . ($cols ? "repeat($cols,1fr)" : 'repeat(auto-fill,minmax(180px,1fr))') . ';gap:12px;margin:1.5em 0');
            $el->setAttribute('class', 'gallery');
            foreach ($x->query('.//figure', $el) as $f) {
                $f->setAttribute('style', 'margin:0');
            }
            foreach ($x->query('.//img', $el) as $img) {
                $img->setAttribute('style', 'width:100%;height:100%;object-fit:cover;display:block');
            }
        }
        foreach (iterator_to_array($x->query('.//*[' . $has('wp-block-buttons') . ']', $root)) as $el) {
            $el->setAttribute('class', 'buttons');
            $el->setAttribute('style', 'display:flex;flex-wrap:wrap;gap:12px;margin:1.5em 0');
        }
        foreach (iterator_to_array($x->query('.//a[' . $has('wp-block-button__link') . ']', $root)) as $a) {
            $wrap = $a->parentNode;
            $outline = $wrap instanceof \DOMElement && str_contains($wrap->getAttribute('class'), 'is-style-outline');
            $a->setAttribute('class', $outline ? 'button button-outline' : 'button');
            if ($wrap instanceof \DOMElement && str_contains($wrap->getAttribute('class'), 'wp-block-button')) {
                $wrap->parentNode->replaceChild($a, $wrap);
            }
        }
        foreach (iterator_to_array($x->query('.//*[' . $has('wp-block-cover') . ']', $root)) as $el) {
            $bg = $x->query('.//img[' . $has('wp-block-cover__image-background') . ']', $el)->item(0);
            $css = 'position:relative;min-height:' . (preg_match('/min-height:\s*([\d.]+(px|vh|em|rem))/', $el->getAttribute('style'), $m) ? $m[1] : '360px')
                . ';display:flex;align-items:center;justify-content:center;padding:2rem;color:#fff;background:#333 center/cover no-repeat';
            if ($bg instanceof \DOMElement) {
                $css .= ';background-image:url(' . $bg->getAttribute('src') . ')';
                $bg->parentNode->removeChild($bg);
            }
            foreach (iterator_to_array($x->query('.//*[' . $has('wp-block-cover__background') . ']', $el)) as $ov) {
                $ov->parentNode->removeChild($ov);
            }
            $el->setAttribute('style', $css);
            $el->setAttribute('class', 'cover');
        }
        foreach (iterator_to_array($x->query('.//*[' . $has('wp-block-media-text') . ']', $root)) as $el) {
            $el->setAttribute('style', 'display:flex;flex-wrap:wrap;gap:2rem;align-items:center');
            $el->setAttribute('class', 'media-text');
            foreach ($x->query('./*', $el) as $kid) {
                $kid->setAttribute('style', 'flex:1 1 280px');
            }
        }
        foreach (iterator_to_array($x->query('.//*[' . $has('wp-block-pullquote') . ']', $root)) as $el) {
            $q = $x->query('.//blockquote', $el)->item(0);
            if ($q) {
                $el->parentNode->replaceChild($q, $el);
            }
        }
        foreach (iterator_to_array($x->query('.//*[' . $has('wp-block-group__inner-container') . ']', $root)) as $el) {
            while ($el->firstChild) {
                $el->parentNode->insertBefore($el->firstChild, $el);
            }
            $el->parentNode->removeChild($el);
        }
        // Remove WordPress-only classes, keep alignment and anything else.
        foreach ($x->query('.//*[@class]', $root) as $el) {
            $keep = array_filter(preg_split('/\s+/', $el->getAttribute('class')) ?: [], static fn($c) => $c !== ''
                && !preg_match('/^(wp-block|wp-image-|wp-element|has-|is-style-|is-layout|is-type|is-provider|is-content|size-|wp-embed|wp-container|is-nowrap|are-vertically|is-vertically|is-stacked|is-not-stacked|is-cropped|columns-default)/', $c));
            $keep ? $el->setAttribute('class', implode(' ', $keep)) : $el->removeAttribute('class');
        }
        foreach ($x->query('.//*[@data-id] | .//*[@data-link]', $root) as $el) {
            $el->removeAttribute('data-id');
            $el->removeAttribute('data-link');
        }
        return trim(\Slate\HtmlPages::inner($doc, $root));
    }
}
