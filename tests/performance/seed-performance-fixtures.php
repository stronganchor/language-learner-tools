<?php
/**
 * WP-CLI eval-file script for the LL Tools performance benchmark fixture.
 *
 * This script reuses the deterministic fixture when it is current. When it is
 * missing, stale, or force-seeded, it resets only content tagged as the LL
 * performance fixture, then recreates wordsets, categories, words, quiz pages,
 * vocab lesson pages, and one benchmark learn-grid page.
 */

if (!defined('ABSPATH')) {
    fwrite(STDERR, "This script must run inside WordPress, usually through WP-CLI eval-file.\n");
    exit(1);
}

const LL_TOOLS_PERF_FIXTURE_KEY = 'll-tools-performance-benchmark';
const LL_TOOLS_PERF_FIXTURE_META_KEY = '_ll_tools_performance_fixture';
const LL_TOOLS_PERF_FIXTURE_VERSION_META_KEY = '_ll_tools_performance_fixture_version';
const LL_TOOLS_PERF_FIXTURE_MANIFEST_OPTION = 'll_tools_performance_fixture_manifest';

function ll_tools_perf_seed_fail(string $message): void {
    if (class_exists('WP_CLI')) {
        WP_CLI::error($message);
    }

    throw new RuntimeException($message);
}

function ll_tools_perf_seed_log(string $message): void {
    if (class_exists('WP_CLI')) {
        WP_CLI::log($message);
        return;
    }

    fwrite(STDOUT, $message . PHP_EOL);
}

function ll_tools_perf_seed_env_flag(string $name): bool {
    $raw = getenv($name);
    if (!is_string($raw)) {
        return false;
    }

    return preg_match('/^(1|true|yes|on)$/i', trim($raw)) === 1;
}

function ll_tools_perf_seed_manifest_path(): string {
    foreach (['LL_TOOLS_PERF_FIXTURE_MANIFEST', 'LL_E2E_PERF_FIXTURE_MANIFEST'] as $env_name) {
        $env_path = getenv($env_name);
        if (is_string($env_path) && trim($env_path) !== '') {
            return trim($env_path);
        }
    }

    return __DIR__ . '/fixtures/performance-wordsets.json';
}

function ll_tools_perf_seed_load_manifest(): array {
    $path = ll_tools_perf_seed_manifest_path();
    if (!is_readable($path)) {
        ll_tools_perf_seed_fail('Performance fixture manifest is not readable: ' . $path);
    }

    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded)) {
        ll_tools_perf_seed_fail('Performance fixture manifest is not valid JSON: ' . $path);
    }

    $version = trim((string) ($decoded['fixtureVersion'] ?? ''));
    if ($version === '') {
        ll_tools_perf_seed_fail('Performance fixture manifest is missing fixtureVersion.');
    }

    $wordsets = $decoded['wordsets'] ?? [];
    if (!is_array($wordsets) || empty($wordsets)) {
        ll_tools_perf_seed_fail('Performance fixture manifest has no wordsets.');
    }

    return $decoded;
}

function ll_tools_perf_seed_manifest_checksum(): string {
    $path = ll_tools_perf_seed_manifest_path();
    return is_readable($path) ? (string) hash_file('sha256', $path) : '';
}

function ll_tools_perf_seed_manifest_totals(array $manifest): array {
    $wordsets = (array) ($manifest['wordsets'] ?? []);
    $category_count = 0;
    $word_count = 0;
    foreach ($wordsets as $wordset) {
        $wordset_categories = max(0, (int) ($wordset['categoryCount'] ?? 0));
        $words_per_category = max(0, (int) ($wordset['wordsPerCategory'] ?? 0));
        $category_count += $wordset_categories;
        $word_count += $wordset_categories * $words_per_category;
    }

    return [
        'wordsets' => count($wordsets),
        'categories' => $category_count,
        'words' => $word_count,
        'word_images' => !empty($manifest['media']['imagePerCategory']) ? $category_count : 0,
        'attachments' => !empty($manifest['media']['imagePerCategory']) ? $category_count : 0,
        'word_audio' => !empty($manifest['media']['audioPerWord']) ? $word_count : 0,
        'quiz_pages' => $category_count,
        'vocab_lessons' => $category_count,
        'pages' => $category_count + 1,
    ];
}

function ll_tools_perf_seed_expected_category_slugs(array $manifest): array {
    $slugs = [];
    foreach ((array) ($manifest['wordsets'] ?? []) as $wordset) {
        $wordset_slug = sanitize_title((string) ($wordset['slug'] ?? ''));
        $category_count = max(0, (int) ($wordset['categoryCount'] ?? 0));
        for ($index = 1; $index <= $category_count; $index++) {
            $slugs[] = sprintf('%s-cat-%02d', $wordset_slug, $index);
        }
    }

    return array_values(array_unique(array_filter($slugs)));
}

