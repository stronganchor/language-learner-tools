<?php
if (!defined('WPINC')) { die; }

function ll_tools_site_sync_get_admin_page_slug(): string {
    return 'll-site-sync';
}

function ll_tools_site_sync_connection_option_name(): string {
    return 'll_tools_site_sync_connection';
}

function ll_tools_site_sync_state_option_name(): string {
    return 'll_tools_site_sync_state';
}

function ll_tools_site_sync_get_admin_page_url(array $args = []): string {
    $query_args = array_merge(['page' => ll_tools_site_sync_get_admin_page_slug()], $args);
    return (string) add_query_arg($query_args, admin_url('tools.php'));
}

function ll_tools_site_sync_get_saved_connection(): array {
    $raw = get_option(ll_tools_site_sync_connection_option_name(), []);
    return ll_tools_site_sync_sanitize_connection(is_array($raw) ? $raw : []);
}

function ll_tools_site_sync_sanitize_connection(array $raw): array {
    $remote_url = isset($raw['remote_url']) ? esc_url_raw(trim((string) $raw['remote_url'])) : '';
    $remote_url = untrailingslashit($remote_url);

    return [
        'local_wordset_id' => isset($raw['local_wordset_id']) ? absint($raw['local_wordset_id']) : 0,
        'remote_url' => $remote_url,
        'remote_wordset' => isset($raw['remote_wordset']) ? sanitize_text_field((string) $raw['remote_wordset']) : '',
        'remote_username' => isset($raw['remote_username']) ? sanitize_text_field((string) $raw['remote_username']) : '',
        'surface' => ll_tools_site_sync_normalize_surface((string) ($raw['surface'] ?? 'transcriptions')),
    ];
}

function ll_tools_site_sync_connection_from_request(array $fallback): array {
    $raw = [
        'local_wordset_id' => isset($_POST['ll_site_sync_local_wordset_id'])
            ? wp_unslash((string) $_POST['ll_site_sync_local_wordset_id'])
            : ($fallback['local_wordset_id'] ?? 0),
        'remote_url' => isset($_POST['ll_site_sync_remote_url'])
            ? wp_unslash((string) $_POST['ll_site_sync_remote_url'])
            : ($fallback['remote_url'] ?? ''),
        'remote_wordset' => isset($_POST['ll_site_sync_remote_wordset'])
            ? wp_unslash((string) $_POST['ll_site_sync_remote_wordset'])
            : ($fallback['remote_wordset'] ?? ''),
        'remote_username' => isset($_POST['ll_site_sync_remote_username'])
            ? wp_unslash((string) $_POST['ll_site_sync_remote_username'])
            : ($fallback['remote_username'] ?? ''),
        'surface' => isset($_POST['ll_site_sync_surface'])
            ? wp_unslash((string) $_POST['ll_site_sync_surface'])
            : ($fallback['surface'] ?? 'transcriptions'),
    ];

    return ll_tools_site_sync_sanitize_connection($raw);
}

function ll_tools_site_sync_connection_key(array $connection): string {
    $parts = [
        (string) ($connection['remote_url'] ?? ''),
        (string) ($connection['remote_wordset'] ?? ''),
        (string) ($connection['local_wordset_id'] ?? 0),
        ll_tools_site_sync_normalize_surface((string) ($connection['surface'] ?? 'transcriptions')),
    ];
    return md5(implode('|', $parts));
}

function ll_tools_site_sync_get_state(): array {
    $state = get_option(ll_tools_site_sync_state_option_name(), []);
    return is_array($state) ? $state : [];
}

function ll_tools_site_sync_get_base_snapshot(array $connection): array {
    $state = ll_tools_site_sync_get_state();
    $key = ll_tools_site_sync_connection_key($connection);
    $snapshot = $state[$key]['base_snapshot'] ?? [];
    return is_array($snapshot) ? $snapshot : [];
}

function ll_tools_site_sync_save_base_snapshot(array $connection, array $snapshot): void {
    $state = ll_tools_site_sync_get_state();
    $key = ll_tools_site_sync_connection_key($connection);
    $state[$key] = [
        'updated_at_gmt' => gmdate('c'),
        'connection' => ll_tools_site_sync_sanitize_connection($connection),
        'base_snapshot' => $snapshot,
    ];
    update_option(ll_tools_site_sync_state_option_name(), $state, false);
}

