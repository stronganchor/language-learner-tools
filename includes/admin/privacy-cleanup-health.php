<?php
if (!defined('ABSPATH')) {
    exit;
}

/** Summarize one bounded queue page without exposing deleted-account identities. */
function ll_tools_privacy_deleted_user_lms_cleanup_health_summary(): array {
    $complete = true;
    $rows = ll_tools_privacy_deleted_user_lms_cleanup_rows(0, 51, $complete);
    $summary = [
        'read_error' => !$complete,
        'overflow' => count($rows) > 50,
        'pending_count' => 0,
        'due_count' => 0,
        'deferred_count' => 0,
        'blocked_count' => 0,
        'blocked_details' => [],
        'earliest_next_attempt_at' => 0,
    ];
    $now = time();
    foreach (array_slice($rows, 0, 50) as $row) {
        $summary['pending_count']++;
        $next_attempt = max(0, (int) ($row['next_attempt_at'] ?? 0));
        if ($next_attempt > $now) {
            $summary['deferred_count']++;
            if ($summary['earliest_next_attempt_at'] === 0 || $next_attempt < $summary['earliest_next_attempt_at']) {
                $summary['earliest_next_attempt_at'] = $next_attempt;
            }
        } else {
            $summary['due_count']++;
        }
        if ((string) ($row['blocked_reason'] ?? '') === '') {
            continue;
        }
        $summary['blocked_count']++;
        foreach (array_slice((array) ($row['blocked_details'] ?? []), 0, 16) as $detail) {
            if (count($summary['blocked_details']) >= 16) {
                break;
            }
            if (!is_string($detail) || $detail === '') {
                continue;
            }
            $detail = substr($detail, 0, 200);
            $summary['blocked_details'][$detail] = $detail;
        }
    }
    $summary['blocked_details'] = array_values(array_slice($summary['blocked_details'], 0, 16));
    return $summary;
}

/** WordPress Site Health reads this only for an administrator. */
function ll_tools_privacy_account_cleanup_site_health_test(): array {
    $result = [
        'label' => __('LL Tools account cleanup is up to date', 'll-tools-text-domain'),
        'status' => 'good',
        'badge' => ['label' => __('LL Tools', 'll-tools-text-domain'), 'color' => 'blue'],
        'description' => '<p>' . esc_html__('No pending deleted-account cleanup was found in the checked queue.', 'll-tools-text-domain') . '</p>',
        'actions' => '',
        'test' => 'll_tools_account_cleanup',
    ];
    if (!current_user_can('manage_options')) {
        $result['status'] = 'recommended';
        $result['label'] = __('LL Tools cleanup status requires administrator access', 'll-tools-text-domain');
        $result['description'] = '';
        return $result;
    }
    $summary = ll_tools_privacy_deleted_user_lms_cleanup_health_summary();
    if ($summary['read_error']) {
        $result['status'] = 'recommended';
        $result['label'] = __('LL Tools account cleanup status could not be checked', 'll-tools-text-domain');
        $result['description'] = '<p>' . esc_html__('The cleanup queue could not be read. Try Site Health again after resolving the database error.', 'll-tools-text-domain') . '</p>';
    } elseif ($summary['blocked_count'] > 0) {
        $result['status'] = 'critical';
        $result['label'] = __('LL Tools account cleanup needs database maintenance', 'll-tools-text-domain');
        $result['description'] = '<p>' . esc_html__('Some deleted-account cleanup jobs are blocked by database storage requirements. Their cleanup protections remain active, and retries use longer intervals. Review the affected tables with your site administrator before converting them to InnoDB.', 'll-tools-text-domain') . '</p>';
        if ($summary['blocked_details'] !== []) {
            $result['description'] .= '<ul><li>' . implode('</li><li>', array_map('esc_html', $summary['blocked_details'])) . '</li></ul>';
        }
    } elseif ($summary['pending_count'] > 0 || $summary['overflow']) {
        $result['status'] = 'recommended';
        $result['label'] = __('LL Tools account cleanup is pending', 'll-tools-text-domain');
        $result['description'] = '<p>' . esc_html__('Deleted-account cleanup continues in the background. This check reads at most 50 jobs; check again after the scheduled workers run.', 'll-tools-text-domain') . '</p>';
    }
    return $result;
}

function ll_tools_privacy_register_account_cleanup_site_health_test(array $tests): array {
    if (current_user_can('manage_options')) {
        $tests['direct']['ll_tools_account_cleanup'] = [
            'label' => __('LL Tools account cleanup', 'll-tools-text-domain'),
            'test' => 'll_tools_privacy_account_cleanup_site_health_test',
        ];
    }
    return $tests;
}
add_filter('site_status_tests', 'll_tools_privacy_register_account_cleanup_site_health_test');
