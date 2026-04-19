<?php
if (!defined('WPINC')) { die; }

if (!defined('LL_TOOLS_TEACHER_CLASS_POST_TYPE')) {
    define('LL_TOOLS_TEACHER_CLASS_POST_TYPE', 'll_teacher_class');
}
if (!defined('LL_TOOLS_TEACHER_CLASS_STUDENT_IDS_META')) {
    define('LL_TOOLS_TEACHER_CLASS_STUDENT_IDS_META', '_ll_teacher_class_student_ids');
}
if (!defined('LL_TOOLS_STUDENT_CLASS_IDS_META')) {
    define('LL_TOOLS_STUDENT_CLASS_IDS_META', 'll_tools_teacher_class_ids');
}
if (!defined('LL_TOOLS_TEACHER_CLASS_INVITE_QUERY_ARG')) {
    define('LL_TOOLS_TEACHER_CLASS_INVITE_QUERY_ARG', 'll_tools_class_invite');
}
if (!defined('LL_TOOLS_TEACHER_CLASS_NOTICE_QUERY_ARG')) {
    define('LL_TOOLS_TEACHER_CLASS_NOTICE_QUERY_ARG', 'll_tools_class_notice');
}

if (!function_exists('ll_tools_register_teacher_class_post_type')) {
    function ll_tools_register_teacher_class_post_type(): void {
        $labels = [
            'name' => __('Classes', 'll-tools-text-domain'),
            'singular_name' => __('Class', 'll-tools-text-domain'),
        ];

        register_post_type(LL_TOOLS_TEACHER_CLASS_POST_TYPE, [
            'labels' => $labels,
            'public' => false,
            'show_ui' => false,
            'show_in_menu' => false,
            'show_in_admin_bar' => false,
            'exclude_from_search' => true,
            'publicly_queryable' => false,
            'query_var' => false,
            'rewrite' => false,
            'supports' => ['title', 'author'],
            'map_meta_cap' => false,
            'capability_type' => 'post',
        ]);
    }
}
add_action('init', 'll_tools_register_teacher_class_post_type', 5);

if (!function_exists('ll_tools_teacher_class_normalize_ids')) {
    function ll_tools_teacher_class_normalize_ids($raw): array {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $raw), static function (int $id): bool {
            return $id > 0;
        })));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }
}

if (!function_exists('ll_tools_get_teacher_class')) {
    function ll_tools_get_teacher_class(int $class_id): ?WP_Post {
        if ($class_id <= 0) {
            return null;
        }

        $post = get_post($class_id);
        if (!($post instanceof WP_Post) || $post->post_type !== LL_TOOLS_TEACHER_CLASS_POST_TYPE) {
            return null;
        }

        return $post;
    }
}

if (!function_exists('ll_tools_teacher_class_exists')) {
    function ll_tools_teacher_class_exists(int $class_id): bool {
        return ll_tools_get_teacher_class($class_id) instanceof WP_Post;
    }
}

if (!function_exists('ll_tools_teacher_class_get_owner_id')) {
    function ll_tools_teacher_class_get_owner_id(int $class_id): int {
        $class_post = ll_tools_get_teacher_class($class_id);
        if (!($class_post instanceof WP_Post)) {
            return 0;
        }

        return max(0, (int) $class_post->post_author);
    }
}

if (!function_exists('ll_tools_teacher_class_get_name')) {
    function ll_tools_teacher_class_get_name(int $class_id): string {
        $class_post = ll_tools_get_teacher_class($class_id);
        if (!($class_post instanceof WP_Post)) {
            return '';
        }

        return sanitize_text_field((string) $class_post->post_title);
    }
}

if (!function_exists('ll_tools_teacher_class_user_can_access')) {
    function ll_tools_teacher_class_user_can_access(int $class_id, int $user_id = 0): bool {
        $uid = (int) ($user_id ?: get_current_user_id());
        if ($uid <= 0 || !ll_tools_teacher_class_exists($class_id)) {
            return false;
        }

        if (user_can($uid, 'manage_options')) {
            return true;
        }

        if (!function_exists('ll_tools_user_can_view_class_progress') || !ll_tools_user_can_view_class_progress($uid)) {
            return false;
        }

        return ll_tools_teacher_class_get_owner_id($class_id) === $uid;
    }
}

