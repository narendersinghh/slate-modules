<?php
declare(strict_types=1);

namespace SlateModules\WpImport;

use Slate\ContentModel;
use Slate\Crypto;
use Slate\DB;
use Slate\Media;
use Slate\Menus;
use Slate\Options;
use Slate\PageCache;
use Slate\Posts;
use Slate\Terms;
use Slate\Auth;
use Slate\HtmlPages;
use SlateModules\AutoCpt\Generator;
use SlateModules\AutoCpt\WpClient;
use SlateModules\AutoCpt\Wxr;

/**
 * Batch importer: WordPress (REST API or WXR export, plus optional SQL dump / uploads zip / companion plugin) → Slate.
 * State lives in the lazily-loaded option "wpi_job"; id/url mappings in the {{wpi_map}} table; staged WordPress data
 * (meta, options, definitions) in {{wpi_data}} (see Data).
 *
 * Options added in 2.0 default to the 1.x behaviour when a job (or the command line) does not set them, so syncing an
 * existing migration keeps working the same way: designed (builder pages as designed pages), clean (Gutenberg → clean
 * HTML), forms, templates, comments, authors (keep|create|me), design_system.
 */
final class Importer
{
    public const WORK = SLATE_CACHE . '/wpi';
    private const BUDGET = 18; // seconds per HTTP request
    private float $t0;
    private array $job;
    private ?WpClient $wp = null;
    private ?array $wxr = null;
    private ?Fetch $fetch = null;
    /** Spec of the source being analysed (before it is saved), for slateTypeFor() / slateTaxFor(). */
    public static ?array $currentSpec = null;
    /** Forms known from the source (plugin:id => normalized form), loaded once per request. */
    private ?array $formDefs = null;
    /** Pre-fetched rendered pages for the current batch: [url => html|null]. */
    private array $prefetch = [];

    // ------------------------------------------------------------------ setup

    public static function install(): void
    {
        $sqlite = DB::$driver === 'sqlite';
        $pk = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        DB::exec("CREATE TABLE IF NOT EXISTS {{wpi_map}} (
            kind VARCHAR(20) NOT NULL, old_id VARCHAR(64) NOT NULL, new_id INT NOT NULL DEFAULT 0,
            old_url VARCHAR(700) NOT NULL DEFAULT '', extra TEXT NULL, PRIMARY KEY (kind, old_id))$pk");
        DB::exec("CREATE TABLE IF NOT EXISTS {{wpi_forms}} (
            id " . ($sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY') . ",
            form VARCHAR(191) NOT NULL DEFAULT '', page VARCHAR(255) NOT NULL DEFAULT '', data TEXT NULL,
            ip VARCHAR(45) NOT NULL DEFAULT '', is_read TINYINT NOT NULL DEFAULT 0, created_at DATETIME NOT NULL)$pk");
        Data::install();
        if (!is_dir(self::WORK)) {
            @mkdir(self::WORK, 0755, true);
        }
        if (!is_file(self::WORK . '/.htaccess')) {
            @file_put_contents(self::WORK . '/.htaccess', "Require all denied\n"); // export files, dumps, archives are private
        }
    }

    /** Option defaults: 1.x behaviour for anything a stored job or the command line does not set. */
    public const LEGACY = ['designed' => false, 'clean' => false, 'forms' => false, 'templates' => false, 'comments' => false, 'authors' => 'keep',
        'design_system' => null, 'seo_global' => false, 'plugin_redirects' => false];

    public static function job(): ?array
    {
        $j = Options::get('wpi_job');
        return is_array($j) ? $j : null;
    }

    private static function store(array $job): void
    {
        Options::set('wpi_job', $job, false);
    }

    public static function client(array $conn): WpClient
    {
        return new WpClient((string)$conn['url'], (string)($conn['user'] ?? ''), Crypto::decrypt((string)($conn['pass'] ?? '')));
    }

    /** Analyse the source and return what will be imported. */
    public static function scan(array $conn): array
    {
        self::install();
        if (($conn['source'] ?? 'rest') === 'wxr') {
            $wxr = self::loadWxr();
            if (!empty($conn['_fresh'])) {
                Data::clear();
                WxrExtras::load(self::WORK . '/export.xml');
            }
            $spec = Generator::fromWxr($wxr);
            $summary = ['site' => $wxr['site']['title'], 'url' => $wxr['site']['url'], 'auth' => false, 'media' => count($wxr['media']),
                'menus' => count($wxr['menus']), 'elementor' => (bool)array_filter($wxr['posts'], static fn($p) => $p['elementor']),
                'seo' => (bool)array_filter($wxr['posts'], static fn($p) => $p['seo']), 'acf' => (bool)array_filter($wxr['posts'], static fn($p) => $p['fields'])];
            if ($summary['seo'] && ($seoPlugin = SeoMap::plugin())) {
                $summary['seo'] = $seoPlugin;
            }
        } else {
            $wp = self::client($conn);
            $root = $wp->get('/');
            if (!$root['ok']) {
                throw new \RuntimeException('Could not reach the WordPress REST API at ' . $wp->site . ', ' . $root['error']);
            }
            $spec = Generator::fromWordPress($wp);
            $media = $wp->get('/wp/v2/media', ['per_page' => 1]);
            $me = $wp->authenticated() ? $wp->get('/wp/v2/users/me', ['context' => 'edit']) : ['ok' => false];
            if ($wp->authenticated() && !$me['ok']) {
                throw new \RuntimeException('WordPress rejected the username / application password: ' . $me['error']);
            }
            $menus = $wp->authenticated() ? $wp->get('/wp/v2/menus', ['per_page' => 100]) : ['ok' => false, 'data' => []];
            $sample = $wp->get('/wp/v2/pages', ['per_page' => 3]);
            $first = $sample['ok'] && is_array($sample['data']) ? ($sample['data'][0] ?? []) : [];
            $summary = ['site' => html_entity_decode((string)($root['data']['name'] ?? ''), ENT_QUOTES), 'url' => $wp->site,
                'auth' => $me['ok'] ?? false, 'user' => $me['data']['name'] ?? null, 'media' => $media['total'],
                'menus' => $menus['ok'] && is_array($menus['data']) ? count($menus['data']) : null,
                'elementor' => str_contains(json_encode($sample['data'] ?? ''), 'elementor'),
                'seo' => isset($first['yoast_head_json']) ? 'Yoast SEO' : (str_contains(json_encode($root['data']['namespaces'] ?? []), 'rankmath') ? 'Rank Math' : false),
                'acf' => isset($first['acf']), 'namespaces' => $root['data']['namespaces'] ?? []];
        }
        if (($conn['source'] ?? 'rest') !== 'wxr' && !empty($conn['_fresh'])) {
            Data::clear();
        }
        if (($conn['source'] ?? 'rest') !== 'wxr' && isset($wp) && $wp->authenticated() && ($info = Companion::detect($wp))) {
            if (!empty($conn['_fresh']) || !Data::has('source')) {
                Companion::load($wp, $info);
            }
            $summary['companion'] = $info;
        }
        if (Data::has('option') || Data::has('wpost')) {
            $spec = Acf::addMissingTypes(Acf::enrichSpec($spec));
        }
        $counts = [];
        foreach ($spec['wp_map']['types'] as $wpSlug => $m) {
            $counts['types'][$wpSlug] = ['slug' => $m['slug'], 'total' => $m['total'] ?? 0];
        }
        foreach ($spec['wp_map']['taxonomies'] as $wpSlug => $m) {
            $counts['taxonomies'][$wpSlug] = ['slug' => $m['slug'], 'total' => $m['total'] ?? null];
        }
        $scan = ['summary' => $summary, 'spec' => $spec, 'counts' => $counts];
        self::$currentSpec = $spec;
        $scan['analysis'] = Analyse::run($conn, $scan, isset($wp) ? $wp : null);
        return $scan;
    }

    /** Re-run only the analysis (after adding an SQL dump or an uploads archive). */
    public static function reanalyse(array $conn, array $scan): array
    {
        if (Data::has('option') || Data::has('wpost')) {
            $scan['spec'] = Acf::addMissingTypes(Acf::enrichSpec($scan['spec']));
            foreach ($scan['spec']['wp_map']['types'] as $wpSlug => $m) {
                $scan['counts']['types'][$wpSlug] ??= ['slug' => $m['slug'], 'total' => $m['total'] ?? 0];
            }
        }
        $wp = ($conn['source'] ?? 'rest') === 'wxr' ? null : self::client($conn);
        self::$currentSpec = $scan['spec'];
        $scan['analysis'] = Analyse::run($conn, $scan, $wp);
        return $scan;
    }

