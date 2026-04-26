===  SQRD Page Cache ===
Contributors:      sqrd
Tags:              cache, page cache, nginx, markdown, performance
Requires at least: 6.4
Tested up to:      6.8
Requires PHP:      8.3
Stable tag:        1.0.0
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
2. Activate the plugin through the *Plugins* menu in WordPress.
3. Visit *Settings → SQRD Page Cache* to review the defaults.

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

    add_filter('sqrd_cache_significant_headers', function (array $list): array {
        $list[] = 'x-my-custom-header';
        return $list;
    });

== Frequently Asked Questions ==

= Does this work with WooCommerce? =

Yes.  Add `/cart`, `/checkout`, and `/my-account` to the *Exclude paths*
setting.  Also extend the nginx cookie bypass regex to include
`woocommerce_items_in_cart` so carts with items are never served from cache.

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

== Changelog ==

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
Initial release.  No upgrade path required.
