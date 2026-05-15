<?php
// /templates/vocab-lesson-template.php
if (!defined('WPINC')) { die; }

$ll_vocab_lesson_access_denied = false;
if (is_singular('ll_vocab_lesson') && function_exists('ll_tools_user_can_view_wordset')) {
    $preflight_post_id = (int) get_queried_object_id();
    if ($preflight_post_id > 0) {
        $preflight_wordset_id = (int) get_post_meta($preflight_post_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, true);
        if ($preflight_wordset_id > 0 && !ll_tools_user_can_view_wordset($preflight_wordset_id)) {
            $ll_vocab_lesson_access_denied = true;
            $wp_query = $GLOBALS['wp_query'] ?? null;
            if ($wp_query instanceof WP_Query) {
                $wp_query->set_404();
            } elseif (is_object($wp_query)) {
                $wp_query->is_404 = true;
            }
            status_header(404);
            nocache_headers();
        }
    }
}

$ll_vocab_lesson_print_requested = function_exists('ll_tools_is_vocab_lesson_print_request')
    && ll_tools_is_vocab_lesson_print_request();
if ($ll_vocab_lesson_print_requested) {
    $print_post_id = (int) get_queried_object_id();
    $print_post = ($print_post_id > 0) ? get_post($print_post_id) : null;
    $print_wordset_id = ($print_post_id > 0) ? (int) get_post_meta($print_post_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, true) : 0;
    $print_category_id = ($print_post_id > 0) ? (int) get_post_meta($print_post_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, true) : 0;
    $print_wordset = ($print_wordset_id > 0) ? get_term($print_wordset_id, 'wordset') : null;
    $print_category = ($print_category_id > 0) ? get_term($print_category_id, 'word-category') : null;
    $print_display_name = ($print_category instanceof WP_Term && !is_wp_error($print_category) && function_exists('ll_tools_get_category_display_name'))
        ? ll_tools_get_category_display_name($print_category)
        : (($print_category instanceof WP_Term && !is_wp_error($print_category)) ? $print_category->name : (($print_post instanceof WP_Post) ? get_the_title($print_post) : ''));
    $print_settings = function_exists('ll_tools_get_vocab_lesson_print_request_settings')
        ? ll_tools_get_vocab_lesson_print_request_settings()
        : [
            'show_text' => false,
            'show_translations' => false,
            'auto_print' => false,
        ];
    $print_allowed = !$ll_vocab_lesson_access_denied
        && ($print_post instanceof WP_Post)
        && $print_post->post_type === 'll_vocab_lesson'
        && (
            function_exists('ll_tools_vocab_lesson_print_view_is_available')
                ? ll_tools_vocab_lesson_print_view_is_available($print_wordset_id, $print_category)
                : true
        );
    $print_items = ($print_allowed && function_exists('ll_tools_get_vocab_lesson_print_items'))
        ? ll_tools_get_vocab_lesson_print_items($print_wordset_id, $print_category_id)
        : [];

    status_header($print_allowed ? 200 : 404);
    nocache_headers();

    ll_tools_render_template('vocab-lesson-print-template.php', [
        'lesson'                => $print_post,
        'lesson_id'             => $print_post_id,
        'wordset'               => $print_wordset,
        'wordset_id'            => $print_wordset_id,
        'category'              => $print_category,
        'category_id'           => $print_category_id,
        'display_name'          => $print_display_name,
        'print_items'           => $print_items,
        'print_settings'        => $print_settings,
        'print_request_allowed' => $print_allowed,
        'print_error_status'    => 404,
        'auto_print'            => $print_allowed && !empty($print_items) && !empty($print_settings['auto_print']),
    ]);
    return;
}

get_header();

if ($ll_vocab_lesson_access_denied) {
    echo '<main class="ll-vocab-lesson-page"><div class="ll-wordset-empty">';
    echo esc_html__('Lesson not found.', 'll-tools-text-domain');
    echo '</div></main>';
    get_footer();
    return;
}

