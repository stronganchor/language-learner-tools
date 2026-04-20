<?php
if (!defined('WPINC')) { die; }

if (!function_exists('ll_tools_get_user_progress_report_page_slug')) {
    function ll_tools_get_user_progress_report_page_slug(): string {
        return 'll-tools-user-progress-report';
    }
}

if (!function_exists('ll_tools_get_user_progress_report_capability')) {
    function ll_tools_get_user_progress_report_capability(): string {
        return (string) apply_filters('ll_tools_user_progress_report_capability', 'manage_options');
    }
}

if (!function_exists('ll_tools_current_user_can_view_user_progress_report')) {
    function ll_tools_current_user_can_view_user_progress_report(): bool {
        return current_user_can(ll_tools_get_user_progress_report_capability());
    }
}

if (!function_exists('ll_tools_register_user_progress_report_page')) {
    function ll_tools_register_user_progress_report_page(): void {
        $parent_slug = function_exists('ll_tools_get_admin_menu_slug')
            ? ll_tools_get_admin_menu_slug()
            : 'll-tools-dashboard-home';

        add_submenu_page(
            $parent_slug,
            __('Learner Progress', 'll-tools-text-domain'),
            __('Learner Progress', 'll-tools-text-domain'),
            ll_tools_get_user_progress_report_capability(),
            ll_tools_get_user_progress_report_page_slug(),
            'll_tools_render_user_progress_report_page'
        );
    }
}
add_action('admin_menu', 'll_tools_register_user_progress_report_page', 15);

if (!function_exists('ll_tools_user_progress_report_wordsets')) {
    function ll_tools_user_progress_report_wordsets(): array {
        $terms = get_terms([
            'taxonomy' => 'wordset',
            'hide_empty' => false,
        ]);

        if (is_wp_error($terms) || !is_array($terms)) {
            return [];
        }

        return array_values(array_filter($terms, static function ($term): bool {
            return ($term instanceof WP_Term) && !is_wp_error($term);
        }));
    }
}

if (!function_exists('ll_tools_user_progress_report_tracked_user_ids')) {
    function ll_tools_user_progress_report_tracked_user_ids(int $wordset_id = 0): array {
        global $wpdb;

        if (!function_exists('ll_tools_user_progress_table_names')) {
            return [];
        }

        $tables = ll_tools_user_progress_table_names();
        $queries = [];

        if ($wordset_id > 0) {
            $queries[] = $wpdb->prepare(
                "SELECT DISTINCT user_id FROM {$tables['words']} WHERE wordset_id = %d",
                $wordset_id
            );
            $queries[] = $wpdb->prepare(
                "SELECT DISTINCT user_id FROM {$tables['events']} WHERE wordset_id = %d",
                $wordset_id
            );
        } else {
            $queries[] = "SELECT DISTINCT user_id FROM {$tables['words']}";
            $queries[] = "SELECT DISTINCT user_id FROM {$tables['events']}";
        }

        $ids = [];
        foreach ($queries as $sql) {
            foreach ((array) $wpdb->get_col($sql) as $user_id) {
                $user_id = (int) $user_id;
                if ($user_id > 0) {
                    $ids[$user_id] = true;
                }
            }
        }

        $resolved = array_map('intval', array_keys($ids));
        sort($resolved, SORT_NUMERIC);
        return $resolved;
    }
}

if (!function_exists('ll_tools_user_progress_report_build_admin_url')) {
    function ll_tools_user_progress_report_build_admin_url(array $args = []): string {
        $query_args = array_merge([
            'page' => ll_tools_get_user_progress_report_page_slug(),
        ], $args);

        foreach ($query_args as $key => $value) {
            if ($value === null) {
                unset($query_args[$key]);
                continue;
            }

            if (is_string($value) && $value === '') {
                unset($query_args[$key]);
                continue;
            }

            if (is_int($value)) {
                if ($key === 'paged' && $value <= 1) {
                    unset($query_args[$key]);
                    continue;
                }

                if ($key !== 'paged' && $value <= 0) {
                    unset($query_args[$key]);
                }
            }
        }

        return add_query_arg($query_args, admin_url('admin.php'));
    }
}

if (!function_exists('ll_tools_render_user_progress_report_notice')) {
    function ll_tools_render_user_progress_report_notice(): void {
        $notice_key = isset($_GET['ll_tools_user_progress_notice'])
            ? sanitize_key((string) wp_unslash($_GET['ll_tools_user_progress_notice']))
            : '';

        if ($notice_key === '') {
            return;
        }

        $notice_class = 'notice notice-warning';
        $message = '';

        switch ($notice_key) {
            case 'deleted':
                $notice_class = 'notice notice-success';
                $message = __('Learner account deleted.', 'll-tools-text-domain');
                break;
            case 'delete-nonce':
                $notice_class = 'notice notice-error';
                $message = __('The delete request could not be verified. Please try again.', 'll-tools-text-domain');
                break;
            case 'delete-invalid':
                $notice_class = 'notice notice-error';
                $message = __('That learner account could not be found.', 'll-tools-text-domain');
                break;
            case 'delete-permission':
                $notice_class = 'notice notice-error';
                $message = __('You do not have permission to delete learner accounts from this screen.', 'll-tools-text-domain');
                break;
            case 'delete-self':
                $message = __('You cannot delete the account you are currently using from this screen.', 'll-tools-text-domain');
                break;
            case 'delete-privileged':
                $message = __('Direct delete is limited to learner accounts. Use the main Users screen for staff or tool accounts.', 'll-tools-text-domain');
                break;
            case 'delete-content':
                $message = __('Direct delete is disabled for accounts that own WordPress content. Use the main Users screen so content can be reassigned first.', 'll-tools-text-domain');
                break;
            case 'delete-failed':
                $notice_class = 'notice notice-error';
                $message = __('WordPress could not delete that learner account.', 'll-tools-text-domain');
                break;
        }

        if ($message === '') {
            return;
        }

        printf(
            '<div class="%1$s"><p>%2$s</p></div>',
            esc_attr($notice_class),
            esc_html($message)
        );
    }
}

