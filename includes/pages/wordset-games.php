<?php
// /includes/pages/wordset-games.php
if (!defined('WPINC')) { die; }

function ll_tools_wordset_games_min_word_count(): int {
    return max(5, (int) apply_filters('ll_tools_wordset_games_min_word_count', 5));
}

function ll_tools_wordset_games_space_shooter_launch_word_cap(): int {
    $minimum = ll_tools_wordset_games_min_word_count();
    return max($minimum, (int) apply_filters('ll_tools_wordset_games_space_shooter_launch_word_cap', 60));
}

function ll_tools_wordset_games_bubble_pop_launch_word_cap(): int {
    $minimum = ll_tools_wordset_games_min_word_count();
    return max($minimum, (int) apply_filters('ll_tools_wordset_games_bubble_pop_launch_word_cap', 60));
}

function ll_tools_wordset_games_speaking_practice_launch_word_cap(): int {
    $minimum = ll_tools_wordset_games_min_word_count();
    return max($minimum, (int) apply_filters('ll_tools_wordset_games_speaking_practice_launch_word_cap', 60));
}

function ll_tools_wordset_games_speaking_stack_launch_word_cap(): int {
    $minimum = ll_tools_wordset_games_min_word_count();
    return max($minimum, (int) apply_filters('ll_tools_wordset_games_speaking_stack_launch_word_cap', 60));
}

function ll_tools_wordset_games_render_page_icon(string $class = 'll-wordset-games-icon'): string {
    $class_attr = $class !== '' ? ' class="' . esc_attr($class) . '"' : '';
    return '<svg' . $class_attr . ' viewBox="0 0 256 256" width="18" height="18" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">'
        . '<path d="M72 88C52 88 38 102 34 122L26 162C22 184 38 202 58 202C70 202 80 197 88 188L102 172H154L168 188C176 197 186 202 198 202C218 202 234 184 230 162L222 122C218 102 204 88 184 88H72Z" fill="#2F3B52"/>'
        . '<rect x="72" y="114" width="14" height="40" rx="4" fill="#FFFFFF"/>'
        . '<rect x="59" y="127" width="40" height="14" rx="4" fill="#FFFFFF"/>'
        . '<circle cx="178" cy="121" r="10" fill="#FFFFFF"/>'
        . '<circle cx="196" cy="139" r="10" fill="#FFFFFF"/>'
        . '<circle cx="160" cy="139" r="10" fill="#FFFFFF"/>'
        . '<circle cx="178" cy="157" r="10" fill="#FFFFFF"/>'
        . '<rect x="112" y="124" width="14" height="8" rx="4" fill="#D9E2F2"/>'
        . '<rect x="130" y="124" width="14" height="8" rx="4" fill="#D9E2F2"/>'
        . '</svg>';
}

function ll_tools_wordset_games_render_icon(string $class = 'll-wordset-games-icon'): string {
    $class_attr = $class !== '' ? ' class="' . esc_attr($class) . '"' : '';
    return '<svg' . $class_attr . ' viewBox="0 0 24 24" width="18" height="18" xmlns="http://www.w3.org/2000/svg" fill="none" aria-hidden="true" focusable="false">'
        . '<path d="M12 3.2L17.95 17.9L12 14.65L6.05 17.9L12 3.2Z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/>'
        . '<path d="M12 6.55L14.15 11.9H9.85L12 6.55Z" fill="currentColor"/>'
        . '<path d="M9.65 15.1C9.86 16.44 10.74 17.45 12 17.45C13.26 17.45 14.14 16.44 14.35 15.1H9.65Z" fill="currentColor"/>'
        . '<path d="M9.25 14.1H14.75" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>'
        . '<path d="M8.15 18.05L6.35 20.25" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>'
        . '<path d="M15.85 18.05L17.65 20.25" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>'
        . '</svg>';
}

function ll_tools_wordset_games_render_bubble_icon(string $class = 'll-wordset-games-icon'): string {
    $class_attr = $class !== '' ? ' class="' . esc_attr($class) . '"' : '';
    return '<svg' . $class_attr . ' viewBox="0 0 24 24" width="18" height="18" xmlns="http://www.w3.org/2000/svg" fill="none" aria-hidden="true" focusable="false">'
        . '<circle cx="10.8" cy="12" r="5.8" fill="currentColor" fill-opacity="0.18" stroke="currentColor" stroke-width="1.5"/>'
        . '<circle cx="8.8" cy="9.9" r="1.4" fill="currentColor" fill-opacity="0.72"/>'
        . '<circle cx="17.2" cy="7" r="2.6" fill="currentColor" fill-opacity="0.16" stroke="currentColor" stroke-width="1.3"/>'
        . '<circle cx="16.1" cy="6.1" r="0.8" fill="currentColor" fill-opacity="0.64"/>'
        . '<circle cx="6.5" cy="6.4" r="1.8" fill="currentColor" fill-opacity="0.14" stroke="currentColor" stroke-width="1.1"/>'
        . '</svg>';
}

function ll_tools_wordset_games_render_speaking_icon(string $class = 'll-wordset-games-icon'): string {
    $class_attr = $class !== '' ? ' class="' . esc_attr($class) . '"' : '';
    return '<svg' . $class_attr . ' viewBox="0 0 24 24" width="18" height="18" xmlns="http://www.w3.org/2000/svg" fill="none" aria-hidden="true" focusable="false">'
        . '<path d="M12 3.5c-1.93 0-3.5 1.57-3.5 3.5v4.15c0 1.93 1.57 3.5 3.5 3.5s3.5-1.57 3.5-3.5V7c0-1.93-1.57-3.5-3.5-3.5Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/>'
        . '<path d="M8.4 11.3c0 1.96 1.64 3.55 3.6 3.55s3.6-1.59 3.6-3.55" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>'
        . '<path d="M12 15.1v3.4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>'
        . '<path d="M9.1 18.5h5.8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>'
        . '</svg>';
}

function ll_tools_wordset_games_render_speaking_stack_icon(string $class = 'll-wordset-games-icon'): string {
    $class_attr = $class !== '' ? ' class="' . esc_attr($class) . '"' : '';
    return '<svg' . $class_attr . ' viewBox="0 0 24 24" width="18" height="18" xmlns="http://www.w3.org/2000/svg" fill="none" aria-hidden="true" focusable="false">'
        . '<rect x="4.2" y="15.2" width="6.2" height="4.4" rx="1.2" stroke="currentColor" stroke-width="1.5"/>'
        . '<rect x="13.6" y="15.2" width="6.2" height="4.4" rx="1.2" stroke="currentColor" stroke-width="1.5"/>'
        . '<path d="M12 3.6c-1.41 0-2.55 1.14-2.55 2.55v2.7c0 1.41 1.14 2.55 2.55 2.55s2.55-1.14 2.55-2.55v-2.7c0-1.41-1.14-2.55-2.55-2.55Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>'
        . '<path d="M9.8 8.95c0 1.22.99 2.2 2.2 2.2s2.2-.98 2.2-2.2" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>'
        . '<path d="M12 11.35v1.6" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>'
        . '<path d="M9.9 12.95h4.2" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>'
        . '<path d="M12 14.2v-1.4" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>'
        . '<path d="M12 14.2l-1.65-1.65" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>'
        . '<path d="M12 14.2l1.65-1.65" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>'
        . '</svg>';
}

function ll_tools_wordset_games_render_game_icon(string $slug, string $class = 'll-wordset-games-icon'): string {
    $normalized_slug = sanitize_key($slug);
    if ($normalized_slug === 'bubble-pop') {
        return ll_tools_wordset_games_render_bubble_icon($class);
    }
    if ($normalized_slug === 'speaking-stack') {
        return ll_tools_wordset_games_render_speaking_stack_icon($class);
    }
    if ($normalized_slug === 'speaking-practice') {
        return ll_tools_wordset_games_render_speaking_icon($class);
    }

    return ll_tools_wordset_games_render_icon($class);
}

function ll_tools_wordset_games_base_catalog(): array {
    return [
        'space-shooter' => [
            'slug' => 'space-shooter',
            'title' => __('Space Shooter', 'll-tools-text-domain'),
            'description' => __('Hear the word. Blast the matching picture.', 'll-tools-text-domain'),
        ],
        'bubble-pop' => [
            'slug' => 'bubble-pop',
            'title' => __('Bubble Pop', 'll-tools-text-domain'),
            'description' => __('Hear the word. Pop the matching bubble.', 'll-tools-text-domain'),
        ],
    ];
}

function ll_tools_wordset_games_default_catalog(): array {
    $wordset_term = function_exists('ll_tools_get_wordset_page_term') ? ll_tools_get_wordset_page_term() : null;
    if ($wordset_term instanceof WP_Term && !is_wp_error($wordset_term)) {
        return ll_tools_wordset_games_build_catalog((int) $wordset_term->term_id, get_current_user_id());
    }

    return ll_tools_wordset_games_base_catalog();
}

function ll_tools_wordset_games_categories_for_wordset(int $wordset_id): array {
    $wordset_id = max(0, $wordset_id);
    if ($wordset_id <= 0) {
        return [];
    }

    $query = new WP_Query([
        'post_type' => 'words',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'no_found_rows' => true,
        'suppress_filters' => true,
        'tax_query' => [[
            'taxonomy' => 'wordset',
            'field' => 'term_id',
            'terms' => [$wordset_id],
        ]],
    ]);

    $word_ids = array_values(array_filter(array_map('intval', (array) $query->posts), static function (int $word_id): bool {
        return $word_id > 0;
    }));
    if (empty($word_ids)) {
        return [];
    }

    $terms = get_terms([
        'taxonomy' => 'word-category',
        'hide_empty' => false,
        'object_ids' => $word_ids,
    ]);
    if (is_wp_error($terms) || !is_array($terms)) {
        return [];
    }

    $terms = array_values(array_filter($terms, static function ($term): bool {
        return ($term instanceof WP_Term)
            && $term->taxonomy === 'word-category'
            && (string) $term->slug !== 'uncategorized';
    }));
    if (empty($terms)) {
        return [];
    }

    if (function_exists('ll_tools_filter_category_terms_for_user')) {
        $terms = ll_tools_filter_category_terms_for_user($terms);
    }
    if (empty($terms)) {
        return [];
    }

    $category_name_map = [];
    $category_ids = [];
    $terms_by_id = [];
    foreach ($terms as $term) {
        if (!($term instanceof WP_Term)) {
            continue;
        }

        $category_id = (int) $term->term_id;
        if ($category_id <= 0) {
            continue;
        }

        $category_ids[] = $category_id;
        $category_name_map[$category_id] = (string) $term->name;
        $terms_by_id[$category_id] = $term;
    }

    if (empty($category_ids)) {
        return [];
    }

    if (function_exists('ll_tools_wordset_sort_category_ids')) {
        $category_ids = ll_tools_wordset_sort_category_ids(
            $category_ids,
            $wordset_id,
            ['category_name_map' => $category_name_map]
        );
    }

    $level_info = function_exists('ll_tools_wordset_get_prereq_level_info')
        ? ll_tools_wordset_get_prereq_level_info($wordset_id, $category_ids)
        : ['has_cycle' => false, 'levels' => []];
    $levels = (is_array($level_info) && isset($level_info['levels']) && is_array($level_info['levels']))
        ? $level_info['levels']
        : [];
    $prereq_mode = function_exists('ll_tools_wordset_get_category_ordering_mode')
        ? (ll_tools_wordset_get_category_ordering_mode($wordset_id) === 'prerequisite')
        : false;

    $categories = [];
    foreach ($category_ids as $category_id) {
        if (!isset($terms_by_id[$category_id])) {
            continue;
        }

        $term = $terms_by_id[$category_id];
        $row = [
            'id' => (int) $term->term_id,
            'name' => (string) $term->name,
            'slug' => (string) $term->slug,
        ];
        if ($prereq_mode && empty($level_info['has_cycle'])) {
            $row['logical_order_level'] = (int) ($levels[$category_id] ?? 0);
        }
        $categories[] = $row;
    }

    return $categories;
}

function ll_tools_wordset_games_visible_categories(int $wordset_id, int $user_id = 0): array {
    $wordset_id = max(0, $wordset_id);
    $uid = (int) ($user_id ?: get_current_user_id());
    if ($wordset_id <= 0) {
        return [];
    }

    $categories_payload = ll_tools_wordset_games_categories_for_wordset($wordset_id);
    if (empty($categories_payload)) {
        return [];
    }

    if (function_exists('ll_tools_user_progress_category_ids_in_scope')) {
        $goals = function_exists('ll_tools_get_user_study_goals') && $uid > 0
            ? ll_tools_get_user_study_goals($uid)
            : [];
        $visible_ids = ll_tools_user_progress_category_ids_in_scope($categories_payload, [], $goals, false);
        if (!empty($visible_ids)) {
            $visible_lookup = array_fill_keys(array_map('intval', (array) $visible_ids), true);
            return array_values(array_filter($categories_payload, static function ($row) use ($visible_lookup): bool {
                return is_array($row) && !empty($visible_lookup[(int) ($row['id'] ?? 0)]);
            }));
        }
    }

    return $categories_payload;
}

