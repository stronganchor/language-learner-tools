<?php

function ll_tools_word_grid_collect_audio_files(array $word_ids, bool $include_meta = false): array {
    $word_ids = array_values(array_filter(array_map('intval', $word_ids), function ($id) { return $id > 0; }));
    if (empty($word_ids)) {
        return [];
    }

    $audio_posts = get_posts([
        'post_type'      => 'word_audio',
        'post_parent__in'=> $word_ids,
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'suppress_filters' => true,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ]);

    $audio_by_word = [];
    foreach ($audio_posts as $audio_post) {
        $parent_id = (int) $audio_post->post_parent;
        if (!$parent_id) {
            continue;
        }

        $audio_path = get_post_meta($audio_post->ID, 'audio_file_path', true);
        if (!$audio_path) {
            continue;
        }
        $audio_url = (0 === strpos($audio_path, 'http')) ? $audio_path : site_url($audio_path);
        $recording_types = wp_get_post_terms($audio_post->ID, 'recording_type', ['fields' => 'slugs']);
        if (is_wp_error($recording_types) || empty($recording_types)) {
            continue;
        }

        $speaker_uid = (int) get_post_meta($audio_post->ID, 'speaker_user_id', true);
        if (!$speaker_uid) {
            $speaker_uid = (int) $audio_post->post_author;
        }

        $recording_text = '';
        $recording_translation = '';
        $recording_ipa = '';
        if ($include_meta) {
            $recording_text = get_post_meta($audio_post->ID, 'recording_text', true);
            $recording_translation = get_post_meta($audio_post->ID, 'recording_translation', true);
            $recording_ipa = get_post_meta($audio_post->ID, 'recording_ipa', true);
        }

        foreach ($recording_types as $type) {
            $type = sanitize_text_field($type);
            if ($type === '') {
                continue;
            }
            $entry = [
                'id'              => (int) $audio_post->ID,
                'url'             => $audio_url,
                'recording_type'  => $type,
                'speaker_user_id' => $speaker_uid,
            ];
            if ($include_meta) {
                $entry['recording_text'] = $recording_text;
                $entry['recording_translation'] = $recording_translation;
                $entry['recording_ipa'] = $recording_ipa;
            }
            $audio_by_word[$parent_id][] = $entry;
        }
    }

    return $audio_by_word;
}

function ll_tools_word_grid_get_preferred_speaker(array $audio_files, array $main_types): int {
    if (empty($audio_files) || empty($main_types)) {
        return 0;
    }

    $by_speaker = [];
    foreach ($audio_files as $file) {
        $uid = isset($file['speaker_user_id']) ? (int) $file['speaker_user_id'] : 0;
        $type = isset($file['recording_type']) ? (string) $file['recording_type'] : '';
        if (!$uid || $type === '') {
            continue;
        }
        $by_speaker[$uid][$type] = true;
    }

    foreach ($by_speaker as $uid => $types) {
        if (!array_diff($main_types, array_keys($types))) {
            return (int) $uid;
        }
    }

    return 0;
}

function ll_tools_word_grid_select_audio_entry(array $audio_files, string $type, int $preferred_speaker): array {
    $fallback = [];
    foreach ($audio_files as $file) {
        if (empty($file['recording_type']) || $file['recording_type'] !== $type || empty($file['url'])) {
            continue;
        }
        if (!$fallback) {
            $fallback = $file;
        }
        $speaker_uid = isset($file['speaker_user_id']) ? (int) $file['speaker_user_id'] : 0;
        if ($preferred_speaker && $speaker_uid === $preferred_speaker) {
            return $file;
        }
    }
    return $fallback ? (array) $fallback : [];
}

function ll_tools_word_grid_select_audio_url(array $audio_files, string $type, int $preferred_speaker): string {
    $entry = ll_tools_word_grid_select_audio_entry($audio_files, $type, $preferred_speaker);
    return isset($entry['url']) ? (string) $entry['url'] : '';
}

function ll_tools_word_grid_supports_ipa_extended(): bool {
    static $supports = null;
    if ($supports !== null) {
        return $supports;
    }

    global $wpdb;
    if (!$wpdb || !method_exists($wpdb, 'has_cap') || !$wpdb->has_cap('utf8mb4')) {
        $supports = false;
        return $supports;
    }

    if (!empty($wpdb->charset) && stripos((string) $wpdb->charset, 'utf8mb4') === false) {
        $supports = false;
        return $supports;
    }

    if (method_exists($wpdb, 'get_col_charset')) {
        $charset = $wpdb->get_col_charset($wpdb->postmeta, 'meta_value');
        if ($charset && stripos((string) $charset, 'utf8mb4') === false) {
            $supports = false;
            return $supports;
        }
    }

    $supports = true;
    return $supports;
}

function ll_tools_word_grid_normalize_ipa_text(string $text): string {
    if ($text === '') {
        return '';
    }

    $text = preg_replace_callback('/<\s*sup[^>]*>(.*?)<\/\s*sup>/iu', function ($matches) {
        $content = trim($matches[1] ?? '');
        if ($content === '') {
            return '';
        }
        if (preg_match('/^[Bb\x{0299}\x{1D2E}\x{10784}]$/u', $content)) {
            return "\u{10784}";
        }
        return $content;
    }, $text);

    $text = wp_strip_all_tags($text);
    $text = str_replace("\u{1D2E}", "\u{10784}", $text);

    return $text;
}

function ll_tools_word_grid_normalize_ipa_output(string $text): string {
    $text = ll_tools_word_grid_normalize_ipa_text($text);
    return trim($text);
}

function ll_tools_word_grid_normalize_ipa_input(string $text): string {
    if ($text === '') {
        return '';
    }

    $text = ll_tools_word_grid_normalize_ipa_text($text);

    $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
    if (!$chars) {
        return $text;
    }

    $out = '';
    foreach ($chars as $char) {
        if ($char === "'" || $char === '’') {
            $out .= "\u{02C8}";
            continue;
        }
        if (strlen($char) === 1) {
            $ord = ord($char);
            if ($ord >= 65 && $ord <= 90) {
                if ($char === 'R') {
                    $out .= "\u{0280}";
                    continue;
                }
                if ($char === 'B') {
                    $out .= "\u{0299}";
                    continue;
                }
                if ($char === 'G') {
                    $out .= "\u{0262}";
                    continue;
                }
                $out .= strtolower($char);
                continue;
            }
        }
        $out .= $char;
    }

    return $out;
}

function ll_tools_word_grid_sanitize_ipa(string $text): string {
    $text = trim($text);
    if ($text === '') {
        return '';
    }

    $text = ll_tools_word_grid_normalize_ipa_input($text);

    $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
    if (!$chars) {
        return '';
    }

    $out = '';
    foreach ($chars as $char) {
        if ($char === "\u{10784}" || $char === '.') {
            $out .= $char;
            continue;
        }
        if (ll_tools_word_grid_is_ipa_combining_mark($char)) {
            $out .= $char;
            continue;
        }
        if (preg_match('/\s/u', $char)) {
            $out .= ' ';
            continue;
        }
        if (preg_match('/[a-z]/u', $char)) {
            $out .= $char;
            continue;
        }
        if (preg_match('/[\x{00C0}-\x{02FF}\x{0370}-\x{03FF}\x{1D00}-\x{1DFF}]/u', $char)) {
            $out .= $char;
        }
    }

    $out = preg_replace('/\s+/u', ' ', $out);
    $out = trim((string) $out);

    if (!ll_tools_word_grid_supports_ipa_extended()) {
        $out = str_replace("\u{10784}", "\u{1D2E}", $out);
    }

    return $out;
}

function ll_tools_word_grid_is_ipa_separator(string $char): bool {
    return $char === '.' || preg_match('/\s/u', $char);
}

function ll_tools_word_grid_is_ipa_combining_mark(string $char): bool {
    return preg_match('/[\x{0300}-\x{036F}]/u', $char) === 1;
}

function ll_tools_word_grid_format_ipa_display_html(string $ipa): string {
    $ipa = ll_tools_word_grid_normalize_ipa_output($ipa);
    if ($ipa === '') {
        return '';
    }

    return esc_html($ipa);
}

function ll_tools_word_grid_is_ipa_tie_bar(string $char): bool {
    return preg_match('/[\x{035C}\x{0361}]/u', $char) === 1;
}

