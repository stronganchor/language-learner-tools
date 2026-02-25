<?php
/*
Plugin Name: Language Learner Tools
Plugin URI: https://github.com/stronganchor/language-learner-tools
Description: WordPress tools for building language-learning vocabulary content with word management, audio/image uploads, and ready-to-use flashcard quizzes and embeddable practice pages.
Version: 5.5.0
Author: Strong Anchor Tech
Author URI: https://stronganchortech.com
Text Domain: ll-tools-text-domain
Domain Path: /languages
*/

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

define('LL_TOOLS_BASE_URL', plugin_dir_url(__FILE__)); 
define('LL_TOOLS_BASE_PATH', plugin_dir_path(__FILE__));
define('LL_TOOLS_MAIN_FILE', __FILE__);
define('LL_TOOLS_MIN_WORDS_PER_QUIZ', 5);
define('LL_TOOLS_SETTINGS_SLUG', 'language-learning-tools-settings');

function ll_tools_normalize_update_branch($branch) {
    return ($branch === 'dev') ? 'dev' : 'main';
}

function ll_tools_get_update_branch() {
    $branch = get_option('ll_update_branch', 'main');
    return ll_tools_normalize_update_branch($branch);
}

/**
 * Only boot the plugin update checker where it is useful.
 *
 * Front-end page requests don't need PUC hooks and object setup.
 */
function ll_tools_should_boot_update_checker() {
    if (is_admin()) {
        return true;
    }

    if (function_exists('wp_doing_cron') && wp_doing_cron()) {
        return true;
    }

    if (defined('WP_CLI') && WP_CLI) {
        return true;
    }

    return false;
}

require_once LL_TOOLS_BASE_PATH . 'includes/bootstrap.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;
$ll_tools_update_checker = null;
if (ll_tools_should_boot_update_checker()) {
    $ll_tools_update_branch = ll_tools_get_update_branch();
    $ll_tools_update_checker = PucFactory::buildUpdateChecker(
        'https://github.com/stronganchor/language-learner-tools',
        __FILE__,
        'language-learner-tools'
    );
    $ll_tools_update_checker->setBranch($ll_tools_update_branch);
}

add_action('update_option_ll_update_branch', function ($old_value, $value, $option_name) {
    global $ll_tools_update_checker;
    if (empty($ll_tools_update_checker)) {
        return;
    }
    $branch = ll_tools_normalize_update_branch($value);
    $ll_tools_update_checker->setBranch($branch);
    $ll_tools_update_checker->resetUpdateState();
    $ll_tools_update_checker->checkForUpdates();
}, 10, 3);

/**
 * Determine whether the current user should see plugin update UI.
 */
function ll_tools_user_can_manage_plugin_updates() {
    if (!is_user_logged_in()) {
        return false;
    }

    if (!current_user_can('update_plugins')) {
        return false;
    }

    return current_user_can('view_ll_tools')
        || current_user_can('manage_options')
        || current_user_can('manage_network_plugins');
}

/**
 * Returns plugin update status for this plugin from WordPress/PUC caches.
 *
 * @return array{
 *   status:string,
 *   version:string,
 *   raw:object|null
 * }
 */
function ll_tools_get_plugin_update_status_details() {
    static $has_checked = false;
    static $cached_result = null;

    if ($has_checked) {
        return $cached_result;
    }
    $has_checked = true;

    $plugin_file = plugin_basename(LL_TOOLS_MAIN_FILE);
    $raw_update = null;
    $known_no_update = false;
    $known_checked = false;

    $updates = get_site_transient('update_plugins');
    if (is_object($updates)) {
        if (isset($updates->response)) {
            $response = is_array($updates->response) ? $updates->response : (array) $updates->response;
            if (!empty($response[$plugin_file]) && is_object($response[$plugin_file])) {
                $raw_update = $response[$plugin_file];
            }
        }

        if ($raw_update === null && isset($updates->no_update)) {
            $no_update = is_array($updates->no_update) ? $updates->no_update : (array) $updates->no_update;
            if (array_key_exists($plugin_file, $no_update)) {
                $known_no_update = true;
            }
        }

        if ($raw_update === null && isset($updates->checked)) {
            $checked = is_array($updates->checked) ? $updates->checked : (array) $updates->checked;
            if (array_key_exists($plugin_file, $checked)) {
                $known_checked = true;
            }
        }
    }

    if ($raw_update === null) {
        global $ll_tools_update_checker;
        if (is_object($ll_tools_update_checker) && method_exists($ll_tools_update_checker, 'getUpdate')) {
            $puc_update = $ll_tools_update_checker->getUpdate();
            if (is_object($puc_update)) {
                $raw_update = $puc_update;
            }
        }
    }

    $version = '';
    if (is_object($raw_update)) {
        if (!empty($raw_update->new_version) && is_string($raw_update->new_version)) {
            $version = trim((string) $raw_update->new_version);
        } elseif (!empty($raw_update->version) && is_string($raw_update->version)) {
            $version = trim((string) $raw_update->version);
        }
    }

    if ($version !== '') {
        $cached_result = [
            'status' => 'available',
            'version' => $version,
            'raw' => $raw_update,
        ];
        return $cached_result;
    }

    if ($known_no_update || $known_checked) {
        $cached_result = [
            'status' => 'none',
            'version' => '',
            'raw' => null,
        ];
        return $cached_result;
    }

    $cached_result = [
        'status' => 'unknown',
        'version' => '',
        'raw' => null,
    ];

    return $cached_result;
}

