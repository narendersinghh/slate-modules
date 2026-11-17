<?php
declare(strict_types=1);

namespace SlateModules\Forms;

use Slate\Auth;
use Slate\DB;
use Slate\Hooks;
use Slate\Options;
use Slate\Posts;

/**
 * Forms: a "form" content type whose custom fields describe the form (fields + settings), the submissions store,
 * notification emails and the helpers shared by the builder, the renderer and the submit handler.
 *
 * Storage (post "fields" JSON) is the same as in 1.x, extended with optional keys, so 1.x forms keep working:
 *   form_fields: [{id, label, type, name, placeholder, required, width, options, help,
 *                  default, min, max, pattern, pattern_msg, accept, max_size, cond: {field, op, value, action}}]
 *   submit_label, success_message, error_message, note, redirect_url, notify_to, email_subject, style, css_class,
 *   confirm_enabled, confirm_subject, confirm_message, max_entries, close_at, closed_message
 */
final class Forms
{
    public const TYPE = 'form';
    public const ENDPOINT = '/_slate/form';
    public const VERSION_DB = 3;

    /** Field types: key => label. Order is the palette order in the builder. */
    public const FIELD_TYPES = [
        'text' => 'Short text', 'email' => 'Email', 'tel' => 'Phone', 'number' => 'Number', 'textarea' => 'Long text',
        'select' => 'Dropdown', 'radio' => 'One choice', 'checkbox' => 'Checkboxes', 'date' => 'Date', 'time' => 'Time', 'url' => 'Website',
        'file' => 'File upload', 'consent' => 'Consent', 'hidden' => 'Hidden', 'heading' => 'Heading', 'html' => 'Paragraph', 'step' => 'Page break',
    ];
    /** Types that only show something; they collect no value. */
    public const DISPLAY_TYPES = ['html', 'heading', 'step'];
    public const CHOICE_TYPES = ['select', 'radio', 'checkbox'];
    public const STATUSES = ['new' => 'New', 'read' => 'Read', 'spam' => 'Spam'];
    public const DEFAULT_UPLOAD_TYPES = 'jpg, jpeg, png, gif, webp, pdf, doc, docx, xls, xlsx, ppt, pptx, odt, ods, txt, csv, zip';
    /** Never accepted, whatever a form allows. */
    public const BLOCKED_EXT = ['php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'pht', 'phps', 'cgi', 'pl', 'py', 'sh', 'bash', 'exe', 'dll',
        'bat', 'cmd', 'com', 'msi', 'js', 'mjs', 'jsp', 'asp', 'aspx', 'html', 'htm', 'xhtml', 'shtml', 'svg', 'svgz', 'xml', 'htaccess', 'htpasswd', 'ini',
        'jar', 'vbs', 'ps1', 'scr', 'hta', 'swf'];

    // ------------------------------------------------------------------ setup

    /** Create / upgrade the submissions table. Cheap after the first run (one option read). */
    public static function install(): void
    {
        $v = (int)Options::get('forms_db', 0);
        if ($v >= self::VERSION_DB) {
            return;
        }
        $sqlite = DB::$driver === 'sqlite';
        if ($v < 1) {
            $pk = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
            $int = $sqlite ? 'INTEGER' : 'INT UNSIGNED';
            $text = $sqlite ? 'TEXT' : 'LONGTEXT';
            $tail = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
            DB::exec("CREATE TABLE IF NOT EXISTS {{form_submissions}} (
                id $pk,
                form_id $int NULL,
                form_title VARCHAR(191) NOT NULL DEFAULT '',
                page_url VARCHAR(700) NOT NULL DEFAULT '',
                page_title VARCHAR(255) NOT NULL DEFAULT '',
                data $text NULL,
                ip VARCHAR(45) NOT NULL DEFAULT '',
                user_agent VARCHAR(255) NOT NULL DEFAULT '',
                status VARCHAR(20) NOT NULL DEFAULT 'new',
                source VARCHAR(40) NOT NULL DEFAULT 'slate',
                created_at DATETIME NOT NULL
            )$tail");
            foreach (['CREATE INDEX idx_fs_form ON {{form_submissions}} (form_id, created_at)', 'CREATE INDEX idx_fs_status ON {{form_submissions}} (status, created_at)'] as $sql) {
                try {
                    DB::exec($sql);
                } catch (\PDOException) {
                }
            }
        }
        // 2.0: a "starred" flag. Added, never dropped; old code simply ignores it.
        try {
            DB::exec('ALTER TABLE {{form_submissions}} ADD COLUMN starred ' . ($sqlite ? 'INTEGER' : 'TINYINT(1)') . ' NOT NULL DEFAULT 0');
        } catch (\PDOException) {
            // already there
        }
        // 2.2: queue and log of the form actions (webhooks and chat messages).
        $pk = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
        $int = $sqlite ? 'INTEGER' : 'INT UNSIGNED';
        $text = $sqlite ? 'TEXT' : 'MEDIUMTEXT';
        $tail = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        DB::exec("CREATE TABLE IF NOT EXISTS {{form_deliveries}} (
            id $pk,
            form_id $int NOT NULL,
            submission_id $int NULL,
            action_id VARCHAR(40) NOT NULL DEFAULT '',
            kind VARCHAR(20) NOT NULL DEFAULT 'webhook',
            url VARCHAR(1000) NOT NULL DEFAULT '',
            payload $text NULL,
            content_type VARCHAR(80) NOT NULL DEFAULT 'application/json',
            event VARCHAR(20) NOT NULL DEFAULT 'entry',
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            attempts $int NOT NULL DEFAULT 0,
            response_code $int NULL,
            response VARCHAR(500) NOT NULL DEFAULT '',
            next_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL
        )$tail");
        foreach (['CREATE INDEX idx_fd_form ON {{form_deliveries}} (form_id, id)', 'CREATE INDEX idx_fd_due ON {{form_deliveries}} (status, next_at)'] as $sql) {
            try {
                DB::exec($sql);
            } catch (\PDOException) {
            }
        }
        Options::set('forms_db', self::VERSION_DB);
    }

