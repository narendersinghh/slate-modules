<?php
declare(strict_types=1);

namespace SlateModules\WpImport;

/**
 * Network access of the importer, limited to the source site (SSRF guard):
 * - only http(s) URLs on the source host, its www variant and its subdomains (e.g. cdn.example.com);
 * - redirects are followed by hand and must stay on those hosts;
 * - addresses in private / reserved ranges are refused unless the source itself lives there (a local test site);
 * - every request has a connect and total timeout and a size limit.
 * Same method names as the REST client of Auto CPT (html, htmlMulti, download, downloadMulti) so callers can swap.
 */
final class Fetch
{
    public const MAX_PAGE = 15 * 1024 * 1024;   // rendered HTML page
    public const MAX_FILE = 200 * 1024 * 1024;  // media / asset download
    private const UA = 'Mozilla/5.0 (compatible; Slate-Importer/2.0)';

    public string $origin;
    private string $host;
    private bool $privateOk;
    /** HTTP status of each URL fetched by the last htmlMulti() call (0 = network or policy error). */
    public array $codes = [];
    /** URLs whose body is wanted even when they answer 404 (e.g. to capture the 404 template). */
    public array $keep404 = [];
    /** Extra hosts the person allowed (e.g. a media CDN named in the options). */
    public array $extraHosts = [];

    public function __construct(string $origin)
    {
        $origin = trim($origin);
        if ($origin !== '' && !preg_match('#^https?://#i', $origin)) {
            $origin = 'https://' . $origin;
        }
        $this->origin = rtrim($origin, '/');
        $this->host = strtolower(preg_replace('/^www\./i', '', (string)parse_url($this->origin, PHP_URL_HOST)));
        $this->privateOk = $this->host !== '' && self::isPrivateHost($this->host);
    }

    /** Is this URL one the importer may request? */
    public function allowed(string $url): bool
    {
        $p = parse_url($url);
        if (!$p || !in_array(strtolower((string)($p['scheme'] ?? '')), ['http', 'https'], true) || empty($p['host']) || isset($p['user']) || isset($p['pass'])) {
            return false;
        }
        $h = strtolower(preg_replace('/^www\./i', '', (string)$p['host']));
        $ok = $h === $this->host || ($this->host !== '' && str_ends_with($h, '.' . $this->host)) || in_array($h, $this->extraHosts, true);
        if (!$ok) {
            return false;
        }
        return $this->privateOk || !self::isPrivateHost((string)$p['host']);
    }