if (have_posts()) {
    the_post();
    $post_id = get_the_ID();
    $wordset_id = (int) get_post_meta($post_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, true);
    $category_id = (int) get_post_meta($post_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, true);

    $wordset = $wordset_id ? get_term($wordset_id, 'wordset') : null;
    $category = $category_id ? get_term($category_id, 'word-category') : null;
    $can_edit_words = function_exists('ll_tools_user_can_edit_vocab_words')
        ? ll_tools_user_can_edit_vocab_words($wordset_id)
        : (is_user_logged_in() && current_user_can('view_ll_tools'));
    $can_transcribe = $can_edit_words
        && function_exists('ll_tools_can_transcribe_recordings')
        && ll_tools_can_transcribe_recordings([$wordset_id]);

    $display_name = '';
    if ($category && !is_wp_error($category)) {
        $display_name = function_exists('ll_tools_get_category_display_name')
            ? ll_tools_get_category_display_name($category)
            : $category->name;
    }
    if ($display_name === '') {
        $display_name = get_the_title();
    }

    $can_edit_category_title = $category_id > 0
        && function_exists('ll_tools_user_can_edit_vocab_lesson_title')
        && ll_tools_user_can_edit_vocab_lesson_title($category_id);
    $can_manage_category_settings = $category_id > 0
        && function_exists('ll_tools_user_can_manage_vocab_lesson_category_settings')
        && ll_tools_user_can_manage_vocab_lesson_category_settings($category_id, $wordset_id);
    $title_edit_context = ($can_edit_category_title && function_exists('ll_tools_get_vocab_lesson_category_title_edit_target'))
        ? ll_tools_get_vocab_lesson_category_title_edit_target($category)
        : [
            'field' => 'name',
            'value' => $display_name,
            'display_name' => $display_name,
        ];
    $title_edit_field = isset($title_edit_context['field']) ? (string) $title_edit_context['field'] : 'name';
    $title_edit_value = isset($title_edit_context['value']) ? (string) $title_edit_context['value'] : $display_name;
    if ($title_edit_value === '') {
        $title_edit_value = $display_name;
    }
    $title_edit_nonce = $can_edit_category_title ? wp_create_nonce('ll_vocab_lesson_title_' . $post_id) : '';
    $title_input_id = 'll-vocab-lesson-title-input-' . $post_id . '-' . $category_id;
    $title_form_id = 'll-vocab-lesson-title-form-' . $post_id . '-' . $category_id;
    $category_settings_notice = function_exists('ll_tools_get_vocab_lesson_category_settings_notice')
        ? ll_tools_get_vocab_lesson_category_settings_notice()
        : null;
    $category_settings_nonce = $can_manage_category_settings ? wp_create_nonce('ll_vocab_lesson_category_settings_' . $post_id) : '';
    $category_settings_panel = ($can_manage_category_settings && function_exists('ll_tools_get_vocab_lesson_category_settings_panel_data'))
        ? ll_tools_get_vocab_lesson_category_settings_panel_data($category, $wordset_id)
        : [];
    $category_split_url = ($can_manage_category_settings && function_exists('ll_tools_get_vocab_lesson_split_category_url'))
        ? ll_tools_get_vocab_lesson_split_category_url((int) $wordset_id, (int) $category_id, (int) $post_id)
        : '';

    $wordset_name = ($wordset && !is_wp_error($wordset)) ? $wordset->name : '';
    $wordset_slug = ($wordset && !is_wp_error($wordset)) ? $wordset->slug : '';
    $wordset_url = ($wordset instanceof WP_Term && !is_wp_error($wordset) && function_exists('ll_tools_get_wordset_page_view_url'))
        ? (string) ll_tools_get_wordset_page_view_url($wordset)
        : (($wordset_slug !== '') ? trailingslashit(home_url($wordset_slug)) : '');
    $print_view_available = function_exists('ll_tools_vocab_lesson_print_view_is_available')
        ? ll_tools_vocab_lesson_print_view_is_available($wordset_id, $category)
        : true;
    $print_form_action = ($print_view_available && $post_id > 0)
        ? get_permalink($post_id)
        : '';
    $has_print_settings = is_string($print_form_action) && $print_form_action !== '';
    $category_slug = ($category && !is_wp_error($category)) ? $category->slug : '';
    $category_name = ($category && !is_wp_error($category)) ? $category->name : $display_name;
    $embed_base = ($category_slug !== '') ? home_url('/embed/' . $category_slug) : '';
    $related_content_lessons = (
        $wordset_id > 0
        && $category_id > 0
        && function_exists('ll_tools_get_content_lessons_for_vocab_lesson')
    )
        ? ll_tools_get_content_lessons_for_vocab_lesson($wordset_id, $category_id)
        : [];
    $mode_ui = function_exists('ll_flashcards_get_mode_ui_config') ? ll_flashcards_get_mode_ui_config() : [];
    $mode_labels = [
        'practice'  => __('Practice', 'll-tools-text-domain'),
        'learning'  => __('Learn', 'll-tools-text-domain'),
        'self-check' => __('Self check', 'll-tools-text-domain'),
        'gender'    => __('Gender', 'll-tools-text-domain'),
        'listening' => __('Listen', 'll-tools-text-domain'),
    ];
    $lesson_quiz_config = ($category instanceof WP_Term && function_exists('ll_tools_get_category_quiz_config'))
        ? ll_tools_get_category_quiz_config($category)
        : [];
    $lesson_learning_supported = !array_key_exists('learning_supported', $lesson_quiz_config) || !empty($lesson_quiz_config['learning_supported']);
    $lesson_self_check_supported = !array_key_exists('self_check_supported', $lesson_quiz_config) || !empty($lesson_quiz_config['self_check_supported']);
    $lesson_prompt_card_posts = [];
    if (
        $wordset_id > 0
        && $category instanceof WP_Term
        && !is_wp_error($category)
        && function_exists('ll_tools_get_vocab_lesson_prompt_card_posts')
    ) {
        $lesson_prompt_card_posts = ll_tools_get_vocab_lesson_prompt_card_posts($wordset_id, $category, true);
    }
    $lesson_uses_prompt_cards = !empty($lesson_prompt_card_posts);
    $can_add_lesson_word = $can_edit_words && $wordset_id > 0 && $category_id > 0 && !$lesson_uses_prompt_cards;
    $lesson_prereq_editor = [
        'show' => false,
        'options_json' => '[]',
        'selected_json' => '[]',
        'blocked_json' => '[]',
        'current_level' => null,
        'has_cycle' => false,
    ];
    if ($can_manage_category_settings
        && function_exists('ll_tools_wordset_get_category_ordering_mode')
        && function_exists('ll_tools_wordset_get_admin_category_ordering_rows')
        && ll_tools_wordset_get_category_ordering_mode($wordset_id) === 'prerequisite'
    ) {
        $ordering_rows = ll_tools_wordset_get_admin_category_ordering_rows($wordset_id);
        $ordering_ids = function_exists('ll_tools_wordset_normalize_category_id_list')
            ? ll_tools_wordset_normalize_category_id_list(wp_list_pluck((array) $ordering_rows, 'id'))
            : [];

        if (!empty($ordering_ids) && in_array($category_id, $ordering_ids, true)) {
            $ordering_label_map = [];
            foreach ((array) $ordering_rows as $ordering_row) {
                if (!is_array($ordering_row)) {
                    continue;
                }
                $row_id = (int) ($ordering_row['id'] ?? 0);
                if ($row_id <= 0) {
                    continue;
                }
                $ordering_label_map[$row_id] = (string) ($ordering_row['name'] ?? (string) $row_id);
            }

            $prereq_map = function_exists('ll_tools_wordset_get_category_prereq_map')
                ? ll_tools_wordset_get_category_prereq_map($wordset_id, $ordering_ids)
                : [];
            $prereq_levels = function_exists('ll_tools_wordset_calculate_prereq_levels')
                ? ll_tools_wordset_calculate_prereq_levels($ordering_ids, $prereq_map)
                : [
                    'has_cycle' => false,
                    'levels' => [],
                ];

            $selected_prereq_ids = array_map('intval', (array) ($prereq_map[$category_id] ?? []));
            $selected_prereq_lookup = [];
            foreach ($selected_prereq_ids as $selected_prereq_id) {
                if ($selected_prereq_id > 0) {
                    $selected_prereq_lookup[$selected_prereq_id] = true;
                }
            }
            $blocked_prereq_ids = function_exists('ll_tools_wordset_get_blocked_prereq_ids_for_category') && empty($prereq_levels['has_cycle'])
                ? ll_tools_wordset_get_blocked_prereq_ids_for_category($category_id, $ordering_ids, $prereq_map)
                : [];

            $prereq_option_rows = [];
            $prereq_selected_rows = [];
            foreach ($ordering_ids as $ordering_category_id) {
                $ordering_category_id = (int) $ordering_category_id;
                if ($ordering_category_id <= 0 || $ordering_category_id === $category_id) {
                    continue;
                }
                $option_row = [
                    'id' => $ordering_category_id,
                    'label' => (string) ($ordering_label_map[$ordering_category_id] ?? (string) $ordering_category_id),
                ];
                if (isset($prereq_levels['levels'][$ordering_category_id]) && empty($prereq_levels['has_cycle'])) {
                    $option_row['level'] = (int) $prereq_levels['levels'][$ordering_category_id];
                }
                $prereq_option_rows[] = $option_row;
                if (isset($selected_prereq_lookup[$ordering_category_id])) {
                    $prereq_selected_rows[] = $option_row;
                }
            }

            $lesson_prereq_editor['show'] = true;
            $lesson_prereq_editor['has_cycle'] = !empty($prereq_levels['has_cycle']);
            $lesson_prereq_editor['current_level'] = isset($prereq_levels['levels'][$category_id]) && empty($prereq_levels['has_cycle'])
                ? (int) $prereq_levels['levels'][$category_id]
                : null;
            $lesson_prereq_editor['options_json'] = wp_json_encode($prereq_option_rows);
            $lesson_prereq_editor['selected_json'] = wp_json_encode($prereq_selected_rows);
            $lesson_prereq_editor['blocked_json'] = wp_json_encode(array_values(array_map('intval', $blocked_prereq_ids)));
            if (!is_string($lesson_prereq_editor['options_json']) || $lesson_prereq_editor['options_json'] === '') {
                $lesson_prereq_editor['options_json'] = '[]';
            }
            if (!is_string($lesson_prereq_editor['selected_json']) || $lesson_prereq_editor['selected_json'] === '') {
                $lesson_prereq_editor['selected_json'] = '[]';
            }
            if (!is_string($lesson_prereq_editor['blocked_json']) || $lesson_prereq_editor['blocked_json'] === '') {
                $lesson_prereq_editor['blocked_json'] = '[]';
            }
        }
    }
    $lesson_prereq_input_id = 'll-vocab-lesson-prereq-input-' . $post_id . '-' . $category_id;
    $lesson_prereq_level_display = ($lesson_prereq_editor['current_level'] === null)
        ? esc_html__('—', 'll-tools-text-domain')
        : sprintf(
            /* translators: %d is the prerequisite level number for the current lesson category. */
            esc_html__('L%d', 'll-tools-text-domain'),
            (int) $lesson_prereq_editor['current_level']
        );
    $render_mode_icon = function (string $mode, string $fallback) use ($mode_ui): void {
        $cfg = (isset($mode_ui[$mode]) && is_array($mode_ui[$mode])) ? $mode_ui[$mode] : [];
        if (!empty($cfg['svg'])) {
            echo '<span class="ll-vocab-lesson-mode-icon" aria-hidden="true">' . $cfg['svg'] . '</span>';
            return;
        }
        $icon = !empty($cfg['icon']) ? $cfg['icon'] : $fallback;
        echo '<span class="ll-vocab-lesson-mode-icon" aria-hidden="true" data-emoji="' . esc_attr($icon) . '"></span>';
    };

    $gender_quiz_available = $wordset_id > 0
        && $category && !is_wp_error($category)
        && function_exists('ll_tools_vocab_lesson_category_supports_gender_mode')
        && ll_tools_vocab_lesson_category_supports_gender_mode($wordset_id, $category);

    $defer_grid = $wordset_id > 0
        && $category_id > 0
        && function_exists('ll_tools_word_grid_resolve_context')
        && function_exists('ll_tools_word_grid_enqueue_frontend_assets_for_context')
        && function_exists('ll_tools_word_grid_get_shell_spec')
        && (bool) apply_filters('ll_tools_vocab_lesson_defer_grid', true, $post_id, $wordset_id, $category_id);
    $grid_shell_spec = null;
    $grid_shell_nonce = '';
    $grid_cached_html = '';
    $render_html_attributes = static function (array $attributes): string {
        $parts = [];
        foreach ($attributes as $name => $value) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }
            if ($value === '') {
                $parts[] = esc_attr($name);
                continue;
            }
            $parts[] = esc_attr($name) . '="' . esc_attr((string) $value) . '"';
        }
        return implode(' ', $parts);
    };
    if ($defer_grid || $can_edit_category_title || $can_manage_category_settings || $has_print_settings) {
        if ($defer_grid) {
            $grid_context = ll_tools_word_grid_resolve_context([
                'category' => $category_slug,
                'wordset' => $wordset_slug,
                'deepest_only' => true,
            ]);
            ll_tools_word_grid_enqueue_frontend_assets_for_context($grid_context);
        }
        $lesson_page_script_deps = ['jquery'];
        if ($defer_grid) {
            $lesson_page_script_deps[] = 'll-tools-word-grid';
        }
        ll_enqueue_asset_by_timestamp('/js/vocab-lesson-page.js', 'll-tools-vocab-lesson-page', $lesson_page_script_deps, true);
        wp_localize_script('ll-tools-vocab-lesson-page', 'llToolsVocabLessonData', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'grid' => [
                'action' => 'll_tools_get_vocab_lesson_grid',
                'enabled' => $defer_grid,
                'i18n' => [
                    'loading' => __('Loading lesson words...', 'll-tools-text-domain'),
                    'loaded' => __('Lesson words loaded.', 'll-tools-text-domain'),
                    'error' => __('Unable to load this lesson right now.', 'll-tools-text-domain'),
                    'retry' => __('Retry', 'll-tools-text-domain'),
                ],
            ],
            'titleEditor' => [
                'enabled' => $can_edit_category_title,
                'i18n' => [
                    'empty' => __('Enter a category title.', 'll-tools-text-domain'),
                    'saving' => __('Saving...', 'll-tools-text-domain'),
                    'saved' => __('Category title saved.', 'll-tools-text-domain'),
                    'error' => __('Unable to save this category title right now.', 'll-tools-text-domain'),
                ],
            ],
            'categorySettings' => [
                'enabled' => $can_manage_category_settings,
                'action' => 'll_tools_save_vocab_lesson_category_settings',
                'i18n' => [
                    'saving' => __('Saving changes...', 'll-tools-text-domain'),
                    'saved' => __('Changes saved.', 'll-tools-text-domain'),
                    'error' => __('Unable to save category settings right now.', 'll-tools-text-domain'),
                ],
            ],
        ]);
        if ($defer_grid) {
            $grid_shell_spec = ll_tools_word_grid_get_shell_spec($grid_context);
            if ($lesson_uses_prompt_cards) {
                $prompt_shell_count = max(1, min(3, count($lesson_prompt_card_posts)));
                $grid_shell_spec['prompt_card_shell'] = true;
                $grid_shell_spec['cards'] = array_fill(0, $prompt_shell_count, [
                    'media_aspect_ratio' => '4 / 3',
                    'title_width' => '72%',
                    'recording_count' => 2,
                ]);
                $grid_shell_spec['show_media'] = true;
                $grid_shell_spec['show_title'] = false;
                $grid_shell_attributes = (array) ($grid_shell_spec['attributes'] ?? []);
                $grid_shell_attributes['class'] = trim((string) ($grid_shell_attributes['class'] ?? 'word-grid ll-word-grid') . ' ll-vocab-prompt-card-grid ll-vocab-prompt-card-grid--skeleton');
                $grid_shell_attributes['data-ll-prompt-card-lesson-grid'] = '1';
                $grid_shell_spec['attributes'] = $grid_shell_attributes;
            }
            if (function_exists('ll_tools_vocab_lesson_grid_public_cache_get')) {
                $cached_grid_html = ll_tools_vocab_lesson_grid_public_cache_get($post_id, $wordset_id, $category_id);
                if (is_string($cached_grid_html) && trim($cached_grid_html) !== '') {
                    $grid_cached_html = $cached_grid_html;
                }
            }
            $grid_shell_nonce = wp_create_nonce('ll_vocab_lesson_grid_' . $post_id);
        }
    }
    ?>
    <main class="ll-vocab-lesson-page" data-ll-vocab-lesson>
        <header class="ll-vocab-lesson-hero">
            <div class="ll-vocab-lesson-top-row">
                <?php if ($wordset_url !== '') : ?>
                    <a class="ll-vocab-lesson-back" href="<?php echo esc_url($wordset_url); ?>" aria-label="<?php echo esc_attr($wordset_name !== '' ? sprintf(__('Back to %s', 'll-tools-text-domain'), $wordset_name) : __('Back to Word Set', 'll-tools-text-domain')); ?>">
                        <span class="ll-vocab-lesson-back__icon" aria-hidden="true">
                            <svg viewBox="0 0 16 16" focusable="false" aria-hidden="true">
                                <path d="M9.8 3.2L5 8l4.8 4.8" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </span>
                        <span class="ll-vocab-lesson-back__label"><?php echo esc_html($wordset_name !== '' ? $wordset_name : __('Word Set', 'll-tools-text-domain')); ?></span>
                    </a>
                <?php endif; ?>
                <?php if (is_user_logged_in()) : ?>
                    <div class="ll-vocab-lesson-star-controls">
                        <button type="button" class="ll-vocab-lesson-star-toggle ll-study-btn tiny ghost ll-group-star" data-ll-word-grid-star-toggle aria-pressed="false">
                            <span class="ll-vocab-lesson-star-icon" aria-hidden="true">&#9734;</span>
                            <span class="ll-vocab-lesson-star-label"><?php echo esc_html__('Star all', 'll-tools-text-domain'); ?></span>
                        </button>
                        <?php if ($can_transcribe) : ?>
                            <div class="ll-vocab-lesson-transcribe" data-ll-transcribe-wrapper>
                                <button
                                    type="button"
                                    class="ll-vocab-lesson-transcribe-menu-trigger ll-tools-settings-button"
                                    data-ll-transcribe-menu-trigger
                                    aria-haspopup="true"
                                    aria-expanded="false"
                                    aria-controls="<?php echo esc_attr('ll-vocab-lesson-transcribe-menu-' . $post_id); ?>"
                                    aria-label="<?php echo esc_attr__('Transcription actions', 'll-tools-text-domain'); ?>">
                                    <span class="ll-vocab-lesson-transcribe-icons" aria-hidden="true">
                                        <span class="ll-vocab-lesson-transcribe-icon ll-vocab-lesson-transcribe-icon--bolt">
                                            <svg viewBox="0 0 20 20" focusable="false" aria-hidden="true">
                                                <path d="M11.5 2.5 4 11h4l-1 6.5L15.5 9h-4l0-6.5Z" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/>
                                            </svg>
                                        </span>
                                    </span>
                                    <span class="ll-vocab-lesson-transcribe-label"><?php echo esc_html__('STT', 'll-tools-text-domain'); ?></span>
                                    <span class="ll-vocab-lesson-transcribe-menu-caret" aria-hidden="true">
                                        <svg viewBox="0 0 12 12" focusable="false" aria-hidden="true">
                                            <path d="M3 4.5 6 7.5 9 4.5" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                        </svg>
                                    </span>
                                </button>
                                <div
                                    id="<?php echo esc_attr('ll-vocab-lesson-transcribe-menu-' . $post_id); ?>"
                                    class="ll-vocab-lesson-transcribe-menu"
                                    data-ll-transcribe-menu
                                    role="menu"
                                    aria-label="<?php echo esc_attr__('Transcription actions', 'll-tools-text-domain'); ?>"
                                    aria-hidden="true">
                                    <button type="button" class="ll-vocab-lesson-transcribe-menu-item ll-vocab-lesson-transcribe-btn ll-vocab-lesson-transcribe-btn--auto" data-ll-transcribe-recordings data-lesson-id="<?php echo esc_attr($post_id); ?>" role="menuitem" aria-label="<?php echo esc_attr__('Auto-transcribe missing recordings for this lesson', 'll-tools-text-domain'); ?>">
                                        <span class="ll-vocab-lesson-transcribe-icons" aria-hidden="true">
                                            <span class="ll-vocab-lesson-transcribe-icon ll-vocab-lesson-transcribe-icon--bolt">
                                                <svg viewBox="0 0 20 20" focusable="false" aria-hidden="true">
                                                    <path d="M11.5 2.5 4 11h4l-1 6.5L15.5 9h-4l0-6.5Z" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/>
                                                </svg>
                                            </span>
                                        </span>
                                        <span class="ll-vocab-lesson-transcribe-label"><?php echo esc_html__('Auto transcription', 'll-tools-text-domain'); ?></span>
                                    </button>
                                    <button type="button" class="ll-vocab-lesson-transcribe-menu-item ll-vocab-lesson-transcribe-btn ll-vocab-lesson-transcribe-btn--replace" data-ll-transcribe-replace data-lesson-id="<?php echo esc_attr($post_id); ?>" role="menuitem" aria-label="<?php echo esc_attr__('Replace transcription for this lesson', 'll-tools-text-domain'); ?>">
                                        <span class="ll-vocab-lesson-transcribe-icons" aria-hidden="true">
                                            <span class="ll-vocab-lesson-transcribe-icon ll-vocab-lesson-transcribe-icon--refresh">
                                                <svg viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                                                    <path d="M4 7h9a6 6 0 1 1 0 12h-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                                    <path d="M4 7l3-3M4 7l3 3" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                                </svg>
                                            </span>
                                        </span>
                                        <span class="ll-vocab-lesson-transcribe-label"><?php echo esc_html__('Replace', 'll-tools-text-domain'); ?></span>
                                    </button>
                                    <button type="button" class="ll-vocab-lesson-transcribe-menu-item ll-vocab-lesson-transcribe-btn ll-vocab-lesson-transcribe-btn--clear" data-ll-transcribe-clear data-lesson-id="<?php echo esc_attr($post_id); ?>" role="menuitem" aria-label="<?php echo esc_attr__('Clear transcription for this lesson', 'll-tools-text-domain'); ?>">
                                        <span class="ll-vocab-lesson-transcribe-icons" aria-hidden="true">
                                            <span class="ll-vocab-lesson-transcribe-icon ll-vocab-lesson-transcribe-icon--clear">
                                                <svg viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                                                    <path d="M3 6h18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                                    <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                                    <path d="M10 11v6M14 11v6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                                    <path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                                </svg>
                                            </span>
                                        </span>
                                        <span class="ll-vocab-lesson-transcribe-label"><?php echo esc_html__('Clear', 'll-tools-text-domain'); ?></span>
                                    </button>
                                    <button type="button" class="ll-vocab-lesson-transcribe-menu-item ll-vocab-lesson-transcribe-btn ll-vocab-lesson-transcribe-btn--cancel" data-ll-transcribe-cancel data-lesson-id="<?php echo esc_attr($post_id); ?>" role="menuitem" aria-label="<?php echo esc_attr__('Cancel transcription', 'll-tools-text-domain'); ?>" disabled>
                                        <span class="ll-vocab-lesson-transcribe-icons" aria-hidden="true">
                                            <span class="ll-vocab-lesson-transcribe-icon ll-vocab-lesson-transcribe-icon--cancel">
                                                <svg viewBox="0 0 20 20" focusable="false" aria-hidden="true">
                                                    <path d="M5.5 5.5 14.5 14.5M14.5 5.5 5.5 14.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
                                                </svg>
                                            </span>
                                        </span>
                                        <span class="ll-vocab-lesson-transcribe-label"><?php echo esc_html__('Cancel', 'll-tools-text-domain'); ?></span>
                                    </button>
                                </div>
                                <span class="ll-vocab-lesson-transcribe-status" data-ll-transcribe-status aria-live="polite"></span>
                            </div>
                        <?php endif; ?>
                        <?php if ($can_edit_words && $wordset_id > 0 && $category_id > 0) : ?>
                            <?php
                            $pos_terms = get_terms([
                                'taxonomy' => 'part_of_speech',
                                'hide_empty' => false,
                                'orderby' => 'name',
                                'order' => 'ASC',
                            ]);
                            if (is_wp_error($pos_terms)) {
                                $pos_terms = [];
                            }
                            $gender_enabled = function_exists('ll_tools_wordset_has_grammatical_gender')
                                ? ll_tools_wordset_has_grammatical_gender($wordset_id)
                                : false;
                            $gender_options = $gender_enabled && function_exists('ll_tools_wordset_get_gender_options')
                                ? ll_tools_wordset_get_gender_options($wordset_id)
                                : [];
                            $plurality_enabled = function_exists('ll_tools_wordset_has_plurality')
                                ? ll_tools_wordset_has_plurality($wordset_id)
                                : false;
                            $plurality_options = $plurality_enabled && function_exists('ll_tools_wordset_get_plurality_options')
                                ? ll_tools_wordset_get_plurality_options($wordset_id)
                                : [];
                            $verb_tense_enabled = function_exists('ll_tools_wordset_has_verb_tense')
                                ? ll_tools_wordset_has_verb_tense($wordset_id)
                                : false;
                            $verb_tense_options = $verb_tense_enabled && function_exists('ll_tools_wordset_get_verb_tense_options')
                                ? ll_tools_wordset_get_verb_tense_options($wordset_id)
                                : [];
                            $verb_mood_enabled = function_exists('ll_tools_wordset_has_verb_mood')
                                ? ll_tools_wordset_has_verb_mood($wordset_id)
                                : false;
                            $verb_mood_options = $verb_mood_enabled && function_exists('ll_tools_wordset_get_verb_mood_options')
                                ? ll_tools_wordset_get_verb_mood_options($wordset_id)
                                : [];
                            $bulk_pos_default = '';
                            $bulk_gender_default = '';
                            $bulk_plurality_default = '';
                            $bulk_verb_tense_default = '';
                            $bulk_verb_mood_default = '';
                            $bulk_undo_aria = esc_attr__('Undo last bulk change', 'll-tools-text-domain');
                            $bulk_undo_text = esc_html__('Undo', 'll-tools-text-domain');
                            $bulk_panel_title_id = 'll-vocab-lesson-bulk-title-' . $post_id . '-' . $category_id;
                            ?>
                            <div class="ll-vocab-lesson-bulk ll-tools-settings-control" data-ll-word-grid-bulk>
                                <button type="button" class="ll-vocab-lesson-bulk-button ll-tools-settings-button" aria-haspopup="true" aria-expanded="false" aria-label="<?php echo esc_attr__('Bulk edit', 'll-tools-text-domain'); ?>">
                                    <span class="mode-icon" aria-hidden="true">
                                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                            <path d="M4 20.5h4l10-10-4-4-10 10v4z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                            <path d="M13.5 6.5l4 4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                        </svg>
                                    </span>
                                    <span class="ll-vocab-lesson-bulk-label"><?php echo esc_html__('Bulk edit', 'll-tools-text-domain'); ?></span>
                                </button>
                                <div class="ll-vocab-lesson-bulk-panel ll-vocab-lesson-tool-modal ll-tools-settings-panel" role="dialog" aria-modal="true" aria-labelledby="<?php echo esc_attr($bulk_panel_title_id); ?>" aria-hidden="true">
                                    <div class="ll-vocab-lesson-tool-modal__header">
                                        <div class="ll-vocab-lesson-tool-modal__title" id="<?php echo esc_attr($bulk_panel_title_id); ?>"><?php echo esc_html__('Bulk edit', 'll-tools-text-domain'); ?></div>
                                        <button type="button" class="ll-vocab-lesson-tool-modal__close" data-ll-bulk-modal-close aria-label="<?php echo esc_attr__('Close bulk edit', 'll-tools-text-domain'); ?>">
                                            <span aria-hidden="true">x</span>
                                        </button>
                                    </div>
                                    <div class="ll-vocab-lesson-bulk-section">
                                        <div class="ll-vocab-lesson-bulk-heading-row">
                                            <div class="ll-vocab-lesson-bulk-heading"><?php echo esc_html__('Part of Speech', 'll-tools-text-domain'); ?></div>
                                            <div class="ll-vocab-lesson-bulk-heading-actions">
                                                <span class="ll-vocab-lesson-bulk-control-status" data-ll-bulk-control-status="pos" data-state="idle" role="status" aria-live="polite" hidden>
                                                    <span class="ll-vocab-lesson-bulk-control-status-icon" aria-hidden="true"></span>
                                                    <span class="ll-vocab-lesson-bulk-control-status-message" data-ll-bulk-control-status-message hidden></span>
                                                </span>
                                                <button type="button" class="ll-vocab-lesson-bulk-control-undo" data-ll-bulk-control-undo="pos" aria-label="<?php echo $bulk_undo_aria; ?>" title="<?php echo $bulk_undo_aria; ?>" hidden>
                                                    <span class="ll-vocab-lesson-bulk-control-undo-icon" aria-hidden="true">
                                                        <svg viewBox="0 0 20 20" focusable="false" aria-hidden="true">
                                                            <path d="M8 6 4.5 9.5 8 13" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
                                                            <path d="M5.2 9.5h5.3a4 4 0 1 1 0 8h-1.3" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
                                                        </svg>
                                                    </span>
                                                    <span class="screen-reader-text"><?php echo $bulk_undo_text; ?></span>
                                                </button>
                                            </div>
                                        </div>
                                        <div class="ll-vocab-lesson-bulk-controls" role="group" aria-label="<?php echo esc_attr__('Part of speech', 'll-tools-text-domain'); ?>">
                                            <select class="ll-vocab-lesson-bulk-select" data-ll-bulk-pos aria-label="<?php echo esc_attr__('Select part of speech', 'll-tools-text-domain'); ?>">
                                                <option value=""><?php echo esc_html__('Part of Speech', 'll-tools-text-domain'); ?></option>
                                                <?php $bulk_pos_found = false; ?>
                                                <?php foreach ($pos_terms as $term) : ?>
                                                    <?php if (!empty($term->slug)) : ?>
                                                        <?php if ((string) $term->slug === $bulk_pos_default) : ?>
                                                            <?php $bulk_pos_found = true; ?>
                                                        <?php endif; ?>
                                                        <option value="<?php echo esc_attr($term->slug); ?>" <?php selected((string) $term->slug, $bulk_pos_default); ?>><?php echo esc_html($term->name); ?></option>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                                <?php if ($bulk_pos_default !== '' && !$bulk_pos_found) : ?>
                                                    <option value="<?php echo esc_attr($bulk_pos_default); ?>" selected><?php echo esc_html($bulk_pos_default); ?></option>
                                                <?php endif; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <?php if ($gender_enabled) : ?>
                                        <div class="ll-vocab-lesson-bulk-section">
                                            <div class="ll-vocab-lesson-bulk-heading-row">
                                                <div class="ll-vocab-lesson-bulk-heading"><?php echo esc_html__('Gender', 'll-tools-text-domain'); ?></div>
                                                <div class="ll-vocab-lesson-bulk-heading-actions">
                                                    <span class="ll-vocab-lesson-bulk-control-status" data-ll-bulk-control-status="gender" data-state="idle" role="status" aria-live="polite" hidden>
                                                        <span class="ll-vocab-lesson-bulk-control-status-icon" aria-hidden="true"></span>
                                                        <span class="ll-vocab-lesson-bulk-control-status-message" data-ll-bulk-control-status-message hidden></span>
                                                    </span>
                                                    <button type="button" class="ll-vocab-lesson-bulk-control-undo" data-ll-bulk-control-undo="gender" aria-label="<?php echo $bulk_undo_aria; ?>" title="<?php echo $bulk_undo_aria; ?>" hidden>
                                                        <span class="ll-vocab-lesson-bulk-control-undo-icon" aria-hidden="true">
                                                            <svg viewBox="0 0 20 20" focusable="false" aria-hidden="true">
                                                                <path d="M8 6 4.5 9.5 8 13" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
                                                                <path d="M5.2 9.5h5.3a4 4 0 1 1 0 8h-1.3" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
                                                            </svg>
                                                        </span>
                                                        <span class="screen-reader-text"><?php echo $bulk_undo_text; ?></span>
                                                    </button>
                                                </div>
                                            </div>
                                            <div class="ll-vocab-lesson-bulk-controls" role="group" aria-label="<?php echo esc_attr__('Noun gender', 'll-tools-text-domain'); ?>">
                                                <select class="ll-vocab-lesson-bulk-select" data-ll-bulk-gender aria-label="<?php echo esc_attr__('Select gender', 'll-tools-text-domain'); ?>">
                                                    <option value=""><?php echo esc_html__('Gender', 'll-tools-text-domain'); ?></option>
                                                    <?php $bulk_gender_found = false; ?>
                                                    <?php foreach ($gender_options as $option) : ?>
                                                        <?php if (!empty($option)) : ?>
                                                            <?php
                                                            $option_label = function_exists('ll_tools_wordset_format_gender_display_label')
                                                                ? ll_tools_wordset_format_gender_display_label($option)
                                                                : $option;
                                                            if ((string) $option === $bulk_gender_default) {
                                                                $bulk_gender_found = true;
                                                            }
                                                            ?>
                                                            <option value="<?php echo esc_attr($option); ?>" <?php selected((string) $option, $bulk_gender_default); ?>><?php echo esc_html($option_label); ?></option>
                                                        <?php endif; ?>
                                                    <?php endforeach; ?>
                                                    <?php if ($bulk_gender_default !== '' && !$bulk_gender_found) : ?>
                                                        <?php
                                                        $bulk_gender_fallback_label = function_exists('ll_tools_wordset_get_gender_label')
                                                            ? ll_tools_wordset_get_gender_label($wordset_id, $bulk_gender_default)
                                                            : $bulk_gender_default;
                                                        ?>
                                                        <option value="<?php echo esc_attr($bulk_gender_default); ?>" selected><?php echo esc_html($bulk_gender_fallback_label); ?></option>
                                                    <?php endif; ?>
                                                </select>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($plurality_enabled) : ?>
                                        <div class="ll-vocab-lesson-bulk-section">
                                            <div class="ll-vocab-lesson-bulk-heading-row">
                                                <div class="ll-vocab-lesson-bulk-heading"><?php echo esc_html__('Plurality', 'll-tools-text-domain'); ?></div>
                                                <div class="ll-vocab-lesson-bulk-heading-actions">
                                                    <span class="ll-vocab-lesson-bulk-control-status" data-ll-bulk-control-status="plurality" data-state="idle" role="status" aria-live="polite" hidden>
                                                        <span class="ll-vocab-lesson-bulk-control-status-icon" aria-hidden="true"></span>
                                                        <span class="ll-vocab-lesson-bulk-control-status-message" data-ll-bulk-control-status-message hidden></span>
                                                    </span>
                                                    <button type="button" class="ll-vocab-lesson-bulk-control-undo" data-ll-bulk-control-undo="plurality" aria-label="<?php echo $bulk_undo_aria; ?>" title="<?php echo $bulk_undo_aria; ?>" hidden>
                                                        <span class="ll-vocab-lesson-bulk-control-undo-icon" aria-hidden="true">
                                                            <svg viewBox="0 0 20 20" focusable="false" aria-hidden="true">
                                                                <path d="M8 6 4.5 9.5 8 13" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
                                                                <path d="M5.2 9.5h5.3a4 4 0 1 1 0 8h-1.3" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
                                                            </svg>
                                                        </span>
                                                        <span class="screen-reader-text"><?php echo $bulk_undo_text; ?></span>
                                                    </button>
                                                </div>
                                            </div>
                                            <div class="ll-vocab-lesson-bulk-controls" role="group" aria-label="<?php echo esc_attr__('Noun plurality', 'll-tools-text-domain'); ?>">
                                                <select class="ll-vocab-lesson-bulk-select" data-ll-bulk-plurality aria-label="<?php echo esc_attr__('Select plurality', 'll-tools-text-domain'); ?>">
                                                    <option value=""><?php echo esc_html__('Plurality', 'll-tools-text-domain'); ?></option>
                                                    <?php $bulk_plurality_found = false; ?>
                                                    <?php foreach ($plurality_options as $option) : ?>
                                                        <?php if (!empty($option)) : ?>
                                                            <?php if ((string) $option === $bulk_plurality_default) : ?>
                                                                <?php $bulk_plurality_found = true; ?>
                                                            <?php endif; ?>
                                                            <option value="<?php echo esc_attr($option); ?>" <?php selected((string) $option, $bulk_plurality_default); ?>><?php echo esc_html($option); ?></option>
                                                        <?php endif; ?>
                                                    <?php endforeach; ?>
                                                    <?php if ($bulk_plurality_default !== '' && !$bulk_plurality_found) : ?>
                                                        <?php
                                                        $bulk_plurality_fallback_label = function_exists('ll_tools_wordset_get_plurality_label')
                                                            ? ll_tools_wordset_get_plurality_label($wordset_id, $bulk_plurality_default)
                                                            : $bulk_plurality_default;
                                                        ?>
                                                        <option value="<?php echo esc_attr($bulk_plurality_default); ?>" selected><?php echo esc_html($bulk_plurality_fallback_label); ?></option>
                                                    <?php endif; ?>
                                                </select>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($verb_tense_enabled) : ?>
                                        <div class="ll-vocab-lesson-bulk-section">
                                            <div class="ll-vocab-lesson-bulk-heading-row">
                                                <div class="ll-vocab-lesson-bulk-heading"><?php echo esc_html__('Verb tense', 'll-tools-text-domain'); ?></div>
                                                <div class="ll-vocab-lesson-bulk-heading-actions">
                                                    <span class="ll-vocab-lesson-bulk-control-status" data-ll-bulk-control-status="verb-tense" data-state="idle" role="status" aria-live="polite" hidden>
                                                        <span class="ll-vocab-lesson-bulk-control-status-icon" aria-hidden="true"></span>
                                                        <span class="ll-vocab-lesson-bulk-control-status-message" data-ll-bulk-control-status-message hidden></span>
                                                    </span>
                                                    <button type="button" class="ll-vocab-lesson-bulk-control-undo" data-ll-bulk-control-undo="verb-tense" aria-label="<?php echo $bulk_undo_aria; ?>" title="<?php echo $bulk_undo_aria; ?>" hidden>
                                                        <span class="ll-vocab-lesson-bulk-control-undo-icon" aria-hidden="true">
                                                            <svg viewBox="0 0 20 20" focusable="false" aria-hidden="true">
                                                                <path d="M8 6 4.5 9.5 8 13" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
                                                                <path d="M5.2 9.5h5.3a4 4 0 1 1 0 8h-1.3" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
                                                            </svg>
                                                        </span>
                                                        <span class="screen-reader-text"><?php echo $bulk_undo_text; ?></span>
                                                    </button>
                                                </div>
                                            </div>
                                            <div class="ll-vocab-lesson-bulk-controls" role="group" aria-label="<?php echo esc_attr__('Verb tense', 'll-tools-text-domain'); ?>">
                                                <select class="ll-vocab-lesson-bulk-select" data-ll-bulk-verb-tense aria-label="<?php echo esc_attr__('Select verb tense', 'll-tools-text-domain'); ?>">
                                                    <option value=""><?php echo esc_html__('Verb tense', 'll-tools-text-domain'); ?></option>
                                                    <?php $bulk_verb_tense_found = false; ?>
                                                    <?php foreach ($verb_tense_options as $option) : ?>
                                                        <?php if (!empty($option)) : ?>
                                                            <?php if ((string) $option === $bulk_verb_tense_default) : ?>
                                                                <?php $bulk_verb_tense_found = true; ?>
                                                            <?php endif; ?>
                                                            <option value="<?php echo esc_attr($option); ?>" <?php selected((string) $option, $bulk_verb_tense_default); ?>><?php echo esc_html($option); ?></option>
                                                        <?php endif; ?>
                                                    <?php endforeach; ?>
                                                    <?php if ($bulk_verb_tense_default !== '' && !$bulk_verb_tense_found) : ?>
                                                        <?php
                                                        $bulk_verb_tense_fallback_label = function_exists('ll_tools_wordset_get_verb_tense_label')
                                                            ? ll_tools_wordset_get_verb_tense_label($wordset_id, $bulk_verb_tense_default)
                                                            : $bulk_verb_tense_default;
                                                        ?>
                                                        <option value="<?php echo esc_attr($bulk_verb_tense_default); ?>" selected><?php echo esc_html($bulk_verb_tense_fallback_label); ?></option>
                                                    <?php endif; ?>
                                                </select>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($verb_mood_enabled) : ?>
                                        <div class="ll-vocab-lesson-bulk-section">
                                            <div class="ll-vocab-lesson-bulk-heading-row">
                                                <div class="ll-vocab-lesson-bulk-heading"><?php echo esc_html__('Verb mood', 'll-tools-text-domain'); ?></div>
                                                <div class="ll-vocab-lesson-bulk-heading-actions">
                                                    <span class="ll-vocab-lesson-bulk-control-status" data-ll-bulk-control-status="verb-mood" data-state="idle" role="status" aria-live="polite" hidden>
                                                        <span class="ll-vocab-lesson-bulk-control-status-icon" aria-hidden="true"></span>
                                                        <span class="ll-vocab-lesson-bulk-control-status-message" data-ll-bulk-control-status-message hidden></span>
                                                    </span>
                                                    <button type="button" class="ll-vocab-lesson-bulk-control-undo" data-ll-bulk-control-undo="verb-mood" aria-label="<?php echo $bulk_undo_aria; ?>" title="<?php echo $bulk_undo_aria; ?>" hidden>
                                                        <span class="ll-vocab-lesson-bulk-control-undo-icon" aria-hidden="true">
                                                            <svg viewBox="0 0 20 20" focusable="false" aria-hidden="true">
                                                                <path d="M8 6 4.5 9.5 8 13" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
                                                                <path d="M5.2 9.5h5.3a4 4 0 1 1 0 8h-1.3" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
                                                            </svg>
                                                        </span>
                                                        <span class="screen-reader-text"><?php echo $bulk_undo_text; ?></span>
                                                    </button>
                                                </div>
                                            </div>
                                            <div class="ll-vocab-lesson-bulk-controls" role="group" aria-label="<?php echo esc_attr__('Verb mood', 'll-tools-text-domain'); ?>">
                                                <select class="ll-vocab-lesson-bulk-select" data-ll-bulk-verb-mood aria-label="<?php echo esc_attr__('Select verb mood', 'll-tools-text-domain'); ?>">
                                                    <option value=""><?php echo esc_html__('Verb mood', 'll-tools-text-domain'); ?></option>
                                                    <?php $bulk_verb_mood_found = false; ?>
                                                    <?php foreach ($verb_mood_options as $option) : ?>
                                                        <?php if (!empty($option)) : ?>
                                                            <?php if ((string) $option === $bulk_verb_mood_default) : ?>
                                                                <?php $bulk_verb_mood_found = true; ?>
                                                            <?php endif; ?>
                                                            <option value="<?php echo esc_attr($option); ?>" <?php selected((string) $option, $bulk_verb_mood_default); ?>><?php echo esc_html($option); ?></option>
                                                        <?php endif; ?>
                                                    <?php endforeach; ?>
                                                    <?php if ($bulk_verb_mood_default !== '' && !$bulk_verb_mood_found) : ?>
                                                        <?php
                                                        $bulk_verb_mood_fallback_label = function_exists('ll_tools_wordset_get_verb_mood_label')
                                                            ? ll_tools_wordset_get_verb_mood_label($wordset_id, $bulk_verb_mood_default)
                                                            : $bulk_verb_mood_default;
                                                        ?>
                                                        <option value="<?php echo esc_attr($bulk_verb_mood_default); ?>" selected><?php echo esc_html($bulk_verb_mood_fallback_label); ?></option>
                                                    <?php endif; ?>
                                                </select>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    <span class="ll-vocab-lesson-bulk-status" data-ll-bulk-status aria-live="polite"></span>
                                </div>
                            </div>
                        <?php endif; ?>
                        <?php if ($can_manage_category_settings && $wordset_id > 0 && $category_id > 0) : ?>
                            <?php
                            $category_panel_quiz_config = is_array($category_settings_panel['quiz_config'] ?? null)
                                ? $category_settings_panel['quiz_config']
                                : [
                                    'prompt_type' => 'audio',
                                    'option_type' => 'image',
                                ];
                            $category_panel_prompt_type = (string) ($category_panel_quiz_config['prompt_type'] ?? 'audio');
                            $category_panel_option_type = (string) ($category_panel_quiz_config['option_type'] ?? 'image');
                            $category_panel_presentation_label = function_exists('ll_tools_get_category_quiz_presentation_label')
                                ? ll_tools_get_category_quiz_presentation_label($category_panel_quiz_config)
                                : '';
                            $category_panel_visibility = (string) ($category_settings_panel['lesson_grid_text_visibility'] ?? 'inherit');
                            switch ($category_panel_visibility) {
                                case 'show':
                                    $category_panel_visibility_label = __('Text always visible', 'll-tools-text-domain');
                                    break;
                                case 'hide':
                                    $category_panel_visibility_label = __('Text hidden by default', 'll-tools-text-domain');
                                    break;
                                default:
                                    $category_panel_visibility_label = __('Text uses word set default', 'll-tools-text-domain');
                                    break;
                            }
                            $category_panel_enabled_games = array_fill_keys(array_values(array_map('strval', (array) ($category_settings_panel['enabled_games'] ?? []))), true);
                            $category_panel_game_definitions = is_array($category_settings_panel['game_definitions'] ?? null)
                                ? $category_settings_panel['game_definitions']
                                : [];
                            $category_panel_recording_type_terms = is_array($category_settings_panel['recording_type_terms'] ?? null)
                                ? $category_settings_panel['recording_type_terms']
                                : [];
                            $category_panel_recording_types = array_values(array_map('strval', (array) ($category_settings_panel['recording_types'] ?? [])));
                            $category_panel_recording_lookup = array_fill_keys($category_panel_recording_types, true);
                            $category_panel_recording_disabled = !empty($category_settings_panel['recording_disabled']);
                            $category_panel_recording_summary = $category_panel_recording_disabled
                                ? __('Recording off', 'll-tools-text-domain')
                                : sprintf(
                                    /* translators: %d is the number of enabled recording types for the category. */
                                    _n('%d recording type', '%d recording types', count($category_panel_recording_types), 'll-tools-text-domain'),
                                    count($category_panel_recording_types)
                                );
                            $category_panel_prompt_types = function_exists('ll_tools_get_quiz_prompt_types')
                                ? ll_tools_get_quiz_prompt_types()
                                : ['audio'];
                            $category_panel_option_types = function_exists('ll_tools_get_quiz_option_types')
                                ? ll_tools_get_quiz_option_types()
                                : ['image'];
                            $category_panel_lineup = is_array($category_settings_panel['lineup'] ?? null)
                                ? $category_settings_panel['lineup']
                                : [
                                    'direction' => 'auto',
                                    'word_ids' => [],
                                ];
                            $category_panel_lineup_direction = (string) ($category_panel_lineup['direction'] ?? 'auto');
                            $category_panel_lineup_items = is_array($category_settings_panel['lineup_items'] ?? null)
                                ? $category_settings_panel['lineup_items']
                                : [];
                            $category_panel_lineup_input_id = 'll-vocab-lesson-category-lineup-input-' . $post_id . '-' . $category_id;
                            $category_settings_title_id = 'll-vocab-lesson-category-settings-title-' . $post_id . '-' . $category_id;
                            ?>
                            <div class="ll-vocab-lesson-category-settings ll-tools-settings-control" data-ll-vocab-lesson-category-settings>
                                <button type="button" class="ll-vocab-lesson-category-settings-trigger ll-tools-settings-button" aria-haspopup="true" aria-expanded="false" aria-label="<?php echo esc_attr__('Category settings', 'll-tools-text-domain'); ?>">
                                    <span class="ll-vocab-lesson-category-settings-trigger-icon" aria-hidden="true">
                                        <svg viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                                            <path d="M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Z" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                            <path d="M19.4 12.98c.04-.32.06-.65.06-.98 0-.33-.02-.66-.06-.98l1.73-1.35a.5.5 0 0 0 .12-.64l-1.64-2.84a.5.5 0 0 0-.6-.22l-2.04.82a7.1 7.1 0 0 0-1.7-.98l-.26-2.17A.5.5 0 0 0 14.5 3h-5a.5.5 0 0 0-.5.43l-.26 2.17c-.6.24-1.17.55-1.7.93l-2.04-.82a.5.5 0 0 0-.6.22L2.76 8.58a.5.5 0 0 0 .12.64L4.6 10.57c-.04.32-.06.65-.06.98 0 .33.02.66.06.98l-1.73 1.35a.5.5 0 0 0-.12.64l1.64 2.84a.5.5 0 0 0 .6.22l2.04-.82c.53.38 1.1.69 1.7.93l.26 2.17a.5.5 0 0 0 .5.43h5a.5.5 0 0 0 .5-.43l.26-2.17c.6-.24 1.17-.55 1.7-.93l2.04.82a.5.5 0 0 0 .6-.22l1.64-2.84a.5.5 0 0 0-.12-.64l-1.73-1.35Z" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                        </svg>
                                    </span>
                                    <span class="ll-vocab-lesson-category-settings-trigger-label"><?php echo esc_html__('Category', 'll-tools-text-domain'); ?></span>
                                </button>
                                <form
                                    class="ll-tools-settings-panel ll-vocab-lesson-category-settings-panel ll-vocab-lesson-tool-modal"
                                    method="post"
                                    action="<?php echo esc_url(get_permalink($post_id)); ?>"
                                    role="dialog"
                                    aria-modal="true"
                                    aria-labelledby="<?php echo esc_attr($category_settings_title_id); ?>"
                                    aria-hidden="true">
                                    <input type="hidden" name="ll_vocab_lesson_category_settings_action" value="save" />
                                    <input type="hidden" name="ll_vocab_lesson_category_settings_lesson_id" value="<?php echo esc_attr((string) $post_id); ?>" />
                                    <input type="hidden" name="ll_vocab_lesson_category_settings_wordset_id" value="<?php echo esc_attr((string) $wordset_id); ?>" />
                                    <input type="hidden" name="ll_vocab_lesson_category_settings_category_id" value="<?php echo esc_attr((string) $category_id); ?>" />
                                    <input type="hidden" name="ll_vocab_lesson_category_settings_nonce" value="<?php echo esc_attr($category_settings_nonce); ?>" />
                                    <div class="ll-vocab-lesson-category-settings-panel__title-row">
                                        <div class="ll-vocab-lesson-category-settings-panel__title" id="<?php echo esc_attr($category_settings_title_id); ?>"><?php echo esc_html__('Category settings', 'll-tools-text-domain'); ?></div>
                                        <button type="button" class="ll-vocab-lesson-tool-modal__close" data-ll-category-settings-close aria-label="<?php echo esc_attr__('Close category settings', 'll-tools-text-domain'); ?>">
                                            <span aria-hidden="true">x</span>
                                        </button>
                                        <div class="ll-vocab-lesson-category-settings-summary">
                                            <?php if ($category_panel_presentation_label !== '') : ?>
                                                <span class="ll-vocab-lesson-category-settings-summary-pill"><?php echo esc_html($category_panel_presentation_label); ?></span>
                                            <?php endif; ?>
                                            <span class="ll-vocab-lesson-category-settings-summary-pill"><?php echo esc_html($category_panel_visibility_label); ?></span>
                                            <span class="ll-vocab-lesson-category-settings-summary-pill"><?php echo esc_html($category_panel_recording_summary); ?></span>
                                        </div>
                                    </div>

                                    <?php if ($category_split_url !== '') : ?>
                                        <div class="ll-vocab-lesson-category-settings-section ll-vocab-lesson-category-settings-section--tools">
                                            <div class="ll-vocab-lesson-category-settings-section__heading"><?php echo esc_html__('Tools', 'll-tools-text-domain'); ?></div>
                                            <a class="ll-vocab-lesson-category-settings-tool-link" href="<?php echo esc_url($category_split_url); ?>">
                                                <span class="ll-vocab-lesson-category-settings-tool-link__icon" aria-hidden="true">
                                                    <svg viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                                                        <path d="M5 4v4a5 5 0 0 0 5 5h1" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                                                        <path d="M5 20v-4a5 5 0 0 1 5-5h1" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                                                        <path d="M14 8l4 4-4 4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                                                    </svg>
                                                </span>
                                                <span><?php echo esc_html__('Split Category', 'll-tools-text-domain'); ?></span>
                                            </a>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($lesson_prereq_editor['show'])) : ?>
                                        <div
                                            class="ll-vocab-lesson-category-settings-section ll-vocab-lesson-bulk-section--prereq ll-vocab-lesson-prereq-editor"
                                            data-ll-prereq-editor
                                            data-ll-prereq-options="<?php echo esc_attr($lesson_prereq_editor['options_json']); ?>"
                                            data-ll-prereq-selected="<?php echo esc_attr($lesson_prereq_editor['selected_json']); ?>"
                                            data-ll-prereq-blocked="<?php echo esc_attr($lesson_prereq_editor['blocked_json']); ?>"
                                            data-ll-prereq-current-level="<?php echo esc_attr($lesson_prereq_editor['current_level'] === null ? '' : (string) ((int) $lesson_prereq_editor['current_level'])); ?>"
                                            data-ll-prereq-has-cycle="<?php echo !empty($lesson_prereq_editor['has_cycle']) ? '1' : '0'; ?>"
                                        >
                                            <div class="ll-vocab-lesson-category-settings-section__heading"><?php echo esc_html__('Prerequisites', 'll-tools-text-domain'); ?></div>
                                            <div class="ll-vocab-lesson-prereq-toolbar">
                                                <div class="ll-vocab-lesson-prereq-meta" aria-label="<?php echo esc_attr__('Prerequisite level', 'll-tools-text-domain'); ?>">
                                                    <span class="ll-vocab-lesson-prereq-meta-icon" aria-hidden="true">
                                                        <svg width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true">
                                                            <path d="M2 11.5h10" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
                                                            <path d="M3.5 8.5h7" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
                                                            <path d="M5 5.5h4" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
                                                        </svg>
                                                    </span>
                                                    <span class="screen-reader-text"><?php echo esc_html__('Level', 'll-tools-text-domain'); ?></span>
                                                    <span class="ll-vocab-lesson-prereq-level-value" data-ll-prereq-level><?php echo esc_html($lesson_prereq_level_display); ?></span>
                                                </div>
                                                <span class="ll-vocab-lesson-prereq-status" data-ll-prereq-status data-state="idle" role="status" aria-live="polite" hidden>
                                                    <span class="ll-vocab-lesson-prereq-status-icon" aria-hidden="true"></span>
                                                    <span class="ll-vocab-lesson-prereq-status-message" data-ll-prereq-status-message hidden></span>
                                                </span>
                                            </div>
                                            <label class="screen-reader-text" for="<?php echo esc_attr($lesson_prereq_input_id); ?>">
                                                <?php echo esc_html__('Search prerequisite categories', 'll-tools-text-domain'); ?>
                                            </label>
                                            <div class="ll-vocab-lesson-prereq-controls">
                                                <div class="ll-vocab-lesson-prereq-search">
                                                    <span class="ll-vocab-lesson-prereq-search-icon" aria-hidden="true">
                                                        <svg width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true">
                                                            <circle cx="6" cy="6" r="4" stroke="currentColor" stroke-width="1.4"/>
                                                            <path d="M9.5 9.5l2 2" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
                                                        </svg>
                                                    </span>
                                                    <input
                                                        type="text"
                                                        id="<?php echo esc_attr($lesson_prereq_input_id); ?>"
                                                        class="ll-vocab-lesson-prereq-input"
                                                        data-ll-prereq-input
                                                        autocomplete="off"
                                                        placeholder="<?php echo esc_attr__('Find categories', 'll-tools-text-domain'); ?>"
                                                    />
                                                    <button type="button" class="ll-vocab-lesson-prereq-search-clear" data-ll-prereq-search-clear aria-label="<?php echo esc_attr__('Clear search', 'll-tools-text-domain'); ?>" hidden>
                                                        <span aria-hidden="true">x</span>
                                                    </button>
                                                </div>
                                            </div>
                                            <div class="ll-vocab-lesson-prereq-chips" data-ll-prereq-chips aria-live="polite" hidden></div>
                                            <div class="ll-vocab-lesson-prereq-options" data-ll-prereq-options-list></div>
                                            <p class="ll-vocab-lesson-prereq-warning" data-ll-prereq-cycle-warning <?php if (empty($lesson_prereq_editor['has_cycle'])) : ?>hidden<?php endif; ?>>
                                                <?php echo esc_html__('A prerequisite loop exists in this word set. Remove the loop to restore prerequisite ordering.', 'll-tools-text-domain'); ?>
                                            </p>
                                        </div>
                                    <?php endif; ?>

                                    <div class="ll-vocab-lesson-category-settings-section">
                                        <div class="ll-vocab-lesson-category-settings-section__heading"><?php echo esc_html__('Quiz', 'll-tools-text-domain'); ?></div>
                                        <label class="ll-vocab-lesson-category-settings-field">
                                            <span class="ll-vocab-lesson-category-settings-field__label"><?php echo esc_html__('Prompt', 'll-tools-text-domain'); ?></span>
                                            <select name="ll_vocab_lesson_quiz_prompt_type" class="ll-vocab-lesson-category-settings-select">
                                                <?php foreach ($category_panel_prompt_types as $prompt_type_option) : ?>
                                                    <?php $prompt_type_option = sanitize_key((string) $prompt_type_option); ?>
                                                    <option value="<?php echo esc_attr($prompt_type_option); ?>" <?php selected($category_panel_prompt_type, $prompt_type_option); ?>>
                                                        <?php echo esc_html(function_exists('ll_tools_get_quiz_prompt_type_label') ? ll_tools_get_quiz_prompt_type_label($prompt_type_option) : $prompt_type_option); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                        <label class="ll-vocab-lesson-category-settings-field">
                                            <span class="ll-vocab-lesson-category-settings-field__label"><?php echo esc_html__('Answers', 'll-tools-text-domain'); ?></span>
                                            <select name="ll_vocab_lesson_quiz_option_type" class="ll-vocab-lesson-category-settings-select">
                                                <?php foreach ($category_panel_option_types as $option_type_option) : ?>
                                                    <?php $option_type_option = sanitize_key((string) $option_type_option); ?>
                                                    <option value="<?php echo esc_attr($option_type_option); ?>" <?php selected($category_panel_option_type, $option_type_option); ?>>
                                                        <?php echo esc_html(function_exists('ll_tools_get_quiz_option_type_label') ? ll_tools_get_quiz_option_type_label($option_type_option) : $option_type_option); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                        <label class="ll-vocab-lesson-category-settings-field">
                                            <span class="ll-vocab-lesson-category-settings-field__label"><?php echo esc_html__('Lesson text', 'll-tools-text-domain'); ?></span>
                                            <select name="ll_vocab_lesson_grid_text_visibility" class="ll-vocab-lesson-category-settings-select">
                                                <option value="inherit" <?php selected($category_panel_visibility, 'inherit'); ?>><?php echo esc_html__('Use word set default', 'll-tools-text-domain'); ?></option>
                                                <option value="show" <?php selected($category_panel_visibility, 'show'); ?>><?php echo esc_html__('Always show text', 'll-tools-text-domain'); ?></option>
                                                <option value="hide" <?php selected($category_panel_visibility, 'hide'); ?>><?php echo esc_html__('Always hide text', 'll-tools-text-domain'); ?></option>
                                            </select>
                                        </label>
                                    </div>

                                    <div class="ll-vocab-lesson-category-settings-section">
                                        <div class="ll-vocab-lesson-category-settings-section__heading"><?php echo esc_html__('Games', 'll-tools-text-domain'); ?></div>
                                        <div class="ll-vocab-lesson-category-settings-checkboxes">
                                            <?php foreach ($category_panel_game_definitions as $game_slug => $game_definition) : ?>
                                                <?php
                                                $game_slug = sanitize_key((string) $game_slug);
                                                $game_label = (string) ($game_definition['label'] ?? $game_slug);
                                                if ($game_slug === '') {
                                                    continue;
                                                }
                                                ?>
                                                <label class="ll-vocab-lesson-category-settings-check">
                                                    <input type="checkbox" name="ll_vocab_lesson_category_enabled_games[]" value="<?php echo esc_attr($game_slug); ?>" <?php checked(!empty($category_panel_enabled_games[$game_slug])); ?> />
                                                    <span><?php echo esc_html($game_label); ?></span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>

                                    <div class="ll-vocab-lesson-category-settings-section">
                                        <div class="ll-vocab-lesson-category-settings-section__heading"><?php echo esc_html__('Recording', 'll-tools-text-domain'); ?></div>
                                        <p class="ll-vocab-lesson-category-settings-help"><?php echo esc_html__('Choose which recording types this category expects. Leave everything unchecked to disable recording for the category.', 'll-tools-text-domain'); ?></p>
                                        <div class="ll-vocab-lesson-category-settings-checkboxes">
                                            <?php foreach ($category_panel_recording_type_terms as $recording_type_term) : ?>
                                                <?php
                                                if (!($recording_type_term instanceof WP_Term) || is_wp_error($recording_type_term)) {
                                                    continue;
                                                }
                                                $recording_type_slug = sanitize_key((string) $recording_type_term->slug);
                                                if ($recording_type_slug === '') {
                                                    continue;
                                                }
                                                ?>
                                                <label class="ll-vocab-lesson-category-settings-check">
                                                    <input type="checkbox" name="ll_vocab_lesson_desired_recording_types[]" value="<?php echo esc_attr($recording_type_slug); ?>" <?php checked(!$category_panel_recording_disabled && !empty($category_panel_recording_lookup[$recording_type_slug])); ?> />
                                                    <span><?php echo esc_html($recording_type_term->name); ?></span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>

                                    <div class="ll-vocab-lesson-category-settings-section ll-vocab-lesson-category-settings-section--lineup" data-ll-category-lineup-ordering>
                                        <div class="ll-vocab-lesson-category-settings-section__heading"><?php echo esc_html__('Line-Up', 'll-tools-text-domain'); ?></div>
                                        <input type="hidden" name="ll_vocab_lesson_category_lineup_submitted" value="1" />
                                        <label class="ll-vocab-lesson-category-settings-field">
                                            <span class="ll-vocab-lesson-category-settings-field__label"><?php echo esc_html__('Direction', 'll-tools-text-domain'); ?></span>
                                            <select name="ll_vocab_lesson_category_lineup_direction" class="ll-vocab-lesson-category-settings-select" data-ll-category-lineup-direction>
                                                <option value="auto" <?php selected($category_panel_lineup_direction, 'auto'); ?>><?php echo esc_html__('Auto', 'll-tools-text-domain'); ?></option>
                                                <option value="ltr" <?php selected($category_panel_lineup_direction, 'ltr'); ?>><?php echo esc_html__('Left to right', 'll-tools-text-domain'); ?></option>
                                                <option value="rtl" <?php selected($category_panel_lineup_direction, 'rtl'); ?>><?php echo esc_html__('Right to left', 'll-tools-text-domain'); ?></option>
                                            </select>
                                        </label>
                                        <?php if (!empty($category_panel_lineup_items)) : ?>
                                            <ol class="ll-vocab-lesson-category-lineup-list" data-ll-category-lineup-list>
                                                <?php foreach ($category_panel_lineup_items as $lineup_item) : ?>
                                                    <?php
                                                    $lineup_item_id = isset($lineup_item['id']) ? (int) $lineup_item['id'] : 0;
                                                    $lineup_item_title = (string) ($lineup_item['title'] ?? '');
                                                    if ($lineup_item_id <= 0 || $lineup_item_title === '') {
                                                        continue;
                                                    }
                                                    ?>
                                                    <li class="ll-vocab-lesson-category-lineup-item" data-ll-category-lineup-item data-word-id="<?php echo esc_attr((string) $lineup_item_id); ?>">
                                                        <span class="ll-vocab-lesson-category-lineup-title" dir="auto"><?php echo esc_html($lineup_item_title); ?></span>
                                                        <span class="ll-vocab-lesson-category-lineup-actions">
                                                            <button type="button" class="ll-vocab-lesson-category-lineup-move" data-ll-category-lineup-move="up"><?php echo esc_html__('Up', 'll-tools-text-domain'); ?></button>
                                                            <button type="button" class="ll-vocab-lesson-category-lineup-move" data-ll-category-lineup-move="down"><?php echo esc_html__('Down', 'll-tools-text-domain'); ?></button>
                                                        </span>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ol>
                                            <input type="hidden" id="<?php echo esc_attr($category_panel_lineup_input_id); ?>" name="ll_vocab_lesson_category_lineup_word_ids" value="<?php echo esc_attr(implode(',', array_map(static function ($lineup_item): int { return (int) ($lineup_item['id'] ?? 0); }, $category_panel_lineup_items))); ?>" data-ll-category-lineup-order-input />
                                            <p class="ll-vocab-lesson-category-settings-help"><?php echo esc_html__('Move words up or down to set the Line-Up teaching order for this category.', 'll-tools-text-domain'); ?></p>
                                        <?php else : ?>
                                            <p class="ll-vocab-lesson-category-settings-help"><?php echo esc_html__('Add words to this category before configuring the Line-Up sequence.', 'll-tools-text-domain'); ?></p>
                                        <?php endif; ?>
                                    </div>

                                    <div class="ll-vocab-lesson-category-settings-actions" data-ll-category-settings-actions hidden>
                                        <span class="ll-vocab-lesson-category-settings-status" data-ll-category-settings-status data-state="idle" role="status" aria-live="polite" hidden>
                                            <span class="ll-vocab-lesson-category-settings-status-icon" aria-hidden="true"></span>
                                            <span class="ll-vocab-lesson-category-settings-status-message" data-ll-category-settings-status-message hidden></span>
                                        </span>
                                    </div>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <?php
                if (function_exists('ll_tools_render_interlinear_block')) {
                    echo ll_tools_render_interlinear_block($post_id); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                }
                ?>
                <?php if ($has_print_settings) : ?>
                    <div class="ll-vocab-lesson-settings ll-vocab-lesson-print-settings">
                        <button
                            type="button"
                            class="ll-tools-settings-button ll-vocab-lesson-print-trigger"
                            aria-haspopup="true"
                            aria-expanded="false"
                            aria-label="<?php echo esc_attr__('Print lesson', 'll-tools-text-domain'); ?>">
                            <span class="ll-vocab-lesson-print-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                                    <path d="M7 8V4h10v4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                                    <path d="M7 17H5.5A2.5 2.5 0 0 1 3 14.5v-4A2.5 2.5 0 0 1 5.5 8h13A2.5 2.5 0 0 1 21 10.5v4a2.5 2.5 0 0 1-2.5 2.5H17" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                                    <path d="M7 14h10v6H7z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                                    <circle cx="17.5" cy="11.5" r="1" fill="currentColor"/>
                                </svg>
                            </span>
                            <span class="ll-vocab-lesson-print-label"><?php echo esc_html__('Print', 'll-tools-text-domain'); ?></span>
                        </button>
                        <form
                            class="ll-tools-settings-panel ll-vocab-lesson-print-panel"
                            method="get"
                            action="<?php echo esc_url($print_form_action); ?>"
                            target="_blank"
                            role="dialog"
                            aria-label="<?php echo esc_attr__('Print options', 'll-tools-text-domain'); ?>"
                            aria-hidden="true">
                            <input type="hidden" name="ll_print" value="1" />
                            <div class="ll-vocab-lesson-print-panel__title"><?php echo esc_html__('Print options', 'll-tools-text-domain'); ?></div>
                            <label class="ll-vocab-lesson-print-panel__option">
                                <input type="checkbox" name="ll_print_text" value="1" />
                                <span><?php echo esc_html__('Text', 'll-tools-text-domain'); ?></span>
                            </label>
                            <label class="ll-vocab-lesson-print-panel__option">
                                <input type="checkbox" name="ll_print_translations" value="1" />
                                <span><?php echo esc_html__('Translations', 'll-tools-text-domain'); ?></span>
                            </label>
                            <button type="submit" class="ll-study-btn tiny ll-vocab-lesson-print-button ll-vocab-lesson-print-panel__submit">
                                <span class="ll-vocab-lesson-print-icon" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                                        <path d="M7 8V4h10v4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                                        <path d="M7 17H5.5A2.5 2.5 0 0 1 3 14.5v-4A2.5 2.5 0 0 1 5.5 8h13A2.5 2.5 0 0 1 21 10.5v4a2.5 2.5 0 0 1-2.5 2.5H17" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                                        <path d="M7 14h10v6H7z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                                        <circle cx="17.5" cy="11.5" r="1" fill="currentColor"/>
                                    </svg>
                                </span>
                                <span class="ll-vocab-lesson-print-label"><?php echo esc_html__('Open print view', 'll-tools-text-domain'); ?></span>
                            </button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
            <div class="ll-vocab-lesson-title-row">
                <div class="ll-vocab-lesson-title-wrap">
                    <?php if ($can_edit_category_title) : ?>
                        <div
                            class="ll-vocab-lesson-title-edit"
                            data-ll-vocab-lesson-title-editor
                            data-lesson-id="<?php echo esc_attr($post_id); ?>"
                            data-category-id="<?php echo esc_attr($category_id); ?>"
                            data-action="ll_tools_update_vocab_lesson_category_title"
                            data-nonce="<?php echo esc_attr($title_edit_nonce); ?>"
                            data-current-field="<?php echo esc_attr($title_edit_field); ?>">
                            <h1 class="ll-vocab-lesson-title">
                                <button
                                    type="button"
                                    class="ll-vocab-lesson-title-trigger"
                                    data-ll-vocab-lesson-title-trigger
                                    aria-expanded="false"
                                    aria-controls="<?php echo esc_attr($title_form_id); ?>"
                                    aria-label="<?php echo esc_attr__('Edit category title', 'll-tools-text-domain'); ?>"
                                    title="<?php echo esc_attr__('Edit category title', 'll-tools-text-domain'); ?>">
                                    <span class="ll-vocab-lesson-title-text" data-ll-vocab-lesson-title-text><?php echo ll_tools_esc_html_display($display_name); ?></span>
                                    <span class="ll-vocab-lesson-title-edit-icon" aria-hidden="true">
                                        <svg viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                                            <path d="M4 20.5h4l10-10-4-4-10 10v4z" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                            <path d="M13.5 6.5l4 4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                        </svg>
                                    </span>
                                </button>
                            </h1>
                            <form
                                class="ll-vocab-lesson-title-form"
                                id="<?php echo esc_attr($title_form_id); ?>"
                                data-ll-vocab-lesson-title-form
                                hidden
                                novalidate>
                                <input
                                    type="text"
                                    id="<?php echo esc_attr($title_input_id); ?>"
                                    class="ll-vocab-lesson-title-input"
                                    data-ll-vocab-lesson-title-input
                                    value="<?php echo esc_attr($title_edit_value); ?>"
                                    aria-label="<?php echo esc_attr__('Category title', 'll-tools-text-domain'); ?>"
                                    autocomplete="off" />
                                <div class="ll-vocab-lesson-title-form-actions">
                                    <button
                                        type="submit"
                                        class="ll-vocab-lesson-title-icon-button ll-study-btn tiny"
                                        data-ll-vocab-lesson-title-save
                                        aria-label="<?php echo esc_attr__('Save title', 'll-tools-text-domain'); ?>"
                                        title="<?php echo esc_attr__('Save title', 'll-tools-text-domain'); ?>">
                                        <svg viewBox="0 0 16 16" focusable="false" aria-hidden="true">
                                            <path d="m3 8 3 3 7-7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                        </svg>
                                    </button>
                                    <button
                                        type="button"
                                        class="ll-vocab-lesson-title-icon-button ll-study-btn tiny ghost"
                                        data-ll-vocab-lesson-title-cancel
                                        aria-label="<?php echo esc_attr__('Cancel editing', 'll-tools-text-domain'); ?>"
                                        title="<?php echo esc_attr__('Cancel editing', 'll-tools-text-domain'); ?>">
                                        <svg viewBox="0 0 16 16" focusable="false" aria-hidden="true">
                                            <path d="M4 4l8 8M12 4 4 12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                                        </svg>
                                    </button>
                                </div>
                                <span class="ll-vocab-lesson-title-status" data-ll-vocab-lesson-title-status aria-live="polite" hidden></span>
                            </form>
                        </div>
                    <?php else : ?>
                        <h1 class="ll-vocab-lesson-title"><?php echo ll_tools_esc_html_display($display_name); ?></h1>
                    <?php endif; ?>
                </div>
                <div class="ll-vocab-lesson-actions">
                    <div class="ll-vocab-lesson-modes" role="group" aria-label="<?php echo esc_attr__('Quiz modes', 'll-tools-text-domain'); ?>">
                        <?php
                        $lesson_modes = array_values(array_filter(
                            ll_tools_get_study_launch_mode_order($gender_quiz_available),
                            static function (string $mode) use ($lesson_learning_supported, $lesson_self_check_supported): bool {
                                if ($mode === 'learning') {
                                    return $lesson_learning_supported;
                                }
                                if ($mode === 'self-check') {
                                    return $lesson_self_check_supported;
                                }
                                return true;
                            }
                        ));
                        $fallback_icons = [
                            'learning' => '🎓',
                            'practice' => '❓',
                            'listening' => '🎧',
                            'gender' => '⚥',
                            'self-check' => '✔✖',
                        ];
                        ?>
                        <?php foreach ($lesson_modes as $mode) : ?>
                            <?php
                            $mode_label = $mode_labels[$mode] ?? ucfirst($mode);
                            $mode_url = $embed_base;
                            if ($mode_url !== '') {
                                $args = ['mode' => $mode];
                                $args['ll_context'] = 'vocab_lesson';
                                if ($wordset_slug !== '') {
                                    $args['wordset'] = $wordset_slug;
                                }
                                $mode_url = add_query_arg($args, $mode_url);
                            }
                            ?>
                            <button type="button"
                                    class="ll-vocab-lesson-mode-button ll-quiz-page-trigger"
                                    data-ll-open-cat="<?php echo esc_attr($category_name); ?>"
                                    data-category="<?php echo esc_attr($category_name); ?>"
                                    data-url="<?php echo esc_url($mode_url); ?>"
                                    data-mode="<?php echo esc_attr($mode); ?>"
                                    data-self-check-supported="<?php echo $lesson_self_check_supported ? '1' : '0'; ?>"
                                    data-wordset="<?php echo esc_attr($wordset_slug); ?>"
                                    data-wordset-id="<?php echo esc_attr($wordset_id); ?>">
                                <?php $render_mode_icon($mode, $fallback_icons[$mode] ?? '❓'); ?>
                                <span class="ll-vocab-lesson-mode-label"><?php echo esc_html($mode_label); ?></span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </header>

        <?php if (is_array($category_settings_notice) && !empty($category_settings_notice['message'])) : ?>
            <div class="ll-vocab-lesson-notice ll-vocab-lesson-notice--<?php echo esc_attr(($category_settings_notice['type'] ?? 'success') === 'error' ? 'error' : 'success'); ?>" role="status" aria-live="polite">
                <?php echo esc_html((string) $category_settings_notice['message']); ?>
            </div>
        <?php endif; ?>

        <div class="ll-vocab-lesson-content">
            <?php
            if (!empty($related_content_lessons) && function_exists('ll_tools_render_content_lesson_cards')) {
                echo ll_tools_render_content_lesson_cards($related_content_lessons, [
                    'title' => __('From Main Lessons', 'll-tools-text-domain'),
                    'description' => __('Return to the main audio/video lesson that introduced this vocabulary.', 'll-tools-text-domain'),
                    'context' => 'vocab',
                    'open_label' => __('Open main lesson', 'll-tools-text-domain'),
                ]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            }
            ?>
            <?php if ($defer_grid && $grid_cached_html !== '') : ?>
                <?php echo $grid_cached_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php elseif ($defer_grid && is_array($grid_shell_spec)) : ?>
                <?php
                $is_prompt_card_shell = !empty($grid_shell_spec['prompt_card_shell']);
                $grid_shell_classes = 'll-vocab-lesson-grid-shell is-loading';
                if ($is_prompt_card_shell) {
                    $grid_shell_classes .= ' ll-vocab-lesson-grid-shell--prompt-cards';
                }
                ?>
                <div
                    class="<?php echo esc_attr($grid_shell_classes); ?>"
                    data-ll-vocab-lesson-grid-shell
                    data-lesson-id="<?php echo esc_attr($post_id); ?>"
                    data-nonce="<?php echo esc_attr($grid_shell_nonce); ?>"
                    aria-busy="true">
                    <div class="screen-reader-text" data-ll-vocab-lesson-grid-status role="status" aria-live="polite">
                        <?php echo esc_html__('Loading lesson words...', 'll-tools-text-domain'); ?>
                    </div>
                    <div <?php echo $render_html_attributes((array) ($grid_shell_spec['attributes'] ?? [])); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
                        <?php
                        $shell_cards = isset($grid_shell_spec['cards']) && is_array($grid_shell_spec['cards'])
                            ? array_values($grid_shell_spec['cards'])
                            : [];
                        $show_shell_media = !isset($grid_shell_spec['show_media']) || !empty($grid_shell_spec['show_media']);
                        $show_shell_title = !isset($grid_shell_spec['show_title']) || !empty($grid_shell_spec['show_title']);
                        if (empty($shell_cards)) {
                            $shell_cards = array_fill(0, 6, [
                                'media_aspect_ratio' => '',
                                'title_width' => '68%',
                                'recording_count' => 3,
                            ]);
                        }
                        ?>
                        <?php foreach ($shell_cards as $shell_card) : ?>
                            <?php
                            $shell_word_text = trim((string) ($shell_card['word_text'] ?? ''));
                            $shell_translation_text = trim((string) ($shell_card['translation_text'] ?? ''));
                            $shell_has_visible_text = !$is_prompt_card_shell
                                && $show_shell_title
                                && ($shell_word_text !== '' || $shell_translation_text !== '');
                            $shell_preview_url = trim((string) ($shell_card['image_preview_url'] ?? ''));
                            $shell_has_preview = !$is_prompt_card_shell
                                && $show_shell_media
                                && $shell_preview_url !== '';
                            $shell_recording_types = [];
                            if (!$is_prompt_card_shell && isset($shell_card['recording_types']) && is_array($shell_card['recording_types'])) {
                                $shell_recording_types = array_values(array_filter(array_map('sanitize_key', $shell_card['recording_types'])));
                            }
                            $card_attributes = [
                                'class' => 'word-item ll-vocab-lesson-skeleton-card' . ($is_prompt_card_shell ? ' ll-vocab-lesson-skeleton-card--prompt-card' : ''),
                            ];
                            if (!$shell_has_visible_text && !$shell_has_preview) {
                                $card_attributes['aria-hidden'] = 'true';
                            }
                            $shell_word_id = (int) ($shell_card['word_id'] ?? 0);
                            if ($shell_word_id > 0) {
                                $card_attributes['data-ll-shell-word-id'] = (string) $shell_word_id;
                            }
                            $card_style_parts = [];
                            $card_ratio = trim((string) ($shell_card['media_aspect_ratio'] ?? ''));
                            if ($card_ratio !== '') {
                                $card_style_parts[] = '--ll-word-grid-shell-card-image-aspect:' . $card_ratio;
                            }
                            $card_title_width = trim((string) ($shell_card['title_width'] ?? ''));
                            if ($card_title_width !== '') {
                                $card_style_parts[] = '--ll-word-grid-shell-card-title-width:' . $card_title_width;
                            }
                            if (!empty($card_style_parts)) {
                                $card_attributes['style'] = implode(';', $card_style_parts) . ';';
                            }
                            $recording_count = max(0, (int) ($shell_card['recording_count'] ?? 3));
                            if (!empty($shell_recording_types)) {
                                $recording_count = count($shell_recording_types);
                            }
                            ?>
                            <article <?php echo $render_html_attributes($card_attributes); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
                                <?php if ($is_prompt_card_shell) : ?>
                                    <div class="ll-vocab-lesson-skeleton-media"></div>
                                    <div class="ll-vocab-lesson-skeleton-prompt-card-body">
                                        <div class="ll-vocab-lesson-skeleton-prompt-box">
                                            <span class="ll-vocab-lesson-skeleton-dot"></span>
                                            <span class="ll-vocab-lesson-skeleton-line ll-vocab-lesson-skeleton-line--prompt"></span>
                                            <span class="ll-vocab-lesson-skeleton-recording-button"></span>
                                            <span class="ll-vocab-lesson-skeleton-line ll-vocab-lesson-skeleton-line--prompt-secondary"></span>
                                        </div>
                                        <div class="ll-vocab-lesson-skeleton-answer-list">
                                            <?php for ($answer_index = 0; $answer_index < 2; $answer_index++) : ?>
                                                <div class="ll-vocab-lesson-skeleton-answer">
                                                    <span class="ll-vocab-lesson-skeleton-dot"></span>
                                                    <span class="ll-vocab-lesson-skeleton-recording-button"></span>
                                                    <span class="ll-vocab-lesson-skeleton-line ll-vocab-lesson-skeleton-line--answer"></span>
                                                    <span class="ll-vocab-lesson-skeleton-line ll-vocab-lesson-skeleton-line--answer-secondary"></span>
                                                </div>
                                            <?php endfor; ?>
                                        </div>
                                    </div>
                                <?php elseif ($show_shell_media) : ?>
                                    <div class="ll-vocab-lesson-skeleton-media<?php echo $shell_has_preview ? ' ll-vocab-lesson-skeleton-media--preview' : ''; ?>">
                                        <?php if ($shell_has_preview) : ?>
                                            <img
                                                class="ll-vocab-lesson-shell-preview-image"
                                                src="<?php echo esc_url($shell_preview_url); ?>"
                                                alt=""
                                                aria-hidden="true"
                                                loading="eager"
                                                decoding="async"
                                                fetchpriority="low"
                                                <?php if ((int) ($shell_card['image_preview_width'] ?? 0) > 0) : ?>
                                                    width="<?php echo esc_attr((string) (int) $shell_card['image_preview_width']); ?>"
                                                <?php endif; ?>
                                                <?php if ((int) ($shell_card['image_preview_height'] ?? 0) > 0) : ?>
                                                    height="<?php echo esc_attr((string) (int) $shell_card['image_preview_height']); ?>"
                                                <?php endif; ?>
                                            />
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                <?php if (!$is_prompt_card_shell && $show_shell_title) : ?>
                                    <?php if ($shell_has_visible_text) : ?>
                                        <div class="ll-vocab-lesson-shell-title">
                                            <?php if ($shell_word_text !== '') : ?>
                                                <span class="ll-vocab-lesson-shell-word-text" dir="auto"><?php echo function_exists('ll_tools_esc_html_display') ? ll_tools_esc_html_display($shell_word_text) : esc_html($shell_word_text); ?></span>
                                            <?php endif; ?>
                                            <?php if ($shell_translation_text !== '') : ?>
                                                <span class="ll-vocab-lesson-shell-translation-text" dir="auto"><?php echo function_exists('ll_tools_esc_html_display') ? ll_tools_esc_html_display($shell_translation_text) : esc_html($shell_translation_text); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    <?php else : ?>
                                        <div class="ll-vocab-lesson-skeleton-title"></div>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if (!$is_prompt_card_shell && $recording_count > 0) : ?>
                                    <div class="ll-vocab-lesson-skeleton-recordings">
                                        <?php if (!empty($shell_recording_types)) : ?>
                                            <?php foreach ($shell_recording_types as $shell_recording_type) : ?>
                                                <?php $shell_recording_class = sanitize_html_class($shell_recording_type); ?>
                                                <button
                                                    type="button"
                                                    class="ll-study-recording-btn ll-word-grid-recording-btn ll-study-recording-btn--<?php echo esc_attr($shell_recording_class); ?> ll-vocab-lesson-shell-recording-btn"
                                                    data-recording-type="<?php echo esc_attr($shell_recording_type); ?>"
                                                    disabled
                                                    tabindex="-1"
                                                    aria-hidden="true">
                                                    <span class="ll-study-recording-icon" aria-hidden="true"></span>
                                                    <span class="ll-study-recording-visualizer" aria-hidden="true">
                                                        <span class="bar"></span>
                                                        <span class="bar"></span>
                                                        <span class="bar"></span>
                                                        <span class="bar"></span>
                                                    </span>
                                                </button>
                                            <?php endforeach; ?>
                                        <?php else : ?>
                                            <?php for ($recording_index = 0; $recording_index < $recording_count; $recording_index++) : ?>
                                                <span class="ll-vocab-lesson-skeleton-recording-button"></span>
                                            <?php endfor; ?>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>
                    <div class="ll-vocab-lesson-grid-feedback" data-ll-vocab-lesson-grid-feedback hidden></div>
                </div>
            <?php else : ?>
                <?php the_content(); ?>
            <?php endif; ?>
            <?php if ($can_add_lesson_word) : ?>
                <div class="ll-vocab-lesson-add-word" data-ll-add-lesson-word-wrap>
                    <button
                        type="button"
                        class="ll-vocab-lesson-add-word__button"
                        data-ll-add-lesson-word
                        data-lesson-id="<?php echo esc_attr((string) $post_id); ?>"
                        aria-label="<?php echo esc_attr__('Add word to this lesson', 'll-tools-text-domain'); ?>"
                        title="<?php echo esc_attr__('Add word to this lesson', 'll-tools-text-domain'); ?>">
                        <svg viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                            <path d="M12 5v14M5 12h14" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"/>
                        </svg>
                        <span class="screen-reader-text"><?php echo esc_html__('Add word', 'll-tools-text-domain'); ?></span>
                    </button>
                    <span class="ll-vocab-lesson-add-word__status" data-ll-add-lesson-word-status role="status" aria-live="polite" hidden></span>
                </div>
            <?php endif; ?>
        </div>
    </main>
    <?php
}

get_footer();