function ll_tools_site_sync_validate_connection(array $connection, string $password = '') {
    if ((int) ($connection['local_wordset_id'] ?? 0) <= 0) {
        return new WP_Error('ll_tools_site_sync_missing_local_wordset', __('Select the local staging word set.', 'll-tools-text-domain'));
    }

    if ((string) ($connection['remote_url'] ?? '') === '') {
        return new WP_Error('ll_tools_site_sync_missing_remote_url', __('Enter the live or remote site URL.', 'll-tools-text-domain'));
    }

    if ((string) ($connection['remote_wordset'] ?? '') === '') {
        return new WP_Error('ll_tools_site_sync_missing_remote_wordset', __('Enter the remote word set slug or ID.', 'll-tools-text-domain'));
    }

    if ((string) ($connection['remote_username'] ?? '') === '') {
        return new WP_Error('ll_tools_site_sync_missing_username', __('Enter the remote WordPress username.', 'll-tools-text-domain'));
    }

    if ($password === '') {
        return new WP_Error('ll_tools_site_sync_missing_password', __('Enter the remote WordPress password or application password for this operation.', 'll-tools-text-domain'));
    }

    $scheme = strtolower((string) wp_parse_url((string) $connection['remote_url'], PHP_URL_SCHEME));
    $host = strtolower((string) wp_parse_url((string) $connection['remote_url'], PHP_URL_HOST));
    $is_local = in_array($host, ['localhost', '127.0.0.1', '::1'], true) || preg_match('/\.(local|test)$/', $host);
    $allowed_insecure = (bool) apply_filters('ll_tools_site_sync_allow_insecure_remote', false, $connection);
    if ($scheme !== 'https' && !$is_local && !$allowed_insecure) {
        return new WP_Error('ll_tools_site_sync_https_required', __('Remote password authentication is only allowed over HTTPS unless the remote host is local development.', 'll-tools-text-domain'));
    }

    return true;
}

