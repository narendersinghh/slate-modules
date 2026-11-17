<?php
declare(strict_types=1);

namespace SlateModules\Forms;

use Slate\Crypto;
use Slate\Http;
use Slate\Options;

/**
 * Spam checks a form can add on top of the honeypot, time trap and rate limit:
 *   question   a question only people answer (a sum by default), no third party, works without JavaScript;
 *   turnstile  Cloudflare Turnstile;  recaptcha  Google reCAPTCHA v3 (score);  hcaptcha  hCaptcha.
 * The third-party ones need JavaScript in the visitor's browser. Keys live in the Forms settings; every answer is
 * checked on the server.
 */
final class Captcha
{
    public const PROVIDERS = ['' => 'None', 'question' => 'Simple question', 'turnstile' => 'Cloudflare Turnstile', 'recaptcha' => 'Google reCAPTCHA v3', 'hcaptcha' => 'hCaptcha'];
    private const VERIFY = [
        'turnstile' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
        'recaptcha' => 'https://www.google.com/recaptcha/api/siteverify',
        'hcaptcha' => 'https://api.hcaptcha.com/siteverify',
    ];
    private const SCRIPT = [
        'turnstile' => 'https://challenges.cloudflare.com/turnstile/v0/api.js',
        'hcaptcha' => 'https://js.hcaptcha.com/1/api.js',
        'recaptcha' => 'https://www.google.com/recaptcha/api.js?render=',
    ];
    private const TOKEN_FIELD = ['turnstile' => 'cf-turnstile-response', 'recaptcha' => 'g-recaptcha-response', 'hcaptcha' => 'h-captcha-response'];

    private static array $scripts = [];

    public static function siteKey(string $provider): string
    {
        return trim((string)Options::get('forms_' . $provider . '_site', ''));
    }

    private static function secret(string $provider): string
    {
        $v = (string)Options::get('forms_' . $provider . '_secret', '');
        try {
            return trim(Crypto::decrypt($v)) ?: trim($v);
        } catch (\Throwable) {
            return trim($v);
        }
    }

    /** Are the keys of a third-party provider set in the Forms settings? */
    public static function configured(string $provider): bool
    {
        return $provider === 'question' || ($provider !== '' && isset(self::VERIFY[$provider]) && self::siteKey($provider) !== '' && self::secret($provider) !== '');
    }

    /** The provider a form uses, or '' (a provider without keys is skipped, so the form keeps working). */
    public static function provider(array $form): string
    {
        $p = (string)($form['fields']['captcha'] ?? '');
        return isset(self::PROVIDERS[$p]) && $p !== '' && self::configured($p) ? $p : '';
    }

    // ------------------------------------------------------------------ simple question

    /** [question, accepted answers]. Without a question of its own the form asks a small sum. */
    private static function question(array $form, ?int $seed = null): array
    {
        $q = trim((string)($form['fields']['captcha_question'] ?? ''));
        $a = trim((string)($form['fields']['captcha_answer'] ?? ''));
        if ($q !== '' && $a !== '') {
            return [$q, array_values(array_filter(array_map(static fn($x) => self::norm($x), explode(',', $a)), 'strlen'))];
        }
        $seed ??= random_int(0, 99);
        $x = 2 + $seed % 8;
        $y = 1 + intdiv($seed, 8) % 9;
        return ['What is ' . $x . ' plus ' . $y . '?', [(string)($x + $y)]];
    }

    private static function norm(string $s): string
    {
        return trim((string)preg_replace('/\s+/', ' ', mb_strtolower(trim($s))), " .!");
    }

    private static function sign(string $data): string
    {
        try {
            return substr(Crypto::hmac('slate-forms-q|' . $data), 0, 24);
        } catch (\Throwable) {
            return substr(hash_hmac('sha256', 'slate-forms-q|' . $data, (string)Options::get('forms_secret', 'slate')), 0, 24);
        }
    }

    // ------------------------------------------------------------------ render

