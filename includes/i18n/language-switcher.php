<?php
if (!defined('WPINC')) { die; }

/**
 * Only auto-detect on first visit, never in admin/AJAX/REST,
 * and never if a language was already chosen (our cookie or TP).
 * If TranslatePress is active, redirect to its proper language URL.
 */
function ll_tools_maybe_autodetect_language() {
    // Frontend only; do not interfere with editors/REST/AJAX.
    if (
        is_admin() ||
        (defined('DOING_AJAX') && DOING_AJAX) ||
        (defined('REST_REQUEST') && REST_REQUEST) ||
        (function_exists('wp_is_json_request') && wp_is_json_request())
    ) {
        return;
    }

    // Respect an explicit manual choice or an existing preference.
    if (!empty($_GET['ll_locale'])) return; // manual switch in progress
    if (!empty($_COOKIE['ll_locale'])) return; // our cookie already set
    if (!empty($_COOKIE['trp_language'])) return; // TranslatePress cookie set

    // Basic Accept-Language → WP locale mapping.
    $http = isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? $_SERVER['HTTP_ACCEPT_LANGUAGE'] : '';
    if (!$http) return;

    $lang2 = strtolower(substr($http, 0, 2));
    $wp_locale = '';
    if ($lang2 === 'tr') $wp_locale = 'tr_TR';
    elseif ($lang2 === 'en') $wp_locale = 'en_US';
    if (!$wp_locale) return;

    // If TranslatePress is active, redirect to its language URL.
    if (class_exists('TRP_Translate_Press')) {
        $current_url = home_url(add_query_arg([]));
        $trp         = \TRP_Translate_Press::get_trp_instance();
        $url_conv    = $trp->get_component('url_converter');
        $target      = $url_conv->get_url_for_language($wp_locale, $current_url, '');
        if ($target && is_string($target)) {
            nocache_headers();
            wp_safe_redirect($target, 302);
            exit;
        }
        return;
    }

    // No TP → persist our cookie for the frontend.
    $expire      = time() + YEAR_IN_SECONDS;
    $cookie_path = defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/';
    setcookie('ll_locale', $wp_locale, $expire, $cookie_path, COOKIE_DOMAIN, is_ssl(), true);
}
add_action('template_redirect', 'll_tools_maybe_autodetect_language', 1);
