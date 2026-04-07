<?php
if (!defined('WPINC')) { die; }

if (!defined('LL_TOOLS_OFFLINE_APP_SESSION_META')) {
    define('LL_TOOLS_OFFLINE_APP_SESSION_META', 'll_tools_offline_app_sessions');
}

if (!defined('LL_TOOLS_OFFLINE_APP_MAX_SESSIONS')) {
    define('LL_TOOLS_OFFLINE_APP_MAX_SESSIONS', 8);
}

if (!function_exists('ll_tools_offline_app_public_ajax_actions')) {
    function ll_tools_offline_app_public_ajax_actions(): array {
        return [
            'll_tools_offline_app_login',
            'll_tools_offline_app_logout',
            'll_tools_offline_app_sync',
        ];
    }
}

if (!function_exists('ll_tools_offline_app_allowed_origins')) {
    function ll_tools_offline_app_allowed_origins(): array {
        $origins = [
            'capacitor://localhost',
            'http://localhost',
            'https://localhost',
            'http://127.0.0.1',
            'https://127.0.0.1',
            'ionic://localhost',
        ];

        foreach ([home_url('/'), site_url('/'), admin_url()] as $url) {
            $parts = wp_parse_url((string) $url);
            if (!is_array($parts)) {
                continue;
            }
            $scheme = isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : '';
            $host = isset($parts['host']) ? strtolower((string) $parts['host']) : '';
            if ($scheme === '' || $host === '') {
                continue;
            }
            $origin = $scheme . '://' . $host;
            if (!empty($parts['port'])) {
                $origin .= ':' . (int) $parts['port'];
            }
            $origins[] = $origin;
        }

        $origins = apply_filters('ll_tools_offline_app_allowed_origins', $origins);
        if (!is_array($origins)) {
            return [];
        }

        $clean = [];
        foreach ($origins as $origin) {
            $origin = trim((string) $origin);
            if ($origin === '') {
                continue;
            }
            $clean[$origin] = $origin;
        }

        return array_values($clean);
    }
}

if (!function_exists('ll_tools_offline_app_is_allowed_origin')) {
    function ll_tools_offline_app_is_allowed_origin(string $origin): bool {
        $origin = trim($origin);
        if ($origin === '') {
            return false;
        }

        return in_array($origin, ll_tools_offline_app_allowed_origins(), true);
    }
}

if (!function_exists('ll_tools_offline_app_send_cors_headers')) {
    function ll_tools_offline_app_send_cors_headers(): void {
        $origin = isset($_SERVER['HTTP_ORIGIN']) ? trim((string) wp_unslash($_SERVER['HTTP_ORIGIN'])) : '';
        if ($origin === '' || !ll_tools_offline_app_is_allowed_origin($origin)) {
            return;
        }

        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin', false);
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Access-Control-Max-Age: 600');
    }
}

if (!function_exists('ll_tools_offline_app_maybe_handle_preflight')) {
    function ll_tools_offline_app_maybe_handle_preflight(): void {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'OPTIONS') {
            return;
        }

        $action = isset($_REQUEST['action']) ? sanitize_key((string) wp_unslash($_REQUEST['action'])) : '';
        if (!in_array($action, ll_tools_offline_app_public_ajax_actions(), true)) {
            return;
        }

        ll_tools_offline_app_send_cors_headers();
        status_header(204);
        exit;
    }
}
add_action('init', 'll_tools_offline_app_maybe_handle_preflight', 0);

if (!function_exists('ll_tools_offline_app_prepare_json_response')) {
    function ll_tools_offline_app_prepare_json_response(): void {
        ll_tools_offline_app_send_cors_headers();
        nocache_headers();
    }
}

if (!function_exists('ll_tools_offline_app_session_ttl')) {
    function ll_tools_offline_app_session_ttl(): int {
        $ttl = (int) apply_filters('ll_tools_offline_app_session_ttl', 90 * DAY_IN_SECONDS);
        return max(DAY_IN_SECONDS, $ttl);
    }
}

