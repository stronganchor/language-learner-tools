<?php
// /includes/admin/word-option-rules-admin.php
if (!defined('WPINC')) { die; }

function ll_register_word_option_rules_admin_page() {
    add_submenu_page(
        'tools.php',
        __('Language Learner Tools - Word Options', 'll-tools-text-domain'),
        __('LL Word Options', 'll-tools-text-domain'),
        'view_ll_tools',
        'll-word-option-rules',
        'll_render_word_option_rules_admin_page'
    );
}
add_action('admin_menu', 'll_register_word_option_rules_admin_page');

function ll_enqueue_word_option_rules_admin_assets($hook) {
    if ($hook !== 'tools_page_ll-word-option-rules') {
        return;
    }

    ll_enqueue_asset_by_timestamp('/css/word-option-rules-admin.css', 'll-tools-word-option-rules-admin', [], false);
    ll_enqueue_asset_by_timestamp('/js/word-option-rules-admin.js', 'll-tools-word-option-rules-admin-js', [], true);
    wp_localize_script('ll-tools-word-option-rules-admin-js', 'llWordOptionRulesI18n', [
        'remove' => __('Remove', 'll-tools-text-domain'),
        'assignToGroup' => __('Assign to group', 'll-tools-text-domain'),
        /* translators: %s: group label */
        'assignToGroupNamedTemplate' => __('Assign to group %s', 'll-tools-text-domain'),
    ]);
}
add_action('admin_enqueue_scripts', 'll_enqueue_word_option_rules_admin_assets');

