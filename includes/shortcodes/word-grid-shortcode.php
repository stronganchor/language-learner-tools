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
        if ($include_meta) {
            $recording_text = get_post_meta($audio_post->ID, 'recording_text', true);
            $recording_translation = get_post_meta($audio_post->ID, 'recording_translation', true);
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
        $option_type = (string) ($quiz_config['option_type'] ?? '');
        $is_text_based = (strpos($option_type, 'text') === 0);
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

    wp_localize_script('ll-tools-word-grid', 'llToolsWordGridData', [
        'ajaxUrl'    => admin_url('admin-ajax.php'),
        'nonce'      => is_user_logged_in() ? wp_create_nonce('ll_user_study') : '',
        'isLoggedIn' => is_user_logged_in(),
        'canEdit'    => $can_edit_words,
        'editNonce'  => $can_edit_words ? wp_create_nonce('ll_word_grid_edit') : '',
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
    $play_label_template = __('Play %s recording', 'll-tools-text-domain');
    $edit_labels = [
        'edit_word'   => __('Edit word', 'll-tools-text-domain'),
        'word'        => __('Word', 'll-tools-text-domain'),
        'translation' => __('Translation', 'll-tools-text-domain'),
        'recordings'  => __('Recordings', 'll-tools-text-domain'),
        'text'        => __('Text', 'll-tools-text-domain'),
        'save'        => __('Save', 'll-tools-text-domain'),
        'cancel'      => __('Cancel', 'll-tools-text-domain'),
    ];
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
                $recording_caption = '';
                if ($recording_text !== '' && $recording_translation !== '') {
                    $recording_caption = $recording_text . ' (' . $recording_translation . ')';
                } elseif ($recording_text !== '') {
                    $recording_caption = $recording_text;
                } elseif ($recording_translation !== '') {
                    $recording_caption = '(' . $recording_translation . ')';
                }
                if ($recording_caption !== '') {
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
                    'text' => $recording_caption,
                    'id' => !empty($entry['id']) ? (int) $entry['id'] : 0,
                ];

                if ($can_edit_words && !empty($entry['id'])) {
                    $edit_recordings[] = [
                        'id' => (int) $entry['id'],
                        'type' => $type,
                        'label' => $label,
                        'text' => (string) ($entry['recording_text'] ?? ''),
                        'translation' => (string) ($entry['recording_translation'] ?? ''),
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
                        $recording_text_id = 'll-word-edit-recording-text-' . $recording_id;
                        $recording_translation_id = 'll-word-edit-recording-translation-' . $recording_id;
                        echo '<div class="ll-word-edit-recording" data-recording-id="' . esc_attr($recording_id) . '" data-recording-type="' . esc_attr($recording_type) . '">';
                        echo '<div class="ll-word-edit-recording-header">';
                        echo '<span class="ll-word-edit-recording-icon" aria-hidden="true"></span>';
                        echo '<span class="ll-word-edit-recording-name">' . esc_html($recording_label) . '</span>';
                        echo '</div>';
                        echo '<div class="ll-word-edit-recording-fields">';
                        echo '<label class="ll-word-edit-label" for="' . esc_attr($recording_text_id) . '">' . esc_html($edit_labels['text']) . '</label>';
                        echo '<input type="text" class="ll-word-edit-input" id="' . esc_attr($recording_text_id) . '" data-ll-recording-input="text" value="' . esc_attr($recording_text) . '" />';
                        echo '<label class="ll-word-edit-label" for="' . esc_attr($recording_translation_id) . '">' . esc_html($edit_labels['translation']) . '</label>';
                        echo '<input type="text" class="ll-word-edit-input" id="' . esc_attr($recording_translation_id) . '" data-ll-recording-input="translation" value="' . esc_attr($recording_translation) . '" />';
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
                        if (!empty($row['text'])) {
                            $recordings_html .= '<span class="ll-word-recording-text">' . esc_html($row['text']) . '</span>';
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
        $decoded = $raw;
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
        ];
    }

    return $parsed;
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

    $store_in_title = function_exists('ll_tools_should_store_word_in_title')
        ? ll_tools_should_store_word_in_title($word_id)
        : true;
    $new_title = $word_post->post_title;
    if ($store_in_title && $word_text !== '') {
        $new_title = $word_text;
    } elseif (!$store_in_title && $translation_text !== '') {
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
    }
    if ($store_in_title) {
        if ($translation_text !== '') {
            update_post_meta($word_id, 'word_translation', $translation_text);
        }
    } else {
        if ($word_text !== '') {
            update_post_meta($word_id, 'word_translation', $word_text);
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

        if ($recording_text !== '') {
            update_post_meta($recording_id, 'recording_text', $recording_text);
        }
        if ($recording_translation !== '') {
            update_post_meta($recording_id, 'recording_translation', $recording_translation);
        }

        $recordings_out[] = [
            'id' => $recording_id,
            'recording_text' => (string) get_post_meta($recording_id, 'recording_text', true),
            'recording_translation' => (string) get_post_meta($recording_id, 'recording_translation', true),
        ];
    }

    $display_values = ll_tools_word_grid_resolve_display_text($word_id);

    wp_send_json_success([
        'word_id' => $word_id,
        'word_text' => $display_values['word_text'],
        'word_translation' => $display_values['translation_text'],
        'recordings' => $recordings_out,
    ]);
}

// Register the 'word_grid' shortcode
function ll_tools_register_word_grid_shortcode() {
    add_shortcode('word_grid', 'll_tools_word_grid_shortcode');
}
add_action('init', 'll_tools_register_word_grid_shortcode');
