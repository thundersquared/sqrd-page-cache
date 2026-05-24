<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use sqrd\Cache\WooCommerce as WC;

/*
 * Declared once for the whole Pest suite. WC::register_hooks() guards
 * on `class_exists('WooCommerce')` and is a no-op without it. Pest's test order
 * is not guaranteed so we cannot reliably test the "absent" branch in isolation;
 * the guard is a trivial early-return covered by inspection.
 */
if (!class_exists('WooCommerce')) {
    class WooCommerce {}
}

describe('WC::register_hooks', function (): void {

    it('registers product lifecycle hooks at default priority', function (): void {
        WC::register_hooks();

        expect(Actions\has('woocommerce_update_product', [WC::class, 'on_product_change']))->toBe(10);
        expect(Actions\has('woocommerce_new_product',    [WC::class, 'on_product_change']))->toBe(10);
        expect(Actions\has('woocommerce_delete_product', [WC::class, 'on_product_change']))->toBe(10);
        expect(Actions\has('woocommerce_trash_product',  [WC::class, 'on_product_change']))->toBe(10);
    });

    it('registers stock-change hooks for both object and id variants', function (): void {
        WC::register_hooks();

        expect(Actions\has('woocommerce_product_set_stock',          [WC::class, 'on_product_object_change']))->toBe(10);
        expect(Actions\has('woocommerce_variation_set_stock',        [WC::class, 'on_product_object_change']))->toBe(10);
        expect(Actions\has('woocommerce_product_set_stock_status',   [WC::class, 'on_product_id_change']))->toBe(10);
        expect(Actions\has('woocommerce_variation_set_stock_status', [WC::class, 'on_product_id_change']))->toBe(10);
    });

    it('registers the order stock decrement hook', function (): void {
        WC::register_hooks();

        expect(Actions\has('woocommerce_reduce_order_stock', [WC::class, 'on_order_stock_change']))->toBe(10);
    });

    it('flushes the entire cache when WooCommerce settings are saved', function (): void {
        WC::register_hooks();

        expect(Actions\has('woocommerce_settings_saved', [\sqrd\Cache\Invalidator::class, 'flush_all']))->toBe(10);
    });

    it('hooks the exclude-paths filter', function (): void {
        WC::register_hooks();

        expect(Filters\has('sqrd_page_cache/exclude_paths', [WC::class, 'add_wc_page_exclusions']))->toBe(10);
    });

    it('is a no-op when the woocommerce_enabled filter returns false', function (): void {
        Monkey\Functions\when('apply_filters')->alias(function (string $name, mixed $value): mixed {
            if ($name === 'sqrd_page_cache/woocommerce_enabled') {
                return false;
            }
            return $value;
        });

        WC::register_hooks();

        expect(Actions\has('woocommerce_update_product'))->toBeFalse();
        expect(Actions\has('woocommerce_settings_saved'))->toBeFalse();
        expect(Filters\has('sqrd_page_cache/exclude_paths'))->toBeFalse();
    });
});

describe('WC::add_wc_page_exclusions', function (): void {

    it('appends cart, checkout, and my-account permalinks as relative paths', function (): void {
        $pages = ['cart' => 11, 'checkout' => 12, 'myaccount' => 13];
        $permalinks = [
            11 => 'https://shop.example.com/basket/',
            12 => 'https://shop.example.com/pay/',
            13 => 'https://shop.example.com/account/',
        ];

        Monkey\Functions\when('wc_get_page_id')->alias(fn(string $k): int => $pages[$k] ?? 0);
        Monkey\Functions\when('get_permalink')->alias(fn(int $id): string => $permalinks[$id] ?? '');
        Monkey\Functions\when('wp_make_link_relative')->alias(
            fn(string $url): string => (string) parse_url($url, PHP_URL_PATH)
        );

        $result = WC::add_wc_page_exclusions(['/wp-json', '/feed']);

        expect($result)->toBe(['/wp-json', '/feed', '/basket/', '/pay/', '/account/']);
    });

    it('dedupes when an excluded path is already present', function (): void {
        Monkey\Functions\when('wc_get_page_id')->alias(fn(string $k): int => $k === 'cart' ? 11 : 0);
        Monkey\Functions\when('get_permalink')->justReturn('https://shop.example.com/cart/');
        Monkey\Functions\when('wp_make_link_relative')->alias(
            fn(string $url): string => (string) parse_url($url, PHP_URL_PATH)
        );

        $result = WC::add_wc_page_exclusions(['/cart/']);

        expect($result)->toBe(['/cart/']);
    });

    it('skips pages that resolve to root or empty path', function (): void {
        Monkey\Functions\when('wc_get_page_id')->alias(fn(string $k): int => $k === 'cart' ? 11 : 0);
        Monkey\Functions\when('get_permalink')->justReturn('https://shop.example.com/');
        Monkey\Functions\when('wp_make_link_relative')->alias(
            fn(string $url): string => (string) parse_url($url, PHP_URL_PATH)
        );

        $result = WC::add_wc_page_exclusions(['/wp-json']);

        expect($result)->toBe(['/wp-json']);
    });

    it('skips pages with no configured permalink', function (): void {
        Monkey\Functions\when('wc_get_page_id')->alias(fn(string $k): int => 0);

        $result = WC::add_wc_page_exclusions(['/existing']);

        expect($result)->toBe(['/existing']);
    });
});

describe('WC::on_product_object_change', function (): void {

    it('ignores non-object arguments without throwing', function (): void {
        expect(fn(): mixed => WC::on_product_object_change(null))->not->toThrow(Throwable::class);
        expect(fn(): mixed => WC::on_product_object_change('not a product'))->not->toThrow(Throwable::class);
        expect(fn(): mixed => WC::on_product_object_change(42))->not->toThrow(Throwable::class);
    });

    it('ignores objects without a get_id() method', function (): void {
        $bad = new stdClass();
        $bad->id = 7;
        expect(fn(): mixed => WC::on_product_object_change($bad))->not->toThrow(Throwable::class);
    });
});

describe('WC::on_order_stock_change', function (): void {

    it('ignores non-order arguments without throwing', function (): void {
        expect(fn(): mixed => WC::on_order_stock_change(null))->not->toThrow(Throwable::class);
        expect(fn(): mixed => WC::on_order_stock_change(new stdClass()))->not->toThrow(Throwable::class);
    });
});
