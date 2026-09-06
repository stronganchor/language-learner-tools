<?php
if (!defined('WPINC')) { die; }
require_once __DIR__ . '/mutation-job-state.php';

/** Copy content metadata, never recording state, caches, or stable identities. */
function ll_tools_word_copy_meta_allowed(string $key): bool {
    return !in_array($key, ['word_audio_file', '_edit_lock', '_edit_last', '_ll_skip_audio_requirement_once', '_ll_picked_count', '_ll_picked_last', '_ll_autopicked_image_id', '_thumbnail_id'], true)
        && (!is_protected_meta($key, 'post') || $key === '_ll_similar_word_id');
}

function ll_tools_word_copy_error(string $message = '', int $status = 409): WP_Error {
    return new WP_Error('ll_word_copy_failed', $message ?: __('The word could not be copied. Please try again.', 'll-tools-text-domain'), ['status' => $status]);
}

/** Fresh object privacy checks; scoped managers may work on staff-created drafts. */
function ll_tools_word_copy_can_read_post(WP_Post $post): bool {
    return !post_password_required($post)
        && (!in_array($post->post_status, ['publish', 'private'], true) || current_user_can('read_post', $post->ID));
}

/** Raw terms must be checked before frontend filters can hide a private category. */
function ll_tools_word_copy_categories(int $post_id, int $wordset_id) {
    global $wpdb;
    $wpdb->last_error = '';
    $terms = wp_get_object_terms($post_id, 'word-category', ['suppress_filter' => true]);
    if (is_wp_error($terms) || $wpdb->last_error !== '') { return ll_tools_word_copy_error('', 503); }
    $visible = [];
    foreach ($terms as $term) {
        $complete = true;
        $allowed = ll_tools_user_can_view_category($term, get_current_user_id(), $complete);
        if (!$complete) { return ll_tools_word_copy_error('', 503); }
        $owner = ll_tools_get_category_wordset_owner_id((int) $term->term_id, $complete);
        if (!$complete) { return ll_tools_word_copy_error('', 503); }
        if ($allowed && (!$owner || $owner === $wordset_id)) { $visible[] = (int) $term->term_id; }
    }
    if ($terms && !$visible) { return ll_tools_word_copy_error(__('You do not have permission to copy this word.', 'll-tools-text-domain'), 403); }
    return $visible;
}

function ll_tools_word_copy_source(int $wordset_id, int $word_id) {
    global $wpdb;
    if (!is_user_logged_in() || !current_user_can('view_ll_tools') || !ll_tools_current_user_can_manage_wordset_content($wordset_id)) {
        return ll_tools_word_copy_error(__('You do not have permission to copy this word.', 'll-tools-text-domain'), 403);
    }
    clean_post_cache($word_id);
    $word = get_post($word_id);
    $wpdb->last_error = '';
    $sets = wp_get_object_terms($word_id, 'wordset', ['fields' => 'ids', 'suppress_filter' => true]);
    if (is_wp_error($sets) || $wpdb->last_error !== '') { return ll_tools_word_copy_error('', 503); }
    if (!$word instanceof WP_Post || $word->post_type !== 'words' || !in_array($word->post_status, ['publish', 'draft', 'pending', 'private'], true)
        || is_wp_error($sets) || !in_array($wordset_id, array_map('intval', $sets), true)) {
        return ll_tools_word_copy_error(__('The word is no longer available in this word set.', 'll-tools-text-domain'), 404);
    }
    if (!ll_tools_word_copy_can_read_post($word)) { return ll_tools_word_copy_error(__('You do not have permission to copy this word.', 'll-tools-text-domain'), 403); }
    $categories = ll_tools_word_copy_categories($word_id, $wordset_id);
    if (is_wp_error($categories)) { return $categories; }
    return $word;
}

