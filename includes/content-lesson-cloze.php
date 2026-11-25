<?php
// Dedicated, bounded passage activities. Source stays on the server; the bank has no slot mapping.
if (!defined('WPINC')) { die; }

define('LL_TOOLS_CONTENT_LESSON_CLOZE_SOURCE_META', '_ll_tools_content_lesson_cloze_source');
define('LL_TOOLS_CONTENT_LESSON_CLOZE_ERROR_META', '_ll_tools_content_lesson_cloze_error');

/** @return array|WP_Error */
function ll_tools_cloze_parse_source($source) {
    if (!is_string($source) || strlen($source) > 20000 || wp_check_invalid_utf8($source) !== $source) {
        return new WP_Error('cloze_source_limit', __('Use valid text of no more than 20,000 bytes.', 'll-tools-text-domain'));
    }
    $source = str_replace(["\r\n", "\r"], "\n", $source);
    if (strpos($source, "\0") !== false || wp_strip_all_tags($source) !== trim($source)) {
        return new WP_Error('cloze_plain_text', __('Use plain text without HTML.', 'll-tools-text-domain'));
    }
    $segments = [];
    $answers = [];
    $cursor = 0;
    while (($start = strpos($source, '[[', $cursor)) !== false) {
        $text = substr($source, $cursor, $start - $cursor);
        $end = strpos($source, ']]', $start + 2);
        if ($end === false || strpos($text, ']]') !== false) {
            return new WP_Error('cloze_markers', __('Put each missing word inside matching [[ and ]] markers.', 'll-tools-text-domain'));
        }
        $answer = substr($source, $start + 2, $end - $start - 2);
        if (trim($answer) === '' || trim($answer) !== $answer || strlen($answer) > 300
            || strpos($answer, '[[') !== false || strpos($answer, "\n") !== false) {
            return new WP_Error('cloze_answer', __('Each blank needs a word or short phrase, without surrounding spaces or nested markers.', 'll-tools-text-domain'));
        }
        if (count($answers) >= 60) {
            return new WP_Error('cloze_blank_limit', __('Use no more than 60 blanks in one lesson.', 'll-tools-text-domain'));
        }
        $segments[] = ['text' => $text];
        $segments[] = ['blank' => count($answers)];
        $answers[] = $answer;
        $cursor = $end + 2;
    }
    $tail = substr($source, $cursor);
    if (strpos($tail, ']]') !== false || empty($answers)) {
        return new WP_Error('cloze_markers', __('Put each missing word inside matching [[ and ]] markers.', 'll-tools-text-domain'));
    }
    $segments[] = ['text' => $tail];
    return ['source' => $source, 'segments' => $segments, 'answers' => $answers];
}

function ll_tools_cloze_source(int $lesson_id): string {
    return (string) get_post_meta($lesson_id, LL_TOOLS_CONTENT_LESSON_CLOZE_SOURCE_META, true);
}

function ll_tools_cloze_can_view(int $lesson_id): bool {
    $post = get_post($lesson_id);
    if (!($post instanceof WP_Post) || $post->post_type !== 'll_content_lesson'
        || ll_tools_get_content_lesson_kind($lesson_id) !== 'cloze'
        || ($post->post_status !== 'publish' && !current_user_can('edit_post', $lesson_id))
        || post_password_required($post)) {
        return false;
    }
    $complete = true;
    $wordset_id = ll_tools_get_content_lesson_wordset_id_authoritative($lesson_id, $complete);
    return $complete && $wordset_id > 0 && ll_tools_user_can_view_wordset($wordset_id);
}

function ll_tools_cloze_token(string $salt, int $index): string {
    return substr(hash_hmac('sha256', $salt . ':' . $index, wp_salt('nonce')), 0, 32);
}

function ll_tools_cloze_signature(int $lesson_id, string $version, string $salt): string {
    return hash_hmac('sha256', $lesson_id . ':' . $version . ':' . $salt, wp_salt('nonce'));
}

