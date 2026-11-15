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

    /**
     * Replace every string from posted rows: [['source' => 'Read more', 'tr' => ['es' => '…']], ...].
     * @return int number of strings stored
     */
    public static function save(array $rows): int
    {
        $codes = Languages::extraCodes();
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !empty($row['delete'])) {
                continue;
            }
            $source = self::clean((string)($row['source'] ?? ''));
            if ($source === '' || isset($out[$source])) {
                continue;
            }
            $tr = [];
            foreach ($codes as $code) {
                $v = self::clean((string)($row['tr'][$code] ?? ''));
                if ($v !== '') {
                    $tr[$code] = $v;
                }
            }
            $out[$source] = $tr;
            if (count($out) >= self::MAX) {
                break;
            }
        }
        ksort($out, SORT_NATURAL | SORT_FLAG_CASE);
        Options::lazy(self::OPTION);
        Options::set(self::OPTION, $out, false);
        self::$all = null;
        return count($out);
    }

    private static function clean(string $s): string
    {
        $s = str_replace(["\r\n", "\r"], "\n", $s);
        return mb_substr(trim($s), 0, 1000);
    }
}
