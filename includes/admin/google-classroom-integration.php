<?php
/**
 * Teacher-facing Google Classroom connection diagnostics and OAuth actions.
 *
 * This page deliberately exposes only connection and bounded course reads.
 * CourseWork creation and grade writes have no UI action in this phase.
 */

if (!defined('WPINC')) { die; }

function ll_tools_google_classroom_integration_page_slug(): string {
    return 'll-tools-google-classroom';
}

function ll_tools_google_classroom_integration_page_url(array $args = []): string {
    return (string) add_query_arg(
        array_merge(['page' => ll_tools_google_classroom_integration_page_slug()], $args),
        admin_url('admin.php')
    );
}

function ll_tools_google_classroom_integration_capability(): string {
    return function_exists('ll_tools_get_teacher_manage_classes_capability')
        ? ll_tools_get_teacher_manage_classes_capability()
        : 'manage_options';
}

function ll_tools_google_classroom_current_user_can_connect(): bool {
    $user_id = get_current_user_id();
    if ($user_id <= 0) {
        return false;
    }
    if (function_exists('ll_tools_user_can_manage_classes')) {
        return ll_tools_user_can_manage_classes($user_id);
    }
    return current_user_can('manage_options')
        || current_user_can(ll_tools_google_classroom_integration_capability());
}

function ll_tools_register_google_classroom_integration_page(): void {
    $parent_slug = function_exists('ll_tools_get_admin_menu_slug')
        ? ll_tools_get_admin_menu_slug()
        : 'll-tools-dashboard-home';
    add_submenu_page(
        $parent_slug,
        __('Google Classroom', 'll-tools-text-domain'),
        __('Google Classroom', 'll-tools-text-domain'),
        ll_tools_google_classroom_integration_capability(),
        ll_tools_google_classroom_integration_page_slug(),
        'll_tools_render_google_classroom_integration_page'
    );
}
add_action('admin_menu', 'll_tools_register_google_classroom_integration_page', 17);

/**
 * Whitelisted notice copy: redirects never reflect Google/raw exception text.
 */
function ll_tools_google_classroom_notice(string $code): array {
    $notices = [
        'connected' => ['success', __('Google Classroom was connected.', 'll-tools-text-domain')],
        'disconnected' => ['success', __('The local Google Classroom connection was removed.', 'll-tools-text-domain')],
        'disconnected_revoke_failed' => ['warning', __('The local connection was removed, but Google could not confirm remote revocation. You can also remove access in your Google Account.', 'll-tools-text-domain')],
        'authorization_declined' => ['warning', __('Google Classroom authorization was not completed.', 'll-tools-text-domain')],
        'authorization_failed' => ['error', __('Google Classroom authorization could not be verified. Please try again.', 'll-tools-text-domain')],
        'configuration_missing' => ['error', __('Google Classroom is not configured on this site.', 'll-tools-text-domain')],
        'disconnect_failed' => ['error', __('The Google Classroom connection could not be removed.', 'll-tools-text-domain')],
    ];
    return $notices[$code] ?? [];
}

function ll_tools_google_classroom_redirect_with_notice(string $code): void {
    $code = sanitize_key($code);
    if (ll_tools_google_classroom_notice($code) === []) {
        $code = 'authorization_failed';
    }
    wp_safe_redirect(ll_tools_google_classroom_integration_page_url(['ll_tools_gc_notice' => $code]));
    exit;
}

function ll_tools_google_classroom_require_action_access(): int {
    if (!is_user_logged_in()) {
        auth_redirect();
    }
    if (!ll_tools_google_classroom_current_user_can_connect()) {
        wp_die(
            esc_html__('You are not allowed to manage Google Classroom connections.', 'll-tools-text-domain'),
            esc_html__('Access denied', 'll-tools-text-domain'),
            ['response' => 403]
        );
    }
    return get_current_user_id();
}

