<?php
/**
 * Forms: visual form builder (Modules › Forms), Form widget / field / shortcode / template tag, entries inbox.
 */
declare(strict_types=1);

use Slate\App;
use Slate\Auth;
use Slate\Hooks;
use Slate\Modules;
use Slate\Router;
use Slate\Session;
use SlateModules\Forms\Admin;
use SlateModules\Forms\Forms;
use SlateModules\Forms\Inbox;

require_once __DIR__ . '/src/Forms.php';
require_once __DIR__ . '/src/Render.php';
require_once __DIR__ . '/src/Submit.php';
require_once __DIR__ . '/src/Inbox.php';
require_once __DIR__ . '/src/Admin.php';

Hooks::add('module_activated_slate-forms', [Forms::class, 'install']);
Hooks::add('module_updated_slate-forms', [Forms::class, 'install']);

// Content type + the field groups that describe its stored format.
Hooks::add('post_types', static function (array $types): array {
    $types[Forms::TYPE] = Forms::postType();
    return $types;
});
Hooks::add('field_groups', static fn(array $groups): array => $groups + Forms::fieldGroups());

// Front end: widgets, shortcodes and {{form:slug}} anywhere in the page (content, designed pages, templates).
Hooks::add('html_output', [Forms::class, 'replaceTokens']);

Hooks::add('init', static function (): void {
    $path = App::path();
    if ($path === Forms::ENDPOINT) {
        Forms::handleSubmit();
    }
    // Old dashboard addresses of forms (Content › Forms) open the form builder.
    $admin = '/' . admin_slug() . '/content/form';
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && ($path === $admin || str_starts_with($path, $admin . '/'))) {
        $rest = substr($path, strlen($admin));
        if ($rest === '' || $rest === '/') {
            redirect(Admin::listUrl());
        }
        if ($rest === '/new') {
            redirect(Forms::editUrl());
        }
        if (preg_match('#^/(\d+)/?$#', $rest, $m)) {
            redirect(Forms::editUrl((int)$m[1]));
        }
    }
}, 1);

// Theme helper: echo slate_form('contact');
if (!function_exists('slate_form')) {
    function slate_form(string|int $ref): string
    {
        return Forms::render($ref);
    }
}

// ---------------------------------------------------------------- admin
Modules::adminPage('Forms', 'forms', [Admin::class, 'formsPage'], Admin::CAP, 'message');
Modules::adminPage('Entries', 'submissions', static function (): void {
    Forms::install();
    require __DIR__ . '/views/submissions.php';
}, Admin::CAP, 'inbox', 'main', [Forms::class, 'newCount']);
Modules::adminPage('Form builder', 'form-edit', [Admin::class, 'editPage'], Admin::CAP, 'message', 'hidden');

Modules::settings([
    ['type' => 'heading', 'label' => 'Email notifications'],
    ['key' => 'forms_notify_to', 'type' => 'text', 'label' => 'Send new entries to', 'default' => '', 'placeholder' => 'Admin email (Settings › General)',
        'help' => 'Comma-separated addresses, used by every form that has no recipients of its own. Empty = the admin email.'],
    ['type' => 'heading', 'label' => 'Spam protection', 'help' => 'Every form has a hidden honeypot field that bots fill in; those entries are dropped silently.'],
    ['key' => 'forms_min_seconds', 'type' => 'number', 'label' => 'Minimum time to fill in a form', 'default' => 3, 'min' => 0, 'max' => 60, 'suffix' => 'seconds',
        'help' => 'Entries sent faster than this after the page loaded go to Spam. 0 = off.'],
    ['key' => 'forms_rate_limit', 'type' => 'number', 'label' => 'Limit per visitor', 'default' => 15, 'min' => 0, 'max' => 1000, 'suffix' => 'per hour',
        'help' => 'Maximum entries from one IP address per hour. 0 = no limit.'],
    ['key' => 'forms_require_js', 'type' => 'toggle', 'label' => 'Send entries from browsers without JavaScript to Spam', 'default' => false,
        'help' => 'Forms work without JavaScript. Turn this on only if spam keeps getting through; those entries stay reviewable under Spam.'],
    ['key' => 'forms_spam_days', 'type' => 'number', 'label' => 'Delete spam after', 'default' => 0, 'min' => 0, 'max' => 3650, 'suffix' => 'days',
        'help' => '0 = keep spam until you delete it.'],
    ['type' => 'heading', 'label' => 'File uploads', 'help' => 'Files are stored privately (content/form-uploads), renamed and never run. Only signed-in team members can download them.'],
    ['key' => 'forms_upload_max', 'type' => 'number', 'label' => 'Largest file', 'default' => 10, 'min' => 1, 'max' => 100, 'suffix' => 'MB',
        'help' => 'A field can set a lower limit. The server limit (upload_max_filesize) always applies.'],
    ['key' => 'forms_upload_types', 'type' => 'text', 'label' => 'Allowed file types', 'default' => '', 'placeholder' => Forms::DEFAULT_UPLOAD_TYPES,
        'help' => 'Comma-separated extensions. Scripts, web pages and programs are always refused.'],
], ['help' => 'Options shared by all forms. Each form’s fields, messages and emails are set in the form builder.']);

Hooks::add('cron_jobs', static fn(array $jobs): array => $jobs + ['forms_spam_cleanup' => ['every' => 86400, 'label' => 'Delete old form spam (Forms settings)', 'run' => [Inbox::class, 'purgeSpam']]]);

Hooks::add('admin_routes', static function (Router $r): void {
    Admin::routes($r);
    $r->post('/m/submissions/bulk', static function (): void {
        Auth::require(Admin::CAP);
        Forms::install();
        $state = null;
        if (input('all_matching')) {
            // Every entry matching the list's search, view and filters, not just this page.
            parse_str((string)input('query'), $get);
            $saved = $_GET;
            $_GET = $get;
            $state = Inbox::table()->state();
            $_GET = $saved;
        }
        $action = (string)input('action', '');
        $n = Inbox::apply($action, (array)input('ids', []), $state);
        if (is_ajax()) {
            json_response(['ok' => true, 'count' => $n]);
        }
        if ($n) {
            Session::flash('success', number_format($n) . ' entr' . ($n === 1 ? 'y' : 'ies') . ($action === 'delete' ? ' deleted.' : ' updated.'));
        }
        $to = (string)input('return', '') ?: (string)($_SERVER['HTTP_REFERER'] ?? '');
        $u = parse_url($to) ?: [];
        if (isset($u['host']) && $u['host'] === parse_url('//' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST)) {
            $to = ($u['path'] ?? '') . (isset($u['query']) ? '?' . $u['query'] : ''); // back to the same list page / filters
        }
        redirect($to !== '' && str_starts_with($to, admin_url()) ? $to : Inbox::url());
    });
    // Old export address (?form= &status= &q=), kept for bookmarks. The list's own export button uses ?export=csv.
    $r->get('/m/submissions/export', static function (): void {
        Auth::require(Admin::CAP);
        Forms::install();
        $q = Inbox::legacyQuery($_GET + ['status' => '']) ?? [];
        [$where, $params] = Inbox::where(['view' => $q['view'] ?? 'inbox', 'filters' => $q['f'] ?? [], 'q' => $q['q'] ?? '']);
        Inbox::exportCsv($where, $params);
    });
});