/**
 * Returns available update info for this plugin from WordPress/PUC caches.
 *
 * @return array|null {
 *   @type string $version Available version string.
 *   @type object $raw     Raw update object from WP/PUC.
 * }
 */
function ll_tools_get_available_plugin_update_details() {
    $status = ll_tools_get_plugin_update_status_details();
    if (!is_array($status) || ($status['status'] ?? '') !== 'available') {
        return null;
    }

    return [
        'version' => (string) ($status['version'] ?? ''),
        'raw' => isset($status['raw']) && is_object($status['raw']) ? $status['raw'] : null,
    ];
}

/**
 * Build the authenticated update URL for this plugin.
 */
function ll_tools_get_plugin_update_action_url() {
    if (!ll_tools_user_can_manage_plugin_updates()) {
        return '';
    }

    $plugin_file = plugin_basename(LL_TOOLS_MAIN_FILE);
    $url = self_admin_url('update.php?action=upgrade-plugin&plugin=' . rawurlencode($plugin_file));

    return wp_nonce_url($url, 'upgrade-plugin_' . $plugin_file);
}

/**
 * Build the authenticated manual "check for updates" URL for this plugin.
 */
function ll_tools_get_plugin_update_check_action_url($redirect_to = '') {
    if (!ll_tools_user_can_manage_plugin_updates()) {
        return '';
    }

    $url = add_query_arg(
        [
            'action' => 'll_tools_check_plugin_update',
        ],
        admin_url('admin-post.php')
    );

    if (is_string($redirect_to) && $redirect_to !== '') {
        $url = add_query_arg('redirect_to', $redirect_to, $url);
    }

    return wp_nonce_url($url, 'll_tools_check_plugin_update');
}

/**
 * Build a per-user transient key for one-time update-check UI feedback.
 */
function ll_tools_plugin_update_check_flash_key($user_id = 0) {
    $user_id = (int) $user_id;
    if ($user_id <= 0) {
        $user_id = get_current_user_id();
    }
    if ($user_id <= 0) {
        return '';
    }

    $blog_id = function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 0;
    return 'll_tools_upd_ck_flash_' . $blog_id . '_' . $user_id;
}

/**
 * Store one-time plugin update check UI feedback for the current admin user.
 */
function ll_tools_set_plugin_update_check_flash($status) {
    $key = ll_tools_plugin_update_check_flash_key();
    if ($key === '') {
        return;
    }

    $status = is_string($status) ? trim($status) : '';
    if ($status === '') {
        delete_transient($key);
        return;
    }

    set_transient($key, $status, 2 * MINUTE_IN_SECONDS);
}

/**
 * Consume one-time plugin update check UI feedback for the current user.
 */
function ll_tools_consume_plugin_update_check_flash() {
    $key = ll_tools_plugin_update_check_flash_key();
    if ($key === '') {
        return '';
    }

    $value = get_transient($key);
    delete_transient($key);

    return is_string($value) ? trim($value) : '';
}

/**
 * Manual update check endpoint for admins clicking from the wordset page.
 */