/** Only public segments and a shuffled bank; never include the expected slot answers. */
function ll_tools_cloze_payload(int $lesson_id, array $parsed): array {
    $salt = str_replace('-', '', wp_generate_uuid4());
    $version = hash('sha256', $parsed['source']);
    $bank = [];
    foreach ($parsed['answers'] as $index => $answer) {
        $bank[] = ['id' => ll_tools_cloze_token($salt, $index), 'text' => $answer];
    }
    shuffle($bank);
    return [
        'lessonId' => $lesson_id, 'segments' => $parsed['segments'], 'bank' => $bank,
        'version' => $version, 'salt' => $salt,
        'signature' => ll_tools_cloze_signature($lesson_id, $version, $salt),
    ];
}

function ll_tools_cloze_strings(): array {
    return [
        'instructions' => __('Drag a word into each blank, or select a word and then a blank.', 'll-tools-text-domain'),
        'bank' => __('Word bank', 'll-tools-text-domain'),
        'blank' => __('Blank %d', 'll-tools-text-domain'),
        'filled' => __('Blank %1$d: %2$s. Select to return this word.', 'll-tools-text-domain'),
        'selected' => __('Word selected. Choose a blank.', 'll-tools-text-domain'),
        'placed' => __('Word placed.', 'll-tools-text-domain'),
        'returned' => __('Word returned to the bank.', 'll-tools-text-domain'),
        'check' => __('Check', 'll-tools-text-domain'),
        'checking' => __('Checking...', 'll-tools-text-domain'),
        'reset' => __('Start again', 'll-tools-text-domain'),
        'incomplete' => __('Fill every blank first.', 'll-tools-text-domain'),
        'retry' => __('Try the marked blanks again.', 'll-tools-text-domain'),
        'correct' => __('All correct!', 'll-tools-text-domain'),
        'saved' => __('Progress saved.', 'll-tools-text-domain'),
        'saveError' => __('Your answers are correct, but progress could not be saved. Check again to retry.', 'll-tools-text-domain'),
        'error' => __('Answers could not be checked. Please try again.', 'll-tools-text-domain'),
        'changed' => __('This lesson changed. Reload the page to try the latest version.', 'll-tools-text-domain'),
        'correctBlank' => __('Correct', 'll-tools-text-domain'),
        'incorrectBlank' => __('Try again', 'll-tools-text-domain'),
    ];
}

function ll_tools_cloze_enqueue_assets(): void {
    ll_enqueue_asset_by_timestamp('/css/content-lesson-cloze.css', 'll-tools-content-lesson-cloze', [], false);
    ll_enqueue_asset_by_timestamp('/js/content-lesson-cloze.js', 'll-tools-content-lesson-cloze', [], true);
    wp_localize_script('ll-tools-content-lesson-cloze', 'llToolsCloze', [
        'ajaxUrl' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('ll_tools_cloze_check'),
        'requestTimeoutMs' => 15000, 'i18n' => ll_tools_cloze_strings(),
    ]);
}

