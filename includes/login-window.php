<?php
if (!defined('WPINC')) { die; }

if (!function_exists('ll_tools_get_current_request_url')) {
    function ll_tools_get_current_request_url(): string {
        global $wp;

        $request_path = '';
        if (isset($wp) && is_object($wp) && isset($wp->request)) {
            $request_path = (string) $wp->request;
        }

        $url = home_url('/');
        if ($request_path !== '') {
            $url = home_url('/' . ltrim($request_path, '/'));
        }

        if (!empty($_GET) && is_array($_GET)) {
            $query_args = wp_unslash($_GET);
            if (is_array($query_args) && !empty($query_args)) {
                $url = add_query_arg($query_args, $url);
            }
        }

        return esc_url_raw($url);
    }
}

if (!function_exists('ll_tools_get_valid_login_redirect_request')) {
    function ll_tools_get_valid_login_redirect_request($request): string {
        $request = is_string($request) ? trim($request) : '';
        if ($request === '') {
            return '';
        }

        return (string) wp_validate_redirect($request, '');
    }
}

if (!function_exists('ll_tools_is_learner_self_registration_enabled')) {
    function ll_tools_is_learner_self_registration_enabled(): bool {
        $enabled = (int) get_option('ll_allow_learner_self_registration', 1);
        return (bool) apply_filters('ll_tools_allow_learner_self_registration', ($enabled === 1));
    }
}

if (!function_exists('ll_tools_is_wordpress_user_registration_enabled')) {
    function ll_tools_is_wordpress_user_registration_enabled(): bool {
        if (is_multisite()) {
            $registration = (string) get_site_option('registration', 'none');
            return in_array($registration, ['all', 'user'], true);
        }

        return ((int) get_option('users_can_register', 0) === 1);
    }
}

if (!function_exists('ll_tools_is_learner_self_registration_available')) {
    function ll_tools_is_learner_self_registration_available(): bool {
        if (ll_tools_is_learner_self_registration_enabled() && ll_tools_is_wordpress_user_registration_enabled()) {
            return true;
        }

        if (function_exists('ll_tools_teacher_class_current_request_allows_signup_registration')) {
            return ll_tools_teacher_class_current_request_allows_signup_registration();
        }

        return false;
    }
}

if (!function_exists('ll_tools_is_generated_registration_password_visible')) {
    function ll_tools_is_generated_registration_password_visible(): bool {
        $enabled = (int) get_option('ll_show_generated_registration_password', 1);
        return (bool) apply_filters('ll_tools_show_generated_registration_password', ($enabled === 1));
    }
}

if (!function_exists('ll_tools_get_password_visibility_toggle_icon')) {
    function ll_tools_get_password_visibility_toggle_icon(string $state, string $class = 'll-tools-login-window__password-toggle-icon-svg'): string {
        if ($state === 'hide') {
            return '<svg class="' . esc_attr($class) . '" viewBox="0 0 64 64" width="18" height="18" xmlns="http://www.w3.org/2000/svg" fill="none" aria-hidden="true" focusable="false">'
                . '<path d="M6 32 C14 26, 22 22, 32 22 C42 22, 50 26, 58 32 C50 38, 42 42, 32 42 C22 42, 14 38, 6 32Z" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>'
                . '<circle cx="32" cy="32" r="7" fill="currentColor"/>'
                . '<path d="M16 16 L48 48" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round"/>'
                . '</svg>';
        }

        return '<svg class="' . esc_attr($class) . '" viewBox="0 0 24 24" width="18" height="18" xmlns="http://www.w3.org/2000/svg" fill="currentColor" aria-hidden="true" focusable="false">'
            . '<path d="M12 5c5.8 0 9.8 4.6 11.3 6.8a1 1 0 0 1 0 1.1C21.8 15 17.8 19.5 12 19.5S2.2 15 0.7 12.9a1 1 0 0 1 0-1.1C2.2 9.6 6.2 5 12 5Zm0 2C7.5 7 4.2 10.4 2.8 12 4.2 13.6 7.5 17 12 17s7.8-3.4 9.2-5C19.8 10.4 16.5 7 12 7Zm0 2.2a2.8 2.8 0 1 1 0 5.6 2.8 2.8 0 0 1 0-5.6Z"/>'
            . '</svg>';
    }
}

if (!function_exists('ll_tools_sanitize_notification_email')) {
    function ll_tools_sanitize_notification_email($value, $settings_key = 'll_tools_recording_notification_email', $error_code = 'll_tools_notification_email_invalid'): string {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        $email = sanitize_email($value);
        if (!is_email($email)) {
            add_settings_error(
                (string) $settings_key,
                (string) $error_code,
                __('Please enter a valid notification email address.', 'll-tools-text-domain')
            );
            return '';
        }

        return $email;
    }
}

if (!function_exists('ll_tools_get_admin_notification_recipient')) {
    function ll_tools_get_admin_notification_recipient(): string {
        // Keep using the existing recorder notification setting as the shared admin notification recipient.
        $configured = trim((string) get_option('ll_tools_recording_notification_email', ''));
        if ($configured !== '' && is_email($configured)) {
            return $configured;
        }

        $admin_email = trim((string) get_option('admin_email', ''));
        if (is_email($admin_email)) {
            return $admin_email;
        }

        return '';
    }
}

if (!function_exists('ll_tools_get_notification_sender_email')) {
    function ll_tools_get_notification_sender_email(): string {
        $hosts = [];

        $home_host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
        if ($home_host !== '') {
            $hosts[] = $home_host;
        }

        $network_host = (string) wp_parse_url(network_home_url('/'), PHP_URL_HOST);
        if ($network_host !== '') {
            $hosts[] = $network_host;
        }

        $hosts = array_values(array_unique($hosts));
        foreach ($hosts as $host) {
            $host = strtolower(trim((string) $host));
            if ($host === '') {
                continue;
            }

            if (strpos($host, 'www.') === 0) {
                $host = substr($host, 4);
            }

            $email = sanitize_email('wordpress@' . $host);
            if (is_email($email)) {
                return $email;
            }
        }

        return '';
    }
}

if (!function_exists('ll_tools_get_notification_sender_name')) {
    function ll_tools_get_notification_sender_name(): string {
        $site_name = sanitize_text_field((string) wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES));
        if ($site_name !== '') {
            return $site_name;
        }

        return 'WordPress';
    }
}

if (!function_exists('ll_tools_override_mail_from_header')) {
    function ll_tools_override_mail_from_header($headers, string $from_name, string $from_email): array {
        $normalized_headers = [];

        if (is_array($headers)) {
            foreach ($headers as $header_line) {
                $header_line = trim((string) $header_line);
                if ($header_line !== '') {
                    $normalized_headers[] = $header_line;
                }
            }
        } else {
            $header_lines = preg_split('/\r\n|\r|\n/', (string) $headers);
            if (is_array($header_lines)) {
                foreach ($header_lines as $header_line) {
                    $header_line = trim((string) $header_line);
                    if ($header_line !== '') {
                        $normalized_headers[] = $header_line;
                    }
                }
            }
        }

        $normalized_headers = array_values(array_filter(
            $normalized_headers,
            static function ($header_line): bool {
                return stripos((string) $header_line, 'From:') !== 0;
            }
        ));

        $normalized_headers[] = sprintf('From: %s <%s>', $from_name, $from_email);

        return $normalized_headers;
    }
}

if (!function_exists('ll_tools_normalize_registration_admin_email_setting')) {
    function ll_tools_normalize_registration_admin_email_setting($value): int {
        return absint($value) === 1 ? 1 : 0;
    }
}

if (!function_exists('ll_tools_sanitize_registration_admin_email_setting')) {
    function ll_tools_sanitize_registration_admin_email_setting($value): int {
        return ll_tools_normalize_registration_admin_email_setting($value);
    }
}

