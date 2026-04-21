<?php
/**
 * [ll_site_tools] - Front-end hub for sitewide LL Tools settings and maintenance.
 */

if (!defined('WPINC')) { die; }

function ll_tools_site_tools_user_can_access(): bool {
    return is_user_logged_in() && current_user_can('view_ll_tools');
}

function ll_tools_get_site_tools_settings_capability(): string {
    return (string) apply_filters('ll_tools_site_tools_settings_capability', 'manage_options');
}

function ll_tools_site_tools_current_user_can_manage_settings(): bool {
    return current_user_can(ll_tools_get_site_tools_settings_capability());
}

function ll_tools_site_tools_current_user_can_run_maintenance(): bool {
    if (function_exists('ll_tools_current_user_can_settings_maintenance')) {
        return ll_tools_current_user_can_settings_maintenance();
    }

    return current_user_can('manage_options');
}

function ll_tools_site_tools_normalize_toggle($value): int {
    return absint($value) === 1 ? 1 : 0;
}

function ll_tools_site_tools_normalize_max_options_override($value): int {
    $value = absint($value);
    return $value >= 2 ? $value : 9;
}

function ll_tools_site_tools_normalize_flashcard_image_size($value): string {
    $value = sanitize_key((string) $value);
    return in_array($value, ['small', 'medium', 'large'], true) ? $value : 'small';
}

function ll_tools_site_tools_sync_wordpress_registration_setting(int $enabled): void {
    if (is_multisite()) {
        $current = (string) get_site_option('registration', 'none');

        if ($enabled === 1) {
            $next = in_array($current, ['blog', 'all'], true) ? 'all' : 'user';
        } else {
            $next = in_array($current, ['blog', 'all'], true) ? 'blog' : 'none';
        }

        if ($current !== $next) {
            update_site_option('registration', $next);
        }

        return;
    }

    if ((int) get_option('users_can_register', 0) !== $enabled) {
        update_option('users_can_register', $enabled);
    }
}

function ll_tools_site_tools_get_current_url(): string {
    $current_url = function_exists('ll_tools_get_current_request_url')
        ? (string) ll_tools_get_current_request_url()
        : '';

    if ($current_url === '' && function_exists('ll_tools_get_site_tools_page_url')) {
        $current_url = (string) ll_tools_get_site_tools_page_url(get_current_user_id());
    }

    return $current_url !== '' ? $current_url : home_url('/');
}

function ll_tools_site_tools_resolve_redirect_url(): string {
    $raw = isset($_REQUEST['redirect_to']) ? wp_unslash((string) $_REQUEST['redirect_to']) : '';
    $validated = (string) wp_validate_redirect($raw, '');
    if ($validated !== '') {
        return $validated;
    }

    $page_url = function_exists('ll_tools_get_site_tools_page_url')
        ? (string) ll_tools_get_site_tools_page_url(get_current_user_id())
        : '';

    return $page_url !== '' ? $page_url : home_url('/');
}

