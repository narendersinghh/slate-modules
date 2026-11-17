<?php
declare(strict_types=1);

namespace SlateModules\Forms;

use Slate\Auth;
use Slate\Hooks;
use Slate\Sanitizer;
use Slate\Session;

/**
 * Front-end HTML of a form. Accessible markup (labels, fieldsets, described-by help and errors), works without
 * JavaScript (the server validates and sends people back with their answers and inline errors), and styled with
 * the site's design-system variables.
 */
final class Render
{
    private static bool $assetsPrinted = false;
    private static array $instances = [];

    /** CSS class map; a theme can swap these for its own (filter "form_classes"). */
    public static function classes(array $form): array
    {
        $base = ['form' => 'slate-form', 'field' => 'slate-form__field', 'label' => 'slate-form__label', 'input' => 'slate-form__input',
            'textarea' => 'slate-form__input slate-form__textarea', 'select' => 'slate-form__input slate-form__select', 'choice' => 'slate-form__choice',
            'help' => 'slate-form__help', 'button' => 'slate-form__submit', 'success' => 'slate-form__message slate-form__success',
            'error' => 'slate-form__message slate-form__error', 'html' => 'slate-form__text', 'note' => 'slate-form__note'];
        $base = Hooks::filter('form_classes', $base, $form);
        // Per-form overrides (e.g. kept from a migrated design): fields._classes = {success: "…", …}
        foreach ((array)($form['fields']['_classes'] ?? []) as $k => $v) {
            if (isset($base[$k]) && is_string($v)) {
                $base[$k] = trim(($k === 'success' || $k === 'error' ? 'slate-form__message slate-form__' . $k . ' ' : '') . $v);
            }
        }
        return $base;
    }

    /**
     * @param array $opts preview: render drafts too (dashboard previews)
     */
    public static function form(string|int $ref, array $opts = []): string
    {
        $preview = !empty($opts['preview']);
        $form = Forms::find($ref, !$preview);
        if (!$form) {
            return Auth::can('edit_posts') ? '<p class="slate-form-missing">[Form "' . e((string)$ref) . '" not found or not published]</p>' : '';
        }
        $id = (int)$form['id'];
        $st = Forms::settings($form);
        $c = self::classes($form);
        $n = self::$instances[$id] = (self::$instances[$id] ?? 0) + 1;
        $uid = 'sf-' . $id . '-' . $n; // the first form of each id on the page also gets id="sf-<id>" (Forms::replaceTokens)
        $state = self::takeState($id);

        // Sent without JavaScript and came back here: show the thank-you message instead of the form.
        if ($state && !empty($state['ok'])) {
            return '<div id="' . $uid . '" data-slate-form="' . $id . '" class="' . e($c['form'] . ' slate-form--' . $st['style']) . '"><div class="' . e($c['success']) . '" role="status" tabindex="-1">'
                . self::message($st['success_message']) . '</div></div>' . self::assets();
        }
        if (!$preview && ($closed = Forms::closedReason($form)) !== null) {
            return '<div id="' . $uid . '" data-slate-form="' . $id . '" class="' . e($c['form'] . ' slate-form--' . $st['style'] . ' slate-form--closed') . '"><p class="slate-form__message slate-form__closed">'
                . e($closed) . '</p></div>' . self::assets();
        }

        $fields = Forms::fields($form);
        $errors = (array)($state['errors'] ?? []);
        $old = isset($state['old']) ? (array)$state['old'] : null;
        $flat = $st['style'] === 'theme';
        $hasFile = (bool)array_filter($fields, static fn($f) => $f['type'] === 'file');
        $steps = !$flat && array_filter($fields, static fn($f) => $f['type'] === 'step') ? 1 : 0;

        $h = '<form id="' . $uid . '" data-slate-form="' . $id . '" class="' . e(trim($c['form'] . ' slate-form--' . $st['style'] . ' slate-form--' . $form['slug'] . ($steps ? ' slate-form--steps' : '') . ' ' . $st['css_class']))
            . '" method="post" action="' . e(url(ltrim(Forms::ENDPOINT, '/'))) . '"' . ($hasFile ? ' enctype="multipart/form-data"' : '')
            . ' aria-label="' . e($form['title']) . '"'
            . ($st['redirect_url'] !== '' ? ' data-redirect="' . e($st['redirect_url']) . '"' : '')
            . ($steps ? ' data-next="' . e($st['next_label']) . '" data-back="' . e($st['back_label']) . '"' : '') . '>';
        if ($errors) {
            $h .= '<div class="' . e($c['error']) . ' slate-form__summary" role="alert" tabindex="-1">' . self::message($st['error_message']) . '</div>';
        }
        $tail = '';
        if ($steps) {
            $h .= '<div class="slate-form__progress" data-sf-progress hidden></div><fieldset class="slate-form__step" data-sf-step>';
        }
        foreach ($fields as $fd) {
            if ($fd['type'] === 'step') {
                if ($steps) {
                    $h .= '</fieldset><fieldset class="slate-form__step" data-sf-step>' . ($fd['label'] !== '' ? '<legend class="slate-form__step-title">' . e($fd['label']) . '</legend>' : '');
                }
                continue;
            }
            if ($fd['type'] === 'hidden') {
                $v = $old[$fd['key']] ?? $fd['default'] ?: $fd['placeholder']; // 1.x stored the value in "placeholder"
                $tail .= '<input type="hidden" name="' . e('fields[' . $fd['key'] . ']') . '" value="' . e(is_array($v) ? '' : (string)$v) . '">';
                continue;
            }
            $h .= self::field($fd, $uid, $c, $flat, $errors[$fd['key']] ?? null, $old);
        }
        if ($steps) {
            $h .= '</fieldset>';
        }
        $button = '<button class="' . e($c['button']) . '" type="submit">' . e($st['submit_label']) . '</button>';
        $h .= $flat ? $button : '<div class="slate-form__actions">' . $button . '</div>';
        if ($st['note'] !== '') {
            $h .= '<p class="' . e($c['note']) . '">' . Sanitizer::html($st['note']) . '</p>';
        }
        $h .= '<div class="' . e($c['success']) . '" role="status" aria-live="polite" tabindex="-1" hidden>' . self::message($st['success_message']) . '</div>';
        $h .= '<div class="' . e($c['error']) . '" role="alert" tabindex="-1" hidden>' . self::message($st['error_message']) . '</div>';
        $h .= $tail . '<input type="hidden" name="_form" value="' . $id . '"><input type="hidden" name="_page" value="' . e(self::currentUrl()) . '">'
            . '<input type="hidden" name="_title" value=""><input type="hidden" name="_js" value=""><input type="hidden" name="_ts" value="' . e(Submit::stamp()) . '">'
            . '<div class="slate-form__hp" aria-hidden="true"><label>Leave this empty <input type="text" name="_hp" tabindex="-1" autocomplete="off"></label></div>';
        $h .= '</form>';
        return $h . self::assets();
    }