function ll_tools_word_option_rules_get_word_posts(int $wordset_id, int $category_id): array {
    $wordset_id = (int) $wordset_id;
    $category_id = (int) $category_id;
    if ($wordset_id <= 0 || $category_id <= 0) {
        return [];
    }

    return get_posts([
        'post_type' => 'words',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'orderby' => 'title',
        'order' => 'ASC',
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
}

function ll_tools_word_option_rules_get_word_ids(int $wordset_id, int $category_id): array {
    $posts = ll_tools_word_option_rules_get_word_posts($wordset_id, $category_id);
    if (empty($posts)) {
        return [];
    }
    return array_values(array_map('intval', wp_list_pluck($posts, 'ID')));
}

function ll_tools_word_option_rules_get_word_label(int $word_id): string {
    $word_id = (int) $word_id;
    if ($word_id <= 0) {
        return '';
    }

    if (function_exists('ll_tools_word_grid_resolve_display_text')) {
        $display = ll_tools_word_grid_resolve_display_text($word_id);
        $word_text = trim((string) ($display['word_text'] ?? ''));
        $translation = trim((string) ($display['translation_text'] ?? ''));
        if ($translation !== '') {
            return $word_text . ' - ' . $translation;
        }
        return $word_text;
    }

    $title = get_the_title($word_id);
    $translation = (string) get_post_meta($word_id, 'word_translation', true);
    if ($translation !== '') {
        return $title . ' - ' . $translation;
    }
    return (string) $title;
}

function ll_tools_word_option_rules_get_audio_entry(array $audio_files): array {
    if (empty($audio_files)) {
        return [];
    }

    $preferred_speaker = function_exists('ll_tools_word_grid_get_preferred_speaker')
        ? ll_tools_word_grid_get_preferred_speaker($audio_files, ['isolation', 'question', 'introduction'])
        : 0;
    $priority = ['isolation', 'question', 'introduction', 'sentence', 'in sentence'];
    if (function_exists('ll_tools_word_grid_select_audio_entry')) {
        foreach ($priority as $type) {
            $entry = ll_tools_word_grid_select_audio_entry($audio_files, $type, $preferred_speaker);
            if (!empty($entry['url'])) {
                if (empty($entry['recording_type'])) {
                    $entry['recording_type'] = $type;
                }
                return (array) $entry;
            }
        }
    }

    foreach ($audio_files as $file) {
        if (!empty($file['url'])) {
            $file = (array) $file;
            if (empty($file['recording_type'])) {
                $file['recording_type'] = 'isolation';
            }
            return $file;
        }
    }

    return [];
}

function ll_tools_word_option_rules_get_similar_pair_map(array $word_ids): array {
    $word_ids = array_values(array_filter(array_map('intval', $word_ids), function ($id) {
        return $id > 0;
    }));
    if (empty($word_ids)) {
        return [];
    }

    $lookup = array_fill_keys($word_ids, true);
    $pairs = [];
    foreach ($word_ids as $word_id) {
        $similar_id = get_post_meta($word_id, 'similar_word_id', true);
        if ($similar_id === '' || $similar_id === null) {
            $similar_id = get_post_meta($word_id, '_ll_similar_word_id', true);
        }
        $similar_id = (int) $similar_id;
        if ($similar_id <= 0 || $similar_id === $word_id) {
            continue;
        }
        if (!isset($lookup[$similar_id])) {
            continue;
        }

        $a = $word_id;
        $b = $similar_id;
        if ($a > $b) {
            $tmp = $a;
            $a = $b;
            $b = $tmp;
        }
        $key = $a . '|' . $b;
        if (!isset($pairs[$key])) {
            $pairs[$key] = [
                'a' => $a,
                'b' => $b,
                'sources' => [],
            ];
        }
        $pairs[$key]['sources'][] = $word_id;
    }

    return $pairs;
}

function ll_tools_word_option_rules_get_image_pair_map(array $word_ids): array {
    $word_ids = array_values(array_filter(array_map('intval', $word_ids), function ($id) {
        return $id > 0;
    }));
    if (empty($word_ids)) {
        return [];
    }

    if (!function_exists('ll_tools_collect_word_image_hashes') || !function_exists('ll_tools_find_similar_image_pairs')) {
        return [];
    }

    $hashes = ll_tools_collect_word_image_hashes($word_ids);
    if (empty($hashes)) {
        return [];
    }

    $pairs = ll_tools_find_similar_image_pairs($hashes);
    if (empty($pairs)) {
        return [];
    }

    $out = [];
    foreach ($pairs as $pair) {
        $a = (int) ($pair['a'] ?? 0);
        $b = (int) ($pair['b'] ?? 0);
        if ($a <= 0 || $b <= 0) {
            continue;
        }
        $out[$a . '|' . $b] = [
            'a' => $a,
            'b' => $b,
            'distance' => (int) ($pair['distance'] ?? 0),
        ];
    }

    return $out;
}

function ll_tools_word_option_rules_clear_similar_meta_pair(int $word_id, int $other_id): void {
    $word_id = (int) $word_id;
    $other_id = (int) $other_id;
    if ($word_id <= 0 || $other_id <= 0) {
        return;
    }

    $keys = ['similar_word_id', '_ll_similar_word_id'];
    foreach ($keys as $key) {
        $value = (int) get_post_meta($word_id, $key, true);
        if ($value === $other_id) {
            delete_post_meta($word_id, $key);
        }
    }
}

function ll_tools_word_option_rules_get_wordset_category_ids(int $wordset_id): array {
    global $wpdb;

    $wordset_id = (int) $wordset_id;
    if ($wordset_id <= 0) {
        return [];
    }

    $sql = $wpdb->prepare("
        SELECT DISTINCT tt_cat.term_id
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->term_relationships} tr_ws ON tr_ws.object_id = p.ID
        INNER JOIN {$wpdb->term_taxonomy} tt_ws ON tt_ws.term_taxonomy_id = tr_ws.term_taxonomy_id
        INNER JOIN {$wpdb->term_relationships} tr_cat ON tr_cat.object_id = p.ID
        INNER JOIN {$wpdb->term_taxonomy} tt_cat ON tt_cat.term_taxonomy_id = tr_cat.term_taxonomy_id
        WHERE p.post_type = %s
          AND p.post_status = %s
          AND tt_ws.taxonomy = %s
          AND tt_ws.term_id = %d
          AND tt_cat.taxonomy = %s
    ", 'words', 'publish', 'wordset', $wordset_id, 'word-category');

    $ids = array_map('intval', (array) $wpdb->get_col($sql));
    $ids = array_values(array_filter($ids, function ($id) { return $id > 0; }));
    return array_values(array_unique($ids));
}

function ll_tools_word_option_rules_collect_export_data(int $wordset_id, int $category_id): array {
    $rules = function_exists('ll_tools_get_word_option_rules')
        ? ll_tools_get_word_option_rules($wordset_id, $category_id)
        : ['groups' => [], 'pairs' => []];
    $maps = function_exists('ll_tools_get_word_option_maps')
        ? ll_tools_get_word_option_maps($wordset_id, $category_id)
        : ['group_map' => []];
    $group_map = $maps['group_map'] ?? [];

    $word_ids = ll_tools_word_option_rules_get_word_ids($wordset_id, $category_id);
    $hashes = function_exists('ll_tools_collect_word_image_hashes')
        ? ll_tools_collect_word_image_hashes($word_ids)
        : [];

    $items_map = [];
    foreach ($hashes as $word_id => $info) {
        $hash = (string) ($info['hash'] ?? '');
        if ($hash === '') {
            continue;
        }
        if (!isset($items_map[$hash])) {
            $items_map[$hash] = [
                'image_hash' => $hash,
                'groups' => [],
            ];
        }
        $labels = isset($group_map[$word_id]) && is_array($group_map[$word_id]) ? $group_map[$word_id] : [];
        foreach ($labels as $label) {
            $label = trim((string) $label);
            if ($label !== '') {
                $items_map[$hash]['groups'][$label] = true;
            }
        }
    }

    $items = [];
    foreach ($items_map as $item) {
        $groups = array_keys($item['groups'] ?? []);
        sort($groups, SORT_NATURAL | SORT_FLAG_CASE);
        $items[] = [
            'image_hash' => $item['image_hash'],
            'groups' => array_values($groups),
        ];
    }

    $pairs_map = [];
    foreach ($rules['pairs'] as $pair) {
        $a = (int) ($pair[0] ?? 0);
        $b = (int) ($pair[1] ?? 0);
        if ($a <= 0 || $b <= 0 || $a === $b) {
            continue;
        }
        $hash_a = $hashes[$a]['hash'] ?? '';
        $hash_b = $hashes[$b]['hash'] ?? '';
        if ($hash_a === '' || $hash_b === '' || $hash_a === $hash_b) {
            continue;
        }
        $key = (strcmp($hash_a, $hash_b) < 0) ? ($hash_a . '|' . $hash_b) : ($hash_b . '|' . $hash_a);
        $pairs_map[$key] = [
            'image_a' => (strcmp($hash_a, $hash_b) < 0) ? $hash_a : $hash_b,
            'image_b' => (strcmp($hash_a, $hash_b) < 0) ? $hash_b : $hash_a,
        ];
    }

    $pairs = array_values($pairs_map);

    $group_names = [];
    foreach ($items as $item) {
        foreach ($item['groups'] as $label) {
            if (!in_array($label, $group_names, true)) {
                $group_names[] = $label;
            }
        }
    }

    return [
        'groups' => $group_names,
        'items' => $items,
        'pairs' => $pairs,
    ];
}

function ll_tools_word_option_rules_extract_hashes(array $data): array {
    $hashes = [];
    if (empty($data['items']) || !is_array($data['items'])) {
        return [];
    }
    foreach ($data['items'] as $item) {
        $hash = isset($item['image_hash']) ? (string) $item['image_hash'] : '';
        $hash = trim($hash);
        if ($hash === '' || !ctype_xdigit($hash)) {
            continue;
        }
        $hashes[$hash] = true;
    }
    return array_keys($hashes);
}

function ll_tools_word_option_rules_score_category_matches(array $export_hashes, int $wordset_id, ?int $threshold = null): array {
    $export_hashes = array_values(array_filter(array_map('strval', $export_hashes), function ($hash) {
        return $hash !== '';
    }));
    if (empty($export_hashes)) {
        return [];
    }

    $threshold = $threshold === null && function_exists('ll_tools_get_image_hash_threshold')
        ? ll_tools_get_image_hash_threshold()
        : (int) $threshold;
    if ($threshold < 0) {
        $threshold = 0;
    }

    $category_ids = ll_tools_word_option_rules_get_wordset_category_ids($wordset_id);
    if (empty($category_ids)) {
        return [];
    }

    $match_counts = [];
    foreach ($category_ids as $category_id) {
        $word_ids = ll_tools_word_option_rules_get_word_ids($wordset_id, (int) $category_id);
        if (empty($word_ids) || !function_exists('ll_tools_collect_word_image_hashes')) {
            $match_counts[$category_id] = 0;
            continue;
        }
        $hashes = ll_tools_collect_word_image_hashes($word_ids);
        if (empty($hashes)) {
            $match_counts[$category_id] = 0;
            continue;
        }
        $count = 0;
        foreach ($hashes as $info) {
            $hash = (string) ($info['hash'] ?? '');
            if ($hash === '') {
                continue;
            }
            foreach ($export_hashes as $export_hash) {
                if (function_exists('ll_tools_image_hash_is_similar') && ll_tools_image_hash_is_similar($hash, $export_hash, $threshold)) {
                    $count++;
                    break;
                }
            }
        }
        $match_counts[$category_id] = $count;
    }

    return $match_counts;
}

function ll_tools_word_option_rules_map_import_data(array $data, int $wordset_id, int $category_id): array {
    $items = isset($data['items']) && is_array($data['items']) ? $data['items'] : [];
    $pairs = isset($data['pairs']) && is_array($data['pairs']) ? $data['pairs'] : [];
    $threshold = isset($data['hash_threshold']) ? (int) $data['hash_threshold'] : null;
    if ($threshold === null && function_exists('ll_tools_get_image_hash_threshold')) {
        $threshold = ll_tools_get_image_hash_threshold();
    }
    if ($threshold < 0) {
        $threshold = 0;
    }

    $export_items = [];
    foreach ($items as $item) {
        $hash = isset($item['image_hash']) ? (string) $item['image_hash'] : '';
        $hash = trim($hash);
        if ($hash === '') {
            continue;
        }
        $groups = isset($item['groups']) && is_array($item['groups']) ? $item['groups'] : [];
        $clean_groups = [];
        foreach ($groups as $label) {
            $label = trim((string) $label);
            if ($label !== '') {
                $clean_groups[$label] = true;
            }
        }
        $export_items[$hash] = array_keys($clean_groups);
    }

    $export_hashes = array_keys($export_items);
    $word_ids = ll_tools_word_option_rules_get_word_ids($wordset_id, $category_id);
    $hashes = function_exists('ll_tools_collect_word_image_hashes')
        ? ll_tools_collect_word_image_hashes($word_ids)
        : [];

    $word_to_export = [];
    foreach ($hashes as $word_id => $info) {
        $hash = (string) ($info['hash'] ?? '');
        if ($hash === '') {
            continue;
        }
        $best_hash = '';
        $best_dist = $threshold + 1;
        foreach ($export_hashes as $export_hash) {
            $dist = function_exists('ll_tools_image_hash_hamming')
                ? ll_tools_image_hash_hamming($hash, $export_hash)
                : PHP_INT_MAX;
            if ($dist <= $threshold && $dist < $best_dist) {
                $best_dist = $dist;
                $best_hash = $export_hash;
            }
        }
        if ($best_hash !== '') {
            $word_to_export[$word_id] = $best_hash;
        }
    }

    $hash_to_words = [];
    foreach ($word_to_export as $word_id => $hash) {
        if (!isset($hash_to_words[$hash])) {
            $hash_to_words[$hash] = [];
        }
        $hash_to_words[$hash][] = (int) $word_id;
    }

    $groups_map = [];
    foreach ($hash_to_words as $hash => $mapped_words) {
        $labels = $export_items[$hash] ?? [];
        if (empty($labels)) {
            continue;
        }
        foreach ($labels as $label) {
            if (!isset($groups_map[$label])) {
                $groups_map[$label] = [];
            }
            foreach ($mapped_words as $word_id) {
                $groups_map[$label][$word_id] = true;
            }
        }
    }

    $groups = [];
    foreach ($groups_map as $label => $members) {
        $ids = array_keys($members);
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), function ($id) {
            return $id > 0;
        })));
        if (!empty($ids)) {
            $groups[] = [
                'label' => $label,
                'word_ids' => $ids,
            ];
        }
    }

    $pairs_map = [];
    foreach ($pairs as $pair) {
        $hash_a = isset($pair['image_a']) ? (string) $pair['image_a'] : '';
        $hash_b = isset($pair['image_b']) ? (string) $pair['image_b'] : '';
        if ($hash_a === '' || $hash_b === '' || $hash_a === $hash_b) {
            continue;
        }
        $words_a = $hash_to_words[$hash_a] ?? [];
        $words_b = $hash_to_words[$hash_b] ?? [];
        if (empty($words_a) || empty($words_b)) {
            continue;
        }
        foreach ($words_a as $word_a) {
            foreach ($words_b as $word_b) {
                $a = (int) $word_a;
                $b = (int) $word_b;
                if ($a <= 0 || $b <= 0 || $a === $b) {
                    continue;
                }
                if ($a > $b) {
                    $tmp = $a;
                    $a = $b;
                    $b = $tmp;
                }
                $pairs_map[$a . '|' . $b] = [$a, $b];
            }
        }
    }

    $pairs_out = array_values($pairs_map);

    return [
        'groups' => $groups,
        'pairs' => $pairs_out,
        'stats' => [
            'matched_words' => count($word_to_export),
            'group_count' => count($groups),
            'pair_count' => count($pairs_out),
        ],
    ];
}

