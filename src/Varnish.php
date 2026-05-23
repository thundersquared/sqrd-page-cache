<?php

declare(strict_types=1);

namespace sqrd\Cache;

class Varnish
{
    /** Short timeout — purges must never delay a post save. */
    private const TIMEOUT_SECONDS = 2;

    private function __construct() {}

    /**
     * Send a per-URL PURGE to every configured Varnish host, preserving the
     * original URL's Host header so VCL routes to the right backend object.
     */
    public static function purge_url(string $url): void
    {
        $hosts = self::enabled_hosts();
        if ($hosts === []) {
            return;
        }

        $host_header = (string) parse_url($url, PHP_URL_HOST);
        if ($host_header === '') {
            return;
        }

        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');
        self::send($hosts, 'PURGE', $host_header, $path);
    }

    /**
     * @param list<string> $urls
     */
    public static function purge_urls(array $urls): void
    {
        $hosts = self::enabled_hosts();
        if ($hosts === []) {
            return;
        }

        foreach ($urls as $url) {
            self::purge_url($url);
        }
    }

    /**
     * Send a BAN to every configured Varnish host for the current site,
     * scoped by tag prefix so multi-tenant Varnish only invalidates this domain.
     */
    public static function purge_all(): void
    {
        $hosts = self::enabled_hosts();
        if ($hosts === []) {
            return;
        }

        $home = (string) home_url('/');
        $host_header = (string) parse_url($home, PHP_URL_HOST);
        if ($host_header === '') {
            return;
        }

        self::send($hosts, 'BAN', $host_header, '/');
    }

    // -------------------------------------------------------------------------

    /**
     * @param list<string> $hosts
     */
    private static function send(array $hosts, string $method, string $host_header, string $path): void
    {
        $headers = ['Host' => $host_header];
        $prefix  = self::tag_prefix();
        if ($prefix !== '') {
            $headers['X-Cache-Tag-Prefix'] = $prefix;
        }

        foreach ($hosts as $host) {
            $endpoint = rtrim($host, '/') . $path;
            $response = wp_remote_request($endpoint, [
                'method'      => $method,
                'headers'     => $headers,
                'timeout'     => self::TIMEOUT_SECONDS,
                'blocking'    => true,
                'redirection' => 0,
                'sslverify'   => false,
            ]);

            if (is_wp_error($response)) {
                error_log(sprintf(
                    'sqrd-page-cache: varnish %s %s failed (%s): %s',
                    $method,
                    $endpoint,
                    $host_header,
                    $response->get_error_message()
                ));
            }
        }
    }

    /**
     * @return list<string> Normalized scheme://host[:port] endpoints, or [] if disabled.
     */
    private static function enabled_hosts(): array
    {
        if (!get_option('sqrd_varnish_enabled', false)) {
            return [];
        }

        $raw = get_option('sqrd_varnish_hosts', []);
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $entry) {
            $entry = trim((string) $entry);
            if ($entry === '') {
                continue;
            }
            if (!preg_match('#^https?://#i', $entry)) {
                $entry = 'http://' . $entry;
            }
            $out[] = $entry;
        }
        return $out;
    }

    private static function tag_prefix(): string
    {
        return trim((string) get_option('sqrd_varnish_tag_prefix', ''));
    }
}