function ll_tools_wordset_games_visible_category_ids(int $wordset_id, int $user_id = 0): array {
    return array_values(array_filter(array_map(static function ($row): int {
        return is_array($row) ? (int) ($row['id'] ?? 0) : 0;
    }, ll_tools_wordset_games_visible_categories($wordset_id, $user_id)), static function (int $id): bool {
        return $id > 0;
    }));
}

function ll_tools_wordset_games_word_has_recording_type(array $word, string $recording_type): bool {
    $target = ll_tools_normalize_practice_recording_type_slug($recording_type);
    if ($target === '') {
        return false;
    }

    $practice_types = ll_tools_decode_practice_recording_types($word['practice_recording_types'] ?? []);
    if (in_array($target, $practice_types, true)) {
        return true;
    }

    $audio_files = isset($word['audio_files']) && is_array($word['audio_files']) ? $word['audio_files'] : [];
    foreach ($audio_files as $audio_file) {
        if (!is_array($audio_file)) {
            continue;
        }
        $entry_type = ll_tools_normalize_practice_recording_type_slug($audio_file['recording_type'] ?? '');
        $url = trim((string) ($audio_file['url'] ?? ''));
        if ($entry_type === $target && $url !== '') {
            return true;
        }
    }

    return false;
}

/**
 * Wordset games render card art into a canvas, so animated WebPs degrade to a still frame.
 */
function ll_tools_wordset_games_word_has_supported_image(array $word): bool {
    $image_url = trim((string) ($word['image'] ?? ''));
    if ($image_url === '') {
        return false;
    }

    $is_animated_webp = !empty($word['image_is_animated_webp']);
    $allow_animated_webp = (bool) apply_filters('ll_tools_wordset_games_allow_animated_webp_images', false, $word);

    return !$is_animated_webp || $allow_animated_webp;
}

function ll_tools_wordset_games_prompt_recording_types(): array {
    $types = function_exists('ll_tools_get_main_recording_types')
        ? ll_tools_get_main_recording_types()
        : ['isolation', 'question', 'introduction'];

    return function_exists('ll_tools_sort_practice_recording_types')
        ? ll_tools_sort_practice_recording_types($types)
        : array_values(array_unique(array_filter(array_map('sanitize_key', (array) $types))));
}

function ll_tools_wordset_games_get_word_prompt_recording_types(array $word): array {
    $allowed = array_flip(ll_tools_wordset_games_prompt_recording_types());
    if (empty($allowed)) {
        return [];
    }

    $available = [];
    $practice_types = ll_tools_decode_practice_recording_types($word['practice_recording_types'] ?? []);
    foreach ($practice_types as $type) {
        if (isset($allowed[$type]) && ll_tools_wordset_games_word_has_recording_type($word, $type)) {
            $available[$type] = $type;
        }
    }

    $audio_files = isset($word['audio_files']) && is_array($word['audio_files']) ? $word['audio_files'] : [];
    foreach ($audio_files as $audio_file) {
        if (!is_array($audio_file)) {
            continue;
        }
        $url = trim((string) ($audio_file['url'] ?? ''));
        $type = ll_tools_normalize_practice_recording_type_slug($audio_file['recording_type'] ?? '');
        if ($url === '' || $type === '' || !isset($allowed[$type])) {
            continue;
        }
        $available[$type] = $type;
    }

    $out = array_values($available);
    return function_exists('ll_tools_sort_practice_recording_types')
        ? ll_tools_sort_practice_recording_types($out)
        : $out;
}

function ll_tools_wordset_games_collect_visible_words(int $wordset_id, int $user_id = 0): array {
    $wordset_id = max(0, $wordset_id);
    $uid = (int) ($user_id ?: get_current_user_id());
    if ($wordset_id <= 0) {
        return [
            'categories' => [],
            'category_ids' => [],
            'words' => [],
        ];
    }

    $visible_categories = ll_tools_wordset_games_visible_categories($wordset_id, $uid);
    $visible_category_ids = array_values(array_filter(array_map(static function ($row): int {
        return is_array($row) ? (int) ($row['id'] ?? 0) : 0;
    }, $visible_categories), static function (int $id): bool {
        return $id > 0;
    }));
    if (empty($visible_categories) || empty($visible_category_ids)) {
        return [
            'categories' => [],
            'category_ids' => [],
            'words' => [],
        ];
    }

    $wordset_ids = [$wordset_id];
    $words_by_id = [];

    foreach ($visible_categories as $category_row) {
        $category_id = is_array($category_row) ? (int) ($category_row['id'] ?? 0) : 0;
        if ($category_id <= 0) {
            continue;
        }
        $term = get_term($category_id, 'word-category');
        if (!($term instanceof WP_Term) || is_wp_error($term)) {
            continue;
        }

        $config = function_exists('ll_tools_get_category_quiz_config')
            ? ll_tools_get_category_quiz_config($term)
            : ['prompt_type' => 'audio', 'option_type' => 'image'];
        $merged_config = array_merge((array) $config, [
            'prompt_type' => 'audio',
            'option_type' => 'image',
        ]);

        $words = ll_get_words_by_category((string) $term->name, 'image', $wordset_ids, $merged_config);
        foreach ((array) $words as $word) {
            if (!is_array($word)) {
                continue;
            }

            $word_id = (int) ($word['id'] ?? 0);
            if ($word_id <= 0) {
                continue;
            }

            if (!isset($words_by_id[$word_id])) {
                $word['category_id'] = $category_id;
                $word['category_name'] = (string) $term->name;
                $word['category_ids'] = [$category_id];
                $word['category_names'] = [(string) $term->name];
                $words_by_id[$word_id] = $word;
                continue;
            }

            $existing_category_ids = isset($words_by_id[$word_id]['category_ids']) && is_array($words_by_id[$word_id]['category_ids'])
                ? $words_by_id[$word_id]['category_ids']
                : [];
            $existing_category_names = isset($words_by_id[$word_id]['category_names']) && is_array($words_by_id[$word_id]['category_names'])
                ? $words_by_id[$word_id]['category_names']
                : [];

            if (!in_array($category_id, $existing_category_ids, true)) {
                $existing_category_ids[] = $category_id;
            }
            if (!in_array((string) $term->name, $existing_category_names, true)) {
                $existing_category_names[] = (string) $term->name;
            }

            $words_by_id[$word_id]['category_ids'] = array_values(array_unique(array_filter(array_map('intval', $existing_category_ids), static function (int $id): bool {
                return $id > 0;
            })));
            $words_by_id[$word_id]['category_names'] = array_values(array_unique(array_filter(array_map('strval', $existing_category_names), static function (string $name): bool {
                return $name !== '';
            })));
        }
    }

    return [
        'categories' => $visible_categories,
        'category_ids' => $visible_category_ids,
        'words' => array_values($words_by_id),
    ];
}

function ll_tools_wordset_games_collect_visible_speaking_words(int $wordset_id, int $user_id = 0): array {
    $wordset_id = max(0, $wordset_id);
    $uid = (int) ($user_id ?: get_current_user_id());
    if ($wordset_id <= 0) {
        return [
            'categories' => [],
            'category_ids' => [],
            'words' => [],
        ];
    }

    $visible_categories = ll_tools_wordset_games_visible_categories($wordset_id, $uid);
    $visible_category_ids = array_values(array_filter(array_map(static function ($row): int {
        return is_array($row) ? (int) ($row['id'] ?? 0) : 0;
    }, $visible_categories), static function (int $id): bool {
        return $id > 0;
    }));
    if (empty($visible_categories) || empty($visible_category_ids)) {
        return [
            'categories' => [],
            'category_ids' => [],
            'words' => [],
        ];
    }

    $wordset_ids = [$wordset_id];
    $words_by_id = [];
    foreach ($visible_categories as $category_row) {
        $category_id = is_array($category_row) ? (int) ($category_row['id'] ?? 0) : 0;
        if ($category_id <= 0) {
            continue;
        }
        $term = get_term($category_id, 'word-category');
        if (!($term instanceof WP_Term) || is_wp_error($term)) {
            continue;
        }

        $config = function_exists('ll_tools_get_category_quiz_config')
            ? ll_tools_get_category_quiz_config($term)
            : ['prompt_type' => 'text_title', 'option_type' => 'text_title'];
        $merged_config = array_merge((array) $config, [
            'prompt_type' => 'text_title',
            'option_type' => 'text_title',
        ]);

        $words = ll_get_words_by_category((string) $term->name, 'text_title', $wordset_ids, $merged_config);
        foreach ((array) $words as $word) {
            if (!is_array($word)) {
                continue;
            }

            $word_id = (int) ($word['id'] ?? 0);
            if ($word_id <= 0) {
                continue;
            }

            if (!isset($words_by_id[$word_id])) {
                $word['category_id'] = $category_id;
                $word['category_name'] = (string) $term->name;
                $word['category_ids'] = [$category_id];
                $word['category_names'] = [(string) $term->name];
                $words_by_id[$word_id] = $word;
                continue;
            }

            $existing_category_ids = isset($words_by_id[$word_id]['category_ids']) && is_array($words_by_id[$word_id]['category_ids'])
                ? $words_by_id[$word_id]['category_ids']
                : [];
            $existing_category_names = isset($words_by_id[$word_id]['category_names']) && is_array($words_by_id[$word_id]['category_names'])
                ? $words_by_id[$word_id]['category_names']
                : [];

            if (!in_array($category_id, $existing_category_ids, true)) {
                $existing_category_ids[] = $category_id;
            }
            if (!in_array((string) $term->name, $existing_category_names, true)) {
                $existing_category_names[] = (string) $term->name;
            }

            $words_by_id[$word_id]['category_ids'] = array_values(array_unique(array_filter(array_map('intval', $existing_category_ids), static function (int $id): bool {
                return $id > 0;
            })));
            $words_by_id[$word_id]['category_names'] = array_values(array_unique(array_filter(array_map('strval', $existing_category_names), static function (string $name): bool {
                return $name !== '';
            })));
        }
    }

    return [
        'categories' => $visible_categories,
        'category_ids' => $visible_category_ids,
        'words' => array_values($words_by_id),
    ];
}

function ll_tools_wordset_games_lowest_frontier_category_ids(int $wordset_id, array $categories): array {
    $category_rows = array_values(array_filter($categories, static function ($row): bool {
        return is_array($row) && !empty($row['id']);
    }));
    if (empty($category_rows)) {
        return [];
    }

    $ordered_ids = array_values(array_filter(array_map(static function ($row): int {
        return (int) ($row['id'] ?? 0);
    }, $category_rows), static function (int $id): bool {
        return $id > 0;
    }));
    if (empty($ordered_ids)) {
        return [];
    }

    $ordering_mode = function_exists('ll_tools_wordset_get_category_ordering_mode')
        ? ll_tools_wordset_get_category_ordering_mode($wordset_id)
        : 'none';
    if ($ordering_mode === 'prerequisite') {
        $min_level = null;
        $frontier = [];
        foreach ($category_rows as $row) {
            $level = array_key_exists('logical_order_level', $row)
                ? max(0, (int) $row['logical_order_level'])
                : 0;
            if ($min_level === null || $level < $min_level) {
                $min_level = $level;
                $frontier = [(int) $row['id']];
                continue;
            }
            if ($level === $min_level) {
                $frontier[] = (int) $row['id'];
            }
        }
        $frontier = array_values(array_filter(array_map('intval', $frontier), static function (int $id): bool {
            return $id > 0;
        }));
        if (!empty($frontier)) {
            return $frontier;
        }
    }

    return [(int) $ordered_ids[0]];
}

function ll_tools_wordset_games_expand_pool_by_category_order(array $words, array $ordered_category_ids, int $minimum_word_count): array {
    $minimum_word_count = max(1, $minimum_word_count);
    $ordered_category_ids = array_values(array_filter(array_map('intval', $ordered_category_ids), static function (int $id): bool {
        return $id > 0;
    }));
    if (empty($words) || empty($ordered_category_ids)) {
        return [];
    }

    $words_by_category = [];
    foreach ($words as $word) {
        if (!is_array($word)) {
            continue;
        }

        $word_category_ids = isset($word['category_ids']) && is_array($word['category_ids'])
            ? array_values(array_filter(array_map('intval', $word['category_ids']), static function (int $id): bool {
                return $id > 0;
            }))
            : [];
        if (empty($word_category_ids) && !empty($word['category_id'])) {
            $word_category_ids = [(int) $word['category_id']];
        }

        foreach ($word_category_ids as $category_id) {
            if (!isset($words_by_category[$category_id])) {
                $words_by_category[$category_id] = [];
            }
            $words_by_category[$category_id][] = $word;
        }
    }

    $selected = [];
    $selected_lookup = [];
    foreach ($ordered_category_ids as $category_id) {
        $category_words = isset($words_by_category[$category_id]) && is_array($words_by_category[$category_id])
            ? $words_by_category[$category_id]
            : [];
        foreach ($category_words as $word) {
            $word_id = (int) ($word['id'] ?? 0);
            if ($word_id <= 0 || !empty($selected_lookup[$word_id])) {
                continue;
            }
            $selected[] = $word;
            $selected_lookup[$word_id] = true;
        }
        if (count($selected) >= $minimum_word_count) {
            break;
        }
    }

    return $selected;
}

