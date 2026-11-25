<?php
/**
 * Bounded WP-CLI profiler for the tagged Genç-scale recorder summary fixture.
 *
 * Usage (run cold first, then warm as a separate WP-CLI process):
 *   wp eval-file tests/performance/profile-recorder-summary.php cold 80 180
 *   wp eval-file tests/performance/profile-recorder-summary.php warm 80 180
 *   wp eval-file tests/performance/profile-recorder-summary.php reset
 *
 * Positional arguments are phase, maximum batches, and maximum wall seconds.
 * The script never creates fixture content. The cold and reset phases delete
 * only the exact recorder-summary durable-cache keys derived from the verified
 * tagged fixture. Reset also proves that the unfocused browser route selects
 * that same recorder, then exits without rebuilding the cache.
 */

if (!defined('WP_CLI') || !WP_CLI) {
    fwrite(STDERR, "This profiler must run through WP-CLI eval-file.\n");
    exit(1);
}

$profile_fail = static function (string $message, array $context = []): void {
    $payload = [
        'ok' => false,
        'error' => $message,
    ];
    if (!empty($context)) {
        $payload['context'] = $context;
    }
    fwrite(STDERR, (string) wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    WP_CLI::halt(1);
};

$profile_args = isset($args) && is_array($args) ? array_values($args) : [];
$phase = strtolower(trim((string) ($profile_args[0] ?? '')));
if (!in_array($phase, ['cold', 'warm', 'reset'], true)) {
    $profile_fail('Phase must be the positional value cold, warm, or reset.');
}

$max_batches = isset($profile_args[1]) ? (int) $profile_args[1] : 80;
$max_seconds = isset($profile_args[2]) ? (int) $profile_args[2] : 180;
if ($phase === 'reset' && count($profile_args) !== 1) {
    $profile_fail('Reset does not accept batch or wall-time arguments.');
}
if ($max_batches < 1 || $max_batches > 120) {
    $profile_fail('Maximum batches must be between 1 and 120.');
}
if ($max_seconds < 10 || $max_seconds > 300) {
    $profile_fail('Maximum wall seconds must be between 10 and 300.');
}
if (count($profile_args) > 3) {
    $profile_fail('Unexpected arguments. Expected: phase [max-batches] [max-seconds].');
}

$repository_root = dirname(__DIR__, 2);
$manifest_path = $repository_root . '/tests/performance/fixtures/performance-wordsets-genc.json';
$checksum_helpers = $repository_root . '/tests/performance/lib/performance-manifest.php';
if (!is_readable($manifest_path) || !is_readable($checksum_helpers)) {
    $profile_fail('The fixed Genç manifest or checksum helper is not readable.');
}
require_once $checksum_helpers;

$manifest_json = file_get_contents($manifest_path);
$manifest = is_string($manifest_json) ? json_decode($manifest_json, true) : null;
if (!is_array($manifest)) {
    $profile_fail('The fixed Genç manifest is invalid JSON.');
}

$fixture_key = 'll-tools-performance-benchmark';
$fixture_meta_key = '_ll_tools_performance_fixture';
$fixture_version_meta_key = '_ll_tools_performance_fixture_version';
$fixture_option_name = 'll_tools_performance_fixture_manifest';
$fixture_version = trim((string) ($manifest['fixtureVersion'] ?? ''));
$recorder_manifest = is_array($manifest['recorderQueue'] ?? null) ? $manifest['recorderQueue'] : [];
$wordset_slug = sanitize_title((string) ($recorder_manifest['wordsetSlug'] ?? ''));
$recorder_login = sanitize_user((string) ($recorder_manifest['username'] ?? ''), true);
$wordset_manifest = null;
foreach ((array) ($manifest['wordsets'] ?? []) as $candidate) {
    if (is_array($candidate) && sanitize_title((string) ($candidate['slug'] ?? '')) === $wordset_slug) {
        $wordset_manifest = $candidate;
        break;
    }
}
if (
    $fixture_version === ''
    || $wordset_slug !== 'll-perf-genc'
    || $recorder_login !== 'll-perf-genc-recorder'
    || !is_array($wordset_manifest)
) {
    $profile_fail('The fixed manifest does not identify the expected Genç fixture.');
}

$expected_category_count = (int) ($wordset_manifest['categoryCount'] ?? 0);
$words_per_category = (int) ($wordset_manifest['wordsPerCategory'] ?? 0);
if ($expected_category_count !== 209 || $expected_category_count > 240 || $words_per_category !== 13) {
    $profile_fail('The Genç fixture scale no longer matches the bounded profiler contract.', [
        'categories' => $expected_category_count,
        'words_per_category' => $words_per_category,
    ]);
}

try {
    $expected_manifest_checksum = ll_tools_perf_manifest_checksum_file($manifest_path);
} catch (Throwable $error) {
    $profile_fail('Could not checksum the fixed Genç manifest.', ['message' => $error->getMessage()]);
}
$stored_manifest = get_option($fixture_option_name, []);
if (
    !is_array($stored_manifest)
    || (string) ($stored_manifest['fixture_version'] ?? '') !== $fixture_version
    || !ll_tools_perf_manifest_stored_checksum_is_current($stored_manifest, $expected_manifest_checksum)
) {
    $profile_fail('The stored performance fixture manifest does not match the fixed Genç manifest.');
}

$wordset_term = get_term_by('slug', $wordset_slug, 'wordset');
if (!($wordset_term instanceof WP_Term) || is_wp_error($wordset_term)) {
    $profile_fail('The tagged Genç wordset is missing.');
}
$wordset_id = (int) $wordset_term->term_id;
if (
    (string) get_term_meta($wordset_id, $fixture_meta_key, true) !== $fixture_key
    || (string) get_term_meta($wordset_id, $fixture_version_meta_key, true) !== $fixture_version
) {
    $profile_fail('The Genç wordset marker or fixture version does not match.');
}

$recorder = get_user_by('login', $recorder_login);
if (!($recorder instanceof WP_User)) {
    $profile_fail('The tagged Genç recorder is missing.');
}
$recorder_user_id = (int) $recorder->ID;
if (
    (string) get_user_meta($recorder_user_id, $fixture_meta_key, true) !== $fixture_key
    || (string) get_user_meta($recorder_user_id, $fixture_version_meta_key, true) !== $fixture_version
) {
    $profile_fail('The Genç recorder marker or fixture version does not match.');
}

$normalize_types = static function ($value): array {
    $values = is_array($value) ? $value : explode(',', (string) $value);
    $values = array_values(array_unique(array_filter(array_map(static function ($type): string {
        return sanitize_key(trim((string) $type));
    }, $values))));
    sort($values, SORT_STRING);
    return $values;
};
$recording_config = get_user_meta($recorder_user_id, 'll_recording_config', true);
$recording_config = is_array($recording_config) ? $recording_config : [];
$expected_include_types = $normalize_types($recorder_manifest['includeRecordingTypes'] ?? []);
$expected_exclude_types = $normalize_types($recorder_manifest['excludeRecordingTypes'] ?? []);
if (
    sanitize_title((string) ($recording_config['wordset'] ?? '')) !== $wordset_slug
    || $normalize_types($recording_config['include_recording_types'] ?? '') !== $expected_include_types
    || $normalize_types($recording_config['exclude_recording_types'] ?? '') !== $expected_exclude_types
) {
    $profile_fail('The Genç recorder assignment or recording-type scope does not match the manifest.');
}
$include_types_csv = trim((string) ($recording_config['include_recording_types'] ?? ''));
$exclude_types_csv = trim((string) ($recording_config['exclude_recording_types'] ?? ''));
$profile_locale = sanitize_key((string) get_locale());
$profile_include_types = $normalize_types($include_types_csv);
$profile_exclude_types = $normalize_types($exclude_types_csv);
$per_category_cache_schema = 6;

$browser_selected_recorder_user_id = 0;
$browser_assigned_recorder_count = 0;
if ($phase === 'reset') {
    // Match the production unfocused stream selection exactly: all audio
    // recorders ordered by display name, filtered by their configured wordset,
    // with the first assigned recorder selected. Never clear a non-fixture
    // recorder's cache merely to make two diagnostic runs look comparable.
    $available_audio_recorders = get_users([
        'role' => 'audio_recorder',
        'orderby' => 'display_name',
        'order' => 'ASC',
    ]);
    foreach ((array) $available_audio_recorders as $available_audio_recorder) {
        if (!($available_audio_recorder instanceof WP_User)) {
            continue;
        }
        $available_recording_config = function_exists('ll_get_user_recording_config')
            ? ll_get_user_recording_config((int) $available_audio_recorder->ID)
            : get_user_meta((int) $available_audio_recorder->ID, 'll_recording_config', true);
        $available_wordset_slug = is_array($available_recording_config)
            ? sanitize_title((string) ($available_recording_config['wordset'] ?? ''))
            : '';
        if ($available_wordset_slug !== $wordset_slug) {
            continue;
        }
        $browser_assigned_recorder_count++;
        if ($browser_selected_recorder_user_id === 0) {
            $browser_selected_recorder_user_id = (int) $available_audio_recorder->ID;
        }
    }
    if ($browser_selected_recorder_user_id !== $recorder_user_id) {
        $profile_fail('The unfocused browser route does not select the tagged fixture recorder; no cache keys were deleted.', [
            'fixture_recorder_user_id' => $recorder_user_id,
            'browser_selected_recorder_user_id' => $browser_selected_recorder_user_id,
            'assigned_recorder_count' => $browser_assigned_recorder_count,
        ]);
    }
}

$required_functions = [
    'll_tools_wordset_page_get_recorder_queue_summary_batch_size',
    'll_tools_wordset_page_get_recorder_queue_summary_categories_cache_key',
    'll_tools_wordset_page_get_recorder_queue_summary_categories',
    'll_tools_wordset_page_build_recorder_queue_summary_manifest_scope',
    'll_tools_wordset_page_build_recorder_queue_summary_batch',
    'll_tools_wordset_page_build_cache_key',
    'll_tools_wordset_page_delete_durable_cached_payload',
];
foreach ($required_functions as $required_function) {
    if (!function_exists($required_function)) {
        $profile_fail('The active plugin does not expose a required recorder-summary function.', [
            'function' => $required_function,
        ]);
    }
}

$batch_size = ll_tools_wordset_page_get_recorder_queue_summary_batch_size();
if ($batch_size !== 6) {
    $profile_fail('The configured recorder-summary batch size is not the expected normal size of six.', [
        'batch_size' => $batch_size,
    ]);
}

$catalog_complete = true;
$catalog = ll_tools_wordset_page_get_recorder_queue_summary_categories(
    $wordset_id,
    $recorder_user_id,
    $catalog_complete
);
if (!$catalog_complete || count($catalog) !== $expected_category_count) {
    $profile_fail('The recorder-summary catalog is incomplete or not the exact Genç fixture size.', [
        'catalog_complete' => $catalog_complete,
        'expected_categories' => $expected_category_count,
        'actual_categories' => count($catalog),
    ]);
}

$expected_slug_lookup = [];
for ($category_index = 1; $category_index <= $expected_category_count; $category_index++) {
    $expected_slug_lookup[sprintf('%s-cat-%02d', $wordset_slug, $category_index)] = true;
}
$catalog_lookup = [];
foreach ($catalog as $category) {
    $category_slug = sanitize_title((string) ($category['slug'] ?? ''));
    $category_id = (int) ($category['id'] ?? 0);
    if ($category_slug === '' || !isset($expected_slug_lookup[$category_slug]) || $category_id <= 0) {
        $profile_fail('The recorder-summary catalog contains a non-fixture category.', [
            'slug' => $category_slug,
            'term_id' => $category_id,
        ]);
    }
    if (
        (string) get_term_meta($category_id, $fixture_meta_key, true) !== $fixture_key
        || (string) get_term_meta($category_id, $fixture_version_meta_key, true) !== $fixture_version
    ) {
        $profile_fail('A recorder-summary category is not tagged as the exact fixture version.', [
            'slug' => $category_slug,
            'term_id' => $category_id,
        ]);
    }
    $catalog_lookup[$category_slug] = $category;
}
if (count($catalog_lookup) !== $expected_category_count || array_diff_key($expected_slug_lookup, $catalog_lookup)) {
    $profile_fail('The recorder-summary catalog does not contain the exact expected Genç category set.');
}

$manifest_scope = ll_tools_wordset_page_build_recorder_queue_summary_manifest_scope(
    $wordset_id,
    $recorder_user_id,
    $catalog,
    $include_types_csv,
    $exclude_types_csv
);
$generation = (string) ($manifest_scope['generation'] ?? '');
$manifest_cache_key = (string) ($manifest_scope['cache_key'] ?? '');
if ($generation === '' || $manifest_cache_key === '') {
    $profile_fail('Could not derive the exact Genç recorder-summary manifest scope.');
}

$deleted_cache_keys = 0;
if (in_array($phase, ['cold', 'reset'], true)) {
    // Exact-key deletion only. There is deliberately no wp_cache_flush(),
    // transient wildcard, epoch bump, content mutation, or unrelated cache work.
    $cache_keys = [
        ll_tools_wordset_page_get_recorder_queue_summary_categories_cache_key(
            $wordset_id,
            $recorder_user_id
        ),
        $manifest_cache_key,
    ];
    foreach ($catalog as $category) {
        $cache_keys[] = ll_tools_wordset_page_build_cache_key('recorder_queue_summary', [
            'schema' => $per_category_cache_schema,
            'wordset_id' => $wordset_id,
            'recorder_user_id' => $recorder_user_id,
            'category_slug' => (string) $category['slug'],
            'category_name' => (string) $category['name'],
            'include_types' => $include_types_csv,
            'exclude_types' => $exclude_types_csv,
            'locale' => $profile_locale,
        ]);
    }
    $cache_keys = array_values(array_unique($cache_keys));
    $expected_cache_key_count = $expected_category_count + 2;
    if (count($cache_keys) !== $expected_cache_key_count) {
        $profile_fail('The verified fixture did not derive the expected exact cache-key set; no cache keys were deleted.', [
            'expected_cache_keys' => $expected_cache_key_count,
            'actual_cache_keys' => count($cache_keys),
        ]);
    }
    foreach ($cache_keys as $cache_key) {
        ll_tools_wordset_page_delete_durable_cached_payload((string) $cache_key);
        $deleted_cache_keys++;
    }
}

if ($phase === 'reset') {
    WP_CLI::line((string) wp_json_encode([
        'ok' => true,
        'phase' => 'reset',
        'fixture' => [
            'version' => $fixture_version,
            'manifest_sha256' => $expected_manifest_checksum,
            'wordset_id' => $wordset_id,
            'wordset_slug' => $wordset_slug,
            'recorder_user_id' => $recorder_user_id,
            'recorder_login' => $recorder_login,
            'category_count' => $expected_category_count,
            'generation' => $generation,
        ],
        'browser_selection' => [
            'verified' => true,
            'selected_recorder_user_id' => $browser_selected_recorder_user_id,
            'assigned_recorder_count' => $browser_assigned_recorder_count,
        ],
        'cache_scope' => [
            'locale' => $profile_locale,
            'include_types_raw' => $include_types_csv,
            'include_types_normalized' => $profile_include_types,
            'exclude_types_raw' => $exclude_types_csv,
            'exclude_types_normalized' => $profile_exclude_types,
            'per_category_schema' => $per_category_cache_schema,
        ],
        'cache' => [
            'exact_keys_deleted' => $deleted_cache_keys,
            'broad_flush_used' => false,
            'rebuild_started' => false,
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    WP_CLI::halt(0);
}

global $wpdb;
$profile_started_at = microtime(true);
$profile_started_queries = (int) $wpdb->num_queries;
$queue = array_values(array_map(static function (array $category): string {
    return (string) $category['slug'];
}, $catalog));
$attempts_by_slug = [];
$terminal_slugs = [];
$resolved_lookup = [];
$batch_results = [];
$expected_generation = $generation;
$max_attempts_per_slug = 8;

while (!empty($queue) && count($batch_results) < $max_batches) {
    if ((microtime(true) - $profile_started_at) >= $max_seconds) {
        break;
    }
    $requested_slugs = array_splice($queue, 0, $batch_size);
    $batch_started_at = microtime(true);
    $batch_started_queries = (int) $wpdb->num_queries;
    $result = ll_tools_wordset_page_build_recorder_queue_summary_batch(
        $wordset_id,
        $wordset_term,
        $recorder_user_id,
        $requested_slugs,
        '',
        [
            'include_recording_types' => $include_types_csv,
            'exclude_recording_types' => $exclude_types_csv,
            'card_interaction' => 'button',
        ]
    );
    $batch_elapsed_ms = round((microtime(true) - $batch_started_at) * 1000, 3);
    $batch_queries = (int) $wpdb->num_queries - $batch_started_queries;

    if (
        empty($result['catalogComplete'])
        || (int) ($result['sourceTotal'] ?? 0) !== $expected_category_count
        || (int) ($result['batchSize'] ?? 0) !== $batch_size
        || !hash_equals($expected_generation, (string) ($result['generation'] ?? ''))
    ) {
        $profile_fail('A recorder-summary batch violated the verified fixture scope.', [
            'batch' => count($batch_results) + 1,
            'catalog_complete' => !empty($result['catalogComplete']),
            'source_total' => (int) ($result['sourceTotal'] ?? 0),
            'batch_size' => (int) ($result['batchSize'] ?? 0),
            'generation' => (string) ($result['generation'] ?? ''),
        ]);
    }

    $resolved_slugs = array_values(array_intersect(
        $requested_slugs,
        array_map('sanitize_title', (array) ($result['resolvedSlugs'] ?? []))
    ));
    $pending_slugs = array_values(array_intersect(
        $requested_slugs,
        array_map('sanitize_title', (array) ($result['pendingSlugs'] ?? []))
    ));
    $classified_lookup = array_fill_keys(array_merge($resolved_slugs, $pending_slugs), true);
    foreach ($requested_slugs as $requested_slug) {
        if (!isset($classified_lookup[$requested_slug])) {
            $profile_fail('A recorder-summary batch did not classify a requested fixture category.', [
                'batch' => count($batch_results) + 1,
                'slug' => $requested_slug,
            ]);
        }
    }
    foreach ($resolved_slugs as $resolved_slug) {
        $resolved_lookup[$resolved_slug] = true;
    }
    foreach ($pending_slugs as $pending_slug) {
        $attempts_by_slug[$pending_slug] = (int) ($attempts_by_slug[$pending_slug] ?? 0) + 1;
        if ($attempts_by_slug[$pending_slug] >= $max_attempts_per_slug) {
            $terminal_slugs[$pending_slug] = $attempts_by_slug[$pending_slug];
        } else {
            $queue[] = $pending_slug;
        }
    }

    $batch_results[] = [
        'batch' => count($batch_results) + 1,
        'requested' => $requested_slugs,
        'resolved_count' => count($resolved_slugs),
        'pending_count' => count($pending_slugs),
        'elapsed_ms' => $batch_elapsed_ms,
        'queries' => $batch_queries,
        'status' => is_array($result['status'] ?? null) ? $result['status'] : [],
    ];
}

$elapsed_samples = array_values(array_map(static function (array $batch): float {
    return (float) $batch['elapsed_ms'];
}, $batch_results));
$query_samples = array_values(array_map(static function (array $batch): int {
    return (int) $batch['queries'];
}, $batch_results));
sort($elapsed_samples, SORT_NUMERIC);
$percentile = static function (array $samples, float $percentile): float {
    if (empty($samples)) {
        return 0.0;
    }
    $index = (int) ceil((count($samples) * $percentile) - 1);
    $index = max(0, min(count($samples) - 1, $index));
    return round((float) $samples[$index], 3);
};

$remaining_slugs = array_values(array_unique(array_merge($queue, array_keys($terminal_slugs))));
$profile_elapsed_ms = round((microtime(true) - $profile_started_at) * 1000, 3);
$profile_queries = (int) $wpdb->num_queries - $profile_started_queries;
$complete = empty($remaining_slugs) && count($resolved_lookup) === $expected_category_count;
$output = [
    'ok' => $complete,
    'phase' => $phase,
    'fixture' => [
        'version' => $fixture_version,
        'manifest_sha256' => $expected_manifest_checksum,
        'wordset_id' => $wordset_id,
        'wordset_slug' => $wordset_slug,
        'recorder_user_id' => $recorder_user_id,
        'recorder_login' => $recorder_login,
        'category_count' => $expected_category_count,
        'generation' => $generation,
    ],
    'limits' => [
        'batch_size' => $batch_size,
        'max_batches' => $max_batches,
        'max_seconds' => $max_seconds,
        'max_attempts_per_slug' => $max_attempts_per_slug,
    ],
    'cache' => [
        'exact_keys_deleted' => $deleted_cache_keys,
        'broad_flush_used' => false,
        'catalog_preflight_not_timed' => true,
    ],
    'cache_scope' => [
        'locale' => $profile_locale,
        'include_types_raw' => $include_types_csv,
        'include_types_normalized' => $profile_include_types,
        'exclude_types_raw' => $exclude_types_csv,
        'exclude_types_normalized' => $profile_exclude_types,
        'per_category_schema' => $per_category_cache_schema,
    ],
    'summary' => [
        'complete' => $complete,
        'resolved_categories' => count($resolved_lookup),
        'remaining_categories' => count($remaining_slugs),
        'batch_count' => count($batch_results),
        'elapsed_ms' => $profile_elapsed_ms,
        'queries' => $profile_queries,
        'batch_elapsed_ms_min' => empty($elapsed_samples) ? 0.0 : round((float) min($elapsed_samples), 3),
        'batch_elapsed_ms_median' => $percentile($elapsed_samples, 0.5),
        'batch_elapsed_ms_p95' => $percentile($elapsed_samples, 0.95),
        'batch_elapsed_ms_max' => empty($elapsed_samples) ? 0.0 : round((float) max($elapsed_samples), 3),
        'batch_queries_min' => empty($query_samples) ? 0 : min($query_samples),
        'batch_queries_max' => empty($query_samples) ? 0 : max($query_samples),
    ],
    'remaining_slugs' => $remaining_slugs,
    'attempts_exhausted' => $terminal_slugs,
    'batches' => $batch_results,
];

WP_CLI::line((string) wp_json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
if (!$complete) {
    WP_CLI::halt(2);
}
