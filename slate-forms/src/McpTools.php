<?php
declare(strict_types=1);

namespace SlateModules\Forms;

use Slate\Auth;
use Slate\DB;
use Slate\Options;
use Slate\PageCache;
use Slate\Posts;
use Slate\Revisions;
use Slate\ToolError;

/**
 * Forms tools for Slate's MCP server (core "mcp_tools" filter): list, read, create and change forms with the same cleaning
 * as the visual builder (Admin::cleanFields / cleanSettings), and entry counts. The entries themselves (personal data) are
 * only readable when the site allows it under the Forms settings ("Let AI agents read entries"), and never with IP
 * addresses or browser details.
 */
final class McpTools
{
    public static function register(array $tools): array
    {
        $str = static fn(int $max, string $desc = ''): array => array_filter(['type' => 'string', 'maxLength' => $max, 'description' => $desc ?: null]);
        $field = ['type' => 'object', 'additionalProperties' => false, 'required' => ['type', 'label'], 'properties' => [
            'type' => ['type' => 'string', 'enum' => array_keys(Forms::FIELD_TYPES), 'description' => 'heading, html (paragraph) and step (page break) only show something.'],
            'label' => $str(300),
            'name' => $str(60, 'Key of the value in entries and email tags ({name}); made from the label when empty.'),
            'required' => ['type' => 'boolean'],
            'placeholder' => $str(300),
            'options' => ['type' => ['string', 'array'], 'maxItems' => 200, 'items' => ['type' => 'string', 'maxLength' => 300],
                'description' => 'Choices of select/radio/checkbox ("value : Label" or plain), the text of consent and html fields.'],
            'width' => ['type' => 'string', 'enum' => ['100', '66', '50', '33']],
            'help' => $str(500), 'default' => $str(1000),
            'min' => $str(20), 'max' => $str(20), 'pattern' => $str(300, 'Regular expression the value must match.'), 'pattern_msg' => $str(200),
            'accept' => $str(300, 'File fields: allowed extensions, comma separated.'), 'max_size' => ['type' => 'number', 'description' => 'File fields: MB.'],
            'cond' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['field'], 'properties' => [
                'field' => $str(60, 'name of another field'), 'op' => ['type' => 'string', 'enum' => ['is', 'not', 'contains', 'empty', 'filled']],
                'value' => $str(300), 'action' => ['type' => 'string', 'enum' => ['show', 'hide']]], 'description' => 'Show or hide this field depending on another one.'],
        ]];
        $settings = ['type' => 'object', 'additionalProperties' => false, 'description' => 'Messages, emails and limits; send only what changes.', 'properties' => [
            'submit_label' => $str(80), 'success_message' => $str(3000), 'error_message' => $str(1000), 'note' => $str(2000, 'Small text under the form.'),
            'redirect_url' => $str(700, 'Go to this address after sending instead of showing the success message.'),
            'notify_to' => $str(1000, 'Notification email addresses, comma separated; empty = the Forms default; "none" = no email.'),
            'email_subject' => $str(200, 'Tags: {form} {page} and {field_name}.'),
            'style' => ['type' => 'string', 'enum' => ['slate', 'theme'], 'description' => 'slate = the module\'s form styles, theme = only the site\'s own CSS.'],
            'css_class' => $str(200),
            'confirm_enabled' => ['type' => 'boolean', 'description' => 'Send a confirmation email to the visitor (needs an email field).'],
            'confirm_subject' => $str(200), 'confirm_message' => $str(5000, 'Plain text; {all_fields} {site} and field tags work.'),
            'max_entries' => ['type' => 'integer', 'minimum' => 0, 'description' => '0 = no limit.'],
            'close_at' => $str(16, 'YYYY-MM-DD or YYYY-MM-DD HH:MM; the form closes then.'), 'closed_message' => $str(1000),
            'next_label' => $str(40), 'back_label' => $str(40),
        ]];
        $id = ['type' => 'integer', 'minimum' => 1, 'description' => 'Form id (list_forms).'];
        $obj = static fn(array $props, array $req = []): array => ['type' => 'object', 'additionalProperties' => false, 'properties' => $props ?: new \stdClass()] + ($req ? ['required' => $req] : []);

