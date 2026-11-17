<?php
declare(strict_types=1);

namespace SlateModules\WpImport;

use Slate\DB;
use Slate\Posts;

/**
 * WordPress form plugins → Slate Forms (module "slate-forms").
 * Readers turn Contact Form 7, WPForms, Gravity Forms, Fluent Forms and Elementor Pro forms into one normalized shape:
 *   ['plugin', 'id', 'title', 'fields' => [['label','type','key','required','placeholder','choices' => [value => label],'help','default']],
 *    'notify_to', 'subject', 'submit', 'success']
 * and save() is the single adapter to the Forms module's storage (see save() for the assumptions about its format).
 */
final class FormsMap
{
    /** Normalized type → Slate Forms field type. */
    private const TYPES = ['text' => 'text', 'email' => 'email', 'tel' => 'tel', 'phone' => 'tel', 'number' => 'number', 'textarea' => 'textarea',
        'select' => 'select', 'radio' => 'radio', 'checkbox' => 'checkbox', 'date' => 'date', 'time' => 'time', 'url' => 'url', 'file' => 'file',
        'consent' => 'consent', 'hidden' => 'hidden', 'heading' => 'heading', 'html' => 'html', 'step' => 'step'];

    public static function available(): bool
    {
        return class_exists(\SlateModules\Forms\Forms::class);
    }

    // ------------------------------------------------------------------ readers

    /** Every form found in the staged data and (for Contact Form 7) through the REST API. */
    public static function all(?\SlateModules\AutoCpt\WpClient $wp = null): array
    {
        $out = [];
        foreach (Data::rows('wpost') as $r) {
            $f = match ($r['type'] ?? '') {
                'wpcf7_contact_form' => self::cf7((int)$r['id'], (string)$r['title'], Data::metaDecoded((int)$r['id']), (string)$r['content']),
                'wpforms' => self::wpforms((int)$r['id'], (string)$r['title'], (string)$r['content']),
                default => null,
            };
            if ($f && ($r['status'] ?? 'publish') !== 'trash') {
                $out[$f['plugin'] . ':' . $f['id']] = $f;
            }
        }
        $gfMeta = Data::rows('table:gf_form_meta');
        foreach (Data::rows('table:gf_form') as $id => $row) {
            if (!empty($row['is_trash'])) {
                continue;
            }
            $m = $gfMeta[$id] ?? [];
            $out['gf:' . $id] = self::gravity((int)$id, (string)($row['title'] ?? 'Form'), (array)(json_decode((string)($m['display_meta'] ?? ''), true) ?: []),
                (array)(json_decode((string)($m['notifications'] ?? ''), true) ?: []), (array)(json_decode((string)($m['confirmations'] ?? ''), true) ?: []));
        }
        $ffMeta = [];
        foreach (Data::rows('table:fluentform_form_meta') as $row) {
            $ffMeta[(int)($row['form_id'] ?? 0)][(string)($row['meta_key'] ?? '')] = (string)($row['value'] ?? '');
        }
        foreach (Data::rows('table:fluentform_forms') as $id => $row) {
            $out['fluentform:' . $id] = self::fluent((int)$id, (string)($row['title'] ?? 'Form'), (array)(json_decode((string)($row['form_fields'] ?? ''), true) ?: []), $ffMeta[(int)$id] ?? []);
        }
        // Elementor Pro forms stored in page data.
        foreach (DB::all("SELECT ref, v FROM {{wpi_data}} WHERE kind = 'postmeta' AND k = '_elementor_data' AND v LIKE '%\"widgetType\":\"form\"%'") as $r) {
            if (!Data::isLive((string)$r['ref'])) {
                continue; // revision copies
            }
            foreach (self::elementorForms((int)$r['ref'], json_decode((string)$r['v'], true) ?: []) as $f) {
                $out['elementor:' . $f['id']] = $f;
            }
        }
        // Contact Form 7 through its REST API (administrator application password), when nothing was staged.
        if ($wp && $wp->authenticated() && !array_filter(array_keys($out), static fn($k) => str_starts_with($k, 'cf7:'))) {
            $list = $wp->get('/contact-form-7/v1/contact-forms', ['per_page' => 100]);
            foreach ($list['ok'] && is_array($list['data']) ? $list['data'] : [] as $c) {
                $one = $wp->get('/contact-form-7/v1/contact-forms/' . (int)$c['id']);
                if ($one['ok'] && is_array($one['data'])) {
                    $p = (array)($one['data']['properties'] ?? []);
                    $out['cf7:' . (int)$c['id']] = self::cf7((int)$c['id'], (string)($c['title'] ?? 'Contact form'), ['_form' => (string)(is_array($p['form'] ?? null) ? ($p['form']['content'] ?? '') : ($p['form'] ?? '')), '_mail' => (array)($p['mail'] ?? []),
                        '_messages' => (array)($p['messages'] ?? []), '_hash' => (string)($one['data']['hash'] ?? '')], '');
                }
            }
        }
        return $out;
    }