/** Check the image object and attachment before resolving any URL or copying its ID. */
function ll_tools_word_copy_image(int $word_id, int $wordset_id, bool $resolve_url = false) {
    global $wpdb;
    $wpdb->last_error = '';
    $image_id = (int) ll_tools_get_canonical_word_image_post_id_for_word($word_id, true);
    if ($wpdb->last_error !== '') { return ll_tools_word_copy_error('', 503); }
    if ($image_id) {
        $image = get_post($image_id);
        if (!$image instanceof WP_Post || !in_array($image->post_status, ['publish','draft','pending','private','future'], true)
            || !ll_tools_word_copy_can_read_post($image)) { return ll_tools_word_copy_error('', 403); }
        $categories = ll_tools_word_copy_categories($image_id, $wordset_id);
        if (is_wp_error($categories)) { return $categories; }
        $wpdb->last_error = '';
        $owner = ll_tools_get_word_image_wordset_owner_id($image_id);
        if ($wpdb->last_error !== '') { return ll_tools_word_copy_error('', 503); }
        $sets = wp_get_object_terms($image_id, 'wordset', ['fields' => 'ids', 'suppress_filter' => true]);
        if (is_wp_error($sets) || $wpdb->last_error !== '') { return ll_tools_word_copy_error('', 503); }
        if (($owner && $owner !== $wordset_id) || ($sets && !in_array($wordset_id, array_map('intval', $sets), true))) { return ll_tools_word_copy_error('', 403); }
    }
    $wpdb->last_error = '';
    $attachment_id = $image_id ? (int) get_post_thumbnail_id($image_id) : 0;
    if ($wpdb->last_error !== '') { return ll_tools_word_copy_error('', 503); }
    if (!$attachment_id) { $attachment_id = (int) get_post_thumbnail_id($word_id); }
    if ($wpdb->last_error !== '') { return ll_tools_word_copy_error('', 503); }
    if (!$attachment_id) { return []; }
    $attachment = get_post($attachment_id);
    if (!$attachment instanceof WP_Post || $attachment->post_type !== 'attachment'
        || !in_array($attachment->post_status, ['inherit','publish','draft','pending','private'], true)
        || !ll_tools_word_copy_can_read_post($attachment)) { return ll_tools_word_copy_error('', 403); }
    if ($attachment->post_parent && !in_array((int) $attachment->post_parent, [$word_id, $image_id], true)) {
        $parent = get_post($attachment->post_parent);
        if (!$parent instanceof WP_Post || !ll_tools_word_copy_can_read_post($parent)) { return ll_tools_word_copy_error('', 403); }
        if ($parent->post_type === 'words') {
            $source = ll_tools_word_copy_source($wordset_id, $parent->ID);
            if (is_wp_error($source)) { return $source; }
        } elseif (!current_user_can('read_post', $parent->ID)) { return ll_tools_word_copy_error('', 403); }
        if (in_array($parent->post_type, ['words', 'word_images'], true)) {
            $categories = ll_tools_word_copy_categories($parent->ID, $wordset_id);
            if (is_wp_error($categories)) { return $categories; }
            if (!ll_tools_user_can_view_vocab_post($parent)) { return ll_tools_word_copy_error('', 403); }
        }
    }
    if (!$resolve_url) { return ['attachment_id' => $attachment_id, 'word_image_id' => $image_id]; }
    $size = wp_get_attachment_image_src($attachment_id, 'thumbnail');
    if ($wpdb->last_error !== '') { return ll_tools_word_copy_error('', 503); }
    $url = (string) (wp_get_attachment_image_url($attachment_id, 'thumbnail') ?: '');
    if ($wpdb->last_error !== '') { return ll_tools_word_copy_error('', 503); }
    $alt = (string) get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
    if ($wpdb->last_error !== '') { return ll_tools_word_copy_error('', 503); }
    return ['attachment_id' => $attachment_id, 'word_image_id' => $image_id,
        'url' => $url, 'alt' => $alt,
        'width' => is_array($size) ? (int) $size[1] : 0, 'height' => is_array($size) ? (int) $size[2] : 0];
}

