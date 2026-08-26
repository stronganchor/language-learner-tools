<?php
if (!defined('WPINC')) { die; }

if (!defined('LL_TOOLS_USER_PROGRESS_RETENTION_OPTION')) {
    define('LL_TOOLS_USER_PROGRESS_RETENTION_OPTION', 'll_user_progress_events_retention_days');
}

if (!defined('LL_TOOLS_USER_PROGRESS_RETENTION_CRON_HOOK')) {
    define('LL_TOOLS_USER_PROGRESS_RETENTION_CRON_HOOK', 'll_tools_user_progress_retention_cleanup');
}

if (!defined('LL_TOOLS_USER_PROGRESS_RETENTION_CONTINUATION_HOOK')) {
    define(
        'LL_TOOLS_USER_PROGRESS_RETENTION_CONTINUATION_HOOK',
        'll_tools_user_progress_retention_cleanup_continue'
    );
}

if (!defined('LL_TOOLS_USER_PROGRESS_RETENTION_CURSOR_OPTION')) {
    define(
        'LL_TOOLS_USER_PROGRESS_RETENTION_CURSOR_OPTION',
        'll_tools_user_progress_retention_cursor'
    );
}

if (!defined('LL_TOOLS_USER_PROGRESS_RETENTION_CEILING_OPTION')) {
    define(
        'LL_TOOLS_USER_PROGRESS_RETENTION_CEILING_OPTION',
        'll_tools_user_progress_retention_ceiling'
    );
}

if (!defined('LL_TOOLS_USER_PROGRESS_RETENTION_HARD_BATCH_LIMIT')) {
    define('LL_TOOLS_USER_PROGRESS_RETENTION_HARD_BATCH_LIMIT', 2000);
}

if (!function_exists('ll_tools_user_progress_retention_default_days')) {
    function ll_tools_user_progress_retention_default_days(): int {
        return max(30, (int) apply_filters('ll_tools_user_progress_retention_default_days', 180));
    }
}

if (!function_exists('ll_tools_sanitize_user_progress_retention_days')) {
    function ll_tools_sanitize_user_progress_retention_days($value): int {
        $days = absint($value);
        if ($days < 30) {
            $days = ll_tools_user_progress_retention_default_days();
        }

        return min(1095, max(30, $days));
    }
}

if (!function_exists('ll_tools_get_user_progress_retention_days')) {
    function ll_tools_get_user_progress_retention_days(): int {
        $saved = get_option(LL_TOOLS_USER_PROGRESS_RETENTION_OPTION, ll_tools_user_progress_retention_default_days());
        return ll_tools_sanitize_user_progress_retention_days($saved);
    }
}

if (!function_exists('ll_tools_user_progress_event_identity_storage_enabled')) {
    function ll_tools_user_progress_event_identity_storage_enabled(): bool {
        return (bool) apply_filters('ll_tools_store_progress_event_client_identity', false);
    }
}

if (!function_exists('ll_tools_register_privacy_settings')) {
    function ll_tools_register_privacy_settings(): void {
        register_setting('language-learning-tools-options', LL_TOOLS_USER_PROGRESS_RETENTION_OPTION, [
            'type' => 'integer',
            'sanitize_callback' => 'll_tools_sanitize_user_progress_retention_days',
            'default' => ll_tools_user_progress_retention_default_days(),
        ]);
    }
}
add_action('admin_init', 'll_tools_register_privacy_settings');

if (!function_exists('ll_tools_render_privacy_settings_rows')) {
    function ll_tools_render_privacy_settings_rows(): void {
        $retention_days = ll_tools_get_user_progress_retention_days();
        ?>
        <tr valign="top">
            <th scope="row"><?php esc_html_e('Learner Progress Privacy:', 'll-tools-text-domain'); ?></th>
            <td>
                <p class="description">
                    <?php esc_html_e('LL Tools treats logged-in study progress as core account functionality. No separate consent checkbox is shown for this core feature. Personal learner analytics, formative practice results, server-scored assignment attempts, selected grades, and configured LMS delivery records should be limited to administrators and the learner\'s assigned teachers, documented in the site privacy policy, and handled through WordPress export/erase tools. Teacher-authorized Google Classroom connections are stored locally only when that connector is configured.', 'll-tools-text-domain'); ?>
                </p>
            </td>
        </tr>
        <tr valign="top">
            <th scope="row"><?php esc_html_e('Detailed Activity Retention (days):', 'll-tools-text-domain'); ?></th>
            <td>
                <input
                    type="number"
                    min="30"
                    max="1095"
                    name="<?php echo esc_attr(LL_TOOLS_USER_PROGRESS_RETENTION_OPTION); ?>"
                    id="<?php echo esc_attr(LL_TOOLS_USER_PROGRESS_RETENTION_OPTION); ?>"
                    value="<?php echo esc_attr((string) $retention_days); ?>" />
                <p class="description">
                    <?php esc_html_e('Detailed activity log rows are deleted automatically after this many days. Summary progress rows remain until the user account is deleted or the site erases the learner’s personal data.', 'll-tools-text-domain'); ?>
                </p>
            </td>
        </tr>
        <?php
    }
}
add_action('ll_tools_settings_after_translations', 'll_tools_render_privacy_settings_rows', 20);

if (!function_exists('ll_tools_schedule_user_progress_retention_cleanup')) {
    function ll_tools_schedule_user_progress_retention_cleanup(): void {
        if (!function_exists('wp_next_scheduled') || !function_exists('wp_schedule_event')) {
            return;
        }

        if (!wp_next_scheduled(LL_TOOLS_USER_PROGRESS_RETENTION_CRON_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', LL_TOOLS_USER_PROGRESS_RETENTION_CRON_HOOK);
        }
    }
}
add_action('init', 'll_tools_schedule_user_progress_retention_cleanup', 30);

if (!function_exists('ll_tools_clear_user_progress_retention_schedule')) {
    function ll_tools_clear_user_progress_retention_schedule(): void {
        if (function_exists('wp_clear_scheduled_hook')) {
            wp_clear_scheduled_hook(LL_TOOLS_USER_PROGRESS_RETENTION_CRON_HOOK);
            wp_clear_scheduled_hook(LL_TOOLS_USER_PROGRESS_RETENTION_CONTINUATION_HOOK);
        }

        delete_option(LL_TOOLS_USER_PROGRESS_RETENTION_CURSOR_OPTION);
        delete_option(LL_TOOLS_USER_PROGRESS_RETENTION_CEILING_OPTION);
    }
}

if (!function_exists('ll_tools_user_progress_retention_batch_limit')) {
    function ll_tools_user_progress_retention_batch_limit(): int {
        $limit = (int) apply_filters('ll_tools_user_progress_retention_batch_limit', 500);
        return max(1, min(LL_TOOLS_USER_PROGRESS_RETENTION_HARD_BATCH_LIMIT, $limit));
    }
}

if (!function_exists('ll_tools_schedule_user_progress_retention_continuation')) {
    function ll_tools_schedule_user_progress_retention_continuation(): void {
        if (!function_exists('wp_next_scheduled') || !function_exists('wp_schedule_single_event')) {
            return;
        }

        if (!wp_next_scheduled(LL_TOOLS_USER_PROGRESS_RETENTION_CONTINUATION_HOOK)) {
            wp_schedule_single_event(
                time() + (5 * MINUTE_IN_SECONDS),
                LL_TOOLS_USER_PROGRESS_RETENTION_CONTINUATION_HOOK
            );
        }
    }
}

if (!function_exists('ll_tools_user_progress_retention_lock_name')) {
    function ll_tools_user_progress_retention_lock_name(): string {
        global $wpdb;

        // MySQL advisory locks are server-wide. Include both the database and
        // the exact site options table so ordinary wp_options tables in
        // unrelated databases do not contend. Hash the scope to avoid putting
        // database or table names into the server-visible lock name.
        $database_name = defined('DB_NAME') ? (string) DB_NAME : '';
        $scope = $database_name . '|' . (string) $wpdb->options;
        return 'll_tools_retention_' . substr(hash('sha256', $scope), 0, 32);
    }
}

if (!function_exists('ll_tools_acquire_user_progress_retention_lock')) {
    function ll_tools_acquire_user_progress_retention_lock(): string {
        global $wpdb;

        $lock_name = ll_tools_user_progress_retention_lock_name();
        $wpdb->last_error = '';
        $acquired = $wpdb->get_var(
            $wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock_name, 0)
        );
        if ($wpdb->last_error !== '' || (int) $acquired !== 1) {
            return '';
        }

        return $lock_name;
    }
}

if (!function_exists('ll_tools_release_user_progress_retention_lock')) {
    function ll_tools_release_user_progress_retention_lock(string $lock_name): void {
        global $wpdb;

        if ($lock_name !== '') {
            // Advisory locks belong to the acquiring DB connection. A delayed
            // worker therefore cannot release a newer worker's lock.
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
        }
    }
}

if (!function_exists('ll_tools_run_user_progress_retention_cleanup_locked')) {
    function ll_tools_run_user_progress_retention_cleanup_locked(): int {
        global $wpdb;

        if (!function_exists('ll_tools_user_progress_table_names')) {
            return 0;
        }

        $retention_days = ll_tools_get_user_progress_retention_days();
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($retention_days * DAY_IN_SECONDS));
        $events_table = ll_tools_user_progress_table_names()['events'];
        $batch_limit = ll_tools_user_progress_retention_batch_limit();
        $cursor = max(0, (int) get_option(LL_TOOLS_USER_PROGRESS_RETENTION_CURSOR_OPTION, 0));
        $ceiling = max(0, (int) get_option(LL_TOOLS_USER_PROGRESS_RETENTION_CEILING_OPTION, 0));

        // Capture one immutable high-water ID per pass. Without a fixed
        // ceiling, a stream that appends faster than continuations drain it can
        // keep the cursor moving forever and prevent already-scanned rows from
        // being revisited after they age past the retention cutoff.
        if ($ceiling <= 0) {
            $wpdb->last_error = '';
            $raw_ceiling = $wpdb->get_var("SELECT MAX(id) FROM {$events_table}");
            if ($wpdb->last_error !== '') {
                return 0;
            }

            $proposed_ceiling = max(0, (int) $raw_ceiling);
            if ($proposed_ceiling <= 0) {
                delete_option(LL_TOOLS_USER_PROGRESS_RETENTION_CURSOR_OPTION);
                delete_option(LL_TOOLS_USER_PROGRESS_RETENTION_CEILING_OPTION);
                return 0;
            }

            if (add_option(LL_TOOLS_USER_PROGRESS_RETENTION_CEILING_OPTION, $proposed_ceiling, '', false)) {
                $ceiling = $proposed_ceiling;
            } else {
                // Another runner may have started the same pass. Join its
                // durable generation instead of replacing the ceiling.
                $ceiling = max(0, (int) get_option(LL_TOOLS_USER_PROGRESS_RETENTION_CEILING_OPTION, 0));
                if ($ceiling <= 0) {
                    return 0;
                }
            }
        }

        if ($cursor >= $ceiling) {
            delete_option(LL_TOOLS_USER_PROGRESS_RETENTION_CURSOR_OPTION);
            delete_option(LL_TOOLS_USER_PROGRESS_RETENTION_CEILING_OPTION);
            return 0;
        }

        // Scan by the primary key instead of created_at: the event table's
        // timestamp indexes are user-scoped, so a timestamp-only cleanup can
        // otherwise scan and lock an arbitrarily large table in one cron run.
        $wpdb->last_error = '';
        $candidate_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT id FROM {$events_table} WHERE id > %d AND id <= %d ORDER BY id ASC LIMIT %d",
                $cursor,
                $ceiling,
                $batch_limit
            )
        );
        if ($wpdb->last_error !== '' || !is_array($candidate_ids)) {
            return 0;
        }

        $candidate_ids = array_values(array_filter(array_map('intval', $candidate_ids), static function (int $event_id): bool {
            return $event_id > 0;
        }));
        if (empty($candidate_ids)) {
            delete_option(LL_TOOLS_USER_PROGRESS_RETENTION_CURSOR_OPTION);
            delete_option(LL_TOOLS_USER_PROGRESS_RETENTION_CEILING_OPTION);
            return 0;
        }

        $placeholders = implode(', ', array_fill(0, count($candidate_ids), '%d'));
        $delete_args = array_merge($candidate_ids, [$cutoff]);
        $sql = $wpdb->prepare(
            "DELETE FROM {$events_table} WHERE id IN ({$placeholders}) AND created_at < %s",
            ...$delete_args
        );

        $wpdb->last_error = '';
        $deleted = $wpdb->query($sql);
        if ($deleted === false || $wpdb->last_error !== '') {
            return 0;
        }

        $last_candidate_id = (int) end($candidate_ids);
        $pass_complete = $last_candidate_id >= $ceiling || count($candidate_ids) < $batch_limit;
        if (!$pass_complete) {
            update_option(LL_TOOLS_USER_PROGRESS_RETENTION_CURSOR_OPTION, $last_candidate_id, false);
            ll_tools_schedule_user_progress_retention_continuation();
        } else {
            delete_option(LL_TOOLS_USER_PROGRESS_RETENTION_CURSOR_OPTION);
            delete_option(LL_TOOLS_USER_PROGRESS_RETENTION_CEILING_OPTION);
        }

        return max(0, (int) $deleted);
    }
}