if (!function_exists('ll_tools_user_progress_report_bot_keywords')) {
    function ll_tools_user_progress_report_bot_keywords(): array {
        $keywords = apply_filters('ll_tools_user_progress_report_bot_keywords', [
            'seo',
            'casino',
            'loan',
            'forex',
            'crypto',
            'bet',
            'viagra',
            'cbd',
            'pharmacy',
            'escort',
            'porn',
            'traffic',
            'backlink',
            'marketing',
            'telegram',
            'whatsapp',
        ]);

        if (!is_array($keywords)) {
            return [];
        }

        return array_values(array_filter(array_map(static function ($keyword): string {
            return strtolower(sanitize_text_field((string) $keyword));
        }, $keywords), static function (string $keyword): bool {
            return $keyword !== '';
        }));
    }
}

if (!function_exists('ll_tools_user_progress_report_disposable_email_domains')) {
    function ll_tools_user_progress_report_disposable_email_domains(): array {
        $domains = apply_filters('ll_tools_user_progress_report_disposable_email_domains', [
            'mailinator.com',
            'guerrillamail.com',
            'guerrillamailblock.com',
            'sharklasers.com',
            'spam4.me',
            'tempmail.com',
            'temp-mail.org',
            '10minutemail.com',
            'yopmail.com',
            'dispostable.com',
            'fakeinbox.com',
        ]);

        if (!is_array($domains)) {
            return [];
        }

        return array_values(array_filter(array_map(static function ($domain): string {
            return strtolower(sanitize_text_field((string) $domain));
        }, $domains), static function (string $domain): bool {
            return $domain !== '';
        }));
    }
}

if (!function_exists('ll_tools_user_progress_report_login_looks_generated')) {
    function ll_tools_user_progress_report_login_looks_generated(string $login): bool {
        $normalized = strtolower((string) preg_replace('/[^a-z0-9]+/', '', $login));
        $length = strlen($normalized);
        if ($length < 10) {
            return false;
        }

        if (!preg_match('/[a-z]/', $normalized) || !preg_match('/\d/', $normalized)) {
            return false;
        }

        $digit_count = preg_match_all('/\d/', $normalized, $digit_matches);
        $vowel_count = preg_match_all('/[aeiou]/', $normalized, $vowel_matches);
        $digit_count = is_int($digit_count) ? $digit_count : 0;
        $vowel_count = is_int($vowel_count) ? $vowel_count : 0;

        if ($digit_count >= 4 && $vowel_count <= 2) {
            return true;
        }

        if (preg_match('/[bcdfghjklmnpqrstvwxyz]{6,}/', $normalized)) {
            return true;
        }

        return false;
    }
}