function ll_tools_perf_seed_count_fixture_posts(string $post_type, string $fixture_version): int {
    $query = new WP_Query([
        'post_type' => $post_type,
        'post_status' => 'any',
        'posts_per_page' => 1,
        'fields' => 'ids',
        'no_found_rows' => false,
        'suppress_filters' => true,
        'meta_query' => [
            [
                'key' => LL_TOOLS_PERF_FIXTURE_META_KEY,
                'value' => LL_TOOLS_PERF_FIXTURE_KEY,
            ],
            [
                'key' => LL_TOOLS_PERF_FIXTURE_VERSION_META_KEY,
                'value' => $fixture_version,
            ],
        ],
    ]);

    return (int) $query->found_posts;
}

function ll_tools_perf_seed_count_wordset_fixture_words(int $wordset_id, string $fixture_version): int {
    $query = new WP_Query([
        'post_type' => 'words',
        'post_status' => 'publish',
        'posts_per_page' => 1,
        'fields' => 'ids',
        'no_found_rows' => false,
        'suppress_filters' => true,
        'tax_query' => [
            [
                'taxonomy' => 'wordset',
                'field' => 'term_id',
                'terms' => [$wordset_id],
            ],
        ],
        'meta_query' => [
            [
                'key' => LL_TOOLS_PERF_FIXTURE_META_KEY,
                'value' => LL_TOOLS_PERF_FIXTURE_KEY,
            ],
            [
                'key' => LL_TOOLS_PERF_FIXTURE_VERSION_META_KEY,
                'value' => $fixture_version,
            ],
        ],
    ]);

    return (int) $query->found_posts;
}

function ll_tools_perf_seed_refresh_rewrites(): void {
    if (function_exists('delete_transient')) {
        delete_transient('ll_tools_vocab_lesson_flush_rewrite');
    }

    if (function_exists('ll_tools_register_wordset_page_rewrite_rules')) {
        ll_tools_register_wordset_page_rewrite_rules();
    }

    if (function_exists('flush_rewrite_rules')) {
        flush_rewrite_rules(false);
    }
}

