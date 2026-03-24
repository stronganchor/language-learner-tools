<?php
if (!defined('WPINC')) { die; }

if (!defined('LL_TOOLS_USER_PROGRESS_SCHEMA_VERSION')) {
    define('LL_TOOLS_USER_PROGRESS_SCHEMA_VERSION', '1.3.0');
}
if (!defined('LL_TOOLS_USER_PROGRESS_VERSION_OPTION')) {
    define('LL_TOOLS_USER_PROGRESS_VERSION_OPTION', 'll_tools_user_progress_schema_version');
}
if (!defined('LL_TOOLS_USER_GOALS_META')) {
    define('LL_TOOLS_USER_GOALS_META', 'll_user_study_goals');
}
if (!defined('LL_TOOLS_USER_CATEGORY_PROGRESS_META')) {
    define('LL_TOOLS_USER_CATEGORY_PROGRESS_META', 'll_user_study_category_progress');
}
if (!defined('LL_TOOLS_USER_RECOMMENDATION_QUEUE_META')) {
    define('LL_TOOLS_USER_RECOMMENDATION_QUEUE_META', 'll_user_study_recommendation_queue');
}
if (!defined('LL_TOOLS_USER_LAST_RECOMMENDATION_META')) {
    define('LL_TOOLS_USER_LAST_RECOMMENDATION_META', 'll_user_study_last_recommendation');
}
if (!defined('LL_TOOLS_USER_RECOMMENDATION_DISMISSED_META')) {
    define('LL_TOOLS_USER_RECOMMENDATION_DISMISSED_META', 'll_user_study_recommendation_dismissed');
}
if (!defined('LL_TOOLS_USER_RECOMMENDATION_DEFERRALS_META')) {
    define('LL_TOOLS_USER_RECOMMENDATION_DEFERRALS_META', 'll_user_study_recommendation_deferrals');
}

/**
 * Minimal access helper shared by dashboard AJAX endpoints.
 */
if (!function_exists('ll_tools_user_study_can_access')) {
    function ll_tools_user_study_can_access($user_id = 0): bool {
        $uid = (int) ($user_id ?: get_current_user_id());
        if ($uid <= 0) {
            return false;
        }

        if (current_user_can('manage_options') || current_user_can('view_ll_tools')) {
            return true;
        }

        // Keep learner-facing study tools available to normal logged-in users unless overridden.
        return (bool) apply_filters('ll_tools_allow_basic_user_study_access', current_user_can('read'), $uid);
    }
}

function ll_tools_user_progress_table_names(): array {
    global $wpdb;
    return [
        'words'  => $wpdb->prefix . 'll_tools_user_word_progress',
        'events' => $wpdb->prefix . 'll_tools_user_progress_events',
    ];
}

function ll_tools_progress_modes(): array {
    return ['learning', 'practice', 'listening', 'gender', 'self-check'];
}

function ll_tools_normalize_progress_mode($mode): string {
    $raw = is_string($mode) ? strtolower(trim($mode)) : '';
    if ($raw === 'self_check') {
        $raw = 'self-check';
    }
    return in_array($raw, ll_tools_progress_modes(), true) ? $raw : 'practice';
}

function ll_tools_progress_mode_column(string $mode): string {
    $map = [
        'learning'   => 'coverage_learning',
        'practice'   => 'coverage_practice',
        'listening'  => 'coverage_listening',
        'gender'     => 'coverage_gender',
        'self-check' => 'coverage_self_check',
    ];
    return $map[$mode] ?? 'coverage_practice';
}

function ll_tools_practice_recording_type_order(): array {
    $order = apply_filters('ll_tools_practice_recording_type_order', [
        'question',
        'isolation',
        'introduction',
        'sentence',
        'in-sentence',
    ]);

    if (!is_array($order) || empty($order)) {
        $order = ['question', 'isolation', 'introduction', 'sentence', 'in-sentence'];
    }

    $normalized = [];
    foreach ($order as $type) {
        $slug = ll_tools_normalize_practice_recording_type_slug($type);
        if ($slug === '') {
            continue;
        }
        $normalized[$slug] = $slug;
    }

    return array_values($normalized);
}

function ll_tools_sort_practice_recording_types(array $types): array {
    $normalized = [];
    foreach ($types as $type) {
        $slug = ll_tools_normalize_practice_recording_type_slug($type);
        if ($slug === '') {
            continue;
        }
        $normalized[$slug] = $slug;
    }

    if (empty($normalized)) {
        return [];
    }

    $ordered = ll_tools_practice_recording_type_order();
    $priority = array_flip($ordered);
    $values = array_values($normalized);

    usort($values, static function (string $left, string $right) use ($priority): int {
        $left_priority = array_key_exists($left, $priority) ? (int) $priority[$left] : PHP_INT_MAX;
        $right_priority = array_key_exists($right, $priority) ? (int) $priority[$right] : PHP_INT_MAX;
        if ($left_priority === $right_priority) {
            return strnatcasecmp($left, $right);
        }
        return $left_priority <=> $right_priority;
    });

    return $values;
}

function ll_tools_normalize_practice_recording_type_slug($type): string {
    $raw = strtolower(trim((string) $type));
    if ($raw === '') {
        return '';
    }

    $raw = preg_replace('/[\s_]+/', '-', $raw);
    return sanitize_key((string) $raw);
}

function ll_tools_decode_practice_recording_types($raw): array {
    if (is_array($raw)) {
        return ll_tools_sort_practice_recording_types($raw);
    }

    if (!is_string($raw)) {
        return [];
    }

    $trimmed = trim($raw);
    if ($trimmed === '') {
        return [];
    }

    $decoded = json_decode($trimmed, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        return ll_tools_sort_practice_recording_types($decoded);
    }

    return ll_tools_sort_practice_recording_types(array_map('trim', explode(',', $trimmed)));
}

function ll_tools_encode_practice_recording_types(array $types): string {
    $normalized = ll_tools_sort_practice_recording_types($types);
    if (empty($normalized)) {
        return '';
    }

    $encoded = wp_json_encode(array_values($normalized));
    return is_string($encoded) ? $encoded : '';
}

function ll_tools_user_progress_normalize_gender_level($raw, int $fallback = 1): int {
    $level = (int) $raw;
    if ($level >= 1 && $level <= 3) {
        return $level;
    }

    return ($fallback >= 1 && $fallback <= 3) ? $fallback : 1;
}

function ll_tools_user_progress_gender_default_state(): array {
    return [
        'level' => 1,
        'confidence' => 0,
        'intro_seen' => false,
        'quick_correct_streak' => 0,
        'level1_passes' => 0,
        'level1_failures' => 0,
        'level2_correct' => 0,
        'level2_wrong' => 0,
        'level3_correct' => 0,
        'level3_wrong' => 0,
        'dont_know_count' => 0,
        'seen_total' => 0,
        'category_name' => '',
        'last_seen_at' => '',
        'updated_at' => 0,
    ];
}

function ll_tools_user_progress_datetime_to_millis($raw): int {
    if (is_numeric($raw)) {
        $value = (int) round((float) $raw);
        if ($value <= 0) {
            return 0;
        }
        return ($value > 100000000000) ? $value : ($value * 1000);
    }

    if (!is_string($raw)) {
        return 0;
    }

    $value = trim($raw);
    if ($value === '') {
        return 0;
    }

    $timestamp = strtotime($value . ' UTC');
    if ($timestamp === false || $timestamp <= 0) {
        return 0;
    }

    return $timestamp * 1000;
}

function ll_tools_user_progress_normalize_gender_state($raw, array $fallback = []): array {
    $defaults = ll_tools_user_progress_gender_default_state();
    $base = array_merge($defaults, $fallback);
    $src = is_array($raw) ? $raw : [];

    $last_seen_at = isset($src['last_seen_at']) ? sanitize_text_field((string) $src['last_seen_at']) : sanitize_text_field((string) ($base['last_seen_at'] ?? ''));
    $updated_at = ll_tools_user_progress_datetime_to_millis(
        $src['updated_at'] ?? ($src['updated_at_ms'] ?? ($base['updated_at'] ?? 0))
    );
    if ($updated_at <= 0 && $last_seen_at !== '') {
        $updated_at = ll_tools_user_progress_datetime_to_millis($last_seen_at);
    }

    return [
        'level' => ll_tools_user_progress_normalize_gender_level(
            $src['level'] ?? ($base['level'] ?? 1),
            (int) ($base['level'] ?? 1)
        ),
        'confidence' => max(-8, min(12, (int) ($src['confidence'] ?? ($base['confidence'] ?? 0)))),
        'intro_seen' => !empty($src['intro_seen'] ?? ($base['intro_seen'] ?? false)),
        'quick_correct_streak' => max(0, (int) ($src['quick_correct_streak'] ?? ($base['quick_correct_streak'] ?? 0))),
        'level1_passes' => max(0, (int) ($src['level1_passes'] ?? ($base['level1_passes'] ?? 0))),
        'level1_failures' => max(0, (int) ($src['level1_failures'] ?? ($base['level1_failures'] ?? 0))),
        'level2_correct' => max(0, (int) ($src['level2_correct'] ?? ($base['level2_correct'] ?? 0))),
        'level2_wrong' => max(0, (int) ($src['level2_wrong'] ?? ($base['level2_wrong'] ?? 0))),
        'level3_correct' => max(0, (int) ($src['level3_correct'] ?? ($base['level3_correct'] ?? 0))),
        'level3_wrong' => max(0, (int) ($src['level3_wrong'] ?? ($base['level3_wrong'] ?? 0))),
        'dont_know_count' => max(0, (int) ($src['dont_know_count'] ?? ($base['dont_know_count'] ?? 0))),
        'seen_total' => max(0, (int) ($src['seen_total'] ?? ($base['seen_total'] ?? 0))),
        'category_name' => sanitize_text_field((string) ($src['category_name'] ?? ($base['category_name'] ?? ''))),
        'last_seen_at' => $last_seen_at,
        'updated_at' => $updated_at,
    ];
}

function ll_tools_encode_gender_progress_state(array $state): string {
    $normalized = ll_tools_user_progress_normalize_gender_state($state);
    $encoded = wp_json_encode($normalized);
    return is_string($encoded) ? $encoded : '';
}

function ll_tools_decode_gender_progress_state($raw, array $fallback = []): array {
    if (is_array($raw)) {
        return ll_tools_user_progress_normalize_gender_state($raw, $fallback);
    }

    if (!is_string($raw)) {
        return [];
    }

    $trimmed = trim($raw);
    if ($trimmed === '') {
        return [];
    }

    $decoded = json_decode($trimmed, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        return [];
    }

    return ll_tools_user_progress_normalize_gender_state($decoded, $fallback);
}

function ll_tools_progress_row_has_gender_tracking(array $row): bool {
    $level = max(0, (int) ($row['gender_level'] ?? 0));
    $seen_total = max(0, (int) ($row['gender_seen_total'] ?? 0));
    $last_seen_at = trim((string) ($row['gender_last_seen_at'] ?? ''));
    $state_json = trim((string) ($row['gender_state_json'] ?? ''));

    return ($level > 0) || ($seen_total > 0) || ($last_seen_at !== '') || ($state_json !== '');
}

function ll_tools_get_progress_row_gender_progress(array $row): array {
    if (isset($row['gender_progress']) && is_array($row['gender_progress'])) {
        return ll_tools_user_progress_normalize_gender_state((array) $row['gender_progress']);
    }

    if (!ll_tools_progress_row_has_gender_tracking($row)) {
        return [];
    }

    $fallback_level = ll_tools_user_progress_normalize_gender_level($row['gender_level'] ?? 1);
    $fallback = [
        'level' => $fallback_level,
        'seen_total' => max(0, (int) ($row['gender_seen_total'] ?? 0)),
        'category_name' => sanitize_text_field((string) ($row['category_name'] ?? '')),
        'last_seen_at' => sanitize_text_field((string) ($row['gender_last_seen_at'] ?? '')),
        'updated_at' => ll_tools_user_progress_datetime_to_millis($row['gender_last_seen_at'] ?? ($row['updated_at'] ?? 0)),
    ];

    $state = ll_tools_decode_gender_progress_state($row['gender_state_json'] ?? '', $fallback);
    if (empty($state)) {
        $state = ll_tools_user_progress_normalize_gender_state($fallback, $fallback);
    }

    if ($state['last_seen_at'] === '' && !empty($fallback['last_seen_at'])) {
        $state['last_seen_at'] = (string) $fallback['last_seen_at'];
    }
    if ($state['updated_at'] <= 0) {
        $state['updated_at'] = (int) ($fallback['updated_at'] ?? 0);
    }

    return $state;
}

function ll_tools_word_is_blocked_from_prompt_rounds(array $word): bool {
    if (array_key_exists('is_specific_wrong_answer_only', $word) && !empty($word['is_specific_wrong_answer_only'])) {
        return true;
    }

    $owner_ids = array_values(array_filter(array_map('intval', (array) ($word['specific_wrong_answer_owner_ids'] ?? [])), static function (int $owner_id): bool {
        return $owner_id > 0;
    }));
    if (empty($owner_ids)) {
        return false;
    }

    $specific_ids = array_values(array_filter(array_map('intval', (array) ($word['specific_wrong_answer_ids'] ?? [])), static function (int $word_id): bool {
        return $word_id > 0;
    }));
    $specific_texts = array_values(array_filter(array_map(static function ($value): string {
        return trim((string) $value);
    }, (array) ($word['specific_wrong_answer_texts'] ?? [])), static function (string $value): bool {
        return $value !== '';
    }));

    return empty($specific_ids) && empty($specific_texts);
}

function ll_tools_get_word_gender_support_snapshot(array $word, int $wordset_id, array $context = []): array {
    $defaults = [
        'normalized_gender' => '',
        'gender_marked' => false,
        'gender_progress_tracked' => false,
        'gender_eligible' => false,
        'requires_audio' => false,
        'requires_image' => false,
    ];

    if ($wordset_id <= 0
        || !function_exists('ll_tools_wordset_has_grammatical_gender')
        || !ll_tools_wordset_has_grammatical_gender($wordset_id)
        || !function_exists('ll_tools_wordset_get_gender_options')
        || !function_exists('ll_tools_wordset_normalize_gender_value_for_options')
    ) {
        return $defaults;
    }

    $options = ll_tools_wordset_get_gender_options($wordset_id);
    if (empty($options)) {
        return $defaults;
    }

    $gender_lookup = [];
    foreach ($options as $option) {
        $option_key = strtolower(trim((string) $option));
        if ($option_key !== '') {
            $gender_lookup[$option_key] = true;
        }
    }

    $raw_gender = trim((string) ($word['grammatical_gender'] ?? ''));
    $normalized_gender = ll_tools_wordset_normalize_gender_value_for_options($raw_gender, $options);
    $gender_key = strtolower(trim((string) $normalized_gender));
    $gender_marked = ($gender_key !== '') && !empty($gender_lookup[$gender_key]);

    $pos_values = (array) ($word['part_of_speech'] ?? []);
    $is_noun = in_array('noun', array_values(array_filter(array_map('sanitize_key', array_map('strval', $pos_values)))), true);
    $blocked_prompt = ll_tools_word_is_blocked_from_prompt_rounds($word);
    $tracked = $gender_marked && $is_noun && !$blocked_prompt;

    $quiz_config = (isset($context['quiz_config']) && is_array($context['quiz_config'])) ? $context['quiz_config'] : [];
    if (empty($quiz_config)) {
        $category_ref = 0;
        if (!empty($context['category_id'])) {
            $category_ref = (int) $context['category_id'];
        } elseif (!empty($context['category_name'])) {
            $term = get_term_by('name', sanitize_text_field((string) $context['category_name']), 'word-category');
            if ($term instanceof WP_Term && !is_wp_error($term)) {
                $category_ref = (int) $term->term_id;
            }
        }
        if ($category_ref > 0 && function_exists('ll_tools_get_category_quiz_config')) {
            $quiz_config = (array) ll_tools_get_category_quiz_config($category_ref);
        }
    }

    $prompt_type = sanitize_key((string) ($quiz_config['prompt_type'] ?? 'audio'));
    $option_type = sanitize_key((string) ($quiz_config['option_type'] ?? 'image'));
    $requires_audio = function_exists('ll_tools_quiz_requires_audio')
        ? ll_tools_quiz_requires_audio(
            [
                'prompt_type' => $prompt_type,
                'option_type' => $option_type,
            ],
            $option_type
        )
        : ($prompt_type === 'audio' || in_array($option_type, ['audio', 'text_audio'], true));
    $requires_image = ($prompt_type === 'image') || ($option_type === 'image');

    $has_audio = !empty($word['has_audio']) || !empty($word['audio']);
    $has_image = !empty($word['has_image']) || !empty($word['image']);
    $eligible = $tracked
        && (!$requires_audio || $has_audio)
        && (!$requires_image || $has_image);

    return [
        'normalized_gender' => $gender_marked ? $normalized_gender : '',
        'gender_marked' => $gender_marked,
        'gender_progress_tracked' => $tracked,
        'gender_eligible' => $eligible,
        'requires_audio' => (bool) $requires_audio,
        'requires_image' => (bool) $requires_image,
    ];
}

function ll_tools_get_word_practice_recording_types_map(array $word_ids): array {
    $word_ids = array_values(array_unique(array_filter(array_map('intval', $word_ids), static function (int $word_id): bool {
        return $word_id > 0;
    })));

    if (empty($word_ids)) {
        return [];
    }

    static $cache = [];
    $missing = [];
    foreach ($word_ids as $word_id) {
        if (!array_key_exists($word_id, $cache)) {
            $missing[] = $word_id;
        }
    }

    if (!empty($missing)) {
        $word_audio_posts = get_posts([
            'post_type'        => 'word_audio',
            'post_parent__in'  => $missing,
            'post_status'      => 'publish',
            'posts_per_page'   => -1,
            'suppress_filters' => true,
            'orderby'          => 'date',
            'order'            => 'DESC',
            'no_found_rows'    => true,
        ]);

        $audio_ids = array_values(array_filter(array_map(static function ($post): int {
            return ($post instanceof WP_Post) ? (int) $post->ID : 0;
        }, (array) $word_audio_posts), static function (int $post_id): bool {
            return $post_id > 0;
        }));

        if (!empty($audio_ids)) {
            update_postmeta_cache($audio_ids);
            update_object_term_cache($audio_ids, 'word_audio');
        }

        $types_by_word = [];
        foreach ($word_audio_posts as $audio_post) {
            if (!($audio_post instanceof WP_Post)) {
                continue;
            }

            $word_id = (int) $audio_post->post_parent;
            if ($word_id <= 0) {
                continue;
            }

            $audio_path = trim((string) get_post_meta($audio_post->ID, 'audio_file_path', true));
            if ($audio_path === '') {
                continue;
            }

            $recording_types = wp_get_post_terms($audio_post->ID, 'recording_type', ['fields' => 'slugs']);
            if (is_wp_error($recording_types) || empty($recording_types)) {
                continue;
            }

            if (!isset($types_by_word[$word_id])) {
                $types_by_word[$word_id] = [];
            }

            foreach ((array) $recording_types as $recording_type) {
                $slug = ll_tools_normalize_practice_recording_type_slug($recording_type);
                if ($slug === '') {
                    continue;
                }
                $types_by_word[$word_id][$slug] = $slug;
            }
        }

        foreach ($missing as $word_id) {
            $cache[$word_id] = ll_tools_sort_practice_recording_types(array_values($types_by_word[$word_id] ?? []));
        }
    }

    $out = [];
    foreach ($word_ids as $word_id) {
        $out[$word_id] = isset($cache[$word_id]) && is_array($cache[$word_id])
            ? $cache[$word_id]
            : [];
    }

    return $out;
}

function ll_tools_get_word_practice_recording_types(int $word_id): array {
    $map = ll_tools_get_word_practice_recording_types_map([$word_id]);
    return $map[$word_id] ?? [];
}

function ll_tools_get_progress_row_practice_correct_recording_types(array $row): array {
    if (isset($row['practice_correct_recording_types']) && is_array($row['practice_correct_recording_types'])) {
        return ll_tools_sort_practice_recording_types((array) $row['practice_correct_recording_types']);
    }
    return ll_tools_decode_practice_recording_types($row['practice_correct_recording_types'] ?? '');
}

function ll_tools_progress_row_has_practice_recording_tracking(array $row): bool {
    $stored_required = ll_tools_decode_practice_recording_types(
        $row['practice_required_recording_types_json'] ?? ($row['practice_required_recording_types'] ?? '')
    );
    $stored_correct = ll_tools_decode_practice_recording_types(
        $row['practice_correct_recording_types_json'] ?? ($row['practice_correct_recording_types'] ?? '')
    );

    return !empty($stored_required) || !empty($stored_correct);
}

function ll_tools_get_progress_row_practice_required_recording_types(array $row): array {
    if (!empty($row['practice_required_recording_types_resolved']) && is_array($row['practice_required_recording_types_resolved'])) {
        return ll_tools_sort_practice_recording_types((array) $row['practice_required_recording_types_resolved']);
    }

    if (isset($row['practice_required_recording_types']) && is_array($row['practice_required_recording_types'])) {
        $stored = ll_tools_sort_practice_recording_types((array) $row['practice_required_recording_types']);
    } else {
        $stored = ll_tools_decode_practice_recording_types($row['practice_required_recording_types'] ?? '');
    }

    if (!empty($stored)) {
        return $stored;
    }

    $word_id = isset($row['word_id']) ? (int) $row['word_id'] : 0;
    if ($word_id <= 0) {
        return [];
    }

    return ll_tools_get_word_practice_recording_types($word_id);
}

function ll_tools_attach_user_practice_progress_to_words(array $words, $user_id = 0, array $context = []): array {
    $uid = (int) ($user_id ?: get_current_user_id());
    if (empty($words)) {
        return $words;
    }

    $context_wordset_id = max(0, (int) ($context['wordset_id'] ?? 0));
    $context_category_id = max(0, (int) ($context['category_id'] ?? 0));
    $context_category_name = sanitize_text_field((string) ($context['category_name'] ?? ''));
    $context_quiz_config = (isset($context['quiz_config']) && is_array($context['quiz_config'])) ? $context['quiz_config'] : [];

    $word_ids = array_values(array_unique(array_filter(array_map(static function ($word): int {
        return (is_array($word) && !empty($word['id'])) ? (int) $word['id'] : 0;
    }, $words), static function (int $word_id): bool {
        return $word_id > 0;
    })));

    $progress_rows = ($uid > 0)
        ? ll_tools_get_user_word_progress_rows($uid, $word_ids)
        : [];

    foreach ($words as $idx => $word) {
        if (!is_array($word)) {
            continue;
        }

        $word_id = isset($word['id']) ? (int) $word['id'] : 0;
        $row = ($word_id > 0 && isset($progress_rows[$word_id]) && is_array($progress_rows[$word_id]))
            ? $progress_rows[$word_id]
            : [];

        $resolved_wordset_id = $context_wordset_id;
        if ($resolved_wordset_id <= 0) {
            $wordset_ids = array_values(array_filter(array_map('intval', (array) ($word['wordset_ids'] ?? [])), static function (int $wordset_id): bool {
                return $wordset_id > 0;
            }));
            $resolved_wordset_id = !empty($wordset_ids) ? (int) $wordset_ids[0] : 0;
        }

        $resolved_category_id = $context_category_id;
        if ($resolved_category_id <= 0) {
            $resolved_category_id = max(0, (int) ($word['category_id'] ?? 0));
        }

        $support = ll_tools_get_word_gender_support_snapshot($word, $resolved_wordset_id, [
            'category_id' => $resolved_category_id,
            'category_name' => $context_category_name,
            'quiz_config' => $context_quiz_config,
        ]);
        $progress_status = ll_tools_user_progress_word_status($row);
        $progress_total_coverage = max(0, (int) ($row['total_coverage'] ?? 0));

        $words[$idx]['practice_correct_recording_types'] = ll_tools_get_progress_row_practice_correct_recording_types($row);
        $words[$idx]['practice_exposure_count'] = max(0, (int) ($row['coverage_practice'] ?? 0));
        $words[$idx]['progress_status'] = $progress_status;
        $words[$idx]['progress_total_coverage'] = $progress_total_coverage;
        $words[$idx]['normalized_grammatical_gender'] = (string) ($support['normalized_gender'] ?? '');
        $words[$idx]['gender_marked'] = !empty($support['gender_marked']);
        $words[$idx]['gender_progress_tracked'] = !empty($support['gender_progress_tracked']);
        $words[$idx]['gender_eligible'] = !empty($support['gender_eligible']);
        $words[$idx]['gender_progress'] = ll_tools_get_progress_row_gender_progress($row);
        if ($context_category_name !== '') {
            $words[$idx]['__categoryName'] = $context_category_name;
        }
        if ($words[$idx]['normalized_grammatical_gender'] !== '') {
            $words[$idx]['__gender_label'] = $words[$idx]['normalized_grammatical_gender'];
        }
    }

    return $words;
}

function ll_tools_install_user_progress_schema(): void {
    global $wpdb;
    $tables = ll_tools_user_progress_table_names();
    $words_table = $tables['words'];
    $events_table = $tables['events'];

    $charset_collate = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $sql_words = "CREATE TABLE {$words_table} (
        user_id bigint(20) unsigned NOT NULL,
        word_id bigint(20) unsigned NOT NULL,
        category_id bigint(20) unsigned NOT NULL DEFAULT 0,
        wordset_id bigint(20) unsigned NOT NULL DEFAULT 0,
        first_seen_at datetime NOT NULL,
        last_seen_at datetime NOT NULL,
        last_mode varchar(32) NOT NULL DEFAULT '',
        total_coverage int(10) unsigned NOT NULL DEFAULT 0,
        coverage_learning int(10) unsigned NOT NULL DEFAULT 0,
        coverage_practice int(10) unsigned NOT NULL DEFAULT 0,
        coverage_listening int(10) unsigned NOT NULL DEFAULT 0,
        coverage_gender int(10) unsigned NOT NULL DEFAULT 0,
        coverage_self_check int(10) unsigned NOT NULL DEFAULT 0,
        gender_level smallint(5) unsigned NOT NULL DEFAULT 0,
        gender_seen_total int(10) unsigned NOT NULL DEFAULT 0,
        gender_last_seen_at datetime NULL DEFAULT NULL,
        gender_state_json longtext NULL,
        practice_required_recording_types longtext NULL,
        practice_correct_recording_types longtext NULL,
        correct_clean int(10) unsigned NOT NULL DEFAULT 0,
        correct_after_retry int(10) unsigned NOT NULL DEFAULT 0,
        current_correct_streak int(10) unsigned NOT NULL DEFAULT 0,
        mastery_unlocked tinyint(1) unsigned NOT NULL DEFAULT 0,
        incorrect int(10) unsigned NOT NULL DEFAULT 0,
        lapse_count int(10) unsigned NOT NULL DEFAULT 0,
        stage smallint(5) unsigned NOT NULL DEFAULT 0,
        due_at datetime NULL DEFAULT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (user_id, word_id),
        KEY idx_user_due (user_id, due_at),
        KEY idx_user_category (user_id, category_id),
        KEY idx_user_wordset (user_id, wordset_id),
        KEY idx_word (word_id)
    ) {$charset_collate};";

    $sql_events = "CREATE TABLE {$events_table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        user_id bigint(20) unsigned NOT NULL,
        event_uuid varchar(64) NOT NULL,
        event_type varchar(40) NOT NULL,
        mode varchar(32) NOT NULL DEFAULT '',
        word_id bigint(20) unsigned NOT NULL DEFAULT 0,
        category_id bigint(20) unsigned NOT NULL DEFAULT 0,
        wordset_id bigint(20) unsigned NOT NULL DEFAULT 0,
        is_correct tinyint(1) NULL DEFAULT NULL,
        had_wrong_before tinyint(1) NOT NULL DEFAULT 0,
        payload_json longtext NULL,
        created_at datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY uniq_event_uuid (event_uuid),
        KEY idx_user_created (user_id, created_at),
        KEY idx_user_word (user_id, word_id),
        KEY idx_user_category (user_id, category_id)
    ) {$charset_collate};";

    dbDelta($sql_words);
    dbDelta($sql_events);
    update_option(LL_TOOLS_USER_PROGRESS_VERSION_OPTION, LL_TOOLS_USER_PROGRESS_SCHEMA_VERSION, false);
}