if (!function_exists('ll_tools_is_registration_admin_notification_enabled')) {
    function ll_tools_is_registration_admin_notification_enabled(): bool {
        $enabled = (int) get_option('ll_tools_send_registration_admin_email', 1);
        return (bool) apply_filters('ll_tools_send_registration_admin_email', ($enabled === 1));
    }
}

if (!function_exists('ll_tools_filter_send_new_user_notification_to_admin')) {
    function ll_tools_filter_send_new_user_notification_to_admin($send, $user): bool {
        if (!$send) {
            return false;
        }

        return ll_tools_is_registration_admin_notification_enabled();
    }
}
add_filter('wp_send_new_user_notification_to_admin', 'll_tools_filter_send_new_user_notification_to_admin', 10, 2);

if (!function_exists('ll_tools_filter_new_user_notification_email_admin')) {
    function ll_tools_filter_new_user_notification_email_admin($email_args, $user, $blogname) {
        if (!is_array($email_args)) {
            return $email_args;
        }

        $recipient = ll_tools_get_admin_notification_recipient();
        if ($recipient !== '') {
            $email_args['to'] = $recipient;
        }

        $from_email = ll_tools_get_notification_sender_email();
        if ($from_email !== '') {
            $email_args['headers'] = ll_tools_override_mail_from_header(
                $email_args['headers'] ?? '',
                ll_tools_get_notification_sender_name(),
                $from_email
            );
        }

        return $email_args;
    }
}
add_filter('wp_new_user_notification_email_admin', 'll_tools_filter_new_user_notification_email_admin', 10, 3);

if (!function_exists('ll_tools_maybe_send_registration_admin_notification')) {
    function ll_tools_maybe_send_registration_admin_notification($user_id): bool {
        $user_id = (int) $user_id;
        if ($user_id <= 0 || !ll_tools_is_registration_admin_notification_enabled()) {
            return false;
        }

        if (function_exists('wp_send_new_user_notifications')) {
            wp_send_new_user_notifications($user_id, 'admin');
            return true;
        }

        if (function_exists('wp_new_user_notification')) {
            wp_new_user_notification($user_id, null, 'admin');
            return true;
        }

        return false;
    }
}

if (!function_exists('ll_tools_register_registration_admin_notification_setting')) {
    function ll_tools_register_registration_admin_notification_setting(): void {
        register_setting('language-learning-tools-options', 'll_tools_send_registration_admin_email', [
            'type' => 'boolean',
            'sanitize_callback' => 'll_tools_sanitize_registration_admin_email_setting',
            'default' => 1,
        ]);
    }
}
add_action('admin_init', 'll_tools_register_registration_admin_notification_setting');

if (!function_exists('ll_tools_render_registration_admin_notification_settings_row')) {
    function ll_tools_render_registration_admin_notification_settings_row(): void {
        $enabled = (int) get_option('ll_tools_send_registration_admin_email', 1);
        ?>
        <tr valign="top">
            <th scope="row"><?php esc_html_e('Admin Email On User Registration', 'll-tools-text-domain'); ?></th>
            <td>
                <input
                    type="checkbox"
                    name="ll_tools_send_registration_admin_email"
                    id="ll_tools_send_registration_admin_email"
                    value="1"
                    <?php checked(1, $enabled, true); ?>
                />
                <p class="description">
                    <?php esc_html_e('Send an admin notification when a new user account is created through LL Tools or standard WordPress registration flows. Uses the Admin Notification Email setting below.', 'll-tools-text-domain'); ?>
                </p>
            </td>
        </tr>
        <?php
    }
}
add_action('ll_tools_settings_after_translations', 'll_tools_render_registration_admin_notification_settings_row', 8);

if (!function_exists('ll_tools_login_window_class_string')) {
    function ll_tools_login_window_class_string($classes = ''): string {
        $class_list = ['ll-tools-login-window-wrap'];
        $raw_classes = is_array($classes) ? $classes : preg_split('/\s+/', (string) $classes);

        foreach ((array) $raw_classes as $candidate) {
            $candidate = sanitize_html_class((string) $candidate);
            if ($candidate !== '') {
                $class_list[] = $candidate;
            }
        }

        $class_list = array_values(array_unique($class_list));
        return implode(' ', $class_list);
    }
}

if (!function_exists('ll_tools_login_window_sanitize_auth_mode')) {
    function ll_tools_login_window_sanitize_auth_mode($mode): string {
        $mode = sanitize_key((string) $mode);
        if (!in_array($mode, ['login', 'register'], true)) {
            return '';
        }

        return $mode;
    }
}

if (!function_exists('ll_tools_login_window_requested_mode')) {
    function ll_tools_login_window_requested_mode(): string {
        $requested = isset($_GET['ll_tools_auth']) ? wp_unslash((string) $_GET['ll_tools_auth']) : '';
        return ll_tools_login_window_sanitize_auth_mode($requested);
    }
}

if (!function_exists('ll_tools_login_window_sanitize_screen_mode')) {
    function ll_tools_login_window_sanitize_screen_mode($mode): string {
        $mode = sanitize_key((string) $mode);
        if (!in_array($mode, ['auto', 'combined', 'login', 'register'], true)) {
            return 'auto';
        }

        return $mode;
    }
}

if (!function_exists('ll_tools_login_window_strip_internal_query_args')) {
    function ll_tools_login_window_strip_internal_query_args(string $url): string {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $url = preg_replace('/#.*/', '', $url);
        $url = is_string($url) ? $url : '';
        if ($url === '') {
            return '';
        }

        return (string) remove_query_arg([
            'll_tools_auth',
            'll_tools_auth_feedback',
        ], $url);
    }
}

if (!function_exists('ll_tools_get_frontend_auth_url')) {
    function ll_tools_get_frontend_auth_url(string $url = '', string $mode = 'login'): string {
        $mode = ll_tools_login_window_sanitize_auth_mode($mode);
        if ($mode === '') {
            $mode = 'login';
        }

        $url = trim($url);
        if ($url === '') {
            $url = ll_tools_get_current_request_url();
        }

        $url = ll_tools_login_window_strip_internal_query_args($url);
        if ($url === '') {
            $url = home_url('/');
        }

        return (string) add_query_arg('ll_tools_auth', $mode, $url) . '#ll-tools-auth-window';
    }
}

if (!function_exists('ll_tools_login_window_feedback_storage_key')) {
    function ll_tools_login_window_feedback_storage_key(string $token): string {
        return 'll_tools_auth_feedback_' . $token;
    }
}

if (!function_exists('ll_tools_login_window_sanitize_feedback_token')) {
    function ll_tools_login_window_sanitize_feedback_token($token): string {
        $token = strtolower((string) $token);
        $token = preg_replace('/[^a-z0-9]/', '', $token);
        return substr((string) $token, 0, 40);
    }
}

if (!function_exists('ll_tools_login_window_store_feedback')) {
    function ll_tools_login_window_store_feedback(array $feedback): string {
        $token = ll_tools_login_window_sanitize_feedback_token(wp_generate_password(24, false, false));
        if ($token === '') {
            return '';
        }

        set_transient(ll_tools_login_window_feedback_storage_key($token), $feedback, 10 * MINUTE_IN_SECONDS);
        return $token;
    }
}

if (!function_exists('ll_tools_login_window_append_feedback_to_url')) {
    function ll_tools_login_window_append_feedback_to_url(string $url, array $feedback, string $mode = 'login'): string {
        $token = ll_tools_login_window_store_feedback($feedback);
        if ($token === '') {
            return ll_tools_get_frontend_auth_url($url, $mode);
        }

        $auth_url = ll_tools_get_frontend_auth_url($url, $mode);
        return (string) add_query_arg('ll_tools_auth_feedback', $token, $auth_url);
    }
}