function ll_tools_site_tools_build_notice(): ?array {
    $notice = isset($_GET['ll_site_tools_notice'])
        ? sanitize_key(wp_unslash((string) $_GET['ll_site_tools_notice']))
        : '';
    if ($notice === '') {
        return null;
    }

    $section = isset($_GET['ll_site_tools_section'])
        ? sanitize_key(wp_unslash((string) $_GET['ll_site_tools_section']))
        : '';
    $error = isset($_GET['ll_site_tools_error'])
        ? sanitize_key(wp_unslash((string) $_GET['ll_site_tools_error']))
        : '';

    if ($notice === 'settings_saved') {
        $message = __('Site settings saved.', 'll-tools-text-domain');
        if ($section === 'study-defaults') {
            $message = __('Study defaults saved.', 'll-tools-text-domain');
        } elseif ($section === 'learner-accounts') {
            $message = __('Learner account defaults saved.', 'll-tools-text-domain');
        } elseif ($section === 'recording-defaults') {
            $message = __('Recording defaults saved.', 'll-tools-text-domain');
        }

        return [
            'type' => 'success',
            'message' => $message,
        ];
    }

    if ($notice === 'cache_flushed') {
        $deleted = isset($_GET['ll_site_tools_deleted'])
            ? max(0, (int) wp_unslash((string) $_GET['ll_site_tools_deleted']))
            : 0;
        $bumped = isset($_GET['ll_site_tools_bumped'])
            ? max(0, (int) wp_unslash((string) $_GET['ll_site_tools_bumped']))
            : 0;
        $object_cache_flushed = isset($_GET['ll_site_tools_object_cache_flushed'])
            ? ll_tools_site_tools_normalize_toggle(wp_unslash((string) $_GET['ll_site_tools_object_cache_flushed']))
            : 0;

        return [
            'type' => 'success',
            'message' => sprintf(
                /* translators: 1: deleted transient rows, 2: bumped categories, 3: yes/no object cache state */
                __('Flushed quiz caches. Deleted %1$d transient rows, bumped %2$d categories, object cache flushed: %3$s.', 'll-tools-text-domain'),
                $deleted,
                $bumped,
                $object_cache_flushed === 1 ? __('yes', 'll-tools-text-domain') : __('no', 'll-tools-text-domain')
            ),
        ];
    }

    if ($notice === 'legacy_purged') {
        $count = isset($_GET['ll_site_tools_count'])
            ? max(0, (int) wp_unslash((string) $_GET['ll_site_tools_count']))
            : 0;
        $deleted = isset($_GET['ll_site_tools_deleted'])
            ? max(0, (int) wp_unslash((string) $_GET['ll_site_tools_deleted']))
            : 0;

        return [
            'type' => 'success',
            'message' => sprintf(
                /* translators: 1: deleted rows, 2: total rows found */
                __('Purged %1$d legacy audio meta rows out of %2$d found.', 'll-tools-text-domain'),
                $deleted,
                $count
            ),
        ];
    }

    if ($notice === 'error') {
        if ($error === 'permission') {
            return [
                'type' => 'error',
                'message' => __('You do not have permission to change site tools settings.', 'll-tools-text-domain'),
            ];
        }
        if ($error === 'maintenance_permission') {
            return [
                'type' => 'error',
                'message' => __('You do not have permission to run maintenance actions.', 'll-tools-text-domain'),
            ];
        }
        if ($error === 'nonce') {
            return [
                'type' => 'error',
                'message' => __('Your session expired. Please try again.', 'll-tools-text-domain'),
            ];
        }
        if ($error === 'notification_email') {
            return [
                'type' => 'error',
                'message' => __('Please enter a valid notification email address.', 'll-tools-text-domain'),
            ];
        }
        if ($error === 'section') {
            return [
                'type' => 'error',
                'message' => __('That site tools section is not available.', 'll-tools-text-domain'),
            ];
        }
        if ($error === 'maintenance_action') {
            return [
                'type' => 'error',
                'message' => __('That maintenance action is not available.', 'll-tools-text-domain'),
            ];
        }

        return [
            'type' => 'error',
            'message' => __('Unable to complete that site tools request right now.', 'll-tools-text-domain'),
        ];
    }

    return null;
}

function ll_tools_site_tools_render_notice(): string {
    $notice = ll_tools_site_tools_build_notice();
    if (!is_array($notice) || empty($notice['message'])) {
        return '';
    }

    $type = (($notice['type'] ?? 'error') === 'success') ? 'success' : 'error';
    $role = ($type === 'success') ? 'status' : 'alert';

    return '<div class="ll-site-tools-notice ll-site-tools-notice--' . esc_attr($type) . '" role="' . esc_attr($role) . '">' .
        esc_html((string) $notice['message']) .
        '</div>';
}

