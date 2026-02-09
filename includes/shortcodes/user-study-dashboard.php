<?php
// /includes/shortcodes/user-study-dashboard.php
if (!defined('WPINC')) { die; }

/**
 * Shortcode to render a user-facing study dashboard with wordset/category/word selections.
 * Usage: [ll_user_study_dashboard]
 */
function ll_tools_user_study_enqueue_assets() {
    ll_enqueue_asset_by_timestamp('/css/self-check-shared.css', 'll-tools-self-check-shared');
    ll_enqueue_asset_by_timestamp('/css/user-study-dashboard.css', 'll-tools-study-dashboard');
    ll_enqueue_asset_by_timestamp('/js/self-check-shared.js', 'll-tools-self-check-shared-script', ['jquery'], true);
    ll_enqueue_asset_by_timestamp('/js/user-study-dashboard.js', 'll-tools-study-dashboard', ['jquery', 'll-tools-self-check-shared-script'], true);
}

function ll_tools_user_study_maybe_enqueue_assets() {
    if (is_admin()) { return; }
    $post = get_queried_object();
    if (!$post instanceof WP_Post) { return; }
    if (!isset($post->post_content)) { return; }
    if (!has_shortcode($post->post_content, 'll_user_study_dashboard')) { return; }
    ll_tools_user_study_enqueue_assets();
}
add_action('wp_enqueue_scripts', 'll_tools_user_study_maybe_enqueue_assets');

