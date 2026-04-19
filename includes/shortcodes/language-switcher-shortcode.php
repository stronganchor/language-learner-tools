<?php
// File: includes/shortcodes/language-switcher-shortcode.php

if (!defined('ABSPATH')) exit;

define('LL_TOOLS_I18N_COOKIE', 'll_locale');
define('LL_TOOLS_TEXTDOMAIN', 'll-tools-text-domain');

/**
 * Build the locale list for the language switcher.
 *
 * Sources:
 * - LL Tools plugin translation files (.mo/.json)
 * - WordPress installed locales (core language packs)
 * - Site default locale
 * - en_US (built into WordPress and often not present as a language pack)
 */
function ll_tools_get_plugin_locales() {
    static $cache = null;
    if ($cache !== null) return $cache;

    $locales = [];
    $dirs = [
        trailingslashit(WP_LANG_DIR) . 'plugins/',          // wp-content/languages/plugins/
        trailingslashit(LL_TOOLS_BASE_PATH) . 'languages/', // plugin/languages/
    ];
    // Support hyphen/underscore + optional hash + .mo/.json
    $patterns = [
        LL_TOOLS_TEXTDOMAIN . '-*.mo',
        LL_TOOLS_TEXTDOMAIN . '_*.mo',
        LL_TOOLS_TEXTDOMAIN . '-*.json',
        LL_TOOLS_TEXTDOMAIN . '_*.json',
    ];
    $re = '#^' . preg_quote(LL_TOOLS_TEXTDOMAIN, '#') .
          '[-_]([a-z]{2,3}(?:_[A-Z]{2})?)(?:-[A-Za-z0-9_.-]+)?\.(?:mo|json)$#';

    foreach ($dirs as $dir) {
        if (!is_dir($dir)) continue;
        foreach ($patterns as $pat) {
            foreach (glob($dir . $pat) ?: [] as $path) {
                $base = basename($path);
                if (preg_match($re, $base, $m)) {
                    $locales[] = $m[1];
                }
            }
        }
    }

    // Include WordPress-installed locales. Core English (en_US) is built-in and
    // is usually not returned here, so we add it explicitly below.
    if (function_exists('get_available_languages')) {
        foreach (get_available_languages() as $wp_locale) {
            if (is_string($wp_locale) && preg_match('/^[a-z]{2,3}(?:_[A-Z]{2})?$/', $wp_locale)) {
                $locales[] = $wp_locale;
            }
        }
    }

    // Add a non-filtering default without invoking locale hooks:
    // get_option('WPLANG') returns site setting or '' (use en_US as WP default).
    $site_default = get_option('WPLANG');
    $locales[] = $site_default ? $site_default : 'en_US';
    $locales[] = 'en_US';

    $cache = array_values(array_unique($locales));
    return $cache;
}

/**
 * Validate locale strings accepted by the switcher cookie.
 */
function ll_tools_is_valid_switcher_locale($locale) {
    return is_string($locale) && preg_match('/^[a-z]{2,3}(?:_[A-Z]{2})?$/', $locale);
}

/**
 * Persist the front-end locale cookie (best effort).
 */
function ll_tools_set_locale_cookie($locale) {
    if (!ll_tools_is_valid_switcher_locale($locale)) {
        return false;
    }

    if (headers_sent()) {
        return false;
    }

    $expire = time() + YEAR_IN_SECONDS;
    $cookie_path = defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/';

    return setcookie(LL_TOOLS_I18N_COOKIE, $locale, $expire, $cookie_path, COOKIE_DOMAIN, is_ssl(), true);
}

/**
 * Return the current front-end locale cookie value when it is valid.
 */
function ll_tools_get_locale_cookie_preference() {
    if (empty($_COOKIE[LL_TOOLS_I18N_COOKIE])) {
        return '';
    }

    $chosen = sanitize_text_field(wp_unslash((string) $_COOKIE[LL_TOOLS_I18N_COOKIE]));
    return ll_tools_is_valid_switcher_locale($chosen) ? $chosen : '';
}

/**
 * Return the site default locale used when the user has not set a preference.
 */
function ll_tools_get_site_default_locale_preference() {
    $site_default = trim((string) get_option('WPLANG'));
    if ($site_default === '') {
        $site_default = 'en_US';
    }

    return ll_tools_is_valid_switcher_locale($site_default) ? $site_default : 'en_US';
}

