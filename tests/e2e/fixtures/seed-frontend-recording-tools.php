<?php
/** Disposable real-route fixture. Run only through local WordPress test tooling. */
if (!defined('ABSPATH')) { exit(1); }
const LL_FRONTEND_TOOLS_FIXTURE = 'll_tools_e2e_frontend_recording_tools';

function ll_frontend_tools_state(): array {
    $state = ll_tools_mutation_job_read_option(LL_FRONTEND_TOOLS_FIXTURE);
    if (is_wp_error($state) || ($state !== null && !is_array($state))) { WP_CLI::error('Fixture state could not be read; refusing to guess ownership.'); }
    return $state ?? [];
}
function ll_frontend_tools_save(array $state): void {
    update_option(LL_FRONTEND_TOOLS_FIXTURE, $state, false);
    if (ll_frontend_tools_state() !== $state) { WP_CLI::error('Fixture state could not be saved and verified.'); }
}
function ll_frontend_tools_cleanup(string $run_id, string $request_id = ''): array {
    global $wpdb;
    $state = ll_frontend_tools_state();
    if (!$state) { return ['ok' => true]; }
    if ($run_id === '' || ($state['run_id'] ?? '') !== $run_id) { WP_CLI::error('Fixture belongs to a different run; refusing cleanup.'); }
    $marker = (string) ($state['marker'] ?? '');
    if (!preg_match('/^[a-f0-9]{12}$/D', $marker)) { WP_CLI::error('Fixture marker is invalid; refusing cleanup.'); }
    $recording_ids = array_values(array_unique(array_map('intval', (array) ($state['recordingIds'] ?? []))));
    if (count($recording_ids) > 100) { WP_CLI::error('Fixture recording scope exceeds its cleanup bound.'); }
    sort($recording_ids);
    $leases = [];
    try {
        // A browser timeout does not stop PHP. Hold the same server-side leases
        // before discovering/deleting anything an outstanding request can alter.
        if (!empty($state['wordId'])) {
            $lease = ll_tools_mutation_job_acquire('word_copy', (string) $state['wordId']);
            if (is_wp_error($lease)) { WP_CLI::error('A fixture copy is still running; fixture state retained for cleanup retry.'); }
            $leases[] = $lease;
        }
        foreach ($recording_ids as $id) {
            $lease = ll_tools_mutation_job_acquire('recording_metadata', (string) $id);
            if (is_wp_error($lease)) { WP_CLI::error('A fixture recording save is still running; fixture state retained for cleanup retry.'); }
            $leases[] = $lease;
        }
    $errors = [];
    $receipt_key = (string) ($state['copy_receipt_key'] ?? '');
    if ($receipt_key !== '' && !preg_match('/^ll_word_copy_[a-f0-9]{64}$/D', $receipt_key)) { WP_CLI::error('Fixture copy receipt key is invalid; refusing cleanup.'); }
    $copy_ids = [];
    if ($receipt_key !== '' || ($request_id !== '' && preg_match('/^[a-f0-9-]{36}$/D', $request_id))) {
        if ($receipt_key === '') {
            wp_set_current_user((int) ($state['manager']['id'] ?? 0));
            if (get_current_user_id() !== (int) ($state['manager']['id'] ?? 0)) { WP_CLI::error('Fixture manager identity is unavailable; receipt ownership needs review.'); }
            $receipt_key = ll_tools_word_copy_receipt_key((int) ($state['wordsetId'] ?? 0), (int) ($state['wordId'] ?? 0), $request_id);
            $state['copy_receipt_key'] = $receipt_key;
            ll_frontend_tools_save($state);
        }
        $key = $receipt_key;
        $receipt = ll_tools_mutation_job_read_option($key);
        if (is_wp_error($receipt)) { $errors[] = 'Copy receipt could not be read.'; }
        if (is_array($receipt) && (int) ($receipt['source_id'] ?? 0) === (int) ($state['wordId'] ?? 0)
            && (int) ($receipt['wordset_id'] ?? 0) === (int) ($state['wordsetId'] ?? 0) && ($receipt['key'] ?? '') === $key) {
            if (!empty($receipt['new_word_id'])) { $copy_ids[] = (int) $receipt['new_word_id']; }
            $discovered = $wpdb->get_col($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_ll_word_copy_receipt' AND meta_value = %s LIMIT 101", $key));
            if ($wpdb->last_error !== '' || count($discovered) > 100) { $errors[] = 'Copy discovery was incomplete.'; }
            else { $copy_ids = array_merge($copy_ids, array_map('intval', $discovered)); }
            $receipt_key = $key;
        } elseif ($receipt !== null && !is_wp_error($receipt)) {
            $errors[] = 'Copy receipt does not match this fixture.';
        }
        wp_set_current_user(0);
    }
    foreach ((array) ($state['recordingIds'] ?? []) as $id) {
        if (get_post_meta((int) $id, LL_FRONTEND_TOOLS_FIXTURE, true) === $marker) { ll_tools_ipa_keyboard_clear_scheduled_recording_validation((int) $id); }
    }
    $wpdb->last_error = '';
    $posts = get_posts(['post_type' => ['words', 'word_audio', 'page'], 'post_status' => ['publish','draft','pending','private','future','trash'],
        'fields' => 'ids', 'posts_per_page' => 101, 'no_found_rows' => true, 'suppress_filters' => true,
        'meta_key' => LL_FRONTEND_TOOLS_FIXTURE, 'meta_value' => $marker]);
    if ($wpdb->last_error !== '' || count($posts) > 100) { WP_CLI::error('Fixture post discovery was incomplete; state retained for cleanup.'); }
    foreach (array_unique(array_merge($posts, $copy_ids)) as $id) {
        $fixture_marker = get_post_meta($id, LL_FRONTEND_TOOLS_FIXTURE, true);
        $is_copy = in_array((int) $id, $copy_ids, true);
        if ($is_copy) {
            $post = get_post($id);
            if (!$post) { continue; }
            if ($post->post_type !== 'words' || ($fixture_marker !== $marker && get_post_meta($id, '_ll_word_copy_receipt', true) !== $receipt_key)) {
                $errors[] = 'Copy ' . (int) $id . ' no longer matches the fixture identity.';
                continue;
            }
        }
        if ($is_copy || $fixture_marker === $marker) {
            wp_delete_post((int) $id, true);
            $remaining = $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE ID = %d", $id));
            if ($wpdb->last_error !== '' || $remaining !== null) { $errors[] = 'Post ' . (int) $id . ' could not be removed.'; }
        }
    }
    foreach (array_filter(array_merge([(int) ($state['wordId'] ?? 0), (int) ($state['pageId'] ?? 0)], (array) ($state['recordingIds'] ?? []))) as $id) {
        $remaining = $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE ID = %d", $id));
        if ($wpdb->last_error !== '' || $remaining !== null) { $errors[] = 'Tracked fixture post ' . (int) $id . ' remains and needs review.'; }
    }
    foreach ((array) ($state['terms'] ?? []) as $term) {
        $remaining = $wpdb->get_var($wpdb->prepare("SELECT term_id FROM {$wpdb->term_taxonomy} WHERE term_id = %d AND taxonomy = %s", $term['id'], $term['taxonomy']));
        if ($wpdb->last_error !== '') { $errors[] = 'Term ownership could not be read.'; continue; }
        if ($remaining === null) { continue; }
        if (get_term_meta((int) $term['id'], LL_FRONTEND_TOOLS_FIXTURE, true) !== $marker) { $errors[] = 'Term ' . (int) $term['id'] . ' no longer has the fixture marker.'; continue; }
        wp_delete_term((int) $term['id'], (string) $term['taxonomy']);
        $remaining = $wpdb->get_var($wpdb->prepare("SELECT term_id FROM {$wpdb->term_taxonomy} WHERE term_id = %d AND taxonomy = %s", $term['id'], $term['taxonomy']));
        if ($wpdb->last_error !== '' || $remaining !== null) { $errors[] = 'Term ' . (int) $term['id'] . ' could not be removed.'; }
    }
    if (!function_exists('wp_delete_user')) { require_once ABSPATH . 'wp-admin/includes/user.php'; }
    foreach ((array) ($state['users'] ?? []) as $id) {
        $remaining = $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->users} WHERE ID = %d", $id));
        if ($wpdb->last_error !== '') { $errors[] = 'User ownership could not be read.'; continue; }
        if ($remaining === null) { continue; }
        if (get_user_meta((int) $id, LL_FRONTEND_TOOLS_FIXTURE, true) !== $marker) { $errors[] = 'User ' . (int) $id . ' no longer has the fixture marker.'; continue; }
        // wp_delete_user otherwise deletes every authored post, including an
        // unexpected unmarked object. Only remove an already-empty fixture user.
        $authored = $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_author = %d LIMIT 1", $id));
        if ($wpdb->last_error !== '' || $authored !== null) { $errors[] = 'User ' . (int) $id . ' still owns posts; retained for review.'; continue; }
        wp_delete_user((int) $id);
        $remaining = $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->users} WHERE ID = %d", $id));
        if ($wpdb->last_error !== '' || $remaining !== null) { $errors[] = 'User ' . (int) $id . ' could not be removed.'; }
    }
    $file = (string) ($state['mediaPath'] ?? '');
    $uploads = wp_upload_dir();
    if ($file !== '' && is_file($file)) {
        if (realpath(dirname($file)) !== realpath($uploads['basedir']) || basename($file) !== 'll-e2e-recording-tools-' . $marker . '.wav') { $errors[] = 'Fixture media path failed its scope guard.'; }
        elseif (!unlink($file) || is_file($file)) { $errors[] = 'Fixture media could not be removed.'; }
    }
    if ($errors) { WP_CLI::error(implode(' ', $errors) . ' Fixture state retained for cleanup retry.'); }
    if ($receipt_key !== '') {
        delete_option($receipt_key);
        if (ll_tools_mutation_job_read_option($receipt_key) !== null) { WP_CLI::error('Copy receipt cleanup could not be verified; fixture state retained.'); }
    }
    delete_option(LL_FRONTEND_TOOLS_FIXTURE);
    if (ll_frontend_tools_state()) { WP_CLI::error('Fixture state cleanup could not be verified.'); }
    return ['ok' => true];
    } finally {
        foreach (array_reverse($leases) as $lease) { ll_tools_mutation_job_release($lease); }
    }
}

