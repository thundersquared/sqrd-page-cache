<?php

declare(strict_types=1);

use Brain\Monkey;
use sqrd\Cache\Minifier;

/**
 * Stub get_option for the minify toggle.
 * Defaults to `enabled = true` matching Minifier's production default.
 */
function sqrd_minifier_setup(bool $enabled = true): void
{
    Monkey\Functions\when('get_option')->alias(
        fn(string $key, mixed $default = null): mixed =>
            $key === 'sqrd_cache_minify_html' ? $enabled : $default
    );
}

describe('Minifier::html', function (): void {
    it('returns the body unchanged when the toggle is off', function (): void {
        sqrd_minifier_setup(enabled: false);

        $html = "<html>\n  <body>\n    <p>Hello   world</p>\n  </body>\n</html>";
        expect(Minifier::html($html))->toBe($html);
    });

    it('collapses inter-tag whitespace and newlines when enabled', function (): void {
        sqrd_minifier_setup();

        $html = "<html><body>\n  <p>One</p>\n  <p>Two</p>\n</body></html>";
        $out  = Minifier::html($html);

        // Smaller and tag-adjacent whitespace gone.
        expect(strlen($out))->toBeLessThan(strlen($html));
        expect($out)->not->toContain("\n  ");
        // Content survives.
        expect($out)->toContain('One');
        expect($out)->toContain('Two');
    });

    it('preserves the inner content of <pre> verbatim', function (): void {
        sqrd_minifier_setup();

        $pre = "line one\n    line two\n        line three";
        $html = "<html><body><pre>{$pre}</pre></body></html>";
        $out  = Minifier::html($html);

        expect($out)->toContain($pre);
    });

    it('preserves the inner content of <textarea> verbatim', function (): void {
        sqrd_minifier_setup();

        $body = "draft\n  with indent\n";
        $html = "<html><body><textarea>{$body}</textarea></body></html>";
        $out  = Minifier::html($html);

        expect($out)->toContain($body);
    });

    it('preserves the inner content of <script>', function (): void {
        sqrd_minifier_setup();

        $js   = "console.log('hello');\nvar x = 1;";
        $html = "<html><body><script>{$js}</script></body></html>";
        $out  = Minifier::html($html);

        expect($out)->toContain("console.log('hello');");
        expect($out)->toContain('var x = 1;');
    });

    it('keeps explicit closing tags (does not apply WHATWG omitted-tag rules)', function (): void {
        sqrd_minifier_setup();

        $html = "<html><body><p>First</p><p>Second</p><ul><li>a</li><li>b</li></ul></body></html>";
        $out  = Minifier::html($html);

        // Browser-legal omissions disabled — every closing tag survives.
        expect($out)->toContain('</html>');
        expect($out)->toContain('</body>');
        expect(substr_count($out, '</p>'))->toBe(2);
        expect(substr_count($out, '</li>'))->toBe(2);
    });

    it('preserves IE conditional comments', function (): void {
        sqrd_minifier_setup();

        $cc   = '<!--[if IE]><p>old browser</p><![endif]-->';
        $html = "<html><body>{$cc}</body></html>";
        $out  = Minifier::html($html);

        expect($out)->toContain('[if IE]');
        expect($out)->toContain('[endif]');
        expect($out)->toContain('old browser');
    });

    it('falls back to the original body on minifier failure', function (): void {
        sqrd_minifier_setup();

        // Force a throw via the options filter — return a fake non-HtmlMin object so
        // Minifier reverts to its own configured instance. Then feed deliberately
        // malformed input. HtmlMin is robust, so we instead inject a filter that
        // returns a stub which throws on minify().
        Monkey\Functions\when('apply_filters')->alias(
            function (string $tag, mixed $value) {
                if ($tag === 'sqrd_cache_minify_html_options') {
                    return new class {
                        public function minify(string $html): string
                        {
                            throw new \RuntimeException('boom');
                        }
                    };
                }
                return $value;
            }
        );

        $original = '<p>untouched</p>';
        expect(Minifier::html($original))->toBe($original);
    });
});