if (!function_exists('ll_tools_offline_app_sanitize_instance_id')) {
    function ll_tools_offline_app_sanitize_instance_id($raw): string {
        $value = strtolower(trim((string) $raw));
        if ($value === '') {
            return '';
        }

        return substr(preg_replace('/[^a-z0-9._:-]/', '', $value), 0, 80);
    }
}

if (!function_exists('ll_tools_offline_app_request_string')) {
    function ll_tools_offline_app_request_string(string $key): string {
        return isset($_POST[$key]) ? trim((string) wp_unslash($_POST[$key])) : '';
    }
}

if (!function_exists('ll_tools_offline_app_decode_json_payload')) {
    function ll_tools_offline_app_decode_json_payload($raw): array {
        if (is_array($raw)) {
            return $raw;
        }

        $decoded = json_decode((string) wp_unslash($raw), true);
        return is_array($decoded) ? $decoded : [];
    }
}

if (!function_exists('ll_tools_offline_app_sessions_for_user')) {
    function ll_tools_offline_app_sessions_for_user(int $user_id): array {
        if ($user_id <= 0) {
            return [];
        }

        $raw = get_user_meta($user_id, LL_TOOLS_OFFLINE_APP_SESSION_META, true);
        if (!is_array($raw)) {
            return [];
        }

        $now = time();
        $clean = [];
        foreach ($raw as $session_key => $session) {
            $session_key = sanitize_key((string) $session_key);
            if ($session_key === '' || !is_array($session)) {
                continue;
            }

            $hash = isset($session['secret_hash']) ? (string) $session['secret_hash'] : '';
            $expires_at = isset($session['expires_at']) ? (string) $session['expires_at'] : '';
            $expires_ts = $expires_at !== '' ? strtotime($expires_at . ' UTC') : 0;
            if ($hash === '' || ($expires_ts > 0 && $expires_ts < $now)) {
                continue;
            }

            $clean[$session_key] = [
                'secret_hash' => $hash,
                'created_at' => isset($session['created_at']) ? (string) $session['created_at'] : '',
                'expires_at' => $expires_at,
                'last_used_at' => isset($session['last_used_at']) ? (string) $session['last_used_at'] : '',
                'device_id' => ll_tools_offline_app_sanitize_instance_id($session['device_id'] ?? ''),
                'profile_id' => ll_tools_offline_app_sanitize_instance_id($session['profile_id'] ?? ''),
            ];
        }

        if (count($clean) > LL_TOOLS_OFFLINE_APP_MAX_SESSIONS) {
            uasort($clean, static function (array $left, array $right): int {
                return strcmp((string) ($right['last_used_at'] ?? ''), (string) ($left['last_used_at'] ?? ''));
            });
            $clean = array_slice($clean, 0, LL_TOOLS_OFFLINE_APP_MAX_SESSIONS, true);
        }

        if ($clean !== $raw) {
            update_user_meta($user_id, LL_TOOLS_OFFLINE_APP_SESSION_META, $clean);
        }

        return $clean;
    }
}

if (!function_exists('ll_tools_offline_app_store_sessions_for_user')) {
    function ll_tools_offline_app_store_sessions_for_user(int $user_id, array $sessions): void {
        if ($user_id <= 0) {
            return;
        }

        if (empty($sessions)) {
            delete_user_meta($user_id, LL_TOOLS_OFFLINE_APP_SESSION_META);
            return;
        }

        update_user_meta($user_id, LL_TOOLS_OFFLINE_APP_SESSION_META, $sessions);
    }
}

if (!function_exists('ll_tools_offline_app_create_session')) {
    function ll_tools_offline_app_create_session(int $user_id, array $context = []): array {
        $sessions = ll_tools_offline_app_sessions_for_user($user_id);
        $session_key = sanitize_key(str_replace('-', '', wp_generate_uuid4()));
        $session_key = substr($session_key, 0, 24);
        if ($session_key === '') {
            $session_key = strtolower(wp_generate_password(20, false, false));
        }

        $secret = wp_generate_password(40, false, false);
        $now = gmdate('Y-m-d H:i:s');
        $expires_at = gmdate('Y-m-d H:i:s', time() + ll_tools_offline_app_session_ttl());
        $sessions[$session_key] = [
            'secret_hash' => wp_hash_password($secret),
            'created_at' => $now,
            'expires_at' => $expires_at,
            'last_used_at' => $now,
            'device_id' => ll_tools_offline_app_sanitize_instance_id($context['device_id'] ?? ''),
            'profile_id' => ll_tools_offline_app_sanitize_instance_id($context['profile_id'] ?? ''),
        ];
        ll_tools_offline_app_store_sessions_for_user($user_id, $sessions);

        return [
            'token' => sprintf('llapp.%d.%s.%s', $user_id, $session_key, $secret),
            'expires_at' => $expires_at,
            'session_key' => $session_key,
        ];
    }
}