        $tools['list_forms'] = ['group' => 'Forms', 'title' => 'List forms', 'cap' => Admin::CAP, 'handler' => [self::class, 'listForms'],
            'description' => 'Forms of the site with their fields count, entry counts and how to place them ({{form:slug}} in pages and templates, [form slug="…"] in content).',
            'inputSchema' => $obj([])];
        $tools['get_form'] = ['group' => 'Forms', 'title' => 'Get a form', 'cap' => Admin::CAP, 'handler' => [self::class, 'getForm'],
            'description' => 'One form in full: fields, settings (messages, emails, limits), status, entry count and embed codes.',
            'inputSchema' => $obj(['id' => $id], ['id'])];
        $tools['save_form'] = ['group' => 'Forms', 'title' => 'Create or change a form', 'write' => true, 'cap' => Admin::CAP, 'handler' => [self::class, 'saveForm'],
            'description' => 'Create a form (from a template: ' . implode(', ', array_keys(Forms::templates())) . ', or your own fields) or change one by id: title, fields (replace all fields when sent), '
                . 'settings (only the keys sent change), status. Forms are validated like in the form builder; changes keep a revision. Place it with {{form:slug}} in a page (write_page) or template.',
            'inputSchema' => $obj(['id' => $id, 'title' => $str(190), 'template' => ['type' => 'string', 'enum' => array_keys(Forms::templates()), 'description' => 'Starting point for a new form.'],
                'fields' => ['type' => 'array', 'maxItems' => 150, 'items' => $field], 'settings' => $settings,
                'status' => ['type' => 'string', 'enum' => ['publish', 'draft'], 'description' => 'Only published forms are shown on the site. Default publish (draft if the user may not publish).']])];
        $tools['list_form_entries'] = ['group' => 'Forms', 'title' => 'Form entries', 'cap' => Admin::CAP, 'handler' => [self::class, 'listEntries'],
            'description' => 'Entry counts per form (new, read, spam, last entry). The entries themselves are personal data: they are only included when the site allows AI agents to read them (Forms settings).',
            'inputSchema' => $obj(['form_id' => $id, 'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'description' => 'Entries to include when allowed (default 20).']])];
        return $tools;
    }

    /** Older cores ignore 'cap' on module tools: check here too. */
    private static function allowed(): void
    {
        if (!Auth::can(Admin::CAP)) {
            throw new ToolError('The user of this key may not manage forms (needs ' . Admin::CAP . ').');
        }
    }

    private static function find(int $id): array
    {
        self::allowed();
        $f = Posts::find($id);
        if (!$f || $f['type'] !== Forms::TYPE || $f['status'] === 'trash') {
            throw new ToolError("No form with id $id.");
        }
        return $f;
    }

    private static function embed(array $f): array
    {
        return ['template_tag' => '{{form:' . $f['slug'] . '}}', 'shortcode' => '[form slug="' . $f['slug'] . '"]'];
    }

    public static function listForms(array $a): array
    {
        self::allowed();
        Forms::install();
        $stats = Forms::stats();
        $out = [];
        foreach (Posts::query(['type' => Forms::TYPE, 'status' => 'any', 'limit' => 200, 'orderby' => 'title', 'order' => 'ASC'])['items'] as $f) {
            $out[] = ['id' => (int)$f['id'], 'title' => $f['title'], 'slug' => $f['slug'], 'status' => $f['status'], 'fields' => count(Forms::fields($f)),
                'entries' => $stats[(int)$f['id']]['total'] ?? 0, 'unread' => $stats[(int)$f['id']]['unread'] ?? 0, 'embed' => self::embed($f)];
        }
        return ['forms' => $out, 'templates' => array_map(static fn($t) => $t[0] . ': ' . $t[1], Forms::templates())];
    }

    public static function getForm(array $a): array
    {
        $f = self::find((int)$a['id']);
        $fields = array_map(static fn($r) => array_filter($r, static fn($v) => $v !== '' && $v !== null && $v !== []), (array)($f['fields']['form_fields'] ?? []));
        return ['id' => (int)$f['id'], 'title' => $f['title'], 'slug' => $f['slug'], 'status' => $f['status'], 'fields' => array_values($fields),
            'settings' => Forms::settings($f), 'entries' => Forms::entryCount((int)$f['id']), 'closed' => Forms::closedReason($f), 'embed' => self::embed($f),
            'builder_url' => Forms::editUrl((int)$f['id'])];
    }

    public static function saveForm(array $a): array
    {
        self::allowed();
        Forms::install();
        $canPublish = Auth::can('publish_posts');
        $status = ($a['status'] ?? 'publish') === 'draft' || !$canPublish ? 'draft' : 'publish';
        $created = false;
        if (isset($a['id'])) {
            $f = self::find((int)$a['id']);
        } else {
            $title = trim(strip_tags((string)($a['title'] ?? '')));
            $id = Forms::create($title, (string)($a['template'] ?? 'blank'), $status);
            $f = Posts::find($id);
            $created = true;
        }
        $title = trim(strip_tags((string)($a['title'] ?? $f['title'])));
        if ($title === '') {
            throw new ToolError('Give the form a name.');
        }
        $rows = (array)($f['fields']['form_fields'] ?? []);
        if (isset($a['fields'])) {
            $rows = array_map(static function (array $r): array {
                if (is_array($r['options'] ?? null)) {
                    $r['options'] = implode("\n", array_map('strval', $r['options']));
                }
                $r['required'] = !empty($r['required']) ? '1' : '';
                return $r;
            }, $a['fields']);
        }
        $fields = Admin::cleanFields($rows);
        $settings = Admin::cleanSettings((array)($a['settings'] ?? []) + Forms::settings($f));
        $data = ['title' => mb_substr($title, 0, 190), 'status' => isset($a['status']) || $created ? $status : $f['status'], 'fields' => ['form_fields' => $fields] + $settings];
        if (!$created) {
            Revisions::snapshot($f, $data);
        }
        Posts::save($data, (int)$f['id']);
        PageCache::clear(); // the form can be on any page
        log_activity('mcp_form_saved', $title);
        $warnings = isset($a['fields']) && count($fields) < count($a['fields']) ? ['Some fields were skipped (unknown type).'] : [];
        return ['saved' => true, 'created' => $created] + self::getForm(['id' => (int)$f['id']]) + ($warnings ? ['warnings' => $warnings] : [])
            + ($status === 'draft' && !$canPublish ? ['note' => 'Saved as a draft: the user of this key may not publish, so the form is not shown yet.'] : []);
    }

    public static function listEntries(array $a): array
    {
        self::allowed();
        Forms::install();
        $where = '';
        $params = [];
        if (isset($a['form_id'])) {
            self::find((int)$a['form_id']);
            $where = ' WHERE form_id = :f';
            $params['f'] = (int)$a['form_id'];
        }
        $counts = [];
        foreach (DB::all("SELECT form_id, form_title, status, COUNT(*) n, MAX(created_at) last FROM {{form_submissions}}$where GROUP BY form_id, form_title, status", $params) as $r) {
            $k = (int)$r['form_id'];
            $counts[$k] ??= ['form_id' => $k ?: null, 'form' => (string)$r['form_title'], 'new' => 0, 'read' => 0, 'spam' => 0, 'last_entry' => null];
            $counts[$k][$r['status']] = ($counts[$k][$r['status']] ?? 0) + (int)$r['n'];
            if ($r['status'] !== 'spam' && (string)$r['last'] > (string)$counts[$k]['last_entry']) {
                $counts[$k]['last_entry'] = (string)$r['last'];
            }
        }
        $out = ['counts' => array_values($counts)];
        if (!Options::get('forms_mcp_entries', false)) {
            $out['entries_note'] = 'Entries are personal data and are not shared with AI agents on this site (Forms settings: "Let AI agents read entries").';
            return $out;
        }
        $limit = (int)($a['limit'] ?? 20);
        $rows = DB::all("SELECT id, form_id, form_title, page_url, page_title, data, status, created_at FROM {{form_submissions}}"
            . ($where !== '' ? $where . " AND status <> 'spam'" : " WHERE status <> 'spam'") . " ORDER BY id DESC LIMIT $limit", $params);
        $out['entries'] = array_map(static fn($s) => ['id' => (int)$s['id'], 'form_id' => (int)$s['form_id'], 'form' => (string)$s['form_title'], 'status' => $s['status'],
            'received_at' => (string)$s['created_at'], 'page' => (string)$s['page_url'],
            'values' => array_map(static fn($r) => ['label' => $r['label'], 'value' => $r['file'] ? '(file upload)' : $r['value']], Forms::rows($s))], $rows);
        return $out;
    }
}
