<?php
/**
 * Profile one guarded Genç recorder-summary batch in a fresh PHP process.
 *
 * This file is launched by profile-recorder-summary-fresh-process.ps1. It is
 * intentionally not a WP-CLI eval-file: SAVEQUERIES and the request timer must
 * be enabled before WordPress bootstraps.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$ll_tools_profile_process_started_at = microtime(true);

$ll_tools_profile_fail = static function (string $message, array $context = []): void {
    $payload = [
        'ok' => false,
        'error' => $message,
    ];
    if (!empty($context)) {
        $payload['context'] = $context;
    }
    fwrite(STDERR, (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
};

$ll_tools_profile_args = [];
foreach (array_slice((array) ($_SERVER['argv'] ?? []), 1) as $argument) {
    if (!is_string($argument) || strpos($argument, '--') !== 0 || strpos($argument, '=') === false) {
        $ll_tools_profile_fail('Arguments must use the form --name=value.');
    }
    [$name, $value] = explode('=', substr($argument, 2), 2);
    if ($name === '' || array_key_exists($name, $ll_tools_profile_args)) {
        $ll_tools_profile_fail('Arguments must have unique nonempty names.');
    }
    $ll_tools_profile_args[$name] = $value;
}

$ll_tools_profile_required_args = [
    'phase',
    'wp-root',
    'manager-login',
    'wordset-id',
    'recorder-user-id',
    'fixture-version',
    'manifest-sha256',
    'generation',
];
foreach ($ll_tools_profile_required_args as $required_arg) {
    if (!isset($ll_tools_profile_args[$required_arg]) || trim((string) $ll_tools_profile_args[$required_arg]) === '') {
        $ll_tools_profile_fail('A required argument is missing.', ['argument' => $required_arg]);
    }
}
if (array_diff(array_keys($ll_tools_profile_args), $ll_tools_profile_required_args)) {
    $ll_tools_profile_fail('An unexpected argument was supplied.');
}

$ll_tools_profile_phase = strtolower(trim((string) $ll_tools_profile_args['phase']));
$ll_tools_profile_slug_ranges = [
    'first-cold6' => [1, 6],
    'later-cold6' => [7, 12],
    'warm6' => [1, 6],
];
if (!isset($ll_tools_profile_slug_ranges[$ll_tools_profile_phase])) {
    $ll_tools_profile_fail('Phase must be first-cold6, later-cold6, or warm6.');
}

$ll_tools_profile_wp_root = realpath((string) $ll_tools_profile_args['wp-root']);
$ll_tools_profile_wp_load = is_string($ll_tools_profile_wp_root)
    ? $ll_tools_profile_wp_root . DIRECTORY_SEPARATOR . 'wp-load.php'
    : '';
if (!is_string($ll_tools_profile_wp_root) || !is_readable($ll_tools_profile_wp_load)) {
    $ll_tools_profile_fail('The supplied WordPress root is invalid or wp-load.php is unreadable.');
}

$ll_tools_profile_manager_login = trim((string) $ll_tools_profile_args['manager-login']);
$ll_tools_profile_wordset_id = (int) $ll_tools_profile_args['wordset-id'];
$ll_tools_profile_recorder_user_id = (int) $ll_tools_profile_args['recorder-user-id'];
$ll_tools_profile_fixture_version = trim((string) $ll_tools_profile_args['fixture-version']);
$ll_tools_profile_manifest_sha256 = strtolower(trim((string) $ll_tools_profile_args['manifest-sha256']));
$ll_tools_profile_expected_generation = strtolower(trim((string) $ll_tools_profile_args['generation']));
if (
    $ll_tools_profile_wordset_id <= 0
    || $ll_tools_profile_recorder_user_id <= 0
    || !preg_match('/^[a-f0-9]{64}$/', $ll_tools_profile_manifest_sha256)
    || !preg_match('/^[a-f0-9]{32}$/', $ll_tools_profile_expected_generation)
) {
    $ll_tools_profile_fail('Fixture IDs, manifest checksum, or generation are invalid.');
}

[$ll_tools_profile_slug_start, $ll_tools_profile_slug_end] = $ll_tools_profile_slug_ranges[$ll_tools_profile_phase];
$ll_tools_profile_requested_slugs = [];
for ($category_index = $ll_tools_profile_slug_start; $category_index <= $ll_tools_profile_slug_end; $category_index++) {
    $ll_tools_profile_requested_slugs[] = sprintf('ll-perf-genc-cat-%02d', $category_index);
}

if (!defined('WP_ADMIN')) {
    define('WP_ADMIN', true);
}
if (!defined('DOING_AJAX')) {
    define('DOING_AJAX', true);
}
if (!defined('SAVEQUERIES')) {
    define('SAVEQUERIES', true);
}
if (!SAVEQUERIES) {
    $ll_tools_profile_fail('SAVEQUERIES must be enabled before WordPress loads.');
}

$_SERVER['REQUEST_METHOD'] = 'POST';
$_REQUEST['action'] = 'll_tools_wordset_recorder_queue_summaries';
$_POST['action'] = 'll_tools_wordset_recorder_queue_summaries';

require_once $ll_tools_profile_wp_load;

global $wpdb;
if (!($wpdb instanceof wpdb) || !isset($wpdb->queries) || !is_array($wpdb->queries)) {
    $ll_tools_profile_fail('WordPress did not expose SAVEQUERIES data after bootstrap.');
}

$ll_tools_profile_wp_loaded_at = microtime(true);
$ll_tools_profile_queries_after_wp_load = (int) $wpdb->num_queries;
$ll_tools_profile_saved_after_wp_load = count($wpdb->queries);

$ll_tools_profile_manager_setup_started_at = microtime(true);
$ll_tools_profile_manager = get_user_by('login', $ll_tools_profile_manager_login);
if (!($ll_tools_profile_manager instanceof WP_User) || !$ll_tools_profile_manager->exists()) {
    $ll_tools_profile_fail('The supplied manager login does not identify a local WordPress user.');
}
wp_set_current_user((int) $ll_tools_profile_manager->ID);
$ll_tools_profile_manager_setup_finished_at = microtime(true);
$ll_tools_profile_queries_after_manager_setup = (int) $wpdb->num_queries;
$ll_tools_profile_saved_after_manager_setup = count($wpdb->queries);

$ll_tools_profile_admin_started_at = microtime(true);
require_once ABSPATH . 'wp-admin/includes/admin.php';
require_once ABSPATH . 'wp-admin/includes/ajax-actions.php';
do_action('admin_init');
$ll_tools_profile_admin_finished_at = microtime(true);
$ll_tools_profile_queries_after_admin = (int) $wpdb->num_queries;
$ll_tools_profile_saved_after_admin = count($wpdb->queries);

$ll_tools_profile_guard_started_at = microtime(true);
$ll_tools_profile_repository_root = dirname(__DIR__, 2);
$ll_tools_profile_manifest_path = $ll_tools_profile_repository_root . '/tests/performance/fixtures/performance-wordsets-genc.json';
$ll_tools_profile_checksum_helpers = $ll_tools_profile_repository_root . '/tests/performance/lib/performance-manifest.php';
if (!is_readable($ll_tools_profile_manifest_path) || !is_readable($ll_tools_profile_checksum_helpers)) {
    $ll_tools_profile_fail('The fixed Genç manifest or checksum helper is unreadable.');
}
require_once $ll_tools_profile_checksum_helpers;

$ll_tools_profile_home_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
$ll_tools_profile_host_has_suffix = static function (string $host, string $suffix): bool {
    return $suffix !== '' && strlen($host) >= strlen($suffix)
        && substr($host, -strlen($suffix)) === $suffix;
};
$ll_tools_profile_is_local_host = in_array($ll_tools_profile_home_host, ['localhost', '127.0.0.1', '::1'], true)
    || $ll_tools_profile_host_has_suffix($ll_tools_profile_home_host, '.localhost')
    || $ll_tools_profile_host_has_suffix($ll_tools_profile_home_host, '.local')
    || $ll_tools_profile_host_has_suffix($ll_tools_profile_home_host, '.test');
if (!$ll_tools_profile_is_local_host) {
    $ll_tools_profile_fail('Fresh-process profiling is restricted to an explicit local WordPress home host.', [
        'home_host' => $ll_tools_profile_home_host,
    ]);
}

$ll_tools_profile_manifest_json = file_get_contents($ll_tools_profile_manifest_path);
$ll_tools_profile_manifest = is_string($ll_tools_profile_manifest_json)
    ? json_decode($ll_tools_profile_manifest_json, true)
    : null;
if (!is_array($ll_tools_profile_manifest)) {
    $ll_tools_profile_fail('The fixed Genç manifest is invalid JSON.');
}
try {
    $ll_tools_profile_actual_checksum = ll_tools_perf_manifest_checksum_file($ll_tools_profile_manifest_path);
} catch (Throwable $error) {
    $ll_tools_profile_fail('The fixed Genç manifest checksum could not be calculated.', [
        'message' => $error->getMessage(),
    ]);
}
$ll_tools_profile_wordset_manifest = null;
foreach ((array) ($ll_tools_profile_manifest['wordsets'] ?? []) as $candidate) {
    if (is_array($candidate) && (string) ($candidate['slug'] ?? '') === 'll-perf-genc') {
        $ll_tools_profile_wordset_manifest = $candidate;
        break;
    }
}
if (
    !hash_equals($ll_tools_profile_manifest_sha256, $ll_tools_profile_actual_checksum)
    || (string) ($ll_tools_profile_manifest['fixtureVersion'] ?? '') !== $ll_tools_profile_fixture_version
    || !is_array($ll_tools_profile_wordset_manifest)
    || (int) ($ll_tools_profile_wordset_manifest['categoryCount'] ?? 0) !== 209
    || (int) ($ll_tools_profile_wordset_manifest['wordsPerCategory'] ?? 0) !== 13
    || sanitize_title((string) ($ll_tools_profile_manifest['recorderQueue']['wordsetSlug'] ?? '')) !== 'll-perf-genc'
    || sanitize_user((string) ($ll_tools_profile_manifest['recorderQueue']['username'] ?? ''), true) !== 'll-perf-genc-recorder'
) {
    $ll_tools_profile_fail('The local Genç manifest does not match the guarded profiler contract.');
}

$ll_tools_profile_stored_manifest = get_option('ll_tools_performance_fixture_manifest', []);
if (
    !is_array($ll_tools_profile_stored_manifest)
    || (string) ($ll_tools_profile_stored_manifest['fixture_version'] ?? '') !== $ll_tools_profile_fixture_version
    || !ll_tools_perf_manifest_stored_checksum_is_current(
        $ll_tools_profile_stored_manifest,
        $ll_tools_profile_manifest_sha256
    )
) {
    $ll_tools_profile_fail('The stored fixture manifest does not match the guarded Genç manifest.');
}

$ll_tools_profile_fixture_key = 'll-tools-performance-benchmark';
$ll_tools_profile_fixture_meta_key = '_ll_tools_performance_fixture';
$ll_tools_profile_fixture_version_meta_key = '_ll_tools_performance_fixture_version';
$ll_tools_profile_wordset_row = $wpdb->get_row($wpdb->prepare(
    "SELECT terms.slug
     FROM {$wpdb->terms} AS terms
     INNER JOIN {$wpdb->term_taxonomy} AS taxonomy
        ON taxonomy.term_id = terms.term_id
        AND taxonomy.taxonomy = 'wordset'
     WHERE terms.term_id = %d
     LIMIT 1",
    $ll_tools_profile_wordset_id
), ARRAY_A);
$ll_tools_profile_recorder_login = (string) $wpdb->get_var($wpdb->prepare(
    "SELECT user_login FROM {$wpdb->users} WHERE ID = %d LIMIT 1",
    $ll_tools_profile_recorder_user_id
));
$ll_tools_profile_wordset_marker = (string) $wpdb->get_var($wpdb->prepare(
    "SELECT meta_value FROM {$wpdb->termmeta} WHERE term_id = %d AND meta_key = %s LIMIT 1",
    $ll_tools_profile_wordset_id,
    $ll_tools_profile_fixture_meta_key
));
$ll_tools_profile_wordset_version = (string) $wpdb->get_var($wpdb->prepare(
    "SELECT meta_value FROM {$wpdb->termmeta} WHERE term_id = %d AND meta_key = %s LIMIT 1",
    $ll_tools_profile_wordset_id,
    $ll_tools_profile_fixture_version_meta_key
));
$ll_tools_profile_recorder_marker = (string) $wpdb->get_var($wpdb->prepare(
    "SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s LIMIT 1",
    $ll_tools_profile_recorder_user_id,
    $ll_tools_profile_fixture_meta_key
));
$ll_tools_profile_recorder_version = (string) $wpdb->get_var($wpdb->prepare(
    "SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s LIMIT 1",
    $ll_tools_profile_recorder_user_id,
    $ll_tools_profile_fixture_version_meta_key
));
if (
    (string) ($ll_tools_profile_wordset_row['slug'] ?? '') !== 'll-perf-genc'
    || $ll_tools_profile_recorder_login !== 'll-perf-genc-recorder'
    || $ll_tools_profile_wordset_marker !== $ll_tools_profile_fixture_key
    || $ll_tools_profile_recorder_marker !== $ll_tools_profile_fixture_key
    || $ll_tools_profile_wordset_version !== $ll_tools_profile_fixture_version
    || $ll_tools_profile_recorder_version !== $ll_tools_profile_fixture_version
) {
    $ll_tools_profile_fail('The supplied IDs are not the checksum-verified tagged Genç fixture targets.');
}

$ll_tools_profile_slug_placeholders = implode(', ', array_fill(0, count($ll_tools_profile_requested_slugs), '%s'));
$ll_tools_profile_category_sql = $wpdb->prepare(
    "SELECT terms.term_id, terms.slug
     FROM {$wpdb->terms} AS terms
     INNER JOIN {$wpdb->term_taxonomy} AS taxonomy
        ON taxonomy.term_id = terms.term_id
        AND taxonomy.taxonomy = 'word-category'
     WHERE terms.slug IN ({$ll_tools_profile_slug_placeholders})",
    $ll_tools_profile_requested_slugs
);
$ll_tools_profile_category_rows = $wpdb->get_results($ll_tools_profile_category_sql, ARRAY_A);
$ll_tools_profile_category_ids = [];
foreach ((array) $ll_tools_profile_category_rows as $category_row) {
    $slug = sanitize_title((string) ($category_row['slug'] ?? ''));
    if (in_array($slug, $ll_tools_profile_requested_slugs, true)) {
        $ll_tools_profile_category_ids[$slug] = (int) ($category_row['term_id'] ?? 0);
    }
}
if (count($ll_tools_profile_category_ids) !== 6) {
    $ll_tools_profile_fail('The six deterministic profile categories are not present.');
}
foreach ($ll_tools_profile_requested_slugs as $requested_slug) {
    $category_id = (int) ($ll_tools_profile_category_ids[$requested_slug] ?? 0);
    $category_marker = (string) $wpdb->get_var($wpdb->prepare(
        "SELECT meta_value FROM {$wpdb->termmeta} WHERE term_id = %d AND meta_key = %s LIMIT 1",
        $category_id,
        $ll_tools_profile_fixture_meta_key
    ));
    $category_version = (string) $wpdb->get_var($wpdb->prepare(
        "SELECT meta_value FROM {$wpdb->termmeta} WHERE term_id = %d AND meta_key = %s LIMIT 1",
        $category_id,
        $ll_tools_profile_fixture_version_meta_key
    ));
    if (
        $category_marker !== $ll_tools_profile_fixture_key
        || $category_version !== $ll_tools_profile_fixture_version
    ) {
        $ll_tools_profile_fail('A requested category is not tagged as the exact guarded fixture.', [
            'slug' => $requested_slug,
        ]);
    }
}

$ll_tools_profile_required_functions = [
    'll_tools_current_user_can_manage_wordset_content',
    'll_tools_wordset_page_is_recorder_assigned_to_wordset',
    'll_tools_wordset_page_build_recorder_queue_summary_batch',
    'll_tools_wordset_page_get_recorder_queue_summary_batch_size',
];
foreach ($ll_tools_profile_required_functions as $required_function) {
    if (!function_exists($required_function)) {
        $ll_tools_profile_fail('The active plugin does not expose a required recorder-summary function.', [
            'function' => $required_function,
        ]);
    }
}
if (ll_tools_wordset_page_get_recorder_queue_summary_batch_size() !== 6) {
    $ll_tools_profile_fail('The configured recorder-summary batch size is not six.');
}
$ll_tools_profile_guard_finished_at = microtime(true);
$ll_tools_profile_queries_after_guard = (int) $wpdb->num_queries;
$ll_tools_profile_saved_after_guard = count($wpdb->queries);

$ll_tools_profile_authorization_started_at = microtime(true);
$ll_tools_profile_authorization_query_start = count($wpdb->queries);
$ll_tools_profile_wordset_term = get_term($ll_tools_profile_wordset_id, 'wordset');
$ll_tools_profile_recorder_user = get_userdata($ll_tools_profile_recorder_user_id);
$ll_tools_profile_nonce = wp_create_nonce('ll_tools_wordset_recorder_queue_summaries');
$ll_tools_profile_authorized = $ll_tools_profile_wordset_term instanceof WP_Term
    && !is_wp_error($ll_tools_profile_wordset_term)
    && wp_verify_nonce($ll_tools_profile_nonce, 'll_tools_wordset_recorder_queue_summaries')
    && current_user_can('view_ll_tools')
    && ll_tools_current_user_can_manage_wordset_content($ll_tools_profile_wordset_id)
    && $ll_tools_profile_recorder_user instanceof WP_User
    && in_array('audio_recorder', (array) $ll_tools_profile_recorder_user->roles, true)
    && ll_tools_wordset_page_is_recorder_assigned_to_wordset(
        $ll_tools_profile_recorder_user_id,
        $ll_tools_profile_wordset_term
    );
$ll_tools_profile_authorization_finished_at = microtime(true);
$ll_tools_profile_authorization_query_end = count($wpdb->queries);
if (!$ll_tools_profile_authorized) {
    $ll_tools_profile_fail('The supplied manager cannot pass the production recorder-summary authorization checks.');
}

$ll_tools_profile_batch_started_at = microtime(true);
$ll_tools_profile_batch_query_start = count($wpdb->queries);
$ll_tools_profile_action_url = ll_tools_get_wordset_settings_tool_url(
    $ll_tools_profile_wordset_term,
    'recorder-queues',
    ''
);
$ll_tools_profile_result = ll_tools_wordset_page_build_recorder_queue_summary_batch(
    $ll_tools_profile_wordset_id,
    $ll_tools_profile_wordset_term,
    $ll_tools_profile_recorder_user_id,
    $ll_tools_profile_requested_slugs,
    $ll_tools_profile_action_url
);
$ll_tools_profile_batch_finished_at = microtime(true);
$ll_tools_profile_batch_query_end = count($wpdb->queries);

$ll_tools_profile_resolved_slugs = array_values(array_intersect(
    $ll_tools_profile_requested_slugs,
    array_map('sanitize_title', (array) ($ll_tools_profile_result['resolvedSlugs'] ?? []))
));
$ll_tools_profile_pending_slugs = array_values(array_intersect(
    $ll_tools_profile_requested_slugs,
    array_map('sanitize_title', (array) ($ll_tools_profile_result['pendingSlugs'] ?? []))
));
$ll_tools_profile_classified = array_values(array_unique(array_merge(
    $ll_tools_profile_resolved_slugs,
    $ll_tools_profile_pending_slugs
)));
sort($ll_tools_profile_classified, SORT_STRING);
$ll_tools_profile_expected_classified = $ll_tools_profile_requested_slugs;
sort($ll_tools_profile_expected_classified, SORT_STRING);
if (
    empty($ll_tools_profile_result['catalogComplete'])
    || (int) ($ll_tools_profile_result['sourceTotal'] ?? 0) !== 209
    || (int) ($ll_tools_profile_result['batchSize'] ?? 0) !== 6
    || !hash_equals($ll_tools_profile_expected_generation, (string) ($ll_tools_profile_result['generation'] ?? ''))
    || $ll_tools_profile_classified !== $ll_tools_profile_expected_classified
) {
    $ll_tools_profile_fail('The measured batch violated the guarded fixture or classification contract.', [
        'catalog_complete' => !empty($ll_tools_profile_result['catalogComplete']),
        'source_total' => (int) ($ll_tools_profile_result['sourceTotal'] ?? 0),
        'batch_size' => (int) ($ll_tools_profile_result['batchSize'] ?? 0),
        'generation' => (string) ($ll_tools_profile_result['generation'] ?? ''),
        'classified_slugs' => $ll_tools_profile_classified,
    ]);
}

$ll_tools_profile_serialization_started_at = microtime(true);
$ll_tools_profile_encoded_response = wp_json_encode([
    'success' => true,
    'data' => $ll_tools_profile_result,
]);
$ll_tools_profile_serialization_finished_at = microtime(true);
if (!is_string($ll_tools_profile_encoded_response)) {
    $ll_tools_profile_fail('The measured response could not be serialized.');
}

$ll_tools_profile_query_summary = static function (array $queries, int $start, int $end): array {
    $count = 0;
    $elapsed_ms = 0.0;
    for ($index = max(0, $start); $index < min($end, count($queries)); $index++) {
        $entry = $queries[$index] ?? null;
        if (!is_array($entry)) {
            continue;
        }
        $count++;
        $elapsed_ms += max(0.0, (float) ($entry[1] ?? 0.0)) * 1000;
    }
    return [
        'count' => $count,
        'database_elapsed_ms' => round($elapsed_ms, 3),
    ];
};

$ll_tools_profile_attributed_queries = [
    'catalog' => ['count' => 0, 'database_elapsed_ms' => 0.0],
    'source_signature_generation' => ['count' => 0, 'database_elapsed_ms' => 0.0],
    'summary_scan' => ['count' => 0, 'database_elapsed_ms' => 0.0],
    'render' => ['count' => 0, 'database_elapsed_ms' => 0.0],
    'durable_cache_manifest' => ['count' => 0, 'database_elapsed_ms' => 0.0],
    'other' => ['count' => 0, 'database_elapsed_ms' => 0.0],
];
for ($query_index = $ll_tools_profile_batch_query_start; $query_index < $ll_tools_profile_batch_query_end; $query_index++) {
    $query_entry = $wpdb->queries[$query_index] ?? null;
    if (!is_array($query_entry)) {
        continue;
    }
    $caller = strtolower((string) ($query_entry[2] ?? ''));
    if (strpos($caller, 'render_recorder_queue') !== false) {
        $bucket = 'render';
    } elseif (strpos($caller, 'get_recorder_queue_summary_categories') !== false) {
        $bucket = 'catalog';
    } elseif (
        strpos($caller, 'recorder_queue_summary_source_signature') !== false
        || strpos($caller, 'recorder_queue_summary_hidden_source_signatures') !== false
        || strpos($caller, 'recorder_queue_summary_global_source_signature') !== false
        || strpos($caller, 'recorder_queue_summary_wordset_source_signature') !== false
        || strpos($caller, 'get_recorder_queue_summary_generation') !== false
    ) {
        $bucket = 'source_signature_generation';
    } elseif (
        strpos($caller, 'build_recorder_queue_summary_group') !== false
        || strpos($caller, 'build_recorder_queue_summary_groups') !== false
    ) {
        $bucket = 'summary_scan';
    } elseif (
        strpos($caller, 'cached_payload') !== false
        || strpos($caller, 'cache_rebuild_lock') !== false
        || strpos($caller, 'recorder_queue_summary_manifest') !== false
    ) {
        $bucket = 'durable_cache_manifest';
    } else {
        $bucket = 'other';
    }
    $ll_tools_profile_attributed_queries[$bucket]['count']++;
    $ll_tools_profile_attributed_queries[$bucket]['database_elapsed_ms'] += max(
        0.0,
        (float) ($query_entry[1] ?? 0.0)
    ) * 1000;
}
foreach ($ll_tools_profile_attributed_queries as &$attributed_bucket) {
    $attributed_bucket['database_elapsed_ms'] = round(
        (float) $attributed_bucket['database_elapsed_ms'],
        3
    );
}
unset($attributed_bucket);

$ll_tools_profile_slowest_summary_queries = [];
$ll_tools_profile_immediate_caller_aggregates = [];
for ($query_index = $ll_tools_profile_batch_query_start; $query_index < $ll_tools_profile_batch_query_end; $query_index++) {
    $query_entry = $wpdb->queries[$query_index] ?? null;
    if (!is_array($query_entry)) {
        continue;
    }
    $query_elapsed_ms = round(max(0.0, (float) ($query_entry[1] ?? 0.0)) * 1000, 3);
    $caller_stack = substr((string) ($query_entry[2] ?? ''), 0, 1000);
    $ll_tools_profile_slowest_summary_queries[] = [
        'database_elapsed_ms' => $query_elapsed_ms,
        // SAVEQUERIES exposes WordPress's function-only caller summary here;
        // never emit the SQL text or its values from entry zero.
        'caller_stack' => $caller_stack,
    ];

    $caller_parts = array_values(array_filter(array_map('trim', explode(',', $caller_stack)), static function (string $part): bool {
        return $part !== '';
    }));
    $immediate_caller = 'unknown';
    $immediate_index = -1;
    // WordPress records caller summaries from the outer plugin call toward the
    // inner database call. Walk backward so the group is the actual immediate
    // non-wpdb caller rather than the outer batch builder repeated everywhere.
    for ($caller_index = count($caller_parts) - 1; $caller_index >= 0; $caller_index--) {
        $caller_part = $caller_parts[$caller_index];
        $normalized_part = strtolower($caller_part);
        if (
            strpos($normalized_part, 'wpdb->') === 0
            || strpos($normalized_part, 'wpdb::') === 0
            || strpos($normalized_part, 'mysqli_') === 0
        ) {
            continue;
        }
        $immediate_caller = $caller_part;
        $immediate_index = $caller_index;
        break;
    }
    $nearest_plugin_parent = '';
    for ($parent_index = $immediate_index - 1; $parent_index >= 0; $parent_index--) {
        $parent_part = $caller_parts[$parent_index];
        $normalized_parent = strtolower($parent_part);
        if (
            strpos($normalized_parent, 'll_tools_') !== false
            || preg_match('/(?:^|[^a-z0-9])ll_[a-z0-9_]+/', $normalized_parent)
        ) {
            $nearest_plugin_parent = $parent_part;
            break;
        }
    }
    if ($nearest_plugin_parent !== '' && $nearest_plugin_parent !== $immediate_caller) {
        $immediate_caller .= ' <- ' . $nearest_plugin_parent;
    }
    $immediate_caller = substr($immediate_caller, 0, 500);
    if (!isset($ll_tools_profile_immediate_caller_aggregates[$immediate_caller])) {
        $ll_tools_profile_immediate_caller_aggregates[$immediate_caller] = [
            'caller' => $immediate_caller,
            'count' => 0,
            'database_elapsed_ms' => 0.0,
            'max_database_elapsed_ms' => 0.0,
        ];
    }
    $ll_tools_profile_immediate_caller_aggregates[$immediate_caller]['count']++;
    $ll_tools_profile_immediate_caller_aggregates[$immediate_caller]['database_elapsed_ms'] += $query_elapsed_ms;
    $ll_tools_profile_immediate_caller_aggregates[$immediate_caller]['max_database_elapsed_ms'] = max(
        (float) $ll_tools_profile_immediate_caller_aggregates[$immediate_caller]['max_database_elapsed_ms'],
        $query_elapsed_ms
    );
}
usort($ll_tools_profile_slowest_summary_queries, static function (array $left, array $right): int {
    return (float) $right['database_elapsed_ms'] <=> (float) $left['database_elapsed_ms'];
});
$ll_tools_profile_slowest_summary_queries = array_slice($ll_tools_profile_slowest_summary_queries, 0, 5);
$ll_tools_profile_immediate_caller_aggregates = array_values($ll_tools_profile_immediate_caller_aggregates);
foreach ($ll_tools_profile_immediate_caller_aggregates as &$caller_aggregate) {
    $caller_aggregate['database_elapsed_ms'] = round((float) $caller_aggregate['database_elapsed_ms'], 3);
    $caller_aggregate['max_database_elapsed_ms'] = round((float) $caller_aggregate['max_database_elapsed_ms'], 3);
    $caller_aggregate['average_database_elapsed_ms'] = $caller_aggregate['count'] > 0
        ? round($caller_aggregate['database_elapsed_ms'] / $caller_aggregate['count'], 3)
        : 0.0;
}
unset($caller_aggregate);
usort($ll_tools_profile_immediate_caller_aggregates, static function (array $left, array $right): int {
    $elapsed_order = (float) $right['database_elapsed_ms'] <=> (float) $left['database_elapsed_ms'];
    return $elapsed_order !== 0
        ? $elapsed_order
        : ((int) $right['count'] <=> (int) $left['count']);
});
$ll_tools_profile_immediate_caller_aggregates = array_slice($ll_tools_profile_immediate_caller_aggregates, 0, 10);

$ll_tools_profile_finished_at = microtime(true);
$ll_tools_profile_output = [
    'ok' => true,
    'phase' => $ll_tools_profile_phase,
    'fixture' => [
        'version' => $ll_tools_profile_fixture_version,
        'manifest_sha256' => $ll_tools_profile_manifest_sha256,
        'wordset_id' => $ll_tools_profile_wordset_id,
        'recorder_user_id' => $ll_tools_profile_recorder_user_id,
        'generation' => $ll_tools_profile_expected_generation,
        'category_count' => 209,
        'words_per_category' => 13,
        'requested_slugs' => $ll_tools_profile_requested_slugs,
        'local_home_host' => $ll_tools_profile_home_host,
    ],
    'request_model' => [
        'fresh_php_process' => true,
        'doing_ajax' => DOING_AJAX,
        'wp_admin' => WP_ADMIN,
        'savequeries' => SAVEQUERIES,
        'manager_auth_mode' => 'post-bootstrap-wp-set-current-user',
        'production_handler_called' => false,
        'production_batch_builder_called_once' => true,
    ],
    'timings_ms' => [
        'request_start_to_wp_loaded' => round(($ll_tools_profile_wp_loaded_at - $ll_tools_profile_process_started_at) * 1000, 3),
        'manager_setup' => round(($ll_tools_profile_manager_setup_finished_at - $ll_tools_profile_manager_setup_started_at) * 1000, 3),
        'admin_ajax_setup' => round(($ll_tools_profile_admin_finished_at - $ll_tools_profile_admin_started_at) * 1000, 3),
        'fixture_guard' => round(($ll_tools_profile_guard_finished_at - $ll_tools_profile_guard_started_at) * 1000, 3),
        'authorization' => round(($ll_tools_profile_authorization_finished_at - $ll_tools_profile_authorization_started_at) * 1000, 3),
        'summary_batch_authoritative' => round(($ll_tools_profile_batch_finished_at - $ll_tools_profile_batch_started_at) * 1000, 3),
        'json_serialization' => round(($ll_tools_profile_serialization_finished_at - $ll_tools_profile_serialization_started_at) * 1000, 3),
        'process_total' => round(($ll_tools_profile_finished_at - $ll_tools_profile_process_started_at) * 1000, 3),
    ],
    'queries' => [
        'wp_load' => [
            'count' => $ll_tools_profile_queries_after_wp_load,
            'saved_query_entries' => $ll_tools_profile_saved_after_wp_load,
            'database_elapsed_ms' => $ll_tools_profile_query_summary(
                $wpdb->queries,
                0,
                $ll_tools_profile_saved_after_wp_load
            )['database_elapsed_ms'],
        ],
        'manager_setup' => $ll_tools_profile_query_summary(
            $wpdb->queries,
            $ll_tools_profile_saved_after_wp_load,
            $ll_tools_profile_saved_after_manager_setup
        ),
        'admin_ajax_setup' => $ll_tools_profile_query_summary(
            $wpdb->queries,
            $ll_tools_profile_saved_after_manager_setup,
            $ll_tools_profile_saved_after_admin
        ),
        'fixture_guard' => $ll_tools_profile_query_summary(
            $wpdb->queries,
            $ll_tools_profile_saved_after_admin,
            $ll_tools_profile_saved_after_guard
        ),
        'authorization' => $ll_tools_profile_query_summary(
            $wpdb->queries,
            $ll_tools_profile_authorization_query_start,
            $ll_tools_profile_authorization_query_end
        ),
        'summary_batch' => $ll_tools_profile_query_summary(
            $wpdb->queries,
            $ll_tools_profile_batch_query_start,
            $ll_tools_profile_batch_query_end
        ),
        'summary_batch_sql_attribution' => $ll_tools_profile_attributed_queries,
        'summary_batch_slowest_queries' => $ll_tools_profile_slowest_summary_queries,
        'summary_batch_immediate_callers' => $ll_tools_profile_immediate_caller_aggregates,
        'process_total' => (int) $wpdb->num_queries,
    ],
    'result' => [
        'resolved_count' => count($ll_tools_profile_resolved_slugs),
        'pending_count' => count($ll_tools_profile_pending_slugs),
        'cards_count' => count((array) ($ll_tools_profile_result['cards'] ?? [])),
        'response_bytes' => strlen($ll_tools_profile_encoded_response),
        'status' => is_array($ll_tools_profile_result['status'] ?? null)
            ? $ll_tools_profile_result['status']
            : [],
    ],
    'notes' => [
        'The summary batch wall time is authoritative for the exact existing batch builder call.',
        'Catalog, signature/generation, scan, render, and cache buckets attribute SQL caller stacks only; they are not PHP wall-time phases.',
        'Manager authentication is established after wp-load without a browser cookie; authorization checks and admin_init are still included and reported separately.',
    ],
];

fwrite(STDOUT, (string) wp_json_encode($ll_tools_profile_output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
