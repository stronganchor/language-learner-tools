<?php
if (!defined('WPINC')) {
    die;
}

/**
 * Normalize the LL Tools learner-registration setting.
 *
 * @param mixed $value Raw option value.
 */
function ll_tools_normalize_learner_registration_setting_value($value): int {
    return absint($value) === 1 ? 1 : 0;
}

/**
 * Keep WordPress registration aligned with the LL Tools learner setting.
 *
 * On multisite, preserve whether site registration is enabled while toggling
 * only the user-registration part of the network registration setting.
 *
 * @param mixed $value Raw learner-registration setting.
 */
function ll_tools_sync_wordpress_registration_setting($value): void {
    $enabled = ll_tools_normalize_learner_registration_setting_value($value);

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

/**
 * Normalize and synchronize every update of the LL Tools registration option.
 *
 * @param mixed  $value     New option value.
 * @param mixed  $old_value Previous option value.
 * @param string $option    Option name.
 * @return int Normalized option value.
 */
function ll_tools_sync_wordpress_registration_from_learner_setting($value, $old_value, $option): int {
    $normalized = ll_tools_normalize_learner_registration_setting_value($value);
    ll_tools_sync_wordpress_registration_setting($normalized);
    return $normalized;
}
add_filter(
    'pre_update_option_ll_allow_learner_self_registration',
    'll_tools_sync_wordpress_registration_from_learner_setting',
    10,
    3
);