function ll_render_word_option_rules_admin_page() {
    if (!current_user_can('view_ll_tools')) {
        wp_die(__('You do not have sufficient permissions to access this page.', 'll-tools-text-domain'));
    }

    $wordset_id = isset($_GET['wordset_id']) ? (int) $_GET['wordset_id'] : 0;
    $category_id = isset($_GET['category_id']) ? (int) $_GET['category_id'] : 0;

    $wordsets = get_terms([
        'taxonomy' => 'wordset',
        'hide_empty' => false,
        'orderby' => 'name',
        'order' => 'ASC',
    ]);

    $categories = get_terms([
        'taxonomy' => 'word-category',
        'hide_empty' => false,
        'orderby' => 'name',
        'order' => 'ASC',
    ]);

    $wordset_term = $wordset_id ? get_term($wordset_id, 'wordset') : null;
    $category_term = $category_id ? get_term($category_id, 'word-category') : null;
    $has_selection = $wordset_term && !is_wp_error($wordset_term) && $category_term && !is_wp_error($category_term);

    echo '<div class="wrap ll-tools-word-options">';
    echo '<h1>' . esc_html__('Word Option Pairing', 'll-tools-text-domain') . '</h1>';
    echo '<p class="description">' . esc_html__('Group words that should appear together as quiz options, and block pairs that should never be wrong answers for each other.', 'll-tools-text-domain') . '</p>';

    if (!empty($_GET['ll_word_options_updated'])) {
        echo '<div class="notice notice-success"><p>' . esc_html__('Word option rules saved.', 'll-tools-text-domain') . '</p></div>';
    } elseif (!empty($_GET['ll_word_options_error'])) {
        echo '<div class="notice notice-error"><p>' . esc_html__('Unable to save word option rules. Please check your selections.', 'll-tools-text-domain') . '</p></div>';
    }

    $import_result = get_transient('ll_word_options_import_result');
    if ($import_result !== false) {
        delete_transient('ll_word_options_import_result');
        $is_success = !empty($import_result['ok']);
        $notice_class = $is_success ? 'notice-success' : 'notice-error';
        echo '<div class="notice ' . esc_attr($notice_class) . '"><p>' . esc_html($import_result['message'] ?? '') . '</p></div>';
    }

    if (!empty($_GET['ll_word_options_import_error'])) {
        $error_code = sanitize_text_field($_GET['ll_word_options_import_error']);
        $error_messages = [
            'missing_file' => __('Select a file to import.', 'll-tools-text-domain'),
            'read_failed' => __('Unable to read the import file.', 'll-tools-text-domain'),
            'invalid_json' => __('Import file is not valid JSON.', 'll-tools-text-domain'),
            'no_hashes' => __('Import file does not contain any image hashes.', 'll-tools-text-domain'),
            'missing_wordset' => __('Select a word set to apply the import to.', 'll-tools-text-domain'),
            'no_categories' => __('No categories found for the selected word set.', 'll-tools-text-domain'),
        ];
        $msg = $error_messages[$error_code] ?? __('Unable to import word option settings.', 'll-tools-text-domain');
        echo '<div class="notice notice-error"><p>' . esc_html($msg) . '</p></div>';
    }

    echo '<form method="get" action="' . esc_url(admin_url('tools.php')) . '" class="ll-tools-word-options-filter">';
    echo '<input type="hidden" name="page" value="ll-word-option-rules" />';
    echo '<div class="ll-tools-word-options-field">';
    echo '<label for="ll-word-option-wordset">' . esc_html__('Word set', 'll-tools-text-domain') . '</label>';
    echo '<select id="ll-word-option-wordset" name="wordset_id">';
    echo '<option value="">' . esc_html__('Select a word set', 'll-tools-text-domain') . '</option>';
    if (!empty($wordsets) && !is_wp_error($wordsets)) {
        foreach ($wordsets as $wordset) {
            echo '<option value="' . esc_attr($wordset->term_id) . '"' . selected($wordset_id, (int) $wordset->term_id, false) . '>' . esc_html($wordset->name) . '</option>';
        }
    }
    echo '</select>';
    echo '</div>';

    echo '<div class="ll-tools-word-options-field">';
    echo '<label for="ll-word-option-category">' . esc_html__('Category', 'll-tools-text-domain') . '</label>';
    echo '<select id="ll-word-option-category" name="category_id">';
    echo '<option value="">' . esc_html__('Select a category', 'll-tools-text-domain') . '</option>';
    if (!empty($categories) && !is_wp_error($categories)) {
        foreach ($categories as $category) {
            echo '<option value="' . esc_attr($category->term_id) . '"' . selected($category_id, (int) $category->term_id, false) . '>' . esc_html($category->name) . '</option>';
        }
    }
    echo '</select>';
    echo '</div>';

    echo '<button type="submit" class="button button-secondary ll-tools-button">' . esc_html__('Load words', 'll-tools-text-domain') . '</button>';
    echo '</form>';

    if (!$has_selection) {
        echo '<p class="description ll-tools-word-options-hint">' . esc_html__('Select a word set and category to manage word option rules.', 'll-tools-text-domain') . '</p>';
        echo '</div>';
        return;
    }

    $words = ll_tools_word_option_rules_get_word_posts($wordset_id, $category_id);
    if (empty($words)) {
        echo '<p class="description ll-tools-word-options-hint">' . esc_html__('No words found for this word set and category.', 'll-tools-text-domain') . '</p>';
        echo '</div>';
        return;
    }

    $maps = function_exists('ll_tools_get_word_option_maps')
        ? ll_tools_get_word_option_maps($wordset_id, $category_id)
        : ['groups' => [], 'pairs' => [], 'group_map' => [], 'blocked_map' => []];
    $group_map = $maps['group_map'] ?? [];
    $pair_list = $maps['pairs'] ?? [];

    $group_labels = [];
    foreach ($maps['groups'] ?? [] as $group) {
        $label = trim((string) ($group['label'] ?? ''));
        if ($label === '') {
            continue;
        }
        if (!in_array($label, $group_labels, true)) {
            $group_labels[] = $label;
        }
    }
    $group_ids = [];
    foreach ($group_labels as $idx => $label) {
        $group_ids[] = 'g' . $idx;
    }

    $word_label_map = [];
    foreach ($words as $word) {
        $word_id = (int) $word->ID;
        if ($word_id <= 0) {
            continue;
        }
        $word_label_map[$word_id] = ll_tools_word_option_rules_get_word_label($word_id);
    }
    $word_ids = array_keys($word_label_map);
    $manual_pairs = [];
    if (!empty($pair_list)) {
        foreach ($pair_list as $pair) {
            $a = (int) ($pair[0] ?? 0);
            $b = (int) ($pair[1] ?? 0);
            if ($a <= 0 || $b <= 0 || $a === $b) {
                continue;
            }
            if ($a > $b) {
                $tmp = $a;
                $a = $b;
                $b = $tmp;
            }
            $manual_pairs[$a . '|' . $b] = ['a' => $a, 'b' => $b];
        }
    }
    $similar_pairs = ll_tools_word_option_rules_get_similar_pair_map($word_ids);
    $image_pairs = ll_tools_word_option_rules_get_image_pair_map($word_ids);

    $blocked_pairs = [];
    foreach ($manual_pairs as $key => $pair) {
        if (!isset($blocked_pairs[$key])) {
            $blocked_pairs[$key] = [
                'a' => (int) $pair['a'],
                'b' => (int) $pair['b'],
                'reasons' => [],
            ];
        }
        $blocked_pairs[$key]['reasons']['manual'] = true;
    }
    foreach ($similar_pairs as $key => $pair) {
        $a = (int) ($pair['a'] ?? 0);
        $b = (int) ($pair['b'] ?? 0);
        if ($a <= 0 || $b <= 0) {
            continue;
        }
        if (!isset($blocked_pairs[$key])) {
            $blocked_pairs[$key] = [
                'a' => $a,
                'b' => $b,
                'reasons' => [],
            ];
        }
        $blocked_pairs[$key]['reasons']['similar'] = true;
    }
    foreach ($image_pairs as $key => $pair) {
        $a = (int) ($pair['a'] ?? 0);
        $b = (int) ($pair['b'] ?? 0);
        if ($a <= 0 || $b <= 0) {
            continue;
        }
        if (!isset($blocked_pairs[$key])) {
            $blocked_pairs[$key] = [
                'a' => $a,
                'b' => $b,
                'reasons' => [],
            ];
        }
        $blocked_pairs[$key]['reasons']['same_image'] = true;
    }

    $blocked_pairs_list = array_values($blocked_pairs);
    if (!empty($blocked_pairs_list)) {
        usort($blocked_pairs_list, function ($left, $right) use ($word_label_map) {
            $left_a = (int) ($left['a'] ?? 0);
            $left_b = (int) ($left['b'] ?? 0);
            $right_a = (int) ($right['a'] ?? 0);
            $right_b = (int) ($right['b'] ?? 0);
            $left_label = $word_label_map[$left_a] ?? ('#' . $left_a);
            $right_label = $word_label_map[$right_a] ?? ('#' . $right_a);
            $cmp = strnatcasecmp($left_label, $right_label);
            if ($cmp !== 0) {
                return $cmp;
            }
            $left_label_b = $word_label_map[$left_b] ?? ('#' . $left_b);
            $right_label_b = $word_label_map[$right_b] ?? ('#' . $right_b);
            return strnatcasecmp($left_label_b, $right_label_b);
        });
    }

    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="ll-tools-word-options-form">';
    wp_nonce_field('ll_word_option_rules_save');
    echo '<input type="hidden" name="action" value="ll_tools_save_word_option_rules" />';
    echo '<input type="hidden" name="wordset_id" value="' . esc_attr($wordset_id) . '" />';
    echo '<input type="hidden" name="category_id" value="' . esc_attr($category_id) . '" />';

    echo '<h2>' . esc_html__('Groups of words that go together', 'll-tools-text-domain') . '</h2>';
    echo '<p class="description">' . esc_html__('Use the same label for words that should be used together for wrong answers. Leave blank to keep a word ungrouped.', 'll-tools-text-domain') . '</p>';
    echo '<p class="description">' . esc_html__('Groups are ordered alphabetically by label in the word grid. Prefix labels with numbers to force an order.', 'll-tools-text-domain') . '</p>';

    echo '<div class="ll-tools-word-options-group-editor">';
    echo '<h3>' . esc_html__('Group names', 'll-tools-text-domain') . '</h3>';
    echo '<p class="description">' . esc_html__('Add one or more group names, then use the checkboxes below to assign words to multiple groups.', 'll-tools-text-domain') . '</p>';
    echo '<div class="ll-tools-word-options-group-list" data-ll-group-list data-next-index="' . esc_attr(count($group_labels)) . '">';
    if (!empty($group_labels)) {
        foreach ($group_labels as $idx => $label) {
            $group_id = $group_ids[$idx] ?? ('g' . $idx);
            echo '<div class="ll-tools-word-options-group-row" data-group-id="' . esc_attr($group_id) . '">';
            echo '<input type="text" class="ll-tools-word-options-group-input" name="group_names[' . esc_attr($group_id) . ']" value="' . esc_attr($label) . '" data-group-name-input />';
            echo '<button type="button" class="button button-secondary ll-tools-button ll-tools-word-options-remove-group" data-group-remove>' . esc_html__('Remove', 'll-tools-text-domain') . '</button>';
            echo '</div>';
        }
    }
    echo '</div>';
    echo '<button type="button" class="button button-secondary ll-tools-button ll-tools-word-options-add-group" data-group-add>' . esc_html__('Add group', 'll-tools-text-domain') . '</button>';
    echo '</div>';

    $audio_by_word = function_exists('ll_tools_word_grid_collect_audio_files')
        ? ll_tools_word_grid_collect_audio_files($word_ids, false)
        : [];

    echo '<table class="widefat striped ll-tools-word-options-table" data-ll-group-table>';
    echo '<thead><tr>';
    echo '<th scope="col">' . esc_html__('Image', 'll-tools-text-domain') . '</th>';
    echo '<th scope="col">' . esc_html__('Audio', 'll-tools-text-domain') . '</th>';
    echo '<th scope="col">' . esc_html__('Word', 'll-tools-text-domain') . '</th>';
    foreach ($group_labels as $idx => $label) {
        $group_id = $group_ids[$idx] ?? ('g' . $idx);
        echo '<th scope="col" data-group-id="' . esc_attr($group_id) . '"><span class="ll-tools-word-options-group-header" data-group-header="' . esc_attr($group_id) . '">' . esc_html($label) . '</span></th>';
    }
    echo '</tr></thead>';
    echo '<tbody>';
    foreach ($words as $word) {
        $word_id = (int) $word->ID;
        if ($word_id <= 0) {
            continue;
        }
        $display_label = $word_label_map[$word_id] ?? get_the_title($word_id);
        $word_groups = isset($group_map[$word_id]) && is_array($group_map[$word_id]) ? $group_map[$word_id] : [];
        echo '<tr data-word-id="' . esc_attr($word_id) . '">';
        $thumb_html = '';
        $thumb_id = get_post_thumbnail_id($word_id);
        if ($thumb_id) {
            $thumb_html = get_the_post_thumbnail($word_id, 'thumbnail', [
                'class' => 'll-tools-word-options-thumb',
                'alt' => '',
                'loading' => 'lazy',
            ]);
        }
        if ($thumb_html === '') {
            $thumb_html = '<span class="ll-tools-word-options-thumb-placeholder">' . esc_html__('No image', 'll-tools-text-domain') . '</span>';
        }

        $audio_entry = [];
        if (!empty($audio_by_word[$word_id])) {
            $audio_entry = ll_tools_word_option_rules_get_audio_entry($audio_by_word[$word_id]);
        }
        $audio_url = isset($audio_entry['url']) ? (string) $audio_entry['url'] : '';
        $audio_type_raw = isset($audio_entry['recording_type']) ? (string) $audio_entry['recording_type'] : '';
        if ($audio_url === '' && function_exists('ll_get_word_audio_url')) {
            $audio_url = (string) ll_get_word_audio_url($word_id);
            $audio_type_raw = $audio_type_raw ?: 'isolation';
        }
        $audio_type_slug = $audio_type_raw !== '' ? sanitize_key($audio_type_raw) : '';
        $icon_type = in_array($audio_type_slug, ['question', 'isolation', 'introduction'], true)
            ? $audio_type_slug
            : 'isolation';
        $label_type = $audio_type_raw !== '' ? ucwords(str_replace('-', ' ', $audio_type_raw)) : __('Audio', 'll-tools-text-domain');
        $play_label = sprintf(__('Play %s recording', 'll-tools-text-domain'), $label_type);

        echo '<td class="ll-tools-word-options-media">' . $thumb_html . '</td>';
        echo '<td class="ll-tools-word-options-media">';
        if ($audio_url !== '') {
            echo '<button type="button" class="ll-study-recording-btn ll-study-recording-btn--' . esc_attr($icon_type) . '" data-audio-url="' . esc_url($audio_url) . '" data-recording-type="' . esc_attr($audio_type_slug) . '" aria-label="' . esc_attr($play_label) . '" title="' . esc_attr($play_label) . '">';
            echo '<span class="ll-study-recording-icon" aria-hidden="true"></span>';
            echo '<span class="ll-study-recording-visualizer" aria-hidden="true">';
            for ($i = 0; $i < 4; $i++) {
                echo '<span class="bar"></span>';
            }
            echo '</span>';
            echo '</button>';
        } else {
            echo '<span class="ll-tools-word-options-audio-missing">' . esc_html__('No audio', 'll-tools-text-domain') . '</span>';
        }
        echo '</td>';

        echo '<td class="ll-tools-word-options-word">';
        echo '<span class="ll-tools-word-options-word-title">' . esc_html($display_label) . '</span>';
        echo '<span class="ll-tools-word-options-word-id">#' . esc_html($word_id) . '</span>';
        echo '</td>';
        foreach ($group_labels as $idx => $label) {
            $group_id = $group_ids[$idx] ?? ('g' . $idx);
            $is_checked = in_array($label, $word_groups, true);
            $checkbox_label = sprintf(__('Assign to group %s', 'll-tools-text-domain'), $label);
            echo '<td class="ll-tools-word-options-group-cell" data-group-id="' . esc_attr($group_id) . '">';
            echo '<label class="ll-tools-word-options-group-check">';
            echo '<input type="checkbox" name="group_members[' . esc_attr($group_id) . '][]" value="' . esc_attr($word_id) . '" ' . checked($is_checked, true, false) . ' aria-label="' . esc_attr($checkbox_label) . '" />';
            echo '</label>';
            echo '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody>';
    echo '</table>';

    echo '<p class="ll-tools-word-options-actions">';
    echo '<button type="submit" class="button button-primary ll-tools-button" name="save_groups" value="1">' . esc_html__('Save groups', 'll-tools-text-domain') . '</button>';
    echo '</p>';

    echo '<h2>' . esc_html__('Blocked Pairs', 'll-tools-text-domain') . '</h2>';
    echo '<p class="description">' . esc_html__('Blocked pairs will never appear as wrong answers for each other.', 'll-tools-text-domain') . '</p>';
    echo '<p class="description">' . esc_html__('Pairs with the same image are locked and cannot be removed.', 'll-tools-text-domain') . '</p>';

    echo '<div class="ll-tools-word-options-pair-add">';
    echo '<div class="ll-tools-word-options-field">';
    echo '<label for="ll-word-option-pair-a">' . esc_html__('Word A', 'll-tools-text-domain') . '</label>';
    echo '<select id="ll-word-option-pair-a" name="pair_a">';
    echo '<option value="">' . esc_html__('Select a word', 'll-tools-text-domain') . '</option>';
    foreach ($words as $word) {
        $word_id = (int) $word->ID;
        if ($word_id <= 0) {
            continue;
        }
        $label = $word_label_map[$word_id] ?? get_the_title($word_id);
        echo '<option value="' . esc_attr($word_id) . '">' . esc_html($label) . '</option>';
    }
    echo '</select>';
    echo '</div>';

    echo '<div class="ll-tools-word-options-field">';
    echo '<label for="ll-word-option-pair-b">' . esc_html__('Word B', 'll-tools-text-domain') . '</label>';
    echo '<select id="ll-word-option-pair-b" name="pair_b">';
    echo '<option value="">' . esc_html__('Select a word', 'll-tools-text-domain') . '</option>';
    foreach ($words as $word) {
        $word_id = (int) $word->ID;
        if ($word_id <= 0) {
            continue;
        }
        $label = $word_label_map[$word_id] ?? get_the_title($word_id);
        echo '<option value="' . esc_attr($word_id) . '">' . esc_html($label) . '</option>';
    }
    echo '</select>';
    echo '</div>';

    echo '<button type="submit" class="button button-secondary ll-tools-button" name="add_pair" value="1">' . esc_html__('Add pair', 'll-tools-text-domain') . '</button>';
    echo '</div>';

    if (!empty($blocked_pairs_list)) {
        $reason_labels = [
            'manual' => __('Manual pair', 'll-tools-text-domain'),
            'similar' => __('Similar word', 'll-tools-text-domain'),
            'same_image' => __('Same image', 'll-tools-text-domain'),
        ];
        $reason_order = ['manual', 'similar', 'same_image'];

        echo '<table class="widefat striped ll-tools-word-options-table ll-tools-word-options-pair-table">';
        echo '<thead><tr>';
        echo '<th scope="col">' . esc_html__('Remove', 'll-tools-text-domain') . '</th>';
        echo '<th scope="col">' . esc_html__('Pair', 'll-tools-text-domain') . '</th>';
        echo '<th scope="col">' . esc_html__('Reason', 'll-tools-text-domain') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';
        foreach ($blocked_pairs_list as $pair) {
            $a = (int) ($pair['a'] ?? 0);
            $b = (int) ($pair['b'] ?? 0);
            if ($a <= 0 || $b <= 0) {
                continue;
            }
            $label_a = $word_label_map[$a] ?? ('#' . $a);
            $label_b = $word_label_map[$b] ?? ('#' . $b);
            $value = $a . '|' . $b;
            $reasons = is_array($pair['reasons'] ?? null) ? $pair['reasons'] : [];
            $is_locked = isset($reasons['same_image']);
            $remove_label = sprintf(__('Remove pair: %s', 'll-tools-text-domain'), $label_a . ' / ' . $label_b);
            $reason_bits = [];
            foreach ($reason_order as $reason_key) {
                if (!isset($reasons[$reason_key])) {
                    continue;
                }
                $label = $reason_labels[$reason_key] ?? $reason_key;
                $reason_bits[] = '<span class="ll-tools-word-options-reason ll-tools-word-options-reason--' . esc_attr($reason_key) . '">' . esc_html($label) . '</span>';
            }
            $reason_html = !empty($reason_bits)
                ? '<div class="ll-tools-word-options-reasons">' . implode(' ', $reason_bits) . '</div>'
                : '<span class="ll-tools-word-options-reason ll-tools-word-options-reason--unknown">' . esc_html__('Unknown', 'll-tools-text-domain') . '</span>';

            echo '<tr>';
            echo '<td>';
            if ($is_locked) {
                echo '<span class="ll-tools-word-options-locked">' . esc_html__('Locked', 'll-tools-text-domain') . '</span>';
            } else {
                echo '<button type="submit" class="ll-tools-word-options-remove-pair" name="remove_pair" value="' . esc_attr($value) . '" aria-label="' . esc_attr($remove_label) . '" title="' . esc_attr($remove_label) . '">x</button>';
            }
            echo '</td>';
            echo '<td>' . esc_html($label_a . ' / ' . $label_b) . '</td>';
            echo '<td>' . $reason_html . '</td>';
            echo '</tr>';
        }
        echo '</tbody>';
        echo '</table>';
        echo '<p class="description">' . esc_html__('Click the x to remove a pair.', 'll-tools-text-domain') . '</p>';
    } else {
        echo '<p class="description">' . esc_html__('No blocked pairs yet.', 'll-tools-text-domain') . '</p>';
    }

    echo '</form>';

    echo '<hr>';
    echo '<div class="ll-tools-word-options-import-export">';
    echo '<h2>' . esc_html__('Import / Export', 'll-tools-text-domain') . '</h2>';

    echo '<div class="ll-tools-word-options-export">';
    echo '<h3>' . esc_html__('Export', 'll-tools-text-domain') . '</h3>';
    echo '<p class="description">' . esc_html__('Download group and pair settings for this category as JSON, mapped to image hashes.', 'll-tools-text-domain') . '</p>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field('ll_tools_export_word_options');
    echo '<input type="hidden" name="action" value="ll_tools_export_word_options" />';
    echo '<input type="hidden" name="wordset_id" value="' . esc_attr($wordset_id) . '" />';
    echo '<input type="hidden" name="category_id" value="' . esc_attr($category_id) . '" />';
    echo '<button type="submit" class="button button-secondary ll-tools-button">' . esc_html__('Download JSON', 'll-tools-text-domain') . '</button>';
    echo '</form>';
    echo '</div>';

    echo '<div class="ll-tools-word-options-import">';
    echo '<h3>' . esc_html__('Import', 'll-tools-text-domain') . '</h3>';
    echo '<p class="description">' . esc_html__('Upload an exported JSON file and select the word set to apply it to. You will confirm the category on the next step.', 'll-tools-text-domain') . '</p>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" enctype="multipart/form-data">';
    wp_nonce_field('ll_tools_prepare_word_options_import');
    echo '<input type="hidden" name="action" value="ll_tools_prepare_word_options_import" />';
    echo '<div class="ll-tools-word-options-field">';
    echo '<label for="ll-word-options-import-file">' . esc_html__('Import file', 'll-tools-text-domain') . '</label>';
    echo '<input type="file" id="ll-word-options-import-file" name="ll_word_options_import_file" accept=".json,application/json" />';
    echo '</div>';
    echo '<div class="ll-tools-word-options-field">';
    echo '<label for="ll-word-options-import-wordset">' . esc_html__('Apply to word set', 'll-tools-text-domain') . '</label>';
    echo '<select id="ll-word-options-import-wordset" name="wordset_id">';
    echo '<option value="">' . esc_html__('Select a word set', 'll-tools-text-domain') . '</option>';
    if (!empty($wordsets) && !is_wp_error($wordsets)) {
        foreach ($wordsets as $wordset) {
            echo '<option value="' . esc_attr($wordset->term_id) . '"' . selected($wordset_id, (int) $wordset->term_id, false) . '>' . esc_html($wordset->name) . '</option>';
        }
    }
    echo '</select>';
    echo '</div>';
    echo '<button type="submit" class="button button-secondary ll-tools-button">' . esc_html__('Continue', 'll-tools-text-domain') . '</button>';
    echo '</form>';
    echo '</div>';

    $import_token = isset($_GET['ll_word_options_import_token']) ? sanitize_text_field($_GET['ll_word_options_import_token']) : '';
    if ($import_token !== '') {
        $import_payload = get_transient('ll_word_options_import_' . $import_token);
        if (is_array($import_payload) && !empty($import_payload['data'])) {
            $match_counts = isset($import_payload['match_counts']) && is_array($import_payload['match_counts'])
                ? $import_payload['match_counts']
                : [];
            $suggested_id = (int) ($import_payload['suggested_id'] ?? 0);
            $export_total = (int) ($import_payload['export_hash_count'] ?? 0);
            echo '<div class="ll-tools-word-options-import-confirm">';
            echo '<h3>' . esc_html__('Confirm Import Target', 'll-tools-text-domain') . '</h3>';
            $source = $import_payload['data']['source'] ?? [];
            if (!empty($source['category_name'])) {
                echo '<p class="description">' . esc_html(sprintf(__('Exported from category: %s', 'll-tools-text-domain'), $source['category_name'])) . '</p>';
            }
            echo '<p class="description">' . esc_html__('Select the category to apply these settings to. The suggested category has the most matching images.', 'll-tools-text-domain') . '</p>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('ll_tools_apply_word_options_import');
            echo '<input type="hidden" name="action" value="ll_tools_apply_word_options_import" />';
            echo '<input type="hidden" name="import_token" value="' . esc_attr($import_token) . '" />';
            echo '<div class="ll-tools-word-options-field">';
            echo '<label for="ll-word-options-import-category">' . esc_html__('Apply to category', 'll-tools-text-domain') . '</label>';
            echo '<select id="ll-word-options-import-category" name="category_id">';
            foreach ($match_counts as $cat_id => $count) {
                $term = get_term((int) $cat_id, 'word-category');
                if (!$term || is_wp_error($term)) {
                    continue;
                }
                $label = $term->name;
                if ($export_total > 0) {
                    $label .= sprintf(' (%d/%d)', (int) $count, $export_total);
                }
                echo '<option value="' . esc_attr($term->term_id) . '"' . selected($suggested_id, (int) $term->term_id, false) . '>' . esc_html($label) . '</option>';
            }
            echo '</select>';
            echo '</div>';
            echo '<button type="submit" class="button button-primary ll-tools-button">' . esc_html__('Apply Import', 'll-tools-text-domain') . '</button>';
            echo '</form>';
            echo '</div>';
        } else {
            echo '<div class="notice notice-error"><p>' . esc_html__('Import session expired. Please upload the file again.', 'll-tools-text-domain') . '</p></div>';
        }
    }

    echo '</div>';
    echo '</div>';
}

function ll_tools_handle_word_option_rules_save() {
    if (!current_user_can('view_ll_tools')) {
        wp_die(__('Permission denied.', 'll-tools-text-domain'));
    }

    check_admin_referer('ll_word_option_rules_save');

    $wordset_id = isset($_POST['wordset_id']) ? (int) $_POST['wordset_id'] : 0;
    $category_id = isset($_POST['category_id']) ? (int) $_POST['category_id'] : 0;

    $redirect = [
        'page' => 'll-word-option-rules',
        'wordset_id' => $wordset_id,
        'category_id' => $category_id,
    ];

    if ($wordset_id <= 0 || $category_id <= 0) {
        $redirect['ll_word_options_error'] = 1;
        wp_safe_redirect(add_query_arg($redirect, admin_url('tools.php')));
        exit;
    }

    $word_ids = ll_tools_word_option_rules_get_word_ids($wordset_id, $category_id);
    $word_lookup = array_fill_keys($word_ids, true);

    $groups = [];
    $raw_group_names = isset($_POST['group_names']) && is_array($_POST['group_names']) ? $_POST['group_names'] : [];
    $raw_group_members = isset($_POST['group_members']) && is_array($_POST['group_members']) ? $_POST['group_members'] : [];

    if (!empty($raw_group_names)) {
        $groups_map = [];
        $label_order = [];
        foreach ($raw_group_names as $group_id => $label) {
            $group_id = sanitize_text_field(wp_unslash($group_id));
            $label = sanitize_text_field(wp_unslash($label));
            $label = trim($label);
            if ($label === '') {
                continue;
            }
            if (!isset($groups_map[$label])) {
                $groups_map[$label] = [];
                $label_order[] = $label;
            }
            $members = isset($raw_group_members[$group_id]) && is_array($raw_group_members[$group_id])
                ? $raw_group_members[$group_id]
                : [];
            foreach ($members as $member_id) {
                $member_id = (int) $member_id;
                if ($member_id <= 0 || !isset($word_lookup[$member_id])) {
                    continue;
                }
                $groups_map[$label][$member_id] = true;
            }
        }

        foreach ($label_order as $label) {
            $ids_in_group = array_keys($groups_map[$label] ?? []);
            $ids_in_group = array_values(array_unique(array_filter(array_map('intval', $ids_in_group), function ($id) {
                return $id > 0;
            })));
            if (!empty($ids_in_group)) {
                $groups[] = [
                    'label' => $label,
                    'word_ids' => $ids_in_group,
                ];
            }
        }
    } else {
        $labels_by_word = [];
        $raw_labels = isset($_POST['group_label']) && is_array($_POST['group_label']) ? $_POST['group_label'] : [];
        foreach ($raw_labels as $word_id => $label) {
            $word_id = (int) $word_id;
            if ($word_id <= 0 || !isset($word_lookup[$word_id])) {
                continue;
            }
            $label = sanitize_text_field(wp_unslash($label));
            $label = trim($label);
            if ($label === '') {
                continue;
            }
            $labels_by_word[$word_id] = $label;
        }

        $groups_map = [];
        foreach ($labels_by_word as $word_id => $label) {
            if (!isset($groups_map[$label])) {
                $groups_map[$label] = [];
            }
            $groups_map[$label][] = $word_id;
        }

        $labels = array_keys($groups_map);
        if (!empty($labels)) {
            usort($labels, 'strnatcasecmp');
        }

        foreach ($labels as $label) {
            $ids_in_group = [];
            foreach ($word_ids as $word_id) {
                if (isset($labels_by_word[$word_id]) && $labels_by_word[$word_id] === $label) {
                    $ids_in_group[] = $word_id;
                }
            }
            if (!empty($ids_in_group)) {
                $groups[] = [
                    'label' => $label,
                    'word_ids' => $ids_in_group,
                ];
            }
        }
    }

    $current = function_exists('ll_tools_get_word_option_rules')
        ? ll_tools_get_word_option_rules($wordset_id, $category_id)
        : ['pairs' => []];
    $pairs_map = [];
    foreach ($current['pairs'] as $pair) {
        $a = (int) ($pair[0] ?? 0);
        $b = (int) ($pair[1] ?? 0);
        if ($a <= 0 || $b <= 0 || $a === $b) {
            continue;
        }
        if (!isset($word_lookup[$a]) || !isset($word_lookup[$b])) {
            continue;
        }
        if ($a > $b) {
            $tmp = $a;
            $a = $b;
            $b = $tmp;
        }
        $pairs_map[$a . '|' . $b] = [$a, $b];
    }

    $image_pairs = ll_tools_word_option_rules_get_image_pair_map($word_ids);
    $locked_pairs = [];
    if (!empty($image_pairs)) {
        $locked_pairs = array_fill_keys(array_keys($image_pairs), true);
    }

    $remove_pairs = isset($_POST['remove_pairs']) && is_array($_POST['remove_pairs']) ? $_POST['remove_pairs'] : [];
    $single_remove = isset($_POST['remove_pair']) ? sanitize_text_field(wp_unslash($_POST['remove_pair'])) : '';
    if ($single_remove !== '') {
        $remove_pairs[] = $single_remove;
    }
    foreach ($remove_pairs as $raw_pair) {
        $raw_pair = sanitize_text_field(wp_unslash($raw_pair));
        if ($raw_pair === '') {
            continue;
        }
        $parts = array_map('intval', explode('|', $raw_pair));
        if (count($parts) < 2) {
            continue;
        }
        $a = (int) ($parts[0] ?? 0);
        $b = (int) ($parts[1] ?? 0);
        if ($a <= 0 || $b <= 0 || $a === $b) {
            continue;
        }
        if ($a > $b) {
            $tmp = $a;
            $a = $b;
            $b = $tmp;
        }
        $key = $a . '|' . $b;
        if (isset($locked_pairs[$key])) {
            continue;
        }
        unset($pairs_map[$key]);
        ll_tools_word_option_rules_clear_similar_meta_pair($a, $b);
        ll_tools_word_option_rules_clear_similar_meta_pair($b, $a);
    }

    if (!empty($_POST['add_pair'])) {
        $pair_a = isset($_POST['pair_a']) ? (int) $_POST['pair_a'] : 0;
        $pair_b = isset($_POST['pair_b']) ? (int) $_POST['pair_b'] : 0;
        if ($pair_a > 0 && $pair_b > 0 && $pair_a !== $pair_b && isset($word_lookup[$pair_a]) && isset($word_lookup[$pair_b])) {
            $a = $pair_a;
            $b = $pair_b;
            if ($a > $b) {
                $tmp = $a;
                $a = $b;
                $b = $tmp;
            }
            $pairs_map[$a . '|' . $b] = [$a, $b];
        }
    }

    $pairs = array_values($pairs_map);

    if (!function_exists('ll_tools_update_word_option_rules')) {
        $redirect['ll_word_options_error'] = 1;
        wp_safe_redirect(add_query_arg($redirect, admin_url('tools.php')));
        exit;
    }

    ll_tools_update_word_option_rules($wordset_id, $category_id, $groups, $pairs);

    $redirect['ll_word_options_updated'] = 1;
    wp_safe_redirect(add_query_arg($redirect, admin_url('tools.php')));
    exit;
}
add_action('admin_post_ll_tools_save_word_option_rules', 'll_tools_handle_word_option_rules_save');

function ll_tools_handle_export_word_option_rules() {
    if (!current_user_can('view_ll_tools')) {
        wp_die(__('Permission denied.', 'll-tools-text-domain'));
    }

    check_admin_referer('ll_tools_export_word_options');

    $wordset_id = isset($_POST['wordset_id']) ? (int) $_POST['wordset_id'] : 0;
    $category_id = isset($_POST['category_id']) ? (int) $_POST['category_id'] : 0;
    if ($wordset_id <= 0 || $category_id <= 0) {
        wp_die(__('Missing word set or category for export.', 'll-tools-text-domain'));
    }

    $wordset = get_term($wordset_id, 'wordset');
    $category = get_term($category_id, 'word-category');
    if (!$wordset || is_wp_error($wordset) || !$category || is_wp_error($category)) {
        wp_die(__('Invalid word set or category.', 'll-tools-text-domain'));
    }

    $payload = ll_tools_word_option_rules_collect_export_data($wordset_id, $category_id);
    $data = [
        'version' => 1,
        'hash_algo' => 'dhash',
        'hash_threshold' => function_exists('ll_tools_get_image_hash_threshold') ? ll_tools_get_image_hash_threshold() : 0,
        'exported_at' => gmdate('c'),
        'source' => [
            'wordset_id' => $wordset_id,
            'wordset_name' => $wordset->name,
            'category_id' => $category_id,
            'category_name' => $category->name,
            'category_slug' => $category->slug,
        ],
        'groups' => $payload['groups'] ?? [],
        'items' => $payload['items'] ?? [],
        'pairs' => $payload['pairs'] ?? [],
    ];

    $filename = 'll-word-options-' . sanitize_title($category->slug) . '-' . sanitize_title($wordset->slug) . '-' . gmdate('Ymd-His') . '.json';
    $json = wp_json_encode($data, JSON_PRETTY_PRINT);

    nocache_headers();
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($json));
    echo $json;
    exit;
}
add_action('admin_post_ll_tools_export_word_options', 'll_tools_handle_export_word_option_rules');

function ll_tools_handle_prepare_word_option_rules_import() {
    if (!current_user_can('view_ll_tools')) {
        wp_die(__('Permission denied.', 'll-tools-text-domain'));
    }

    check_admin_referer('ll_tools_prepare_word_options_import');

    $wordset_id = isset($_POST['wordset_id']) ? (int) $_POST['wordset_id'] : 0;
    $redirect_base = admin_url('tools.php?page=ll-word-option-rules');
    if ($wordset_id > 0) {
        $redirect_base = add_query_arg('wordset_id', $wordset_id, $redirect_base);
    }

    $file = $_FILES['ll_word_options_import_file'] ?? null;
    if (!$file || empty($file['tmp_name'])) {
        wp_safe_redirect(add_query_arg('ll_word_options_import_error', 'missing_file', $redirect_base));
        exit;
    }

    $raw = file_get_contents($file['tmp_name']);
    if ($raw === false || $raw === '') {
        wp_safe_redirect(add_query_arg('ll_word_options_import_error', 'read_failed', $redirect_base));
        exit;
    }

    $data = json_decode($raw, true);
    if (!is_array($data) || empty($data['items']) || !is_array($data['items'])) {
        wp_safe_redirect(add_query_arg('ll_word_options_import_error', 'invalid_json', $redirect_base));
        exit;
    }

    $export_hashes = ll_tools_word_option_rules_extract_hashes($data);
    if (empty($export_hashes)) {
        wp_safe_redirect(add_query_arg('ll_word_options_import_error', 'no_hashes', $redirect_base));
        exit;
    }

    if ($wordset_id <= 0) {
        wp_safe_redirect(add_query_arg('ll_word_options_import_error', 'missing_wordset', $redirect_base));
        exit;
    }

    $threshold = isset($data['hash_threshold']) ? (int) $data['hash_threshold'] : null;
    $match_counts = ll_tools_word_option_rules_score_category_matches($export_hashes, $wordset_id, $threshold);
    if (empty($match_counts)) {
        wp_safe_redirect(add_query_arg('ll_word_options_import_error', 'no_categories', $redirect_base));
        exit;
    }

    $suggested_id = 0;
    $best_count = -1;
    foreach ($match_counts as $cat_id => $count) {
        if ($count > $best_count) {
            $best_count = $count;
            $suggested_id = (int) $cat_id;
        }
    }

    $token = wp_generate_password(20, false, false);
    set_transient('ll_word_options_import_' . $token, [
        'data' => $data,
        'wordset_id' => $wordset_id,
        'match_counts' => $match_counts,
        'suggested_id' => $suggested_id,
        'export_hash_count' => count($export_hashes),
    ], HOUR_IN_SECONDS);

    $redirect = add_query_arg([
        'wordset_id' => $wordset_id,
        'category_id' => $suggested_id,
        'll_word_options_import_token' => $token,
    ], admin_url('tools.php?page=ll-word-option-rules'));
    wp_safe_redirect($redirect);
    exit;
}
add_action('admin_post_ll_tools_prepare_word_options_import', 'll_tools_handle_prepare_word_option_rules_import');

function ll_tools_handle_apply_word_option_rules_import() {
    if (!current_user_can('view_ll_tools')) {
        wp_die(__('Permission denied.', 'll-tools-text-domain'));
    }

    check_admin_referer('ll_tools_apply_word_options_import');

    $token = isset($_POST['import_token']) ? sanitize_text_field(wp_unslash($_POST['import_token'])) : '';
    $category_id = isset($_POST['category_id']) ? (int) $_POST['category_id'] : 0;
    $import = $token ? get_transient('ll_word_options_import_' . $token) : false;
    if (!$import || empty($import['data']) || empty($import['wordset_id'])) {
        wp_die(__('Import session expired. Please start again.', 'll-tools-text-domain'));
    }

    $wordset_id = (int) $import['wordset_id'];
    if ($wordset_id <= 0 || $category_id <= 0) {
        wp_die(__('Missing word set or category for import.', 'll-tools-text-domain'));
    }

    $mapped = ll_tools_word_option_rules_map_import_data($import['data'], $wordset_id, $category_id);
    if (!function_exists('ll_tools_update_word_option_rules')) {
        wp_die(__('Unable to apply import rules.', 'll-tools-text-domain'));
    }

    ll_tools_update_word_option_rules($wordset_id, $category_id, $mapped['groups'] ?? [], $mapped['pairs'] ?? []);
    delete_transient('ll_word_options_import_' . $token);

    $stats = $mapped['stats'] ?? [];
    set_transient('ll_word_options_import_result', [
        'ok' => true,
        'message' => __('Word option settings imported.', 'll-tools-text-domain'),
        'stats' => $stats,
    ], MINUTE_IN_SECONDS * 5);

    $redirect = add_query_arg([
        'wordset_id' => $wordset_id,
        'category_id' => $category_id,
        'll_word_options_imported' => 1,
    ], admin_url('tools.php?page=ll-word-option-rules'));
    wp_safe_redirect($redirect);
    exit;
}
add_action('admin_post_ll_tools_apply_word_options_import', 'll_tools_handle_apply_word_option_rules_import');
