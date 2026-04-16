<?php
if (!defined('WPINC')) { die; }

function ll_tools_dictionary_shortcode_query_keys(): array {
    return [
        'll_dictionary_q',
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
            'debounceMs' => 220,
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

function ll_tools_dictionary_get_current_base_url(): string {
    return (string) remove_query_arg(ll_tools_dictionary_shortcode_query_keys(), get_pagenum_link(1, false));
}

function ll_tools_dictionary_preserve_non_dictionary_query_inputs(): string {
    $exclude = array_flip(ll_tools_dictionary_shortcode_query_keys());
    $html = '';

    foreach ($_GET as $key => $value) {
        if (!is_string($key) || isset($exclude[$key])) {
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
        $value = is_scalar($value) ? trim((string) $value) : '';
        if ($value === '' || ($key === 'll_dictionary_page' && (int) $value <= 1)) {
            continue;
        }
        $query_args[$key] = $value;
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

    if ($wordset_id > 0 && function_exists('ll_tools_dictionary_entry_matches_wordset_context')) {
        if (!ll_tools_dictionary_entry_matches_wordset_context($entry_id, $wordset_id)) {
            return 0;
        }
    }

    return $entry_id;
}

/**
 * Build one entry-detail URL that preserves the current dictionary query context.
 */
function ll_tools_dictionary_build_detail_url(string $base_url, int $entry_id, string $search, string $letter, string $pos_slug, int $page, string $source_id = '', string $dialect = ''): string {
    return ll_tools_dictionary_build_url($base_url, [
        'll_dictionary_q' => $search,
        'll_dictionary_letter' => $letter,
        'll_dictionary_pos' => $pos_slug,
        'll_dictionary_source' => $source_id,
        'll_dictionary_dialect' => $dialect,
        'll_dictionary_page' => (string) $page,
        'll_dictionary_entry' => (string) $entry_id,
    ]);
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
    $current_sources = ll_tools_dictionary_collect_source_labels(
        function_exists('ll_tools_get_dictionary_entry_senses') ? ll_tools_get_dictionary_entry_senses($entry_id) : []
    );

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
        $reasons = [];
        $candidate_senses = function_exists('ll_tools_get_dictionary_entry_senses') ? ll_tools_get_dictionary_entry_senses($candidate_id) : [];
        $candidate_sources = ll_tools_dictionary_collect_source_labels($candidate_senses);
        $candidate_wordset_id = function_exists('ll_tools_get_dictionary_entry_wordset_id')
            ? (int) ll_tools_get_dictionary_entry_wordset_id($candidate_id)
            : 0;

        if ($candidate_norm === $title_norm) {
            $score += 140;
            if (!empty($current_sources) && !empty($candidate_sources) && array_diff($current_sources, $candidate_sources) !== []) {
                $reasons[] = __('Other dictionary', 'll-tools-text-domain');
            } else {
                $reasons[] = __('Same headword', 'll-tools-text-domain');
            }
        }

        if ($candidate_norm !== $title_norm && (strpos($candidate_norm, $title_norm) !== false || strpos($title_norm, $candidate_norm) !== false)) {
            $score += 90;
            $reasons[] = __('Contains headword', 'll-tools-text-domain');
        }

        similar_text($title_norm, $candidate_norm, $percent);
        if ($percent >= 72.0) {
            $score += (int) round($percent / 2);
            $reasons[] = __('Similar form', 'll-tools-text-domain');
        }

        $ascii_title = preg_replace('/[^a-z]/', '', $title_norm) ?? '';
        $ascii_candidate = preg_replace('/[^a-z]/', '', $candidate_norm) ?? '';
        if ($ascii_title !== '' && $ascii_candidate !== '' && function_exists('metaphone') && metaphone($ascii_title) === metaphone($ascii_candidate)) {
            $score += 20;
            $reasons[] = __('Similar sound', 'll-tools-text-domain');
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
        $item['related_reason'] = $reasons[0] ?? __('Related entry', 'll-tools-text-domain');
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
        return '<a class="' . esc_attr($classes) . '" href="' . esc_url($url) . '">' . $content . '</a>';
    }

    return '<span class="' . esc_attr($classes) . '">' . $content . '</span>';
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
    $page_number = trim((string) ($item['page_number'] ?? ''));
    $sense_count = max(0, (int) ($item['sense_count'] ?? 0));
    $linked_word_count = max(0, (int) ($item['linked_word_count'] ?? 0));
    $senses = (array) ($item['senses'] ?? []);
    $linked_words = (array) ($item['linked_words'] ?? []);
    $sources = array_values(array_filter((array) ($item['sources'] ?? []), 'is_array'));
    $dialects = array_values(array_filter(array_map('strval', (array) ($item['dialects'] ?? []))));
    $preferred_languages = array_values(array_filter(array_map('strval', (array) ($item['preferred_languages'] ?? []))));

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
    if ($translation !== '') {
        $html .= '<p class="ll-dictionary__summary">' . esc_html($translation) . '</p>';
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
    if ($page_number !== '') {
        $html .= ll_tools_dictionary_render_badge(
            sprintf(
                /* translators: %s: source page number */
                __('p. %s', 'll-tools-text-domain'),
                $page_number
            ),
            'page'
        );
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

    if (!empty($senses)) {
        $html .= '<ol class="ll-dictionary__sense-list">';
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
            $sense_page = trim((string) ($sense['page_number'] ?? ''));
            if ($definition === '') {
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
            if ($sense_page !== '') {
                $meta_parts[] = sprintf(
                    /* translators: %s: source page number */
                    __('Page %s', 'll-tools-text-domain'),
                    $sense_page
                );
            }

            $translation_rows = [];
            $visible_lookup = function_exists('ll_tools_dictionary_entry_normalize_lookup_value')
                ? ll_tools_dictionary_entry_normalize_lookup_value($definition)
                : strtolower($definition);
            $translations = function_exists('ll_tools_dictionary_get_sense_translations')
                ? ll_tools_dictionary_get_sense_translations($sense)
                : [];
            foreach ($translations as $language => $text) {
                $text = trim((string) $text);
                if ($text === '') {
                    continue;
                }

                $text_lookup = function_exists('ll_tools_dictionary_entry_normalize_lookup_value')
                    ? ll_tools_dictionary_entry_normalize_lookup_value($text)
                    : strtolower($text);
                if ($text_lookup !== '' && $text_lookup === $visible_lookup) {
                    continue;
                }

                $label = function_exists('ll_tools_dictionary_get_language_label')
                    ? ll_tools_dictionary_get_language_label((string) $language)
                    : strtoupper((string) $language);
                $translation_rows[] = '<span class="ll-dictionary__sense-translation">'
                    . '<span class="ll-dictionary__sense-lang">' . esc_html($label) . '</span>'
                    . '<span class="ll-dictionary__sense-value">' . esc_html($text) . '</span>'
                    . '</span>';
            }

            $html .= '<li class="ll-dictionary__sense-item">';
            $html .= '<span class="ll-dictionary__sense-text">' . esc_html($definition) . '</span>';
            if (!empty($translation_rows)) {
                $html .= '<span class="ll-dictionary__sense-translations">' . implode('', $translation_rows) . '</span>';
            }
            if (!empty($meta_parts)) {
                $html .= '<span class="ll-dictionary__sense-meta">' . esc_html(implode(' • ', $meta_parts)) . '</span>';
            }
            $html .= '</li>';
        }
        $html .= '</ol>';
        if ($sense_count > count($senses)) {
            $html .= '<p class="ll-dictionary__more">';
            $html .= esc_html(sprintf(
                /* translators: %d: number of hidden senses */
                _n('+ %d more sense', '+ %d more senses', $sense_count - count($senses), 'll-tools-text-domain'),
                $sense_count - count($senses)
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
    $translation_groups = ll_tools_dictionary_collect_translation_groups($senses, $preferred_languages);
    $sources = function_exists('ll_tools_dictionary_collect_sources') ? ll_tools_dictionary_collect_sources($senses) : [];
    $dialects = ll_tools_dictionary_collect_entry_dialects($senses);
    $wordset_names = array_values(array_filter(array_map('strval', (array) ($entry['wordset_names'] ?? []))));
    $word_ids = function_exists('ll_tools_get_dictionary_entry_word_ids')
        ? array_values(array_filter(array_map('intval', ll_tools_get_dictionary_entry_word_ids($entry_id, -1)), static function (int $word_id): bool {
            return $word_id > 0 && get_post_status($word_id) === 'publish';
        }))
        : ll_tools_dictionary_get_public_word_ids_for_entry($entry_id, -1);
    $related_entries = ll_tools_dictionary_collect_related_entries($entry_id, $preferred_languages, 6);

    $back_url = ll_tools_dictionary_build_url($base_url, [
        'll_dictionary_q' => isset($_GET['ll_dictionary_q']) ? sanitize_text_field(wp_unslash((string) $_GET['ll_dictionary_q'])) : '',
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
    $html .= '<h3 class="ll-dictionary__detail-title">' . esc_html($title) . '</h3>';
    if ($summary !== '') {
        $html .= '<p class="ll-dictionary__detail-summary">' . esc_html($summary) . '</p>';
    }
    $html .= '</div>';
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
    if (!empty($entry['page_number'])) {
        $html .= ll_tools_dictionary_render_badge(sprintf(__('p. %s', 'll-tools-text-domain'), (string) $entry['page_number']), 'page');
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
    $html .= '</header>';

    if (!empty($translation_groups)) {
        $html .= '<section class="ll-dictionary__detail-section">';
        $html .= '<h4 class="ll-dictionary__section-title">' . esc_html__('Translations', 'll-tools-text-domain') . '</h4>';
        $html .= '<div class="ll-dictionary__translation-groups">';
        foreach ($translation_groups as $group) {
            $html .= '<div class="ll-dictionary__translation-group">';
            $html .= '<div class="ll-dictionary__translation-label">' . esc_html((string) ($group['label'] ?? '')) . '</div>';
            $html .= '<div class="ll-dictionary__translation-values">';
            foreach ((array) ($group['values'] ?? []) as $value) {
                $html .= '<span class="ll-dictionary__translation-chip">' . esc_html((string) $value) . '</span>';
            }
            $html .= '</div></div>';
        }
        $html .= '</div></section>';
    }

    if (!empty($senses)) {
        $html .= '<section class="ll-dictionary__detail-section">';
        $html .= '<h4 class="ll-dictionary__section-title">' . esc_html__('Senses', 'll-tools-text-domain') . '</h4>';
        $html .= '<ol class="ll-dictionary__sense-list ll-dictionary__sense-list--detail">';
        foreach ($senses as $sense) {
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
            if (!empty($sense['page_number'])) {
                $meta_parts[] = sprintf(__('Page %s', 'll-tools-text-domain'), (string) $sense['page_number']);
            }
            if (!empty($sense['source_dictionary'])) {
                $meta_parts[] = (string) $sense['source_dictionary'];
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
            $html .= '<span class="ll-dictionary__sense-text">' . esc_html($sense_text) . '</span>';
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
                $html .= '<p class="ll-dictionary__source-copy"><a href="' . esc_url($attribution_url) . '">' . esc_html__('License and attribution details', 'll-tools-text-domain') . '</a></p>';
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
            if (!empty($related_entry['related_reason'])) {
                $html .= '<span class="ll-dictionary__related-reason">' . esc_html((string) $related_entry['related_reason']) . '</span>';
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
function ll_tools_dictionary_render_pagination(array $query, string $base_url, string $search, string $letter, string $pos_slug, string $source_id = '', string $dialect = ''): string {
    $page = max(1, (int) ($query['page'] ?? 1));
    $total_pages = max(1, (int) ($query['total_pages'] ?? 1));
    if ($total_pages <= 1) {
        return '';
    }

    $html = '<nav class="ll-dictionary__pagination" aria-label="' . esc_attr__('Dictionary pagination', 'll-tools-text-domain') . '">';

    $prev_url = ll_tools_dictionary_build_url($base_url, [
        'll_dictionary_q' => $search,
        'll_dictionary_letter' => $letter,
        'll_dictionary_pos' => $pos_slug,
        'll_dictionary_source' => $source_id,
        'll_dictionary_dialect' => $dialect,
        'll_dictionary_page' => (string) max(1, $page - 1),
    ]);
    $next_url = ll_tools_dictionary_build_url($base_url, [
        'll_dictionary_q' => $search,
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
    string $letter,
    int $page,
    string $pos_slug,
    string $source_id,
    string $dialect,
    int $per_page,
    int $sense_limit,
    int $linked_word_limit,
    array $preferred_languages = []
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

    return ll_tools_dictionary_query_entries([
        'search' => $search,
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
    ]);
}

/**
 * Render the browse-results region used by both the shortcode and live AJAX search.
 */
function ll_tools_dictionary_render_browse_results(array $query, string $base_url, string $search, string $letter, string $pos_slug, string $source_id = '', string $dialect = ''): string {
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
                    ? ll_tools_dictionary_build_detail_url($base_url, $entry_id, $search, $letter, $pos_slug, $current_page, $source_id, $dialect)
                    : '';
                ?>
                <?php echo ll_tools_dictionary_render_result_card((array) $item, $detail_url); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php endforeach; ?>
        </div>
        <?php echo ll_tools_dictionary_render_pagination($query, $base_url, $search, $letter, $pos_slug, $source_id, $dialect); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
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

    return (string) remove_query_arg(ll_tools_dictionary_shortcode_query_keys(), $base_url);
}

/**
 * Handle public live-search requests for the dictionary shortcode.
 */
function ll_tools_dictionary_handle_live_search(): void {
    check_ajax_referer('ll_tools_dictionary_live_search', 'nonce');

    $wordset_id = isset($_POST['wordset_id']) ? max(0, (int) wp_unslash((string) $_POST['wordset_id'])) : 0;
    $per_page = isset($_POST['per_page']) ? max(1, min(100, (int) wp_unslash((string) $_POST['per_page']))) : 20;
    $sense_limit = isset($_POST['sense_limit']) ? max(1, min(8, (int) wp_unslash((string) $_POST['sense_limit']))) : 3;
    $linked_word_limit = isset($_POST['linked_word_limit']) ? max(0, min(8, (int) wp_unslash((string) $_POST['linked_word_limit']))) : 4;
    $gloss_lang = isset($_POST['gloss_lang']) ? sanitize_text_field(wp_unslash((string) $_POST['gloss_lang'])) : '';
    $search = isset($_POST['ll_dictionary_q']) ? trim(sanitize_text_field(wp_unslash((string) $_POST['ll_dictionary_q']))) : '';
    $letter = isset($_POST['ll_dictionary_letter']) ? trim(sanitize_text_field(wp_unslash((string) $_POST['ll_dictionary_letter']))) : '';
    $page = isset($_POST['ll_dictionary_page']) ? max(1, (int) wp_unslash((string) $_POST['ll_dictionary_page'])) : 1;
    $pos_slug = isset($_POST['ll_dictionary_pos']) ? sanitize_title(wp_unslash((string) $_POST['ll_dictionary_pos'])) : '';
    $source_id = isset($_POST['ll_dictionary_source']) ? sanitize_text_field(wp_unslash((string) $_POST['ll_dictionary_source'])) : '';
    if (function_exists('ll_tools_dictionary_normalize_source_id')) {
        $source_id = ll_tools_dictionary_normalize_source_id($source_id);
    }
    $dialect = isset($_POST['ll_dictionary_dialect']) ? trim(sanitize_text_field(wp_unslash((string) $_POST['ll_dictionary_dialect']))) : '';
    $base_url = ll_tools_dictionary_resolve_live_base_url(isset($_POST['base_url']) ? (string) wp_unslash((string) $_POST['base_url']) : '');
    $preferred_languages = ll_tools_dictionary_shortcode_resolve_preferred_languages($wordset_id, $gloss_lang);

    if ($search !== '') {
        $letter = '';
    }

    $has_active_browse_query = ($search !== '' || $letter !== '' || $pos_slug !== '' || $source_id !== '' || $dialect !== '');
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

    wp_send_json_success([
        'html' => $has_active_browse_query
            ? ll_tools_dictionary_render_browse_results($query, $base_url, $search, $letter, $pos_slug, $source_id, $dialect)
            : '',
        'has_active_query' => $has_active_browse_query,
        'url' => $has_active_browse_query
            ? ll_tools_dictionary_build_url($base_url, [
                'll_dictionary_q' => $search,
                'll_dictionary_letter' => $letter,
                'll_dictionary_pos' => $pos_slug,
                'll_dictionary_source' => $source_id,
                'll_dictionary_dialect' => $dialect,
                'll_dictionary_page' => (string) max(1, (int) ($query['page'] ?? $page)),
            ])
            : $base_url,
    ]);
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
    $preferred_languages = ll_tools_dictionary_shortcode_resolve_preferred_languages($wordset_id, (string) $atts['gloss_lang']);
    $search = isset($_GET['ll_dictionary_q']) ? trim(sanitize_text_field(wp_unslash((string) $_GET['ll_dictionary_q']))) : '';
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
    $letters = [];
    $pos_options = [];
    $source_options = [];
    $dialect_options = [];
    if ($requested_entry_id <= 0) {
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
    }
    $reset_url = ll_tools_dictionary_build_url($base_url);

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
            <div class="ll-dictionary__toolbar<?php echo $has_active_browse_query ? ' is-expanded' : ' is-collapsed'; ?>">
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
                            <a class="ll-dictionary__button ll-dictionary__button--ghost" data-ll-dictionary-reset href="<?php echo esc_url($reset_url); ?>"<?php echo $has_active_browse_query ? '' : ' hidden'; ?>><?php esc_html_e('Reset', 'll-tools-text-domain'); ?></a>
                        </div>
                    </div>
                    <div class="ll-dictionary__toolbar-panel">
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
                </form>
            </div>

            <div class="ll-dictionary__browse-results" data-ll-dictionary-results>
                <?php
                if ($has_active_browse_query) {
                    echo ll_tools_dictionary_render_browse_results($query, $base_url, $search, $letter, $pos_slug, $source_id, $dialect); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
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