function ll_tools_handle_save_site_tools_action(): void {
    if (!is_user_logged_in()) {
        auth_redirect();
    }

    $redirect_url = ll_tools_site_tools_resolve_redirect_url();
    $section = isset($_POST['ll_site_tools_section'])
        ? sanitize_key(wp_unslash((string) $_POST['ll_site_tools_section']))
        : '';
    $nonce = isset($_POST['ll_site_tools_nonce'])
        ? wp_unslash((string) $_POST['ll_site_tools_nonce'])
        : '';

    $redirect_error = static function (string $error) use ($redirect_url, $section): void {
        wp_safe_redirect(add_query_arg([
            'll_site_tools_notice' => 'error',
            'll_site_tools_error' => $error,
            'll_site_tools_section' => $section,
        ], $redirect_url));
        exit;
    };

    if ($section === '') {
        $redirect_error('section');
    }

    if (!ll_tools_site_tools_current_user_can_manage_settings()) {
        $redirect_error('permission');
    }

    if (!wp_verify_nonce($nonce, 'll_tools_site_tools_' . $section)) {
        $redirect_error('nonce');
    }

    if ($section === 'study-defaults') {
        $browser_autoswitch = function_exists('ll_tools_normalize_browser_language_autoswitch_setting_value')
            ? ll_tools_normalize_browser_language_autoswitch_setting_value(isset($_POST['ll_enable_browser_language_autoswitch']) ? 1 : 0)
            : ll_tools_site_tools_normalize_toggle(isset($_POST['ll_enable_browser_language_autoswitch']) ? 1 : 0);

        update_option('ll_enable_browser_language_autoswitch', $browser_autoswitch);
        update_option(
            'll_max_options_override',
            ll_tools_site_tools_normalize_max_options_override($_POST['ll_max_options_override'] ?? 9)
        );
        update_option(
            'll_flashcard_image_size',
            ll_tools_site_tools_normalize_flashcard_image_size($_POST['ll_flashcard_image_size'] ?? 'small')
        );
    } elseif ($section === 'learner-accounts') {
        $learner_registration = ll_tools_site_tools_normalize_toggle(isset($_POST['ll_allow_learner_self_registration']) ? 1 : 0);
        $show_generated_password = ll_tools_site_tools_normalize_toggle(isset($_POST['ll_show_generated_registration_password']) ? 1 : 0);
        $send_admin_email = function_exists('ll_tools_sanitize_registration_admin_email_setting')
            ? ll_tools_sanitize_registration_admin_email_setting(isset($_POST['ll_tools_send_registration_admin_email']) ? 1 : 0)
            : ll_tools_site_tools_normalize_toggle(isset($_POST['ll_tools_send_registration_admin_email']) ? 1 : 0);

        update_option('ll_allow_learner_self_registration', $learner_registration);
        ll_tools_site_tools_sync_wordpress_registration_setting($learner_registration);
        update_option('ll_show_generated_registration_password', $show_generated_password);
        update_option('ll_tools_send_registration_admin_email', $send_admin_email);
    } elseif ($section === 'recording-defaults') {
        $notification_email_raw = trim((string) wp_unslash($_POST['ll_tools_recording_notification_email'] ?? ''));
        if ($notification_email_raw !== '') {
            $candidate_email = sanitize_email($notification_email_raw);
            if (!is_email($candidate_email)) {
                $redirect_error('notification_email');
            }
        }

        $notification_email = function_exists('ll_tools_sanitize_recording_notification_email')
            ? ll_tools_sanitize_recording_notification_email($notification_email_raw)
            : sanitize_email($notification_email_raw);
        $notification_delay = function_exists('ll_tools_sanitize_recording_notification_delay_minutes')
            ? ll_tools_sanitize_recording_notification_delay_minutes($_POST['ll_tools_recording_notification_delay_minutes'] ?? 5)
            : max(1, min(1440, absint($_POST['ll_tools_recording_notification_delay_minutes'] ?? 5)));

        update_option('ll_hide_recording_titles', ll_tools_site_tools_normalize_toggle(isset($_POST['ll_hide_recording_titles']) ? 1 : 0));
        update_option('ll_tools_recording_notification_email', $notification_email);
        update_option('ll_tools_recording_notification_delay_minutes', $notification_delay);
    } else {
        $redirect_error('section');
    }

    wp_safe_redirect(add_query_arg([
        'll_site_tools_notice' => 'settings_saved',
        'll_site_tools_section' => $section,
    ], $redirect_url));
    exit;
}
add_action('admin_post_ll_tools_save_site_tools', 'll_tools_handle_save_site_tools_action');