function ll_tools_word_grid_tokenize_ipa(string $text): array {
    $text = ll_tools_word_grid_sanitize_ipa($text);
    if ($text === '') {
        return [];
    }

    $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
    if (!$chars) {
        return [];
    }

    $tokens = [];
    $buffer = '';
    $pending = '';
    $tie_pending = false;

    foreach ($chars as $char) {
        if (ll_tools_word_grid_is_ipa_separator($char)) {
            if ($buffer !== '') {
                $tokens[] = $buffer;
                $buffer = '';
            }
            $pending = '';
            $tie_pending = false;
            continue;
        }

        if (ll_tools_word_grid_is_ipa_combining_mark($char)) {
            if ($buffer === '') {
                $pending .= $char;
            } else {
                $buffer .= $char;
            }
            if (ll_tools_word_grid_is_ipa_tie_bar($char) && $buffer !== '') {
                $tie_pending = true;
            }
            continue;
        }

        if ($buffer === '') {
            $buffer = $pending . $char;
            $pending = '';
            $tie_pending = false;
            continue;
        }

        if ($tie_pending) {
            $buffer .= $char;
            $tie_pending = false;
            continue;
        }

        $tokens[] = $buffer;
        $buffer = $pending . $char;
        $pending = '';
        $tie_pending = false;
    }

    if ($buffer !== '') {
        $tokens[] = $buffer;
    }

    return $tokens;
}

function ll_tools_word_grid_is_special_ipa_token(string $token): bool {
    $token = trim($token);
    if ($token === '') {
        return false;
    }
    if (ll_tools_word_grid_is_ipa_separator($token)) {
        return false;
    }
    if (preg_match('/[\x{0300}-\x{036F}]/u', $token)) {
        return true;
    }
    if (preg_match('/[^a-z]/u', $token)) {
        return true;
    }
    return false;
}

function ll_tools_word_grid_extract_ipa_special_chars(string $text): array {
    $tokens = ll_tools_word_grid_tokenize_ipa($text);
    if (empty($tokens)) {
        return [];
    }

    $special = [];
    foreach ($tokens as $token) {
        if (!ll_tools_word_grid_is_special_ipa_token($token)) {
            continue;
        }
        $special[$token] = true;
    }

    return array_keys($special);
}

function ll_tools_word_grid_parse_ipa_symbol_meta($raw): array {
    $tokens = [];
    if (is_string($raw)) {
        $tokens = ll_tools_word_grid_tokenize_ipa($raw);
    } elseif (is_array($raw)) {
        foreach ($raw as $entry) {
            if (!is_string($entry) || $entry === '') {
                continue;
            }
            $entry_tokens = ll_tools_word_grid_tokenize_ipa($entry);
            if (!empty($entry_tokens)) {
                $tokens = array_merge($tokens, $entry_tokens);
            }
        }
    } else {
        return [];
    }

    if (empty($tokens)) {
        return [];
    }

    $cleaned = [];
    foreach ($tokens as $token) {
        if (!ll_tools_word_grid_is_special_ipa_token($token)) {
            continue;
        }
        $cleaned[$token] = true;
    }

    return array_keys($cleaned);
}

function ll_tools_word_grid_get_wordset_ipa_auto_symbols(int $wordset_id): array {
    if ($wordset_id <= 0) {
        return [];
    }

    $raw = get_term_meta($wordset_id, 'll_wordset_ipa_special_chars', true);
    return ll_tools_word_grid_parse_ipa_symbol_meta($raw);
}

function ll_tools_word_grid_get_wordset_ipa_manual_symbols(int $wordset_id): array {
    if ($wordset_id <= 0) {
        return [];
    }

    $raw = get_term_meta($wordset_id, 'll_wordset_ipa_manual_symbols', true);
    return ll_tools_word_grid_parse_ipa_symbol_meta($raw);
}

function ll_tools_word_grid_get_wordset_ipa_special_chars(int $wordset_id): array {
    if ($wordset_id <= 0) {
        return [];
    }

    $auto = ll_tools_word_grid_get_wordset_ipa_auto_symbols($wordset_id);
    $manual = ll_tools_word_grid_get_wordset_ipa_manual_symbols($wordset_id);
    if (empty($auto) && empty($manual)) {
        return [];
    }

    return array_values(array_unique(array_merge($auto, $manual)));
}

function ll_tools_word_grid_rebuild_wordset_ipa_special_chars(int $wordset_id): array {
    $wordset_id = (int) $wordset_id;
    if ($wordset_id <= 0) {
        return [];
    }

    $word_ids = function_exists('ll_tools_ipa_keyboard_get_word_ids_for_wordset')
        ? ll_tools_ipa_keyboard_get_word_ids_for_wordset($wordset_id)
        : get_posts([
            'post_type' => 'words',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'tax_query' => [
                [
                    'taxonomy' => 'wordset',
                    'field' => 'term_id',
                    'terms' => $wordset_id,
                ],
            ],
        ]);

    if (empty($word_ids)) {
        update_term_meta($wordset_id, 'll_wordset_ipa_special_chars', []);
        return [];
    }

    $recording_ids = get_posts([
        'post_type' => 'word_audio',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'post_parent__in' => $word_ids,
    ]);

    $symbols = [];
    foreach ($recording_ids as $recording_id) {
        $recording_id = (int) $recording_id;
        if ($recording_id <= 0) {
            continue;
        }
        $recording_ipa = trim((string) get_post_meta($recording_id, 'recording_ipa', true));
        if ($recording_ipa === '') {
            continue;
        }
        $new_tokens = ll_tools_word_grid_extract_ipa_special_chars($recording_ipa);
        foreach ($new_tokens as $token) {
            $symbols[$token] = true;
        }
    }

    $symbols = array_keys($symbols);
    update_term_meta($wordset_id, 'll_wordset_ipa_special_chars', $symbols);
    return $symbols;
}

function ll_tools_word_grid_update_wordset_ipa_special_chars(int $word_id, string $ipa_text): void {
    $word_id = (int) $word_id;
    if ($word_id <= 0) {
        return;
    }

    $wordset_ids = wp_get_post_terms($word_id, 'wordset', ['fields' => 'ids']);
    if (is_wp_error($wordset_ids) || empty($wordset_ids)) {
        return;
    }

    foreach ($wordset_ids as $wordset_id) {
        $wordset_id = (int) $wordset_id;
        if ($wordset_id <= 0) {
            continue;
        }
        ll_tools_word_grid_rebuild_wordset_ipa_special_chars($wordset_id);
    }
}

function ll_tools_word_grid_format_language_code(string $raw): string {
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }

    $raw = str_replace('_', '-', $raw);
    $raw = preg_replace('/[^A-Za-z\-]+/', '', $raw);
    $raw = trim($raw, '-');
    if ($raw === '') {
        return '';
    }

    $code = strtoupper($raw);
    if ($code === 'AUTO') {
        return '';
    }
    if (strpos($code, '-') === false && strlen($code) > 6) {
        return '';
    }

    return $code;
}

function ll_tools_word_grid_label_with_code(string $label, string $code): string {
    if ($code === '') {
        return $label;
    }
    return $label . ' (' . $code . ')';
}

function ll_tools_word_grid_resolve_display_text(int $word_id): array {
    $word_post = get_post($word_id);
    if (!$word_post || $word_post->post_type !== 'words') {
        return [
            'word_text' => '',
            'translation_text' => '',
            'store_in_title' => true,
        ];
    }

    $store_in_title = function_exists('ll_tools_should_store_word_in_title')
        ? ll_tools_should_store_word_in_title($word_id)
        : true;
    $word_title = get_the_title($word_id);
    $word_translation = get_post_meta($word_id, 'word_translation', true);
    if ($store_in_title && $word_translation === '') {
        $word_translation = get_post_meta($word_id, 'word_english_meaning', true);
    }

    if ($store_in_title) {
        $word_text = $word_title;
        $translation_text = $word_translation;
    } else {
        $word_text = $word_translation;
        $translation_text = $word_title;
    }

    return [
        'word_text' => (string) $word_text,
        'translation_text' => (string) $translation_text,
        'store_in_title' => (bool) $store_in_title,
    ];
}

function ll_tools_word_grid_reorder_by_option_groups(array $posts, array $groups): array {
    if (empty($posts) || empty($groups)) {
        return $posts;
    }

    $order_map = [];
    $post_map = [];
    foreach ($posts as $idx => $post) {
        $post_id = isset($post->ID) ? (int) $post->ID : 0;
        if ($post_id <= 0) {
            continue;
        }
        $order_map[$post_id] = $idx;
        $post_map[$post_id] = $post;
    }

    if (empty($order_map)) {
        return $posts;
    }

    $used = [];
    $ordered_posts = [];
    foreach ($groups as $group) {
        if (empty($group['word_ids']) || !is_array($group['word_ids'])) {
            continue;
        }
        $ids = array_values(array_filter(array_map('intval', $group['word_ids']), function ($id) use ($order_map) {
            return $id > 0 && isset($order_map[$id]);
        }));
        if (empty($ids)) {
            continue;
        }
        usort($ids, function ($a, $b) use ($order_map) {
            return ($order_map[$a] ?? 0) <=> ($order_map[$b] ?? 0);
        });
        foreach ($ids as $id) {
            if (isset($used[$id])) {
                continue;
            }
            $ordered_posts[] = $post_map[$id];
            $used[$id] = true;
        }
    }

    foreach ($posts as $post) {
        $post_id = isset($post->ID) ? (int) $post->ID : 0;
        if ($post_id <= 0 || isset($used[$post_id])) {
            continue;
        }
        $ordered_posts[] = $post;
    }

    return $ordered_posts;
}

