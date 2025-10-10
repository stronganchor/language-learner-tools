<?php
/**
 * [audio_recording_interface] - Public-facing interface for native speakers
 * to record audio for word images that don't have audio yet.
 */

if (!defined('WPINC')) { die; }

/**
 * Get translatable name for a recording type by slug
 */
function ll_get_recording_type_name($slug) {
    $names = [
        'isolation'     => __('Isolation', 'll-tools-text-domain'),
        'question'      => __('Question', 'll-tools-text-domain'),
        'introduction'  => __('Introduction', 'll-tools-text-domain'),
        'sentence'      => __('In Sentence', 'll-tools-text-domain'),
    ];

    return isset($names[$slug]) ? $names[$slug] : ucfirst($slug);
}

function ll_audio_recording_interface_shortcode($atts) {
    // Require user to be logged in
    if (!is_user_logged_in()) {
        return '<div class="ll-recording-interface"><p>' .
               __('You must be logged in to record audio.', 'll-tools-text-domain') .
               ' <a href="' . wp_login_url(get_permalink()) . '">' . __('Log in', 'll-tools-text-domain') . '</a></p></div>';
    }

    if (!ll_tools_user_can_record()) {
        return '<div class="ll-recording-interface"><p>' .
               __('You do not have permission to record audio. If you think this is a mistake, ask for the "Audio Recorder" user role to be added to your user account.', 'll-tools-text-domain') . '</p></div>';
    }

    $atts = shortcode_atts([
        'category' => '',
        'wordset'  => '',
        'language' => '',
        'include_recording_types' => '',
        'exclude_recording_types' => '',
    ], $atts);

    // Resolve wordset term IDs
    $wordset_term_ids = ll_resolve_wordset_term_ids_or_default($atts['wordset']);

    // Get available categories for the wordset
    $available_categories = ll_get_categories_for_wordset($wordset_term_ids, $atts['include_recording_types'], $atts['exclude_recording_types']);

    // If no categories available, no point showing
    if (empty($available_categories)) {
        return '<div class="ll-recording-interface"><p>' .
               __('No categories available for audio recordings at this time. Thank you!', 'll-tools-text-domain') .
               '</p></div>';
    }

    // Get images for the initial category (or first if none specified)
    $initial_category = !empty($atts['category']) && isset($available_categories[$atts['category']]) ? $atts['category'] : key($available_categories);
    $images_needing_audio = ll_get_images_needing_audio($initial_category, $wordset_term_ids, $atts['include_recording_types'], $atts['exclude_recording_types']);

    if (empty($images_needing_audio)) {
        return '<div class="ll-recording-interface"><p>' .
               __('No images need audio recordings in the selected category at this time. Thank you!', 'll-tools-text-domain') .
               '</p></div>';
    }

    ll_enqueue_recording_assets();

    // Get recording types for dropdown (based on initial images)
    $recording_types = [];
    foreach ($images_needing_audio as $img) {
        if (is_array($img['missing_types'])) {
            $recording_types = array_merge($recording_types, $img['missing_types']);
        }
        if (is_array($img['existing_types'])) {
            $recording_types = array_merge($recording_types, $img['existing_types']);
        }
    }
    $recording_types = array_unique($recording_types);
    $dropdown_types = [];
    if (!empty($recording_types)) {
        foreach ($recording_types as $slug) {
            $term = get_term_by('slug', $slug, 'recording_type');
            if ($term && !is_wp_error($term)) {
                $dropdown_types[] = $term;
            }
        }
    }

    // Get current user info for display
    $current_user = wp_get_current_user();

    wp_localize_script('ll-audio-recorder', 'll_recorder_data', [
        'ajax_url'        => admin_url('admin-ajax.php'),
        'nonce'           => wp_create_nonce('ll_upload_recording'),
        'images'          => $images_needing_audio,
        'available_categories' => $available_categories,
        'language'        => $atts['language'],
        'wordset'         => $atts['wordset'],
        'wordset_ids'     => $wordset_term_ids,
        'hide_name'       => get_option('ll_hide_recording_titles', 0),
        'recording_types' => $dropdown_types,
        'user_display_name' => $current_user->display_name,
        'require_all_types' => true,
        'initial_category' => $initial_category,
        'include_types'    => $atts['include_recording_types'],
        'exclude_types'    => $atts['exclude_recording_types'],
        'i18n' => [
            'uploading' => __('Uploading...', 'll-tools-text-domain'),
            'success' => __('Success! Recording will be processed later.', 'll-tools-text-domain'),
            'error_prefix' => __('Error:', 'll-tools-text-domain'),
            'upload_failed' => __('Upload failed:', 'll-tools-text-domain'),
            'saved_type' => __('Saved %s. Next type selected.', 'll-tools-text-domain'),
            'skipped_type' => __('Skipped this type. Next type selected.', 'll-tools-text-domain'),
            'all_complete' => __('All recordings completed for the selected set. Thank you!', 'll-tools-text-domain'),
            'category' => __('Category:', 'll-tools-text-domain'),
            'uncategorized' => __('Uncategorized', 'll-tools-text-domain'),
            'no_blob' => __('No audio blob to submit', 'll-tools-text-domain'),
            'microphone_error' => __('Error: Could not access microphone', 'll-tools-text-domain'),
            'starting_upload' => __('Starting upload for image:', 'll-tools-text-domain'),
            'http_error' => __('HTTP %d: %s', 'll-tools-text-domain'),
            'invalid_response' => __('Server returned invalid response format', 'll-tools-text-domain'),
            'switching_category' => __('Switching category...', 'll-tools-text-domain'),
        ],
    ]);

    ob_start();
    ?>
    <div class="ll-recording-interface">
        <div class="ll-recording-progress">
            <span class="ll-current-num">1</span> / <span class="ll-total-num"><?php echo count($images_needing_audio); ?></span>
        </div>

        <div class="ll-recorder-info">
            <?php
            printf(
                __('Recording as: %s', 'll-tools-text-domain'),
                '<strong>' . esc_html($current_user->display_name) . '</strong>'
            );
            ?>
        </div>

        <div class="ll-category-selector">
            <label for="ll-category-select"><?php _e('Category:', 'll-tools-text-domain'); ?></label>
            <select id="ll-category-select">
                <?php
                foreach ($available_categories as $slug => $name) {
                    $selected = ($slug === $initial_category) ? 'selected' : '';
                    printf(
                        '<option value="%s" %s>%s</option>',
                        esc_attr($slug),
                        $selected,
                        esc_html($name)
                    );
                }
                ?>
            </select>
        </div>

        <div class="ll-recording-main">
            <?php
            // Get the site-wide flashcard size setting
            $flashcard_size = get_option('ll_flashcard_image_size', 'small');
            $size_class = 'flashcard-size-' . sanitize_html_class($flashcard_size);
            ?>

            <div class="ll-recording-image-container">
                <div class="flashcard-container <?php echo esc_attr($size_class); ?>">
                    <img id="ll-current-image" class="quiz-image" src="" alt="">
                </div>
                <p id="ll-image-title" class="ll-image-title"></p>
            </div>

            <div class="ll-recording-type-selector">
                <label for="ll-recording-type"><?php _e('Recording Type:', 'll-tools-text-domain'); ?></label>
                <select id="ll-recording-type">
                    <?php
                    if (!empty($dropdown_types) && !is_wp_error($dropdown_types)) {
                        foreach ($dropdown_types as $type) {
                            $selected = ($type->slug === 'isolation' || (empty($images_needing_audio[0]['missing_types']) ? false : $type->slug === $images_needing_audio[0]['missing_types'][0])) ? 'selected' : '';
                            printf(
                                '<option value="%s" %s>%s</option>',
                                esc_attr($type->slug),
                                $selected,
                                esc_html(ll_get_recording_type_name($type->slug))
                            );
                        }
                    }
                    ?>
                </select>
            </div>

            <div class="ll-recording-controls">
                <button id="ll-record-btn" class="ll-btn ll-btn-record"
                        title="<?php esc_attr_e('Record', 'll-tools-text-domain'); ?>"></button>

                <button id="ll-skip-btn" class="ll-btn ll-btn-skip"
                        title="<?php esc_attr_e('Skip', 'll-tools-text-domain'); ?>">
                    <svg viewBox="0 0 24 24"><path d="M6 18l8.5-6L6 6v12zM16 6v12h2V6h-2z"/></svg>
                </button>

                <div id="ll-recording-indicator" class="ll-recording-indicator" style="display:none;">
                    <span class="ll-recording-dot"></span>
                    <span id="ll-recording-timer">0:00</span>
                </div>

                <div id="ll-playback-controls" style="display:none;">
                    <audio id="ll-playback-audio" controls></audio>
                    <div class="ll-playback-actions">
                        <button id="ll-redo-btn" class="ll-btn ll-btn-secondary"
                                title="<?php esc_attr_e('Record again', 'll-tools-text-domain'); ?>"></button>
                        <button id="ll-submit-btn" class="ll-btn ll-btn-primary"
                                title="<?php esc_attr_e('Save and continue', 'll-tools-text-domain'); ?>"></button>
                    </div>
                </div>

                <div id="ll-upload-status" class="ll-upload-status"></div>
            </div>
        </div>

        <div class="ll-recording-complete" style="display:none;">
            <h2>✓</h2>
            <p><span class="ll-completed-count"></span> <?php _e('recordings completed', 'll-tools-text-domain'); ?></p>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('audio_recording_interface', 'll_audio_recording_interface_shortcode');

/**
 * Get categories (slugs => names) that have images needing audio in the given wordset, with type filters applied.
 */
function ll_get_categories_for_wordset($wordset_term_ids, $include_types_csv, $exclude_types_csv) {
    $args = [
        'post_type'      => 'word_images',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
        'fields'         => 'ids',
        'meta_query'     => [
            [
                'key'     => '_thumbnail_id',
                'compare' => 'EXISTS',
            ],
        ],
    ];

    $image_posts = get_posts($args);
    $categories = [];
    $all_types = get_terms(['taxonomy' => 'recording_type', 'fields' => 'slugs']);
    if (is_wp_error($all_types)) $all_types = [];
    $include_types = !empty($include_types_csv) ? array_map('trim', explode(',', $include_types_csv)) : [];
    $exclude_types = !empty($exclude_types_csv) ? array_map('trim', explode(',', $exclude_types_csv)) : [];
    $filtered_types = $all_types;
    if (!empty($include_types)) {
        $filtered_types = array_intersect($filtered_types, $include_types);
    } elseif (!empty($exclude_types)) {
        $filtered_types = array_diff($filtered_types, $exclude_types);
    }

    foreach ($image_posts as $img_id) {
        $word_id = ll_get_word_for_image_in_wordset($img_id, $wordset_term_ids);
        // Removed the skip condition so all qualifying images are considered (words are created during recording if needed)
        $missing = $word_id ? ll_get_missing_recording_types_for_word($word_id, $filtered_types) : $filtered_types;
        if (!empty($missing)) {
            $terms = wp_get_post_terms($img_id, 'word-category');
            if (!is_wp_error($terms) && !empty($terms)) {
                foreach ($terms as $term) {
                    $categories[$term->slug] = $term->name;
                }
            }
        }
    }
    asort($categories);
    return $categories;
}

/**
 * AJAX handler to get new images for a selected category
 */
add_action('wp_ajax_ll_get_images_for_recording', 'll_get_images_for_recording_handler');
add_action('wp_ajax_nopriv_ll_get_images_for_recording', 'll_get_images_for_recording_handler');

function ll_get_images_for_recording_handler() {
    check_ajax_referer('ll_upload_recording', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error('You must be logged in');
    }

    if (!isset($_POST['category']) || !isset($_POST['wordset_ids'])) {
        wp_send_json_error('Missing parameters');
    }

    $category = sanitize_text_field($_POST['category']);
    $wordset_ids = json_decode(stripslashes($_POST['wordset_ids']), true);
    $wordset_term_ids = array_map('intval', $wordset_ids);
    $include_types = isset($_POST['include_types']) ? sanitize_text_field($_POST['include_types']) : '';
    $exclude_types = isset($_POST['exclude_types']) ? sanitize_text_field($_POST['exclude_types']) : '';

    $images = ll_get_images_needing_audio($category, $wordset_term_ids, $include_types, $exclude_types);

    if (empty($images)) {
        wp_send_json_error('No images need audio in this category');
    }

    $recording_types = [];
    foreach ($images as $img) {
        if (is_array($img['missing_types'])) {
            $recording_types = array_merge($recording_types, $img['missing_types']);
        }
        if (is_array($img['existing_types'])) {
            $recording_types = array_merge($recording_types, $img['existing_types']);
        }
    }
    $recording_types = array_unique($recording_types);
    $dropdown_types = [];
    foreach ($recording_types as $slug) {
        $term = get_term_by('slug', $slug, 'recording_type');
        if ($term) $dropdown_types[] = $term;
    }

    wp_send_json_success([
        'images' => $images,
        'recording_types' => $dropdown_types,
    ]);
}

// AJAX: verify a recording exists after a possibly misleading 500
add_action('wp_ajax_ll_verify_recording', 'll_verify_recording_handler');
add_action('wp_ajax_nopriv_ll_verify_recording', 'll_verify_recording_handler');

function ll_verify_recording_handler() {
    check_ajax_referer('ll_upload_recording', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error('You must be logged in to verify recordings');
    }

    $image_id       = intval($_POST['image_id'] ?? 0);
    $recording_type = sanitize_text_field($_POST['recording_type'] ?? '');
    $include_types  = sanitize_text_field($_POST['include_types'] ?? '');
    $exclude_types  = sanitize_text_field($_POST['exclude_types'] ?? '');
    $wordset_ids    = [];

    if (!empty($_POST['wordset_ids'])) {
        $decoded = json_decode(stripslashes((string) $_POST['wordset_ids']), true);
        if (is_array($decoded)) $wordset_ids = array_map('intval', $decoded);
    }
    $wordset_spec = sanitize_text_field($_POST['wordset'] ?? '');
    if (empty($wordset_ids)) {
        $wordset_ids = ll_resolve_wordset_term_ids_or_default($wordset_spec);
    }

    $image_post = get_post($image_id);
    if (!$image_post || $image_post->post_type !== 'word_images') {
        wp_send_json_error('Invalid image ID');
    }

    // Rebuild filtered type list just like the UI
    $all_types = get_terms(['taxonomy' => 'recording_type', 'fields' => 'slugs']);
    if (is_wp_error($all_types) || empty($all_types)) { $all_types = []; }

    $inc = $include_types ? array_map('trim', explode(',', $include_types)) : [];
    $exc = $exclude_types ? array_map('trim', explode(',', $exclude_types)) : [];

    $filtered_types = $all_types;
    if (!empty($inc))  $filtered_types = array_values(array_intersect($filtered_types, $inc));
    elseif (!empty($exc)) $filtered_types = array_values(array_diff($filtered_types, $exc));

    // Find the word
    $word_id = ll_find_or_create_word_for_image($image_id, $image_post, $wordset_ids);
    if (is_wp_error($word_id)) {
        wp_send_json_error('Failed to find/create word: ' . $word_id->get_error_message());
    }
    $word_id = (int) $word_id;

    // Look for a recent child "word_audio" with this type
    $args = [
        'post_type'      => 'word_audio',
        'post_status'    => ['draft','pending','publish'],
        'posts_per_page' => 1,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'post_parent'    => $word_id,
        'tax_query'      => [[
            'taxonomy' => 'recording_type',
            'field'    => 'slug',
            'terms'    => $recording_type ? [$recording_type] : $filtered_types,
        ]],
    ];
    $latest = get_posts($args);
    $audio_post_id = !empty($latest) ? (int) $latest[0] : 0;

    // Remaining types (computed with the same filtered list)
    $remaining_missing = ll_get_missing_recording_types_for_word($word_id, $filtered_types);

    // If this exact type was requested and it still appears in remaining, then treat as not found.
    $found = 0;
    if ($audio_post_id) {
        if ($recording_type && in_array($recording_type, $remaining_missing, true)) {
            $found = 0;
        } else {
            $found = $audio_post_id;
        }
    }

    wp_send_json_success([
        'found_audio_post_id' => $found,
        'word_id'             => $word_id,
        'remaining_types'     => array_values($remaining_missing),
    ]);
}

/**
 * Return the earliest-created wordset term_id (approximate via lowest term_id).
 */
function ll_get_default_wordset_term_id() {
    $terms = get_terms([
        'taxonomy'   => 'wordset',
        'hide_empty' => false,
        'orderby'    => 'id',   // lowest term_id first
        'order'      => 'ASC',
        'number'     => 1,
    ]);
    if (!is_wp_error($terms) && !empty($terms)) {
        return (int) $terms[0]->term_id;
    }
    return 0;
}

/**
 * Resolve explicit wordset spec to term IDs, otherwise fall back to default wordset.
 */
function ll_resolve_wordset_term_ids_or_default($wordset_spec) {
    $ids = [];
    if (!empty($wordset_spec) && function_exists('ll_raw_resolve_wordset_term_ids')) {
        $ids = ll_raw_resolve_wordset_term_ids($wordset_spec);
    }
    if (empty($ids)) {
        $default_id = ll_get_default_wordset_term_id();
        if ($default_id) {
            $ids = [$default_id];
        }
    }
    return array_map('intval', $ids);
}

/**
 * Get word images that need audio recordings for a specific wordset (by term IDs),
 * returning per-image missing/existing recording types so the UI can prompt for each type.
 *
 * @param string $category_slug
 * @param array  $wordset_term_ids
 * @param string $include_types_csv Comma-separated slugs to include
 * @param string $exclude_types_csv Comma-separated slugs to exclude
 * @return array [
 *   [
 *     'id'            => int,
 *     'title'         => string,
 *     'image_url'     => string,
 *     'category_name' => string,
 *     'word_id'       => int|null,      // the word in this wordset that uses the image (if any)
 *     'missing_types' => string[],       // recording_type slugs still needed (filtered)
 *     'existing_types'=> string[],       // recording_type slugs already present (not filtered, all)
 *   ],
 *   ...
 * ]
 */
function ll_get_images_needing_audio($category_slug = '', $wordset_term_ids = [], $include_types_csv = '', $exclude_types_csv = '') {
    // If nothing provided, fall back to default wordset so guests never see "all images"
    if (empty($wordset_term_ids)) {
        $default_id = ll_get_default_wordset_term_id();
        if ($default_id) {
            $wordset_term_ids = [$default_id];
        }
    }

    // All defined recording types (slugs)
    $all_types = get_terms([
        'taxonomy'   => 'recording_type',
        'hide_empty' => false,
        'fields'     => 'slugs',
    ]);
    if (is_wp_error($all_types) || empty($all_types)) {
        $all_types = [];
    }

    // Parse and apply filters
    $include_types = !empty($include_types_csv) ? array_map('trim', explode(',', $include_types_csv)) : [];
    $exclude_types = !empty($exclude_types_csv) ? array_map('trim', explode(',', $exclude_types_csv)) : [];

    $filtered_types = $all_types;
    if (!empty($include_types)) {
        $filtered_types = array_intersect($filtered_types, $include_types);
    } elseif (!empty($exclude_types)) {
        $filtered_types = array_diff($filtered_types, $exclude_types);
    }

    $args = [
        'post_type'      => 'word_images',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
        'fields'         => 'ids',
    ];

    if (!empty($category_slug)) {
        $args['tax_query'] = [[
            'taxonomy' => 'word-category',
            'field'    => 'slug',
            'terms'    => $category_slug,
        ]];
    }

    $image_posts = get_posts($args);
    $result = [];
    $images_by_category = [];

    foreach ($image_posts as $img_id) {
        // Find the word in THIS wordset that uses this image (if any)
        $word_id = ll_get_word_for_image_in_wordset($img_id, $wordset_term_ids);

        if ($word_id) {
            $missing_types = ll_get_missing_recording_types_for_word($word_id, $filtered_types);
        } else {
            $missing_types = $filtered_types;
        }
        $existing_types = $word_id ? ll_get_existing_recording_types_for_word($word_id) : [];

        // Only include the image if at least one type is missing
        if (!empty($missing_types)) {
            $thumb_url = get_the_post_thumbnail_url($img_id, 'large');
            if ($thumb_url) {
                $categories = wp_get_post_terms($img_id, 'word-category');
                if (!empty($categories) && !is_wp_error($categories)) {
                    $category      = $categories[0];
                    $category_name = $category->name;
                    $category_id   = $category->term_id;
                } else {
                    $category_name = 'Uncategorized';
                    $category_id   = 0;
                }

                if (!isset($images_by_category[$category_id])) {
                    $images_by_category[$category_id] = [
                        'name'   => $category_name,
                        'images' => [],
                    ];
                }

                $images_by_category[$category_id]['images'][] = [
                    'id'             => $img_id,
                    'title'          => get_the_title($img_id),
                    'image_url'      => $thumb_url,
                    'category_name'  => $category_name,
                    'word_id'        => $word_id ?: 0,
                    'missing_types'  => $missing_types,
                    'existing_types' => $existing_types,
                ];
            }
        }
    }

    uasort($images_by_category, function($a, $b) {
        return strcasecmp($a['name'], $b['name']);
    });

    foreach ($images_by_category as $category_data) {
        foreach ($category_data['images'] as $image) {
            $result[] = $image;
        }
    }

    return $result;
}

/**
 * Return the first "words" post (ID) in the given wordset(s) that uses this image.
 */
function ll_get_word_for_image_in_wordset(int $image_post_id, array $wordset_term_ids) {
    $attachment_id = get_post_thumbnail_id($image_post_id);
    if (!$attachment_id) {
        return 0;
    }

    $query_args = [
        'post_type'      => 'words',
        'post_status'    => ['publish', 'draft', 'pending'], // Include draft/pending words
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'meta_query'     => [[
            'key'   => '_thumbnail_id',
            'value' => $attachment_id,
        ]],
    ];

    if (!empty($wordset_term_ids)) {
        $query_args['tax_query'] = [[
            'taxonomy' => 'wordset',
            'field'    => 'term_id',
            'terms'    => array_map('intval', $wordset_term_ids),
        ]];
    }

    $ids = get_posts($query_args);
    return !empty($ids) ? (int) $ids[0] : 0;
}

/**
 * For a given word (parent of word_audio), return the recording_type slugs already present.
 */
function ll_get_existing_recording_types_for_word(int $word_id): array {
    $audio_posts = get_posts([
        'post_type'      => 'word_audio',
        'post_status'    => ['draft','pending','publish'], // count in-flight too
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'post_parent'    => $word_id,
        'tax_query'      => [[
            'taxonomy' => 'recording_type',
            'field'    => 'slug',
            'terms'    => [], // placeholder so WP includes the join; we’ll read terms below
            'operator' => 'NOT IN', // this keeps query valid; we’ll fetch terms via wp_get_post_terms
        ]],
    ]);

    if (empty($audio_posts)) {
        return [];
    }

    $existing = [];
    foreach ($audio_posts as $post_id) {
        $terms = wp_get_post_terms($post_id, 'recording_type', ['fields' => 'slugs']);
        if (!is_wp_error($terms) && !empty($terms)) {
            // allow only one per audio post; if multiple, merge all
            foreach ($terms as $slug) {
                $existing[] = $slug;
            }
        }
    }
    return array_values(array_unique($existing));
}

/**
 * For a given word (parent of word_audio), return the recording_type slugs missing (not recorded and not skipped), limited to provided filtered types.
 *
 * @param int $word_id
 * @param array $filtered_types Slugs available for this shortcode instance
 * @return array
 */
function ll_get_missing_recording_types_for_word(int $word_id, array $filtered_types): array {
    $existing = ll_get_existing_recording_types_for_word($word_id);
    $skipped = get_post_meta($word_id, 'll_skipped_recording_types', true);
    $skipped = is_array($skipped) ? $skipped : [];

    $missing = array_values(array_diff($filtered_types, $existing, $skipped));
    return $missing;
}

/**
 * Check if an image has audio for a specific wordset
 */
function ll_image_has_audio_for_wordset($image_post_id, $wordset_term_ids = []) {
    $attachment_id = get_post_thumbnail_id($image_post_id);
    if (!$attachment_id) {
        return false;
    }

    // Find words using this image
    $query_args = [
        'post_type' => 'words',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'meta_query' => [[
            'key' => '_thumbnail_id',
            'value' => $attachment_id,
        ]],
    ];

    // If wordset specified, filter by it
    if (!empty($wordset_term_ids)) {
        $query_args['tax_query'] = [[
            'taxonomy' => 'wordset',
            'field' => 'term_id',
            'terms' => $wordset_term_ids,
        ]];
    }

    $words = get_posts($query_args);

    if (empty($words)) {
        return false;
    }

    // Check if any have audio
    foreach ($words as $word_id) {
        $audio = get_post_meta($word_id, 'word_audio_file', true);
        if (!empty($audio)) {
            return true;
        }
    }

    return false;
}

/**
 * Check if an image already has a word post with audio (any audio, processed or not)
 */
function ll_image_has_processed_audio($image_post_id) {
    $attachment_id = get_post_thumbnail_id($image_post_id);
    if (!$attachment_id) {
        return false;
    }

    // Find words using this image
    $words = get_posts([
        'post_type' => 'words',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'meta_query' => [[
            'key' => '_thumbnail_id',
            'value' => $attachment_id,
        ]],
    ]);

    if (empty($words)) {
        return false;
    }

    // Check if any of these words have audio (processed or not)
    foreach ($words as $word_id) {
        $audio = get_post_meta($word_id, 'word_audio_file', true);
        if (!empty($audio)) {
            return true;
        }
    }

    return false;
}

/**
 * Check if an image already has a word post with audio
 */
function ll_image_has_word_with_audio($image_post_id) {
    $attachment_id = get_post_thumbnail_id($image_post_id);
    if (!$attachment_id) {
        return false;
    }

    // Find words using this image
    $words = get_posts([
        'post_type' => 'words',
        'post_status' => 'publish',
        'posts_per_page' => 1,
        'fields' => 'ids',
        'meta_query' => [[
            'key' => '_thumbnail_id',
            'value' => $attachment_id,
        ]],
    ]);

    if (empty($words)) {
        return false;
    }

    // Check if any of these words have audio
    foreach ($words as $word_id) {
        $audio = get_post_meta($word_id, 'word_audio_file', true);
        if (!empty($audio)) {
            return true;
        }
    }

    return false;
}

/**
 * Enqueue recording interface assets
 */
function ll_enqueue_recording_assets() {
    // Enqueue flashcard styles first so recording interface can use them
    wp_enqueue_style(
        'll-flashcard-style',
        plugins_url('css/flashcard-style.css', LL_TOOLS_MAIN_FILE),
        [],
        filemtime(LL_TOOLS_BASE_PATH . 'css/flashcard-style.css')
    );

    wp_enqueue_style(
        'll-recording-interface',
        plugins_url('css/recording-interface.css', LL_TOOLS_MAIN_FILE),
        ['ll-flashcard-style'],
        filemtime(LL_TOOLS_BASE_PATH . 'css/recording-interface.css')
    );

    wp_enqueue_script(
        'll-audio-recorder',
        plugins_url('js/audio-recorder.js', LL_TOOLS_MAIN_FILE),
        [],
        filemtime(LL_TOOLS_BASE_PATH . 'js/audio-recorder.js'),
        true
    );
}

/**
 * AJAX handler: Skip recording a type for a word
 */
add_action('wp_ajax_ll_skip_recording_type', 'll_skip_recording_type_handler');
add_action('wp_ajax_nopriv_ll_skip_recording_type', 'll_skip_recording_type_handler');

function ll_skip_recording_type_handler() {
    check_ajax_referer('ll_upload_recording', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error('You must be logged in to skip recordings');
    }

    $image_id      = intval($_POST['image_id'] ?? 0);
    $recording_type = sanitize_text_field($_POST['recording_type'] ?? '');

    // Wordset resolution (explicit first, then fallback)
    $posted_ids = [];
    if (isset($_POST['wordset_ids'])) {
        $decoded = json_decode(stripslashes((string) $_POST['wordset_ids']), true);
        if (is_array($decoded)) {
            $posted_ids = array_map('intval', $decoded);
        }
    }
    $wordset_spec = sanitize_text_field($_POST['wordset'] ?? '');
    if (empty($posted_ids)) {
        $posted_ids = ll_resolve_wordset_term_ids_or_default($wordset_spec);
    }

    // Include/exclude filters (used to compute the same filtered set the UI is using)
    $include_types_csv = sanitize_text_field($_POST['include_types'] ?? '');
    $exclude_types_csv = sanitize_text_field($_POST['exclude_types'] ?? '');

    $all_types = get_terms(['taxonomy' => 'recording_type', 'fields' => 'slugs']);
    if (is_wp_error($all_types) || empty($all_types)) { $all_types = []; }

    $include_types = $include_types_csv ? array_map('trim', explode(',', $include_types_csv)) : [];
    $exclude_types = $exclude_types_csv ? array_map('trim', explode(',', $exclude_types_csv)) : [];

    $filtered_types = $all_types;
    if (!empty($include_types)) {
        $filtered_types = array_values(array_intersect($filtered_types, $include_types));
    } elseif (!empty($exclude_types)) {
        $filtered_types = array_values(array_diff($filtered_types, $exclude_types));
    }

    $image_post = get_post($image_id);
    if (!$image_post || $image_post->post_type !== 'word_images') {
        wp_send_json_error('Invalid image ID');
    }

    // Find or create parent word
    $word_id = ll_find_or_create_word_for_image($image_id, $image_post, $posted_ids);
    if (is_wp_error($word_id)) {
        wp_send_json_error('Failed to find/create word post: ' . $word_id->get_error_message());
    }
    $word_id = (int) $word_id;

    // Mark this type as skipped
    $skipped = get_post_meta($word_id, 'll_skipped_recording_types', true);
    $skipped = is_array($skipped) ? $skipped : [];
    if ($recording_type && !in_array($recording_type, $skipped, true)) {
        $skipped[] = $recording_type;
        update_post_meta($word_id, 'll_skipped_recording_types', $skipped);
    }

    // Compute remaining based on the SAME filtered set the UI is using
    $remaining_missing = ll_get_missing_recording_types_for_word($word_id, $filtered_types);

    wp_send_json_success([
        'remaining_types' => array_values($remaining_missing),
    ]);
}

/**
 * AJAX handler: Upload recording and create word_audio post
 */
add_action('wp_ajax_ll_upload_recording', 'll_handle_recording_upload');
add_action('wp_ajax_nopriv_ll_upload_recording', 'll_handle_recording_upload');

function ll_handle_recording_upload() {
    check_ajax_referer('ll_upload_recording', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error('You must be logged in to upload recordings');
    }

    $current_user_id = get_current_user_id();
    if (empty($_FILES['audio']) || empty($_POST['image_id'])) {
        wp_send_json_error('Missing data');
    }

    $image_id       = intval($_POST['image_id']);
    $recording_type = sanitize_text_field($_POST['recording_type'] ?? 'isolation');

    // Wordset resolution (explicit first, then fallback)
    $posted_ids = [];
    if (isset($_POST['wordset_ids'])) {
        $decoded = json_decode(stripslashes((string) $_POST['wordset_ids']), true);
        if (is_array($decoded)) {
            $posted_ids = array_map('intval', $decoded);
        }
    }
    $wordset_spec = sanitize_text_field($_POST['wordset'] ?? '');
    if (empty($posted_ids)) {
        $posted_ids = ll_resolve_wordset_term_ids_or_default($wordset_spec);
    }

    // Include/exclude filters (used to compute the same filtered set the UI is using)
    $include_types_csv = sanitize_text_field($_POST['include_types'] ?? '');
    $exclude_types_csv = sanitize_text_field($_POST['exclude_types'] ?? '');

    $all_types = get_terms(['taxonomy' => 'recording_type', 'fields' => 'slugs']);
    if (is_wp_error($all_types) || empty($all_types)) { $all_types = []; }

    $include_types = $include_types_csv ? array_map('trim', explode(',', $include_types_csv)) : [];
    $exclude_types = $exclude_types_csv ? array_map('trim', explode(',', $exclude_types_csv)) : [];

    $filtered_types = $all_types;
    if (!empty($include_types)) {
        $filtered_types = array_values(array_intersect($filtered_types, $include_types));
    } elseif (!empty($exclude_types)) {
        $filtered_types = array_values(array_diff($filtered_types, $exclude_types));
    }

    $image_post = get_post($image_id);
    if (!$image_post || $image_post->post_type !== 'word_images') {
        error_log('Upload step: Invalid image ID');
        wp_send_json_error('Invalid image ID');
    }

    // Save file
    $file       = $_FILES['audio'];
    $upload_dir = wp_upload_dir();
    $image_title = sanitize_file_name($image_post->post_title);
    $timestamp   = time();

    $mime_type = $file['type'] ?? '';
    $extension = '.webm';
    if (strpos($mime_type, 'wav') !== false)       { $extension = '.wav'; }
    elseif (strpos($mime_type, 'mp3') !== false)   { $extension = '.mp3'; }
    elseif (strpos($mime_type, 'pcm') !== false)   { $extension = '.wav'; }

    $filename = $image_title . '_' . $timestamp . $extension;
    $filepath = trailingslashit($upload_dir['path']) . $filename;

    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        error_log('Upload step: Failed to save file to ' . $filepath);
        wp_send_json_error('Failed to save file');
    }

    $relative_path = str_replace(
        wp_normalize_path(untrailingslashit(ABSPATH)),
        '',
        wp_normalize_path($filepath)
    );

    // Find or create parent word
    $word_id = ll_find_or_create_word_for_image($image_id, $image_post, $posted_ids);
    if (is_wp_error($word_id)) {
        error_log('Upload step: Failed to find/create word post: ' . $word_id->get_error_message());
        wp_send_json_error('Failed to find/create word post: ' . $word_id->get_error_message());
    }
    $word_id = (int) $word_id;

    // Create word_audio post
    $audio_post_id = wp_insert_post([
        'post_title'  => $image_post->post_title,
        'post_type'   => 'word_audio',
        'post_status' => 'draft',
        'post_parent' => $word_id,
        'post_author' => $current_user_id,
    ]);
    if (is_wp_error($audio_post_id)) {
        error_log('Upload step: Failed to create word_audio post: ' . $audio_post_id->get_error_message());
        wp_send_json_error('Failed to create word_audio post');
    }

    // Meta + taxonomy
    update_post_meta($audio_post_id, 'audio_file_path', $relative_path);
    update_post_meta($audio_post_id, 'speaker_user_id', $current_user_id);
    update_post_meta($audio_post_id, 'recording_date', current_time('mysql'));
    update_post_meta($audio_post_id, '_ll_needs_audio_processing', '1');
    update_post_meta($audio_post_id, '_ll_needs_audio_review', '1');
    update_post_meta($audio_post_id, '_ll_raw_recording_format', $extension);

    if (!empty($recording_type)) {
        wp_set_object_terms($audio_post_id, $recording_type, 'recording_type');
    }

    // If this type had been skipped earlier, unskip it now
    $skipped = get_post_meta($word_id, 'll_skipped_recording_types', true);
    $skipped = is_array($skipped) ? $skipped : [];
    if (($key = array_search($recording_type, $skipped, true)) !== false) {
        unset($skipped[$key]);
        update_post_meta($word_id, 'll_skipped_recording_types', array_values($skipped));
    }

    // Legacy compatibility
    if (!get_post_meta($word_id, 'word_audio_file', true)) {
        update_post_meta($word_id, 'word_audio_file', $relative_path);
    }

    // Compute remaining based on same filtered set the UI is using
    $remaining_missing = ll_get_missing_recording_types_for_word($word_id, $filtered_types);

    wp_send_json_success([
        'audio_post_id'   => (int) $audio_post_id,
        'word_id'         => (int) $word_id,
        'recording_type'  => $recording_type,
        'remaining_types' => array_values($remaining_missing),
    ]);
}

