<?php
declare(strict_types=1);

namespace SlateModules\WpImport;

/**
 * WordPress shortcodes in stored content → HTML Slate can show.
 * Forms become Slate form embeds ([form slug="…"]), media shortcodes become markup, page-builder shortcodes
 * (WPBakery, Divi, Beaver, Fusion/Avada, Elementor templates) are unwrapped so their text stays, everything else unknown is removed.
 */
final class Shortcodes
{
    /** Shortcodes handled by Slate itself (left untouched). */
    private const SLATE = ['form'];
    /** Unknown shortcodes seen (name => count), for the report. */
    public array $removed = [];
    /** fn(string plugin, string id): ?string form slug */
    private $formSlug;
    /** fn(array ids): string gallery html */
    private $gallery;

    public function __construct(?callable $formSlug = null, ?callable $gallery = null)
    {
        $this->formSlug = $formSlug ?? static fn() => null;
        $this->gallery = $gallery ?? static fn() => '';
    }

    public static function attrs(string $s): array
    {
        $out = [];
        if (preg_match_all('/([\w-]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|“([^”]*)”|″([^″]*)″|([^\s\]]+))/u', $s, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                $out[strtolower($x[1])] = html_entity_decode($x[2] !== '' ? $x[2] : ($x[3] ?? '') . ($x[4] ?? '') . ($x[5] ?? '') . ($x[6] ?? ''), ENT_QUOTES);
            }
        }
        return $out;
    }

