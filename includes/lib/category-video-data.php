<?php
/** Read-only, bounded category media adapter for the browser video maker. */
if (!defined('WPINC')) { die; }

require_once __DIR__ . '/complete-source-reads.php';

function ll_tools_category_video_error(string $code = 'read_failed', int $status = 503): WP_Error {
    $message = $status === 403
        ? __('You do not have permission to create vocabulary videos.', 'll-tools-text-domain')
        : ($status === 400 ? __('Select a valid word set and category.', 'll-tools-text-domain')
            : __('Could not load vocabulary video sources. Please retry.', 'll-tools-text-domain'));
    return new WP_Error($code, $message, ['status' => $status]);
}

function ll_tools_category_video_can_access(): bool {
    return current_user_can('manage_options') && current_user_can('view_ll_tools');
}

/** Validate explicit scope before exposing media; incomplete privacy reads fail closed. */
function ll_tools_category_video_validate_scope(int $wordset_id, int $category_id = 0) {
    global $wpdb;
    if (!ll_tools_category_video_can_access()) { return ll_tools_category_video_error('forbidden', 403); }
    $wpdb->last_error = '';
    $wordset = get_term($wordset_id, 'wordset');
    if ($wpdb->last_error !== '' || is_wp_error($wordset)) { return ll_tools_category_video_error(); }
    if (!($wordset instanceof WP_Term)) { return ll_tools_category_video_error('invalid_scope', 400); }
    $complete = true;
    $allowed = ll_tools_user_can_view_wordset($wordset_id, get_current_user_id(), $complete);
    if (!$complete) { return ll_tools_category_video_error(); }
    if (!$allowed) { return ll_tools_category_video_error('forbidden', 403); }
    if ($category_id === 0) { return true; }
    $wpdb->last_error = '';
    $category = get_term($category_id, 'word-category');
    if ($wpdb->last_error !== '' || is_wp_error($category)) { return ll_tools_category_video_error(); }
    if (!($category instanceof WP_Term)) { return ll_tools_category_video_error('invalid_scope', 400); }
    $allowed = ll_tools_user_can_view_category($category, get_current_user_id(), $complete);
    if (!$complete) { return ll_tools_category_video_error(); }
    $owner = ll_tools_get_category_wordset_owner_id($category_id, $complete);
    if (!$complete) { return ll_tools_category_video_error(); }
    if (!$allowed || ($owner > 0 && $owner !== $wordset_id)) { return ll_tools_category_video_error('forbidden', 403); }
    return true;
}

/** Use the same first positive metadata row, or legacy author, for every speaker read. */
function ll_tools_category_video_speaker_sql(): string {
    global $wpdb;
    return "COALESCE((SELECT CAST(sm.meta_value AS UNSIGNED) FROM {$wpdb->postmeta} sm
        WHERE sm.post_id = a.ID AND sm.meta_key = 'speaker_user_id'
            AND sm.meta_value REGEXP '^[0-9]{1,10}$' AND CAST(sm.meta_value AS UNSIGNED) > 0
        ORDER BY sm.meta_id ASC LIMIT 1), a.post_author)";
}

/** Fixed-size taxonomy/user-ID pages, without hydrating the vocabulary catalog. */
function ll_tools_category_video_get_sources(int $wordset_id = 0, int $category_id = 0, int $cursor = 0) {
    if (!ll_tools_category_video_can_access()) { return ll_tools_category_video_error('forbidden', 403); }
    if ($wordset_id < 0 || $category_id < 0 || $cursor < 0 || (!$wordset_id && $category_id)) {
        return ll_tools_category_video_error('invalid_scope', 400);
    }
    return ll_tools_with_complete_source_reads(
        static function () use ($wordset_id, $category_id, $cursor) {
            return ll_tools_category_video_read_sources($wordset_id, $category_id, $cursor);
        },
        'll_tools_category_video_error'
    );
}