function ll_tools_maybe_upgrade_user_progress_schema(): void {
    $installed = (string) get_option(LL_TOOLS_USER_PROGRESS_VERSION_OPTION, '');
    if ($installed === LL_TOOLS_USER_PROGRESS_SCHEMA_VERSION) {
        return;
    }
    ll_tools_install_user_progress_schema();
}
add_action('init', 'll_tools_maybe_upgrade_user_progress_schema', 12);

function ll_tools_default_user_study_goals(): array {
    return [
        'enabled_modes' => ll_tools_progress_modes(),
        'ignored_category_ids' => [],
        'preferred_wordset_ids' => [],
        'placement_known_category_ids' => [],
        'daily_new_word_target' => 2,
        'priority_focus' => '',
        'prioritize_new_words' => false,
        'prioritize_studied_words' => false,
        'prioritize_learned_words' => false,
        'prefer_starred_words' => false,
        'prefer_hard_words' => false,
    ];
}

function ll_tools_user_study_priority_focus_options(): array {
    return ['new', 'studied', 'learned', 'starred', 'hard'];
}

function ll_tools_normalize_user_study_priority_focus($focus): string {
    $key = sanitize_key((string) $focus);
    return in_array($key, ll_tools_user_study_priority_focus_options(), true) ? $key : '';
}

function ll_tools_sanitize_user_study_goals(array $raw): array {
    $defaults = ll_tools_default_user_study_goals();

    $enabled = isset($raw['enabled_modes']) ? (array) $raw['enabled_modes'] : $defaults['enabled_modes'];
    $enabled = array_values(array_unique(array_filter(array_map('ll_tools_normalize_progress_mode', $enabled))));
    if (empty($enabled)) {
        $enabled = $defaults['enabled_modes'];
    }

    $ignored = isset($raw['ignored_category_ids']) ? (array) $raw['ignored_category_ids'] : [];
    $ignored = array_values(array_unique(array_filter(array_map('intval', $ignored), function ($id) {
        return $id > 0;
    })));

    $preferred_wordsets = isset($raw['preferred_wordset_ids']) ? (array) $raw['preferred_wordset_ids'] : [];
    $preferred_wordsets = array_values(array_unique(array_filter(array_map('intval', $preferred_wordsets), function ($id) {
        return $id > 0;
    })));

    $placement = isset($raw['placement_known_category_ids']) ? (array) $raw['placement_known_category_ids'] : [];
    $placement = array_values(array_unique(array_filter(array_map('intval', $placement), function ($id) {
        return $id > 0;
    })));

    $daily = isset($raw['daily_new_word_target']) ? (int) $raw['daily_new_word_target'] : (int) $defaults['daily_new_word_target'];
    $daily = max(0, min(12, $daily));

    $priority_focus = ll_tools_normalize_user_study_priority_focus($raw['priority_focus'] ?? '');
    if ($priority_focus === '') {
        $legacy_focus_order = [
            'new' => !empty($raw['prioritize_new_words']),
            'studied' => !empty($raw['prioritize_studied_words']),
            'learned' => !empty($raw['prioritize_learned_words']),
            'starred' => !empty($raw['prefer_starred_words']),
            'hard' => !empty($raw['prefer_hard_words']),
        ];
        foreach ($legacy_focus_order as $legacy_key => $is_enabled) {
            if ($is_enabled) {
                $priority_focus = $legacy_key;
                break;
            }
        }
    }

    $prioritize_new_words = ($priority_focus === 'new');
    $prioritize_studied_words = ($priority_focus === 'studied');
    $prioritize_learned_words = ($priority_focus === 'learned');
    $prefer_starred_words = ($priority_focus === 'starred');
    $prefer_hard_words = ($priority_focus === 'hard');

    return [
        'enabled_modes' => $enabled,
        'ignored_category_ids' => $ignored,
        'preferred_wordset_ids' => $preferred_wordsets,
        'placement_known_category_ids' => $placement,
        'daily_new_word_target' => $daily,
        'priority_focus' => $priority_focus,
        'prioritize_new_words' => $prioritize_new_words,
        'prioritize_studied_words' => $prioritize_studied_words,
        'prioritize_learned_words' => $prioritize_learned_words,
        'prefer_starred_words' => $prefer_starred_words,
        'prefer_hard_words' => $prefer_hard_words,
    ];
}

function ll_tools_get_user_study_goals($user_id = 0): array {
    $uid = (int) ($user_id ?: get_current_user_id());
    if ($uid <= 0) {
        return ll_tools_default_user_study_goals();
    }
    $raw = get_user_meta($uid, LL_TOOLS_USER_GOALS_META, true);
    if (!is_array($raw)) {
        $raw = [];
    }
    return ll_tools_sanitize_user_study_goals($raw);
}

function ll_tools_save_user_study_goals(array $goals, $user_id = 0): array {
    $uid = (int) ($user_id ?: get_current_user_id());
    if ($uid <= 0) {
        return ll_tools_default_user_study_goals();
    }
    $normalized = ll_tools_sanitize_user_study_goals($goals);
    update_user_meta($uid, LL_TOOLS_USER_GOALS_META, $normalized);
    return $normalized;
}

function ll_tools_get_user_category_progress($user_id = 0): array {
    $uid = (int) ($user_id ?: get_current_user_id());
    if ($uid <= 0) {
        return [];
    }
    $raw = get_user_meta($uid, LL_TOOLS_USER_CATEGORY_PROGRESS_META, true);
    if (!is_array($raw)) {
        return [];
    }

    $modes = ll_tools_progress_modes();
    $out = [];
    foreach ($raw as $cid => $entry) {
        $category_id = (int) $cid;
        if ($category_id <= 0 || !is_array($entry)) {
            continue;
        }
        $by_mode_raw = isset($entry['exposure_by_mode']) && is_array($entry['exposure_by_mode']) ? $entry['exposure_by_mode'] : [];
        $by_mode = [];
        foreach ($modes as $mode) {
            $by_mode[$mode] = max(0, (int) ($by_mode_raw[$mode] ?? 0));
        }
        $out[$category_id] = [
            'category_id'      => $category_id,
            'wordset_id'       => max(0, (int) ($entry['wordset_id'] ?? 0)),
            'exposure_total'   => max(0, (int) ($entry['exposure_total'] ?? 0)),
            'exposure_by_mode' => $by_mode,
            'last_mode'        => ll_tools_normalize_progress_mode((string) ($entry['last_mode'] ?? 'practice')),
            'last_seen_at'     => isset($entry['last_seen_at']) ? (string) $entry['last_seen_at'] : '',
        ];
    }

    return $out;
}

function ll_tools_record_category_exposure($user_id, int $category_id, string $mode, int $wordset_id = 0, int $delta = 1): void {
    $uid = (int) $user_id;
    $category_id = (int) $category_id;
    $delta = max(1, (int) $delta);
    if ($uid <= 0 || $category_id <= 0) {
        return;
    }

    $mode = ll_tools_normalize_progress_mode($mode);
    $progress = ll_tools_get_user_category_progress($uid);
    if (!isset($progress[$category_id])) {
        $progress[$category_id] = [
            'category_id'      => $category_id,
            'wordset_id'       => max(0, $wordset_id),
            'exposure_total'   => 0,
            'exposure_by_mode' => array_fill_keys(ll_tools_progress_modes(), 0),
            'last_mode'        => $mode,
            'last_seen_at'     => '',
        ];
    }

    $entry = $progress[$category_id];
    $entry['exposure_total'] = max(0, (int) $entry['exposure_total']) + $delta;
    $entry['exposure_by_mode'][$mode] = max(0, (int) ($entry['exposure_by_mode'][$mode] ?? 0)) + $delta;
    $entry['last_mode'] = $mode;
    $entry['last_seen_at'] = gmdate('Y-m-d H:i:s');
    if ($wordset_id > 0) {
        $entry['wordset_id'] = $wordset_id;
    }

    $progress[$category_id] = $entry;
    update_user_meta($uid, LL_TOOLS_USER_CATEGORY_PROGRESS_META, $progress);
}

function ll_tools_progress_due_intervals_days(): array {
    return [
        0 => 0,
        1 => 1,
        2 => 2,
        3 => 4,
        4 => 7,
        5 => 14,
        6 => 30,
    ];
}

function ll_tools_progress_due_at_for_stage(int $stage, int $base_ts): string {
    $intervals = ll_tools_progress_due_intervals_days();
    $clamped_stage = max(0, min(6, $stage));
    $days = (int) ($intervals[$clamped_stage] ?? 0);
    if ($days <= 0) {
        return gmdate('Y-m-d H:i:s', $base_ts + 12 * HOUR_IN_SECONDS);
    }
    return gmdate('Y-m-d H:i:s', $base_ts + ($days * DAY_IN_SECONDS));
}

/**
 * Apply stronger self-check-specific outcome weighting when payload provides a bucket.
 * Returns true when a self-check bucket was recognized and applied.
 */
function ll_tools_apply_self_check_outcome_signal(array &$data, array $event, int $base_ts): bool {
    $mode = ll_tools_normalize_progress_mode((string) ($event['mode'] ?? 'practice'));
    if ($mode !== 'self-check') {
        return false;
    }
    $payload = isset($event['payload']) && is_array($event['payload']) ? $event['payload'] : [];
    $bucket = isset($payload['self_check_bucket']) ? strtolower(trim((string) $payload['self_check_bucket'])) : '';
    if (!in_array($bucket, ['idk', 'wrong', 'close', 'right'], true)) {
        return false;
    }

    $stage = max(0, min(6, (int) ($data['stage'] ?? 0)));

    if ($bucket === 'idk') {
        $data['incorrect'] = max(0, (int) $data['incorrect']) + 2;
        $data['lapse_count'] = max(0, (int) $data['lapse_count']) + 2;
        $data['stage'] = 0;
        $data['due_at'] = gmdate('Y-m-d H:i:s', $base_ts + (4 * HOUR_IN_SECONDS));
        return true;
    }

    if ($bucket === 'wrong') {
        $data['incorrect'] = max(0, (int) $data['incorrect']) + 1;
        $data['lapse_count'] = max(0, (int) $data['lapse_count']) + 1;
        $data['stage'] = max(0, $stage - 1);
        $data['due_at'] = gmdate('Y-m-d H:i:s', $base_ts + (8 * HOUR_IN_SECONDS));
        return true;
    }

    if ($bucket === 'close') {
        $data['correct_after_retry'] = max(0, (int) $data['correct_after_retry']) + 1;
        $data['stage'] = max(1, $stage);
        $data['due_at'] = ll_tools_progress_due_at_for_stage((int) $data['stage'], $base_ts);
        return true;
    }

    $data['correct_clean'] = max(0, (int) $data['correct_clean']) + 1;
    $data['stage'] = max(0, min(6, max($stage + 2, 3)));
    $data['due_at'] = ll_tools_progress_due_at_for_stage((int) $data['stage'], $base_ts);
    return true;
}

function ll_tools_resolve_wordset_id_for_word(int $word_id): int {
    if ($word_id <= 0) {
        return 0;
    }
    $terms = wp_get_post_terms($word_id, 'wordset', ['fields' => 'ids']);
    if (is_wp_error($terms) || empty($terms)) {
        return 0;
    }
    return max(0, (int) $terms[0]);
}

function ll_tools_resolve_category_id_for_word(int $word_id): int {
    if ($word_id <= 0) {
        return 0;
    }
    $terms = wp_get_post_terms($word_id, 'word-category', ['fields' => 'ids']);
    if (is_wp_error($terms) || empty($terms)) {
        return 0;
    }
    return max(0, (int) $terms[0]);
}

function ll_tools_resolve_category_id_from_event(array $event): int {
    if (!empty($event['category_id'])) {
        return (int) $event['category_id'];
    }
    $name = isset($event['category_name']) ? trim((string) $event['category_name']) : '';
    if ($name !== '') {
        $term = get_term_by('name', $name, 'word-category');
        if (!$term || is_wp_error($term)) {
            $term = get_term_by('slug', sanitize_title($name), 'word-category');
        }
        if ($term && !is_wp_error($term)) {
            return (int) $term->term_id;
        }
    }
    if (!empty($event['word_id'])) {
        return ll_tools_resolve_category_id_for_word((int) $event['word_id']);
    }
    return 0;
}