if (!function_exists('ll_tools_run_user_progress_retention_cleanup')) {
    function ll_tools_run_user_progress_retention_cleanup(): int {
        $lock_name = ll_tools_acquire_user_progress_retention_lock();
        if ($lock_name === '') {
            return 0;
        }

        try {
            return ll_tools_run_user_progress_retention_cleanup_locked();
        } finally {
            ll_tools_release_user_progress_retention_lock($lock_name);
        }
    }
}

add_action(LL_TOOLS_USER_PROGRESS_RETENTION_CRON_HOOK, 'll_tools_run_user_progress_retention_cleanup');
add_action(LL_TOOLS_USER_PROGRESS_RETENTION_CONTINUATION_HOOK, 'll_tools_run_user_progress_retention_cleanup');

if (!function_exists('ll_tools_privacy_get_user_by_email')) {
    function ll_tools_privacy_get_user_by_email(string $email_address): ?WP_User {
        $email_address = sanitize_email($email_address);
        if ($email_address === '') {
            return null;
        }

        $user = get_user_by('email', $email_address);
        return ($user instanceof WP_User) ? $user : null;
    }
}

if (!function_exists('ll_tools_privacy_term_label')) {
    function ll_tools_privacy_term_label(int $term_id, string $taxonomy): string {
        static $cache = [];

        $cache_key = $taxonomy . ':' . $term_id;
        if (isset($cache[$cache_key])) {
            return $cache[$cache_key];
        }

        if ($term_id <= 0) {
            $cache[$cache_key] = '';
            return '';
        }

        $term = get_term($term_id, $taxonomy);
        if (!($term instanceof WP_Term) || is_wp_error($term)) {
            $cache[$cache_key] = '';
            return '';
        }

        $cache[$cache_key] = sanitize_text_field((string) $term->name);
        return $cache[$cache_key];
    }
}

if (!function_exists('ll_tools_privacy_word_label')) {
    function ll_tools_privacy_word_label(int $word_id): string {
        static $cache = [];

        if (isset($cache[$word_id])) {
            return $cache[$word_id];
        }

        $label = $word_id > 0 ? get_the_title($word_id) : '';
        $cache[$word_id] = sanitize_text_field((string) $label);
        return $cache[$word_id];
    }
}

if (!function_exists('ll_tools_privacy_prompt_card_label')) {
    function ll_tools_privacy_prompt_card_label(int $prompt_card_id): string {
        static $cache = [];

        if (isset($cache[$prompt_card_id])) {
            return $cache[$prompt_card_id];
        }

        $label = $prompt_card_id > 0 ? get_the_title($prompt_card_id) : '';
        $cache[$prompt_card_id] = sanitize_text_field((string) $label);
        return $cache[$prompt_card_id];
    }
}

if (!function_exists('ll_tools_privacy_export_data_pair')) {
    function ll_tools_privacy_export_data_pair(string $name, $value): array {
        if (is_bool($value)) {
            $value = $value ? __('Yes', 'll-tools-text-domain') : __('No', 'll-tools-text-domain');
        } elseif (is_array($value) || is_object($value)) {
            $value = wp_json_encode($value);
        } elseif ($value === null) {
            $value = '';
        }

        return [
            'name' => $name,
            'value' => is_scalar($value) ? (string) $value : '',
        ];
    }
}

if (!function_exists('ll_tools_privacy_register_exporters')) {
    function ll_tools_privacy_register_exporters(array $exporters): array {
        $exporters['ll-tools-study-settings'] = [
            'exporter_friendly_name' => __('LL Tools Study Settings', 'll-tools-text-domain'),
            'callback' => 'll_tools_privacy_export_study_settings',
        ];
        $exporters['ll-tools-study-progress'] = [
            'exporter_friendly_name' => __('LL Tools Study Progress', 'll-tools-text-domain'),
            'callback' => 'll_tools_privacy_export_study_progress_rows',
        ];
        $exporters['ll-tools-prompt-card-progress'] = [
            'exporter_friendly_name' => __('LL Tools Prompt Card Progress', 'll-tools-text-domain'),
            'callback' => 'll_tools_privacy_export_prompt_card_progress_rows',
        ];
        $exporters['ll-tools-study-events'] = [
            'exporter_friendly_name' => __('LL Tools Study Activity', 'll-tools-text-domain'),
            'callback' => 'll_tools_privacy_export_study_event_rows',
        ];
        $exporters['ll-tools-offline-sessions'] = [
            'exporter_friendly_name' => __('LL Tools Offline Sessions', 'll-tools-text-domain'),
            'callback' => 'll_tools_privacy_export_offline_sessions',
        ];
        $exporters['ll-tools-class-memberships'] = [
            'exporter_friendly_name' => __('LL Tools Class Memberships', 'll-tools-text-domain'),
            'callback' => 'll_tools_privacy_export_class_memberships',
        ];
        $exporters['ll-tools-lms-assignments'] = [
            'exporter_friendly_name' => __('LL Tools Assignments', 'll-tools-text-domain'),
            'callback' => 'll_tools_privacy_export_lms_assignments',
        ];
        $exporters['ll-tools-grade-deliveries'] = [
            'exporter_friendly_name' => __('LL Tools External Grade Deliveries', 'll-tools-text-domain'),
            'callback' => 'll_tools_privacy_export_grade_deliveries',
        ];
        $exporters['ll-tools-google-classroom'] = [
            'exporter_friendly_name' => __('LL Tools Google Classroom Connections', 'll-tools-text-domain'),
            'callback' => 'll_tools_privacy_export_google_classroom_connections',
        ];

        return $exporters;
    }
}

if (!function_exists('ll_tools_privacy_export_class_memberships')) {
    function ll_tools_privacy_export_class_memberships(string $email_address, int $page = 1): array {
        $user = ll_tools_privacy_get_user_by_email($email_address);
        if (!($user instanceof WP_User) || $page > 1 || !function_exists('ll_tools_teacher_class_get_ids_for_student')) {
            return ['data' => [], 'done' => true];
        }

        $export_items = [];
        foreach (ll_tools_teacher_class_get_ids_for_student((int) $user->ID) as $class_id) {
            $class_id = (int) $class_id;
            if ($class_id <= 0 || !ll_tools_teacher_class_exists($class_id)) {
                continue;
            }

            $wordset_id = ll_tools_teacher_class_get_wordset_id($class_id);
            $teacher_id = ll_tools_teacher_class_get_owner_id($class_id);
            $export_items[] = [
                'group_id' => 'll-tools-class-memberships',
                'group_label' => __('LL Tools Class Memberships', 'll-tools-text-domain'),
                'item_id' => 'll-tools-class-membership-' . $class_id,
                'data' => [
                    ll_tools_privacy_export_data_pair(__('Class ID', 'll-tools-text-domain'), $class_id),
                    ll_tools_privacy_export_data_pair(__('Class', 'll-tools-text-domain'), ll_tools_teacher_class_get_name($class_id)),
                    ll_tools_privacy_export_data_pair(__('Word set ID', 'll-tools-text-domain'), $wordset_id),
                    ll_tools_privacy_export_data_pair(__('Word set', 'll-tools-text-domain'), ll_tools_privacy_term_label($wordset_id, 'wordset')),
                    ll_tools_privacy_export_data_pair(__('Teacher', 'll-tools-text-domain'), $teacher_id > 0 ? get_the_author_meta('display_name', $teacher_id) : ''),
                ],
            ];
        }

        return ['data' => $export_items, 'done' => true];
    }
}

if (!function_exists('ll_tools_privacy_export_lms_assignments')) {
    function ll_tools_privacy_export_lms_assignments(string $email_address, int $page = 1) {
        $user = ll_tools_privacy_get_user_by_email($email_address);
        if (!($user instanceof WP_User)) {
            return ['data' => [], 'done' => true];
        }
        if (!function_exists('ll_tools_lms_assignment_privacy_export_items')) {
            return new WP_Error(
                'assignment_privacy_export_unavailable',
                __('Assignment data is temporarily unavailable for export.', 'll-tools-text-domain')
            );
        }
        $result = ll_tools_lms_assignment_privacy_export_items((int) $user->ID, max(1, $page), 25);
        if (is_wp_error($result)) {
            return $result;
        }
        return [
            'data' => (array) ($result['data'] ?? []),
            'done' => !empty($result['done']),
        ];
    }
}

if (!function_exists('ll_tools_privacy_export_grade_deliveries')) {
    function ll_tools_privacy_export_grade_deliveries(string $email_address, int $page = 1) {
        $user = ll_tools_privacy_get_user_by_email($email_address);
        if (!($user instanceof WP_User)) {
            return ['data' => [], 'done' => true];
        }
        if (!function_exists('ll_tools_grade_delivery_export_user_data_page')) {
            return new WP_Error(
                'grade_delivery_privacy_export_unavailable',
                __('External grade delivery data is temporarily unavailable for export.', 'll-tools-text-domain')
            );
        }
        $result = ll_tools_grade_delivery_export_user_data_page((int) $user->ID, max(1, $page), 50);
        if (is_wp_error($result)) {
            return $result;
        }

        $items = [];
        foreach ((array) ($result['items'] ?? []) as $index => $row) {
            if (!is_array($row)) {
                continue;
            }
            $items[] = [
                'group_id' => 'll-tools-grade-deliveries',
                'group_label' => __('LL Tools External Grade Deliveries', 'll-tools-text-domain'),
                'item_id' => 'll-tools-grade-delivery-' . max(1, (($page - 1) * 50) + $index + 1),
                'data' => [
                    ll_tools_privacy_export_data_pair(__('Provider adapter', 'll-tools-text-domain'), (string) ($row['adapter'] ?? '')),
                    ll_tools_privacy_export_data_pair(__('Assignment ID', 'll-tools-text-domain'), (int) ($row['assignment_id'] ?? 0)),
                    ll_tools_privacy_export_data_pair(__('Assignment revision ID', 'll-tools-text-domain'), (int) ($row['revision_id'] ?? 0)),
                    ll_tools_privacy_export_data_pair(__('Grade revision', 'll-tools-text-domain'), (int) ($row['grade_revision'] ?? 0)),
                    ll_tools_privacy_export_data_pair(__('Score', 'll-tools-text-domain'), (int) ($row['score_given'] ?? 0) . ' / ' . (int) ($row['score_maximum'] ?? 0)),
                    ll_tools_privacy_export_data_pair(__('Points', 'll-tools-text-domain'), (string) ($row['points_given'] ?? '') . ' / ' . (string) ($row['points_maximum'] ?? '')),
                    ll_tools_privacy_export_data_pair(__('Delivery status', 'll-tools-text-domain'), (string) ($row['status'] ?? '')),
                    ll_tools_privacy_export_data_pair(__('Delivery attempts', 'll-tools-text-domain'), (int) ($row['attempt_count'] ?? 0)),
                    ll_tools_privacy_export_data_pair(__('Last HTTP status', 'll-tools-text-domain'), (int) ($row['last_http_status'] ?? 0)),
                    ll_tools_privacy_export_data_pair(__('Last error code', 'll-tools-text-domain'), (string) ($row['last_error_code'] ?? '')),
                    ll_tools_privacy_export_data_pair(__('Created at (UTC)', 'll-tools-text-domain'), (string) ($row['created_at'] ?? '')),
                    ll_tools_privacy_export_data_pair(__('Updated at (UTC)', 'll-tools-text-domain'), (string) ($row['updated_at'] ?? '')),
                    ll_tools_privacy_export_data_pair(__('Delivered at (UTC)', 'll-tools-text-domain'), (string) ($row['delivered_at'] ?? '')),
                    ll_tools_privacy_export_data_pair(__('Superseded at (UTC)', 'll-tools-text-domain'), (string) ($row['superseded_at'] ?? '')),
                ],
            ];
        }

        if ($page === 1) {
            $mapping_counts = (array) ($result['mapping_counts'] ?? []);
            $items[] = [
                'group_id' => 'll-tools-grade-deliveries',
                'group_label' => __('LL Tools External Grade Deliveries', 'll-tools-text-domain'),
                'item_id' => 'll-tools-grade-delivery-mappings',
                'data' => [
                    ll_tools_privacy_export_data_pair(__('External identity mappings', 'll-tools-text-domain'), (int) ($mapping_counts['external_identities'] ?? 0)),
                    ll_tools_privacy_export_data_pair(__('Grade recipient mappings', 'll-tools-text-domain'), (int) ($mapping_counts['grade_recipients'] ?? 0)),
                ],
            ];
        }

        return ['data' => $items, 'done' => !empty($result['done'])];
    }
}