function ll_tools_render_cloze_activity(int $lesson_id): string {
    if (!ll_tools_cloze_can_view($lesson_id)) {
        return '';
    }
    $parsed = ll_tools_cloze_parse_source(ll_tools_cloze_source($lesson_id));
    if (is_wp_error($parsed)) {
        return '<p class="ll-content-lesson-empty">' . esc_html__('This activity is not ready yet.', 'll-tools-text-domain') . '</p>';
    }
    $json = wp_json_encode(ll_tools_cloze_payload($lesson_id, $parsed), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    return '<section class="ll-cloze" data-ll-cloze><script type="application/json" data-ll-cloze-payload>' . $json . '</script>'
        . '<noscript>' . esc_html__('Enable JavaScript to use this activity.', 'll-tools-text-domain') . '</noscript></section>';
}

/** Bounded, read-only answer validation; optional completion uses the normal privacy/write lock. */
function ll_tools_cloze_check_request(int $lesson_id, array $request) {
    if (!ll_tools_cloze_can_view($lesson_id)) {
        return new WP_Error('cloze_forbidden', __('This activity is unavailable.', 'll-tools-text-domain'), ['status' => 403]);
    }
    $parsed = ll_tools_cloze_parse_source(ll_tools_cloze_source($lesson_id));
    if (is_wp_error($parsed)) {
        return new WP_Error('cloze_unavailable', __('This activity is not ready yet.', 'll-tools-text-domain'), ['status' => 409]);
    }
    $version = isset($request['version']) && is_string($request['version']) ? $request['version'] : '';
    $salt = isset($request['salt']) && is_string($request['salt']) ? $request['salt'] : '';
    $signature = isset($request['signature']) && is_string($request['signature']) ? $request['signature'] : '';
    if (!preg_match('/^[a-f0-9]{32}$/D', $salt) || !preg_match('/^[a-f0-9]{64}$/D', $version)
        || !hash_equals(ll_tools_cloze_signature($lesson_id, $version, $salt), $signature)) {
        return new WP_Error('cloze_invalid', __('The activity request could not be verified.', 'll-tools-text-domain'), ['status' => 400]);
    }
    if (!hash_equals(hash('sha256', $parsed['source']), $version)) {
        return new WP_Error('cloze_changed', ll_tools_cloze_strings()['changed'], ['status' => 409]);
    }
    $choices = $request['choices'] ?? null;
    if (!is_array($choices) || !ll_tools_array_is_list($choices) || count($choices) !== count($parsed['answers'])) {
        return new WP_Error('cloze_invalid', __('Fill every blank first.', 'll-tools-text-domain'), ['status' => 400]);
    }
    $tokens = [];
    foreach ($parsed['answers'] as $index => $answer) {
        $tokens[ll_tools_cloze_token($salt, $index)] = $answer;
    }
    $used = [];
    $correct = [];
    foreach ($choices as $index => $token) {
        if (!is_string($token) || !isset($tokens[$token]) || isset($used[$token])) {
            return new WP_Error('cloze_invalid', __('Use each word from the bank once.', 'll-tools-text-domain'), ['status' => 400]);
        }
        $used[$token] = true;
        $correct[] = $tokens[$token] === $parsed['answers'][$index];
    }
    $all_correct = !in_array(false, $correct, true);
    $saved = false;
    $save_error = false;
    if ($all_correct && is_user_logged_in() && get_post_status($lesson_id) === 'publish') {
        $result = ll_tools_update_content_lesson_completion_request(get_current_user_id(), $lesson_id, true, true);
        $saved = !is_wp_error($result) && !empty($result['completed']);
        $save_error = !$saved;
    }
    return ['correct' => $correct, 'allCorrect' => $all_correct, 'saved' => $saved, 'saveError' => $save_error];
}

function ll_tools_cloze_check_ajax(): void {
    check_ajax_referer('ll_tools_cloze_check', 'nonce');
    nocache_headers();
    $raw = isset($_POST['attempt']) && is_string($_POST['attempt']) ? wp_unslash($_POST['attempt']) : '';
    if (strlen($raw) > 10000) {
        wp_send_json_error(['message' => __('The activity request is too large.', 'll-tools-text-domain')], 400);
    }
    $request = json_decode($raw, true, 8);
    if (!is_array($request)) {
        wp_send_json_error(['message' => __('The activity request could not be verified.', 'll-tools-text-domain')], 400);
    }
    $result = ll_tools_cloze_check_request(absint($request['lessonId'] ?? 0), $request);
    if (is_wp_error($result)) {
        $data = $result->get_error_data();
        wp_send_json_error(['message' => $result->get_error_message(), 'code' => $result->get_error_code()], (int) ($data['status'] ?? 400));
    }
    wp_send_json_success($result);
}
add_action('wp_ajax_ll_tools_cloze_check', 'll_tools_cloze_check_ajax');
add_action('wp_ajax_nopriv_ll_tools_cloze_check', 'll_tools_cloze_check_ajax');

/** Serialize first writes and normal post updates with autosave; never overwrite a stale draft. */
function ll_tools_cloze_persist_source(int $lesson_id, $source, string $previous_hash) {
    global $wpdb;
    $parsed = ll_tools_cloze_parse_source($source);
    if (is_wp_error($parsed)) { return $parsed; }
    $lock = 'll_cloze_' . md5($wpdb->prefix . ':' . $lesson_id);
    if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock)) !== 1) {
        return new WP_Error('cloze_busy', __('This passage is being saved. Please retry.', 'll-tools-text-domain'), ['status' => 503]);
    }
    try {
        $wpdb->last_error = '';
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 2",
            $lesson_id, LL_TOOLS_CONTENT_LESSON_CLOZE_SOURCE_META
        ));
        if ($wpdb->last_error !== '' || count($rows) > 1) {
            return new WP_Error('cloze_save_failed', __('The passage could not be read safely. Please retry.', 'll-tools-text-domain'), ['status' => 503]);
        }
        $previous = empty($rows) ? '' : maybe_unserialize($rows[0]);
        if (!is_string($previous)) {
            return new WP_Error('cloze_save_failed', __('The passage could not be read safely. Please retry.', 'll-tools-text-domain'), ['status' => 503]);
        }
        if ($previous !== $parsed['source'] && !hash_equals(hash('sha256', $previous), $previous_hash)) {
            return new WP_Error('cloze_conflict', __('This passage changed in another editor. Reload before editing again.', 'll-tools-text-domain'), ['status' => 409]);
        }
        wp_cache_delete($lesson_id, 'post_meta');
        if ($previous !== $parsed['source']) {
            update_post_meta($lesson_id, LL_TOOLS_CONTENT_LESSON_CLOZE_SOURCE_META, wp_slash($parsed['source']), $previous);
            if (ll_tools_cloze_source($lesson_id) !== $parsed['source']) {
                return new WP_Error('cloze_save_failed', __('The passage could not be saved. Please retry.', 'll-tools-text-domain'), ['status' => 503]);
            }
        }
        delete_post_meta($lesson_id, LL_TOOLS_CONTENT_LESSON_CLOZE_ERROR_META);
        return ['hash' => hash('sha256', $parsed['source']), 'count' => count($parsed['answers'])];
    } finally {
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
    }
}

