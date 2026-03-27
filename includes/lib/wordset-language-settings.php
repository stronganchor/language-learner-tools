<?php
if (!defined('WPINC')) {
    die;
}

if (!defined('LL_TOOLS_WORDSET_TRANSLATION_LANGUAGE_META_KEY')) {
    define('LL_TOOLS_WORDSET_TRANSLATION_LANGUAGE_META_KEY', 'll_wordset_translation_language');
}
if (!defined('LL_TOOLS_WORDSET_CATEGORY_TRANSLATION_ENABLED_META_KEY')) {
    define('LL_TOOLS_WORDSET_CATEGORY_TRANSLATION_ENABLED_META_KEY', 'll_wordset_enable_category_translation');
}
if (!defined('LL_TOOLS_WORDSET_CATEGORY_TRANSLATION_SOURCE_META_KEY')) {
    define('LL_TOOLS_WORDSET_CATEGORY_TRANSLATION_SOURCE_META_KEY', 'll_wordset_category_translation_source');
}
if (!defined('LL_TOOLS_WORDSET_WORD_TITLE_LANGUAGE_ROLE_META_KEY')) {
    define('LL_TOOLS_WORDSET_WORD_TITLE_LANGUAGE_ROLE_META_KEY', 'll_wordset_word_title_language_role');
}
if (!defined('LL_TOOLS_WORDSET_LANGUAGE_SETTINGS_MIGRATION_OPTION')) {
    define('LL_TOOLS_WORDSET_LANGUAGE_SETTINGS_MIGRATION_OPTION', 'll_tools_wordset_language_settings_migrated_version');
}

function ll_tools_sanitize_wordset_language_setting($value): string {
    return sanitize_text_field((string) $value);
}

function ll_tools_sanitize_wordset_category_translation_source($value): string {
    $value = sanitize_key((string) $value);
    return in_array($value, ['target', 'translation'], true) ? $value : 'target';
}

function ll_tools_sanitize_wordset_title_language_role($value): string {
    $value = sanitize_key((string) $value);
    return in_array($value, ['target', 'translation'], true) ? $value : 'target';
}

function ll_tools_normalize_wordset_boolean_setting($value): int {
    return absint($value) === 1 ? 1 : 0;
}

/**
 * Normalize a mixed wordset context into unique positive term IDs.
 *
 * @param mixed $wordset_ids
 * @return int[]
 */
function ll_tools_normalize_wordset_setting_ids($wordset_ids): array {
    if ($wordset_ids instanceof WP_Term) {
        $wordset_ids = [$wordset_ids->term_id];
    } elseif (is_numeric($wordset_ids)) {
        $wordset_ids = [(int) $wordset_ids];
    } elseif (is_string($wordset_ids)) {
        $resolved = 0;
        if (function_exists('ll_tools_resolve_wordset_term_id')) {
            $resolved = (int) ll_tools_resolve_wordset_term_id($wordset_ids);
        }
        $wordset_ids = $resolved > 0 ? [$resolved] : [];
    } elseif (!is_array($wordset_ids)) {
        $wordset_ids = [];
    }

    $normalized = [];
    foreach ((array) $wordset_ids as $wordset_id) {
        if ($wordset_id instanceof WP_Term) {
            $wordset_id = (int) $wordset_id->term_id;
        }
        $wordset_id = (int) $wordset_id;
        if ($wordset_id > 0) {
            $normalized[$wordset_id] = true;
        }
    }

    return array_map('intval', array_keys($normalized));
}

/**
 * Get wordset term IDs assigned to a post.
 *
 * @param int $post_id
 * @return int[]
 */
function ll_tools_get_post_wordset_ids(int $post_id): array {
    $post_id = (int) $post_id;
    if ($post_id <= 0) {
        return [];
    }

    $term_ids = wp_get_post_terms($post_id, 'wordset', ['fields' => 'ids']);
    if (is_wp_error($term_ids) || !is_array($term_ids)) {
        return [];
    }

    return ll_tools_normalize_wordset_setting_ids($term_ids);
}

function ll_tools_get_legacy_target_language_setting(): string {
    return ll_tools_sanitize_wordset_language_setting(get_option('ll_target_language', ''));
}

function ll_tools_get_legacy_translation_language_setting(): string {
    return ll_tools_sanitize_wordset_language_setting(get_option('ll_translation_language', ''));
}

function ll_tools_get_legacy_category_translation_enabled_setting(): bool {
    return ll_tools_normalize_wordset_boolean_setting(get_option('ll_enable_category_translation', 0)) === 1;
}

