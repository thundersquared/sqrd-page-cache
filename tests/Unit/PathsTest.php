<?php

declare(strict_types=1);

use sqrd\Cache\Paths;

// The cache_root() uses apply_filters which Pest.php stubs to return the 2nd
// arg — i.e. WP_CONTENT_DIR . '/cache/sqrd-page-cache'.  We define the
// constant here so the stub resolves to a deterministic path.
if (!defined('WP_CONTENT_DIR')) {
    define('WP_CONTENT_DIR', '/var/www/html/wp-content');
}

// ── normalize_uri ─────────────────────────────────────────────────────────────

describe('Paths::normalize_uri', function (): void {
    it('returns / unchanged', function (): void {
        expect(Paths::normalize_uri('/'))->toBe('/');
    });

    it('forces trailing slash on bare path', function (): void {
        expect(Paths::normalize_uri('/about'))->toBe('/about/');
    });

    it('preserves existing trailing slash', function (): void {
        expect(Paths::normalize_uri('/about/'))->toBe('/about/');
    });

    it('strips query string', function (): void {
        expect(Paths::normalize_uri('/about?foo=bar'))->toBe('/about/');
    });

    it('strips query string and forces trailing slash', function (): void {
        expect(Paths::normalize_uri('/blog/post?utm_source=x&utm_medium=email'))->toBe('/blog/post/');
    });

    it('throws on raw .. traversal — caller must bail', function (): void {
        expect(fn() => Paths::normalize_uri('/foo/../etc/passwd'))
            ->toThrow(\InvalidArgumentException::class);
    });

    it('throws on traversal in the middle', function (): void {
        expect(fn() => Paths::normalize_uri('/safe/../unsafe'))
            ->toThrow(\InvalidArgumentException::class);
    });

    it('throws on percent-encoded traversal (%2e%2e)', function (): void {
        expect(fn() => Paths::normalize_uri('/foo/%2e%2e/bar'))
            ->toThrow(\InvalidArgumentException::class);
        expect(fn() => Paths::normalize_uri('/foo/%2E%2E/bar'))
            ->toThrow(\InvalidArgumentException::class);
    });

    it('normalises multiple consecutive slashes', function (): void {
        expect(Paths::normalize_uri('//double//slash//'))->toBe('/double/slash/');
    });

    it('handles empty string gracefully', function (): void {
        expect(Paths::normalize_uri(''))->toBe('/');
    });

    it('handles nested paths', function (): void {
        expect(Paths::normalize_uri('/blog/2025/hello-world'))->toBe('/blog/2025/hello-world/');
    });

    it('preserves trailing slash on nested paths', function (): void {
        expect(Paths::normalize_uri('/blog/2025/hello-world/'))->toBe('/blog/2025/hello-world/');
    });
});

// ── file_for ──────────────────────────────────────────────────────────────────

describe('Paths::file_for', function (): void {
    it('builds the correct path for the home page', function (): void {
        $expected = '/var/www/html/wp-content/cache/sqrd-page-cache/example.com/index.html';
        expect(Paths::file_for('example.com', '/', 'html'))->toBe($expected);
    });

    it('builds the correct path for an inner page', function (): void {
        $expected = '/var/www/html/wp-content/cache/sqrd-page-cache/example.com/about/index.html';
        expect(Paths::file_for('example.com', '/about/', 'html'))->toBe($expected);
    });

    it('builds the correct path for a markdown variant', function (): void {
        $expected = '/var/www/html/wp-content/cache/sqrd-page-cache/example.com/about/index.md';
        expect(Paths::file_for('example.com', '/about/', 'md'))->toBe($expected);
    });

    it('normalises the URI (adds trailing slash)', function (): void {
        $expected = '/var/www/html/wp-content/cache/sqrd-page-cache/example.com/about/index.html';
        expect(Paths::file_for('example.com', '/about', 'html'))->toBe($expected);
    });

    it('strips illegal characters from the host', function (): void {
        $path = Paths::file_for('example.com; rm -rf /', '/page/', 'html');
        expect($path)->not->toContain(';');
        expect($path)->not->toContain(' ');
    });

    it('handles port in host', function (): void {
        $path = Paths::file_for('localhost:8080', '/', 'html');
        expect($path)->toContain('localhost:8080');
    });

    it('rejects a Host header that reduces to ".."', function (): void {
        // Sanitiser previously preserved bare dots, so `Host: ..` produced
        // cache_root/../index.html and escaped the cache root.
        expect(fn() => Paths::file_for('..', '/', 'html'))
            ->toThrow(\InvalidArgumentException::class);
    });

    it('rejects a Host header that reduces to "."', function (): void {
        expect(fn() => Paths::file_for('.', '/', 'html'))
            ->toThrow(\InvalidArgumentException::class);
    });

    it('rejects a Host header beginning with a dot', function (): void {
        expect(fn() => Paths::file_for('.example.com', '/', 'html'))
            ->toThrow(\InvalidArgumentException::class);
    });

    it('rejects an empty Host header', function (): void {
        expect(fn() => Paths::file_for('', '/', 'html'))
            ->toThrow(\InvalidArgumentException::class);
    });

    it('rejects a Host header that contains a traversal sequence', function (): void {
        // "a..b" survives the character class strip — make sure the post-strip
        // double-dot check still rejects it.
        expect(fn() => Paths::file_for('a..b', '/', 'html'))
            ->toThrow(\InvalidArgumentException::class);
    });

    it('rejects a Host header whose unsafe characters strip down to ".."', function (): void {
        // `..!@#` reduces to `..` after the character-class filter.
        expect(fn() => Paths::file_for('..!@#', '/', 'html'))
            ->toThrow(\InvalidArgumentException::class);
    });
});