if (!function_exists('ll_tools_privacy_export_google_classroom_connections')) {
    function ll_tools_privacy_export_google_classroom_connections(string $email_address, int $page = 1) {
        global $wpdb;

        $user = ll_tools_privacy_get_user_by_email($email_address);
        if (!($user instanceof WP_User)) {
            return ['data' => [], 'done' => true];
        }
        if (
            !function_exists('ll_tools_google_classroom_schema_is_ready')
            || !ll_tools_google_classroom_schema_is_ready()
            || !function_exists('ll_tools_google_classroom_get_connection_summary')
        ) {
            return new WP_Error(
                'google_classroom_privacy_export_unavailable',
                __('Google Classroom connection data is temporarily unavailable for export.', 'll-tools-text-domain')
            );
        }
        $page = max(1, $page);
        $number = 20;
        $offset = ($page - 1) * $number;
        $table = ll_tools_google_classroom_table_names()['connections'];
        $wpdb->last_error = '';
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$table} WHERE teacher_user_id = %d ORDER BY updated_at DESC, id DESC LIMIT %d OFFSET %d",
            (int) $user->ID,
            $number + 1,
            $offset
        ));
        if (!is_array($ids) || (string) $wpdb->last_error !== '') {
            return new WP_Error(
                'google_classroom_privacy_export_failed',
                __('Google Classroom connection data could not be exported.', 'll-tools-text-domain')
            );
        }
        $has_more = count((array) $ids) > $number;
        $ids = array_slice((array) $ids, 0, $number);
        $items = [];
        foreach ($ids as $id) {
            $summary = ll_tools_google_classroom_get_connection_summary((int) $id, (int) $user->ID);
            if (is_wp_error($summary)) {
                return new WP_Error(
                    'google_classroom_privacy_export_failed',
                    __('Google Classroom connection data could not be exported.', 'll-tools-text-domain')
                );
            }
            $profile = function_exists('ll_tools_google_classroom_privacy_profile_for_connection')
                ? ll_tools_google_classroom_privacy_profile_for_connection((int) $id, (int) $user->ID)
                : new WP_Error('ll_tools_google_classroom_privacy_profile_unavailable');
            if (is_wp_error($profile)) {
                return new WP_Error(
                    'google_classroom_privacy_export_failed',
                    __('Google Classroom connection data could not be exported.', 'll-tools-text-domain')
                );
            }
            $items[] = [
                'group_id' => 'll-tools-google-classroom',
                'group_label' => __('LL Tools Google Classroom Connections', 'll-tools-text-domain'),
                'item_id' => 'll-tools-google-classroom-' . (int) $summary['id'],
                'data' => [
                    ll_tools_privacy_export_data_pair(__('Provider', 'll-tools-text-domain'), (string) $summary['provider']),
                    ll_tools_privacy_export_data_pair(__('Connection status', 'll-tools-text-domain'), (string) $summary['status']),
                    ll_tools_privacy_export_data_pair(__('Google account email', 'll-tools-text-domain'), (string) ($profile['email'] ?? '')),
                    ll_tools_privacy_export_data_pair(__('Google account name', 'll-tools-text-domain'), (string) ($profile['name'] ?? '')),
                    ll_tools_privacy_export_data_pair(__('Google Workspace domain', 'll-tools-text-domain'), (string) ($profile['hd'] ?? '')),
                    ll_tools_privacy_export_data_pair(__('Google email verified', 'll-tools-text-domain'), !empty($profile['email_verified'])),
                    ll_tools_privacy_export_data_pair(__('Authorized scopes', 'll-tools-text-domain'), (array) $summary['scopes']),
                    ll_tools_privacy_export_data_pair(__('Connected at (UTC)', 'll-tools-text-domain'), (string) $summary['connected_at']),
                    ll_tools_privacy_export_data_pair(__('Updated at (UTC)', 'll-tools-text-domain'), (string) $summary['updated_at']),
                    ll_tools_privacy_export_data_pair(__('Disconnected at (UTC)', 'll-tools-text-domain'), (string) ($summary['disconnected_at'] ?? '')),
                ],
            ];
        }
        if ($page === 1) {
            $states_table = ll_tools_google_classroom_table_names()['oauth_states'];
            $wpdb->last_error = '';
            $state_summary = $wpdb->get_row($wpdb->prepare(
                "SELECT COUNT(*) AS total,
                        SUM(CASE WHEN consumed_at IS NOT NULL THEN 1 ELSE 0 END) AS consumed,
                        MIN(created_at) AS oldest_created_at,
                        MAX(expires_at) AS latest_expires_at
                 FROM {$states_table}
                 WHERE teacher_user_id = %d",
                (int) $user->ID
            ), ARRAY_A);
            if (!is_array($state_summary) || (string) $wpdb->last_error !== '') {
                return new WP_Error(
                    'google_classroom_privacy_export_failed',
                    __('Google Classroom connection data could not be exported.', 'll-tools-text-domain')
                );
            }
            if ((int) ($state_summary['total'] ?? 0) > 0) {
                $items[] = [
                    'group_id' => 'll-tools-google-classroom',
                    'group_label' => __('LL Tools Google Classroom Connections', 'll-tools-text-domain'),
                    'item_id' => 'll-tools-google-classroom-oauth-state-summary',
                    'data' => [
                        ll_tools_privacy_export_data_pair(__('Stored authorization attempts', 'll-tools-text-domain'), (int) $state_summary['total']),
                        ll_tools_privacy_export_data_pair(__('Consumed authorization attempts', 'll-tools-text-domain'), (int) ($state_summary['consumed'] ?? 0)),
                        ll_tools_privacy_export_data_pair(__('Oldest authorization attempt (UTC)', 'll-tools-text-domain'), (string) ($state_summary['oldest_created_at'] ?? '')),
                        ll_tools_privacy_export_data_pair(__('Latest authorization expiry (UTC)', 'll-tools-text-domain'), (string) ($state_summary['latest_expires_at'] ?? '')),
                    ],
                ];
            }
        }
        return ['data' => $items, 'done' => !$has_more];
    }
}
add_filter('wp_privacy_personal_data_exporters', 'll_tools_privacy_register_exporters');

if (!function_exists('ll_tools_privacy_export_study_settings')) {
    function ll_tools_privacy_export_study_settings(string $email_address, int $page = 1): array {
        $user = ll_tools_privacy_get_user_by_email($email_address);
        if (!($user instanceof WP_User) || $page > 1) {
            return [
                'data' => [],
                'done' => true,
            ];
        }

        $study_state = function_exists('ll_tools_get_user_study_state')
            ? ll_tools_get_user_study_state((int) $user->ID)
            : [];
        $study_goals = function_exists('ll_tools_get_user_study_goals')
            ? ll_tools_get_user_study_goals((int) $user->ID)
            : [];
        $category_progress = function_exists('ll_tools_get_user_category_progress')
            ? ll_tools_get_user_category_progress((int) $user->ID)
            : [];
        $recommendation_queue = defined('LL_TOOLS_USER_RECOMMENDATION_QUEUE_META')
            ? get_user_meta((int) $user->ID, LL_TOOLS_USER_RECOMMENDATION_QUEUE_META, true)
            : [];
        $last_recommendation = defined('LL_TOOLS_USER_LAST_RECOMMENDATION_META')
            ? get_user_meta((int) $user->ID, LL_TOOLS_USER_LAST_RECOMMENDATION_META, true)
            : [];
        $dismissed = defined('LL_TOOLS_USER_RECOMMENDATION_DISMISSED_META')
            ? get_user_meta((int) $user->ID, LL_TOOLS_USER_RECOMMENDATION_DISMISSED_META, true)
            : [];
        $deferrals = defined('LL_TOOLS_USER_RECOMMENDATION_DEFERRALS_META')
            ? get_user_meta((int) $user->ID, LL_TOOLS_USER_RECOMMENDATION_DEFERRALS_META, true)
            : [];
        $completed_content_lesson_ids = function_exists('ll_tools_get_completed_content_lesson_ids')
            ? ll_tools_get_completed_content_lesson_ids((int) $user->ID)
            : [];
        $legacy_completed_lessons = get_user_meta(
            (int) $user->ID,
            'tt_completed_lessons',
            true
        );

        $data = [];

        if (!empty($study_state)) {
            $wordset_id = (int) ($study_state['wordset_id'] ?? 0);
            $category_ids = array_values(array_filter(array_map('intval', (array) ($study_state['category_ids'] ?? []))));
            $data[] = [
                'group_id' => 'll-tools-study-settings',
                'group_label' => __('LL Tools Study Settings', 'll-tools-text-domain'),
                'item_id' => 'll-tools-study-settings-' . (int) $user->ID,
                'data' => [
                    ll_tools_privacy_export_data_pair(__('Selected word set ID', 'll-tools-text-domain'), $wordset_id),
                    ll_tools_privacy_export_data_pair(__('Selected word set', 'll-tools-text-domain'), ll_tools_privacy_term_label($wordset_id, 'wordset')),
                    ll_tools_privacy_export_data_pair(__('Selected category IDs', 'll-tools-text-domain'), $category_ids),
                    ll_tools_privacy_export_data_pair(__('Starred word IDs', 'll-tools-text-domain'), (array) ($study_state['starred_word_ids'] ?? [])),
                    ll_tools_privacy_export_data_pair(__('Fast transitions enabled', 'll-tools-text-domain'), !empty($study_state['fast_transitions'])),
                ],
            ];
        }

        if (!empty($study_goals)) {
            $data[] = [
                'group_id' => 'll-tools-study-goals',
                'group_label' => __('LL Tools Study Goals', 'll-tools-text-domain'),
                'item_id' => 'll-tools-study-goals-' . (int) $user->ID,
                'data' => [
                    ll_tools_privacy_export_data_pair(__('Enabled modes', 'll-tools-text-domain'), (array) ($study_goals['enabled_modes'] ?? [])),
                    ll_tools_privacy_export_data_pair(__('Ignored category IDs', 'll-tools-text-domain'), (array) ($study_goals['ignored_category_ids'] ?? [])),
                    ll_tools_privacy_export_data_pair(__('Preferred word set IDs', 'll-tools-text-domain'), (array) ($study_goals['preferred_wordset_ids'] ?? [])),
                    ll_tools_privacy_export_data_pair(__('Placement-known category IDs', 'll-tools-text-domain'), (array) ($study_goals['placement_known_category_ids'] ?? [])),
                    ll_tools_privacy_export_data_pair(__('Daily new-word target', 'll-tools-text-domain'), (int) ($study_goals['daily_new_word_target'] ?? 0)),
                    ll_tools_privacy_export_data_pair(__('Priority focus', 'll-tools-text-domain'), (string) ($study_goals['priority_focus'] ?? '')),
                ],
            ];
        }

        if (!empty($category_progress)) {
            $data[] = [
                'group_id' => 'll-tools-category-progress',
                'group_label' => __('LL Tools Category Progress', 'll-tools-text-domain'),
                'item_id' => 'll-tools-category-progress-' . (int) $user->ID,
                'data' => [
                    ll_tools_privacy_export_data_pair(__('Category progress', 'll-tools-text-domain'), $category_progress),
                ],
            ];
        }

        if (!empty($completed_content_lesson_ids) || !empty($legacy_completed_lessons)) {
            $completion_data = [];
            if (!empty($completed_content_lesson_ids)) {
                $completion_data[] = ll_tools_privacy_export_data_pair(
                    __('Completed content lesson IDs', 'll-tools-text-domain'),
                    $completed_content_lesson_ids
                );
            }
            if (!empty($legacy_completed_lessons)) {
                $completion_data[] = ll_tools_privacy_export_data_pair(
                    __('Legacy completed lesson data', 'll-tools-text-domain'),
                    $legacy_completed_lessons
                );
            }
            $data[] = [
                'group_id' => 'll-tools-content-lesson-progress',
                'group_label' => __('LL Tools Content Lesson Progress', 'll-tools-text-domain'),
                'item_id' => 'll-tools-content-lesson-progress-' . (int) $user->ID,
                'data' => $completion_data,
            ];
        }

        if (!empty($recommendation_queue) || !empty($last_recommendation) || !empty($dismissed) || !empty($deferrals)) {
            $data[] = [
                'group_id' => 'll-tools-recommendations',
                'group_label' => __('LL Tools Recommendation State', 'll-tools-text-domain'),
                'item_id' => 'll-tools-recommendations-' . (int) $user->ID,
                'data' => [
                    ll_tools_privacy_export_data_pair(__('Recommendation queue', 'll-tools-text-domain'), $recommendation_queue),
                    ll_tools_privacy_export_data_pair(__('Last recommendation', 'll-tools-text-domain'), $last_recommendation),
                    ll_tools_privacy_export_data_pair(__('Dismissed recommendation signatures', 'll-tools-text-domain'), $dismissed),
                    ll_tools_privacy_export_data_pair(__('Recommendation deferrals', 'll-tools-text-domain'), $deferrals),
                ],
            ];
        }

        return [
            'data' => $data,
            'done' => true,
        ];
    }
}