function ll_tools_user_study_dashboard_shortcode($atts) {
    if (!is_user_logged_in()) {
        global $wp;

        $request_path = isset($wp->request) ? $wp->request : '';
        $query_args   = !empty($_GET) ? wp_unslash($_GET) : [];
        $redirect_path = $request_path;
        if (!empty($query_args)) {
            $redirect_path = add_query_arg($query_args, $request_path);
        }

        $redirect_to = home_url(ltrim((string) $redirect_path, '/'));
        $login_url   = wp_login_url(esc_url_raw($redirect_to));

        $message = sprintf(
            wp_kses(
                /* translators: %s: login URL */
                __('Please <a href="%s">log in</a> to save your study preferences. We will bring you back to this page after you sign in.', 'll-tools-text-domain'),
                ['a' => ['href' => []]]
            ),
            esc_url($login_url)
        );

        return '<div class="ll-user-study-dashboard ll-login-required"><p>' . $message . '</p></div>';
    }

    $payload = ll_tools_build_user_study_payload(get_current_user_id());
    $nonce   = wp_create_nonce('ll_user_study');
    $mode_ui = function_exists('ll_flashcards_get_mode_ui_config') ? ll_flashcards_get_mode_ui_config() : [];
    $render_mode_icon = function (string $mode, string $fallback) use ($mode_ui): void {
        $cfg = (isset($mode_ui[$mode]) && is_array($mode_ui[$mode])) ? $mode_ui[$mode] : [];
        if (!empty($cfg['svg'])) {
            echo '<span class="ll-vocab-lesson-mode-icon" aria-hidden="true">' . $cfg['svg'] . '</span>';
            return;
        }
        $icon = !empty($cfg['icon']) ? $cfg['icon'] : $fallback;
        echo '<span class="ll-vocab-lesson-mode-icon" aria-hidden="true" data-emoji="' . esc_attr($icon) . '"></span>';
    };

    ll_tools_user_study_enqueue_assets();

    $i18n = [
        'wordsetLabel'     => __('Word set', 'll-tools-text-domain'),
        'categoriesLabel'  => __('Categories', 'll-tools-text-domain'),
        'wordsLabel'       => __('Words', 'll-tools-text-domain'),
        'starLabel'        => __('Starred', 'll-tools-text-domain'),
        'saveSuccess'      => __('Saved.', 'll-tools-text-domain'),
        'practice'         => __('Practice', 'll-tools-text-domain'),
        'learning'         => __('Learn', 'll-tools-text-domain'),
        'gender'           => __('Gender', 'll-tools-text-domain'),
        'listening'        => __('Listen', 'll-tools-text-domain'),
        'noCategories'     => __('No categories available for this word set yet.', 'll-tools-text-domain'),
        'noWords'          => __('Select a category to see its words.', 'll-tools-text-domain'),
        'starAll'          => __('Star all', 'll-tools-text-domain'),
        'unstarAll'        => __('Unstar all', 'll-tools-text-domain'),
        'playAudio'        => __('Play audio', 'll-tools-text-domain'),
        'playAudioType'    => __('Play %s recording', 'll-tools-text-domain'),
        'recordingQuestion' => __('Question', 'll-tools-text-domain'),
        'recordingIsolation' => __('Isolation', 'll-tools-text-domain'),
        'recordingIntroduction' => __('Introduction', 'll-tools-text-domain'),
        'recordingsLabel'  => __('Recordings', 'll-tools-text-domain'),
        'transitionLabel'  => __('Transition speed', 'll-tools-text-domain'),
        'transitionHint'   => __('Keep the default smooth pacing or speed up after correct answers.', 'll-tools-text-domain'),
        'transitionSlow'   => __('Standard', 'll-tools-text-domain'),
        'transitionFast'   => __('Faster', 'll-tools-text-domain'),
        'checkLabel'       => __('Self check', 'll-tools-text-domain'),
        'checkTitle'       => __('Self check', 'll-tools-text-domain'),
        'checkKnow'        => __('I know it', 'll-tools-text-domain'),
        'checkDontKnow'    => __("I don't know it", 'll-tools-text-domain'),
        'checkSummary'     => __("You marked %d as \"I don't know\".", 'll-tools-text-domain'),
        'checkApply'       => __('Set stars', 'll-tools-text-domain'),
        'checkApplyHint'   => __("Replace stars for the selected categories with the words you marked as \"I don't know\".", 'll-tools-text-domain'),
        'checkRestart'     => __('Review again', 'll-tools-text-domain'),
        'checkExit'        => __('Close', 'll-tools-text-domain'),
        'checkEmpty'       => __('No words available for this check.', 'll-tools-text-domain'),
        'checkFlipLabel'   => __('Show answer', 'll-tools-text-domain'),
    ];

    wp_localize_script('ll-tools-study-dashboard', 'llToolsStudyData', [
        'ajaxUrl'  => admin_url('admin-ajax.php'),
        'nonce'    => $nonce,
        'payload'  => $payload,
        'i18n'     => $i18n,
    ]);

    // Embed the flashcard widget; we keep it non-embed so the header (category label/results UI) shows.
    $flashcard_markup = do_shortcode('[flashcard_widget embed="false" wordset="" wordset_fallback="false" quiz_mode="practice"]');

    ob_start();
    ?>
    <div class="ll-user-study-dashboard" data-ll-study-root>
        <div class="ll-study-header">
            <div>
                <h2><?php echo esc_html__('Your study plan', 'll-tools-text-domain'); ?></h2>
                <p class="ll-study-subhead"><?php echo esc_html__('Choose a word set, pick categories, and star the words you want to see more often.', 'll-tools-text-domain'); ?></p>
            </div>
            <div class="ll-study-actions">
                <button type="button" class="ll-study-btn ll-vocab-lesson-mode-button" data-ll-study-start data-mode="practice">
                    <?php $render_mode_icon('practice', '❓'); ?>
                    <span class="ll-vocab-lesson-mode-label"><?php echo esc_html($i18n['practice']); ?></span>
                </button>
                <button type="button" class="ll-study-btn ll-vocab-lesson-mode-button" data-ll-study-start data-mode="learning">
                    <?php $render_mode_icon('learning', '🎓'); ?>
                    <span class="ll-vocab-lesson-mode-label"><?php echo esc_html($i18n['learning']); ?></span>
                </button>
                <button type="button" class="ll-study-btn ll-vocab-lesson-mode-button ll-study-btn--gender ll-study-btn--hidden" data-ll-study-start data-ll-study-gender data-mode="gender" aria-hidden="true">
                    <?php $render_mode_icon('gender', '⚥'); ?>
                    <span class="ll-vocab-lesson-mode-label"><?php echo esc_html($i18n['gender']); ?></span>
                </button>
                <button type="button" class="ll-study-btn ll-vocab-lesson-mode-button" data-ll-study-start data-mode="listening">
                    <?php $render_mode_icon('listening', '🎧'); ?>
                    <span class="ll-vocab-lesson-mode-label"><?php echo esc_html($i18n['listening']); ?></span>
                </button>
                <button type="button" class="ll-study-btn ll-vocab-lesson-mode-button" data-ll-study-check-start>
                    <?php $render_mode_icon('self-check', '✔✖'); ?>
                    <span class="ll-vocab-lesson-mode-label"><?php echo esc_html($i18n['checkLabel']); ?></span>
                </button>
            </div>
        </div>

        <div class="ll-study-grid ll-study-grid--top">
            <div class="ll-study-card ll-study-wordset-card">
                <label for="ll-study-wordset"><?php echo esc_html($i18n['wordsetLabel']); ?></label>
                <select id="ll-study-wordset" data-ll-study-wordset></select>
                <p class="ll-study-hint"><?php echo esc_html__('Switch word sets to load their categories and words.', 'll-tools-text-domain'); ?></p>
                <div class="ll-star-mode">
                    <span class="ll-card-title-sub"><?php echo esc_html__('Word inclusion', 'll-tools-text-domain'); ?></span>
                    <div class="ll-star-toggle-group" data-ll-star-mode>
                        <button type="button" class="ll-study-btn tiny active" data-mode="normal"><?php echo esc_html__('☆★ All words once', 'll-tools-text-domain'); ?></button>
                        <button type="button" class="ll-study-btn tiny" data-mode="weighted"><?php echo esc_html__('★☆★ Starred twice', 'll-tools-text-domain'); ?></button>
                        <button type="button" class="ll-study-btn tiny" data-mode="only"><?php echo esc_html__('★ Starred only', 'll-tools-text-domain'); ?></button>
                    </div>
                    <p class="ll-study-hint"><?php echo esc_html__('Decide how often starred words appear: normal mix, starred twice, or only starred.', 'll-tools-text-domain'); ?></p>
                </div>
                <div class="ll-transition-speed">
                    <span class="ll-card-title-sub"><?php echo esc_html($i18n['transitionLabel']); ?></span>
                    <div class="ll-star-toggle-group" data-ll-transition-speed>
                        <button type="button" class="ll-study-btn tiny" data-speed="slow"><?php echo esc_html($i18n['transitionSlow']); ?></button>
                        <button type="button" class="ll-study-btn tiny" data-speed="fast"><?php echo esc_html($i18n['transitionFast']); ?></button>
                    </div>
                    <p class="ll-study-hint"><?php echo esc_html($i18n['transitionHint']); ?></p>
                </div>
            </div>

            <div class="ll-study-card ll-study-categories-card">
                <div class="ll-card-title">
                    <span><?php echo esc_html($i18n['categoriesLabel']); ?></span>
                    <button type="button" class="ll-study-btn ghost" data-ll-check-all><?php echo esc_html__('All', 'll-tools-text-domain'); ?></button>
                    <button type="button" class="ll-study-btn ghost" data-ll-uncheck-all><?php echo esc_html__('None', 'll-tools-text-domain'); ?></button>
                </div>
                <div id="ll-study-categories" data-ll-study-categories></div>
                <p class="ll-study-empty" data-ll-cat-empty><?php echo esc_html($i18n['noCategories']); ?></p>
            </div>
        </div>

        <div class="ll-study-words-section">
            <div class="ll-study-card ll-study-words-card">
                <div class="ll-card-title">
                    <span><?php echo esc_html($i18n['wordsLabel']); ?></span>
                    <span class="ll-badge" data-ll-star-count>0</span>
                </div>
                <div id="ll-study-words" data-ll-study-words></div>
                <p class="ll-study-empty" data-ll-words-empty><?php echo esc_html($i18n['noWords']); ?></p>
            </div>
        </div>

        <div class="ll-study-flashcard">
            <?php echo $flashcard_markup; ?>
        </div>

        <div class="ll-study-check" data-ll-study-check aria-hidden="true">
            <div class="ll-study-check-card" role="dialog" aria-modal="true" aria-labelledby="ll-study-check-title">
                <div class="ll-study-check-header">
                    <div>
                        <span id="ll-study-check-title" class="ll-study-check-title"><?php echo esc_html($i18n['checkTitle']); ?></span>
                        <span class="ll-study-check-category" data-ll-study-check-category></span>
                    </div>
                    <div class="ll-study-check-meta">
                        <span class="ll-study-check-progress" data-ll-study-check-progress></span>
                    </div>
                    <button type="button" class="ll-study-check-close" data-ll-study-check-exit aria-label="<?php echo esc_attr($i18n['checkExit']); ?>">&times;</button>
                </div>

                <div class="ll-study-check-flip-card" data-ll-study-check-card>
                    <div class="ll-study-check-card-inner" data-ll-study-check-card-inner>
                        <div class="ll-study-check-prompt ll-study-check-face ll-study-check-face--front" data-ll-study-check-prompt></div>
                        <div class="ll-study-check-prompt ll-study-check-face ll-study-check-face--back" data-ll-study-check-answer></div>
                    </div>
                    <button type="button" class="ll-study-check-flip" data-ll-study-check-flip aria-label="<?php echo esc_attr($i18n['checkFlipLabel']); ?>">
                        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M20 7h-9a6 6 0 1 0 0 12h4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M20 7l-3-3M20 7l-3 3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </button>
                </div>

                <div class="ll-study-check-actions" data-ll-study-check-actions>
                    <button type="button" class="ll-study-btn ll-study-check-btn ll-study-check-btn--know" data-ll-study-check-know>
                        <span class="ll-study-check-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none">
                                <path d="M5 13l4 4L19 7" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </span>
                        <span class="ll-study-check-btn-text"><?php echo esc_html($i18n['checkKnow']); ?></span>
                    </button>
                    <button type="button" class="ll-study-btn ll-study-check-btn ll-study-check-btn--unknown" data-ll-study-check-unknown>
                        <span class="ll-study-check-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none">
                                <path d="M6 6l12 12M18 6l-12 12" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </span>
                        <span class="ll-study-check-btn-text"><?php echo esc_html($i18n['checkDontKnow']); ?></span>
                    </button>
                </div>

                <div class="ll-study-check-complete" data-ll-study-check-complete style="display:none;">
                    <p class="ll-study-check-summary" data-ll-study-check-summary></p>
                    <p class="ll-study-check-hint"><?php echo esc_html($i18n['checkApplyHint']); ?></p>
                    <div class="ll-study-check-complete-actions">
                        <button type="button" class="ll-study-btn primary" data-ll-study-check-apply><?php echo esc_html($i18n['checkApply']); ?></button>
                        <button type="button" class="ll-study-btn ghost" data-ll-study-check-restart><?php echo esc_html($i18n['checkRestart']); ?></button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
    return (string) ob_get_clean();
}
add_shortcode('ll_user_study_dashboard', 'll_tools_user_study_dashboard_shortcode');