if (!function_exists('ll_tools_teacher_class_get_student_ids')) {
    function ll_tools_teacher_class_get_student_ids(int $class_id): array {
        if (!ll_tools_teacher_class_exists($class_id)) {
            return [];
        }

        return ll_tools_teacher_class_normalize_ids(
            get_post_meta($class_id, LL_TOOLS_TEACHER_CLASS_STUDENT_IDS_META, true)
        );
    }
}

if (!function_exists('ll_tools_teacher_class_get_ids_for_student')) {
    function ll_tools_teacher_class_get_ids_for_student(int $user_id): array {
        if ($user_id <= 0) {
            return [];
        }

        return ll_tools_teacher_class_normalize_ids(
            get_user_meta($user_id, LL_TOOLS_STUDENT_CLASS_IDS_META, true)
        );
    }
}

if (!function_exists('ll_tools_teacher_class_user_is_student')) {
    function ll_tools_teacher_class_user_is_student(int $class_id, int $user_id): bool {
        if ($class_id <= 0 || $user_id <= 0) {
            return false;
        }

        return in_array($user_id, ll_tools_teacher_class_get_student_ids($class_id), true);
    }
}

if (!function_exists('ll_tools_teacher_class_add_student')) {
    function ll_tools_teacher_class_add_student(int $class_id, int $user_id): bool {
        if (!ll_tools_teacher_class_exists($class_id) || $user_id <= 0 || !get_userdata($user_id)) {
            return false;
        }

        $student_ids = ll_tools_teacher_class_get_student_ids($class_id);
        if (!in_array($user_id, $student_ids, true)) {
            $student_ids[] = $user_id;
            $student_ids = ll_tools_teacher_class_normalize_ids($student_ids);
            update_post_meta($class_id, LL_TOOLS_TEACHER_CLASS_STUDENT_IDS_META, $student_ids);
        }

        $class_ids = ll_tools_teacher_class_get_ids_for_student($user_id);
        if (!in_array($class_id, $class_ids, true)) {
            $class_ids[] = $class_id;
            $class_ids = ll_tools_teacher_class_normalize_ids($class_ids);
            update_user_meta($user_id, LL_TOOLS_STUDENT_CLASS_IDS_META, $class_ids);
        }

        return true;
    }
}

if (!function_exists('ll_tools_teacher_class_create')) {
    function ll_tools_teacher_class_create(int $teacher_user_id, string $class_name) {
        $teacher_user_id = max(0, $teacher_user_id);
        $class_name = sanitize_text_field($class_name);

        if ($teacher_user_id <= 0) {
            return new WP_Error('missing_teacher', __('A teacher account is required to create a class.', 'll-tools-text-domain'));
        }

        if ($class_name === '') {
            return new WP_Error('empty_name', __('Please enter a class name.', 'll-tools-text-domain'));
        }

        $class_id = wp_insert_post([
            'post_type' => LL_TOOLS_TEACHER_CLASS_POST_TYPE,
            'post_status' => 'publish',
            'post_title' => $class_name,
            'post_author' => $teacher_user_id,
        ], true, false);

        if (is_wp_error($class_id)) {
            return $class_id;
        }

        update_post_meta((int) $class_id, LL_TOOLS_TEACHER_CLASS_STUDENT_IDS_META, []);
        return (int) $class_id;
    }
}

if (!function_exists('ll_tools_teacher_classes_for_user')) {
    function ll_tools_teacher_classes_for_user(int $user_id = 0): array {
        $uid = (int) ($user_id ?: get_current_user_id());
        if ($uid <= 0) {
            return [];
        }

        $query_args = [
            'post_type' => LL_TOOLS_TEACHER_CLASS_POST_TYPE,
            'post_status' => 'publish',
            'orderby' => 'title',
            'order' => 'ASC',
            'posts_per_page' => -1,
            'no_found_rows' => true,
            'suppress_filters' => false,
        ];

        if (!user_can($uid, 'manage_options')) {
            $query_args['author'] = $uid;
        }

        $posts = get_posts($query_args);
        if (!is_array($posts)) {
            return [];
        }

        return array_values(array_filter($posts, static function ($post): bool {
            return $post instanceof WP_Post;
        }));
    }
}