function ll_tools_google_classroom_oauth_start(): void {
    nocache_headers();
    $teacher_user_id = ll_tools_google_classroom_require_action_access();
    check_admin_referer('ll_tools_google_classroom_oauth_start');

    if (!ll_tools_google_classroom_is_configured() || !ll_tools_google_classroom_schema_is_ready()) {
        ll_tools_google_classroom_redirect_with_notice('configuration_missing');
    }
    $oauth = ll_tools_google_classroom_create_oauth_state($teacher_user_id);
    if (is_wp_error($oauth)) {
        ll_tools_google_classroom_redirect_with_notice('authorization_failed');
    }
    $user = wp_get_current_user();
    $login_hint = ($user instanceof WP_User) ? (string) $user->user_email : '';
    $url = ll_tools_google_classroom_authorization_url($oauth['state'], $oauth['code_challenge'], $login_hint);
    if (is_wp_error($url) || !is_string($url) || !str_starts_with($url, 'https://accounts.google.com/o/oauth2/v2/auth?')) {
        ll_tools_google_classroom_redirect_with_notice('authorization_failed');
    }
    wp_redirect($url, 302, 'LL Tools');
    exit;
}
add_action('admin_post_ll_tools_google_classroom_oauth_start', 'll_tools_google_classroom_oauth_start');

function ll_tools_google_classroom_oauth_callback(): void {
    nocache_headers();
    $teacher_user_id = ll_tools_google_classroom_require_action_access();
    $raw_state = isset($_GET['state']) ? wp_unslash($_GET['state']) : '';
    $state = is_string($raw_state) ? $raw_state : '';
    $consumed = ll_tools_google_classroom_consume_oauth_state($state, $teacher_user_id);
    if (is_wp_error($consumed)) {
        ll_tools_google_classroom_redirect_with_notice('authorization_failed');
    }
    if (isset($_GET['error']) || !isset($_GET['code'])) {
        ll_tools_google_classroom_redirect_with_notice('authorization_declined');
    }

    $raw_code = wp_unslash($_GET['code']);
    $code = is_string($raw_code) ? $raw_code : '';
    $tokens = ll_tools_google_classroom_exchange_code($code, $consumed['code_verifier']);
    if (is_wp_error($tokens)) {
        ll_tools_google_classroom_redirect_with_notice('authorization_failed');
    }
    $profile = ll_tools_google_classroom_fetch_userinfo($tokens['access_token']);
    if (is_wp_error($profile)) {
        ll_tools_google_classroom_redirect_with_notice('authorization_failed');
    }
    $stored = ll_tools_google_classroom_store_connection(
        $teacher_user_id,
        $tokens['refresh_token'],
        $profile,
        $tokens['scopes'],
        $consumed['state_hash']
    );
    if (is_wp_error($stored)) {
        ll_tools_google_classroom_redirect_with_notice('authorization_failed');
    }
    ll_tools_google_classroom_redirect_with_notice('connected');
}
add_action('admin_post_ll_tools_google_classroom_oauth_callback', 'll_tools_google_classroom_oauth_callback');

function ll_tools_google_classroom_disconnect(): void {
    nocache_headers();
    $teacher_user_id = ll_tools_google_classroom_require_action_access();
    check_admin_referer('ll_tools_google_classroom_disconnect');
    $raw_connection_id = isset($_POST['connection_id']) ? wp_unslash($_POST['connection_id']) : 0;
    $connection_id = is_scalar($raw_connection_id) ? absint($raw_connection_id) : 0;
    if ($connection_id <= 0) {
        ll_tools_google_classroom_redirect_with_notice('disconnect_failed');
    }
    $result = ll_tools_google_classroom_disconnect_connection($connection_id, $teacher_user_id);
    if (is_wp_error($result)) {
        ll_tools_google_classroom_redirect_with_notice('disconnect_failed');
    }
    ll_tools_google_classroom_redirect_with_notice(
        !empty($result['revocation_failed']) ? 'disconnected_revoke_failed' : 'disconnected'
    );
}
add_action('admin_post_ll_tools_google_classroom_disconnect', 'll_tools_google_classroom_disconnect');

function ll_tools_google_classroom_render_notice_from_request(): void {
    $raw_code = isset($_GET['ll_tools_gc_notice'])
        ? wp_unslash($_GET['ll_tools_gc_notice'])
        : '';
    $code = is_string($raw_code) ? sanitize_key($raw_code) : '';
    $notice = ll_tools_google_classroom_notice($code);
    if ($notice === []) {
        return;
    }
    $class = $notice[0] === 'success'
        ? 'notice-success'
        : ($notice[0] === 'warning' ? 'notice-warning' : 'notice-error');
    printf(
        '<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
        esc_attr($class),
        esc_html($notice[1])
    );
}

