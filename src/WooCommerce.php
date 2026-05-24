<?php

declare(strict_types=1);

namespace sqrd\Cache;

/**
 * WooCommerce-aware cache invalidation and exclusions.
 *
 * Auto-activated when WooCommerce is loaded. Three responsibilities:
 *
 *  1. Purge product/shop/category pages on product, stock, and order events
 *     that the core Invalidator (which only listens to save_post and friends)
 *     does not cover — direct CLI/API updates and stock changes triggered
 *     by checkout do not always fire save_post.
 *
 *  2. Purge the entire cache when WooCommerce settings are saved. Currency,
 *     tax display, and price formatting changes affect every product and
 *     shop page, so a targeted purge is not worth the complexity.
 *
 *  3. Auto-add the configurable cart / checkout / my-account permalinks to
 *     the exclude-paths list. The defaults in Admin.php cover the common
 *     English slugs; this filter handles renamed or localised pages.
 *
 * Opt-out: filter `sqrd_page_cache/woocommerce_enabled` returning false.
 *
 * @see Invalidator::on_post_change() — already purges product permalink,
 *      home, post-type archive (shop page), and all product cat/tag terms.
 */
final class WooCommerce
{
    private function __construct() {}

    /**
     * Register hooks if WooCommerce is loaded and the integration is enabled.
     * Safe to call unconditionally from Plugin::init().
     */
    public static function register_hooks(): void
    {
        if (!self::is_enabled()) {
            return;
        }

        // Product lifecycle — direct API/CLI updates bypass save_post.
        add_action('woocommerce_update_product',  [self::class, 'on_product_change'], 10, 1);
        add_action('woocommerce_new_product',     [self::class, 'on_product_change'], 10, 1);
        add_action('woocommerce_delete_product',  [self::class, 'on_product_change'], 10, 1);
        add_action('woocommerce_trash_product',   [self::class, 'on_product_change'], 10, 1);

        // Stock level / status changes — price+stock are rendered into HTML.
        add_action('woocommerce_product_set_stock',            [self::class, 'on_product_object_change'], 10, 1);
        add_action('woocommerce_variation_set_stock',          [self::class, 'on_product_object_change'], 10, 1);
        add_action('woocommerce_product_set_stock_status',     [self::class, 'on_product_id_change'],     10, 1);
        add_action('woocommerce_variation_set_stock_status',   [self::class, 'on_product_id_change'],     10, 1);

        // Stock decrement when an order is placed.
        add_action('woocommerce_reduce_order_stock', [self::class, 'on_order_stock_change'], 10, 1);

        // Settings changes — too broad to target, flush everything.
        add_action('woocommerce_settings_saved', [Invalidator::class, 'flush_all']);

        // Auto-exclude WooCommerce's configured cart/checkout/account pages.
        add_filter('sqrd_page_cache/exclude_paths', [self::class, 'add_wc_page_exclusions']);
    }

    /**
     * @internal Public for testing — purge cached pages for a product by ID.
     */
    public static function on_product_change(int $product_id): void
    {
        Invalidator::on_post_change($product_id);
    }

    /**
     * @internal Public for testing — purge cached pages from a product object.
     * Accepts any object with a `get_id()` method (WC_Product, WC_Product_Variation).
     */
    public static function on_product_object_change(mixed $product): void
    {
        if (!is_object($product) || !method_exists($product, 'get_id')) {
            return;
        }
        $id = (int) $product->get_id();
        if ($id > 0) {
            self::on_product_change($id);
        }
    }

    /**
     * @internal Public for testing — alias for hooks that pass the product ID
     * directly. Kept distinct from on_product_change for hook-registration
     * symmetry (mirrors WooCommerce's two flavours: object hooks vs id hooks).
     */
    public static function on_product_id_change(int $product_id): void
    {
        self::on_product_change($product_id);
    }

    /**
     * @internal Public for testing — iterate order line items and purge each
     * product page. Used when a checkout completes and stock decrements.
     */
    public static function on_order_stock_change(mixed $order): void
    {
        if (!is_object($order) || !method_exists($order, 'get_items')) {
            return;
        }
        foreach ($order->get_items() as $item) {
            if (!is_object($item) || !method_exists($item, 'get_product_id')) {
                continue;
            }
            $id = (int) $item->get_product_id();
            if ($id > 0) {
                self::on_product_change($id);
            }
        }
    }

    /**
     * Filter callback for `sqrd_page_cache/exclude_paths` — appends the
     * relative permalinks of WC's cart, checkout, and my-account pages.
     *
     * @param list<string> $paths
     * @return list<string>
     */
    public static function add_wc_page_exclusions(array $paths): array
    {
        if (!function_exists('wc_get_page_id')) {
            return $paths;
        }

        foreach (['cart', 'checkout', 'myaccount'] as $key) {
            $page_id = (int) wc_get_page_id($key);
            if ($page_id <= 0) {
                continue;
            }
            $permalink = function_exists('get_permalink') ? get_permalink($page_id) : '';
            if (!is_string($permalink) || $permalink === '') {
                continue;
            }
            $relative = function_exists('wp_make_link_relative')
                ? wp_make_link_relative($permalink)
                : (string) parse_url($permalink, PHP_URL_PATH);
            if (is_string($relative) && $relative !== '' && $relative !== '/') {
                $paths[] = $relative;
            }
        }

        return array_values(array_unique($paths));
    }

    private static function is_enabled(): bool
    {
        if (!class_exists('WooCommerce')) {
            return false;
        }
        return (bool) apply_filters('sqrd_page_cache/woocommerce_enabled', true);
    }
}