    /** One field: wrapper, label, control, help and error. */
    private static function field(array $fd, string $uid, array $c, bool $flat, ?string $error, ?array $old): string
    {
        $key = $fd['key'];
        $id = $uid . '-' . $key;
        $name = 'fields[' . $key . ']';
        $req = $fd['required'] ? ' required' : '';
        $ph = $fd['placeholder'] !== '' && !in_array($fd['type'], ['select', 'date', 'time', 'file'], true) ? ' placeholder="' . e($fd['placeholder']) . '"' : '';
        $mark = $fd['required'] && !$flat ? ' <span class="slate-form__req" aria-hidden="true">*</span>' : '';
        $label = e($fd['label']) . $mark;
        $value = $old !== null ? ($old[$key] ?? '') : self::defaultValue($fd);
        $describe = [];
        $help = $fd['help'];
        if ($fd['type'] === 'file') {
            $types = $fd['accept'] ?: Submit::allowedTypes();
            $hint = strtoupper(implode(', ', array_slice($types, 0, 6))) . (count($types) > 6 ? ' and more' : '') . ', up to ' . rtrim(rtrim(number_format(Submit::maxSize($fd) / 1048576, 1), '0'), '.') . ' MB';
            $help = trim($help . ($help !== '' ? ' ' : '') . '(' . $hint . ')');
        }
        if ($help !== '' && !in_array($fd['type'], ['html', 'heading'], true)) {
            $describe[] = $id . '-help';
        }
        $describe[] = $id . '-err';
        $aria = ' aria-describedby="' . implode(' ', $describe) . '"' . ($error ? ' aria-invalid="true"' : '');
        $data = ' data-sf-field="' . e($key) . '"';
        $cond = $fd['cond'] ? " data-sf-cond='" . e((string)json_encode($fd['cond'], JSON_UNESCAPED_UNICODE)) . "'" : '';
        $attrs = self::limits($fd);
        $str = is_array($value) ? '' : (string)$value;

        $h = $flat ? '' : '<div class="' . e($c['field'] . ' slate-form__field--' . $fd['type'] . ($error ? ' has-error' : '')) . '" style="--sf-w:' . self::width($fd['width']) . '"' . $data . $cond . '>';
        $tag = $flat ? $data . $cond : ''; // flat markup: every element carries the field key, so rules can hide them together
        switch ($fd['type']) {
            case 'heading':
                $h .= '<h3 class="slate-form__heading"' . $tag . '>' . e($fd['label']) . '</h3>' . ($fd['help'] !== '' ? '<p class="' . e($c['help']) . '"' . ($flat ? $data : '') . '>' . e($fd['help']) . '</p>' : '');
                return $h . ($flat ? '' : '</div>');
            case 'html':
                $h .= '<div class="' . e($c['html']) . '"' . $tag . '>' . Sanitizer::html($fd['text'] !== '' ? $fd['text'] : $fd['label']) . '</div>';
                return $h . ($flat ? '' : '</div>');
            case 'textarea':
                $h .= '<label class="' . e($c['label']) . '" for="' . $id . '"' . $tag . '>' . $label . '</label><textarea class="' . e($c['textarea']) . '" id="' . $id . '" name="' . e($name) . '" rows="5"'
                    . $ph . $req . $attrs . $aria . ($flat ? $data : '') . '>' . e($str) . '</textarea>';
                break;
            case 'select':
                $h .= '<label class="' . e($c['label']) . '" for="' . $id . '"' . $tag . '>' . $label . '</label><select class="' . e($c['select']) . '" id="' . $id . '" name="' . e($name) . '"' . $req . $aria . ($flat ? $data : '') . '>';
                $h .= '<option value="">' . e($fd['placeholder'] !== '' ? $fd['placeholder'] : 'Choose…') . '</option>';
                foreach ($fd['choices'] as $v => $l) {
                    $h .= '<option value="' . e((string)$v) . '"' . ((string)$v === $str ? ' selected' : '') . '>' . e($l) . '</option>';
                }
                $h .= '</select>';
                break;
            case 'radio':
            case 'checkbox':
                $picked = array_map('strval', is_array($value) ? $value : ($str !== '' ? array_map('trim', explode(',', $str)) : []));
                $h .= '<fieldset class="slate-form__group" id="' . $id . '"' . ($fd['required'] ? ' data-sf-req="' . e($key) . '"' : '') . ($fd['type'] === 'checkbox' && ctype_digit($fd['min']) ? ' data-min="' . (int)$fd['min'] . '"' : '') . ($fd['type'] === 'checkbox' && ctype_digit($fd['max']) && (int)$fd['max'] > 0 ? ' data-max="' . (int)$fd['max'] . '"' : '') . $aria . ($flat ? $data . $cond : '') . '><legend class="' . e($c['label']) . '">' . $label . '</legend>';
                $i = 0;
                foreach ($fd['choices'] as $v => $l) {
                    $h .= '<label class="' . e($c['choice']) . '"><input type="' . $fd['type'] . '" name="' . e($name) . ($fd['type'] === 'checkbox' ? '[]' : '') . '" value="' . e((string)$v) . '"'
                        . (in_array((string)$v, $picked, true) ? ' checked' : '') . ($fd['type'] === 'radio' && $i === 0 ? $req : '') . '> <span>' . e($l) . '</span></label>';
                    $i++;
                }
                $h .= '</fieldset>';
                break;
            case 'consent':
                $text = $fd['text'] !== '' ? Sanitizer::html($fd['text']) : e($fd['label']);
                $h .= '<label class="' . e($c['choice']) . ' slate-form__consent"' . $tag . '><input type="checkbox" id="' . $id . '" name="' . e($name) . '" value="yes"'
                    . ($str === 'yes' ? ' checked' : '') . $req . $aria . '> <span>' . $text . $mark . '</span></label>';
                break;
            case 'file':
                $accept = implode(',', array_map(static fn($x) => '.' . $x, $fd['accept'] ?: Submit::allowedTypes()));
                $h .= '<label class="' . e($c['label']) . '" for="' . $id . '"' . $tag . '>' . $label . '</label><input class="' . e($c['input']) . ' slate-form__file" type="file" id="' . $id . '" name="' . e($name) . '"'
                    . ' accept="' . e($accept) . '" data-max="' . Submit::maxSize($fd) . '"' . $req . $aria . ($flat ? $data : '') . '>';
                break;
            default:
                $type = $fd['type'];
                $auto = ['email' => 'email', 'tel' => 'tel', 'url' => 'url'][$type] ?? (preg_match('/\bname\b/i', $fd['label']) ? 'name' : 'on');
                $mode = $type === 'url' ? ' inputmode="url"' : '';
                $h .= '<label class="' . e($c['label']) . '" for="' . $id . '"' . $tag . '>' . $label . '</label><input class="' . e($c['input']) . '" type="' . e($type) . '" id="' . $id . '" name="' . e($name) . '"'
                    . ' autocomplete="' . $auto . '"' . $mode . ($str !== '' ? ' value="' . e($str) . '"' : '') . $ph . $req . $attrs . $aria . ($flat ? $data : '') . '>';
        }
        if ($help !== '' && (!$flat || $fd['help'] !== '')) {
            $h .= '<small class="' . e($c['help']) . '" id="' . $id . '-help"' . ($flat ? $data : '') . '>' . e($help) . '</small>';
        }
        // Theme look keeps the original element order (designs may style by position): the script adds error lines when needed.
        if (!$flat || $error) {
            $h .= '<p class="slate-form__error-msg" id="' . $id . '-err" data-sf-error' . ($flat ? $data : '') . ($error ? '' : ' hidden') . '>' . e((string)$error) . '</p>';
        }
        return $h . ($flat ? '' : '</div>');
    }