/**
 * Find existing word post for an image, or create one
 */
function ll_find_or_create_word_for_image($image_id, $image_post, $wordset_ids) {
    $attachment_id = get_post_thumbnail_id($image_id);

    if (!$attachment_id) {
        return new WP_Error('no_attachment', 'Image has no attachment');
    }

    // Check if a word already exists with this image IN THE SPECIFIED WORDSET
    $query_args = [
        'post_type' => 'words',
        'post_status' => ['publish', 'draft', 'pending'],
        'posts_per_page' => 1,
        'fields' => 'ids',
        'meta_query' => [[
            'key' => '_thumbnail_id',
            'value' => $attachment_id,
        ]],
    ];

    // Filter by wordset if specified
    if (!empty($wordset_ids)) {
        $query_args['tax_query'] = [[
            'taxonomy' => 'wordset',
            'field'    => 'term_id',
            'terms'    => array_map('intval', $wordset_ids),
        ]];
    }

    $existing_words = get_posts($query_args);

    if (!empty($existing_words)) {
        return (int) $existing_words[0];
    }

    // Create new word post
    $word_id = wp_insert_post([
        'post_title'  => $image_post->post_title,
        'post_type'   => 'words',
        'post_status' => 'draft',
    ]);

    if (is_wp_error($word_id)) {
        return $word_id;
    }

    // Set the featured image
    set_post_thumbnail($word_id, $attachment_id);

    // Copy categories from image to word
    $categories = wp_get_post_terms($image_id, 'word-category', ['fields' => 'ids']);
    if (!is_wp_error($categories) && !empty($categories)) {
        wp_set_object_terms($word_id, $categories, 'word-category');
    }

    // Assign to wordset
    if (!empty($wordset_ids)) {
        wp_set_object_terms($word_id, $wordset_ids, 'wordset');
    }

    return $word_id;
}
