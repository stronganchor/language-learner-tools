<?php
if (!defined('WPINC')) { die; }

function ll_enqueue_asset_by_timestamp($relative_path, $handle, $deps = [], $in_footer = false) {
    static $version_cache = [];

    $file = LL_TOOLS_BASE_PATH . ltrim($relative_path, '/');
    if (!is_readable($file)) return;
    if (!isset($version_cache[$file])) {
        $version_cache[$file] = (string) filemtime($file);
    }
    $ver  = $version_cache[$file];
    $url  = plugins_url(ltrim($relative_path, '/'), LL_TOOLS_MAIN_FILE);
    $ext  = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if ($ext === 'js')  wp_enqueue_script($handle, $url, $deps, $ver, $in_footer);
    if ($ext === 'css') wp_enqueue_style($handle, $url, $deps, $ver);
}

function ll_tools_get_custom_font_stylesheet_url(): string {
    $url = trim((string) get_option('ll_quiz_font_url', ''));
    if ($url === '') {
        return '';
    }
    $url = esc_url_raw($url);
    if ($url === '') {
        return '';
    }
    $scheme = wp_parse_url($url, PHP_URL_SCHEME);
    if (!in_array(strtolower((string) $scheme), ['http', 'https'], true)) {
        return '';
    }
    return $url;
}

function ll_tools_enqueue_custom_font_stylesheet(): void {
    static $enqueued = false;
    if ($enqueued) {
        return;
    }

    $font_url = ll_tools_get_custom_font_stylesheet_url();
    if ($font_url === '') {
        return;
    }

    $enqueued = true;
    wp_enqueue_style('ll-tools-custom-font-stylesheet', $font_url, [], null);
}

/**
 * Shared front-end base styles used across LL Tools pages/shortcodes.
 *
 * Feature-specific libraries (autocomplete/confetti) are enqueued on demand by
 * the features that use them.
 */
function ll_tools_enqueue_public_assets() {
    static $enqueued = false;
    if ($enqueued) {
        return;
    }
    $enqueued = true;

    ll_tools_enqueue_custom_font_stylesheet();
    ll_enqueue_asset_by_timestamp('/css/ipa-fonts.css', 'll-ipa-fonts');
    ll_enqueue_asset_by_timestamp('/css/language-learner-tools.css', 'll-tools-style', ['ll-ipa-fonts']);
}

/**
 * Allow feature code to request the shared public LL Tools styles.
 *
 * This is primarily useful for custom integrations/themes that know they will
 * render LL UI outside of normal shortcode/page contexts.
 */
function ll_tools_mark_public_assets_needed(): void {
    $GLOBALS['ll_tools_public_assets_needed'] = true;
}

function ll_tools_public_assets_marked(): bool {
    return !empty($GLOBALS['ll_tools_public_assets_needed']);
}

/**
 * Detect LL shortcodes in post content.
 */
function ll_tools_post_content_has_public_assets_shortcodes($post): bool {
    if (!($post instanceof WP_Post)) {
        return false;
    }

    $content = (string) ($post->post_content ?? '');
    if ($content === '' || strpos($content, '[') === false) {
        return false;
    }

    static $request_cache = [];
    $cache_key = ((int) $post->ID) . ':' . md5($content);
    if (array_key_exists($cache_key, $request_cache)) {
        return (bool) $request_cache[$cache_key];
    }

    $shortcodes = apply_filters('ll_tools_public_assets_shortcode_tags', [
        'flashcard_widget',
        'quiz_pages_grid',
        'quiz_pages_dropdown',
        'word_grid',
        'word_audio',
        'wordset_page',
        'll_wordset_page',
        'audio_recording_interface',
        'editor_hub',
        'user_study_dashboard',
        'image_copyright_grid',
        'audio_upload_form',
        'image_upload_form',
        'language_switcher',
    ]);
    $shortcodes = array_values(array_unique(array_filter(array_map('strval', (array) $shortcodes))));

    $has_match = false;
    foreach ($shortcodes as $tag) {
        if ($tag === '') {
            continue;
        }
        if (has_shortcode($content, $tag)) {
            $has_match = true;
            break;
        }
    }

    $request_cache[$cache_key] = $has_match;
    return $has_match;
}

/**
 * Decide whether the current front-end request needs shared LL Tools styles.
 */
function ll_tools_request_needs_public_assets(): bool {
    if (is_admin()) {
        return false;
    }

    if ((bool) apply_filters('ll_tools_enqueue_public_assets_globally', false)) {
        return true;
    }

    if (ll_tools_public_assets_marked()) {
        return true;
    }

    if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
        return false;
    }

    if ((function_exists('is_feed') && is_feed())
        || (function_exists('is_robots') && is_robots())
        || (function_exists('is_trackback') && is_trackback())
    ) {
        return false;
    }

    if ((string) get_query_var('embed_category') !== '') {
        return true;
    }

    if (function_exists('ll_qp_is_quiz_page_context') && ll_qp_is_quiz_page_context()) {
        return true;
    }

    if (function_exists('ll_tools_is_wordset_page_context') && ll_tools_is_wordset_page_context()) {
        return true;
    }

    if (is_singular('ll_vocab_lesson')) {
        return true;
    }

    if (is_singular()) {
        $post = get_post();
        if ($post instanceof WP_Post && ll_tools_post_content_has_public_assets_shortcodes($post)) {
            return true;
        }
    }

    global $wp_query;
    if (isset($wp_query) && is_object($wp_query) && !empty($wp_query->posts) && is_array($wp_query->posts)) {
        $checked = 0;
        foreach ($wp_query->posts as $post) {
            if (!($post instanceof WP_Post)) {
                continue;
            }
            if (ll_tools_post_content_has_public_assets_shortcodes($post)) {
                return true;
            }
            $checked++;
            if ($checked >= 20) {
                break;
            }
        }
    }

    return false;
}

function ll_tools_maybe_enqueue_public_assets() {
    if (!ll_tools_request_needs_public_assets()) {
        return;
    }

    ll_tools_enqueue_public_assets();
}
add_action('wp_enqueue_scripts', 'll_tools_maybe_enqueue_public_assets', 100);

function ll_tools_maybe_enqueue_admin_custom_font_stylesheet($hook = ''): void {
    if (!is_admin()) {
        return;
    }

    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    $screen_base = $screen && !empty($screen->base) ? (string) $screen->base : '';
    $screen_taxonomy = $screen && !empty($screen->taxonomy) ? (string) $screen->taxonomy : '';

    $settings_page_slug = function_exists('ll_tools_get_admin_settings_page_slug')
        ? (string) ll_tools_get_admin_settings_page_slug()
        : (defined('LL_TOOLS_SETTINGS_SLUG') ? (string) LL_TOOLS_SETTINGS_SLUG : 'language-learning-tools-settings');

    $is_ll_settings_page = ($hook === ('settings_page_' . $settings_page_slug));
    $is_wordset_taxonomy_screen = ($screen_base === 'edit-tags' && $screen_taxonomy === 'wordset');

    if (!$is_ll_settings_page && !$is_wordset_taxonomy_screen) {
        return;
    }

    ll_tools_enqueue_custom_font_stylesheet();
}
add_action('admin_enqueue_scripts', 'll_tools_maybe_enqueue_admin_custom_font_stylesheet', 20);

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