function ll_tools_get_legacy_category_translation_source_setting(): string {
    return ll_tools_sanitize_wordset_category_translation_source(get_option('ll_category_translation_source', 'target'));
}

function ll_tools_get_legacy_word_title_language_role_setting(): string {
    return ll_tools_sanitize_wordset_title_language_role(get_option('ll_word_title_language_role', 'target'));
}

/**
 * Resolve the target language label/code for a wordset.
 *
 * @param mixed $wordset_ids
 * @param bool  $fallback_to_legacy
 * @return string
 */
function ll_tools_get_wordset_target_language($wordset_ids = [], bool $fallback_to_legacy = true): string {
    $ids = ll_tools_normalize_wordset_setting_ids($wordset_ids);
    foreach ($ids as $wordset_id) {
        $value = ll_tools_sanitize_wordset_language_setting(get_term_meta($wordset_id, 'll_language', true));
        if ($value !== '') {
            return $value;
        }
    }

    return $fallback_to_legacy ? ll_tools_get_legacy_target_language_setting() : '';
}

/**
 * Resolve the translation/helper language label/code for a wordset.
 *
 * @param mixed $wordset_ids
 * @param bool  $fallback_to_legacy
 * @return string
 */
function ll_tools_get_wordset_translation_language($wordset_ids = [], bool $fallback_to_legacy = true): string {
    $ids = ll_tools_normalize_wordset_setting_ids($wordset_ids);
    foreach ($ids as $wordset_id) {
        if (!metadata_exists('term', $wordset_id, LL_TOOLS_WORDSET_TRANSLATION_LANGUAGE_META_KEY)) {
            continue;
        }
        return ll_tools_sanitize_wordset_language_setting(
            get_term_meta($wordset_id, LL_TOOLS_WORDSET_TRANSLATION_LANGUAGE_META_KEY, true)
        );
    }

    return $fallback_to_legacy ? ll_tools_get_legacy_translation_language_setting() : '';
}

/**
 * Resolve the configured word-title language role for a wordset.
 *
 * @param mixed $wordset_ids
 * @param bool  $fallback_to_legacy
 * @return string
 */
function ll_tools_get_wordset_title_language_role($wordset_ids = [], bool $fallback_to_legacy = true): string {
    $ids = ll_tools_normalize_wordset_setting_ids($wordset_ids);
    foreach ($ids as $wordset_id) {
        if (!metadata_exists('term', $wordset_id, LL_TOOLS_WORDSET_WORD_TITLE_LANGUAGE_ROLE_META_KEY)) {
            continue;
        }
        return ll_tools_sanitize_wordset_title_language_role(
            get_term_meta($wordset_id, LL_TOOLS_WORDSET_WORD_TITLE_LANGUAGE_ROLE_META_KEY, true)
        );
    }

    return $fallback_to_legacy ? ll_tools_get_legacy_word_title_language_role_setting() : 'target';
}

/**
 * Resolve the category translation source direction for a wordset.
 *
 * @param mixed $wordset_ids
 * @param bool  $fallback_to_legacy
 * @return string
 */
function ll_tools_get_wordset_category_translation_source($wordset_ids = [], bool $fallback_to_legacy = true): string {
    $ids = ll_tools_normalize_wordset_setting_ids($wordset_ids);
    foreach ($ids as $wordset_id) {
        if (!metadata_exists('term', $wordset_id, LL_TOOLS_WORDSET_CATEGORY_TRANSLATION_SOURCE_META_KEY)) {
            continue;
        }
        return ll_tools_sanitize_wordset_category_translation_source(
            get_term_meta($wordset_id, LL_TOOLS_WORDSET_CATEGORY_TRANSLATION_SOURCE_META_KEY, true)
        );
    }

    return $fallback_to_legacy ? ll_tools_get_legacy_category_translation_source_setting() : 'target';
}

/**
 * Resolve whether category name translations are enabled for a wordset.
 *
 * @param mixed $wordset_ids
 * @param bool  $fallback_to_legacy
 * @return bool
 */
function ll_tools_is_wordset_category_translation_enabled($wordset_ids = [], bool $fallback_to_legacy = true): bool {
    $ids = ll_tools_normalize_wordset_setting_ids($wordset_ids);
    foreach ($ids as $wordset_id) {
        if (!metadata_exists('term', $wordset_id, LL_TOOLS_WORDSET_CATEGORY_TRANSLATION_ENABLED_META_KEY)) {
            continue;
        }
        return ll_tools_normalize_wordset_boolean_setting(
            get_term_meta($wordset_id, LL_TOOLS_WORDSET_CATEGORY_TRANSLATION_ENABLED_META_KEY, true)
        ) === 1;
    }

    return $fallback_to_legacy ? ll_tools_get_legacy_category_translation_enabled_setting() : false;
}