if (!function_exists('ll_tools_privacy_export_study_progress_rows')) {
    function ll_tools_privacy_export_study_progress_rows(string $email_address, int $page = 1): array {
        global $wpdb;

        $user = ll_tools_privacy_get_user_by_email($email_address);
        if (!($user instanceof WP_User)) {
            return [
                'data' => [],
                'done' => true,
            ];
        }

        $page = max(1, (int) $page);
        $number = 100;
        $offset = ($page - 1) * $number;
        $table = ll_tools_user_progress_table_names()['words'];

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE user_id = %d ORDER BY last_seen_at DESC, word_id DESC LIMIT %d OFFSET %d",
                (int) $user->ID,
                $number,
                $offset
            ),
            ARRAY_A
        );

        $export_items = [];
        foreach ((array) $rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $word_id = (int) ($row['word_id'] ?? 0);
            $category_id = (int) ($row['category_id'] ?? 0);
            $wordset_id = (int) ($row['wordset_id'] ?? 0);
            $export_items[] = [
                'group_id' => 'll-tools-study-progress',
                'group_label' => __('LL Tools Study Progress', 'll-tools-text-domain'),
                'item_id' => 'll-tools-progress-word-' . $word_id,
                'data' => [
                    ll_tools_privacy_export_data_pair(__('Word ID', 'll-tools-text-domain'), $word_id),
                    ll_tools_privacy_export_data_pair(__('Word', 'll-tools-text-domain'), ll_tools_privacy_word_label($word_id)),
                    ll_tools_privacy_export_data_pair(__('Category ID', 'll-tools-text-domain'), $category_id),
                    ll_tools_privacy_export_data_pair(__('Category', 'll-tools-text-domain'), ll_tools_privacy_term_label($category_id, 'word-category')),
                    ll_tools_privacy_export_data_pair(__('Word set ID', 'll-tools-text-domain'), $wordset_id),
                    ll_tools_privacy_export_data_pair(__('Word set', 'll-tools-text-domain'), ll_tools_privacy_term_label($wordset_id, 'wordset')),
                    ll_tools_privacy_export_data_pair(__('First seen at (UTC)', 'll-tools-text-domain'), (string) ($row['first_seen_at'] ?? '')),
                    ll_tools_privacy_export_data_pair(__('Last seen at (UTC)', 'll-tools-text-domain'), (string) ($row['last_seen_at'] ?? '')),
                    ll_tools_privacy_export_data_pair(__('Last mode', 'll-tools-text-domain'), (string) ($row['last_mode'] ?? '')),
                    ll_tools_privacy_export_data_pair(__('Status', 'll-tools-text-domain'), function_exists('ll_tools_user_progress_word_status') ? ll_tools_user_progress_word_status($row) : ''),
                    ll_tools_privacy_export_data_pair(__('Difficulty score', 'll-tools-text-domain'), function_exists('ll_tools_user_progress_word_difficulty_score') ? ll_tools_user_progress_word_difficulty_score($row) : 0),
                    ll_tools_privacy_export_data_pair(__('Total coverage', 'll-tools-text-domain'), (int) ($row['total_coverage'] ?? 0)),
                    ll_tools_privacy_export_data_pair(__('Learning coverage', 'll-tools-text-domain'), (int) ($row['coverage_learning'] ?? 0)),
                    ll_tools_privacy_export_data_pair(__('Practice coverage', 'll-tools-text-domain'), (int) ($row['coverage_practice'] ?? 0)),
                    ll_tools_privacy_export_data_pair(__('Listening coverage', 'll-tools-text-domain'), (int) ($row['coverage_listening'] ?? 0)),
                    ll_tools_privacy_export_data_pair(__('Gender coverage', 'll-tools-text-domain'), (int) ($row['coverage_gender'] ?? 0)),
                    ll_tools_privacy_export_data_pair(__('Self-check coverage', 'll-tools-text-domain'), (int) ($row['coverage_self_check'] ?? 0)),
                    ll_tools_privacy_export_data_pair(__('Correct on first try', 'll-tools-text-domain'), (int) ($row['correct_clean'] ?? 0)),
                    ll_tools_privacy_export_data_pair(__('Correct after retry', 'll-tools-text-domain'), (int) ($row['correct_after_retry'] ?? 0)),
                    ll_tools_privacy_export_data_pair(__('Incorrect answers', 'll-tools-text-domain'), (int) ($row['incorrect'] ?? 0)),
                    ll_tools_privacy_export_data_pair(__('Lapse count', 'll-tools-text-domain'), (int) ($row['lapse_count'] ?? 0)),
                    ll_tools_privacy_export_data_pair(__('Current streak', 'll-tools-text-domain'), (int) ($row['current_correct_streak'] ?? 0)),
                    ll_tools_privacy_export_data_pair(__('Stage', 'll-tools-text-domain'), (int) ($row['stage'] ?? 0)),
                    ll_tools_privacy_export_data_pair(__('Due at (UTC)', 'll-tools-text-domain'), (string) ($row['due_at'] ?? '')),
                    ll_tools_privacy_export_data_pair(__('Mastery unlocked', 'll-tools-text-domain'), !empty($row['mastery_unlocked'])),
                    ll_tools_privacy_export_data_pair(__('Gender progress', 'll-tools-text-domain'), function_exists('ll_tools_get_progress_row_gender_progress') ? ll_tools_get_progress_row_gender_progress($row) : []),
                    ll_tools_privacy_export_data_pair(__('Required practice recording types', 'll-tools-text-domain'), function_exists('ll_tools_get_progress_row_practice_required_recording_types') ? ll_tools_get_progress_row_practice_required_recording_types($row) : []),
                    ll_tools_privacy_export_data_pair(__('Correct practice recording types', 'll-tools-text-domain'), function_exists('ll_tools_get_progress_row_practice_correct_recording_types') ? ll_tools_get_progress_row_practice_correct_recording_types($row) : []),
                ],
            ];
        }

        return [
            'data' => $export_items,
            'done' => count((array) $rows) < $number,
        ];
    }
}

if (!function_exists('ll_tools_privacy_export_prompt_card_progress_rows')) {
    function ll_tools_privacy_export_prompt_card_progress_rows(string $email_address, int $page = 1): array {
        $user = ll_tools_privacy_get_user_by_email($email_address);
        if (!($user instanceof WP_User)) {
            return [
                'data' => [],
                'done' => true,
            ];
        }

        $page = max(1, (int) $page);
        $number = 100;
        $offset = ($page - 1) * $number;
        $all_rows = function_exists('ll_tools_get_user_prompt_card_progress')
            ? array_values(ll_tools_get_user_prompt_card_progress((int) $user->ID))
            : [];
        if (empty($all_rows)) {
            return [
                'data' => [],
                'done' => true,
            ];
        }

        usort($all_rows, static function (array $left, array $right): int {
            return strcmp((string) ($right['last_seen_at'] ?? ''), (string) ($left['last_seen_at'] ?? ''));
        });

        $rows = array_slice($all_rows, $offset, $number);
        $export_items = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $prompt_card_id = (int) ($row['prompt_card_id'] ?? 0);
            $category_id = (int) ($row['category_id'] ?? 0);
            $wordset_id = (int) ($row['wordset_id'] ?? 0);
            $card = function_exists('ll_tools_get_prompt_card_data')
                ? ll_tools_get_prompt_card_data($prompt_card_id)
                : [];
            $correct_answer_word_id = (int) ($card['correct_answer_word_id'] ?? 0);

            $export_items[] = [
                'group_id' => 'll-tools-prompt-card-progress',
                'group_label' => __('LL Tools Prompt Card Progress', 'll-tools-text-domain'),
                'item_id' => 'll-tools-prompt-card-progress-' . $prompt_card_id,
                'data' => [
                    ll_tools_privacy_export_data_pair(__('Prompt card ID', 'll-tools-text-domain'), $prompt_card_id),
                    ll_tools_privacy_export_data_pair(__('Prompt card', 'll-tools-text-domain'), ll_tools_privacy_prompt_card_label($prompt_card_id)),
                    ll_tools_privacy_export_data_pair(__('Prompt text', 'll-tools-text-domain'), (string) ($card['prompt_text'] ?? '')),
                    ll_tools_privacy_export_data_pair(__('Correct answer word ID', 'll-tools-text-domain'), $correct_answer_word_id),
                    ll_tools_privacy_export_data_pair(__('Correct answer word', 'll-tools-text-domain'), ll_tools_privacy_word_label($correct_answer_word_id)),
                    ll_tools_privacy_export_data_pair(__('Category ID', 'll-tools-text-domain'), $category_id),
                    ll_tools_privacy_export_data_pair(__('Category', 'll-tools-text-domain'), ll_tools_privacy_term_label($category_id, 'word-category')),
                    ll_tools_privacy_export_data_pair(__('Word set ID', 'll-tools-text-domain'), $wordset_id),
                    ll_tools_privacy_export_data_pair(__('Word set', 'll-tools-text-domain'), ll_tools_privacy_term_label($wordset_id, 'wordset')),
                    ll_tools_privacy_export_data_pair(__('First seen at (UTC)', 'll-tools-text-domain'), (string) ($row['first_seen_at'] ?? '')),
                    ll_tools_privacy_export_data_pair(__('Last seen at (UTC)', 'll-tools-text-domain'), (string) ($row['last_seen_at'] ?? '')),
                    ll_tools_privacy_export_data_pair(__('Last mode', 'll-tools-text-domain'), (string) ($row['last_mode'] ?? '')),
                    ll_tools_privacy_export_data_pair(__('Status', 'll-tools-text-domain'), function_exists('ll_tools_user_prompt_card_progress_status') ? ll_tools_user_prompt_card_progress_status($row) : ''),
                    ll_tools_privacy_export_data_pair(__('Exposure total', 'll-tools-text-domain'), (int) ($row['exposure_total'] ?? 0)),
                    ll_tools_privacy_export_data_pair(__('Correct on first try', 'll-tools-text-domain'), (int) ($row['correct_clean'] ?? 0)),
                    ll_tools_privacy_export_data_pair(__('Correct after retry', 'll-tools-text-domain'), (int) ($row['correct_after_retry'] ?? 0)),
                    ll_tools_privacy_export_data_pair(__('Incorrect answers', 'll-tools-text-domain'), (int) ($row['incorrect'] ?? 0)),
                    ll_tools_privacy_export_data_pair(__('Current streak', 'll-tools-text-domain'), (int) ($row['current_correct_streak'] ?? 0)),
                    ll_tools_privacy_export_data_pair(__('Mastery unlocked', 'll-tools-text-domain'), !empty($row['mastery_unlocked'])),
                ],
            ];
        }

        return [
            'data' => $export_items,
            'done' => ($offset + count($rows)) >= count($all_rows),
        ];
    }
}

if (!function_exists('ll_tools_privacy_export_study_event_rows')) {
    function ll_tools_privacy_export_study_event_rows(string $email_address, int $page = 1): array {
        global $wpdb;

        $user = ll_tools_privacy_get_user_by_email($email_address);
        if (!($user instanceof WP_User)) {
            return [
                'data' => [],
                'done' => true,
            ];
        }

        $page = max(1, (int) $page);
        $number = 100;
        $offset = ($page - 1) * $number;
        $table = ll_tools_user_progress_table_names()['events'];
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE user_id = %d ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d",
                (int) $user->ID,
                $number,
                $offset
            ),
            ARRAY_A
        );

        $export_items = [];
        foreach ((array) $rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $word_id = (int) ($row['word_id'] ?? 0);
            $category_id = (int) ($row['category_id'] ?? 0);
            $wordset_id = (int) ($row['wordset_id'] ?? 0);
            $payload = [];
            $payload_json = isset($row['payload_json']) ? (string) $row['payload_json'] : '';
            if ($payload_json !== '') {
                $decoded = json_decode($payload_json, true);
                if (is_array($decoded)) {
                    $payload = $decoded;
                }
            }
            $prompt_card_id = isset($payload['prompt_card_id']) ? (int) $payload['prompt_card_id'] : 0;

            $export_items[] = [
                'group_id' => 'll-tools-study-activity',
                'group_label' => __('LL Tools Study Activity', 'll-tools-text-domain'),
                'item_id' => 'll-tools-activity-' . (int) ($row['id'] ?? 0),
                'data' => [
                    ll_tools_privacy_export_data_pair(__('Recorded at (UTC)', 'll-tools-text-domain'), (string) ($row['created_at'] ?? '')),
                    ll_tools_privacy_export_data_pair(__('Event type', 'll-tools-text-domain'), (string) ($row['event_type'] ?? '')),
                    ll_tools_privacy_export_data_pair(__('Mode', 'll-tools-text-domain'), (string) ($row['mode'] ?? '')),
                    ll_tools_privacy_export_data_pair(__('Word ID', 'll-tools-text-domain'), $word_id),
                    ll_tools_privacy_export_data_pair(__('Word', 'll-tools-text-domain'), ll_tools_privacy_word_label($word_id)),
                    ll_tools_privacy_export_data_pair(__('Prompt card ID', 'll-tools-text-domain'), $prompt_card_id),
                    ll_tools_privacy_export_data_pair(__('Prompt card', 'll-tools-text-domain'), ll_tools_privacy_prompt_card_label($prompt_card_id)),
                    ll_tools_privacy_export_data_pair(__('Category ID', 'll-tools-text-domain'), $category_id),
                    ll_tools_privacy_export_data_pair(__('Category', 'll-tools-text-domain'), ll_tools_privacy_term_label($category_id, 'word-category')),
                    ll_tools_privacy_export_data_pair(__('Word set ID', 'll-tools-text-domain'), $wordset_id),
                    ll_tools_privacy_export_data_pair(__('Word set', 'll-tools-text-domain'), ll_tools_privacy_term_label($wordset_id, 'wordset')),
                    ll_tools_privacy_export_data_pair(__('Correct result', 'll-tools-text-domain'), $row['is_correct']),
                    ll_tools_privacy_export_data_pair(__('Had wrong answer before success', 'll-tools-text-domain'), !empty($row['had_wrong_before'])),
                    ll_tools_privacy_export_data_pair(__('Payload', 'll-tools-text-domain'), $payload),
                ],
            ];
        }

        return [
            'data' => $export_items,
            'done' => count((array) $rows) < $number,
        ];
    }
}

