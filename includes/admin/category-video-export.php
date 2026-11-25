<?php
/** Category slideshow authoring. Rendering stays on the administrator's device. */
if (!defined('ABSPATH')) { exit; }

require_once LL_TOOLS_BASE_PATH . 'includes/lib/category-video-data.php';

function ll_tools_register_category_video_page(): void {
    add_management_page(
        __('Category Video', 'll-tools-text-domain'),
        __('Category Video', 'll-tools-text-domain'),
        'manage_options',
        'll-category-video',
        'll_tools_render_category_video_page'
    );
}
add_action('admin_menu', 'll_tools_register_category_video_page');

function ll_tools_enqueue_category_video_assets(string $hook): void {
    if ($hook !== 'tools_page_ll-category-video' || !current_user_can('manage_options') || !current_user_can('view_ll_tools')) {
        return;
    }
    ll_enqueue_asset_by_timestamp('/css/category-video-export.css', 'll-category-video');
    ll_enqueue_asset_by_timestamp('/css/ipa-fonts.css', 'll-category-video-fonts');
    ll_enqueue_asset_by_timestamp('/js/category-video-renderer.js', 'll-category-video-renderer', [], true);
    ll_enqueue_asset_by_timestamp('/js/category-video-export.js', 'll-category-video-export', ['ll-category-video-renderer'], true);
    wp_localize_script('ll-category-video-export', 'llCategoryVideo', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('ll_tools_category_video'),
        'maxItems' => 30,
        'strings' => [
            'chooseWordset' => __('Choose a word set', 'll-tools-text-domain'),
            'chooseCategory' => __('Choose a category', 'll-tools-text-domain'),
            'allSpeakers' => __('Any speaker', 'll-tools-text-domain'),
            'loading' => __('Loading…', 'll-tools-text-domain'),
            'chooseWords' => __('Choose words, then prepare your preview.', 'll-tools-text-domain'),
            'selection' => __('%1$d of %2$d words selected', 'll-tools-text-domain'),
            'limit' => __('Select up to 30 words per video. Make another video for the remaining words.', 'll-tools-text-domain'),
            'empty' => __('No usable words were found in this batch. Check the skipped words or load the next batch.', 'll-tools-text-domain'),
            'complete' => __('All words in this category have been checked.', 'll-tools-text-domain'),
            'more' => __('More words are available. Load the next batch to include them.', 'll-tools-text-domain'),
            'preparing' => __('Preparing media: %1$d / %2$d', 'll-tools-text-domain'),
            'ready' => __('Ready · %1$d words · %2$s', 'll-tools-text-domain'),
            'playing' => __('Previewing · %1$s / %2$s', 'll-tools-text-domain'),
            'recording' => __('Creating video · %1$s / %2$s', 'll-tools-text-domain'),
            'finished' => __('Your video is ready. Watch it below, then download it.', 'll-tools-text-domain'),
            'download' => __('Download %s', 'll-tools-text-domain'),
            'canceled' => __('Stopped. You can prepare or play the video again.', 'll-tools-text-domain'),
            'changed' => __('Settings changed. Prepare a new preview to apply them.', 'll-tools-text-domain'),
            'requestError' => __('Could not load this category. Please try again.', 'll-tools-text-domain'),
            'sourceLimit' => __('This source list is too large to load at once.', 'll-tools-text-domain'),
            'moveUp' => __('Move up', 'll-tools-text-domain'),
            'moveDown' => __('Move down', 'll-tools-text-domain'),
            'skipped' => __('Skipped words (%d)', 'll-tools-text-domain'),
            'skipReasons' => [
                'missing_image' => __('No usable image', 'll-tools-text-domain'),
                'missing_audio' => __('No published isolation recording for this speaker', 'll-tools-text-domain'),
                'text_needs_review' => __('Recording text needs review', 'll-tools-text-domain'),
                'recording_limit' => __('Too many recordings to choose safely; narrow the speaker selection', 'll-tools-text-domain'),
                'missing_text' => __('No target-language label', 'll-tools-text-domain'),
                'unavailable' => __('Media or label unavailable', 'll-tools-text-domain'),
            ],
            'errors' => [
                'unsupported' => __('This browser cannot create videos. Try a current desktop Chrome or Edge browser.', 'll-tools-text-domain'),
                'invalid_items' => __('Choose between 1 and 30 words with pictures and audio.', 'll-tools-text-domain'),
                'invalid_options' => __('Check the video settings and try again.', 'll-tools-text-domain'),
                'media_fetch' => __('A media file could not be loaded. Check its URL and cross-origin permissions, then retry.', 'll-tools-text-domain'),
                'media_too_large' => __('A media file is too large for this browser video.', 'll-tools-text-domain'),
                'image_decode' => __('A picture could not be opened.', 'll-tools-text-domain'),
                'audio_decode' => __('An audio recording could not be opened.', 'll-tools-text-domain'),
                'audio_too_long' => __('Use recordings shorter than 30 seconds for this tool.', 'll-tools-text-domain'),
                'project_too_long' => __('Keep each video under 10 minutes. Choose fewer words or repetitions.', 'll-tools-text-domain'),
                'memory_limit' => __('These media files need too much memory. Choose fewer words.', 'll-tools-text-domain'),
                'recording_failed' => __('Video creation failed. Please try again or use another browser.', 'll-tools-text-domain'),
                'recording_empty' => __('The browser returned an empty video. Please try again.', 'll-tools-text-domain'),
                'recording_timeout' => __('Video creation timed out. Please try a shorter video.', 'll-tools-text-domain'),
                'hidden' => __('Video creation stopped because this tab was hidden. Keep this tab visible while creating a video.', 'll-tools-text-domain'),
                'audio_suspended' => __('Audio playback was interrupted. Keep this tab active and try again.', 'll-tools-text-domain'),
                'not_prepared' => __('Prepare the preview first.', 'll-tools-text-domain'),
                'busy' => __('Wait for the current operation or stop it first.', 'll-tools-text-domain'),
                'disposed' => __('Prepare a new preview and try again.', 'll-tools-text-domain'),
                'canceled' => __('Stopped.', 'll-tools-text-domain'),
            ],
        ],
    ]);
}
add_action('admin_enqueue_scripts', 'll_tools_enqueue_category_video_assets');

