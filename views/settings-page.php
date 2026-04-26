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
                        Write <code>.gz</code> siblings for use with nginx <code>gzip_static on</code>
                    </label>
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
