<?php
// /templates/vocab-lesson-template.php
if (!defined('WPINC')) { die; }

get_header();

if (have_posts()) {
    the_post();
    $post_id = get_the_ID();
    $wordset_id = (int) get_post_meta($post_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, true);
    $category_id = (int) get_post_meta($post_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, true);

    $wordset = $wordset_id ? get_term($wordset_id, 'wordset') : null;
    $category = $category_id ? get_term($category_id, 'word-category') : null;
    $can_edit_words = function_exists('ll_tools_user_can_edit_vocab_words')
        ? ll_tools_user_can_edit_vocab_words()
        : (is_user_logged_in() && current_user_can('view_ll_tools'));
    $can_transcribe = $can_edit_words
        && function_exists('ll_tools_can_transcribe_recordings')
        && ll_tools_can_transcribe_recordings();

    $display_name = '';
    if ($category && !is_wp_error($category)) {
        $display_name = function_exists('ll_tools_get_category_display_name')
            ? ll_tools_get_category_display_name($category)
            : $category->name;
    }
    if ($display_name === '') {
        $display_name = get_the_title();
    }

    $wordset_name = ($wordset && !is_wp_error($wordset)) ? $wordset->name : '';
    $wordset_slug = ($wordset && !is_wp_error($wordset)) ? $wordset->slug : '';
    $wordset_url = ($wordset_slug !== '')
        ? trailingslashit(home_url($wordset_slug))
        : '';
    $category_slug = ($category && !is_wp_error($category)) ? $category->slug : '';
    $category_name = ($category && !is_wp_error($category)) ? $category->name : $display_name;
    $embed_base = ($category_slug !== '') ? home_url('/embed/' . $category_slug) : '';
    $mode_ui = function_exists('ll_flashcards_get_mode_ui_config') ? ll_flashcards_get_mode_ui_config() : [];
    $mode_labels = [
        'practice'  => __('Practice', 'll-tools-text-domain'),
        'learning'  => __('Learn', 'll-tools-text-domain'),
        'self-check' => __('Self check', 'll-tools-text-domain'),
        'gender'    => __('Gender', 'll-tools-text-domain'),
        'listening' => __('Listen', 'll-tools-text-domain'),
    ];
    $render_mode_icon = function (string $mode, string $fallback) use ($mode_ui): void {
        $cfg = (isset($mode_ui[$mode]) && is_array($mode_ui[$mode])) ? $mode_ui[$mode] : [];
        if (!empty($cfg['svg'])) {
            echo '<span class="ll-vocab-lesson-mode-icon" aria-hidden="true">' . $cfg['svg'] . '</span>';
            return;
        }
        $icon = !empty($cfg['icon']) ? $cfg['icon'] : $fallback;
        echo '<span class="ll-vocab-lesson-mode-icon" aria-hidden="true" data-emoji="' . esc_attr($icon) . '"></span>';
    };

    $gender_quiz_available = false;
    if ($wordset_id > 0 && $category && !is_wp_error($category)
        && function_exists('ll_tools_wordset_has_grammatical_gender')
        && ll_tools_wordset_has_grammatical_gender($wordset_id)
        && function_exists('ll_get_words_by_category')
    ) {
        $min_count = (int) apply_filters('ll_tools_quiz_min_words', LL_TOOLS_MIN_WORDS_PER_QUIZ);
        $gender_options = function_exists('ll_tools_wordset_get_gender_options')
            ? ll_tools_wordset_get_gender_options($wordset_id)
            : [];
        $gender_lookup = [];
        foreach ($gender_options as $option) {
            $key = strtolower(trim((string) $option));
            if (function_exists('ll_tools_wordset_strip_variation_selectors')) {
                $key = ll_tools_wordset_strip_variation_selectors($key);
            }
            if ($key !== '') {
                $gender_lookup[$key] = true;
            }
        }
        if (!empty($gender_lookup)) {
            $quiz_config = function_exists('ll_tools_get_category_quiz_config')
                ? ll_tools_get_category_quiz_config($category)
                : ['prompt_type' => 'audio', 'option_type' => 'image'];
            $option_type = $quiz_config['option_type'] ?? 'image';
            $gender_words = ll_get_words_by_category($category->name, $option_type, [$wordset_id], array_merge($quiz_config, [
                '__skip_quiz_config_merge' => true,
            ]));
            if (count($gender_words) < $min_count && in_array($option_type, ['audio', 'text_audio'], true)) {
                $fallback_config = $quiz_config;
                $fallback_config['option_type'] = 'text_translation';
                $fallback_config['__skip_quiz_config_merge'] = true;
                $fallback_words = ll_get_words_by_category($category->name, 'text', [$wordset_id], $fallback_config);
                if (count($fallback_words) >= $min_count) {
                    $option_type = 'text_translation';
                    $quiz_config['option_type'] = $option_type;
                    $gender_words = $fallback_words;
                }
            }
            $prompt_type = isset($quiz_config['prompt_type']) ? (string) $quiz_config['prompt_type'] : 'audio';
            $requires_audio = function_exists('ll_tools_quiz_requires_audio')
                ? ll_tools_quiz_requires_audio(['prompt_type' => $prompt_type, 'option_type' => $option_type], $option_type)
                : ($prompt_type === 'audio' || in_array($option_type, ['audio', 'text_audio'], true));
            $requires_image = ($prompt_type === 'image') || ($option_type === 'image');
            $gender_count = 0;
            foreach ($gender_words as $word) {
                if (!is_array($word)) {
                    continue;
                }
                $pos = $word['part_of_speech'] ?? [];
                $pos = is_array($pos) ? $pos : [$pos];
                $pos = array_map('strtolower', array_map('strval', $pos));
                if (!in_array('noun', $pos, true)) {
                    continue;
                }
                $gender_raw = (string) ($word['grammatical_gender'] ?? '');
                $gender_label = function_exists('ll_tools_wordset_normalize_gender_value_for_options')
                    ? ll_tools_wordset_normalize_gender_value_for_options($gender_raw, $gender_options)
                    : trim($gender_raw);
                $gender = strtolower(trim($gender_label));
                if ($gender === '' || !isset($gender_lookup[$gender])) {
                    continue;
                }
                if (($requires_image && empty($word['has_image'])) || ($requires_audio && empty($word['has_audio']))) {
                    continue;
                }
                $gender_count++;
                if ($gender_count >= $min_count) {
                    $gender_quiz_available = true;
                    break;
                }
            }
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
                            <?php echo '&#9734; ' . esc_html__('Star all', 'll-tools-text-domain'); ?>
                        </button>
                        <?php if ($can_transcribe) : ?>
                            <div class="ll-vocab-lesson-transcribe" data-ll-transcribe-wrapper>
                                <div class="ll-vocab-lesson-transcribe-actions" role="group" aria-label="<?php echo esc_attr__('Captions', 'll-tools-text-domain'); ?>">
                                    <button type="button" class="ll-study-btn tiny ll-vocab-lesson-transcribe-btn ll-vocab-lesson-transcribe-btn--auto" data-ll-transcribe-recordings data-lesson-id="<?php echo esc_attr($post_id); ?>" aria-label="<?php echo esc_attr__('Auto-transcribe missing recordings', 'll-tools-text-domain'); ?>">
                                        <span class="ll-vocab-lesson-transcribe-icons" aria-hidden="true">
                                            <span class="ll-vocab-lesson-transcribe-icon ll-vocab-lesson-transcribe-icon--bolt">
                                                <svg viewBox="0 0 20 20" focusable="false" aria-hidden="true">
                                                    <path d="M11.5 2.5 4 11h4l-1 6.5L15.5 9h-4l0-6.5Z" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/>
                                                </svg>
                                            </span>
                                        </span>
                                        <span class="ll-vocab-lesson-transcribe-label"><?php echo esc_html__('Auto captions', 'll-tools-text-domain'); ?></span>
                                    </button>
                                    <button type="button" class="ll-study-btn tiny ll-vocab-lesson-transcribe-btn ll-vocab-lesson-transcribe-btn--replace" data-ll-transcribe-replace data-lesson-id="<?php echo esc_attr($post_id); ?>" aria-label="<?php echo esc_attr__('Replace captions for this lesson', 'll-tools-text-domain'); ?>">
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
                                    <button type="button" class="ll-study-btn tiny ll-vocab-lesson-transcribe-btn ll-vocab-lesson-transcribe-btn--clear" data-ll-transcribe-clear data-lesson-id="<?php echo esc_attr($post_id); ?>" aria-label="<?php echo esc_attr__('Clear captions for this lesson', 'll-tools-text-domain'); ?>">
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
                                    <button type="button" class="ll-study-btn tiny ghost ll-vocab-lesson-transcribe-btn ll-vocab-lesson-transcribe-btn--cancel" data-ll-transcribe-cancel data-lesson-id="<?php echo esc_attr($post_id); ?>" aria-label="<?php echo esc_attr__('Cancel transcription', 'll-tools-text-domain'); ?>" disabled>
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
                                <div class="ll-vocab-lesson-bulk-panel ll-tools-settings-panel" role="dialog" aria-label="<?php echo esc_attr__('Word details', 'll-tools-text-domain'); ?>" aria-hidden="true">
                                    <div class="ll-vocab-lesson-bulk-section">
                                        <div class="ll-vocab-lesson-bulk-heading"><?php echo esc_html__('Part of Speech', 'll-tools-text-domain'); ?></div>
                                        <div class="ll-vocab-lesson-bulk-controls" role="group" aria-label="<?php echo esc_attr__('Part of speech', 'll-tools-text-domain'); ?>">
                                            <select class="ll-vocab-lesson-bulk-select" data-ll-bulk-pos aria-label="<?php echo esc_attr__('Select part of speech', 'll-tools-text-domain'); ?>">
                                                <option value=""><?php echo esc_html__('Part of Speech', 'll-tools-text-domain'); ?></option>
                                                <?php foreach ($pos_terms as $term) : ?>
                                                    <?php if (!empty($term->slug)) : ?>
                                                        <option value="<?php echo esc_attr($term->slug); ?>"><?php echo esc_html($term->name); ?></option>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="button" class="ll-study-btn tiny ll-vocab-lesson-bulk-apply" data-ll-bulk-pos-apply aria-label="<?php echo esc_attr__('Apply part of speech to all words', 'll-tools-text-domain'); ?>">
                                                <?php echo esc_html__('Apply', 'll-tools-text-domain'); ?>
                                            </button>
                                        </div>
                                    </div>
                                    <?php if ($gender_enabled) : ?>
                                        <div class="ll-vocab-lesson-bulk-section">
                                            <div class="ll-vocab-lesson-bulk-heading"><?php echo esc_html__('Gender', 'll-tools-text-domain'); ?></div>
                                            <div class="ll-vocab-lesson-bulk-controls" role="group" aria-label="<?php echo esc_attr__('Noun gender', 'll-tools-text-domain'); ?>">
                                                <select class="ll-vocab-lesson-bulk-select" data-ll-bulk-gender aria-label="<?php echo esc_attr__('Select gender', 'll-tools-text-domain'); ?>">
                                                    <option value=""><?php echo esc_html__('Gender', 'll-tools-text-domain'); ?></option>
                                                    <?php foreach ($gender_options as $option) : ?>
                                                        <?php if (!empty($option)) : ?>
                                                            <?php
                                                            $option_label = function_exists('ll_tools_wordset_format_gender_display_label')
                                                                ? ll_tools_wordset_format_gender_display_label($option)
                                                                : $option;
                                                            ?>
                                                            <option value="<?php echo esc_attr($option); ?>"><?php echo esc_html($option_label); ?></option>
                                                        <?php endif; ?>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button type="button" class="ll-study-btn tiny ll-vocab-lesson-bulk-apply" data-ll-bulk-gender-apply aria-label="<?php echo esc_attr__('Apply gender to all nouns', 'll-tools-text-domain'); ?>">
                                                    <?php echo esc_html__('Apply', 'll-tools-text-domain'); ?>
                                                </button>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($plurality_enabled) : ?>
                                        <div class="ll-vocab-lesson-bulk-section">
                                            <div class="ll-vocab-lesson-bulk-heading"><?php echo esc_html__('Plurality', 'll-tools-text-domain'); ?></div>
                                            <div class="ll-vocab-lesson-bulk-controls" role="group" aria-label="<?php echo esc_attr__('Noun plurality', 'll-tools-text-domain'); ?>">
                                                <select class="ll-vocab-lesson-bulk-select" data-ll-bulk-plurality aria-label="<?php echo esc_attr__('Select plurality', 'll-tools-text-domain'); ?>">
                                                    <option value=""><?php echo esc_html__('Plurality', 'll-tools-text-domain'); ?></option>
                                                    <?php foreach ($plurality_options as $option) : ?>
                                                        <?php if (!empty($option)) : ?>
                                                            <option value="<?php echo esc_attr($option); ?>"><?php echo esc_html($option); ?></option>
                                                        <?php endif; ?>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button type="button" class="ll-study-btn tiny ll-vocab-lesson-bulk-apply" data-ll-bulk-plurality-apply aria-label="<?php echo esc_attr__('Apply plurality to all nouns', 'll-tools-text-domain'); ?>">
                                                    <?php echo esc_html__('Apply', 'll-tools-text-domain'); ?>
                                                </button>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    <span class="ll-vocab-lesson-bulk-status" data-ll-bulk-status aria-live="polite"></span>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="ll-vocab-lesson-title-row">
                <h1 class="ll-vocab-lesson-title"><?php echo esc_html($display_name); ?></h1>
                <div class="ll-vocab-lesson-actions">
                    <div class="ll-vocab-lesson-modes" role="group" aria-label="<?php echo esc_attr__('Quiz modes', 'll-tools-text-domain'); ?>">
                        <?php
                        $lesson_modes = $gender_quiz_available
                            ? ['practice', 'learning', 'self-check', 'gender', 'listening']
                            : ['practice', 'learning', 'self-check', 'listening'];
                        $fallback_icons = [
                            'practice' => '❓',
                            'learning' => '🎓',
                            'self-check' => '✔✖',
                            'gender' => '⚥',
                            'listening' => '🎧',
                        ];
                        ?>
                        <?php foreach ($lesson_modes as $mode) : ?>
                            <?php
                            $mode_label = $mode_labels[$mode] ?? ucfirst($mode);
                            $mode_url = $embed_base;
                            if ($mode_url !== '') {
                                $args = ['mode' => $mode];
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
                                    data-wordset="<?php echo esc_attr($wordset_slug); ?>"
                                    data-wordset-id="<?php echo esc_attr($wordset_id); ?>">
                                <?php $render_mode_icon($mode, $fallback_icons[$mode] ?? '❓'); ?>
                                <span class="ll-vocab-lesson-mode-label"><?php echo esc_html($mode_label); ?></span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                    <?php if (is_user_logged_in()) : ?>
                        <div class="ll-vocab-lesson-settings ll-tools-settings-control" data-ll-vocab-lesson-settings>
                            <button type="button" class="ll-vocab-lesson-settings-button ll-tools-settings-button" aria-haspopup="true" aria-expanded="false" aria-label="<?php echo esc_attr__('Study settings', 'll-tools-text-domain'); ?>">
                                <span class="mode-icon" aria-hidden="true">
                                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                        <path d="M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                        <path d="M19.4 12.98c.04-.32.06-.65.06-.98 0-.33-.02-.66-.06-.98l1.73-1.35a.5.5 0 0 0 .12-.64l-1.64-2.84a.5.5 0 0 0-.6-.22l-2.04.82a7.1 7.1 0 0 0-1.7-.98l-.26-2.17A.5.5 0 0 0 14.5 3h-5a.5.5 0 0 0-.5.43l-.26 2.17c-.6.24-1.17.55-1.7.93l-2.04-.82a.5.5 0 0 0-.6.22L2.76 8.58a.5.5 0 0 0 .12.64L4.6 10.57c-.04.32-.06.65-.06.98 0 .33.02.66.06.98l-1.73 1.35a.5.5 0 0 0-.12.64l1.64 2.84a.5.5 0 0 0 .6.22l2.04-.82c.53.38 1.1.69 1.7.93l.26 2.17a.5.5 0 0 0 .5.43h5a.5.5 0 0 0 .5-.43l.26-2.17c.6-.24 1.17-.55 1.7-.93l2.04.82a.5.5 0 0 0 .6-.22l1.64-2.84a.5.5 0 0 0-.12-.64l-1.73-1.35Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                </span>
                            </button>
                            <div class="ll-vocab-lesson-settings-panel ll-tools-settings-panel" role="dialog" aria-label="<?php echo esc_attr__('Word inclusion', 'll-tools-text-domain'); ?>" aria-hidden="true">
                                <div class="ll-tools-settings-section">
                                    <div class="ll-tools-settings-heading"><?php echo esc_html__('Word inclusion', 'll-tools-text-domain'); ?></div>
                                    <div class="ll-tools-settings-options" role="group" aria-label="<?php echo esc_attr__('Word inclusion', 'll-tools-text-domain'); ?>">
                                        <button type="button" class="ll-tools-settings-option ll-vocab-lesson-star-mode" data-star-mode="normal" aria-pressed="false"><?php echo esc_html__('☆★ All words once', 'll-tools-text-domain'); ?></button>
                                        <button type="button" class="ll-tools-settings-option ll-vocab-lesson-star-mode" data-star-mode="weighted" aria-pressed="false"><?php echo esc_html__('★☆★ Starred twice', 'll-tools-text-domain'); ?></button>
                                        <button type="button" class="ll-tools-settings-option ll-vocab-lesson-star-mode" data-star-mode="only" aria-pressed="false"><?php echo esc_html__('★ Starred only', 'll-tools-text-domain'); ?></button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </header>

        <div class="ll-vocab-lesson-content">
            <?php the_content(); ?>
        </div>
    </main>
    <?php
}

get_footer();