/** Called only after the existing metabox capability, nonce and wordset checks. */
function ll_tools_cloze_save_metabox(int $lesson_id): void {
    if (!isset($_POST['ll_content_lesson_cloze_source']) || !is_string($_POST['ll_content_lesson_cloze_source'])) { return; }
    $previous_hash = isset($_POST['ll_content_lesson_cloze_hash']) && is_string($_POST['ll_content_lesson_cloze_hash']) ? wp_unslash($_POST['ll_content_lesson_cloze_hash']) : '';
    $result = ll_tools_cloze_persist_source($lesson_id, wp_unslash($_POST['ll_content_lesson_cloze_source']), $previous_hash);
    if (is_wp_error($result)) {
        update_post_meta($lesson_id, LL_TOOLS_CONTENT_LESSON_CLOZE_ERROR_META, $result->get_error_message());
    }
}

function ll_tools_cloze_save_request(int $lesson_id, $source, string $previous_hash) {
    $complete = true;
    $wordset_id = ll_tools_get_content_lesson_wordset_id_authoritative($lesson_id, $complete);
    if (!current_user_can('view_ll_tools') || !current_user_can('edit_post', $lesson_id)
        || get_post_type($lesson_id) !== 'll_content_lesson'
        || ll_tools_get_content_lesson_kind($lesson_id) !== 'cloze' || !$complete || $wordset_id <= 0
        || !ll_tools_user_can_manage_wordset_content($wordset_id, get_current_user_id())) {
        return new WP_Error('cloze_forbidden', __('You are not allowed to edit this activity.', 'll-tools-text-domain'), ['status' => 403]);
    }
    return ll_tools_cloze_persist_source($lesson_id, $source, $previous_hash);
}