function ll_tools_wordset_games_limit_launch_words(array $words, int $word_cap): array {
    $word_cap = max(1, $word_cap);
    $words = array_values(array_filter($words, 'is_array'));
    if (count($words) <= $word_cap) {
        return $words;
    }

    $groups = [];
    $group_order = [];
    foreach ($words as $word) {
        $category_id = 0;
        if (!empty($word['category_id'])) {
            $category_id = (int) $word['category_id'];
        } elseif (!empty($word['category_ids']) && is_array($word['category_ids'])) {
            $category_id = (int) reset($word['category_ids']);
        }

        $group_key = $category_id > 0 ? (string) $category_id : 'default';
        if (!isset($groups[$group_key])) {
            $groups[$group_key] = [];
            $group_order[] = $group_key;
        }
        $groups[$group_key][] = $word;
    }

    $selected = [];
    $selected_lookup = [];
    while (count($selected) < $word_cap) {
        $added_this_pass = false;
        foreach ($group_order as $group_key) {
            if (empty($groups[$group_key])) {
                continue;
            }

            $word = array_shift($groups[$group_key]);
            $word_id = (int) ($word['id'] ?? 0);
            if ($word_id > 0 && !empty($selected_lookup[$word_id])) {
                continue;
            }

            $selected[] = $word;
            if ($word_id > 0) {
                $selected_lookup[$word_id] = true;
            }
            $added_this_pass = true;

            if (count($selected) >= $word_cap) {
                break;
            }
        }

        if (!$added_this_pass) {
            break;
        }
    }

    if (count($selected) >= $word_cap) {
        return array_slice($selected, 0, $word_cap);
    }

    foreach ($words as $word) {
        $word_id = (int) ($word['id'] ?? 0);
        if ($word_id > 0 && !empty($selected_lookup[$word_id])) {
            continue;
        }

        $selected[] = $word;
        if ($word_id > 0) {
            $selected_lookup[$word_id] = true;
        }

        if (count($selected) >= $word_cap) {
            break;
        }
    }

    return array_slice($selected, 0, $word_cap);
}

function ll_tools_wordset_games_build_practice_source_pool(int $wordset_id, int $user_id = 0): array {
    $uid = (int) ($user_id ?: get_current_user_id());
    $collected = function_exists('ll_tools_wordset_games_collect_visible_speaking_words')
        ? ll_tools_wordset_games_collect_visible_speaking_words($wordset_id, $uid)
        : ll_tools_wordset_games_collect_visible_words($wordset_id, $uid);
    $categories = isset($collected['categories']) && is_array($collected['categories']) ? $collected['categories'] : [];
    $words = isset($collected['words']) && is_array($collected['words']) ? $collected['words'] : [];
    $minimum_word_count = ll_tools_wordset_games_min_word_count();
    $word_ids = array_values(array_filter(array_map(static function ($row): int {
        return is_array($row) ? (int) ($row['id'] ?? 0) : 0;
    }, $words), static function (int $id): bool {
        return $id > 0;
    }));
    $progress_rows = (!empty($word_ids) && function_exists('ll_tools_get_user_word_progress_rows'))
        ? ll_tools_get_user_word_progress_rows($uid, $word_ids)
        : [];

    $eligible_words = [];
    $studied_words = [];
    $mastered_words = [];
    $has_recorded_progress = false;
    foreach ($words as $word) {
        if (!is_array($word)) {
            continue;
        }

        $word_id = (int) ($word['id'] ?? 0);
        if ($word_id <= 0) {
            continue;
        }

        $progress = isset($progress_rows[$word_id]) && is_array($progress_rows[$word_id])
            ? $progress_rows[$word_id]
            : [];
        if (!empty($progress)) {
            $has_recorded_progress = true;
        }
        $status = 'new';
        if (function_exists('ll_tools_user_progress_word_status')) {
            $status = ll_tools_user_progress_word_status($progress);
        }

        if (!ll_tools_wordset_games_word_has_supported_image($word)) {
            continue;
        }
        $prompt_recording_types = ll_tools_wordset_games_get_word_prompt_recording_types($word);
        if (empty($prompt_recording_types)) {
            continue;
        }

        $word['game_prompt_recording_types'] = $prompt_recording_types;
        $word['progress_status'] = $status;
        $eligible_words[] = $word;

        if ($status === 'studied') {
            $studied_words[] = $word;
        } elseif ($status === 'mastered') {
            $mastered_words[] = $word;
        }
    }

    $pool = $studied_words;
    $pool_source = 'studied';
    if (count($pool) < $minimum_word_count && !empty($mastered_words)) {
        $pool = array_merge($studied_words, $mastered_words);
        $pool_source = 'studied_mastered';
    }

    if (count($pool) < $minimum_word_count && !$has_recorded_progress && !empty($eligible_words)) {
        $frontier_category_ids = ll_tools_wordset_games_lowest_frontier_category_ids($wordset_id, $categories);
        $ordered_category_ids = array_values(array_unique(array_merge(
            $frontier_category_ids,
            array_values(array_filter(array_map(static function ($row): int {
                return is_array($row) ? (int) ($row['id'] ?? 0) : 0;
            }, $categories), static function (int $id): bool {
                return $id > 0;
            }))
        )));
        $pool = ll_tools_wordset_games_expand_pool_by_category_order($eligible_words, $ordered_category_ids, $minimum_word_count);
        $pool_source = 'frontier_new';
    }

    if (!empty($pool) && function_exists('ll_tools_attach_user_practice_progress_to_words')) {
        $pool = ll_tools_attach_user_practice_progress_to_words($pool, $uid);
    }

    return [
        'minimum_word_count' => $minimum_word_count,
        'pool_source' => $pool_source,
        'category_ids' => isset($collected['category_ids']) && is_array($collected['category_ids']) ? $collected['category_ids'] : [],
        'words' => array_values($pool),
    ];
}

function ll_tools_wordset_games_collect_speaking_ipa_map(array $word_ids, string $recording_type = ''): array {
    $word_ids = array_values(array_filter(array_map('intval', $word_ids), static function (int $word_id): bool {
        return $word_id > 0;
    }));
    $target_type = ll_tools_normalize_practice_recording_type_slug($recording_type);
    if (empty($word_ids)) {
        return [];
    }

    $audio_posts = get_posts([
        'post_type' => 'word_audio',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'fields' => 'all',
        'orderby' => 'date',
        'order' => 'DESC',
        'post_parent__in' => $word_ids,
        'suppress_filters' => true,
        'no_found_rows' => true,
    ]);
    if (empty($audio_posts)) {
        return [];
    }

    $audio_ids = [];
    $audio_posts_by_word = [];
    foreach ($audio_posts as $audio_post) {
        if (!($audio_post instanceof WP_Post)) {
            continue;
        }
        $audio_ids[] = (int) $audio_post->ID;
        $parent_id = (int) $audio_post->post_parent;
        if ($parent_id <= 0) {
            continue;
        }
        if (!isset($audio_posts_by_word[$parent_id])) {
            $audio_posts_by_word[$parent_id] = [];
        }
        $audio_posts_by_word[$parent_id][] = $audio_post;
    }

    if (!empty($audio_ids)) {
        update_postmeta_cache($audio_ids);
        update_object_term_cache($audio_ids, 'word_audio');
    }

    $map = [];
    foreach ($audio_posts_by_word as $word_id => $word_audio_posts) {
        if ($target_type !== '') {
            $word_audio_posts = array_values(array_filter($word_audio_posts, static function ($audio_post) use ($target_type): bool {
                if (!($audio_post instanceof WP_Post)) {
                    return false;
                }

                $entry_type = ll_tools_wordset_games_get_audio_recording_type($audio_post);

                return $entry_type === $target_type;
            }));
        }
        if (empty($word_audio_posts)) {
            continue;
        }

        $preferred_speaker = function_exists('ll_tools_get_preferred_speaker_from_audio_posts')
            ? ll_tools_get_preferred_speaker_from_audio_posts($word_audio_posts)
            : 0;
        $prioritized_audio = function_exists('ll_get_prioritized_audio')
            ? ll_get_prioritized_audio($word_audio_posts, $preferred_speaker)
            : null;

        $candidate_audio_posts = [];
        if ($prioritized_audio instanceof WP_Post) {
            $candidate_audio_posts[] = $prioritized_audio;
        }
        foreach ($word_audio_posts as $audio_post) {
            if (!($audio_post instanceof WP_Post)) {
                continue;
            }
            if ($prioritized_audio instanceof WP_Post && (int) $audio_post->ID === (int) $prioritized_audio->ID) {
                continue;
            }
            $candidate_audio_posts[] = $audio_post;
        }

        foreach ($candidate_audio_posts as $audio_post) {
            $ipa = trim((string) get_post_meta($audio_post->ID, 'recording_ipa', true));
            if ($ipa !== '') {
                $map[(int) $word_id] = $ipa;
                break;
            }
        }
    }

    return $map;
}

function ll_tools_wordset_games_get_speaking_prompt_text(array $word): string {
    if (empty($word['image'])) {
        $translation = trim((string) ($word['translation'] ?? ''));
        if ($translation !== '') {
            return $translation;
        }
    }

    $prompt = trim((string) ($word['prompt_label'] ?? ''));
    if ($prompt !== '') {
        return $prompt;
    }

    $label = trim((string) ($word['label'] ?? ''));
    if ($label !== '') {
        return $label;
    }

    return trim((string) ($word['title'] ?? ''));
}

function ll_tools_wordset_games_build_speaking_practice_pool(int $wordset_id, int $user_id = 0): array {
    $wordset_id = max(0, $wordset_id);
    $uid = (int) ($user_id ?: get_current_user_id());
    if ($wordset_id <= 0) {
        return [
            'minimum_word_count' => ll_tools_wordset_games_min_word_count(),
            'pool_source' => 'disabled',
            'category_ids' => [],
            'words' => [],
            'target_field' => '',
            'target_label' => '',
            'enabled' => false,
            'reason_code' => 'invalid_wordset',
        ];
    }

    $config = function_exists('ll_tools_get_wordset_speaking_game_config')
        ? ll_tools_get_wordset_speaking_game_config([$wordset_id], true)
        : [
            'enabled' => false,
            'provider' => '',
            'provider_label' => '',
            'uses_local_browser' => false,
            'local_endpoint' => '',
            'service_enabled' => false,
            'target' => 'word_title',
            'target_label' => __('Word title', 'll-tools-text-domain'),
        ];
    $target_field = sanitize_key((string) ($config['target'] ?? 'word_title'));
    $target_label = (string) ($config['target_label'] ?? __('Word title', 'll-tools-text-domain'));
    $minimum_word_count = ll_tools_wordset_games_min_word_count();
    if (empty($config['enabled'])) {
        return [
            'minimum_word_count' => $minimum_word_count,
            'pool_source' => 'stt_disabled',
            'category_ids' => [],
            'words' => [],
            'target_field' => $target_field,
            'target_label' => $target_label,
            'enabled' => false,
            'reason_code' => 'stt_not_enabled',
            'provider' => (string) ($config['provider'] ?? ''),
            'provider_label' => (string) ($config['provider_label'] ?? ''),
            'service_enabled' => !empty($config['service_enabled']),
        ];
    }

    $collected = function_exists('ll_tools_wordset_games_collect_visible_speaking_words')
        ? ll_tools_wordset_games_collect_visible_speaking_words($wordset_id, $uid)
        : ll_tools_wordset_games_collect_visible_words($wordset_id, $uid);
    $categories = isset($collected['categories']) && is_array($collected['categories']) ? $collected['categories'] : [];
    $words = isset($collected['words']) && is_array($collected['words']) ? $collected['words'] : [];
    $word_ids = array_values(array_filter(array_map(static function ($row): int {
        return is_array($row) ? (int) ($row['id'] ?? 0) : 0;
    }, $words), static function (int $id): bool {
        return $id > 0;
    }));

    $ipa_map = ($target_field === 'recording_ipa')
        ? ll_tools_wordset_games_collect_speaking_ipa_map($word_ids, 'isolation')
        : [];

    $eligible_words = [];
    foreach ($words as $word) {
        if (!is_array($word)) {
            continue;
        }

        $word_id = (int) ($word['id'] ?? 0);
        if ($word_id <= 0) {
            continue;
        }

        $prompt_text = ll_tools_wordset_games_get_speaking_prompt_text($word);
        if ($prompt_text === '') {
            $prompt_text = trim((string) ($word['title'] ?? ''));
        }
        $isolation_audio = ll_tools_wordset_games_get_audio_details($word_id, 'isolation');
        $best_correct_audio_url = trim((string) ($isolation_audio['url'] ?? ''));
        if ($best_correct_audio_url === '') {
            continue;
        }

        if ($target_field === 'recording_ipa') {
            $word['recording_ipa'] = trim((string) ($ipa_map[$word_id] ?? ($isolation_audio['recording_ipa'] ?? '')));
        }

        $target_text = ($target_field === 'recording_ipa')
            ? trim((string) ($word['recording_ipa'] ?? ''))
            : ll_tools_get_wordset_speaking_game_target_value($word_id, $target_field, $word);
        if ($target_text === '') {
            continue;
        }

        $display_texts = ll_tools_wordset_games_get_speaking_display_texts($word_id, $target_field, $word);

        $word['speaking_target_field'] = $target_field;
        $word['speaking_target_label'] = $target_label;
        $word['speaking_target_text'] = $target_text;
        $word['speaking_prompt_text'] = $prompt_text;
        $word['speaking_prompt_type'] = !empty($word['image']) ? 'image' : 'text';
        $word['speaking_display_texts'] = $display_texts;
        $word['speaking_best_correct_audio_url'] = $best_correct_audio_url;
        $eligible_words[] = $word;
    }

    if (!empty($eligible_words) && function_exists('ll_tools_attach_user_practice_progress_to_words')) {
        $eligible_words = ll_tools_attach_user_practice_progress_to_words($eligible_words, $uid);
    }

    return [
        'minimum_word_count' => $minimum_word_count,
        'pool_source' => 'speaking_practice',
        'category_ids' => isset($collected['category_ids']) && is_array($collected['category_ids']) ? $collected['category_ids'] : [],
        'words' => array_values($eligible_words),
        'target_field' => $target_field,
        'target_label' => $target_label,
        'enabled' => true,
        'reason_code' => '',
        'provider' => (string) ($config['provider'] ?? ''),
        'provider_label' => (string) ($config['provider_label'] ?? ''),
        'service_enabled' => !empty($config['service_enabled']),
        'local_endpoint' => (string) ($config['local_endpoint'] ?? ''),
    ];
}