if (!function_exists('ll_tools_user_progress_report_assess_bot_risk')) {
    function ll_tools_user_progress_report_assess_bot_risk(WP_User $user, array $stats): array {
        $user_login = strtolower((string) $user->user_login);
        $display_name = strtolower((string) $user->display_name);
        $user_email = strtolower((string) $user->user_email);
        $email_domain = '';
        if (strpos($user_email, '@') !== false) {
            $email_domain = (string) substr(strrchr($user_email, '@'), 1);
        }

        $subject = trim($user_login . ' ' . $display_name . ' ' . $user_email);
        $score = 0;
        $reasons = [];

        $matched_keywords = [];
        foreach (ll_tools_user_progress_report_bot_keywords() as $keyword) {
            if ($keyword !== '' && strpos($subject, $keyword) !== false) {
                $matched_keywords[$keyword] = true;
            }
        }
        if (!empty($matched_keywords)) {
            $score += 3;
            $reasons[] = sprintf(
                /* translators: %s: comma separated keyword list */
                __('Spam-style keyword match: %s', 'll-tools-text-domain'),
                implode(', ', array_slice(array_keys($matched_keywords), 0, 3))
            );
        }

        if ($email_domain !== '' && in_array($email_domain, ll_tools_user_progress_report_disposable_email_domains(), true)) {
            $score += 3;
            $reasons[] = sprintf(
                /* translators: %s: email domain */
                __('Disposable email domain: %s', 'll-tools-text-domain'),
                $email_domain
            );
        }

        if (ll_tools_user_progress_report_login_looks_generated((string) $user->user_login)) {
            $score += 2;
            $reasons[] = __('Username looks auto-generated.', 'll-tools-text-domain');
        }

        $tracked_words = max(0, (int) ($stats['tracked_words'] ?? 0));
        $rounds_30d = max(0, (int) ($stats['rounds_30d'] ?? 0));
        $outcomes_30d = max(0, (int) ($stats['outcomes_30d'] ?? 0));
        $sessions_30d = max(0, (int) ($stats['sessions_30d'] ?? 0));
        $stt_calls_7d = max(0, (int) ($stats['stt_calls_7d'] ?? 0));
        $stt_calls_30d = max(0, (int) ($stats['stt_calls_30d'] ?? 0));

        if ($rounds_30d >= 2500 || $outcomes_30d >= 2500) {
            $score += 2;
            $reasons[] = __('Very high 30-day quiz activity volume.', 'll-tools-text-domain');
        } elseif ($rounds_30d >= 800 || $outcomes_30d >= 800) {
            $score += 1;
            $reasons[] = sprintf(
                /* translators: 1: rounds count, 2: outcomes count */
                __('Unusually heavy recent quiz activity (%1$d rounds / %2$d outcomes).', 'll-tools-text-domain'),
                $rounds_30d,
                $outcomes_30d
            );
        }

        if ($stt_calls_7d >= 80) {
            $score += 3;
            $reasons[] = sprintf(
                /* translators: %d: call count */
                __('High speech-to-text volume in 7 days: %d calls.', 'll-tools-text-domain'),
                $stt_calls_7d
            );
        } elseif ($stt_calls_30d >= 150) {
            $score += 2;
            $reasons[] = sprintf(
                /* translators: %d: call count */
                __('High speech-to-text volume in 30 days: %d calls.', 'll-tools-text-domain'),
                $stt_calls_30d
            );
        }

        if ($stt_calls_30d >= max(30, $outcomes_30d * 2)) {
            $score += 2;
            $reasons[] = __('Speech-to-text usage is far higher than quiz outcomes.', 'll-tools-text-domain');
        }

        if ($sessions_30d === 0 && ($rounds_30d >= 25 || $outcomes_30d >= 25 || $stt_calls_30d >= 10)) {
            $score += 2;
            $reasons[] = __('Recent activity was recorded without completed study sessions.', 'll-tools-text-domain');
        }

        if ($tracked_words === 0 && ($rounds_30d >= 25 || $outcomes_30d >= 25 || $stt_calls_30d >= 10)) {
            $score += 2;
            $reasons[] = __('Recent activity exists without tracked word progress rows.', 'll-tools-text-domain');
        }

        $registered_at = strtotime((string) $user->user_registered . ' UTC');
        if ($registered_at !== false) {
            $age_seconds = max(0, time() - $registered_at);
            if ($age_seconds <= DAY_IN_SECONDS && ($rounds_30d >= 200 || $outcomes_30d >= 200 || $stt_calls_30d >= 40)) {
                $score += 2;
                $reasons[] = __('New account with unusually heavy activity.', 'll-tools-text-domain');
            }
        }

        $level = 'none';
        $label = __('No flag', 'll-tools-text-domain');

        if ($score >= 7) {
            $level = 'high';
            $label = __('High Risk', 'll-tools-text-domain');
        } elseif ($score >= 4) {
            $level = 'review';
            $label = __('Review', 'll-tools-text-domain');
        } elseif ($score > 0) {
            $level = 'watch';
            $label = __('Watch', 'll-tools-text-domain');
        }

        $result = [
            'score' => $score,
            'level' => $level,
            'label' => $label,
            'flagged' => ($score > 0),
            'reasons' => array_slice(array_values(array_unique($reasons)), 0, 4),
        ];

        return apply_filters('ll_tools_user_progress_report_bot_risk', $result, $user, $stats);
    }
}

if (!function_exists('ll_tools_user_progress_report_user_has_authored_content')) {
    function ll_tools_user_progress_report_user_has_authored_content(int $user_id): bool {
        static $cache = [];

        if ($user_id <= 0) {
            return false;
        }

        if (array_key_exists($user_id, $cache)) {
            return (bool) $cache[$user_id];
        }

        $posts = get_posts([
            'author' => $user_id,
            'post_type' => 'any',
            'post_status' => 'any',
            'fields' => 'ids',
            'posts_per_page' => 1,
            'no_found_rows' => true,
            'suppress_filters' => true,
        ]);

        $cache[$user_id] = !empty($posts);
        return (bool) $cache[$user_id];
    }
}

if (!function_exists('ll_tools_user_progress_report_direct_delete_status')) {
    function ll_tools_user_progress_report_direct_delete_status(WP_User $user): array {
        $user_id = (int) $user->ID;

        if ($user_id <= 0) {
            return [
                'allowed' => false,
                'reason_key' => 'invalid',
                'reason_message' => __('That learner account could not be found.', 'll-tools-text-domain'),
            ];
        }

        if ($user_id === get_current_user_id()) {
            return [
                'allowed' => false,
                'reason_key' => 'self',
                'reason_message' => __('You cannot delete the account you are currently using from this screen.', 'll-tools-text-domain'),
            ];
        }

        if (!current_user_can('delete_user', $user_id)) {
            return [
                'allowed' => false,
                'reason_key' => 'permission',
                'reason_message' => __('You do not have permission to delete this learner account.', 'll-tools-text-domain'),
            ];
        }

        if (user_can($user, 'manage_options') || user_can($user, 'view_ll_tools') || user_can($user, 'edit_posts')) {
            return [
                'allowed' => false,
                'reason_key' => 'privileged',
                'reason_message' => __('Use the main Users screen for staff or tool accounts.', 'll-tools-text-domain'),
            ];
        }

        if (ll_tools_user_progress_report_user_has_authored_content($user_id)) {
            return [
                'allowed' => false,
                'reason_key' => 'content',
                'reason_message' => __('Use the main Users screen for accounts that own WordPress content.', 'll-tools-text-domain'),
            ];
        }

        return [
            'allowed' => true,
            'reason_key' => '',
            'reason_message' => '',
        ];
    }
}

