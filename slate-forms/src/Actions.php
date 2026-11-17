<?php
declare(strict_types=1);

namespace SlateModules\Forms;

use Slate\Crypto;
use Slate\DB;
use Slate\Options;
use Slate\RemoteFetch;

/**
 * Per-form actions after an entry arrives (builder tab "Actions"). Each one can be switched on or off and can have a
 * condition ("only when Topic is Sales"):
 *   webhook  POST the entry to any URL as JSON or form data, all fields or chosen fields under own keys, own headers,
 *            optional HMAC-SHA256 signature (X-Slate-Signature: sha256=HMAC(secret, timestamp + "." + body));
 *   slack / discord / teams  a readable message to an incoming webhook of that chat app;
 *   email    an extra notification to other people (email routing).
 * HTTP actions are queued in {{form_deliveries}}, sent after the response has gone out and retried with back-off by a
 * scheduled task. Targets must be public http(s) addresses (private networks only when the site allows them).
 * Secrets and header values are stored encrypted with the site key.
 */
final class Actions
{
    public const TYPES = ['webhook' => 'Webhook', 'slack' => 'Slack', 'discord' => 'Discord', 'teams' => 'Microsoft Teams', 'email' => 'Email someone'];
    private const HTTP = ['webhook', 'slack', 'discord', 'teams'];
    private const BACKOFF = [60, 300, 1800, 7200, 43200];
    private const TIMEOUT = 8;
    private static array $queued = [];
    private static bool $flush = false;

    // ------------------------------------------------------------------ config

    /** Actions of a form, normalised (secrets stay encrypted). */
    public static function of(array $form): array
    {
        $out = [];
        foreach ((array)($form['fields']['actions'] ?? []) as $a) {
            if (is_array($a) && isset(self::TYPES[$a['type'] ?? '']) && !empty($a['id'])) {
                $out[] = $a + ['on' => true, 'name' => '', 'url' => '', 'format' => 'json', 'fields' => 'all', 'map' => [], 'header_names' => [], 'headers_enc' => '',
                    'secret_enc' => '', 'to' => '', 'subject' => '', 'cond' => null];
            }
        }
        return $out;
    }

    /** What the builder may see: no secrets, no header values. */
    public static function forBuilder(array $form): array
    {
        return array_map(static function (array $a): array {
            $b = $a;
            unset($b['secret_enc'], $b['headers_enc']);
            $b['has_secret'] = $a['secret_enc'] !== '';
            $b['secret'] = '';
            $b['headers'] = array_map(static fn($n) => ['name' => $n, 'value' => '', 'saved' => true], (array)$a['header_names']);
            return $b;
        }, self::of($form));
    }