function ll_tools_perf_seed_get_current_fixture_summary(array $manifest): array {
    $stored = get_option(LL_TOOLS_PERF_FIXTURE_MANIFEST_OPTION, []);
    if (!is_array($stored)) {
        return [
            'current' => false,
            'reasons' => ['missing stored fixture manifest option'],
        ];
    }

    $fixture_version = (string) ($manifest['fixtureVersion'] ?? '');
    $stored_version = (string) ($stored['fixture_version'] ?? '');
    $stored_checksum = (string) ($stored['manifest_sha256'] ?? '');
    $manifest_checksum = ll_tools_perf_seed_manifest_checksum();
    $reasons = [];
    if ($stored_version !== $fixture_version) {
        $reasons[] = 'fixture version changed';
    }
    if ($manifest_checksum !== '' && $stored_checksum !== '' && $stored_checksum !== $manifest_checksum) {
        $reasons[] = 'fixture manifest checksum changed';
    }

    $totals = ll_tools_perf_seed_manifest_totals($manifest);
    $seeded_wordsets = [];
    foreach ((array) ($manifest['wordsets'] ?? []) as $wordset) {
        $slug = sanitize_title((string) ($wordset['slug'] ?? ''));
        $expected_words = max(0, (int) ($wordset['categoryCount'] ?? 0)) * max(0, (int) ($wordset['wordsPerCategory'] ?? 0));
        $term = $slug !== '' ? get_term_by('slug', $slug, 'wordset') : false;
        if (!($term instanceof WP_Term) || is_wp_error($term)) {
            $reasons[] = 'missing wordset ' . $slug;
            continue;
        }

        $term_id = (int) $term->term_id;
        if ((string) get_term_meta($term_id, LL_TOOLS_PERF_FIXTURE_META_KEY, true) !== LL_TOOLS_PERF_FIXTURE_KEY) {
            $reasons[] = 'wordset not tagged as fixture ' . $slug;
        }
        if ((string) get_term_meta($term_id, LL_TOOLS_PERF_FIXTURE_VERSION_META_KEY, true) !== $fixture_version) {
            $reasons[] = 'wordset fixture version mismatch ' . $slug;
        }

        $actual_words = ll_tools_perf_seed_count_wordset_fixture_words($term_id, $fixture_version);
        if ($actual_words !== $expected_words) {
            $reasons[] = sprintf('word count mismatch for %s: expected %d, found %d', $slug, $expected_words, $actual_words);
        }

        $seeded_wordsets[] = [
            'size' => (string) ($wordset['size'] ?? ''),
            'wordset_id' => $term_id,
            'slug' => $slug,
            'category_count' => max(0, (int) ($wordset['categoryCount'] ?? 0)),
            'words_per_category' => max(0, (int) ($wordset['wordsPerCategory'] ?? 0)),
            'word_count' => $actual_words,
        ];
    }

    foreach (ll_tools_perf_seed_expected_category_slugs($manifest) as $category_slug) {
        $term = get_term_by('slug', $category_slug, 'word-category');
        if (!($term instanceof WP_Term) || is_wp_error($term)) {
            $reasons[] = 'missing category ' . $category_slug;
            continue;
        }
        $term_id = (int) $term->term_id;
        if ((string) get_term_meta($term_id, LL_TOOLS_PERF_FIXTURE_META_KEY, true) !== LL_TOOLS_PERF_FIXTURE_KEY) {
            $reasons[] = 'category not tagged as fixture ' . $category_slug;
        }
        if ((string) get_term_meta($term_id, LL_TOOLS_PERF_FIXTURE_VERSION_META_KEY, true) !== $fixture_version) {
            $reasons[] = 'category fixture version mismatch ' . $category_slug;
        }
    }

    $post_type_expectations = [
        'words' => $totals['words'],
        'word_audio' => $totals['word_audio'],
        'word_images' => $totals['word_images'],
        'attachment' => $totals['attachments'],
        'll_vocab_lesson' => $totals['vocab_lessons'],
        'page' => $totals['pages'],
    ];
    foreach ($post_type_expectations as $post_type => $expected_count) {
        $actual_count = ll_tools_perf_seed_count_fixture_posts($post_type, $fixture_version);
        if ($actual_count !== (int) $expected_count) {
            $reasons[] = sprintf('fixture %s count mismatch: expected %d, found %d', $post_type, (int) $expected_count, $actual_count);
        }
    }

    $learn_slug = sanitize_title((string) ($manifest['learnPage']['slug'] ?? 'll-perf-learn'));
    $learn_page = $learn_slug !== '' ? get_page_by_path($learn_slug, OBJECT, 'page') : null;
    if (!($learn_page instanceof WP_Post)) {
        $reasons[] = 'missing learn page ' . $learn_slug;
    } elseif ((string) get_post_meta((int) $learn_page->ID, LL_TOOLS_PERF_FIXTURE_META_KEY, true) !== LL_TOOLS_PERF_FIXTURE_KEY) {
        $reasons[] = 'learn page not tagged as fixture';
    }

    return [
        'current' => empty($reasons),
        'reasons' => array_values(array_unique($reasons)),
        'summary' => [
            'fixture_version' => $fixture_version,
            'skipped' => true,
            'reason' => 'current fixture already seeded',
            'seeded_wordsets' => $seeded_wordsets,
            'pages' => [
                'quiz_pages' => $totals['quiz_pages'],
                'vocab_lessons' => $totals['vocab_lessons'],
                'learn_page_id' => $learn_page instanceof WP_Post ? (int) $learn_page->ID : 0,
            ],
            'learn_page_path' => '/' . trim($learn_slug, '/') . '/',
            'checked_at_gmt' => gmdate('c'),
        ],
    ];
}

function ll_tools_perf_seed_delete_posts(array $post_ids): int {
    $deleted = 0;
    foreach (array_values(array_unique(array_map('intval', $post_ids))) as $post_id) {
        if ($post_id <= 0) {
            continue;
        }
        if (wp_delete_post($post_id, true)) {
            $deleted++;
        }
    }

    return $deleted;
}

function ll_tools_perf_seed_query_fixture_posts(): array {
    return get_posts([
        'post_type' => ['words', 'word_audio', 'word_images', 'll_vocab_lesson', 'page', 'attachment'],
        'post_status' => 'any',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'no_found_rows' => true,
        'suppress_filters' => true,
        'meta_key' => LL_TOOLS_PERF_FIXTURE_META_KEY,
        'meta_value' => LL_TOOLS_PERF_FIXTURE_KEY,
    ]);
}