function ll_tools_site_sync_remote_request(array $connection, string $method, string $route, string $password, array $body = []) {
    $validation = ll_tools_site_sync_validate_connection($connection, $password);
    if (is_wp_error($validation)) {
        return $validation;
    }

    $remote_url = untrailingslashit((string) $connection['remote_url']);
    $route = '/' . ltrim($route, '/');
    $url = $remote_url . '/wp-json/ll-tools/v1' . $route;

    $args = [
        'method' => strtoupper($method),
        'timeout' => 30,
        'headers' => [
            'Authorization' => 'Basic ' . base64_encode((string) $connection['remote_username'] . ':' . $password),
            'Accept' => 'application/json',
        ],
    ];

    if (!empty($body)) {
        $args['headers']['Content-Type'] = 'application/json';
        $args['body'] = wp_json_encode($body);
    }

    $response = wp_remote_request($url, $args);
    if (is_wp_error($response)) {
        return $response;
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $raw_body = (string) wp_remote_retrieve_body($response);
    $data = json_decode($raw_body, true);
    if (!is_array($data)) {
        return new WP_Error(
            'll_tools_site_sync_bad_remote_response',
            __('The remote site did not return a valid JSON response.', 'll-tools-text-domain'),
            ['status' => $code, 'body' => $raw_body]
        );
    }

    if ($code < 200 || $code >= 300) {
        $message = isset($data['message']) ? (string) $data['message'] : __('The remote site returned an error.', 'll-tools-text-domain');
        return new WP_Error('ll_tools_site_sync_remote_error', $message, ['status' => $code, 'response' => $data]);
    }

    return $data;
}

function ll_tools_site_sync_fetch_remote_snapshot(array $connection, string $password) {
    $remote_wordset = rawurlencode((string) $connection['remote_wordset']);
    $surface = ll_tools_site_sync_normalize_surface((string) ($connection['surface'] ?? 'transcriptions'));
    $route = '/wordsets/' . $remote_wordset . '/site-sync/snapshot?' . http_build_query([
        'surface' => $surface,
        'ensure_sync_ids' => 1,
    ]);

    return ll_tools_site_sync_remote_request($connection, 'GET', $route, $password);
}

function ll_tools_site_sync_send_remote_transcription_updates(array $connection, string $password, array $updates) {
    $updates = array_values(array_filter($updates, 'is_array'));
    if (empty($updates)) {
        return [
            'matched_count' => 0,
            'updated_count' => 0,
            'updated' => [],
            'errors' => [],
        ];
    }

    $remote_wordset = rawurlencode((string) $connection['remote_wordset']);
    return ll_tools_site_sync_remote_request(
        $connection,
        'POST',
        '/wordsets/' . $remote_wordset . '/transcriptions',
        $password,
        ['updates' => $updates]
    );
}

function ll_tools_site_sync_register_admin_page(): void {
    $capability = ll_tools_site_sync_capability();
    add_management_page(
        __('LL Site Sync', 'll-tools-text-domain'),
        __('LL Site Sync', 'll-tools-text-domain'),
        $capability,
        ll_tools_site_sync_get_admin_page_slug(),
        'll_tools_site_sync_render_admin_page'
    );
}
add_action('admin_menu', 'll_tools_site_sync_register_admin_page');

function ll_tools_site_sync_enqueue_admin_assets($hook): void {
    if ((string) $hook !== 'tools_page_' . ll_tools_site_sync_get_admin_page_slug()) {
        return;
    }

    ll_enqueue_asset_by_timestamp('/css/site-sync-admin.css', 'll-tools-site-sync-admin', [], false);
}
add_action('admin_enqueue_scripts', 'll_tools_site_sync_enqueue_admin_assets');

function ll_tools_site_sync_admin_process_request(array &$connection): array {
    $result = [
        'notices' => [],
        'errors' => [],
        'plan' => null,
        'remote_result' => null,
        'pull_result' => null,
        'processed_action' => '',
    ];

    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['ll_site_sync_action'])) {
        return $result;
    }

    if (!current_user_can(ll_tools_site_sync_capability())) {
        $result['errors'][] = __('You do not have permission to use site sync.', 'll-tools-text-domain');
        return $result;
    }

    check_admin_referer('ll_tools_site_sync_action', 'll_site_sync_nonce');

    $action = sanitize_key((string) wp_unslash($_POST['ll_site_sync_action']));
    $result['processed_action'] = $action;
    $connection = ll_tools_site_sync_connection_from_request($connection);
    update_option(ll_tools_site_sync_connection_option_name(), $connection, false);

    if ($action === 'save') {
        $result['notices'][] = __('Site sync connection saved. Passwords are not stored.', 'll-tools-text-domain');
        return $result;
    }

    $password = isset($_POST['ll_site_sync_remote_password'])
        ? (string) wp_unslash($_POST['ll_site_sync_remote_password'])
        : '';
    $validation = ll_tools_site_sync_validate_connection($connection, $password);
    if (is_wp_error($validation)) {
        $result['errors'][] = $validation->get_error_message();
        return $result;
    }

    $local_snapshot = ll_tools_site_sync_build_snapshot(
        (int) $connection['local_wordset_id'],
        (string) $connection['surface'],
        true
    );
    if (is_wp_error($local_snapshot)) {
        $result['errors'][] = $local_snapshot->get_error_message();
        return $result;
    }

    $remote_snapshot = ll_tools_site_sync_fetch_remote_snapshot($connection, $password);
    if (is_wp_error($remote_snapshot)) {
        $result['errors'][] = $remote_snapshot->get_error_message();
        return $result;
    }

    $base_snapshot = ll_tools_site_sync_get_base_snapshot($connection);

    if ($action === 'pull') {
        $plan = ll_tools_site_sync_build_pull_plan($local_snapshot, $remote_snapshot, $base_snapshot);
        $pull_result = ll_tools_site_sync_apply_pull_plan($plan, (int) $connection['local_wordset_id']);
        $merged_base = ll_tools_site_sync_merge_base_snapshot_after_pull($base_snapshot, $remote_snapshot, $plan);
        ll_tools_site_sync_save_base_snapshot($connection, $merged_base);
        $result['plan'] = $plan;
        $result['pull_result'] = $pull_result;
        $result['notices'][] = sprintf(
            /* translators: 1: record count, 2: field count, 3: media reference count */
            __('Pull finished. Updated %1$d local recordings across %2$d fields and %3$d media references.', 'll-tools-text-domain'),
            (int) ($pull_result['records_updated'] ?? 0),
            (int) ($pull_result['fields_updated'] ?? 0),
            (int) ($pull_result['media_refs_updated'] ?? 0)
        );
        foreach ((array) ($pull_result['errors'] ?? []) as $pull_error) {
            $result['errors'][] = (string) $pull_error;
        }
        if (!empty($plan['conflicts'])) {
            $result['errors'][] = sprintf(
                /* translators: %d conflict count */
                __('Pull found %d conflicts. Conflicting fields were not overwritten locally.', 'll-tools-text-domain'),
                count((array) $plan['conflicts'])
            );
        }
        return $result;
    }

    if ($action === 'preview_push') {
        $result['plan'] = ll_tools_site_sync_build_push_plan($local_snapshot, $remote_snapshot, $base_snapshot);
        return $result;
    }

    if ($action === 'apply_push') {
        $plan = ll_tools_site_sync_build_push_plan($local_snapshot, $remote_snapshot, $base_snapshot);
        $result['plan'] = $plan;
        $remote_result = ll_tools_site_sync_send_remote_transcription_updates($connection, $password, (array) ($plan['remote_updates'] ?? []));
        if (is_wp_error($remote_result)) {
            $result['errors'][] = $remote_result->get_error_message();
            return $result;
        }
        $result['remote_result'] = $remote_result;

        $flag_conflicts = !empty($_POST['ll_site_sync_flag_conflicts']);
        if ($flag_conflicts && !empty($plan['conflict_review_updates'])) {
            $conflict_result = ll_tools_site_sync_send_remote_transcription_updates($connection, $password, (array) $plan['conflict_review_updates']);
            if (is_wp_error($conflict_result)) {
                $result['errors'][] = $conflict_result->get_error_message();
                return $result;
            }
            $result['conflict_result'] = $conflict_result;
        }

        $fresh_remote_snapshot = ll_tools_site_sync_fetch_remote_snapshot($connection, $password);
        if (!is_wp_error($fresh_remote_snapshot)) {
            $merged_base = ll_tools_site_sync_merge_base_snapshot_after_pull($base_snapshot, $fresh_remote_snapshot, $plan);
            ll_tools_site_sync_save_base_snapshot($connection, $merged_base);
        }

        $result['notices'][] = sprintf(
            /* translators: 1: remote updated count, 2: conflict count */
            __('Push finished. Applied %1$d remote updates. Conflicts remaining: %2$d.', 'll-tools-text-domain'),
            (int) ($remote_result['updated_count'] ?? 0),
            count((array) ($plan['conflicts'] ?? []))
        );
        return $result;
    }

    $result['errors'][] = __('Unknown site sync action.', 'll-tools-text-domain');
    return $result;
}

