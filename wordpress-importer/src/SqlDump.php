<?php
declare(strict_types=1);

namespace SlateModules\WpImport;

/**
 * Reads a WordPress database dump (.sql or .sql.gz, mysqldump / phpMyAdmin / WP-CLI) WITHOUT running it:
 * the file is streamed, only "INSERT INTO <known table>" statements are tokenised, and the rows of interest
 * go to the staging store (Data). Nothing else in the file is interpreted.
 */
final class SqlDump
{
    /** Post types kept as whole rows (definitions, forms, templates): their content is not in the REST API. */
    public const SPECIAL_TYPES = ['acf-field-group', 'acf-field', 'acf-post-type', 'acf-taxonomy', 'acf-ui-options-page', 'wpcf7_contact_form', 'wpforms',
        'elementor_library', 'wp_template', 'wp_template_part', 'wp_block', 'wp_navigation', 'nav_menu_item', 'jet-engine', 'mb-post-type', 'mb-taxonomy',
        'meta-box', '_pods_pod', '_pods_field', 'fl-builder-template', 'et_pb_layout', 'bricks_template', 'ct_template'];
    /** Meta keys never needed (caches, locks, screenshots). */
    private const SKIP_META = '/^(_edit_lock|_edit_last|_encloseme|_pingme|_wp_trash_meta_\w+|_elementor_css|_elementor_page_assets|_elementor_element_cache|_elementor_screenshot\w*|_oembed_\w+|_wp_attachment_backup_sizes|_elementor_inline_svg|_transient\w*|_yoast_wpseo_estimated-reading-time-minutes|_yoast_indexnow_last_ping|rank_math_analytic_object_id|rank_math_internal_links_processed|_aioseo_\w*_cache)$/';
    private const TABLES = ['options', 'postmeta', 'termmeta', 'posts', 'users', 'usermeta', 'comments', 'terms', 'term_taxonomy',
        'rank_math_redirections', 'redirection_items', 'aioseo_posts', 'gf_form', 'gf_form_meta', 'fluentform_forms', 'fluentform_form_meta', 'yoast_seo_links'];

    private string $prefix = 'wp_';
    private array $cols = [];
    private array $buffer = [];
    private array $stats = [];
    /** Meta of attachments is needed (alt text) but post rows of normal content are not. */
    private array $userRoles = [];

    /** Parse the dump. Returns counts per table. */
    public static function load(string $file): array
    {
        $self = new self();
        return $self->run($file);
    }

    private function run(string $file): array
    {
        if (!is_file($file)) {
            throw new \RuntimeException('The database file was not found.');
        }
        $gz = str_ends_with(strtolower($file), '.gz');
        $fh = $gz ? @gzopen($file, 'rb') : @fopen($file, 'rb');
        if (!$fh) {
            throw new \RuntimeException('The database file could not be opened.');
        }
        $read = $gz ? 'gzgets' : 'fgets';
        $eof = $gz ? 'gzeof' : 'feof';
        $close = $gz ? 'gzclose' : 'fclose';
        // Table prefix from the first CREATE TABLE / INSERT of an options table.
        $stmt = '';
        $inStr = false;
        $lines = 0;
        while (!$eof($fh)) {
            $line = $read($fh, 4 * 1024 * 1024);
            if ($line === false) {
                break;
            }
            if (++$lines % 20000 === 0) {
                @set_time_limit(300);
            }
            if ($stmt === '') {
                $t = ltrim($line);
                if ($t === '' || str_starts_with($t, '--') || str_starts_with($t, '/*') || str_starts_with($t, '#')) {
                    continue;
                }
                if (preg_match('/^CREATE TABLE (?:IF NOT EXISTS )?`?(\w+?)options`?\s*\(/i', $t, $m)) {
                    $this->prefix = $m[1];
                    continue;
                }
                if (!preg_match('/^INSERT\s+(?:IGNORE\s+)?INTO\s/i', $t)) {
                    continue; // never interpreted
                }
            }
            $stmt .= $line;
            $inStr = self::scanQuotes($line, $inStr);
            if (!$inStr && preg_match('/;\s*$/', $line)) {
                $this->statement($stmt);
                $stmt = '';
            }
            if (strlen($stmt) > 64 * 1024 * 1024) {
                $stmt = ''; // runaway statement: skip
                $inStr = false;
            }
        }
        $close($fh);
        $this->flush();
        $this->finishUsers();
        Data::noteSource('sql', ['file' => basename($file), 'prefix' => $this->prefix, 'rows' => $this->stats]);
        return $this->stats;
    }

