<?php
/**
 * Forms, form builder (Content › Forms), Form widget / field / template tag, submissions inbox.
 */
declare(strict_types=1);

use Slate\App;
use Slate\Auth;
use Slate\Hooks;
use Slate\Modules;
use Slate\Router;
use Slate\Session;
use SlateModules\Forms\Forms;
use SlateModules\Forms\Inbox;

require_once __DIR__ . '/src/Forms.php';
require_once __DIR__ . '/src/Inbox.php';

Hooks::add('module_activated_slate-forms', [Forms::class, 'install']);

// Content type + its builder fields.
Hooks::add('post_types', static function (array $types): array {
    $types[Forms::TYPE] = Forms::postType();
    return $types;
});
Hooks::add('field_groups', static fn(array $groups): array => $groups + Forms::fieldGroups());

// Front end: widgets and shortcodes anywhere in the page (content, snapshots, templates).
Hooks::add('html_output', [Forms::class, 'replaceTokens']);

Hooks::add('init', static function (): void {
    if (App::path() === Forms::ENDPOINT) {
        Forms::handleSubmit();
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
Modules::adminPage('Submissions', 'submissions', static function (): void {
    Forms::install();
    require __DIR__ . '/views/submissions.php';
}, 'edit_others_posts', 'message', 'main', [Forms::class, 'newCount']);

Modules::settings([
    ['type' => 'heading', 'label' => 'Email notifications'],
    ['key' => 'forms_notify_to', 'type' => 'text', 'label' => 'Send new submissions to', 'default' => '', 'placeholder' => 'Admin email (Settings › General)',
        'help' => 'Comma-separated addresses, used by every form that has no recipients of its own. Empty = the admin email.'],
    ['type' => 'heading', 'label' => 'Spam protection', 'help' => 'Every form also has a hidden honeypot field that bots fill in; those entries are dropped silently.'],
    ['key' => 'forms_require_js', 'type' => 'toggle', 'label' => 'Send entries from browsers without JavaScript to Spam', 'default' => true,
        'help' => 'Most bots do not run JavaScript. These entries are kept under Spam (no email is sent) so you can still review them.'],
    ['key' => 'forms_rate_limit', 'type' => 'number', 'label' => 'Limit per visitor', 'default' => 15, 'min' => 0, 'max' => 1000, 'suffix' => 'per hour',
        'help' => 'Maximum submissions from one IP address per hour. 0 = no limit.'],
    ['key' => 'forms_spam_days', 'type' => 'number', 'label' => 'Delete spam after', 'default' => 0, 'min' => 0, 'max' => 3650, 'suffix' => 'days',
        'help' => '0 = keep spam until you delete it.'],
], ['help' => 'Options shared by all forms. Each form’s own fields, messages and recipients are set when you edit the form.']);

Hooks::add('cron_jobs', static fn(array $jobs): array => $jobs + ['forms_spam_cleanup' => ['every' => 86400, 'label' => 'Delete old form spam (Forms settings)', 'run' => [Inbox::class, 'purgeSpam']]]);

Hooks::add('admin_routes', static function (Router $r): void {
    $r->post('/m/submissions/bulk', static function (): void {
        Auth::require('edit_others_posts');
        Forms::install();
        $state = null;
        if (input('all_matching')) {
            // Every submission matching the list's search, view and filters, not just this page.
            parse_str((string)input('query'), $get);
            $saved = $_GET;
            $_GET = $get;
            $state = Inbox::table()->state();
            $_GET = $saved;
        }
        $n = Inbox::apply((string)input('action', ''), (array)input('ids', []), $state);
        if ($n) {
            Session::flash('success', number_format($n) . ' submission' . ($n === 1 ? '' : 's') . (input('action') === 'delete' ? ' deleted.' : ' updated.'));
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
        Auth::require('edit_others_posts');
        Forms::install();
        $q = Inbox::legacyQuery($_GET + ['status' => '']) ?? [];
        [$where, $params] = Inbox::where(['view' => $q['view'] ?? 'inbox', 'filters' => $q['f'] ?? [], 'q' => $q['q'] ?? '']);
        Inbox::exportCsv($where, $params);
    });
});