    /** HTML validation attributes (the server checks the same rules). */
    private static function limits(array $fd): string
    {
        $a = '';
        $num = in_array($fd['type'], ['number', 'date', 'time'], true);
        if ($fd['min'] !== '') {
            $a .= $num ? ' min="' . e($fd['min']) . '"' : (in_array($fd['type'], ['text', 'textarea', 'tel', 'url', 'email'], true) && ctype_digit($fd['min']) ? ' minlength="' . (int)$fd['min'] . '"' : '');
        }
        if ($fd['max'] !== '') {
            $a .= $num ? ' max="' . e($fd['max']) . '"' : (in_array($fd['type'], ['text', 'textarea', 'tel', 'url', 'email'], true) && ctype_digit($fd['max']) ? ' maxlength="' . (int)$fd['max'] . '"' : '');
        }
        if ($fd['type'] === 'number') {
            $a .= ' step="any"';
        }
        if ($fd['pattern'] !== '' && in_array($fd['type'], ['text', 'tel'], true) && Submit::validPattern($fd['pattern'])) {
            $a .= ' pattern="' . e($fd['pattern']) . '"' . ($fd['pattern_msg'] !== '' ? ' data-pattern-msg="' . e($fd['pattern_msg']) . '"' : '');
        }
        return $a;
    }

