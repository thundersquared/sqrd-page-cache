<?php

declare(strict_types=1);

namespace sqrd\Cache;

class Output
{
    private static bool $buffering = false;
    private static bool $enabled   = true;

    private function __construct() {}

    /**
     * Open the output buffer. Called on init priority 0.
     * Bails out cheaply if the request is obviously not cacheable.
     */
    public static function start(): void
    {
        if (!self::$enabled) {
            return;
        }

        // Only cache GET and HEAD.
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if ($method !== 'GET' && $method !== 'HEAD') {
            return;
        }

        // Skip admin, AJAX, cron, REST.
        if (defined('DOING_AJAX') && DOING_AJAX) {
            return;
        }
        if (defined('DOING_CRON') && DOING_CRON) {
            return;
        }
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return;
        }
        if (is_admin()) {
            return;
        }

        // Skip if query string present (bypass matches nginx).
        if (!empty($_GET)) {
            return;
        }

        // Skip if user is already logged in (determined by cookie at this early stage).
        if (self::has_bypass_cookie()) {
            return;
        }

        // Skip if another plugin disabled caching via the DONOTCACHEPAGE constant.
        if (defined('DONOTCACHEPAGE')) {
            return;
        }

        ob_start([self::class, 'finish']);
        self::$buffering = true;
    }

    /**
     * Output buffer callback. Runs when WordPress flushes the buffer.
     * Decides whether to persist the response, then returns it unchanged.
     */
    public static function finish(string $buffer): string
    {
        if (!self::$buffering || !self::$enabled) {
            return $buffer;
        }

        // Empty response — nothing to cache.
        if (trim($buffer) === '') {
            return $buffer;
        }

        // Skip if DONOTCACHEPAGE was defined after start() ran.
        if (defined('DONOTCACHEPAGE')) {
            return $buffer;
        }

        // WP-specific skip conditions (only available after WP has fully booted).
        if (function_exists('is_user_logged_in') && is_user_logged_in()) {
            return $buffer;
        }
        if (function_exists('is_search') && is_search()) {
            return $buffer;
        }
        if (function_exists('is_feed') && is_feed()) {
            return $buffer;
        }
        if (function_exists('is_preview') && is_preview()) {
            return $buffer;
        }
        if (function_exists('is_404') && is_404()) {
            return $buffer;
        }
        if (function_exists('post_password_required') && post_password_required()) {
            return $buffer;
        }

        // Only cache 200 OK.
        $status = http_response_code();
        if ($status !== 200 && $status !== false) {
            return $buffer;
        }

        // Determine cache extension from actual response Content-Type.
        $content_type = self::response_content_type();
        if ($content_type === null) {
            // No Content-Type set; assume HTML.
            $content_type = 'text/html';
        }

        $response_ext = Negotiation::ext_for_response_content_type($content_type);
        if ($response_ext === null) {
            // Non-cacheable type (JSON, XML, feed, etc.).
            return $buffer;
        }

        // Parity guard: Accept header must agree with what WP returned.
        // If they disagree, caching would poison nginx's lookup table.
        $accept_ext = Negotiation::ext_for_accept($_SERVER['HTTP_ACCEPT'] ?? '');
        if ($accept_ext !== $response_ext) {
            return $buffer;
        }

        // Check user-defined exclude paths.
        $request_uri = $_SERVER['REQUEST_URI'] ?? '/';
        if (self::is_excluded($request_uri)) {
            return $buffer;
        }

        // All checks passed — write to cache.
        $host = $_SERVER['HTTP_HOST'] ?? (string) parse_url((string) home_url(), PHP_URL_HOST);
        $path = Paths::file_for($host, $request_uri, $response_ext);

        $compress = (bool) get_option('sqrd_cache_compress', true);

        try {
            Store::write_body($path, $buffer, $compress);
        } catch (\Throwable $e) {
            error_log('sqrd-page-cache: body write error — ' . $e->getMessage());
            return $buffer;
        }

        try {
            $pairs = Headers::filter(headers_list(), strlen($buffer));
            Store::write_headers($path, $pairs);
        } catch (\Throwable $e) {
            error_log('sqrd-page-cache: headers write error — ' . $e->getMessage());
            // Don't abort — body is already cached, sidecar failure is non-fatal.
        }

        return $buffer;
    }

    public static function disable(): void
    {
        self::$enabled = false;
    }

    // -------------------------------------------------------------------------

    private static function has_bypass_cookie(): bool
    {
        foreach (array_keys($_COOKIE) as $name) {
            if (
                str_starts_with($name, 'wordpress_logged_in_') ||
                str_starts_with($name, 'comment_author_')       ||
                str_starts_with($name, 'wp-postpass_')
            ) {
                return true;
            }
        }
        return false;
    }

    private static function response_content_type(): ?string
    {
        foreach (headers_list() as $header) {
            if (stripos($header, 'Content-Type:') === 0) {
                return trim(substr($header, strlen('Content-Type:')));
            }
        }
        return null;
    }

    private static function is_excluded(string $request_uri): bool
    {
        $patterns = get_option('sqrd_cache_exclude_paths', []);
        if (!is_array($patterns)) {
            return false;
        }
        $path = strtok($request_uri, '?');
        foreach ($patterns as $pattern) {
            $pattern = trim((string) $pattern);
            if ($pattern === '') {
                continue;
            }
            // Treat each line as a regex if wrapped in delimiters, or a plain path prefix otherwise.
            if (@preg_match($pattern, '') !== false) {
                if (preg_match($pattern, $path)) {
                    return true;
                }
            } elseif (str_starts_with($path, $pattern)) {
                return true;
            }
        }
        return false;
    }
}
