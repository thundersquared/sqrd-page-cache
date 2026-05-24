<?php

declare(strict_types=1);

use sqrd\Cache\Output;

// ── is_tracking_only ──────────────────────────────────────────────────────────

describe('Output::is_tracking_only', function (): void {
    it('returns true for an empty query string', function (): void {
        expect(Output::is_tracking_only([]))->toBeTrue();
    });

    it('accepts a single Google Analytics _ga param', function (): void {
        expect(Output::is_tracking_only(['_ga' => 'GA1.2.123']))->toBeTrue();
    });

    it('accepts the _ga_<PROPERTY> prefixed form', function (): void {
        expect(Output::is_tracking_only(['_ga_XM7C2CX85R' => 's1779562484']))->toBeTrue();
    });

    it('accepts the cross-domain _gl param', function (): void {
        $gl = '1*1eg0zb2*_up*MQ..*_ga*MTczODI5MjgzNi4xNzc5NTYyNDg0';
        expect(Output::is_tracking_only(['_gl' => $gl]))->toBeTrue();
    });

    it('accepts every utm_* parameter via the prefix pattern', function (): void {
        $args = [
            'utm_source'   => 'newsletter',
            'utm_medium'   => 'email',
            'utm_campaign' => 'launch',
            'utm_term'     => 'foo',
            'utm_content'  => 'bar',
        ];
        expect(Output::is_tracking_only($args))->toBeTrue();
    });

    it('accepts a mix of trackers from different vendors', function (): void {
        $args = [
            '_ga'        => 'GA1.2.123',
            '_gl'        => '1*1eg0zb2*_up*MQ..',
            'utm_source' => 'twitter',
            'fbclid'     => 'IwAR0xyz',
            'gclid'      => 'EAIaIQob',
            'msclkid'    => 'abc123',
            'mc_cid'     => 'def456',
        ];
        expect(Output::is_tracking_only($args))->toBeTrue();
    });

    it('returns false when at least one param is not a known tracker', function (): void {
        $args = [
            '_ga'       => 'GA1.2.123',
            'page'      => '2',  // real param — should bust cache
        ];
        expect(Output::is_tracking_only($args))->toBeFalse();
    });

    it('returns false for an entirely unknown param', function (): void {
        expect(Output::is_tracking_only(['filter' => 'red']))->toBeFalse();
    });

    it('does not match _gas — prefix _ga_ requires the underscore', function (): void {
        // _gas is not in the tracker list; only _ga (exact) and _ga_* (prefix) match.
        expect(Output::is_tracking_only(['_gas' => 'x']))->toBeFalse();
    });
});

// ── tracking_params filter ────────────────────────────────────────────────────

describe('Output::tracking_params', function (): void {
    it('returns the built-in defaults out of the box', function (): void {
        $patterns = Output::tracking_params();
        expect($patterns)->toContain('_ga');
        expect($patterns)->toContain('_ga_*');
        expect($patterns)->toContain('_gl');
        expect($patterns)->toContain('utm_*');
        expect($patterns)->toContain('fbclid');
        expect($patterns)->toContain('gclid');
    });

    it('honours additions via the sqrd_page_cache/tracking_params filter', function (): void {
        Brain\Monkey\Functions\when('apply_filters')->alias(
            fn(string $tag, mixed $value): mixed =>
                $tag === 'sqrd_page_cache/tracking_params'
                    ? [...$value, '_clck', 'ttclid']
                    : $value
        );

        $patterns = Output::tracking_params();
        expect($patterns)->toContain('_clck');
        expect($patterns)->toContain('ttclid');

        // And is_tracking_only sees the additions.
        expect(Output::is_tracking_only(['_clck' => 'x', 'ttclid' => 'y']))->toBeTrue();
    });

    it('refuses bare-* wildcard pattern that would match every key', function (): void {
        // A filter that returns ['*'] used to collapse every query string onto
        // the same cache file via str_starts_with($key, '') === true.
        Brain\Monkey\Functions\when('apply_filters')->alias(
            fn(string $tag, mixed $value): mixed =>
                $tag === 'sqrd_page_cache/tracking_params' ? ['*'] : $value
        );

        expect(Output::is_tracking_only(['inject' => '<script>']))->toBeFalse();
        expect(Output::is_tracking_only(['anything' => '1']))->toBeFalse();
    });

    it('ignores empty patterns in the filter result', function (): void {
        Brain\Monkey\Functions\when('apply_filters')->alias(
            fn(string $tag, mixed $value): mixed =>
                $tag === 'sqrd_page_cache/tracking_params' ? ['', '_ga'] : $value
        );

        // Empty pattern would have matched everything (=== '' check) — guard
        // rejects it. _ga still works.
        expect(Output::is_tracking_only(['anything' => '1']))->toBeFalse();
        expect(Output::is_tracking_only(['_ga' => 'x']))->toBeTrue();
    });
});

// ── bypass_cookie_prefixes ────────────────────────────────────────────────────

describe('Output::bypass_cookie_prefixes', function (): void {
    it('includes WordPress core auth/comment/postpass prefixes by default', function (): void {
        $prefixes = Output::bypass_cookie_prefixes();
        expect($prefixes)->toContain('wordpress_logged_in_', 'comment_author_', 'wp-postpass_');
    });

    it('includes WooCommerce + EDD session prefixes — must mirror the nginx alternation', function (): void {
        // Regression guard: nginx's cookie bypass regex in
        // nginx/sqrd-page-cache.conf must list these same prefixes, or a
        // shopper with a cart cookie hits the disk cache before PHP runs.
        $prefixes = Output::bypass_cookie_prefixes();
        expect($prefixes)->toContain(
            'woocommerce_items_in_cart',
            'woocommerce_cart_hash',
            'wp_woocommerce_session_',
            'edd_items_in_cart',
            'edd_cart_messages',
        );
    });

    it('filters out empty strings supplied by sqrd_page_cache/bypass_cookie_prefixes', function (): void {
        Brain\Monkey\Functions\when('apply_filters')->alias(
            fn(string $name, mixed $value): mixed =>
                $name === 'sqrd_page_cache/bypass_cookie_prefixes'
                    ? ['wp_user_', '', 'cart_']
                    : $value
        );

        expect(Output::bypass_cookie_prefixes())->toBe(['wp_user_', 'cart_']);
    });
});
