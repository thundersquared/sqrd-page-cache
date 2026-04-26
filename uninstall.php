<?php

declare(strict_types=1);

defined('WP_UNINSTALL_PLUGIN') || exit;

// Remove all plugin options.
$options = [
    'sqrd_cache_enabled',
    'sqrd_cache_ttl_hours',
    'sqrd_cache_compress',
    'sqrd_cache_exclude_paths',
];
foreach ($options as $option) {
    delete_option($option);
}

// Flush the cache directory.
$cache_root = WP_CONTENT_DIR . '/cache/sqrd-page-cache';
if (is_dir($cache_root)) {
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($cache_root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iter as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }
    @rmdir($cache_root);
}