if (!function_exists('ll_tools_offline_app_authenticate_token')) {
    function ll_tools_offline_app_authenticate_token(string $token, bool $touch = true): ?array {
        $token = trim($token);
        if (!preg_match('/^llapp\.(\d+)\.([a-z0-9]+)\.([A-Za-z0-9]+)$/', $token, $matches)) {
            return null;
        }

        $user_id = (int) $matches[1];
        $session_key = sanitize_key((string) $matches[2]);
        $secret = (string) $matches[3];
        if ($user_id <= 0 || $session_key === '' || $secret === '') {
            return null;
        }

        $sessions = ll_tools_offline_app_sessions_for_user($user_id);
        if (empty($sessions[$session_key]) || !is_array($sessions[$session_key])) {
            return null;
        }

        $session = $sessions[$session_key];
        if (!wp_check_password($secret, (string) ($session['secret_hash'] ?? ''))) {
            return null;
        }

        if ($touch) {
            $now = gmdate('Y-m-d H:i:s');
            $session['last_used_at'] = $now;
            $session['expires_at'] = gmdate('Y-m-d H:i:s', time() + ll_tools_offline_app_session_ttl());
            $sessions[$session_key] = $session;
            ll_tools_offline_app_store_sessions_for_user($user_id, $sessions);
        }

        return [
            'user_id' => $user_id,
            'session_key' => $session_key,
            'session' => $session,
        ];
    }
}

if (!function_exists('ll_tools_offline_app_revoke_session')) {
    function ll_tools_offline_app_revoke_session(int $user_id, string $session_key): void {
        $sessions = ll_tools_offline_app_sessions_for_user($user_id);
        if (!isset($sessions[$session_key])) {
            return;
        }

        unset($sessions[$session_key]);
        ll_tools_offline_app_store_sessions_for_user($user_id, $sessions);
    }
}

if (!function_exists('ll_tools_offline_app_require_authenticated_user')) {
    function ll_tools_offline_app_require_authenticated_user(): array {
        $token = ll_tools_offline_app_request_string('auth_token');
        $auth = ll_tools_offline_app_authenticate_token($token);
        if (!$auth) {
            wp_send_json_error(['message' => __('Sign in required.', 'll-tools-text-domain')], 401);
        }

        $user_id = (int) ($auth['user_id'] ?? 0);
        if ($user_id <= 0 || !ll_tools_user_study_can_access($user_id)) {
            wp_send_json_error(['message' => __('You do not have permission.', 'll-tools-text-domain')], 403);
        }

        return $auth;
    }
}

if (!function_exists('ll_tools_offline_app_build_user_summary')) {
    function ll_tools_offline_app_build_user_summary(int $user_id): array {
        $user = get_userdata($user_id);
        if (!($user instanceof WP_User)) {
            return [];
        }

        return [
            'id' => (int) $user->ID,
            'login' => (string) $user->user_login,
            'display_name' => (string) $user->display_name,
        ];
    }
}

