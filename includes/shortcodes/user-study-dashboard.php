<?php
// /includes/shortcodes/user-study-dashboard.php
if (!defined('WPINC')) { die; }

/**
 * Shortcode to render a user-facing study dashboard with wordset/category/word selections.
 * Usage: [ll_user_study_dashboard]
 */
function ll_tools_user_study_enqueue_assets() {
    ll_enqueue_asset_by_timestamp('/css/user-study-dashboard.css', 'll-tools-study-dashboard');
    ll_enqueue_asset_by_timestamp('/js/user-study-dashboard.js', 'll-tools-study-dashboard', ['jquery'], true);
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

    ll_tools_user_study_enqueue_assets();

    $i18n = [
        'wordsetLabel'     => __('Word set', 'll-tools-text-domain'),
        'categoriesLabel'  => __('Categories', 'll-tools-text-domain'),
        'wordsLabel'       => __('Words', 'll-tools-text-domain'),
        'starLabel'        => __('Starred', 'll-tools-text-domain'),
        'saveSuccess'      => __('Saved.', 'll-tools-text-domain'),
        'practice'         => __('Practice', 'll-tools-text-domain'),
        'learning'         => __('Learn', 'll-tools-text-domain'),
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
                <button type="button" class="ll-study-btn primary" data-ll-study-start data-mode="practice"><?php echo esc_html($i18n['practice']); ?></button>
                <button type="button" class="ll-study-btn" data-ll-study-start data-mode="learning"><?php echo esc_html($i18n['learning']); ?></button>
                <button type="button" class="ll-study-btn" data-ll-study-start data-mode="listening"><?php echo esc_html($i18n['listening']); ?></button>
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
    </div>
    <?php
    return (string) ob_get_clean();
}
add_shortcode('ll_user_study_dashboard', 'll_tools_user_study_dashboard_shortcode');