function ll_tools_sanitize_progress_event(array $raw): ?array {
    $type = isset($raw['event_type']) ? (string) $raw['event_type'] : (string) ($raw['type'] ?? '');
    $type = strtolower(trim($type));
    $allowed_types = ['word_outcome', 'word_exposure', 'category_study', 'mode_session_complete'];
    if (!in_array($type, $allowed_types, true)) {
        return null;
    }

    $uuid = isset($raw['event_uuid']) ? (string) $raw['event_uuid'] : (string) ($raw['uuid'] ?? '');
    $uuid = sanitize_text_field(substr($uuid, 0, 64));
    if ($uuid === '') {
        $uuid = wp_generate_uuid4();
    }

    $mode = ll_tools_normalize_progress_mode((string) ($raw['mode'] ?? 'practice'));
    $word_id = isset($raw['word_id']) ? (int) $raw['word_id'] : 0;
    $category_id = isset($raw['category_id']) ? (int) $raw['category_id'] : 0;
    $wordset_id = isset($raw['wordset_id']) ? (int) $raw['wordset_id'] : 0;

    $is_correct = null;
    if (array_key_exists('is_correct', $raw)) {
        $is_correct = filter_var($raw['is_correct'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }
    $had_wrong_before = filter_var(($raw['had_wrong_before'] ?? false), FILTER_VALIDATE_BOOLEAN);

    $payload = isset($raw['payload']) && is_array($raw['payload']) ? $raw['payload'] : [];
    $category_name = isset($raw['category_name']) ? sanitize_text_field((string) $raw['category_name']) : '';

    return [
        'event_uuid'       => $uuid,
        'event_type'       => $type,
        'mode'             => $mode,
        'word_id'          => max(0, $word_id),
        'category_id'      => max(0, $category_id),
        'wordset_id'       => max(0, $wordset_id),
        'is_correct'       => $is_correct,
        'had_wrong_before' => !empty($had_wrong_before),
        'payload'          => $payload,
        'category_name'    => $category_name,
    ];
}

function ll_tools_is_soft_space_shooter_incorrect_event(array $event): bool {
    if (($event['is_correct'] ?? null) !== false) {
        return false;
    }

    $mode = ll_tools_normalize_progress_mode((string) ($event['mode'] ?? 'practice'));
    if ($mode !== 'practice') {
        return false;
    }

    $payload = isset($event['payload']) && is_array($event['payload']) ? $event['payload'] : [];
    return strtolower(trim((string) ($payload['event_source'] ?? ''))) === 'space_shooter';
}

function ll_tools_user_progress_get_practice_audio_timing(array $payload): array {
    $ratio = null;
    if (isset($payload['audio_progress_ratio']) && is_numeric($payload['audio_progress_ratio'])) {
        $ratio = (float) $payload['audio_progress_ratio'];
        if (!is_finite($ratio)) {
            $ratio = null;
        }
    }

    if ($ratio !== null) {
        $ratio = max(0.0, min(1.0, $ratio));
    }

    $answered_before_end = filter_var(
        $payload['answered_before_audio_end'] ?? false,
        FILTER_VALIDATE_BOOLEAN
    );

    if ($ratio === null && !$answered_before_end) {
        return [
            'ratio' => null,
            'early' => false,
            'very_fast' => false,
        ];
    }

    return [
        'ratio' => $ratio,
        'early' => $answered_before_end || ($ratio !== null && $ratio < 0.98),
        'very_fast' => $ratio !== null && $ratio <= 0.45,
    ];
}

function ll_tools_apply_word_progress_event(int $user_id, array $event, string $now_mysql): bool {
    global $wpdb;
    $tables = ll_tools_user_progress_table_names();
    $table = $tables['words'];

    $word_id = (int) ($event['word_id'] ?? 0);
    if ($word_id <= 0) {
        return false;
    }

    $row = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM {$table} WHERE user_id = %d AND word_id = %d", $user_id, $word_id),
        ARRAY_A
    );

    $base_ts = time();
    $mode = ll_tools_normalize_progress_mode((string) ($event['mode'] ?? 'practice'));
    $category_id = max(0, (int) ($event['category_id'] ?? 0));
    $wordset_id = max(0, (int) ($event['wordset_id'] ?? 0));

    if ($category_id <= 0) {
        $category_id = ll_tools_resolve_category_id_for_word($word_id);
    }
    if ($wordset_id <= 0) {
        $wordset_id = ll_tools_resolve_wordset_id_for_word($word_id);
    }

    $data = [
        'user_id'              => $user_id,
        'word_id'              => $word_id,
        'category_id'          => $category_id,
        'wordset_id'           => $wordset_id,
        'first_seen_at'        => $now_mysql,
        'last_seen_at'         => $now_mysql,
        'last_mode'            => $mode,
        'total_coverage'       => 0,
        'coverage_learning'    => 0,
        'coverage_practice'    => 0,
        'coverage_listening'   => 0,
        'coverage_gender'      => 0,
        'coverage_self_check'  => 0,
        'gender_level'         => 0,
        'gender_seen_total'    => 0,
        'gender_last_seen_at'  => null,
        'gender_state_json'    => '',
        'practice_required_recording_types' => '',
        'practice_correct_recording_types' => '',
        'correct_clean'        => 0,
        'correct_after_retry'  => 0,
        'current_correct_streak' => 0,
        'mastery_unlocked'     => 0,
        'incorrect'            => 0,
        'lapse_count'          => 0,
        'stage'                => 0,
        'due_at'               => null,
        'updated_at'           => $now_mysql,
    ];

    if (is_array($row)) {
        foreach ($data as $key => $default_val) {
            if (!array_key_exists($key, $row)) {
                continue;
            }
            if (is_numeric($default_val)) {
                $data[$key] = (int) $row[$key];
            } elseif ($key === 'due_at' || $key === 'gender_last_seen_at') {
                $data[$key] = !empty($row[$key]) ? (string) $row[$key] : null;
            } else {
                $data[$key] = (string) $row[$key];
            }
        }
    }

    $data['last_seen_at'] = $now_mysql;
    $data['updated_at'] = $now_mysql;
    $data['last_mode'] = $mode;
    if ($category_id > 0) {
        $data['category_id'] = $category_id;
    }
    if ($wordset_id > 0) {
        $data['wordset_id'] = $wordset_id;
    }

    $event_type = (string) ($event['event_type'] ?? 'word_exposure');

    if ($event_type === 'word_exposure') {
        $data['total_coverage'] = max(0, (int) $data['total_coverage']) + 1;
        $mode_column = ll_tools_progress_mode_column($mode);
        $data[$mode_column] = max(0, (int) $data[$mode_column]) + 1;
    }

    if ($event_type === 'word_outcome') {
        $payload = isset($event['payload']) && is_array($event['payload']) ? $event['payload'] : [];
        $practice_timing = ($mode === 'practice')
            ? ll_tools_user_progress_get_practice_audio_timing($payload)
            : ['ratio' => null, 'early' => false, 'very_fast' => false];
        if ($mode === 'gender' && !empty($payload['gender']) && is_array($payload['gender'])) {
            $gender_state = ll_tools_user_progress_normalize_gender_state($payload['gender'], [
                'level' => ll_tools_user_progress_normalize_gender_level($data['gender_level'] ?? 1),
                'seen_total' => max(0, (int) ($data['gender_seen_total'] ?? 0)),
                'category_name' => sanitize_text_field((string) ($event['category_name'] ?? '')),
                'last_seen_at' => $now_mysql,
                'updated_at' => ll_tools_user_progress_datetime_to_millis($now_mysql),
            ]);
            $gender_state['last_seen_at'] = $now_mysql;
            if ($gender_state['category_name'] === '' && !empty($event['category_name'])) {
                $gender_state['category_name'] = sanitize_text_field((string) $event['category_name']);
            }
            if ($gender_state['updated_at'] <= 0) {
                $gender_state['updated_at'] = ll_tools_user_progress_datetime_to_millis($now_mysql);
            }

            $data['gender_level'] = ll_tools_user_progress_normalize_gender_level($gender_state['level'] ?? 1);
            $data['gender_seen_total'] = max(0, (int) ($gender_state['seen_total'] ?? 0));
            $data['gender_last_seen_at'] = $now_mysql;
            $data['gender_state_json'] = ll_tools_encode_gender_progress_state($gender_state);
        }
        if ($mode === 'practice') {
            $required_types = ll_tools_decode_practice_recording_types($payload['available_recording_types'] ?? []);
            if (empty($required_types)) {
                $required_types = ll_tools_get_progress_row_practice_required_recording_types($data);
            }

            $recording_type = ll_tools_normalize_practice_recording_type_slug($payload['recording_type'] ?? '');
            if ($recording_type !== '') {
                $required_types[] = $recording_type;
            }

            $required_types = ll_tools_sort_practice_recording_types($required_types);
            $correct_types = ll_tools_get_progress_row_practice_correct_recording_types($data);
            if (($event['is_correct'] ?? null) === true && $recording_type !== '') {
                $correct_types[] = $recording_type;
            }

            $correct_types = ll_tools_sort_practice_recording_types($correct_types);
            if (!empty($required_types)) {
                $correct_types = array_values(array_intersect($correct_types, $required_types));
            }

            $data['practice_required_recording_types'] = ll_tools_encode_practice_recording_types($required_types);
            $data['practice_correct_recording_types'] = ll_tools_encode_practice_recording_types($correct_types);
        }

        $handled_self_check = ll_tools_apply_self_check_outcome_signal($data, $event, $base_ts);
        if (!$handled_self_check) {
            $is_correct = $event['is_correct'];
            $had_wrong_before = !empty($event['had_wrong_before']);
            if ($is_correct === true) {
                $stage_bonus = ($mode === 'practice' && !empty($practice_timing['very_fast'])) ? 1 : 0;
                if ($had_wrong_before) {
                    $data['correct_after_retry'] = max(0, (int) $data['correct_after_retry']) + 1;
                    $data['stage'] = max(1, min(6, (int) $data['stage'] + $stage_bonus));
                } else {
                    $data['correct_clean'] = max(0, (int) $data['correct_clean']) + 1;
                    $data['stage'] = max(0, min(6, (int) $data['stage'] + 1 + $stage_bonus));
                }
                $data['due_at'] = ll_tools_progress_due_at_for_stage((int) $data['stage'], $base_ts);
            } elseif ($is_correct === false) {
                $data['incorrect'] = max(0, (int) $data['incorrect']) + 1;
                if (!ll_tools_is_soft_space_shooter_incorrect_event($event)) {
                    $apply_lapse = true;
                    $apply_stage_drop = true;
                    if ($mode === 'practice') {
                        if (!empty($practice_timing['very_fast'])) {
                            $apply_lapse = false;
                            $apply_stage_drop = false;
                        } elseif (!empty($practice_timing['early'])) {
                            $apply_stage_drop = false;
                        }
                    }

                    if ($apply_lapse) {
                        $data['lapse_count'] = max(0, (int) $data['lapse_count']) + 1;
                    }
                    if ($apply_stage_drop) {
                        $data['stage'] = max(0, min(6, (int) $data['stage'] - 1));
                    }
                    $data['due_at'] = gmdate('Y-m-d H:i:s', $base_ts + (12 * HOUR_IN_SECONDS));
                }
            }
        }

        $is_correct = $event['is_correct'];
        if ($is_correct === true) {
            $streak_increment = 1;
            if ($mode === 'practice' && !empty($practice_timing['early'])) {
                $streak_increment++;
            }
            $data['current_correct_streak'] = max(0, (int) $data['current_correct_streak']) + $streak_increment;
        } elseif ($is_correct === false) {
            $data['current_correct_streak'] = 0;
        }

        if (ll_tools_user_progress_row_meets_mastery_requirements($data)) {
            $data['mastery_unlocked'] = 1;
        }
    }

    $formats = [
        '%d', // user_id
        '%d', // word_id
        '%d', // category_id
        '%d', // wordset_id
        '%s', // first_seen_at
        '%s', // last_seen_at
        '%s', // last_mode
        '%d', // total_coverage
        '%d', // coverage_learning
        '%d', // coverage_practice
        '%d', // coverage_listening
        '%d', // coverage_gender
        '%d', // coverage_self_check
        '%d', // gender_level
        '%d', // gender_seen_total
        '%s', // gender_last_seen_at
        '%s', // gender_state_json
        '%s', // practice_required_recording_types
        '%s', // practice_correct_recording_types
        '%d', // correct_clean
        '%d', // correct_after_retry
        '%d', // current_correct_streak
        '%d', // mastery_unlocked
        '%d', // incorrect
        '%d', // lapse_count
        '%d', // stage
        '%s', // due_at
        '%s', // updated_at
    ];

    $saved = $wpdb->replace($table, $data, $formats);
    if ($saved === false) {
        return false;
    }

    if ($category_id > 0 && $event_type === 'word_exposure') {
        ll_tools_record_category_exposure($user_id, $category_id, $mode, $wordset_id, 1);
    }

    return true;
}

function ll_tools_process_progress_events_batch(int $user_id, array $events): array {
    global $wpdb;

    $tables = ll_tools_user_progress_table_names();
    $events_table = $tables['events'];

    $stats = [
        'received' => count($events),
        'processed' => 0,
        'duplicates' => 0,
        'invalid' => 0,
        'failed' => 0,
    ];

    $now = gmdate('Y-m-d H:i:s');

    foreach ($events as $raw) {
        if (!is_array($raw)) {
            $stats['invalid']++;
            continue;
        }

        $event = ll_tools_sanitize_progress_event($raw);
        if (!$event) {
            $stats['invalid']++;
            continue;
        }

        $event['category_id'] = ll_tools_resolve_category_id_from_event($event);
        if ($event['wordset_id'] <= 0 && !empty($event['word_id'])) {
            $event['wordset_id'] = ll_tools_resolve_wordset_id_for_word((int) $event['word_id']);
        }

        $inserted = $wpdb->insert(
            $events_table,
            [
                'user_id' => $user_id,
                'event_uuid' => $event['event_uuid'],
                'event_type' => $event['event_type'],
                'mode' => $event['mode'],
                'word_id' => (int) $event['word_id'],
                'category_id' => (int) $event['category_id'],
                'wordset_id' => (int) $event['wordset_id'],
                'is_correct' => is_null($event['is_correct']) ? null : ($event['is_correct'] ? 1 : 0),
                'had_wrong_before' => !empty($event['had_wrong_before']) ? 1 : 0,
                'payload_json' => !empty($event['payload']) ? wp_json_encode($event['payload']) : null,
                'created_at' => $now,
            ],
            ['%d', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%s']
        );

        if ($inserted === false) {
            $last_error = (string) $wpdb->last_error;
            if (stripos($last_error, 'duplicate') !== false) {
                $stats['duplicates']++;
            } else {
                $stats['failed']++;
            }
            continue;
        }

        $ok = true;
        if ($event['event_type'] === 'word_outcome' || $event['event_type'] === 'word_exposure') {
            $ok = ll_tools_apply_word_progress_event($user_id, $event, $now);
        } elseif ($event['event_type'] === 'category_study') {
            if (!empty($event['category_id'])) {
                $delta = isset($event['payload']['units']) ? max(1, (int) $event['payload']['units']) : 1;
                ll_tools_record_category_exposure($user_id, (int) $event['category_id'], $event['mode'], (int) $event['wordset_id'], $delta);
            }
        } elseif ($event['event_type'] === 'mode_session_complete') {
            $payload_categories = isset($event['payload']['category_ids']) ? (array) $event['payload']['category_ids'] : [];
            $payload_categories = array_values(array_filter(array_map('intval', $payload_categories), function ($id) {
                return $id > 0;
            }));
            foreach ($payload_categories as $cid) {
                ll_tools_record_category_exposure($user_id, $cid, $event['mode'], (int) $event['wordset_id'], 1);
            }
        }

        if ($ok) {
            $stats['processed']++;
        } else {
            $stats['failed']++;
        }
    }

    return $stats;
}

function ll_tools_get_user_word_progress_rows(int $user_id, array $word_ids): array {
    global $wpdb;
    if ($user_id <= 0 || empty($word_ids)) {
        return [];
    }

    $word_ids = array_values(array_unique(array_filter(array_map('intval', $word_ids), function ($id) {
        return $id > 0;
    })));
    if (empty($word_ids)) {
        return [];
    }

    $table = ll_tools_user_progress_table_names()['words'];
    $out = [];
    $chunks = array_chunk($word_ids, 200);
    foreach ($chunks as $chunk) {
        $placeholders = implode(', ', array_fill(0, count($chunk), '%d'));
        $sql = "SELECT * FROM {$table} WHERE user_id = %d AND word_id IN ({$placeholders})";
        $params = array_merge([$user_id], $chunk);
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        foreach ((array) $rows as $row) {
            $wid = isset($row['word_id']) ? (int) $row['word_id'] : 0;
            if ($wid > 0) {
                $out[$wid] = $row;
            }
        }
    }

    if (!empty($out)) {
        $required_types_map = ll_tools_get_word_practice_recording_types_map(array_keys($out));
        foreach ($out as $wid => $row) {
            $out[$wid]['practice_required_recording_types_json'] = (string) ($row['practice_required_recording_types'] ?? '');
            $out[$wid]['practice_correct_recording_types_json'] = (string) ($row['practice_correct_recording_types'] ?? '');
            $out[$wid]['practice_required_recording_types'] = ll_tools_decode_practice_recording_types($row['practice_required_recording_types'] ?? '');
            $out[$wid]['practice_correct_recording_types'] = ll_tools_decode_practice_recording_types($row['practice_correct_recording_types'] ?? '');
            $out[$wid]['practice_required_recording_types_resolved'] = $required_types_map[(int) $wid] ?? [];
            $out[$wid]['gender_state_json_raw'] = (string) ($row['gender_state_json'] ?? '');
            $out[$wid]['gender_progress'] = ll_tools_get_progress_row_gender_progress($row);
        }
    }

    return $out;
}

function ll_tools_user_progress_mastered_stage_threshold(): int {
    return max(1, min(6, (int) apply_filters('ll_tools_user_progress_mastered_stage_threshold', 5)));
}

function ll_tools_user_progress_mastered_clean_threshold(): int {
    return max(1, (int) apply_filters('ll_tools_user_progress_mastered_clean_threshold', 3));
}

function ll_tools_user_progress_hard_difficulty_threshold(): int {
    return max(1, (int) apply_filters('ll_tools_user_progress_hard_difficulty_threshold', 4));
}

function ll_tools_user_progress_streak_difficulty_relief(int $streak): int {
    $streak = max(0, $streak);
    if ($streak <= 0) {
        return 0;
    }

    $relief = intdiv($streak * ($streak + 1), 2);
    return max(0, (int) apply_filters('ll_tools_user_progress_streak_difficulty_relief', $relief, $streak));
}

function ll_tools_user_progress_row_meets_mastery_requirements(array $row): bool {
    if (!ll_tools_user_progress_word_is_studied($row)) {
        return false;
    }

    $stage_threshold = ll_tools_user_progress_mastered_stage_threshold();
    $stage = max(0, (int) ($row['stage'] ?? 0));
    if ($stage < $stage_threshold) {
        return false;
    }

    if (ll_tools_progress_row_has_practice_recording_tracking($row)) {
        $required_types = ll_tools_get_progress_row_practice_required_recording_types($row);
        $correct_types = ll_tools_get_progress_row_practice_correct_recording_types($row);
        if (!empty($required_types) && !empty(array_diff($required_types, $correct_types))) {
            return false;
        }
    }

    $clean_threshold = ll_tools_user_progress_mastered_clean_threshold();
    $clean = max(0, (int) ($row['correct_clean'] ?? 0));
    return $clean >= $clean_threshold;
}

function ll_tools_user_progress_row_has_mastery_unlock(array $row): bool {
    if (!empty($row['mastery_unlocked'])) {
        return true;
    }

    return ll_tools_user_progress_row_meets_mastery_requirements($row);
}

function ll_tools_user_progress_word_is_studied(array $row): bool {
    $coverage = max(0, (int) ($row['total_coverage'] ?? 0));
    $correct_clean = max(0, (int) ($row['correct_clean'] ?? 0));
    $correct_retry = max(0, (int) ($row['correct_after_retry'] ?? 0));
    $incorrect = max(0, (int) ($row['incorrect'] ?? 0));
    return ($coverage > 0) || ($correct_clean > 0) || ($correct_retry > 0) || ($incorrect > 0);
}

function ll_tools_user_progress_word_difficulty_score(array $row): int {
    if (!ll_tools_user_progress_word_is_studied($row)) {
        return -1000;
    }

    $incorrect = max(0, (int) ($row['incorrect'] ?? 0));
    $lapses = max(0, (int) ($row['lapse_count'] ?? 0));
    $clean = max(0, (int) ($row['correct_clean'] ?? 0));
    $retry = max(0, (int) ($row['correct_after_retry'] ?? 0));
    $stage = max(0, (int) ($row['stage'] ?? 0));
    $streak = max(0, (int) ($row['current_correct_streak'] ?? 0));

    return ($incorrect * 3)
        + ($lapses * 2)
        + max(0, 2 - $stage)
        - min(4, $clean)
        - min(2, $retry)
        - ll_tools_user_progress_streak_difficulty_relief($streak);
}

function ll_tools_user_progress_word_is_hard(array $row): bool {
    if (!ll_tools_user_progress_word_is_studied($row)) {
        return false;
    }

    return ll_tools_user_progress_word_difficulty_score($row) >= ll_tools_user_progress_hard_difficulty_threshold();
}

function ll_tools_user_progress_word_is_mastered(array $row): bool {
    if (!ll_tools_user_progress_word_is_studied($row)) {
        return false;
    }

    if (ll_tools_user_progress_word_is_hard($row)) {
        return false;
    }

    return ll_tools_user_progress_row_has_mastery_unlock($row);
}

function ll_tools_user_progress_word_status(array $row): string {
    if (ll_tools_user_progress_word_is_mastered($row)) {
        return 'mastered';
    }
    if (ll_tools_user_progress_word_is_studied($row)) {
        return 'studied';
    }
    return 'new';
}

function ll_tools_user_progress_category_ids_in_scope(array $categories_payload, array $requested_category_ids, array $goals, bool $include_ignored = false): array {
    $category_lookup = [];
    foreach ((array) $categories_payload as $cat) {
        if (!is_array($cat)) {
            continue;
        }
        $cid = isset($cat['id']) ? (int) $cat['id'] : 0;
        if ($cid > 0) {
            $category_lookup[$cid] = true;
        }
    }

    $ignored_lookup = [];
    foreach ((array) ($goals['ignored_category_ids'] ?? []) as $ignored_id) {
        $iid = (int) $ignored_id;
        if ($iid > 0) {
            $ignored_lookup[$iid] = true;
        }
    }

    $requested = array_values(array_filter(array_map('intval', (array) $requested_category_ids), function ($id) use ($category_lookup, $ignored_lookup, $include_ignored) {
        if ($id <= 0 || !isset($category_lookup[$id])) {
            return false;
        }
        if (!$include_ignored && !empty($ignored_lookup[$id])) {
            return false;
        }
        return true;
    }));

    if (!empty($requested)) {
        return $requested;
    }

    $all_available = array_values(array_filter(array_map('intval', array_keys($category_lookup)), function ($id) use ($ignored_lookup, $include_ignored) {
        if ($id <= 0) {
            return false;
        }
        if (!$include_ignored && !empty($ignored_lookup[$id])) {
            return false;
        }
        return true;
    }));
    return $all_available;
}

/**
 * The daily graph counts answered quiz rounds via `word_exposure` events.
 * `events` / `max_events` are retained as backward-compatible aliases.
 *
 * @return array{
 *   days:array<int,array{date:string,rounds:int,events:int,unique_words:int,outcomes:int}>,
 *   max_rounds:int,
 *   max_events:int,
 *   window_days:int
 * }
 */
function ll_tools_user_study_daily_activity_series(int $user_id, int $wordset_id, array $category_ids, int $days = 14): array {
    global $wpdb;

    if ($user_id <= 0) {
        return [
            'days' => [],
            'max_rounds' => 0,
            'max_events' => 0,
            'window_days' => 0,
        ];
    }

    $window_days = max(7, min(60, (int) $days));
    $start_ts = strtotime(gmdate('Y-m-d 00:00:00')) - (($window_days - 1) * DAY_IN_SECONDS);
    $start_mysql = gmdate('Y-m-d H:i:s', $start_ts);

    $tables = ll_tools_user_progress_table_names();
    $table = $tables['events'];

    $where_parts = [
        'user_id = %d',
        'created_at >= %s',
    ];
    $params = [$user_id, $start_mysql];

    if ($wordset_id > 0) {
        $where_parts[] = 'wordset_id = %d';
        $params[] = $wordset_id;
    }

    $scoped_categories = array_values(array_filter(array_map('intval', $category_ids), function ($id) {
        return $id > 0;
    }));
    if (!empty($scoped_categories)) {
        $placeholders = implode(', ', array_fill(0, count($scoped_categories), '%d'));
        $where_parts[] = "category_id IN ({$placeholders})";
        $params = array_merge($params, $scoped_categories);
    }

    $where_sql = implode(' AND ', $where_parts);
    $sql = "
        SELECT
            DATE(created_at) AS activity_date,
            SUM(CASE WHEN event_type = 'word_exposure' THEN 1 ELSE 0 END) AS rounds_count,
            COUNT(DISTINCT CASE WHEN event_type = 'word_exposure' AND word_id > 0 THEN word_id END) AS unique_words_count,
            SUM(CASE WHEN event_type = 'word_outcome' THEN 1 ELSE 0 END) AS outcomes_count
        FROM {$table}
        WHERE {$where_sql}
        GROUP BY DATE(created_at)
        ORDER BY activity_date ASC
    ";

    $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
    $by_date = [];
    foreach ((array) $rows as $row) {
        $date = isset($row['activity_date']) ? (string) $row['activity_date'] : '';
        if ($date === '') {
            continue;
        }
        $rounds = max(0, (int) ($row['rounds_count'] ?? 0));
        $by_date[$date] = [
            'rounds' => $rounds,
            'events' => $rounds,
            'unique_words' => max(0, (int) ($row['unique_words_count'] ?? 0)),
            'outcomes' => max(0, (int) ($row['outcomes_count'] ?? 0)),
        ];
    }

    $series = [];
    $max_rounds = 0;
    for ($offset = 0; $offset < $window_days; $offset++) {
        $day_ts = $start_ts + ($offset * DAY_IN_SECONDS);
        $date = gmdate('Y-m-d', $day_ts);
        $entry = $by_date[$date] ?? ['rounds' => 0, 'events' => 0, 'unique_words' => 0, 'outcomes' => 0];
        $rounds = max(0, (int) ($entry['rounds'] ?? ($entry['events'] ?? 0)));
        $max_rounds = max($max_rounds, $rounds);
        $series[] = [
            'date' => $date,
            'rounds' => $rounds,
            'events' => $rounds,
            'unique_words' => max(0, (int) ($entry['unique_words'] ?? 0)),
            'outcomes' => max(0, (int) ($entry['outcomes'] ?? 0)),
        ];
    }

    return [
        'days' => $series,
        'max_rounds' => $max_rounds,
        'max_events' => $max_rounds,
        'window_days' => $window_days,
    ];
}

/**
 * Aggregate category activity as completed mode sessions (category rounds), not word exposures.
 *
 * @return array<int,array{
 *   by_mode:array<string,int>,
 *   last_seen_at:string,
 *   last_mode:string
 * }>
 */
function ll_tools_user_study_category_mode_session_counts(int $user_id, int $wordset_id, array $category_ids = []): array {
    global $wpdb;

    if ($user_id <= 0) {
        return [];
    }

    $scope_lookup = [];
    foreach ((array) $category_ids as $cid) {
        $id = (int) $cid;
        if ($id > 0) {
            $scope_lookup[$id] = true;
        }
    }

    $tables = ll_tools_user_progress_table_names();
    $table = $tables['events'];

    $where_parts = [
        'user_id = %d',
        'event_type = %s',
    ];
    $params = [$user_id, 'mode_session_complete'];
    if ($wordset_id > 0) {
        $where_parts[] = 'wordset_id = %d';
        $params[] = $wordset_id;
    }

    $sql = "
        SELECT mode, category_id, payload_json, created_at
        FROM {$table}
        WHERE " . implode(' AND ', $where_parts) . '
        ORDER BY created_at ASC
    ';
    $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);

    $modes = ll_tools_progress_modes();
    $out = [];
    foreach ((array) $rows as $row) {
        $mode = ll_tools_normalize_progress_mode((string) ($row['mode'] ?? 'practice'));
        if ($mode === '') {
            $mode = 'practice';
        }

        $payload = [];
        $payload_json = isset($row['payload_json']) ? (string) $row['payload_json'] : '';
        if ($payload_json !== '') {
            $decoded = json_decode($payload_json, true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }

        $payload_categories = [];
        if (isset($payload['category_ids']) && is_array($payload['category_ids'])) {
            foreach ($payload['category_ids'] as $raw_cid) {
                $cid = (int) $raw_cid;
                if ($cid > 0) {
                    $payload_categories[] = $cid;
                }
            }
        }
        if (empty($payload_categories)) {
            $row_category_id = isset($row['category_id']) ? (int) $row['category_id'] : 0;
            if ($row_category_id > 0) {
                $payload_categories[] = $row_category_id;
            }
        }
        if (empty($payload_categories)) {
            continue;
        }

        $created_at = isset($row['created_at']) ? (string) $row['created_at'] : '';
        foreach ($payload_categories as $cid) {
            if (!empty($scope_lookup) && empty($scope_lookup[$cid])) {
                continue;
            }
            if (!isset($out[$cid]) || !is_array($out[$cid])) {
                $out[$cid] = [
                    'by_mode' => array_fill_keys($modes, 0),
                    'last_seen_at' => '',
                    'last_mode' => 'practice',
                ];
            }
            if (!isset($out[$cid]['by_mode']) || !is_array($out[$cid]['by_mode'])) {
                $out[$cid]['by_mode'] = array_fill_keys($modes, 0);
            }
            $out[$cid]['by_mode'][$mode] = max(0, (int) ($out[$cid]['by_mode'][$mode] ?? 0)) + 1;

            if ($created_at !== '' && (string) ($out[$cid]['last_seen_at'] ?? '') !== '' && strcmp($created_at, (string) $out[$cid]['last_seen_at']) <= 0) {
                continue;
            }
            if ($created_at !== '') {
                $out[$cid]['last_seen_at'] = $created_at;
            }
            $out[$cid]['last_mode'] = $mode;
        }
    }

    return $out;
}

/**
 * Pick the preferred audio entry for a progress row.
 *
 * @return array{url?:string,recording_type?:string}
 */
function ll_tools_user_progress_get_word_audio_entry(array $audio_files, int $preferred_speaker = 0, int $word_id = 0): array {
    $preferred_speaker = max(0, $preferred_speaker);
    if ($preferred_speaker <= 0 && function_exists('ll_tools_word_grid_get_preferred_speaker')) {
        $preferred_speaker = (int) ll_tools_word_grid_get_preferred_speaker($audio_files, ['isolation', 'question', 'introduction']);
    }

    $priority = ['isolation', 'question', 'introduction', 'sentence', 'in sentence'];
    if (function_exists('ll_tools_word_grid_select_audio_entry')) {
        foreach ($priority as $type) {
            $entry = (array) ll_tools_word_grid_select_audio_entry($audio_files, $type, $preferred_speaker);
            if (!empty($entry['url'])) {
                if (empty($entry['recording_type'])) {
                    $entry['recording_type'] = $type;
                }
                return $entry;
            }
        }
    }

    foreach ($audio_files as $file) {
        $file = (array) $file;
        if (!empty($file['url'])) {
            $recording_type = sanitize_key((string) ($file['recording_type'] ?? ''));
            if ($recording_type === '' || $recording_type === 'unknown') {
                $file['recording_type'] = 'isolation';
            } else {
                $file['recording_type'] = $recording_type;
            }
            return $file;
        }
    }

    if ($word_id > 0 && function_exists('ll_get_word_audio_url')) {
        $audio_url = (string) ll_get_word_audio_url($word_id);
        if ($audio_url !== '') {
            return [
                'url' => $audio_url,
                'recording_type' => 'isolation',
            ];
        }
    }

    return [];
}

function ll_tools_user_progress_empty_gender_analytics(bool $enabled = false): array {
    return [
        'enabled' => $enabled,
        'tracked_word_total' => 0,
        'not_started_words' => 0,
        'level_1_words' => 0,
        'level_2_words' => 0,
        'level_3_words' => 0,
        'categories' => [],
    ];
}

/**
 * Build analytics used by learner-facing study surfaces.
 *
 * @return array<string,mixed>
 */
function ll_tools_build_user_study_analytics_payload($user_id = 0, $wordset_id = 0, $category_ids = [], $days = 14, bool $include_ignored = false): array {
    $uid = (int) ($user_id ?: get_current_user_id());
    $scope_wordset_id = max(0, (int) $wordset_id);
    $gender_enabled = ($scope_wordset_id > 0 && function_exists('ll_tools_wordset_has_grammatical_gender'))
        ? ll_tools_wordset_has_grammatical_gender($scope_wordset_id)
        : false;
    if ($uid <= 0) {
        return [
            'scope' => [
                'wordset_id' => $scope_wordset_id,
                'category_ids' => [],
                'category_count' => 0,
                'mode' => 'all',
            ],
            'summary' => [
                'total_words' => 0,
                'mastered_words' => 0,
                'studied_words' => 0,
                'new_words' => 0,
                'hard_words' => 0,
                'starred_words' => 0,
            ],
            'daily_activity' => [
                'days' => [],
                'max_rounds' => 0,
                'max_events' => 0,
                'window_days' => 0,
            ],
            'gender_progress' => ll_tools_user_progress_empty_gender_analytics($gender_enabled),
            'categories' => [],
            'words' => [],
            'generated_at' => gmdate('c'),
        ];
    }

    $goals = ll_tools_get_user_study_goals($uid);
    $categories_payload = function_exists('ll_tools_user_study_categories_for_wordset')
        ? ll_tools_user_study_categories_for_wordset($scope_wordset_id)
        : [];

    $category_lookup = [];
    foreach ((array) $categories_payload as $cat) {
        if (!is_array($cat)) {
            continue;
        }
        $cid = isset($cat['id']) ? (int) $cat['id'] : 0;
        if ($cid <= 0) {
            continue;
        }
        $category_lookup[$cid] = $cat;
    }

    $requested_scope_category_ids = array_values(array_filter(array_map('intval', (array) $category_ids), function ($id) use ($category_lookup) {
        return $id > 0 && isset($category_lookup[$id]);
    }));

    $scope_category_ids = ll_tools_user_progress_category_ids_in_scope($categories_payload, (array) $category_ids, $goals, $include_ignored);
    $requested_scope_included = !empty(array_intersect($requested_scope_category_ids, $scope_category_ids));
    $scope_mode = $requested_scope_included ? 'selected' : 'all';

    $words_by_category = function_exists('ll_tools_user_study_words')
        ? ll_tools_user_study_words($scope_category_ids, $scope_wordset_id)
        : [];

    $word_map = [];
    $category_word_ids = [];

    foreach ($scope_category_ids as $cid) {
        $category_word_ids[$cid] = [];
        $rows = isset($words_by_category[$cid]) && is_array($words_by_category[$cid]) ? $words_by_category[$cid] : [];
        foreach ($rows as $word) {
            if (!is_array($word)) {
                continue;
            }
            $wid = isset($word['id']) ? (int) $word['id'] : 0;
            if ($wid <= 0) {
                continue;
            }
            $category_word_ids[$cid][$wid] = true;

            if (!isset($word_map[$wid])) {
                $title = isset($word['title']) ? (string) $word['title'] : '';
                $translation = isset($word['translation']) ? (string) $word['translation'] : '';
                $word_map[$wid] = [
                    'id' => $wid,
                    'title' => $title,
                    'translation' => $translation,
                    'label' => isset($word['label']) ? (string) $word['label'] : '',
                    'image' => isset($word['image']) ? (string) $word['image'] : '',
                    'audio_files' => isset($word['audio_files']) && is_array($word['audio_files']) ? array_values($word['audio_files']) : [],
                    'audio_files_count' => isset($word['audio_files']) && is_array($word['audio_files']) ? count($word['audio_files']) : 0,
                    'preferred_speaker_user_id' => isset($word['preferred_speaker_user_id']) ? (int) $word['preferred_speaker_user_id'] : 0,
                    'normalized_grammatical_gender' => isset($word['normalized_grammatical_gender']) ? (string) $word['normalized_grammatical_gender'] : '',
                    'gender_marked' => !empty($word['gender_marked']),
                    'gender_progress_tracked' => !empty($word['gender_progress_tracked']),
                    'gender_eligible' => !empty($word['gender_eligible']),
                    'category_ids' => [],
                ];
            }
            $word_map[$wid]['category_ids'][$cid] = true;
        }
    }

    $all_word_ids = array_values(array_filter(array_map('intval', array_keys($word_map)), function ($id) {
        return $id > 0;
    }));
    $progress_rows = ll_tools_get_user_word_progress_rows($uid, $all_word_ids);
    $category_progress = ll_tools_get_user_category_progress($uid);

    $study_state = function_exists('ll_tools_get_user_study_state') ? ll_tools_get_user_study_state($uid) : [];
    $starred_lookup = [];
    foreach ((array) ($study_state['starred_word_ids'] ?? []) as $starred_id) {
        $sid = (int) $starred_id;
        if ($sid > 0) {
            $starred_lookup[$sid] = true;
        }
    }

    $word_rows = [];
    $summary_total = 0;
    $summary_mastered = 0;
    $summary_studied = 0;
    $summary_hard = 0;
    $summary_starred = 0;
    $category_studied_lookup = [];
    $category_mastered_lookup = [];
    $category_last_word_seen = [];
    $category_last_word_mode = [];
    $category_last_word_mode_seen = [];
    $category_mode_floor = [];
    $progress_modes = ll_tools_progress_modes();
    $gender_progress = ll_tools_user_progress_empty_gender_analytics($gender_enabled);
    $gender_category_totals = [];
    $gender_category_last_seen = [];

    foreach ($word_map as $wid => $word) {
        $progress = isset($progress_rows[$wid]) && is_array($progress_rows[$wid]) ? $progress_rows[$wid] : [];
        $gender_state = ll_tools_get_progress_row_gender_progress($progress);
        $status = ll_tools_user_progress_word_status($progress);
        $difficulty = ll_tools_user_progress_word_difficulty_score($progress);
        $coverage = max(0, (int) ($progress['total_coverage'] ?? 0));
        $stage = max(0, (int) ($progress['stage'] ?? 0));
        $correct_clean = max(0, (int) ($progress['correct_clean'] ?? 0));
        $correct_retry = max(0, (int) ($progress['correct_after_retry'] ?? 0));
        $incorrect = max(0, (int) ($progress['incorrect'] ?? 0));
        $lapses = max(0, (int) ($progress['lapse_count'] ?? 0));
        $total_outcomes = $correct_clean + $correct_retry + $incorrect;
        $accuracy = ($total_outcomes > 0)
            ? round((($correct_clean + $correct_retry) / $total_outcomes) * 100, 1)
            : null;
        $last_seen_at = isset($progress['last_seen_at']) ? (string) $progress['last_seen_at'] : '';
        $last_mode = isset($progress['last_mode']) ? ll_tools_normalize_progress_mode((string) $progress['last_mode']) : '';
        $due_at = isset($progress['due_at']) ? (string) $progress['due_at'] : '';
        $word_mode_coverage = [];
        foreach ($progress_modes as $mode_key) {
            $mode_column = ll_tools_progress_mode_column($mode_key);
            $word_mode_coverage[$mode_key] = max(0, (int) ($progress[$mode_column] ?? 0));
        }

        $tracks_gender_progress = !empty($word['gender_progress_tracked']);
        $gender_level = 0;
        if ($tracks_gender_progress) {
            $gender_progress['tracked_word_total']++;
            if (empty($gender_state)) {
                $gender_progress['not_started_words']++;
            } else {
                $gender_level = ll_tools_user_progress_normalize_gender_level($gender_state['level'] ?? 1);
                if ($gender_level === 1) {
                    $gender_progress['level_1_words']++;
                } elseif ($gender_level === 2) {
                    $gender_progress['level_2_words']++;
                } else {
                    $gender_progress['level_3_words']++;
                }
            }
        }

        $word_category_ids = array_values(array_filter(array_map('intval', array_keys((array) ($word['category_ids'] ?? []))), function ($id) {
            return $id > 0;
        }));
        sort($word_category_ids, SORT_NUMERIC);
        $audio_entry = ll_tools_user_progress_get_word_audio_entry(
            isset($word['audio_files']) && is_array($word['audio_files']) ? $word['audio_files'] : [],
            (int) ($word['preferred_speaker_user_id'] ?? 0),
            (int) $wid
        );

        $word_category_labels = [];
        foreach ($word_category_ids as $cid) {
            $meta = $category_lookup[$cid] ?? [];
            $label = '';
            if (is_array($meta)) {
                $label = isset($meta['translation']) && (string) $meta['translation'] !== ''
                    ? (string) $meta['translation']
                    : (string) ($meta['name'] ?? '');
            }
            if ($label === '') {
                $label = (string) ll_tools_get_category_display_name($cid);
            }
            $word_category_labels[] = $label;

            if ($status !== 'new') {
                if (!isset($category_studied_lookup[$cid])) {
                    $category_studied_lookup[$cid] = [];
                }
                $category_studied_lookup[$cid][$wid] = true;
            }
            if ($status === 'mastered') {
                if (!isset($category_mastered_lookup[$cid])) {
                    $category_mastered_lookup[$cid] = [];
                }
                $category_mastered_lookup[$cid][$wid] = true;
            }
            if ($last_seen_at !== '') {
                if (!isset($category_last_word_seen[$cid]) || strcmp($last_seen_at, (string) $category_last_word_seen[$cid]) > 0) {
                    $category_last_word_seen[$cid] = $last_seen_at;
                }
                if (!isset($category_last_word_mode_seen[$cid]) || strcmp($last_seen_at, (string) $category_last_word_mode_seen[$cid]) > 0) {
                    $category_last_word_mode_seen[$cid] = $last_seen_at;
                    $category_last_word_mode[$cid] = $last_mode !== '' ? $last_mode : 'practice';
                }
            }
            if (!isset($category_mode_floor[$cid]) || !is_array($category_mode_floor[$cid])) {
                $category_mode_floor[$cid] = array_fill_keys($progress_modes, null);
            }
            foreach ($progress_modes as $mode_key) {
                $word_count_for_mode = max(0, (int) ($word_mode_coverage[$mode_key] ?? 0));
                $existing_floor = $category_mode_floor[$cid][$mode_key];
                if ($existing_floor === null || $word_count_for_mode < (int) $existing_floor) {
                    $category_mode_floor[$cid][$mode_key] = $word_count_for_mode;
                }
            }

            if ($tracks_gender_progress) {
                if (!isset($gender_category_totals[$cid]) || !is_array($gender_category_totals[$cid])) {
                    $gender_category_totals[$cid] = [
                        'tracked_word_total' => 0,
                        'not_started_words' => 0,
                        'level_1_words' => 0,
                        'level_2_words' => 0,
                        'level_3_words' => 0,
                    ];
                }

                $gender_category_totals[$cid]['tracked_word_total']++;
                if (empty($gender_state)) {
                    $gender_category_totals[$cid]['not_started_words']++;
                } elseif ($gender_level === 1) {
                    $gender_category_totals[$cid]['level_1_words']++;
                } elseif ($gender_level === 2) {
                    $gender_category_totals[$cid]['level_2_words']++;
                } else {
                    $gender_category_totals[$cid]['level_3_words']++;
                }

                $gender_last_seen_at = !empty($gender_state['last_seen_at']) ? (string) $gender_state['last_seen_at'] : '';
                if ($gender_last_seen_at !== '' && (!isset($gender_category_last_seen[$cid]) || strcmp($gender_last_seen_at, (string) $gender_category_last_seen[$cid]) > 0)) {
                    $gender_category_last_seen[$cid] = $gender_last_seen_at;
                }
            }
        }

        $word_rows[] = [
            'id' => (int) $wid,
            'title' => (string) ($word['title'] ?? ''),
            'translation' => (string) ($word['translation'] ?? ''),
            'label' => (string) ($word['label'] ?? ''),
            'image' => (string) ($word['image'] ?? ''),
            'audio_url' => (string) ($audio_entry['url'] ?? ''),
            'audio_recording_type' => (string) ($audio_entry['recording_type'] ?? ''),
            'audio_files_count' => max(0, (int) ($word['audio_files_count'] ?? 0)),
            'category_ids' => $word_category_ids,
            'category_labels' => $word_category_labels,
            'category_id' => !empty($word_category_ids) ? (int) $word_category_ids[0] : 0,
            'category_label' => !empty($word_category_labels) ? (string) $word_category_labels[0] : '',
            'status' => $status,
            'difficulty_score' => $difficulty,
            'total_coverage' => $coverage,
            'stage' => $stage,
            'correct_clean' => $correct_clean,
            'correct_after_retry' => $correct_retry,
            'incorrect' => $incorrect,
            'lapse_count' => $lapses,
            'accuracy_percent' => $accuracy,
            'last_mode' => $last_mode,
            'last_seen_at' => $last_seen_at,
            'due_at' => $due_at,
            'is_starred' => !empty($starred_lookup[$wid]),
            'normalized_grammatical_gender' => (string) ($word['normalized_grammatical_gender'] ?? ''),
            'gender_marked' => !empty($word['gender_marked']),
            'gender_progress_tracked' => !empty($word['gender_progress_tracked']),
            'gender_eligible' => !empty($word['gender_eligible']),
            'gender_level' => $tracks_gender_progress ? $gender_level : 0,
            'gender_seen_total' => !empty($gender_state) ? max(0, (int) ($gender_state['seen_total'] ?? 0)) : 0,
            'gender_last_seen_at' => !empty($gender_state['last_seen_at']) ? (string) $gender_state['last_seen_at'] : '',
            'gender_progress' => $tracks_gender_progress ? $gender_state : [],
        ];

        $summary_total++;
        if ($status !== 'new') {
            $summary_studied++;
        }
        if ($status === 'mastered') {
            $summary_mastered++;
        }
        if (ll_tools_user_progress_word_is_hard($progress)) {
            $summary_hard++;
        }
        if (!empty($starred_lookup[$wid])) {
            $summary_starred++;
        }
    }

    usort($word_rows, function ($left, $right) {
        $left_status = isset($left['status']) ? (string) $left['status'] : 'new';
        $right_status = isset($right['status']) ? (string) $right['status'] : 'new';
        if ($left_status !== $right_status) {
            if ($left_status === 'new') {
                return 1;
            }
            if ($right_status === 'new') {
                return -1;
            }
        }

        $left_score = (int) ($left['difficulty_score'] ?? 0);
        $right_score = (int) ($right['difficulty_score'] ?? 0);
        if ($left_score !== $right_score) {
            return $right_score <=> $left_score;
        }

        $left_incorrect = (int) ($left['incorrect'] ?? 0);
        $right_incorrect = (int) ($right['incorrect'] ?? 0);
        if ($left_incorrect !== $right_incorrect) {
            return $right_incorrect <=> $left_incorrect;
        }

        if (function_exists('ll_tools_locale_compare_strings')) {
            return ll_tools_locale_compare_strings((string) ($left['title'] ?? ''), (string) ($right['title'] ?? ''));
        }
        return strcasecmp((string) ($left['title'] ?? ''), (string) ($right['title'] ?? ''));
    });

    $category_rows = [];
    foreach ($scope_category_ids as $cid) {
        $cat_meta = isset($category_lookup[$cid]) && is_array($category_lookup[$cid]) ? $category_lookup[$cid] : [];
        $cat_label = isset($cat_meta['translation']) && (string) $cat_meta['translation'] !== ''
            ? (string) $cat_meta['translation']
            : (string) ($cat_meta['name'] ?? '');
        if ($cat_label === '') {
            $cat_label = (string) ll_tools_get_category_display_name($cid);
        }

        $cat_word_total = isset($category_word_ids[$cid]) && is_array($category_word_ids[$cid]) ? count($category_word_ids[$cid]) : 0;
        $cat_studied = isset($category_studied_lookup[$cid]) && is_array($category_studied_lookup[$cid]) ? count($category_studied_lookup[$cid]) : 0;
        $cat_mastered = isset($category_mastered_lookup[$cid]) && is_array($category_mastered_lookup[$cid]) ? count($category_mastered_lookup[$cid]) : 0;
        $cat_progress = isset($category_progress[$cid]) && is_array($category_progress[$cid]) ? $category_progress[$cid] : [];
        $exposure_by_mode_raw = isset($category_mode_floor[$cid]) && is_array($category_mode_floor[$cid])
            ? $category_mode_floor[$cid]
            : [];
        $exposure_by_mode = [];
        foreach ($progress_modes as $mode) {
            $exposure_by_mode[$mode] = max(0, (int) ($exposure_by_mode_raw[$mode] ?? 0));
        }
        $last_seen_at = isset($cat_progress['last_seen_at']) ? (string) $cat_progress['last_seen_at'] : '';
        if (!empty($category_last_word_seen[$cid]) && ($last_seen_at === '' || strcmp((string) $category_last_word_seen[$cid], $last_seen_at) > 0)) {
            $last_seen_at = (string) $category_last_word_seen[$cid];
        }

        $exposure_total = array_sum($exposure_by_mode);
        $last_mode = isset($category_last_word_mode[$cid]) ? ll_tools_normalize_progress_mode((string) $category_last_word_mode[$cid]) : '';
        if ($last_mode === '') {
            $last_mode = isset($cat_progress['last_mode']) ? ll_tools_normalize_progress_mode((string) $cat_progress['last_mode']) : '';
        }
        if ($last_mode === '') {
            $last_mode = 'practice';
        }

        $category_rows[] = [
            'id' => $cid,
            'label' => $cat_label,
            'word_count' => $cat_word_total,
            'studied_words' => $cat_studied,
            'mastered_words' => $cat_mastered,
            'new_words' => max(0, $cat_word_total - $cat_studied),
            'exposure_total' => $exposure_total,
            'exposure_by_mode' => $exposure_by_mode,
            'last_mode' => $last_mode,
            'last_seen_at' => $last_seen_at,
            'gender_progress' => [
                'tracked_word_total' => max(0, (int) ($gender_category_totals[$cid]['tracked_word_total'] ?? 0)),
                'not_started_words' => max(0, (int) ($gender_category_totals[$cid]['not_started_words'] ?? 0)),
                'level_1_words' => max(0, (int) ($gender_category_totals[$cid]['level_1_words'] ?? 0)),
                'level_2_words' => max(0, (int) ($gender_category_totals[$cid]['level_2_words'] ?? 0)),
                'level_3_words' => max(0, (int) ($gender_category_totals[$cid]['level_3_words'] ?? 0)),
                'last_seen_at' => isset($gender_category_last_seen[$cid]) ? (string) $gender_category_last_seen[$cid] : '',
            ],
        ];
    }

    usort($category_rows, function ($left, $right) {
        if (function_exists('ll_tools_locale_compare_strings')) {
            return ll_tools_locale_compare_strings((string) ($left['label'] ?? ''), (string) ($right['label'] ?? ''));
        }
        return strcasecmp((string) ($left['label'] ?? ''), (string) ($right['label'] ?? ''));
    });

    foreach ($scope_category_ids as $cid) {
        $counts = isset($gender_category_totals[$cid]) && is_array($gender_category_totals[$cid])
            ? $gender_category_totals[$cid]
            : [];
        $tracked_total = max(0, (int) ($counts['tracked_word_total'] ?? 0));
        if ($tracked_total <= 0) {
            continue;
        }

        $cat_meta = isset($category_lookup[$cid]) && is_array($category_lookup[$cid]) ? $category_lookup[$cid] : [];
        $cat_label = isset($cat_meta['translation']) && (string) $cat_meta['translation'] !== ''
            ? (string) $cat_meta['translation']
            : (string) ($cat_meta['name'] ?? '');
        if ($cat_label === '') {
            $cat_label = (string) ll_tools_get_category_display_name($cid);
        }

        $gender_progress['categories'][] = [
            'id' => $cid,
            'label' => $cat_label,
            'tracked_word_total' => $tracked_total,
            'not_started_words' => max(0, (int) ($counts['not_started_words'] ?? 0)),
            'level_1_words' => max(0, (int) ($counts['level_1_words'] ?? 0)),
            'level_2_words' => max(0, (int) ($counts['level_2_words'] ?? 0)),
            'level_3_words' => max(0, (int) ($counts['level_3_words'] ?? 0)),
            'last_gender_seen_at' => isset($gender_category_last_seen[$cid]) ? (string) $gender_category_last_seen[$cid] : '',
        ];
    }

    if (!empty($gender_progress['categories'])) {
        usort($gender_progress['categories'], static function ($left, $right): int {
            if (function_exists('ll_tools_locale_compare_strings')) {
                return ll_tools_locale_compare_strings((string) ($left['label'] ?? ''), (string) ($right['label'] ?? ''));
            }
            return strcasecmp((string) ($left['label'] ?? ''), (string) ($right['label'] ?? ''));
        });
    }

    $daily_activity = ll_tools_user_study_daily_activity_series($uid, $scope_wordset_id, $scope_category_ids, (int) $days);

    return [
        'scope' => [
            'wordset_id' => $scope_wordset_id,
            'category_ids' => $scope_category_ids,
            'category_count' => count($scope_category_ids),
            'mode' => $scope_mode,
        ],
        'summary' => [
            'total_words' => $summary_total,
            'mastered_words' => $summary_mastered,
            'studied_words' => $summary_studied,
            'new_words' => max(0, $summary_total - $summary_studied),
            'hard_words' => $summary_hard,
            'starred_words' => $summary_starred,
        ],
        'daily_activity' => $daily_activity,
        'gender_progress' => $gender_progress,
        'categories' => $category_rows,
        'words' => $word_rows,
        'generated_at' => gmdate('c'),
    ];
}

function ll_tools_recommendation_ordered_modes(array $enabled_modes = []): array {
    $preferred_order = ['learning', 'practice', 'listening', 'gender', 'self-check'];
    $enabled = array_values(array_unique(array_filter(array_map('ll_tools_normalize_progress_mode', $enabled_modes))));
    if (empty($enabled)) {
        $enabled = ll_tools_progress_modes();
    }
    $enabled_lookup = array_flip($enabled);
    $ordered = [];
    foreach ($preferred_order as $mode) {
        if (isset($enabled_lookup[$mode])) {
            $ordered[] = $mode;
        }
    }
    foreach ($enabled as $mode) {
        if (!in_array($mode, $ordered, true)) {
            $ordered[] = $mode;
        }
    }
    return $ordered;
}

function ll_tools_recommendation_activity_queue_id(array $activity): string {
    $mode = ll_tools_normalize_progress_mode((string) ($activity['mode'] ?? 'practice'));
    if ($mode === '') {
        $mode = 'practice';
    }
    $category_ids = array_values(array_unique(array_filter(array_map('intval', (array) ($activity['category_ids'] ?? [])), function ($id) {
        return $id > 0;
    })));
    sort($category_ids, SORT_NUMERIC);
    $session_word_ids = array_values(array_unique(array_filter(array_map('intval', (array) ($activity['session_word_ids'] ?? [])), function ($id) {
        return $id > 0;
    })));
    sort($session_word_ids, SORT_NUMERIC);

    return md5(wp_json_encode([
        'mode' => $mode,
        'category_ids' => $category_ids,
        'session_word_ids' => $session_word_ids,
    ]));
}

function ll_tools_recommendation_activity_category_ids(array $activity): array {
    $category_ids = array_values(array_unique(array_filter(array_map('intval', (array) ($activity['category_ids'] ?? [])), function ($id) {
        return $id > 0;
    })));
    sort($category_ids, SORT_NUMERIC);
    return $category_ids;
}

/**
 * Consecutive conflict levels:
 * 0: no category overlap
 * 1: category overlap with different mode
 * 2: category overlap with same mode
 */
function ll_tools_recommendation_consecutive_conflict_level(array $previous, array $candidate): int {
    $previous_ids = ll_tools_recommendation_activity_category_ids($previous);
    $candidate_ids = ll_tools_recommendation_activity_category_ids($candidate);
    if (empty($previous_ids) || empty($candidate_ids)) {
        return 0;
    }

    $previous_lookup = array_fill_keys($previous_ids, true);
    $has_overlap = false;
    foreach ($candidate_ids as $category_id) {
        if (!empty($previous_lookup[$category_id])) {
            $has_overlap = true;
            break;
        }
    }
    if (!$has_overlap) {
        return 0;
    }

    $allow_overlap_repeat = false;
    if (isset($candidate['details']) && is_array($candidate['details']) && !empty($candidate['details']['allow_consecutive_same_category'])) {
        $allow_overlap_repeat = true;
    } elseif (isset($previous['details']) && is_array($previous['details']) && !empty($previous['details']['allow_consecutive_same_category'])) {
        $allow_overlap_repeat = true;
    }
    if ($allow_overlap_repeat) {
        return 0;
    }

    $previous_mode = ll_tools_normalize_progress_mode((string) ($previous['mode'] ?? 'practice'));
    if ($previous_mode === '') {
        $previous_mode = 'practice';
    }
    $candidate_mode = ll_tools_normalize_progress_mode((string) ($candidate['mode'] ?? 'practice'));
    if ($candidate_mode === '') {
        $candidate_mode = 'practice';
    }

    return ($previous_mode === $candidate_mode) ? 2 : 1;
}

function ll_tools_recommendation_queue_promote_non_overlapping_head(array $queue, array $previous_activity, string $preferred_mode = ''): array {
    $max_items = max(1, min(16, (int) count($queue)));
    $normalized = ll_tools_normalize_recommendation_queue($queue, $max_items);
    if (empty($normalized)) {
        return [];
    }

    $preferred_mode = strtolower(trim($preferred_mode));
    if ($preferred_mode === 'self_check') {
        $preferred_mode = 'self-check';
    }
    if (!in_array($preferred_mode, ll_tools_progress_modes(), true)) {
        $preferred_mode = '';
    }

    $current_candidate = ll_tools_recommendation_queue_pick_next($normalized, $preferred_mode);
    if (is_array($current_candidate) && ll_tools_recommendation_consecutive_conflict_level($previous_activity, $current_candidate) === 0) {
        return $normalized;
    }

    $candidate_index = null;
    $count = count($normalized);
    for ($idx = 0; $idx < $count; $idx++) {
        $candidate = $normalized[$idx];
        if (!is_array($candidate)) {
            continue;
        }
        $candidate_mode = ll_tools_normalize_progress_mode((string) ($candidate['mode'] ?? 'practice'));
        if ($preferred_mode !== '' && $candidate_mode !== $preferred_mode) {
            continue;
        }
        if (ll_tools_recommendation_consecutive_conflict_level($previous_activity, $candidate) === 0) {
            $candidate_index = $idx;
            break;
        }
    }
    if ($candidate_index === null) {
        return $normalized;
    }

    $candidate = $normalized[$candidate_index];
    array_splice($normalized, $candidate_index, 1);
    array_unshift($normalized, $candidate);
    return $normalized;
}

function ll_tools_normalize_recommendation_activity($raw): ?array {
    if (!is_array($raw)) {
        return null;
    }
    $mode = ll_tools_normalize_progress_mode((string) ($raw['mode'] ?? ''));
    if ($mode === '') {
        $mode = 'practice';
    }
    $category_ids = array_values(array_unique(array_filter(array_map('intval', (array) ($raw['category_ids'] ?? [])), function ($id) {
        return $id > 0;
    })));
    $session_word_ids_raw = array_values(array_unique(array_filter(array_map('intval', (array) ($raw['session_word_ids'] ?? [])), function ($id) {
        return $id > 0;
    })));
    $session_word_ids = function_exists('ll_tools_recommendation_enforce_session_word_bounds')
        ? ll_tools_recommendation_enforce_session_word_bounds($session_word_ids_raw)
        : $session_word_ids_raw;
    if (empty($session_word_ids)) {
        return null;
    }
    $activity = [
        'type' => sanitize_key((string) ($raw['type'] ?? 'review_chunk')),
        'reason_code' => sanitize_key((string) ($raw['reason_code'] ?? 'recommended')),
        'mode' => $mode,
        'category_ids' => $category_ids,
        'session_word_ids' => $session_word_ids,
        'details' => isset($raw['details']) && is_array($raw['details']) ? $raw['details'] : [],
    ];
    $queue_id = sanitize_key((string) ($raw['queue_id'] ?? ''));
    if ($queue_id === '') {
        $queue_id = ll_tools_recommendation_activity_queue_id($activity);
    }
    $activity['queue_id'] = $queue_id;
    return $activity;
}

function ll_tools_normalize_recommendation_queue(array $raw_queue, int $limit = 8): array {
    $out = [];
    $seen = [];
    $max_items = max(1, min(16, (int) $limit));
    foreach ($raw_queue as $raw_activity) {
        $activity = ll_tools_normalize_recommendation_activity($raw_activity);
        if (!$activity) {
            continue;
        }
        $queue_id = (string) ($activity['queue_id'] ?? '');
        if ($queue_id === '' || !empty($seen[$queue_id])) {
            continue;
        }
        $seen[$queue_id] = true;
        $out[] = $activity;
        if (count($out) >= $max_items) {
            break;
        }
    }
    return $out;
}

function ll_tools_recommendation_queue_ensure_length(array $queue, int $limit = 8): array {
    $max_items = max(1, min(16, (int) $limit));
    $normalized = ll_tools_normalize_recommendation_queue($queue, $max_items);
    if (empty($normalized)) {
        return [];
    }
    if (count($normalized) >= $max_items) {
        return array_slice($normalized, 0, $max_items);
    }

    $base = array_values($normalized);
    $base_count = count($base);
    $seen_ids = [];
    foreach ($normalized as $item) {
        if (!is_array($item)) {
            continue;
        }
        $queue_id = sanitize_key((string) ($item['queue_id'] ?? ''));
        if ($queue_id !== '') {
            $seen_ids[$queue_id] = true;
        }
    }

    $seed_index = 0;
    $max_attempts = $max_items * 24;
    while (count($normalized) < $max_items && $seed_index < $max_attempts) {
        $last_item = null;
        $last_index = count($normalized) - 1;
        if ($last_index >= 0 && isset($normalized[$last_index]) && is_array($normalized[$last_index])) {
            $last_item = $normalized[$last_index];
        }

        $best_source = null;
        $best_source_index = null;
        $best_conflict = 3;
        for ($offset = 0; $offset < $base_count; $offset++) {
            $candidate_index = ($seed_index + $offset) % $base_count;
            $candidate_source = $base[$candidate_index];
            if (!is_array($candidate_source)) {
                continue;
            }
            $conflict_level = ($last_item && is_array($last_item))
                ? ll_tools_recommendation_consecutive_conflict_level($last_item, $candidate_source)
                : 0;
            if ($best_source === null || $conflict_level < $best_conflict) {
                $best_source = $candidate_source;
                $best_source_index = $candidate_index;
                $best_conflict = $conflict_level;
                if ($conflict_level === 0) {
                    break;
                }
            }
        }

        if (!is_array($best_source) || $best_source_index === null) {
            $seed_index++;
            continue;
        }

        $seed_index = ($best_source_index + 1) % $base_count;
        $source = $best_source;
        if (!is_array($source)) {
            continue;
        }

        $item = $source;
        $base_id = sanitize_key((string) ($item['queue_id'] ?? 'queue'));
        if ($base_id === '') {
            $base_id = 'queue';
        }

        $suffix = 1;
        $candidate = sanitize_key($base_id . '-dup-' . $suffix);
        while ($candidate === '' || !empty($seen_ids[$candidate])) {
            $suffix++;
            $candidate = sanitize_key($base_id . '-dup-' . $suffix);
            if ($suffix > 200) {
                break;
            }
        }
        if ($candidate === '' || !empty($seen_ids[$candidate])) {
            continue;
        }

        $item['queue_id'] = $candidate;
        $seen_ids[$candidate] = true;
        $normalized[] = $item;
    }

    return array_slice($normalized, 0, $max_items);
}

function ll_tools_normalize_recommendation_signature_list(array $raw, int $limit = 64): array {
    $max_items = max(1, min(128, (int) $limit));
    $out = [];
    $seen = [];
    foreach ($raw as $value) {
        $signature = sanitize_key((string) $value);
        if ($signature === '' || isset($seen[$signature])) {
            continue;
        }
        $seen[$signature] = true;
        $out[] = $signature;
        if (count($out) >= $max_items) {
            break;
        }
    }
    return $out;
}

function ll_tools_get_user_recommendation_dismissed_signatures($user_id = 0, $wordset_id = 0): array {
    $uid = (int) ($user_id ?: get_current_user_id());
    $wordset_id = (int) $wordset_id;
    if ($uid <= 0 || $wordset_id <= 0) {
        return [];
    }

    $raw = get_user_meta($uid, LL_TOOLS_USER_RECOMMENDATION_DISMISSED_META, true);
    if (!is_array($raw)) {
        return [];
    }
    $list = isset($raw[(string) $wordset_id]) && is_array($raw[(string) $wordset_id])
        ? $raw[(string) $wordset_id]
        : [];
    return ll_tools_normalize_recommendation_signature_list($list, 64);
}

function ll_tools_save_user_recommendation_dismissed_signatures(array $signatures, $user_id = 0, $wordset_id = 0): array {
    $uid = (int) ($user_id ?: get_current_user_id());
    $wordset_id = (int) $wordset_id;
    if ($uid <= 0 || $wordset_id <= 0) {
        return [];
    }

    $raw = get_user_meta($uid, LL_TOOLS_USER_RECOMMENDATION_DISMISSED_META, true);
    if (!is_array($raw)) {
        $raw = [];
    }

    $normalized = ll_tools_normalize_recommendation_signature_list($signatures, 64);
    if (empty($normalized)) {
        unset($raw[(string) $wordset_id]);
    } else {
        $raw[(string) $wordset_id] = $normalized;
    }

    if (empty($raw)) {
        delete_user_meta($uid, LL_TOOLS_USER_RECOMMENDATION_DISMISSED_META);
    } else {
        update_user_meta($uid, LL_TOOLS_USER_RECOMMENDATION_DISMISSED_META, $raw);
    }

    return $normalized;
}

function ll_tools_add_user_recommendation_dismissed_signature(string $signature, $user_id = 0, $wordset_id = 0): array {
    $uid = (int) ($user_id ?: get_current_user_id());
    $wordset_id = (int) $wordset_id;
    $signature = sanitize_key($signature);
    if ($uid <= 0 || $wordset_id <= 0 || $signature === '') {
        return ll_tools_get_user_recommendation_dismissed_signatures($uid, $wordset_id);
    }

    $current = ll_tools_get_user_recommendation_dismissed_signatures($uid, $wordset_id);
    array_unshift($current, $signature);
    return ll_tools_save_user_recommendation_dismissed_signatures($current, $uid, $wordset_id);
}

function ll_tools_recommendation_deferral_delay_seconds(int $dismiss_count): int {
    $dismiss_count = max(1, (int) $dismiss_count);
    $schedule = [
        DAY_IN_SECONDS,
        WEEK_IN_SECONDS,
        14 * DAY_IN_SECONDS,
        30 * DAY_IN_SECONDS,
        90 * DAY_IN_SECONDS,
    ];
    $idx = min(count($schedule) - 1, $dismiss_count - 1);
    return (int) ($schedule[$idx] ?? WEEK_IN_SECONDS);
}

function ll_tools_normalize_recommendation_deferral_entry(string $signature, $raw_entry, int $now_ts = 0): ?array {
    $signature = sanitize_key($signature);
    if ($signature === '') {
        return null;
    }

    $now_ts = ($now_ts > 0) ? $now_ts : time();
    $raw = is_array($raw_entry) ? $raw_entry : [];
    $count = max(1, (int) ($raw['count'] ?? 1));

    $available_after = '';
    if (!empty($raw['available_after']) && is_string($raw['available_after'])) {
        $available_after = (string) $raw['available_after'];
    } elseif (!empty($raw['available_at']) && is_string($raw['available_at'])) {
        $available_after = (string) $raw['available_at'];
    }
    $available_after_ts = $available_after !== '' ? strtotime($available_after . ' UTC') : false;
    if ($available_after_ts === false || $available_after_ts <= 0) {
        $available_after_ts = $now_ts + ll_tools_recommendation_deferral_delay_seconds($count);
    }

    $last_dismissed_at = '';
    if (!empty($raw['last_dismissed_at']) && is_string($raw['last_dismissed_at'])) {
        $last_dismissed_at = (string) $raw['last_dismissed_at'];
    } else {
        $last_dismissed_at = gmdate('Y-m-d H:i:s', max(0, $available_after_ts - ll_tools_recommendation_deferral_delay_seconds($count)));
    }

    $category_ids = [];
    if (isset($raw['category_ids']) && is_array($raw['category_ids'])) {
        $category_ids = array_values(array_unique(array_filter(array_map('intval', $raw['category_ids']), function ($id) {
            return $id > 0;
        })));
        sort($category_ids, SORT_NUMERIC);
    }

    $mode = ll_tools_normalize_progress_mode((string) ($raw['mode'] ?? 'practice'));
    $type = sanitize_key((string) ($raw['type'] ?? ''));

    return [
        'count' => $count,
        'available_after' => gmdate('Y-m-d H:i:s', (int) $available_after_ts),
        'last_dismissed_at' => $last_dismissed_at,
        'category_ids' => $category_ids,
        'mode' => $mode,
        'type' => $type,
    ];
}

function ll_tools_normalize_recommendation_deferral_map($raw_map, int $limit = 256): array {
    $max_items = max(1, min(512, (int) $limit));
    if (!is_array($raw_map)) {
        return [];
    }

    $now_ts = time();
    $out = [];
    foreach ($raw_map as $raw_signature => $raw_entry) {
        $signature = sanitize_key((string) $raw_signature);
        if ($signature === '' || isset($out[$signature])) {
            continue;
        }
        $normalized = ll_tools_normalize_recommendation_deferral_entry($signature, $raw_entry, $now_ts);
        if (!$normalized) {
            continue;
        }
        $out[$signature] = $normalized;
        if (count($out) >= $max_items) {
            break;
        }
    }

    return $out;
}

function ll_tools_get_user_recommendation_deferrals($user_id = 0, $wordset_id = 0): array {
    $uid = (int) ($user_id ?: get_current_user_id());
    $wordset_id = (int) $wordset_id;
    if ($uid <= 0 || $wordset_id <= 0) {
        return [];
    }

    $raw = get_user_meta($uid, LL_TOOLS_USER_RECOMMENDATION_DEFERRALS_META, true);
    if (!is_array($raw)) {
        return [];
    }

    $bucket = isset($raw[(string) $wordset_id]) ? $raw[(string) $wordset_id] : [];
    return ll_tools_normalize_recommendation_deferral_map($bucket);
}

function ll_tools_save_user_recommendation_deferrals(array $deferrals, $user_id = 0, $wordset_id = 0): array {
    $uid = (int) ($user_id ?: get_current_user_id());
    $wordset_id = (int) $wordset_id;
    if ($uid <= 0 || $wordset_id <= 0) {
        return [];
    }

    $raw = get_user_meta($uid, LL_TOOLS_USER_RECOMMENDATION_DEFERRALS_META, true);
    if (!is_array($raw)) {
        $raw = [];
    }

    $normalized = ll_tools_normalize_recommendation_deferral_map($deferrals);
    if (empty($normalized)) {
        unset($raw[(string) $wordset_id]);
    } else {
        $raw[(string) $wordset_id] = $normalized;
    }

    if (empty($raw)) {
        delete_user_meta($uid, LL_TOOLS_USER_RECOMMENDATION_DEFERRALS_META);
    } else {
        update_user_meta($uid, LL_TOOLS_USER_RECOMMENDATION_DEFERRALS_META, $raw);
    }

    return $normalized;
}

function ll_tools_get_user_active_recommendation_deferred_signatures($user_id = 0, $wordset_id = 0): array {
    $deferrals = ll_tools_get_user_recommendation_deferrals($user_id, $wordset_id);
    if (empty($deferrals)) {
        return [];
    }

    $now_mysql = gmdate('Y-m-d H:i:s');
    $active = [];
    foreach ($deferrals as $signature => $entry) {
        $available_after = (string) ($entry['available_after'] ?? '');
        if ($available_after === '' || strcmp($available_after, $now_mysql) > 0) {
            $active[] = sanitize_key((string) $signature);
        }
    }

    return ll_tools_normalize_recommendation_signature_list($active, 128);
}

function ll_tools_defer_user_recommendation_activity($activity, $user_id = 0, $wordset_id = 0): array {
    $uid = (int) ($user_id ?: get_current_user_id());
    $wordset_id = (int) $wordset_id;
    $normalized_activity = ll_tools_normalize_recommendation_activity($activity);
    if ($uid <= 0 || $wordset_id <= 0 || !$normalized_activity) {
        return ll_tools_get_user_recommendation_deferrals($uid, $wordset_id);
    }

    $signature = ll_tools_recommendation_activity_queue_id($normalized_activity);
    if ($signature === '') {
        return ll_tools_get_user_recommendation_deferrals($uid, $wordset_id);
    }

    $deferrals = ll_tools_get_user_recommendation_deferrals($uid, $wordset_id);
    $current = isset($deferrals[$signature]) && is_array($deferrals[$signature]) ? $deferrals[$signature] : [];
    $next_count = max(0, (int) ($current['count'] ?? 0)) + 1;
    $now_ts = time();

    $deferrals[$signature] = [
        'count' => $next_count,
        'available_after' => gmdate('Y-m-d H:i:s', $now_ts + ll_tools_recommendation_deferral_delay_seconds($next_count)),
        'last_dismissed_at' => gmdate('Y-m-d H:i:s', $now_ts),
        'category_ids' => ll_tools_recommendation_activity_category_ids($normalized_activity),
        'mode' => ll_tools_normalize_progress_mode((string) ($normalized_activity['mode'] ?? 'practice')),
        'type' => sanitize_key((string) ($normalized_activity['type'] ?? '')),
    ];

    return ll_tools_save_user_recommendation_deferrals($deferrals, $uid, $wordset_id);
}

function ll_tools_get_user_recommendation_queue($user_id = 0, $wordset_id = 0): array {
    $uid = (int) ($user_id ?: get_current_user_id());
    $wordset_id = (int) $wordset_id;
    if ($uid <= 0 || $wordset_id <= 0) {
        return [];
    }
    $raw = get_user_meta($uid, LL_TOOLS_USER_RECOMMENDATION_QUEUE_META, true);
    if (!is_array($raw)) {
        return [];
    }
    $queue_raw = isset($raw[(string) $wordset_id]) && is_array($raw[(string) $wordset_id])
        ? $raw[(string) $wordset_id]
        : [];
    $queue = ll_tools_normalize_recommendation_queue($queue_raw, 8);
    return ll_tools_recommendation_queue_ensure_length($queue, 8);
}

function ll_tools_save_user_recommendation_queue(array $queue, $user_id = 0, $wordset_id = 0): array {
    $uid = (int) ($user_id ?: get_current_user_id());
    $wordset_id = (int) $wordset_id;
    if ($uid <= 0 || $wordset_id <= 0) {
        return [];
    }
    $raw = get_user_meta($uid, LL_TOOLS_USER_RECOMMENDATION_QUEUE_META, true);
    if (!is_array($raw)) {
        $raw = [];
    }
    $normalized = ll_tools_normalize_recommendation_queue($queue);
    $raw[(string) $wordset_id] = $normalized;
    update_user_meta($uid, LL_TOOLS_USER_RECOMMENDATION_QUEUE_META, $raw);
    return $normalized;
}

function ll_tools_get_user_last_recommendation_activity($user_id = 0, $wordset_id = 0): ?array {
    $uid = (int) ($user_id ?: get_current_user_id());
    $wordset_id = (int) $wordset_id;
    if ($uid <= 0 || $wordset_id <= 0) {
        return null;
    }

    $raw = get_user_meta($uid, LL_TOOLS_USER_LAST_RECOMMENDATION_META, true);
    if (!is_array($raw)) {
        return null;
    }

    $entry = isset($raw[(string) $wordset_id]) ? $raw[(string) $wordset_id] : null;
    $normalized = ll_tools_normalize_recommendation_activity($entry);
    return $normalized ?: null;
}

function ll_tools_save_user_last_recommendation_activity($activity, $user_id = 0, $wordset_id = 0): ?array {
    $uid = (int) ($user_id ?: get_current_user_id());
    $wordset_id = (int) $wordset_id;
    if ($uid <= 0 || $wordset_id <= 0) {
        return null;
    }

    $raw = get_user_meta($uid, LL_TOOLS_USER_LAST_RECOMMENDATION_META, true);
    if (!is_array($raw)) {
        $raw = [];
    }

    $normalized = ll_tools_normalize_recommendation_activity($activity);
    if ($normalized) {
        $raw[(string) $wordset_id] = $normalized;
    } else {
        unset($raw[(string) $wordset_id]);
    }

    if (empty($raw)) {
        delete_user_meta($uid, LL_TOOLS_USER_LAST_RECOMMENDATION_META);
    } else {
        update_user_meta($uid, LL_TOOLS_USER_LAST_RECOMMENDATION_META, $raw);
    }

    return $normalized ?: null;
}

function ll_tools_delete_user_recommendation_queue_item(string $queue_id, $user_id = 0, $wordset_id = 0): array {
    $uid = (int) ($user_id ?: get_current_user_id());
    $wordset_id = (int) $wordset_id;
    $queue_id = sanitize_key($queue_id);
    if ($uid <= 0 || $wordset_id <= 0 || $queue_id === '') {
        return ll_tools_get_user_recommendation_queue($uid, $wordset_id);
    }
    $queue = ll_tools_get_user_recommendation_queue($uid, $wordset_id);
    $queue = array_values(array_filter($queue, function ($activity) use ($queue_id) {
        $current_id = is_array($activity) ? (string) ($activity['queue_id'] ?? '') : '';
        return $current_id !== $queue_id;
    }));
    return ll_tools_save_user_recommendation_queue($queue, $uid, $wordset_id);
}

function ll_tools_recommendation_queue_defer_activity_signature(array $queue, string $signature): array {
    $signature = sanitize_key($signature);
    $max_items = max(1, min(16, (int) count($queue)));
    $normalized = ll_tools_normalize_recommendation_queue($queue, $max_items);
    if ($signature === '' || empty($normalized)) {
        return $normalized;
    }

    $head = [];
    $tail = [];
    foreach ($normalized as $activity) {
        if (!is_array($activity)) {
            continue;
        }
        $activity_signature = ll_tools_recommendation_activity_queue_id($activity);
        if ($activity_signature === $signature) {
            $tail[] = $activity;
        } else {
            $head[] = $activity;
        }
    }

    return array_values(array_merge($head, $tail));
}

function ll_tools_user_study_priority_flags(array $goals): array {
    $focus_key = ll_tools_normalize_user_study_priority_focus($goals['priority_focus'] ?? '');
    if ($focus_key === '') {
        if (!empty($goals['prioritize_new_words'])) {
            $focus_key = 'new';
        } elseif (!empty($goals['prioritize_studied_words'])) {
            $focus_key = 'studied';
        } elseif (!empty($goals['prioritize_learned_words'])) {
            $focus_key = 'learned';
        } elseif (!empty($goals['prefer_starred_words'])) {
            $focus_key = 'starred';
        } elseif (!empty($goals['prefer_hard_words'])) {
            $focus_key = 'hard';
        }
    }
    $focus_new = ($focus_key === 'new');
    $focus_studied = ($focus_key === 'studied');
    $focus_learned = ($focus_key === 'learned');
    $focus_starred = ($focus_key === 'starred');
    $focus_hard = ($focus_key === 'hard');
    return [
        'focus' => $focus_key,
        'new' => $focus_new,
        'studied' => $focus_studied,
        'learned' => $focus_learned,
        'any_focus' => $focus_key !== '',
        'prefer_starred' => $focus_starred,
        'prefer_hard' => $focus_hard,
    ];
}

function ll_tools_user_study_word_matches_priority_focus(int $word_id, ?array $progress_row, string $focus, array $starred_lookup = []): bool {
    $focus = ll_tools_normalize_user_study_priority_focus($focus);
    if ($focus === '') {
        return false;
    }

    $row = is_array($progress_row) ? $progress_row : [];
    $status = !empty($row) ? ll_tools_user_progress_word_status($row) : 'new';

    if ($focus === 'new') {
        return $status === 'new';
    }
    if ($focus === 'studied') {
        return $status === 'studied';
    }
    if ($focus === 'learned') {
        return $status === 'mastered';
    }
    if ($focus === 'starred') {
        return !empty($starred_lookup[$word_id]);
    }
    if ($focus === 'hard') {
        if (empty($row) || $status === 'new') {
            return false;
        }
        return ll_tools_user_progress_word_is_hard($row);
    }
    return false;
}

function ll_tools_user_study_word_priority_score(int $word_id, ?array $progress_row, array $goals, array $starred_lookup = []): array {
    $word_id = (int) $word_id;
    $row = is_array($progress_row) ? $progress_row : [];
    $flags = ll_tools_user_study_priority_flags($goals);

    $status = 'new';
    if (!empty($row)) {
        $status = ll_tools_user_progress_word_status($row);
    }
    $is_new = ($status === 'new');
    $is_studied = ($status === 'studied');
    $is_mastered = ($status === 'mastered');

    $score = 0;
    if (empty($flags['any_focus'])) {
        $score += 8;
    } else {
        if ($is_new) {
            $score += !empty($flags['new']) ? 22 : 8;
        } elseif ($is_studied) {
            $score += !empty($flags['studied']) ? 20 : 8;
        } elseif ($is_mastered) {
            $score += !empty($flags['learned']) ? 17 : 7;
        }
    }

    $incorrect = max(0, (int) ($row['incorrect'] ?? 0));
    $lapses = max(0, (int) ($row['lapse_count'] ?? 0));
    $correct_clean = max(0, (int) ($row['correct_clean'] ?? 0));
    $stage = max(0, (int) ($row['stage'] ?? 0));
    $due_at_raw = isset($row['due_at']) ? (string) $row['due_at'] : '';
    $due_ts = $due_at_raw !== '' ? strtotime($due_at_raw . ' UTC') : false;
    $is_due = ($due_ts !== false) && ($due_ts <= time());
    $is_weak = !$is_new && ($is_due || $incorrect > $correct_clean || $stage <= 1);

    if ($is_due) {
        $score += 12;
    }
    if ($is_weak) {
        $score += 9;
    }
    if (!$is_new) {
        $score += max(0, 2 - $stage);
        $score += min(10, ($incorrect * 2) + $lapses);
    }

    if (!empty($flags['prefer_starred']) && !empty($starred_lookup[$word_id])) {
        $score += 10;
    }

    if (!empty($flags['prefer_hard']) && !$is_new && ll_tools_user_progress_word_is_hard($row)) {
        $difficulty = max(0, ll_tools_user_progress_word_difficulty_score($row));
        $score += min(10, $difficulty);
    }

    return [
        'score' => (int) $score,
        'status' => $status,
        'is_new' => $is_new,
        'is_due' => $is_due,
        'is_weak' => $is_weak,
    ];
}

function ll_tools_recommendation_rotate_category_ids(array $category_ids, int $user_id = 0, int $wordset_id = 0, array $args = []): array {
    $normalized = array_values(array_unique(array_filter(array_map('intval', $category_ids), function ($id) {
        return $id > 0;
    })));
    if (count($normalized) < 2) {
        return $normalized;
    }

    $ordering_mode = function_exists('ll_tools_wordset_get_category_ordering_mode')
        ? ll_tools_wordset_get_category_ordering_mode($wordset_id)
        : 'none';
    if ($ordering_mode !== 'none') {
        return $normalized;
    }

    $anchor_category_ids = [];
    if (isset($args['anchor_category_ids']) && is_array($args['anchor_category_ids'])) {
        $anchor_category_ids = array_values(array_unique(array_filter(array_map('intval', $args['anchor_category_ids']), function ($id) {
            return $id > 0;
        })));
    }
    if (empty($anchor_category_ids) && $user_id > 0 && $wordset_id > 0) {
        $last_activity = ll_tools_get_user_last_recommendation_activity($user_id, $wordset_id);
        if (is_array($last_activity)) {
            $anchor_category_ids = ll_tools_recommendation_activity_category_ids($last_activity);
        }
    }

    $rotation_start = null;
    if (!empty($anchor_category_ids)) {
        $index_lookup = array_flip($normalized);
        $anchor_index = null;
        foreach ($anchor_category_ids as $anchor_category_id) {
            if (!isset($index_lookup[$anchor_category_id])) {
                continue;
            }
            $candidate_index = (int) $index_lookup[$anchor_category_id];
            if ($anchor_index === null || $candidate_index > $anchor_index) {
                $anchor_index = $candidate_index;
            }
        }
        if ($anchor_index !== null) {
            $rotation_start = ($anchor_index + 1) % count($normalized);
        }
    }

    if ($rotation_start === null) {
        $rotation_seed = $user_id . ':' . $wordset_id . ':' . implode(',', $normalized);
        $rotation_start = (int) (sprintf('%u', crc32($rotation_seed)) % count($normalized));
    }

    if ($rotation_start <= 0) {
        return $normalized;
    }

    return array_merge(
        array_slice($normalized, $rotation_start),
        array_slice($normalized, 0, $rotation_start)
    );
}

function ll_tools_recommendation_category_rank_lookup(array $category_ids): array {
    $normalized = array_values(array_unique(array_filter(array_map('intval', $category_ids), function ($id) {
        return $id > 0;
    })));
    $out = [];
    foreach ($normalized as $index => $category_id) {
        $out[(int) $category_id] = (int) $index;
    }
    return $out;
}

function ll_tools_pick_recommendation_mode(array $enabled_modes, array $category_ids, array $category_lookup): string {
    $ordered_modes = ll_tools_recommendation_ordered_modes($enabled_modes);
    foreach ($ordered_modes as $mode) {
        if ($mode !== 'gender') {
            return $mode;
        }
        foreach ($category_ids as $cid) {
            if (!empty($category_lookup[$cid]['gender_supported'])) {
                return 'gender';
            }
        }
    }
    return 'practice';
}

function ll_tools_category_pipeline_sequence(array $category_meta): array {
    $sequence = ['learning', 'listening', 'practice'];
    if (!empty($category_meta['gender_supported'])) {
        $sequence[] = 'gender';
    }
    $sequence[] = 'self-check';
    return $sequence;
}

function ll_tools_recommendation_session_word_bounds(): array {
    $quiz_min = (int) apply_filters('ll_tools_quiz_min_words', LL_TOOLS_MIN_WORDS_PER_QUIZ);
    $default_min = max(5, $quiz_min);
    $min = (int) apply_filters('ll_tools_recommendation_min_words', $default_min);
    $max = (int) apply_filters('ll_tools_recommendation_max_words', 15);

    $min = max(5, min(30, $min));
    $max = max($min, min(30, $max));

    return [$min, $max];
}

function ll_tools_recommendation_enforce_session_word_bounds(array $session_word_ids, array $primary_pool_ids = [], array $fallback_pool_ids = []): array {
    [$min_words, $max_words] = ll_tools_recommendation_session_word_bounds();

    $normalized = [];
    $seen = [];
    $append_ids = static function (array $ids, int $limit) use (&$normalized, &$seen): void {
        foreach ($ids as $raw_id) {
            $id = (int) $raw_id;
            if ($id <= 0 || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $normalized[] = $id;
            if (count($normalized) >= $limit) {
                break;
            }
        }
    };

    $append_ids($session_word_ids, $max_words);
    if (count($normalized) < $min_words) {
        $append_ids($primary_pool_ids, $min_words);
    }
    if (count($normalized) < $min_words) {
        $append_ids($fallback_pool_ids, $min_words);
    }
    if (count($normalized) < $min_words) {
        return [];
    }
    if (count($normalized) > $max_words) {
        $normalized = array_slice($normalized, 0, $max_words);
    }

    return $normalized;
}

function ll_tools_recommendation_chunk_size_for_pool(int $pool_size, int $preferred = 12): int {
    [$min_words, $max_words] = ll_tools_recommendation_session_word_bounds();

    $pool = max(0, (int) $pool_size);
    if ($pool <= 0) {
        return 0;
    }

    if ($pool <= $max_words) {
        return $pool;
    }

    $target = max($min_words, min($max_words, (int) $preferred));
    if ($pool >= 60) {
        $target = $max_words;
    } elseif ($pool >= 40) {
        $target = min($max_words, max($min_words, 13));
    } elseif ($pool >= 24) {
        $target = min($max_words, max($min_words, 12));
    } else {
        $target = min($max_words, max($min_words, 10));
    }

    return max($min_words, min($max_words, min($pool, $target)));
}

/**
 * Pick a review chunk using progress + user priority settings.
 *
 * @return array{
 *   rows:array<int,array<string,mixed>>,
 *   word_ids:array<int>,
 *   counts:array{weak:int,new:int,due:int,score_total:int,priority_match:int}
 * }
 */
function ll_tools_select_review_chunk_rows(array $all_word_ids, array $progress_rows, array $goals, int $chunk_size = 12, array $starred_lookup = [], array $context = []): array {
    [, $max_words] = ll_tools_recommendation_session_word_bounds();

    $all_word_ids = array_values(array_unique(array_filter(array_map('intval', $all_word_ids), function ($id) {
        return $id > 0;
    })));

    $size = max(1, min($max_words, (int) $chunk_size));
    if (empty($all_word_ids)) {
        return [
            'rows' => [],
            'word_ids' => [],
            'counts' => ['weak' => 0, 'new' => 0, 'due' => 0, 'score_total' => 0, 'priority_match' => 0],
        ];
    }

    $priority_flags = ll_tools_user_study_priority_flags($goals);
    $priority_focus = (string) ($priority_flags['focus'] ?? '');
    $word_category_lookup = isset($context['word_category_lookup']) && is_array($context['word_category_lookup'])
        ? $context['word_category_lookup']
        : [];
    $category_rank_lookup = isset($context['category_rank_lookup']) && is_array($context['category_rank_lookup'])
        ? $context['category_rank_lookup']
        : [];
    $category_rank_lookup = array_combine(
        array_map('intval', array_keys($category_rank_lookup)),
        array_map('intval', array_values($category_rank_lookup))
    );
    if (!is_array($category_rank_lookup)) {
        $category_rank_lookup = [];
    }
    if (empty($category_rank_lookup) && !empty($word_category_lookup)) {
        $derived_category_ids = [];
        foreach ($all_word_ids as $word_id_for_rank) {
            $derived_category_id = isset($word_category_lookup[$word_id_for_rank]) ? (int) $word_category_lookup[$word_id_for_rank] : 0;
            if ($derived_category_id <= 0 || isset($derived_category_ids[$derived_category_id])) {
                continue;
            }
            $derived_category_ids[$derived_category_id] = true;
        }
        $category_rank_lookup = ll_tools_recommendation_category_rank_lookup(array_keys($derived_category_ids));
    }
    $category_group_count = max(1, count($category_rank_lookup));
    $category_sequence_lookup = [];
    $entries = [];
    foreach ($all_word_ids as $wid) {
        $progress = isset($progress_rows[$wid]) && is_array($progress_rows[$wid]) ? $progress_rows[$wid] : null;
        $priority = ll_tools_user_study_word_priority_score($wid, $progress, $goals, $starred_lookup);
        $total_coverage = max(0, (int) ($progress['total_coverage'] ?? 0));
        $is_priority_match = ll_tools_user_study_word_matches_priority_focus($wid, $progress, $priority_focus, $starred_lookup);
        $weight = $is_priority_match ? 2 : 1;
        $normalized_coverage = $weight > 0 ? ($total_coverage / $weight) : (float) $total_coverage;
        $category_id = isset($word_category_lookup[$wid]) ? (int) $word_category_lookup[$wid] : 0;
        $category_rank = ($category_id > 0 && isset($category_rank_lookup[$category_id]))
            ? (int) $category_rank_lookup[$category_id]
            : $category_group_count;
        $category_position = 0;
        if ($category_id > 0) {
            $category_position = (int) ($category_sequence_lookup[$category_id] ?? 0);
            $category_sequence_lookup[$category_id] = $category_position + 1;
        }
        $entry = [
            'word_id' => $wid,
            'score' => (int) ($priority['score'] ?? 0),
            'status' => (string) ($priority['status'] ?? 'new'),
            'is_due' => !empty($priority['is_due']),
            'is_weak' => !empty($priority['is_weak']),
            'is_new' => !empty($priority['is_new']),
            'is_priority_match' => $is_priority_match,
            'total_coverage' => $total_coverage,
            'normalized_coverage' => $normalized_coverage,
            'category_id' => $category_id,
            'category_rotation_slot' => ($category_id > 0)
                ? (($category_position * $category_group_count) + $category_rank)
                : PHP_INT_MAX,
        ];
        $entries[] = $entry;
    }

    $score_sort = static function ($a, $b): int {
        $score_compare = ((int) ($b['score'] ?? 0)) <=> ((int) ($a['score'] ?? 0));
        if ($score_compare !== 0) {
            return $score_compare;
        }
        if (!empty($a['is_due']) !== !empty($b['is_due'])) {
            return !empty($a['is_due']) ? -1 : 1;
        }
        if (!empty($a['is_weak']) !== !empty($b['is_weak'])) {
            return !empty($a['is_weak']) ? -1 : 1;
        }
        $coverage_compare = ((int) ($a['total_coverage'] ?? 0)) <=> ((int) ($b['total_coverage'] ?? 0));
        if ($coverage_compare !== 0) {
            return $coverage_compare;
        }
        $rotation_compare = ((int) ($a['category_rotation_slot'] ?? PHP_INT_MAX)) <=> ((int) ($b['category_rotation_slot'] ?? PHP_INT_MAX));
        if ($rotation_compare !== 0) {
            return $rotation_compare;
        }
        return ((int) ($a['word_id'] ?? 0)) <=> ((int) ($b['word_id'] ?? 0));
    };

    $coverage_sort = static function ($a, $b) use ($score_sort): int {
        $left = (float) ($a['normalized_coverage'] ?? 0.0);
        $right = (float) ($b['normalized_coverage'] ?? 0.0);
        if ($left < $right) {
            return -1;
        }
        if ($left > $right) {
            return 1;
        }
        return $score_sort($a, $b);
    };

    $selected_rows = [];
    if ($priority_focus !== '') {
        $matched = [];
        $others = [];
        foreach ($entries as $entry) {
            if (!empty($entry['is_priority_match'])) {
                $matched[] = $entry;
            } else {
                $others[] = $entry;
            }
        }

        usort($matched, $coverage_sort);
        usort($others, $coverage_sort);

        $match_target = 0;
        if (!empty($matched)) {
            if (!empty($others)) {
                $match_count = count($matched);
                $other_count = count($others);
                $weighted_total = ($match_count * 2) + $other_count;
                if ($weighted_total > 0) {
                    $match_share = ($match_count * 2) / $weighted_total;
                    $match_target = (int) round($size * $match_share);
                }
                if ($match_target <= 0) {
                    $match_target = 1;
                }
            } else {
                $match_target = $size;
            }
            $match_target = min($match_target, count($matched), $size);
        }
        $other_target = min($size - $match_target, count($others));

        if ($match_target > 0) {
            $selected_rows = array_merge($selected_rows, array_slice($matched, 0, $match_target));
        }
        if ($other_target > 0) {
            $selected_rows = array_merge($selected_rows, array_slice($others, 0, $other_target));
        }

        if (count($selected_rows) < $size) {
            $remainder = array_merge(array_slice($matched, $match_target), array_slice($others, $other_target));
            usort($remainder, $coverage_sort);
            if (!empty($remainder)) {
                $selected_rows = array_merge($selected_rows, array_slice($remainder, 0, $size - count($selected_rows)));
            }
        }

        usort($selected_rows, $score_sort);
    } else {
        usort($entries, $score_sort);
        $selected_rows = array_slice($entries, 0, $size);
    }

    $word_ids = array_values(array_map(function ($row) {
        return (int) $row['word_id'];
    }, $selected_rows));

    $counts = ['weak' => 0, 'new' => 0, 'due' => 0, 'score_total' => 0, 'priority_match' => 0];
    foreach ($selected_rows as $row) {
        if (!empty($row['is_weak'])) {
            $counts['weak']++;
        }
        if (!empty($row['is_new'])) {
            $counts['new']++;
        }
        if (!empty($row['is_due'])) {
            $counts['due']++;
        }
        if (!empty($row['is_priority_match'])) {
            $counts['priority_match']++;
        }
        $counts['score_total'] += max(0, (int) ($row['score'] ?? 0));
    }

    return [
        'rows' => $selected_rows,
        'word_ids' => $word_ids,
        'counts' => $counts,
    ];
}

function ll_tools_recommendation_category_completion_map(int $user_id, int $wordset_id, array $category_ids): array {
    static $cache = [];

    $category_ids = array_values(array_unique(array_filter(array_map('intval', $category_ids), function ($id) {
        return $id > 0;
    })));
    if ($user_id <= 0 || $wordset_id <= 0 || empty($category_ids) || !function_exists('ll_tools_user_study_words')) {
        return [];
    }

    $cache_key = $user_id . ':' . $wordset_id . ':' . md5((string) wp_json_encode($category_ids));
    if (isset($cache[$cache_key]) && is_array($cache[$cache_key])) {
        return $cache[$cache_key];
    }

    $words_by_category = ll_tools_user_study_words($category_ids, $wordset_id);
    $out = [];
    foreach ($category_ids as $cid) {
        $rows = isset($words_by_category[$cid]) && is_array($words_by_category[$cid]) ? $words_by_category[$cid] : [];
        $total_words = 0;
        $studied_words = 0;
        foreach ($rows as $word) {
            if (!is_array($word)) {
                continue;
            }
            $wid = isset($word['id']) ? (int) $word['id'] : 0;
            if ($wid <= 0) {
                continue;
            }
            $total_words++;
            $coverage = max(0, (int) ($word['progress_total_coverage'] ?? 0));
            $correct_clean = max(0, (int) ($word['progress_correct_clean'] ?? 0));
            $correct_retry = max(0, (int) ($word['progress_correct_after_retry'] ?? 0));
            $incorrect = max(0, (int) ($word['progress_incorrect'] ?? 0));
            if ($coverage > 0 || $correct_clean > 0 || $correct_retry > 0 || $incorrect > 0) {
                $studied_words++;
            }
        }

        $out[$cid] = [
            'total_words' => $total_words,
            'studied_words' => $studied_words,
            'unstudied_words' => max(0, $total_words - $studied_words),
            'is_fully_studied' => ($total_words > 0 && $studied_words >= $total_words),
            'has_any_studied' => ($studied_words > 0),
        ];
    }

    $cache[$cache_key] = $out;
    return $out;
}

function ll_tools_recommendation_build_logical_order_plan(int $user_id, int $wordset_id, array $selected_category_ids, array $categories_payload = [], array $context = []): array {
    $selected_category_ids = array_values(array_unique(array_filter(array_map('intval', $selected_category_ids), function ($id) {
        return $id > 0;
    })));

    $ordering_mode = function_exists('ll_tools_wordset_get_category_ordering_mode')
        ? ll_tools_wordset_get_category_ordering_mode($wordset_id)
        : 'none';
    if (!in_array($ordering_mode, ['manual', 'prerequisite'], true) || count($selected_category_ids) < 1) {
        return [
            'enabled' => false,
            'mode' => 'none',
            'attempts' => [],
        ];
    }

    $ordered_category_ids = $selected_category_ids;
    if (count($ordered_category_ids) > 1 && function_exists('ll_tools_wordset_sort_category_ids')) {
        $ordered_category_ids = ll_tools_wordset_sort_category_ids($ordered_category_ids, $wordset_id, [
            'categories_payload' => $categories_payload,
        ]);
    }

    $completion_map = ll_tools_recommendation_category_completion_map($user_id, $wordset_id, $ordered_category_ids);
    if (empty($completion_map)) {
        return [
            'enabled' => false,
            'mode' => $ordering_mode,
            'attempts' => [],
        ];
    }

    $selected_lookup = array_fill_keys($ordered_category_ids, true);
    $fully_studied_lookup = [];
    $review_categories = [];
    $unstudied_categories = [];
    foreach ($ordered_category_ids as $cid) {
        $status = isset($completion_map[$cid]) && is_array($completion_map[$cid]) ? $completion_map[$cid] : [];
        $is_fully_studied = !empty($status['is_fully_studied']);
        if ($is_fully_studied) {
            $fully_studied_lookup[$cid] = true;
            $review_categories[] = $cid;
        } else {
            $unstudied_categories[] = $cid;
        }
    }

    if (empty($review_categories) && empty($unstudied_categories)) {
        return [
            'enabled' => false,
            'mode' => $ordering_mode,
            'attempts' => [],
        ];
    }

    $frontier_new_categories = [];
    $frontier_lookup = [];

    if ($ordering_mode === 'prerequisite') {
        $prereq_map = function_exists('ll_tools_wordset_get_category_prereq_map')
            ? ll_tools_wordset_get_category_prereq_map($wordset_id, $ordered_category_ids)
            : [];

        foreach ($ordered_category_ids as $cid) {
            if (empty($selected_lookup[$cid]) || empty($completion_map[$cid]) || !empty($completion_map[$cid]['is_fully_studied'])) {
                continue;
            }
            $deps = isset($prereq_map[$cid]) && is_array($prereq_map[$cid]) ? $prereq_map[$cid] : [];
            $deps_satisfied = true;
            foreach ($deps as $dep_id) {
                $dep_id = (int) $dep_id;
                if ($dep_id <= 0 || empty($selected_lookup[$dep_id])) {
                    continue;
                }
                if (empty($fully_studied_lookup[$dep_id])) {
                    $deps_satisfied = false;
                    break;
                }
            }
            if ($deps_satisfied) {
                $frontier_new_categories[] = $cid;
                $frontier_lookup[$cid] = true;
            }
        }

        if (empty($frontier_new_categories) && !empty($unstudied_categories)) {
            $level_lookup = [];
            foreach ((array) $categories_payload as $cat) {
                if (!is_array($cat)) {
                    continue;
                }
                $cid = isset($cat['id']) ? (int) $cat['id'] : 0;
                if ($cid > 0 && array_key_exists('logical_order_level', $cat)) {
                    $level_lookup[$cid] = max(0, (int) $cat['logical_order_level']);
                }
            }
            $min_level = null;
            foreach ($unstudied_categories as $cid) {
                $level = (int) ($level_lookup[$cid] ?? 0);
                if ($min_level === null || $level < $min_level) {
                    $min_level = $level;
                }
            }
            foreach ($unstudied_categories as $cid) {
                $level = (int) ($level_lookup[$cid] ?? 0);
                if ($min_level === null || $level === $min_level) {
                    $frontier_new_categories[] = $cid;
                    $frontier_lookup[$cid] = true;
                }
            }
        }
    } else {
        $first_unstudied_added = false;
        foreach ($ordered_category_ids as $idx => $cid) {
            if (!empty($completion_map[$cid]['is_fully_studied'])) {
                continue;
            }
            if (!$first_unstudied_added) {
                $frontier_new_categories[] = $cid;
                $frontier_lookup[$cid] = true;
                $first_unstudied_added = true;
            }
            $prev_cid = ($idx > 0 && isset($ordered_category_ids[$idx - 1])) ? (int) $ordered_category_ids[$idx - 1] : 0;
            if ($prev_cid > 0 && !empty($fully_studied_lookup[$prev_cid]) && empty($frontier_lookup[$cid])) {
                $frontier_new_categories[] = $cid;
                $frontier_lookup[$cid] = true;
            }
        }
    }

    $remaining_new_categories = [];
    foreach ($unstudied_categories as $cid) {
        if (empty($frontier_lookup[$cid])) {
            $remaining_new_categories[] = $cid;
        }
    }

    $last_lane = sanitize_key((string) ($context['previous_lane'] ?? ''));
    if ($last_lane === '') {
        $last_recommendation = ll_tools_get_user_last_recommendation_activity($user_id, $wordset_id);
        if (is_array($last_recommendation) && isset($last_recommendation['details']) && is_array($last_recommendation['details'])) {
            $last_lane = sanitize_key((string) ($last_recommendation['details']['logical_lane'] ?? ''));
        }
    }
    if ($last_lane === 'newexpanded') {
        $last_lane = 'new';
    } elseif ($last_lane === 'new-expanded') {
        $last_lane = 'new';
    }
    if (!in_array($last_lane, ['new', 'review'], true)) {
        $last_lane = '';
    }

    $has_new = !empty($frontier_new_categories) || !empty($remaining_new_categories);
    $has_review = !empty($review_categories);
    $preferred_lane = '';
    if ($has_new && $has_review) {
        $preferred_lane = ($last_lane === 'new') ? 'review' : 'new';
    } elseif ($has_new) {
        $preferred_lane = 'new';
    } elseif ($has_review) {
        $preferred_lane = 'review';
    }

    $attempts = [];
    $seen_attempts = [];
    $append_attempt = static function (array $ids, string $lane, bool $allow_consecutive = false) use (&$attempts, &$seen_attempts): void {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), function ($id) {
            return $id > 0;
        })));
        if (empty($ids)) {
            return;
        }
        $key = $lane . ':' . implode(',', $ids) . ':' . ($allow_consecutive ? '1' : '0');
        if (isset($seen_attempts[$key])) {
            return;
        }
        $seen_attempts[$key] = true;
        $attempts[] = [
            'category_ids' => $ids,
            'lane' => $lane,
            'allow_consecutive_same_category' => $allow_consecutive,
        ];
    };

    $new_primary = $frontier_new_categories;
    $new_secondary = $remaining_new_categories;
    $review_primary = $review_categories;

    $no_review_yet = empty($review_categories);
    $single_first_frontier = ($no_review_yet && count($frontier_new_categories) === 1);
    $allow_single_frontier_repeat = $single_first_frontier;
    $strict_frontier_only = $single_first_frontier;

    if ($strict_frontier_only) {
        $append_attempt($new_primary, 'new', $allow_single_frontier_repeat);
    } else {
        if ($preferred_lane === 'new') {
            $append_attempt($new_primary, 'new', $allow_single_frontier_repeat);
            $append_attempt($review_primary, 'review');
            $append_attempt($new_secondary, 'new');
        } elseif ($preferred_lane === 'review') {
            $append_attempt($review_primary, 'review');
            $append_attempt($new_primary, 'new', $allow_single_frontier_repeat);
            $append_attempt($new_secondary, 'new');
        } else {
            $append_attempt($new_primary, 'new', $allow_single_frontier_repeat);
            $append_attempt($review_primary, 'review');
            $append_attempt($new_secondary, 'new');
        }

        // If primary logical "next" recommendations are skipped/blocked, expand upward.
        $append_attempt(array_merge($frontier_new_categories, $remaining_new_categories), 'new-expanded');
        $append_attempt($ordered_category_ids, 'all');
    }

    return [
        'enabled' => true,
        'mode' => $ordering_mode,
        'ordered_category_ids' => $ordered_category_ids,
        'completion_map' => $completion_map,
        'frontier_new_category_ids' => $frontier_new_categories,
        'review_category_ids' => $review_categories,
        'strict_frontier_only' => $strict_frontier_only,
        'attempts' => $attempts,
    ];
}

