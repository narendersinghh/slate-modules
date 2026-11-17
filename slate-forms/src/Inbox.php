<?php
declare(strict_types=1);

namespace SlateModules\Forms;

use Slate\AdminUi;
use Slate\DB;
use Slate\ListTable;
use Slate\Posts;

/**
 * The submissions inbox in the dashboard (Modules › Forms › Entries): list table, filters, bulk actions, CSV.
 */
final class Inbox
{
    /** Status views: key => [label, SQL condition]. The first one is the default. */
    public const VIEWS = [
        'inbox' => ['Inbox', "status <> 'spam'"],
        'new' => ['Unread', "status = 'new'"],
        'starred' => ['Starred', "starred = 1 AND status <> 'spam'"],
        'spam' => ['Spam', "status = 'spam'"],
        'all' => ['All', ''],
    ];
    private const SORTS = ['id' => 'id', 'form' => 'form_title', 'status' => 'status'];

    public static function url(array $query = []): string
    {
        return admin_url('m/submissions') . ($query ? '?' . http_build_query($query) : '');
    }

    private const DATES = ['today' => 'Today', '7d' => 'Last 7 days', '30d' => 'Last 30 days', '90d' => 'Last 90 days', '365d' => 'Last 12 months'];

    public static function table(): ListTable
    {
        $counts = array_map('intval', array_column(DB::all('SELECT status, COUNT(*) c FROM {{form_submissions}} GROUP BY status'), 'c', 'status'));
        $counts['starred'] = (int)DB::val("SELECT COUNT(*) FROM {{form_submissions}} WHERE starred = 1 AND status <> 'spam'");
        $views = [];
        foreach (self::VIEWS as $k => [$label]) {
            $views[$k] = [$label, match ($k) {
                'inbox' => ($counts['new'] ?? 0) + ($counts['read'] ?? 0),
                'all' => array_sum($counts),
                default => $counts[$k] ?? 0,
            }];
        }
        $perForm = array_map('intval', array_column(DB::all('SELECT form_id, COUNT(*) c FROM {{form_submissions}} WHERE form_id IS NOT NULL GROUP BY form_id'), 'c', 'form_id'));
        $forms = [];
        foreach (Posts::query(['type' => Forms::TYPE, 'status' => 'any', 'limit' => 200, 'count' => false, 'orderby' => 'title', 'order' => 'ASC'])['items'] as $f) {
            $forms[(int)$f['id']] = ($f['title'] ?: '(no title)') . ' (' . number_format($perForm[(int)$f['id']] ?? 0) . ')';
        }
        $spamView = ($_GET['view'] ?? '') === 'spam';
        $bulk = $spamView ? ['read' => 'Not spam'] : ['read' => 'Mark as read', 'new' => 'Mark as unread', 'star' => 'Star', 'unstar' => 'Remove star', 'spam' => 'Mark as spam'];
        $bulk['delete'] = ['label' => 'Delete', 'danger' => true, 'confirm' => 'Permanently delete the selected submissions? This cannot be undone.'];

        $open = static fn(array $s): string => self::url(['id' => (int)$s['id']]);
        return new ListTable('forms.submissions', [
            'star' => ['label' => 'Starred', 'class' => 'sf-star-col', 'render' => static fn(array $s): string => self::starButton($s), 'export' => static fn(array $s) => !empty($s['starred']) ? 'yes' : ''],
            'from' => ['label' => 'From', 'primary' => true, 'render' => static function (array $s) use ($open): string {
                [$name, $email] = self::sender($s);
                $title = $name !== '' ? $name : ($email !== '' ? $email : 'Submission #' . (int)$s['id']);
                $html = '<a class="row-title" href="' . e($open($s)) . '">' . ($s['status'] === 'new' ? '<strong>' . e($title) . '</strong>' : e($title)) . '</a>';
                if ($s['status'] === 'new') {
                    $html .= ' ' . AdminUi::pill('New', 'info');
                } elseif ($s['status'] === 'spam') {
                    $html .= ' ' . AdminUi::pill('Spam', 'warn');
                }
                return $html . ($name !== '' && $email !== '' ? '<br><small class="muted">' . e($email) . '</small>' : '');
            }, 'export' => static fn(array $s) => implode(' ', array_filter(self::sender($s)))],
            'message' => ['label' => 'Message', 'render' => static function (array $s): string {
                $out = '';
                foreach (self::preview($s) as $r) {
                    $out .= '<div class="small"><span class="muted">' . e($r['label']) . ':</span> ' . e(mb_strimwidth($r['value'], 0, 90, '…')) . '</div>';
                }
                return $out ?: '<span class="muted">-</span>';
            }],
            'form' => ['label' => 'Form', 'sort' => 'form', 'render' => static fn(array $s) => e($s['form_title'])],
            'page' => ['label' => 'Page', 'render' => static function (array $s): string {
                if ($s['page_url'] === '') {
                    return '<span class="muted">-</span>';
                }
                return '<a href="' . e($s['page_url']) . '" target="_blank" rel="noopener">' . e($s['page_title'] ?: ((string)parse_url($s['page_url'], PHP_URL_PATH) ?: $s['page_url'])) . '</a>';
            }, 'export' => static fn(array $s) => (string)$s['page_url']],
            'status' => ['label' => 'Status', 'default' => false, 'sort' => 'status', 'render' => static fn(array $s) => e(Forms::STATUSES[$s['status']] ?? $s['status'])],
            'ip' => ['label' => 'IP address', 'default' => false, 'render' => static fn(array $s) => e($s['ip'] ?: '-')],
            'received' => ['label' => 'Received', 'sort' => 'id', 'sort_dir' => 'desc', 'class' => 'nowrap', 'render' => static fn(array $s) => e(local_date($s['created_at'], 'M j, Y H:i')),
                'export' => static fn(array $s) => (string)$s['created_at']],
        ], [
            'base' => self::url(),
            'search' => 'Search entries…',
            'views' => $views,
            'filters' => ($forms ? ['form' => ['label' => 'Form', 'all' => 'All forms', 'options' => $forms]] : []) + ['date' => ['label' => 'Received', 'all' => 'Any time', 'options' => self::DATES]],
            'bulk' => $bulk,
            'bulk_action' => admin_url('m/submissions/bulk'),
            'all_matching' => true,
            'sort' => 'id', 'dir' => 'desc', 'per_page' => 50, 'export' => true,
            'row_actions' => static function (array $s) use ($open): string {
                $email = self::sender($s)[1];
                return '<a href="' . e($open($s)) . '">Open</a>'
                    . ($email !== '' ? '<a href="mailto:' . e($email) . '?subject=' . rawurlencode('Re: ' . $s['form_title']) . '">Reply</a>' : '');
            },
            'empty' => ['icon' => 'inbox', 'title' => 'No entries here', 'text' => 'Entries appear here as soon as a visitor sends one of your forms.'],
        ]);
    }

