<?php
declare(strict_types=1);

namespace SlateModules\Multilingual;

use Slate\Options;

/**
 * Translations of fixed texts used by templates and themes ("Read more", "Search"…).
 * Option "ml_strings" (not autoloaded): ['Read more' => ['es' => 'Leer más', 'fr' => 'Lire la suite'], ...]
 */
final class Strings
{
    public const OPTION = 'ml_strings';
    public const MAX = 2000;

    private static ?array $all = null;

    public static function all(): array
    {
        if (self::$all === null) {
            Options::lazy(self::OPTION);
            $stored = Options::get(self::OPTION, []);
            self::$all = [];
            foreach (is_array($stored) ? $stored : [] as $source => $tr) {
                if (is_string($source) && $source !== '' && is_array($tr)) {
                    self::$all[$source] = array_filter(array_map(static fn($v) => is_scalar($v) ? (string)$v : '', $tr), static fn($v) => $v !== '');
                }
            }
        }
        return self::$all;
    }

    /** Translation of $text in $lang (default: the current language); falls back to $text. */
    public static function translate(string $text, ?string $lang = null): string
    {
        if ($text === '' || !Languages::isActive()) {
            return $text;
        }
        $lang ??= Urls::current();
        if ($lang === Languages::default()) {
            return $text;
        }
        $t = self::all()[$text][$lang] ?? '';
        return $t !== '' ? $t : $text;
    }

    /** Stable key of a string (row id in the dashboard list). */
    public static function key(string $source): string
    {
        return md5($source);
    }

    /** Source text of a key, or null. */
    public static function byKey(string $key): ?string
    {
        foreach (self::all() as $source => $tr) {
            if (self::key((string)$source) === $key) {
                return (string)$source;
            }
        }
        return null;
    }

    /**
     * Update the translations of existing strings: [key => [lang => text]]. Strings not posted are left alone.
     * @return int number of strings updated
     */
    public static function update(array $posted): int
    {
        $codes = Languages::extraCodes();
        $all = self::all();
        $n = 0;
        foreach ($all as $source => $tr) {
            $row = $posted[self::key((string)$source)] ?? null;
            if (!is_array($row)) {
                continue;
            }
            foreach ($codes as $code) {
                if (array_key_exists($code, $row)) {
                    $v = self::clean(is_scalar($row[$code]) ? (string)$row[$code] : '');
                    if ($v === '') {
                        unset($tr[$code]);
                    } else {
                        $tr[$code] = $v;
                    }
                }
            }
            $all[$source] = $tr;
            $n++;
        }
        self::store($all);
        return $n;
    }

    /** Add a string (or update it when it exists). Returns false when the text is empty or the limit is reached. */
    public static function add(string $source, array $tr): bool
    {
        $source = self::clean($source);
        $all = self::all();
        if ($source === '' || (!isset($all[$source]) && count($all) >= self::MAX)) {
            return false;
        }
        $row = $all[$source] ?? [];
        foreach (Languages::extraCodes() as $code) {
            $v = self::clean(is_scalar($tr[$code] ?? null) ? (string)$tr[$code] : '');
            if ($v !== '') {
                $row[$code] = $v;
            }
        }
        $all[$source] = $row;
        self::store($all);
        return true;
    }

    /** Delete strings by key. Returns how many were deleted. */
    public static function delete(array $keys): int
    {
        $keys = array_flip(array_map('strval', $keys));
        $all = self::all();
        $before = count($all);
        $all = array_filter($all, static fn($tr, $source) => !isset($keys[self::key((string)$source)]), ARRAY_FILTER_USE_BOTH);
        self::store($all);
        return $before - count($all);
    }

    private static function store(array $all): void
    {
        ksort($all, SORT_NATURAL | SORT_FLAG_CASE);
        Options::lazy(self::OPTION);
        Options::set(self::OPTION, $all, false);
        self::$all = null;
    }

    private static function clean(string $s): string
    {
        $s = str_replace(["\r\n", "\r"], "\n", $s);
        return mb_substr(trim($s), 0, 1000);
    }
}