function ll_tools_build_next_activity_recommendation($user_id = 0, $wordset_id = 0, $category_ids = [], $categories_payload = [], array $options = []): ?array {
    $uid = (int) ($user_id ?: get_current_user_id());
    $wordset_id = (int) $wordset_id;
    if ($uid <= 0) {
        return null;
    }

    $existing_excluded_signatures = [];
    if (isset($options['exclude_signatures']) && is_array($options['exclude_signatures'])) {
        $existing_excluded_signatures = $options['exclude_signatures'];
    } elseif (isset($options['exclude_queue_ids']) && is_array($options['exclude_queue_ids'])) {
        $existing_excluded_signatures = $options['exclude_queue_ids'];
    }
    $active_deferred_signatures = function_exists('ll_tools_get_user_active_recommendation_deferred_signatures')
        ? ll_tools_get_user_active_recommendation_deferred_signatures($uid, $wordset_id)
        : [];
    if (!empty($active_deferred_signatures)) {
        $options['exclude_signatures'] = array_values(array_unique(array_merge(
            ll_tools_normalize_recommendation_signature_list($existing_excluded_signatures, 128),
            ll_tools_normalize_recommendation_signature_list($active_deferred_signatures, 128)
        )));
    }

    $goals = ll_tools_get_user_study_goals($uid);
    $ignored_lookup = [];
    foreach ((array) ($goals['ignored_category_ids'] ?? []) as $ignored_id) {
        $ignored_lookup[(int) $ignored_id] = true;
    }

    $excluded_category_lookup = [];
    if (isset($options['exclude_category_ids']) && is_array($options['exclude_category_ids'])) {
        foreach ((array) $options['exclude_category_ids'] as $excluded_category_id) {
            $cid = (int) $excluded_category_id;
            if ($cid > 0) {
                $excluded_category_lookup[$cid] = true;
            }
        }
    }

    $selected = array_values(array_filter(array_map('intval', (array) $category_ids), function ($id) use ($ignored_lookup, $excluded_category_lookup) {
        return $id > 0 && empty($ignored_lookup[$id]) && empty($excluded_category_lookup[$id]);
    }));
    if (empty($selected) && !empty($categories_payload) && is_array($categories_payload)) {
        foreach ((array) $categories_payload as $cat) {
            $cid = isset($cat['id']) ? (int) $cat['id'] : 0;
            if ($cid > 0 && empty($ignored_lookup[$cid]) && empty($excluded_category_lookup[$cid])) {
                $selected[] = $cid;
            }
        }
    }
    $selected = array_values(array_unique(array_filter(array_map('intval', $selected), function ($id) {
        return $id > 0;
    })));

    $logical_plan = ll_tools_recommendation_build_logical_order_plan($uid, $wordset_id, $selected, (array) $categories_payload, [
        'previous_lane' => (string) ($options['logical_previous_lane'] ?? ''),
    ]);
    if (!empty($logical_plan['enabled']) && !empty($logical_plan['attempts']) && function_exists('ll_tools_build_next_activity_recommendation_core')) {
        foreach ((array) $logical_plan['attempts'] as $attempt) {
            if (!is_array($attempt)) {
                continue;
            }
            $attempt_category_ids = isset($attempt['category_ids']) && is_array($attempt['category_ids'])
                ? $attempt['category_ids']
                : [];
            if (empty($attempt_category_ids)) {
                continue;
            }

            $activity = ll_tools_build_next_activity_recommendation_core($uid, $wordset_id, $attempt_category_ids, $categories_payload, $options);
            if (!is_array($activity)) {
                continue;
            }

            if (!isset($activity['details']) || !is_array($activity['details'])) {
                $activity['details'] = [];
            }
            $activity['details']['logical_ordering_mode'] = (string) ($logical_plan['mode'] ?? 'none');
            $activity['details']['logical_lane'] = sanitize_key((string) ($attempt['lane'] ?? 'all'));
            $activity['details']['logical_frontier_size'] = count((array) ($logical_plan['frontier_new_category_ids'] ?? []));
            if (!empty($attempt['allow_consecutive_same_category'])) {
                $activity['details']['allow_consecutive_same_category'] = true;
            }

            $normalized = ll_tools_normalize_recommendation_activity($activity);
            if ($normalized) {
                return $normalized;
            }
            return $activity;
        }
    }

    if (!empty($logical_plan['enabled']) && !empty($logical_plan['strict_frontier_only'])) {
        return null;
    }

    return ll_tools_build_next_activity_recommendation_core($uid, $wordset_id, $category_ids, $categories_payload, $options);
}

