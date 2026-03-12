<?php
if (!defined('WPINC')) { die; }

/**
 * Normalize the browser-language autoswitch setting.
 */
function ll_tools_normalize_browser_language_autoswitch_setting_value($value): int {
    return absint($value) === 1 ? 1 : 0;
}

/**
 * Returns whether browser-language autoswitch is enabled.
 *
 * Enabled by default for visitors who have not explicitly chosen a locale.
 * The legacy filter still applies so code can force the behavior on or off.
 */
function ll_tools_is_browser_language_autoswitch_enabled(): bool {
    $enabled = ll_tools_normalize_browser_language_autoswitch_setting_value(
        get_option('ll_enable_browser_language_autoswitch', 1)
    ) === 1;

    return (bool) apply_filters('ll_tools_enable_browser_language_autoswitch', $enabled);
}

/**
 * Normalize locale values such as en-US or en_US to WordPress-style locale codes.
 */
function ll_tools_normalize_switcher_locale_code($locale): string {
    $locale = trim((string) $locale);
    if ($locale === '') {
        return '';
    }

    $locale = str_replace('-', '_', $locale);
    $parts = array_values(array_filter(explode('_', $locale), 'strlen'));
    if (empty($parts)) {
        return '';
    }

    $language = strtolower((string) $parts[0]);
    if (!preg_match('/^[a-z]{2,3}$/', $language)) {
        return '';
    }

    if (count($parts) < 2) {
        return $language;
    }

    $region = strtoupper((string) $parts[1]);
    if (!preg_match('/^[A-Z]{2}$/', $region)) {
        return $language;
    }

    return $language . '_' . $region;
}

/**
 * Parse the browser Accept-Language header into an ordered list of preferred locales.
 *
 * @return string[]
 */
function ll_tools_get_accept_language_preferences($accept_language): array {
    $header = trim((string) $accept_language);
    if ($header === '') {
        return [];
    }

    $preferences = [];
    foreach (explode(',', $header) as $index => $raw_range) {
        $raw_range = trim((string) $raw_range);
        if ($raw_range === '') {
            continue;
        }

        $parts = explode(';', $raw_range);
        $locale = ll_tools_normalize_switcher_locale_code($parts[0]);
        if ($locale === '') {
            continue;
        }

        $quality = 1.0;
        if (isset($parts[1]) && preg_match('/q=([0-9.]+)/i', (string) $parts[1], $matches)) {
            $quality = max(0.0, min(1.0, (float) $matches[1]));
        }

        $preferences[] = [
            'locale' => $locale,
            'quality' => $quality,
            'index' => (int) $index,
        ];
    }

    if (empty($preferences)) {
        return [];
    }

    usort($preferences, static function (array $left, array $right): int {
        if ($left['quality'] === $right['quality']) {
            return $left['index'] <=> $right['index'];
        }

        return ($left['quality'] > $right['quality']) ? -1 : 1;
    });

    $ordered = [];
    foreach ($preferences as $preference) {
        $locale = (string) $preference['locale'];
        if ($locale !== '' && !in_array($locale, $ordered, true)) {
            $ordered[] = $locale;
        }
    }

    return $ordered;
}

/**
 * Match a browser locale preference to the available plugin locales.
 */
function ll_tools_match_browser_locale_to_available_locales($browser_locale, array $available_locales): string {
    $normalized_browser_locale = ll_tools_normalize_switcher_locale_code($browser_locale);
    if ($normalized_browser_locale === '') {
        return '';
    }

    $normalized_available_locales = [];
    foreach ($available_locales as $available_locale) {
        $normalized = ll_tools_normalize_switcher_locale_code($available_locale);
        if ($normalized === '') {
            continue;
        }

        $normalized_available_locales[$normalized] = (string) $available_locale;
    }

    if (isset($normalized_available_locales[$normalized_browser_locale])) {
        return $normalized_available_locales[$normalized_browser_locale];
    }

    $language = strtok($normalized_browser_locale, '_');
    if (!is_string($language) || $language === '') {
        return '';
    }

    if (isset($normalized_available_locales[$language])) {
        return $normalized_available_locales[$language];
    }

    foreach ($normalized_available_locales as $normalized_locale => $available_locale) {
        if (strpos($normalized_locale, $language . '_') === 0) {
            return $available_locale;
        }
    }

    return '';
}

/**
 * Return the browser locale preference when it is enabled and applicable.
 */
function ll_tools_get_browser_locale_preference(): string {
    if (!ll_tools_is_browser_language_autoswitch_enabled()) {
        return '';
    }

    if (is_admin() || (defined('DOING_AJAX') && DOING_AJAX) || (defined('REST_REQUEST') && REST_REQUEST)) {
        return '';
    }

    // Respect explicit user locale choices.
    if (isset($_GET['ll_locale'])) {
        return '';
    }
    if (function_exists('ll_tools_get_logged_in_user_locale_preference')) {
        $user_locale = ll_tools_get_logged_in_user_locale_preference();
        if ($user_locale !== '') {
            return '';
        }
    }
    if (defined('LL_TOOLS_I18N_COOKIE') && !empty($_COOKIE[LL_TOOLS_I18N_COOKIE])) {
        return '';
    }

    $accept_language = isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? (string) $_SERVER['HTTP_ACCEPT_LANGUAGE'] : '';
    if ($accept_language === '') {
        return '';
    }

    if (function_exists('ll_tools_get_plugin_locales')) {
        $available_languages = ll_tools_get_plugin_locales();
    } else {
        $available_languages = get_available_languages();
        $available_languages[] = trim((string) get_option('WPLANG')) ?: 'en_US';
        $available_languages[] = 'en_US';
    }

    if (empty($available_languages)) {
        return '';
    }

    foreach (ll_tools_get_accept_language_preferences($accept_language) as $browser_locale) {
        $matched_locale = ll_tools_match_browser_locale_to_available_locales($browser_locale, $available_languages);
        if ($matched_locale !== '') {
            return $matched_locale;
        }
    }

    return '';
}

/**
 * Browser-language fallback for locales.
 */
function ll_tools_switch_language_from_browser() {
    $browser_locale = ll_tools_get_browser_locale_preference();
    if ($browser_locale === '') {
        return;
    }

    if (function_exists('get_locale') && get_locale() === $browser_locale) {
        return;
    }

    if (function_exists('switch_to_locale')) {
        switch_to_locale($browser_locale);
    }
}
add_action('init', 'll_tools_switch_language_from_browser');
