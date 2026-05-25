<?php
/**
 * Admin screen for releasing frontend auth rate-limit blocks.
 */

if (!defined('WPINC')) { die; }

if (!defined('LL_TOOLS_LOGIN_BLOCKS_PAGE_SLUG')) {
    define('LL_TOOLS_LOGIN_BLOCKS_PAGE_SLUG', 'll-login-blocks');
}

function ll_tools_get_login_blocks_admin_page_slug(): string {
    return (string) LL_TOOLS_LOGIN_BLOCKS_PAGE_SLUG;
}

function ll_tools_get_login_blocks_admin_capability(): string {
    return (string) apply_filters('ll_tools_login_blocks_admin_capability', 'manage_options');
}

function ll_tools_login_blocks_admin_current_user_can_manage(): bool {
    return current_user_can(ll_tools_get_login_blocks_admin_capability());
}

function ll_tools_login_blocks_admin_url(array $args = []): string {
    if (function_exists('ll_tools_get_tools_page_url')) {
        return ll_tools_get_tools_page_url(ll_tools_get_login_blocks_admin_page_slug(), $args);
    }

    $url = add_query_arg(
        ['page' => ll_tools_get_login_blocks_admin_page_slug()],
        admin_url('tools.php')
    );

    return !empty($args) ? (string) add_query_arg($args, $url) : (string) $url;
}

function ll_tools_register_login_blocks_admin_page(): void {
    add_submenu_page(
        'tools.php',
        __('Login Blocks', 'll-tools-text-domain'),
        __('Login Blocks', 'll-tools-text-domain'),
        ll_tools_get_login_blocks_admin_capability(),
        ll_tools_get_login_blocks_admin_page_slug(),
        'll_tools_render_login_blocks_admin_page'
    );
}
add_action('admin_menu', 'll_tools_register_login_blocks_admin_page');

function ll_tools_login_blocks_format_seconds(int $seconds): string {
    $seconds = max(0, $seconds);
    if ($seconds >= HOUR_IN_SECONDS && $seconds % HOUR_IN_SECONDS === 0) {
        return sprintf(
            /* translators: %d: number of hours */
            _n('%d hour', '%d hours', (int) ($seconds / HOUR_IN_SECONDS), 'll-tools-text-domain'),
            (int) ($seconds / HOUR_IN_SECONDS)
        );
    }

    if ($seconds >= MINUTE_IN_SECONDS) {
        return sprintf(
            /* translators: %d: number of minutes */
            _n('%d minute', '%d minutes', (int) ceil($seconds / MINUTE_IN_SECONDS), 'll-tools-text-domain'),
            (int) ceil($seconds / MINUTE_IN_SECONDS)
        );
    }

    return sprintf(
        /* translators: %d: number of seconds */
        _n('%d second', '%d seconds', $seconds, 'll-tools-text-domain'),
        $seconds
    );
}

function ll_tools_login_blocks_format_release_time(int $expires_at): string {
    if ($expires_at <= 0) {
        return __('Unknown', 'll-tools-text-domain');
    }

    $now = time();
    if ($expires_at <= $now) {
        return __('Any moment now', 'll-tools-text-domain');
    }

    return sprintf(
        /* translators: 1: relative time, 2: exact date/time */
        __('In %1$s (%2$s)', 'll-tools-text-domain'),
        human_time_diff($now, $expires_at),
        wp_date(get_option('date_format') . ' ' . get_option('time_format'), $expires_at)
    );
}

function ll_tools_login_blocks_status_summary(array $statuses): string {
    $types = function_exists('ll_tools_login_window_rate_limit_types')
        ? ll_tools_login_window_rate_limit_types()
        : [];
    $parts = [];

    foreach ($statuses as $type => $status) {
        $attempts = max(0, (int) ($status['attempts'] ?? 0));
        if ($attempts <= 0) {
            continue;
        }

        $label = (string) ($types[$type]['label'] ?? $type);
        $parts[] = sprintf(
            /* translators: 1: block type, 2: attempt count, 3: limit */
            __('%1$s: %2$d/%3$d', 'll-tools-text-domain'),
            $label,
            $attempts,
            max(0, (int) ($status['limit'] ?? 0))
        );
    }

    return implode(', ', $parts);
}