    /** Problem with a target address, or null. */
    public static function urlProblem(string $url): ?string
    {
        if (!preg_match('#^https?://#i', $url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            return 'Enter a full http:// or https:// address.';
        }
        if (self::privateAllowed()) {
            return null;
        }
        [$target, $error] = RemoteFetch::check($url);
        return $target === null ? (string)$error : null;
    }

    private static function privateAllowed(): bool
    {
        return RemoteFetch::allowPrivate() || (bool)Options::get('webhooks_allow_private', false);
    }

    /**
     * Clean the actions sent by the builder. Secrets and header values that come back empty keep their saved value.
     * Returns [actions, error message or null].
     */
    public static function clean(array $rows, array $existing, array $fieldKeys): array
    {
        $old = [];
        foreach ($existing as $a) {
            $old[(string)$a['id']] = $a;
        }
        $str = static fn($v, int $max): string => mb_substr(trim(str_replace(["\0", "\r", "\n"], ' ', is_scalar($v) ? (string)$v : '')), 0, $max);
        $out = [];
        foreach (array_slice(array_values($rows), 0, 20) as $r) {
            if (!is_array($r) || !isset(self::TYPES[$r['type'] ?? ''])) {
                continue;
            }
            $id = preg_match('/^[a-z0-9]{1,20}$/i', (string)($r['id'] ?? '')) ? (string)$r['id'] : 'a' . bin2hex(random_bytes(4));
            $prev = $old[$id] ?? null;
            $type = (string)$r['type'];
            $a = ['id' => $id, 'type' => $type, 'on' => !empty($r['on']), 'name' => strip_tags($str($r['name'] ?? '', 80))];
            if (in_array($type, self::HTTP, true)) {
                $a['url'] = $str($r['url'] ?? '', 1000);
                if ($a['url'] !== '' && ($p = self::urlProblem($a['url']))) {
                    return [[], self::TYPES[$type] . ' action: ' . $p];
                }
            }
            if ($type === 'webhook') {
                $a['format'] = ($r['format'] ?? '') === 'form' ? 'form' : 'json';
                $a['fields'] = ($r['fields'] ?? '') === 'chosen' ? 'chosen' : 'all';
                $a['map'] = [];
                foreach (array_slice((array)($r['map'] ?? []), 0, 150) as $m) {
                    $f = $str($m['field'] ?? '', 60);
                    if (in_array($f, $fieldKeys, true)) {
                        $k = trim((string)preg_replace('/[^\w.\-\[\]]+/', '_', $str($m['key'] ?? '', 80)), '_');
                        $a['map'][] = ['field' => $f, 'key' => $k !== '' ? $k : $f];
                    }
                }
                // Headers: names in clear (for the builder), name => value encrypted.
                $prevHeaders = $prev && $prev['headers_enc'] !== '' ? (json_decode(Crypto::decrypt($prev['headers_enc']), true) ?: []) : [];
                $headers = [];
                foreach (array_slice((array)($r['headers'] ?? []), 0, 10) as $h) {
                    $name = $str($h['name'] ?? '', 60);
                    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9\-]*$/', $name) || in_array(strtolower($name), ['host', 'content-length', 'content-type', 'transfer-encoding', 'connection'], true)) {
                        continue;
                    }
                    $value = $str($h['value'] ?? '', 2000);
                    if ($value === '' && isset($prevHeaders[$name])) {
                        $value = (string)$prevHeaders[$name];
                    }
                    if ($value !== '') {
                        $headers[$name] = $value;
                    }
                }
                $a['header_names'] = array_keys($headers);
                $a['headers_enc'] = $headers ? Crypto::encrypt((string)json_encode($headers)) : '';
                $secret = $str($r['secret'] ?? '', 200);
                $a['secret_enc'] = !empty($r['secret_clear']) ? '' : ($secret !== '' ? Crypto::encrypt($secret) : (string)($prev['secret_enc'] ?? ''));
            }
            if ($type === 'email') {
                $a['to'] = implode(', ', array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', $str($r['to'] ?? '', 1000)) ?: []), static fn($x) => filter_var($x, FILTER_VALIDATE_EMAIL))));
                $a['subject'] = strip_tags($str($r['subject'] ?? '', 200));
            }
            $c = $r['cond'] ?? null;
            $a['cond'] = is_array($c) && in_array((string)($c['field'] ?? ''), $fieldKeys, true) ? [
                'field' => (string)$c['field'], 'op' => in_array($c['op'] ?? '', ['is', 'not', 'contains', 'empty', 'filled'], true) ? (string)$c['op'] : 'is',
                'value' => strip_tags($str($c['value'] ?? '', 300)),
            ] : null;
            $out[] = $a;
        }
        return [$out, null];
    }

    // ------------------------------------------------------------------ run

    /** Run the form's actions for a new entry: emails now, HTTP calls queued and sent after the response. */
    public static function run(array $form, array $data, string $page, ?int $submissionId): void
    {
        $values = [];
        foreach ($data as $k => $row) {
            $values[(string)$k] = $row['value'] ?? '';
        }
        foreach (self::of($form) as $a) {
            if (!$a['on'] || !self::condMet($a['cond'], $values)) {
                continue;
            }
            try {
                if ($a['type'] === 'email') {
                    if ($a['to'] !== '') {
                        Forms::notify($form, $data, $page, (int)$submissionId, ['to' => $a['to'], 'subject' => $a['subject']]);
                    }
                    continue;
                }
                if ($a['url'] === '') {
                    continue;
                }
                [$body, $type] = self::body($a, $form, $data, $page, $submissionId);
                self::$queued[] = self::queue($form, $a, $submissionId, $body, $type, 'entry');
            } catch (\Throwable $e) {
                error_log('Slate Forms action ' . $a['type'] . ': ' . $e->getMessage());
            }
        }
        if (self::$queued && !self::$flush && PHP_SAPI !== 'cli') {
            self::$flush = true;
            register_shutdown_function(static function (): void {
                if (function_exists('fastcgi_finish_request')) {
                    @fastcgi_finish_request();
                } elseif (function_exists('litespeed_finish_request')) {
                    @litespeed_finish_request();
                }
                ignore_user_abort(true);
                try {
                    self::deliver(self::$queued);
                } catch (\Throwable $e) {
                    error_log('Slate Forms deliveries: ' . $e->getMessage());
                }
            });
        }
    }

