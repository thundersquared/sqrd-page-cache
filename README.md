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
- Accept-aware next-gen image serving — AVIF/WebP variants of uploaded JPEG/PNG images served by nginx based on the client's `Accept` header (generation delegated to a conversion plugin)

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

## Next-gen image serving (AVIF/WebP)

The nginx include negotiates **AVIF/WebP** variants of uploaded JPEG/PNG images based on the client's `Accept` header — nginx serves the best supported variant at static-file speed before PHP is ever involved. **The plugin only serves; it does not generate variants.** Pair it with a third-party conversion plugin that writes the `.avif`/`.webp` siblings next to (or alongside) your originals.

### Supported conversion plugins

The upload-image location in `nginx/sqrd-page-cache.conf` walks every common sibling-file convention with `try_files`, so any of the following work without custom rewrite rules:

| Plugin(s) | AVIF | WebP | Local? | Free & unlimited? | Sibling convention |
|-----------|:----:|:----:|:------:|:-----------------:|--------------------|
| **CompressX** (recommended, solo) | yes | yes | yes (Imagick) | yes | Appended, same dir |
| **WebP Express + AVIF Express** (combo) | yes | yes | yes (Imagick/GD) | yes | Appended, same dir |
| Converter for Media | PRO | yes | yes | WebP free, AVIF paid | Separate dir |
| Imagify | yes | yes | no (cloud) | no (20 MB/mo) | Appended, same dir |
| ShortPixel | yes | yes | no (cloud) | no (~50 credits/mo) | Appended, same dir |
| EWWW Image Optimizer | Premium | yes | yes (needs `exec()`+binaries) | WebP free, AVIF paid | Appended, same dir |
| WebP Express (solo) | no | yes | yes | yes | Appended, same dir |
| LiteSpeed Cache | yes | yes | server-managed | yes | **conflicts** — it's a page-cache plugin |

**Recommended for local / free / unlimited / both formats:**

- **CompressX** — one plugin, both AVIF + WebP, 100 % local via PHP Imagick, no quotas, no cloud. Best single-plugin option.
- **WebP Express + AVIF Express** — combine the mature, local-only WebP generator with a local AVIF generator. Each plugin owns one format. Both are free and unlimited.

> **Avoid LiteSpeed Cache** on nginx — it duplicates full-page caching and conflicts with SQRD Page Cache.

### Pros & cons

**CompressX**

- ✅ Both AVIF + WebP in one free, local, unlimited plugin
- ✅ Uses PHP Imagick (no `exec()` / server binaries needed)
- ⚠️ Newer project (v0.9.x) than the decade-old incumbents

**WebP Express + AVIF Express (combo)**

- ✅ Both formats, fully local, free, unlimited
- ✅ WebP Express is mature and battle-tested
- ⚠️ Two plugins to configure and keep in sync
- ⚠️ AVIF Express needs Imagick ≥ 7.0.25 *or* GD compiled with libavif; verify your host
- ⚠️ Verify the two plugins don't trip each other's "multiple optimizer detected" guard (they target different output extensions, so this is unlikely)

**Converter for Media** (alternative)

- ✅ WebP fully free & local; clean uninstall (separate dir, self-removing)
- ⚠️ AVIF is PRO-only (paid)

**WebP Express (solo)**

- ✅ Mature, local, free, unlimited WebP
- ❌ No AVIF support at all

### Setup

1. Install **and activate** a conversion plugin from the table above (CompressX, or WebP Express + AVIF Express).
2. **Enable generation** of AVIF and/or WebP in the plugin's settings.
3. **Disable the plugin's own delivery / rewrite / HTML-alteration feature** — SQRD Page Cache's nginx include does the serving. Do *not* paste the conversion plugin's nginx/`.htaccess` rewrite snippet; our include replaces it.
4. If using **WebP Express**, set its storage mode to **"Mingled"** (converted `.webp` files next to originals) so the appended-convention `try_files` candidate finds them.
5. Run the plugin's bulk conversion to generate variants for existing images.
6. Pull the updated `nginx/sqrd-page-cache.conf` and reload: `nginx -t && systemctl reload nginx`.

> **Host requirement for AVIF generation:** an AVIF-capable Imagick build (compiled with libheif/libaom) **or** GD compiled with libavif. Your conversion plugin will report whether AVIF generation is available.

### How nginx picks the variant

For a request to `/wp-content/uploads/2024/01/cat.jpg`, nginx resolves `$sqrd_img_ext` from the `Accept` header (AVIF preferred over WebP, empty if neither) then `try_files`:

1. `cat.jpg.avif` (or `.webp`) — appended, same directory
2. `cat.avif` — replaced extension, same directory
3. `wp-content/uploads-webpc/.../cat.jpg.avif` — Converter for Media's separate directory
4. `cat.jpg` — original fallback (always served when no variant exists or the browser supports neither)

Every response carries `Vary: Accept` so CDNs and browsers cache the correct per-client variant, plus a one-year immutable `Cache-Control`.

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
