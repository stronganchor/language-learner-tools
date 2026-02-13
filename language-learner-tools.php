<?php
/*
Plugin Name: Language Learner Tools
Plugin URI: https://github.com/stronganchor/language-learner-tools
Description: A toolkit for building vocabulary-driven language sites in WordPress: custom post types (“Words”, “Word Images”), taxonomies (Word Category, Word Set, Language, Part of Speech), flashcard quizzes with audio & images via [flashcard_widget], auto-generated quiz pages (/quiz/<category>) and embeddable pages (/embed/<category>), vocabulary grids, audio players, bulk uploaders (audio/images), DeepL-assisted translations, template overrides, and lightweight roles (“Word Set Manager”, “LL Tools Editor”).
Version: 5.1.6
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

require_once LL_TOOLS_BASE_PATH . 'includes/bootstrap.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;
$ll_tools_update_branch = ll_tools_get_update_branch();
$ll_tools_update_checker = PucFactory::buildUpdateChecker(
    'https://github.com/stronganchor/language-learner-tools',
    __FILE__,
    'language-learner-tools'
);
$ll_tools_update_checker->setBranch($ll_tools_update_branch);

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