if (!function_exists('ll_tools_user_progress_report_delete_request_result')) {
    function ll_tools_user_progress_report_delete_request_result(array $request): array {
        $target_user_id = isset($request['ll_tools_user_id'])
            ? max(0, (int) wp_unslash((string) $request['ll_tools_user_id']))
            : 0;
        $return_wordset_id = isset($request['ll_tools_return_wordset_id'])
            ? max(0, (int) wp_unslash((string) $request['ll_tools_return_wordset_id']))
            : 0;
        $return_search = isset($request['ll_tools_return_search'])
            ? sanitize_text_field((string) wp_unslash($request['ll_tools_return_search']))
            : '';
        $return_paged = isset($request['ll_tools_return_paged'])
            ? max(1, (int) wp_unslash((string) $request['ll_tools_return_paged']))
            : 1;
        $return_user_id = isset($request['ll_tools_return_user_id'])
            ? max(0, (int) wp_unslash((string) $request['ll_tools_return_user_id']))
            : 0;

        $redirect_args = [
            'wordset_id' => $return_wordset_id,
            's' => $return_search,
            'paged' => $return_paged,
        ];
        if ($return_user_id > 0 && $return_user_id !== $target_user_id) {
            $redirect_args['user_id'] = $return_user_id;
        }

        if (!ll_tools_current_user_can_view_user_progress_report()) {
            return [
                'notice' => 'delete-permission',
                'redirect_args' => $redirect_args,
            ];
        }

        if ($target_user_id <= 0) {
            return [
                'notice' => 'delete-invalid',
                'redirect_args' => $redirect_args,
            ];
        }

        $nonce = isset($request['ll_tools_delete_user_nonce'])
            ? sanitize_text_field((string) wp_unslash($request['ll_tools_delete_user_nonce']))
            : '';
        if ($nonce === '' || !wp_verify_nonce($nonce, 'll_tools_delete_progress_user_' . $target_user_id)) {
            return [
                'notice' => 'delete-nonce',
                'redirect_args' => $redirect_args,
            ];
        }

        $user = get_userdata($target_user_id);
        if (!($user instanceof WP_User)) {
            return [
                'notice' => 'delete-invalid',
                'redirect_args' => $redirect_args,
            ];
        }

        $delete_status = ll_tools_user_progress_report_direct_delete_status($user);
        if (empty($delete_status['allowed'])) {
            $reason_key = sanitize_key((string) ($delete_status['reason_key'] ?? 'permission'));
            return [
                'notice' => 'delete-' . $reason_key,
                'redirect_args' => $redirect_args,
            ];
        }

        if (!function_exists('wp_delete_user')) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
        }

        $deleted = wp_delete_user($target_user_id);
        return [
            'notice' => $deleted ? 'deleted' : 'delete-failed',
            'redirect_args' => $redirect_args,
        ];
    }
}

if (!function_exists('ll_tools_handle_user_progress_report_delete_user')) {
    function ll_tools_handle_user_progress_report_delete_user(): void {
        $result = ll_tools_user_progress_report_delete_request_result($_POST);
        $redirect_url = ll_tools_user_progress_report_build_admin_url(array_merge(
            is_array($result['redirect_args'] ?? null) ? $result['redirect_args'] : [],
            [
                'll_tools_user_progress_notice' => sanitize_key((string) ($result['notice'] ?? 'delete-failed')),
            ]
        ));

        wp_safe_redirect($redirect_url);
        exit;
    }
}
add_action('admin_post_ll_tools_user_progress_delete_user', 'll_tools_handle_user_progress_report_delete_user');

