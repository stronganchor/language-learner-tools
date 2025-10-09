<?php
if (!defined('WPINC')) { die; }

/**
 * Single source of truth:
 * - If TranslatePress is active: never change WP locale; only redirect
 *   users to the TP URL that encodes the language.
 * - If TP is NOT active: let our cookie drive WP locale (via 'locale' filter).
 */

// Safety: disable any old switcher that was hooked on 'init'.
function ll_tools_disable_legacy_init_switcher() {
    // Old code used: add_action('init', 'switch_language');
    remove_action('init', 'switch_language');
}
add_action('plugins_loaded', 'll_tools_disable_legacy_init_switcher', 1);

if (!defined('LL_TOOLS_I18N_COOKIE')) {
    define('LL_TOOLS_I18N_COOKIE', 'll_locale');
}

/**
 * Auto-detect only on first visit.
 * With TP: redirect to TP’s canonical language URL
 * Without TP: set our cookie (used by the locale filter below)
 */
function ll_tools_maybe_autodetect_language() {
    if (is_admin()
        || (defined('DOING_AJAX') && DOING_AJAX)
        || (defined('REST_REQUEST') && REST_REQUEST)
        || (function_exists('wp_is_json_request') && wp_is_json_request())
    ) {
        return;
    }

    // Respect existing preferences (TP cookie, our cookie, or an explicit switch param)
    if (!empty($_COOKIE['trp_language']) || !empty($_COOKIE[LL_TOOLS_I18N_COOKIE]) || isset($_GET['ll_locale'])) {
        return;
    }

    $http = isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? $_SERVER['HTTP_ACCEPT_LANGUAGE'] : '';
    if (!$http) { return; }
    $lang2 = strtolower(substr($http, 0, 2));

    if (class_exists('TRP_Translate_Press')) {
        // Map 'tr'/'en' to a TP language_code like 'tr_TR', 'en_US' using TP’s API
        if (!function_exists('trp_custom_language_switcher')) { return; }
        $ls = trp_custom_language_switcher();
        if (empty($ls['languages'])) { return; }

        $target_code = '';
        foreach ($ls['languages'] as $row) {
            $code = isset($row['language_code']) ? strtolower($row['language_code']) : '';
            if ($code && strpos($code, $lang2) === 0) { $target_code = $row['language_code']; break; }
        }
        if (!$target_code && !empty($ls['current_language'])) {
            $target_code = $ls['current_language']; // fallback
        }
        if (!$target_code) { return; }

        $trp      = \TRP_Translate_Press::get_trp_instance();
        $url_conv = $trp->get_component('url_converter');
        $current  = home_url(add_query_arg([]));
        $target   = $url_conv->get_url_for_language($target_code, $current, '');
        if ($target && is_string($target) && $target !== $current) {
            nocache_headers();
            wp_safe_redirect($target, 302);
            exit;
        }
        return;
    }

    // No TP → set our cookie; 'locale' filter below will use it
    $map = ['tr' => 'tr_TR', 'en' => 'en_US'];
    if (!isset($map[$lang2])) { return; }
    $wp_locale = $map[$lang2];

    $expire      = time() + YEAR_IN_SECONDS;
    $cookie_path = defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/';
    setcookie(LL_TOOLS_I18N_COOKIE, $wp_locale, $expire, $cookie_path, COOKIE_DOMAIN, is_ssl(), true);
}
add_action('template_redirect', 'll_tools_maybe_autodetect_language', 1);

/**
 * When TP is not active, let our cookie decide WP's locale.
 * When TP is active, do nothing — TP stays the authority (and we clear our stale cookie).
 */
function ll_tools_filter_locale_if_no_tp($locale) {
    if (class_exists('TRP_Translate_Press')) {
        if (!headers_sent() && !empty($_COOKIE[LL_TOOLS_I18N_COOKIE])) {
            $cookie_path = defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/';
            setcookie(LL_TOOLS_I18N_COOKIE, '', time() - 3600, $cookie_path, COOKIE_DOMAIN, is_ssl(), true);
        }
        return $locale;
    }

    if (empty($_COOKIE[LL_TOOLS_I18N_COOKIE])) { return $locale; }
    $chosen = sanitize_text_field(wp_unslash($_COOKIE[LL_TOOLS_I18N_COOKIE]));
    if (preg_match('/^[a-z]{2,3}(?:_[A-Z]{2})?$/', $chosen)) {
        return $chosen;
    }
    return $locale;
}
add_filter('locale', 'll_tools_filter_locale_if_no_tp', 9);
