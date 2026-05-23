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

    it('honours additions via the sqrd_cache_tracking_params filter', function (): void {
        Brain\Monkey\Functions\when('apply_filters')->alias(
            fn(string $tag, mixed $value): mixed =>
                $tag === 'sqrd_cache_tracking_params'
                    ? [...$value, '_clck', 'ttclid']
                    : $value
        );

        $patterns = Output::tracking_params();
        expect($patterns)->toContain('_clck');
        expect($patterns)->toContain('ttclid');

        // And is_tracking_only sees the additions.
        expect(Output::is_tracking_only(['_clck' => 'x', 'ttclid' => 'y']))->toBeTrue();
    });
});
