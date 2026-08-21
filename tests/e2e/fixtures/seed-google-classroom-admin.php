<?php
/**
 * WP-CLI eval-file controller for the local-only Classroom admin E2E fixture.
 *
 * Usage:
 *   wp eval-file seed-google-classroom-admin.php activate-unconfigured
 *   wp eval-file seed-google-classroom-admin.php activate-configured
 *   wp eval-file seed-google-classroom-admin.php seed
 *   wp eval-file seed-google-classroom-admin.php cleanup
 */

if (!defined('ABSPATH')) {
    fwrite(STDERR, "This script must run inside WordPress, usually through WP-CLI eval-file.\n");
    exit(1);
}

const LL_TOOLS_GOOGLE_CLASSROOM_ADMIN_E2E_OPTION = 'll_tools_e2e_google_classroom_admin_fixture';
const LL_TOOLS_GOOGLE_CLASSROOM_ADMIN_E2E_MU_FILE = 'll-tools-e2e-google-classroom-admin.php';
const LL_TOOLS_GOOGLE_CLASSROOM_ADMIN_E2E_SUBJECT = 'll-tools-e2e-google-classroom-admin';
const LL_TOOLS_GOOGLE_CLASSROOM_ADMIN_E2E_USER_LOGIN = 'll-e2e-google-classroom-teacher';
const LL_TOOLS_GOOGLE_CLASSROOM_ADMIN_E2E_USER_META = '_ll_tools_e2e_google_classroom_admin';

function ll_tools_google_classroom_admin_e2e_fail(string $message): void {
    if (class_exists('WP_CLI')) {
        WP_CLI::error($message);
    }

    throw new RuntimeException($message);
}

function ll_tools_google_classroom_admin_e2e_user(): WP_User {
    $user = get_user_by('login', LL_TOOLS_GOOGLE_CLASSROOM_ADMIN_E2E_USER_LOGIN);
    if (!($user instanceof WP_User)
        || (string) get_user_meta((int) $user->ID, LL_TOOLS_GOOGLE_CLASSROOM_ADMIN_E2E_USER_META, true)
            !== LL_TOOLS_GOOGLE_CLASSROOM_ADMIN_E2E_SUBJECT
        || !function_exists('ll_tools_user_can_manage_classes')
        || !ll_tools_user_can_manage_classes((int) $user->ID)) {
        ll_tools_google_classroom_admin_e2e_fail(
            'The bounded Classroom fixture teacher is unavailable.'
        );
    }
    return $user;
}

function ll_tools_google_classroom_admin_e2e_create_user(): array {
    $existing = get_user_by('login', LL_TOOLS_GOOGLE_CLASSROOM_ADMIN_E2E_USER_LOGIN);
    if ($existing instanceof WP_User) {
        $marker = (string) get_user_meta(
            (int) $existing->ID,
            LL_TOOLS_GOOGLE_CLASSROOM_ADMIN_E2E_USER_META,
            true
        );
        if ($marker !== LL_TOOLS_GOOGLE_CLASSROOM_ADMIN_E2E_SUBJECT) {
            ll_tools_google_classroom_admin_e2e_fail(
                'Refusing to replace an unrelated user at the Classroom fixture login.'
            );
        }
        ll_tools_google_classroom_admin_e2e_fail(
            'The prior Classroom fixture teacher must be cleaned before creating a replacement.'
        );
    }

    if (function_exists('ll_tools_register_or_refresh_teacher_role')) {
        ll_tools_register_or_refresh_teacher_role();
    }
    $password = wp_generate_password(32, true, true);
    $user_id = wp_create_user(
        LL_TOOLS_GOOGLE_CLASSROOM_ADMIN_E2E_USER_LOGIN,
        $password,
        'll-e2e-google-classroom-teacher@example.invalid'
    );
    if (is_wp_error($user_id) || (int) $user_id <= 0) {
        $message = is_wp_error($user_id) ? $user_id->get_error_message() : 'unknown error';
        ll_tools_google_classroom_admin_e2e_fail('Unable to create the Classroom fixture teacher: ' . $message);
    }
    $user_id = (int) $user_id;
    wp_update_user([
        'ID' => $user_id,
        'display_name' => 'Classroom E2E Teacher',
        'nickname' => 'Classroom E2E Teacher',
        'role' => function_exists('ll_tools_get_teacher_role_slug')
            ? ll_tools_get_teacher_role_slug()
            : 'll_tools_teacher',
    ]);
    update_user_meta(
        $user_id,
        LL_TOOLS_GOOGLE_CLASSROOM_ADMIN_E2E_USER_META,
        LL_TOOLS_GOOGLE_CLASSROOM_ADMIN_E2E_SUBJECT
    );
    $user = ll_tools_google_classroom_admin_e2e_user();

    return [
        'id' => (int) $user->ID,
        'login' => LL_TOOLS_GOOGLE_CLASSROOM_ADMIN_E2E_USER_LOGIN,
        'password' => $password,
        'displayName' => 'Classroom E2E Teacher',
    ];
}

