<?php
/**
 * 1.2: settings move from the "shop_settings" array to one option per setting ("shop_currency", …) so core's settings
 * screen can edit them, and secrets from the "shop_secrets" array to one encrypted option each ("shop_stripe_test_secret",
 * …). Values are copied as they are (secrets stay encrypted with the same Crypto key); options that already exist are
 * never overwritten. The old options are removed afterwards so no stale copy of a secret is left behind.
 */
declare(strict_types=1);

use Slate\DB;
use Slate\Options;
use SlateModules\Shop\Shop;

return static function (): void {
    $read = static function (string $name): ?array {
        $raw = DB::val('SELECT value FROM {{options}} WHERE name = :n', ['n' => $name]);
        $v = $raw === null ? null : json_decode((string)$raw, true);
        return is_array($v) ? $v : null;
    };
    $exists = static fn(string $name): bool => DB::val('SELECT 1 FROM {{options}} WHERE name = :n', ['n' => $name]) !== null;

    $settings = $read(Shop::LEGACY_SETTINGS);
    if ($settings !== null) {
        foreach (Shop::DEFAULTS as $key => $default) {
            if (!array_key_exists($key, $settings) || $exists(Shop::optionKey($key))) {
                continue;
            }
            $v = $settings[$key];
            if ($key === 'decimals') {
                $v = (string)(int)$v;
            } elseif ($key === 'thousand_sep' && $v === ' ') {
                $v = "\u{00A0}"; // the settings screen offers a non-breaking space (a plain one would be trimmed away)
            }
            Options::set(Shop::optionKey($key), $v);
        }
    }
    $secrets = $read(Shop::LEGACY_SECRETS);
    if ($secrets !== null) {
        foreach (array_keys(Shop::SECRET_KEYS) as $key) {
            $v = $secrets[$key] ?? '';
            if (is_string($v) && $v !== '' && !$exists(Shop::optionKey($key))) {
                Options::set(Shop::optionKey($key), $v); // already "enc:…", the format core's secret fields use
            }
        }
    }
    Options::delete(Shop::LEGACY_SETTINGS);
    Options::delete(Shop::LEGACY_SECRETS);
    Shop::flush();
};
