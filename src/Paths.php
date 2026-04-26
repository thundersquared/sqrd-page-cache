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
     */
    public static function normalize_uri(string $uri): string
    {
        // Strip query string.
        $pos = strpos($uri, '?');
        if ($pos !== false) {
            $uri = substr($uri, 0, $pos);
        }

        // Reject traversal.
        if (str_contains($uri, '..')) {
            return '/';
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
     * Resolve the absolute path on disk for a cache file.
     *
     * Example:
     *   file_for('example.com', '/about/', 'html')
     *   → /var/www/wp-content/cache/sqrd-page-cache/example.com/about/index.html
     */
    public static function file_for(string $host, string $uri, string $ext): string
    {
        $normalized = self::normalize_uri($uri);

        // Sanitize host: allow only hostname-safe characters.
        $host = preg_replace('#[^a-zA-Z0-9.\-:]#', '', $host) ?? 'unknown';

        // Cache root lives two directories above this file: plugin_root/wp-content/cache/...
        // In practice, callers pass the resolved cache_dir from Settings.
        $cache_root = self::cache_root();

        return $cache_root . '/' . $host . $normalized . 'index.' . $ext;
    }

    public static function cache_root(): string
    {
        return (string) apply_filters(
            'sqrd_cache_root',
            WP_CONTENT_DIR . '/cache/sqrd-page-cache'
        );
    }
}