if (!function_exists('ll_tools_login_window_consume_feedback_from_request')) {
    function ll_tools_login_window_consume_feedback_from_request(): array {
        $raw_token = isset($_GET['ll_tools_auth_feedback']) ? wp_unslash((string) $_GET['ll_tools_auth_feedback']) : '';
        $token = ll_tools_login_window_sanitize_feedback_token($raw_token);
        if ($token === '') {
            return [];
        }

        $key = ll_tools_login_window_feedback_storage_key($token);
        $payload = get_transient($key);
        delete_transient($key);

        if (!is_array($payload)) {
            return [];
        }

        return $payload;
    }
}

if (!function_exists('ll_tools_get_auth_redirect_target')) {
    function ll_tools_get_auth_redirect_target($requested_redirect = ''): string {
        $requested = ll_tools_get_valid_login_redirect_request($requested_redirect);
        if ($requested !== '') {
            return $requested;
        }

        $referer = wp_get_referer();
        if (is_string($referer) && $referer !== '') {
            $referer = ll_tools_get_valid_login_redirect_request($referer);
            if ($referer !== '') {
                return $referer;
            }
        }

        return home_url('/');
    }
}

if (!function_exists('ll_tools_login_window_trimmed_identifier')) {
    function ll_tools_login_window_trimmed_identifier($value): string {
        return trim(sanitize_text_field(wp_unslash((string) $value)));
    }
}

if (!function_exists('ll_tools_login_window_username_base_from_email')) {
    function ll_tools_login_window_username_base_from_email(string $email): string {
        $email = sanitize_email($email);
        $local_part = $email;

        if (strpos($email, '@') !== false) {
            $parts = explode('@', $email, 2);
            $local_part = (string) ($parts[0] ?? '');
        }

        $base = strtolower(remove_accents($local_part));
        $base = sanitize_user($base, true);
        $base = preg_replace('/[^a-z0-9]+/', '', $base);
        $base = is_string($base) ? $base : '';
        $base = trim($base);

        return substr($base, 0, 50);
    }
}

if (!function_exists('ll_tools_login_window_username_candidate')) {
    function ll_tools_login_window_username_candidate(string $base, int $suffix = 0): string {
        $suffix_text = $suffix > 0 ? (string) $suffix : '';
        $max_length = 60 - strlen($suffix_text);
        $max_length = max(1, $max_length);

        $base = substr($base, 0, $max_length);
        $candidate = $base . $suffix_text;

        return sanitize_user($candidate, true);
    }
}

if (!function_exists('ll_tools_login_window_available_username')) {
    function ll_tools_login_window_available_username(string $seed): string {
        $base = sanitize_user($seed, true);
        if ($base === '') {
            $base = 'u';
        }

        $suffix = 0;
        while ($suffix < 5000) {
            $candidate = ll_tools_login_window_username_candidate($base, $suffix);
            if ($candidate !== '' && validate_username($candidate) && !username_exists($candidate)) {
                return $candidate;
            }
            $suffix++;
        }

        return ll_tools_login_window_username_candidate($base, wp_rand(5001, 9999));
    }
}

if (!function_exists('ll_tools_login_window_available_username_from_email')) {
    function ll_tools_login_window_available_username_from_email(string $email): string {
        $base = ll_tools_login_window_username_base_from_email($email);
        if ($base === '') {
            return '';
        }

        return ll_tools_login_window_available_username($base);
    }
}

