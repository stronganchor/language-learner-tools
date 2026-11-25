<?php
/**
 * Static safety contract for the local fresh-process recorder profiler.
 *
 * This test does not load WordPress, connect to the database, or run a probe.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__, 2);
$probe_path = $root . '/tests/performance/profile-recorder-summary-request.php';
$runner_path = $root . '/tests/performance/profile-recorder-summary-fresh-process.ps1';
$readme_path = $root . '/tests/performance/README.md';

$failures = [];
$read = static function (string $path) use (&$failures): string {
    $contents = is_readable($path) ? file_get_contents($path) : false;
    if (!is_string($contents)) {
        $failures[] = 'Unreadable required file: ' . $path;
        return '';
    }
    return $contents;
};
$expect_contains = static function (string $source, string $needle, string $label) use (&$failures): void {
    if (strpos($source, $needle) === false) {
        $failures[] = $label . ' is missing: ' . $needle;
    }
};
$expect_absent = static function (string $source, string $pattern, string $label) use (&$failures): void {
    if (preg_match($pattern, $source)) {
        $failures[] = $label . ' contains forbidden mutation pattern: ' . $pattern;
    }
};

$probe = $read($probe_path);
$runner = $read($runner_path);
$readme = $read($readme_path);

$savequeries_position = strpos($probe, "define('SAVEQUERIES', true)");
$wp_load_position = strpos($probe, 'require_once $ll_tools_profile_wp_load;');
if (
    $savequeries_position === false
    || $wp_load_position === false
    || $savequeries_position >= $wp_load_position
) {
    $failures[] = 'SAVEQUERIES must be enabled before the direct WordPress bootstrap.';
}

foreach ([
    "if (PHP_SAPI !== 'cli')",
    "'first-cold6' => [1, 6]",
    "'later-cold6' => [7, 12]",
    "'warm6' => [1, 6]",
    "'category_count' => 209",
    "'words_per_category' => 13",
    "'production_handler_called' => false",
    "'production_batch_builder_called_once' => true",
    "'manager_auth_mode' => 'post-bootstrap-wp-set-current-user'",
    "'summary_batch_authoritative'",
    "'summary_batch_sql_attribution'",
    "'summary_batch_immediate_callers'",
    'array_slice($ll_tools_profile_immediate_caller_aggregates, 0, 10)',
    'for ($caller_index = count($caller_parts) - 1; $caller_index >= 0; $caller_index--)',
    "\$immediate_caller .= ' <- ' . \$nearest_plugin_parent",
    "'.local'",
    "'.test'",
    "ll_tools_perf_manifest_stored_checksum_is_current",
    "ll_tools_wordset_page_is_recorder_assigned_to_wordset",
] as $needle) {
    $expect_contains($probe, $needle, 'Fresh-process request probe');
}

if (preg_match_all('/ll_tools_wordset_page_build_recorder_queue_summary_batch\s*\(/', $probe) !== 1) {
    $failures[] = 'The request probe must call the production batch builder exactly once.';
}
foreach ([
    '/\bwp_cache_flush\s*\(/i',
    '/\bll_tools_wordset_page_delete_durable_cached_payload\s*\(/i',
    '/\b(?:update|insert|delete)_option\s*\(/i',
    '/\b(?:wp_insert|wp_update|wp_delete)_/i',
    '/\bDELETE\s+FROM\b/i',
] as $pattern) {
    $expect_absent($probe, $pattern, 'Fresh-process request probe');
}

$reset_position = strpos($runner, "'reset'");
$probe_loop_position = strpos($runner, 'foreach ($phase in $phases)');
if ($reset_position === false || $probe_loop_position === false || $reset_position >= $probe_loop_position) {
    $failures[] = 'The exact-key guarded reset must run before all fresh-process probes.';
}
foreach ([
    "@('first-cold6', 'later-cold6', 'warm6')",
    'exact_keys_deleted -ne 211',
    'broad_flush_used',
    'rebuild_started',
    'LL_E2E_ADMIN_USER',
    'distinct_php_invocations = 3',
    'full_stream_extrapolation_included = $false',
] as $needle) {
    $expect_contains($runner, $needle, 'Fresh-process orchestrator');
}

$expect_contains(
    $readme,
    'profile-recorder-summary-fresh-process.ps1',
    'Performance README fresh-process usage'
);
$expect_contains(
    $readme,
    'verify-recorder-summary-fresh-process-profiler.php',
    'Performance README static contract usage'
);

if (!empty($failures)) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "Recorder summary fresh-process profiler contract passed.\n");