/**
 * Read a stored user locale preference without invoking get_locale() recursion.
 */
function ll_tools_get_user_locale_preference($user_id = 0, $fallback_to_site_default = false) {
    $user_id = (int) $user_id;
    if ($user_id <= 0 && function_exists('get_current_user_id')) {
        $user_id = (int) get_current_user_id();
    }
    if ($user_id <= 0) {
        return $fallback_to_site_default ? ll_tools_get_site_default_locale_preference() : '';
    }

    $user_locale = '';
    if (function_exists('get_user_meta')) {
        $user_locale = (string) get_user_meta($user_id, 'locale', true);
    } else {
        $user = get_userdata($user_id);
        if ($user instanceof WP_User && isset($user->locale)) {
            $user_locale = (string) $user->locale;
        }
    }

    if (ll_tools_is_valid_switcher_locale($user_locale)) {
        return $user_locale;
    }

    return $fallback_to_site_default ? ll_tools_get_site_default_locale_preference() : '';
}

/**
 * Persist a locale preference to the cookie and, for logged-in users, their account.
 */
function ll_tools_persist_locale_preference($locale, $user_id = 0) {
    if (!ll_tools_is_valid_switcher_locale($locale)) {
        return false;
    }

    $updated = false;
    if (ll_tools_set_locale_cookie($locale)) {
        $updated = true;
    }
    $_COOKIE[LL_TOOLS_I18N_COOKIE] = $locale;

    $user_id = (int) $user_id;
    if ($user_id <= 0 && function_exists('get_current_user_id')) {
        $user_id = (int) get_current_user_id();
    }
    if ($user_id > 0 && function_exists('update_user_meta')) {
        update_user_meta($user_id, 'locale', $locale);
        $updated = true;
    }

    return $updated;
}

/**
 * Return a requested locale from the current request when it is valid.
 */
function ll_tools_get_requested_switcher_locale($require_available = false) {
    if (!isset($_REQUEST['ll_locale'])) {
        return '';
    }

    $requested = sanitize_text_field(wp_unslash((string) $_REQUEST['ll_locale']));
    if (!ll_tools_is_valid_switcher_locale($requested)) {
        return '';
    }

    if ($require_available) {
        $available = ll_tools_get_plugin_locales();
        if (!in_array($requested, $available, true)) {
            return '';
        }
    }

    return $requested;
}

/**
 * Build the nonce action used by locale-switch links.
 */
function ll_tools_get_locale_switch_nonce_action(): string {
    return 'll_tools_switch_locale';
}

/**
 * Verify the current locale-switch request nonce.
 */
function ll_tools_verify_locale_switch_request_nonce(): bool {
    if (!isset($_REQUEST['ll_locale_nonce'])) {
        return false;
    }

    $nonce = sanitize_text_field(wp_unslash((string) $_REQUEST['ll_locale_nonce']));
    if ($nonce === '') {
        return false;
    }

    return (bool) wp_verify_nonce($nonce, ll_tools_get_locale_switch_nonce_action());
}

/**
 * Add the preferred locale to an internal URL so redirects land in the right UI language.
 */
function ll_tools_append_preferred_locale_to_url($url, $user_id = 0) {
    $url = is_string($url) ? trim($url) : '';
    if ($url === '') {
        return '';
    }

    $validated = (string) wp_validate_redirect($url, '');
    if ($validated === '') {
        return $url;
    }

    $preferred = ll_tools_get_user_locale_preference($user_id, false);
    if ($preferred === '') {
        $preferred = ll_tools_get_locale_cookie_preference();
    }
    if ($preferred === '') {
        return $url;
    }

    return (string) add_query_arg([
        'll_locale' => $preferred,
        'll_locale_nonce' => wp_create_nonce(ll_tools_get_locale_switch_nonce_action()),
    ], $validated);
}

/**
 * Sync the front-end locale cookie to the user's profile locale on login.
 */
function ll_tools_sync_locale_cookie_on_login($user_login, $user) {
    if (!($user instanceof WP_User)) {
        return;
    }

    $user_locale = ll_tools_get_user_locale_preference((int) $user->ID, false);
    if ($user_locale !== '') {
        ll_tools_persist_locale_preference($user_locale, (int) $user->ID);
        return;
    }

    ll_tools_persist_locale_preference(ll_tools_get_site_default_locale_preference(), 0);
}
add_action('wp_login', 'll_tools_sync_locale_cookie_on_login', 10, 2);