/**
 * Render configuration diagnostics, connection controls, and an explicitly
 * requested bounded course list. Nothing on this page can write a grade.
 */
function ll_tools_render_google_classroom_integration_page(): void {
    if (!ll_tools_google_classroom_current_user_can_connect()) {
        wp_die(
            esc_html__('You are not allowed to manage Google Classroom connections.', 'll-tools-text-domain'),
            esc_html__('Access denied', 'll-tools-text-domain'),
            ['response' => 403]
        );
    }
    nocache_headers();
    $teacher_user_id = get_current_user_id();
    $config = ll_tools_google_classroom_config_status();
    $connection_limit = ll_tools_google_classroom_connection_limit();
    $connections_result = ll_tools_google_classroom_owned_connections($teacher_user_id, $connection_limit);
    $connections = is_wp_error($connections_result) ? [] : $connections_result;
    $connection_error = is_wp_error($connections_result);
    $selected_connection = null;
    $courses = null;
    $course_error = null;

    $raw_page_action = isset($_POST['ll_tools_google_classroom_page_action'])
        ? wp_unslash($_POST['ll_tools_google_classroom_page_action'])
        : '';
    $is_course_request = is_string($raw_page_action)
        && sanitize_key($raw_page_action) === 'list_courses';
    if ($is_course_request) {
        check_admin_referer('ll_tools_google_classroom_list_courses');
        $raw_connection_id = isset($_POST['connection_id']) ? wp_unslash($_POST['connection_id']) : 0;
        $connection_id = is_scalar($raw_connection_id) ? absint($raw_connection_id) : 0;
        foreach ($connections as $candidate) {
            if ((int) ($candidate['id'] ?? 0) === $connection_id) {
                $selected_connection = $candidate;
                break;
            }
        }
        if (!is_array($selected_connection)) {
            $course_error = __('Connect Google Classroom before listing courses.', 'll-tools-text-domain');
        } else {
            $courses = ll_tools_google_classroom_list_active_courses(
                (int) $selected_connection['id'],
                $teacher_user_id,
                50
            );
            if (is_wp_error($courses)) {
                $course_error = __('Active courses could not be loaded from Google Classroom.', 'll-tools-text-domain');
                $courses = null;
            }
        }
    }

    echo '<div class="wrap ll-tools-google-classroom">';
    echo '<h1>' . esc_html__('Google Classroom', 'll-tools-text-domain') . '</h1>';
    ll_tools_google_classroom_render_notice_from_request();

    echo '<h2>' . esc_html__('Configuration', 'll-tools-text-domain') . '</h2>';
    echo '<p>' . esc_html__('OAuth credentials and the encryption key must be supplied by site configuration. They are never stored or entered on this page.', 'll-tools-text-domain') . '</p>';
    echo '<table class="widefat striped" style="max-width:900px"><tbody>';
    printf(
        '<tr><th scope="row">%1$s</th><td><code>%2$s</code></td></tr>',
        esc_html__('Authorized redirect URI', 'll-tools-text-domain'),
        esc_html((string) $config['callback_url'])
    );
    $rows = [
        __('Client ID', 'll-tools-text-domain') => !empty($config['client_id_configured']),
        __('Client secret', 'll-tools-text-domain') => !empty($config['client_secret_configured']),
        __('Credential encryption', 'll-tools-text-domain') => !empty($config['credential_store_configured']),
        __('Database schema', 'll-tools-text-domain') => ll_tools_google_classroom_schema_is_ready(),
    ];
    foreach ($rows as $label => $ready) {
        printf(
            '<tr><th scope="row">%1$s</th><td>%2$s</td></tr>',
            esc_html($label),
            esc_html($ready ? __('Ready', 'll-tools-text-domain') : __('Not ready', 'll-tools-text-domain'))
        );
    }
    echo '</tbody></table>';

    echo '<h2>' . esc_html__('Connection', 'll-tools-text-domain') . '</h2>';
    if ($connection_error) {
        echo '<div class="notice notice-error inline"><p>' . esc_html__('Google Classroom connections could not be loaded.', 'll-tools-text-domain') . '</p></div>';
    } elseif ($connections === []) {
        echo '<p>' . esc_html__('No Google Classroom account is connected.', 'll-tools-text-domain') . '</p>';
    } else {
        echo '<p>' . esc_html__('Courses are requested from Google only when you use a button below. Up to 50 active courses are shown for that exact account.', 'll-tools-text-domain') . '</p>';
        echo '<table class="widefat striped" style="max-width:900px"><thead><tr>';
        echo '<th>' . esc_html__('Google account', 'll-tools-text-domain') . '</th>';
        echo '<th>' . esc_html__('Connected since', 'll-tools-text-domain') . '</th>';
        echo '<th>' . esc_html__('Actions', 'll-tools-text-domain') . '</th>';
        echo '</tr></thead><tbody>';
        foreach ($connections as $connection) {
            $profile = ll_tools_google_classroom_privacy_profile_for_connection(
                (int) $connection['id'],
                $teacher_user_id
            );
            $account_label = !is_wp_error($profile) && (string) ($profile['email'] ?? '') !== ''
                ? (string) $profile['email']
                : __('Connected Google account', 'll-tools-text-domain');
            echo '<tr><td>' . esc_html($account_label) . '</td>';
            echo '<td><code>' . esc_html((string) $connection['connected_at']) . '</code></td><td>';
            echo '<form method="post" action="' . esc_url(ll_tools_google_classroom_integration_page_url()) . '" style="display:inline-block;margin-right:8px">';
            echo '<input type="hidden" name="ll_tools_google_classroom_page_action" value="list_courses">';
            echo '<input type="hidden" name="connection_id" value="' . esc_attr((string) $connection['id']) . '">';
            wp_nonce_field('ll_tools_google_classroom_list_courses');
            submit_button(__('Load active courses', 'll-tools-text-domain'), 'secondary small', 'submit', false);
            echo '</form>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block">';
            echo '<input type="hidden" name="action" value="ll_tools_google_classroom_disconnect">';
            echo '<input type="hidden" name="connection_id" value="' . esc_attr((string) $connection['id']) . '">';
            wp_nonce_field('ll_tools_google_classroom_disconnect');
            submit_button(__('Disconnect', 'll-tools-text-domain'), 'secondary small', 'submit', false);
            echo '</form></td></tr>';
        }
        echo '</tbody></table>';
    }
    if (!$connection_error && count($connections) < $connection_limit && !empty($config['ready']) && ll_tools_google_classroom_schema_is_ready()) {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="ll_tools_google_classroom_oauth_start">';
        wp_nonce_field('ll_tools_google_classroom_oauth_start');
        submit_button($connections === [] ? __('Connect Google Classroom', 'll-tools-text-domain') : __('Connect another Google account', 'll-tools-text-domain'), 'primary', 'submit', false);
        echo '</form>';
    }

    if ($course_error !== null) {
        echo '<div class="notice notice-error inline"><p>' . esc_html($course_error) . '</p></div>';
    } elseif (is_array($courses)) {
        echo '<h2>' . esc_html__('Active courses', 'll-tools-text-domain') . '</h2>';
        if ($courses === []) {
            echo '<p>' . esc_html__('No active courses were returned.', 'll-tools-text-domain') . '</p>';
        } else {
            echo '<table class="widefat striped" style="max-width:900px"><thead><tr>';
            echo '<th>' . esc_html__('Course', 'll-tools-text-domain') . '</th>';
            echo '<th>' . esc_html__('Section', 'll-tools-text-domain') . '</th>';
            echo '<th>' . esc_html__('Google course ID', 'll-tools-text-domain') . '</th>';
            echo '</tr></thead><tbody>';
            foreach ($courses as $course) {
                echo '<tr>';
                echo '<td>' . esc_html((string) $course['name']) . '</td>';
                echo '<td>' . esc_html((string) $course['section']) . '</td>';
                echo '<td><code>' . esc_html((string) $course['id']) . '</code></td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
    }

    echo '<h2>' . esc_html__('Grade writes', 'll-tools-text-domain') . '</h2>';
    echo '<p>' . esc_html__('CourseWork and grade writes are not available on this page. They remain disabled until server-verified assignments and attempts are ready for production.', 'll-tools-text-domain') . '</p>';
    echo '</div>';
}