    public static function postType(): array
    {
        // The dashboard screens live under Modules > Forms (list, builder, entries), so the type is not in Content.
        return ['slug' => self::TYPE, 'singular' => 'Form', 'plural' => 'Forms', 'icon' => 'message', 'public' => false, 'has_archive' => false,
            'hierarchical' => false, 'show_in_menu' => false, 'rewrite' => '', 'menu_position' => 85, 'supports' => ['title'],
            'description' => 'Forms you can place on any page.', 'builtin' => true];
    }

    /**
     * Field groups that describe the stored format (used by the API, AI tools and the generic editor).
     * People build forms in the visual builder (Modules > Forms).
     */
    public static function fieldGroups(): array
    {
        $types = array_map(static fn($k, $l) => "$k : $l", array_keys(self::FIELD_TYPES), self::FIELD_TYPES);
        return [
            'slate_form_fields' => ['id' => 'slate_form_fields', 'title' => 'Form fields', 'position' => 'main', 'system' => true,
                'location' => ['post_types' => [self::TYPE], 'taxonomies' => []],
                'fields' => [[
                    'name' => 'form_fields', 'label' => 'Fields', 'type' => 'repeater', 'width' => '100', 'options' => [], 'post_type' => '',
                    'help' => 'Edit forms in the form builder (Modules › Forms). One row per field, in order.',
                    'sub_fields' => [
                        ['name' => 'label', 'label' => 'Label', 'type' => 'text'],
                        ['name' => 'type', 'label' => 'Type', 'type' => 'select', 'options' => $types],
                        ['name' => 'name', 'label' => 'Key', 'type' => 'text'],
                        ['name' => 'placeholder', 'label' => 'Placeholder', 'type' => 'text'],
                        ['name' => 'required', 'label' => 'Required', 'type' => 'toggle'],
                        ['name' => 'width', 'label' => 'Width', 'type' => 'select', 'options' => ['100 : Full width', '50 : Half', '33 : One third', '66 : Two thirds']],
                        ['name' => 'options', 'label' => 'Choices (one per line, value : Label) / text', 'type' => 'textarea'],
                        ['name' => 'help', 'label' => 'Help text', 'type' => 'text'],
                        ['name' => 'default', 'label' => 'Default value', 'type' => 'text'],
                        ['name' => 'min', 'label' => 'Minimum', 'type' => 'text'],
                        ['name' => 'max', 'label' => 'Maximum', 'type' => 'text'],
                        ['name' => 'pattern', 'label' => 'Pattern', 'type' => 'text'],
                    ],
                ]],
            ],
            'slate_form_settings' => ['id' => 'slate_form_settings', 'title' => 'Form settings', 'position' => 'side', 'system' => true,
                'location' => ['post_types' => [self::TYPE], 'taxonomies' => []],
                'fields' => [
                    ['name' => 'submit_label', 'label' => 'Button text', 'type' => 'text', 'width' => '100'],
                    ['name' => 'success_message', 'label' => 'Success message', 'type' => 'textarea', 'width' => '100'],
                    ['name' => 'error_message', 'label' => 'Error message', 'type' => 'textarea', 'width' => '100'],
                    ['name' => 'note', 'label' => 'Small print under the button', 'type' => 'textarea', 'width' => '100'],
                    ['name' => 'redirect_url', 'label' => 'Redirect after sending', 'type' => 'url', 'width' => '100'],
                    ['name' => 'notify_to', 'label' => 'Email notifications to', 'type' => 'text', 'width' => '100'],
                    ['name' => 'email_subject', 'label' => 'Email subject', 'type' => 'text', 'width' => '100'],
                    ['name' => 'style', 'label' => 'Look', 'type' => 'select', 'width' => '100', 'options' => ['slate : Slate default styles', 'theme : Use the website’s own styles']],
                    ['name' => 'css_class', 'label' => 'Extra CSS classes', 'type' => 'text', 'width' => '100'],
                ],
            ],
        ];
    }