if (!function_exists('ll_tools_privacy_export_offline_sessions')) {
    function ll_tools_privacy_export_offline_sessions(string $email_address, int $page = 1): array {
        $user = ll_tools_privacy_get_user_by_email($email_address);
        if (!($user instanceof WP_User) || $page > 1 || !defined('LL_TOOLS_OFFLINE_APP_SESSION_META')) {
            return [
                'data' => [],
                'done' => true,
            ];
        }

        $sessions = function_exists('ll_tools_offline_app_sessions_for_user')
            ? ll_tools_offline_app_sessions_for_user((int) $user->ID)
            : [];
        if (empty($sessions)) {
            return [
                'data' => [],
                'done' => true,
            ];
        }

        $export_items = [];
        foreach ($sessions as $session_key => $session) {
            if (!is_array($session)) {
                continue;
            }

            $export_items[] = [
                'group_id' => 'll-tools-offline-sessions',
                'group_label' => __('LL Tools Offline Sessions', 'll-tools-text-domain'),
                'item_id' => 'll-tools-offline-session-' . sanitize_key((string) $session_key),
                'data' => [
                    ll_tools_privacy_export_data_pair(__('Session key', 'll-tools-text-domain'), sanitize_key((string) $session_key)),
                    ll_tools_privacy_export_data_pair(__('Created at (UTC)', 'll-tools-text-domain'), (string) ($session['created_at'] ?? '')),
                    ll_tools_privacy_export_data_pair(__('Last used at (UTC)', 'll-tools-text-domain'), (string) ($session['last_used_at'] ?? '')),
                    ll_tools_privacy_export_data_pair(__('Expires at (UTC)', 'll-tools-text-domain'), (string) ($session['expires_at'] ?? '')),
                    ll_tools_privacy_export_data_pair(__('Device identifier', 'll-tools-text-domain'), (string) ($session['device_id'] ?? '')),
                    ll_tools_privacy_export_data_pair(__('Profile identifier', 'll-tools-text-domain'), (string) ($session['profile_id'] ?? '')),
                ],
            ];
        }

        return [
            'data' => $export_items,
            'done' => true,
        ];
    }
}

if (!function_exists('ll_tools_privacy_local_erasure_engine_status')) {
    /**
     * Verify every table covered by the local privacy rollback is transactional.
     *
     * @return array{ready:bool,engines:array<string,string>,details:array<int,string>}
     */
    function ll_tools_privacy_local_erasure_engine_status(): array {
        global $wpdb;

        $progress_tables = ll_tools_user_progress_table_names();
        $tables = [
            'users' => (string) $wpdb->users,
            'usermeta' => (string) $wpdb->usermeta,
            'posts' => (string) $wpdb->posts,
            'postmeta' => (string) $wpdb->postmeta,
            'offline_sessions' => (string) ll_tools_offline_app_session_table(),
            'progress_words' => (string) ($progress_tables['words'] ?? ''),
            'progress_events' => (string) ($progress_tables['events'] ?? ''),
        ];
        $engines = [];
        $details = [];
        foreach ($tables as $table_key => $table) {
            if ($table === '') {
                $engines[$table_key] = '';
                $details[] = $table_key . ': table unavailable';
                continue;
            }

            $wpdb->last_error = '';
            $table_status = $wpdb->get_row(
                $wpdb->prepare('SHOW TABLE STATUS LIKE %s', $wpdb->esc_like($table)),
                ARRAY_A
            );
            $query_error = (string) $wpdb->last_error;
            $engine = is_array($table_status) ? trim((string) ($table_status['Engine'] ?? '')) : '';
            $engine = trim((string) apply_filters(
                'll_tools_privacy_erasure_table_engine',
                $engine,
                $table_key,
                $table,
                $table_status,
                $query_error
            ));
            $engines[$table_key] = $engine;
            if ($query_error !== '') {
                $details[] = $table . ': ' . $query_error;
            } elseif (!is_array($table_status) || $engine === '') {
                $details[] = $table . ': engine metadata unavailable';
            } elseif (strcasecmp($engine, 'InnoDB') !== 0) {
                $details[] = $table . ': ' . $engine;
            }
        }

        return [
            'ready' => $details === [],
            'engines' => $engines,
            'details' => $details,
        ];
    }
}

if (!function_exists('ll_tools_privacy_delete_user_personal_data_verified')) {
    /**
     * Transactionally erase and verify local study/session/class data.
     *
     * Lock ordering is always offline-session advisory lock, then the user row.
     * Direct/offline progress writers use the same order, so a writer either
     * completes before this transaction and is erased or observes the fence.
     *
     * @param bool $allow_missing_user Permit durable post-delete cleanup after
     *                                 the WordPress user row is already gone.
     * @return array{removed:int}|WP_Error
     */
    function ll_tools_privacy_delete_user_personal_data_verified(
        int $user_id,
        bool $allow_missing_user = false
    ) {
        global $wpdb;

        if ($user_id <= 0) {
            return new WP_Error('ll_tools_privacy_invalid_user');
        }
        if (
            !function_exists('ll_tools_offline_app_acquire_user_session_lock')
            || !function_exists('ll_tools_offline_app_release_user_session_lock')
            || !function_exists('ll_tools_offline_app_privacy_erase_user_sessions_locked')
            || !function_exists('ll_tools_teacher_class_unlink_student_verified')
            || !function_exists('ll_tools_user_progress_begin_event_transaction')
            || !function_exists('ll_tools_user_progress_lock_user_for_event')
        ) {
            return new WP_Error(
                'll_tools_privacy_erasure_runtime_unavailable',
                __('LL Tools personal data could not be erased safely.', 'll-tools-text-domain')
            );
        }
        $schema_status = function_exists('ll_tools_user_progress_runtime_schema_status')
            ? ll_tools_user_progress_runtime_schema_status()
            : ['ready' => false];
        if (empty($schema_status['ready'])) {
            return new WP_Error(
                'll_tools_privacy_progress_schema_unavailable',
                __('LL Tools personal data could not be erased safely.', 'll-tools-text-domain')
            );
        }
        $engine_status = function_exists('ll_tools_user_progress_core_engine_status')
            ? ll_tools_user_progress_core_engine_status(true)
            : ['ready' => false];
        if (empty($engine_status['ready'])) {
            return new WP_Error(
                'll_tools_privacy_transactional_engine_unavailable',
                __('LL Tools personal data cannot be erased safely while transactional storage is unavailable.', 'll-tools-text-domain')
            );
        }
        $erasure_engine_status = ll_tools_privacy_local_erasure_engine_status();
        if (empty($erasure_engine_status['ready'])) {
            return new WP_Error(
                'll_tools_privacy_transactional_engine_unavailable',
                __('LL Tools personal data cannot be erased safely while transactional storage is unavailable.', 'll-tools-text-domain'),
                ['details' => (array) ($erasure_engine_status['details'] ?? [])]
            );
        }

        $meta_keys = [
            defined('LL_TOOLS_USER_WORDSET_META') ? LL_TOOLS_USER_WORDSET_META : 'll_user_study_wordset',
            defined('LL_TOOLS_USER_CATEGORY_META') ? LL_TOOLS_USER_CATEGORY_META : 'll_user_study_categories',
            defined('LL_TOOLS_USER_STARRED_META') ? LL_TOOLS_USER_STARRED_META : 'll_user_study_starred',
            'll_user_star_mode',
            defined('LL_TOOLS_USER_FAST_TRANSITIONS_META') ? LL_TOOLS_USER_FAST_TRANSITIONS_META : 'll_user_fast_transitions',
            defined('LL_TOOLS_USER_GOALS_META') ? LL_TOOLS_USER_GOALS_META : 'll_user_study_goals',
            defined('LL_TOOLS_USER_CATEGORY_PROGRESS_META') ? LL_TOOLS_USER_CATEGORY_PROGRESS_META : 'll_user_study_category_progress',
            defined('LL_TOOLS_USER_PROMPT_CARD_PROGRESS_META') ? LL_TOOLS_USER_PROMPT_CARD_PROGRESS_META : 'll_user_study_prompt_card_progress',
            defined('LL_TOOLS_USER_RECOMMENDATION_QUEUE_META') ? LL_TOOLS_USER_RECOMMENDATION_QUEUE_META : 'll_user_study_recommendation_queue',
            defined('LL_TOOLS_USER_LAST_RECOMMENDATION_META') ? LL_TOOLS_USER_LAST_RECOMMENDATION_META : 'll_user_study_last_recommendation',
            defined('LL_TOOLS_USER_RECOMMENDATION_DISMISSED_META') ? LL_TOOLS_USER_RECOMMENDATION_DISMISSED_META : 'll_user_study_recommendation_dismissed',
            defined('LL_TOOLS_USER_RECOMMENDATION_DEFERRALS_META') ? LL_TOOLS_USER_RECOMMENDATION_DEFERRALS_META : 'll_user_study_recommendation_deferrals',
            defined('LL_TOOLS_USER_CONTENT_LESSON_COMPLETION_META') ? LL_TOOLS_USER_CONTENT_LESSON_COMPLETION_META : 'll_tools_completed_content_lessons',
            'tt_completed_lessons',
        ];
        $meta_keys = array_values(array_unique(array_filter(array_map('strval', $meta_keys))));

        $session_lock = ll_tools_offline_app_acquire_user_session_lock($user_id);
        if ($session_lock === '') {
            return new WP_Error(
                'll_tools_privacy_mutation_lock_unavailable',
                __('LL Tools personal data could not be erased safely.', 'll-tools-text-domain')
            );
        }

        $transaction = null;
        $class_ids = [];
        $previous_suppress_errors = $wpdb->suppress_errors(true);
        $clear_caches = static function () use ($user_id, &$class_ids): void {
            if (function_exists('ll_tools_user_progress_clear_user_cache')) {
                ll_tools_user_progress_clear_user_cache($user_id);
            } else {
                wp_cache_delete($user_id, 'user_meta');
                clean_user_cache($user_id);
            }
            foreach (array_values(array_unique(array_map('intval', $class_ids))) as $class_id) {
                if ($class_id > 0) {
                    clean_post_cache($class_id);
                }
            }
        };
        $rollback = static function (WP_Error $error) use (&$transaction, $user_id, $clear_caches): WP_Error {
            if (is_array($transaction)) {
                ll_tools_user_progress_rollback_event_transaction($transaction, $user_id);
                $transaction = null;
            }
            $clear_caches();
            return $error;
        };

        try {
            $transaction = ll_tools_user_progress_begin_event_transaction();
            if (!is_array($transaction)) {
                return new WP_Error(
                    'll_tools_privacy_transaction_start_failed',
                    __('LL Tools personal data could not be erased safely.', 'll-tools-text-domain')
                );
            }
            if (!ll_tools_user_progress_lock_user_for_event($user_id)) {
                $wpdb->last_error = '';
                $existing_user_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT ID FROM {$wpdb->users} WHERE ID = %d",
                    $user_id
                ));
                if (
                    !$allow_missing_user
                    || $existing_user_id !== null
                    || (string) $wpdb->last_error !== ''
                ) {
                    return $rollback(new WP_Error(
                        'll_tools_privacy_user_lock_failed',
                        __('LL Tools personal data could not be erased safely.', 'll-tools-text-domain')
                    ));
                }
            }

            $removed = 0;
            $session_erasure = ll_tools_offline_app_privacy_erase_user_sessions_locked($user_id);
            if (is_wp_error($session_erasure)) {
                return $rollback($session_erasure);
            }
            $removed += max(0, (int) ($session_erasure['removed'] ?? 0));

            $class_erasure = ll_tools_teacher_class_unlink_student_verified($user_id);
            if (is_wp_error($class_erasure)) {
                $error_data = $class_erasure->get_error_data();
                if (is_array($error_data) && !empty($error_data['class_ids'])) {
                    $class_ids = array_values(array_map('intval', (array) $error_data['class_ids']));
                }
                return $rollback($class_erasure);
            }
            $class_ids = array_values(array_map('intval', (array) ($class_erasure['class_ids'] ?? [])));
            $removed += max(0, (int) ($class_erasure['removed'] ?? 0));

            $tables = ll_tools_user_progress_table_names();
            foreach (['words', 'events'] as $table_key) {
                $table = (string) ($tables[$table_key] ?? '');
                if ($table === '') {
                    return $rollback(new WP_Error(
                        'll_tools_privacy_progress_table_unavailable',
                        __('LL Tools personal data could not be erased safely.', 'll-tools-text-domain')
                    ));
                }

                $wpdb->last_error = '';
                $before_count = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE user_id = %d",
                    $user_id
                ));
                if (!is_numeric($before_count) || (string) $wpdb->last_error !== '') {
                    return $rollback(new WP_Error(
                        'll_tools_privacy_progress_read_failed',
                        __('LL Tools personal data could not be erased safely.', 'll-tools-text-domain')
                    ));
                }

                $wpdb->last_error = '';
                $deleted = $wpdb->delete($table, ['user_id' => $user_id], ['%d']);
                if ($deleted === false || (string) $wpdb->last_error !== '') {
                    return $rollback(new WP_Error(
                        'll_tools_privacy_progress_delete_failed',
                        __('LL Tools personal data could not be erased safely.', 'll-tools-text-domain')
                    ));
                }

                $wpdb->last_error = '';
                $remaining_count = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE user_id = %d",
                    $user_id
                ));
                if (
                    !is_numeric($remaining_count)
                    || (string) $wpdb->last_error !== ''
                    || (int) $remaining_count !== 0
                ) {
                    return $rollback(new WP_Error(
                        'll_tools_privacy_progress_retained',
                        __('LL Tools personal data could not be erased safely.', 'll-tools-text-domain')
                    ));
                }
                $removed += max(0, (int) $before_count);
            }

            foreach ($meta_keys as $meta_key) {
                if (!metadata_exists('user', $user_id, $meta_key)) {
                    continue;
                }
                delete_user_meta($user_id, $meta_key);
                wp_cache_delete($user_id, 'user_meta');
                if (metadata_exists('user', $user_id, $meta_key)) {
                    return $rollback(new WP_Error(
                        'll_tools_privacy_user_meta_retained',
                        __('LL Tools personal data could not be erased safely.', 'll-tools-text-domain')
                    ));
                }
                $removed++;
            }

            if (!ll_tools_user_progress_commit_event_transaction($transaction)) {
                return $rollback(new WP_Error(
                    'll_tools_privacy_transaction_commit_failed',
                    __('LL Tools personal data could not be erased safely.', 'll-tools-text-domain')
                ));
            }
            $transaction = null;
            $clear_caches();

            return ['removed' => $removed];
        } finally {
            if (is_array($transaction)) {
                ll_tools_user_progress_rollback_event_transaction($transaction, $user_id);
                $transaction = null;
                $clear_caches();
            }
            $wpdb->suppress_errors($previous_suppress_errors);
            ll_tools_offline_app_release_user_session_lock($session_lock);
        }
    }
}

