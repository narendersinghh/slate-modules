<?php
/**
 * WordPress Importer, migrate a WordPress / Elementor site to Slate, keep its design and URLs.
 */
declare(strict_types=1);

use Slate\App;
use Slate\Auth;
use Slate\Crypto;
use Slate\DB;
use Slate\Hooks;
use Slate\Media;
use Slate\Modules;
use Slate\Options;
use Slate\Posts;
use Slate\Router;
use SlateModules\WpImport\Archive;
use SlateModules\WpImport\Companion;
use SlateModules\WpImport\Data;
use SlateModules\WpImport\Fetch;
use SlateModules\WpImport\Importer;
use SlateModules\WpImport\SqlDump;
use SlateModules\WpImport\WxrExtras;

require_once SLATE_MODULES . '/auto-cpt-generator/src/WpClient.php';
require_once SLATE_MODULES . '/auto-cpt-generator/src/Generator.php';
require_once SLATE_MODULES . '/auto-cpt-generator/src/Wxr.php';
foreach (['Fetch', 'Data', 'SqlDump', 'WxrExtras', 'Companion', 'Archive', 'Acf', 'Blocks', 'Shortcodes', 'Builders', 'Loops', 'Designed', 'FormsMap', 'SeoMap',
    'TemplatesMap', 'Analyse', 'Importer', 'Mirror'] as $wpiClass) {
    require_once __DIR__ . '/src/' . $wpiClass . '.php';
}
unset($wpiClass);

Options::lazy('wpi_job', 'wpi_conn', 'wpi_scan');
Hooks::add('module_activated_wordpress-importer', [Importer::class, 'install']);
Hooks::add('module_updated_wordpress-importer', [Importer::class, 'install']);

Modules::adminPage('Migration', 'wp-import', static function (): void {
    Importer::install();
    require __DIR__ . '/view.php';
}, 'manage_settings', 'download');

/** Options of the "Choose" step / command line → job options (unknown keys ignored). */
function wpi_options(array $o): array
{
    $opts = ['types' => array_values(array_map('strval', (array)($o['types'] ?? [])))];
    foreach (['model', 'media', 'seo', 'menus', 'settings', 'redirects', 'design', 'activate_theme', 'drafts', 'designed', 'clean', 'forms', 'templates', 'comments',
                 'design_system', 'seo_global', 'plugin_redirects'] as $k) {
        if (array_key_exists($k, $o)) {
            $opts[$k] = !empty($o[$k]);
        }
    }
    if (isset($o['authors']) && in_array($o['authors'], ['keep', 'create', 'me'], true)) {
        $opts['authors'] = (string)$o['authors'];
    }
    foreach (['model', 'media', 'seo', 'menus', 'settings', 'redirects', 'design', 'activate_theme', 'drafts'] as $k) {
        $opts[$k] ??= false; // 1.x: the form always sends these
    }
    return $opts;
}

/** Store an uploaded extra source (database dump or uploads archive) and stage what it contains. */
function wpi_extra(string $tmp, string $name): array
{
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $isGz = str_ends_with(strtolower($name), '.sql.gz');
    if ($ext === 'sql' || $isGz) {
        $dest = Importer::WORK . '/database.sql' . ($isGz ? '.gz' : '');
        @unlink(Importer::WORK . '/database.sql');
        @unlink(Importer::WORK . '/database.sql.gz');
        is_uploaded_file($tmp) ? move_uploaded_file($tmp, $dest) : copy($tmp, $dest);
        $rows = SqlDump::load($dest);
        @unlink($dest); // staged; the dump itself is not kept
        return ['kind' => 'sql', 'rows' => $rows];
    }
    if ($ext === 'zip') {
        $stats = Archive::extract($tmp);
        return ['kind' => 'uploads', 'stats' => $stats];
    }
    throw new RuntimeException('Upload a database dump (.sql or .sql.gz) or a .zip of wp-content/uploads.');
}

// Form entries of the previous site now live in the Forms module's Submissions inbox.