    // ------------------------------------------------------------------ lookup

    public static function find(string|int $ref, bool $published = true): ?array
    {
        $ref = trim((string)$ref);
        if ($ref === '') {
            return null;
        }
        $form = ctype_digit($ref) ? Posts::find((int)$ref) : Posts::findBySlug(self::TYPE, $ref, $published);
        if (!$form || $form['type'] !== self::TYPE) {
            return null;
        }
        return !$published || $form['status'] === 'publish' ? $form : null;
    }

    /** Builder URL of a form (or of a new one). */
    public static function editUrl(?int $id = null): string
    {
        return admin_url('m/form-edit') . ($id ? '?id=' . $id : '');
    }

    /** Key as 1.x derived it (kept bug for bug: the label is not lower-cased first), so stored entries keep matching. */
    private static function legacyKey(array $row, int $i): string
    {
        $label = trim((string)($row['label'] ?? ''));
        $key = strtolower(trim((string)($row['name'] ?? '')));
        $key = preg_replace('/[^a-z0-9_-]+/', '_', $key !== '' ? $key : ($label !== '' ? $label : 'field_' . ($i + 1)));
        return trim((string)$key, '_') ?: 'field_' . ($i + 1);
    }

    /** A clean key for a new field ("Your name" => "your_name"). */
    public static function keyFor(string $label, array $taken = []): string
    {
        $key = trim((string)preg_replace('/[^a-z0-9_]+/', '_', strtolower(slugify($label, '_'))), '_');
        $key = $key === '' ? 'field' : substr($key, 0, 40);
        $base = $key;
        for ($n = 2; in_array($key, $taken, true); $n++) {
            $key = $base . '_' . $n;
        }
        return $key;
    }

