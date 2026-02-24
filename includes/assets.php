<?php
if (!defined('WPINC')) { die; }

function ll_enqueue_asset_by_timestamp($relative_path, $handle, $deps = [], $in_footer = false) {
    $file = LL_TOOLS_BASE_PATH . ltrim($relative_path, '/');
    if (!is_readable($file)) return;
    $ver  = (string) filemtime($file);
    $url  = plugins_url(ltrim($relative_path, '/'), LL_TOOLS_MAIN_FILE);
    $ext  = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if ($ext === 'js')  wp_enqueue_script($handle, $url, $deps, $ver, $in_footer);
    if ($ext === 'css') wp_enqueue_style($handle, $url, $deps, $ver);
}

/**
 * Shared front-end base styles used across LL Tools pages/shortcodes.
 *
 * Kept global for compatibility; feature-specific libraries (autocomplete/confetti)
 * are enqueued on demand by the features that use them.
 */
function ll_tools_enqueue_public_assets() {
    ll_enqueue_asset_by_timestamp('/css/ipa-fonts.css', 'll-ipa-fonts');
    ll_enqueue_asset_by_timestamp('/css/language-learner-tools.css', 'll-tools-style', ['ll-ipa-fonts']);
}
add_action('wp_enqueue_scripts', 'll_tools_enqueue_public_assets');

/**
 * Enqueue jQuery UI Autocomplete assets only for screens/features that use it.
 */
function ll_tools_enqueue_jquery_ui_autocomplete_assets() {
    static $enqueued = false;
    if ($enqueued) {
        return;
    }
    $enqueued = true;

    wp_enqueue_script('jquery-ui-autocomplete');
    wp_enqueue_style(
        'jquery-ui-css',
        'https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css',
        [],
        '1.12.1'
    );
}

/**
 * Enqueue canvas-confetti only for experiences that use it.
 */
function ll_tools_enqueue_confetti_asset() {
    static $enqueued = false;
    if ($enqueued) {
        return;
    }
    $enqueued = true;

    wp_enqueue_script(
        'll-confetti',
        'https://cdn.jsdelivr.net/npm/canvas-confetti@1.5.1/dist/confetti.browser.min.js',
        [],
        '1.5.1',
        true
    );
}

function ll_tools_enqueue_non_admin_assets($hook = '') {
    if (current_user_can('manage_options') || current_user_can('view_ll_tools')) return;
    ll_enqueue_asset_by_timestamp('/css/non-admin-style.css', 'll-tools-nonadmin-style');
}
add_action('admin_enqueue_scripts', 'll_tools_enqueue_non_admin_assets');