if (!function_exists('ll_tools_offline_app_normalize_state_payload')) {
    function ll_tools_offline_app_normalize_state_payload(array $raw, int $user_id): array {
        $current = ll_tools_get_user_study_state($user_id);
        $wordset_id = isset($raw['wordset_id']) ? (int) $raw['wordset_id'] : (int) ($current['wordset_id'] ?? 0);
        $category_ids = isset($raw['category_ids']) ? (array) $raw['category_ids'] : (array) ($current['category_ids'] ?? []);
        $starred_ids = isset($raw['starred_word_ids']) ? (array) $raw['starred_word_ids'] : (array) ($current['starred_word_ids'] ?? []);
        $star_mode = ll_tools_normalize_star_mode($raw['star_mode'] ?? ($current['star_mode'] ?? 'normal'));
        $fast_transitions = filter_var($raw['fast_transitions'] ?? ($current['fast_transitions'] ?? false), FILTER_VALIDATE_BOOLEAN);

        $goals = function_exists('ll_tools_get_user_study_goals')
            ? ll_tools_get_user_study_goals($user_id)
            : [];
        $ignored_lookup = [];
        foreach ((array) ($goals['ignored_category_ids'] ?? []) as $ignored_id) {
            $ignored_lookup[(int) $ignored_id] = true;
        }

        $category_ids = array_values(array_filter(array_map('intval', $category_ids), static function (int $id) use ($ignored_lookup): bool {
            return $id > 0 && empty($ignored_lookup[$id]);
        }));
        if (function_exists('ll_tools_user_study_filter_quizzable_category_ids')) {
            $category_ids = ll_tools_user_study_filter_quizzable_category_ids($category_ids, $wordset_id);
        }

        $starred_ids = array_values(array_filter(array_map('intval', $starred_ids), static function (int $id): bool {
            return $id > 0;
        }));

        return [
            'wordset_id' => $wordset_id,
            'category_ids' => $category_ids,
            'starred_word_ids' => $starred_ids,
            'star_mode' => $star_mode,
            'fast_transitions' => $fast_transitions,
        ];
    }
}

if (!function_exists('ll_tools_offline_app_parse_state_request')) {
    function ll_tools_offline_app_parse_state_request(int $user_id): array {
        $state_raw = $_POST['state'] ?? null;
        if ($state_raw === null) {
            return ll_tools_get_user_study_state($user_id);
        }

        $state = ll_tools_offline_app_decode_json_payload($state_raw);
        return ll_tools_save_user_study_state(ll_tools_offline_app_normalize_state_payload($state, $user_id), $user_id);
    }
}

if (!function_exists('ll_tools_offline_app_parse_goals_request')) {
    function ll_tools_offline_app_parse_goals_request(int $user_id): array {
        if (!function_exists('ll_tools_get_user_study_goals')) {
            return [];
        }

        $goals_raw = $_POST['goals'] ?? null;
        if ($goals_raw === null) {
            return ll_tools_get_user_study_goals($user_id);
        }

        $goals = ll_tools_offline_app_decode_json_payload($goals_raw);
        if (function_exists('ll_tools_save_user_study_goals')) {
            return ll_tools_save_user_study_goals($goals, $user_id);
        }

        return ll_tools_get_user_study_goals($user_id);
    }
}

if (!function_exists('ll_tools_offline_app_parse_word_ids')) {
    function ll_tools_offline_app_parse_word_ids(): array {
        $raw = $_POST['word_ids'] ?? [];
        $word_ids = ll_tools_offline_app_decode_json_payload($raw);
        $word_ids = array_values(array_unique(array_filter(array_map('intval', (array) $word_ids), static function (int $id): bool {
            return $id > 0;
        })));
        return array_slice($word_ids, 0, 5000);
    }
}