function ll_tools_handle_manual_plugin_update_check() {
    if (!ll_tools_user_can_manage_plugin_updates()) {
        wp_die(esc_html__('You are not allowed to check plugin updates.', 'll-tools-text-domain'), 403);
    }

    check_admin_referer('ll_tools_check_plugin_update');

    $redirect_to = '';
    if (isset($_GET['redirect_to'])) {
        $redirect_to = wp_unslash((string) $_GET['redirect_to']);
    }
    $redirect_to = wp_validate_redirect($redirect_to, self_admin_url('plugins.php'));

    global $ll_tools_update_checker;
    if (is_object($ll_tools_update_checker)) {
        if (method_exists($ll_tools_update_checker, 'resetUpdateState')) {
            $ll_tools_update_checker->resetUpdateState();
        }
    }

    delete_site_transient('update_plugins');
    if (!function_exists('wp_update_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/update.php';
    }
    if (function_exists('wp_update_plugins')) {
        wp_update_plugins();
    } elseif (is_object($ll_tools_update_checker) && method_exists($ll_tools_update_checker, 'checkForUpdates')) {
        $ll_tools_update_checker->checkForUpdates();
    }

    ll_tools_set_plugin_update_check_flash('');
    $plugin_file = plugin_basename(LL_TOOLS_MAIN_FILE);
    $updates = get_site_transient('update_plugins');
    if (is_object($updates)) {
        $response = isset($updates->response) ? (is_array($updates->response) ? $updates->response : (array) $updates->response) : [];
        $no_update = isset($updates->no_update) ? (is_array($updates->no_update) ? $updates->no_update : (array) $updates->no_update) : [];
        if (empty($response[$plugin_file]) && array_key_exists($plugin_file, $no_update)) {
            ll_tools_set_plugin_update_check_flash('up_to_date');
        }
    }

    wp_safe_redirect($redirect_to);
    exit;
}
add_action('admin_post_ll_tools_check_plugin_update', 'll_tools_handle_manual_plugin_update_check');

/**
 * Dashboard notice for plugin updates (when detected by WordPress/PUC).
 */
function ll_tools_maybe_render_plugin_update_dashboard_notice() {
    static $rendered = false;
    if ($rendered) {
        return;
    }

    if (!ll_tools_user_can_manage_plugin_updates()) {
        return;
    }

    if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
        return;
    }

    if (!function_exists('get_current_screen')) {
        return;
    }

    $screen = get_current_screen();
    if (!$screen || !in_array($screen->base, ['dashboard', 'dashboard-network'], true)) {
        return;
    }

    $update = ll_tools_get_available_plugin_update_details();
    if (!is_array($update) || empty($update['version'])) {
        return;
    }

    $rendered = true;
    $version = (string) $update['version'];
    $update_url = ll_tools_get_plugin_update_action_url();
    if ($update_url === '') {
        return;
    }
    ?>
    <div class="notice notice-warning">
        <p>
            <?php
            echo esc_html(
                sprintf(
                    __('Language Learner Tools update available: version %s.', 'll-tools-text-domain'),
                    $version
                )
            );
            ?>
            <a class="button button-primary" href="<?php echo esc_url($update_url); ?>">
                <?php
                echo esc_html(
                    sprintf(
                        __('Update to %s', 'll-tools-text-domain'),
                        $version
                    )
                );
                ?>
            </a>
        </p>
    </div>
    <?php
}
add_action('admin_notices', 'll_tools_maybe_render_plugin_update_dashboard_notice');
add_action('network_admin_notices', 'll_tools_maybe_render_plugin_update_dashboard_notice');

add_filter('upgrader_source_selection', function ($source, $remote_source, $upgrader) {
    global $wp_filesystem;
    if (!isset($source, $remote_source, $upgrader, $upgrader->skin, $wp_filesystem)) {
        return $source;
    }

    $plugin_basename = plugin_basename(__FILE__);
    $maybe_plugin = '';
    if (!empty($upgrader->skin->plugin)) {
        $maybe_plugin = $upgrader->skin->plugin;
    } elseif (!empty($upgrader->skin->plugin_info['Name'])) {
        // Handle manual uploads where plugin basename isn't set.
        $plugin_name = $upgrader->skin->plugin_info['Name'];
        $plugin_domain = !empty($upgrader->skin->plugin_info['TextDomain']) ? $upgrader->skin->plugin_info['TextDomain'] : '';
        if ($plugin_name === 'Language Learner Tools' || $plugin_domain === 'll-tools-text-domain') {
            $maybe_plugin = $plugin_basename;
        }
    } elseif (is_string($source) && strpos(basename($source), 'language-learner-tools') !== false) {
        // Fallback for cases where the installer can't determine plugin headers yet.
        $maybe_plugin = $plugin_basename;
    }

    // Only adjust the directory for this plugin.
    if ($maybe_plugin !== $plugin_basename) {
        return $source;
    }

    $corrected_source = trailingslashit($remote_source) . 'language-learner-tools/';
    if ($source === $corrected_source) {
        return $source;
    }

    if ($wp_filesystem->move($source, $corrected_source, true)) {
        return $corrected_source;
    }

    // Fall back to the original source if rename fails to avoid aborting the upgrade.
    return $source;
}, 9, 3);

