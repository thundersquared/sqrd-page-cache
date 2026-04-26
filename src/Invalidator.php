<?php

declare(strict_types=1);

namespace sqrd\Cache;

class Invalidator
{
    private function __construct() {}

    public static function register_hooks(): void
    {
        // Per-URL purge on post changes.
        add_action('save_post',        [self::class, 'on_post_change'],   10, 1);
        add_action('delete_post',      [self::class, 'on_post_change'],   10, 1);
        add_action('wp_trash_post',    [self::class, 'on_post_change'],   10, 1);
        add_action('clean_post_cache', [self::class, 'on_post_change'],   10, 1);

        // Comments — purge the post that received the comment.
        add_action('comment_post',            [self::class, 'on_comment'], 10, 2);
        add_action('wp_set_comment_status',   [self::class, 'on_comment_by_id'], 10, 1);
        add_action('edit_comment',            [self::class, 'on_comment_by_id'], 10, 1);
        add_action('delete_comment',          [self::class, 'on_comment_by_id'], 10, 1);
        add_action('trackback_post',          [self::class, 'on_comment_by_id'], 10, 1);
        add_action('pingback_post',           [self::class, 'on_comment_by_id'], 10, 1);

        // Full flush on structural changes.
        add_action('switch_theme',                              [self::class, 'flush_all']);
        add_action('customize_save_after',                      [self::class, 'flush_all']);
        add_action('update_option_permalink_structure',         [self::class, 'flush_all']);
        add_action('update_option_home',                        [self::class, 'flush_all']);
        add_action('update_option_siteurl',                     [self::class, 'flush_all']);
        add_action('update_option_blogname',                    [self::class, 'flush_all']);
        add_action('update_option_blogdescription',             [self::class, 'flush_all']);
        add_action('update_option_page_on_front',               [self::class, 'flush_all']);
        add_action('update_option_page_for_posts',              [self::class, 'flush_all']);
        add_action('wp_update_nav_menu',                        [self::class, 'flush_all']);
    }

    public static function on_post_change(int $post_id): void
    {
        $post = get_post($post_id);
        if (!$post || $post->post_status === 'auto-draft') {
            return;
        }

        $urls = self::urls_for_post($post);
        Store::purge_urls($urls);
    }

    public static function on_comment(int $comment_id, mixed $comment_approved = null): void
    {
        $comment = get_comment($comment_id);
        if (!$comment) {
            return;
        }
        self::on_post_change((int) $comment->comment_post_ID);
    }

    public static function on_comment_by_id(int $comment_id): void
    {
        self::on_comment($comment_id);
    }

    public static function flush_all(): void
    {
        Store::flush_all();
    }

    // -------------------------------------------------------------------------

    /**
     * Collect all URLs to purge when a post changes.
     * Includes the post permalink, home, relevant archives, and feeds.
     *
     * @return list<string>
     */
    private static function urls_for_post(\WP_Post $post): array
    {
        $urls = [];

        $permalink = get_permalink($post->ID);
        if ($permalink) {
            $urls[] = $permalink;
        }

        // Home and front page.
        $urls[] = home_url('/');
        $front = get_option('page_on_front');
        if ($front && (int) $front !== $post->ID) {
            $front_url = get_permalink((int) $front);
            if ($front_url) {
                $urls[] = $front_url;
            }
        }

        // Post type archive.
        $pta = get_post_type_archive_link($post->post_type);
        if ($pta) {
            $urls[] = $pta;
        }

        // Term archives for all taxonomies.
        $taxonomies = get_object_taxonomies($post->post_type);
        foreach ($taxonomies as $taxonomy) {
            $terms = get_the_terms($post->ID, $taxonomy);
            if (!is_array($terms)) {
                continue;
            }
            foreach ($terms as $term) {
                $link = get_term_link($term, $taxonomy);
                if (!is_wp_error($link)) {
                    $urls[] = $link;
                }
            }
        }

        // Date archives.
        $year_link = get_year_link((int) get_the_date('Y', $post));
        if ($year_link) {
            $urls[] = $year_link;
        }

        return array_unique($urls);
    }
}