function ll_tools_build_next_activity_recommendation_core($user_id = 0, $wordset_id = 0, $category_ids = [], $categories_payload = [], array $options = []): ?array {
    $uid = (int) ($user_id ?: get_current_user_id());
    if ($uid <= 0) {
        return null;
    }

    $goals = ll_tools_get_user_study_goals($uid);
    $enabled_modes = (array) ($goals['enabled_modes'] ?? ll_tools_progress_modes());
    if (empty($enabled_modes)) {
        $enabled_modes = ll_tools_progress_modes();
    }
    $enabled_modes = ll_tools_recommendation_ordered_modes($enabled_modes);
    $enabled_lookup = array_flip($enabled_modes);

    $preferred_mode_raw = strtolower(trim((string) ($options['preferred_mode'] ?? '')));
    if ($preferred_mode_raw === 'self_check') {
        $preferred_mode_raw = 'self-check';
    }
    $preferred_mode = in_array($preferred_mode_raw, ll_tools_progress_modes(), true) ? $preferred_mode_raw : '';
    if ($preferred_mode !== '' && !isset($enabled_lookup[$preferred_mode])) {
        $preferred_mode = '';
    }

    $excluded_queue_lookup = [];
    $excluded_raw = [];
    if (isset($options['exclude_queue_ids']) && is_array($options['exclude_queue_ids'])) {
        $excluded_raw = $options['exclude_queue_ids'];
    } elseif (isset($options['exclude_signatures']) && is_array($options['exclude_signatures'])) {
        $excluded_raw = $options['exclude_signatures'];
    }
    foreach ($excluded_raw as $queue_id) {
        $key = sanitize_key((string) $queue_id);
        if ($key !== '') {
            $excluded_queue_lookup[$key] = true;
        }
    }

    $excluded_category_lookup = [];
    if (isset($options['exclude_category_ids']) && is_array($options['exclude_category_ids'])) {
        foreach ((array) $options['exclude_category_ids'] as $excluded_category_id) {
            $cid = (int) $excluded_category_id;
            if ($cid > 0) {
                $excluded_category_lookup[$cid] = true;
            }
        }
    }

    $selected = [];
    $words_by_category = [];
    $all_word_ids = [];
    $word_category_lookup = [];
    $category_word_ids_lookup = [];
    $scope_words_loaded = false;

    $activity_has_category_scope_changed = static function (array $before, array $after): bool {
        $left = array_values(array_unique(array_filter(array_map('intval', $before), function ($id) {
            return $id > 0;
        })));
        $right = array_values(array_unique(array_filter(array_map('intval', $after), function ($id) {
            return $id > 0;
        })));
        sort($left, SORT_NUMERIC);
        sort($right, SORT_NUMERIC);
        if (count($left) !== count($right)) {
            return true;
        }
        foreach ($left as $idx => $id) {
            if (!isset($right[$idx]) || (int) $right[$idx] !== (int) $id) {
                return true;
            }
        }
        return false;
    };

    $enforce_single_aspect_bucket = static function (array $activity) use ($categories_payload, $activity_has_category_scope_changed): array {
        $category_ids = array_values(array_unique(array_filter(array_map('intval', (array) ($activity['category_ids'] ?? [])), function ($id) {
            return $id > 0;
        })));
        if (count($category_ids) < 2) {
            $activity['category_ids'] = $category_ids;
            return $activity;
        }

        $preferred_category_id = (int) ($category_ids[0] ?? 0);
        $filtered_ids = function_exists('ll_tools_filter_category_ids_by_aspect_bucket')
            ? ll_tools_filter_category_ids_by_aspect_bucket($category_ids, [
                'categories_payload' => (array) $categories_payload,
                'prefer_category_id' => $preferred_category_id,
            ])
            : $category_ids;

        $filtered_ids = array_values(array_unique(array_filter(array_map('intval', (array) $filtered_ids), function ($id) {
            return $id > 0;
        })));
        if (empty($filtered_ids)) {
            $filtered_ids = [(int) $category_ids[0]];
        }

        $activity['category_ids'] = $filtered_ids;

        if ($activity_has_category_scope_changed($category_ids, $filtered_ids)) {
            $activity['session_word_ids'] = [];
            if (!isset($activity['details']) || !is_array($activity['details'])) {
                $activity['details'] = [];
            }
            $activity['details']['aspect_bucket_filtered'] = true;
            $activity['details']['aspect_categories_before'] = count($category_ids);
            $activity['details']['aspect_categories_after'] = count($filtered_ids);
        }

        return $activity;
    };

    $load_scope_words = static function () use (&$scope_words_loaded, &$words_by_category, &$all_word_ids, &$word_category_lookup, &$category_word_ids_lookup, &$selected, $wordset_id): void {
        if ($scope_words_loaded) {
            return;
        }
        $scope_words_loaded = true;

        $words_by_category = [];
        $all_word_ids = [];
        $word_category_lookup = [];
        $category_word_ids_lookup = [];

        if (empty($selected) || !function_exists('ll_tools_user_study_words')) {
            return;
        }

        $words_by_category = ll_tools_user_study_words($selected, (int) $wordset_id);
        foreach ($selected as $cid_raw) {
            $cid = (int) $cid_raw;
            if ($cid <= 0) {
                continue;
            }

            if (!isset($category_word_ids_lookup[$cid])) {
                $category_word_ids_lookup[$cid] = [];
            }

            $rows = isset($words_by_category[$cid]) && is_array($words_by_category[$cid]) ? $words_by_category[$cid] : [];
            foreach ($rows as $word) {
                if (!is_array($word)) {
                    continue;
                }
                $wid = isset($word['id']) ? (int) $word['id'] : 0;
                if ($wid <= 0) {
                    continue;
                }
                $category_word_ids_lookup[$cid][] = $wid;
                if (!isset($word_category_lookup[$wid])) {
                    $word_category_lookup[$wid] = $cid;
                }
            }

            if (!empty($category_word_ids_lookup[$cid])) {
                $category_word_ids_lookup[$cid] = array_values(array_unique(array_filter(array_map('intval', $category_word_ids_lookup[$cid]), static function ($id): bool {
                    return $id > 0;
                })));
                $all_word_ids = array_merge($all_word_ids, $category_word_ids_lookup[$cid]);
            }
        }

        $all_word_ids = array_values(array_unique(array_filter(array_map('intval', $all_word_ids), static function ($id): bool {
            return $id > 0;
        })));
    };

    $finalize = static function (array $activity) use ($excluded_queue_lookup, $enforce_single_aspect_bucket, $load_scope_words, &$all_word_ids, &$category_word_ids_lookup): ?array {
        $activity = $enforce_single_aspect_bucket($activity);

        $raw_session_ids = array_values(array_unique(array_filter(array_map('intval', (array) ($activity['session_word_ids'] ?? [])), static function ($id): bool {
            return $id > 0;
        })));

        $load_scope_words();
        $activity_category_ids = array_values(array_unique(array_filter(array_map('intval', (array) ($activity['category_ids'] ?? [])), static function ($id): bool {
            return $id > 0;
        })));
        $primary_pool_ids = [];
        foreach ($activity_category_ids as $activity_cid) {
            $category_ids = isset($category_word_ids_lookup[$activity_cid]) && is_array($category_word_ids_lookup[$activity_cid])
                ? $category_word_ids_lookup[$activity_cid]
                : [];
            if (!empty($category_ids)) {
                $primary_pool_ids = array_merge($primary_pool_ids, $category_ids);
            }
        }

        $normalized_session_ids = ll_tools_recommendation_enforce_session_word_bounds(
            $raw_session_ids,
            $primary_pool_ids,
            $all_word_ids
        );
        if (empty($normalized_session_ids)) {
            return null;
        }
        $activity['session_word_ids'] = $normalized_session_ids;
        if ($normalized_session_ids !== $raw_session_ids) {
            if (!isset($activity['details']) || !is_array($activity['details'])) {
                $activity['details'] = [];
            }
            $activity['details']['session_word_ids_enforced'] = true;
            $activity['details']['chunk_size'] = count($normalized_session_ids);
        }

        $normalized = ll_tools_normalize_recommendation_activity($activity);
        if (!$normalized) {
            return null;
        }
        $queue_id = (string) ($normalized['queue_id'] ?? '');
        if ($queue_id !== '' && !empty($excluded_queue_lookup[$queue_id])) {
            return null;
        }
        return $normalized;
    };

    $ignored_lookup = [];
    foreach ((array) ($goals['ignored_category_ids'] ?? []) as $ignored_id) {
        $ignored_lookup[(int) $ignored_id] = true;
    }

    $placement_lookup = [];
    foreach ((array) ($goals['placement_known_category_ids'] ?? []) as $known_id) {
        $placement_lookup[(int) $known_id] = true;
    }

    $selected = array_values(array_filter(array_map('intval', (array) $category_ids), function ($id) use ($ignored_lookup, $excluded_category_lookup) {
        return $id > 0 && empty($ignored_lookup[$id]) && empty($excluded_category_lookup[$id]);
    }));

    if (empty($selected) && !empty($categories_payload) && is_array($categories_payload)) {
        foreach ($categories_payload as $cat) {
            $cid = isset($cat['id']) ? (int) $cat['id'] : 0;
            if ($cid > 0 && empty($ignored_lookup[$cid]) && empty($excluded_category_lookup[$cid])) {
                $selected[] = $cid;
            }
        }
    }

    if (count($selected) > 1 && function_exists('ll_tools_wordset_sort_category_ids')) {
        $selected = ll_tools_wordset_sort_category_ids($selected, (int) $wordset_id, [
            'categories_payload' => (array) $categories_payload,
        ]);
    }

    if (count($selected) > 1 && function_exists('ll_tools_filter_category_ids_by_aspect_bucket')) {
        $selected = ll_tools_filter_category_ids_by_aspect_bucket($selected, [
            'categories_payload' => (array) $categories_payload,
            'prefer_category_id' => (int) ($selected[0] ?? 0),
        ]);
    }

    if (empty($selected)) {
        return null;
    }

    $selected = ll_tools_recommendation_rotate_category_ids($selected, $uid, (int) $wordset_id);
    $category_rank_lookup = ll_tools_recommendation_category_rank_lookup($selected);

    $category_lookup = [];
    foreach ((array) $categories_payload as $cat) {
        $cid = isset($cat['id']) ? (int) $cat['id'] : 0;
        if ($cid > 0) {
            $category_lookup[$cid] = $cat;
        }
    }

    $study_state = function_exists('ll_tools_get_user_study_state') ? ll_tools_get_user_study_state($uid) : [];
    $starred_lookup = [];
    foreach ((array) ($study_state['starred_word_ids'] ?? []) as $wid) {
        $word_id = (int) $wid;
        if ($word_id > 0) {
            $starred_lookup[$word_id] = true;
        }
    }

    $category_progress = ll_tools_get_user_category_progress($uid);
    $priority_focus = ll_tools_normalize_user_study_priority_focus($goals['priority_focus'] ?? '');

    // 1) Pipeline recommendation for categories with no exposure in one or more enabled modes.
    if ($priority_focus === '') {
        foreach ($selected as $cid) {
            if (!empty($placement_lookup[$cid])) {
                continue;
            }

            $meta = $category_lookup[$cid] ?? [];
            $pipeline = ($preferred_mode !== '')
                ? [$preferred_mode]
                : ll_tools_category_pipeline_sequence($meta);
            $progress_for_cat = $category_progress[$cid]['exposure_by_mode'] ?? [];

            foreach ($pipeline as $mode) {
                if (!isset($enabled_lookup[$mode])) {
                    continue;
                }
                if ($mode === 'gender' && empty($meta['gender_supported'])) {
                    continue;
                }
                if ($mode === 'learning' && array_key_exists('learning_supported', $meta) && !$meta['learning_supported']) {
                    continue;
                }

                $seen = max(0, (int) ($progress_for_cat[$mode] ?? 0));
                if ($seen !== 0) {
                    continue;
                }

                $pipeline_chunk_word_ids = [];
                $load_scope_words();
                $pipeline_word_ids = isset($category_word_ids_lookup[$cid]) && is_array($category_word_ids_lookup[$cid])
                    ? array_values(array_unique(array_filter(array_map('intval', $category_word_ids_lookup[$cid]), static function ($id): bool {
                        return $id > 0;
                    })))
                    : [];
                if (!empty($pipeline_word_ids)) {
                    $pipeline_progress_rows = ll_tools_get_user_word_progress_rows($uid, $pipeline_word_ids);
                    $pipeline_chunk_size = ll_tools_recommendation_chunk_size_for_pool(count($pipeline_word_ids));
                    $pipeline_chunk = ll_tools_select_review_chunk_rows($pipeline_word_ids, $pipeline_progress_rows, $goals, $pipeline_chunk_size, $starred_lookup, [
                        'word_category_lookup' => array_fill_keys($pipeline_word_ids, $cid),
                        'category_rank_lookup' => [$cid => 0],
                    ]);
                    $pipeline_chunk_word_ids = $pipeline_chunk['word_ids'];
                    if (empty($pipeline_chunk_word_ids)) {
                        $pipeline_chunk_word_ids = array_slice($pipeline_word_ids, 0, $pipeline_chunk_size);
                    }
                }
                $pipeline_activity = $finalize([
                    'type'             => 'pipeline',
                    'reason_code'      => 'pipeline_unseen_mode',
                    'mode'             => $mode,
                    'category_ids'     => [$cid],
                    'session_word_ids' => $pipeline_chunk_word_ids,
                    'details'          => [
                        'pipeline' => $pipeline,
                        'seen_mode_count' => 0,
                        'chunk_size' => count($pipeline_chunk_word_ids),
                    ],
                ]);
                if ($pipeline_activity) {
                    return $pipeline_activity;
                }
            }
        }
    }

    // 2) Review chunk recommendation (typically 8-15 words) shaped by user priorities.
    if (!function_exists('ll_tools_user_study_words')) {
        return null;
    }

    $load_scope_words();
    if (empty($all_word_ids)) {
        return null;
    }

    $total_pool_count = count($all_word_ids);

    if ($priority_focus !== '') {
        $priority_progress_rows = ll_tools_get_user_word_progress_rows($uid, $all_word_ids);
        $focus_word_lookup = [];
        $pure_focus_categories = [];
        foreach ($selected as $cid) {
            $category_word_ids = isset($category_word_ids_lookup[$cid]) && is_array($category_word_ids_lookup[$cid])
                ? array_values(array_unique(array_filter(array_map('intval', $category_word_ids_lookup[$cid]), function ($id) {
                    return $id > 0;
                })))
                : [];
            if (empty($category_word_ids)) {
                continue;
            }

            $all_match = true;
            foreach ($category_word_ids as $wid) {
                $row = isset($priority_progress_rows[$wid]) && is_array($priority_progress_rows[$wid])
                    ? $priority_progress_rows[$wid]
                    : null;
                $matches_focus = ll_tools_user_study_word_matches_priority_focus($wid, $row, $priority_focus, $starred_lookup);
                if ($matches_focus) {
                    $focus_word_lookup[$wid] = true;
                } else {
                    $all_match = false;
                }
            }

            if ($all_match) {
                $pure_focus_categories[$cid] = $category_word_ids;
            }
        }

        $resolve_mode_for_categories = static function (array $category_ids_for_mode) use ($preferred_mode, $enabled_modes, $enabled_lookup, $category_lookup): string {
            $candidate_category_ids = array_values(array_filter(array_map('intval', $category_ids_for_mode), function ($id) {
                return $id > 0;
            }));
            $mode = ($preferred_mode !== '') ? $preferred_mode : ll_tools_pick_recommendation_mode($enabled_modes, $candidate_category_ids, $category_lookup);
            if ($mode === 'gender') {
                $supports_gender = false;
                foreach ($candidate_category_ids as $cid) {
                    if (!empty($category_lookup[$cid]['gender_supported'])) {
                        $supports_gender = true;
                        break;
                    }
                }
                if (!$supports_gender) {
                    $mode = isset($enabled_lookup['practice']) ? 'practice' : ll_tools_pick_recommendation_mode($enabled_modes, $candidate_category_ids, $category_lookup);
                }
            }
            if ($mode === 'learning') {
                $supports_learning = false;
                foreach ($candidate_category_ids as $cid) {
                    $meta = $category_lookup[$cid] ?? [];
                    if (!array_key_exists('learning_supported', $meta) || !empty($meta['learning_supported'])) {
                        $supports_learning = true;
                        break;
                    }
                }
                if (!$supports_learning) {
                    $mode = isset($enabled_lookup['practice']) ? 'practice' : ll_tools_pick_recommendation_mode($enabled_modes, $candidate_category_ids, $category_lookup);
                }
            }
            return $mode;
        };

        // Prefer a full single category when every word in that category matches the focus.
        $pure_category_candidates = [];
        foreach ($pure_focus_categories as $cid => $ids) {
            $count = count($ids);
            if ($count >= 8) {
                $pure_category_candidates[(int) $cid] = $ids;
            }
        }
        if (!empty($pure_category_candidates)) {
            $best_cid = 0;
            $best_score = null;
            foreach ($pure_category_candidates as $cid => $ids) {
                $meta = $category_lookup[$cid] ?? [];
                if ($preferred_mode === 'gender' && empty($meta['gender_supported'])) {
                    continue;
                }
                if ($preferred_mode === 'learning' && array_key_exists('learning_supported', $meta) && !$meta['learning_supported']) {
                    continue;
                }
                $progress_for_cat = isset($category_progress[$cid]['exposure_by_mode']) && is_array($category_progress[$cid]['exposure_by_mode'])
                    ? $category_progress[$cid]['exposure_by_mode']
                    : [];
                $exposure_total = max(0, (int) ($category_progress[$cid]['exposure_total'] ?? 0));
                $missing_modes = 0;
                foreach ($enabled_modes as $mode_raw) {
                    $mode = ll_tools_normalize_progress_mode($mode_raw);
                    if ($mode === 'gender' && empty($meta['gender_supported'])) {
                        continue;
                    }
                    if ($mode === 'learning' && array_key_exists('learning_supported', $meta) && !$meta['learning_supported']) {
                        continue;
                    }
                    $seen = max(0, (int) ($progress_for_cat[$mode] ?? 0));
                    if ($seen === 0) {
                        $missing_modes++;
                    }
                }

                $score = ($missing_modes * 1000) - $exposure_total + count($ids);
                if ($preferred_mode !== '') {
                    $preferred_seen = max(0, (int) ($progress_for_cat[$preferred_mode] ?? 0));
                    $score += 500 - ($preferred_seen * 220);
                }
                if ($best_score === null || $score > $best_score) {
                    $best_score = $score;
                    $best_cid = (int) $cid;
                }
            }

            if ($best_cid > 0 && !empty($pure_category_candidates[$best_cid])) {
                $category_word_ids = array_values(array_unique(array_filter(array_map('intval', (array) $pure_category_candidates[$best_cid]), function ($id) {
                    return $id > 0;
                })));
                $category_chunk_size = ll_tools_recommendation_chunk_size_for_pool(count($category_word_ids));
                $category_chunk_selection = ll_tools_select_review_chunk_rows($category_word_ids, $priority_progress_rows, $goals, $category_chunk_size, $starred_lookup, [
                    'word_category_lookup' => array_fill_keys($category_word_ids, $best_cid),
                    'category_rank_lookup' => [$best_cid => 0],
                ]);
                $category_session_word_ids = array_values(array_filter(array_map('intval', (array) ($category_chunk_selection['word_ids'] ?? [])), function ($id) {
                    return $id > 0;
                }));
                if (empty($category_session_word_ids)) {
                    $category_session_word_ids = array_slice($category_word_ids, 0, $category_chunk_size);
                }

                $category_focus_activity = $finalize([
                    'type'             => 'review_chunk',
                    'reason_code'      => 'priority_focus_category',
                    'mode'             => $resolve_mode_for_categories([$best_cid]),
                    'category_ids'     => [$best_cid],
                    'session_word_ids' => $category_session_word_ids,
                    'details'          => [
                        'chunk_size' => count($category_session_word_ids),
                        'total_pool' => $total_pool_count,
                    ],
                ]);
                if ($category_focus_activity) {
                    return $category_focus_activity;
                }
            }
        }

        // Otherwise combine only focus-matching words across categories.
        $focus_word_ids = array_values(array_filter(array_map('intval', array_keys($focus_word_lookup)), function ($id) {
            return $id > 0;
        }));
        sort($focus_word_ids, SORT_NUMERIC);
        if (!empty($focus_word_ids)) {
            $priority_chunk_size = ll_tools_recommendation_chunk_size_for_pool(count($focus_word_ids));
            $priority_selection = ll_tools_select_review_chunk_rows($focus_word_ids, $priority_progress_rows, $goals, $priority_chunk_size, $starred_lookup, [
                'word_category_lookup' => $word_category_lookup,
                'category_rank_lookup' => $category_rank_lookup,
            ]);
            $priority_session_word_ids = array_values(array_filter(array_map('intval', (array) ($priority_selection['word_ids'] ?? [])), function ($id) {
                return $id > 0;
            }));
            if (empty($priority_session_word_ids)) {
                $priority_session_word_ids = array_slice($focus_word_ids, 0, $priority_chunk_size);
            }

            if (!empty($priority_session_word_ids)) {
                $priority_session_categories = [];
                foreach ($priority_session_word_ids as $wid) {
                    if (isset($word_category_lookup[$wid])) {
                        $priority_session_categories[$word_category_lookup[$wid]] = true;
                    }
                }
                $priority_category_ids = array_values(array_keys($priority_session_categories));
                if (empty($priority_category_ids)) {
                    $priority_category_ids = array_slice($selected, 0, 3);
                }

                $priority_activity = $finalize([
                    'type'             => 'priority_focus',
                    'reason_code'      => 'priority_focus',
                    'mode'             => $resolve_mode_for_categories($priority_category_ids),
                    'category_ids'     => $priority_category_ids,
                    'session_word_ids' => $priority_session_word_ids,
                    'details'          => [
                        'priority_focus' => $priority_focus,
                        'priority_match_count' => count($priority_session_word_ids),
                        'chunk_size' => count($priority_session_word_ids),
                        'total_pool' => $total_pool_count,
                    ],
                ]);
                if ($priority_activity) {
                    return $priority_activity;
                }
            }
        }
    }

    // Prefer a full single-category chunk when that category naturally fits the target range.
    $single_category_candidates = [];
    foreach ($selected as $cid) {
        $ids = isset($category_word_ids_lookup[$cid]) && is_array($category_word_ids_lookup[$cid])
            ? $category_word_ids_lookup[$cid]
            : [];
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), function ($id) {
            return $id > 0;
        })));
        $count = count($ids);
        if ($count >= 8 && $count <= 15) {
            $single_category_candidates[$cid] = $ids;
        }
    }
    if (!empty($single_category_candidates)) {
        $best_cid = 0;
        $best_score = null;
        foreach ($single_category_candidates as $cid => $ids) {
            $meta = $category_lookup[$cid] ?? [];
            if ($preferred_mode === 'gender' && empty($meta['gender_supported'])) {
                continue;
            }
            if ($preferred_mode === 'learning' && array_key_exists('learning_supported', $meta) && !$meta['learning_supported']) {
                continue;
            }
            $progress_for_cat = isset($category_progress[$cid]['exposure_by_mode']) && is_array($category_progress[$cid]['exposure_by_mode'])
                ? $category_progress[$cid]['exposure_by_mode']
                : [];
            $exposure_total = max(0, (int) ($category_progress[$cid]['exposure_total'] ?? 0));
            $missing_modes = 0;
            foreach ($enabled_modes as $mode_raw) {
                $mode = ll_tools_normalize_progress_mode($mode_raw);
                if ($mode === 'gender' && empty($meta['gender_supported'])) {
                    continue;
                }
                if ($mode === 'learning' && array_key_exists('learning_supported', $meta) && !$meta['learning_supported']) {
                    continue;
                }
                $seen = max(0, (int) ($progress_for_cat[$mode] ?? 0));
                if ($seen === 0) {
                    $missing_modes++;
                }
            }

            $score = ($missing_modes * 1000) - $exposure_total + count($ids);
            if ($preferred_mode !== '') {
                $preferred_seen = max(0, (int) ($progress_for_cat[$preferred_mode] ?? 0));
                // When a specific mode is requested, prioritize rotating to categories
                // with lower exposure in that mode before repeating the same category.
                $score += 500 - ($preferred_seen * 220);
            }
            if ($best_score === null || $score > $best_score) {
                $best_score = $score;
                $best_cid = (int) $cid;
            }
        }

        if ($best_cid > 0 && !empty($single_category_candidates[$best_cid])) {
            $all_word_ids = $single_category_candidates[$best_cid];
            $word_category_lookup = [];
            foreach ($all_word_ids as $wid) {
                $word_category_lookup[(int) $wid] = $best_cid;
            }
        }
    }

    $progress_rows = ll_tools_get_user_word_progress_rows($uid, $all_word_ids);
    $chunk_size = ll_tools_recommendation_chunk_size_for_pool(count($all_word_ids));
    $chunk_selection = ll_tools_select_review_chunk_rows($all_word_ids, $progress_rows, $goals, $chunk_size, $starred_lookup, [
        'word_category_lookup' => $word_category_lookup,
        'category_rank_lookup' => $category_rank_lookup,
    ]);
    $selected_rows = $chunk_selection['rows'];
    $session_word_ids = $chunk_selection['word_ids'];
    $weak_selected = (int) ($chunk_selection['counts']['weak'] ?? 0);
    $new_selected = (int) ($chunk_selection['counts']['new'] ?? 0);
    $due_selected = (int) ($chunk_selection['counts']['due'] ?? 0);
    $score_total = (int) ($chunk_selection['counts']['score_total'] ?? 0);

    if (empty($session_word_ids)) {
        $session_word_ids = array_slice($all_word_ids, 0, $chunk_size);
    }

    $session_categories = [];
    foreach ($selected_rows as $row) {
        $wid = (int) ($row['word_id'] ?? 0);
        if ($wid > 0 && isset($word_category_lookup[$wid])) {
            $session_categories[$word_category_lookup[$wid]] = true;
        }
    }

    $session_category_ids = array_values(array_keys($session_categories));
    if (empty($session_category_ids)) {
        $session_category_ids = array_slice($selected, 0, 3);
    }

    $all_session_categories_placement_known = !empty($session_category_ids);
    foreach ($session_category_ids as $cid) {
        if (empty($placement_lookup[(int) $cid])) {
            $all_session_categories_placement_known = false;
            break;
        }
    }

    $mode = ($preferred_mode !== '') ? $preferred_mode : ll_tools_pick_recommendation_mode($enabled_modes, $session_category_ids, $category_lookup);
    if ($preferred_mode === '' && $all_session_categories_placement_known && isset($enabled_lookup['self-check'])) {
        // When categories are marked known via placement, bias first recommendation to recall checks.
        $mode = 'self-check';
    }
    if ($mode === 'gender') {
        $supports_gender = false;
        foreach ($session_category_ids as $cid) {
            if (!empty($category_lookup[$cid]['gender_supported'])) {
                $supports_gender = true;
                break;
            }
        }
        if (!$supports_gender) {
            $mode = isset($enabled_lookup['practice']) ? 'practice' : ll_tools_pick_recommendation_mode($enabled_modes, $session_category_ids, $category_lookup);
        }
    }
    if ($mode === 'learning') {
        $supports_learning = false;
        foreach ($session_category_ids as $cid) {
            $meta = $category_lookup[$cid] ?? [];
            if (!array_key_exists('learning_supported', $meta) || !empty($meta['learning_supported'])) {
                $supports_learning = true;
                break;
            }
        }
        if (!$supports_learning) {
            $mode = isset($enabled_lookup['practice']) ? 'practice' : ll_tools_pick_recommendation_mode($enabled_modes, $session_category_ids, $category_lookup);
        }
    }

    return $finalize([
        'type'             => 'review_chunk',
        'reason_code'      => 'review_chunk_balanced',
        'mode'             => $mode,
        'category_ids'     => $session_category_ids,
        'session_word_ids' => $session_word_ids,
        'details'          => [
            'chunk_size'   => count($session_word_ids),
            'weak_count'   => $weak_selected,
            'new_count'    => $new_selected,
            'due_count'    => $due_selected,
            'score_total'  => $score_total,
            'total_pool'   => $total_pool_count,
        ],
    ]);
}