function ll_tools_render_login_blocks_admin_notice(string $type, string $message): void {
    $type = in_array($type, ['success', 'warning', 'error', 'info'], true) ? $type : 'info';
    echo '<div class="notice notice-' . esc_attr($type) . '"><p>' . esc_html($message) . '</p></div>';
}

function ll_tools_render_login_blocks_admin_page(): void {
    if (!ll_tools_login_blocks_admin_current_user_can_manage()) {
        wp_die(__('You do not have permission to release login blocks.', 'll-tools-text-domain'));
    }

    $notice_type = '';
    $notice_message = '';
    $submitted_ip = '';
    $current_ip = function_exists('ll_tools_login_window_get_client_ip')
        ? ll_tools_login_window_get_client_ip()
        : '';

    if (isset($_POST['ll_tools_release_auth_blocks'])) {
        check_admin_referer('ll_tools_release_auth_blocks');

        $submitted_ip = isset($_POST['ll_tools_auth_block_ip'])
            ? (string) wp_unslash($_POST['ll_tools_auth_block_ip'])
            : '';
        $normalized_ip = function_exists('ll_tools_login_window_normalize_ip')
            ? ll_tools_login_window_normalize_ip($submitted_ip)
            : '';

        if ($normalized_ip === '') {
            $notice_type = 'error';
            $notice_message = __('Enter a valid IPv4 or IPv6 address.', 'll-tools-text-domain');
        } elseif (function_exists('ll_tools_login_window_release_rate_limits_for_ip')) {
            $result = ll_tools_login_window_release_rate_limits_for_ip($normalized_ip);
            $summary = ll_tools_login_blocks_status_summary((array) ($result['before'] ?? []));

            if ($summary !== '') {
                $notice_type = 'success';
                $notice_message = sprintf(
                    /* translators: 1: IP address, 2: released status summary */
                    __('Released auth blocks for %1$s. Previous status: %2$s.', 'll-tools-text-domain'),
                    $normalized_ip,
                    $summary
                );
            } else {
                $notice_type = 'success';
                $notice_message = sprintf(
                    /* translators: %s: IP address */
                    __('Cleared any matching auth block records for %s.', 'll-tools-text-domain'),
                    $normalized_ip
                );
            }

            $submitted_ip = $normalized_ip;
        }
    }

    $login_config = function_exists('ll_tools_login_window_login_attempt_limit_config')
        ? ll_tools_login_window_login_attempt_limit_config()
        : ['limit' => 10, 'window' => 10 * MINUTE_IN_SECONDS];
    $active_rows = function_exists('ll_tools_login_window_get_tracked_rate_limits')
        ? ll_tools_login_window_get_tracked_rate_limits(true)
        : [];
    $release_ip_value = $submitted_ip !== '' ? $submitted_ip : $current_ip;
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Login Blocks', 'll-tools-text-domain'); ?></h1>

        <?php
        if ($notice_message !== '') {
            ll_tools_render_login_blocks_admin_notice($notice_type, $notice_message);
        }
        ?>

        <p>
            <?php
            echo esc_html(sprintf(
                /* translators: 1: failed attempt limit, 2: release window */
                __('Frontend login blocks happen after %1$d failed login attempts from the same connection. They expire automatically after %2$s.', 'll-tools-text-domain'),
                max(0, (int) ($login_config['limit'] ?? 0)),
                ll_tools_login_blocks_format_seconds(max(0, (int) ($login_config['window'] ?? 0)))
            ));
            ?>
        </p>

        <form method="post" action="<?php echo esc_url(ll_tools_login_blocks_admin_url()); ?>" style="max-width: 760px; margin: 20px 0;">
            <?php wp_nonce_field('ll_tools_release_auth_blocks'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="ll_tools_auth_block_ip"><?php esc_html_e('Connection IP', 'll-tools-text-domain'); ?></label>
                    </th>
                    <td>
                        <input
                            type="text"
                            class="regular-text"
                            id="ll_tools_auth_block_ip"
                            name="ll_tools_auth_block_ip"
                            value="<?php echo esc_attr($release_ip_value); ?>"
                            placeholder="<?php echo esc_attr__('203.0.113.24', 'll-tools-text-domain'); ?>"
                        />
                        <?php if ($current_ip !== '') : ?>
                            <p class="description">
                                <?php
                                printf(
                                    /* translators: %s: current admin IP address */
                                    esc_html__('This admin request appears to come from %s.', 'll-tools-text-domain'),
                                    '<code>' . esc_html($current_ip) . '</code>'
                                );
                                ?>
                            </p>
                        <?php endif; ?>
                        <p class="description">
                            <?php esc_html_e('Releases login, sign-up, and username-suggestion rate limits for the IP. Older blocks created before active tracking existed may not appear below, but entering the IP here can still clear them.', 'll-tools-text-domain'); ?>
                        </p>
                    </td>
                </tr>
            </table>
            <p class="submit">
                <button type="submit" name="ll_tools_release_auth_blocks" value="1" class="button button-primary">
                    <?php esc_html_e('Release Blocks', 'll-tools-text-domain'); ?>
                </button>
            </p>
        </form>

        <h2><?php esc_html_e('Active Tracked Blocks', 'll-tools-text-domain'); ?></h2>

        <?php if (empty($active_rows)) : ?>
            <p><?php esc_html_e('No active tracked auth blocks were found.', 'll-tools-text-domain'); ?></p>
        <?php else : ?>
            <table class="wp-list-table widefat striped">
                <thead>
                    <tr>
                        <th scope="col"><?php esc_html_e('Connection', 'll-tools-text-domain'); ?></th>
                        <th scope="col"><?php esc_html_e('Block Type', 'll-tools-text-domain'); ?></th>
                        <th scope="col"><?php esc_html_e('Attempts', 'll-tools-text-domain'); ?></th>
                        <th scope="col"><?php esc_html_e('Automatic Release', 'll-tools-text-domain'); ?></th>
                        <th scope="col"><?php esc_html_e('Action', 'll-tools-text-domain'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($active_rows as $row) : ?>
                        <tr>
                            <td><code><?php echo esc_html((string) ($row['ip'] ?? '')); ?></code></td>
                            <td><?php echo esc_html((string) ($row['label'] ?? '')); ?></td>
                            <td>
                                <?php
                                printf(
                                    /* translators: 1: attempt count, 2: limit */
                                    esc_html__('%1$d of %2$d', 'll-tools-text-domain'),
                                    max(0, (int) ($row['attempts'] ?? 0)),
                                    max(0, (int) ($row['limit'] ?? 0))
                                );
                                ?>
                            </td>
                            <td><?php echo esc_html(ll_tools_login_blocks_format_release_time(max(0, (int) ($row['expires_at'] ?? 0)))); ?></td>
                            <td>
                                <form method="post" action="<?php echo esc_url(ll_tools_login_blocks_admin_url()); ?>">
                                    <?php wp_nonce_field('ll_tools_release_auth_blocks'); ?>
                                    <input type="hidden" name="ll_tools_auth_block_ip" value="<?php echo esc_attr((string) ($row['ip'] ?? '')); ?>" />
                                    <button type="submit" name="ll_tools_release_auth_blocks" value="1" class="button button-small">
                                        <?php esc_html_e('Release', 'll-tools-text-domain'); ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
}