function ll_tools_site_sync_get_wordset_options(): array {
    $terms = get_terms([
        'taxonomy' => 'wordset',
        'hide_empty' => false,
        'orderby' => 'name',
        'order' => 'ASC',
    ]);

    return is_wp_error($terms) ? [] : array_values((array) $terms);
}

function ll_tools_site_sync_render_notices(array $result): void {
    foreach ((array) ($result['notices'] ?? []) as $notice) {
        echo '<div class="notice notice-success"><p>' . esc_html((string) $notice) . '</p></div>';
    }

    foreach ((array) ($result['errors'] ?? []) as $error) {
        echo '<div class="notice notice-error"><p>' . esc_html((string) $error) . '</p></div>';
    }
}

function ll_tools_site_sync_render_connection_fields(array $connection): void {
    $wordsets = ll_tools_site_sync_get_wordset_options();
    ?>
    <div class="ll-site-sync-grid">
        <label class="ll-site-sync-field">
            <span><?php esc_html_e('Local staging word set', 'll-tools-text-domain'); ?></span>
            <select name="ll_site_sync_local_wordset_id" required>
                <option value="0"><?php esc_html_e('Select a word set', 'll-tools-text-domain'); ?></option>
                <?php foreach ($wordsets as $wordset) : ?>
                    <?php if ($wordset instanceof WP_Term) : ?>
                        <option value="<?php echo esc_attr((string) $wordset->term_id); ?>" <?php selected((int) ($connection['local_wordset_id'] ?? 0), (int) $wordset->term_id); ?>>
                            <?php echo esc_html($wordset->name . ' (' . $wordset->slug . ')'); ?>
                        </option>
                    <?php endif; ?>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="ll-site-sync-field">
            <span><?php esc_html_e('Remote site URL', 'll-tools-text-domain'); ?></span>
            <input type="url" name="ll_site_sync_remote_url" value="<?php echo esc_attr((string) ($connection['remote_url'] ?? '')); ?>" placeholder="https://example.com" required>
        </label>
        <label class="ll-site-sync-field">
            <span><?php esc_html_e('Remote word set slug or ID', 'll-tools-text-domain'); ?></span>
            <input type="text" name="ll_site_sync_remote_wordset" value="<?php echo esc_attr((string) ($connection['remote_wordset'] ?? '')); ?>" required>
        </label>
        <label class="ll-site-sync-field">
            <span><?php esc_html_e('Remote username', 'll-tools-text-domain'); ?></span>
            <input type="text" name="ll_site_sync_remote_username" value="<?php echo esc_attr((string) ($connection['remote_username'] ?? '')); ?>" autocomplete="username" required>
        </label>
        <label class="ll-site-sync-field">
            <span><?php esc_html_e('Remote password for this action', 'll-tools-text-domain'); ?></span>
            <input type="password" name="ll_site_sync_remote_password" value="" autocomplete="current-password">
        </label>
        <input type="hidden" name="ll_site_sync_surface" value="transcriptions">
    </div>
    <?php
}