function ll_tools_wordset_games_build_speaking_stack_pool(int $wordset_id, int $user_id = 0): array {
    $speaking_pool = ll_tools_wordset_games_build_speaking_practice_pool($wordset_id, $user_id);
    $eligible_words = isset($speaking_pool['words']) && is_array($speaking_pool['words'])
        ? array_values(array_filter($speaking_pool['words'], static function ($word): bool {
            return is_array($word) && trim((string) ($word['image'] ?? '')) !== '';
        }))
        : [];

    return [
        'minimum_word_count' => (int) ($speaking_pool['minimum_word_count'] ?? ll_tools_wordset_games_min_word_count()),
        'pool_source' => 'speaking_stack',
        'category_ids' => isset($speaking_pool['category_ids']) && is_array($speaking_pool['category_ids']) ? $speaking_pool['category_ids'] : [],
        'words' => $eligible_words,
        'target_field' => (string) ($speaking_pool['target_field'] ?? ''),
        'target_label' => (string) ($speaking_pool['target_label'] ?? ''),
        'enabled' => !empty($speaking_pool['enabled']),
        'reason_code' => !empty($speaking_pool['reason_code']) ? (string) $speaking_pool['reason_code'] : '',
        'provider' => (string) ($speaking_pool['provider'] ?? ''),
        'provider_label' => (string) ($speaking_pool['provider_label'] ?? ''),
        'service_enabled' => !empty($speaking_pool['service_enabled']),
        'local_endpoint' => (string) ($speaking_pool['local_endpoint'] ?? ''),
    ];
}

function ll_tools_wordset_games_get_audio_recording_type($audio_post): string {
    $audio_post_id = $audio_post instanceof WP_Post
        ? (int) $audio_post->ID
        : (int) $audio_post;
    if ($audio_post_id <= 0) {
        return '';
    }

    $meta_type = ll_tools_normalize_practice_recording_type_slug((string) get_post_meta($audio_post_id, 'recording_type', true));
    if ($meta_type !== '') {
        return $meta_type;
    }

    $terms = get_the_terms($audio_post_id, 'recording_type');
    if (!is_array($terms)) {
        return '';
    }

    foreach ($terms as $term) {
        if (!($term instanceof WP_Term)) {
            continue;
        }

        $slug = ll_tools_normalize_practice_recording_type_slug((string) $term->slug);
        if ($slug !== '') {
            return $slug;
        }
    }

    return '';
}

function ll_tools_wordset_games_finalize_pool(array $source_pool, string $slug, int $launch_word_cap): array {
    $minimum_word_count = max(1, (int) ($source_pool['minimum_word_count'] ?? ll_tools_wordset_games_min_word_count()));
    $available_words = isset($source_pool['words']) && is_array($source_pool['words'])
        ? array_values(array_filter($source_pool['words'], 'is_array'))
        : [];
    $available_word_count = count($available_words);
    $resolved_launch_word_cap = max($minimum_word_count, $launch_word_cap);
    $launch_words = ll_tools_wordset_games_limit_launch_words($available_words, $resolved_launch_word_cap);

    return [
        'slug' => sanitize_key($slug),
        'minimum_word_count' => $minimum_word_count,
        'available_word_count' => $available_word_count,
        'launch_word_cap' => $resolved_launch_word_cap,
        'launch_word_count' => count($launch_words),
        'launchable' => $available_word_count >= $minimum_word_count,
        'pool_source' => isset($source_pool['pool_source']) ? (string) $source_pool['pool_source'] : '',
        'category_ids' => isset($source_pool['category_ids']) && is_array($source_pool['category_ids']) ? $source_pool['category_ids'] : [],
        'words' => array_values($launch_words),
    ];
}

function ll_tools_wordset_games_build_space_shooter_pool(int $wordset_id, int $user_id = 0): array {
    $source_pool = ll_tools_wordset_games_build_practice_source_pool($wordset_id, $user_id);

    return ll_tools_wordset_games_finalize_pool(
        $source_pool,
        'space-shooter',
        ll_tools_wordset_games_space_shooter_launch_word_cap()
    );
}

function ll_tools_wordset_games_build_bubble_pop_pool(int $wordset_id, int $user_id = 0): array {
    $source_pool = ll_tools_wordset_games_build_practice_source_pool($wordset_id, $user_id);

    return ll_tools_wordset_games_finalize_pool(
        $source_pool,
        'bubble-pop',
        ll_tools_wordset_games_bubble_pop_launch_word_cap()
    );
}

function ll_tools_wordset_games_build_catalog(int $wordset_id, int $user_id = 0): array {
    $catalog = ll_tools_wordset_games_base_catalog();
    $source_pool = ll_tools_wordset_games_build_practice_source_pool($wordset_id, $user_id);
    $space_shooter = ll_tools_wordset_games_finalize_pool(
        $source_pool,
        'space-shooter',
        ll_tools_wordset_games_space_shooter_launch_word_cap()
    );
    $bubble_pop = ll_tools_wordset_games_finalize_pool(
        $source_pool,
        'bubble-pop',
        ll_tools_wordset_games_bubble_pop_launch_word_cap()
    );
    $minimum = (int) ($space_shooter['minimum_word_count'] ?? ll_tools_wordset_games_min_word_count());
    $available = (int) ($space_shooter['available_word_count'] ?? 0);

    $catalog['space-shooter'] = array_merge($catalog['space-shooter'], [
        'minimum_word_count' => $minimum,
        'available_word_count' => $available,
        'launch_word_cap' => (int) ($space_shooter['launch_word_cap'] ?? ll_tools_wordset_games_space_shooter_launch_word_cap()),
        'launch_word_count' => (int) ($space_shooter['launch_word_count'] ?? 0),
        'launchable' => !empty($space_shooter['launchable']),
        'reason_code' => $available >= $minimum ? '' : 'not_enough_words',
        'category_ids' => isset($space_shooter['category_ids']) && is_array($space_shooter['category_ids']) ? $space_shooter['category_ids'] : [],
        'words' => isset($space_shooter['words']) && is_array($space_shooter['words']) ? $space_shooter['words'] : [],
    ]);

    $bubble_minimum = (int) ($bubble_pop['minimum_word_count'] ?? ll_tools_wordset_games_min_word_count());
    $bubble_available = (int) ($bubble_pop['available_word_count'] ?? 0);
    $catalog['bubble-pop'] = array_merge($catalog['bubble-pop'], [
        'minimum_word_count' => $bubble_minimum,
        'available_word_count' => $bubble_available,
        'launch_word_cap' => (int) ($bubble_pop['launch_word_cap'] ?? ll_tools_wordset_games_bubble_pop_launch_word_cap()),
        'launch_word_count' => (int) ($bubble_pop['launch_word_count'] ?? 0),
        'launchable' => !empty($bubble_pop['launchable']),
        'reason_code' => $bubble_available >= $bubble_minimum ? '' : 'not_enough_words',
        'category_ids' => isset($bubble_pop['category_ids']) && is_array($bubble_pop['category_ids']) ? $bubble_pop['category_ids'] : [],
        'words' => isset($bubble_pop['words']) && is_array($bubble_pop['words']) ? $bubble_pop['words'] : [],
    ]);

    $speaking_pool = ll_tools_wordset_games_build_speaking_practice_pool($wordset_id, $user_id);
    $speaking_minimum = (int) ($speaking_pool['minimum_word_count'] ?? ll_tools_wordset_games_min_word_count());
    $speaking_available = count((array) ($speaking_pool['words'] ?? []));
    $speaking_launch_words = ll_tools_wordset_games_limit_launch_words(
        isset($speaking_pool['words']) && is_array($speaking_pool['words']) ? $speaking_pool['words'] : [],
        ll_tools_wordset_games_speaking_practice_launch_word_cap()
    );
    $speaking_enabled = !empty($speaking_pool['enabled']) && !empty($speaking_pool['service_enabled']);
    if ($speaking_enabled && $speaking_available >= $speaking_minimum) {
        $catalog['speaking-practice'] = [
            'slug' => 'speaking-practice',
            'title' => __('Speaking Practice', 'll-tools-text-domain'),
            'description' => __('Say the word aloud. Compare what you said to the target text.', 'll-tools-text-domain'),
            'minimum_word_count' => $speaking_minimum,
            'available_word_count' => $speaking_available,
            'launch_word_cap' => ll_tools_wordset_games_speaking_practice_launch_word_cap(),
            'launch_word_count' => count($speaking_launch_words),
            'launchable' => true,
            'reason_code' => '',
            'category_ids' => isset($speaking_pool['category_ids']) && is_array($speaking_pool['category_ids']) ? $speaking_pool['category_ids'] : [],
            'words' => $speaking_launch_words,
            'target_field' => (string) ($speaking_pool['target_field'] ?? ''),
            'target_label' => (string) ($speaking_pool['target_label'] ?? ''),
            'provider' => (string) ($speaking_pool['provider'] ?? ''),
            'provider_label' => (string) ($speaking_pool['provider_label'] ?? ''),
            'local_endpoint' => (string) ($speaking_pool['local_endpoint'] ?? ''),
        ];
    }

    $speaking_stack_pool = ll_tools_wordset_games_build_speaking_stack_pool($wordset_id, $user_id);
    $speaking_stack_minimum = (int) ($speaking_stack_pool['minimum_word_count'] ?? ll_tools_wordset_games_min_word_count());
    $speaking_stack_available = count((array) ($speaking_stack_pool['words'] ?? []));
    $speaking_stack_launch_words = ll_tools_wordset_games_limit_launch_words(
        isset($speaking_stack_pool['words']) && is_array($speaking_stack_pool['words']) ? $speaking_stack_pool['words'] : [],
        ll_tools_wordset_games_speaking_stack_launch_word_cap()
    );
    $speaking_stack_enabled = !empty($speaking_stack_pool['enabled']) && !empty($speaking_stack_pool['service_enabled']);
    if ($speaking_stack_enabled && $speaking_stack_available >= $speaking_stack_minimum) {
        $catalog['speaking-stack'] = [
            'slug' => 'speaking-stack',
            'title' => __('Word Stack', 'll-tools-text-domain'),
            'description' => __('Say the picture before the stack reaches the top.', 'll-tools-text-domain'),
            'minimum_word_count' => $speaking_stack_minimum,
            'available_word_count' => $speaking_stack_available,
            'launch_word_cap' => ll_tools_wordset_games_speaking_stack_launch_word_cap(),
            'launch_word_count' => count($speaking_stack_launch_words),
            'launchable' => true,
            'reason_code' => '',
            'category_ids' => isset($speaking_stack_pool['category_ids']) && is_array($speaking_stack_pool['category_ids']) ? $speaking_stack_pool['category_ids'] : [],
            'words' => $speaking_stack_launch_words,
            'target_field' => (string) ($speaking_stack_pool['target_field'] ?? ''),
            'target_label' => (string) ($speaking_stack_pool['target_label'] ?? ''),
            'provider' => (string) ($speaking_stack_pool['provider'] ?? ''),
            'provider_label' => (string) ($speaking_stack_pool['provider_label'] ?? ''),
            'local_endpoint' => (string) ($speaking_stack_pool['local_endpoint'] ?? ''),
        ];
    }

    return $catalog;
}

