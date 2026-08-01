===  SQRD Page Cache ===
Contributors:      sqrd
Tags:              cache, page cache, nginx, markdown, performance
Requires at least: 6.4
Tested up to:      6.8
Requires PHP:      8.3
Stable tag:        0.2.0
License:           GPL-2.0-or-later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Accept-aware disk page cache served directly by nginx.  Caches text/html and
text/markdown variants independently; nginx picks the right one per-request.

== Description ==

SQRD Page Cache stores WordPress responses on disk so nginx can serve repeat
visitors without touching PHP.  It is designed for sites that produce two
response variants depending on the `Accept` header:

* `text/html` — the classic browser response
* `text/markdown` — a plain-text variant for LLM agents and other automated
  clients (produced by a separate theme/plugin)

Each variant is cached as an independent file (`index.html` / `index.md`).
nginx selects the correct file based on the incoming `Accept` header before
PHP is invoked, so cached pages are served at static-file speed.

= Features =

* Disk-backed file cache with atomic writes (no partial reads by nginx)
* Pre-compressed `.gz` siblings for nginx `gzip_static on`
* Headers sidecar (`index.html.headers` / `index.md.headers`) in plaintext
  HTTP-style format, ready for future Lua/njs header-injection layers
* Smart invalidation: per-URL purge on `save_post`, full flush on structural
  changes (theme switch, permalink change, etc.)
* Admin settings page with live nginx config preview and one-click purge
* Admin bar "Purge Cache" link for logged-in admins
* All nginx changes scoped to the vhost — zero `http { }` pollution
* No external dependencies; vendor/ is committed

= Requirements =

* PHP 8.3 or higher
* nginx (any modern version with `gzip_static` support)
* WordPress 6.4 or higher
* A theme or plugin that renders `text/markdown` when the `Accept` header
  requests it (SQRD Page Cache only *caches* — it does not generate markdown)

= Important: Accept-header parity =

SQRD Page Cache uses the same simple rule in both PHP and nginx to decide
which variant applies to a request:

> If the `Accept` header contains `text/markdown` (case-insensitive) → md.
> Otherwise → html.

Your markdown-generating code MUST use the same rule.  If you need RFC 7231
q-value parsing, both `sqrd\Cache\Negotiation::ext_for_accept()` in PHP and
the `if ($http_accept ~* "text/markdown")` block in the nginx include must be
updated in lockstep.

== Installation ==

= Plugin installation =

1. Upload the `sqrd-page-cache` directory to `/wp-content/plugins/`.
2. Inside the plugin directory, run `composer install --no-dev --optimize-autoloader`.
3. Activate the plugin through the *Plugins* menu in WordPress.
4. Visit *Settings → SQRD Page Cache* to review the defaults.

= Nginx configuration =

Add a single include to your WordPress `server { }` block:

    # Inside server { }, ABOVE location / { }
    include /path/to/wp-content/plugins/sqrd-page-cache/nginx/sqrd-page-cache.conf;

Then update your `location / { }` block to add `$sqrd_cache_file` as the
first argument to `try_files`:

    location / {
        try_files $sqrd_cache_file $uri $uri/ /index.php?$args;
    }

Reload nginx:

    nginx -t && systemctl reload nginx

The full annotated example is in `nginx/example-server.conf` and also shown
in the Settings page inside WordPress admin.

= Excluding paths =

Add paths or regexes to the *Exclude paths* setting (one per line).  For
WooCommerce sites you likely want to add:

    /cart
    /checkout
    /my-account

