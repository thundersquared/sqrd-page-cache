<?php

declare(strict_types=1);

use sqrd\Cache\Negotiation;

// ── ext_for_accept ────────────────────────────────────────────────────────────

describe('Negotiation::ext_for_accept', function (): void {
    it('returns html for empty Accept', function (): void {
        expect(Negotiation::ext_for_accept(''))->toBe('html');
    });

    it('returns html for text/html', function (): void {
        expect(Negotiation::ext_for_accept('text/html'))->toBe('html');
    });

    it('returns html for application/json', function (): void {
        expect(Negotiation::ext_for_accept('application/json'))->toBe('html');
    });

    it('returns html for wildcard */*', function (): void {
        expect(Negotiation::ext_for_accept('*/*'))->toBe('html');
    });

    it('returns md for text/markdown', function (): void {
        expect(Negotiation::ext_for_accept('text/markdown'))->toBe('md');
    });

    it('is case-insensitive for text/markdown', function (): void {
        expect(Negotiation::ext_for_accept('TEXT/MARKDOWN'))->toBe('md');
        expect(Negotiation::ext_for_accept('Text/Markdown'))->toBe('md');
    });

    it('returns md when markdown appears alongside html with q-values', function (): void {
        expect(Negotiation::ext_for_accept('text/markdown, text/html;q=0.9'))->toBe('md');
    });

    it('returns md when markdown appears last in list', function (): void {
        expect(Negotiation::ext_for_accept('text/html, text/markdown'))->toBe('md');
    });

    it('mirrors the nginx `if ($http_accept ~* "text/markdown")` rule exactly', function (): void {
        // substring match — does NOT parse q-values; text/markdown present anywhere → md
        expect(Negotiation::ext_for_accept('text/html;q=1.0, text/markdown;q=0.1'))->toBe('md');
    });
});

// ── ext_for_response_content_type ─────────────────────────────────────────────

describe('Negotiation::ext_for_response_content_type', function (): void {
    it('returns html for text/html', function (): void {
        expect(Negotiation::ext_for_response_content_type('text/html'))->toBe('html');
    });

    it('returns html for text/html with charset', function (): void {
        expect(Negotiation::ext_for_response_content_type('text/html; charset=utf-8'))->toBe('html');
    });

    it('returns html for application/xhtml+xml', function (): void {
        expect(Negotiation::ext_for_response_content_type('application/xhtml+xml'))->toBe('html');
    });

    it('returns md for text/markdown', function (): void {
        expect(Negotiation::ext_for_response_content_type('text/markdown'))->toBe('md');
    });

    it('returns md for text/markdown with charset', function (): void {
        expect(Negotiation::ext_for_response_content_type('text/markdown; charset=utf-8'))->toBe('md');
    });

    it('returns md for text/x-markdown', function (): void {
        expect(Negotiation::ext_for_response_content_type('text/x-markdown'))->toBe('md');
    });

    it('returns null for application/json', function (): void {
        expect(Negotiation::ext_for_response_content_type('application/json'))->toBeNull();
    });

    it('returns null for text/xml', function (): void {
        expect(Negotiation::ext_for_response_content_type('text/xml'))->toBeNull();
    });

    it('returns null for application/rss+xml', function (): void {
        expect(Negotiation::ext_for_response_content_type('application/rss+xml'))->toBeNull();
    });

    it('is case-insensitive', function (): void {
        expect(Negotiation::ext_for_response_content_type('TEXT/HTML'))->toBe('html');
        expect(Negotiation::ext_for_response_content_type('TEXT/MARKDOWN'))->toBe('md');
    });
});

// ── image_ext_for_accept ──────────────────────────────────────────────────────

describe('Negotiation::image_ext_for_accept', function (): void {
    it('returns avif when the client accepts image/avif', function (): void {
        expect(Negotiation::image_ext_for_accept('image/avif'))->toBe('avif');
    });

    it('returns webp when the client accepts image/webp only', function (): void {
        expect(Negotiation::image_ext_for_accept('image/webp'))->toBe('webp');
    });

    it('prefers avif over webp when both are accepted', function (): void {
        expect(Negotiation::image_ext_for_accept('image/avif, image/webp'))->toBe('avif');
        expect(Negotiation::image_ext_for_accept('image/webp, image/avif'))->toBe('avif');
    });

    it('is case-insensitive', function (): void {
        expect(Negotiation::image_ext_for_accept('IMAGE/AVIF'))->toBe('avif');
        expect(Negotiation::image_ext_for_accept('Image/WebP'))->toBe('webp');
    });

    it('detects avif inside a full browser Accept string', function (): void {
        $chrome = 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8';
        expect(Negotiation::image_ext_for_accept($chrome))->toBe('avif');
    });

    it('detects webp when avif is absent from a browser Accept', function (): void {
        $firefox = 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8';
        expect(Negotiation::image_ext_for_accept($firefox))->toBe('webp');
    });

    it('returns null when neither next-gen format is accepted', function (): void {
        expect(Negotiation::image_ext_for_accept('text/html,application/xhtml+xml'))->toBeNull();
    });

    it('returns null for an empty Accept header', function (): void {
        expect(Negotiation::image_ext_for_accept(''))->toBeNull();
    });

    it('mirrors the nginx $sqrd_img_ext rule: webp set first, avif overwrites', function (): void {
        // nginx sets .webp then overwrites with .avif — avif wins when both present.
        // PHP checks avif first then webp — same outcome, fewer comparisons.
        expect(Negotiation::image_ext_for_accept('image/webp, image/avif'))->toBe('avif');
        expect(Negotiation::image_ext_for_accept('image/webp'))->toBe('webp');
        expect(Negotiation::image_ext_for_accept(''))->toBeNull();
    });
});
