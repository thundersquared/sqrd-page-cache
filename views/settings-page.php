<?php

declare(strict_types=1);

use sqrd\Cache\Admin;
use sqrd\Cache\Store;
use sqrd\Cache\Paths;

defined('ABSPATH') || exit;

$stats    = Store::stats();
$exclude  = get_option('sqrd_cache_exclude_paths', []);
$nginx_conf = file_get_contents(dirname(__DIR__) . '/nginx/sqrd-page-cache.conf');
$cache_root = Paths::cache_root();
$has_brotli = function_exists('brotli_compress');

$varnish_enabled    = (bool) get_option('sqrd_varnish_enabled', false);
$varnish_hosts      = (array) get_option('sqrd_varnish_hosts', []);
$varnish_tag_prefix = (string) get_option('sqrd_varnish_tag_prefix', '');

// Substitute the actual cache root path in the nginx config preview.
$nginx_preview = str_replace(
    '/wp-content/cache/sqrd-page-cache',
    str_replace(ABSPATH, '/', $cache_root),
    $nginx_conf
);

?>
<div class="wrap">
    <h1>SQRD Page Cache</h1>

    <form method="post" action="options.php">
        <?php settings_fields(Admin::option_group()); ?>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">Enable cache</th>
                <td>
                    <label>
                        <input type="checkbox" name="sqrd_cache_enabled" value="1"
                            <?php checked(get_option('sqrd_cache_enabled', true)); ?>>
                        Cache HTML and Markdown responses on disk for nginx to serve
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="sqrd_ttl">Cache TTL (hours)</label></th>
                <td>
                    <input type="number" id="sqrd_ttl" name="sqrd_cache_ttl_hours"
                        value="<?php echo esc_attr((string) get_option('sqrd_cache_ttl_hours', 24)); ?>"
                        min="0" step="1" class="small-text">
                    <p class="description">0 = no expiry; cache is invalidated only by content changes.</p>
                </td>
            </tr>
            <tr>
                <th scope="row">Pre-compress</th>
                <td>
                    <label>
                        <input type="checkbox" name="sqrd_cache_compress" value="1"
                            <?php checked(get_option('sqrd_cache_compress', true)); ?>>
                        Write <code>.gz</code><?php echo $has_brotli ? ' and <code>.br</code>' : ''; ?> siblings for use with nginx
                        <code>gzip_static on</code><?php echo $has_brotli ? ' / <code>brotli_static on</code>' : ''; ?>
                    </label>
                    <?php if (!$has_brotli) : ?>
                        <p class="description">PHP <code>brotli</code> extension not detected — only gzip siblings will be written.</p>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="sqrd_exclude">Exclude paths</label></th>
                <td>
                    <textarea id="sqrd_exclude" name="sqrd_cache_exclude_paths"
                        rows="6" class="large-text code"><?php
                        echo esc_textarea(implode("\n", (array) $exclude));
                    ?></textarea>
                    <p class="description">
                        One path prefix or regex per line. Example: <code>/cart</code>, <code>~/my-account/orders/\d+/</code>
                    </p>
                </td>
            </tr>
        </table>

        <h2>Varnish integration</h2>
        <p class="description">
            When enabled, the plugin issues <code>PURGE</code> per-URL and <code>BAN /</code> for
            full flushes to each configured Varnish endpoint. Every request carries an
            <code>X-Cache-Tag-Prefix</code> header so a multi-tenant Varnish can scope the BAN
            to this domain only. Failures are logged via <code>error_log</code> and do not
            block the WordPress request.
        </p>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">Enable Varnish purge</th>
                <td>
                    <label>
                        <input type="checkbox" name="sqrd_varnish_enabled" value="1"
                            <?php checked($varnish_enabled); ?>>
                        Send purge requests to upstream Varnish on cache invalidation
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="sqrd_varnish_hosts">Varnish hosts</label></th>
                <td>
                    <textarea id="sqrd_varnish_hosts" name="sqrd_varnish_hosts"
                        rows="4" class="large-text code"
                        placeholder="127.0.0.1:6081&#10;http://varnish-2.internal:6081"><?php
                        echo esc_textarea(implode("\n", $varnish_hosts));
                    ?></textarea>
                    <p class="description">
                        One <code>host[:port]</code> per line. Scheme defaults to <code>http://</code>.
                        All hosts receive every purge.
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="sqrd_varnish_tag_prefix">Cache tag prefix</label></th>
                <td>
                    <input type="text" id="sqrd_varnish_tag_prefix" name="sqrd_varnish_tag_prefix"
                        value="<?php echo esc_attr($varnish_tag_prefix); ?>"
                        class="regular-text" placeholder="example-com">
                    <p class="description">
                        Sent as <code>X-Cache-Tag-Prefix</code> on every purge. Use this to scope
                        a BAN to a single domain on a shared Varnish. Leave empty to omit the header.
                    </p>
                </td>
            </tr>
        </table>

        <?php submit_button('Save settings'); ?>
    </form>

    <hr>

    <h2>Cache statistics</h2>
    <table class="widefat fixed striped" style="max-width:480px">
        <tbody>
            <tr>
                <td><strong>Files on disk</strong></td>
                <td><?php echo number_format($stats['files']); ?></td>
            </tr>
            <tr>
                <td><strong>Total size</strong></td>
                <td><?php echo size_format($stats['bytes']); ?></td>
            </tr>
            <tr>
                <td><strong>Oldest file</strong></td>
                <td><?php echo $stats['oldest'] ? esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $stats['oldest'])) : '—'; ?></td>
            </tr>
            <tr>
                <td><strong>Newest file</strong></td>
                <td><?php echo $stats['newest'] ? esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $stats['newest'])) : '—'; ?></td>
            </tr>
            <tr>
                <td><strong>Cache root</strong></td>
                <td><code><?php echo esc_html($cache_root); ?></code></td>
            </tr>
        </tbody>
    </table>

    <p>
        <a href="<?php echo esc_url(Admin::purge_url()); ?>" class="button button-secondary"
            onclick="return confirm('Purge all cached pages?');">
            Purge all cached pages
        </a>
    </p>

    <hr>

    <h2>Nginx configuration</h2>
    <p>
        Add the following include inside your WordPress <code>server { }</code> block,
        <strong>above</strong> the <code>location /</code> block, then update your
        <code>location /</code> to use <code>try_files</code> as shown below.
        No <code>http { }</code> changes are required.
    </p>
    <h3>Include in <code>server { }</code></h3>
    <pre style="background:#f6f7f7;padding:12px;overflow:auto;max-height:400px;"><code><?php echo esc_html($nginx_preview); ?></code></pre>
    <h3>Update <code>location / { }</code></h3>
    <pre style="background:#f6f7f7;padding:12px;"><code>location / {
    try_files $sqrd_cache_file $uri $uri/ /index.php?$args;
}</code></pre>
</div>
