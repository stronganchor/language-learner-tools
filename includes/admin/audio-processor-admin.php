<?php
/**
 * Admin page for bulk processing uploaded audio recordings
 */

if (!defined('WPINC')) { die; }

function ll_register_audio_processor_page() {
    add_submenu_page(
        'tools.php',
        'Audio Processor - Language Learner Tools',
        'LL Audio Processor',
        'view_ll_tools',
        'll-audio-processor',
        'll_render_audio_processor_page'
    );
}
add_action('admin_menu', 'll_register_audio_processor_page');

function ll_audio_processor_get_recording_type_icons_map() {
    if (function_exists('ll_get_recording_type_icons_map')) {
        $map = ll_get_recording_type_icons_map();
        if (is_array($map) && !empty($map)) {
            return $map;
        }
    }

    return [
        'isolation'    => '🔍',
        'introduction' => '💬',
        'question'     => '❓',
        'sentence'     => '📝',
        'default'      => '🎙️',
    ];
}

function ll_audio_processor_get_recording_type_icon($slug) {
    if (function_exists('ll_get_recording_type_icon')) {
        return (string) ll_get_recording_type_icon($slug);
    }
    $map = ll_audio_processor_get_recording_type_icons_map();
    $key = sanitize_title((string) $slug);
    if ($key !== '' && !empty($map[$key])) {
        return (string) $map[$key];
    }
    return (string) ($map['default'] ?? '');
}

function ll_audio_processor_get_recording_type_name($slug, $term_name = '') {
    if (function_exists('ll_get_recording_type_name')) {
        return (string) ll_get_recording_type_name($slug, $term_name);
    }
    if ((string) $term_name !== '') {
        return (string) $term_name;
    }
    $normalized = trim((string) $slug);
    if ($normalized === '') {
        return '';
    }
    return ucwords(str_replace(['-', '_'], ' ', $normalized));
}

function ll_audio_processor_get_recording_type_label($slug, $term_name = '') {
    $name = ll_audio_processor_get_recording_type_name($slug, $term_name);
    $icon = ll_audio_processor_get_recording_type_icon($slug);
    return trim($icon . ' ' . $name);
}