function ll_tools_perf_seed_reset_fixture(array $manifest): array {
    $deleted = [
        'posts' => 0,
        'quiz_pages' => 0,
        'categories' => 0,
        'wordsets' => 0,
    ];

    $fixture_category_ids = get_terms([
        'taxonomy' => 'word-category',
        'hide_empty' => false,
        'fields' => 'ids',
        'meta_key' => LL_TOOLS_PERF_FIXTURE_META_KEY,
        'meta_value' => LL_TOOLS_PERF_FIXTURE_KEY,
    ]);
    if (!is_wp_error($fixture_category_ids)) {
        foreach (array_map('intval', (array) $fixture_category_ids) as $category_id) {
            $quiz_pages = get_posts([
                'post_type' => 'page',
                'post_status' => 'any',
                'posts_per_page' => -1,
                'fields' => 'ids',
                'no_found_rows' => true,
                'suppress_filters' => true,
                'meta_key' => '_ll_tools_word_category_id',
                'meta_value' => (string) $category_id,
            ]);
            $deleted['quiz_pages'] += ll_tools_perf_seed_delete_posts((array) $quiz_pages);
        }
    }

    $learn_slug = sanitize_title((string) ($manifest['learnPage']['slug'] ?? 'll-perf-learn'));
    if ($learn_slug !== '') {
        $learn_page = get_page_by_path($learn_slug, OBJECT, 'page');
        if ($learn_page instanceof WP_Post) {
            $deleted['posts'] += ll_tools_perf_seed_delete_posts([(int) $learn_page->ID]);
        }
    }

    $deleted['posts'] += ll_tools_perf_seed_delete_posts(ll_tools_perf_seed_query_fixture_posts());

    $expected_category_slugs = ll_tools_perf_seed_expected_category_slugs($manifest);
    foreach ($expected_category_slugs as $category_slug) {
        $term = get_term_by('slug', $category_slug, 'word-category');
        if ($term instanceof WP_Term && !is_wp_error($term)) {
            update_term_meta((int) $term->term_id, LL_TOOLS_PERF_FIXTURE_META_KEY, LL_TOOLS_PERF_FIXTURE_KEY);
        }
    }

    $term_sets = [
        'word-category' => get_terms([
            'taxonomy' => 'word-category',
            'hide_empty' => false,
            'fields' => 'ids',
            'meta_key' => LL_TOOLS_PERF_FIXTURE_META_KEY,
            'meta_value' => LL_TOOLS_PERF_FIXTURE_KEY,
        ]),
        'wordset' => get_terms([
            'taxonomy' => 'wordset',
            'hide_empty' => false,
            'fields' => 'ids',
            'meta_key' => LL_TOOLS_PERF_FIXTURE_META_KEY,
            'meta_value' => LL_TOOLS_PERF_FIXTURE_KEY,
        ]),
    ];

    foreach ((array) ($manifest['wordsets'] ?? []) as $wordset) {
        $wordset_slug = sanitize_title((string) ($wordset['slug'] ?? ''));
        if ($wordset_slug === '') {
            continue;
        }
        $term = get_term_by('slug', $wordset_slug, 'wordset');
        if ($term instanceof WP_Term && !is_wp_error($term)) {
            $term_sets['wordset'][] = (int) $term->term_id;
        }
    }

    foreach ($term_sets as $taxonomy => $ids) {
        if (is_wp_error($ids)) {
            continue;
        }
        foreach (array_values(array_unique(array_map('intval', (array) $ids))) as $term_id) {
            if ($term_id <= 0) {
                continue;
            }
            $result = wp_delete_term($term_id, $taxonomy);
            if (!is_wp_error($result) && $result) {
                if ($taxonomy === 'wordset') {
                    $deleted['wordsets']++;
                } else {
                    $deleted['categories']++;
                }
            }
        }
    }

    clean_term_cache([], 'word-category');
    clean_term_cache([], 'wordset');

    return $deleted;
}

function ll_tools_perf_seed_tag_post(int $post_id, string $fixture_version): void {
    update_post_meta($post_id, LL_TOOLS_PERF_FIXTURE_META_KEY, LL_TOOLS_PERF_FIXTURE_KEY);
    update_post_meta($post_id, LL_TOOLS_PERF_FIXTURE_VERSION_META_KEY, $fixture_version);
}

function ll_tools_perf_seed_tag_term(int $term_id, string $fixture_version): void {
    update_term_meta($term_id, LL_TOOLS_PERF_FIXTURE_META_KEY, LL_TOOLS_PERF_FIXTURE_KEY);
    update_term_meta($term_id, LL_TOOLS_PERF_FIXTURE_VERSION_META_KEY, $fixture_version);
}

function ll_tools_perf_seed_relative_site_path(string $absolute_path): string {
    $path = wp_normalize_path($absolute_path);
    $root = wp_normalize_path(ABSPATH);
    if (strpos($path, $root) === 0) {
        return '/' . ltrim(substr($path, strlen($root)), '/');
    }

    return $path;
}

