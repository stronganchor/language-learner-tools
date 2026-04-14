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

        $result = function_exists('ll_tools_teacher_class_create')
            ? ll_tools_teacher_class_create(get_current_user_id(), $class_name)
            : new WP_Error('missing_helper', __('Class creation is currently unavailable.', 'll-tools-text-domain'));

        $redirect_url = ll_tools_get_teacher_classes_page_url();
        if (is_wp_error($result)) {
            wp_safe_redirect(ll_tools_teacher_classes_build_notice_url(
                $redirect_url,
                'error',
                $result->get_error_message()
            ));
            exit;
        }

        $created_class_id = (int) $result;
        wp_safe_redirect(ll_tools_teacher_classes_build_notice_url(
            ll_tools_get_teacher_classes_page_url(['class_id' => $created_class_id]),
            'success',
            sprintf(
                /* translators: %s: class name */
                __('Created class: %s', 'll-tools-text-domain'),
                ll_tools_teacher_class_get_name($created_class_id)
            )
        ));
        exit;
    }
}
add_action('admin_post_ll_tools_teacher_create_class', 'll_tools_handle_teacher_class_create_action');

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

        $redirect_url = ll_tools_get_teacher_classes_page_url(['class_id' => $class_id]);
        if (is_wp_error($result)) {
            wp_safe_redirect(ll_tools_teacher_classes_build_notice_url(
                $redirect_url,
                'error',
                $result->get_error_message()
            ));
            exit;
        }

        wp_safe_redirect(ll_tools_teacher_classes_build_notice_url(
            $redirect_url,
            'success',
            sprintf(
                /* translators: %s: learner email */
                __('Sent a class invitation to %s.', 'll-tools-text-domain'),
                $email
            )
        ));
        exit;
    }
}
add_action('admin_post_ll_tools_teacher_send_class_invite', 'll_tools_handle_teacher_class_invite_action');

if (!function_exists('ll_tools_teacher_classes_student_rows')) {
    function ll_tools_teacher_classes_student_rows(array $student_ids): array {
        $student_ids = array_values(array_filter(array_map('intval', $student_ids), static function (int $user_id): bool {
            return $user_id > 0;
        }));
        if (empty($student_ids)) {
            return [];
        }

        $users = get_users([
            'include' => $student_ids,
            'orderby' => 'display_name',
            'order' => 'ASC',
        ]);

        if (!is_array($users)) {
            return [];
        }

        $stats = function_exists('ll_tools_user_progress_report_stats_for_users')
            ? ll_tools_user_progress_report_stats_for_users($student_ids, 0)
            : [];

        $rows = [];
        foreach ($users as $user) {
            if (!($user instanceof WP_User)) {
                continue;
            }

            $user_id = (int) $user->ID;
            $row_stats = isset($stats[$user_id]) && is_array($stats[$user_id]) ? $stats[$user_id] : [];
            $current_wordset_id = function_exists('ll_tools_user_progress_report_user_wordset_id')
                ? ll_tools_user_progress_report_user_wordset_id($user_id)
                : 0;
            $rows[] = [
                'user' => $user,
                'stats' => $row_stats,
                'current_wordset_name' => function_exists('ll_tools_user_progress_report_wordset_name')
                    ? ll_tools_user_progress_report_wordset_name($current_wordset_id)
                    : '',
                'last_activity' => function_exists('ll_tools_user_progress_report_last_activity')
                    ? ll_tools_user_progress_report_last_activity($row_stats)
                    : '',
            ];
        }

        return $rows;
    }
}