if (!function_exists('ll_tools_privacy_delete_user_personal_data')) {
    /** Preserve the existing boolean helper contract for direct callers. */
    function ll_tools_privacy_delete_user_personal_data(int $user_id): bool {
        $result = ll_tools_privacy_delete_user_personal_data_verified($user_id);
        return !is_wp_error($result) && (int) ($result['removed'] ?? 0) > 0;
    }
}

if (!function_exists('ll_tools_privacy_register_erasers')) {
    function ll_tools_privacy_register_erasers(array $erasers): array {
        $erasers['ll-tools-progress'] = [
            'eraser_friendly_name' => __('LL Tools Study Data', 'll-tools-text-domain'),
            'callback' => 'll_tools_privacy_erase_personal_data',
        ];

        return $erasers;
    }
}
add_filter('wp_privacy_personal_data_erasers', 'll_tools_privacy_register_erasers');

if (!function_exists('ll_tools_privacy_erase_personal_data')) {
    function ll_tools_privacy_erase_personal_data(string $email_address, int $page = 1) {
        global $wpdb;

        $user = ll_tools_privacy_get_user_by_email($email_address);
        if (!($user instanceof WP_User)) {
            return [
                'items_removed' => false,
                'items_retained' => false,
                'messages' => [],
                'done' => true,
            ];
        }

        $user_id = (int) $user->ID;
        $erasure_job_token = ll_tools_privacy_user_lms_erasure_job_token($user_id, $email_address);
        $erasure_call_token = ll_tools_privacy_begin_user_lms_erasure($user_id, $erasure_job_token);
        if (!is_string($erasure_call_token) || $erasure_call_token === '') {
            return new WP_Error(
                'lms_privacy_erasure_fence_failed',
                __('LL Tools data could not be fenced for erasure because another pass is active or storage is temporarily unavailable.', 'll-tools-text-domain')
            );
        }
        $removed = false;
        $messages = [];
        $abort_erasure = static function (WP_Error $error) use ($user_id, $erasure_call_token): WP_Error {
            if (ll_tools_privacy_finish_user_lms_erasure($user_id, $erasure_call_token)) {
                return $error;
            }
            return new WP_Error(
                'lms_privacy_erasure_fence_release_failed',
                __('LL Tools data erasure stopped and the account write fence could not be released. The fence will expire automatically.', 'll-tools-text-domain'),
                ['cause' => $error->get_error_code()]
            );
        };

        // Remove provider mappings and delivery rows before deleting the local
        // selected grade they reference. No outbound callback runs here.
        if (function_exists('ll_tools_grade_delivery_erase_user_data')) {
            $delivery_status = function_exists('ll_tools_grade_delivery_runtime_schema_status')
                ? ll_tools_grade_delivery_runtime_schema_status()
                : ['ready' => false];
            if (empty($delivery_status['ready'])) {
                return $abort_erasure(new WP_Error(
                    'grade_delivery_privacy_erasure_unavailable',
                    __('External grade delivery data is temporarily unavailable for erasure. No external system was changed.', 'll-tools-text-domain')
                ));
            }
            $delivery_erasure = ll_tools_grade_delivery_erase_user_data($user_id);
            if (is_wp_error($delivery_erasure)) {
                return $abort_erasure($delivery_erasure);
            }
            $removed = $removed || (int) ($delivery_erasure['removed'] ?? 0) > 0;
            if (empty($delivery_erasure['done'])) {
                if (!ll_tools_privacy_pause_user_lms_erasure($user_id, $erasure_call_token)) {
                    return new WP_Error(
                        'lms_privacy_erasure_fence_pause_failed',
                        __('LL Tools data erasure paused, but its account write fence could not be renewed.', 'll-tools-text-domain')
                    );
                }
                return [
                    'items_removed' => $removed,
                    'items_retained' => true,
                    'messages' => [__('Additional local external-grade records remain for the next bounded erasure pass. No external system was changed.', 'll-tools-text-domain')],
                    'done' => false,
                ];
            }
        }

        if (function_exists('ll_tools_lms_assignment_erase_user_data')) {
            if (!function_exists('ll_tools_lms_assignment_schema_is_available') || !ll_tools_lms_assignment_schema_is_available()) {
                return $abort_erasure(new WP_Error(
                    'assignment_privacy_erasure_unavailable',
                    __('Assignment attempt data is temporarily unavailable for erasure.', 'll-tools-text-domain')
                ));
            }
            $assignment_erasure = ll_tools_lms_assignment_erase_user_data($user_id);
            if (is_wp_error($assignment_erasure)) {
                return $abort_erasure($assignment_erasure);
            }
            $removed = $removed || (int) ($assignment_erasure['removed'] ?? 0) > 0;
            if (empty($assignment_erasure['done'])) {
                if (!ll_tools_privacy_pause_user_lms_erasure($user_id, $erasure_call_token)) {
                    return new WP_Error(
                        'lms_privacy_erasure_fence_pause_failed',
                        __('LL Tools data erasure paused, but its account write fence could not be renewed.', 'll-tools-text-domain')
                    );
                }
                return [
                    'items_removed' => $removed,
                    'items_retained' => true,
                    'messages' => [__('Additional assignment attempt records remain for the next bounded erasure pass.', 'll-tools-text-domain')],
                    'done' => false,
                ];
            }
        }

        if (function_exists('ll_tools_google_classroom_erase_connection_for_user')) {
            if (!function_exists('ll_tools_google_classroom_schema_is_ready') || !ll_tools_google_classroom_schema_is_ready()) {
                return $abort_erasure(new WP_Error(
                    'google_classroom_privacy_erasure_unavailable',
                    __('Google Classroom connection data is temporarily unavailable for erasure. No request was sent to Google.', 'll-tools-text-domain')
                ));
            }
            $connections = function_exists('ll_tools_google_classroom_connection_summary_for_user')
                ? ll_tools_google_classroom_connection_summary_for_user($user_id)
                : [];
            $states_table = ll_tools_google_classroom_table_names()['oauth_states'];
            $wpdb->last_error = '';
            $state_count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$states_table} WHERE teacher_user_id = %d",
                $user_id
            ));
            if ((string) $wpdb->last_error !== '') {
                return $abort_erasure(new WP_Error(
                    'google_classroom_privacy_erasure_failed',
                    __('The local Google Classroom connection could not be erased. No request was sent to Google.', 'll-tools-text-domain')
                ));
            }
            if (!ll_tools_google_classroom_erase_connection_for_user($user_id)) {
                return $abort_erasure(new WP_Error(
                    'google_classroom_privacy_erasure_failed',
                    __('The local Google Classroom connection could not be erased. No request was sent to Google.', 'll-tools-text-domain')
                ));
            }
            $removed = $removed || $connections !== [] || $state_count > 0;
            if ($connections !== [] || $state_count > 0) {
                $messages[] = __('Local Google Classroom credentials were erased. Privacy erasure did not change grades or revoke access in Google; the user can separately revoke the application in their Google Account.', 'll-tools-text-domain');
            }
        }

        $personal_erasure = ll_tools_privacy_delete_user_personal_data_verified($user_id);
        if (is_wp_error($personal_erasure)) {
            return $abort_erasure($personal_erasure);
        }
        $removed = $removed || (int) ($personal_erasure['removed'] ?? 0) > 0;
        if (!ll_tools_privacy_finish_user_lms_erasure($user_id, $erasure_call_token)) {
            return new WP_Error(
                'lms_privacy_erasure_fence_release_failed',
                __('LL Tools data was erased, but the account write fence could not be released.', 'll-tools-text-domain')
            );
        }

        return [
            'items_removed' => $removed,
            'items_retained' => false,
            'messages' => $messages,
            'done' => true,
        ];
    }
}

if (!defined('LL_TOOLS_LMS_DELETED_USER_CLEANUP_HOOK')) {
    define('LL_TOOLS_LMS_DELETED_USER_CLEANUP_HOOK', 'll_tools_lms_deleted_user_cleanup');
}
if (!defined('LL_TOOLS_LMS_DELETED_USER_CLEANUP_RESUME_HOOK')) {
    define('LL_TOOLS_LMS_DELETED_USER_CLEANUP_RESUME_HOOK', 'll_tools_lms_deleted_user_cleanup_resume');
}
if (!defined('LL_TOOLS_LMS_DELETED_USER_CLEANUP_OPTION_PREFIX')) {
    define('LL_TOOLS_LMS_DELETED_USER_CLEANUP_OPTION_PREFIX', 'll_tools_lms_deleted_user_cleanup_');
}
if (!defined('LL_TOOLS_LMS_DELETED_USER_CLEANUP_RESUME_TRANSIENT')) {
    define('LL_TOOLS_LMS_DELETED_USER_CLEANUP_RESUME_TRANSIENT', 'll_tools_lms_deleted_user_cleanup_resume_guard');
}
if (!defined('LL_TOOLS_LMS_PRIVACY_ERASURE_OPTION_PREFIX')) {
    define('LL_TOOLS_LMS_PRIVACY_ERASURE_OPTION_PREFIX', 'll_tools_lms_privacy_erasure_');
}
if (!defined('LL_TOOLS_LMS_PRIVACY_ERASURE_FENCE_TTL')) {
    define('LL_TOOLS_LMS_PRIVACY_ERASURE_FENCE_TTL', 30 * MINUTE_IN_SECONDS);
}
if (!defined('LL_TOOLS_LMS_PRIVACY_ERASURE_CALL_TTL')) {
    define('LL_TOOLS_LMS_PRIVACY_ERASURE_CALL_TTL', 5 * MINUTE_IN_SECONDS);
}

function ll_tools_privacy_deleted_user_lms_cleanup_option_name(int $user_id): string {
    return LL_TOOLS_LMS_DELETED_USER_CLEANUP_OPTION_PREFIX . max(0, $user_id);
}

function ll_tools_privacy_user_lms_erasure_option_name(int $user_id): string {
    return LL_TOOLS_LMS_PRIVACY_ERASURE_OPTION_PREFIX . max(0, $user_id);
}

/** @return array{exists:bool,raw:string,value:mixed}|WP_Error */
function ll_tools_privacy_deleted_user_lms_cleanup_row(int $user_id) {
    global $wpdb;

    if ($user_id <= 0) {
        return new WP_Error('lms_deleted_user_cleanup_tombstone_read_failed');
    }
    $wpdb->last_error = '';
    $raw = $wpdb->get_var($wpdb->prepare(
        "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
        ll_tools_privacy_deleted_user_lms_cleanup_option_name($user_id)
    ));
    if ((string) $wpdb->last_error !== '') {
        return new WP_Error('lms_deleted_user_cleanup_tombstone_read_failed');
    }
    if ($raw === null) {
        return ['exists' => false, 'raw' => '', 'value' => false];
    }
    return [
        'exists' => true,
        'raw' => (string) $raw,
        'value' => maybe_unserialize($raw),
    ];
}

function ll_tools_privacy_deleted_user_lms_cleanup_cache_forget(int $user_id): void {
    wp_cache_delete(ll_tools_privacy_deleted_user_lms_cleanup_option_name($user_id), 'options');
    $notoptions = wp_cache_get('notoptions', 'options');
    $option_name = ll_tools_privacy_deleted_user_lms_cleanup_option_name($user_id);
    if (is_array($notoptions) && isset($notoptions[$option_name])) {
        unset($notoptions[$option_name]);
        wp_cache_set('notoptions', $notoptions, 'options');
    }
}

function ll_tools_privacy_user_lms_erasure_fence_ttl(int $user_id): int {
    return max(
        5 * MINUTE_IN_SECONDS,
        min(DAY_IN_SECONDS, (int) apply_filters(
            'll_tools_lms_privacy_erasure_fence_ttl',
            LL_TOOLS_LMS_PRIVACY_ERASURE_FENCE_TTL,
            $user_id
        ))
    );
}

function ll_tools_privacy_user_lms_erasure_call_ttl(int $user_id): int {
    return max(
        MINUTE_IN_SECONDS,
        min(15 * MINUTE_IN_SECONDS, (int) apply_filters(
            'll_tools_lms_privacy_erasure_call_ttl',
            LL_TOOLS_LMS_PRIVACY_ERASURE_CALL_TTL,
            $user_id
        ))
    );
}

/**
 * Read the exact raw option value used for compare-and-swap ownership.
 *
 * @return array{exists:bool,raw:string,value:mixed}|WP_Error
 */