For cookie-specific exclusions (e.g. WooCommerce cart cookie) extend the
nginx bypass regex in `sqrd-page-cache.conf`:

    if ($http_cookie ~* "(wordpress_logged_in_|wp-postpass_|comment_author_|woocommerce_items_in_cart)") {

Both the PHP exclude list and the nginx cookie pattern must be kept in sync
for bypasses that depend on cookies.

== Headers sidecar ==

Alongside each cached body file nginx writes a plaintext header block:

    Content-Type: text/markdown; charset=utf-8\r\n
    Content-Length: 1247\r\n
    Cache-Control: public, max-age=3600\r\n
    Link: <https://example.com/wp-json/>; rel="https://api.w.org/"\r\n
    X-Robots-Tag: index, follow\r\n

These files are not served by nginx today (marked `internal`).  They exist
for forward-compatibility: a future Lua/njs layer can read them and inject the
stored headers into the cached response without any PHP involvement.

The default allow-list of captured headers can be extended at runtime:

    add_filter('sqrd_page_cache/significant_headers', function (array $list): array {
        $list[] = 'x-my-custom-header';
        return $list;
    });

== Frequently Asked Questions ==

= Does this work with WooCommerce? =

Yes — automatically, as of 0.2.0.  When WooCommerce is active the plugin
loads `src/WooCommerce.php`, which (1) hooks product/stock/order-stock
events to purge affected product, shop, and category pages, (2) flushes
the entire cache on `woocommerce_settings_saved`, and (3) auto-adds the
configured cart, checkout, and my-account pages to the exclude list via
`wc_get_page_id()` — so renamed or localised pages (e.g. `/basket`,
`/panier`) are covered without extra configuration.

The nginx include already bypasses WooCommerce/EDD session cookies
(`woocommerce_items_in_cart`, `woocommerce_cart_hash`,
`wp_woocommerce_session_`, `edd_items_in_cart`, `edd_cart_messages`)
in lockstep with PHP, so shoppers with active carts are never served the
anonymous cached page from disk.

To opt out:  `add_filter('sqrd_page_cache/woocommerce_enabled', '__return_false');`

= Does this replace other full-page cache plugins? =

Yes.  Do not run SQRD Page Cache alongside W3 Total Cache, WP Super Cache,
WP Fastest Cache, or similar plugins — they will conflict.

= What happens if nginx is not configured? =

The plugin still writes cache files to disk; they just won't be served by
nginx.  All requests will continue to go through PHP normally.  This means
the plugin is safe to activate before completing the nginx configuration.

= Why does the markdown variant not get cached? =

The plugin caches only when the request's `Accept` header matches the
response's `Content-Type`.  If your site returns `text/html` for an
`Accept: text/markdown` request (no markdown renderer installed), the parity
guard skips the write to avoid poisoning nginx's lookup.

= Which image conversion plugin should I use for AVIF/WebP? =

SQRD Page Cache only *serves* AVIF/WebP variants — it does not generate them.
Pair it with a conversion plugin. For local, free, unlimited conversion of
*both* formats, use **CompressX** (single plugin, both AVIF + WebP via PHP
Imagick) or the combination of **WebP Express** (WebP) + **AVIF Express**
(AVIF). See the "Next-gen image serving" section in the project README for a
full comparison and setup steps.

Important: enable generation in the conversion plugin but **disable its own
delivery/rewrite feature** — the nginx include shipped with this plugin handles
serving based on the `Accept` header. Do not paste the conversion plugin's
nginx rewrite snippet.

== Changelog ==

= 0.3.0 =
* **Feature — next-gen image serving (AVIF/WebP).** The nginx include now negotiates AVIF/WebP variants of uploaded JPEG/PNG images based on the client's `Accept` header, serving the best supported format at static-file speed before PHP is invoked. Generation of the variants is delegated to a third-party conversion plugin; this plugin only serves them. The upload-image `location` walks every common sibling-file convention via `try_files` (appended same-dir, replaced same-dir, and Converter for Media's separate `uploads-webpc/` directory), so it works with CompressX, WebP Express + AVIF Express (in combination), Imagify, ShortPixel, EWWW, and Converter for Media without custom rewrite rules. AVIF is preferred over WebP when both are accepted; legacy browsers get the original. `Vary: Accept` and a one-year immutable `Cache-Control` are set on every image response.
* **Feature — PHP parity anchor.** `sqrd\Cache\Negotiation::image_ext_for_accept()` mirrors the nginx `$sqrd_img_ext` resolution (avif → webp → null). Not invoked on the image request path today (images bypass PHP) — it exists as a testable parity mirror and for future PHP-side delivery layers.