function ll_enqueue_audio_processor_assets($hook) {
    if ($hook !== 'tools_page_ll-audio-processor') return;

    $recording_type_terms = get_terms([
        'taxonomy'   => 'recording_type',
        'hide_empty' => false,
    ]);

    $recording_type_choices = [];
    if (!is_wp_error($recording_type_terms)) {
        usort($recording_type_terms, static function ($left, $right) {
            if (function_exists('ll_compare_recording_type_slugs')) {
                return ll_compare_recording_type_slugs($left->slug, $right->slug);
            }
            return strnatcasecmp((string) $left->slug, (string) $right->slug);
        });

        foreach ($recording_type_terms as $term) {
            $name = ll_audio_processor_get_recording_type_name($term->slug, $term->name);
            $recording_type_choices[] = [
                'slug' => $term->slug,
                'name' => $name,
                'icon' => ll_audio_processor_get_recording_type_icon($term->slug),
                'label' => ll_audio_processor_get_recording_type_label($term->slug, $term->name),
            ];
        }
    }

    ll_enqueue_asset_by_timestamp('/css/audio-processor.css', 'll-audio-processor-css');
    ll_enqueue_asset_by_timestamp('/js/audio-processor.js', 'll-audio-processor-js', [], true);

    // Get all unprocessed recordings (grouped with duplicates)
    $recording_sets = ll_get_unprocessed_recordings();
    $recordings = isset($recording_sets['all']) ? $recording_sets['all'] : [];

    wp_localize_script('ll-audio-processor-js', 'llAudioProcessor', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('ll_audio_processor'),
        'recordings' => $recordings,
        'recordingTypes' => $recording_type_choices,
        'recordingTypeIcons' => ll_audio_processor_get_recording_type_icons_map(),
        'i18n' => [
            'saveNoFiles' => __('No files to save.', 'll-tools-text-domain'),
            'saveConfirmTemplate' => __('Save %d processed audio file(s)?', 'll-tools-text-domain'),
            'saveButtonDefault' => __('Save All Changes', 'll-tools-text-domain'),
            'saveButtonSaving' => __('Saving...', 'll-tools-text-domain'),
            'savePreparing' => __('Preparing uploads...', 'll-tools-text-domain'),
            'saveStatusTemplate' => __('Saving: %1$s (%2$d/%3$d)', 'll-tools-text-domain'),
            'saveSuccessTemplate' => __('Success! Saved %1$d of %2$d files.', 'll-tools-text-domain'),
            'saveErrorSummaryTemplate' => __('Completed with errors: %1$d saved, %2$d failed.', 'll-tools-text-domain'),
            'saveUnexpectedError' => __('Unexpected error while saving. Please try again.', 'll-tools-text-domain'),
            'saveCountTemplate' => __('%1$d / %2$d complete', 'll-tools-text-domain'),
            'beforeUnloadWarning' => __('Saving is still in progress. Leaving this page will interrupt uploads.', 'll-tools-text-domain'),
            'editTitleButton' => __('Edit title', 'll-tools-text-domain'),
            'saveTitleButton' => __('Save title', 'll-tools-text-domain'),
            'cancelTitleButton' => __('Cancel', 'll-tools-text-domain'),
            'titleInputLabel' => __('Word title', 'll-tools-text-domain'),
            'titleInputPlaceholder' => __('Enter word title', 'll-tools-text-domain'),
            'titleRequired' => __('Title cannot be empty.', 'll-tools-text-domain'),
            'titleSaving' => __('Saving...', 'll-tools-text-domain'),
            'titleSaveFailed' => __('Could not update title.', 'll-tools-text-domain'),
        ],
    ]);
}
add_action('admin_enqueue_scripts', 'll_enqueue_audio_processor_assets');

function ll_audio_processor_build_recording_key($parent_word_id, $recording_type_slug) {
    $type_key = $recording_type_slug ? $recording_type_slug : '__none__';
    return $parent_word_id . '::' . $type_key;
}

function ll_audio_processor_get_published_recording_map($parent_word_ids) {
    $parent_word_ids = array_values(array_unique(array_filter(array_map('intval', $parent_word_ids))));
    if (empty($parent_word_ids)) {
        return [];
    }

    $published_ids = get_posts([
        'post_type' => 'word_audio',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'post_parent__in' => $parent_word_ids,
        'fields' => 'ids',
    ]);

    if (empty($published_ids) || is_wp_error($published_ids)) {
        return [];
    }

    $published_by_key = [];
    foreach ($published_ids as $audio_post_id) {
        $parent_word_id = wp_get_post_parent_id($audio_post_id);
        if (!$parent_word_id) {
            continue;
        }

        $recording_type_slugs = wp_get_post_terms($audio_post_id, 'recording_type', ['fields' => 'slugs']);
        $recording_type_slug = (!is_wp_error($recording_type_slugs) && !empty($recording_type_slugs)) ? $recording_type_slugs[0] : '';
        $key = ll_audio_processor_build_recording_key($parent_word_id, $recording_type_slug);

        if (!isset($published_by_key[$key])) {
            $published_by_key[$key] = [];
        }
        $published_by_key[$key][] = (int) $audio_post_id;
    }

    return $published_by_key;
}

