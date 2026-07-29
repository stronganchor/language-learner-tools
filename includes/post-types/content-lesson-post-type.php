<?php
// File: includes/post-types/content-lesson-post-type.php
if (!defined('WPINC')) { die; }

require_once __DIR__ . '/../legacy-content-lesson-contracts.php';

if (!defined('LL_TOOLS_CONTENT_LESSON_WORDSET_META')) {
    define('LL_TOOLS_CONTENT_LESSON_WORDSET_META', '_ll_tools_content_lesson_wordset_id');
}
if (!defined('LL_TOOLS_CONTENT_LESSON_KIND_META')) {
    define('LL_TOOLS_CONTENT_LESSON_KIND_META', '_ll_tools_content_lesson_kind');
}
if (!defined('LL_TOOLS_CONTENT_LESSON_MEDIA_TYPE_META')) {
    define('LL_TOOLS_CONTENT_LESSON_MEDIA_TYPE_META', '_ll_tools_content_lesson_media_type');
}
if (!defined('LL_TOOLS_CONTENT_LESSON_MEDIA_URL_META')) {
    define('LL_TOOLS_CONTENT_LESSON_MEDIA_URL_META', '_ll_tools_content_lesson_media_url');
}
if (!defined('LL_TOOLS_CONTENT_LESSON_TRANSCRIPT_FORMAT_META')) {
    define('LL_TOOLS_CONTENT_LESSON_TRANSCRIPT_FORMAT_META', '_ll_tools_content_lesson_transcript_format');
}
if (!defined('LL_TOOLS_CONTENT_LESSON_TRANSCRIPT_SOURCE_META')) {
    define('LL_TOOLS_CONTENT_LESSON_TRANSCRIPT_SOURCE_META', '_ll_tools_content_lesson_transcript_source');
}
if (!defined('LL_TOOLS_CONTENT_LESSON_CUES_META')) {
    define('LL_TOOLS_CONTENT_LESSON_CUES_META', '_ll_tools_content_lesson_cues');
}
if (!defined('LL_TOOLS_CONTENT_LESSON_CATEGORY_IDS_META')) {
    define('LL_TOOLS_CONTENT_LESSON_CATEGORY_IDS_META', '_ll_tools_content_lesson_category_ids');
}
if (!defined('LL_TOOLS_CONTENT_LESSON_SHOW_IN_MIX_META')) {
    define('LL_TOOLS_CONTENT_LESSON_SHOW_IN_MIX_META', '_ll_tools_content_lesson_show_in_mix');
}
if (!defined('LL_TOOLS_CONTENT_LESSON_CORPUS_COLLECTION_META')) {
    define('LL_TOOLS_CONTENT_LESSON_CORPUS_COLLECTION_META', '_ll_tools_corpus_text_collection');
}
if (!defined('LL_TOOLS_CONTENT_LESSON_CORPUS_COLLECTION_LABEL_META')) {
    define('LL_TOOLS_CONTENT_LESSON_CORPUS_COLLECTION_LABEL_META', '_ll_tools_corpus_text_collection_label');
}
if (!defined('LL_TOOLS_CONTENT_LESSON_CORPUS_SOURCE_AUTHOR_META')) {
    define('LL_TOOLS_CONTENT_LESSON_CORPUS_SOURCE_AUTHOR_META', '_ll_tools_corpus_text_source_author');
}
if (!defined('LL_TOOLS_CONTENT_LESSON_PREREQ_CATEGORY_IDS_META')) {
    define('LL_TOOLS_CONTENT_LESSON_PREREQ_CATEGORY_IDS_META', '_ll_tools_content_lesson_prereq_category_ids');
}
if (!defined('LL_TOOLS_CONTENT_LESSON_PREREQ_LESSON_IDS_META')) {
    define('LL_TOOLS_CONTENT_LESSON_PREREQ_LESSON_IDS_META', '_ll_tools_content_lesson_prereq_lesson_ids');
}
if (!defined('LL_TOOLS_CONTENT_LESSON_PARSE_ERROR_META')) {
    define('LL_TOOLS_CONTENT_LESSON_PARSE_ERROR_META', '_ll_tools_content_lesson_parse_error');
}
if (!defined('LL_TOOLS_CONTENT_LESSON_RELATION_ERROR_META')) {
    define('LL_TOOLS_CONTENT_LESSON_RELATION_ERROR_META', '_ll_tools_content_lesson_relation_error');
}
if (!defined('LL_TOOLS_CONTENT_LESSON_REWRITE_OPTION')) {
    define('LL_TOOLS_CONTENT_LESSON_REWRITE_OPTION', 'll_tools_content_lesson_rewrite_schema');
}

/**
 * Register the general content lesson custom post type.
 */
function ll_tools_register_content_lesson_post_type() {
    $labels = [
        'name'               => esc_html__('Content Lessons', 'll-tools-text-domain'),
        'singular_name'      => esc_html__('Content Lesson', 'll-tools-text-domain'),
        'add_new_item'       => esc_html__('Add New Content Lesson', 'll-tools-text-domain'),
        'edit_item'          => esc_html__('Edit Content Lesson', 'll-tools-text-domain'),
        'new_item'           => esc_html__('New Content Lesson', 'll-tools-text-domain'),
        'view_item'          => esc_html__('View Content Lesson', 'll-tools-text-domain'),
        'search_items'       => esc_html__('Search Content Lessons', 'll-tools-text-domain'),
        'not_found'          => esc_html__('No content lessons found', 'll-tools-text-domain'),
        'not_found_in_trash' => esc_html__('No content lessons found in Trash', 'll-tools-text-domain'),
        'menu_name'          => esc_html__('Content Lessons', 'll-tools-text-domain'),
    ];

    $args = [
        'label'               => esc_html__('Content Lessons', 'll-tools-text-domain'),
        'labels'              => $labels,
        'description'         => '',
        'public'              => true,
        'publicly_queryable'  => true,
        'show_ui'             => true,
        'show_in_menu'        => true,
        'show_in_nav_menus'   => false,
        'exclude_from_search' => true,
        'has_archive'         => false,
        'show_in_rest'        => false,
        'rewrite'             => [
            'slug'       => 'lesson',
            'with_front' => false,
        ],
        'query_var'           => 'll_content_lesson',
        'capability_type'     => 'post',
        'map_meta_cap'        => true,
        'supports'            => ['title', 'editor', 'excerpt', 'page-attributes'],
    ];

    register_post_type('ll_content_lesson', $args);
}
add_action('init', 'll_tools_register_content_lesson_post_type', 0);

/**
 * Flush rewrite rules once after this CPT ships into an already-active site.
 */
function ll_tools_maybe_schedule_content_lesson_rewrite_flush(): void {
    $schema_version = '1';
    $stored_version = (string) get_option(LL_TOOLS_CONTENT_LESSON_REWRITE_OPTION, '');
    if ($stored_version === $schema_version) {
        return;
    }

    set_transient('ll_tools_vocab_lesson_flush_rewrite', 1, 10 * MINUTE_IN_SECONDS);
    update_option(LL_TOOLS_CONTENT_LESSON_REWRITE_OPTION, $schema_version, false);
}
add_action('init', 'll_tools_maybe_schedule_content_lesson_rewrite_flush', 5);

function ll_tools_content_lesson_sanitize_media_type($raw): string {
    $value = sanitize_key((string) $raw);
    return in_array($value, ['audio', 'video'], true) ? $value : 'audio';
}

function ll_tools_content_lesson_kind_options(): array {
    return [
        'standard' => __('Audio/video lesson', 'll-tools-text-domain'),
        'article' => __('Article lesson', 'll-tools-text-domain'),
        'corpus_text' => __('Corpus text', 'll-tools-text-domain'),
    ];
}

function ll_tools_content_lesson_sanitize_kind($raw): string {
    $value = sanitize_key((string) $raw);
    return array_key_exists($value, ll_tools_content_lesson_kind_options()) ? $value : 'standard';
}

function ll_tools_content_lesson_sanitize_transcript_format($raw): string {
    $value = sanitize_key((string) $raw);
    return in_array($value, ['auto', 'vtt', 'json', 'tsv'], true) ? $value : 'auto';
}

function ll_tools_content_lesson_normalize_category_ids(
    $raw,
    ?bool &$complete = null
): array {
    global $wpdb;

    $complete = true;
    if (!is_array($raw)) {
        $raw = [$raw];
    }

    $ids = array_values(array_unique(array_filter(array_map('intval', $raw), static function (int $term_id): bool {
        return $term_id > 0;
    })));
    $limit = max(10, min(1000, (int) apply_filters(
        'll_tools_content_lesson_category_identity_limit',
        500
    )));
    if (count($ids) > $limit) {
        $complete = false;
        return [];
    }

    if (empty($ids)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '%d'));
    $wpdb->last_error = '';
    $valid_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT t.term_id
        FROM {$wpdb->terms} t
        INNER JOIN {$wpdb->term_taxonomy} tt
            ON tt.term_id = t.term_id
           AND tt.taxonomy = %s
        WHERE t.term_id IN ({$placeholders})",
        array_merge(['word-category'], $ids)
    ));
    if ($wpdb->last_error !== '') {
        $complete = false;
        return [];
    }

    $ids = array_values(array_unique(array_map('intval', (array) $valid_ids)));
    sort($ids, SORT_NUMERIC);

    return $ids;
}

function ll_tools_content_lesson_normalize_lesson_ids(
    $raw,
    ?bool &$complete = null
): array {
    global $wpdb;

    $complete = true;
    if (!is_array($raw)) {
        $raw = [$raw];
    }

    $ids = array_values(array_unique(array_filter(array_map('intval', $raw), static function (int $post_id): bool {
        return $post_id > 0;
    })));
    $limit = max(10, min(1000, (int) apply_filters(
        'll_tools_content_lesson_lesson_identity_limit',
        500
    )));
    if (count($ids) > $limit) {
        $complete = false;
        return [];
    }

    if (empty($ids)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '%d'));
    $wpdb->last_error = '';
    $valid_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT ID
        FROM {$wpdb->posts}
        WHERE post_type = %s
          AND ID IN ({$placeholders})",
        array_merge(['ll_content_lesson'], $ids)
    ));
    if ($wpdb->last_error !== '') {
        $complete = false;
        return [];
    }

    $ids = array_values(array_unique(array_map('intval', (array) $valid_ids)));
    sort($ids, SORT_NUMERIC);

    return $ids;
}

function ll_tools_content_lesson_resolve_wordset_id(
    $raw,
    ?bool &$complete = null
): int {
    global $wpdb;

    $complete = true;
    $wordset_id = max(0, (int) $raw);
    if ($wordset_id <= 0) {
        return 0;
    }

    $wpdb->last_error = '';
    $resolved_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT t.term_id
        FROM {$wpdb->terms} t
        INNER JOIN {$wpdb->term_taxonomy} tt
            ON tt.term_id = t.term_id
           AND tt.taxonomy = %s
        WHERE t.term_id = %d
        LIMIT 2",
        'wordset',
        $wordset_id
    ));
    if ($wpdb->last_error !== '' || count($resolved_ids) > 1) {
        $complete = false;
        return 0;
    }

    return !empty($resolved_ids) ? max(0, (int) $resolved_ids[0]) : 0;
}

function ll_tools_content_lesson_prerequisite_edge_limit(int $lesson_id = 0): int {
    return max(10, min(500, (int) apply_filters(
        'll_tools_content_lesson_prerequisite_edge_limit',
        200,
        $lesson_id
    )));
}

