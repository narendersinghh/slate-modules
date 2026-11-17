<?php
declare(strict_types=1);

namespace SlateModules\WpImport;

/**
 * An uploaded copy of wp-content/uploads (.zip): media files are taken from it instead of being downloaded.
 * Extraction is defensive: only media / document extensions, no path traversal, no symlinks, no PHP or other
 * executable files, limits on count and size. The files stay in the private work folder until imported.
 */
final class Archive
{
    public const EXT = ['jpg', 'jpeg', 'jpe', 'png', 'gif', 'webp', 'avif', 'svg', 'ico', 'bmp', 'tif', 'tiff', 'heic', 'pdf', 'doc', 'docx', 'xls', 'xlsx',
        'ppt', 'pptx', 'odt', 'ods', 'odp', 'txt', 'csv', 'rtf', 'mp4', 'm4v', 'mov', 'webm', 'ogv', 'mp3', 'm4a', 'wav', 'ogg', 'oga', 'woff', 'woff2', 'ttf', 'otf'];
    private const MAX_FILES = 100000;
    private const MAX_FILE = 300 * 1024 * 1024;
    private const MAX_TOTAL = 20 * 1024 * 1024 * 1024;

    public static function dir(): string
    {
        return Importer::WORK . '/uploads';
    }

    /** Extract the media of an uploads archive. Returns ['files' => n, 'skipped' => n, 'bytes' => n]. */
    public static function extract(string $zipFile): array
    {
        if (!class_exists('ZipArchive')) {
            throw new \RuntimeException('The PHP zip extension is needed to read the archive.');
        }
        $zip = new \ZipArchive();
        if ($zip->open($zipFile, \ZipArchive::RDONLY) !== true) {
            throw new \RuntimeException('That archive could not be opened. Upload a .zip of wp-content/uploads.');
        }
        $dir = self::dir();
        @mkdir($dir, 0755, true);
        $stats = ['files' => 0, 'skipped' => 0, 'bytes' => 0];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $st = $zip->statIndex($i);
            if (!$st || str_ends_with((string)$st['name'], '/')) {
                continue;
            }
            $rel = self::relative((string)$st['name']);
            $ext = strtolower(pathinfo((string)$rel, PATHINFO_EXTENSION));
            // Unix symlinks have mode 0120000 in the upper 16 bits of the external attributes.
            $isLink = $zip->getExternalAttributesIndex($i, $opsys, $attr) && $opsys === \ZipArchive::OPSYS_UNIX && (($attr >> 16) & 0170000) === 0120000;
            if ($rel === null || $isLink || !in_array($ext, self::EXT, true) || (int)$st['size'] > self::MAX_FILE
                || $stats['files'] >= self::MAX_FILES || $stats['bytes'] + (int)$st['size'] > self::MAX_TOTAL) {
                $stats['skipped']++;
                continue;
            }
            $dest = $dir . '/' . $rel;
            @mkdir(dirname($dest), 0755, true);
            $in = $zip->getStream((string)$st['name']);
            $out = $in ? @fopen($dest, 'wb') : false;
            if (!$in || !$out) {
                $stats['skipped']++;
                continue;
            }
            $copied = stream_copy_to_stream($in, $out, self::MAX_FILE + 1);
            fclose($in);
            fclose($out);
            if ($copied === false || $copied > self::MAX_FILE) {
                @unlink($dest);
                $stats['skipped']++;
                continue;
            }
            $stats['files']++;
            $stats['bytes'] += (int)$copied;
            if ($stats['files'] % 500 === 0) {
                @set_time_limit(300);
            }
        }
        $zip->close();
        Data::noteSource('uploads', $stats);
        return $stats;
    }

    /**
     * Path inside the uploads folder for an archive entry: "wp-content/uploads/2024/05/a.jpg", "uploads/2024/05/a.jpg"
     * and "2024/05/a.jpg" all give "2024/05/a.jpg". Null for anything unsafe.
     */
    public static function relative(string $name): ?string
    {
        $name = str_replace('\\', '/', $name);
        if (str_starts_with($name, '/') || preg_match('#^[a-z]:#i', $name) || str_contains($name, "\0")) {
            return null;
        }
        $parts = array_values(array_filter(explode('/', $name), static fn($p) => $p !== '' && $p !== '.'));
        if (!$parts || in_array('..', $parts, true) || preg_grep('/^(__MACOSX|\..*)$/', $parts)) {
            return null;
        }
        $pos = array_search('uploads', $parts, true);
        if ($pos !== false) {
            $parts = array_slice($parts, $pos + 1);
        }
        if (!$parts) {
            return null;
        }
        foreach ($parts as $p) {
            if (preg_match('/\.(php\d?|phtml|phar|pht|cgi|pl|py|sh|htaccess|htpasswd|ini|exe|js|html?)($|\.)/i', $p)) {
                return null;
            }
        }
        return implode('/', array_map(static fn($p) => preg_replace('/[^\w.@+ ()-]/u', '-', $p), $parts));
    }

    /** Remove the extracted files (new source, or reset). */
    public static function clear(): void
    {
        $dir = self::dir();
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }

    /** Local copy of an uploads URL path (/wp-content/uploads/2024/05/a.jpg), when the archive had it. */
    public static function localUpload(string $urlPath): ?string
    {
        $path = rawurldecode($urlPath);
        $pos = strpos($path, '/uploads/');
        if ($pos === false || !is_dir(self::dir())) {
            return null;
        }
        $rel = self::relative(substr($path, $pos + 9));
        if ($rel === null) {
            return null;
        }
        $file = self::dir() . '/' . $rel;
        $real = realpath($file);
        $root = realpath(self::dir());
        return $real && $root && str_starts_with($real, $root . DIRECTORY_SEPARATOR) && is_file($real) ? $real : null;
    }
}