/** One fixed-size page; an interactive copy never hydrates all recordings. */
function ll_tools_word_copy_preview(int $wordset_id, int $word_id, int $after_id = 0) {
    global $wpdb;
    $word = ll_tools_word_copy_source($wordset_id, $word_id);
    if (is_wp_error($word)) { return $word; }
    $image = ll_tools_word_copy_image($word_id, $wordset_id, true);
    if (is_wp_error($image)) { return $image; }
    $ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts}
        WHERE post_type = 'word_audio' AND post_parent = %d AND ID > %d
        AND post_status IN ('publish','draft','pending','private') ORDER BY ID ASC LIMIT 21", $word_id, max(0, $after_id)));
    if ($wpdb->last_error !== '') { return ll_tools_word_copy_error('', 503); }
    $more = count($ids) > 20;
    $ids = array_slice(array_map('intval', $ids), 0, 20);
    $rows = [];
    foreach ($ids as $id) {
        $wpdb->last_error = '';
        $audio = get_post($id);
        if ($wpdb->last_error !== '') { return ll_tools_word_copy_error('', 503); }
        if (!$audio instanceof WP_Post || (int) $audio->post_parent !== $word_id
            || !in_array($audio->post_status, ['publish','draft','pending','private'], true) || !ll_tools_word_copy_can_read_post($audio)) { continue; }
        $path = (string) get_post_meta($id, 'audio_file_path', true);
        if ($wpdb->last_error !== '') { return ll_tools_word_copy_error('', 503); }
        $rows[] = [
            'id' => $id, 'title' => (string) $audio->post_title,
            'status' => ll_tools_wordset_editor_status_label((string) $audio->post_status),
            'url' => ll_tools_wordset_editor_audio_url_from_path($path),
        ];
    }
    return [
        'title' => (string) $word->post_title, 'recordings' => $rows,
        'has_more' => $more, 'after_id' => $ids ? max($ids) : $after_id,
        'image' => $image,
    ];
}

function ll_tools_word_copy_receipt_key(int $wordset_id, int $word_id, string $request_id): string {
    return 'll_word_copy_' . hash('sha256', get_current_user_id() . '|' . $wordset_id . '|' . $word_id . '|' . $request_id);
}

