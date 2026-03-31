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

function ll_tools_wordset_games_render_game_icon(string $slug, string $class = 'll-wordset-games-icon'): string {
    $normalized_slug = sanitize_key($slug);
    if ($normalized_slug === 'bubble-pop') {
        return ll_tools_wordset_games_render_bubble_icon($class);
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

function ll_tools_wordset_games_collect_speaking_ipa_map(array $word_ids): array {
    $word_ids = array_values(array_filter(array_map('intval', $word_ids), static function (int $word_id): bool {
        return $word_id > 0;
    }));
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

    $collected = ll_tools_wordset_games_collect_visible_words($wordset_id, $uid);
    $categories = isset($collected['categories']) && is_array($collected['categories']) ? $collected['categories'] : [];
    $words = isset($collected['words']) && is_array($collected['words']) ? $collected['words'] : [];
    $word_ids = array_values(array_filter(array_map(static function ($row): int {
        return is_array($row) ? (int) ($row['id'] ?? 0) : 0;
    }, $words), static function (int $id): bool {
        return $id > 0;
    }));

    $ipa_map = ($target_field === 'recording_ipa')
        ? ll_tools_wordset_games_collect_speaking_ipa_map($word_ids)
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

        $target_text = ll_tools_get_wordset_speaking_game_target_value($word_id, $target_field, $word);
        if ($target_field === 'recording_ipa' && $target_text === '' && isset($ipa_map[$word_id])) {
            $target_text = trim((string) $ipa_map[$word_id]);
        }
        if ($target_text === '') {
            continue;
        }

        $prompt_text = ll_tools_wordset_games_get_speaking_prompt_text($word);
        if ($prompt_text === '') {
            $prompt_text = trim((string) ($word['title'] ?? ''));
        }
        $display_texts = ll_tools_wordset_games_get_speaking_display_texts($word_id, $target_field, $word);
        $best_correct_audio_url = ll_tools_wordset_games_get_best_correct_audio_url($word_id, $word);
        if ($best_correct_audio_url === '') {
            continue;
        }

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

function ll_tools_wordset_games_similarity_score(string $expected, string $actual, string $target_field): float {
    $target_field = sanitize_key($target_field);
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

function ll_tools_wordset_games_get_best_correct_audio_url(int $word_id, array $word_data = []): string {
    $word_id = (int) $word_id;
    if ($word_id <= 0) {
        return '';
    }

    $word_audio_posts = ll_tools_wordset_games_get_word_audio_posts($word_id);
    if (empty($word_audio_posts)) {
        return '';
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
        if ($audio_url !== '') {
            return $audio_url;
        }
    }

    return '';
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

    $display_texts = ll_tools_wordset_games_get_speaking_display_texts($word_id, $target_field, ['title' => $word->post_title]);
    $expected = trim((string) ($display_texts['target_text'] ?? ''));
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

    $best_audio_url = ll_tools_wordset_games_get_best_correct_audio_url($word_id, ['title' => $word->post_title]);

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
    if (($config['provider'] ?? '') !== 'assemblyai') {
        wp_send_json_error([
            'code' => 'provider_unsupported',
            'message' => __('This speaking set is not configured for AssemblyAI transcription.', 'll-tools-text-domain'),
        ], 400);
    }
    if (!function_exists('ll_tools_assemblyai_transcribe_audio_file')) {
        wp_send_json_error([
            'code' => 'assemblyai_unavailable',
            'message' => __('AssemblyAI integration is not available.', 'll-tools-text-domain'),
        ], 500);
    }
    if (empty($_FILES['audio']) || !is_array($_FILES['audio']) || empty($_FILES['audio']['tmp_name'])) {
        wp_send_json_error([
            'code' => 'missing_audio',
            'message' => __('Missing audio file.', 'll-tools-text-domain'),
        ], 400);
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
    wp_send_json_success([
        'wordset_id' => $wordset_id,
        'provider' => 'assemblyai',
        'status' => (string) ($result['status'] ?? 'completed'),
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