function ll_tools_perf_seed_fixture_upload_dir(): array {
    $uploads = wp_upload_dir();
    if (!empty($uploads['error'])) {
        ll_tools_perf_seed_fail('Unable to resolve uploads directory: ' . (string) $uploads['error']);
    }

    $dir = trailingslashit((string) $uploads['basedir']) . 'll-tools-performance-fixtures';
    if (!is_dir($dir) && !wp_mkdir_p($dir)) {
        ll_tools_perf_seed_fail('Unable to create performance fixture uploads directory: ' . $dir);
    }

    return [
        'dir' => wp_normalize_path($dir),
        'base_dir' => wp_normalize_path((string) $uploads['basedir']),
    ];
}

function ll_tools_perf_seed_image_bytes(): string {
    return (string) base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAEAAAABACAIAAAAlC+aJAAAAVklEQVR4nO3PQQ0AIBDAsAP/nkEEj4ZkVbDtmTsP0Jm9A5gNwGAABmAwAIMBGIDBAAzAYAAEYDAAAzAYgAEYDDAAMwEYDMAADAZgAAZD/YBZAROD8dN9AAAAAElFTkSuQmCC'
    );
}

function ll_tools_perf_seed_silent_wav_bytes(): string {
    $sample_rate = 8000;
    $channels = 1;
    $bits_per_sample = 16;
    $duration_seconds = 1;
    $sample_count = $sample_rate * $duration_seconds;
    $data = str_repeat("\0", $sample_count * $channels * ($bits_per_sample / 8));
    $byte_rate = $sample_rate * $channels * ($bits_per_sample / 8);
    $block_align = $channels * ($bits_per_sample / 8);

    return 'RIFF'
        . pack('V', 36 + strlen($data))
        . 'WAVEfmt '
        . pack('VvvVVvv', 16, 1, $channels, $sample_rate, $byte_rate, $block_align, $bits_per_sample)
        . 'data'
        . pack('V', strlen($data))
        . $data;
}

function ll_tools_perf_seed_attachment(string $slug, string $title, string $fixture_version): int {
    $upload = ll_tools_perf_seed_fixture_upload_dir();
    $filename = sanitize_file_name($slug . '.png');
    $file = trailingslashit($upload['dir']) . $filename;
    file_put_contents($file, ll_tools_perf_seed_image_bytes());

    $attachment_id = wp_insert_attachment([
        'post_title' => $title,
        'post_name' => sanitize_title($slug),
        'post_status' => 'inherit',
        'post_mime_type' => 'image/png',
    ], $file);

    if (is_wp_error($attachment_id) || (int) $attachment_id <= 0) {
        ll_tools_perf_seed_fail('Unable to create fixture image attachment for ' . $slug);
    }

    $attachment_id = (int) $attachment_id;
    $relative_file = ltrim(substr(wp_normalize_path($file), strlen($upload['base_dir'])), '/');
    wp_update_attachment_metadata($attachment_id, [
        'width' => 64,
        'height' => 64,
        'file' => $relative_file,
        'sizes' => [],
    ]);
    update_post_meta($attachment_id, '_wp_attachment_image_alt', $title);
    ll_tools_perf_seed_tag_post($attachment_id, $fixture_version);

    return $attachment_id;
}

function ll_tools_perf_seed_audio_path(): string {
    $upload = ll_tools_perf_seed_fixture_upload_dir();
    $file = trailingslashit($upload['dir']) . 'll-perf-silence.wav';
    file_put_contents($file, ll_tools_perf_seed_silent_wav_bytes());

    return ll_tools_perf_seed_relative_site_path($file);
}

function ll_tools_perf_seed_insert_term(string $taxonomy, string $name, string $slug, string $fixture_version): int {
    $existing = get_term_by('slug', $slug, $taxonomy);
    if ($existing instanceof WP_Term && !is_wp_error($existing)) {
        $existing_fixture = (string) get_term_meta((int) $existing->term_id, LL_TOOLS_PERF_FIXTURE_META_KEY, true);
        if ($existing_fixture !== LL_TOOLS_PERF_FIXTURE_KEY) {
            ll_tools_perf_seed_fail(sprintf(
                'Refusing to overwrite existing non-fixture %s term with slug %s.',
                $taxonomy,
                $slug
            ));
        }
        wp_delete_term((int) $existing->term_id, $taxonomy);
    }

    $insert = wp_insert_term($name, $taxonomy, ['slug' => $slug]);
    if (is_wp_error($insert)) {
        ll_tools_perf_seed_fail(sprintf('Unable to create %s term %s: %s', $taxonomy, $slug, $insert->get_error_message()));
    }

    $term_id = (int) ($insert['term_id'] ?? 0);
    if ($term_id <= 0) {
        ll_tools_perf_seed_fail(sprintf('Unable to create %s term %s.', $taxonomy, $slug));
    }

    ll_tools_perf_seed_tag_term($term_id, $fixture_version);
    return $term_id;
}