function ll_tools_user_can_edit_vocab_words(): bool {
    if (!is_user_logged_in() || !current_user_can('view_ll_tools')) {
        return false;
    }

    if (current_user_can('manage_options')) {
        return true;
    }

    $user = wp_get_current_user();
    return in_array('ll_tools_editor', (array) $user->roles, true);
}

/**
 * The callback function for the 'word_grid' shortcode.
 *
 * @param array $atts Shortcode attributes.
 * @return string HTML content to display the grid.
 */
function ll_tools_word_grid_shortcode($atts) {
    // Shortcode attributes with defaults
    $atts = shortcode_atts(array(
        'category' => '', // Default category to empty
        'wordset'  => '', // Optional wordset filter
        'deepest_only' => '', // When truthy, restrict to lowest-level categories.
    ), $atts);

    // Sanitize the category attribute
    $sanitized_category = sanitize_text_field($atts['category']);
    $sanitized_wordset = sanitize_text_field($atts['wordset']);
    $deepest_only = false;
    if (!empty($atts['deepest_only'])) {
        $deepest_only = filter_var($atts['deepest_only'], FILTER_VALIDATE_BOOLEAN);
    }

    $category_term = null;
    $is_text_based = false;
    if ($sanitized_category !== '') {
        $category_term = get_term_by('slug', $sanitized_category, 'word-category');
    }
    if ($category_term && !is_wp_error($category_term) && function_exists('ll_tools_get_category_quiz_config')) {
        $quiz_config = ll_tools_get_category_quiz_config($category_term);
        $prompt_type = (string) ($quiz_config['prompt_type'] ?? 'audio');
        $option_type = (string) ($quiz_config['option_type'] ?? '');
        $requires_images = ($prompt_type === 'image') || ($option_type === 'image');
        $is_text_based = !$requires_images && (strpos($option_type, 'text') === 0);
    }

    $wordset_term = null;
    $wordset_id = 0;
    if ($sanitized_wordset !== '') {
        $wordset_field = ctype_digit($sanitized_wordset) ? 'term_id' : 'slug';
        $wordset_value = ctype_digit($sanitized_wordset) ? (int) $sanitized_wordset : $sanitized_wordset;
        $wordset_term = get_term_by($wordset_field, $wordset_value, 'wordset');
        if ($wordset_term && !is_wp_error($wordset_term)) {
            $wordset_id = (int) $wordset_term->term_id;
        } else {
            $wordset_term = null;
        }
    }
    ll_enqueue_asset_by_timestamp('/js/word-grid.js', 'll-tools-word-grid', ['jquery'], true);

    $can_edit_words = ll_tools_user_can_edit_vocab_words()
        && is_singular('ll_vocab_lesson');

    $user_study_state = [
        'wordset_id'       => 0,
        'category_ids'     => [],
        'starred_word_ids' => [],
        'star_mode'        => 'normal',
        'fast_transitions' => false,
    ];
    if (is_user_logged_in() && function_exists('ll_tools_get_user_study_state')) {
        $user_study_state = ll_tools_get_user_study_state();
    }

    // Start output buffering
    ob_start();

    // WP_Query arguments
    $args = array(
        'post_type' => 'words',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'orderby' => 'date', // Order by date
        'order' => 'ASC', // Ascending order
    );
    if (!$is_text_based) {
        $args['meta_query'] = array(
            array(
                'key' => '_thumbnail_id', // Checks if the post has a featured image.
                'compare' => 'EXISTS'
            ),
        );
    }

    $tax_query = [];
    if (!empty($sanitized_category)) {
        $tax_query[] = [
            'taxonomy' => 'word-category',
            'field' => 'slug',
            'terms' => $sanitized_category,
        ];
    }
    if (!empty($sanitized_wordset)) {
        $is_numeric = ctype_digit($sanitized_wordset);
        $tax_query[] = [
            'taxonomy' => 'wordset',
            'field'    => $is_numeric ? 'term_id' : 'slug',
            'terms'    => $is_numeric ? [(int) $sanitized_wordset] : $sanitized_wordset,
        ];
    }
    if (!empty($tax_query)) {
        if (count($tax_query) > 1) {
            $tax_query['relation'] = 'AND';
        }
        $args['tax_query'] = $tax_query;
    }

    // The Query
    $query = new WP_Query($args);
    if ($deepest_only && $category_term && function_exists('ll_get_deepest_categories')) {
        $filtered_posts = [];
        foreach ((array) $query->posts as $post_obj) {
            $post_id = isset($post_obj->ID) ? (int) $post_obj->ID : 0;
            if ($post_id <= 0) {
                continue;
            }
            $deepest_terms = ll_get_deepest_categories($post_id);
            $deepest_ids = wp_list_pluck((array) $deepest_terms, 'term_id');
            if (in_array((int) $category_term->term_id, $deepest_ids, true)) {
                $filtered_posts[] = $post_obj;
            }
        }
        $query->posts = $filtered_posts;
        $query->post_count = count($filtered_posts);
        $query->current_post = -1;
    }

    if ($category_term && $wordset_id > 0 && function_exists('ll_tools_get_word_option_maps')) {
        $maps = ll_tools_get_word_option_maps($wordset_id, (int) $category_term->term_id);
        $groups = isset($maps['groups']) && is_array($maps['groups']) ? $maps['groups'] : [];
        if (!empty($groups)) {
            $query->posts = ll_tools_word_grid_reorder_by_option_groups($query->posts, $groups);
            $query->post_count = count($query->posts);
            $query->current_post = -1;
        }
    }
    $word_ids = wp_list_pluck($query->posts, 'ID');
    $audio_by_word = ll_tools_word_grid_collect_audio_files($word_ids, true);
    $main_recording_types = function_exists('ll_tools_get_main_recording_types')
        ? ll_tools_get_main_recording_types()
        : ['isolation', 'question', 'introduction'];
    $recording_type_order = ['question', 'isolation', 'introduction'];
    $recording_labels = [
        'question'     => __('Question', 'll-tools-text-domain'),
        'isolation'    => __('Isolation', 'll-tools-text-domain'),
        'introduction' => __('Introduction', 'll-tools-text-domain'),
    ];

    $target_lang_raw = '';
    if ($wordset_term && function_exists('ll_get_wordset_language')) {
        $target_lang_raw = (string) ll_get_wordset_language((int) $wordset_term->term_id);
    }
    if ($target_lang_raw === '') {
        $target_lang_raw = (string) get_option('ll_target_language', '');
    }
    $translation_lang_raw = (string) get_option('ll_translation_language', '');
    $target_lang_code = ll_tools_word_grid_format_language_code($target_lang_raw);
    $translation_lang_code = ll_tools_word_grid_format_language_code($translation_lang_raw);

    $play_label_template = __('Play %s recording', 'll-tools-text-domain');
    $edit_labels = [
        'edit_word'   => __('Edit word', 'll-tools-text-domain'),
        'word'        => ll_tools_word_grid_label_with_code(__('Word', 'll-tools-text-domain'), $target_lang_code),
        'translation' => ll_tools_word_grid_label_with_code(__('Translation', 'll-tools-text-domain'), $translation_lang_code),
        'recordings'  => __('Recordings', 'll-tools-text-domain'),
        'text'        => ll_tools_word_grid_label_with_code(__('Text', 'll-tools-text-domain'), $target_lang_code),
        'ipa'         => ll_tools_word_grid_label_with_code(__('IPA', 'll-tools-text-domain'), $target_lang_code),
        'ipa_superscript' => __('Superscript selection', 'll-tools-text-domain'),
        'save'        => __('Save', 'll-tools-text-domain'),
        'cancel'      => __('Cancel', 'll-tools-text-domain'),
    ];
    $show_stars = is_user_logged_in();
    $starred_ids = array_values(array_filter(array_map('intval', (array) ($user_study_state['starred_word_ids'] ?? []))));

    $ipa_special_chars = $wordset_id ? ll_tools_word_grid_get_wordset_ipa_special_chars($wordset_id) : [];
    if (!empty($audio_by_word)) {
        foreach ($audio_by_word as $entries) {
            foreach ((array) $entries as $entry) {
                $ipa_value = isset($entry['recording_ipa']) ? (string) $entry['recording_ipa'] : '';
                if ($ipa_value === '') {
                    continue;
                }
                $new_chars = ll_tools_word_grid_extract_ipa_special_chars($ipa_value);
                foreach ($new_chars as $char) {
                    if (!in_array($char, $ipa_special_chars, true)) {
                        $ipa_special_chars[] = $char;
                    }
                }
            }
        }
    }

    $ipa_counts = [];
    $count_audio_by_word = $audio_by_word;
    if ($wordset_id > 0 && function_exists('ll_tools_ipa_keyboard_get_word_ids_for_wordset')) {
        $wordset_word_ids = ll_tools_ipa_keyboard_get_word_ids_for_wordset($wordset_id);
        if (!empty($wordset_word_ids)) {
            $count_audio_by_word = ll_tools_word_grid_collect_audio_files($wordset_word_ids, true);
        }
    }
    if (!empty($count_audio_by_word)) {
        foreach ($count_audio_by_word as $entries) {
            foreach ((array) $entries as $entry) {
                $ipa_value = isset($entry['recording_ipa']) ? (string) $entry['recording_ipa'] : '';
                if ($ipa_value === '') {
                    continue;
                }
                $tokens = function_exists('ll_tools_word_grid_tokenize_ipa')
                    ? ll_tools_word_grid_tokenize_ipa($ipa_value)
                    : preg_split('//u', $ipa_value, -1, PREG_SPLIT_NO_EMPTY);
                foreach ((array) $tokens as $token) {
                    if (function_exists('ll_tools_word_grid_is_special_ipa_token')
                        && !ll_tools_word_grid_is_special_ipa_token($token)) {
                        continue;
                    }
                    if (!isset($ipa_counts[$token])) {
                        $ipa_counts[$token] = 0;
                    }
                    $ipa_counts[$token] += 1;
                }
            }
        }
    }

    if (!empty($ipa_special_chars)) {
        usort($ipa_special_chars, function ($a, $b) use ($ipa_counts) {
            $count_a = (int) ($ipa_counts[$a] ?? 0);
            $count_b = (int) ($ipa_counts[$b] ?? 0);
            if ($count_a === $count_b) {
                return strnatcasecmp((string) $a, (string) $b);
            }
            return ($count_b <=> $count_a);
        });
    }

    if (!empty($ipa_special_chars)) {
        $normalized_chars = [];
        foreach ($ipa_special_chars as $char) {
            $normalized = ll_tools_word_grid_normalize_ipa_output((string) $char);
            if ($normalized === '') {
                continue;
            }
            if (!in_array($normalized, $normalized_chars, true)) {
                $normalized_chars[] = $normalized;
            }
        }
        $ipa_special_chars = $normalized_chars;
    }

    wp_localize_script('ll-tools-word-grid', 'llToolsWordGridData', [
        'ajaxUrl'    => admin_url('admin-ajax.php'),
        'nonce'      => is_user_logged_in() ? wp_create_nonce('ll_user_study') : '',
        'isLoggedIn' => is_user_logged_in(),
        'canEdit'    => $can_edit_words,
        'editNonce'  => $can_edit_words ? wp_create_nonce('ll_word_grid_edit') : '',
        'supportsIpaExtended' => ll_tools_word_grid_supports_ipa_extended(),
        'state'      => $user_study_state,
        'i18n'       => [
            'starLabel'      => __('Star word', 'll-tools-text-domain'),
            'unstarLabel'    => __('Unstar word', 'll-tools-text-domain'),
            'starAllLabel'   => __('Star all', 'll-tools-text-domain'),
            'unstarAllLabel' => __('Unstar all', 'll-tools-text-domain'),
        ],
        'editI18n'   => [
            'saving' => __('Saving...', 'll-tools-text-domain'),
            'saved'  => __('Saved.', 'll-tools-text-domain'),
            'error'  => __('Unable to save changes.', 'll-tools-text-domain'),
            'ipaCommon' => __('Common IPA symbols', 'll-tools-text-domain'),
            'ipaWordset' => __('Wordset IPA symbols', 'll-tools-text-domain'),
        ],
        'transcribeI18n' => [
            'confirm'        => __('Transcribe missing recordings for this lesson?', 'll-tools-text-domain'),
            'confirmReplace' => __('Replace captions for this lesson?', 'll-tools-text-domain'),
            'confirmClear'   => __('Clear captions for this lesson?', 'll-tools-text-domain'),
            'working'        => __('Transcribing...', 'll-tools-text-domain'),
            'progress'       => __('Transcribing %1$d of %2$d...', 'll-tools-text-domain'),
            'done'           => __('Transcription complete.', 'll-tools-text-domain'),
            'none'           => __('No recordings need text.', 'll-tools-text-domain'),
            'clearing'       => __('Clearing captions...', 'll-tools-text-domain'),
            'cleared'        => __('Captions cleared.', 'll-tools-text-domain'),
            'cancelled'      => __('Transcription cancelled.', 'll-tools-text-domain'),
            'error'          => __('Unable to transcribe recordings.', 'll-tools-text-domain'),
        ],
        'ipaSpecialChars' => $ipa_special_chars,
    ]);

    // The Loop
    if ($query->have_posts()) {
        $grid_classes = 'word-grid ll-word-grid';
        if ($is_text_based) {
            $grid_classes .= ' ll-word-grid--text';
        }
        echo '<div id="word-grid" class="' . esc_attr($grid_classes) . '" data-ll-word-grid>'; // Grid container
        while ($query->have_posts()) {
            $query->the_post();
            $word_id = get_the_ID();
            $display_values = ll_tools_word_grid_resolve_display_text($word_id);
            $word_text = $display_values['word_text'];
            $translation_text = $display_values['translation_text'];

            // Individual item
            echo '<div class="word-item" data-word-id="' . esc_attr($word_id) . '">';
            // Featured image with container
            if (!$is_text_based && has_post_thumbnail()) {
                echo '<div class="word-image-container">'; // Start new container
                echo get_the_post_thumbnail(get_the_ID(), 'full', array('class' => 'word-image'));
                echo '</div>'; // Close container
            }

            $audio_files = $audio_by_word[$word_id] ?? [];
            $preferred_speaker = ll_tools_word_grid_get_preferred_speaker($audio_files, $main_recording_types);
            $has_recordings = false;
            $recordings_html = '';
            $recording_rows = [];
            $has_recording_caption = false;
            $edit_recordings = [];

            foreach ($recording_type_order as $type) {
                $entry = ll_tools_word_grid_select_audio_entry($audio_files, $type, $preferred_speaker);
                $audio_url = isset($entry['url']) ? (string) $entry['url'] : '';
                if (!$audio_url) {
                    continue;
                }
                $has_recordings = true;
                $label = $recording_labels[$type] ?? ucfirst($type);
                $play_label = sprintf($play_label_template, $label);
                $recording_text = trim((string) ($entry['recording_text'] ?? ''));
                $recording_translation = trim((string) ($entry['recording_translation'] ?? ''));
                $recording_ipa = ll_tools_word_grid_normalize_ipa_output((string) ($entry['recording_ipa'] ?? ''));
                if ($recording_text !== '' || $recording_translation !== '' || $recording_ipa !== '') {
                    $has_recording_caption = true;
                }
                $recording_id_attr = '';
                if (!empty($entry['id'])) {
                    $recording_id_attr = ' data-recording-id="' . esc_attr((int) $entry['id']) . '"';
                }
                $recording_button = '<button type="button" class="ll-study-recording-btn ll-word-grid-recording-btn ll-study-recording-btn--' . esc_attr($type) . '" data-audio-url="' . esc_url($audio_url) . '" data-recording-type="' . esc_attr($type) . '"' . $recording_id_attr . ' aria-label="' . esc_attr($play_label) . '" title="' . esc_attr($play_label) . '">';
                $recording_button .= '<span class="ll-study-recording-icon" aria-hidden="true"></span>';
                $recording_button .= '<span class="ll-study-recording-visualizer" aria-hidden="true">';
                for ($i = 0; $i < 4; $i++) {
                    $recording_button .= '<span class="bar"></span>';
                }
                $recording_button .= '</span>';
                $recording_button .= '</button>';
                $recording_rows[] = [
                    'button' => $recording_button,
                    'text' => $recording_text,
                    'translation' => $recording_translation,
                    'ipa' => $recording_ipa,
                    'id' => !empty($entry['id']) ? (int) $entry['id'] : 0,
                ];

                if ($can_edit_words && !empty($entry['id'])) {
                    $edit_recordings[] = [
                        'id' => (int) $entry['id'],
                        'type' => $type,
                        'label' => $label,
                        'text' => (string) ($entry['recording_text'] ?? ''),
                        'translation' => (string) ($entry['recording_translation'] ?? ''),
                        'ipa' => ll_tools_word_grid_normalize_ipa_output((string) ($entry['recording_ipa'] ?? '')),
                        'audio_url' => $audio_url,
                    ];
                }
            }

            if ($show_stars || $can_edit_words) {
                echo '<div class="ll-word-actions-row">';
                if ($show_stars) {
                    $is_starred = in_array((int) $word_id, $starred_ids, true);
                    $star_label = $is_starred
                        ? __('Unstar word', 'll-tools-text-domain')
                        : __('Star word', 'll-tools-text-domain');
                    echo '<button type="button" class="ll-word-star ll-word-grid-star' . ($is_starred ? ' active' : '') . '" data-word-id="' . esc_attr($word_id) . '" aria-pressed="' . ($is_starred ? 'true' : 'false') . '" aria-label="' . esc_attr($star_label) . '" title="' . esc_attr($star_label) . '"></button>';
                }
                if ($can_edit_words) {
                    echo '<button type="button" class="ll-word-edit-toggle" data-ll-word-edit-toggle aria-label="' . esc_attr($edit_labels['edit_word']) . '" title="' . esc_attr($edit_labels['edit_word']) . '" aria-expanded="false">';
                    echo '<span class="ll-word-edit-icon" aria-hidden="true">';
                    echo '<svg viewBox="0 0 24 24" focusable="false" aria-hidden="true"><path d="M4 20.5h4l10-10-4-4-10 10v4z" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M13.5 6.5l4 4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
                    echo '</span>';
                    echo '</button>';
                }
                echo '</div>';
            }

            echo '<div class="ll-word-title-row">';
            echo '<h3 class="word-title">';
            echo '<span class="ll-word-text" data-ll-word-text>' . esc_html($word_text) . '</span>';
            echo '<span class="ll-word-translation" data-ll-word-translation>' . esc_html($translation_text) . '</span>';
            echo '</h3>';
            echo '</div>';

            if ($can_edit_words) {
                $word_input_id = 'll-word-edit-word-' . $word_id;
                $translation_input_id = 'll-word-edit-translation-' . $word_id;
                echo '<div class="ll-word-edit-panel" data-ll-word-edit-panel aria-hidden="true">';
                echo '<div class="ll-word-edit-fields">';
                echo '<label class="ll-word-edit-label" for="' . esc_attr($word_input_id) . '">' . esc_html($edit_labels['word']) . '</label>';
                echo '<input type="text" class="ll-word-edit-input" id="' . esc_attr($word_input_id) . '" data-ll-word-input="word" value="' . esc_attr($word_text) . '" />';
                echo '<label class="ll-word-edit-label" for="' . esc_attr($translation_input_id) . '">' . esc_html($edit_labels['translation']) . '</label>';
                echo '<input type="text" class="ll-word-edit-input" id="' . esc_attr($translation_input_id) . '" data-ll-word-input="translation" value="' . esc_attr($translation_text) . '" />';
                echo '</div>';

                if (!empty($edit_recordings)) {
                    echo '<button type="button" class="ll-word-edit-recordings-toggle" data-ll-word-recordings-toggle aria-expanded="false">';
                    echo '<span class="ll-word-edit-recordings-icon" aria-hidden="true">';
                    echo '<svg viewBox="0 0 24 24" focusable="false" aria-hidden="true"><path d="M4 10v4M9 6v12M14 8v8M19 11v2" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
                    echo '</span>';
                    echo '<span class="ll-word-edit-recordings-label">' . esc_html($edit_labels['recordings']) . '</span>';
                    echo '</button>';
                    echo '<div class="ll-word-edit-recordings" data-ll-word-recordings-panel aria-hidden="true">';
                    foreach ($edit_recordings as $recording) {
                        $recording_id = (int) ($recording['id'] ?? 0);
                        if ($recording_id <= 0) {
                            continue;
                        }
                        $recording_type = (string) ($recording['type'] ?? '');
                        $recording_label = (string) ($recording['label'] ?? $recording_type);
                        $recording_text = (string) ($recording['text'] ?? '');
                        $recording_translation = (string) ($recording['translation'] ?? '');
                        $recording_ipa = ll_tools_word_grid_normalize_ipa_output((string) ($recording['ipa'] ?? ''));
                        $recording_audio_url = (string) ($recording['audio_url'] ?? '');
                        $recording_text_id = 'll-word-edit-recording-text-' . $recording_id;
                        $recording_translation_id = 'll-word-edit-recording-translation-' . $recording_id;
                        $recording_ipa_id = 'll-word-edit-recording-ipa-' . $recording_id;
                        echo '<div class="ll-word-edit-recording" data-recording-id="' . esc_attr($recording_id) . '" data-recording-type="' . esc_attr($recording_type) . '">';
                        echo '<div class="ll-word-edit-recording-header">';
                        echo '<div class="ll-word-edit-recording-title">';
                        echo '<span class="ll-word-edit-recording-icon" aria-hidden="true"></span>';
                        echo '<span class="ll-word-edit-recording-name">' . esc_html($recording_label) . '</span>';
                        echo '</div>';
                        if ($recording_audio_url !== '') {
                            $recording_play_label = sprintf($play_label_template, $recording_label);
                            echo '<button type="button" class="ll-study-recording-btn ll-word-grid-recording-btn ll-word-edit-recording-btn ll-study-recording-btn--' . esc_attr($recording_type) . '" data-audio-url="' . esc_url($recording_audio_url) . '" data-recording-type="' . esc_attr($recording_type) . '" data-recording-id="' . esc_attr($recording_id) . '" aria-label="' . esc_attr($recording_play_label) . '" title="' . esc_attr($recording_play_label) . '">';
                            echo '<span class="ll-study-recording-icon" aria-hidden="true"></span>';
                            echo '<span class="ll-study-recording-visualizer" aria-hidden="true">';
                            for ($i = 0; $i < 4; $i++) {
                                echo '<span class="bar"></span>';
                            }
                            echo '</span>';
                            echo '</button>';
                        }
                        echo '</div>';
                        echo '<div class="ll-word-edit-recording-fields">';
                        echo '<label class="ll-word-edit-label" for="' . esc_attr($recording_text_id) . '">' . esc_html($edit_labels['text']) . '</label>';
                        echo '<input type="text" class="ll-word-edit-input" id="' . esc_attr($recording_text_id) . '" data-ll-recording-input="text" value="' . esc_attr($recording_text) . '" />';
                        echo '<label class="ll-word-edit-label" for="' . esc_attr($recording_translation_id) . '">' . esc_html($edit_labels['translation']) . '</label>';
                        echo '<input type="text" class="ll-word-edit-input" id="' . esc_attr($recording_translation_id) . '" data-ll-recording-input="translation" value="' . esc_attr($recording_translation) . '" />';
                        echo '<label class="ll-word-edit-label" for="' . esc_attr($recording_ipa_id) . '">' . esc_html($edit_labels['ipa']) . '</label>';
                        echo '<div class="ll-word-edit-input-wrap ll-word-edit-input-wrap--ipa">';
                        echo '<input type="text" class="ll-word-edit-input ll-word-edit-input--ipa" id="' . esc_attr($recording_ipa_id) . '" data-ll-recording-input="ipa" value="' . esc_attr($recording_ipa) . '" />';
                        echo '</div>';
                        echo '<button type="button" class="ll-word-edit-ipa-superscript" data-ll-ipa-superscript aria-hidden="true" aria-label="' . esc_attr($edit_labels['ipa_superscript']) . '" title="' . esc_attr($edit_labels['ipa_superscript']) . '"><span aria-hidden="true">x&sup2;</span></button>';
                        echo '<div class="ll-word-edit-ipa-keyboard" data-ll-ipa-keyboard aria-hidden="true"></div>';
                        echo '</div>';
                        echo '</div>';
                    }
                    echo '</div>';
                }

                echo '<div class="ll-word-edit-actions">';
                echo '<button type="button" class="ll-word-edit-action ll-word-edit-save" data-ll-word-edit-save aria-label="' . esc_attr($edit_labels['save']) . '" title="' . esc_attr($edit_labels['save']) . '">';
                echo '<span aria-hidden="true">';
                echo '<svg viewBox="0 0 24 24" focusable="false" aria-hidden="true"><path d="M5 13l4 4L19 7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
                echo '</span>';
                echo '</button>';
                echo '<button type="button" class="ll-word-edit-action ll-word-edit-cancel" data-ll-word-edit-cancel aria-label="' . esc_attr($edit_labels['cancel']) . '" title="' . esc_attr($edit_labels['cancel']) . '">';
                echo '<span aria-hidden="true">';
                echo '<svg viewBox="0 0 24 24" focusable="false" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
                echo '</span>';
                echo '</button>';
                echo '</div>';
                echo '<div class="ll-word-edit-status" data-ll-word-edit-status aria-live="polite"></div>';
                echo '</div>';
            }

            // Audio buttons
            if ($has_recordings) {
                if ($has_recording_caption) {
                    $recordings_html .= '<div class="ll-word-recordings ll-word-recordings--with-text" aria-label="' . esc_attr__('Recordings', 'll-tools-text-domain') . '">';
                    foreach ($recording_rows as $row) {
                        $row_id_attr = !empty($row['id']) ? ' data-recording-id="' . esc_attr((int) $row['id']) . '"' : '';
                        $recordings_html .= '<div class="ll-word-recording-row"' . $row_id_attr . '>';
                        $recordings_html .= $row['button'];
                        if (!empty($row['text']) || !empty($row['translation']) || !empty($row['ipa'])) {
                            $recordings_html .= '<span class="ll-word-recording-text">';
                            if (!empty($row['text'])) {
                                $recordings_html .= '<span class="ll-word-recording-text-main">' . esc_html($row['text']) . '</span>';
                            }
                            if (!empty($row['translation'])) {
                                $recordings_html .= '<span class="ll-word-recording-text-translation">' . esc_html($row['translation']) . '</span>';
                            }
                            if (!empty($row['ipa'])) {
                                $recordings_html .= '<span class="ll-word-recording-ipa ll-ipa">' . ll_tools_word_grid_format_ipa_display_html((string) $row['ipa']) . '</span>';
                            }
                            $recordings_html .= '</span>';
                        }
                        $recordings_html .= '</div>';
                    }
                    $recordings_html .= '</div>';
                } else {
                    $recordings_html .= '<div class="ll-word-recordings" aria-label="' . esc_attr__('Recordings', 'll-tools-text-domain') . '">';
                    foreach ($recording_rows as $row) {
                        $recordings_html .= $row['button'];
                    }
                    $recordings_html .= '</div>';
                }
                echo $recordings_html;
            }
            echo '</div>'; // End of word-item
        }
        echo '</div>'; // End of word-grid
    } else {
        // No posts found
        echo '<p>' . esc_html__('No words found in this category.', 'll-tools-text-domain') . '</p>';
    }

    // Restore original Post Data
    wp_reset_postdata();

    // Get the buffer and return it
    return ob_get_clean();
}

