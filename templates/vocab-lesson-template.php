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
    $mode_labels = [
        'practice'  => __('Practice', 'll-tools-text-domain'),
        'learning'  => __('Learn', 'll-tools-text-domain'),
        'listening' => __('Listen', 'll-tools-text-domain'),
    ];
    $mode_icons = [
        'practice'  => '❓',
        'learning'  => '🎓',
        'listening' => '🎧',
    ];
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
                                <button type="button" class="ll-study-btn tiny ll-vocab-lesson-transcribe-btn" data-ll-transcribe-recordings data-lesson-id="<?php echo esc_attr($post_id); ?>" aria-label="<?php echo esc_attr__('Auto-transcribe missing recordings', 'll-tools-text-domain'); ?>">
                                    <span class="ll-vocab-lesson-transcribe-icons" aria-hidden="true">
                                        <span class="ll-vocab-lesson-transcribe-icon">
                                            <svg viewBox="0 0 20 20" focusable="false" aria-hidden="true">
                                                <path d="M10 3a3 3 0 0 0-3 3v3a3 3 0 0 0 6 0V6a3 3 0 0 0-3-3Z" fill="none" stroke="currentColor" stroke-width="1.6"/>
                                                <path d="M4.5 9.5v.5a5.5 5.5 0 0 0 11 0v-.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
                                                <path d="M10 15.5v2" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
                                            </svg>
                                        </span>
                                        <span class="ll-vocab-lesson-transcribe-icon ll-vocab-lesson-transcribe-icon--bolt">
                                            <svg viewBox="0 0 20 20" focusable="false" aria-hidden="true">
                                                <path d="M11.5 2.5 4 11h4l-1 6.5L15.5 9h-4l0-6.5Z" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/>
                                            </svg>
                                        </span>
                                    </span>
                                    <span class="ll-vocab-lesson-transcribe-label"><?php echo esc_html__('Auto captions', 'll-tools-text-domain'); ?></span>
                                </button>
                                <span class="ll-vocab-lesson-transcribe-status" data-ll-transcribe-status aria-live="polite"></span>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="ll-vocab-lesson-title-row">
                <h1 class="ll-vocab-lesson-title"><?php echo esc_html($display_name); ?></h1>
                <div class="ll-vocab-lesson-actions">
                    <div class="ll-vocab-lesson-modes" role="group" aria-label="<?php echo esc_attr__('Quiz modes', 'll-tools-text-domain'); ?>">
                        <?php foreach (['practice', 'learning', 'listening'] as $mode) : ?>
                            <?php
                            $mode_label = $mode_labels[$mode] ?? ucfirst($mode);
                            $mode_icon = $mode_icons[$mode] ?? '';
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
                                <span class="ll-vocab-lesson-mode-icon" aria-hidden="true" data-emoji="<?php echo esc_attr($mode_icon); ?>"></span>
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