    /** Track whether the end of $line is inside a quoted string (backslash and doubled-quote escapes). */
    private static function scanQuotes(string $line, bool $in): bool
    {
        $n = strlen($line);
        for ($i = 0; $i < $n; $i++) {
            $c = $line[$i];
            if ($in) {
                if ($c === '\\') {
                    $i++;
                } elseif ($c === "'") {
                    if ($i + 1 < $n && $line[$i + 1] === "'") {
                        $i++;
                    } else {
                        $in = false;
                    }
                }
            } elseif ($c === "'") {
                $in = true;
            }
        }
        return $in;
    }

    private function statement(string $sql): void
    {
        if (!preg_match('/^\s*INSERT\s+(?:IGNORE\s+)?INTO\s+`?(\w+)`?\s*(\(([^)]*)\))?\s*VALUES\s*/is', $sql, $m)) {
            return;
        }
        $table = $m[1];
        if (!str_starts_with($table, $this->prefix)) {
            // Prefix not seen in a CREATE TABLE (data-only dump): guess it from the table name.
            foreach (self::TABLES as $t) {
                if (str_ends_with($table, '_' . $t) || str_ends_with($table, $t)) {
                    $this->prefix = substr($table, 0, -strlen($t));
                    break;
                }
            }
        }
        $name = substr($table, strlen($this->prefix));
        if (!in_array($name, self::TABLES, true)) {
            return;
        }
        $cols = isset($m[3]) && $m[3] !== '' ? array_map(static fn($c) => trim($c, " `\t\n\r"), explode(',', $m[3])) : self::defaultCols($name);
        if (!$cols) {
            return;
        }
        $pos = strlen($m[0]);
        foreach ($this->tuples($sql, $pos) as $vals) {
            if (count($vals) !== count($cols)) {
                continue;
            }
            $this->row($name, array_combine($cols, $vals));
        }
        if (count($this->buffer) > 500) {
            $this->flush();
        }
    }

    /** Column order of core tables when a dump omits the column list. */
    private static function defaultCols(string $t): array
    {
        return match ($t) {
            'options' => ['option_id', 'option_name', 'option_value', 'autoload'],
            'postmeta' => ['meta_id', 'post_id', 'meta_key', 'meta_value'],
            'termmeta' => ['meta_id', 'term_id', 'meta_key', 'meta_value'],
            'usermeta' => ['umeta_id', 'user_id', 'meta_key', 'meta_value'],
            'terms' => ['term_id', 'name', 'slug', 'term_group'],
            'term_taxonomy' => ['term_taxonomy_id', 'term_id', 'taxonomy', 'description', 'parent', 'count'],
            'users' => ['ID', 'user_login', 'user_pass', 'user_nicename', 'user_email', 'user_url', 'user_registered', 'user_activation_key', 'user_status', 'display_name'],
            'posts' => ['ID', 'post_author', 'post_date', 'post_date_gmt', 'post_content', 'post_title', 'post_excerpt', 'post_status', 'comment_status', 'ping_status',
                'post_password', 'post_name', 'to_ping', 'pinged', 'post_modified', 'post_modified_gmt', 'post_content_filtered', 'post_parent', 'guid', 'menu_order',
                'post_type', 'post_mime_type', 'comment_count'],
            'comments' => ['comment_ID', 'comment_post_ID', 'comment_author', 'comment_author_email', 'comment_author_url', 'comment_author_IP', 'comment_date',
                'comment_date_gmt', 'comment_content', 'comment_karma', 'comment_approved', 'comment_agent', 'comment_type', 'comment_parent', 'user_id'],
            default => [],
        };
    }

