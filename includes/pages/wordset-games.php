<?php
// /includes/pages/wordset-games.php
if (!defined('WPINC')) { die; }

function ll_tools_wordset_games_min_word_count(): int {
    return max(5, (int) apply_filters('ll_tools_wordset_games_min_word_count', 5));
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

function ll_tools_wordset_games_default_catalog(): array {
    return [
        'space-shooter' => [
            'slug' => 'space-shooter',
            'title' => __('Arcane Space Shooter', 'll-tools-text-domain'),
            'description' => __('Hear the word. Blast the matching picture.', 'll-tools-text-domain'),
        ],
    ];
}

function ll_tools_wordset_games_visible_category_ids(int $wordset_id, int $user_id = 0): array {
    $wordset_id = max(0, $wordset_id);
    $uid = (int) ($user_id ?: get_current_user_id());
    if ($wordset_id <= 0) {
        return [];
    }

    $categories_payload = function_exists('ll_tools_user_study_categories_for_wordset')
        ? ll_tools_user_study_categories_for_wordset($wordset_id)
        : [];
    if (empty($categories_payload)) {
        return [];
    }

    if (function_exists('ll_tools_user_progress_category_ids_in_scope')) {
        $goals = function_exists('ll_tools_get_user_study_goals') && $uid > 0
            ? ll_tools_get_user_study_goals($uid)
            : [];
        $visible = ll_tools_user_progress_category_ids_in_scope($categories_payload, [], $goals, false);
        if (!empty($visible)) {
            return array_values(array_unique(array_filter(array_map('intval', (array) $visible), static function (int $id): bool {
                return $id > 0;
            })));
        }
    }

    return array_values(array_filter(array_map(static function ($row): int {
        return is_array($row) ? (int) ($row['id'] ?? 0) : 0;
    }, (array) $categories_payload), static function (int $id): bool {
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

function ll_tools_wordset_games_collect_visible_words(int $wordset_id, int $user_id = 0): array {
    $wordset_id = max(0, $wordset_id);
    $uid = (int) ($user_id ?: get_current_user_id());
    if ($wordset_id <= 0) {
        return [
            'category_ids' => [],
            'words' => [],
        ];
    }

    $visible_category_ids = ll_tools_wordset_games_visible_category_ids($wordset_id, $uid);
    if (empty($visible_category_ids)) {
        return [
            'category_ids' => [],
            'words' => [],
        ];
    }

    $wordset_ids = [$wordset_id];
    $words_by_id = [];

    foreach ($visible_category_ids as $category_id) {
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
        'category_ids' => $visible_category_ids,
        'words' => array_values($words_by_id),
    ];
}

function ll_tools_wordset_games_build_space_shooter_pool(int $wordset_id, int $user_id = 0): array {
    $uid = (int) ($user_id ?: get_current_user_id());
    $collected = ll_tools_wordset_games_collect_visible_words($wordset_id, $uid);
    $words = isset($collected['words']) && is_array($collected['words']) ? $collected['words'] : [];
    $word_ids = array_values(array_filter(array_map(static function ($row): int {
        return is_array($row) ? (int) ($row['id'] ?? 0) : 0;
    }, $words), static function (int $id): bool {
        return $id > 0;
    }));
    $progress_rows = (!empty($word_ids) && function_exists('ll_tools_get_user_word_progress_rows'))
        ? ll_tools_get_user_word_progress_rows($uid, $word_ids)
        : [];

    $pool = [];
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
        if (function_exists('ll_tools_user_progress_word_status')) {
            $status = ll_tools_user_progress_word_status($progress);
            if ($status !== 'studied') {
                continue;
            }
        }

        if (empty($word['image'])) {
            continue;
        }
        if (!ll_tools_wordset_games_word_has_recording_type($word, 'isolation')) {
            continue;
        }

        $word['game_prompt_recording_type'] = 'isolation';
        $word['progress_status'] = 'studied';
        $pool[] = $word;
    }

    if (!empty($pool) && function_exists('ll_tools_attach_user_practice_progress_to_words')) {
        $pool = ll_tools_attach_user_practice_progress_to_words($pool, $uid);
    }

    return [
        'slug' => 'space-shooter',
        'minimum_word_count' => ll_tools_wordset_games_min_word_count(),
        'available_word_count' => count($pool),
        'launchable' => count($pool) >= ll_tools_wordset_games_min_word_count(),
        'category_ids' => isset($collected['category_ids']) && is_array($collected['category_ids']) ? $collected['category_ids'] : [],
        'words' => array_values($pool),
    ];
}

function ll_tools_wordset_games_build_catalog(int $wordset_id, int $user_id = 0): array {
    $catalog = ll_tools_wordset_games_default_catalog();
    $space_shooter = ll_tools_wordset_games_build_space_shooter_pool($wordset_id, $user_id);
    $minimum = (int) ($space_shooter['minimum_word_count'] ?? ll_tools_wordset_games_min_word_count());
    $available = (int) ($space_shooter['available_word_count'] ?? 0);

    $catalog['space-shooter'] = array_merge($catalog['space-shooter'], [
        'minimum_word_count' => $minimum,
        'available_word_count' => $available,
        'launchable' => !empty($space_shooter['launchable']),
        'reason_code' => $available >= $minimum ? '' : 'not_enough_words',
        'category_ids' => isset($space_shooter['category_ids']) && is_array($space_shooter['category_ids']) ? $space_shooter['category_ids'] : [],
        'words' => isset($space_shooter['words']) && is_array($space_shooter['words']) ? $space_shooter['words'] : [],
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