function ll_tools_build_activity_recommendation_queue($user_id = 0, $wordset_id = 0, $category_ids = [], $categories_payload = [], int $limit = 8, array $options = []): array {
    $uid = (int) ($user_id ?: get_current_user_id());
    $wordset_id = (int) $wordset_id;
    if ($uid <= 0) {
        return [];
    }

    $max_items = max(1, min(12, (int) $limit));
    $goals = ll_tools_get_user_study_goals($uid);
    $enabled_modes = ll_tools_recommendation_ordered_modes((array) ($goals['enabled_modes'] ?? ll_tools_progress_modes()));
    $preferred_mode_raw = strtolower(trim((string) ($options['preferred_mode'] ?? '')));
    if ($preferred_mode_raw === 'self_check') {
        $preferred_mode_raw = 'self-check';
    }
    $preferred_mode = in_array($preferred_mode_raw, ll_tools_progress_modes(), true) ? $preferred_mode_raw : '';
    if ($preferred_mode !== '') {
        $enabled_modes = array_values(array_unique(array_merge([$preferred_mode], $enabled_modes)));
    }
    $persistent_excluded_signatures = [];
    if (isset($options['exclude_signatures']) && is_array($options['exclude_signatures'])) {
        $persistent_excluded_signatures = $options['exclude_signatures'];
    } elseif (isset($options['exclude_queue_ids']) && is_array($options['exclude_queue_ids'])) {
        $persistent_excluded_signatures = $options['exclude_queue_ids'];
    }
    $persistent_excluded_signatures = ll_tools_normalize_recommendation_signature_list($persistent_excluded_signatures, 64);
    $scoped_category_ids = ll_tools_user_progress_category_ids_in_scope((array) $categories_payload, (array) $category_ids, $goals);
    $scoped_category_ids = array_values(array_filter(array_map('intval', $scoped_category_ids), function ($id) {
        return $id > 0;
    }));
    if (count($scoped_category_ids) > 1 && function_exists('ll_tools_wordset_sort_category_ids')) {
        $scoped_category_ids = ll_tools_wordset_sort_category_ids($scoped_category_ids, $wordset_id, [
            'categories_payload' => (array) $categories_payload,
        ]);
    }
    $scoped_category_lookup = array_fill_keys($scoped_category_ids, true);

    $queue = [];
    $seen_queue_ids = [];
    $seen_scope_category_ids = [];
    $deferred_by_conflict = [1 => [], 2 => []];
    $add_activity = static function ($activity, int $max_conflict_level = 0) use (&$queue, &$seen_queue_ids, &$seen_scope_category_ids, &$deferred_by_conflict, $scoped_category_lookup): bool {
        if (!is_array($activity)) {
            return false;
        }
        $queue_id = (string) ($activity['queue_id'] ?? '');
        if ($queue_id === '' || !empty($seen_queue_ids[$queue_id])) {
            return false;
        }
        $max_conflict_level = max(0, min(2, (int) $max_conflict_level));

        if (!empty($queue)) {
            $previous = $queue[count($queue) - 1];
            if (is_array($previous)) {
                $conflict_level = ll_tools_recommendation_consecutive_conflict_level($previous, $activity);
                if ($conflict_level > $max_conflict_level) {
                    if ($conflict_level >= 1 && $conflict_level <= 2) {
                        $deferred_by_conflict[$conflict_level][$queue_id] = $activity;
                    }
                    return false;
                }
            }
        }

        foreach ((array) ($activity['category_ids'] ?? []) as $cid_raw) {
            $cid = (int) $cid_raw;
            if ($cid > 0 && !empty($scoped_category_lookup[$cid])) {
                $seen_scope_category_ids[$cid] = true;
            }
        }
        unset($deferred_by_conflict[1][$queue_id], $deferred_by_conflict[2][$queue_id]);
        $seen_queue_ids[$queue_id] = true;
        $queue[] = $activity;
        return true;
    };

    $build_options = static function (string $mode = '') use (&$seen_queue_ids, &$seen_scope_category_ids, &$queue, $scoped_category_ids, $persistent_excluded_signatures): array {
        $exclude_queue_ids = array_values(array_unique(array_merge($persistent_excluded_signatures, array_keys($seen_queue_ids))));
        $opts = [
            'exclude_queue_ids' => $exclude_queue_ids,
        ];
        if ($mode !== '') {
            $opts['preferred_mode'] = $mode;
        }
        $allow_same_category_focus_repeat = false;
        $preserve_single_logical_frontier_focus = false;
        if (!empty($queue)) {
            $last_activity = $queue[count($queue) - 1];
            if (is_array($last_activity) && isset($last_activity['details']) && is_array($last_activity['details'])) {
                $last_lane = sanitize_key((string) ($last_activity['details']['logical_lane'] ?? ''));
                if ($last_lane === 'newexpanded') {
                    $last_lane = 'new';
                }
                if (in_array($last_lane, ['new', 'review'], true)) {
                    $opts['logical_previous_lane'] = $last_lane;
                }
                $allow_same_category_focus_repeat = !empty($last_activity['details']['allow_consecutive_same_category']);
                $logical_mode = sanitize_key((string) ($last_activity['details']['logical_ordering_mode'] ?? ''));
                $logical_frontier_size = isset($last_activity['details']['logical_frontier_size'])
                    ? max(0, (int) $last_activity['details']['logical_frontier_size'])
                    : 0;
                $preserve_single_logical_frontier_focus = ($logical_mode !== '' && $logical_frontier_size === 1);
            }
        }
        $scope_total = count($scoped_category_ids);
        $seen_total = count($seen_scope_category_ids);
        if (!$allow_same_category_focus_repeat && !$preserve_single_logical_frontier_focus && $scope_total > 1 && $seen_total > 0 && $seen_total < $scope_total) {
            $opts['exclude_category_ids'] = array_keys($seen_scope_category_ids);
        }
        return $opts;
    };

    $seed_options = [];
    if ($preferred_mode !== '') {
        $seed_options['preferred_mode'] = $preferred_mode;
    }
    if (!empty($persistent_excluded_signatures)) {
        $seed_options['exclude_queue_ids'] = $persistent_excluded_signatures;
    }
    $last_saved_recommendation = ll_tools_get_user_last_recommendation_activity($uid, $wordset_id);
    if (is_array($last_saved_recommendation) && isset($last_saved_recommendation['details']) && is_array($last_saved_recommendation['details'])) {
        $seed_last_lane = sanitize_key((string) ($last_saved_recommendation['details']['logical_lane'] ?? ''));
        if ($seed_last_lane === 'newexpanded') {
            $seed_last_lane = 'new';
        }
        if (in_array($seed_last_lane, ['new', 'review'], true)) {
            $seed_options['logical_previous_lane'] = $seed_last_lane;
        }
    }
    $seed = ll_tools_build_next_activity_recommendation($uid, $wordset_id, $category_ids, $categories_payload, $seed_options);
    $add_activity($seed);

    $max_passes = 3;
    for ($pass = 0; $pass < $max_passes && count($queue) < $max_items; $pass++) {
        foreach ($enabled_modes as $mode) {
            if (count($queue) >= $max_items) {
                break;
            }
            $activity = ll_tools_build_next_activity_recommendation($uid, $wordset_id, $category_ids, $categories_payload, $build_options($mode));
            $add_activity($activity);
        }
    }

    if (count($queue) < $max_items && count($scoped_category_ids) > 1) {
        foreach ($enabled_modes as $mode) {
            if (count($queue) >= $max_items) {
                break;
            }
            foreach ($scoped_category_ids as $cid) {
                if (count($queue) >= $max_items) {
                    break;
                }
                $activity = ll_tools_build_next_activity_recommendation($uid, $wordset_id, [$cid], $categories_payload, [
                    'preferred_mode' => $mode,
                    'exclude_queue_ids' => array_values(array_unique(array_merge($persistent_excluded_signatures, array_keys($seen_queue_ids)))),
                    'logical_previous_lane' => (function () use (&$queue) {
                        if (empty($queue)) {
                            return '';
                        }
                        $last = $queue[count($queue) - 1];
                        if (!is_array($last) || !isset($last['details']) || !is_array($last['details'])) {
                            return '';
                        }
                        $lane = sanitize_key((string) ($last['details']['logical_lane'] ?? ''));
                        return ($lane === 'newexpanded') ? 'new' : $lane;
                    })(),
                ]);
                $add_activity($activity);
            }
        }
    }

    if (count($queue) < $max_items) {
        $fallback = ll_tools_build_next_activity_recommendation($uid, $wordset_id, $category_ids, $categories_payload, $build_options());
        $add_activity($fallback);
    }

    if (count($queue) < $max_items && !empty($deferred_by_conflict[1])) {
        foreach (array_values((array) $deferred_by_conflict[1]) as $activity) {
            if (count($queue) >= $max_items) {
                break;
            }
            $add_activity($activity, 1);
        }
    }

    if (count($queue) < $max_items && !empty($deferred_by_conflict[2])) {
        foreach (array_values((array) $deferred_by_conflict[2]) as $activity) {
            if (count($queue) >= $max_items) {
                break;
            }
            $add_activity($activity, 2);
        }
    }

    if (count($queue) < $max_items) {
        $fallback_relaxed = ll_tools_build_next_activity_recommendation($uid, $wordset_id, $category_ids, $categories_payload, [
            'exclude_queue_ids' => array_values(array_unique(array_merge($persistent_excluded_signatures, array_keys($seen_queue_ids)))),
        ]);
        $add_activity($fallback_relaxed, 2);
    }

    return ll_tools_normalize_recommendation_queue($queue, $max_items);
}

