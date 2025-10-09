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
 * Automatically redirects to the appropriate TranslatePress language URL
 * based on the user's browser language settings (one-time redirect).
 */
function ll_tools_maybe_redirect_to_preferred_language() {
    // Only run on front-end, non-AJAX, first page load
    if (is_admin() ||
        wp_doing_ajax() ||
        wp_doing_cron() ||
        (defined('REST_REQUEST') && REST_REQUEST)) {
        return;
    }

    // Don't run in Elementor editor or preview mode
    if (isset($_GET['elementor-preview']) ||
        isset($_GET['elementor_library']) ||
        (isset($_GET['action']) && $_GET['action'] === 'elementor')) {
        return;
    }

    // Check if TranslatePress is active
    if (!function_exists('trp_enable_translatepress')) {
        return;
    }

    // Check if user already has a language preference cookie (TranslatePress sets this)
    if (isset($_COOKIE['pll_language']) || isset($_COOKIE['trp-form-language'])) {
        return;
    }

    // Check if we're already on a language-specific URL
    $current_path = trim($_SERVER['REQUEST_URI'], '/');
    $path_parts = explode('/', $current_path);
    if (!empty($path_parts[0]) && strlen($path_parts[0]) === 2) {
        // Likely already on a language path like /tr/ or /en/
        return;
    }

    // Get browser language preference
    if (!isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
        return;
    }

    $browser_lang = substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2);

    // Map browser languages to TranslatePress URL slugs
    $language_mapping = array(
        'tr' => 'tr',  // Turkish
        // Add more mappings as needed
        // 'de' => 'de',  // German
        // 'fr' => 'fr',  // French
    );

    // Only redirect if we have a mapping for this language and it's not English
    if (!isset($language_mapping[$browser_lang]) || $browser_lang === 'en') {
        return;
    }

    $lang_slug = $language_mapping[$browser_lang];

    // Get TranslatePress settings to verify the language is actually enabled
    $trp_settings = get_option('trp_settings');
    if (empty($trp_settings['publish-languages']) ||
        !in_array($browser_lang, $trp_settings['publish-languages'])) {
        return;
    }

    // Build the redirect URL
    $redirect_url = home_url('/' . $lang_slug . '/' . ltrim($_SERVER['REQUEST_URI'], '/'));

    // Perform the redirect (302 temporary redirect)
    wp_safe_redirect($redirect_url, 302);
    exit;
}

// Hook to template_redirect (runs after query is set up, but before template loads)
add_action('template_redirect', 'll_tools_maybe_redirect_to_preferred_language');

// Hook the function to an action that runs early in the WordPress initialization process
add_action('init', 'switch_language');
?>