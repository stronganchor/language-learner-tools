<?php
/**
 * [audio_recording_interface] - Public-facing interface for native speakers
 * to record audio for word images that don't have audio yet.
 */

if (!defined('WPINC')) { die; }

/**
 * Get translatable name for a recording type by slug
 */
function ll_get_recording_type_name($slug, $term_name = '') {
    $term = get_term_by('slug', $slug, 'recording_type');
    if ($term && !is_wp_error($term)) {
        $translated = get_term_meta((int) $term->term_id, 'term_translation', true);
        if ($translated !== '') {
            return $translated;
        }
    }

    $english_defaults = [
        'isolation'     => 'Isolation',
        'question'      => 'Question',
        'introduction'  => 'Introduction',
        'sentence'      => 'In Sentence',
    ];

    $translated_defaults = [
        'isolation'     => __('Isolation', 'll-tools-text-domain'),
        'question'      => __('Question', 'll-tools-text-domain'),
        'introduction'  => __('Introduction', 'll-tools-text-domain'),
        'sentence'      => __('In Sentence', 'll-tools-text-domain'),
    ];

    if ($term_name !== '') {
        if (!empty($english_defaults[$slug]) && $term_name !== $english_defaults[$slug]) {
            return $term_name;
        }
        if (isset($translated_defaults[$slug]) && isset($english_defaults[$slug])) {
            if ($translated_defaults[$slug] !== $english_defaults[$slug]) {
                return $translated_defaults[$slug];
            }
        }
        return $term_name;
    }

    if ($term && !is_wp_error($term) && !empty($term->name)) {
        if (!empty($english_defaults[$slug]) && $term->name !== $english_defaults[$slug]) {
            return $term->name;
        }
    }

    if (isset($translated_defaults[$slug]) && isset($english_defaults[$slug])) {
        if ($translated_defaults[$slug] !== $english_defaults[$slug]) {
            return $translated_defaults[$slug];
        }
    }

    if ($term && !is_wp_error($term) && !empty($term->name)) {
        return $term->name;
    }

    return isset($translated_defaults[$slug]) ? $translated_defaults[$slug] : ucfirst($slug);
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

    // Get user-specific configuration from meta (if exists)
    $current_user_id = get_current_user_id();
    $user_config = get_user_meta($current_user_id, 'll_recording_config', true);

    // Merge user config with shortcode attributes (shortcode takes precedence if specified)
    $defaults = [];
    if (is_array($user_config)) {
        $defaults = $user_config;
    }

    $atts = shortcode_atts(array_merge([
        'category' => '',
        'wordset'  => '',
        'language' => '',
        'include_recording_types' => '',
        'exclude_recording_types' => '',
        'allow_new_words' => '',
    ], $defaults), $atts);

    // Resolve wordset term IDs
    $wordset_term_ids = ll_resolve_wordset_term_ids_or_default($atts['wordset']);

    $allow_new_words = !empty($atts['allow_new_words']);

    // Get available categories for the wordset
    $available_categories = ll_get_categories_for_wordset($wordset_term_ids, $atts['include_recording_types'], $atts['exclude_recording_types']);

    // If no categories available, provide helpful diagnostics
    if (empty($available_categories)) {
        if (!$allow_new_words) {
            $diagnostic_msg = ll_diagnose_no_categories($wordset_term_ids, $atts['include_recording_types'], $atts['exclude_recording_types']);
            return '<div class="ll-recording-interface"><div class="ll-diagnostic-message">' . $diagnostic_msg . '</div></div>';
        }
        $available_categories = [
            'uncategorized' => __('Uncategorized', 'll-tools-text-domain'),
        ];
    }

    // Get images for the initial category (or first if none specified)
    $initial_category = !empty($atts['category']) && isset($available_categories[$atts['category']]) ? $atts['category'] : key($available_categories);
    // Prefer showing uncategorized first when present so missing-audio words are surfaced
    if (empty($atts['category']) && isset($available_categories['uncategorized'])) {
        $initial_category = 'uncategorized';
    }
    $images_needing_audio = ll_get_images_needing_audio($initial_category, $wordset_term_ids, $atts['include_recording_types'], $atts['exclude_recording_types']);
    // If the preferred initial category is empty (e.g., stale uncategorized records), fall back to the first category with work.
    if (empty($images_needing_audio) && count($available_categories) > 1) {
        foreach ($available_categories as $slug => $name) {
            if ($slug === $initial_category) {
                continue;
            }
            $maybe = ll_get_images_needing_audio($slug, $wordset_term_ids, $atts['include_recording_types'], $atts['exclude_recording_types']);
            if (!empty($maybe)) {
                $images_needing_audio = $maybe;
                $initial_category = $slug;
                break;
            }
        }
    }

    if (empty($images_needing_audio) && !$allow_new_words) {
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
                $dropdown_types[] = [
                    'slug' => $term->slug,
                    'name' => ll_get_recording_type_name($term->slug, $term->name),
                    'term_id' => $term->term_id,
                ];
            }
        }
    }

    // Get current user info for display
    $current_user = wp_get_current_user();

    $all_recording_types = [];
    if ($allow_new_words) {
        $all_recording_types = get_terms([
            'taxonomy'   => 'recording_type',
            'hide_empty' => false,
        ]);
        if (is_wp_error($all_recording_types)) {
            $all_recording_types = [];
        }
    }

    wp_localize_script('ll-audio-recorder', 'll_recorder_data', [
        'ajax_url'        => admin_url('admin-ajax.php'),
        'nonce'           => wp_create_nonce('ll_upload_recording'),
        'images'          => $images_needing_audio,
        'available_categories' => $available_categories,
        'language'        => $atts['language'],
        'wordset'         => $atts['wordset'],
        'wordset_ids'     => $wordset_term_ids,
        'hide_name'       => (bool) get_option('ll_hide_recording_titles', 0),
        'recording_types' => $dropdown_types,
        'allow_new_words' => $allow_new_words,
        'user_display_name' => $current_user->display_name,
        'require_all_types' => true,
        'initial_category' => $initial_category,
        'include_types'    => $atts['include_recording_types'],
        'exclude_types'    => $atts['exclude_recording_types'],
        'current_user_id'  => get_current_user_id(),
        'i18n' => [
            'uploading' => __('Uploading...', 'll-tools-text-domain'),
            'success' => __('Success! Recording will be processed later.', 'll-tools-text-domain'),
            'error_prefix' => __('Error:', 'll-tools-text-domain'),
            'upload_failed' => __('Upload failed:', 'll-tools-text-domain'),
            'saved_next_type' => __('Saved. Next type selected.', 'll-tools-text-domain'),
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
            'skipping'            => __('Skipping...', 'll-tools-text-domain'),
            'skip_failed'         => __('Skip failed:', 'll-tools-text-domain'),
            'no_images_in_category'=> __('No images need audio in this category.', 'll-tools-text-domain'),
            'category_switched'   => __('Category switched. Ready to record.', 'll-tools-text-domain'),
            'switch_failed'       => __('Switch failed:', 'll-tools-text-domain'),
            'new_word_preparing'  => __('Preparing new word...', 'll-tools-text-domain'),
            'new_word_failed'     => __('New word setup failed:', 'll-tools-text-domain'),
            'new_word_missing_category' => __('Enter a category name or disable "Create new category".', 'll-tools-text-domain'),
        ],
    ]);
    // Get wordset name for display
    $wordset_name = '';
    if (!empty($wordset_term_ids)) {
        $wordset_term = get_term($wordset_term_ids[0], 'wordset');
        if ($wordset_term && !is_wp_error($wordset_term)) {
            $wordset_name = $wordset_term->name;
        }
    }

    ob_start();
    ?>
    <?php $initial_count = is_array($images_needing_audio) ? count($images_needing_audio) : 0; ?>
    <div class="ll-recording-interface">
        <!-- Compact header - progress, category, wordset, user -->
        <div class="ll-recording-header">
            <div class="ll-recording-progress">
                <span class="ll-current-num"><?php echo $initial_count ? 1 : 0; ?></span> / <span class="ll-total-num"><?php echo $initial_count; ?></span>
            </div>

            <div class="ll-category-selector">
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

            <?php if ($wordset_name): ?>
            <div class="ll-wordset-display">
                <span><?php _e('Set:', 'll-tools-text-domain'); ?></span>
                <strong><?php echo esc_html($wordset_name); ?></strong>
            </div>
            <?php endif; ?>

            <?php if ($allow_new_words): ?>
            <div class="ll-new-word-toggle">
                <button type="button" class="ll-btn ll-btn-secondary" id="ll-new-word-toggle">
                    <?php _e('Record New Word', 'll-tools-text-domain'); ?>
                </button>
            </div>
            <?php endif; ?>

            <div class="ll-recorder-info">
                <?php echo esc_html($current_user->display_name); ?>
            </div>
        </div>

        <?php if ($allow_new_words): ?>
        <div class="ll-new-word-panel" style="display: none;">
            <div class="ll-new-word-card">
                <h3><?php _e('Record a New Word', 'll-tools-text-domain'); ?></h3>
                <p class="ll-new-word-status" id="ll-new-word-status"></p>
                <div class="ll-new-word-row">
                    <label for="ll-new-word-category"><?php _e('Category', 'll-tools-text-domain'); ?></label>
                    <select id="ll-new-word-category">
                        <?php
                        $uncat_label = __('Uncategorized', 'll-tools-text-domain');
                        $category_terms = get_terms([
                            'taxonomy'   => 'word-category',
                            'hide_empty' => false,
                        ]);
                        if (is_wp_error($category_terms)) { $category_terms = []; }
                        $category_options = [];
                        foreach ($category_terms as $term) {
                            if ($term->slug === 'uncategorized') {
                                $uncat_label = $term->name ?: $uncat_label;
                                continue;
                            }
                            $category_options[$term->slug] = $term->name;
                        }
                        if (!empty($category_options)) {
                            asort($category_options, SORT_FLAG_CASE | SORT_NATURAL);
                        }
                        ?>
                        <option value="uncategorized"><?php echo esc_html($uncat_label); ?></option>
                        <?php foreach ($category_options as $slug => $name): ?>
                            <option value="<?php echo esc_attr($slug); ?>"><?php echo esc_html($name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="ll-new-word-row ll-new-word-checkbox">
                    <label>
                        <input type="checkbox" id="ll-new-word-create-category" />
                        <?php _e('Create a new category for these words', 'll-tools-text-domain'); ?>
                    </label>
                </div>

                <div class="ll-new-word-create-fields" style="display: none;">
                    <div class="ll-new-word-row">
                        <label for="ll-new-word-category-name"><?php _e('New Category Name', 'll-tools-text-domain'); ?></label>
                        <input type="text" id="ll-new-word-category-name" placeholder="<?php esc_attr_e('e.g., Food', 'll-tools-text-domain'); ?>" />
                    </div>
                    <div class="ll-new-word-row">
                        <label><?php _e('Desired Recording Types', 'll-tools-text-domain'); ?></label>
                        <div class="ll-new-word-types">
                            <?php if (!empty($all_recording_types)): ?>
                                <?php foreach ($all_recording_types as $type): ?>
                                    <label>
                                        <input type="checkbox" value="<?php echo esc_attr($type->slug); ?>" <?php checked($type->slug, 'isolation'); ?> />
                                        <?php echo esc_html(ll_get_recording_type_name($type->slug, $type->name)); ?>
                                    </label>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <em><?php _e('No recording types available.', 'll-tools-text-domain'); ?></em>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="ll-new-word-row">
                    <label for="ll-new-word-text-target"><?php _e('Target Word (optional)', 'll-tools-text-domain'); ?></label>
                    <input type="text" id="ll-new-word-text-target" placeholder="<?php esc_attr_e('Enter the word in the target language', 'll-tools-text-domain'); ?>" />
                </div>
                <div class="ll-new-word-row">
                    <label for="ll-new-word-text-translation"><?php _e('Translation (optional)', 'll-tools-text-domain'); ?></label>
                    <input type="text" id="ll-new-word-text-translation" placeholder="<?php esc_attr_e('Enter the translation', 'll-tools-text-domain'); ?>" />
                </div>

                <div class="ll-new-word-actions">
                    <button type="button" class="ll-btn ll-btn-primary" id="ll-new-word-start"><?php _e('Continue to Recording', 'll-tools-text-domain'); ?></button>
                    <button type="button" class="ll-btn ll-btn-secondary" id="ll-new-word-back"><?php _e('Back to Existing Words', 'll-tools-text-domain'); ?></button>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="ll-recording-main">
            <?php
            $flashcard_size = get_option('ll_flashcard_image_size', 'small');
            $size_class = 'flashcard-size-' . sanitize_html_class($flashcard_size);
            ?>

            <!-- Image on left -->
            <div class="ll-recording-image-container">
                <div class="flashcard-container <?php echo esc_attr($size_class); ?>">
                    <img id="ll-current-image" class="quiz-image" src="" alt="">
                </div>
                <p id="ll-image-title" class="ll-image-title"></p>
                <p id="ll-image-category" class="ll-image-category"></p>
            </div>

            <!-- Controls on right -->
            <div class="ll-recording-controls-column">
                <!-- Recording type moved here for better visibility and more space -->
                <div class="ll-recording-type-selector">
                    <label for="ll-recording-type"><?php _e('Recording Type:', 'll-tools-text-domain'); ?></label>
                    <select id="ll-recording-type">
                        <?php
                        if (!empty($dropdown_types) && !is_wp_error($dropdown_types)) {
                            foreach ($dropdown_types as $type) {
                                $selected = ($type['slug'] === 'isolation' || (empty($images_needing_audio[0]['missing_types']) ? false : $type['slug'] === $images_needing_audio[0]['missing_types'][0])) ? 'selected' : '';
                                printf(
                                    '<option value="%s" %s>%s</option>',
                                    esc_attr($type['slug']),
                                    $selected,
                                    esc_html($type['name'])
                                );
                            }
                        }
                        ?>
                    </select>
                </div>

                <div class="ll-recording-buttons">
                    <button id="ll-record-btn" class="ll-btn ll-btn-record"
                            title="<?php esc_attr_e('Record', 'll-tools-text-domain'); ?>"></button>

                    <button id="ll-skip-btn" class="ll-btn ll-btn-skip"
                            title="<?php esc_attr_e('Skip', 'll-tools-text-domain'); ?>">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="white">
                            <path d="M6 18l8.5-6L6 6v12zM16 6v12h2V6h-2z"/>
                        </svg>
                    </button>
                </div>

                <div id="ll-recording-indicator" class="ll-recording-indicator" style="display:none;">
                    <span class="ll-recording-dot"></span>
                    <span id="ll-recording-timer">0:00</span>
                </div>

                <div id="ll-playback-controls" class="ll-playback-controls" style="display:none;">
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

function ll_get_categories_for_wordset($wordset_term_ids, $include_types_csv, $exclude_types_csv) {
    $all_types = get_terms(['taxonomy' => 'recording_type', 'fields' => 'slugs', 'hide_empty' => false]);
    if (is_wp_error($all_types)) $all_types = [];

    $include_types = !empty($include_types_csv) ? array_map('trim', explode(',', $include_types_csv)) : [];
    $exclude_types = !empty($exclude_types_csv) ? array_map('trim', explode(',', $exclude_types_csv)) : [];

    $base_filtered = $all_types;
    if (!empty($include_types)) {
        $base_filtered = array_intersect($base_filtered, $include_types);
    } elseif (!empty($exclude_types)) {
        $base_filtered = array_diff($base_filtered, $exclude_types);
    }

    if (empty($base_filtered)) {
        return [];
    }

    $categories = [];
    $has_uncategorized_items = false;
    $current_uid = get_current_user_id();

    // Image-backed items
    $image_args = [
        'post_type'      => 'word_images',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
        'fields'         => 'ids',
        'meta_query'     => [
            [ 'key' => '_thumbnail_id', 'compare' => 'EXISTS' ],
        ],
    ];
    $image_posts = get_posts($image_args);

    foreach ($image_posts as $img_id) {
        $word_id = ll_get_word_for_image_in_wordset($img_id, $wordset_term_ids);
        // desired types
        $desired = [];
        if ($word_id) {
            $desired = ll_tools_get_desired_recording_types_for_word($word_id);
        } else {
            $term_ids = wp_get_post_terms($img_id, 'word-category', ['fields' => 'ids']);
            $has_enabled_cat = false;
            $has_disabled_cat = false;
            if (!is_wp_error($term_ids) && !empty($term_ids)) {
                foreach ($term_ids as $tid) {
                    if (ll_tools_is_category_recording_disabled($tid)) {
                        $has_disabled_cat = true;
                        continue;
                    }
                    $has_enabled_cat = true;
                    $desired = array_merge($desired, ll_tools_get_desired_recording_types_for_category($tid));
                }
            }
            if (empty($desired)) {
                if ($has_enabled_cat) {
                    $desired = ll_tools_get_uncategorized_desired_recording_types();
                } elseif ($has_disabled_cat) {
                    $desired = [];
                } else {
                    $desired = ll_tools_get_uncategorized_desired_recording_types();
                }
            }
        }
        $filtered_types = array_values(array_intersect($desired, $base_filtered));
        if (empty($filtered_types)) { continue; }

        // Apply single-speaker gating only if requesting the full main set; otherwise, use global missing logic
        $main_types = ll_tools_get_main_recording_types();
        $types_equal_main = empty(array_diff($filtered_types, $main_types)) && empty(array_diff($main_types, $filtered_types));

        if ($word_id && $types_equal_main && ll_tools_get_preferred_speaker_for_word($word_id)) { continue; }

        if ($word_id) {
            $missing = $types_equal_main
                ? ll_get_user_missing_recording_types_for_word($word_id, $filtered_types, $current_uid)
                : ll_get_missing_recording_types_for_word($word_id, $filtered_types);
        } else {
            $missing = $filtered_types;
        }
        if (!empty($missing)) {
            $cats = wp_get_post_terms($img_id, 'word-category');
            if (!empty($cats) && !is_wp_error($cats)) {
                foreach ($cats as $cat) {
                    $categories[$cat->slug] = $cat->name;
                }
            } else {
                $has_uncategorized_items = true;
            }
        }
    }

    // Text-only words (no image)
    $word_args = [
        'post_type'      => 'words',
        'post_status'    => ['publish', 'draft'],
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
        'fields'         => 'ids',
        'meta_query'     => [
            'relation' => 'OR',
            [ 'key' => '_thumbnail_id', 'compare' => 'NOT EXISTS' ],
            [ 'key' => '_thumbnail_id', 'value' => '', 'compare' => '=' ],
        ],
    ];
    if (!empty($wordset_term_ids)) {
        $word_args['tax_query'] = [[ 'taxonomy' => 'wordset', 'field' => 'term_id', 'terms' => array_map('intval', $wordset_term_ids) ]];
    }
    $text_words = get_posts($word_args);
    foreach ($text_words as $word_id) {
        $desired = ll_tools_get_desired_recording_types_for_word($word_id);
        $filtered_types = array_values(array_intersect($desired, $base_filtered));
        if (empty($filtered_types)) { continue; }

        $main_types = ll_tools_get_main_recording_types();
        $types_equal_main = empty(array_diff($filtered_types, $main_types)) && empty(array_diff($main_types, $filtered_types));

        if ($word_id && $types_equal_main && ll_tools_get_preferred_speaker_for_word($word_id)) { continue; }

        $missing = $types_equal_main
            ? ll_get_user_missing_recording_types_for_word($word_id, $filtered_types, $current_uid)
            : ll_get_missing_recording_types_for_word($word_id, $filtered_types);
        if (!empty($missing)) {
            $cats = wp_get_post_terms($word_id, 'word-category');
            if (!empty($cats) && !is_wp_error($cats)) {
                foreach ($cats as $cat) {
                    $categories[$cat->slug] = $cat->name;
                }
            } else {
                $has_uncategorized_items = true;
            }
        }
    }

    // Drop categories that don't actually have any items after applying filters.
    if (!empty($categories)) {
        $filtered_categories = [];
        foreach ($categories as $slug => $name) {
            if ($slug === 'uncategorized') {
                $filtered_categories[$slug] = $name;
                continue;
            }
            $maybe_images = ll_get_images_needing_audio($slug, $wordset_term_ids, $include_types_csv, $exclude_types_csv);
            if (!empty($maybe_images)) {
                $filtered_categories[$slug] = $name;
            }
        }
        $categories = $filtered_categories;
    }

    $uncat_images = ll_get_images_needing_audio('uncategorized', $wordset_term_ids, $include_types_csv, $exclude_types_csv);
    $has_uncategorized_items = !empty($uncat_images);

    $uncat_label = isset($categories['uncategorized']) ? $categories['uncategorized'] : __('Uncategorized', 'll-tools-text-domain');
    unset($categories['uncategorized']);

    if (!empty($categories)) {
        asort($categories, SORT_FLAG_CASE | SORT_NATURAL);
    }

    if ($has_uncategorized_items) {
        $categories = array_merge(['uncategorized' => $uncat_label], $categories);
    }

    return $categories;
}

/**
 * Diagnose why no categories are available and provide helpful feedback
 */
function ll_diagnose_no_categories($wordset_term_ids, $include_types_csv, $exclude_types_csv) {
    $messages = [];

    // Check if any word_images posts exist at all
    $total_images = wp_count_posts('word_images');
    $published_images = $total_images->publish ?? 0;

    if ($published_images === 0) {
        $messages[] = __('No word images have been created yet. Please create some word images first.', 'll-tools-text-domain');
        $messages[] = sprintf(
            '<a href="%s">%s</a>',
            admin_url('post-new.php?post_type=word_images'),
            __('Create a word image', 'll-tools-text-domain')
        );
        return '<p>' . implode('</p><p>', $messages) . '</p>';
    }

    // Check what's actually being looked for by the recording interface
    $args = [
        'post_type' => 'word_images',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'meta_query' => [
            [
                'key' => '_thumbnail_id',
                'compare' => 'EXISTS',
            ],
        ],
    ];

    $images_with_featured = get_posts($args);

    if (empty($images_with_featured)) {
        $messages[] = sprintf(
            __('You have %d word image(s), but none have a featured image set.', 'll-tools-text-domain'),
            $published_images
        );
        $messages[] = __('<strong>To fix this:</strong> Edit each word image and set a featured image using the "Featured Image" panel on the right side of the editor.', 'll-tools-text-domain');
        $messages[] = sprintf(
            '<a href="%s" class="button button-primary">%s</a>',
            admin_url('edit.php?post_type=word_images'),
            __('Edit Word Images', 'll-tools-text-domain')
        );
        return '<p>' . implode('</p><p>', $messages) . '</p>';
    }

    // Check if any have categories
    $images_with_categories = 0;
    $sample_categories = [];

    foreach ($images_with_featured as $img_id) {
        $categories = wp_get_post_terms($img_id, 'word-category');
        if (!is_wp_error($categories) && !empty($categories)) {
            $images_with_categories++;
            if (count($sample_categories) < 3) {
                foreach ($categories as $cat) {
                    $sample_categories[$cat->slug] = $cat->name;
                }
            }
        }
    }

    if ($images_with_categories === 0) {
        $messages[] = sprintf(
            __('You have %d word image(s) with featured images, but none are assigned to any word categories.', 'll-tools-text-domain'),
            count($images_with_featured)
        );
        $messages[] = __('<strong>To fix this:</strong> Edit each word image and assign it to at least one word category.', 'll-tools-text-domain');
        $messages[] = sprintf(
            '<a href="%s" class="button button-primary">%s</a>',
            admin_url('edit.php?post_type=word_images'),
            __('Edit Word Images', 'll-tools-text-domain')
        );
        return '<p>' . implode('</p><p>', $messages) . '</p>';
    }

    // At this point we have images with featured images and categories
    // Check recording types
    $is_uncategorized_request = ($category_slug === 'uncategorized');
    $uncategorized_label = __('Uncategorized', 'll-tools-text-domain');
    $active_category_term = null;
    if (!empty($category_slug) && !$is_uncategorized_request) {
        $active_category_term = get_term_by('slug', $category_slug, 'word-category');
        if ($active_category_term && is_wp_error($active_category_term)) {
            $active_category_term = null;
        }
    }
    $active_category_term = null;
    if (!empty($category_slug) && !$is_uncategorized_request) {
        $term = get_term_by('slug', $category_slug, 'word-category');
        if ($term && !is_wp_error($term)) {
            $active_category_term = $term;
        }
    }
    $active_category_term = null;
    if (!empty($category_slug) && !$is_uncategorized_request) {
        $term = get_term_by('slug', $category_slug, 'word-category');
        if ($term && !is_wp_error($term)) {
            $active_category_term = $term;
        }
    }
    $active_category_term = null;
    if (!empty($category_slug) && !$is_uncategorized_request) {
        $term = get_term_by('slug', $category_slug, 'word-category');
        if ($term && !is_wp_error($term)) {
            $active_category_term = $term;
        }
    }
    $active_category_term = null;
    if (!empty($category_slug) && !$is_uncategorized_request) {
        $term = get_term_by('slug', $category_slug, 'word-category');
        if ($term && !is_wp_error($term)) {
            $active_category_term = $term;
        }
    }

    $all_types = get_terms([
        'taxonomy' => 'recording_type',
        'hide_empty' => false,
        'fields' => 'slugs'
    ]);

    if (is_wp_error($all_types) || empty($all_types)) {
        $messages[] = __('No recording types are configured in your system.', 'll-tools-text-domain');
        $messages[] = sprintf(
            '<a href="%s" class="button button-primary">%s</a>',
            admin_url('tools.php?page=ll-recording-types'),
            __('Set Up Recording Types', 'll-tools-text-domain')
        );
        return '<p>' . implode('</p><p>', $messages) . '</p>';
    }

    // Everything looks good but still no categories showing
    $messages[] = sprintf(
        __('You have %d word image(s) with featured images in categories (%s), but all images in the selected wordset already have recordings.', 'll-tools-text-domain'),
        $images_with_categories,
        implode(', ', array_slice($sample_categories, 0, 3)) . (count($sample_categories) > 3 ? '...' : '')
    );

    if (!empty($wordset_term_ids)) {
        $wordset = get_term($wordset_term_ids[0], 'wordset');
        if ($wordset && !is_wp_error($wordset)) {
            $messages[] = sprintf(__('Current wordset filter: <strong>%s</strong>', 'll-tools-text-domain'), $wordset->name);
        }
    }

    $messages[] = __('This means all available images already have the required recording types. Great work!', 'll-tools-text-domain');

    return '<p>' . implode('</p><p>', $messages) . '</p>';
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
        if ($term) {
            $dropdown_types[] = [
                'slug' => $term->slug,
                'name' => ll_get_recording_type_name($term->slug, $term->name),
                'term_id' => $term->term_id,
            ];
        }
    }

    wp_send_json_success([
        'images' => $images,
        'recording_types' => $dropdown_types,
    ]);
}