= 0.2.0 =
* **Feature — WooCommerce-aware cache invalidation.** New `sqrd\Cache\WooCommerce` integration auto-activates when WooCommerce is loaded. Purges product, shop, and category pages on `woocommerce_update_product` / `woocommerce_new_product` / `woocommerce_delete_product` / `woocommerce_trash_product` (covering direct CLI/API updates that bypass `save_post`), on stock changes (`woocommerce_product_set_stock`, `woocommerce_variation_set_stock`, plus the `_stock_status` variants), and per line item on `woocommerce_reduce_order_stock` when a checkout completes. Full-cache flush on `woocommerce_settings_saved` (currency, tax, and display rules affect every cached page).
* **Feature — auto-exclude WooCommerce pages by permalink, not slug.** Cart, checkout, and my-account paths are now appended to the exclude list via `wc_get_page_id()` lookups, so sites that renamed those pages (e.g. `/basket`, `/panier`, `/checkout-pro`) are covered without touching the Admin defaults. The legacy `/cart`, `/checkout`, `/my-account` defaults still ship as belt-and-suspenders for non-WC installs.
* **Fix — nginx cookie bypass lagged behind PHP.** `nginx/sqrd-page-cache.conf` only listed `wordpress_logged_in_|wp-postpass_|comment_author_` in its bypass regex, while `Output::has_bypass_cookie()` had been recognising five additional WooCommerce/EDD session prefixes since 0.1.6. A shopper with `woocommerce_items_in_cart` would hit nginx first and get the anonymous cached page — PHP never ran. The nginx alternation now mirrors PHP exactly, and a regression test (`OutputTest`) pins the prefix list so they cannot drift apart silently. New CLAUDE.md gotcha documents the sync requirement next to the existing tracking-params one.
* **API — `Output::BYPASS_COOKIE_PREFIXES` constant and `Output::bypass_cookie_prefixes()` accessor.** The previously inlined list now lives on the class so the nginx mirror has a canonical reference and tests can pin it.
* **API — `sqrd_page_cache/woocommerce_enabled` filter.** Returns `true` by default when WooCommerce is loaded; filter to `false` to disable the integration without deactivating WC.

= 0.1.7 =
* **Fix — wp-admin assets (and every other static file) loaded with `Content-Type: application/octet-stream`.** The plugin's nginx include declared `types { text/html ...; text/markdown md; }` at server scope, which REPLACES the inherited http-level `mime.types` map entirely. Browsers then refuse the response under strict MIME-type checking (`X-Content-Type-Options: nosniff` is default in modern WP), breaking wp-admin styling/scripts, the block editor, theme assets, and so on. The mapping is now scoped to the internal cache HIT location only, so the rest of the vhost keeps using the system mime.types unmodified. After upgrading, no `nginx -t && systemctl reload nginx` change is needed beyond pulling the new file (the include path is unchanged).

= 0.1.6 =
* **Security — Host header path traversal.** `Paths::file_for()` previously kept dot characters in the Host header sanitizer, so a request with `Host: ..` produced a cache path of `cache_root/../index.html` — an arbitrary-file overwrite scoped to whatever PHP could write. The sanitizer now rejects empty, dot-only, leading-dot, and double-dot host values, and `Paths::file_for()` enforces a `realpath()`-based bound check so the resolved path must live inside the cache root.
* **Security — URI traversal silently mapped to home page.** `Paths::normalize_uri()` used to return `/` when the URI contained `..`, which meant any 200 response for a traversal-style URL (custom rewrites, certain themes) would overwrite the home-page cache. Normalization now throws `\InvalidArgumentException` for raw `..` and percent-encoded `%2e%2e` traversal, and `Output::finish()` aborts the cache write when the request is unsafe.
* **Security — `flush_all` followed symlinks.** `RecursiveDirectoryIterator` descends through symlinked directories by default, so a symlink planted in the cache root could turn "Purge all" into an arbitrary-file-delete primitive. `Store::rmdir_recursive()` now treats symlinks as leaf nodes — the link itself is unlinked, but its target is never traversed.
* **Security — tracking-param wildcard footgun.** A `sqrd_page_cache/tracking_params` filter that returned `'*'` (or any empty-stem wildcard) used to collapse every query string onto the same cache key via `str_starts_with($key, '')`. The matcher now rejects empty patterns and bare-`*` wildcards.
* **Security — atomic write temp-file collision.** `Store::atomic_write()` derived its temp suffix from `getmypid()` alone, which collided when a single PHP-FPM worker handled overlapping writes. The suffix is now `bin2hex(random_bytes(8))`.
* **Hardening — bypass cookies for non-WP commerce.** `Output::has_bypass_cookie()` now recognises WooCommerce (`woocommerce_items_in_cart`, `woocommerce_cart_hash`, `wp_woocommerce_session_`) and Easy Digital Downloads (`edd_items_in_cart`, `edd_cart_messages`) session cookies, and exposes the full prefix list via the new `sqrd_page_cache/bypass_cookie_prefixes` filter. Previously, anonymous shoppers' cart-aware HTML could be cached and served to other visitors.

