<?php

declare(strict_types=1);

namespace sqrd\Cache;

class Admin
{
    private const OPTION_GROUP   = 'sqrd_cache_settings';
    private const MENU_SLUG      = 'sqrd-page-cache';
    private const PURGE_ACTION   = 'sqrd_cache_purge_all';
    private const NONCE_ACTION   = 'sqrd_cache_purge_all_nonce';

    private function __construct() {}

    public static function register_hooks(): void
    {
        add_action('admin_menu',         [self::class, 'add_settings_page']);
        add_action('admin_init',         [self::class, 'register_settings']);
        add_action('admin_bar_menu',     [self::class, 'add_admin_bar_item'], 100);
        add_action('admin_post_' . self::PURGE_ACTION, [self::class, 'handle_purge']);
        add_action('admin_notices',      [self::class, 'show_notices']);
    }

    public static function add_settings_page(): void
    {
        add_options_page(
            'SQRD Page Cache',
            'SQRD Page Cache',
            'manage_options',
            self::MENU_SLUG,
            [self::class, 'render_settings_page']
        );
    }

    public static function register_settings(): void
    {
        register_setting(self::OPTION_GROUP, 'sqrd_cache_enabled', [
            'type'              => 'boolean',
            'default'           => true,
            'sanitize_callback' => 'rest_sanitize_boolean',
        ]);
        register_setting(self::OPTION_GROUP, 'sqrd_cache_ttl_hours', [
            'type'              => 'integer',
            'default'           => 24,
            'sanitize_callback' => fn($v) => max(0, (int) $v),
        ]);
        register_setting(self::OPTION_GROUP, 'sqrd_cache_compress', [
            'type'              => 'boolean',
            'default'           => true,
            'sanitize_callback' => 'rest_sanitize_boolean',
        ]);
        register_setting(self::OPTION_GROUP, 'sqrd_cache_exclude_paths', [
            'type'              => 'array',
            'default'           => ['/cart', '/checkout', '/my-account', '/wp-json', '/feed'],
            'sanitize_callback' => [self::class, 'sanitize_exclude_paths'],
        ]);
    }

    /** @return list<string> */
    public static function sanitize_exclude_paths(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = explode("\n", $raw);
        }
        return array_values(array_filter(array_map('trim', (array) $raw)));
    }

    public static function add_admin_bar_item(\WP_Admin_Bar $bar): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $bar->add_node([
            'id'    => 'sqrd-cache-purge',
            'title' => 'Purge Cache',
            'href'  => self::purge_url(),
            'meta'  => ['title' => 'Purge the SQRD page cache'],
        ]);
    }

    public static function handle_purge(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized', 403);
        }

        check_admin_referer(self::NONCE_ACTION);

        Store::flush_all();

        wp_redirect(add_query_arg(
            ['page' => self::MENU_SLUG, 'sqrd_purged' => '1'],
            admin_url('options-general.php')
        ));
        exit;
    }

    public static function show_notices(): void
    {
        if (
            isset($_GET['sqrd_purged']) &&
            current_user_can('manage_options') &&
            get_current_screen()?->id === 'settings_page_' . self::MENU_SLUG
        ) {
            echo '<div class="notice notice-success is-dismissible"><p><strong>SQRD Page Cache:</strong> All cached pages have been purged.</p></div>';
        }
    }

    public static function render_settings_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        require __DIR__ . '/../views/settings-page.php';
    }

    public static function purge_url(): string
    {
        return wp_nonce_url(
            admin_url('admin-post.php?action=' . self::PURGE_ACTION),
            self::NONCE_ACTION
        );
    }

    public static function option_group(): string
    {
        return self::OPTION_GROUP;
    }

    public static function menu_slug(): string
    {
        return self::MENU_SLUG;
    }
}