if (!function_exists('ll_tools_teacher_class_user_can_be_student')) {
    function ll_tools_teacher_class_user_can_be_student($user): bool {
        if (!($user instanceof WP_User)) {
            return false;
        }

        if (in_array('ll_tools_learner', (array) $user->roles, true)) {
            return true;
        }

        return (bool) apply_filters('ll_tools_teacher_class_user_can_be_student', false, $user);
    }
}

if (!function_exists('ll_tools_teacher_class_get_invitable_learner_by_email')) {
    function ll_tools_teacher_class_get_invitable_learner_by_email(string $email): ?WP_User {
        $email = sanitize_email($email);
        if (!is_email($email)) {
            return null;
        }

        $user = get_user_by('email', $email);
        if (!($user instanceof WP_User) || !ll_tools_teacher_class_user_can_be_student($user)) {
            return null;
        }

        return $user;
    }
}

if (!function_exists('ll_tools_teacher_class_base64url_encode')) {
    function ll_tools_teacher_class_base64url_encode(string $value): string {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}

if (!function_exists('ll_tools_teacher_class_base64url_decode')) {
    function ll_tools_teacher_class_base64url_decode(string $value): string {
        $padded = strtr($value, '-_', '+/');
        $remainder = strlen($padded) % 4;
        if ($remainder > 0) {
            $padded .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode($padded, true);
        return is_string($decoded) ? $decoded : '';
    }
}

if (!function_exists('ll_tools_teacher_class_invite_secret')) {
    function ll_tools_teacher_class_invite_secret(): string {
        return (string) wp_salt('auth');
    }
}

if (!function_exists('ll_tools_teacher_class_build_invite_token')) {
    function ll_tools_teacher_class_build_invite_token(int $class_id, array $args = []): string {
        if (!ll_tools_teacher_class_exists($class_id)) {
            return '';
        }

        $mode = isset($args['mode']) ? sanitize_key((string) $args['mode']) : 'signup';
        if (!in_array($mode, ['signup', 'existing'], true)) {
            $mode = 'signup';
        }

        $email = '';
        if (!empty($args['email'])) {
            $email = strtolower(trim(sanitize_email((string) $args['email'])));
        }

        if ($mode === 'existing' && !is_email($email)) {
            return '';
        }

        $expiry = isset($args['expires_at']) ? (int) $args['expires_at'] : 0;
        if ($expiry <= time()) {
            $expiry = time() + (int) apply_filters('ll_tools_teacher_class_invite_expiration_seconds', 30 * DAY_IN_SECONDS, $mode, $class_id);
        }

        $payload = [
            'cid' => $class_id,
            'mode' => $mode,
            'email' => $email,
            'exp' => $expiry,
        ];

        $encoded_payload = ll_tools_teacher_class_base64url_encode((string) wp_json_encode($payload));
        if ($encoded_payload === '') {
            return '';
        }

        $signature = hash_hmac('sha256', $encoded_payload, ll_tools_teacher_class_invite_secret());
        return $encoded_payload . '.' . $signature;
    }
}

if (!function_exists('ll_tools_teacher_class_parse_invite_token')) {
    function ll_tools_teacher_class_parse_invite_token(string $token) {
        $token = trim((string) $token);
        if ($token === '' || strpos($token, '.') === false) {
            return new WP_Error('invalid_invite', __('This class invitation link is invalid.', 'll-tools-text-domain'));
        }

        [$encoded_payload, $signature] = explode('.', $token, 2);
        $expected_signature = hash_hmac('sha256', $encoded_payload, ll_tools_teacher_class_invite_secret());
        if (!hash_equals($expected_signature, (string) $signature)) {
            return new WP_Error('invalid_invite', __('This class invitation link is invalid.', 'll-tools-text-domain'));
        }

        $decoded = ll_tools_teacher_class_base64url_decode($encoded_payload);
        if ($decoded === '') {
            return new WP_Error('invalid_invite', __('This class invitation link is invalid.', 'll-tools-text-domain'));
        }

        $payload = json_decode($decoded, true);
        if (!is_array($payload)) {
            return new WP_Error('invalid_invite', __('This class invitation link is invalid.', 'll-tools-text-domain'));
        }

        $class_id = max(0, (int) ($payload['cid'] ?? 0));
        $mode = sanitize_key((string) ($payload['mode'] ?? ''));
        $email = strtolower(trim(sanitize_email((string) ($payload['email'] ?? ''))));
        $expires_at = (int) ($payload['exp'] ?? 0);

        if ($class_id <= 0 || !ll_tools_teacher_class_exists($class_id)) {
            return new WP_Error('missing_class', __('This class no longer exists.', 'll-tools-text-domain'));
        }
        if (!in_array($mode, ['signup', 'existing'], true)) {
            return new WP_Error('invalid_invite', __('This class invitation link is invalid.', 'll-tools-text-domain'));
        }
        if ($mode === 'existing' && !is_email($email)) {
            return new WP_Error('invalid_invite', __('This class invitation link is invalid.', 'll-tools-text-domain'));
        }
        if ($expires_at <= time()) {
            return new WP_Error('expired_invite', __('This class invitation link has expired.', 'll-tools-text-domain'));
        }

        return [
            'class_id' => $class_id,
            'class_name' => ll_tools_teacher_class_get_name($class_id),
            'mode' => $mode,
            'email' => $email,
            'expires_at' => $expires_at,
        ];
    }
}

if (!function_exists('ll_tools_teacher_class_get_invite_landing_url')) {
    function ll_tools_teacher_class_get_invite_landing_url(int $class_id = 0): string {
        $url = function_exists('ll_tools_get_learner_redirect_url')
            ? ll_tools_get_learner_redirect_url()
            : home_url('/');

        return (string) wp_validate_redirect($url, home_url('/'));
    }
}

if (!function_exists('ll_tools_teacher_class_get_invite_url')) {
    function ll_tools_teacher_class_get_invite_url(int $class_id, array $args = []): string {
        $token = ll_tools_teacher_class_build_invite_token($class_id, $args);
        if ($token === '') {
            return '';
        }

        $url = ll_tools_teacher_class_get_invite_landing_url($class_id);
        return (string) add_query_arg(LL_TOOLS_TEACHER_CLASS_INVITE_QUERY_ARG, $token, $url);
    }
}

if (!function_exists('ll_tools_teacher_class_get_signup_invite_url')) {
    function ll_tools_teacher_class_get_signup_invite_url(int $class_id): string {
        return ll_tools_teacher_class_get_invite_url($class_id, ['mode' => 'signup']);
    }
}

if (!function_exists('ll_tools_teacher_class_get_existing_invite_url')) {
    function ll_tools_teacher_class_get_existing_invite_url(int $class_id, string $email): string {
        return ll_tools_teacher_class_get_invite_url($class_id, [
            'mode' => 'existing',
            'email' => $email,
        ]);
    }
}

if (!function_exists('ll_tools_teacher_class_get_request_invite_token')) {
    function ll_tools_teacher_class_get_request_invite_token(): string {
        $raw = isset($_REQUEST[LL_TOOLS_TEACHER_CLASS_INVITE_QUERY_ARG])
            ? (string) wp_unslash($_REQUEST[LL_TOOLS_TEACHER_CLASS_INVITE_QUERY_ARG])
            : '';
        $token = preg_replace('/[^A-Za-z0-9\-\_\.]/', '', $raw);
        return is_string($token) ? $token : '';
    }
}

if (!function_exists('ll_tools_teacher_class_get_invite_request_context')) {
    function ll_tools_teacher_class_get_invite_request_context() {
        if (array_key_exists('ll_tools_teacher_class_invite_request_context', $GLOBALS)) {
            return $GLOBALS['ll_tools_teacher_class_invite_request_context'];
        }

        $token = ll_tools_teacher_class_get_request_invite_token();
        if ($token === '') {
            $GLOBALS['ll_tools_teacher_class_invite_request_context'] = [];
            return $GLOBALS['ll_tools_teacher_class_invite_request_context'];
        }

        $parsed = ll_tools_teacher_class_parse_invite_token($token);
        if (is_wp_error($parsed)) {
            $GLOBALS['ll_tools_teacher_class_invite_request_context'] = $parsed;
            return $GLOBALS['ll_tools_teacher_class_invite_request_context'];
        }

        $parsed['token'] = $token;
        $GLOBALS['ll_tools_teacher_class_invite_request_context'] = $parsed;
        return $GLOBALS['ll_tools_teacher_class_invite_request_context'];
    }
}

if (!function_exists('ll_tools_teacher_class_reset_invite_request_context')) {
    function ll_tools_teacher_class_reset_invite_request_context(): void {
        unset($GLOBALS['ll_tools_teacher_class_invite_request_context']);
    }
}

if (!function_exists('ll_tools_teacher_class_current_request_allows_signup_registration')) {
    function ll_tools_teacher_class_current_request_allows_signup_registration(): bool {
        $context = ll_tools_teacher_class_get_invite_request_context();
        return is_array($context) && (($context['mode'] ?? '') === 'signup');
    }
}

if (!function_exists('ll_tools_teacher_class_accept_invite_for_user')) {
    function ll_tools_teacher_class_accept_invite_for_user(string $token, int $user_id) {
        $context = ll_tools_teacher_class_parse_invite_token($token);
        if (is_wp_error($context)) {
            return $context;
        }

        $user = get_userdata($user_id);
        if (!($user instanceof WP_User)) {
            return new WP_Error('missing_user', __('Please sign in with a valid learner account.', 'll-tools-text-domain'));
        }

        $requires_learner_role = (($context['mode'] ?? '') === 'existing') || (($context['mode'] ?? '') === 'signup');
        if ($requires_learner_role && !ll_tools_teacher_class_user_can_be_student($user)) {
            return new WP_Error('invalid_student', __('This invitation is only for learner accounts.', 'll-tools-text-domain'));
        }

        $expected_email = isset($context['email']) ? strtolower(trim((string) $context['email'])) : '';
        if ($expected_email !== '' && strtolower(trim((string) $user->user_email)) !== $expected_email) {
            return new WP_Error('wrong_user', __('Please sign in with the invited learner email address.', 'll-tools-text-domain'));
        }

        $class_id = max(0, (int) ($context['class_id'] ?? 0));
        $already_member = ll_tools_teacher_class_user_is_student($class_id, $user_id);
        if (!$already_member && !ll_tools_teacher_class_add_student($class_id, $user_id)) {
            return new WP_Error('join_failed', __('The learner could not be added to the class.', 'll-tools-text-domain'));
        }

        return [
            'class_id' => $class_id,
            'class_name' => (string) ($context['class_name'] ?? ''),
            'already_member' => $already_member,
        ];
    }
}

if (!function_exists('ll_tools_teacher_class_notice_storage_key')) {
    function ll_tools_teacher_class_notice_storage_key(string $token): string {
        return 'll_tools_teacher_class_notice_' . $token;
    }
}

if (!function_exists('ll_tools_teacher_class_store_notice')) {
    function ll_tools_teacher_class_store_notice(array $notice): string {
        $token = strtolower(wp_generate_password(18, false, false));
        $token = preg_replace('/[^a-z0-9]/', '', $token);
        $token = is_string($token) ? substr($token, 0, 30) : '';
        if ($token === '') {
            return '';
        }

        set_transient(ll_tools_teacher_class_notice_storage_key($token), $notice, 10 * MINUTE_IN_SECONDS);
        return $token;
    }
}

if (!function_exists('ll_tools_teacher_class_append_notice_to_url')) {
    function ll_tools_teacher_class_append_notice_to_url(string $url, array $notice): string {
        $token = ll_tools_teacher_class_store_notice($notice);
        if ($token === '') {
            return $url;
        }

        return (string) add_query_arg(LL_TOOLS_TEACHER_CLASS_NOTICE_QUERY_ARG, $token, $url);
    }
}

if (!function_exists('ll_tools_teacher_class_consume_notice_from_request')) {
    function ll_tools_teacher_class_consume_notice_from_request(): array {
        $raw_token = isset($_GET[LL_TOOLS_TEACHER_CLASS_NOTICE_QUERY_ARG])
            ? (string) wp_unslash($_GET[LL_TOOLS_TEACHER_CLASS_NOTICE_QUERY_ARG])
            : '';
        $token = preg_replace('/[^a-z0-9]/', '', strtolower($raw_token));
        $token = is_string($token) ? substr($token, 0, 30) : '';
        if ($token === '') {
            return [];
        }

        $notice = get_transient(ll_tools_teacher_class_notice_storage_key($token));
        delete_transient(ll_tools_teacher_class_notice_storage_key($token));

        return is_array($notice) ? $notice : [];
    }
}

if (!function_exists('ll_tools_teacher_class_render_frontend_notice')) {
    function ll_tools_teacher_class_render_frontend_notice(): string {
        $notice = ll_tools_teacher_class_consume_notice_from_request();
        $message = isset($notice['message']) ? trim((string) $notice['message']) : '';
        if ($message === '') {
            return '';
        }

        $type = (isset($notice['type']) && $notice['type'] === 'success') ? 'success' : 'error';

        ob_start();
        ?>
        <div
            class="ll-wordset-progress-reset-notice ll-wordset-progress-reset-notice--<?php echo esc_attr($type); ?>"
            role="<?php echo esc_attr($type === 'success' ? 'status' : 'alert'); ?>">
            <?php echo esc_html($message); ?>
        </div>
        <?php

        return (string) ob_get_clean();
    }
}

if (!function_exists('ll_tools_teacher_class_strip_invite_query_args')) {
    function ll_tools_teacher_class_strip_invite_query_args(string $url): string {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        return (string) remove_query_arg([
            LL_TOOLS_TEACHER_CLASS_INVITE_QUERY_ARG,
            LL_TOOLS_TEACHER_CLASS_NOTICE_QUERY_ARG,
            'll_tools_auth',
            'll_tools_auth_feedback',
        ], $url);
    }
}

if (!function_exists('ll_tools_teacher_class_get_current_invite_base_url')) {
    function ll_tools_teacher_class_get_current_invite_base_url(): string {
        $current_url = function_exists('ll_tools_get_current_request_url')
            ? ll_tools_get_current_request_url()
            : home_url('/');
        $current_url = ll_tools_teacher_class_strip_invite_query_args($current_url);

        return $current_url !== '' ? $current_url : home_url('/');
    }
}

if (!function_exists('ll_tools_teacher_class_build_auth_redirect_url')) {
    function ll_tools_teacher_class_build_auth_redirect_url(string $invite_url, string $mode, string $message): string {
        $feedback = [
            'type' => 'success',
            'form' => $mode,
            'messages' => [$message],
        ];

        if (function_exists('ll_tools_login_window_append_feedback_to_url')) {
            return ll_tools_login_window_append_feedback_to_url($invite_url, $feedback, $mode);
        }

        if (function_exists('ll_tools_get_frontend_auth_url')) {
            return ll_tools_get_frontend_auth_url($invite_url, $mode);
        }

        return $invite_url;
    }
}

if (!function_exists('ll_tools_teacher_class_send_existing_learner_invitation')) {
    function ll_tools_teacher_class_send_existing_learner_invitation(int $class_id, string $email, int $teacher_user_id = 0) {
        $learner = ll_tools_teacher_class_get_invitable_learner_by_email($email);
        if (!($learner instanceof WP_User)) {
            return new WP_Error('invalid_learner', __('Enter the email address of an existing learner account.', 'll-tools-text-domain'));
        }

        $invite_url = ll_tools_teacher_class_get_existing_invite_url($class_id, (string) $learner->user_email);
        if ($invite_url === '') {
            return new WP_Error('invite_link_failed', __('The class invitation link could not be generated.', 'll-tools-text-domain'));
        }

        $teacher_user = ($teacher_user_id > 0) ? get_userdata($teacher_user_id) : null;
        $teacher_name = '';
        if ($teacher_user instanceof WP_User) {
            $teacher_name = $teacher_user->display_name ?: $teacher_user->user_login;
        }

        $class_name = ll_tools_teacher_class_get_name($class_id);
        $site_name = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $subject = sprintf(
            /* translators: %s: class name */
            __('Invitation to join %s', 'll-tools-text-domain'),
            $class_name
        );

        $lines = [
            sprintf(
                /* translators: %1$s: site name, %2$s: class name */
                __('You have been invited on %1$s to join the class "%2$s".', 'll-tools-text-domain'),
                $site_name,
                $class_name
            ),
        ];

        if ($teacher_name !== '') {
            $lines[] = sprintf(
                /* translators: %s: teacher display name */
                __('Teacher: %s', 'll-tools-text-domain'),
                $teacher_name
            );
        }

        $lines[] = '';
        $lines[] = __('Open this link and sign in with your learner account to join the class:', 'll-tools-text-domain');
        $lines[] = $invite_url;

        $headers = ['Content-Type: text/plain; charset=UTF-8'];
        if (function_exists('ll_tools_get_notification_sender_email') && function_exists('ll_tools_override_mail_from_header')) {
            $from_email = ll_tools_get_notification_sender_email();
            if ($from_email !== '') {
                $headers = ll_tools_override_mail_from_header(
                    $headers,
                    function_exists('ll_tools_get_notification_sender_name')
                        ? ll_tools_get_notification_sender_name()
                        : 'WordPress',
                    $from_email
                );
            }
        }

        $sent = wp_mail((string) $learner->user_email, $subject, implode("\n", $lines), $headers);
        if (!$sent) {
            return new WP_Error('mail_failed', __('The invitation email could not be sent.', 'll-tools-text-domain'));
        }

        return true;
    }
}

if (!function_exists('ll_tools_teacher_class_maybe_handle_invite_request')) {
    function ll_tools_teacher_class_maybe_handle_invite_request(): void {
        if (is_admin() || (function_exists('wp_doing_ajax') && wp_doing_ajax())) {
            return;
        }

        $token = ll_tools_teacher_class_get_request_invite_token();
        if ($token === '') {
            return;
        }

        $context = ll_tools_teacher_class_get_invite_request_context();
        $base_url = ll_tools_teacher_class_get_current_invite_base_url();
        if (is_wp_error($context)) {
            $message = $context->get_error_message();
            wp_safe_redirect(ll_tools_teacher_class_append_notice_to_url($base_url, [
                'type' => 'error',
                'message' => $message,
            ]));
            exit;
        }

        $current_url = function_exists('ll_tools_get_current_request_url')
            ? ll_tools_get_current_request_url()
            : $base_url;
        $desired_mode = (($context['mode'] ?? '') === 'signup') ? 'register' : 'login';

        if (!is_user_logged_in()) {
            $requested_mode = function_exists('ll_tools_login_window_requested_mode')
                ? ll_tools_login_window_requested_mode()
                : '';
            if ($requested_mode === $desired_mode) {
                return;
            }

            $message = ($desired_mode === 'register')
                ? sprintf(
                    /* translators: %s: class name */
                    __('Create a learner account to join %s.', 'll-tools-text-domain'),
                    (string) ($context['class_name'] ?? __('this class', 'll-tools-text-domain'))
                )
                : sprintf(
                    /* translators: %s: class name */
                    __('Sign in with the invited learner account to join %s.', 'll-tools-text-domain'),
                    (string) ($context['class_name'] ?? __('this class', 'll-tools-text-domain'))
                );

            wp_safe_redirect(ll_tools_teacher_class_build_auth_redirect_url($current_url, $desired_mode, $message));
            exit;
        }

        $result = ll_tools_teacher_class_accept_invite_for_user($token, get_current_user_id());
        if (is_wp_error($result)) {
            wp_safe_redirect(ll_tools_teacher_class_append_notice_to_url($base_url, [
                'type' => 'error',
                'message' => $result->get_error_message(),
            ]));
            exit;
        }

        $message = !empty($result['already_member'])
            ? sprintf(
                /* translators: %s: class name */
                __('You are already a member of %s.', 'll-tools-text-domain'),
                (string) ($result['class_name'] ?? __('this class', 'll-tools-text-domain'))
            )
            : sprintf(
                /* translators: %s: class name */
                __('You joined %s.', 'll-tools-text-domain'),
                (string) ($result['class_name'] ?? __('this class', 'll-tools-text-domain'))
            );

        wp_safe_redirect(ll_tools_teacher_class_append_notice_to_url($base_url, [
            'type' => 'success',
            'message' => $message,
        ]));
        exit;
    }
}
add_action('template_redirect', 'll_tools_teacher_class_maybe_handle_invite_request', 1);