function ll_tools_content_lesson_normalize_mix_flag($raw): string {
    return !empty($raw) ? '1' : '';
}

function ll_tools_content_lesson_sanitize_source_text($raw): string {
    if (!is_string($raw)) {
        return '';
    }

    $value = wp_check_invalid_utf8($raw);
    $value = str_replace("\0", '', $value);
    $value = str_replace(["\r\n", "\r"], "\n", $value);

    return trim($value);
}

function ll_tools_content_lesson_clean_cue_text($text): string {
    if (is_array($text)) {
        $text = implode(' ', array_map('strval', $text));
    }

    $value = ll_tools_content_lesson_sanitize_source_text((string) $text);
    $value = preg_replace('/\s+/u', ' ', $value);
    $value = wp_strip_all_tags((string) $value, true);

    return trim((string) $value);
}

function ll_tools_content_lesson_parse_time_to_ms($raw, string $unit_hint = ''): int {
    if (is_numeric($raw)) {
        $number = (float) $raw;
        if ($number <= 0) {
            return 0;
        }

        if ($unit_hint === 'ms') {
            return (int) round($number);
        }

        if ($unit_hint === 'sec') {
            return (int) round($number * 1000);
        }

        return ($number >= 1000)
            ? (int) round($number)
            : (int) round($number * 1000);
    }

    $value = trim((string) $raw);
    if ($value === '') {
        return 0;
    }

    if (preg_match('/^([0-9]{1,2}:)?[0-9]{2}:[0-9]{2}[.,][0-9]{3}$/', $value)) {
        $value = str_replace(',', '.', $value);
        $parts = explode(':', $value);
        $parts = array_map('trim', $parts);
        if (count($parts) === 2) {
            array_unshift($parts, '0');
        }
        if (count($parts) !== 3) {
            return 0;
        }

        $hours = (int) $parts[0];
        $minutes = (int) $parts[1];
        $seconds = (float) $parts[2];

        return (int) round((($hours * 3600) + ($minutes * 60) + $seconds) * 1000);
    }

    $numeric = str_replace(',', '.', $value);
    if (is_numeric($numeric)) {
        return ll_tools_content_lesson_parse_time_to_ms((float) $numeric, $unit_hint);
    }

    return 0;
}

function ll_tools_content_lesson_build_cue(int $start_ms, int $end_ms, string $text, int $index): array {
    return [
        'id' => max(1, $index),
        'start_ms' => max(0, $start_ms),
        'end_ms' => max(0, $end_ms),
        'text' => $text,
    ];
}

function ll_tools_content_lesson_sort_cues(array $cues): array {
    usort($cues, static function (array $left, array $right): int {
        $left_start = (int) ($left['start_ms'] ?? 0);
        $right_start = (int) ($right['start_ms'] ?? 0);
        if ($left_start === $right_start) {
            return ((int) ($left['id'] ?? 0)) <=> ((int) ($right['id'] ?? 0));
        }
        return $left_start <=> $right_start;
    });

    $normalized = [];
    $index = 1;
    foreach ($cues as $cue) {
        $start_ms = max(0, (int) ($cue['start_ms'] ?? 0));
        $end_ms = max(0, (int) ($cue['end_ms'] ?? 0));
        $text = ll_tools_content_lesson_clean_cue_text((string) ($cue['text'] ?? ''));
        if ($text === '' || $end_ms <= $start_ms) {
            continue;
        }

        $normalized[] = ll_tools_content_lesson_build_cue($start_ms, $end_ms, $text, $index);
        $index++;
    }

    return $normalized;
}

function ll_tools_content_lesson_parse_vtt_source(string $raw): array {
    $lines = preg_split('/\n/', $raw);
    if (!is_array($lines)) {
        return [];
    }

    $cues = [];
    $cue_index = 1;
    $current_start = 0;
    $current_end = 0;
    $current_lines = [];
    $in_cue = false;

    $flush_cue = static function () use (&$cues, &$cue_index, &$current_start, &$current_end, &$current_lines, &$in_cue): void {
        if (!$in_cue) {
            return;
        }

        $text = ll_tools_content_lesson_clean_cue_text($current_lines);
        if ($text !== '' && $current_end > $current_start) {
            $cues[] = ll_tools_content_lesson_build_cue($current_start, $current_end, $text, $cue_index);
            $cue_index++;
        }

        $current_start = 0;
        $current_end = 0;
        $current_lines = [];
        $in_cue = false;
    };

    foreach ($lines as $line) {
        $line = trim((string) $line);
        if ($line === '') {
            $flush_cue();
            continue;
        }

        if (strcasecmp($line, 'WEBVTT') === 0 || strpos($line, 'NOTE') === 0) {
            continue;
        }

        if (preg_match('/^([0-9:\.,]+)\s*-->\s*([0-9:\.,]+)/', $line, $matches)) {
            $flush_cue();
            $current_start = ll_tools_content_lesson_parse_time_to_ms($matches[1]);
            $current_end = ll_tools_content_lesson_parse_time_to_ms($matches[2]);
            $current_lines = [];
            $in_cue = true;
            continue;
        }

        if (!$in_cue && ctype_digit($line)) {
            continue;
        }

        if ($in_cue) {
            $current_lines[] = $line;
        }
    }

    $flush_cue();

    return ll_tools_content_lesson_sort_cues($cues);
}

function ll_tools_content_lesson_parse_tsv_source(string $raw): array {
    $lines = preg_split('/\n/', $raw);
    if (!is_array($lines) || count($lines) < 2) {
        return [];
    }

    $header = str_getcsv((string) array_shift($lines), "\t", '"', '\\');
    if (!is_array($header) || empty($header)) {
        return [];
    }

    $header = array_map(static function ($value): string {
        return sanitize_key((string) $value);
    }, $header);
    $index_map = array_flip($header);

    $start_key = null;
    foreach (['start_ms', 'start_sec', 'start', 'begin'] as $candidate) {
        if (isset($index_map[$candidate])) {
            $start_key = $candidate;
            break;
        }
    }

    $end_key = null;
    foreach (['end_ms', 'end_sec', 'end', 'stop'] as $candidate) {
        if (isset($index_map[$candidate])) {
            $end_key = $candidate;
            break;
        }
    }

    $text_key = null;
    foreach (['text_projected', 'text_full', 'text', 'cue_text', 'transcript'] as $candidate) {
        if (isset($index_map[$candidate])) {
            $text_key = $candidate;
            break;
        }
    }

    if ($start_key === null || $end_key === null || $text_key === null) {
        return [];
    }

    $start_unit = ($start_key === 'start_ms') ? 'ms' : 'sec';
    $end_unit = ($end_key === 'end_ms') ? 'ms' : 'sec';
    $cues = [];
    $cue_index = 1;

    foreach ($lines as $line) {
        if (trim((string) $line) === '') {
            continue;
        }

        $row = str_getcsv((string) $line, "\t", '"', '\\');
        if (!is_array($row) || empty($row)) {
            continue;
        }

        $start_raw = $row[(int) $index_map[$start_key]] ?? '';
        $end_raw = $row[(int) $index_map[$end_key]] ?? '';
        $text_raw = $row[(int) $index_map[$text_key]] ?? '';

        $start_ms = ll_tools_content_lesson_parse_time_to_ms($start_raw, $start_unit);
        $end_ms = ll_tools_content_lesson_parse_time_to_ms($end_raw, $end_unit);
        $text = ll_tools_content_lesson_clean_cue_text((string) $text_raw);

        if ($text === '' || $end_ms <= $start_ms) {
            continue;
        }

        $cues[] = ll_tools_content_lesson_build_cue($start_ms, $end_ms, $text, $cue_index);
        $cue_index++;
    }

    return ll_tools_content_lesson_sort_cues($cues);
}

function ll_tools_content_lesson_parse_json_source(string $raw): array {
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }

    $rows = [];
    if (isset($decoded['lines']) && is_array($decoded['lines'])) {
        $rows = $decoded['lines'];
    } elseif (isset($decoded['sentences']) && is_array($decoded['sentences'])) {
        $rows = $decoded['sentences'];
    } elseif (isset($decoded['paragraphs']) && is_array($decoded['paragraphs'])) {
        $rows = $decoded['paragraphs'];
    } elseif (isset($decoded['cues']) && is_array($decoded['cues'])) {
        $rows = $decoded['cues'];
    } elseif (isset($decoded['items']) && is_array($decoded['items'])) {
        $rows = $decoded['items'];
    } elseif (ll_tools_array_is_list($decoded)) {
        $rows = $decoded;
    }

    if (!is_array($rows) || empty($rows)) {
        return [];
    }

    $cues = [];
    $cue_index = 1;
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $start_ms = ll_tools_content_lesson_parse_time_to_ms(
            $row['start_ms'] ?? ($row['start_sec'] ?? ($row['start'] ?? 0)),
            isset($row['start_ms']) ? 'ms' : 'sec'
        );
        $end_ms = ll_tools_content_lesson_parse_time_to_ms(
            $row['end_ms'] ?? ($row['end_sec'] ?? ($row['end'] ?? 0)),
            isset($row['end_ms']) ? 'ms' : 'sec'
        );
        $text = ll_tools_content_lesson_clean_cue_text(
            (string) ($row['text'] ?? ($row['text_projected'] ?? ($row['text_full'] ?? '')))
        );

        if ($text === '' || $end_ms <= $start_ms) {
            continue;
        }

        $cues[] = ll_tools_content_lesson_build_cue($start_ms, $end_ms, $text, $cue_index);
        $cue_index++;
    }

    return ll_tools_content_lesson_sort_cues($cues);
}

function ll_tools_content_lesson_parse_source(string $raw, string $format = 'auto') {
    $source = ll_tools_content_lesson_sanitize_source_text($raw);
    if ($source === '') {
        return [];
    }

    $format = ll_tools_content_lesson_sanitize_transcript_format($format);
    $detected_format = $format;
    if ($format === 'auto') {
        if (strpos($source, 'WEBVTT') === 0 || preg_match('/[0-9:\.,]+\s*-->\s*[0-9:\.,]+/', $source)) {
            $detected_format = 'vtt';
        } elseif (strpos($source, "\t") !== false) {
            $detected_format = 'tsv';
        } elseif (strpos(ltrim($source), '{') === 0 || strpos(ltrim($source), '[') === 0) {
            $detected_format = 'json';
        }
    }

    $cues = [];
    if ($detected_format === 'vtt') {
        $cues = ll_tools_content_lesson_parse_vtt_source($source);
    } elseif ($detected_format === 'tsv') {
        $cues = ll_tools_content_lesson_parse_tsv_source($source);
    } elseif ($detected_format === 'json') {
        $cues = ll_tools_content_lesson_parse_json_source($source);
    }

    if (empty($cues)) {
        return new WP_Error(
            'content_lesson_parse_failed',
            __('The transcript source could not be parsed. Paste WebVTT, TSV, or JSON timing data.', 'll-tools-text-domain')
        );
    }

    return $cues;
}

function ll_tools_get_content_lesson_wordset_id($lesson_id): int {
    return max(0, (int) get_post_meta((int) $lesson_id, LL_TOOLS_CONTENT_LESSON_WORDSET_META, true));
}

