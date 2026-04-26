<?php

declare(strict_types=1);

namespace sqrd\Cache;

class Headers
{
    private function __construct() {}

    /**
     * Hop-by-hop headers that must never be stored in a cache sidecar.
     */
    private const HOP_BY_HOP = [
        'connection',
        'keep-alive',
        'proxy-authenticate',
        'proxy-authorization',
        'proxy-connection',
        'te',
        'trailer',
        'transfer-encoding',
        'upgrade',
    ];

    /**
     * Default allow-list of headers worth preserving alongside the cached body.
     * Filterable at runtime via the sqrd_cache_significant_headers filter.
     *
     * @return list<string> lowercase header names
     */
    public static function allowlist(): array
    {
        $defaults = [
            'cache-control',
            'content-language',
            'content-length',
            'content-security-policy',
            'content-type',
            'etag',
            'last-modified',
            'link',
            'permissions-policy',
            'referrer-policy',
            'strict-transport-security',
            'vary',
            'x-content-type-options',
            'x-frame-options',
            'x-robots-tag',
        ];

        /** @var list<string> $list */
        $list = (array) apply_filters('sqrd_cache_significant_headers', $defaults);

        return array_map('strtolower', $list);
    }

    /**
     * Filter headers_list() down to significant entries, replacing Content-Length
     * with the actual body byte count and rejecting CRLF-injection attempts.
     *
     * @param  list<string>       $headers_list  Output of headers_list()
     * @param  int                $body_length   strlen() of the captured response body
     * @return list<array{0:string,1:string}>    [name, value] pairs
     */
    public static function filter(array $headers_list, int $body_length): array
    {
        $allowlist = self::allowlist();
        $hop_by_hop = self::HOP_BY_HOP;

        $pairs = [];

        foreach ($headers_list as $raw) {
            $colon = strpos($raw, ':');
            if ($colon === false) {
                continue;
            }

            $name  = trim(substr($raw, 0, $colon));
            $value = trim(substr($raw, $colon + 1));
            $name_lc = strtolower($name);

            // Never store hop-by-hop.
            if (in_array($name_lc, $hop_by_hop, strict: true)) {
                continue;
            }

            // Only store allow-listed headers.
            if (!in_array($name_lc, $allowlist, strict: true)) {
                continue;
            }

            // Reject header injection — any CR or LF in name or value is a red flag.
            if (preg_match('/[\r\n]/', $name . $value)) {
                error_log('sqrd-page-cache: rejected header with CRLF — ' . $name);
                continue;
            }

            // Skip Content-Length from headers_list(); we recompute it from actual body size.
            if ($name_lc === 'content-length') {
                continue;
            }

            $pairs[] = [$name, $value];
        }

        // Always write Content-Length using the actual body byte count.
        // Insert it immediately after Content-Type when present, otherwise prepend.
        $insert_at = 0;
        foreach ($pairs as $i => [$n]) {
            if (strtolower($n) === 'content-type') {
                $insert_at = $i + 1;
                break;
            }
        }
        array_splice($pairs, $insert_at, 0, [['Content-Length', (string) $body_length]]);

        return $pairs;
    }

    /**
     * Serialize [name, value] pairs to plaintext HTTP-style header block.
     * Each header is terminated by CRLF; the file ends after the last CRLF.
     *
     * @param  list<array{0:string,1:string}> $pairs
     */
    public static function serialize(array $pairs): string
    {
        $out = '';
        foreach ($pairs as [$name, $value]) {
            $out .= $name . ': ' . $value . "\r\n";
        }
        return $out;
    }
}