function ll_tools_word_grid_parse_recordings_payload($raw): array {
    if (empty($raw)) {
        return [];
    }

    if (is_string($raw)) {
        $decoded = json_decode(stripslashes($raw), true);
    } elseif (is_array($raw)) {
        $decoded = function_exists('wp_unslash') ? wp_unslash($raw) : $raw;
    } else {
        return [];
    }

    if (!is_array($decoded)) {
        return [];
    }

    $parsed = [];
    foreach ($decoded as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $recording_id = isset($entry['id']) ? (int) $entry['id'] : 0;
        if ($recording_id <= 0) {
            continue;
        }
        $parsed[] = [
            'id' => $recording_id,
            'text' => sanitize_text_field($entry['text'] ?? ''),
            'translation' => sanitize_text_field($entry['translation'] ?? ''),
            'ipa' => ll_tools_word_grid_sanitize_ipa((string) ($entry['ipa'] ?? '')),
        ];
    }

    return $parsed;
}

function ll_tools_resolve_audio_file_for_transcription(string $audio_path): array {
    $audio_path = trim($audio_path);
    if ($audio_path === '') {
        return [
            'path' => '',
            'is_temp' => false,
        ];
    }

    if (strpos($audio_path, 'http') === 0) {
        $parsed = wp_parse_url($audio_path);
        $home = wp_parse_url(home_url('/'));
        if (!empty($parsed['path']) && (!empty($home['host']) && (empty($parsed['host']) || $parsed['host'] === $home['host']))) {
            $local_path = ABSPATH . ltrim($parsed['path'], '/');
            if (file_exists($local_path) && is_readable($local_path)) {
                return [
                    'path' => $local_path,
                    'is_temp' => false,
                ];
            }
        }

        if (!function_exists('download_url') && file_exists(ABSPATH . 'wp-admin/includes/file.php')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if (function_exists('download_url')) {
            $temp = download_url($audio_path);
            if (!is_wp_error($temp) && is_string($temp) && $temp !== '') {
                return [
                    'path' => $temp,
                    'is_temp' => true,
                ];
            }
        }

        return [
            'path' => '',
            'is_temp' => false,
        ];
    }

    $local_path = ABSPATH . ltrim($audio_path, '/');
    if (file_exists($local_path) && is_readable($local_path)) {
        return [
            'path' => $local_path,
            'is_temp' => false,
        ];
    }

    if (file_exists($audio_path) && is_readable($audio_path)) {
        return [
            'path' => $audio_path,
            'is_temp' => false,
        ];
    }

    return [
        'path' => '',
        'is_temp' => false,
    ];
}

function ll_tools_get_lesson_word_ids_for_transcription(int $wordset_id, int $category_id): array {
    $wordset_id = (int) $wordset_id;
    $category_id = (int) $category_id;
    if ($wordset_id <= 0 || $category_id <= 0) {
        return [];
    }

    $query = new WP_Query([
        'post_type'      => 'words',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'tax_query'      => [
            [
                'taxonomy' => 'word-category',
                'field'    => 'term_id',
                'terms'    => [$category_id],
            ],
            [
                'taxonomy' => 'wordset',
                'field'    => 'term_id',
                'terms'    => [$wordset_id],
            ],
        ],
    ]);

    $word_ids = array_values(array_filter(array_map('intval', (array) $query->posts), function ($id) { return $id > 0; }));
    if (empty($word_ids)) {
        return [];
    }

    if (function_exists('ll_get_deepest_categories')) {
        $filtered = [];
        foreach ($word_ids as $word_id) {
            $deepest_terms = ll_get_deepest_categories($word_id);
            $deepest_ids = wp_list_pluck((array) $deepest_terms, 'term_id');
            if (in_array($category_id, array_map('intval', $deepest_ids), true)) {
                $filtered[] = $word_id;
            }
        }
        $word_ids = $filtered;
    }

    return $word_ids;
}

function ll_tools_get_vocab_lesson_ids_from_post(int $lesson_id): array {
    if ($lesson_id <= 0) {
        return [0, 0];
    }
    $wordset_id = (int) get_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, true);
    $category_id = (int) get_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, true);
    return [$wordset_id, $category_id];
}