function ll_tools_wordset_games_strip_speaking_stress_marks(string $text): string {
    $text = trim($text);
    if ($text === '') {
        return '';
    }

    $text = str_replace(["\u{02C8}", "\u{02CC}", "'", '’'], '', $text);
    $text = preg_replace('/\s+/u', ' ', $text);

    return trim((string) $text);
}

function ll_tools_wordset_games_normalize_speaking_text(string $text, string $target_field): string {
    $target_field = sanitize_key($target_field);
    $text = trim($text);
    if ($text === '') {
        return '';
    }

    if ($target_field === 'recording_ipa') {
        if (function_exists('ll_tools_word_grid_normalize_ipa_output')) {
            $text = ll_tools_word_grid_normalize_ipa_output($text, 'ipa');
        } else {
            $text = trim($text);
        }
        $text = ll_tools_wordset_games_strip_speaking_stress_marks($text);
        return trim($text);
    }

    if (function_exists('ll_tools_word_grid_normalize_non_ipa_text')) {
        $text = ll_tools_word_grid_normalize_non_ipa_text($text);
    } else {
        $text = wp_strip_all_tags($text);
        $text = str_replace(["\r", "\n", "\t", "\u{00A0}"], ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text);
    }

    $text = trim((string) $text);
    if ($text === '') {
        return '';
    }

    if (function_exists('mb_strtolower')) {
        $text = mb_strtolower($text, 'UTF-8');
    } else {
        $text = strtolower($text);
    }

    return trim($text);
}