if (!function_exists('ll_tools_render_user_progress_report_page')) {
    function ll_tools_render_user_progress_report_page(): void {
        if (!ll_tools_current_user_can_view_user_progress_report()) {
            wp_die(__('You do not have permission to access this page.', 'll-tools-text-domain'));
        }

        $wordset_id = isset($_GET['wordset_id']) ? max(0, (int) wp_unslash((string) $_GET['wordset_id'])) : 0;
        $search = isset($_GET['s']) ? sanitize_text_field((string) wp_unslash($_GET['s'])) : '';
        $selected_user_id = isset($_GET['user_id']) ? max(0, (int) wp_unslash((string) $_GET['user_id'])) : 0;
        $paged = isset($_GET['paged']) ? max(1, (int) wp_unslash((string) $_GET['paged'])) : 1;
        $per_page = 20;

        $tracked_user_ids = ll_tools_user_progress_report_tracked_user_ids($wordset_id);
        $user_query = null;
        $users = [];
        $stats = [];

        if (!empty($tracked_user_ids)) {
            $query_args = [
                'include' => $tracked_user_ids,
                'number' => $per_page,
                'paged' => $paged,
                'orderby' => 'display_name',
                'order' => 'ASC',
                'count_total' => true,
            ];

            if ($search !== '') {
                $query_args['search'] = '*' . $search . '*';
                $query_args['search_columns'] = ['user_login', 'user_email', 'display_name'];
            }

            $user_query = new WP_User_Query($query_args);
            $users = (array) $user_query->get_results();

            $page_user_ids = array_values(array_filter(array_map(static function ($user): int {
                return ($user instanceof WP_User) ? (int) $user->ID : 0;
            }, $users), static function (int $user_id): bool {
                return $user_id > 0;
            }));

            $stats = ll_tools_user_progress_report_stats_for_users($page_user_ids, $wordset_id);
        }

        $detail_user = ($selected_user_id > 0) ? get_userdata($selected_user_id) : null;
        $detail_wordset_id = $wordset_id;
        if ($detail_user instanceof WP_User && $detail_wordset_id <= 0) {
            $detail_wordset_id = ll_tools_user_progress_report_user_wordset_id((int) $detail_user->ID);
        }

        $detail_analytics = [];
        if ($detail_user instanceof WP_User && $detail_wordset_id > 0 && function_exists('ll_tools_build_user_study_analytics_payload')) {
            $detail_analytics = ll_tools_build_user_study_analytics_payload((int) $detail_user->ID, $detail_wordset_id, [], 30, true);
        }
        $detail_stats = [];
        if ($detail_user instanceof WP_User) {
            $detail_stats = ll_tools_user_progress_report_stats_for_users(
                [(int) $detail_user->ID],
                $detail_wordset_id > 0 ? $detail_wordset_id : $wordset_id
            );
            $detail_stats = is_array($detail_stats) ? ($detail_stats[(int) $detail_user->ID] ?? []) : [];
        }
        $detail_bot_risk = ($detail_user instanceof WP_User)
            ? ll_tools_user_progress_report_assess_bot_risk($detail_user, $detail_stats)
            : [];
        $detail_summary = (is_array($detail_analytics) && isset($detail_analytics['summary']) && is_array($detail_analytics['summary']))
            ? $detail_analytics['summary']
            : [];
        $detail_daily = (is_array($detail_analytics) && isset($detail_analytics['daily_activity']) && is_array($detail_analytics['daily_activity']))
            ? $detail_analytics['daily_activity']
            : [];
        $delete_confirm_text = __('Delete this learner account permanently? This also removes its LL Tools progress data.', 'll-tools-text-domain');
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Learner Progress', 'll-tools-text-domain'); ?></h1>
            <p><?php esc_html_e('This report is limited to administrators because it contains identifiable learner usage and progress data.', 'll-tools-text-domain'); ?></p>
            <?php ll_tools_render_user_progress_report_notice(); ?>

            <style>
                .ll-tools-user-progress-risk-badge {
                    display: inline-flex;
                    align-items: center;
                    gap: 4px;
                    padding: 2px 8px;
                    border: 1px solid transparent;
                    border-radius: 999px;
                    font-size: 12px;
                    font-weight: 600;
                    line-height: 1.5;
                }
                .ll-tools-user-progress-risk-badge .dashicons {
                    width: 14px;
                    height: 14px;
                    font-size: 14px;
                }
                .ll-tools-user-progress-risk-none {
                    color: #1d2327;
                    background: #f6f7f7;
                    border-color: #dcdcde;
                }
                .ll-tools-user-progress-risk-watch {
                    color: #674d00;
                    background: #fcf9e8;
                    border-color: #dba617;
                }
                .ll-tools-user-progress-risk-review {
                    color: #8a4b00;
                    background: #fff2d6;
                    border-color: #f0b849;
                }
                .ll-tools-user-progress-risk-high {
                    color: #8a1f11;
                    background: #fcf0f1;
                    border-color: #d63638;
                }
                .ll-tools-user-progress-risk-col .description {
                    display: block;
                    margin-top: 6px;
                }
                .ll-tools-user-progress-actions {
                    display: flex;
                    flex-direction: column;
                    gap: 6px;
                    align-items: flex-start;
                }
                .ll-tools-user-progress-delete-form {
                    margin: 0;
                }
                .ll-tools-user-progress-delete-button {
                    border-color: #d63638 !important;
                    color: #b32d2e !important;
                }
                .ll-tools-user-progress-delete-button:hover,
                .ll-tools-user-progress-delete-button:focus {
                    border-color: #b32d2e !important;
                    color: #8a2424 !important;
                }
                .ll-tools-user-progress-risk-panel {
                    max-width: 900px;
                    margin: 0 0 18px;
                    padding: 12px 14px;
                    border: 1px solid #dcdcde;
                    background: #fff;
                }
                .ll-tools-user-progress-risk-panel .description {
                    margin: 8px 0 0;
                }
            </style>

            <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
                <input type="hidden" name="page" value="<?php echo esc_attr(ll_tools_get_user_progress_report_page_slug()); ?>" />
                <p class="search-box" style="max-width: 880px;">
                    <label class="screen-reader-text" for="ll-tools-user-progress-search"><?php esc_html_e('Search learners', 'll-tools-text-domain'); ?></label>
                    <input
                        type="search"
                        id="ll-tools-user-progress-search"
                        name="s"
                        value="<?php echo esc_attr($search); ?>"
                        placeholder="<?php esc_attr_e('Search by username, display name, or email', 'll-tools-text-domain'); ?>" />
                    <select name="wordset_id" id="ll-tools-user-progress-wordset">
                        <option value="0"><?php esc_html_e('All word sets', 'll-tools-text-domain'); ?></option>
                        <?php foreach (ll_tools_user_progress_report_wordsets() as $wordset_term) : ?>
                            <option value="<?php echo esc_attr((string) $wordset_term->term_id); ?>" <?php selected($wordset_id, (int) $wordset_term->term_id); ?>>
                                <?php echo esc_html($wordset_term->name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php submit_button(__('Filter', 'll-tools-text-domain'), 'secondary', '', false); ?>
                </p>
            </form>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Learner', 'll-tools-text-domain'); ?></th>
                        <th><?php esc_html_e('Email', 'll-tools-text-domain'); ?></th>
                        <th><?php esc_html_e('Roles', 'll-tools-text-domain'); ?></th>
                        <th><?php esc_html_e('Current Word Set', 'll-tools-text-domain'); ?></th>
                        <th><?php esc_html_e('Bot Risk', 'll-tools-text-domain'); ?></th>
                        <th><?php esc_html_e('30d Rounds', 'll-tools-text-domain'); ?></th>
                        <th><?php esc_html_e('30d Outcomes', 'll-tools-text-domain'); ?></th>
                        <th><?php esc_html_e('STT Calls', 'll-tools-text-domain'); ?></th>
                        <th><?php esc_html_e('30d STT', 'll-tools-text-domain'); ?></th>
                        <th><?php esc_html_e('Studied', 'll-tools-text-domain'); ?></th>
                        <th><?php esc_html_e('Mastered', 'll-tools-text-domain'); ?></th>
                        <th><?php esc_html_e('Hard', 'll-tools-text-domain'); ?></th>
                        <th><?php esc_html_e('Last Activity (UTC)', 'll-tools-text-domain'); ?></th>
                        <th><?php esc_html_e('Actions', 'll-tools-text-domain'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users)) : ?>
                        <tr>
                            <td colspan="14"><?php esc_html_e('No learner progress data matched the current filters.', 'll-tools-text-domain'); ?></td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($users as $user) : ?>
                            <?php
                            if (!($user instanceof WP_User)) {
                                continue;
                            }

                            $row_stats = $stats[(int) $user->ID] ?? [
                                'studied_words' => 0,
                                'mastered_words' => 0,
                                'hard_words' => 0,
                                'rounds_30d' => 0,
                                'outcomes_30d' => 0,
                                'last_progress_at' => '',
                                'last_event_at' => '',
                            ];
                            $current_wordset_id = ll_tools_user_progress_report_user_wordset_id((int) $user->ID);
                            $bot_risk = ll_tools_user_progress_report_assess_bot_risk($user, $row_stats);
                            $delete_status = ll_tools_user_progress_report_direct_delete_status($user);
                            $detail_url = ll_tools_user_progress_report_build_admin_url([
                                'user_id' => (int) $user->ID,
                                'wordset_id' => $wordset_id,
                                's' => $search,
                                'paged' => $paged,
                            ]);
                            ?>
                            <tr>
                                <td><?php echo esc_html($user->display_name ?: $user->user_login); ?></td>
                                <td><a href="mailto:<?php echo esc_attr($user->user_email); ?>"><?php echo esc_html($user->user_email); ?></a></td>
                                <td><?php echo esc_html(implode(', ', array_map('sanitize_text_field', (array) $user->roles))); ?></td>
                                <td><?php echo esc_html(ll_tools_user_progress_report_wordset_name($current_wordset_id) ?: ''); ?></td>
                                <td class="ll-tools-user-progress-risk-col">
                                    <span class="ll-tools-user-progress-risk-badge ll-tools-user-progress-risk-<?php echo esc_attr((string) ($bot_risk['level'] ?? 'none')); ?>">
                                        <?php if (!empty($bot_risk['flagged'])) : ?>
                                            <span class="dashicons dashicons-warning" aria-hidden="true"></span>
                                        <?php endif; ?>
                                        <?php echo esc_html((string) ($bot_risk['label'] ?? __('No flag', 'll-tools-text-domain'))); ?>
                                    </span>
                                    <?php if (!empty($bot_risk['reasons'])) : ?>
                                        <span class="description"><?php echo esc_html(implode('; ', array_slice((array) $bot_risk['reasons'], 0, 2))); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html((string) max(0, (int) ($row_stats['rounds_30d'] ?? 0))); ?></td>
                                <td><?php echo esc_html((string) max(0, (int) ($row_stats['outcomes_30d'] ?? 0))); ?></td>
                                <td><?php echo esc_html((string) max(0, (int) ($row_stats['stt_calls_total'] ?? 0))); ?></td>
                                <td><?php echo esc_html((string) max(0, (int) ($row_stats['stt_calls_30d'] ?? 0))); ?></td>
                                <td><?php echo esc_html((string) max(0, (int) ($row_stats['studied_words'] ?? 0))); ?></td>
                                <td><?php echo esc_html((string) max(0, (int) ($row_stats['mastered_words'] ?? 0))); ?></td>
                                <td><?php echo esc_html((string) max(0, (int) ($row_stats['hard_words'] ?? 0))); ?></td>
                                <td><?php echo esc_html(ll_tools_user_progress_report_last_activity($row_stats)); ?></td>
                                <td>
                                    <div class="ll-tools-user-progress-actions">
                                        <a class="button button-small" href="<?php echo esc_url($detail_url); ?>"><?php esc_html_e('View', 'll-tools-text-domain'); ?></a>
                                        <?php if (!empty($delete_status['allowed'])) : ?>
                                            <form
                                                method="post"
                                                action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                                                class="ll-tools-user-progress-delete-form"
                                                data-confirm="<?php echo esc_attr($delete_confirm_text); ?>"
                                                onsubmit="return confirm(this.getAttribute('data-confirm'));"
                                            >
                                                <input type="hidden" name="action" value="ll_tools_user_progress_delete_user" />
                                                <input type="hidden" name="ll_tools_user_id" value="<?php echo esc_attr((string) $user->ID); ?>" />
                                                <input type="hidden" name="ll_tools_return_wordset_id" value="<?php echo esc_attr((string) $wordset_id); ?>" />
                                                <input type="hidden" name="ll_tools_return_search" value="<?php echo esc_attr($search); ?>" />
                                                <input type="hidden" name="ll_tools_return_paged" value="<?php echo esc_attr((string) $paged); ?>" />
                                                <input type="hidden" name="ll_tools_return_user_id" value="<?php echo esc_attr((string) $selected_user_id); ?>" />
                                                <input type="hidden" name="ll_tools_delete_user_nonce" value="<?php echo esc_attr(wp_create_nonce('ll_tools_delete_progress_user_' . (int) $user->ID)); ?>" />
                                                <button type="submit" class="button button-small ll-tools-user-progress-delete-button"><?php esc_html_e('Delete User', 'll-tools-text-domain'); ?></button>
                                            </form>
                                        <?php elseif (!empty($delete_status['reason_message'])) : ?>
                                            <span class="description"><?php echo esc_html((string) $delete_status['reason_message']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php
            $total_users = ($user_query instanceof WP_User_Query) ? (int) $user_query->get_total() : 0;
            $total_pages = $per_page > 0 ? (int) ceil($total_users / $per_page) : 1;
            if ($total_pages > 1) :
                echo '<div class="tablenav"><div class="tablenav-pages">';
                echo wp_kses_post(paginate_links([
                    'base' => add_query_arg([
                        'page' => ll_tools_get_user_progress_report_page_slug(),
                        'wordset_id' => $wordset_id,
                        's' => $search,
                        'user_id' => $selected_user_id,
                        'paged' => '%#%',
                    ], admin_url('admin.php')),
                    'format' => '',
                    'current' => $paged,
                    'total' => $total_pages,
                    'type' => 'plain',
                ]));
                echo '</div></div>';
            endif;
            ?>

            <?php if ($detail_user instanceof WP_User) : ?>
                <hr />
                <h2>
                    <?php
                    printf(
                        /* translators: %s: learner display name */
                        esc_html__('Progress details for %s', 'll-tools-text-domain'),
                        esc_html($detail_user->display_name ?: $detail_user->user_login)
                    );
                    ?>
                </h2>
                <p>
                    <?php
                    if ($detail_wordset_id > 0) {
                        printf(
                            /* translators: %s: word set name */
                            esc_html__('Showing analytics for word set: %s', 'll-tools-text-domain'),
                            esc_html(ll_tools_user_progress_report_wordset_name($detail_wordset_id))
                        );
                    } else {
                        esc_html_e('Choose or assign a word set to view detailed progress analytics for this learner.', 'll-tools-text-domain');
                    }
                    ?>
                </p>

                <div class="ll-tools-user-progress-risk-panel">
                    <strong><?php esc_html_e('Bot Risk', 'll-tools-text-domain'); ?></strong>
                    <span class="ll-tools-user-progress-risk-badge ll-tools-user-progress-risk-<?php echo esc_attr((string) ($detail_bot_risk['level'] ?? 'none')); ?>">
                        <?php if (!empty($detail_bot_risk['flagged'])) : ?>
                            <span class="dashicons dashicons-warning" aria-hidden="true"></span>
                        <?php endif; ?>
                        <?php echo esc_html((string) ($detail_bot_risk['label'] ?? __('No flag', 'll-tools-text-domain'))); ?>
                    </span>
                    <?php if (!empty($detail_bot_risk['reasons'])) : ?>
                        <p class="description"><?php echo esc_html(implode('; ', (array) $detail_bot_risk['reasons'])); ?></p>
                    <?php else : ?>
                        <p class="description"><?php esc_html_e('No current bot-risk signals were detected from this account profile and recent study activity.', 'll-tools-text-domain'); ?></p>
                    <?php endif; ?>
                </div>

                <?php if (!empty($detail_analytics) || !empty($detail_stats)) : ?>
                    <table class="widefat striped" style="max-width: 900px; margin-bottom: 24px;">
                        <tbody>
                            <tr>
                                <th><?php esc_html_e('Total words in scope', 'll-tools-text-domain'); ?></th>
                                <td><?php echo esc_html((string) max(0, (int) ($detail_summary['total_words'] ?? 0))); ?></td>
                                <th><?php esc_html_e('Studied', 'll-tools-text-domain'); ?></th>
                                <td><?php echo esc_html((string) max(0, (int) ($detail_summary['studied_words'] ?? 0))); ?></td>
                                <th><?php esc_html_e('Mastered', 'll-tools-text-domain'); ?></th>
                                <td><?php echo esc_html((string) max(0, (int) ($detail_summary['mastered_words'] ?? 0))); ?></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('New', 'll-tools-text-domain'); ?></th>
                                <td><?php echo esc_html((string) max(0, (int) ($detail_summary['new_words'] ?? 0))); ?></td>
                                <th><?php esc_html_e('Hard', 'll-tools-text-domain'); ?></th>
                                <td><?php echo esc_html((string) max(0, (int) ($detail_summary['hard_words'] ?? 0))); ?></td>
                                <th><?php esc_html_e('30d rounds window', 'll-tools-text-domain'); ?></th>
                                <td><?php echo esc_html((string) max(0, (int) ($detail_daily['max_rounds'] ?? 0))); ?></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('STT calls', 'll-tools-text-domain'); ?></th>
                                <td><?php echo esc_html((string) max(0, (int) ($detail_stats['stt_calls_total'] ?? 0))); ?></td>
                                <th><?php esc_html_e('7d STT calls', 'll-tools-text-domain'); ?></th>
                                <td><?php echo esc_html((string) max(0, (int) ($detail_stats['stt_calls_7d'] ?? 0))); ?></td>
                                <th><?php esc_html_e('Last STT call (UTC)', 'll-tools-text-domain'); ?></th>
                                <td><?php echo esc_html((string) ($detail_stats['last_stt_api_call_at'] ?? '')); ?></td>
                            </tr>
                        </tbody>
                    </table>

                    <?php if (!empty($detail_analytics)) : ?>
                    <h3><?php esc_html_e('Categories', 'll-tools-text-domain'); ?></h3>
                    <table class="widefat striped" style="margin-bottom: 24px;">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Category', 'll-tools-text-domain'); ?></th>
                                <th><?php esc_html_e('Words', 'll-tools-text-domain'); ?></th>
                                <th><?php esc_html_e('Studied', 'll-tools-text-domain'); ?></th>
                                <th><?php esc_html_e('Mastered', 'll-tools-text-domain'); ?></th>
                                <th><?php esc_html_e('Last Seen (UTC)', 'll-tools-text-domain'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $category_rows = array_slice((array) ($detail_analytics['categories'] ?? []), 0, 12);
                            if (empty($category_rows)) :
                                ?>
                                <tr>
                                    <td colspan="5"><?php esc_html_e('No category analytics are available for this learner in the selected scope.', 'll-tools-text-domain'); ?></td>
                                </tr>
                            <?php else : ?>
                                <?php foreach ($category_rows as $category_row) : ?>
                                    <tr>
                                        <td><?php echo esc_html((string) ($category_row['label'] ?? '')); ?></td>
                                        <td><?php echo esc_html((string) max(0, (int) ($category_row['word_count'] ?? 0))); ?></td>
                                        <td><?php echo esc_html((string) max(0, (int) ($category_row['studied_words'] ?? 0))); ?></td>
                                        <td><?php echo esc_html((string) max(0, (int) ($category_row['mastered_words'] ?? 0))); ?></td>
                                        <td><?php echo esc_html((string) ($category_row['last_seen_at'] ?? '')); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>

                    <?php if (!empty($detail_analytics)) : ?>
                    <h3><?php esc_html_e('Words Needing Attention', 'll-tools-text-domain'); ?></h3>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Word', 'll-tools-text-domain'); ?></th>
                                <th><?php esc_html_e('Translation', 'll-tools-text-domain'); ?></th>
                                <th><?php esc_html_e('Status', 'll-tools-text-domain'); ?></th>
                                <th><?php esc_html_e('Difficulty', 'll-tools-text-domain'); ?></th>
                                <th><?php esc_html_e('Incorrect', 'll-tools-text-domain'); ?></th>
                                <th><?php esc_html_e('Last Seen (UTC)', 'll-tools-text-domain'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $word_rows = array_values(array_filter((array) ($detail_analytics['words'] ?? []), static function ($word_row): bool {
                                return is_array($word_row) && (
                                    max(0, (int) ($word_row['difficulty_score'] ?? 0)) > 0
                                    || (string) ($word_row['status'] ?? '') !== 'mastered'
                                );
                            }));
                            $word_rows = array_slice($word_rows, 0, 15);
                            if (empty($word_rows)) :
                                ?>
                                <tr>
                                    <td colspan="6"><?php esc_html_e('No non-mastered or difficult words were found in this scope.', 'll-tools-text-domain'); ?></td>
                                </tr>
                            <?php else : ?>
                                <?php foreach ($word_rows as $word_row) : ?>
                                    <tr>
                                        <td><?php echo esc_html((string) ($word_row['title'] ?? '')); ?></td>
                                        <td><?php echo esc_html((string) ($word_row['translation'] ?? '')); ?></td>
                                        <td><?php echo esc_html((string) ($word_row['status'] ?? '')); ?></td>
                                        <td><?php echo esc_html((string) max(0, (int) ($word_row['difficulty_score'] ?? 0))); ?></td>
                                        <td><?php echo esc_html((string) max(0, (int) ($word_row['incorrect'] ?? 0))); ?></td>
                                        <td><?php echo esc_html((string) ($word_row['last_seen_at'] ?? '')); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }
}