function ll_tools_lesson_recording_belongs_to(int $recording_id, int $wordset_id, int $category_id): bool {
    $recording_id = (int) $recording_id;
    if ($recording_id <= 0 || $wordset_id <= 0 || $category_id <= 0) {
        return false;
    }
    $recording = get_post($recording_id);
    if (!$recording || $recording->post_type !== 'word_audio') {
        return false;
    }
    $word_id = (int) $recording->post_parent;
    if ($word_id <= 0) {
        return false;
    }
    if (!has_term($wordset_id, 'wordset', $word_id) || !has_term($category_id, 'word-category', $word_id)) {
        return false;
    }
    if (function_exists('ll_get_deepest_categories')) {
        $deepest_terms = ll_get_deepest_categories($word_id);
        $deepest_ids = wp_list_pluck((array) $deepest_terms, 'term_id');
        if (!in_array($category_id, array_map('intval', $deepest_ids), true)) {
            return false;
        }
    }
    return true;
}

function ll_tools_can_transcribe_recordings(): bool {
    if (!function_exists('ll_tools_assemblyai_transcribe_audio_file') || !function_exists('ll_get_assemblyai_api_key')) {
        return false;
    }
    return ll_get_assemblyai_api_key() !== '';
}

function ll_tools_recording_is_isolation(int $recording_id): bool {
    if ($recording_id <= 0) {
        return false;
    }
    $terms = wp_get_post_terms($recording_id, 'recording_type', ['fields' => 'slugs']);
    if (is_wp_error($terms) || empty($terms)) {
        return false;
    }
    return in_array('isolation', (array) $terms, true);
}

