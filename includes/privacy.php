<?php
if (!defined('WPINC')) { die; }

if (!defined('LL_TOOLS_USER_PROGRESS_RETENTION_OPTION')) {
    define('LL_TOOLS_USER_PROGRESS_RETENTION_OPTION', 'll_user_progress_events_retention_days');
}

if (!defined('LL_TOOLS_USER_PROGRESS_RETENTION_CRON_HOOK')) {
    define('LL_TOOLS_USER_PROGRESS_RETENTION_CRON_HOOK', 'll_tools_user_progress_retention_cleanup');
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
                    <?php esc_html_e('LL Tools treats logged-in study progress as core account functionality. No separate consent checkbox is shown for this core feature. Personal learner analytics should be limited to administrators, documented in the site privacy policy, and handled through WordPress export/erase tools.', 'll-tools-text-domain'); ?>
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
        if (!function_exists('wp_next_scheduled') || !function_exists('wp_unschedule_event')) {
            return;
        }

        $next = wp_next_scheduled(LL_TOOLS_USER_PROGRESS_RETENTION_CRON_HOOK);
        while ($next) {
            wp_unschedule_event($next, LL_TOOLS_USER_PROGRESS_RETENTION_CRON_HOOK);
            $next = wp_next_scheduled(LL_TOOLS_USER_PROGRESS_RETENTION_CRON_HOOK);
        }
    }
}

if (!function_exists('ll_tools_run_user_progress_retention_cleanup')) {
    function ll_tools_run_user_progress_retention_cleanup(): int {
        global $wpdb;

        if (!function_exists('ll_tools_user_progress_table_names')) {
            return 0;
        }

        $retention_days = ll_tools_get_user_progress_retention_days();
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($retention_days * DAY_IN_SECONDS));
        $events_table = ll_tools_user_progress_table_names()['events'];

        $sql = $wpdb->prepare(
            "DELETE FROM {$events_table} WHERE created_at < %s",
            $cutoff
        );

        $deleted = $wpdb->query($sql);
        return is_numeric($deleted) ? max(0, (int) $deleted) : 0;
    }
}

add_action(LL_TOOLS_USER_PROGRESS_RETENTION_CRON_HOOK, 'll_tools_run_user_progress_retention_cleanup');

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
        $exporters['ll-tools-study-events'] = [
            'exporter_friendly_name' => __('LL Tools Study Activity', 'll-tools-text-domain'),
            'callback' => 'll_tools_privacy_export_study_event_rows',
        ];
        $exporters['ll-tools-offline-sessions'] = [
            'exporter_friendly_name' => __('LL Tools Offline Sessions', 'll-tools-text-domain'),
            'callback' => 'll_tools_privacy_export_offline_sessions',
        ];

        return $exporters;
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
                    ll_tools_privacy_export_data_pair(__('Star mode', 'll-tools-text-domain'), (string) ($study_state['star_mode'] ?? 'normal')),
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

if (!function_exists('ll_tools_privacy_delete_user_personal_data')) {
    function ll_tools_privacy_delete_user_personal_data(int $user_id): bool {
        global $wpdb;

        if ($user_id <= 0) {
            return false;
        }

        $removed = false;
        $tables = function_exists('ll_tools_user_progress_table_names')
            ? ll_tools_user_progress_table_names()
            : [];

        if (!empty($tables['words'])) {
            $deleted_words = $wpdb->delete($tables['words'], ['user_id' => $user_id], ['%d']);
            $removed = $removed || (!empty($deleted_words));
        }

        if (!empty($tables['events'])) {
            $deleted_events = $wpdb->delete($tables['events'], ['user_id' => $user_id], ['%d']);
            $removed = $removed || (!empty($deleted_events));
        }

        $meta_keys = [
            defined('LL_TOOLS_USER_WORDSET_META') ? LL_TOOLS_USER_WORDSET_META : 'll_user_study_wordset',
            defined('LL_TOOLS_USER_CATEGORY_META') ? LL_TOOLS_USER_CATEGORY_META : 'll_user_study_categories',
            defined('LL_TOOLS_USER_STARRED_META') ? LL_TOOLS_USER_STARRED_META : 'll_user_study_starred',
            'll_user_star_mode',
            defined('LL_TOOLS_USER_FAST_TRANSITIONS_META') ? LL_TOOLS_USER_FAST_TRANSITIONS_META : 'll_user_fast_transitions',
            defined('LL_TOOLS_USER_GOALS_META') ? LL_TOOLS_USER_GOALS_META : 'll_user_study_goals',
            defined('LL_TOOLS_USER_CATEGORY_PROGRESS_META') ? LL_TOOLS_USER_CATEGORY_PROGRESS_META : 'll_user_study_category_progress',
            defined('LL_TOOLS_USER_RECOMMENDATION_QUEUE_META') ? LL_TOOLS_USER_RECOMMENDATION_QUEUE_META : 'll_user_study_recommendation_queue',
            defined('LL_TOOLS_USER_LAST_RECOMMENDATION_META') ? LL_TOOLS_USER_LAST_RECOMMENDATION_META : 'll_user_study_last_recommendation',
            defined('LL_TOOLS_USER_RECOMMENDATION_DISMISSED_META') ? LL_TOOLS_USER_RECOMMENDATION_DISMISSED_META : 'll_user_study_recommendation_dismissed',
            defined('LL_TOOLS_USER_RECOMMENDATION_DEFERRALS_META') ? LL_TOOLS_USER_RECOMMENDATION_DEFERRALS_META : 'll_user_study_recommendation_deferrals',
            defined('LL_TOOLS_OFFLINE_APP_SESSION_META') ? LL_TOOLS_OFFLINE_APP_SESSION_META : '',
        ];

        foreach ($meta_keys as $meta_key) {
            $meta_key = (string) $meta_key;
            if ($meta_key === '') {
                continue;
            }
            $existing = get_user_meta($user_id, $meta_key, true);
            if ($existing === '' || $existing === [] || $existing === null) {
                continue;
            }
            delete_user_meta($user_id, $meta_key);
            $removed = true;
        }

        return $removed;
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
    function ll_tools_privacy_erase_personal_data(string $email_address, int $page = 1): array {
        $user = ll_tools_privacy_get_user_by_email($email_address);
        if (!($user instanceof WP_User) || $page > 1) {
            return [
                'items_removed' => false,
                'items_retained' => false,
                'messages' => [],
                'done' => true,
            ];
        }

        $removed = ll_tools_privacy_delete_user_personal_data((int) $user->ID);

        return [
            'items_removed' => $removed,
            'items_retained' => false,
            'messages' => [],
            'done' => true,
        ];
    }
}

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
            esc_html__('When you use LL Tools while signed in, this site stores your study progress, learning preferences, selected word set/categories, starred words, progress summaries for studied words, and detailed activity history such as quiz exposures and outcomes. If you use the offline sync feature, the site also stores limited offline-session device/profile identifiers needed to keep that sync working. This information is used to save your progress, personalize next-study recommendations, restore your study state across sessions, sync offline study activity back to your account, and let site administrators review learner progress when needed for site operations. Detailed activity log entries are kept for %d days. Summary progress data remains until your account is deleted or the site erases your LL Tools personal data. You can request an export or erasure of this data through the site’s privacy request tools.', 'll-tools-text-domain'),
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
