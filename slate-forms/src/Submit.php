<?php
declare(strict_types=1);

namespace SlateModules\Forms;

use Slate\Auth;
use Slate\Crypto;
use Slate\DB;
use Slate\Hooks;
use Slate\Options;
use Slate\Session;

/**
 * The submit endpoint (/_slate/form): spam checks, limits, server-side validation of every rule the builder offers,
 * safe file uploads, storage, emails and the webhook. Answers JSON to the script and a redirect (with inline errors)
 * to browsers without JavaScript.
 */
final class Submit
{
    public static function handle(): never
    {
        $wantsJson = is_ajax() || !empty($_POST['_js']);
        $formId = (int)($_POST['_form'] ?? 0);
        $back = self::backUrl();
        $reply = static function (bool $ok, string $message, int $code = 200, ?string $redirect = null, array $errors = [], array $old = []) use ($wantsJson, $formId, $back): never {
            if ($wantsJson) {
                json_response(['ok' => $ok, 'message' => $message, 'redirect' => $redirect, 'errors' => (object)$errors], $code);
            }
            if ($ok && $redirect) {
                redirect($redirect);
            }
            if ($back !== null && $formId > 0 && ($ok || $errors)) {
                // Back to the page, which shows the thank-you message or the answers with inline errors.
                Session::start();
                $_SESSION['slate_forms'][$formId] = $ok ? ['ok' => true] : ['errors' => $errors, 'old' => $old];
                redirect($back . (str_contains($back, '?') ? '&' : '?') . 'sf=' . $formId . '#sf-' . $formId, 303);
            }
            http_response_code($code);
            header('Content-Type: text/html; charset=utf-8');
            echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">'
                . '<title>' . e(site_name()) . '</title><body style="font:16px/1.6 system-ui,sans-serif;max-width:560px;margin:15vh auto;padding:0 20px">'
                . '<p>' . nl2br(e($message)) . '</p><p><a href="' . e($back ?? url()) . '">Back</a></p></body>';
            exit;
        };
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $reply(false, 'Method not allowed.', 405);
        }
        Forms::install();
        $form = Forms::find((string)$formId);
        if (!$form) {
            $reply(false, 'This form is no longer available.', 404);
        }
        $st = Forms::settings($form);
        $redirect = $st['redirect_url'] !== '' ? $st['redirect_url'] : null;
        $ip = Auth::ip();

        // Spam: honeypot (dropped silently), then limits.
        if (!empty($_POST['_hp'])) {
            $reply(true, $st['success_message'], 200, $redirect);
        }
        if (($closed = Forms::closedReason($form)) !== null) {
            $reply(false, $closed, 403);
        }
        $limit = (int)Options::get('forms_rate_limit', 15);
        if ($limit > 0 && (int)DB::val('SELECT COUNT(*) FROM {{form_submissions}} WHERE ip = :ip AND created_at > :t', ['ip' => $ip, 't' => gmdate('Y-m-d H:i:s', time() - 3600)]) >= $limit) {
            $reply(false, 'Too many submissions from your connection. Please try again later.', 429);
        }

        [$data, $errors, $old, $uploads] = self::validate($form, (array)($_POST['fields'] ?? []));
        if ($errors) {
            $fields = array_column(Forms::fields($form), 'label', 'key');
            $lines = [];
            foreach ($errors as $k => $msg) {
                $lines[] = (($fields[$k] ?? '') !== '' ? $fields[$k] . ': ' : '') . $msg;
            }
            $reply(false, implode("\n", $lines), 422, null, $errors, $old);
        }
        // The form's own spam check (simple question, Turnstile, reCAPTCHA, hCaptcha), once the answers are fine.
        if (($cerr = Captcha::verify($form, $_POST, $ip)) !== null) {
            $reply(false, $cerr, 422, null, ['_captcha' => $cerr], $old);
        }