= 0.1.5 =
* **Breaking — filter renames.** Five legacy underscore-prefixed filters renamed to the modern slash-namespaced convention (matching `sqrd_page_cache/respect_donotcachepage` and `sqrd_page_cache/minify_html_options`):
  * `sqrd_cache_significant_headers` → `sqrd_page_cache/significant_headers`
  * `sqrd_cache_root` → `sqrd_page_cache/root`
  * `sqrd_cache_tracking_params` → `sqrd_page_cache/tracking_params`
  * `sqrd_cache_github_owner` → `sqrd_page_cache/github_owner`
  * `sqrd_cache_github_repo` → `sqrd_page_cache/github_repo`
* Anyone hooking the legacy names must rename their callback strings. The old hook names are no longer fired.
* WordPress option names (`sqrd_cache_enabled`, `sqrd_cache_ttl_hours`, etc.) and admin-post action names are intentionally unchanged — renaming options would break existing installs.

= 0.1.4 =
* Minifier no longer applies WHATWG's optional end-tag omission rules — closing `</html>`, `</body>`, `</p>`, `</li>`, `</td>`, etc. are preserved. Browser parsing was always fine without them, but the missing tags surprised devtools/snapshot/regex tooling. Attribute quotes are likewise preserved.
* Filter rename: `sqrd_cache_minify_html_options` → `sqrd_page_cache/minify_html_options` (slash-namespaced to match the rest of the modern filter surface like `sqrd_page_cache/respect_donotcachepage`). Anyone hooking the 0.1.3-era name must rename their callback.

