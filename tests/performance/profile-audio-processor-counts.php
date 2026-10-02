<?php
/** Read-only Local profile: wp eval-file <this-file> --user=<staff-user>. */
if (!defined('WP_CLI') || !WP_CLI || !defined('LL_TOOLS_BASE_PATH')) {
    exit(1);
}

$host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
$plugin = realpath(LL_TOOLS_BASE_PATH);
if (!preg_match('/(?:\.local|\.test)$/i', $host)
    || $plugin !== realpath(dirname(__DIR__, 2))
    || !current_user_can('view_ll_tools')) {
    WP_CLI::error('This diagnostic requires the matching Local plugin and an authorized staff user.');
}

global $wpdb;
$queries = [];
$capture = static function (string $sql) use (&$queries): string {
    if (count($queries) < 2 && (strpos($sql, 'AS queue_count') !== false || strpos($sql, 'original_audio_file_path') !== false)) {
        $queries[] = $sql;
    }
    return $sql;
};
$samples = [];
for ($i = 0; $i < 3; $i++) {
    $query_start = $wpdb->num_queries;
    $started = microtime(true);
    if ($i === 0) { add_filter('query', $capture); }
    $counts = ll_audio_processor_get_queue_counts();
    if ($i === 0) { remove_filter('query', $capture); }
    $samples[] = [
        'milliseconds' => round((microtime(true) - $started) * 1000, 3),
        'queries' => $wpdb->num_queries - $query_start,
        'counts' => $counts,
    ];
}
$plans = [];
foreach ($queries as $sql) {
    $plans[] = $wpdb->get_results('EXPLAIN ' . $sql, ARRAY_A);
}
echo wp_json_encode([
    'at' => gmdate('c'),
    'recording_rows_by_status' => $wpdb->get_results(
        "SELECT post_status, COUNT(*) AS rows_count FROM {$wpdb->posts} WHERE post_type = 'word_audio' GROUP BY post_status",
        ARRAY_A
    ),
    'samples' => $samples,
    'plans' => $plans,
    'last_error' => $wpdb->last_error !== '' ? 'database_error' : '',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
