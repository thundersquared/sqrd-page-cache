<?php

declare(strict_types=1);

namespace sqrd\Cache;

class Store
{
    private function __construct() {}

    /**
     * Write the response body to disk, optionally alongside a pre-compressed sibling.
     * Uses atomic temp-file + rename to avoid partial reads by nginx.
     *
     * @throws \RuntimeException on write failure
     */
    public static function write_body(string $path, string $body, bool $compress): void
    {
        self::ensure_dir($path);

        self::atomic_write($path, $body);

        if ($compress) {
            $gz = gzencode($body, 6);
            if ($gz !== false) {
                self::atomic_write($path . '.gz', $gz);
            }
        }
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
            foreach ([$base, $base . '.gz', $base . '.headers'] as $file) {
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
        $tmp = $path . '.tmp.' . getmypid();
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

    private static function rmdir_recursive(string $dir): void
    {
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iter as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }
}