    /** "value : Label" lines => [value => label]. */
    public static function parseChoices(string $text): array
    {
        $choices = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            [$v, $l] = str_contains($line, ':') ? array_map('trim', explode(':', $line, 2)) : [$line, $line];
            $choices[$v !== '' ? $v : $l] = $l !== '' ? $l : $v;
        }
        return $choices;
    }

    /** Normalised field list of a form (1.x rows and 2.x rows alike). */
    public static function fields(array $form): array
    {
        $out = [];
        $used = [];
        foreach (array_values((array)($form['fields']['form_fields'] ?? [])) as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            $type = (string)($row['type'] ?? '');
            $type = isset(self::FIELD_TYPES[$type]) ? $type : 'text';
            $key = self::legacyKey($row, $i);
            while (isset($used[$key])) {
                $key .= '_' . ($i + 1);
            }
            $used[$key] = true;
            $label = trim((string)($row['label'] ?? ''));
            $width = (string)($row['width'] ?? '');
            $cond = is_array($row['cond'] ?? null) && trim((string)($row['cond']['field'] ?? '')) !== '' ? [
                'field' => (string)$row['cond']['field'],
                'op' => in_array($row['cond']['op'] ?? '', ['is', 'not', 'contains', 'empty', 'filled'], true) ? (string)$row['cond']['op'] : 'is',
                'value' => (string)($row['cond']['value'] ?? ''),
                'action' => ($row['cond']['action'] ?? 'show') === 'hide' ? 'hide' : 'show',
            ] : null;
            $accept = array_values(array_filter(array_map(static fn($x) => strtolower(trim($x, " .\t")), explode(',', (string)($row['accept'] ?? '')))));
            $out[] = [
                'id' => (string)($row['id'] ?? '') ?: 'f' . $i,
                'key' => $key, 'label' => $label, 'type' => $type,
                'placeholder' => (string)($row['placeholder'] ?? ''),
                'required' => !empty($row['required']) && !in_array($type, self::DISPLAY_TYPES, true),
                'width' => in_array($width, ['50', '33', '66'], true) ? $width : '100',
                'choices' => in_array($type, self::CHOICE_TYPES, true) ? self::parseChoices((string)($row['options'] ?? '')) : [],
                'text' => (string)($row['options'] ?? ''), 'help' => (string)($row['help'] ?? ''),
                'default' => (string)($row['default'] ?? ''),
                'min' => trim((string)($row['min'] ?? '')), 'max' => trim((string)($row['max'] ?? '')),
                'pattern' => (string)($row['pattern'] ?? ''), 'pattern_msg' => (string)($row['pattern_msg'] ?? ''),
                'accept' => $accept, 'max_size' => max(0, (float)($row['max_size'] ?? 0)),
                'cond' => $cond,
            ];
        }
        return $out;
    }

    /** Form settings with defaults. */
    public static function settings(array $form): array
    {
        $f = (array)($form['fields'] ?? []);
        $s = static fn(string $k, string $d = ''): string => trim((string)($f[$k] ?? '')) !== '' ? trim((string)$f[$k]) : $d;
        return [
            'submit_label' => $s('submit_label', 'Send'),
            'success_message' => $s('success_message', 'Thanks! Your message has been sent.'),
            'error_message' => $s('error_message', 'Sorry, that did not go through. Please check the form and try again.'),
            'note' => $s('note'),
            'redirect_url' => $s('redirect_url'),
            'notify_to' => $s('notify_to'),
            'email_subject' => $s('email_subject', 'New entry: {form}'),
            'style' => ($f['style'] ?? '') === 'theme' ? 'theme' : 'slate',
            'css_class' => $s('css_class'),
            'confirm_enabled' => !empty($f['confirm_enabled']),
            'confirm_subject' => $s('confirm_subject', 'We received your message'),
            'confirm_message' => $s('confirm_message', "Hi,\n\nThanks for getting in touch. We received your message and will get back to you soon.\n\n{all_fields}\n\n{site}"),
            'max_entries' => max(0, (int)($f['max_entries'] ?? 0)),
            'close_at' => $s('close_at'),
            'closed_message' => $s('closed_message', 'This form is closed and no longer accepts entries.'),
            'next_label' => $s('next_label', 'Next'),
            'back_label' => $s('back_label', 'Back'),
            // 2.2: actions and spam checks. Entries are saved unless a form says otherwise.
            'store_entries' => !array_key_exists('store_entries', $f) || ($f['store_entries'] !== '0' && $f['store_entries'] !== false && $f['store_entries'] !== 0),
            'captcha' => isset(Captcha::PROVIDERS[(string)($f['captcha'] ?? '')]) ? (string)($f['captcha'] ?? '') : '',
            'captcha_question' => $s('captcha_question'),
            'captcha_answer' => $s('captcha_answer'),
        ];
    }

    /** Why the form does not accept entries right now, or null when it is open. */
    public static function closedReason(array $form): ?string
    {
        $st = self::settings($form);
        if ($st['close_at'] !== '') {
            $ts = 0;
            try {
                $tz = new \DateTimeZone((string)Options::get('timezone', 'UTC') ?: 'UTC');
            } catch (\Throwable) {
                $tz = new \DateTimeZone('UTC');
            }
            try {
                // A date alone closes the form at the end of that day (site time zone).
                $ts = (new \DateTimeImmutable($st['close_at'] . (strlen($st['close_at']) <= 10 ? ' 23:59:59' : ''), $tz))->getTimestamp();
            } catch (\Throwable) {
            }
            if ($ts && time() > $ts) {
                return $st['closed_message'];
            }
        }
        if ($st['max_entries'] > 0 && self::entryCount((int)$form['id']) >= $st['max_entries']) {
            return $st['closed_message'];
        }
        return null;
    }

    /** Entries (not spam) received by a form. */
    public static function entryCount(int $formId): int
    {
        self::install();
        return (int)DB::val("SELECT COUNT(*) FROM {{form_submissions}} WHERE form_id = :f AND status <> 'spam'", ['f' => $formId]);
    }

    /** Per-form figures: id => [total, unread, last]. One query. */
    public static function stats(): array
    {
        self::install();
        $out = [];
        foreach (DB::all("SELECT form_id, COUNT(*) total, SUM(CASE WHEN status = 'new' THEN 1 ELSE 0 END) unread, MAX(created_at) last
            FROM {{form_submissions}} WHERE status <> 'spam' AND form_id IS NOT NULL GROUP BY form_id") as $r) {
            $out[(int)$r['form_id']] = ['total' => (int)$r['total'], 'unread' => (int)$r['unread'], 'last' => (string)$r['last']];
        }
        return $out;
    }

    // ------------------------------------------------------------------ conditions

    /** Is $fd shown, given the submitted / default values (key => string|array)? Fields hidden by a rule count as empty. */
    public static function visibility(array $fields, array $values): array
    {
        $shown = [];
        foreach ($fields as $fd) {
            $on = true;
            if ($fd['cond']) {
                $c = $fd['cond'];
                $v = ($shown[$c['field']] ?? true) ? ($values[$c['field']] ?? '') : '';
                $list = array_map(static fn($x) => mb_strtolower(trim((string)$x)), (array)$v);
                $list = array_values(array_filter($list, static fn($x) => $x !== ''));
                $want = mb_strtolower(trim($c['value']));
                $met = match ($c['op']) {
                    'not' => !in_array($want, $list, true),
                    'contains' => $want !== '' && (bool)array_filter($list, static fn($x) => str_contains($x, $want)),
                    'empty' => !$list,
                    'filled' => (bool)$list,
                    default => in_array($want, $list, true),
                };
                $on = $c['action'] === 'show' ? $met : !$met;
            }
            $shown[$fd['key']] = $on;
        }
        return $shown;
    }

    // ------------------------------------------------------------------ front end (see Render / Submit)

    public static function render(string|int $ref, array $opts = []): string
    {
        return Render::form($ref, $opts);
    }

    /** Replace form widgets / shortcodes / template tags left in a page. */
    public static function replaceTokens(string $html): string
    {
        if (!str_contains($html, 'data-slate-form') && !str_contains($html, '[form ') && !str_contains($html, '{{form:') && !str_contains($html, '{{ form:')) {
            return $html;
        }
        // Editor widget: <div class="slate-form-widget" data-slate-form-widget="12" …>…</div> (also usable in designed pages).
        $html = (string)preg_replace_callback('#<(div|p|section)\b[^>]*\bdata-slate-form-widget=["\']([\w-]+)["\'][^>]*>.*?</\1>#is', static fn($m) => self::render($m[2]), $html);
        // Shortcode: [form id="12"] or [form slug="contact"] (optionally wrapped in its own paragraph).
        $html = (string)preg_replace_callback('#(?:<p>\s*)?\[form\s+(?:id|slug)=["“”]?([\w-]+)["“”]?[^\]]*\](?:\s*</p>)?#i', static fn($m) => self::render($m[1]), $html);
        // {{form:slug}} written straight into an imported or hand-made page (templates already turn it into a shortcode).
        // Only as an element of its own (between tags), never inside an attribute, a meta description or JSON.
        $html = (string)preg_replace_callback('#<p>\s*\{\{\s*form:([\w-]+)\s*\}\}\s*</p>#i', static fn($m) => self::render($m[1]), $html);
        $html = (string)preg_replace_callback('#(?<=>)\s*\{\{\s*form:([\w-]+)\s*\}\}\s*(?=<)#i', static fn($m) => self::render($m[1]), $html);
        // The first form of each id becomes the anchor "#sf-<id>" (where a submit without JavaScript comes back to).
        $seen = [];
        return (string)preg_replace_callback('#(<(?:form|div) id=")sf-(\d+)-\d+(" data-slate-form=)#', static function ($m) use (&$seen) {
            if (isset($seen[$m[2]])) {
                return $m[0];
            }
            $seen[$m[2]] = true;
            return $m[1] . 'sf-' . $m[2] . $m[3];
        }, $html);
    }

    public static function handleSubmit(): never
    {
        Submit::handle();
    }

    // ------------------------------------------------------------------ storage

    public static function store(?array $form, array $data, string $page, string $pageTitle, string $status = 'new', string $source = 'slate', ?string $createdAt = null, ?string $ip = null, ?string $ua = null, ?string $formTitle = null): int
    {
        self::install();
        return DB::insert('form_submissions', [
            'form_id' => $form['id'] ?? null,
            'form_title' => mb_substr($formTitle ?? (string)($form['title'] ?? 'Form'), 0, 191),
            'page_url' => mb_substr($page, 0, 700),
            'page_title' => mb_substr($pageTitle, 0, 255),
            'data' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip' => $ip ?? Auth::ip(),
            'user_agent' => mb_substr($ua ?? (string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            'status' => isset(self::STATUSES[$status]) ? $status : 'new',
            'source' => $source,
            'created_at' => $createdAt ?? now(),
        ]);
    }

    /** Field => value pairs for display, whatever shape the submission was stored in. */
    public static function rows(array $submission): array
    {
        $data = json_decode((string)$submission['data'], true) ?: [];
        $out = [];
        foreach ($data as $k => $v) {
            if (is_array($v) && array_key_exists('value', $v)) {
                $out[] = ['key' => (string)$k, 'label' => (string)($v['label'] ?? $k), 'type' => (string)($v['type'] ?? ''),
                    'value' => is_array($v['value']) ? implode(', ', array_map('strval', $v['value'])) : (string)$v['value'],
                    'file' => is_array($v['file'] ?? null) ? $v['file'] : null];
            } else {
                $out[] = ['key' => (string)$k, 'label' => ucfirst(str_replace(['_', '-'], ' ', (string)$k)), 'type' => '',
                    'value' => is_array($v) ? implode(', ', array_map('strval', $v)) : (string)$v, 'file' => null];
            }
        }
        return $out;
    }

    /** Absolute dashboard URL that downloads an uploaded file of a submission. */
    public static function fileUrl(int $submissionId, string $key, bool $absolute = false): string
    {
        $path = admin_slug() . '/m/forms/file?id=' . $submissionId . '&key=' . rawurlencode($key);
        return $absolute ? site_url($path) : url($path);
    }

    public static function newCount(): int
    {
        try {
            return (int)DB::val("SELECT COUNT(*) FROM {{form_submissions}} WHERE status = 'new'");
        } catch (\Throwable) {
            return 0;
        }
    }

    // ------------------------------------------------------------------ uploads

    /** Private folder for uploaded files: never served by the web server, never executed. */
    public static function uploadDir(): string
    {
        $dir = SLATE_CONTENT . '/form-uploads';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (!is_file($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "Require all denied\nOptions -Indexes -ExecCGI\nRemoveHandler .php .phtml .phar\n<IfModule mod_php.c>\n    php_flag engine off\n</IfModule>\n");
        }
        return $dir;
    }

    /** Full path of a stored file, or null if the reference is not one of ours. */
    public static function filePath(array $file): ?string
    {
        $rel = (string)($file['path'] ?? '');
        if (!preg_match('#^\d{4}/\d{2}/[a-f0-9]{32}$#', $rel)) {
            return null;
        }
        $path = self::uploadDir() . '/' . $rel;
        return is_file($path) ? $path : null;
    }

    /** Delete the uploaded files of submissions (before deleting the rows). */
    public static function deleteFiles(array $submissions): void
    {
        foreach ($submissions as $s) {
            foreach (self::rows($s) as $r) {
                if ($r['file'] && ($p = self::filePath($r['file']))) {
                    @unlink($p);
                }
            }
        }
    }

    // ------------------------------------------------------------------ email

    /** First valid email address the visitor typed. */
    public static function submitterEmail(array $data): string
    {
        foreach ($data as $row) {
            $v = is_array($row) ? ($row['value'] ?? '') : $row;
            if (is_string($v) && ($row['type'] ?? '') === 'email' && filter_var($v, FILTER_VALIDATE_EMAIL)) {
                return $v;
            }
        }
        foreach ($data as $row) {
            $v = is_array($row) ? ($row['value'] ?? '') : $row;
            if (is_string($v) && filter_var($v, FILTER_VALIDATE_EMAIL)) {
                return $v;
            }
        }
        return '';
    }

    /**
     * Replace {form} {site} {date} {page} {all_fields} and {field_key} tags. $html: escape values for HTML
     * (and render {all_fields} as a table).
     */
    public static function tags(string $text, array $form, array $data, string $page = '', bool $html = false, int $submissionId = 0): string
    {
        $esc = static fn(string $v): string => $html ? e($v) : $v;
        $map = ['{form}' => $esc((string)$form['title']), '{site}' => $esc((string)Options::get('site_title')), '{date}' => $esc(local_date(now(), 'M j, Y H:i')), '{page}' => $esc($page)];
        foreach ($data as $k => $row) {
            $v = is_array($row['value'] ?? null) ? implode(', ', $row['value']) : (string)($row['value'] ?? '');
            $map['{' . $k . '}'] = $esc($v);
        }
        if (str_contains($text, '{all_fields}')) {
            $map['{all_fields}'] = self::fieldsTable($data, $html, $submissionId);
        }
        return strtr($text, $map);
    }

    /** Submitted values as an HTML table or as plain text. */
    public static function fieldsTable(array $data, bool $html, int $submissionId = 0): string
    {
        $out = $html ? '<table cellpadding="8" cellspacing="0" style="border-collapse:collapse;width:100%;max-width:640px;font:14px/1.5 sans-serif">' : '';
        foreach ($data as $k => $row) {
            $v = is_array($row['value'] ?? null) ? implode(', ', $row['value']) : (string)($row['value'] ?? '');
            $label = (string)($row['label'] ?? $k);
            if ($html) {
                $cell = $v === '' ? '<span style="color:#888">-</span>' : nl2br(e($v));
                if (!empty($row['file']) && $submissionId) {
                    $cell = '<a href="' . e(self::fileUrl($submissionId, (string)$k, true)) . '">' . e($v) . '</a>';
                }
                $out .= '<tr><th align="left" valign="top" style="border-bottom:1px solid #eee;width:34%;color:#555;font-weight:600">' . e($label) . '</th>'
                    . '<td valign="top" style="border-bottom:1px solid #eee">' . $cell . '</td></tr>';
            } else {
                $out .= $label . ":\n" . ($v === '' ? '-' : $v) . "\n\n";
            }
        }
        return $html ? $out . '</table>' : rtrim($out);
    }

    /**
     * Email the site team about a new entry. $opts (form actions, email routing): to, subject. $id 0 = the entry
     * was not saved (the form keeps no entries), so there is no dashboard link.
     */
    public static function notify(array $form, array $data, string $page, int $id, array $opts = []): void
    {
        $f = (array)($form['fields'] ?? []);
        $to = trim((string)($opts['to'] ?? ($f['notify_to'] ?? '')));
        if (!empty($opts['subject'])) {
            $f['email_subject'] = (string)$opts['subject'];
        }
        if (strtolower($to) === 'none') {
            return;
        }
        $list = array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', $to !== '' ? $to : (trim((string)Options::get('forms_notify_to', '')) ?: (string)Options::get('admin_email'))) ?: []),
            static fn($x) => filter_var($x, FILTER_VALIDATE_EMAIL)));
        if (!$list) {
            return;
        }
        $subject = self::tags(trim((string)($f['email_subject'] ?? '')) ?: 'New entry: {form}', $form, $data, $page);
        $subject = trim((string)preg_replace('/\s+/', ' ', $subject));
        $replyTo = self::submitterEmail($data);
        $link = site_url(admin_slug() . '/m/submissions?id=' . $id);
        $text = "New entry from \"{$form['title']}\"" . ($page ? " on $page" : '') . "\n\n" . self::fieldsTable($data, false)
            . ($id ? "\n\nOpen it in the dashboard: $link\n" : "\n\nThis form does not keep entries: this email is the only copy.\n");
        $html = '<div style="font:15px/1.6 sans-serif;color:#1f1f1f"><p>New entry from <strong>' . e((string)$form['title']) . '</strong>'
            . ($page ? ' on <a href="' . e($page) . '">' . e($page) . '</a>' : '') . '</p>' . self::fieldsTable($data, true, $id)
            . '<p style="margin-top:20px">' . ($id ? '<a href="' . e($link) . '" style="display:inline-block;padding:10px 16px;background:#0D0D0E;color:#fff;border-radius:8px;text-decoration:none">Open in the dashboard</a>'
                : 'This form does not keep entries: this email is the only copy.')
            . ($replyTo ? ' &nbsp; Reply to this email to answer ' . e($replyTo) . '.' : '') . '</p></div>';
        self::mail($list, $subject, $html, $text, $replyTo);
    }

    /** Optional copy / thank-you email to the person who filled in the form. */
    public static function confirm(array $form, array $data, string $page, int $id): void
    {
        $st = self::settings($form);
        $to = self::submitterEmail($data);
        if (!$st['confirm_enabled'] || $to === '') {
            return;
        }
        // Files stay private: the copy lists their names only.
        // Hidden fields are internal (campaign names and the like): not part of the visitor's copy.
        $data = array_filter($data, static fn($row) => ($row['type'] ?? '') !== 'hidden');
        $subject = trim((string)preg_replace('/\s+/', ' ', self::tags($st['confirm_subject'], $form, $data, $page)));
        $text = self::tags($st['confirm_message'], $form, $data, $page);
        $html = '<div style="font:15px/1.6 sans-serif;color:#1f1f1f">' . nl2br(self::tags(e($st['confirm_message']), $form, $data, $page, true)) . '</div>';
        $admin = (string)Options::get('admin_email');
        self::mail([$to], $subject, $html, $text, filter_var($admin, FILTER_VALIDATE_EMAIL) ? $admin : '');
    }

    private static function mail(array $to, string $subject, string $html, string $text, string $replyTo): void
    {
        if (class_exists(\Slate\Mailer::class)) {
            \Slate\Mailer::send($to, $subject, $html, ['text' => $text, 'reply_to' => $replyTo]);
            return;
        }
        $host = (string)parse_url(site_url(), PHP_URL_HOST);
        $headers = 'From: ' . preg_replace('/[^\w .-]/u', '', (string)Options::get('site_title')) . ' <noreply@' . $host . ">\r\nContent-Type: text/plain; charset=utf-8"
            . ($replyTo ? "\r\nReply-To: " . $replyTo : '');
        @mail(implode(',', $to), '=?UTF-8?B?' . base64_encode($subject) . '?=', $text, $headers);
    }

    // ------------------------------------------------------------------ templates for new forms

    /** Starting points offered when creating a form: key => [title, description, icon, fields]. */
    public static function templates(): array
    {
        $f = static fn(string $type, string $label, array $more = []): array => $more + ['type' => $type, 'label' => $label];
        $name = $f('text', 'Name', ['required' => '1', 'width' => '50', 'placeholder' => 'Your name']);
        $email = $f('email', 'Email', ['required' => '1', 'width' => '50', 'placeholder' => 'you@example.com']);
        return [
            'blank' => ['Blank form', 'Start from nothing and add your own fields.', 'plus', []],
            'contact' => ['Contact', 'Name, email and a message.', 'message', [$name, $email, $f('tel', 'Phone', ['width' => '50']), $f('select', 'Subject', ['width' => '50', 'placeholder' => 'Choose a topic', 'options' => "General question\nSales\nSupport"]), $f('textarea', 'Message', ['required' => '1'])]],
            'callback' => ['Call me back', 'A short request for a phone call.', 'bell', [$name, $f('tel', 'Phone', ['required' => '1', 'width' => '50']), $f('radio', 'Best time to call', ['options' => "Morning\nAfternoon\nEvening"]), $f('textarea', 'What is it about?')]],
            'newsletter' => ['Newsletter sign-up', 'Collect email addresses with consent.', 'mail', [$f('email', 'Email', ['required' => '1', 'placeholder' => 'you@example.com']), $f('consent', 'Consent', ['required' => '1', 'options' => 'Yes, send me the newsletter. I can unsubscribe at any time.'])]],
            'quote' => ['Quote request', 'Project details with budget and timing.', 'briefcase', [$name, $email, $f('text', 'Company', ['width' => '50']), $f('select', 'Budget', ['width' => '50', 'placeholder' => 'Choose a range', 'options' => "Under 1,000\n1,000 to 5,000\n5,000 to 20,000\nMore than 20,000"]), $f('date', 'Ideal start date', ['width' => '50']), $f('textarea', 'Tell us about the project', ['required' => '1'])]],
            'event' => ['Event registration', 'Sign-ups with a seat limit.', 'calendar', [$name, $email, $f('number', 'Number of guests', ['min' => '1', 'max' => '10', 'default' => '1', 'width' => '50']), $f('radio', 'Will you join the dinner?', ['options' => "Yes\nNo", 'width' => '50']), $f('textarea', 'Dietary requirements', ['cond' => ['field' => 'will_you_join_the_dinner', 'op' => 'is', 'value' => 'Yes', 'action' => 'show']])]],
            'application' => ['Job application', 'Applicant details and a CV upload.', 'user', [$name, $email, $f('tel', 'Phone', ['width' => '50']), $f('url', 'LinkedIn or portfolio', ['width' => '50']), $f('file', 'CV', ['required' => '1', 'accept' => 'pdf, doc, docx', 'max_size' => '5']), $f('textarea', 'Why do you want to join?')]],
            'feedback' => ['Feedback survey', 'A rating with a follow-up question.', 'star', [$f('radio', 'How would you rate us?', ['required' => '1', 'options' => "5 : Excellent\n4 : Good\n3 : Okay\n2 : Poor\n1 : Bad"]), $f('textarea', 'What could we do better?', ['cond' => ['field' => 'how_would_you_rate_us', 'op' => 'is', 'value' => '1', 'action' => 'show']]), $f('email', 'Email (optional)', ['help' => 'Only if you would like a reply.'])]],
        ];
    }

    /** Field rows of a template with keys and ids filled in. */
    public static function templateFields(string $key): array
    {
        $rows = self::templates()[$key][3] ?? [];
        $taken = [];
        foreach ($rows as &$r) {
            $r['name'] = self::keyFor((string)$r['label'], $taken);
            $taken[] = $r['name'];
            $r['id'] = 'f' . bin2hex(random_bytes(4));
        }
        unset($r);
        return $rows;
    }

    /** Create a form from a template. Returns the new id. */
    public static function create(string $title, string $template = 'blank', string $status = 'publish'): int
    {
        $tpl = isset(self::templates()[$template]) ? $template : 'blank';
        $title = trim($title) !== '' ? trim($title) : self::templates()[$tpl][0];
        $fields = ['form_fields' => self::templateFields($tpl), 'submit_label' => $tpl === 'newsletter' ? 'Subscribe' : ($tpl === 'event' ? 'Register' : 'Send')];
        if ($tpl === 'application') {
            $fields['success_message'] = 'Thanks for applying! We will be in touch.';
        }
        $id = Posts::save(['type' => self::TYPE, 'title' => mb_substr($title, 0, 190), 'status' => $status, 'fields' => $fields]);
        log_activity('form_created', $title);
        return $id;
    }
}
