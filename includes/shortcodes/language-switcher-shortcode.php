<?php
// File: includes/shortcodes/language-switcher-shortcode.php

if (!defined('ABSPATH')) exit;

define('LL_TOOLS_I18N_COOKIE', 'll_locale');
define('LL_TOOLS_TEXTDOMAIN', 'll-tools-text-domain');
define('LL_TOOLS_PUBLIC_LOCALE_META', 'll_tools_public_locale');

/**
 * Return locale tier configuration for the public UI translation system.
 */
function ll_tools_get_public_i18n_locale_config(): array {
    static $config = null;
    if (is_array($config)) {
        return $config;
    }

    $path = trailingslashit(LL_TOOLS_BASE_PATH) . 'languages/tier2-public-ui-sources.php';
    $loaded = is_file($path) ? require $path : [];
    $config = is_array($loaded) ? $loaded : [];

    return $config;
}

/**
 * Locales with full plugin coverage, suitable for staff/admin UI.
 *
 * @return string[]
 */
function ll_tools_get_core_full_locales(): array {
    $config = ll_tools_get_public_i18n_locale_config();
    $locales = ['en_US'];

    foreach (array_keys((array) ($config['core_full_locales'] ?? [])) as $locale) {
        if (ll_tools_is_valid_switcher_locale($locale)) {
            $locales[] = (string) $locale;
        }
    }

    return array_values(array_unique($locales));
}

/**
 * Tier-2 locales configured for public UI translation.
 *
 * @return string[]
 */
function ll_tools_get_tier2_public_locales(bool $active_only = false): array {
    $config = ll_tools_get_public_i18n_locale_config();
    $locales = [];

    foreach ((array) ($config['tier2_locales'] ?? []) as $locale => $locale_config) {
        if (!ll_tools_is_valid_switcher_locale($locale)) {
            continue;
        }

        $status = is_array($locale_config) ? (string) ($locale_config['status'] ?? '') : '';
        if ($active_only && !in_array($status, ['active', 'complete'], true)) {
            continue;
        }
        $locales[] = (string) $locale;
    }

    $locales = array_values(array_unique($locales));

    return (array) apply_filters('ll_tools_tier2_public_locales', $locales, $active_only);
}

/**
 * Public-only tier-2 locales configured as ready for the language switcher.
 *
 * @return string[]
 */
function ll_tools_get_active_tier2_public_locales(): array {
    return ll_tools_get_tier2_public_locales(true);
}

function ll_tools_locale_has_full_translation($locale): bool {
    $normalized = ll_tools_normalize_switcher_locale_code($locale);
    if ($normalized === '') {
        return false;
    }

    return in_array($normalized, ll_tools_get_core_full_locales(), true);
}

function ll_tools_is_public_only_locale($locale): bool {
    $normalized = ll_tools_normalize_switcher_locale_code($locale);
    if ($normalized === '') {
        return false;
    }

    return in_array($normalized, ll_tools_get_tier2_public_locales(false), true)
        && !ll_tools_locale_has_full_translation($normalized);
}

function ll_tools_is_available_public_locale($locale): bool {
    $normalized = ll_tools_normalize_switcher_locale_code($locale);
    if ($normalized === '') {
        return false;
    }

    return ll_tools_locale_has_full_translation($normalized)
        || in_array($normalized, ll_tools_get_active_tier2_public_locales(), true);
}

function ll_tools_get_staff_locale_fallback(): string {
    $site_default = ll_tools_get_site_default_locale_preference();
    if (ll_tools_locale_has_full_translation($site_default)) {
        return $site_default;
    }

    return 'en_US';
}

function ll_tools_user_has_elevated_locale_context($user_id = 0): bool {
    $user_id = (int) $user_id;
    if ($user_id <= 0 && function_exists('get_current_user_id')) {
        $user_id = (int) get_current_user_id();
    }
    if ($user_id <= 0) {
        return false;
    }

    return user_can($user_id, 'view_ll_tools') || user_can($user_id, 'manage_options');
}

