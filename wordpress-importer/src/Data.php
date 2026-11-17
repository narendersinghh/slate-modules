<?php
declare(strict_types=1);

namespace SlateModules\WpImport;

use Slate\DB;

/**
 * Staging store for WordPress data that the REST API and the export file do not carry in a usable form:
 * post / term meta, options (SEO plugins, CPT UI, ACF options, menu locations), ACF field groups, forms,
 * builder templates, redirects, users and comments. Filled from the companion plugin, an SQL dump or the WXR file.
 *
 * Table {{wpi_data}}: (kind, ref, k) => v. Kinds:
 *   postmeta  ref = WP post id,  k = meta key, v = raw value (as stored, maybe PHP-serialized)
 *   termmeta  ref = WP term id,  k = meta key
 *   option    ref = option name, k = ''
 *   wpost     ref = WP post id,  k = 'row' (JSON of the wp_posts row) for non-content types (ACF, forms, templates…)
 *   user      ref = WP user id,  k = 'row' (JSON: login, email, display_name, role, url, description)
 *   comment   ref = WP comment id, k = 'row' (JSON)
 *   term      ref = WP term id,  k = 'row' (JSON: name, slug, taxonomy, parent) for nav menus etc.
 *   table:<name> ref = row id,   k = 'row' (JSON) for plugin tables (redirects, forms, AIOSEO)
 *   acf       ref = 'groups' / 'post_types' / 'taxonomies', k = '' (JSON of exact definitions from the companion)
 *   source    ref = 'sql' / 'wxr' / 'companion', k = '' (JSON: what was loaded, when)
 * Values are never executed; PHP-serialized strings are decoded with allowed_classes = false.
 */
final class Data
{
    private static array $metaCache = [];
    private static ?array $optCache = null;

    public static function install(): void
    {
        $sqlite = DB::$driver === 'sqlite';
        $text = $sqlite ? 'TEXT' : 'LONGTEXT';
        $tail = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        DB::exec("CREATE TABLE IF NOT EXISTS {{wpi_data}} (
            kind VARCHAR(40) NOT NULL, ref VARCHAR(191) NOT NULL, k VARCHAR(191) NOT NULL DEFAULT '', v $text NULL,
            PRIMARY KEY (kind, ref, k))$tail");
    }

    public static function put(string $kind, string|int $ref, string $k, mixed $v): void
    {
        self::putMany([[$kind, (string)$ref, $k, $v]]);
    }