function ll_tools_word_copy_result(array $receipt) {
    global $wpdb;
    $new_id = (int) ($receipt['new_word_id'] ?? 0);
    if (!$new_id && !empty($receipt['key'])) {
        $new_id = (int) $wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_ll_word_copy_receipt' AND meta_value = %s ORDER BY meta_id ASC LIMIT 1", $receipt['key']));
        if ($wpdb->last_error !== '') { return ll_tools_word_copy_error('', 503); }
    }
    $source_id = (int) ($receipt['source_id'] ?? 0);
    $scope = ll_tools_word_copy_source((int) $receipt['wordset_id'], $source_id);
    if (is_wp_error($scope)) { return $scope; }
    $unscoped_pending = false;
    if ($new_id) {
        $scope = ll_tools_word_copy_source((int) $receipt['wordset_id'], $new_id);
        if (is_wp_error($scope)) {
            // Creation can commit before its acknowledgement or first taxonomy
            // write. Reveal only the retained ID for our exact unscoped draft;
            // a copy reassigned to another scope never qualifies for this path.
            $wpdb->last_error = '';
            $sets = wp_get_object_terms($new_id, 'wordset', ['fields' => 'ids', 'suppress_filter' => true]);
            if (is_wp_error($sets) || $wpdb->last_error !== '') { return ll_tools_word_copy_error('', 503); }
            $copy = get_post($new_id);
            $marker = get_post_meta($new_id, '_ll_word_copy_receipt', true);
            if ($wpdb->last_error !== '') { return ll_tools_word_copy_error('', 503); }
            // MyISAM metadata is not rolled back with post creation. The
            // separately persisted receipt also proves ownership when its
            // post-meta discovery marker failed to write.
            $stored = ll_tools_mutation_job_read_option((string) ($receipt['key'] ?? ''));
            if (is_wp_error($stored)) { return $stored; }
            $owns_copy = !empty($receipt['key']) && ($marker === $receipt['key']
                || (is_array($stored) && ($stored['key'] ?? '') === $receipt['key'] && (int) ($stored['new_word_id'] ?? 0) === $new_id));
            $unscoped_pending = $wpdb->last_error === '' && !is_wp_error($sets) && !$sets
                && ($receipt['state'] ?? '') === 'pending' && $owns_copy
                && $copy instanceof WP_Post && $copy->post_type === 'words' && $copy->post_status === 'draft'
                && ll_tools_word_copy_can_read_post($copy);
            if (!$unscoped_pending) { return $scope; }
        }
    }
    $visible_new_id = $unscoped_pending ? 0 : $new_id;
    $image = $visible_new_id ? ll_tools_word_copy_image($visible_new_id, (int) $receipt['wordset_id'], true) : [];
    if (is_wp_error($image)) { return $image; }
    $source_audio_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='word_audio' AND post_parent=%d AND post_status='publish'", $source_id));
    if ($wpdb->last_error !== '') { return ll_tools_word_copy_error('', 503); }
    // Always describe actual parent assignments, including a move whose
    // receipt acknowledgement was lost after the SQL mutation committed.
    $moved_ids = [];
    foreach ((array) ($receipt['intent']['move_ids'] ?? []) as $id) {
        clean_post_cache((int) $id);
        $wpdb->last_error = '';
        $parent_id = (int) wp_get_post_parent_id((int) $id);
        if ($wpdb->last_error !== '') { return ll_tools_word_copy_error('', 503); }
        if ($new_id && $parent_id === $new_id) { $moved_ids[] = (int) $id; }
    }
    $term = get_term((int) $receipt['wordset_id'], 'wordset');
    $url = $term instanceof WP_Term && $visible_new_id ? add_query_arg('ll_editor_q', (string) get_the_title($visible_new_id), ll_tools_get_wordset_settings_tool_url($term, 'editor')) . '#ll-wordset-editor-word-' . $visible_new_id : '';
    return [
        'state' => (string) $receipt['state'], 'new_word_id' => $visible_new_id,
        'retained_word_id' => $unscoped_pending ? $new_id : 0,
        'title' => $visible_new_id ? (string) get_the_title($visible_new_id) : '', 'url' => $url,
        'status' => $visible_new_id ? (string) get_post_status($visible_new_id) : '',
        'status_label' => $visible_new_id ? ll_tools_wordset_editor_status_label((string) get_post_status($visible_new_id)) : '',
        'source_status' => (string) get_post_status($source_id),
        'source_status_label' => ll_tools_wordset_editor_status_label((string) get_post_status($source_id)),
        'source_audio_count' => $source_audio_count,
        'source_audio_label' => sprintf(_n('%d published recording', '%d published recordings', $source_audio_count, 'll-tools-text-domain'), $source_audio_count),
        'moved_ids' => $moved_ids,
        'image' => $image,
        'message' => $unscoped_pending
            /* translators: %d: ID of a retained draft word that needs administrative recovery. */
            ? sprintf(__('Draft word #%d was retained before word-set setup finished. Ask an administrator to review it; do not create another copy.', 'll-tools-text-domain'), $new_id)
            : ($receipt['state'] === 'completed'
            ? __('Word copied.', 'll-tools-text-domain')
            : __('This copy needs review before continuing. Check the saved word and recordings; do not submit another copy.', 'll-tools-text-domain')),
    ];
}