function ll_tools_refresh_user_recommendation_queue($user_id = 0, $wordset_id = 0, $category_ids = [], $categories_payload = [], int $limit = 8, array $options = []): array {
    $uid = (int) ($user_id ?: get_current_user_id());
    $wordset_id = (int) $wordset_id;
    if ($uid <= 0 || $wordset_id <= 0) {
        return [];
    }
    $dismissed_signatures = ll_tools_get_user_recommendation_dismissed_signatures($uid, $wordset_id);
    $deferred_signatures = function_exists('ll_tools_get_user_active_recommendation_deferred_signatures')
        ? ll_tools_get_user_active_recommendation_deferred_signatures($uid, $wordset_id)
        : [];
    $queue_options = $options;
    if (!empty($dismissed_signatures) || !empty($deferred_signatures)) {
        $existing_excluded = [];
        if (isset($queue_options['exclude_signatures']) && is_array($queue_options['exclude_signatures'])) {
            $existing_excluded = $queue_options['exclude_signatures'];
        } elseif (isset($queue_options['exclude_queue_ids']) && is_array($queue_options['exclude_queue_ids'])) {
            $existing_excluded = $queue_options['exclude_queue_ids'];
        }
        $queue_options['exclude_signatures'] = array_values(array_unique(array_merge(
            ll_tools_normalize_recommendation_signature_list($existing_excluded, 64),
            $dismissed_signatures,
            ll_tools_normalize_recommendation_signature_list($deferred_signatures, 128)
        )));
    }

    $queue = ll_tools_build_activity_recommendation_queue($uid, $wordset_id, $category_ids, $categories_payload, $limit, $queue_options);
    if (empty($queue) && !empty($dismissed_signatures)) {
        ll_tools_save_user_recommendation_dismissed_signatures([], $uid, $wordset_id);
        $queue_options = $options;
        if (!empty($deferred_signatures)) {
            $existing_excluded = [];
            if (isset($queue_options['exclude_signatures']) && is_array($queue_options['exclude_signatures'])) {
                $existing_excluded = $queue_options['exclude_signatures'];
            } elseif (isset($queue_options['exclude_queue_ids']) && is_array($queue_options['exclude_queue_ids'])) {
                $existing_excluded = $queue_options['exclude_queue_ids'];
            }
            $queue_options['exclude_signatures'] = array_values(array_unique(array_merge(
                ll_tools_normalize_recommendation_signature_list($existing_excluded, 64),
                ll_tools_normalize_recommendation_signature_list($deferred_signatures, 128)
            )));
        }
        $queue = ll_tools_build_activity_recommendation_queue($uid, $wordset_id, $category_ids, $categories_payload, $limit, $queue_options);
    }
    $last_recommendation = ll_tools_get_user_last_recommendation_activity($uid, $wordset_id);
    $preferred_mode = strtolower(trim((string) ($options['preferred_mode'] ?? '')));
    if ($preferred_mode === 'self_check') {
        $preferred_mode = 'self-check';
    }
    if (!in_array($preferred_mode, ll_tools_progress_modes(), true)) {
        $preferred_mode = '';
    }
    if ($last_recommendation && !empty($queue)) {
        $queue = ll_tools_recommendation_queue_promote_non_overlapping_head($queue, $last_recommendation, $preferred_mode);
        $head = ll_tools_recommendation_queue_pick_next($queue, $preferred_mode);
        $head_conflict = (is_array($head))
            ? ll_tools_recommendation_consecutive_conflict_level($last_recommendation, $head)
            : 0;
        if ($head_conflict > 0) {
            $exclude_category_ids = ll_tools_recommendation_activity_category_ids($last_recommendation);
            if (!empty($exclude_category_ids)) {
                $exclude_queue_ids = [];
                foreach ((array) $queue as $activity) {
                    if (!is_array($activity)) {
                        continue;
                    }
                    $queue_id = sanitize_key((string) ($activity['queue_id'] ?? ''));
                    if ($queue_id !== '') {
                        $exclude_queue_ids[] = $queue_id;
                    }
                }
                $extra_options = $queue_options;
                $extra_excluded = [];
                if (isset($extra_options['exclude_signatures']) && is_array($extra_options['exclude_signatures'])) {
                    $extra_excluded = ll_tools_normalize_recommendation_signature_list($extra_options['exclude_signatures'], 64);
                } elseif (isset($extra_options['exclude_queue_ids']) && is_array($extra_options['exclude_queue_ids'])) {
                    $extra_excluded = ll_tools_normalize_recommendation_signature_list($extra_options['exclude_queue_ids'], 64);
                }
                $extra_options['exclude_queue_ids'] = array_values(array_unique(array_merge($exclude_queue_ids, $extra_excluded)));
                $extra_options['exclude_category_ids'] = $exclude_category_ids;
                $alternative = ll_tools_build_next_activity_recommendation($uid, $wordset_id, $category_ids, $categories_payload, $extra_options);
                if (is_array($alternative) && ll_tools_recommendation_consecutive_conflict_level($last_recommendation, $alternative) === 0) {
                    array_unshift($queue, $alternative);
                    $queue = ll_tools_normalize_recommendation_queue($queue, $limit);
                }
            }
        }
    }
    $queue = ll_tools_recommendation_queue_ensure_length($queue, $limit);
    return ll_tools_save_user_recommendation_queue($queue, $uid, $wordset_id);
}