/**
 * Read the stored wordset without trusting the shared post-meta cache.
 *
 * Authorization checks use this bounded snapshot so a failed cache fill cannot
 * make an existing scoped lesson appear unscoped. Duplicate rows are treated as
 * incomplete because there is no unambiguous scope to authorize.
 */
function ll_tools_get_content_lesson_wordset_id_authoritative(
    int $lesson_id,
    ?bool &$complete = null
): int {
    global $wpdb;

    $complete = true;
    $lesson_id = max(0, $lesson_id);
    if ($lesson_id <= 0) {
        return 0;
    }

    $wpdb->last_error = '';
    $values = $wpdb->get_col($wpdb->prepare(
        "SELECT meta_value
        FROM {$wpdb->postmeta}
        WHERE post_id = %d
          AND meta_key = %s
        ORDER BY meta_id ASC
        LIMIT 2",
        $lesson_id,
        LL_TOOLS_CONTENT_LESSON_WORDSET_META
    ));
    if ($wpdb->last_error !== '' || count($values) > 1) {
        $complete = false;
        return 0;
    }
    if (empty($values)) {
        return 0;
    }

    $value = maybe_unserialize($values[0]);
    if (!is_scalar($value) || preg_match('/^\d+$/', (string) $value) !== 1) {
        $complete = false;
        return 0;
    }

    return max(0, (int) $value);
}

function ll_tools_get_content_lesson_kind($lesson_id): string {
    return ll_tools_content_lesson_sanitize_kind(
        (string) get_post_meta((int) $lesson_id, LL_TOOLS_CONTENT_LESSON_KIND_META, true)
    );
}

function ll_tools_content_lesson_is_corpus_text($lesson_id): bool {
    return ll_tools_get_content_lesson_kind((int) $lesson_id) === 'corpus_text';
}

function ll_tools_content_lesson_is_article($lesson_id): bool {
    return ll_tools_get_content_lesson_kind((int) $lesson_id) === 'article';
}

function ll_tools_get_content_lesson_media_type($lesson_id): string {
    return ll_tools_content_lesson_sanitize_media_type(
        (string) get_post_meta((int) $lesson_id, LL_TOOLS_CONTENT_LESSON_MEDIA_TYPE_META, true)
    );
}

function ll_tools_get_content_lesson_media_url($lesson_id): string {
    return esc_url((string) get_post_meta((int) $lesson_id, LL_TOOLS_CONTENT_LESSON_MEDIA_URL_META, true));
}

function ll_tools_get_content_lesson_transcript_format($lesson_id): string {
    return ll_tools_content_lesson_sanitize_transcript_format(
        (string) get_post_meta((int) $lesson_id, LL_TOOLS_CONTENT_LESSON_TRANSCRIPT_FORMAT_META, true)
    );
}

function ll_tools_get_content_lesson_transcript_source($lesson_id): string {
    return ll_tools_content_lesson_sanitize_source_text(
        (string) get_post_meta((int) $lesson_id, LL_TOOLS_CONTENT_LESSON_TRANSCRIPT_SOURCE_META, true)
    );
}

function ll_tools_get_content_lesson_cues($lesson_id): array {
    $raw = get_post_meta((int) $lesson_id, LL_TOOLS_CONTENT_LESSON_CUES_META, true);
    return is_array($raw) ? ll_tools_content_lesson_sort_cues($raw) : [];
}

function ll_tools_get_content_lesson_related_category_ids($lesson_id): array {
    $raw = get_post_meta((int) $lesson_id, LL_TOOLS_CONTENT_LESSON_CATEGORY_IDS_META, true);
    return ll_tools_content_lesson_normalize_category_ids(is_array($raw) ? $raw : []);
}

function ll_tools_get_content_lesson_parse_error($lesson_id): string {
    return sanitize_text_field((string) get_post_meta((int) $lesson_id, LL_TOOLS_CONTENT_LESSON_PARSE_ERROR_META, true));
}

function ll_tools_get_content_lesson_category_option_rows(int $wordset_id = 0): array {
    $wordset_id = max(0, $wordset_id);
    $rows = [];

    if (function_exists('ll_tools_get_word_category_selector_rows')) {
        $rows = ll_tools_get_word_category_selector_rows($wordset_id, [
            'post_types' => ['words'],
            'post_statuses' => ['publish'],
        ]);
    }

    if (empty($rows)) {
        $term_query = [
            'taxonomy' => 'word-category',
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC',
        ];
        if ($wordset_id > 0 && function_exists('ll_tools_get_word_category_ids_for_wordset_posts')) {
            $category_ids = ll_tools_get_word_category_ids_for_wordset_posts($wordset_id, ['words'], ['publish']);
            if (empty($category_ids)) {
                return [];
            }

            $term_query['include'] = $category_ids;
        }

        $terms = get_terms($term_query);

        if (is_wp_error($terms)) {
            $terms = [];
        }

        foreach ($terms as $term) {
            if (!($term instanceof WP_Term) || is_wp_error($term)) {
                continue;
            }

            $label = function_exists('ll_tools_get_category_display_name')
                ? ll_tools_get_category_display_name($term, ['wordset_ids' => $wordset_id > 0 ? [$wordset_id] : []])
                : $term->name;
            $rows[] = [
                'id' => (int) $term->term_id,
                'label' => (string) ($label !== '' ? $label : $term->name),
            ];
        }
    }

    $normalized = [];
    foreach ((array) $rows as $row) {
        $category_id = isset($row['id']) ? (int) $row['id'] : 0;
        if ($category_id <= 0) {
            continue;
        }

        $label = isset($row['label']) ? (string) $row['label'] : '';
        if ($label === '') {
            $term = get_term($category_id, 'word-category');
            if ($term instanceof WP_Term && !is_wp_error($term)) {
                $label = (string) $term->name;
            }
        }

        $source_id = function_exists('ll_tools_get_category_isolation_source_id')
            ? (int) ll_tools_get_category_isolation_source_id($category_id)
            : $category_id;
        if ($source_id <= 0) {
            $source_id = $category_id;
        }

        $normalized[$category_id] = [
            'id' => $category_id,
            'label' => $label,
            'source_id' => $source_id,
        ];
    }

    return array_values($normalized);
}

function ll_tools_content_lesson_filter_category_candidates_for_wordset(
    int $wordset_id,
    array $category_ids,
    bool $require_quizzable = false,
    ?bool &$complete = null
): array {
    global $wpdb;

    $complete = true;
    $wordset_id = max(0, $wordset_id);
    $identity_complete = true;
    $category_ids = ll_tools_content_lesson_normalize_category_ids(
        $category_ids,
        $identity_complete
    );
    if (!$identity_complete) {
        $complete = false;
        return [];
    }
    if ($wordset_id <= 0 || empty($category_ids)) {
        return ($wordset_id <= 0 && !$require_quizzable) ? $category_ids : [];
    }

    $id_placeholders = implode(',', array_fill(0, count($category_ids), '%d'));
    $status_placeholders = implode(',', array_fill(0, 1, '%s'));
    $sql = "
        SELECT DISTINCT tt_cat.term_id
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->term_relationships} tr_cat ON tr_cat.object_id = p.ID
        INNER JOIN {$wpdb->term_taxonomy} tt_cat
            ON tt_cat.term_taxonomy_id = tr_cat.term_taxonomy_id
           AND tt_cat.taxonomy = 'word-category'
        INNER JOIN {$wpdb->term_relationships} tr_ws ON tr_ws.object_id = p.ID
        INNER JOIN {$wpdb->term_taxonomy} tt_ws
            ON tt_ws.term_taxonomy_id = tr_ws.term_taxonomy_id
           AND tt_ws.taxonomy = 'wordset'
           AND tt_ws.term_id = %d
        WHERE p.post_type = 'words'
          AND p.post_status IN ({$status_placeholders})
          AND tt_cat.term_id IN ({$id_placeholders})
    ";
    $params = array_merge([$wordset_id, 'publish'], $category_ids);
    $wpdb->last_error = '';
    $allowed_ids = array_values(array_unique(array_map('intval', (array) $wpdb->get_col($wpdb->prepare($sql, $params)))));
    if ($wpdb->last_error !== '') {
        $complete = false;
        return [];
    }

    if ($require_quizzable && !empty($allowed_ids) && function_exists('ll_tools_user_study_filter_quizzable_category_ids')) {
        $quizzable_complete = true;
        $allowed_ids = ll_tools_user_study_filter_quizzable_category_ids(
            $allowed_ids,
            $wordset_id,
            $quizzable_complete
        );
        if (!$quizzable_complete) {
            $complete = false;
            return [];
        }

        $quizzable_identity_complete = true;
        $allowed_ids = ll_tools_content_lesson_normalize_category_ids(
            $allowed_ids,
            $quizzable_identity_complete
        );
        if (!$quizzable_identity_complete) {
            $complete = false;
            return [];
        }
    }

    $allowed_lookup = array_fill_keys($allowed_ids, true);
    return array_values(array_filter($category_ids, static function (int $category_id) use ($allowed_lookup): bool {
        return isset($allowed_lookup[$category_id]);
    }));
}

function ll_tools_content_lesson_category_rows_for_ids(array $category_ids, int $wordset_id = 0, bool $require_quizzable = false): array {
    $category_ids = ll_tools_content_lesson_normalize_category_ids($category_ids);
    if ($wordset_id > 0) {
        $category_ids = ll_tools_content_lesson_filter_category_candidates_for_wordset(
            $wordset_id,
            $category_ids,
            $require_quizzable
        );
    }
    if (empty($category_ids)) {
        return [];
    }

    $terms = get_terms([
        'taxonomy' => 'word-category',
        'hide_empty' => false,
        'include' => $category_ids,
    ]);
    if (is_wp_error($terms)) {
        return [];
    }
    if (function_exists('ll_tools_filter_category_terms_for_user')) {
        $terms = ll_tools_filter_category_terms_for_user((array) $terms);
    }

    $rows = [];
    foreach ((array) $terms as $term) {
        if (!($term instanceof WP_Term) || is_wp_error($term) || (string) $term->slug === 'uncategorized') {
            continue;
        }
        $label = function_exists('ll_tools_get_category_display_name')
            ? (string) ll_tools_get_category_display_name($term, ['wordset_ids' => $wordset_id > 0 ? [$wordset_id] : []])
            : (string) $term->name;
        $source_id = function_exists('ll_tools_get_category_isolation_source_id')
            ? (int) ll_tools_get_category_isolation_source_id((int) $term->term_id)
            : (int) $term->term_id;
        $rows[(int) $term->term_id] = [
            'id' => (int) $term->term_id,
            'label' => $label !== '' ? $label : (string) $term->name,
            'source_id' => $source_id > 0 ? $source_id : (int) $term->term_id,
        ];
    }

    $ordered = [];
    foreach ($category_ids as $category_id) {
        if (isset($rows[$category_id])) {
            $ordered[] = $rows[$category_id];
        }
    }
    return $ordered;
}

function ll_tools_content_lesson_option_lesson_label(WP_Post $post): string {
    $label = trim((string) get_the_title($post));
    if ($label === '') {
        $label = __('(no title)', 'll-tools-text-domain');
    }

    if ((string) $post->post_status !== 'publish') {
        $status_object = get_post_status_object((string) $post->post_status);
        $status_label = ($status_object && !empty($status_object->label))
            ? wp_strip_all_tags((string) $status_object->label)
            : '';
        if ($status_label !== '') {
            $label = sprintf(
                __('%1$s (%2$s)', 'll-tools-text-domain'),
                $label,
                $status_label
            );
        }
    }

    return $label;
}

