<?php
if (!defined('WPINC')) { die; }

if (!function_exists('ll_tools_get_teacher_classes_page_slug')) {
    function ll_tools_get_teacher_classes_page_slug(): string {
        return 'll-tools-teacher-classes';
    }
}

if (!function_exists('ll_tools_get_teacher_classes_page_url')) {
    function ll_tools_get_teacher_classes_page_url(array $args = []): string {
        $query_args = array_merge([
            'page' => ll_tools_get_teacher_classes_page_slug(),
        ], $args);

        return (string) add_query_arg($query_args, admin_url('admin.php'));
    }
}

if (!function_exists('ll_tools_teacher_classes_page_capability')) {
    function ll_tools_teacher_classes_page_capability(): string {
        return function_exists('ll_tools_get_teacher_manage_classes_capability')
            ? ll_tools_get_teacher_manage_classes_capability()
            : 'manage_options';
    }
}

if (!function_exists('ll_tools_register_teacher_classes_page')) {
    function ll_tools_register_teacher_classes_page(): void {
        $parent_slug = function_exists('ll_tools_get_admin_menu_slug')
            ? ll_tools_get_admin_menu_slug()
            : 'll-tools-dashboard-home';

        add_submenu_page(
            $parent_slug,
            __('Classes', 'll-tools-text-domain'),
            __('Classes', 'll-tools-text-domain'),
            ll_tools_teacher_classes_page_capability(),
            ll_tools_get_teacher_classes_page_slug(),
            'll_tools_render_teacher_classes_page'
        );
    }
}
add_action('admin_menu', 'll_tools_register_teacher_classes_page', 16);

if (!function_exists('ll_tools_teacher_classes_build_notice_url')) {
    function ll_tools_teacher_classes_build_notice_url(string $url, string $type, string $message): string {
        return (string) add_query_arg([
            'll_tools_teacher_notice_type' => ($type === 'success') ? 'success' : 'error',
            'll_tools_teacher_notice_message' => $message,
        ], $url);
    }
}

if (!function_exists('ll_tools_teacher_classes_render_notice_from_request')) {
    function ll_tools_teacher_classes_render_notice_from_request(): void {
        $raw_type = isset($_GET['ll_tools_teacher_notice_type'])
            ? sanitize_key((string) wp_unslash($_GET['ll_tools_teacher_notice_type']))
            : '';
        $type = ($raw_type === 'success') ? 'success' : (($raw_type === 'error') ? 'error' : '');
        $message = isset($_GET['ll_tools_teacher_notice_message'])
            ? sanitize_text_field((string) wp_unslash($_GET['ll_tools_teacher_notice_message']))
            : '';

        if ($type === '' || $message === '') {
            return;
        }

        printf(
            '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
            esc_attr($type === 'success' ? 'success' : 'error'),
            esc_html($message)
        );
    }
}

if (!function_exists('ll_tools_teacher_classes_requested_redirect_url')) {
    function ll_tools_teacher_classes_requested_redirect_url(string $fallback_url): string {
        $fallback_url = trim((string) $fallback_url);
        if ($fallback_url === '') {
            $fallback_url = ll_tools_get_teacher_classes_page_url();
        }

        $requested = isset($_POST['ll_tools_teacher_redirect_to'])
            ? trim((string) wp_unslash($_POST['ll_tools_teacher_redirect_to']))
            : '';
        if ($requested === '') {
            return $fallback_url;
        }

        $validated = (string) wp_validate_redirect($requested, '');
        if ($validated === '') {
            return $fallback_url;
        }

        $home_host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
        $target_host = (string) wp_parse_url($validated, PHP_URL_HOST);
        if ($home_host !== '' && $target_host !== '' && strtolower($home_host) !== strtolower($target_host)) {
            return $fallback_url;
        }

        return $validated;
    }
}

if (!function_exists('ll_tools_teacher_classes_redirect_with_notice')) {
    function ll_tools_teacher_classes_redirect_with_notice(string $fallback_url, string $type, string $message): void {
        $redirect_url = ll_tools_teacher_classes_requested_redirect_url($fallback_url);

        $fallback_query = (string) wp_parse_url($fallback_url, PHP_URL_QUERY);
        if ($fallback_query !== '') {
            $fallback_query_args = [];
            parse_str($fallback_query, $fallback_query_args);
            $redirect_query_args = [];
            $redirect_query = (string) wp_parse_url($redirect_url, PHP_URL_QUERY);
            if ($redirect_query !== '') {
                parse_str($redirect_query, $redirect_query_args);
            }

            $fallback_class_id = isset($fallback_query_args['class_id'])
                ? max(0, (int) $fallback_query_args['class_id'])
                : 0;
            if (
                $fallback_class_id > 0
                && !isset($redirect_query_args['class_id'])
            ) {
                $redirect_url = add_query_arg(
                    'class_id',
                    $fallback_class_id,
                    $redirect_url
                );
            }
        }

        $redirect_url = remove_query_arg([
            'll_tools_teacher_notice_type',
            'll_tools_teacher_notice_message',
        ], $redirect_url);

        if (function_exists('ll_tools_teacher_class_strip_invite_query_args')) {
            $redirect_url = ll_tools_teacher_class_strip_invite_query_args($redirect_url);
        }

        $admin_base = (string) admin_url();
        $is_admin_redirect = ($admin_base !== '') && strpos($redirect_url, $admin_base) === 0;
        if ($is_admin_redirect || !function_exists('ll_tools_teacher_class_append_notice_to_url')) {
            $redirect_url = ll_tools_teacher_classes_build_notice_url($redirect_url, $type, $message);
        } else {
            $redirect_url = ll_tools_teacher_class_append_notice_to_url($redirect_url, [
                'type' => ($type === 'success') ? 'success' : 'error',
                'message' => $message,
            ]);
        }

        wp_safe_redirect($redirect_url);
        exit;
    }
}