function ll_get_unprocessed_recordings() {
    $args = [
        'post_type' => 'word_audio',
        'post_status' => ['publish', 'draft'],
        'posts_per_page' => -1,
        'meta_query' => [
            [
                'key' => '_ll_needs_audio_processing',
                'value' => '1',
                'compare' => '='
            ]
        ],
        'orderby' => 'date',
        'order' => 'DESC'
    ];

    $query = new WP_Query($args);
    $recordings = [];
    $parent_word_ids = [];

    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $audio_post_id = get_the_ID();
            $audio_file = get_post_meta($audio_post_id, 'audio_file_path', true);
            $parent_word_id = wp_get_post_parent_id($audio_post_id);

            if ($audio_file && $parent_word_id) {
                $word_title = get_the_title($parent_word_id);
                $categories = wp_get_post_terms($parent_word_id, 'word-category', ['fields' => 'names']);

                // Get wordset names
                $wordsets = wp_get_post_terms($parent_word_id, 'wordset', ['fields' => 'names']);
                $wordset_names = (!is_wp_error($wordsets) && !empty($wordsets)) ? $wordsets : [];

                // Get recording type names
                $recording_type_terms = wp_get_post_terms($audio_post_id, 'recording_type');
                $recording_type_names = [];
                $recording_type_slugs = [];
                $recording_type_items = [];
                if (!is_wp_error($recording_type_terms) && !empty($recording_type_terms)) {
                    foreach ($recording_type_terms as $term) {
                        $slug = (string) $term->slug;
                        $name = ll_audio_processor_get_recording_type_name($slug, $term->name);
                        $recording_type_slugs[] = $slug;
                        $recording_type_names[] = $name;
                        $recording_type_items[] = [
                            'slug' => $slug,
                            'name' => $name,
                            'icon' => ll_audio_processor_get_recording_type_icon($slug),
                            'label' => ll_audio_processor_get_recording_type_label($slug, $term->name),
                        ];
                    }
                }
                $recording_type_slug = !empty($recording_type_slugs) ? $recording_type_slugs[0] : '';

                // Get image thumbnail
                $image_url = get_the_post_thumbnail_url($parent_word_id, 'thumbnail');

                $recordings[] = [
                    'id' => $audio_post_id,
                    'title' => $word_title,
                    'audioUrl' => site_url($audio_file),
                    'uploadDate' => get_post_meta($audio_post_id, 'recording_date', true),
                    'categories' => is_array($categories) && !is_wp_error($categories) ? $categories : [],
                    'wordsets' => $wordset_names,
                    'recordingTypes' => $recording_type_names,
                    'recordingTypeItems' => $recording_type_items,
                    'recordingType' => $recording_type_slug,
                    'imageUrl' => $image_url ?: '',
                    'parentWordId' => (int) $parent_word_id,
                    'recordingKey' => ll_audio_processor_build_recording_key($parent_word_id, $recording_type_slug),
                ];

                $parent_word_ids[] = (int) $parent_word_id;
            }
        }
        wp_reset_postdata();
    }

    $published_by_key = ll_audio_processor_get_published_recording_map($parent_word_ids);
    $queue = [];
    $duplicates = [];
    $seen_keys = [];

    foreach ($recordings as $recording) {
        $key = $recording['recordingKey'];
        $published_ids = isset($published_by_key[$key]) ? $published_by_key[$key] : [];
        $has_published_duplicate = false;

        if (!empty($published_ids)) {
            if (count($published_ids) > 1 || (count($published_ids) === 1 && (int) $published_ids[0] !== (int) $recording['id'])) {
                $has_published_duplicate = true;
            }
        }

        if ($has_published_duplicate) {
            $recording['duplicateReason'] = 'published';
            $duplicates[] = $recording;
            continue;
        }

        if (isset($seen_keys[$key])) {
            $recording['duplicateReason'] = 'queued';
            $duplicates[] = $recording;
            continue;
        }

        $recording['duplicateReason'] = '';
        $queue[] = $recording;
        $seen_keys[$key] = true;
    }

    return [
        'queue' => $queue,
        'duplicates' => $duplicates,
        'all' => $recordings,
    ];
}