function ll_tools_content_lesson_option_page(string $kind, int $wordset_id = 0, array $args = []): array {
    global $wpdb;

    $kind = sanitize_key($kind);
    $allowed_kinds = ['categories', 'prereq_categories', 'prereq_lessons'];
    if (!in_array($kind, $allowed_kinds, true)) {
        return ['rows' => [], 'has_more' => false, 'next_offset' => 0];
    }

    $wordset_id = max(0, $wordset_id);
    $requested_limit = isset($args['limit']) ? (int) $args['limit'] : 40;
    $limit = max(1, min(75, (int) apply_filters('ll_tools_content_lesson_option_page_size', $requested_limit, $kind)));
    $offset = isset($args['offset']) ? max(0, (int) $args['offset']) : 0;
    $search = isset($args['search']) ? sanitize_text_field((string) $args['search']) : '';
    $selected_ids = isset($args['selected_ids']) ? (array) $args['selected_ids'] : [];
    $exclude_lesson_id = isset($args['exclude_lesson_id']) ? max(0, (int) $args['exclude_lesson_id']) : 0;
    $rows = [];
    $has_more = false;
    $next_offset = $offset;

    if ($kind === 'prereq_lessons') {
        if ($wordset_id <= 0) {
            return ['rows' => [], 'has_more' => false, 'next_offset' => 0];
        }

        $query_args = [
            'post_type' => 'll_content_lesson',
            'post_status' => ['publish', 'draft', 'pending', 'future', 'private'],
            'posts_per_page' => $limit + 1,
            'offset' => $offset,
            'orderby' => 'menu_order title',
            'order' => 'ASC',
            'no_found_rows' => true,
            'post__not_in' => $exclude_lesson_id > 0 ? [$exclude_lesson_id] : [],
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => LL_TOOLS_CONTENT_LESSON_WORDSET_META,
                    'value' => (string) $wordset_id,
                ],
                ll_tools_legacy_lesson_retained_source_catalog_exclusion(),
            ],
        ];
        if ($search !== '') {
            $query_args['s'] = $search;
        }
        $posts = get_posts($query_args);
        $has_more = count($posts) > $limit;
        $posts = array_slice($posts, 0, $limit);
        $next_offset = $offset + count($posts);

        foreach ($posts as $post) {
            if (!($post instanceof WP_Post)) {
                continue;
            }
            $rows[(int) $post->ID] = [
                'id' => (int) $post->ID,
                'label' => ll_tools_content_lesson_option_lesson_label($post),
            ];
        }

        $selected_ids = ll_tools_filter_content_lesson_prereq_lesson_ids_for_wordset(
            $wordset_id,
            $selected_ids,
            $exclude_lesson_id
        );
        if (!empty($selected_ids)) {
            $selected_posts = get_posts([
                'post_type' => 'll_content_lesson',
                'post_status' => ['publish', 'draft', 'pending', 'future', 'private'],
                'posts_per_page' => count($selected_ids),
                'post__in' => $selected_ids,
                'orderby' => 'post__in',
                'no_found_rows' => true,
            ]);
            foreach ($selected_posts as $post) {
                if (!($post instanceof WP_Post)) {
                    continue;
                }
                $rows[(int) $post->ID] = [
                    'id' => (int) $post->ID,
                    'label' => ll_tools_content_lesson_option_lesson_label($post),
                ];
            }
        }
    } else {
        $query_params = [];
        $where = ["tt_cat.taxonomy = 'word-category'", "t.slug <> 'uncategorized'"];
        $joins = '';
        if ($wordset_id > 0) {
            $joins = "
                INNER JOIN {$wpdb->term_relationships} tr_cat ON tr_cat.term_taxonomy_id = tt_cat.term_taxonomy_id
                INNER JOIN {$wpdb->posts} p ON p.ID = tr_cat.object_id
                INNER JOIN {$wpdb->term_relationships} tr_ws ON tr_ws.object_id = p.ID
                INNER JOIN {$wpdb->term_taxonomy} tt_ws
                    ON tt_ws.term_taxonomy_id = tr_ws.term_taxonomy_id
                   AND tt_ws.taxonomy = 'wordset'
            ";
            $where[] = "p.post_type = 'words'";
            $where[] = "p.post_status = 'publish'";
            $where[] = 'tt_ws.term_id = %d';
            $query_params[] = $wordset_id;
        }
        if ($search !== '') {
            $where[] = 't.name LIKE %s';
            $query_params[] = '%' . $wpdb->esc_like($search) . '%';
        }
        $query_params[] = $limit + 1;
        $query_params[] = $offset;
        $sql = "
            SELECT DISTINCT t.term_id, t.name
            FROM {$wpdb->terms} t
            INNER JOIN {$wpdb->term_taxonomy} tt_cat ON tt_cat.term_id = t.term_id
            {$joins}
            WHERE " . implode(' AND ', $where) . "
            ORDER BY t.name ASC, t.term_id ASC
            LIMIT %d OFFSET %d
        ";
        $raw_rows = (array) $wpdb->get_results($wpdb->prepare($sql, $query_params));
        $has_more = count($raw_rows) > $limit;
        $raw_rows = array_slice($raw_rows, 0, $limit);
        $next_offset = $offset + count($raw_rows);
        $candidate_ids = array_values(array_filter(array_map(static function ($row): int {
            return isset($row->term_id) ? (int) $row->term_id : 0;
        }, $raw_rows)));

        if ($kind === 'prereq_categories' && $wordset_id > 0) {
            $candidate_ids = ll_tools_content_lesson_filter_category_candidates_for_wordset($wordset_id, $candidate_ids, true);
        }
        foreach (ll_tools_content_lesson_category_rows_for_ids($candidate_ids, $wordset_id, $kind === 'prereq_categories') as $row) {
            $rows[(int) $row['id']] = $row;
        }

        $selected_ids = ll_tools_content_lesson_normalize_category_ids($selected_ids);
        if ($wordset_id > 0 && function_exists('ll_tools_wordset_isolation_remap_category_id_list_for_wordset')) {
            $selected_ids = ll_tools_wordset_isolation_remap_category_id_list_for_wordset($selected_ids, $wordset_id, false);
        }
        foreach (ll_tools_content_lesson_category_rows_for_ids($selected_ids, $wordset_id, $kind === 'prereq_categories') as $row) {
            $rows[(int) $row['id']] = $row;
        }
    }

    return [
        'rows' => array_values($rows),
        'has_more' => $has_more,
        'next_offset' => $next_offset,
        'limit' => $limit,
    ];
}

function ll_tools_filter_content_lesson_category_ids_for_wordset(
    int $wordset_id = 0,
    array $category_ids = [],
    ?bool &$complete = null
): array {
    global $wpdb;

    $complete = true;
    $wordset_id = max(0, $wordset_id);
    $identity_complete = true;
    $category_ids = ll_tools_content_lesson_normalize_category_ids(
        $category_ids,
        $identity_complete
    );
    if (!$identity_complete) {
        $complete = false;
        return [];
    }

    if ($wordset_id <= 0) {
        return $category_ids;
    }

    if (!empty($category_ids)) {
        if (!function_exists('ll_tools_wordset_isolation_remap_category_id_list_for_wordset_complete')) {
            $complete = false;
            return [];
        }

        $wpdb->last_error = '';
        $remapped_category_ids = ll_tools_wordset_isolation_remap_category_id_list_for_wordset_complete(
            $category_ids,
            $wordset_id,
            false
        );
        if ($remapped_category_ids === null || $wpdb->last_error !== '') {
            $complete = false;
            return [];
        }
        $category_ids = $remapped_category_ids;
    }

    $filter_complete = true;
    $filtered = ll_tools_content_lesson_filter_category_candidates_for_wordset(
        $wordset_id,
        $category_ids,
        false,
        $filter_complete
    );
    $complete = $filter_complete;
    return $filtered;
}

function ll_tools_get_content_lesson_prereq_option_rows(int $wordset_id = 0): array {
    $wordset_id = max(0, $wordset_id);
    if ($wordset_id <= 0 || !function_exists('ll_tools_wordset_get_admin_category_ordering_rows')) {
        return [];
    }

    $rows = [];
    foreach ((array) ll_tools_wordset_get_admin_category_ordering_rows($wordset_id) as $row) {
        if (!is_array($row)) {
            continue;
        }

        $category_id = isset($row['id']) ? (int) $row['id'] : 0;
        if ($category_id <= 0) {
            continue;
        }

        $label = isset($row['name']) ? (string) $row['name'] : '';
        if ($label === '') {
            $term = get_term($category_id, 'word-category');
            if ($term instanceof WP_Term && !is_wp_error($term)) {
                $label = function_exists('ll_tools_get_category_display_name')
                    ? (string) ll_tools_get_category_display_name($term, ['wordset_ids' => [$wordset_id]])
                    : (string) $term->name;
            }
        }

        if ($label === '') {
            continue;
        }

        $rows[] = [
            'id' => $category_id,
            'label' => $label,
        ];
    }

    return $rows;
}

function ll_tools_get_content_lesson_prereq_lesson_option_rows(int $wordset_id = 0, int $exclude_lesson_id = 0): array {
    $wordset_id = max(0, $wordset_id);
    $exclude_lesson_id = max(0, $exclude_lesson_id);
    if ($wordset_id <= 0) {
        return [];
    }

    $page = ll_tools_content_lesson_option_page(
        'prereq_lessons',
        $wordset_id,
        [
            'exclude_lesson_id' => $exclude_lesson_id,
        ]
    );
    return (array) ($page['rows'] ?? []);
}

function ll_tools_filter_content_lesson_prereq_category_ids_for_wordset(
    int $wordset_id = 0,
    array $category_ids = [],
    ?bool &$complete = null
): array {
    global $wpdb;

    $complete = true;
    $wordset_id = max(0, $wordset_id);
    $identity_complete = true;
    $category_ids = ll_tools_content_lesson_normalize_category_ids(
        $category_ids,
        $identity_complete
    );
    if (!$identity_complete) {
        $complete = false;
        return [];
    }

    if ($wordset_id <= 0) {
        return [];
    }

    if (!empty($category_ids)) {
        if (!function_exists('ll_tools_wordset_isolation_remap_category_id_list_for_wordset_complete')) {
            $complete = false;
            return [];
        }

        $wpdb->last_error = '';
        $remapped_category_ids = ll_tools_wordset_isolation_remap_category_id_list_for_wordset_complete(
            $category_ids,
            $wordset_id,
            false
        );
        if ($remapped_category_ids === null || $wpdb->last_error !== '') {
            $complete = false;
            return [];
        }
        $category_ids = $remapped_category_ids;
    }

    $filter_complete = true;
    $filtered = ll_tools_content_lesson_filter_category_candidates_for_wordset(
        $wordset_id,
        $category_ids,
        true,
        $filter_complete
    );
    $complete = $filter_complete;
    return $filtered;
}