function ll_tools_google_classroom_admin_e2e_delete_user(): bool {
    $user = get_user_by('login', LL_TOOLS_GOOGLE_CLASSROOM_ADMIN_E2E_USER_LOGIN);
    if (!($user instanceof WP_User)) {
        return true;
    }
    if ((string) get_user_meta((int) $user->ID, LL_TOOLS_GOOGLE_CLASSROOM_ADMIN_E2E_USER_META, true)
        !== LL_TOOLS_GOOGLE_CLASSROOM_ADMIN_E2E_SUBJECT) {
        ll_tools_google_classroom_admin_e2e_fail(
            'Refusing to remove an unrelated user from the Classroom fixture login.'
        );
    }
    if (!function_exists('wp_delete_user')) {
        require_once ABSPATH . 'wp-admin/includes/user.php';
    }
    return (bool) wp_delete_user((int) $user->ID);
}

function ll_tools_google_classroom_admin_e2e_mu_path(): string {
    return trailingslashit(WP_CONTENT_DIR . '/mu-plugins') . LL_TOOLS_GOOGLE_CLASSROOM_ADMIN_E2E_MU_FILE;
}

function ll_tools_google_classroom_admin_e2e_install_mu_plugin(): string {
    $source_path = __DIR__ . '/google-classroom-admin-mu.php';
    $source = is_file($source_path) ? file_get_contents($source_path) : false;
    if (!is_string($source) || $source === ''
        || strpos($source, 'LL Tools E2E Google Classroom admin fixture') === false) {
        ll_tools_google_classroom_admin_e2e_fail('The Classroom MU fixture source is unavailable.');
    }

    $mu_dir = dirname(ll_tools_google_classroom_admin_e2e_mu_path());
    if (!wp_mkdir_p($mu_dir)) {
        ll_tools_google_classroom_admin_e2e_fail('Unable to create the local mu-plugins fixture directory.');
    }

    $target = ll_tools_google_classroom_admin_e2e_mu_path();
    if (is_file($target)) {
        $existing = file_get_contents($target);
        if (!is_string($existing)
            || strpos($existing, 'LL Tools E2E Google Classroom admin fixture') === false) {
            ll_tools_google_classroom_admin_e2e_fail(
                'Refusing to replace an unrelated mu-plugin at the Classroom fixture path.'
            );
        }
    }
    if (file_put_contents($target, $source, LOCK_EX) !== strlen($source)) {
        ll_tools_google_classroom_admin_e2e_fail('Unable to install the local Classroom MU fixture.');
    }

    return $target;
}

function ll_tools_google_classroom_admin_e2e_remove_mu_plugin(): bool {
    $target = ll_tools_google_classroom_admin_e2e_mu_path();
    if (!is_file($target)) {
        return true;
    }
    $source = file_get_contents($target);
    if (!is_string($source)
        || strpos($source, 'LL Tools E2E Google Classroom admin fixture') === false) {
        ll_tools_google_classroom_admin_e2e_fail(
            'Refusing to remove an unrelated mu-plugin from the Classroom fixture path.'
        );
    }
    return unlink($target);
}