/**
 * Determine whether the current locale should display translated category names.
 *
 * @param mixed       $wordset_ids
 * @param string|null $site_language
 * @return bool
 */
function ll_tools_should_use_category_translations($wordset_ids = [], ?string $site_language = null): bool {
    $target_language = strtolower((string) ll_tools_get_wordset_translation_language($wordset_ids));
    $site_language = strtolower((string) ($site_language ?? get_locale()));

    return ll_tools_is_wordset_category_translation_enabled($wordset_ids)
        && $target_language !== ''
        && strpos($site_language, $target_language) === 0;
}

/**
 * Resolve the language used in word titles for a wordset.
 *
 * @param mixed $wordset_ids
 * @return string
 */
function ll_tools_get_wordset_title_language_label($wordset_ids = []): string {
    $title_role = ll_tools_get_wordset_title_language_role($wordset_ids);
    if ($title_role === 'translation') {
        return ll_tools_get_wordset_translation_language($wordset_ids);
    }

    return ll_tools_get_wordset_target_language($wordset_ids);
}

function ll_tools_should_show_category_translation_ui(): bool {
    if (ll_tools_get_legacy_category_translation_enabled_setting()) {
        return true;
    }

    $enabled_wordsets = get_terms([
        'taxonomy'   => 'wordset',
        'hide_empty' => false,
        'fields'     => 'ids',
        'number'     => 1,
        'meta_query' => [
            [
                'key'     => LL_TOOLS_WORDSET_CATEGORY_TRANSLATION_ENABLED_META_KEY,
                'value'   => '1',
                'compare' => '=',
            ],
        ],
    ]);

    return !is_wp_error($enabled_wordsets) && !empty($enabled_wordsets);
}

function ll_tools_translate_missing_category_names(string $source_language, string $target_language): array {
    $source_language = ll_tools_sanitize_wordset_language_setting($source_language);
    $target_language = ll_tools_sanitize_wordset_language_setting($target_language);

    $results = [
        'success' => [],
        'errors' => [],
        'source_language' => $source_language,
        'target_language' => $target_language,
    ];

    if ($source_language === '' || $target_language === '' || !function_exists('translate_with_deepl')) {
        return $results;
    }

    $categories = get_terms([
        'taxonomy' => 'word-category',
        'hide_empty' => false,
    ]);
    if (is_wp_error($categories) || empty($categories)) {
        return $results;
    }

    foreach ($categories as $category) {
        if (!($category instanceof WP_Term)) {
            continue;
        }

        $translation = get_term_meta($category->term_id, 'term_translation', true);
        if (!empty($translation)) {
            continue;
        }

        $translated_name = translate_with_deepl($category->name, $target_language, $source_language);
        if ($translated_name) {
            update_term_meta($category->term_id, 'term_translation', $translated_name);
            $results['success'][] = [
                'original' => $category->name,
                'translated' => $translated_name,
            ];
            continue;
        }

        $results['errors'][] = [
            'original' => $category->name,
            'error' => sprintf(
                /* translators: 1: category ID, 2: category name */
                __('Translation failed for category ID %1$d (%2$s).', 'll-tools-text-domain'),
                (int) $category->term_id,
                (string) $category->name
            ),
        ];
    }

    return $results;
}

function ll_tools_store_category_translation_results_notice(array $results): void {
    set_transient('ll_translation_results', $results, 60);
}

function ll_tools_auto_translate_categories_for_wordset(int $wordset_id): array {
    $wordset_id = (int) $wordset_id;
    if ($wordset_id <= 0 || !ll_tools_is_wordset_category_translation_enabled([$wordset_id])) {
        return [
            'success' => [],
            'errors' => [],
            'source_language' => '',
            'target_language' => '',
        ];
    }

    $source_language = ll_tools_get_wordset_target_language([$wordset_id]);
    $target_language = ll_tools_get_wordset_translation_language([$wordset_id]);
    if (ll_tools_get_wordset_category_translation_source([$wordset_id]) === 'translation') {
        $source_language = ll_tools_get_wordset_translation_language([$wordset_id]);
        $target_language = ll_tools_get_wordset_target_language([$wordset_id]);
    }

    $results = ll_tools_translate_missing_category_names($source_language, $target_language);
    ll_tools_store_category_translation_results_notice($results);

    return $results;
}