function ll_tools_repair_elevated_public_only_wp_locale($user_id = 0): void {
    $user_id = (int) $user_id;
    if ($user_id <= 0 && function_exists('get_current_user_id')) {
        $user_id = (int) get_current_user_id();
    }
    if ($user_id <= 0 || !ll_tools_user_has_elevated_locale_context($user_id) || !function_exists('get_user_meta')) {
        return;
    }

    $wp_locale = (string) get_user_meta($user_id, 'locale', true);
    if (!ll_tools_is_public_only_locale($wp_locale)) {
        return;
    }

    $public_locale = (string) get_user_meta($user_id, LL_TOOLS_PUBLIC_LOCALE_META, true);
    if (!ll_tools_is_valid_switcher_locale($public_locale)) {
        update_user_meta($user_id, LL_TOOLS_PUBLIC_LOCALE_META, $wp_locale);
    }
    update_user_meta($user_id, 'locale', ll_tools_get_staff_locale_fallback());
}

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
    $locales = array_merge($locales, ll_tools_get_core_full_locales(), ll_tools_get_active_tier2_public_locales());

    $cache = array_values(array_filter(
        array_unique($locales),
        static fn (string $locale): bool => ll_tools_is_available_public_locale($locale)
    ));
    $preferred_order = ['tr_TR' => 0, 'en_US' => 1, 'de_DE' => 2];
    usort($cache, static function (string $left, string $right) use ($preferred_order): int {
        return (($preferred_order[$left] ?? 50) <=> ($preferred_order[$right] ?? 50)) ?: strcmp($left, $right);
    });

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
    return ll_tools_is_available_public_locale($chosen) ? $chosen : '';
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
        $public_locale = (string) get_user_meta($user_id, LL_TOOLS_PUBLIC_LOCALE_META, true);
        if (ll_tools_is_available_public_locale($public_locale)) {
            return $public_locale;
        }

        $user_locale = (string) get_user_meta($user_id, 'locale', true);
    } else {
        $user = get_userdata($user_id);
        if ($user instanceof WP_User && isset($user->locale)) {
            $user_locale = (string) $user->locale;
        }
    }

    if (ll_tools_is_available_public_locale($user_locale)) {
        return $user_locale;
    }

    return $fallback_to_site_default ? ll_tools_get_site_default_locale_preference() : '';
}

/**
 * Persist a locale preference to the cookie and, for logged-in users, their account.
 */
