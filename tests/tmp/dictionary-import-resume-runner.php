<?php
declare(strict_types=1);

define('WP_DEBUG_DISPLAY', false);
define('WP_USE_THEMES', false);

$plugin_root = dirname(__DIR__, 2);
$wp_root = dirname($plugin_root, 3);

require $wp_root . '/wp-load.php';
require_once $plugin_root . '/includes/admin/dictionary-import-admin.php';

$job_id = isset($argv[1]) ? sanitize_text_field((string) $argv[1]) : '';
if ($job_id === '') {
    fwrite(STDERR, "missing-job-id\n");
    exit(1);
}

ll_tools_dictionary_import_set_active_job_id($job_id);
$job = ll_tools_dictionary_import_get_job($job_id);
if (!is_array($job)) {
    fwrite(STDERR, "missing-job\n");
    exit(1);
}

$status = sanitize_key((string) ($job['status'] ?? ''));
if ($status !== 'running') {
    echo wp_json_encode([
        'status' => $status,
        'current_index' => (int) ($job['current_index'] ?? 0),
        'processed_groups' => (int) ($job['processed_groups'] ?? 0),
        'total_groups' => (int) ($job['total_groups'] ?? 0),
    ]) . "\n";
    exit($status === 'completed' ? 0 : 1);
}

if (!ll_tools_dictionary_import_acquire_job_lock($job_id)) {
    echo wp_json_encode([
        'status' => 'locked',
        'timestamp' => time(),
    ]) . "\n";
    exit(10);
}

$exit_code = 1;

try {
    $processed_job = ll_tools_dictionary_import_process_job($job);
    if (is_wp_error($processed_job)) {
        $job['status'] = 'failed';
        $job['error_message'] = $processed_job->get_error_message();
        $job['summary'] = ll_tools_dictionary_import_merge_summary(
            is_array($job['summary'] ?? null) ? $job['summary'] : ll_tools_dictionary_import_default_summary(),
            [
                'errors' => [$processed_job->get_error_message()],
                'error_count' => 1,
            ]
        );
        ll_tools_dictionary_import_clear_active_job_id($job_id);
        $job = ll_tools_dictionary_import_save_job($job_id, $job);
        $job = ll_tools_dictionary_import_finalize_job_history($job);
        ll_tools_dictionary_import_save_job($job_id, $job);
        fwrite(STDERR, $processed_job->get_error_message() . "\n");
        $exit_code = 1;
    } else {
        $saved_job = ll_tools_dictionary_import_save_job($job_id, $processed_job);
        $saved_job = ll_tools_dictionary_import_finalize_job_history($saved_job);
        $saved_job = ll_tools_dictionary_import_save_job($job_id, $saved_job);

        echo wp_json_encode([
            'status' => (string) ($saved_job['status'] ?? ''),
            'current_index' => (int) ($saved_job['current_index'] ?? 0),
            'total_chunks' => (int) ($saved_job['total_chunks'] ?? 0),
            'processed_groups' => (int) ($saved_job['processed_groups'] ?? 0),
            'total_groups' => (int) ($saved_job['total_groups'] ?? 0),
            'timestamp' => time(),
        ]) . "\n";

        $exit_code = sanitize_key((string) ($saved_job['status'] ?? '')) === 'running' ? 10 : 0;
    }
} finally {
    ll_tools_dictionary_import_release_job_lock($job_id);
}

exit($exit_code);