function ll_tools_privacy_user_lms_erasure_row(int $user_id) {
    global $wpdb;

    $option_name = ll_tools_privacy_user_lms_erasure_option_name($user_id);
    $wpdb->last_error = '';
    $raw = $wpdb->get_var($wpdb->prepare(
        "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
        $option_name
    ));
    if ((string) $wpdb->last_error !== '') {
        return new WP_Error('lms_privacy_erasure_fence_read_failed');
    }
    if ($raw === null) {
        return ['exists' => false, 'raw' => '', 'value' => false];
    }
    return [
        'exists' => true,
        'raw' => (string) $raw,
        'value' => maybe_unserialize($raw),
    ];
}

function ll_tools_privacy_user_lms_erasure_cache_forget(int $user_id): void {
    wp_cache_delete(ll_tools_privacy_user_lms_erasure_option_name($user_id), 'options');
}

/** Exact raw-value compare-and-swap for the non-autoloaded erasure fence. */
function ll_tools_privacy_user_lms_erasure_cas(int $user_id, string $expected_raw, array $next): bool {
    global $wpdb;

    $wpdb->last_error = '';
    $updated = $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
        maybe_serialize($next),
        ll_tools_privacy_user_lms_erasure_option_name($user_id),
        $expected_raw
    ));
    ll_tools_privacy_user_lms_erasure_cache_forget($user_id);
    return $updated === 1 && (string) $wpdb->last_error === '';
}

/** Exact call-owner deletion; another request's renewed fence is untouched. */
function ll_tools_privacy_user_lms_erasure_delete_owned(int $user_id, string $call_token): bool {
    global $wpdb;

    $row = ll_tools_privacy_user_lms_erasure_row($user_id);
    if (is_wp_error($row) || empty($row['exists']) || !is_array($row['value'])) {
        return false;
    }
    if (!hash_equals((string) ($row['value']['call_hash'] ?? ''), hash('sha256', $call_token))) {
        return false;
    }
    $wpdb->last_error = '';
    $deleted = $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
        ll_tools_privacy_user_lms_erasure_option_name($user_id),
        (string) $row['raw']
    ));
    ll_tools_privacy_user_lms_erasure_cache_forget($user_id);
    return $deleted === 1 && (string) $wpdb->last_error === '';
}

/**
 * Resolve one stable WordPress privacy-request job identity. Direct test/CLI
 * calls share a request-local fallback and may provide an explicit filtered
 * token when they need continuation across requests.
 */
function ll_tools_privacy_user_lms_erasure_job_token(int $user_id, string $email_address): string {
    $request_id = isset($_POST['id']) ? absint(wp_unslash($_POST['id'])) : 0;
    if ($request_id > 0 && function_exists('wp_get_user_request')) {
        $request = wp_get_user_request($request_id);
        if (
            $request
            && (string) ($request->action_name ?? '') === 'remove_personal_data'
            && strcasecmp((string) ($request->email ?? ''), $email_address) === 0
        ) {
            return 'wp-privacy:' . get_current_blog_id() . ':' . $request_id;
        }
    }

    $filtered = (string) apply_filters('ll_tools_lms_privacy_erasure_job_token', '', $user_id, $email_address);
    if ($filtered !== '' && strlen($filtered) <= 256) {
        return $filtered;
    }

    static $request_tokens = [];
    $key = $user_id . '|' . strtolower($email_address);
    if (!isset($request_tokens[$key])) {
        $request_tokens[$key] = wp_generate_uuid4();
    }
    return (string) $request_tokens[$key];
}

/**
 * Acquire one owner-fenced erasure call lease.
 *
 * @return string|false Opaque call token on success.
 */
function ll_tools_privacy_begin_user_lms_erasure(int $user_id, string $job_token = '') {
    if ($user_id <= 0) {
        return false;
    }
    $job_token = $job_token !== '' ? $job_token : 'direct:' . wp_generate_uuid4();
    $job_hash = hash('sha256', $job_token);

    for ($attempt = 0; $attempt < 3; $attempt++) {
        $now = time();
        $call_token = wp_generate_uuid4();
        $next = [
            'schema' => 1,
            'job_hash' => $job_hash,
            'call_hash' => hash('sha256', $call_token),
            'state' => 'active',
            'fence_expires_at' => $now + ll_tools_privacy_user_lms_erasure_fence_ttl($user_id),
            'call_expires_at' => $now + ll_tools_privacy_user_lms_erasure_call_ttl($user_id),
            'updated_at' => $now,
        ];
        $row = ll_tools_privacy_user_lms_erasure_row($user_id);
        if (is_wp_error($row)) {
            return false;
        }
        if (empty($row['exists'])) {
            if (add_option(ll_tools_privacy_user_lms_erasure_option_name($user_id), $next, '', false)) {
                ll_tools_privacy_user_lms_erasure_cache_forget($user_id);
                $readback = ll_tools_privacy_user_lms_erasure_row($user_id);
                if (
                    !is_wp_error($readback)
                    && is_array($readback['value'] ?? null)
                    && hash_equals($next['call_hash'], (string) ($readback['value']['call_hash'] ?? ''))
                ) {
                    return $call_token;
                }
                return false;
            }
            continue;
        }

        $current = $row['value'];
        if (!is_array($current)) {
            $legacy_started_at = (int) $current;
            if ($legacy_started_at > 0 && $legacy_started_at >= $now - ll_tools_privacy_user_lms_erasure_fence_ttl($user_id)) {
                return false;
            }
        } else {
            $fence_active = (int) ($current['fence_expires_at'] ?? 0) >= $now;
            $call_active = (string) ($current['state'] ?? '') === 'active'
                && (int) ($current['call_expires_at'] ?? 0) >= $now;
            $same_job = hash_equals((string) ($current['job_hash'] ?? ''), $job_hash);
            if ($fence_active && (!$same_job || $call_active)) {
                return false;
            }
        }

        if (ll_tools_privacy_user_lms_erasure_cas($user_id, (string) $row['raw'], $next)) {
            return $call_token;
        }
    }
    return false;
}

/** Retain the write fence between bounded pages while releasing this call. */
function ll_tools_privacy_pause_user_lms_erasure(int $user_id, string $call_token): bool {
    $row = ll_tools_privacy_user_lms_erasure_row($user_id);
    if (is_wp_error($row) || empty($row['exists']) || !is_array($row['value'])) {
        return false;
    }
    $current = $row['value'];
    if (!hash_equals((string) ($current['call_hash'] ?? ''), hash('sha256', $call_token))) {
        return false;
    }
    $now = time();
    $current['call_hash'] = '';
    $current['state'] = 'idle';
    $current['call_expires_at'] = 0;
    $current['fence_expires_at'] = $now + ll_tools_privacy_user_lms_erasure_fence_ttl($user_id);
    $current['updated_at'] = $now;
    return ll_tools_privacy_user_lms_erasure_cas($user_id, (string) $row['raw'], $current);
}

function ll_tools_privacy_finish_user_lms_erasure(int $user_id, string $call_token): bool {
    if ($user_id <= 0 || $call_token === '') {
        return false;
    }
    return ll_tools_privacy_user_lms_erasure_delete_owned($user_id, $call_token);
}

/** Account deletion owns the stronger tombstone and may clear an abandoned manual fence. */
function ll_tools_privacy_force_finish_user_lms_erasure(int $user_id): bool {
    global $wpdb;

    if ($user_id <= 0) {
        return false;
    }
    $row = ll_tools_privacy_user_lms_erasure_row($user_id);
    if (is_wp_error($row)) {
        return false;
    }
    if (empty($row['exists'])) {
        return true;
    }
    $wpdb->last_error = '';
    $deleted = $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
        ll_tools_privacy_user_lms_erasure_option_name($user_id),
        (string) $row['raw']
    ));
    ll_tools_privacy_user_lms_erasure_cache_forget($user_id);
    return $deleted === 1 && (string) $wpdb->last_error === '';
}

/**
 * Manual WordPress erasure runs are multi-request jobs. An active structured
 * fence blocks writes; legacy timestamp rows remain honored until their bound.
 */
function ll_tools_privacy_user_lms_erasure_is_active(int $user_id): bool {
    if ($user_id <= 0) {
        return false;
    }
    $row = ll_tools_privacy_user_lms_erasure_row($user_id);
    if (is_wp_error($row)) {
        return true;
    }
    if (empty($row['exists'])) {
        return false;
    }
    if (is_array($row['value'])) {
        return (int) ($row['value']['fence_expires_at'] ?? 0) >= time();
    }
    $started_at = (int) $row['value'];
    return $started_at > 0
        && $started_at >= time() - ll_tools_privacy_user_lms_erasure_fence_ttl($user_id);
}

/** A durable deletion fence consulted by every late LMS credential/grade write. */
function ll_tools_privacy_user_lms_deletion_is_pending(int $user_id): bool {
    if ($user_id <= 0) {
        return true;
    }
    $tombstone = ll_tools_privacy_deleted_user_lms_cleanup_row($user_id);
    return is_wp_error($tombstone)
        || !empty($tombstone['exists'])
        || ll_tools_privacy_user_lms_erasure_is_active($user_id);
}

/**
 * Read one bounded keyset page of per-user tombstones.
 *
 * @return array<int,array{option_id:int,user_id:int,queued_at:int}>
 */
function ll_tools_privacy_deleted_user_lms_cleanup_rows(int $after_option_id = 0, int $limit = 50): array {
    global $wpdb;

    $after_option_id = max(0, $after_option_id);
    $limit = max(1, min(100, $limit));
    $prefix = LL_TOOLS_LMS_DELETED_USER_CLEANUP_OPTION_PREFIX;
    $wpdb->last_error = '';
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT option_id, option_name, option_value
         FROM {$wpdb->options}
         WHERE option_id > %d AND option_name LIKE %s
         ORDER BY option_id ASC
         LIMIT %d",
        $after_option_id,
        $wpdb->esc_like($prefix) . '%',
        $limit
    ), ARRAY_A);
    if (!is_array($rows) || (string) $wpdb->last_error !== '') {
        return [];
    }

    $result = [];
    foreach ($rows as $row) {
        $option_name = (string) ($row['option_name'] ?? '');
        $suffix = substr($option_name, strlen($prefix));
        if ($suffix === '' || preg_match('/^[1-9][0-9]*$/D', $suffix) !== 1) {
            continue;
        }
        $user_id = (int) $suffix;
        $payload = maybe_unserialize($row['option_value'] ?? 0);
        $queued_at = is_array($payload)
            ? (int) ($payload['queued_at'] ?? 0)
            : (int) $payload;
        $result[] = [
            'option_id' => max(0, (int) ($row['option_id'] ?? 0)),
            'user_id' => $user_id,
            'queued_at' => max(1, $queued_at),
        ];
    }
    return $result;
}

/** @return array<int,int> bounded user ID => first queued timestamp */
function ll_tools_privacy_deleted_user_lms_cleanup_queue(): array {
    $queue = [];
    foreach (ll_tools_privacy_deleted_user_lms_cleanup_rows(0, 100) as $row) {
        $queue[(int) $row['user_id']] = (int) $row['queued_at'];
    }
    ksort($queue, SORT_NUMERIC);
    return $queue;
}

function ll_tools_privacy_queue_deleted_user_lms_cleanup(int $user_id): bool {
    if ($user_id <= 0) {
        return false;
    }
    $option_name = ll_tools_privacy_deleted_user_lms_cleanup_option_name($user_id);
    if (add_option($option_name, ['queued_at' => time(), 'post_seen_at' => 0], '', false)) {
        ll_tools_privacy_deleted_user_lms_cleanup_cache_forget($user_id);
        return true;
    }
    $row = ll_tools_privacy_deleted_user_lms_cleanup_row($user_id);
    return !is_wp_error($row) && !empty($row['exists']);
}

function ll_tools_privacy_dequeue_deleted_user_lms_cleanup(int $user_id): bool {
    global $wpdb;

    $option_name = ll_tools_privacy_deleted_user_lms_cleanup_option_name($user_id);
    $row = ll_tools_privacy_deleted_user_lms_cleanup_row($user_id);
    if (is_wp_error($row)) {
        return false;
    }
    if (empty($row['exists'])) {
        return true;
    }
    $wpdb->last_error = '';
    $deleted = $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
        $option_name,
        (string) $row['raw']
    ));
    ll_tools_privacy_deleted_user_lms_cleanup_cache_forget($user_id);
    return $deleted === 1 && (string) $wpdb->last_error === '';
}

/** Reschedule one bounded keyset page after activation or a cron-write failure. */
function ll_tools_privacy_resume_deleted_user_lms_cleanup(int $after_option_id = 0): void {
    $rows = ll_tools_privacy_deleted_user_lms_cleanup_rows($after_option_id, 51);
    $offset = 1;
    $last_option_id = $after_option_id;
    foreach (array_slice($rows, 0, 50) as $row) {
        $user_id = (int) $row['user_id'];
        $last_option_id = max($last_option_id, (int) $row['option_id']);
        if (wp_next_scheduled(LL_TOOLS_LMS_DELETED_USER_CLEANUP_HOOK, [$user_id]) === false) {
            wp_schedule_single_event(time() + $offset, LL_TOOLS_LMS_DELETED_USER_CLEANUP_HOOK, [$user_id]);
        }
        $offset = min(60, $offset + 1);
    }
    if (count($rows) > 50) {
        wp_schedule_single_event(
            time() + MINUTE_IN_SECONDS,
            LL_TOOLS_LMS_DELETED_USER_CLEANUP_RESUME_HOOK,
            [$last_option_id]
        );
    }
}