    /** WHERE clause + params for a table state (view, form filter, search). */
    public static function where(array $state): array
    {
        $w = [];
        $p = [];
        $view = (string)($state['view'] ?? '');
        $cond = self::VIEWS[$view][1] ?? ($view === 'read' ? "status = 'read'" : self::VIEWS['inbox'][1]); // "read": old export links
        if ($cond !== '') {
            $w[] = $cond;
        }
        if (($form = (int)($state['filters']['form'] ?? 0)) > 0) {
            $w[] = 'form_id = :form';
            $p['form'] = $form;
        }
        $since = match ((string)($state['filters']['date'] ?? '')) {
            'today' => gmdate('Y-m-d H:i:s', strtotime('today')), '7d' => gmdate('Y-m-d H:i:s', time() - 7 * 86400), '30d' => gmdate('Y-m-d H:i:s', time() - 30 * 86400),
            '90d' => gmdate('Y-m-d H:i:s', time() - 90 * 86400), '365d' => gmdate('Y-m-d H:i:s', time() - 365 * 86400), default => null,
        };
        if ($since !== null) {
            $w[] = 'created_at >= :since';
            $p['since'] = $since;
        }
        if (($q = trim((string)($state['q'] ?? ''))) !== '') {
            $w[] = '(data LIKE :q OR page_url LIKE :q2 OR form_title LIKE :q3 OR ip = :q4)';
            $p['q'] = $p['q2'] = $p['q3'] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
            $p['q4'] = $q;
        }
        return [$w ? 'WHERE ' . implode(' AND ', $w) : '', $p];
    }

    public static function orderBy(array $state): string
    {
        $col = self::SORTS[$state['sort'] ?? 'id'] ?? 'id';
        return $col . ($state['dir'] === 'asc' ? ' ASC' : ' DESC') . ($col === 'id' ? '' : ', id DESC');
    }

    /** Old inbox addresses (?status= &form= &pg=) to the list table's (?view= &f[form]= &p=). */
    public static function legacyQuery(array $get): ?array
    {
        if (!isset($get['status']) && !isset($get['form']) && !isset($get['pg'])) {
            return null;
        }
        $status = (string)($get['status'] ?? '');
        $q = ['view' => match ($status) { 'new' => 'new', 'spam' => 'spam', 'all' => 'all', 'read' => 'read', default => null },
            'f' => (int)($get['form'] ?? 0) > 0 ? ['form' => (int)$get['form']] : null, 'q' => (string)($get['q'] ?? '') ?: null,
            'p' => (int)($get['pg'] ?? 0) > 1 ? (int)$get['pg'] : null];
        return array_filter($q, static fn($v) => $v !== null);
    }

    // ------------------------------------------------------------------ bulk

