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

if (!function_exists('ll_tools_user_progress_report_stats_for_users')) {
    function ll_tools_user_progress_report_stats_for_users(array $user_ids, int $wordset_id = 0): array {
        global $wpdb;

        $user_ids = array_values(array_unique(array_filter(array_map('intval', $user_ids), static function (int $user_id): bool {
            return $user_id > 0;
        })));
        if (empty($user_ids) || !function_exists('ll_tools_user_progress_table_names')) {
            return [];
        }

        $tables = ll_tools_user_progress_table_names();
        $placeholders = implode(', ', array_fill(0, count($user_ids), '%d'));
        $where_sql = "user_id IN ({$placeholders})";
        $params = $user_ids;

        if ($wordset_id > 0) {
            $where_sql .= ' AND wordset_id = %d';
            $params[] = $wordset_id;
        }

        $rows_sql = "SELECT * FROM {$tables['words']} WHERE {$where_sql}";
        $rows = $wpdb->get_results($wpdb->prepare($rows_sql, $params), ARRAY_A);

        $stats = [];
        foreach ($user_ids as $user_id) {
            $stats[$user_id] = [
                'tracked_words' => 0,
                'studied_words' => 0,
                'mastered_words' => 0,
                'hard_words' => 0,
                'last_progress_at' => '',
                'last_event_at' => '',
                'rounds_30d' => 0,
                'outcomes_30d' => 0,
                'sessions_30d' => 0,
            ];
        }

        foreach ((array) $rows as $row) {
            $user_id = isset($row['user_id']) ? (int) $row['user_id'] : 0;
            if ($user_id <= 0 || !isset($stats[$user_id]) || !is_array($row)) {
                continue;
            }

            $stats[$user_id]['tracked_words']++;
            if (function_exists('ll_tools_user_progress_word_is_studied') && ll_tools_user_progress_word_is_studied($row)) {
                $stats[$user_id]['studied_words']++;
            }
            if (function_exists('ll_tools_user_progress_word_is_mastered') && ll_tools_user_progress_word_is_mastered($row)) {
                $stats[$user_id]['mastered_words']++;
            }
            if (function_exists('ll_tools_user_progress_word_is_hard') && ll_tools_user_progress_word_is_hard($row)) {
                $stats[$user_id]['hard_words']++;
            }

            $last_seen_at = isset($row['last_seen_at']) ? (string) $row['last_seen_at'] : '';
            if ($last_seen_at !== '' && (
                $stats[$user_id]['last_progress_at'] === ''
                || strcmp($last_seen_at, (string) $stats[$user_id]['last_progress_at']) > 0
            )) {
                $stats[$user_id]['last_progress_at'] = $last_seen_at;
            }
        }

        $events_where_sql = "user_id IN ({$placeholders})";
        if ($wordset_id > 0) {
            $events_where_sql .= ' AND wordset_id = %d';
        }
        $cutoff = gmdate('Y-m-d H:i:s', time() - (30 * DAY_IN_SECONDS));

        $events_sql = "
            SELECT
                user_id,
                MAX(created_at) AS last_event_at,
                SUM(CASE WHEN created_at >= %s AND event_type = 'word_exposure' THEN 1 ELSE 0 END) AS rounds_30d,
                SUM(CASE WHEN created_at >= %s AND event_type = 'word_outcome' THEN 1 ELSE 0 END) AS outcomes_30d,
                SUM(CASE WHEN created_at >= %s AND event_type = 'mode_session_complete' THEN 1 ELSE 0 END) AS sessions_30d
            FROM {$tables['events']}
            WHERE {$events_where_sql}
            GROUP BY user_id
        ";

        $events_query_params = array_merge([$cutoff, $cutoff, $cutoff], $user_ids);
        if ($wordset_id > 0) {
            $events_query_params[] = $wordset_id;
        }

        $event_rows = $wpdb->get_results($wpdb->prepare($events_sql, $events_query_params), ARRAY_A);
        foreach ((array) $event_rows as $row) {
            $user_id = isset($row['user_id']) ? (int) $row['user_id'] : 0;
            if ($user_id <= 0 || !isset($stats[$user_id])) {
                continue;
            }

            $stats[$user_id]['last_event_at'] = isset($row['last_event_at']) ? (string) $row['last_event_at'] : '';
            $stats[$user_id]['rounds_30d'] = max(0, (int) ($row['rounds_30d'] ?? 0));
            $stats[$user_id]['outcomes_30d'] = max(0, (int) ($row['outcomes_30d'] ?? 0));
            $stats[$user_id]['sessions_30d'] = max(0, (int) ($row['sessions_30d'] ?? 0));
        }

        return $stats;
    }
}

if (!function_exists('ll_tools_user_progress_report_user_wordset_id')) {
    function ll_tools_user_progress_report_user_wordset_id(int $user_id): int {
        $state = function_exists('ll_tools_get_user_study_state')
            ? ll_tools_get_user_study_state($user_id)
            : [];

        return max(0, (int) ($state['wordset_id'] ?? 0));
    }
}

if (!function_exists('ll_tools_user_progress_report_wordset_name')) {
    function ll_tools_user_progress_report_wordset_name(int $wordset_id): string {
        if ($wordset_id <= 0) {
            return '';
        }

        $term = get_term($wordset_id, 'wordset');
        if (!($term instanceof WP_Term) || is_wp_error($term)) {
            return '';
        }

        return sanitize_text_field((string) $term->name);
    }
}

