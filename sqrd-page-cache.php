<?php

/**
 * Plugin Name:       SQRD Page Cache
 * Plugin URI:        https://github.com/sqrd/page-cache
 * Description:       Accept-aware disk page cache served directly by nginx. Caches text/html and text/markdown variants independently and stores a headers sidecar for forward-compatibility.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.3
 * Author:            SQRD
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sqrd-page-cache
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

if (PHP_VERSION_ID < 80300) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p><strong>SQRD Page Cache</strong> requires PHP 8.3 or higher. The plugin has been disabled.</p></div>';
    });
    return;
}

$sqrd_autoload = __DIR__ . '/vendor/autoload.php';
if (!is_file($sqrd_autoload)) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p><strong>SQRD Page Cache:</strong> <code>vendor/autoload.php</code> is missing. Run <code>composer install</code> inside the plugin directory.</p></div>';
    });
    return;
}
require $sqrd_autoload;

sqrd\Cache\Plugin::instance()->init(__FILE__);