function ll_tools_cloze_save_ajax(): void {
    check_ajax_referer('ll_tools_cloze_save', 'nonce');
    $result = ll_tools_cloze_save_request(
        absint($_POST['lesson_id'] ?? 0),
        isset($_POST['source']) && is_string($_POST['source']) ? wp_unslash($_POST['source']) : null,
        isset($_POST['previous_hash']) && is_string($_POST['previous_hash']) ? wp_unslash($_POST['previous_hash']) : ''
    );
    if (is_wp_error($result)) {
        $data = $result->get_error_data();
        wp_send_json_error(['message' => $result->get_error_message()], (int) ($data['status'] ?? 400));
    }
    wp_send_json_success($result);
}
add_action('wp_ajax_ll_tools_cloze_save', 'll_tools_cloze_save_ajax');

function ll_tools_cloze_admin_field(int $lesson_id): void {
    $source = ll_tools_cloze_source($lesson_id);
    $error = (string) get_post_meta($lesson_id, LL_TOOLS_CONTENT_LESSON_CLOZE_ERROR_META, true);
    ?>
    <tr data-ll-cloze-setting <?php echo ll_tools_get_content_lesson_kind($lesson_id) === 'cloze' ? '' : 'hidden'; ?>>
        <th scope="row"><label for="ll-content-lesson-cloze-source"><?php esc_html_e('Passage with blanks', 'll-tools-text-domain'); ?></label></th>
        <td>
            <input type="hidden" name="ll_content_lesson_cloze_hash" value="<?php echo esc_attr(hash('sha256', $source)); ?>" />
            <textarea id="ll-content-lesson-cloze-source" name="ll_content_lesson_cloze_source" rows="10" class="large-text" dir="auto" data-lesson-id="<?php echo esc_attr((string) $lesson_id); ?>" data-source-hash="<?php echo esc_attr(hash('sha256', $source)); ?>" data-saved-kind="<?php echo esc_attr(ll_tools_get_content_lesson_kind($lesson_id)); ?>"><?php echo esc_textarea($source); ?></textarea>
            <p class="description"><?php esc_html_e('Paste plain text and put each missing word inside [[double brackets]]. Example: The [[cat]] sat. Use 1-60 blanks. The activity opens directly on the lesson page; no answer key or vocabulary grid is shown.', 'll-tools-text-domain'); ?></p>
            <p class="description" data-ll-cloze-save-status role="status"><?php echo esc_html($error); ?></p>
            <button type="button" class="button" data-ll-cloze-save-retry hidden><?php esc_html_e('Retry', 'll-tools-text-domain'); ?></button>
            <p class="description"><?php esc_html_e('Passage edits autosave once this lesson is saved as a fill-in-the-blank lesson with a word set. Save other lesson settings with Update.', 'll-tools-text-domain'); ?></p>
        </td>
    </tr>
    <?php
}

function ll_tools_cloze_admin_assets($hook): void {
    global $typenow;
    if (!in_array($hook, ['post.php', 'post-new.php'], true) || $typenow !== 'll_content_lesson') {
        return;
    }
    ll_enqueue_asset_by_timestamp('/js/content-lesson-cloze-admin.js', 'll-tools-cloze-admin', [], true);
    wp_localize_script('ll-tools-cloze-admin', 'llToolsClozeAdmin', [
        'ajaxUrl' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('ll_tools_cloze_save'),
        'i18n' => [
            'saving' => __('Saving...', 'll-tools-text-domain'), 'saved' => __('Saved.', 'll-tools-text-domain'),
            'error' => __('The passage could not be saved. Please retry.', 'll-tools-text-domain'),
            'pending' => __('Save the lesson settings first to enable passage autosave.', 'll-tools-text-domain'),
            'valid' => __('%d blanks ready.', 'll-tools-text-domain'),
            'invalid' => __('Use 1-60 matching [[word]] markers without empty, nested, or multiline answers.', 'll-tools-text-domain'),
        ],
    ]);
}
add_action('admin_enqueue_scripts', 'll_tools_cloze_admin_assets');

function ll_tools_cloze_no_page_cache(): void {
    if (is_singular('ll_content_lesson') && ll_tools_get_content_lesson_kind((int) get_queried_object_id()) === 'cloze') {
        if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE', true); }
        nocache_headers();
    }
}
add_action('template_redirect', 'll_tools_cloze_no_page_cache', 0);
