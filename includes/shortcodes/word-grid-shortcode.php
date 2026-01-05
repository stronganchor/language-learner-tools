<?php

function ll_tools_word_grid_collect_audio_files(array $word_ids): array {
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

        foreach ($recording_types as $type) {
            $type = sanitize_text_field($type);
            if ($type === '') {
                continue;
            }
            $audio_by_word[$parent_id][] = [
                'url'             => $audio_url,
                'recording_type'  => $type,
                'speaker_user_id' => $speaker_uid,
            ];
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

function ll_tools_word_grid_select_audio_url(array $audio_files, string $type, int $preferred_speaker): string {
    $fallback = '';
    foreach ($audio_files as $file) {
        if (empty($file['recording_type']) || $file['recording_type'] !== $type || empty($file['url'])) {
            continue;
        }
        if (!$fallback) {
            $fallback = $file['url'];
        }
        $speaker_uid = isset($file['speaker_user_id']) ? (int) $file['speaker_user_id'] : 0;
        if ($preferred_speaker && $speaker_uid === $preferred_speaker) {
            return $file['url'];
        }
    }
    return $fallback;
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
    ), $atts);

    // Sanitize the category attribute
    $sanitized_category = sanitize_text_field($atts['category']);
    $sanitized_wordset = sanitize_text_field($atts['wordset']);

    $category_term = null;
    $is_text_based = false;
    if ($sanitized_category !== '') {
        $category_term = get_term_by('slug', $sanitized_category, 'word-category');
    }
    if ($category_term && !is_wp_error($category_term) && function_exists('ll_tools_get_category_quiz_config')) {
        $quiz_config = ll_tools_get_category_quiz_config($category_term);
        $option_type = (string) ($quiz_config['option_type'] ?? '');
        $is_text_based = (strpos($option_type, 'text') === 0);
    }

    ll_enqueue_asset_by_timestamp('/js/word-grid.js', 'll-tools-word-grid', ['jquery'], true);

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

    wp_localize_script('ll-tools-word-grid', 'llToolsWordGridData', [
        'ajaxUrl'    => admin_url('admin-ajax.php'),
        'nonce'      => is_user_logged_in() ? wp_create_nonce('ll_user_study') : '',
        'isLoggedIn' => is_user_logged_in(),
        'state'      => $user_study_state,
        'i18n'       => [
            'starLabel'      => __('Star word', 'll-tools-text-domain'),
            'unstarLabel'    => __('Unstar word', 'll-tools-text-domain'),
            'starAllLabel'   => __('Star all', 'll-tools-text-domain'),
            'unstarAllLabel' => __('Unstar all', 'll-tools-text-domain'),
        ],
    ]);

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
    $word_ids = wp_list_pluck($query->posts, 'ID');
    $audio_by_word = ll_tools_word_grid_collect_audio_files($word_ids);
    $main_recording_types = function_exists('ll_tools_get_main_recording_types')
        ? ll_tools_get_main_recording_types()
        : ['isolation', 'question', 'introduction'];
    $recording_type_order = ['question', 'isolation', 'introduction'];
    $recording_labels = [
        'question'     => __('Question', 'll-tools-text-domain'),
        'isolation'    => __('Isolation', 'll-tools-text-domain'),
        'introduction' => __('Introduction', 'll-tools-text-domain'),
    ];
    $play_label_template = __('Play %s recording', 'll-tools-text-domain');
    $show_stars = is_user_logged_in();
    $starred_ids = array_values(array_filter(array_map('intval', (array) ($user_study_state['starred_word_ids'] ?? []))));

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
            $word_english_meaning = get_post_meta($word_id, 'word_english_meaning', true);
            $word_example_sentence = get_post_meta(get_the_ID(), 'word_example_sentence', true);
            $word_example_translation = get_post_meta(get_the_ID(), 'word_example_sentence_translation', true);

            // Individual item
            echo '<div class="word-item">';
            // Featured image with container
            if (!$is_text_based && has_post_thumbnail()) {
                echo '<div class="word-image-container">'; // Start new container
                echo get_the_post_thumbnail(get_the_ID(), 'full', array('class' => 'word-image'));
                echo '</div>'; // Close container
            }

            // Word title and meaning
            $title_text = get_the_title();
            if ($word_english_meaning !== '') {
                $title_text .= ' (' . $word_english_meaning . ')';
            }
            echo '<div class="ll-word-title-row">';
            if ($show_stars) {
                $is_starred = in_array((int) $word_id, $starred_ids, true);
                $star_label = $is_starred
                    ? __('Unstar word', 'll-tools-text-domain')
                    : __('Star word', 'll-tools-text-domain');
                echo '<button type="button" class="ll-word-star ll-word-grid-star' . ($is_starred ? ' active' : '') . '" data-word-id="' . esc_attr($word_id) . '" aria-pressed="' . ($is_starred ? 'true' : 'false') . '" aria-label="' . esc_attr($star_label) . '" title="' . esc_attr($star_label) . '"></button>';
            }
            echo '<h3 class="word-title">' . esc_html($title_text) . '</h3>';
            echo '</div>';
            // Example sentences
            if ($word_example_sentence && $word_example_translation) {
                echo '<p class="word-example">' . esc_html($word_example_sentence) . '</p>';
                echo '<p class="word-translation"><em>' . esc_html($word_example_translation) . '</em></p>';
            }
            // Audio buttons
            $audio_files = $audio_by_word[$word_id] ?? [];
            $preferred_speaker = ll_tools_word_grid_get_preferred_speaker($audio_files, $main_recording_types);
            $has_recordings = false;
            $recordings_html = '';
            foreach ($recording_type_order as $type) {
                $audio_url = ll_tools_word_grid_select_audio_url($audio_files, $type, $preferred_speaker);
                if (!$audio_url) {
                    continue;
                }
                $has_recordings = true;
                $label = $recording_labels[$type] ?? ucfirst($type);
                $play_label = sprintf($play_label_template, $label);
                $recordings_html .= '<button type="button" class="ll-study-recording-btn ll-word-grid-recording-btn ll-study-recording-btn--' . esc_attr($type) . '" data-audio-url="' . esc_url($audio_url) . '" data-recording-type="' . esc_attr($type) . '" aria-label="' . esc_attr($play_label) . '" title="' . esc_attr($play_label) . '">';
                $recordings_html .= '<span class="ll-study-recording-icon" aria-hidden="true"></span>';
                $recordings_html .= '<span class="ll-study-recording-visualizer" aria-hidden="true">';
                for ($i = 0; $i < 6; $i++) {
                    $recordings_html .= '<span class="bar"></span>';
                }
                $recordings_html .= '</span>';
                $recordings_html .= '</button>';
            }
            if ($has_recordings) {
                echo '<div class="ll-word-recordings" aria-label="' . esc_attr__('Recordings', 'll-tools-text-domain') . '">' . $recordings_html . '</div>';
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

// Register the 'word_grid' shortcode
function ll_tools_register_word_grid_shortcode() {
    add_shortcode('word_grid', 'll_tools_word_grid_shortcode');
}
add_action('init', 'll_tools_register_word_grid_shortcode');
