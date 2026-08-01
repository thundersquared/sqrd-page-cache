<?php

declare(strict_types=1);

namespace sqrd\Cache;

/**
 * Determines which cache variant (html vs md) applies to a given request or response.
 *
 * Uses a simple substring match — no RFC 7231 q-value parsing — so the rule stays
 * identical to what the nginx `if ($http_accept ~* "text/markdown")` block does.
 * Both sides MUST change in lockstep if a more sophisticated algorithm is needed.
 */
class Negotiation
{
    private function __construct() {}

    /**
     * Map an Accept header value to a cache file extension.
     *
     * @return 'html'|'md'
     */
    public static function ext_for_accept(string $accept): string
    {
        if (stripos($accept, 'text/markdown') !== false) {
            return 'md';
        }

        return 'html';
    }

    /**
     * Map a response Content-Type header value to a cache file extension.
     *
     * Returns null when the content type is neither HTML nor Markdown — meaning
     * the response should not be written to cache at all (JSON, XML, feeds, etc.).
     *
     * @return 'html'|'md'|null
     */
    public static function ext_for_response_content_type(string $content_type): ?string
    {
        $base = strtolower(strtok($content_type, ';'));

        return match (trim($base)) {
            'text/html', 'application/xhtml+xml' => 'html',
            'text/markdown', 'text/x-markdown'   => 'md',
            default                               => null,
        };
    }

    /**
     * Map an Accept header value to a next-gen image extension.
     *
     * Mirrors the nginx `$sqrd_img_ext` resolution in nginx/sqrd-page-cache.conf
     * exactly: AVIF wins over WebP when both are accepted, and null is returned
     * when neither is present. Both sides MUST change in lockstep if the
     * priority or supported formats ever change.
     *
     * Not invoked on the image request path today — upload images are served
     * directly by nginx and never touch PHP. The method exists as a parity
     * anchor so the nginx rule has a testable PHP mirror, and so a future
     * PHP-side delivery layer (e.g. a <picture> fallback) can reuse it.
     *
     * @return 'avif'|'webp'|null
     */
    public static function image_ext_for_accept(string $accept): ?string
    {
        if (stripos($accept, 'image/avif') !== false) {
            return 'avif';
        }
        if (stripos($accept, 'image/webp') !== false) {
            return 'webp';
        }
        return null;
    }
}