function ll_tools_google_classroom_admin_e2e_add_cli_config_filters(bool $configured): void {
    add_filter('ll_tools_google_classroom_client_id', static fn($value): string => $configured
        ? 'll-tools-e2e-classroom.apps.googleusercontent.com'
        : '', PHP_INT_MAX);
    add_filter('ll_tools_google_classroom_client_secret', static fn($value): string => $configured
        ? 'll-tools-e2e-client-secret-never-sent'
        : '', PHP_INT_MAX);
    add_filter('ll_tools_lms_credential_master_key', static fn($value): string => $configured
        ? str_repeat('E', 32)
        : '', PHP_INT_MAX);
    add_filter('ll_tools_google_classroom_allow_insecure_callback', static fn($value): bool => $configured
        ? true
        : (bool) $value, PHP_INT_MAX);
}

function ll_tools_google_classroom_admin_e2e_expected_sub_hash(): string {
    return hash_hmac(
        'sha256',
        'google-classroom-sub|' . LL_TOOLS_GOOGLE_CLASSROOM_ADMIN_E2E_SUBJECT,
        str_repeat('E', 32)
    );
}

function ll_tools_google_classroom_admin_e2e_table_exists(string $table): bool {
    global $wpdb;
    return (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
}

/**
 * Remove only the exact deterministic fixture connection/state.
 *
 * @return array{connections:int,states:int}
 */
function ll_tools_google_classroom_admin_e2e_cleanup_database(int $teacher_user_id): array {
    global $wpdb;
    $deleted = ['connections' => 0, 'states' => 0];
    if (!function_exists('ll_tools_google_classroom_table_names')) {
        return $deleted;
    }
    $tables = ll_tools_google_classroom_table_names();
    $connections = (string) ($tables['connections'] ?? '');
    $states = (string) ($tables['oauth_states'] ?? '');
    $expected_hash = ll_tools_google_classroom_admin_e2e_expected_sub_hash();

    if ($teacher_user_id > 0 && $connections !== ''
        && ll_tools_google_classroom_admin_e2e_table_exists($connections)) {
        $result = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$connections} WHERE teacher_user_id = %d AND google_sub_hash = %s",
            $teacher_user_id,
            $expected_hash
        ));
        if ($result === false) {
            ll_tools_google_classroom_admin_e2e_fail('Unable to remove the exact Classroom fixture connection.');
        }
        $deleted['connections'] = (int) $result;
    }

    $config = get_option(LL_TOOLS_GOOGLE_CLASSROOM_ADMIN_E2E_OPTION, []);
    $state_hash = is_array($config) ? strtolower((string) ($config['state_hash'] ?? '')) : '';
    if ($teacher_user_id > 0 && preg_match('/^[a-f0-9]{64}$/D', $state_hash) === 1
        && $states !== '' && ll_tools_google_classroom_admin_e2e_table_exists($states)) {
        $result = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$states} WHERE teacher_user_id = %d AND state_hash = %s",
            $teacher_user_id,
            $state_hash
        ));
        if ($result === false) {
            ll_tools_google_classroom_admin_e2e_fail('Unable to remove the exact Classroom fixture OAuth state.');
        }
        $deleted['states'] = (int) $result;
    }

    return $deleted;
}

function ll_tools_google_classroom_admin_e2e_assert_teacher_has_no_other_connections(int $teacher_user_id): void {
    global $wpdb;
    if (!function_exists('ll_tools_google_classroom_table_names')) {
        return;
    }
    $table = (string) (ll_tools_google_classroom_table_names()['connections'] ?? '');
    if ($table === '' || !ll_tools_google_classroom_admin_e2e_table_exists($table)) {
        return;
    }

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id, google_sub_hash FROM {$table} WHERE teacher_user_id = %d",
        $teacher_user_id
    ), ARRAY_A);
    if (!is_array($rows)) {
        ll_tools_google_classroom_admin_e2e_fail('Unable to inspect existing local Classroom connections.');
    }
    $unexpected = array_filter($rows, static fn(array $row): bool =>
        !hash_equals(
            ll_tools_google_classroom_admin_e2e_expected_sub_hash(),
            (string) ($row['google_sub_hash'] ?? '')
        )
    );
    if ($unexpected !== []) {
        ll_tools_google_classroom_admin_e2e_fail(
            'Refusing to run the Classroom browser fixture for an administrator with non-fixture connections.'
        );
    }
}