    /** Markup for the form (empty when the form has no captcha). $error: message from a submit without JavaScript. */
    public static function html(array $form, string $uid, ?string $error = null, bool $flat = false): string
    {
        $p = self::provider($form);
        if ($p === '') {
            return '';
        }
        $err = '<p class="slate-form__error-msg" id="' . $uid . '-captcha-err" data-sf-error data-sf-field="_captcha"' . ($error ? '' : ' hidden') . '>' . e((string)$error) . '</p>';
        if ($p === 'question') {
            $seed = random_int(0, 71);
            [$q] = self::question($form, $seed);
            $id = $uid . '-captcha';
            $wrap = $flat ? '' : '<div class="slate-form__field slate-form__field--captcha' . ($error ? ' has-error' : '') . '" data-sf-field="_captcha">';
            return $wrap . '<label class="slate-form__label" for="' . $id . '">' . e($q) . ' <span class="slate-form__req" aria-hidden="true">*</span></label>'
                . '<input class="slate-form__input" type="text" id="' . $id . '" name="_captcha" required autocomplete="off" inputmode="text" aria-describedby="' . $uid . '-captcha-err"' . ($error ? ' aria-invalid="true"' : '') . '>'
                . '<input type="hidden" name="_cq" value="' . $seed . '.' . self::sign((string)$seed . '|' . (int)$form['id']) . '">'
                . ($flat && !$error ? '' : $err) . ($flat ? '' : '</div>');
        }
        $key = self::siteKey($p);
        $h = $flat ? '' : '<div class="slate-form__field slate-form__field--captcha" data-sf-field="_captcha">';
        if ($p === 'turnstile') {
            $h .= '<div class="cf-turnstile" data-sitekey="' . e($key) . '" data-action="form"></div>';
        } elseif ($p === 'hcaptcha') {
            $h .= '<div class="h-captcha" data-sitekey="' . e($key) . '"></div>';
        } else {
            $h .= '<input type="hidden" name="g-recaptcha-response" value="" data-sf-recaptcha="' . e($key) . '"><small class="slate-form__help">This site is protected by reCAPTCHA (Google <a href="https://policies.google.com/privacy" rel="noopener">Privacy</a> and <a href="https://policies.google.com/terms" rel="noopener">Terms</a>).</small>';
        }
        $h .= '<noscript><p class="slate-form__help">Please turn on JavaScript to send this form: it uses a spam check that needs it.</p></noscript>';
        $h .= ($flat && !$error ? '' : $err) . ($flat ? '' : '</div>');
        // The provider's script, once per page and only where such a form is shown.
        if (!isset(self::$scripts[$p])) {
            self::$scripts[$p] = true;
            $h .= '<script src="' . e(self::SCRIPT[$p] . ($p === 'recaptcha' ? rawurlencode($key) : '')) . '" async defer></script>';
        }
        return $h;
    }

    // ------------------------------------------------------------------ verify

    /** Error message for the visitor, or null when the check passed (or the form has none). */
    public static function verify(array $form, array $post, string $ip): ?string
    {
        $p = self::provider($form);
        if ($p === '') {
            return null;
        }
        if ($p === 'question') {
            $cq = (string)($post['_cq'] ?? '');
            $answer = self::norm((string)($post['_captcha'] ?? ''));
            if (!preg_match('/^(\d{1,3})\.([a-f0-9]{24})$/', $cq, $m) || !hash_equals(self::sign($m[1] . '|' . (int)$form['id']), $m[2])) {
                return 'Please answer the question again.';
            }
            if ($answer === '') {
                return 'Please answer the question.';
            }
            [, $accepted] = self::question($form, (int)$m[1]);
            return in_array($answer, $accepted, true) ? null : 'That answer is not right. Please try again.';
        }
        $token = trim((string)($post[self::TOKEN_FIELD[$p]] ?? ''));
        if ($token === '') {
            return 'Please complete the spam check. It needs JavaScript in your browser.';
        }
        $res = self::remote($p, $token, $ip);
        if ($res === null) {
            return 'The spam check could not be reached. Please try again in a moment.';
        }
        if (empty($res['success'])) {
            return 'The spam check failed. Please try again.';
        }
        if ($p === 'recaptcha') {
            $min = (float)Options::get('forms_recaptcha_score', 0.5);
            if (isset($res['score']) && (float)$res['score'] < $min) {
                return 'The spam check could not confirm you are a person. Please try again.';
            }
        }
        return null;
    }

    /** Ask the provider. Returns its JSON answer or null when it could not be reached. */
    private static function remote(string $p, string $token, string $ip): ?array
    {
        // Tests point this at a local mock (SLATE_CONFIG['forms_captcha_verify_url']); real sites never set it.
        $url = defined('SLATE_CONFIG') && !empty(SLATE_CONFIG['forms_captcha_verify_url']) ? (string)SLATE_CONFIG['forms_captcha_verify_url'] : self::VERIFY[$p];
        $body = http_build_query(['secret' => self::secret($p), 'response' => mb_substr($token, 0, 4096), 'remoteip' => $ip]);
        $r = Http::request('POST', $url, ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'], $body, 10);
        if (($r['status'] ?? 0) < 200 || ($r['status'] ?? 0) >= 300) {
            return null;
        }
        $j = json_decode((string)$r['body'], true);
        return is_array($j) ? $j : null;
    }
}