function ll_tools_filter_content_lesson_prereq_lesson_ids_for_wordset(
    int $wordset_id = 0,
    array $lesson_ids = [],
    int $exclude_lesson_id = 0,
    ?bool &$complete = null
): array {
    global $wpdb;

    $complete = true;
    $wordset_id = max(0, $wordset_id);
    $exclude_lesson_id = max(0, $exclude_lesson_id);
    $identity_complete = true;
    $lesson_ids = ll_tools_content_lesson_normalize_lesson_ids(
        $lesson_ids,
        $identity_complete
    );
    if (!$identity_complete) {
        $complete = false;
        return [];
    }
    if (count($lesson_ids) > ll_tools_content_lesson_prerequisite_edge_limit($exclude_lesson_id)) {
        $complete = false;
        return [];
    }

    if ($wordset_id <= 0 || empty($lesson_ids)) {
        return [];
    }

    $wpdb->last_error = '';
    $allowed_lesson_ids = get_posts([
        'post_type' => 'll_content_lesson',
        'post_status' => ['publish', 'draft', 'pending', 'future', 'private'],
        'posts_per_page' => count($lesson_ids),
        'fields' => 'ids',
        'no_found_rows' => true,
        'orderby' => 'post__in',
        'post__in' => $lesson_ids,
        'post__not_in' => $exclude_lesson_id > 0 ? [$exclude_lesson_id] : [],
        'cache_results' => false,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
        'meta_query' => [
            [
                'key' => LL_TOOLS_CONTENT_LESSON_WORDSET_META,
                'value' => (string) $wordset_id,
            ],
        ],
    ]);
    if ($wpdb->last_error !== '') {
        $complete = false;
        return [];
    }
    $allowed_lesson_ids = array_fill_keys(array_map('intval', (array) $allowed_lesson_ids), true);

    if (empty($allowed_lesson_ids)) {
        return [];
    }

    return array_values(array_filter($lesson_ids, static function (int $lesson_id) use ($allowed_lesson_ids): bool {
        return !empty($allowed_lesson_ids[$lesson_id]);
    }));
}

function ll_tools_get_content_lesson_show_in_mix($lesson_id): bool {
    if (ll_tools_legacy_lesson_has_retained_source_marker((int) $lesson_id)) {
        return false;
    }

    return ll_tools_content_lesson_normalize_mix_flag(
        get_post_meta((int) $lesson_id, LL_TOOLS_CONTENT_LESSON_SHOW_IN_MIX_META, true)
    ) === '1';
}

function ll_tools_get_content_lesson_prereq_category_ids($lesson_id): array {
    $lesson_id = (int) $lesson_id;
    if ($lesson_id <= 0) {
        return [];
    }

    $raw = get_post_meta($lesson_id, LL_TOOLS_CONTENT_LESSON_PREREQ_CATEGORY_IDS_META, true);
    if (function_exists('ll_tools_wordset_parse_id_list_meta')) {
        $category_ids = ll_tools_wordset_parse_id_list_meta($raw);
    } else {
        $category_ids = ll_tools_content_lesson_normalize_category_ids(is_array($raw) ? $raw : [$raw]);
    }

    return ll_tools_filter_content_lesson_prereq_category_ids_for_wordset(
        ll_tools_get_content_lesson_wordset_id($lesson_id),
        $category_ids
    );
}

function ll_tools_get_content_lesson_prereq_lesson_ids($lesson_id): array {
    $lesson_id = (int) $lesson_id;
    if ($lesson_id <= 0) {
        return [];
    }

    $raw = get_post_meta($lesson_id, LL_TOOLS_CONTENT_LESSON_PREREQ_LESSON_IDS_META, true);
    if (function_exists('ll_tools_wordset_parse_id_list_meta')) {
        $lesson_ids = ll_tools_wordset_parse_id_list_meta($raw);
    } else {
        $lesson_ids = ll_tools_content_lesson_normalize_lesson_ids(is_array($raw) ? $raw : [$raw]);
    }

    return ll_tools_filter_content_lesson_prereq_lesson_ids_for_wordset(
        ll_tools_get_content_lesson_wordset_id($lesson_id),
        $lesson_ids,
        $lesson_id
    );
}

/**
 * Validate one proposed content-lesson dependency list without scanning every
 * lesson in the word set. Only the selected dependency branches are walked.
 *
 * @return true|WP_Error
 */
function ll_tools_validate_content_lesson_prerequisite_graph(
    int $lesson_id,
    int $wordset_id,
    array $prerequisite_lesson_ids
) {
    global $wpdb;

    $lesson_id = max(0, $lesson_id);
    $wordset_id = max(0, $wordset_id);
    $node_limit = max(25, min(2000, (int) apply_filters(
        'll_tools_content_lesson_prerequisite_graph_node_limit',
        500,
        $lesson_id,
        $wordset_id
    )));
    $edge_limit = ll_tools_content_lesson_prerequisite_edge_limit($lesson_id);
    if (count($prerequisite_lesson_ids) > $edge_limit
        || count($prerequisite_lesson_ids) > $node_limit
    ) {
        return new WP_Error(
            'content_lesson_prerequisite_graph_too_large',
            __('The prerequisite graph is too large to validate safely.', 'll-tools-text-domain')
        );
    }
    $initial_filter_complete = true;
    $prerequisite_lesson_ids = ll_tools_filter_content_lesson_prereq_lesson_ids_for_wordset(
        $wordset_id,
        $prerequisite_lesson_ids,
        $lesson_id,
        $initial_filter_complete
    );
    if (!$initial_filter_complete) {
        return new WP_Error(
            'content_lesson_relation_query_incomplete',
            __('The prerequisite relationships could not be read completely.', 'll-tools-text-domain')
        );
    }
    if ($lesson_id <= 0 || $wordset_id <= 0 || empty($prerequisite_lesson_ids)) {
        return true;
    }

    $queue = array_values($prerequisite_lesson_ids);
    $queued = array_fill_keys($queue, true);
    $visited = [];
    for ($offset = 0; $offset < count($queue); $offset++) {
        $candidate_id = (int) $queue[$offset];
        if ($candidate_id === $lesson_id) {
            return new WP_Error(
                'content_lesson_prerequisite_cycle',
                __('These prerequisites would create a lesson loop.', 'll-tools-text-domain')
            );
        }
        if ($candidate_id <= 0 || isset($visited[$candidate_id])) {
            continue;
        }

        $visited[$candidate_id] = true;
        if (count($visited) > $node_limit) {
            return new WP_Error(
                'content_lesson_prerequisite_graph_too_large',
                __('The prerequisite graph is too large to validate safely.', 'll-tools-text-domain')
            );
        }

        $wpdb->last_error = '';
        $candidate_wordset_id = ll_tools_get_content_lesson_wordset_id($candidate_id);
        if ($wpdb->last_error !== '') {
            return new WP_Error(
                'content_lesson_relation_query_incomplete',
                __('The prerequisite relationships could not be read completely.', 'll-tools-text-domain')
            );
        }
        if ($candidate_wordset_id !== $wordset_id) {
            continue;
        }

        $wpdb->last_error = '';
        $stored_raw = get_post_meta(
            $candidate_id,
            LL_TOOLS_CONTENT_LESSON_PREREQ_LESSON_IDS_META,
            true
        );
        if ($wpdb->last_error !== '') {
            return new WP_Error(
                'content_lesson_relation_query_incomplete',
                __('The prerequisite relationships could not be read completely.', 'll-tools-text-domain')
            );
        }
        $stored_ids = function_exists('ll_tools_wordset_parse_id_list_meta')
            ? ll_tools_wordset_parse_id_list_meta($stored_raw)
            : ll_tools_content_lesson_normalize_lesson_ids($stored_raw);
        if (count($stored_ids) > $edge_limit) {
            return new WP_Error(
                'content_lesson_prerequisite_graph_too_large',
                __('The prerequisite graph is too large to validate safely.', 'll-tools-text-domain')
            );
        }
        if (in_array($lesson_id, $stored_ids, true)) {
            return new WP_Error(
                'content_lesson_prerequisite_cycle',
                __('These prerequisites would create a lesson loop.', 'll-tools-text-domain')
            );
        }
        $stored_filter_complete = true;
        $stored_ids = ll_tools_filter_content_lesson_prereq_lesson_ids_for_wordset(
            $wordset_id,
            $stored_ids,
            $candidate_id,
            $stored_filter_complete
        );
        if (!$stored_filter_complete) {
            return new WP_Error(
                'content_lesson_relation_query_incomplete',
                __('The prerequisite relationships could not be read completely.', 'll-tools-text-domain')
            );
        }
        foreach ($stored_ids as $stored_id) {
            $stored_id = (int) $stored_id;
            if ($stored_id === $lesson_id) {
                return new WP_Error(
                    'content_lesson_prerequisite_cycle',
                    __('These prerequisites would create a lesson loop.', 'll-tools-text-domain')
                );
            }
            if ($stored_id > 0 && !isset($visited[$stored_id]) && !isset($queued[$stored_id])) {
                if (count($queued) >= $node_limit) {
                    return new WP_Error(
                        'content_lesson_prerequisite_graph_too_large',
                        __('The prerequisite graph is too large to validate safely.', 'll-tools-text-domain')
                    );
                }
                $queue[] = $stored_id;
                $queued[$stored_id] = true;
            }
        }
    }

    return true;
}

function ll_tools_get_content_lesson_relation_error($lesson_id): string {
    $error_code = sanitize_key((string) get_post_meta(
        (int) $lesson_id,
        LL_TOOLS_CONTENT_LESSON_RELATION_ERROR_META,
        true
    ));
    if ($error_code === 'content_lesson_prerequisite_cycle') {
        return __('The prerequisite change was not saved because it would create a lesson loop.', 'll-tools-text-domain');
    }
    if ($error_code === 'content_lesson_prerequisite_graph_too_large') {
        return __('The prerequisite change was not saved because the dependency graph exceeded the safe validation limit.', 'll-tools-text-domain');
    }
    if ($error_code === 'content_lesson_relation_query_incomplete') {
        return __('The prerequisite change was not saved because its relationships could not be read completely. Please try again.', 'll-tools-text-domain');
    }

    return '';
}

function ll_tools_get_content_lesson_selectable_prereq_rows(int $wordset_id = 0, array $selected_ids = []): array {
    $wordset_id = max(0, $wordset_id);
    $selected_ids = ll_tools_content_lesson_normalize_category_ids($selected_ids);
    $selected_ids = ll_tools_filter_content_lesson_prereq_category_ids_for_wordset($wordset_id, $selected_ids);
    $rows = ll_tools_get_content_lesson_prereq_option_rows($wordset_id);

    if (empty($selected_ids) || $wordset_id <= 0) {
        return $rows;
    }

    $existing_ids = [];
    foreach ($rows as $row) {
        $existing_id = isset($row['id']) ? (int) $row['id'] : 0;
        if ($existing_id > 0) {
            $existing_ids[$existing_id] = true;
        }
    }

    $missing_ids = array_values(array_filter($selected_ids, static function (int $category_id) use ($existing_ids): bool {
        return empty($existing_ids[$category_id]);
    }));
    if (empty($missing_ids)) {
        return $rows;
    }

    $terms = get_terms([
        'taxonomy' => 'word-category',
        'include' => $missing_ids,
        'hide_empty' => false,
    ]);
    if (is_wp_error($terms) || empty($terms)) {
        return $rows;
    }

    foreach ($terms as $term) {
        if (!($term instanceof WP_Term) || is_wp_error($term)) {
            continue;
        }

        $label = function_exists('ll_tools_get_category_display_name')
            ? ll_tools_get_category_display_name($term, ['wordset_ids' => [$wordset_id]])
            : $term->name;
        if ($label === '') {
            $label = $term->name;
        }

        $rows[] = [
            'id' => (int) $term->term_id,
            'label' => (string) $label,
        ];
    }

    usort($rows, static function (array $left, array $right): int {
        $left_label = (string) ($left['label'] ?? '');
        $right_label = (string) ($right['label'] ?? '');
        $cmp = function_exists('ll_tools_locale_compare_strings')
            ? ll_tools_locale_compare_strings($left_label, $right_label)
            : strnatcasecmp($left_label, $right_label);
        if ($cmp !== 0) {
            return $cmp;
        }

        return ((int) ($left['id'] ?? 0)) <=> ((int) ($right['id'] ?? 0));
    });

    return $rows;
}

