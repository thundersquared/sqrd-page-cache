<?php

declare(strict_types=1);

namespace sqrd\Cache;

use Akankov\HtmlMin\HtmlMin;

class Minifier
{
    private function __construct() {}

    /**
     * Minify an HTML document. Safe defaults — inline <script>/<style>/<pre>/
     * <textarea> and conditional comments are protected by the library
     * regardless of toggles. Per-region opt-out via <nocompress>…</nocompress>.
     *
     * Short-circuits to the original body when the sqrd_cache_minify_html
     * option is off, or when minify() throws (logged, never fatal — we'd
     * rather ship a valid un-minified page than a broken minified one).
     */
    public static function html(string $body): string
    {
        if (!get_option('sqrd_cache_minify_html', true)) {
            return $body;
        }

        try {
            $minifier = self::configured_instance();
            /** @var string $minified */
            $minified = $minifier->minify($body);
            return $minified !== '' ? $minified : $body;
        } catch (\Throwable $e) {
            error_log('sqrd-page-cache: html minify failed — ' . $e->getMessage());
            return $body;
        }
    }

    // -------------------------------------------------------------------------

    /**
     * Build the HtmlMin instance with the "standard" preset and expose it via
     * filter so sites can flip individual toggles without forking.
     *
     * The filter may return either a configured HtmlMin or any other object
     * exposing a `minify(string): string` method — useful for testing and for
     * sites that want to swap in an alternative minifier.
     */
    private static function configured_instance(): object
    {
        $minifier = (new HtmlMin())
            ->doOptimizeViaHtmlDomParser(true)
            ->doSumUpWhitespace(true)
            ->doRemoveWhitespaceAroundTags(true)
            ->doRemoveComments(true)
            // WHATWG allows omitting </html>, </body>, </p>, </li>, </td>, </tr>,
            // </option>, etc. — browsers parse fine, but missing closing tags
            // break regex-based HTML tooling, snapshot tests, and confuse anyone
            // viewing source. Keep the explicit end tags.
            ->doRemoveOmittedHtmlTags(false)
            ->doRemoveOmittedQuotes(false);

        $filtered = apply_filters('sqrd_page_cache/minify_html_options', $minifier);
        return is_object($filtered) && method_exists($filtered, 'minify')
            ? $filtered
            : $minifier;
    }
}