function ll_frontend_tools_seed(string $run_id): array {
    if (!preg_match('/^[a-f0-9-]{36}$/D', $run_id)) { WP_CLI::error('A valid fixture run ID is required.'); }
    if (ll_frontend_tools_state()) { WP_CLI::error('An earlier frontend-tools fixture needs cleanup first.'); }
    wp_set_current_user(0);
    $marker = substr(str_replace('-', '', wp_generate_uuid4()), 0, 12);
    $state = ['run_id' => $run_id, 'marker' => $marker, 'terms' => [], 'users' => []];
    ll_frontend_tools_save($state);
    foreach (['manager' => 'wordset_manager', 'recorder' => 'audio_recorder'] as $kind => $role) {
        $login = 'll-front-' . $kind . '-' . $marker;
        $password = wp_generate_password(28, false, false);
        $id = wp_insert_user(['user_login' => $login, 'user_pass' => $password, 'role' => $role,
            'user_email' => $login . '@example.invalid', 'display_name' => 'Fixture ' . $kind]);
        if (is_wp_error($id)) { WP_CLI::error($id->get_error_message()); }
        update_user_meta($id, LL_FRONTEND_TOOLS_FIXTURE, $marker);
        update_user_meta($id, 'locale', 'en_US');
        get_userdata($id)->add_cap('view_ll_tools');
        $state[$kind] = ['id' => $id, 'login' => $login, 'password' => $password];
        $state['users'][] = $id;
        ll_frontend_tools_save($state);
    }
    foreach (['wordset' => 'Tools wordset', 'word-category' => 'Tools category'] as $taxonomy => $name) {
        $term = wp_insert_term($name . ' ' . $marker, $taxonomy, ['slug' => 'll-front-' . $taxonomy . '-' . $marker]);
        if (is_wp_error($term)) { WP_CLI::error($term->get_error_message()); }
        $id = (int) $term['term_id'];
        update_term_meta($id, LL_FRONTEND_TOOLS_FIXTURE, $marker);
        $state['terms'][] = ['id' => $id, 'taxonomy' => $taxonomy];
        $state[$taxonomy === 'wordset' ? 'wordsetId' : 'categoryId'] = $id;
        ll_frontend_tools_save($state);
    }
    $wordset = get_term($state['wordsetId'], 'wordset');
    $category = get_term($state['categoryId'], 'word-category');
    ll_tools_set_wordset_manager_user_ids($state['wordsetId'], [$state['manager']['id']], $state['manager']['id']);
    update_term_meta($state['categoryId'], LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY, $state['wordsetId']);
    update_term_meta($state['categoryId'], 'll_quiz_prompt_type', 'audio');
    update_term_meta($state['categoryId'], 'll_quiz_option_type', 'text_translation');
    update_term_meta($state['wordsetId'], 'll_language', 'English');
    ll_set_user_recording_config($state['recorder']['id'], ['wordset' => $wordset->slug, 'category' => $category->slug,
        'allow_new_words' => '0', 'auto_process_recordings' => '0']);
    $state['wordTitle'] = 'Frontend review word ' . $marker;
    $state['wordId'] = wp_insert_post(['post_type' => 'words', 'post_status' => 'draft', 'post_title' => $state['wordTitle'],
        'post_author' => $state['recorder']['id'], 'meta_input' => [LL_FRONTEND_TOOLS_FIXTURE => $marker, 'word_translation' => 'Fixture meaning']], true);
    if (is_wp_error($state['wordId'])) { WP_CLI::error($state['wordId']->get_error_message()); }
    wp_set_object_terms($state['wordId'], [$state['wordsetId']], 'wordset');
    wp_set_object_terms($state['wordId'], [$state['categoryId']], 'word-category');
    ll_frontend_tools_save($state);
    $uploads = wp_upload_dir();
    if ($uploads['error']) { WP_CLI::error($uploads['error']); }
    $state['mediaPath'] = trailingslashit($uploads['basedir']) . 'll-e2e-recording-tools-' . $marker . '.wav';
    ll_frontend_tools_save($state);
    $pcm = str_repeat("\0", 1600);
    $wav = 'RIFF' . pack('V', 36 + strlen($pcm)) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16) . 'data' . pack('V', strlen($pcm)) . $pcm;
    if (file_put_contents($state['mediaPath'], $wav) !== strlen($wav)) { WP_CLI::error('Could not write fixture media.'); }
    $audio_url = trailingslashit($uploads['baseurl']) . basename($state['mediaPath']);
    $state['recordingIds'] = [];
    foreach ([1, 2] as $number) {
        $id = wp_insert_post(['post_type' => 'word_audio', 'post_status' => 'publish', 'post_parent' => $state['wordId'],
            'post_author' => $state['recorder']['id'], 'post_title' => 'Fixture recording ' . $number,
            'meta_input' => [LL_FRONTEND_TOOLS_FIXTURE => $marker, 'audio_file_path' => $audio_url,
                'recording_text' => 'Original recording ' . $number, 'recording_ipa' => 'aba', 'speaker_user_id' => $state['recorder']['id']]], true);
        if (is_wp_error($id)) { WP_CLI::error($id->get_error_message()); }
        $state['recordingIds'][] = $id;
        ll_frontend_tools_save($state);
    }
    wp_update_post(['ID' => $state['wordId'], 'post_status' => 'publish']);
    ll_tools_ipa_keyboard_mark_recording_needs_auto_review($state['recordingIds'][0], 'recording_ipa', 'Fixture review note');
    $state['pageId'] = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Recorder fixture ' . $marker,
        'post_content' => '[audio_recording_interface wordset="' . $wordset->slug . '" category="' . $category->slug . '"]',
        'meta_input' => [LL_FRONTEND_TOOLS_FIXTURE => $marker]], true);
    if (is_wp_error($state['pageId'])) { WP_CLI::error($state['pageId']->get_error_message()); }
    $base = ['ll_wordset_page' => $wordset->slug, 'll_wordset_view' => 'settings'];
    $state['reviewPath'] = wp_make_link_relative(add_query_arg($base + ['ll_wordset_tool' => 'transcription-review'], home_url('/')));
    $state['editorPath'] = wp_make_link_relative(add_query_arg($base + ['ll_wordset_tool' => 'editor'], home_url('/')));
    $state['recorderPath'] = wp_make_link_relative(get_permalink($state['pageId']));
    $state['copyTitle'] = 'Copied frontend word ' . $marker;
    ll_frontend_tools_save($state);
    return $state;
}