function ll_tools_migrate_legacy_language_settings_to_wordsets(): array {
    $wordset_ids = get_terms([
        'taxonomy' => 'wordset',
        'hide_empty' => false,
        'fields' => 'ids',
    ]);
    if (is_wp_error($wordset_ids) || !is_array($wordset_ids)) {
        return [
            'wordsets' => 0,
            'migrated' => [],
        ];
    }

    $legacy = [
        'target_language' => ll_tools_get_legacy_target_language_setting(),
        'translation_language' => ll_tools_get_legacy_translation_language_setting(),
        'category_translation_enabled' => ll_tools_get_legacy_category_translation_enabled_setting() ? '1' : '0',
        'category_translation_source' => ll_tools_get_legacy_category_translation_source_setting(),
        'title_language_role' => ll_tools_get_legacy_word_title_language_role_setting(),
    ];

    $migrated = [
        'target_language' => 0,
        'translation_language' => 0,
        'category_translation_enabled' => 0,
        'category_translation_source' => 0,
        'title_language_role' => 0,
    ];

    foreach ($wordset_ids as $wordset_id) {
        $wordset_id = (int) $wordset_id;
        if ($wordset_id <= 0) {
            continue;
        }

        if ($legacy['target_language'] !== '' && ll_tools_sanitize_wordset_language_setting(get_term_meta($wordset_id, 'll_language', true)) === '') {
            update_term_meta($wordset_id, 'll_language', $legacy['target_language']);
            $migrated['target_language']++;
        }

        if ($legacy['translation_language'] !== '' && ll_tools_sanitize_wordset_language_setting(get_term_meta($wordset_id, LL_TOOLS_WORDSET_TRANSLATION_LANGUAGE_META_KEY, true)) === '') {
            update_term_meta($wordset_id, LL_TOOLS_WORDSET_TRANSLATION_LANGUAGE_META_KEY, $legacy['translation_language']);
            $migrated['translation_language']++;
        }

        if (!metadata_exists('term', $wordset_id, LL_TOOLS_WORDSET_CATEGORY_TRANSLATION_ENABLED_META_KEY)) {
            update_term_meta($wordset_id, LL_TOOLS_WORDSET_CATEGORY_TRANSLATION_ENABLED_META_KEY, $legacy['category_translation_enabled']);
            $migrated['category_translation_enabled']++;
        }

        if (!metadata_exists('term', $wordset_id, LL_TOOLS_WORDSET_CATEGORY_TRANSLATION_SOURCE_META_KEY)) {
            update_term_meta($wordset_id, LL_TOOLS_WORDSET_CATEGORY_TRANSLATION_SOURCE_META_KEY, $legacy['category_translation_source']);
            $migrated['category_translation_source']++;
        }

        if (!metadata_exists('term', $wordset_id, LL_TOOLS_WORDSET_WORD_TITLE_LANGUAGE_ROLE_META_KEY)) {
            update_term_meta($wordset_id, LL_TOOLS_WORDSET_WORD_TITLE_LANGUAGE_ROLE_META_KEY, $legacy['title_language_role']);
            $migrated['title_language_role']++;
        }
    }

    return [
        'wordsets' => count($wordset_ids),
        'migrated' => $migrated,
    ];
}

function ll_tools_maybe_migrate_legacy_language_settings_to_wordsets(): void {
    if ((function_exists('wp_doing_ajax') && wp_doing_ajax()) || (defined('REST_REQUEST') && REST_REQUEST)) {
        return;
    }

    if (!(current_user_can('view_ll_tools') || current_user_can('manage_options'))) {
        return;
    }

    $current_version = defined('LL_TOOLS_VERSION') ? (string) LL_TOOLS_VERSION : '';
    if ($current_version === '') {
        return;
    }

    $completed_version = (string) get_option(LL_TOOLS_WORDSET_LANGUAGE_SETTINGS_MIGRATION_OPTION, '');
    if ($completed_version !== '' && version_compare($completed_version, $current_version, '>=')) {
        return;
    }

    ll_tools_migrate_legacy_language_settings_to_wordsets();
    update_option(LL_TOOLS_WORDSET_LANGUAGE_SETTINGS_MIGRATION_OPTION, $current_version, false);
}
add_action('admin_init', 'll_tools_maybe_migrate_legacy_language_settings_to_wordsets', 11);
