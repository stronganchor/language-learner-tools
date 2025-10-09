<?php
// File: includes/shortcodes/language-switcher-shortcode.php

if (!defined('ABSPATH')) exit;

define('LL_TOOLS_I18N_COOKIE', 'll_locale');
define('LL_TOOLS_TEXTDOMAIN', 'll-tools-text-domain');

/**
 * Find locales the LL Tools plugin has translations for by scanning
 * wp-content/languages/plugins/ll-tools-text-domain-*.mo
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

    // Add a non-filtering default without invoking locale hooks:
    // get_option('WPLANG') returns site setting or '' (use en_US as WP default)
    $site_default = get_option('WPLANG');
    $locales[] = $site_default ? $site_default : 'en_US';

    $cache = array_values(array_unique($locales));
    return $cache;
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
    // Frontend only; do not break editors/REST/AJAX.
    if (
        is_admin() ||
        (defined('DOING_AJAX') && DOING_AJAX) ||
        (defined('REST_REQUEST') && REST_REQUEST) ||
        (function_exists('wp_is_json_request') && wp_is_json_request())
    ) {
        return;
    }
    if (!isset($_GET['ll_locale'])) return;

    $requested = sanitize_text_field(wp_unslash($_GET['ll_locale']));
    $available = ll_tools_get_plugin_locales();
    if (!in_array($requested, $available, true)) return;

    // Always set our cookie so WP gettext uses the right .mo when TP isn't active.
    $expire      = time() + YEAR_IN_SECONDS;
    $cookie_path = defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/';
    setcookie(LL_TOOLS_I18N_COOKIE, $requested, $expire, $cookie_path, COOKIE_DOMAIN, is_ssl(), true);

    $current_url = home_url(add_query_arg([]));

    // If TranslatePress is active, redirect to its canonical URL for the target language.
    if (class_exists('TRP_Translate_Press')) {
        $trp      = \TRP_Translate_Press::get_trp_instance();
        $url_conv = $trp->get_component('url_converter');
        $target   = $url_conv->get_url_for_language($requested, $current_url, '');
        if ($target && is_string($target)) {
            nocache_headers();
            wp_safe_redirect($target, 302);
            exit;
        }
    }

    // Fallback: clean the param and stay on the same path.
    $target = remove_query_arg('ll_locale');
    if (!$target) {
        $target = $current_url;
    }
    nocache_headers();
    wp_safe_redirect($target, 302);
    exit;
}
add_action('template_redirect', 'll_tools_handle_locale_switch');

/**
 * Apply the chosen locale (from cookie) to all requests, if it's allowed.
 */
function ll_tools_filter_locale($locale) {
    if (
        is_admin() ||
        (defined('DOING_AJAX') && DOING_AJAX) ||
        (defined('REST_REQUEST') && REST_REQUEST) ||
        (function_exists('wp_is_json_request') && wp_is_json_request())
    ) {
        return $locale;
    }

    // If TranslatePress is active, let it drive the locale.
    if (class_exists('TRP_Translate_Press')) {
        return $locale;
    }

    if (empty($_COOKIE[LL_TOOLS_I18N_COOKIE])) return $locale;

    $chosen = sanitize_text_field(wp_unslash($_COOKIE[LL_TOOLS_I18N_COOKIE]));
    if (preg_match('/^[a-z]{2,3}(?:_[A-Z]{2})?$/', $chosen)) {
        return $chosen;
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

    $show_flags  = $atts['show_flags'] !== '0';
    $style       = in_array($atts['style'], ['native','english','code'], true) ? $atts['style'] : 'native';
    $extra_class = sanitize_html_class($atts['class']);

    // If TranslatePress is active, use its API to get correct per-language URLs.
    $tp_languages = [];
    if (function_exists('trp_custom_language_switcher')) {
        $tp_languages = trp_custom_language_switcher(); // has keys: 'current_language', 'languages' => [ [ 'language_code', 'url', ... ], ... ]
    }

    // Our fallback locales list (plugin translation presence + site default).
    $available = ll_tools_get_plugin_locales();
    if (empty($available)) return '';

    $current = get_locale();

    ob_start(); ?>
    <nav class="ll-lang-switcher <?php echo esc_attr($extra_class); ?>" data-no-translation>
        <ul>
            <?php if (!empty($tp_languages) && !empty($tp_languages['languages'])): ?>
                <?php foreach ($tp_languages['languages'] as $lang):
                    $loc        = isset($lang['language_code']) ? $lang['language_code'] : '';
                    $is_current = ($loc === $tp_languages['current_language']);
                    $label      = ll_tools_locale_label($loc, $style);
                    $flag       = $show_flags ? ll_tools_locale_flag($loc) : '';
                    $url        = isset($lang['url']) ? esc_url($lang['url']) : '#';
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
            <?php else: ?>
                <?php foreach ($available as $loc):
                    $is_current = ($loc === $current);
                    $label      = ll_tools_locale_label($loc, $style);
                    $flag       = $show_flags ? ll_tools_locale_flag($loc) : '';
                    $url        = esc_url(add_query_arg('ll_locale', $loc));
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
            <?php endif; ?>
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