    /** Yield the value lists of "(...),(...);" starting at $pos. */
    private function tuples(string $s, int $pos): \Generator
    {
        $n = strlen($s);
        while ($pos < $n) {
            while ($pos < $n && $s[$pos] !== '(' && $s[$pos] !== ';') {
                $pos++;
            }
            if ($pos >= $n || $s[$pos] === ';') {
                return;
            }
            $pos++;
            $vals = [];
            while ($pos < $n) {
                while ($pos < $n && ($s[$pos] === ' ' || $s[$pos] === "\n" || $s[$pos] === "\r" || $s[$pos] === "\t")) {
                    $pos++;
                }
                if ($pos >= $n) {
                    return;
                }
                $c = $s[$pos];
                if ($c === "'") {
                    $pos++;
                    $buf = '';
                    while ($pos < $n) {
                        $ch = $s[$pos];
                        if ($ch === '\\' && $pos + 1 < $n) {
                            $nx = $s[$pos + 1];
                            $buf .= match ($nx) { 'n' => "\n", 'r' => "\r", 't' => "\t", '0' => "\0", 'Z' => "\x1a", 'b' => "\x08", default => $nx };
                            $pos += 2;
                            continue;
                        }
                        if ($ch === "'") {
                            if ($pos + 1 < $n && $s[$pos + 1] === "'") {
                                $buf .= "'";
                                $pos += 2;
                                continue;
                            }
                            $pos++;
                            break;
                        }
                        // Copy a run of plain characters at once.
                        $span = strcspn($s, "\\'", $pos);
                        $buf .= substr($s, $pos, $span);
                        $pos += $span;
                    }
                    $vals[] = $buf;
                } else {
                    $span = strcspn($s, ',)', $pos);
                    $raw = trim(substr($s, $pos, $span));
                    $pos += $span;
                    $vals[] = strtoupper($raw) === 'NULL' ? null : (str_starts_with($raw, '0x') ? (string)@hex2bin(substr($raw, 2)) : $raw);
                }
                while ($pos < $n && ($s[$pos] === ' ' || $s[$pos] === "\n" || $s[$pos] === "\r" || $s[$pos] === "\t")) {
                    $pos++;
                }
                if ($pos < $n && $s[$pos] === ',') {
                    $pos++;
                    continue;
                }
                if ($pos < $n && $s[$pos] === ')') {
                    $pos++;
                    break;
                }
            }
            yield $vals;
        }
    }

    private function row(string $t, array $r): void
    {
        $this->stats[$t] = ($this->stats[$t] ?? 0) + 1;
        switch ($t) {
            case 'options':
                $name = (string)$r['option_name'];
                if (!self::wantedOption($name) || strlen((string)$r['option_value']) > 4 * 1024 * 1024) {
                    return;
                }
                $this->buffer[] = ['option', $name, '', (string)$r['option_value']];
                return;
            case 'postmeta':
            case 'termmeta':
                $key = (string)$r['meta_key'];
                if ($key === '' || preg_match(self::SKIP_META, $key) || strlen((string)$r['meta_value']) > 8 * 1024 * 1024) {
                    return;
                }
                $this->buffer[] = [$t, (string)($r['post_id'] ?? $r['term_id']), $key, (string)$r['meta_value']];
                return;
            case 'posts':
                // Type and status of every row (revisions and trash are ignored elsewhere; counts are compared with the export file).
                $this->buffer[] = ['ptype', (string)$r['ID'], '', (string)$r['post_type'] . '|' . (string)$r['post_status']];
                if (!in_array((string)$r['post_type'], self::SPECIAL_TYPES, true)) {
                    return;
                }
                $this->buffer[] = ['wpost', (string)$r['ID'], 'row', self::postRow($r)];
                return;
            case 'users':
                $this->buffer[] = ['user', (string)$r['ID'], 'row', ['login' => $r['user_login'], 'email' => $r['user_email'], 'display_name' => $r['display_name'],
                    'nicename' => $r['user_nicename'], 'url' => $r['user_url'], 'registered' => $r['user_registered']]];
                return;
            case 'usermeta':
                $k = (string)$r['meta_key'];
                if (str_ends_with($k, 'capabilities') || in_array($k, ['description', 'first_name', 'last_name'], true)) {
                    $this->userRoles[(string)$r['user_id']][$k] = (string)$r['meta_value'];
                }
                return;
            case 'comments':
                if (!in_array((string)$r['comment_approved'], ['1', '0'], true) || !in_array((string)($r['comment_type'] ?? ''), ['', 'comment', 'review'], true)) {
                    return;
                }
                $this->buffer[] = ['comment', (string)$r['comment_ID'], 'row', ['post_id' => (int)$r['comment_post_ID'], 'parent' => (int)$r['comment_parent'],
                    'author' => $r['comment_author'], 'email' => $r['comment_author_email'], 'url' => $r['comment_author_url'], 'date_gmt' => $r['comment_date_gmt'],
                    'content' => $r['comment_content'], 'approved' => $r['comment_approved'] === '1', 'user_id' => (int)$r['user_id']]];
                return;
            case 'terms':
                $this->buffer[] = ['term', (string)$r['term_id'], 'name', ['name' => $r['name'], 'slug' => $r['slug']]];
                return;
            case 'term_taxonomy':
                $this->buffer[] = ['term', (string)$r['term_id'], 'tax', ['taxonomy' => $r['taxonomy'], 'parent' => (int)$r['parent'], 'description' => $r['description'], 'tt_id' => (int)$r['term_taxonomy_id']]];
                return;
            default:
                $id = (string)($r['id'] ?? $r['ID'] ?? $r['form_id'] ?? count($this->buffer));
                if ($t === 'gf_form_meta') {
                    $id = (string)$r['form_id'];
                } elseif ($t === 'fluentform_form_meta') {
                    $id = (string)$r['id'];
                }
                $this->buffer[] = ['table:' . $t, $id, 'row', $r];
        }
    }