function ll_tools_get_content_lesson_selectable_prereq_lesson_rows(int $wordset_id = 0, array $selected_ids = [], int $exclude_lesson_id = 0): array {
    $wordset_id = max(0, $wordset_id);
    $exclude_lesson_id = max(0, $exclude_lesson_id);
    $selected_ids = ll_tools_content_lesson_normalize_lesson_ids($selected_ids);
    $selected_ids = ll_tools_filter_content_lesson_prereq_lesson_ids_for_wordset($wordset_id, $selected_ids, $exclude_lesson_id);
    $rows = ll_tools_get_content_lesson_prereq_lesson_option_rows($wordset_id, $exclude_lesson_id);

    if (empty($selected_ids) || $wordset_id <= 0) {
        return $rows;
    }

    $existing_ids = [];
    foreach ($rows as $row) {
        $existing_id = isset($row['id']) ? (int) $row['id'] : 0;
        if ($existing_id > 0) {
            $existing_ids[$existing_id] = true;
        }
    }

    $missing_ids = array_values(array_filter($selected_ids, static function (int $lesson_id) use ($existing_ids): bool {
        return empty($existing_ids[$lesson_id]);
    }));
    if (empty($missing_ids)) {
        return $rows;
    }

    $posts = get_posts([
        'post_type' => 'll_content_lesson',
        'post_status' => ['publish', 'draft', 'pending', 'future', 'private'],
        'posts_per_page' => -1,
        'orderby' => 'menu_order title',
        'order' => 'ASC',
        'no_found_rows' => true,
        'post__in' => $missing_ids,
    ]);
    if (empty($posts)) {
        return $rows;
    }

    foreach ((array) $posts as $post) {
        if (!($post instanceof WP_Post) || $post->post_type !== 'll_content_lesson') {
            continue;
        }

        $label = trim((string) get_the_title($post));
        if ($label === '') {
            $label = __('(no title)', 'll-tools-text-domain');
        }

        $rows[] = [
            'id' => (int) $post->ID,
            'label' => $label,
        ];
    }

    return $rows;
}

function ll_tools_get_content_lesson_selectable_category_rows(int $wordset_id = 0, array $selected_ids = []): array {
    $wordset_id = max(0, $wordset_id);
    $selected_ids = ll_tools_content_lesson_normalize_category_ids($selected_ids);
    $rows = ll_tools_get_content_lesson_category_option_rows($wordset_id);

    if ($wordset_id > 0 || empty($selected_ids)) {
        return $rows;
    }

    $existing_ids = [];
    foreach ($rows as $row) {
        $existing_id = isset($row['id']) ? (int) $row['id'] : 0;
        if ($existing_id > 0) {
            $existing_ids[$existing_id] = true;
        }
    }

    $missing_selected_ids = array_values(array_filter($selected_ids, static function (int $category_id) use ($existing_ids): bool {
        return empty($existing_ids[$category_id]);
    }));
    if (empty($missing_selected_ids)) {
        return $rows;
    }

    $missing_terms = get_terms([
        'taxonomy' => 'word-category',
        'include' => $missing_selected_ids,
        'hide_empty' => false,
    ]);
    if (is_wp_error($missing_terms) || empty($missing_terms)) {
        return $rows;
    }

    foreach ($missing_terms as $term) {
        if (!($term instanceof WP_Term) || is_wp_error($term)) {
            continue;
        }

        $label = function_exists('ll_tools_get_category_display_name')
            ? ll_tools_get_category_display_name($term)
            : $term->name;
        $source_id = function_exists('ll_tools_get_category_isolation_source_id')
            ? (int) ll_tools_get_category_isolation_source_id((int) $term->term_id)
            : (int) $term->term_id;
        if ($source_id <= 0) {
            $source_id = (int) $term->term_id;
        }

        $rows[] = [
            'id' => (int) $term->term_id,
            'label' => (string) ($label !== '' ? $label : $term->name),
            'source_id' => $source_id,
        ];
    }

    usort($rows, static function (array $left, array $right): int {
        $left_label = (string) ($left['label'] ?? '');
        $right_label = (string) ($right['label'] ?? '');
        $cmp = function_exists('ll_tools_locale_compare_strings')
            ? ll_tools_locale_compare_strings($left_label, $right_label)
            : strnatcasecmp($left_label, $right_label);
        if ($cmp !== 0) {
            return $cmp;
        }

        $left_id = (int) ($left['id'] ?? 0);
        $right_id = (int) ($right['id'] ?? 0);
        return $left_id <=> $right_id;
    });

    return $rows;
}

function ll_tools_enqueue_content_lesson_admin_assets($hook): void {
    global $typenow;

    if (!in_array($hook, ['post.php', 'post-new.php'], true) || $typenow !== 'll_content_lesson') {
        return;
    }

    ll_enqueue_asset_by_timestamp('/js/content-lesson-admin.js', 'll-tools-content-lesson-admin', ['jquery'], true);
    ll_enqueue_asset_by_timestamp('/css/content-lesson-admin.css', 'll-tools-content-lesson-admin', [], false);

    $current_lesson_id = 0;
    if (isset($_GET['post'])) {
        $current_lesson_id = absint(wp_unslash((string) $_GET['post']));
    } elseif (isset($_POST['post_ID'])) {
        $current_lesson_id = absint(wp_unslash((string) $_POST['post_ID']));
    }
    if ($current_lesson_id > 0 && get_post_type($current_lesson_id) !== 'll_content_lesson') {
        $current_lesson_id = 0;
    }

    $current_wordset_id = $current_lesson_id > 0
        ? ll_tools_get_content_lesson_wordset_id($current_lesson_id)
        : 0;
    $current_category_ids = $current_lesson_id > 0
        ? ll_tools_get_content_lesson_related_category_ids($current_lesson_id)
        : [];
    $current_prereq_category_ids = $current_lesson_id > 0
        ? ll_tools_get_content_lesson_prereq_category_ids($current_lesson_id)
        : [];
    $current_prereq_lesson_ids = $current_lesson_id > 0
        ? ll_tools_get_content_lesson_prereq_lesson_ids($current_lesson_id)
        : [];

    $category_page = ll_tools_content_lesson_option_page('categories', $current_wordset_id, [
        'selected_ids' => $current_category_ids,
    ]);
    $prereq_page = ll_tools_content_lesson_option_page('prereq_categories', $current_wordset_id, [
        'selected_ids' => $current_prereq_category_ids,
    ]);
    $prereq_lesson_page = ll_tools_content_lesson_option_page('prereq_lessons', $current_wordset_id, [
        'selected_ids' => $current_prereq_lesson_ids,
        'exclude_lesson_id' => $current_lesson_id,
    ]);
    $wordset_key = (string) $current_wordset_id;

    wp_localize_script('ll-tools-content-lesson-admin', 'llContentLessonAdminData', [
        'rowsByWordset' => [$wordset_key => (array) $category_page['rows']],
        'prereqRowsByWordset' => [$wordset_key => (array) $prereq_page['rows']],
        'prereqLessonRowsByWordset' => [$wordset_key => (array) $prereq_lesson_page['rows']],
        'pageStateByKind' => [
            'categories' => [$wordset_key => [
                'has_more' => (bool) $category_page['has_more'],
                'next_offset' => (int) $category_page['next_offset'],
                'limit' => (int) $category_page['limit'],
            ]],
            'prereq_categories' => [$wordset_key => [
                'has_more' => (bool) $prereq_page['has_more'],
                'next_offset' => (int) $prereq_page['next_offset'],
                'limit' => (int) $prereq_page['limit'],
            ]],
            'prereq_lessons' => [$wordset_key => [
                'has_more' => (bool) $prereq_lesson_page['has_more'],
                'next_offset' => (int) $prereq_lesson_page['next_offset'],
                'limit' => (int) $prereq_lesson_page['limit'],
            ]],
        ],
        'currentLessonId' => $current_lesson_id,
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('ll_tools_content_lesson_options'),
        'strings' => [
            'search' => __('Search', 'll-tools-text-domain'),
            'loadMore' => __('Load more', 'll-tools-text-domain'),
            'loading' => __('Loading...', 'll-tools-text-domain'),
            'loadFailed' => __('Options could not be loaded.', 'll-tools-text-domain'),
        ],
    ]);
}
add_action('admin_enqueue_scripts', 'll_tools_enqueue_content_lesson_admin_assets');

function ll_tools_ajax_content_lesson_options(): void {
    if (!current_user_can('view_ll_tools')) {
        wp_send_json_error(['message' => __('You are not allowed to edit content lessons.', 'll-tools-text-domain')], 403);
    }
    check_ajax_referer('ll_tools_content_lesson_options', 'nonce');

    $kind = isset($_POST['kind']) ? sanitize_key((string) wp_unslash($_POST['kind'])) : '';
    $wordset_id = isset($_POST['wordset_id']) ? absint(wp_unslash((string) $_POST['wordset_id'])) : 0;
    if ($wordset_id > 0) {
        $wordset = get_term($wordset_id, 'wordset');
        if (!($wordset instanceof WP_Term) || is_wp_error($wordset)) {
            wp_send_json_error(['message' => __('Select a valid word set.', 'll-tools-text-domain')], 400);
        }
        if (!ll_tools_user_can_manage_wordset_content($wordset_id, get_current_user_id())) {
            wp_send_json_error(['message' => __('You do not have permission to manage this word set.', 'll-tools-text-domain')], 403);
        }
    }

    $selected_ids = isset($_POST['selected_ids']) ? (array) wp_unslash($_POST['selected_ids']) : [];
    $selected_ids = array_slice(array_values($selected_ids), 0, 200);
    $page = ll_tools_content_lesson_option_page($kind, $wordset_id, [
        'search' => isset($_POST['search']) ? wp_unslash((string) $_POST['search']) : '',
        'offset' => isset($_POST['offset']) ? absint(wp_unslash((string) $_POST['offset'])) : 0,
        'selected_ids' => $selected_ids,
        'exclude_lesson_id' => isset($_POST['exclude_lesson_id']) ? absint(wp_unslash((string) $_POST['exclude_lesson_id'])) : 0,
    ]);

    wp_send_json_success($page);
}
add_action('wp_ajax_ll_tools_content_lesson_options', 'll_tools_ajax_content_lesson_options');

function ll_tools_add_content_lesson_metabox(): void {
    add_meta_box(
        'll-tools-content-lesson-details',
        __('Lesson Details', 'll-tools-text-domain'),
        'll_tools_render_content_lesson_metabox',
        'll_content_lesson',
        'normal',
        'high'
    );
}
add_action('add_meta_boxes_ll_content_lesson', 'll_tools_add_content_lesson_metabox');