function ll_tools_wordset_games_tokenize_speaking_text(string $text, string $target_field): array {
    $target_field = sanitize_key($target_field);
    $normalized = ll_tools_wordset_games_normalize_speaking_text($text, $target_field);
    if ($normalized === '') {
        return [];
    }

    if ($target_field === 'recording_ipa' && function_exists('ll_tools_word_grid_tokenize_ipa')) {
        $tokens = ll_tools_word_grid_tokenize_ipa($normalized, 'ipa');
        return array_values(array_filter(array_map('trim', (array) $tokens), static function (string $token): bool {
            return $token !== '';
        }));
    }

    $parts = preg_split('/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY);
    if (is_array($parts) && count($parts) > 1) {
        return array_values(array_filter(array_map('trim', $parts), static function (string $token): bool {
            return $token !== '';
        }));
    }

    $chars = preg_split('//u', $normalized, -1, PREG_SPLIT_NO_EMPTY);
    return is_array($chars) ? array_values(array_filter(array_map('trim', $chars), static function (string $token): bool {
        return $token !== '';
    })) : [];
}

function ll_tools_wordset_games_array_weighted_levenshtein(array $left, array $right, callable $substitution_cost, float $insert_delete_cost = 1.0): float {
    $left = array_values($left);
    $right = array_values($right);
    $left_count = count($left);
    $right_count = count($right);
    $insert_delete_cost = max(0.0, $insert_delete_cost);

    if ($left_count === 0) {
        return (float) $right_count * $insert_delete_cost;
    }
    if ($right_count === 0) {
        return (float) $left_count * $insert_delete_cost;
    }

    $previous = [];
    for ($index = 0; $index <= $right_count; $index++) {
        $previous[$index] = $index * $insert_delete_cost;
    }

    for ($i = 1; $i <= $left_count; $i++) {
        $current = [$i * $insert_delete_cost];
        $left_token = $left[$i - 1] ?? '';
        for ($j = 1; $j <= $right_count; $j++) {
            $right_token = $right[$j - 1] ?? '';
            $substitution = max(0.0, min(1.0, (float) $substitution_cost($left_token, $right_token)));
            $current[] = min(
                $previous[$j] + $insert_delete_cost,
                $current[$j - 1] + $insert_delete_cost,
                $previous[$j - 1] + $substitution
            );
        }
        $previous = $current;
    }

    return (float) ($previous[$right_count] ?? 0.0);
}

function ll_tools_wordset_games_array_levenshtein(array $left, array $right): int {
    $left = array_values($left);
    $right = array_values($right);
    $left_count = count($left);
    $right_count = count($right);

    if ($left_count === 0) {
        return $right_count;
    }
    if ($right_count === 0) {
        return $left_count;
    }

    $previous = range(0, $right_count);
    for ($i = 1; $i <= $left_count; $i++) {
        $current = [$i];
        $left_token = (string) ($left[$i - 1] ?? '');
        for ($j = 1; $j <= $right_count; $j++) {
            $right_token = (string) ($right[$j - 1] ?? '');
            $cost = ($left_token === $right_token) ? 0 : 1;
            $current[] = min(
                $previous[$j] + 1,
                $current[$j - 1] + 1,
                $previous[$j - 1] + $cost
            );
        }
        $previous = $current;
    }

    return (int) $previous[$right_count];
}

function ll_tools_wordset_games_ipa_similarity_feature_map(): array {
    static $map = null;
    if (is_array($map)) {
        return $map;
    }

    $map = [
        'i' => ['type' => 'vowel', 'height' => 0.0, 'back' => 0.0, 'round' => 0.0],
        'y' => ['type' => 'vowel', 'height' => 0.0, 'back' => 0.0, 'round' => 1.0],
        'ɨ' => ['type' => 'vowel', 'height' => 0.0, 'back' => 2.0, 'round' => 0.0],
        'ʉ' => ['type' => 'vowel', 'height' => 0.0, 'back' => 2.0, 'round' => 1.0],
        'ɯ' => ['type' => 'vowel', 'height' => 0.0, 'back' => 4.0, 'round' => 0.0],
        'u' => ['type' => 'vowel', 'height' => 0.0, 'back' => 4.0, 'round' => 1.0],
        'ɪ' => ['type' => 'vowel', 'height' => 1.0, 'back' => 0.5, 'round' => 0.0],
        'ʏ' => ['type' => 'vowel', 'height' => 1.0, 'back' => 0.5, 'round' => 1.0],
        'ʊ' => ['type' => 'vowel', 'height' => 1.0, 'back' => 3.5, 'round' => 1.0],
        'e' => ['type' => 'vowel', 'height' => 2.0, 'back' => 0.0, 'round' => 0.0],
        'ø' => ['type' => 'vowel', 'height' => 2.0, 'back' => 0.0, 'round' => 1.0],
        'ɘ' => ['type' => 'vowel', 'height' => 2.0, 'back' => 2.0, 'round' => 0.0],
        'ɵ' => ['type' => 'vowel', 'height' => 2.0, 'back' => 2.0, 'round' => 1.0],
        'ɤ' => ['type' => 'vowel', 'height' => 2.0, 'back' => 4.0, 'round' => 0.0],
        'o' => ['type' => 'vowel', 'height' => 2.0, 'back' => 4.0, 'round' => 1.0],
        'ə' => ['type' => 'vowel', 'height' => 3.0, 'back' => 2.0, 'round' => 0.0],
        'ɛ' => ['type' => 'vowel', 'height' => 4.0, 'back' => 0.0, 'round' => 0.0],
        'œ' => ['type' => 'vowel', 'height' => 4.0, 'back' => 0.0, 'round' => 1.0],
        'ɜ' => ['type' => 'vowel', 'height' => 4.0, 'back' => 2.0, 'round' => 0.0],
        'ɞ' => ['type' => 'vowel', 'height' => 4.0, 'back' => 2.0, 'round' => 1.0],
        'ʌ' => ['type' => 'vowel', 'height' => 4.0, 'back' => 3.5, 'round' => 0.0],
        'ɔ' => ['type' => 'vowel', 'height' => 4.0, 'back' => 4.0, 'round' => 1.0],
        'æ' => ['type' => 'vowel', 'height' => 5.0, 'back' => 0.0, 'round' => 0.0],
        'ɐ' => ['type' => 'vowel', 'height' => 5.0, 'back' => 2.0, 'round' => 0.0],
        'a' => ['type' => 'vowel', 'height' => 6.0, 'back' => 1.5, 'round' => 0.0],
        'ɶ' => ['type' => 'vowel', 'height' => 6.0, 'back' => 0.0, 'round' => 1.0],
        'ɑ' => ['type' => 'vowel', 'height' => 6.0, 'back' => 4.0, 'round' => 0.0],
        'ɒ' => ['type' => 'vowel', 'height' => 6.0, 'back' => 4.0, 'round' => 1.0],
        'j' => ['type' => 'glide', 'height' => 0.0, 'back' => 0.0, 'round' => 0.0],
        'ɥ' => ['type' => 'glide', 'height' => 0.0, 'back' => 0.0, 'round' => 1.0],
        'ɰ' => ['type' => 'glide', 'height' => 0.0, 'back' => 4.0, 'round' => 0.0],
        'w' => ['type' => 'glide', 'height' => 0.0, 'back' => 4.0, 'round' => 1.0],
        'p' => ['type' => 'consonant', 'place' => 0.0, 'manner' => 'stop', 'voice' => 0.0, 'lateral' => 0.0, 'rhotic' => 0.0],
        'b' => ['type' => 'consonant', 'place' => 0.0, 'manner' => 'stop', 'voice' => 1.0, 'lateral' => 0.0, 'rhotic' => 0.0],
        'm' => ['type' => 'consonant', 'place' => 0.0, 'manner' => 'nasal', 'voice' => 1.0, 'lateral' => 0.0, 'rhotic' => 0.0],
        'ɸ' => ['type' => 'consonant', 'place' => 0.0, 'manner' => 'fricative', 'voice' => 0.0, 'lateral' => 0.0, 'rhotic' => 0.0],
        'β' => ['type' => 'consonant', 'place' => 0.0, 'manner' => 'fricative', 'voice' => 1.0, 'lateral' => 0.0, 'rhotic' => 0.0],
        'f' => ['type' => 'consonant', 'place' => 1.0, 'manner' => 'fricative', 'voice' => 0.0, 'lateral' => 0.0, 'rhotic' => 0.0],
        'v' => ['type' => 'consonant', 'place' => 1.0, 'manner' => 'fricative', 'voice' => 1.0, 'lateral' => 0.0, 'rhotic' => 0.0],
        'ɱ' => ['type' => 'consonant', 'place' => 1.0, 'manner' => 'nasal', 'voice' => 1.0, 'lateral' => 0.0, 'rhotic' => 0.0],
        'θ' => ['type' => 'consonant', 'place' => 2.0, 'manner' => 'fricative', 'voice' => 0.0, 'lateral' => 0.0, 'rhotic' => 0.0],
        'ð' => ['type' => 'consonant', 'place' => 2.0, 'manner' => 'fricative', 'voice' => 1.0, 'lateral' => 0.0, 'rhotic' => 0.0],
        't' => ['type' => 'consonant', 'place' => 3.0, 'manner' => 'stop', 'voice' => 0.0, 'lateral' => 0.0, 'rhotic' => 0.0],
        'd' => ['type' => 'consonant', 'place' => 3.0, 'manner' => 'stop', 'voice' => 1.0, 'lateral' => 0.0, 'rhotic' => 0.0],
        'n' => ['type' => 'consonant', 'place' => 3.0, 'manner' => 'nasal', 'voice' => 1.0, 'lateral' => 0.0, 'rhotic' => 0.0],
        's' => ['type' => 'consonant', 'place' => 3.0, 'manner' => 'fricative', 'voice' => 0.0, 'lateral' => 0.0, 'rhotic' => 0.0],
        'z' => ['type' => 'consonant', 'place' => 3.0, 'manner' => 'fricative', 'voice' => 1.0, 'lateral' => 0.0, 'rhotic' => 0.0],
        'ɾ' => ['type' => 'consonant', 'place' => 3.0, 'manner' => 'tap', 'voice' => 1.0, 'lateral' => 0.0, 'rhotic' => 1.0],
        'r' => ['type' => 'consonant', 'place' => 3.0, 'manner' => 'trill', 'voice' => 1.0, 'lateral' => 0.0, 'rhotic' => 1.0],
        'ɹ' => ['type' => 'consonant', 'place' => 3.0, 'manner' => 'approximant', 'voice' => 1.0, 'lateral' => 0.0, 'rhotic' => 1.0],
        'l' => ['type' => 'consonant', 'place' => 3.0, 'manner' => 'approximant', 'voice' => 1.0, 'lateral' => 1.0, 'rhotic' => 0.0],
        'ɫ' => ['type' => 'consonant', 'place' => 3.0, 'manner' => 'approximant', 'voice' => 1.0, 'lateral' => 1.0, 'rhotic' => 0.0],
        'ɬ' => ['type' => 'consonant', 'place' => 3.0, 'manner' => 'fricative', 'voice' => 0.0, 'lateral' => 1.0, 'rhotic' => 0.0],
        'ɮ' => ['type' => 'consonant', 'place' => 3.0, 'manner' => 'fricative', 'voice' => 1.0, 'lateral' => 1.0, 'rhotic' => 0.0],
        'ʃ' => ['type' => 'consonant', 'place' => 4.0, 'manner' => 'fricative', 'voice' => 0.0, 'lateral' => 0.0, 'rhotic' => 0.0],
        'ʒ' => ['type' => 'consonant', 'place' => 4.0, 'manner' => 'fricative', 'voice' => 1.0, 'lateral' => 0.0, 'rhotic' => 0.0],
        'ʈ' => ['type' => 'consonant', 'place' => 5.0, 'manner' => 'stop', 'voice' => 0.0, 'lateral' => 0.0, 'rhotic' => 0.0],
        'ɖ' => ['type' => 'consonant', 'place' => 5.0, 'manner' => 'stop', 'voice' => 1.0, 'lateral' => 0.0, 'rhotic' => 0.0],
        'ɳ' => ['type' => 'consonant', 'place' => 5.0, 'manner' => 'nasal', 'voice' => 1.0, 'lateral' => 0.0, 'rhotic' => 0.0],
        'ʂ' => ['type' => 'consonant', 'place' => 5.0, 'manner' => 'fricative', 'voice' => 0.0, 'lateral' => 0.0, 'rhotic' => 0.0],
        'ʐ' => ['type' => 'consonant', 'place' => 5.0, 'manner' => 'fricative', 'voice' => 1.0, 'lateral' => 0.0, 'rhotic' => 0.0],
        'ɻ' => ['type' => 'consonant', 'place' => 5.0, 'manner' => 'approximant', 'voice' => 1.0, 'lateral' => 0.0, 'rhotic' => 1.0],
        'c' => ['type' => 'consonant', 'place' => 6.0, 'manner' => 'stop', 'voice' => 0.0, 'lateral' => 0.0, 'rhotic' => 0.0],
        'ɟ' => ['type' => 'consonant', 'place' => 6.0, 'manner' => 'stop', 'voice' => 1.0, 'lateral' => 0.0, 'rhotic' => 0.0],
        'ɲ' => ['type' => 'consonant', 'place' => 6.0, 'manner' => 'nasal', 'voice' => 1.0, 'lateral' => 0.0, 'rhotic' => 0.0],
        'ç' => ['type' => 'consonant', 'place' => 6.0, 'manner' => 'fricative', 'voice' => 0.0, 'lateral' => 0.0, 'rhotic' => 0.0],
        'ʝ' => ['type' => 'consonant', 'place' => 6.0, 'manner' => 'fricative', 'voice' => 1.0, 'lateral' => 0.0, 'rhotic' => 0.0],
        'ʎ' => ['type' => 'consonant', 'place' => 6.0, 'manner' => 'approximant', 'voice' => 1.0, 'lateral' => 1.0, 'rhotic' => 0.0],
        'k' => ['type' => 'consonant', 'place' => 7.0, 'manner' => 'stop', 'voice' => 0.0, 'lateral' => 0.0, 'rhotic' => 0.0],
        'g' => ['type' => 'consonant', 'place' => 7.0, 'manner' => 'stop', 'voice' => 1.0, 'lateral' => 0.0, 'rhotic' => 0.0],
        'ŋ' => ['type' => 'consonant', 'place' => 7.0, 'manner' => 'nasal', 'voice' => 1.0, 'lateral' => 0.0, 'rhotic' => 0.0],
        'x' => ['type' => 'consonant', 'place' => 7.0, 'manner' => 'fricative', 'voice' => 0.0, 'lateral' => 0.0, 'rhotic' => 0.0],
        'ɣ' => ['type' => 'consonant', 'place' => 7.0, 'manner' => 'fricative', 'voice' => 1.0, 'lateral' => 0.0, 'rhotic' => 0.0],
        'q' => ['type' => 'consonant', 'place' => 8.0, 'manner' => 'stop', 'voice' => 0.0, 'lateral' => 0.0, 'rhotic' => 0.0],
        'ɢ' => ['type' => 'consonant', 'place' => 8.0, 'manner' => 'stop', 'voice' => 1.0, 'lateral' => 0.0, 'rhotic' => 0.0],
        'ɴ' => ['type' => 'consonant', 'place' => 8.0, 'manner' => 'nasal', 'voice' => 1.0, 'lateral' => 0.0, 'rhotic' => 0.0],
        'χ' => ['type' => 'consonant', 'place' => 8.0, 'manner' => 'fricative', 'voice' => 0.0, 'lateral' => 0.0, 'rhotic' => 0.0],
        'ʁ' => ['type' => 'consonant', 'place' => 8.0, 'manner' => 'fricative', 'voice' => 1.0, 'lateral' => 0.0, 'rhotic' => 1.0],
        'ʀ' => ['type' => 'consonant', 'place' => 8.0, 'manner' => 'trill', 'voice' => 1.0, 'lateral' => 0.0, 'rhotic' => 1.0],
        'ħ' => ['type' => 'consonant', 'place' => 9.0, 'manner' => 'fricative', 'voice' => 0.0, 'lateral' => 0.0, 'rhotic' => 0.0],
        'ʕ' => ['type' => 'consonant', 'place' => 9.0, 'manner' => 'approximant', 'voice' => 1.0, 'lateral' => 0.0, 'rhotic' => 0.0],
        'h' => ['type' => 'consonant', 'place' => 10.0, 'manner' => 'fricative', 'voice' => 0.0, 'lateral' => 0.0, 'rhotic' => 0.0],
        'ɦ' => ['type' => 'consonant', 'place' => 10.0, 'manner' => 'fricative', 'voice' => 1.0, 'lateral' => 0.0, 'rhotic' => 0.0],
        'ʔ' => ['type' => 'consonant', 'place' => 10.0, 'manner' => 'stop', 'voice' => 0.0, 'lateral' => 0.0, 'rhotic' => 0.0],
    ];

    return $map;
}

function ll_tools_wordset_games_parse_ipa_similarity_token(string $token): array {
    $token = ll_tools_wordset_games_normalize_speaking_text($token, 'recording_ipa');
    if ($token === '') {
        return [
            'base_units' => [],
            'modifiers' => [],
        ];
    }

    $chars = preg_split('//u', $token, -1, PREG_SPLIT_NO_EMPTY);
    if (!$chars) {
        return [
            'base_units' => [$token],
            'modifiers' => [],
        ];
    }

    $base_units = [];
    $modifiers = [];
    foreach ($chars as $char) {
        if (function_exists('ll_tools_word_grid_is_ipa_separator') && ll_tools_word_grid_is_ipa_separator($char, 'ipa')) {
            continue;
        }
        if (function_exists('ll_tools_word_grid_is_ipa_tie_bar') && ll_tools_word_grid_is_ipa_tie_bar($char, 'ipa')) {
            continue;
        }
        if ((function_exists('ll_tools_word_grid_is_ipa_combining_mark') && ll_tools_word_grid_is_ipa_combining_mark($char))
            || (function_exists('ll_tools_word_grid_is_ipa_post_modifier') && ll_tools_word_grid_is_ipa_post_modifier($char, 'ipa'))) {
            $modifiers[] = $char;
            continue;
        }
        $base_units[] = $char;
    }

    if (empty($base_units)) {
        $base_units[] = $token;
    }

    return [
        'base_units' => array_values($base_units),
        'modifiers' => array_values(array_unique($modifiers)),
    ];
}

function ll_tools_wordset_games_ipa_modifier_weight(string $modifier): float {
    static $weights = [
        'ʰ' => 0.08,
        'ʷ' => 0.08,
        'ʲ' => 0.08,
        'ˠ' => 0.08,
        'ˤ' => 0.08,
        'ʱ' => 0.08,
        '̪' => 0.06,
        '̥' => 0.06,
        '̬' => 0.06,
        '̟' => 0.05,
        '̠' => 0.05,
        '̃' => 0.06,
        'ː' => 0.05,
        'ˑ' => 0.04,
    ];

    return (float) ($weights[$modifier] ?? 0.06);
}

function ll_tools_wordset_games_ipa_modifier_penalty(array $left_modifiers, array $right_modifiers): float {
    $left_lookup = array_fill_keys(array_values(array_filter(array_map('strval', $left_modifiers))), true);
    $right_lookup = array_fill_keys(array_values(array_filter(array_map('strval', $right_modifiers))), true);
    $difference = array_unique(array_merge(
        array_diff(array_keys($left_lookup), array_keys($right_lookup)),
        array_diff(array_keys($right_lookup), array_keys($left_lookup))
    ));

    if (empty($difference)) {
        return 0.0;
    }

    $penalty = 0.0;
    foreach ($difference as $modifier) {
        $penalty += ll_tools_wordset_games_ipa_modifier_weight((string) $modifier);
    }

    return min(0.32, $penalty);
}

function ll_tools_wordset_games_ipa_symbol_similarity(string $left_symbol, string $right_symbol): float {
    $left_symbol = trim((string) $left_symbol);
    $right_symbol = trim((string) $right_symbol);
    if ($left_symbol === '' || $right_symbol === '') {
        return 0.0;
    }
    if ($left_symbol === $right_symbol) {
        return 1.0;
    }

    $feature_map = ll_tools_wordset_games_ipa_similarity_feature_map();
    $left = $feature_map[$left_symbol] ?? null;
    $right = $feature_map[$right_symbol] ?? null;
    if (!is_array($left) || !is_array($right)) {
        return 0.0;
    }

    $left_type = (string) ($left['type'] ?? '');
    $right_type = (string) ($right['type'] ?? '');
    $left_vowel_like = in_array($left_type, ['vowel', 'glide'], true);
    $right_vowel_like = in_array($right_type, ['vowel', 'glide'], true);

    if ($left_vowel_like && $right_vowel_like) {
        $height_penalty = abs((float) ($left['height'] ?? 0.0) - (float) ($right['height'] ?? 0.0)) / 6.0;
        $back_penalty = abs((float) ($left['back'] ?? 0.0) - (float) ($right['back'] ?? 0.0)) / 4.0;
        $round_penalty = abs((float) ($left['round'] ?? 0.0) - (float) ($right['round'] ?? 0.0));
        $type_penalty = ($left_type === $right_type) ? 0.0 : 0.12;
        $score = 1.0 - (($height_penalty * 0.45) + ($back_penalty * 0.35) + ($round_penalty * 0.20) + $type_penalty);
        return max(0.0, min(1.0, $score));
    }

    if ($left_type === 'consonant' && $right_type === 'consonant') {
        $left_manner = (string) ($left['manner'] ?? '');
        $right_manner = (string) ($right['manner'] ?? '');
        if ($left_manner === $right_manner) {
            $manner_penalty = 0.0;
        } elseif (in_array($left_manner, ['tap', 'trill', 'approximant'], true) && in_array($right_manner, ['tap', 'trill', 'approximant'], true)) {
            $manner_penalty = 0.14;
        } elseif (in_array($left_manner, ['fricative', 'approximant'], true) && in_array($right_manner, ['fricative', 'approximant'], true)) {
            $manner_penalty = 0.20;
        } elseif (in_array($left_manner, ['stop', 'fricative'], true) && in_array($right_manner, ['stop', 'fricative'], true)) {
            $manner_penalty = 0.28;
        } elseif (in_array($left_manner, ['stop', 'nasal'], true) && in_array($right_manner, ['stop', 'nasal'], true)) {
            $manner_penalty = 0.24;
        } else {
            $manner_penalty = 0.34;
        }

        $place_penalty = min(0.36, abs((float) ($left['place'] ?? 0.0) - (float) ($right['place'] ?? 0.0)) * 0.075);
        $voice_penalty = ((float) ($left['voice'] ?? 0.0) === (float) ($right['voice'] ?? 0.0)) ? 0.0 : 0.10;
        $lateral_penalty = ((float) ($left['lateral'] ?? 0.0) === (float) ($right['lateral'] ?? 0.0)) ? 0.0 : 0.08;
        $rhotic_penalty = ((float) ($left['rhotic'] ?? 0.0) === (float) ($right['rhotic'] ?? 0.0)) ? 0.0 : 0.06;

        $score = 1.0 - ($manner_penalty + $place_penalty + $voice_penalty + $lateral_penalty + $rhotic_penalty);
        return max(0.0, min(1.0, $score));
    }

    return 0.0;
}

function ll_tools_wordset_games_ipa_token_similarity(string $left_token, string $right_token): float {
    $left_token = trim((string) $left_token);
    $right_token = trim((string) $right_token);
    if ($left_token === '' || $right_token === '') {
        return 0.0;
    }
    if ($left_token === $right_token) {
        return 1.0;
    }

    $left_parts = ll_tools_wordset_games_parse_ipa_similarity_token($left_token);
    $right_parts = ll_tools_wordset_games_parse_ipa_similarity_token($right_token);
    $left_units = (array) ($left_parts['base_units'] ?? []);
    $right_units = (array) ($right_parts['base_units'] ?? []);

    if (empty($left_units) || empty($right_units)) {
        return 0.0;
    }

    $base_distance = ll_tools_wordset_games_array_weighted_levenshtein(
        $left_units,
        $right_units,
        static function ($left_unit, $right_unit): float {
            return 1.0 - ll_tools_wordset_games_ipa_symbol_similarity((string) $left_unit, (string) $right_unit);
        }
    );
    $base_score = max(0.0, 1.0 - ($base_distance / max(count($left_units), count($right_units), 1)));
    $modifier_penalty = ll_tools_wordset_games_ipa_modifier_penalty(
        (array) ($left_parts['modifiers'] ?? []),
        (array) ($right_parts['modifiers'] ?? [])
    );

    return max(0.0, min(1.0, $base_score - $modifier_penalty));
}

function ll_tools_wordset_games_similarity_score_ipa(string $expected, string $actual): float {
    $expected_tokens = ll_tools_wordset_games_tokenize_speaking_text($expected, 'recording_ipa');
    $actual_tokens = ll_tools_wordset_games_tokenize_speaking_text($actual, 'recording_ipa');
    if (empty($expected_tokens) || empty($actual_tokens)) {
        return 0.0;
    }
    if ($expected_tokens === $actual_tokens) {
        return 100.0;
    }

    $token_distance = ll_tools_wordset_games_array_weighted_levenshtein(
        $expected_tokens,
        $actual_tokens,
        static function ($left_token, $right_token): float {
            return 1.0 - ll_tools_wordset_games_ipa_token_similarity((string) $left_token, (string) $right_token);
        }
    );
    $max_tokens = max(count($expected_tokens), count($actual_tokens), 1);
    $token_score = max(0.0, (1.0 - ($token_distance / $max_tokens)) * 100.0);

    $expected_units = [];
    foreach ($expected_tokens as $token) {
        $expected_units = array_merge($expected_units, (array) (ll_tools_wordset_games_parse_ipa_similarity_token((string) $token)['base_units'] ?? []));
    }
    $actual_units = [];
    foreach ($actual_tokens as $token) {
        $actual_units = array_merge($actual_units, (array) (ll_tools_wordset_games_parse_ipa_similarity_token((string) $token)['base_units'] ?? []));
    }

    if (empty($expected_units) || empty($actual_units)) {
        return round($token_score, 2);
    }

    $unit_distance = ll_tools_wordset_games_array_weighted_levenshtein(
        $expected_units,
        $actual_units,
        static function ($left_unit, $right_unit): float {
            return 1.0 - ll_tools_wordset_games_ipa_symbol_similarity((string) $left_unit, (string) $right_unit);
        }
    );
    $max_units = max(count($expected_units), count($actual_units), 1);
    $unit_score = max(0.0, (1.0 - ($unit_distance / $max_units)) * 100.0);

    return round(($token_score * 0.72) + ($unit_score * 0.28), 2);
}

function ll_tools_wordset_games_similarity_score(string $expected, string $actual, string $target_field): float {
    $target_field = sanitize_key($target_field);
    if ($target_field === 'recording_ipa') {
        return ll_tools_wordset_games_similarity_score_ipa($expected, $actual);
    }

    $expected_tokens = ll_tools_wordset_games_tokenize_speaking_text($expected, $target_field);
    $actual_tokens = ll_tools_wordset_games_tokenize_speaking_text($actual, $target_field);

    if (empty($expected_tokens) || empty($actual_tokens)) {
        return 0.0;
    }

    if ($expected_tokens === $actual_tokens) {
        return 100.0;
    }

    $distance = ll_tools_wordset_games_array_levenshtein($expected_tokens, $actual_tokens);
    $max_tokens = max(count($expected_tokens), count($actual_tokens), 1);
    $token_score = max(0.0, (1.0 - ($distance / $max_tokens)) * 100.0);

    $expected_string = implode(' ', $expected_tokens);
    $actual_string = implode(' ', $actual_tokens);
    $char_distance = ll_tools_wordset_games_array_levenshtein(
        preg_split('//u', $expected_string, -1, PREG_SPLIT_NO_EMPTY) ?: [],
        preg_split('//u', $actual_string, -1, PREG_SPLIT_NO_EMPTY) ?: []
    );
    $expected_char_count = function_exists('mb_strlen') ? mb_strlen($expected_string, 'UTF-8') : strlen($expected_string);
    $actual_char_count = function_exists('mb_strlen') ? mb_strlen($actual_string, 'UTF-8') : strlen($actual_string);
    $max_chars = max((int) $expected_char_count, (int) $actual_char_count, 1);
    $char_score = max(0.0, (1.0 - ($char_distance / $max_chars)) * 100.0);

    return round(($token_score + $char_score) / 2.0, 2);
}

function ll_tools_wordset_games_score_bucket(float $score): string {
    if ($score >= 90.0) {
        return 'right';
    }
    if ($score >= 65.0) {
        return 'close';
    }
    return 'wrong';
}

function ll_tools_wordset_games_get_word_audio_posts(int $word_id): array {
    $word_id = (int) $word_id;
    if ($word_id <= 0) {
        return [];
    }

    $audio_posts = get_posts([
        'post_type' => 'word_audio',
        'post_status' => 'publish',
        'post_parent' => $word_id,
        'posts_per_page' => -1,
        'orderby' => 'date',
        'order' => 'DESC',
        'suppress_filters' => true,
        'no_found_rows' => true,
    ]);

    return array_values(array_filter((array) $audio_posts, static function ($post): bool {
        return $post instanceof WP_Post;
    }));
}

function ll_tools_wordset_games_get_audio_details(int $word_id, string $recording_type = ''): array {
    $word_id = (int) $word_id;
    $target_type = ll_tools_normalize_practice_recording_type_slug($recording_type);
    if ($word_id <= 0) {
        return [
            'audio_post_id' => 0,
            'recording_type' => $target_type,
            'url' => '',
            'recording_ipa' => '',
        ];
    }

    $word_audio_posts = ll_tools_wordset_games_get_word_audio_posts($word_id);
    if ($target_type !== '') {
        $word_audio_posts = array_values(array_filter($word_audio_posts, static function ($audio_post) use ($target_type): bool {
            if (!($audio_post instanceof WP_Post)) {
                return false;
            }

            $entry_type = ll_tools_wordset_games_get_audio_recording_type($audio_post);

            return $entry_type === $target_type;
        }));
    }
    if (empty($word_audio_posts)) {
        return [
            'audio_post_id' => 0,
            'recording_type' => $target_type,
            'url' => '',
            'recording_ipa' => '',
        ];
    }

    $preferred_speaker = function_exists('ll_tools_get_preferred_speaker_from_audio_posts')
        ? ll_tools_get_preferred_speaker_from_audio_posts($word_audio_posts)
        : 0;
    $prioritized_audio = function_exists('ll_get_prioritized_audio')
        ? ll_get_prioritized_audio($word_audio_posts, $preferred_speaker)
        : null;

    $candidate_audio_posts = [];
    if ($prioritized_audio instanceof WP_Post) {
        $candidate_audio_posts[] = $prioritized_audio;
    }
    foreach ($word_audio_posts as $audio_post) {
        if (!($audio_post instanceof WP_Post)) {
            continue;
        }
        if ($prioritized_audio instanceof WP_Post && (int) $audio_post->ID === (int) $prioritized_audio->ID) {
            continue;
        }
        $candidate_audio_posts[] = $audio_post;
    }

    foreach ($candidate_audio_posts as $audio_post) {
        $audio_path = trim((string) get_post_meta($audio_post->ID, 'audio_file_path', true));
        if ($audio_path === '') {
            continue;
        }

        if (function_exists('ll_tools_resolve_audio_file_url')) {
            $audio_url = (string) ll_tools_resolve_audio_file_url($audio_path);
        } else {
            $audio_url = (0 === strpos($audio_path, 'http')) ? $audio_path : site_url($audio_path);
        }
        if ($audio_url === '') {
            continue;
        }

        $resolved_type = ll_tools_wordset_games_get_audio_recording_type($audio_post);

        return [
            'audio_post_id' => (int) $audio_post->ID,
            'recording_type' => $resolved_type !== '' ? $resolved_type : $target_type,
            'url' => $audio_url,
            'recording_ipa' => trim((string) get_post_meta($audio_post->ID, 'recording_ipa', true)),
        ];
    }

    return [
        'audio_post_id' => 0,
        'recording_type' => $target_type,
        'url' => '',
        'recording_ipa' => '',
    ];
}

function ll_tools_wordset_games_get_best_correct_audio_url(int $word_id, array $word_data = []): string {
    return (string) (ll_tools_wordset_games_get_audio_details((int) $word_id, '')['url'] ?? '');
}

function ll_tools_wordset_games_get_speaking_display_texts(int $word_id, string $target_field, array $word_data = []): array {
    $word_id = (int) $word_id;
    $target_field = sanitize_key($target_field);

    $title = trim((string) ($word_data['title'] ?? ''));
    if ($title === '' && $word_id > 0) {
        $title = html_entity_decode((string) get_the_title($word_id), ENT_QUOTES, 'UTF-8');
    }
    $title = trim((string) $title);

    $ipa = trim((string) ($word_data['recording_ipa'] ?? ''));
    if ($ipa === '' && $word_id > 0) {
        $ipa = trim((string) ll_tools_get_wordset_speaking_game_target_value($word_id, 'recording_ipa', $word_data));
    }

    $target_text = ll_tools_get_wordset_speaking_game_target_value($word_id, $target_field, $word_data);

    return [
        'title' => $title,
        'ipa' => $ipa,
        'target_text' => $target_text,
        'target_field' => $target_field,
        'target_label' => $target_field === 'recording_ipa'
            ? __('IPA', 'll-tools-text-domain')
            : __('Word title', 'll-tools-text-domain'),
    ];
}

function ll_tools_wordset_games_score_speaking_transcript(int $wordset_id, int $word_id, string $transcript, string $target_field = ''): array {
    $wordset_id = (int) $wordset_id;
    $word_id = (int) $word_id;
    $transcript = trim((string) $transcript);
    $target_field = sanitize_key($target_field);

    if ($wordset_id <= 0 || $word_id <= 0) {
        return new WP_Error('invalid_context', __('Invalid word or word set.', 'll-tools-text-domain'));
    }

    $wordset_term = get_term($wordset_id, 'wordset');
    if (!($wordset_term instanceof WP_Term) || is_wp_error($wordset_term)) {
        return new WP_Error('invalid_wordset', __('Word set not found.', 'll-tools-text-domain'));
    }

    $word = get_post($word_id);
    if (!($word instanceof WP_Post) || $word->post_type !== 'words' || $word->post_status !== 'publish') {
        return new WP_Error('invalid_word', __('Word not found.', 'll-tools-text-domain'));
    }
    if (function_exists('ll_tools_user_can_view_wordset') && !ll_tools_user_can_view_wordset($wordset_term)) {
        return new WP_Error('forbidden', __('You do not have permission.', 'll-tools-text-domain'));
    }
    if (!has_term($wordset_id, 'wordset', $word)) {
        return new WP_Error('invalid_word', __('Word does not belong to the selected word set.', 'll-tools-text-domain'));
    }
    if ($transcript === '') {
        return new WP_Error('empty_transcript', __('Transcript is empty.', 'll-tools-text-domain'));
    }

    $config = function_exists('ll_tools_get_wordset_speaking_game_config')
        ? ll_tools_get_wordset_speaking_game_config([$wordset_id], true)
        : [];
    $configured_target = sanitize_key((string) ($config['target'] ?? 'word_title'));
    if ($target_field === '') {
        $target_field = $configured_target;
    }
    if ($target_field !== $configured_target) {
        return new WP_Error('target_mismatch', __('Invalid speaking target.', 'll-tools-text-domain'));
    }

    $isolation_audio = ll_tools_wordset_games_get_audio_details($word_id, 'isolation');
    $display_word_data = ['title' => $word->post_title];
    if ($target_field === 'recording_ipa') {
        $display_word_data['recording_ipa'] = trim((string) ($isolation_audio['recording_ipa'] ?? ''));
    }

    $isolation_ipa = trim((string) ($isolation_audio['recording_ipa'] ?? ''));
    $display_texts = ll_tools_wordset_games_get_speaking_display_texts($word_id, $target_field, $display_word_data);
    if ($target_field === 'recording_ipa') {
        $display_texts['ipa'] = $isolation_ipa;
        $display_texts['target_text'] = $isolation_ipa;
    }
    $expected = ($target_field === 'recording_ipa')
        ? $isolation_ipa
        : trim((string) ($display_texts['target_text'] ?? ''));
    if ($expected === '') {
        return new WP_Error('missing_target', __('Target text is missing for this word.', 'll-tools-text-domain'));
    }

    $normalized_expected = ll_tools_wordset_games_normalize_speaking_text($expected, $target_field);
    $normalized_transcript = ll_tools_wordset_games_normalize_speaking_text($transcript, $target_field);
    if ($normalized_expected === '' || $normalized_transcript === '') {
        return new WP_Error('empty_transcript', __('Transcript could not be normalized.', 'll-tools-text-domain'));
    }

    $score = ll_tools_wordset_games_similarity_score($normalized_expected, $normalized_transcript, $target_field);
    $bucket = ll_tools_wordset_games_score_bucket($score);

    $best_audio_url = trim((string) ($isolation_audio['url'] ?? ''));

    return [
        'wordset_id' => $wordset_id,
        'word_id' => $word_id,
        'target_field' => $target_field,
        'target_label' => (string) ($display_texts['target_label'] ?? ''),
        'target_text' => $expected,
        'normalized_target_text' => $normalized_expected,
        'normalized_transcript_text' => $normalized_transcript,
        'score' => $score,
        'bucket' => $bucket,
        'display_texts' => $display_texts,
        'best_correct_audio_url' => $best_audio_url,
    ];
}

function ll_tools_wordset_games_score_best_speaking_match(int $wordset_id, array $word_ids, string $transcript, string $target_field = '') {
    $candidate_ids = array_values(array_unique(array_filter(array_map('intval', $word_ids), static function (int $word_id): bool {
        return $word_id > 0;
    })));
    if (empty($candidate_ids)) {
        return new WP_Error('missing_words', __('No active words were provided.', 'll-tools-text-domain'));
    }

    $best_result = null;
    $scored_count = 0;
    $bucket_rank = [
        'wrong' => 0,
        'close' => 1,
        'right' => 2,
    ];

    foreach ($candidate_ids as $candidate_id) {
        $result = ll_tools_wordset_games_score_speaking_transcript($wordset_id, $candidate_id, $transcript, $target_field);
        if (is_wp_error($result)) {
            continue;
        }

        $scored_count += 1;
        if (!is_array($best_result)) {
            $best_result = $result;
            continue;
        }

        $result_score = (float) ($result['score'] ?? 0.0);
        $best_score = (float) ($best_result['score'] ?? 0.0);
        $result_rank = (int) ($bucket_rank[(string) ($result['bucket'] ?? 'wrong')] ?? 0);
        $best_rank = (int) ($bucket_rank[(string) ($best_result['bucket'] ?? 'wrong')] ?? 0);

        if ($result_score > $best_score || ($result_score === $best_score && $result_rank > $best_rank)) {
            $best_result = $result;
        }
    }

    if ($scored_count <= 0 || !is_array($best_result)) {
        return new WP_Error('no_candidates', __('No playable words were available for matching.', 'll-tools-text-domain'));
    }

    $matched = (string) ($best_result['bucket'] ?? 'wrong') !== 'wrong';

    return array_merge($best_result, [
        'matched' => $matched,
        'candidate_word_ids' => $candidate_ids,
        'candidate_count' => $scored_count,
    ]);
}

function ll_tools_wordset_games_require_speaking_permissions(): void {
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => __('Login required.', 'll-tools-text-domain')], 401);
    }
    if (!(current_user_can('view_ll_tools') || current_user_can('manage_options'))) {
        wp_send_json_error(['message' => __('You do not have permission.', 'll-tools-text-domain')], 403);
    }
    if (function_exists('ll_tools_user_study_can_access') && !ll_tools_user_study_can_access()) {
        wp_send_json_error(['message' => __('You do not have permission.', 'll-tools-text-domain')], 403);
    }
    check_ajax_referer('ll_user_study', 'nonce');
}