    /** Contact Form 7: form-tags in the template, labels from the surrounding <label>. */
    public static function cf7(int $id, string $title, array $meta, string $content): array
    {
        $tpl = (string)($meta['_form'] ?? '');
        if ($tpl === '' && $content !== '') {
            $tpl = $content;
        }
        $fields = [];
        $submit = 'Send';
        // Acceptance: [acceptance name] text [/acceptance] → one tag carrying its text, so the field keeps its place.
        $consent = [];
        $tpl = (string)preg_replace_callback('/\[acceptance\s+([\w-]+)[^\]]*\](.*?)\[\/acceptance\]/s', static function ($m) use (&$consent) {
            $consent[$m[1]] = trim(strip_tags($m[2])) ?: 'I agree';
            return '[acceptance ' . $m[1] . ']';
        }, $tpl);
        preg_match_all('/(<label[^>]*>)?([^\[\]<]*)\[([a-z_]+)(\*?)\s*([^\]]*)\]/i', $tpl, $mm, PREG_SET_ORDER);
        foreach ($mm as $m) {
            $tag = strtolower($m[3]);
            $rest = $m[5];
            if ($tag === 'submit') {
                $submit = preg_match('/"([^"]+)"/', $rest, $s) ? $s[1] : $submit;
                continue;
            }
            $name = preg_match('/^([\w-]+)/', $rest, $n) ? $n[1] : '';
            if ($name === '') {
                continue;
            }
            preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"/', $rest, $q);
            $quoted = $q[1] ?? [];
            $placeholder = '';
            if (preg_match('/\b(?:placeholder|watermark)\b/', $rest)) {
                $placeholder = (string)array_shift($quoted);
            }
            $type = match ($tag) {
                'text', 'email', 'tel', 'url', 'number', 'date', 'textarea', 'select', 'checkbox', 'radio', 'file', 'hidden' => $tag,
                'range' => 'number', 'quiz' => 'text', 'acceptance' => 'consent', 'time' => 'time',
                default => null,
            };
            if ($type === null) {
                continue;
            }
            $choices = [];
            foreach (in_array($type, ['select', 'checkbox', 'radio'], true) ? $quoted : [] as $c) {
                [$label, $value] = str_contains($c, '|') ? explode('|', $c, 2) : [$c, $c];
                $choices[$value] = $label;
            }
            if ($type === 'consent') {
                $fields[] = ['label' => $consent[$name] ?? 'I agree', 'type' => 'consent', 'key' => $name, 'required' => true];
                continue;
            }
            $label = trim(preg_replace('/\s+/', ' ', strip_tags((string)$m[2])));
            $fields[] = ['label' => $label !== '' ? $label : ucfirst(str_replace(['-', '_'], ' ', preg_replace('/^your-/', '', $name))), 'type' => $type, 'key' => $name,
                'required' => $m[4] === '*', 'placeholder' => $placeholder, 'choices' => $choices,
                'default' => $type === 'hidden' ? (string)($quoted[0] ?? '') : ''];
        }
        $mail = is_array($meta['_mail'] ?? null) ? $meta['_mail'] : [];
        $msgs = is_array($meta['_messages'] ?? null) ? $meta['_messages'] : [];
        return ['plugin' => 'cf7', 'id' => (string)$id, 'hash' => substr((string)($meta['_hash'] ?? ''), 0, 7), 'title' => $title ?: 'Contact form', 'fields' => $fields,
            'notify_to' => self::emails((string)($mail['recipient'] ?? '')), 'subject' => self::cf7Tags((string)($mail['subject'] ?? '')), 'submit' => $submit,
            'success' => (string)($msgs['mail_sent_ok'] ?? '')];
    }