function ll_render_audio_processor_recording_item($recording, $duplicate_reason = '') {
    $recording_type_items = [];
    if (!empty($recording['recordingTypeItems']) && is_array($recording['recordingTypeItems'])) {
        foreach ($recording['recordingTypeItems'] as $item) {
            $slug = sanitize_title((string) ($item['slug'] ?? ''));
            if ($slug === '') {
                continue;
            }
            $name = ll_audio_processor_get_recording_type_name($slug, (string) ($item['name'] ?? ''));
            $recording_type_items[] = [
                'slug' => $slug,
                'name' => $name,
                'icon' => ll_audio_processor_get_recording_type_icon($slug),
            ];
        }
    } elseif (!empty($recording['recordingType'])) {
        $slug = sanitize_title((string) $recording['recordingType']);
        if ($slug !== '') {
            $fallback_name = !empty($recording['recordingTypes'][0]) ? (string) $recording['recordingTypes'][0] : '';
            $recording_type_items[] = [
                'slug' => $slug,
                'name' => ll_audio_processor_get_recording_type_name($slug, $fallback_name),
                'icon' => ll_audio_processor_get_recording_type_icon($slug),
            ];
        }
    }

    $duplicate_label = '';
    if ($duplicate_reason === 'published') {
        $duplicate_label = __('Published audio exists', 'll-tools-text-domain');
    } elseif ($duplicate_reason === 'queued') {
        $duplicate_label = __('Duplicate in queue', 'll-tools-text-domain');
    }
    ?>
    <div
        class="ll-recording-item"
        data-id="<?php echo esc_attr($recording['id']); ?>"
        data-parent-word-id="<?php echo esc_attr((int) ($recording['parentWordId'] ?? 0)); ?>"
    >
        <div class="ll-recording-label">
            <input type="checkbox" class="ll-recording-checkbox" value="<?php echo esc_attr($recording['id']); ?>">
            <div class="ll-recording-info">
                <div class="ll-word-title-block" data-parent-word-id="<?php echo esc_attr((int) ($recording['parentWordId'] ?? 0)); ?>">
                    <div class="ll-word-title-display-row">
                        <strong class="ll-recording-title-text"><?php echo esc_html($recording['title']); ?></strong>
                        <button type="button" class="ll-edit-word-title-btn button-link">
                            <?php echo esc_html__('Edit title', 'll-tools-text-domain'); ?>
                        </button>
                    </div>
                    <div class="ll-word-title-editor" hidden>
                        <label class="screen-reader-text" for="<?php echo esc_attr('ll-word-title-input-' . (int) $recording['id']); ?>">
                            <?php echo esc_html__('Word title', 'll-tools-text-domain'); ?>
                        </label>
                        <input
                            id="<?php echo esc_attr('ll-word-title-input-' . (int) $recording['id']); ?>"
                            type="text"
                            class="ll-word-title-input"
                            value="<?php echo esc_attr($recording['title']); ?>"
                            placeholder="<?php echo esc_attr__('Enter word title', 'll-tools-text-domain'); ?>"
                            maxlength="200"
                        >
                        <button type="button" class="button button-small ll-save-word-title-btn">
                            <?php echo esc_html__('Save title', 'll-tools-text-domain'); ?>
                        </button>
                        <button type="button" class="button button-small ll-cancel-word-title-btn">
                            <?php echo esc_html__('Cancel', 'll-tools-text-domain'); ?>
                        </button>
                    </div>
                </div>
                <div class="ll-recording-meta">
                    <?php if (!empty($recording['categories'])): ?>
                        <span class="ll-recording-categories">
                            <?php echo esc_html(implode(', ', $recording['categories'])); ?>
                        </span>
                    <?php endif; ?>
                    <span class="ll-recording-type">
                        <span class="ll-recording-meta-label"><?php echo esc_html__('Type', 'll-tools-text-domain'); ?>:</span>
                        <span class="ll-recording-meta-value">
                            <?php if (!empty($recording_type_items)): ?>
                                <?php foreach ($recording_type_items as $index => $type): ?>
                                    <span class="ll-recording-type-entry" data-recording-type="<?php echo esc_attr($type['slug']); ?>">
                                        <span class="ll-recording-type-icon" aria-hidden="true"><?php echo esc_html($type['icon']); ?></span>
                                        <span class="ll-recording-type-text"><?php echo esc_html($type['name']); ?></span>
                                    </span>
                                    <?php if ($index < (count($recording_type_items) - 1)): ?>
                                        <span class="ll-recording-type-separator" aria-hidden="true">, </span>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span class="ll-recording-type-entry">
                                    <span class="ll-recording-type-text"><?php echo esc_html__('Unassigned', 'll-tools-text-domain'); ?></span>
                                </span>
                            <?php endif; ?>
                        </span>
                    </span>
                    <?php if ($duplicate_label): ?>
                        <span class="ll-recording-duplicate"><?php echo esc_html($duplicate_label); ?></span>
                    <?php endif; ?>
                    <span class="ll-recording-date">
                        <?php echo esc_html(date('Y-m-d H:i', strtotime($recording['uploadDate']))); ?>
                    </span>
                </div>
            </div>
        </div>
        <audio controls preload="none" src="<?php echo esc_url($recording['audioUrl']); ?>"></audio>
    </div>
    <?php
}

