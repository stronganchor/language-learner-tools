<?php
/**
 * Plugin Name: Language Learner Tools
 * Plugin URI: https://stronganchortech.com
 * Description: Adds custom display features for vocab items in the 'words' custom post type.
 * Author: Strong Anchor Tech
 * Version: 3.0.0
 * Text Domain: ll-tools-text-domain
 * Domain Path: /languages
 *
 * This plugin is designed to display vocabulary items in a custom post type called 'words'.
 * It adds the English meaning, an audio file, and associated images to each post.
 *
 * The plugin also includes activities for practicing vocabulary.
 *
 * The plugin uses a combination of PHP, JavaScript (jQuery), and CSS to provide its functionality.
 * It interacts with the WordPress database to store and retrieve vocabulary items, categories, and user progress.
 *
 * Key concepts and techniques used in the plugin:
 * - Custom post types and taxonomies
 * - Shortcodes for displaying vocabulary grids, flashcard quizzes, and audio playback
 * - AJAX for saving and retrieving user progress in the flashcard quiz
 * - Integration with DeepL for automatic translation
 * - Handling for bulk audio and image uploads and generating posts from uploaded files
 * - Custom admin pages and settings for configuring the plugin
 * - Enqueuing custom styles and scripts
 * - Internationalization and localization support
 *
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

define('LL_TOOLS_BASE_URL', plugin_dir_url(__FILE__)); 
define('LL_TOOLS_BASE_PATH', plugin_dir_path(__FILE__));
define('LL_TOOLS_MAIN_FILE', __FILE__);

require_once LL_TOOLS_BASE_PATH . 'includes/bootstrap.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;
$myUpdateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/stronganchor/language-learner-tools',
    __FILE__,
    'language-learner-tools'
);
$myUpdateChecker->setBranch('main');


/**
 * Adds the 'view_ll_tools' capability to the Administrator role on plugin activation.
 */
register_activation_hook(__FILE__, function () {
    $role = get_role('administrator');
    if ($role && !$role->has_cap('view_ll_tools')) {
        $role->add_cap('view_ll_tools');
    }
});

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

const LL_TOOLS_SETTINGS_SLUG = 'language-learning-tools-settings';

/**
 * Add a "Settings" link on the Plugins screen row for this plugin.
 */
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    if (is_network_admin()) {
        return $links;
    }

    $url = admin_url('options-general.php?page=' . LL_TOOLS_SETTINGS_SLUG);
    $settings_link = '<a href="' . esc_url($url) . '">' . esc_html__('Settings', 'll-tools') . '</a>';

    array_unshift($links, $settings_link);
    return $links;
});

?>