// ---------------------------------------------------------------- admin endpoints
Hooks::add('admin_routes', static function (Router $r): void {
    $respond = static function (callable $fn): void {
        Auth::require('manage_settings');
        @set_time_limit(300);
        try {
            json_response(['ok' => true] + $fn());
        } catch (Throwable $e) {
            json_response(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    };
    $r->post('/m/wp-import/connect', static fn() => $respond(static function (): array {
        $in = json_input();
        $conn = ['source' => 'rest', 'url' => trim((string)($in['url'] ?? '')), 'user' => trim((string)($in['user'] ?? '')),
            'pass' => Crypto::encrypt(preg_replace('/\s+/', '', (string)($in['password'] ?? '')))];
        if (!filter_var($conn['url'], FILTER_VALIDATE_URL) && !filter_var('https://' . $conn['url'], FILTER_VALIDATE_URL)) {
            throw new RuntimeException('Enter the WordPress site address, e.g. https://example.com');
        }
        $scan = Importer::scan($conn + ['_fresh' => true]);
        $conn['url'] = $scan['summary']['url'];
        Options::set('wpi_conn', $conn, false);
        Options::set('wpi_scan', $scan, false);
        return ['scan' => $scan];
    }));
    // Optional extra sources, added on the Analyse step: a database dump or a .zip of wp-content/uploads.
    $r->post('/m/wp-import/extra', static fn() => $respond(static function (): array {
        $conn = Options::get('wpi_conn');
        $scan = Options::get('wpi_scan');
        if (!$conn || !$scan) {
            throw new RuntimeException('Connect to a site or upload its export file first.');
        }
        $f = $_FILES['file'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            throw new RuntimeException('The file did not arrive. If it is large, raise upload_max_filesize and post_max_size, or use the command line (--sql=, --uploads=).');
        }
        Importer::install();
        @set_time_limit(900);
        $res = wpi_extra($f['tmp_name'], (string)$f['name']);
        $scan = Importer::reanalyse($conn, $scan);
        Options::set('wpi_scan', $scan, false);
        return ['added' => $res, 'scan' => $scan];
    }));
    $r->get('/m/wp-import/companion.zip', static function (): void {
        Auth::require('manage_settings');
        $file = Companion::zip();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="slate-export.zip"');
        header('Content-Length: ' . filesize($file));
        header('X-Content-Type-Options: nosniff');
        readfile($file);
        exit;
    });
    $r->post('/m/wp-import/upload', static fn() => $respond(static function (): array {
        $f = $_FILES['file'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            throw new RuntimeException('Upload the WordPress export (.xml) file. If it is large, raise upload_max_filesize in PHP settings.');
        }
        Importer::install();
        move_uploaded_file($f['tmp_name'], Importer::WORK . '/export.xml');
        @unlink(Importer::WORK . '/wxr.json');
        $conn = ['source' => 'wxr', 'url' => ''];
        $scan = Importer::scan($conn + ['_fresh' => true]);
        $conn['url'] = $scan['summary']['url'];
        Options::set('wpi_conn', $conn, false);
        Options::set('wpi_scan', $scan, false);
        return ['scan' => $scan];
    }));
    $r->post('/m/wp-import/start', static fn() => $respond(static function (): array {
        $conn = Options::get('wpi_conn');
        $scan = Options::get('wpi_scan');
        if (!$conn || !$scan) {
            throw new RuntimeException('Connect to a site first.');
        }
        $o = (array)(json_input()['options'] ?? []);
        $opts = wpi_options($o);
        if (!empty($o['only'])) {
            $opts['only'] = array_values(array_map('strval', (array)$o['only']));
        }
        $job = Importer::start($conn, $scan, $opts);
        log_activity('wp_import_started', $job['origin']);
        return ['steps' => count($job['steps'])];
    }));
    $r->post('/m/wp-import/step', static fn() => $respond(static fn(): array => Importer::step()));
    $r->post('/m/wp-import/reset', static fn() => $respond(static function (): array {
        Importer::reset(!empty(json_input()['delete']));
        return [];
    }));
});

// ---------------------------------------------------------------- frontend compatibility layer
Hooks::add('init', static function (): void {
    $path = App::path();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    // Form posts from the previous site's markup → Submissions inbox (+ email).
    if ($path === '/wp-admin/admin-ajax.php') {
        wpi_handle_ajax();
    }

    // Old WordPress media & asset URLs.
    if (preg_match('#^/(wp-content|wp-includes)/#', $path)) {
        wpi_serve_asset($path);
    }

    // ?post_type=books links from the old site → that type's listing.
    if ($method === 'GET' && $path === '/' && isset($_GET['post_type']) && is_string($_GET['post_type'])) {
        $type = \Slate\ContentModel::type(preg_replace('/[^a-z0-9_-]/', '', $_GET['post_type']));
        $base = trim((string)($type['rewrite'] ?? ''), '/');
        if ($type && !empty($type['public'])) {
            if (!empty($type['has_archive']) && $base !== '' && !str_contains($base, '%')) {
                redirect(url($base . '/'), 301);
            }
            if ($page = Posts::findBySlug('page', (string)$type['slug'])) {
                redirect(Posts::permalink($page), 301);
            }
        }
    }

    // ?p=123 / ?page_id=123 links from the old site.
    if ($method === 'GET' && $path === '/' && (isset($_GET['p']) || isset($_GET['page_id']))) {
        $id = Importer::map('post_id', (string)(int)($_GET['p'] ?? $_GET['page_id']));
        if ($id && ($post = Posts::find($id))) {
            redirect(Posts::permalink($post), 301);
        }
    }
}, 1);

function wpi_handle_ajax(): never
{
    $action = (string)($_POST['action'] ?? $_GET['action'] ?? '');
    header('Content-Type: application/json; charset=utf-8');
    // Form actions of the previous site's page builder (original and renamed identifiers).
    if (!preg_match('/^(elementor|slatekit)_pro_(atomic_)?forms_send_form$/', $action)) {
        echo json_encode(['success' => false, 'data' => ['message' => 'Unsupported action.']]);
        exit;
    }
    $ok = ['success' => true, 'data' => ['message' => 'Your submission was successful.', 'data' => []]];
    $fields = (array)($_POST['form_fields'] ?? []);
    if (!empty($fields['honeypot']) || !empty($_POST['website_url_hp'])) {
        echo json_encode($ok);
        exit;
    }
    $data = [];
    foreach ($fields as $k => $v) {
        $key = mb_substr(preg_replace('/[^\w-]/', '', (string)$k), 0, 60);
        $val = is_array($v) ? array_map(static fn($x) => mb_substr(strip_tags((string)$x), 0, 5000), $v) : mb_substr(strip_tags((string)$v), 0, 5000);
        $data[$key] = ['label' => ucfirst(str_replace(['_', '-'], ' ', $key)), 'value' => $val, 'type' => filter_var(is_string($val) ? $val : '', FILTER_VALIDATE_EMAIL) ? 'email' : 'text'];
    }
    $formName = mb_substr(strip_tags((string)($_POST['form_name'] ?? $_POST['form_id'] ?? 'Form')), 0, 191);
    if (!class_exists(\SlateModules\Forms\Forms::class)) {
        echo json_encode(['success' => false, 'data' => ['message' => 'Forms are not enabled on this site.']]);
        exit;
    }
    $Forms = \SlateModules\Forms\Forms::class;
    $Forms::install();
    $recent = (int)DB::val('SELECT COUNT(*) FROM {{form_submissions}} WHERE ip = :ip AND created_at > :t', ['ip' => Auth::ip(), 't' => gmdate('Y-m-d H:i:s', time() - 3600)]);
    if ($recent >= 15) {
        echo json_encode($ok);
        exit;
    }
    $form = $Forms::find(slugify($formName)) ?? null;
    $page = \Slate\ContentModel::cleanUrl((string)($_POST['referrer'] ?? $_SERVER['HTTP_REFERER'] ?? ''));
    $id = $Forms::store($form, $data, $page, mb_substr(strip_tags((string)($_POST['referer_title'] ?? '')), 0, 255), 'new', 'legacy', null, null, null, $form['title'] ?? $formName);
    if ($form) {
        $Forms::notify($form, $data, $page, $id);
    }
    echo json_encode($ok);
    exit;
}

function wpi_serve_asset(string $path): void
{
    $file = SLATE_ROOT . $path;
    if (str_contains($path, '..') || preg_match('/\.(php\d?|phtml|phar|sh|py|pl|cgi|htaccess)$/i', $path)) {
        http_response_code(404);
        exit;
    }
    // Imported media → its optimized Slate copy (permanent redirect keeps image SEO).
    if (str_starts_with($path, '/wp-content/uploads/') && !str_contains($path, '/uploads/elementor/')) {
        $id = Importer::mediaByUrl($path);
        if ($id && ($m = Media::find($id))) {
            redirect(Media::url($m), 301);
        }
    }
    if (is_file($file)) {
        return; // the web server normally serves it directly
    }
    // Lazy mirror: fetch missing theme/plugin assets from the original site while it is still reachable.
    $origin = (string)Options::get('wpi_origin');
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $allowed = ['css', 'js', 'woff', 'woff2', 'ttf', 'otf', 'eot', 'svg', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'ico', 'json', 'map', 'mp4', 'webm'];
    $self = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($origin && in_array($ext, $allowed, true) && strcasecmp((string)parse_url($origin, PHP_URL_HOST), $self) !== 0) {
        @mkdir(dirname($file), 0755, true);
        if ((new Fetch($origin))->download($origin . $path, $file . '.part', 20 * 1024 * 1024)) {
            rename($file . '.part', $file);
            $types = ['css' => 'text/css', 'js' => 'application/javascript', 'svg' => 'image/svg+xml', 'woff2' => 'font/woff2', 'woff' => 'font/woff', 'json' => 'application/json'];
            header('Content-Type: ' . ($types[$ext] ?? (mime_content_type($file) ?: 'application/octet-stream')));
            header('Cache-Control: public, max-age=31536000');
            readfile($file);
            exit;
        }
    }
    http_response_code(404);
    exit;
}

// ---------------------------------------------------------------- command line
// php bin/slate import:wordpress --url=https://example.com --user=admin [--password=APP_PASSWORD]
//      [--no-design] [--no-theme] [--no-media] [--no-drafts] [--types=post,page,...] [--fresh]
//      [--wxr=export.xml] [--sql=database.sql(.gz)] [--uploads=uploads.zip]
//      [--everything] or any of: [--designed] [--clean] [--forms] [--templates] [--comments] [--authors=create|me|keep]
//      [--design-system] [--seo-global] [--plugin-redirects]
// Without the 2.0 flags the command behaves exactly like 1.x (what an existing migration expects when it syncs).
Hooks::add('cli_commands', static function (array $commands): array {
    $commands['import:wordpress'] = ['Import a WordPress site (--url=https://example.com --user=admin)',
        static function (array $opts, string $arg, callable $out, callable $fail): void {
            $url = (string)($opts['url'] ?? '');
            $user = (string)($opts['user'] ?? '');
            $pass = (string)($opts['password'] ?? (getenv('WP_APP_PASSWORD') ?: ''));
            $wxrFile = (string)($opts['wxr'] ?? '');
            if ($url === '' && $wxrFile === '') {
                $fail('Missing --url=https://your-wordpress-site.com (or --wxr=export.xml)');
            }
            if ($user !== '' && $pass === '') {
                fwrite(STDOUT, 'WordPress application password for ' . $user . ': ');
                if (DIRECTORY_SEPARATOR === '/') {
                    @shell_exec('stty -echo 2>/dev/null');
                }
                $pass = trim((string)fgets(STDIN));
                if (DIRECTORY_SEPARATOR === '/') {
                    @shell_exec('stty echo 2>/dev/null');
                }
                fwrite(STDOUT, PHP_EOL);
            }
            Importer::install();
            if (!empty($opts['fresh'])) {
                Importer::reset(true);
                $out('✓ Removed previously imported content (--fresh)');
            }
            @set_time_limit(0);
            if ($wxrFile !== '') {
                if (!is_file($wxrFile)) {
                    $fail("Export file not found: $wxrFile");
                }
                copy($wxrFile, Importer::WORK . '/export.xml');
                @unlink(Importer::WORK . '/wxr.json');
                $conn = ['source' => 'wxr', 'url' => ''];
            } else {
                $conn = ['source' => 'rest', 'url' => $url, 'user' => $user, 'pass' => Crypto::encrypt(preg_replace('/\s+/', '', $pass))];
            }
            $out('Analysing ' . ($wxrFile ?: $url) . ' ...');
            try {
                $scan = Importer::scan($conn + ['_fresh' => true]);
                foreach (['sql' => 'database dump', 'uploads' => 'uploads archive'] as $flag => $label) {
                    if (!empty($opts[$flag])) {
                        if (!is_file((string)$opts[$flag])) {
                            $fail("File not found: {$opts[$flag]}");
                        }
                        $res = wpi_extra((string)$opts[$flag], basename((string)$opts[$flag]));
                        $out("  Added the $label: " . json_encode($res['rows'] ?? $res['stats'] ?? []));
                    }
                }
                if (!empty($opts['sql']) || !empty($opts['uploads'])) {
                    $scan = Importer::reanalyse($conn, $scan);
                }
            } catch (Throwable $e) {
                $fail($e->getMessage());
            }
            $s = $scan['summary'];
            $conn['url'] = $s['url'];
            $out('  Site: ' . $s['site'] . ($s['auth'] ? '  (signed in as ' . $s['user'] . ')' : '  (public access only, drafts, menus and settings need an application password)'));
            foreach ($scan['counts']['types'] ?? [] as $wp => $c) {
                $out(sprintf('  %-22s %5d  → %s', $wp, $c['total'], $c['slug']));
            }
            $out(sprintf('  %-22s %5s', 'media', (string)($s['media'] ?? '?')));
            $out('  Detected: ' . (implode(', ', array_filter([$s['elementor'] ? 'Elementor' : null, $s['seo'] ?: null, $s['acf'] ? 'ACF' : null])) ?: '-'));
            Options::set('wpi_conn', $conn, false);
            Options::set('wpi_scan', $scan, false);
            $types = !empty($opts['types']) ? array_map('trim', explode(',', (string)$opts['types'])) : array_keys($scan['counts']['types'] ?? []);
            $all = !empty($opts['everything']);
            $new = [];
            foreach (['designed', 'clean', 'forms', 'templates', 'comments', 'design-system', 'seo-global', 'plugin-redirects'] as $f) {
                if ($all || !empty($opts[$f])) {
                    $new[str_replace('-', '_', $f)] = true;
                }
            }
            if ($all || !empty($opts['authors'])) {
                $new['authors'] = in_array($opts['authors'] ?? '', ['create', 'me', 'keep'], true) ? (string)$opts['authors'] : 'create';
            }
            $job = Importer::start($conn, $scan, [
                'types' => $types, 'model' => true, 'media' => empty($opts['no-media']), 'seo' => true, 'menus' => true, 'settings' => true,
                'redirects' => true, 'design' => empty($opts['no-design']), 'activate_theme' => empty($opts['no-theme']) && empty($opts['no-design']),
                'drafts' => empty($opts['no-drafts']),
            ] + $new);
            $out('→ Importing (' . count($job['steps']) . ' steps) …');
            $shown = 0;
            do {
                try {
                    $r = Importer::step();
                } catch (Throwable $e) {
                    $fail($e->getMessage());
                }
                $all = Importer::job()['log'] ?? [];
                foreach (array_slice($all, $shown) as $line) {
                    $out('  ' . $line);
                }
                $shown = count($all);
                fwrite(STDOUT, sprintf("\r  [%3d%%] %-60s", $r['progress'], mb_substr($r['step'], 0, 60)));
            } while (!$r['done']);
            $out(PHP_EOL . '✓ Import finished: ' . json_encode($r['stats']));
            $out('  Open ' . site_url() . ' to review. Re-run this command any time to sync changes (it updates, never duplicates).');
        }];
    return $commands;
});
