<?php
declare(strict_types=1);

namespace SlateModules\Forms;

use Slate\AdminUi;
use Slate\Auth;
use Slate\DB;
use Slate\ListTable;
use Slate\PageCache;
use Slate\Posts;
use Slate\Router;
use Slate\Session;

/**
 * Dashboard screens of the Forms module: the forms list, the visual builder and the small JSON endpoints used by the
 * builder and by the content editor's "Insert a form" picker. Every POST is CSRF-checked by the dashboard.
 */
final class Admin
{
    public const CAP = 'edit_others_posts';
    public const MODULE = 'slate-forms';

    public static function listUrl(array $q = []): string
    {
        return admin_url('m/forms') . ($q ? '?' . http_build_query($q) : '');
    }

    public static function asset(string $file): string
    {
        $path = SLATE_MODULES . '/slate-forms/assets/' . $file;
        return url('modules/slate-forms/assets/' . $file) . '?v=' . substr(md5((string)@filemtime($path)), 0, 8);
    }

    /** Module stylesheet + script for dashboard pages. */
    public static function assets(bool $builder = false): string
    {
        return '<link rel="stylesheet" href="' . e(self::asset('admin.css')) . '">'
            . '<script src="' . e(self::asset('admin.js')) . '" defer></script>'
            . ($builder ? '<script src="' . e(self::asset('builder.js')) . '" defer></script>' : '');
    }

    // ------------------------------------------------------------------ forms list

    public static function formsPage(): void
    {
        Forms::install();
        $stats = Forms::stats();
        $counts = Posts::counts(Forms::TYPE);
        $table = self::table($stats, $counts);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            self::bulk();
        }
        $s = $table->state();
        $status = match ($s['view']) { 'publish' => 'publish', 'draft' => 'draft', default => 'any' };
        $orderby = ['title' => 'title', 'updated' => 'updated_at', 'created' => 'created_at'][$s['sort']] ?? 'updated_at';
        $res = Posts::query(['type' => Forms::TYPE, 'status' => $status, 'search' => $s['q'], 'limit' => $s['per'], 'page' => $s['page'], 'orderby' => $orderby, 'order' => strtoupper($s['dir'])]);
        $unread = Forms::newCount();
        $month = (int)DB::val("SELECT COUNT(*) FROM {{form_submissions}} WHERE status <> 'spam' AND created_at >= :t", ['t' => gmdate('Y-m-d H:i:s', time() - 30 * 86400)]);