function ll_tools_category_video_read_sources(int $wordset_id, int $category_id, int $cursor) {
    global $wpdb;
    $limit = 100;
    if ($wordset_id) {
        $access = ll_tools_category_video_validate_scope($wordset_id, $category_id);
        if (is_wp_error($access)) { return $access; }
    }
    $wpdb->last_error = '';
    if (!$wordset_id) {
        $sql = $wpdb->prepare("SELECT t.term_id AS id, t.name, t.slug FROM {$wpdb->terms} t
            INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
            WHERE tt.taxonomy = 'wordset' AND t.term_id > %d ORDER BY t.term_id ASC LIMIT %d", $cursor, $limit + 1);
        $key = 'wordsets';
    } elseif (!$category_id) {
        $sql = $wpdb->prepare("SELECT t.term_id AS id, t.name, t.slug FROM {$wpdb->terms} t
            INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
            WHERE tt.taxonomy = 'word-category' AND t.term_id > %d
            AND EXISTS (SELECT 1 FROM {$wpdb->term_relationships} tc INNER JOIN {$wpdb->posts} w ON w.ID = tc.object_id
                WHERE tc.term_taxonomy_id = tt.term_taxonomy_id AND w.post_type = 'words' AND w.post_status = 'publish'
                AND EXISTS (SELECT 1 FROM {$wpdb->term_relationships} tw INNER JOIN {$wpdb->term_taxonomy} wt ON wt.term_taxonomy_id = tw.term_taxonomy_id
                    WHERE tw.object_id = w.ID AND wt.taxonomy = 'wordset' AND wt.term_id = %d))
            ORDER BY t.term_id ASC LIMIT %d", $cursor, $wordset_id, $limit + 1);
        $key = 'categories';
    } else {
        $speaker_sql = ll_tools_category_video_speaker_sql();
        $sql = $wpdb->prepare("SELECT DISTINCT {$speaker_sql} AS id FROM {$wpdb->posts} a
            INNER JOIN {$wpdb->posts} w ON w.ID = a.post_parent
            WHERE a.post_type = 'word_audio' AND a.post_status = 'publish' AND w.post_type = 'words' AND w.post_status = 'publish'
            AND w.post_password = '' AND a.post_password = ''
            AND EXISTS (SELECT 1 FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                WHERE tr.object_id = w.ID AND tt.taxonomy = 'wordset' AND tt.term_id = %d)
            AND EXISTS (SELECT 1 FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                WHERE tr.object_id = w.ID AND tt.taxonomy = 'word-category' AND tt.term_id = %d)
            AND EXISTS (SELECT 1 FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id WHERE tr.object_id = a.ID AND tt.taxonomy = 'recording_type' AND t.slug = 'isolation')
            HAVING id > %d ORDER BY id ASC LIMIT %d", $wordset_id, $category_id, $cursor, $limit + 1);
        $key = 'speakers';
    }
    $rows = $wpdb->get_results($sql, ARRAY_A);
    if ($wpdb->last_error !== '' || !is_array($rows)) { return ll_tools_category_video_error(); }
    $has_more = count($rows) > $limit;
    $rows = array_slice($rows, 0, $limit);
    $output = [];
    foreach ($rows as $row) {
        $cursor = (int) $row['id'];
        if ($key === 'speakers') {
            $wpdb->last_error = '';
            $user = get_userdata($cursor);
            if ($wpdb->last_error !== '') { return ll_tools_category_video_error(); }
            $output[] = ['id' => $cursor, 'name' => $user instanceof WP_User ? $user->display_name : sprintf(__('Speaker %d', 'll-tools-text-domain'), $cursor)];
            continue;
        }
        $access = $key === 'wordsets'
            ? ll_tools_category_video_validate_scope($cursor)
            : ll_tools_category_video_validate_scope($wordset_id, $cursor);
        if (is_wp_error($access)) {
            if ((int) ($access->get_error_data()['status'] ?? 503) === 403) { continue; }
            return $access;
        }
        $output[] = ['id' => $cursor, 'name' => (string) $row['name'], 'slug' => (string) $row['slug']];
    }
    return [$key => $output, 'next_cursor' => $cursor, 'has_more' => $has_more];
}

/** Resolve existing image relationships only: general effective-image helpers may create copies. */
function ll_tools_category_video_image_url(int $word_id, int $wordset_id): string {
    $image_id = (int) get_post_meta($word_id, '_ll_autopicked_image_id', true);
    $attachment_id = 0;
    if ($image_id > 0) {
        $owner = ll_tools_get_word_image_wordset_owner_id($image_id);
        if ($owner > 0 && $owner !== $wordset_id) {
            $image_id = ll_tools_get_existing_isolated_word_image_copy_id(ll_tools_get_word_image_isolation_source_id($image_id), $wordset_id);
        }
        $image = $image_id > 0 ? get_post($image_id) : null;
        if (!($image instanceof WP_Post) || $image->post_type !== 'word_images' || $image->post_status !== 'publish' || $image->post_password !== '') { return ''; }
        $attachment_id = (int) get_post_thumbnail_id($image_id);
    }
    if (!$attachment_id) { $attachment_id = (int) get_post_thumbnail_id($word_id); }
    if (!$attachment_id || !wp_attachment_is_image($attachment_id)) { return ''; }
    $size = wp_get_attachment_image_src($attachment_id, 'large');
    if (!is_array($size) || empty($size[0])) { return ''; }
    return (string) ll_tools_get_masked_image_url($attachment_id, 'large');
}

/** Return at most 25 candidate words; skips advance the same exact-category cursor. */
function ll_tools_category_video_get_items(int $wordset_id, int $category_id, int $speaker_id = 0, int $after_id = 0, int $limit = 25) {
    if (!ll_tools_category_video_can_access()) { return ll_tools_category_video_error('forbidden', 403); }
    if ($wordset_id <= 0 || $category_id <= 0 || $speaker_id < 0 || $after_id < 0) { return ll_tools_category_video_error('invalid_scope', 400); }
    return ll_tools_with_complete_source_reads(
        static function () use ($wordset_id, $category_id, $speaker_id, $after_id, $limit) {
            return ll_tools_category_video_read_items($wordset_id, $category_id, $speaker_id, $after_id, $limit);
        },
        'll_tools_category_video_error'
    );
}

function ll_tools_category_video_read_items(int $wordset_id, int $category_id, int $speaker_id, int $after_id, int $limit) {
    global $wpdb;
    $access = ll_tools_category_video_validate_scope($wordset_id, $category_id);
    if (is_wp_error($access)) { return $access; }
    $limit = max(1, min(25, $limit));
    $wpdb->last_error = '';
    $ids = $wpdb->get_col($wpdb->prepare("SELECT w.ID FROM {$wpdb->posts} w
        WHERE w.post_type = 'words' AND w.post_status = 'publish' AND w.post_password = '' AND w.ID > %d
        AND EXISTS (SELECT 1 FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
            WHERE tr.object_id = w.ID AND tt.taxonomy = 'wordset' AND tt.term_id = %d)
        AND EXISTS (SELECT 1 FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
            WHERE tr.object_id = w.ID AND tt.taxonomy = 'word-category' AND tt.term_id = %d)
        ORDER BY w.ID ASC LIMIT %d", $after_id, $wordset_id, $category_id, $limit + 1));
    if ($wpdb->last_error !== '' || !is_array($ids)) { return ll_tools_category_video_error(); }
    $has_more = count($ids) > $limit;
    $ids = array_map('intval', array_slice($ids, 0, $limit));
    $wpdb->last_error = '';
    _prime_post_caches($ids, false, true);
    if ($wpdb->last_error !== '') { return ll_tools_category_video_error(); }
    $items = $skipped = [];
    $speaker_sql = ll_tools_category_video_speaker_sql();
    $speaker_filter = $speaker_id > 0 ? $wpdb->prepare(' HAVING speaker_id = %d', $speaker_id) : '';
    foreach ($ids as $word_id) {
        $after_id = $word_id;
        $wpdb->last_error = '';
        $parts = ll_tools_get_word_text_parts($word_id, null, true, [$wordset_id]);
        $text = (string) ($parts['target_text'] ?? '');
        $image_url = ll_tools_category_video_image_url($word_id, $wordset_id);
        if ($wpdb->last_error !== '') { return ll_tools_category_video_error(); }
        if ($image_url === '') { $skipped[] = ['id' => $word_id, 'text' => $text, 'reason' => 'missing_image']; continue; }
        $wpdb->last_error = '';
        $recordings = $wpdb->get_results($wpdb->prepare("SELECT a.ID, {$speaker_sql} AS speaker_id FROM {$wpdb->posts} a
            WHERE a.post_type = 'word_audio' AND a.post_status = 'publish' AND a.post_password = '' AND a.post_parent = %d
            AND EXISTS (SELECT 1 FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id WHERE tr.object_id = a.ID AND tt.taxonomy = 'recording_type' AND t.slug = 'isolation')
            {$speaker_filter} ORDER BY a.ID DESC LIMIT 21", $word_id), ARRAY_A);
        if ($wpdb->last_error !== '' || !is_array($recordings)) { return ll_tools_category_video_error(); }
        $candidate_limit = count($recordings) > 20;
        $recordings = array_slice($recordings, 0, 20);
        _prime_post_caches(array_map('intval', array_column($recordings, 'ID')), false, true);
        if ($wpdb->last_error !== '') { return ll_tools_category_video_error(); }
        $reason = 'missing_audio';
        $selected = null;
        foreach ($recordings as $recording) {
            $audio_id = (int) $recording['ID'];
            $reviews = ll_tools_ipa_keyboard_get_recording_review_fields($audio_id);
            if (!empty($reviews['recording_text'])) { $reason = 'text_needs_review'; continue; }
            $path = (string) get_post_meta($audio_id, 'audio_file_path', true);
            if (trim($path) === '') { continue; }
            $audio_url = ll_tools_resolve_audio_file_url($path);
            if (!in_array(strtolower((string) wp_parse_url($audio_url, PHP_URL_SCHEME)), ['http', 'https'], true)) { continue; }
            $recording_text = (string) get_post_meta($audio_id, 'recording_text', true);
            $label = trim($recording_text) !== '' ? $recording_text : $text;
            if (trim($label) === '') { $reason = 'missing_text'; continue; }
            $selected = ['id' => $word_id, 'text' => $label, 'translation' => (string) ($parts['translation_text'] ?? ''),
                'image_url' => $image_url, 'audio_url' => $audio_url, 'recording_id' => $audio_id, 'speaker_id' => (int) $recording['speaker_id'],
                'text_source' => trim($recording_text) !== '' ? 'recording' : 'word'];
            break;
        }
        if ($wpdb->last_error !== '') { return ll_tools_category_video_error(); }
        if ($selected) { $items[] = $selected; }
        else { $skipped[] = ['id' => $word_id, 'text' => $text, 'reason' => $candidate_limit ? 'recording_limit' : $reason]; }
    }
    return ['schema' => 1, 'items' => $items, 'skipped' => $skipped, 'next_cursor' => $after_id, 'has_more' => $has_more];
}

function ll_tools_category_video_ajax(): void {
    if (!ll_tools_category_video_can_access()) { wp_send_json_error(['message' => ll_tools_category_video_error('forbidden', 403)->get_error_message()], 403); }
    check_ajax_referer('ll_tools_category_video', 'nonce');
    $values = [];
    foreach (['wordset_id', 'category_id', 'speaker_id', 'cursor'] as $key) {
        $raw = $_POST[$key] ?? '0';
        if (!is_scalar($raw) || !preg_match('/^\d{1,10}$/D', (string) $raw)) {
            wp_send_json_error(['message' => ll_tools_category_video_error('invalid_scope', 400)->get_error_message()], 400);
        }
        $values[$key] = (int) $raw;
    }
    $result = current_action() === 'wp_ajax_ll_tools_category_video_sources'
        ? ll_tools_category_video_get_sources($values['wordset_id'], $values['category_id'], $values['cursor'])
        : ll_tools_category_video_get_items($values['wordset_id'], $values['category_id'], $values['speaker_id'], $values['cursor']);
    nocache_headers();
    if (is_wp_error($result)) { wp_send_json_error(['message' => $result->get_error_message(), 'code' => $result->get_error_code()], (int) ($result->get_error_data()['status'] ?? 503)); }
    wp_send_json_success($result);
}
add_action('wp_ajax_ll_tools_category_video_sources', 'll_tools_category_video_ajax');
add_action('wp_ajax_ll_tools_category_video_items', 'll_tools_category_video_ajax');