// AJAX: prepare a new word (and optional category) for recording
add_action('wp_ajax_ll_prepare_new_word_recording', 'll_prepare_new_word_recording_handler');

function ll_prepare_new_word_recording_handler() {
    check_ajax_referer('ll_upload_recording', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error('You must be logged in');
    }

    if (!ll_tools_user_can_record()) {
        wp_send_json_error('You do not have permission to record audio');
    }

    $config = function_exists('ll_get_user_recording_config') ? ll_get_user_recording_config(get_current_user_id()) : [];
    $allow_new = is_array($config) && !empty($config['allow_new_words']);
    if (!$allow_new && !current_user_can('manage_options')) {
        wp_send_json_error('New word recording is not enabled for your account');
    }

    $target_text_raw = sanitize_text_field($_POST['word_text_target'] ?? '');
    $target_text = ll_sanitize_word_title_text($target_text_raw);
    $translation_text = sanitize_text_field($_POST['word_text_translation'] ?? '');
    $translation_text = trim($translation_text);

    $category_slug = sanitize_text_field($_POST['category'] ?? 'uncategorized');
    $create_category = !empty($_POST['create_category']);
    $new_category_name = sanitize_text_field($_POST['new_category_name'] ?? '');

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

    $include_types_csv = sanitize_text_field($_POST['include_types'] ?? '');
    $exclude_types_csv = sanitize_text_field($_POST['exclude_types'] ?? '');

    $all_types = get_terms(['taxonomy' => 'recording_type', 'fields' => 'slugs', 'hide_empty' => false]);
    if (is_wp_error($all_types) || empty($all_types)) {
        wp_send_json_error('No recording types are configured');
    }

    $category_term = null;
    $category_name = __('Uncategorized', 'll-tools-text-domain');
    $category_slug_value = 'uncategorized';
    $category_term_id = 0;

    if ($create_category) {
        if ($new_category_name === '') {
            wp_send_json_error('Missing new category name');
        }
        $existing = term_exists($new_category_name, 'word-category');
        if (is_array($existing)) {
            $category_term_id = (int) $existing['term_id'];
        } elseif ($existing) {
            $category_term_id = (int) $existing;
        } else {
            $created = wp_insert_term($new_category_name, 'word-category');
            if (is_wp_error($created)) {
                wp_send_json_error('Failed to create category: ' . $created->get_error_message());
            }
            $category_term_id = (int) $created['term_id'];
        }

        $selected_types = isset($_POST['new_category_types']) ? (array) $_POST['new_category_types'] : [];
        $selected_types = array_values(array_unique(array_filter(array_map('sanitize_text_field', $selected_types))));
        $selected_types = array_values(array_intersect($selected_types, $all_types));
        if (empty($selected_types)) {
            $selected_types = in_array('isolation', $all_types, true) ? ['isolation'] : array_slice($all_types, 0, 1);
        }
        update_term_meta($category_term_id, 'll_desired_recording_types', $selected_types);

        $category_term = get_term($category_term_id, 'word-category');
    } elseif (!empty($category_slug) && $category_slug !== 'uncategorized') {
        $category_term = get_term_by('slug', $category_slug, 'word-category');
    } elseif ($category_slug === 'uncategorized') {
        $maybe_uncat = get_term_by('slug', 'uncategorized', 'word-category');
        if ($maybe_uncat && !is_wp_error($maybe_uncat)) {
            $category_term = $maybe_uncat;
        }
    }

    if ($category_term && !is_wp_error($category_term)) {
        $category_term_id = (int) $category_term->term_id;
        $category_name = $category_term->name;
        $category_slug_value = $category_term->slug;
    }

    if ($category_term_id && function_exists('ll_tools_is_category_recording_disabled') && ll_tools_is_category_recording_disabled($category_term_id)) {
        wp_send_json_error('Recording is disabled for this category');
    }

    $desired_types = [];
    if ($category_term_id) {
        $desired_types = ll_tools_get_desired_recording_types_for_category($category_term_id);
    }
    if (empty($desired_types)) {
        $desired_types = ll_tools_get_uncategorized_desired_recording_types();
    }

    $include_types = $include_types_csv ? array_map('trim', explode(',', $include_types_csv)) : [];
    $exclude_types = $exclude_types_csv ? array_map('trim', explode(',', $exclude_types_csv)) : [];

    $filtered_types = $all_types;
    if (!empty($include_types)) {
        $filtered_types = array_values(array_intersect($filtered_types, $include_types));
    } elseif (!empty($exclude_types)) {
        $filtered_types = array_values(array_diff($filtered_types, $exclude_types));
    }
    $filtered_types = array_values(array_intersect($desired_types, $filtered_types));

    if (empty($filtered_types)) {
        wp_send_json_error('No recording types are available for this category');
    }

    $store_in_title = (get_option('ll_word_title_language_role', 'target') === 'target');
    if ($create_category) {
        $store_in_title = true;
    } elseif ($category_term && function_exists('ll_tools_get_category_quiz_config')) {
        $cat_cfg = ll_tools_get_category_quiz_config($category_term);
        $opt_type = isset($cat_cfg['option_type']) ? (string) $cat_cfg['option_type'] : '';
        if ($opt_type === 'text_title') {
            $store_in_title = true;
        } elseif (in_array($opt_type, ['text_translation', 'text_audio'], true)) {
            $store_in_title = false;
        }
    }

    $placeholder = sprintf(
        __('New word %s', 'll-tools-text-domain'),
        date_i18n('Y-m-d H:i', current_time('timestamp'))
    );
    if ($store_in_title) {
        $post_title = $target_text !== '' ? $target_text : $placeholder;
    } else {
        $post_title = $translation_text !== '' ? $translation_text : $placeholder;
    }

    $word_id = wp_insert_post([
        'post_title'  => $post_title,
        'post_type'   => 'words',
        'post_status' => 'draft',
    ]);

    if (is_wp_error($word_id) || !$word_id) {
        $err = is_wp_error($word_id) ? $word_id->get_error_message() : 'Unknown error';
        wp_send_json_error('Failed to create word: ' . $err);
    }

    if ($store_in_title) {
        if ($translation_text !== '') {
            update_post_meta($word_id, 'word_translation', $translation_text);
        }
    } else {
        if ($target_text !== '') {
            update_post_meta($word_id, 'word_translation', $target_text);
        }
    }

    if ($category_term_id) {
        wp_set_object_terms($word_id, [$category_term_id], 'word-category');
    }

    if (!empty($posted_ids)) {
        wp_set_object_terms($word_id, array_map('intval', $posted_ids), 'wordset');
    }

    $display_text = $target_text !== '' ? $target_text : $post_title;

    $dropdown_types = [];
    foreach ($filtered_types as $slug) {
        $term = get_term_by('slug', $slug, 'recording_type');
        if ($term && !is_wp_error($term)) {
            $dropdown_types[] = [
                'slug' => $term->slug,
                'name' => ll_get_recording_type_name($term->slug, $term->name),
                'term_id' => $term->term_id,
            ];
        }
    }

    $item = [
        'id'               => 0,
        'title'            => $display_text,
        'image_url'        => '',
        'category_name'    => $category_name,
        'category_slug'    => $category_slug_value,
        'word_id'          => (int) $word_id,
        'word_title'       => $display_text,
        'word_translation' => $store_in_title
            ? ($translation_text !== '' ? $translation_text : '')
            : ($target_text !== '' ? $target_text : ''),
        'use_word_display' => true,
        'missing_types'    => array_values($filtered_types),
        'existing_types'   => [],
        'is_text_only'     => true,
    ];

    wp_send_json_success([
        'word' => $item,
        'recording_types' => $dropdown_types,
        'category' => [
            'slug' => $category_slug_value,
            'name' => $category_name,
            'term_id' => $category_term_id,
        ],
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
    $word_id        = intval($_POST['word_id'] ?? 0);
    $recording_type = sanitize_text_field($_POST['recording_type'] ?? '');
    $word_title     = sanitize_text_field($_POST['word_title'] ?? '');
    $include_types  = sanitize_text_field($_POST['include_types'] ?? '');
    $exclude_types  = sanitize_text_field($_POST['exclude_types'] ?? '');
    $wordset_ids    = [];

    if (!$image_id && !$word_id) {
        if ($word_title === '') {
            wp_send_json_error('Missing image_id or word_id');
        }
    }

    if (!empty($_POST['wordset_ids'])) {
        $decoded = json_decode(stripslashes((string) $_POST['wordset_ids']), true);
        if (is_array($decoded)) $wordset_ids = array_map('intval', $decoded);
    }
    $wordset_spec = sanitize_text_field($_POST['wordset'] ?? '');
    if (empty($wordset_ids)) {
        $wordset_ids = ll_resolve_wordset_term_ids_or_default($wordset_spec);
    }

    if ($image_id) {
        $image_post = get_post($image_id);
        if (!$image_post || $image_post->post_type !== 'word_images') {
            wp_send_json_error('Invalid image ID');
        }
        $word_id = ll_find_or_create_word_for_image($image_id, $image_post, $wordset_ids);
        if (is_wp_error($word_id)) {
            wp_send_json_error('Failed to find/create word: ' . $word_id->get_error_message());
        }
        $word_id = (int) $word_id;
    } else {
        if ($word_id) {
            $word_post = get_post($word_id);
            if (!$word_post || $word_post->post_type !== 'words') {
                wp_send_json_error('Invalid word ID');
            }
        } else {
            $created_word_id = ll_find_or_create_word_by_title($word_title, $wordset_ids);
            if (is_wp_error($created_word_id)) {
                wp_send_json_error('Failed to create word: ' . $created_word_id->get_error_message());
            }
            $word_id = (int) $created_word_id;
        }
    }

    // Rebuild filtered type list just like the UI
    $all_types = get_terms(['taxonomy' => 'recording_type', 'fields' => 'slugs', 'hide_empty' => false]);
    if (is_wp_error($all_types) || empty($all_types)) { $all_types = []; }

    $inc = $include_types ? array_map('trim', explode(',', $include_types)) : [];
    $exc = $exclude_types ? array_map('trim', explode(',', $exclude_types)) : [];

    $filtered_types = $all_types;
    if (!empty($inc))  $filtered_types = array_values(array_intersect($filtered_types, $inc));
    elseif (!empty($exc)) $filtered_types = array_values(array_diff($filtered_types, $exc));

    // Look for a recent child "word_audio" with this type
    $args = [
        'post_type'      => 'word_audio',
        'post_status'    => ['draft','pending','publish','private','inherit'],
        'perm'           => 'any', // allow detection even if the current user can't read drafts
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
    // Recompute remaining types, respecting desired types and only applying single-speaker logic
    // when the full main set is requested.
    $desired_word = ll_tools_get_desired_recording_types_for_word($word_id);
    $filtered_types = array_values(array_intersect($filtered_types, $desired_word));

    $main_types = ll_tools_get_main_recording_types();
    $types_equal_main = empty(array_diff($filtered_types, $main_types)) && empty(array_diff($main_types, $filtered_types));

    if ($types_equal_main) {
        if (ll_tools_get_preferred_speaker_for_word($word_id)) {
            $remaining_missing = [];
        } else {
            $remaining_missing = ll_get_user_missing_recording_types_for_word($word_id, $filtered_types, get_current_user_id());
        }
    } else {
        // For subset requests (e.g., isolation-only), consider any existing recording sufficient
        $remaining_missing = ll_get_missing_recording_types_for_word($word_id, $filtered_types);
    }

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
 *     'word_title'    => string|null,   // NEW: word post title (target lang, preferred)
 *     'word_translation' => string|null, // NEW: word's English meaning (fallback)
 *     'use_word_display' => bool,       // NEW: true if word data is preferred over image title
 *     'category_slug' => string,        // category slug or "uncategorized" placeholder
 *     'missing_types' => string[],       // recording_type slugs still needed (filtered)
 *     'existing_types'=> string[],       // recording_type slugs already present (not filtered, all)
 *   ],
 *   ...
 * ]
 */
function ll_get_images_needing_audio($category_slug = '', $wordset_term_ids = [], $include_types_csv = '', $exclude_types_csv = '') {
    if (empty($wordset_term_ids)) {
        $default_id = ll_get_default_wordset_term_id();
        if ($default_id) {
            $wordset_term_ids = [$default_id];
        }
    }

    $is_uncategorized_request = ($category_slug === 'uncategorized');
    $uncategorized_label = __('Uncategorized', 'll-tools-text-domain');

    $all_types = get_terms([
        'taxonomy'   => 'recording_type',
        'hide_empty' => false,
        'fields'     => 'slugs',
    ]);
    if (is_wp_error($all_types) || empty($all_types)) {
        $all_types = [];
    }

    $include_types = !empty($include_types_csv) ? array_map('trim', explode(',', $include_types_csv)) : [];
    $exclude_types = !empty($exclude_types_csv) ? array_map('trim', explode(',', $exclude_types_csv)) : [];

    $filtered_types = $all_types;
    if (!empty($include_types)) {
        $filtered_types = array_intersect($filtered_types, $include_types);
    } elseif (!empty($exclude_types)) {
        $filtered_types = array_diff($filtered_types, $exclude_types);
    }

    if (empty($filtered_types)) {
        return [];
    }
    $items_by_category = [];
    $missing_audio_instances = get_option('ll_missing_audio_instances', []);

    $image_args = [
        'post_type'      => 'word_images',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
        'fields'         => 'ids',
    ];

    if (!empty($category_slug) && !$is_uncategorized_request) {
        $image_args['tax_query'] = [[
            'taxonomy' => 'word-category',
            'field'    => 'slug',
            'terms'    => $category_slug,
        ]];
    }

    $image_posts = get_posts($image_args);

    foreach ($image_posts as $img_id) {
        $word_id = ll_get_word_for_image_in_wordset($img_id, $wordset_term_ids);

        // NEW: Enrich with word display data (title or translation)
        $word_title = null;
        $word_translation = null;
        $use_word_display = false;
        $title_role = get_option('ll_word_title_language_role', 'target');
        if ($word_id) {
            $word_post = get_post($word_id);
            if ($word_post && $word_post->post_type === 'words') {
                $use_word_display = true;
                $word_title = get_the_title($word_id);
                $word_translation = get_post_meta($word_id, 'word_translation', true);
                if ($word_translation === '') {
                    $word_translation = get_post_meta($word_id, 'word_english_meaning', true);
                }
                // If titles are in translation language (helper) and we have a learned-language translation,
                // prefer showing the translation for recording UI.
                if ($title_role === 'translation' && !empty($word_translation)) {
                    $word_title = $word_translation;
                }
            }
        }

        // Determine desired + filtered types for this item
        $desired = [];
        $has_disabled_cat = false;
        if ($word_id) {
            $category_disabled = false;
            // If a specific category is being recorded, respect only that category's settings
            if (!empty($category_slug) && !$is_uncategorized_request) {
                $cat_term = get_term_by('slug', $category_slug, 'word-category');
                if ($cat_term && !is_wp_error($cat_term)) {
                    if (ll_tools_is_category_recording_disabled((int) $cat_term->term_id)) {
                        $category_disabled = true;
                    } else {
                        $desired = ll_tools_get_desired_recording_types_for_category((int) $cat_term->term_id);
                    }
                }
            }
            // Fallback to union across the word's categories when no specific category context
            if (empty($desired) && !$category_disabled) {
                $desired = ll_tools_get_desired_recording_types_for_word($word_id);
            }
            if ($category_disabled && empty($desired)) {
                $has_disabled_cat = true;
            }
        } else {
            $term_ids = wp_get_post_terms($img_id, 'word-category', ['fields' => 'ids']);
            if (!is_wp_error($term_ids) && !empty($term_ids)) {
                foreach ($term_ids as $tid) {
                    if (ll_tools_is_category_recording_disabled($tid)) {
                        $has_disabled_cat = true;
                        continue;
                    }
                    $desired = array_merge($desired, ll_tools_get_desired_recording_types_for_category($tid));
                }
            }
        }
        if (empty($desired)) {
            if ($has_disabled_cat) {
                $desired = [];
            } else {
                $desired = ll_tools_get_uncategorized_desired_recording_types();
            }
        }
        $types_for_item = array_values(array_intersect($desired, $filtered_types));

        // Decide whether to enforce single-speaker gating and per-user missing logic
        $main_types = ll_tools_get_main_recording_types();
        $types_equal_main = empty(array_diff($types_for_item, $main_types)) && empty(array_diff($main_types, $types_for_item));

        // If a complete speaker exists and we're asking for all main types, skip this item entirely
        if ($word_id && $types_equal_main && ll_tools_get_preferred_speaker_for_word($word_id)) {
            continue;
        }

        if ($word_id) {
            if ($types_equal_main) {
                // Encourage a single speaker to complete the full set
                $current_uid = get_current_user_id();
                $missing_types = ll_get_user_missing_recording_types_for_word($word_id, $types_for_item, $current_uid);
            } else {
                // For subset requests (e.g., isolation-only), consider any existing recording sufficient
                $missing_types = ll_get_missing_recording_types_for_word($word_id, $types_for_item);
            }
            $existing_types = ll_get_existing_recording_types_for_word($word_id);
        } else {
            $missing_types = $types_for_item;
            $existing_types = [];
        }

        if (!empty($missing_types)) {
            $thumb_url = get_the_post_thumbnail_url($img_id, 'large');
            if ($thumb_url) {
                if (isset($active_category_term) && $active_category_term) {
                    $category_name       = $active_category_term->name;
                    $category_slug_value = $active_category_term->slug;
                } else {
                    $categories = wp_get_post_terms($img_id, 'word-category');
                    if (!empty($categories) && !is_wp_error($categories)) {
                        $category            = $categories[0];
                        $category_name       = $category->name;
                        $category_slug_value = $category->slug;
                    } else {
                        $category_name       = $uncategorized_label;
                        $category_slug_value = 'uncategorized';
                    }
                }

                if (!isset($items_by_category[$category_slug_value])) {
                    $items_by_category[$category_slug_value] = [
                        'name'  => $category_name,
                        'slug'  => $category_slug_value,
                        'items' => [],
                    ];
                }

                // Try to find a translation for image-only cases when titles are helper-language
                $img_translation = get_post_meta($img_id, 'word_translation', true);
                if ($img_translation === '') {
                    $img_translation = get_post_meta($img_id, 'word_english_meaning', true);
                }

                if (!$word_id && $title_role === 'translation' && empty($img_translation)) {
                    // Search for a word with the same title in the same wordset or same-language wordsets
                    $image_title = get_the_title($img_id);
                    $allowed_wordset_ids = array_map('intval', $wordset_term_ids);
                    // Expand to wordsets with the same language label(s)
                    $langs = [];
                    foreach ((array)$wordset_term_ids as $wsid) {
                        $lang = function_exists('ll_get_wordset_language') ? ll_get_wordset_language($wsid) : '';
                        if (!empty($lang)) $langs[$lang] = true;
                    }
                    if (!empty($langs)) {
                        $lang_list = array_keys($langs);
                        // Fetch wordsets that share the same language meta
                        $mq = ['relation' => (count($lang_list) > 1 ? 'OR' : 'AND')];
                        foreach ($lang_list as $l) { $mq[] = ['key' => 'll_language', 'value' => $l, 'compare' => '=']; }
                        $lang_sets = get_terms([
                            'taxonomy'   => 'wordset',
                            'hide_empty' => false,
                            'fields'     => 'ids',
                            'meta_query' => $mq,
                        ]);
                        if (!is_wp_error($lang_sets) && !empty($lang_sets)) {
                            $allowed_wordset_ids = array_values(array_unique(array_merge($allowed_wordset_ids, array_map('intval', $lang_sets))));
                        }
                    }

                    $related_words = get_posts([
                        'post_type'      => 'words',
                        'post_status'    => ['publish','draft','pending'],
                        'posts_per_page' => 25,
                        's'              => $image_title,
                        'fields'         => 'ids',
                        'tax_query'      => !empty($allowed_wordset_ids) ? [[
                            'taxonomy' => 'wordset',
                            'field'    => 'term_id',
                            'terms'    => $allowed_wordset_ids,
                        ]] : [],
                    ]);
                    if (!is_wp_error($related_words) && !empty($related_words)) {
                        foreach ($related_words as $rw_id) {
                            if (strcasecmp(get_the_title($rw_id), $image_title) === 0) {
                                $img_translation = get_post_meta($rw_id, 'word_translation', true);
                                if ($img_translation === '') {
                                    $img_translation = get_post_meta($rw_id, 'word_english_meaning', true);
                                }
                                if (!empty($img_translation)) {
                                    break;
                                }
                            }
                        }
                    }
                }

                $items_by_category[$category_slug_value]['items'][] = [
                    'id'               => $img_id,
                    'title'            => ($title_role === 'translation' && !empty($img_translation)) ? $img_translation : get_the_title($img_id),
                    'image_url'        => $thumb_url,
                    'category_name'    => $category_name,
                    'category_slug'    => $category_slug_value,
                    'word_id'          => $word_id ?: 0,
                    'word_title'       => $word_title,
                    'word_translation' => $word_translation,
                    'use_word_display' => ($use_word_display || ($title_role === 'translation' && (!empty($img_translation) || !empty($word_translation)))),
                    'missing_types'    => $missing_types,
                    'existing_types'   => $existing_types,
                    'is_text_only'     => false,
                ];
            }
        }
    }

    $word_args = [
        'post_type'      => 'words',
        'post_status'    => ['publish', 'draft'],
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
        'fields'         => 'ids',
        'meta_query'     => [
            'relation' => 'OR',
            [
                'key'     => '_thumbnail_id',
                'compare' => 'NOT EXISTS',
            ],
            [
                'key'     => '_thumbnail_id',
                'value'   => '',
                'compare' => '=',
            ],
        ],
    ];

    if (!empty($wordset_term_ids)) {
        $word_args['tax_query'] = [[
            'taxonomy' => 'wordset',
            'field'    => 'term_id',
            'terms'    => array_map('intval', $wordset_term_ids),
        ]];
    }

    if (!empty($category_slug) && !$is_uncategorized_request) {
        if (empty($word_args['tax_query'])) {
            $word_args['tax_query'] = [];
        }
        $word_args['tax_query'][] = [
            'taxonomy' => 'word-category',
            'field'    => 'slug',
            'terms'    => $category_slug,
        ];
        if (count($word_args['tax_query']) > 1) {
            $word_args['tax_query']['relation'] = 'AND';
        }
    }

    $text_words = get_posts($word_args);

    foreach ($text_words as $word_id) {
        // Respect category-specific desired types when a category is targeted
        $desired = [];
        $category_disabled = false;
        if (!empty($category_slug) && !$is_uncategorized_request) {
            $cat_term = get_term_by('slug', $category_slug, 'word-category');
            if ($cat_term && !is_wp_error($cat_term)) {
                if (ll_tools_is_category_recording_disabled((int) $cat_term->term_id)) {
                    $category_disabled = true;
                } else {
                    $desired = ll_tools_get_desired_recording_types_for_category((int) $cat_term->term_id);
                }
            }
        }
        if (empty($desired) && !$category_disabled) {
            $desired = ll_tools_get_desired_recording_types_for_word($word_id);
        }
        $types_for_word = array_values(array_intersect($desired, $filtered_types));
        if (empty($types_for_word)) { continue; }

        $main_types = ll_tools_get_main_recording_types();
        $types_equal_main = empty(array_diff($types_for_word, $main_types)) && empty(array_diff($main_types, $types_for_word));

        // Skip only if complete speaker exists AND we’re collecting the full main set
        if ($types_equal_main && ll_tools_get_preferred_speaker_for_word($word_id)) { continue; }

        if ($types_equal_main) {
            $missing_types = ll_get_user_missing_recording_types_for_word($word_id, $types_for_word, get_current_user_id());
        } else {
            $missing_types = ll_get_missing_recording_types_for_word($word_id, $types_for_word);
        }
        $existing_types = ll_get_existing_recording_types_for_word($word_id);

        if (!empty($missing_types)) {
            if (isset($active_category_term) && $active_category_term) {
                $category_name       = $active_category_term->name;
                $category_slug_value = $active_category_term->slug;
            } else {
                $categories = wp_get_post_terms($word_id, 'word-category');
                if (!empty($categories) && !is_wp_error($categories)) {
                    $category            = $categories[0];
                    $category_name       = $category->name;
                    $category_slug_value = $category->slug;
                } else {
                    $category_name       = $uncategorized_label;
                    $category_slug_value = 'uncategorized';
                }
            }

            if (!isset($items_by_category[$category_slug_value])) {
                $items_by_category[$category_slug_value] = [
                    'name'  => $category_name,
                    'slug'  => $category_slug_value,
                    'items' => [],
                ];
            }

            $translation = get_post_meta($word_id, 'word_translation', true);
            if ($translation === '') {
                $translation = get_post_meta($word_id, 'word_english_meaning', true);
            }

            $display_word_title = get_the_title($word_id);
            if (get_option('ll_word_title_language_role', 'target') === 'translation' && !empty($translation)) {
                $display_word_title = $translation;
            }

            $items_by_category[$category_slug_value]['items'][] = [
                'id'             => 0,
                'title'          => $display_word_title,
                'image_url'      => '',
                'category_name'  => $category_name,
                'category_slug'  => $category_slug_value,
                'word_id'        => $word_id,
                // NEW: For text-only (no image), use word's own title and translation
                'word_title'       => $display_word_title,
                'word_translation' => $translation,
                'use_word_display' => true, // Always prefer word data for text-only
                'missing_types'    => $missing_types,
                'existing_types'  => $existing_types,
                'is_text_only'   => true,
            ];
        }
    }

    // Missing audio words captured by [word_audio] shortcode (no matching word/audio found)
    if ($is_uncategorized_request || empty($category_slug)) {
        $missing_audio_instances = is_array($missing_audio_instances) ? $missing_audio_instances : [];
        if (!empty($missing_audio_instances)) {
            $uncat_desired = ll_tools_get_uncategorized_desired_recording_types();
            $types_for_missing = array_values(array_intersect($uncat_desired, $filtered_types));

            if (!empty($types_for_missing)) {
                if (!isset($items_by_category['uncategorized'])) {
                    $items_by_category['uncategorized'] = [
                        'name'  => $uncategorized_label,
                        'slug'  => 'uncategorized',
                        'items' => [],
                    ];
                }

                $seen_missing_titles = [];
                foreach ($missing_audio_instances as $missing_word => $source_post_id) {
                    $word_title = sanitize_text_field($missing_word);
                    if ($word_title === '' || isset($seen_missing_titles[$word_title])) {
                        continue;
                    }
                    $seen_missing_titles[$word_title] = true;

                    $word_id = 0;
                    $existing_types = [];
                    $missing_types = $types_for_missing;

                    if (function_exists('ll_find_post_by_exact_title')) {
                        $maybe_word = ll_find_post_by_exact_title($word_title, 'words');
                        if ($maybe_word) {
                            $word_id = (int) $maybe_word->ID;
                            $main_types = ll_tools_get_main_recording_types();
                            $types_equal_main = empty(array_diff($types_for_missing, $main_types)) && empty(array_diff($main_types, $types_for_missing));
                            if ($types_equal_main && ll_tools_get_preferred_speaker_for_word($word_id)) {
                                continue;
                            }
                            if ($types_equal_main) {
                                $missing_types = ll_get_user_missing_recording_types_for_word($word_id, $types_for_missing, get_current_user_id());
                            } else {
                                $missing_types = ll_get_missing_recording_types_for_word($word_id, $types_for_missing);
                            }
                            $existing_types = ll_get_existing_recording_types_for_word($word_id);
                        }
                    }

                    if (empty($missing_types)) {
                        continue;
                    }

                    $items_by_category['uncategorized']['items'][] = [
                        'id'               => 0,
                        'title'            => $word_title,
                        'image_url'        => '',
                        'category_name'    => $uncategorized_label,
                        'category_slug'    => 'uncategorized',
                        'word_id'          => $word_id,
                        'word_title'       => $word_title,
                        'word_translation' => '',
                        'use_word_display' => true,
                        'missing_types'    => $missing_types,
                        'existing_types'   => $existing_types,
                        'is_text_only'     => true,
                        'missing_audio_source_post' => intval($source_post_id),
                    ];
                }
            }
        }
    }

    uasort($items_by_category, function($a, $b) {
        return strcasecmp($a['name'], $b['name']);
    });

    if (!empty($category_slug)) {
        $target_slugs = $is_uncategorized_request ? ['uncategorized'] : [$category_slug];
    } else {
        $target_slugs = array_keys($items_by_category);
    }

    $result = [];
    foreach ($target_slugs as $slug) {
        if (!isset($items_by_category[$slug])) {
            continue;
        }
        foreach ($items_by_category[$slug]['items'] as $item) {
            $result[] = $item;
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
        'post_status'    => ['draft','pending','publish','private','inherit'], // count in-flight too
        'perm'           => 'any', // bypass capability gating so recorder drafts still count
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
 * For a given word (parent of word_audio), return the recording_type slugs missing (not recorded), limited to provided filtered types.
 * The $ignore_skipped flag is retained for backward compatibility but has no effect now that skipped types are session-only.
 *
 * @param int $word_id
 * @param array $filtered_types Slugs available for this shortcode instance
 * @param bool  $ignore_skipped Legacy flag (unused)
 * @return array
 */
function ll_get_missing_recording_types_for_word(int $word_id, array $filtered_types, bool $ignore_skipped = false): array {
    $existing = ll_get_existing_recording_types_for_word($word_id);
    $missing = array_values(array_diff($filtered_types, $existing));
    return $missing;
}

/**
 * Get existing recording types for a word recorded by a specific user.
 */
function ll_get_existing_recording_types_for_word_by_user(int $word_id, int $user_id): array {
    if (!$user_id) { return []; }
    $audio_posts = get_posts([
        'post_type'      => 'word_audio',
        'post_status'    => ['draft','pending','publish','private','inherit'],
        'perm'           => 'any',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'post_parent'    => $word_id,
        'author'         => $user_id,
    ]);
    if (empty($audio_posts)) { return []; }
    $existing = [];
    foreach ($audio_posts as $post_id) {
        $terms = wp_get_post_terms($post_id, 'recording_type', ['fields' => 'slugs']);
        if (!is_wp_error($terms) && !empty($terms)) {
            foreach ($terms as $slug) { $existing[] = $slug; }
        }
    }
    return array_values(array_unique($existing));
}

/**
 * User-specific missing types for a word among the provided desired/filter set.
 * The $ignore_skipped flag is retained for backward compatibility but has no effect now that skipped types are session-only.
 */
function ll_get_user_missing_recording_types_for_word(int $word_id, array $filtered_types, int $user_id, bool $ignore_skipped = false): array {
    $user_existing = ll_get_existing_recording_types_for_word_by_user($word_id, $user_id);
    $missing = array_values(array_diff($filtered_types, $user_existing));
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
        plugins_url('css/flashcard/base.css', LL_TOOLS_MAIN_FILE),
        [],
        filemtime(LL_TOOLS_BASE_PATH . 'css/flashcard/base.css')
    );

    wp_enqueue_style(
        'll-flashcard-mode-practice',
        plugins_url('css/flashcard/mode-practice.css', LL_TOOLS_MAIN_FILE),
        ['ll-flashcard-style'],
        filemtime(LL_TOOLS_BASE_PATH . 'css/flashcard/mode-practice.css')
    );

    wp_enqueue_style(
        'll-flashcard-mode-learning',
        plugins_url('css/flashcard/mode-learning.css', LL_TOOLS_MAIN_FILE),
        ['ll-flashcard-style'],
        filemtime(LL_TOOLS_BASE_PATH . 'css/flashcard/mode-learning.css')
    );

    wp_enqueue_style(
        'll-flashcard-mode-listening',
        plugins_url('css/flashcard/mode-listening.css', LL_TOOLS_MAIN_FILE),
        ['ll-flashcard-style'],
        filemtime(LL_TOOLS_BASE_PATH . 'css/flashcard/mode-listening.css')
    );

    wp_enqueue_style(
        'll-recording-interface',
        plugins_url('css/recording-interface.css', LL_TOOLS_MAIN_FILE),
        [
            'll-flashcard-style',
            'll-flashcard-mode-practice',
            'll-flashcard-mode-learning',
            'll-flashcard-mode-listening'
        ],
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

    $image_id       = intval($_POST['image_id'] ?? 0);
    $word_id        = intval($_POST['word_id'] ?? 0);
    $recording_type = sanitize_text_field($_POST['recording_type'] ?? '');
    $word_title     = sanitize_text_field($_POST['word_title'] ?? '');

    if (!$image_id && !$word_id) {
        if ($word_title === '') {
            wp_send_json_error('Missing image_id or word_id');
        }
    }

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

    $include_types_csv = sanitize_text_field($_POST['include_types'] ?? '');
    $exclude_types_csv = sanitize_text_field($_POST['exclude_types'] ?? '');

    $all_types = get_terms(['taxonomy' => 'recording_type', 'fields' => 'slugs', 'hide_empty' => false]);
    if (is_wp_error($all_types) || empty($all_types)) { $all_types = []; }

    $include_types = $include_types_csv ? array_map('trim', explode(',', $include_types_csv)) : [];
    $exclude_types = $exclude_types_csv ? array_map('trim', explode(',', $exclude_types_csv)) : [];

    $filtered_types = $all_types;
    if (!empty($include_types)) {
        $filtered_types = array_values(array_intersect($filtered_types, $include_types));
    } elseif (!empty($exclude_types)) {
        $filtered_types = array_values(array_diff($filtered_types, $exclude_types));
    }

    if ($image_id) {
        $image_post = get_post($image_id);
        if (!$image_post || $image_post->post_type !== 'word_images') {
            wp_send_json_error('Invalid image ID');
        }
        $word_id = ll_find_or_create_word_for_image($image_id, $image_post, $posted_ids);
        if (is_wp_error($word_id)) {
            wp_send_json_error('Failed to find/create word post: ' . $word_id->get_error_message());
        }
        $word_id = (int) $word_id;
    } else {
        if ($word_id) {
            $word_post = get_post($word_id);
            if (!$word_post || $word_post->post_type !== 'words') {
                wp_send_json_error('Invalid word ID');
            }
        } else {
            $created_word_id = ll_find_or_create_word_by_title($word_title, $posted_ids);
            if (is_wp_error($created_word_id)) {
                wp_send_json_error('Failed to create word post: ' . $created_word_id->get_error_message());
            }
            $word_id = (int) $created_word_id;
        }
    }

    // Remaining types, applying single-speaker logic only when collecting the full main set
    $desired_word = ll_tools_get_desired_recording_types_for_word($word_id);
    $filtered_types = array_values(array_intersect($filtered_types, $desired_word));

    $main_types = ll_tools_get_main_recording_types();
    $types_equal_main = empty(array_diff($filtered_types, $main_types)) && empty(array_diff($main_types, $filtered_types));

    if ($types_equal_main) {
        if (ll_tools_get_preferred_speaker_for_word($word_id)) {
            $remaining_missing = [];
        } else {
            $remaining_missing = ll_get_user_missing_recording_types_for_word($word_id, $filtered_types, get_current_user_id());
        }
    } else {
        $remaining_missing = ll_get_missing_recording_types_for_word($word_id, $filtered_types);
    }
    // Drop the skipped type for this session so the UI can move on, but do not persist anything.
    if ($recording_type) {
        $remaining_missing = array_values(array_diff($remaining_missing, [$recording_type]));
    }

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
    if (!current_user_can('upload_files')) {
        wp_send_json_error('You do not have permission to upload recordings');
    }

    $current_user_id = get_current_user_id();

    if (empty($_FILES['audio'])) {
        wp_send_json_error('Missing data');
    }

    $image_id       = intval($_POST['image_id'] ?? 0);
    $word_id        = intval($_POST['word_id'] ?? 0);
    $recording_type = sanitize_text_field($_POST['recording_type'] ?? 'isolation');
    $word_title     = sanitize_text_field($_POST['word_title'] ?? '');

    if (!$image_id && !$word_id) {
        if ($word_title === '') {
            wp_send_json_error('Missing image_id or word_id');
        }
    }

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

    $include_types_csv = sanitize_text_field($_POST['include_types'] ?? '');
    $exclude_types_csv = sanitize_text_field($_POST['exclude_types'] ?? '');

    $all_types = get_terms(['taxonomy' => 'recording_type', 'fields' => 'slugs', 'hide_empty' => false]);
    if (is_wp_error($all_types) || empty($all_types)) { $all_types = []; }

    $include_types = $include_types_csv ? array_map('trim', explode(',', $include_types_csv)) : [];
    $exclude_types = $exclude_types_csv ? array_map('trim', explode(',', $exclude_types_csv)) : [];

    $filtered_types = $all_types;
    if (!empty($include_types)) {
        $filtered_types = array_values(array_intersect($filtered_types, $include_types));
    } elseif (!empty($exclude_types)) {
        $filtered_types = array_values(array_diff($filtered_types, $exclude_types));
    }

    if ($image_id) {
        $image_post = get_post($image_id);
        if (!$image_post || $image_post->post_type !== 'word_images') {
            error_log('Upload step: Invalid image ID');
            wp_send_json_error('Invalid image ID');
        }
        $title = $image_post->post_title;
        $word_id = ll_find_or_create_word_for_image($image_id, $image_post, $posted_ids);
        if (is_wp_error($word_id)) {
            error_log('Upload step: Failed to find/create word post: ' . $word_id->get_error_message());
            wp_send_json_error('Failed to find/create word post: ' . $word_id->get_error_message());
        }
        $word_id = (int) $word_id;
    } else {
        if ($word_id) {
            $word_post = get_post($word_id);
            if (!$word_post || $word_post->post_type !== 'words') {
                error_log('Upload step: Invalid word ID');
                wp_send_json_error('Invalid word ID');
            }
            $title = $word_post->post_title;
        } else {
            $created_word_id = ll_find_or_create_word_by_title($word_title, $posted_ids);
            if (is_wp_error($created_word_id)) {
                error_log('Upload step: Failed to create word post: ' . $created_word_id->get_error_message());
                wp_send_json_error('Failed to create word post: ' . $created_word_id->get_error_message());
            }
            $word_id = (int) $created_word_id;
            $title = $word_title;
        }
    }

    $file       = $_FILES['audio'];
    $upload_dir = wp_upload_dir();
    $safe_title = sanitize_file_name($title);
    // Extra hardening for edge-case titles (quotes, exotic punctuation)
    $safe_title = str_replace(array("'", '"'), '', $safe_title);
    $safe_title = preg_replace('/[^A-Za-z0-9._-]+/', '-', $safe_title);
    $safe_title = trim($safe_title, '-_.');
    if ($safe_title === '') {
        $safe_title = 'recording';
    }
    $timestamp  = time();

    $mime_type = $file['type'] ?? '';
    $extension = '.wav';
    if (strpos($mime_type, 'wav') !== false)       { $extension = '.wav'; }
    elseif (strpos($mime_type, 'pcm') !== false)   { $extension = '.wav'; }
    elseif (strpos($mime_type, 'mpeg') !== false || strpos($mime_type, 'mp3') !== false) { $extension = '.mp3'; }
    elseif (strpos($mime_type, 'mp4') !== false)   { $extension = '.mp4'; }
    elseif (strpos($mime_type, 'aac') !== false)   { $extension = '.aac'; }
    else { $extension = '.webm'; }

    $filename = $safe_title . '_' . $timestamp . $extension;
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

    $audio_post_id = wp_insert_post([
        'post_title'  => $title,
        'post_type'   => 'word_audio',
        'post_status' => 'draft',
        'post_parent' => $word_id,
        'post_author' => $current_user_id,
    ]);
    if (is_wp_error($audio_post_id) || !$audio_post_id) {
        $err_msg = is_wp_error($audio_post_id) ? $audio_post_id->get_error_message() : 'Unknown insert failure';
        error_log('Upload step: Failed to create word_audio post: ' . $err_msg);
        wp_send_json_error('Failed to create word_audio post: ' . $err_msg);
    }

    update_post_meta($audio_post_id, 'audio_file_path', $relative_path);
    update_post_meta($audio_post_id, 'speaker_user_id', $current_user_id);
    update_post_meta($audio_post_id, 'recording_date', current_time('mysql'));
    update_post_meta($audio_post_id, '_ll_needs_audio_processing', '1');
    update_post_meta($audio_post_id, '_ll_raw_recording_format', $extension);

    if (!empty($recording_type)) {
        wp_set_object_terms($audio_post_id, $recording_type, 'recording_type');
    }

    if (!get_post_meta($word_id, 'word_audio_file', true)) {
        update_post_meta($word_id, 'word_audio_file', $relative_path);
    }

    // Recompute remaining types using the same rules as the UI: honor desired types
    // and only apply single-speaker gating when the full main set is being collected.
    $desired_word = ll_tools_get_desired_recording_types_for_word($word_id);
    $filtered_types = array_values(array_intersect($filtered_types, $desired_word));

    $main_types = ll_tools_get_main_recording_types();
    $types_equal_main = empty(array_diff($filtered_types, $main_types)) && empty(array_diff($main_types, $filtered_types));

    if ($types_equal_main) {
        if (ll_tools_get_preferred_speaker_for_word($word_id)) {
            $remaining_missing = [];
        } else {
            $remaining_missing = ll_get_user_missing_recording_types_for_word($word_id, $filtered_types, $current_user_id);
        }
    } else {
        $remaining_missing = ll_get_missing_recording_types_for_word($word_id, $filtered_types);
    }

    if (function_exists('ll_remove_missing_audio_instance')) {
        // Match the normalization pipeline used when populating the cache (normalize case, then sanitize)
        $normalized_for_cache = $title;
        if (function_exists('ll_normalize_case')) {
            $normalized_for_cache = ll_normalize_case($normalized_for_cache);
        }
        if (function_exists('ll_missing_audio_sanitize_word_text')) {
            $normalized_for_cache = ll_missing_audio_sanitize_word_text($normalized_for_cache);
        }

        // Canonicalize apostrophes to avoid smart-quote vs straight-quote mismatches
        $canonicalize_apostrophes = function ($text) {
            return str_replace(
                array("\u{2019}", "\u{2018}", "\u{201B}", "\u{02BC}", "\u{FF07}"),
                "'",
                (string) $text
            );
        };

        $candidates = [];
        if (is_string($normalized_for_cache) && $normalized_for_cache !== '') {
            $candidates[] = $normalized_for_cache;
            $candidates[] = $canonicalize_apostrophes($normalized_for_cache);
            // Version without apostrophes to catch legacy keys
            $candidates[] = preg_replace("/['’ʼ`´]/u", '', $normalized_for_cache);
        }
        // Fall back to raw title variants in case earlier cache entries were stored differently
        if ($title !== '') {
            $candidates[] = $title;
            $candidates[] = $canonicalize_apostrophes($title);
            $candidates[] = preg_replace("/['’ʼ`´]/u", '', $title);
            if (function_exists('ll_normalize_case')) {
                $norm = ll_normalize_case($title);
                $candidates[] = $norm;
                $candidates[] = $canonicalize_apostrophes($norm);
                $candidates[] = preg_replace("/['’ʼ`´]/u", '', $norm);
            }
        }

        foreach (array_unique(array_filter($candidates, function ($v) { return is_string($v) && $v !== ''; })) as $cand) {
            ll_remove_missing_audio_instance($cand);
        }
    }

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

    // Copy translation from image if present
    $img_translation = get_post_meta($image_id, 'word_translation', true);
    if (!empty($img_translation)) {
        update_post_meta($word_id, 'word_translation', $img_translation);
    }

    // Assign to wordset
    if (!empty($wordset_ids)) {
        wp_set_object_terms($word_id, $wordset_ids, 'wordset');
    }

    return $word_id;
}

/**
 * Find an existing word by exact title or create a new one (draft) with the default wordset.
 *
 * @param string $word_title
 * @param array  $wordset_ids
 * @return int|WP_Error
 */
function ll_find_or_create_word_by_title($word_title, $wordset_ids = []) {
    $word_title = ll_sanitize_word_title_text($word_title);
    if ($word_title === '') {
        return new WP_Error('empty_title', 'Missing word title');
    }

    // Try to find existing by exact title
    if (function_exists('ll_find_post_by_exact_title')) {
        $maybe = ll_find_post_by_exact_title($word_title, 'words');
        if ($maybe) {
            $word_id = (int) $maybe->ID;
            if (!empty($wordset_ids)) {
                wp_set_object_terms($word_id, $wordset_ids, 'wordset', true);
            }
            return $word_id;
        }
    }

    if (empty($wordset_ids)) {
        $default_id = ll_get_default_wordset_term_id();
        if ($default_id) {
            $wordset_ids = [$default_id];
        }
    }

    $word_id = wp_insert_post([
        'post_title'  => $word_title,
        'post_type'   => 'words',
        'post_status' => 'draft',
    ]);

    if (is_wp_error($word_id)) {
        return $word_id;
    }

    if (!empty($wordset_ids)) {
        wp_set_object_terms($word_id, $wordset_ids, 'wordset');
    }

    return (int) $word_id;
}

/**
 * Strip shortcodes while keeping their inner content intact.
 *
 * @param string $text
 * @return string
 */
function ll_strip_shortcodes_preserve_content($text) {
    if (!function_exists('get_shortcode_regex')) {
        return $text;
    }

    $pattern = '/' . get_shortcode_regex() . '/s';
    $previous = null;

    while ($previous !== $text) {
        $previous = $text;
        $text = preg_replace_callback($pattern, function ($m) {
            // Respect escaped shortcodes [[tag]]
            if ($m[1] === '[' && $m[6] === ']') {
                return substr($m[0], 1, -1);
            }
            // Group 5 is the inner content of enclosing shortcodes.
            return isset($m[5]) ? $m[5] : '';
        }, $text);
    }

    return $text;
}

/**
 * Sanitize a word title by stripping shortcodes, HTML, parentheses, and extra whitespace.
 *
 * @param string $text
 * @return string
 */
function ll_sanitize_word_title_text($text) {
    $text = (string) $text;
    // Remove shortcode wrappers while keeping the inner text (e.g., color tags)
    $text = ll_strip_shortcodes_preserve_content($text);
    // Strip BBCode-style or unknown bracket tags (e.g., [color]...[/color])
    $text = preg_replace('/\[[^\]]+\]/u', '', $text);
    $text = wp_kses_decode_entities($text);
    // Strip HTML tags
    $text = wp_strip_all_tags($text);
    // Remove anything in parentheses (multiple occurrences)
    $text = preg_replace('/\s*\([^)]*\)/u', '', $text);
    // Collapse whitespace
    $text = preg_replace('/\s+/u', ' ', $text);
    return trim($text);
}