= 0.1.3 =
* HTML minification on cache write via the [akankov/html-min](https://packagist.org/packages/akankov/html-min) library. Strips redundant whitespace, line breaks, and non-conditional HTML comments before the file is persisted and before the response goes back to the client — typical WP pages shrink 15-25% before gzip/brotli compression. On by default; toggle under **Settings → SQRD Page Cache**.
* Markdown variants are never minified (would corrupt list/paragraph structure). Inline `<script>` / `<style>` / `<pre>` / `<textarea>` and IE conditional comments are protected automatically by the library. Per-region opt-out: wrap any block in `<nocompress>…</nocompress>`.
* New filter `sqrd_page_cache/minify_html_options` receives the configured `HtmlMin` instance so sites can flip individual `do*` toggles (or swap in an alternative minifier entirely) without forking.

= 0.1.2 =
* Analytics tracking parameters (Google Analytics `_ga` / `_ga_*` / `_gl`, UTM `utm_*`, Facebook `fbclid`, Google Ads `gclid`, Microsoft `msclkid`, Mailchimp `mc_cid` / `mc_eid`, Yandex `yclid`, DoubleClick `dclid`) no longer bust the cache — URLs that carry only trackers resolve to the same cache file as their bare-path counterpart. Extend the list with the new `sqrd_cache_tracking_params` filter.
* nginx include emits `X-Cached-By: sqrd-page-cache` on the cache location block as a diagnostic marker for disk-cache HITs.

= 0.1.1 =
* New filter `sqrd_page_cache/respect_donotcachepage` (default `false`): sqrd ignores the generic `DONOTCACHEPAGE` constant by default — its parity guard already prevents cross-variant poisoning, so the bypass is overly conservative. Filter to `true` to restore the legacy behavior.
* Plugin now drives `post_content_to_markdown/cache_md_urls` from the same filter, so the Roots markdown plugin stops defining `DONOTCACHEPAGE` on `.md` URLs out of the box and Accept-keyed markdown is cached without extra setup.

= 0.1.0 =
* Varnish purge integration: per-URL `PURGE` and full-flush `BAN /` to every configured Varnish host, scoped by `X-Cache-Tag-Prefix` for multi-tenant setups.
* New invalidation hooks: plugin activate/deactivate, `upgrader_process_complete` (any plugin/theme/core upgrade), plus Varnish flush on plugin activate/deactivate.
* Brotli pre-compression: `.br` siblings written alongside `.gz` when ext-brotli is loaded; nginx config gains `brotli_static` block.
* Admin settings UI for Varnish hosts, enable toggle, and cache tag prefix.

= 0.0.1 =
* Initial release.

== Upgrade Notice ==

= 0.3.0 =
Next-gen image serving: pull the updated `nginx/sqrd-page-cache.conf` and run `nginx -t && systemctl reload nginx`. The include now negotiates AVIF/WebP variants of uploaded JPEG/PNG images via the client's `Accept` header. Pair it with a conversion plugin (CompressX, or WebP Express + AVIF Express) — enable generation in that plugin but disable its own delivery/rewrite so the nginx include does the serving. No PHP settings change required.

= 0.2.0 =
WooCommerce sites: the plugin now auto-purges product / shop / category pages on product, stock, and order-stock events, full-flushes on settings save, and auto-excludes the cart / checkout / my-account pages by permalink (covers renamed or localised pages). The nginx include's cookie bypass regex has been extended to mirror the PHP-side list — pull the new file and `nginx -t && systemctl reload nginx` so carted shoppers stop being served the anonymous cached page from disk. No setting changes required.

= 0.1.7 =
Hotfix: the nginx include was overriding the vhost's MIME map, serving every css/js/woff/etc. as `application/octet-stream` and breaking wp-admin under strict MIME checking. Pull the new include and reload nginx (`nginx -t && systemctl reload nginx`).

= 0.1.6 =
Security release — fixes Host-header path traversal, URI-traversal home-page poisoning, symlink-following on `Purge all`, and a tracking-param wildcard footgun. Adds WooCommerce/EDD session cookies to the cache bypass list. Strongly recommended for any public-facing installation. After upgrading, purge the cache once so any stray files written outside the cache root by the old behavior are visible/clean.

= 0.1.5 =
Breaking: five filters renamed to the `sqrd_page_cache/` slash-namespace. If you hook `sqrd_cache_significant_headers`, `sqrd_cache_root`, `sqrd_cache_tracking_params`, `sqrd_cache_github_owner`, or `sqrd_cache_github_repo` anywhere, rename the callback string to the slash-prefixed form before upgrading. Option names are unchanged.

= 0.1.4 =
Minifier now keeps explicit closing tags and attribute quotes. Purge the cache once after upgrading so existing files get rewritten with the full markup.

= 0.1.3 =
HTML minification is on by default. Purge the cache once after upgrading so existing un-minified files get rewritten on next visit. Opt out in **Settings → SQRD Page Cache** if it interferes with anything.

= 0.1.2 =
Tracker query strings now hit cache instead of bypassing. To pick up the X-Cached-By header (and the relaxed bypass on the nginx HIT path), reload nginx after upgrading: `nginx -t && systemctl reload nginx`.

= 0.1.1 =
Now ignores `DONOTCACHEPAGE` by default and bridges to roots/post-content-to-markdown so Accept-keyed and `.md`-URL markdown both cache out of the box. To restore the previous strict behavior, add `add_filter('sqrd_page_cache/respect_donotcachepage', '__return_true');` in a mu-plugin.

= 0.1.0 =
Adds upstream Varnish purge and Brotli pre-compression. Configure Varnish hosts under Settings → SQRD Page Cache before enabling. Requires ngx_brotli compiled into nginx to serve `.br` siblings.

= 0.0.1 =
Initial release.  No upgrade path required.