function ll_tools_site_sync_render_plan_summary(?array $plan): void {
    if (!is_array($plan)) {
        return;
    }

    $stats = (array) ($plan['stats'] ?? []);
    ?>
    <section class="ll-site-sync-panel">
        <h2><?php esc_html_e('Sync Preview', 'll-tools-text-domain'); ?></h2>
        <div class="ll-site-sync-stat-row">
            <span><?php echo esc_html(sprintf(__('Records checked: %d', 'll-tools-text-domain'), (int) ($stats['records_checked'] ?? 0))); ?></span>
            <span><?php echo esc_html(sprintf(__('Fields to apply: %d', 'll-tools-text-domain'), (int) ($stats['fields_to_apply'] ?? 0))); ?></span>
            <span><?php echo esc_html(sprintf(__('Media refs to apply: %d', 'll-tools-text-domain'), (int) ($stats['media_refs_to_apply'] ?? 0))); ?></span>
            <span><?php echo esc_html(sprintf(__('Conflicts: %d', 'll-tools-text-domain'), (int) ($stats['conflicts'] ?? 0))); ?></span>
            <span><?php echo esc_html(sprintf(__('Skipped: %d', 'll-tools-text-domain'), (int) ($stats['skipped'] ?? 0))); ?></span>
        </div>
        <?php if (!empty($plan['actions'])) : ?>
            <h3><?php esc_html_e('Clean Changes', 'll-tools-text-domain'); ?></h3>
            <table class="widefat striped ll-site-sync-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Word', 'll-tools-text-domain'); ?></th>
                        <th><?php esc_html_e('Recording', 'll-tools-text-domain'); ?></th>
                        <th><?php esc_html_e('Fields', 'll-tools-text-domain'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_slice((array) $plan['actions'], 0, 25) as $action) : ?>
                        <?php $local_record = (array) ($action['local_record'] ?? []); ?>
                        <tr>
                            <td><?php echo esc_html((string) ($local_record['word']['title'] ?? '')); ?></td>
                            <td><?php echo esc_html((string) ($local_record['recording']['title'] ?? '')); ?></td>
                            <td><?php echo esc_html(implode(', ', array_map('strval', (array) ($action['fields'] ?? [])))); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php if (!empty($plan['conflicts'])) : ?>
            <h3><?php esc_html_e('Conflicts', 'll-tools-text-domain'); ?></h3>
            <table class="widefat striped ll-site-sync-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Word', 'll-tools-text-domain'); ?></th>
                        <th><?php esc_html_e('Recording', 'll-tools-text-domain'); ?></th>
                        <th><?php esc_html_e('Field', 'll-tools-text-domain'); ?></th>
                        <th><?php esc_html_e('Staging', 'll-tools-text-domain'); ?></th>
                        <th><?php esc_html_e('Live', 'll-tools-text-domain'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_slice((array) $plan['conflicts'], 0, 25) as $conflict) : ?>
                        <tr>
                            <td><?php echo esc_html((string) ($conflict['word_title'] ?? '')); ?></td>
                            <td><?php echo esc_html((string) ($conflict['recording_title'] ?? '')); ?></td>
                            <td><code><?php echo esc_html((string) ($conflict['field'] ?? '')); ?></code></td>
                            <td><?php echo esc_html(is_array($conflict['local_value'] ?? null) ? implode(', ', (array) $conflict['local_value']) : (string) ($conflict['local_value'] ?? '')); ?></td>
                            <td><?php echo esc_html(is_array($conflict['remote_value'] ?? null) ? implode(', ', (array) $conflict['remote_value']) : (string) ($conflict['remote_value'] ?? '')); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php if (!empty($plan['skipped'])) : ?>
            <p class="description">
                <?php echo esc_html(sprintf(__('Skipped rows are usually recordings that exist on only one site or rows without a previous pull baseline. Count: %d', 'll-tools-text-domain'), count((array) $plan['skipped']))); ?>
            </p>
        <?php endif; ?>
    </section>
    <?php
}

