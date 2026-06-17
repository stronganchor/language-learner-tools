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

function ll_tools_site_tools_current_user_can_manage_api_settings(): bool {
    $capability = function_exists('ll_tools_api_settings_capability')
        ? ll_tools_api_settings_capability()
        : 'manage_options';

    return current_user_can($capability);
}

function ll_tools_site_tools_current_user_can_manage_recording_types(): bool {
    return current_user_can('manage_options');
}

function ll_tools_site_tools_current_user_can_run_maintenance(): bool {
    if (function_exists('ll_tools_current_user_can_settings_maintenance')) {
        return ll_tools_current_user_can_settings_maintenance();
    }

    return current_user_can('manage_options');
}

function ll_tools_site_tools_normalize_update_branch($value): string {
    return ((string) $value === 'dev') ? 'dev' : 'main';
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

function ll_tools_site_tools_normalize_language_switcher_primary_count($value): int {
    if (function_exists('ll_tools_normalize_language_switcher_primary_count')) {
        return ll_tools_normalize_language_switcher_primary_count($value);
    }

    return min(20, absint($value));
}

function ll_tools_site_tools_sanitize_language_switcher_locale_order($value): string {
    if (function_exists('ll_tools_sanitize_language_switcher_locale_order')) {
        return ll_tools_sanitize_language_switcher_locale_order($value);
    }

    if (is_array($value)) {
        $value = implode(',', array_map('strval', $value));
    }

    return sanitize_text_field(wp_unslash((string) $value));
}

function ll_tools_site_tools_secret_is_configured(string $option_name): bool {
    return trim((string) get_option($option_name, '')) !== '';
}

function ll_tools_site_tools_sanitize_api_key($value): string {
    return trim(sanitize_text_field(wp_unslash((string) $value)));
}

function ll_tools_site_tools_current_user_can_save_section(string $section): bool {
    if ($section === 'api-providers') {
        return ll_tools_site_tools_current_user_can_manage_api_settings();
    }

    return ll_tools_site_tools_current_user_can_manage_settings();
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

function ll_tools_site_tools_get_managed_page_configs(): array {
    $pages = [];

    if (function_exists('ll_tools_get_site_tools_page_config')) {
        $pages['site-tools'] = ll_tools_get_site_tools_page_config();
    }
    if (function_exists('ll_tools_get_editor_hub_page_config')) {
        $pages['editor-hub'] = ll_tools_get_editor_hub_page_config();
    }
    if (function_exists('ll_tools_get_recording_page_config')) {
        $pages['recording'] = ll_tools_get_recording_page_config();
    }
    if (function_exists('ll_tools_get_dictionary_page_config')) {
        $pages['dictionary'] = ll_tools_get_dictionary_page_config();
    }

    return array_filter($pages, 'is_array');
}

function ll_tools_site_tools_get_page_manager_rows(): array {
    $rows = [];

    foreach (ll_tools_site_tools_get_managed_page_configs() as $page_key => $config) {
        $option_key = isset($config['option_key']) ? (string) $config['option_key'] : '';
        if ($option_key === '') {
            continue;
        }

        $page_id = (int) get_option($option_key);
        $page_url = $page_id > 0 ? (string) ll_tools_get_published_page_permalink($page_id) : '';
        $page_exists = ($page_id > 0 && $page_url !== '');

        $rows[] = [
            'key' => (string) $page_key,
            'label' => (string) ($config['post_title'] ?? ($config['settings_label'] ?? '')),
            'page_id' => $page_exists ? $page_id : 0,
            'exists' => $page_exists,
            'title' => $page_exists ? (string) get_the_title($page_id) : '',
            'url' => $page_url,
            'edit_url' => $page_exists ? (string) get_edit_post_link($page_id) : '',
            'empty_text' => (string) ($config['none_found_text'] ?? ''),
            'create_label' => (string) ($config['create_label'] ?? __('Create', 'll-tools-text-domain')),
            'recreate_label' => (string) ($config['recreate_label'] ?? __('Recreate', 'll-tools-text-domain')),
        ];
    }

    return $rows;
}

function ll_tools_site_tools_get_recording_type_terms(): array {
    $terms = get_terms([
        'taxonomy' => 'recording_type',
        'hide_empty' => false,
        'orderby' => 'name',
        'order' => 'ASC',
    ]);

    if (is_wp_error($terms) || !is_array($terms)) {
        return [];
    }

    return array_values(array_filter($terms, static function ($term): bool {
        return $term instanceof WP_Term;
    }));
}

function ll_tools_site_tools_get_recording_type_rows(): array {
    $rows = [];

    foreach (ll_tools_site_tools_get_recording_type_terms() as $term) {
        $rows[] = [
            'id' => (int) $term->term_id,
            'name' => (string) $term->name,
            'slug' => (string) $term->slug,
            'count' => (int) $term->count,
        ];
    }

    return $rows;
}

function ll_tools_site_tools_sanitize_recording_type_slugs($raw_slugs, array $available_slugs): array {
    $submitted = is_array($raw_slugs) ? $raw_slugs : [];
    $available_map = array_fill_keys(array_values(array_filter(array_map('sanitize_key', $available_slugs))), true);
    $slugs = [];

    foreach ($submitted as $raw_slug) {
        $slug = sanitize_key(wp_unslash((string) $raw_slug));
        if ($slug === '' || !isset($available_map[$slug])) {
            continue;
        }
        $slugs[$slug] = $slug;
    }

    return array_values($slugs);
}

function ll_tools_site_tools_refresh_language_list(): array {
    $languages = get_terms([
        'taxonomy' => 'language',
        'hide_empty' => false,
        'fields' => 'ids',
    ]);

    $deleted = 0;
    if (!is_wp_error($languages)) {
        foreach ((array) $languages as $language_id) {
            $result = wp_delete_term((int) $language_id, 'language');
            if (!is_wp_error($result) && $result !== false) {
                $deleted++;
            }
        }
    }

    update_option('ll_languages_populated', false);
    if (function_exists('ll_tools_ensure_language_taxonomy_terms')) {
        ll_tools_ensure_language_taxonomy_terms();
    }

    $count = wp_count_terms([
        'taxonomy' => 'language',
        'hide_empty' => false,
    ]);

    return [
        'deleted' => $deleted,
        'count' => is_wp_error($count) ? 0 : (int) $count,
    ];
}

function ll_tools_site_tools_get_page_management_label(string $page_key): string {
    $configs = ll_tools_site_tools_get_managed_page_configs();
    $config = $configs[$page_key] ?? null;

    if (is_array($config)) {
        $label = trim((string) ($config['post_title'] ?? ''));
        if ($label === '') {
            $label = trim((string) ($config['settings_label'] ?? ''));
        }
        if ($label !== '') {
            return $label;
        }
    }

    return __('Page', 'll-tools-text-domain');
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
        } elseif ($section === 'privacy-retention') {
            $message = __('Privacy retention settings saved.', 'll-tools-text-domain');
        } elseif ($section === 'plugin-updates') {
            $message = __('Plugin update settings saved.', 'll-tools-text-domain');
        } elseif ($section === 'api-providers') {
            $message = __('API provider keys saved.', 'll-tools-text-domain');
        }

        return [
            'type' => 'success',
            'message' => $message,
        ];
    }

    if ($notice === 'page_managed') {
        return [
            'type' => 'success',
            'message' => sprintf(
                /* translators: %s: managed page label */
                __('%s page is ready.', 'll-tools-text-domain'),
                ll_tools_site_tools_get_page_management_label($section)
            ),
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

    if ($notice === 'recording_type_added') {
        return [
            'type' => 'success',
            'message' => __('Recording type added.', 'll-tools-text-domain'),
        ];
    }

    if ($notice === 'recording_type_deleted') {
        return [
            'type' => 'success',
            'message' => __('Recording type deleted.', 'll-tools-text-domain'),
        ];
    }

    if ($notice === 'recording_type_defaults_saved') {
        return [
            'type' => 'success',
            'message' => __('Uncategorized recording defaults saved.', 'll-tools-text-domain'),
        ];
    }

    if ($notice === 'languages_refreshed') {
        $deleted = isset($_GET['ll_site_tools_deleted'])
            ? max(0, (int) wp_unslash((string) $_GET['ll_site_tools_deleted']))
            : 0;
        $count = isset($_GET['ll_site_tools_count'])
            ? max(0, (int) wp_unslash((string) $_GET['ll_site_tools_count']))
            : 0;

        return [
            'type' => 'success',
            'message' => sprintf(
                /* translators: 1: deleted language terms, 2: current language terms */
                __('Refreshed language list. Removed %1$d old language terms and loaded %2$d current terms.', 'll-tools-text-domain'),
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
        if ($error === 'api_permission') {
            return [
                'type' => 'error',
                'message' => __('You do not have permission to manage API provider keys.', 'll-tools-text-domain'),
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
        if ($error === 'page_key') {
            return [
                'type' => 'error',
                'message' => __('That managed page is not available.', 'll-tools-text-domain'),
            ];
        }
        if ($error === 'page_create_failed') {
            return [
                'type' => 'error',
                'message' => __('Unable to create or reuse that managed page right now.', 'll-tools-text-domain'),
            ];
        }
        if ($error === 'maintenance_action') {
            return [
                'type' => 'error',
                'message' => __('That maintenance action is not available.', 'll-tools-text-domain'),
            ];
        }
        if ($error === 'recording_type_action') {
            return [
                'type' => 'error',
                'message' => __('That recording type action is not available.', 'll-tools-text-domain'),
            ];
        }
        if ($error === 'recording_type_name') {
            return [
                'type' => 'error',
                'message' => __('Enter a recording type name.', 'll-tools-text-domain'),
            ];
        }
        if ($error === 'recording_type_save') {
            return [
                'type' => 'error',
                'message' => __('Unable to save that recording type right now.', 'll-tools-text-domain'),
            ];
        }
        if ($error === 'plugin_update_permission') {
            return [
                'type' => 'error',
                'message' => __('You do not have permission to manage plugin updates.', 'll-tools-text-domain'),
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

    if (!ll_tools_site_tools_current_user_can_save_section($section)) {
        $redirect_error($section === 'api-providers' ? 'api_permission' : 'permission');
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
        update_option(
            defined('LL_TOOLS_LANGUAGE_SWITCHER_PRIMARY_COUNT_OPTION') ? LL_TOOLS_LANGUAGE_SWITCHER_PRIMARY_COUNT_OPTION : 'll_language_switcher_primary_count',
            ll_tools_site_tools_normalize_language_switcher_primary_count($_POST['ll_language_switcher_primary_count'] ?? 3)
        );
        update_option(
            defined('LL_TOOLS_LANGUAGE_SWITCHER_LOCALE_ORDER_OPTION') ? LL_TOOLS_LANGUAGE_SWITCHER_LOCALE_ORDER_OPTION : 'll_language_switcher_locale_order',
            ll_tools_site_tools_sanitize_language_switcher_locale_order($_POST['ll_language_switcher_locale_order'] ?? '')
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
    } elseif ($section === 'privacy-retention') {
        $retention_option_name = defined('LL_TOOLS_USER_PROGRESS_RETENTION_OPTION')
            ? LL_TOOLS_USER_PROGRESS_RETENTION_OPTION
            : 'll_user_progress_events_retention_days';
        $retention_days = function_exists('ll_tools_sanitize_user_progress_retention_days')
            ? ll_tools_sanitize_user_progress_retention_days($_POST[$retention_option_name] ?? 180)
            : max(30, min(1095, absint($_POST[$retention_option_name] ?? 180)));

        update_option($retention_option_name, $retention_days);
    } elseif ($section === 'plugin-updates') {
        if (!function_exists('ll_tools_user_can_manage_plugin_updates') || !ll_tools_user_can_manage_plugin_updates()) {
            $redirect_error('plugin_update_permission');
        }

        update_option(
            'll_update_branch',
            ll_tools_site_tools_normalize_update_branch($_POST['ll_update_branch'] ?? 'main')
        );
    } elseif ($section === 'api-providers') {
        if (!ll_tools_site_tools_current_user_can_manage_api_settings()) {
            $redirect_error('api_permission');
        }

        if (isset($_POST['ll_deepl_api_key_clear'])) {
            delete_option('ll_deepl_api_key');
            delete_transient('deepl_language_json_source');
            delete_transient('deepl_language_json_target');
        } else {
            $deepl_api_key = ll_tools_site_tools_sanitize_api_key($_POST['ll_deepl_api_key'] ?? '');
            if ($deepl_api_key !== '') {
                update_option('ll_deepl_api_key', $deepl_api_key);
                delete_transient('deepl_language_json_source');
                delete_transient('deepl_language_json_target');
            }
        }

        if (isset($_POST['ll_assemblyai_api_key_clear'])) {
            delete_option('ll_assemblyai_api_key');
        } else {
            $assemblyai_api_key = ll_tools_site_tools_sanitize_api_key($_POST['ll_assemblyai_api_key'] ?? '');
            if ($assemblyai_api_key !== '') {
                update_option('ll_assemblyai_api_key', $assemblyai_api_key);
            }
        }
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

function ll_tools_handle_site_tools_page_management_action(): void {
    if (!is_user_logged_in()) {
        auth_redirect();
    }

    $redirect_url = ll_tools_site_tools_resolve_redirect_url();
    $page_key = isset($_POST['ll_site_tools_page_key'])
        ? sanitize_key(wp_unslash((string) $_POST['ll_site_tools_page_key']))
        : '';
    $page_mode = isset($_POST['ll_site_tools_page_mode'])
        ? sanitize_key(wp_unslash((string) $_POST['ll_site_tools_page_mode']))
        : 'create';
    $nonce = isset($_POST['ll_site_tools_page_nonce'])
        ? wp_unslash((string) $_POST['ll_site_tools_page_nonce'])
        : '';

    $redirect_error = static function (string $error) use ($redirect_url, $page_key): void {
        wp_safe_redirect(add_query_arg([
            'll_site_tools_notice' => 'error',
            'll_site_tools_error' => $error,
            'll_site_tools_section' => $page_key,
        ], $redirect_url));
        exit;
    };

    if ($page_key === '') {
        $redirect_error('page_key');
    }

    if (!ll_tools_site_tools_current_user_can_manage_settings()) {
        $redirect_error('permission');
    }

    if (!wp_verify_nonce($nonce, 'll_tools_site_tools_page_' . $page_key)) {
        $redirect_error('nonce');
    }

    $configs = ll_tools_site_tools_get_managed_page_configs();
    $config = $configs[$page_key] ?? null;
    if (!is_array($config)) {
        $redirect_error('page_key');
    }

    if ($page_mode === 'recreate') {
        $force_option_key = isset($config['force_option_key']) ? (string) $config['force_option_key'] : '';
        if ($force_option_key !== '') {
            update_option($force_option_key, 1);
        }
    }

    $page_id = function_exists('ll_tools_ensure_default_shortcode_page')
        ? (int) ll_tools_ensure_default_shortcode_page($config)
        : 0;
    if ($page_id <= 0) {
        $redirect_error('page_create_failed');
    }

    wp_safe_redirect(add_query_arg([
        'll_site_tools_notice' => 'page_managed',
        'll_site_tools_section' => $page_key,
    ], $redirect_url));
    exit;
}
add_action('admin_post_ll_tools_manage_site_tools_page', 'll_tools_handle_site_tools_page_management_action');

function ll_tools_handle_site_tools_recording_type_action(): void {
    if (!is_user_logged_in()) {
        auth_redirect();
    }

    $redirect_url = ll_tools_site_tools_resolve_redirect_url();
    $recording_type_action = isset($_POST['ll_site_tools_recording_type_action'])
        ? sanitize_key(wp_unslash((string) $_POST['ll_site_tools_recording_type_action']))
        : '';
    $nonce = isset($_POST['ll_site_tools_recording_type_nonce'])
        ? wp_unslash((string) $_POST['ll_site_tools_recording_type_nonce'])
        : '';

    $redirect_error = static function (string $error) use ($redirect_url): void {
        wp_safe_redirect(add_query_arg([
            'll_site_tools_notice' => 'error',
            'll_site_tools_error' => $error,
            'll_site_tools_section' => 'recording-types',
        ], $redirect_url));
        exit;
    };

    if ($recording_type_action === '') {
        $redirect_error('recording_type_action');
    }

    if (!ll_tools_site_tools_current_user_can_manage_recording_types()) {
        $redirect_error('permission');
    }

    if (!wp_verify_nonce($nonce, 'll_tools_site_tools_recording_type_' . $recording_type_action)) {
        $redirect_error('nonce');
    }

    if ($recording_type_action === 'add') {
        $name = trim(sanitize_text_field(wp_unslash((string) ($_POST['term_name'] ?? ''))));
        if ($name === '') {
            $redirect_error('recording_type_name');
        }

        $slug = sanitize_title(wp_unslash((string) ($_POST['term_slug'] ?? '')));
        $result = wp_insert_term($name, 'recording_type', [
            'slug' => $slug !== '' ? $slug : sanitize_title($name),
        ]);
        if (is_wp_error($result)) {
            $redirect_error('recording_type_save');
        }

        wp_safe_redirect(add_query_arg([
            'll_site_tools_notice' => 'recording_type_added',
            'll_site_tools_section' => 'recording-types',
        ], $redirect_url));
        exit;
    }

    if ($recording_type_action === 'delete') {
        $term_id = isset($_POST['term_id']) ? max(0, (int) wp_unslash((string) $_POST['term_id'])) : 0;
        if ($term_id <= 0) {
            $redirect_error('recording_type_action');
        }

        $result = wp_delete_term($term_id, 'recording_type');
        if (is_wp_error($result) || $result === false) {
            $redirect_error('recording_type_save');
        }

        wp_safe_redirect(add_query_arg([
            'll_site_tools_notice' => 'recording_type_deleted',
            'll_site_tools_section' => 'recording-types',
        ], $redirect_url));
        exit;
    }

    if ($recording_type_action === 'defaults') {
        $available_slugs = array_map(static function (array $row): string {
            return (string) ($row['slug'] ?? '');
        }, ll_tools_site_tools_get_recording_type_rows());
        $selected_slugs = ll_tools_site_tools_sanitize_recording_type_slugs(
            $_POST['ll_uncategorized_desired_recording_types'] ?? [],
            $available_slugs
        );

        if (empty($selected_slugs)) {
            delete_option('ll_uncategorized_desired_recording_types');
        } else {
            update_option('ll_uncategorized_desired_recording_types', $selected_slugs);
        }

        wp_safe_redirect(add_query_arg([
            'll_site_tools_notice' => 'recording_type_defaults_saved',
            'll_site_tools_section' => 'recording-types',
        ], $redirect_url));
        exit;
    }

    $redirect_error('recording_type_action');
}
add_action('admin_post_ll_tools_site_tools_recording_type', 'll_tools_handle_site_tools_recording_type_action');

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

    if ($maintenance_action === 'refresh-languages') {
        $result = ll_tools_site_tools_refresh_language_list();

        wp_safe_redirect(add_query_arg([
            'll_site_tools_notice' => 'languages_refreshed',
            'll_site_tools_section' => $maintenance_action,
            'll_site_tools_deleted' => max(0, (int) ($result['deleted'] ?? 0)),
            'll_site_tools_count' => max(0, (int) ($result['count'] ?? 0)),
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
    $can_manage_api_settings = ll_tools_site_tools_current_user_can_manage_api_settings();
    $can_manage_recording_types = ll_tools_site_tools_current_user_can_manage_recording_types();
    $can_manage_plugin_updates = function_exists('ll_tools_user_can_manage_plugin_updates') && ll_tools_user_can_manage_plugin_updates();
    $workspace_links = ll_tools_site_tools_get_workspace_links();
    $page_manager_rows = ll_tools_site_tools_get_page_manager_rows();
    $recording_type_rows = ll_tools_site_tools_get_recording_type_rows();
    $notice_html = ll_tools_site_tools_render_notice();

    $browser_autoswitch = function_exists('ll_tools_normalize_browser_language_autoswitch_setting_value')
        ? ll_tools_normalize_browser_language_autoswitch_setting_value(get_option('ll_enable_browser_language_autoswitch', 1))
        : ll_tools_site_tools_normalize_toggle(get_option('ll_enable_browser_language_autoswitch', 1));
    $max_options_override = ll_tools_site_tools_normalize_max_options_override(get_option('ll_max_options_override', 9));
    $flashcard_image_size = ll_tools_site_tools_normalize_flashcard_image_size(get_option('ll_flashcard_image_size', 'small'));
    $language_switcher_primary_count_option = defined('LL_TOOLS_LANGUAGE_SWITCHER_PRIMARY_COUNT_OPTION') ? LL_TOOLS_LANGUAGE_SWITCHER_PRIMARY_COUNT_OPTION : 'll_language_switcher_primary_count';
    $language_switcher_locale_order_option = defined('LL_TOOLS_LANGUAGE_SWITCHER_LOCALE_ORDER_OPTION') ? LL_TOOLS_LANGUAGE_SWITCHER_LOCALE_ORDER_OPTION : 'll_language_switcher_locale_order';
    $language_switcher_primary_count = function_exists('ll_tools_get_language_switcher_primary_count_setting')
        ? ll_tools_get_language_switcher_primary_count_setting()
        : ll_tools_site_tools_normalize_language_switcher_primary_count(get_option($language_switcher_primary_count_option, 3));
    $language_switcher_locale_order = (string) get_option($language_switcher_locale_order_option, '');
    $allow_learner_registration = ll_tools_site_tools_normalize_toggle(get_option('ll_allow_learner_self_registration', 1));
    $show_generated_password = ll_tools_site_tools_normalize_toggle(get_option('ll_show_generated_registration_password', 1));
    $send_registration_admin_email = ll_tools_site_tools_normalize_toggle(get_option('ll_tools_send_registration_admin_email', 1));
    $hide_recording_titles = ll_tools_site_tools_normalize_toggle(get_option('ll_hide_recording_titles', 0));
    $recording_notification_email = (string) get_option('ll_tools_recording_notification_email', '');
    $recording_notification_delay = function_exists('ll_tools_get_recording_notification_delay_minutes')
        ? (int) ll_tools_get_recording_notification_delay_minutes()
        : max(1, min(1440, (int) get_option('ll_tools_recording_notification_delay_minutes', 5)));
    $retention_option_name = defined('LL_TOOLS_USER_PROGRESS_RETENTION_OPTION')
        ? LL_TOOLS_USER_PROGRESS_RETENTION_OPTION
        : 'll_user_progress_events_retention_days';
    $retention_days = function_exists('ll_tools_get_user_progress_retention_days')
        ? (int) ll_tools_get_user_progress_retention_days()
        : max(30, min(1095, (int) get_option($retention_option_name, 180)));
    $update_branch = function_exists('ll_tools_get_update_branch')
        ? (string) ll_tools_get_update_branch()
        : ll_tools_site_tools_normalize_update_branch(get_option('ll_update_branch', 'main'));
    $plugin_update_status = function_exists('ll_tools_get_plugin_update_status_details')
        ? ll_tools_get_plugin_update_status_details()
        : ['status' => 'unknown', 'version' => '', 'raw' => null];
    $plugin_update_status_name = is_array($plugin_update_status)
        ? (string) ($plugin_update_status['status'] ?? 'unknown')
        : 'unknown';
    $plugin_update_version = is_array($plugin_update_status)
        ? (string) ($plugin_update_status['version'] ?? '')
        : '';
    $plugin_update_status_label = __('Status unknown', 'll-tools-text-domain');
    if ($plugin_update_status_name === 'available' && $plugin_update_version !== '') {
        $plugin_update_status_label = sprintf(
            /* translators: %s: plugin version */
            __('Update %s available', 'll-tools-text-domain'),
            $plugin_update_version
        );
    } elseif ($plugin_update_status_name === 'none') {
        $plugin_update_status_label = __('No update detected', 'll-tools-text-domain');
    }
    $plugin_update_action_url = ($can_manage_plugin_updates && function_exists('ll_tools_get_plugin_update_action_url'))
        ? (string) ll_tools_get_plugin_update_action_url()
        : '';
    $plugin_update_check_url = ($can_manage_plugin_updates && function_exists('ll_tools_get_plugin_update_check_action_url'))
        ? (string) ll_tools_get_plugin_update_check_action_url($current_url)
        : '';
    $deepl_configured = ll_tools_site_tools_secret_is_configured('ll_deepl_api_key');
    $assemblyai_configured = ll_tools_site_tools_secret_is_configured('ll_assemblyai_api_key');
    $current_uncategorized_recording_types = function_exists('ll_tools_get_uncategorized_desired_recording_types')
        ? ll_tools_get_uncategorized_desired_recording_types()
        : [];
    $current_uncategorized_recording_types = array_values(array_unique(array_filter(array_map('sanitize_key', (array) $current_uncategorized_recording_types))));

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
                    <span class="ll-site-tools-pill"><?php echo $language_switcher_primary_count > 0 ? esc_html(sprintf(__('Switcher shows %d first', 'll-tools-text-domain'), $language_switcher_primary_count)) : esc_html__('Switcher shows all', 'll-tools-text-domain'); ?></span>
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

                            <label class="ll-site-tools-field">
                                <span><?php echo esc_html__('Language switcher buttons', 'll-tools-text-domain'); ?></span>
                                <input
                                    type="number"
                                    name="ll_language_switcher_primary_count"
                                    min="0"
                                    max="<?php echo esc_attr((string) (defined('LL_TOOLS_LANGUAGE_SWITCHER_MAX_PRIMARY_COUNT') ? LL_TOOLS_LANGUAGE_SWITCHER_MAX_PRIMARY_COUNT : 20)); ?>"
                                    value="<?php echo esc_attr((string) $language_switcher_primary_count); ?>" />
                            </label>

                            <label class="ll-site-tools-field">
                                <span><?php echo esc_html__('Language switcher order', 'll-tools-text-domain'); ?></span>
                                <input
                                    type="text"
                                    name="ll_language_switcher_locale_order"
                                    class="code"
                                    value="<?php echo esc_attr($language_switcher_locale_order); ?>"
                                    placeholder="tr_TR,en_US,de_DE" />
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
                            <span><?php echo esc_html__('Hide word titles by default when no wordset recorder-text setting applies', 'll-tools-text-domain'); ?></span>
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

            <section class="ll-site-tools-card ll-site-tools-card--recording-types">
                <div class="ll-site-tools-card__head">
                    <h2 class="ll-site-tools-card__title"><?php echo esc_html__('Recording Types', 'll-tools-text-domain'); ?></h2>
                    <p class="ll-site-tools-card__description"><?php echo esc_html__('Manage recording type labels and the fallback prompts used when a word has no category without opening wp-admin.', 'll-tools-text-domain'); ?></p>
                </div>
                <div class="ll-site-tools-card__meta">
                    <span class="ll-site-tools-pill">
                        <?php
                        echo esc_html(
                            sprintf(
                                /* translators: %d: number of recording types */
                                _n('%d recording type', '%d recording types', count($recording_type_rows), 'll-tools-text-domain'),
                                count($recording_type_rows)
                            )
                        );
                        ?>
                    </span>
                    <span class="ll-site-tools-pill">
                        <?php echo esc_html(!empty($current_uncategorized_recording_types) ? implode(', ', $current_uncategorized_recording_types) : __('Default fallback', 'll-tools-text-domain')); ?>
                    </span>
                </div>

                <?php if ($can_manage_recording_types) : ?>
                    <div class="ll-site-tools-recording-layout">
                        <form class="ll-site-tools-form ll-site-tools-recording-add" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="ll_tools_site_tools_recording_type" />
                            <input type="hidden" name="ll_site_tools_recording_type_action" value="add" />
                            <input type="hidden" name="redirect_to" value="<?php echo esc_attr($current_url); ?>" />
                            <?php wp_nonce_field('ll_tools_site_tools_recording_type_add', 'll_site_tools_recording_type_nonce'); ?>

                            <div class="ll-site-tools-field-grid">
                                <label class="ll-site-tools-field">
                                    <span><?php echo esc_html__('Name', 'll-tools-text-domain'); ?></span>
                                    <input type="text" name="term_name" required />
                                </label>

                                <label class="ll-site-tools-field">
                                    <span><?php echo esc_html__('Slug', 'll-tools-text-domain'); ?></span>
                                    <input type="text" name="term_slug" />
                                </label>
                            </div>

                            <button type="submit" class="ll-site-tools-button"><?php echo esc_html__('Add Recording Type', 'll-tools-text-domain'); ?></button>
                        </form>

                        <div class="ll-site-tools-recording-list" aria-label="<?php echo esc_attr__('Existing recording types', 'll-tools-text-domain'); ?>">
                            <?php if (empty($recording_type_rows)) : ?>
                                <p class="ll-site-tools-card__empty"><?php echo esc_html__('No recording types have been created yet.', 'll-tools-text-domain'); ?></p>
                            <?php else : ?>
                                <?php foreach ($recording_type_rows as $recording_type_row) : ?>
                                    <div class="ll-site-tools-recording-item">
                                        <div class="ll-site-tools-recording-item__copy">
                                            <h3><?php echo esc_html((string) ($recording_type_row['name'] ?? '')); ?></h3>
                                            <p>
                                                <code><?php echo esc_html((string) ($recording_type_row['slug'] ?? '')); ?></code>
                                                <?php
                                                echo esc_html(
                                                    sprintf(
                                                        /* translators: %d: recording count */
                                                        _n('%d recording', '%d recordings', (int) ($recording_type_row['count'] ?? 0), 'll-tools-text-domain'),
                                                        (int) ($recording_type_row['count'] ?? 0)
                                                    )
                                                );
                                                ?>
                                            </p>
                                        </div>
                                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                            <input type="hidden" name="action" value="ll_tools_site_tools_recording_type" />
                                            <input type="hidden" name="ll_site_tools_recording_type_action" value="delete" />
                                            <input type="hidden" name="term_id" value="<?php echo esc_attr((string) ($recording_type_row['id'] ?? 0)); ?>" />
                                            <input type="hidden" name="redirect_to" value="<?php echo esc_attr($current_url); ?>" />
                                            <?php wp_nonce_field('ll_tools_site_tools_recording_type_delete', 'll_site_tools_recording_type_nonce'); ?>
                                            <button type="submit" class="ll-site-tools-button ll-site-tools-button--ghost"><?php echo esc_html__('Delete', 'll-tools-text-domain'); ?></button>
                                        </form>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <form class="ll-site-tools-form ll-site-tools-recording-defaults" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="ll_tools_site_tools_recording_type" />
                            <input type="hidden" name="ll_site_tools_recording_type_action" value="defaults" />
                            <input type="hidden" name="redirect_to" value="<?php echo esc_attr($current_url); ?>" />
                            <?php wp_nonce_field('ll_tools_site_tools_recording_type_defaults', 'll_site_tools_recording_type_nonce'); ?>

                            <h3><?php echo esc_html__('Uncategorized Defaults', 'll-tools-text-domain'); ?></h3>
                            <p class="ll-site-tools-note"><?php echo esc_html__('Choose recording types to prompt for when a word has no category. Leave all unchecked to return to the plugin fallback.', 'll-tools-text-domain'); ?></p>
                            <div class="ll-site-tools-checkbox-list">
                                <?php if (empty($recording_type_rows)) : ?>
                                    <p class="ll-site-tools-card__empty"><?php echo esc_html__('Create a recording type before selecting fallback prompts.', 'll-tools-text-domain'); ?></p>
                                <?php else : ?>
                                    <?php foreach ($recording_type_rows as $recording_type_row) : ?>
                                        <?php $slug = (string) ($recording_type_row['slug'] ?? ''); ?>
                                        <label class="ll-site-tools-checkbox">
                                            <input type="checkbox" name="ll_uncategorized_desired_recording_types[]" value="<?php echo esc_attr($slug); ?>" <?php checked(in_array($slug, $current_uncategorized_recording_types, true)); ?> />
                                            <span><?php echo esc_html((string) ($recording_type_row['name'] ?? '')); ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            <button type="submit" class="ll-site-tools-button ll-site-tools-button--secondary"><?php echo esc_html__('Save Uncategorized Defaults', 'll-tools-text-domain'); ?></button>
                        </form>
                    </div>
                <?php else : ?>
                    <p class="ll-site-tools-card__empty"><?php echo esc_html__('Administrator access is required to manage recording types.', 'll-tools-text-domain'); ?></p>
                <?php endif; ?>
            </section>

            <section class="ll-site-tools-card">
                <div class="ll-site-tools-card__head">
                    <h2 class="ll-site-tools-card__title"><?php echo esc_html__('Privacy & Retention', 'll-tools-text-domain'); ?></h2>
                    <p class="ll-site-tools-card__description"><?php echo esc_html__('Move the learner progress retention control out of the dashboard settings table and into the front-end admin workspace.', 'll-tools-text-domain'); ?></p>
                </div>
                <div class="ll-site-tools-card__meta">
                    <span class="ll-site-tools-pill">
                        <?php
                        echo esc_html(
                            sprintf(
                                /* translators: %d: number of days */
                                __('Keep event details for %d days', 'll-tools-text-domain'),
                                $retention_days
                            )
                        );
                        ?>
                    </span>
                </div>

                <?php if ($can_manage_settings) : ?>
                    <form class="ll-site-tools-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="ll_tools_save_site_tools" />
                        <input type="hidden" name="ll_site_tools_section" value="privacy-retention" />
                        <input type="hidden" name="redirect_to" value="<?php echo esc_attr($current_url); ?>" />
                        <?php wp_nonce_field('ll_tools_site_tools_privacy-retention', 'll_site_tools_nonce'); ?>

                        <label class="ll-site-tools-field">
                            <span><?php echo esc_html__('Detailed activity retention (days)', 'll-tools-text-domain'); ?></span>
                            <input type="number" min="30" max="1095" step="1" name="<?php echo esc_attr($retention_option_name); ?>" value="<?php echo esc_attr((string) $retention_days); ?>" />
                        </label>

                        <p class="ll-site-tools-note"><?php echo esc_html__('Detailed learner activity rows older than this are deleted automatically. Summary progress remains attached to the learner account until that account is removed or erased.', 'll-tools-text-domain'); ?></p>

                        <button type="submit" class="ll-site-tools-button"><?php echo esc_html__('Save Retention Settings', 'll-tools-text-domain'); ?></button>
                    </form>
                <?php else : ?>
                    <p class="ll-site-tools-card__empty"><?php echo esc_html__('Administrator access is required to change privacy retention settings.', 'll-tools-text-domain'); ?></p>
                <?php endif; ?>
            </section>

            <section class="ll-site-tools-card">
                <div class="ll-site-tools-card__head">
                    <h2 class="ll-site-tools-card__title"><?php echo esc_html__('Plugin Updates', 'll-tools-text-domain'); ?></h2>
                    <p class="ll-site-tools-card__description"><?php echo esc_html__('Bring update-channel selection and manual update checks into the custom UI while still handing installation off to core WordPress update flow.', 'll-tools-text-domain'); ?></p>
                </div>
                <div class="ll-site-tools-card__meta">
                    <span class="ll-site-tools-pill"><?php echo $update_branch === 'dev' ? esc_html__('Dev channel', 'll-tools-text-domain') : esc_html__('Stable channel', 'll-tools-text-domain'); ?></span>
                    <span class="ll-site-tools-pill"><?php echo esc_html($plugin_update_status_label); ?></span>
                </div>

                <?php if ($can_manage_plugin_updates) : ?>
                    <form class="ll-site-tools-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="ll_tools_save_site_tools" />
                        <input type="hidden" name="ll_site_tools_section" value="plugin-updates" />
                        <input type="hidden" name="redirect_to" value="<?php echo esc_attr($current_url); ?>" />
                        <?php wp_nonce_field('ll_tools_site_tools_plugin-updates', 'll_site_tools_nonce'); ?>

                        <label class="ll-site-tools-field">
                            <span><?php echo esc_html__('Update channel', 'll-tools-text-domain'); ?></span>
                            <select name="ll_update_branch">
                                <option value="main" <?php selected($update_branch, 'main'); ?>><?php echo esc_html__('Stable release packages', 'll-tools-text-domain'); ?></option>
                                <option value="dev" <?php selected($update_branch, 'dev'); ?>><?php echo esc_html__('Dev branch builds', 'll-tools-text-domain'); ?></option>
                            </select>
                        </label>

                        <div class="ll-site-tools-inline-actions">
                            <button type="submit" class="ll-site-tools-button"><?php echo esc_html__('Save Update Channel', 'll-tools-text-domain'); ?></button>
                            <?php if ($plugin_update_check_url !== '') : ?>
                                <a class="ll-site-tools-button ll-site-tools-button--secondary" href="<?php echo esc_url($plugin_update_check_url); ?>"><?php echo esc_html__('Check for Updates', 'll-tools-text-domain'); ?></a>
                            <?php endif; ?>
                            <?php if ($plugin_update_action_url !== '' && $plugin_update_status_name === 'available' && $plugin_update_version !== '') : ?>
                                <a class="ll-site-tools-button ll-site-tools-button--ghost" href="<?php echo esc_url($plugin_update_action_url); ?>">
                                    <?php
                                    echo esc_html(
                                        sprintf(
                                            /* translators: %s: plugin version */
                                            __('Install %s', 'll-tools-text-domain'),
                                            $plugin_update_version
                                        )
                                    );
                                    ?>
                                </a>
                            <?php endif; ?>
                        </div>

                        <p class="ll-site-tools-note"><?php echo esc_html__('Stable uses packaged GitHub releases only. Dev follows the configured branch for testing changes before release.', 'll-tools-text-domain'); ?></p>
                    </form>
                <?php else : ?>
                    <p class="ll-site-tools-card__empty"><?php echo esc_html__('The current account cannot manage LL Tools plugin updates.', 'll-tools-text-domain'); ?></p>
                <?php endif; ?>
            </section>

            <section class="ll-site-tools-card">
                <div class="ll-site-tools-card__head">
                    <h2 class="ll-site-tools-card__title"><?php echo esc_html__('API Providers', 'll-tools-text-domain'); ?></h2>
                    <p class="ll-site-tools-card__description"><?php echo esc_html__('Move the dashboard-only DeepL and AssemblyAI key screens into the custom admin workspace.', 'll-tools-text-domain'); ?></p>
                </div>
                <div class="ll-site-tools-card__meta">
                    <span class="ll-site-tools-pill"><?php echo $deepl_configured ? esc_html__('DeepL configured', 'll-tools-text-domain') : esc_html__('DeepL missing', 'll-tools-text-domain'); ?></span>
                    <span class="ll-site-tools-pill"><?php echo $assemblyai_configured ? esc_html__('AssemblyAI configured', 'll-tools-text-domain') : esc_html__('AssemblyAI missing', 'll-tools-text-domain'); ?></span>
                </div>

                <?php if ($can_manage_api_settings) : ?>
                    <form class="ll-site-tools-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="ll_tools_save_site_tools" />
                        <input type="hidden" name="ll_site_tools_section" value="api-providers" />
                        <input type="hidden" name="redirect_to" value="<?php echo esc_attr($current_url); ?>" />
                        <?php wp_nonce_field('ll_tools_site_tools_api-providers', 'll_site_tools_nonce'); ?>

                        <label class="ll-site-tools-field">
                            <span><?php echo esc_html__('New DeepL API key', 'll-tools-text-domain'); ?></span>
                            <input type="password" name="ll_deepl_api_key" autocomplete="off" placeholder="<?php echo esc_attr($deepl_configured ? __('Leave blank to keep current key', 'll-tools-text-domain') : __('Paste API key', 'll-tools-text-domain')); ?>" />
                        </label>
                        <?php if ($deepl_configured) : ?>
                            <label class="ll-site-tools-checkbox">
                                <input type="checkbox" name="ll_deepl_api_key_clear" value="1" />
                                <span><?php echo esc_html__('Clear saved DeepL key', 'll-tools-text-domain'); ?></span>
                            </label>
                        <?php endif; ?>

                        <label class="ll-site-tools-field">
                            <span><?php echo esc_html__('New AssemblyAI API key', 'll-tools-text-domain'); ?></span>
                            <input type="password" name="ll_assemblyai_api_key" autocomplete="off" placeholder="<?php echo esc_attr($assemblyai_configured ? __('Leave blank to keep current key', 'll-tools-text-domain') : __('Paste API key', 'll-tools-text-domain')); ?>" />
                        </label>
                        <?php if ($assemblyai_configured) : ?>
                            <label class="ll-site-tools-checkbox">
                                <input type="checkbox" name="ll_assemblyai_api_key_clear" value="1" />
                                <span><?php echo esc_html__('Clear saved AssemblyAI key', 'll-tools-text-domain'); ?></span>
                            </label>
                        <?php endif; ?>

                        <button type="submit" class="ll-site-tools-button"><?php echo esc_html__('Save API Keys', 'll-tools-text-domain'); ?></button>
                    </form>
                <?php else : ?>
                    <p class="ll-site-tools-card__empty"><?php echo esc_html__('The current account cannot manage API provider keys.', 'll-tools-text-domain'); ?></p>
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

                        <form class="ll-site-tools-maintenance-item" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="ll_tools_run_site_tools_maintenance" />
                            <input type="hidden" name="ll_site_tools_maintenance_action" value="refresh-languages" />
                            <input type="hidden" name="redirect_to" value="<?php echo esc_attr($current_url); ?>" />
                            <?php wp_nonce_field('ll_tools_site_tools_maintenance_refresh-languages', 'll_site_tools_maintenance_nonce'); ?>
                            <div class="ll-site-tools-maintenance-item__copy">
                                <h3><?php echo esc_html__('Refresh language list', 'll-tools-text-domain'); ?></h3>
                                <p><?php echo esc_html__('Rebuild the bundled language taxonomy list used by wordset and language settings.', 'll-tools-text-domain'); ?></p>
                            </div>
                            <button type="submit" class="ll-site-tools-button ll-site-tools-button--secondary"><?php echo esc_html__('Run', 'll-tools-text-domain'); ?></button>
                        </form>
                    </div>
                <?php else : ?>
                    <p class="ll-site-tools-card__empty"><?php echo esc_html__('Administrator access is required to run maintenance actions.', 'll-tools-text-domain'); ?></p>
                <?php endif; ?>
            </section>

            <section class="ll-site-tools-card ll-site-tools-card--managed-pages">
                <div class="ll-site-tools-card__head">
                    <h2 class="ll-site-tools-card__title"><?php echo esc_html__('Managed Pages', 'll-tools-text-domain'); ?></h2>
                    <p class="ll-site-tools-card__description"><?php echo esc_html__('Create or recreate the plugin-owned front-end pages that still relied on dashboard settings-row actions.', 'll-tools-text-domain'); ?></p>
                </div>

                <?php if (empty($page_manager_rows)) : ?>
                    <p class="ll-site-tools-card__empty"><?php echo esc_html__('No managed LL Tools pages are configured yet.', 'll-tools-text-domain'); ?></p>
                <?php else : ?>
                    <div class="ll-site-tools-page-list">
                        <?php foreach ($page_manager_rows as $page_row) : ?>
                            <div class="ll-site-tools-page-item">
                                <div class="ll-site-tools-page-item__copy">
                                    <div class="ll-site-tools-page-item__header">
                                        <h3 class="ll-site-tools-page-item__title"><?php echo esc_html((string) ($page_row['label'] ?? '')); ?></h3>
                                        <span class="ll-site-tools-pill">
                                            <?php echo !empty($page_row['exists']) ? esc_html__('Published', 'll-tools-text-domain') : esc_html__('Missing', 'll-tools-text-domain'); ?>
                                        </span>
                                    </div>
                                    <p class="ll-site-tools-page-item__description">
                                        <?php
                                        if (!empty($page_row['exists'])) {
                                            echo esc_html(
                                                sprintf(
                                                    /* translators: %s: page title */
                                                    __('Using "%s".', 'll-tools-text-domain'),
                                                    (string) ($page_row['title'] ?? '')
                                                )
                                            );
                                        } else {
                                            echo esc_html((string) ($page_row['empty_text'] ?? ''));
                                        }
                                        ?>
                                    </p>

                                    <?php if (!empty($page_row['exists'])) : ?>
                                        <div class="ll-site-tools-page-links">
                                            <a class="ll-site-tools-text-link" href="<?php echo esc_url((string) ($page_row['url'] ?? '')); ?>"><?php echo esc_html__('View', 'll-tools-text-domain'); ?></a>
                                            <?php if (!empty($page_row['edit_url'])) : ?>
                                                <a class="ll-site-tools-text-link" href="<?php echo esc_url((string) $page_row['edit_url']); ?>"><?php echo esc_html__('Edit in WordPress', 'll-tools-text-domain'); ?></a>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <?php if ($can_manage_settings) : ?>
                                    <form class="ll-site-tools-page-item__form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                        <input type="hidden" name="action" value="ll_tools_manage_site_tools_page" />
                                        <input type="hidden" name="ll_site_tools_page_key" value="<?php echo esc_attr((string) ($page_row['key'] ?? '')); ?>" />
                                        <input type="hidden" name="ll_site_tools_page_mode" value="<?php echo !empty($page_row['exists']) ? 'recreate' : 'create'; ?>" />
                                        <input type="hidden" name="redirect_to" value="<?php echo esc_attr($current_url); ?>" />
                                        <?php wp_nonce_field('ll_tools_site_tools_page_' . (string) ($page_row['key'] ?? ''), 'll_site_tools_page_nonce'); ?>
                                        <button type="submit" class="ll-site-tools-button ll-site-tools-button--secondary">
                                            <?php echo esc_html(!empty($page_row['exists']) ? (string) ($page_row['recreate_label'] ?? '') : (string) ($page_row['create_label'] ?? '')); ?>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </section>
    <?php

    return (string) ob_get_clean();
}
add_shortcode('ll_site_tools', 'll_site_tools_shortcode');
