<?php

declare(strict_types=1);

use sqrd\Cache\Headers;

// ── serialize ─────────────────────────────────────────────────────────────────

describe('Headers::serialize', function (): void {
    it('serializes a single header pair', function (): void {
        $result = Headers::serialize([['Content-Type', 'text/html; charset=utf-8']]);
        expect($result)->toBe("Content-Type: text/html; charset=utf-8\r\n");
    });

    it('serializes multiple headers in order', function (): void {
        $pairs = [
            ['Content-Type', 'text/markdown; charset=utf-8'],
            ['Cache-Control', 'public, max-age=3600'],
            ['X-Robots-Tag', 'index, follow'],
        ];
        $result = Headers::serialize($pairs);
        expect($result)->toBe(
            "Content-Type: text/markdown; charset=utf-8\r\n" .
            "Cache-Control: public, max-age=3600\r\n" .
            "X-Robots-Tag: index, follow\r\n"
        );
    });

    it('preserves duplicate headers for Link', function (): void {
        $pairs = [
            ['Link', '<https://example.com/wp-json/>; rel="https://api.w.org/"'],
            ['Link', '<https://example.com/?p=42>; rel=shortlink'],
        ];
        $result = Headers::serialize($pairs);
        expect($result)->toBe(
            "Link: <https://example.com/wp-json/>; rel=\"https://api.w.org/\"\r\n" .
            "Link: <https://example.com/?p=42>; rel=shortlink\r\n"
        );
    });

    it('returns empty string for empty pairs', function (): void {
        expect(Headers::serialize([]))->toBe('');
    });

    it('does not append a trailing blank line', function (): void {
        $result = Headers::serialize([['X-Foo', 'bar']]);
        expect($result)->toBe("X-Foo: bar\r\n");
        expect(str_ends_with($result, "\r\n\r\n"))->toBeFalse();
    });
});

// ── filter ────────────────────────────────────────────────────────────────────

describe('Headers::filter', function (): void {
    it('keeps allow-listed headers', function (): void {
        $pairs = Headers::filter(['Cache-Control: public, max-age=3600'], 100);
        $names = array_column($pairs, 0);
        expect($names)->toContain('Cache-Control');
    });

    it('drops non-allow-listed headers', function (): void {
        $pairs = Headers::filter(['X-Powered-By: PHP/8.3', 'X-Generator: WordPress'], 100);
        $names = array_column($pairs, 0);
        expect($names)->not->toContain('X-Powered-By');
        expect($names)->not->toContain('X-Generator');
    });

    it('always includes Content-Length recomputed from body_length', function (): void {
        $pairs = Headers::filter([], 1234);
        $map   = array_column($pairs, 1, 0);
        expect($map)->toHaveKey('Content-Length');
        expect($map['Content-Length'])->toBe('1234');
    });

    it('recomputes Content-Length even when headers_list reports a different value', function (): void {
        $pairs = Headers::filter(['Content-Length: 9999'], 42);
        $map   = array_column($pairs, 1, 0);
        expect($map['Content-Length'])->toBe('42');
    });

    it('drops hop-by-hop headers', function (): void {
        $hopByHop = [
            'Connection: keep-alive',
            'Keep-Alive: timeout=5',
            'Transfer-Encoding: chunked',
            'Upgrade: h2',
            'Proxy-Authorization: Basic xyz',
            'TE: trailers',
            'Trailer: Expires',
        ];
        $pairs = Headers::filter($hopByHop, 0);
        $names = array_map(fn($p) => strtolower($p[0]), $pairs);
        foreach (['connection', 'keep-alive', 'transfer-encoding', 'upgrade', 'proxy-authorization', 'te', 'trailer'] as $hop) {
            expect($names)->not->toContain($hop);
        }
    });

    it('rejects header values containing CR', function (): void {
        $pairs = Headers::filter(["Cache-Control: public\rmalicious"], 0);
        $names = array_column($pairs, 0);
        expect($names)->not->toContain('Cache-Control');
    });

    it('rejects header values containing LF', function (): void {
        $pairs = Headers::filter(["Cache-Control: public\nInjected: yes"], 0);
        $names = array_column($pairs, 0);
        expect($names)->not->toContain('Cache-Control');
    });

    it('rejects header names containing CR or LF', function (): void {
        $pairs = Headers::filter(["X-Fo\ro: bar", "X-Ba\nz: baz"], 0);
        expect($pairs)->each->not->toContain('X-Foo');
    });

    it('inserts Content-Length immediately after Content-Type when present', function (): void {
        $pairs = Headers::filter([
            'Content-Type: text/markdown; charset=utf-8',
            'Cache-Control: public',
        ], 512);
        $names = array_column($pairs, 0);
        $ct_pos = array_search('Content-Type', $names, strict: true);
        $cl_pos = array_search('Content-Length', $names, strict: true);
        expect($cl_pos)->toBe($ct_pos + 1);
    });

    it('preserves order of remaining headers', function (): void {
        $pairs = Headers::filter([
            'Content-Type: text/html',
            'Cache-Control: max-age=60',
            'Last-Modified: Mon, 01 Jan 2025 00:00:00 GMT',
        ], 0);
        $names = array_column($pairs, 0);
        $cache_pos = array_search('Cache-Control', $names, strict: true);
        $lm_pos    = array_search('Last-Modified', $names, strict: true);
        expect($cache_pos)->toBeLessThan($lm_pos);
    });

    it('preserves duplicate Link headers', function (): void {
        $pairs = Headers::filter([
            'Link: <https://example.com/wp-json/>; rel="https://api.w.org/"',
            'Link: <https://example.com/?p=1>; rel=shortlink',
        ], 0);
        $links = array_filter($pairs, fn($p) => $p[0] === 'Link');
        expect(count($links))->toBe(2);
    });

    it('skips malformed headers without colon', function (): void {
        $pairs = Headers::filter(['NotAHeader', 'Cache-Control: public'], 0);
        $names = array_column($pairs, 0);
        expect($names)->not->toContain('NotAHeader');
        expect($names)->toContain('Cache-Control');
    });
});
