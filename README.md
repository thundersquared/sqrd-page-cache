# SQRD Page Cache

Accept-aware disk page cache for WordPress, served directly by nginx. Caches `text/html` and `text/markdown` variants independently — nginx picks the right file per-request before PHP is ever invoked.

## How it works

The plugin buffers WordPress responses and writes them to disk. On the next request, nginx serves the cached file at static-file speed — no PHP, no database.

Two variants are cached independently per URL:

| Accept header contains | Cached as | nginx serves |
|------------------------|-----------|--------------|
| `text/markdown` | `index.md` + `.md.gz` | markdown to LLM/API clients |
| anything else | `index.html` + `.html.gz` | HTML to browsers |

A headers sidecar (`index.html.headers` / `index.md.headers`) stores the original response headers in plaintext HTTP format for future Lua/njs injection without PHP.

## Features

- Atomic disk writes — no partial reads by nginx
- Pre-compressed `.gz` siblings (`gzip_static on`) and `.br` siblings when ext-brotli is loaded
- Smart invalidation: per-URL purge on `save_post`, full flush on structural changes (theme switch, permalink change, plugin/core upgrades)
- Varnish purge integration: per-URL `PURGE` and full-flush `BAN /` scoped by `X-Cache-Tag-Prefix` for multi-tenant fleets
- HTML minification on cache write (15–25% size reduction before gzip)
- Analytics tracking parameters (`utm_*`, `fbclid`, `_ga*`, etc.) treated as cache-transparent — decorated URLs share one cache file with their bare-path counterpart
- `X-Cached-By: sqrd-page-cache` diagnostic header on nginx cache HITs
- Admin settings page with live nginx config preview and one-click purge
- Admin bar "Purge Cache" shortcut for logged-in admins
- All nginx config scoped to the vhost — zero `http { }` pollution

## Requirements

- PHP 8.3+
- nginx with `gzip_static` support
- WordPress 6.4+
- A theme or plugin that renders `text/markdown` responses — this plugin only caches, it does not generate markdown

## Installation

**1. Install the plugin**

```bash
# Upload sqrd-page-cache/ to /wp-content/plugins/, then:
composer install --no-dev --optimize-autoloader
```

Activate through **Plugins** in WordPress admin, then visit **Settings → SQRD Page Cache**.

**2. Configure nginx**

Add one `include` to your `server { }` block, above `location / { }`:

```nginx
# Inside server { }, ABOVE location / { }
include /path/to/wp-content/plugins/sqrd-page-cache/nginx/sqrd-page-cache.conf;
```

Update your `location / { }` to try the cache file first:

```nginx
location / {
    try_files $sqrd_cache_file $uri $uri/ /index.php?$args;
}
```

Reload nginx:

```bash
nginx -t && systemctl reload nginx
```

The full annotated example is in [`nginx/example-server.conf`](nginx/example-server.conf) and shown live in the Settings page.

## Accept-header parity

SQRD Page Cache applies the same rule in both PHP and nginx:

> If `Accept` contains `text/markdown` (case-insensitive) → md variant. Otherwise → html.

Your markdown-rendering code must use the same rule. If you ever need RFC 7231 q-value parsing, update `sqrd\Cache\Negotiation::ext_for_accept()` and the `if ($http_accept ~* "text/markdown")` block in the nginx include **in lockstep**.

## Excluding paths

Add paths or regexes to **Exclude paths** in the settings page (one per line). For WooCommerce:

```
/cart
/checkout
/my-account
```

For cookie-based bypasses (e.g. WooCommerce cart), extend the nginx cookie regex in `sqrd-page-cache.conf`:

```nginx
if ($http_cookie ~* "(wordpress_logged_in_|woocommerce_items_in_cart)") {
```

Both the PHP exclude list and the nginx cookie pattern must stay in sync.

## Filters

| Filter | Default | Description |
|--------|---------|-------------|
| `sqrd_page_cache/respect_donotcachepage` | `false` | Set to `true` to honour the `DONOTCACHEPAGE` constant |
| `sqrd_page_cache/tracking_params` | built-in list | Add extra tracking parameter patterns (supports `*` suffix glob) |
| `sqrd_page_cache/minify_html_options` | configured `HtmlMin` instance | Swap or reconfigure the HTML minifier — accepts any object with `minify(string): string` |
| `sqrd_page_cache/significant_headers` | standard list | Extend which response headers are written to the `.headers` sidecar |

## HTML minification

Enabled by default. Toggle under **Settings → SQRD Page Cache**. Strips redundant whitespace and HTML comments before the file is persisted. Inline `<script>`, `<style>`, `<pre>`, `<textarea>`, and IE conditional comments are protected automatically. Markdown variants are never minified.

Per-region opt-out:

```html
<nocompress>…this block is not minified…</nocompress>
```

Closing tags (`</html>`, `</body>`, `</p>`, etc.) and attribute quotes are always preserved.

## Development

```bash
composer install
composer test              # Pest test suite
composer test:coverage     # with coverage
```

Tests cover: Headers, Minifier, Negotiation, Output, Paths, Store, Varnish.

## License

GPL-2.0-or-later