        echo self::assets();
        echo AdminUi::header('Forms', 'Build forms, place them on any page and read the entries.', 'message', ['New form' => [Forms::editUrl() . '?new=1', 'primary', 'plus']]);
        if (!$res['total'] && $s['q'] === '' && $s['view'] === 'all') {
            echo self::templatePicker(true);
            return;
        }
        echo AdminUi::kpis(['Forms' => number_format((int)($counts['publish'] ?? 0) + (int)($counts['draft'] ?? 0)), 'Entries, last 30 days' => number_format($month), 'Unread' => number_format($unread)]);
        echo $table->render($res['items'], (int)$res['total']);
    }

    private static function table(array $stats, array $counts): ListTable
    {
        $all = 0;
        foreach ($counts as $k => $n) {
            $all += $k !== 'trash' ? (int)$n : 0;
        }
        return new ListTable('forms.list', [
            'title' => ['label' => 'Form', 'primary' => true, 'sort' => 'title', 'render' => static function (array $f) use ($stats): string {
                $n = count(array_filter(Forms::fields($f), static fn($x) => !in_array($x['type'], Forms::DISPLAY_TYPES, true)));
                return '<a class="row-title" href="' . e(Forms::editUrl((int)$f['id'])) . '">' . e($f['title'] ?: '(no title)') . '</a>'
                    . ($f['status'] !== 'publish' ? ' ' . AdminUi::pill('Draft', 'warn') : '')
                    . '<br><small class="muted">' . $n . ' field' . ($n === 1 ? '' : 's') . (Forms::closedReason($f) !== null ? ' · closed' : '') . '</small>';
            }, 'export' => static fn(array $f) => (string)$f['title']],
            'entries' => ['label' => 'Entries', 'align' => 'right', 'render' => static function (array $f) use ($stats): string {
                $st = $stats[(int)$f['id']] ?? ['total' => 0, 'unread' => 0];
                $link = '<a href="' . e(Inbox::url(['f' => ['form' => (int)$f['id']]])) . '" class="num">' . number_format($st['total']) . '</a>';
                return $link . ($st['unread'] ? ' ' . AdminUi::pill($st['unread'] . ' new', 'info') : '');
            }, 'export' => static fn(array $f) => (string)($stats[(int)$f['id']]['total'] ?? 0)],
            'last' => ['label' => 'Last entry', 'class' => 'nowrap', 'render' => static function (array $f) use ($stats): string {
                $last = $stats[(int)$f['id']]['last'] ?? '';
                return $last !== '' ? '<span title="' . e(local_date($last, 'M j, Y H:i')) . '">' . e(time_ago($last)) . '</span>' : '<span class="muted">-</span>';
            }],
            'embed' => ['label' => 'Shortcode', 'render' => static fn(array $f): string => '<button type="button" class="sf-code" data-copy="[form slug=&quot;' . e($f['slug']) . '&quot;]" title="Copy shortcode">'
                . '<code>[form slug="' . e($f['slug']) . '"]</code>' . icon('copy', 14) . '</button>', 'export' => static fn(array $f) => '[form slug="' . $f['slug'] . '"]'],
            'updated' => ['label' => 'Updated', 'sort' => 'updated', 'class' => 'nowrap', 'default' => false, 'render' => static fn(array $f) => e(local_date($f['updated_at'], 'M j, Y'))],
        ], [
            'base' => self::listUrl(),
            'search' => 'Search forms…',
            'views' => ['all' => ['All', $all], 'publish' => ['Published', (int)($counts['publish'] ?? 0)], 'draft' => ['Drafts', (int)($counts['draft'] ?? 0)]],
            'bulk' => ['publish' => 'Publish', 'draft' => 'Switch to draft', 'duplicate' => 'Duplicate',
                'trash' => ['label' => 'Delete', 'danger' => true, 'confirm' => 'Delete the selected forms? Pages that show them will no longer display them. Their entries are kept.']],
            'bulk_action' => self::listUrl(),
            'sort' => 'updated', 'dir' => 'desc', 'per_page' => 25, 'export' => false,
            'row_actions' => static fn(array $f): string => '<a href="' . e(Forms::editUrl((int)$f['id'])) . '">Edit</a>'
                . '<a href="' . e(Inbox::url(['f' => ['form' => (int)$f['id']]])) . '">Entries</a>'
                . '<a href="' . e(admin_url('m/forms/preview?id=' . (int)$f['id'])) . '" target="_blank" rel="noopener">Preview</a>',
            'empty' => ['icon' => 'message', 'title' => 'No forms found', 'text' => 'Try another search, or create a new form.'],
        ]);
    }

    private static function bulk(): never
    {
        $action = (string)input('action', '');
        $ids = array_values(array_filter(array_map('intval', (array)input('ids', []))));
        $n = 0;
        foreach ($ids as $id) {
            $f = Posts::find($id);
            if (!$f || $f['type'] !== Forms::TYPE) {
                continue;
            }
            match ($action) {
                'publish', 'draft' => Posts::save(['status' => $action], $id),
                'trash' => Posts::delete($id),
                'duplicate' => self::duplicate($f),
                default => null,
            };
            $n++;
        }
        if ($n) {
            PageCache::clear();
            Session::flash('success', $n . ' form' . ($n === 1 ? '' : 's') . ' ' . match ($action) { 'trash' => 'deleted', 'duplicate' => 'duplicated', default => 'updated' } . '.');
        }
        redirect(self::listUrl());
    }

    private static function duplicate(array $f): int
    {
        $fields = $f['fields'];
        return Posts::save(['type' => Forms::TYPE, 'title' => $f['title'] . ' (copy)', 'status' => 'draft', 'fields' => $fields]);
    }

    /** Template cards for a new form (a POST form, so nothing is created by just opening a link). */
    public static function templatePicker(bool $first = false): string
    {
        $h = '<form method="post" action="' . e(admin_url('m/forms/create')) . '" class="card sf-new">' . csrf_field()
            . ($first ? '<div class="card-head"><h2>Create your first form</h2></div>' : '') . '<div class="card-pad stack">'
            . '<label class="field"><span>Form name</span><input class="input input-lg" name="title" placeholder="e.g. Contact us" maxlength="190" autofocus></label>'
            . '<div><p class="small-label">Start from</p><div class="sf-templates">';
        $i = 0;
        foreach (Forms::templates() as $k => [$title, $desc, $ic, $fields]) {
            $h .= '<label class="sf-tpl"><input type="radio" name="template" value="' . e($k) . '"' . ($i++ === 1 ? ' checked' : '') . '>'
                . '<span class="sf-tpl-icon">' . icon($ic, 18) . '</span><span><strong>' . e($title) . '</strong><small>' . e($desc) . '</small></span></label>';
        }
        return $h . '</div></div></div><div class="card-foot row-between"><a class="btn btn-ghost" href="' . e(self::listUrl()) . '">Cancel</a>'
            . '<button class="btn btn-primary">' . icon('plus', 16) . ' Create form</button></div></form>';
    }

    // ------------------------------------------------------------------ builder

    public static function editPage(): void
    {
        Forms::install();
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) {
            echo self::assets();
            echo AdminUi::header('New form', 'Pick a starting point. You can change everything afterwards.', 'message');
            echo self::templatePicker();
            return;
        }
        $form = Posts::find($id);
        if (!$form || $form['type'] !== Forms::TYPE || $form['status'] === 'trash') {
            echo AdminUi::emptyState('Form not found', 'It may have been deleted.', 'message', ['All forms' => self::listUrl()]);
            return;
        }
        $rows = [];
        foreach (Forms::fields($form) as $i => $fd) {
            $raw = (array)(array_values((array)($form['fields']['form_fields'] ?? []))[$i] ?? []);
            $rows[] = ['id' => preg_match('/^[a-z0-9]{1,20}$/i', (string)($raw['id'] ?? '')) ? (string)$raw['id'] : 'f' . bin2hex(random_bytes(4)),
                'type' => $fd['type'], 'label' => $fd['label'], 'name' => $fd['key'], 'placeholder' => $fd['placeholder'], 'required' => $fd['required'],
                'width' => $fd['width'], 'options' => $fd['text'], 'help' => $fd['help'], 'default' => $fd['default'], 'min' => $fd['min'], 'max' => $fd['max'],
                'pattern' => $fd['pattern'], 'pattern_msg' => $fd['pattern_msg'], 'accept' => implode(', ', $fd['accept']), 'max_size' => $fd['max_size'] ? (string)$fd['max_size'] : '',
                'cond' => $fd['cond']];
        }
        $st = Forms::settings($form);
        $raw = (array)$form['fields'];
        // Settings as stored (empty = default) so the builder can show defaults as placeholders.
        $settings = [];
        foreach (array_keys($st) as $k) {
            $settings[$k] = is_bool($st[$k]) ? $st[$k] : (string)($raw[$k] ?? '');
        }
        $stats = Forms::stats()[$id] ?? ['total' => 0, 'unread' => 0];
        $config = [
            'id' => $id, 'title' => $form['title'], 'slug' => $form['slug'], 'status' => $form['status'],
            'fields' => $rows, 'settings' => $settings, 'defaults' => $st,
            'types' => Forms::FIELD_TYPES, 'uploadTypes' => implode(', ', Submit::allowedTypes()), 'uploadMax' => round(Submit::maxSize([]) / 1048576, 1),
            'entries' => $stats['total'], 'unread' => $stats['unread'],
            'urls' => ['save' => admin_url('m/forms/save'), 'test' => admin_url('m/forms/action-test'), 'retry' => admin_url('m/forms/delivery-retry'), 'preview' => admin_url('m/forms/preview?id=' . $id), 'list' => self::listUrl(),
                'entries' => Inbox::url(['f' => ['form' => $id]]), 'settings' => AdminUi::settingsUrl(self::MODULE)],
            'adminEmail' => (string)\Slate\Options::get('forms_notify_to', '') ?: (string)\Slate\Options::get('admin_email'),
            'actions' => Actions::forBuilder($form),
            'actionTypes' => Actions::TYPES,
            'deliveries' => Actions::recent($id),
            'captcha' => ['providers' => Captcha::PROVIDERS, 'ready' => array_map(static fn($p) => Captcha::configured((string)$p), array_combine(array_keys(Captcha::PROVIDERS), array_keys(Captcha::PROVIDERS)))],
        ];
        echo self::assets(true);
        echo '<div class="sfb" data-sfb><script type="application/json" data-sfb-config>' . json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . '</script>'
            . '<div class="sfb-loading muted">Loading the form builder…</div></div>';
    }

    /** POST JSON {id, title, status, fields, settings} from the builder. */
    public static function save(): never
    {
        Auth::require(self::CAP);
        $in = json_input() ?: $_POST;
        $id = (int)($in['id'] ?? 0);
        $form = Posts::find($id);
        if (!$form || $form['type'] !== Forms::TYPE) {
            json_response(['ok' => false, 'error' => 'Form not found.'], 404);
        }
        $title = trim(strip_tags((string)($in['title'] ?? '')));
        if ($title === '') {
            json_response(['ok' => false, 'error' => 'Give the form a name.'], 422);
        }
        $fields = self::cleanFields((array)($in['fields'] ?? []));
        $settings = self::cleanSettings((array)($in['settings'] ?? []));
        $status = ($in['status'] ?? '') === 'draft' ? 'draft' : 'publish';
        $data = ['form_fields' => $fields] + $settings;
        if (isset($in['actions']) && is_array($in['actions'])) {
            [$actions, $err] = Actions::clean($in['actions'], Actions::of($form), array_column($fields, 'name'));
            if ($err !== null) {
                json_response(['ok' => false, 'error' => $err], 422);
            }
            $data['actions'] = $actions;
        }
        Posts::save(['title' => mb_substr($title, 0, 190), 'status' => $status, 'fields' => $data], $id);
        PageCache::clear(); // the form can be on any page
        log_activity('form_saved', $title);
        $f = Posts::find($id);
        json_response(['ok' => true, 'id' => $id, 'slug' => $f['slug'] ?? '', 'status' => $f['status'] ?? $status, 'message' => 'Form saved.',
            'actions' => $f ? Actions::forBuilder($f) : []]);
    }

    /** Whitelist and clean the field rows sent by the builder. */
    public static function cleanFields(array $rows): array
    {
        $out = [];
        $taken = [];
        $str = static fn($v, int $max): string => mb_substr(trim(str_replace("\0", '', is_scalar($v) ? (string)$v : '')), 0, $max);
        foreach (array_slice(array_values($rows), 0, 150) as $r) {
            if (!is_array($r)) {
                continue;
            }
            $type = (string)($r['type'] ?? 'text');
            if (!isset(Forms::FIELD_TYPES[$type])) {
                continue;
            }
            $label = strip_tags($str($r['label'] ?? '', 300));
            $name = strtolower($str($r['name'] ?? '', 60));
            $name = trim((string)preg_replace('/[^a-z0-9_-]+/', '_', $name), '_');
            if ($name === '' || in_array($name, $taken, true)) {
                $name = Forms::keyFor($label !== '' ? $label : $type, $taken);
            }
            $taken[] = $name;
            $pattern = $str($r['pattern'] ?? '', 300);
            $exts = array_map(static fn($x) => strtolower((string)preg_replace('/[^a-z0-9]/i', '', $x)), explode(',', $str($r['accept'] ?? '', 300)));
            $accept = array_values(array_diff(array_unique(array_filter($exts)), Forms::BLOCKED_EXT));
            $row = [
                'id' => preg_match('/^[a-z0-9]{1,20}$/i', (string)($r['id'] ?? '')) ? (string)$r['id'] : 'f' . bin2hex(random_bytes(4)),
                'label' => $label, 'type' => $type, 'name' => $name,
                'placeholder' => strip_tags($str($r['placeholder'] ?? '', 300)),
                'required' => !empty($r['required']) ? '1' : '',
                'width' => in_array((string)($r['width'] ?? ''), ['50', '33', '66'], true) ? (string)$r['width'] : '100',
                'options' => $str($r['options'] ?? '', 20000), // HTML (paragraph / consent) is sanitised when shown
                'help' => strip_tags($str($r['help'] ?? '', 500)),
                'default' => strip_tags($str($r['default'] ?? '', 1000)),
                'min' => (string)preg_replace('/[^0-9.:\-]/', '', $str($r['min'] ?? '', 20)),
                'max' => (string)preg_replace('/[^0-9.:\-]/', '', $str($r['max'] ?? '', 20)),
                'pattern' => Submit::validPattern($pattern) ? $pattern : '',
                'pattern_msg' => strip_tags($str($r['pattern_msg'] ?? '', 200)),
                'accept' => implode(', ', $accept),
                'max_size' => is_numeric($r['max_size'] ?? null) && (float)$r['max_size'] > 0 ? (string)min(100, (float)$r['max_size']) : '',
            ];
            $c = $r['cond'] ?? null;
            if (is_array($c) && trim((string)($c['field'] ?? '')) !== '') {
                $row['cond'] = ['field' => $str($c['field'], 60), 'op' => in_array($c['op'] ?? '', ['is', 'not', 'contains', 'empty', 'filled'], true) ? (string)$c['op'] : 'is',
                    'value' => strip_tags($str($c['value'] ?? '', 300)), 'action' => ($c['action'] ?? '') === 'hide' ? 'hide' : 'show'];
            }
            $out[] = $row;
        }
        // Conditions may only point at another field of this form.
        foreach ($out as &$row) {
            if (isset($row['cond']) && ($row['cond']['field'] === $row['name'] || !in_array($row['cond']['field'], $taken, true))) {
                unset($row['cond']);
            }
        }
        unset($row);
        return $out;
    }

    public static function cleanSettings(array $s): array
    {
        $str = static fn(string $k, int $max): string => mb_substr(trim(str_replace("\0", '', is_scalar($s[$k] ?? null) ? (string)$s[$k] : '')), 0, $max);
        $redirect = $str('redirect_url', 700);
        if ($redirect !== '' && !preg_match('#^(https?://[^\s]+|/[^\s]*)$#i', $redirect)) {
            $redirect = str_starts_with($redirect, '/') ? $redirect : '';
        }
        $notify = $str('notify_to', 1000);
        if (strtolower($notify) !== 'none') {
            $notify = implode(', ', array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', $notify) ?: []), static fn($x) => filter_var($x, FILTER_VALIDATE_EMAIL))));
        } else {
            $notify = 'none';
        }
        $close = $str('close_at', 16);
        if ($close !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2})?$/', $close)) {
            $close = '';
        }
        return [
            'submit_label' => strip_tags($str('submit_label', 80)),
            'success_message' => $str('success_message', 3000),
            'error_message' => $str('error_message', 1000),
            'note' => $str('note', 2000),
            'redirect_url' => $redirect,
            'notify_to' => $notify,
            'email_subject' => strip_tags($str('email_subject', 200)),
            'style' => ($s['style'] ?? '') === 'theme' ? 'theme' : 'slate',
            'css_class' => trim((string)preg_replace('/[^\w\s-]/', '', $str('css_class', 200))),
            'confirm_enabled' => !empty($s['confirm_enabled']) ? '1' : '',
            'confirm_subject' => strip_tags($str('confirm_subject', 200)),
            'confirm_message' => strip_tags($str('confirm_message', 5000)),
            'max_entries' => (string)max(0, (int)($s['max_entries'] ?? 0) ?: 0) ?: '',
            'close_at' => str_replace('T', ' ', $close),
            'closed_message' => strip_tags($str('closed_message', 1000)),
            'next_label' => strip_tags($str('next_label', 40)),
            'back_label' => strip_tags($str('back_label', 40)),
            'store_entries' => !array_key_exists('store_entries', $s) || !in_array($s['store_entries'], [false, 0, '0', ''], true) ? '1' : '0',
            'captcha' => isset(Captcha::PROVIDERS[(string)($s['captcha'] ?? '')]) ? (string)($s['captcha'] ?? '') : '',
            'captcha_question' => strip_tags($str('captcha_question', 200)),
            'captcha_answer' => strip_tags($str('captcha_answer', 300)),
        ];
    }

    // ------------------------------------------------------------------ routes

    public static function routes(Router $r): void
    {
        $r->post('/m/forms/save', [self::class, 'save']);

        // Send one action now with sample answers (the builder's "Send test"), unsaved changes included.
        $r->post('/m/forms/action-test', static function (): never {
            Auth::require(self::CAP);
            $in = json_input() ?: $_POST;
            $form = Posts::find((int)($in['id'] ?? 0));
            if (!$form || $form['type'] !== Forms::TYPE) {
                json_response(['ok' => false, 'error' => 'Form not found.'], 404);
            }
            $keys = array_column(Forms::fields($form), 'key');
            [$list, $err] = Actions::clean([(array)($in['action'] ?? [])], Actions::of($form), $keys);
            if ($err !== null || !$list) {
                json_response(['ok' => false, 'error' => $err ?? 'Nothing to test.'], 422);
            }
            [$ok, $msg] = Actions::test($form, $list[0]);
            json_response(['ok' => $ok, 'message' => $msg, 'error' => $ok ? null : $msg, 'deliveries' => Actions::recent((int)$form['id'])]);
        });
        $r->post('/m/forms/delivery-retry', static function (): never {
            Auth::require(self::CAP);
            $id = (int)input('delivery', 0);
            $row = DB::row('SELECT form_id FROM {{form_deliveries}} WHERE id = :id', ['id' => $id]);
            if (!$row) {
                json_response(['ok' => false, 'error' => 'Delivery not found.'], 404);
            }
            Actions::retry($id);
            json_response(['ok' => true, 'deliveries' => Actions::recent((int)$row['form_id'])]);
        });

        // New form from a template: the template cards (redirect to the builder) and the editor picker (JSON).
        $r->post('/m/forms/create', static function (): never {
            Auth::require(self::CAP);
            $id = Forms::create((string)input('title', ''), (string)input('template', 'blank'));
            PageCache::clear();
            if (is_ajax()) {
                $f = Posts::find($id);
                json_response(['ok' => true, 'form' => self::pickerItem($f, [])]);
            }
            redirect(Forms::editUrl($id));
        });

        // Forms for the editor's "Insert a form" picker.
        $r->get('/m/forms/picker', static function (): never {
            Auth::require('edit_posts');
            $stats = Forms::stats();
            $items = [];
            foreach (Posts::query(['type' => Forms::TYPE, 'status' => 'any', 'limit' => 500, 'count' => false, 'orderby' => 'updated_at', 'order' => 'DESC'])['items'] as $f) {
                $items[] = self::pickerItem($f, $stats);
            }
            $tpl = [];
            foreach (Forms::templates() as $k => [$title, $desc]) {
                $tpl[] = ['key' => $k, 'title' => $title, 'description' => $desc];
            }
            json_response(['ok' => true, 'items' => $items, 'templates' => $tpl, 'can_create' => Auth::can(self::CAP), 'list_url' => self::listUrl()]);
        });

        // The real form, alone on a page with the site's design variables (builder and picker previews).
        $r->get('/m/forms/preview', static function (): never {
            Auth::require('edit_posts');
            $id = (int)($_GET['id'] ?? 0);
            $form = Forms::find((string)$id, false);
            header('Content-Type: text/html; charset=utf-8');
            header('X-Frame-Options: SAMEORIGIN');
            $body = $form ? Forms::render($id, ['preview' => true]) : '<p>Form not found.</p>';
            $embed = !empty($_GET['embed']);
            echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">'
                . '<title>Preview: ' . e($form['title'] ?? 'Form') . '</title>' . \Slate\DesignSystem::head()
                . '<style>body{margin:0;padding:' . ($embed ? '20px' : '48px 20px') . ';font:var(--type-body,16px/1.6 system-ui,sans-serif);font-family:var(--font-body,system-ui,sans-serif);color:var(--color-text,#1f1f1f);background:var(--color-background,#fff)}'
                . 'main{max-width:720px;margin:0 auto}.pv-bar{max-width:720px;margin:0 auto 24px;padding:10px 14px;border-radius:10px;background:#FFF5EF;color:#8C3312;font:14px/1.4 system-ui,sans-serif}</style></head><body>'
                . ($embed ? '' : '<div class="pv-bar">Preview' . ($form && $form['status'] !== 'publish' ? ' of a draft: publish the form to accept entries.' : '. Entries sent from here are real.') . '</div>')
                . '<main>' . ($embed ? '' : '<h1 style="font:var(--type-h3,600 28px/1.2 sans-serif);margin:0 0 20px">' . e($form['title'] ?? '') . '</h1>') . $body . '</main></body></html>';
            exit;
        });

        // Download an uploaded file (never served directly).
        $r->get('/m/forms/file', static function (): never {
            Auth::require(self::CAP);
            $s = DB::row('SELECT * FROM {{form_submissions}} WHERE id = :id', ['id' => (int)($_GET['id'] ?? 0)]);
            $key = (string)($_GET['key'] ?? '');
            $row = null;
            foreach ($s ? Forms::rows($s) : [] as $r) {
                if ($r['key'] === $key && $r['file']) {
                    $row = $r;
                }
            }
            $path = $row ? Forms::filePath($row['file']) : null;
            if (!$path) {
                http_response_code(404);
                exit('File not found.');
            }
            $name = $row['value'] !== '' ? $row['value'] : 'file';
            header('Content-Type: application/octet-stream');
            header('X-Content-Type-Options: nosniff');
            header('Content-Disposition: attachment; filename="' . preg_replace('/[^\w.\- ]+/', '_', $name) . '"; filename*=UTF-8\'\'' . rawurlencode($name));
            header('Content-Length: ' . filesize($path));
            header('Cache-Control: private, no-store');
            readfile($path);
            exit;
        });
    }

    private static function pickerItem(?array $f, array $stats): array
    {
        if (!$f) {
            return [];
        }
        $fields = Forms::fields($f);
        return ['id' => (int)$f['id'], 'title' => $f['title'] ?: '(no title)', 'slug' => $f['slug'], 'status' => $f['status'],
            'fields' => count(array_filter($fields, static fn($x) => !in_array($x['type'], Forms::DISPLAY_TYPES, true))),
            'labels' => array_slice(array_values(array_filter(array_map(static fn($x) => in_array($x['type'], Forms::DISPLAY_TYPES, true) ? '' : $x['label'], $fields))), 0, 6),
            'entries' => (int)($stats[(int)$f['id']]['total'] ?? 0),
            'updated' => local_date($f['updated_at'], 'M j, Y'),
            'edit_url' => Forms::editUrl((int)$f['id']),
            'preview_url' => admin_url('m/forms/preview?embed=1&id=' . (int)$f['id'])];
    }
}