/**
 * Front-end locale for a logged-in user's account preference.
 */
function ll_tools_get_logged_in_user_locale_preference() {
    if (!function_exists('is_user_logged_in') || !is_user_logged_in()) {
        return '';
    }

    return ll_tools_get_user_locale_preference(0, false);
}

/**
 * Get a nice display label for a locale
 * $style: 'native' (Türkçe), 'english' (Turkish), or 'code' (tr_TR)
 */
function ll_tools_locale_label($locale, $style = 'native') {
    // Minimal built-ins for common cases; fall back gracefully.
    $map = [
        'en_US' => ['native' => 'English', 'english' => 'English'],
        'en_GB' => ['native' => 'English (UK)', 'english' => 'English (UK)'],
        'tr_TR' => ['native' => 'Türkçe', 'english' => 'Turkish'],
        'ar'    => ['native' => 'العربية', 'english' => 'Arabic'],
        'de_DE' => ['native' => 'Deutsch', 'english' => 'German'],
        'fr_FR' => ['native' => 'Français', 'english' => 'French'],
        'es_ES' => ['native' => 'Español', 'english' => 'Spanish'],
        'ru_RU' => ['native' => 'Русский', 'english' => 'Russian'],
    ];

    if (isset($map[$locale])) {
        return $map[$locale][$style === 'english' ? 'english' : 'native'];
    }

    // Try to be helpful even if unknown
    if ($style === 'code') return $locale;
    return $locale; // as-is
}

/**
 * Emoji flag for a locale (best-effort). If unknown, returns ''.
 */
function ll_tools_locale_flag($locale) {
    // Map by region subtag
    $region = '';
    if (strpos($locale, '_') !== false) {
        $region = substr(strrchr($locale, '_'), 1);
    }
    $region_map = [
        'US' => '🇺🇸',
        'GB' => '🇬🇧',
        'TR' => '🇹🇷',
        'DE' => '🇩🇪',
        'FR' => '🇫🇷',
        'ES' => '🇪🇸',
        'RU' => '🇷🇺',
        'IT' => '🇮🇹',
        'NL' => '🇳🇱',
        'PT' => '🇵🇹',
        'BR' => '🇧🇷',
        'AR' => '🇦🇷',
        'MX' => '🇲🇽',
        'SA' => '🇸🇦',
        'EG' => '🇪🇬',
    ];
    return $region && isset($region_map[$region]) ? $region_map[$region] : '';
}

/**
 * Handle ?ll_locale=xx_XX switches and persist to a cookie, then redirect.
 */