function ll_tools_perf_seed_create_category(array $wordset, int $wordset_id, int $category_index, array $manifest): array {
    $fixture_version = (string) $manifest['fixtureVersion'];
    $wordset_slug = sanitize_title((string) $wordset['slug']);
    $category_slug = sprintf('%s-cat-%02d', $wordset_slug, $category_index);
    $category_name = sprintf('%s Category %02d', (string) $wordset['title'], $category_index);
    $category_id = ll_tools_perf_seed_insert_term('word-category', $category_name, $category_slug, $fixture_version);

    if (function_exists('ll_tools_set_category_wordset_owner')) {
        ll_tools_set_category_wordset_owner($category_id, $wordset_id, $category_id);
    }

    update_term_meta($category_id, 'll_quiz_prompt_type', sanitize_key((string) ($manifest['quizConfig']['promptType'] ?? 'text_title')));
    update_term_meta($category_id, 'll_quiz_option_type', sanitize_key((string) ($manifest['quizConfig']['optionType'] ?? 'text_translation')));
    update_term_meta($category_id, 'll_category_visibility', 'public');
    update_term_meta($category_id, 'll_category_enabled_games', ['space-shooter', 'line-up', 'unscramble']);

    return [
        'id' => $category_id,
        'slug' => $category_slug,
        'name' => $category_name,
    ];
}

function ll_tools_perf_seed_create_category_image(int $wordset_id, array $category, string $fixture_version): int {
    $attachment_id = ll_tools_perf_seed_attachment(
        (string) $category['slug'] . '-image',
        (string) $category['name'] . ' Image',
        $fixture_version
    );
    $image_id = wp_insert_post([
        'post_type' => 'word_images',
        'post_status' => 'publish',
        'post_title' => (string) $category['name'] . ' Image',
        'post_name' => sanitize_title((string) $category['slug'] . '-image'),
    ], true);

    if (is_wp_error($image_id) || (int) $image_id <= 0) {
        ll_tools_perf_seed_fail('Unable to create fixture word image for ' . (string) $category['slug']);
    }

    $image_id = (int) $image_id;
    set_post_thumbnail($image_id, $attachment_id);
    wp_set_object_terms($image_id, [(int) $category['id']], 'word-category', false);
    if (function_exists('ll_tools_set_word_image_wordset_owner')) {
        ll_tools_set_word_image_wordset_owner($image_id, $wordset_id, $image_id);
    }
    update_post_meta($image_id, 'copyright_info', 'LL Tools performance fixture');
    ll_tools_perf_seed_tag_post($image_id, $fixture_version);

    return $image_id;
}

function ll_tools_perf_seed_create_word(array $wordset, int $wordset_id, array $category, int $word_index, int $image_id, int $attachment_id, string $audio_path, array $manifest): int {
    $fixture_version = (string) $manifest['fixtureVersion'];
    $size = sanitize_key((string) ($wordset['size'] ?? 'wordset'));
    $wordset_slug = sanitize_title((string) $wordset['slug']);
    $category_number = 0;
    if (preg_match('/cat-(\d+)$/', (string) $category['slug'], $matches)) {
        $category_number = (int) $matches[1];
    }
    $word_slug = sprintf('%s-word-%02d-%02d', $wordset_slug, $category_number, $word_index);
    $word_title = sprintf('LLPerf %s %02d %02d', $size, $category_number, $word_index);
    $translation = sprintf('Performance fixture %s translation %02d %02d', $size, $category_number, $word_index);

    $word_id = wp_insert_post([
        'post_type' => 'words',
        'post_status' => 'publish',
        'post_title' => $word_title,
        'post_name' => $word_slug,
        'post_content' => '',
    ], true);

    if (is_wp_error($word_id) || (int) $word_id <= 0) {
        ll_tools_perf_seed_fail('Unable to create fixture word ' . $word_slug);
    }

    $word_id = (int) $word_id;
    wp_set_object_terms($word_id, [$wordset_id], 'wordset', false);
    wp_set_object_terms($word_id, [(int) $category['id']], 'word-category', false);
    update_post_meta($word_id, 'word_translation', $translation);
    update_post_meta($word_id, 'word_note', 'Static LL Tools performance benchmark fixture word.');
    update_post_meta($word_id, '_ll_autopicked_image_id', $image_id);
    if ($attachment_id > 0) {
        set_post_thumbnail($word_id, $attachment_id);
    }
    ll_tools_perf_seed_tag_post($word_id, $fixture_version);

    if (!empty($manifest['media']['audioPerWord'])) {
        $audio_id = wp_insert_post([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_title' => $word_title . ' Audio',
            'post_name' => $word_slug . '-audio',
            'post_parent' => $word_id,
        ], true);
        if (!is_wp_error($audio_id) && (int) $audio_id > 0) {
            $audio_id = (int) $audio_id;
            update_post_meta($audio_id, 'audio_file_path', $audio_path);
            update_post_meta($audio_id, 'recording_text', $word_title);
            update_post_meta($audio_id, 'speaker_name', 'LL Performance Fixture');
            wp_set_object_terms($audio_id, ['isolation'], 'recording_type', false);
            ll_tools_perf_seed_tag_post($audio_id, $fixture_version);
        }
    }

    return $word_id;
}