function ll_tools_render_content_lesson_metabox($post): void {
    if (!($post instanceof WP_Post) || $post->post_type !== 'll_content_lesson') {
        return;
    }
    if (!current_user_can('view_ll_tools')) {
        return;
    }

    wp_nonce_field('ll_tools_content_lesson_save', 'll_tools_content_lesson_nonce');

    $wordset_id = ll_tools_get_content_lesson_wordset_id((int) $post->ID);
    $lesson_kind = ll_tools_get_content_lesson_kind((int) $post->ID);
    $media_type = ll_tools_get_content_lesson_media_type((int) $post->ID);
    $media_url = (string) get_post_meta((int) $post->ID, LL_TOOLS_CONTENT_LESSON_MEDIA_URL_META, true);
    $transcript_format = ll_tools_get_content_lesson_transcript_format((int) $post->ID);
    $transcript_source = ll_tools_get_content_lesson_transcript_source((int) $post->ID);
    $category_ids = ll_tools_filter_content_lesson_category_ids_for_wordset(
        $wordset_id,
        ll_tools_get_content_lesson_related_category_ids((int) $post->ID)
    );
    $show_in_mix = ll_tools_get_content_lesson_show_in_mix((int) $post->ID);
    $prereq_category_ids = ll_tools_get_content_lesson_prereq_category_ids((int) $post->ID);
    $prereq_lesson_ids = ll_tools_get_content_lesson_prereq_lesson_ids((int) $post->ID);
    $cue_count = count(ll_tools_get_content_lesson_cues((int) $post->ID));
    $parse_error = ll_tools_get_content_lesson_parse_error((int) $post->ID);
    $relation_error = ll_tools_get_content_lesson_relation_error((int) $post->ID);
    $category_rows = (array) ll_tools_content_lesson_option_page('categories', $wordset_id, [
        'selected_ids' => $category_ids,
    ])['rows'];
    $prereq_rows = (array) ll_tools_content_lesson_option_page('prereq_categories', $wordset_id, [
        'selected_ids' => $prereq_category_ids,
    ])['rows'];
    $prereq_lesson_rows = (array) ll_tools_content_lesson_option_page('prereq_lessons', $wordset_id, [
        'selected_ids' => $prereq_lesson_ids,
        'exclude_lesson_id' => (int) $post->ID,
    ])['rows'];
    $wordsets = get_terms([
        'taxonomy' => 'wordset',
        'hide_empty' => false,
        'orderby' => 'name',
        'order' => 'ASC',
    ]);
    if (is_wp_error($wordsets)) {
        $wordsets = [];
    }
    ?>
    <p class="description">
        <?php esc_html_e('Create an article, audio, or video lesson and connect it to the appropriate word set and learning sequence.', 'll-tools-text-domain'); ?>
    </p>
    <?php if ($relation_error !== '') : ?>
        <div class="notice notice-error inline">
            <p><?php echo esc_html($relation_error); ?></p>
        </div>
    <?php endif; ?>

    <table class="form-table" role="presentation">
        <tbody>
            <tr>
                <th scope="row">
                    <label for="ll-content-lesson-wordset"><?php esc_html_e('Word set', 'll-tools-text-domain'); ?></label>
                </th>
                <td>
                    <select id="ll-content-lesson-wordset" name="ll_content_lesson_wordset_id" class="regular-text">
                        <option value="0"><?php esc_html_e('Select a word set', 'll-tools-text-domain'); ?></option>
                        <?php foreach ((array) $wordsets as $wordset) : ?>
                            <?php if ($wordset instanceof WP_Term) : ?>
                                <option value="<?php echo esc_attr((string) $wordset->term_id); ?>" <?php selected($wordset_id, (int) $wordset->term_id); ?>>
                                    <?php echo esc_html($wordset->name); ?>
                                </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">
                        <?php esc_html_e('Used for front-end access checks and to surface this lesson on the word set page.', 'll-tools-text-domain'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="ll-content-lesson-kind"><?php esc_html_e('Lesson kind', 'll-tools-text-domain'); ?></label>
                </th>
                <td>
                    <select id="ll-content-lesson-kind" name="ll_content_lesson_kind" class="regular-text">
                        <?php foreach (ll_tools_content_lesson_kind_options() as $kind_value => $kind_label) : ?>
                            <option value="<?php echo esc_attr((string) $kind_value); ?>" <?php selected($lesson_kind, (string) $kind_value); ?>>
                                <?php echo esc_html((string) $kind_label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">
                        <?php esc_html_e('Use Article for a text-first lesson with no media player. Corpus text remains reserved for editions, translations, and linguist-facing interlinear payloads.', 'll-tools-text-domain'); ?>
                    </p>
                </td>
            </tr>
            <tr data-ll-content-lesson-media-setting>
                <th scope="row">
                    <label for="ll-content-lesson-media-type"><?php esc_html_e('Media type', 'll-tools-text-domain'); ?></label>
                </th>
                <td>
                    <select id="ll-content-lesson-media-type" name="ll_content_lesson_media_type" class="regular-text">
                        <option value="audio" <?php selected($media_type, 'audio'); ?>><?php esc_html_e('Audio', 'll-tools-text-domain'); ?></option>
                        <option value="video" <?php selected($media_type, 'video'); ?>><?php esc_html_e('Video', 'll-tools-text-domain'); ?></option>
                    </select>
                </td>
            </tr>
            <tr data-ll-content-lesson-media-setting>
                <th scope="row">
                    <label for="ll-content-lesson-media-url"><?php esc_html_e('Media URL', 'll-tools-text-domain'); ?></label>
                </th>
                <td>
                    <input
                        type="url"
                        id="ll-content-lesson-media-url"
                        name="ll_content_lesson_media_url"
                        value="<?php echo esc_attr($media_url); ?>"
                        class="regular-text code"
                        placeholder="<?php echo esc_attr__('Paste the direct media URL here.', 'll-tools-text-domain'); ?>"
                    />
                    <p class="description">
                        <?php esc_html_e('Use a direct file URL for the audio or video you want to play on the lesson page.', 'll-tools-text-domain'); ?>
                    </p>
                </td>
            </tr>
            <tr data-ll-content-lesson-media-setting>
                <th scope="row">
                    <label for="ll-content-lesson-transcript-format"><?php esc_html_e('Timing format', 'll-tools-text-domain'); ?></label>
                </th>
                <td>
                    <select id="ll-content-lesson-transcript-format" name="ll_content_lesson_transcript_format" class="regular-text">
                        <option value="auto" <?php selected($transcript_format, 'auto'); ?>><?php esc_html_e('Auto detect', 'll-tools-text-domain'); ?></option>
                        <option value="vtt" <?php selected($transcript_format, 'vtt'); ?>><?php esc_html_e('WebVTT', 'll-tools-text-domain'); ?></option>
                        <option value="json" <?php selected($transcript_format, 'json'); ?>><?php esc_html_e('JSON', 'll-tools-text-domain'); ?></option>
                        <option value="tsv" <?php selected($transcript_format, 'tsv'); ?>><?php esc_html_e('TSV', 'll-tools-text-domain'); ?></option>
                    </select>
                    <p class="description">
                        <?php esc_html_e('Phase 1 supports pasted WebVTT, TSV, or JSON timing payloads.', 'll-tools-text-domain'); ?>
                    </p>
                </td>
            </tr>
            <tr data-ll-content-lesson-media-setting>
                <th scope="row">
                    <label for="ll-content-lesson-transcript-source"><?php esc_html_e('Transcript timing source', 'll-tools-text-domain'); ?></label>
                </th>
                <td>
                    <textarea
                        id="ll-content-lesson-transcript-source"
                        name="ll_content_lesson_transcript_source"
                        rows="14"
                        class="large-text code"
                        placeholder="<?php echo esc_attr__("Paste WebVTT, TSV, or JSON timing data here.", 'll-tools-text-domain'); ?>"
                    ><?php echo esc_textarea($transcript_source); ?></textarea>
                    <p class="description">
                        <?php esc_html_e('Example sources: line_alignment.vtt, line_alignment.tsv, sentence_alignment.vtt, or highlight_approx.json.', 'll-tools-text-domain'); ?>
                    </p>
                    <?php if ($cue_count > 0) : ?>
                        <p class="description" style="margin-top:8px;">
                            <?php
                            echo esc_html(sprintf(
                                _n('%d cue parsed and ready for playback.', '%d cues parsed and ready for playback.', $cue_count, 'll-tools-text-domain'),
                                $cue_count
                            ));
                            ?>
                        </p>
                    <?php endif; ?>
                    <?php if ($parse_error !== '') : ?>
                        <p class="description" style="margin-top:8px;color:#b32d2e;font-weight:600;">
                            <?php echo esc_html($parse_error); ?>
                        </p>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="ll-content-lesson-categories"><?php esc_html_e('Related vocab categories', 'll-tools-text-domain'); ?></label>
                </th>
                <td>
                    <select
                        id="ll-content-lesson-categories"
                        name="ll_content_lesson_category_ids[]"
                        class="large-text"
                        size="8"
                        multiple>
                        <?php foreach ($category_rows as $category_row) : ?>
                            <option
                                value="<?php echo esc_attr((string) $category_row['id']); ?>"
                                data-ll-category-source-id="<?php echo esc_attr((string) ($category_row['source_id'] ?? $category_row['id'])); ?>"
                                <?php selected(in_array((int) $category_row['id'], $category_ids, true), true); ?>>
                                <?php echo esc_html((string) $category_row['label']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">
                        <?php esc_html_e('These categories become related vocab-lesson links on the content lesson page and backlinks on drill pages. Use Ctrl/Command + click to select multiple.', 'll-tools-text-domain'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <?php esc_html_e('Show in lesson grid', 'll-tools-text-domain'); ?>
                </th>
                <td>
                    <label for="ll-content-lesson-show-in-mix">
                        <input
                            type="checkbox"
                            id="ll-content-lesson-show-in-mix"
                            name="ll_content_lesson_show_in_mix"
                            value="1"
                            <?php checked($show_in_mix); ?>
                        />
                        <?php esc_html_e('Show this lesson as a full-size card in the main word set lesson grid.', 'll-tools-text-domain'); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e('When enabled, this lesson can be placed between vocab lesson cards on the word set page instead of only appearing in the separate main-lessons section.', 'll-tools-text-domain'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="ll-content-lesson-prereq-categories"><?php esc_html_e('Vocab lesson prerequisites', 'll-tools-text-domain'); ?></label>
                </th>
                <td>
                    <select
                        id="ll-content-lesson-prereq-categories"
                        name="ll_content_lesson_prereq_category_ids[]"
                        class="large-text"
                        size="8"
                        multiple>
                        <?php foreach ($prereq_rows as $prereq_row) : ?>
                            <option
                                value="<?php echo esc_attr((string) $prereq_row['id']); ?>"
                                data-ll-category-source-id="<?php echo esc_attr((string) ($prereq_row['source_id'] ?? $prereq_row['id'])); ?>"
                                <?php selected(in_array((int) $prereq_row['id'], $prereq_category_ids, true), true); ?>>
                                <?php echo esc_html((string) $prereq_row['label']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">
                        <?php esc_html_e('When this lesson is mixed into the main grid, it is inserted after the selected vocab lessons. If multiple mixed content lessons share the same prerequisites, their Order value breaks ties.', 'll-tools-text-domain'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="ll-content-lesson-prereq-lessons"><?php esc_html_e('Content lesson prerequisites', 'll-tools-text-domain'); ?></label>
                </th>
                <td>
                    <select
                        id="ll-content-lesson-prereq-lessons"
                        name="ll_content_lesson_prereq_lesson_ids[]"
                        class="large-text"
                        size="8"
                        multiple>
                        <?php foreach ($prereq_lesson_rows as $prereq_lesson_row) : ?>
                            <option
                                value="<?php echo esc_attr((string) $prereq_lesson_row['id']); ?>"
                                <?php selected(in_array((int) $prereq_lesson_row['id'], $prereq_lesson_ids, true), true); ?>>
                                <?php echo esc_html((string) $prereq_lesson_row['label']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">
                        <?php esc_html_e('When this lesson is mixed into the main grid, it can also be placed after earlier content lessons. Use this to keep story, review, and follow-up practice lessons in sequence.', 'll-tools-text-domain'); ?>
                    </p>
                </td>
            </tr>
        </tbody>
    </table>
    <?php
}

function ll_tools_save_content_lesson_metabox($post_id, $post): void {
    global $wpdb;

    if (!($post instanceof WP_Post) || $post->post_type !== 'll_content_lesson') {
        return;
    }
    if (wp_is_post_revision($post_id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) {
        return;
    }
    if (!isset($_POST['ll_tools_content_lesson_nonce'])
        || !wp_verify_nonce(wp_unslash((string) $_POST['ll_tools_content_lesson_nonce']), 'll_tools_content_lesson_save')) {
        return;
    }
    if (!current_user_can('edit_post', $post_id) || !current_user_can('view_ll_tools')) {
        return;
    }

    $existing_wordset_complete = true;
    $existing_wordset_id = ll_tools_get_content_lesson_wordset_id_authoritative(
        (int) $post_id,
        $existing_wordset_complete
    );
    if (!$existing_wordset_complete) {
        return;
    }
    if ($existing_wordset_id > 0 && !ll_tools_user_can_manage_wordset_content($existing_wordset_id, get_current_user_id())) {
        return;
    }

    $wordset_identity_complete = true;
    $wordset_id = ll_tools_content_lesson_resolve_wordset_id(
        isset($_POST['ll_content_lesson_wordset_id'])
            ? wp_unslash((string) $_POST['ll_content_lesson_wordset_id'])
            : 0,
        $wordset_identity_complete
    );
    if (!$wordset_identity_complete) {
        return;
    }
    if ($wordset_id > 0 && !ll_tools_user_can_manage_wordset_content($wordset_id, get_current_user_id())) {
        return;
    }

    $lesson_kind = ll_tools_content_lesson_sanitize_kind(
        wp_unslash((string) ($_POST['ll_content_lesson_kind'] ?? 'standard'))
    );
    $media_type = ll_tools_content_lesson_sanitize_media_type(
        wp_unslash((string) ($_POST['ll_content_lesson_media_type'] ?? 'audio'))
    );
    $media_url = isset($_POST['ll_content_lesson_media_url'])
        ? esc_url_raw(wp_unslash((string) $_POST['ll_content_lesson_media_url']))
        : '';
    $transcript_format = ll_tools_content_lesson_sanitize_transcript_format(
        wp_unslash((string) ($_POST['ll_content_lesson_transcript_format'] ?? 'auto'))
    );
    $transcript_source = isset($_POST['ll_content_lesson_transcript_source'])
        ? ll_tools_content_lesson_sanitize_source_text(wp_unslash((string) $_POST['ll_content_lesson_transcript_source']))
        : '';
    if ($lesson_kind === 'article') {
        $media_url = '';
        $transcript_source = '';
    }

    $category_identity_complete = true;
    $category_ids = ll_tools_content_lesson_normalize_category_ids(
        isset($_POST['ll_content_lesson_category_ids']) ? (array) wp_unslash($_POST['ll_content_lesson_category_ids']) : [],
        $category_identity_complete
    );
    $category_filter_complete = true;
    $category_ids = ll_tools_filter_content_lesson_category_ids_for_wordset(
        $wordset_id,
        $category_ids,
        $category_filter_complete
    );
    $show_in_mix = ll_tools_content_lesson_normalize_mix_flag(
        isset($_POST['ll_content_lesson_show_in_mix']) ? wp_unslash((string) $_POST['ll_content_lesson_show_in_mix']) : ''
    );
    $prereq_category_identity_complete = true;
    $prereq_category_ids = ll_tools_content_lesson_normalize_category_ids(
        isset($_POST['ll_content_lesson_prereq_category_ids']) ? (array) wp_unslash($_POST['ll_content_lesson_prereq_category_ids']) : [],
        $prereq_category_identity_complete
    );
    $prereq_category_filter_complete = true;
    $prereq_category_ids = ll_tools_filter_content_lesson_prereq_category_ids_for_wordset(
        $wordset_id,
        $prereq_category_ids,
        $prereq_category_filter_complete
    );
    $raw_prereq_lesson_ids = isset($_POST['ll_content_lesson_prereq_lesson_ids'])
        ? (array) wp_unslash($_POST['ll_content_lesson_prereq_lesson_ids'])
        : [];
    $edge_limit = ll_tools_content_lesson_prerequisite_edge_limit((int) $post_id);
    $prereq_input_too_large = count($raw_prereq_lesson_ids) > $edge_limit;
    $prereq_lesson_identity_complete = true;
    $prereq_lesson_ids = ll_tools_content_lesson_normalize_lesson_ids(
        array_slice(array_values($raw_prereq_lesson_ids), 0, $edge_limit + 1),
        $prereq_lesson_identity_complete
    );
    if (!$category_identity_complete
        || !$prereq_category_identity_complete
        || !$prereq_lesson_identity_complete
    ) {
        $prereq_graph_result = new WP_Error(
            'content_lesson_relation_query_incomplete',
            __('The prerequisite relationships could not be read completely.', 'll-tools-text-domain')
        );
    } elseif (!$prereq_input_too_large && count($prereq_lesson_ids) <= $edge_limit) {
        $prereq_lesson_filter_complete = true;
        $prereq_lesson_ids = ll_tools_filter_content_lesson_prereq_lesson_ids_for_wordset(
            $wordset_id,
            $prereq_lesson_ids,
            (int) $post_id,
            $prereq_lesson_filter_complete
        );
        if (!$category_filter_complete
            || !$prereq_category_filter_complete
            || !$prereq_lesson_filter_complete
        ) {
            $prereq_graph_result = new WP_Error(
                'content_lesson_relation_query_incomplete',
                __('The prerequisite relationships could not be read completely.', 'll-tools-text-domain')
            );
        } else {
            $prereq_graph_result = ll_tools_validate_content_lesson_prerequisite_graph(
                (int) $post_id,
                $wordset_id,
                $prereq_lesson_ids
            );
        }
    } else {
        $prereq_graph_result = new WP_Error(
            'content_lesson_prerequisite_graph_too_large',
            __('The prerequisite graph is too large to validate safely.', 'll-tools-text-domain')
        );
    }
    $should_save_relation_scope = !is_wp_error($prereq_graph_result);
    if ($should_save_relation_scope) {
        delete_post_meta($post_id, LL_TOOLS_CONTENT_LESSON_RELATION_ERROR_META);
    } else {
        update_post_meta(
            $post_id,
            LL_TOOLS_CONTENT_LESSON_RELATION_ERROR_META,
            sanitize_key((string) $prereq_graph_result->get_error_code())
        );
    }

    if ($should_save_relation_scope) {
        if ($wordset_id > 0) {
            update_post_meta($post_id, LL_TOOLS_CONTENT_LESSON_WORDSET_META, (string) $wordset_id);
        } else {
            delete_post_meta($post_id, LL_TOOLS_CONTENT_LESSON_WORDSET_META);
        }
    }

    if ($lesson_kind !== 'standard') {
        update_post_meta($post_id, LL_TOOLS_CONTENT_LESSON_KIND_META, $lesson_kind);
    } else {
        delete_post_meta($post_id, LL_TOOLS_CONTENT_LESSON_KIND_META);
    }

    update_post_meta($post_id, LL_TOOLS_CONTENT_LESSON_MEDIA_TYPE_META, $media_type);

    if ($media_url !== '') {
        update_post_meta($post_id, LL_TOOLS_CONTENT_LESSON_MEDIA_URL_META, $media_url);
    } else {
        delete_post_meta($post_id, LL_TOOLS_CONTENT_LESSON_MEDIA_URL_META);
    }

    update_post_meta($post_id, LL_TOOLS_CONTENT_LESSON_TRANSCRIPT_FORMAT_META, $transcript_format);

    if ($transcript_source !== '') {
        update_post_meta($post_id, LL_TOOLS_CONTENT_LESSON_TRANSCRIPT_SOURCE_META, $transcript_source);
    } else {
        delete_post_meta($post_id, LL_TOOLS_CONTENT_LESSON_TRANSCRIPT_SOURCE_META);
    }

    if ($should_save_relation_scope) {
        if (!empty($category_ids)) {
            update_post_meta($post_id, LL_TOOLS_CONTENT_LESSON_CATEGORY_IDS_META, array_values($category_ids));
        } else {
            delete_post_meta($post_id, LL_TOOLS_CONTENT_LESSON_CATEGORY_IDS_META);
        }
    }

    if ($show_in_mix === '1') {
        update_post_meta($post_id, LL_TOOLS_CONTENT_LESSON_SHOW_IN_MIX_META, '1');
    } else {
        delete_post_meta($post_id, LL_TOOLS_CONTENT_LESSON_SHOW_IN_MIX_META);
    }

    if ($should_save_relation_scope) {
        if (!empty($prereq_category_ids)) {
            update_post_meta($post_id, LL_TOOLS_CONTENT_LESSON_PREREQ_CATEGORY_IDS_META, array_values($prereq_category_ids));
        } else {
            delete_post_meta($post_id, LL_TOOLS_CONTENT_LESSON_PREREQ_CATEGORY_IDS_META);
        }

        if (!empty($prereq_lesson_ids)) {
            update_post_meta($post_id, LL_TOOLS_CONTENT_LESSON_PREREQ_LESSON_IDS_META, array_values($prereq_lesson_ids));
        } else {
            delete_post_meta($post_id, LL_TOOLS_CONTENT_LESSON_PREREQ_LESSON_IDS_META);
        }
    }

    if ($transcript_source === '') {
        delete_post_meta($post_id, LL_TOOLS_CONTENT_LESSON_CUES_META);
        delete_post_meta($post_id, LL_TOOLS_CONTENT_LESSON_PARSE_ERROR_META);
        return;
    }

    $parsed_cues = ll_tools_content_lesson_parse_source($transcript_source, $transcript_format);
    if (is_wp_error($parsed_cues)) {
        delete_post_meta($post_id, LL_TOOLS_CONTENT_LESSON_CUES_META);
        update_post_meta($post_id, LL_TOOLS_CONTENT_LESSON_PARSE_ERROR_META, (string) $parsed_cues->get_error_message());
        return;
    }

    update_post_meta($post_id, LL_TOOLS_CONTENT_LESSON_CUES_META, $parsed_cues);
    delete_post_meta($post_id, LL_TOOLS_CONTENT_LESSON_PARSE_ERROR_META);
}
add_action('save_post_ll_content_lesson', 'll_tools_save_content_lesson_metabox', 20, 2);
