<?php

declare(strict_types=1);

namespace sqrd\Cache;

class Store
{
    /** Gzip compression level (0–9). 6 balances ratio and CPU. */
    private const GZIP_LEVEL = 6;
    /** Brotli quality (0–11). 5 mirrors gzencode($body, 6) cost/ratio. */
    private const BROTLI_QUALITY = 5;
    /** Brotli mode literal: 0=GENERIC, 1=TEXT, 2=FONT. Constants aren't defined in older ext-brotli builds. */
    private const BROTLI_MODE_TEXT = 1;

    private function __construct() {}

    /**
     * Write the response body to disk, optionally alongside pre-compressed siblings
     * (.gz always when $compress, .br additionally when ext-brotli is loaded).
     * Uses atomic temp-file + rename to avoid partial reads by nginx.
     *
     * Siblings that would be LARGER than the original are skipped — nginx's
     * gzip_static / brotli_static would otherwise serve the bigger file to any
     * client that sends Accept-Encoding, wasting bandwidth. Without the sibling
     * on disk nginx transparently falls back to the plain file.
     *
     * @throws \RuntimeException on write failure
     */
    public static function write_body(string $path, string $body, bool $compress): void
    {
        self::ensure_dir($path);

        self::atomic_write($path, $body);

        if (!$compress) {
            return;
        }

        $original_size = strlen($body);

        // Gzip sibling — ext-zlib is a hard WordPress dependency, so gzencode is
        // always available. nginx checks gzip_static AFTER brotli_static, so a
        // client that accepts both gets .br when present and .gz otherwise.
        $gz = @gzencode($body, self::gzip_level());
        if ($gz === false) {
            error_log('sqrd-page-cache: gzencode failed — no .gz sibling will be written');
        } elseif (strlen($gz) < $original_size) {
            self::atomic_write($path . '.gz', $gz);
        }

        // Brotli sibling — only when ext-brotli (kjdev/php-ext-brotli) is loaded.
        // nginx serves .br ahead of .gz when the client accepts both, so brotli
        // is the preferred encoding when available.
        if (function_exists('brotli_compress')) {
            $br = @brotli_compress($body, self::brotli_quality(), self::BROTLI_MODE_TEXT);
            if ($br === false) {
                error_log('sqrd-page-cache: brotli_compress failed — no .br sibling will be written');
            } elseif (strlen($br) < $original_size) {
                self::atomic_write($path . '.br', $br);
            }
        }
    }

    /**
     * Resolved gzip compression level (0–9), filterable via
     * `sqrd_page_cache/gzip_level`. Clamped to the valid zlib range.
     */
    public static function gzip_level(): int
    {
        /** @var int|numeric-string $level */
        $level = apply_filters('sqrd_page_cache/gzip_level', self::GZIP_LEVEL);
        return max(0, min(9, (int) $level));
    }

    /**
     * Resolved brotli compression quality (0–11), filterable via
     * `sqrd_page_cache/brotli_quality`. Clamped to the valid ext-brotli range.
     * The kjdev ext-brotli default is 11 (max); 5 is used here to mirror the
     * cost/ratio of gzencode level 6 on typical HTML.
     */
    public static function brotli_quality(): int
    {
        /** @var int|numeric-string $quality */
        $quality = apply_filters('sqrd_page_cache/brotli_quality', self::BROTLI_QUALITY);
        return max(0, min(11, (int) $quality));
    }

    /**
     * Write the headers sidecar file (plaintext HTTP-style block).
     *
     * @param list<array{0:string,1:string}> $pairs
     * @throws \RuntimeException on write failure
     */
    public static function write_headers(string $path, array $pairs): void
    {
        self::ensure_dir($path);
        self::atomic_write($path . '.headers', Headers::serialize($pairs));
    }

    /**
     * Delete all cache files for a fully-qualified URL across both variants.
     */
    public static function purge_url(string $url): void
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '/');

        foreach (['html', 'md'] as $ext) {
            $base = Paths::file_for($host, $path, $ext);
            foreach ([$base, $base . '.gz', $base . '.br', $base . '.headers'] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }
    }

    /**
     * Delete all cached files for a set of URLs, then also clear the parent directory
     * if it's now empty. Useful when post archives/home are also invalidated.
     *
     * @param list<string> $urls
     */
    public static function purge_urls(array $urls): void
    {
        foreach ($urls as $url) {
            self::purge_url($url);
        }
    }

    /**
     * Wipe the entire cache directory and recreate it empty.
     */
    public static function flush_all(): void
    {
        $root = Paths::cache_root();
        if (is_dir($root)) {
            self::rmdir_recursive($root);
        }
        wp_mkdir_p($root);
    }

    /**
     * Return basic cache statistics.
     *
     * @return array{files:int,bytes:int,oldest:int|null,newest:int|null}
     */
    public static function stats(): array
    {
        $root = Paths::cache_root();
        if (!is_dir($root)) {
            return ['files' => 0, 'bytes' => 0, 'oldest' => null, 'newest' => null];
        }

        $files  = 0;
        $bytes  = 0;
        $oldest = null;
        $newest = null;

        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iter as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $files++;
            $bytes += $file->getSize();
            $mtime = $file->getMTime();
            if ($oldest === null || $mtime < $oldest) {
                $oldest = $mtime;
            }
            if ($newest === null || $mtime > $newest) {
                $newest = $mtime;
            }
        }

        return compact('files', 'bytes', 'oldest', 'newest');
    }

    // -------------------------------------------------------------------------

    private static function ensure_dir(string $file_path): void
    {
        $dir = dirname($file_path);
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
    }

    private static function atomic_write(string $path, string $data): void
    {
        // Cryptographically random suffix — getmypid() alone collided when a
        // single PHP-FPM worker handled overlapping writes (sub-requests,
        // fastcgi_finish_request continuations) and could publish a partial
        // body via rename.
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(8));
        $written = file_put_contents($tmp, $data, LOCK_EX);
        if ($written === false) {
            @unlink($tmp);
            throw new \RuntimeException("sqrd-page-cache: write failed for {$path}");
        }
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException("sqrd-page-cache: rename failed for {$path}");
        }
    }

    /**
     * Recursively delete a directory tree WITHOUT descending through symlinks.
     *
     * `RecursiveDirectoryIterator` follows symlinked directories by default,
     * which would let any symlink planted in the cache root expand the blast
     * radius of `flush_all` to arbitrary filesystem locations writable by the
     * PHP user.
     */
    private static function rmdir_recursive(string $dir): void
    {
        $dh = @opendir($dir);
        if ($dh === false) {
            return;
        }

        while (($entry = readdir($dh)) !== false) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;

            // Treat symlinks as files — unlink the link itself, never traverse it.
            if (is_link($path)) {
                @unlink($path);
                continue;
            }

            if (is_dir($path)) {
                self::rmdir_recursive($path);
            } else {
                @unlink($path);
            }
        }
        closedir($dh);

        @rmdir($dir);
    }
}
