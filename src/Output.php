<?php

declare(strict_types=1);

namespace sqrd\Cache;

class Output
{
    private static bool $buffering = false;
    private static bool $enabled   = true;

    /**
     * Query-string parameters that should NOT bust the cache. Default list covers
     * the common analytics/ad-click trackers. Glob suffix `*` matches any prefix:
     *   _ga_*  matches _ga_XM7C2CX85R, _ga_ANYTHING
     *   utm_*  matches utm_source, utm_medium, ...
     *
     * Extend at runtime:
     *   add_filter('sqrd_cache_tracking_params', fn(array $p): array =>
     *       [...$p, '_clck', 'ttclid', 'twclid']
     *   );
     *
     * Note: the nginx include carries its own hardcoded copy of these patterns
     * (nginx can't read WP options). Edit nginx/sqrd-page-cache.conf if you add
     * patterns and want the bypass relaxation to apply on cache HIT lookups too.
     */
    private const TRACKING_PATTERNS = [
        '_ga', '_ga_*', '_gl',
        'utm_*',
        'fbclid', 'gclid', 'msclkid',
        'mc_cid', 'mc_eid',
        'yclid', 'dclid',
    ];

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

        // Skip if query string contains anything beyond tracking params.
        // Bypass logic mirrors the nginx include — keep both in sync if you extend
        // the tracking pattern list via the sqrd_cache_tracking_params filter.
        if (!self::is_tracking_only($_GET)) {
            return;
        }

        // Skip if user is already logged in (determined by cookie at this early stage).
        if (self::has_bypass_cookie()) {
            return;
        }

        // Skip if another plugin disabled caching via the DONOTCACHEPAGE constant.
        // Opt-out via filter for setups whose cache is variant-isolated (e.g. Accept-keyed)
        // and can safely ignore generic "uncacheable" signals from other plugins.
        if (defined('DONOTCACHEPAGE') && apply_filters('sqrd_page_cache/respect_donotcachepage', false)) {
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
        if (defined('DONOTCACHEPAGE') && apply_filters('sqrd_page_cache/respect_donotcachepage', false)) {
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

        // Minify HTML variants before persisting. Markdown is left untouched —
        // minifying markdown would corrupt list/paragraph structure. Disk and
        // live response stay byte-identical because we reassign $buffer here.
        if ($response_ext === 'html') {
            $buffer = Minifier::html($buffer);
            // Drop any stale Content-Length so the smaller body isn't truncated
            // by an oversized value WP or another plugin may have set.
            if (!headers_sent()) {
                header_remove('Content-Length');
            }
        }

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

    /**
     * Return the active list of tracking parameter patterns (defaults + filter).
     *
     * @return list<string>
     */
    public static function tracking_params(): array
    {
        /** @var list<string> $patterns */
        $patterns = (array) apply_filters('sqrd_cache_tracking_params', self::TRACKING_PATTERNS);
        return $patterns;
    }

    /**
     * True when every key in $args matches a tracking pattern (or $args is empty).
     * False when at least one key looks like a real query parameter.
     *
     * @param array<string,mixed> $args
     */
    public static function is_tracking_only(array $args): bool
    {
        if ($args === []) {
            return true;
        }

        $patterns = self::tracking_params();
        foreach (array_keys($args) as $key) {
            if (!self::matches_any((string) $key, $patterns)) {
                return false;
            }
        }
        return true;
    }

    // -------------------------------------------------------------------------

    /**
     * @param list<string> $patterns
     */
    private static function matches_any(string $key, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (str_ends_with($pattern, '*')) {
                if (str_starts_with($key, substr($pattern, 0, -1))) {
                    return true;
                }
            } elseif ($pattern === $key) {
                return true;
            }
        }
        return false;
    }

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