$arguments = isset($args) && is_array($args) ? $args : [];
$command = (string) ($arguments[0] ?? 'seed');
if (in_array($command, ['seed', 'cleanup'], true)) {
    $lease = ll_tools_mutation_job_acquire('frontend_tools_fixture', 'state');
    if (is_wp_error($lease)) { WP_CLI::error('Another fixture command is running; refusing overlapping setup or cleanup.'); }
    try {
        $result = $command === 'seed'
            ? ll_frontend_tools_seed((string) ($arguments[1] ?? ''))
            : ll_frontend_tools_cleanup((string) ($arguments[1] ?? ''), (string) ($arguments[2] ?? ''));
    } finally { ll_tools_mutation_job_release($lease); }
}
elseif ($command === 'inspect') {
    $state = ll_frontend_tools_state();
    $copies = get_posts(['post_type' => 'words', 'post_status' => ['publish','draft','pending','private'], 'numberposts' => 10,
        'meta_key' => LL_FRONTEND_TOOLS_FIXTURE, 'meta_value' => (string) ($state['marker'] ?? '')]);
    $result = ['recording_text' => get_post_meta((int) $state['recordingIds'][0], 'recording_text', true),
        'recording_ipa' => get_post_meta((int) $state['recordingIds'][0], 'recording_ipa', true),
        'review_note' => ll_tools_ipa_keyboard_get_recording_review_note((int) $state['recordingIds'][0]),
        'copies' => array_values(array_map(static fn($post) => ['id' => (int) $post->ID, 'title' => $post->post_title, 'status' => $post->post_status],
            array_filter($copies, static fn($post) => $post->ID !== (int) $state['wordId']))),
        'parents' => array_map('wp_get_post_parent_id', $state['recordingIds'])];
} else { WP_CLI::error('Unknown fixture command.'); }
echo wp_json_encode($result);