    private static function condMet(?array $c, array $values): bool
    {
        if (!$c) {
            return true;
        }
        $fake = [['key' => '_a', 'cond' => $c + ['action' => 'show']]];
        // Same rules as conditional fields.
        return Forms::visibility(array_merge(array_map(static fn($k) => ['key' => $k, 'cond' => null], array_keys($values)), $fake), $values)['_a'] ?? true;
    }

    private static function queue(array $form, array $a, ?int $submissionId, string $body, string $type, string $event): int
    {
        Forms::install();
        return DB::insert('form_deliveries', ['form_id' => (int)$form['id'], 'submission_id' => $submissionId, 'action_id' => $a['id'], 'kind' => $a['type'],
            'url' => mb_substr($a['url'], 0, 1000), 'payload' => $body, 'content_type' => $type, 'event' => $event, 'status' => 'pending', 'attempts' => 0,
            'next_at' => now(), 'created_at' => now()]);
    }

    /** [body, content type] for an action. */
    public static function body(array $a, array $form, array $data, string $page, ?int $submissionId): array
    {
        $title = (string)$form['title'];
        $link = $submissionId ? site_url(admin_slug() . '/m/submissions?id=' . $submissionId) : '';
        $lines = [];
        foreach ($data as $row) {
            if (($row['type'] ?? '') === 'hidden') {
                continue;
            }
            $v = is_array($row['value'] ?? null) ? implode(', ', $row['value']) : (string)($row['value'] ?? '');
            $lines[] = [(string)($row['label'] ?? ''), $v === '' ? '-' : mb_strimwidth($v, 0, 600, '…')];
        }
        $json = static fn(array $x): string => (string)json_encode($x, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        switch ($a['type']) {
            case 'slack':
                $esc = static fn(string $s): string => str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $s);
                $text = '*New entry: ' . $esc($title) . "*\n" . implode("\n", array_map(static fn($l) => '*' . $esc($l[0]) . ':* ' . $esc($l[1]), $lines))
                    . ($link ? "\n<" . $link . '|Open in the dashboard>' : '');
                return [$json(['text' => $text]), 'application/json'];
            case 'discord':
                $text = '**New entry: ' . $title . "**\n" . implode("\n", array_map(static fn($l) => '**' . $l[0] . ':** ' . $l[1], $lines)) . ($link ? "\n<" . $link . '>' : '');
                return [$json(['content' => mb_strimwidth($text, 0, 1990, '…'), 'allowed_mentions' => ['parse' => []]]), 'application/json'];
            case 'teams':
                $text = '**New entry: ' . $title . "**\n\n" . implode("  \n", array_map(static fn($l) => '**' . $l[0] . ':** ' . $l[1], $lines)) . ($link ? "\n\n[Open in the dashboard](" . $link . ')' : '');
                return [$json(['text' => $text]), 'application/json'];
        }
        // Webhook: all fields under their keys, or chosen fields under own keys.
        $fields = [];
        $files = [];
        if ($a['fields'] === 'chosen') {
            foreach ((array)$a['map'] as $m) {
                if (array_key_exists($m['field'], $data)) {
                    $fields[$m['key']] = $data[$m['field']]['value'] ?? null;
                }
            }
        } else {
            foreach ($data as $k => $row) {
                $fields[(string)$k] = $row['value'] ?? null;
            }
        }
        foreach ($data as $k => $row) {
            if (!empty($row['file']) && $submissionId) {
                $files[(string)$k] = ['name' => $row['value'], 'size' => (int)($row['file']['size'] ?? 0), 'url' => Forms::fileUrl($submissionId, (string)$k, true)];
            }
        }
        if ($a['format'] === 'form') {
            $flat = [];
            foreach ($fields as $k => $v) {
                $flat[$k] = is_array($v) ? implode(', ', $v) : $v;
            }
            return [http_build_query($flat + ['form_id' => (int)$form['id'], 'form_title' => $title, 'submission_id' => (string)($submissionId ?? ''), 'page' => $page]),
                'application/x-www-form-urlencoded'];
        }
        $payload = ['event' => 'form.submitted', 'id' => 'evt_' . bin2hex(random_bytes(8)), 'created_at' => gmdate('c'), 'site' => site_url(),
            'form' => ['id' => (int)$form['id'], 'title' => $title, 'slug' => (string)$form['slug']], 'submission_id' => $submissionId, 'page' => $page, 'fields' => (object)$fields];
        if ($files) {
            $payload['files'] = $files;
        }
        return [$json($payload), 'application/json'];
    }