    public static function loadWxr(): array
    {
        $cache = self::WORK . '/wxr.json';
        if (is_file($cache)) {
            return json_decode((string)file_get_contents($cache), true);
        }
        $file = self::WORK . '/export.xml';
        $data = Wxr::parse($file);
        file_put_contents($cache, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $data;
    }

    public static function start(array $conn, array $scan, array $opts): array
    {
        self::install();
        $opts += ['types' => [], 'model' => true, 'media' => true, 'seo' => true, 'menus' => true, 'settings' => true,
            'redirects' => true, 'design' => true, 'activate_theme' => true, 'drafts' => true] + self::LEGACY;
        $opts['design_system'] ??= (bool)$opts['design'];
        if (!empty($opts['only'])) {
            // Re-run just these steps (e.g. ['archives'] to refresh archive snapshots) on an existing import.
            $steps = array_map(static fn($s) => [(string)$s], array_values(array_intersect((array)$opts['only'], ['design', 'archives', 'menus', 'finalize'])));
            $job = ['conn' => $conn, 'spec' => $scan['spec'], 'opts' => $opts, 'steps' => $steps ?: [['finalize']], 'i' => 0, 'page' => 1,
                'total' => 1, 'done' => 0, 'stats' => [], 'log' => [], 'wp_settings' => (array)(self::job()['wp_settings'] ?? []), 'started_at' => now(), 'finished_at' => null, 'report' => [],
                'origin' => rtrim((string)($scan['summary']['url'] ?? $conn['url'] ?? ''), '/')];
            self::store($job);
            return $job;
        }
        $steps = [['cleanup']];
        if ($opts['settings']) {
            $steps[] = ['settings'];
        }
        if ($opts['model']) {
            $steps[] = ['model'];
        }
        if ($opts['design']) {
            $steps[] = ['design'];
        }
        foreach ($scan['spec']['wp_map']['taxonomies'] as $wpTax => $m) {
            if (($m['total'] ?? 1) !== 0) {
                $steps[] = ['terms', $wpTax];
            }
        }
        if ($opts['media']) {
            $steps[] = ['media'];
        }
        if ($opts['authors'] !== 'keep') {
            $steps[] = ['users'];
        }
        if ($opts['forms']) {
            $steps[] = ['forms'];
        }
        foreach ($scan['spec']['wp_map']['types'] as $wpType => $m) {
            if (!$opts['types'] || in_array($wpType, $opts['types'], true)) {
                $steps[] = ['posts', $wpType];
            }
        }
        if ($opts['comments']) {
            $steps[] = ['comments'];
        }
        if ($opts['templates']) {
            $steps[] = ['templates'];
        }
        if ($opts['design']) {
            $steps[] = ['archives'];
        }
        if ($opts['menus']) {
            $steps[] = ['menus'];
        }
        if ($opts['seo'] && $opts['seo_global']) {
            $steps[] = ['seo_global'];
        }
        $steps[] = ['finalize'];
        $total = 0;
        foreach ($scan['counts']['types'] ?? [] as $wt => $c) {
            if (!$opts['types'] || in_array($wt, $opts['types'], true)) {
                $total += (int)$c['total'];
            }
        }
        $total += $opts['media'] ? (int)($scan['summary']['media'] ?? 0) : 0;
        $job = ['conn' => $conn, 'spec' => $scan['spec'], 'opts' => $opts, 'steps' => $steps, 'i' => 0, 'page' => 1,
            'total' => max(1, $total), 'done' => 0, 'stats' => [], 'log' => [], 'wp_settings' => [], 'started_at' => now(), 'finished_at' => null, 'report' => [],
            'origin' => rtrim((string)($scan['summary']['url'] ?? $conn['url'] ?? ''), '/')];
        Options::set('wpi_origin', $job['origin']);
        self::store($job);
        return $job;
    }

    public static function reset(bool $deleteImported = false): void
    {
        self::install();
        if ($deleteImported) {
            foreach (DB::all("SELECT new_id FROM {{wpi_map}} WHERE kind = 'post'") as $r) {
                Posts::delete((int)$r['new_id'], true);
            }
            foreach (DB::all("SELECT new_id FROM {{wpi_map}} WHERE kind = 'media'") as $r) {
                Media::delete((int)$r['new_id']);
            }
            foreach (DB::all("SELECT new_id FROM {{wpi_map}} WHERE kind = 'term'") as $r) {
                Terms::delete((int)$r['new_id']);
            }
            foreach (DB::all("SELECT new_id FROM {{wpi_map}} WHERE kind = 'form'") as $r) {
                Posts::delete((int)$r['new_id'], true);
            }
            if (class_exists(\SlateModules\Comments\Comments::class)) {
                foreach (DB::all("SELECT new_id FROM {{wpi_map}} WHERE kind = 'comment'") as $r) {
                    DB::delete('comments', ['id' => (int)$r['new_id']]);
                }
            }
            foreach (DB::all("SELECT extra FROM {{wpi_map}} WHERE kind = 'template'") as $r) {
                $x = json_decode((string)$r['extra'], true) ?: [];
                if (!empty($x['tpl']) && ($t = \Slate\Templates::find((string)$x['tpl'])) && empty($t['enabled'])) {
                    \Slate\Templates::delete((string)$x['tpl']);
                }
            }
        }
        Data::clear();
        DB::exec('DELETE FROM {{wpi_map}}');
        Options::delete('wpi_job');
        foreach (glob(self::WORK . '/*') ?: [] as $f) {
            is_file($f) && !str_ends_with($f, 'export.xml') && !str_ends_with($f, '.htaccess') && @unlink($f);
        }
        Archive::clear();
    }

    // ------------------------------------------------------------------ run

    /** Run steps until the time budget is used. */
    public static function step(): array
    {
        $job = self::job();
        if (!$job) {
            throw new \RuntimeException('No import is running.');
        }
        @set_time_limit(120);
        self::install(); // a job paused before an update to 2.0 needs the new staging table
        self::$currentSpec = $job['spec'];
        $self = new self($job);
        return $self->run();
    }

    private function __construct(array $job)
    {
        // A job stored by 1.x (e.g. a paused sync) has none of the 2.0 options: run it the 1.x way.
        $job['opts'] = (array)($job['opts'] ?? []) + self::LEGACY;
        $job['opts']['design_system'] ??= !empty($job['opts']['design']);
        $job['report'] ??= [];
        $this->job = $job;
        $this->t0 = microtime(true);
    }

    private function run(): array
    {
        while ($this->job['i'] < count($this->job['steps']) && microtime(true) - $this->t0 < self::BUDGET) {
            [$name, $arg] = $this->job['steps'][$this->job['i']] + [1 => null];
            try {
                $finished = match ($name) {
                    'cleanup' => $this->stepCleanup(),
                    'settings' => $this->stepSettings(),
                    'model' => $this->stepModel(),
                    'design' => $this->stepDesign(),
                    'terms' => $this->stepTerms((string)$arg),
                    'media' => $this->stepMedia(),
                    'posts' => $this->stepPosts((string)$arg),
                    'menus' => $this->stepMenus(),
                    'archives' => $this->stepArchives(),
                    'users' => $this->stepUsers(),
                    'forms' => $this->stepForms(),
                    'comments' => $this->stepComments(),
                    'templates' => $this->stepTemplates(),
                    'seo_global' => $this->stepSeoGlobal(),
                    'finalize' => $this->stepFinalize(),
                    default => true,
                };
            } catch (\Throwable $e) {
                $this->log('Warning: ' . $name . ($arg ? " ($arg)" : '') . ': ' . $e->getMessage());
                $this->warn($name === 'posts' ? 'content' : $name, ucfirst($name) . ($arg ? " ($arg)" : '') . ' stopped early: ' . $e->getMessage());
                $finished = true;
            }
            if ($finished) {
                $this->job['i']++;
                $this->job['page'] = 1;
            }
            self::store($this->job);
        }
        $complete = $this->job['i'] >= count($this->job['steps']);
        if ($complete && !$this->job['finished_at']) {
            $this->job['finished_at'] = now();
            self::store($this->job);
            PageCache::clear();
            log_activity('wp_import_finished', $this->job['origin']);
        }
        $cur = $this->job['steps'][$this->job['i']] ?? ['done'];
        return ['done' => $complete, 'progress' => $complete ? 100 : min(99, (int)round(($this->job['done'] / $this->job['total']) * 90 + $this->job['i'] / count($this->job['steps']) * 10)),
            'step' => $this->label($cur), 'stats' => $this->job['stats'], 'log' => array_slice($this->job['log'], -40)];
    }

    private function label(array $step): string
    {
        return match ($step[0]) {
            'cleanup' => 'Removing Slate sample content', 'settings' => 'Reading site settings', 'model' => 'Creating post types, taxonomies & fields', 'design' => 'Mirroring theme design',
            'terms' => 'Importing ' . ($step[1] ?? '') . ' terms', 'media' => 'Importing & optimizing media (page ' . $this->job['page'] . ')',
            'posts' => 'Importing ' . ($step[1] ?? '') . ' (page ' . $this->job['page'] . ')', 'menus' => 'Importing menus', 'archives' => 'Capturing archive pages (' . count($this->job['archive_queue'] ?? []) . ' left)',
            'finalize' => 'Linking parents, front page, permalinks & redirects', 'users' => 'Importing authors', 'forms' => 'Importing forms',
            'comments' => 'Importing comments', 'templates' => 'Importing templates', 'seo_global' => 'Importing SEO settings', default => 'Done',
        };
    }

    private function log(string $msg): void
    {
        $this->job['log'][] = gmdate('H:i:s') . '  ' . $msg;
        if (count($this->job['log']) > 300) {
            $this->job['log'] = array_slice($this->job['log'], -300);
        }
    }

    private function stat(string $k, int $n = 1): void
    {
        $this->job['stats'][$k] = ($this->job['stats'][$k] ?? 0) + $n;
    }

    // ------------------------------------------------------------------ report (per category: counts, warnings, items)

    private function rep(string $cat, string $key, int $n = 1): void
    {
        $this->job['report'][$cat]['counts'][$key] = ($this->job['report'][$cat]['counts'][$key] ?? 0) + $n;
    }

    private function warn(string $cat, string $msg): void
    {
        $w = &$this->job['report'][$cat]['warnings'];
        $w ??= [];
        if (!in_array($msg, $w, true) && count($w) < 60) {
            $w[] = $msg;
        }
    }

    /** A reviewable item: title, url, edit link, note; $ai: a prompt for "Improve with Goraiya". */
    private function item(string $cat, string $title, string $url, string $edit, string $note, string $ai = ''): void
    {
        $list = &$this->job['report'][$cat]['items'];
        $list ??= [];
        if (count($list) < 150) {
            $list[] = ['title' => $title, 'url' => $url, 'edit' => $edit, 'note' => $note, 'ai' => $ai];
        }
    }

    private function isWxr(): bool
    {
        return ($this->job['conn']['source'] ?? 'rest') === 'wxr';
    }

    private function wp(): WpClient
    {
        return $this->wp ??= self::client($this->job['conn'] + ['url' => $this->job['origin']]);
    }

    private function wxr(): array
    {
        return $this->wxr ??= self::loadWxr();
    }

    private function fetch(): Fetch
    {
        return $this->fetch ??= new Fetch($this->job['origin']);
    }

    /** REST client only when the source is the live site with credentials (companion plugin, forms, templates). */
    private function restOrNull(): ?WpClient
    {
        return !$this->isWxr() && !empty($this->job['conn']['url']) ? $this->wp() : null;
    }

    /** Slate post type for a WordPress post type of the current (or last analysed) source. */
    public static function slateTypeFor(string $wp): ?string
    {
        $spec = self::$currentSpec ?? self::job()['spec'] ?? (Options::get('wpi_scan')['spec'] ?? []);
        if (isset($spec['wp_map']['types'][$wp]['slug'])) {
            return (string)$spec['wp_map']['types'][$wp]['slug']; // created by the import if it does not exist yet
        }
        $slug = Generator::TYPE_ALIASES[$wp] ?? null;
        return $slug && ContentModel::type($slug) ? $slug : null;
    }

    public static function slateTaxFor(string $wp): ?string
    {
        $spec = self::$currentSpec ?? self::job()['spec'] ?? (Options::get('wpi_scan')['spec'] ?? []);
        if (isset($spec['wp_map']['taxonomies'][$wp]['slug'])) {
            return (string)$spec['wp_map']['taxonomies'][$wp]['slug'];
        }
        $slug = Generator::TAX_ALIASES[$wp] ?? null;
        return $slug && ContentModel::taxonomy($slug) ? $slug : null;
    }

    // ------------------------------------------------------------------ mapping

    public static function map(string $kind, string|int $oldId): ?int
    {
        $v = DB::val('SELECT new_id FROM {{wpi_map}} WHERE kind = :k AND old_id = :o', ['k' => $kind, 'o' => (string)$oldId]);
        return $v === null ? null : (int)$v;
    }

    public static function setMapPublic(string $kind, string|int $oldId, int $newId, string $url = '', array $extra = []): void
    {
        self::setMap($kind, $oldId, $newId, $url, $extra);
    }

    public static function mapExtra(string $kind, string|int $oldId): ?array
    {
        $v = DB::val('SELECT extra FROM {{wpi_map}} WHERE kind = :k AND old_id = :o', ['k' => $kind, 'o' => (string)$oldId]);
        return $v === null ? null : (json_decode((string)$v, true) ?: []);
    }

    private static function setMap(string $kind, string|int $oldId, int $newId, string $url = '', array $extra = []): void
    {
        DB::query('DELETE FROM {{wpi_map}} WHERE kind = :k AND old_id = :o', ['k' => $kind, 'o' => (string)$oldId]);
        DB::insert('wpi_map', ['kind' => $kind, 'old_id' => (string)$oldId, 'new_id' => $newId, 'old_url' => mb_substr($url, 0, 700),
            'extra' => $extra ? json_encode($extra, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null]);
    }

    /** Map an old upload URL (any size) to the new media id. */
    public static function mediaByUrl(string $url): ?int
    {
        $path = (string)parse_url($url, PHP_URL_PATH);
        if ($path === '') {
            return null;
        }
        $id = self::map('media_url', md5($path));
        if ($id !== null) {
            return $id;
        }
        // "image-300x200.jpg" / "image-scaled.jpg" → "image.jpg"
        $orig = preg_replace('/-(\d+x\d+|scaled|rotated|e\d{10,})(?=\.\w+$)/', '', $path);
        return $orig !== $path ? self::map('media_url', md5((string)$orig)) : null;
    }

    private function slateType(string $wpType): ?string
    {
        $slug = $this->job['spec']['wp_map']['types'][$wpType]['slug'] ?? null;
        return $slug && ContentModel::type($slug) ? $slug : null;
    }

    private function slateTax(string $wpTax): ?string
    {
        $slug = $this->job['spec']['wp_map']['taxonomies'][$wpTax]['slug'] ?? null;
        return $slug && ContentModel::taxonomy($slug) ? $slug : null;
    }

    // ------------------------------------------------------------------ steps

    /** Remove Slate's untouched sample content so WordPress URLs (/, /about/, /blog/…) are free. */
    private function stepCleanup(): bool
    {
        $samples = [
            ['page', 'home', 'This is your homepage'], ['page', 'about', 'Tell your visitors who you are'],
            ['page', 'contact', 'How can people reach you?'], ['page', 'blog', ''], ['post', 'hello-world', 'This is your first post'],
        ];
        $n = 0;
        foreach ($samples as [$type, $slug, $marker]) {
            $p = Posts::findBySlug($type, $slug, false);
            if (!$p || self::isMapped((int)$p['id'])) {
                continue;
            }
            $content = trim(strip_tags((string)$p['content']));
            if (($marker === '' && $content === '') || ($marker !== '' && str_contains($content, $marker))) {
                Posts::delete((int)$p['id'], true);
                $n++;
            }
        }
        $menus = Menus::all();
        if (isset($menus['main']) && $menus['main']['name'] === 'Main menu' && $this->job['opts']['menus']) {
            unset($menus['main']);
            Menus::save(array_values($menus));
        }
        $this->log($n ? "Removed $n untouched Slate sample item(s)." : 'No sample content to remove.');
        return true;
    }

    private static function isMapped(int $newId): bool
    {
        return (bool)DB::val("SELECT 1 FROM {{wpi_map}} WHERE kind = 'post' AND new_id = :id", ['id' => $newId]);
    }

    private function stepSettings(): bool
    {
        if ($this->isWxr()) {
            $s = $this->wxr()['site'];
            $this->job['wp_settings'] = ['title' => $s['title'], 'description' => $s['description']];
            // Reading settings, front page and logo from an uploaded database dump, when there is one.
            foreach (['show_on_front', 'page_on_front', 'page_for_posts', 'timezone_string', 'date_format', 'time_format', 'posts_per_page', 'site_icon', 'site_logo'] as $k) {
                if (($v = Data::option($k)) !== null && !is_array($v)) {
                    $this->job['wp_settings'][$k] = $v;
                }
            }
        } else {
            $r = $this->wp()->get('/wp/v2/settings');
            $root = $this->wp()->get('/');
            $set = $r['ok'] && is_array($r['data']) ? $r['data'] : [];
            $set += ['title' => html_entity_decode((string)($root['data']['name'] ?? ''), ENT_QUOTES),
                'description' => html_entity_decode((string)($root['data']['description'] ?? ''), ENT_QUOTES),
                'site_logo' => $root['data']['site_logo'] ?? null, 'site_icon' => $root['data']['site_icon'] ?? null,
                'timezone_string' => $root['data']['timezone_string'] ?? null];
            $this->job['wp_settings'] = $set;
        }
        $s = $this->job['wp_settings'];
        if (!empty($s['title'])) {
            Options::set('site_title', html_entity_decode((string)$s['title'], ENT_QUOTES));
        }
        if (isset($s['description'])) {
            Options::set('tagline', html_entity_decode((string)$s['description'], ENT_QUOTES));
        }
        $tz = $s['timezone'] ?? $s['timezone_string'] ?? '';
        if ($tz && in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
            Options::set('timezone', $tz);
        }
        if (!empty($s['date_format'])) {
            Options::set('date_format', $s['date_format']);
        }
        if (!empty($s['time_format'])) {
            Options::set('time_format', $s['time_format']);
        }
        if (!empty($s['posts_per_page'])) {
            Options::set('posts_per_page', max(1, min(100, (int)$s['posts_per_page'])));
        }
        $this->log('Site settings imported: ' . ($s['title'] ?? ''));
        return true;
    }

    private function stepModel(): bool
    {
        foreach (Generator::apply($this->job['spec']) as $line) {
            $this->log($line);
            $this->rep('model', 'definitions');
        }
        foreach (Acf::apply($this->job['spec']) as $line) {
            $this->log($line);
            if (!str_starts_with($line, '  ')) {
                $this->rep('model', 'acf_groups');
            } else {
                $this->warn('model', trim($line));
            }
        }
        foreach ((array)($this->job['spec']['registered'] ?? []) as $src) {
            $this->log('Post types and taxonomies use the exact settings from ' . ($src === 'cptui' ? 'CPT UI' : 'ACF') . '.');
        }
        return true;
    }

    private function stepDesign(): bool
    {
        $url = $this->job['origin'] . '/';
        $html = $this->fetch()->html($url);
        if (!$html) {
            $this->log('Warning: could not fetch the homepage to mirror the design.');
            return true;
        }
        $res = (new Mirror($this->job['origin']))->captureSite($html);
        $this->log("Design mirrored: {$res['css']} stylesheets, {$res['js']} scripts, header " . ($res['header'] ? 'yes' : 'no') . ', footer ' . ($res['footer'] ? 'yes' : 'no'));
        if ($this->job['opts']['design_system'] ?? true) {
            $ds = \Slate\DesignSystem::fromElementorCss(Mirror::globalCss());
            \Slate\DesignSystem::save($ds);
            $this->log('Design system updated from the site: ' . count($ds['colors']) . ' colours, fonts “' . $ds['fonts']['heading'] . '” / “' . $ds['fonts']['body'] . '”.');
            $this->rep('design', 'design_system');
        }
        if ($this->job['opts']['activate_theme']) {
            Options::set('theme', 'wp-mirror');
            $this->log('Activated the “WordPress mirror” theme.');
        }
        return true;
    }

    private function stepTerms(string $wpTax): bool
    {
        $tax = $this->slateTax($wpTax);
        if (!$tax) {
            return true;
        }
        if ($this->isWxr()) {
            foreach (array_filter($this->wxr()['terms'], static fn($t) => $t['taxonomy'] === $wpTax) as $t) {
                $this->importTerm($tax, $wpTax, (string)$t['old_id'], $t['name'], $t['slug'], $t['description'], $t['parent'] ? 'slug:' . $t['parent'] : null, '', []);
            }
            return true;
        }
        $base = $this->job['spec']['wp_map']['taxonomies'][$wpTax]['rest_base'];
        $r = $this->wp()->get('/wp/v2/' . $base, ['per_page' => 100, 'page' => $this->job['page'], 'hide_empty' => 'false']);
        if (!$r['ok'] || !is_array($r['data']) || !$r['data']) {
            return true;
        }
        foreach ($r['data'] as $t) {
            $seo = isset($t['yoast_head_json']) ? $this->yoastSeo($t['yoast_head_json'], (string)($t['link'] ?? '')) : [];
            $this->importTerm($tax, $wpTax, (string)$t['id'], html_entity_decode((string)$t['name'], ENT_QUOTES), (string)$t['slug'],
                (string)($t['description'] ?? ''), !empty($t['parent']) ? 'id:' . $t['parent'] : null, (string)($t['link'] ?? ''), $seo, (array)($t['acf'] ?? []));
        }
        $this->job['page']++;
        return $this->job['page'] > max(1, $r['pages']);
    }

    private function importTerm(string $tax, string $wpTax, string $oldId, string $name, string $slug, string $desc, ?string $parentRef, string $link, array $seo, array $fields = []): void
    {
        $key = $wpTax . ':' . $oldId;
        $id = self::map('term', $key) ?? (Terms::findBySlug($tax, $slug)['id'] ?? null);
        $defs = ContentModel::fieldsFor('term', $tax);
        // Staged term meta (companion / SQL / WXR): exact ACF values and SEO set by hand.
        $meta = Data::meta($oldId, 'termmeta');
        if ($meta && ($acf = Acf::fieldsForTax($wpTax))) {
            $fields = Acf::values($acf, $meta, $this->acfCallbacks()) + $fields;
        }
        if ($this->job['opts']['seo']) {
            $seo = array_merge($seo, array_filter(SeoMap::termFromMeta($meta)), SeoMap::yoastTerm($wpTax, (int)$oldId));
            unset($seo['og_image_url'], $seo['og_image_id']);
        }
        $id = Terms::save($tax, ['name' => $name, 'slug' => $slug, 'description' => $desc, 'seo' => $seo,
            'fields' => $defs ? ContentModel::sanitizeFields($defs, $fields, true) : []], $id);
        if ($defs && $fields) {
            // Relationship values ("old:ID") are resolved in finalize like post fields.
            DB::update('terms', ['fields' => json_encode(array_merge(ContentModel::sanitizeFields($defs, $fields, true), array_filter($fields, static fn($v) => is_array($v) && $v && is_string(reset($v)) && str_starts_with((string)reset($v), 'old:'))), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)], ['id' => $id]);
        }
        self::setMap('term', $key, $id, $link, ['parent' => $parentRef, 'tax' => $tax, 'wp_tax' => $wpTax, 'slug' => $slug]);
        $this->stat('terms');
        $this->rep('terms', 'imported');
    }

    private function stepMedia(): bool
    {
        if ($this->isWxr()) {
            $items = array_slice($this->wxr()['media'], ($this->job['page'] - 1) * 10, 10);
            foreach ($items as $m) {
                $this->importMedia((string)$m['old_id'], $m['url'], (string)$m['alt'], (string)$m['title'], strip_tags((string)$m['caption']), [], $m['date_gmt']);
            }
            $this->job['page']++;
            return count($items) < 10;
        }
        $r = $this->wp()->get('/wp/v2/media', ['per_page' => 10, 'page' => $this->job['page'], 'orderby' => 'id', 'order' => 'asc']);
        if (!$r['ok'] || !is_array($r['data']) || !$r['data']) {
            return true;
        }
        foreach ($r['data'] as $m) {
            $sizes = [];
            foreach ((array)($m['media_details']['sizes'] ?? []) as $sz) {
                if (!empty($sz['source_url'])) {
                    $sizes[] = $sz['source_url'];
                }
            }
            $this->importMedia((string)$m['id'], (string)$m['source_url'], (string)($m['alt_text'] ?? ''),
                html_entity_decode(strip_tags((string)($m['title']['rendered'] ?? '')), ENT_QUOTES), trim(html_entity_decode(strip_tags((string)($m['caption']['rendered'] ?? '')), ENT_QUOTES)),
                $sizes, $m['date_gmt'] ?? null);
            if (microtime(true) - $this->t0 > self::BUDGET + 8) {
                break;
            }
        }
        $this->job['page']++;
        return $this->job['page'] > max(1, $r['pages']);
    }

    private function importMedia(string $oldId, string $url, string $alt, string $title, string $caption, array $sizes, ?string $date): void
    {
        $this->job['done']++;
        if ($url === '' || self::map('media', $oldId) !== null) {
            return;
        }
        $abs = $this->wpAbs($url);
        $name = basename((string)parse_url($abs, PHP_URL_PATH));
        $name = preg_replace('/-(scaled|rotated)(?=\.\w+$)/', '', rawurldecode($name));
        $tmp = self::WORK . '/dl-' . bin2hex(random_bytes(6));
        // A file from the uploaded wp-content/uploads archive is used instead of downloading it.
        $local = Archive::localUpload((string)parse_url($abs, PHP_URL_PATH));
        if ($local !== null) {
            copy($local, $tmp);
        } elseif (!$this->fetch()->allowed($abs)) {
            $this->log("Warning: Skipped $name: it is on another server (" . parse_url($abs, PHP_URL_HOST) . ')');
            $this->warn('media', 'Files on other servers are not downloaded (only ' . parse_url($this->job['origin'], PHP_URL_HOST) . ' and its subdomains): ' . parse_url($abs, PHP_URL_HOST));
            $this->stat('media_failed');
            $this->rep('media', 'skipped');
            return;
        } elseif (!$this->fetch()->download($abs, $tmp)) {
            $this->log("Warning: Could not download $name");
            $this->warn('media', "Could not download $name");
            $this->stat('media_failed');
            $this->rep('media', 'failed');
            return;
        }
        $res = Media::upload(['name' => $name, 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp)], true);
        @unlink($tmp);
        if (!$res['ok']) {
            $this->log("Warning: $name: " . $res['error']);
            $this->warn('media', "$name: " . $res['error']);
            $this->stat('media_failed');
            $this->rep('media', 'failed');
            return;
        }
        $id = (int)$res['media']['id'];
        $upd = ['alt' => mb_substr($alt, 0, 255), 'title' => mb_substr($title ?: pathinfo($name, PATHINFO_FILENAME), 0, 255), 'caption' => $caption];
        if ($date) {
            $upd['created_at'] = $date;
        }
        DB::update('media', $upd, ['id' => $id]);
        self::setMap('media', $oldId, $id, $abs);
        foreach (array_unique(array_merge([$abs], $sizes)) as $u) {
            $p = (string)parse_url($this->wpAbs($u), PHP_URL_PATH);
            if ($p !== '') {
                self::setMap('media_url', md5($p), $id, $p);
            }
        }
        $this->stat('media');
        $this->rep('media', $local !== null ? 'from_archive' : 'imported');
        if ($alt === '' && str_starts_with((string)($res['media']['mime'] ?? ''), 'image/')) {
            $this->rep('media', 'no_alt');
        }
    }

    private function stepPosts(string $wpType): bool
    {
        $type = $this->slateType($wpType);
        if (!$type) {
            $this->log("Skipped $wpType (no matching Slate post type).");
            $this->warn('content', "“{$wpType}” was skipped: no matching Slate post type (switch on “Create post types” to create it).");
            return true;
        }
        if ($this->isWxr()) {
            $all = array_values(array_filter($this->wxr()['posts'], static fn($p) => $p['type'] === $wpType));
            $n = $this->batch();
            $items = array_slice($all, ($this->job['page'] - 1) * $n, $n);
            $items = array_map(fn($p) => $this->normalizeWxrPost($p), $items);
            $this->prefetchPages($items);
            foreach ($items as $p) {
                $this->importPost($type, $wpType, $p);
            }
            $this->prefetch = [];
            $this->job['page']++;
            return count($items) < $n;
        }
        $base = $this->job['spec']['wp_map']['types'][$wpType]['rest_base'];
        $q = ['per_page' => $this->batch(), 'page' => $this->job['page'], 'orderby' => 'id', 'order' => 'asc'];
        if ($this->wp()->authenticated()) {
            $q += ['context' => 'edit', 'status' => $this->job['opts']['drafts'] ? 'publish,future,draft,pending,private' : 'publish,future'];
        }
        $r = $this->wp()->get('/wp/v2/' . $base, $q);
        if (!$r['ok'] && isset($q['context'])) {
            unset($q['context'], $q['status']);
            $r = $this->wp()->get('/wp/v2/' . $base, $q);
        }
        if (!$r['ok'] || !is_array($r['data']) || !$r['data']) {
            if (!$r['ok'] && $this->job['page'] === 1) {
                $this->log("Warning: $wpType: " . $r['error']);
                $this->warn('content', "$wpType: " . $r['error']);
            }
            return true;
        }
        // Post meta of this batch through the companion plugin (ACF raw values, SEO meta, builder data).
        if (isset(Data::sources()['companion'])) {
            Companion::fetchMeta($this->wp(), array_map(static fn($p) => (int)$p['id'], $r['data']));
        }
        $items = array_map(fn($p) => $this->normalizeRestPost($p, $wpType), $r['data']);
        $this->prefetchPages($items);
        foreach ($items as $p) {
            $this->importPost($type, $wpType, $p);
        }
        $this->prefetch = [];
        $this->job['page']++;
        return $this->job['page'] > max(1, $r['pages']);
    }

    /** Fetch the rendered pages of a batch in parallel (design mirror, designed pages, SEO from HTML). */
    private function prefetchPages(array $items): void
    {
        $o = $this->job['opts'];
        if (!$o['design'] && !$o['seo'] && !$o['designed']) {
            return;
        }
        $urls = [];
        foreach ($items as $p) {
            if ($p['link'] !== '' && in_array($p['status'], ['publish', 'future'], true) && !str_contains($p['link'], '?')) {
                $urls[] = $p['link'];
            }
        }
        $this->prefetch = $this->fetch()->htmlMulti($urls, 8);
        if ($o['design']) {
            (new Mirror($this->job['origin']))->prefetchAssets($this->prefetch);
        }
    }

    /** Smaller batches when each item also fetches its rendered page (design mirror / SEO from HTML). */
    private function batch(): int
    {
        return 10;
    }

    private function normalizeRestPost(array $p, string $wpType): array
    {
        $terms = [];
        foreach ($this->job['spec']['wp_map']['taxonomies'] as $wpTax => $m) {
            foreach ((array)($p[$m['rest_base']] ?? []) as $tid) {
                $terms[] = ['taxonomy' => $wpTax, 'old_id' => (string)$tid];
            }
        }
        $fields = [];
        foreach (['meta', 'acf'] as $b) {
            if (!empty($p[$b]) && is_array($p[$b]) && !array_is_list($p[$b])) {
                $fields = array_merge($fields, $p[$b]);
            }
        }
        $title = $p['title']['raw'] ?? html_entity_decode(strip_tags((string)($p['title']['rendered'] ?? '')), ENT_QUOTES);
        $excerpt = $p['excerpt']['raw'] ?? null;
        if ($excerpt === null) {
            $excerpt = trim(html_entity_decode(strip_tags((string)($p['excerpt']['rendered'] ?? '')), ENT_QUOTES));
            $excerpt = preg_replace('/\s*\[(…|&hellip;|\.\.\.)\]\s*$/u', '', $excerpt); // drop WP auto-excerpts' "[…]"
        }
        return [
            'old_id' => (int)$p['id'], 'title' => (string)$title, 'slug' => rawurldecode((string)$p['slug']), 'status' => (string)($p['status'] ?? 'publish'),
            'date_gmt' => $p['date_gmt'] ?? null, 'modified_gmt' => $p['modified_gmt'] ?? null,
            'content' => (string)($p['content']['rendered'] ?? ''), 'raw' => (string)($p['content']['raw'] ?? ''), 'rendered' => true, 'excerpt' => (string)$excerpt,
            'parent' => (int)($p['parent'] ?? 0), 'menu_order' => (int)($p['menu_order'] ?? 0), 'link' => (string)($p['link'] ?? ''),
            'thumbnail' => (int)($p['featured_media'] ?? 0), 'template' => (string)($p['template'] ?? ''), 'terms' => $terms, 'fields' => $fields,
            'seo' => isset($p['yoast_head_json']) ? $this->yoastSeo($p['yoast_head_json'], (string)($p['link'] ?? '')) : [],
            'elementor' => str_contains((string)($p['content']['rendered'] ?? ''), 'data-elementor-type'),
            'author' => (int)($p['author'] ?? 0), 'author_login' => '', 'sticky' => !empty($p['sticky']),
        ];
    }

    private function normalizeWxrPost(array $p): array
    {
        $meta = Data::meta((int)$p['old_id']);
        return $p + ['modified_gmt' => null, 'raw' => (string)$p['content'], 'rendered' => false, 'author' => 0,
            'author_login' => (string)($meta['_wpi_author'] ?? ''), 'sticky' => !empty($meta['_wpi_sticky'])];
    }

    private function yoastSeo(array $y, string $link): array
    {
        $seo = [];
        if (!empty($y['title'])) {
            $seo['title'] = html_entity_decode((string)$y['title'], ENT_QUOTES);
        }
        if (!empty($y['description'])) {
            $seo['description'] = html_entity_decode((string)$y['description'], ENT_QUOTES);
        }
        if (($y['robots']['index'] ?? '') === 'noindex') {
            $seo['robots_index'] = 'noindex';
        }
        if (($y['robots']['follow'] ?? '') === 'nofollow') {
            $seo['nofollow'] = true;
        }
        if (!empty($y['canonical']) && rtrim((string)$y['canonical'], '/') !== rtrim($link, '/')) {
            $seo['canonical'] = (string)$y['canonical'];
        }
        if (!empty($y['og_title']) && $y['og_title'] !== ($y['title'] ?? '')) {
            $seo['og_title'] = html_entity_decode((string)$y['og_title'], ENT_QUOTES);
        }
        if (!empty($y['og_description']) && $y['og_description'] !== ($y['description'] ?? '')) {
            $seo['og_description'] = html_entity_decode((string)$y['og_description'], ENT_QUOTES);
        }
        if (!empty($y['og_image'][0]['url'])) {
            $seo['og_image_url'] = (string)$y['og_image'][0]['url'];
        }
        return $seo;
    }

    /** SEO from the rendered page head (works for Rank Math, AIOSEO, SEOPress, etc.). */
    private function seoFromHtml(string $html, string $link): array
    {
        $seo = [];
        if (preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m)) {
            $seo['title'] = trim(html_entity_decode($m[1], ENT_QUOTES));
        }
        if (preg_match('#<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']*)#i', $html, $m)
            || preg_match('#<meta[^>]+content=["\']([^"\']*)["\'][^>]+name=["\']description#i', $html, $m)) {
            $seo['description'] = html_entity_decode($m[1], ENT_QUOTES);
        }
        if (preg_match('#<meta[^>]+name=["\']robots["\'][^>]+content=["\']([^"\']*)#i', $html, $m)) {
            if (str_contains($m[1], 'noindex')) {
                $seo['robots_index'] = 'noindex';
            }
            if (str_contains($m[1], 'nofollow')) {
                $seo['nofollow'] = true;
            }
        }
        if (preg_match('#<link[^>]+rel=["\']canonical["\'][^>]+href=["\']([^"\']+)#i', $html, $m) && rtrim($m[1], '/') !== rtrim($link, '/')) {
            $seo['canonical'] = $m[1];
        }
        if (preg_match('#<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)#i', $html, $m)) {
            $seo['og_image_url'] = $m[1];
        }
        if (preg_match_all('#<script[^>]+application/ld\+json[^>]*>(.*?)</script>#is', $html, $mm)) {
            $seo['_schema_found'] = count($mm[1]);
        }
        return $seo;
    }

    private function importPost(string $type, string $wpType, array $p): void
    {
        $this->job['done']++;
        if (in_array($p['status'], ['trash', 'auto-draft', 'inherit'], true) || (!$this->job['opts']['drafts'] && $p['status'] !== 'publish' && $p['status'] !== 'future')) {
            if (!in_array($p['status'], ['trash', 'auto-draft', 'inherit'], true)) {
                $this->rep('content', 'skipped_drafts');
            }
            return;
        }
        $key = $wpType . ':' . $p['old_id'];
        $existing = self::map('post', $key);
        if (!$existing && $p['slug'] !== '' && ($same = Posts::findBySlug($type, $p['slug'], false)) && !self::isMapped((int)$same['id'])) {
            $existing = (int)$same['id']; // same URL already exists in Slate → update it instead of creating "-2"
        }
        $opts = $this->job['opts'];
        $meta = Data::meta($p['old_id']);
        $builder = Builders::detect($p['raw'] !== '' ? $p['raw'] : $p['content'], $meta, $p['rendered'] ? $p['content'] : '');
        $published = $p['link'] !== '' && in_array($p['status'], ['publish', 'future'], true);
        $wantsDesigned = $opts['designed'] && Builders::isBuilder($builder) && $published && class_exists(HtmlPages::class);
        $metaSeo = $opts['seo'] && $meta ? SeoMap::fromMeta($meta, $this->aioseoRow((int)$p['old_id'])) : [];
        $html = null;
        $needsHtml = ($opts['design'] || $wantsDesigned || ($opts['seo'] && !$p['seo'] && !$metaSeo) || ($this->isWxr() && ($p['elementor'] || Builders::isBuilder($builder)))) && $published;
        if ($needsHtml) {
            $html = array_key_exists($p['link'], $this->prefetch) ? $this->prefetch[$p['link']] : $this->fetch()->html($p['link']);
        }
        if ($html && !Builders::isBuilder($builder) && Builders::isBuilder($b2 = Builders::detect($p['raw'] !== '' ? $p['raw'] : $p['content'], $meta, $html))) {
            $builder = $b2;
            $wantsDesigned = $opts['designed'] && $published && class_exists(HtmlPages::class);
        }
        $this->rep('builders', $builder);
        $notes = [];
        $designed = null;
        if ($wantsDesigned && $html) {
            // Links keep their old root-relative path here; finalize points the ones whose address changed at the new one.
            $d = new Designed($this->job['origin'], static fn(string $path) => null);
            $designed = $d->build($html, fn(string $plugin, string $id) => $this->formSlug($plugin, $id));
            $notes = array_merge($notes, $designed['notes']);
        } elseif ($wantsDesigned) {
            $this->warn('content', 'Some ' . Builders::LABELS[$builder] . ' pages could not be fetched from the live site; they were rebuilt from the stored content without their design.');
        }
        $content = $designed ? '' : $this->convertContent($p, $builder, $html, $meta, $notes);

        $seo = $p['seo'];
        if ($metaSeo || ($opts['seo'] && $meta && Data::hasMeta($p['old_id']))) {
            // With the plugin's title templates imported, keep only what was set by hand (templates stay templates);
            // otherwise keep the rendered values (1.x) and add the hand-set ones on top.
            $seo = $opts['seo_global'] ? $metaSeo : array_merge($p['seo'], $metaSeo);
        } elseif ($opts['seo'] && !$seo && $html) {
            $seo = $this->seoFromHtml($html, $p['link']);
        }
        if ($opts['seo'] && $html && empty($seo['schema_custom']) && ($schema = Mirror::schemaNodes($html, $this->job['origin'], site_url()))) {
            $seo['schema_custom'] = $schema; // keep rich results (Person, Book, Event, FAQPage, Article…)
        }
        if (!$opts['seo']) {
            $seo = [];
        }
        if (!empty($seo['og_image_id'])) {
            $seo['og_image'] = self::map('media', (string)$seo['og_image_id']);
        } elseif (!empty($seo['og_image_url'])) {
            $seo['og_image'] = self::mediaByUrl($this->wpAbs($seo['og_image_url']));
        }
        unset($seo['og_image_url'], $seo['og_image_id'], $seo['_schema_found']);
        if (!empty($seo['canonical'])) {
            $seo['canonical'] = $this->relativeIfOwn($seo['canonical']);
        }
        if ($seo) {
            $this->rep('seo', 'items');
        }
        if (!empty($seo['focus_keyword'])) {
            $this->rep('seo', 'focus_keywords');
        }

        $defs = ContentModel::fieldsFor('post', $type);
        $fields = $this->convertFields($defs, $p['fields']);
        if ($meta && ($acf = Acf::fieldsForType($wpType))) {
            $vals = Acf::values($acf, $meta, $this->acfCallbacks());
            $fields = array_merge($fields, array_intersect_key($vals, $defs));
            if ($vals) {
                $this->rep('fields', 'items_with_acf');
            }
        }
        $wpTemplate = $p['template'] !== '' ? $p['template'] : (string)($meta['_wp_page_template'] ?? '');
        if ($wpTemplate !== '' && $wpTemplate !== 'default') {
            $fields['_wp_template'] = $wpTemplate;
        }
        if (!empty($p['sticky'])) {
            $fields['_wp_sticky'] = true;
            $this->rep('content', 'sticky');
        }
        if ($opts['design'] && $html && !$designed) {
            $fields += Mirror::pageFields($html, $this->job['origin'], (new Mirror($this->job['origin'])));
            if ($type !== 'page' && !Options::get('wpm_single_' . $type)) {
                if ($wrap = Mirror::captureSingle($html, $p['title'], $this->job['origin'])) {
                    Options::set('wpm_single_' . $type, $wrap);
                    $this->log("Captured the single “{$wpType}” layout for reuse.");
                }
            }
        }
        $terms = [];
        foreach ($p['terms'] as $t) {
            $tax = $this->slateTax($t['taxonomy']);
            if (!$tax || !in_array($tax, array_keys(ContentModel::taxonomiesFor($type)), true)) {
                continue;
            }
            $tid = isset($t['old_id']) ? self::map('term', $t['taxonomy'] . ':' . $t['old_id']) : (Terms::findBySlug($tax, (string)$t['slug'])['id'] ?? null);
            if ($tid) {
                $terms[$tax][] = $tid;
            }
        }
        foreach (array_keys(ContentModel::taxonomiesFor($type)) as $tax) {
            $terms[$tax] ??= [];
        }
        $status = match ($p['status']) {
            'publish', 'future' => 'publish', 'private' => 'private', 'pending' => 'pending', default => 'draft',
        };
        $data = [
            'type' => $type, 'title' => html_entity_decode($p['title'], ENT_QUOTES), 'slug' => $p['slug'] ?: $p['title'],
            'content' => $content, 'excerpt' => trim(html_entity_decode(strip_tags((string)$p['excerpt']), ENT_QUOTES)),
            'status' => $status, 'menu_order' => $p['menu_order'],
            'featured_media_id' => $p['thumbnail'] ? self::map('media', (string)$p['thumbnail']) : (!empty($meta['_thumbnail_id']) ? self::map('media', (string)(int)$meta['_thumbnail_id']) : null),
            'template' => $designed ? null : match ($wpTemplate) { 'elementor_canvas' => 'landing', 'elementor_header_footer', 'page-templates/full-width.php' => 'full-width', default => null },
            'fields' => $fields, 'seo' => $seo, 'terms' => $terms,
            'published_at' => $p['date_gmt'], 'created_at' => $p['date_gmt'] ?? now(), 'updated_at' => $p['modified_gmt'] ?? null,
        ];
        if (($author = $this->authorFor($p)) !== null) {
            $data['author_id'] = $author;
        }
        // A designed page edited in Slate since the last import keeps its edits (texts, images, layout).
        $prev = $existing ? (self::mapExtra('post', $key) ?? []) : [];
        $kept = false;
        if ($designed && $existing && !empty($prev['saved']) && ($cur = Posts::find($existing)) && strtotime((string)$cur['updated_at']) > strtotime((string)$prev['saved']) + 5) {
            $kept = true;
            unset($data['content']);
            $designed = null;
            $this->rep('content', 'kept_edits');
        }
        $hash = $designed ? md5((string)$designed['data']['head'] . (string)$designed['data']['body']) : '';
        $sameDesign = $designed && $existing && ($prev['hash'] ?? '') === $hash && HtmlPages::active((array)Posts::find($existing));
        if ($sameDesign) {
            unset($data['content']); // unchanged on WordPress: no new revision
        }
        $id = Posts::save($data, $existing);
        // Posts::save normalises the slug; keep WordPress's exact slug for URL parity.
        if ($p['slug'] !== '' && Posts::find($id)['slug'] !== $p['slug'] && !DB::val('SELECT id FROM {{posts}} WHERE type = :t AND slug = :s AND id <> :id', ['t' => $type, 's' => $p['slug'], 'id' => $id])) {
            DB::update('posts', ['slug' => $p['slug']], ['id' => $id]);
        }
        if ($designed) {
            if (!$sameDesign) {
                HtmlPages::save($id, $designed['data'] + ['import' => ['source' => 'wordpress', 'url' => $p['link'], 'builder' => $builder, 'at' => now()]]);
            }
            $this->rep('content', 'designed');
        }
        if ($opts['design'] && $html && !$designed && !$kept && ($snap = (new Mirror($this->job['origin']))->snapshot($html))) {
            Mirror::store('single', (string)$id, $snap); // exact rendered template (Elementor Pro single + ACF)
        }
        Posts::flush($id);
        $saved = Posts::find($id);
        self::setMap('post', $key, $id, $p['link'], ['parent' => $p['parent'] ? $wpType . ':' . $p['parent'] : null, 'type' => $type,
            'designed' => (bool)($designed || ($kept && HtmlPages::active((array)$saved))), 'builder' => $builder, 'saved' => (string)($saved['updated_at'] ?? ''),
            'hash' => $designed ? $hash : ($prev['hash'] ?? '')]);
        self::setMap('post_id', (string)$p['old_id'], $id, $p['link']);
        $this->stat($type);
        $this->rep('content', 'imported');
        $title = (string)$saved['title'];
        $url = $status === 'publish' ? Posts::permalink($saved) : '';
        $edit = admin_url('content/' . $type . '/' . $id);
        if ($designed) {
            $this->item('designed', $title, $url, $edit, Builders::LABELS[$builder] . ' page kept with its design; click any text or image on the page to edit it.' . ($notes ? ' ' . implode(' ', $notes) : ''),
                'Review the designed page #' . $id . ' "' . $title . '" that was imported from a ' . Builders::LABELS[$builder] . ' WordPress site. Check that nothing looks broken, add missing image alt texts, and tell me what you changed. Keep the design.');
        } elseif ($kept) {
            $this->item('designed', $title, $url, $edit, 'Edited in Slate since the last import: your changes were kept.');
        } elseif ($notes) {
            $this->item('content', $title, $url, $edit, implode(' ', $notes),
                'The page #' . $id . ' "' . $title . '" was converted from WordPress (' . Builders::LABELS[$builder] . '). ' . implode(' ', $notes) . ' Tidy its content: fix leftovers of the conversion, keep all text, links and images, and tell me what you changed.');
        }
        if ($opts['seo'] && empty($seo['description']) && $status === 'publish' && $this->wantsSeoItem()) {
            $this->item('seo', $title, $url, $edit, 'No meta description was set in WordPress (Slate writes one from the content).',
                'Write an SEO meta description (max 155 characters) for item #' . $id . ' "' . $title . '" and save it in its SEO settings.');
        }
    }

    private int $seoItems = 0;

    private function wantsSeoItem(): bool
    {
        return $this->seoItems++ < 40;
    }

    /** Content for the editor: Gutenberg → clean HTML, shortcodes → Slate equivalents, offline builder pages rebuilt. */
    private function convertContent(array $p, string $builder, ?string $html, array $meta, array &$notes): string
    {
        $o = $this->job['opts'];
        $content = $p['content'];
        if ($this->isWxr() && Builders::isBuilder($builder) && $html && $builder === 'elementor') {
            $content = Mirror::extractElementor($html, (int)$p['old_id']) ?? $content; // 1.x behaviour
        } elseif (!$p['rendered'] && Builders::isBuilder($builder) && !$html) {
            // Site not reachable: rebuild from what WordPress stored.
            if ($builder === 'elementor' && ($data = Builders::elementorData($meta))) {
                $b = new Builders();
                $b->formSlug = fn($pl, $id) => $this->formSlug($pl, $id);
                $b->gallery = fn(array $ids) => $this->galleryHtml($ids);
                $content = $b->elementorHtml($data, (int)$p['old_id']);
                if ($b->skipped) {
                    $notes[] = 'Elementor widgets without an equivalent: ' . implode(', ', array_keys($b->skipped)) . '.';
                }
            }
            $notes[] = 'Rebuilt from the stored ' . Builders::LABELS[$builder] . ' content (the live page could not be fetched), so the design was not kept.';
            $this->rep('content', 'rebuilt_offline');
        }
        if ($o['clean'] && !Builders::isBuilder($builder)) {
            if ($p['rendered']) {
                $content = Blocks::tidy($content);
            } else {
                $b = new Blocks();
                $content = $b->render($content);
                if ($b->dropped) {
                    $notes[] = 'Dynamic blocks that need WordPress were left out: ' . implode(', ', array_keys($b->dropped)) . '.';
                    $this->warn('content', 'Dynamic blocks are not stored in the export file and were left out (connect the live site to keep them): ' . implode(', ', array_keys($b->dropped)));
                }
            }
            $this->rep('content', 'cleaned');
        } elseif (!$p['rendered'] && ($o['clean'] || $o['forms'])) {
            $content = (string)preg_replace('/<!--\s+\/?wp:[^>]*-->/', '', $content);
        }
        if ($o['forms'] && $p['rendered']) {
            $content = FormsMap::replaceRendered($content, fn(string $pl, string $id) => $this->formSlug($pl, $id), static fn(string $slug) => '[form slug="' . $slug . '"]');
        }
        if (!$p['rendered'] && ($o['clean'] || $o['forms'] || Builders::isBuilder($builder))) {
            $sc = new Shortcodes(fn(string $pl, string $id) => $this->formSlug($pl, $id), fn(array $ids) => $this->galleryHtml($ids));
            $content = $sc->convert($content);
            if ($sc->removed) {
                $notes[] = 'Shortcodes removed: ' . implode(', ', array_map(static fn($k, $v) => "[$k]" . ($v > 1 ? " x$v" : ''), array_keys($sc->removed), $sc->removed)) . '.';
            }
            $content = (string)preg_replace_callback('/<!-- wp-image:(\d+) -->/', fn($m) => ($mid = self::map('media', $m[1])) ? '<figure><img src="' . e(Media::url($mid)) . '" alt=""></figure>' : '', $content);
        }
        return $this->rewriteHtml($content);
    }

    /** Gallery markup for WordPress attachment ids (imported media). */
    private function galleryHtml(array $ids): string
    {
        $imgs = '';
        foreach ($ids as $wid) {
            $mid = self::map('media', (string)(int)$wid);
            $m = $mid ? Media::find($mid) : null;
            if ($m) {
                $imgs .= '<figure style="margin:0"><img src="' . e(Media::url($m)) . '" alt="' . e((string)$m['alt']) . '" style="width:100%;height:100%;object-fit:cover;display:block"></figure>';
            }
        }
        return $imgs !== '' ? '<div class="gallery" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px;margin:1.5em 0">' . $imgs . '</div>' : '';
    }

    /** Slate form slug for a form of a WordPress plugin (created on first use); null when forms are not imported. */
    private function formSlug(string $plugin, string $id): ?string
    {
        if (empty($this->job['opts']['forms']) || !FormsMap::available()) {
            return null;
        }
        $log = [];
        $slug = FormsMap::slugFor($plugin, $id, $this->formDefs(), $log);
        foreach ($log as $l) {
            $this->log($l);
            $this->rep('forms', 'imported');
        }
        if ($slug) {
            $this->rep('forms', 'embeds_replaced');
        } else {
            $this->warn('forms', 'A ' . FormsMap::pluginLabel($plugin) . ' form (id ' . $id . ') is used on a page but its definition was not found; it was removed from the page.');
        }
        return $slug;
    }

    /** Form definitions of the source (staged data, CF7 REST), cached in the work folder for the whole run. */
    private function formDefs(): array
    {
        if ($this->formDefs !== null) {
            return $this->formDefs;
        }
        $file = self::WORK . '/forms.json';
        if (is_file($file) && filemtime($file) >= strtotime((string)$this->job['started_at'] . ' UTC')) {
            return $this->formDefs = (array)json_decode((string)file_get_contents($file), true);
        }
        $this->formDefs = FormsMap::all($this->restOrNull());
        file_put_contents($file, json_encode($this->formDefs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $this->formDefs;
    }

    /** All in One SEO keeps per-item SEO in its own table. */
    private function aioseoRow(int $wpId): ?array
    {
        static $rows = null;
        if ($rows === null) {
            $rows = [];
            foreach (Data::rows('table:aioseo_posts') as $r) {
                $rows[(int)($r['post_id'] ?? 0)] = $r;
            }
        }
        return $rows[$wpId] ?? null;
    }

    /** Callbacks used to turn raw ACF values into Slate values. */
    private function acfCallbacks(): array
    {
        return [
            'media' => fn($x) => is_numeric($x) ? self::map('media', (string)(int)$x) : (is_string($x) && $x !== '' ? self::mediaByUrl($this->wpAbs($x)) : null),
            'url' => fn(string $u) => $this->relativeIfOwn($u),
            'html' => fn(string $h) => $this->rewriteHtml($h),
            'term' => fn(string $wpTax, int $id) => self::map('term', $wpTax . ':' . $id),
            'user' => fn(int $id) => (string)(Data::row('user', $id)['display_name'] ?? ''),
        ];
    }

    /** Author of an imported item, by the chosen option (keep = 1.x behaviour). */
    private function authorFor(array $p): ?int
    {
        $mode = (string)($this->job['opts']['authors'] ?? 'keep');
        if ($mode === 'keep') {
            return null;
        }
        $me = Auth::id() ?: ((int)DB::val("SELECT id FROM {{users}} WHERE role = 'administrator' ORDER BY id LIMIT 1") ?: null);
        if ($mode === 'me') {
            return $me;
        }
        $id = $p['author'] ? self::map('user', (string)$p['author']) : null;
        if (!$id && $p['author_login'] !== '') {
            $id = self::map('user', 'login:' . $p['author_login']);
        }
        return $id ?: $me;
    }

    /** Convert ACF / meta values to Slate field values (images → media ids, etc.). */
    private function convertFields(array $defs, array $values): array
    {
        $out = [];
        foreach ($values as $name => $v) {
            $name = preg_replace('/[^a-z0-9_]/', '_', strtolower((string)$name));
            $def = $defs[$name] ?? null;
            if (!$def) {
                continue;
            }
            $out[$name] = $this->convertValue($def, $v);
        }
        return $out;
    }

    private function convertValue(array $def, mixed $v): mixed
    {
        $mediaId = function ($x): ?int {
            if (is_array($x)) {
                $x = $x['ID'] ?? $x['id'] ?? ($x['url'] ?? null);
            }
            if (is_numeric($x)) {
                return self::map('media', (string)(int)$x);
            }
            return is_string($x) && $x !== '' ? self::mediaByUrl($this->wpAbs($x)) : null;
        };
        switch ($def['type']) {
            case 'image':
            case 'file':
                return $mediaId($v);
            case 'gallery':
                return array_values(array_filter(array_map($mediaId, is_array($v) ? $v : explode(',', (string)$v))));
            case 'relation':
                $ids = is_array($v) ? array_map(static fn($x) => is_array($x) ? ($x['ID'] ?? $x['id'] ?? 0) : $x, $v) : explode(',', (string)$v);
                return array_values(array_filter(array_map(static fn($x) => 'old:' . (int)$x, $ids), static fn($x) => $x !== 'old:0'));
            case 'repeater':
                $sub = [];
                foreach ($def['sub_fields'] ?? [] as $sf) {
                    $sub[$sf['name']] = $sf;
                }
                $rows = [];
                foreach ((array)$v as $row) {
                    if (is_array($row)) {
                        $r = [];
                        foreach ($row as $k => $rv) {
                            if (isset($sub[$k])) {
                                $r[$k] = $this->convertValue($sub[$k], $rv);
                            }
                        }
                        $rows[] = $r;
                    }
                }
                return $rows;
            case 'url':
                return $this->relativeIfOwn(is_array($v) ? (string)($v['url'] ?? '') : (string)$v);
            case 'richtext':
                return $this->rewriteHtml((string)$v);
            case 'toggle':
                return (bool)$v;
            case 'number':
                return is_numeric($v) ? $v + 0 : null;
            case 'date':
                $v = (string)$v;
                return preg_match('/^(\d{4})(\d{2})(\d{2})$/', $v, $d) ? "$d[1]-$d[2]-$d[3]" : (preg_match('/^\d{4}-\d{2}-\d{2}/', $v) ? substr($v, 0, 10) : '');
            default:
                return is_scalar($v) ? $v : (is_array($v) ? implode(', ', array_filter($v, 'is_scalar')) : '');
        }
    }

    /** Snapshot archive pages (CPT archives + every term archive) so listings look like the original. */
    private function stepArchives(): bool
    {
        if (!isset($this->job['archive_queue'])) {
            $q = [];
            // The blog listing (WordPress "posts page", e.g. /blog/) usually has its own archive template.
            if (Options::get('front_page') === 'page' && ($pp = (int)Options::get('posts_page_id')) && ($postsPage = Posts::find($pp))) {
                $path = (string)parse_url(Posts::permalink($postsPage), PHP_URL_PATH);
                $q[] = ['archive', 'type-post', $this->job['origin'] . '/' . trim(substr($path, strlen(rtrim((string)parse_url(url('/'), PHP_URL_PATH), '/'))), '/') . '/'];
            }
            foreach (ContentModel::types() as $slug => $t) {
                $base = trim((string)($t['rewrite'] ?? ''), '/');
                if ($slug !== 'page' && $slug !== 'post' && !empty($t['has_archive']) && $base !== '' && !str_contains($base, '%')) {
                    $q[] = ['archive', 'type-' . $slug, $this->job['origin'] . '/' . $base . '/'];
                }
            }
            // The theme's "page not found" template.
            $q[] = ['archive', 'special-404', $this->job['origin'] . '/slate-import-404-check-' . substr(md5($this->job['origin']), 0, 8) . '/'];
            foreach (DB::all("SELECT new_id, old_url, extra FROM {{wpi_map}} WHERE kind = 'term' AND old_url <> ''") as $r) {
                $x = json_decode((string)$r['extra'], true) ?: [];
                $q[] = ['archive', 'term-' . ($x['tax'] ?? 'tax') . '-' . ($x['slug'] ?? $r['new_id']), $r['old_url']];
            }
            $this->job['archive_queue'] = $q;
        }
        $batch = array_splice($this->job['archive_queue'], 0, 16);
        $client = new Fetch($this->job['origin']);
        foreach ($batch as [, $key, $url]) {
            if ($key === 'special-404') {
                $client->keep404[$url] = true;
            }
        }
        $pages = $client->htmlMulti(array_column($batch, 2), 8);
        $mirror = new Mirror($this->job['origin']);
        $mirror->prefetchAssets($pages);
        foreach ($batch as [$bucket, $key, $url]) {
            // A post type whose archive URL is a 404 on WordPress has no archive there: match that.
            if (str_starts_with($key, 'type-') && $key !== 'type-post' && ($client->codes[$url] ?? 0) === 404
                && ($type = ContentModel::type(substr($key, 5))) && !empty($type['has_archive'])) {
                unset($type['builtin']);
                $type['slug'] = substr($key, 5);
                $type['has_archive'] = false;
                ContentModel::saveType($type);
                Mirror::forget($bucket, $key);
                $this->log("“{$type['plural']}” has no archive page on WordPress, so it was switched off here too.");
                continue;
            }
            if (!empty($pages[$url]) && ($snap = $mirror->snapshot($pages[$url]))) {
                Mirror::store($bucket, $key, $snap);
                // Page-specific CSS / JS / body classes of the archive template.
                Mirror::store($bucket, $key . '.assets', (string)json_encode(Mirror::pageFields($pages[$url], $this->job['origin'], $mirror), JSON_UNESCAPED_SLASHES));
                $this->stat('archives');
            }
        }
        return !$this->job['archive_queue'];
    }

    private function stepMenus(): bool
    {
        $menus = [];
        $locations = array_keys(\Slate\Theme::meta()['menus'] ?? ['primary' => 1, 'footer' => 1]);
        if ($this->isWxr()) {
            $locByName = $this->stagedMenuLocations();
            foreach ($this->wxr()['menus'] as $m) {
                $menus[] = ['name' => $m['name'], 'wp_location' => $locByName[$m['name']] ?? $m['name'], 'items' => $m['items']];
            }
        } else {
            $locs = $this->wp()->get('/wp/v2/menu-locations');
            $byMenu = [];
            foreach ($locs['ok'] && is_array($locs['data']) ? $locs['data'] : [] as $loc => $d) {
                if (!empty($d['menu'])) {
                    $byMenu[(int)$d['menu']][] = $loc;
                }
            }
            $list = $this->wp()->get('/wp/v2/menus', ['per_page' => 100]);
            foreach ($list['ok'] && is_array($list['data']) ? $list['data'] : [] as $menu) {
                $items = [];
                $page = 1;
                do {
                    $r = $this->wp()->get('/wp/v2/menu-items', ['menus' => $menu['id'], 'per_page' => 100, 'page' => $page]);
                    foreach ($r['ok'] && is_array($r['data']) ? $r['data'] : [] as $it) {
                        $items[] = ['old_id' => (int)$it['id'], 'title' => html_entity_decode(strip_tags((string)($it['title']['rendered'] ?? '')), ENT_QUOTES),
                            'order' => (int)($it['menu_order'] ?? 0), 'parent' => (int)($it['parent'] ?? 0), 'type' => (string)($it['type'] ?? 'custom'),
                            'object' => (string)($it['object'] ?? ''), 'object_id' => (int)($it['object_id'] ?? 0), 'url' => (string)($it['url'] ?? ''),
                            'target' => (string)($it['target'] ?? '')];
                    }
                    $page++;
                } while ($r['ok'] && $page <= $r['pages']);
                usort($items, static fn($a, $b) => $a['order'] <=> $b['order']);
                $menus[] = ['name' => html_entity_decode((string)$menu['name'], ENT_QUOTES), 'wp_location' => implode(' ', $byMenu[(int)$menu['id']] ?? []), 'items' => $items];
            }
            if (!$list['ok']) {
                $this->log('Menus API not accessible (needs an administrator application password). Rebuilding the main menu from the homepage navigation.');
                $html = $this->wp()->html($this->job['origin'] . '/');
                if ($html && ($items = Mirror::navFromHtml($html))) {
                    $menus[] = ['name' => 'Main menu', 'wp_location' => 'primary', 'items' => $items];
                }
            }
        }
        $out = [];
        $used = [];
        foreach ($menus as $m) {
            $loc = '';
            $wl = strtolower($m['wp_location']);
            foreach (preg_split('/\s+/', $wl) ?: [] as $one) {
                if ($one !== '' && in_array($one, $locations, true) && !isset($used[$one])) {
                    $loc = $one; // same location name in the Slate theme
                    break;
                }
            }
            if ($loc !== '') {
                // matched exactly
            } elseif ((str_contains($wl, 'foot') || ($wl !== '' && str_contains(strtolower($m['name']), 'foot'))) && in_array('footer', $locations, true) && !isset($used['footer'])) {
                $loc = 'footer';
            } elseif ($wl !== '' && in_array('primary', $locations, true) && !isset($used['primary'])) {
                $loc = 'primary';
            }
            if ($loc) {
                $used[$loc] = true;
            }
            $out[] = ['id' => 'wp-' . slugify($m['name']), 'name' => $m['name'], 'location' => $loc, 'items' => $this->menuTree($m['items'], 0, 0)];
            $this->stat('menus');
            $this->rep('menus', 'imported');
            $this->rep('menus', 'items', count($m['items']));
            $this->item('menus', $m['name'], '', admin_url('design/menus'), $loc !== '' ? 'Shown in the “' . $loc . '” place of the theme.' : 'Not assigned to a place in the theme yet' . ($m['wp_location'] !== '' && $m['wp_location'] !== $m['name'] ? ' (was “' . $m['wp_location'] . '” in WordPress).' : '.'));
        }
        if ($out) {
            $existing = array_filter(Menus::all(), static fn($m) => !str_starts_with((string)$m['id'], 'wp-'));
            foreach ($existing as &$e) {
                if (in_array($e['location'], array_column($out, 'location'), true)) {
                    $e['location'] = '';
                }
            }
            unset($e);
            Menus::save(array_merge(array_values($existing), $out));
            $this->log('Imported ' . count($out) . ' menu(s).');
        }
        return true;
    }

    /** WXR exports carry no menu locations: read them from a staged database dump / companion (theme_mods_<theme>). */
    private function stagedMenuLocations(): array
    {
        $theme = (string)(Data::option('stylesheet') ?? '');
        $mods = Data::option('theme_mods_' . $theme);
        $locs = is_array($mods) ? (array)($mods['nav_menu_locations'] ?? []) : [];
        $out = [];
        foreach ($locs as $loc => $termId) {
            $name = json_decode((string)Data::get('term', (int)$termId, 'name'), true)['name'] ?? null;
            if ($name) {
                $out[$name] = trim(($out[$name] ?? '') . ' ' . $loc);
            }
        }
        return $out;
    }

    private function menuTree(array $items, int $parent, int $depth): array
    {
        $out = [];
        foreach ($items as $it) {
            if ((int)$it['parent'] !== $parent || $depth > 3) {
                continue;
            }
            $node = ['label' => $it['title'], 'type' => 'custom', 'url' => $this->relativeIfOwn($it['url']), 'target' => $it['target'] === '_blank' ? '_blank' : '',
                'children' => $it['old_id'] ? $this->menuTree($items, (int)$it['old_id'], $depth + 1) : []];
            if (($it['type'] === 'post_type') && ($pid = self::map('post_id', (string)$it['object_id']))) {
                $node = ['type' => 'post', 'object_id' => $pid] + $node;
            } elseif ($it['type'] === 'post_type_archive' && ($st = $this->slateType((string)$it['object']))) {
                $base = trim((string)(ContentModel::type($st)['rewrite'] ?? ''), '/');
                $node['url'] = url($base !== '' && !str_contains($base, '%') ? $base . '/' : '');
            } elseif ($it['type'] === 'taxonomy' && ($tax = $this->slateTax($it['object']))) {
                $tid = self::map('term', $it['object'] . ':' . $it['object_id']);
                if ($tid) {
                    $node = ['type' => 'term', 'object_id' => $tid] + $node;
                }
            }
            $out[] = $node;
        }
        return $out;
    }

    private function stepFinalize(): bool
    {
        // Post parents + relation fields.
        foreach (DB::all("SELECT new_id, extra FROM {{wpi_map}} WHERE kind = 'post'") as $r) {
            $extra = json_decode((string)$r['extra'], true) ?: [];
            if (!empty($extra['parent']) && ($pid = self::map('post', $extra['parent']))) {
                DB::update('posts', ['parent_id' => $pid], ['id' => (int)$r['new_id']]);
            }
            $post = Posts::find((int)$r['new_id']);
            if ($post) {
                $changed = false;
                array_walk_recursive($post['fields'], static function (&$v) use (&$changed) {
                    if (is_string($v) && str_starts_with($v, 'old:')) {
                        $v = self::map('post_id', substr($v, 4)) ?? 0;
                        $changed = true;
                    }
                });
                if ($changed) {
                    foreach ($post['fields'] as &$f) {
                        if (is_array($f) && array_is_list($f)) {
                            $f = array_values(array_filter($f, static fn($x) => $x !== 0));
                        }
                    }
                    unset($f);
                    DB::update('posts', ['fields' => json_encode($post['fields'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)], ['id' => $post['id']]);
                }
            }
        }
        // Relationship values in term fields.
        foreach (DB::all("SELECT id, fields FROM {{terms}} WHERE fields LIKE '%\"old:%'") as $t) {
            $f = json_decode((string)$t['fields'], true) ?: [];
            array_walk_recursive($f, static function (&$v) {
                if (is_string($v) && str_starts_with($v, 'old:')) {
                    $v = self::map('post_id', substr($v, 4)) ?? 0;
                }
            });
            DB::update('terms', ['fields' => json_encode($f, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)], ['id' => (int)$t['id']]);
        }
        // Term parents.
        foreach (DB::all("SELECT old_id, new_id, extra FROM {{wpi_map}} WHERE kind = 'term'") as $r) {
            $extra = json_decode((string)$r['extra'], true) ?: [];
            $ref = (string)($extra['parent'] ?? '');
            if ($ref === '') {
                continue;
            }
            $pid = str_starts_with($ref, 'id:') ? self::map('term', $extra['wp_tax'] . ':' . substr($ref, 3)) : (Terms::findBySlug($extra['tax'], substr($ref, 5))['id'] ?? null);
            if ($pid && $pid !== (int)$r['new_id']) {
                DB::update('terms', ['parent_id' => $pid], ['id' => (int)$r['new_id']]);
            }
        }
        Terms::recount(DB::col('SELECT id FROM {{terms}}'));
        Posts::flush();
        Terms::flush();

        // Front page, posts page, logo, icon.
        $s = $this->job['wp_settings'];
        if (($s['show_on_front'] ?? '') === 'page' && !empty($s['page_on_front']) && ($fp = self::map('post_id', (string)$s['page_on_front']))) {
            Options::set('front_page', 'page');
            Options::set('front_page_id', $fp);
            Options::set('posts_page_id', !empty($s['page_for_posts']) ? (self::map('post_id', (string)$s['page_for_posts']) ?? 0) : 0);
        } elseif (($s['show_on_front'] ?? '') === 'posts') {
            Options::set('front_page', 'posts');
        } elseif (!isset($s['show_on_front']) && ($home = $this->guessFrontPage())) {
            Options::set('front_page', 'page');
            Options::set('front_page_id', $home);
        }
        foreach (['site_logo' => 'site_logo', 'site_icon' => 'site_icon'] as $wk => $sk) {
            if (!empty($s[$wk]) && ($mid = self::map('media', (string)$s[$wk]))) {
                Options::set($sk, $mid);
            }
        }

        // Permalink structure (from a blog post's URL) and trailing slash.
        $sample = DB::row("SELECT m.old_url, p.* FROM {{wpi_map}} m INNER JOIN {{posts}} p ON p.id = m.new_id WHERE m.kind = 'post' AND p.type = 'post' AND m.old_url <> '' LIMIT 1");
        if ($sample) {
            $path = trim((string)parse_url($sample['old_url'], PHP_URL_PATH), '/');
            $ts = strtotime($sample['published_at'] . ' UTC') ?: time();
            $structure = '/' . strtr($path, [$sample['slug'] => '%slug%', date('Y', $ts) => '%year%', date('m', $ts) => '%monthnum%', date('d', $ts) => '%day%', (string)$sample['id'] => '%id%']) . '/';
            if (str_contains($structure, '%slug%') || str_contains($structure, '%id%')) {
                $presets = ['/%slug%/', '/blog/%slug%/', '/%year%/%monthnum%/%slug%/', '/%year%/%monthnum%/%day%/%slug%/', '/archives/%id%/'];
                if (in_array($structure, $presets, true)) {
                    Options::set('permalink_post', $structure);
                } else {
                    Options::set('permalink_post', 'custom');
                    Options::set('permalink_custom', $structure);
                }
                $this->log('Permalink structure: ' . $structure);
            }
            Options::set('permalink_trailing_slash', str_ends_with((string)parse_url($sample['old_url'], PHP_URL_PATH), '/'));
        }

        // Redirect any URL that changed, so rankings and backlinks are preserved.
        $added = 0;
        if ($this->job['opts']['redirects']) {
            $rules = array_values(array_filter(Options::get('redirects') ?: [], static fn($r) => trim((string)$r['from'], '/') !== ''));
            $existing = array_column($rules, 'from');
            foreach (DB::all("SELECT kind, new_id, old_url FROM {{wpi_map}} WHERE kind IN ('post','term') AND old_url <> ''") as $r) {
                if (str_contains($r['old_url'], '?')) {
                    continue; // drafts / ?p= links are handled by the compatibility layer, never redirect "/"
                }
                $old = (string)parse_url($r['old_url'], PHP_URL_PATH);
                if ($old === '' || $old === '/') {
                    continue;
                }
                if ($r['kind'] === 'post') {
                    $p = Posts::find((int)$r['new_id']);
                    $new = $p ? (string)parse_url(Posts::permalink($p), PHP_URL_PATH) : '';
                } else {
                    $t = Terms::find((int)$r['new_id']);
                    $new = $t ? (string)parse_url(Terms::link($t), PHP_URL_PATH) : '';
                }
                $new = '/' . ltrim(substr($new, strlen(base_path())), '/');
                if ($old && $new && rtrim(rawurldecode($old), '/') !== rtrim($new, '/') && !in_array($old, $existing, true)) {
                    $rules[] = ['from' => $old, 'to' => $new, 'code' => 301, 'regex' => false];
                    $existing[] = $old;
                    $added++;
                }
            }
            Options::set('redirects', $rules);
            $this->rep('redirects', 'changed_urls', $added);
        }
        $changed = $this->changedPaths();
        if (!empty($this->job['opts']['plugin_redirects'])) {
            $this->importPluginRedirects($changed);
        }
        $this->relinkDesigned($changed);
        if (($structure = Options::get('permalink_post')) !== null) {
            $this->job['report']['structure']['permalink'] = $structure === 'custom' ? (string)Options::get('permalink_custom') : (string)$structure;
        }
        if (is_string($wpStructure = Data::option('permalink_structure')) && $wpStructure !== '') {
            $this->job['report']['structure']['wordpress'] = $wpStructure;
            foreach (['category_base' => 'category', 'tag_base' => 'tag'] as $opt => $tax) {
                $base = trim((string)Data::option($opt, ''), '/');
                if ($base !== '' && ($t = ContentModel::taxonomy($tax)) && trim((string)($t['rewrite'] ?? ''), '/') !== $base) {
                    $this->warn('redirects', "WordPress used /$base/ for " . $t['plural'] . " (Slate: /" . trim((string)$t['rewrite'], '/') . '/). Redirects cover the old addresses; change the base under Content model if you prefer the old one.');
                }
            }
        }
        $this->log("Finalized: parents linked, front page set, $added redirect(s) created for changed URLs.");
        PageCache::clear();
        return true;
    }

    /** Old path => new path for every imported item whose address changed. */
    private function changedPaths(): array
    {
        $out = [];
        foreach (DB::all("SELECT kind, new_id, old_url FROM {{wpi_map}} WHERE kind IN ('post','term') AND old_url <> ''") as $r) {
            if (str_contains($r['old_url'], '?')) {
                continue;
            }
            $old = rtrim(rawurldecode((string)parse_url($r['old_url'], PHP_URL_PATH)), '/');
            if ($old === '') {
                continue;
            }
            if ($r['kind'] === 'post') {
                $p = Posts::find((int)$r['new_id']);
                $new = $p ? (string)parse_url(Posts::permalink($p), PHP_URL_PATH) : '';
            } else {
                $t = Terms::find((int)$r['new_id']);
                $new = $t ? (string)parse_url(Terms::link($t), PHP_URL_PATH) : '';
            }
            if ($new !== '' && rtrim($new, '/') !== $old) {
                $out[$old] = $new;
            }
        }
        return $out;
    }

    /** Rank Math / Redirection / Yoast Premium redirect rules → Slate redirects (targets pointed at new addresses). */
    private function importPluginRedirects(array $changed): void
    {
        $rules = array_values(array_filter(Options::get('redirects') ?: [], static fn($r) => trim((string)$r['from'], '/') !== ''));
        $have = array_map(static fn($r) => strtolower(rtrim((string)$r['from'], '/')), $rules);
        $n = 0;
        foreach (SeoMap::pluginRedirects($this->restOrNull()) as $r) {
            $from = (string)$r['from'];
            if (!$r['regex'] && preg_match('#^https?://#', $from)) {
                $from = (string)parse_url($from, PHP_URL_PATH);
            }
            if (!$r['regex']) {
                $from = '/' . ltrim($from, '/');
            }
            $to = $r['code'] === 410 ? '' : $this->relativeIfOwn((string)$r['to']);
            if ($to !== '' && !preg_match('#^https?://#', $to)) {
                $path = '/' . ltrim(substr((string)parse_url($to, PHP_URL_PATH), strlen(base_path())), '/');
                $to = $changed[rtrim($path, '/')] ?? $path;
                // A target that is now the homepage (WordPress redirects /front-page-slug/ to /).
                if ($to === $path && ($slug = basename(rtrim($path, '/'))) !== '' && ($pg = Posts::findBySlug('page', $slug)) && self::isMapped((int)$pg['id'])
                    && (string)parse_url(Posts::permalink($pg), PHP_URL_PATH) !== $path && substr_count(trim($path, '/'), '/') === 0) {
                    $to = (string)parse_url(Posts::permalink($pg), PHP_URL_PATH);
                }
                $to = '/' . ltrim(substr($to, str_starts_with($to, base_path() . '/') ? strlen(base_path()) : 0), '/');
            }
            $mkey = md5(strtolower(rtrim($from, '/')));
            $prev = self::mapExtra('redirect', $mkey);
            if ($prev !== null) {
                // Imported by an earlier run: refresh it, unless someone changed it in Slate since.
                foreach ($rules as $i => $rule) {
                    if (strtolower(rtrim((string)$rule['from'], '/')) === strtolower(rtrim($from, '/')) && (string)$rule['to'] === (string)($prev['to'] ?? '')) {
                        unset($rules[$i]);
                        $have = array_values(array_diff($have, [strtolower(rtrim($from, '/'))]));
                    }
                }
                $rules = array_values($rules);
            }
            if ($from === '/' || in_array(strtolower(rtrim($from, '/')), $have, true) || ($to === '' && $r['code'] !== 410)) {
                $this->rep('redirects', 'plugin_skipped');
                continue;
            }
            self::setMap('redirect', $mkey, 0, $from, ['to' => $to, 'source' => $r['source']]);
            $rules[] = ['from' => $from, 'to' => $to, 'code' => $r['code'], 'regex' => (bool)$r['regex']];
            $have[] = strtolower(rtrim($from, '/'));
            $n++;
            $this->rep('redirects', 'from_' . strtolower(str_replace(' ', '_', $r['source'])));
        }
        if ($n) {
            Options::set('redirects', $rules);
            $this->log("Imported $n redirect rule(s) from WordPress plugins.");
        }
    }

    /** Links inside designed pages that point at old addresses → new addresses (pages imported later than the page itself). */
    private function relinkDesigned(array $changed): void
    {
        if (!$changed) {
            return;
        }
        $base = base_path();
        $map = [];
        foreach ($changed as $old => $new) {
            foreach (['href="' . $base . $old . '/"', 'href="' . $base . $old . '"'] as $from) {
                $map[$from] = 'href="' . $new . '"';
            }
        }
        foreach (DB::all("SELECT new_id, extra FROM {{wpi_map}} WHERE kind = 'post' AND extra LIKE '%\"designed\":true%'") as $r) {
            $post = Posts::find((int)$r['new_id']);
            $d = $post ? HtmlPages::data($post) : null;
            if (!$d) {
                continue;
            }
            $body = strtr((string)$d['body'], $map);
            if ($body !== $d['body']) {
                $post['fields']['_html']['body'] = $body;
                DB::update('posts', ['fields' => json_encode($post['fields'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)], ['id' => (int)$post['id']]);
                Posts::flush((int)$post['id']);
                $this->rep('content', 'relinked');
            }
        }
    }

    private function guessFrontPage(): ?int
    {
        foreach (DB::all("SELECT new_id, old_url FROM {{wpi_map}} WHERE kind = 'post'") as $r) {
            if (rtrim($r['old_url'], '/') === rtrim($this->job['origin'], '/')) {
                return (int)$r['new_id'];
            }
        }
        return null;
    }

    // ------------------------------------------------------------------ steps added in 2.0

    /**
     * Authors. Decision: a WordPress author becomes a Slate user (matched by email first, so existing accounts are reused).
     * New accounts get the WordPress role (administrators become editors), a random password and the status "suspended":
     * nobody can sign in with them until an administrator activates the account and its owner resets the password.
     * Without an email address (public REST API) no account is created and the items go to the person running the import.
     */
    private function stepUsers(): bool
    {
        if (($this->job['opts']['authors'] ?? 'keep') !== 'create') {
            return true;
        }
        $users = Data::rows('user');
        if (!$users && !$this->isWxr()) {
            for ($page = 1; $page < 30; $page++) {
                $r = $this->wp()->get('/wp/v2/users', ['per_page' => 100, 'page' => $page] + ($this->wp()->authenticated() ? ['context' => 'edit'] : []));
                foreach ($r['ok'] && is_array($r['data']) ? $r['data'] : [] as $u) {
                    $users[(string)$u['id']] = ['login' => (string)($u['username'] ?? $u['slug'] ?? ''), 'email' => (string)($u['email'] ?? ''), 'display_name' => (string)($u['name'] ?? ''),
                        'role' => (string)(($u['roles'] ?? [])[0] ?? 'author'), 'url' => (string)($u['url'] ?? ''), 'description' => (string)($u['description'] ?? '')];
                }
                if (!$r['ok'] || $page >= max(1, $r['pages'])) {
                    break;
                }
            }
        }
        $roles = ['administrator' => 'editor', 'editor' => 'editor', 'author' => 'author', 'contributor' => 'contributor'];
        foreach ($users as $wid => $u) {
            $login = strtolower(trim((string)($u['login'] ?? '')));
            $email = strtolower(trim((string)($u['email'] ?? '')));
            if (self::map('user', (string)$wid)) {
                $this->rep('users', 'mapped');
                continue;
            }
            $sid = $email !== '' ? (int)DB::val('SELECT id FROM {{users}} WHERE LOWER(email) = :e', ['e' => $email]) : 0;
            if (!$sid && $email === '') {
                $this->warn('users', 'WordPress did not share e-mail addresses (sign in with an administrator application password to get them), so authors were not created; their items belong to you.');
                $this->rep('users', 'skipped');
                continue;
            }
            if (!$sid) {
                $name = preg_replace('/[^a-z0-9._-]/', '', $login) ?: 'author';
                $base = $name;
                for ($n = 2; DB::val('SELECT id FROM {{users}} WHERE username = :u', ['u' => $name]); $n++) {
                    $name = $base . $n;
                }
                $sid = DB::insert('users', ['username' => mb_substr($name, 0, 60), 'email' => mb_substr($email, 0, 191), 'password' => Auth::hash(bin2hex(random_bytes(24))),
                    'display_name' => mb_substr(trim((string)($u['display_name'] ?? '')) ?: $name, 0, 191), 'role' => $roles[(string)($u['role'] ?? '')] ?? 'subscriber',
                    'status' => 'suspended', 'meta' => json_encode(['bio' => strip_tags((string)($u['description'] ?? '')), 'website' => ContentModel::cleanUrl((string)($u['url'] ?? '')),
                        'imported_from' => 'wordpress']), 'created_at' => now()]);
                $this->rep('users', 'created');
                $this->item('users', (string)($u['display_name'] ?? $name), '', admin_url('users/' . $sid), 'New account (suspended until you activate it; the person then resets the password).');
            } else {
                $this->rep('users', 'matched');
            }
            self::setMap('user', (string)$wid, $sid);
            if ($login !== '') {
                self::setMap('user', 'login:' . $login, $sid);
            }
        }
        $this->log('Authors: ' . count($users) . ' WordPress user(s) checked.');
        return true;
    }

    private function stepForms(): bool
    {
        if (!FormsMap::available()) {
            $this->warn('forms', 'The Forms module is not active, so forms were not imported. Activate it and run the import again.');
            return true;
        }
        $log = [];
        foreach ($this->formDefs() as $f) {
            [$id, $slug, $state] = FormsMap::save($f, $log);
            $this->rep('forms', $state);
            $this->item('forms', (string)$f['title'], '', class_exists(\SlateModules\Forms\Admin::class) && method_exists(\SlateModules\Forms\Admin::class, 'listUrl') ? admin_url('m/form-edit') . '?id=' . $id : admin_url('content/form/' . $id),
                FormsMap::pluginLabel($f['plugin']) . ': ' . count($f['fields']) . ' field(s)' . ($f['notify_to'] !== '' ? ', notifications to ' . $f['notify_to'] : '') . '. Embed it with [form slug="' . $slug . '"].');
        }
        foreach ($log as $l) {
            $this->log($l);
        }
        if (!$this->formDefs()) {
            $this->log('No forms found (Contact Form 7 via REST, or WPForms / Gravity / Fluent / Elementor forms via the companion plugin, export file or database dump).');
        }
        return true;
    }

    private function stepComments(): bool
    {
        if (!class_exists(\SlateModules\Comments\Comments::class)) {
            $this->warn('comments', 'The Comments module is not active, so comments were not imported.');
            return true;
        }
        $rows = Data::rows('comment');
        $done = true;
        if (!$rows && !$this->isWxr()) {
            $q = ['per_page' => 100, 'page' => $this->job['page'], 'orderby' => 'id', 'order' => 'asc'] + ($this->wp()->authenticated() ? ['context' => 'edit'] : []);
            $r = $this->wp()->get('/wp/v2/comments', $q);
            foreach ($r['ok'] && is_array($r['data']) ? $r['data'] : [] as $c) {
                $rows[(string)$c['id']] = ['post_id' => (int)$c['post'], 'parent' => (int)$c['parent'], 'author' => (string)$c['author_name'], 'email' => (string)($c['author_email'] ?? ''),
                    'url' => (string)($c['author_url'] ?? ''), 'date_gmt' => (string)$c['date_gmt'], 'content' => (string)($c['content']['raw'] ?? strip_tags((string)($c['content']['rendered'] ?? ''), '<a><em><strong><p><br>')),
                    'approved' => ($c['status'] ?? 'approved') === 'approved', 'user_id' => (int)($c['author'] ?? 0)];
            }
            $done = !$r['ok'] || $this->job['page'] >= max(1, $r['pages']);
            $this->job['page']++;
        }
        uksort($rows, static fn($a, $b) => (int)$a <=> (int)$b);
        $C = \SlateModules\Comments\Comments::class;
        foreach ($rows as $wid => $c) {
            if (self::map('comment', (string)$wid)) {
                $this->rep('comments', 'already');
                continue;
            }
            $post = self::map('post_id', (string)$c['post_id']);
            if (!$post) {
                $this->rep('comments', 'skipped');
                continue;
            }
            $id = $C::create(['post_id' => $post, 'parent_id' => $c['parent'] ? self::map('comment', (string)$c['parent']) : null, 'author_name' => (string)$c['author'],
                'author_email' => (string)$c['email'], 'author_url' => (string)$c['url'], 'user_id' => $c['user_id'] ? self::map('user', (string)$c['user_id']) : null,
                'content' => trim(strip_tags(html_entity_decode((string)$c['content'], ENT_QUOTES), '<a><em><strong><p><br><code>')), 'status' => !empty($c['approved']) ? 'approved' : 'pending']);
            if (!empty($c['date_gmt']) && !str_starts_with((string)$c['date_gmt'], '0000')) {
                $d = str_replace('T', ' ', substr((string)$c['date_gmt'], 0, 19));
                DB::update('comments', ['created_at' => $d, 'updated_at' => $d], ['id' => $id]);
            }
            self::setMap('comment', (string)$wid, $id);
            $this->rep('comments', 'imported');
        }
        if ($done) {
            PageCache::clear();
        }
        return $done;
    }

    private function stepTemplates(): bool
    {
        foreach (TemplatesMap::collect($this->restOrNull()) as $t) {
            [$id, $state] = TemplatesMap::save($t);
            $this->rep('templates', $state);
            $this->item('templates', 'From WordPress: ' . $t['name'], '', admin_url('templates/' . $id), $t['source'] . ', used for: ' . \Slate\Templates::label($t['kind'], $t['target'])
                . ' (switched off: review it, then switch it on).' . ($t['notes'] ? ' ' . implode(' ', $t['notes']) : ''),
                'Review the template "' . $t['name'] . '" (id ' . $id . ') imported from WordPress. Make it match the rest of the site, keep the {{tags}}, and tell me what you changed. Do not switch it on.');
            $this->log(($state === 'kept' ? 'Kept your edited template' : ucfirst($state) . ' template') . ' “' . $t['name'] . '” (' . $t['kind'] . '/' . $t['target'] . ').');
        }
        foreach (Data::rows('wpost') as $id => $r) {
            if (($r['type'] ?? '') === 'elementor_library') {
                $tt = (string)(Data::decode(Data::meta($id)['_elementor_template_type'] ?? '') ?? '');
                if (in_array($tt, ['header', 'footer', 'popup'], true)) {
                    $this->rep('templates', 'skipped_' . $tt);
                }
            }
        }
        if (!empty($this->job['report']['templates']['counts']['skipped_header']) || !empty($this->job['report']['templates']['counts']['skipped_footer'])) {
            $this->warn('templates', 'Elementor headers and footers are not separate Slate templates: designed pages keep them, and the mirrored theme (if used) shows them on every page.');
        }
        return true;
    }

    private function stepSeoGlobal(): bool
    {
        $typeMap = array_map(static fn($m) => $m['slug'], (array)$this->job['spec']['wp_map']['types']);
        $taxMap = array_map(static fn($m) => $m['slug'], (array)$this->job['spec']['wp_map']['taxonomies']);
        if (!Data::has('option')) {
            $this->warn('seo', 'Global SEO settings (title templates, separator, social profiles) need the companion plugin or a database dump; per-page SEO was imported.');
            return true;
        }
        foreach (SeoMap::applyGlobal(array_filter($typeMap, static fn($s) => ContentModel::type($s) !== null), array_filter($taxMap, static fn($s) => ContentModel::taxonomy($s) !== null)) as $l) {
            $this->log($l);
            $this->rep('seo', 'settings');
        }
        return true;
    }

    // ------------------------------------------------------------------ url helpers

    private function wpAbs(string $url): string
    {
        $url = html_entity_decode(trim($url), ENT_QUOTES);
        if (str_starts_with($url, '//')) {
            return 'https:' . $url;
        }
        return str_starts_with($url, '/') ? $this->job['origin'] . $url : $url;
    }

    /** Turn links to the old site into root-relative links. */
    public function relativeIfOwn(string $url): string
    {
        $hp = static fn(string $u): ?string => ($h = parse_url($u, PHP_URL_HOST)) ? $h . (($p = parse_url($u, PHP_URL_PORT)) ? ':' . $p : '') : null;
        $host = $hp($this->job['origin']);
        $uh = $hp($url);
        if ($uh && $host && (strcasecmp($uh, $host) === 0 || strcasecmp($uh, 'www.' . $host) === 0 || strcasecmp('www.' . $uh, $host) === 0)) {
            $rel = (string)parse_url($url, PHP_URL_PATH);
            $q = parse_url($url, PHP_URL_QUERY);
            $f = parse_url($url, PHP_URL_FRAGMENT);
            return url(ltrim($rel ?: '/', '/')) . ($q ? '?' . $q : '') . ($f ? '#' . $f : '');
        }
        return $url;
    }

    /** Replace imported media URLs with their Slate equivalents and make internal links relative. */
    public function rewriteHtml(string $html): string
    {
        if ($html === '') {
            return '';
        }
        $port = parse_url($this->job['origin'], PHP_URL_PORT);
        $host = preg_quote(preg_replace('/^www\./', '', (string)parse_url($this->job['origin'], PHP_URL_HOST)) . ($port ? ':' . $port : ''), '#');
        // Media URLs → new media.
        $html = preg_replace_callback('#(?:https?:)?//(?:www\.)?' . $host . '(/wp-content/uploads/[^"\'\s\)<>,]+)#i', function ($m) {
            $id = self::mediaByUrl($m[1]);
            return $id ? Media::url($id) : $m[1];
        }, $html);
        // srcset entries pointing at the same image are redundant once optimized.
        $html = preg_replace('#\s(srcset|sizes)="[^"]*content/uploads[^"]*"#i', '', (string)$html);
        // Remaining absolute links to the old site → relative.
        $html = preg_replace('#(href|src|action|data-src|data-bg)=(["\'])(?:https?:)?//(?:www\.)?' . $host . '/?#i', '$1=$2' . base_path() . '/', (string)$html);
        $html = preg_replace('#url\((["\']?)(?:https?:)?//(?:www\.)?' . $host . '/#i', 'url($1' . base_path() . '/', (string)$html);
        return (string)$html;
    }
}
