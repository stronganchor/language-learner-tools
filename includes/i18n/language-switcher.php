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
 * Auto-route users to the TranslatePress URL that matches their language.
 * Works regardless of TP's internal state by checking the actual URL path.
 */
add_action('template_redirect', function () {
    // Only front-end HTML requests
    if ( is_admin() || wp_doing_ajax() ) return;

    $uri = $_SERVER['REQUEST_URI'] ?? '/';

    // Skip REST, cron, feeds, previews, file requests, and login
    if (
        strpos($uri, '/wp-json/') === 0 ||
        strpos($uri, '/wp-cron.php') === 0 ||
        is_feed() ||
        (isset($_GET['preview']) && $_GET['preview']=='true') ||
        preg_match('~\.(css|js|jpg|jpeg|png|gif|svg|webp|ico|woff2?|ttf|eot|mp3|mp4|wav)$~i', $uri) ||
        strpos($uri, '/wp-login.php') === 0
    ) {
        return;
    }

    // ----- Decide the desired language (swap this with your own logic if you have it) -----
    // Basic device/browser detection:
    $accept = strtolower($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
    $wants_tr = (strpos($accept, 'tr') === 0 || preg_match('/(^|,)\s*tr\b/', $accept));

    // If YOUR plugin has a function/setting, prefer it:
    // $wants_tr = function_exists('ll_user_wants_turkish') ? ll_user_wants_turkish() : $wants_tr;

    $desired = $wants_tr ? 'tr' : 'en';

    // Is current URL already Turkish?
    $on_tr = preg_match('#^/tr(?:/|$)#i', $uri) === 1;

    // If desired is TR but URL isn’t /tr/, redirect to the TR version of THIS page.
    if ($desired === 'tr' && !$on_tr) {
        if (class_exists('TRP_Translate_Press')) {
            $trp = TRP_Translate_Press::get_trp_instance();
            $url_converter = $trp->get_component('url_converter');
            $target = $url_converter->get_url_for_language('tr'); // current URL in TR
        } else {
            // Fallback: prefix /tr/ manually
            $target = home_url('/tr' . $uri);
        }

        // Set TP cookie and redirect
        if (!headers_sent()) {
            setcookie('trp_language', 'tr', time()+YEAR_IN_SECONDS, '/', parse_url(home_url(), PHP_URL_HOST) ?: '');
        }
        wp_safe_redirect($target, 302);
        exit;
    }

    // If desired is EN but URL is /tr/..., send to EN version (no /tr/ prefix)
    if ($desired === 'en' && $on_tr) {
        if (class_exists('TRP_Translate_Press')) {
            $trp = TRP_Translate_Press::get_trp_instance();
            $url_converter = $trp->get_component('url_converter');
            $target = $url_converter->get_url_for_language('en'); // current URL in EN
        } else {
            // Fallback: strip the /tr prefix
            $target = home_url( preg_replace('#^/tr#i', '', $uri) ?: '/' );
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