    /** @param array<array{0:string,1:string|int,2:string,3:mixed}> $rows */
    public static function putMany(array $rows): void
    {
        if (!$rows) {
            return;
        }
        $sqlite = DB::$driver === 'sqlite';
        $sql = $sqlite ? 'INSERT OR REPLACE INTO {{wpi_data}} (kind, ref, k, v) VALUES (:a, :b, :c, :d)'
            : 'REPLACE INTO {{wpi_data}} (kind, ref, k, v) VALUES (:a, :b, :c, :d)';
        $pdo = DB::pdo();
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        try {
            foreach ($rows as [$kind, $ref, $k, $v]) {
                DB::query($sql, ['a' => mb_substr($kind, 0, 40), 'b' => mb_substr((string)$ref, 0, 191), 'c' => mb_substr($k, 0, 191),
                    'd' => is_string($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
            }
            if ($own) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        self::$metaCache = [];
        self::$optCache = null;
    }

    public static function get(string $kind, string|int $ref, string $k = ''): ?string
    {
        $v = DB::val('SELECT v FROM {{wpi_data}} WHERE kind = :a AND ref = :b AND k = :c', ['a' => $kind, 'b' => (string)$ref, 'c' => $k]);
        return $v === null ? null : (string)$v;
    }

    /** JSON row stored under k = 'row'. */
    public static function row(string $kind, string|int $ref): ?array
    {
        $v = self::get($kind, $ref, 'row');
        $d = $v !== null ? json_decode($v, true) : null;
        return is_array($d) ? $d : null;
    }

    /** All rows of a kind: [ref => decoded row]. */
    public static function rows(string $kind, int $limit = 100000): array
    {
        $out = [];
        foreach (DB::all("SELECT ref, v FROM {{wpi_data}} WHERE kind = :a AND k = 'row' LIMIT " . (int)$limit, ['a' => $kind]) as $r) {
            $d = json_decode((string)$r['v'], true);
            if (is_array($d)) {
                $out[(string)$r['ref']] = $d;
            }
        }
        return $out;
    }

    public static function count(string $kind): int
    {
        return (int)DB::val('SELECT COUNT(DISTINCT ref) FROM {{wpi_data}} WHERE kind = :a', ['a' => $kind]);
    }

    /** Raw meta of one object: [key => raw value]. */
    public static function meta(string|int $id, string $kind = 'postmeta'): array
    {
        $key = $kind . ':' . $id;
        if (isset(self::$metaCache[$key])) {
            return self::$metaCache[$key];
        }
        if (count(self::$metaCache) > 400) {
            self::$metaCache = [];
        }
        $out = [];
        foreach (DB::all('SELECT k, v FROM {{wpi_data}} WHERE kind = :a AND ref = :b', ['a' => $kind, 'b' => (string)$id]) as $r) {
            $out[(string)$r['k']] = (string)$r['v'];
        }
        return self::$metaCache[$key] = $out;
    }

    public static function hasMeta(string|int $id): bool
    {
        return (bool)DB::val("SELECT 1 FROM {{wpi_data}} WHERE kind = 'postmeta' AND ref = :b LIMIT 1", ['b' => (string)$id]);
    }

    /** A WordPress option, decoded (PHP-serialized or JSON). */
    public static function option(string $name, mixed $default = null): mixed
    {
        if (self::$optCache === null) {
            self::$optCache = [];
            foreach (DB::all("SELECT ref, v FROM {{wpi_data}} WHERE kind = 'option'") as $r) {
                self::$optCache[(string)$r['ref']] = (string)$r['v'];
            }
        }
        return array_key_exists($name, self::$optCache) ? self::decode(self::$optCache[$name]) : $default;
    }

    public static function optionNames(): array
    {
        self::option('__');
        return array_keys(self::$optCache ?? []);
    }

    public static function has(string $kind): bool
    {
        return (bool)DB::val('SELECT 1 FROM {{wpi_data}} WHERE kind = :a LIMIT 1', ['a' => $kind]);
    }

    public static function clear(?string $kind = null): void
    {
        self::install();
        if ($kind === null) {
            DB::exec('DELETE FROM {{wpi_data}}');
            Archive::clear(); // the uploads archive belongs to the staged source
        } else {
            DB::query('DELETE FROM {{wpi_data}} WHERE kind = :a', ['a' => $kind]);
        }
        self::$metaCache = [];
        self::$optCache = null;
    }

    /** Decode a stored WordPress value: PHP-serialized arrays/scalars (never objects), JSON, else the string. */
    public static function decode(?string $v): mixed
    {
        if ($v === null) {
            return null;
        }
        $t = trim($v);
        if ($t !== '' && preg_match('/^(a|s|i|b|d|N):[0-9:;]/', $t)) {
            if (preg_match('/(^|[;{])O:\d+:/', $t) || str_contains($t, 'C:')) {
                // Objects are never instantiated: drop them (allowed_classes = false would give __PHP_Incomplete_Class).
                $r = @unserialize($t, ['allowed_classes' => false]);
                return $r === false ? $v : self::stripObjects($r);
            }
            $r = @unserialize($t, ['allowed_classes' => false]);
            if ($r !== false || $t === 'b:0;') {
                return $r;
            }
        }
        if ($t !== '' && ($t[0] === '{' || $t[0] === '[')) {
            $j = json_decode($t, true);
            if (is_array($j)) {
                return $j;
            }
        }
        return $v;
    }

    private static function stripObjects(mixed $v): mixed
    {
        if (is_object($v)) {
            return null;
        }
        if (is_array($v)) {
            foreach ($v as $k => $x) {
                $v[$k] = self::stripObjects($x);
            }
        }
        return $v;
    }

    /** Decoded meta of a post: [key => value]. */
    public static function metaDecoded(string|int $id, string $kind = 'postmeta'): array
    {
        return array_map([self::class, 'decode'], self::meta($id, $kind));
    }

    /** WordPress post type and status of an id when a database dump told us ([type, status]), else null. */
    public static function postType(string|int $id): ?array
    {
        $v = self::get('ptype', $id);
        return $v === null ? null : array_pad(explode('|', $v, 2), 2, '');
    }

    /** False for revisions, auto-drafts and trashed posts known from a database dump. */
    public static function isLive(string|int $id): bool
    {
        $t = self::postType($id);
        return !$t || !in_array($t[0], ['revision', 'customize_changeset', 'oembed_cache'], true) && !in_array($t[1], ['trash', 'auto-draft', 'inherit'], true) || $t[0] === 'attachment';
    }

    /** Count of live posts per type from a database dump: [type => n]. */
    public static function typeCounts(): array
    {
        $out = [];
        foreach (\Slate\DB::all("SELECT v FROM {{wpi_data}} WHERE kind = 'ptype'") as $r) {
            [$t, $st] = array_pad(explode('|', (string)$r['v'], 2), 2, '');
            if (in_array($st, ['publish', 'future', 'draft', 'pending', 'private'], true)) {
                $out[$t] = ($out[$t] ?? 0) + 1;
            }
        }
        return $out;
    }

    /** Remember where the data came from (shown in the analysis). */
    public static function noteSource(string $source, array $info): void
    {
        self::put('source', $source, '', $info + ['at' => gmdate('c')]);
    }

    public static function sources(): array
    {
        $out = [];
        foreach (DB::all("SELECT ref, v FROM {{wpi_data}} WHERE kind = 'source'") as $r) {
            $out[(string)$r['ref']] = json_decode((string)$r['v'], true) ?: [];
        }
        return $out;
    }
}