function ll_tools_perf_seed_create_pages(array $manifest, array $seeded_wordsets): array {
    $fixture_version = (string) $manifest['fixtureVersion'];
    $created = [
        'quiz_pages' => 0,
        'vocab_lessons' => 0,
        'learn_page_id' => 0,
    ];

    foreach ($seeded_wordsets as $wordset_summary) {
        foreach ((array) ($wordset_summary['categories'] ?? []) as $category) {
            $category_id = (int) ($category['id'] ?? 0);
            $wordset_id = (int) ($wordset_summary['wordset_id'] ?? 0);
            if ($category_id <= 0 || $wordset_id <= 0) {
                continue;
            }
            if (function_exists('ll_tools_get_or_create_quiz_page_for_category')) {
                $quiz_page_id = ll_tools_get_or_create_quiz_page_for_category($category_id);
                if (!is_wp_error($quiz_page_id) && (int) $quiz_page_id > 0) {
                    ll_tools_perf_seed_tag_post((int) $quiz_page_id, $fixture_version);
                    $created['quiz_pages']++;
                }
            }
            if (function_exists('ll_tools_get_or_create_vocab_lesson_page')) {
                $lesson = ll_tools_get_or_create_vocab_lesson_page($category_id, $wordset_id);
                $lesson_id = is_array($lesson) ? (int) ($lesson['post_id'] ?? 0) : (int) $lesson;
                if ($lesson_id > 0) {
                    ll_tools_perf_seed_tag_post($lesson_id, $fixture_version);
                    $created['vocab_lessons']++;
                }
            }
        }
    }

    $learn_slug = sanitize_title((string) ($manifest['learnPage']['slug'] ?? 'll-perf-learn'));
    $learn_title = sanitize_text_field((string) ($manifest['learnPage']['title'] ?? 'LL Performance Learn Grid'));
    $learn_wordset = sanitize_title((string) ($manifest['learnPage']['wordsetSlug'] ?? 'll-perf-large'));
    $content = sprintf('[quiz_pages_grid wordset="%s" popup="yes" mode="practice"]', esc_attr($learn_wordset));

    $learn_id = wp_insert_post([
        'post_type' => 'page',
        'post_status' => 'publish',
        'post_title' => $learn_title,
        'post_name' => $learn_slug,
        'post_content' => $content,
    ], true);

    if (is_wp_error($learn_id) || (int) $learn_id <= 0) {
        ll_tools_perf_seed_fail('Unable to create performance learn page.');
    }

    $created['learn_page_id'] = (int) $learn_id;
    ll_tools_perf_seed_tag_post((int) $learn_id, $fixture_version);

    return $created;
}