add_action('upgrader_process_complete', function ($upgrader, $options) {
    if (empty($options['action']) || empty($options['type'])) {
        return;
    }
    if ($options['action'] !== 'update' || $options['type'] !== 'plugin') {
        return;
    }

    $plugins = [];
    if (!empty($options['plugins'])) {
        $plugins = (array) $options['plugins'];
    } elseif (!empty($options['plugin'])) {
        $plugins = [(string) $options['plugin']];
    }

    if (empty($plugins)) {
        return;
    }

    if (in_array(plugin_basename(LL_TOOLS_MAIN_FILE), $plugins, true)) {
        set_transient('ll_tools_seed_default_wordset', 1, 10 * MINUTE_IN_SECONDS);
    }
}, 10, 2);

// Actions to take on plugin activation
register_activation_hook(__FILE__, function () {
    // Add 'view_ll_tools' capability to administrator role
    $role = get_role('administrator');
    if ($role && !$role->has_cap('view_ll_tools')) {
        $role->add_cap('view_ll_tools');
    }
    // Flag post-activation tasks to run on the next init (after taxonomies are available).
    set_transient('ll_tools_seed_default_wordset', 1, 10 * MINUTE_IN_SECONDS);
    set_transient('ll_tools_create_recording_page', 1, 10 * MINUTE_IN_SECONDS);
    // Safeguard to skip quiz page sync until seeding completes
    set_transient('ll_tools_skip_sync_until_seeded', 1, 10 * MINUTE_IN_SECONDS);

    if (function_exists('ll_tools_install_user_progress_schema')) {
        ll_tools_install_user_progress_schema();
    }
});

// Ensure this runs after CPTs/taxonomies are included (bootstrap requires them early).
add_action('init', 'll_tools_maybe_seed_default_wordset_and_assign', 20);

add_action('admin_init', function () {
    if (current_user_can('manage_options')) {
        $role = get_role('administrator');
        if ($role && !$role->has_cap('view_ll_tools')) {
            $role->add_cap('view_ll_tools');
        }
    }
});

/**
 * Removes 'view_ll_tools' capability on deactivation
 */
register_deactivation_hook(__FILE__, function () {
    $role = get_role('administrator');
    if ($role) {
        $role->remove_cap('view_ll_tools');
    }
});

/**
 * Loads the plugin text domain for internationalization.
 */
function ll_tools_load_textdomain() {
    load_plugin_textdomain('ll-tools-text-domain', false, basename(dirname(__FILE__)) . '/languages');
}
add_action('plugins_loaded', 'll_tools_load_textdomain');

/* Embed Flashcard Pages - create embeddable quiz pages for each category */
function ll_embed_rewrite_rule() {
    add_rewrite_rule('^embed/([^/]+)/?$', 'index.php?embed_category=$matches[1]', 'top');
}
add_action('init', 'll_embed_rewrite_rule');

function ll_embed_query_vars($vars) {
    $vars[] = 'embed_category';
    return $vars;
}
add_filter('query_vars', 'll_embed_query_vars');

function ll_embed_template_include($template) {
    if (get_query_var('embed_category')) {
        return LL_TOOLS_BASE_PATH . 'includes/pages/embed-page.php';
    }
    return $template;
}
add_filter('template_include', 'll_embed_template_include');

/**
 * Hide the admin bar on embed quiz pages for all users/roles.
 *
 * @param bool $show Whether to show the admin bar.
 * @return bool
 */
function ll_hide_admin_bar_on_embed_pages($show) {
    if (get_query_var('embed_category')) {
        return false;
    }

    return $show;
}
add_filter('show_admin_bar', 'll_hide_admin_bar_on_embed_pages', 1000);

/**
 * Add a "Settings" link on the Plugins screen row for this plugin.
 */
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    if (is_network_admin()) {
        return $links;
    }

    $url = admin_url('admin.php?page=' . LL_TOOLS_SETTINGS_SLUG);
    $settings_link = '<a href="' . esc_url($url) . '">' . esc_html__('Settings', 'll-tools-text-domain') . '</a>';

    array_unshift($links, $settings_link);
    return $links;
});

?>
