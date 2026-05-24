## Commands

```bash
composer install --no-dev   # first-time setup (vendor/ is gitignored)
composer test               # run Pest suite (aliases pest --no-coverage)
composer test:coverage      # with coverage
```

## Architecture

Plugin caches two response variants per URL to disk; nginx serves them directly.

| Variant | Cache file | Condition |
|---------|-----------|-----------|
| HTML | `index.html` + `.html.gz` | default |
| Markdown | `index.md` + `.md.gz` | `Accept: text/markdown` |

A `.headers` sidecar (`index.html.headers` / `index.md.headers`) stores response headers in plain HTTP format for future Lua/njs injection.

```
src/
  Plugin.php       — singleton bootstrap, WP hook registration
  Output.php       — ob_start/ob_end capture pipeline
  Store.php        — atomic disk write + gzip sibling
  Paths.php        — URL → cache file path (strips query strings)
  Invalidator.php  — WP event hooks → purge fan-out
  Headers.php      — response header capture
  Minifier.php     — HTML minification sink (akankov/html-min)
  Negotiation.php  — Accept-header → variant resolver
  Varnish.php      — Varnish BAN client
  Admin.php        — settings page + nginx config preview
nginx/
  sqrd-page-cache.conf  — include this in server{} above location/
```

## Conventions

**Hook naming** — all WordPress filters and actions registered by this plugin MUST be namespaced with the `sqrd_page_cache/` prefix (slash-separated). Example: `sqrd_page_cache/minify_html_options`, `sqrd_page_cache/respect_donotcachepage`. Do not introduce new hooks under the legacy `sqrd_cache_*` underscore style — those are kept only for back-compat and will be renamed.

## Gotchas

**nginx ↔ PHP tracking-params sync** — `nginx/sqrd-page-cache.conf` hardcodes a regex of tracking params (utm_*, fbclid, _ga*, etc.). `Output::TRACKING_PATTERNS` in `src/Output.php` is the PHP mirror. Both must be updated together when patterns change. There is no runtime link between them.

**nginx ↔ PHP bypass-cookie sync** — same shape as the tracking-params sync. The cookie alternation in `nginx/sqrd-page-cache.conf` (the `if ($http_cookie ~* "(...)")` block) must mirror `Output::BYPASS_COOKIE_PREFIXES` exactly. PHP only runs on cache MISSes; nginx serves HITs without invoking PHP, so a shopper with `woocommerce_items_in_cart` (or any other bypass cookie) hits nginx first — if nginx's regex is missing that prefix, they get the anonymous cached page even though PHP would have correctly bypassed them. To extend in PHP only, hook `sqrd_page_cache/bypass_cookie_prefixes`; to extend in both layers, edit the constant and the nginx alternation in lockstep.

**WooCommerce integration auto-activates via `class_exists('WooCommerce')`** — `WooCommerce::register_hooks()` in `src/WooCommerce.php` early-returns when WC is absent, so it's safe to call unconditionally from `Plugin::init()`. Three responsibilities: purge product pages on product/stock/order-stock events that bypass `save_post`; full flush on `woocommerce_settings_saved` (currency/tax/format affects everything); auto-add the configurable cart/checkout/my-account permalinks to the exclude-paths list via `wc_get_page_id()` so renamed/localised pages are covered. Opt out with `add_filter('sqrd_page_cache/woocommerce_enabled', '__return_false')`. Product purges delegate to `Invalidator::on_post_change($product_id)` which already handles permalink + shop archive + product cats/tags + home — do not duplicate URL collection in `WooCommerce.php`.

**Accept-header parity** — nginx and PHP use the same rule: `Accept` contains `text/markdown` (case-insensitive) → md variant. If this rule ever changes, update `Negotiation::ext_for_accept()` and the nginx `if ($http_accept ~* "text/markdown")` block in lockstep.

**HtmlMin omitted-tag stripping** — `akankov/html-min` defaults `removeOmittedHtmlTags=true`. `Minifier::configured_instance()` explicitly calls `->doRemoveOmittedHtmlTags(false)->doRemoveOmittedQuotes(false)`. Do not remove these — omitting closing tags breaks downstream regex tooling and snapshot tests even though browsers parse fine.

**Minifier filter duck-typing** — `sqrd_page_cache/minify_html_options` filter accepts any object with a `minify(string): string` method, not just `HtmlMin` instances. Filter name uses slash convention; the old `sqrd_cache_minify_html_options` name is dead.

**Cache key = path only** — `Paths::file_for()` strips query strings. All tracking-param variants of a URL (`?utm_source=x&fbclid=y`) share one cache file — no extra key logic needed or wanted.

**Path validation is load-bearing security, not ergonomics** — `Paths::normalize_uri()` THROWS `\InvalidArgumentException` on raw `..` and percent-encoded `%2e%2e`; `Paths::normalize_host()` THROWS on empty/dot-only/leading-dot/double-dot hosts; `Paths::file_for()` then runs a `realpath()` bound check against the cache root. Do NOT change any of these to "return a fallback" — the original 0.1.5 fallback (`/` for traversal, dots preserved in host) was an arbitrary-write primitive. New callers MUST wrap `file_for()` in try/catch and skip the operation on failure (see `Output::finish()` for the pattern).

**`matches_any()` rejects empty patterns and bare `*`** — a `sqrd_page_cache/tracking_params` filter returning `['*']` would otherwise match every key via `str_starts_with($key, '')` and collapse all query strings onto one cache file. If you ever add a new pattern syntax (e.g. regex), apply the same "non-empty stem" guard.

**`Store::rmdir_recursive()` must not follow symlinks** — built on `opendir`/`readdir` with explicit `is_link()` checks for that reason. `RecursiveDirectoryIterator` descends through symlinked dirs by default; using it here would turn `flush_all` into an arbitrary-file-delete primitive.

**`Store::atomic_write()` temp suffix uses `random_bytes()`** — not `getmypid()`. A single PHP-FPM worker can handle overlapping writes (sub-requests, `fastcgi_finish_request` continuations); PID collisions would publish a partially written body via rename.