function ll_tools_perf_seed_run(): array {
    $manifest = ll_tools_perf_seed_load_manifest();
    $fixture_version = (string) $manifest['fixtureVersion'];
    $current_fixture = ll_tools_perf_seed_get_current_fixture_summary($manifest);
    if (empty($current_fixture['current']) && !empty($current_fixture['reasons'])) {
        ll_tools_perf_seed_log('Performance fixture reseed required: ' . implode('; ', (array) $current_fixture['reasons']));
    }
    if (!ll_tools_perf_seed_env_flag('LL_PERF_FORCE_SEED') && !empty($current_fixture['current'])) {
        $stored_manifest = get_option(LL_TOOLS_PERF_FIXTURE_MANIFEST_OPTION, []);
        if (is_array($stored_manifest)) {
            $manifest_checksum = ll_tools_perf_seed_manifest_checksum();
            if ($manifest_checksum !== '' && (string) ($stored_manifest['manifest_sha256'] ?? '') !== $manifest_checksum) {
                $stored_manifest['manifest_sha256'] = $manifest_checksum;
                update_option(LL_TOOLS_PERF_FIXTURE_MANIFEST_OPTION, $stored_manifest, false);
            }
        }
        ll_tools_perf_seed_refresh_rewrites();
        return (array) ($current_fixture['summary'] ?? []);
    }

    $deleted = ll_tools_perf_seed_reset_fixture($manifest);
    $audio_path = ll_tools_perf_seed_audio_path();

    $seeded_wordsets = [];
    foreach ((array) $manifest['wordsets'] as $wordset) {
        $wordset_slug = sanitize_title((string) ($wordset['slug'] ?? ''));
        $wordset_title = sanitize_text_field((string) ($wordset['title'] ?? ''));
        $category_count = max(0, (int) ($wordset['categoryCount'] ?? 0));
        $words_per_category = max(0, (int) ($wordset['wordsPerCategory'] ?? 0));
        if ($wordset_slug === '' || $wordset_title === '' || $category_count <= 0 || $words_per_category <= 0) {
            ll_tools_perf_seed_fail('Invalid performance fixture wordset entry.');
        }

        $wordset_id = ll_tools_perf_seed_insert_term('wordset', $wordset_title, $wordset_slug, $fixture_version);
        if (function_exists('ll_tools_ensure_vocab_lessons_enabled_for_wordset')) {
            ll_tools_ensure_vocab_lessons_enabled_for_wordset($wordset_id, false);
        }

        $summary = [
            'size' => (string) ($wordset['size'] ?? ''),
            'wordset_id' => $wordset_id,
            'slug' => $wordset_slug,
            'category_count' => $category_count,
            'words_per_category' => $words_per_category,
            'word_count' => 0,
            'categories' => [],
        ];

        for ($category_index = 1; $category_index <= $category_count; $category_index++) {
            $category = ll_tools_perf_seed_create_category($wordset, $wordset_id, $category_index, $manifest);
            $image_id = 0;
            $attachment_id = 0;
            if (!empty($manifest['media']['imagePerCategory'])) {
                $image_id = ll_tools_perf_seed_create_category_image($wordset_id, $category, $fixture_version);
                $attachment_id = (int) get_post_thumbnail_id($image_id);
            }

            for ($word_index = 1; $word_index <= $words_per_category; $word_index++) {
                ll_tools_perf_seed_create_word($wordset, $wordset_id, $category, $word_index, $image_id, $attachment_id, $audio_path, $manifest);
                $summary['word_count']++;
            }

            if (function_exists('ll_tools_bump_category_cache_version')) {
                ll_tools_bump_category_cache_version([(int) $category['id']]);
            }

            $summary['categories'][] = $category;
        }

        $seeded_wordsets[] = $summary;
    }

    $pages = ll_tools_perf_seed_create_pages($manifest, $seeded_wordsets);

    update_option(LL_TOOLS_PERF_FIXTURE_MANIFEST_OPTION, [
        'fixture_version' => $fixture_version,
        'manifest_sha256' => ll_tools_perf_seed_manifest_checksum(),
        'seeded_at_gmt' => gmdate('c'),
        'wordsets' => array_map(static function (array $wordset): array {
            return [
                'size' => (string) ($wordset['size'] ?? ''),
                'wordset_id' => (int) ($wordset['wordset_id'] ?? 0),
                'slug' => (string) ($wordset['slug'] ?? ''),
                'category_count' => (int) ($wordset['category_count'] ?? 0),
                'words_per_category' => (int) ($wordset['words_per_category'] ?? 0),
                'word_count' => (int) ($wordset['word_count'] ?? 0),
            ];
        }, $seeded_wordsets),
        'learn_page' => [
            'slug' => (string) ($manifest['learnPage']['slug'] ?? 'll-perf-learn'),
            'path' => '/' . trim((string) ($manifest['learnPage']['slug'] ?? 'll-perf-learn'), '/') . '/',
        ],
    ], false);

    ll_tools_perf_seed_refresh_rewrites();

    return [
        'fixture_version' => $fixture_version,
        'deleted' => $deleted,
        'seeded_wordsets' => array_map(static function (array $wordset): array {
            unset($wordset['categories']);
            return $wordset;
        }, $seeded_wordsets),
        'pages' => $pages,
        'learn_page_path' => '/' . trim((string) ($manifest['learnPage']['slug'] ?? 'll-perf-learn'), '/') . '/',
        'seeded_at_gmt' => gmdate('c'),
    ];
}

$summary = ll_tools_perf_seed_run();
ll_tools_perf_seed_log(wp_json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
