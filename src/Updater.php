<?php

declare(strict_types=1);

namespace sqrd\Cache;

/**
 * Hooks into WordPress's plugin update system and queries the GitHub Releases API
 * to surface new versions in the standard WP admin "Updates" screen.
 *
 * The GitHub repo must be public (no auth token required).  The release zip asset
 * built by .github/workflows/release.yml must contain the plugin in a top-level
 * `sqrd-page-cache/` directory — exactly what WP expects for installation.
 *
 * To point at a different repo, use the filters:
 *   add_filter('sqrd_cache_github_owner', fn() => 'myorg');
 *   add_filter('sqrd_cache_github_repo',  fn() => 'my-page-cache-fork');
 */
class Updater
{
    private const GITHUB_OWNER = 'thundersquared';
    private const GITHUB_REPO  = 'sqrd-page-cache';
    private const SLUG         = 'sqrd-page-cache';
    private const CACHE_KEY  = 'sqrd_cache_update_data';
    private const CACHE_TTL  = 43200; // 12 hours

    private string $basename;

    private function __construct(
        private readonly string $plugin_file,
        private readonly string $version,
    ) {
        $this->basename = plugin_basename($plugin_file);
    }

    public static function register(string $plugin_file, string $version): void
    {
        $instance = new self($plugin_file, $version);

        add_filter('pre_set_site_transient_update_plugins', [$instance, 'inject_update']);
        add_filter('plugins_api', [$instance, 'plugin_info'], 20, 3);
        add_action('upgrader_process_complete', [$instance, 'purge_transient'], 10, 2);

        // Surface a "force-check" link on the plugin's row in the Plugins screen.
        add_filter('plugin_action_links_' . plugin_basename($plugin_file), [$instance, 'add_check_link']);
    }

    // ── WP filter: inject update info into the update_plugins transient ───────

    /** @param mixed $transient */
    public function inject_update(mixed $transient): mixed
    {
        if (empty($transient->checked)) {
            return $transient;
        }

        $release = $this->fetch_latest_release();
        if ($release === null) {
            return $transient;
        }

        $remote_version = ltrim($release->tag_name, 'v');

        if (version_compare($this->version, $remote_version, '>=')) {
            // Already up to date — tell WP explicitly so it doesn't show a false positive.
            $transient->no_update[$this->basename] = $this->no_update_item($remote_version);
            return $transient;
        }

        $zip_url = $this->find_zip_url($release);
        if ($zip_url === null) {
            return $transient;
        }

        $transient->response[$this->basename] = (object) [
            'id'            => $this->basename,
            'slug'          => self::SLUG,
            'plugin'        => $this->basename,
            'new_version'   => $remote_version,
            'url'           => $release->html_url,
            'package'       => $zip_url,
            'icons'         => [],
            'banners'       => [],
            'banners_rtl'   => [],
            'requires'      => '6.4',
            'requires_php'  => '8.3',
            'tested'        => '6.8',
            'compatibility' => new \stdClass(),
        ];

        return $transient;
    }

    // ── WP filter: power the "View version details" overlay ──────────────────

    /** @param mixed $result */
    public function plugin_info(mixed $result, string $action, object $args): mixed
    {
        if ($action !== 'plugin_information') {
            return $result;
        }
        if (($args->slug ?? '') !== self::SLUG) {
            return $result;
        }

        $release = $this->fetch_latest_release();
        if ($release === null) {
            return $result;
        }

        $remote_version = ltrim($release->tag_name, 'v');
        $zip_url        = $this->find_zip_url($release);
        $repo_url       = $this->repo_url();

        return (object) [
            'name'          => 'SQRD Page Cache',
            'slug'          => self::SLUG,
            'version'       => $remote_version,
            'author'        => '<a href="' . esc_url($repo_url) . '">SQRD</a>',
            'homepage'      => $repo_url . '/releases',
            'download_link' => $zip_url,
            'requires'      => '6.4',
            'requires_php'  => '8.3',
            'tested'        => '6.8',
            'last_updated'  => $release->published_at ?? '',
            'sections'      => [
                'description' => 'Accept-aware disk page cache served directly by nginx. Caches text/html and text/markdown variants independently.',
                'changelog'   => nl2br((string) ($release->body ?? 'No release notes.')),
            ],
        ];
    }