if (!function_exists('ll_tools_teacher_classes_require_manage_access')) {
    function ll_tools_teacher_classes_require_manage_access(): void {
        if (!current_user_can('view_ll_tools') || !function_exists('ll_tools_user_can_manage_classes') || !ll_tools_user_can_manage_classes()) {
            wp_die(__('You do not have permission to access this page.', 'll-tools-text-domain'));
        }
    }
}

if (!function_exists('ll_tools_handle_teacher_class_create_action')) {
    function ll_tools_handle_teacher_class_create_action(): void {
        ll_tools_teacher_classes_require_manage_access();
        check_admin_referer('ll_tools_teacher_create_class');

        $class_name = isset($_POST['ll_tools_teacher_class_name'])
            ? sanitize_text_field((string) wp_unslash($_POST['ll_tools_teacher_class_name']))
            : '';
        $wordset_id = isset($_POST['ll_tools_teacher_class_wordset_id'])
            ? max(0, (int) wp_unslash((string) $_POST['ll_tools_teacher_class_wordset_id']))
            : 0;
        $teacher_user_id = get_current_user_id();

        if (current_user_can('manage_options')) {
            $teacher_user_id = isset($_POST['ll_tools_teacher_class_teacher_user_id'])
                ? max(0, (int) wp_unslash((string) $_POST['ll_tools_teacher_class_teacher_user_id']))
                : get_current_user_id();
        }

        $result = function_exists('ll_tools_teacher_class_create')
            ? ll_tools_teacher_class_create($teacher_user_id, $class_name, $wordset_id)
            : new WP_Error('missing_helper', __('Class creation is currently unavailable.', 'll-tools-text-domain'));

        $redirect_url = ll_tools_teacher_classes_requested_redirect_url(ll_tools_get_teacher_classes_page_url());
        if (is_wp_error($result)) {
            ll_tools_teacher_classes_redirect_with_notice(
                $redirect_url,
                'error',
                $result->get_error_message()
            );
        }

        $created_class_id = (int) $result;
        ll_tools_teacher_classes_redirect_with_notice(
            add_query_arg('class_id', $created_class_id, $redirect_url),
            'success',
            sprintf(
                /* translators: %s: class name */
                __('Created class: %s', 'll-tools-text-domain'),
                ll_tools_teacher_class_get_name($created_class_id)
            )
        );
    }
}
add_action('admin_post_ll_tools_teacher_create_class', 'll_tools_handle_teacher_class_create_action');

if (!function_exists('ll_tools_handle_teacher_class_assign_teacher_action')) {
    function ll_tools_handle_teacher_class_assign_teacher_action(): void {
        ll_tools_teacher_classes_require_manage_access();

        if (!current_user_can('manage_options')) {
            wp_die(__('Only administrators can assign teachers to classes directly.', 'll-tools-text-domain'));
        }

        $class_id = isset($_POST['class_id']) ? max(0, (int) wp_unslash((string) $_POST['class_id'])) : 0;
        if ($class_id <= 0 || !function_exists('ll_tools_teacher_class_user_can_access') || !ll_tools_teacher_class_user_can_access($class_id)) {
            wp_die(__('You do not have permission to manage that class.', 'll-tools-text-domain'));
        }

        check_admin_referer('ll_tools_teacher_assign_class_teacher_' . $class_id);

        $user_id = isset($_POST['ll_tools_teacher_class_teacher_user_id'])
            ? max(0, (int) wp_unslash((string) $_POST['ll_tools_teacher_class_teacher_user_id']))
            : 0;

        $result = function_exists('ll_tools_teacher_class_assign_teacher')
            ? ll_tools_teacher_class_assign_teacher($class_id, $user_id)
            : new WP_Error('missing_helper', __('Teacher assignment is currently unavailable.', 'll-tools-text-domain'));

        $redirect_url = ll_tools_teacher_classes_requested_redirect_url(
            ll_tools_get_teacher_classes_page_url(['class_id' => $class_id])
        );
        if (is_wp_error($result)) {
            ll_tools_teacher_classes_redirect_with_notice(
                $redirect_url,
                'error',
                $result->get_error_message()
            );
        }

        $teacher_user = isset($result['user']) && ($result['user'] instanceof WP_User)
            ? $result['user']
            : get_userdata($user_id);
        $teacher_label = $teacher_user instanceof WP_User
            ? ll_tools_teacher_class_user_label($teacher_user)
            : __('The selected teacher', 'll-tools-text-domain');

        if (!empty($result['ownership_changed']) && !empty($result['teacher_role_added'])) {
            $message = sprintf(
                /* translators: %s: teacher display name */
                __('Assigned %s as the class teacher and granted the Teacher role.', 'll-tools-text-domain'),
                $teacher_label
            );
        } elseif (!empty($result['ownership_changed'])) {
            $message = sprintf(
                /* translators: %s: teacher display name */
                __('Assigned %s as the class teacher.', 'll-tools-text-domain'),
                $teacher_label
            );
        } elseif (!empty($result['teacher_role_added'])) {
            $message = sprintf(
                /* translators: %s: teacher display name */
                __('%s is already the class teacher. The Teacher role was granted.', 'll-tools-text-domain'),
                $teacher_label
            );
        } else {
            $message = sprintf(
                /* translators: %s: teacher display name */
                __('%s is already the class teacher.', 'll-tools-text-domain'),
                $teacher_label
            );
        }

        ll_tools_teacher_classes_redirect_with_notice(
            $redirect_url,
            'success',
            $message
        );
    }
}
add_action('admin_post_ll_tools_teacher_assign_class_teacher', 'll_tools_handle_teacher_class_assign_teacher_action');

