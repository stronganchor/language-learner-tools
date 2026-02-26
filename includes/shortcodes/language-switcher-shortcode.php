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
 * Sync the front-end locale cookie to the user's profile locale on login.
 *
 * This makes front-end language follow the account "Language" setting after
 * sign-in, while still allowing later manual overrides via the switcher.
 */
function ll_tools_sync_locale_cookie_on_login($user_login, $user) {
    if (!($user instanceof WP_User)) {
        return;
    }

    $user_locale = '';
    if (function_exists('get_user_meta')) {
        $user_locale = (string) get_user_meta((int) $user->ID, 'locale', true);
    }
    if ($user_locale === '') {
        $site_default = (string) get_option('WPLANG');
        $user_locale = ($site_default !== '') ? $site_default : 'en_US';
    }

    if (!ll_tools_is_valid_switcher_locale($user_locale)) {
        return;
    }

    ll_tools_set_locale_cookie($user_locale);
    // Make the new locale visible during the remainder of the current request.
    $_COOKIE[LL_TOOLS_I18N_COOKIE] = $user_locale;
}
add_action('wp_login', 'll_tools_sync_locale_cookie_on_login', 10, 2);

/**
 * Front-end fallback to a logged-in user's preferred locale when no explicit
 * switcher cookie exists. Reads raw user meta to avoid get_locale() recursion.
 */
function ll_tools_get_logged_in_user_locale_preference() {
    if (!function_exists('is_user_logged_in') || !is_user_logged_in()) {
        return '';
    }
    if (!function_exists('wp_get_current_user')) {
        return '';
    }

    $user = wp_get_current_user();
    if (!($user instanceof WP_User) || empty($user->ID)) {
        return '';
    }

    $user_locale = '';
    if (function_exists('get_user_meta')) {
        $user_locale = (string) get_user_meta((int) $user->ID, 'locale', true);
    } elseif (isset($user->locale)) {
        $user_locale = (string) $user->locale;
    }

    return ll_tools_is_valid_switcher_locale($user_locale) ? $user_locale : '';
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
    if (!isset($_GET['ll_locale'])) return;

    $requested = sanitize_text_field(wp_unslash($_GET['ll_locale']));
    $available = ll_tools_get_plugin_locales();

    if (!in_array($requested, $available, true)) return;

    ll_tools_set_locale_cookie($requested);
    $_COOKIE[LL_TOOLS_I18N_COOKIE] = $requested;

    // Redirect to a clean URL (remove the param). Use current URL as base safely.
    $target = remove_query_arg('ll_locale');
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
 * Apply the chosen locale (from cookie) to all requests, if it's allowed.
 */
function ll_tools_filter_locale($locale) {
    // Only front-end cookie override; never run in admin/AJAX/REST.
    if (is_admin() || (defined('DOING_AJAX') && DOING_AJAX) || (defined('REST_REQUEST') && REST_REQUEST)) {
        return $locale;
    }
    if (!empty($_COOKIE[LL_TOOLS_I18N_COOKIE])) {
        $chosen = sanitize_text_field(wp_unslash($_COOKIE[LL_TOOLS_I18N_COOKIE]));

        // Accept only well-formed locales; DO NOT scan files or call get_locale() here.
        if (ll_tools_is_valid_switcher_locale($chosen)) {
            return $chosen;
        }
    }

    $user_locale = ll_tools_get_logged_in_user_locale_preference();
    if ($user_locale !== '') {
        return $user_locale;
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
                $url   = esc_url(add_query_arg('ll_locale', $loc));
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