if (!function_exists('ll_tools_render_teacher_classes_page')) {
    function ll_tools_render_teacher_classes_page(): void {
        ll_tools_teacher_classes_require_manage_access();

        $classes = function_exists('ll_tools_teacher_classes_for_user')
            ? ll_tools_teacher_classes_for_user(get_current_user_id())
            : [];
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
        $student_ids = ($selected_class instanceof WP_Post && function_exists('ll_tools_teacher_class_get_student_ids'))
            ? ll_tools_teacher_class_get_student_ids((int) $selected_class->ID)
            : [];
        $student_rows = ll_tools_teacher_classes_student_rows($student_ids);

        $summary = [
            'students' => count($student_rows),
            'rounds_30d' => 0,
            'studied_words' => 0,
            'mastered_words' => 0,
            'hard_words' => 0,
        ];
        foreach ($student_rows as $row) {
            $row_stats = (array) ($row['stats'] ?? []);
            $summary['rounds_30d'] += max(0, (int) ($row_stats['rounds_30d'] ?? 0));
            $summary['studied_words'] += max(0, (int) ($row_stats['studied_words'] ?? 0));
            $summary['mastered_words'] += max(0, (int) ($row_stats['mastered_words'] ?? 0));
            $summary['hard_words'] += max(0, (int) ($row_stats['hard_words'] ?? 0));
        }

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Classes', 'll-tools-text-domain'); ?></h1>
            <p><?php esc_html_e('Create classes, invite learners, and review progress for students you teach.', 'll-tools-text-domain'); ?></p>

            <?php ll_tools_teacher_classes_render_notice_from_request(); ?>

            <div class="card" style="max-width: 720px;">
                <h2><?php esc_html_e('Create a class', 'll-tools-text-domain'); ?></h2>
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
                    </table>
                    <?php submit_button(__('Create class', 'll-tools-text-domain')); ?>
                </form>
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
                        <?php if (current_user_can('manage_options')) : ?>
                            <th><?php esc_html_e('Teacher', 'll-tools-text-domain'); ?></th>
                        <?php endif; ?>
                        <th><?php esc_html_e('Students', 'll-tools-text-domain'); ?></th>
                        <th><?php esc_html_e('Open', 'll-tools-text-domain'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($classes as $class_post) : ?>
                        <?php if (!($class_post instanceof WP_Post)) { continue; } ?>
                        <?php
                        $class_id = (int) $class_post->ID;
                        $teacher_user = get_userdata((int) $class_post->post_author);
                        ?>
                        <tr>
                            <td><?php echo esc_html($class_post->post_title); ?></td>
                            <?php if (current_user_can('manage_options')) : ?>
                                <td><?php echo esc_html($teacher_user instanceof WP_User ? ($teacher_user->display_name ?: $teacher_user->user_login) : ''); ?></td>
                            <?php endif; ?>
                            <td><?php echo esc_html((string) count(ll_tools_teacher_class_get_student_ids($class_id))); ?></td>
                            <td>
                                <a class="button button-small" href="<?php echo esc_url(ll_tools_get_teacher_classes_page_url(['class_id' => $class_id])); ?>">
                                    <?php esc_html_e('Open', 'll-tools-text-domain'); ?>
                                </a>
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
                        <th><?php esc_html_e('Current Word Set', 'll-tools-text-domain'); ?></th>
                        <th><?php esc_html_e('30d Rounds', 'll-tools-text-domain'); ?></th>
                        <th><?php esc_html_e('Studied', 'll-tools-text-domain'); ?></th>
                        <th><?php esc_html_e('Mastered', 'll-tools-text-domain'); ?></th>
                        <th><?php esc_html_e('Hard', 'll-tools-text-domain'); ?></th>
                        <th><?php esc_html_e('Last Activity (UTC)', 'll-tools-text-domain'); ?></th>
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
                                <td><?php echo esc_html($user->display_name ?: $user->user_login); ?></td>
                                <td><a href="mailto:<?php echo esc_attr($user->user_email); ?>"><?php echo esc_html($user->user_email); ?></a></td>
                                <td><?php echo esc_html((string) ($row['current_wordset_name'] ?? '')); ?></td>
                                <td><?php echo esc_html((string) max(0, (int) ($row_stats['rounds_30d'] ?? 0))); ?></td>
                                <td><?php echo esc_html((string) max(0, (int) ($row_stats['studied_words'] ?? 0))); ?></td>
                                <td><?php echo esc_html((string) max(0, (int) ($row_stats['mastered_words'] ?? 0))); ?></td>
                                <td><?php echo esc_html((string) max(0, (int) ($row_stats['hard_words'] ?? 0))); ?></td>
                                <td><?php echo esc_html((string) ($row['last_activity'] ?? '')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}