if (!function_exists('ll_tools_handle_teacher_class_invite_action')) {
    function ll_tools_handle_teacher_class_invite_action(): void {
        ll_tools_teacher_classes_require_manage_access();

        $class_id = isset($_POST['class_id']) ? max(0, (int) wp_unslash((string) $_POST['class_id'])) : 0;
        if ($class_id <= 0 || !function_exists('ll_tools_teacher_class_user_can_access') || !ll_tools_teacher_class_user_can_access($class_id)) {
            wp_die(__('You do not have permission to manage that class.', 'll-tools-text-domain'));
        }

        check_admin_referer('ll_tools_teacher_send_class_invite_' . $class_id);

        $email = isset($_POST['ll_tools_teacher_invite_email'])
            ? sanitize_email((string) wp_unslash($_POST['ll_tools_teacher_invite_email']))
            : '';

        $result = function_exists('ll_tools_teacher_class_send_existing_learner_invitation')
            ? ll_tools_teacher_class_send_existing_learner_invitation($class_id, $email, get_current_user_id())
            : new WP_Error('missing_helper', __('Class invitations are currently unavailable.', 'll-tools-text-domain'));

        $redirect_url = ll_tools_teacher_classes_requested_redirect_url(
            ll_tools_get_teacher_classes_page_url(['class_id' => $class_id])
        );
        if (is_wp_error($result)) {
            ll_tools_teacher_classes_redirect_with_notice(
                $redirect_url,
                'error',
                $result->get_error_message()
            );
        }

        ll_tools_teacher_classes_redirect_with_notice(
            $redirect_url,
            'success',
            sprintf(
                /* translators: %s: learner email */
                __('Sent a class invitation to %s.', 'll-tools-text-domain'),
                $email
            )
        );
    }
}
add_action('admin_post_ll_tools_teacher_send_class_invite', 'll_tools_handle_teacher_class_invite_action');

if (!function_exists('ll_tools_handle_teacher_class_manual_assign_action')) {
    function ll_tools_handle_teacher_class_manual_assign_action(): void {
        ll_tools_teacher_classes_require_manage_access();

        if (!current_user_can('manage_options')) {
            wp_die(__('Only administrators can assign learners to classes directly.', 'll-tools-text-domain'));
        }

        $class_id = isset($_POST['class_id']) ? max(0, (int) wp_unslash((string) $_POST['class_id'])) : 0;
        if ($class_id <= 0 || !function_exists('ll_tools_teacher_class_user_can_access') || !ll_tools_teacher_class_user_can_access($class_id)) {
            wp_die(__('You do not have permission to manage that class.', 'll-tools-text-domain'));
        }

        check_admin_referer('ll_tools_teacher_assign_class_student_' . $class_id);

        $user_id = isset($_POST['ll_tools_teacher_assign_user_id'])
            ? max(0, (int) wp_unslash((string) $_POST['ll_tools_teacher_assign_user_id']))
            : 0;

        $result = function_exists('ll_tools_teacher_class_assign_student')
            ? ll_tools_teacher_class_assign_student($class_id, $user_id)
            : new WP_Error('missing_helper', __('Manual class assignment is currently unavailable.', 'll-tools-text-domain'));

        $redirect_url = ll_tools_teacher_classes_requested_redirect_url(
            ll_tools_get_teacher_classes_page_url(['class_id' => $class_id])
        );
        if (is_wp_error($result)) {
            ll_tools_teacher_classes_redirect_with_notice(
                $redirect_url,
                'error',
                $result->get_error_message()
            );
        }

        $assigned_user = isset($result['user']) && ($result['user'] instanceof WP_User)
            ? $result['user']
            : get_userdata($user_id);
        $assigned_label = $assigned_user instanceof WP_User
            ? ll_tools_teacher_class_user_label($assigned_user)
            : __('The selected learner', 'll-tools-text-domain');

        $message = !empty($result['already_member'])
            ? sprintf(
                /* translators: %s: learner display name */
                __('%s is already a member of this class.', 'll-tools-text-domain'),
                $assigned_label
            )
            : sprintf(
                /* translators: %s: learner display name */
                __('Added %s to this class.', 'll-tools-text-domain'),
                $assigned_label
            );

        ll_tools_teacher_classes_redirect_with_notice(
            $redirect_url,
            'success',
            $message
        );
    }
}
add_action('admin_post_ll_tools_teacher_assign_class_student', 'll_tools_handle_teacher_class_manual_assign_action');