    /** Apply a bulk action; returns the number of submissions changed. */
    public static function apply(string $action, array $ids, ?array $state = null): int
    {
        if (!in_array($action, ['read', 'new', 'spam', 'delete', 'star', 'unstar'], true)) {
            return 0;
        }
        if ($state !== null) {
            [$where, $params] = self::where($state);
        } else {
            $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
            if (!$ids) {
                return 0;
            }
            [$in, $params] = DB::in($ids, 'sid');
            $where = "WHERE id IN ($in)";
        }
        $n = (int)DB::val("SELECT COUNT(*) FROM {{form_submissions}} $where", $params); // matched, not just changed
        if (!$n) {
            return 0;
        }
        if ($action === 'delete') {
            Forms::deleteFiles(DB::all("SELECT id, data FROM {{form_submissions}} $where", $params));
            DB::query("DELETE FROM {{form_submissions}} $where", $params);
        } elseif ($action === 'star' || $action === 'unstar') {
            DB::query("UPDATE {{form_submissions}} SET starred = :sv $where", $params + ['sv' => $action === 'star' ? 1 : 0]);
        } else {
            DB::query("UPDATE {{form_submissions}} SET status = :st $where", $params + ['st' => $action]);
        }
        log_activity('submissions_' . $action, (string)$n);
        return $n;
    }

    // ------------------------------------------------------------------ export

    /** CSV with one column per form field (the format exports have always had), then exit. */
    public static function exportCsv(string $where, array $params, string $order = 'id DESC'): never
    {
        $rows = DB::all("SELECT * FROM {{form_submissions}} $where ORDER BY $order LIMIT 50000", $params);
        $labels = [];
        $decoded = [];
        foreach ($rows as $r) {
            $pairs = Forms::rows($r);
            $decoded[] = [$r, $pairs];
            foreach ($pairs as $p) {
                $labels[$p['label']] = true;
            }
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="submissions-' . gmdate('Y-m-d') . '.csv"');
        header('Cache-Control: no-store');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        $cols = array_keys($labels);
        fputcsv($out, array_merge(['ID', 'Received (UTC)', 'Form', 'Status', 'Page'], $cols, ['IP']), ',', '"', '');
        foreach ($decoded as [$r, $pairs]) {
            $map = array_column($pairs, 'value', 'label');
            $line = [$r['id'], $r['created_at'], $r['form_title'], $r['status'], $r['page_url']];
            foreach ($cols as $c) {
                $v = (string)($map[$c] ?? '');
                $line[] = preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v; // no spreadsheet formula injection
            }
            $line[] = $r['ip'];
            fputcsv($out, $line, ',', '"', '');
        }
        fclose($out);
        exit;
    }

    // ------------------------------------------------------------------ helpers

    /** [name, email] guessed from the submitted fields. */
    public static function sender(array $s): array
    {
        $name = '';
        $email = '';
        foreach (Forms::rows($s) as $r) {
            $v = trim($r['value']);
            if ($email === '' && filter_var($v, FILTER_VALIDATE_EMAIL)) {
                $email = $v;
            } elseif ($name === '' && $v !== '' && mb_strlen($v) <= 80 && preg_match('/\bname\b|^name|naam|nom\b/i', $r['label'])) {
                $name = $v;
            }
        }
        return [$name, $email];
    }

    /** First few non-empty fields that are not the sender's name/email. */
    private static function preview(array $s, int $max = 3): array
    {
        $skip = array_filter(self::sender($s));
        $out = [];
        foreach (Forms::rows($s) as $r) {
            if (trim($r['value']) === '' || in_array(trim($r['value']), $skip, true)) {
                continue;
            }
            $out[] = $r;
            if (count($out) >= $max) {
                break;
            }
        }
        return $out;
    }

    /** Star toggle (the module script posts it; lists sit inside the bulk form, so no nested forms). */
    public static function starButton(array $s): string
    {
        $on = !empty($s['starred']);
        return '<button type="button" class="sf-star' . ($on ? ' is-on' : '') . '" data-sf-star="' . (int)$s['id'] . '" title="Star" aria-label="Star" aria-pressed="' . ($on ? 'true' : 'false') . '">'
            . icon('star', 16) . '</button>';
    }

    /** Daily clean-up: delete spam older than the "forms_spam_days" setting (0 = keep). */
    public static function purgeSpam(): void
    {
        $days = (int)\Slate\Options::get('forms_spam_days', 0);
        if ($days > 0) {
            Forms::install();
            Forms::deleteFiles(DB::all("SELECT id, data FROM {{form_submissions}} WHERE status = 'spam' AND created_at < :t", ['t' => gmdate('Y-m-d H:i:s', time() - $days * 86400)]));
            DB::query("DELETE FROM {{form_submissions}} WHERE status = 'spam' AND created_at < :t", ['t' => gmdate('Y-m-d H:i:s', time() - $days * 86400)]);
        }
    }
}