        // Bots fill forms in a blink and rarely run JavaScript: keep those reviewable under Spam.
        $status = 'new';
        $age = self::stampAge((string)($_POST['_ts'] ?? ''));
        $min = (int)Options::get('forms_min_seconds', 3);
        if ($age !== null && $min > 0 && $age < $min) {
            $status = 'spam';
        }
        if (empty($_POST['_js']) && Options::get('forms_require_js', false)) {
            $status = 'spam';
        }
        // "Save entries" off (privacy): nothing is stored, the emails and actions are the only copy. Spam is dropped.
        $keep = $st['store_entries'];
        if (!$keep && $status === 'spam') {
            $reply(true, $st['success_message'], 200, $redirect);
        }
        // Files are only kept for real, saved entries.
        foreach ($uploads as $key => $file) {
            if ($status === 'new' && $keep) {
                $meta = self::saveUpload($file);
                if ($meta === null) {
                    $reply(false, 'The file could not be saved. Please try again.', 500, null, [$key => 'The file could not be saved. Please try again.'], $old);
                }
                $data[$key]['file'] = $meta;
            }
        }

        $page = mb_substr(\Slate\ContentModel::cleanUrl((string)($_POST['_page'] ?? ($_SERVER['HTTP_REFERER'] ?? ''))), 0, 700);
        $page = (string)preg_replace('/([?&])sf=\d+(&|$)/', '$1', $page);
        $page = rtrim($page, '?&');
        $id = $keep ? Forms::store($form, $data, $page, mb_substr(strip_tags((string)($_POST['_title'] ?? '')), 0, 255), $status) : 0;
        if ($status === 'new') {
            foreach (['notify', 'confirm'] as $fn) {
                try {
                    Forms::$fn($form, $data, $page, $id);
                } catch (\Throwable $e) {
                    error_log('Slate Forms ' . $fn . ': ' . $e->getMessage());
                }
            }
            if (class_exists(\Slate\Webhooks::class)) {
                $fieldsOut = [];
                $files = [];
                foreach ($data as $k => $row) {
                    $fieldsOut[(string)$k] = $row['value'] ?? null;
                    if (!empty($row['file']) && $id) {
                        $files[(string)$k] = ['name' => $row['value'], 'size' => $row['file']['size'] ?? 0, 'url' => Forms::fileUrl($id, (string)$k, true)];
                    }
                }
                \Slate\Webhooks::dispatch('form.submitted', ['submission_id' => $id ?: null, 'form' => ['id' => (int)$form['id'], 'title' => $form['title'], 'slug' => $form['slug']],
                    'page' => $page, 'fields' => $fieldsOut] + ($files ? ['files' => $files] : []));
            }
        }
        if ($status === 'new') {
            // The form's own actions: webhooks, chat messages, email routing.
            Actions::run($form, $data, $page, $id ?: null);
        }
        Hooks::action('form_submitted', $id, $form, $data);
        $reply(true, $st['success_message'], 200, $redirect);
    }

    /**
     * Check every visible field. Returns [data to store, errors key => message, answers to refill, uploads key => $_FILES entry].
     */
    public static function validate(array $form, array $input, ?array $files = null): array
    {
        $fields = Forms::fields($form);
        $files ??= self::files();
        // Raw answers first: conditions depend on them.
        $raw = [];
        foreach ($fields as $fd) {
            if (in_array($fd['type'], Forms::DISPLAY_TYPES, true)) {
                continue;
            }
            $v = $input[$fd['key']] ?? null;
            if ($fd['type'] === 'checkbox') {
                $raw[$fd['key']] = array_values(array_intersect(array_map('strval', (array)$v), array_map('strval', array_keys($fd['choices']))));
            } elseif ($fd['type'] === 'file') {
                $raw[$fd['key']] = isset($files[$fd['key']]) && (int)$files[$fd['key']]['error'] !== UPLOAD_ERR_NO_FILE ? (string)$files[$fd['key']]['name'] : '';
            } else {
                $raw[$fd['key']] = is_array($v) ? '' : trim(str_replace("\0", '', (string)$v));
            }
        }
        $shown = Forms::visibility($fields, $raw);

        $data = [];
        $errors = [];
        $uploads = [];
        foreach ($fields as $fd) {
            $k = $fd['key'];
            if (in_array($fd['type'], Forms::DISPLAY_TYPES, true) || !($shown[$k] ?? true)) {
                continue;
            }
            $val = $raw[$k];
            $err = null;
            switch ($fd['type']) {
                case 'checkbox':
                    $n = count($val);
                    if ($n && $fd['min'] !== '' && $n < (int)$fd['min']) {
                        $err = 'Choose at least ' . (int)$fd['min'] . '.';
                    } elseif ($fd['max'] !== '' && (int)$fd['max'] > 0 && $n > (int)$fd['max']) {
                        $err = 'Choose at most ' . (int)$fd['max'] . '.';
                    }
                    break;
                case 'file':
                    if ($val !== '') {
                        [$ok, $msg] = self::checkUpload($fd, $files[$k]);
                        if ($ok) {
                            $uploads[$k] = $files[$k];
                            $val = self::cleanName((string)$files[$k]['name']);
                        } else {
                            $err = $msg;
                            $val = '';
                        }
                    }
                    break;
                default:
                    $val = mb_substr(strip_tags($val), 0, $fd['type'] === 'textarea' ? 10000 : 1000);
                    if ($val !== '') {
                        $err = self::checkValue($fd, $val);
                    }
            }
            if ($err === null && $fd['required'] && ($val === '' || $val === [])) {
                $err = match ($fd['type']) {
                    'consent' => 'Please tick this box to continue.',
                    'file' => 'Please choose a file.',
                    'radio', 'select' => 'Please choose an option.',
                    'checkbox' => 'Please choose at least one option.',
                    default => 'This field is required.',
                };
            }
            if ($err !== null) {
                $errors[$k] = $err;
            }
            $data[$k] = ['label' => $fd['label'] !== '' ? $fd['label'] : $k, 'value' => $val, 'type' => $fd['type']];
        }
        $old = [];
        foreach ($raw as $k => $v) {
            $old[$k] = is_array($v) ? $v : mb_substr($v, 0, 10000);
        }
        return [$data, $errors, $old, $uploads];
    }

    /** Type and rule checks of a filled-in value (normalises $val in place). Returns an error message or null. */
    private static function checkValue(array $fd, string &$val): ?string
    {
        $min = $fd['min'];
        $max = $fd['max'];
        switch ($fd['type']) {
            case 'email':
                return filter_var($val, FILTER_VALIDATE_EMAIL) ? null : 'Enter a valid email address.';
            case 'url':
                if (!preg_match('#^https?://#i', $val)) {
                    $val = 'https://' . $val;
                }
                return filter_var($val, FILTER_VALIDATE_URL) ? null : 'Enter a valid web address.';
            case 'number':
                if (!is_numeric($val)) {
                    return 'Enter a number.';
                }
                if ($min !== '' && is_numeric($min) && (float)$val < (float)$min) {
                    return 'Enter ' . $min . ' or more.';
                }
                if ($max !== '' && is_numeric($max) && (float)$val > (float)$max) {
                    return 'Enter ' . $max . ' or less.';
                }
                return null;
            case 'date':
                $d = \DateTime::createFromFormat('!Y-m-d', $val);
                if (!$d || $d->format('Y-m-d') !== $val) {
                    return 'Enter a valid date.';
                }
                if ($min !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $min) && $val < $min) {
                    return 'Pick a date on or after ' . $min . '.';
                }
                if ($max !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $max) && $val > $max) {
                    return 'Pick a date on or before ' . $max . '.';
                }
                return null;
            case 'time':
                return preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $val) ? null : 'Enter a valid time.';
            case 'select':
            case 'radio':
                if (!array_key_exists($val, $fd['choices'])) {
                    $val = '';
                }
                return null;
            case 'consent':
                $val = $val !== '' ? 'yes' : '';
                return null;
            case 'tel':
                if ($fd['pattern'] === '' && !preg_match('/^[0-9+()\-.\s\/]{5,30}$/', $val)) {
                    return 'Enter a valid phone number.';
                }
                break;
        }
        // Text-like: length and pattern.
        $len = mb_strlen($val);
        if ($min !== '' && ctype_digit($min) && $len < (int)$min) {
            return 'Use at least ' . (int)$min . ' characters.';
        }
        if ($max !== '' && ctype_digit($max) && (int)$max > 0 && $len > (int)$max) {
            return 'Use at most ' . (int)$max . ' characters.';
        }
        if ($fd['pattern'] !== '' && self::validPattern($fd['pattern']) && !preg_match(self::regex($fd['pattern']), $val)) {
            return $fd['pattern_msg'] !== '' ? $fd['pattern_msg'] : 'Use the requested format.';
        }
        return null;
    }

    /** An HTML pattern attribute as a PHP regex (whole value must match). */
    private static function regex(string $pattern): string
    {
        return '~^(?:' . str_replace('~', '\~', $pattern) . ')$~u';
    }

    public static function validPattern(string $pattern): bool
    {
        return $pattern !== '' && strlen($pattern) <= 300 && @preg_match(self::regex($pattern), '') !== false;
    }

    // ------------------------------------------------------------------ spam time trap

    /** Signed render time, so a submit sent a second after the page loaded can be told apart. */
    public static function stamp(): string
    {
        $t = time();
        return $t . '.' . self::sign((string)$t);
    }

    /** Seconds since the stamp was made, or null when missing / not ours (e.g. a page cached before 2.0). */
    public static function stampAge(string $stamp): ?int
    {
        if (!preg_match('/^(\d{9,11})\.([a-f0-9]{20})$/', $stamp, $m) || !hash_equals(self::sign($m[1]), $m[2])) {
            return null;
        }
        return time() - (int)$m[1];
    }

    private static function sign(string $data): string
    {
        try {
            return substr(Crypto::hmac('slate-forms|' . $data), 0, 20);
        } catch (\Throwable) {
            $secret = (string)Options::get('forms_secret', '');
            if ($secret === '') {
                $secret = bin2hex(random_bytes(16));
                Options::set('forms_secret', $secret, false);
            }
            return substr(hash_hmac('sha256', 'slate-forms|' . $data, $secret), 0, 20);
        }
    }

    // ------------------------------------------------------------------ uploads

    /** File extensions accepted by default (Forms settings), without the always-blocked ones. */
    public static function allowedTypes(): array
    {
        $list = array_map(static fn($x) => strtolower(trim($x, " .\t")), explode(',', (string)(Options::get('forms_upload_types', '') ?: Forms::DEFAULT_UPLOAD_TYPES)));
        return array_values(array_diff(array_unique(array_filter($list)), Forms::BLOCKED_EXT));
    }

    /** Size limit in bytes: the field's own, else the site setting, never above what PHP accepts. */
    public static function maxSize(array $fd): int
    {
        $mb = (float)($fd['max_size'] ?? 0) ?: (float)Options::get('forms_upload_max', 10);
        $bytes = (int)round(max(0.1, $mb) * 1048576);
        $php = min(self::iniBytes((string)ini_get('upload_max_filesize')), self::iniBytes((string)ini_get('post_max_size')));
        return $php > 0 ? min($bytes, $php) : $bytes;
    }

    private static function iniBytes(string $v): int
    {
        $v = trim($v);
        if ($v === '') {
            return 0;
        }
        $n = (float)$v;
        return (int)match (strtolower(substr($v, -1))) { 'g' => $n * 1073741824, 'm' => $n * 1048576, 'k' => $n * 1024, default => $n };
    }

    /** Uploaded files of "fields[...]" as key => [name, type, tmp_name, error, size]. */
    private static function files(): array
    {
        $f = $_FILES['fields'] ?? null;
        if (!is_array($f) || !is_array($f['name'] ?? null)) {
            return [];
        }
        $out = [];
        foreach ($f['name'] as $k => $name) {
            if (is_array($name)) {
                continue; // one file per field
            }
            $out[(string)$k] = ['name' => (string)$name, 'type' => (string)($f['type'][$k] ?? ''), 'tmp_name' => (string)($f['tmp_name'][$k] ?? ''),
                'error' => (int)($f['error'][$k] ?? UPLOAD_ERR_NO_FILE), 'size' => (int)($f['size'][$k] ?? 0)];
        }
        return $out;
    }

    private static function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = trim((string)preg_replace('/[\x00-\x1F\x7F"<>:|?*]+/u', '', $name));
        return mb_substr($name !== '' ? $name : 'file', -120);
    }

    /** [ok, error message]: size, extension (allow list + block list) and real content type. */
    private static function checkUpload(array $fd, array $file): array
    {
        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
            return [false, 'The file is too large (up to ' . self::mb(self::maxSize($fd)) . ' MB).'];
        }
        if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            return [false, 'The upload did not work. Please try again.'];
        }
        if ($file['size'] <= 0 || $file['size'] > self::maxSize($fd)) {
            return [false, 'The file is too large (up to ' . self::mb(self::maxSize($fd)) . ' MB).'];
        }
        $name = strtolower(self::cleanName($file['name']));
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $allowed = array_values(array_diff($fd['accept'] ?: self::allowedTypes(), Forms::BLOCKED_EXT));
        // "photo.php.jpg" style names: every part must be harmless.
        foreach (array_slice(explode('.', $name), 1) as $part) {
            if (in_array($part, Forms::BLOCKED_EXT, true)) {
                return [false, 'This type of file is not allowed.'];
            }
        }
        if ($ext === '' || !in_array($ext, $allowed, true)) {
            return [false, 'Please upload one of: ' . strtoupper(implode(', ', $allowed)) . '.'];
        }
        $mime = function_exists('finfo_open') ? (string)(new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) : '';
        if ($mime !== '') {
            if (preg_match('#(php|html|javascript|x-sh|x-shellscript|svg|x-msdownload|x-executable|x-dosexec)#i', $mime)) {
                return [false, 'This type of file is not allowed.'];
            }
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'heic', 'bmp', 'tif', 'tiff'], true) && !str_starts_with($mime, 'image/')) {
                return [false, 'This file is not a valid image.'];
            }
            if ($ext === 'pdf' && $mime !== 'application/pdf') {
                return [false, 'This file is not a valid PDF.'];
            }
        }
        // Text formats must not carry markup or code.
        if (in_array($ext, ['txt', 'csv'], true)) {
            $head = (string)file_get_contents($file['tmp_name'], false, null, 0, 4096);
            if (preg_match('/<\?php|<script|<html|<\?=/i', $head)) {
                return [false, 'This file is not allowed.'];
            }
        }
        return [true, ''];
    }

    private static function mb(int $bytes): string
    {
        return rtrim(rtrim(number_format($bytes / 1048576, 1), '0'), '.');
    }

    /** Move a checked upload into the private folder under a random name with no extension. */
    private static function saveUpload(array $file): ?array
    {
        $rel = gmdate('Y/m') . '/' . bin2hex(random_bytes(16));
        $dir = Forms::uploadDir() . '/' . dirname($rel);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return null;
        }
        $path = Forms::uploadDir() . '/' . $rel;
        if (!@move_uploaded_file($file['tmp_name'], $path)) {
            return null;
        }
        @chmod($path, 0640);
        $mime = function_exists('finfo_open') ? (string)(new \finfo(FILEINFO_MIME_TYPE))->file($path) : '';
        return ['path' => $rel, 'size' => (int)$file['size'], 'mime' => $mime ?: 'application/octet-stream'];
    }

    // ------------------------------------------------------------------ helpers

    /** Same-site page the form was sent from (path + query only), or null. */
    private static function backUrl(): ?string
    {
        $src = trim((string)($_POST['_page'] ?? '')) ?: (string)($_SERVER['HTTP_REFERER'] ?? '');
        if ($src === '') {
            return null;
        }
        $u = parse_url($src);
        if (!$u) {
            return null;
        }
        $hosts = array_filter([strtolower((string)parse_url(site_url(), PHP_URL_HOST)), strtolower((string)preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')))]);
        if (isset($u['host']) && !in_array(strtolower($u['host']), $hosts, true)) {
            return null;
        }
        $path = '/' . ltrim((string)($u['path'] ?? '/'), '/');
        if (str_starts_with($path, '//')) {
            return null;
        }
        $query = (string)preg_replace('/(^|&)sf=\d+/', '', (string)($u['query'] ?? ''));
        $query = trim($query, '&');
        return $path . ($query !== '' ? '?' . $query : '');
    }
}