function ll_render_audio_processor_page() {
    $recording_sets = ll_get_unprocessed_recordings();
    $queue_recordings = isset($recording_sets['queue']) ? $recording_sets['queue'] : [];
    $duplicate_recordings = isset($recording_sets['duplicates']) ? $recording_sets['duplicates'] : [];
    $has_recordings = !empty($queue_recordings) || !empty($duplicate_recordings);
    $active_tab = !empty($queue_recordings) ? 'queue' : 'duplicates';
    ?>
    <div class="wrap ll-audio-processor-wrap">
        <h1>Audio Processor</h1>
        <p>Process uploaded audio recordings with configurable noise reduction, loudness normalization, and silence trimming.</p>

        <?php if (!$has_recordings): ?>
            <div class="notice notice-info">
                <p>No unprocessed audio recordings found.</p>
            </div>
        <?php else: ?>
            <div class="ll-processing-options">
                <h3>Processing Options</h3>
                <label>
                    <input type="checkbox" id="ll-enable-trim" checked>
                    <span>Trim silence from start and end</span>
                </label>
                <label>
                    <input type="checkbox" id="ll-enable-noise" checked>
                    <span>Apply noise reduction</span>
                </label>
                <label>
                    <input type="checkbox" id="ll-enable-loudness" checked>
                    <span>Normalize loudness</span>
                </label>
            </div>

            <div class="ll-processor-controls">
                <button id="ll-select-all" class="button">Select All</button>
                <button id="ll-deselect-all" class="button">Deselect All</button>
                <button id="ll-process-selected" class="button button-primary" disabled>
                    Process Selected (<span id="ll-selected-count">0</span>)
                </button>
                <button id="ll-delete-selected" class="ll-btn-danger" type="button" disabled>
                    <span class="ll-btn-label">Delete Selected</span> (<span id="ll-delete-selected-count">0</span>)
                </button>
            </div>

            <div id="ll-processor-status" class="ll-processor-status" style="display:none;">
                <div class="ll-progress-bar">
                    <div class="ll-progress-fill" style="width: 0%"></div>
                </div>
                <p class="ll-status-text">Processing...</p>
            </div>

            <div id="ll-save-progress-overlay" class="ll-save-progress-overlay" hidden aria-hidden="true">
                <div class="ll-save-progress-panel" role="status" aria-live="assertive" aria-atomic="true">
                    <h2 class="ll-save-progress-title"><?php echo esc_html__('Saving Processed Audio', 'll-tools-text-domain'); ?></h2>
                    <p id="ll-save-progress-current" class="ll-save-progress-current"><?php echo esc_html__('Preparing uploads...', 'll-tools-text-domain'); ?></p>
                    <div class="ll-progress-bar ll-save-progress-bar" aria-hidden="true">
                        <div id="ll-save-progress-fill" class="ll-progress-fill" style="width: 0%"></div>
                    </div>
                    <p id="ll-save-progress-count" class="ll-save-progress-count">0 / 0</p>
                    <p class="ll-save-progress-note">
                        <?php echo esc_html__('Keep this page open until saving is complete. Navigating away will interrupt remaining uploads.', 'll-tools-text-domain'); ?>
                    </p>
                </div>
            </div>

            <div class="ll-audio-processor-tabs" role="tablist" data-initial-tab="<?php echo esc_attr($active_tab); ?>">
                <button
                    type="button"
                    class="ll-audio-processor-tab <?php echo $active_tab === 'queue' ? 'is-active' : ''; ?>"
                    data-tab="queue"
                    role="tab"
                    aria-selected="<?php echo $active_tab === 'queue' ? 'true' : 'false'; ?>"
                    aria-controls="ll-recordings-queue"
                >
                    <span class="ll-tab-label"><?php echo esc_html__('Queue', 'll-tools-text-domain'); ?></span>
                    <span class="ll-tab-count" data-tab-count="queue"><?php echo esc_html(count($queue_recordings)); ?></span>
                </button>
                <button
                    type="button"
                    class="ll-audio-processor-tab <?php echo $active_tab === 'duplicates' ? 'is-active' : ''; ?>"
                    data-tab="duplicates"
                    role="tab"
                    aria-selected="<?php echo $active_tab === 'duplicates' ? 'true' : 'false'; ?>"
                    aria-controls="ll-recordings-duplicates"
                >
                    <span class="ll-tab-label"><?php echo esc_html__('Duplicates', 'll-tools-text-domain'); ?></span>
                    <span class="ll-tab-count" data-tab-count="duplicates"><?php echo esc_html(count($duplicate_recordings)); ?></span>
                </button>
            </div>

            <div
                id="ll-recordings-queue"
                class="ll-recordings-list <?php echo $active_tab === 'queue' ? 'is-active' : ''; ?>"
                data-tab="queue"
                role="tabpanel"
                aria-hidden="<?php echo $active_tab === 'queue' ? 'false' : 'true'; ?>"
            >
                <?php if (empty($queue_recordings)): ?>
                    <div class="notice notice-info ll-recordings-empty">
                        <p><?php echo esc_html__('No unique recordings in the queue. Check the duplicates tab for additional recordings.', 'll-tools-text-domain'); ?></p>
                    </div>
                <?php else: ?>
                    <?php foreach ($queue_recordings as $recording): ?>
                        <?php ll_render_audio_processor_recording_item($recording); ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div
                id="ll-recordings-duplicates"
                class="ll-recordings-list <?php echo $active_tab === 'duplicates' ? 'is-active' : ''; ?>"
                data-tab="duplicates"
                role="tabpanel"
                aria-hidden="<?php echo $active_tab === 'duplicates' ? 'false' : 'true'; ?>"
            >
                <?php if (!empty($duplicate_recordings)): ?>
                    <div class="ll-duplicate-note">
                        <?php echo esc_html__('Duplicates are hidden so you can process one recording per word and recording type first.', 'll-tools-text-domain'); ?>
                    </div>
                    <?php foreach ($duplicate_recordings as $recording): ?>
                        <?php ll_render_audio_processor_recording_item($recording, isset($recording['duplicateReason']) ? $recording['duplicateReason'] : ''); ?>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="notice notice-info ll-recordings-empty">
                        <p><?php echo esc_html__('No duplicates found.', 'll-tools-text-domain'); ?></p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Review Interface (shown after processing) -->
            <div id="ll-review-interface" class="ll-review-interface">
                <h2>Review Processed Audio</h2>
                <div id="ll-review-files-container"></div>
                <div class="ll-review-actions">
                    <button id="ll-save-all" class="ll-btn-save-all">Save All Changes</button>
                    <button id="ll-delete-all-review" class="button button-link-delete">Delete All</button>
                    <button id="ll-cancel-review" class="ll-btn-cancel">Cancel</button>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * AJAX handler to save processed audio file
 */
add_action('wp_ajax_ll_save_processed_audio', 'll_save_processed_audio_handler');

function ll_save_processed_audio_handler() {
    check_ajax_referer('ll_audio_processor', 'nonce');

    if (!current_user_can('view_ll_tools')) {
        wp_send_json_error('Permission denied');
    }

    $audio_post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
    $recording_type = isset($_POST['recording_type']) ? sanitize_text_field($_POST['recording_type']) : '';

    if (!$audio_post_id || !isset($_FILES['audio'])) {
        wp_send_json_error('Missing required data');
    }

    $audio_post = get_post($audio_post_id);
    if (!$audio_post || $audio_post->post_type !== 'word_audio') {
        wp_send_json_error('Invalid audio post');
    }

    $parent_word_id = wp_get_post_parent_id($audio_post_id);
    if (!$parent_word_id) {
        wp_send_json_error('No parent word post found');
    }

    $parent_word = get_post($parent_word_id);
    if (!$parent_word || $parent_word->post_type !== 'words') {
        wp_send_json_error('Invalid parent word post');
    }

    $file = $_FILES['audio'];
    $upload_dir = wp_upload_dir();

    $title = sanitize_file_name($parent_word->post_title);

    // Get recording type (selected override falls back to current term) to make filename unique
    $existing_recording_types = wp_get_post_terms($audio_post_id, 'recording_type', ['fields' => 'slugs']);
    $type_for_filename = $recording_type ?: (!is_wp_error($existing_recording_types) && !empty($existing_recording_types) ? $existing_recording_types[0] : '');
    $type_suffix = $type_for_filename ? '_' . $type_for_filename : '';

    // Include audio_post_id to ensure absolute uniqueness
    $filename = $title . $type_suffix . '_' . $audio_post_id . '_' . time() . '.mp3';
    $filepath = trailingslashit($upload_dir['path']) . $filename;

    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        wp_send_json_error('Failed to save file');
    }

    $relative_path = str_replace(
        wp_normalize_path(untrailingslashit(ABSPATH)),
        '',
        wp_normalize_path($filepath)
    );

    // Update this specific word_audio post
    update_post_meta($audio_post_id, 'audio_file_path', $relative_path);
    delete_post_meta($audio_post_id, '_ll_needs_audio_processing');
    update_post_meta($audio_post_id, '_ll_processed_audio_date', current_time('mysql'));
    delete_post_meta($audio_post_id, '_ll_needs_audio_review');

    if (!empty($recording_type)) {
        wp_set_object_terms($audio_post_id, $recording_type, 'recording_type');
    }

    wp_update_post([
        'ID'          => $audio_post_id,
        'post_status' => 'publish',
    ]);

    // Also publish the parent word post if it's still a draft
    if ($parent_word->post_status === 'draft') {
        wp_update_post([
            'ID'          => $parent_word_id,
            'post_status' => 'publish',
        ]);
    }

    wp_send_json_success([
        'message' => 'Audio processed and published successfully',
        'file_path' => $relative_path,
        'audio_post_id' => $audio_post_id,
        'recording_type' => $recording_type,
    ]);
}