function ll_tools_google_classroom_admin_e2e_set_mode(string $mode, int $teacher_user_id): array {
    if (!in_array($mode, ['unconfigured', 'configured'], true)) {
        ll_tools_google_classroom_admin_e2e_fail('Unknown Classroom fixture mode.');
    }
    $config = [
        'schema' => 1,
        'mode' => $mode,
        'expires_at' => time() + 15 * MINUTE_IN_SECONDS,
        'teacher_user_id' => $teacher_user_id,
        'connection_id' => 0,
        'state_hash' => '',
    ];
    update_option(LL_TOOLS_GOOGLE_CLASSROOM_ADMIN_E2E_OPTION, $config, false);
    return $config;
}

function ll_tools_google_classroom_admin_e2e_seed_connection(WP_User $teacher): array {
    if (!function_exists('ll_tools_install_google_classroom_schema')
        || !ll_tools_install_google_classroom_schema()
        || !ll_tools_google_classroom_schema_is_ready(true)) {
        ll_tools_google_classroom_admin_e2e_fail('Unable to prepare the local Classroom schema.');
    }

    $teacher_id = (int) $teacher->ID;
    ll_tools_google_classroom_admin_e2e_cleanup_database($teacher_id);
    ll_tools_google_classroom_admin_e2e_assert_teacher_has_no_other_connections($teacher_id);

    $oauth = ll_tools_google_classroom_create_oauth_state($teacher_id);
    if (is_wp_error($oauth)) {
        ll_tools_google_classroom_admin_e2e_fail('Unable to create a local fixture OAuth state: ' . $oauth->get_error_message());
    }
    $consumed = ll_tools_google_classroom_consume_oauth_state((string) $oauth['state'], $teacher_id);
    if (is_wp_error($consumed)) {
        ll_tools_google_classroom_admin_e2e_fail('Unable to consume the local fixture OAuth state: ' . $consumed->get_error_message());
    }

    $stored = ll_tools_google_classroom_store_connection(
        $teacher_id,
        'll-tools-e2e-refresh-token-never-sent',
        [
            'sub' => LL_TOOLS_GOOGLE_CLASSROOM_ADMIN_E2E_SUBJECT,
            'email' => 'classroom-e2e@example.invalid',
            'email_verified' => true,
            'name' => 'Classroom E2E Teacher',
            'hd' => 'example.invalid',
        ],
        ll_tools_google_classroom_required_scopes(),
        (string) $consumed['state_hash']
    );
    if (is_wp_error($stored)) {
        ll_tools_google_classroom_admin_e2e_fail('Unable to store the local fixture connection: ' . $stored->get_error_message());
    }

    $config = ll_tools_google_classroom_admin_e2e_set_mode('configured', $teacher_id);
    $config['connection_id'] = (int) ($stored['id'] ?? 0);
    $config['state_hash'] = (string) $consumed['state_hash'];
    update_option(LL_TOOLS_GOOGLE_CLASSROOM_ADMIN_E2E_OPTION, $config, false);

    return [
        'ok' => true,
        'mode' => 'configured',
        'teacherUserId' => $teacher_id,
        'connectionId' => (int) $config['connection_id'],
        'accountEmail' => 'classroom-e2e@example.invalid',
        'courseNames' => ['E2E Hebrew I', 'E2E Greek I'],
    ];
}