if (!function_exists('ll_tools_login_window_normalize_ip')) {
    function ll_tools_login_window_normalize_ip($candidate): string {
        $candidate = trim((string) $candidate);
        if ($candidate === '') {
            return '';
        }

        if (strpos($candidate, ',') !== false) {
            $parts = explode(',', $candidate);
            foreach ($parts as $part) {
                $normalized = ll_tools_login_window_normalize_ip($part);
                if ($normalized !== '') {
                    return $normalized;
                }
            }

            return '';
        }

        $candidate = trim($candidate, "[] \t\n\r\0\x0B");
        if (substr_count($candidate, ':') === 1 && strpos($candidate, '.') !== false) {
            $segments = explode(':', $candidate, 2);
            if (count($segments) === 2 && filter_var($segments[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $candidate = $segments[0];
            }
        }

        return filter_var($candidate, FILTER_VALIDATE_IP) ? $candidate : '';
    }
}

if (!function_exists('ll_tools_login_window_get_client_ip')) {
    function ll_tools_login_window_get_client_ip(): string {
        $candidates = apply_filters('ll_tools_registration_ip_candidates', [
            isset($_SERVER['REMOTE_ADDR']) ? wp_unslash((string) $_SERVER['REMOTE_ADDR']) : '',
        ]);

        foreach ((array) $candidates as $candidate) {
            $normalized = ll_tools_login_window_normalize_ip($candidate);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return '';
    }
}

if (!function_exists('ll_tools_login_window_registration_attempt_limit_config')) {
    function ll_tools_login_window_registration_attempt_limit_config(): array {
        return [
            'limit' => max(0, (int) apply_filters('ll_tools_registration_ip_attempt_limit', 10)),
            'window' => max(MINUTE_IN_SECONDS, (int) apply_filters('ll_tools_registration_ip_attempt_window', 15 * MINUTE_IN_SECONDS)),
        ];
    }
}

if (!function_exists('ll_tools_login_window_registration_attempt_key')) {
    function ll_tools_login_window_registration_attempt_key(string $ip): string {
        return 'll_tools_reg_attempt_' . substr(md5($ip), 0, 24);
    }
}

if (!function_exists('ll_tools_login_window_get_registration_rate_limit_status')) {
    function ll_tools_login_window_get_registration_rate_limit_status(string $ip = ''): array {
        if ($ip === '') {
            $ip = ll_tools_login_window_get_client_ip();
        }

        $config = ll_tools_login_window_registration_attempt_limit_config();
        if ($ip === '' || $config['limit'] <= 0) {
            return [
                'limited' => false,
                'attempts' => 0,
                'limit' => $config['limit'],
                'window' => $config['window'],
                'ip' => $ip,
            ];
        }

        $attempts = (int) get_transient(ll_tools_login_window_registration_attempt_key($ip));

        return [
            'limited' => ($attempts >= $config['limit']),
            'attempts' => $attempts,
            'limit' => $config['limit'],
            'window' => $config['window'],
            'ip' => $ip,
        ];
    }
}

if (!function_exists('ll_tools_login_window_record_registration_attempt')) {
    function ll_tools_login_window_record_registration_attempt(string $ip = ''): void {
        if ($ip === '') {
            $ip = ll_tools_login_window_get_client_ip();
        }

        $config = ll_tools_login_window_registration_attempt_limit_config();
        if ($ip === '' || $config['limit'] <= 0) {
            return;
        }

        $key = ll_tools_login_window_registration_attempt_key($ip);
        $attempts = (int) get_transient($key);
        set_transient($key, $attempts + 1, $config['window']);
    }
}

if (!function_exists('ll_tools_login_window_reset_registration_attempts')) {
    function ll_tools_login_window_reset_registration_attempts(string $ip = ''): void {
        if ($ip === '') {
            $ip = ll_tools_login_window_get_client_ip();
        }

        if ($ip === '') {
            return;
        }

        delete_transient(ll_tools_login_window_registration_attempt_key($ip));
    }
}

if (!function_exists('ll_tools_login_window_registration_rate_limit_message')) {
    function ll_tools_login_window_registration_rate_limit_message(): string {
        return __('Too many sign-up attempts from this connection. Please try again in a few minutes.', 'll-tools-text-domain');
    }
}

if (!function_exists('ll_tools_login_window_blocked_email_domains')) {
    function ll_tools_login_window_blocked_email_domains(): array {
        $domains = [
            '10minutemail.com',
            '10minutemail.net',
            'dispostable.com',
            'dropmail.me',
            'emailondeck.com',
            'fakeinbox.com',
            'getnada.com',
            'grr.la',
            'guerrillamail.com',
            'guerrillamailblock.com',
            'maildrop.cc',
            'mailinator.com',
            'mintemail.com',
            'moakt.com',
            'mytemp.email',
            'sharklasers.com',
            'temp-mail.org',
            'tempmail.com',
            'tempmail.plus',
            'throwawaymail.com',
            'tmpmail.org',
            'trashmail.com',
            'trashmail.de',
            'yopmail.com',
            'yopmail.fr',
            'yopmail.net',
        ];

        $domains = apply_filters('ll_tools_registration_blocked_email_domains', $domains);
        $normalized = [];
        foreach ((array) $domains as $domain) {
            $domain = strtolower(trim((string) $domain));
            $domain = ltrim($domain, '.');
            if ($domain !== '') {
                $normalized[$domain] = $domain;
            }
        }

        return array_values($normalized);
    }
}

if (!function_exists('ll_tools_login_window_email_domain')) {
    function ll_tools_login_window_email_domain(string $email): string {
        $email = sanitize_email($email);
        $parts = explode('@', $email, 2);
        if (count($parts) !== 2) {
            return '';
        }

        return strtolower(trim((string) $parts[1]));
    }
}

if (!function_exists('ll_tools_login_window_is_blocked_email')) {
    function ll_tools_login_window_is_blocked_email(string $email): bool {
        $domain = ll_tools_login_window_email_domain($email);
        if ($domain === '') {
            return false;
        }

        $blocked = false;
        foreach (ll_tools_login_window_blocked_email_domains() as $blocked_domain) {
            $suffix = '.' . $blocked_domain;
            if ($domain === $blocked_domain || substr($domain, -strlen($suffix)) === $suffix) {
                $blocked = true;
                break;
            }
        }

        return (bool) apply_filters('ll_tools_registration_is_blocked_email', $blocked, $email, $domain);
    }
}

if (!function_exists('ll_tools_login_window_normalize_registration_email_input')) {
    function ll_tools_login_window_normalize_registration_email_input($value): string {
        return trim(wp_unslash((string) $value));
    }
}

if (!function_exists('ll_tools_login_window_email_has_template_tokens')) {
    function ll_tools_login_window_email_has_template_tokens(string $email): bool {
        $parts = explode('@', $email, 2);
        $local_part = (string) ($parts[0] ?? '');
        if ($local_part === '' || strpos($local_part, '%') === false) {
            return false;
        }

        $has_tokens = (bool) preg_match('/%[a-z0-9][a-z0-9._-]*%/i', $local_part);
        return (bool) apply_filters('ll_tools_registration_email_has_template_tokens', $has_tokens, $email, $local_part);
    }
}

if (!function_exists('ll_tools_login_window_validate_registration_email')) {
    function ll_tools_login_window_validate_registration_email($value, bool $check_existing = true): array {
        $raw_email = ll_tools_login_window_normalize_registration_email_input($value);
        $email = sanitize_email($raw_email);
        $errors = [];

        if (
            $raw_email === ''
            || $email === ''
            || $raw_email !== $email
            || !is_email($raw_email)
            || ll_tools_login_window_email_has_template_tokens($raw_email)
        ) {
            $errors[] = __('Please enter a valid email address.', 'll-tools-text-domain');

            return [
                'raw_email' => $raw_email,
                'email' => '',
                'errors' => $errors,
                'is_blocked' => false,
            ];
        }

        $is_blocked = ll_tools_login_window_is_blocked_email($email);
        if ($is_blocked) {
            $errors[] = __('Please use a non-temporary email address.', 'll-tools-text-domain');
        } elseif ($check_existing && email_exists($email)) {
            $errors[] = __('That email is already registered.', 'll-tools-text-domain');
        }

        return [
            'raw_email' => $raw_email,
            'email' => $email,
            'errors' => $errors,
            'is_blocked' => $is_blocked,
        ];
    }
}

if (!function_exists('ll_tools_filter_core_registration_email_errors')) {
    function ll_tools_filter_core_registration_email_errors($errors, $sanitized_user_login, $user_email) {
        if (!$errors instanceof WP_Error) {
            return $errors;
        }

        $validation = ll_tools_login_window_validate_registration_email($user_email, false);
        if ($validation['email'] !== '' && !empty($validation['is_blocked'])) {
            $errors->add('ll_tools_blocked_email', __('Please use a non-temporary email address.', 'll-tools-text-domain'));
        }

        return $errors;
    }
}
add_filter('registration_errors', 'll_tools_filter_core_registration_email_errors', 20, 3);

if (!function_exists('ll_tools_login_window_sign_registration_challenge')) {
    function ll_tools_login_window_sign_registration_challenge(int $timestamp, int $left, int $right): string {
        return wp_hash($timestamp . '|' . $left . '|' . $right, 'nonce');
    }
}

if (!function_exists('ll_tools_login_window_validate_registration_challenge')) {
    function ll_tools_login_window_validate_registration_challenge(array $request): array {
        $errors = [];

        $honeypot = isset($request['ll_tools_register_website'])
            ? trim((string) wp_unslash($request['ll_tools_register_website']))
            : '';
        if ($honeypot !== '') {
            $errors[] = __('Bot protection check failed. Please try again.', 'll-tools-text-domain');
            return $errors;
        }

        $timestamp = isset($request['ll_tools_register_rendered_at'])
            ? (int) wp_unslash((string) $request['ll_tools_register_rendered_at'])
            : 0;
        $left = isset($request['ll_tools_register_math_left'])
            ? (int) wp_unslash((string) $request['ll_tools_register_math_left'])
            : 0;
        $right = isset($request['ll_tools_register_math_right'])
            ? (int) wp_unslash((string) $request['ll_tools_register_math_right'])
            : 0;
        $signature = isset($request['ll_tools_register_math_signature'])
            ? (string) wp_unslash($request['ll_tools_register_math_signature'])
            : '';

        $expected_signature = ll_tools_login_window_sign_registration_challenge($timestamp, $left, $right);
        if ($timestamp <= 0 || $left <= 0 || $right <= 0 || $signature === '' || !hash_equals($expected_signature, $signature)) {
            $errors[] = __('Registration security check failed. Please refresh and try again.', 'll-tools-text-domain');
            return $errors;
        }

        $elapsed = time() - $timestamp;
        $min_seconds = max(2, (int) apply_filters('ll_tools_registration_min_fill_seconds', 3));
        $max_seconds = max(60, (int) apply_filters('ll_tools_registration_max_fill_seconds', 30 * MINUTE_IN_SECONDS));

        if ($elapsed < $min_seconds) {
            $errors[] = __('Please wait a moment before submitting the form.', 'll-tools-text-domain');
        } elseif ($elapsed > $max_seconds) {
            $errors[] = __('That sign up form expired. Please try again.', 'll-tools-text-domain');
        }

        $answer = isset($request['ll_tools_register_math_answer'])
            ? (int) wp_unslash((string) $request['ll_tools_register_math_answer'])
            : null;
        if ($answer !== ($left + $right)) {
            $errors[] = __('Please solve the math question.', 'll-tools-text-domain');
        }

        $external_result = apply_filters('ll_tools_validate_registration_bot_protection', true, $request);
        if (is_wp_error($external_result)) {
            $errors = array_merge($errors, array_values(array_map('strval', $external_result->get_error_messages())));
        } elseif ($external_result === false) {
            $errors[] = __('Bot protection check failed. Please try again.', 'll-tools-text-domain');
        }

        return array_values(array_filter(array_map('strval', $errors)));
    }
}

if (!function_exists('ll_tools_login_window_resolve_login_identifier')) {
    function ll_tools_login_window_resolve_login_identifier(string $identifier): string {
        if ($identifier !== '' && strpos($identifier, '@') !== false && is_email($identifier)) {
            $user = get_user_by('email', $identifier);
            if ($user instanceof WP_User && !empty($user->user_login)) {
                return (string) $user->user_login;
            }
        }

        return sanitize_user($identifier, true);
    }
}

if (!function_exists('ll_tools_handle_frontend_login')) {
    function ll_tools_handle_frontend_login(): void {
        $raw_redirect = isset($_POST['redirect_to']) ? wp_unslash((string) $_POST['redirect_to']) : '';
        $redirect_to = ll_tools_get_auth_redirect_target($raw_redirect);

        if (is_user_logged_in()) {
            wp_safe_redirect($redirect_to);
            exit;
        }

        $nonce = isset($_POST['ll_tools_login_nonce']) ? wp_unslash((string) $_POST['ll_tools_login_nonce']) : '';
        if (!wp_verify_nonce($nonce, 'll_tools_login')) {
            $redirect_to = ll_tools_login_window_append_feedback_to_url($redirect_to, [
                'type' => 'error',
                'form' => 'login',
                'messages' => [__('Login security check failed. Please try again.', 'll-tools-text-domain')],
            ], 'login');
            wp_safe_redirect($redirect_to);
            exit;
        }

        $identifier = isset($_POST['log']) ? ll_tools_login_window_trimmed_identifier($_POST['log']) : '';
        $password = isset($_POST['pwd']) ? (string) wp_unslash($_POST['pwd']) : '';
        $remember = !empty($_POST['rememberme']);

        $errors = [];
        if ($identifier === '') {
            $errors[] = __('Please enter your username or email address.', 'll-tools-text-domain');
        }
        if ($password === '') {
            $errors[] = __('Please enter your password.', 'll-tools-text-domain');
        }

        if (!empty($errors)) {
            $redirect_to = ll_tools_login_window_append_feedback_to_url($redirect_to, [
                'type' => 'error',
                'form' => 'login',
                'messages' => $errors,
                'prefill' => [
                    'login_identifier' => $identifier,
                    'login_remember' => $remember ? '1' : '0',
                ],
            ], 'login');
            wp_safe_redirect($redirect_to);
            exit;
        }

        $user = wp_signon([
            'user_login' => ll_tools_login_window_resolve_login_identifier($identifier),
            'user_password' => $password,
            'remember' => $remember,
        ], is_ssl());

        if (is_wp_error($user)) {
            $messages = array_values(array_filter(array_map('strval', $user->get_error_messages())));
            if (empty($messages)) {
                $messages = [__('Unable to sign you in right now. Please try again.', 'll-tools-text-domain')];
            }

            $redirect_to = ll_tools_login_window_append_feedback_to_url($redirect_to, [
                'type' => 'error',
                'form' => 'login',
                'messages' => $messages,
                'prefill' => [
                    'login_identifier' => $identifier,
                    'login_remember' => $remember ? '1' : '0',
                ],
            ], 'login');
            wp_safe_redirect($redirect_to);
            exit;
        }

        $requested_redirect = ll_tools_get_valid_login_redirect_request($raw_redirect);
        $final_redirect = apply_filters(
            'login_redirect',
            $requested_redirect !== '' ? $requested_redirect : admin_url(),
            $raw_redirect,
            $user
        );
        $final_redirect = (string) wp_validate_redirect((string) $final_redirect, home_url('/'));

        wp_safe_redirect($final_redirect);
        exit;
    }
}
add_action('admin_post_nopriv_ll_tools_login', 'll_tools_handle_frontend_login');
add_action('admin_post_ll_tools_login', 'll_tools_handle_frontend_login');

if (!function_exists('ll_tools_handle_frontend_learner_registration')) {
    function ll_tools_handle_frontend_learner_registration(): void {
        $raw_redirect = isset($_POST['redirect_to']) ? wp_unslash((string) $_POST['redirect_to']) : '';
        $redirect_to = ll_tools_get_auth_redirect_target($raw_redirect);

        if (is_user_logged_in()) {
            wp_safe_redirect($redirect_to);
            exit;
        }

        if (!ll_tools_is_learner_self_registration_available()) {
            $redirect_to = ll_tools_login_window_append_feedback_to_url($redirect_to, [
                'type' => 'error',
                'form' => 'register',
                'messages' => [__('New account registration is currently disabled.', 'll-tools-text-domain')],
            ], 'register');
            wp_safe_redirect($redirect_to);
            exit;
        }

        $nonce = isset($_POST['ll_tools_register_learner_nonce']) ? wp_unslash((string) $_POST['ll_tools_register_learner_nonce']) : '';
        if (!wp_verify_nonce($nonce, 'll_tools_register_learner')) {
            $redirect_to = ll_tools_login_window_append_feedback_to_url($redirect_to, [
                'type' => 'error',
                'form' => 'register',
                'messages' => [__('Registration security check failed. Please try again.', 'll-tools-text-domain')],
            ], 'register');
            wp_safe_redirect($redirect_to);
            exit;
        }

        $request_ip = ll_tools_login_window_get_client_ip();
        $rate_limit_status = ll_tools_login_window_get_registration_rate_limit_status($request_ip);
        if (!empty($rate_limit_status['limited'])) {
            $redirect_to = ll_tools_login_window_append_feedback_to_url($redirect_to, [
                'type' => 'error',
                'form' => 'register',
                'messages' => [ll_tools_login_window_registration_rate_limit_message()],
            ], 'register');
            wp_safe_redirect($redirect_to);
            exit;
        }
        ll_tools_login_window_record_registration_attempt($request_ip);

        $email_validation = ll_tools_login_window_validate_registration_email($_POST['user_email'] ?? '');
        $email = $email_validation['email'];
        $raw_username = isset($_POST['user_login'])
            ? sanitize_user(wp_unslash((string) $_POST['user_login']), true)
            : '';
        $password = isset($_POST['user_pass'])
            ? (string) wp_unslash($_POST['user_pass'])
            : '';
        $remember = !empty($_POST['rememberme']);
        $username_is_custom = isset($_POST['ll_tools_register_username_is_custom'])
            && ((string) wp_unslash($_POST['ll_tools_register_username_is_custom']) === '1');

        $errors = ll_tools_login_window_validate_registration_challenge($_POST);
        $errors = array_merge($errors, $email_validation['errors']);

        $username = $raw_username;
        if ($email !== '') {
            if ($username === '') {
                $username = ll_tools_login_window_available_username_from_email($email);
            } elseif (!$username_is_custom && (!validate_username($username) || username_exists($username))) {
                $username = ll_tools_login_window_available_username($username);
            }
        }

        if ($username === '') {
            $errors[] = __('Please choose a username.', 'll-tools-text-domain');
        } elseif (!validate_username($username)) {
            $errors[] = __('That username is not valid.', 'll-tools-text-domain');
        } elseif (username_exists($username)) {
            $errors[] = __('That username is already taken.', 'll-tools-text-domain');
        }

        if ($password === '') {
            $errors[] = __('Please enter a password.', 'll-tools-text-domain');
        } elseif (strlen($password) < 8) {
            $errors[] = __('Use at least 8 characters for your password.', 'll-tools-text-domain');
        }

        if (!empty($errors)) {
            $redirect_to = ll_tools_login_window_append_feedback_to_url($redirect_to, [
                'type' => 'error',
                'form' => 'register',
                'messages' => array_values(array_map('strval', $errors)),
                'prefill' => [
                    'username' => $username,
                    'email' => $email,
                    'register_remember' => $remember ? '1' : '0',
                    'username_is_custom' => $username_is_custom ? '1' : '0',
                ],
            ], 'register');
            wp_safe_redirect($redirect_to);
            exit;
        }

        if (function_exists('ll_tools_register_or_refresh_learner_role')) {
            ll_tools_register_or_refresh_learner_role();
        }

        $insert_args = [
            'user_login' => $username,
            'user_email' => $email,
            'user_pass' => $password,
            'display_name' => $username,
            'role' => 'll_tools_learner',
        ];
        $user_id = wp_insert_user($insert_args);

        if (is_wp_error($user_id) && !$username_is_custom && $user_id->get_error_code() === 'existing_user_login') {
            $insert_args['user_login'] = ll_tools_login_window_available_username($username);
            $user_id = wp_insert_user($insert_args);
        }

        if (is_wp_error($user_id)) {
            $messages = $user_id->get_error_messages();
            if (empty($messages)) {
                $messages = [__('Unable to create the account right now.', 'll-tools-text-domain')];
            }

            $redirect_to = ll_tools_login_window_append_feedback_to_url($redirect_to, [
                'type' => 'error',
                'form' => 'register',
                'messages' => array_values(array_map('strval', $messages)),
                'prefill' => [
                    'username' => $insert_args['user_login'],
                    'email' => $email,
                    'register_remember' => $remember ? '1' : '0',
                    'username_is_custom' => $username_is_custom ? '1' : '0',
                ],
            ], 'register');
            wp_safe_redirect($redirect_to);
            exit;
        }

        $user_id = (int) $user_id;
        $user = get_user_by('id', $user_id);
        if ($user instanceof WP_User) {
            $user->set_role('ll_tools_learner');
        }

        ll_tools_maybe_send_registration_admin_notification($user_id);

        $signed_in_user = wp_signon([
            'user_login' => $insert_args['user_login'],
            'user_password' => $password,
            'remember' => $remember,
        ], is_ssl());

        if (!is_wp_error($signed_in_user) && $signed_in_user instanceof WP_User) {
            $requested_redirect = ll_tools_get_valid_login_redirect_request($raw_redirect);
            $final_redirect = apply_filters(
                'login_redirect',
                $requested_redirect !== '' ? $requested_redirect : admin_url(),
                $raw_redirect,
                $signed_in_user
            );
            $redirect_to = (string) wp_validate_redirect((string) $final_redirect, home_url('/'));
        }

        wp_safe_redirect($redirect_to);
        exit;
    }
}
add_action('admin_post_nopriv_ll_tools_register_learner', 'll_tools_handle_frontend_learner_registration');
add_action('admin_post_ll_tools_register_learner', 'll_tools_handle_frontend_learner_registration');

if (!function_exists('ll_tools_login_window_username_suggestion_ajax')) {
    function ll_tools_login_window_username_suggestion_ajax(): void {
        check_ajax_referer('ll_tools_suggest_learner_username', 'nonce');

        if (!ll_tools_is_learner_self_registration_available()) {
            wp_send_json_error(['message' => __('New account registration is currently disabled.', 'll-tools-text-domain')], 403);
        }

        $email_validation = ll_tools_login_window_validate_registration_email($_POST['email'] ?? '', false);
        if (!empty($email_validation['errors'])) {
            wp_send_json_error(['message' => $email_validation['errors'][0]], 400);
        }

        wp_send_json_success([
            'username' => ll_tools_login_window_available_username_from_email($email_validation['email']),
        ]);
    }
}
add_action('wp_ajax_nopriv_ll_tools_suggest_learner_username', 'll_tools_login_window_username_suggestion_ajax');
add_action('wp_ajax_ll_tools_suggest_learner_username', 'll_tools_login_window_username_suggestion_ajax');

if (!function_exists('ll_tools_enqueue_login_window_assets')) {
    function ll_tools_enqueue_login_window_assets(): void {
        ll_enqueue_asset_by_timestamp('/css/login-window.css', 'll-tools-login-window');
        ll_enqueue_asset_by_timestamp('/js/login-window.js', 'll-tools-login-window-js', [], true);

        if (wp_script_is('ll-tools-login-window-js', 'registered')) {
            wp_localize_script('ll-tools-login-window-js', 'llToolsLoginWindow', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'suggestUsernameNonce' => wp_create_nonce('ll_tools_suggest_learner_username'),
            ]);
        }
    }
}

if (!function_exists('ll_tools_render_login_window_notice')) {
    function ll_tools_render_login_window_notice(array $messages, string $type = 'error'): string {
        $messages = array_values(array_filter(array_map('strval', $messages)));
        if (empty($messages)) {
            return '';
        }

        ob_start();
        ?>
        <div class="ll-tools-login-window__notice ll-tools-login-window__notice--<?php echo esc_attr($type === 'success' ? 'success' : 'error'); ?>" role="alert">
            <?php if (count($messages) === 1): ?>
                <p><?php echo esc_html($messages[0]); ?></p>
            <?php else: ?>
                <ul>
                    <?php foreach ($messages as $message): ?>
                        <li><?php echo esc_html($message); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        <?php

        return (string) ob_get_clean();
    }
}

if (!function_exists('ll_tools_render_login_window')) {
    function ll_tools_render_login_window(array $args = []): string {
        $defaults = [
            'container_class' => '',
            'title' => __('Sign in', 'll-tools-text-domain'),
            'message' => __('Sign in to continue.', 'll-tools-text-domain'),
            'submit_label' => __('Log in', 'll-tools-text-domain'),
            'screen_mode' => 'auto',
            'redirect_to' => '',
            'show_lost_password' => true,
            'show_registration' => false,
            'registration_title' => __('Create account', 'll-tools-text-domain'),
            'registration_message' => '',
            'registration_submit_label' => __('Create account', 'll-tools-text-domain'),
            'registration_disabled_message' => __('New account registration is currently disabled.', 'll-tools-text-domain'),
        ];
        $args = wp_parse_args($args, $defaults);

        if (!did_action('wp_head') && function_exists('ll_tools_enqueue_login_window_assets')) {
            ll_tools_enqueue_login_window_assets();
        }

        $redirect_to = trim((string) $args['redirect_to']);
        if ($redirect_to === '') {
            $redirect_to = ll_tools_get_current_request_url();
        }
        $redirect_to = ll_tools_login_window_strip_internal_query_args($redirect_to);
        if ($redirect_to === '') {
            $redirect_to = home_url('/');
        }
        $redirect_to = esc_url_raw($redirect_to);

        $suffix = substr(md5($redirect_to . '|' . (string) $args['title']), 0, 8);
        $show_registration = !empty($args['show_registration']);
        $feedback = ll_tools_login_window_consume_feedback_from_request();
        $feedback_messages = [];
        if (isset($feedback['messages']) && is_array($feedback['messages'])) {
            foreach ($feedback['messages'] as $message) {
                $message = trim((string) $message);
                if ($message !== '') {
                    $feedback_messages[] = $message;
                }
            }
        }
        $feedback_type = (isset($feedback['type']) && $feedback['type'] === 'success') ? 'success' : 'error';
        $feedback_form = ll_tools_login_window_sanitize_auth_mode($feedback['form'] ?? '');
        $prefill = (isset($feedback['prefill']) && is_array($feedback['prefill'])) ? $feedback['prefill'] : [];
        $requested_mode = ll_tools_login_window_requested_mode();
        $registration_enabled = $show_registration && ll_tools_is_learner_self_registration_available();
        $registration_disabled = $show_registration && !$registration_enabled;
        $supports_registration_screen = $registration_enabled || $registration_disabled;
        $requested_screen_mode = ll_tools_login_window_sanitize_screen_mode($args['screen_mode'] ?? 'auto');
        $explicit_mode = $feedback_form !== '' ? $feedback_form : $requested_mode;

        if ($requested_screen_mode === 'auto') {
            if ($explicit_mode === 'register' && $supports_registration_screen) {
                $screen_mode = 'register';
            } elseif ($explicit_mode === 'login') {
                $screen_mode = 'login';
            } else {
                $screen_mode = 'combined';
            }
        } else {
            $screen_mode = $requested_screen_mode;
        }

        if ($screen_mode === 'register' && !$supports_registration_screen) {
            $screen_mode = 'login';
        }
        if ($screen_mode === 'combined' && !$show_registration) {
            $screen_mode = 'login';
        }

        $show_login_screen = ($screen_mode !== 'register');
        $show_registration_screen = ($screen_mode !== 'login');
        $show_auth_divider = ($screen_mode === 'combined') && ($registration_enabled || $registration_disabled);
        $show_registration_intro = $registration_enabled && ($screen_mode === 'combined');
        $active_mode = ($screen_mode === 'register' && $supports_registration_screen) ? 'register' : 'login';

        $login_feedback = ($feedback_form === 'login') ? $feedback_messages : [];
        $registration_feedback = ($feedback_form === 'register') ? $feedback_messages : [];

        $registration_title = trim((string) $args['registration_title']);
        $registration_message = trim((string) $args['registration_message']);
        $registration_submit_label = trim((string) $args['registration_submit_label']);
        if ($registration_submit_label === '') {
            $registration_submit_label = __('Create account', 'll-tools-text-domain');
        }
        $registration_disabled_message = trim((string) $args['registration_disabled_message']);
        if ($registration_disabled_message === '') {
            $registration_disabled_message = __('New account registration is currently disabled.', 'll-tools-text-domain');
        }
        $show_generated_registration_password = ll_tools_is_generated_registration_password_visible();

        $login_form_id = 'll-tools-login-form-' . $suffix;
        $login_identifier_id = 'll-tools-user-login-' . $suffix;
        $login_password_id = 'll-tools-user-pass-' . $suffix;
        $login_remember_id = 'll-tools-user-remember-' . $suffix;

        $registration_form_id = 'll-tools-register-form-' . $suffix;
        $registration_email_id = 'll-tools-register-email-' . $suffix;
        $registration_username_id = 'll-tools-register-username-' . $suffix;
        $registration_password_id = 'll-tools-register-password-' . $suffix;
        $registration_remember_id = 'll-tools-register-remember-' . $suffix;
        $registration_math_id = 'll-tools-register-math-' . $suffix;
        $registration_honeypot_id = 'll-tools-register-website-' . $suffix;

        $prefill_login_identifier = isset($prefill['login_identifier'])
            ? ll_tools_login_window_trimmed_identifier($prefill['login_identifier'])
            : '';
        $prefill_login_remember = !isset($prefill['login_remember']) || ((string) $prefill['login_remember'] !== '0');
        $prefill_username = isset($prefill['username']) ? sanitize_user((string) $prefill['username'], true) : '';
        $prefill_email = isset($prefill['email']) ? sanitize_email((string) $prefill['email']) : '';
        $prefill_register_remember = !isset($prefill['register_remember']) || ((string) $prefill['register_remember'] !== '0');
        $prefill_username_is_custom = isset($prefill['username_is_custom']) && ((string) $prefill['username_is_custom'] === '1');

        $challenge_left = random_int(1, 5);
        $challenge_right = random_int(1, 5);
        $challenge_timestamp = time();
        $challenge_signature = ll_tools_login_window_sign_registration_challenge(
            $challenge_timestamp,
            $challenge_left,
            $challenge_right
        );
        $registration_extra_fields = '';
        if ($registration_enabled) {
            $registration_extra_fields = (string) apply_filters('ll_tools_registration_extra_fields_markup', '', [
                'form_id' => $registration_form_id,
                'redirect_to' => $redirect_to,
            ]);
        }

        $container_class = ll_tools_login_window_class_string((string) $args['container_class']);
        $title = trim((string) $args['title']);
        $message = trim((string) $args['message']);
        if ($screen_mode === 'register') {
            if ($registration_title !== '') {
                $title = $registration_title;
            }
            $message = $registration_message;
        }
        $show_lost_password = !empty($args['show_lost_password']);
        $lost_password_url = wp_lostpassword_url($redirect_to);
        $login_screen_url = function_exists('ll_tools_get_frontend_auth_url')
            ? ll_tools_get_frontend_auth_url($redirect_to, 'login')
            : wp_login_url($redirect_to);
        $register_screen_url = ($registration_enabled && function_exists('ll_tools_get_frontend_auth_url'))
            ? ll_tools_get_frontend_auth_url($redirect_to, 'register')
            : '';

        ob_start();
        ?>
        <div id="ll-tools-auth-window" class="<?php echo esc_attr($container_class); ?>" data-ll-tools-login-window="1" data-ll-auth-focus="<?php echo esc_attr($active_mode); ?>">
            <div class="ll-tools-login-window" role="group" aria-label="<?php echo esc_attr($title); ?>">
                <span class="ll-tools-login-window__icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="20" height="20" focusable="false">
                        <path d="M17 10h-1V7a4 4 0 10-8 0v3H7a2 2 0 00-2 2v7a2 2 0 002 2h10a2 2 0 002-2v-7a2 2 0 00-2-2zm-3 0h-4V7a2 2 0 114 0v3zm-2 8a2 2 0 110-4 2 2 0 010 4z"></path>
                    </svg>
                </span>
                <?php if ($title !== ''): ?>
                    <h2 class="ll-tools-login-window__title"><?php echo esc_html($title); ?></h2>
                <?php endif; ?>
                <?php if ($message !== ''): ?>
                    <p class="ll-tools-login-window__message"><?php echo esc_html($message); ?></p>
                <?php endif; ?>

                <?php if ($show_login_screen): ?>
                    <div class="ll-tools-login-window__form" data-ll-auth-section="login">
                        <?php echo ll_tools_render_login_window_notice($login_feedback, $feedback_type); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

                        <form id="<?php echo esc_attr($login_form_id); ?>" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="ll_tools_login" />
                            <input type="hidden" name="redirect_to" value="<?php echo esc_attr($redirect_to); ?>" />
                            <?php wp_nonce_field('ll_tools_login', 'll_tools_login_nonce'); ?>

                            <p>
                                <label for="<?php echo esc_attr($login_identifier_id); ?>"><?php esc_html_e('Username or Email', 'll-tools-text-domain'); ?></label>
                                <input
                                    type="text"
                                    id="<?php echo esc_attr($login_identifier_id); ?>"
                                    name="log"
                                    value="<?php echo esc_attr($prefill_login_identifier); ?>"
                                    autocomplete="username"
                                    required />
                            </p>
                            <p>
                                <label for="<?php echo esc_attr($login_password_id); ?>"><?php esc_html_e('Password', 'll-tools-text-domain'); ?></label>
                                <input
                                    type="password"
                                    id="<?php echo esc_attr($login_password_id); ?>"
                                    name="pwd"
                                    autocomplete="current-password"
                                    required />
                            </p>
                            <p class="login-remember">
                                <input
                                    type="checkbox"
                                    id="<?php echo esc_attr($login_remember_id); ?>"
                                    name="rememberme"
                                    value="forever"
                                    <?php checked($prefill_login_remember); ?> />
                                <label for="<?php echo esc_attr($login_remember_id); ?>"><?php esc_html_e('Keep me signed in', 'll-tools-text-domain'); ?></label>
                            </p>
                            <p class="login-submit">
                                <button type="submit"><?php echo esc_html((string) $args['submit_label']); ?></button>
                            </p>
                        </form>
                    </div>
                <?php endif; ?>

                <?php if ($show_auth_divider): ?>
                    <div class="ll-tools-login-window__divider" role="presentation">
                        <span><?php esc_html_e('or', 'll-tools-text-domain'); ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($registration_enabled && $show_registration_screen): ?>
                    <div class="ll-tools-login-window__register" data-ll-auth-section="register">
                        <?php if ($show_registration_intro && $registration_title !== ''): ?>
                            <h3 class="ll-tools-login-window__register-title"><?php echo esc_html($registration_title); ?></h3>
                        <?php endif; ?>
                        <?php if ($show_registration_intro && $registration_message !== ''): ?>
                            <p class="ll-tools-login-window__register-message"><?php echo esc_html($registration_message); ?></p>
                        <?php endif; ?>

                        <?php echo ll_tools_render_login_window_notice($registration_feedback, $feedback_type); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

                        <form id="<?php echo esc_attr($registration_form_id); ?>" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="ll_tools_register_learner" />
                            <input type="hidden" name="redirect_to" value="<?php echo esc_attr($redirect_to); ?>" />
                            <input type="hidden" name="ll_tools_register_username_is_custom" value="<?php echo esc_attr($prefill_username_is_custom ? '1' : '0'); ?>" data-ll-register-username-custom="1" />
                            <input type="hidden" name="ll_tools_register_rendered_at" value="<?php echo esc_attr((string) $challenge_timestamp); ?>" />
                            <input type="hidden" name="ll_tools_register_math_left" value="<?php echo esc_attr((string) $challenge_left); ?>" />
                            <input type="hidden" name="ll_tools_register_math_right" value="<?php echo esc_attr((string) $challenge_right); ?>" />
                            <input type="hidden" name="ll_tools_register_math_signature" value="<?php echo esc_attr($challenge_signature); ?>" />
                            <?php wp_nonce_field('ll_tools_register_learner', 'll_tools_register_learner_nonce'); ?>

                            <p>
                                <label for="<?php echo esc_attr($registration_email_id); ?>"><?php esc_html_e('Email', 'll-tools-text-domain'); ?></label>
                                <input
                                    type="email"
                                    id="<?php echo esc_attr($registration_email_id); ?>"
                                    name="user_email"
                                    value="<?php echo esc_attr($prefill_email); ?>"
                                    autocomplete="email"
                                    data-ll-register-email="1"
                                    required />
                            </p>
                            <p>
                                <label for="<?php echo esc_attr($registration_username_id); ?>"><?php esc_html_e('Username', 'll-tools-text-domain'); ?></label>
                                <input
                                    type="text"
                                    id="<?php echo esc_attr($registration_username_id); ?>"
                                    name="user_login"
                                    value="<?php echo esc_attr($prefill_username); ?>"
                                    autocomplete="username"
                                    data-ll-register-username="1"
                                    required />
                            </p>
                            <p>
                                <label for="<?php echo esc_attr($registration_password_id); ?>"><?php esc_html_e('Password', 'll-tools-text-domain'); ?></label>
                                <span class="ll-tools-login-window__password-row">
                                    <input
                                        type="<?php echo $show_generated_registration_password ? 'text' : 'password'; ?>"
                                        id="<?php echo esc_attr($registration_password_id); ?>"
                                        name="user_pass"
                                        autocomplete="new-password"
                                        autocapitalize="none"
                                        spellcheck="false"
                                        data-ll-register-password="1"
                                        required />
                                    <button
                                        type="button"
                                        class="ll-tools-login-window__password-toggle"
                                        data-ll-register-password-toggle="1"
                                        data-password-visible="<?php echo $show_generated_registration_password ? '1' : '0'; ?>"
                                        data-show-label="<?php echo esc_attr__('Show', 'll-tools-text-domain'); ?>"
                                        data-hide-label="<?php echo esc_attr__('Hide', 'll-tools-text-domain'); ?>"
                                        aria-controls="<?php echo esc_attr($registration_password_id); ?>"
                                        aria-label="<?php echo esc_attr($show_generated_registration_password ? __('Hide', 'll-tools-text-domain') : __('Show', 'll-tools-text-domain')); ?>"
                                        aria-pressed="<?php echo $show_generated_registration_password ? 'true' : 'false'; ?>">
                                        <span class="ll-tools-login-window__password-toggle-icon ll-tools-login-window__password-toggle-icon--show" aria-hidden="true">
                                            <?php echo ll_tools_get_password_visibility_toggle_icon('show'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                        </span>
                                        <span class="ll-tools-login-window__password-toggle-icon ll-tools-login-window__password-toggle-icon--hide" aria-hidden="true">
                                            <?php echo ll_tools_get_password_visibility_toggle_icon('hide'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                        </span>
                                        <span class="ll-tools-login-window__password-toggle-text">
                                            <?php echo $show_generated_registration_password
                                                ? esc_html__('Hide', 'll-tools-text-domain')
                                                : esc_html__('Show', 'll-tools-text-domain'); ?>
                                        </span>
                                    </button>
                                </span>
                            </p>
                            <p class="login-remember">
                                <input
                                    type="checkbox"
                                    id="<?php echo esc_attr($registration_remember_id); ?>"
                                    name="rememberme"
                                    value="forever"
                                    <?php checked($prefill_register_remember); ?> />
                                <label for="<?php echo esc_attr($registration_remember_id); ?>"><?php esc_html_e('Keep me signed in', 'll-tools-text-domain'); ?></label>
                            </p>
                            <p class="ll-tools-login-window__honeypot" aria-hidden="true">
                                <label for="<?php echo esc_attr($registration_honeypot_id); ?>"><?php esc_html_e('Leave this field empty', 'll-tools-text-domain'); ?></label>
                                <input
                                    type="text"
                                    id="<?php echo esc_attr($registration_honeypot_id); ?>"
                                    name="ll_tools_register_website"
                                    value=""
                                    tabindex="-1"
                                    autocomplete="off" />
                            </p>
                            <p>
                                <label for="<?php echo esc_attr($registration_math_id); ?>">
                                    <?php echo esc_html($challenge_left . ' + ' . $challenge_right . ' ='); ?>
                                </label>
                                <input
                                    type="number"
                                    id="<?php echo esc_attr($registration_math_id); ?>"
                                    name="ll_tools_register_math_answer"
                                    inputmode="numeric"
                                    min="0"
                                    required />
                            </p>
                            <?php if ($registration_extra_fields !== ''): ?>
                                <?php echo wp_kses_post($registration_extra_fields); ?>
                            <?php endif; ?>
                            <p class="ll-tools-login-window__register-submit">
                                <button type="submit"><?php echo esc_html($registration_submit_label); ?></button>
                            </p>
                        </form>
                    </div>
                <?php elseif ($registration_disabled && $show_registration_screen): ?>
                    <p class="ll-tools-login-window__assist ll-tools-login-window__assist--muted">
                        <?php echo esc_html($registration_disabled_message); ?>
                    </p>
                <?php endif; ?>

                <?php if ($screen_mode === 'login' && $register_screen_url !== ''): ?>
                    <p class="ll-tools-login-window__assist ll-tools-login-window__assist--muted">
                        <?php esc_html_e('Need an account?', 'll-tools-text-domain'); ?>
                        <a href="<?php echo esc_url($register_screen_url); ?>">
                            <?php esc_html_e('Sign up', 'll-tools-text-domain'); ?>
                        </a>
                    </p>
                <?php elseif ($screen_mode === 'register' && $login_screen_url !== ''): ?>
                    <p class="ll-tools-login-window__assist ll-tools-login-window__assist--muted">
                        <?php esc_html_e('Already have an account?', 'll-tools-text-domain'); ?>
                        <a href="<?php echo esc_url($login_screen_url); ?>">
                            <?php esc_html_e('Log in', 'll-tools-text-domain'); ?>
                        </a>
                    </p>
                <?php endif; ?>

                <?php if ($show_lost_password): ?>
                    <p class="ll-tools-login-window__assist">
                        <a href="<?php echo esc_url($lost_password_url); ?>">
                            <?php esc_html_e('Forgot password?', 'll-tools-text-domain'); ?>
                        </a>
                    </p>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}