    // ------------------------------------------------------------------ delivery

    /** @param int[] $ids */
    public static function deliver(array $ids): void
    {
        foreach ($ids as $id) {
            $d = DB::row("SELECT * FROM {{form_deliveries}} WHERE id = :id AND status = 'pending'", ['id' => (int)$id]);
            if (!$d) {
                continue;
            }
            $form = Forms::find((string)(int)$d['form_id'], false);
            $a = null;
            foreach ($form ? self::of($form) : [] as $x) {
                if ($x['id'] === $d['action_id']) {
                    $a = $x;
                }
            }
            if (!$a) {
                DB::update('form_deliveries', ['status' => 'failed', 'response' => 'The action was removed from the form.'], ['id' => (int)$id]);
                continue;
            }
            $r = self::post($a, (string)$d['payload'], (string)$d['content_type'], (string)$d['event'], (int)$d['id']);
            $attempts = (int)$d['attempts'] + 1;
            $ok = $r['status'] >= 200 && $r['status'] < 300;
            $final = $ok || $attempts > count(self::BACKOFF) || !empty($r['permanent']) || $d['event'] === 'test';
            DB::update('form_deliveries', [
                'status' => $ok ? 'delivered' : ($final ? 'failed' : 'pending'), 'attempts' => $attempts, 'response_code' => $r['status'] ?: null,
                'response' => mb_substr($r['error'] !== '' ? $r['error'] : (string)$r['body'], 0, 500),
                'next_at' => gmdate('Y-m-d H:i:s', time() + (self::BACKOFF[$attempts - 1] ?? 0)),
            ], ['id' => (int)$id]);
        }
    }