function ll_tools_handle_locale_switch() {
    if (is_admin() || (defined('DOING_AJAX') && DOING_AJAX) || (defined('REST_REQUEST') && REST_REQUEST)) {
        return; // front-end only
    }
    $requested = ll_tools_get_requested_switcher_locale(true);
    if ($requested === '') return;
    if (!ll_tools_verify_locale_switch_request_nonce()) return;

    ll_tools_persist_locale_preference($requested);

    // Redirect to a clean URL (remove the param). Use current URL as base safely.
    $target = remove_query_arg(['ll_locale', 'll_locale_nonce']);
    if (!$target) {
        // very defensive fallback
        $target = home_url(add_query_arg([], wp_parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/'));
    }

    // Prevent any output + send no-cache headers before redirect
    nocache_headers();
    wp_safe_redirect($target, 302);
    exit;
}
add_action('template_redirect', 'll_tools_handle_locale_switch');

/**
 * Keep the locale cookie aligned with the logged-in user's saved preference.
 */
function ll_tools_sync_frontend_locale_cookie_from_user_preference() {
    if (is_admin() || (defined('DOING_AJAX') && DOING_AJAX) || (defined('REST_REQUEST') && REST_REQUEST)) {
        return;
    }

    $user_locale = ll_tools_get_logged_in_user_locale_preference();
    if ($user_locale === '') {
        return;
    }

    if (ll_tools_get_locale_cookie_preference() === $user_locale) {
        return;
    }

    ll_tools_persist_locale_preference($user_locale);
}
add_action('init', 'll_tools_sync_frontend_locale_cookie_from_user_preference', 20);

/**
 * Apply the chosen locale to all requests, if it's allowed.
 */
function ll_tools_filter_locale($locale) {
    // Only front-end overrides; never run in admin/AJAX/REST.
    if (is_admin() || (defined('DOING_AJAX') && DOING_AJAX) || (defined('REST_REQUEST') && REST_REQUEST)) {
        return $locale;
    }

    $requested = ll_tools_get_requested_switcher_locale(true);
    if ($requested !== '' && !ll_tools_verify_locale_switch_request_nonce()) {
        $requested = '';
    }
    if ($requested !== '') {
        return $requested;
    }

    $user_locale = ll_tools_get_logged_in_user_locale_preference();
    if ($user_locale !== '') {
        return $user_locale;
    }

    $cookie_locale = ll_tools_get_locale_cookie_preference();
    if ($cookie_locale !== '') {
        return $cookie_locale;
    }

    if (function_exists('ll_tools_get_browser_locale_preference')) {
        $browser_locale = ll_tools_get_browser_locale_preference();
        if ($browser_locale !== '') {
            return $browser_locale;
        }
    }

    return $locale;
}
add_filter('locale', 'll_tools_filter_locale');

/**
 * Shortcode: [ll_language_switcher show_flags="1" style="native" class=""]
 *  - show_flags: 1 or 0 (default 1)
 *  - style: 'native' | 'english' | 'code' (default 'native')
 *  - class: extra CSS class to add to wrapper
 */
function ll_language_switcher_shortcode($atts) {
    $atts = shortcode_atts([
        'show_flags' => '1',
        'style'      => 'native',
        'class'      => '',
    ], $atts, 'll_language_switcher');

    $available = ll_tools_get_plugin_locales();
    if (empty($available)) return '';

    $current = get_locale();
    $show_flags = $atts['show_flags'] !== '0';
    $style = in_array($atts['style'], ['native','english','code'], true) ? $atts['style'] : 'native';
    $extra_class = sanitize_html_class($atts['class']);

    ob_start();
    ?>
    <nav class="ll-lang-switcher <?php echo esc_attr($extra_class); ?>">
        <ul>
            <?php foreach ($available as $loc):
                $is_current = ($loc === $current);
                $label = ll_tools_locale_label($loc, $style);
                $flag  = $show_flags ? ll_tools_locale_flag($loc) : '';
                $url   = esc_url(add_query_arg([
                    'll_locale' => $loc,
                    'll_locale_nonce' => wp_create_nonce(ll_tools_get_locale_switch_nonce_action()),
                ]));
            ?>
                <li class="<?php echo $is_current ? 'is-current' : ''; ?>">
                    <?php if ($is_current): ?>
                        <span aria-current="true" class="ll-lang-current">
                            <?php echo $flag ? '<span class="ll-flag" aria-hidden="true">' . esc_html($flag) . '</span> ' : ''; ?>
                            <span class="ll-label"><?php echo esc_html($label); ?></span>
                        </span>
                    <?php else: ?>
                        <a href="<?php echo $url; ?>" class="ll-lang-link">
                            <?php echo $flag ? '<span class="ll-flag" aria-hidden="true">' . esc_html($flag) . '</span> ' : ''; ?>
                            <span class="ll-label"><?php echo esc_html($label); ?></span>
                        </a>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </nav>
    <style>
        .ll-lang-switcher { display:inline-block; font-size:14px; }
        .ll-lang-switcher ul { list-style:none; margin:0; padding:0; display:flex; gap:.5rem; align-items:center; }
        .ll-lang-switcher li { margin:0; padding:0; }
        .ll-lang-switcher .ll-lang-link, .ll-lang-switcher .ll-lang-current {
            display:inline-flex; align-items:center; gap:.35rem; text-decoration:none;
            padding:.3rem .5rem; border-radius:.4rem; border:1px solid rgba(0,0,0,.08);
        }
        .ll-lang-switcher .ll-lang-link:hover { background:rgba(0,0,0,.05); }
        .ll-lang-switcher .is-current .ll-lang-current { background:rgba(0,0,0,.08); font-weight:600; }
        .ll-lang-switcher .ll-flag { line-height:1; }
    </style>
    <?php
    return ob_get_clean();
}
add_shortcode('ll_language_switcher', 'll_language_switcher_shortcode');