function ll_tools_site_sync_render_admin_page(): void {
    if (!current_user_can(ll_tools_site_sync_capability())) {
        wp_die(__('You do not have permission to use site sync.', 'll-tools-text-domain'));
    }

    $connection = ll_tools_site_sync_get_saved_connection();
    $result = ll_tools_site_sync_admin_process_request($connection);
    $base_snapshot = ll_tools_site_sync_get_base_snapshot($connection);
    ?>
    <div class="wrap ll-site-sync">
        <h1><?php esc_html_e('LL Site Sync', 'll-tools-text-domain'); ?></h1>
        <p class="ll-site-sync-intro"><?php esc_html_e('Sync one word set at a time between this staging site and a live LL Tools site. The first supported sync surface is recording text, transcription, and transcription review state.', 'll-tools-text-domain'); ?></p>
        <?php ll_tools_site_sync_render_notices($result); ?>

        <section class="ll-site-sync-panel">
            <h2><?php esc_html_e('Connection', 'll-tools-text-domain'); ?></h2>
            <form method="post" action="<?php echo esc_url(ll_tools_site_sync_get_admin_page_url()); ?>">
                <?php wp_nonce_field('ll_tools_site_sync_action', 'll_site_sync_nonce'); ?>
                <?php ll_tools_site_sync_render_connection_fields($connection); ?>
                <div class="ll-site-sync-actions">
                    <button type="submit" class="button" name="ll_site_sync_action" value="save"><?php esc_html_e('Save Connection', 'll-tools-text-domain'); ?></button>
                    <button type="submit" class="button button-secondary" name="ll_site_sync_action" value="pull"><?php esc_html_e('Pull Live into Staging', 'll-tools-text-domain'); ?></button>
                    <button type="submit" class="button button-secondary" name="ll_site_sync_action" value="preview_push"><?php esc_html_e('Preview Push to Live', 'll-tools-text-domain'); ?></button>
                </div>
            </form>
        </section>

        <section class="ll-site-sync-panel ll-site-sync-panel--status">
            <h2><?php esc_html_e('Baseline', 'll-tools-text-domain'); ?></h2>
            <?php if (!empty($base_snapshot['records'])) : ?>
                <p><?php echo esc_html(sprintf(__('Last pull baseline: %1$s, %2$d records.', 'll-tools-text-domain'), (string) ($base_snapshot['generated_at_gmt'] ?? ''), count((array) ($base_snapshot['records'] ?? [])))); ?></p>
            <?php else : ?>
                <p><?php esc_html_e('No pull baseline is stored yet. Pull from live before pushing staging changes.', 'll-tools-text-domain'); ?></p>
            <?php endif; ?>
        </section>

        <?php ll_tools_site_sync_render_plan_summary(is_array($result['plan'] ?? null) ? $result['plan'] : null); ?>

        <?php if (is_array($result['plan'] ?? null) && (string) (($result['plan']['direction'] ?? '')) === 'push' && (string) ($result['processed_action'] ?? '') === 'preview_push') : ?>
            <section class="ll-site-sync-panel">
                <h2><?php esc_html_e('Apply Push', 'll-tools-text-domain'); ?></h2>
                <form method="post" action="<?php echo esc_url(ll_tools_site_sync_get_admin_page_url()); ?>">
                    <?php wp_nonce_field('ll_tools_site_sync_action', 'll_site_sync_nonce'); ?>
                    <?php ll_tools_site_sync_render_connection_fields($connection); ?>
                    <label class="ll-site-sync-checkbox">
                        <input type="checkbox" name="ll_site_sync_flag_conflicts" value="1" checked>
                        <span><?php esc_html_e('Flag conflicts on the live site for transcription review instead of silently ignoring them.', 'll-tools-text-domain'); ?></span>
                    </label>
                    <button type="submit" class="button button-primary" name="ll_site_sync_action" value="apply_push"><?php esc_html_e('Apply Clean Push to Live', 'll-tools-text-domain'); ?></button>
                </form>
            </section>
        <?php endif; ?>
    </div>
    <?php
}
