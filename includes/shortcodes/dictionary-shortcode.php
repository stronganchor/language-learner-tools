<?php
if (!defined('WPINC')) { die; }

function ll_tools_dictionary_shortcode_query_keys(): array {
    return [
        'letter',
        'll_dictionary_q',
        'll_dictionary_scope',
        'll_dictionary_page',
        'll_dictionary_letter',
        'll_dictionary_pos',
        'll_dictionary_source',
        'll_dictionary_dialect',
        'll_dictionary_entry',
    ];
}

function ll_tools_dictionary_enqueue_assets(): void {
    static $script_localized = false;

    if (function_exists('ll_tools_enqueue_public_assets')) {
        ll_tools_enqueue_public_assets();
    }
    ll_enqueue_asset_by_timestamp('/css/dictionary-shortcode.css', 'll-tools-dictionary-shortcode', ['ll-tools-style']);
    ll_enqueue_asset_by_timestamp('/js/dictionary-shortcode.js', 'll-tools-dictionary-shortcode-script', [], true);

    if (!$script_localized && wp_script_is('ll-tools-dictionary-shortcode-script', 'enqueued')) {
        wp_localize_script('ll-tools-dictionary-shortcode-script', 'llToolsDictionary', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ll_tools_dictionary_live_search'),
            'minChars' => 2,
            'debounceMs' => 160,
            'loadingCards' => 3,
            'cacheSize' => 24,
            'loadingLabel' => __('Loading dictionary results...', 'll-tools-text-domain'),
            'toolbarLoadingLabel' => __('Loading dictionary filters...', 'll-tools-text-domain'),
            'entryTitleRequiredLabel' => __('Enter a dictionary entry title.', 'll-tools-text-domain'),
            'entryDefinitionRequiredLabel' => __('Enter a definition.', 'll-tools-text-domain'),
            'entrySavingLabel' => __('Saving...', 'll-tools-text-domain'),
            'entryErrorLabel' => __('Unable to save this dictionary entry right now.', 'll-tools-text-domain'),
        ]);
        $script_localized = true;
    }
}

function ll_tools_dictionary_shortcode_maybe_enqueue_assets(): void {
    if (is_admin() || !is_singular()) {
        return;
    }

    $post = get_queried_object();
    if (!$post instanceof WP_Post || empty($post->post_content)) {
        return;
    }

    $content = (string) $post->post_content;
    $has_shortcode = has_shortcode($content, 'll_dictionary')
        || has_shortcode($content, 'dictionary_search')
        || has_shortcode($content, 'dictionary_browser');
    if (!$has_shortcode) {
        return;
    }

    ll_tools_dictionary_enqueue_assets();
}
add_action('wp_enqueue_scripts', 'll_tools_dictionary_shortcode_maybe_enqueue_assets', 120);

function ll_tools_dictionary_shortcode_resolve_wordset_id($raw_wordset = ''): int {
    $raw_wordset = is_string($raw_wordset) ? trim($raw_wordset) : '';
    if ($raw_wordset !== '' && function_exists('ll_tools_resolve_wordset_term_id')) {
        $resolved = (int) ll_tools_resolve_wordset_term_id($raw_wordset);
        if ($resolved > 0) {
            return $resolved;
        }
    }

    if ($raw_wordset !== '' && is_numeric($raw_wordset)) {
        return (int) $raw_wordset;
    }

    return 0;
}

function ll_tools_dictionary_current_user_can_view_wordset_id(int $wordset_id): bool {
    $wordset_id = max(0, (int) $wordset_id);
    if ($wordset_id <= 0) {
        return true;
    }

    if (!function_exists('ll_tools_user_can_view_wordset')) {
        return true;
    }

    return ll_tools_user_can_view_wordset($wordset_id, (int) get_current_user_id());
}

function ll_tools_dictionary_send_wordset_forbidden_ajax(): void {
    wp_send_json_error([
        'message' => __('You do not have permission to view this dictionary word set.', 'll-tools-text-domain'),
    ], 403);
}

function ll_tools_dictionary_get_current_base_url(): string {
    $base_url = (string) remove_query_arg(ll_tools_dictionary_shortcode_query_keys(), get_pagenum_link(1, false));
    if (function_exists('ll_tools_dictionary_strip_noise_query_args_from_url')) {
        $base_url = ll_tools_dictionary_strip_noise_query_args_from_url($base_url);
    }

    return $base_url;
}

function ll_tools_dictionary_preserve_non_dictionary_query_inputs(): string {
    $exclude = array_flip(ll_tools_dictionary_shortcode_query_keys());
    $html = '';

    foreach ($_GET as $key => $value) {
        if (
            !is_string($key)
            || isset($exclude[$key])
            || (function_exists('ll_tools_dictionary_is_noise_query_key') && ll_tools_dictionary_is_noise_query_key($key))
        ) {
            continue;
        }
        if (is_array($value)) {
            continue;
        }
        $html .= '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr(wp_unslash((string) $value)) . '">';
    }

    return $html;
}

function ll_tools_dictionary_build_url(string $base_url, array $args = []): string {
    $query_args = [];
    foreach ($args as $key => $value) {
        if (!is_string($key)) {
            continue;
        }

        if ($key === 'll_dictionary_scope') {
            $scope_value = ll_tools_dictionary_shortcode_build_scope_query_value($value);
            if ($scope_value === '') {
                continue;
            }

            $query_args[$key] = $scope_value;
            continue;
        }

        $string_value = is_scalar($value) ? trim((string) $value) : '';
        if ($string_value === '' || ($key === 'll_dictionary_page' && (int) $string_value <= 1)) {
            continue;
        }

        $query_args[$key] = $string_value;
    }

    return (string) add_query_arg($query_args, $base_url);
}

/**
 * Resolve the current UI language code from the active LL Tools locale.
 */
function ll_tools_dictionary_shortcode_get_current_ui_language(): string {
    $locale = function_exists('get_locale') ? (string) get_locale() : '';
    if ($locale === '' && function_exists('determine_locale')) {
        $locale = (string) determine_locale();
    }
    if ($locale === '') {
        return '';
    }

    if (function_exists('ll_tools_normalize_switcher_locale_code')) {
        $locale = ll_tools_normalize_switcher_locale_code($locale);
    } else {
        $locale = str_replace('-', '_', trim($locale));
    }

    $language = strtolower((string) strtok($locale, '_'));
    return preg_match('/^[a-z]{2,3}$/', $language) === 1 ? $language : '';
}

/**
 * Resolve which gloss languages should be preferred in dictionary summaries.
 *
 * @return string[]
 */
function ll_tools_dictionary_shortcode_resolve_preferred_languages(int $wordset_id = 0, string $raw_gloss_langs = ''): array {
    $languages = [];

    $parts = preg_split('/[\s,|]+/', trim($raw_gloss_langs), -1, PREG_SPLIT_NO_EMPTY);
    if (is_array($parts)) {
        foreach ($parts as $part) {
            $language_key = function_exists('ll_tools_dictionary_normalize_language_key')
                ? ll_tools_dictionary_normalize_language_key((string) $part)
                : strtolower(trim((string) $part));
            if ($language_key === '' || in_array($language_key, $languages, true)) {
                continue;
            }
            $languages[] = $language_key;
        }
    }

    if (empty($languages)) {
        $ui_language = ll_tools_dictionary_shortcode_get_current_ui_language();
        if ($ui_language !== '') {
            $languages[] = $ui_language;
        }
    }

    if ($wordset_id > 0 && function_exists('ll_tools_get_wordset_translation_language')) {
        $wordset_language = (string) ll_tools_get_wordset_translation_language([$wordset_id]);
        $language_key = function_exists('ll_tools_dictionary_normalize_language_key')
            ? ll_tools_dictionary_normalize_language_key($wordset_language)
            : strtolower(trim($wordset_language));
        if ($language_key !== '' && !in_array($language_key, $languages, true)) {
            $languages[] = $language_key;
        }
    }

    return $languages;
}

/**
 * Resolve visible translation languages from the checked scope UI.
 *
 * @param array<int,string> $search_scopes
 * @return string[]
 */
function ll_tools_dictionary_shortcode_resolve_display_languages(array $search_scopes, int $wordset_id = 0, string $raw_gloss_langs = ''): array {
    $search_scopes = ll_tools_dictionary_shortcode_resolve_search_scopes($search_scopes);
    $scope_languages = function_exists('ll_tools_dictionary_search_scopes_translation_languages')
        ? ll_tools_dictionary_search_scopes_translation_languages($search_scopes)
        : [];
    $preferred_languages = ll_tools_dictionary_shortcode_resolve_preferred_languages($wordset_id, $raw_gloss_langs);

    $resolved_languages = [];
    foreach ($preferred_languages as $language) {
        $language_key = function_exists('ll_tools_dictionary_normalize_language_key')
            ? ll_tools_dictionary_normalize_language_key((string) $language)
            : strtolower(trim((string) $language));
        if ($language_key === '' || in_array($language_key, $resolved_languages, true)) {
            continue;
        }
        if (!empty($scope_languages) && !in_array($language_key, $scope_languages, true)) {
            continue;
        }

        $resolved_languages[] = $language_key;
    }

    foreach ($scope_languages as $language) {
        $language_key = function_exists('ll_tools_dictionary_normalize_language_key')
            ? ll_tools_dictionary_normalize_language_key((string) $language)
            : strtolower(trim((string) $language));
        if ($language_key === '' || in_array($language_key, $resolved_languages, true)) {
            continue;
        }

        $resolved_languages[] = $language_key;
    }

    if (!empty($resolved_languages)) {
        return $resolved_languages;
    }

    return $preferred_languages;
}

/**
 * Resolve the current dictionary search scope from request/query input.
 */
function ll_tools_dictionary_shortcode_resolve_search_scope(string $raw_scope = ''): string {
    if (function_exists('ll_tools_dictionary_normalize_search_scope')) {
        return ll_tools_dictionary_normalize_search_scope($raw_scope);
    }

    $raw_scope = trim(strtolower($raw_scope));
    return $raw_scope !== '' ? $raw_scope : 'all';
}

/**
 * Return search-scope options for the public dictionary toolbar.
 *
 * @return array<int,array{value:string,label:string}>
 */
function ll_tools_dictionary_get_search_scope_options(): array {
    return [
        [
            'value' => 'headword',
            'label' => __('Zazaki', 'll-tools-text-domain'),
        ],
        [
            'value' => 'tr',
            'label' => __('Türkçe', 'll-tools-text-domain'),
        ],
        [
            'value' => 'en',
            'label' => __('English', 'll-tools-text-domain'),
        ],
        [
            'value' => 'de',
            'label' => __('Deutsch', 'll-tools-text-domain'),
        ],
    ];
}

/**
 * Return the ordered set of search scopes exposed by the public checkbox UI.
 *
 * @return string[]
 */
function ll_tools_dictionary_shortcode_get_available_search_scopes(): array {
    return array_values(array_filter(array_map(static function (array $option): string {
        return trim((string) ($option['value'] ?? ''));
    }, ll_tools_dictionary_get_search_scope_options())));
}

/**
 * Resolve one or more submitted dictionary search scopes for the public UI.
 *
 * @param string|string[] $raw_scope
 * @return string[]
 */
function ll_tools_dictionary_shortcode_resolve_search_scopes($raw_scope = []): array {
    $available_scopes = ll_tools_dictionary_shortcode_get_available_search_scopes();
    if (empty($available_scopes)) {
        return ['all'];
    }

    $normalized_scopes = function_exists('ll_tools_dictionary_normalize_search_scopes')
        ? ll_tools_dictionary_normalize_search_scopes($raw_scope)
        : [ll_tools_dictionary_shortcode_resolve_search_scope(is_scalar($raw_scope) ? (string) $raw_scope : '')];

    if (in_array('all', $normalized_scopes, true)) {
        return $available_scopes;
    }

    $selected_scopes = [];
    foreach ($available_scopes as $scope) {
        if (in_array($scope, $normalized_scopes, true)) {
            $selected_scopes[] = $scope;
        }
    }

    return !empty($selected_scopes) ? $selected_scopes : $available_scopes;
}

/**
 * Resolve submitted search scopes from a request array.
 *
 * @param array<string,mixed> $source
 * @return string[]
 */
function ll_tools_dictionary_shortcode_resolve_search_scopes_from_request(array $source): array {
    if (!array_key_exists('ll_dictionary_scope', $source)) {
        return ll_tools_dictionary_shortcode_resolve_search_scopes([]);
    }

    $raw_scope = wp_unslash($source['ll_dictionary_scope']);
    if (is_array($raw_scope)) {
        return ll_tools_dictionary_shortcode_resolve_search_scopes(array_map('sanitize_text_field', array_map('strval', $raw_scope)));
    }

    return ll_tools_dictionary_shortcode_resolve_search_scopes(sanitize_text_field((string) $raw_scope));
}

/**
 * Determine whether the selected scopes match the default "all checked" UI state.
 *
 * @param string[] $search_scopes
 */
function ll_tools_dictionary_shortcode_uses_default_search_scopes(array $search_scopes): bool {
    $available_scopes = ll_tools_dictionary_shortcode_get_available_search_scopes();
    $resolved_scopes = ll_tools_dictionary_shortcode_resolve_search_scopes($search_scopes);

    return count($resolved_scopes) === count($available_scopes)
        && empty(array_diff($available_scopes, $resolved_scopes))
        && empty(array_diff($resolved_scopes, $available_scopes));
}

/**
 * Convert the selected scopes to one compact query-string value.
 *
 * @param string|string[] $search_scopes
 */
function ll_tools_dictionary_shortcode_build_scope_query_value($search_scopes): string {
    $resolved_scopes = ll_tools_dictionary_shortcode_resolve_search_scopes($search_scopes);
    if (ll_tools_dictionary_shortcode_uses_default_search_scopes($resolved_scopes)) {
        return '';
    }

    return implode(',', $resolved_scopes);
}

/**
 * Resolve a requested dictionary entry from the current query string.
 */
function ll_tools_dictionary_shortcode_resolve_requested_entry_id(int $wordset_id = 0): int {
    $raw_entry = isset($_GET['ll_dictionary_entry']) ? sanitize_text_field(wp_unslash((string) $_GET['ll_dictionary_entry'])) : '';
    if ($raw_entry === '' || !ctype_digit($raw_entry)) {
        return 0;
    }

    $entry_id = (int) $raw_entry;
    if ($entry_id <= 0 || !function_exists('ll_tools_is_dictionary_entry_id') || !ll_tools_is_dictionary_entry_id($entry_id)) {
        return 0;
    }

    if (get_post_status($entry_id) !== 'publish') {
        return 0;
    }

    if (function_exists('ll_tools_dictionary_current_user_can_view_entry') && !ll_tools_dictionary_current_user_can_view_entry($entry_id)) {
        return 0;
    }

    if ($wordset_id > 0 && function_exists('ll_tools_dictionary_entry_matches_wordset_context')) {
        if (!ll_tools_dictionary_entry_matches_wordset_context($entry_id, $wordset_id)) {
            return 0;
        }
    }

    return $entry_id;
}

/**
 * Build one canonical entry-detail URL.
 */
function ll_tools_dictionary_build_detail_url(string $base_url, int $entry_id, string $search, array $search_scopes, string $letter, string $pos_slug, int $page, string $source_id = '', string $dialect = ''): string {
    return ll_tools_dictionary_build_url($base_url, [
        'll_dictionary_entry' => (string) $entry_id,
    ]);
}

/**
 * Determine whether the current user can edit one dictionary entry from the public dictionary UI.
 */