if (!function_exists('ll_tools_user_progress_report_last_activity')) {
    function ll_tools_user_progress_report_last_activity(array $stats): string {
        $last_progress = isset($stats['last_progress_at']) ? (string) $stats['last_progress_at'] : '';
        $last_event = isset($stats['last_event_at']) ? (string) $stats['last_event_at'] : '';

        if ($last_progress === '') {
            return $last_event;
        }
        if ($last_event === '') {
            return $last_progress;
        }

        return (strcmp($last_event, $last_progress) > 0) ? $last_event : $last_progress;
    }
}

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
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Learner Progress', 'll-tools-text-domain'); ?></h1>
            <p><?php esc_html_e('This report is limited to administrators because it contains identifiable learner usage and progress data.', 'll-tools-text-domain'); ?></p>

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
                        <th><?php esc_html_e('30d Rounds', 'll-tools-text-domain'); ?></th>
                        <th><?php esc_html_e('30d Outcomes', 'll-tools-text-domain'); ?></th>
                        <th><?php esc_html_e('Studied', 'll-tools-text-domain'); ?></th>
                        <th><?php esc_html_e('Mastered', 'll-tools-text-domain'); ?></th>
                        <th><?php esc_html_e('Hard', 'll-tools-text-domain'); ?></th>
                        <th><?php esc_html_e('Last Activity (UTC)', 'll-tools-text-domain'); ?></th>
                        <th><?php esc_html_e('Details', 'll-tools-text-domain'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users)) : ?>
                        <tr>
                            <td colspan="11"><?php esc_html_e('No learner progress data matched the current filters.', 'll-tools-text-domain'); ?></td>
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
                            $detail_url = add_query_arg([
                                'page' => ll_tools_get_user_progress_report_page_slug(),
                                'user_id' => (int) $user->ID,
                                'wordset_id' => $wordset_id,
                                's' => $search,
                                'paged' => $paged,
                            ], admin_url('admin.php'));
                            ?>
                            <tr>
                                <td><?php echo esc_html($user->display_name ?: $user->user_login); ?></td>
                                <td><a href="mailto:<?php echo esc_attr($user->user_email); ?>"><?php echo esc_html($user->user_email); ?></a></td>
                                <td><?php echo esc_html(implode(', ', array_map('sanitize_text_field', (array) $user->roles))); ?></td>
                                <td><?php echo esc_html(ll_tools_user_progress_report_wordset_name($current_wordset_id) ?: ''); ?></td>
                                <td><?php echo esc_html((string) max(0, (int) ($row_stats['rounds_30d'] ?? 0))); ?></td>
                                <td><?php echo esc_html((string) max(0, (int) ($row_stats['outcomes_30d'] ?? 0))); ?></td>
                                <td><?php echo esc_html((string) max(0, (int) ($row_stats['studied_words'] ?? 0))); ?></td>
                                <td><?php echo esc_html((string) max(0, (int) ($row_stats['mastered_words'] ?? 0))); ?></td>
                                <td><?php echo esc_html((string) max(0, (int) ($row_stats['hard_words'] ?? 0))); ?></td>
                                <td><?php echo esc_html(ll_tools_user_progress_report_last_activity($row_stats)); ?></td>
                                <td><a class="button button-small" href="<?php echo esc_url($detail_url); ?>"><?php esc_html_e('View', 'll-tools-text-domain'); ?></a></td>
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

                <?php if (!empty($detail_analytics)) : ?>
                    <?php $summary = (array) ($detail_analytics['summary'] ?? []); ?>
                    <?php $daily = (array) ($detail_analytics['daily_activity'] ?? []); ?>
                    <table class="widefat striped" style="max-width: 900px; margin-bottom: 24px;">
                        <tbody>
                            <tr>
                                <th><?php esc_html_e('Total words in scope', 'll-tools-text-domain'); ?></th>
                                <td><?php echo esc_html((string) max(0, (int) ($summary['total_words'] ?? 0))); ?></td>
                                <th><?php esc_html_e('Studied', 'll-tools-text-domain'); ?></th>
                                <td><?php echo esc_html((string) max(0, (int) ($summary['studied_words'] ?? 0))); ?></td>
                                <th><?php esc_html_e('Mastered', 'll-tools-text-domain'); ?></th>
                                <td><?php echo esc_html((string) max(0, (int) ($summary['mastered_words'] ?? 0))); ?></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('New', 'll-tools-text-domain'); ?></th>
                                <td><?php echo esc_html((string) max(0, (int) ($summary['new_words'] ?? 0))); ?></td>
                                <th><?php esc_html_e('Hard', 'll-tools-text-domain'); ?></th>
                                <td><?php echo esc_html((string) max(0, (int) ($summary['hard_words'] ?? 0))); ?></td>
                                <th><?php esc_html_e('30d rounds window', 'll-tools-text-domain'); ?></th>
                                <td><?php echo esc_html((string) max(0, (int) ($daily['max_rounds'] ?? 0))); ?></td>
                            </tr>
                        </tbody>
                    </table>

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
        </div>
        <?php
    }
}
