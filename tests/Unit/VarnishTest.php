<?php

declare(strict_types=1);

use Brain\Monkey;
use sqrd\Cache\Varnish;

// Minimal WP_Error stub so is_wp_error()/get_error_message() work without WP loaded.
if (!class_exists('WP_Error')) {
    class WP_Error
    {
        public function __construct(private string $message = 'error') {}
        public function get_error_message(): string { return $this->message; }
    }
}

/**
 * Configure WP option + HTTP stubs shared by every Varnish test.
 * Returns an ArrayObject of captured wp_remote_request calls — pass-by-reference
 * semantics so the caller sees mutations the stubbed closure makes.
 *
 * @param array{
 *   enabled?: bool,
 *   hosts?: list<string>,
 *   prefix?: string,
 *   home?: string,
 *   response?: mixed,
 * } $cfg
 */
function sqrd_varnish_setup(array $cfg = []): ArrayObject
{
    $opts = [
        'sqrd_varnish_enabled'    => $cfg['enabled'] ?? true,
        'sqrd_varnish_hosts'      => $cfg['hosts']   ?? ['127.0.0.1:6081'],
        'sqrd_varnish_tag_prefix' => $cfg['prefix']  ?? 'example-com',
    ];

    Monkey\Functions\when('get_option')->alias(
        fn(string $key, mixed $default = null): mixed => $opts[$key] ?? $default
    );
    Monkey\Functions\when('home_url')->justReturn($cfg['home'] ?? 'https://example.com/');
    Monkey\Functions\when('is_wp_error')->alias(fn($v): bool => $v instanceof WP_Error);

    $captured = new ArrayObject();
    $response = $cfg['response'] ?? ['response' => ['code' => 200]];
    Monkey\Functions\when('wp_remote_request')->alias(
        function (string $url, array $args = []) use ($captured, $response): mixed {
            $captured->append(['url' => $url, 'args' => $args]);
            return $response;
        }
    );

    return $captured;
}

// ── purge_url ─────────────────────────────────────────────────────────────────

describe('Varnish::purge_url', function (): void {
    it('sends PURGE with correct method, path, Host, and tag prefix headers', function (): void {
        $calls = sqrd_varnish_setup();

        Varnish::purge_url('https://example.com/about/');

        expect($calls)->toHaveCount(1);
        expect($calls[0]['url'])->toBe('http://127.0.0.1:6081/about/');
        expect($calls[0]['args']['method'])->toBe('PURGE');
        expect($calls[0]['args']['headers']['Host'])->toBe('example.com');
        expect($calls[0]['args']['headers']['X-Cache-Tag-Prefix'])->toBe('example-com');
    });

    it('fans out to every configured host', function (): void {
        $calls = sqrd_varnish_setup([
            'hosts' => ['127.0.0.1:6081', 'http://varnish-2:6081'],
        ]);

        Varnish::purge_url('https://example.com/blog/post/');

        expect($calls)->toHaveCount(2);
        expect($calls[0]['url'])->toBe('http://127.0.0.1:6081/blog/post/');
        expect($calls[1]['url'])->toBe('http://varnish-2:6081/blog/post/');
    });

    it('prefixes http:// when scheme is missing', function (): void {
        $calls = sqrd_varnish_setup(['hosts' => ['varnish.internal:6081']]);

        Varnish::purge_url('https://example.com/');

        expect($calls[0]['url'])->toBe('http://varnish.internal:6081/');
    });

    it('preserves an https:// scheme when explicitly set', function (): void {
        $calls = sqrd_varnish_setup(['hosts' => ['https://varnish.internal:443']]);

        Varnish::purge_url('https://example.com/');

        expect($calls[0]['url'])->toBe('https://varnish.internal:443/');
    });

    it('omits X-Cache-Tag-Prefix header when prefix is empty', function (): void {
        $calls = sqrd_varnish_setup(['prefix' => '']);

        Varnish::purge_url('https://example.com/');

        expect($calls[0]['args']['headers'])->not->toHaveKey('X-Cache-Tag-Prefix');
        expect($calls[0]['args']['headers']['Host'])->toBe('example.com');
    });

    it('does nothing when Varnish is disabled', function (): void {
        $calls = sqrd_varnish_setup(['enabled' => false]);

        Varnish::purge_url('https://example.com/');

        expect($calls)->toBeEmpty();
    });

    it('does nothing when no hosts are configured', function (): void {
        $calls = sqrd_varnish_setup(['hosts' => []]);

        Varnish::purge_url('https://example.com/');

        expect($calls)->toBeEmpty();
    });

    it('skips entries that are empty or whitespace-only', function (): void {
        $calls = sqrd_varnish_setup(['hosts' => ['', '   ', '127.0.0.1:6081']]);

        Varnish::purge_url('https://example.com/');

        expect($calls)->toHaveCount(1);
        expect($calls[0]['url'])->toBe('http://127.0.0.1:6081/');
    });

    it('does not throw when wp_remote_request returns a WP_Error', function (): void {
        sqrd_varnish_setup(['response' => new WP_Error('connect failed')]);

        expect(fn() => Varnish::purge_url('https://example.com/'))
            ->not->toThrow(Throwable::class);
    });
});

// ── purge_urls ────────────────────────────────────────────────────────────────

describe('Varnish::purge_urls', function (): void {
    it('purges every URL in the list', function (): void {
        $calls = sqrd_varnish_setup();

        Varnish::purge_urls([
            'https://example.com/a/',
            'https://example.com/b/',
            'https://example.com/c/',
        ]);

        expect($calls)->toHaveCount(3);
        expect(array_column(iterator_to_array($calls), 'url'))->toBe([
            'http://127.0.0.1:6081/a/',
            'http://127.0.0.1:6081/b/',
            'http://127.0.0.1:6081/c/',
        ]);
    });

    it('does nothing when disabled', function (): void {
        $calls = sqrd_varnish_setup(['enabled' => false]);

        Varnish::purge_urls(['https://example.com/a/']);

        expect($calls)->toBeEmpty();
    });
});

// ── purge_all ─────────────────────────────────────────────────────────────────

describe('Varnish::purge_all', function (): void {
    it('sends BAN / with site Host and tag prefix to every host', function (): void {
        $calls = sqrd_varnish_setup([
            'hosts' => ['127.0.0.1:6081', 'varnish-2:6081'],
            'home'  => 'https://example.com/',
        ]);

        Varnish::purge_all();

        expect($calls)->toHaveCount(2);
        foreach ($calls as $c) {
            expect($c['args']['method'])->toBe('BAN');
            expect($c['args']['headers']['Host'])->toBe('example.com');
            expect($c['args']['headers']['X-Cache-Tag-Prefix'])->toBe('example-com');
            expect(str_ends_with($c['url'], '/'))->toBeTrue();
        }
    });

    it('does nothing when Varnish is disabled', function (): void {
        $calls = sqrd_varnish_setup(['enabled' => false]);

        Varnish::purge_all();

        expect($calls)->toBeEmpty();
    });

    it('does not throw on transport failure', function (): void {
        sqrd_varnish_setup(['response' => new WP_Error('unreachable')]);

        expect(fn() => Varnish::purge_all())->not->toThrow(Throwable::class);
    });
});