/** Cheap bounded recovery when an earlier cron-option write failed. */
function ll_tools_privacy_maybe_resume_deleted_user_lms_cleanup(): void {
    if (get_transient(LL_TOOLS_LMS_DELETED_USER_CLEANUP_RESUME_TRANSIENT)) {
        return;
    }
    set_transient(LL_TOOLS_LMS_DELETED_USER_CLEANUP_RESUME_TRANSIENT, 1, 5 * MINUTE_IN_SECONDS);
    ll_tools_privacy_resume_deleted_user_lms_cleanup(0);
}

/** Durable per-site proof that WordPress completed removal from this site. */
function ll_tools_privacy_deleted_user_post_seen(int $user_id, bool $mark = false): bool {
    global $wpdb;

    if ($user_id <= 0) {
        return false;
    }
    $option_name = ll_tools_privacy_deleted_user_lms_cleanup_option_name($user_id);
    if ($mark) {
        if (!ll_tools_privacy_queue_deleted_user_lms_cleanup($user_id)) {
            return false;
        }
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $row = ll_tools_privacy_deleted_user_lms_cleanup_row($user_id);
            if (is_wp_error($row) || empty($row['exists'])) {
                return false;
            }
            $current = $row['value'];
            if (is_array($current) && (int) ($current['post_seen_at'] ?? 0) > 0) {
                break;
            }
            $queued_at = is_array($current)
                ? max(1, (int) ($current['queued_at'] ?? 0))
                : max(1, (int) $current);
            $payload = [
                'queued_at' => $queued_at,
                'post_seen_at' => time(),
            ];
            $wpdb->last_error = '';
            $updated = $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
                maybe_serialize($payload),
                $option_name,
                (string) $row['raw']
            ));
            ll_tools_privacy_deleted_user_lms_cleanup_cache_forget($user_id);
            if ($updated === 1 && (string) $wpdb->last_error === '') {
                break;
            }
        }
    }
    $row = ll_tools_privacy_deleted_user_lms_cleanup_row($user_id);
    return !is_wp_error($row)
        && !empty($row['exists'])
        && is_array($row['value'])
        && (int) ($row['value']['post_seen_at'] ?? 0) > 0;
}

/**
 * A multisite network deletion fires only once outside the per-blog loop.
 * Fence and schedule every site while membership is still discoverable; each
 * site's own bounded worker later erases only tables under that site prefix.
 */
function ll_tools_privacy_prepare_network_deleted_user_lms_cleanup(int $user_id): void {
    if ($user_id <= 0 || !is_multisite() || !function_exists('get_blogs_of_user')) {
        return;
    }

    $blog_ids = array_map('intval', array_keys((array) get_blogs_of_user($user_id, true)));
    $current_blog_id = get_current_blog_id();
    if ($current_blog_id > 0) {
        $blog_ids[] = $current_blog_id;
    }
    $blog_ids = array_values(array_unique(array_filter($blog_ids, static fn(int $blog_id): bool => $blog_id > 0)));

    foreach ($blog_ids as $blog_id) {
        $switched = $blog_id !== get_current_blog_id();
        if ($switched && !switch_to_blog($blog_id)) {
            continue;
        }
        try {
            if (!ll_tools_privacy_queue_deleted_user_lms_cleanup($user_id)) {
                continue;
            }
            // Establish the site-local writer barrier while membership and the
            // user row are still available. The durable worker keeps retrying
            // if a previously admitted writer outlives this bounded attempt.
            ll_tools_privacy_cleanup_deleted_user_lms_data($user_id);
        } finally {
            if ($switched) {
                restore_current_blog();
            }
        }
    }
}

/** Mark the post-delete boundary before attempting the final bounded pass. */
function ll_tools_privacy_cleanup_after_deleted_user(int $user_id): void {
    ll_tools_privacy_deleted_user_post_seen($user_id, true);
    ll_tools_privacy_cleanup_deleted_user_lms_data($user_id);
}

/** Establish the durable tombstone before WordPress begins account removal. */
function ll_tools_privacy_prepare_deleted_user_lms_cleanup(int $user_id): void {
    if (!ll_tools_privacy_queue_deleted_user_lms_cleanup($user_id)) {
        return;
    }
    ll_tools_privacy_cleanup_deleted_user_lms_data($user_id);
}

/**
 * Establish site-local cleanup before WordPress removes a multisite member.
 *
 * Full account deletion has already created the same site's tombstone through
 * delete_user or wpmu_delete_user. Avoid consuming another bounded cleanup
 * pass when remove_user_from_blog is nested inside either of those flows.
 */
function ll_tools_privacy_prepare_removed_user_lms_cleanup(int $user_id, int $blog_id): void {
    if (
        $user_id <= 0
        || $blog_id <= 0
        || !is_multisite()
        || get_current_blog_id() !== $blog_id
    ) {
        return;
    }

    $tombstone = ll_tools_privacy_deleted_user_lms_cleanup_row($user_id);
    if (!is_wp_error($tombstone) && !empty($tombstone['exists'])) {
        return;
    }

    ll_tools_privacy_prepare_deleted_user_lms_cleanup($user_id);
}

/** Fail-closed proof that a multisite account no longer belongs to this site. */
function ll_tools_privacy_user_removed_from_current_site(int $user_id): bool {
    global $wpdb;

    if ($user_id <= 0) {
        return false;
    }
    if (!(get_userdata($user_id) instanceof WP_User)) {
        return true;
    }
    if (!is_multisite()) {
        return false;
    }

    $capabilities_key = $wpdb->get_blog_prefix() . 'capabilities';
    $wpdb->last_error = '';
    $membership_row = $wpdb->get_var($wpdb->prepare(
        "SELECT umeta_id FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s LIMIT 1",
        $user_id,
        $capabilities_key
    ));
    if ((string) $wpdb->last_error !== '') {
        return false;
    }
    return $membership_row === null;
}

/**
 * Continue bounded local-only cleanup for an account that is being deleted.
 *
 * Delivery mappings are removed before their authoritative grades/attempts;
 * Google credentials are removed last. No provider callback or HTTP request is
 * made. A durable one-minute continuation handles accounts larger than one
 * request budget.
 */
function ll_tools_privacy_cleanup_deleted_user_lms_data(int $user_id): void {
    if ($user_id <= 0) {
        return;
    }
    // Cron is only a continuation consumer. It must never recreate a completed
    // tombstone if a stale/in-flight event arrives after successful dequeue.
    $tombstone = ll_tools_privacy_deleted_user_lms_cleanup_row($user_id);
    if (is_wp_error($tombstone)) {
        if (wp_next_scheduled(LL_TOOLS_LMS_DELETED_USER_CLEANUP_HOOK, [$user_id]) === false) {
            wp_schedule_single_event(time() + MINUTE_IN_SECONDS, LL_TOOLS_LMS_DELETED_USER_CLEANUP_HOOK, [$user_id]);
        }
        return;
    }
    if (empty($tombstone['exists'])) {
        return;
    }

    // The durable tombstone is installed before account/site deletion begins.
    // Taking the same per-user advisory lock drains any writer admitted before
    // that fence. Post-delete retries may safely omit the vanished user-row
    // lock because the tombstone rejects every new LL Tools writer.
    $local_erasure = function_exists('ll_tools_privacy_delete_user_personal_data_verified')
        ? ll_tools_privacy_delete_user_personal_data_verified($user_id, true)
        : new WP_Error('ll_tools_privacy_erasure_runtime_unavailable');
    if (is_wp_error($local_erasure)) {
        if (wp_next_scheduled(LL_TOOLS_LMS_DELETED_USER_CLEANUP_HOOK, [$user_id]) === false) {
            wp_schedule_single_event(time() + MINUTE_IN_SECONDS, LL_TOOLS_LMS_DELETED_USER_CLEANUP_HOOK, [$user_id]);
        }
        return;
    }

    $passes = max(1, min(100, (int) apply_filters('ll_tools_lms_deleted_user_cleanup_passes', 10, $user_id)));
    $delivery_done = !function_exists('ll_tools_grade_delivery_erase_user_data');
    if (!$delivery_done) {
        for ($pass = 0; $pass < $passes; $pass++) {
            $result = ll_tools_grade_delivery_erase_user_data($user_id);
            if (is_wp_error($result)) {
                break;
            }
            if (!empty($result['done'])) {
                $delivery_done = true;
                break;
            }
        }
    }

    $assignment_done = false;
    if ($delivery_done) {
        $assignment_done = !function_exists('ll_tools_lms_assignment_erase_user_data');
        if (!$assignment_done) {
            for ($pass = 0; $pass < $passes; $pass++) {
                $result = ll_tools_lms_assignment_erase_user_data($user_id);
                if (is_wp_error($result)) {
                    break;
                }
                if (!empty($result['done'])) {
                    $assignment_done = true;
                    break;
                }
            }
        }
    }

    $google_done = false;
    if ($delivery_done && $assignment_done) {
        $google_done = !function_exists('ll_tools_google_classroom_erase_connection_for_user')
            || ll_tools_google_classroom_erase_connection_for_user($user_id);
    }

    $site_scope_removed = ll_tools_privacy_user_removed_from_current_site($user_id)
        || (is_multisite() && ll_tools_privacy_deleted_user_post_seen($user_id));
    if ($delivery_done && $assignment_done && $google_done && $site_scope_removed) {
        // A failed/abandoned manual erasure may have left its own write fence.
        // Do not declare account cleanup complete unless both fence types can
        // be removed; the durable deletion tombstone then keeps retrying.
        if (!ll_tools_privacy_force_finish_user_lms_erasure($user_id)) {
            if (wp_next_scheduled(LL_TOOLS_LMS_DELETED_USER_CLEANUP_HOOK, [$user_id]) === false) {
                wp_schedule_single_event(time() + MINUTE_IN_SECONDS, LL_TOOLS_LMS_DELETED_USER_CLEANUP_HOOK, [$user_id]);
            }
            return;
        }
        if (ll_tools_privacy_dequeue_deleted_user_lms_cleanup($user_id)) {
            wp_clear_scheduled_hook(LL_TOOLS_LMS_DELETED_USER_CLEANUP_HOOK, [$user_id]);
            return;
        }
    }

    if (wp_next_scheduled(LL_TOOLS_LMS_DELETED_USER_CLEANUP_HOOK, [$user_id]) === false) {
        wp_schedule_single_event(time() + MINUTE_IN_SECONDS, LL_TOOLS_LMS_DELETED_USER_CLEANUP_HOOK, [$user_id]);
    }
}
add_action('delete_user', 'll_tools_privacy_prepare_deleted_user_lms_cleanup', 1, 1);
add_action('wpmu_delete_user', 'll_tools_privacy_prepare_network_deleted_user_lms_cleanup', 1, 1);
add_action('remove_user_from_blog', 'll_tools_privacy_prepare_removed_user_lms_cleanup', 1, 2);
add_action('deleted_user', 'll_tools_privacy_cleanup_after_deleted_user', 20, 1);
add_action(LL_TOOLS_LMS_DELETED_USER_CLEANUP_HOOK, 'll_tools_privacy_cleanup_deleted_user_lms_data', 10, 1);
add_action(LL_TOOLS_LMS_DELETED_USER_CLEANUP_RESUME_HOOK, 'll_tools_privacy_resume_deleted_user_lms_cleanup', 10, 1);
add_action('init', 'll_tools_privacy_maybe_resume_deleted_user_lms_cleanup', 15);

if (!function_exists('ll_tools_add_privacy_policy_content')) {
    function ll_tools_add_privacy_policy_content(): void {
        if (!function_exists('wp_add_privacy_policy_content')) {
            return;
        }

        $retention_days = ll_tools_get_user_progress_retention_days();
        $content = '<p class="privacy-policy-tutorial">'
            . esc_html__('Suggested text for sites that use LL Tools learner progress features.', 'll-tools-text-domain')
            . '</p>';
        $content .= '<p><strong>' . esc_html__('Suggested Text:', 'll-tools-text-domain') . '</strong> ';
        $content .= sprintf(
            /* translators: %d: retention days */
            esc_html__('When you use LL Tools while signed in, this site stores your study progress, learning preferences, selected word set/categories, starred words, progress summaries for studied words, formative practice results, and detailed activity history such as quiz exposures and outcomes. If you use the offline sync feature, the site also stores limited offline-session device/profile identifiers needed to keep that sync working. When your teacher uses official LL Tools assignments, the site stores the frozen assignment revision, your attempts and responses, the server-calculated score, and the selected grade. If an LMS connector is configured and explicitly mapped, the site also stores hashed external identity/resource mappings and a delivery audit needed to send or correct that selected grade. A teacher who connects Google Classroom authorizes this site to retain an encrypted refresh credential and limited account details for that connection; access tokens are short lived and are not stored. This information is used to save your progress, personalize next-study recommendations, restore your study state across sessions, sync offline study activity back to your account, let site administrators and assigned teachers review learner work, and deliver an authorized official assignment grade to the configured learning system. Detailed activity log entries are kept for %d days. Summary progress and official assignment data remain until your account is deleted, the connection is removed, or the site erases your LL Tools personal data according to its education-record retention policy. Local privacy erasure does not silently alter or delete a grade already held by an external LMS. You can request an export or erasure of local data through the site’s privacy request tools.', 'll-tools-text-domain'),
            $retention_days
        );
        $content .= '</p>';

        wp_add_privacy_policy_content(
            __('Language Learner Tools', 'll-tools-text-domain'),
            wp_kses_post(wpautop($content, false))
        );
    }
}
add_action('admin_init', 'll_tools_add_privacy_policy_content');
