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
        . '<circle cx="11.5" cy="10.2" r="6.2" fill="currentColor" fill-opacity="0.18" stroke="currentColor" stroke-width="1.5"/>'
        . '<circle cx="9.3" cy="8.1" r="1.5" fill="currentColor" fill-opacity="0.72"/>'
        . '<path d="M11.5 16.4V20.2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>'
        . '<path d="M9.2 19.1L11.5 21.4L13.8 19.1" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>'
        . '<circle cx="17.7" cy="6" r="2.3" fill="currentColor" fill-opacity="0.16" stroke="currentColor" stroke-width="1.3"/>'
        . '</svg>';
}

function ll_tools_wordset_games_render_game_icon(string $slug, string $class = 'll-wordset-games-icon'): string {
    $normalized_slug = sanitize_key($slug);
    if ($normalized_slug === 'bubble-pop') {
        return ll_tools_wordset_games_render_bubble_icon($class);
    }

    return ll_tools_wordset_games_render_icon($class);
}

function ll_tools_wordset_games_default_catalog(): array {
    return [
        'space-shooter' => [
            'slug' => 'space-shooter',
            'title' => __('Arcane Space Shooter', 'll-tools-text-domain'),
            'description' => __('Hear the word. Blast the matching picture.', 'll-tools-text-domain'),
        ],
        'bubble-pop' => [
            'slug' => 'bubble-pop',
            'title' => __('Bubble Pop', 'll-tools-text-domain'),
            'description' => __('Hear the word. Pop the matching bubble.', 'll-tools-text-domain'),
        ],
    ];
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
    $collected = ll_tools_wordset_games_collect_visible_words($wordset_id, $uid);
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

        if (empty($word['image'])) {
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
    $catalog = ll_tools_wordset_games_default_catalog();
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

    return $catalog;
}

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