function ll_tools_dictionary_user_can_inline_edit_entry(int $entry_id): bool {
    $entry_id = (int) $entry_id;
    if ($entry_id <= 0 || !is_user_logged_in()) {
        return false;
    }

    if (!current_user_can('view_ll_tools')) {
        return false;
    }

    return current_user_can('edit_post', $entry_id);
}

function ll_tools_dictionary_inline_sense_has_content(array $sense): bool {
    foreach ($sense as $key => $value) {
        if ($key === 'translations' || $key === 'dialects') {
            if (!empty($value) && is_array($value)) {
                return true;
            }
            continue;
        }

        if (trim((string) $value) !== '') {
            return true;
        }
    }

    return false;
}

/**
 * Normalize and persist structured senses for one dictionary entry.
 *
 * @param array<int,array<string,mixed>> $senses
 * @return true|WP_Error
 */
function ll_tools_dictionary_persist_entry_senses(int $entry_id, array $senses, array $preferred_languages = []) {
    $entry_id = (int) $entry_id;
    if ($entry_id <= 0 || get_post_type($entry_id) !== 'll_dictionary_entry') {
        return new WP_Error('ll_tools_dictionary_invalid_entry', __('Dictionary entry not found.', 'll-tools-text-domain'));
    }

    $normalized_senses = [];
    foreach ($senses as $sense) {
        if (!is_array($sense)) {
            continue;
        }

        $clean = function_exists('ll_tools_dictionary_sanitize_sense')
            ? ll_tools_dictionary_sanitize_sense($sense)
            : $sense;
        if (ll_tools_dictionary_inline_sense_has_content($clean)) {
            $normalized_senses[] = $clean;
        }
    }

    $content = function_exists('ll_tools_dictionary_build_post_content_from_senses')
        ? ll_tools_dictionary_build_post_content_from_senses($normalized_senses, $preferred_languages)
        : '';
    $translation = function_exists('ll_tools_dictionary_build_translation_summary')
        ? ll_tools_dictionary_build_translation_summary(
            $normalized_senses,
            (string) get_post_meta($entry_id, LL_TOOLS_DICTIONARY_ENTRY_TRANSLATION_META_KEY, true),
            $preferred_languages
        )
        : '';

    if (!empty($normalized_senses)) {
        update_post_meta($entry_id, LL_TOOLS_DICTIONARY_ENTRY_SENSES_META_KEY, $normalized_senses);
    } else {
        delete_post_meta($entry_id, LL_TOOLS_DICTIONARY_ENTRY_SENSES_META_KEY);
    }

    if ($translation !== '') {
        update_post_meta($entry_id, LL_TOOLS_DICTIONARY_ENTRY_TRANSLATION_META_KEY, $translation);
    } else {
        delete_post_meta($entry_id, LL_TOOLS_DICTIONARY_ENTRY_TRANSLATION_META_KEY);
    }

    $primary_entry_type = function_exists('ll_tools_dictionary_get_primary_sense_value')
        ? ll_tools_dictionary_get_primary_sense_value($normalized_senses, 'entry_type')
        : '';
    $primary_page_number = function_exists('ll_tools_dictionary_get_primary_sense_value')
        ? ll_tools_dictionary_get_primary_sense_value($normalized_senses, 'page_number')
        : '';
    $primary_parent = function_exists('ll_tools_dictionary_get_primary_sense_value')
        ? ll_tools_dictionary_get_primary_sense_value($normalized_senses, 'parent')
        : '';
    $primary_gender = function_exists('ll_tools_dictionary_get_primary_sense_value')
        ? ll_tools_dictionary_get_primary_sense_value($normalized_senses, 'gender_number')
        : '';
    $primary_entry_lang = function_exists('ll_tools_dictionary_get_primary_sense_value')
        ? ll_tools_dictionary_get_primary_sense_value($normalized_senses, 'entry_lang')
        : '';
    $primary_def_lang = function_exists('ll_tools_dictionary_get_primary_sense_value')
        ? ll_tools_dictionary_get_primary_sense_value($normalized_senses, 'def_lang')
        : '';
    $primary_review = function_exists('ll_tools_dictionary_get_primary_sense_value')
        ? ll_tools_dictionary_get_primary_sense_value($normalized_senses, 'needs_review')
        : '';
    $pos_slug = function_exists('ll_tools_dictionary_resolve_pos_slug_from_entry_type')
        ? ll_tools_dictionary_resolve_pos_slug_from_entry_type($primary_entry_type)
        : '';

    $meta_updates = [
        LL_TOOLS_DICTIONARY_ENTRY_TYPE_META_KEY => $primary_entry_type,
        LL_TOOLS_DICTIONARY_ENTRY_PAGE_META_KEY => $primary_page_number,
        LL_TOOLS_DICTIONARY_ENTRY_PARENT_META_KEY => $primary_parent,
        LL_TOOLS_DICTIONARY_ENTRY_GENDER_META_KEY => $primary_gender,
        LL_TOOLS_DICTIONARY_ENTRY_ENTRY_LANG_META_KEY => $primary_entry_lang,
        LL_TOOLS_DICTIONARY_ENTRY_DEF_LANG_META_KEY => $primary_def_lang,
        LL_TOOLS_DICTIONARY_ENTRY_REVIEW_META_KEY => $primary_review,
        LL_TOOLS_DICTIONARY_ENTRY_POS_META_KEY => $pos_slug,
    ];

    foreach ($meta_updates as $meta_key => $value) {
        if ($value !== '') {
            update_post_meta($entry_id, $meta_key, $value);
        } else {
            delete_post_meta($entry_id, $meta_key);
        }
    }

    $current_content = (string) get_post_field('post_content', $entry_id);
    if ($content !== $current_content) {
        $updated = wp_update_post([
            'ID' => $entry_id,
            'post_content' => $content,
        ], true);
        if (is_wp_error($updated)) {
            return $updated;
        }
    }

    if (function_exists('ll_tools_dictionary_refresh_entry_search_meta')) {
        ll_tools_dictionary_refresh_entry_search_meta($entry_id);
    }
    if (function_exists('ll_tools_refresh_dictionary_entry_wordset_scope_meta')) {
        ll_tools_refresh_dictionary_entry_wordset_scope_meta($entry_id);
    }
    if (function_exists('ll_tools_dictionary_sync_lookup_rows_for_entry')) {
        ll_tools_dictionary_sync_lookup_rows_for_entry($entry_id);
    } elseif (function_exists('ll_tools_bump_dictionary_browser_cache_version')) {
        ll_tools_bump_dictionary_browser_cache_version();
    }

    clean_post_cache($entry_id);

    return true;
}

function ll_tools_dictionary_build_inline_entry_response(int $entry_id, array $preferred_languages = [], array $extra = []): array {
    $needs_review = function_exists('ll_tools_dictionary_entry_has_review_flag')
        ? ll_tools_dictionary_entry_has_review_flag($entry_id)
        : false;
    $summary = '';
    if (function_exists('ll_tools_dictionary_build_translation_summary') && function_exists('ll_tools_get_dictionary_entry_senses')) {
        $summary = ll_tools_dictionary_build_translation_summary(
            ll_tools_get_dictionary_entry_senses($entry_id),
            trim((string) get_post_meta($entry_id, LL_TOOLS_DICTIONARY_ENTRY_TRANSLATION_META_KEY, true)),
            $preferred_languages
        );
    }
    if ($summary === '') {
        $summary = function_exists('ll_tools_get_dictionary_entry_translation')
            ? ll_tools_get_dictionary_entry_translation($entry_id)
            : trim((string) get_post_meta($entry_id, LL_TOOLS_DICTIONARY_ENTRY_TRANSLATION_META_KEY, true));
    }

    return array_merge([
        'title' => (string) get_the_title($entry_id),
        'summary' => $summary,
        'needs_review' => $needs_review,
        'review_label' => $needs_review
            ? __('Needs review', 'll-tools-text-domain')
            : __('Reviewed', 'll-tools-text-domain'),
    ], $extra);
}

function ll_tools_dictionary_get_editable_sense_language(array $sense, array $preferred_languages = []): string {
    $translations = function_exists('ll_tools_dictionary_get_sense_translations')
        ? ll_tools_dictionary_get_sense_translations($sense)
        : [];

    foreach ($preferred_languages as $language) {
        $language_key = function_exists('ll_tools_dictionary_normalize_language_key')
            ? ll_tools_dictionary_normalize_language_key((string) $language)
            : strtolower(trim((string) $language));
        if ($language_key !== '' && !empty($translations[$language_key])) {
            return $language_key;
        }
    }

    $def_lang = function_exists('ll_tools_dictionary_normalize_language_key')
        ? ll_tools_dictionary_normalize_language_key((string) ($sense['def_lang'] ?? ''))
        : strtolower(trim((string) ($sense['def_lang'] ?? '')));
    if ($def_lang !== '' && !empty($translations[$def_lang])) {
        return $def_lang;
    }

    if (!empty($translations)) {
        $first_language = (string) array_key_first($translations);
        if ($first_language !== '') {
            return $first_language;
        }
    }

    return $def_lang;
}

/**
 * @param array<int,array<string,mixed>> $senses
 * @return array<int,array{language:string,label:string,items:array<int,array{sense_index:int,text:string,language:string}>}>
 */
function ll_tools_dictionary_collect_editable_translation_groups(array $senses, array $preferred_languages = []): array {
    $groups = [];

    foreach ($senses as $sense_index => $sense) {
        if (!is_array($sense)) {
            continue;
        }

        $translations = function_exists('ll_tools_dictionary_get_sense_translations')
            ? ll_tools_dictionary_get_sense_translations($sense)
            : [];
        if (empty($translations)) {
            continue;
        }

        foreach ($translations as $language => $text) {
            $language = function_exists('ll_tools_dictionary_normalize_language_key')
                ? ll_tools_dictionary_normalize_language_key((string) $language)
                : strtolower(trim((string) $language));
            $text = trim((string) $text);
            if ($language === '' || $text === '') {
                continue;
            }

            if (!isset($groups[$language])) {
                $groups[$language] = [
                    'language' => $language,
                    'label' => function_exists('ll_tools_dictionary_get_language_label')
                        ? ll_tools_dictionary_get_language_label($language)
                        : strtoupper($language),
                    'items' => [],
                ];
            }

            $groups[$language]['items'][] = [
                'sense_index' => (int) $sense_index,
                'language' => $language,
                'text' => $text,
            ];
        }
    }

    if (empty($groups)) {
        return [];
    }

    $ordered_languages = [];
    foreach ($preferred_languages as $language) {
        $language = function_exists('ll_tools_dictionary_normalize_language_key')
            ? ll_tools_dictionary_normalize_language_key((string) $language)
            : strtolower(trim((string) $language));
        if ($language !== '' && isset($groups[$language]) && !in_array($language, $ordered_languages, true)) {
            $ordered_languages[] = $language;
        }
    }

    $remaining_languages = array_values(array_diff(array_keys($groups), $ordered_languages));
    usort($remaining_languages, static function (string $left, string $right): int {
        $left_label = function_exists('ll_tools_dictionary_get_language_label')
            ? ll_tools_dictionary_get_language_label($left)
            : strtoupper($left);
        $right_label = function_exists('ll_tools_dictionary_get_language_label')
            ? ll_tools_dictionary_get_language_label($right)
            : strtoupper($right);

        return function_exists('ll_tools_locale_compare_strings')
            ? ll_tools_locale_compare_strings($left_label, $right_label)
            : strnatcasecmp($left_label, $right_label);
    });

    $ordered_groups = [];
    foreach (array_merge($ordered_languages, $remaining_languages) as $language) {
        $ordered_groups[] = $groups[$language];
    }

    return $ordered_groups;
}

function ll_tools_dictionary_render_inline_title_editor(int $entry_id, string $title): string {
    $entry_id = (int) $entry_id;
    if ($entry_id <= 0) {
        return '<h3 class="ll-dictionary__detail-title">' . esc_html($title) . '</h3>';
    }

    $nonce = wp_create_nonce('ll_dictionary_entry_inline_edit_' . $entry_id);
    $input_id = 'll-dictionary-inline-title-' . $entry_id;
    $html = '<div class="ll-dictionary__detail-title-edit ll-dictionary__inline-editor ll-dictionary__inline-editor--title"'
        . ' data-ll-dictionary-inline-editor'
        . ' data-entry-id="' . esc_attr((string) $entry_id) . '"'
        . ' data-action="ll_tools_dictionary_update_entry"'
        . ' data-nonce="' . esc_attr($nonce) . '"'
        . ' data-update-type="title">';
    $html .= '<div class="ll-dictionary__inline-display">';
    $html .= '<h3 class="ll-dictionary__detail-title"><span class="ll-dictionary__detail-title-text ll-dictionary__inline-text" data-ll-dictionary-inline-text>' . esc_html($title) . '</span></h3>';
    $html .= '<button type="button" class="ll-dictionary__inline-edit-button ll-dictionary__inline-edit-button--title" data-ll-dictionary-inline-trigger aria-label="' . esc_attr__('Edit dictionary entry title', 'll-tools-text-domain') . '" title="' . esc_attr__('Edit dictionary entry title', 'll-tools-text-domain') . '">'
        . '<svg viewBox="0 0 24 24" focusable="false" aria-hidden="true">'
        . '<path d="M4 20.5h4l10-10-4-4-10 10v4z" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>'
        . '<path d="M13.5 6.5l4 4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>'
        . '</svg>'
        . '</button>';
    $html .= '</div>';
    $html .= '<div class="ll-dictionary__inline-field" data-ll-dictionary-inline-field hidden>';
    $html .= '<label class="screen-reader-text" for="' . esc_attr($input_id) . '">' . esc_html__('Dictionary entry title', 'll-tools-text-domain') . '</label>';
    $html .= '<input type="text" id="' . esc_attr($input_id) . '" class="ll-dictionary__input ll-dictionary__inline-input ll-dictionary__inline-input--title" data-ll-dictionary-inline-input value="' . esc_attr($title) . '" autocomplete="off" aria-label="' . esc_attr__('Dictionary entry title', 'll-tools-text-domain') . '">';
    $html .= '</div>';
    $html .= '<span class="ll-dictionary__inline-status" data-ll-dictionary-inline-status aria-live="polite" hidden></span>';
    $html .= '</div>';

    return $html;
}