function ll_tools_handle_site_tools_maintenance_action(): void {
    if (!is_user_logged_in()) {
        auth_redirect();
    }

    $redirect_url = ll_tools_site_tools_resolve_redirect_url();
    $maintenance_action = isset($_POST['ll_site_tools_maintenance_action'])
        ? sanitize_key(wp_unslash((string) $_POST['ll_site_tools_maintenance_action']))
        : '';
    $nonce = isset($_POST['ll_site_tools_maintenance_nonce'])
        ? wp_unslash((string) $_POST['ll_site_tools_maintenance_nonce'])
        : '';

    $redirect_error = static function (string $error) use ($redirect_url, $maintenance_action): void {
        wp_safe_redirect(add_query_arg([
            'll_site_tools_notice' => 'error',
            'll_site_tools_error' => $error,
            'll_site_tools_section' => $maintenance_action,
        ], $redirect_url));
        exit;
    };

    if ($maintenance_action === '') {
        $redirect_error('maintenance_action');
    }

    if (!ll_tools_site_tools_current_user_can_run_maintenance()) {
        $redirect_error('maintenance_permission');
    }

    if (!wp_verify_nonce($nonce, 'll_tools_site_tools_maintenance_' . $maintenance_action)) {
        $redirect_error('nonce');
    }

    if ($maintenance_action === 'flush-quiz-caches') {
        $result = function_exists('ll_tools_flush_quiz_word_caches')
            ? (array) ll_tools_flush_quiz_word_caches()
            : ['deleted' => 0, 'bumped' => 0, 'object_cache_flushed' => false];

        wp_safe_redirect(add_query_arg([
            'll_site_tools_notice' => 'cache_flushed',
            'll_site_tools_section' => $maintenance_action,
            'll_site_tools_deleted' => max(0, (int) ($result['deleted'] ?? 0)),
            'll_site_tools_bumped' => max(0, (int) ($result['bumped'] ?? 0)),
            'll_site_tools_object_cache_flushed' => !empty($result['object_cache_flushed']) ? 1 : 0,
        ], $redirect_url));
        exit;
    }

    if ($maintenance_action === 'purge-legacy-audio') {
        $result = function_exists('ll_tools_purge_legacy_word_audio_meta')
            ? (array) ll_tools_purge_legacy_word_audio_meta()
            : ['count' => 0, 'deleted' => 0];

        wp_safe_redirect(add_query_arg([
            'll_site_tools_notice' => 'legacy_purged',
            'll_site_tools_section' => $maintenance_action,
            'll_site_tools_count' => max(0, (int) ($result['count'] ?? 0)),
            'll_site_tools_deleted' => max(0, (int) ($result['deleted'] ?? 0)),
        ], $redirect_url));
        exit;
    }

    $redirect_error('maintenance_action');
}
add_action('admin_post_ll_tools_run_site_tools_maintenance', 'll_tools_handle_site_tools_maintenance_action');

function ll_tools_site_tools_get_workspace_links(): array {
    $links = [];
    $current_user_id = get_current_user_id();

    if (function_exists('ll_tools_get_site_tools_page_url')) {
        $site_tools_url = (string) ll_tools_get_site_tools_page_url($current_user_id);
        if ($site_tools_url !== '') {
            $links[] = [
                'label' => __('Site Tools', 'll-tools-text-domain'),
                'url' => $site_tools_url,
                'description' => __('This page for global settings and maintenance.', 'll-tools-text-domain'),
            ];
        }
    }

    if (function_exists('ll_get_editor_hub_redirect_url')) {
        $editor_hub_url = (string) ll_get_editor_hub_redirect_url($current_user_id);
        if ($editor_hub_url !== '' && $editor_hub_url !== home_url('/')) {
            $links[] = [
                'label' => __('Editor Hub', 'll-tools-text-domain'),
                'url' => $editor_hub_url,
                'description' => __('Complete missing word metadata in the custom editor workflow.', 'll-tools-text-domain'),
            ];
        }
    }

    if (function_exists('ll_get_recording_redirect_url')) {
        $recording_url = (string) ll_get_recording_redirect_url($current_user_id);
        if ($recording_url !== '' && $recording_url !== home_url('/')) {
            $links[] = [
                'label' => __('Recorder', 'll-tools-text-domain'),
                'url' => $recording_url,
                'description' => __('Open the custom recording interface.', 'll-tools-text-domain'),
            ];
        }
    }

    if (function_exists('ll_tools_get_dictionary_page_url')) {
        $dictionary_url = (string) ll_tools_get_dictionary_page_url();
        if ($dictionary_url !== '' && $dictionary_url !== home_url('/')) {
            $links[] = [
                'label' => __('Dictionary', 'll-tools-text-domain'),
                'url' => $dictionary_url,
                'description' => __('Browse the public dictionary page without opening wp-admin.', 'll-tools-text-domain'),
            ];
        }
    }

    return $links;
}

