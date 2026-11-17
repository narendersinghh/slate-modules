<?php
declare(strict_types=1);

namespace SlateModules\WpImport;

/**
 * Second, streaming pass over a WordPress export (WXR) that keeps what the content parser drops:
 * every post meta value, the rows of definition / form / template post types (ACF field groups, Contact Form 7,
 * WPForms, Elementor library, block templates, reusable blocks), comments, authors, term meta and nav menu terms.
 */
final class WxrExtras
{
    public static function load(string $file): array
    {
        if (!is_file($file) || !class_exists('XMLReader')) {
            return [];
        }
        $r = new \XMLReader();
        if (!$r->open($file, null, LIBXML_NONET | LIBXML_PARSEHUGE | LIBXML_COMPACT)) {
            return [];
        }
        $head = (string)file_get_contents($file, false, null, 0, 16384);
        $wp = preg_match('#xmlns:wp="([^"]+)"#', $head, $m) ? $m[1] : 'http://wordpress.org/export/1.2/';
        $exNs = preg_match('#xmlns:excerpt="([^"]+)"#', $head, $m) ? $m[1] : 'http://wordpress.org/export/1.2/excerpt/';
        $stats = ['meta' => 0, 'special' => 0, 'comments' => 0, 'authors' => 0, 'terms' => 0];
        $rows = [];
        $flush = static function () use (&$rows): void {
            if ($rows) {
                Data::putMany($rows);
                $rows = [];
            }
        };
        $n = 0;
        while ($r->read()) {
            if ($r->nodeType !== \XMLReader::ELEMENT) {
                continue;
            }
            if ($r->localName === 'author' && $r->depth === 2) {
                $x = self::node($r, $wp, $exNs);
                if ($x) {
                    $c = $x->children($wp);
                    $rows[] = ['user', (string)$c->author_id, 'row', ['login' => (string)$c->author_login, 'email' => (string)$c->author_email,
                        'display_name' => (string)$c->author_display_name ?: (string)$c->author_login, 'first_name' => (string)$c->author_first_name, 'last_name' => (string)$c->author_last_name]];
                    $stats['authors']++;
                }
                continue;
            }
            if ($r->localName === 'term' && $r->depth === 2) {
                $x = self::node($r, $wp, $exNs);
                if ($x) {
                    $c = $x->children($wp);
                    $tid = (string)$c->term_id;
                    $rows[] = ['term', $tid, 'name', ['name' => (string)$c->term_name, 'slug' => (string)$c->term_slug]];
                    $rows[] = ['term', $tid, 'tax', ['taxonomy' => (string)$c->term_taxonomy, 'parent_slug' => (string)$c->term_parent]];
                    foreach ($c->termmeta as $tm) {
                        $rows[] = ['termmeta', $tid, (string)$tm->meta_key, (string)$tm->meta_value];
                    }
                    $stats['terms']++;
                }
                continue;
            }
            if ($r->localName === 'category' && $r->depth === 2 && $r->namespaceURI === $wp) {
                $x = self::node($r, $wp, $exNs);
                if ($x) {
                    $c = $x->children($wp);
                    foreach ($c->termmeta as $tm) {
                        $rows[] = ['termmeta', (string)$c->term_id, (string)$tm->meta_key, (string)$tm->meta_value];
                    }
                }
                continue;
            }
            if ($r->localName !== 'item') {
                continue;
            }
            $x = self::node($r, $wp, $exNs);
            if (!$x) {
                continue;
            }
            $c = $x->children($wp);
            $id = (string)$c->post_id;
            $type = (string)$c->post_type;
            foreach ($c->postmeta as $pm) {
                $k = (string)$pm->meta_key;
                if ($k === '' || preg_match('/^(_edit_lock|_edit_last|_elementor_css|_elementor_page_assets|_elementor_element_cache|_oembed_\w+|_wp_attachment_backup_sizes)$/', $k)) {
                    continue;
                }
                $rows[] = ['postmeta', $id, $k, (string)$pm->meta_value];
                $stats['meta']++;
            }
            $dc = $x->children('http://purl.org/dc/elements/1.1/');
            if ((string)$dc->creator !== '') {
                $rows[] = ['postmeta', $id, '_wpi_author', (string)$dc->creator];
            }
            if ((string)$c->is_sticky === '1') {
                $rows[] = ['postmeta', $id, '_wpi_sticky', '1'];
            }
            if (in_array($type, SqlDump::SPECIAL_TYPES, true)) {
                $content = $x->children('http://purl.org/rss/1.0/modules/content/');
                $excerpt = $x->children($exNs);
                $terms = [];
                foreach ($x->category as $cat) {
                    $terms[] = ['taxonomy' => (string)$cat['domain'], 'slug' => (string)$cat['nicename']];
                }
                $rows[] = ['wpost', $id, 'row', ['id' => (int)$id, 'type' => $type, 'title' => (string)$x->title, 'slug' => (string)$c->post_name,
                    'content' => (string)$content->encoded, 'excerpt' => (string)$excerpt->encoded, 'status' => (string)$c->status,
                    'parent' => (int)$c->post_parent, 'menu_order' => (int)$c->menu_order, 'date_gmt' => (string)$c->post_date_gmt, 'terms' => $terms]];
                $stats['special']++;
            }
            foreach ($c->comment as $cm) {
                $approved = (string)$cm->comment_approved;
                $ctype = (string)$cm->comment_type;
                if (!in_array($approved, ['0', '1'], true) || !in_array($ctype, ['', 'comment', 'review'], true)) {
                    continue;
                }
                $rows[] = ['comment', (string)$cm->comment_id, 'row', ['post_id' => (int)$id, 'parent' => (int)$cm->comment_parent, 'author' => (string)$cm->comment_author,
                    'email' => (string)$cm->comment_author_email, 'url' => (string)$cm->comment_author_url, 'date_gmt' => (string)$cm->comment_date_gmt,
                    'content' => (string)$cm->comment_content, 'approved' => $approved === '1', 'user_id' => (int)$cm->comment_user_id]];
                $stats['comments']++;
            }
            if (++$n % 200 === 0 || count($rows) > 2000) {
                $flush();
                @set_time_limit(300);
            }
        }
        $r->close();
        $flush();
        Data::noteSource('wxr', ['rows' => $stats]);
        return $stats;
    }

    private static function node(\XMLReader $r, string $wp, string $exNs): ?\SimpleXMLElement
    {
        $xml = $r->readOuterXml();
        if ($xml === '') {
            return null;
        }
        // Namespaces are declared on <rss>: re-declare them so the fragment parses alone.
        $ns = 'xmlns:wp="' . $wp . '" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:excerpt="' . $exNs . '" xmlns:dc="http://purl.org/dc/elements/1.1/"';
        $prev = libxml_use_internal_errors(true);
        $x = simplexml_load_string('<root ' . $ns . '>' . $xml . '</root>', 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET | LIBXML_PARSEHUGE);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$x) {
            return null;
        }
        foreach ($x->children() as $child) {
            return $child;
        }
        foreach ($x->children($wp) as $child) {
            return $child;
        }
        return null;
    }
}