function ll_tools_wordset_games_validate_speaking_wordset_request(): array {
    $wordset_id = isset($_POST['wordset_id']) ? (int) $_POST['wordset_id'] : 0;
    if ($wordset_id <= 0) {
        return [null, new WP_Error('invalid_wordset', __('Invalid word set.', 'll-tools-text-domain'))];
    }

    $wordset_term = get_term($wordset_id, 'wordset');
    if (!($wordset_term instanceof WP_Term) || is_wp_error($wordset_term)) {
        return [null, new WP_Error('invalid_wordset', __('Word set not found.', 'll-tools-text-domain'))];
    }

    if (function_exists('ll_tools_user_can_view_wordset') && !ll_tools_user_can_view_wordset($wordset_term)) {
        return [null, new WP_Error('forbidden', __('You do not have permission.', 'll-tools-text-domain'))];
    }

    $config = function_exists('ll_tools_get_wordset_speaking_game_config')
        ? ll_tools_get_wordset_speaking_game_config([$wordset_id], true)
        : [];
    if (empty($config['enabled'])) {
        return [null, new WP_Error('speaking_disabled', __('Speaking practice is not enabled for this word set.', 'll-tools-text-domain'))];
    }

    return [$wordset_id, $config];
}

function ll_tools_wordset_games_transcribe_attempt_ajax(): void {
    ll_tools_wordset_games_require_speaking_permissions();

    [$wordset_id, $config_or_error] = ll_tools_wordset_games_validate_speaking_wordset_request();
    if (is_wp_error($config_or_error)) {
        wp_send_json_error([
            'code' => $config_or_error->get_error_code(),
            'message' => $config_or_error->get_error_message(),
        ], 400);
    }

    $config = (array) $config_or_error;
    $provider = sanitize_key((string) ($config['provider'] ?? ''));
    if (!in_array($provider, ['assemblyai', 'hosted_api'], true)) {
        wp_send_json_error([
            'code' => 'provider_unsupported',
            'message' => __('This speaking set is not configured for server-side transcription.', 'll-tools-text-domain'),
        ], 400);
    }
    if (empty($_FILES['audio']) || !is_array($_FILES['audio']) || empty($_FILES['audio']['tmp_name'])) {
        wp_send_json_error([
            'code' => 'missing_audio',
            'message' => __('Missing audio file.', 'll-tools-text-domain'),
        ], 400);
    }

    $text = '';
    if ($provider === 'assemblyai') {
        if (!function_exists('ll_tools_assemblyai_transcribe_audio_file')) {
            wp_send_json_error([
                'code' => 'assemblyai_unavailable',
                'message' => __('AssemblyAI integration is not available.', 'll-tools-text-domain'),
            ], 500);
        }

        $language_code = '';
        if (function_exists('ll_tools_get_assemblyai_language_code')) {
            $language_code = (string) ll_tools_get_assemblyai_language_code([$wordset_id]);
        }

        $result = ll_tools_assemblyai_transcribe_audio_file((string) $_FILES['audio']['tmp_name'], $language_code);
        if (is_wp_error($result)) {
            wp_send_json_error([
                'code' => $result->get_error_code(),
                'message' => $result->get_error_message(),
            ], 400);
        }

        $text = trim((string) ($result['text'] ?? ''));
    } else {
        $service = function_exists('ll_tools_get_wordset_transcription_service_config')
            ? ll_tools_get_wordset_transcription_service_config([$wordset_id], true)
            : [];
        $endpoint = trim((string) ($service['local_endpoint'] ?? ''));
        $token = function_exists('ll_tools_get_wordset_transcription_api_token')
            ? ll_tools_get_wordset_transcription_api_token([$wordset_id], true)
            : '';
        $word_id = isset($_POST['word_id']) ? (int) $_POST['word_id'] : 0;
        $word_title = isset($_POST['word_title']) ? sanitize_text_field(wp_unslash((string) $_POST['word_title'])) : '';
        $target_field = (($service['target_field'] ?? '') === 'recording_ipa') ? 'recording_ipa' : 'recording_text';
        $result = ll_tools_remote_stt_transcribe_audio_file($endpoint, (string) $_FILES['audio']['tmp_name'], [
            'token' => $token,
            'filename' => sanitize_file_name((string) ($_FILES['audio']['name'] ?? 'speaking-attempt.webm')),
            'fields' => [
                'wordset_id' => (string) $wordset_id,
                'word_id' => $word_id > 0 ? (string) $word_id : '',
                'word_title' => $word_title,
                'recording_type' => 'speaking_attempt',
                'target_field' => $target_field,
            ],
        ]);
        if (is_wp_error($result)) {
            wp_send_json_error([
                'code' => $result->get_error_code(),
                'message' => $result->get_error_message(),
            ], 400);
        }

        $text = trim((string) ($result['transcript'] ?? ''));
    }

    wp_send_json_success([
        'wordset_id' => $wordset_id,
        'provider' => $provider,
        'status' => 'completed',
        'transcript' => $text,
        'text' => $text,
        'normalized_transcript' => ll_tools_wordset_games_normalize_speaking_text($text, sanitize_key((string) ($config['target'] ?? 'word_title'))),
    ]);
}
add_action('wp_ajax_ll_wordset_speaking_game_transcribe_attempt', 'll_tools_wordset_games_transcribe_attempt_ajax');