function ll_tools_dictionary_render_inline_definition_editor(int $entry_id, string $value, int $sense_index, string $language = '', string $label = ''): string {
    $entry_id = (int) $entry_id;
    $sense_index = max(0, $sense_index);
    $value = trim((string) $value);
    if ($entry_id <= 0 || $value === '') {
        return '<div class="ll-dictionary__inline-text ll-dictionary__inline-text--definition">' . nl2br(esc_html($value)) . '</div>';
    }

    $nonce = wp_create_nonce('ll_dictionary_entry_inline_edit_' . $entry_id);
    $input_id = 'll-dictionary-inline-definition-' . $entry_id . '-' . $sense_index . '-' . ($language !== '' ? sanitize_html_class($language) : 'default');
    $aria_label = $label !== ''
        ? sprintf(
            /* translators: %s: glossary language label. */
            __('Edit %s definition', 'll-tools-text-domain'),
            $label
        )
        : __('Edit definition', 'll-tools-text-domain');

    $html = '<div class="ll-dictionary__inline-editor ll-dictionary__inline-editor--definition"'
        . ' data-ll-dictionary-inline-editor'
        . ' data-entry-id="' . esc_attr((string) $entry_id) . '"'
        . ' data-action="ll_tools_dictionary_update_entry"'
        . ' data-nonce="' . esc_attr($nonce) . '"'
        . ' data-update-type="sense"'
        . ' data-sense-index="' . esc_attr((string) $sense_index) . '"'
        . ($language !== '' ? ' data-language="' . esc_attr($language) . '"' : '')
        . '>';
    $html .= '<div class="ll-dictionary__inline-display ll-dictionary__inline-display--definition">';
    $html .= '<div class="ll-dictionary__inline-text ll-dictionary__inline-text--definition" data-ll-dictionary-inline-text>' . nl2br(esc_html($value)) . '</div>';
    $html .= '<button type="button" class="ll-dictionary__inline-edit-button ll-dictionary__inline-edit-button--definition" data-ll-dictionary-inline-trigger aria-label="' . esc_attr($aria_label) . '" title="' . esc_attr($aria_label) . '">'
        . '<svg viewBox="0 0 24 24" focusable="false" aria-hidden="true">'
        . '<path d="M4 20.5h4l10-10-4-4-10 10v4z" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>'
        . '<path d="M13.5 6.5l4 4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>'
        . '</svg>'
        . '</button>';
    $html .= '</div>';
    $html .= '<div class="ll-dictionary__inline-field" data-ll-dictionary-inline-field hidden>';
    $html .= '<label class="screen-reader-text" for="' . esc_attr($input_id) . '">' . esc_html($aria_label) . '</label>';
    $html .= '<textarea id="' . esc_attr($input_id) . '" class="ll-dictionary__inline-input ll-dictionary__inline-input--definition" data-ll-dictionary-inline-input rows="2" aria-label="' . esc_attr($aria_label) . '">' . esc_textarea($value) . '</textarea>';
    $html .= '</div>';
    $html .= '<span class="ll-dictionary__inline-status" data-ll-dictionary-inline-status aria-live="polite" hidden></span>';
    $html .= '</div>';

    return $html;
}

function ll_tools_dictionary_render_review_state(int $entry_id, bool $needs_review): string {
    $entry_id = (int) $entry_id;
    if ($entry_id <= 0) {
        return '';
    }

    $nonce = wp_create_nonce('ll_dictionary_entry_inline_edit_' . $entry_id);
    $html = '<div class="ll-dictionary__review-state' . ($needs_review ? ' is-needs-review' : ' is-reviewed') . '"'
        . ' data-ll-dictionary-review-state'
        . ' data-entry-id="' . esc_attr((string) $entry_id) . '"'
        . ' data-action="ll_tools_dictionary_update_entry"'
        . ' data-nonce="' . esc_attr($nonce) . '">';
    $html .= '<span class="ll-dictionary__review-pill' . ($needs_review ? ' is-active' : '') . '" data-ll-dictionary-entry-review-pill>';
    $html .= '<span data-ll-dictionary-entry-review-label>' . esc_html($needs_review ? __('Needs review', 'll-tools-text-domain') : __('Reviewed', 'll-tools-text-domain')) . '</span>';
    $html .= '<button type="button" class="ll-dictionary__review-clear" data-ll-dictionary-review-clear' . ($needs_review ? '' : ' hidden') . ' aria-label="' . esc_attr__('Clear review flag', 'll-tools-text-domain') . '" title="' . esc_attr__('Clear review flag', 'll-tools-text-domain') . '">'
        . '<span aria-hidden="true">&times;</span>'
        . '</button>';
    $html .= '</span>';
    $html .= '<button type="button" class="ll-dictionary__review-mark" data-ll-dictionary-review-mark' . ($needs_review ? ' hidden' : '') . '>' . esc_html__('Mark for review', 'll-tools-text-domain') . '</button>';
    $html .= '<span class="ll-dictionary__inline-status ll-dictionary__inline-status--review" data-ll-dictionary-review-status aria-live="polite" hidden></span>';
    $html .= '</div>';

    return $html;
}

function ll_tools_dictionary_render_detail_admin_meta(int $entry_id, bool $needs_review): string {
    $edit_link = get_edit_post_link($entry_id, '');

    $html = '<div class="ll-dictionary__admin-meta">';
    $html .= ll_tools_dictionary_render_review_state($entry_id, $needs_review);
    if (is_string($edit_link) && $edit_link !== '') {
        $html .= '<a class="ll-dictionary__admin-link" href="' . esc_url($edit_link) . '">'
            . '<span class="ll-dictionary__admin-link-icon" aria-hidden="true">'
            . '<svg viewBox="0 0 24 24" focusable="false" aria-hidden="true">'
            . '<path d="M4 20.5h4l10-10-4-4-10 10v4z" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>'
            . '<path d="M13.5 6.5l4 4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>'
            . '</svg>'
            . '</span>'
            . '<span>' . esc_html__('Open in admin', 'll-tools-text-domain') . '</span>'
            . '</a>';
    }
    $html .= '</div>';

    return $html;
}

/**
 * Collect all visible translations grouped by language.
 *
 * @param array<int,array<string,mixed>> $senses
 * @param array<int,string> $preferred_languages
 * @return array<int,array{language:string,label:string,values:array<int,string>}>
 */
function ll_tools_dictionary_collect_translation_groups(array $senses, array $preferred_languages = []): array {
    $values_by_language = [];

    foreach ($senses as $sense) {
        if (!is_array($sense)) {
            continue;
        }

        $translations = function_exists('ll_tools_dictionary_get_sense_translations')
            ? ll_tools_dictionary_get_sense_translations($sense)
            : [];
        if (empty($translations)) {
            $definition = trim((string) ($sense['definition'] ?? ''));
            $language = function_exists('ll_tools_dictionary_normalize_language_key')
                ? ll_tools_dictionary_normalize_language_key((string) ($sense['def_lang'] ?? ''))
                : strtolower(trim((string) ($sense['def_lang'] ?? '')));
            if ($definition !== '' && $language !== '') {
                $translations = [$language => $definition];
            }
        }

        foreach ($translations as $language => $text) {
            $language = function_exists('ll_tools_dictionary_normalize_language_key')
                ? ll_tools_dictionary_normalize_language_key((string) $language)
                : strtolower(trim((string) $language));
            $text = trim((string) $text);
            if ($language === '' || $text === '') {
                continue;
            }

            $lookup = function_exists('ll_tools_dictionary_entry_normalize_lookup_value')
                ? ll_tools_dictionary_entry_normalize_lookup_value($text)
                : strtolower($text);
            if ($lookup === '' || isset($values_by_language[$language][$lookup])) {
                continue;
            }
            $values_by_language[$language][$lookup] = $text;
        }
    }

    if (empty($values_by_language)) {
        return [];
    }

    $ordered_languages = [];
    foreach ($preferred_languages as $language) {
        $language = function_exists('ll_tools_dictionary_normalize_language_key')
            ? ll_tools_dictionary_normalize_language_key((string) $language)
            : strtolower(trim((string) $language));
        if ($language !== '' && isset($values_by_language[$language]) && !in_array($language, $ordered_languages, true)) {
            $ordered_languages[] = $language;
        }
    }

    $remaining_languages = array_values(array_diff(array_keys($values_by_language), $ordered_languages));
    usort($remaining_languages, static function (string $left, string $right): int {
        $left_label = function_exists('ll_tools_dictionary_get_language_label')
            ? ll_tools_dictionary_get_language_label($left)
            : strtoupper($left);
        $right_label = function_exists('ll_tools_dictionary_get_language_label')
            ? ll_tools_dictionary_get_language_label($right)
            : strtoupper($right);

        return function_exists('ll_tools_locale_compare_strings')
            ? ll_tools_locale_compare_strings($left_label, $right_label)
            : strnatcasecmp($left_label, $right_label);
    });

    $groups = [];
    foreach (array_merge($ordered_languages, $remaining_languages) as $language) {
        $groups[] = [
            'language' => $language,
            'label' => function_exists('ll_tools_dictionary_get_language_label')
                ? ll_tools_dictionary_get_language_label($language)
                : strtoupper($language),
            'values' => array_values($values_by_language[$language]),
        ];
    }

    return $groups;
}

/**
 * Keep only the translation groups for the requested display languages.
 *
 * @param array<int,array{language:string,label:string,values:array<int,string>}> $translation_groups
 * @param array<int,string> $visible_languages
 * @return array<int,array{language:string,label:string,values:array<int,string>}>
 */
function ll_tools_dictionary_filter_translation_groups(array $translation_groups, array $visible_languages = []): array {
    $visible_lookup = [];
    foreach ($visible_languages as $language) {
        $language_key = function_exists('ll_tools_dictionary_normalize_language_key')
            ? ll_tools_dictionary_normalize_language_key((string) $language)
            : strtolower(trim((string) $language));
        if ($language_key === '') {
            continue;
        }

        $visible_lookup[$language_key] = true;
    }

    if (empty($visible_lookup)) {
        return [];
    }

    return array_values(array_filter($translation_groups, static function (array $group) use ($visible_lookup): bool {
        $language = trim((string) ($group['language'] ?? ''));
        return $language !== '' && !empty($visible_lookup[$language]);
    }));
}

/**
 * Keep only editable translation groups for the requested display languages.
 *
 * @param array<int,array{language:string,label:string,items:array<int,array{sense_index:int,text:string,language:string}>}> $translation_groups
 * @param array<int,string> $visible_languages
 * @return array<int,array{language:string,label:string,items:array<int,array{sense_index:int,text:string,language:string}>}>
 */
function ll_tools_dictionary_filter_editable_translation_groups(array $translation_groups, array $visible_languages = []): array {
    $visible_lookup = [];
    foreach ($visible_languages as $language) {
        $language_key = function_exists('ll_tools_dictionary_normalize_language_key')
            ? ll_tools_dictionary_normalize_language_key((string) $language)
            : strtolower(trim((string) $language));
        if ($language_key === '') {
            continue;
        }

        $visible_lookup[$language_key] = true;
    }

    if (empty($visible_lookup)) {
        return [];
    }

    return array_values(array_filter($translation_groups, static function (array $group) use ($visible_lookup): bool {
        $language = trim((string) ($group['language'] ?? ''));
        return $language !== '' && !empty($visible_lookup[$language]);
    }));
}

/**
 * Collect source-dictionary labels used by an entry.
 *
 * @param array<int,array<string,mixed>> $senses
 * @return string[]
 */
function ll_tools_dictionary_collect_source_labels(array $senses): array {
    $labels = [];
    foreach ((array) (function_exists('ll_tools_dictionary_collect_sources') ? ll_tools_dictionary_collect_sources($senses) : []) as $source) {
        $label = trim((string) ($source['label'] ?? ''));
        if ($label !== '') {
            $labels[] = $label;
        }
    }

    return array_values(array_unique($labels));
}

/**
 * Collect dialect labels used by an entry.
 *
 * @param array<int,array<string,mixed>> $senses
 * @return string[]
 */
function ll_tools_dictionary_collect_entry_dialects(array $senses): array {
    return function_exists('ll_tools_dictionary_collect_dialects')
        ? ll_tools_dictionary_collect_dialects($senses)
        : [];
}

/**
 * Return published words linked to one dictionary entry.
 *
 * @return int[]
 */
function ll_tools_dictionary_get_public_word_ids_for_entry(int $entry_id, int $limit = -1): array {
    $entry_id = (int) $entry_id;
    if ($entry_id <= 0 || !function_exists('ll_tools_is_dictionary_entry_id') || !ll_tools_is_dictionary_entry_id($entry_id)) {
        return [];
    }

    if (function_exists('ll_tools_get_dictionary_entry_word_ids')) {
        $linked_ids = array_values(array_filter(array_map('intval', ll_tools_get_dictionary_entry_word_ids($entry_id, -1)), static function (int $word_id): bool {
            return $word_id > 0 && get_post_status($word_id) === 'publish';
        }));
        if (!empty($linked_ids)) {
            if ($limit > 0) {
                return array_slice($linked_ids, 0, $limit);
            }
            return $linked_ids;
        }
    }

    $query_args = [
        'post_type' => 'words',
        'post_status' => 'publish',
        'posts_per_page' => $limit === 0 ? -1 : $limit,
        'fields' => 'ids',
        'orderby' => 'title',
        'order' => 'ASC',
        'no_found_rows' => true,
        'suppress_filters' => true,
        'meta_query' => [[
            'key' => LL_TOOLS_WORD_DICTIONARY_ENTRY_META_KEY,
            'value' => (string) $entry_id,
            'compare' => '=',
        ]],
    ];

    $word_ids = array_values(array_filter(array_map('intval', (array) get_posts($query_args))));
    if (!empty($word_ids)) {
        return $word_ids;
    }

    global $wpdb;

    $sql = "
        SELECT p.ID
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->postmeta} pm
            ON pm.post_id = p.ID
           AND pm.meta_key = %s
        WHERE p.post_type = 'words'
          AND p.post_status = 'publish'
          AND pm.meta_value = %s
        ORDER BY p.post_title ASC
    ";
    $params = [
        LL_TOOLS_WORD_DICTIONARY_ENTRY_META_KEY,
        (string) $entry_id,
    ];
    if ($limit > 0) {
        $sql .= ' LIMIT %d';
        $params[] = $limit;
    }

    return array_values(array_filter(array_map('intval', (array) $wpdb->get_col($wpdb->prepare($sql, $params)))));
}

/**
 * Build related-entry suggestions for one dictionary entry.
 *
 * @param array<int,string> $preferred_languages
 * @return array<int,array<string,mixed>>
 */