/** A durable receipt makes retry/readback safe after a lost browser response. */
function ll_tools_word_copy_apply(int $wordset_id, int $word_id, string $request_id, string $title, array $move_ids = []) {
    global $wpdb;
    if (!preg_match('/^[a-f0-9-]{36}$/D', $request_id) || count($move_ids) > 50 || strlen($title) > 1000) {
        return ll_tools_word_copy_error(__('Choose at most 50 recordings for one copy.', 'll-tools-text-domain'), 400);
    }
    foreach ($move_ids as $id) {
        if ((!is_int($id) && (!is_string($id) || !ctype_digit($id))) || (int) $id <= 0) { return ll_tools_word_copy_error('', 400); }
    }
    $move_ids = array_values(array_unique(array_map('intval', $move_ids)));
    sort($move_ids);
    $title = trim(sanitize_text_field($title));
    $source = ll_tools_word_copy_source($wordset_id, $word_id);
    if (is_wp_error($source)) { return $source; }
    $key = ll_tools_word_copy_receipt_key($wordset_id, $word_id, $request_id);
    $lease = ll_tools_mutation_job_acquire('word_copy', (string) $word_id);
    if (is_wp_error($lease)) { return $lease; }
    try {
        $intent = ['title' => $title, 'move_ids' => $move_ids];
        $receipt = ll_tools_mutation_job_read_option($key);
        if (is_wp_error($receipt)) { return $receipt; }
        if (is_array($receipt)) {
            if (($receipt['intent'] ?? null) !== $intent) { return ll_tools_word_copy_error(__('This request already belongs to a different copy.', 'll-tools-text-domain')); }
            return ll_tools_word_copy_result($receipt);
        }
        $source = ll_tools_word_copy_source($wordset_id, $word_id);
        if (is_wp_error($source)) { return $source; }
        $wpdb->last_error = '';
        $source_sets = wp_get_object_terms($word_id, 'wordset', ['fields' => 'ids', 'suppress_filter' => true]);
        if (is_wp_error($source_sets) || $wpdb->last_error !== '') { return ll_tools_word_copy_error('', 503); }
        if ($move_ids) {
            foreach ($source_sets as $set_id) {
                if (!ll_tools_current_user_can_manage_wordset_content((int) $set_id)) {
                    return ll_tools_word_copy_error(__('You must manage every word set using this word to move its recordings.', 'll-tools-text-domain'), 403);
                }
            }
        }
        foreach ($move_ids as $id) {
            clean_post_cache($id);
            $audio = get_post($id);
            if (!$audio instanceof WP_Post || $audio->post_type !== 'word_audio' || (int) $audio->post_parent !== $word_id
                || !in_array($audio->post_status, ['publish','draft','pending','private'], true) || !ll_tools_word_copy_can_read_post($audio)) {
                return ll_tools_word_copy_error(__('A selected recording changed or is no longer available. Reopen the copy dialog.', 'll-tools-text-domain'));
            }
        }
        // Capture all bounded one-word source data before inserting anything.
        $taxonomies = [];
        foreach (get_object_taxonomies('words', 'names') as $taxonomy) {
            $wpdb->last_error = '';
            $ids = wp_get_object_terms($word_id, $taxonomy, ['fields' => 'ids', 'suppress_filter' => true]);
            if (is_wp_error($ids) || $wpdb->last_error !== '') { return ll_tools_word_copy_error('', 503); }
            $ids = array_map('intval', $ids);
            if ($taxonomy === 'wordset') { $ids = [$wordset_id]; }
            if ($taxonomy === 'word-category') {
                $ids = ll_tools_word_copy_categories($word_id, $wordset_id);
                if (is_wp_error($ids)) { return $ids; }
            }
            sort($ids);
            $taxonomies[$taxonomy] = $ids;
        }
        $raw_meta = $wpdb->get_results($wpdb->prepare("SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_id ASC LIMIT 1001", $word_id), ARRAY_A);
        if ($wpdb->last_error !== '' || !is_array($raw_meta) || count($raw_meta) > 1000) { return ll_tools_word_copy_error('', 503); }
        $meta = []; $meta_bytes = 0;
        foreach ($raw_meta as $item) {
            $meta_bytes += strlen($item['meta_value']);
            if ($meta_bytes > 2 * MB_IN_BYTES) { return ll_tools_word_copy_error('', 400); }
            $meta[$item['meta_key']][] = $item['meta_value'];
        }
        $image = ll_tools_word_copy_image($word_id, $wordset_id);
        if (is_wp_error($image)) { return $image; }
        $image_id = (int) ($image['attachment_id'] ?? 0);
        $receipt = ['key' => $key, 'state' => 'pending', 'source_id' => $word_id, 'wordset_id' => $wordset_id, 'intent' => $intent, 'new_word_id' => 0, 'moved_ids' => [], 'created_at' => time()];
        $saved = ll_tools_mutation_job_write_option($key, $receipt, $lease);
        if (is_wp_error($saved)) { return $saved; }
        $new_id = wp_insert_post(wp_slash([
            'post_type' => 'words', 'post_status' => 'draft', 'post_title' => $title !== '' ? $title : (string) $source->post_title,
            'post_content' => (string) $source->post_content, 'post_excerpt' => (string) $source->post_excerpt,
            'post_password' => (string) $source->post_password,
            'post_author' => (int) $source->post_author, 'comment_status' => (string) $source->comment_status,
            'ping_status' => (string) $source->ping_status, 'menu_order' => (int) $source->menu_order,
            'meta_input' => ['_ll_word_copy_receipt' => $key],
        ]), true);
        if (is_wp_error($new_id) || !$new_id) { return ll_tools_word_copy_result($receipt); }
        $receipt['new_word_id'] = (int) $new_id;
        if (is_wp_error(ll_tools_mutation_job_write_option($key, $receipt, $lease))) { return ll_tools_word_copy_result($receipt); }
        wp_cache_delete($new_id, 'post_meta');
        if (get_post_meta($new_id, '_ll_word_copy_receipt', true) !== $key || $wpdb->last_error !== '') { return ll_tools_word_copy_result($receipt); }
        // Assign scope first so a retained partial copy remains accessible to its manager.
        $taxonomies = ['wordset' => [$wordset_id]] + $taxonomies;
        foreach ($taxonomies as $taxonomy => $ids) {
            $wpdb->last_error = '';
            $assigned = wp_set_object_terms($new_id, $ids, $taxonomy, false);
            if (is_wp_error($assigned) || $wpdb->last_error !== '') { return ll_tools_word_copy_result($receipt); }
            wp_cache_delete($new_id, $taxonomy . '_relationships');
            $actual = wp_get_object_terms($new_id, $taxonomy, ['fields' => 'ids', 'suppress_filter' => true]);
            if (is_wp_error($actual) || $wpdb->last_error !== '') { return ll_tools_word_copy_result($receipt); }
            $actual = array_map('intval', $actual); sort($actual);
            if ($actual !== $ids) { return ll_tools_word_copy_result($receipt); }
        }
        foreach ($meta as $meta_key => $values) {
            if (!ll_tools_word_copy_meta_allowed($meta_key)) { continue; }
            if (metadata_exists('post', $new_id, $meta_key)) { delete_post_meta($new_id, $meta_key); }
            foreach ($values as $value) {
                if (!add_post_meta($new_id, $meta_key, wp_slash(maybe_unserialize($value)))) { return ll_tools_word_copy_result($receipt); }
            }
            wp_cache_delete($new_id, 'post_meta');
            if (get_post_meta($new_id, $meta_key, false) !== array_map('maybe_unserialize', $values)) { return ll_tools_word_copy_result($receipt); }
        }
        if ($image_id > 0) {
            set_post_thumbnail($new_id, $image_id);
            wp_cache_delete($new_id, 'post_meta');
            if ((int) get_post_thumbnail_id($new_id) !== $image_id) { return ll_tools_word_copy_result($receipt); }
        }
        // Exact parent compare-and-swap prevents moving an audio record that
        // another editor reassigned after preflight. No audio or image file is copied.
        foreach ($move_ids as $id) {
            if (!ll_tools_mutation_job_owns($lease)) { return ll_tools_word_copy_result($receipt); }
            clean_post_cache($id);
            $audio = get_post($id);
            if (!$audio instanceof WP_Post || !in_array($audio->post_status, ['publish','draft','pending','private'], true)
                || !ll_tools_word_copy_can_read_post($audio)) { return ll_tools_word_copy_result($receipt); }
            $moved = $wpdb->query($wpdb->prepare("UPDATE {$wpdb->posts} SET post_parent = %d
                WHERE ID = %d AND post_type = 'word_audio' AND post_parent = %d AND post_author = %d
                AND BINARY post_status = BINARY %s AND BINARY post_password = BINARY %s
                AND CONNECTION_ID() = %d AND IS_USED_LOCK(%s) = %d", $new_id, $id, $word_id, $audio->post_author, $audio->post_status, $audio->post_password, (int) $lease['connection_id'], $lease['name'], (int) $lease['connection_id']));
            clean_post_cache($id);
            if ($moved !== 1 || (int) wp_get_post_parent_id($id) !== $new_id) { return ll_tools_word_copy_result($receipt); }
            if (function_exists('ll_word_requires_audio_to_publish') && ll_word_requires_audio_to_publish($word_id)) {
                ll_tools_sync_parent_word_status_by_children($word_id);
            }
            $receipt['moved_ids'][] = $id;
            if (is_wp_error(ll_tools_mutation_job_write_option($key, $receipt, $lease))) { return ll_tools_word_copy_result($receipt); }
        }
        $desired_status = (string) $source->post_status;
        if ($desired_status === 'publish' && function_exists('ll_word_requires_audio_to_publish') && ll_word_requires_audio_to_publish($new_id)) {
            $has_audio = $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type='word_audio' AND post_parent=%d AND post_status='publish' LIMIT 1", $new_id));
            if ($wpdb->last_error !== '') { return ll_tools_word_copy_result($receipt); }
            if (!$has_audio) { $desired_status = 'draft'; }
        }
        if ($move_ids && function_exists('ll_word_requires_audio_to_publish') && ll_word_requires_audio_to_publish($word_id)) {
            ll_tools_sync_parent_word_status_by_children($word_id);
        }
        $new_title = $title !== '' ? $title : (string) $source->post_title;
        $updated = wp_update_post(wp_slash(['ID' => $new_id, 'post_status' => $desired_status, 'post_title' => $new_title]), true);
        clean_post_cache($new_id);
        $new_post = get_post($new_id);
        if (is_wp_error($updated) || !$new_post || $new_post->post_status !== $desired_status || $new_post->post_title !== $new_title
            || $new_post->post_content !== $source->post_content || $new_post->post_excerpt !== $source->post_excerpt
            || $new_post->post_password !== $source->post_password) { return ll_tools_word_copy_result($receipt); }
        $receipt['state'] = 'completed';
        if (is_wp_error(ll_tools_mutation_job_write_option($key, $receipt, $lease))) { $receipt['state'] = 'pending'; }
        return ll_tools_word_copy_result($receipt);
    } catch (Throwable $error) {
        $receipt = ll_tools_mutation_job_read_option($key);
        return is_array($receipt) ? ll_tools_word_copy_result($receipt) : ll_tools_word_copy_error('', 503);
    } finally {
        // Partial copies/moves affect caches too. Invalidate only the captured
        // source word-set scope; receipt writes never decide whether this runs.
        try { if (!empty($receipt['new_word_id'])) {
            foreach ((array) ($source_sets ?? [$wordset_id]) as $set_id) { ll_tools_wordset_editor_invalidate_wordset((int) $set_id); }
            if (function_exists('ll_tools_bump_quiz_content_cache_epoch')) { ll_tools_bump_quiz_content_cache_epoch((array) ($source_sets ?? [$wordset_id])); }
        } } finally { ll_tools_mutation_job_release($lease); }
    }
}

function ll_tools_word_copy_ajax(): void {
    foreach (['wordset_id', 'word_id', 'request_id', 'title', 'after_id'] as $field) {
        if (isset($_POST[$field]) && !is_scalar($_POST[$field])) { wp_send_json_error(['message' => __('Invalid copy request.', 'll-tools-text-domain')], 400); }
    }
    $wordset_id = absint($_POST['wordset_id'] ?? 0);
    $word_id = absint($_POST['word_id'] ?? 0);
    if (!check_ajax_referer('ll_wordset_manager_editor_' . $wordset_id, 'nonce', false)) {
        wp_send_json_error(['message' => __('Your session expired. Reload the page.', 'll-tools-text-domain')], 403);
    }
    $source = ll_tools_word_copy_source($wordset_id, $word_id);
    if (is_wp_error($source)) { $result = $source; }
    elseif (($_POST['action'] ?? '') === 'll_tools_word_copy_preview') {
        $result = ll_tools_word_copy_preview($wordset_id, $word_id, absint($_POST['after_id'] ?? 0));
    } elseif (($_POST['action'] ?? '') === 'll_tools_word_copy_status') {
        $receipt = ll_tools_mutation_job_read_option(ll_tools_word_copy_receipt_key($wordset_id, $word_id, sanitize_text_field(wp_unslash($_POST['request_id'] ?? ''))));
        $result = is_wp_error($receipt) ? $receipt : (is_array($receipt) ? ll_tools_word_copy_result($receipt) : ['state' => 'not_started']);
    } else {
        $result = ll_tools_word_copy_apply($wordset_id, $word_id, sanitize_text_field(wp_unslash($_POST['request_id'] ?? '')), (string) wp_unslash($_POST['title'] ?? ''), (array) ($_POST['move_ids'] ?? []));
    }
    if (is_wp_error($result)) { wp_send_json_error(['message' => $result->get_error_message()] + (array) $result->get_error_data(), (int) ($result->get_error_data()['status'] ?? 500)); }
    wp_send_json_success($result);
}
add_action('wp_ajax_ll_tools_word_copy_preview', 'll_tools_word_copy_ajax');
add_action('wp_ajax_ll_tools_word_copy_apply', 'll_tools_word_copy_ajax');
add_action('wp_ajax_ll_tools_word_copy_status', 'll_tools_word_copy_ajax');

function ll_tools_word_copy_enqueue_assets(int $wordset_id): void {
    ll_enqueue_asset_by_timestamp('/css/word-copy-dialog.css', 'll-word-copy-dialog');
    ll_enqueue_asset_by_timestamp('/js/word-copy-dialog.js', 'll-word-copy-dialog', [], true);
    wp_localize_script('ll-word-copy-dialog', 'llWordCopy', [
        'ajaxUrl' => admin_url('admin-ajax.php'), 'wordsetId' => $wordset_id, 'userId' => get_current_user_id(),
        'nonce' => wp_create_nonce('ll_wordset_manager_editor_' . $wordset_id),
        'heading' => __('Copy / Split Word', 'll-tools-text-domain'),
        'title' => __('New word title', 'll-tools-text-domain'),
        'recordings' => __('Move selected recordings to the copy', 'll-tools-text-domain'),
        'hint' => __('The image and word details are copied. Recordings stay on the original unless selected below.', 'll-tools-text-domain'),
        'empty' => __('No recordings to move.', 'll-tools-text-domain'),
        'loading' => __('Loading…', 'll-tools-text-domain'),
        'saving' => __('Copying…', 'll-tools-text-domain'),
        'submit' => __('Create copy', 'll-tools-text-domain'),
        'cancel' => __('Cancel', 'll-tools-text-domain'), 'close' => __('Close', 'll-tools-text-domain'),
        'more' => __('More recordings', 'll-tools-text-domain'), 'open' => __('View copy', 'll-tools-text-domain'),
        'retry' => __('Retry', 'll-tools-text-domain'), 'check' => __('Check result', 'll-tools-text-domain'),
        'failed' => __('The request did not finish. Please try again.', 'll-tools-text-domain'),
        'uncertain' => __('The result is not confirmed yet. Check it before starting another copy.', 'll-tools-text-domain'),
        'storageUnavailable' => __('Your browser could not save this copy request. Enable session storage and try again.', 'll-tools-text-domain'),
        'limit' => __('Choose at most 50 recordings for one copy.', 'll-tools-text-domain'),
    ]);
}
