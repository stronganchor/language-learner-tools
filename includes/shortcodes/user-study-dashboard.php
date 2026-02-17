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
        return ll_tools_render_login_window([
            'container_class' => 'll-user-study-dashboard ll-login-required',
            'title' => __('Sign in to study', 'll-tools-text-domain'),
            'message' => __('Use your account to open your study panel and keep your progress.', 'll-tools-text-domain'),
            'submit_label' => __('Open study panel', 'll-tools-text-domain'),
            'redirect_to' => ll_tools_get_current_request_url(),
        ]);
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
    $start_next_icon_svg = <<<'SVG'
<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" fill="currentColor" aria-hidden="true">
  <rect x="4" y="10" width="11" height="4"/>
  <path d="M13 6l7 6-7 6z"/>
</svg>
SVG;
    $render_start_next_icon = function () use ($start_next_icon_svg): void {
        echo '<span class="ll-vocab-lesson-mode-icon" aria-hidden="true">' . $start_next_icon_svg . '</span>';
    };
    ll_tools_user_study_enqueue_assets();

    $i18n = [
        'wordsetLabel'     => __('Word set', 'll-tools-text-domain'),
        'categoriesLabel'  => __('Categories', 'll-tools-text-domain'),
        'starredWordsLabel' => __('Starred words', 'll-tools-text-domain'),
        'wordsLabel'       => __('Words', 'll-tools-text-domain'),
        'nextLabel'        => __('Next', 'll-tools-text-domain'),
        'nextStart'        => __('Start next', 'll-tools-text-domain'),
        'nextNone'         => __('No recommendation yet. Pick categories or do one round first.', 'll-tools-text-domain'),
        'nextReady'        => __('Recommended: %1$s in %2$s (%3$d words).', 'll-tools-text-domain'),
        'nextReadyNoCount' => __('Recommended: %1$s in %2$s.', 'll-tools-text-domain'),
        'resultsRedoChunk' => __('Repeat', 'll-tools-text-domain'),
        'resultsDifferentChunk' => __('Categories', 'll-tools-text-domain'),
        'resultsDifferentChunkCount' => __('Categories (%2$d)', 'll-tools-text-domain'),
        'resultsRecommendedActivity' => __('Recommended', 'll-tools-text-domain'),
        'resultsRecommendedActivityCount' => __('Recommended (%2$d)', 'll-tools-text-domain'),
        'modePractice'     => __('Practice', 'll-tools-text-domain'),
        'modeLearning'     => __('Learn', 'll-tools-text-domain'),
        'modeListening'    => __('Listen', 'll-tools-text-domain'),
        'modeGender'       => __('Gender', 'll-tools-text-domain'),
        'modeSelfCheck'    => __('Self check', 'll-tools-text-domain'),
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
        'checkThinkKnow'   => __('I think I know it', 'll-tools-text-domain'),
        'checkSummary'     => __('Self check complete: %1$d unsure, %2$d wrong, %3$d close, %4$d right.', 'll-tools-text-domain'),
        'checkSummaryCompact' => __('Self check complete: x %1$d, ~ %2$d, ✓ %3$d.', 'll-tools-text-domain'),
        'checkCompleteTitle' => __('Self check complete', 'll-tools-text-domain'),
        'checkPhasePrompt' => __('What do you think this word is?', 'll-tools-text-domain'),
        'checkPhaseResult' => __('Listen, then choose your result.', 'll-tools-text-domain'),
        'checkGotRight'    => __('I got it right', 'll-tools-text-domain'),
        'checkGotClose'    => __('I got close', 'll-tools-text-domain'),
        'checkGotWrong'    => __('I got it wrong', 'll-tools-text-domain'),
        'checkRestart'     => __('Repeat', 'll-tools-text-domain'),
        'checkExit'        => __('Close', 'll-tools-text-domain'),
        'checkEmpty'       => __('No words available for this check.', 'll-tools-text-domain'),
        'checkAutoAdvance' => __('Playing audio, then moving to the next word...', 'll-tools-text-domain'),
        'checkNeedResult'  => __('Now choose how close your answer was.', 'll-tools-text-domain'),
        'goalsLabel'       => __('Learning goals', 'll-tools-text-domain'),
        'enabledModesLabel' => __('Enabled modes', 'll-tools-text-domain'),
        'dailyNewLabel'    => __('New words / day', 'll-tools-text-domain'),
        'dailyNewHint'     => __('Set 0 to focus only on review.', 'll-tools-text-domain'),
        'categorySkip'     => __('Skip', 'll-tools-text-domain'),
        'categoryUnskip'   => __('Use', 'll-tools-text-domain'),
        'categoryKnown'    => __('Known', 'll-tools-text-domain'),
        'categoryUnknown'  => __('Unknown', 'll-tools-text-domain'),
        'markKnownSelected' => __('Mark selected as known', 'll-tools-text-domain'),
        'clearKnownSelected' => __('Clear known on selected', 'll-tools-text-domain'),
        'analyticsLabel' => __('Progress', 'll-tools-text-domain'),
        'analyticsLoading' => __('Loading progress...', 'll-tools-text-domain'),
        'analyticsUnavailable' => __('Progress is unavailable right now.', 'll-tools-text-domain'),
        'analyticsScopeSelected' => __('Selected categories (%d)', 'll-tools-text-domain'),
        'analyticsScopeAll' => __('All categories (%d)', 'll-tools-text-domain'),
        'analyticsMastered' => __('Learned', 'll-tools-text-domain'),
        'analyticsStudied' => __('Studied', 'll-tools-text-domain'),
        'analyticsNew' => __('New', 'll-tools-text-domain'),
        'analyticsStarred' => __('Starred', 'll-tools-text-domain'),
        'analyticsHard' => __('Hard', 'll-tools-text-domain'),
        'analyticsDaily' => __('Last 14 days', 'll-tools-text-domain'),
        'analyticsDailyEmpty' => __('No activity yet.', 'll-tools-text-domain'),
        'analyticsTabCategories' => __('Categories', 'll-tools-text-domain'),
        'analyticsTabWords' => __('Words', 'll-tools-text-domain'),
        'analyticsNoRows' => __('No data yet.', 'll-tools-text-domain'),
        'analyticsWordFilterAll' => __('All', 'll-tools-text-domain'),
        'analyticsWordFilterHard' => __('Hardest', 'll-tools-text-domain'),
        'analyticsWordFilterNew' => __('Unseen', 'll-tools-text-domain'),
        'analyticsUnseen' => __('Unseen', 'll-tools-text-domain'),
        'analyticsWordStatusMastered' => __('Learned', 'll-tools-text-domain'),
        'analyticsWordStatusStudied' => __('Studied', 'll-tools-text-domain'),
        'analyticsWordStatusNew' => __('New', 'll-tools-text-domain'),
        'analyticsStarWord' => __('Star word', 'll-tools-text-domain'),
        'analyticsUnstarWord' => __('Unstar word', 'll-tools-text-domain'),
        'analyticsLast' => __('Last', 'll-tools-text-domain'),
        'analyticsNever' => __('Never', 'll-tools-text-domain'),
        'analyticsDayEvents' => __('%1$d events, %2$d words', 'll-tools-text-domain'),
    ];

    wp_localize_script('ll-tools-study-dashboard', 'llToolsStudyData', [
        'ajaxUrl'  => admin_url('admin-ajax.php'),
        'nonce'    => $nonce,
        'payload'  => $payload,
        'modeUi'   => $mode_ui,
        'i18n'     => $i18n,
    ]);

    // Embed the flashcard widget; we keep it non-embed so the header (category label/results UI) shows.
    $flashcard_markup = do_shortcode('[flashcard_widget embed="false" wordset="" wordset_fallback="false" quiz_mode="practice"]');

    ob_start();
    ?>
    <div class="ll-user-study-dashboard" data-ll-study-root>
        <div class="ll-study-header">
            <div class="ll-study-header-title">
                <h2><?php echo esc_html__('Your study plan', 'll-tools-text-domain'); ?></h2>
            </div>
            <div class="ll-study-grid ll-study-grid--hero">
                <div class="ll-study-card ll-study-wordset-card">
                    <label for="ll-study-wordset"><?php echo esc_html($i18n['wordsetLabel']); ?></label>
                    <select id="ll-study-wordset" data-ll-study-wordset></select>
                    <p class="ll-study-hint"><?php echo esc_html__('Switch word sets to load their categories and words.', 'll-tools-text-domain'); ?></p>
                </div>
                <button type="button" class="ll-study-card ll-study-analytics-mini" data-ll-analytics-open aria-expanded="false" aria-controls="ll-study-analytics-screen">
                    <span class="ll-card-title ll-study-analytics-mini-title-row">
                        <span><?php echo esc_html($i18n['analyticsLabel']); ?></span>
                        <span class="ll-study-analytics-mini-open" aria-hidden="true">
                            <svg class="ll-study-analytics-mini-open-icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path d="M6 9l6 6 6-6" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"></path>
                            </svg>
                        </span>
                    </span>
                    <span class="ll-study-analytics-mini-scope" data-ll-analytics-mini-scope></span>
                    <span class="ll-study-analytics-mini-summary">
                        <span class="ll-study-analytics-mini-pill ll-study-analytics-mini-pill--mastered">
                            <span class="ll-study-analytics-mini-icon-wrap" data-ll-mini-icon="mastered"></span>
                            <span class="ll-study-analytics-mini-value" data-ll-mini-value="mastered">0</span>
                            <span class="ll-study-analytics-mini-label"><?php echo esc_html($i18n['analyticsMastered']); ?></span>
                        </span>
                        <span class="ll-study-analytics-mini-pill ll-study-analytics-mini-pill--studied">
                            <span class="ll-study-analytics-mini-icon-wrap" data-ll-mini-icon="studied"></span>
                            <span class="ll-study-analytics-mini-value" data-ll-mini-value="studied">0</span>
                            <span class="ll-study-analytics-mini-label"><?php echo esc_html($i18n['analyticsStudied']); ?></span>
                        </span>
                        <span class="ll-study-analytics-mini-pill ll-study-analytics-mini-pill--new">
                            <span class="ll-study-analytics-mini-icon-wrap" data-ll-mini-icon="new"></span>
                            <span class="ll-study-analytics-mini-value" data-ll-mini-value="new">0</span>
                            <span class="ll-study-analytics-mini-label"><?php echo esc_html($i18n['analyticsUnseen']); ?></span>
                        </span>
                    </span>
                </button>
            </div>
            <div class="ll-study-actions">
                <button type="button" class="ll-study-btn ll-vocab-lesson-mode-button" data-ll-study-start-next disabled>
                    <?php $render_start_next_icon(); ?>
                    <span class="ll-vocab-lesson-mode-label"><?php echo esc_html($i18n['nextStart']); ?></span>
                </button>
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
        <div class="ll-study-next-card">
            <span class="ll-card-title-sub"><?php echo esc_html($i18n['nextLabel']); ?></span>
            <p class="ll-study-hint" data-ll-study-next-text><?php echo esc_html($i18n['nextNone']); ?></p>
        </div>

        <div id="ll-study-analytics-screen" class="ll-study-analytics-screen" data-ll-analytics-screen hidden>
            <div class="ll-study-analytics-screen-head">
                <span class="ll-card-title-sub"><?php echo esc_html($i18n['analyticsLabel']); ?></span>
            </div>
            <div class="ll-study-card ll-study-analytics-card" data-ll-analytics-root>
                <div class="ll-card-title ll-study-analytics-title-row">
                    <span><?php echo esc_html($i18n['analyticsLabel']); ?></span>
                    <span class="ll-study-analytics-scope" data-ll-analytics-scope></span>
                </div>
                <p class="ll-study-hint ll-study-analytics-status" data-ll-analytics-status><?php echo esc_html($i18n['analyticsLoading']); ?></p>
                <div class="ll-study-analytics-summary" data-ll-analytics-summary></div>
                <div class="ll-study-analytics-graph-wrap">
                    <span class="ll-card-title-sub"><?php echo esc_html($i18n['analyticsDaily']); ?></span>
                    <div class="ll-study-analytics-graph" data-ll-analytics-graph></div>
                </div>
                <div class="ll-study-analytics-tabs" role="tablist" aria-label="<?php echo esc_attr($i18n['analyticsLabel']); ?>">
                    <button type="button" class="ll-study-btn tiny ghost active" data-ll-analytics-tab="categories" aria-selected="true"><?php echo esc_html($i18n['analyticsTabCategories']); ?></button>
                    <button type="button" class="ll-study-btn tiny ghost" data-ll-analytics-tab="words" aria-selected="false"><?php echo esc_html($i18n['analyticsTabWords']); ?></button>
                </div>
                <div class="ll-study-analytics-panel" data-ll-analytics-panel="categories">
                    <div class="ll-study-analytics-table-wrap">
                        <table class="ll-study-analytics-table ll-study-analytics-table--categories">
                            <thead>
                                <tr>
                                    <th scope="col"><?php echo esc_html($i18n['analyticsTabCategories']); ?></th>
                                    <th scope="col"><?php echo esc_html($i18n['analyticsStudied']); ?></th>
                                    <th scope="col"><?php echo esc_html($i18n['analyticsMastered']); ?></th>
                                    <th scope="col"><?php echo esc_html__('Activity', 'll-tools-text-domain'); ?></th>
                                    <th scope="col"><?php echo esc_html($i18n['analyticsLast']); ?></th>
                                </tr>
                            </thead>
                            <tbody data-ll-analytics-categories-body>
                                <tr>
                                    <td colspan="5"><?php echo esc_html($i18n['analyticsNoRows']); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="ll-study-analytics-panel" data-ll-analytics-panel="words" hidden>
                    <div class="ll-study-analytics-word-filters">
                        <button type="button" class="ll-study-btn tiny ghost active" data-ll-analytics-word-filter="all"><?php echo esc_html($i18n['analyticsWordFilterAll']); ?></button>
                        <button type="button" class="ll-study-btn tiny ghost" data-ll-analytics-word-filter="hard"><?php echo esc_html($i18n['analyticsWordFilterHard']); ?></button>
                        <button type="button" class="ll-study-btn tiny ghost" data-ll-analytics-word-filter="new"><?php echo esc_html($i18n['analyticsWordFilterNew']); ?></button>
                    </div>
                    <div class="ll-study-analytics-table-wrap">
                        <table class="ll-study-analytics-table ll-study-analytics-table--words">
                            <thead>
                                <tr>
                                    <th scope="col"><span class="screen-reader-text"><?php echo esc_html($i18n['starLabel']); ?></span></th>
                                    <th scope="col"><?php echo esc_html($i18n['analyticsTabWords']); ?></th>
                                    <th scope="col"><?php echo esc_html($i18n['analyticsTabCategories']); ?></th>
                                    <th scope="col"><?php echo esc_html__('Status', 'll-tools-text-domain'); ?></th>
                                    <th scope="col"><?php echo esc_html($i18n['analyticsHard']); ?></th>
                                    <th scope="col"><?php echo esc_html__('Seen', 'll-tools-text-domain'); ?></th>
                                    <th scope="col"><?php echo esc_html__('Wrong', 'll-tools-text-domain'); ?></th>
                                    <th scope="col"><?php echo esc_html($i18n['analyticsLast']); ?></th>
                                </tr>
                            </thead>
                            <tbody data-ll-analytics-words-body>
                                <tr>
                                    <td colspan="8"><?php echo esc_html($i18n['analyticsNoRows']); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="ll-study-main" data-ll-study-main>
            <div class="ll-study-grid ll-study-grid--top">
                <div class="ll-study-card ll-study-controls-card">
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
                    <div class="ll-study-goals">
                        <span class="ll-card-title-sub"><?php echo esc_html($i18n['goalsLabel']); ?></span>
                        <span class="ll-study-goal-label"><?php echo esc_html($i18n['enabledModesLabel']); ?></span>
                        <div class="ll-star-toggle-group" data-ll-goals-modes>
                            <button type="button" class="ll-study-btn tiny" data-goal-mode="learning"><?php echo esc_html($i18n['modeLearning']); ?></button>
                            <button type="button" class="ll-study-btn tiny" data-goal-mode="listening"><?php echo esc_html($i18n['modeListening']); ?></button>
                            <button type="button" class="ll-study-btn tiny" data-goal-mode="practice"><?php echo esc_html($i18n['modePractice']); ?></button>
                            <button type="button" class="ll-study-btn tiny ll-study-btn--hidden" data-goal-mode="gender" data-ll-study-goal-gender aria-hidden="true"><?php echo esc_html($i18n['modeGender']); ?></button>
                            <button type="button" class="ll-study-btn tiny" data-goal-mode="self-check"><?php echo esc_html($i18n['modeSelfCheck']); ?></button>
                        </div>
                        <label for="ll-study-daily-new" class="ll-study-goal-input-label"><?php echo esc_html($i18n['dailyNewLabel']); ?></label>
                        <input id="ll-study-daily-new" class="ll-study-goal-input" data-ll-goal-daily-new type="number" min="0" max="12" step="1" value="2" />
                        <p class="ll-study-hint"><?php echo esc_html($i18n['dailyNewHint']); ?></p>
                        <div class="ll-study-goal-actions">
                            <button type="button" class="ll-study-btn tiny ghost" data-ll-goal-mark-known><?php echo esc_html($i18n['markKnownSelected']); ?></button>
                            <button type="button" class="ll-study-btn tiny ghost" data-ll-goal-clear-known><?php echo esc_html($i18n['clearKnownSelected']); ?></button>
                        </div>
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
                </div>

                <div class="ll-study-check-actions" data-ll-study-check-actions></div>

                <div class="ll-study-check-complete" data-ll-study-check-complete style="display:none;">
                    <p class="ll-study-check-summary" data-ll-study-check-summary></p>
                    <div class="ll-study-check-complete-actions">
                        <button type="button" class="ll-study-btn ll-vocab-lesson-mode-button ll-study-followup-mode-button" data-ll-study-check-restart><?php echo esc_html($i18n['checkRestart']); ?></button>
                        <span class="ll-study-check-followup ll-study-check-followup-inline" data-ll-study-check-followup style="display:none;">
                            <button type="button" class="ll-study-btn ll-vocab-lesson-mode-button ll-study-followup-mode-button" data-ll-study-check-followup-different><?php echo esc_html($i18n['categoriesLabel']); ?></button>
                            <button type="button" class="ll-study-btn ll-vocab-lesson-mode-button ll-study-followup-mode-button" data-ll-study-check-followup-next><?php echo esc_html__('Recommended', 'll-tools-text-domain'); ?></button>
                        </span>
                    </div>
                    <p class="ll-study-check-hint" data-ll-study-check-followup-text style="display:none;"></p>
                </div>
            </div>
        </div>
    </div>
    <?php
    return (string) ob_get_clean();
}
add_shortcode('ll_user_study_dashboard', 'll_tools_user_study_dashboard_shortcode');
