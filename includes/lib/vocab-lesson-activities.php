<?php
if (!defined('WPINC')) { die; }

if (!defined('LL_TOOLS_VOCAB_LESSON_ACTIVITIES_META')) {
    define('LL_TOOLS_VOCAB_LESSON_ACTIVITIES_META', '_ll_tools_vocab_activity_settings');
}

/** Lesson preferences are separate from category presentation and word data. */
function ll_tools_get_vocab_lesson_activity_settings(int $lesson_id): array {
    $defaults = ['enabled_modes' => ['learning', 'practice', 'listening', 'self-check', 'gender'], 'embedded_mode' => ''];
    $stored = get_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_ACTIVITIES_META, true);
    if (!is_array($stored)) { return $defaults; }
    $modes = array_values(array_intersect($defaults['enabled_modes'], (array) ($stored['enabled_modes'] ?? $defaults['enabled_modes'])));
    if (!$modes) { $modes = ['practice']; }
    $embedded = is_string($stored['embedded_mode'] ?? null) ? $stored['embedded_mode'] : '';
    return ['enabled_modes' => $modes, 'embedded_mode' => in_array($embedded, $modes, true) ? $embedded : ''];
}

/** Validate the exact lesson boundary before an embed or widget uses its settings. */
function ll_tools_vocab_lesson_activity_context_is_accessible(int $lesson_id, int $category_id, int $wordset_id): bool {
    $lesson = get_post($lesson_id);
    if (!$lesson instanceof WP_Post || $lesson->post_type !== 'll_vocab_lesson'
        || !in_array($lesson->post_status, ['publish', 'private', 'draft', 'pending', 'future'], true)
        || ($lesson->post_status !== 'publish' && !current_user_can('read_post', $lesson_id))
        || post_password_required($lesson)
        || (int) get_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, true) !== $category_id
        || (int) get_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, true) !== $wordset_id
        || $category_id <= 0 || $wordset_id <= 0) {
        return false;
    }
    return (!ll_tools_vocab_lesson_is_preview_only($lesson_id) || ll_tools_current_user_can_view_vocab_lesson_preview($lesson_id))
        && (!function_exists('ll_tools_user_can_view_wordset') || ll_tools_user_can_view_wordset($wordset_id))
        && (!function_exists('ll_tools_user_can_view_category') || ll_tools_user_can_view_category($category_id));
}

/** Only query one exact category for audio; never hydrate the lesson's words. */
function ll_tools_get_vocab_lesson_available_modes(int $lesson_id): array {
    $settings = ll_tools_get_vocab_lesson_activity_settings($lesson_id);
    $wordset_id = (int) get_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, true);
    $category_id = (int) get_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, true);
    $category = get_term($category_id, 'word-category');
    if (!$category instanceof WP_Term || is_wp_error($category) || $wordset_id <= 0) { return []; }
    $config = ll_tools_vocab_lesson_get_effective_quiz_config($wordset_id, $category);
    return array_values(array_filter($settings['enabled_modes'], static function (string $mode) use ($wordset_id, $category_id, $category, $config): bool {
        if ($mode === 'listening') {
            return function_exists('ll_tools_category_has_playable_audio') && ll_tools_category_has_playable_audio($category_id, [$wordset_id]);
        }
        if ($mode === 'learning') { return !array_key_exists('learning_supported', $config) || !empty($config['learning_supported']); }
        if ($mode === 'self-check') { return !array_key_exists('self_check_supported', $config) || !empty($config['self_check_supported']); }
        if ($mode === 'gender') { return ll_tools_vocab_lesson_category_supports_gender_mode($wordset_id, $category); }
        return true;
    }));
}

function ll_tools_get_vocab_lesson_embedded_mode(int $lesson_id): string {
    $settings = ll_tools_get_vocab_lesson_activity_settings($lesson_id);
    if ($settings['embedded_mode'] === '') { return ''; }
    $available = ll_tools_get_vocab_lesson_available_modes($lesson_id);
    // An unavailable activity must not silently reveal the vocabulary answer overview.
    return in_array($settings['embedded_mode'], $available, true) ? $settings['embedded_mode'] : (string) ($available[0] ?? $settings['embedded_mode']);
}

function ll_tools_get_vocab_lesson_activity_embed_url(int $lesson_id, string $mode): string {
    $category = get_term((int) get_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, true), 'word-category');
    $wordset = get_term((int) get_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, true), 'wordset');
    if (!$category instanceof WP_Term || !$wordset instanceof WP_Term || !in_array($mode, ll_tools_get_vocab_lesson_available_modes($lesson_id), true)) { return ''; }
    return add_query_arg(['wordset' => $wordset->slug, 'mode' => $mode, 'll_context' => 'vocab_lesson', 'lesson_id' => $lesson_id], home_url(user_trailingslashit('/embed/' . $category->slug)));
}

/** @return array|null|WP_Error Omitted controls preserve existing settings. */
function ll_tools_vocab_lesson_activity_settings_from_request(array $request) {
    if (!array_key_exists('ll_vocab_lesson_activity_settings_present', $request)) { return null; }
    $raw_modes = $request['ll_vocab_lesson_enabled_modes'] ?? [];
    $embedded = $request['ll_vocab_lesson_embedded_mode'] ?? '';
    $allowed = ['learning', 'practice', 'listening', 'self-check', 'gender'];
    if ($request['ll_vocab_lesson_activity_settings_present'] !== '1' || !is_array($raw_modes) || count($raw_modes) > 5
        || !is_string($embedded) || strlen($embedded) > 20) {
        return ll_tools_vocab_lesson_category_settings_error('activities', __('Choose valid lesson activities.', 'll-tools-text-domain'));
    }
    foreach ($raw_modes as $mode) {
        if (!is_string($mode) || !in_array($mode, $allowed, true)) {
            return ll_tools_vocab_lesson_category_settings_error('activities', __('Choose valid lesson activities.', 'll-tools-text-domain'));
        }
    }
    $modes = array_values(array_intersect($allowed, $raw_modes));
    if (!$modes || ($embedded !== '' && !in_array($embedded, $modes, true))) {
        return ll_tools_vocab_lesson_category_settings_error('activities', __('Choose at least one activity and enable the activity that opens on this lesson.', 'll-tools-text-domain'));
    }
    return ['enabled_modes' => $modes, 'embedded_mode' => $embedded];
}

/** Single postmeta value participates in the existing category settings transaction. */
function ll_tools_write_verified_vocab_lesson_activity_settings(int $lesson_id, array $settings): bool {
    global $wpdb;
    $wpdb->last_error = '';
    update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_ACTIVITIES_META, $settings);
    if ($wpdb->last_error !== '') { return false; }
    $rows = $wpdb->get_col($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $lesson_id, LL_TOOLS_VOCAB_LESSON_ACTIVITIES_META));
    if ($wpdb->last_error !== '' || !$rows) { return false; }
    foreach ($rows as $row) { if (maybe_unserialize($row) !== $settings) { return false; } }
    return true;
}

function ll_tools_vocab_lesson_activity_settings_storage_is_transactional(): bool {
    global $wpdb;
    $wpdb->last_error = '';
    $status = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS LIKE %s', $wpdb->esc_like($wpdb->postmeta)), ARRAY_A);
    return (bool) apply_filters('ll_tools_vocab_lesson_activity_settings_storage_is_transactional', $wpdb->last_error === '' && is_array($status) && strtolower((string) ($status['Engine'] ?? '')) === 'innodb');
}