    private static function cf7Tags(string $s): string
    {
        $s = strtr($s, ['[_site_title]' => '{site}', '[_post_title]' => '{page}', '[_date]' => '{date}']);
        return (string)preg_replace_callback('/\[([\w-]+)\]/', static fn($m) => '{' . $m[1] . '}', $s);
    }

    /** "admin@x.com, [_site_admin_email]" → valid addresses only. */
    private static function emails(string $s): string
    {
        preg_match_all('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $s, $m);
        return implode(', ', array_unique($m[0]));
    }

    public static function wpforms(int $id, string $title, string $content): array
    {
        $d = json_decode($content, true) ?: [];
        $fields = [];
        foreach ((array)($d['fields'] ?? []) as $f) {
            $t = (string)($f['type'] ?? 'text');
            $type = ['name' => 'text', 'text' => 'text', 'email' => 'email', 'phone' => 'tel', 'number' => 'number', 'number-slider' => 'number', 'textarea' => 'textarea',
                'select' => 'select', 'radio' => 'radio', 'checkbox' => 'checkbox', 'url' => 'url', 'date-time' => 'date', 'file-upload' => 'file', 'hidden' => 'hidden',
                'html' => 'html', 'divider' => 'heading', 'gdpr-checkbox' => 'consent', 'address' => 'textarea', 'pagebreak' => 'step', 'content' => 'html'][$t] ?? null;
            if ($type === null) {
                continue;
            }
            $choices = [];
            foreach ((array)($f['choices'] ?? []) as $c) {
                $l = (string)($c['label'] ?? '');
                $choices[(string)(($c['value'] ?? '') !== '' ? $c['value'] : $l)] = $l;
            }
            $label = (string)($f['label'] ?? '');
            if ($type === 'consent') {
                $label = trim(strip_tags((string)($f['choices'][1]['label'] ?? $f['choices'][0]['label'] ?? $label)));
            }
            $fields[] = ['label' => $label, 'type' => $type, 'key' => '', 'required' => !empty($f['required']), 'placeholder' => (string)($f['placeholder'] ?? ''),
                'choices' => $type === 'consent' ? [] : $choices, 'help' => (string)($f['description'] ?? ''), 'default' => (string)($f['default_value'] ?? ''),
                'text' => in_array($type, ['html', 'heading'], true) ? (string)($f['code'] ?? $f['content'] ?? $f['label'] ?? '') : ''];
        }
        $s = (array)($d['settings'] ?? []);
        $n = (array)(array_values((array)($s['notifications'] ?? []))[0] ?? []);
        $c = (array)(array_values((array)($s['confirmations'] ?? []))[0] ?? []);
        return ['plugin' => 'wpforms', 'id' => (string)$id, 'title' => (string)($s['form_title'] ?? $title ?: 'Form'), 'fields' => $fields,
            'notify_to' => self::emails((string)($n['email'] ?? '')), 'subject' => (string)preg_replace('/\{[^}]+\}/', '', (string)($n['subject'] ?? '')),
            'submit' => (string)($s['submit_text'] ?? 'Submit'), 'success' => trim(strip_tags((string)($c['message'] ?? '')))];
    }

    public static function gravity(int $id, string $title, array $meta, array $notes, array $confirms): array
    {
        $fields = [];
        foreach ((array)($meta['fields'] ?? []) as $f) {
            $t = (string)($f['type'] ?? 'text');
            $type = ['text' => 'text', 'email' => 'email', 'phone' => 'tel', 'number' => 'number', 'textarea' => 'textarea', 'select' => 'select', 'multiselect' => 'checkbox',
                'radio' => 'radio', 'checkbox' => 'checkbox', 'website' => 'url', 'date' => 'date', 'time' => 'time', 'fileupload' => 'file', 'hidden' => 'hidden',
                'html' => 'html', 'section' => 'heading', 'name' => 'text', 'address' => 'textarea', 'consent' => 'consent', 'page' => 'step'][$t] ?? null;
            if ($type === null) {
                continue;
            }
            $choices = [];
            foreach ((array)($f['choices'] ?? []) as $c) {
                $choices[(string)($c['value'] ?? $c['text'] ?? '')] = (string)($c['text'] ?? '');
            }
            $fields[] = ['label' => (string)($type === 'consent' ? ($f['checkboxLabel'] ?? $f['label'] ?? '') : ($f['label'] ?? '')), 'type' => $type, 'key' => 'field_' . ($f['id'] ?? count($fields)),
                'required' => !empty($f['isRequired']), 'placeholder' => (string)($f['placeholder'] ?? ''), 'choices' => $type === 'consent' ? [] : $choices,
                'help' => strip_tags((string)($f['description'] ?? '')), 'default' => (string)($f['defaultValue'] ?? ''), 'text' => (string)($f['content'] ?? '')];
        }
        $n = (array)(array_values($notes)[0] ?? []);
        $c = (array)(array_values($confirms)[0] ?? []);
        return ['plugin' => 'gf', 'id' => (string)$id, 'title' => $title, 'fields' => $fields, 'notify_to' => self::emails((string)($n['to'] ?? '')),
            'subject' => (string)preg_replace('/\{[^}]+\}/', '', (string)($n['subject'] ?? '')), 'submit' => (string)($meta['button']['text'] ?? 'Submit'),
            'success' => trim(strip_tags((string)($c['message'] ?? '')))];
    }

    public static function fluent(int $id, string $title, array $def, array $meta): array
    {
        $fields = [];
        $walk = static function (array $list) use (&$walk, &$fields): void {
            foreach ($list as $f) {
                $el = (string)($f['element'] ?? '');
                if ($el === 'container') {
                    foreach ((array)($f['columns'] ?? []) as $col) {
                        $walk((array)($col['fields'] ?? []));
                    }
                    continue;
                }
                if ($el === 'input_name') {
                    foreach ((array)($f['fields'] ?? []) as $sub) {
                        if (($sub['settings']['visible'] ?? true)) {
                            $walk([$sub + ['element' => 'input_text']]);
                        }
                    }
                    continue;
                }
                $type = ['input_text' => 'text', 'input_email' => 'email', 'phone' => 'tel', 'input_number' => 'number', 'textarea' => 'textarea', 'select' => 'select',
                    'input_radio' => 'radio', 'input_checkbox' => 'checkbox', 'input_url' => 'url', 'input_date' => 'date', 'input_file' => 'file', 'input_hidden' => 'hidden',
                    'custom_html' => 'html', 'section_break' => 'heading', 'terms_and_condition' => 'consent', 'gdpr_agreement' => 'consent', 'form_step' => 'step'][$el] ?? null;
                if ($type === null) {
                    continue;
                }
                $st = (array)($f['settings'] ?? []);
                $choices = [];
                foreach ((array)($st['advanced_options'] ?? []) as $o) {
                    $choices[(string)($o['value'] ?? $o['label'] ?? '')] = (string)($o['label'] ?? '');
                }
                $fields[] = ['label' => (string)($type === 'consent' ? strip_tags((string)($st['tnc_html'] ?? $st['label'] ?? 'I agree')) : ($st['label'] ?? $st['label_text'] ?? '')),
                    'type' => $type, 'key' => (string)($f['attributes']['name'] ?? ''), 'required' => !empty($st['validation_rules']['required']['value']),
                    'placeholder' => (string)($f['attributes']['placeholder'] ?? ''), 'choices' => $choices, 'help' => (string)($st['help_message'] ?? ''),
                    'default' => (string)($f['attributes']['value'] ?? ''), 'text' => (string)($st['html_codes'] ?? $st['description'] ?? '')];
            }
        };
        $walk((array)($def['fields'] ?? []));
        $notes = json_decode((string)($meta['notifications'] ?? ''), true);
        $n = is_array($notes) ? (array)($notes['sendTo']['email'] ?? $notes[0]['sendTo']['email'] ?? '') : [];
        return ['plugin' => 'fluentform', 'id' => (string)$id, 'title' => $title, 'fields' => $fields, 'notify_to' => self::emails(implode(',', array_map('strval', $n))),
            'subject' => '', 'submit' => (string)($def['submitButton']['settings']['button_ui']['text'] ?? 'Submit'), 'success' => ''];
    }

    /** Forms inside Elementor page data: id "<post id>:<widget id>". */
    public static function elementorForms(int $postId, array $elements): array
    {
        $out = [];
        $walk = static function (array $els) use (&$walk, &$out, $postId): void {
            foreach ($els as $el) {
                if (!is_array($el)) {
                    continue;
                }
                if (($el['widgetType'] ?? '') === 'form') {
                    $out[] = self::elementor($postId . ':' . ($el['id'] ?? ''), (array)($el['settings'] ?? []));
                }
                $walk((array)($el['elements'] ?? []));
            }
        };
        $walk($elements);
        return $out;
    }

    public static function elementor(string $id, array $s): array
    {
        $fields = [];
        foreach ((array)($s['form_fields'] ?? []) as $f) {
            $t = (string)($f['field_type'] ?? 'text');
            $type = ['text' => 'text', 'email' => 'email', 'tel' => 'tel', 'number' => 'number', 'textarea' => 'textarea', 'select' => 'select', 'radio' => 'radio',
                'checkbox' => 'checkbox', 'url' => 'url', 'date' => 'date', 'time' => 'time', 'upload' => 'file', 'hidden' => 'hidden', 'html' => 'html',
                'acceptance' => 'consent', 'step' => 'step'][$t] ?? null;
            if ($type === null) {
                continue;
            }
            $choices = [];
            foreach (preg_split('/\R/', (string)($f['field_options'] ?? '')) ?: [] as $line) {
                if (trim($line) === '') {
                    continue;
                }
                [$l, $v] = str_contains($line, '|') ? explode('|', $line, 2) : [$line, $line];
                $choices[trim($v)] = trim($l);
            }
            $fields[] = ['label' => (string)($type === 'consent' ? ($f['acceptance_text'] ?? $f['field_label'] ?? '') : ($f['field_label'] ?? '')), 'type' => $type,
                'key' => (string)($f['custom_id'] ?? ''), 'required' => in_array((string)($f['required'] ?? ''), ['true', 'yes', '1'], true),
                'placeholder' => (string)($f['placeholder'] ?? ''), 'choices' => $choices, 'default' => (string)($f['field_value'] ?? ''), 'text' => (string)($f['field_html'] ?? '')];
        }
        return ['plugin' => 'elementor', 'id' => $id, 'title' => (string)($s['form_name'] ?? 'Form'), 'fields' => $fields,
            'notify_to' => self::emails((string)($s['email_to'] ?? '')), 'subject' => (string)preg_replace('/\[[^\]]+\]/', '', (string)($s['email_subject'] ?? '')),
            'submit' => (string)($s['button_text'] ?? 'Send'), 'success' => (string)($s['success_message'] ?? '')];
    }

    // ------------------------------------------------------------------ adapter to the Forms module

    /**
     * Create or update the Slate form for a normalized form. Returns [post id, slug, 'created'|'updated'|'kept'].
     *
     * Storage assumptions (Forms 1.x and 2.0): a post of type "form"; fields['form_fields'] is a list of rows
     * {id, label, type, name, placeholder, required, width, options, help, default}; "options" holds the choices as
     * "value : Label" lines (or the text of a paragraph / consent field); settings are flat fields (submit_label,
     * success_message, notify_to, email_subject). When Forms 2.0 is present its own Admin::cleanFields() /
     * cleanSettings() normalise the rows, so newer keys get their defaults.
     */
    public static function save(array $f, array &$log): array
    {
        $key = $f['plugin'] . ':' . $f['id'];
        $rows = [];
        $taken = [];
        foreach ($f['fields'] as $i => $fd) {
            $type = self::TYPES[$fd['type']] ?? 'text';
            $label = trim((string)$fd['label']);
            $name = strtolower(trim((string)preg_replace('/[^a-z0-9_]+/i', '_', (string)($fd['key'] ?? '')), '_'));
            if ($name === '' || in_array($name, $taken, true)) {
                $name = class_exists(\SlateModules\Forms\Forms::class) ? \SlateModules\Forms\Forms::keyFor($label !== '' ? $label : $type, $taken) : 'field_' . ($i + 1);
            }
            $taken[] = $name;
            $options = '';
            if (!empty($fd['choices'])) {
                $options = implode("\n", array_map(static fn($v, $l) => (string)$v === (string)$l ? (string)$l : $v . ' : ' . $l, array_keys($fd['choices']), $fd['choices']));
            } elseif (in_array($type, ['html', 'heading', 'consent'], true)) {
                $options = (string)($fd['text'] ?? '') !== '' ? (string)$fd['text'] : $label;
            }
            $rows[] = ['id' => 'f' . substr(md5($key . ':' . $name), 0, 8), 'label' => $type === 'html' ? '' : $label, 'type' => $type, 'name' => $name,
                'placeholder' => (string)($fd['placeholder'] ?? ''), 'required' => !empty($fd['required']) ? '1' : '', 'width' => '100',
                'options' => $options, 'help' => strip_tags((string)($fd['help'] ?? '')), 'default' => (string)($fd['default'] ?? '')];
        }
        $settings = ['submit_label' => $f['submit'] ?: 'Send', 'success_message' => $f['success'] ?? '', 'notify_to' => $f['notify_to'] ?? '',
            'email_subject' => trim((string)($f['subject'] ?? '')) ?: 'New entry: {form}'];
        if (class_exists(\SlateModules\Forms\Admin::class) && method_exists(\SlateModules\Forms\Admin::class, 'cleanFields')) {
            $rows = \SlateModules\Forms\Admin::cleanFields($rows);
            $settings = array_filter(\SlateModules\Forms\Admin::cleanSettings($settings + ['style' => 'slate']), static fn($v) => $v !== '');
        }
        $existing = Importer::map('form', $key);
        $post = $existing ? Posts::find($existing) : null;
        $extra = $post ? (json_decode((string)DB::val("SELECT extra FROM {{wpi_map}} WHERE kind = 'form' AND old_id = :o", ['o' => $key]), true) ?: []) : [];
        if ($post && !empty($extra['saved']) && strtotime((string)$post['updated_at']) > strtotime((string)$extra['saved']) + 5) {
            return [(int)$post['id'], (string)$post['slug'], 'kept']; // edited in Slate since the last import
        }
        $id = Posts::save(['type' => 'form', 'title' => mb_substr((string)$f['title'], 0, 190), 'slug' => $post['slug'] ?? slugify((string)$f['title']), 'status' => 'publish',
            'fields' => ['form_fields' => $rows] + $settings], $post ? (int)$post['id'] : null);
        $saved = Posts::find($id);
        Importer::setMapPublic('form', $key, $id, '', ['hash' => $f['hash'] ?? '', 'saved' => (string)$saved['updated_at'], 'plugin' => $f['plugin']]);
        if (!empty($f['hash'])) {
            Importer::setMapPublic('form', $f['plugin'] . ':' . $f['hash'], $id);
        }
        $log[] = ($post ? 'Updated' : 'Created') . ' form “' . $f['title'] . '” (' . count($rows) . ' fields, from ' . self::pluginLabel($f['plugin']) . ')';
        return [$id, (string)$saved['slug'], $post ? 'updated' : 'created'];
    }

    public static function pluginLabel(string $p): string
    {
        return ['cf7' => 'Contact Form 7', 'wpforms' => 'WPForms', 'gf' => 'Gravity Forms', 'fluentform' => 'Fluent Forms', 'elementor' => 'Elementor', 'ninja' => 'Ninja Forms',
            'formidable' => 'Formidable', 'forminator_form' => 'Forminator'][$p] ?? $p;
    }

    /** Slug of the Slate form for a plugin form id (or CF7 hash), creating it on first use when its definition is known. */
    public static function slugFor(string $plugin, string $id, array $known, array &$log): ?string
    {
        if ($id === '') {
            return null;
        }
        $new = Importer::map('form', $plugin . ':' . $id);
        if ($new && ($p = Posts::find($new))) {
            return (string)$p['slug'];
        }
        $def = $known[$plugin . ':' . $id] ?? null;
        if (!$def && $plugin === 'cf7') {
            foreach ($known as $k => $f) {
                if (str_starts_with($k, 'cf7:') && ($f['hash'] ?? '') !== '' && str_starts_with($id, $f['hash'])) {
                    $def = $f;
                    break;
                }
            }
        }
        if (!$def || !self::available()) {
            return null;
        }
        return self::save($def, $log)[1];
    }

    /**
     * Rendered form markup in a page (Contact Form 7, WPForms, Gravity Forms, Fluent Forms) → Slate form widgets.
     * $widget: fn(string slug): string replacement HTML.
     */
    public static function replaceRendered(string $html, callable $slugFor, callable $widget): string
    {
        $patterns = [
            'cf7' => '#<div\b[^>]*class="[^"]*\bwpcf7\b[^"]*"[^>]*id="wpcf7-f(\d+)-[^"]*"[^>]*>#i',
            'wpforms' => '#<div\b[^>]*class="[^"]*\bwpforms-container\b[^"]*"[^>]*id="wpforms-(\d+)"[^>]*>#i',
            'gf' => '#<div\b[^>]*class=[\'"][^\'"]*\bgform_wrapper\b[^\'"]*[\'"][^>]*id=[\'"]gform_wrapper_(\d+)[\'"][^>]*>#i',
            'fluentform' => '#<div\b[^>]*class="[^"]*\bfluentform\b[^"]*fluentform_wrapper_(\d+)[^"]*"[^>]*>#i',
        ];
        foreach ($patterns as $plugin => $re) {
            while (preg_match($re, $html, $m, PREG_OFFSET_CAPTURE)) {
                $start = $m[0][1];
                $end = self::closingDiv($html, $start);
                $slug = $slugFor($plugin, $m[1][0]);
                $html = substr($html, 0, $start) . ($slug ? $widget($slug) : '<!-- form removed -->') . substr($html, $end);
            }
        }
        return $html;
    }

    /** Offset just after the </div> that closes the <div> starting at $start. */
    private static function closingDiv(string $html, int $start): int
    {
        $depth = 0;
        $pos = $start;
        while (preg_match('#<(/?)div\b[^>]*>#i', $html, $m, PREG_OFFSET_CAPTURE, $pos)) {
            $depth += $m[1][0] === '/' ? -1 : 1;
            $pos = $m[0][1] + strlen($m[0][0]);
            if ($depth === 0) {
                return $pos;
            }
        }
        return strlen($html);
    }
}
