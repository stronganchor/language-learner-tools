<?php
// /includes/admin/word-option-rules-admin.php
if (!defined('WPINC')) { die; }

function ll_register_word_option_rules_admin_page() {
    add_submenu_page(
        'tools.php',
        'Language Learner Tools - Word Options',
        'LL Word Options',
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
    $priority = ['isolation', 'question', 'introduction', 'in sentence'];
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

    $by_image = [];
    foreach ($word_ids as $word_id) {
        $image_id = (int) get_post_thumbnail_id($word_id);
        if ($image_id <= 0) {
            continue;
        }
        if (!isset($by_image[$image_id])) {
            $by_image[$image_id] = [];
        }
        $by_image[$image_id][] = $word_id;
    }

    $pairs = [];
    foreach ($by_image as $image_id => $ids) {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), function ($id) {
            return $id > 0;
        })));
        $count = count($ids);
        if ($count < 2) {
            continue;
        }
        sort($ids, SORT_NUMERIC);
        for ($i = 0; $i < $count - 1; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $a = $ids[$i];
                $b = $ids[$j];
                $pairs[$a . '|' . $b] = [
                    'a' => $a,
                    'b' => $b,
                    'image_id' => (int) $image_id,
                ];
            }
        }
    }

    return $pairs;
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
        if ($label !== '') {
            $group_labels[$label] = true;
        }
    }
    $group_labels = array_keys($group_labels);
    if (!empty($group_labels)) {
        natcasesort($group_labels);
        $group_labels = array_values($group_labels);
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

    if (!empty($group_labels)) {
        echo '<datalist id="ll-word-group-labels">';
        foreach ($group_labels as $label) {
            echo '<option value="' . esc_attr($label) . '"></option>';
        }
        echo '</datalist>';
    }

    $audio_by_word = function_exists('ll_tools_word_grid_collect_audio_files')
        ? ll_tools_word_grid_collect_audio_files($word_ids, false)
        : [];

    echo '<table class="widefat striped ll-tools-word-options-table">';
    echo '<thead><tr>';
    echo '<th scope="col">' . esc_html__('Image', 'll-tools-text-domain') . '</th>';
    echo '<th scope="col">' . esc_html__('Audio', 'll-tools-text-domain') . '</th>';
    echo '<th scope="col">' . esc_html__('Word', 'll-tools-text-domain') . '</th>';
    echo '<th scope="col">' . esc_html__('Group label', 'll-tools-text-domain') . '</th>';
    echo '</tr></thead>';
    echo '<tbody>';
    foreach ($words as $word) {
        $word_id = (int) $word->ID;
        if ($word_id <= 0) {
            continue;
        }
        $display_label = $word_label_map[$word_id] ?? get_the_title($word_id);
        $group_label = isset($group_map[$word_id]) ? (string) $group_map[$word_id] : '';
        echo '<tr>';
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
        echo '<td>';
        echo '<input type="text" class="ll-tools-word-options-input" name="group_label[' . esc_attr($word_id) . ']" value="' . esc_attr($group_label) . '"';
        if (!empty($group_labels)) {
            echo ' list="ll-word-group-labels"';
        }
        echo ' />';
        echo '</td>';
        echo '</tr>';
    }
    echo '</tbody>';
    echo '</table>';

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

    echo '<button type="submit" class="button button-secondary ll-tools-button">' . esc_html__('Add pair', 'll-tools-text-domain') . '</button>';
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
                echo '<input type="checkbox" name="remove_pairs[]" value="' . esc_attr($value) . '" />';
            }
            echo '</td>';
            echo '<td>' . esc_html($label_a . ' / ' . $label_b) . '</td>';
            echo '<td>' . $reason_html . '</td>';
            echo '</tr>';
        }
        echo '</tbody>';
        echo '</table>';
        echo '<p class="description">' . esc_html__('Check pairs to remove them, then save.', 'll-tools-text-domain') . '</p>';
    } else {
        echo '<p class="description">' . esc_html__('No blocked pairs yet.', 'll-tools-text-domain') . '</p>';
    }

    echo '<p class="ll-tools-word-options-actions">';
    echo '<button type="submit" class="button button-primary ll-tools-button">' . esc_html__('Save changes', 'll-tools-text-domain') . '</button>';
    echo '</p>';

    echo '</form>';
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

    $groups = [];
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
