<?php

declare(strict_types=1);

namespace sqrd\Cache;

class Plugin
{
    private static ?self $instance = null;
    private string $file;

    private function __construct() {}

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init(string $plugin_file): void
    {
        $this->file = $plugin_file;

        register_activation_hook($plugin_file, [self::class, 'on_activate']);
        register_deactivation_hook($plugin_file, [self::class, 'on_deactivate']);

        // Bail if caching is disabled via option.
        if (!get_option('sqrd_cache_enabled', true)) {
            return;
        }

        // Start capturing output as early as possible.
        add_action('init', [Output::class, 'start'], 0);

        // Cache invalidation hooks.
        Invalidator::register_hooks();

        // Admin UI.
        if (is_admin() || wp_doing_ajax()) {
            Admin::register_hooks();
        }
    }

    public static function on_activate(): void
    {
        wp_mkdir_p(Paths::cache_root());

        // Set sensible defaults on first activation only.
        if (get_option('sqrd_cache_enabled') === false) {
            update_option('sqrd_cache_enabled', true);
            update_option('sqrd_cache_ttl_hours', 24);
            update_option('sqrd_cache_compress', true);
            update_option('sqrd_cache_exclude_paths', [
                '/cart',
                '/checkout',
                '/my-account',
                '/wp-json',
                '/feed',
            ]);
        }
    }

    public static function on_deactivate(): void
    {
        // Intentionally does NOT flush the cache — files remain for inspection.
        // Use "Purge all" in admin if needed before reactivating or uninstalling.
    }
}
