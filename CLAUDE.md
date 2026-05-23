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

**Accept-header parity** — nginx and PHP use the same rule: `Accept` contains `text/markdown` (case-insensitive) → md variant. If this rule ever changes, update `Negotiation::ext_for_accept()` and the nginx `if ($http_accept ~* "text/markdown")` block in lockstep.

**HtmlMin omitted-tag stripping** — `akankov/html-min` defaults `removeOmittedHtmlTags=true`. `Minifier::configured_instance()` explicitly calls `->doRemoveOmittedHtmlTags(false)->doRemoveOmittedQuotes(false)`. Do not remove these — omitting closing tags breaks downstream regex tooling and snapshot tests even though browsers parse fine.

**Minifier filter duck-typing** — `sqrd_page_cache/minify_html_options` filter accepts any object with a `minify(string): string` method, not just `HtmlMin` instances. Filter name uses slash convention; the old `sqrd_cache_minify_html_options` name is dead.

**Cache key = path only** — `Paths::file_for()` strips query strings. All tracking-param variants of a URL (`?utm_source=x&fbclid=y`) share one cache file — no extra key logic needed or wanted.