    // ── WP action: clear cached release data after any plugin update ─────────

    /** @param mixed $upgrader */
    public function purge_transient(mixed $upgrader, array $options): void
    {
        if (
            ($options['action'] ?? '') === 'update' &&
            ($options['type'] ?? '') === 'plugin'
        ) {
            delete_transient(self::CACHE_KEY);
        }
    }

    // ── Plugin row link: "Check for updates" ─────────────────────────────────

    /** @param list<string> $links */
    public function add_check_link(array $links): array
    {
        $url = wp_nonce_url(
            add_query_arg(['sqrd_cache_check_update' => '1'], self_admin_url('plugins.php')),
            'sqrd_cache_check_update'
        );
        $links[] = '<a href="' . esc_url($url) . '">' . esc_html__('Check for updates', 'sqrd-page-cache') . '</a>';
        return $links;
    }

    /**
     * Handle the "Check for updates" link click: purge the cached release data
     * so the next transient rebuild hits the API fresh.  Must be hooked to `admin_init`.
     */
    public static function handle_manual_check(): void
    {
        if (!isset($_GET['sqrd_cache_check_update'])) {
            return;
        }
        if (!current_user_can('update_plugins')) {
            return;
        }
        check_admin_referer('sqrd_cache_check_update');

        delete_transient(self::CACHE_KEY);
        delete_site_transient('update_plugins');

        wp_safe_redirect(remove_query_arg(['sqrd_cache_check_update', '_wpnonce'], wp_get_referer() ?: self_admin_url('plugins.php')));
        exit;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function fetch_latest_release(): ?\stdClass
    {
        $cached = get_transient(self::CACHE_KEY);
        if ($cached !== false && $cached instanceof \stdClass) {
            return $cached;
        }

        $url = $this->api_url();

        $response = wp_remote_get($url, [
            'timeout' => 10,
            'headers' => [
                'Accept'     => 'application/vnd.github+json',
                'User-Agent' => 'sqrd-page-cache/' . $this->version . '; WordPress/' . get_bloginfo('version'),
            ],
        ]);

        if (is_wp_error($response)) {
            error_log('sqrd-page-cache updater: ' . $response->get_error_message());
            return null;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            error_log("sqrd-page-cache updater: GitHub API returned HTTP {$code}");
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response));
        if (!($body instanceof \stdClass) || !isset($body->tag_name)) {
            return null;
        }

        set_transient(self::CACHE_KEY, $body, self::CACHE_TTL);
        return $body;
    }

    private function find_zip_url(\stdClass $release): ?string
    {
        foreach ($release->assets ?? [] as $asset) {
            if (str_ends_with((string) ($asset->name ?? ''), '.zip')) {
                return (string) $asset->browser_download_url;
            }
        }
        return null;
    }

    private function no_update_item(string $version): \stdClass
    {
        return (object) [
            'id'            => $this->basename,
            'slug'          => self::SLUG,
            'plugin'        => $this->basename,
            'new_version'   => $version,
            'url'           => $this->repo_url() . '/releases',
            'package'       => '',
            'icons'         => [],
            'banners'       => [],
            'banners_rtl'   => [],
            'requires'      => '6.4',
            'requires_php'  => '8.3',
            'compatibility' => new \stdClass(),
        ];
    }

    private function api_url(): string
    {
        return sprintf(
            'https://api.github.com/repos/%s/%s/releases/latest',
            rawurlencode((string) apply_filters('sqrd_cache_github_owner', self::GITHUB_OWNER)),
            rawurlencode((string) apply_filters('sqrd_cache_github_repo', self::GITHUB_REPO)),
        );
    }

    private function repo_url(): string
    {
        return sprintf(
            'https://github.com/%s/%s',
            (string) apply_filters('sqrd_cache_github_owner', self::GITHUB_OWNER),
            (string) apply_filters('sqrd_cache_github_repo', self::GITHUB_REPO),
        );
    }
}