add_action('wp_ajax_ll_audio_processor_update_word_title', 'll_audio_processor_update_word_title_handler');

function ll_audio_processor_update_word_title_handler() {
    check_ajax_referer('ll_audio_processor', 'nonce');

    if (!current_user_can('view_ll_tools')) {
        wp_send_json_error(__('Permission denied', 'll-tools-text-domain'));
    }

    $word_id = isset($_POST['word_id']) ? intval($_POST['word_id']) : 0;
    $title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';

    if (!$word_id || $title === '') {
        wp_send_json_error(__('Missing required data', 'll-tools-text-domain'));
    }

    $word_post = get_post($word_id);
    if (!$word_post || $word_post->post_type !== 'words') {
        wp_send_json_error(__('Invalid word post', 'll-tools-text-domain'));
    }

    if (!current_user_can('edit_post', $word_id)) {
        wp_send_json_error(__('Insufficient permissions to edit this word.', 'll-tools-text-domain'));
    }

    $updated = wp_update_post([
        'ID' => $word_id,
        'post_title' => $title,
    ], true);

    if (is_wp_error($updated)) {
        wp_send_json_error(__('Could not update title.', 'll-tools-text-domain'));
    }

    wp_send_json_success([
        'word_id' => $word_id,
        'title' => get_the_title($word_id),
    ]);
}

