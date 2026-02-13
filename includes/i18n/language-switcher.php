<?php
if (!defined('WPINC')) { die; }

/**
 * Optional browser-language fallback for locales.
 *
 * Disabled by default to avoid conflicts with explicit locale selection
 * (for example, cookie/query handling in the language switcher shortcode flow).
 *
 * Enable only when desired:
 * add_filter('ll_tools_enable_browser_language_autoswitch', '__return_true');
 */
function ll_tools_switch_language_from_browser() {
    if (!apply_filters('ll_tools_enable_browser_language_autoswitch', false)) {
        return;
    }

    if (is_admin() || (defined('DOING_AJAX') && DOING_AJAX) || (defined('REST_REQUEST') && REST_REQUEST)) {
        return;
    }

    // Respect explicit user locale choices.
    if (isset($_GET['ll_locale'])) {
        return;
    }
    if (defined('LL_TOOLS_I18N_COOKIE') && !empty($_COOKIE[LL_TOOLS_I18N_COOKIE])) {
        return;
    }

    $accept_language = isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? (string) $_SERVER['HTTP_ACCEPT_LANGUAGE'] : '';
    if ($accept_language === '') {
        return;
    }

    $available_languages = get_available_languages();
    if (empty($available_languages)) {
        return;
    }

    // Use the first language range only; map to known locales.
    $browser_lang = sanitize_key(substr($accept_language, 0, 2));
    if ($browser_lang === '') {
        return;
    }

    $map = [
        'en' => 'en_US',
        'tr' => 'tr_TR',
    ];
    $wp_lang = isset($map[$browser_lang]) ? $map[$browser_lang] : '';
    if ($wp_lang === '') {
        return;
    }

    if (in_array($wp_lang, $available_languages, true)) {
        switch_to_locale($wp_lang);
    }
}
add_action('init', 'll_tools_switch_language_from_browser');