$fixture_args = isset($args) && is_array($args) ? array_values($args) : [];
$command = sanitize_key((string) ($fixture_args[0] ?? ''));
$stored_config = get_option(LL_TOOLS_GOOGLE_CLASSROOM_ADMIN_E2E_OPTION, []);
$stored_teacher_id = is_array($stored_config) ? (int) ($stored_config['teacher_user_id'] ?? 0) : 0;

if ($command === 'cleanup') {
    ll_tools_google_classroom_admin_e2e_add_cli_config_filters(true);
    $teacher_id = $stored_teacher_id;
    $fixture_user = get_user_by('login', LL_TOOLS_GOOGLE_CLASSROOM_ADMIN_E2E_USER_LOGIN);
    if ($teacher_id <= 0 && $fixture_user instanceof WP_User
        && (string) get_user_meta((int) $fixture_user->ID, LL_TOOLS_GOOGLE_CLASSROOM_ADMIN_E2E_USER_META, true)
            === LL_TOOLS_GOOGLE_CLASSROOM_ADMIN_E2E_SUBJECT) {
        $teacher_id = (int) $fixture_user->ID;
    }
    $database_cleanup = ll_tools_google_classroom_admin_e2e_cleanup_database($teacher_id);
    delete_option(LL_TOOLS_GOOGLE_CLASSROOM_ADMIN_E2E_OPTION);
    $removed_mu_plugin = ll_tools_google_classroom_admin_e2e_remove_mu_plugin();
    $removed_user = ll_tools_google_classroom_admin_e2e_delete_user();
    $result = [
        'ok' => true,
        'databaseCleanup' => $database_cleanup,
        'removedMuPlugin' => $removed_mu_plugin,
        'removedUser' => $removed_user,
    ];
} elseif ($command === 'activate-unconfigured') {
    ll_tools_google_classroom_admin_e2e_add_cli_config_filters(true);
    $old_fixture_user = get_user_by('login', LL_TOOLS_GOOGLE_CLASSROOM_ADMIN_E2E_USER_LOGIN);
    $old_teacher_id = $stored_teacher_id;
    if ($old_fixture_user instanceof WP_User
        && (string) get_user_meta((int) $old_fixture_user->ID, LL_TOOLS_GOOGLE_CLASSROOM_ADMIN_E2E_USER_META, true)
            === LL_TOOLS_GOOGLE_CLASSROOM_ADMIN_E2E_SUBJECT) {
        $old_teacher_id = (int) $old_fixture_user->ID;
    }
    if ($old_teacher_id > 0) {
        ll_tools_google_classroom_admin_e2e_cleanup_database($old_teacher_id);
    }
    ll_tools_google_classroom_admin_e2e_delete_user();
    $teacher_fixture = ll_tools_google_classroom_admin_e2e_create_user();
    ll_tools_google_classroom_admin_e2e_assert_teacher_has_no_other_connections((int) $teacher_fixture['id']);
    $mu_path = ll_tools_google_classroom_admin_e2e_install_mu_plugin();
    ll_tools_google_classroom_admin_e2e_set_mode('unconfigured', (int) $teacher_fixture['id']);
    $result = [
        'ok' => true,
        'mode' => 'unconfigured',
        'muPath' => $mu_path,
        'teacher' => $teacher_fixture,
    ];
} elseif ($command === 'activate-configured') {
    $teacher = ll_tools_google_classroom_admin_e2e_user();
    $mu_path = ll_tools_google_classroom_admin_e2e_install_mu_plugin();
    ll_tools_google_classroom_admin_e2e_set_mode('configured', (int) $teacher->ID);
    $result = ['ok' => true, 'mode' => 'configured', 'muPath' => $mu_path];
} elseif ($command === 'seed') {
    $teacher = ll_tools_google_classroom_admin_e2e_user();
    ll_tools_google_classroom_admin_e2e_add_cli_config_filters(true);
    $result = ll_tools_google_classroom_admin_e2e_seed_connection($teacher);
} else {
    ll_tools_google_classroom_admin_e2e_fail('Unknown Classroom admin fixture command.');
}

echo wp_json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