function ll_tools_wordset_games_score_attempt_ajax(): void {
    ll_tools_wordset_games_require_speaking_permissions();

    [$wordset_id, $config_or_error] = ll_tools_wordset_games_validate_speaking_wordset_request();
    if (is_wp_error($config_or_error)) {
        wp_send_json_error([
            'code' => $config_or_error->get_error_code(),
            'message' => $config_or_error->get_error_message(),
        ], 400);
    }

    $word_id = isset($_POST['word_id']) ? (int) $_POST['word_id'] : 0;
    $transcript = isset($_POST['transcript']) ? wp_unslash((string) $_POST['transcript']) : '';
    if ($word_id <= 0 || trim($transcript) === '') {
        wp_send_json_error([
            'code' => 'missing_data',
            'message' => __('Missing word or transcript.', 'll-tools-text-domain'),
        ], 400);
    }

    $target_field = sanitize_key((string) ($_POST['target_field'] ?? ''));
    $result = ll_tools_wordset_games_score_speaking_transcript($wordset_id, $word_id, $transcript, $target_field);
    if (is_wp_error($result)) {
        wp_send_json_error([
            'code' => $result->get_error_code(),
            'message' => $result->get_error_message(),
        ], 400);
    }

    wp_send_json_success($result);
}
add_action('wp_ajax_ll_wordset_speaking_game_score_attempt', 'll_tools_wordset_games_score_attempt_ajax');

function ll_tools_wordset_games_match_attempt_ajax(): void {
    ll_tools_wordset_games_require_speaking_permissions();

    [$wordset_id, $config_or_error] = ll_tools_wordset_games_validate_speaking_wordset_request();
    if (is_wp_error($config_or_error)) {
        wp_send_json_error([
            'code' => $config_or_error->get_error_code(),
            'message' => $config_or_error->get_error_message(),
        ], 400);
    }

    $transcript = isset($_POST['transcript']) ? wp_unslash((string) $_POST['transcript']) : '';
    $word_ids = isset($_POST['word_ids']) ? (array) $_POST['word_ids'] : [];
    if (trim($transcript) === '' || empty($word_ids)) {
        wp_send_json_error([
            'code' => 'missing_data',
            'message' => __('Missing active words or transcript.', 'll-tools-text-domain'),
        ], 400);
    }

    $target_field = sanitize_key((string) ($_POST['target_field'] ?? ''));
    $result = ll_tools_wordset_games_score_best_speaking_match($wordset_id, $word_ids, $transcript, $target_field);
    if (is_wp_error($result)) {
        wp_send_json_error([
            'code' => $result->get_error_code(),
            'message' => $result->get_error_message(),
        ], 400);
    }

    wp_send_json_success($result);
}
add_action('wp_ajax_ll_wordset_speaking_game_match_attempt', 'll_tools_wordset_games_match_attempt_ajax');

function ll_tools_wordset_games_bootstrap_ajax(): void {
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => __('Login required.', 'll-tools-text-domain')], 401);
    }
    if (!ll_tools_user_study_can_access()) {
        wp_send_json_error(['message' => __('You do not have permission.', 'll-tools-text-domain')], 403);
    }
    check_ajax_referer('ll_user_study', 'nonce');

    $wordset_id = isset($_POST['wordset_id']) ? (int) $_POST['wordset_id'] : 0;
    if ($wordset_id <= 0) {
        wp_send_json_error(['message' => __('Invalid word set.', 'll-tools-text-domain')], 400);
    }

    $wordset_term = get_term($wordset_id, 'wordset');
    if (!($wordset_term instanceof WP_Term) || is_wp_error($wordset_term)) {
        wp_send_json_error(['message' => __('Word set not found.', 'll-tools-text-domain')], 404);
    }
    if (function_exists('ll_tools_user_can_view_wordset') && !ll_tools_user_can_view_wordset($wordset_term)) {
        wp_send_json_error(['message' => __('You do not have permission.', 'll-tools-text-domain')], 403);
    }

    wp_send_json_success([
        'wordset_id' => $wordset_id,
        'games' => ll_tools_wordset_games_build_catalog($wordset_id, get_current_user_id()),
    ]);
}
add_action('wp_ajax_ll_wordset_games_bootstrap', 'll_tools_wordset_games_bootstrap_ajax');