if (!function_exists('ll_tools_handle_teacher_class_remove_student_action')) {
    function ll_tools_handle_teacher_class_remove_student_action(): void {
        ll_tools_teacher_classes_require_manage_access();

        $class_id = isset($_POST['class_id']) ? max(0, (int) wp_unslash((string) $_POST['class_id'])) : 0;
        if ($class_id <= 0 || !function_exists('ll_tools_teacher_class_user_can_access') || !ll_tools_teacher_class_user_can_access($class_id)) {
            wp_die(__('You do not have permission to manage that class.', 'll-tools-text-domain'));
        }

        check_admin_referer('ll_tools_teacher_remove_class_student_' . $class_id);

        $user_id = isset($_POST['ll_tools_teacher_remove_user_id'])
            ? max(0, (int) wp_unslash((string) $_POST['ll_tools_teacher_remove_user_id']))
            : 0;

        $result = function_exists('ll_tools_teacher_class_remove_student')
            ? ll_tools_teacher_class_remove_student($class_id, $user_id)
            : new WP_Error('missing_helper', __('Removing a learner is currently unavailable.', 'll-tools-text-domain'));

        $redirect_url = ll_tools_teacher_classes_requested_redirect_url(
            ll_tools_get_teacher_classes_page_url(['class_id' => $class_id])
        );
        if (is_wp_error($result)) {
            ll_tools_teacher_classes_redirect_with_notice(
                $redirect_url,
                'error',
                $result->get_error_message()
            );
        }

        $removed_user = isset($result['user']) && ($result['user'] instanceof WP_User)
            ? $result['user']
            : get_userdata($user_id);
        $removed_label = $removed_user instanceof WP_User
            ? ll_tools_teacher_class_user_label($removed_user)
            : __('The selected learner', 'll-tools-text-domain');

        $message = !empty($result['already_removed'])
            ? sprintf(
                /* translators: %s: learner display name */
                __('%s is no longer in this class.', 'll-tools-text-domain'),
                $removed_label
            )
            : sprintf(
                /* translators: %s: learner display name */
                __('Removed %s from this class.', 'll-tools-text-domain'),
                $removed_label
            );

        ll_tools_teacher_classes_redirect_with_notice(
            $redirect_url,
            'success',
            $message
        );
    }
}
add_action('admin_post_ll_tools_teacher_remove_class_student', 'll_tools_handle_teacher_class_remove_student_action');

if (!function_exists('ll_tools_handle_teacher_class_delete_action')) {
    function ll_tools_handle_teacher_class_delete_action(): void {
        ll_tools_teacher_classes_require_manage_access();

        $class_id = isset($_POST['class_id']) ? max(0, (int) wp_unslash((string) $_POST['class_id'])) : 0;
        if ($class_id <= 0 || !function_exists('ll_tools_teacher_class_user_can_access') || !ll_tools_teacher_class_user_can_access($class_id)) {
            wp_die(__('You do not have permission to manage that class.', 'll-tools-text-domain'));
        }

        check_admin_referer('ll_tools_teacher_delete_class_' . $class_id);

        $result = function_exists('ll_tools_teacher_class_delete')
            ? ll_tools_teacher_class_delete($class_id)
            : new WP_Error('missing_helper', __('Deleting a class is currently unavailable.', 'll-tools-text-domain'));

        $redirect_url = ll_tools_teacher_classes_requested_redirect_url(ll_tools_get_teacher_classes_page_url());
        $redirect_query = [];
        wp_parse_str((string) wp_parse_url($redirect_url, PHP_URL_QUERY), $redirect_query);
        if ((int) ($redirect_query['class_id'] ?? 0) === $class_id) {
            $redirect_url = remove_query_arg('class_id', $redirect_url);
        }
        if (is_wp_error($result)) {
            ll_tools_teacher_classes_redirect_with_notice(
                $redirect_url,
                'error',
                $result->get_error_message()
            );
        }

        ll_tools_teacher_classes_redirect_with_notice(
            $redirect_url,
            'success',
            sprintf(
                /* translators: %s: class name */
                __('Deleted class: %s', 'll-tools-text-domain'),
                (string) ($result['class_name'] ?? __('Untitled class', 'll-tools-text-domain'))
            )
        );
    }
}
add_action('admin_post_ll_tools_teacher_delete_class', 'll_tools_handle_teacher_class_delete_action');