    /** One POST, connected to the address that was checked (no DNS tricks), no redirects. */
    private static function post(array $a, string $body, string $type, string $event, int $deliveryId): array
    {
        $url = (string)$a['url'];
        if (!function_exists('curl_init')) {
            return ['status' => 0, 'body' => '', 'error' => 'The PHP cURL extension is required.', 'permanent' => true];
        }
        $resolve = [];
        if (!self::privateAllowed()) {
            [$target, $error] = RemoteFetch::check($url);
            if ($target === null) {
                return ['status' => 0, 'body' => '', 'error' => (string)$error, 'permanent' => true];
            }
            $resolve = [$target['host'] . ':' . $target['port'] . ':' . $target['ip']];
        }
        $ts = (string)time();
        $headers = ['Content-Type: ' . $type, 'User-Agent: Slate-Forms/2.2 (+' . site_url() . ')', 'Accept: */*'];
        if ($a['type'] === 'webhook') {
            $headers[] = 'X-Slate-Event: form.' . ($event === 'test' ? 'test' : 'submitted');
            $headers[] = 'X-Slate-Delivery: ' . $deliveryId;
            $headers[] = 'X-Slate-Timestamp: ' . $ts;
            $secret = $a['secret_enc'] !== '' ? Crypto::decrypt($a['secret_enc']) : '';
            if ($secret !== '') {
                $headers[] = 'X-Slate-Signature: sha256=' . hash_hmac('sha256', $ts . '.' . $body, $secret);
            }
            foreach ($a['headers_enc'] !== '' ? (json_decode(Crypto::decrypt($a['headers_enc']), true) ?: []) : [] as $n => $v) {
                $headers[] = $n . ': ' . str_replace(["\r", "\n"], '', (string)$v);
            }
        }
        $resp = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$resp): int {
                if (strlen($resp) < 2000) {
                    $resp .= $chunk;
                }
                return strlen($chunk);
            },
        ] + ($resolve ? [CURLOPT_RESOLVE => $resolve] : []));
        curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_errno($ch) ? curl_error($ch) : '';
        curl_close($ch);
        return ['status' => $status, 'body' => $resp, 'error' => $error];
    }

    /** Scheduled task: send what is due (retries) and forget deliveries older than 30 days. */
    public static function cron(): void
    {
        Forms::install();
        $ids = DB::col("SELECT id FROM {{form_deliveries}} WHERE status = 'pending' AND next_at <= :now ORDER BY id LIMIT 50", ['now' => now()]);
        self::deliver(array_map('intval', $ids));
        DB::query('DELETE FROM {{form_deliveries}} WHERE created_at < :t', ['t' => gmdate('Y-m-d H:i:s', time() - 30 * 86400)]);
    }

    /** Send an action right now with sample answers. Returns [ok, message]. */
    public static function test(array $form, array $a): array
    {
        if (!in_array($a['type'], self::HTTP, true)) {
            return [false, 'Only web addresses can be tested.'];
        }
        if (($p = self::urlProblem((string)$a['url'])) !== null) {
            return [false, $p];
        }
        $data = [];
        foreach (Forms::fields($form) as $fd) {
            if (!in_array($fd['type'], Forms::DISPLAY_TYPES, true)) {
                $data[$fd['key']] = ['label' => $fd['label'] ?: $fd['key'], 'value' => $fd['type'] === 'email' ? 'test@example.com' : 'Sample ' . mb_strtolower($fd['label'] ?: $fd['key']), 'type' => $fd['type']];
            }
        }
        [$body, $type] = self::body($a, $form, $data, site_url(), null);
        $id = self::queue($form, $a, null, $body, $type, 'test');
        // The saved action may differ from the one being tested: send this one.
        $d = DB::row('SELECT * FROM {{form_deliveries}} WHERE id = :id', ['id' => $id]);
        $r = self::post($a, (string)$d['payload'], $type, 'test', $id);
        $ok = $r['status'] >= 200 && $r['status'] < 300;
        DB::update('form_deliveries', ['status' => $ok ? 'delivered' : 'failed', 'attempts' => 1, 'response_code' => $r['status'] ?: null,
            'response' => mb_substr($r['error'] !== '' ? $r['error'] : (string)$r['body'], 0, 500)], ['id' => $id]);
        return [$ok, $ok ? 'Delivered (HTTP ' . $r['status'] . ').' : 'Failed: ' . ($r['status'] ? 'HTTP ' . $r['status'] . ' ' : '') . mb_strimwidth($r['error'] !== '' ? $r['error'] : strip_tags((string)$r['body']), 0, 160, '…')];
    }

    /** Last deliveries of a form for the builder. */
    public static function recent(int $formId, int $limit = 15): array
    {
        Forms::install();
        return array_map(static fn($r) => ['id' => (int)$r['id'], 'action_id' => $r['action_id'], 'kind' => $r['kind'], 'event' => $r['event'], 'status' => $r['status'],
            'attempts' => (int)$r['attempts'], 'code' => $r['response_code'] ? (int)$r['response_code'] : null, 'response' => (string)$r['response'],
            'when' => local_date($r['created_at'], 'M j, H:i'), 'host' => (string)parse_url((string)$r['url'], PHP_URL_HOST)],
            DB::all('SELECT * FROM {{form_deliveries}} WHERE form_id = :f ORDER BY id DESC LIMIT ' . max(1, $limit), ['f' => $formId]));
    }

    /** Send a failed delivery again now. */
    public static function retry(int $id): void
    {
        DB::update('form_deliveries', ['status' => 'pending', 'attempts' => 0, 'next_at' => now()], ['id' => $id]);
        self::deliver([$id]);
    }
}