function ll_site_tools_shortcode($atts): string {
    unset($atts);

    $utility_nav = function_exists('ll_tools_render_frontend_user_utility_menu')
        ? ll_tools_render_frontend_user_utility_menu([
            'current_area' => 'site_tools',
        ])
        : '';
    $current_url = ll_tools_site_tools_get_current_url();

    if (!is_user_logged_in()) {
        return $utility_nav . ll_tools_render_login_window([
            'container_class' => 'll-site-tools ll-site-tools--login-required',
            'title' => __('Sign in to access Site Tools', 'll-tools-text-domain'),
            'message' => __('Use an administrator account to manage sitewide LL Tools settings without opening the WordPress dashboard.', 'll-tools-text-domain'),
            'submit_label' => __('Continue', 'll-tools-text-domain'),
            'redirect_to' => $current_url,
        ]);
    }

    if (!ll_tools_site_tools_user_can_access()) {
        return $utility_nav . '<div class="ll-site-tools"><p>' .
            esc_html__('You do not have permission to access Site Tools.', 'll-tools-text-domain') .
            '</p></div>';
    }

    ll_enqueue_asset_by_timestamp('/css/site-tools.css', 'll-site-tools-css');

    $can_manage_settings = ll_tools_site_tools_current_user_can_manage_settings();
    $can_run_maintenance = ll_tools_site_tools_current_user_can_run_maintenance();
    $workspace_links = ll_tools_site_tools_get_workspace_links();
    $notice_html = ll_tools_site_tools_render_notice();

    $browser_autoswitch = function_exists('ll_tools_normalize_browser_language_autoswitch_setting_value')
        ? ll_tools_normalize_browser_language_autoswitch_setting_value(get_option('ll_enable_browser_language_autoswitch', 1))
        : ll_tools_site_tools_normalize_toggle(get_option('ll_enable_browser_language_autoswitch', 1));
    $max_options_override = ll_tools_site_tools_normalize_max_options_override(get_option('ll_max_options_override', 9));
    $flashcard_image_size = ll_tools_site_tools_normalize_flashcard_image_size(get_option('ll_flashcard_image_size', 'small'));
    $allow_learner_registration = ll_tools_site_tools_normalize_toggle(get_option('ll_allow_learner_self_registration', 1));
    $show_generated_password = ll_tools_site_tools_normalize_toggle(get_option('ll_show_generated_registration_password', 1));
    $send_registration_admin_email = ll_tools_site_tools_normalize_toggle(get_option('ll_tools_send_registration_admin_email', 1));
    $hide_recording_titles = ll_tools_site_tools_normalize_toggle(get_option('ll_hide_recording_titles', 0));
    $recording_notification_email = (string) get_option('ll_tools_recording_notification_email', '');
    $recording_notification_delay = function_exists('ll_tools_get_recording_notification_delay_minutes')
        ? (int) ll_tools_get_recording_notification_delay_minutes()
        : max(1, min(1440, (int) get_option('ll_tools_recording_notification_delay_minutes', 5)));

    ob_start();
    ?>
    <?php echo $utility_nav; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
    <section class="ll-site-tools" data-ll-site-tools>
        <header class="ll-site-tools__hero">
            <div class="ll-site-tools__hero-copy">
                <p class="ll-site-tools__eyebrow"><?php echo esc_html__('Custom Admin UI', 'll-tools-text-domain'); ?></p>
                <h1 class="ll-site-tools__title"><?php echo esc_html__('Site Tools', 'll-tools-text-domain'); ?></h1>
                <p class="ll-site-tools__intro"><?php echo esc_html__('This page starts moving sitewide LL Tools settings and maintenance out of the WordPress dashboard into the plugin’s own front-end workspace.', 'll-tools-text-domain'); ?></p>
            </div>
        </header>

        <?php echo $notice_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

        <div class="ll-site-tools__grid">
            <section class="ll-site-tools-card ll-site-tools-card--links">
                <div class="ll-site-tools-card__head">
                    <h2 class="ll-site-tools-card__title"><?php echo esc_html__('Workspace Pages', 'll-tools-text-domain'); ?></h2>
                    <p class="ll-site-tools-card__description"><?php echo esc_html__('Open the existing custom LL Tools pages directly from here.', 'll-tools-text-domain'); ?></p>
                </div>

                <?php if (empty($workspace_links)) : ?>
                    <p class="ll-site-tools-card__empty"><?php echo esc_html__('No custom LL Tools pages are available yet.', 'll-tools-text-domain'); ?></p>
                <?php else : ?>
                    <div class="ll-site-tools-link-grid">
                        <?php foreach ($workspace_links as $workspace_link) : ?>
                            <a class="ll-site-tools-link-card" href="<?php echo esc_url((string) ($workspace_link['url'] ?? '')); ?>">
                                <span class="ll-site-tools-link-card__title"><?php echo esc_html((string) ($workspace_link['label'] ?? '')); ?></span>
                                <span class="ll-site-tools-link-card__description"><?php echo esc_html((string) ($workspace_link['description'] ?? '')); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <section class="ll-site-tools-card">
                <div class="ll-site-tools-card__head">
                    <h2 class="ll-site-tools-card__title"><?php echo esc_html__('Study Defaults', 'll-tools-text-domain'); ?></h2>
                    <p class="ll-site-tools-card__description"><?php echo esc_html__('These are global defaults currently only available in the dashboard settings screen.', 'll-tools-text-domain'); ?></p>
                </div>
                <div class="ll-site-tools-card__meta">
                    <span class="ll-site-tools-pill"><?php echo $browser_autoswitch === 1 ? esc_html__('Browser language auto-detect on', 'll-tools-text-domain') : esc_html__('Browser language auto-detect off', 'll-tools-text-domain'); ?></span>
                    <span class="ll-site-tools-pill"><?php echo esc_html(sprintf(__('Max %d options', 'll-tools-text-domain'), $max_options_override)); ?></span>
                    <span class="ll-site-tools-pill"><?php echo esc_html(ucfirst($flashcard_image_size)); ?></span>
                </div>

                <?php if ($can_manage_settings) : ?>
                    <form class="ll-site-tools-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="ll_tools_save_site_tools" />
                        <input type="hidden" name="ll_site_tools_section" value="study-defaults" />
                        <input type="hidden" name="redirect_to" value="<?php echo esc_attr($current_url); ?>" />
                        <?php wp_nonce_field('ll_tools_site_tools_study-defaults', 'll_site_tools_nonce'); ?>

                        <label class="ll-site-tools-checkbox">
                            <input type="checkbox" name="ll_enable_browser_language_autoswitch" value="1" <?php checked(1, $browser_autoswitch, true); ?> />
                            <span><?php echo esc_html__('Auto-detect browser language on first visit', 'll-tools-text-domain'); ?></span>
                        </label>

                        <div class="ll-site-tools-field-grid">
                            <label class="ll-site-tools-field">
                                <span><?php echo esc_html__('Max answer options', 'll-tools-text-domain'); ?></span>
                                <input type="number" name="ll_max_options_override" min="2" value="<?php echo esc_attr((string) $max_options_override); ?>" />
                            </label>

                            <label class="ll-site-tools-field">
                                <span><?php echo esc_html__('Flashcard image size', 'll-tools-text-domain'); ?></span>
                                <select name="ll_flashcard_image_size">
                                    <option value="small" <?php selected($flashcard_image_size, 'small'); ?>><?php echo esc_html__('Small (150×150)', 'll-tools-text-domain'); ?></option>
                                    <option value="medium" <?php selected($flashcard_image_size, 'medium'); ?>><?php echo esc_html__('Medium (200×200)', 'll-tools-text-domain'); ?></option>
                                    <option value="large" <?php selected($flashcard_image_size, 'large'); ?>><?php echo esc_html__('Large (250×250)', 'll-tools-text-domain'); ?></option>
                                </select>
                            </label>
                        </div>

                        <button type="submit" class="ll-site-tools-button"><?php echo esc_html__('Save Study Defaults', 'll-tools-text-domain'); ?></button>
                    </form>
                <?php else : ?>
                    <p class="ll-site-tools-card__empty"><?php echo esc_html__('Administrator access is required to change sitewide study defaults.', 'll-tools-text-domain'); ?></p>
                <?php endif; ?>
            </section>

            <section class="ll-site-tools-card">
                <div class="ll-site-tools-card__head">
                    <h2 class="ll-site-tools-card__title"><?php echo esc_html__('Learner Accounts', 'll-tools-text-domain'); ?></h2>
                    <p class="ll-site-tools-card__description"><?php echo esc_html__('Registration defaults and admin alerts can now be managed outside the dashboard.', 'll-tools-text-domain'); ?></p>
                </div>
                <div class="ll-site-tools-card__meta">
                    <span class="ll-site-tools-pill"><?php echo $allow_learner_registration === 1 ? esc_html__('Registration open', 'll-tools-text-domain') : esc_html__('Registration closed', 'll-tools-text-domain'); ?></span>
                    <span class="ll-site-tools-pill"><?php echo $show_generated_password === 1 ? esc_html__('Passwords visible by default', 'll-tools-text-domain') : esc_html__('Passwords hidden by default', 'll-tools-text-domain'); ?></span>
                    <span class="ll-site-tools-pill"><?php echo $send_registration_admin_email === 1 ? esc_html__('Admin alerts on', 'll-tools-text-domain') : esc_html__('Admin alerts off', 'll-tools-text-domain'); ?></span>
                </div>

                <?php if ($can_manage_settings) : ?>
                    <form class="ll-site-tools-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="ll_tools_save_site_tools" />
                        <input type="hidden" name="ll_site_tools_section" value="learner-accounts" />
                        <input type="hidden" name="redirect_to" value="<?php echo esc_attr($current_url); ?>" />
                        <?php wp_nonce_field('ll_tools_site_tools_learner-accounts', 'll_site_tools_nonce'); ?>

                        <label class="ll-site-tools-checkbox">
                            <input type="checkbox" name="ll_allow_learner_self_registration" value="1" <?php checked(1, $allow_learner_registration, true); ?> />
                            <span><?php echo esc_html__('Allow learners to create their own accounts', 'll-tools-text-domain'); ?></span>
                        </label>

                        <label class="ll-site-tools-checkbox">
                            <input type="checkbox" name="ll_show_generated_registration_password" value="1" <?php checked(1, $show_generated_password, true); ?> />
                            <span><?php echo esc_html__('Show generated passwords by default on sign-up forms', 'll-tools-text-domain'); ?></span>
                        </label>

                        <label class="ll-site-tools-checkbox">
                            <input type="checkbox" name="ll_tools_send_registration_admin_email" value="1" <?php checked(1, $send_registration_admin_email, true); ?> />
                            <span><?php echo esc_html__('Email an admin when a new learner registers', 'll-tools-text-domain'); ?></span>
                        </label>

                        <button type="submit" class="ll-site-tools-button"><?php echo esc_html__('Save Account Defaults', 'll-tools-text-domain'); ?></button>
                    </form>
                <?php else : ?>
                    <p class="ll-site-tools-card__empty"><?php echo esc_html__('Administrator access is required to change learner account defaults.', 'll-tools-text-domain'); ?></p>
                <?php endif; ?>
            </section>

            <section class="ll-site-tools-card">
                <div class="ll-site-tools-card__head">
                    <h2 class="ll-site-tools-card__title"><?php echo esc_html__('Recording Defaults', 'll-tools-text-domain'); ?></h2>
                    <p class="ll-site-tools-card__description"><?php echo esc_html__('Move recorder-facing defaults and summary email timing into the custom interface.', 'll-tools-text-domain'); ?></p>
                </div>
                <div class="ll-site-tools-card__meta">
                    <span class="ll-site-tools-pill"><?php echo $hide_recording_titles === 1 ? esc_html__('Word titles hidden', 'll-tools-text-domain') : esc_html__('Word titles shown', 'll-tools-text-domain'); ?></span>
                    <span class="ll-site-tools-pill"><?php echo esc_html(sprintf(__('First email after %d min', 'll-tools-text-domain'), $recording_notification_delay)); ?></span>
                    <span class="ll-site-tools-pill"><?php echo esc_html($recording_notification_email !== '' ? $recording_notification_email : __('Using site admin email', 'll-tools-text-domain')); ?></span>
                </div>

                <?php if ($can_manage_settings) : ?>
                    <form class="ll-site-tools-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="ll_tools_save_site_tools" />
                        <input type="hidden" name="ll_site_tools_section" value="recording-defaults" />
                        <input type="hidden" name="redirect_to" value="<?php echo esc_attr($current_url); ?>" />
                        <?php wp_nonce_field('ll_tools_site_tools_recording-defaults', 'll_site_tools_nonce'); ?>

                        <label class="ll-site-tools-checkbox">
                            <input type="checkbox" name="ll_hide_recording_titles" value="1" <?php checked(1, $hide_recording_titles, true); ?> />
                            <span><?php echo esc_html__('Hide word titles for recorders by default', 'll-tools-text-domain'); ?></span>
                        </label>

                        <div class="ll-site-tools-field-grid">
                            <label class="ll-site-tools-field">
                                <span><?php echo esc_html__('Admin notification email', 'll-tools-text-domain'); ?></span>
                                <input type="email" name="ll_tools_recording_notification_email" value="<?php echo esc_attr($recording_notification_email); ?>" placeholder="<?php echo esc_attr(get_option('admin_email', '')); ?>" />
                            </label>

                            <label class="ll-site-tools-field">
                                <span><?php echo esc_html__('First recording summary delay (minutes)', 'll-tools-text-domain'); ?></span>
                                <input type="number" name="ll_tools_recording_notification_delay_minutes" min="1" max="1440" step="1" value="<?php echo esc_attr((string) $recording_notification_delay); ?>" />
                            </label>
                        </div>

                        <button type="submit" class="ll-site-tools-button"><?php echo esc_html__('Save Recording Defaults', 'll-tools-text-domain'); ?></button>
                    </form>
                <?php else : ?>
                    <p class="ll-site-tools-card__empty"><?php echo esc_html__('Administrator access is required to change recording defaults.', 'll-tools-text-domain'); ?></p>
                <?php endif; ?>
            </section>

            <section class="ll-site-tools-card ll-site-tools-card--maintenance">
                <div class="ll-site-tools-card__head">
                    <h2 class="ll-site-tools-card__title"><?php echo esc_html__('Maintenance', 'll-tools-text-domain'); ?></h2>
                    <p class="ll-site-tools-card__description"><?php echo esc_html__('These dashboard-only maintenance actions now have a first custom UI home too.', 'll-tools-text-domain'); ?></p>
                </div>

                <?php if ($can_run_maintenance) : ?>
                    <div class="ll-site-tools-maintenance-list">
                        <form class="ll-site-tools-maintenance-item" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="ll_tools_run_site_tools_maintenance" />
                            <input type="hidden" name="ll_site_tools_maintenance_action" value="flush-quiz-caches" />
                            <input type="hidden" name="redirect_to" value="<?php echo esc_attr($current_url); ?>" />
                            <?php wp_nonce_field('ll_tools_site_tools_maintenance_flush-quiz-caches', 'll_site_tools_maintenance_nonce'); ?>
                            <div class="ll-site-tools-maintenance-item__copy">
                                <h3><?php echo esc_html__('Flush quiz caches', 'll-tools-text-domain'); ?></h3>
                                <p><?php echo esc_html__('Clear cached quiz payloads and bump word-category cache versions.', 'll-tools-text-domain'); ?></p>
                            </div>
                            <button type="submit" class="ll-site-tools-button ll-site-tools-button--secondary"><?php echo esc_html__('Run', 'll-tools-text-domain'); ?></button>
                        </form>

                        <form class="ll-site-tools-maintenance-item" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="ll_tools_run_site_tools_maintenance" />
                            <input type="hidden" name="ll_site_tools_maintenance_action" value="purge-legacy-audio" />
                            <input type="hidden" name="redirect_to" value="<?php echo esc_attr($current_url); ?>" />
                            <?php wp_nonce_field('ll_tools_site_tools_maintenance_purge-legacy-audio', 'll_site_tools_maintenance_nonce'); ?>
                            <div class="ll-site-tools-maintenance-item__copy">
                                <h3><?php echo esc_html__('Purge legacy word audio meta', 'll-tools-text-domain'); ?></h3>
                                <p><?php echo esc_html__('Remove old word_audio_file meta from words posts now that audio lives in child recordings.', 'll-tools-text-domain'); ?></p>
                            </div>
                            <button type="submit" class="ll-site-tools-button ll-site-tools-button--secondary"><?php echo esc_html__('Run', 'll-tools-text-domain'); ?></button>
                        </form>
                    </div>
                <?php else : ?>
                    <p class="ll-site-tools-card__empty"><?php echo esc_html__('Administrator access is required to run maintenance actions.', 'll-tools-text-domain'); ?></p>
                <?php endif; ?>
            </section>
        </div>
    </section>
    <?php

    return (string) ob_get_clean();
}
add_shortcode('ll_site_tools', 'll_site_tools_shortcode');