    public function convert(string $html): string
    {
        if (!str_contains($html, '[')) {
            return $html;
        }
        // Forms.
        $html = (string)preg_replace_callback('/\[(contact-form-7|wpforms|gravityform|gravityforms|fluentform|formidable|ninja_form|ninja_forms|forminator_form)\b([^\]]*)\]/i', function ($m) {
            $a = self::attrs($m[2]);
            $plugin = ['contact-form-7' => 'cf7', 'gravityforms' => 'gf', 'gravityform' => 'gf', 'ninja_forms' => 'ninja', 'ninja_form' => 'ninja'][strtolower($m[1])] ?? strtolower($m[1]);
            $slug = ($this->formSlug)($plugin, (string)($a['id'] ?? $a['form'] ?? ''), (string)($a['title'] ?? ''));
            return $slug ? '[form slug="' . $slug . '"]' : '';
        }, $html);
        // [caption id="" align="" width=""]<img …> Caption text[/caption]
        $html = (string)preg_replace_callback('#\[caption\b([^\]]*)\](.*?)\[/caption\]#is', static function ($m) {
            $a = self::attrs($m[1]);
            if (preg_match('#^(\s*(?:<a [^>]*>)?\s*<img[^>]*>\s*(?:</a>)?)(.*)$#is', trim($m[2]), $p)) {
                $cap = trim($p[2]) !== '' ? $p[2] : (string)($a['caption'] ?? '');
                return '<figure' . (!empty($a['align']) ? ' class="' . e($a['align']) . '"' : '') . '>' . $p[1] . ($cap !== '' ? '<figcaption>' . trim($cap) . '</figcaption>' : '') . '</figure>';
            }
            return $m[2];
        }, $html);
        $html = (string)preg_replace_callback('/\[gallery\b([^\]]*)\]/i', function ($m) {
            $ids = array_filter(array_map('intval', explode(',', (string)(self::attrs($m[1])['ids'] ?? ''))));
            return $ids ? ($this->gallery)($ids) : '';
        }, $html);
        $html = (string)preg_replace_callback('#\[embed\b[^\]]*\](.*?)\[/embed\]#is', static fn($m) => Blocks::embed(trim(strip_tags($m[1]))), $html);
        $html = (string)preg_replace_callback('/\[(video|audio)\b([^\]]*)\](?:\[\/\1\])?/i', static function ($m) {
            $a = self::attrs($m[2]);
            $src = (string)($a['src'] ?? $a['mp4'] ?? $a['mp3'] ?? $a['webm'] ?? $a['ogg'] ?? '');
            if ($src === '') {
                return '';
            }
            return strtolower($m[1]) === 'audio' ? '<audio controls src="' . e($src) . '"></audio>' : '<video controls src="' . e($src) . '"' . (!empty($a['poster']) ? ' poster="' . e($a['poster']) . '"' : '') . '></video>';
        }, $html);
        // Page builders: images and buttons keep their meaning, wrappers are unwrapped.
        $html = (string)preg_replace_callback('/\[(vc_single_image|et_pb_image|fusion_imageframe|fl_photo)\b([^\]]*)\](?:(.*?)\[\/\1\])?/is', static function ($m) {
            $a = self::attrs($m[2]);
            $src = (string)($a['src'] ?? $a['image'] ?? '');
            if ($src === '' && !empty($a['image']) && is_numeric($a['image'])) {
                return '<!-- wp-image:' . (int)$a['image'] . ' -->';
            }
            if ($src === '' && preg_match('#<img[^>]*>#', (string)($m[3] ?? ''), $im)) {
                return $im[0];
            }
            return $src !== '' ? '<figure><img src="' . e($src) . '" alt="' . e((string)($a['alt'] ?? '')) . '"></figure>' : '';
        }, $html);
        $html = (string)preg_replace_callback('/\[(vc_btn|et_pb_button|fusion_button|vc_button2?)\b([^\]]*)\](?:(.*?)\[\/\1\])?/is', static function ($m) {
            $a = self::attrs($m[2]);
            $url = (string)($a['button_url'] ?? $a['link'] ?? $a['url'] ?? '');
            if (str_starts_with($url, 'url:')) {
                $url = rawurldecode(explode('|', substr($url, 4))[0]);
            }
            $text = (string)($a['button_text'] ?? $a['title'] ?? trim(strip_tags((string)($m[3] ?? ''))));
            return $text !== '' ? '<p class="buttons"><a class="button" href="' . e($url ?: '#') . '">' . e($text) . '</a></p>' : '';
        }, $html);
        $html = (string)preg_replace_callback('/\[(et_pb_text|vc_column_text|fusion_text|et_pb_blurb|et_pb_cta)\b([^\]]*)\]/i', static function ($m) {
            $a = self::attrs($m[2]);
            $title = (string)($a['title'] ?? '');
            return $title !== '' && in_array(strtolower($m[1]), ['et_pb_blurb', 'et_pb_cta'], true) ? '<h3>' . e($title) . '</h3>' : '';
        }, $html);
        $html = (string)preg_replace_callback('/\[(vc_custom_heading|et_pb_heading)\b([^\]]*)\](?:\[\/\1\])?/i', static function ($m) {
            $a = self::attrs($m[2]);
            $text = (string)($a['text'] ?? $a['title'] ?? '');
            return $text !== '' ? '<h2>' . e($text) . '</h2>' : '';
        }, $html);
        // Remaining shortcodes (opening, closing, self-closing): drop the tags, keep enclosed content.
        $source = $html;
        $html = (string)preg_replace_callback('/\[(\/)?([a-zA-Z][\w-]*)((?:\s[^\]]*)?)\]/', function ($m) use ($source) {
            if ($m[1] === '/') {
                $m[3] = ' ';
            }
            $name = strtolower($m[2]);
            // Plain words in brackets ("[Updated]") are text: only names that look like shortcodes are removed.
            $looks = preg_match('/[_-]/', $name) || $m[3] !== '' || stripos($source, '[/' . $m[2] . ']') !== false
                || in_array($name, ['gallery', 'caption', 'audio', 'video', 'playlist', 'embed', 'wpforms', 'gravityform', 'fluentform', 'rev_slider', 'layerslider', 'smartslider3'], true);
            if (in_array($name, self::SLATE, true) || !$looks) {
                return $m[0];
            }
            if ($m[1] === '') {
                $this->removed[$name] = ($this->removed[$name] ?? 0) + 1;
            }
            return '';
        }, $html);
        // Builder wrappers often leave empty paragraphs behind.
        return (string)preg_replace('#<p>\s*(&nbsp;)?\s*</p>#i', '', $html);
    }
}
