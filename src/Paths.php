<?php

declare(strict_types=1);

namespace sqrd\Cache;

class Paths
{
    private function __construct() {}

    /**
     * Normalize a request URI for use as a cache key.
     *
     * - Strips query string
     * - Rejects path traversal sequences
     * - Forces a trailing slash so /about and /about/ resolve identically
     *
     * @throws \InvalidArgumentException when the URI contains traversal segments
     *         (raw `..` or percent-encoded `%2e%2e`). Callers MUST bail rather than
     *         silently substitute a fallback — collapsing traversal to '/' previously
     *         allowed cache poisoning of the home page.
     */
    public static function normalize_uri(string $uri): string
    {
        // Strip query string.
        $pos = strpos($uri, '?');
        if ($pos !== false) {
            $uri = substr($uri, 0, $pos);
        }

        // Reject traversal in both raw and percent-encoded forms.
        if (str_contains($uri, '..') || stripos($uri, '%2e%2e') !== false) {
            throw new \InvalidArgumentException('Refusing to normalize URI with traversal sequence');
        }

        // Normalize multiple slashes.
        $uri = preg_replace('#/{2,}#', '/', $uri) ?? $uri;

        if ($uri === '' || $uri === '/') {
            return '/';
        }

        // Force trailing slash.
        if (!str_ends_with($uri, '/')) {
            $uri .= '/';
        }

        return $uri;
    }

    /**
     * Sanitize an HTTP Host header for use as a cache directory name.
     *
     * Accepts only RFC 1123 host characters plus an optional ":port" suffix.
     * Rejects bare/leading dots, traversal, and empty results — anything that
     * could escape the cache root once joined with the cache path.
     *
     * @throws \InvalidArgumentException when the host cannot be reduced to a
     *         safe single directory segment.
     */
    public static function normalize_host(string $host): string
    {
        // Strip everything that is not a hostname or port character.
        $host = preg_replace('#[^a-zA-Z0-9.\-:]#', '', $host) ?? '';

        // Refuse anything that resolves to traversal once joined to a path.
        if (
            $host === ''
            || $host === '.'
            || $host === '..'
            || str_starts_with($host, '.')
            || str_starts_with($host, '-')
            || str_contains($host, '..')
            || str_contains($host, '/')
        ) {
            throw new \InvalidArgumentException('Refusing unsafe Host header value');
        }

        return $host;
    }

    /**
     * Resolve the absolute path on disk for a cache file.
     *
     * Example:
     *   file_for('example.com', '/about/', 'html')
     *   → /var/www/wp-content/cache/sqrd-page-cache/example.com/about/index.html
     *
     * @throws \InvalidArgumentException when host or URI fails validation.
     */
    public static function file_for(string $host, string $uri, string $ext): string
    {
        $normalized = self::normalize_uri($uri);
        $host       = self::normalize_host($host);

        $cache_root = self::cache_root();

        $path = $cache_root . '/' . $host . $normalized . 'index.' . $ext;

        // Defense in depth: regardless of upstream validation, the resolved path
        // MUST live inside cache_root. realpath() normalises symlinks too.
        $real_root = realpath($cache_root);
        if ($real_root !== false) {
            $real_parent = realpath(\dirname($path));
            if ($real_parent !== false && !str_starts_with($real_parent . '/', $real_root . '/')) {
                throw new \InvalidArgumentException('Cache path escapes cache root');
            }
        }

        return $path;
    }

    public static function cache_root(): string
    {
        return (string) apply_filters(
            'sqrd_page_cache/root',
            WP_CONTENT_DIR . '/cache/sqrd-page-cache'
        );
    }
}