function ll_tools_render_category_video_page(): void {
    if (!current_user_can('manage_options') || !current_user_can('view_ll_tools')) {
        wp_die(esc_html__('You do not have permission to create category videos.', 'll-tools-text-domain'));
    }
    ?>
    <div class="wrap ll-video" id="ll-category-video">
        <h1><?php esc_html_e('Category Video', 'll-tools-text-domain'); ?></h1>
        <p class="ll-video-intro"><?php esc_html_e('Turn vocabulary pictures and original recordings into a short learning video.', 'll-tools-text-domain'); ?></p>
        <div class="ll-video-layout">
            <div class="ll-video-editor">
                <section class="ll-video-panel" aria-labelledby="ll-video-source-heading">
                    <h2 id="ll-video-source-heading"><?php esc_html_e('1. Choose your words', 'll-tools-text-domain'); ?></h2>
                    <div class="ll-video-fields">
                        <label><?php esc_html_e('Word set', 'll-tools-text-domain'); ?><select id="ll-video-wordset" disabled></select></label>
                        <label><?php esc_html_e('Category', 'll-tools-text-domain'); ?><select id="ll-video-category" disabled></select></label>
                        <label><?php esc_html_e('Speaker', 'll-tools-text-domain'); ?><select id="ll-video-speaker" disabled></select></label>
                    </div>
                    <div class="ll-video-actions">
                        <button type="button" class="button button-secondary" id="ll-video-load" disabled><?php esc_html_e('Load words', 'll-tools-text-domain'); ?></button>
                        <button type="button" class="button" id="ll-video-retry-sources" hidden><?php esc_html_e('Reload choices', 'll-tools-text-domain'); ?></button>
                    </div>
                    <p class="description"><?php esc_html_e('Uses published words with pictures and isolation recordings. Recordings with text awaiting review are excluded.', 'll-tools-text-domain'); ?></p>
                    <div id="ll-video-word-section" hidden>
                        <div class="ll-video-selection"><strong id="ll-video-count"></strong><button type="button" class="button-link" id="ll-video-select-all"><?php esc_html_e('Select first 30', 'll-tools-text-domain'); ?></button><button type="button" class="button-link" id="ll-video-select-none"><?php esc_html_e('Clear selection', 'll-tools-text-domain'); ?></button></div>
                        <fieldset id="ll-video-word-controls" aria-labelledby="ll-video-source-heading">
                            <ol id="ll-video-words" class="ll-video-words"></ol>
                        </fieldset>
                        <p id="ll-video-page-status" class="description"></p>
                        <button type="button" class="button" id="ll-video-more" hidden><?php esc_html_e('Load more words', 'll-tools-text-domain'); ?></button>
                        <details id="ll-video-skipped" hidden><summary></summary><ul></ul></details>
                    </div>
                </section>
                <section class="ll-video-panel" aria-labelledby="ll-video-settings-heading">
                    <h2 id="ll-video-settings-heading"><?php esc_html_e('2. Set the pace', 'll-tools-text-domain'); ?></h2>
                    <fieldset id="ll-video-settings">
                        <div class="ll-video-fields">
                            <label><?php esc_html_e('Video title', 'll-tools-text-domain'); ?><input id="ll-video-title" type="text" maxlength="100"></label>
                            <label><?php esc_html_e('Shape', 'll-tools-text-domain'); ?><select id="ll-video-layout"><option value="portrait"><?php esc_html_e('Portrait · 9:16', 'll-tools-text-domain'); ?></option><option value="landscape"><?php esc_html_e('Landscape · 16:9', 'll-tools-text-domain'); ?></option><option value="square"><?php esc_html_e('Square · 1:1', 'll-tools-text-domain'); ?></option></select></label>
                            <label><?php esc_html_e('Play each word', 'll-tools-text-domain'); ?><select id="ll-video-repeats"><option value="1"><?php esc_html_e('Once', 'll-tools-text-domain'); ?></option><option value="2" selected><?php esc_html_e('Twice', 'll-tools-text-domain'); ?></option><option value="3"><?php esc_html_e('Three times', 'll-tools-text-domain'); ?></option></select></label>
                            <label><?php esc_html_e('Pause after audio (seconds)', 'll-tools-text-domain'); ?><input id="ll-video-gap" type="number" min="0.5" max="5" step="0.5" value="1.5"></label>
                            <label><?php esc_html_e('Thinking time before answer (seconds)', 'll-tools-text-domain'); ?><input id="ll-video-recall" type="number" min="0" max="5" step="0.5" value="0"></label>
                        </div>
                        <label class="ll-video-checkbox"><input id="ll-video-show-text" type="checkbox" checked><?php esc_html_e('Show the written word with the answer', 'll-tools-text-domain'); ?></label>
                    </fieldset>
                    <p class="description"><?php esc_html_e('Thinking time shows the picture before revealing the word and playing its recording.', 'll-tools-text-domain'); ?></p>
                </section>
            </div>
            <section class="ll-video-panel ll-video-preview" aria-labelledby="ll-video-preview-heading">
                <h2 id="ll-video-preview-heading"><?php esc_html_e('3. Preview and create', 'll-tools-text-domain'); ?></h2>
                <canvas id="ll-video-canvas" width="1080" height="1920" aria-label="<?php esc_attr_e('Vocabulary video preview', 'll-tools-text-domain'); ?>"></canvas>
                <div class="ll-video-actions">
                    <button type="button" class="button button-secondary" id="ll-video-prepare" disabled><?php esc_html_e('Prepare preview', 'll-tools-text-domain'); ?></button>
                    <button type="button" class="button" id="ll-video-play" disabled><?php esc_html_e('Play preview', 'll-tools-text-domain'); ?></button>
                    <button type="button" class="button button-primary" id="ll-video-record" disabled><?php esc_html_e('Create video', 'll-tools-text-domain'); ?></button>
                    <button type="button" class="button" id="ll-video-stop" hidden><?php esc_html_e('Stop', 'll-tools-text-domain'); ?></button>
                </div>
                <progress id="ll-video-progress" max="1" value="0" hidden></progress>
                <p id="ll-video-status" role="status" aria-live="polite"></p>
                <p class="description"><?php esc_html_e('Keep this tab visible during video creation. It takes about as long as the video. The file stays on your device; nothing is published.', 'll-tools-text-domain'); ?></p>
                <p class="description"><?php esc_html_e('MP4 is used when your browser supports it; otherwise the download is WebM.', 'll-tools-text-domain'); ?></p>
                <div id="ll-video-result" hidden><video controls playsinline preload="metadata"></video><a class="button button-primary" id="ll-video-download"></a></div>
            </section>
        </div>
        <noscript><p><?php esc_html_e('Enable JavaScript to create category videos.', 'll-tools-text-domain'); ?></p></noscript>
    </div>
    <?php
}
