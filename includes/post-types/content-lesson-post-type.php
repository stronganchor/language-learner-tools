<?php
// File: includes/post-types/content-lesson-post-type.php
if (!defined('WPINC')) { die; }

if (!defined('LL_TOOLS_CONTENT_LESSON_WORDSET_META')) {
    define('LL_TOOLS_CONTENT_LESSON_WORDSET_META', '_ll_tools_content_lesson_wordset_id');
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
if (!defined('LL_TOOLS_CONTENT_LESSON_PARSE_ERROR_META')) {
    define('LL_TOOLS_CONTENT_LESSON_PARSE_ERROR_META', '_ll_tools_content_lesson_parse_error');
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

function ll_tools_content_lesson_sanitize_transcript_format($raw): string {
    $value = sanitize_key((string) $raw);
    return in_array($value, ['auto', 'vtt', 'json', 'tsv'], true) ? $value : 'auto';
}

function ll_tools_content_lesson_normalize_category_ids($raw): array {
    if (!is_array($raw)) {
        $raw = [$raw];
    }

    $ids = array_values(array_unique(array_filter(array_map('intval', $raw), static function (int $term_id): bool {
        return $term_id > 0 && term_exists($term_id, 'word-category');
    })));
    sort($ids, SORT_NUMERIC);

    return $ids;
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
    } elseif (array_is_list($decoded)) {
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

function ll_tools_get_content_lesson_selectable_category_rows(int $wordset_id = 0, array $selected_ids = []): array {
    $wordset_id = max(0, $wordset_id);
    $selected_ids = ll_tools_content_lesson_normalize_category_ids($selected_ids);
    $candidate_ids = $selected_ids;

    if ($wordset_id > 0 && function_exists('ll_tools_get_wordset_page_category_rows')) {
        foreach ((array) ll_tools_get_wordset_page_category_rows($wordset_id) as $row) {
            $term_id = isset($row['term_id']) ? (int) $row['term_id'] : 0;
            if ($term_id > 0) {
                $candidate_ids[] = $term_id;
            }
        }
        $candidate_ids = array_values(array_unique(array_filter(array_map('intval', $candidate_ids))));
    }

    if (!empty($candidate_ids)) {
        $terms = get_terms([
            'taxonomy' => 'word-category',
            'include' => $candidate_ids,
            'hide_empty' => false,
        ]);
    } else {
        $terms = get_terms([
            'taxonomy' => 'word-category',
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC',
        ]);
    }

    if (is_wp_error($terms) || empty($terms)) {
        return [];
    }

    $rows = [];
    foreach ($terms as $term) {
        if (!($term instanceof WP_Term)) {
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

    usort($rows, static function (array $left, array $right): int {
        $left_label = (string) ($left['label'] ?? '');
        $right_label = (string) ($right['label'] ?? '');
        return function_exists('ll_tools_locale_compare_strings')
            ? ll_tools_locale_compare_strings($left_label, $right_label)
            : strnatcasecmp($left_label, $right_label);
    });

    return $rows;
}

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
    $media_type = ll_tools_get_content_lesson_media_type((int) $post->ID);
    $media_url = (string) get_post_meta((int) $post->ID, LL_TOOLS_CONTENT_LESSON_MEDIA_URL_META, true);
    $transcript_format = ll_tools_get_content_lesson_transcript_format((int) $post->ID);
    $transcript_source = ll_tools_get_content_lesson_transcript_source((int) $post->ID);
    $category_ids = ll_tools_get_content_lesson_related_category_ids((int) $post->ID);
    $cue_count = count(ll_tools_get_content_lesson_cues((int) $post->ID));
    $parse_error = ll_tools_get_content_lesson_parse_error((int) $post->ID);
    $category_rows = ll_tools_get_content_lesson_selectable_category_rows($wordset_id, $category_ids);
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
        <?php esc_html_e('Create a main audio/video lesson that can link learners into related vocab drills.', 'll-tools-text-domain'); ?>
    </p>

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
                    <label for="ll-content-lesson-media-type"><?php esc_html_e('Media type', 'll-tools-text-domain'); ?></label>
                </th>
                <td>
                    <select id="ll-content-lesson-media-type" name="ll_content_lesson_media_type" class="regular-text">
                        <option value="audio" <?php selected($media_type, 'audio'); ?>><?php esc_html_e('Audio', 'll-tools-text-domain'); ?></option>
                        <option value="video" <?php selected($media_type, 'video'); ?>><?php esc_html_e('Video', 'll-tools-text-domain'); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
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
                        placeholder="https://"
                    />
                    <p class="description">
                        <?php esc_html_e('Use a direct file URL for the audio or video you want to play on the lesson page.', 'll-tools-text-domain'); ?>
                    </p>
                </td>
            </tr>
            <tr>
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
            <tr>
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
        </tbody>
    </table>
    <?php
}

function ll_tools_save_content_lesson_metabox($post_id, $post): void {
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

    $wordset_id = isset($_POST['ll_content_lesson_wordset_id']) ? (int) wp_unslash((string) $_POST['ll_content_lesson_wordset_id']) : 0;
    if ($wordset_id <= 0 || !term_exists($wordset_id, 'wordset')) {
        $wordset_id = 0;
    }

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

    $category_ids = ll_tools_content_lesson_normalize_category_ids(
        isset($_POST['ll_content_lesson_category_ids']) ? (array) wp_unslash($_POST['ll_content_lesson_category_ids']) : []
    );
    $allowed_category_ids = [];
    foreach (ll_tools_get_content_lesson_selectable_category_rows($wordset_id, $category_ids) as $category_row) {
        $allowed_category_id = isset($category_row['id']) ? (int) $category_row['id'] : 0;
        if ($allowed_category_id > 0) {
            $allowed_category_ids[$allowed_category_id] = true;
        }
    }
    if (!empty($allowed_category_ids)) {
        $category_ids = array_values(array_filter($category_ids, static function (int $category_id) use ($allowed_category_ids): bool {
            return !empty($allowed_category_ids[$category_id]);
        }));
    }

    if ($wordset_id > 0) {
        update_post_meta($post_id, LL_TOOLS_CONTENT_LESSON_WORDSET_META, (string) $wordset_id);
    } else {
        delete_post_meta($post_id, LL_TOOLS_CONTENT_LESSON_WORDSET_META);
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

    if (!empty($category_ids)) {
        update_post_meta($post_id, LL_TOOLS_CONTENT_LESSON_CATEGORY_IDS_META, array_values($category_ids));
    } else {
        delete_post_meta($post_id, LL_TOOLS_CONTENT_LESSON_CATEGORY_IDS_META);
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