function ll_tools_persist_locale_preference($locale, $user_id = 0) {
    if (!ll_tools_is_available_public_locale($locale)) {
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
        update_user_meta($user_id, LL_TOOLS_PUBLIC_LOCALE_META, $locale);
        if (ll_tools_locale_has_full_translation($locale)) {
            update_user_meta($user_id, 'locale', $locale);
        }
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
 * Keep public-only tier-2 preferences out of wp-admin/staff locale selection.
 */
function ll_tools_pre_determine_locale_for_staff_public_only_preference($locale) {
    if ($locale !== null) {
        return $locale;
    }
    if (!is_admin() || !ll_tools_user_has_elevated_locale_context()) {
        return $locale;
    }

    $user_id = (int) get_current_user_id();
    ll_tools_repair_elevated_public_only_wp_locale($user_id);
    $public_locale = (string) get_user_meta($user_id, LL_TOOLS_PUBLIC_LOCALE_META, true);
    $wp_locale = (string) get_user_meta($user_id, 'locale', true);

    if (ll_tools_is_public_only_locale($public_locale) || ll_tools_is_public_only_locale($wp_locale)) {
        return ll_tools_get_staff_locale_fallback();
    }

    return $locale;
}
add_filter('pre_determine_locale', 'll_tools_pre_determine_locale_for_staff_public_only_preference', 20);

/**
 * Shortcode: [ll_language_switcher show_flags="1" style="native" display="list" class=""]
 *  - show_flags: 1 or 0 (default 1)
 *  - style: 'native' | 'english' | 'code' (default 'native')
 *  - display: 'list' | 'dropdown' | 'button' (default 'list')
 *  - class: extra CSS class to add to wrapper
 */
function ll_language_switcher_shortcode($atts) {
    $atts = shortcode_atts([
        'show_flags' => '1',
        'style'      => 'native',
        'display'    => 'list',
        'button_label' => '',
        'show_current' => '1',
        'class'      => '',
    ], $atts, 'll_language_switcher');

    $available = ll_tools_get_plugin_locales();
    if (empty($available)) return '';

    $current = get_locale();
    $show_flags = $atts['show_flags'] !== '0';
    $style = in_array($atts['style'], ['native','english','code'], true) ? $atts['style'] : 'native';
    $display = in_array($atts['display'], ['list', 'dropdown', 'button'], true) ? $atts['display'] : 'list';
    $show_current = $atts['show_current'] !== '0';
    $button_label = trim((string) $atts['button_label']);
    if ($button_label === '') {
        $button_label = __('Language', 'll-tools-text-domain');
    }
    $extra_class = sanitize_html_class($atts['class']);
    $current_label = ll_tools_locale_label($current, $style);
    $wrapper_classes = trim('ll-lang-switcher ll-lang-switcher--' . $display . ' ' . $extra_class);

    ob_start();
    ?>
    <nav class="<?php echo esc_attr($wrapper_classes); ?>" aria-label="<?php echo esc_attr__('Language', 'll-tools-text-domain'); ?>">
        <?php if ($display !== 'list') : ?>
            <details class="ll-lang-switcher__details">
                <summary class="ll-lang-switcher__summary">
                    <span class="ll-lang-switcher__summary-icon" aria-hidden="true">A/あ</span>
                    <span class="ll-lang-switcher__summary-label"><?php echo esc_html($button_label); ?></span>
                    <?php if ($show_current) : ?>
                        <span class="ll-lang-switcher__summary-current"><?php echo esc_html($current_label); ?></span>
                    <?php endif; ?>
                </summary>
        <?php endif; ?>
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
        <?php if ($display !== 'list') : ?>
            </details>
        <?php endif; ?>
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
        .ll-lang-switcher--dropdown, .ll-lang-switcher--button { position:relative; }
        .ll-lang-switcher__details { position:relative; }
        .ll-lang-switcher__summary {
            list-style:none; cursor:pointer; display:inline-flex; align-items:center; gap:.4rem;
            padding:.4rem .65rem; border-radius:.4rem; border:1px solid rgba(0,0,0,.12);
            background:#fff; color:inherit; line-height:1.2; user-select:none;
        }
        .ll-lang-switcher__summary::-webkit-details-marker { display:none; }
        .ll-lang-switcher__summary-icon { font-weight:700; letter-spacing:0; line-height:1; }
        .ll-lang-switcher__summary-current { color:rgba(0,0,0,.62); font-size:.92em; }
        .ll-lang-switcher--button .ll-lang-switcher__summary-current { display:none; }
        .ll-lang-switcher--dropdown ul, .ll-lang-switcher--button ul {
            position:absolute; z-index:200; right:0; top:calc(100% + 6px);
            min-width:14rem; max-height:min(70vh, 24rem); overflow:auto;
            display:grid; gap:.25rem; padding:.35rem; border:1px solid rgba(0,0,0,.12);
            border-radius:.45rem; background:#fff; box-shadow:0 12px 28px rgba(0,0,0,.16);
        }
        .ll-lang-switcher--dropdown .ll-lang-link, .ll-lang-switcher--dropdown .ll-lang-current,
        .ll-lang-switcher--button .ll-lang-link, .ll-lang-switcher--button .ll-lang-current {
            width:100%; justify-content:flex-start; border-color:transparent; border-radius:.35rem;
        }
    </style>
    <?php
    return ob_get_clean();
}
add_shortcode('ll_language_switcher', 'll_language_switcher_shortcode');

function ll_tools_language_switcher_current_language_key(): string {
    if (function_exists('ll_tools_text_document_current_language_candidates')) {
        $candidates = ll_tools_text_document_current_language_candidates();
        if (!empty($candidates)) {
            return (string) $candidates[0];
        }
    }

    $locale = function_exists('get_locale') ? (string) get_locale() : '';
    $locale = strtolower(str_replace('-', '_', $locale));
    if (strpos($locale, 'tr') === 0) {
        return 'tr';
    }
    if (strpos($locale, 'de') === 0) {
        return 'de';
    }
    if (strpos($locale, 'en') === 0) {
        return 'en';
    }

    return 'tr';
}

function ll_locale_block_shortcode($atts, $content = ''): string {
    $atts = shortcode_atts([
        'lang' => '',
        'language' => '',
    ], is_array($atts) ? $atts : [], 'll_locale_block');

    $raw_languages = trim((string) ($atts['lang'] !== '' ? $atts['lang'] : $atts['language']));
    if ($raw_languages === '') {
        return '';
    }

    $current = ll_tools_language_switcher_current_language_key();
    $languages = preg_split('/[\s,|]+/', strtolower($raw_languages));
    $languages = is_array($languages) ? array_filter($languages) : [];
    foreach ($languages as $language) {
        $language = sanitize_key((string) $language);
        if (function_exists('ll_tools_text_document_language_key_from_locale')) {
            $language = ll_tools_text_document_language_key_from_locale($language);
        }
        if ($language === $current) {
            return do_shortcode((string) $content);
        }
    }

    return '';
}
add_shortcode('ll_locale_block', 'll_locale_block_shortcode');

function ll_tools_should_render_header_language_switcher(): bool {
    if (is_admin() || (defined('DOING_AJAX') && DOING_AJAX) || (defined('REST_REQUEST') && REST_REQUEST)) {
        return false;
    }

    return (bool) apply_filters('ll_tools_header_language_switcher_enabled', true);
}

function ll_tools_header_language_switcher_styles(): string {
    static $printed = false;
    if ($printed) {
        return '';
    }
    $printed = true;

    return '<style id="ll-tools-header-language-switcher-css">'
        . '.ll-tools-header-language-switcher{box-sizing:border-box;width:100%;display:flex;justify-content:flex-end;padding:8px 16px;background:#fffdf8;border-bottom:1px solid rgba(28,68,60,.12);position:relative;z-index:20;}'
        . '.ll-tools-header-language-switcher .ll-lang-switcher{display:block;}'
        . '.ll-tools-header-language-switcher .ll-lang-switcher ul{justify-content:flex-end;flex-wrap:wrap;}'
        . '.ll-tools-header-language-switcher .ll-lang-switcher .ll-lang-link,.ll-tools-header-language-switcher .ll-lang-switcher .ll-lang-current{border-color:#d7c5a9;background:#fff;color:#26463f;}'
        . '.ll-tools-header-language-switcher .ll-lang-switcher .is-current .ll-lang-current{background:#e6f3ef;border-color:#1f6b5c;color:#0f5d52;}'
        . '@media(max-width:600px){.ll-tools-header-language-switcher{justify-content:center;padding:8px 12px;}}'
        . '</style>';
}

function ll_tools_render_header_language_switcher(): void {
    static $printed = false;
    if ($printed || !ll_tools_should_render_header_language_switcher()) {
        return;
    }

    $markup = ll_language_switcher_shortcode([
        'show_flags' => '1',
        'style' => 'native',
        'display' => 'dropdown',
        'button_label' => __('Language', 'll-tools-text-domain'),
        'class' => 'll-lang-switcher--header',
    ]);
    if (trim((string) $markup) === '') {
        return;
    }

    $printed = true;
    echo ll_tools_header_language_switcher_styles(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    echo '<div class="ll-tools-header-language-switcher" role="region" aria-label="' . esc_attr__('Language', 'll-tools-text-domain') . '">';
    echo $markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    echo '</div>';
}
add_action('wp_body_open', 'll_tools_render_header_language_switcher', 20);
add_action('wp_footer', 'll_tools_render_header_language_switcher', 1);