function ll_tools_trim_isolation_transcript(string $text): string {
    $trimmed = rtrim($text, " \t\n\r\0\x0B.,!?;:");
    return $trimmed !== '' ? $trimmed : trim($text);
}

function ll_tools_get_isolation_recording_data(int $word_id): array {
    $word_id = (int) $word_id;
    if ($word_id <= 0) {
        return [
            'has_isolation' => false,
            'text' => '',
            'translation' => '',
        ];
    }

    $recording_ids = get_posts([
        'post_type'      => 'word_audio',
        'post_status'    => 'publish',
        'post_parent'    => $word_id,
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'tax_query'      => [
            [
                'taxonomy' => 'recording_type',
                'field'    => 'slug',
                'terms'    => ['isolation'],
            ],
        ],
    ]);

    $recording_ids = array_values(array_filter(array_map('intval', (array) $recording_ids), function ($id) { return $id > 0; }));
    $data = [
        'has_isolation' => !empty($recording_ids),
        'text' => '',
        'translation' => '',
    ];

    if (empty($recording_ids)) {
        return $data;
    }

    foreach ($recording_ids as $recording_id) {
        $text = trim((string) get_post_meta($recording_id, 'recording_text', true));
        $translation = trim((string) get_post_meta($recording_id, 'recording_translation', true));
        if ($text !== '' || $translation !== '') {
            $data['text'] = $text;
            $data['translation'] = $translation;
            break;
        }
    }

    return $data;
}