if (!function_exists('ll_tools_offline_app_build_category_progress_snapshots')) {
    function ll_tools_offline_app_build_category_progress_snapshots(int $user_id, array $state): array {
        if (!function_exists('ll_tools_get_user_category_progress')) {
            return [];
        }

        $wordset_id = (int) ($state['wordset_id'] ?? 0);
        $category_ids = array_values(array_unique(array_filter(array_map('intval', (array) ($state['category_ids'] ?? [])), static function (int $id): bool {
            return $id > 0;
        })));
        $category_lookup = array_fill_keys($category_ids, true);
        $raw = ll_tools_get_user_category_progress($user_id);
        if (empty($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $category_id => $entry) {
            $cid = (int) $category_id;
            if ($cid <= 0 || !is_array($entry)) {
                continue;
            }
            if (!empty($category_lookup) && empty($category_lookup[$cid])) {
                continue;
            }
            if ($wordset_id > 0 && !empty($entry['wordset_id']) && (int) $entry['wordset_id'] !== $wordset_id) {
                continue;
            }
            $out[$cid] = $entry;
        }

        return $out;
    }
}

if (!function_exists('ll_tools_offline_app_build_word_progress_snapshots')) {
    function ll_tools_offline_app_build_word_progress_snapshots(int $user_id, array $word_ids): array {
        $rows = ll_tools_get_user_word_progress_rows($user_id, $word_ids);
        if (empty($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $word_id => $row) {
            if (!is_array($row)) {
                continue;
            }

            $out[(int) $word_id] = [
                'total_coverage' => max(0, (int) ($row['total_coverage'] ?? 0)),
                'coverage_learning' => max(0, (int) ($row['coverage_learning'] ?? 0)),
                'coverage_practice' => max(0, (int) ($row['coverage_practice'] ?? 0)),
                'coverage_listening' => max(0, (int) ($row['coverage_listening'] ?? 0)),
                'coverage_gender' => max(0, (int) ($row['coverage_gender'] ?? 0)),
                'coverage_self_check' => max(0, (int) ($row['coverage_self_check'] ?? 0)),
                'correct_clean' => max(0, (int) ($row['correct_clean'] ?? 0)),
                'correct_after_retry' => max(0, (int) ($row['correct_after_retry'] ?? 0)),
                'current_correct_streak' => max(0, (int) ($row['current_correct_streak'] ?? 0)),
                'mastery_unlocked' => !empty($row['mastery_unlocked']),
                'incorrect' => max(0, (int) ($row['incorrect'] ?? 0)),
                'lapse_count' => max(0, (int) ($row['lapse_count'] ?? 0)),
                'stage' => max(0, (int) ($row['stage'] ?? 0)),
                'last_mode' => isset($row['last_mode']) ? (string) $row['last_mode'] : '',
                'last_seen_at' => isset($row['last_seen_at']) ? (string) $row['last_seen_at'] : '',
                'updated_at' => isset($row['updated_at']) ? (string) $row['updated_at'] : '',
                'progress_status' => ll_tools_user_progress_word_status($row),
                'difficulty_score' => ll_tools_user_progress_word_difficulty_score($row),
                'practice_required_recording_types' => ll_tools_get_progress_row_practice_required_recording_types($row),
                'practice_correct_recording_types' => ll_tools_get_progress_row_practice_correct_recording_types($row),
                'gender_progress' => ll_tools_get_progress_row_gender_progress($row),
            ];
        }

        return $out;
    }
}

if (!function_exists('ll_tools_offline_app_build_sync_response')) {
    function ll_tools_offline_app_build_sync_response(int $user_id, array $state, array $stats, array $word_ids): array {
        $goals = ll_tools_offline_app_parse_goals_request($user_id);
        $categories = function_exists('ll_tools_user_study_categories_for_wordset')
            ? ll_tools_user_study_categories_for_wordset((int) ($state['wordset_id'] ?? 0))
            : [];
        $queue = function_exists('ll_tools_refresh_user_recommendation_queue')
            ? ll_tools_refresh_user_recommendation_queue($user_id, (int) ($state['wordset_id'] ?? 0), (array) ($state['category_ids'] ?? []), $categories, 8)
            : [];
        $recommendation = function_exists('ll_tools_recommendation_queue_pick_next')
            ? ll_tools_recommendation_queue_pick_next($queue)
            : null;
        if (!$recommendation && function_exists('ll_tools_build_next_activity_recommendation')) {
            $recommendation = ll_tools_build_next_activity_recommendation(
                $user_id,
                (int) ($state['wordset_id'] ?? 0),
                (array) ($state['category_ids'] ?? []),
                $categories
            );
        }
        if ($recommendation && function_exists('ll_tools_save_user_last_recommendation_activity')) {
            ll_tools_save_user_last_recommendation_activity($recommendation, $user_id, (int) ($state['wordset_id'] ?? 0));
        }

        return [
            'auth' => [
                'user' => ll_tools_offline_app_build_user_summary($user_id),
            ],
            'stats' => $stats,
            'state' => $state,
            'goals' => $goals,
            'scope_word_ids' => array_values(array_map('intval', $word_ids)),
            'progress_words' => ll_tools_offline_app_build_word_progress_snapshots($user_id, $word_ids),
            'category_progress' => ll_tools_offline_app_build_category_progress_snapshots($user_id, $state),
            'next_activity' => $recommendation,
            'recommendation_queue' => $queue,
            'server_time' => gmdate('c'),
        ];
    }
}

if (!function_exists('ll_tools_offline_app_login_ajax')) {
    function ll_tools_offline_app_login_ajax(): void {
        ll_tools_offline_app_prepare_json_response();

        $identifier = ll_tools_offline_app_request_string('identifier');
        $password = isset($_POST['password']) ? (string) wp_unslash($_POST['password']) : '';
        if ($identifier === '' || $password === '') {
            wp_send_json_error(['message' => __('Enter your username or email and password.', 'll-tools-text-domain')], 400);
        }

        $user = wp_authenticate($identifier, $password);
        if (is_wp_error($user) || !($user instanceof WP_User)) {
            wp_send_json_error(['message' => __('Invalid login.', 'll-tools-text-domain')], 401);
        }
        if (!ll_tools_user_study_can_access((int) $user->ID)) {
            wp_send_json_error(['message' => __('You do not have permission.', 'll-tools-text-domain')], 403);
        }

        $session = ll_tools_offline_app_create_session((int) $user->ID, [
            'device_id' => ll_tools_offline_app_request_string('device_id'),
            'profile_id' => ll_tools_offline_app_request_string('profile_id'),
        ]);

        wp_send_json_success([
            'auth_token' => (string) ($session['token'] ?? ''),
            'expires_at' => (string) ($session['expires_at'] ?? ''),
            'user' => ll_tools_offline_app_build_user_summary((int) $user->ID),
        ]);
    }
}
add_action('wp_ajax_nopriv_ll_tools_offline_app_login', 'll_tools_offline_app_login_ajax');
add_action('wp_ajax_ll_tools_offline_app_login', 'll_tools_offline_app_login_ajax');

if (!function_exists('ll_tools_offline_app_logout_ajax')) {
    function ll_tools_offline_app_logout_ajax(): void {
        ll_tools_offline_app_prepare_json_response();
        $auth = ll_tools_offline_app_require_authenticated_user();
        ll_tools_offline_app_revoke_session((int) ($auth['user_id'] ?? 0), (string) ($auth['session_key'] ?? ''));
        wp_send_json_success(['logged_out' => true]);
    }
}
add_action('wp_ajax_nopriv_ll_tools_offline_app_logout', 'll_tools_offline_app_logout_ajax');
add_action('wp_ajax_ll_tools_offline_app_logout', 'll_tools_offline_app_logout_ajax');

if (!function_exists('ll_tools_offline_app_sync_ajax')) {
    function ll_tools_offline_app_sync_ajax(): void {
        ll_tools_offline_app_prepare_json_response();
        $auth = ll_tools_offline_app_require_authenticated_user();
        $user_id = (int) ($auth['user_id'] ?? 0);

        $events_raw = $_POST['events'] ?? '[]';
        $events = ll_tools_offline_app_decode_json_payload($events_raw);
        $events = array_slice($events, 0, 200);

        $state = ll_tools_offline_app_parse_state_request($user_id);
        $stats = ll_tools_process_progress_events_batch($user_id, $events);

        $requested_word_ids = ll_tools_offline_app_parse_word_ids();
        if (empty($requested_word_ids)) {
            $requested_word_ids = array_values(array_unique(array_filter(array_map(static function ($event): int {
                return is_array($event) ? (int) ($event['word_id'] ?? 0) : 0;
            }, $events), static function (int $word_id): bool {
                return $word_id > 0;
            })));
        }

        wp_send_json_success(ll_tools_offline_app_build_sync_response($user_id, $state, $stats, $requested_word_ids));
    }
}
add_action('wp_ajax_nopriv_ll_tools_offline_app_sync', 'll_tools_offline_app_sync_ajax');
add_action('wp_ajax_ll_tools_offline_app_sync', 'll_tools_offline_app_sync_ajax');