/**
 * AJAX handler to delete an audio recording
 */
add_action('wp_ajax_ll_delete_audio_recording', 'll_delete_audio_recording_handler');

function ll_delete_audio_recording_handler() {
    check_ajax_referer('ll_audio_processor', 'nonce');

    if (!current_user_can('view_ll_tools')) {
        wp_send_json_error('Permission denied');
    }

    $audio_post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;

    if (!$audio_post_id) {
        wp_send_json_error('Invalid post ID');
    }

    $audio_post = get_post($audio_post_id);
    if (!$audio_post || $audio_post->post_type !== 'word_audio') {
        wp_send_json_error('Invalid audio post');
    }

    // Resolve parent word before deletion
    $parent_word_id = (int) $audio_post->post_parent;

    // Delete the audio file from filesystem
    $audio_file_path = get_post_meta($audio_post_id, 'audio_file_path', true);
    if ($audio_file_path) {
        $full_path = ABSPATH . ltrim($audio_file_path, '/');
        if (file_exists($full_path)) {
            @unlink($full_path);
        }
    }

    // Delete the post
    $deleted = wp_delete_post($audio_post_id, true);

    if ($deleted) {
        // If parent exists, and it now has no published audio posts, set it to draft and clean legacy meta
        if ($parent_word_id) {
            $remaining = get_posts([
                'post_type'      => 'word_audio',
                'post_parent'    => $parent_word_id,
                'post_status'    => 'publish',
                'posts_per_page' => 1,
                'fields'         => 'ids',
            ]);
            if (empty($remaining)) {
                $parent = get_post($parent_word_id);
                if ($parent && $parent->post_status === 'publish') {
                    wp_update_post([
                        'ID'          => $parent_word_id,
                        'post_status' => 'draft',
                    ]);
                }
                // Remove legacy meta to prevent stale fallbacks elsewhere
                delete_post_meta($parent_word_id, 'word_audio_file');
            }
        }

        wp_send_json_success(['message' => 'Audio recording deleted']);
    } else {
        wp_send_json_error('Failed to delete audio recording');
    }
}

/**
 * Show admin notice if there are unprocessed recordings
 */
add_action('admin_notices', 'll_audio_processor_admin_notice');

function ll_audio_processor_admin_notice() {
    if (!current_user_can('view_ll_tools')) {
        return;
    }

    $screen = get_current_screen();
    if ($screen && $screen->id === 'tools_page_ll-audio-processor') {
        return;
    }

    $args = [
        'post_type' => 'word_audio',
        'post_status' => ['publish', 'draft'],
        'posts_per_page' => 1,
        'meta_query' => [
            [
                'key' => '_ll_needs_audio_processing',
                'value' => '1',
                'compare' => '='
            ]
        ],
        'fields' => 'ids'
    ];

    $query = new WP_Query($args);
    $unprocessed_count = $query->found_posts;
    wp_reset_postdata();

    if ($unprocessed_count > 0) {
        $url = admin_url('tools.php?page=ll-audio-processor');
        printf(
            '<div class="notice notice-warning is-dismissible"><p><strong>Audio Processor:</strong> You have %d unprocessed audio recording%s. <a href="%s">Process them now</a></p></div>',
            $unprocessed_count,
            $unprocessed_count === 1 ? '' : 's',
            esc_url($url)
        );
    }
}
