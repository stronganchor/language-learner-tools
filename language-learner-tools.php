<?php
/*
Plugin Name: Language Learner Tools
Plugin URI: https://github.com/stronganchor/language-learner-tools
Description: A toolkit for building vocabulary-driven language sites in WordPress: custom post types (“Words”, “Word Images”), taxonomies (Word Category, Word Set, Language, Part of Speech), flashcard quizzes with audio & images via [flashcard_widget], auto-generated quiz pages (/quiz/<category>) and embeddable pages (/embed/<category>), vocabulary grids, audio players, bulk uploaders (audio/images), DeepL-assisted translations, template overrides, and lightweight roles (“Word Set Manager”, “LL Tools Editor”).
Version: 3.9.1
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

require_once LL_TOOLS_BASE_PATH . 'includes/bootstrap.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;
$myUpdateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/stronganchor/language-learner-tools',
    __FILE__,
    'language-learner-tools'
);
$myUpdateChecker->setBranch('main');

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