function ll_tools_fill_missing_word_fields_from_recording(int $word_id, string $candidate_text, string $candidate_translation): array {
    $display_values = ll_tools_word_grid_resolve_display_text($word_id);
    $store_in_title = isset($display_values['store_in_title']) ? (bool) $display_values['store_in_title'] : true;
    $current_text = trim((string) ($display_values['word_text'] ?? ''));
    $current_translation = trim((string) ($display_values['translation_text'] ?? ''));

    $candidate_text = trim($candidate_text);
    $candidate_translation = trim($candidate_translation);

    $updated = false;
    if ($current_text === '' && $candidate_text !== '') {
        $word_text = function_exists('ll_sanitize_word_title_text')
            ? ll_sanitize_word_title_text($candidate_text)
            : $candidate_text;
        if ($store_in_title) {
            wp_update_post([
                'ID' => $word_id,
                'post_title' => $word_text,
            ]);
        } else {
            update_post_meta($word_id, 'word_translation', $word_text);
        }
        $updated = true;
    }

    if ($current_translation === '' && $candidate_translation !== '') {
        $translation_text = sanitize_text_field($candidate_translation);
        if ($store_in_title) {
            update_post_meta($word_id, 'word_translation', $translation_text);
        } else {
            wp_update_post([
                'ID' => $word_id,
                'post_title' => $translation_text,
            ]);
        }
        update_post_meta($word_id, 'word_english_meaning', $translation_text);
        $updated = true;
    }

    if ($updated) {
        $display_values = ll_tools_word_grid_resolve_display_text($word_id);
    }

    return [
        'updated' => $updated,
        'word_text' => (string) ($display_values['word_text'] ?? ''),
        'word_translation' => (string) ($display_values['translation_text'] ?? ''),
    ];
}

add_action('wp_ajax_ll_tools_word_grid_update_word', 'll_tools_word_grid_update_word_handler');
function ll_tools_word_grid_update_word_handler() {
    check_ajax_referer('ll_word_grid_edit', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error('You must be logged in', 401);
    }

    if (!ll_tools_user_can_edit_vocab_words()) {
        wp_send_json_error('Forbidden', 403);
    }

    $word_id = (int) ($_POST['word_id'] ?? 0);
    if ($word_id <= 0) {
        wp_send_json_error('Missing word ID', 400);
    }

    $word_post = get_post($word_id);
    if (!$word_post || $word_post->post_type !== 'words') {
        wp_send_json_error('Invalid word ID', 404);
    }

    $word_text_raw = sanitize_text_field($_POST['word_text'] ?? '');
    $word_text = function_exists('ll_sanitize_word_title_text')
        ? ll_sanitize_word_title_text($word_text_raw)
        : trim($word_text_raw);
    $translation_text = sanitize_text_field($_POST['word_translation'] ?? '');
    $translation_text = trim($translation_text);

    $store_in_title = function_exists('ll_tools_should_store_word_in_title')
        ? ll_tools_should_store_word_in_title($word_id)
        : true;
    $new_title = $word_post->post_title;
    if ($store_in_title) {
        $new_title = $word_text;
    } else {
        $new_title = $translation_text;
    }

    if ($new_title !== $word_post->post_title) {
        wp_update_post([
            'ID' => $word_id,
            'post_title' => $new_title,
        ]);
    }

    if ($translation_text !== '') {
        update_post_meta($word_id, 'word_english_meaning', $translation_text);
    } else {
        delete_post_meta($word_id, 'word_english_meaning');
    }
    if ($store_in_title) {
        if ($translation_text !== '') {
            update_post_meta($word_id, 'word_translation', $translation_text);
        } else {
            delete_post_meta($word_id, 'word_translation');
        }
    } else {
        if ($word_text !== '') {
            update_post_meta($word_id, 'word_translation', $word_text);
        } else {
            delete_post_meta($word_id, 'word_translation');
        }
    }

    $recordings_payload = ll_tools_word_grid_parse_recordings_payload($_POST['recordings'] ?? '');
    $recordings_out = [];
    foreach ($recordings_payload as $recording) {
        $recording_id = (int) ($recording['id'] ?? 0);
        if ($recording_id <= 0) {
            continue;
        }
        $recording_post = get_post($recording_id);
        if (!$recording_post || $recording_post->post_type !== 'word_audio') {
            continue;
        }
        if ((int) $recording_post->post_parent !== $word_id) {
            continue;
        }

        $recording_text = (string) ($recording['text'] ?? '');
        $recording_translation = (string) ($recording['translation'] ?? '');
        $recording_ipa = (string) ($recording['ipa'] ?? '');

        if ($recording_text !== '') {
            update_post_meta($recording_id, 'recording_text', $recording_text);
        } else {
            delete_post_meta($recording_id, 'recording_text');
        }
        if ($recording_translation !== '') {
            update_post_meta($recording_id, 'recording_translation', $recording_translation);
        } else {
            delete_post_meta($recording_id, 'recording_translation');
        }
        if ($recording_ipa !== '') {
            update_post_meta($recording_id, 'recording_ipa', $recording_ipa);
        } else {
            delete_post_meta($recording_id, 'recording_ipa');
        }

        $recordings_out[] = [
            'id' => $recording_id,
            'recording_text' => (string) get_post_meta($recording_id, 'recording_text', true),
            'recording_translation' => (string) get_post_meta($recording_id, 'recording_translation', true),
            'recording_ipa' => ll_tools_word_grid_normalize_ipa_output((string) get_post_meta($recording_id, 'recording_ipa', true)),
        ];
    }

    ll_tools_word_grid_update_wordset_ipa_special_chars($word_id, '');

    $display_values = ll_tools_word_grid_resolve_display_text($word_id);

    wp_send_json_success([
        'word_id' => $word_id,
        'word_text' => $display_values['word_text'],
        'word_translation' => $display_values['translation_text'],
        'recordings' => $recordings_out,
    ]);
}

add_action('wp_ajax_ll_tools_get_lesson_transcribe_queue', 'll_tools_get_lesson_transcribe_queue_handler');
function ll_tools_get_lesson_transcribe_queue_handler() {
    check_ajax_referer('ll_word_grid_edit', 'nonce');

    if (!ll_tools_user_can_edit_vocab_words()) {
        wp_send_json_error('Forbidden', 403);
    }
    if (!ll_tools_can_transcribe_recordings()) {
        wp_send_json_error('Transcription service not configured', 400);
    }

    $mode = sanitize_text_field($_POST['mode'] ?? 'missing');
    $include_existing = in_array($mode, ['all', 'replace'], true);

    $lesson_id = (int) ($_POST['lesson_id'] ?? 0);
    $lesson = $lesson_id ? get_post($lesson_id) : null;
    if (!$lesson || $lesson->post_type !== 'll_vocab_lesson') {
        wp_send_json_error('Invalid lesson', 400);
    }

    [$wordset_id, $category_id] = ll_tools_get_vocab_lesson_ids_from_post($lesson_id);
    if ($wordset_id <= 0 || $category_id <= 0) {
        wp_send_json_error('Missing lesson metadata', 400);
    }

    $word_ids = ll_tools_get_lesson_word_ids_for_transcription($wordset_id, $category_id);
    if (empty($word_ids)) {
        wp_send_json_success([
            'queue' => [],
            'total' => 0,
        ]);
    }

    $recording_ids = get_posts([
        'post_type'      => 'word_audio',
        'post_status'    => 'publish',
        'post_parent__in'=> $word_ids,
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ]);

    $queue = [];
    foreach ($recording_ids as $recording_id) {
        $recording_id = (int) $recording_id;
        if ($recording_id <= 0) {
            continue;
        }
        $text = trim((string) get_post_meta($recording_id, 'recording_text', true));
        if (!$include_existing && $text !== '') {
            continue;
        }
        if (!ll_tools_lesson_recording_belongs_to($recording_id, $wordset_id, $category_id)) {
            continue;
        }
        $queue[] = [
            'recording_id' => $recording_id,
        ];
    }

    wp_send_json_success([
        'queue' => $queue,
        'total' => count($queue),
    ]);
}