if (!function_exists('ll_tools_render_teacher_classes_page')) {
    function ll_tools_render_teacher_classes_page(): void {
        ll_tools_teacher_classes_require_manage_access();

        $classes = function_exists('ll_tools_teacher_classes_for_user')
            ? ll_tools_teacher_classes_for_user(get_current_user_id())
            : [];
        $available_wordsets = function_exists('ll_tools_teacher_class_get_available_wordsets')
            ? ll_tools_teacher_class_get_available_wordsets()
            : [];
        $single_wordset_id = function_exists('ll_tools_teacher_class_get_single_wordset_id')
            ? ll_tools_teacher_class_get_single_wordset_id()
            : 0;
        $selected_class_id = isset($_GET['class_id'])
            ? max(0, (int) wp_unslash((string) $_GET['class_id']))
            : 0;

        if ($selected_class_id <= 0 && !empty($classes) && ($classes[0] instanceof WP_Post)) {
            $selected_class_id = (int) $classes[0]->ID;
        }

        if ($selected_class_id > 0 && (!function_exists('ll_tools_teacher_class_user_can_access') || !ll_tools_teacher_class_user_can_access($selected_class_id))) {
            $selected_class_id = 0;
        }

        $selected_class = ($selected_class_id > 0 && function_exists('ll_tools_get_teacher_class'))
            ? ll_tools_get_teacher_class($selected_class_id)
            : null;
        $selected_class_wordset_term = ($selected_class instanceof WP_Post && function_exists('ll_tools_teacher_class_get_wordset_term'))
            ? ll_tools_teacher_class_get_wordset_term((int) $selected_class->ID)
            : null;
        $selected_class_wordset_id = ($selected_class_wordset_term instanceof WP_Term)
            ? max(0, (int) $selected_class_wordset_term->term_id)
            : 0;
        $student_ids = ($selected_class instanceof WP_Post && function_exists('ll_tools_teacher_class_get_student_ids'))
            ? ll_tools_teacher_class_get_student_ids((int) $selected_class->ID)
            : [];
        $student_rows = function_exists('ll_tools_teacher_class_student_progress_rows')
            ? ll_tools_teacher_class_student_progress_rows($student_ids, $selected_class_wordset_id)
            : [];
        $selected_teacher_user = ($selected_class instanceof WP_Post)
            ? get_userdata((int) $selected_class->post_author)
            : null;
        $can_directly_assign_teachers = current_user_can('manage_options');
        $assignable_teachers = $can_directly_assign_teachers && function_exists('ll_tools_teacher_class_get_assignable_teachers')
            ? ll_tools_teacher_class_get_assignable_teachers()
            : [];
        $can_directly_assign_students = current_user_can('manage_options');
        $assignable_students = ($selected_class instanceof WP_Post && $can_directly_assign_students && function_exists('ll_tools_teacher_class_get_assignable_students'))
            ? ll_tools_teacher_class_get_assignable_students((int) $selected_class->ID)
            : [];

        $summary = function_exists('ll_tools_teacher_class_progress_summary')
            ? ll_tools_teacher_class_progress_summary($student_rows)
            : [
                'students' => count($student_rows),
                'rounds_30d' => 0,
                'studied_words' => 0,
                'mastered_words' => 0,
                'hard_words' => 0,
            ];

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Classes', 'll-tools-text-domain'); ?></h1>
            <p><?php esc_html_e('Create classes, invite learners, and review progress for students you teach.', 'll-tools-text-domain'); ?></p>

            <?php ll_tools_teacher_classes_render_notice_from_request(); ?>

            <div class="card" style="max-width: 720px;">
                <h2><?php esc_html_e('Create a class', 'll-tools-text-domain'); ?></h2>
                <?php if (empty($available_wordsets)) : ?>
                    <p><?php esc_html_e('Create a word set before creating classes.', 'll-tools-text-domain'); ?></p>
                <?php else : ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="ll_tools_teacher_create_class" />
                        <?php wp_nonce_field('ll_tools_teacher_create_class'); ?>
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row">
                                    <label for="ll-tools-teacher-class-name"><?php esc_html_e('Class name', 'll-tools-text-domain'); ?></label>
                                </th>
                                <td>
                                    <input
                                        type="text"
                                        id="ll-tools-teacher-class-name"
                                        name="ll_tools_teacher_class_name"
                                        class="regular-text"
                                        required />
                                    <p class="description"><?php esc_html_e('Use a short label that learners will recognize in invitation emails.', 'll-tools-text-domain'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="ll-tools-teacher-class-wordset-id"><?php esc_html_e('Word set', 'll-tools-text-domain'); ?></label>
                                </th>
                                <td>
                                    <?php if (count($available_wordsets) === 1 && ($single_wordset_id > 0) && ($available_wordsets[0] instanceof WP_Term)) : ?>
                                        <input type="hidden" name="ll_tools_teacher_class_wordset_id" value="<?php echo esc_attr((string) $single_wordset_id); ?>" />
                                        <input
                                            type="text"
                                            id="ll-tools-teacher-class-wordset-id"
                                            class="regular-text"
                                            value="<?php echo esc_attr((string) $available_wordsets[0]->name); ?>"
                                            readonly />
                                    <?php else : ?>
                                        <select
                                            id="ll-tools-teacher-class-wordset-id"
                                            name="ll_tools_teacher_class_wordset_id"
                                            class="regular-text"
                                            required>
                                            <option value=""><?php esc_html_e('Select a word set', 'll-tools-text-domain'); ?></option>
                                            <?php foreach ($available_wordsets as $wordset_term) : ?>
                                                <?php if (!($wordset_term instanceof WP_Term)) { continue; } ?>
                                                <option value="<?php echo esc_attr((string) $wordset_term->term_id); ?>">
                                                    <?php echo esc_html((string) $wordset_term->name); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php endif; ?>
                                    <p class="description"><?php esc_html_e('Each class tracks progress for one word set only.', 'll-tools-text-domain'); ?></p>
                                </td>
                            </tr>
                            <?php if ($can_directly_assign_teachers && !empty($assignable_teachers)) : ?>
                                <tr>
                                    <th scope="row">
                                        <label for="ll-tools-teacher-class-teacher-user-id"><?php esc_html_e('Teacher', 'll-tools-text-domain'); ?></label>
                                    </th>
                                    <td>
                                        <select
                                            id="ll-tools-teacher-class-teacher-user-id"
                                            name="ll_tools_teacher_class_teacher_user_id"
                                            class="regular-text"
                                            required>
                                            <?php foreach ($assignable_teachers as $assignable_teacher) : ?>
                                                <?php if (!($assignable_teacher instanceof WP_User)) { continue; } ?>
                                                <option value="<?php echo esc_attr((string) $assignable_teacher->ID); ?>" <?php selected((int) $assignable_teacher->ID, get_current_user_id()); ?>>
                                                    <?php echo esc_html(ll_tools_teacher_class_user_option_label($assignable_teacher)); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <p class="description"><?php esc_html_e('Administrators can create the class for any existing user. The selected user will receive the Teacher role automatically if needed.', 'll-tools-text-domain'); ?></p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </table>
                        <?php submit_button(__('Create class', 'll-tools-text-domain')); ?>
                    </form>
                <?php endif; ?>
            </div>

            <?php if (empty($classes)) : ?>
                <div class="card" style="max-width: 720px;">
                    <p><?php esc_html_e('No classes exist yet. Create one to start inviting learners.', 'll-tools-text-domain'); ?></p>
                </div>
                <?php return; ?>
            <?php endif; ?>

            <h2><?php esc_html_e('Your classes', 'll-tools-text-domain'); ?></h2>
            <table class="widefat striped" style="max-width: 980px; margin-bottom: 24px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Class', 'll-tools-text-domain'); ?></th>
                        <th><?php esc_html_e('Word Set', 'll-tools-text-domain'); ?></th>
                        <?php if (current_user_can('manage_options')) : ?>
                            <th><?php esc_html_e('Teacher', 'll-tools-text-domain'); ?></th>
                        <?php endif; ?>
                        <th><?php esc_html_e('Students', 'll-tools-text-domain'); ?></th>
                        <th><?php esc_html_e('Open', 'll-tools-text-domain'); ?></th>
                        <th><?php esc_html_e('Delete', 'll-tools-text-domain'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($classes as $class_post) : ?>
                        <?php if (!($class_post instanceof WP_Post)) { continue; } ?>
                        <?php
                        $class_id = (int) $class_post->ID;
                        $teacher_user = get_userdata((int) $class_post->post_author);
                        $class_wordset_name = function_exists('ll_tools_teacher_class_get_wordset_name')
                            ? ll_tools_teacher_class_get_wordset_name($class_id)
                            : '';
                        ?>
                        <tr>
                            <td><?php echo esc_html($class_post->post_title); ?></td>
                            <td><?php echo esc_html($class_wordset_name !== '' ? $class_wordset_name : __('Not set', 'll-tools-text-domain')); ?></td>
                            <?php if (current_user_can('manage_options')) : ?>
                                <td><?php echo esc_html($teacher_user instanceof WP_User ? ($teacher_user->display_name ?: $teacher_user->user_login) : ''); ?></td>
                            <?php endif; ?>
                            <td><?php echo esc_html((string) count(ll_tools_teacher_class_get_student_ids($class_id))); ?></td>
                            <td>
                                <a class="button button-small" href="<?php echo esc_url(ll_tools_get_teacher_classes_page_url(['class_id' => $class_id])); ?>">
                                    <?php esc_html_e('Open', 'll-tools-text-domain'); ?>
                                </a>
                            </td>
                            <td>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return window.confirm('<?php echo esc_js(sprintf(__('Delete %s?', 'll-tools-text-domain'), $class_post->post_title)); ?>');">
                                    <input type="hidden" name="action" value="ll_tools_teacher_delete_class" />
                                    <input type="hidden" name="class_id" value="<?php echo esc_attr((string) $class_id); ?>" />
                                    <?php wp_nonce_field('ll_tools_teacher_delete_class_' . $class_id); ?>
                                    <button type="submit" class="button button-small">
                                        <?php esc_html_e('Delete', 'll-tools-text-domain'); ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if (!($selected_class instanceof WP_Post)) : ?>
                <?php return; ?>
            <?php endif; ?>

            <?php $signup_url = function_exists('ll_tools_teacher_class_get_signup_invite_url') ? ll_tools_teacher_class_get_signup_invite_url((int) $selected_class->ID) : ''; ?>

            <h2><?php echo esc_html($selected_class->post_title); ?></h2>
            <table class="widefat striped" style="max-width: 900px; margin-bottom: 24px;">
                <tbody>
                    <tr>
                        <th><?php esc_html_e('Teacher', 'll-tools-text-domain'); ?></th>
                        <td><?php echo esc_html($selected_teacher_user instanceof WP_User ? ll_tools_teacher_class_user_label($selected_teacher_user) : ''); ?></td>
                        <th><?php esc_html_e('Word Set', 'll-tools-text-domain'); ?></th>
                        <td><?php echo esc_html(($selected_class_wordset_term instanceof WP_Term) ? (string) $selected_class_wordset_term->name : __('Not set', 'll-tools-text-domain')); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Students', 'll-tools-text-domain'); ?></th>
                        <td><?php echo esc_html((string) $summary['students']); ?></td>
                        <th><?php esc_html_e('30d rounds', 'll-tools-text-domain'); ?></th>
                        <td><?php echo esc_html((string) $summary['rounds_30d']); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Studied words', 'll-tools-text-domain'); ?></th>
                        <td><?php echo esc_html((string) $summary['studied_words']); ?></td>
                        <th><?php esc_html_e('Mastered words', 'll-tools-text-domain'); ?></th>
                        <td><?php echo esc_html((string) $summary['mastered_words']); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Hard words', 'll-tools-text-domain'); ?></th>
                        <td><?php echo esc_html((string) $summary['hard_words']); ?></td>
                        <th><?php esc_html_e('Signup link', 'll-tools-text-domain'); ?></th>
                        <td><?php echo $signup_url !== '' ? esc_html__('Ready', 'll-tools-text-domain') : esc_html__('Unavailable', 'll-tools-text-domain'); ?></td>
                    </tr>
                </tbody>
            </table>

            <div class="card" style="max-width: 900px;">
                <?php if ($can_directly_assign_teachers) : ?>
                    <h3><?php esc_html_e('Assign a teacher', 'll-tools-text-domain'); ?></h3>
                    <p><?php esc_html_e('Choose which user should manage this class. The selected user will receive the Teacher role automatically if needed.', 'll-tools-text-domain'); ?></p>
                    <?php if (empty($assignable_teachers)) : ?>
                        <p class="description"><?php esc_html_e('No eligible user accounts are currently available to assign as teachers.', 'll-tools-text-domain'); ?></p>
                    <?php else : ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom: 18px;">
                            <input type="hidden" name="action" value="ll_tools_teacher_assign_class_teacher" />
                            <input type="hidden" name="class_id" value="<?php echo esc_attr((string) $selected_class->ID); ?>" />
                            <?php wp_nonce_field('ll_tools_teacher_assign_class_teacher_' . (int) $selected_class->ID); ?>
                            <p>
                                <label for="ll-tools-teacher-class-teacher-select" class="screen-reader-text"><?php esc_html_e('Teacher account', 'll-tools-text-domain'); ?></label>
                                <select id="ll-tools-teacher-class-teacher-select" name="ll_tools_teacher_class_teacher_user_id" class="regular-text" required>
                                    <?php foreach ($assignable_teachers as $assignable_teacher) : ?>
                                        <?php if (!($assignable_teacher instanceof WP_User)) { continue; } ?>
                                        <option value="<?php echo esc_attr((string) $assignable_teacher->ID); ?>" <?php selected((int) $assignable_teacher->ID, (int) $selected_class->post_author); ?>>
                                                <?php echo esc_html(ll_tools_teacher_class_user_option_label($assignable_teacher)); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </p>
                            <?php submit_button(__('Assign teacher to class', 'll-tools-text-domain'), 'secondary', '', false); ?>
                        </form>
                    <?php endif; ?>
                    <hr />
                <?php endif; ?>

                <?php if ($can_directly_assign_students) : ?>
                    <h3><?php esc_html_e('Assign an existing learner now', 'll-tools-text-domain'); ?></h3>
                    <p><?php esc_html_e('Administrators can add an existing learner account to this class immediately without waiting for the learner to open an invitation link.', 'll-tools-text-domain'); ?></p>
                    <?php if (empty($assignable_students)) : ?>
                        <p class="description"><?php esc_html_e('No eligible learner accounts are currently available to assign.', 'll-tools-text-domain'); ?></p>
                    <?php else : ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom: 18px;">
                            <input type="hidden" name="action" value="ll_tools_teacher_assign_class_student" />
                            <input type="hidden" name="class_id" value="<?php echo esc_attr((string) $selected_class->ID); ?>" />
                            <?php wp_nonce_field('ll_tools_teacher_assign_class_student_' . (int) $selected_class->ID); ?>
                            <p>
                                <label for="ll-tools-teacher-assign-user" class="screen-reader-text"><?php esc_html_e('Learner account', 'll-tools-text-domain'); ?></label>
                                <select id="ll-tools-teacher-assign-user" name="ll_tools_teacher_assign_user_id" class="regular-text" required>
                                    <option value=""><?php esc_html_e('Select a learner account', 'll-tools-text-domain'); ?></option>
                                    <?php foreach ($assignable_students as $assignable_student) : ?>
                                        <?php if (!($assignable_student instanceof WP_User)) { continue; } ?>
                                        <?php $assignable_label = ll_tools_teacher_class_user_option_label($assignable_student); ?>
                                        <option value="<?php echo esc_attr((string) $assignable_student->ID); ?>"><?php echo esc_html($assignable_label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </p>
                            <p class="description"><?php esc_html_e('This adds the learner to the selected class immediately and updates the learner account membership list.', 'll-tools-text-domain'); ?></p>
                            <?php submit_button(__('Assign learner to class', 'll-tools-text-domain'), 'secondary', '', false); ?>
                        </form>
                    <?php endif; ?>
                    <hr />
                <?php endif; ?>

                <h3><?php esc_html_e('Invite an existing learner', 'll-tools-text-domain'); ?></h3>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="ll_tools_teacher_send_class_invite" />
                    <input type="hidden" name="class_id" value="<?php echo esc_attr((string) $selected_class->ID); ?>" />
                    <?php wp_nonce_field('ll_tools_teacher_send_class_invite_' . (int) $selected_class->ID); ?>
                    <p>
                        <label for="ll-tools-teacher-invite-email" class="screen-reader-text"><?php esc_html_e('Learner email', 'll-tools-text-domain'); ?></label>
                        <input
                            type="email"
                            id="ll-tools-teacher-invite-email"
                            name="ll_tools_teacher_invite_email"
                            class="regular-text"
                            placeholder="<?php esc_attr_e('learner@example.com', 'll-tools-text-domain'); ?>"
                            required />
                    </p>
                    <p class="description"><?php esc_html_e('This only sends to learner accounts that already exist on the site.', 'll-tools-text-domain'); ?></p>
                    <?php submit_button(__('Send invitation email', 'll-tools-text-domain'), 'secondary', '', false); ?>
                </form>
            </div>

            <div class="card" style="max-width: 900px;">
                <h3><?php esc_html_e('Learner signup link', 'll-tools-text-domain'); ?></h3>
                <p><?php esc_html_e('Share this link with a learner who does not have an account yet. The learner account created from this link will join the class automatically.', 'll-tools-text-domain'); ?></p>
                <input
                    type="url"
                    class="large-text code"
                    readonly
                    value="<?php echo esc_attr($signup_url); ?>"
                    onclick="this.select();" />
            </div>

            <h3><?php esc_html_e('Student progress', 'll-tools-text-domain'); ?></h3>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Learner', 'll-tools-text-domain'); ?></th>
                        <th><?php esc_html_e('Email', 'll-tools-text-domain'); ?></th>
                        <th><?php esc_html_e('30d Rounds', 'll-tools-text-domain'); ?></th>
                        <th><?php esc_html_e('Studied', 'll-tools-text-domain'); ?></th>
                        <th><?php esc_html_e('Mastered', 'll-tools-text-domain'); ?></th>
                        <th><?php esc_html_e('Hard', 'll-tools-text-domain'); ?></th>
                        <th><?php esc_html_e('Last Activity (UTC)', 'll-tools-text-domain'); ?></th>
                        <th><?php esc_html_e('Remove', 'll-tools-text-domain'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($student_rows)) : ?>
                        <tr>
                            <td colspan="8"><?php esc_html_e('No learners have joined this class yet.', 'll-tools-text-domain'); ?></td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($student_rows as $row) : ?>
                            <?php
                            $user = $row['user'];
                            $row_stats = (array) ($row['stats'] ?? []);
                            if (!($user instanceof WP_User)) {
                                continue;
                            }
                            ?>
                            <tr>
                                <td><?php echo esc_html(ll_tools_teacher_class_user_label($user)); ?></td>
                                <td><a href="mailto:<?php echo esc_attr($user->user_email); ?>"><?php echo esc_html($user->user_email); ?></a></td>
                                <td><?php echo esc_html((string) max(0, (int) ($row_stats['rounds_30d'] ?? 0))); ?></td>
                                <td><?php echo esc_html((string) max(0, (int) ($row_stats['studied_words'] ?? 0))); ?></td>
                                <td><?php echo esc_html((string) max(0, (int) ($row_stats['mastered_words'] ?? 0))); ?></td>
                                <td><?php echo esc_html((string) max(0, (int) ($row_stats['hard_words'] ?? 0))); ?></td>
                                <td><?php echo esc_html((string) ($row['last_activity'] ?? '')); ?></td>
                                <td>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return window.confirm('<?php echo esc_js(sprintf(__('Remove %s from this class?', 'll-tools-text-domain'), ll_tools_teacher_class_user_label($user))); ?>');">
                                        <input type="hidden" name="action" value="ll_tools_teacher_remove_class_student" />
                                        <input type="hidden" name="class_id" value="<?php echo esc_attr((string) $selected_class->ID); ?>" />
                                        <input type="hidden" name="ll_tools_teacher_remove_user_id" value="<?php echo esc_attr((string) $user->ID); ?>" />
                                        <?php wp_nonce_field('ll_tools_teacher_remove_class_student_' . (int) $selected_class->ID); ?>
                                        <button type="submit" class="button button-small">
                                            <?php esc_html_e('Remove', 'll-tools-text-domain'); ?>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}