    /** True when the host is (or resolves to) a loopback, private, link-local or reserved address. */
    public static function isPrivateHost(string $host): bool
    {
        $host = trim($host, '[]');
        if (in_array(strtolower($host), ['localhost', 'localhost.localdomain'], true) || str_ends_with(strtolower($host), '.localhost')) {
            return true;
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return true;
            }
        }
        return false;
    }

    private function resolveLocation(string $base, string $loc): string
    {
        if (preg_match('#^https?://#i', $loc)) {
            return $loc;
        }
        $p = parse_url($base);
        $root = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
        if (str_starts_with($loc, '//')) {
            return $p['scheme'] . ':' . $loc;
        }
        if (str_starts_with($loc, '/')) {
            return $root . $loc;
        }
        return $root . preg_replace('#/[^/]*$#', '/', (string)($p['path'] ?? '/')) . $loc;
    }

    private function handle(string $url, int $max, $fh = null, array $headers = [], int $timeout = 45): \CurlHandle
    {
        $ch = curl_init($url);
        $opts = [CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 12, CURLOPT_ENCODING => '',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP, CURLOPT_HTTPHEADER => array_merge(['User-Agent: ' . self::UA], $headers),
            CURLOPT_NOPROGRESS => false, CURLOPT_HEADER => false,
            CURLOPT_XFERINFOFUNCTION => static fn($c, $dlTotal, $dl) => ($dlTotal > $max || $dl > $max) ? 1 : 0];
        if ($fh) {
            $opts[CURLOPT_FILE] = $fh;
        } else {
            $opts[CURLOPT_RETURNTRANSFER] = true;
        }
        curl_setopt_array($ch, $opts);
        return $ch;
    }

    /**
     * One request with hand-followed redirects. Returns [status, body|null, finalUrl].
     * With $dest the body is written to that file instead (body is then null).
     */
    public function request(string $url, int $max = self::MAX_PAGE, ?string $dest = null, array $headers = [], int $timeout = 45): array
    {
        for ($hop = 0; $hop < 5; $hop++) {
            if (!$this->allowed($url)) {
                return [0, null, $url];
            }
            $fh = $dest ? @fopen($dest, 'wb') : null;
            if ($dest && !$fh) {
                return [0, null, $url];
            }
            $ch = $this->handle($url, $max, $fh, $headers, $timeout);
            $body = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $loc = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
            $err = curl_errno($ch);
            unset($ch);
            if ($fh) {
                fclose($fh);
            }
            if ($err) {
                $dest && @unlink($dest);
                return [0, null, $url];
            }
            if ($code >= 300 && $code < 400 && $loc !== '') {
                $url = $this->resolveLocation($url, $loc);
                continue;
            }
            return [$code, $dest ? null : (is_string($body) ? $body : null), $url];
        }
        return [0, null, $url];
    }

    /** Fetch a public page's HTML (200 only). */
    public function html(string $url): ?string
    {
        [$code, $body] = $this->request($url, self::MAX_PAGE, null, ['Accept: text/html']);
        return $code === 200 ? $body : null;
    }

    /** Fetch many public pages in parallel. Returns [url => html|null]; status codes in $this->codes. */
    public function htmlMulti(array $urls, int $concurrency = 8): array
    {
        $this->codes = [];
        $out = [];
        $queue = array_values(array_unique(array_filter($urls)));
        $mh = curl_multi_init();
        $active = [];
        $add = function (string $url, string $orig, int $hops) use (&$active, $mh, &$out): void {
            if (!$this->allowed($url)) {
                $this->codes[$orig] = 0;
                $out[$orig] = null;
                return;
            }
            $ch = $this->handle($url, self::MAX_PAGE, null, ['Accept: text/html']);
            curl_multi_add_handle($mh, $ch);
            $active[spl_object_id($ch)] = [$ch, $url, $orig, $hops];
        };
        while ($queue && count($active) < $concurrency) {
            $u = array_shift($queue);
            $add($u, $u, 0);
        }
        while ($active) {
            curl_multi_exec($mh, $running);
            curl_multi_select($mh, 1.0);
            while ($info = curl_multi_info_read($mh)) {
                $ch = $info['handle'];
                [, $url, $orig, $hops] = $active[spl_object_id($ch)];
                $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $loc = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
                $body = (string)curl_multi_getcontent($ch);
                curl_multi_remove_handle($mh, $ch);
                unset($active[spl_object_id($ch)]);
                if ($code >= 300 && $code < 400 && $loc !== '' && $hops < 4) {
                    $add($this->resolveLocation($url, $loc), $orig, $hops + 1);
                } else {
                    $this->codes[$orig] = $info['result'] === CURLE_OK ? $code : 0;
                    $out[$orig] = $info['result'] === CURLE_OK && ($code === 200 || ($code === 404 && isset($this->keep404[$orig]))) ? $body : null;
                }
                while ($queue && count($active) < $concurrency) {
                    $u = array_shift($queue);
                    $add($u, $u, 0);
                }
            }
            if (!$active && $queue) {
                $u = array_shift($queue);
                $add($u, $u, 0);
            }
        }
        curl_multi_close($mh);
        return $out;
    }

    /** Download a URL to a local file (200 only, size-limited). */
    public function download(string $url, string $dest, int $max = self::MAX_FILE): bool
    {
        [$code] = $this->request($url, $max, $dest, [], 120);
        if ($code !== 200 || !is_file($dest) || filesize($dest) === 0) {
            @unlink($dest);
            return false;
        }
        return true;
    }

    /** Download many files: [url => destPath]. Returns [url => bool]. Sequential redirects, parallel transfers. */
    public function downloadMulti(array $map, int $concurrency = 8): array
    {
        $out = [];
        $mh = curl_multi_init();
        $active = [];
        $queue = $map;
        $add = function (string $url, string $orig, string $dest, int $hops) use (&$active, $mh, &$out): void {
            $fh = $this->allowed($url) ? @fopen($dest, 'wb') : false;
            if (!$fh) {
                $out[$orig] = false;
                return;
            }
            $ch = $this->handle($url, self::MAX_FILE, $fh, [], 60);
            curl_multi_add_handle($mh, $ch);
            $active[spl_object_id($ch)] = [$ch, $url, $orig, $dest, $fh, $hops];
        };
        $next = function () use (&$queue, $add): void {
            $u = (string)array_key_first($queue);
            $d = $queue[$u];
            unset($queue[$u]);
            $add($u, $u, $d, 0);
        };
        while ($queue && count($active) < $concurrency) {
            $next();
        }
        while ($active) {
            curl_multi_exec($mh, $running);
            curl_multi_select($mh, 1.0);
            while ($info = curl_multi_info_read($mh)) {
                $ch = $info['handle'];
                [, $url, $orig, $dest, $fh, $hops] = $active[spl_object_id($ch)];
                fclose($fh);
                $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $loc = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
                curl_multi_remove_handle($mh, $ch);
                unset($active[spl_object_id($ch)]);
                if ($code >= 300 && $code < 400 && $loc !== '' && $hops < 4) {
                    $add($this->resolveLocation($url, $loc), $orig, $dest, $hops + 1);
                } else {
                    $ok = $info['result'] === CURLE_OK && $code === 200 && is_file($dest) && filesize($dest) > 0;
                    if (!$ok) {
                        @unlink($dest);
                    }
                    $out[$orig] = $ok;
                }
                while ($queue && count($active) < $concurrency) {
                    $next();
                }
            }
            if (!$active && $queue) {
                $next();
            }
        }
        curl_multi_close($mh);
        return $out;
    }
}
