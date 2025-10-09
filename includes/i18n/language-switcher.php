<?php

/**
 * Automatically switches the site language based on the user's browser language settings.
 */
function switch_language() {
    if (!isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
        return;
    }

    // Get the list of available languages in your WordPress site
    $available_languages = get_available_languages(); // Returns an array of installed language codes (e.g., 'en_US')

    // Extract the preferred language from the browser
    $browser_lang = substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2); // This gets the 2-letter language code

    // Convert browser language to WordPress format (e.g., 'en' to 'en_US')
    // This part may require customization based on your specific available languages and default language format
    switch ($browser_lang) {
        case 'en':
            $wp_lang = 'en_US';
            break;
        case 'tr':
            $wp_lang = 'tr_TR';
            break;
        // Add more cases as needed for your site's languages
        default:
            $wp_lang = ''; // Default language set in WordPress settings
            break;
    }

    // Check if the browser language is available in your site and switch
    if (in_array($wp_lang, $available_languages)) {
        switch_to_locale($wp_lang);
    }
}

/**
 * Auto-route visitors to the correct TranslatePress URL (/tr/ vs /),
 * but NEVER touch Elementor editor/preview or other special requests.
 */
add_action('template_redirect', function () {
    // 1) Hard skips: admin, AJAX
    if ( is_admin() || wp_doing_ajax() ) return;

    // 2) Elementor editor / preview / CSS / REST / heartbeat
    //    (Editor loads the front-end with ?elementor-preview=ID)
    $q = $_GET;
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $is_elementor =
        (isset($q['elementor-preview'])) ||                // front-end preview frame
        (isset($q['elementor_library'])) ||                // library routes
        (isset($q['action']) && $q['action'] === 'elementor') ||
        (isset($q['elementor']) || isset($q['elementor_css'])) ||
        (strpos($uri, '/wp-json/elementor/') === 0) ||     // REST endpoints
        (strpos($uri, '/?rest_route=/elementor/') !== false);

    if ( $is_elementor ) return;

    // 3) Other non-HTML or sensitive routes we should never redirect
    if (
        strpos($uri, '/wp-json/') === 0 ||
        strpos($uri, '/wp-cron.php') === 0 ||
        strpos($uri, '/wp-login.php') === 0 ||
        is_feed() ||
        (isset($q['preview']) && $q['preview'] === 'true') ||
        preg_match('~\.(css|js|jpg|jpeg|png|gif|svg|webp|ico|woff2?|ttf|eot|mp3|mp4|wav)$~i', $uri)
    ) {
        return;
    }

    // 4) Decide desired language (swap with your own logic if you have one)
    //    Example: browser pref. Use your plugin's function instead if available.
    $accept   = strtolower($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
    $wants_tr = (strpos($accept, 'tr') === 0 || preg_match('/(^|,)\s*tr\b/', $accept));
    // e.g. if you already have this: $wants_tr = (ll_get_current_language() === 'tr_TR');

    $desired = $wants_tr ? 'tr' : 'en';

    // 5) Are we already on the TR route?
    $on_tr = preg_match('#^/tr(?:/|$)#i', $uri) === 1;

    // 6) Redirect only when URL route and desired language disagree
    if ($desired === 'tr' && !$on_tr) {
        // Ask TP for the current URL in TR if possible
        if (class_exists('TRP_Translate_Press')) {
            $trp = TRP_Translate_Press::get_trp_instance();
            $url_converter = $trp->get_component('url_converter');
            $target = $url_converter->get_url_for_language('tr');
        } else {
            $target = home_url('/tr' . $uri);
        }
        if (!headers_sent()) {
            setcookie('trp_language', 'tr', time()+YEAR_IN_SECONDS, '/', parse_url(home_url(), PHP_URL_HOST) ?: '');
        }
        wp_safe_redirect($target, 302);
        exit;
    }

    if ($desired === 'en' && $on_tr) {
        if (class_exists('TRP_Translate_Press')) {
            $trp = TRP_Translate_Press::get_trp_instance();
            $url_converter = $trp->get_component('url_converter');
            $target = $url_converter->get_url_for_language('en');
        } else {
            $target = home_url(preg_replace('#^/tr#i', '', $uri) ?: '/');
        }
        if (!headers_sent()) {
            setcookie('trp_language', 'en', time()+YEAR_IN_SECONDS, '/', parse_url(home_url(), PHP_URL_HOST) ?: '');
        }
        wp_safe_redirect($target, 302);
        exit;
    }
}, 1);

// Hook the function to an action that runs early in the WordPress initialization process
add_action('init', 'switch_language');
?>