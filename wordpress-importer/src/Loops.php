<?php
declare(strict_types=1);

namespace SlateModules\WpImport;

use Slate\HtmlPages;

/**
 * Post lists rendered by WordPress (Elementor Posts / Loop Grid / Archive Posts widgets, the Query Loop block)
 * → live Slate loops: the first rendered item becomes the template ({{title}}, {{url}}, {{image_url}}, {{excerpt}},
 * {{date}}) inside {{#query type=… limit=…}}, so the list keeps the design and shows current content.
 */
final class Loops
{
    private const CONTAINERS = [
        // [list container, item, title link, image, excerpt, date, read more]
        'elementor' => ['//*[contains(@class,"elementor-posts-container")]', './article', './/*[contains(@class,"elementor-post__title")]//a', './/*[contains(@class,"elementor-post__thumbnail")]//img',
            './/*[contains(@class,"elementor-post__excerpt")]', './/*[contains(@class,"elementor-post-date")]', './/a[contains(@class,"elementor-post__read-more")] | .//a[contains(@class,"elementor-post__thumbnail__link")]'],
        'loop' => ['//*[contains(@class,"elementor-loop-container")]', './*[contains(@class,"e-loop-item")]', './/*[contains(@class,"elementor-widget-theme-post-title")]//a | .//*[contains(@class,"elementor-widget-theme-post-title")]//*[self::h1 or self::h2 or self::h3 or self::h4]',
            './/*[contains(@class,"elementor-widget-theme-post-featured-image")]//img', './/*[contains(@class,"elementor-widget-theme-post-excerpt")]//*[contains(@class,"elementor-widget-container")]',
            './/*[contains(@class,"elementor-post-info__item--type-date")]', './/a[contains(@class,"elementor-button")]'],
        'block' => ['//ul[contains(@class,"wp-block-post-template")]', './li', './/*[contains(@class,"wp-block-post-title")]//a | .//*[contains(@class,"wp-block-post-title")]',
            './/*[contains(@class,"wp-block-post-featured-image")]//img', './/*[contains(@class,"wp-block-post-excerpt__excerpt")]', './/*[contains(@class,"wp-block-post-date")]//time',
            './/a[contains(@class,"wp-block-post-excerpt__more-link")]'],
    ];

    /** Replace rendered post lists in a full HTML document. Notes get a line per converted list. */
    public static function dynamize(string $html, array &$notes): string
    {
        if (!preg_match('/elementor-posts-container|elementor-loop-container|wp-block-post-template/', $html)) {
            return $html;
        }
        $doc = HtmlPages::load($html);
        $x = new \DOMXPath($doc);
        $changed = false;
        foreach (self::CONTAINERS as $kind => [$cq, $iq, $tq, $imq, $exq, $dq, $mq]) {
            foreach (iterator_to_array($x->query($cq) ?: []) as $box) {
                /** @var \DOMElement $box */
                $items = iterator_to_array($x->query($iq, $box) ?: []);
                if (!$items) {
                    continue;
                }
                $first = $items[0];
                if (!$first instanceof \DOMElement) {
                    continue;
                }
                $type = 'post';
                if (preg_match('/\btype-([a-z0-9_-]+)/', $first->getAttribute('class'), $m)) {
                    $type = Importer::slateTypeFor($m[1]) ?? 'post';
                }
                $tpl = $first->cloneNode(true);
                $tpl->setAttribute('class', trim(preg_replace('/\b(post-\d+|e-loop-item-\d+)\b/', '', $tpl->getAttribute('class'))));
                $title = $x->query($tq, $tpl)->item(0);
                if ($title instanceof \DOMElement && strtolower($title->tagName) !== 'a' && ($inner = $x->query('.//a', $title)->item(0)) instanceof \DOMElement) {
                    $title = $inner;
                }
                if ($title instanceof \DOMElement) {
                    if (strtolower($title->tagName) === 'a') {
                        $title->setAttribute('href', '{{url}}');
                    }
                    self::text($title, '{{title}}');
                }
                foreach ($x->query($imq, $tpl) ?: [] as $img) {
                    /** @var \DOMElement $img */
                    $img->setAttribute('src', '{{image_url}}');
                    $img->setAttribute('alt', '{{title}}');
                    foreach (['srcset', 'sizes', 'data-src', 'data-srcset', 'width', 'height'] as $a) {
                        $img->removeAttribute($a);
                    }
                }
                if (($ex = $x->query($exq, $tpl)->item(0)) instanceof \DOMElement) {
                    self::text($ex, '{{excerpt}}');
                }
                if (($dt = $x->query($dq, $tpl)->item(0)) instanceof \DOMElement) {
                    self::text($dt, '{{date}}');
                }
                foreach ($x->query($mq, $tpl) ?: [] as $a) {
                    /** @var \DOMElement $a */
                    $a->setAttribute('href', '{{url}}');
                }
                foreach ($x->query('.//a', $tpl) ?: [] as $a) {
                    /** @var \DOMElement $a */
                    if (preg_match('#/(\d{4}/\d{2}/)?[\w-]+/?$#', $a->getAttribute('href')) && !str_starts_with($a->getAttribute('href'), '{{')) {
                        $a->setAttribute('href', '{{url}}');
                    }
                }
                while ($box->firstChild) {
                    $box->removeChild($box->firstChild);
                }
                $box->appendChild($doc->createTextNode('{{#query type=' . $type . ' limit=' . count($items) . '}}'));
                $box->appendChild($tpl);
                $box->appendChild($doc->createTextNode('{{/query}}'));
                $box->setAttribute('data-wpi-loop', $kind);
                $notes[] = 'A list of ' . count($items) . ' ' . $type . ' item(s) (' . $kind . ') now shows live content: {{#query type=' . $type . '}}.';
                $changed = true;
            }
        }
        return $changed ? (string)$doc->saveHTML() : $html;
    }

    private static function text(\DOMElement $el, string $tag): void
    {
        // Keep the innermost element (e.g. <h3><a>Title</a></h3>): replace all text with the tag.
        while ($el->firstChild) {
            $el->removeChild($el->firstChild);
        }
        $el->appendChild($el->ownerDocument->createTextNode($tag));
    }
}
