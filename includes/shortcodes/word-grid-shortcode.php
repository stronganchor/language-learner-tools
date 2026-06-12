<?php
if (!defined('WPINC')) { die; }

function ll_tools_word_grid_audio_url_from_path(string $audio_path): string {
    $audio_path = trim($audio_path);
    if ($audio_path === '') {
        return '';
    }

    if (function_exists('ll_tools_resolve_audio_file_url')) {
        return (string) ll_tools_resolve_audio_file_url($audio_path);
    }

    return (0 === strpos($audio_path, 'http')) ? $audio_path : site_url($audio_path);
}

function ll_tools_word_grid_collect_audio_files(array $word_ids, bool $include_meta = false): array {
    $word_ids = array_values(array_filter(array_map('intval', $word_ids), function ($id) { return $id > 0; }));
    if (empty($word_ids)) {
        return [];
    }
    $can_view_review_transcriptions = current_user_can('view_ll_tools');

    $audio_posts = get_posts([
        'post_type'      => 'word_audio',
        'post_parent__in'=> $word_ids,
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'suppress_filters' => true,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ]);
    if (empty($audio_posts)) {
        return [];
    }

    $audio_ids = array_values(array_filter(array_map(static function ($audio_post): int {
        return isset($audio_post->ID) ? (int) $audio_post->ID : 0;
    }, $audio_posts), static function ($audio_id): bool {
        return $audio_id > 0;
    }));
    if (empty($audio_ids)) {
        return [];
    }

    update_meta_cache('post', $audio_ids);

    $recording_types_by_audio = [];
    $recording_terms = wp_get_object_terms($audio_ids, 'recording_type', ['fields' => 'all_with_object_id']);
    if (!is_wp_error($recording_terms) && !empty($recording_terms)) {
        foreach ($recording_terms as $term) {
            $audio_id = isset($term->object_id) ? (int) $term->object_id : 0;
            if ($audio_id <= 0 || empty($term->slug)) {
                continue;
            }
            $recording_types_by_audio[$audio_id][(string) $term->slug] = true;
        }
    }

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
        $audio_url = ll_tools_word_grid_audio_url_from_path((string) $audio_path);
        $processing_source_path = (string) $audio_path;
        $original_audio_path = '';
        if (!preg_match('#^https?://#i', (string) $audio_path) && function_exists('ll_tools_get_audio_processing_source_file_path')) {
            $processing_source_path = ll_tools_get_audio_processing_source_file_path((int) $audio_post->ID, (string) $audio_path);
        }
        if (function_exists('ll_tools_get_audio_original_file_path')) {
            $original_audio_path = ll_tools_get_audio_original_file_path((int) $audio_post->ID);
        }
        $has_original_audio = $original_audio_path !== ''
            && (!function_exists('ll_tools_audio_relative_file_exists') || ll_tools_audio_relative_file_exists($original_audio_path));
        $processing_source_url = ll_tools_word_grid_audio_url_from_path($processing_source_path);
        $recording_types = array_keys($recording_types_by_audio[(int) $audio_post->ID] ?? []);
        if (empty($recording_types)) {
            continue;
        }

        $speaker_uid = (int) get_post_meta($audio_post->ID, 'speaker_user_id', true);
        if (!$speaker_uid) {
            $speaker_uid = (int) $audio_post->post_author;
        }
        $audio_attribution = function_exists('ll_tools_get_audio_attribution_meta')
            ? ll_tools_get_audio_attribution_meta((int) $audio_post->ID)
            : [];

        $recording_text = '';
        $recording_translation = '';
        $recording_ipa = '';
        $recording_review_fields = [
            'recording_text' => false,
            'recording_ipa' => false,
        ];
        $recording_review_note = '';
        if ($include_meta) {
            $recording_text = get_post_meta($audio_post->ID, 'recording_text', true);
            $recording_translation = get_post_meta($audio_post->ID, 'recording_translation', true);
            $recording_ipa = get_post_meta($audio_post->ID, 'recording_ipa', true);
            if (function_exists('ll_tools_ipa_keyboard_get_recording_review_fields')) {
                $recording_review_fields = ll_tools_ipa_keyboard_get_recording_review_fields((int) $audio_post->ID);
            } elseif (function_exists('ll_tools_ipa_keyboard_recording_needs_auto_review') && ll_tools_ipa_keyboard_recording_needs_auto_review((int) $audio_post->ID)) {
                $recording_review_fields['recording_ipa'] = true;
            }
            if (!$can_view_review_transcriptions) {
                if (!empty($recording_review_fields['recording_text'])) {
                    $recording_text = '';
                }
                if (!empty($recording_review_fields['recording_ipa'])) {
                    $recording_ipa = '';
                }
                $recording_review_fields = [
                    'recording_text' => false,
                    'recording_ipa' => false,
                ];
            } elseif (function_exists('ll_tools_ipa_keyboard_get_recording_review_note')) {
                $recording_review_note = ll_tools_ipa_keyboard_get_recording_review_note((int) $audio_post->ID);
            }
        }

        foreach ($recording_types as $type) {
            $type = sanitize_text_field($type);
            if ($type === '') {
                continue;
            }
            $entry = [
                'id'              => (int) $audio_post->ID,
                'url'             => $audio_url,
                'processing_source_url' => $processing_source_url !== '' ? $processing_source_url : $audio_url,
                'uses_original_audio' => ($processing_source_path !== '' && $processing_source_path !== (string) $audio_path),
                'has_original_audio' => $has_original_audio,
                'recording_type'  => $type,
                'speaker_user_id' => $speaker_uid,
            ];
            foreach ($audio_attribution as $field_key => $field_value) {
                $field_value = trim((string) $field_value);
                if ($field_value !== '') {
                    $entry[$field_key] = $field_value;
                }
            }
            if ($include_meta) {
                $entry['recording_text'] = $recording_text;
                $entry['recording_translation'] = $recording_translation;
                $entry['recording_ipa'] = $recording_ipa;
                $entry['review_fields'] = $recording_review_fields;
                $entry['needs_review'] = !empty(array_filter($recording_review_fields));
                $entry['review_note'] = $recording_review_note;
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

function ll_tools_word_grid_normalize_recording_type_slug($type): string {
    if (function_exists('ll_tools_normalize_practice_recording_type_slug')) {
        return ll_tools_normalize_practice_recording_type_slug($type);
    }

    $raw = strtolower(trim((string) $type));
    if ($raw === '') {
        return '';
    }

    $raw = preg_replace('/[\s_]+/', '-', $raw);
    return sanitize_key((string) $raw);
}

function ll_tools_word_grid_get_lesson_recording_type_order(): array {
    $order = function_exists('ll_tools_practice_recording_type_order')
        ? ll_tools_practice_recording_type_order()
        : ['question', 'isolation', 'introduction', 'sentence', 'in-sentence'];

    if (!is_array($order) || empty($order)) {
        $order = ['question', 'isolation', 'introduction', 'sentence', 'in-sentence'];
    }

    $normalized = [];
    foreach ($order as $type) {
        $slug = ll_tools_word_grid_normalize_recording_type_slug($type);
        if ($slug !== '') {
            $normalized[$slug] = $slug;
        }
    }

    foreach (['question', 'isolation', 'introduction'] as $required_type) {
        if (!isset($normalized[$required_type])) {
            $normalized[$required_type] = $required_type;
        }
    }

    return array_values($normalized);
}

function ll_tools_word_grid_get_audio_entry_key(array $entry, string $type): string {
    $recording_id = isset($entry['id']) ? (int) $entry['id'] : 0;
    if ($recording_id > 0) {
        return 'id:' . $recording_id;
    }

    $audio_url = isset($entry['url']) ? (string) $entry['url'] : '';
    return 'url:' . md5($type . '|' . $audio_url);
}

function ll_tools_word_grid_get_ordered_recording_types_for_audio(array $audio_files, array $recording_type_order): array {
    $ordered = [];
    foreach ($recording_type_order as $type) {
        $slug = ll_tools_word_grid_normalize_recording_type_slug($type);
        if ($slug !== '') {
            $ordered[$slug] = $slug;
        }
    }

    foreach ($audio_files as $audio_file_entry) {
        $type = isset($audio_file_entry['recording_type'])
            ? ll_tools_word_grid_normalize_recording_type_slug((string) $audio_file_entry['recording_type'])
            : '';
        if ($type === '' || isset($ordered[$type])) {
            continue;
        }
        $ordered[$type] = $type;
    }

    return array_values($ordered);
}

function ll_tools_word_grid_get_recording_display_entries(array $audio_files, array $main_recording_types, array $recording_type_order, bool $include_secondary = false): array {
    if (empty($audio_files)) {
        return [];
    }

    $preferred_speaker = ll_tools_word_grid_get_preferred_speaker($audio_files, $main_recording_types);
    $ordered_types = ll_tools_word_grid_get_ordered_recording_types_for_audio($audio_files, $recording_type_order);
    $entries = [];
    $used_keys = [];
    $primary_types = [];

    foreach ($ordered_types as $type) {
        $entry = ll_tools_word_grid_select_audio_entry($audio_files, (string) $type, $preferred_speaker);
        $audio_url = isset($entry['url']) ? trim((string) $entry['url']) : '';
        if ($audio_url === '') {
            continue;
        }

        $key = ll_tools_word_grid_get_audio_entry_key($entry, (string) $type);
        if (isset($used_keys[$key])) {
            continue;
        }

        $entry['recording_type'] = (string) $type;
        $entries[] = [
            'entry' => $entry,
            'type' => (string) $type,
            'secondary' => false,
            'duplicate' => false,
            'visibility_note' => '',
        ];
        $used_keys[$key] = true;
        $primary_types[(string) $type] = true;
    }

    if (!$include_secondary) {
        return $entries;
    }

    foreach ($audio_files as $audio_file_entry) {
        $type = isset($audio_file_entry['recording_type'])
            ? ll_tools_word_grid_normalize_recording_type_slug((string) $audio_file_entry['recording_type'])
            : '';
        $audio_url = isset($audio_file_entry['url']) ? trim((string) $audio_file_entry['url']) : '';
        if ($type === '' || $audio_url === '') {
            continue;
        }

        $key = ll_tools_word_grid_get_audio_entry_key((array) $audio_file_entry, $type);
        if (isset($used_keys[$key])) {
            continue;
        }

        $audio_file_entry['recording_type'] = $type;
        $entries[] = [
            'entry' => (array) $audio_file_entry,
            'type' => $type,
            'secondary' => true,
            'duplicate' => isset($primary_types[$type]),
            'visibility_note' => isset($primary_types[$type])
                ? __('Duplicate: not used as the default practice recording.', 'll-tools-text-domain')
                : __('Secondary recording.', 'll-tools-text-domain'),
        ];
        $used_keys[$key] = true;
    }

    return $entries;
}

function ll_tools_word_grid_get_recording_launch_items_by_word(array $word_ids, int $wordset_id, $category_term = null): array {
    $word_ids = array_values(array_unique(array_filter(array_map('intval', $word_ids), static function (int $word_id): bool {
        return $word_id > 0;
    })));
    $wordset_id = (int) $wordset_id;
    if (empty($word_ids) || $wordset_id <= 0) {
        return [];
    }
    if (!function_exists('ll_tools_user_can_record') || !ll_tools_user_can_record()) {
        return [];
    }
    if (!function_exists('ll_get_recording_redirect_url') || !function_exists('ll_tools_get_recording_queue_items')) {
        return [];
    }

    $recording_wordset_ids = [$wordset_id];
    if (function_exists('ll_tools_filter_recording_wordset_ids_for_user')) {
        $recording_wordset_ids = ll_tools_filter_recording_wordset_ids_for_user($recording_wordset_ids, get_current_user_id());
    }
    $recording_wordset_ids = array_values(array_unique(array_filter(array_map('intval', (array) $recording_wordset_ids), static function (int $id): bool {
        return $id > 0;
    })));
    if (!in_array($wordset_id, $recording_wordset_ids, true)) {
        return [];
    }

    $category_slug = '';
    if ($category_term instanceof WP_Term && !is_wp_error($category_term)) {
        $category_slug = (string) $category_term->slug;
    }

    $user_config = get_user_meta(get_current_user_id(), 'll_recording_config', true);
    $include_types = is_array($user_config) ? trim((string) ($user_config['include_recording_types'] ?? '')) : '';
    $exclude_types = is_array($user_config) ? trim((string) ($user_config['exclude_recording_types'] ?? '')) : '';

    $queue_items = ll_tools_get_recording_queue_items($category_slug, [$wordset_id], $include_types, $exclude_types, false, get_current_user_id());
    if (empty($queue_items)) {
        return [];
    }

    $word_lookup = array_fill_keys($word_ids, true);
    $base_url = (string) ll_get_recording_redirect_url(get_current_user_id());
    if ($base_url === '') {
        return [];
    }

    $items_by_word = [];
    foreach ((array) $queue_items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $word_id = (int) ($item['word_id'] ?? 0);
        if ($word_id <= 0 || !isset($word_lookup[$word_id]) || isset($items_by_word[$word_id])) {
            continue;
        }

        $missing_types = array_values(array_filter(array_map('sanitize_key', (array) ($item['missing_types'] ?? []))));
        if (empty($missing_types)) {
            continue;
        }

        $item_category_slug = sanitize_title((string) ($item['category_slug'] ?? ''));
        if ($item_category_slug === '') {
            $item_category_slug = 'uncategorized';
        }

        $items_by_word[$word_id] = [
            'url' => add_query_arg([
                'll_record_wordset'  => (string) $wordset_id,
                'll_record_category' => $item_category_slug,
                'll_record_word'     => (string) $word_id,
            ], $base_url),
            'missing_types' => $missing_types,
            'category_slug' => $item_category_slug,
            'category_name' => sanitize_text_field((string) ($item['category_name'] ?? '')),
        ];
    }

    return $items_by_word;
}

function ll_tools_word_grid_render_recording_launch_button(array $launch_item, string $word_text): string {
    $url = isset($launch_item['url']) ? (string) $launch_item['url'] : '';
    if ($url === '') {
        return '';
    }

    $missing_count = count(array_values(array_filter((array) ($launch_item['missing_types'] ?? []))));
    $word_label = trim(wp_strip_all_tags($word_text));
    if ($word_label === '') {
        $word_label = __('this word', 'll-tools-text-domain');
    }
    $label = sprintf(
        /* translators: %s: word title */
        __('Record missing audio for %s', 'll-tools-text-domain'),
        $word_label
    );
    $loading_label = __('Opening recorder...', 'll-tools-text-domain');

    $html = '<a class="ll-word-recording-launch" href="' . esc_url($url) . '" aria-label="' . esc_attr($label) . '" title="' . esc_attr($label) . '" data-loading-label="' . esc_attr($loading_label) . '">';
    $html .= '<span class="ll-word-recording-launch__icon" aria-hidden="true">';
    $html .= '<svg viewBox="0 0 24 24" focusable="false" aria-hidden="true">';
    $html .= '<circle cx="12" cy="12" r="8" fill="currentColor"/>';
    $html .= '</svg>';
    $html .= '</span>';
    if ($missing_count > 0) {
        $html .= '<span class="ll-word-recording-launch__count" aria-hidden="true">' . esc_html((string) $missing_count) . '</span>';
    }
    $html .= '</a>';

    return $html;
}

function ll_tools_word_grid_word_in_deepest_category(int $word_id, int $category_id): bool {
    $word_id = (int) $word_id;
    $category_id = (int) $category_id;
    if ($word_id <= 0 || $category_id <= 0 || !function_exists('ll_get_deepest_categories')) {
        return false;
    }

    $deepest_terms = ll_get_deepest_categories($word_id);
    $deepest_ids = array_map('intval', wp_list_pluck((array) $deepest_terms, 'term_id'));
    return in_array($category_id, $deepest_ids, true);
}

function ll_tools_word_grid_filter_word_ids_to_deepest_category(array $word_ids, int $category_id): array {
    $word_ids = array_values(array_filter(array_map('intval', $word_ids), static function ($word_id): bool {
        return $word_id > 0;
    }));
    $category_id = (int) $category_id;
    if (empty($word_ids) || $category_id <= 0) {
        return $word_ids;
    }

    $terms = wp_get_object_terms($word_ids, 'word-category', ['fields' => 'all_with_object_id']);
    if (is_wp_error($terms) || empty($terms)) {
        return array_values(array_filter($word_ids, static function ($word_id) use ($category_id): bool {
            return ll_tools_word_grid_word_in_deepest_category((int) $word_id, $category_id);
        }));
    }

    $terms_by_word = [];
    $parent_ids = [];
    foreach ($terms as $term) {
        $word_id = isset($term->object_id) ? (int) $term->object_id : 0;
        $term_id = isset($term->term_id) ? (int) $term->term_id : 0;
        if ($word_id <= 0 || $term_id <= 0) {
            continue;
        }
        $terms_by_word[$word_id][] = $term;
        $parent_ids[$term_id] = isset($term->parent) ? (int) $term->parent : 0;
    }

    $depth_cache = [];
    $get_depth = static function (int $term_id) use (&$get_depth, &$depth_cache, &$parent_ids): int {
        if ($term_id <= 0) {
            return 0;
        }
        if (isset($depth_cache[$term_id])) {
            return $depth_cache[$term_id];
        }

        if (!array_key_exists($term_id, $parent_ids)) {
            $parent_ids[$term_id] = (int) get_term_field('parent', $term_id, 'word-category');
        }

        $parent_id = (int) $parent_ids[$term_id];
        $depth = ($parent_id > 0) ? ($get_depth($parent_id) + 1) : 0;
        $depth_cache[$term_id] = $depth;
        return $depth;
    };

    $filtered_ids = [];
    foreach ($word_ids as $word_id) {
        $word_terms = $terms_by_word[$word_id] ?? [];
        if (empty($word_terms)) {
            if (ll_tools_word_grid_word_in_deepest_category((int) $word_id, $category_id)) {
                $filtered_ids[] = (int) $word_id;
            }
            continue;
        }

        $max_depth = -1;
        $deepest_ids = [];
        foreach ($word_terms as $term) {
            $term_id = isset($term->term_id) ? (int) $term->term_id : 0;
            if ($term_id <= 0) {
                continue;
            }
            $depth = $get_depth($term_id);
            if ($depth > $max_depth) {
                $max_depth = $depth;
                $deepest_ids = [$term_id];
            } elseif ($depth === $max_depth) {
                $deepest_ids[] = $term_id;
            }
        }

        if (in_array($category_id, $deepest_ids, true)) {
            $filtered_ids[] = (int) $word_id;
        }
    }

    return $filtered_ids;
}

function ll_tools_word_grid_filter_posts_to_deepest_category(array $posts, int $category_id): array {
    if (empty($posts) || $category_id <= 0) {
        return $posts;
    }

    $ordered_ids = [];
    foreach ($posts as $post_obj) {
        $post_id = isset($post_obj->ID) ? (int) $post_obj->ID : 0;
        if ($post_id <= 0) {
            continue;
        }
        $ordered_ids[] = $post_id;
    }

    if (empty($ordered_ids)) {
        return [];
    }

    $visible_ids = ll_tools_word_grid_filter_word_ids_to_deepest_category($ordered_ids, $category_id);
    if (empty($visible_ids)) {
        return [];
    }

    $visible_lookup = array_fill_keys($visible_ids, true);
    return array_values(array_filter($posts, static function ($post_obj) use ($visible_lookup): bool {
        $post_id = isset($post_obj->ID) ? (int) $post_obj->ID : 0;
        return $post_id > 0 && isset($visible_lookup[$post_id]);
    }));
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
    $text = str_replace(
        ["\u{1D2E}", "\u{0131}"],
        ["\u{10784}", "\u{026A}"],
        $text
    );

    return $text;
}

function ll_tools_word_grid_normalize_non_ipa_text(string $text): string {
    if ($text === '') {
        return '';
    }

    $text = wp_strip_all_tags($text);
    $text = str_replace(["\r", "\n", "\t", "\u{00A0}"], ' ', $text);
    $text = preg_replace('/\s+/u', ' ', $text);

    return trim((string) $text);
}

function ll_tools_word_grid_normalize_ipa_output(string $text, string $mode = 'ipa'): string {
    if ($mode !== 'ipa') {
        return ll_tools_word_grid_normalize_non_ipa_text($text);
    }

    $text = ll_tools_word_grid_normalize_ipa_text($text);
    return trim($text);
}

function ll_tools_word_grid_normalize_ipa_input(string $text, string $mode = 'ipa'): string {
    if ($mode !== 'ipa') {
        return ll_tools_word_grid_normalize_non_ipa_text($text);
    }

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

function ll_tools_word_grid_sanitize_non_ipa_text(string $text): string {
    $text = trim($text);
    if ($text === '') {
        return '';
    }

    $text = ll_tools_word_grid_normalize_non_ipa_text($text);
    if ($text === '') {
        return '';
    }

    $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
    if (!$chars) {
        return '';
    }

    $out = '';
    foreach ($chars as $char) {
        if (preg_match('/[\p{L}\p{M}\p{N}\x{02B0}-\x{02FF}\.\-\'’\s]/u', $char)) {
            $out .= $char;
        }
    }

    $out = preg_replace('/\s+/u', ' ', $out);
    return trim((string) $out);
}

function ll_tools_word_grid_sanitize_ipa(string $text, string $mode = 'ipa'): string {
    if ($mode !== 'ipa') {
        return ll_tools_word_grid_sanitize_non_ipa_text($text);
    }

    $text = trim($text);
    if ($text === '') {
        return '';
    }

    $text = ll_tools_word_grid_normalize_ipa_input($text, $mode);

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

function ll_tools_word_grid_is_ipa_separator(string $char, string $mode = 'ipa'): bool {
    if ($mode !== 'ipa') {
        return $char === '.' || $char === '-' || preg_match('/\s/u', $char) === 1;
    }
    return $char === '.' || preg_match('/\s/u', $char);
}

function ll_tools_word_grid_is_ipa_combining_mark(string $char): bool {
    return preg_match('/[\x{0300}-\x{036F}]/u', $char) === 1;
}

function ll_tools_word_grid_is_ipa_post_modifier(string $char, string $mode = 'ipa'): bool {
    if ($mode !== 'ipa') {
        return false;
    }
    return preg_match('/[\x{02B0}-\x{02B8}\x{02D0}\x{02D1}\x{02E0}-\x{02E4}\x{1D2C}-\x{1D6A}\x{1D9B}-\x{1DBF}\x{2070}-\x{209F}\x{10784}]/u', $char) === 1;
}

function ll_tools_word_grid_is_ipa_stress_marker(string $char, string $mode = 'ipa'): bool {
    if ($mode !== 'ipa') {
        return false;
    }
    return $char === "\u{02C8}" || $char === "\u{02CC}";
}

function ll_tools_word_grid_strip_ipa_stress_markers(string $token, string $mode = 'ipa'): string {
    if ($mode !== 'ipa' || $token === '') {
        return $token;
    }
    return str_replace(["\u{02C8}", "\u{02CC}"], '', $token);
}

function ll_tools_word_grid_clean_ipa_letter_map(array $map, string $language = '', string $mode = 'ipa'): array {
    $cleaned = [];
    foreach ($map as $letter => $ipa_counts) {
        if (!is_array($ipa_counts)) {
            continue;
        }
        $letter_key = ll_tools_word_grid_lowercase((string) $letter, $language);
        if ($letter_key === '') {
            continue;
        }
        foreach ($ipa_counts as $ipa => $count) {
            $ipa_key = ll_tools_word_grid_normalize_ipa_output((string) $ipa, $mode);
            $ipa_key = ll_tools_word_grid_strip_ipa_stress_markers($ipa_key, $mode);
            $ipa_key = trim($ipa_key);
            if ($ipa_key === '') {
                continue;
            }
            if (!isset($cleaned[$letter_key])) {
                $cleaned[$letter_key] = [];
            }
            if (!isset($cleaned[$letter_key][$ipa_key])) {
                $cleaned[$letter_key][$ipa_key] = 0;
            }
            $cleaned[$letter_key][$ipa_key] += max(1, (int) $count);
        }
    }
    return $cleaned;
}

function ll_tools_word_grid_clean_ipa_letter_blocklist(array $blocklist, string $language = '', string $mode = 'ipa'): array {
    $cleaned = [];
    foreach ($blocklist as $letter => $symbols) {
        $letter_key = ll_tools_word_grid_lowercase((string) $letter, $language);
        $letter_key = preg_replace('/[^\p{L}]+/u', '', $letter_key);
        if ($letter_key === '') {
            continue;
        }

        $tokens = [];
        if (is_array($symbols)) {
            $tokens = $symbols;
        } elseif (is_string($symbols) && $symbols !== '') {
            $tokens = function_exists('ll_tools_word_grid_tokenize_ipa')
                ? ll_tools_word_grid_tokenize_ipa($symbols, $mode)
                : preg_split('//u', $symbols, -1, PREG_SPLIT_NO_EMPTY);
        }

        if (empty($tokens)) {
            continue;
        }

        $seen = [];
        $clean_tokens = [];
        foreach ($tokens as $token) {
            $token = ll_tools_word_grid_normalize_ipa_output((string) $token, $mode);
            $token = ll_tools_word_grid_strip_ipa_stress_markers($token, $mode);
            $token = trim($token);
            if ($token === '' || isset($seen[$token]) || ll_tools_word_grid_is_ipa_stress_marker($token, $mode)) {
                continue;
            }
            $seen[$token] = true;
            $clean_tokens[] = $token;
        }
        if (empty($clean_tokens)) {
            continue;
        }
        $cleaned[$letter_key] = $clean_tokens;
    }
    return $cleaned;
}

function ll_tools_word_grid_filter_ipa_letter_map_with_blocklist(array $auto_map, array $blocklist, string $mode = 'ipa'): array {
    $cleaned = ll_tools_word_grid_clean_ipa_letter_map($auto_map, '', $mode);
    if (empty($blocklist)) {
        return $cleaned;
    }
    $blocked = ll_tools_word_grid_clean_ipa_letter_blocklist($blocklist, '', $mode);
    if (empty($blocked)) {
        return $cleaned;
    }
    foreach ($blocked as $letter => $symbols) {
        if (!isset($cleaned[$letter]) || !is_array($symbols)) {
            continue;
        }
        foreach ($symbols as $symbol) {
            unset($cleaned[$letter][$symbol]);
        }
        if (empty($cleaned[$letter])) {
            unset($cleaned[$letter]);
        }
    }
    return $cleaned;
}

function ll_tools_word_grid_format_ipa_display_html(string $ipa, string $mode = 'ipa'): string {
    $ipa = ll_tools_word_grid_normalize_ipa_output($ipa, $mode);
    if ($ipa === '') {
        return '';
    }

    return function_exists('ll_tools_esc_html_display')
        ? ll_tools_esc_html_display($ipa)
        : esc_html($ipa);
}

function ll_tools_word_grid_is_ipa_tie_bar(string $char, string $mode = 'ipa'): bool {
    if ($mode !== 'ipa') {
        return false;
    }
    return preg_match('/[\x{035C}\x{0361}]/u', $char) === 1;
}

function ll_tools_word_grid_tokenize_ipa(string $text, string $mode = 'ipa'): array {
    $text = ll_tools_word_grid_sanitize_ipa($text, $mode);
    if ($text === '') {
        return [];
    }

    $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
    if (!$chars) {
        return [];
    }

    if ($mode !== 'ipa') {
        $tokens = [];
        $buffer = '';

        foreach ($chars as $char) {
            if (ll_tools_word_grid_is_ipa_separator($char, $mode)) {
                if ($buffer !== '') {
                    $tokens[] = $buffer;
                    $buffer = '';
                }
                continue;
            }

            if (ll_tools_word_grid_is_ipa_combining_mark($char)) {
                if ($buffer === '') {
                    $buffer = $char;
                } else {
                    $buffer .= $char;
                }
                continue;
            }

            if ($buffer !== '') {
                $tokens[] = $buffer;
            }
            $buffer = $char;
        }

        if ($buffer !== '') {
            $tokens[] = $buffer;
        }

        return $tokens;
    }

    $tokens = [];
    $buffer = '';
    $pending = '';
    $tie_pending = false;

    foreach ($chars as $char) {
        if (ll_tools_word_grid_is_ipa_separator($char, $mode)) {
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
            if (ll_tools_word_grid_is_ipa_tie_bar($char, $mode) && $buffer !== '') {
                $tie_pending = true;
            }
            continue;
        }

        if (ll_tools_word_grid_is_ipa_post_modifier($char, $mode)) {
            if ($buffer !== '') {
                $buffer .= $char;
                continue;
            }
            if ($pending !== '') {
                $buffer = $pending . $char;
                $pending = '';
                $tie_pending = false;
                continue;
            }
            $buffer = $char;
            $tie_pending = false;
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

function ll_tools_word_grid_is_special_ipa_token(string $token, string $mode = 'ipa'): bool {
    $token = trim($token);
    if ($token === '') {
        return false;
    }
    if (ll_tools_word_grid_is_ipa_separator($token, $mode)) {
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

function ll_tools_word_grid_extract_ipa_special_chars(string $text, string $mode = 'ipa'): array {
    $tokens = ll_tools_word_grid_tokenize_ipa($text, $mode);
    if (empty($tokens)) {
        return [];
    }

    $special = [];
    foreach ($tokens as $token) {
        if (!ll_tools_word_grid_is_special_ipa_token($token, $mode)) {
            continue;
        }
        $special[$token] = true;
    }

    return array_keys($special);
}

function ll_tools_word_grid_parse_ipa_symbol_meta($raw, string $mode = 'ipa'): array {
    $tokens = [];
    if (is_string($raw)) {
        $tokens = ll_tools_word_grid_tokenize_ipa($raw, $mode);
    } elseif (is_array($raw)) {
        foreach ($raw as $entry) {
            if (!is_string($entry) || $entry === '') {
                continue;
            }
            $entry_tokens = ll_tools_word_grid_tokenize_ipa($entry, $mode);
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
        if (!ll_tools_word_grid_is_special_ipa_token($token, $mode)) {
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
    $mode = function_exists('ll_tools_get_wordset_recording_transcription_mode')
        ? ll_tools_get_wordset_recording_transcription_mode([$wordset_id], true)
        : 'ipa';
    return ll_tools_word_grid_parse_ipa_symbol_meta($raw, $mode);
}

function ll_tools_word_grid_get_wordset_ipa_manual_symbols(int $wordset_id): array {
    if ($wordset_id <= 0) {
        return [];
    }

    $raw = get_term_meta($wordset_id, 'll_wordset_ipa_manual_symbols', true);
    $mode = function_exists('ll_tools_get_wordset_recording_transcription_mode')
        ? ll_tools_get_wordset_recording_transcription_mode([$wordset_id], true)
        : 'ipa';
    return ll_tools_word_grid_parse_ipa_symbol_meta($raw, $mode);
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

    $symbols = array_values(array_unique(array_merge($auto, $manual)));
    if (function_exists('ll_tools_sort_secondary_text_symbols')) {
        $mode = function_exists('ll_tools_get_wordset_recording_transcription_mode')
            ? ll_tools_get_wordset_recording_transcription_mode([$wordset_id], true)
            : 'ipa';
        return ll_tools_sort_secondary_text_symbols($symbols, $mode);
    }

    return $symbols;
}

function ll_tools_word_grid_recording_ipa_is_reviewed(int $recording_id): bool {
    $recording_id = (int) $recording_id;
    if ($recording_id <= 0) {
        return false;
    }

    if (function_exists('ll_tools_ipa_keyboard_recording_field_needs_review')) {
        return !ll_tools_ipa_keyboard_recording_field_needs_review($recording_id, 'recording_ipa');
    }

    $raw_fields = get_post_meta($recording_id, 'll_auto_transcription_review_fields', true);
    if (is_array($raw_fields)) {
        if (!empty($raw_fields['recording_ipa']) || in_array('recording_ipa', $raw_fields, true)) {
            return false;
        }
        return true;
    }

    return !((bool) get_post_meta($recording_id, 'll_auto_transcription_needs_review', true));
}

function ll_tools_word_grid_get_wordset_reviewed_ipa_symbol_inventory(int $wordset_id): array {
    $wordset_id = (int) $wordset_id;
    if ($wordset_id <= 0) {
        return [
            'symbols' => [],
            'recording_counts' => [],
            'occurrence_counts' => [],
        ];
    }

    $mode = function_exists('ll_tools_get_wordset_recording_transcription_mode')
        ? ll_tools_get_wordset_recording_transcription_mode([$wordset_id], true)
        : 'ipa';

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
        return [
            'symbols' => [],
            'recording_counts' => [],
            'occurrence_counts' => [],
        ];
    }

    $recording_ids = get_posts([
        'post_type' => 'word_audio',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'post_parent__in' => $word_ids,
        'no_found_rows' => true,
    ]);

    $recording_counts = [];
    $occurrence_counts = [];
    foreach ((array) $recording_ids as $recording_id) {
        $recording_id = (int) $recording_id;
        if ($recording_id <= 0 || !ll_tools_word_grid_recording_ipa_is_reviewed($recording_id)) {
            continue;
        }

        $recording_ipa = trim((string) get_post_meta($recording_id, 'recording_ipa', true));
        if ($recording_ipa === '') {
            continue;
        }

        $tokens = function_exists('ll_tools_word_grid_tokenize_ipa')
            ? ll_tools_word_grid_tokenize_ipa($recording_ipa, $mode)
            : preg_split('//u', $recording_ipa, -1, PREG_SPLIT_NO_EMPTY);
        if (empty($tokens)) {
            continue;
        }

        $seen_in_recording = [];
        foreach ($tokens as $token) {
            $token = ll_tools_word_grid_normalize_ipa_output((string) $token, $mode);
            $token = ll_tools_word_grid_strip_ipa_stress_markers($token, $mode);
            $token = trim($token);
            if ($token === '' || !ll_tools_word_grid_is_special_ipa_token($token, $mode)) {
                continue;
            }

            if (!isset($occurrence_counts[$token])) {
                $occurrence_counts[$token] = 0;
            }
            $occurrence_counts[$token]++;

            if (isset($seen_in_recording[$token])) {
                continue;
            }
            $seen_in_recording[$token] = true;
            if (!isset($recording_counts[$token])) {
                $recording_counts[$token] = 0;
            }
            $recording_counts[$token]++;
        }
    }

    $symbols = array_keys($recording_counts);
    if (function_exists('ll_tools_sort_secondary_text_symbols')) {
        $symbols = ll_tools_sort_secondary_text_symbols($symbols, $mode);
    }

    return [
        'symbols' => $symbols,
        'recording_counts' => $recording_counts,
        'occurrence_counts' => $occurrence_counts,
    ];
}

function ll_tools_word_grid_rebuild_wordset_ipa_special_chars(int $wordset_id): array {
    $wordset_id = (int) $wordset_id;
    if ($wordset_id <= 0) {
        return [];
    }

    $mode = function_exists('ll_tools_get_wordset_recording_transcription_mode')
        ? ll_tools_get_wordset_recording_transcription_mode([$wordset_id], true)
        : 'ipa';

    $inventory = ll_tools_word_grid_get_wordset_reviewed_ipa_symbol_inventory($wordset_id);
    $symbols = array_values((array) ($inventory['symbols'] ?? []));
    if (function_exists('ll_tools_sort_secondary_text_symbols')) {
        $symbols = ll_tools_sort_secondary_text_symbols($symbols, $mode);
    }
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

if (!defined('LL_TOOLS_WORD_GRID_IPA_LETTER_MAP_CASE_VERSION')) {
    define('LL_TOOLS_WORD_GRID_IPA_LETTER_MAP_CASE_VERSION', 2);
}

function ll_tools_word_grid_get_wordset_ipa_language(int $wordset_id): string {
    $wordset_id = (int) $wordset_id;
    if ($wordset_id <= 0) {
        return '';
    }

    if (function_exists('ll_tools_get_wordset_target_language')) {
        return (string) ll_tools_get_wordset_target_language([$wordset_id]);
    }

    return (string) get_option('ll_target_language', '');
}

function ll_tools_word_grid_lowercase(string $value, string $language = ''): string {
    if (function_exists('ll_tools_lowercase_for_language')) {
        return ll_tools_lowercase_for_language($value, $language);
    }

    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function ll_tools_word_grid_prepare_text_letters(string $text, string $language = ''): array {
    $text = trim($text);
    if ($text === '') {
        return [];
    }

    $text = ll_tools_word_grid_lowercase($text, $language);
    $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
    if (!$chars) {
        return [];
    }

    $letters = [];
    foreach ($chars as $char) {
        if (!preg_match('/\p{L}/u', $char)) {
            continue;
        }
        $letters[] = $char;
    }

    return $letters;
}

function ll_tools_word_grid_get_ipa_match_map(): array {
    static $map = null;
    if ($map !== null) {
        return $map;
    }

    $map = [
        "\u{027E}" => 'r',
        "\u{0279}" => 'r',
        "\u{027B}" => 'r',
        "\u{0280}" => 'r',
        "\u{0281}" => 'r',
        "\u{027D}" => 'r',
        "\u{029C}" => 'h',
        "\u{0266}" => 'h',
        "\u{0283}" => 'sh',
        "\u{0292}" => 'zh',
        "\u{03B8}" => 'th',
        "\u{00F0}" => 'th',
        "\u{014B}" => 'ng',
        "\u{0272}" => 'ny',
        "\u{0250}" => 'a',
        "\u{0251}" => 'a',
        "\u{0252}" => 'o',
        "\u{00E6}" => 'a',
        "\u{025B}" => 'e',
        "\u{025C}" => 'e',
        "\u{0259}" => 'e',
        "\u{026A}" => 'i',
        "\u{028A}" => 'u',
        "\u{028C}" => 'u',
        "\u{0254}" => 'o',
        "\u{026F}" => 'u',
        "\u{0268}" => 'i',
        "\u{0289}" => 'u',
        "\u{00F8}" => 'o',
        "\u{0153}" => 'oe',
        "\u{0276}" => 'oe',
        "\u{0261}" => 'g',
        "\u{0263}" => 'g',
        "\u{028B}" => 'v',
    ];

    return $map;
}

function ll_tools_word_grid_get_transcription_match_map(): array {
    static $map = null;
    if ($map !== null) {
        return $map;
    }

    $map = [
        'ā' => 'a',
        'ă' => 'a',
        'â' => 'a',
        'á' => 'a',
        'à' => 'a',
        'ä' => 'a',
        'ã' => 'a',
        'ē' => 'e',
        'ĕ' => 'e',
        'ê' => 'e',
        'é' => 'e',
        'è' => 'e',
        'ë' => 'e',
        'ī' => 'i',
        'ĭ' => 'i',
        'î' => 'i',
        'í' => 'i',
        'ì' => 'i',
        'ï' => 'i',
        'ō' => 'o',
        'ŏ' => 'o',
        'ô' => 'o',
        'ó' => 'o',
        'ò' => 'o',
        'ö' => 'o',
        'õ' => 'o',
        'ū' => 'u',
        'ŭ' => 'u',
        'û' => 'u',
        'ú' => 'u',
        'ù' => 'u',
        'ü' => 'u',
        'ḥ' => 'h',
        'ḫ' => 'h',
        'ṭ' => 't',
        'ṣ' => 's',
        'š' => 'sh',
        'ś' => 's',
        'ḏ' => 'd',
        'ḇ' => 'v',
        'ʾ' => 'a',
        'ʿ' => 'a',
        "'" => 'a',
        '’' => 'a',
        'ʻ' => 'a',
        'א' => 'a',
        'ע' => 'a',
        'ב' => 'b',
        'ג' => 'g',
        'ד' => 'd',
        'ה' => 'h',
        'ו' => 'w',
        'ז' => 'z',
        'ח' => 'h',
        'ט' => 't',
        'י' => 'y',
        'כ' => 'k',
        'ך' => 'k',
        'ל' => 'l',
        'מ' => 'm',
        'ם' => 'm',
        'נ' => 'n',
        'ן' => 'n',
        'ס' => 's',
        'פ' => 'p',
        'ף' => 'p',
        'צ' => 's',
        'ץ' => 's',
        'ק' => 'q',
        'ר' => 'r',
        'ש' => 'sh',
        'ת' => 't',
    ];

    return $map;
}

function ll_tools_word_grid_apply_transcription_match_map(string $segment): string {
    if ($segment === '') {
        return '';
    }

    $map = ll_tools_word_grid_get_transcription_match_map();
    $chars = preg_split('//u', $segment, -1, PREG_SPLIT_NO_EMPTY);
    if (!$chars) {
        return $segment;
    }

    $out = '';
    foreach ($chars as $char) {
        $out .= $map[$char] ?? $char;
    }

    return $out;
}

function ll_tools_word_grid_normalize_text_segment_for_match(string $segment): string {
    $segment = trim($segment);
    if ($segment === '') {
        return '';
    }

    $segment = ll_tools_word_grid_lowercase($segment);
    $segment = preg_replace('/[^\p{L}]+/u', '', $segment);
    if ($segment === '') {
        return '';
    }
    $segment = strtr($segment, [
        'ı' => 'i',
    ]);

    if (function_exists('transliterator_transliterate')) {
        $converted = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $segment);
        if (is_string($converted) && $converted !== '') {
            $segment = $converted;
        }
    } elseif (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT', $segment);
        if (is_string($converted) && $converted !== '') {
            $segment = $converted;
        }
    }

    $segment = ll_tools_word_grid_apply_transcription_match_map($segment);
    $segment = preg_replace('/[^a-z]+/', '', $segment);
    return (string) $segment;
}

function ll_tools_word_grid_normalize_ipa_segment_for_match(string $segment, string $mode = 'ipa'): string {
    $segment = ll_tools_word_grid_normalize_ipa_output($segment, $mode);
    if ($segment === '') {
        return '';
    }

    $segment = ll_tools_word_grid_lowercase($segment);
    $segment = preg_replace('/[\s\.]+/u', '', $segment);
    $segment = preg_replace('/[\x{0300}-\x{036F}]+/u', '', $segment);

    if ($mode !== 'ipa') {
        $segment = ll_tools_word_grid_apply_transcription_match_map($segment);
        $segment = preg_replace('/[^a-z]+/', '', $segment);
        return (string) $segment;
    }

    $map = ll_tools_word_grid_get_ipa_match_map();
    $chars = preg_split('//u', $segment, -1, PREG_SPLIT_NO_EMPTY);
    if (!$chars) {
        return '';
    }

    $out = '';
    foreach ($chars as $char) {
        if (preg_match('/[a-z]/', $char)) {
            $out .= $char;
            continue;
        }
        if (isset($map[$char])) {
            $out .= $map[$char];
        }
    }

    return $out;
}

function ll_tools_word_grid_normalize_ipa_segment_for_match_with_length(string $segment, string $mode = 'ipa'): string {
    $segment = ll_tools_word_grid_normalize_ipa_output($segment, $mode);
    if ($segment === '') {
        return '';
    }

    $segment = ll_tools_word_grid_lowercase($segment);
    $segment = preg_replace('/[\s\.]+/u', '', $segment);
    $segment = preg_replace('/[\x{0300}-\x{036F}]+/u', '', $segment);

    if ($mode !== 'ipa') {
        $segment = ll_tools_word_grid_apply_transcription_match_map($segment);
        $segment = preg_replace('/[^a-z]+/', '', $segment);
        return (string) $segment;
    }

    $map = ll_tools_word_grid_get_ipa_match_map();
    $chars = preg_split('//u', $segment, -1, PREG_SPLIT_NO_EMPTY);
    if (!$chars) {
        return '';
    }

    $out = '';
    $last = '';
    foreach ($chars as $char) {
        if ($char === "\u{02D0}" || $char === "\u{02D1}") {
            if ($last !== '') {
                $out .= $last;
            }
            continue;
        }
        if (preg_match('/[a-z]/', $char)) {
            $out .= $char;
            $last = $char;
            continue;
        }
        if (isset($map[$char])) {
            $mapped = $map[$char];
            $out .= $mapped;
            $last = $mapped;
        }
    }

    return $out;
}

function ll_tools_word_grid_similarity_score(string $text_segment, string $ipa_segment, string $mode = 'ipa'): float {
    $text_norm = ll_tools_word_grid_normalize_text_segment_for_match($text_segment);
    if ($text_norm === '') {
        return 0.0;
    }
    $ipa_norm = ll_tools_word_grid_normalize_ipa_segment_for_match($ipa_segment, $mode);
    $ipa_norm_expanded = ll_tools_word_grid_normalize_ipa_segment_for_match_with_length($ipa_segment, $mode);
    if ($ipa_norm === '' && $ipa_norm_expanded === '') {
        return 0.0;
    }

    $best = 0.0;
    foreach ([$ipa_norm, $ipa_norm_expanded] as $variant) {
        if ($variant === '') {
            continue;
        }
        if ($text_norm === $variant) {
            return 1.0;
        }
        $distance = function_exists('levenshtein') ? levenshtein($text_norm, $variant) : null;
        if ($distance === null) {
            $score = ($text_norm[0] === $variant[0]) ? 0.5 : 0.0;
        } else {
            $max_len = max(strlen($text_norm), strlen($variant));
            if ($max_len === 0) {
                $score = 0.0;
            } else {
                $score = 1 - ($distance / $max_len);
            }
        }
        if ($score < 0) {
            $score = 0.0;
        } elseif ($score > 1) {
            $score = 1.0;
        }
        if ($score > $best) {
            $best = (float) $score;
        }
        if ($best >= 1.0) {
            break;
        }
    }

    return $best;
}

function ll_tools_word_grid_align_text_to_ipa(array $letters, array $tokens, string $mode = 'ipa'): array {
    $letter_count = count($letters);
    $token_count = count($tokens);
    if ($letter_count === 0 || $token_count === 0) {
        return [
            'matches' => [],
            'matched_letters' => 0,
            'matched_tokens' => 0,
            'avg_score' => 0.0,
            'total_score' => 0.0,
        ];
    }

    $skip_penalty = 0.25;
    $match_threshold = 0.55;
    $multi_penalty = 0.05;

    $token_norms = [];
    for ($idx = 0; $idx < $token_count; $idx++) {
        $token_norms[$idx] = ll_tools_word_grid_normalize_ipa_segment_for_match((string) $tokens[$idx], $mode);
    }
    $combo_norms = [];
    if ($token_count > 1) {
        for ($idx = 0; $idx < ($token_count - 1); $idx++) {
            $combo_norms[$idx] = ll_tools_word_grid_normalize_ipa_segment_for_match(
                (string) $tokens[$idx] . (string) $tokens[$idx + 1],
                $mode
            );
        }
    }

    $dp = array_fill(0, $letter_count + 1, array_fill(0, $token_count + 1, null));
    $dp[0][0] = ['score' => 0, 'prev' => null];

    $update = function (int $i, int $j, float $score, array $prev) use (&$dp) {
        $existing = $dp[$i][$j];
        if ($existing === null || $score > $existing['score']) {
            $dp[$i][$j] = [
                'score' => $score,
                'prev' => $prev,
            ];
        }
    };

    for ($i = 0; $i <= $letter_count; $i++) {
        for ($j = 0; $j <= $token_count; $j++) {
            $cell = $dp[$i][$j];
            if ($cell === null) {
                continue;
            }

            if ($i < $letter_count) {
                $update($i + 1, $j, $cell['score'] - $skip_penalty, [
                    'type' => 'skip_letter',
                    'i' => $i,
                    'j' => $j,
                ]);
            }
            if ($j < $token_count) {
                $update($i, $j + 1, $cell['score'] - $skip_penalty, [
                    'type' => 'skip_token',
                    'i' => $i,
                    'j' => $j,
                ]);
            }

            if ($i < $letter_count && $j < $token_count) {
                $score = ll_tools_word_grid_similarity_score($letters[$i], $tokens[$j], $mode);
                if ($score >= $match_threshold) {
                    $update($i + 1, $j + 1, $cell['score'] + $score, [
                        'type' => 'match',
                        'i' => $i,
                        'j' => $j,
                        'text' => $letters[$i],
                        'ipa' => $tokens[$j],
                        'text_len' => 1,
                        'token_len' => 1,
                        'score' => $score,
                    ]);
                }
            }

            if ($i < $letter_count && ($j + 1) < $token_count) {
                $combo_norm = $combo_norms[$j] ?? '';
                if ($combo_norm !== '') {
                    $norm_a = $token_norms[$j] ?? '';
                    $norm_b = $token_norms[$j + 1] ?? '';
                    if ($combo_norm !== $norm_a && $combo_norm !== $norm_b) {
                        $ipa_segment = $tokens[$j] . $tokens[$j + 1];
                        $score = ll_tools_word_grid_similarity_score($letters[$i], $ipa_segment, $mode);
                        if ($score >= $match_threshold) {
                            $update($i + 1, $j + 2, $cell['score'] + $score - $multi_penalty, [
                                'type' => 'match',
                                'i' => $i,
                                'j' => $j,
                                'text' => $letters[$i],
                                'ipa' => $ipa_segment,
                                'text_len' => 1,
                                'token_len' => 2,
                                'score' => $score,
                            ]);
                        }
                    }
                }
            }

            if (($i + 1) < $letter_count && $j < $token_count) {
                $text_segment = $letters[$i] . $letters[$i + 1];
                $score = ll_tools_word_grid_similarity_score($text_segment, $tokens[$j], $mode);
                if ($score >= $match_threshold) {
                    $update($i + 2, $j + 1, $cell['score'] + $score - $multi_penalty, [
                        'type' => 'match',
                        'i' => $i,
                        'j' => $j,
                        'text' => $text_segment,
                        'ipa' => $tokens[$j],
                        'text_len' => 2,
                        'token_len' => 1,
                        'score' => $score,
                    ]);
                }
            }
        }
    }

    $cell = $dp[$letter_count][$token_count];
    if ($cell === null) {
        return [
            'matches' => [],
            'matched_letters' => 0,
            'matched_tokens' => 0,
            'avg_score' => 0.0,
            'total_score' => 0.0,
        ];
    }

    $matches = [];
    $matched_letters = 0;
    $matched_tokens = 0;
    $total_score = 0.0;
    $i = $letter_count;
    $j = $token_count;
    while ($i > 0 || $j > 0) {
        $cell = $dp[$i][$j];
        if (!$cell || !isset($cell['prev'])) {
            break;
        }
        $prev = $cell['prev'];
        if (($prev['type'] ?? '') === 'match') {
            $matches[] = [
                'text' => (string) ($prev['text'] ?? ''),
                'ipa' => (string) ($prev['ipa'] ?? ''),
                'text_len' => (int) ($prev['text_len'] ?? 1),
                'token_len' => (int) ($prev['token_len'] ?? 1),
                'score' => (float) ($prev['score'] ?? 0),
            ];
            $matched_letters += (int) ($prev['text_len'] ?? 1);
            $matched_tokens += (int) ($prev['token_len'] ?? 1);
            $total_score += (float) ($prev['score'] ?? 0);
        }
        $i = (int) ($prev['i'] ?? 0);
        $j = (int) ($prev['j'] ?? 0);
    }

    $matches = array_reverse($matches);
    $avg_score = $matches ? ($total_score / count($matches)) : 0.0;

    return [
        'matches' => $matches,
        'matched_letters' => $matched_letters,
        'matched_tokens' => $matched_tokens,
        'avg_score' => $avg_score,
        'total_score' => $total_score,
    ];
}

function ll_tools_word_grid_rebuild_wordset_ipa_letter_map(int $wordset_id): array {
    $wordset_id = (int) $wordset_id;
    if ($wordset_id <= 0) {
        return [];
    }

    $wordset_language = ll_tools_word_grid_get_wordset_ipa_language($wordset_id);
    $transcription_mode = function_exists('ll_tools_get_wordset_recording_transcription_mode')
        ? ll_tools_get_wordset_recording_transcription_mode([$wordset_id], true)
        : 'ipa';

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

    $word_ids = array_values(array_filter(array_map('intval', (array) $word_ids), function ($id) { return $id > 0; }));
    if (empty($word_ids)) {
        update_term_meta($wordset_id, 'll_wordset_ipa_letter_map', []);
        update_term_meta($wordset_id, 'll_wordset_ipa_letter_map_case_version', LL_TOOLS_WORD_GRID_IPA_LETTER_MAP_CASE_VERSION);
        return [];
    }

    $recording_ids = get_posts([
        'post_type' => 'word_audio',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'post_parent__in' => $word_ids,
    ]);

    $map = [];
    foreach ((array) $recording_ids as $recording_id) {
        $recording_id = (int) $recording_id;
        if ($recording_id <= 0) {
            continue;
        }
        $recording_text = trim((string) get_post_meta($recording_id, 'recording_text', true));
        $recording_ipa = trim((string) get_post_meta($recording_id, 'recording_ipa', true));
        if ($recording_text === '' || $recording_ipa === '') {
            continue;
        }

        $letters = ll_tools_word_grid_prepare_text_letters($recording_text, $wordset_language);
        $tokens = ll_tools_word_grid_tokenize_ipa($recording_ipa, $transcription_mode);
        if (!empty($tokens)) {
            $tokens = array_values(array_filter($tokens, function ($token) use ($transcription_mode) {
                return !ll_tools_word_grid_is_ipa_stress_marker((string) $token, $transcription_mode);
            }));
        }
        if (empty($letters) || empty($tokens)) {
            continue;
        }

        $alignment = ll_tools_word_grid_align_text_to_ipa($letters, $tokens, $transcription_mode);
        if (empty($alignment['matches'])) {
            continue;
        }
        $letter_coverage = $alignment['matched_letters'] / max(1, count($letters));
        $token_coverage = $alignment['matched_tokens'] / max(1, count($tokens));
        if ($alignment['avg_score'] < 0.55 || $letter_coverage < 0.55 || $token_coverage < 0.45) {
            continue;
        }

        foreach ($alignment['matches'] as $match) {
            $text_key = ll_tools_word_grid_lowercase((string) ($match['text'] ?? ''), $wordset_language);
            $ipa_key = ll_tools_word_grid_normalize_ipa_output((string) ($match['ipa'] ?? ''), $transcription_mode);
            if ($text_key === '' || $ipa_key === '') {
                continue;
            }
            if (!isset($map[$text_key])) {
                $map[$text_key] = [];
            }
            if (!isset($map[$text_key][$ipa_key])) {
                $map[$text_key][$ipa_key] = 0;
            }
            $map[$text_key][$ipa_key] += 1;
        }
    }

    update_term_meta($wordset_id, 'll_wordset_ipa_letter_map', $map);
    update_term_meta($wordset_id, 'll_wordset_ipa_letter_map_case_version', LL_TOOLS_WORD_GRID_IPA_LETTER_MAP_CASE_VERSION);
    return $map;
}

function ll_tools_word_grid_get_wordset_ipa_letter_map(int $wordset_id): array {
    if ($wordset_id <= 0) {
        return [];
    }

    static $cache = [];
    if (isset($cache[$wordset_id])) {
        return $cache[$wordset_id];
    }

    $wordset_language = ll_tools_word_grid_get_wordset_ipa_language($wordset_id);
    $transcription_mode = function_exists('ll_tools_get_wordset_recording_transcription_mode')
        ? ll_tools_get_wordset_recording_transcription_mode([$wordset_id], true)
        : 'ipa';
    $case_version = (int) get_term_meta($wordset_id, 'll_wordset_ipa_letter_map_case_version', true);
    if (function_exists('ll_tools_language_uses_turkish_casing')
        && ll_tools_language_uses_turkish_casing($wordset_language)
        && $case_version < LL_TOOLS_WORD_GRID_IPA_LETTER_MAP_CASE_VERSION) {
        $cache[$wordset_id] = ll_tools_word_grid_rebuild_wordset_ipa_letter_map($wordset_id);
        return $cache[$wordset_id];
    }

    $raw = get_term_meta($wordset_id, 'll_wordset_ipa_letter_map', true);
    if (!is_array($raw)) {
        $cache[$wordset_id] = ll_tools_word_grid_rebuild_wordset_ipa_letter_map($wordset_id);
        return $cache[$wordset_id];
    }
    $cleaned = ll_tools_word_grid_clean_ipa_letter_map($raw, $wordset_language, $transcription_mode);
    if ($cleaned !== $raw) {
        update_term_meta($wordset_id, 'll_wordset_ipa_letter_map', $cleaned);
    }
    $cache[$wordset_id] = $cleaned;
    return $cache[$wordset_id];
}

function ll_tools_word_grid_get_wordset_ipa_letter_manual_map(int $wordset_id): array {
    if ($wordset_id <= 0) {
        return [];
    }

    $wordset_language = ll_tools_word_grid_get_wordset_ipa_language($wordset_id);
    $transcription_mode = function_exists('ll_tools_get_wordset_recording_transcription_mode')
        ? ll_tools_get_wordset_recording_transcription_mode([$wordset_id], true)
        : 'ipa';
    $raw = get_term_meta($wordset_id, 'll_wordset_ipa_letter_manual_map', true);
    if (!is_array($raw)) {
        return [];
    }

    $cleaned = [];
    foreach ($raw as $letter => $symbols) {
        $letter_key = ll_tools_word_grid_lowercase((string) $letter, $wordset_language);
        $letter_key = preg_replace('/[^\p{L}]+/u', '', $letter_key);
        if ($letter_key === '') {
            continue;
        }

        $tokens = [];
        if (is_array($symbols)) {
            $tokens = $symbols;
        } elseif (is_string($symbols) && $symbols !== '') {
            $tokens = function_exists('ll_tools_word_grid_tokenize_ipa')
                ? ll_tools_word_grid_tokenize_ipa($symbols, $transcription_mode)
                : preg_split('//u', $symbols, -1, PREG_SPLIT_NO_EMPTY);
        }

        if (empty($tokens)) {
            continue;
        }

        $seen = [];
        $clean_tokens = [];
        foreach ($tokens as $token) {
            $token = ll_tools_word_grid_normalize_ipa_output((string) $token, $transcription_mode);
            $token = ll_tools_word_grid_strip_ipa_stress_markers($token, $transcription_mode);
            if ($token === '' || isset($seen[$token]) || ll_tools_word_grid_is_ipa_stress_marker($token, $transcription_mode)) {
                continue;
            }
            $seen[$token] = true;
            $clean_tokens[] = $token;
        }
        if (empty($clean_tokens)) {
            continue;
        }
        $cleaned[$letter_key] = $clean_tokens;
    }

    return $cleaned;
}

function ll_tools_word_grid_get_wordset_ipa_letter_blocklist(int $wordset_id): array {
    if ($wordset_id <= 0) {
        return [];
    }

    $wordset_language = ll_tools_word_grid_get_wordset_ipa_language($wordset_id);
    $transcription_mode = function_exists('ll_tools_get_wordset_recording_transcription_mode')
        ? ll_tools_get_wordset_recording_transcription_mode([$wordset_id], true)
        : 'ipa';
    $raw = get_term_meta($wordset_id, 'll_wordset_ipa_letter_blocklist', true);
    if (!is_array($raw)) {
        return [];
    }

    $cleaned = ll_tools_word_grid_clean_ipa_letter_blocklist($raw, $wordset_language, $transcription_mode);
    if ($cleaned !== $raw) {
        update_term_meta($wordset_id, 'll_wordset_ipa_letter_blocklist', $cleaned);
    }

    return $cleaned;
}

function ll_tools_word_grid_get_wordset_ipa_letter_maps(int $wordset_id): array {
    return [
        'auto' => ll_tools_word_grid_get_wordset_ipa_letter_map($wordset_id),
        'manual' => ll_tools_word_grid_get_wordset_ipa_letter_manual_map($wordset_id),
    ];
}

function ll_tools_word_grid_update_wordset_ipa_letter_map(int $word_id): void {
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
        ll_tools_word_grid_rebuild_wordset_ipa_letter_map($wordset_id);
    }
}

if (!defined('LL_TOOLS_WORD_GRID_IPA_REBUILD_HOOK')) {
    define('LL_TOOLS_WORD_GRID_IPA_REBUILD_HOOK', 'll_tools_word_grid_rebuild_wordset_ipa_maps');
}

function ll_tools_word_grid_schedule_wordset_ipa_rebuild(int $word_id, int $delay_seconds = 8): void {
    $word_id = (int) $word_id;
    if ($word_id <= 0) {
        return;
    }

    $wordset_ids = wp_get_post_terms($word_id, 'wordset', ['fields' => 'ids']);
    if (is_wp_error($wordset_ids) || empty($wordset_ids)) {
        return;
    }

    $delay_seconds = (int) apply_filters('ll_tools_word_grid_ipa_rebuild_delay_seconds', $delay_seconds, $word_id);
    $delay_seconds = max(1, (int) $delay_seconds);
    foreach ($wordset_ids as $wordset_id) {
        $wordset_id = (int) $wordset_id;
        if ($wordset_id <= 0) {
            continue;
        }

        $args = [$wordset_id];
        if (!wp_next_scheduled(LL_TOOLS_WORD_GRID_IPA_REBUILD_HOOK, $args)) {
            $scheduled = wp_schedule_single_event(time() + $delay_seconds, LL_TOOLS_WORD_GRID_IPA_REBUILD_HOOK, $args);
            if ($scheduled === false) {
                ll_tools_word_grid_run_wordset_ipa_rebuild($wordset_id);
            }
        }
    }
}

add_action(LL_TOOLS_WORD_GRID_IPA_REBUILD_HOOK, 'll_tools_word_grid_run_wordset_ipa_rebuild', 10, 1);
function ll_tools_word_grid_run_wordset_ipa_rebuild($wordset_id): void {
    $wordset_id = (int) $wordset_id;
    if ($wordset_id <= 0) {
        return;
    }

    ll_tools_word_grid_rebuild_wordset_ipa_special_chars($wordset_id);
    ll_tools_word_grid_rebuild_wordset_ipa_letter_map($wordset_id);
}

function ll_tools_word_grid_prepare_ipa_letter_suggestions(array $auto_map, array $manual_map = [], int $limit = 6, array $blocklist = [], string $mode = 'ipa'): array {
    $limit = max(1, $limit);
    $output = [];

    if (!empty($blocklist)) {
        $auto_map = ll_tools_word_grid_filter_ipa_letter_map_with_blocklist($auto_map, $blocklist, $mode);
    }

    foreach ($manual_map as $letter => $symbols) {
        if (!is_array($symbols)) {
            continue;
        }
        $letter_key = ll_tools_word_grid_lowercase((string) $letter);
        $letter_key = preg_replace('/[^\p{L}]+/u', '', $letter_key);
        if ($letter_key === '') {
            continue;
        }
        $clean = [];
        $seen = [];
        foreach ($symbols as $symbol) {
            $symbol = ll_tools_word_grid_normalize_ipa_output((string) $symbol, $mode);
            $symbol = ll_tools_word_grid_strip_ipa_stress_markers($symbol, $mode);
            if ($symbol === '' || isset($seen[$symbol]) || ll_tools_word_grid_is_ipa_stress_marker($symbol, $mode)) {
                continue;
            }
            $seen[$symbol] = true;
            $clean[] = $symbol;
        }
        if (empty($clean)) {
            continue;
        }
        $output[$letter_key] = array_slice($clean, 0, $limit);
    }

    foreach ($auto_map as $letter => $ipa_counts) {
        if (!is_array($ipa_counts)) {
            continue;
        }
        $letter_key = ll_tools_word_grid_lowercase((string) $letter);
        if ($letter_key === '') {
            continue;
        }
        if (isset($output[$letter_key])) {
            continue;
        }
        $clean_counts = [];
        foreach ($ipa_counts as $ipa => $count) {
            $ipa_key = ll_tools_word_grid_normalize_ipa_output((string) $ipa, $mode);
            $ipa_key = ll_tools_word_grid_strip_ipa_stress_markers($ipa_key, $mode);
            if ($ipa_key === '' || ll_tools_word_grid_is_ipa_stress_marker($ipa_key, $mode)) {
                continue;
            }
            $clean_counts[$ipa_key] = max(1, (int) $count);
        }
        if (empty($clean_counts)) {
            continue;
        }
        arsort($clean_counts);
        $output[$letter_key] = array_slice(array_keys($clean_counts), 0, $limit);
    }

    return $output;
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

function ll_tools_word_grid_render_inline_title_editor(array $args): string {
    $field = (string) ($args['field'] ?? '');
    if ($field !== 'translation') {
        $field = 'word';
    }

    $value = (string) ($args['value'] ?? '');
    $editor_id = (string) ($args['editor_id'] ?? ('ll-word-inline-editor-' . $field));
    $input_id = (string) ($args['input_id'] ?? ($editor_id . '-input'));
    $field_label = (string) ($args['field_label'] ?? '');
    $trigger_label = (string) ($args['trigger_label'] ?? '');
    $placeholder = (string) ($args['placeholder'] ?? '');
    $display_attr = ($field === 'translation') ? 'data-ll-word-translation' : 'data-ll-word-text';
    $display_class = ($field === 'translation') ? 'll-word-translation' : 'll-word-text';
    $is_empty = trim($value) === '';
    $display_html = function_exists('ll_tools_esc_html_display')
        ? ll_tools_esc_html_display($value)
        : esc_html($value);

    ob_start();
    ?>
    <span
        class="ll-word-inline-edit ll-word-inline-edit--<?php echo esc_attr($field); ?>"
        data-ll-inline-word-editor="<?php echo esc_attr($field); ?>"
        id="<?php echo esc_attr($editor_id); ?>">
        <button
            type="button"
            class="ll-word-inline-edit-trigger"
            data-ll-inline-word-trigger="<?php echo esc_attr($field); ?>"
            aria-expanded="false"
            aria-controls="<?php echo esc_attr($editor_id . '-controls'); ?>"
            aria-label="<?php echo esc_attr($trigger_label); ?>"
            title="<?php echo esc_attr($trigger_label); ?>">
            <span
                class="<?php echo esc_attr($display_class); ?>"
                <?php echo $display_attr; ?>
                dir="auto"
                <?php if ($is_empty) : ?>hidden<?php endif; ?>><?php echo $display_html; ?></span>
            <span
                class="ll-word-inline-edit-placeholder"
                data-ll-inline-word-placeholder
                <?php if (!$is_empty) : ?>hidden<?php endif; ?>><?php echo esc_html($placeholder); ?></span>
            <span class="ll-word-inline-edit-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                    <path d="M4 20.5h4l10-10-4-4-10 10v4z" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M13.5 6.5l4 4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </span>
        </button>
        <span
            class="ll-word-inline-edit-controls"
            id="<?php echo esc_attr($editor_id . '-controls'); ?>"
            data-ll-inline-word-form
            hidden>
            <input
                type="text"
                class="ll-word-inline-edit-input"
                id="<?php echo esc_attr($input_id); ?>"
                data-ll-inline-word-input="<?php echo esc_attr($field); ?>"
                value="<?php echo esc_attr($value); ?>"
                placeholder="<?php echo esc_attr($placeholder); ?>"
                aria-label="<?php echo esc_attr($field_label); ?>"
                dir="auto"
                autocomplete="off" />
            <span class="ll-word-inline-edit-actions">
                <button
                    type="button"
                    class="ll-word-inline-edit-action ll-word-inline-edit-action--save"
                    data-ll-inline-word-save
                    aria-label="<?php echo esc_attr__('Save', 'll-tools-text-domain'); ?>"
                    title="<?php echo esc_attr__('Save', 'll-tools-text-domain'); ?>">
                    <svg viewBox="0 0 16 16" focusable="false" aria-hidden="true">
                        <path d="m3 8 3 3 7-7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>
                <button
                    type="button"
                    class="ll-word-inline-edit-action ll-word-inline-edit-action--cancel"
                    data-ll-inline-word-cancel
                    aria-label="<?php echo esc_attr__('Cancel editing', 'll-tools-text-domain'); ?>"
                    title="<?php echo esc_attr__('Cancel editing', 'll-tools-text-domain'); ?>">
                    <svg viewBox="0 0 16 16" focusable="false" aria-hidden="true">
                        <path d="M4 4l8 8M12 4 4 12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                </button>
            </span>
        </span>
    </span>
    <?php

    return (string) ob_get_clean();
}

function ll_tools_word_grid_resolve_display_text(int $word_id, ?bool $store_in_title_override = null): array {
    $word_post = get_post($word_id);
    if (!$word_post || $word_post->post_type !== 'words') {
        return [
            'word_text' => '',
            'translation_text' => '',
            'store_in_title' => true,
        ];
    }

    $store_in_title = $store_in_title_override !== null
        ? $store_in_title_override
        : (function_exists('ll_tools_should_store_word_in_title')
            ? ll_tools_should_store_word_in_title($word_id)
            : true);
    $word_title = get_the_title($word_id);
    $word_translation = get_post_meta($word_id, 'word_translation', true);
    $legacy_translation = get_post_meta($word_id, 'word_english_meaning', true);
    if ($store_in_title && $word_translation === '') {
        $word_translation = $legacy_translation;
    }

    if ($store_in_title) {
        $word_text = $word_title;
        $translation_text = $word_translation;
    } else {
        $word_text = $word_translation;
        $translation_text = trim((string) $legacy_translation) !== '' ? $legacy_translation : $word_title;
    }

    if (function_exists('ll_tools_decode_display_entities')) {
        $word_text = ll_tools_decode_display_entities($word_text);
        $translation_text = ll_tools_decode_display_entities($translation_text);
    }

    return [
        'word_text' => (string) $word_text,
        'translation_text' => (string) $translation_text,
        'store_in_title' => (bool) $store_in_title,
    ];
}

function ll_tools_word_grid_collect_part_of_speech_terms(array $word_ids): array {
    $word_ids = array_values(array_filter(array_map('intval', $word_ids), function ($id) { return $id > 0; }));
    if (empty($word_ids)) {
        return [];
    }

    $terms = wp_get_object_terms($word_ids, 'part_of_speech', [
        'fields' => 'all_with_object_id',
        'orderby' => 'name',
        'order' => 'ASC',
    ]);
    if (is_wp_error($terms) || empty($terms)) {
        return [];
    }

    $map = [];
    foreach ($terms as $term) {
        $word_id = isset($term->object_id) ? (int) $term->object_id : 0;
        if ($word_id <= 0) {
            continue;
        }
        if (isset($map[$word_id])) {
            continue;
        }
        $map[$word_id] = [
            'slug' => (string) $term->slug,
            'label' => (string) $term->name,
            'term_id' => (int) $term->term_id,
        ];
    }

    return $map;
}

function ll_tools_word_grid_get_wordset_id_for_word(int $word_id): int {
    if ($word_id <= 0) {
        return 0;
    }
    $term_ids = wp_get_post_terms($word_id, 'wordset', ['fields' => 'ids']);
    if (is_wp_error($term_ids) || empty($term_ids)) {
        return 0;
    }
    return (int) $term_ids[0];
}

function ll_tools_word_grid_get_wordset_ids_for_word(int $word_id): array {
    if ($word_id <= 0) {
        return [];
    }

    $term_ids = wp_get_post_terms($word_id, 'wordset', ['fields' => 'ids']);
    if (is_wp_error($term_ids) || empty($term_ids)) {
        return [];
    }

    return array_values(array_unique(array_filter(array_map('intval', (array) $term_ids), static function (int $term_id): bool {
        return $term_id > 0;
    })));
}

function ll_tools_word_grid_reorder_by_option_groups(array $posts, array $groups): array {
    if (empty($posts) || empty($groups)) {
        return $posts;
    }

    $sorted_groups = $groups;
    usort($sorted_groups, function ($left, $right) {
        $left_label = isset($left['label']) ? trim((string) $left['label']) : '';
        $right_label = isset($right['label']) ? trim((string) $right['label']) : '';
        if (function_exists('ll_tools_locale_compare_strings')) {
            return ll_tools_locale_compare_strings($left_label, $right_label);
        }
        return strnatcasecmp($left_label, $right_label);
    });

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
    foreach ($sorted_groups as $group) {
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

function ll_tools_word_grid_normalize_name_key(string $value): string {
    $value = wp_strip_all_tags($value);
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $collapsed = preg_replace('/\s+/u', ' ', $value);
    if (is_string($collapsed) && $collapsed !== '') {
        $value = $collapsed;
    }

    if (function_exists('mb_strtolower')) {
        return mb_strtolower($value, 'UTF-8');
    }

    return strtolower($value);
}

function ll_tools_word_grid_group_same_name_or_image(array $posts, array &$display_values_cache = []): array {
    if (empty($posts)) {
        return $posts;
    }

    $post_map = [];
    $index_map = [];
    $name_map = [];
    $image_map = [];
    $links = [];

    foreach ($posts as $index => $post) {
        $post_id = isset($post->ID) ? (int) $post->ID : 0;
        if ($post_id <= 0) {
            continue;
        }

        $post_map[$post_id] = $post;
        $index_map[$post_id] = (int) $index;
        if (!isset($links[$post_id])) {
            $links[$post_id] = [];
        }

        if (!isset($display_values_cache[$post_id]) || !is_array($display_values_cache[$post_id])) {
            $display_values_cache[$post_id] = ll_tools_word_grid_resolve_display_text($post_id);
        }
        $display_values = $display_values_cache[$post_id];
        $name_key = ll_tools_word_grid_normalize_name_key((string) ($display_values['word_text'] ?? ''));
        if ($name_key !== '') {
            if (!isset($name_map[$name_key])) {
                $name_map[$name_key] = [];
            }
            $name_map[$name_key][$post_id] = true;
        }

        $thumb_id = function_exists('ll_tools_get_effective_word_image_attachment_id_for_word')
            ? (int) ll_tools_get_effective_word_image_attachment_id_for_word($post_id, true)
            : (int) get_post_thumbnail_id($post_id);
        if ($thumb_id > 0) {
            if (!isset($image_map[$thumb_id])) {
                $image_map[$thumb_id] = [];
            }
            $image_map[$thumb_id][$post_id] = true;
        }
    }

    if (count($post_map) < 2) {
        return $posts;
    }

    $link_ids = static function (array $members) use (&$links): void {
        $ids = array_values(array_unique(array_filter(array_map('intval', $members), function ($id) {
            return $id > 0;
        })));
        if (count($ids) < 2) {
            return;
        }

        $anchor = (int) $ids[0];
        if (!isset($links[$anchor])) {
            return;
        }

        $total = count($ids);
        for ($i = 1; $i < $total; $i++) {
            $member_id = (int) $ids[$i];
            if ($member_id <= 0 || !isset($links[$member_id])) {
                continue;
            }
            $links[$anchor][$member_id] = true;
            $links[$member_id][$anchor] = true;
        }
    };

    foreach ($name_map as $members) {
        $link_ids(array_keys($members));
    }
    foreach ($image_map as $members) {
        $link_ids(array_keys($members));
    }

    $visited = [];
    $clusters = [];
    foreach (array_keys($post_map) as $post_id) {
        if (isset($visited[$post_id])) {
            continue;
        }

        $stack = [$post_id];
        $visited[$post_id] = true;
        $members = [];

        while (!empty($stack)) {
            $current = array_pop($stack);
            $members[] = $current;

            $neighbors = isset($links[$current]) ? array_keys($links[$current]) : [];
            foreach ($neighbors as $neighbor_id) {
                $neighbor_id = (int) $neighbor_id;
                if ($neighbor_id <= 0 || isset($visited[$neighbor_id])) {
                    continue;
                }
                $visited[$neighbor_id] = true;
                $stack[] = $neighbor_id;
            }
        }

        usort($members, function ($left, $right) use ($index_map) {
            return ($index_map[$left] ?? PHP_INT_MAX) <=> ($index_map[$right] ?? PHP_INT_MAX);
        });

        $clusters[] = [
            'first_index' => $index_map[$members[0]] ?? PHP_INT_MAX,
            'members' => $members,
        ];
    }

    if (empty($clusters)) {
        return $posts;
    }

    usort($clusters, function ($left, $right) {
        return ($left['first_index'] ?? PHP_INT_MAX) <=> ($right['first_index'] ?? PHP_INT_MAX);
    });

    $ordered = [];
    $used = [];
    foreach ($clusters as $cluster) {
        $members = isset($cluster['members']) && is_array($cluster['members']) ? $cluster['members'] : [];
        foreach ($members as $member_id) {
            $member_id = (int) $member_id;
            if ($member_id <= 0 || isset($used[$member_id]) || !isset($post_map[$member_id])) {
                continue;
            }
            $ordered[] = $post_map[$member_id];
            $used[$member_id] = true;
        }
    }

    foreach ($posts as $post) {
        $post_id = isset($post->ID) ? (int) $post->ID : 0;
        if ($post_id > 0 && isset($used[$post_id])) {
            continue;
        }
        $ordered[] = $post;
    }

    return $ordered;
}

function ll_tools_word_grid_is_lesson_context(array $context): bool {
    return !empty($context['lesson_id']) || is_singular('ll_vocab_lesson') || !empty($GLOBALS['ll_tools_word_grid_force_lesson_context']) || !empty($context['editor_context']);
}

function ll_tools_word_grid_should_force_media_for_lesson_context(array $context): bool {
    if (!ll_tools_word_grid_is_lesson_context($context) || empty($context['is_text_based'])) {
        return false;
    }
    if (!function_exists('ll_tools_filter_word_ids_with_effective_images')) {
        return false;
    }

    $category_term = $context['category_term'] ?? null;
    $category_id = ($category_term instanceof WP_Term && !is_wp_error($category_term))
        ? (int) $category_term->term_id
        : 0;
    $wordset_id = isset($context['wordset_id']) ? (int) $context['wordset_id'] : 0;
    if ($category_id <= 0 || $wordset_id <= 0) {
        return false;
    }

    $specific_word_ids = array_values(array_filter(array_map('intval', (array) ($context['specific_word_ids'] ?? [])), static function (int $word_id): bool {
        return $word_id > 0;
    }));
    $deepest_only = !empty($context['deepest_only']);

    static $request_cache = [];
    $cache_key = $category_id . ':' . $wordset_id . ':' . ($deepest_only ? '1' : '0') . ':' . md5(wp_json_encode($specific_word_ids));
    if (array_key_exists($cache_key, $request_cache)) {
        return $request_cache[$cache_key];
    }

    if (!empty($specific_word_ids)) {
        $word_ids = $specific_word_ids;
    } else {
        $word_ids = array_values(array_filter(array_map('intval', (array) get_posts([
            'post_type' => 'words',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'orderby' => 'date',
            'order' => 'ASC',
            'suppress_filters' => true,
            'tax_query' => [
                [
                    'taxonomy' => 'word-category',
                    'field' => 'term_id',
                    'terms' => [$category_id],
                ],
                [
                    'taxonomy' => 'wordset',
                    'field' => 'term_id',
                    'terms' => [$wordset_id],
                ],
            ],
        ])), static function (int $word_id): bool {
            return $word_id > 0;
        }));
    }

    if ($deepest_only && !empty($word_ids)) {
        $word_ids = ll_tools_word_grid_filter_word_ids_to_deepest_category($word_ids, $category_id);
    }
    if (!empty($word_ids) && function_exists('ll_tools_filter_specific_wrong_answer_only_word_ids')) {
        $word_ids = ll_tools_filter_specific_wrong_answer_only_word_ids($word_ids);
    }
    $word_ids = array_values(array_unique(array_filter(array_map('intval', $word_ids), static function (int $word_id): bool {
        return $word_id > 0;
    })));
    if (empty($word_ids)) {
        $request_cache[$cache_key] = false;
        return false;
    }

    $image_backed_word_ids = ll_tools_filter_word_ids_with_effective_images($word_ids, true);
    $should_force = !empty($image_backed_word_ids) && count($image_backed_word_ids) === count($word_ids);
    $request_cache[$cache_key] = $should_force;
    return $should_force;
}

function ll_tools_word_grid_should_sort_visible_titles(array $context): bool {
    if (!ll_tools_word_grid_is_lesson_context($context)) {
        return false;
    }

    return empty($context['hide_lesson_grid_text']);
}

function ll_tools_word_grid_sort_posts_by_display_title(array $posts, array &$display_values_cache = []): array {
    if (count($posts) < 2) {
        return $posts;
    }

    $sortable = [];
    foreach ($posts as $index => $post) {
        if (!($post instanceof WP_Post)) {
            continue;
        }

        $post_id = (int) $post->ID;
        if ($post_id <= 0) {
            continue;
        }

        if (!isset($display_values_cache[$post_id]) || !is_array($display_values_cache[$post_id])) {
            $display_values_cache[$post_id] = ll_tools_word_grid_resolve_display_text($post_id);
        }

        $display_values = $display_values_cache[$post_id];
        $word_text = html_entity_decode(trim((string) ($display_values['word_text'] ?? '')), ENT_QUOTES, 'UTF-8');
        $translation_text = html_entity_decode(trim((string) ($display_values['translation_text'] ?? '')), ENT_QUOTES, 'UTF-8');
        $sort_title = ($word_text !== '') ? $word_text : $translation_text;

        $sortable[] = [
            'index' => (int) $index,
            'post' => $post,
            'sort_title' => $sort_title,
            'translation_text' => $translation_text,
            'raw_title' => html_entity_decode((string) $post->post_title, ENT_QUOTES, 'UTF-8'),
        ];
    }

    if (count($sortable) < 2) {
        return $posts;
    }

    usort($sortable, static function (array $left, array $right): int {
        $left_title = (string) ($left['sort_title'] ?? '');
        $right_title = (string) ($right['sort_title'] ?? '');
        if (function_exists('ll_tools_locale_compare_strings')) {
            $compared = ll_tools_locale_compare_strings($left_title, $right_title);
        } else {
            $compared = strnatcasecmp($left_title, $right_title);
        }
        if ($compared !== 0) {
            return $compared;
        }

        $left_translation = (string) ($left['translation_text'] ?? '');
        $right_translation = (string) ($right['translation_text'] ?? '');
        if (function_exists('ll_tools_locale_compare_strings')) {
            $compared = ll_tools_locale_compare_strings($left_translation, $right_translation);
        } else {
            $compared = strnatcasecmp($left_translation, $right_translation);
        }
        if ($compared !== 0) {
            return $compared;
        }

        $left_raw_title = (string) ($left['raw_title'] ?? '');
        $right_raw_title = (string) ($right['raw_title'] ?? '');
        if (function_exists('ll_tools_locale_compare_strings')) {
            $compared = ll_tools_locale_compare_strings($left_raw_title, $right_raw_title);
        } else {
            $compared = strnatcasecmp($left_raw_title, $right_raw_title);
        }
        if ($compared !== 0) {
            return $compared;
        }

        return ((int) ($left['index'] ?? 0)) <=> ((int) ($right['index'] ?? 0));
    });

    return array_values(array_map(static function (array $entry) {
        return $entry['post'];
    }, $sortable));
}

function ll_tools_user_can_edit_vocab_words(int $wordset_id = 0): bool {
    if (!is_user_logged_in() || !current_user_can('view_ll_tools')) {
        return false;
    }

    if (current_user_can('manage_options')) {
        return true;
    }

    $user = wp_get_current_user();
    if (in_array('ll_tools_editor', (array) $user->roles, true)) {
        return true;
    }

    if ($wordset_id <= 0 && is_singular('ll_vocab_lesson')) {
        $lesson_id = (int) get_queried_object_id();
        if ($lesson_id > 0) {
            $wordset_id = (int) get_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, true);
        }
    }

    if ($wordset_id > 0 && function_exists('ll_tools_current_user_can_manage_wordset_content')) {
        return ll_tools_current_user_can_manage_wordset_content($wordset_id);
    }

    return false;
}

function ll_tools_word_grid_normalize_category_id_list($category_ids): array {
    if (function_exists('ll_tools_wordset_normalize_category_id_list')) {
        return ll_tools_wordset_normalize_category_id_list((array) $category_ids);
    }

    $normalized = [];
    foreach ((array) $category_ids as $category_id) {
        $category_id = (int) $category_id;
        if ($category_id <= 0 || isset($normalized[$category_id])) {
            continue;
        }
        $normalized[$category_id] = $category_id;
    }

    return array_values($normalized);
}

function ll_tools_word_grid_category_belongs_to_wordset_editor_scope(int $category_id, int $wordset_id): bool {
    $category_id = (int) $category_id;
    $wordset_id = (int) $wordset_id;
    if ($category_id <= 0 || $wordset_id <= 0) {
        return false;
    }

    $owner_id = 0;
    if (function_exists('ll_tools_get_category_wordset_owner_id')) {
        $owner_id = (int) ll_tools_get_category_wordset_owner_id($category_id);
    } elseif (defined('LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY')) {
        $owner_id = (int) get_term_meta($category_id, LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY, true);
    }

    return $owner_id <= 0 || $owner_id === $wordset_id;
}

function ll_tools_word_grid_get_category_editor_terms_for_wordset(int $wordset_id): array {
    $wordset_id = (int) $wordset_id;
    if ($wordset_id <= 0) {
        return [];
    }

    $terms = [];
    if (function_exists('ll_tools_recorder_get_category_terms_for_wordsets')) {
        $terms = ll_tools_recorder_get_category_terms_for_wordsets([$wordset_id], get_current_user_id());
    }

    if (empty($terms)) {
        $category_ids = [];

        if (defined('LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY')) {
            $owned_ids = get_terms([
                'taxonomy'   => 'word-category',
                'hide_empty' => false,
                'fields'     => 'ids',
                'meta_query' => [
                    [
                        'key'   => LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY,
                        'value' => $wordset_id,
                    ],
                ],
            ]);
            if (!is_wp_error($owned_ids)) {
                $category_ids = array_merge($category_ids, array_map('intval', (array) $owned_ids));
            }
        }

        $word_ids = get_posts([
            'post_type'      => 'words',
            'post_status'    => ['publish', 'draft', 'pending', 'future', 'private'],
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'tax_query'      => [
                [
                    'taxonomy' => 'wordset',
                    'field'    => 'term_id',
                    'terms'    => [$wordset_id],
                ],
            ],
        ]);
        if (!empty($word_ids)) {
            $used_ids = wp_get_object_terms(array_map('intval', (array) $word_ids), 'word-category', ['fields' => 'ids']);
            if (!is_wp_error($used_ids)) {
                $category_ids = array_merge($category_ids, array_map('intval', (array) $used_ids));
            }
        }

        if (defined('LL_TOOLS_WORD_IMAGE_WORDSET_OWNER_META_KEY')) {
            $image_ids = get_posts([
                'post_type'      => 'word_images',
                'post_status'    => ['publish', 'draft', 'pending', 'future', 'private'],
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'no_found_rows'  => true,
                'meta_query'     => [
                    [
                        'key'   => LL_TOOLS_WORD_IMAGE_WORDSET_OWNER_META_KEY,
                        'value' => $wordset_id,
                    ],
                ],
            ]);
            if (!empty($image_ids)) {
                $image_category_ids = wp_get_object_terms(array_map('intval', (array) $image_ids), 'word-category', ['fields' => 'ids']);
                if (!is_wp_error($image_category_ids)) {
                    $category_ids = array_merge($category_ids, array_map('intval', (array) $image_category_ids));
                }
            }
        }

        if (!empty($category_ids) && function_exists('ll_tools_get_effective_category_id_for_wordset')) {
            $effective_ids = [];
            foreach (ll_tools_word_grid_normalize_category_id_list($category_ids) as $category_id) {
                $effective_id = (int) ll_tools_get_effective_category_id_for_wordset($category_id, $wordset_id, false);
                $effective_ids[] = $effective_id > 0 ? $effective_id : $category_id;
            }
            $category_ids = $effective_ids;
        }

        $category_ids = ll_tools_word_grid_normalize_category_id_list($category_ids);
        if (!empty($category_ids)) {
            $terms = get_terms([
                'taxonomy'   => 'word-category',
                'hide_empty' => false,
                'include'    => $category_ids,
            ]);
            if (is_wp_error($terms)) {
                $terms = [];
            }
        }
    }

    if (function_exists('ll_tools_filter_category_terms_for_user')) {
        $terms = ll_tools_filter_category_terms_for_user((array) $terms, get_current_user_id());
    }

    $terms_by_id = [];
    $label_map = [];
    foreach ((array) $terms as $term) {
        if (!($term instanceof WP_Term) || is_wp_error($term) || $term->taxonomy !== 'word-category') {
            continue;
        }
        if ((string) $term->slug === 'uncategorized') {
            continue;
        }

        $term_id = (int) $term->term_id;
        if ($term_id <= 0) {
            continue;
        }
        if (!ll_tools_word_grid_category_belongs_to_wordset_editor_scope($term_id, $wordset_id)) {
            continue;
        }

        $label = function_exists('ll_tools_get_category_display_name')
            ? (string) ll_tools_get_category_display_name($term, ['wordset_ids' => [$wordset_id]])
            : (string) $term->name;
        $terms_by_id[$term_id] = $term;
        $label_map[$term_id] = $label !== '' ? $label : (string) $term->name;
    }

    $ordered_ids = array_map('intval', array_keys($terms_by_id));
    if (!empty($ordered_ids) && function_exists('ll_tools_wordset_sort_category_ids')) {
        $ordered_ids = ll_tools_wordset_sort_category_ids($ordered_ids, $wordset_id, [
            'category_name_map' => $label_map,
        ]);
    } else {
        usort($ordered_ids, static function (int $left, int $right) use ($label_map): int {
            $left_label = (string) ($label_map[$left] ?? '');
            $right_label = (string) ($label_map[$right] ?? '');
            if (function_exists('ll_tools_locale_compare_strings')) {
                $cmp = ll_tools_locale_compare_strings($left_label, $right_label);
            } else {
                $cmp = strnatcasecmp($left_label, $right_label);
            }
            return ($cmp !== 0) ? $cmp : ($left <=> $right);
        });
    }

    $ordered_terms = [];
    foreach ($ordered_ids as $term_id) {
        if (isset($terms_by_id[$term_id])) {
            $ordered_terms[] = $terms_by_id[$term_id];
        }
    }

    return $ordered_terms;
}

function ll_tools_word_grid_get_category_editor_rows(int $wordset_id): array {
    $rows = [];
    foreach (ll_tools_word_grid_get_category_editor_terms_for_wordset($wordset_id) as $term) {
        if (!($term instanceof WP_Term) || is_wp_error($term)) {
            continue;
        }

        $label = function_exists('ll_tools_get_category_display_name')
            ? (string) ll_tools_get_category_display_name($term, ['wordset_ids' => [$wordset_id]])
            : (string) $term->name;
        $rows[] = [
            'id'    => (int) $term->term_id,
            'slug'  => (string) $term->slug,
            'label' => $label !== '' ? $label : (string) $term->name,
        ];
    }

    return $rows;
}

function ll_tools_word_grid_get_selected_category_ids_for_editor(int $word_id, int $wordset_id, array $available_category_ids): array {
    $word_id = (int) $word_id;
    $wordset_id = (int) $wordset_id;
    $available_category_ids = ll_tools_word_grid_normalize_category_id_list($available_category_ids);
    if ($word_id <= 0 || $wordset_id <= 0 || empty($available_category_ids)) {
        return [];
    }

    $available_lookup = array_fill_keys($available_category_ids, true);
    $term_ids = wp_get_post_terms($word_id, 'word-category', ['fields' => 'ids']);
    if (is_wp_error($term_ids) || empty($term_ids)) {
        return [];
    }

    $selected = [];
    foreach ((array) $term_ids as $term_id) {
        $term_id = (int) $term_id;
        if ($term_id <= 0) {
            continue;
        }
        $effective_id = $term_id;
        if (function_exists('ll_tools_get_effective_category_id_for_wordset')) {
            $resolved_id = (int) ll_tools_get_effective_category_id_for_wordset($term_id, $wordset_id, false);
            if ($resolved_id > 0) {
                $effective_id = $resolved_id;
            }
        }
        if (isset($available_lookup[$effective_id])) {
            $selected[$effective_id] = true;
        } elseif (isset($available_lookup[$term_id])) {
            $selected[$term_id] = true;
        }
    }

    $ordered = [];
    foreach ($available_category_ids as $category_id) {
        if (isset($selected[$category_id])) {
            $ordered[] = (int) $category_id;
        }
    }

    return $ordered;
}

function ll_tools_word_grid_update_word_categories_for_wordset(int $word_id, int $wordset_id, array $submitted_category_ids, array $available_category_ids) {
    $word_id = (int) $word_id;
    $wordset_id = (int) $wordset_id;
    $available_category_ids = ll_tools_word_grid_normalize_category_id_list($available_category_ids);
    $submitted_category_ids = ll_tools_word_grid_normalize_category_id_list($submitted_category_ids);

    if ($word_id <= 0 || $wordset_id <= 0) {
        return new WP_Error('ll_word_category_scope_missing', __('Missing word set category scope.', 'll-tools-text-domain'));
    }

    $available_lookup = array_fill_keys($available_category_ids, true);
    foreach ($submitted_category_ids as $category_id) {
        if (!isset($available_lookup[$category_id])) {
            return new WP_Error('ll_word_category_out_of_scope', __('Select categories from this word set.', 'll-tools-text-domain'));
        }
    }

    $previous_ids_raw = wp_get_post_terms($word_id, 'word-category', ['fields' => 'ids']);
    $previous_ids = is_wp_error($previous_ids_raw)
        ? []
        : ll_tools_word_grid_normalize_category_id_list($previous_ids_raw);

    $preserved_ids = [];
    foreach ($previous_ids as $category_id) {
        $effective_id = $category_id;
        if (function_exists('ll_tools_get_effective_category_id_for_wordset')) {
            $resolved_id = (int) ll_tools_get_effective_category_id_for_wordset($category_id, $wordset_id, false);
            if ($resolved_id > 0) {
                $effective_id = $resolved_id;
            }
        }
        if (isset($available_lookup[$category_id]) || isset($available_lookup[$effective_id])) {
            continue;
        }

        $preserved_ids[] = $category_id;
    }

    $new_ids = ll_tools_word_grid_normalize_category_id_list(array_merge($preserved_ids, $submitted_category_ids));
    $set_result = wp_set_post_terms($word_id, $new_ids, 'word-category', false);
    if (is_wp_error($set_result)) {
        return $set_result;
    }

    $changed = $previous_ids !== $new_ids;
    if ($changed) {
        clean_post_cache($word_id);
    }

    return [
        'selected_category_ids' => $submitted_category_ids,
        'all_category_ids'      => $new_ids,
        'previous_category_ids' => $previous_ids,
        'changed'              => $changed,
    ];
}

function ll_tools_word_grid_should_show_draft_words(array $context): bool {
    if (!empty($context['editor_context']) && !empty($context['can_edit_words'])) {
        return true;
    }

    $lesson_id = isset($context['lesson_id']) ? (int) $context['lesson_id'] : 0;
    if ($lesson_id <= 0 || empty($context['can_edit_words'])) {
        return false;
    }

    return ll_tools_word_grid_is_lesson_context($context);
}

function ll_tools_word_grid_should_show_staff_hidden_words(array $context): bool {
    return ll_tools_word_grid_should_show_draft_words($context);
}

function ll_tools_word_grid_get_draft_word_note(int $word_id): string {
    $word = get_post((int) $word_id);
    if (!($word instanceof WP_Post) || $word->post_type !== 'words' || $word->post_status !== 'draft') {
        return '';
    }

    $requires_audio = function_exists('ll_word_requires_audio_to_publish')
        ? ll_word_requires_audio_to_publish((int) $word->ID)
        : true;
    $has_published_audio = function_exists('ll_tools_word_has_published_audio')
        ? ll_tools_word_has_published_audio((int) $word->ID)
        : false;

    if ($requires_audio && !$has_published_audio) {
        $audio_posts = get_posts([
            'post_type'              => 'word_audio',
            'post_parent'            => (int) $word->ID,
            'post_status'            => ['publish', 'draft', 'pending', 'future', 'private'],
            'posts_per_page'         => -1,
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'suppress_filters'       => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ]);

        if (empty($audio_posts)) {
            return __('No audio recording yet.', 'll-tools-text-domain');
        }

        $has_audio_file = false;
        foreach ((array) $audio_posts as $audio_post_id) {
            $audio_path = trim((string) get_post_meta((int) $audio_post_id, 'audio_file_path', true));
            if ($audio_path !== '') {
                $has_audio_file = true;
                break;
            }
        }

        if ($has_audio_file) {
            return __('Audio exists but is not published yet.', 'll-tools-text-domain');
        }

        return __('Audio record exists but has no file yet.', 'll-tools-text-domain');
    }

    return __('Word has not been published yet.', 'll-tools-text-domain');
}

function ll_tools_word_grid_get_presentation_hidden_word_note(int $word_id, string $reason = ''): string {
    $word = get_post((int) $word_id);
    if (!($word instanceof WP_Post) || $word->post_type !== 'words' || $word->post_status !== 'publish') {
        return '';
    }

    if ($reason === 'missing_audio') {
        return __('Published but hidden. Reason: missing audio.', 'll-tools-text-domain');
    }

    return __('Published but hidden. Reason: missing image.', 'll-tools-text-domain');
}

function ll_tools_word_grid_get_staff_inactive_image_note(): string {
    return __('Not public: quiz does not use pictures.', 'll-tools-text-domain');
}

function ll_tools_word_grid_move_lookup_posts_to_end(array $posts, array $end_lookup): array {
    if (empty($posts) || empty($end_lookup)) {
        return $posts;
    }

    $front_posts = [];
    $end_posts = [];
    foreach ($posts as $post_obj) {
        $post_id = isset($post_obj->ID) ? (int) $post_obj->ID : 0;
        if ($post_id > 0 && isset($end_lookup[$post_id])) {
            $end_posts[] = $post_obj;
        } else {
            $front_posts[] = $post_obj;
        }
    }

    return array_merge($front_posts, $end_posts);
}

function ll_tools_word_grid_resolve_context($atts): array {
    $atts = shortcode_atts([
        'category' => '',
        'wordset'  => '',
        'deepest_only' => '',
        'word_ids' => '',
        'lesson_id' => '',
        'editor_context' => '',
    ], (array) $atts);

    $sanitized_category = sanitize_text_field((string) ($atts['category'] ?? ''));
    $sanitized_wordset = sanitize_text_field((string) ($atts['wordset'] ?? ''));
    $specific_word_ids = array_values(array_filter(array_map('intval', preg_split('/[\s,|]+/', (string) ($atts['word_ids'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: []), static function (int $word_id): bool {
        return $word_id > 0;
    }));
    $specific_word_ids = array_values(array_unique($specific_word_ids));
    $lesson_id = isset($atts['lesson_id']) ? (int) $atts['lesson_id'] : 0;
    if ($lesson_id <= 0 && is_singular('ll_vocab_lesson')) {
        $lesson_id = (int) get_queried_object_id();
    }
    $deepest_only = false;
    if (!empty($atts['deepest_only'])) {
        $deepest_only = filter_var($atts['deepest_only'], FILTER_VALIDATE_BOOLEAN);
    }
    $editor_context = !empty($atts['editor_context']) && filter_var($atts['editor_context'], FILTER_VALIDATE_BOOLEAN);

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

    $category_term = null;
    $access_denied = false;
    $is_text_based = false;
    $has_text_only_answer_options = false;
    $hide_lesson_grid_text = false;
    $category_quiz_uses_images = true;
    $category_quiz_requires_audio = false;
    if ($sanitized_category !== '') {
        if (function_exists('ll_tools_resolve_word_category_term_for_wordsets')) {
            $category_term = ll_tools_resolve_word_category_term_for_wordsets(
                $sanitized_category,
                $wordset_id > 0 ? [$wordset_id] : []
            );
        } else {
            $category_term = get_term_by('slug', sanitize_title($sanitized_category), 'word-category');
        }

        if ($category_term instanceof WP_Term && !is_wp_error($category_term)) {
            $sanitized_category = (string) $category_term->slug;
            if (function_exists('ll_tools_user_can_view_category') && !ll_tools_user_can_view_category($category_term)) {
                $access_denied = true;
            }
        } else {
            $sanitized_category = sanitize_title($sanitized_category);
        }
    }
    if (!$access_denied && $category_term && !is_wp_error($category_term) && function_exists('ll_tools_get_category_quiz_config')) {
        $quiz_config = ll_tools_get_category_quiz_config($category_term);
        if ($wordset_id > 0 && function_exists('ll_tools_apply_wordset_quiz_presentation_overrides')) {
            $quiz_config = ll_tools_apply_wordset_quiz_presentation_overrides((array) $quiz_config, [$wordset_id]);
        }
        $prompt_type = (string) ($quiz_config['prompt_type'] ?? 'audio');
        $option_type = (string) ($quiz_config['option_type'] ?? '');
        $requires_images = function_exists('ll_tools_quiz_requires_image')
            ? ll_tools_quiz_requires_image(['prompt_type' => $prompt_type, 'option_type' => $option_type], $option_type)
            : (($prompt_type === 'image') || ($option_type === 'image'));
        $category_quiz_requires_audio = function_exists('ll_tools_quiz_requires_audio')
            ? ll_tools_quiz_requires_audio(['prompt_type' => $prompt_type, 'option_type' => $option_type], $option_type)
            : ($prompt_type === 'audio' || in_array($option_type, ['audio', 'text_audio'], true));
        $category_quiz_uses_images = $requires_images;
        $is_text_based = !$requires_images && (strpos($option_type, 'text') === 0);
        $has_text_only_answer_options = in_array($option_type, ['text', 'text_translation', 'text_title'], true);
    }

    if ($category_term && !is_wp_error($category_term) && function_exists('ll_tools_should_hide_lesson_grid_text')) {
        $hide_lesson_grid_text = ll_tools_should_hide_lesson_grid_text($category_term, $wordset_id);
    }
    if ($is_text_based && ll_tools_word_grid_should_force_media_for_lesson_context([
        'category_term' => $category_term,
        'wordset_id' => $wordset_id,
        'deepest_only' => $deepest_only,
        'specific_word_ids' => $specific_word_ids,
        'lesson_id' => $lesson_id,
        'is_text_based' => $is_text_based,
    ])) {
        $is_text_based = false;
    }

    $wordset_has_gender = false;
    if ($wordset_id > 0 && function_exists('ll_tools_wordset_has_grammatical_gender')) {
        $wordset_has_gender = ll_tools_wordset_has_grammatical_gender($wordset_id);
    }
    $wordset_has_plurality = false;
    if ($wordset_id > 0 && function_exists('ll_tools_wordset_has_plurality')) {
        $wordset_has_plurality = ll_tools_wordset_has_plurality($wordset_id);
    }
    $wordset_has_verb_tense = false;
    if ($wordset_id > 0 && function_exists('ll_tools_wordset_has_verb_tense')) {
        $wordset_has_verb_tense = ll_tools_wordset_has_verb_tense($wordset_id);
    }
    $wordset_has_verb_mood = false;
    if ($wordset_id > 0 && function_exists('ll_tools_wordset_has_verb_mood')) {
        $wordset_has_verb_mood = ll_tools_wordset_has_verb_mood($wordset_id);
    }

    $is_lesson_context = (
        is_singular('ll_vocab_lesson')
        || (!empty($GLOBALS['ll_tools_word_grid_force_lesson_context']) && wp_doing_ajax())
        || $editor_context
    );
    $can_edit_words = ll_tools_user_can_edit_vocab_words($wordset_id) && $is_lesson_context;
    $show_staff_inactive_images = $can_edit_words && $is_text_based && !$category_quiz_uses_images;
    $can_manage_internal_notes = $is_lesson_context
        && function_exists('ll_tools_current_user_can_manage_internal_review_notes')
        && ll_tools_current_user_can_manage_internal_review_notes($wordset_id);

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

    return [
        'atts'                         => $atts,
        'sanitized_category'           => $sanitized_category,
        'sanitized_wordset'            => $sanitized_wordset,
        'specific_word_ids'            => $specific_word_ids,
        'deepest_only'                 => $deepest_only,
        'lesson_id'                    => $lesson_id,
        'access_denied'                => $access_denied,
        'category_term'                => $category_term,
        'wordset_term'                 => $wordset_term,
        'wordset_id'                   => $wordset_id,
        'is_text_based'                => $is_text_based,
        'has_text_only_answer_options' => $has_text_only_answer_options,
        'hide_lesson_grid_text'        => $hide_lesson_grid_text,
        'category_quiz_requires_audio' => $category_quiz_requires_audio,
        'show_staff_inactive_images'   => $show_staff_inactive_images,
        'editor_context'                => $editor_context,
        'wordset_has_gender'           => $wordset_has_gender,
        'wordset_has_plurality'        => $wordset_has_plurality,
        'wordset_has_verb_tense'       => $wordset_has_verb_tense,
        'wordset_has_verb_mood'        => $wordset_has_verb_mood,
        'can_edit_words'               => $can_edit_words,
        'can_manage_internal_notes'     => $can_manage_internal_notes,
        'user_study_state'             => $user_study_state,
    ];
}

function ll_tools_word_grid_build_base_frontend_config(array $context): array {
    $wordset_id = isset($context['wordset_id']) ? (int) $context['wordset_id'] : 0;
    $can_edit_words = !empty($context['can_edit_words']);
    $can_manage_internal_notes = !empty($context['can_manage_internal_notes']);
    $transcription_service = function_exists('ll_tools_get_wordset_transcription_service_config')
        ? ll_tools_get_wordset_transcription_service_config([$wordset_id], true)
        : [
            'provider' => '',
            'uses_local_browser' => false,
            'target_field' => 'recording_text',
            'local_endpoint' => '',
            'enabled' => false,
        ];
    $transcription_config = function_exists('ll_tools_get_wordset_recording_transcription_config')
        ? ll_tools_get_wordset_recording_transcription_config([$wordset_id], true)
        : [
            'mode' => 'ipa',
            'display_format' => 'brackets',
            'uses_ipa_font' => true,
            'supports_superscript' => true,
            'common_chars' => [],
            'common_chars_label' => '',
            'modifier_chars' => function_exists('ll_tools_get_secondary_text_keyboard_modifier_symbols') ? ll_tools_get_secondary_text_keyboard_modifier_symbols('ipa') : ['ʰ', 'ʲ', 'ʷ', 'ː'],
            'modifier_chars_label' => __('Diacritics and signs', 'll-tools-text-domain'),
            'wordset_chars_label' => __('Wordset symbols', 'll-tools-text-domain'),
        ];
    $transcription_mode = (string) ($transcription_config['mode'] ?? 'ipa');
    $ipa_text_language_code = '';
    $target_lang_raw = '';
    if ($wordset_id > 0 && function_exists('ll_tools_get_wordset_target_language')) {
        $target_lang_raw = (string) ll_tools_get_wordset_target_language([$wordset_id]);
    }
    if ($target_lang_raw === '') {
        $target_lang_raw = (string) get_option('ll_target_language', '');
    }
    if ($target_lang_raw !== '') {
        $ipa_text_language_code = function_exists('ll_tools_resolve_language_code_from_label')
            ? (string) ll_tools_resolve_language_code_from_label($target_lang_raw, 'lower')
            : strtolower(ll_tools_word_grid_format_language_code($target_lang_raw));
    }
    $user_study_state = isset($context['user_study_state']) && is_array($context['user_study_state'])
        ? $context['user_study_state']
        : [
            'wordset_id'       => 0,
            'category_ids'     => [],
            'starred_word_ids' => [],
            'star_mode'        => 'normal',
            'fast_transitions' => false,
        ];

    $ipa_special_chars = [];
    $ipa_symbol_recording_counts = [];
    $secondary_text_keyboard_groups = [];
    $secondary_text_symbol_details = [];
    $ipa_letter_map = [];
    if ($can_edit_words && $wordset_id > 0) {
        $ipa_inventory = function_exists('ll_tools_word_grid_get_wordset_reviewed_ipa_symbol_inventory')
            ? ll_tools_word_grid_get_wordset_reviewed_ipa_symbol_inventory($wordset_id)
            : [
                'symbols' => function_exists('ll_tools_word_grid_get_wordset_ipa_special_chars')
                    ? ll_tools_word_grid_get_wordset_ipa_special_chars($wordset_id)
                    : [],
                'recording_counts' => [],
            ];
        $ipa_special_chars = array_values((array) ($ipa_inventory['symbols'] ?? []));
        $ipa_symbol_recording_counts = (array) ($ipa_inventory['recording_counts'] ?? []);
        if (!empty($ipa_special_chars)) {
            $normalized_chars = [];
            foreach ((array) $ipa_special_chars as $char) {
                $normalized = ll_tools_word_grid_normalize_ipa_output((string) $char, $transcription_mode);
                if ($normalized === '' || in_array($normalized, $normalized_chars, true)) {
                    continue;
                }
                $normalized_chars[] = $normalized;
            }
            $ipa_special_chars = $normalized_chars;
            if (function_exists('ll_tools_sort_secondary_text_symbols')) {
                $ipa_special_chars = ll_tools_sort_secondary_text_symbols($ipa_special_chars, $transcription_mode);
            }
        }

        $letter_maps = function_exists('ll_tools_word_grid_get_wordset_ipa_letter_maps')
            ? ll_tools_word_grid_get_wordset_ipa_letter_maps($wordset_id)
            : [];
        $blocklist = function_exists('ll_tools_word_grid_get_wordset_ipa_letter_blocklist')
            ? ll_tools_word_grid_get_wordset_ipa_letter_blocklist($wordset_id)
            : [];
        if (!empty($letter_maps['auto']) || !empty($letter_maps['manual'])) {
            $ipa_letter_map = ll_tools_word_grid_prepare_ipa_letter_suggestions(
                (array) ($letter_maps['auto'] ?? []),
                (array) ($letter_maps['manual'] ?? []),
                6,
                (array) $blocklist,
                $transcription_mode
            );
        }
    }

    $secondary_text_common_chars = array_values(array_map('strval', (array) ($transcription_config['common_chars'] ?? [])));
    if (function_exists('ll_tools_sort_secondary_text_symbols')) {
        $secondary_text_common_chars = ll_tools_sort_secondary_text_symbols($secondary_text_common_chars, $transcription_mode);
    }
    if (function_exists('ll_tools_compact_secondary_text_keyboard_symbols')) {
        $secondary_text_common_chars = ll_tools_compact_secondary_text_keyboard_symbols($secondary_text_common_chars, $transcription_mode);
    }
    $secondary_text_modifier_chars = $transcription_mode === 'ipa'
        ? array_values(array_map('strval', (array) ($transcription_config['modifier_chars'] ?? [])))
        : [];
    if ($transcription_mode === 'ipa' && empty($secondary_text_modifier_chars) && function_exists('ll_tools_get_secondary_text_keyboard_modifier_symbols')) {
        $secondary_text_modifier_chars = ll_tools_get_secondary_text_keyboard_modifier_symbols($transcription_mode);
    }
    if ($transcription_mode === 'ipa' && function_exists('ll_tools_build_secondary_text_keyboard_groups')) {
        $illegal_symbols = function_exists('ll_tools_get_wordset_secondary_text_illegal_symbols')
            ? ll_tools_get_wordset_secondary_text_illegal_symbols($wordset_id, $transcription_mode)
            : [];
        $secondary_text_keyboard_groups = ll_tools_build_secondary_text_keyboard_groups(
            $ipa_special_chars,
            $transcription_mode,
            $ipa_symbol_recording_counts,
            ['illegal_symbols' => $illegal_symbols]
        );
        $detail_symbols = function_exists('ll_tools_flatten_secondary_text_keyboard_groups')
            ? ll_tools_flatten_secondary_text_keyboard_groups($secondary_text_keyboard_groups)
            : array_values(array_unique(array_merge($secondary_text_modifier_chars, $ipa_special_chars)));
        $secondary_text_symbol_details = function_exists('ll_tools_get_secondary_text_keyboard_symbol_details')
            ? ll_tools_get_secondary_text_keyboard_symbol_details($detail_symbols, $transcription_mode)
            : [];
    }

    return [
        'ajaxUrl'    => admin_url('admin-ajax.php'),
        'nonce'      => is_user_logged_in() ? wp_create_nonce('ll_user_study') : '',
        'isLoggedIn' => is_user_logged_in(),
        'canEdit'    => $can_edit_words,
        'editNonce'  => $can_edit_words ? wp_create_nonce('ll_word_grid_edit') : '',
        'internalNotes' => [
            'enabled' => $can_manage_internal_notes,
            'action' => 'll_tools_save_internal_review_note',
            'nonce' => $can_manage_internal_notes ? wp_create_nonce('ll_internal_review_note') : '',
            'saveDelayMs' => (int) apply_filters('ll_tools_internal_review_note_save_delay_ms', 3000),
            'i18n' => [
                'saving' => __('Saving review note...', 'll-tools-text-domain'),
                'saved' => __('Review note saved.', 'll-tools-text-domain'),
                'error' => __('Unable to save the review note.', 'll-tools-text-domain'),
            ],
        ],
        'supportsIpaExtended' => ll_tools_word_grid_supports_ipa_extended(),
        'secondaryTextMode' => $transcription_mode,
        'secondaryTextDisplayFormat' => (string) ($transcription_config['display_format'] ?? 'plain'),
        'secondaryTextCommonChars' => $secondary_text_common_chars,
        'secondaryTextModifierChars' => $secondary_text_modifier_chars,
        'secondaryTextKeyboardGroups' => $secondary_text_keyboard_groups,
        'secondaryTextSymbolDetails' => $secondary_text_symbol_details,
        'secondaryTextUsesIpaFont' => !empty($transcription_config['uses_ipa_font']),
        'secondaryTextSupportsSuperscript' => !empty($transcription_config['supports_superscript']),
        'state'      => $user_study_state,
        'i18n'       => [
            'starLabel'      => __('Star word', 'll-tools-text-domain'),
            'unstarLabel'    => __('Unstar word', 'll-tools-text-domain'),
            'starAllLabel'   => __('Star all', 'll-tools-text-domain'),
            'unstarAllLabel' => __('Unstar all', 'll-tools-text-domain'),
        ],
        'editI18n'   => [
            'saving' => __('Saving...', 'll-tools-text-domain'),
            'savingBackground' => __('Saving in background...', 'll-tools-text-domain'),
            'saved'  => __('Saved.', 'll-tools-text-domain'),
            'error'  => __('Unable to save changes.', 'll-tools-text-domain'),
            'processingAudio' => __('Processing audio...', 'll-tools-text-domain'),
            'processedAudio' => __('Audio processed.', 'll-tools-text-domain'),
            'processAudio' => __('Process audio', 'll-tools-text-domain'),
            'reprocessAudio' => __('Reprocess audio', 'll-tools-text-domain'),
            'processAudioError' => __('Unable to process audio.', 'll-tools-text-domain'),
            'audioDecodeError' => __('Unable to read this audio file in the browser.', 'll-tools-text-domain'),
            'audioUnsupportedError' => __('This browser cannot process audio here.', 'll-tools-text-domain'),
            'sourceOriginal' => __('Using saved original audio', 'll-tools-text-domain'),
            'sourceCurrent' => __('Using current audio', 'll-tools-text-domain'),
            'playSelection' => __('Play clip', 'll-tools-text-domain'),
            'pauseSelection' => __('Pause clip', 'll-tools-text-domain'),
            'downloadAudio' => __('Download audio', 'll-tools-text-domain'),
            'waveformLoading' => __('Loading waveform...', 'll-tools-text-domain'),
            'waveformUnavailable' => __('Waveform unavailable.', 'll-tools-text-domain'),
            'recordings' => __('Recordings', 'll-tools-text-domain'),
            'deletingWord' => __('Deleting word...', 'll-tools-text-domain'),
            'wordDeleted' => __('Word moved to Trash.', 'll-tools-text-domain'),
            'deleteWordError' => __('Unable to delete word.', 'll-tools-text-domain'),
            'addingWord' => __('Adding word...', 'll-tools-text-domain'),
            'wordAdded' => __('Word added.', 'll-tools-text-domain'),
            'addWordError' => __('Unable to add word.', 'll-tools-text-domain'),
            'lessonGridLoading' => __('Lesson words are still loading.', 'll-tools-text-domain'),
            'deletingRecording' => __('Deleting recording...', 'll-tools-text-domain'),
            'recordingDeleted' => __('Recording moved to Trash.', 'll-tools-text-domain'),
            'deleteRecordingError' => __('Unable to delete recording.', 'll-tools-text-domain'),
            'movingRecording' => __('Moving recording...', 'll-tools-text-domain'),
            'recordingMoved' => __('Recording moved.', 'll-tools-text-domain'),
            'moveRecordingError' => __('Unable to move recording.', 'll-tools-text-domain'),
            'selectMoveTarget' => __('Choose a target word.', 'll-tools-text-domain'),
            'noMatchingWords' => __('No matching words.', 'll-tools-text-domain'),
            'ipaCommon' => (string) ($transcription_config['common_chars_label'] ?? ''),
            'ipaModifiers' => (string) ($transcription_config['modifier_chars_label'] ?? __('Diacritics and signs', 'll-tools-text-domain')),
            'ipaWordset' => (string) ($transcription_config['wordset_chars_label'] ?? __('Wordset symbols', 'll-tools-text-domain')),
            'secondaryTextCommon' => (string) ($transcription_config['common_chars_label'] ?? ''),
            'secondaryTextModifiers' => (string) ($transcription_config['modifier_chars_label'] ?? __('Diacritics and signs', 'll-tools-text-domain')),
            'secondaryTextWordset' => (string) ($transcription_config['wordset_chars_label'] ?? __('Wordset symbols', 'll-tools-text-domain')),
        ],
        'orderI18n' => [
            'saving' => __('Saving order...', 'll-tools-text-domain'),
            'saved' => __('Order saved.', 'll-tools-text-domain'),
            'error' => __('Unable to save the lesson order.', 'll-tools-text-domain'),
        ],
        'bulkI18n' => [
            'saving' => __('Updating...', 'll-tools-text-domain'),
            'saved' => __('Saved.', 'll-tools-text-domain'),
            'undoLabel' => __('Undo last bulk change', 'll-tools-text-domain'),
            'undoSuccess' => __('Bulk changes undone.', 'll-tools-text-domain'),
            'undoError' => __('Unable to undo bulk changes.', 'll-tools-text-domain'),
            'posSuccess' => __('Updated %d words.', 'll-tools-text-domain'),
            'genderSuccess' => __('Updated %d nouns.', 'll-tools-text-domain'),
            'pluralitySuccess' => __('Updated %d nouns.', 'll-tools-text-domain'),
            'verbTenseSuccess' => __('Updated %d verbs.', 'll-tools-text-domain'),
            'verbMoodSuccess' => __('Updated %d verbs.', 'll-tools-text-domain'),
            'posMissing' => __('Choose a part of speech.', 'll-tools-text-domain'),
            'genderMissing' => __('Choose a gender.', 'll-tools-text-domain'),
            'pluralityMissing' => __('Choose a plurality option.', 'll-tools-text-domain'),
            'verbTenseMissing' => __('Choose a verb tense option.', 'll-tools-text-domain'),
            'verbMoodMissing' => __('Choose a verb mood option.', 'll-tools-text-domain'),
            'error' => __('Unable to update words.', 'll-tools-text-domain'),
        ],
        'prereqI18n' => [
            'saving' => __('Saving prerequisites...', 'll-tools-text-domain'),
            'saved' => __('Prerequisites saved.', 'll-tools-text-domain'),
            'error' => __('Unable to save prerequisites.', 'll-tools-text-domain'),
            'empty' => __('No prerequisites selected.', 'll-tools-text-domain'),
            'remove' => __('Remove %s', 'll-tools-text-domain'),
            'optionAdd' => __('Add %s', 'll-tools-text-domain'),
            'optionRemove' => __('Remove %s', 'll-tools-text-domain'),
            'optionBlocked' => __('Cannot add %s because it would create a loop.', 'll-tools-text-domain'),
            'blockedHint' => __('Would create a prerequisite loop.', 'll-tools-text-domain'),
            'noMatches' => __('No matching categories.', 'll-tools-text-domain'),
            'levelCycle' => __('Cycle', 'll-tools-text-domain'),
            'levelUnknown' => __('—', 'll-tools-text-domain'),
        ],
        'transcribeI18n' => [
            'confirm'        => __('Transcribe missing recordings for this lesson?', 'll-tools-text-domain'),
            'confirmReplace' => __('Replace transcription for this lesson?', 'll-tools-text-domain'),
            'confirmClear'   => __('Clear transcription for this lesson?', 'll-tools-text-domain'),
            'working'        => __('Transcribing...', 'll-tools-text-domain'),
            'progress'       => __('Transcribing %1$d of %2$d...', 'll-tools-text-domain'),
            'done'           => __('Transcription complete.', 'll-tools-text-domain'),
            'none'           => __('No recordings need transcription.', 'll-tools-text-domain'),
            'clearing'       => __('Clearing transcription...', 'll-tools-text-domain'),
            'cleared'        => __('Transcription cleared.', 'll-tools-text-domain'),
            'cancelled'      => __('Transcription cancelled.', 'll-tools-text-domain'),
            'error'          => __('Unable to transcribe recordings.', 'll-tools-text-domain'),
            'localServiceError' => __('Unable to reach the local transcription service.', 'll-tools-text-domain'),
            'localAudioError' => __('Unable to fetch the recording audio in this browser.', 'll-tools-text-domain'),
        ],
        'transcribePollAttempts' => (int) apply_filters('ll_tools_word_grid_transcribe_poll_attempts', 20),
        'transcribePollIntervalMs' => (int) apply_filters('ll_tools_word_grid_transcribe_poll_interval_ms', 1200),
        'transcribeProvider' => (string) ($transcription_service['provider'] ?? ''),
        'transcribeTargetField' => (string) ($transcription_service['target_field'] ?? 'recording_text'),
        'transcribeLocalEndpoint' => (string) ($transcription_service['local_endpoint'] ?? ''),
        'transcribeUsesLocalBrowser' => !empty($transcription_service['uses_local_browser']),
        'ipaSpecialChars' => $ipa_special_chars,
        'ipaLetterMap' => $ipa_letter_map,
        'ipaTextLanguageCode' => $ipa_text_language_code,
    ];
}

function ll_tools_word_grid_enqueue_frontend_assets_for_context(array $context, array $overrides = []): array {
    $can_edit_words = !empty($context['can_edit_words']);
    if ($can_edit_words && function_exists('ll_tools_enqueue_jquery_ui_autocomplete_assets')) {
        ll_tools_enqueue_jquery_ui_autocomplete_assets();
    }

    $deps = ['jquery'];
    if ($can_edit_words) {
        $deps[] = 'jquery-ui-autocomplete';
        if (ll_tools_word_grid_is_lesson_context($context)) {
            $deps[] = 'jquery-ui-sortable';
        }
    }
    ll_enqueue_asset_by_timestamp('/js/word-grid.js', 'll-tools-word-grid', $deps, true);

    $config = array_replace_recursive(ll_tools_word_grid_build_base_frontend_config($context), $overrides);
    wp_localize_script('ll-tools-word-grid', 'llToolsWordGridData', $config);

    return $config;
}

function ll_tools_word_edit_modal_enqueue_assets(int $wordset_id = 0): void {
    $wordset_id = (int) $wordset_id;
    ll_enqueue_asset_by_timestamp('/css/ipa-fonts.css', 'll-ipa-fonts');
    ll_enqueue_asset_by_timestamp('/css/language-learner-tools.css', 'll-tools-style', ['ll-ipa-fonts']);

    $context = ll_tools_word_grid_resolve_context([
        'wordset' => $wordset_id > 0 ? (string) $wordset_id : '',
        'editor_context' => '1',
    ]);
    ll_tools_word_grid_enqueue_frontend_assets_for_context($context);
    ll_enqueue_asset_by_timestamp('/js/word-edit-modal.js', 'll-tools-word-edit-modal', ['jquery', 'll-tools-word-grid'], true);
    wp_localize_script('ll-tools-word-edit-modal', 'llToolsWordEditModalData', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('ll_word_edit_modal'),
        'i18n' => [
            'openError' => __('Unable to open the word editor.', 'll-tools-text-domain'),
            'renderError' => __('Unable to open the word editor.', 'll-tools-text-domain'),
            'missingHost' => __('Word editor modal is not available on this page.', 'll-tools-text-domain'),
        ],
    ]);
}

function ll_tools_word_edit_modal_host_html(int $wordset_id = 0): string {
    $wordset_id = (int) $wordset_id;
    $grid_attrs = 'class="word-grid ll-word-grid" data-ll-word-grid data-ll-word-edit-modal-grid="1"';
    if ($wordset_id > 0) {
        $grid_attrs .= ' data-ll-wordset-id="' . esc_attr((string) $wordset_id) . '"';
    }

    return '<div class="ll-word-edit-modal-host" data-ll-word-edit-modal-host aria-live="polite">'
        . '<div ' . $grid_attrs . '></div>'
        . '</div>';
}

function ll_tools_word_edit_modal_build_grid_context(int $word_id, int $wordset_id = 0, int $category_id = 0): array {
    $word_id = (int) $word_id;
    $wordset_id = (int) $wordset_id;
    $category_id = (int) $category_id;
    if ($word_id <= 0) {
        return [];
    }
    if ($wordset_id <= 0 && function_exists('ll_tools_word_grid_get_wordset_id_for_word')) {
        $wordset_id = (int) ll_tools_word_grid_get_wordset_id_for_word($word_id);
    }
    if ($wordset_id <= 0 || !has_term($wordset_id, 'wordset', $word_id)) {
        return [];
    }

    $atts = [
        'wordset' => (string) $wordset_id,
        'word_ids' => (string) $word_id,
        'editor_context' => '1',
    ];
    if ($category_id > 0) {
        $category = get_term($category_id, 'word-category');
        if ($category instanceof WP_Term && !is_wp_error($category)) {
            $atts['category'] = (string) $category->slug;
        }
    }

    return ll_tools_word_grid_resolve_context($atts);
}

add_action('wp_ajax_ll_tools_get_word_edit_modal_grid', 'll_tools_word_edit_modal_grid_handler');
function ll_tools_word_edit_modal_grid_handler() {
    check_ajax_referer('ll_word_edit_modal', 'nonce');

    $word_id = isset($_POST['word_id']) ? absint($_POST['word_id']) : 0;
    $wordset_id = isset($_POST['wordset_id']) ? absint($_POST['wordset_id']) : 0;
    $category_id = isset($_POST['category_id']) ? absint($_POST['category_id']) : 0;
    $word = $word_id > 0 ? get_post($word_id) : null;
    if (!($word instanceof WP_Post) || $word->post_type !== 'words') {
        wp_send_json_error(__('Invalid word.', 'll-tools-text-domain'), 400);
    }

    $context = ll_tools_word_edit_modal_build_grid_context($word_id, $wordset_id, $category_id);
    if (empty($context) || !empty($context['access_denied'])) {
        wp_send_json_error(__('Invalid word editor context.', 'll-tools-text-domain'), 400);
    }

    $resolved_wordset_id = (int) ($context['wordset_id'] ?? 0);
    if ($resolved_wordset_id <= 0 || empty($context['can_edit_words'])) {
        wp_send_json_error(__('Insufficient permissions to edit this word.', 'll-tools-text-domain'), 403);
    }
    if (function_exists('ll_tools_word_grid_user_can_manage_word') && !ll_tools_word_grid_user_can_manage_word($word_id, $resolved_wordset_id)) {
        wp_send_json_error(__('Insufficient permissions to edit this word.', 'll-tools-text-domain'), 403);
    }

    $html = ll_tools_word_grid_shortcode($context['atts']);
    if (!is_string($html) || trim($html) === '') {
        wp_send_json_error(__('Unable to open the word editor.', 'll-tools-text-domain'), 500);
    }

    wp_send_json_success([
        'html' => $html,
        'word_id' => $word_id,
        'wordset_id' => $resolved_wordset_id,
        'config' => ll_tools_word_grid_build_base_frontend_config($context),
    ]);
}

function ll_tools_word_grid_sanitize_thumbnail_html(string $html): string {
    $html = trim($html);
    if ($html === '') {
        return '';
    }

    if (class_exists('WP_HTML_Tag_Processor')) {
        $processor = new WP_HTML_Tag_Processor($html);
        if ($processor->next_tag(['tag_name' => 'IMG'])) {
            $width_attr = $processor->get_attribute('width');
            $height_attr = $processor->get_attribute('height');
            $has_bad_width = (null !== $width_attr && (int) $width_attr <= 1);
            $has_bad_height = (null !== $height_attr && (int) $height_attr <= 1);
            if ($has_bad_width || $has_bad_height) {
                $processor->remove_attribute('width');
                $processor->remove_attribute('height');
                return $processor->get_updated_html();
            }
        }

        return $html;
    }

    $width_match = [];
    $height_match = [];
    $has_width = preg_match('/<img\b[^>]*\bwidth=(["\']?)(\d+)\1/i', $html, $width_match) === 1;
    $has_height = preg_match('/<img\b[^>]*\bheight=(["\']?)(\d+)\1/i', $html, $height_match) === 1;
    $has_bad_width = $has_width && isset($width_match[2]) && (int) $width_match[2] <= 1;
    $has_bad_height = $has_height && isset($height_match[2]) && (int) $height_match[2] <= 1;
    if ($has_bad_width || $has_bad_height) {
        $html = preg_replace('/\swidth=(["\']?)[^"\'>\s]+\1/i', '', $html);
        $html = preg_replace('/\sheight=(["\']?)[^"\'>\s]+\1/i', '', $html);
    }

    return $html;
}

function ll_tools_word_grid_get_post_thumbnail_html(int $post_id, $size = 'post-thumbnail', array $attr = []): string {
    $thumbnail_html = function_exists('ll_tools_get_effective_word_image_html_for_word')
        ? ll_tools_get_effective_word_image_html_for_word($post_id, $size, $attr, true)
        : (
            function_exists('ll_tools_get_post_thumbnail_html_with_repair')
                ? ll_tools_get_post_thumbnail_html_with_repair($post_id, $size, $attr)
                : get_the_post_thumbnail($post_id, $size, $attr)
        );

    return ll_tools_word_grid_sanitize_thumbnail_html((string) $thumbnail_html);
}

function ll_tools_word_grid_decode_plain_text_entities($text): string {
    $decoded = trim(wp_strip_all_tags((string) $text));
    if ($decoded === '') {
        return '';
    }

    if (function_exists('ll_tools_decode_display_entities')) {
        $decoded = ll_tools_decode_display_entities($decoded);
    }

    return trim($decoded);
}

function ll_tools_word_grid_get_image_data_for_word(int $word_id): array {
    $fallback = function_exists('ll_tools_get_effective_word_image_data_for_word')
        ? ll_tools_get_effective_word_image_data_for_word($word_id, 'large', true)
        : [
            'word_image_id' => function_exists('ll_tools_get_linked_word_image_post_id_for_word')
                ? (int) ll_tools_get_linked_word_image_post_id_for_word($word_id)
                : 0,
            'attachment_id' => 0,
            'url'           => '',
            'alt'           => '',
            'width'         => 0,
            'height'        => 0,
        ];

    $display_values = ll_tools_word_grid_resolve_display_text($word_id);
    $alt = ll_tools_word_grid_decode_plain_text_entities((string) ($fallback['alt'] ?? ''));
    if ($alt === '') {
        $alt = ll_tools_word_grid_decode_plain_text_entities((string) ($display_values['word_text'] ?? ''));
    }
    if ($alt === '') {
        $alt = ll_tools_word_grid_decode_plain_text_entities((string) get_the_title($word_id));
    }

    $fallback['id'] = (int) ($fallback['attachment_id'] ?? 0);
    $fallback['alt'] = $alt;
    $word_image_id = (int) ($fallback['word_image_id'] ?? 0);
    $fallback['copyright_info'] = $word_image_id > 0
        ? (string) get_post_meta($word_image_id, 'copyright_info', true)
        : '';

    return $fallback;
}

function ll_tools_word_grid_get_image_data_for_word_image(int $word_image_id, $size = 'large'): array {
    $word_image_id = (int) $word_image_id;
    $fallback = [
        'id'            => 0,
        'word_image_id' => $word_image_id,
        'attachment_id' => 0,
        'url'           => '',
        'alt'           => '',
        'width'         => 0,
        'height'        => 0,
        'label'         => '',
        'source'        => 'word_image',
        'copyright_info' => '',
    ];

    if ($word_image_id <= 0 || get_post_type($word_image_id) !== 'word_images') {
        return $fallback;
    }

    $attachment_id = (int) get_post_thumbnail_id($word_image_id);
    $label = ll_tools_word_grid_decode_plain_text_entities((string) get_the_title($word_image_id));
    if ($label === '') {
        $label = sprintf(__('Word image #%d', 'll-tools-text-domain'), $word_image_id);
    }
    $fallback['label'] = $label;
    $fallback['copyright_info'] = (string) get_post_meta($word_image_id, 'copyright_info', true);

    if ($attachment_id <= 0) {
        return $fallback;
    }

    if (function_exists('ll_tools_maybe_regenerate_attachment_metadata')) {
        ll_tools_maybe_regenerate_attachment_metadata($attachment_id);
    }

    $url = function_exists('ll_tools_get_masked_image_url')
        ? (string) ll_tools_get_masked_image_url($attachment_id, $size)
        : '';
    if ($url === '') {
        $url = (string) (wp_get_attachment_image_url($attachment_id, $size) ?: '');
    }

    $size_data = wp_get_attachment_image_src($attachment_id, $size);
    $width = 0;
    $height = 0;
    if (is_array($size_data) && isset($size_data[1], $size_data[2])) {
        $width = (int) $size_data[1];
        $height = (int) $size_data[2];
    }

    $alt = ll_tools_word_grid_decode_plain_text_entities((string) get_post_meta($attachment_id, '_wp_attachment_image_alt', true));
    if ($alt === '') {
        $alt = $label;
    }

    return [
        'id'            => $attachment_id,
        'word_image_id' => $word_image_id,
        'attachment_id' => $attachment_id,
        'url'           => $url,
        'alt'           => $alt,
        'width'         => $width,
        'height'        => $height,
        'label'         => $label,
        'source'        => 'word_image',
        'copyright_info' => (string) get_post_meta($word_image_id, 'copyright_info', true),
    ];
}

function ll_tools_word_grid_word_image_is_selectable_for_word(int $word_image_id, int $word_id, int $wordset_id): bool {
    $word_image_id = (int) $word_image_id;
    $word_id = (int) $word_id;
    $wordset_id = (int) $wordset_id;

    if ($word_image_id <= 0 || $word_id <= 0) {
        return false;
    }

    $word_image_post = get_post($word_image_id);
    if (!$word_image_post || $word_image_post->post_type !== 'word_images' || $word_image_post->post_status === 'trash') {
        return false;
    }

    if ((int) get_post_thumbnail_id($word_image_id) <= 0) {
        return false;
    }

    $current_linked_id = function_exists('ll_tools_get_linked_word_image_post_id_for_word')
        ? (int) ll_tools_get_linked_word_image_post_id_for_word($word_id)
        : (int) get_post_meta($word_id, '_ll_autopicked_image_id', true);
    if ($current_linked_id === $word_image_id) {
        return true;
    }

    if ($wordset_id <= 0) {
        $wordset_id = ll_tools_word_grid_get_wordset_id_for_word($word_id);
    }

    $owner_wordset_id = function_exists('ll_tools_get_word_image_wordset_owner_id')
        ? (int) ll_tools_get_word_image_wordset_owner_id($word_image_id)
        : 0;
    if ($owner_wordset_id > 0) {
        return $wordset_id > 0 && $owner_wordset_id === $wordset_id;
    }

    $word_category_ids = wp_get_post_terms($word_id, 'word-category', ['fields' => 'ids']);
    if (is_wp_error($word_category_ids) || empty($word_category_ids)) {
        return false;
    }

    $image_category_ids = wp_get_post_terms($word_image_id, 'word-category', ['fields' => 'ids']);
    if (is_wp_error($image_category_ids) || empty($image_category_ids)) {
        return false;
    }

    $word_category_lookup = array_fill_keys(array_map('intval', (array) $word_category_ids), true);
    foreach ((array) $image_category_ids as $image_category_id) {
        if (isset($word_category_lookup[(int) $image_category_id])) {
            return true;
        }
    }

    return false;
}

function ll_tools_word_grid_search_word_image_ids_by_attached_word_text(string $query, int $limit, int $wordset_id, int $word_id): array {
    $query = trim($query);
    if ($query === '') {
        return [];
    }

    $limit = max(1, min(200, (int) $limit));
    $wordset_id = (int) $wordset_id;
    $word_id = (int) $word_id;

    $base_args = [
        'post_type'              => 'words',
        'post_status'            => ['publish', 'draft', 'pending', 'future', 'private'],
        'posts_per_page'         => min(300, max($limit * 4, $limit)),
        'fields'                 => 'ids',
        'no_found_rows'          => true,
        'orderby'                => 'title',
        'order'                  => 'ASC',
        'suppress_filters'       => true,
        'update_post_meta_cache' => true,
        'update_post_term_cache' => false,
    ];

    if ($wordset_id > 0) {
        $base_args['tax_query'] = [
            [
                'taxonomy' => 'wordset',
                'field'    => 'term_id',
                'terms'    => [$wordset_id],
            ],
        ];
    } elseif ($word_id > 0) {
        $word_category_ids = wp_get_post_terms($word_id, 'word-category', ['fields' => 'ids']);
        if (!is_wp_error($word_category_ids) && !empty($word_category_ids)) {
            $base_args['tax_query'] = [
                [
                    'taxonomy' => 'word-category',
                    'field'    => 'term_id',
                    'terms'    => array_map('intval', (array) $word_category_ids),
                ],
            ];
        }
    }

    $candidate_word_ids = [];

    $title_args = $base_args;
    $title_args['s'] = $query;
    $candidate_word_ids = array_merge($candidate_word_ids, array_map('intval', (array) get_posts($title_args)));

    $meta_args = $base_args;
    $meta_args['meta_query'] = [
        'relation' => 'OR',
        [
            'key'     => 'word_translation',
            'value'   => $query,
            'compare' => 'LIKE',
        ],
        [
            'key'     => 'word_english_meaning',
            'value'   => $query,
            'compare' => 'LIKE',
        ],
    ];
    $candidate_word_ids = array_merge($candidate_word_ids, array_map('intval', (array) get_posts($meta_args)));

    $image_ids = [];
    $seen_image_ids = [];
    $seen_word_ids = [];
    foreach ($candidate_word_ids as $candidate_word_id) {
        $candidate_word_id = (int) $candidate_word_id;
        if ($candidate_word_id <= 0 || isset($seen_word_ids[$candidate_word_id])) {
            continue;
        }
        $seen_word_ids[$candidate_word_id] = true;

        $linked_image_id = (int) get_post_meta($candidate_word_id, '_ll_autopicked_image_id', true);
        if ($linked_image_id <= 0 || isset($seen_image_ids[$linked_image_id])) {
            continue;
        }

        $seen_image_ids[$linked_image_id] = true;
        $image_ids[] = $linked_image_id;
        if (count($image_ids) >= $limit) {
            break;
        }
    }

    return $image_ids;
}

function ll_tools_word_grid_search_word_images_for_word(string $query, int $limit, int $wordset_id, int $word_id): array {
    $limit = max(1, min(50, (int) $limit));
    $word_id = (int) $word_id;
    $wordset_id = (int) $wordset_id;
    if ($word_id > 0 && get_post_type($word_id) === 'words') {
        $resolved_wordset_id = ll_tools_word_grid_get_wordset_id_for_word($word_id);
        if ($resolved_wordset_id > 0) {
            $wordset_id = $resolved_wordset_id;
        }
    }

    $args = [
        'post_type'              => 'word_images',
        'post_status'            => 'publish',
        'posts_per_page'         => min(200, max($limit * 5, $limit)),
        'orderby'                => 'title',
        'order'                  => 'ASC',
        'fields'                 => 'ids',
        'no_found_rows'          => true,
        'update_post_meta_cache' => true,
        'update_post_term_cache' => true,
    ];

    $query = trim($query);
    if ($query !== '') {
        $args['s'] = $query;
    }

    if ($wordset_id > 0 && function_exists('ll_tools_get_word_image_owner_meta_query')) {
        $owner_meta_query = ll_tools_get_word_image_owner_meta_query([$wordset_id], true);
        if (!empty($owner_meta_query)) {
            $args['meta_query'] = $owner_meta_query;
        }
    } elseif ($word_id > 0) {
        $word_category_ids = wp_get_post_terms($word_id, 'word-category', ['fields' => 'ids']);
        if (!is_wp_error($word_category_ids) && !empty($word_category_ids)) {
            $args['tax_query'] = [[
                'taxonomy' => 'word-category',
                'field'    => 'term_id',
                'terms'    => array_map('intval', (array) $word_category_ids),
            ]];
        }
    }

    $image_ids = get_posts($args);
    if ($query !== '') {
        $attached_word_image_ids = ll_tools_word_grid_search_word_image_ids_by_attached_word_text(
            $query,
            min(200, max($limit * 5, $limit)),
            $wordset_id,
            $word_id
        );
        if (!empty($attached_word_image_ids)) {
            $image_ids = array_merge((array) $image_ids, $attached_word_image_ids);
        }
    }

    $choices = [];
    $seen_image_ids = [];
    foreach ((array) $image_ids as $image_id) {
        $image_id = (int) $image_id;
        if ($image_id <= 0 || isset($seen_image_ids[$image_id])) {
            continue;
        }
        $seen_image_ids[$image_id] = true;
        if (!ll_tools_word_grid_word_image_is_selectable_for_word($image_id, $word_id, $wordset_id)) {
            continue;
        }

        $choices[] = ll_tools_word_grid_get_image_data_for_word_image($image_id, 'medium');
        if (count($choices) >= $limit) {
            break;
        }
    }

    if (count($choices) < $limit && $word_id > 0) {
        $word_category_ids = wp_get_post_terms($word_id, 'word-category', ['fields' => 'ids']);
        if (!is_wp_error($word_category_ids) && !empty($word_category_ids)) {
            $fallback_args = $args;
            unset($fallback_args['meta_query']);
            $fallback_args['posts_per_page'] = min(200, max(($limit - count($choices)) * 5, $limit));
            $fallback_args['post__not_in'] = array_map('intval', array_keys($seen_image_ids));
            $fallback_args['tax_query'] = [[
                'taxonomy' => 'word-category',
                'field'    => 'term_id',
                'terms'    => array_map('intval', (array) $word_category_ids),
            ]];

            $fallback_image_ids = get_posts($fallback_args);
            foreach ((array) $fallback_image_ids as $image_id) {
                $image_id = (int) $image_id;
                if ($image_id <= 0 || isset($seen_image_ids[$image_id])) {
                    continue;
                }
                $seen_image_ids[$image_id] = true;
                if (!ll_tools_word_grid_word_image_is_selectable_for_word($image_id, $word_id, $wordset_id)) {
                    continue;
                }

                $choices[] = ll_tools_word_grid_get_image_data_for_word_image($image_id, 'medium');
                if (count($choices) >= $limit) {
                    break;
                }
            }
        }
    }

    return $choices;
}

/**
 * Store a replacement image upload as a WordPress attachment.
 *
 * @param array  $file
 * @param string $attachment_title
 * @param string $alt_text
 * @return int|WP_Error
 */
function ll_tools_word_grid_store_uploaded_image_attachment(array $file, string $attachment_title = '', string $alt_text = '') {
    $tmp_name = isset($file['tmp_name']) ? (string) $file['tmp_name'] : '';
    $original_name = isset($file['name']) ? (string) $file['name'] : '';
    $file_error = isset($file['error']) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
    if ($tmp_name === '' || $file_error === UPLOAD_ERR_NO_FILE) {
        return new WP_Error('ll_word_grid_image_missing', __('Please choose an image to upload.', 'll-tools-text-domain'));
    }

    if (!function_exists('ll_image_upload_validate_uploaded_image')) {
        return new WP_Error('ll_word_grid_image_unavailable', __('Image uploads are not available right now.', 'll-tools-text-domain'));
    }

    $allowed_image_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $allowed_image_extensions = ['jpg', 'jpeg', 'jpe', 'png', 'gif', 'webp'];
    $require_uploaded_file = !defined('WP_TESTS_DOMAIN');
    $validation = ll_image_upload_validate_uploaded_image(
        $tmp_name,
        $original_name,
        $file_error,
        $allowed_image_types,
        $allowed_image_extensions,
        $require_uploaded_file,
        isset($file['size']) ? (int) $file['size'] : null
    );
    if (empty($validation['valid'])) {
        $message = isset($validation['error']) ? (string) $validation['error'] : '';
        if ($message === 'File upload error') {
            return new WP_Error('ll_word_grid_image_upload_failed', __('Image upload failed. Please try again.', 'll-tools-text-domain'));
        }
        return new WP_Error('ll_word_grid_image_invalid', __('Please upload a valid JPG, PNG, GIF, or WebP image.', 'll-tools-text-domain'));
    }

    if (!function_exists('wp_handle_sideload') && file_exists(ABSPATH . 'wp-admin/includes/file.php')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }

    if (!function_exists('wp_handle_sideload')) {
        return new WP_Error('ll_word_grid_image_upload_unavailable', __('Image uploads are not available right now.', 'll-tools-text-domain'));
    }

    $safe_name = isset($validation['safe_name']) ? (string) $validation['safe_name'] : '';
    if ($safe_name === '') {
        $safe_name = sanitize_file_name($original_name);
    }
    if ($safe_name === '') {
        $safe_name = 'image.png';
    }

    $file_array = [
        'name' => $safe_name,
        'type' => isset($validation['mime']) ? (string) $validation['mime'] : '',
        'tmp_name' => $tmp_name,
        'error' => $file_error,
        'size' => isset($file['size']) ? (int) $file['size'] : 0,
    ];

    $upload = wp_handle_sideload($file_array, ['test_form' => false]);
    if (!is_array($upload) || !empty($upload['error']) || empty($upload['file'])) {
        $message = is_array($upload) && !empty($upload['error'])
            ? sanitize_text_field((string) $upload['error'])
            : __('Image upload failed. Please try again.', 'll-tools-text-domain');
        return new WP_Error('ll_word_grid_image_upload_failed', $message);
    }

    $base_title = trim($attachment_title);
    if ($base_title === '') {
        $base_title = pathinfo($original_name, PATHINFO_FILENAME);
    }
    if ($base_title === '') {
        $base_title = __('Lesson image', 'll-tools-text-domain');
    }

    $attachment_id = wp_insert_attachment([
        'guid' => (string) ($upload['url'] ?? ''),
        'post_mime_type' => (string) ($upload['type'] ?? ($validation['mime'] ?? '')),
        'post_title' => $base_title,
        'post_content' => '',
        'post_status' => 'inherit',
    ], (string) $upload['file']);

    if (is_wp_error($attachment_id) || !$attachment_id) {
        return is_wp_error($attachment_id)
            ? $attachment_id
            : new WP_Error('ll_word_grid_image_attachment_failed', __('Could not save the uploaded image.', 'll-tools-text-domain'));
    }

    if ($alt_text !== '') {
        update_post_meta((int) $attachment_id, '_wp_attachment_image_alt', sanitize_text_field($alt_text));
    }

    $can_generate_metadata = file_exists(ABSPATH . 'wp-admin/includes/image.php')
        && file_exists(ABSPATH . 'wp-includes/class-wp-image-editor.php');
    if ($can_generate_metadata && !function_exists('wp_generate_attachment_metadata')) {
        require_once ABSPATH . 'wp-admin/includes/image.php';
    }

    if ($can_generate_metadata) {
        if (function_exists('ll_tools_maybe_regenerate_attachment_metadata')) {
            ll_tools_maybe_regenerate_attachment_metadata((int) $attachment_id);
        } elseif (function_exists('wp_generate_attachment_metadata')) {
            $metadata = wp_generate_attachment_metadata((int) $attachment_id, (string) $upload['file']);
            if (is_array($metadata) && !empty($metadata)) {
                wp_update_attachment_metadata((int) $attachment_id, $metadata);
            }
        }
    }

    return (int) $attachment_id;
}

function ll_tools_word_grid_normalize_css_aspect_ratio(string $ratio): string {
    $ratio = trim($ratio);
    if ($ratio === '') {
        return '';
    }

    if (!preg_match('/^(\d+)\s*([:\/])\s*(\d+)$/', $ratio, $matches)) {
        return '';
    }

    $width = (int) ($matches[1] ?? 0);
    $height = (int) ($matches[3] ?? 0);
    if ($width <= 0 || $height <= 0) {
        return '';
    }

    $divisor = 1;
    if (function_exists('ll_tools_aspect_gcd')) {
        $divisor = max(1, (int) ll_tools_aspect_gcd($width, $height));
    }

    $width = max(1, (int) round($width / $divisor));
    $height = max(1, (int) round($height / $divisor));

    return $width . ' / ' . $height;
}

function ll_tools_word_grid_estimate_shell_title_width(array $display_values): string {
    $word_text = trim((string) ($display_values['word_text'] ?? ''));
    $translation_text = trim((string) ($display_values['translation_text'] ?? ''));
    $char_count = 0;

    if ($word_text !== '') {
        $char_count += function_exists('mb_strlen')
            ? (int) mb_strlen($word_text, 'UTF-8')
            : strlen($word_text);
    }
    if ($translation_text !== '') {
        $char_count += 3;
        $char_count += function_exists('mb_strlen')
            ? (int) mb_strlen($translation_text, 'UTF-8')
            : strlen($translation_text);
    }

    if ($char_count <= 0) {
        return '68%';
    }

    $width = 28 + ($char_count * 2.15);
    $width = max(34.0, min(84.0, $width));

    return rtrim(rtrim(number_format($width, 2, '.', ''), '0'), '.') . '%';
}

function ll_tools_word_grid_get_visible_recording_types(array $audio_files, array $main_recording_types, array $recording_type_order): array {
    $types = [];
    foreach (ll_tools_word_grid_get_recording_display_entries($audio_files, $main_recording_types, $recording_type_order, false) as $recording_entry) {
        $type = isset($recording_entry['type']) ? (string) $recording_entry['type'] : '';
        if ($type !== '') {
            $types[$type] = $type;
        }
    }

    return array_values($types);
}

function ll_tools_word_grid_get_visible_recording_shell_entries(array $audio_files, array $main_recording_types, array $recording_type_order): array {
    $entries = [];
    foreach (ll_tools_word_grid_get_recording_display_entries($audio_files, $main_recording_types, $recording_type_order, false) as $recording_entry) {
        $entry = isset($recording_entry['entry']) && is_array($recording_entry['entry'])
            ? (array) $recording_entry['entry']
            : [];
        $type = isset($recording_entry['type']) ? (string) $recording_entry['type'] : '';
        $audio_url = isset($entry['url']) ? (string) $entry['url'] : '';
        if ($type === '' || $audio_url === '') {
            continue;
        }
        $entries[] = [
            'type' => $type,
            'url' => $audio_url,
            'id' => isset($entry['id']) ? (int) $entry['id'] : 0,
        ];
    }

    return $entries;
}

function ll_tools_word_grid_count_visible_recording_buttons(array $audio_files, array $main_recording_types, array $recording_type_order): int {
    return count(ll_tools_word_grid_get_visible_recording_types($audio_files, $main_recording_types, $recording_type_order));
}

function ll_tools_word_grid_get_shell_image_preview_data(int $attachment_id): array {
    if ($attachment_id <= 0) {
        return [
            'url' => '',
            'width' => 0,
            'height' => 0,
        ];
    }

    $preview_size = apply_filters('ll_tools_word_grid_shell_preview_image_size', 'thumbnail', $attachment_id);
    if (!is_array($preview_size) && !is_string($preview_size)) {
        $preview_size = 'thumbnail';
    }
    if (is_string($preview_size)) {
        $preview_size = trim($preview_size);
        if ($preview_size === '') {
            $preview_size = 'thumbnail';
        }
    }

    $url = function_exists('ll_tools_get_masked_image_url')
        ? (string) ll_tools_get_masked_image_url($attachment_id, $preview_size)
        : '';
    if ($url === '') {
        $url = (string) (wp_get_attachment_image_url($attachment_id, $preview_size) ?: '');
    }

    if ($url === '') {
        return [
            'url' => '',
            'width' => 0,
            'height' => 0,
        ];
    }

    $width = 0;
    $height = 0;
    $size_data = wp_get_attachment_image_src($attachment_id, $preview_size);
    if (is_array($size_data) && isset($size_data[1], $size_data[2])) {
        $width = (int) $size_data[1];
        $height = (int) $size_data[2];
    }

    return [
        'url' => $url,
        'width' => $width,
        'height' => $height,
    ];
}

function ll_tools_word_grid_get_default_shell_cards(array $context, int $limit = 6): array {
    $limit = max(1, (int) $limit);
    $default_ratio = ll_tools_word_grid_get_shell_media_aspect_ratio($context);
    $cards = [];

    for ($i = 0; $i < $limit; $i++) {
        $cards[] = [
            'media_aspect_ratio' => $default_ratio,
            'title_width' => '68%',
            'recording_count' => 3,
        ];
    }

    return $cards;
}

function ll_tools_word_grid_get_shell_ordered_word_ids(array $context): array {
    static $request_cache = [];

    $category_term = $context['category_term'] ?? null;
    $category_id = ($category_term instanceof WP_Term && !is_wp_error($category_term))
        ? (int) $category_term->term_id
        : 0;
    $wordset_id = isset($context['wordset_id']) ? (int) $context['wordset_id'] : 0;
    $lesson_id = isset($context['lesson_id']) ? (int) $context['lesson_id'] : 0;
    $deepest_only = !empty($context['deepest_only']);
    $is_text_based = !empty($context['is_text_based']);
    $requires_audio = !empty($context['category_quiz_requires_audio']) && ll_tools_word_grid_is_lesson_context($context);

    $cache_key = md5((string) wp_json_encode([
        'category_id' => $category_id,
        'wordset_id' => $wordset_id,
        'lesson_id' => $lesson_id,
        'deepest_only' => $deepest_only,
        'is_text_based' => $is_text_based,
        'requires_audio' => $requires_audio,
        'hide_lesson_grid_text' => !empty($context['hide_lesson_grid_text']),
    ]));
    if (isset($request_cache[$cache_key])) {
        return $request_cache[$cache_key];
    }

    if ($category_id <= 0 || $wordset_id <= 0 || !($category_term instanceof WP_Term) || is_wp_error($category_term)) {
        $request_cache[$cache_key] = [];
        return [];
    }

    $posts = get_posts([
        'post_type' => 'words',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'no_found_rows' => true,
        'orderby' => 'date',
        'order' => 'ASC',
        'suppress_filters' => true,
        'tax_query' => [
            'relation' => 'AND',
            [
                'taxonomy' => 'word-category',
                'field' => 'term_id',
                'terms' => [$category_id],
            ],
            [
                'taxonomy' => 'wordset',
                'field' => 'term_id',
                'terms' => [$wordset_id],
            ],
        ],
    ]);
    $posts = array_values(array_filter((array) $posts, static function ($post_obj): bool {
        return $post_obj instanceof WP_Post && !empty($post_obj->ID);
    }));

    if (empty($posts)) {
        $request_cache[$cache_key] = [];
        return [];
    }

    if ($deepest_only) {
        $posts = ll_tools_word_grid_filter_posts_to_deepest_category($posts, $category_id);
    }

    if (!empty($posts) && function_exists('ll_tools_filter_specific_wrong_answer_only_word_ids')) {
        $visible_word_ids = ll_tools_filter_specific_wrong_answer_only_word_ids(array_map(static function ($post_obj): int {
            return isset($post_obj->ID) ? (int) $post_obj->ID : 0;
        }, $posts));
        $visible_lookup = array_fill_keys($visible_word_ids, true);
        if (count($visible_lookup) !== count($posts)) {
            $posts = array_values(array_filter($posts, static function ($post_obj) use ($visible_lookup): bool {
                $post_id = isset($post_obj->ID) ? (int) $post_obj->ID : 0;
                return $post_id > 0 && isset($visible_lookup[$post_id]);
            }));
        }
    }

    if (!$is_text_based && !empty($posts) && function_exists('ll_tools_filter_word_ids_with_effective_images')) {
        $image_word_ids = array_values(array_filter(array_map(static function ($post_obj): int {
            return isset($post_obj->ID) ? (int) $post_obj->ID : 0;
        }, $posts), static function (int $post_id): bool {
            return $post_id > 0;
        }));
        $visible_word_ids = ll_tools_filter_word_ids_with_effective_images($image_word_ids, true);
        $visible_lookup = array_fill_keys($visible_word_ids, true);
        if (count($visible_lookup) !== count($posts)) {
            $posts = array_values(array_filter($posts, static function ($post_obj) use ($visible_lookup): bool {
                $post_id = isset($post_obj->ID) ? (int) $post_obj->ID : 0;
                return $post_id > 0 && isset($visible_lookup[$post_id]);
            }));
        }
    }

    if ($requires_audio && !empty($posts)) {
        $audio_word_ids = array_values(array_filter(array_map(static function ($post_obj): int {
            return isset($post_obj->ID) ? (int) $post_obj->ID : 0;
        }, $posts), static function (int $post_id): bool {
            return $post_id > 0;
        }));
        $audio_by_word = ll_tools_word_grid_collect_audio_files($audio_word_ids, false);
        if (count($audio_by_word) !== count($audio_word_ids)) {
            $posts = array_values(array_filter($posts, static function ($post_obj) use ($audio_by_word): bool {
                $post_id = isset($post_obj->ID) ? (int) $post_obj->ID : 0;
                return $post_id > 0 && !empty($audio_by_word[$post_id]);
            }));
        }
    }

    if (empty($posts)) {
        $request_cache[$cache_key] = [];
        return [];
    }

    $manual_order_applied = false;
    if ($lesson_id > 0 && function_exists('ll_tools_get_vocab_lesson_manual_word_order')) {
        $allowed_word_ids = array_values(array_filter(array_map(static function ($post_obj): int {
            return isset($post_obj->ID) ? (int) $post_obj->ID : 0;
        }, $posts), static function (int $word_id): bool {
            return $word_id > 0;
        }));
        $manual_word_order = ll_tools_get_vocab_lesson_manual_word_order($lesson_id, $allowed_word_ids);
        if (!empty($manual_word_order) && function_exists('ll_tools_reorder_posts_by_word_id_order')) {
            $posts = ll_tools_reorder_posts_by_word_id_order($posts, $manual_word_order);
            $manual_order_applied = true;
        }
    }

    if (
        !$manual_order_applied
        && function_exists('ll_tools_get_word_option_maps')
        && function_exists('ll_tools_word_grid_reorder_by_option_groups')
    ) {
        $maps = ll_tools_get_word_option_maps($wordset_id, $category_id);
        $groups = isset($maps['groups']) && is_array($maps['groups']) ? $maps['groups'] : [];
        if (!empty($groups)) {
            $posts = ll_tools_word_grid_reorder_by_option_groups($posts, $groups);
        }
    }

    $word_ids = array_values(array_filter(array_map(static function ($post_obj): int {
        return isset($post_obj->ID) ? (int) $post_obj->ID : 0;
    }, $posts), static function (int $word_id): bool {
        return $word_id > 0;
    }));
    if (!empty($word_ids)) {
        update_meta_cache('post', $word_ids);
    }

    $display_values_cache = [];
    if (!$manual_order_applied && function_exists('ll_tools_word_grid_group_same_name_or_image')) {
        $posts = ll_tools_word_grid_group_same_name_or_image($posts, $display_values_cache);
    }
    if (!$manual_order_applied && ll_tools_word_grid_should_sort_visible_titles($context)) {
        $posts = ll_tools_word_grid_sort_posts_by_display_title($posts, $display_values_cache);
    }

    $ordered_word_ids = array_values(array_filter(array_map(static function ($post_obj): int {
        return isset($post_obj->ID) ? (int) $post_obj->ID : 0;
    }, $posts), static function (int $word_id): bool {
        return $word_id > 0;
    }));

    $request_cache[$cache_key] = $ordered_word_ids;
    return $ordered_word_ids;
}

function ll_tools_word_grid_get_shell_cards(array $context, int $limit = 6): array {
    static $request_cache = [];

    $preview_limit = max(1, (int) $limit);
    $category_term = $context['category_term'] ?? null;
    $category_id = ($category_term instanceof WP_Term && !is_wp_error($category_term))
        ? (int) $category_term->term_id
        : 0;
    $wordset_id = isset($context['wordset_id']) ? (int) $context['wordset_id'] : 0;
    $lesson_id = isset($context['lesson_id']) ? (int) $context['lesson_id'] : 0;
    $deepest_only = !empty($context['deepest_only']);
    $is_text_based = !empty($context['is_text_based']);
    $manual_order_hash = '';
    if ($lesson_id > 0 && function_exists('ll_tools_get_vocab_lesson_manual_word_order')) {
        $manual_order_hash = md5((string) wp_json_encode(ll_tools_get_vocab_lesson_manual_word_order($lesson_id)));
    }

    $cache_key = $category_id . ':' . $wordset_id . ':' . $lesson_id . ':' . ($deepest_only ? '1' : '0') . ':' . ($is_text_based ? '1' : '0') . ':' . $preview_limit . ':' . $manual_order_hash;
    if (isset($request_cache[$cache_key])) {
        return $request_cache[$cache_key];
    }

    $persistent_cache_key = 'll_wg_shell_cards_' . md5(wp_json_encode([
        'schema' => 3,
        'category_id' => $category_id,
        'wordset_id' => $wordset_id,
        'lesson_id' => $lesson_id,
        'deepest_only' => $deepest_only,
        'is_text_based' => $is_text_based,
        'preview_limit' => $preview_limit,
        'hide_lesson_grid_text' => !empty($context['hide_lesson_grid_text']),
        'category_quiz_requires_audio' => !empty($context['category_quiz_requires_audio']),
        'manual_order_hash' => $manual_order_hash,
        'locale' => function_exists('determine_locale') ? (string) determine_locale() : (function_exists('get_locale') ? (string) get_locale() : ''),
        'plugin_version' => defined('LL_TOOLS_VERSION') ? (string) LL_TOOLS_VERSION : '',
        'category_epoch' => function_exists('ll_tools_get_category_cache_epoch') ? max(1, (int) ll_tools_get_category_cache_epoch()) : 1,
        'wordset_epoch' => function_exists('ll_tools_get_wordset_cache_epoch') ? max(1, (int) ll_tools_get_wordset_cache_epoch()) : 1,
    ]));
    $cached_cards = wp_cache_get($persistent_cache_key, 'll_tools');
    if (!is_array($cached_cards)) {
        $cached_cards = get_transient($persistent_cache_key);
    }
    if (is_array($cached_cards) && !empty($cached_cards)) {
        $request_cache[$cache_key] = $cached_cards;
        return $cached_cards;
    }

    if ($category_id <= 0 || $wordset_id <= 0) {
        $request_cache[$cache_key] = ll_tools_word_grid_get_default_shell_cards($context, $preview_limit);
        return $request_cache[$cache_key];
    }

    $word_ids = ll_tools_word_grid_get_shell_ordered_word_ids($context);
    $max_shell_cards = (int) apply_filters('ll_tools_word_grid_shell_max_cards', 0, $context, $preview_limit, count($word_ids));
    if ($max_shell_cards > 0 && count($word_ids) > $max_shell_cards) {
        $word_ids = array_slice($word_ids, 0, $max_shell_cards);
    }

    if (empty($word_ids)) {
        $request_cache[$cache_key] = ll_tools_word_grid_get_default_shell_cards($context, $preview_limit);
        return $request_cache[$cache_key];
    }

    update_meta_cache('post', $word_ids);

    $display_values_cache = [];
    foreach ($word_ids as $word_id) {
        $display_values_cache[$word_id] = ll_tools_word_grid_resolve_display_text((int) $word_id);
    }

    $audio_by_word = ll_tools_word_grid_collect_audio_files($word_ids, false);
    $main_recording_types = function_exists('ll_tools_get_main_recording_types')
        ? ll_tools_get_main_recording_types()
        : ['isolation', 'question', 'introduction'];
    $recording_type_order = ll_tools_word_grid_get_lesson_recording_type_order();
    $recording_labels = [
        'question' => __('Question', 'll-tools-text-domain'),
        'isolation' => __('Isolation', 'll-tools-text-domain'),
        'introduction' => __('Introduction', 'll-tools-text-domain'),
        'sentence' => __('Sentence', 'll-tools-text-domain'),
        'in-sentence' => __('In sentence', 'll-tools-text-domain'),
    ];
    $play_label_template = __('Play %s recording', 'll-tools-text-domain');
    $default_ratio = ll_tools_word_grid_get_shell_media_aspect_ratio($context);

    $word_grid_image_size = apply_filters('ll_tools_word_grid_image_size', 'medium_large', $wordset_id, $category_term);
    if (!is_string($word_grid_image_size)) {
        $word_grid_image_size = 'medium_large';
    }
    $word_grid_image_size = trim($word_grid_image_size);
    if ($word_grid_image_size === '') {
        $word_grid_image_size = 'medium_large';
    }

    $cards = [];
    foreach ($word_ids as $index => $word_id) {
        $display_values = $display_values_cache[$word_id] ?? ll_tools_word_grid_resolve_display_text((int) $word_id);
        $media_aspect_ratio = $default_ratio;
        $image_preview_data = [
            'url' => '',
            'width' => 0,
            'height' => 0,
        ];
        $include_image_preview = !$is_text_based && $index < $preview_limit;

        if ($include_image_preview) {
            $attachment_id = function_exists('ll_tools_get_effective_word_image_attachment_id_for_word')
                ? (int) ll_tools_get_effective_word_image_attachment_id_for_word((int) $word_id, true)
                : (int) get_post_thumbnail_id((int) $word_id);
            if ($attachment_id > 0 && function_exists('ll_tools_get_image_aspect_ratio_for_size')) {
                $media_aspect_ratio = ll_tools_word_grid_normalize_css_aspect_ratio(
                    (string) ll_tools_get_image_aspect_ratio_for_size($attachment_id, $word_grid_image_size)
                );
            }
            if ($media_aspect_ratio === '' && $attachment_id > 0 && function_exists('ll_tools_get_attachment_aspect_data')) {
                $aspect = ll_tools_get_attachment_aspect_data($attachment_id);
                $media_aspect_ratio = ll_tools_word_grid_normalize_css_aspect_ratio((string) ($aspect['ratio_key'] ?? ''));
            }
            if ($media_aspect_ratio === '') {
                $media_aspect_ratio = $default_ratio;
            }
            $image_preview_data = ll_tools_word_grid_get_shell_image_preview_data($attachment_id);
        }

        $recordings = ll_tools_word_grid_get_visible_recording_shell_entries(
            (array) ($audio_by_word[$word_id] ?? []),
            $main_recording_types,
            $recording_type_order
        );
        foreach ($recordings as &$recording) {
            $recording_type = (string) ($recording['type'] ?? '');
            $recording_label = $recording_labels[$recording_type] ?? ucwords(str_replace(['-', '_'], ' ', $recording_type));
            $recording['label'] = $recording_label;
            $recording['play_label'] = sprintf($play_label_template, $recording_label);
        }
        unset($recording);
        $recording_types = array_values(array_filter(array_map(static function (array $recording): string {
            return isset($recording['type']) ? (string) $recording['type'] : '';
        }, $recordings)));

        $cards[] = [
            'word_id' => (int) $word_id,
            'word_text' => (string) ($display_values['word_text'] ?? ''),
            'translation_text' => (string) ($display_values['translation_text'] ?? ''),
            'media_aspect_ratio' => $media_aspect_ratio,
            'title_width' => ll_tools_word_grid_estimate_shell_title_width($display_values),
            'recording_count' => count($recording_types),
            'recording_types' => $recording_types,
            'recordings' => $recordings,
            'image_preview_url' => (string) ($image_preview_data['url'] ?? ''),
            'image_preview_width' => (int) ($image_preview_data['width'] ?? 0),
            'image_preview_height' => (int) ($image_preview_data['height'] ?? 0),
        ];
    }

    if (empty($cards)) {
        $cards = ll_tools_word_grid_get_default_shell_cards($context, $preview_limit);
    }

    wp_cache_set($persistent_cache_key, $cards, 'll_tools', 10 * MINUTE_IN_SECONDS);
    set_transient($persistent_cache_key, $cards, 10 * MINUTE_IN_SECONDS);
    $request_cache[$cache_key] = $cards;
    return $cards;
}

function ll_tools_word_grid_get_shell_media_aspect_ratio(array $context): string {
    $default_ratio = '1 / 1';
    $category_term = $context['category_term'] ?? null;
    $category_id = ($category_term instanceof WP_Term && !is_wp_error($category_term))
        ? (int) $category_term->term_id
        : 0;

    if ($category_id > 0 && defined('LL_TOOLS_CATEGORY_CANONICAL_ASPECT_META_KEY')) {
        $stored_ratio = (string) get_term_meta($category_id, LL_TOOLS_CATEGORY_CANONICAL_ASPECT_META_KEY, true);
        $normalized_ratio = ll_tools_word_grid_normalize_css_aspect_ratio($stored_ratio);
        if ($normalized_ratio !== '') {
            return $normalized_ratio;
        }
    }

    return $default_ratio;
}

function ll_tools_word_grid_get_shell_spec(array $context): array {
    $grid_classes = 'word-grid ll-word-grid';
    if (!empty($context['is_text_based'])) {
        $grid_classes .= ' ll-word-grid--text';
    }
    if (!empty($context['show_staff_inactive_images'])) {
        $grid_classes .= ' ll-word-grid--staff-inactive-images';
    }
    if (!empty($context['hide_lesson_grid_text'])) {
        $grid_classes .= ' ll-word-grid--hide-text';
    }

    $wordset_id = isset($context['wordset_id']) ? (int) $context['wordset_id'] : 0;
    $category_term = $context['category_term'] ?? null;

    $word_grid_style_parts = [];
    if ($wordset_id > 0 && function_exists('ll_tools_wordset_get_answer_option_text_style_config')) {
        $answer_option_style = ll_tools_wordset_get_answer_option_text_style_config($wordset_id);
        $answer_option_font_family = isset($answer_option_style['fontFamily']) ? trim((string) $answer_option_style['fontFamily']) : '';
        $answer_option_font_weight = function_exists('ll_tools_wordset_normalize_answer_option_font_weight')
            ? ll_tools_wordset_normalize_answer_option_font_weight((string) ($answer_option_style['fontWeight'] ?? '700'))
            : trim((string) ($answer_option_style['fontWeight'] ?? '700'));
        if ($answer_option_font_weight !== '') {
            $word_grid_style_parts[] = '--ll-word-grid-answer-option-font-weight:' . $answer_option_font_weight;
        }
        if ($answer_option_font_family !== '') {
            $grid_classes .= ' ll-word-grid--answer-option-font-custom';
            $word_grid_style_parts[] = '--ll-word-grid-answer-option-font-family:' . $answer_option_font_family;
        }
        $line_height = isset($answer_option_style['lineHeight']) ? (float) $answer_option_style['lineHeight'] : 0.0;
        if ($line_height > 0) {
            $word_grid_style_parts[] = '--ll-word-grid-answer-option-line-height:' . rtrim(rtrim(number_format($line_height, 2, '.', ''), '0'), '.');
        }
    }

    $shell_cards = !empty($context['skip_shell_cards'])
        ? []
        : ll_tools_word_grid_get_shell_cards($context);
    $shell_media_aspect_ratio = ll_tools_word_grid_get_shell_media_aspect_ratio($context);
    foreach ($shell_cards as $shell_card) {
        $card_ratio = trim((string) ($shell_card['media_aspect_ratio'] ?? ''));
        if ($card_ratio !== '') {
            $shell_media_aspect_ratio = $card_ratio;
            break;
        }
    }

    $word_grid_style_parts[] = '--ll-word-grid-shell-image-aspect:' . $shell_media_aspect_ratio;

    $attributes = [
        'id' => 'word-grid',
        'class' => $grid_classes,
        'data-ll-word-grid' => '',
    ];
    if ($wordset_id > 0) {
        $attributes['data-ll-wordset-id'] = (string) $wordset_id;
    }
    if ($category_term instanceof WP_Term && !is_wp_error($category_term)) {
        $attributes['data-ll-category-id'] = (string) ((int) $category_term->term_id);
    }
    if (!empty($context['wordset_has_gender'])) {
        $attributes['data-ll-gender-enabled'] = '1';
    }
    if (!empty($context['wordset_has_plurality'])) {
        $attributes['data-ll-plurality-enabled'] = '1';
    }
    if (!empty($context['wordset_has_verb_tense'])) {
        $attributes['data-ll-verb-tense-enabled'] = '1';
    }
    if (!empty($context['wordset_has_verb_mood'])) {
        $attributes['data-ll-verb-mood-enabled'] = '1';
    }
    if (!empty($word_grid_style_parts)) {
        $attributes['style'] = implode(';', $word_grid_style_parts) . ';';
    }

    return [
        'class' => $grid_classes,
        'attributes' => $attributes,
        'cards' => $shell_cards,
        'show_media' => empty($context['is_text_based']) || !empty($context['show_staff_inactive_images']),
        'show_title' => empty($context['hide_lesson_grid_text']),
    ];
}

/**
 * The callback function for the 'word_grid' shortcode.
 *
 * @param array $atts Shortcode attributes.
 * @return string HTML content to display the grid.
 */
function ll_tools_word_grid_shortcode($atts) {
    $context = ll_tools_word_grid_resolve_context($atts);
    if (!empty($context['access_denied'])) {
        return '';
    }
    $atts = $context['atts'];
    $sanitized_category = $context['sanitized_category'];
    $sanitized_wordset = $context['sanitized_wordset'];
    $deepest_only = !empty($context['deepest_only']);
    $specific_word_ids = array_values(array_filter(array_map('intval', (array) ($context['specific_word_ids'] ?? [])), static function (int $word_id): bool {
        return $word_id > 0;
    }));
    $category_term = $context['category_term'];
    $wordset_term = $context['wordset_term'];
    $wordset_id = (int) ($context['wordset_id'] ?? 0);
    $is_text_based = !empty($context['is_text_based']);
    $has_text_only_answer_options = !empty($context['has_text_only_answer_options']);
    $hide_lesson_grid_text = !empty($context['hide_lesson_grid_text']);
    $show_staff_inactive_images = !empty($context['show_staff_inactive_images']);
    $wordset_has_gender = !empty($context['wordset_has_gender']);
    $wordset_has_plurality = !empty($context['wordset_has_plurality']);
    $wordset_has_verb_tense = !empty($context['wordset_has_verb_tense']);
    $wordset_has_verb_mood = !empty($context['wordset_has_verb_mood']);
    $lesson_id = isset($context['lesson_id']) ? (int) $context['lesson_id'] : 0;
    $can_edit_words = !empty($context['can_edit_words']);
    $user_study_state = is_array($context['user_study_state'] ?? null)
        ? $context['user_study_state']
        : [
            'wordset_id'       => 0,
            'category_ids'     => [],
            'starred_word_ids' => [],
            'star_mode'        => 'normal',
            'fast_transitions' => false,
        ];
    $sort_visible_titles = ll_tools_word_grid_should_sort_visible_titles($context);
    $show_draft_words = ll_tools_word_grid_should_show_draft_words($context);
    $show_editor_statuses = !empty($context['editor_context']) && $can_edit_words;
    $show_staff_hidden_words = ll_tools_word_grid_should_show_staff_hidden_words($context);
    $presentation_hidden_word_lookup = [];
    $presentation_hidden_word_reasons = [];

    ll_tools_word_grid_enqueue_frontend_assets_for_context($context);

    $part_of_speech_terms = [];
    if ($can_edit_words) {
        $part_of_speech_terms = get_terms([
            'taxonomy' => 'part_of_speech',
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC',
        ]);
        if (is_wp_error($part_of_speech_terms)) {
            $part_of_speech_terms = [];
        }
    }
    $gender_options = [];
    if ($wordset_has_gender && function_exists('ll_tools_wordset_get_gender_options')) {
        $gender_options = ll_tools_wordset_get_gender_options($wordset_id);
    }
    $plurality_options = [];
    if ($wordset_has_plurality && function_exists('ll_tools_wordset_get_plurality_options')) {
        $plurality_options = ll_tools_wordset_get_plurality_options($wordset_id);
    }
    $verb_tense_options = [];
    if ($wordset_has_verb_tense && function_exists('ll_tools_wordset_get_verb_tense_options')) {
        $verb_tense_options = ll_tools_wordset_get_verb_tense_options($wordset_id);
    }
    $verb_mood_options = [];
    if ($wordset_has_verb_mood && function_exists('ll_tools_wordset_get_verb_mood_options')) {
        $verb_mood_options = ll_tools_wordset_get_verb_mood_options($wordset_id);
    }

    // Start output buffering
    ob_start();

    // WP_Query arguments
    $args = array(
        'post_type' => 'words',
        'post_status' => $show_editor_statuses ? ['publish', 'draft', 'pending', 'future', 'private'] : ($show_draft_words ? ['publish', 'draft'] : 'publish'),
        'posts_per_page' => -1,
        'no_found_rows' => true,
        'orderby' => 'date', // Order by date
        'order' => 'ASC', // Ascending order
    );
    if (!empty($specific_word_ids)) {
        $args['post__in'] = $specific_word_ids;
        $args['orderby'] = 'post__in';
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
    if (empty($specific_word_ids) && $deepest_only && $category_term) {
        $query->posts = ll_tools_word_grid_filter_posts_to_deepest_category((array) $query->posts, (int) $category_term->term_id);
        $query->post_count = count((array) $query->posts);
        $query->current_post = -1;
    }

    // Words reserved as specific wrong answers should not appear in lesson grids.
    if (empty($context['editor_context']) && empty($specific_word_ids) && !empty($query->posts) && function_exists('ll_tools_filter_specific_wrong_answer_only_word_ids')) {
        $visible_word_ids = ll_tools_filter_specific_wrong_answer_only_word_ids(array_map(static function ($post_obj): int {
            return isset($post_obj->ID) ? (int) $post_obj->ID : 0;
        }, (array) $query->posts));
        $visible_lookup = array_fill_keys($visible_word_ids, true);
        if (count($visible_lookup) !== count((array) $query->posts)) {
            $visible_posts = array_values(array_filter((array) $query->posts, static function ($post_obj) use ($visible_lookup): bool {
                $post_id = isset($post_obj->ID) ? (int) $post_obj->ID : 0;
                return $post_id > 0 && isset($visible_lookup[$post_id]);
            }));
            $query->posts = $visible_posts;
            $query->post_count = count($visible_posts);
            $query->current_post = -1;
        }
    }
    if (empty($context['editor_context']) && !$is_text_based && !empty($query->posts) && function_exists('ll_tools_filter_word_ids_with_effective_images')) {
        $image_filter_word_ids = array_values(array_filter(array_map(static function ($post_obj): int {
            return isset($post_obj->ID) ? (int) $post_obj->ID : 0;
        }, (array) $query->posts), static function (int $post_id): bool {
            return $post_id > 0;
        }));
        $visible_word_ids = ll_tools_filter_word_ids_with_effective_images($image_filter_word_ids, true);
        $visible_lookup = array_fill_keys($visible_word_ids, true);
        if (count($visible_lookup) !== count((array) $query->posts)) {
            if ($show_staff_hidden_words && empty($specific_word_ids)) {
                foreach ($image_filter_word_ids as $post_id) {
                    if (!isset($visible_lookup[$post_id]) && get_post_status($post_id) === 'publish') {
                        $presentation_hidden_word_lookup[$post_id] = true;
                        $presentation_hidden_word_reasons[$post_id] = 'missing_image';
                    }
                }
            } else {
                $visible_posts = array_values(array_filter((array) $query->posts, static function ($post_obj) use ($visible_lookup): bool {
                    $post_id = isset($post_obj->ID) ? (int) $post_obj->ID : 0;
                    return $post_id > 0 && isset($visible_lookup[$post_id]);
                }));
                $query->posts = $visible_posts;
                $query->post_count = count($visible_posts);
                $query->current_post = -1;
            }
        }
    }
    if (
        empty($context['editor_context'])
        && !empty($context['category_quiz_requires_audio'])
        && ll_tools_word_grid_is_lesson_context($context)
        && !empty($query->posts)
    ) {
        $audio_filter_word_ids = array_values(array_filter(array_map(static function ($post_obj): int {
            return isset($post_obj->ID) ? (int) $post_obj->ID : 0;
        }, (array) $query->posts), static function (int $post_id): bool {
            return $post_id > 0;
        }));
        $audio_visible_lookup = [];
        foreach ($audio_filter_word_ids as $post_id) {
            $has_published_audio = function_exists('ll_tools_word_has_published_audio')
                ? ll_tools_word_has_published_audio($post_id)
                : !empty(ll_tools_word_grid_collect_audio_files([$post_id], false));
            if ($has_published_audio) {
                $audio_visible_lookup[$post_id] = true;
            }
        }
        if (count($audio_visible_lookup) !== count($audio_filter_word_ids)) {
            if ($show_staff_hidden_words && empty($specific_word_ids)) {
                foreach ($audio_filter_word_ids as $post_id) {
                    if (!isset($audio_visible_lookup[$post_id]) && get_post_status($post_id) === 'publish') {
                        $presentation_hidden_word_lookup[$post_id] = true;
                        $presentation_hidden_word_reasons[$post_id] = 'missing_audio';
                    }
                }
            } else {
                $visible_posts = array_values(array_filter((array) $query->posts, static function ($post_obj) use ($audio_visible_lookup, $show_staff_hidden_words): bool {
                    $post_id = isset($post_obj->ID) ? (int) $post_obj->ID : 0;
                    if ($post_id <= 0) {
                        return false;
                    }
                    if (isset($audio_visible_lookup[$post_id])) {
                        return true;
                    }

                    return $show_staff_hidden_words && get_post_status($post_id) !== 'publish';
                }));
                $query->posts = $visible_posts;
                $query->post_count = count($visible_posts);
                $query->current_post = -1;
            }
        }
    }

    $manual_order_applied = false;
    if ($lesson_id > 0 && empty($specific_word_ids)) {
        $allowed_word_ids = array_values(array_filter(array_map(static function ($post_obj): int {
            return isset($post_obj->ID) ? (int) $post_obj->ID : 0;
        }, (array) $query->posts), static function (int $word_id): bool {
            return $word_id > 0;
        }));
        $manual_word_order = function_exists('ll_tools_get_vocab_lesson_manual_word_order')
            ? ll_tools_get_vocab_lesson_manual_word_order($lesson_id, $allowed_word_ids)
            : [];
        if (!empty($manual_word_order) && function_exists('ll_tools_reorder_posts_by_word_id_order')) {
            $query->posts = ll_tools_reorder_posts_by_word_id_order((array) $query->posts, $manual_word_order);
            $query->post_count = count($query->posts);
            $query->current_post = -1;
            $manual_order_applied = true;
        }
    }

    if (
        !$manual_order_applied
        && empty($specific_word_ids)
        && $category_term
        && $wordset_id > 0
        && function_exists('ll_tools_get_word_option_maps')
    ) {
        $maps = ll_tools_get_word_option_maps($wordset_id, (int) $category_term->term_id);
        $groups = isset($maps['groups']) && is_array($maps['groups']) ? $maps['groups'] : [];
        if (!empty($groups)) {
            $query->posts = ll_tools_word_grid_reorder_by_option_groups($query->posts, $groups);
            $query->post_count = count($query->posts);
            $query->current_post = -1;
        }
    }
    $word_ids = wp_list_pluck($query->posts, 'ID');
    if (!empty($word_ids)) {
        update_meta_cache('post', $word_ids);
    }
    $display_values_cache = [];
    if (empty($specific_word_ids) && !$manual_order_applied) {
        $query->posts = ll_tools_word_grid_group_same_name_or_image($query->posts, $display_values_cache);
    }
    if ($sort_visible_titles && empty($specific_word_ids) && !$manual_order_applied) {
        $query->posts = ll_tools_word_grid_sort_posts_by_display_title($query->posts, $display_values_cache);
    }
    if (!empty($presentation_hidden_word_lookup)) {
        $query->posts = ll_tools_word_grid_move_lookup_posts_to_end($query->posts, $presentation_hidden_word_lookup);
    }
    $query->post_count = count($query->posts);
    $query->current_post = -1;
    $word_ids = wp_list_pluck($query->posts, 'ID');
    $part_of_speech_by_word = ll_tools_word_grid_collect_part_of_speech_terms($word_ids);
    $recording_launch_items_by_word = ll_tools_word_grid_get_recording_launch_items_by_word($word_ids, $wordset_id, $category_term);
    $audio_by_word = ll_tools_word_grid_collect_audio_files($word_ids, true);
    $main_recording_types = function_exists('ll_tools_get_main_recording_types')
        ? ll_tools_get_main_recording_types()
        : ['isolation', 'question', 'introduction'];
    $recording_type_order = ll_tools_word_grid_get_lesson_recording_type_order();
    $recording_labels = [
        'question'     => __('Question', 'll-tools-text-domain'),
        'isolation'    => __('Isolation', 'll-tools-text-domain'),
        'introduction' => __('Introduction', 'll-tools-text-domain'),
        'sentence'     => __('Sentence', 'll-tools-text-domain'),
        'in-sentence'  => __('In sentence', 'll-tools-text-domain'),
    ];

    $target_lang_raw = '';
    if ($wordset_term && function_exists('ll_tools_get_wordset_target_language')) {
        $target_lang_raw = (string) ll_tools_get_wordset_target_language([(int) $wordset_term->term_id]);
    }
    if ($target_lang_raw === '') {
        $target_lang_raw = (string) get_option('ll_target_language', '');
    }
    $translation_lang_raw = ($wordset_term && function_exists('ll_tools_get_wordset_translation_language'))
        ? (string) ll_tools_get_wordset_translation_language([(int) $wordset_term->term_id])
        : (string) get_option('ll_translation_language', '');
    $target_lang_code = ll_tools_word_grid_format_language_code($target_lang_raw);
    $translation_lang_code = ll_tools_word_grid_format_language_code($translation_lang_raw);
    $transcription_config = function_exists('ll_tools_get_wordset_recording_transcription_config')
        ? ll_tools_get_wordset_recording_transcription_config([$wordset_id], true)
        : [
            'mode' => 'ipa',
            'label' => __('IPA', 'll-tools-text-domain'),
            'display_format' => 'brackets',
            'uses_ipa_font' => true,
            'supports_superscript' => true,
            'suggestions_aria_label' => __('IPA suggestions', 'll-tools-text-domain'),
            'keyboard_aria_label' => __('IPA symbols', 'll-tools-text-domain'),
        ];
    $transcription_mode = (string) ($transcription_config['mode'] ?? 'ipa');
    $secondary_text_label = (string) ($transcription_config['label'] ?? __('IPA', 'll-tools-text-domain'));
    $secondary_text_display_format = (string) ($transcription_config['display_format'] ?? 'plain');
    $secondary_text_uses_ipa_font = !empty($transcription_config['uses_ipa_font']);
    $secondary_text_supports_superscript = !empty($transcription_config['supports_superscript']);

    $play_label_template = __('Play %s recording', 'll-tools-text-domain');
    $edit_labels = [
        'edit_word'   => __('Edit word', 'll-tools-text-domain'),
        'edit_field'  => __('Edit %s', 'll-tools-text-domain'),
        'edit_recording' => __('Edit %s recording', 'll-tools-text-domain'),
        'word'        => ll_tools_word_grid_label_with_code(__('Word', 'll-tools-text-domain'), $target_lang_code),
        'translation' => ll_tools_word_grid_label_with_code(__('Translation', 'll-tools-text-domain'), $translation_lang_code),
        'add_word'    => __('Add word', 'll-tools-text-domain'),
        'add_translation' => __('Add translation', 'll-tools-text-domain'),
        'note'        => __('Note', 'll-tools-text-domain'),
        'categories'  => __('Categories', 'll-tools-text-domain'),
        'category_search' => __('Search categories', 'll-tools-text-domain'),
        'category_sort' => __('Sort categories', 'll-tools-text-domain'),
        'category_sort_wordset' => __('Wordset order', 'll-tools-text-domain'),
        'category_sort_alpha' => __('A-Z', 'll-tools-text-domain'),
        'category_no_matches' => __('No categories match.', 'll-tools-text-domain'),
        'image'       => __('Image', 'll-tools-text-domain'),
        'select_image' => __('Choose image', 'll-tools-text-domain'),
        'existing_image' => __('Existing image', 'll-tools-text-domain'),
        'existing_image_placeholder' => __('Search word set images', 'll-tools-text-domain'),
        'image_hint'  => __('Upload a new image or select an existing word image.', 'll-tools-text-domain'),
        'no_image'    => __('No image', 'll-tools-text-domain'),
        'image_copyright' => __('Copyright Info', 'll-tools-text-domain'),
        'image_copyright_hint' => __('Edit or add any copyright or source info for this image.', 'll-tools-text-domain'),
        'wrong_answer_options' => __('Wrong answer options (one per line)', 'll-tools-text-domain'),
        'recordings'  => __('Recordings', 'll-tools-text-domain'),
        'text'        => ll_tools_word_grid_label_with_code(__('Text', 'll-tools-text-domain'), $target_lang_code),
        'ipa'         => ll_tools_word_grid_label_with_code($secondary_text_label, $target_lang_code),
        'mark_text_review' => __('Mark text for review', 'll-tools-text-domain'),
        'clear_text_review' => __('Clear text review flag', 'll-tools-text-domain'),
        'mark_ipa_review' => __('Mark pronunciation for review', 'll-tools-text-domain'),
        'clear_ipa_review' => __('Clear pronunciation review flag', 'll-tools-text-domain'),
        'review_note' => __('Review note', 'll-tools-text-domain'),
        'ipa_superscript' => __('Superscript selection', 'll-tools-text-domain'),
        'processing'  => __('Audio processing', 'll-tools-text-domain'),
        'process_audio' => __('Process audio', 'll-tools-text-domain'),
        'reprocess_audio' => __('Reprocess audio', 'll-tools-text-domain'),
        'auto_trim'   => __('Auto trim', 'll-tools-text-domain'),
        'noise_reduction' => __('Noise reduction', 'll-tools-text-domain'),
        'normalize_loudness' => __('Normalize volume', 'll-tools-text-domain'),
        'play_selection' => __('Play clip', 'll-tools-text-domain'),
        'waveform_label' => __('Audio clip boundaries', 'll-tools-text-domain'),
        'waveform_loading' => __('Loading waveform...', 'll-tools-text-domain'),
        'waveform_unavailable' => __('Waveform unavailable.', 'll-tools-text-domain'),
        'trim_start_handle' => __('Start boundary', 'll-tools-text-domain'),
        'trim_end_handle' => __('End boundary', 'll-tools-text-domain'),
        'source_original' => __('Using saved original audio', 'll-tools-text-domain'),
        'source_current' => __('Using current audio', 'll-tools-text-domain'),
        'download_audio' => __('Download audio', 'll-tools-text-domain'),
        'move_recording' => __('Move recording', 'll-tools-text-domain'),
        'move_recording_to' => __('Move to word', 'll-tools-text-domain'),
        'move_recording_placeholder' => __('Search words', 'll-tools-text-domain'),
        'delete_recording' => __('Delete recording', 'll-tools-text-domain'),
        'delete_recording_prompt' => __('Delete this recording?', 'll-tools-text-domain'),
        'delete_recording_confirm' => __('Move recording to Trash', 'll-tools-text-domain'),
        'delete_word' => __('Delete word', 'll-tools-text-domain'),
        'delete_word_prompt' => __('Delete this word?', 'll-tools-text-domain'),
        'delete_word_confirm' => __('Move word to Trash', 'll-tools-text-domain'),
        'save'        => __('Save', 'll-tools-text-domain'),
        'cancel'      => __('Cancel', 'll-tools-text-domain'),
    ];
    $show_stars = is_user_logged_in();
    $starred_ids = array_values(array_filter(array_map('intval', (array) ($user_study_state['starred_word_ids'] ?? []))));
    $show_lesson_recording_edit_triggers = $can_edit_words && ll_tools_word_grid_is_lesson_context($context);
    $can_manage_internal_notes = !empty($context['can_manage_internal_notes'])
        && function_exists('ll_tools_render_internal_review_note_field');
    $show_word_category_editor = $can_edit_words && $wordset_id > 0 && ll_tools_word_grid_is_lesson_context($context);
    $show_word_interlinears = $lesson_id > 0
        && function_exists('ll_tools_current_user_can_view_interlinear')
        && function_exists('ll_tools_interlinear_has_payload')
        && function_exists('ll_tools_render_recording_interlinear_block')
        && ll_tools_current_user_can_view_interlinear($lesson_id)
        && ll_tools_interlinear_has_payload($lesson_id);
    $category_editor_rows = $show_word_category_editor
        ? ll_tools_word_grid_get_category_editor_rows($wordset_id)
        : [];
    $category_editor_ids = ll_tools_word_grid_normalize_category_id_list(wp_list_pluck($category_editor_rows, 'id'));

    // The Loop
    if ($query->have_posts()) {
        $grid_classes = 'word-grid ll-word-grid';
        if ($is_text_based) {
            $grid_classes .= ' ll-word-grid--text';
        }
        if ($show_staff_inactive_images) {
            $grid_classes .= ' ll-word-grid--staff-inactive-images';
        }
        if ($hide_lesson_grid_text) {
            $grid_classes .= ' ll-word-grid--hide-text';
        }
        if ($can_edit_words && $lesson_id > 0) {
            $grid_classes .= ' ll-word-grid--reorderable';
        }
        if ($show_word_interlinears) {
            $grid_classes .= ' ll-word-grid--has-interlinear';
        }
        $grid_attrs = 'data-ll-word-grid';
        $grid_attrs .= ' data-ll-secondary-text-mode="' . esc_attr($transcription_mode) . '"';
        $grid_attrs .= ' data-ll-secondary-text-format="' . esc_attr($secondary_text_display_format) . '"';
        $grid_attrs .= ' data-ll-secondary-text-uses-ipa-font="' . ($secondary_text_uses_ipa_font ? '1' : '0') . '"';
        $grid_attrs .= ' data-ll-secondary-text-supports-superscript="' . ($secondary_text_supports_superscript ? '1' : '0') . '"';
        if ($lesson_id > 0) {
            $grid_attrs .= ' data-ll-lesson-id="' . esc_attr($lesson_id) . '"';
        }
        if ($wordset_id > 0) {
            $grid_attrs .= ' data-ll-wordset-id="' . esc_attr($wordset_id) . '"';
        }
        if ($category_term && !is_wp_error($category_term)) {
            $grid_attrs .= ' data-ll-category-id="' . esc_attr((int) $category_term->term_id) . '"';
        }
        if ($wordset_has_gender) {
            $grid_attrs .= ' data-ll-gender-enabled="1"';
        }
        if ($wordset_has_plurality) {
            $grid_attrs .= ' data-ll-plurality-enabled="1"';
        }
        if ($wordset_has_verb_tense) {
            $grid_attrs .= ' data-ll-verb-tense-enabled="1"';
        }
        if ($wordset_has_verb_mood) {
            $grid_attrs .= ' data-ll-verb-mood-enabled="1"';
        }
        if ($can_edit_words && $lesson_id > 0) {
            $grid_attrs .= ' data-ll-word-grid-reorderable="1"';
        }
        $word_grid_style_parts = [];
        if ($wordset_id > 0 && function_exists('ll_tools_wordset_get_answer_option_text_style_config')) {
            $answer_option_style = ll_tools_wordset_get_answer_option_text_style_config((int) $wordset_id);
            $answer_option_font_family = isset($answer_option_style['fontFamily']) ? trim((string) $answer_option_style['fontFamily']) : '';
            $answer_option_font_weight = function_exists('ll_tools_wordset_normalize_answer_option_font_weight')
                ? ll_tools_wordset_normalize_answer_option_font_weight((string) ($answer_option_style['fontWeight'] ?? '700'))
                : trim((string) ($answer_option_style['fontWeight'] ?? '700'));
            if ($answer_option_font_weight !== '') {
                $word_grid_style_parts[] = '--ll-word-grid-answer-option-font-weight:' . $answer_option_font_weight;
            }
            if ($answer_option_font_family !== '') {
                $grid_classes .= ' ll-word-grid--answer-option-font-custom';
                $word_grid_style_parts[] = '--ll-word-grid-answer-option-font-family:' . $answer_option_font_family;
                $line_height = isset($answer_option_style['lineHeightRatioWithDiacritics'])
                    ? (float) $answer_option_style['lineHeightRatioWithDiacritics']
                    : 1.4;
                if ($line_height < 1.05) {
                    $line_height = 1.05;
                } elseif ($line_height > 2.4) {
                    $line_height = 2.4;
                }
                $word_grid_style_parts[] = '--ll-word-grid-answer-option-line-height:' . rtrim(rtrim(number_format($line_height, 2, '.', ''), '0'), '.');
            }
        }
        if (!empty($word_grid_style_parts)) {
            $grid_attrs .= ' style="' . esc_attr(implode(';', $word_grid_style_parts) . ';') . '"';
        }
        echo '<div id="word-grid" class="' . esc_attr($grid_classes) . '" ' . $grid_attrs . '>'; // Grid container
        if ($can_edit_words && $lesson_id > 0) {
            echo '<div class="ll-word-grid-order-status" data-ll-word-grid-order-status aria-live="polite" hidden></div>';
        }
        $word_grid_image_size = apply_filters('ll_tools_word_grid_image_size', 'medium_large', $wordset_id, $category_term);
        if (!is_array($word_grid_image_size) && !is_string($word_grid_image_size)) {
            $word_grid_image_size = 'medium_large';
        }
        if (is_string($word_grid_image_size)) {
            $word_grid_image_size = trim($word_grid_image_size);
            if ($word_grid_image_size === '') {
                $word_grid_image_size = 'medium_large';
            }
        }
        $word_grid_render_index = 0;
        $prioritize_initial_lesson_images = ll_tools_word_grid_is_lesson_context($context);
        while ($query->have_posts()) {
            $query->the_post();
            $word_grid_render_index++;
            $word_id = get_the_ID();
            $word_status = (string) get_post_status($word_id);
            $is_draft_word = ($word_status === 'draft');
            $is_presentation_hidden_word = isset($presentation_hidden_word_lookup[(int) $word_id]);
            $presentation_hidden_reason = $is_presentation_hidden_word
                ? (string) ($presentation_hidden_word_reasons[(int) $word_id] ?? '')
                : '';
            $draft_note = $is_draft_word ? ll_tools_word_grid_get_draft_word_note((int) $word_id) : '';
            $presentation_hidden_note = (!$is_draft_word && $is_presentation_hidden_word)
                ? ll_tools_word_grid_get_presentation_hidden_word_note((int) $word_id, $presentation_hidden_reason)
                : '';
            $hide_text_for_word = $hide_lesson_grid_text && !$is_presentation_hidden_word;
            $display_values = $display_values_cache[$word_id] ?? ll_tools_word_grid_resolve_display_text($word_id);
            $word_text = $display_values['word_text'];
            $translation_text = $display_values['translation_text'];
            $image_data = $can_edit_words
                ? ll_tools_word_grid_get_image_data_for_word($word_id)
                : ['id' => 0, 'url' => '', 'alt' => '', 'width' => 0, 'height' => 0, 'word_image_id' => 0];
            $word_note = trim((string) get_post_meta($word_id, 'll_word_usage_note', true));
            $specific_wrong_answer_texts = ($can_edit_words && $has_text_only_answer_options && function_exists('ll_tools_get_word_specific_wrong_answer_texts'))
                ? ll_tools_get_word_specific_wrong_answer_texts($word_id)
                : [];
            $dictionary_entry_id = function_exists('ll_tools_get_word_dictionary_entry_id')
                ? (int) ll_tools_get_word_dictionary_entry_id($word_id)
                : 0;
            $dictionary_entry_title = $dictionary_entry_id > 0
                ? trim((string) get_the_title($dictionary_entry_id))
                : '';
            $selected_category_ids = (!empty($category_editor_ids) && $wordset_id > 0)
                ? ll_tools_word_grid_get_selected_category_ids_for_editor($word_id, $wordset_id, $category_editor_ids)
                : [];
            $selected_category_lookup = array_fill_keys($selected_category_ids, true);
            $pos_entry = $part_of_speech_by_word[$word_id] ?? [];
            $pos_slug = isset($pos_entry['slug']) ? (string) $pos_entry['slug'] : '';
            $pos_label = isset($pos_entry['label']) ? (string) $pos_entry['label'] : '';
            $is_noun = ($pos_slug === 'noun');
            $is_verb = ($pos_slug === 'verb');
            $gender_value = '';
            $gender_label = '';
            $gender_display = [
                'value' => '',
                'label' => '',
                'role' => 'other',
                'style' => '',
                'html' => '',
            ];
            if ($wordset_has_gender && $wordset_id > 0 && $is_noun) {
                $gender_value = trim((string) get_post_meta($word_id, 'll_grammatical_gender', true));
                if ($gender_value !== '') {
                    if (function_exists('ll_tools_wordset_normalize_gender_value_for_options')) {
                        $gender_value = ll_tools_wordset_normalize_gender_value_for_options($gender_value, $gender_options);
                    } elseif (function_exists('ll_tools_word_grid_match_option_value_case_insensitive')) {
                        $gender_value = ll_tools_word_grid_match_option_value_case_insensitive($gender_value, $gender_options);
                    }
                    if (function_exists('ll_tools_wordset_get_gender_display_data')) {
                        $gender_display = ll_tools_wordset_get_gender_display_data($wordset_id, $gender_value);
                        $gender_value = (string) ($gender_display['value'] ?? $gender_value);
                        $gender_label = (string) ($gender_display['label'] ?? '');
                    } elseif (function_exists('ll_tools_wordset_get_gender_label')) {
                        $gender_label = ll_tools_wordset_get_gender_label($wordset_id, $gender_value);
                        $gender_display['label'] = $gender_label;
                    } else {
                        $gender_label = $gender_value;
                        $gender_display['label'] = $gender_label;
                    }
                }
            }
            $plurality_value = '';
            $plurality_label = '';
            if ($wordset_has_plurality && $wordset_id > 0 && $is_noun) {
                $plurality_value = trim((string) get_post_meta($word_id, 'll_grammatical_plurality', true));
                if ($plurality_value !== '' && function_exists('ll_tools_word_grid_match_option_value_case_insensitive')) {
                    $plurality_value = ll_tools_word_grid_match_option_value_case_insensitive($plurality_value, $plurality_options);
                }
                if ($plurality_value !== '' && function_exists('ll_tools_wordset_get_plurality_label')) {
                    $plurality_label = ll_tools_wordset_get_plurality_label($wordset_id, $plurality_value);
                } else {
                    $plurality_label = $plurality_value;
                }
            }
            $verb_tense_value = '';
            $verb_tense_label = '';
            if ($wordset_has_verb_tense && $wordset_id > 0 && $is_verb) {
                $verb_tense_value = trim((string) get_post_meta($word_id, 'll_verb_tense', true));
                if ($verb_tense_value !== '' && function_exists('ll_tools_word_grid_match_option_value_case_insensitive')) {
                    $verb_tense_value = ll_tools_word_grid_match_option_value_case_insensitive($verb_tense_value, $verb_tense_options);
                }
                if ($verb_tense_value !== '' && function_exists('ll_tools_wordset_get_verb_tense_label')) {
                    $verb_tense_label = ll_tools_wordset_get_verb_tense_label($wordset_id, $verb_tense_value);
                } else {
                    $verb_tense_label = $verb_tense_value;
                }
            }
            $verb_mood_value = '';
            $verb_mood_label = '';
            if ($wordset_has_verb_mood && $wordset_id > 0 && $is_verb) {
                $verb_mood_value = trim((string) get_post_meta($word_id, 'll_verb_mood', true));
                if ($verb_mood_value !== '' && function_exists('ll_tools_word_grid_match_option_value_case_insensitive')) {
                    $verb_mood_value = ll_tools_word_grid_match_option_value_case_insensitive($verb_mood_value, $verb_mood_options);
                }
                if ($verb_mood_value !== '' && function_exists('ll_tools_wordset_get_verb_mood_label')) {
                    $verb_mood_label = ll_tools_wordset_get_verb_mood_label($wordset_id, $verb_mood_value);
                } else {
                    $verb_mood_label = $verb_mood_value;
                }
            }

            $word_has_any_effective_image = (!$is_text_based || $show_staff_inactive_images)
                ? (
                    function_exists('ll_tools_word_has_effective_image')
                        ? ll_tools_word_has_effective_image((int) $word_id, true)
                        : has_post_thumbnail((int) $word_id)
                )
                : false;
            $word_has_staff_inactive_image = $is_text_based
                && $show_staff_inactive_images
                && !$is_draft_word
                && !$is_presentation_hidden_word
                && $word_has_any_effective_image;
            $word_has_effective_image = !$is_text_based && $word_has_any_effective_image;
            $should_render_word_image = $word_has_effective_image || $word_has_staff_inactive_image;

            // Individual item
            $word_item_classes = 'word-item';
            if ($is_draft_word || $is_presentation_hidden_word) {
                $word_item_classes .= ' ll-word-item--draft';
            }
            if ($is_presentation_hidden_word) {
                $word_item_classes .= ' ll-word-item--presentation-hidden';
            }
            if ($word_has_staff_inactive_image) {
                $word_item_classes .= ' ll-word-item--staff-inactive-image';
            }
            if (!$is_text_based && !$word_has_effective_image) {
                $word_item_classes .= ' ll-word-item--no-image';
            }
            $presentation_hidden_attr = $is_presentation_hidden_word ? ' data-ll-word-presentation-hidden="1"' : '';
            if ($presentation_hidden_attr !== '' && $presentation_hidden_reason !== '') {
                $presentation_hidden_attr .= ' data-ll-word-presentation-hidden-reason="' . esc_attr($presentation_hidden_reason) . '"';
            }
            echo '<div class="' . esc_attr($word_item_classes) . '" data-word-id="' . esc_attr($word_id) . '" data-ll-word-status="' . esc_attr($word_status) . '"' . $presentation_hidden_attr . '>';
            // Featured image with container
            if ($should_render_word_image) {
                $image_container_classes = 'word-image-container';
                if ($word_has_staff_inactive_image) {
                    $image_container_classes .= ' ll-word-image-container--staff-inactive';
                }
                $word_image_attachment_id = function_exists('ll_tools_get_effective_word_image_attachment_id_for_word')
                    ? (int) ll_tools_get_effective_word_image_attachment_id_for_word((int) get_the_ID(), true)
                    : (int) get_post_thumbnail_id((int) get_the_ID());
                $word_image_is_animated_webp = $word_image_attachment_id > 0
                    && function_exists('ll_tools_is_attachment_animated_webp')
                    && ll_tools_is_attachment_animated_webp($word_image_attachment_id);
                if ($word_image_is_animated_webp) {
                    $image_container_classes .= ' ll-word-image-container--animated-webp';
                }
                echo '<div class="' . esc_attr($image_container_classes) . '">'; // Start new container
                $image_loading_attr = ($prioritize_initial_lesson_images && $word_grid_render_index <= 6) ? 'eager' : 'lazy';
                $image_fetchpriority_attr = ($prioritize_initial_lesson_images && $word_grid_render_index <= 2) ? 'high' : 'low';
                $word_image_attrs = array(
                    'class' => 'word-image',
                    'loading' => $image_loading_attr,
                    'decoding' => 'async',
                    'fetchpriority' => $image_fetchpriority_attr,
                );
                if ($word_image_is_animated_webp) {
                    $word_image_attrs['data-ll-animated-webp'] = '1';
                }
                echo ll_tools_word_grid_get_post_thumbnail_html((int) get_the_ID(), $word_grid_image_size, $word_image_attrs);
                if ($word_has_staff_inactive_image) {
                    echo '<span class="ll-word-image-staff-overlay">' . esc_html(ll_tools_word_grid_get_staff_inactive_image_note()) . '</span>';
                }
                echo '</div>'; // Close container
            }

            $audio_files = $audio_by_word[$word_id] ?? [];
            $preferred_speaker = ll_tools_word_grid_get_preferred_speaker($audio_files, $main_recording_types);
            $has_recordings = false;
            $recordings_html = '';
            $recording_rows = [];
            $has_recording_caption = false;
            $edit_recordings = [];
            $recording_launch_html = '';
            if (isset($recording_launch_items_by_word[(int) $word_id])) {
                $recording_launch_html = ll_tools_word_grid_render_recording_launch_button(
                    (array) $recording_launch_items_by_word[(int) $word_id],
                    (string) $word_text
                );
            }

            $recording_display_entries = ll_tools_word_grid_get_recording_display_entries(
                $audio_files,
                $main_recording_types,
                $recording_type_order,
                $can_edit_words
            );
            foreach ($recording_display_entries as $display_entry) {
                $entry = isset($display_entry['entry']) && is_array($display_entry['entry'])
                    ? (array) $display_entry['entry']
                    : [];
                $type = isset($display_entry['type'])
                    ? ll_tools_word_grid_normalize_recording_type_slug((string) $display_entry['type'])
                    : '';
                $audio_url = isset($entry['url']) ? (string) $entry['url'] : '';
                if ($type === '' || !$audio_url) {
                    continue;
                }
                $has_recordings = true;
                $label = $recording_labels[$type] ?? ll_tools_word_grid_get_recording_type_label($type);
                $play_label = sprintf($play_label_template, $label);
                $recording_text = trim((string) ($entry['recording_text'] ?? ''));
                $recording_translation = trim((string) ($entry['recording_translation'] ?? ''));
                $recording_ipa = ll_tools_word_grid_normalize_ipa_output((string) ($entry['recording_ipa'] ?? ''), $transcription_mode);
                $recording_review_fields = isset($entry['review_fields']) && is_array($entry['review_fields'])
                    ? (array) $entry['review_fields']
                    : ['recording_text' => false, 'recording_ipa' => false];
                if ($recording_text !== '' || $recording_translation !== '' || $recording_ipa !== '') {
                    $has_recording_caption = true;
                }
                $recording_id_attr = '';
                if (!empty($entry['id'])) {
                    $recording_id_attr = ' data-recording-id="' . esc_attr((int) $entry['id']) . '"';
                }
                $is_secondary_recording = !empty($display_entry['secondary']);
                $recording_visibility_note = trim((string) ($display_entry['visibility_note'] ?? ''));
                $recording_edit_label = ($show_lesson_recording_edit_triggers && !empty($entry['id']))
                    ? sprintf($edit_labels['edit_recording'], $label)
                    : '';
                $recording_button_classes = 'll-study-recording-btn ll-word-grid-recording-btn ll-study-recording-btn--' . $type;
                if ($is_secondary_recording) {
                    $recording_button_classes .= ' ll-word-grid-recording-btn--secondary';
                }
                $recording_button = '<button type="button" class="' . esc_attr($recording_button_classes) . '" data-audio-url="' . esc_url($audio_url) . '" data-recording-type="' . esc_attr($type) . '"' . $recording_id_attr . ' aria-label="' . esc_attr($play_label) . '" title="' . esc_attr($play_label) . '"';
                if ($recording_edit_label !== '') {
                    $recording_button .= ' data-ll-recording-edit-label="' . esc_attr($recording_edit_label) . '"';
                }
                if ($recording_visibility_note !== '') {
                    $recording_button .= ' data-ll-recording-visibility-note="' . esc_attr($recording_visibility_note) . '"';
                    $recording_button .= ' data-ll-recording-visibility-label="' . esc_attr__('Secondary', 'll-tools-text-domain') . '"';
                }
                $recording_button .= '>';
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
                    'review_fields' => $recording_review_fields,
                    'id' => !empty($entry['id']) ? (int) $entry['id'] : 0,
                    'edit_label' => $recording_edit_label,
                    'type' => $type,
                    'label' => $label,
                    'secondary' => $is_secondary_recording,
                    'duplicate' => !empty($display_entry['duplicate']),
                    'visibility_note' => $recording_visibility_note,
                ];

                if ($can_edit_words && !empty($entry['id'])) {
                    $edit_recordings[] = [
                        'id' => (int) $entry['id'],
                        'type' => $type,
                        'label' => $label,
                        'text' => (string) ($entry['recording_text'] ?? ''),
                        'translation' => (string) ($entry['recording_translation'] ?? ''),
                        'ipa' => ll_tools_word_grid_normalize_ipa_output((string) ($entry['recording_ipa'] ?? ''), $transcription_mode),
                        'audio_url' => $audio_url,
                        'processing_source_audio_url' => (string) ($entry['processing_source_url'] ?? $audio_url),
                        'uses_original_audio' => !empty($entry['uses_original_audio']),
                        'has_original_audio' => !empty($entry['has_original_audio']),
                        'review_fields' => $recording_review_fields,
                        'review_note' => (string) ($entry['review_note'] ?? ''),
                        'secondary' => $is_secondary_recording,
                        'duplicate' => !empty($display_entry['duplicate']),
                        'visibility_note' => $recording_visibility_note,
                    ];
                }
            }

            $actions_row_html = '';
            if ($show_stars || $can_edit_words) {
                $actions_row_html .= '<div class="ll-word-actions-row">';
                if ($can_edit_words && $lesson_id > 0) {
                    $order_handle_label = __('Drag to reorder word', 'll-tools-text-domain');
                    $actions_row_html .= '<span class="ll-word-grid-order-handle" data-ll-word-grid-order-handle title="' . esc_attr($order_handle_label) . '" aria-hidden="true">';
                    $actions_row_html .= '<svg viewBox="0 0 20 20" focusable="false" aria-hidden="true">';
                    $actions_row_html .= '<circle cx="7" cy="5" r="1.4" fill="currentColor"/><circle cx="13" cy="5" r="1.4" fill="currentColor"/><circle cx="7" cy="10" r="1.4" fill="currentColor"/><circle cx="13" cy="10" r="1.4" fill="currentColor"/><circle cx="7" cy="15" r="1.4" fill="currentColor"/><circle cx="13" cy="15" r="1.4" fill="currentColor"/>';
                    $actions_row_html .= '</svg>';
                    $actions_row_html .= '</span>';
                }
                if ($show_stars) {
                    $is_starred = in_array((int) $word_id, $starred_ids, true);
                    $star_label = $is_starred
                        ? __('Unstar word', 'll-tools-text-domain')
                        : __('Star word', 'll-tools-text-domain');
                    $actions_row_html .= '<button type="button" class="ll-word-star ll-word-grid-star ll-tools-star-button' . ($is_starred ? ' active' : '') . '" data-word-id="' . esc_attr($word_id) . '" aria-pressed="' . ($is_starred ? 'true' : 'false') . '" aria-label="' . esc_attr($star_label) . '" title="' . esc_attr($star_label) . '"></button>';
                }
                if ($can_edit_words) {
                    $actions_row_html .= '<button type="button" class="ll-word-edit-toggle" data-ll-word-edit-toggle aria-label="' . esc_attr($edit_labels['edit_word']) . '" title="' . esc_attr($edit_labels['edit_word']) . '" aria-expanded="false">';
                    $actions_row_html .= '<span class="ll-word-edit-icon" aria-hidden="true">';
                    $actions_row_html .= '<svg viewBox="0 0 24 24" focusable="false" aria-hidden="true"><path d="M4 20.5h4l10-10-4-4-10 10v4z" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M13.5 6.5l4 4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
                    $actions_row_html .= '</span>';
                    $actions_row_html .= '</button>';
                }
                $actions_row_html .= '</div>';
            }

            $title_row_html = '<div class="ll-word-title-row">';
            $title_row_html .= '<h3 class="word-title">';
            $show_inline_title_edit = $can_edit_words
                && ll_tools_word_grid_is_lesson_context($context)
                && !$hide_text_for_word;
            if ($show_inline_title_edit) {
                $title_row_html .= ll_tools_word_grid_render_inline_title_editor([
                    'field' => 'word',
                    'value' => $word_text,
                    'editor_id' => 'll-word-inline-editor-word-' . $word_id,
                    'input_id' => 'll-word-inline-input-word-' . $word_id,
                    'field_label' => $edit_labels['word'],
                    'trigger_label' => sprintf($edit_labels['edit_field'], $edit_labels['word']),
                    'placeholder' => $edit_labels['add_word'],
                ]);
                $title_row_html .= ll_tools_word_grid_render_inline_title_editor([
                    'field' => 'translation',
                    'value' => $translation_text,
                    'editor_id' => 'll-word-inline-editor-translation-' . $word_id,
                    'input_id' => 'll-word-inline-input-translation-' . $word_id,
                    'field_label' => $edit_labels['translation'],
                    'trigger_label' => sprintf($edit_labels['edit_field'], $edit_labels['translation']),
                    'placeholder' => $edit_labels['add_translation'],
                ]);
            } else {
                $title_row_html .= '<span class="ll-word-text" data-ll-word-text dir="auto">' . ll_tools_esc_html_display($word_text) . '</span>';
                $title_row_html .= '<span class="ll-word-translation" data-ll-word-translation dir="auto">' . ll_tools_esc_html_display($translation_text) . '</span>';
            }
            $title_row_html .= '</h3>';
            $title_row_html .= '</div>';

            if ($hide_text_for_word) {
                if ($actions_row_html !== '') {
                    echo $actions_row_html;
                }
            } elseif ($is_text_based) {
                echo $title_row_html;
                if ($actions_row_html !== '') {
                    echo $actions_row_html;
                }
            } else {
                if ($actions_row_html !== '') {
                    echo $actions_row_html;
                }
                echo $title_row_html;
            }
            $visibility_note = $draft_note !== '' ? $draft_note : $presentation_hidden_note;
            if ($visibility_note !== '') {
                echo '<div class="ll-word-draft-notice" data-ll-word-draft-notice>';
                echo '<span class="ll-word-draft-notice__badge">' . ($draft_note !== '' ? esc_html__('Draft', 'll-tools-text-domain') : esc_html__('Hidden', 'll-tools-text-domain')) . '</span>';
                echo '<span class="ll-word-draft-notice__text">' . esc_html($visibility_note) . '</span>';
                echo '</div>';
            }
            $meta_row_class = 'll-word-meta-row';
            if ($pos_label === '' && $gender_label === '' && $plurality_label === '' && $verb_tense_label === '' && $verb_mood_label === '') {
                $meta_row_class .= ' ll-word-meta-row--empty';
            }
            echo '<div class="' . esc_attr($meta_row_class) . '" data-ll-word-meta>';
            echo '<span class="ll-word-meta-tag ll-word-meta-tag--pos" data-ll-word-pos>' . esc_html($pos_label) . '</span>';
            $gender_tag_class = 'll-word-meta-tag ll-word-meta-tag--gender';
            $gender_role = (string) ($gender_display['role'] ?? '');
            if ($gender_role !== '') {
                $gender_tag_class .= ' ll-word-meta-tag--gender-' . sanitize_html_class($gender_role);
            }
            $gender_style = (string) ($gender_display['style'] ?? '');
            $gender_style_attr = ($gender_style !== '') ? ' style="' . esc_attr($gender_style) . '"' : '';
            $gender_aria_label = (string) ($gender_display['label'] ?? $gender_label);
            echo '<span class="' . esc_attr($gender_tag_class) . '" data-ll-word-gender data-ll-gender-role="' . esc_attr($gender_role) . '"' . $gender_style_attr . ' aria-label="' . esc_attr($gender_aria_label) . '" title="' . esc_attr($gender_aria_label) . '">';
            if (!empty($gender_display['html'])) {
                echo $gender_display['html'];
            } else {
                echo esc_html($gender_label);
            }
            echo '</span>';
            echo '<span class="ll-word-meta-tag ll-word-meta-tag--plurality" data-ll-word-plurality>' . esc_html($plurality_label) . '</span>';
            echo '<span class="ll-word-meta-tag ll-word-meta-tag--verb-tense" data-ll-word-verb-tense>' . esc_html($verb_tense_label) . '</span>';
            echo '<span class="ll-word-meta-tag ll-word-meta-tag--verb-mood" data-ll-word-verb-mood>' . esc_html($verb_mood_label) . '</span>';
            echo '</div>';
            if (!$hide_text_for_word) {
                $note_class = 'll-word-note';
                if ($word_note === '') {
                    $note_class .= ' ll-word-note--empty';
                }
                echo '<div class="' . esc_attr($note_class) . '" data-ll-word-note>' . esc_html($word_note) . '</div>';
            }

            if ($can_edit_words) {
                echo '<div class="ll-word-save-status" data-ll-word-save-status aria-live="polite"></div>';
                $word_input_id = 'll-word-edit-word-' . $word_id;
                $translation_input_id = 'll-word-edit-translation-' . $word_id;
                $note_input_id = 'll-word-edit-note-' . $word_id;
                echo '<div class="ll-word-edit-backdrop" data-ll-word-edit-backdrop aria-hidden="true" hidden></div>';
                echo '<div class="ll-word-edit-panel" data-ll-word-edit-panel aria-hidden="true">';
                echo '<div class="ll-word-edit-body" data-ll-word-edit-body>';
                echo '<div class="ll-word-edit-fields">';
                echo '<label class="ll-word-edit-label" for="' . esc_attr($word_input_id) . '">' . esc_html($edit_labels['word']) . '</label>';
                echo '<input type="text" class="ll-word-edit-input" id="' . esc_attr($word_input_id) . '" data-ll-word-input="word" value="' . esc_attr($word_text) . '" />';
                echo '<label class="ll-word-edit-label" for="' . esc_attr($translation_input_id) . '">' . esc_html($edit_labels['translation']) . '</label>';
                echo '<input type="text" class="ll-word-edit-input" id="' . esc_attr($translation_input_id) . '" data-ll-word-input="translation" value="' . esc_attr($translation_text) . '" />';
                echo '<label class="ll-word-edit-label" for="' . esc_attr($note_input_id) . '">' . esc_html($edit_labels['note']) . '</label>';
                echo '<textarea class="ll-word-edit-input ll-word-edit-textarea" id="' . esc_attr($note_input_id) . '" data-ll-word-input="note" rows="3">' . esc_textarea($word_note) . '</textarea>';
                if ($has_text_only_answer_options) {
                    $wrong_answer_texts_input_id = 'll-word-edit-wrong-answer-texts-' . $word_id;
                    echo '<label class="ll-word-edit-label" for="' . esc_attr($wrong_answer_texts_input_id) . '">' . esc_html($edit_labels['wrong_answer_options']) . '</label>';
                    echo '<textarea class="ll-word-edit-input ll-word-edit-textarea" id="' . esc_attr($wrong_answer_texts_input_id) . '" data-ll-word-input="specific_wrong_answer_texts" rows="4" dir="auto">' . esc_textarea(implode("\n", array_map('strval', (array) $specific_wrong_answer_texts))) . '</textarea>';
                }
                if (!empty($category_editor_rows)) {
                    $category_search_input_id = 'll-word-edit-category-search-' . $word_id;
                    $category_sort_input_id = 'll-word-edit-category-sort-' . $word_id;
                    echo '<fieldset class="ll-word-edit-field ll-word-edit-categories" data-ll-word-categories-field>';
                    echo '<legend class="ll-word-edit-label">' . esc_html($edit_labels['categories']) . '</legend>';
                    echo '<div class="ll-word-edit-category-tools">';
                    echo '<label class="screen-reader-text" for="' . esc_attr($category_search_input_id) . '">' . esc_html($edit_labels['category_search']) . '</label>';
                    echo '<input type="search" class="ll-word-edit-input ll-word-edit-category-search" id="' . esc_attr($category_search_input_id) . '" data-ll-word-category-search placeholder="' . esc_attr($edit_labels['category_search']) . '" autocomplete="off" />';
                    echo '<label class="screen-reader-text" for="' . esc_attr($category_sort_input_id) . '">' . esc_html($edit_labels['category_sort']) . '</label>';
                    echo '<select class="ll-word-edit-input ll-word-edit-category-sort" id="' . esc_attr($category_sort_input_id) . '" data-ll-word-category-sort aria-label="' . esc_attr($edit_labels['category_sort']) . '">';
                    echo '<option value="wordset">' . esc_html($edit_labels['category_sort_wordset']) . '</option>';
                    echo '<option value="alpha">' . esc_html($edit_labels['category_sort_alpha']) . '</option>';
                    echo '</select>';
                    echo '</div>';
                    echo '<div class="ll-word-edit-category-list" data-ll-word-category-list>';
                    $category_order_index = 0;
                    foreach ($category_editor_rows as $category_row) {
                        $category_option_id = (int) ($category_row['id'] ?? 0);
                        if ($category_option_id <= 0) {
                            continue;
                        }
                        $category_option_label = (string) ($category_row['label'] ?? '');
                        if ($category_option_label === '') {
                            $category_option_label = (string) $category_option_id;
                        }
                        $category_input_id = 'll-word-edit-category-' . $word_id . '-' . $category_option_id;
                        $is_checked = isset($selected_category_lookup[$category_option_id]);
                        echo '<label class="ll-word-edit-category-option" for="' . esc_attr($category_input_id) . '" data-ll-word-category-option data-ll-word-category-label="' . esc_attr($category_option_label) . '" data-ll-word-category-search-text="' . esc_attr($category_option_label) . '" data-ll-wordset-order="' . esc_attr((string) $category_order_index) . '">';
                        echo '<input type="checkbox" id="' . esc_attr($category_input_id) . '" class="ll-word-edit-category-checkbox" data-ll-word-category-input value="' . esc_attr((string) $category_option_id) . '"' . checked($is_checked, true, false) . ' />';
                        echo '<span class="ll-word-edit-category-label">' . esc_html($category_option_label) . '</span>';
                        echo '</label>';
                        $category_order_index++;
                    }
                    echo '</div>';
                    echo '<div class="ll-word-edit-category-empty" data-ll-word-category-empty hidden>' . esc_html($edit_labels['category_no_matches']) . '</div>';
                    echo '</fieldset>';
                }
                $image_input_id = 'll-word-edit-image-' . $word_id;
                $existing_image_input_id = 'll-word-edit-existing-image-' . $word_id;
                $image_copyright_input_id = 'll-word-edit-image-copyright-' . $word_id;
                $image_copyright_info = (string) ($image_data['copyright_info'] ?? '');
                echo '<div class="ll-word-edit-image" data-ll-word-image-panel>';
                echo '<div class="ll-word-edit-image-head">';
                echo '<span class="ll-word-edit-label">' . esc_html($edit_labels['image']) . '</span>';
                echo '<label class="ll-word-edit-image-button" for="' . esc_attr($image_input_id) . '">';
                echo '<span>' . esc_html($edit_labels['select_image']) . '</span>';
                echo '<input type="file" class="ll-word-edit-image-input" id="' . esc_attr($image_input_id) . '" data-ll-word-image-input accept="image/jpeg,image/png,image/gif,image/webp" />';
                echo '</label>';
                echo '</div>';
                echo '<div class="ll-word-edit-existing-image">';
                echo '<label class="ll-word-edit-label" for="' . esc_attr($existing_image_input_id) . '">' . esc_html($edit_labels['existing_image']) . '</label>';
                echo '<input type="text" class="ll-word-edit-input ll-word-edit-image-search" id="' . esc_attr($existing_image_input_id) . '" data-ll-word-image-existing-search placeholder="' . esc_attr($edit_labels['existing_image_placeholder']) . '" autocomplete="off" />';
                echo '<input type="hidden" data-ll-word-image-existing-id value="" />';
                echo '</div>';
                echo '<div class="ll-word-edit-image-frame" data-ll-word-image-frame data-ll-empty-label="' . esc_attr($edit_labels['no_image']) . '">';
                if (!empty($image_data['url'])) {
                    echo '<img class="ll-word-edit-image-preview" data-ll-word-image-preview data-ll-word-image-id="' . esc_attr((string) (int) ($image_data['word_image_id'] ?? 0)) . '" data-ll-word-image-attachment-id="' . esc_attr((string) (int) ($image_data['attachment_id'] ?? $image_data['id'] ?? 0)) . '" src="' . esc_url((string) $image_data['url']) . '" alt="' . esc_attr((string) ($image_data['alt'] ?? $word_text)) . '" loading="lazy" decoding="async" />';
                } else {
                    echo '<div class="ll-word-edit-image-empty" data-ll-word-image-empty>' . esc_html($edit_labels['no_image']) . '</div>';
                }
                echo '</div>';
                echo '<div class="ll-word-edit-image-selected" data-ll-word-image-selected aria-live="polite"></div>';
                echo '<div class="ll-word-edit-field ll-word-edit-image-copyright">';
                echo '<label class="ll-word-edit-label" for="' . esc_attr($image_copyright_input_id) . '">' . esc_html($edit_labels['image_copyright']) . '</label>';
                echo '<textarea class="ll-word-edit-input ll-word-edit-textarea" id="' . esc_attr($image_copyright_input_id) . '" data-ll-word-input="image_copyright" data-ll-word-image-copyright rows="3">' . esc_textarea($image_copyright_info) . '</textarea>';
                echo '<p class="ll-word-edit-image-hint">' . esc_html($edit_labels['image_copyright_hint']) . '</p>';
                echo '</div>';
                echo '<p class="ll-word-edit-image-hint">' . esc_html($edit_labels['image_hint']) . '</p>';
                echo '</div>';
                echo '</div>';
                echo '<div class="ll-word-edit-fields ll-word-edit-fields--meta">';
                $dictionary_entry_input_id = 'll-word-edit-dictionary-entry-' . $word_id;
                echo '<div class="ll-word-edit-field ll-word-edit-dictionary-entry">';
                echo '<label class="ll-word-edit-label" for="' . esc_attr($dictionary_entry_input_id) . '">' . esc_html__('Dictionary entry', 'll-tools-text-domain') . '</label>';
                echo '<input type="text" class="ll-word-edit-input" id="' . esc_attr($dictionary_entry_input_id) . '" data-ll-word-input="dictionary_entry_lookup" value="' . esc_attr($dictionary_entry_title) . '" placeholder="' . esc_attr__('Type to select or create dictionary entry', 'll-tools-text-domain') . '" autocomplete="off" />';
                echo '<input type="hidden" data-ll-word-input="dictionary_entry_id" value="' . esc_attr($dictionary_entry_id > 0 ? (string) $dictionary_entry_id : '') . '" />';
                echo '</div>';
                $pos_input_id = 'll-word-edit-pos-' . $word_id;
                echo '<div class="ll-word-edit-field">';
                echo '<label class="ll-word-edit-label" for="' . esc_attr($pos_input_id) . '">' . esc_html__('Part of speech', 'll-tools-text-domain') . '</label>';
                echo '<select class="ll-word-edit-input ll-word-edit-select" id="' . esc_attr($pos_input_id) . '" data-ll-word-input="part_of_speech">';
                echo '<option value="">' . esc_html__('None', 'll-tools-text-domain') . '</option>';
                foreach ($part_of_speech_terms as $term) {
                    $term_slug = (string) ($term->slug ?? '');
                    $term_label = (string) ($term->name ?? '');
                    if ($term_slug === '') {
                        continue;
                    }
                    echo '<option value="' . esc_attr($term_slug) . '"' . selected($term_slug, $pos_slug, false) . '>' . esc_html($term_label) . '</option>';
                }
                echo '</select>';
                echo '</div>';
                if ($wordset_has_gender) {
                    $gender_input_id = 'll-word-edit-gender-' . $word_id;
                    $gender_field_class = 'll-word-edit-field ll-word-edit-gender';
                    if (!$is_noun) {
                        $gender_field_class .= ' ll-word-edit-gender--hidden';
                    }
                    echo '<div class="' . esc_attr($gender_field_class) . '" data-ll-word-gender-field aria-hidden="' . ($is_noun ? 'false' : 'true') . '">';
                    echo '<label class="ll-word-edit-label" for="' . esc_attr($gender_input_id) . '">' . esc_html__('Gender', 'll-tools-text-domain') . '</label>';
                    echo '<select class="ll-word-edit-input ll-word-edit-select" id="' . esc_attr($gender_input_id) . '" data-ll-word-input="gender"' . ($is_noun ? '' : ' disabled') . '>';
                    echo '<option value="">' . esc_html__('None', 'll-tools-text-domain') . '</option>';
                    $gender_found = false;
                    foreach ($gender_options as $option) {
                        $option_value = (string) $option;
                        if ($option_value === '') {
                            continue;
                        }
                        if ($option_value === $gender_value) {
                            $gender_found = true;
                        }
                        $option_label = function_exists('ll_tools_wordset_format_gender_display_label')
                            ? ll_tools_wordset_format_gender_display_label($option_value)
                            : $option_value;
                        echo '<option value="' . esc_attr($option_value) . '"' . selected($option_value, $gender_value, false) . '>' . esc_html($option_label) . '</option>';
                    }
                    if ($gender_value !== '' && !$gender_found) {
                        $fallback_label = function_exists('ll_tools_wordset_format_gender_display_label')
                            ? ll_tools_wordset_format_gender_display_label($gender_value)
                            : $gender_value;
                        echo '<option value="' . esc_attr($gender_value) . '" selected>' . esc_html($fallback_label) . '</option>';
                    }
                    echo '</select>';
                    echo '</div>';
                }
                if ($wordset_has_plurality) {
                    $plurality_input_id = 'll-word-edit-plurality-' . $word_id;
                    $plurality_field_class = 'll-word-edit-field ll-word-edit-plurality';
                    if (!$is_noun) {
                        $plurality_field_class .= ' ll-word-edit-plurality--hidden';
                    }
                    echo '<div class="' . esc_attr($plurality_field_class) . '" data-ll-word-plurality-field aria-hidden="' . ($is_noun ? 'false' : 'true') . '">';
                    echo '<label class="ll-word-edit-label" for="' . esc_attr($plurality_input_id) . '">' . esc_html__('Plurality', 'll-tools-text-domain') . '</label>';
                    echo '<select class="ll-word-edit-input ll-word-edit-select" id="' . esc_attr($plurality_input_id) . '" data-ll-word-input="plurality"' . ($is_noun ? '' : ' disabled') . '>';
                    echo '<option value="">' . esc_html__('None', 'll-tools-text-domain') . '</option>';
                    $plurality_found = false;
                    foreach ($plurality_options as $option) {
                        $option_value = (string) $option;
                        if ($option_value === '') {
                            continue;
                        }
                        if ($option_value === $plurality_value) {
                            $plurality_found = true;
                        }
                        echo '<option value="' . esc_attr($option_value) . '"' . selected($option_value, $plurality_value, false) . '>' . esc_html($option_value) . '</option>';
                    }
                    if ($plurality_value !== '' && !$plurality_found) {
                        echo '<option value="' . esc_attr($plurality_value) . '" selected>' . esc_html($plurality_value) . '</option>';
                    }
                    echo '</select>';
                    echo '</div>';
                }
                if ($wordset_has_verb_tense) {
                    $verb_tense_input_id = 'll-word-edit-verb-tense-' . $word_id;
                    $verb_tense_field_class = 'll-word-edit-field ll-word-edit-verb-tense';
                    if (!$is_verb) {
                        $verb_tense_field_class .= ' ll-word-edit-verb-tense--hidden';
                    }
                    echo '<div class="' . esc_attr($verb_tense_field_class) . '" data-ll-word-verb-tense-field aria-hidden="' . ($is_verb ? 'false' : 'true') . '">';
                    echo '<label class="ll-word-edit-label" for="' . esc_attr($verb_tense_input_id) . '">' . esc_html__('Verb tense', 'll-tools-text-domain') . '</label>';
                    echo '<select class="ll-word-edit-input ll-word-edit-select" id="' . esc_attr($verb_tense_input_id) . '" data-ll-word-input="verb_tense"' . ($is_verb ? '' : ' disabled') . '>';
                    echo '<option value="">' . esc_html__('None', 'll-tools-text-domain') . '</option>';
                    $verb_tense_found = false;
                    foreach ($verb_tense_options as $option) {
                        $option_value = (string) $option;
                        if ($option_value === '') {
                            continue;
                        }
                        if ($option_value === $verb_tense_value) {
                            $verb_tense_found = true;
                        }
                        echo '<option value="' . esc_attr($option_value) . '"' . selected($option_value, $verb_tense_value, false) . '>' . esc_html($option_value) . '</option>';
                    }
                    if ($verb_tense_value !== '' && !$verb_tense_found) {
                        echo '<option value="' . esc_attr($verb_tense_value) . '" selected>' . esc_html($verb_tense_value) . '</option>';
                    }
                    echo '</select>';
                    echo '</div>';
                }
                if ($wordset_has_verb_mood) {
                    $verb_mood_input_id = 'll-word-edit-verb-mood-' . $word_id;
                    $verb_mood_field_class = 'll-word-edit-field ll-word-edit-verb-mood';
                    if (!$is_verb) {
                        $verb_mood_field_class .= ' ll-word-edit-verb-mood--hidden';
                    }
                    echo '<div class="' . esc_attr($verb_mood_field_class) . '" data-ll-word-verb-mood-field aria-hidden="' . ($is_verb ? 'false' : 'true') . '">';
                    echo '<label class="ll-word-edit-label" for="' . esc_attr($verb_mood_input_id) . '">' . esc_html__('Verb mood', 'll-tools-text-domain') . '</label>';
                    echo '<select class="ll-word-edit-input ll-word-edit-select" id="' . esc_attr($verb_mood_input_id) . '" data-ll-word-input="verb_mood"' . ($is_verb ? '' : ' disabled') . '>';
                    echo '<option value="">' . esc_html__('None', 'll-tools-text-domain') . '</option>';
                    $verb_mood_found = false;
                    foreach ($verb_mood_options as $option) {
                        $option_value = (string) $option;
                        if ($option_value === '') {
                            continue;
                        }
                        if ($option_value === $verb_mood_value) {
                            $verb_mood_found = true;
                        }
                        echo '<option value="' . esc_attr($option_value) . '"' . selected($option_value, $verb_mood_value, false) . '>' . esc_html($option_value) . '</option>';
                    }
                    if ($verb_mood_value !== '' && !$verb_mood_found) {
                        echo '<option value="' . esc_attr($verb_mood_value) . '" selected>' . esc_html($verb_mood_value) . '</option>';
                    }
                    echo '</select>';
                    echo '</div>';
                }
                echo '</div>';

                if (!empty($edit_recordings)) {
                    echo '<button type="button" class="ll-word-edit-recordings-toggle" data-ll-word-recordings-toggle aria-expanded="true">';
                    echo '<span class="ll-word-edit-recordings-icon" aria-hidden="true">';
                    echo '<svg viewBox="0 0 24 24" focusable="false" aria-hidden="true"><path d="M4 10v4M9 6v12M14 8v8M19 11v2" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
                    echo '</span>';
                    echo '<span class="ll-word-edit-recordings-label">' . esc_html($edit_labels['recordings']) . '</span>';
                    echo '</button>';
                    echo '<div class="ll-word-edit-recordings" data-ll-word-recordings-panel aria-hidden="false">';
                    foreach ($edit_recordings as $recording) {
                        $recording_id = (int) ($recording['id'] ?? 0);
                        if ($recording_id <= 0) {
                            continue;
                        }
                        $recording_type = (string) ($recording['type'] ?? '');
                        $recording_label = (string) ($recording['label'] ?? $recording_type);
                        $recording_text = (string) ($recording['text'] ?? '');
                        $recording_translation = (string) ($recording['translation'] ?? '');
                        $recording_ipa = ll_tools_word_grid_normalize_ipa_output((string) ($recording['ipa'] ?? ''), $transcription_mode);
                        $recording_review_fields = isset($recording['review_fields']) && is_array($recording['review_fields'])
                            ? (array) $recording['review_fields']
                            : ['recording_text' => false, 'recording_ipa' => false];
                        $recording_text_needs_review = !empty($recording_review_fields['recording_text']);
                        $recording_ipa_needs_review = !empty($recording_review_fields['recording_ipa']);
                        $recording_review_note = (string) ($recording['review_note'] ?? '');
                        $recording_text_review_label = $recording_text_needs_review ? $edit_labels['clear_text_review'] : $edit_labels['mark_text_review'];
                        $recording_ipa_review_label = $recording_ipa_needs_review ? $edit_labels['clear_ipa_review'] : $edit_labels['mark_ipa_review'];
                        $recording_audio_url = (string) ($recording['audio_url'] ?? '');
                        $processing_source_audio_url = (string) ($recording['processing_source_audio_url'] ?? $recording_audio_url);
                        if ($processing_source_audio_url === '') {
                            $processing_source_audio_url = $recording_audio_url;
                        }
                        $uses_original_audio = !empty($recording['uses_original_audio']);
                        $has_original_audio = !empty($recording['has_original_audio']);
                        $is_secondary_recording = !empty($recording['secondary']);
                        $recording_visibility_note = trim((string) ($recording['visibility_note'] ?? ''));
                        $recording_panel_classes = 'll-word-edit-recording';
                        if ($is_secondary_recording) {
                            $recording_panel_classes .= ' ll-word-edit-recording--secondary';
                        }
                        $recording_text_id = 'll-word-edit-recording-text-' . $recording_id;
                        $recording_translation_id = 'll-word-edit-recording-translation-' . $recording_id;
                        $recording_ipa_id = 'll-word-edit-recording-ipa-' . $recording_id;
                        $recording_move_input_id = 'll-word-edit-recording-move-' . $recording_id;
                        $trim_input_id = 'll-word-edit-recording-trim-' . $recording_id;
                        $noise_input_id = 'll-word-edit-recording-noise-' . $recording_id;
                        $loudness_input_id = 'll-word-edit-recording-loudness-' . $recording_id;
                        $process_button_label = $has_original_audio ? $edit_labels['reprocess_audio'] : $edit_labels['process_audio'];
                        $source_label = $uses_original_audio ? $edit_labels['source_original'] : $edit_labels['source_current'];
                        echo '<div class="' . esc_attr($recording_panel_classes) . '" data-recording-id="' . esc_attr($recording_id) . '" data-recording-type="' . esc_attr($recording_type) . '" data-ll-current-audio-url="' . esc_url($recording_audio_url) . '" data-ll-processing-source-audio-url="' . esc_url($processing_source_audio_url) . '" data-ll-uses-original-audio="' . ($uses_original_audio ? '1' : '0') . '" data-ll-has-original-audio="' . ($has_original_audio ? '1' : '0') . '" data-ll-recording-secondary="' . ($is_secondary_recording ? '1' : '0') . '">';
                        echo '<div class="ll-word-edit-recording-header">';
                        echo '<div class="ll-word-edit-recording-title">';
                        echo '<span class="ll-word-edit-recording-icon" aria-hidden="true"></span>';
                        echo '<span class="ll-word-edit-recording-name">' . esc_html($recording_label) . '</span>';
                        if ($recording_visibility_note !== '') {
                            echo '<span class="ll-word-recording-visibility-badge" title="' . esc_attr($recording_visibility_note) . '">' . esc_html__('Secondary', 'll-tools-text-domain') . '</span>';
                        }
                        echo '</div>';
                        echo '<div class="ll-word-edit-recording-actions">';
                        if ($recording_audio_url !== '') {
                            $recording_play_label = sprintf($play_label_template, $recording_label);
                            $recording_play_classes = 'll-study-recording-btn ll-word-grid-recording-btn ll-word-edit-recording-btn ll-study-recording-btn--' . $recording_type;
                            if ($is_secondary_recording) {
                                $recording_play_classes .= ' ll-word-grid-recording-btn--secondary';
                            }
                            echo '<button type="button" class="' . esc_attr($recording_play_classes) . '" data-audio-url="' . esc_url($recording_audio_url) . '" data-recording-type="' . esc_attr($recording_type) . '" data-recording-id="' . esc_attr($recording_id) . '" aria-label="' . esc_attr($recording_play_label) . '" title="' . esc_attr($recording_play_label) . '">';
                            echo '<span class="ll-study-recording-icon" aria-hidden="true"></span>';
                            echo '<span class="ll-study-recording-visualizer" aria-hidden="true">';
                            for ($i = 0; $i < 4; $i++) {
                                echo '<span class="bar"></span>';
                            }
                            echo '</span>';
                            echo '</button>';
                        }
                        echo '<button type="button" class="ll-word-edit-recording-tool ll-word-edit-recording-tool--move" data-ll-recording-move-toggle aria-label="' . esc_attr($edit_labels['move_recording']) . '" title="' . esc_attr($edit_labels['move_recording']) . '">';
                        echo '<svg viewBox="0 0 24 24" focusable="false" aria-hidden="true"><path d="M7 7h10l-3-3M17 7l-3 3M17 17H7l3 3M7 17l3-3" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
                        echo '</button>';
                        echo '<button type="button" class="ll-word-edit-recording-tool ll-word-edit-recording-tool--delete" data-ll-recording-delete-toggle aria-label="' . esc_attr($edit_labels['delete_recording']) . '" title="' . esc_attr($edit_labels['delete_recording']) . '">';
                        echo '<svg viewBox="0 0 24 24" focusable="false" aria-hidden="true"><path d="M4 7h16M10 11v6M14 11v6M9 7l1-2h4l1 2M6 7l1 14h10l1-14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
                        echo '</button>';
                        echo '</div>';
                        echo '</div>';
                        if ($recording_review_note !== '' && ($recording_text_needs_review || $recording_ipa_needs_review)) {
                            echo '<div class="ll-word-edit-review-note" data-ll-recording-review-note>' . esc_html($recording_review_note) . '</div>';
                        }
                        echo '<div class="ll-word-edit-recording-confirm ll-word-edit-recording-confirm--delete" data-ll-recording-delete-confirm hidden>';
                        echo '<span class="ll-word-edit-confirm-icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false" aria-hidden="true"><path d="M4 7h16M10 11v6M14 11v6M9 7l1-2h4l1 2M6 7l1 14h10l1-14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></span>';
                        echo '<span class="ll-word-edit-confirm-text">' . esc_html($edit_labels['delete_recording_prompt']) . '</span>';
                        echo '<button type="button" class="ll-word-edit-confirm-action ll-word-edit-confirm-action--danger" data-ll-recording-delete-confirm-action aria-label="' . esc_attr($edit_labels['delete_recording_confirm']) . '" title="' . esc_attr($edit_labels['delete_recording_confirm']) . '"><svg viewBox="0 0 24 24" focusable="false" aria-hidden="true"><path d="M5 13l4 4L19 7" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg></button>';
                        echo '<button type="button" class="ll-word-edit-confirm-action" data-ll-recording-delete-cancel aria-label="' . esc_attr($edit_labels['cancel']) . '" title="' . esc_attr($edit_labels['cancel']) . '"><svg viewBox="0 0 24 24" focusable="false" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"/></svg></button>';
                        echo '</div>';
                        echo '<div class="ll-word-edit-recording-move" data-ll-recording-move-panel hidden>';
                        echo '<label class="ll-word-edit-label" for="' . esc_attr($recording_move_input_id) . '">' . esc_html($edit_labels['move_recording_to']) . '</label>';
                        echo '<div class="ll-word-edit-recording-move-row">';
                        echo '<input type="text" class="ll-word-edit-input ll-word-edit-recording-move-input" id="' . esc_attr($recording_move_input_id) . '" data-ll-recording-move-search placeholder="' . esc_attr($edit_labels['move_recording_placeholder']) . '" autocomplete="off" />';
                        echo '<input type="hidden" data-ll-recording-move-target value="" />';
                        echo '<button type="button" class="ll-word-edit-confirm-action ll-word-edit-confirm-action--move" data-ll-recording-move-confirm aria-label="' . esc_attr($edit_labels['move_recording']) . '" title="' . esc_attr($edit_labels['move_recording']) . '" disabled><svg viewBox="0 0 24 24" focusable="false" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg></button>';
                        echo '<button type="button" class="ll-word-edit-confirm-action" data-ll-recording-move-cancel aria-label="' . esc_attr($edit_labels['cancel']) . '" title="' . esc_attr($edit_labels['cancel']) . '"><svg viewBox="0 0 24 24" focusable="false" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"/></svg></button>';
                        echo '</div>';
                        echo '<div class="ll-word-edit-recording-move-status" data-ll-recording-move-status aria-live="polite"></div>';
                        echo '</div>';
                        echo '<div class="ll-word-edit-recording-fields">';
                        echo '<div class="ll-word-edit-review-field' . ($recording_text_needs_review ? ' is-needs-review' : '') . '" data-ll-recording-review-field="recording_text">';
                        echo '<div class="ll-word-edit-field-row">';
                        echo '<label class="ll-word-edit-label" for="' . esc_attr($recording_text_id) . '">' . esc_html($edit_labels['text']) . '</label>';
                        echo '<button type="button" class="ll-word-edit-review-toggle" data-ll-recording-review-toggle data-review-field="recording_text" data-review-on-label="' . esc_attr($edit_labels['clear_text_review']) . '" data-review-off-label="' . esc_attr($edit_labels['mark_text_review']) . '" aria-pressed="' . ($recording_text_needs_review ? 'true' : 'false') . '" aria-label="' . esc_attr($recording_text_review_label) . '" title="' . esc_attr($recording_text_review_label) . '"><svg viewBox="0 0 24 24" focusable="false" aria-hidden="true"><path d="M12 8v5M12 17h.01M10.3 4.8 3.3 17a2 2 0 0 0 1.7 3h14a2 2 0 0 0 1.7-3l-7-12.2a2 2 0 0 0-3.4 0Z" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></button>';
                        echo '</div>';
                        echo '<input type="text" class="ll-word-edit-input" id="' . esc_attr($recording_text_id) . '" data-ll-recording-input="text" value="' . esc_attr($recording_text) . '" />';
                        echo '</div>';
                        echo '<label class="ll-word-edit-label" for="' . esc_attr($recording_translation_id) . '">' . esc_html($edit_labels['translation']) . '</label>';
                        echo '<input type="text" class="ll-word-edit-input" id="' . esc_attr($recording_translation_id) . '" data-ll-recording-input="translation" value="' . esc_attr($recording_translation) . '" />';
                        echo '<div class="ll-word-edit-review-field' . ($recording_ipa_needs_review ? ' is-needs-review' : '') . '" data-ll-recording-review-field="recording_ipa">';
                        echo '<div class="ll-word-edit-field-row">';
                        echo '<label class="ll-word-edit-label" for="' . esc_attr($recording_ipa_id) . '">' . esc_html($edit_labels['ipa']) . '</label>';
                        echo '<button type="button" class="ll-word-edit-review-toggle" data-ll-recording-review-toggle data-review-field="recording_ipa" data-review-on-label="' . esc_attr($edit_labels['clear_ipa_review']) . '" data-review-off-label="' . esc_attr($edit_labels['mark_ipa_review']) . '" aria-pressed="' . ($recording_ipa_needs_review ? 'true' : 'false') . '" aria-label="' . esc_attr($recording_ipa_review_label) . '" title="' . esc_attr($recording_ipa_review_label) . '"><svg viewBox="0 0 24 24" focusable="false" aria-hidden="true"><path d="M12 8v5M12 17h.01M10.3 4.8 3.3 17a2 2 0 0 0 1.7 3h14a2 2 0 0 0 1.7-3l-7-12.2a2 2 0 0 0-3.4 0Z" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></button>';
                        echo '</div>';
                        if ($recording_audio_url !== '') {
                            echo '<div class="ll-word-edit-ipa-audio" data-ll-ipa-audio aria-hidden="true">';
                            echo '<div class="ll-word-edit-ipa-waveform" data-ll-ipa-waveform aria-hidden="true">';
                            echo '<canvas class="ll-word-edit-ipa-waveform-canvas"></canvas>';
                            echo '</div>';
                            echo '<audio class="ll-word-edit-ipa-audio-player" controls preload="none" src="' . esc_url($recording_audio_url) . '"></audio>';
                            echo '</div>';
                            echo '<div class="ll-word-edit-processing" data-ll-word-edit-processing>';
                            echo '<div class="ll-word-edit-processing-head">';
                            echo '<span class="ll-word-edit-processing-title">' . esc_html($edit_labels['processing']) . '</span>';
                            echo '<span class="ll-word-edit-processing-source" data-ll-processing-source-label>' . esc_html($source_label) . '</span>';
                            echo '</div>';
                            echo '<div class="ll-word-edit-processing-options">';
                            echo '<label class="ll-word-edit-processing-option" for="' . esc_attr($trim_input_id) . '"><input type="checkbox" id="' . esc_attr($trim_input_id) . '" data-ll-processing-option="trim" checked /> <span>' . esc_html($edit_labels['auto_trim']) . '</span></label>';
                            echo '<label class="ll-word-edit-processing-option" for="' . esc_attr($noise_input_id) . '"><input type="checkbox" id="' . esc_attr($noise_input_id) . '" data-ll-processing-option="noise" checked /> <span>' . esc_html($edit_labels['noise_reduction']) . '</span></label>';
                            echo '<label class="ll-word-edit-processing-option" for="' . esc_attr($loudness_input_id) . '"><input type="checkbox" id="' . esc_attr($loudness_input_id) . '" data-ll-processing-option="loudness" checked /> <span>' . esc_html($edit_labels['normalize_loudness']) . '</span></label>';
                            echo '</div>';
                            echo '<div class="ll-word-edit-processing-waveform" data-ll-processing-waveform aria-label="' . esc_attr($edit_labels['waveform_label']) . '" data-loading-label="' . esc_attr($edit_labels['waveform_loading']) . '" data-unavailable-label="' . esc_attr($edit_labels['waveform_unavailable']) . '" data-start-label="' . esc_attr($edit_labels['trim_start_handle']) . '" data-end-label="' . esc_attr($edit_labels['trim_end_handle']) . '">';
                            echo '<canvas class="ll-word-edit-processing-waveform-canvas" data-ll-processing-waveform-canvas></canvas>';
                            echo '<span class="ll-word-edit-processing-waveform-message" data-ll-processing-waveform-message>' . esc_html($edit_labels['waveform_loading']) . '</span>';
                            echo '</div>';
                            echo '<div class="ll-word-edit-processing-actions">';
                            echo '<button type="button" class="ll-word-edit-processing-play-selection" data-ll-processing-play-selection>' . esc_html($edit_labels['play_selection']) . '</button>';
                            echo '<button type="button" class="ll-word-edit-process-audio" data-ll-process-recording-audio>' . esc_html($process_button_label) . '</button>';
                            echo '<a class="ll-word-edit-processing-download-audio" data-ll-processing-download-audio href="' . esc_url($recording_audio_url) . '" download aria-label="' . esc_attr($edit_labels['download_audio']) . '" title="' . esc_attr($edit_labels['download_audio']) . '"><span class="ll-word-edit-processing-download-icon" aria-hidden="true"></span></a>';
                            echo '<span class="ll-word-edit-processing-status" data-ll-processing-status aria-live="polite"></span>';
                            echo '</div>';
                            echo '</div>';
                        }
                        echo '<div class="ll-word-edit-ipa-target" data-ll-ipa-target aria-hidden="true" aria-label="' . esc_attr__('Transcription guide', 'll-tools-text-domain') . '">';
                        echo '<button type="button" class="ll-word-edit-ipa-shift ll-word-edit-ipa-shift--prev" data-ll-ipa-shift="prev" aria-label="' . esc_attr__('Previous letter', 'll-tools-text-domain') . '" title="' . esc_attr__('Previous letter', 'll-tools-text-domain') . '"><span aria-hidden="true">&lt;</span></button>';
                        echo '<div class="ll-word-edit-ipa-target-text" data-ll-ipa-target-text aria-live="polite"></div>';
                        echo '<button type="button" class="ll-word-edit-ipa-shift ll-word-edit-ipa-shift--next" data-ll-ipa-shift="next" aria-label="' . esc_attr__('Next letter', 'll-tools-text-domain') . '" title="' . esc_attr__('Next letter', 'll-tools-text-domain') . '"><span aria-hidden="true">&gt;</span></button>';
                        echo '</div>';
                        echo '<div class="ll-word-edit-input-wrap ll-word-edit-input-wrap--ipa">';
                        echo '<input type="text" class="ll-word-edit-input ll-word-edit-input--ipa" id="' . esc_attr($recording_ipa_id) . '" data-ll-recording-input="ipa" value="' . esc_attr($recording_ipa) . '" />';
                        echo '</div>';
                        echo '<div class="ll-word-edit-ipa-suggestions" data-ll-ipa-suggestions aria-hidden="true" aria-label="' . esc_attr((string) ($transcription_config['suggestions_aria_label'] ?? __('IPA suggestions', 'll-tools-text-domain'))) . '"></div>';
                        if ($secondary_text_supports_superscript) {
                            echo '<button type="button" class="ll-word-edit-ipa-superscript" data-ll-ipa-superscript aria-hidden="true" aria-label="' . esc_attr($edit_labels['ipa_superscript']) . '" title="' . esc_attr($edit_labels['ipa_superscript']) . '"><span aria-hidden="true">x&sup2;</span></button>';
                        }
                        echo '<div class="ll-word-edit-ipa-keyboard" data-ll-ipa-keyboard aria-hidden="true" aria-label="' . esc_attr((string) ($transcription_config['keyboard_aria_label'] ?? __('IPA symbols', 'll-tools-text-domain'))) . '"></div>';
                        echo '</div>';
                        echo '</div>';
                        echo '</div>';
                    }
                    echo '</div>';
                }

                echo '<div class="ll-word-edit-danger" data-ll-word-edit-danger>';
                echo '<button type="button" class="ll-word-edit-danger-toggle" data-ll-word-delete-toggle aria-label="' . esc_attr($edit_labels['delete_word']) . '" title="' . esc_attr($edit_labels['delete_word']) . '">';
                echo '<svg viewBox="0 0 24 24" focusable="false" aria-hidden="true"><path d="M4 7h16M10 11v6M14 11v6M9 7l1-2h4l1 2M6 7l1 14h10l1-14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
                echo '</button>';
                echo '<div class="ll-word-edit-confirm ll-word-edit-confirm--delete-word" data-ll-word-delete-confirm hidden>';
                echo '<span class="ll-word-edit-confirm-icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false" aria-hidden="true"><path d="M4 7h16M10 11v6M14 11v6M9 7l1-2h4l1 2M6 7l1 14h10l1-14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></span>';
                echo '<span class="ll-word-edit-confirm-text">' . esc_html($edit_labels['delete_word_prompt']) . '</span>';
                echo '<button type="button" class="ll-word-edit-confirm-action ll-word-edit-confirm-action--danger" data-ll-word-delete-confirm-action aria-label="' . esc_attr($edit_labels['delete_word_confirm']) . '" title="' . esc_attr($edit_labels['delete_word_confirm']) . '"><svg viewBox="0 0 24 24" focusable="false" aria-hidden="true"><path d="M5 13l4 4L19 7" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg></button>';
                echo '<button type="button" class="ll-word-edit-confirm-action" data-ll-word-delete-cancel aria-label="' . esc_attr($edit_labels['cancel']) . '" title="' . esc_attr($edit_labels['cancel']) . '"><svg viewBox="0 0 24 24" focusable="false" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"/></svg></button>';
                echo '</div>';
                echo '</div>';

                echo '</div>';
                echo '<div class="ll-word-edit-footer">';
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
                echo '</div>';
            }

            // Audio buttons
            if ($has_recordings || $recording_launch_html !== '') {
                $use_recording_rows = ($has_recording_caption && !$hide_text_for_word) || $show_lesson_recording_edit_triggers || $show_word_interlinears;
                if ($use_recording_rows) {
                    $recordings_wrap_classes = 'll-word-recordings';
                    if ($has_recording_caption && !$hide_text_for_word) {
                        $recordings_wrap_classes .= ' ll-word-recordings--with-text';
                    }
                    if ($show_word_interlinears) {
                        $recordings_wrap_classes .= ' ll-word-recordings--with-interlinear';
                    }
                    $recordings_html .= '<div class="' . esc_attr($recordings_wrap_classes) . '" aria-label="' . esc_attr__('Recordings', 'll-tools-text-domain') . '">';
                    $recording_interlinear_word_fallback = count($recording_rows) === 1 ? (string) $word_text : '';
                    foreach ($recording_rows as $row) {
                        $recording_interlinear_html = $show_word_interlinears
                            ? ll_tools_render_recording_interlinear_block((int) $lesson_id, $row, $recording_interlinear_word_fallback)
                            : '';
                        $row_id_attr = !empty($row['id']) ? ' data-recording-id="' . esc_attr((int) $row['id']) . '"' : '';
                        $row_classes = 'll-word-recording-row';
                        if ($show_lesson_recording_edit_triggers && !empty($row['id']) && !empty($row['edit_label'])) {
                            $row_classes .= ' ll-word-recording-row--editable';
                        }
                        if (!empty($row['secondary'])) {
                            $row_classes .= ' ll-word-recording-row--secondary';
                        }
                        if ($recording_interlinear_html !== '') {
                            $row_classes .= ' ll-word-recording-row--has-interlinear';
                        }
                        $row_visibility_note = trim((string) ($row['visibility_note'] ?? ''));
                        $row_secondary_attr = !empty($row['secondary']) ? ' data-ll-recording-secondary="1"' : '';
                        $recordings_html .= '<div class="' . esc_attr($row_classes) . '"' . $row_id_attr . $row_secondary_attr . '>';
                        $recordings_html .= $row['button'];
                        if ($row_visibility_note !== '') {
                            $recordings_html .= '<span class="ll-word-recording-visibility-badge" title="' . esc_attr($row_visibility_note) . '">' . esc_html__('Secondary', 'll-tools-text-domain') . '</span>';
                        }
                        if (!empty($row['text']) || !empty($row['translation']) || !empty($row['ipa'])) {
                            $row_review_fields = isset($row['review_fields']) && is_array($row['review_fields'])
                                ? (array) $row['review_fields']
                                : [];
                            $recordings_html .= '<span class="ll-word-recording-text">';
                            if (!empty($row['text'])) {
                                $text_class = 'll-word-recording-text-main';
                                if (!empty($row_review_fields['recording_text'])) {
                                    $text_class .= ' ll-word-recording-text-main--needs-review';
                                }
                                $recordings_html .= '<span class="' . esc_attr($text_class) . '" dir="auto">' . ll_tools_esc_html_display($row['text']) . '</span>';
                            }
                            if (!empty($row['translation'])) {
                                $recordings_html .= '<span class="ll-word-recording-text-translation" dir="auto">' . ll_tools_esc_html_display($row['translation']) . '</span>';
                            }
                            if (!empty($row['ipa'])) {
                                $ipa_class = 'll-word-recording-ipa' . ($secondary_text_uses_ipa_font ? ' ll-ipa' : '');
                                if (!empty($row_review_fields['recording_ipa'])) {
                                    $ipa_class .= ' ll-word-recording-ipa--needs-review';
                                }
                                $recordings_html .= '<span class="' . esc_attr($ipa_class) . '">' . ll_tools_word_grid_format_ipa_display_html((string) $row['ipa'], $transcription_mode) . '</span>';
                            }
                            $recordings_html .= '</span>';
                        }
                        if ($show_lesson_recording_edit_triggers && !empty($row['id']) && !empty($row['edit_label'])) {
                            $recordings_html .= '<button type="button" class="ll-word-recording-edit-trigger" data-ll-recording-edit-trigger data-recording-id="' . esc_attr((int) $row['id']) . '" aria-label="' . esc_attr((string) $row['edit_label']) . '" title="' . esc_attr((string) $row['edit_label']) . '">';
                            $recordings_html .= '<svg viewBox="0 0 24 24" focusable="false" aria-hidden="true"><path d="M4 20.5h4l10-10-4-4-10 10v4z" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M13.5 6.5l4 4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
                            $recordings_html .= '</button>';
                        }
                        if ($recording_interlinear_html !== '') {
                            $recordings_html .= $recording_interlinear_html;
                        }
                        $recordings_html .= '</div>';
                    }
                    if ($recording_launch_html !== '') {
                        $recordings_html .= '<div class="ll-word-recording-row ll-word-recording-row--launch">';
                        $recordings_html .= $recording_launch_html;
                        $recordings_html .= '</div>';
                    }
                    $recordings_html .= '</div>';
                } else {
                    $recordings_html .= '<div class="ll-word-recordings" aria-label="' . esc_attr__('Recordings', 'll-tools-text-domain') . '">';
                    foreach ($recording_rows as $row) {
                        $recordings_html .= $row['button'];
                    }
                    if ($recording_launch_html !== '') {
                        $recordings_html .= $recording_launch_html;
                    }
                    $recordings_html .= '</div>';
                }
                echo $recordings_html;
            }
            if ($can_manage_internal_notes) {
                echo ll_tools_render_internal_review_note_field((int) $word_id, 'word', (int) $wordset_id); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
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

function ll_tools_word_grid_parse_recordings_payload($raw, string $transcription_mode = 'ipa'): array {
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
        $text_value = array_key_exists('text', $entry) ? $entry['text'] : ($entry['recording_text'] ?? '');
        $translation_value = array_key_exists('translation', $entry) ? $entry['translation'] : ($entry['recording_translation'] ?? '');
        $ipa_value = array_key_exists('ipa', $entry) ? $entry['ipa'] : ($entry['recording_ipa'] ?? '');
        $review_fields = [];
        $review_fields_submitted = array_key_exists('review_fields', $entry);
        if ($review_fields_submitted) {
            if (function_exists('ll_tools_ipa_keyboard_normalize_review_fields')) {
                $review_fields = ll_tools_ipa_keyboard_normalize_review_fields($entry['review_fields']);
            } else {
                foreach ((array) $entry['review_fields'] as $field => $enabled) {
                    if (!$enabled) {
                        continue;
                    }
                    if (is_string($field) && in_array($field, ['recording_text', 'recording_ipa'], true)) {
                        $review_fields[] = $field;
                    }
                }
            }
        }
        $parsed[] = [
            'id' => $recording_id,
            'text' => sanitize_text_field((string) $text_value),
            'translation' => sanitize_text_field((string) $translation_value),
            'ipa' => ll_tools_word_grid_sanitize_ipa((string) $ipa_value, $transcription_mode),
            'review_fields' => array_values(array_unique($review_fields)),
            'review_fields_submitted' => $review_fields_submitted,
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

function ll_tools_get_lesson_word_ids_for_statuses(int $wordset_id, int $category_id, array $post_statuses): array {
    $wordset_id = (int) $wordset_id;
    $category_id = (int) $category_id;
    if ($wordset_id <= 0 || $category_id <= 0) {
        return [];
    }

    $post_statuses = array_values(array_unique(array_filter(array_map('sanitize_key', $post_statuses), static function (string $status): bool {
        return $status !== '';
    })));
    if (empty($post_statuses)) {
        $post_statuses = ['publish'];
    }

    static $request_cache = [];
    $cache_key = $wordset_id . ':' . $category_id . ':' . implode(',', $post_statuses);
    if (array_key_exists($cache_key, $request_cache)) {
        return $request_cache[$cache_key];
    }

    $query = new WP_Query([
        'post_type'      => 'words',
        'post_status'    => count($post_statuses) === 1 ? $post_statuses[0] : $post_statuses,
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
        $request_cache[$cache_key] = [];
        return [];
    }

    if (function_exists('ll_tools_word_grid_filter_word_ids_to_deepest_category')) {
        $word_ids = ll_tools_word_grid_filter_word_ids_to_deepest_category($word_ids, $category_id);
    } elseif (function_exists('ll_get_deepest_categories')) {
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
    if (function_exists('ll_tools_filter_specific_wrong_answer_only_word_ids')) {
        $word_ids = ll_tools_filter_specific_wrong_answer_only_word_ids($word_ids);
    }

    $request_cache[$cache_key] = $word_ids;
    return $word_ids;
}

function ll_tools_get_lesson_word_ids_for_transcription(int $wordset_id, int $category_id): array {
    return ll_tools_get_lesson_word_ids_for_statuses($wordset_id, $category_id, ['publish']);
}

function ll_tools_get_lesson_word_ids_for_order(int $wordset_id, int $category_id, bool $include_drafts = false): array {
    return ll_tools_get_lesson_word_ids_for_statuses(
        $wordset_id,
        $category_id,
        $include_drafts ? ['publish', 'draft'] : ['publish']
    );
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

function ll_tools_word_grid_user_can_manage_wordset_scope(int $wordset_id): bool {
    $wordset_id = (int) $wordset_id;
    if ($wordset_id <= 0) {
        return false;
    }

    if (function_exists('ll_tools_current_user_can_manage_wordset_content')) {
        return ll_tools_current_user_can_manage_wordset_content($wordset_id);
    }

    return current_user_can('manage_options');
}

function ll_tools_word_grid_user_can_manage_word(int $word_id, int $preferred_wordset_id = 0): bool {
    $word_id = (int) $word_id;
    if ($word_id <= 0) {
        return false;
    }

    // Administrators can edit words even when they are not assigned to a wordset yet.
    if (current_user_can('manage_options')) {
        return true;
    }

    $preferred_wordset_id = (int) $preferred_wordset_id;
    if ($preferred_wordset_id > 0 && has_term($preferred_wordset_id, 'wordset', $word_id)) {
        return ll_tools_word_grid_user_can_manage_wordset_scope($preferred_wordset_id);
    }

    $wordset_ids = wp_get_post_terms($word_id, 'wordset', ['fields' => 'ids']);
    if (is_wp_error($wordset_ids)) {
        return false;
    }

    foreach ((array) $wordset_ids as $wordset_id) {
        $wordset_id = (int) $wordset_id;
        if ($wordset_id > 0 && ll_tools_word_grid_user_can_manage_wordset_scope($wordset_id)) {
            return true;
        }
    }

    return false;
}

function ll_tools_word_grid_word_matches_search(int $word_id, string $query): bool {
    $query = trim($query);
    if ($query === '') {
        return true;
    }

    $haystacks = [get_the_title($word_id)];
    $display_values = ll_tools_word_grid_resolve_display_text($word_id);
    $haystacks[] = (string) ($display_values['word_text'] ?? '');
    $haystacks[] = (string) ($display_values['translation_text'] ?? '');
    $haystacks[] = (string) get_post_meta($word_id, 'word_translation', true);
    $haystacks[] = (string) get_post_meta($word_id, 'word_english_meaning', true);

    $needle = function_exists('mb_strtolower') ? mb_strtolower($query, 'UTF-8') : strtolower($query);
    foreach ($haystacks as $haystack) {
        $candidate = trim(wp_strip_all_tags((string) $haystack));
        if ($candidate === '') {
            continue;
        }
        $candidate = html_entity_decode($candidate, ENT_QUOTES, 'UTF-8');
        $candidate_lower = function_exists('mb_strtolower') ? mb_strtolower($candidate, 'UTF-8') : strtolower($candidate);
        if (function_exists('mb_strpos')) {
            if (mb_strpos($candidate_lower, $needle, 0, 'UTF-8') !== false) {
                return true;
            }
        } elseif (strpos($candidate_lower, $needle) !== false) {
            return true;
        }
    }

    return false;
}

function ll_tools_word_grid_get_word_move_choice(int $word_id): array {
    $word_id = (int) $word_id;
    if ($word_id <= 0 || get_post_type($word_id) !== 'words') {
        return [];
    }

    $display_values = ll_tools_word_grid_resolve_display_text($word_id);
    $word_text = trim((string) ($display_values['word_text'] ?? ''));
    $translation_text = trim((string) ($display_values['translation_text'] ?? ''));
    $title = trim((string) get_the_title($word_id));
    $primary = $word_text !== '' ? $word_text : $title;
    $secondary = $translation_text;
    if ($secondary !== '' && $secondary === $primary) {
        $secondary = '';
    }

    $label = $primary !== '' ? $primary : sprintf(__('Word #%d', 'll-tools-text-domain'), $word_id);
    if ($secondary !== '') {
        $label .= ' - ' . $secondary;
    }

    $category_labels = [];
    $categories = wp_get_post_terms($word_id, 'word-category', ['orderby' => 'name', 'order' => 'ASC']);
    if (!is_wp_error($categories)) {
        foreach ((array) $categories as $category) {
            if (!($category instanceof WP_Term) || is_wp_error($category)) {
                continue;
            }
            $category_labels[] = (string) $category->name;
            if (count($category_labels) >= 2) {
                break;
            }
        }
    }

    return [
        'id' => $word_id,
        'label' => $label,
        'value' => $label,
        'word_text' => $word_text,
        'translation' => $translation_text,
        'status' => (string) get_post_status($word_id),
        'categories' => $category_labels,
    ];
}

function ll_tools_word_grid_search_words_for_move(string $query, int $wordset_id, int $exclude_word_id = 0, int $limit = 20): array {
    $wordset_id = (int) $wordset_id;
    if ($wordset_id <= 0) {
        return [];
    }

    $limit = max(1, min(50, (int) $limit));
    $exclude_word_id = max(0, (int) $exclude_word_id);
    $query = trim($query);
    $base_args = [
        'post_type'              => 'words',
        'post_status'            => ['publish', 'draft', 'pending', 'future', 'private'],
        'posts_per_page'         => max(80, $limit * 4),
        'fields'                 => 'ids',
        'no_found_rows'          => true,
        'orderby'                => 'title',
        'order'                  => 'ASC',
        'suppress_filters'       => true,
        'update_post_meta_cache' => true,
        'update_post_term_cache' => true,
        'tax_query'              => [
            [
                'taxonomy' => 'wordset',
                'field'    => 'term_id',
                'terms'    => [$wordset_id],
            ],
        ],
    ];
    if ($exclude_word_id > 0) {
        $base_args['post__not_in'] = [$exclude_word_id];
    }

    $candidate_ids = [];
    if ($query !== '') {
        $title_args = $base_args;
        $title_args['s'] = $query;
        $candidate_ids = array_merge($candidate_ids, array_map('intval', (array) get_posts($title_args)));

        $meta_args = $base_args;
        $meta_args['meta_query'] = [
            'relation' => 'OR',
            [
                'key'     => 'word_translation',
                'value'   => $query,
                'compare' => 'LIKE',
            ],
            [
                'key'     => 'word_english_meaning',
                'value'   => $query,
                'compare' => 'LIKE',
            ],
        ];
        $candidate_ids = array_merge($candidate_ids, array_map('intval', (array) get_posts($meta_args)));

        if (count(array_unique($candidate_ids)) < $limit) {
            $fallback_args = $base_args;
            $fallback_args['posts_per_page'] = 300;
            $candidate_ids = array_merge($candidate_ids, array_map('intval', (array) get_posts($fallback_args)));
        }
    } else {
        $base_args['posts_per_page'] = $limit;
        $candidate_ids = array_map('intval', (array) get_posts($base_args));
    }

    $seen = [];
    $choices = [];
    foreach ($candidate_ids as $candidate_id) {
        $candidate_id = (int) $candidate_id;
        if ($candidate_id <= 0 || $candidate_id === $exclude_word_id || isset($seen[$candidate_id])) {
            continue;
        }
        $seen[$candidate_id] = true;
        if ($query !== '' && !ll_tools_word_grid_word_matches_search($candidate_id, $query)) {
            continue;
        }
        if (!has_term($wordset_id, 'wordset', $candidate_id)) {
            continue;
        }

        $choice = ll_tools_word_grid_get_word_move_choice($candidate_id);
        if (empty($choice)) {
            continue;
        }
        $choices[] = $choice;
        if (count($choices) >= $limit) {
            break;
        }
    }

    return $choices;
}

function ll_tools_can_transcribe_recordings($wordset_ids = []): bool {
    if (function_exists('ll_tools_get_wordset_transcription_service_config')) {
        $service = ll_tools_get_wordset_transcription_service_config($wordset_ids, true);
        if (!empty($service['provider']) || !empty($wordset_ids)) {
            return !empty($service['enabled']);
        }
    }

    if (!function_exists('ll_tools_assemblyai_transcribe_audio_file') || !function_exists('ll_get_assemblyai_api_key')) {
        return false;
    }
    return ll_get_assemblyai_api_key() !== '';
}

function ll_tools_word_grid_get_transcription_target_meta_key(int $wordset_id): string {
    if ($wordset_id > 0 && function_exists('ll_tools_get_wordset_transcription_service_config')) {
        $service = ll_tools_get_wordset_transcription_service_config([$wordset_id], true);
        if (($service['target_meta_key'] ?? '') === 'recording_ipa') {
            return 'recording_ipa';
        }
    }

    return 'recording_text';
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

function ll_tools_word_grid_get_primary_recording_type_slug(int $recording_id): string {
    if ($recording_id <= 0) {
        return '';
    }

    $terms = wp_get_post_terms($recording_id, 'recording_type', ['fields' => 'slugs']);
    if (is_wp_error($terms) || empty($terms)) {
        return '';
    }

    return sanitize_key((string) reset($terms));
}

function ll_tools_word_grid_get_recording_type_label(string $recording_type): string {
    $recording_type = sanitize_key($recording_type);
    if ($recording_type === '') {
        return __('Recording', 'll-tools-text-domain');
    }

    $term = get_term_by('slug', $recording_type, 'recording_type');
    if ($term instanceof WP_Term && !is_wp_error($term) && trim((string) $term->name) !== '') {
        return (string) $term->name;
    }

    $labels = [
        'question' => __('Question', 'll-tools-text-domain'),
        'isolation' => __('Isolation', 'll-tools-text-domain'),
        'introduction' => __('Introduction', 'll-tools-text-domain'),
        'sentence' => __('Sentence', 'll-tools-text-domain'),
        'in-sentence' => __('In sentence', 'll-tools-text-domain'),
    ];

    return $labels[$recording_type] ?? ucwords(str_replace(['-', '_'], ' ', $recording_type));
}

function ll_tools_word_grid_get_recording_audio_url(int $recording_id): string {
    if ($recording_id <= 0) {
        return '';
    }

    $audio_path = trim((string) get_post_meta($recording_id, 'audio_file_path', true));
    if ($audio_path === '') {
        return '';
    }

    if (function_exists('ll_tools_resolve_audio_file_url')) {
        return (string) ll_tools_resolve_audio_file_url($audio_path);
    }

    return (0 === strpos($audio_path, 'http')) ? $audio_path : site_url($audio_path);
}

function ll_tools_word_grid_get_recording_audio_payload(int $recording_id): array {
    $recording_id = (int) $recording_id;
    $audio_path = $recording_id > 0 ? trim((string) get_post_meta($recording_id, 'audio_file_path', true)) : '';
    $audio_url = $audio_path !== '' ? ll_tools_word_grid_audio_url_from_path($audio_path) : '';
    $source_path = $audio_path;
    if ($recording_id > 0 && $audio_path !== '' && !preg_match('#^https?://#i', $audio_path) && function_exists('ll_tools_get_audio_processing_source_file_path')) {
        $source_path = ll_tools_get_audio_processing_source_file_path($recording_id, $audio_path);
    }
    $source_url = $source_path !== '' ? ll_tools_word_grid_audio_url_from_path($source_path) : $audio_url;
    $original_path = function_exists('ll_tools_get_audio_original_file_path')
        ? ll_tools_get_audio_original_file_path($recording_id)
        : '';
    $has_original_audio = $original_path !== ''
        && (!function_exists('ll_tools_audio_relative_file_exists') || ll_tools_audio_relative_file_exists($original_path));

    return [
        'audio_path' => $audio_path,
        'audio_url' => $audio_url,
        'processing_source_path' => $source_path,
        'processing_source_audio_url' => $source_url !== '' ? $source_url : $audio_url,
        'uses_original_audio' => ($audio_path !== '' && $source_path !== '' && $source_path !== $audio_path),
        'has_original_audio' => $has_original_audio,
    ];
}

function ll_tools_word_grid_get_recording_editor_payload(int $recording_id, string $transcription_mode = 'ipa'): array {
    $recording_id = (int) $recording_id;
    $recording = $recording_id > 0 ? get_post($recording_id) : null;
    if (!$recording || $recording->post_type !== 'word_audio') {
        return [];
    }

    $recording_type = ll_tools_word_grid_get_primary_recording_type_slug($recording_id);
    $audio_payload = ll_tools_word_grid_get_recording_audio_payload($recording_id);

    return [
        'id' => $recording_id,
        'type' => $recording_type,
        'label' => ll_tools_word_grid_get_recording_type_label($recording_type),
        'recording_text' => (string) get_post_meta($recording_id, 'recording_text', true),
        'recording_translation' => (string) get_post_meta($recording_id, 'recording_translation', true),
        'recording_ipa' => ll_tools_word_grid_normalize_ipa_output((string) get_post_meta($recording_id, 'recording_ipa', true), $transcription_mode),
        'audio_url' => (string) ($audio_payload['audio_url'] ?? ''),
        'processing_source_audio_url' => (string) ($audio_payload['processing_source_audio_url'] ?? ''),
        'uses_original_audio' => !empty($audio_payload['uses_original_audio']),
        'has_original_audio' => !empty($audio_payload['has_original_audio']),
    ];
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

function ll_tools_word_grid_bump_category_cache_for_words(array $word_ids, int $fallback_category_id = 0): void {
    if (!function_exists('ll_tools_bump_category_cache_version')) {
        return;
    }

    $touched = [];
    foreach ($word_ids as $word_id) {
        $word_id = (int) $word_id;
        if ($word_id <= 0) {
            continue;
        }
        $term_ids = wp_get_post_terms($word_id, 'word-category', ['fields' => 'ids']);
        if (is_wp_error($term_ids)) {
            continue;
        }
        foreach ($term_ids as $term_id) {
            $term_id = (int) $term_id;
            if ($term_id > 0) {
                $touched[$term_id] = true;
            }
        }
    }

    if (empty($touched) && $fallback_category_id > 0) {
        $touched[$fallback_category_id] = true;
    }

    if (!empty($touched)) {
        ll_tools_bump_category_cache_version(array_keys($touched));
    }
}

function ll_tools_word_grid_collect_audio_processing_settings_from_request(): array {
    $settings = [];
    foreach ([
        'trim_start' => 'trim_start',
        'trim_end' => 'trim_end',
        'source_samples' => 'source_samples',
        'sample_rate' => 'sample_rate',
    ] as $request_key => $setting_key) {
        if (!isset($_POST[$request_key])) {
            continue;
        }
        $settings[$setting_key] = max(0, (int) wp_unslash((string) $_POST[$request_key]));
    }

    foreach (['enable_trim', 'enable_noise', 'enable_loudness', 'used_original_source'] as $request_key) {
        if (!isset($_POST[$request_key])) {
            continue;
        }
        $settings[$request_key] = ((string) wp_unslash((string) $_POST[$request_key]) === '1') ? 1 : 0;
    }

    if (!empty($settings)) {
        $settings['processed_at'] = current_time('mysql');
        $settings['processed_by'] = (int) get_current_user_id();
    }

    return $settings;
}

function ll_tools_word_grid_should_require_uploaded_processed_audio_file(): bool {
    return (bool) apply_filters('ll_tools_word_grid_require_uploaded_processed_audio_file', true);
}

add_action('wp_ajax_ll_tools_word_grid_process_recording_audio', 'll_tools_word_grid_process_recording_audio_handler');
function ll_tools_word_grid_process_recording_audio_handler() {
    check_ajax_referer('ll_word_grid_edit', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(__('You must be logged in', 'll-tools-text-domain'), 401);
    }

    $recording_id = isset($_POST['recording_id']) ? absint($_POST['recording_id']) : 0;
    if ($recording_id <= 0 || empty($_FILES['audio']) || !is_array($_FILES['audio'])) {
        wp_send_json_error(__('Missing required audio data.', 'll-tools-text-domain'), 400);
    }

    $audio_post = get_post($recording_id);
    if (!$audio_post || $audio_post->post_type !== 'word_audio') {
        wp_send_json_error(__('Invalid recording.', 'll-tools-text-domain'), 404);
    }

    $parent_word_id = (int) wp_get_post_parent_id($recording_id);
    $parent_word = $parent_word_id > 0 ? get_post($parent_word_id) : null;
    if (!$parent_word || $parent_word->post_type !== 'words') {
        wp_send_json_error(__('Invalid parent word.', 'll-tools-text-domain'), 404);
    }

    $requested_wordset_id = isset($_POST['wordset_id']) ? absint($_POST['wordset_id']) : 0;
    if (!ll_tools_word_grid_user_can_manage_word($parent_word_id, $requested_wordset_id)) {
        wp_send_json_error(__('Forbidden', 'll-tools-text-domain'), 403);
    }

    if (!function_exists('ll_tools_validate_recording_upload_file')) {
        wp_send_json_error(__('Audio upload validation is unavailable.', 'll-tools-text-domain'), 500);
    }

    $file = (array) $_FILES['audio'];
    $require_uploaded_file = ll_tools_word_grid_should_require_uploaded_processed_audio_file();
    $upload_validation = ll_tools_validate_recording_upload_file($file, $require_uploaded_file);
    if (empty($upload_validation['valid'])) {
        $status = max(400, (int) ($upload_validation['status'] ?? 400));
        $message = (string) ($upload_validation['error'] ?? '');
        wp_send_json_error($message !== '' ? $message : __('Invalid audio upload.', 'll-tools-text-domain'), $status);
    }

    $previous_audio_file = trim((string) get_post_meta($recording_id, 'audio_file_path', true));
    $previous_source_payload = ll_tools_word_grid_get_recording_audio_payload($recording_id);
    $used_original_source = !empty($previous_source_payload['uses_original_audio']);

    $upload_dir = wp_upload_dir();
    if (!empty($upload_dir['error']) || empty($upload_dir['path']) || !wp_mkdir_p((string) $upload_dir['path'])) {
        wp_send_json_error(__('Upload directory is unavailable.', 'll-tools-text-domain'), 500);
    }

    $existing_recording_types = wp_get_post_terms($recording_id, 'recording_type', ['fields' => 'slugs']);
    $recording_type = isset($_POST['recording_type']) ? sanitize_key((string) wp_unslash($_POST['recording_type'])) : '';
    $recording_type_term_id = 0;
    if ($recording_type !== '') {
        $recording_type_term = get_term_by('slug', $recording_type, 'recording_type');
        if (!$recording_type_term || is_wp_error($recording_type_term)) {
            wp_send_json_error(__('Invalid recording type.', 'll-tools-text-domain'), 400);
        }
        $recording_type_term_id = (int) $recording_type_term->term_id;
        $recording_type = (string) $recording_type_term->slug;
    }
    $type_for_filename = $recording_type !== ''
        ? $recording_type
        : (!is_wp_error($existing_recording_types) && !empty($existing_recording_types) ? sanitize_key((string) $existing_recording_types[0]) : '');
    $type_suffix = $type_for_filename !== '' ? '_' . $type_for_filename : '';

    $validated_ext = sanitize_key((string) ($upload_validation['ext'] ?? ''));
    if ($validated_ext === '') {
        $validated_ext = 'wav';
    }
    $title_for_filename = sanitize_file_name((string) get_the_title($parent_word_id));
    if ($title_for_filename === '') {
        $title_for_filename = 'recording';
    }
    $file['name'] = $title_for_filename . $type_suffix . '_' . $recording_id . '_' . time() . '.' . $validated_ext;
    if (!empty($upload_validation['mime'])) {
        $file['type'] = (string) $upload_validation['mime'];
    }

    if (!function_exists('wp_handle_upload')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    $upload_overrides = [
        'test_form' => false,
        'test_type' => false,
        'mimes' => function_exists('ll_tools_get_allowed_recording_upload_mimes')
            ? ll_tools_get_allowed_recording_upload_mimes()
            : null,
    ];
    $upload_result = $require_uploaded_file
        ? wp_handle_upload($file, $upload_overrides)
        : wp_handle_sideload($file, $upload_overrides);
    if (!is_array($upload_result) || !empty($upload_result['error']) || empty($upload_result['file'])) {
        $upload_error = is_array($upload_result) ? (string) ($upload_result['error'] ?? '') : '';
        $message = $upload_error !== ''
            ? sprintf(
                /* translators: %s: upload subsystem error message */
                __('Failed to save file: %s', 'll-tools-text-domain'),
                $upload_error
            )
            : __('Failed to save file.', 'll-tools-text-domain');
        wp_send_json_error($message, 400);
    }

    $relative_path = str_replace(
        wp_normalize_path(untrailingslashit(ABSPATH)),
        '',
        wp_normalize_path((string) $upload_result['file'])
    );

    if ($previous_audio_file !== '' && function_exists('ll_tools_store_original_audio_if_enabled')) {
        ll_tools_store_original_audio_if_enabled($recording_id, $previous_audio_file, [], 'lesson_edit_processor');
    }
    update_post_meta($recording_id, 'audio_file_path', $relative_path);
    delete_post_meta($recording_id, '_ll_needs_audio_processing');
    update_post_meta($recording_id, '_ll_processed_audio_date', current_time('mysql'));
    delete_post_meta($recording_id, '_ll_needs_audio_review');

    $processing_settings = ll_tools_word_grid_collect_audio_processing_settings_from_request();
    if (!empty($processing_settings) && defined('LL_TOOLS_AUDIO_PROCESSING_SETTINGS_META_KEY')) {
        $processing_settings['used_original_source'] = $used_original_source ? 1 : 0;
        update_post_meta($recording_id, LL_TOOLS_AUDIO_PROCESSING_SETTINGS_META_KEY, $processing_settings);
    }

    if ($recording_type_term_id > 0) {
        wp_set_object_terms($recording_id, [$recording_type_term_id], 'recording_type', false);
    }

    wp_update_post([
        'ID' => $recording_id,
        'post_status' => 'publish',
    ]);

    $payload = ll_tools_word_grid_get_recording_audio_payload($recording_id);
    wp_send_json_success([
        'message' => __('Audio processed.', 'll-tools-text-domain'),
        'recording_id' => $recording_id,
        'word_id' => $parent_word_id,
        'recording_type' => $recording_type,
        'audio_url' => (string) ($payload['audio_url'] ?? ''),
        'processing_source_audio_url' => (string) ($payload['processing_source_audio_url'] ?? ''),
        'uses_original_audio' => !empty($payload['uses_original_audio']),
        'has_original_audio' => !empty($payload['has_original_audio']),
        'file_path' => $relative_path,
    ]);
}

add_action('wp_ajax_ll_tools_word_grid_create_lesson_word', 'll_tools_word_grid_create_lesson_word_handler');
function ll_tools_word_grid_create_lesson_word_handler() {
    check_ajax_referer('ll_word_grid_edit', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(__('You must be logged in', 'll-tools-text-domain'), 401);
    }

    $lesson_id = isset($_POST['lesson_id']) ? absint($_POST['lesson_id']) : 0;
    $lesson = $lesson_id > 0 ? get_post($lesson_id) : null;
    if (!$lesson || $lesson->post_type !== 'll_vocab_lesson') {
        wp_send_json_error(__('Invalid lesson', 'll-tools-text-domain'), 400);
    }

    [$wordset_id, $category_id] = ll_tools_get_vocab_lesson_ids_from_post($lesson_id);
    if ($wordset_id <= 0 || $category_id <= 0) {
        wp_send_json_error(__('Missing lesson metadata', 'll-tools-text-domain'), 400);
    }
    if (!ll_tools_word_grid_user_can_manage_wordset_scope($wordset_id)) {
        wp_send_json_error(__('Forbidden', 'll-tools-text-domain'), 403);
    }

    $wordset = get_term($wordset_id, 'wordset');
    $lesson_category_id = $category_id;
    $lesson_category = get_term($lesson_category_id, 'word-category');
    if (!($wordset instanceof WP_Term) || is_wp_error($wordset) || !($lesson_category instanceof WP_Term) || is_wp_error($lesson_category)) {
        wp_send_json_error(__('Lesson terms are missing.', 'll-tools-text-domain'), 400);
    }

    if (function_exists('ll_tools_get_effective_category_id_for_wordset')) {
        $effective_category_id = (int) ll_tools_get_effective_category_id_for_wordset($lesson_category_id, $wordset_id, false);
        if ($effective_category_id > 0) {
            $category_id = $effective_category_id;
        }
    }

    $category = get_term($category_id, 'word-category');
    if (!($category instanceof WP_Term) || is_wp_error($category)) {
        wp_send_json_error(__('Lesson terms are missing.', 'll-tools-text-domain'), 400);
    }
    if (!ll_tools_word_grid_category_belongs_to_wordset_editor_scope($category_id, $wordset_id)) {
        wp_send_json_error(__('This category is outside the lesson word set.', 'll-tools-text-domain'), 400);
    }

    $placeholder_title = __('New word', 'll-tools-text-domain');
    $word_id = wp_insert_post([
        'post_type'   => 'words',
        'post_status' => 'draft',
        'post_title'  => $placeholder_title,
        'post_author' => get_current_user_id(),
    ], true);
    if (is_wp_error($word_id) || (int) $word_id <= 0) {
        $message = is_wp_error($word_id)
            ? $word_id->get_error_message()
            : __('Unable to create the word.', 'll-tools-text-domain');
        wp_send_json_error(['message' => $message], 500);
    }
    $word_id = (int) $word_id;

    $wordset_result = wp_set_object_terms($word_id, [$wordset_id], 'wordset', false);
    if (is_wp_error($wordset_result)) {
        wp_delete_post($word_id, true);
        wp_send_json_error(['message' => $wordset_result->get_error_message()], 500);
    }

    $category_result = wp_set_post_terms($word_id, [$category_id], 'word-category', false);
    if (is_wp_error($category_result)) {
        wp_delete_post($word_id, true);
        wp_send_json_error(['message' => $category_result->get_error_message()], 500);
    }

    update_post_meta($word_id, '_ll_created_from_vocab_lesson_id', $lesson_id);
    clean_post_cache($word_id);

    if (function_exists('ll_tools_bump_category_cache_version')) {
        ll_tools_bump_category_cache_version(array_values(array_unique(array_filter([$lesson_category_id, $category_id]))));
    }
    if (function_exists('ll_tools_invalidate_wordset_page_lesson_cache')) {
        ll_tools_invalidate_wordset_page_lesson_cache();
    }
    wp_cache_delete('ll_vocab_lesson_deep_counts_' . $wordset_id, 'll_tools');

    $GLOBALS['ll_tools_word_grid_force_lesson_context'] = true;
    try {
        $html = ll_tools_word_grid_shortcode([
            'category'       => (string) $category->slug,
            'wordset'        => (string) $wordset->slug,
            'deepest_only'   => true,
            'lesson_id'      => $lesson_id,
            'word_ids'       => (string) $word_id,
            'editor_context' => true,
        ]);
    } finally {
        unset($GLOBALS['ll_tools_word_grid_force_lesson_context']);
    }

    if (function_exists('ll_tools_wordset_editor_log_action')) {
        ll_tools_wordset_editor_log_action(
            $wordset_id,
            'word_create',
            sprintf(__('Created a new word in "%s".', 'll-tools-text-domain'), (string) $category->name),
            [
                'word_id'     => $word_id,
                'lesson_id'   => $lesson_id,
                'category_id' => $category_id,
            ]
        );
    }

    wp_send_json_success([
        'message' => __('Word created.', 'll-tools-text-domain'),
        'word_id' => $word_id,
        'lesson_id' => $lesson_id,
        'wordset_id' => $wordset_id,
        'category_id' => $category_id,
        'lesson_category_id' => $lesson_category_id,
        'html' => is_string($html) ? $html : '',
        'word' => ll_tools_word_grid_get_word_meta_payload($word_id, $wordset_id),
    ]);
}

add_action('wp_ajax_ll_tools_word_grid_update_word', 'll_tools_word_grid_update_word_handler');
function ll_tools_word_grid_update_word_handler() {
    check_ajax_referer('ll_word_grid_edit', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(__('You must be logged in', 'll-tools-text-domain'), 401);
    }

    $word_id = (int) ($_POST['word_id'] ?? 0);
    if ($word_id <= 0) {
        wp_send_json_error(__('Missing word ID', 'll-tools-text-domain'), 400);
    }

    $word_post = get_post($word_id);
    if (!$word_post || $word_post->post_type !== 'words') {
        wp_send_json_error(__('Invalid word ID', 'll-tools-text-domain'), 404);
    }

    $requested_wordset_id = (int) ($_POST['wordset_id'] ?? 0);
    if (!ll_tools_word_grid_user_can_manage_word($word_id, $requested_wordset_id)) {
        wp_send_json_error(__('Forbidden', 'll-tools-text-domain'), 403);
    }
    $wordset_id = $requested_wordset_id;
    if ($wordset_id <= 0 || !has_term($wordset_id, 'wordset', $word_id)) {
        $wordset_id = ll_tools_word_grid_get_wordset_id_for_word($word_id);
    }
    $transcription_mode = function_exists('ll_tools_get_wordset_recording_transcription_mode')
        ? ll_tools_get_wordset_recording_transcription_mode([$wordset_id], true)
        : 'ipa';

    $category_update_result = null;
    $category_update_requested = array_key_exists('category_ids_submitted', $_POST);
    $lesson_category_id = isset($_POST['lesson_category_id']) ? absint($_POST['lesson_category_id']) : 0;
    $submitted_category_ids = [];
    $available_category_ids = [];
    if ($category_update_requested) {
        $submitted_category_ids_raw = isset($_POST['category_ids']) ? wp_unslash($_POST['category_ids']) : [];
        if (!is_array($submitted_category_ids_raw)) {
            $submitted_category_ids_raw = [$submitted_category_ids_raw];
        }
        $submitted_category_ids = ll_tools_word_grid_normalize_category_id_list($submitted_category_ids_raw);
        $available_category_rows = $wordset_id > 0 ? ll_tools_word_grid_get_category_editor_rows($wordset_id) : [];
        $available_category_ids = ll_tools_word_grid_normalize_category_id_list(wp_list_pluck($available_category_rows, 'id'));

        $available_category_lookup = array_fill_keys($available_category_ids, true);
        $has_out_of_scope_category = false;
        foreach ($submitted_category_ids as $submitted_category_id) {
            if (!isset($available_category_lookup[$submitted_category_id])) {
                $has_out_of_scope_category = true;
                break;
            }
        }
        if ($has_out_of_scope_category) {
            wp_send_json_error([
                'message' => __('Select categories from this word set.', 'll-tools-text-domain'),
            ], 400);
        }
    }

    $word_text_raw = sanitize_text_field($_POST['word_text'] ?? '');
    $word_text = function_exists('ll_sanitize_word_title_text')
        ? ll_sanitize_word_title_text($word_text_raw)
        : trim($word_text_raw);
    $translation_text = sanitize_text_field($_POST['word_translation'] ?? '');
    $translation_text = trim($translation_text);
    $pending_image_attachment_id = 0;
    $image_copyright_submitted = array_key_exists('image_copyright', $_POST);
    $image_copyright_info = '';
    if ($image_copyright_submitted) {
        $image_copyright_raw = isset($_POST['image_copyright']) ? wp_unslash($_POST['image_copyright']) : '';
        $image_copyright_info = sanitize_textarea_field((string) $image_copyright_raw);
    }
    $selected_word_image_id = isset($_POST['existing_word_image_id'])
        ? absint($_POST['existing_word_image_id'])
        : absint($_POST['word_image_id'] ?? 0);
    if (!empty($_FILES['word_image_file']) && is_array($_FILES['word_image_file'])) {
        $image_file = $_FILES['word_image_file'];
        $image_error = isset($image_file['error']) ? (int) $image_file['error'] : UPLOAD_ERR_NO_FILE;
        if ($image_error !== UPLOAD_ERR_NO_FILE) {
            if (!current_user_can('upload_files')) {
                wp_send_json_error([
                    'message' => __('You do not have permission to upload images.', 'll-tools-text-domain'),
                ], 403);
            }

            $image_alt_text = $word_text !== '' ? $word_text : trim((string) get_the_title($word_id));
            $pending_image_attachment_id = ll_tools_word_grid_store_uploaded_image_attachment(
                $image_file,
                $image_alt_text,
                $image_alt_text
            );
            if (is_wp_error($pending_image_attachment_id) || (int) $pending_image_attachment_id <= 0) {
                $message = is_wp_error($pending_image_attachment_id)
                    ? $pending_image_attachment_id->get_error_message()
                    : __('Unable to upload the image.', 'll-tools-text-domain');
                wp_send_json_error([
                    'message' => $message,
                ], 400);
            }
            $pending_image_attachment_id = (int) $pending_image_attachment_id;
        }
    }
    if ($pending_image_attachment_id > 0) {
        $selected_word_image_id = 0;
    }
    if ($selected_word_image_id > 0 && !ll_tools_word_grid_word_image_is_selectable_for_word($selected_word_image_id, $word_id, $wordset_id)) {
        wp_send_json_error([
            'message' => __('Select an image from this word set.', 'll-tools-text-domain'),
        ], 400);
    }

    if ($category_update_requested) {
        $category_update_result = ll_tools_word_grid_update_word_categories_for_wordset(
            $word_id,
            $wordset_id,
            $submitted_category_ids,
            $available_category_ids
        );
        if (is_wp_error($category_update_result)) {
            wp_send_json_error([
                'message' => $category_update_result->get_error_message(),
            ], 400);
        }
    }

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

    $word_note = trim((string) get_post_meta($word_id, 'll_word_usage_note', true));
    if (array_key_exists('word_note', $_POST)) {
        $word_note_raw = isset($_POST['word_note']) ? wp_unslash($_POST['word_note']) : '';
        $sanitized_note = sanitize_textarea_field((string) $word_note_raw);
        $sanitized_note = trim($sanitized_note);
        if ($sanitized_note !== '') {
            update_post_meta($word_id, 'll_word_usage_note', $sanitized_note);
        } else {
            delete_post_meta($word_id, 'll_word_usage_note');
        }
        $word_note = $sanitized_note;
    }

    $specific_wrong_answer_texts = function_exists('ll_tools_get_word_specific_wrong_answer_texts')
        ? ll_tools_get_word_specific_wrong_answer_texts($word_id)
        : [];
    if (array_key_exists('specific_wrong_answer_texts', $_POST) && defined('LL_TOOLS_SPECIFIC_WRONG_ANSWER_TEXTS_META_KEY')) {
        $raw_wrong_answer_texts = isset($_POST['specific_wrong_answer_texts'])
            ? wp_unslash($_POST['specific_wrong_answer_texts'])
            : '';
        $exclude_wrong_answer_text = (string) get_the_title($word_id);
        $specific_wrong_answer_texts = function_exists('ll_tools_normalize_specific_wrong_answer_texts')
            ? ll_tools_normalize_specific_wrong_answer_texts($raw_wrong_answer_texts, $exclude_wrong_answer_text)
            : [];

        if (!empty($specific_wrong_answer_texts)) {
            update_post_meta($word_id, LL_TOOLS_SPECIFIC_WRONG_ANSWER_TEXTS_META_KEY, $specific_wrong_answer_texts);
        } else {
            delete_post_meta($word_id, LL_TOOLS_SPECIFIC_WRONG_ANSWER_TEXTS_META_KEY);
        }
    }

    $dictionary_entry_id = function_exists('ll_tools_get_word_dictionary_entry_id')
        ? (int) ll_tools_get_word_dictionary_entry_id($word_id)
        : 0;
    $dictionary_entry_title = $dictionary_entry_id > 0
        ? trim((string) get_the_title($dictionary_entry_id))
        : '';
    $dictionary_entry_submitted = array_key_exists('dictionary_entry_id', $_POST)
        || array_key_exists('dictionary_entry_title', $_POST);
    if ($dictionary_entry_submitted && function_exists('ll_tools_assign_dictionary_entry_to_word')) {
        $submitted_dictionary_entry_id = isset($_POST['dictionary_entry_id'])
            ? absint($_POST['dictionary_entry_id'])
            : 0;
        $submitted_dictionary_entry_title = isset($_POST['dictionary_entry_title'])
            ? sanitize_text_field(wp_unslash((string) $_POST['dictionary_entry_title']))
            : '';

        $dictionary_result = ll_tools_assign_dictionary_entry_to_word(
            $word_id,
            $submitted_dictionary_entry_id,
            $submitted_dictionary_entry_title
        );
        if (!is_wp_error($dictionary_result)) {
            $dictionary_entry_id = isset($dictionary_result['entry_id']) ? (int) $dictionary_result['entry_id'] : 0;
            $dictionary_entry_title = isset($dictionary_result['entry_title']) ? trim((string) $dictionary_result['entry_title']) : '';
        }
    }
    if (!$dictionary_entry_submitted && function_exists('ll_tools_get_word_dictionary_entry_id')) {
        $dictionary_entry_id = (int) ll_tools_get_word_dictionary_entry_id($word_id);
        $dictionary_entry_title = $dictionary_entry_id > 0
            ? trim((string) get_the_title($dictionary_entry_id))
            : '';
    }

    $pos_updated = array_key_exists('part_of_speech', $_POST);
    $pos_slug = '';
    $pos_label = '';
    if ($pos_updated) {
        $pos_slug = sanitize_text_field($_POST['part_of_speech'] ?? '');
        $pos_slug = sanitize_title($pos_slug);
        if ($pos_slug !== '') {
            $pos_term = get_term_by('slug', $pos_slug, 'part_of_speech');
            if ($pos_term && !is_wp_error($pos_term)) {
                wp_set_object_terms($word_id, [$pos_term->term_id], 'part_of_speech', false);
                $pos_slug = (string) $pos_term->slug;
                $pos_label = (string) $pos_term->name;
            } else {
                $pos_slug = '';
                wp_set_object_terms($word_id, [], 'part_of_speech', false);
            }
        } else {
            wp_set_object_terms($word_id, [], 'part_of_speech', false);
        }
    }
    if (!$pos_updated) {
        $pos_terms = wp_get_post_terms($word_id, 'part_of_speech', ['orderby' => 'name', 'order' => 'ASC']);
        if (!is_wp_error($pos_terms) && !empty($pos_terms)) {
            $pos_slug = (string) $pos_terms[0]->slug;
            $pos_label = (string) $pos_terms[0]->name;
        }
    }

    if ($wordset_id <= 0) {
        $wordset_id = ll_tools_word_grid_get_wordset_id_for_word($word_id);
    }
    $gender_enabled = ($wordset_id > 0 && function_exists('ll_tools_wordset_has_grammatical_gender'))
        ? ll_tools_wordset_has_grammatical_gender($wordset_id)
        : false;
    $plurality_enabled = ($wordset_id > 0 && function_exists('ll_tools_wordset_has_plurality'))
        ? ll_tools_wordset_has_plurality($wordset_id)
        : false;
    $verb_tense_enabled = ($wordset_id > 0 && function_exists('ll_tools_wordset_has_verb_tense'))
        ? ll_tools_wordset_has_verb_tense($wordset_id)
        : false;
    $verb_mood_enabled = ($wordset_id > 0 && function_exists('ll_tools_wordset_has_verb_mood'))
        ? ll_tools_wordset_has_verb_mood($wordset_id)
        : false;
    $is_noun = ($pos_slug === 'noun');
    $is_verb = ($pos_slug === 'verb');
    $gender_value = trim((string) get_post_meta($word_id, 'll_grammatical_gender', true));
    $gender_label = '';
    $gender_display = [
        'value' => '',
        'label' => '',
        'role' => 'other',
        'style' => '',
        'html' => '',
    ];
    $gender_submitted = array_key_exists('grammatical_gender', $_POST);
    if ($gender_submitted || $pos_updated) {
        $submitted_gender = sanitize_text_field($_POST['grammatical_gender'] ?? '');
        $submitted_gender = trim($submitted_gender);
        if (!$gender_enabled || !$is_noun) {
            if ($gender_value !== '') {
                delete_post_meta($word_id, 'll_grammatical_gender');
            }
            $gender_value = '';
        } else {
            $allowed = function_exists('ll_tools_wordset_get_gender_options')
                ? ll_tools_wordset_get_gender_options($wordset_id)
                : [];
            if ($submitted_gender === '') {
                delete_post_meta($word_id, 'll_grammatical_gender');
                $gender_value = '';
            } elseif (in_array($submitted_gender, $allowed, true) || $submitted_gender === $gender_value) {
                update_post_meta($word_id, 'll_grammatical_gender', $submitted_gender);
                $gender_value = $submitted_gender;
            } else {
                delete_post_meta($word_id, 'll_grammatical_gender');
                $gender_value = '';
            }
        }
    }
    if (!$gender_enabled || !$is_noun) {
        $gender_value = '';
        $gender_label = '';
    } else {
        if ($gender_value !== '' && function_exists('ll_tools_wordset_get_gender_display_data')) {
            $gender_display = ll_tools_wordset_get_gender_display_data($wordset_id, $gender_value);
            $gender_label = (string) ($gender_display['label'] ?? '');
        } elseif ($gender_value !== '' && function_exists('ll_tools_wordset_get_gender_label')) {
            $gender_label = ll_tools_wordset_get_gender_label($wordset_id, $gender_value);
            $gender_display['label'] = $gender_label;
        } else {
            $gender_label = $gender_value;
            $gender_display['label'] = $gender_label;
        }
    }
    $plurality_value = trim((string) get_post_meta($word_id, 'll_grammatical_plurality', true));
    $plurality_label = '';
    $plurality_submitted = array_key_exists('grammatical_plurality', $_POST);
    if ($plurality_submitted || $pos_updated) {
        $submitted_plurality = sanitize_text_field($_POST['grammatical_plurality'] ?? '');
        $submitted_plurality = trim($submitted_plurality);
        if (!$plurality_enabled || !$is_noun) {
            if ($plurality_value !== '') {
                delete_post_meta($word_id, 'll_grammatical_plurality');
            }
            $plurality_value = '';
        } else {
            $plurality_allowed = function_exists('ll_tools_wordset_get_plurality_options')
                ? ll_tools_wordset_get_plurality_options($wordset_id)
                : [];
            if ($submitted_plurality === '') {
                delete_post_meta($word_id, 'll_grammatical_plurality');
                $plurality_value = '';
            } elseif (in_array($submitted_plurality, $plurality_allowed, true) || $submitted_plurality === $plurality_value) {
                update_post_meta($word_id, 'll_grammatical_plurality', $submitted_plurality);
                $plurality_value = $submitted_plurality;
            } else {
                delete_post_meta($word_id, 'll_grammatical_plurality');
                $plurality_value = '';
            }
        }
    }
    if (!$plurality_enabled || !$is_noun) {
        $plurality_value = '';
        $plurality_label = '';
    } elseif ($plurality_value !== '' && function_exists('ll_tools_wordset_get_plurality_label')) {
        $plurality_label = ll_tools_wordset_get_plurality_label($wordset_id, $plurality_value);
    } else {
        $plurality_label = $plurality_value;
    }
    $verb_tense_value = trim((string) get_post_meta($word_id, 'll_verb_tense', true));
    $verb_tense_label = '';
    $verb_tense_submitted = array_key_exists('verb_tense', $_POST);
    if ($verb_tense_submitted || $pos_updated) {
        $submitted_verb_tense = sanitize_text_field($_POST['verb_tense'] ?? '');
        $submitted_verb_tense = trim($submitted_verb_tense);
        if (!$verb_tense_enabled || !$is_verb) {
            if ($verb_tense_value !== '') {
                delete_post_meta($word_id, 'll_verb_tense');
            }
            $verb_tense_value = '';
        } else {
            $verb_tense_allowed = function_exists('ll_tools_wordset_get_verb_tense_options')
                ? ll_tools_wordset_get_verb_tense_options($wordset_id)
                : [];
            if ($submitted_verb_tense === '') {
                delete_post_meta($word_id, 'll_verb_tense');
                $verb_tense_value = '';
            } elseif (in_array($submitted_verb_tense, $verb_tense_allowed, true) || $submitted_verb_tense === $verb_tense_value) {
                update_post_meta($word_id, 'll_verb_tense', $submitted_verb_tense);
                $verb_tense_value = $submitted_verb_tense;
            } else {
                delete_post_meta($word_id, 'll_verb_tense');
                $verb_tense_value = '';
            }
        }
    }
    if (!$verb_tense_enabled || !$is_verb) {
        $verb_tense_value = '';
        $verb_tense_label = '';
    } elseif ($verb_tense_value !== '' && function_exists('ll_tools_wordset_get_verb_tense_label')) {
        $verb_tense_label = ll_tools_wordset_get_verb_tense_label($wordset_id, $verb_tense_value);
    } else {
        $verb_tense_label = $verb_tense_value;
    }

    $verb_mood_value = trim((string) get_post_meta($word_id, 'll_verb_mood', true));
    $verb_mood_label = '';
    $verb_mood_submitted = array_key_exists('verb_mood', $_POST);
    if ($verb_mood_submitted || $pos_updated) {
        $submitted_verb_mood = sanitize_text_field($_POST['verb_mood'] ?? '');
        $submitted_verb_mood = trim($submitted_verb_mood);
        if (!$verb_mood_enabled || !$is_verb) {
            if ($verb_mood_value !== '') {
                delete_post_meta($word_id, 'll_verb_mood');
            }
            $verb_mood_value = '';
        } else {
            $verb_mood_allowed = function_exists('ll_tools_wordset_get_verb_mood_options')
                ? ll_tools_wordset_get_verb_mood_options($wordset_id)
                : [];
            if ($submitted_verb_mood === '') {
                delete_post_meta($word_id, 'll_verb_mood');
                $verb_mood_value = '';
            } elseif (in_array($submitted_verb_mood, $verb_mood_allowed, true) || $submitted_verb_mood === $verb_mood_value) {
                update_post_meta($word_id, 'll_verb_mood', $submitted_verb_mood);
                $verb_mood_value = $submitted_verb_mood;
            } else {
                delete_post_meta($word_id, 'll_verb_mood');
                $verb_mood_value = '';
            }
        }
    }
    if (!$verb_mood_enabled || !$is_verb) {
        $verb_mood_value = '';
        $verb_mood_label = '';
    } elseif ($verb_mood_value !== '' && function_exists('ll_tools_wordset_get_verb_mood_label')) {
        $verb_mood_label = ll_tools_wordset_get_verb_mood_label($wordset_id, $verb_mood_value);
    } else {
        $verb_mood_label = $verb_mood_value;
    }

    $recordings_payload = ll_tools_word_grid_parse_recordings_payload($_POST['recordings'] ?? '', $transcription_mode);
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
        $review_fields = isset($recording['review_fields']) && is_array($recording['review_fields']) ? (array) $recording['review_fields'] : [];

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
        if (!empty($recording['review_fields_submitted']) && function_exists('ll_tools_ipa_keyboard_set_recording_review_state')) {
            $review_map = array_fill_keys($review_fields, true);
            foreach (['recording_text', 'recording_ipa'] as $field_key) {
                ll_tools_ipa_keyboard_set_recording_review_state($recording_id, !empty($review_map[$field_key]), $field_key);
            }
        }

        $recording_review_fields = function_exists('ll_tools_ipa_keyboard_get_recording_review_fields')
            ? ll_tools_ipa_keyboard_get_recording_review_fields($recording_id)
            : ['recording_text' => false, 'recording_ipa' => false];
        $recordings_out[] = [
            'id' => $recording_id,
            'recording_text' => $recording_text,
            'recording_translation' => $recording_translation,
            'recording_ipa' => ll_tools_word_grid_normalize_ipa_output($recording_ipa, $transcription_mode),
            'review_fields' => $recording_review_fields,
            'needs_review' => !empty(array_filter($recording_review_fields)),
            'review_note' => function_exists('ll_tools_ipa_keyboard_get_recording_review_note')
                ? ll_tools_ipa_keyboard_get_recording_review_note($recording_id)
                : '',
        ];
    }

    $affected_word_ids = [$word_id];
    $image_copyright_target_id = 0;
    if ($pending_image_attachment_id > 0) {
        if (!function_exists('ll_tools_ensure_word_image_post_for_word')) {
            wp_send_json_error([
                'message' => __('Image syncing is not available right now.', 'll-tools-text-domain'),
            ], 500);
        }

        $word_image_id = ll_tools_ensure_word_image_post_for_word($word_id);
        if (is_wp_error($word_image_id) || (int) $word_image_id <= 0) {
            $message = is_wp_error($word_image_id)
                ? $word_image_id->get_error_message()
                : __('Could not update the linked word image.', 'll-tools-text-domain');
            wp_send_json_error([
                'message' => $message,
            ], 500);
        }
        $word_image_id = (int) $word_image_id;

        $old_attachment_id = (int) get_post_thumbnail_id($word_image_id);
        if (function_exists('ll_tools_get_connected_word_ids_for_word_image_sync')) {
            $affected_word_ids = ll_tools_get_connected_word_ids_for_word_image_sync($word_image_id, $old_attachment_id);
            $affected_word_ids[] = $word_id;
            $affected_word_ids = array_values(array_unique(array_filter(array_map('intval', (array) $affected_word_ids), static function ($id) {
                return $id > 0;
            })));
        }

        update_post_meta($word_id, '_ll_autopicked_image_id', $word_image_id);
        wp_update_post([
            'ID' => $pending_image_attachment_id,
            'post_parent' => $word_image_id,
        ]);

        if ((int) get_post_thumbnail_id($word_image_id) !== $pending_image_attachment_id) {
            set_post_thumbnail($word_image_id, $pending_image_attachment_id);
        } elseif ((int) get_post_thumbnail_id($word_id) !== $pending_image_attachment_id) {
            set_post_thumbnail($word_id, $pending_image_attachment_id);
        }
        $image_copyright_target_id = $word_image_id;
    } elseif ($selected_word_image_id > 0) {
        $selected_attachment_id = (int) get_post_thumbnail_id($selected_word_image_id);
        if ($selected_attachment_id <= 0) {
            wp_send_json_error([
                'message' => __('The selected word image does not have an image file.', 'll-tools-text-domain'),
            ], 400);
        }

        update_post_meta($word_id, '_ll_autopicked_image_id', $selected_word_image_id);
        if ((int) get_post_thumbnail_id($word_id) !== $selected_attachment_id) {
            set_post_thumbnail($word_id, $selected_attachment_id);
        }
        $image_copyright_target_id = $selected_word_image_id;
    } elseif ($image_copyright_submitted) {
        $current_image_data = ll_tools_word_grid_get_image_data_for_word($word_id);
        $image_copyright_target_id = (int) ($current_image_data['word_image_id'] ?? 0);
    }

    if ($image_copyright_submitted && $image_copyright_target_id > 0 && get_post_type($image_copyright_target_id) === 'word_images') {
        update_post_meta($image_copyright_target_id, 'copyright_info', $image_copyright_info);
    }

    ll_tools_word_grid_schedule_wordset_ipa_rebuild($word_id);

    if (is_array($category_update_result) && !empty($category_update_result['changed'])) {
        $touched_category_ids = ll_tools_word_grid_normalize_category_id_list(array_merge(
            (array) ($category_update_result['previous_category_ids'] ?? []),
            (array) ($category_update_result['all_category_ids'] ?? [])
        ));
        if (!empty($touched_category_ids) && function_exists('ll_tools_bump_category_cache_version')) {
            ll_tools_bump_category_cache_version($touched_category_ids);
        }
        if (function_exists('ll_tools_invalidate_wordset_page_lesson_cache')) {
            ll_tools_invalidate_wordset_page_lesson_cache();
        }
    }

    ll_tools_word_grid_bump_category_cache_for_words($affected_word_ids);
    foreach ($affected_word_ids as $affected_word_id) {
        $affected_wordset_id = ll_tools_word_grid_get_wordset_id_for_word((int) $affected_word_id);
        if ($affected_wordset_id > 0) {
            wp_cache_delete('ll_vocab_lesson_deep_counts_' . $affected_wordset_id, 'll_tools');
        }
    }

    $display_values = ll_tools_word_grid_resolve_display_text($word_id);
    $categories_response = null;
    if (is_array($category_update_result)) {
        $selected_category_ids = ll_tools_word_grid_normalize_category_id_list(
            (array) ($category_update_result['selected_category_ids'] ?? [])
        );
        $lesson_visible = null;
        if ($lesson_category_id > 0 && $wordset_id > 0) {
            $lesson_visible = has_term($wordset_id, 'wordset', $word_id)
                && has_term($lesson_category_id, 'word-category', $word_id);
            if ($lesson_visible && function_exists('ll_tools_word_grid_word_in_deepest_category')) {
                $lesson_visible = ll_tools_word_grid_word_in_deepest_category($word_id, $lesson_category_id);
            }
        }
        $categories_response = [
            'ids' => $selected_category_ids,
            'all_ids' => ll_tools_word_grid_normalize_category_id_list(
                (array) ($category_update_result['all_category_ids'] ?? [])
            ),
            'lesson_visible' => $lesson_visible,
        ];
    }

    $response_payload = [
        'word_id' => $word_id,
        'word_text' => $display_values['word_text'],
        'word_translation' => $display_values['translation_text'],
        'image' => ll_tools_word_grid_get_image_data_for_word($word_id),
        'word_note' => $word_note,
        'specific_wrong_answer_texts' => array_values(array_map('strval', (array) $specific_wrong_answer_texts)),
        'dictionary_entry' => [
            'id' => $dictionary_entry_id,
            'title' => $dictionary_entry_title,
        ],
        'recordings' => $recordings_out,
        'part_of_speech' => [
            'slug' => $pos_slug,
            'label' => $pos_label,
        ],
        'grammatical_gender' => [
            'value' => $gender_value,
            'label' => $gender_label,
            'role' => (string) ($gender_display['role'] ?? ''),
            'style' => (string) ($gender_display['style'] ?? ''),
            'html' => (string) ($gender_display['html'] ?? ''),
        ],
        'grammatical_plurality' => [
            'value' => $plurality_value,
            'label' => $plurality_label,
        ],
        'verb_tense' => [
            'value' => $verb_tense_value,
            'label' => $verb_tense_label,
        ],
        'verb_mood' => [
            'value' => $verb_mood_value,
            'label' => $verb_mood_label,
        ],
    ];
    if (is_array($categories_response)) {
        $response_payload['categories'] = $categories_response;
        $response_payload['lesson_visible'] = $categories_response['lesson_visible'];
    }

    wp_send_json_success($response_payload);
}

add_action('wp_ajax_ll_tools_word_grid_delete_word', 'll_tools_word_grid_delete_word_handler');
function ll_tools_word_grid_delete_word_handler() {
    check_ajax_referer('ll_word_grid_edit', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(__('You must be logged in', 'll-tools-text-domain'), 401);
    }

    $word_id = isset($_POST['word_id']) ? absint($_POST['word_id']) : 0;
    $wordset_id = isset($_POST['wordset_id']) ? absint($_POST['wordset_id']) : 0;
    if ($word_id <= 0) {
        wp_send_json_error(__('Missing word ID.', 'll-tools-text-domain'), 400);
    }

    $word = get_post($word_id);
    if (!$word || $word->post_type !== 'words' || $word->post_status === 'trash') {
        wp_send_json_error(__('Invalid word.', 'll-tools-text-domain'), 404);
    }

    if (!ll_tools_word_grid_user_can_manage_word($word_id, $wordset_id)) {
        wp_send_json_error(__('Forbidden', 'll-tools-text-domain'), 403);
    }

    $wordset_ids = ll_tools_word_grid_get_wordset_ids_for_word($word_id);
    $category_ids_raw = wp_get_post_terms($word_id, 'word-category', ['fields' => 'ids']);
    $category_ids = is_wp_error($category_ids_raw) ? [] : ll_tools_word_grid_normalize_category_id_list((array) $category_ids_raw);
    if ($wordset_id > 0 && !in_array($wordset_id, $wordset_ids, true) && !current_user_can('manage_options')) {
        wp_send_json_error(__('Forbidden', 'll-tools-text-domain'), 403);
    }

    $deleted = wp_trash_post($word_id);
    if (!$deleted) {
        wp_send_json_error(__('Unable to delete word.', 'll-tools-text-domain'), 500);
    }

    if (!empty($category_ids) && function_exists('ll_tools_bump_category_cache_version')) {
        ll_tools_bump_category_cache_version($category_ids);
    }
    if (function_exists('ll_tools_invalidate_wordset_page_lesson_cache')) {
        ll_tools_invalidate_wordset_page_lesson_cache();
    }
    foreach ($wordset_ids as $affected_wordset_id) {
        wp_cache_delete('ll_vocab_lesson_deep_counts_' . (int) $affected_wordset_id, 'll_tools');
    }
    if (function_exists('ll_tools_wordset_editor_log_action')) {
        $log_wordset_ids = ($wordset_id > 0) ? [$wordset_id] : $wordset_ids;
        foreach (array_values(array_unique(array_map('intval', $log_wordset_ids))) as $log_wordset_id) {
            if ($log_wordset_id <= 0) {
                continue;
            }
            ll_tools_wordset_editor_log_action(
                $log_wordset_id,
                'word_trash',
                sprintf(__('Moved "%s" to Trash.', 'll-tools-text-domain'), get_the_title($word_id)),
                [
                    'word_id'         => $word_id,
                    'previous_status' => (string) $word->post_status,
                ]
            );
        }
    }

    wp_send_json_success([
        'message' => __('Word moved to Trash.', 'll-tools-text-domain'),
        'word_id' => $word_id,
        'wordset_ids' => $wordset_ids,
        'category_ids' => $category_ids,
    ]);
}

add_action('wp_ajax_ll_tools_word_grid_delete_recording', 'll_tools_word_grid_delete_recording_handler');
function ll_tools_word_grid_delete_recording_handler() {
    check_ajax_referer('ll_word_grid_edit', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(__('You must be logged in', 'll-tools-text-domain'), 401);
    }

    $recording_id = isset($_POST['recording_id']) ? absint($_POST['recording_id']) : 0;
    $wordset_id = isset($_POST['wordset_id']) ? absint($_POST['wordset_id']) : 0;
    if ($recording_id <= 0) {
        wp_send_json_error(__('Missing recording ID.', 'll-tools-text-domain'), 400);
    }

    $recording = get_post($recording_id);
    if (!$recording || $recording->post_type !== 'word_audio' || $recording->post_status === 'trash') {
        wp_send_json_error(__('Invalid recording.', 'll-tools-text-domain'), 404);
    }

    $word_id = (int) $recording->post_parent;
    if ($word_id <= 0 || get_post_type($word_id) !== 'words') {
        wp_send_json_error(__('Invalid recording parent.', 'll-tools-text-domain'), 400);
    }
    if ($wordset_id <= 0) {
        $wordset_id = ll_tools_word_grid_get_wordset_id_for_word($word_id);
    }
    if ($wordset_id <= 0 || !has_term($wordset_id, 'wordset', $word_id)) {
        wp_send_json_error(__('Recording is outside this word set.', 'll-tools-text-domain'), 400);
    }
    if (!ll_tools_word_grid_user_can_manage_word($word_id, $wordset_id)) {
        wp_send_json_error(__('Forbidden', 'll-tools-text-domain'), 403);
    }

    $category_ids_raw = wp_get_post_terms($word_id, 'word-category', ['fields' => 'ids']);
    $category_ids = is_wp_error($category_ids_raw) ? [] : ll_tools_word_grid_normalize_category_id_list((array) $category_ids_raw);

    $deleted = wp_trash_post($recording_id);
    if (!$deleted) {
        wp_send_json_error(__('Unable to delete recording.', 'll-tools-text-domain'), 500);
    }

    if (function_exists('ll_tools_sync_parent_word_status_by_children')) {
        ll_tools_sync_parent_word_status_by_children($word_id);
    }
    if (!empty($category_ids) && function_exists('ll_tools_bump_category_cache_version')) {
        ll_tools_bump_category_cache_version($category_ids);
    }
    if (function_exists('ll_tools_invalidate_wordset_page_lesson_cache')) {
        ll_tools_invalidate_wordset_page_lesson_cache();
    }
    wp_cache_delete('ll_vocab_lesson_deep_counts_' . $wordset_id, 'll_tools');

    $remaining_recordings = get_posts([
        'post_type'      => 'word_audio',
        'post_status'    => 'publish',
        'post_parent'    => $word_id,
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ]);
    if (function_exists('ll_tools_wordset_editor_log_action')) {
        ll_tools_wordset_editor_log_action(
            $wordset_id,
            'recording_trash',
            sprintf(__('Moved a recording from "%s" to Trash.', 'll-tools-text-domain'), get_the_title($word_id)),
            [
                'recording_id'     => $recording_id,
                'word_id'          => $word_id,
                'previous_status'  => (string) $recording->post_status,
                'recording_title'  => (string) $recording->post_title,
            ]
        );
    }

    wp_send_json_success([
        'message' => __('Recording moved to Trash.', 'll-tools-text-domain'),
        'recording_id' => $recording_id,
        'word_id' => $word_id,
        'word_status' => (string) get_post_status($word_id),
        'has_remaining_recordings' => !empty($remaining_recordings),
    ]);
}

add_action('wp_ajax_ll_tools_word_grid_move_recording', 'll_tools_word_grid_move_recording_handler');
function ll_tools_word_grid_move_recording_handler() {
    check_ajax_referer('ll_word_grid_edit', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(__('You must be logged in', 'll-tools-text-domain'), 401);
    }

    $recording_id = isset($_POST['recording_id']) ? absint($_POST['recording_id']) : 0;
    $target_word_id = isset($_POST['target_word_id']) ? absint($_POST['target_word_id']) : 0;
    $wordset_id = isset($_POST['wordset_id']) ? absint($_POST['wordset_id']) : 0;
    if ($recording_id <= 0 || $target_word_id <= 0) {
        wp_send_json_error(__('Choose a recording and target word.', 'll-tools-text-domain'), 400);
    }

    $recording = get_post($recording_id);
    if (!$recording || $recording->post_type !== 'word_audio' || $recording->post_status === 'trash') {
        wp_send_json_error(__('Invalid recording.', 'll-tools-text-domain'), 404);
    }

    $source_word_id = (int) $recording->post_parent;
    if ($source_word_id <= 0 || $source_word_id === $target_word_id) {
        wp_send_json_error(__('Choose a different target word.', 'll-tools-text-domain'), 400);
    }

    $source_word = get_post($source_word_id);
    $target_word = get_post($target_word_id);
    if (!$source_word || $source_word->post_type !== 'words' || !$target_word || $target_word->post_type !== 'words' || $target_word->post_status === 'trash') {
        wp_send_json_error(__('Invalid target word.', 'll-tools-text-domain'), 404);
    }

    if ($wordset_id <= 0) {
        $wordset_id = ll_tools_word_grid_get_wordset_id_for_word($source_word_id);
    }
    if ($wordset_id <= 0 || !has_term($wordset_id, 'wordset', $source_word_id) || !has_term($wordset_id, 'wordset', $target_word_id)) {
        wp_send_json_error(__('Move recordings within the same word set.', 'll-tools-text-domain'), 400);
    }
    if (!ll_tools_word_grid_user_can_manage_word($source_word_id, $wordset_id) || !ll_tools_word_grid_user_can_manage_word($target_word_id, $wordset_id)) {
        wp_send_json_error(__('Forbidden', 'll-tools-text-domain'), 403);
    }

    $source_category_ids_raw = wp_get_post_terms($source_word_id, 'word-category', ['fields' => 'ids']);
    $target_category_ids_raw = wp_get_post_terms($target_word_id, 'word-category', ['fields' => 'ids']);
    $category_ids = ll_tools_word_grid_normalize_category_id_list(array_merge(
        is_wp_error($source_category_ids_raw) ? [] : (array) $source_category_ids_raw,
        is_wp_error($target_category_ids_raw) ? [] : (array) $target_category_ids_raw
    ));

    $updated = wp_update_post([
        'ID' => $recording_id,
        'post_parent' => $target_word_id,
    ], true);
    if (is_wp_error($updated) || (int) $updated <= 0) {
        $message = is_wp_error($updated) ? $updated->get_error_message() : __('Unable to move recording.', 'll-tools-text-domain');
        wp_send_json_error($message, 500);
    }

    if (function_exists('ll_tools_sync_parent_word_status_by_children')) {
        ll_tools_sync_parent_word_status_by_children($source_word_id);
        ll_tools_sync_parent_word_status_by_children($target_word_id);
    }
    if (!empty($category_ids) && function_exists('ll_tools_bump_category_cache_version')) {
        ll_tools_bump_category_cache_version($category_ids);
    }
    if (function_exists('ll_tools_invalidate_wordset_page_lesson_cache')) {
        ll_tools_invalidate_wordset_page_lesson_cache();
    }
    wp_cache_delete('ll_vocab_lesson_deep_counts_' . $wordset_id, 'll_tools');
    if (function_exists('ll_tools_wordset_editor_log_action')) {
        ll_tools_wordset_editor_log_action(
            $wordset_id,
            'recording_move',
            sprintf(__('Moved a recording from "%1$s" to "%2$s".', 'll-tools-text-domain'), get_the_title($source_word_id), get_the_title($target_word_id)),
            [
                'recording_id'   => $recording_id,
                'source_word_id' => $source_word_id,
                'target_word_id' => $target_word_id,
            ]
        );
    }

    $transcription_mode = function_exists('ll_tools_get_wordset_recording_transcription_mode')
        ? ll_tools_get_wordset_recording_transcription_mode([$wordset_id], true)
        : 'ipa';

    wp_send_json_success([
        'message' => __('Recording moved.', 'll-tools-text-domain'),
        'recording_id' => $recording_id,
        'source_word_id' => $source_word_id,
        'source_word_status' => (string) get_post_status($source_word_id),
        'target_word_id' => $target_word_id,
        'target_word' => ll_tools_word_grid_get_word_move_choice($target_word_id),
        'recording' => ll_tools_word_grid_get_recording_editor_payload($recording_id, $transcription_mode),
    ]);
}

add_action('wp_ajax_ll_tools_word_grid_save_lesson_order', 'll_tools_word_grid_save_lesson_order_handler');
function ll_tools_word_grid_save_lesson_order_handler() {
    check_ajax_referer('ll_word_grid_edit', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(__('You must be logged in', 'll-tools-text-domain'), 401);
    }

    $lesson_id = (int) ($_POST['lesson_id'] ?? 0);
    $lesson = $lesson_id > 0 ? get_post($lesson_id) : null;
    if (!$lesson || $lesson->post_type !== 'll_vocab_lesson') {
        wp_send_json_error(__('Invalid lesson', 'll-tools-text-domain'), 400);
    }

    [$wordset_id, $category_id] = ll_tools_get_vocab_lesson_ids_from_post($lesson_id);
    if ($wordset_id <= 0 || $category_id <= 0) {
        wp_send_json_error(__('Missing lesson metadata', 'll-tools-text-domain'), 400);
    }
    if (!ll_tools_word_grid_user_can_manage_wordset_scope($wordset_id)) {
        wp_send_json_error(__('Forbidden', 'll-tools-text-domain'), 403);
    }

    $allowed_word_ids = function_exists('ll_tools_get_lesson_word_ids_for_order')
        ? ll_tools_get_lesson_word_ids_for_order($wordset_id, $category_id, true)
        : ll_tools_get_lesson_word_ids_for_transcription($wordset_id, $category_id);
    if (empty($allowed_word_ids)) {
        delete_post_meta($lesson_id, defined('LL_TOOLS_VOCAB_LESSON_WORD_ORDER_META') ? LL_TOOLS_VOCAB_LESSON_WORD_ORDER_META : '_ll_tools_vocab_word_order');
        wp_send_json_success([
            'order' => [],
            'count' => 0,
        ]);
    }

    $submitted_order = $_POST['order'] ?? [];
    $ordered_word_ids = function_exists('ll_tools_normalize_vocab_lesson_word_order_ids')
        ? ll_tools_normalize_vocab_lesson_word_order_ids($submitted_order, $allowed_word_ids)
        : array_values(array_filter(array_map('intval', (array) $submitted_order), static function (int $word_id) use ($allowed_word_ids): bool {
            return $word_id > 0 && in_array($word_id, $allowed_word_ids, true);
        }));

    $ordered_lookup = array_fill_keys($ordered_word_ids, true);
    foreach ($allowed_word_ids as $allowed_word_id) {
        $allowed_word_id = (int) $allowed_word_id;
        if ($allowed_word_id <= 0 || isset($ordered_lookup[$allowed_word_id])) {
            continue;
        }
        $ordered_lookup[$allowed_word_id] = true;
        $ordered_word_ids[] = $allowed_word_id;
    }

    $meta_key = defined('LL_TOOLS_VOCAB_LESSON_WORD_ORDER_META')
        ? LL_TOOLS_VOCAB_LESSON_WORD_ORDER_META
        : '_ll_tools_vocab_word_order';
    if (empty($ordered_word_ids)) {
        delete_post_meta($lesson_id, $meta_key);
    } else {
        update_post_meta($lesson_id, $meta_key, $ordered_word_ids);
    }
    clean_post_cache($lesson_id);

    wp_send_json_success([
        'order' => $ordered_word_ids,
        'count' => count($ordered_word_ids),
    ]);
}

add_action('wp_ajax_ll_tools_search_dictionary_entries', 'll_tools_search_dictionary_entries_handler');
function ll_tools_search_dictionary_entries_handler() {
    check_ajax_referer('ll_word_grid_edit', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(__('You must be logged in', 'll-tools-text-domain'), 401);
    }

    $query = sanitize_text_field($_POST['q'] ?? '');
    $limit = (int) ($_POST['limit'] ?? 20);
    if ($limit <= 0) {
        $limit = 20;
    }
    $limit = min(50, $limit);

    $wordset_id = isset($_POST['wordset_id']) ? (int) $_POST['wordset_id'] : 0;
    $word_id = isset($_POST['word_id']) ? (int) $_POST['word_id'] : 0;
    if ($word_id > 0 && get_post_type($word_id) === 'words') {
        $resolved_wordset_id = function_exists('ll_tools_word_grid_get_wordset_id_for_word')
            ? (int) ll_tools_word_grid_get_wordset_id_for_word($word_id)
            : 0;
        if ($resolved_wordset_id > 0) {
            $wordset_id = $resolved_wordset_id;
        }
    }
    if ($wordset_id > 0 && !ll_tools_word_grid_user_can_manage_wordset_scope($wordset_id)) {
        wp_send_json_error(__('Forbidden', 'll-tools-text-domain'), 403);
    }
    if ($wordset_id <= 0 && !ll_tools_user_can_edit_vocab_words()) {
        wp_send_json_error(__('Forbidden', 'll-tools-text-domain'), 403);
    }

    $entries = function_exists('ll_tools_search_dictionary_entries')
        ? ll_tools_search_dictionary_entries($query, $limit, $wordset_id)
        : [];

    wp_send_json_success([
        'entries' => array_values((array) $entries),
    ]);
}

add_action('wp_ajax_ll_tools_word_grid_search_words', 'll_tools_word_grid_search_words_handler');
function ll_tools_word_grid_search_words_handler() {
    check_ajax_referer('ll_word_grid_edit', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(__('You must be logged in', 'll-tools-text-domain'), 401);
    }

    $wordset_id = isset($_POST['wordset_id']) ? absint($_POST['wordset_id']) : 0;
    if ($wordset_id <= 0 || !ll_tools_word_grid_user_can_manage_wordset_scope($wordset_id)) {
        wp_send_json_error(__('Forbidden', 'll-tools-text-domain'), 403);
    }

    $query = isset($_POST['q']) ? sanitize_text_field(wp_unslash((string) $_POST['q'])) : '';
    $exclude_word_id = isset($_POST['exclude_word_id']) ? absint($_POST['exclude_word_id']) : 0;
    $limit = isset($_POST['limit']) ? absint($_POST['limit']) : 20;

    wp_send_json_success([
        'words' => ll_tools_word_grid_search_words_for_move($query, $wordset_id, $exclude_word_id, $limit),
    ]);
}

add_action('wp_ajax_ll_tools_search_word_images', 'll_tools_search_word_images_handler');
function ll_tools_search_word_images_handler() {
    check_ajax_referer('ll_word_grid_edit', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(__('You must be logged in', 'll-tools-text-domain'), 401);
    }

    $word_id = isset($_POST['word_id']) ? absint($_POST['word_id']) : 0;
    $wordset_id = isset($_POST['wordset_id']) ? absint($_POST['wordset_id']) : 0;
    if ($word_id > 0 && get_post_type($word_id) === 'words') {
        $resolved_wordset_id = ll_tools_word_grid_get_wordset_id_for_word($word_id);
        if ($resolved_wordset_id > 0) {
            $wordset_id = $resolved_wordset_id;
        }

        if (!ll_tools_word_grid_user_can_manage_word($word_id, $wordset_id)) {
            wp_send_json_error(__('Forbidden', 'll-tools-text-domain'), 403);
        }
    } elseif ($wordset_id <= 0 || !ll_tools_word_grid_user_can_manage_wordset_scope($wordset_id)) {
        wp_send_json_error(__('Forbidden', 'll-tools-text-domain'), 403);
    }

    $query = isset($_POST['q']) ? sanitize_text_field(wp_unslash((string) $_POST['q'])) : '';
    $limit = isset($_POST['limit']) ? absint($_POST['limit']) : 20;
    if ($limit <= 0) {
        $limit = 20;
    }

    wp_send_json_success([
        'images' => ll_tools_word_grid_search_word_images_for_word($query, $limit, $wordset_id, $word_id),
    ]);
}

add_action('wp_ajax_ll_tools_word_grid_update_category_prereqs', 'll_tools_word_grid_update_category_prereqs_handler');
function ll_tools_word_grid_update_category_prereqs_handler() {
    check_ajax_referer('ll_word_grid_edit', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error([
            'message' => __('You must be logged in to edit prerequisites.', 'll-tools-text-domain'),
        ], 401);
    }

    $wordset_id = (int) ($_POST['wordset_id'] ?? 0);
    $category_id = (int) ($_POST['category_id'] ?? 0);
    if ($wordset_id <= 0 || $category_id <= 0) {
        wp_send_json_error([
            'message' => __('Missing word set or category.', 'll-tools-text-domain'),
        ], 400);
    }
    if (!ll_tools_word_grid_user_can_manage_wordset_scope($wordset_id)) {
        wp_send_json_error([
            'message' => __('You do not have permission to edit prerequisites for this word set.', 'll-tools-text-domain'),
        ], 403);
    }

    if (!function_exists('ll_tools_wordset_get_category_ordering_mode')
        || ll_tools_wordset_get_category_ordering_mode($wordset_id) !== 'prerequisite'
    ) {
        wp_send_json_error([
            'message' => __('This word set is not using prerequisite ordering.', 'll-tools-text-domain'),
        ], 400);
    }

    if (!function_exists('ll_tools_wordset_get_admin_category_ordering_rows')
        || !function_exists('ll_tools_wordset_normalize_category_id_list')
        || !function_exists('ll_tools_wordset_get_category_prereq_map')
        || !function_exists('ll_tools_wordset_normalize_category_prereq_map')
        || !function_exists('ll_tools_wordset_find_prereq_cycle')
        || !function_exists('ll_tools_wordset_calculate_prereq_levels')
    ) {
        wp_send_json_error([
            'message' => __('Prerequisite editing is unavailable right now.', 'll-tools-text-domain'),
        ], 500);
    }

    $ordering_rows = ll_tools_wordset_get_admin_category_ordering_rows($wordset_id);
    $allowed_category_ids = ll_tools_wordset_normalize_category_id_list(wp_list_pluck((array) $ordering_rows, 'id'));
    if (empty($allowed_category_ids) || !in_array($category_id, $allowed_category_ids, true)) {
        wp_send_json_error([
            'message' => __('This lesson category is not available for prerequisite ordering.', 'll-tools-text-domain'),
        ], 400);
    }

    $label_map = [];
    foreach ((array) $ordering_rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $row_id = (int) ($row['id'] ?? 0);
        if ($row_id <= 0) {
            continue;
        }
        $label_map[$row_id] = (string) ($row['name'] ?? (string) $row_id);
    }

    $posted_prereq_ids = $_POST['prereq_ids'] ?? [];
    $posted_prereq_ids = wp_unslash($posted_prereq_ids);
    if (!is_array($posted_prereq_ids)) {
        $posted_prereq_ids = [$posted_prereq_ids];
    }

    $single_map = ll_tools_wordset_normalize_category_prereq_map(
        [$category_id => $posted_prereq_ids],
        $allowed_category_ids
    );
    $selected_prereq_ids = array_values(array_map('intval', (array) ($single_map[$category_id] ?? [])));

    $build_selected_rows = static function (array $selected_ids, array $labels, array $level_info): array {
        $rows = [];
        foreach ($selected_ids as $selected_prereq_id) {
            $selected_prereq_id = (int) $selected_prereq_id;
            if ($selected_prereq_id <= 0) {
                continue;
            }
            $row = [
                'id' => $selected_prereq_id,
                'label' => (string) ($labels[$selected_prereq_id] ?? (string) $selected_prereq_id),
            ];
            if (isset($level_info['levels'][$selected_prereq_id]) && empty($level_info['has_cycle'])) {
                $row['level'] = (int) $level_info['levels'][$selected_prereq_id];
            }
            $rows[] = $row;
        }

        return $rows;
    };

    $build_editor_state = static function (array $map) use ($allowed_category_ids, $category_id, $label_map, $build_selected_rows): array {
        $normalized_map = ll_tools_wordset_normalize_category_prereq_map($map, $allowed_category_ids);
        $level_info = ll_tools_wordset_calculate_prereq_levels($allowed_category_ids, $normalized_map);
        $selected_ids = array_values(array_map('intval', (array) ($normalized_map[$category_id] ?? [])));
        $blocked_ids = function_exists('ll_tools_wordset_get_blocked_prereq_ids_for_category') && empty($level_info['has_cycle'])
            ? ll_tools_wordset_get_blocked_prereq_ids_for_category($category_id, $allowed_category_ids, $normalized_map)
            : [];

        return [
            'selected' => $build_selected_rows($selected_ids, $label_map, $level_info),
            'selected_ids' => $selected_ids,
            'blocked_ids' => array_values(array_map('intval', (array) $blocked_ids)),
            'level' => (isset($level_info['levels'][$category_id]) && empty($level_info['has_cycle']))
                ? (int) $level_info['levels'][$category_id]
                : null,
            'has_cycle' => !empty($level_info['has_cycle']),
        ];
    };

    $stored_prereq_map = ll_tools_wordset_get_category_prereq_map($wordset_id, $allowed_category_ids);
    $saved_editor_state = $build_editor_state($stored_prereq_map);
    $prereq_map = $stored_prereq_map;
    if (empty($selected_prereq_ids)) {
        unset($prereq_map[$category_id]);
    } else {
        $prereq_map[$category_id] = $selected_prereq_ids;
    }

    $prereq_map = ll_tools_wordset_normalize_category_prereq_map($prereq_map, $allowed_category_ids);
    $cycle_check = ll_tools_wordset_find_prereq_cycle($allowed_category_ids, $prereq_map);
    if (!empty($cycle_check['has_cycle'])) {
        $cycle_labels = [];
        foreach ((array) ($cycle_check['cycle_path'] ?? []) as $cycle_id) {
            $cycle_id = (int) $cycle_id;
            if ($cycle_id <= 0) {
                continue;
            }
            $cycle_labels[] = (string) ($label_map[$cycle_id] ?? (string) $cycle_id);
        }

        if (!empty($cycle_labels)) {
            wp_send_json_error([
                'message' => sprintf(
                    __('Prerequisites were not saved because they create a loop: %s', 'll-tools-text-domain'),
                    implode(' -> ', $cycle_labels)
                ),
                'selected' => $saved_editor_state['selected'],
                'selected_ids' => $saved_editor_state['selected_ids'],
                'blocked_ids' => $saved_editor_state['blocked_ids'],
                'level' => $saved_editor_state['level'],
                'has_cycle' => $saved_editor_state['has_cycle'],
            ], 409);
        }

        wp_send_json_error([
            'message' => __('Prerequisites were not saved because they create a loop.', 'll-tools-text-domain'),
            'selected' => $saved_editor_state['selected'],
            'selected_ids' => $saved_editor_state['selected_ids'],
            'blocked_ids' => $saved_editor_state['blocked_ids'],
            'level' => $saved_editor_state['level'],
            'has_cycle' => $saved_editor_state['has_cycle'],
        ], 409);
    }

    if (empty($prereq_map)) {
        delete_term_meta($wordset_id, 'll_wordset_category_prerequisites');
    } else {
        update_term_meta($wordset_id, 'll_wordset_category_prerequisites', $prereq_map);
    }

    $saved_editor_state = $build_editor_state($prereq_map);

    wp_send_json_success([
        'message' => __('Prerequisites saved.', 'll-tools-text-domain'),
        'selected' => $saved_editor_state['selected'],
        'selected_ids' => $saved_editor_state['selected_ids'],
        'blocked_ids' => $saved_editor_state['blocked_ids'],
        'level' => $saved_editor_state['level'],
        'has_cycle' => $saved_editor_state['has_cycle'],
    ]);
}

function ll_tools_word_grid_match_option_value_case_insensitive(string $value, array $options): string {
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    foreach ($options as $option) {
        $option = trim((string) $option);
        if ($option !== '' && strcasecmp($option, $value) === 0) {
            return $option;
        }
    }

    return $value;
}

function ll_tools_word_grid_get_word_meta_payload(int $word_id, int $wordset_id = 0): array {
    $word_id = (int) $word_id;
    if ($word_id <= 0) {
        return [
            'word_id' => 0,
            'part_of_speech' => [
                'slug' => '',
                'label' => '',
            ],
            'grammatical_gender' => [
                'value' => '',
                'label' => '',
                'role' => 'other',
                'style' => '',
                'html' => '',
            ],
            'grammatical_plurality' => [
                'value' => '',
                'label' => '',
            ],
            'verb_tense' => [
                'value' => '',
                'label' => '',
            ],
            'verb_mood' => [
                'value' => '',
                'label' => '',
            ],
        ];
    }

    if ($wordset_id <= 0) {
        $wordset_id = ll_tools_word_grid_get_wordset_id_for_word($word_id);
    }

    $pos_entry = ll_tools_word_grid_collect_part_of_speech_terms([$word_id]);
    $pos_entry = $pos_entry[$word_id] ?? [];
    $pos_slug = isset($pos_entry['slug']) ? (string) $pos_entry['slug'] : '';
    $pos_label = isset($pos_entry['label']) ? (string) $pos_entry['label'] : '';

    $gender_enabled = ($wordset_id > 0 && function_exists('ll_tools_wordset_has_grammatical_gender'))
        ? ll_tools_wordset_has_grammatical_gender($wordset_id)
        : false;
    $plurality_enabled = ($wordset_id > 0 && function_exists('ll_tools_wordset_has_plurality'))
        ? ll_tools_wordset_has_plurality($wordset_id)
        : false;
    $verb_tense_enabled = ($wordset_id > 0 && function_exists('ll_tools_wordset_has_verb_tense'))
        ? ll_tools_wordset_has_verb_tense($wordset_id)
        : false;
    $verb_mood_enabled = ($wordset_id > 0 && function_exists('ll_tools_wordset_has_verb_mood'))
        ? ll_tools_wordset_has_verb_mood($wordset_id)
        : false;

    $is_noun = ($pos_slug === 'noun');
    $is_verb = ($pos_slug === 'verb');

    $gender_value = '';
    $gender_label = '';
    $gender_display = [
        'value' => '',
        'label' => '',
        'role' => 'other',
        'style' => '',
        'html' => '',
    ];
    if ($gender_enabled && $is_noun) {
        $gender_value = trim((string) get_post_meta($word_id, 'll_grammatical_gender', true));
        if ($gender_value !== '') {
            if (function_exists('ll_tools_wordset_get_gender_display_data')) {
                $gender_display = ll_tools_wordset_get_gender_display_data($wordset_id, $gender_value);
                $gender_value = (string) ($gender_display['value'] ?? $gender_value);
                $gender_label = (string) ($gender_display['label'] ?? '');
            } elseif (function_exists('ll_tools_wordset_get_gender_label')) {
                $gender_label = ll_tools_wordset_get_gender_label($wordset_id, $gender_value);
                $gender_display['value'] = $gender_value;
                $gender_display['label'] = $gender_label;
            } else {
                $gender_label = $gender_value;
                $gender_display['value'] = $gender_value;
                $gender_display['label'] = $gender_label;
            }
        }
    }

    $plurality_value = '';
    $plurality_label = '';
    if ($plurality_enabled && $is_noun) {
        $plurality_value = trim((string) get_post_meta($word_id, 'll_grammatical_plurality', true));
        if ($plurality_value !== '' && function_exists('ll_tools_wordset_get_plurality_label')) {
            $plurality_label = ll_tools_wordset_get_plurality_label($wordset_id, $plurality_value);
            $plurality_value = ll_tools_word_grid_match_option_value_case_insensitive(
                $plurality_value,
                ll_tools_wordset_get_plurality_options($wordset_id)
            );
        } else {
            $plurality_label = $plurality_value;
        }
    }

    $verb_tense_value = '';
    $verb_tense_label = '';
    if ($verb_tense_enabled && $is_verb) {
        $verb_tense_value = trim((string) get_post_meta($word_id, 'll_verb_tense', true));
        if ($verb_tense_value !== '' && function_exists('ll_tools_wordset_get_verb_tense_label')) {
            $verb_tense_label = ll_tools_wordset_get_verb_tense_label($wordset_id, $verb_tense_value);
            $verb_tense_value = ll_tools_word_grid_match_option_value_case_insensitive(
                $verb_tense_value,
                ll_tools_wordset_get_verb_tense_options($wordset_id)
            );
        } else {
            $verb_tense_label = $verb_tense_value;
        }
    }

    $verb_mood_value = '';
    $verb_mood_label = '';
    if ($verb_mood_enabled && $is_verb) {
        $verb_mood_value = trim((string) get_post_meta($word_id, 'll_verb_mood', true));
        if ($verb_mood_value !== '' && function_exists('ll_tools_wordset_get_verb_mood_label')) {
            $verb_mood_label = ll_tools_wordset_get_verb_mood_label($wordset_id, $verb_mood_value);
            $verb_mood_value = ll_tools_word_grid_match_option_value_case_insensitive(
                $verb_mood_value,
                ll_tools_wordset_get_verb_mood_options($wordset_id)
            );
        } else {
            $verb_mood_label = $verb_mood_value;
        }
    }

    return [
        'word_id' => $word_id,
        'part_of_speech' => [
            'slug' => $pos_slug,
            'label' => $pos_label,
        ],
        'grammatical_gender' => [
            'value' => $gender_value,
            'label' => $gender_label,
            'role' => (string) ($gender_display['role'] ?? ''),
            'style' => (string) ($gender_display['style'] ?? ''),
            'html' => (string) ($gender_display['html'] ?? ''),
        ],
        'grammatical_plurality' => [
            'value' => $plurality_value,
            'label' => $plurality_label,
        ],
        'verb_tense' => [
            'value' => $verb_tense_value,
            'label' => $verb_tense_label,
        ],
        'verb_mood' => [
            'value' => $verb_mood_value,
            'label' => $verb_mood_label,
        ],
    ];
}

function ll_tools_word_grid_get_bulk_control_defaults(int $wordset_id, array $word_ids): array {
    $defaults = [
        'part_of_speech' => '',
        'grammatical_gender' => '',
        'grammatical_plurality' => '',
        'verb_tense' => '',
        'verb_mood' => '',
    ];

    $wordset_id = (int) $wordset_id;
    $word_ids = array_values(array_filter(array_map('intval', $word_ids), static function ($word_id): bool {
        return $word_id > 0;
    }));
    if (empty($word_ids)) {
        return $defaults;
    }

    update_meta_cache('post', $word_ids);

    $resolve_uniform_value = static function (array $values): string {
        if (empty($values)) {
            return '';
        }
        $first = trim((string) array_shift($values));
        if ($first === '') {
            return '';
        }
        foreach ($values as $value) {
            if (strcasecmp(trim((string) $value), $first) !== 0) {
                return '';
            }
        }
        return $first;
    };

    $pos_map = ll_tools_word_grid_collect_part_of_speech_terms($word_ids);
    $pos_values = [];
    $noun_ids = [];
    $verb_ids = [];

    foreach ($word_ids as $word_id) {
        $pos_slug = isset($pos_map[$word_id]['slug']) ? trim((string) $pos_map[$word_id]['slug']) : '';
        $pos_values[] = $pos_slug;
        if ($pos_slug === 'noun') {
            $noun_ids[] = $word_id;
        } elseif ($pos_slug === 'verb') {
            $verb_ids[] = $word_id;
        }
    }

    if (count($pos_values) === count($word_ids)) {
        $defaults['part_of_speech'] = $resolve_uniform_value($pos_values);
    }

    if ($wordset_id > 0 && !empty($noun_ids) && function_exists('ll_tools_wordset_has_grammatical_gender') && ll_tools_wordset_has_grammatical_gender($wordset_id)) {
        $gender_options = function_exists('ll_tools_wordset_get_gender_options')
            ? ll_tools_wordset_get_gender_options($wordset_id)
            : [];
        $gender_values = [];
        foreach ($noun_ids as $word_id) {
            $gender_values[] = function_exists('ll_tools_wordset_normalize_gender_value_for_options')
                ? ll_tools_wordset_normalize_gender_value_for_options(
                    trim((string) get_post_meta($word_id, 'll_grammatical_gender', true)),
                    $gender_options
                )
                : trim((string) get_post_meta($word_id, 'll_grammatical_gender', true));
        }
        $defaults['grammatical_gender'] = $resolve_uniform_value($gender_values);
    }

    if ($wordset_id > 0 && !empty($noun_ids) && function_exists('ll_tools_wordset_has_plurality') && ll_tools_wordset_has_plurality($wordset_id)) {
        $plurality_options = function_exists('ll_tools_wordset_get_plurality_options')
            ? ll_tools_wordset_get_plurality_options($wordset_id)
            : [];
        $plurality_values = [];
        foreach ($noun_ids as $word_id) {
            $plurality_values[] = ll_tools_word_grid_match_option_value_case_insensitive(
                trim((string) get_post_meta($word_id, 'll_grammatical_plurality', true)),
                $plurality_options
            );
        }
        $defaults['grammatical_plurality'] = $resolve_uniform_value($plurality_values);
    }

    if ($wordset_id > 0 && !empty($verb_ids) && function_exists('ll_tools_wordset_has_verb_tense') && ll_tools_wordset_has_verb_tense($wordset_id)) {
        $verb_tense_options = function_exists('ll_tools_wordset_get_verb_tense_options')
            ? ll_tools_wordset_get_verb_tense_options($wordset_id)
            : [];
        $verb_tense_values = [];
        foreach ($verb_ids as $word_id) {
            $verb_tense_values[] = ll_tools_word_grid_match_option_value_case_insensitive(
                trim((string) get_post_meta($word_id, 'll_verb_tense', true)),
                $verb_tense_options
            );
        }
        $defaults['verb_tense'] = $resolve_uniform_value($verb_tense_values);
    }

    if ($wordset_id > 0 && !empty($verb_ids) && function_exists('ll_tools_wordset_has_verb_mood') && ll_tools_wordset_has_verb_mood($wordset_id)) {
        $verb_mood_options = function_exists('ll_tools_wordset_get_verb_mood_options')
            ? ll_tools_wordset_get_verb_mood_options($wordset_id)
            : [];
        $verb_mood_values = [];
        foreach ($verb_ids as $word_id) {
            $verb_mood_values[] = ll_tools_word_grid_match_option_value_case_insensitive(
                trim((string) get_post_meta($word_id, 'll_verb_mood', true)),
                $verb_mood_options
            );
        }
        $defaults['verb_mood'] = $resolve_uniform_value($verb_mood_values);
    }

    return $defaults;
}

function ll_tools_word_grid_parse_bulk_snapshot_payload($raw_snapshot, array $allowed_word_ids): array {
    $allowed_word_ids = array_values(array_filter(array_map('intval', $allowed_word_ids), static function ($word_id): bool {
        return $word_id > 0;
    }));
    if (empty($allowed_word_ids)) {
        return [];
    }

    if (is_string($raw_snapshot)) {
        $decoded = json_decode(wp_unslash($raw_snapshot), true);
        $raw_snapshot = is_array($decoded) ? $decoded : [];
    } elseif (is_array($raw_snapshot)) {
        $raw_snapshot = wp_unslash($raw_snapshot);
    } else {
        $raw_snapshot = [];
    }

    $allowed_lookup = array_fill_keys($allowed_word_ids, true);
    $snapshot_rows = [];

    foreach ((array) $raw_snapshot as $row) {
        if (!is_array($row)) {
            continue;
        }

        $word_id = (int) ($row['word_id'] ?? 0);
        if ($word_id <= 0 || !isset($allowed_lookup[$word_id])) {
            continue;
        }

        $snapshot_rows[$word_id] = [
            'word_id' => $word_id,
            'part_of_speech' => sanitize_title((string) ($row['part_of_speech'] ?? '')),
            'grammatical_gender' => sanitize_text_field((string) ($row['grammatical_gender'] ?? '')),
            'grammatical_plurality' => sanitize_text_field((string) ($row['grammatical_plurality'] ?? '')),
            'verb_tense' => sanitize_text_field((string) ($row['verb_tense'] ?? '')),
            'verb_mood' => sanitize_text_field((string) ($row['verb_mood'] ?? '')),
        ];
    }

    return array_values($snapshot_rows);
}

add_action('wp_ajax_ll_tools_word_grid_bulk_update', 'll_tools_word_grid_bulk_update_handler');
function ll_tools_word_grid_bulk_update_handler() {
    check_ajax_referer('ll_word_grid_edit', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(__('You must be logged in', 'll-tools-text-domain'), 401);
    }

    $wordset_id = (int) ($_POST['wordset_id'] ?? 0);
    $category_id = (int) ($_POST['category_id'] ?? 0);
    if ($wordset_id <= 0 || $category_id <= 0) {
        wp_send_json_error(__('Missing wordset or category', 'll-tools-text-domain'), 400);
    }
    if (!ll_tools_word_grid_user_can_manage_wordset_scope($wordset_id)) {
        wp_send_json_error(__('Forbidden', 'll-tools-text-domain'), 403);
    }

    $mode = sanitize_text_field($_POST['mode'] ?? '');
    if (!in_array($mode, ['pos', 'gender', 'plurality', 'verb_tense', 'verb_mood'], true)) {
        wp_send_json_error(__('Invalid mode', 'll-tools-text-domain'), 400);
    }

    $word_ids = ll_tools_get_lesson_word_ids_for_transcription($wordset_id, $category_id);
    if (empty($word_ids)) {
        wp_send_json_success([
            'word_ids' => [],
            'count' => 0,
        ]);
    }

    if ($mode === 'pos') {
        $pos_slug = sanitize_text_field($_POST['part_of_speech'] ?? '');
        $pos_slug = sanitize_title($pos_slug);
        if ($pos_slug === '') {
            wp_send_json_error(__('Missing part of speech', 'll-tools-text-domain'), 400);
        }
        $term = get_term_by('slug', $pos_slug, 'part_of_speech');
        if (!$term || is_wp_error($term)) {
            wp_send_json_error(__('Invalid part of speech', 'll-tools-text-domain'), 400);
        }

        $clear_gender = ($term->slug !== 'noun');
        $clear_plurality = ($term->slug !== 'noun');
        $clear_verb_tense = ($term->slug !== 'verb');
        $clear_verb_mood = ($term->slug !== 'verb');
        foreach ($word_ids as $word_id) {
            $word_id = (int) $word_id;
            if ($word_id <= 0) {
                continue;
            }
            wp_set_object_terms($word_id, [(int) $term->term_id], 'part_of_speech', false);
            if ($clear_gender) {
                delete_post_meta($word_id, 'll_grammatical_gender');
            }
            if ($clear_plurality) {
                delete_post_meta($word_id, 'll_grammatical_plurality');
            }
            if ($clear_verb_tense) {
                delete_post_meta($word_id, 'll_verb_tense');
            }
            if ($clear_verb_mood) {
                delete_post_meta($word_id, 'll_verb_mood');
            }
        }

        ll_tools_word_grid_bump_category_cache_for_words($word_ids, $category_id);

        wp_send_json_success([
            'word_ids' => $word_ids,
            'count' => count($word_ids),
            'part_of_speech' => [
                'slug' => (string) $term->slug,
                'label' => (string) $term->name,
            ],
            'gender_cleared' => $clear_gender,
            'plurality_cleared' => $clear_plurality,
            'verb_tense_cleared' => $clear_verb_tense,
            'verb_mood_cleared' => $clear_verb_mood,
        ]);
    }

    if ($mode === 'gender') {
        if (!function_exists('ll_tools_wordset_has_grammatical_gender') || !ll_tools_wordset_has_grammatical_gender($wordset_id)) {
            wp_send_json_error(__('Gender not enabled', 'll-tools-text-domain'), 400);
        }

        $gender_value = sanitize_text_field($_POST['grammatical_gender'] ?? '');
        $gender_value = trim($gender_value);
        if ($gender_value === '') {
            wp_send_json_error(__('Missing gender', 'll-tools-text-domain'), 400);
        }

        $allowed = function_exists('ll_tools_wordset_get_gender_options')
            ? ll_tools_wordset_get_gender_options($wordset_id)
            : [];
        if (!in_array($gender_value, $allowed, true)) {
            wp_send_json_error(__('Invalid gender', 'll-tools-text-domain'), 400);
        }

        $pos_map = ll_tools_word_grid_collect_part_of_speech_terms($word_ids);
        $updated = [];
        foreach ($word_ids as $word_id) {
            $word_id = (int) $word_id;
            if ($word_id <= 0) {
                continue;
            }
            $pos_slug = isset($pos_map[$word_id]['slug']) ? (string) $pos_map[$word_id]['slug'] : '';
            if ($pos_slug !== 'noun') {
                continue;
            }
            update_post_meta($word_id, 'll_grammatical_gender', $gender_value);
            $updated[] = $word_id;
        }

        $gender_display = function_exists('ll_tools_wordset_get_gender_display_data')
            ? ll_tools_wordset_get_gender_display_data($wordset_id, $gender_value)
            : [
                'value' => $gender_value,
                'label' => (function_exists('ll_tools_wordset_get_gender_label')
                    ? ll_tools_wordset_get_gender_label($wordset_id, $gender_value)
                    : $gender_value),
                'role' => '',
                'style' => '',
                'html' => '',
            ];
        $gender_label = (string) ($gender_display['label'] ?? $gender_value);

        if (!empty($updated)) {
            ll_tools_word_grid_bump_category_cache_for_words($updated, $category_id);
        }

        wp_send_json_success([
            'word_ids' => $updated,
            'count' => count($updated),
            'skipped' => max(0, count($word_ids) - count($updated)),
            'grammatical_gender' => [
                'value' => $gender_value,
                'label' => $gender_label,
                'role' => (string) ($gender_display['role'] ?? ''),
                'style' => (string) ($gender_display['style'] ?? ''),
                'html' => (string) ($gender_display['html'] ?? ''),
            ],
        ]);
    }

    if ($mode === 'plurality') {
        if (!function_exists('ll_tools_wordset_has_plurality') || !ll_tools_wordset_has_plurality($wordset_id)) {
            wp_send_json_error(__('Plurality not enabled', 'll-tools-text-domain'), 400);
        }

        $plurality_value = sanitize_text_field($_POST['grammatical_plurality'] ?? '');
        $plurality_value = trim($plurality_value);
        if ($plurality_value === '') {
            wp_send_json_error(__('Missing plurality', 'll-tools-text-domain'), 400);
        }

        $plurality_allowed = function_exists('ll_tools_wordset_get_plurality_options')
            ? ll_tools_wordset_get_plurality_options($wordset_id)
            : [];
        if (!in_array($plurality_value, $plurality_allowed, true)) {
            wp_send_json_error(__('Invalid plurality', 'll-tools-text-domain'), 400);
        }

        $pos_map = ll_tools_word_grid_collect_part_of_speech_terms($word_ids);
        $updated = [];
        foreach ($word_ids as $word_id) {
            $word_id = (int) $word_id;
            if ($word_id <= 0) {
                continue;
            }
            $pos_slug = isset($pos_map[$word_id]['slug']) ? (string) $pos_map[$word_id]['slug'] : '';
            if ($pos_slug !== 'noun') {
                continue;
            }
            update_post_meta($word_id, 'll_grammatical_plurality', $plurality_value);
            $updated[] = $word_id;
        }

        $plurality_label = function_exists('ll_tools_wordset_get_plurality_label')
            ? ll_tools_wordset_get_plurality_label($wordset_id, $plurality_value)
            : $plurality_value;

        if (!empty($updated)) {
            ll_tools_word_grid_bump_category_cache_for_words($updated, $category_id);
        }

        wp_send_json_success([
            'word_ids' => $updated,
            'count' => count($updated),
            'skipped' => max(0, count($word_ids) - count($updated)),
            'grammatical_plurality' => [
                'value' => $plurality_value,
                'label' => $plurality_label,
            ],
        ]);
    }

    if ($mode === 'verb_tense') {
        if (!function_exists('ll_tools_wordset_has_verb_tense') || !ll_tools_wordset_has_verb_tense($wordset_id)) {
            wp_send_json_error(__('Verb tense not enabled', 'll-tools-text-domain'), 400);
        }

        $verb_tense_value = sanitize_text_field($_POST['verb_tense'] ?? '');
        $verb_tense_value = trim($verb_tense_value);
        if ($verb_tense_value === '') {
            wp_send_json_error(__('Missing verb tense', 'll-tools-text-domain'), 400);
        }

        $verb_tense_allowed = function_exists('ll_tools_wordset_get_verb_tense_options')
            ? ll_tools_wordset_get_verb_tense_options($wordset_id)
            : [];
        if (!in_array($verb_tense_value, $verb_tense_allowed, true)) {
            wp_send_json_error(__('Invalid verb tense', 'll-tools-text-domain'), 400);
        }

        $pos_map = ll_tools_word_grid_collect_part_of_speech_terms($word_ids);
        $updated = [];
        foreach ($word_ids as $word_id) {
            $word_id = (int) $word_id;
            if ($word_id <= 0) {
                continue;
            }
            $pos_slug = isset($pos_map[$word_id]['slug']) ? (string) $pos_map[$word_id]['slug'] : '';
            if ($pos_slug !== 'verb') {
                continue;
            }
            update_post_meta($word_id, 'll_verb_tense', $verb_tense_value);
            $updated[] = $word_id;
        }

        $verb_tense_label = function_exists('ll_tools_wordset_get_verb_tense_label')
            ? ll_tools_wordset_get_verb_tense_label($wordset_id, $verb_tense_value)
            : $verb_tense_value;

        if (!empty($updated)) {
            ll_tools_word_grid_bump_category_cache_for_words($updated, $category_id);
        }

        wp_send_json_success([
            'word_ids' => $updated,
            'count' => count($updated),
            'skipped' => max(0, count($word_ids) - count($updated)),
            'verb_tense' => [
                'value' => $verb_tense_value,
                'label' => $verb_tense_label,
            ],
        ]);
    }

    if ($mode === 'verb_mood') {
        if (!function_exists('ll_tools_wordset_has_verb_mood') || !ll_tools_wordset_has_verb_mood($wordset_id)) {
            wp_send_json_error(__('Verb mood not enabled', 'll-tools-text-domain'), 400);
        }

        $verb_mood_value = sanitize_text_field($_POST['verb_mood'] ?? '');
        $verb_mood_value = trim($verb_mood_value);
        if ($verb_mood_value === '') {
            wp_send_json_error(__('Missing verb mood', 'll-tools-text-domain'), 400);
        }

        $verb_mood_allowed = function_exists('ll_tools_wordset_get_verb_mood_options')
            ? ll_tools_wordset_get_verb_mood_options($wordset_id)
            : [];
        if (!in_array($verb_mood_value, $verb_mood_allowed, true)) {
            wp_send_json_error(__('Invalid verb mood', 'll-tools-text-domain'), 400);
        }

        $pos_map = ll_tools_word_grid_collect_part_of_speech_terms($word_ids);
        $updated = [];
        foreach ($word_ids as $word_id) {
            $word_id = (int) $word_id;
            if ($word_id <= 0) {
                continue;
            }
            $pos_slug = isset($pos_map[$word_id]['slug']) ? (string) $pos_map[$word_id]['slug'] : '';
            if ($pos_slug !== 'verb') {
                continue;
            }
            update_post_meta($word_id, 'll_verb_mood', $verb_mood_value);
            $updated[] = $word_id;
        }

        $verb_mood_label = function_exists('ll_tools_wordset_get_verb_mood_label')
            ? ll_tools_wordset_get_verb_mood_label($wordset_id, $verb_mood_value)
            : $verb_mood_value;

        if (!empty($updated)) {
            ll_tools_word_grid_bump_category_cache_for_words($updated, $category_id);
        }

        wp_send_json_success([
            'word_ids' => $updated,
            'count' => count($updated),
            'skipped' => max(0, count($word_ids) - count($updated)),
            'verb_mood' => [
                'value' => $verb_mood_value,
                'label' => $verb_mood_label,
            ],
        ]);
    }
}

add_action('wp_ajax_ll_tools_word_grid_bulk_undo', 'll_tools_word_grid_bulk_undo_handler');
function ll_tools_word_grid_bulk_undo_handler() {
    check_ajax_referer('ll_word_grid_edit', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error([
            'message' => __('You must be logged in to undo bulk changes.', 'll-tools-text-domain'),
        ], 401);
    }

    $wordset_id = (int) ($_POST['wordset_id'] ?? 0);
    $category_id = (int) ($_POST['category_id'] ?? 0);
    if ($wordset_id <= 0 || $category_id <= 0) {
        wp_send_json_error([
            'message' => __('Missing word set or category.', 'll-tools-text-domain'),
        ], 400);
    }
    if (!ll_tools_word_grid_user_can_manage_wordset_scope($wordset_id)) {
        wp_send_json_error([
            'message' => __('You do not have permission to undo bulk changes for this word set.', 'll-tools-text-domain'),
        ], 403);
    }

    $mode = sanitize_text_field($_POST['mode'] ?? '');
    if (!in_array($mode, ['pos', 'gender', 'plurality', 'verb_tense', 'verb_mood'], true)) {
        wp_send_json_error([
            'message' => __('Invalid bulk edit mode.', 'll-tools-text-domain'),
        ], 400);
    }

    $word_ids = ll_tools_get_lesson_word_ids_for_transcription($wordset_id, $category_id);
    $snapshot_rows = ll_tools_word_grid_parse_bulk_snapshot_payload($_POST['snapshot'] ?? '', $word_ids);
    if (empty($snapshot_rows)) {
        wp_send_json_error([
            'message' => __('Nothing was available to undo.', 'll-tools-text-domain'),
        ], 400);
    }

    $restored_ids = [];

    if ($mode === 'pos') {
        $gender_enabled = function_exists('ll_tools_wordset_has_grammatical_gender')
            ? ll_tools_wordset_has_grammatical_gender($wordset_id)
            : false;
        $plurality_enabled = function_exists('ll_tools_wordset_has_plurality')
            ? ll_tools_wordset_has_plurality($wordset_id)
            : false;
        $verb_tense_enabled = function_exists('ll_tools_wordset_has_verb_tense')
            ? ll_tools_wordset_has_verb_tense($wordset_id)
            : false;
        $verb_mood_enabled = function_exists('ll_tools_wordset_has_verb_mood')
            ? ll_tools_wordset_has_verb_mood($wordset_id)
            : false;
        $gender_options = $gender_enabled && function_exists('ll_tools_wordset_get_gender_options')
            ? ll_tools_wordset_get_gender_options($wordset_id)
            : [];
        $plurality_options = $plurality_enabled && function_exists('ll_tools_wordset_get_plurality_options')
            ? ll_tools_wordset_get_plurality_options($wordset_id)
            : [];
        $verb_tense_options = $verb_tense_enabled && function_exists('ll_tools_wordset_get_verb_tense_options')
            ? ll_tools_wordset_get_verb_tense_options($wordset_id)
            : [];
        $verb_mood_options = $verb_mood_enabled && function_exists('ll_tools_wordset_get_verb_mood_options')
            ? ll_tools_wordset_get_verb_mood_options($wordset_id)
            : [];

        foreach ($snapshot_rows as $row) {
            $word_id = (int) ($row['word_id'] ?? 0);
            if ($word_id <= 0) {
                continue;
            }

            $restored_pos_slug = '';
            $restored_pos = trim((string) ($row['part_of_speech'] ?? ''));
            if ($restored_pos !== '') {
                $pos_term = get_term_by('slug', sanitize_title($restored_pos), 'part_of_speech');
                if ($pos_term && !is_wp_error($pos_term)) {
                    wp_set_object_terms($word_id, [(int) $pos_term->term_id], 'part_of_speech', false);
                    $restored_pos_slug = (string) $pos_term->slug;
                } else {
                    wp_set_object_terms($word_id, [], 'part_of_speech', false);
                }
            } else {
                wp_set_object_terms($word_id, [], 'part_of_speech', false);
            }

            if ($gender_enabled && $restored_pos_slug === 'noun') {
                $gender_value = trim((string) ($row['grammatical_gender'] ?? ''));
                $gender_value = function_exists('ll_tools_wordset_normalize_gender_value_for_options')
                    ? ll_tools_wordset_normalize_gender_value_for_options($gender_value, $gender_options)
                    : $gender_value;
                if ($gender_value === '') {
                    delete_post_meta($word_id, 'll_grammatical_gender');
                } else {
                    update_post_meta($word_id, 'll_grammatical_gender', $gender_value);
                }
            } else {
                delete_post_meta($word_id, 'll_grammatical_gender');
            }

            if ($plurality_enabled && $restored_pos_slug === 'noun') {
                $plurality_value = ll_tools_word_grid_match_option_value_case_insensitive(
                    trim((string) ($row['grammatical_plurality'] ?? '')),
                    $plurality_options
                );
                if ($plurality_value === '') {
                    delete_post_meta($word_id, 'll_grammatical_plurality');
                } else {
                    update_post_meta($word_id, 'll_grammatical_plurality', $plurality_value);
                }
            } else {
                delete_post_meta($word_id, 'll_grammatical_plurality');
            }

            if ($verb_tense_enabled && $restored_pos_slug === 'verb') {
                $verb_tense_value = ll_tools_word_grid_match_option_value_case_insensitive(
                    trim((string) ($row['verb_tense'] ?? '')),
                    $verb_tense_options
                );
                if ($verb_tense_value === '') {
                    delete_post_meta($word_id, 'll_verb_tense');
                } else {
                    update_post_meta($word_id, 'll_verb_tense', $verb_tense_value);
                }
            } else {
                delete_post_meta($word_id, 'll_verb_tense');
            }

            if ($verb_mood_enabled && $restored_pos_slug === 'verb') {
                $verb_mood_value = ll_tools_word_grid_match_option_value_case_insensitive(
                    trim((string) ($row['verb_mood'] ?? '')),
                    $verb_mood_options
                );
                if ($verb_mood_value === '') {
                    delete_post_meta($word_id, 'll_verb_mood');
                } else {
                    update_post_meta($word_id, 'll_verb_mood', $verb_mood_value);
                }
            } else {
                delete_post_meta($word_id, 'll_verb_mood');
            }

            $restored_ids[] = $word_id;
        }
    } elseif ($mode === 'gender') {
        if (!function_exists('ll_tools_wordset_has_grammatical_gender') || !ll_tools_wordset_has_grammatical_gender($wordset_id)) {
            wp_send_json_error([
                'message' => __('Gender is not enabled for this word set.', 'll-tools-text-domain'),
            ], 400);
        }

        $gender_options = function_exists('ll_tools_wordset_get_gender_options')
            ? ll_tools_wordset_get_gender_options($wordset_id)
            : [];
        $pos_map = ll_tools_word_grid_collect_part_of_speech_terms(wp_list_pluck($snapshot_rows, 'word_id'));

        foreach ($snapshot_rows as $row) {
            $word_id = (int) ($row['word_id'] ?? 0);
            if ($word_id <= 0) {
                continue;
            }
            $pos_slug = isset($pos_map[$word_id]['slug']) ? (string) $pos_map[$word_id]['slug'] : '';
            if ($pos_slug !== 'noun') {
                delete_post_meta($word_id, 'll_grammatical_gender');
                $restored_ids[] = $word_id;
                continue;
            }

            $gender_value = trim((string) ($row['grammatical_gender'] ?? ''));
            $gender_value = function_exists('ll_tools_wordset_normalize_gender_value_for_options')
                ? ll_tools_wordset_normalize_gender_value_for_options($gender_value, $gender_options)
                : $gender_value;
            if ($gender_value === '') {
                delete_post_meta($word_id, 'll_grammatical_gender');
            } else {
                update_post_meta($word_id, 'll_grammatical_gender', $gender_value);
            }
            $restored_ids[] = $word_id;
        }
    } elseif ($mode === 'plurality') {
        if (!function_exists('ll_tools_wordset_has_plurality') || !ll_tools_wordset_has_plurality($wordset_id)) {
            wp_send_json_error([
                'message' => __('Plurality is not enabled for this word set.', 'll-tools-text-domain'),
            ], 400);
        }

        $plurality_options = function_exists('ll_tools_wordset_get_plurality_options')
            ? ll_tools_wordset_get_plurality_options($wordset_id)
            : [];
        $pos_map = ll_tools_word_grid_collect_part_of_speech_terms(wp_list_pluck($snapshot_rows, 'word_id'));

        foreach ($snapshot_rows as $row) {
            $word_id = (int) ($row['word_id'] ?? 0);
            if ($word_id <= 0) {
                continue;
            }
            $pos_slug = isset($pos_map[$word_id]['slug']) ? (string) $pos_map[$word_id]['slug'] : '';
            if ($pos_slug !== 'noun') {
                delete_post_meta($word_id, 'll_grammatical_plurality');
                $restored_ids[] = $word_id;
                continue;
            }

            $plurality_value = ll_tools_word_grid_match_option_value_case_insensitive(
                trim((string) ($row['grammatical_plurality'] ?? '')),
                $plurality_options
            );
            if ($plurality_value === '') {
                delete_post_meta($word_id, 'll_grammatical_plurality');
            } else {
                update_post_meta($word_id, 'll_grammatical_plurality', $plurality_value);
            }
            $restored_ids[] = $word_id;
        }
    } elseif ($mode === 'verb_tense') {
        if (!function_exists('ll_tools_wordset_has_verb_tense') || !ll_tools_wordset_has_verb_tense($wordset_id)) {
            wp_send_json_error([
                'message' => __('Verb tense is not enabled for this word set.', 'll-tools-text-domain'),
            ], 400);
        }

        $verb_tense_options = function_exists('ll_tools_wordset_get_verb_tense_options')
            ? ll_tools_wordset_get_verb_tense_options($wordset_id)
            : [];
        $pos_map = ll_tools_word_grid_collect_part_of_speech_terms(wp_list_pluck($snapshot_rows, 'word_id'));

        foreach ($snapshot_rows as $row) {
            $word_id = (int) ($row['word_id'] ?? 0);
            if ($word_id <= 0) {
                continue;
            }
            $pos_slug = isset($pos_map[$word_id]['slug']) ? (string) $pos_map[$word_id]['slug'] : '';
            if ($pos_slug !== 'verb') {
                delete_post_meta($word_id, 'll_verb_tense');
                $restored_ids[] = $word_id;
                continue;
            }

            $verb_tense_value = ll_tools_word_grid_match_option_value_case_insensitive(
                trim((string) ($row['verb_tense'] ?? '')),
                $verb_tense_options
            );
            if ($verb_tense_value === '') {
                delete_post_meta($word_id, 'll_verb_tense');
            } else {
                update_post_meta($word_id, 'll_verb_tense', $verb_tense_value);
            }
            $restored_ids[] = $word_id;
        }
    } elseif ($mode === 'verb_mood') {
        if (!function_exists('ll_tools_wordset_has_verb_mood') || !ll_tools_wordset_has_verb_mood($wordset_id)) {
            wp_send_json_error([
                'message' => __('Verb mood is not enabled for this word set.', 'll-tools-text-domain'),
            ], 400);
        }

        $verb_mood_options = function_exists('ll_tools_wordset_get_verb_mood_options')
            ? ll_tools_wordset_get_verb_mood_options($wordset_id)
            : [];
        $pos_map = ll_tools_word_grid_collect_part_of_speech_terms(wp_list_pluck($snapshot_rows, 'word_id'));

        foreach ($snapshot_rows as $row) {
            $word_id = (int) ($row['word_id'] ?? 0);
            if ($word_id <= 0) {
                continue;
            }
            $pos_slug = isset($pos_map[$word_id]['slug']) ? (string) $pos_map[$word_id]['slug'] : '';
            if ($pos_slug !== 'verb') {
                delete_post_meta($word_id, 'll_verb_mood');
                $restored_ids[] = $word_id;
                continue;
            }

            $verb_mood_value = ll_tools_word_grid_match_option_value_case_insensitive(
                trim((string) ($row['verb_mood'] ?? '')),
                $verb_mood_options
            );
            if ($verb_mood_value === '') {
                delete_post_meta($word_id, 'll_verb_mood');
            } else {
                update_post_meta($word_id, 'll_verb_mood', $verb_mood_value);
            }
            $restored_ids[] = $word_id;
        }
    }

    $restored_ids = array_values(array_unique(array_map('intval', $restored_ids)));
    if (!empty($restored_ids)) {
        ll_tools_word_grid_bump_category_cache_for_words($restored_ids, $category_id);
    }

    $restored_words = [];
    foreach ($restored_ids as $word_id) {
        $restored_words[] = ll_tools_word_grid_get_word_meta_payload($word_id, $wordset_id);
    }

    wp_send_json_success([
        'message' => __('Bulk changes undone.', 'll-tools-text-domain'),
        'word_ids' => $restored_ids,
        'count' => count($restored_ids),
        'words' => $restored_words,
    ]);
}

add_action('wp_ajax_ll_tools_get_lesson_transcribe_queue', 'll_tools_get_lesson_transcribe_queue_handler');
function ll_tools_get_lesson_transcribe_queue_handler() {
    check_ajax_referer('ll_word_grid_edit', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(__('You must be logged in', 'll-tools-text-domain'), 401);
    }

    $mode = sanitize_text_field($_POST['mode'] ?? 'missing');
    $include_existing = in_array($mode, ['all', 'replace'], true);

    $lesson_id = (int) ($_POST['lesson_id'] ?? 0);
    $lesson = $lesson_id ? get_post($lesson_id) : null;
    if (!$lesson || $lesson->post_type !== 'll_vocab_lesson') {
        wp_send_json_error(__('Invalid lesson', 'll-tools-text-domain'), 400);
    }

    [$wordset_id, $category_id] = ll_tools_get_vocab_lesson_ids_from_post($lesson_id);
    if ($wordset_id <= 0 || $category_id <= 0) {
        wp_send_json_error(__('Missing lesson metadata', 'll-tools-text-domain'), 400);
    }
    if (!ll_tools_word_grid_user_can_manage_wordset_scope($wordset_id)) {
        wp_send_json_error(__('Forbidden', 'll-tools-text-domain'), 403);
    }

    $transcription_service = function_exists('ll_tools_get_wordset_transcription_service_config')
        ? ll_tools_get_wordset_transcription_service_config([$wordset_id], true)
        : [
            'uses_local_browser' => false,
            'target_meta_key' => 'recording_text',
            'enabled' => false,
        ];
    if (empty($transcription_service['enabled'])) {
        wp_send_json_error(__('Transcription service not configured', 'll-tools-text-domain'), 400);
    }
    $target_meta_key = (($transcription_service['target_meta_key'] ?? '') === 'recording_ipa')
        ? 'recording_ipa'
        : 'recording_text';
    $uses_local_browser = !empty($transcription_service['uses_local_browser']);

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
        $current_value = trim((string) get_post_meta($recording_id, $target_meta_key, true));
        if (!$include_existing && $current_value !== '') {
            continue;
        }
        if (!ll_tools_lesson_recording_belongs_to($recording_id, $wordset_id, $category_id)) {
            continue;
        }

        $queue_item = [
            'recording_id' => $recording_id,
        ];
        if ($uses_local_browser) {
            $audio_url = ll_tools_word_grid_get_recording_audio_url($recording_id);
            if ($audio_url === '') {
                continue;
            }
            $recording = get_post($recording_id);
            $word_id = ($recording && $recording->post_type === 'word_audio') ? (int) $recording->post_parent : 0;
            $queue_item['audio_url'] = $audio_url;
            $queue_item['audio_filename'] = wp_basename((string) wp_parse_url($audio_url, PHP_URL_PATH));
            $queue_item['recording_type'] = ll_tools_word_grid_get_primary_recording_type_slug($recording_id);
            $queue_item['word_id'] = $word_id;
            $queue_item['word_title'] = $word_id > 0 ? get_the_title($word_id) : '';
        }
        $queue[] = $queue_item;
    }

    wp_send_json_success([
        'queue' => $queue,
        'total' => count($queue),
    ]);
}

add_action('wp_ajax_ll_tools_clear_lesson_transcriptions', 'll_tools_clear_lesson_transcriptions_handler');
function ll_tools_clear_lesson_transcriptions_handler() {
    check_ajax_referer('ll_word_grid_edit', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(__('You must be logged in', 'll-tools-text-domain'), 401);
    }

    $lesson_id = (int) ($_POST['lesson_id'] ?? 0);
    $lesson = $lesson_id ? get_post($lesson_id) : null;
    if (!$lesson || $lesson->post_type !== 'll_vocab_lesson') {
        wp_send_json_error(__('Invalid lesson', 'll-tools-text-domain'), 400);
    }

    [$wordset_id, $category_id] = ll_tools_get_vocab_lesson_ids_from_post($lesson_id);
    if ($wordset_id <= 0 || $category_id <= 0) {
        wp_send_json_error(__('Missing lesson metadata', 'll-tools-text-domain'), 400);
    }
    if (!ll_tools_word_grid_user_can_manage_wordset_scope($wordset_id)) {
        wp_send_json_error(__('Forbidden', 'll-tools-text-domain'), 403);
    }
    $target_meta_key = ll_tools_word_grid_get_transcription_target_meta_key($wordset_id);

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
        if ($target_meta_key === 'recording_ipa') {
            delete_post_meta($recording_id, 'recording_ipa');
        } else {
            delete_post_meta($recording_id, 'recording_text');
            delete_post_meta($recording_id, 'recording_translation');
        }
        $cleared[] = $recording_id;
    }

    if ($target_meta_key === 'recording_ipa') {
        if (function_exists('ll_tools_word_grid_rebuild_wordset_ipa_special_chars')) {
            ll_tools_word_grid_rebuild_wordset_ipa_special_chars($wordset_id);
        }
        if (function_exists('ll_tools_word_grid_rebuild_wordset_ipa_letter_map')) {
            ll_tools_word_grid_rebuild_wordset_ipa_letter_map($wordset_id);
        }
    }

    wp_send_json_success([
        'cleared' => $cleared,
        'total' => count($cleared),
    ]);
}

/**
 * Finalize a lesson recording transcription and return the payload used by the grid UI.
 *
 * @param int     $recording_id
 * @param WP_Post $recording
 * @param int[]   $wordset_ids
 * @param bool    $force
 * @param string  $raw_transcript
 * @return array|WP_Error
 */
function ll_tools_word_grid_finalize_transcription($recording_id, $recording, $wordset_ids, $force, $raw_transcript) {
    $transcript = trim((string) $raw_transcript);
    if ($transcript !== '' && function_exists('ll_tools_normalize_transcript_case')) {
        $transcript = ll_tools_normalize_transcript_case($transcript, $wordset_ids);
    }
    if ($transcript === '') {
        return new WP_Error('empty_transcript', __('Empty transcript', 'll-tools-text-domain'));
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

    $transcription_mode = function_exists('ll_tools_get_wordset_recording_transcription_mode')
        ? ll_tools_get_wordset_recording_transcription_mode([ll_tools_word_grid_get_wordset_id_for_word($word_id)], true)
        : 'ipa';

    $response = [
        'recording' => [
            'id' => $recording_id,
            'word_id' => $word_id,
            'recording_text' => $recording_text,
            'recording_translation' => $recording_translation,
            'recording_ipa' => ll_tools_word_grid_normalize_ipa_output((string) get_post_meta($recording_id, 'recording_ipa', true), $transcription_mode),
            'review_fields' => function_exists('ll_tools_ipa_keyboard_get_recording_review_fields')
                ? ll_tools_ipa_keyboard_get_recording_review_fields($recording_id)
                : ['recording_text' => false, 'recording_ipa' => false],
            'review_note' => function_exists('ll_tools_ipa_keyboard_get_recording_review_note')
                ? ll_tools_ipa_keyboard_get_recording_review_note($recording_id)
                : '',
        ],
    ];
    if ($word_payload) {
        $response['word'] = $word_payload;
    }

    return $response;
}

function ll_tools_word_grid_finalize_secondary_transcription($recording_id, $recording, $wordset_id, $raw_transcript) {
    $word_id = (int) $recording->post_parent;
    $transcription_mode = function_exists('ll_tools_get_wordset_recording_transcription_mode')
        ? ll_tools_get_wordset_recording_transcription_mode([$wordset_id], true)
        : 'ipa';

    $clean = function_exists('ll_tools_word_grid_sanitize_ipa')
        ? ll_tools_word_grid_sanitize_ipa((string) $raw_transcript, $transcription_mode)
        : sanitize_text_field((string) $raw_transcript);
    if ($clean === '') {
        return new WP_Error('empty_transcript', __('Empty transcript', 'll-tools-text-domain'));
    }

    update_post_meta($recording_id, 'recording_ipa', $clean);
    if (function_exists('ll_tools_ipa_keyboard_mark_recording_needs_auto_review')) {
        ll_tools_ipa_keyboard_mark_recording_needs_auto_review($recording_id, 'recording_ipa');
    }

    if ($wordset_id > 0) {
        if (function_exists('ll_tools_word_grid_rebuild_wordset_ipa_special_chars')) {
            ll_tools_word_grid_rebuild_wordset_ipa_special_chars($wordset_id);
        }
        if (function_exists('ll_tools_word_grid_rebuild_wordset_ipa_letter_map')) {
            ll_tools_word_grid_rebuild_wordset_ipa_letter_map($wordset_id);
        }
    }

    return [
        'recording' => [
            'id' => $recording_id,
            'word_id' => $word_id,
            'recording_text' => trim((string) get_post_meta($recording_id, 'recording_text', true)),
            'recording_translation' => trim((string) get_post_meta($recording_id, 'recording_translation', true)),
            'recording_ipa' => ll_tools_word_grid_normalize_ipa_output((string) get_post_meta($recording_id, 'recording_ipa', true), $transcription_mode),
            'review_fields' => function_exists('ll_tools_ipa_keyboard_get_recording_review_fields')
                ? ll_tools_ipa_keyboard_get_recording_review_fields($recording_id)
                : ['recording_text' => false, 'recording_ipa' => false],
            'review_note' => function_exists('ll_tools_ipa_keyboard_get_recording_review_note')
                ? ll_tools_ipa_keyboard_get_recording_review_note($recording_id)
                : '',
        ],
    ];
}

add_action('wp_ajax_ll_tools_transcribe_recording_by_id', 'll_tools_transcribe_recording_by_id_handler');
function ll_tools_transcribe_recording_by_id_handler() {
    check_ajax_referer('ll_word_grid_edit', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(__('You must be logged in', 'll-tools-text-domain'), 401);
    }

    $lesson_id = (int) ($_POST['lesson_id'] ?? 0);
    $recording_id = (int) ($_POST['recording_id'] ?? 0);
    $force = !empty($_POST['force']);
    $posted_transcript_id = sanitize_text_field($_POST['transcript_id'] ?? '');
    $local_transcript = isset($_POST['local_transcript']) ? wp_unslash((string) $_POST['local_transcript']) : '';
    if ($lesson_id <= 0 || $recording_id <= 0) {
        wp_send_json_error(__('Missing data', 'll-tools-text-domain'), 400);
    }

    $lesson = get_post($lesson_id);
    if (!$lesson || $lesson->post_type !== 'll_vocab_lesson') {
        wp_send_json_error(__('Invalid lesson', 'll-tools-text-domain'), 400);
    }

    [$wordset_id, $category_id] = ll_tools_get_vocab_lesson_ids_from_post($lesson_id);
    if ($wordset_id <= 0 || $category_id <= 0) {
        wp_send_json_error(__('Missing lesson metadata', 'll-tools-text-domain'), 400);
    }
    if (!ll_tools_word_grid_user_can_manage_wordset_scope($wordset_id)) {
        wp_send_json_error(__('Forbidden', 'll-tools-text-domain'), 403);
    }
    if (!ll_tools_can_transcribe_recordings([$wordset_id])) {
        wp_send_json_error(__('Transcription service not configured', 'll-tools-text-domain'), 400);
    }

    if (!ll_tools_lesson_recording_belongs_to($recording_id, $wordset_id, $category_id)) {
        wp_send_json_error(__('Recording not in lesson', 'll-tools-text-domain'), 403);
    }

    $recording = get_post($recording_id);
    if (!$recording || $recording->post_type !== 'word_audio') {
        wp_send_json_error(__('Invalid recording', 'll-tools-text-domain'), 400);
    }

    $transcription_service = function_exists('ll_tools_get_wordset_transcription_service_config')
        ? ll_tools_get_wordset_transcription_service_config([$wordset_id], true)
        : [
            'provider' => 'assemblyai',
            'target_meta_key' => 'recording_text',
            'uses_local_browser' => false,
        ];
    $wordset_ids = [$wordset_id];
    $target_meta_key = (($transcription_service['target_meta_key'] ?? '') === 'recording_ipa')
        ? 'recording_ipa'
        : 'recording_text';

    $existing_target_value = trim((string) get_post_meta($recording_id, $target_meta_key, true));
    if ($posted_transcript_id === '' && $local_transcript === '' && !$force && $existing_target_value !== '') {
        delete_post_meta($recording_id, 'll_tools_assemblyai_transcript_id');
        wp_send_json_success([
            'skipped' => true,
        ]);
    }

    if (!empty($transcription_service['uses_local_browser'])) {
        if ($local_transcript === '') {
            wp_send_json_error(__('Local transcript missing', 'll-tools-text-domain'), 400);
        }

        $response = ($target_meta_key === 'recording_ipa')
            ? ll_tools_word_grid_finalize_secondary_transcription($recording_id, $recording, $wordset_id, $local_transcript)
            : ll_tools_word_grid_finalize_transcription($recording_id, $recording, $wordset_ids, $force, $local_transcript);
        if (is_wp_error($response)) {
            wp_send_json_error($response->get_error_message(), 400);
        }

        delete_post_meta($recording_id, 'll_tools_assemblyai_transcript_id');
        wp_send_json_success($response);
    }

    if (($transcription_service['provider'] ?? '') === 'hosted_api') {
        $audio_path = (string) get_post_meta($recording_id, 'audio_file_path', true);
        $file_info = ll_tools_resolve_audio_file_for_transcription($audio_path);
        $file_path = (string) ($file_info['path'] ?? '');
        $is_temp = !empty($file_info['is_temp']);
        if ($file_path === '' || !file_exists($file_path) || !is_readable($file_path)) {
            if ($is_temp && $file_path !== '' && file_exists($file_path)) {
                @unlink($file_path);
            }
            wp_send_json_error(__('Audio file missing', 'll-tools-text-domain'), 400);
        }

        $endpoint = (string) ($transcription_service['local_endpoint'] ?? '');
        $token = function_exists('ll_tools_get_wordset_transcription_api_token')
            ? ll_tools_get_wordset_transcription_api_token($wordset_ids, true)
            : '';
        $remote_result = ll_tools_remote_stt_transcribe_audio_file($endpoint, $file_path, [
            'token' => $token,
            'filename' => wp_basename($file_path),
            'fields' => [
                'wordset_id' => (string) $wordset_id,
                'recording_id' => (string) $recording_id,
                'target_field' => $target_meta_key,
                'recording_type' => (string) ll_tools_normalize_practice_recording_type_slug((string) get_post_meta($recording_id, 'recording_type', true)),
                'word_title' => html_entity_decode((string) get_the_title((int) $recording->post_parent), ENT_QUOTES, 'UTF-8'),
            ],
        ]);
        if ($is_temp && $file_path !== '' && file_exists($file_path)) {
            @unlink($file_path);
        }
        if (is_wp_error($remote_result)) {
            wp_send_json_error($remote_result->get_error_message(), 500);
        }

        $remote_transcript = trim((string) ($remote_result['transcript'] ?? ''));
        $response = ($target_meta_key === 'recording_ipa')
            ? ll_tools_word_grid_finalize_secondary_transcription($recording_id, $recording, $wordset_id, $remote_transcript)
            : ll_tools_word_grid_finalize_transcription($recording_id, $recording, $wordset_ids, $force, $remote_transcript);
        if (is_wp_error($response)) {
            wp_send_json_error($response->get_error_message(), 400);
        }

        delete_post_meta($recording_id, 'll_tools_assemblyai_transcript_id');
        $response['pending'] = false;
        $response['transcript_id'] = '';
        $response['status'] = 'completed';
        wp_send_json_success($response);
    }

    if (!function_exists('ll_tools_assemblyai_start_transcription') || !function_exists('ll_tools_assemblyai_get_transcript')) {
        wp_send_json_error(__('AssemblyAI integration not available', 'll-tools-text-domain'), 400);
    }

    $language_code = function_exists('ll_tools_get_assemblyai_language_code')
        ? ll_tools_get_assemblyai_language_code($wordset_ids)
        : '';
    $transcript_meta_key = 'll_tools_assemblyai_transcript_id';
    $stored_transcript_id = trim((string) get_post_meta($recording_id, $transcript_meta_key, true));

    if ($posted_transcript_id !== '') {
        if ($stored_transcript_id === '') {
            wp_send_json_error(__('Transcription session expired', 'll-tools-text-domain'), 409);
        }
        if (!hash_equals($stored_transcript_id, $posted_transcript_id)) {
            wp_send_json_error(__('Transcription session mismatch', 'll-tools-text-domain'), 409);
        }
    }

    if ($posted_transcript_id === '') {
        $existing_text = trim((string) get_post_meta($recording_id, 'recording_text', true));
        if (!$force && $existing_text !== '') {
            delete_post_meta($recording_id, $transcript_meta_key);
            wp_send_json_success([
                'skipped' => true,
            ]);
        }
    }

    $transcript_id = '';
    if ($posted_transcript_id !== '') {
        $transcript_id = $posted_transcript_id;
    } elseif ($stored_transcript_id !== '') {
        $transcript_id = $stored_transcript_id;
    } else {
        $audio_path = (string) get_post_meta($recording_id, 'audio_file_path', true);
        $file_info = ll_tools_resolve_audio_file_for_transcription($audio_path);
        $file_path = (string) ($file_info['path'] ?? '');
        $is_temp = !empty($file_info['is_temp']);
        if ($file_path === '' || !file_exists($file_path) || !is_readable($file_path)) {
            if ($is_temp && $file_path !== '' && file_exists($file_path)) {
                @unlink($file_path);
            }
            wp_send_json_error(__('Audio file missing', 'll-tools-text-domain'), 400);
        }

        $transcript_id = ll_tools_assemblyai_start_transcription($file_path, $language_code);
        if ($is_temp && $file_path !== '' && file_exists($file_path)) {
            @unlink($file_path);
        }
        if (is_wp_error($transcript_id)) {
            wp_send_json_error($transcript_id->get_error_message(), 500);
        }
        $transcript_id = (string) $transcript_id;
    }

    $status = ll_tools_assemblyai_get_transcript($transcript_id);
    if (is_wp_error($status)) {
        wp_send_json_error($status->get_error_message(), 500);
    }

    $state = isset($status['status']) ? (string) $status['status'] : '';
    if ($state === 'completed') {
        delete_post_meta($recording_id, $transcript_meta_key);
        $response = ll_tools_word_grid_finalize_transcription(
            $recording_id,
            $recording,
            $wordset_ids,
            $force,
            (string) ($status['text'] ?? '')
        );
        if (is_wp_error($response)) {
            wp_send_json_error($response->get_error_message(), 500);
        }
        $response['pending'] = false;
        $response['transcript_id'] = $transcript_id;
        $response['status'] = 'completed';
        wp_send_json_success($response);
    }

    if ($state === 'error') {
        delete_post_meta($recording_id, $transcript_meta_key);
        $message = isset($status['error']) ? (string) $status['error'] : __('AssemblyAI transcription failed.', 'll-tools-text-domain');
        wp_send_json_error($message, 500);
    }

    update_post_meta($recording_id, $transcript_meta_key, $transcript_id);
    wp_send_json_success([
        'pending' => true,
        'transcript_id' => $transcript_id,
        'status' => ($state !== '' ? $state : 'processing'),
        'language_code' => $language_code,
    ]);
}

// Register the 'word_grid' shortcode
function ll_tools_register_word_grid_shortcode() {
    add_shortcode('word_grid', 'll_tools_word_grid_shortcode');
}
add_action('init', 'll_tools_register_word_grid_shortcode');