add_action('wp_ajax_ll_tools_clear_lesson_transcriptions', 'll_tools_clear_lesson_transcriptions_handler');
function ll_tools_clear_lesson_transcriptions_handler() {
    check_ajax_referer('ll_word_grid_edit', 'nonce');

    if (!ll_tools_user_can_edit_vocab_words()) {
        wp_send_json_error('Forbidden', 403);
    }

    $lesson_id = (int) ($_POST['lesson_id'] ?? 0);
    $lesson = $lesson_id ? get_post($lesson_id) : null;
    if (!$lesson || $lesson->post_type !== 'll_vocab_lesson') {
        wp_send_json_error('Invalid lesson', 400);
    }

    [$wordset_id, $category_id] = ll_tools_get_vocab_lesson_ids_from_post($lesson_id);
    if ($wordset_id <= 0 || $category_id <= 0) {
        wp_send_json_error('Missing lesson metadata', 400);
    }

    $word_ids = ll_tools_get_lesson_word_ids_for_transcription($wordset_id, $category_id);
    if (empty($word_ids)) {
        wp_send_json_success([
            'cleared' => [],
            'total' => 0,
        ]);
    }

    $recording_ids = get_posts([
        'post_type'      => 'word_audio',
        'post_status'    => 'publish',
        'post_parent__in'=> $word_ids,
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ]);

    $cleared = [];
    foreach ($recording_ids as $recording_id) {
        $recording_id = (int) $recording_id;
        if ($recording_id <= 0) {
            continue;
        }
        if (!ll_tools_lesson_recording_belongs_to($recording_id, $wordset_id, $category_id)) {
            continue;
        }
        delete_post_meta($recording_id, 'recording_text');
        delete_post_meta($recording_id, 'recording_translation');
        $cleared[] = $recording_id;
    }

    wp_send_json_success([
        'cleared' => $cleared,
        'total' => count($cleared),
    ]);
}

add_action('wp_ajax_ll_tools_transcribe_recording_by_id', 'll_tools_transcribe_recording_by_id_handler');
function ll_tools_transcribe_recording_by_id_handler() {
    check_ajax_referer('ll_word_grid_edit', 'nonce');

    if (!ll_tools_user_can_edit_vocab_words()) {
        wp_send_json_error('Forbidden', 403);
    }
    if (!ll_tools_can_transcribe_recordings()) {
        wp_send_json_error('Transcription service not configured', 400);
    }

    $lesson_id = (int) ($_POST['lesson_id'] ?? 0);
    $recording_id = (int) ($_POST['recording_id'] ?? 0);
    $force = !empty($_POST['force']);
    if ($lesson_id <= 0 || $recording_id <= 0) {
        wp_send_json_error('Missing data', 400);
    }

    $lesson = get_post($lesson_id);
    if (!$lesson || $lesson->post_type !== 'll_vocab_lesson') {
        wp_send_json_error('Invalid lesson', 400);
    }

    [$wordset_id, $category_id] = ll_tools_get_vocab_lesson_ids_from_post($lesson_id);
    if ($wordset_id <= 0 || $category_id <= 0) {
        wp_send_json_error('Missing lesson metadata', 400);
    }

    if (!ll_tools_lesson_recording_belongs_to($recording_id, $wordset_id, $category_id)) {
        wp_send_json_error('Recording not in lesson', 403);
    }

    $recording = get_post($recording_id);
    if (!$recording || $recording->post_type !== 'word_audio') {
        wp_send_json_error('Invalid recording', 400);
    }

    $existing_text = trim((string) get_post_meta($recording_id, 'recording_text', true));
    if (!$force && $existing_text !== '') {
        wp_send_json_success([
            'skipped' => true,
        ]);
    }

    $audio_path = (string) get_post_meta($recording_id, 'audio_file_path', true);
    $file_info = ll_tools_resolve_audio_file_for_transcription($audio_path);
    $file_path = (string) ($file_info['path'] ?? '');
    $is_temp = !empty($file_info['is_temp']);
    if ($file_path === '' || !file_exists($file_path) || !is_readable($file_path)) {
        if ($is_temp && $file_path !== '' && file_exists($file_path)) {
            @unlink($file_path);
        }
        wp_send_json_error('Audio file missing', 400);
    }

    $wordset_ids = [$wordset_id];
    $language_code = function_exists('ll_tools_get_assemblyai_language_code')
        ? ll_tools_get_assemblyai_language_code($wordset_ids)
        : '';

    $result = ll_tools_assemblyai_transcribe_audio_file($file_path, $language_code);
    if ($is_temp && $file_path !== '' && file_exists($file_path)) {
        @unlink($file_path);
    }
    if (is_wp_error($result)) {
        wp_send_json_error($result->get_error_message(), 500);
    }

    $transcript = trim((string) ($result['text'] ?? ''));
    if ($transcript !== '' && function_exists('ll_tools_normalize_transcript_case')) {
        $transcript = ll_tools_normalize_transcript_case($transcript, $wordset_ids);
    }
    if ($transcript === '') {
        wp_send_json_error('Empty transcript', 500);
    }

    $recording_text = sanitize_text_field($transcript);
    $recording_is_isolation = ll_tools_recording_is_isolation($recording_id);
    if ($recording_is_isolation) {
        $recording_text = ll_tools_trim_isolation_transcript($recording_text);
    }
    update_post_meta($recording_id, 'recording_text', $recording_text);

    $recording_translation = '';
    $existing_translation = trim((string) get_post_meta($recording_id, 'recording_translation', true));
    $can_translate = ($force || $existing_translation === '')
        && ((string) get_option('ll_deepl_api_key') !== '')
        && function_exists('translate_with_deepl')
        && function_exists('ll_tools_get_deepl_language_codes');
    if ($can_translate) {
        [$source_lang, $target_lang] = ll_tools_get_deepl_language_codes($wordset_ids);
        if ($target_lang !== '') {
            $translated = translate_with_deepl($recording_text, $target_lang, $source_lang);
            if (is_string($translated) && $translated !== '') {
                $recording_translation = sanitize_text_field($translated);
                update_post_meta($recording_id, 'recording_translation', $recording_translation);
            }
        }
    } elseif ($existing_translation !== '') {
        $recording_translation = $existing_translation;
    }
    if ($recording_translation === '' && $existing_translation !== '') {
        $recording_translation = $existing_translation;
    }

    $word_payload = null;
    $word_id = (int) $recording->post_parent;
    if ($word_id > 0) {
        $display_values = ll_tools_word_grid_resolve_display_text($word_id);
        $missing_word_text = trim((string) ($display_values['word_text'] ?? '')) === '';
        $missing_translation = trim((string) ($display_values['translation_text'] ?? '')) === '';
        if ($missing_word_text || $missing_translation) {
            $candidate_text = '';
            $candidate_translation = '';
            $isolation_data = ll_tools_get_isolation_recording_data($word_id);
            $has_isolation = !empty($isolation_data['has_isolation']);

            if ($has_isolation) {
                if ($recording_is_isolation) {
                    $candidate_text = $recording_text !== '' ? $recording_text : (string) ($isolation_data['text'] ?? '');
                    $candidate_translation = $recording_translation !== '' ? $recording_translation : (string) ($isolation_data['translation'] ?? '');
                } elseif (!empty($isolation_data['text']) || !empty($isolation_data['translation'])) {
                    $candidate_text = (string) ($isolation_data['text'] ?? '');
                    $candidate_translation = (string) ($isolation_data['translation'] ?? '');
                }
            } else {
                $candidate_text = $recording_text;
                $candidate_translation = $recording_translation;
            }

            if ($candidate_text !== '' || $candidate_translation !== '') {
                $word_update = ll_tools_fill_missing_word_fields_from_recording(
                    $word_id,
                    $candidate_text,
                    $candidate_translation
                );
                $word_payload = [
                    'id' => $word_id,
                    'word_text' => (string) ($word_update['word_text'] ?? ''),
                    'word_translation' => (string) ($word_update['word_translation'] ?? ''),
                    'updated' => !empty($word_update['updated']),
                ];
            }
        }
    }

    $response = [
        'recording' => [
            'id' => $recording_id,
            'word_id' => $word_id,
            'recording_text' => $recording_text,
            'recording_translation' => $recording_translation,
            'recording_ipa' => ll_tools_word_grid_normalize_ipa_output((string) get_post_meta($recording_id, 'recording_ipa', true)),
        ],
    ];
    if ($word_payload) {
        $response['word'] = $word_payload;
    }

    wp_send_json_success($response);
}

// Register the 'word_grid' shortcode
function ll_tools_register_word_grid_shortcode() {
    add_shortcode('word_grid', 'll_tools_word_grid_shortcode');
}
add_action('init', 'll_tools_register_word_grid_shortcode');