    public static function postRow(array $r): array
    {
        return ['id' => (int)$r['ID'], 'type' => (string)$r['post_type'], 'title' => (string)$r['post_title'], 'slug' => (string)$r['post_name'],
            'content' => (string)$r['post_content'], 'excerpt' => (string)($r['post_excerpt'] ?? ''), 'status' => (string)$r['post_status'],
            'parent' => (int)($r['post_parent'] ?? 0), 'menu_order' => (int)($r['menu_order'] ?? 0), 'date_gmt' => (string)($r['post_date_gmt'] ?? ''),
            'author' => (int)($r['post_author'] ?? 0)];
    }

    /** Options worth keeping (not transients or caches). */
    public static function wantedOption(string $name): bool
    {
        if (preg_match('/^(_transient|_site_transient|_wp_session|wc_sess|rewrite_rules|cron$|_elementor_global_css|elementor_pro_license|.*_license_key|.*_api_key|.*secret|auth_key|logged_in_key|nonce_key|secure_auth)/i', $name)) {
            return false;
        }
        return (bool)preg_match('/^(blogname|blogdescription|siteurl|home|permalink_structure|category_base|tag_base|show_on_front|page_on_front|page_for_posts|posts_per_page|date_format|time_format|timezone_string|gmt_offset|sticky_posts|site_icon|site_logo|stylesheet|template|theme_mods_.+|nav_menu_options|wpseo.*|rank-math-.*|rank_math_.*|aioseo_options.*|seopress_.*|cptui_.*|options_.+|_options_.+|elementor_active_kit|elementor_cpt_support|elementor_.*|wpcf7|redirection_options|wpforms_settings|jet_engine.*|acf_.*|default_comment_status|comment_moderation|wp_user_roles|.*_user_roles)$/', $name);
    }

    private function flush(): void
    {
        if ($this->buffer) {
            Data::putMany($this->buffer);
            $this->buffer = [];
        }
    }

    /** Users get their WordPress role and bio from usermeta. */
    private function finishUsers(): void
    {
        $rows = [];
        foreach ($this->userRoles as $uid => $m) {
            $u = Data::row('user', $uid);
            if (!$u) {
                continue;
            }
            foreach ($m as $k => $v) {
                if (str_ends_with($k, 'capabilities')) {
                    $caps = Data::decode($v);
                    $u['role'] = is_array($caps) ? (string)(array_keys(array_filter($caps))[0] ?? 'subscriber') : 'subscriber';
                } else {
                    $u[$k] = $v;
                }
            }
            $rows[] = ['user', $uid, 'row', $u];
        }
        Data::putMany($rows);
    }
}
