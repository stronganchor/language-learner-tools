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

function ll_tools_enqueue_public_assets() {
    ll_enqueue_asset_by_timestamp('/css/language-learner-tools.css', 'll-tools-style');
    // jQuery UI if needed on front-end
    wp_enqueue_script('jquery-ui-autocomplete');
    wp_enqueue_style('jquery-ui-css','https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css');
    // Confetti via proper enqueue (no inline <script> in head)
    wp_enqueue_script('ll-confetti','https://cdn.jsdelivr.net/npm/canvas-confetti@1.5.1/dist/confetti.browser.min.js',[],null,true);
}
add_action('wp_enqueue_scripts', 'll_tools_enqueue_public_assets');

function ll_tools_enqueue_non_admin_assets($hook = '') {
    if (current_user_can('manage_options') || current_user_can('view_ll_tools')) return;
    ll_enqueue_asset_by_timestamp('/css/non-admin-style.css', 'll-tools-nonadmin-style');
}
add_action('admin_enqueue_scripts', 'll_tools_enqueue_non_admin_assets');

// /includes/assets.php  (or a small new helper file)
function ll_tools_preload_quiz_icons() {
    if ( ! is_admin() ) {
        // Use LL_TOOLS_BASE_URL constant for absolute paths
        echo '<link rel="preload" as="image" type="image/svg+xml" href="'. esc_url( LL_TOOLS_BASE_URL . 'media/play-symbol.svg' ) .'" />' . "\n";
        echo '<link rel="preload" as="image" type="image/svg+xml" href="'. esc_url( LL_TOOLS_BASE_URL . 'media/stop-symbol.svg' ) .'" />' . "\n";
    }
}
add_action( 'wp_head', 'll_tools_preload_quiz_icons', 5 );