    private static function defaultValue(array $fd): string|array
    {
        $d = $fd['default'];
        if ($d === '') {
            return '';
        }
        if ($fd['type'] === 'checkbox') {
            return array_map('trim', explode(',', $d));
        }
        if ($fd['type'] === 'date' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && ($t = strtotime($d))) {
            return date('Y-m-d', $t); // "today", "+1 week"
        }
        return $d;
    }

    private static function width(string $w): string
    {
        return ['50' => '50%', '33' => '33.333%', '66' => '66.666%'][$w] ?? '100%';
    }

    private static function message(string $text): string
    {
        return Sanitizer::html(nl2br($text, false));
    }

    /** Address of the page being rendered (sent back after a submit without JavaScript). */
    private static function currentUrl(): string
    {
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
        if ($uri === '' || str_starts_with(parse_url($uri, PHP_URL_PATH) ?: '', '/' . admin_slug() . '/')) {
            return '';
        }
        $uri = (string)preg_replace('/([?&])sf=\d+&?/', '$1', $uri);
        return site_url(ltrim(rtrim($uri, '?&'), '/'));
    }

    /** Answers and errors (or success) of a submit without JavaScript, kept in the session for one page view. */
    private static function takeState(int $formId): ?array
    {
        static $taken = [];
        if (array_key_exists($formId, $taken)) {
            return $taken[$formId];
        }
        if ((int)($_GET['sf'] ?? 0) !== $formId || !Session::exists()) {
            return null;
        }
        Session::start();
        $s = $_SESSION['slate_forms'][$formId] ?? null;
        unset($_SESSION['slate_forms'][$formId]);
        return $taken[$formId] = is_array($s) ? $s : null;
    }

    /** Small stylesheet + script, printed once per page. */
    public static function assets(): string
    {
        if (self::$assetsPrinted) {
            return '';
        }
        self::$assetsPrinted = true;
        $dir = SLATE_MODULES . '/slate-forms/assets/';
        $v = substr(md5((string)@filemtime($dir . 'forms.js') . @filemtime($dir . 'forms.css')), 0, 8);
        return '<link rel="stylesheet" href="' . e(url('modules/slate-forms/assets/forms.css')) . '?v=' . $v . '">'
            . '<script src="' . e(url('modules/slate-forms/assets/forms.js')) . '?v=' . $v . '" defer></script>';
    }
}