function ll_tools_dictionary_collect_related_entries(int $entry_id, array $preferred_languages = [], int $limit = 6): array {
    static $request_cache = [];

    $entry_id = (int) $entry_id;
    if ($entry_id <= 0 || !function_exists('ll_tools_is_dictionary_entry_id') || !ll_tools_is_dictionary_entry_id($entry_id)) {
        return [];
    }

    $normalized_preferred_languages = [];
    foreach ($preferred_languages as $language) {
        $language_key = function_exists('ll_tools_dictionary_normalize_language_key')
            ? ll_tools_dictionary_normalize_language_key((string) $language)
            : strtolower(trim((string) $language));
        if ($language_key === '' || in_array($language_key, $normalized_preferred_languages, true)) {
            continue;
        }
        $normalized_preferred_languages[] = $language_key;
    }

    $cache_args = [
        'entry_id' => $entry_id,
        'preferred_languages' => $normalized_preferred_languages,
        'limit' => max(1, $limit),
    ];
    if (function_exists('ll_tools_dictionary_browser_get_cached_payload')) {
        $cached = ll_tools_dictionary_browser_get_cached_payload('related_entries', $cache_args, $request_cache);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $title = trim((string) get_the_title($entry_id));
    $title_norm = function_exists('ll_tools_dictionary_normalize_search_text')
        ? ll_tools_dictionary_normalize_search_text($title)
        : strtolower($title);
    if ($title_norm === '') {
        return [];
    }

    $current_wordset_id = function_exists('ll_tools_get_dictionary_entry_wordset_id')
        ? (int) ll_tools_get_dictionary_entry_wordset_id($entry_id)
        : 0;
    $candidate_ids = [];
    $search_queries = [
        [
            'search' => $title,
            'page' => 1,
            'per_page' => 40,
            'sense_limit' => 1,
            'linked_word_limit' => 0,
            'preferred_languages' => $preferred_languages,
            'post_status' => ['publish'],
        ],
    ];

    if ($current_wordset_id > 0) {
        $search_queries[] = [
            'letter' => function_exists('mb_substr') ? (string) mb_substr($title, 0, 1, 'UTF-8') : substr($title, 0, 1),
            'wordset_id' => $current_wordset_id,
            'page' => 1,
            'per_page' => 40,
            'sense_limit' => 1,
            'linked_word_limit' => 0,
            'preferred_languages' => $preferred_languages,
            'post_status' => ['publish'],
        ];
    }

    foreach ($search_queries as $query_args) {
        $query = function_exists('ll_tools_dictionary_query_entries')
            ? ll_tools_dictionary_query_entries($query_args)
            : ['items' => []];
        foreach ((array) ($query['items'] ?? []) as $item) {
            $candidate_id = isset($item['id']) ? (int) $item['id'] : 0;
            if ($candidate_id > 0 && $candidate_id !== $entry_id) {
                $candidate_ids[$candidate_id] = true;
            }
        }
    }

    if (empty($candidate_ids)) {
        return [];
    }

    $related = [];
    foreach (array_keys($candidate_ids) as $candidate_id) {
        $candidate_id = (int) $candidate_id;
        if ($candidate_id <= 0) {
            continue;
        }

        $candidate_title = trim((string) get_the_title($candidate_id));
        $candidate_norm = function_exists('ll_tools_dictionary_normalize_search_text')
            ? ll_tools_dictionary_normalize_search_text($candidate_title)
            : strtolower($candidate_title);
        if ($candidate_norm === '') {
            continue;
        }

        $score = 0;
        $candidate_wordset_id = function_exists('ll_tools_get_dictionary_entry_wordset_id')
            ? (int) ll_tools_get_dictionary_entry_wordset_id($candidate_id)
            : 0;

        if ($candidate_norm === $title_norm) {
            $score += 140;
        }

        if ($candidate_norm !== $title_norm && (strpos($candidate_norm, $title_norm) !== false || strpos($title_norm, $candidate_norm) !== false)) {
            $score += 90;
        }

        similar_text($title_norm, $candidate_norm, $percent);
        if ($percent >= 72.0) {
            $score += (int) round($percent / 2);
        }

        $ascii_title = preg_replace('/[^a-z]/', '', $title_norm) ?? '';
        $ascii_candidate = preg_replace('/[^a-z]/', '', $candidate_norm) ?? '';
        if ($ascii_title !== '' && $ascii_candidate !== '' && function_exists('metaphone') && metaphone($ascii_title) === metaphone($ascii_candidate)) {
            $score += 20;
        }

        if ($current_wordset_id > 0 && $candidate_wordset_id === $current_wordset_id) {
            $score += 10;
        }

        if ($score <= 0) {
            continue;
        }

        $item = function_exists('ll_tools_dictionary_get_entry_data')
            ? ll_tools_dictionary_get_entry_data($candidate_id, 1, 0, $preferred_languages)
            : [];
        if (empty($item)) {
            continue;
        }

        $item['related_score'] = $score;
        $related[] = $item;
    }

    usort($related, static function (array $left, array $right): int {
        $left_score = (int) ($left['related_score'] ?? 0);
        $right_score = (int) ($right['related_score'] ?? 0);
        if ($left_score !== $right_score) {
            return $right_score <=> $left_score;
        }

        $left_title = (string) ($left['title'] ?? '');
        $right_title = (string) ($right['title'] ?? '');
        return function_exists('ll_tools_locale_compare_strings')
            ? ll_tools_locale_compare_strings($left_title, $right_title)
            : strnatcasecmp($left_title, $right_title);
    });

    $related = array_slice($related, 0, max(1, $limit));
    if (function_exists('ll_tools_dictionary_browser_store_cached_payload')) {
        return ll_tools_dictionary_browser_store_cached_payload(
            'related_entries',
            $cache_args,
            $related,
            10 * MINUTE_IN_SECONDS,
            $request_cache
        );
    }

    return $related;
}

function ll_tools_dictionary_render_badge(string $text, string $modifier = '', string $url = ''): string {
    $modifier = sanitize_html_class($modifier);
    $classes = 'll-dictionary__badge';
    if ($modifier !== '') {
        $classes .= ' ll-dictionary__badge--' . $modifier;
    }

    $content = esc_html($text);
    if ($url !== '') {
        $classes .= ' ll-dictionary__badge--external';
        $content .= '<span class="ll-dictionary__badge-icon" aria-hidden="true">&#8599;</span>';

        $aria_label = sprintf(
            /* translators: %s: badge label */
            __('Open link for %s', 'll-tools-text-domain'),
            $text
        );

        return '<a class="' . esc_attr($classes) . '" href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer" aria-label="' . esc_attr($aria_label) . '">' . $content . '</a>';
    }

    return '<span class="' . esc_attr($classes) . '">' . $content . '</span>';
}

function ll_tools_dictionary_measure_text_length(string $text): int {
    return function_exists('mb_strlen')
        ? (int) mb_strlen($text, 'UTF-8')
        : strlen($text);
}

function ll_tools_dictionary_render_text_block(string $text, string $modifier = '', int $collapse_threshold = 0): string {
    $text = trim((string) $text);
    if ($text === '') {
        return '';
    }

    $modifier = sanitize_html_class($modifier);
    $classes = 'll-dictionary__text-block';
    $content_classes = 'll-dictionary__text-block-content';
    if ($modifier !== '') {
        $classes .= ' ll-dictionary__text-block--' . $modifier;
        $content_classes .= ' ll-dictionary__text-block-content--' . $modifier;
    }

    $content = nl2br(esc_html($text));
    $should_collapse = $collapse_threshold > 0 && ll_tools_dictionary_measure_text_length($text) > $collapse_threshold;
    if (!$should_collapse) {
        return '<div class="' . esc_attr($classes) . '"><div class="' . esc_attr($content_classes) . '">' . $content . '</div></div>';
    }

    return '<div class="' . esc_attr($classes) . ' is-collapsed" data-ll-dictionary-text-block>'
        . '<div class="' . esc_attr($content_classes) . '">' . $content . '</div>'
        . '<button type="button" class="ll-dictionary__text-toggle" data-ll-dictionary-toggle'
        . '" data-expand-label="' . esc_attr__('Show more', 'll-tools-text-domain') . '"'
        . ' data-collapse-label="' . esc_attr__('Show less', 'll-tools-text-domain') . '"'
        . ' aria-expanded="false">'
        . esc_html__('Show more', 'll-tools-text-domain')
        . '</button></div>';
}

/**
 * @param array<int,array{language:string,label:string,values:array<int,string>}> $translation_groups
 */
function ll_tools_dictionary_render_translation_groups(array $translation_groups): string {
    if (empty($translation_groups)) {
        return '';
    }

    $html = '<div class="ll-dictionary__translation-groups">';
    foreach ($translation_groups as $group) {
        $label = trim((string) ($group['label'] ?? ''));
        $values = array_values(array_filter(array_map('strval', (array) ($group['values'] ?? [])), static function (string $value): bool {
            return trim($value) !== '';
        }));
        if ($label === '' || empty($values)) {
            continue;
        }

        $html .= '<article class="ll-dictionary__translation-group">';
        $html .= '<div class="ll-dictionary__translation-label">' . esc_html($label) . '</div>';
        $html .= '<div class="ll-dictionary__translation-values">';
        $html .= ll_tools_dictionary_render_text_block(implode('; ', $values), 'translation', 220);
        $html .= '</div></article>';
    }
    $html .= '</div>';

    return $html;
}

/**
 * @param array<int,array{language:string,label:string,items:array<int,array{sense_index:int,text:string,language:string}>}> $translation_groups
 */
function ll_tools_dictionary_render_editable_translation_groups(int $entry_id, array $translation_groups): string {
    if ($entry_id <= 0 || empty($translation_groups)) {
        return '';
    }

    $html = '<div class="ll-dictionary__translation-groups">';
    foreach ($translation_groups as $group) {
        $label = trim((string) ($group['label'] ?? ''));
        $items = isset($group['items']) && is_array($group['items']) ? array_values($group['items']) : [];
        if ($label === '' || empty($items)) {
            continue;
        }

        $html .= '<article class="ll-dictionary__translation-group">';
        $html .= '<div class="ll-dictionary__translation-label">' . esc_html($label) . '</div>';
        $html .= '<div class="ll-dictionary__translation-values">';
        foreach ($items as $item) {
            $text = trim((string) ($item['text'] ?? ''));
            $sense_index = isset($item['sense_index']) ? (int) $item['sense_index'] : -1;
            $language = trim((string) ($item['language'] ?? ''));
            if ($text === '' || $sense_index < 0) {
                continue;
            }

            $html .= '<div class="ll-dictionary__translation-chip ll-dictionary__translation-chip--editable">';
            $html .= ll_tools_dictionary_render_inline_definition_editor($entry_id, $text, $sense_index, $language, $label);
            $html .= '</div>';
        }
        $html .= '</div></article>';
    }
    $html .= '</div>';

    return $html;
}

/**
 * @param array<int,array<string,mixed>> $senses
 * @return string[]
 */
function ll_tools_dictionary_collect_parent_notes(array $senses): array {
    $notes = [];
    $seen = [];

    foreach ($senses as $sense) {
        if (!is_array($sense)) {
            continue;
        }

        $parent = trim((string) ($sense['parent'] ?? ''));
        if ($parent === '') {
            continue;
        }

        $note = sprintf(
            /* translators: %s: parent dictionary headword */
            __('Parent: %s', 'll-tools-text-domain'),
            $parent
        );
        $lookup = function_exists('ll_tools_dictionary_entry_normalize_lookup_value')
            ? ll_tools_dictionary_entry_normalize_lookup_value($note)
            : strtolower($note);
        if ($lookup === '' || isset($seen[$lookup])) {
            continue;
        }

        $seen[$lookup] = true;
        $notes[] = $note;
    }

    return $notes;
}

function ll_tools_dictionary_should_render_entry_type_badge(string $entry_type, string $pos_slug = '', string $pos_label = ''): bool {
    $entry_type = trim((string) $entry_type);
    if ($entry_type === '') {
        return false;
    }

    $pos_slug = sanitize_title((string) $pos_slug);
    $pos_label = trim((string) $pos_label);

    if (function_exists('ll_tools_dictionary_resolve_pos_slug_from_entry_type')) {
        $entry_type_slug = ll_tools_dictionary_resolve_pos_slug_from_entry_type($entry_type);
        if ($entry_type_slug !== '' && $pos_slug !== '' && $entry_type_slug === $pos_slug) {
            return false;
        }
    }

    $normalize = static function (string $value): string {
        return function_exists('ll_tools_dictionary_normalize_search_text')
            ? ll_tools_dictionary_normalize_search_text($value)
            : strtolower(trim($value));
    };

    $entry_lookup = $normalize($entry_type);
    $pos_lookup = $normalize($pos_label);
    if ($entry_lookup !== '' && $pos_lookup !== '' && $entry_lookup === $pos_lookup) {
        return false;
    }

    return true;
}

/**
 * @param array<string,mixed> $item
 */
function ll_tools_dictionary_render_result_card(array $item, string $detail_url = ''): string {
    $title = trim((string) ($item['title'] ?? ''));
    $translation = trim((string) ($item['translation'] ?? ''));
    $pos_slug = sanitize_title((string) ($item['pos_slug'] ?? ''));
    $pos_label = trim((string) ($item['pos_label'] ?? ''));
    $entry_type = trim((string) ($item['entry_type'] ?? ''));
    $wordset_name = trim((string) ($item['wordset_name'] ?? ''));
    $wordset_names = array_values(array_filter(array_map('strval', (array) ($item['wordset_names'] ?? []))));
    $sense_count = max(0, (int) ($item['sense_count'] ?? 0));
    $linked_word_count = max(0, (int) ($item['linked_word_count'] ?? 0));
    $senses = (array) ($item['senses'] ?? []);
    $linked_words = (array) ($item['linked_words'] ?? []);
    $sources = array_values(array_filter((array) ($item['sources'] ?? []), 'is_array'));
    $dialects = array_values(array_filter(array_map('strval', (array) ($item['dialects'] ?? []))));
    $preferred_languages = array_values(array_filter(array_map('strval', (array) ($item['preferred_languages'] ?? []))));
    $visible_translation_groups = ll_tools_dictionary_filter_translation_groups(
        array_values(array_filter((array) ($item['translation_groups'] ?? []), 'is_array')),
        $preferred_languages
    );

    if (empty($wordset_names) && $wordset_name !== '') {
        $wordset_names[] = $wordset_name;
    }

    $html = '<article class="ll-dictionary__card">';
    $html .= '<div class="ll-dictionary__card-head">';
    $html .= '<div class="ll-dictionary__title-wrap">';
    $html .= '<h3 class="ll-dictionary__title">';
    if ($detail_url !== '') {
        $html .= '<a class="ll-dictionary__title-link" href="' . esc_url($detail_url) . '">' . esc_html($title) . '</a>';
    } else {
        $html .= esc_html($title);
    }
    $html .= '</h3>';
    if (empty($visible_translation_groups) && $translation !== '') {
        $html .= '<div class="ll-dictionary__summary">' . ll_tools_dictionary_render_text_block($translation, 'summary', 220) . '</div>';
    }
    $html .= '</div>';
    $html .= '<div class="ll-dictionary__badges">';
    if ($pos_label !== '') {
        $html .= ll_tools_dictionary_render_badge($pos_label, 'pos');
    }
    if (ll_tools_dictionary_should_render_entry_type_badge($entry_type, $pos_slug, $pos_label)) {
        $html .= ll_tools_dictionary_render_badge($entry_type, 'type');
    }
    foreach ($wordset_names as $name) {
        $name = trim((string) $name);
        if ($name === '') {
            continue;
        }
        $html .= ll_tools_dictionary_render_badge($name, 'wordset');
    }
    if ($linked_word_count > 0) {
        $html .= ll_tools_dictionary_render_badge(
            sprintf(
                /* translators: %d: linked word count */
                _n('%d linked word', '%d linked words', $linked_word_count, 'll-tools-text-domain'),
                $linked_word_count
            ),
            'linked'
        );
    }
    foreach ($sources as $source) {
        $label = trim((string) ($source['label'] ?? ''));
        if ($label === '') {
            continue;
        }
        $html .= ll_tools_dictionary_render_badge($label, 'source', (string) ($source['attribution_url'] ?? ''));
    }
    foreach ($dialects as $dialect) {
        if ($dialect === '') {
            continue;
        }
        $html .= ll_tools_dictionary_render_badge($dialect, 'dialect');
    }
    $html .= '</div></div>';

    if (!empty($visible_translation_groups)) {
        $html .= '<div class="ll-dictionary__card-translations">' . ll_tools_dictionary_render_translation_groups($visible_translation_groups) . '</div>';
    }

    if (!empty($senses)) {
        $summary_lookup = $translation !== '' && function_exists('ll_tools_dictionary_entry_normalize_lookup_value')
            ? ll_tools_dictionary_entry_normalize_lookup_value($translation)
            : strtolower($translation);
        $rendered_sense_count = 0;
        $summary_replaced_sense = 0;
        $sense_items_html = '';
        foreach ($senses as $sense) {
            if (!is_array($sense)) {
                continue;
            }

            $definition = function_exists('ll_tools_dictionary_get_preferred_translation_text')
                ? ll_tools_dictionary_get_preferred_translation_text($sense, $preferred_languages, true)
                : trim((string) ($sense['definition'] ?? ''));
            $sense_type = trim((string) ($sense['entry_type'] ?? ''));
            $gender = trim((string) ($sense['gender_number'] ?? ''));
            $parent = trim((string) ($sense['parent'] ?? ''));
            if ($definition === '') {
                continue;
            }
            $definition_lookup = function_exists('ll_tools_dictionary_entry_normalize_lookup_value')
                ? ll_tools_dictionary_entry_normalize_lookup_value($definition)
                : strtolower($definition);
            if ($summary_lookup !== '' && $definition_lookup !== '' && $definition_lookup === $summary_lookup) {
                $summary_replaced_sense = 1;
                continue;
            }

            $meta_parts = [];
            if ($sense_type !== '') {
                $meta_parts[] = $sense_type;
            }
            if ($gender !== '') {
                $meta_parts[] = $gender;
            }
            if ($parent !== '') {
                $meta_parts[] = sprintf(
                    /* translators: %s: parent headword */
                    __('Parent: %s', 'll-tools-text-domain'),
                    $parent
                );
            }
            $sense_items_html .= '<li class="ll-dictionary__sense-item">';
            $sense_items_html .= ll_tools_dictionary_render_text_block($definition, 'sense', 240);
            if (!empty($meta_parts)) {
                $sense_items_html .= '<span class="ll-dictionary__sense-meta">' . esc_html(implode(' • ', $meta_parts)) . '</span>';
            }
            $sense_items_html .= '</li>';
            $rendered_sense_count++;
        }
        if ($sense_items_html !== '') {
            $html .= '<ol class="ll-dictionary__sense-list">' . $sense_items_html . '</ol>';
        }
        $hidden_sense_count = max(0, $sense_count - $rendered_sense_count - $summary_replaced_sense);
        if ($hidden_sense_count > 0) {
            $html .= '<p class="ll-dictionary__more">';
            $html .= esc_html(sprintf(
                /* translators: %d: number of hidden senses */
                _n('+ %d more sense', '+ %d more senses', $hidden_sense_count, 'll-tools-text-domain'),
                $hidden_sense_count
            ));
            $html .= '</p>';
        }
    }

    if (!empty($linked_words)) {
        $html .= '<div class="ll-dictionary__linked">';
        foreach ($linked_words as $word) {
            if (!is_array($word)) {
                continue;
            }
            $word_text = trim((string) ($word['word_text'] ?? ''));
            $translation_text = trim((string) ($word['translation_text'] ?? ''));
            $wordset_text = trim((string) ($word['wordset_name'] ?? ''));
            if ($word_text === '') {
                continue;
            }
            $html .= '<span class="ll-dictionary__chip">';
            $html .= '<span class="ll-dictionary__chip-word">' . esc_html($word_text) . '</span>';
            if ($translation_text !== '') {
                $html .= '<span class="ll-dictionary__chip-translation">' . esc_html($translation_text) . '</span>';
            }
            if ($wordset_text !== '') {
                $html .= '<span class="ll-dictionary__chip-translation">' . esc_html($wordset_text) . '</span>';
            }
            $html .= '</span>';
        }
        $html .= '</div>';
    }

    $html .= '</article>';

    return $html;
}

/**
 * Render one dictionary entry detail view.
 */
function ll_tools_dictionary_render_detail_view(int $entry_id, string $base_url, array $preferred_languages = []): string {
    $entry = function_exists('ll_tools_dictionary_get_entry_data')
        ? ll_tools_dictionary_get_entry_data($entry_id, 12, 8, $preferred_languages)
        : [];
    if (empty($entry)) {
        return '<div class="ll-dictionary__empty"><p>' . esc_html__('That dictionary entry could not be found.', 'll-tools-text-domain') . '</p></div>';
    }

    $title = trim((string) ($entry['title'] ?? ''));
    $summary = trim((string) ($entry['translation'] ?? ''));
    $senses = array_values(array_filter((array) ($entry['senses'] ?? []), 'is_array'));
    if (function_exists('ll_tools_get_dictionary_entry_senses')) {
        $senses = ll_tools_get_dictionary_entry_senses($entry_id);
    }
    $can_inline_edit = ll_tools_dictionary_user_can_inline_edit_entry($entry_id);
    $translation_groups = ll_tools_dictionary_filter_translation_groups(
        ll_tools_dictionary_collect_translation_groups($senses, $preferred_languages),
        $preferred_languages
    );
    $editable_translation_groups = $can_inline_edit
        ? ll_tools_dictionary_filter_editable_translation_groups(
            ll_tools_dictionary_collect_editable_translation_groups($senses, $preferred_languages),
            $preferred_languages
        )
        : [];
    $parent_notes = ll_tools_dictionary_collect_parent_notes($senses);
    $sources = function_exists('ll_tools_dictionary_collect_sources') ? ll_tools_dictionary_collect_sources($senses) : [];
    $dialects = ll_tools_dictionary_collect_entry_dialects($senses);
    $needs_review = function_exists('ll_tools_dictionary_entry_has_review_flag')
        ? ll_tools_dictionary_entry_has_review_flag($entry_id)
        : false;
    $wordset_names = array_values(array_filter(array_map('strval', (array) ($entry['wordset_names'] ?? []))));
    $word_ids = function_exists('ll_tools_get_dictionary_entry_word_ids')
        ? array_values(array_filter(array_map('intval', ll_tools_get_dictionary_entry_word_ids($entry_id, -1)), static function (int $word_id): bool {
            return $word_id > 0 && get_post_status($word_id) === 'publish';
        }))
        : ll_tools_dictionary_get_public_word_ids_for_entry($entry_id, -1);
    $related_entries = ll_tools_dictionary_collect_related_entries($entry_id, $preferred_languages, 6);

    $back_url = ll_tools_dictionary_build_url($base_url, [
        'll_dictionary_q' => isset($_GET['ll_dictionary_q']) ? sanitize_text_field(wp_unslash((string) $_GET['ll_dictionary_q'])) : '',
        'll_dictionary_scope' => ll_tools_dictionary_shortcode_resolve_search_scopes_from_request($_GET),
        'll_dictionary_letter' => isset($_GET['ll_dictionary_letter']) ? sanitize_text_field(wp_unslash((string) $_GET['ll_dictionary_letter'])) : '',
        'll_dictionary_pos' => isset($_GET['ll_dictionary_pos']) ? sanitize_text_field(wp_unslash((string) $_GET['ll_dictionary_pos'])) : '',
        'll_dictionary_source' => isset($_GET['ll_dictionary_source']) ? sanitize_text_field(wp_unslash((string) $_GET['ll_dictionary_source'])) : '',
        'll_dictionary_dialect' => isset($_GET['ll_dictionary_dialect']) ? sanitize_text_field(wp_unslash((string) $_GET['ll_dictionary_dialect'])) : '',
        'll_dictionary_page' => isset($_GET['ll_dictionary_page']) ? (string) max(1, (int) wp_unslash((string) $_GET['ll_dictionary_page'])) : '',
    ]);

    $html = '<article class="ll-dictionary__detail">';
    $html .= '<div class="ll-dictionary__detail-top">';
    $html .= '<a class="ll-dictionary__back" href="' . esc_url($back_url) . '">' . esc_html__('Back to dictionary', 'll-tools-text-domain') . '</a>';
    $html .= '</div>';

    $html .= '<header class="ll-dictionary__detail-header">';
    $html .= '<div class="ll-dictionary__detail-heading-wrap">';
    if ($can_inline_edit) {
        $html .= ll_tools_dictionary_render_inline_title_editor($entry_id, $title);
    } else {
        $html .= '<h3 class="ll-dictionary__detail-title">' . esc_html($title) . '</h3>';
    }
    if (empty($translation_groups) && ($summary !== '' || $can_inline_edit)) {
        $html .= '<p class="ll-dictionary__detail-summary" data-ll-dictionary-summary' . ($summary === '' ? ' hidden' : '') . '>' . esc_html($summary) . '</p>';
    }
    $html .= '</div>';
    $html .= '<div class="ll-dictionary__detail-side">';
    if ($can_inline_edit) {
        $html .= ll_tools_dictionary_render_detail_admin_meta($entry_id, $needs_review);
    }
    $html .= '<div class="ll-dictionary__badges">';
    if (!empty($entry['pos_label'])) {
        $html .= ll_tools_dictionary_render_badge((string) $entry['pos_label'], 'pos');
    }
    if (ll_tools_dictionary_should_render_entry_type_badge(
        (string) ($entry['entry_type'] ?? ''),
        (string) ($entry['pos_slug'] ?? ''),
        (string) ($entry['pos_label'] ?? '')
    )) {
        $html .= ll_tools_dictionary_render_badge((string) $entry['entry_type'], 'type');
    }
    foreach ($wordset_names as $wordset_name) {
        $wordset_name = trim((string) $wordset_name);
        if ($wordset_name === '') {
            continue;
        }
        $html .= ll_tools_dictionary_render_badge($wordset_name, 'wordset');
    }
    foreach ($sources as $source) {
        $label = trim((string) ($source['label'] ?? ''));
        if ($label === '') {
            continue;
        }
        $html .= ll_tools_dictionary_render_badge($label, 'source', (string) ($source['attribution_url'] ?? ''));
    }
    foreach ($dialects as $dialect) {
        if ($dialect === '') {
            continue;
        }
        $html .= ll_tools_dictionary_render_badge($dialect, 'dialect');
    }
    $html .= '</div>';
    $html .= '</div>';
    $html .= '</header>';

    if (!empty($translation_groups)) {
        $html .= '<section class="ll-dictionary__detail-section">';
        $html .= '<h4 class="ll-dictionary__section-title">' . esc_html__('Definitions', 'll-tools-text-domain') . '</h4>';
        $html .= $can_inline_edit
            ? ll_tools_dictionary_render_editable_translation_groups($entry_id, $editable_translation_groups)
            : ll_tools_dictionary_render_translation_groups($translation_groups);
        if (!empty($parent_notes)) {
            $html .= '<div class="ll-dictionary__detail-notes">';
            foreach ($parent_notes as $note) {
                $html .= '<p class="ll-dictionary__detail-note">' . esc_html($note) . '</p>';
            }
            $html .= '</div>';
        }
        $html .= '</section>';
    }

    if (empty($translation_groups) && !empty($senses)) {
        $html .= '<section class="ll-dictionary__detail-section">';
        $html .= '<h4 class="ll-dictionary__section-title">' . esc_html__('Senses', 'll-tools-text-domain') . '</h4>';
        $html .= '<ol class="ll-dictionary__sense-list ll-dictionary__sense-list--detail">';
        foreach ($senses as $sense_index => $sense) {
            $sense_text = function_exists('ll_tools_dictionary_get_preferred_translation_text')
                ? ll_tools_dictionary_get_preferred_translation_text((array) $sense, $preferred_languages, true)
                : trim((string) ($sense['definition'] ?? ''));
            if ($sense_text === '') {
                continue;
            }

            $meta_parts = [];
            foreach (['entry_type', 'gender_number'] as $field) {
                $value = trim((string) ($sense[$field] ?? ''));
                if ($value !== '') {
                    $meta_parts[] = $value;
                }
            }
            if (!empty($sense['parent'])) {
                $meta_parts[] = sprintf(__('Parent: %s', 'll-tools-text-domain'), (string) $sense['parent']);
            }
            foreach ((array) (function_exists('ll_tools_dictionary_get_sense_dialects') ? ll_tools_dictionary_get_sense_dialects((array) $sense) : []) as $dialect) {
                $dialect = trim((string) $dialect);
                if ($dialect !== '') {
                    $meta_parts[] = $dialect;
                }
            }

            $detail_translations = [];
            foreach ((array) (function_exists('ll_tools_dictionary_get_sense_translations') ? ll_tools_dictionary_get_sense_translations((array) $sense) : []) as $language => $value) {
                $value = trim((string) $value);
                if ($value === '') {
                    continue;
                }
                $detail_translations[] = '<span class="ll-dictionary__sense-translation">'
                    . '<span class="ll-dictionary__sense-lang">' . esc_html(function_exists('ll_tools_dictionary_get_language_label') ? ll_tools_dictionary_get_language_label((string) $language) : strtoupper((string) $language)) . '</span>'
                    . '<span class="ll-dictionary__sense-value">' . esc_html($value) . '</span>'
                    . '</span>';
            }

            $html .= '<li class="ll-dictionary__sense-item">';
            if ($can_inline_edit) {
                $html .= ll_tools_dictionary_render_inline_definition_editor(
                    $entry_id,
                    $sense_text,
                    (int) $sense_index,
                    ll_tools_dictionary_get_editable_sense_language((array) $sense, $preferred_languages),
                    __('Definition', 'll-tools-text-domain')
                );
            } else {
                $html .= '<span class="ll-dictionary__sense-text">' . esc_html($sense_text) . '</span>';
            }
            if (!empty($detail_translations)) {
                $html .= '<span class="ll-dictionary__sense-translations">' . implode('', $detail_translations) . '</span>';
            }
            if (!empty($meta_parts)) {
                $html .= '<span class="ll-dictionary__sense-meta">' . esc_html(implode(' • ', $meta_parts)) . '</span>';
            }
            $html .= '</li>';
        }
        $html .= '</ol></section>';
    }

    if (!empty($sources)) {
        $html .= '<section class="ll-dictionary__detail-section">';
        $html .= '<h4 class="ll-dictionary__section-title">' . esc_html__('Sources', 'll-tools-text-domain') . '</h4>';
        foreach ($sources as $source) {
            $label = trim((string) ($source['label'] ?? ''));
            $attribution_text = trim((string) ($source['attribution_text'] ?? ''));
            $attribution_url = trim((string) ($source['attribution_url'] ?? ''));
            if ($label === '') {
                continue;
            }

            $html .= '<div class="ll-dictionary__source-item">';
            $html .= '<div class="ll-dictionary__source-heading">';
            $html .= ll_tools_dictionary_render_badge($label, 'source', $attribution_url);
            $html .= '</div>';
            if ($attribution_text !== '') {
                $html .= '<p class="ll-dictionary__source-copy">' . esc_html($attribution_text) . '</p>';
            }
            if ($attribution_url !== '') {
                $html .= '<p class="ll-dictionary__source-copy"><a href="' . esc_url($attribution_url) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('View source page', 'll-tools-text-domain') . '</a></p>';
            }
            $html .= '</div>';
        }
        $html .= '</section>';
    }

    if (!empty($word_ids)) {
        $html .= '<section class="ll-dictionary__detail-section">';
        $html .= '<h4 class="ll-dictionary__section-title">' . esc_html__('Linked Words', 'll-tools-text-domain') . '</h4>';
        $shortcode = '[word_grid word_ids="' . esc_attr(implode(',', $word_ids)) . '"]';
        $html .= do_shortcode($shortcode);
        $html .= '</section>';
    }

    if (!empty($related_entries)) {
        $html .= '<section class="ll-dictionary__detail-section">';
        $html .= '<h4 class="ll-dictionary__section-title">' . esc_html__('Related Entries', 'll-tools-text-domain') . '</h4>';
        $html .= '<div class="ll-dictionary__related-list">';
        foreach ($related_entries as $related_entry) {
            $related_id = isset($related_entry['id']) ? (int) $related_entry['id'] : 0;
            if ($related_id <= 0) {
                continue;
            }
            $related_url = ll_tools_dictionary_build_detail_url(
                $base_url,
                $related_id,
                isset($_GET['ll_dictionary_q']) ? sanitize_text_field(wp_unslash((string) $_GET['ll_dictionary_q'])) : '',
                ll_tools_dictionary_shortcode_resolve_search_scopes_from_request($_GET),
                isset($_GET['ll_dictionary_letter']) ? sanitize_text_field(wp_unslash((string) $_GET['ll_dictionary_letter'])) : '',
                isset($_GET['ll_dictionary_pos']) ? sanitize_text_field(wp_unslash((string) $_GET['ll_dictionary_pos'])) : '',
                isset($_GET['ll_dictionary_page']) ? max(1, (int) wp_unslash((string) $_GET['ll_dictionary_page'])) : 1,
                isset($_GET['ll_dictionary_source']) ? sanitize_text_field(wp_unslash((string) $_GET['ll_dictionary_source'])) : '',
                isset($_GET['ll_dictionary_dialect']) ? sanitize_text_field(wp_unslash((string) $_GET['ll_dictionary_dialect'])) : ''
            );
            $html .= '<a class="ll-dictionary__related-card" href="' . esc_url($related_url) . '">';
            $html .= '<span class="ll-dictionary__related-title">' . esc_html((string) ($related_entry['title'] ?? '')) . '</span>';
            if (!empty($related_entry['translation'])) {
                $html .= '<span class="ll-dictionary__related-summary">' . esc_html((string) $related_entry['translation']) . '</span>';
            }
            $html .= '</a>';
        }
        $html .= '</div></section>';
    }

    $html .= '</article>';

    return $html;
}

/**
 * @param array<string,mixed> $query
 */
function ll_tools_dictionary_render_pagination(array $query, string $base_url, string $search, array $search_scopes, string $letter, string $pos_slug, string $source_id = '', string $dialect = ''): string {
    $search_scopes = ll_tools_dictionary_shortcode_resolve_search_scopes($search_scopes);
    $page = max(1, (int) ($query['page'] ?? 1));
    $total_pages = max(1, (int) ($query['total_pages'] ?? 1));
    if ($total_pages <= 1) {
        return '';
    }

    $html = '<nav class="ll-dictionary__pagination" aria-label="' . esc_attr__('Dictionary pagination', 'll-tools-text-domain') . '">';

    $prev_url = ll_tools_dictionary_build_url($base_url, [
        'll_dictionary_q' => $search,
        'll_dictionary_scope' => $search_scopes,
        'll_dictionary_letter' => $letter,
        'll_dictionary_pos' => $pos_slug,
        'll_dictionary_source' => $source_id,
        'll_dictionary_dialect' => $dialect,
        'll_dictionary_page' => (string) max(1, $page - 1),
    ]);
    $next_url = ll_tools_dictionary_build_url($base_url, [
        'll_dictionary_q' => $search,
        'll_dictionary_scope' => $search_scopes,
        'll_dictionary_letter' => $letter,
        'll_dictionary_pos' => $pos_slug,
        'll_dictionary_source' => $source_id,
        'll_dictionary_dialect' => $dialect,
        'll_dictionary_page' => (string) min($total_pages, $page + 1),
    ]);

    $html .= '<a class="ll-dictionary__page-button' . ($page <= 1 ? ' is-disabled' : '') . '" href="' . esc_url($page <= 1 ? '#' : $prev_url) . '"' . ($page <= 1 ? ' tabindex="-1" aria-disabled="true"' : '') . '>' . esc_html__('Previous', 'll-tools-text-domain') . '</a>';

    $start = max(1, $page - 2);
    $end = min($total_pages, $page + 2);
    if ($start > 1) {
        $start = max(1, min($start, $total_pages - 4));
        $end = min($total_pages, max($end, $start + 4));
    }

    for ($current = $start; $current <= $end; $current++) {
        $url = ll_tools_dictionary_build_url($base_url, [
            'll_dictionary_q' => $search,
            'll_dictionary_scope' => $search_scopes,
            'll_dictionary_letter' => $letter,
            'll_dictionary_pos' => $pos_slug,
            'll_dictionary_source' => $source_id,
            'll_dictionary_dialect' => $dialect,
            'll_dictionary_page' => (string) $current,
        ]);
        $active = ($current === $page) ? ' is-active' : '';
        $html .= '<a class="ll-dictionary__page-number' . $active . '" href="' . esc_url($url) . '">' . esc_html((string) $current) . '</a>';
    }

    $html .= '<a class="ll-dictionary__page-button' . ($page >= $total_pages ? ' is-disabled' : '') . '" href="' . esc_url($page >= $total_pages ? '#' : $next_url) . '"' . ($page >= $total_pages ? ' tabindex="-1" aria-disabled="true"' : '') . '>' . esc_html__('Next', 'll-tools-text-domain') . '</a>';
    $html .= '</nav>';

    return $html;
}

/**
 * Run one dictionary browse/search query with normalized limits.
 *
 * @param array<int,string> $preferred_languages
 * @return array<string,mixed>
 */
function ll_tools_dictionary_run_browse_query(
    int $wordset_id,
    string $search,
    array $search_scopes,
    string $letter,
    int $page,
    string $pos_slug,
    string $source_id,
    string $dialect,
    int $per_page,
    int $sense_limit,
    int $linked_word_limit,
    array $preferred_languages = [],
    array $query_limits = []
): array {
    if (!function_exists('ll_tools_dictionary_query_entries')) {
        return [
            'items' => [],
            'total' => 0,
            'page' => 1,
            'per_page' => max(1, $per_page),
            'total_pages' => 1,
        ];
    }

    $search_scopes = ll_tools_dictionary_shortcode_resolve_search_scopes($search_scopes);
    $candidate_scan_limit = max(0, (int) ($query_limits['candidate_scan_limit'] ?? 0));
    $result_depth_limit = max(0, (int) ($query_limits['result_depth_limit'] ?? 0));

    $started = microtime(true);
    $query_args = [
        'search' => $search,
        'search_scopes' => $search_scopes,
        'letter' => $letter,
        'page' => max(1, $page),
        'per_page' => max(1, $per_page),
        'wordset_id' => max(0, $wordset_id),
        'pos_slug' => $pos_slug,
        'source_id' => $source_id,
        'dialect' => $dialect,
        'sense_limit' => max(1, $sense_limit),
        'linked_word_limit' => max(0, $linked_word_limit),
        'preferred_languages' => $preferred_languages,
        'post_status' => ['publish'],
    ];
    if ($candidate_scan_limit > 0) {
        $query_args['candidate_scan_limit'] = $candidate_scan_limit;
    }
    if ($result_depth_limit > 0) {
        $query_args['result_depth_limit'] = $result_depth_limit;
    }

    $query = ll_tools_dictionary_query_entries($query_args);

    if (function_exists('ll_tools_dictionary_static_cache_debug_log')) {
        ll_tools_dictionary_static_cache_debug_log('browse_query', [
            'elapsed_ms' => round((microtime(true) - $started) * 1000, 2),
            'search' => $search !== '',
            'letter' => $letter,
            'page' => (int) ($query['page'] ?? $page),
            'total' => (int) ($query['total'] ?? 0),
        ]);
    }

    return $query;
}

/**
 * Render the browse-results region used by both the shortcode and live AJAX search.
 */
function ll_tools_dictionary_render_browse_results(array $query, string $base_url, string $search, array $search_scopes, string $letter, string $pos_slug, string $source_id = '', string $dialect = ''): string {
    $search_scopes = ll_tools_dictionary_shortcode_resolve_search_scopes($search_scopes);
    $items = (array) ($query['items'] ?? []);
    $total = max(0, (int) ($query['total'] ?? 0));
    $current_page = max(1, (int) ($query['page'] ?? 1));
    $per_page = max(1, (int) ($query['per_page'] ?? 20));
    $start_index = $total > 0 ? (($current_page - 1) * $per_page) + 1 : 0;
    $end_index = $total > 0 ? min($total, $start_index + count($items) - 1) : 0;

    ob_start();
    if ($total > 0) : ?>
        <div class="ll-dictionary__meta">
            <p class="ll-dictionary__count">
                <?php
                echo esc_html(sprintf(
                    /* translators: 1: first visible result number, 2: last visible result number, 3: total result count */
                    __('Showing %1$d-%2$d of %3$d', 'll-tools-text-domain'),
                    $start_index,
                    $end_index,
                    $total
                ));
                ?>
            </p>
        </div>
    <?php else : ?>
        <div class="ll-dictionary__meta">
            <p class="ll-dictionary__count"><?php esc_html_e('No entries found.', 'll-tools-text-domain'); ?></p>
        </div>
    <?php endif;

    if (!empty($items)) : ?>
        <div class="ll-dictionary__results">
            <?php foreach ($items as $item) : ?>
                <?php
                $entry_id = isset($item['id']) ? (int) $item['id'] : 0;
                $detail_url = $entry_id > 0
                    ? ll_tools_dictionary_build_detail_url($base_url, $entry_id, $search, $search_scopes, $letter, $pos_slug, $current_page, $source_id, $dialect)
                    : '';
                ?>
                <?php echo ll_tools_dictionary_render_result_card((array) $item, $detail_url); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php endforeach; ?>
        </div>
        <?php echo ll_tools_dictionary_render_pagination($query, $base_url, $search, $search_scopes, $letter, $pos_slug, $source_id, $dialect); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
    <?php else : ?>
        <div class="ll-dictionary__empty">
            <?php if ($search !== '') : ?>
                <p><?php esc_html_e('Try a shorter query, another spelling, or switch to letter browsing.', 'll-tools-text-domain'); ?></p>
            <?php else : ?>
                <p><?php esc_html_e('No entries matched this filter yet.', 'll-tools-text-domain'); ?></p>
            <?php endif; ?>
        </div>
    <?php endif;

    return (string) ob_get_clean();
}

/**
 * Resolve a safe base URL for live dictionary responses.
 */
function ll_tools_dictionary_resolve_live_base_url(string $raw_base_url = ''): string {
    $base_url = trim(esc_url_raw($raw_base_url));
    if ($base_url === '') {
        $base_url = (string) wp_get_referer();
    }
    if ($base_url === '') {
        return home_url('/');
    }

    $base_url = (string) remove_query_arg(ll_tools_dictionary_shortcode_query_keys(), $base_url);
    if (function_exists('ll_tools_dictionary_strip_noise_query_args_from_url')) {
        $base_url = ll_tools_dictionary_strip_noise_query_args_from_url($base_url);
    }

    return $base_url;
}

function ll_tools_dictionary_ajax_cache_ttl(): int {
    $ttl = (int) apply_filters('ll_tools_dictionary_ajax_cache_ttl', 10 * MINUTE_IN_SECONDS);
    return max(60, $ttl);
}

function ll_tools_dictionary_ajax_cache_enabled(): bool {
    return !is_user_logged_in();
}

function ll_tools_dictionary_ajax_cache_locale(): string {
    if (function_exists('determine_locale')) {
        return (string) determine_locale();
    }

    return function_exists('get_locale') ? (string) get_locale() : '';
}

function ll_tools_dictionary_send_ajax_cache_header(string $status): void {
    if (!headers_sent()) {
        header('X-LL-Dictionary-Ajax-Cache: ' . strtoupper($status));
    }
}

function ll_tools_dictionary_ajax_cache_args(array $args): array {
    $args['locale'] = ll_tools_dictionary_ajax_cache_locale();
    $args['plugin_version'] = defined('LL_TOOLS_VERSION') ? (string) LL_TOOLS_VERSION : '';
    ksort($args, SORT_STRING);

    return $args;
}

function ll_tools_dictionary_ajax_cache_get(string $namespace, array $args) {
    static $request_cache = [];

    if (!ll_tools_dictionary_ajax_cache_enabled() || !function_exists('ll_tools_dictionary_browser_get_cached_payload')) {
        return null;
    }

    $cached = ll_tools_dictionary_browser_get_cached_payload(
        'ajax_' . sanitize_key($namespace),
        ll_tools_dictionary_ajax_cache_args($args),
        $request_cache
    );

    return is_array($cached) ? $cached : null;
}

function ll_tools_dictionary_ajax_cache_set(string $namespace, array $args, array $payload): void {
    static $request_cache = [];

    if (!ll_tools_dictionary_ajax_cache_enabled() || !function_exists('ll_tools_dictionary_browser_store_cached_payload')) {
        return;
    }

    ll_tools_dictionary_browser_store_cached_payload(
        'ajax_' . sanitize_key($namespace),
        ll_tools_dictionary_ajax_cache_args($args),
        $payload,
        ll_tools_dictionary_ajax_cache_ttl(),
        $request_cache
    );
}

function ll_tools_dictionary_live_search_min_chars(): int {
    return max(1, (int) apply_filters('ll_tools_dictionary_live_search_min_chars', 2));
}

function ll_tools_dictionary_anonymous_live_search_page_cap(): int {
    return max(1, (int) apply_filters('ll_tools_dictionary_anonymous_live_search_page_cap', 500));
}

function ll_tools_dictionary_anonymous_live_search_result_depth_cap(): int {
    $cap = (int) apply_filters('ll_tools_dictionary_anonymous_live_search_result_depth_cap', 600);
    return $cap > 0 ? max(1, $cap) : 0;
}

function ll_tools_dictionary_anonymous_live_search_candidate_scan_cap(): int {
    $cap = (int) apply_filters(
        'll_tools_dictionary_anonymous_live_search_candidate_scan_cap',
        ll_tools_dictionary_anonymous_live_search_result_depth_cap()
    );

    return $cap > 0 ? max(1, $cap) : 0;
}

function ll_tools_dictionary_clamp_page_to_result_depth(int $page, int $per_page, int $result_depth_cap): int {
    $page = max(1, $page);
    $per_page = max(1, $per_page);
    $result_depth_cap = max(0, $result_depth_cap);
    if ($result_depth_cap <= 0) {
        return $page;
    }

    return min($page, max(1, intdiv($result_depth_cap, $per_page)));
}

function ll_tools_dictionary_live_search_length(string $search): int {
    if (function_exists('mb_strlen')) {
        return (int) mb_strlen($search, 'UTF-8');
    }

    return strlen($search);
}

function ll_tools_dictionary_build_toolbar_panel_context(int $wordset_id, string $source_id = '', string $dialect = ''): array {
    $letters = function_exists('ll_tools_dictionary_get_available_letters')
        ? ll_tools_dictionary_get_available_letters($wordset_id)
        : [];
    $pos_options = function_exists('ll_tools_dictionary_get_pos_filter_options')
        ? ll_tools_dictionary_get_pos_filter_options($wordset_id)
        : [];
    $source_options = function_exists('ll_tools_dictionary_get_source_filter_options')
        ? ll_tools_dictionary_get_source_filter_options($wordset_id)
        : [];
    $dialect_options = function_exists('ll_tools_dictionary_get_dialect_filter_options')
        ? ll_tools_dictionary_get_dialect_filter_options($wordset_id)
        : [];

    if ($source_id !== '') {
        $has_selected_source = false;
        foreach ($source_options as $option) {
            $option_id = function_exists('ll_tools_dictionary_normalize_source_id')
                ? ll_tools_dictionary_normalize_source_id((string) ($option['id'] ?? ''))
                : sanitize_title((string) ($option['id'] ?? ''));
            if ($option_id === $source_id) {
                $has_selected_source = true;
                break;
            }
        }
        if (!$has_selected_source) {
            $source_options[] = [
                'id' => $source_id,
                'label' => $source_id,
            ];
        }
    }

    if ($dialect !== '' && !in_array($dialect, $dialect_options, true)) {
        $dialect_options[] = $dialect;
        usort($dialect_options, static function (string $left, string $right): int {
            return function_exists('ll_tools_locale_compare_strings')
                ? ll_tools_locale_compare_strings($left, $right)
                : strnatcasecmp($left, $right);
        });
    }

    return [
        'letters' => $letters,
        'pos_options' => $pos_options,
        'source_options' => $source_options,
        'dialect_options' => $dialect_options,
    ];
}

function ll_tools_dictionary_render_toolbar_panel(
    string $base_url,
    int $wordset_id,
    array $search_scopes = ['headword', 'tr', 'en', 'de'],
    string $letter = '',
    string $pos_slug = '',
    string $source_id = '',
    string $dialect = ''
): string {
    $search_scopes = ll_tools_dictionary_shortcode_resolve_search_scopes($search_scopes);
    $context = ll_tools_dictionary_build_toolbar_panel_context($wordset_id, $source_id, $dialect);
    $letters = (isset($context['letters']) && is_array($context['letters'])) ? $context['letters'] : [];
    $pos_options = (isset($context['pos_options']) && is_array($context['pos_options'])) ? $context['pos_options'] : [];
    $source_options = (isset($context['source_options']) && is_array($context['source_options'])) ? $context['source_options'] : [];
    $dialect_options = (isset($context['dialect_options']) && is_array($context['dialect_options'])) ? $context['dialect_options'] : [];

    ob_start();
    ?>
    <div class="ll-dictionary__toolbar-panel" data-ll-dictionary-toolbar-panel>
        <?php if (!empty($pos_options) || !empty($source_options) || !empty($dialect_options)) : ?>
            <div class="ll-dictionary__filters">
                <?php if (!empty($pos_options)) : ?>
                    <div class="ll-dictionary__field ll-dictionary__field--select">
                        <label class="screen-reader-text" for="ll-dictionary-pos"><?php esc_html_e('Filter by part of speech', 'll-tools-text-domain'); ?></label>
                        <select id="ll-dictionary-pos" class="ll-dictionary__select" name="ll_dictionary_pos">
                            <option value=""><?php esc_html_e('All types', 'll-tools-text-domain'); ?></option>
                            <?php foreach ($pos_options as $option) : ?>
                                <option value="<?php echo esc_attr((string) $option['slug']); ?>" <?php selected($pos_slug, (string) $option['slug']); ?>>
                                    <?php echo esc_html((string) $option['label']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
                <?php if (!empty($source_options)) : ?>
                    <div class="ll-dictionary__field ll-dictionary__field--select">
                        <label class="screen-reader-text" for="ll-dictionary-source"><?php esc_html_e('Filter by source dictionary', 'll-tools-text-domain'); ?></label>
                        <select id="ll-dictionary-source" class="ll-dictionary__select" name="ll_dictionary_source">
                            <option value=""><?php esc_html_e('All sources', 'll-tools-text-domain'); ?></option>
                            <?php foreach ($source_options as $option) : ?>
                                <option value="<?php echo esc_attr((string) $option['id']); ?>" <?php selected($source_id, (string) $option['id']); ?>>
                                    <?php echo esc_html((string) $option['label']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
                <?php if (!empty($dialect_options)) : ?>
                    <div class="ll-dictionary__field ll-dictionary__field--select">
                        <label class="screen-reader-text" for="ll-dictionary-dialect"><?php esc_html_e('Filter by dialect', 'll-tools-text-domain'); ?></label>
                        <select id="ll-dictionary-dialect" class="ll-dictionary__select" name="ll_dictionary_dialect">
                            <option value=""><?php esc_html_e('All dialects', 'll-tools-text-domain'); ?></option>
                            <?php foreach ($dialect_options as $option) : ?>
                                <option value="<?php echo esc_attr((string) $option); ?>" <?php selected($dialect, (string) $option); ?>>
                                    <?php echo esc_html((string) $option); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <p class="ll-dictionary__hint"><?php esc_html_e('Type to search, or open the alphabet below.', 'll-tools-text-domain'); ?></p>
        <?php if (!empty($letters)) : ?>
            <nav class="ll-dictionary__letters" aria-label="<?php echo esc_attr__('Browse dictionary by letter', 'll-tools-text-domain'); ?>">
                <?php foreach ($letters as $browse_letter) : ?>
                    <?php
                    $browse_url = ll_tools_dictionary_build_url($base_url, [
                        'll_dictionary_scope' => $search_scopes,
                        'll_dictionary_letter' => (string) $browse_letter,
                        'll_dictionary_pos' => $pos_slug,
                        'll_dictionary_source' => $source_id,
                        'll_dictionary_dialect' => $dialect,
                    ]);
                    ?>
                    <a class="ll-dictionary__letter<?php echo $browse_letter === $letter ? ' is-active' : ''; ?>" href="<?php echo esc_url($browse_url); ?>">
                        <?php echo esc_html((string) $browse_letter); ?>
                    </a>
                <?php endforeach; ?>
            </nav>
        <?php endif; ?>
    </div>
    <?php

    return (string) ob_get_clean();
}

/**
 * Handle admin-only inline dictionary entry edits from the public dictionary UI.
 */
function ll_tools_dictionary_handle_entry_update(): void {
    $entry_id = isset($_POST['entry_id']) ? (int) wp_unslash((string) $_POST['entry_id']) : 0;
    $nonce = isset($_POST['nonce']) ? (string) wp_unslash((string) $_POST['nonce']) : '';
    $update_type = isset($_POST['update_type']) ? sanitize_key(wp_unslash((string) $_POST['update_type'])) : 'title';
    $wordset_id = isset($_POST['wordset_id']) ? max(0, (int) wp_unslash((string) $_POST['wordset_id'])) : 0;
    $gloss_lang = isset($_POST['gloss_lang']) ? sanitize_text_field(wp_unslash((string) $_POST['gloss_lang'])) : '';
    $search_scopes = ll_tools_dictionary_shortcode_resolve_search_scopes_from_request($_POST);
    $preferred_languages = ll_tools_dictionary_shortcode_resolve_display_languages($search_scopes, $wordset_id, $gloss_lang);
    $submitted_title = isset($_POST['title']) ? (string) wp_unslash((string) $_POST['title']) : '';
    $submitted_value = isset($_POST['value']) ? (string) wp_unslash((string) $_POST['value']) : '';
    $sense_index = isset($_POST['sense_index']) ? max(0, (int) wp_unslash((string) $_POST['sense_index'])) : -1;
    $language = isset($_POST['language']) ? sanitize_text_field(wp_unslash((string) $_POST['language'])) : '';
    $raw_needs_review = isset($_POST['needs_review']) ? strtolower(trim((string) wp_unslash((string) $_POST['needs_review']))) : '';
    $needs_review = in_array($raw_needs_review, ['1', 'true', 'yes', 'on', 'needs_review'], true);

    if ($entry_id <= 0) {
        wp_send_json_error([
            'message' => __('Missing dictionary entry.', 'll-tools-text-domain'),
        ], 400);
    }

    if ($nonce === '' || !wp_verify_nonce($nonce, 'll_dictionary_entry_inline_edit_' . $entry_id)) {
        wp_send_json_error([
            'message' => __('Invalid request.', 'll-tools-text-domain'),
        ], 403);
    }

    if (!ll_tools_dictionary_user_can_inline_edit_entry($entry_id)) {
        wp_send_json_error([
            'message' => __('You do not have permission to edit this dictionary entry.', 'll-tools-text-domain'),
        ], 403);
    }

    $entry = get_post($entry_id);
    if (!$entry instanceof WP_Post || $entry->post_type !== 'll_dictionary_entry') {
        wp_send_json_error([
            'message' => __('Dictionary entry not found.', 'll-tools-text-domain'),
        ], 404);
    }

    if ($update_type === 'title') {
        $title = sanitize_text_field($submitted_title);
        $title = trim((string) preg_replace('/\s+/u', ' ', $title));
        if ($title === '') {
            wp_send_json_error([
                'message' => __('Enter a dictionary entry title.', 'll-tools-text-domain'),
            ], 400);
        }

        $current_title = trim((string) get_the_title($entry_id));
        if ($title !== $current_title) {
            $updated = wp_update_post([
                'ID' => $entry_id,
                'post_title' => $title,
                'post_name' => (string) $entry->post_name,
            ], true);

            if (is_wp_error($updated)) {
                wp_send_json_error([
                    'message' => __('Unable to save this dictionary entry right now.', 'll-tools-text-domain'),
                ], 500);
            }
        }

        clean_post_cache($entry_id);

        wp_send_json_success(array_merge(
            [
                'message' => __('Dictionary entry updated.', 'll-tools-text-domain'),
            ],
            ll_tools_dictionary_build_inline_entry_response($entry_id, $preferred_languages)
        ));
    }

    if ($update_type === 'review') {
        if (function_exists('ll_tools_dictionary_entry_set_review_flag')) {
            ll_tools_dictionary_entry_set_review_flag($entry_id, $needs_review);
        } else {
            $review_value = $needs_review ? 'needs_review' : '';
            if ($review_value !== '') {
                update_post_meta($entry_id, LL_TOOLS_DICTIONARY_ENTRY_REVIEW_META_KEY, $review_value);
            } else {
                delete_post_meta($entry_id, LL_TOOLS_DICTIONARY_ENTRY_REVIEW_META_KEY);
            }
            if (function_exists('ll_tools_bump_dictionary_browser_cache_version')) {
                ll_tools_bump_dictionary_browser_cache_version();
            }
        }

        clean_post_cache($entry_id);

        wp_send_json_success(array_merge(
            [
                'message' => __('Dictionary entry updated.', 'll-tools-text-domain'),
            ],
            ll_tools_dictionary_build_inline_entry_response($entry_id, $preferred_languages)
        ));
    }

    if ($update_type === 'sense') {
        $value = trim(sanitize_textarea_field($submitted_value));
        if ($value === '') {
            wp_send_json_error([
                'message' => __('Enter a definition.', 'll-tools-text-domain'),
            ], 400);
        }

        $senses = function_exists('ll_tools_get_dictionary_entry_senses')
            ? ll_tools_get_dictionary_entry_senses($entry_id)
            : [];
        if (!isset($senses[$sense_index]) || !is_array($senses[$sense_index])) {
            wp_send_json_error([
                'message' => __('Dictionary definition not found.', 'll-tools-text-domain'),
            ], 404);
        }

        $sense = $senses[$sense_index];
        $language = function_exists('ll_tools_dictionary_normalize_language_key')
            ? ll_tools_dictionary_normalize_language_key($language)
            : strtolower(trim($language));
        if ($language === '') {
            $language = ll_tools_dictionary_get_editable_sense_language($sense, $preferred_languages);
        }
        if ($language === '') {
            $language = function_exists('ll_tools_dictionary_normalize_language_key')
                ? ll_tools_dictionary_normalize_language_key((string) ($sense['def_lang'] ?? ''))
                : strtolower(trim((string) ($sense['def_lang'] ?? '')));
        }

        if ($language !== '') {
            $translations = function_exists('ll_tools_dictionary_get_sense_translations')
                ? ll_tools_dictionary_get_sense_translations($sense)
                : [];
            $previous_translation = trim((string) ($translations[$language] ?? ''));
            $translations[$language] = $value;
            $sense['translations'] = $translations;

            $definition_language = function_exists('ll_tools_dictionary_normalize_language_key')
                ? ll_tools_dictionary_normalize_language_key((string) ($sense['def_lang'] ?? ''))
                : strtolower(trim((string) ($sense['def_lang'] ?? '')));
            $current_definition = trim((string) ($sense['definition'] ?? ''));
            if (
                $definition_language === $language
                || ($definition_language === '' && ($current_definition === '' || $current_definition === $previous_translation))
            ) {
                $sense['definition'] = $value;
                if ($definition_language === '') {
                    $sense['def_lang'] = $language;
                }
            }
        } else {
            $sense['definition'] = $value;
        }

        $senses[$sense_index] = $sense;
        $persisted = ll_tools_dictionary_persist_entry_senses($entry_id, $senses, $preferred_languages);
        if (is_wp_error($persisted)) {
            wp_send_json_error([
                'message' => __('Unable to save this dictionary entry right now.', 'll-tools-text-domain'),
            ], 500);
        }

        wp_send_json_success(array_merge(
            [
                'message' => __('Dictionary entry updated.', 'll-tools-text-domain'),
                'value' => $value,
                'sense_index' => $sense_index,
                'language' => $language,
            ],
            ll_tools_dictionary_build_inline_entry_response($entry_id, $preferred_languages)
        ));
    }

    wp_send_json_error([
        'message' => __('Invalid dictionary update request.', 'll-tools-text-domain'),
    ], 400);
}
add_action('wp_ajax_ll_tools_dictionary_update_entry', 'll_tools_dictionary_handle_entry_update');

function ll_tools_dictionary_handle_toolbar_bootstrap(): void {
    check_ajax_referer('ll_tools_dictionary_live_search', 'nonce');

    $wordset_id = isset($_POST['wordset_id']) ? max(0, (int) wp_unslash((string) $_POST['wordset_id'])) : 0;
    if (!ll_tools_dictionary_current_user_can_view_wordset_id($wordset_id)) {
        ll_tools_dictionary_send_wordset_forbidden_ajax();
    }

    $base_url = ll_tools_dictionary_resolve_live_base_url(isset($_POST['base_url']) ? (string) wp_unslash((string) $_POST['base_url']) : '');
    $search_scopes = ll_tools_dictionary_shortcode_resolve_search_scopes_from_request($_POST);
    $letter = isset($_POST['ll_dictionary_letter']) ? trim(sanitize_text_field(wp_unslash((string) $_POST['ll_dictionary_letter']))) : '';
    $pos_slug = isset($_POST['ll_dictionary_pos']) ? sanitize_title(wp_unslash((string) $_POST['ll_dictionary_pos'])) : '';
    $source_id = isset($_POST['ll_dictionary_source']) ? sanitize_text_field(wp_unslash((string) $_POST['ll_dictionary_source'])) : '';
    if (function_exists('ll_tools_dictionary_normalize_source_id')) {
        $source_id = ll_tools_dictionary_normalize_source_id($source_id);
    }
    $dialect = isset($_POST['ll_dictionary_dialect']) ? trim(sanitize_text_field(wp_unslash((string) $_POST['ll_dictionary_dialect']))) : '';

    $cache_args = [
        'wordset_id' => $wordset_id,
        'base_url' => $base_url,
        'search_scopes' => $search_scopes,
        'letter' => $letter,
        'pos_slug' => $pos_slug,
        'source_id' => $source_id,
        'dialect' => $dialect,
    ];
    $cached = ll_tools_dictionary_ajax_cache_get('toolbar_bootstrap', $cache_args);
    if (is_array($cached)) {
        ll_tools_dictionary_send_ajax_cache_header('HIT');
        wp_send_json_success($cached);
    }

    $payload = [
        'html' => ll_tools_dictionary_render_toolbar_panel($base_url, $wordset_id, $search_scopes, $letter, $pos_slug, $source_id, $dialect),
    ];
    ll_tools_dictionary_ajax_cache_set('toolbar_bootstrap', $cache_args, $payload);
    ll_tools_dictionary_send_ajax_cache_header('MISS');
    wp_send_json_success($payload);
}
add_action('wp_ajax_ll_tools_dictionary_toolbar_bootstrap', 'll_tools_dictionary_handle_toolbar_bootstrap');
add_action('wp_ajax_nopriv_ll_tools_dictionary_toolbar_bootstrap', 'll_tools_dictionary_handle_toolbar_bootstrap');

/**
 * Handle public live-search requests for the dictionary shortcode.
 */
function ll_tools_dictionary_handle_live_search(): void {
    check_ajax_referer('ll_tools_dictionary_live_search', 'nonce');

    $wordset_id = isset($_POST['wordset_id']) ? max(0, (int) wp_unslash((string) $_POST['wordset_id'])) : 0;
    if (!ll_tools_dictionary_current_user_can_view_wordset_id($wordset_id)) {
        ll_tools_dictionary_send_wordset_forbidden_ajax();
    }

    $per_page_cap = is_user_logged_in()
        ? 100
        : max(1, min(100, (int) apply_filters('ll_tools_dictionary_anonymous_live_search_per_page_cap', 40)));
    $per_page = isset($_POST['per_page']) ? max(1, min($per_page_cap, (int) wp_unslash((string) $_POST['per_page']))) : 20;
    $sense_limit = isset($_POST['sense_limit']) ? max(1, min(8, (int) wp_unslash((string) $_POST['sense_limit']))) : 3;
    $linked_word_limit = isset($_POST['linked_word_limit']) ? max(0, min(8, (int) wp_unslash((string) $_POST['linked_word_limit']))) : 4;
    $gloss_lang = isset($_POST['gloss_lang']) ? sanitize_text_field(wp_unslash((string) $_POST['gloss_lang'])) : '';
    $search = isset($_POST['ll_dictionary_q']) ? trim(sanitize_text_field(wp_unslash((string) $_POST['ll_dictionary_q']))) : '';
    $search_scopes = ll_tools_dictionary_shortcode_resolve_search_scopes_from_request($_POST);
    $letter = isset($_POST['ll_dictionary_letter']) ? trim(sanitize_text_field(wp_unslash((string) $_POST['ll_dictionary_letter']))) : '';
    $page = isset($_POST['ll_dictionary_page']) ? max(1, (int) wp_unslash((string) $_POST['ll_dictionary_page'])) : 1;
    $query_limits = [];
    if (!is_user_logged_in()) {
        $page = min($page, ll_tools_dictionary_anonymous_live_search_page_cap());
        $result_depth_cap = ll_tools_dictionary_anonymous_live_search_result_depth_cap();
        if ($result_depth_cap > 0) {
            $per_page = min($per_page, $result_depth_cap);
            $page = ll_tools_dictionary_clamp_page_to_result_depth($page, $per_page, $result_depth_cap);
            $query_limits['result_depth_limit'] = $result_depth_cap;
        }

        $candidate_scan_cap = ll_tools_dictionary_anonymous_live_search_candidate_scan_cap();
        if ($candidate_scan_cap > 0) {
            $query_limits['candidate_scan_limit'] = $candidate_scan_cap;
        }
    }
    $pos_slug = isset($_POST['ll_dictionary_pos']) ? sanitize_title(wp_unslash((string) $_POST['ll_dictionary_pos'])) : '';
    $source_id = isset($_POST['ll_dictionary_source']) ? sanitize_text_field(wp_unslash((string) $_POST['ll_dictionary_source'])) : '';
    if (function_exists('ll_tools_dictionary_normalize_source_id')) {
        $source_id = ll_tools_dictionary_normalize_source_id($source_id);
    }
    $dialect = isset($_POST['ll_dictionary_dialect']) ? trim(sanitize_text_field(wp_unslash((string) $_POST['ll_dictionary_dialect']))) : '';
    $base_url = ll_tools_dictionary_resolve_live_base_url(isset($_POST['base_url']) ? (string) wp_unslash((string) $_POST['base_url']) : '');
    $preferred_languages = ll_tools_dictionary_shortcode_resolve_display_languages($search_scopes, $wordset_id, $gloss_lang);

    if ($search !== '') {
        $letter = '';
    }

    $has_non_search_filter = ($letter !== '' || $pos_slug !== '' || $source_id !== '' || $dialect !== '');
    if (
        $search !== ''
        && ll_tools_dictionary_live_search_length($search) < ll_tools_dictionary_live_search_min_chars()
        && !$has_non_search_filter
    ) {
        $search = '';
    }

    $has_active_browse_query = ($search !== '' || $has_non_search_filter);
    $query = [
        'items' => [],
        'total' => 0,
        'page' => 1,
        'per_page' => $per_page,
        'total_pages' => 1,
    ];
    $cache_args = [
        'wordset_id' => $wordset_id,
        'per_page' => $per_page,
        'sense_limit' => $sense_limit,
        'linked_word_limit' => $linked_word_limit,
        'gloss_lang' => $gloss_lang,
        'base_url' => $base_url,
        'search' => $search,
        'search_scopes' => $search_scopes,
        'letter' => $letter,
        'page' => $page,
        'pos_slug' => $pos_slug,
        'source_id' => $source_id,
        'dialect' => $dialect,
        'preferred_languages' => $preferred_languages,
        'has_active_query' => $has_active_browse_query,
        'query_limits' => $query_limits,
    ];
    $cached = ll_tools_dictionary_ajax_cache_get('live_search', $cache_args);
    if (is_array($cached)) {
        ll_tools_dictionary_send_ajax_cache_header('HIT');
        wp_send_json_success($cached);
    }

    if ($has_active_browse_query) {
        $query = ll_tools_dictionary_run_browse_query(
            $wordset_id,
            $search,
            $search_scopes,
            $letter,
            $page,
            $pos_slug,
            $source_id,
            $dialect,
            $per_page,
            $sense_limit,
            $linked_word_limit,
            $preferred_languages,
            $query_limits
        );
    }

    $payload = [
        'html' => $has_active_browse_query
            ? ll_tools_dictionary_render_browse_results($query, $base_url, $search, $search_scopes, $letter, $pos_slug, $source_id, $dialect)
            : '',
        'has_active_query' => $has_active_browse_query,
        'is_limited' => !empty($query['candidate_scan_limited']),
        'url' => $has_active_browse_query
            ? ll_tools_dictionary_build_url($base_url, [
                'll_dictionary_q' => $search,
                'll_dictionary_scope' => $search_scopes,
                'll_dictionary_letter' => $letter,
                'll_dictionary_pos' => $pos_slug,
                'll_dictionary_source' => $source_id,
                'll_dictionary_dialect' => $dialect,
                'll_dictionary_page' => (string) max(1, (int) ($query['page'] ?? $page)),
            ])
            : $base_url,
    ];
    ll_tools_dictionary_ajax_cache_set('live_search', $cache_args, $payload);
    ll_tools_dictionary_send_ajax_cache_header('MISS');
    wp_send_json_success($payload);
}
add_action('wp_ajax_ll_tools_dictionary_live_search', 'll_tools_dictionary_handle_live_search');
add_action('wp_ajax_nopriv_ll_tools_dictionary_live_search', 'll_tools_dictionary_handle_live_search');

function ll_tools_dictionary_shortcode($atts = [], $content = null, $tag = ''): string {
    $atts = shortcode_atts([
        'wordset' => '',
        'show_title' => '1',
        'per_page' => '20',
        'sense_limit' => '3',
        'linked_word_limit' => '4',
        'title' => '',
        'gloss_lang' => '',
    ], $atts, $tag ?: 'll_dictionary');

    ll_tools_dictionary_enqueue_assets();

    $wordset_id = ll_tools_dictionary_shortcode_resolve_wordset_id((string) $atts['wordset']);
    if (!ll_tools_dictionary_current_user_can_view_wordset_id($wordset_id)) {
        $wordset_id = 0;
    }

    $search = isset($_GET['ll_dictionary_q']) ? trim(sanitize_text_field(wp_unslash((string) $_GET['ll_dictionary_q']))) : '';
    $search_scopes = ll_tools_dictionary_shortcode_resolve_search_scopes_from_request($_GET);
    $preferred_languages = ll_tools_dictionary_shortcode_resolve_display_languages($search_scopes, $wordset_id, (string) $atts['gloss_lang']);
    $letter = isset($_GET['ll_dictionary_letter'])
        ? trim(sanitize_text_field(wp_unslash((string) $_GET['ll_dictionary_letter'])))
        : (isset($_GET['letter']) ? trim(sanitize_text_field(wp_unslash((string) $_GET['letter']))) : '');
    $page = isset($_GET['ll_dictionary_page']) ? max(1, (int) wp_unslash((string) $_GET['ll_dictionary_page'])) : 1;
    $pos_slug = isset($_GET['ll_dictionary_pos']) ? sanitize_title((string) wp_unslash((string) $_GET['ll_dictionary_pos'])) : '';
    $source_id = isset($_GET['ll_dictionary_source']) ? sanitize_text_field(wp_unslash((string) $_GET['ll_dictionary_source'])) : '';
    if (function_exists('ll_tools_dictionary_normalize_source_id')) {
        $source_id = ll_tools_dictionary_normalize_source_id($source_id);
    }
    $dialect = isset($_GET['ll_dictionary_dialect']) ? trim(sanitize_text_field(wp_unslash((string) $_GET['ll_dictionary_dialect']))) : '';
    $requested_entry_id = ll_tools_dictionary_shortcode_resolve_requested_entry_id($wordset_id);
    if ($search !== '') {
        $letter = '';
    }
    $has_active_browse_query = ($search !== '' || $letter !== '' || $pos_slug !== '' || $source_id !== '' || $dialect !== '');
    $per_page = max(1, (int) $atts['per_page']);
    $sense_limit = max(1, (int) $atts['sense_limit']);
    $linked_word_limit = max(0, (int) $atts['linked_word_limit']);

    $query = [
        'items' => [],
        'total' => 0,
        'page' => 1,
        'per_page' => $per_page,
        'total_pages' => 1,
    ];
    if ($has_active_browse_query) {
        $query = ll_tools_dictionary_run_browse_query(
            $wordset_id,
            $search,
            $search_scopes,
            $letter,
            $page,
            $pos_slug,
            $source_id,
            $dialect,
            $per_page,
            $sense_limit,
            $linked_word_limit,
            $preferred_languages
        );
    }

    $wordset_name = '';
    if ($wordset_id > 0) {
        $wordset_term = get_term($wordset_id, 'wordset');
        if ($wordset_term && !is_wp_error($wordset_term)) {
            $wordset_name = (string) $wordset_term->name;
        }
    }

    $custom_title = trim((string) $atts['title']);
    $show_title_raw = strtolower(trim((string) $atts['show_title']));
    $show_title = !in_array($show_title_raw, ['0', 'false', 'no', 'off'], true);
    $heading = $custom_title !== ''
        ? $custom_title
        : ($wordset_name !== '' ? $wordset_name : __('Dictionary', 'll-tools-text-domain'));

    $base_url = ll_tools_dictionary_get_current_base_url();
    $defer_toolbar_panel = ($requested_entry_id <= 0 && !$has_active_browse_query);
    $search_scope_options = ll_tools_dictionary_get_search_scope_options();
    $search_scope_fieldset_id = 'll-dictionary-scope-group-' . wp_unique_id();
    $has_explicit_scope = array_key_exists('ll_dictionary_scope', $_GET);
    $toolbar_classes = ['ll-dictionary__toolbar', $has_active_browse_query ? 'is-expanded' : 'is-collapsed'];
    if ($has_active_browse_query || $has_explicit_scope) {
        $toolbar_classes[] = 'is-scope-visible';
    }

    ob_start();
    ?>
    <section
        class="ll-dictionary"
        data-ll-dictionary-root
        data-wordset-id="<?php echo esc_attr((string) $wordset_id); ?>"
        data-per-page="<?php echo esc_attr((string) $per_page); ?>"
        data-sense-limit="<?php echo esc_attr((string) $sense_limit); ?>"
        data-linked-word-limit="<?php echo esc_attr((string) $linked_word_limit); ?>"
        data-gloss-lang="<?php echo esc_attr((string) $atts['gloss_lang']); ?>"
        data-base-url="<?php echo esc_attr($base_url); ?>"
        data-ll-dictionary-toolbar-deferred="<?php echo $defer_toolbar_panel ? '1' : '0'; ?>"
        data-ll-dictionary-has-explicit-scope="<?php echo $has_explicit_scope ? '1' : '0'; ?>"
    >
        <?php if ($show_title) : ?>
            <header class="ll-dictionary__header">
                <h2 class="ll-dictionary__heading"><?php echo esc_html($heading); ?></h2>
                <?php if ($wordset_name !== '' && $custom_title !== '' && $custom_title !== $wordset_name) : ?>
                    <p class="ll-dictionary__scope"><?php echo esc_html($wordset_name); ?></p>
                <?php endif; ?>
            </header>
        <?php endif; ?>

        <?php if ($requested_entry_id > 0) : ?>
            <?php echo ll_tools_dictionary_render_detail_view($requested_entry_id, $base_url, $preferred_languages); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        <?php else : ?>
            <div class="<?php echo esc_attr(implode(' ', $toolbar_classes)); ?>">
                <form class="ll-dictionary__form" method="get" action="<?php echo esc_url($base_url); ?>" data-ll-dictionary-form>
                    <?php echo ll_tools_dictionary_preserve_non_dictionary_query_inputs(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <input type="hidden" name="ll_dictionary_letter" value="<?php echo esc_attr($letter); ?>">
                    <div class="ll-dictionary__search-row">
                        <div class="ll-dictionary__field ll-dictionary__field--search">
                            <label class="screen-reader-text" for="ll-dictionary-search"><?php esc_html_e('Search dictionary', 'll-tools-text-domain'); ?></label>
                            <input
                                type="search"
                                id="ll-dictionary-search"
                                class="ll-dictionary__input"
                                name="ll_dictionary_q"
                                value="<?php echo esc_attr($search); ?>"
                                placeholder="<?php echo esc_attr__('Search dictionary', 'll-tools-text-domain'); ?>"
                            >
                        </div>
                        <div class="ll-dictionary__actions ll-dictionary__actions--primary">
                            <button class="ll-dictionary__button" type="submit"><?php esc_html_e('Search', 'll-tools-text-domain'); ?></button>
                        </div>
                    </div>
                    <fieldset class="ll-dictionary__scope-group" aria-labelledby="<?php echo esc_attr($search_scope_fieldset_id); ?>">
                        <legend id="<?php echo esc_attr($search_scope_fieldset_id); ?>" class="screen-reader-text"><?php esc_html_e('Search scope', 'll-tools-text-domain'); ?></legend>
                        <div class="ll-dictionary__scope-options">
                            <?php foreach ($search_scope_options as $option) : ?>
                                <?php
                                $scope_value = trim((string) ($option['value'] ?? ''));
                                if ($scope_value === '') {
                                    continue;
                                }
                                $scope_id = 'll-dictionary-scope-' . sanitize_html_class($scope_value) . '-' . wp_unique_id();
                                ?>
                                <label class="ll-dictionary__scope-option" for="<?php echo esc_attr($scope_id); ?>">
                                    <input
                                        type="checkbox"
                                        id="<?php echo esc_attr($scope_id); ?>"
                                        class="ll-dictionary__scope-checkbox"
                                        name="ll_dictionary_scope[]"
                                        value="<?php echo esc_attr($scope_value); ?>"
                                        <?php checked(in_array($scope_value, $search_scopes, true)); ?>
                                    >
                                    <span class="ll-dictionary__scope-option-label"><?php echo esc_html((string) ($option['label'] ?? '')); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>
                    <?php
                    if ($defer_toolbar_panel) {
                        echo '<div class="ll-dictionary__toolbar-panel ll-dictionary__toolbar-panel--deferred" data-ll-dictionary-toolbar-panel role="status" aria-live="polite">';
                        echo '<span class="screen-reader-text">' . esc_html__('Loading dictionary filters...', 'll-tools-text-domain') . '</span>';
                        echo '</div>';
                    } else {
                        echo ll_tools_dictionary_render_toolbar_panel($base_url, $wordset_id, $search_scopes, $letter, $pos_slug, $source_id, $dialect); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    }
                    ?>
                </form>
            </div>

            <div class="ll-dictionary__browse-results" data-ll-dictionary-results>
                <?php
                if ($has_active_browse_query) {
                    echo ll_tools_dictionary_render_browse_results($query, $base_url, $search, $search_scopes, $letter, $pos_slug, $source_id, $dialect); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                }
                ?>
            </div>
        <?php endif; ?>
    </section>
    <?php

    return (string) ob_get_clean();
}

add_shortcode('ll_dictionary', 'll_tools_dictionary_shortcode');
add_shortcode('dictionary_search', 'll_tools_dictionary_shortcode');
add_shortcode('dictionary_browser', 'll_tools_dictionary_shortcode');