function ll_tools_recommendation_queue_pick_next(array $queue, string $preferred_mode = ''): ?array {
    $preferred_mode = strtolower(trim($preferred_mode));
    if ($preferred_mode === 'self_check') {
        $preferred_mode = 'self-check';
    }
    if (!in_array($preferred_mode, ll_tools_progress_modes(), true)) {
        $preferred_mode = '';
    }
    if ($preferred_mode !== '') {
        foreach ($queue as $activity) {
            if (!is_array($activity)) {
                continue;
            }
            if ((string) ($activity['mode'] ?? '') === $preferred_mode) {
                return $activity;
            }
        }
    }
    foreach ($queue as $activity) {
        if (is_array($activity)) {
            return $activity;
        }
    }
    return null;
}

function ll_tools_user_progress_batch_ajax() {
    if (!is_user_logged_in() || !ll_tools_user_study_can_access()) {
        wp_send_json_error(['message' => __('Login required.', 'll-tools-text-domain')], 401);
    }
    check_ajax_referer('ll_user_study', 'nonce');

    $events_raw = $_POST['events'] ?? '[]';
    if (is_array($events_raw)) {
        $events = $events_raw;
    } else {
        $decoded = json_decode(wp_unslash((string) $events_raw), true);
        $events = is_array($decoded) ? $decoded : [];
    }

    $events = array_slice($events, 0, 200);
    $stats = ll_tools_process_progress_events_batch(get_current_user_id(), $events);

    $wordset_id = isset($_POST['wordset_id']) ? (int) $_POST['wordset_id'] : 0;
    $category_ids = isset($_POST['category_ids']) ? (array) $_POST['category_ids'] : [];
    $category_ids = array_values(array_filter(array_map('intval', $category_ids), function ($id) {
        return $id > 0;
    }));

    $categories = function_exists('ll_tools_user_study_categories_for_wordset')
        ? ll_tools_user_study_categories_for_wordset($wordset_id)
        : [];
    $queue = ll_tools_refresh_user_recommendation_queue(get_current_user_id(), $wordset_id, $category_ids, $categories, 8);
    $recommendation = ll_tools_recommendation_queue_pick_next($queue);
    if (!$recommendation) {
        $recommendation = ll_tools_build_next_activity_recommendation(get_current_user_id(), $wordset_id, $category_ids, $categories);
    }
    if ($recommendation) {
        ll_tools_save_user_last_recommendation_activity($recommendation, get_current_user_id(), $wordset_id);
    }

    wp_send_json_success([
        'stats' => $stats,
        'next_activity' => $recommendation,
        'recommendation_queue' => $queue,
    ]);
}
add_action('wp_ajax_ll_user_study_progress_batch', 'll_tools_user_progress_batch_ajax');

function ll_tools_user_study_save_goals_ajax() {
    if (!is_user_logged_in() || !ll_tools_user_study_can_access()) {
        wp_send_json_error(['message' => __('Login required.', 'll-tools-text-domain')], 401);
    }
    check_ajax_referer('ll_user_study', 'nonce');

    $goals_raw = isset($_POST['goals']) ? $_POST['goals'] : [];
    if (!is_array($goals_raw)) {
        $decoded = json_decode(wp_unslash((string) $goals_raw), true);
        $goals_raw = is_array($decoded) ? $decoded : [];
    }

    $goals = ll_tools_save_user_study_goals($goals_raw, get_current_user_id());

    $wordset_id = isset($_POST['wordset_id']) ? (int) $_POST['wordset_id'] : 0;
    $category_ids = isset($_POST['category_ids']) ? (array) $_POST['category_ids'] : [];
    $category_ids = array_values(array_filter(array_map('intval', $category_ids), function ($id) {
        return $id > 0;
    }));

    $categories = function_exists('ll_tools_user_study_categories_for_wordset')
        ? ll_tools_user_study_categories_for_wordset($wordset_id)
        : [];
    $queue = ll_tools_refresh_user_recommendation_queue(get_current_user_id(), $wordset_id, $category_ids, $categories, 8);
    $recommendation = ll_tools_recommendation_queue_pick_next($queue);
    if (!$recommendation) {
        $recommendation = ll_tools_build_next_activity_recommendation(get_current_user_id(), $wordset_id, $category_ids, $categories);
    }
    if ($recommendation) {
        ll_tools_save_user_last_recommendation_activity($recommendation, get_current_user_id(), $wordset_id);
    }

    wp_send_json_success([
        'goals' => $goals,
        'next_activity' => $recommendation,
        'recommendation_queue' => $queue,
    ]);
}
add_action('wp_ajax_ll_user_study_save_goals', 'll_tools_user_study_save_goals_ajax');

function ll_tools_user_study_recommendation_ajax() {
    if (!is_user_logged_in() || !ll_tools_user_study_can_access()) {
        wp_send_json_error(['message' => __('Login required.', 'll-tools-text-domain')], 401);
    }
    check_ajax_referer('ll_user_study', 'nonce');

    $wordset_id = isset($_POST['wordset_id']) ? (int) $_POST['wordset_id'] : 0;
    $category_ids = isset($_POST['category_ids']) ? (array) $_POST['category_ids'] : [];
    $category_ids = array_values(array_filter(array_map('intval', $category_ids), function ($id) {
        return $id > 0;
    }));
    $preferred_mode_raw = strtolower(trim((string) ($_POST['preferred_mode'] ?? '')));
    if ($preferred_mode_raw === 'self_check') {
        $preferred_mode_raw = 'self-check';
    }
    $preferred_mode = in_array($preferred_mode_raw, ll_tools_progress_modes(), true) ? $preferred_mode_raw : '';
    $force_refresh = !isset($_POST['refresh']) || !empty($_POST['refresh']);

    $categories = function_exists('ll_tools_user_study_categories_for_wordset')
        ? ll_tools_user_study_categories_for_wordset($wordset_id)
        : [];
    if ($force_refresh) {
        $queue = ll_tools_refresh_user_recommendation_queue(get_current_user_id(), $wordset_id, $category_ids, $categories, 8, $preferred_mode !== '' ? ['preferred_mode' => $preferred_mode] : []);
    } else {
        $queue = ll_tools_get_user_recommendation_queue(get_current_user_id(), $wordset_id);
        if (empty($queue)) {
            $queue = ll_tools_refresh_user_recommendation_queue(get_current_user_id(), $wordset_id, $category_ids, $categories, 8, $preferred_mode !== '' ? ['preferred_mode' => $preferred_mode] : []);
        }
    }
    $recommendation = ll_tools_recommendation_queue_pick_next($queue, $preferred_mode);
    if (!$recommendation) {
        $recommendation = ll_tools_build_next_activity_recommendation(get_current_user_id(), $wordset_id, $category_ids, $categories, $preferred_mode !== '' ? ['preferred_mode' => $preferred_mode] : []);
    }
    if ($recommendation) {
        ll_tools_save_user_last_recommendation_activity($recommendation, get_current_user_id(), $wordset_id);
    }

    wp_send_json_success([
        'next_activity' => $recommendation,
        'recommendation_queue' => $queue,
    ]);
}
add_action('wp_ajax_ll_user_study_recommendation', 'll_tools_user_study_recommendation_ajax');

function ll_tools_user_study_queue_remove_ajax() {
    if (!is_user_logged_in() || !ll_tools_user_study_can_access()) {
        wp_send_json_error(['message' => __('Login required.', 'll-tools-text-domain')], 401);
    }
    check_ajax_referer('ll_user_study', 'nonce');

    $uid = get_current_user_id();
    $wordset_id = isset($_POST['wordset_id']) ? (int) $_POST['wordset_id'] : 0;
    $queue_id = isset($_POST['queue_id']) ? sanitize_key((string) $_POST['queue_id']) : '';
    $removed_signature = '';
    $removed_activity = null;
    if ($wordset_id > 0 && $queue_id !== '') {
        $current_queue = ll_tools_get_user_recommendation_queue($uid, $wordset_id);
        foreach ((array) $current_queue as $activity) {
            if (!is_array($activity)) {
                continue;
            }
            $current_id = sanitize_key((string) ($activity['queue_id'] ?? ''));
            if ($current_id === '' || $current_id !== $queue_id) {
                continue;
            }
            $removed_signature = ll_tools_recommendation_activity_queue_id($activity);
            $removed_activity = $activity;
            break;
        }
    }

    $categories = function_exists('ll_tools_user_study_categories_for_wordset')
        ? ll_tools_user_study_categories_for_wordset($wordset_id)
        : [];
    if (is_array($removed_activity) && function_exists('ll_tools_defer_user_recommendation_activity')) {
        ll_tools_defer_user_recommendation_activity($removed_activity, $uid, $wordset_id);
    } elseif ($removed_signature !== '') {
        ll_tools_add_user_recommendation_dismissed_signature($removed_signature, $uid, $wordset_id);
    }
    $queue = ll_tools_refresh_user_recommendation_queue($uid, $wordset_id, [], $categories, 8);

    $recommendation = ll_tools_recommendation_queue_pick_next($queue);
    if (!$recommendation) {
        $recommendation = ll_tools_build_next_activity_recommendation($uid, $wordset_id, [], $categories);
    }
    if ($recommendation) {
        ll_tools_save_user_last_recommendation_activity($recommendation, $uid, $wordset_id);
    }

    wp_send_json_success([
        'next_activity' => $recommendation,
        'recommendation_queue' => $queue,
    ]);
}
add_action('wp_ajax_ll_user_study_queue_remove', 'll_tools_user_study_queue_remove_ajax');

function ll_tools_user_study_analytics_ajax() {
    if (!is_user_logged_in() || !ll_tools_user_study_can_access()) {
        wp_send_json_error(['message' => __('Login required.', 'll-tools-text-domain')], 401);
    }
    check_ajax_referer('ll_user_study', 'nonce');

    $wordset_id = isset($_POST['wordset_id']) ? (int) $_POST['wordset_id'] : 0;
    $category_ids = isset($_POST['category_ids']) ? (array) $_POST['category_ids'] : [];
    $days = isset($_POST['days']) ? (int) $_POST['days'] : 14;
    $include_ignored = !empty($_POST['include_ignored']);
    $category_ids = array_values(array_filter(array_map('intval', $category_ids), function ($id) {
        return $id > 0;
    }));

    $analytics = ll_tools_build_user_study_analytics_payload(get_current_user_id(), $wordset_id, $category_ids, $days, $include_ignored);

    wp_send_json_success([
        'analytics' => $analytics,
    ]);
}
add_action('wp_ajax_ll_user_study_analytics', 'll_tools_user_study_analytics_ajax');

/**
 * Resolve word IDs for a progress reset scope.
 *
 * This intentionally resolves scope from current taxonomy relationships so
 * reset actions remain accurate even if legacy rows have stale wordset/category
 * columns.
 *
 * @param int   $wordset_id   Optional wordset scope.
 * @param int[] $category_ids Optional category scope.
 * @return int[]
 */
function ll_tools_user_progress_resolve_scope_word_ids(int $wordset_id = 0, array $category_ids = []): array {
    $wordset_id = max(0, $wordset_id);
    $category_ids = array_values(array_unique(array_filter(array_map('intval', $category_ids), static function ($id) {
        return $id > 0;
    })));

    if ($wordset_id <= 0 && empty($category_ids)) {
        return [];
    }

    $tax_query = [];
    if (!empty($category_ids)) {
        $tax_query[] = [
            'taxonomy' => 'word-category',
            'field' => 'term_id',
            'terms' => $category_ids,
            'operator' => 'IN',
            'include_children' => false,
        ];
    }
    if ($wordset_id > 0) {
        $tax_query[] = [
            'taxonomy' => 'wordset',
            'field' => 'term_id',
            'terms' => [$wordset_id],
            'operator' => 'IN',
            'include_children' => false,
        ];
    }

    $query_args = [
        'post_type' => 'words',
        'post_status' => 'any',
        'fields' => 'ids',
        'posts_per_page' => -1,
        'no_found_rows' => true,
        'orderby' => 'ID',
        'order' => 'ASC',
    ];
    if (!empty($tax_query)) {
        $query_args['tax_query'] = (count($tax_query) > 1)
            ? array_merge(['relation' => 'AND'], $tax_query)
            : $tax_query;
    }

    $ids_raw = get_posts($query_args);
    if (empty($ids_raw) || !is_array($ids_raw)) {
        return [];
    }

    $word_ids = array_values(array_unique(array_filter(array_map('intval', $ids_raw), static function ($id) {
        return $id > 0;
    })));
    sort($word_ids, SORT_NUMERIC);
    return $word_ids;
}

/**
 * Delete user progress rows with optional wordset/category scope.
 *
 * @param int   $user_id User ID.
 * @param array $args {
 *     Optional. Reset scope.
 *
 *     @type int   $wordset_id   Optional wordset scope.
 *     @type int[] $category_ids Optional category scope.
 * }
 * @return array<string,mixed>
 */
function ll_tools_reset_user_progress(int $user_id, array $args = []): array {
    global $wpdb;

    $uid = (int) $user_id;
    $wordset_id = isset($args['wordset_id']) ? max(0, (int) $args['wordset_id']) : 0;
    $category_ids = isset($args['category_ids']) ? (array) $args['category_ids'] : [];
    $category_ids = array_values(array_unique(array_filter(array_map('intval', $category_ids), function ($id) {
        return $id > 0;
    })));

    $result = [
        'user_id' => $uid,
        'wordset_id' => $wordset_id,
        'category_ids' => $category_ids,
        'deleted_word_rows' => 0,
        'deleted_event_rows' => 0,
        'cleared_category_meta_entries' => 0,
    ];

    if ($uid <= 0) {
        return $result;
    }

    $tables = ll_tools_user_progress_table_names();
    $scope_word_ids = ll_tools_user_progress_resolve_scope_word_ids($wordset_id, $category_ids);

    $word_where = ['user_id = %d'];
    $word_params = [$uid];
    $event_where = ['user_id = %d'];
    $event_params = [$uid];

    if ($wordset_id > 0) {
        $word_where[] = 'wordset_id = %d';
        $word_params[] = $wordset_id;
        $event_where[] = 'wordset_id = %d';
        $event_params[] = $wordset_id;
    }

    if (!empty($category_ids)) {
        $placeholders = implode(', ', array_fill(0, count($category_ids), '%d'));
        $word_where[] = "category_id IN ({$placeholders})";
        $event_where[] = "category_id IN ({$placeholders})";
        $word_params = array_merge($word_params, $category_ids);
        $event_params = array_merge($event_params, $category_ids);
    }

    $word_sql = 'DELETE FROM ' . $tables['words'] . ' WHERE ' . implode(' AND ', $word_where);
    $event_sql = 'DELETE FROM ' . $tables['events'] . ' WHERE ' . implode(' AND ', $event_where);

    $prepared_word_sql = $wpdb->prepare($word_sql, $word_params);
    $prepared_event_sql = $wpdb->prepare($event_sql, $event_params);

    if (is_string($prepared_word_sql) && $prepared_word_sql !== '') {
        $deleted_words = $wpdb->query($prepared_word_sql);
        if ($deleted_words !== false) {
            $result['deleted_word_rows'] = max(0, (int) $deleted_words);
        }
    }

    if (is_string($prepared_event_sql) && $prepared_event_sql !== '') {
        $deleted_events = $wpdb->query($prepared_event_sql);
        if ($deleted_events !== false) {
            $result['deleted_event_rows'] = max(0, (int) $deleted_events);
        }
    }

    if (!empty($scope_word_ids)) {
        foreach (array_chunk($scope_word_ids, 300) as $chunk_ids) {
            $placeholders = implode(', ', array_fill(0, count($chunk_ids), '%d'));

            $word_sql_by_id = "DELETE FROM {$tables['words']} WHERE user_id = %d AND word_id IN ({$placeholders})";
            $word_params_by_id = array_merge([$uid], $chunk_ids);
            $prepared_word_sql_by_id = $wpdb->prepare($word_sql_by_id, $word_params_by_id);
            if (is_string($prepared_word_sql_by_id) && $prepared_word_sql_by_id !== '') {
                $deleted_words_by_id = $wpdb->query($prepared_word_sql_by_id);
                if ($deleted_words_by_id !== false) {
                    $result['deleted_word_rows'] += max(0, (int) $deleted_words_by_id);
                }
            }

            $event_sql_by_id = "DELETE FROM {$tables['events']} WHERE user_id = %d AND word_id IN ({$placeholders})";
            $event_params_by_id = array_merge([$uid], $chunk_ids);
            $prepared_event_sql_by_id = $wpdb->prepare($event_sql_by_id, $event_params_by_id);
            if (is_string($prepared_event_sql_by_id) && $prepared_event_sql_by_id !== '') {
                $deleted_events_by_id = $wpdb->query($prepared_event_sql_by_id);
                if ($deleted_events_by_id !== false) {
                    $result['deleted_event_rows'] += max(0, (int) $deleted_events_by_id);
                }
            }
        }
    }

    $category_progress = ll_tools_get_user_category_progress($uid);
    if (!empty($category_progress)) {
        $should_remove_by_category = !empty($category_ids);
        $category_lookup = $should_remove_by_category ? array_fill_keys($category_ids, true) : [];
        foreach ($category_progress as $progress_category_id => $entry) {
            $cid = (int) $progress_category_id;
            if ($cid <= 0 || !is_array($entry)) {
                continue;
            }

            $entry_wordset_id = max(0, (int) ($entry['wordset_id'] ?? 0));
            $matches_category = $should_remove_by_category && !empty($category_lookup[$cid]);
            $matches_wordset = !$should_remove_by_category && $wordset_id > 0 && $entry_wordset_id === $wordset_id;

            if (!$matches_category && !$matches_wordset) {
                continue;
            }

            unset($category_progress[$cid]);
            $result['cleared_category_meta_entries']++;
        }

        if ($result['cleared_category_meta_entries'] > 0) {
            if (empty($category_progress)) {
                delete_user_meta($uid, LL_TOOLS_USER_CATEGORY_PROGRESS_META);
            } else {
                update_user_meta($uid, LL_TOOLS_USER_CATEGORY_PROGRESS_META, $category_progress);
            }
        }
    }

    if ($wordset_id > 0) {
        ll_tools_save_user_recommendation_queue([], $uid, $wordset_id);
        ll_tools_save_user_last_recommendation_activity(null, $uid, $wordset_id);
        ll_tools_save_user_recommendation_dismissed_signatures([], $uid, $wordset_id);
        if (function_exists('ll_tools_save_user_recommendation_deferrals')) {
            ll_tools_save_user_recommendation_deferrals([], $uid, $wordset_id);
        }
    }

    return $result;
}

function ll_tools_cleanup_user_progress_for_deleted_user($user_id): void {
    global $wpdb;
    $uid = (int) $user_id;
    if ($uid <= 0) {
        return;
    }

    $tables = ll_tools_user_progress_table_names();
    $wpdb->delete($tables['words'], ['user_id' => $uid], ['%d']);
    $wpdb->delete($tables['events'], ['user_id' => $uid], ['%d']);
}
add_action('delete_user', 'll_tools_cleanup_user_progress_for_deleted_user');

function ll_tools_cleanup_user_progress_for_deleted_word($post_id): void {
    if (get_post_type($post_id) !== 'words') {
        return;
    }

    global $wpdb;
    $wid = (int) $post_id;
    if ($wid <= 0) {
        return;
    }

    $tables = ll_tools_user_progress_table_names();
    $wpdb->delete($tables['words'], ['word_id' => $wid], ['%d']);
    $wpdb->delete($tables['events'], ['word_id' => $wid], ['%d']);
}
add_action('before_delete_post', 'll_tools_cleanup_user_progress_for_deleted_word');
