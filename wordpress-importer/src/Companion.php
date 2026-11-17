<?php
declare(strict_types=1);

namespace SlateModules\WpImport;

use SlateModules\AutoCpt\WpClient;

/**
 * The optional "Slate Export" WordPress plugin: Slate generates its zip, the site owner installs it, and the importer
 * then reads (with the administrator application password) what the REST API does not expose: post meta, options,
 * exact ACF definitions, forms, Elementor / block templates, plugin redirects, users and comments.
 */
final class Companion
{
    public const NS = '/slate-export/v1';

    /** Build the installable plugin zip; returns its path. */
    public static function zip(): string
    {
        if (!class_exists('ZipArchive')) {
            throw new \RuntimeException('The PHP zip extension is needed to build the plugin file.');
        }
        Importer::install();
        $file = Importer::WORK . '/slate-export.zip';
        @unlink($file);
        $zip = new \ZipArchive();
        if ($zip->open($file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not create the plugin file.');
        }
        $zip->addFile(dirname(__DIR__) . '/companion/slate-export.php', 'slate-export/slate-export.php');
        $zip->addFromString('slate-export/readme.txt', "=== Slate Export ===\nRead-only helper for moving this site to Slate. Only administrators can use it, through an application password.\nDeactivate and delete it when the migration is done.\n");
        $zip->close();
        return $file;
    }

    /** The plugin's info when it is installed and the credentials are an administrator's, else null. */
    public static function detect(WpClient $wp): ?array
    {
        if (!$wp->authenticated()) {
            return null;
        }
        $r = $wp->get(self::NS . '/info');
        return $r['ok'] && is_array($r['data']) && isset($r['data']['version']) ? $r['data'] : null;
    }

    /** Load everything except per-post meta (fetched per batch while importing). */
    public static function load(WpClient $wp, array $info): array
    {
        $stats = [];
        $o = $wp->get(self::NS . '/options');
        if ($o['ok'] && is_array($o['data'])) {
            $rows = [];
            foreach ($o['data'] as $name => $v) {
                $rows[] = ['option', (string)$name, '', (string)$v];
            }
            Data::putMany($rows);
            $stats['options'] = count($rows);
        }
        for ($page = 1; $page < 100; $page++) {
            $r = $wp->get(self::NS . '/posts', ['page' => $page]);
            if (!$r['ok'] || empty($r['data']['items'])) {
                break;
            }
            $rows = [];
            foreach ($r['data']['items'] as $p) {
                $meta = (array)($p['meta'] ?? []);
                unset($p['meta']);
                $rows[] = ['wpost', (string)$p['id'], 'row', $p];
                foreach ($meta as $k => $v) {
                    $rows[] = ['postmeta', (string)$p['id'], (string)$k, (string)$v];
                }
                $stats['special_posts'] = ($stats['special_posts'] ?? 0) + 1;
            }
            Data::putMany($rows);
            if (empty($r['data']['more'])) {
                break;
            }
        }
        $acf = $wp->get(self::NS . '/acf');
        if ($acf['ok'] && !empty($acf['data']['active'])) {
            Data::put('acf', 'groups', '', $acf['data']['groups'] ?? []);
            Data::put('acf', 'post_types', '', $acf['data']['post_types'] ?? []);
            Data::put('acf', 'taxonomies', '', $acf['data']['taxonomies'] ?? []);
            Data::put('acf', 'pro', '', !empty($acf['data']['pro']) ? '1' : '0');
            $stats['acf_groups'] = count($acf['data']['groups'] ?? []);
        }
        $u = $wp->get(self::NS . '/users');
        if ($u['ok'] && is_array($u['data'])) {
            Data::putMany(array_map(static fn($x) => ['user', (string)$x['id'], 'row', $x], $u['data']));
            $stats['users'] = count($u['data']);
        }
        for ($page = 1; $page < 400; $page++) {
            $r = $wp->get(self::NS . '/comments', ['page' => $page]);
            if (!$r['ok'] || empty($r['data']['items'])) {
                break;
            }
            Data::putMany(array_map(static fn($x) => ['comment', (string)$x['id'], 'row', $x], $r['data']['items']));
            $stats['comments'] = ($stats['comments'] ?? 0) + count($r['data']['items']);
            if (empty($r['data']['more'])) {
                break;
            }
        }
        $t = $wp->get(self::NS . '/terms');
        if ($t['ok'] && is_array($t['data'])) {
            $rows = [];
            foreach ($t['data'] as $x) {
                $rows[] = ['term', (string)$x['id'], 'name', ['name' => $x['name'], 'slug' => $x['slug']]];
                $rows[] = ['term', (string)$x['id'], 'tax', ['taxonomy' => $x['taxonomy'], 'parent' => (int)$x['parent']]];
            }
            Data::putMany($rows);
        }
        for ($page = 1; $page < 50; $page++) {
            $r = $wp->get(self::NS . '/termmeta', ['page' => $page]);
            if (!$r['ok'] || empty($r['data']['items'])) {
                break;
            }
            $rows = [];
            foreach ((array)$r['data']['items'] as $tid => $meta) {
                foreach ((array)$meta as $k => $v) {
                    $rows[] = ['termmeta', (string)$tid, (string)$k, (string)$v];
                }
            }
            Data::putMany($rows);
            if (empty($r['data']['more'])) {
                break;
            }
        }
        foreach ((array)($info['tables'] ?? []) as $table => $count) {
            for ($page = 1; $page < 200 && $count > 0; $page++) {
                $r = $wp->get(self::NS . '/table', ['name' => $table, 'page' => $page]);
                if (!$r['ok'] || empty($r['data']['items'])) {
                    break;
                }
                $rows = [];
                foreach ($r['data']['items'] as $i => $row) {
                    $id = (string)($row['id'] ?? $row['ID'] ?? $row['form_id'] ?? (($page - 1) * 500 + $i));
                    if ($table === 'gf_form_meta') {
                        $id = (string)$row['form_id'];
                    }
                    $rows[] = ['table:' . $table, $id, 'row', $row];
                }
                Data::putMany($rows);
                $stats['table:' . $table] = ($stats['table:' . $table] ?? 0) + count($rows);
                if (empty($r['data']['more'])) {
                    break;
                }
            }
        }
        Data::noteSource('companion', ['version' => $info['version'] ?? '', 'plugins' => $info['plugins'] ?? [], 'rows' => $stats]);
        return $stats;
    }

    /** Post meta of a batch of WordPress ids (skips ids already loaded). */
    public static function fetchMeta(WpClient $wp, array $ids): void
    {
        $need = array_values(array_filter(array_map('intval', $ids), static fn($id) => $id > 0 && !Data::hasMeta($id)));
        foreach (array_chunk($need, 100) as $chunk) {
            $r = $wp->get(self::NS . '/meta', ['ids' => implode(',', $chunk)]);
            if (!$r['ok'] || !is_array($r['data'])) {
                return;
            }
            $rows = [];
            foreach ($r['data'] as $pid => $meta) {
                foreach ((array)$meta as $k => $v) {
                    $rows[] = ['postmeta', (string)$pid, (string)$k, (string)$v];
                }
            }
            // An item without meta still counts as loaded.
            foreach ($chunk as $pid) {
                if (!isset($r['data'][$pid]) && !isset($r['data'][(string)$pid])) {
                    $rows[] = ['postmeta', (string)$pid, '_slate_none', '1'];
                }
            }
            Data::putMany($rows);
        }
    }
}
