<?php
declare(strict_types=1);

namespace SlateModules\Shop;

/**
 * Money helpers. Amounts are stored as decimal strings ("19.90") in product fields and as integer minor units
 * (1990) everywhere else; all arithmetic happens on integers, never on floats.
 */
final class Money
{
    /** ISO 4217 currencies whose minor unit is not 1/100 (as used by Stripe and Razorpay). */
    private const ZERO_DECIMAL = ['BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];
    private const THREE_DECIMAL = ['BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND'];

    public static function decimals(): int
    {
        return max(0, min(3, (int)Shop::setting('decimals')));
    }

    public static function currency(): string
    {
        return strtoupper((string)Shop::setting('currency'));
    }

    /** Number of decimals the payment providers expect for a currency. */
    public static function isoExponent(string $currency): int
    {
        $currency = strtoupper($currency);
        return in_array($currency, self::ZERO_DECIMAL, true) ? 0 : (in_array($currency, self::THREE_DECIMAL, true) ? 3 : 2);
    }

    /**
     * Parse a decimal amount ("1,299.50", "19,9", "20") into minor units. Extra decimals are rounded half up.
     * Returns null for anything that is not a non-negative amount.
     */
    public static function toMinor(mixed $value, ?int $decimals = null): ?int
    {
        $decimals ??= self::decimals();
        if (is_int($value)) {
            $value = (string)$value;
        } elseif (is_float($value)) {
            $value = number_format($value, 6, '.', '');
        }
        if (!is_string($value)) {
            return null;
        }
        $s = str_replace([' ', "\u{00A0}", "'", '_'], '', trim($value));
        if ($s === '') {
            return null;
        }
        // Separators: with both "." and "," the last one is the decimal mark ("1,299.50" / "1.299,50"); a single
        // comma not followed by exactly three digits is a decimal comma ("19,9"); repeated marks group thousands.
        $dot = strrpos($s, '.');
        $comma = strrpos($s, ',');
        if ($dot !== false && $comma !== false) {
            $decimal = $dot > $comma ? '.' : ',';
            $s = str_replace($decimal === '.' ? ',' : '.', '', $s);
            $s = str_replace(',', '.', $s);
        } elseif ($comma !== false) {
            $s = substr_count($s, ',') === 1 && strlen($s) - $comma - 1 !== 3 ? str_replace(',', '.', $s) : str_replace(',', '', $s);
        } elseif ($dot !== false && substr_count($s, '.') > 1) {
            $s = str_replace('.', '', $s);
        }
        if (!preg_match('/^(\d{1,13})(?:\.(\d*))?$/', $s, $m)) {
            return null;
        }
        $frac = $m[2] ?? '';
        $padded = str_pad($frac, $decimals + 1, '0');
        $minor = (int)$m[1] * (10 ** $decimals) + ($decimals > 0 ? (int)substr($padded, 0, $decimals) : 0);
        if ((int)$padded[$decimals] >= 5) {
            $minor++;
        }
        return $minor;
    }

    /** Minor units → canonical decimal string ("1990" → "19.90"). */
    public static function toDecimal(int $minor, ?int $decimals = null): string
    {
        $decimals ??= self::decimals();
        $sign = $minor < 0 ? '-' : '';
        $abs = (string)abs($minor);
        if ($decimals === 0) {
            return $sign . $abs;
        }
        $abs = str_pad($abs, $decimals + 1, '0', STR_PAD_LEFT);
        return $sign . substr($abs, 0, -$decimals) . '.' . substr($abs, -$decimals);
    }

    /** Normalise a stored decimal string; '' when empty or invalid. */
    public static function normalize(mixed $value): string
    {
        $minor = self::toMinor($value);
        return $minor === null ? '' : self::toDecimal($minor);
    }

    /** Formatted for display with the shop's symbol, position and separators (plain text, escape before printing). */
    public static function format(int $minor, ?int $decimals = null, ?string $currency = null): string
    {
        $decimals ??= self::decimals();
        $dec = self::toDecimal(abs($minor), $decimals);
        [$int, $frac] = array_pad(explode('.', $dec, 2), 2, '');
        $int = self::group($int, (string)Shop::setting('thousand_sep'));
        $number = $int . ($decimals > 0 ? (string)Shop::setting('decimal_sep') . $frac : '');
        $symbol = (string)Shop::setting('currency_symbol');
        if ($symbol === '' || ($currency !== null && strtoupper($currency) !== self::currency())) {
            $symbol = strtoupper($currency ?? self::currency()) . ' ';
        }
        $out = match ((string)Shop::setting('currency_position')) {
            'after' => $number . $symbol,
            'after_space' => $number . "\u{00A0}" . $symbol,
            'before_space' => $symbol . "\u{00A0}" . $number,
            default => $symbol . $number,
        };
        return ($minor < 0 ? '-' : '') . $out;
    }

    private static function group(string $digits, string $sep): string
    {
        if ($sep === '' || strlen($digits) <= 3) {
            return $digits;
        }
        return strrev(implode(strrev($sep), str_split(strrev($digits), 3)));
    }

    /**
     * Amount in the unit the payment providers expect (the currency's ISO minor unit). Settings never allow more
     * shop decimals than the ISO exponent, so this is always an exact integer.
     */
    public static function providerAmount(int $minor, ?string $currency = null, ?int $decimals = null): int
    {
        $diff = self::isoExponent($currency ?? self::currency()) - ($decimals ?? self::decimals());
        if ($diff > 0) {
            return $minor * (10 ** $diff);
        }
        if ($diff < 0) {
            $f = 10 ** -$diff;
            return intdiv($minor + intdiv($f, 2), $f);
        }
        return $minor;
    }
}
